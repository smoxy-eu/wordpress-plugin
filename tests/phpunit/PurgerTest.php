<?php

use Smoxy\WP\Purger;
use Smoxy\WP\Settings;

class PurgerTest extends WP_UnitTestCase {


	/** @var array<int,array{url:string,args:array<string,mixed>}> */
	private array $http_calls = array();

	/**
	 * Stubbed response returned to wp_remote_request() callers when set.
	 * When false, the bootstrap-level fallback provides a fake 200.
	 *
	 * @var false|array|\WP_Error
	 */
	private $next_response = false;

	public function set_up(): void {
		parent::set_up();

		$this->http_calls    = array();
		$this->next_response = false;

		update_option( Settings::OPTION_NAME, array( 'secret_key' => 'test-token' ) );

		add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
		delete_option( Settings::OPTION_NAME );

		parent::tear_down();
	}

	/**
	 * Capture every BAN request — covers both tag-based (to the smoxy ingress)
	 * and URL-based purges. The bootstrap-level fallback in tests/bootstrap.php
	 * short-circuits the actual HTTP call with a fake 200; tests that need a
	 * different status (5xx, WP_Error) set $this->next_response to override it.
	 *
	 * @param false|array|\WP_Error $preempt
	 * @param array<string,mixed>   $args
	 */
	public function capture_http( $preempt, $args, string $url ) {
		if ( 'BAN' === ( $args['method'] ?? '' ) ) {
			$this->http_calls[] = array(
				'url'  => $url,
				'args' => $args,
			);
			if ( false !== $this->next_response ) {
				return $this->next_response;
			}
		}
		return $preempt;
	}

	public function test_purge_url_of_known_post_sends_tag_ban_to_ingress(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Hello',
				'post_name'   => 'hello',
			)
		);

		$url = (string) get_permalink( $post_id );
		$this->assertNotSame( '', $url );

		$result = ( new Purger() )->purge_url( $url );

		$this->assertTrue( $result['ok'] ?? false );
		$this->assertSame(
			sprintf( 'Purged %s from smoxy Proxy.', $url ),
			$result['message'] ?? null,
			'Message should be URL-centric regardless of underlying mechanism'
		);

		$this->assertCount( 1, $this->http_calls );

		$call    = $this->http_calls[0];
		$headers = $call['args']['headers'] ?? array();

		$this->assertStringStartsWith( Purger::INGRESS_URL, $call['url'] );
		$this->assertSame( 'tag', $headers['type'] ?? null );

		$tags = isset( $headers['tags'] ) ? explode( ',', (string) $headers['tags'] ) : array();
		$this->assertSame( array( 'p-' . $post_id ), $tags );
	}

	public function test_purge_url_of_unknown_path_sends_direct_url_ban(): void {
		// An archive path that url_to_postid() cannot resolve to a single post.
		$url = home_url( '/2020/01/' );

		$result = ( new Purger() )->purge_url( $url );

		$this->assertTrue( $result['ok'] ?? false );
		$this->assertCount( 1, $this->http_calls );

		$call = $this->http_calls[0];

		$this->assertSame( $url, $call['url'] );
		$this->assertSame( 'BAN', $call['args']['method'] ?? null );
		// Direct URL BAN must NOT carry the tag header, or the edge would
		// treat the request as a tag purge instead of a path purge.
		$this->assertArrayNotHasKey( 'type', $call['args']['headers'] ?? array() );
	}

	public function test_purge_url_treats_empty_input_as_home_page(): void {
		$result = ( new Purger() )->purge_url( '' );

		$this->assertTrue( $result['ok'] ?? false );
		$this->assertCount( 1, $this->http_calls );
		$this->assertSame( home_url( '/' ), $this->http_calls[0]['url'] );
	}

	public function test_purge_url_resolves_relative_path_against_home(): void {
		$result = ( new Purger() )->purge_url( '/2020/01/' );

		$this->assertTrue( $result['ok'] ?? false );
		$this->assertCount( 1, $this->http_calls );
		$this->assertSame( home_url( '/2020/01/' ), $this->http_calls[0]['url'] );
	}

	public function test_purge_url_resolves_bare_path_against_home(): void {
		$result = ( new Purger() )->purge_url( '2020/01/' );

		$this->assertTrue( $result['ok'] ?? false );
		$this->assertCount( 1, $this->http_calls );
		$this->assertSame( home_url( '/2020/01/' ), $this->http_calls[0]['url'] );
	}

	public function test_purge_url_rejects_external_url(): void {
		$result = ( new Purger() )->purge_url( 'https://example.com/some-page/' );

		$this->assertFalse( $result['ok'] );
		$this->assertCount( 0, $this->http_calls, 'External URL must not trigger any BAN request' );
	}

	/* ------------------------------------------------------------------
	 * purge_all error paths
	 * ------------------------------------------------------------------ */

	public function test_purge_all_returns_error_when_wp_remote_request_errors(): void {
		$this->next_response = new WP_Error( 'http_request_failed', 'cURL error 7' );

		$result = ( new Purger() )->purge_all();

		$this->assertFalse( $result['ok'] ?? null );
		$this->assertCount( 1, $this->http_calls );
		$this->assertStringContainsString( 'cURL error 7', (string) ( $result['message'] ?? '' ) );
	}

	public function test_purge_all_returns_error_on_5xx(): void {
		$this->next_response = $this->fake_response( 500 );

		$result = ( new Purger() )->purge_all();

		$this->assertFalse( $result['ok'] ?? null );
		$this->assertCount( 1, $this->http_calls );
		$this->assertStringContainsString( '500', (string) ( $result['message'] ?? '' ) );
	}

	public function test_purge_all_errors_when_secret_token_missing(): void {
		delete_option( Settings::OPTION_NAME );

		$result = ( new Purger() )->purge_all();

		$this->assertFalse( $result['ok'] ?? null );
		$this->assertCount( 0, $this->http_calls, 'No request should be sent without a secret token' );
		$this->assertStringContainsString( 'token', (string) ( $result['message'] ?? '' ) );
	}

	/* ------------------------------------------------------------------
	 * purge_tags happy + error paths
	 * ------------------------------------------------------------------ */

	public function test_purge_tags_sends_tag_ban_with_comma_separated_tags(): void {
		$result = ( new Purger() )->purge_tags( array( 'p-1', 'home', 'feed' ) );

		$this->assertTrue( $result['ok'] ?? false );
		$this->assertCount( 1, $this->http_calls );

		$call    = $this->http_calls[0];
		$headers = $call['args']['headers'] ?? array();

		$this->assertStringStartsWith( Purger::INGRESS_URL, $call['url'] );
		$this->assertSame( 'tag', $headers['type'] ?? null );
		$this->assertSame( array( 'p-1', 'home', 'feed' ), explode( ',', (string) ( $headers['tags'] ?? '' ) ) );
	}

	public function test_purge_tags_returns_error_on_wp_error_response(): void {
		$this->next_response = new WP_Error( 'http_request_failed', 'boom' );

		$result = ( new Purger() )->purge_tags( array( 'p-1' ) );

		$this->assertFalse( $result['ok'] ?? null );
		$this->assertStringContainsString( 'boom', (string) ( $result['message'] ?? '' ) );
	}

	public function test_purge_tags_with_empty_array_short_circuits_with_no_http_call(): void {
		$result = ( new Purger() )->purge_tags( array() );

		// Empty tag list is a no-op success — nothing to purge — and must not
		// fire any HTTP request.
		$this->assertTrue( $result['ok'] ?? false );
		$this->assertCount( 0, $this->http_calls );
	}

	/* ------------------------------------------------------------------
	 * purge_url error paths
	 * ------------------------------------------------------------------ */

	public function test_purge_url_returns_error_when_wp_remote_request_errors(): void {
		// Unknown path so purge_url() takes the direct-URL BAN branch (which
		// calls wp_remote_request() itself instead of routing via purge_tags()).
		$this->next_response = new WP_Error( 'http_request_failed', 'cURL error 28' );

		$result = ( new Purger() )->purge_url( home_url( '/2020/01/' ) );

		$this->assertFalse( $result['ok'] ?? null );
		$this->assertCount( 1, $this->http_calls );
		$this->assertStringContainsString( 'cURL error 28', (string) ( $result['message'] ?? '' ) );
	}

	public function test_purge_url_returns_error_on_5xx(): void {
		$this->next_response = $this->fake_response( 500 );

		$result = ( new Purger() )->purge_url( home_url( '/2020/01/' ) );

		$this->assertFalse( $result['ok'] ?? null );
		$this->assertCount( 1, $this->http_calls );
		$this->assertStringContainsString( '500', (string) ( $result['message'] ?? '' ) );
	}

	/**
	 * Build a fake wp_remote_request() response array for the stubbed filter.
	 *
	 * @return array<string,mixed>
	 */
	private function fake_response( int $code ): array {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
