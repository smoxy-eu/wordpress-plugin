<?php

namespace Smoxy\WP\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wp_remote_request() for the smoxy hub API
 * (https://api.smoxy.eu). Authenticates every call with the
 * X-API-TOKEN header from plugin settings.
 *
 * Every method returns an array of shape:
 *   array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
 *
 * Callers should branch on $result['ok'] and consume $result['body']
 * on success or $result['error'] for a human-readable message.
 */
class Client {


	public const BASE_URL = 'https://api.smoxy.eu';

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
		return $this->request( 'GET', '/api/organizations', array( 'itemsPerPage' => 100 ) );
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function list_zones( int $organization_id ): array {
		return $this->request(
			'GET',
			'/api/zones',
			array(
				'organization' => $organization_id,
				'itemsPerPage' => 100,
			)
		);
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function get_zone( int $zone_id ): array {
		return $this->request( 'GET', '/api/zones/' . $zone_id );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function create_zone( array $payload ): array {
		return $this->request( 'POST', '/api/zones', array(), $payload );
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function list_origins( int $organization_id ): array {
		return $this->request(
			'GET',
			'/api/organizations/' . $organization_id . '/origin-servers',
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
			'/api/organizations/' . $organization_id . '/origin-servers',
			array(),
			$payload
		);
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function list_hostnames( int $organization_id, ?string $hostname = null ): array {
		$query = array( 'itemsPerPage' => 100 );
		if ( null !== $hostname && '' !== $hostname ) {
			$query['q'] = $hostname;
		}
		return $this->request(
			'GET',
			'/api/organizations/' . $organization_id . '/hostnames',
			$query
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function create_hostname( int $zone_id, array $payload ): array {
		return $this->request( 'POST', '/api/zones/' . $zone_id . '/hostnames', array(), $payload );
	}

	/**
	 * Zone-to-zone hostname move: PATCH within the hostname's current zone
	 * with the new zone as an IRI (e.g. `{"zone": "/api/zones/42"}`).
	 *
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function patch_hostname( int $zone_id, string $hostname_id, array $payload ): array {
		return $this->request(
			'PATCH',
			'/api/zones/' . $zone_id . '/hostnames/' . rawurlencode( $hostname_id ),
			array(),
			$payload,
			'application/merge-patch+json'
		);
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function list_configuration_rules( int $zone_id ): array {
		return $this->request(
			'GET',
			'/api/zones/' . $zone_id . '/configuration-rules',
			array( 'itemsPerPage' => 100 )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function create_configuration_rule( int $zone_id, array $payload ): array {
		return $this->request(
			'POST',
			'/api/zones/' . $zone_id . '/configuration-rules',
			array(),
			$payload
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function patch_configuration_rule( int $zone_id, string $rule_id, array $payload ): array {
		// The plugin never renames its managed rules, so drop the name from
		// the payload — under merge-patch semantics omitted fields stay
		// unchanged, and this sidesteps unique-name validation tripping on a
		// self-update that re-sends the same name.
		unset( $payload['name'] );
		return $this->request(
			'PATCH',
			'/api/zones/' . $zone_id . '/configuration-rules/' . rawurlencode( $rule_id ),
			array(),
			$payload,
			'application/merge-patch+json'
		);
	}

	/**
	 * @return array{ok:bool, status:int, body:array<int|string,mixed>, error:?string}
	 */
	public function delete_configuration_rule( int $zone_id, string $rule_id ): array {
		return $this->request(
			'DELETE',
			'/api/zones/' . $zone_id . '/configuration-rules/' . rawurlencode( $rule_id )
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

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Accept'       => 'application/json',
				'X-API-TOKEN'  => $this->token,
				'User-Agent'   => 'smoxy-wordpress-plugin/' . ( defined( 'SMOXY_VERSION' ) ? SMOXY_VERSION : 'dev' ),
				'Content-Type' => $content_type,
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
