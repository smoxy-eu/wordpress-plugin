<?php

namespace Smoxy\WP\Setup;

use Smoxy\WP\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the expected configuration rules (from RuleDefinitions) and the
 * managed zone-level cache settings (from ZoneSettings) against what's
 * actually configured on the bound zone, surfacing missing rules and drift
 * so the settings page can show a status report and offer a one-click fix.
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
	 * @return array{ok:bool, error:?string, rules:array<string, array{key:string, expected_name:string, status:string, remote_id:?string, remote_order:?int, diff:?string}>, settings:array<string, array{field:string, label:string, description:string, status:string, diff:?string}>, settings_ok:bool, settings_error:?string}
	 */
	public function audit_zone( int $zone_id ): array {
		$settings_report = $this->audit_settings( $zone_id );

		$response = $this->client->list_configuration_rules( $zone_id );
		if ( ! $response['ok'] ) {
			return array(
				'ok'             => false,
				'error'          => $response['error'],
				'rules'          => array(),
				'settings'       => $settings_report['settings'],
				'settings_ok'    => $settings_report['ok'],
				'settings_error' => $settings_report['error'],
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
			'ok'             => true,
			'error'          => null,
			'rules'          => $report,
			'settings'       => $settings_report['settings'],
			'settings_ok'    => $settings_report['ok'],
			'settings_error' => $settings_report['error'],
		);
	}

	/**
	 * Reads the bound zone and reports each managed cache setting as OK or
	 * drifted. Zone-level settings are never auto-corrected — a zone the user
	 * picked rather than created may carry deliberate choices — so this only
	 * feeds the status table and the one-click fix.
	 *
	 * @return array{ok:bool, error:?string, settings:array<string, array{field:string, label:string, description:string, status:string, diff:?string}>}
	 */
	private function audit_settings( int $zone_id ): array {
		$response = $this->client->get_zone( $zone_id );
		if ( ! $response['ok'] ) {
			return array(
				'ok'       => false,
				'error'    => $response['error'],
				'settings' => array(),
			);
		}

		$remote = $response['body'];
		$report = array();
		foreach ( ZoneSettings::managed() as $field => $spec ) {
			$diff             = $this->find_setting_drift( $field, $spec, $remote );
			$report[ $field ] = array(
				'field'       => $field,
				'label'       => $spec['label'],
				'description' => $spec['description'],
				'status'      => null === $diff ? self::STATUS_OK : self::STATUS_DRIFTED,
				'diff'        => $diff,
			);
		}

		return array(
			'ok'       => true,
			'error'    => null,
			'settings' => $report,
		);
	}

	/**
	 * @param array{label:string, description:string, value:mixed, subset:bool} $spec
	 * @param array<int|string,mixed>                                           $remote
	 */
	private function find_setting_drift( string $field, array $spec, array $remote ): ?string {
		$actual = $remote[ $field ] ?? null;

		// List-valued settings are a floor, not an exact match: classes the
		// user enabled on top are theirs to keep.
		if ( $spec['subset'] ) {
			$have    = is_array( $actual ) ? array_map( 'strval', $actual ) : array();
			$missing = array_values( array_diff( (array) $spec['value'], $have ) );
			if ( empty( $missing ) ) {
				return null;
			}
			return sprintf(
				/* translators: %s: comma-separated list of content type classes */
				__( 'Not cached at the edge: %s', 'smoxy' ),
				implode( ', ', $missing )
			);
		}

		if ( $this->normalize_bool( $actual ) === $spec['value'] ) {
			return null;
		}

		return true === $spec['value']
			? __( 'Disabled on the zone.', 'smoxy' )
			: __( 'Enabled on the zone.', 'smoxy' );
	}

	/**
	 * @param array{name:string, key:string, description:string, expected_order:?int, payload:array<string,mixed>} $expected
	 * @param array<string,mixed>|null $remote
	 * @return array{key:string, expected_name:string, status:string, remote_id:?string, remote_order:?int, diff:?string}
	 */
	private function compare( string $key, array $expected, ?array $remote ): array {
		if ( null === $remote ) {
			return array(
				'key'           => $key,
				'expected_name' => $expected['name'],
				'status'        => self::STATUS_MISSING,
				'remote_id'     => null,
				'remote_order'  => null,
				'diff'          => null,
			);
		}

		$remote_id    = isset( $remote['id'] ) && is_string( $remote['id'] ) && '' !== $remote['id'] ? $remote['id'] : null;
		$remote_order = isset( $remote['order'] ) && is_numeric( $remote['order'] ) ? (int) $remote['order'] : null;

		$expected_payload = $expected['payload'];
		$expected_order   = $expected['expected_order'] ?? null;
		$drift            = $this->find_drift( $expected_payload, $remote, $expected_order, $remote_order );

		return array(
			'key'           => $key,
			'expected_name' => $expected['name'],
			'status'        => null === $drift ? self::STATUS_OK : self::STATUS_DRIFTED,
			'remote_id'     => $remote_id,
			'remote_order'  => $remote_order,
			'diff'          => $drift,
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
	private function find_drift( array $expected, array $remote, ?int $expected_order = null, ?int $remote_order = null ): ?string {
		if ( $this->normalize_bool( $remote['enabled'] ?? null ) !== ( $expected['enabled'] ?? null ) ) {
			return __( 'Rule is disabled on the zone.', 'smoxy' );
		}

		// Stop flag matters for the images rule, which depends on
		// stopOnMatch=true to short-circuit downstream rules. Comparing it for
		// every rule keeps the drift check uniform.
		if ( $this->normalize_bool( $remote['stopOnMatch'] ?? null ) !== (bool) ( $expected['stopOnMatch'] ?? false ) ) {
			return __( 'Stop flag differs from the plugin default.', 'smoxy' );
		}

		// Only check order drift for rules that declare an expected slot
		// (the images rule does; the bypass rules don't, since users may
		// reorder them around their own custom rules).
		if ( null !== $expected_order && null !== $remote_order && $expected_order !== $remote_order ) {
			return __( 'Position differs from the plugin default.', 'smoxy' );
		}

		$expected_conditions = $this->normalize_condition_group( $expected['conditions'] ?? null );
		$remote_conditions   = $this->normalize_condition_group( $remote['conditions'] ?? null );
		if ( $this->canonicalize( $expected_conditions ) !== $this->canonicalize( $remote_conditions ) ) {
			return __( 'Conditions differ from the plugin default.', 'smoxy' );
		}

		$expected_overrides = $this->canonicalize( $this->normalize_overrides( $expected['settingsOverrides'] ?? null ) );
		$remote_overrides   = $this->canonicalize( $this->normalize_overrides( $remote['settingsOverrides'] ?? null ) );
		if ( $expected_overrides !== $remote_overrides ) {
			return __( 'Settings overrides differ from the plugin default.', 'smoxy' );
		}

		return null;
	}

	/**
	 * Reduce a condition group to a comparable canonical form. The hub
	 * normalizes target-less and value-less conditions (e.g. `exists`) to
	 * omit those keys on read, while the plugin may send them as empty
	 * strings — both collapse to null here.
	 *
	 * @param mixed $value
	 * @return array<string,mixed>|null
	 */
	private function normalize_condition_group( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}
		if ( ! isset( $value['conditions'] ) || ! is_array( $value['conditions'] ) ) {
			return null;
		}
		$logic      = is_string( $value['logic'] ?? null ) ? strtolower( $value['logic'] ) : '';
		$conditions = array();
		foreach ( array_values( $value['conditions'] ) as $item ) {
			$conditions[] = $this->normalize_condition( $item );
		}
		return array(
			'logic'      => $logic,
			'conditions' => $conditions,
		);
	}

	/**
	 * Normalize a single leaf condition or nested group. Empty strings on
	 * `target` and `value` collapse to null so the canonical comparison
	 * matches what the hub returns from GET.
	 *
	 * @param mixed $condition
	 * @return array<string,mixed>
	 */
	private function normalize_condition( $condition ): array {
		if ( ! is_array( $condition ) ) {
			return array();
		}
		// Nested group (has its own conditions + logic keys).
		if ( isset( $condition['conditions'] ) && is_array( $condition['conditions'] ) ) {
			$nested = $this->normalize_condition_group( $condition );
			return null === $nested ? array() : $nested;
		}
		return array(
			'field'    => isset( $condition['field'] ) ? (string) $condition['field'] : '',
			'target'   => $this->blank_to_null( $condition['target'] ?? null ),
			'operator' => isset( $condition['operator'] ) ? (string) $condition['operator'] : '',
			'value'    => $this->blank_to_null( $condition['value'] ?? null ),
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
	 * The hub returns settingsOverrides with only non-null values, but the
	 * cache-key sub-objects always echo the read-only `uri: true` baseline —
	 * strip it (and any explicit nulls) so the comparison only covers the
	 * settings the plugin actually manages.
	 *
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	private function normalize_overrides( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $key => $setting ) {
			if ( null === $setting ) {
				continue;
			}
			if ( in_array( $key, array( 'cachingStaticCacheKey', 'cachingDynamicCacheKey' ), true ) && is_array( $setting ) ) {
				unset( $setting['uri'] );
			}
			$out[ (string) $key ] = $setting;
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
