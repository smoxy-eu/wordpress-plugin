<?php

namespace Smoxy\WP;

defined( 'ABSPATH' ) || exit;

class ConnectionChecker {


	public const OPTION_KEY = 'smoxy_connection_status';

	/**
	 * @return array{checked_at: int, url: string, ok: bool|null, server: string, message: string}
	 */
	public function check(): array {
		$url = home_url( '/' );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 3,
				'headers'     => array(
					'User-Agent'    => 'smoxy WP Connection Check',
					'Cache-Control' => 'no-cache',
				),
			)
		);

		$status = array(
			'checked_at' => time(),
			'url'        => $url,
			'ok'         => false,
			'server'     => '',
			'message'    => '',
		);

		if ( is_wp_error( $response ) ) {
			$status['message'] = sprintf(
			/* translators: 1: WP_Error code, 2: WP_Error message */
				__( 'Connection check failed (%1$s): %2$s', 'smoxy' ),
				$response->get_error_code(),
				$response->get_error_message()
			);
			update_option( self::OPTION_KEY, $status, false );
			return $status;
		}

		$server_raw = wp_remote_retrieve_header( $response, 'server' );
		if ( is_array( $server_raw ) ) {
			$server_raw = isset( $server_raw[0] ) ? $server_raw[0] : '';
		}
		$server = (string) $server_raw;
		$code   = (int) wp_remote_retrieve_response_code( $response );

		$status['server'] = $server;

		if ( $code < 200 || $code >= 300 ) {
			$status['ok']      = false;
			$status['message'] = sprintf(
			/* translators: %d: HTTP status code */
				__( 'Origin returned HTTP %d.', 'smoxy' ),
				$code
			);
		} elseif ( '' !== $server && false !== stripos( $server, 'smoxy' ) ) {
			$status['ok']      = true;
			$status['message'] = sprintf(
			/* translators: %s: Server header value */
				__( 'Connected — origin responded with Server: %s', 'smoxy' ),
				$server
			);
		} else {
			$status['ok']      = null;
			$status['message'] = '' === $server
				? esc_html__( 'Response is 2xx but the Server header is missing, so the smoxy fingerprint could not be confirmed. This is common when a reverse proxy strips the Server header.', 'smoxy' )
				: sprintf(
				/* translators: %s: Server header value */
					esc_html__( 'Response is 2xx but the Server header ("%s") does not contain "smoxy", so the smoxy fingerprint could not be confirmed.', 'smoxy' ),
					$server
				);
		}

		update_option( self::OPTION_KEY, $status, false );
		return $status;
	}

	/**
	 * Returns the persisted status array, or null if none is stored.
	 *
	 * The returned array shape is:
	 *   - 'checked_at' int  Unix timestamp.
	 *   - 'url'        string Checked URL.
	 *   - 'ok'         bool|null  true = confirmed smoxy, false = request
	 *                  failed / non-2xx, null = 2xx but fingerprint
	 *                  could not be confirmed.
	 *   - 'server'     string Server header value (may be empty).
	 *   - 'message'    string Human-readable status message.
	 *
	 * @return array{checked_at?: int, url?: string, ok?: bool|null, server?: string, message?: string}|null
	 */
	public static function get_status(): ?array {
		$status = get_option( self::OPTION_KEY, null );
		return is_array( $status ) ? $status : null;
	}
}
