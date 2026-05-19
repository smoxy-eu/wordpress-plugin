<?php

use Smoxy\WP\Purger;
use Smoxy\WP\Settings;

/**
 * Feature-level tests for the Settings admin page: rendering + each form's
 * admin-post handler. They simulate the full request pipeline (current user,
 * $_POST, nonce) and assert the effects the browser would observe (outbound
 * BAN request, notice transient, redirect).
 */
class SettingsTest extends WP_UnitTestCase {


	/** @var array<int,array{url:string,args:array<string,mixed>}> */
	private array $http_calls = array();

	private int $admin_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->http_calls = array();
		$this->admin_id   = self::factory()->user->create( array( 'role' => 'administrator' ) );

		update_option( Settings::OPTION_NAME, array( 'secret_key' => 'test-token' ) );

		add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );

		// Turn redirects into exceptions so the handler's trailing exit does
		// not abort the test. The filter runs before wp_redirect() emits
		// headers, so throwing here bubbles out cleanly.
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is read back in tests, never rendered.
				throw new SmoxyRedirectException( (string) $location );
			},
			1
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_redirect' );
		remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
		delete_option( Settings::OPTION_NAME );
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * @param false|array|\WP_Error $preempt
	 * @param array<string,mixed>   $args
	 */
	public function capture_http( $preempt, $args, string $url ) {
		if ( 'BAN' === ( $args['method'] ?? '' ) ) {
			$this->http_calls[] = array(
				'url'  => $url,
				'args' => $args,
			);
		}
		return $preempt;
	}

	/* ------------------------------------------------------------------
	 * Page rendering
	 * ------------------------------------------------------------------ */

	public function test_settings_page_renders_all_forms_and_controls_for_admin(): void {
		wp_set_current_user( $this->admin_id );
		( new Settings() )->register_settings();

		ob_start();
		( new Settings() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'smoxy Proxy Settings', $html );

		// Secret token field (registered via the Settings API).
		$this->assertStringContainsString( 'id="smoxy_secret_key"', $html );

		// Purge all form.
		$this->assertStringContainsString( 'value="' . Settings::PURGE_ACTION . '"', $html );

		// Purge by URL form.
		$this->assertStringContainsString( 'id="smoxy_purge_url"', $html );
		$this->assertStringContainsString( 'value="' . Settings::PURGE_URL_ACTION . '"', $html );

		// Purge by tag form.
		$this->assertStringContainsString( 'id="smoxy_purge_tag"', $html );
		$this->assertStringContainsString( 'value="' . Settings::PURGE_TAG_ACTION . '"', $html );

		// Each form carries a WP nonce field.
		preg_match_all( '/name="_wpnonce"\s+value="([a-f0-9]+)"/', $html, $matches );
		$this->assertGreaterThanOrEqual(
			3,
			count( $matches[1] ),
			'Each purge form must contain a _wpnonce hidden field'
		);
	}

	public function test_settings_page_outputs_nothing_for_non_admin(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );

		ob_start();
		( new Settings() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertSame( '', $html );
	}

	/* ------------------------------------------------------------------
	 * Purge all
	 * ------------------------------------------------------------------ */

	public function test_submit_purge_all_sends_flushall_ban_and_redirects(): void {
		$this->login_admin_with_nonce( Settings::PURGE_ACTION );

		$location = $this->capture_redirect(
			fn () => ( new Settings() )->handle_purge()
		);

		$this->assertCount( 1, $this->http_calls );
		$headers = $this->http_calls[0]['args']['headers'] ?? array();
		$this->assertSame( 'flushall', $headers['type'] ?? null );
		$this->assertNotSame( '', $location );
		$this->assertNoticeTransient( true );
	}

	public function test_purge_all_blocks_non_admin(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Settings::PURGE_ACTION );

		$this->expectException( WPDieException::class );
		( new Settings() )->handle_purge();
	}

	public function test_purge_all_requires_valid_nonce(): void {
		wp_set_current_user( $this->admin_id );
		// No nonce provided.

		$this->expectException( WPDieException::class );
		( new Settings() )->handle_purge();
	}

	/* ------------------------------------------------------------------
	 * Purge by URL
	 * ------------------------------------------------------------------ */

	public function test_submit_purge_url_with_known_post_permalink_sends_tag_ban(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->login_admin_with_nonce( Settings::PURGE_URL_ACTION );
		$_POST['smoxy_purge_url'] = (string) get_permalink( $post_id );

		$this->capture_redirect( fn () => ( new Settings() )->handle_purge_url() );

		$this->assertCount( 1, $this->http_calls );
		$headers = $this->http_calls[0]['args']['headers'] ?? array();
		$this->assertStringStartsWith( Purger::INGRESS_URL, $this->http_calls[0]['url'] );
		$this->assertSame( 'tag', $headers['type'] ?? null );
		$this->assertSame( 'p-' . $post_id, $headers['tags'] ?? null );
		$this->assertNoticeTransient( true );
	}

	public function test_submit_purge_url_with_empty_input_purges_home_page(): void {
		$this->login_admin_with_nonce( Settings::PURGE_URL_ACTION );
		$_POST['smoxy_purge_url'] = '';

		$this->capture_redirect( fn () => ( new Settings() )->handle_purge_url() );

		$this->assertCount( 1, $this->http_calls );
		$this->assertSame( home_url( '/' ), $this->http_calls[0]['url'] );
		$this->assertSame( 'BAN', $this->http_calls[0]['args']['method'] ?? null );
		$this->assertNoticeTransient( true );
	}

	public function test_submit_purge_url_resolves_relative_path_against_home(): void {
		$this->login_admin_with_nonce( Settings::PURGE_URL_ACTION );
		$_POST['smoxy_purge_url'] = '/2020/01/';

		$this->capture_redirect( fn () => ( new Settings() )->handle_purge_url() );

		$this->assertCount( 1, $this->http_calls );
		$this->assertSame( home_url( '/2020/01/' ), $this->http_calls[0]['url'] );
	}

	public function test_submit_purge_url_rejects_external_host(): void {
		$this->login_admin_with_nonce( Settings::PURGE_URL_ACTION );
		$_POST['smoxy_purge_url'] = 'https://evil.example.com/x';

		$this->capture_redirect( fn () => ( new Settings() )->handle_purge_url() );

		$this->assertCount( 0, $this->http_calls, 'External URL must not trigger any BAN' );
		$this->assertNoticeTransient( false );
	}

	/**
	 * Asset URLs (JS, CSS, images, fonts) never resolve to a post via
	 * url_to_postid(), so they must fall through to a direct URL BAN sent to
	 * the asset URL itself — not to the smoxy ingress with a tag header.
	 *
	 * @dataProvider asset_url_provider
	 */
	public function test_submit_purge_url_with_asset_url_sends_direct_url_ban( string $input, string $expected_url ): void {
		$this->login_admin_with_nonce( Settings::PURGE_URL_ACTION );
		$_POST['smoxy_purge_url'] = $input;

		$this->capture_redirect( fn () => ( new Settings() )->handle_purge_url() );

		$this->assertCount( 1, $this->http_calls );
		$call    = $this->http_calls[0];
		$headers = $call['args']['headers'] ?? array();

		$this->assertSame( $expected_url, $call['url'], 'BAN must target the asset URL itself' );
		$this->assertSame( 'BAN', $call['args']['method'] ?? null );
		// A direct URL BAN must not carry the tag header, or the edge would
		// treat it as a tag purge instead of a path purge.
		$this->assertArrayNotHasKey( 'type', $headers );
		$this->assertArrayNotHasKey( 'tags', $headers );
		$this->assertNoticeTransient( true );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function asset_url_provider(): array {
		return array(
			'absolute JS URL'         => array(
				home_url( '/wp-content/plugins/smoxy/assets/app.js' ),
				home_url( '/wp-content/plugins/smoxy/assets/app.js' ),
			),
			'relative CSS path'       => array(
				'/wp-content/themes/twentytwenty/style.css',
				home_url( '/wp-content/themes/twentytwenty/style.css' ),
			),
			'relative image path'     => array(
				'/wp-content/uploads/2025/04/hero.jpg',
				home_url( '/wp-content/uploads/2025/04/hero.jpg' ),
			),
			'versioned asset (query)' => array(
				'/wp-content/plugins/smoxy/assets/app.js?ver=1.2.3',
				home_url( '/wp-content/plugins/smoxy/assets/app.js?ver=1.2.3' ),
			),
			'webfont absolute URL'    => array(
				home_url( '/wp-content/themes/twentytwenty/fonts/body.woff2' ),
				home_url( '/wp-content/themes/twentytwenty/fonts/body.woff2' ),
			),
		);
	}

	public function test_purge_url_blocks_non_admin(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$_REQUEST['_wpnonce']     = wp_create_nonce( Settings::PURGE_URL_ACTION );
		$_POST['smoxy_purge_url'] = home_url( '/' );

		$this->expectException( WPDieException::class );
		( new Settings() )->handle_purge_url();
	}

	public function test_purge_url_requires_valid_nonce(): void {
		wp_set_current_user( $this->admin_id );
		$_POST['smoxy_purge_url'] = home_url( '/' );

		$this->expectException( WPDieException::class );
		( new Settings() )->handle_purge_url();
	}

	/* ------------------------------------------------------------------
	 * Purge by tag
	 * ------------------------------------------------------------------ */

	public function test_submit_purge_tag_with_single_tag_sends_tag_ban(): void {
		$this->login_admin_with_nonce( Settings::PURGE_TAG_ACTION );
		$_POST['smoxy_purge_tag'] = 'p-42';

		$this->capture_redirect( fn () => ( new Settings() )->handle_purge_tag() );

		$this->assertCount( 1, $this->http_calls );
		$headers = $this->http_calls[0]['args']['headers'] ?? array();
		$this->assertSame( 'tag', $headers['type'] ?? null );
		$this->assertSame( 'p-42', $headers['tags'] ?? null );
		$this->assertNoticeTransient( true );
	}

	public function test_submit_purge_tag_with_multiple_tags_sends_all_tags(): void {
		$this->login_admin_with_nonce( Settings::PURGE_TAG_ACTION );
		$_POST['smoxy_purge_tag'] = 'p-1, t-7 , home';

		$this->capture_redirect( fn () => ( new Settings() )->handle_purge_tag() );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-1', $tags );
		$this->assertContains( 't-7', $tags );
		$this->assertContains( 'home', $tags );
		$this->assertNoticeTransient( true );
	}

	public function test_submit_purge_tag_trims_whitespace_and_drops_empty_fragments(): void {
		$this->login_admin_with_nonce( Settings::PURGE_TAG_ACTION );
		$_POST['smoxy_purge_tag'] = ' , p-42, , , home , ';

		$this->capture_redirect( fn () => ( new Settings() )->handle_purge_tag() );

		$this->assertCount( 1, $this->http_calls );
		$this->assertSame( array( 'p-42', 'home' ), $this->tags_from( $this->http_calls[0] ) );
	}

	public function test_submit_purge_tag_with_empty_input_errors_and_sends_no_ban(): void {
		$this->login_admin_with_nonce( Settings::PURGE_TAG_ACTION );
		$_POST['smoxy_purge_tag'] = '   ,   ';

		$this->capture_redirect( fn () => ( new Settings() )->handle_purge_tag() );

		$this->assertCount( 0, $this->http_calls );
		$this->assertNoticeTransient( false );
	}

	public function test_purge_tag_blocks_non_admin(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$_REQUEST['_wpnonce']     = wp_create_nonce( Settings::PURGE_TAG_ACTION );
		$_POST['smoxy_purge_tag'] = 'home';

		$this->expectException( WPDieException::class );
		( new Settings() )->handle_purge_tag();
	}

	public function test_purge_tag_requires_valid_nonce(): void {
		wp_set_current_user( $this->admin_id );
		$_POST['smoxy_purge_tag'] = 'home';

		$this->expectException( WPDieException::class );
		( new Settings() )->handle_purge_tag();
	}

	/* ------------------------------------------------------------------
	 * Check connection
	 * ------------------------------------------------------------------ */

	public function test_check_connection_runs_connection_checker_and_redirects(): void {
		$this->login_admin_with_nonce( Settings::CHECK_ACTION );
		delete_option( 'smoxy_connection_status' );

		$location = $this->capture_redirect(
			fn () => ( new Settings() )->handle_check()
		);

		$this->assertStringContainsString( 'page=' . Settings::SETTINGS_SLUG, $location );
		$this->assertIsArray( get_option( 'smoxy_connection_status' ) );
	}

	public function test_check_connection_blocks_non_admin(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Settings::CHECK_ACTION );

		$this->expectException( WPDieException::class );
		( new Settings() )->handle_check();
	}

	/* ------------------------------------------------------------------
	 * Notice feedback loop
	 * ------------------------------------------------------------------ */

	/* ------------------------------------------------------------------
	 * Secret-key field rendering (Phase 1: masked / no stored value in HTML)
	 * ------------------------------------------------------------------ */

	public function test_render_secret_key_field_does_not_leak_stored_token_into_html(): void {
		// Store a sentinel token, render the field, assert the token never
		// appears in the HTML and that the description says one is saved.
		update_option( Settings::OPTION_NAME, array( 'secret_key' => 'secret-sentinel-abc' ) );

		ob_start();
		( new Settings() )->render_secret_key_field();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'secret-sentinel-abc', $html, 'Stored token must never be rendered into the page' );
		$this->assertStringContainsString( 'value=""', $html, 'value attribute must be empty so the token is not exposed' );
		$this->assertStringContainsString( 'secret token is saved', $html, 'Description must indicate that a token is on file' );
	}

	public function test_render_secret_key_field_prompts_for_token_when_unset(): void {
		update_option( Settings::OPTION_NAME, array( 'secret_key' => '' ) );

		ob_start();
		( new Settings() )->render_secret_key_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value=""', $html );
		// One of the placeholder / description copies must prompt the user.
		$this->assertMatchesRegularExpression(
			'/Paste\s+(your\s+)?(the\s+)?(smoxy\s+)?secret token/i',
			$html,
			'Empty-state copy should prompt the user to paste a token'
		);
	}

	/* ------------------------------------------------------------------
	 * sanitize() preserves the stored token on empty/whitespace submit
	 * ------------------------------------------------------------------ */

	public function test_sanitize_preserves_stored_token_on_empty_submit(): void {
		update_option( Settings::OPTION_NAME, array( 'secret_key' => 'kept-token' ) );

		$output = ( new Settings() )->sanitize( array( 'secret_key' => '' ) );

		$this->assertSame( 'kept-token', $output['secret_key'] ?? null );
	}

	public function test_sanitize_preserves_stored_token_on_whitespace_submit(): void {
		update_option( Settings::OPTION_NAME, array( 'secret_key' => 'kept-token' ) );

		$output = ( new Settings() )->sanitize( array( 'secret_key' => "   \t\n" ) );

		$this->assertSame( 'kept-token', $output['secret_key'] ?? null );
	}

	public function test_sanitize_replaces_stored_token_when_new_value_submitted(): void {
		update_option( Settings::OPTION_NAME, array( 'secret_key' => 'old-token' ) );

		$output = ( new Settings() )->sanitize( array( 'secret_key' => 'new-token' ) );

		$this->assertSame( 'new-token', $output['secret_key'] ?? null );
	}

	/* ------------------------------------------------------------------
	 * add_admin_bar_node()
	 * ------------------------------------------------------------------ */

	public function test_add_admin_bar_node_registers_purge_node_for_admin(): void {
		wp_set_current_user( $this->admin_id );

		$bar = $this->new_admin_bar();
		( new Settings() )->add_admin_bar_node( $bar );

		$node = $bar->get_node( 'smoxy-purge' );
		$this->assertNotNull( $node, 'Admin bar node should be registered for users with manage_options' );

		$href = (string) ( $node->href ?? '' );
		$this->assertStringContainsString( 'action=' . Settings::PURGE_ACTION, $href );
		$this->assertStringContainsString( '_wpnonce=', $href, 'Node href must carry the purge_all nonce' );
	}

	public function test_add_admin_bar_node_skipped_for_user_without_manage_options(): void {
		$sub = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub );

		$bar = $this->new_admin_bar();
		( new Settings() )->add_admin_bar_node( $bar );

		$this->assertNull( $bar->get_node( 'smoxy-purge' ), 'Subscribers must not see the purge node' );
	}

	/**
	 * Build a WP_Admin_Bar instance for tests.
	 *
	 * WordPress loads class-wp-admin-bar.php lazily — only when the admin
	 * bar is rendered. Require it explicitly so the class is available in
	 * a non-admin-bar context.
	 */
	private function new_admin_bar(): \WP_Admin_Bar {
		if ( ! class_exists( 'WP_Admin_Bar' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		}
		return new \WP_Admin_Bar();
	}

	public function test_notice_rendered_from_transient_after_handler_runs(): void {
		wp_set_current_user( $this->admin_id );
		set_transient(
			Settings::NOTICE_KEY . '_' . get_current_user_id(),
			array(
				'ok'      => true,
				'message' => 'All cached pages have been purged.',
			),
			60
		);

		ob_start();
		( new Settings() )->render_notice();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'All cached pages have been purged.', $html );
		// Transient must be consumed so the notice shows once.
		$this->assertFalse( get_transient( Settings::NOTICE_KEY . '_' . get_current_user_id() ) );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function login_admin_with_nonce( string $action ): void {
		wp_set_current_user( $this->admin_id );
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
	}

	private function capture_redirect( callable $cb ): string {
		try {
			$cb();
		} catch ( SmoxyRedirectException $e ) {
			return $e->location;
		}
		// PHPUnit 9.x fails with a void-typed Assert::fail(); throw the same
		// exception directly so static analyzers see the terminating branch.
		throw new \PHPUnit\Framework\AssertionFailedError( 'Handler was expected to redirect but did not' );
	}

	/**
	 * @param array{url:string,args:array<string,mixed>} $call
	 * @return string[]
	 */
	private function tags_from( array $call ): array {
		$headers = $call['args']['headers'] ?? array();
		$raw     = isset( $headers['tags'] ) ? (string) $headers['tags'] : '';
		return '' === $raw ? array() : explode( ',', $raw );
	}

	private function assertNoticeTransient( bool $expected_ok ): void {
		$transient = get_transient( Settings::NOTICE_KEY . '_' . get_current_user_id() );
		$this->assertIsArray( $transient, 'A notice transient should be set for the current user' );
		$this->assertArrayHasKey( 'ok', $transient );
		$this->assertSame( $expected_ok, (bool) $transient['ok'] );
	}
}
