<?php

/**
 * Integration test — issues real HTTP requests against the running docker
 * WordPress stack and asserts on the actual X-Cache-Tags response header.
 *
 * Unlike CacheTagsTest (which captures the emission inside the WP test
 * harness via a namespaced header() stub), this test exercises the whole
 * pipeline end-to-end: request -> Apache -> PHP -> WP -> smoxy plugin ->
 * response headers the browser or edge actually receive.
 *
 * Prerequisites
 *   - `docker compose up -d` is running
 *   - Site contains at least the default "Hello world!" post at ID 1
 *
 * The test skips automatically if the WordPress service is unreachable (so
 * CI/local runs that only exercise the WP_UnitTestCase suite don't fail).
 *
 * The canonical host (used for the Host header) is discovered at runtime from
 * WordPress itself — we hit /wp-json/ without a Host header, follow the
 * redirect WP issues to its own siteurl, and extract the host with
 * wp_parse_url(). This keeps the test in sync with whatever home URL the live
 * container is configured with, rather than hard-coding it.
 *
 * Base URL can be overridden via env var:
 *   SMOXY_IT_BASE_URL   default: http://wordpress  (compose service hostname)
 */
class CacheTagsIntegrationTest extends \PHPUnit\Framework\TestCase {


	private const DEFAULT_BASE_URL = 'http://wordpress';

	/** Cached so we don't probe /wp-json/ for every test. */
	private static ?string $resolved_host = null;

	public function setUp(): void {
		parent::setUp();
		if ( null === $this->host() ) {
			$this->markTestSkipped(
				'WordPress service not reachable at ' . $this->base_url() .
				'. Run `docker compose up -d` first, or set SMOXY_IT_BASE_URL.'
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Happy paths
	 * ------------------------------------------------------------------ */

	public function test_home_page_returns_X_Cache_Tags_with_home_tag(): void {
		$response = $this->request( '/' );
		$this->assertNotNull( $response );
		$this->assertSame( 200, $response['status'] );

		$tags = $this->cache_tags( $response );
		$this->assertContains( 'home', $tags );
	}

	public function test_singular_post_returns_X_Cache_Tags_with_post_id(): void {
		$response = $this->request( '/hello-world/' );
		$this->assertNotNull( $response );
		$this->assertSame( 200, $response['status'] );

		$this->assertContains( 'p-1', $this->cache_tags( $response ) );
	}

	public function test_feed_returns_X_Cache_Tags_with_feed_tag(): void {
		$response = $this->request( '/?feed=rss2' );
		$this->assertNotNull( $response );
		$this->assertSame( 200, $response['status'] );

		$this->assertContains( 'feed', $this->cache_tags( $response ) );
	}

	public function test_every_tag_matches_known_shape(): void {
		$response = $this->request( '/' );
		$this->assertNotNull( $response );

		foreach ( $this->cache_tags( $response ) as $tag ) {
			$this->assertMatchesRegularExpression(
				'/^(home|feed|p-\d+|t-\d+|a-\d+)$/',
				$tag,
				sprintf( 'Unexpected tag on home page: %s', $tag )
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Skip paths — response must NOT carry X-Cache-Tags
	 * ------------------------------------------------------------------ */

	public function test_rest_request_does_not_return_X_Cache_Tags(): void {
		$response = $this->request( '/wp-json/' );
		$this->assertNotNull( $response );
		$this->assertSame( 200, $response['status'] );

		$this->assertArrayNotHasKey(
			'x-cache-tags',
			$response['headers'],
			'REST responses must not carry X-Cache-Tags'
		);
	}

	public function test_404_page_does_not_return_X_Cache_Tags(): void {
		$response = $this->request( '/?p=999999' );
		$this->assertNotNull( $response );
		$this->assertSame( 404, $response['status'] );

		$this->assertArrayNotHasKey(
			'x-cache-tags',
			$response['headers'],
			'404 pages have no tags to purge and must omit the header'
		);
	}

	public function test_post_request_to_page_does_not_return_X_Cache_Tags(): void {
		$response = $this->request( '/hello-world/', null, 'POST' );
		$this->assertNotNull( $response );
		$this->assertSame( 200, $response['status'] );

		$this->assertArrayNotHasKey(
			'x-cache-tags',
			$response['headers'],
			'POST responses are not cacheable — the header must be omitted'
		);
	}

	public function test_put_request_to_page_does_not_return_X_Cache_Tags(): void {
		$response = $this->request( '/hello-world/', null, 'PUT' );
		$this->assertNotNull( $response );

		$this->assertArrayNotHasKey(
			'x-cache-tags',
			$response['headers'],
			'PUT/DELETE responses are not cacheable — the header must be omitted'
		);
	}

	public function test_login_page_does_not_return_X_Cache_Tags(): void {
		// wp-login.php is a standalone entry point that bypasses template_redirect
		// entirely — the frontend cache-tags header must never appear there.
		$response = $this->request( '/wp-login.php' );
		$this->assertNotNull( $response );
		$this->assertSame( 200, $response['status'] );

		$this->assertArrayNotHasKey(
			'x-cache-tags',
			$response['headers'],
			'wp-login.php must not carry the frontend cache-tags header'
		);
	}

	/* ------------------------------------------------------------------
	 * HTTP helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Raw curl is intentional here: wp_remote_get() would be intercepted by
	 * the bootstrap's pre_http_request fake-response filter and never hit
	 * the real container. Integration tests need the actual network call.
	 *
	 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
	 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
	 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
	 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_close
	 * phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
	 *
	 * @param string|null $host_override When null, uses the resolved host. The
	 *                                   only caller that passes '' is the
	 *                                   host-discovery probe itself.
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 */
	private function request( string $path, ?string $host_override = null, string $method = 'GET' ): ?array {
		$url     = rtrim( $this->base_url(), '/' ) . $path;
		$host    = null === $host_override ? $this->host() : $host_override;
		$headers = ( null !== $host && '' !== $host ) ? array( 'Host: ' . $host ) : array();

		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_NOBODY         => 'HEAD' === $method,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 5,
				CURLOPT_TIMEOUT        => 5,
				CURLOPT_CONNECTTIMEOUT => 2,
			)
		);

		$raw = curl_exec( $ch );
		if ( false === $raw ) {
			return null;
		}

		$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$hsize  = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );

		$header_blob = (string) substr( (string) $raw, 0, $hsize );
		$body        = (string) substr( (string) $raw, $hsize );

		return array(
			'status'  => $status,
			'headers' => $this->parse_final_headers( $header_blob ),
			'body'    => $body,
		);
	}
	// phpcs:enable WordPress.WP.AlternativeFunctions

	/**
	 * When CURLOPT_FOLLOWLOCATION is on, the header blob contains all header
	 * sets from every hop. We only care about the last one.
	 *
	 * @return array<string,string>
	 */
	private function parse_final_headers( string $blob ): array {
		$blocks = preg_split( "/\r?\n\r?\n/", trim( $blob ) );
		$last   = is_array( $blocks ) && ! empty( $blocks ) ? (string) end( $blocks ) : '';
		$lines  = preg_split( "/\r?\n/", $last );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		$headers = array();
		foreach ( $lines as $line ) {
			$pos = strpos( $line, ':' );
			if ( false === $pos ) {
				continue;
			}
			$key             = strtolower( trim( substr( $line, 0, $pos ) ) );
			$value           = trim( substr( $line, $pos + 1 ) );
			$headers[ $key ] = $value;
		}
		return $headers;
	}

	/**
	 * @param array{status:int,headers:array<string,string>,body:string} $response
	 * @return string[]
	 */
	private function cache_tags( array $response ): array {
		if ( ! isset( $response['headers']['x-cache-tags'] ) ) {
			return array();
		}
		return explode( ',', $response['headers']['x-cache-tags'] );
	}

	private function base_url(): string {
		$env = getenv( 'SMOXY_IT_BASE_URL' );
		return ( false === $env || '' === $env ) ? self::DEFAULT_BASE_URL : $env;
	}

	/**
	 * Discover the host header the live WordPress expects by asking WP itself:
	 * fetch /wp-json/ (no Host header; follow redirects) and read the "home"
	 * URL it returns. Parse that with wp_parse_url() and combine host+port.
	 *
	 * Returns null if the container is unreachable or the response isn't
	 * parseable — setUp() then marks the test skipped.
	 */
	private function host(): ?string {
		if ( null !== self::$resolved_host ) {
			return self::$resolved_host;
		}

		$response = $this->request( '/wp-json/', '' );
		if ( null === $response || 200 !== $response['status'] ) {
			return null;
		}
		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) || ! isset( $data['home'] ) ) {
			return null;
		}

		$home = (string) $data['home'];
		$host = wp_parse_url( $home, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return null;
		}

		$port = wp_parse_url( $home, PHP_URL_PORT );
		if ( is_int( $port ) ) {
			$host .= ':' . $port;
		}

		self::$resolved_host = $host;
		return $host;
	}
}
