<?php

use Smoxy\WP\Purger;
use Smoxy\WP\Settings;

class EventBusTest extends WP_UnitTestCase {


	/** @var array<int,array{url:string,args:array<string,mixed>}> */
	private array $http_calls = array();

	public function set_up(): void {
		parent::set_up();

		$this->http_calls = array();

		update_option( Settings::OPTION_NAME, array( 'secret_key' => 'test-token' ) );

		add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );

		// WP's default shutdown handler closes every active output buffer,
		// which trips PHPUnit's "risky test" check when we fire `shutdown`
		// manually. WP_UnitTestCase backs up/restores hooks per test, so this
		// unregistration is scoped to the current test.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
		delete_option( Settings::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * @param false|array|\WP_Error $preempt
	 * @param array<string,mixed> $args
	 */
	public function capture_http( $preempt, $args, string $url ) {
		// Record purge calls to the smoxy ingress only. The bootstrap-level
		// fallback in tests/bootstrap.php short-circuits every request with a
		// fake 200 response, so returning $preempt unchanged is safe.
		if ( str_starts_with( $url, Purger::INGRESS_URL ) ) {
			$this->http_calls[] = array(
				'url'  => $url,
				'args' => $args,
			);
		}
		return $preempt;
	}

	public function test_post_publish_triggers_tag_purge_with_expected_tags(): void {
		// EventBus is already registered by Plugin::boot() during plugins_loaded.

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Hello',
			)
		);

		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls, 'Expected exactly one BAN call for the post update' );

		$call    = $this->http_calls[0];
		$headers = $call['args']['headers'] ?? array();

		$this->assertSame( 'BAN', $call['args']['method'] ?? null );
		$this->assertSame( 'tag', $headers['type'] ?? null );

		$tags = isset( $headers['tags'] ) ? explode( ',', (string) $headers['tags'] ) : array();

		$this->assertContains( 'p-' . $post_id, $tags );
		$this->assertContains( 'home', $tags );
		$this->assertContains( 'feed', $tags );
	}

	/* ------------------------------------------------------------------
	 * Posts
	 * ------------------------------------------------------------------ */

	public function test_post_update_triggers_tag_purge(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->flush_and_clear();

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated',
			)
		);
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $post_id, $tags );
		$this->assertContains( 'home', $tags );
		$this->assertContains( 'feed', $tags );
	}

	public function test_post_publish_includes_taxonomy_and_author_tags(): void {
		$author_id = self::factory()->user->create();
		$this->flush_and_clear(); // user_register does not fire profile_update, but be safe.

		$cat_id = self::factory()->category->create( array( 'name' => 'smoxy Cat' ) );
		$this->flush_and_clear(); // created_term fires — flush it out before the post event.

		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_author'   => $author_id,
				'post_category' => array( $cat_id ),
			)
		);
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $post_id, $tags );
		$this->assertContains( 'home', $tags );
		$this->assertContains( 'feed', $tags );
		$this->assertContains( 't-' . $cat_id, $tags );
		$this->assertContains( 'a-' . $author_id, $tags );
	}

	public function test_post_trash_triggers_tag_purge(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->flush_and_clear();

		wp_trash_post( $post_id );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $post_id, $tags );
		$this->assertContains( 'home', $tags );
		$this->assertContains( 'feed', $tags );
	}

	public function test_post_force_delete_triggers_tag_purge(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->flush_and_clear();

		wp_delete_post( $post_id, true );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $post_id, $tags );
		$this->assertContains( 'home', $tags );
		$this->assertContains( 'feed', $tags );
	}

	public function test_draft_save_does_not_trigger_purge(): void {
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		do_action( 'shutdown' );

		$this->assertCount( 0, $this->http_calls );
	}

	/* ------------------------------------------------------------------
	 * Comments
	 * ------------------------------------------------------------------ */

	public function test_comment_creation_triggers_post_tag_purge(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->flush_and_clear();

		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		// factory->comment->create uses wp_insert_comment which does NOT fire
		// comment_post; simulate the hook so the EventBus handler runs.
		do_action( 'comment_post', $comment_id, 1, array() );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $post_id, $tags );
	}

	public function test_spam_comment_does_not_trigger_purge(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->flush_and_clear();

		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		do_action( 'comment_post', $comment_id, 'spam', array() );
		do_action( 'shutdown' );

		$this->assertCount( 0, $this->http_calls );
	}

	public function test_comment_edit_triggers_post_tag_purge(): void {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$this->flush_and_clear();

		wp_update_comment(
			array(
				'comment_ID'      => $comment_id,
				'comment_content' => 'updated body',
			)
		);
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $post_id, $tags );
	}

	public function test_comment_status_change_triggers_post_tag_purge(): void {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
			)
		);
		$this->flush_and_clear();

		wp_set_comment_status( $comment_id, 'hold' );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $post_id, $tags );
	}

	public function test_comment_delete_triggers_post_tag_purge(): void {
		$post_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$comment_id = self::factory()->comment->create( array( 'comment_post_ID' => $post_id ) );
		$this->flush_and_clear();

		wp_delete_comment( $comment_id, true );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $post_id, $tags );
	}

	/* ------------------------------------------------------------------
	 * Terms
	 * ------------------------------------------------------------------ */

	public function test_term_created_triggers_term_and_home_purge(): void {
		$term = wp_insert_term( 'smoxy Term Create', 'category' );
		$this->assertIsArray( $term );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 't-' . (int) $term['term_id'], $tags );
		$this->assertContains( 'home', $tags );
	}

	public function test_term_edited_triggers_term_and_home_purge(): void {
		$term = wp_insert_term( 'smoxy Term Edit', 'category' );
		$this->assertIsArray( $term );
		$this->flush_and_clear();

		wp_update_term( (int) $term['term_id'], 'category', array( 'name' => 'smoxy Term Edited' ) );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 't-' . (int) $term['term_id'], $tags );
		$this->assertContains( 'home', $tags );
	}

	public function test_term_deleted_triggers_term_and_home_purge(): void {
		$term = wp_insert_term( 'smoxy Term Delete', 'category' );
		$this->assertIsArray( $term );
		$this->flush_and_clear();

		wp_delete_term( (int) $term['term_id'], 'category' );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 't-' . (int) $term['term_id'], $tags );
		$this->assertContains( 'home', $tags );
	}

	/* ------------------------------------------------------------------
	 * Users
	 * ------------------------------------------------------------------ */

	public function test_profile_update_triggers_author_purge(): void {
		$user_id = self::factory()->user->create();
		$this->flush_and_clear();

		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => 'Updated Name',
			)
		);
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'a-' . $user_id, $tags );
	}

	public function test_user_delete_triggers_author_purge(): void {
		$user_id = self::factory()->user->create();
		$this->flush_and_clear();

		wp_delete_user( $user_id );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'a-' . $user_id, $tags );
	}

	/* ------------------------------------------------------------------
	 * Site-wide flushall
	 * ------------------------------------------------------------------ */

	public function test_site_option_update_triggers_flushall(): void {
		update_option( 'blogname', 'New name' );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$headers = $this->http_calls[0]['args']['headers'] ?? array();
		$this->assertSame( 'flushall', $headers['type'] ?? null );
	}

	/**
	 * @dataProvider flushall_action_provider
	 */
	public function test_flushall_action( string $action ): void {
		do_action( $action, 1 );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$headers = $this->http_calls[0]['args']['headers'] ?? array();
		$this->assertSame( 'flushall', $headers['type'] ?? null );
		$this->assertArrayNotHasKey( 'tags', $headers );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function flushall_action_provider(): array {
		// `upgrader_process_complete` is excluded: WP core attaches handlers that
		// require Language_Pack_Upgrader which isn't loaded in the test env, so
		// firing it from a unit test crashes before our handler runs. The wiring
		// is trivial (one add_action call) and is covered by the other actions.
		return array(
			'nav menu update'      => array( 'wp_update_nav_menu' ),
			'switch theme'         => array( 'switch_theme' ),
			'customize save after' => array( 'customize_save_after' ),
			'activated plugin'     => array( 'activated_plugin' ),
			'deactivated plugin'   => array( 'deactivated_plugin' ),
		);
	}

	/**
	 * @dataProvider flushall_option_provider
	 */
	public function test_flushall_option( string $option ): void {
		// Fire the action EventBus actually subscribes to. Using update_option()
		// is unreliable here because several of these options (home, siteurl,
		// permalink_structure, etc.) are run through sanitizers that may reject
		// arbitrary test values, leaving the option unchanged and the hook silent.
		// Three args mirror WP's real signature (old_value, new_value, option) —
		// some core callbacks on these hooks require all three.
		do_action( 'update_option_' . $option, 'old-value', 'new-value', $option );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$headers = $this->http_calls[0]['args']['headers'] ?? array();
		$this->assertSame( 'flushall', $headers['type'] ?? null );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function flushall_option_provider(): array {
		return array(
			array( 'sidebars_widgets' ),
			array( 'blogname' ),
			array( 'blogdescription' ),
			array( 'home' ),
			array( 'siteurl' ),
			array( 'WPLANG' ),
			array( 'permalink_structure' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Aggregation semantics
	 * ------------------------------------------------------------------ */

	public function test_multiple_post_updates_collapse_into_single_BAN(): void {
		$a = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$b = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls, 'Multiple updates in one request must collapse to one BAN' );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $a, $tags );
		$this->assertContains( 'p-' . $b, $tags );
		// Shared tags must be deduplicated.
		$home_count = count( array_keys( $tags, 'home', true ) );
		$this->assertSame( 1, $home_count, 'Shared tag "home" must be deduplicated' );
	}

	public function test_flushall_trumps_tag_collection(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		do_action( 'switch_theme' );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$headers = $this->http_calls[0]['args']['headers'] ?? array();
		$this->assertSame( 'flushall', $headers['type'] ?? null );
		$this->assertArrayNotHasKey( 'tags', $headers );
	}

	public function test_shutdown_without_events_emits_no_request(): void {
		do_action( 'shutdown' );

		$this->assertCount( 0, $this->http_calls );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Flush any queued EventBus state from test setup so the next assertion
	 * only sees calls produced by the action under test.
	 */
	private function flush_and_clear(): void {
		do_action( 'shutdown' );
		$this->http_calls = array();
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
}
