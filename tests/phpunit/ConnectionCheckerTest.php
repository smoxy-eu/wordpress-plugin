<?php

use Smoxy\WP\ConnectionChecker;

/**
 * Unit tests for Smoxy\WP\ConnectionChecker.
 *
 * Stubs the outbound HTTP request via the `pre_http_request` filter — the
 * same pattern used in PurgerTest — so the checker observes a deterministic
 * response shape without ever hitting the network.
 */
class ConnectionCheckerTest extends WP_UnitTestCase {


	/** @var false|array|\WP_Error */
	private $next_response = false;

	public function set_up(): void {
		parent::set_up();

		$this->next_response = false;
		delete_option( ConnectionChecker::OPTION_KEY );

		add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
		delete_option( ConnectionChecker::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Stub the next outbound request. Returning a non-false value short-circuits
	 * wp_remote_get(); the bootstrap-level fallback otherwise produces an empty
	 * 200, which would mask the differences we want to test.
	 *
	 * @param false|array|\WP_Error $preempt
	 * @param array<string,mixed>   $args
	 */
	public function capture_http( $preempt, $args, string $url ) {
		if ( false !== $this->next_response ) {
			return $this->next_response;
		}
		return $preempt;
	}

	/* ------------------------------------------------------------------
	 * check()
	 * ------------------------------------------------------------------ */

	public function test_check_with_smoxy_server_header_returns_ok_true(): void {
		$this->next_response = $this->fake_response( 200, array( 'server' => 'smoxy/1.0' ) );

		$status = ( new ConnectionChecker() )->check();

		$this->assertSame( true, $status['ok'] ?? null );
		$this->assertIsInt( $status['checked_at'] ?? null );
		$this->assertStringContainsString( 'Server: smoxy/1.0', (string) ( $status['message'] ?? '' ) );

		$persisted = get_option( ConnectionChecker::OPTION_KEY );
		$this->assertIsArray( $persisted );
		$this->assertSame( true, $persisted['ok'] ?? null );
	}

	public function test_check_with_non_smoxy_server_header_returns_ok_null(): void {
		$this->next_response = $this->fake_response( 200, array( 'server' => 'nginx' ) );

		$status = ( new ConnectionChecker() )->check();

		$this->assertArrayHasKey( 'ok', $status );
		$this->assertNull( $status['ok'] );
		$this->assertStringContainsString( 'fingerprint could not be confirmed', (string) ( $status['message'] ?? '' ) );
		$this->assertStringContainsString( 'nginx', (string) ( $status['message'] ?? '' ) );
	}

	public function test_check_with_missing_server_header_returns_ok_null(): void {
		$this->next_response = $this->fake_response( 200, array() );

		$status = ( new ConnectionChecker() )->check();

		$this->assertArrayHasKey( 'ok', $status );
		$this->assertNull( $status['ok'] );
		$this->assertStringContainsString( 'Server header is missing', (string) ( $status['message'] ?? '' ) );
	}

	public function test_check_with_wp_error_returns_ok_false_and_includes_code(): void {
		$this->next_response = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );

		$status = ( new ConnectionChecker() )->check();

		$this->assertSame( false, $status['ok'] ?? null );
		$this->assertStringContainsString( 'http_request_failed', (string) ( $status['message'] ?? '' ) );
	}

	public function test_check_with_non_2xx_returns_ok_false_and_includes_status(): void {
		$this->next_response = $this->fake_response( 500, array( 'server' => 'smoxy/1.0' ) );

		$status = ( new ConnectionChecker() )->check();

		$this->assertSame( false, $status['ok'] ?? null );
		$this->assertStringContainsString( '500', (string) ( $status['message'] ?? '' ) );
	}

	/* ------------------------------------------------------------------
	 * get_status()
	 * ------------------------------------------------------------------ */

	public function test_get_status_returns_cached_option_after_check(): void {
		$this->next_response = $this->fake_response( 200, array( 'server' => 'smoxy/1.0' ) );

		( new ConnectionChecker() )->check();

		$status = ConnectionChecker::get_status();
		$this->assertIsArray( $status );
		$this->assertSame( true, $status['ok'] ?? null );
		$this->assertIsInt( $status['checked_at'] ?? null );
	}

	public function test_get_status_returns_null_when_no_check_has_run(): void {
		$this->assertNull( ConnectionChecker::get_status() );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Build a fake wp_remote_get() response array.
	 *
	 * @param array<string,string> $headers
	 * @return array<string,mixed>
	 */
	private function fake_response( int $code, array $headers ): array {
		return array(
			'headers'  => $headers,
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
