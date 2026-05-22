<?php

namespace Smoxy\WP;

defined( 'ABSPATH' ) || exit;

class Purger {


	public const INGRESS_URL = 'https://ingress.smoxy.eu';

	/**
	 * @return array{ok: bool, message: string}
	 */
	public function purge_all(): array {
		return $this->send(
			array(
				'type' => 'flushall',
			),
			__( 'All cached pages have been purged.', 'smoxy' )
		);
	}

	/**
	 * @return array{ok: bool, message: string}
	 */
	public function purge_url( string $url ): array {
		$url = $this->normalize_url( $url );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Please enter a valid URL.', 'smoxy' ),
			);
		}

		if ( ! $this->is_internal_url( $url ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'URL is not on this site; nothing to purge.', 'smoxy' ),
			);
		}

		return $this->purge_single_url( $url );
	}

	/**
	 * Low-level: purge exactly one URL, routing through a tag BAN when it
	 * resolves to a known post (so every cached variant is invalidated) and
	 * falling back to a direct URL BAN otherwise. No HTML crawling, no
	 * same-origin checks — callers are responsible for those.
	 *
	 * @return array{ok: bool, message: string}
	 */
	private function purge_single_url( string $url ): array {
		$post_id = url_to_postid( $url );
		if ( $post_id > 0 ) {
			$result = $this->purge_tags( array( 'p-' . $post_id ) );
			if ( ! empty( $result['ok'] ) ) {
				$result['message'] = sprintf(
					/* translators: %s: URL */
					__( 'Purged %s from smoxy.', 'smoxy' ),
					$url
				);
			}
			return $result;
		}

		$token = Settings::get_secret_key();
		if ( '' === $token ) {
			return array(
				'ok'      => false,
				'message' => __( 'Secret token is not configured.', 'smoxy' ),
			);
		}

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'BAN',
				'timeout' => 2,
				'headers' => array(
					'secret'     => $token,
					'User-Agent' => 'smoxy WP BAN',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
				/* translators: 1: HTTP status code, 2: URL */
					__( 'smoxy returned HTTP %1$d for %2$s.', 'smoxy' ),
					(int) $code,
					$url
				),
			);
		}

		return array(
			'ok'      => true,
			'message' => sprintf(
			/* translators: %s: URL */
				__( 'Purged %s from smoxy.', 'smoxy' ),
				$url
			),
		);
	}

	/**
	 * Normalizes user input into an absolute URL on this site:
	 *  - empty/whitespace -> home page
	 *  - no scheme://host  -> resolved against home_url() (e.g. "/page",
	 *                         "page/", "some/deep/path")
	 *  - absolute URL      -> returned untouched (after esc_url_raw)
	 *
	 * Host validation still happens downstream via is_internal_url().
	 */
	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return esc_url_raw( home_url( '/' ) );
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			return esc_url_raw( $url );
		}
		$path = '/' === $url[0] ? $url : '/' . $url;
		return esc_url_raw( home_url( $path ) );
	}

	/**
	 * True when $url's host matches this site's home_url() host. External
	 * hosts are rejected because smoxy only fronts this origin.
	 */
	private function is_internal_url( string $url ): bool {
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return '' !== $url_host && '' !== $home_host && $url_host === $home_host;
	}

	/**
	 * Bulk-BAN a set of URLs in parallel via the Requests library that ships
	 * with WordPress core. Designed for cases (asset/image invalidation) where
	 * issuing N sequential wp_remote_request() calls would multiply tail
	 * latency at the end of a request.
	 *
	 * Unlike purge_url(), this path does not consult url_to_postid() — callers
	 * use this for asset URLs that never resolve to a post anyway.
	 *
	 * @param list<string> $urls
	 * @return array{ok: bool, message: string}
	 */
	public function purge_urls( array $urls ): array {
		$urls = array_values(
			array_unique(
				array_filter(
					array_map( array( $this, 'normalize_url' ), $urls ),
					array( $this, 'is_internal_url' )
				)
			)
		);

		if ( empty( $urls ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'No URLs to purge.', 'smoxy' ),
			);
		}

		$token = Settings::get_secret_key();
		if ( '' === $token ) {
			return array(
				'ok'      => false,
				'message' => __( 'Secret token is not configured.', 'smoxy' ),
			);
		}

		/**
		 * Filter test seam: short-circuits the parallel HTTP dispatch when an
		 * array is returned. The array must map each URL to a per-URL result
		 * shaped like array{ok:bool, message:string}. Any non-array return
		 * value (default null) lets the real Requests::request_multiple call
		 * proceed.
		 *
		 * @param mixed        $preempt Default null — proceeds with real dispatch.
		 * @param list<string> $urls    Normalized, deduped, internal URLs about to be BAN'd.
		 */
		$preempt = apply_filters( 'smoxy_pre_purge_urls', null, $urls );
		if ( is_array( $preempt ) ) {
			return $this->aggregate_url_results( $preempt );
		}

		$headers = array(
			'secret'     => $token,
			'User-Agent' => 'smoxy WP BAN',
		);

		$requests = array();
		foreach ( $urls as $url ) {
			$requests[ $url ] = array(
				'url'     => $url,
				'type'    => 'BAN',
				'headers' => $headers,
			);
		}

		$options = array( 'timeout' => 2 );

		// WP 6.2 moved Requests into the \WpOrg\Requests namespace and kept
		// the bare \Requests class as a deprecated alias. Prefer the new one
		// to avoid raising deprecation notices on current WordPress.
		if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
			$responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
		} elseif ( class_exists( '\Requests' ) ) {
			// Deprecated alias used only on WP < 6.2 — the new namespace is preferred above.
			$responses = \Requests::request_multiple( $requests, $options );
		} else {
			return array(
				'ok'      => false,
				'message' => __( 'Requests library not available; cannot purge URLs in parallel.', 'smoxy' ),
			);
		}

		$results = array();
		foreach ( $responses as $url => $response ) {
			if ( $response instanceof \Throwable ) {
				$results[ (string) $url ] = array(
					'ok'      => false,
					'message' => $response->getMessage(),
				);
				continue;
			}
			$code = is_object( $response ) && isset( $response->status_code ) ? (int) $response->status_code : 0;
			if ( $code < 200 || $code >= 300 ) {
				$results[ (string) $url ] = array(
					'ok'      => false,
					'message' => sprintf(
						/* translators: 1: HTTP status code, 2: URL */
						__( 'smoxy returned HTTP %1$d for %2$s.', 'smoxy' ),
						$code,
						(string) $url
					),
				);
				continue;
			}
			$results[ (string) $url ] = array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %s: URL */
					__( 'Purged %s from smoxy.', 'smoxy' ),
					(string) $url
				),
			);
		}

		return $this->aggregate_url_results( $results );
	}

	/**
	 * @param array<string, array{ok: bool, message: string}> $results
	 * @return array{ok: bool, message: string}
	 */
	private function aggregate_url_results( array $results ): array {
		$failed = array();
		foreach ( $results as $url => $result ) {
			if ( empty( $result['ok'] ) ) {
				$failed[] = $url;
			}
		}

		if ( empty( $failed ) ) {
			return array(
				'ok'      => true,
				'message' => sprintf(
					/* translators: %d: number of URLs */
					_n( 'Purged %d URL from smoxy.', 'Purged %d URLs from smoxy.', count( $results ), 'smoxy' ),
					count( $results )
				),
			);
		}

		return array(
			'ok'      => false,
			'message' => sprintf(
				/* translators: 1: number of failed URLs, 2: comma-separated URLs */
				__( '%1$d URL(s) failed to purge: %2$s', 'smoxy' ),
				count( $failed ),
				implode( ', ', $failed )
			),
		);
	}

	/**
	 * @param array<int|string, mixed> $tags
	 * @return array{ok: bool, message: string}
	 */
	public function purge_tags( array $tags ): array {
		$tags = array_values( array_unique( array_filter( array_map( 'strval', $tags ) ) ) );
		if ( empty( $tags ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'No tags to purge.', 'smoxy' ),
			);
		}

		return $this->send(
			array(
				'type' => 'tag',
				'tags' => implode( ',', $tags ),
			),
			sprintf(
			/* translators: %s: comma-separated tags */
				__( 'Purged tags: %s', 'smoxy' ),
				implode( ', ', $tags )
			)
		);
	}

	/**
	 * @param array<string, string> $extra_headers
	 * @return array{ok: bool, message: string}
	 */
	private function send( array $extra_headers, string $success_message ): array {
		$token = Settings::get_secret_key();
		if ( '' === $token ) {
			return array(
				'ok'      => false,
				'message' => __( 'Secret token is not configured.', 'smoxy' ),
			);
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$headers = array_merge(
			array(
				'Host'       => $host,
				'secret'     => $token,
				'User-Agent' => 'smoxy WP BAN',
			),
			$extra_headers
		);

		$response = wp_remote_request(
			self::INGRESS_URL,
			array(
				'method'  => 'BAN',
				'timeout' => 2,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
				/* translators: %d: HTTP status code */
					__( 'smoxy returned HTTP %d.', 'smoxy' ),
					(int) $code
				),
			);
		}

		return array(
			'ok'      => true,
			'message' => $success_message,
		);
	}
}
