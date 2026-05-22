<?php

namespace Smoxy\WP\Setup;

use Smoxy\WP\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the expected conditional rules (from RuleDefinitions) against
 * what's actually configured on the bound zone, surfacing missing rules
 * and drift so the settings page can show a status report and offer a
 * one-click fix.
 */
class Audit {


	public const STATUS_OK      = 'ok';
	public const STATUS_DRIFTED = 'drifted';
	public const STATUS_MISSING = 'missing';

	private Client $client;

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * @return array{ok:bool, error:?string, rules:array<string, array{key:string, expected_name:string, status:string, remote_id:?int, remote_position:?int, diff:?string}>}
	 */
	public function audit_zone( int $zone_id ): array {
		$response = $this->client->list_conditional_rules( $zone_id );
		if ( ! $response['ok'] ) {
			return array(
				'ok'    => false,
				'error' => $response['error'],
				'rules' => array(),
			);
		}

		$remote_by_name = array();
		foreach ( $this->extract_members( $response['body'] ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['name'] ) || ! is_string( $item['name'] ) ) {
				continue;
			}
			$remote_by_name[ $item['name'] ] = $item;
		}

		$report = array();
		foreach ( RuleDefinitions::all() as $key => $expected ) {
			$remote         = $remote_by_name[ $expected['name'] ] ?? null;
			$report[ $key ] = $this->compare( $key, $expected, $remote );
		}

		return array(
			'ok'    => true,
			'error' => null,
			'rules' => $report,
		);
	}

	/**
	 * @param array{name:string, key:string, description:string, expected_position:?int, payload:array<string,mixed>} $expected
	 * @param array<string,mixed>|null $remote
	 * @return array{key:string, expected_name:string, status:string, remote_id:?int, remote_position:?int, diff:?string}
	 */
	private function compare( string $key, array $expected, ?array $remote ): array {
		if ( null === $remote ) {
			return array(
				'key'             => $key,
				'expected_name'   => $expected['name'],
				'status'          => self::STATUS_MISSING,
				'remote_id'       => null,
				'remote_position' => null,
				'diff'            => null,
			);
		}

		$remote_id       = isset( $remote['id'] ) && is_numeric( $remote['id'] ) ? (int) $remote['id'] : null;
		$remote_position = isset( $remote['position'] ) && is_numeric( $remote['position'] ) ? (int) $remote['position'] : null;

		$expected_payload  = $expected['payload'];
		$expected_position = $expected['expected_position'] ?? null;
		$drift             = $this->find_drift( $expected_payload, $remote, $expected_position, $remote_position );

		return array(
			'key'             => $key,
			'expected_name'   => $expected['name'],
			'status'          => null === $drift ? self::STATUS_OK : self::STATUS_DRIFTED,
			'remote_id'       => $remote_id,
			'remote_position' => $remote_position,
			'diff'            => $drift,
		);
	}

	/**
	 * Returns a short, human-readable summary of the first field that
	 * differs between the expected payload and the remote rule, or null
	 * if everything we care about matches.
	 *
	 * @param array<string,mixed> $expected
	 * @param array<string,mixed> $remote
	 */
	private function find_drift( array $expected, array $remote, ?int $expected_position = null, ?int $remote_position = null ): ?string {
		if ( $this->normalize_bool( $remote['enabled'] ?? null ) !== ( $expected['enabled'] ?? null ) ) {
			return __( 'Rule is disabled on the zone.', 'smoxy' );
		}

		// Stop flag matters for the images rule, which depends on stop=true to
		// short-circuit downstream rules. Comparing it for every rule keeps the
		// drift check uniform.
		if ( $this->normalize_bool( $remote['stop'] ?? null ) !== (bool) ( $expected['stop'] ?? false ) ) {
			return __( 'Stop flag differs from the plugin default.', 'smoxy' );
		}

		// Only check position drift for rules that declare an expected slot
		// (the images rule does; the bypass rules don't, since users may
		// reorder them around their own custom rules).
		if ( null !== $expected_position && null !== $remote_position && $expected_position !== $remote_position ) {
			return __( 'Position differs from the plugin default.', 'smoxy' );
		}

		$expected_expr = $this->normalize_expression( $expected['expressions'] ?? null );
		$remote_expr   = $this->normalize_expression( $remote['expressions'] ?? null );
		if ( $this->canonicalize( $expected_expr ) !== $this->canonicalize( $remote_expr ) ) {
			return __( 'Expression differs from the plugin default.', 'smoxy' );
		}

		$expected_actions = $this->canonicalize( $expected['rules'] ?? null );
		$remote_actions   = $this->canonicalize( $this->normalize_actions( $remote['rules'] ?? null ) );
		if ( $expected_actions !== $remote_actions ) {
			return __( 'Action settings differ from the plugin default.', 'smoxy' );
		}

		return null;
	}

	/**
	 * Reduce the expression to a comparable canonical form. The hub stores
	 * leaf rules with `target` and `value` normalized to null when the field
	 * is target-less (e.g. `uri`) or the operator carries no value (e.g.
	 * `exists`) — but the API accepts both empty-string and null on POST, so
	 * we normalize both sides here.
	 *
	 * @param mixed $value
	 * @return array<string,mixed>|null
	 */
	private function normalize_expression( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		if ( ! isset( $value['rules'] ) || ! is_array( $value['rules'] ) ) {
			return null;
		}
		$condition = is_string( $value['condition'] ?? null ) ? strtolower( $value['condition'] ) : '';
		$rules     = array();
		foreach ( array_values( $value['rules'] ) as $item ) {
			$rules[] = $this->normalize_expression_rule( $item );
		}
		return array(
			'condition' => $condition,
			'rules'     => $rules,
		);
	}

	/**
	 * Normalize a single leaf or nested-group rule. Empty strings on
	 * `target` and `value` collapse to null so the canonical comparison
	 * matches what the hub returns from GET.
	 *
	 * @param mixed $rule
	 * @return array<string,mixed>
	 */
	private function normalize_expression_rule( $rule ): array {
		if ( ! is_array( $rule ) ) {
			return array();
		}
		// Nested group (has its own rules + condition keys).
		if ( isset( $rule['rules'] ) && is_array( $rule['rules'] ) ) {
			$nested = $this->normalize_expression( $rule );
			return null === $nested ? array() : $nested;
		}
		return array(
			'field'    => isset( $rule['field'] ) ? (string) $rule['field'] : '',
			'target'   => $this->blank_to_null( $rule['target'] ?? null ),
			'operator' => isset( $rule['operator'] ) ? (string) $rule['operator'] : '',
			'value'    => $this->blank_to_null( $rule['value'] ?? null ),
		);
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function blank_to_null( $value ) {
		if ( '' === $value ) {
			return null;
		}
		return $value;
	}

	/**
	 * The hub returns action rules as a list of {name, value} entries; some
	 * responses wrap the value in an extra layer — strip everything down to
	 * the minimal shape we POST.
	 *
	 * @param mixed $value
	 * @return list<array{name:string, value:mixed}>
	 */
	private function normalize_actions( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['name'] ) ) {
				continue;
			}
			$out[] = array(
				'name'  => (string) $item['name'],
				'value' => $item['value'] ?? null,
			);
		}
		return $out;
	}

	/**
	 * @param mixed $value
	 */
	private function normalize_bool( $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		if ( is_string( $value ) ) {
			$lower = strtolower( $value );
			if ( in_array( $lower, array( 'true', '1', 'yes' ), true ) ) {
				return true;
			}
			if ( in_array( $lower, array( 'false', '0', 'no', '' ), true ) ) {
				return false;
			}
		}
		return null;
	}

	/**
	 * JSON-encode with sorted keys so structurally-equivalent shapes
	 * compare equal regardless of key order returned by the API.
	 *
	 * @param mixed $value
	 */
	private function canonicalize( $value ): string {
		$normalized = $this->sort_recursive( $value );
		return (string) wp_json_encode( $normalized );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function sort_recursive( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		$mapped  = array_map( array( $this, 'sort_recursive' ), $value );
		if ( ! $is_list ) {
			ksort( $mapped );
		}
		return $mapped;
	}

	/**
	 * @param array<int|string,mixed> $body
	 * @return list<mixed>
	 */
	private function extract_members( array $body ): array {
		// Hydra-wrapped responses live under member or hydra:member.
		foreach ( array( 'member', 'hydra:member' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				return array_values( $body[ $key ] );
			}
		}
		// Plain JSON: top-level list.
		if ( array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
			return array_values( $body );
		}
		return array();
	}
}
