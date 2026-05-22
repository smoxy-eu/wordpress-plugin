<?php

namespace Smoxy\WP\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wp_remote_request() for the smoxy hub API
 * (https://hub.smoxy.eu). Authenticates every call with the
 * X-API-TOKEN header from plugin settings.
 *
 * Every method returns an array of shape:
 *   array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
 *
 * Callers should branch on $result['ok'] and consume $result['body']
 * on success or $result['error'] for a human-readable message.
 */
class Client {


	public const BASE_URL = 'https://hub.smoxy.eu';

	private string $token;

	public function __construct( string $token ) {
		$this->token = $token;
	}

	/* ------------------------------------------------------------------
	 * High-level endpoints
	 * ------------------------------------------------------------------ */

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function list_organizations(): array {
		return $this->request( 'GET', '/api/v2/organizations', array( 'itemsPerPage' => 100 ) );
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function list_zones( int $organization_id ): array {
		return $this->request(
			'GET',
			'/api/v2/delivery/zone',
			array(
				'organization' => $organization_id,
				'itemsPerPage' => 100,
				'is_deleted'   => 'false',
			)
		);
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function get_zone( int $zone_id ): array {
		return $this->request( 'GET', '/api/v2/delivery/zone/' . $zone_id );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function create_zone( array $payload ): array {
		return $this->request( 'POST', '/api/v2/delivery/zone', array(), $payload );
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function list_origins( int $organization_id ): array {
		return $this->request(
			'GET',
			'/api/v2/organizations/' . $organization_id . '/origin-servers',
			array( 'itemsPerPage' => 100 )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function create_origin( int $organization_id, array $payload ): array {
		return $this->request(
			'POST',
			'/api/v2/organizations/' . $organization_id . '/origin-servers',
			array(),
			$payload
		);
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function list_hostnames( int $organization_id, ?string $hostname = null ): array {
		$query = array(
			'organization' => $organization_id,
			'itemsPerPage' => 100,
		);
		if ( null !== $hostname && '' !== $hostname ) {
			$query['hostname'] = $hostname;
		}
		return $this->request( 'GET', '/api/v2/hostnames', $query );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function create_hostname( array $payload ): array {
		return $this->request( 'POST', '/api/v2/hostnames', array(), $payload );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function patch_hostname( int $hostname_id, array $payload ): array {
		return $this->request(
			'PATCH',
			'/api/v2/hostnames/' . $hostname_id,
			array(),
			$payload,
			'application/merge-patch+json'
		);
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function list_conditional_rules( int $zone_id ): array {
		return $this->request(
			'GET',
			'/api/v2/delivery/zone/' . $zone_id . '/conditional-rule',
			array( 'itemsPerPage' => 100 )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function create_conditional_rule( int $zone_id, array $payload ): array {
		return $this->request(
			'POST',
			'/api/v2/delivery/zone/' . $zone_id . '/conditional-rule',
			array(),
			$payload
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function patch_conditional_rule( int $zone_id, int $rule_id, array $payload ): array {
		// The hub's UniqueValidator compares the URL `id` (string) to the
		// entity id (int) with strict ===, so it never recognizes a self-update
		// and trips "name already exists" on every PATCH that includes the
		// same name. We don't change rule names from the plugin anyway —
		// dropping the field here works around the bug under merge-patch semantics
		// (omitted fields stay unchanged).
		unset( $payload['name'] );
		return $this->request(
			'PATCH',
			'/api/v2/delivery/zone/' . $zone_id . '/conditional-rule/' . $rule_id,
			array(),
			$payload,
			'application/merge-patch+json'
		);
	}

	/**
	 * Reorder a conditional rule. The hub assigns positions sequentially on
	 * create and ignores `position` in the create/patch payloads — the only
	 * way to move a rule is this dedicated endpoint. Positions are 1-based;
	 * out-of-range values are clamped by the server (per PositionProcessor).
	 *
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function patch_conditional_rule_position( int $zone_id, int $rule_id, int $position ): array {
		return $this->request(
			'PATCH',
			'/api/v2/delivery/zone/' . $zone_id . '/conditional-rule/' . $rule_id . '/position',
			array(),
			array( 'position' => $position ),
			'application/merge-patch+json'
		);
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function delete_conditional_rule( int $zone_id, int $rule_id ): array {
		return $this->request(
			'DELETE',
			'/api/v2/delivery/zone/' . $zone_id . '/conditional-rule/' . $rule_id
		);
	}

	/* ------------------------------------------------------------------
	 * Transport
	 * ------------------------------------------------------------------ */

	/**
	 * @param array<string,mixed>      $query
	 * @param array<string,mixed>|null $body
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	private function request(
		string $method,
		string $path,
		array $query = array(),
		?array $body = null,
		string $content_type = 'application/json'
	): array {
		if ( '' === $this->token ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => array(),
				'error'  => __( 'API token is not configured.', 'smoxy' ),
			);
		}

		$url = self::BASE_URL . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( array_map( static fn( $v ) => is_bool( $v ) ? ( $v ? 'true' : 'false' ) : $v, $query ), $url );
		}

		// The hub's open_api_v2 firewall uses Symfony's access_token
		// authenticator with no custom extractor, so the token must arrive
		// as `Authorization: Bearer <token>`. The OpenAPI doc that names
		// X-API-TOKEN is misleading — sending only that header returns 401.
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Accept'        => 'application/json',
				'Authorization' => 'Bearer ' . $this->token,
				'User-Agent'    => 'smoxy-wordpress-plugin/' . ( defined( 'SMOXY_VERSION' ) ? SMOXY_VERSION : 'dev' ),
				'Content-Type'  => $content_type,
			),
		);

		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );
			if ( false === $encoded ) {
				return array(
					'ok'     => false,
					'status' => 0,
					'body'   => array(),
					'error'  => __( 'Could not encode the request payload as JSON.', 'smoxy' ),
				);
			}
			$args['body'] = $encoded;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => array(),
				'error'  => $response->get_error_message(),
			);
		}

		$status      = (int) wp_remote_retrieve_response_code( $response );
		$raw_body    = (string) wp_remote_retrieve_body( $response );
		$decoded     = '' === $raw_body ? array() : json_decode( $raw_body, true );
		$decoded_arr = is_array( $decoded ) ? $decoded : array();

		if ( $status < 200 || $status >= 300 ) {
			return array(
				'ok'     => false,
				'status' => $status,
				'body'   => $decoded_arr,
				'error'  => $this->extract_error_message( $decoded_arr, $status ),
			);
		}

		return array(
			'ok'     => true,
			'status' => $status,
			'body'   => $decoded_arr,
			'error'  => null,
		);
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function extract_error_message( array $body, int $status ): string {
		// API Platform / Hydra problem+json shape.
		foreach ( array( 'hydra:description', 'detail', 'title', 'message', 'error' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_string( $body[ $key ] ) && '' !== $body[ $key ] ) {
				return $body[ $key ];
			}
		}
		if ( isset( $body['violations'] ) && is_array( $body['violations'] ) ) {
			$messages = array();
			foreach ( $body['violations'] as $v ) {
				if ( is_array( $v ) && isset( $v['message'] ) && is_string( $v['message'] ) ) {
					$path       = isset( $v['propertyPath'] ) && is_string( $v['propertyPath'] ) ? $v['propertyPath'] . ': ' : '';
					$messages[] = $path . $v['message'];
				}
			}
			if ( ! empty( $messages ) ) {
				return implode( '; ', $messages );
			}
		}
		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'smoxy hub returned HTTP %d.', 'smoxy' ),
			$status
		);
	}
}
