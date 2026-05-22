<?php

namespace Smoxy\WP\Setup;

use Smoxy\WP\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * One-shot orchestrator for binding a WordPress site to a smoxy zone:
 * optionally creates an origin, creates the zone if missing, attaches
 * the current WP hostname, reads the zone's BAN secret token, and
 * creates the three conditional rules from RuleDefinitions.
 *
 * Each step records what it did so callers can build a single notice
 * summarizing the result. Failures abort the remaining steps and the
 * partially-applied state stays on the smoxy side (the user can re-run
 * setup; existing resources are detected and reused).
 */
class Bootstrap {


	private Client $client;

	/** @var list<string> */
	private array $steps = array();

	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * @param array{
	 *   organization_id:int,
	 *   zone_id:?int,
	 *   new_zone_name:?string,
	 *   new_zone_tag:?string,
	 *   origin_id:?int,
	 *   new_origin:?array{name:string, protocol:string, address:string, port:int, requestHostname:?string}
	 * } $input
	 *
	 * @return array{ok:bool, message:string, steps:list<string>, zone_id:?int, secret_token:?string}
	 */
	public function run( array $input ): array {
		$organization_id = $input['organization_id'];
		$zone_id         = $input['zone_id'];

		if ( null === $zone_id ) {
			$origin_id = $input['origin_id'];
			if ( null === $origin_id && is_array( $input['new_origin'] ?? null ) ) {
				$origin_result = $this->client->create_origin( $organization_id, $input['new_origin'] );
				if ( ! $origin_result['ok'] ) {
					return $this->fail( __( 'Could not create the origin.', 'smoxy' ) . ' ' . ( $origin_result['error'] ?? '' ) );
				}
				$origin_id = $this->extract_id( $origin_result['body'] );
				if ( null === $origin_id ) {
					return $this->fail( __( 'smoxy hub returned a malformed origin response.', 'smoxy' ) );
				}
				$this->log(
					sprintf(
					/* translators: %d: smoxy origin id */
						__( 'Created new origin (#%d).', 'smoxy' ),
						$origin_id
					)
				);
			}

			if ( null === $origin_id ) {
				return $this->fail( __( 'Pick an existing origin or fill in the new-origin fields.', 'smoxy' ) );
			}

			$zone_payload = array(
				'organization'   => $organization_id,
				'origin'         => $origin_id,
				'name'           => $input['new_zone_name'] ?? '',
				'tag'            => $input['new_zone_tag'] ?? 'Prod',
				'configurations' => array(
					'enabled'              => true,
					'acceleration_enabled' => true,
					'security_enabled'     => true,
				),
			);

			$zone_result = $this->client->create_zone( $zone_payload );
			if ( ! $zone_result['ok'] ) {
				return $this->fail( __( 'Could not create the zone.', 'smoxy' ) . ' ' . ( $zone_result['error'] ?? '' ) );
			}
			$zone_id = $this->extract_id( $zone_result['body'] );
			if ( null === $zone_id ) {
				return $this->fail( __( 'smoxy hub returned a malformed zone response.', 'smoxy' ) );
			}
			$this->log(
				sprintf(
				/* translators: %d: smoxy zone id */
					__( 'Created new zone (#%d).', 'smoxy' ),
					$zone_id
				)
			);
		} else {
			$this->log(
				sprintf(
				/* translators: %d: smoxy zone id */
					__( 'Using existing zone (#%d).', 'smoxy' ),
					$zone_id
				)
			);
		}

		$zone_detail = $this->client->get_zone( $zone_id );
		if ( ! $zone_detail['ok'] ) {
			return $this->fail( __( 'Could not read the zone configuration.', 'smoxy' ) . ' ' . ( $zone_detail['error'] ?? '' ) );
		}

		$secret_token = $this->extract_secret_token( $zone_detail['body'] );
		if ( null === $secret_token || '' === $secret_token ) {
			return $this->fail( __( 'The zone has no purge secret configured on smoxy — open the zone in the hub and save its Basic configuration once.', 'smoxy' ) );
		}

		$hostname_step = $this->ensure_hostname( $organization_id, $zone_id );
		if ( ! $hostname_step['ok'] ) {
			return $this->fail( $hostname_step['message'] );
		}

		$rules_step = $this->ensure_rules( $zone_id );
		if ( ! $rules_step['ok'] ) {
			return $this->fail( $rules_step['message'] );
		}

		return array(
			'ok'                => true,
			'message'           => __( 'smoxy is connected and the WordPress bypass rules are in place.', 'smoxy' ),
			'steps'             => $this->steps,
			'zone_id'           => $zone_id,
			'secret_token'      => $secret_token,
			'hostname_conflict' => $hostname_step['conflict'] ?? null,
		);
	}

	/**
	 * @return array{ok:bool, message:string, conflict:?array{hostname_id:int, hostname:string, existing_zone_id:?int}}
	 */
	private function ensure_hostname( int $organization_id, int $zone_id ): array {
		$site_host = $this->wp_hostname();
		if ( '' === $site_host ) {
			return array(
				'ok'       => false,
				'message'  => __( 'Could not determine this site\'s hostname.', 'smoxy' ),
				'conflict' => null,
			);
		}

		$lookup = $this->client->list_hostnames( $organization_id, $site_host );
		if ( ! $lookup['ok'] ) {
			return array(
				'ok'       => false,
				'message'  => __( 'Could not list hostnames.', 'smoxy' ) . ' ' . ( $lookup['error'] ?? '' ),
				'conflict' => null,
			);
		}

		$existing = null;
		foreach ( $this->extract_members( $lookup['body'] ) as $item ) {
			if ( is_array( $item ) && isset( $item['hostname'] ) && $item['hostname'] === $site_host ) {
				$existing = $item;
				break;
			}
		}

		if ( null !== $existing ) {
			$existing_zone_id = $this->extract_hostname_zone_id( $existing );
			if ( $existing_zone_id === $zone_id ) {
				$this->log(
					sprintf(
					/* translators: %s: hostname */
						__( 'Hostname %s is already attached to this zone.', 'smoxy' ),
						$site_host
					)
				);
				return array(
					'ok'       => true,
					'message'  => '',
					'conflict' => null,
				);
			}

			// Same hostname, different zone — defer to user. Don't fail the
			// bootstrap; the zone is set up correctly and the user can move
			// the hostname from the connected view.
			$this->log(
				sprintf(
				/* translators: 1: hostname, 2: existing zone id */
					__( 'Hostname %1$s is currently attached to zone #%2$s — waiting for user to confirm moving it.', 'smoxy' ),
					$site_host,
					null === $existing_zone_id ? '?' : (string) $existing_zone_id
				)
			);
			return array(
				'ok'       => true,
				'message'  => '',
				'conflict' => array(
					'hostname_id'      => isset( $existing['id'] ) ? (int) $existing['id'] : 0,
					'hostname'         => $site_host,
					'existing_zone_id' => $existing_zone_id,
				),
			);
		}

		$create = $this->client->create_hostname(
			array(
				'hostname' => $site_host,
				'zone'     => $zone_id,
			)
		);
		if ( ! $create['ok'] ) {
			return array(
				'ok'       => false,
				'message'  => __( 'Could not register this site\'s hostname on smoxy.', 'smoxy' ) . ' ' . ( $create['error'] ?? '' ),
				'conflict' => null,
			);
		}
		$this->log(
			sprintf(
			/* translators: %s: hostname */
				__( 'Registered hostname %s on smoxy.', 'smoxy' ),
				$site_host
			)
		);

		return array(
			'ok'       => true,
			'message'  => '',
			'conflict' => null,
		);
	}

	/**
	 * The hub returns hostname.zone as an object `{id: N}` on read endpoints
	 * (per the live API). Read defensively in case the shape changes.
	 *
	 * @param array<int|string,mixed> $hostname
	 */
	private function extract_hostname_zone_id( array $hostname ): ?int {
		if ( isset( $hostname['zone']['id'] ) && is_numeric( $hostname['zone']['id'] ) ) {
			return (int) $hostname['zone']['id'];
		}
		if ( isset( $hostname['zone'] ) && is_numeric( $hostname['zone'] ) ) {
			return (int) $hostname['zone'];
		}
		return null;
	}

	/**
	 * @return array{ok:bool, message:string}
	 */
	private function ensure_rules( int $zone_id ): array {
		$audit  = new Audit( $this->client );
		$report = $audit->audit_zone( $zone_id );
		if ( ! $report['ok'] ) {
			return array(
				'ok'      => false,
				'message' => __( 'Could not list existing conditional rules.', 'smoxy' ) . ' ' . ( $report['error'] ?? '' ),
			);
		}

		foreach ( RuleDefinitions::all() as $key => $expected ) {
			$status          = $report['rules'][ $key ]['status'] ?? Audit::STATUS_MISSING;
			$remote_id       = $report['rules'][ $key ]['remote_id'] ?? null;
			$remote_position = $report['rules'][ $key ]['remote_position'] ?? null;

			if ( Audit::STATUS_OK === $status ) {
				$this->log(
					sprintf(
					/* translators: %s: rule name */
						__( 'Conditional rule "%s" already in place.', 'smoxy' ),
						$expected['name']
					)
				);
			} elseif ( Audit::STATUS_DRIFTED === $status ) {
				if ( null === $remote_id ) {
					$this->log(
						sprintf(
						/* translators: %s: conditional rule name */
							__( 'Conditional rule "%s" has drifted but no id was returned — skipping.', 'smoxy' ),
							$expected['name']
						)
					);
					continue;
				}
				$patch = $this->client->patch_conditional_rule( $zone_id, $remote_id, $expected['payload'] );
				if ( ! $patch['ok'] ) {
					return array(
						'ok'      => false,
						'message' => sprintf(
							/* translators: 1: rule name, 2: error message */
							__( 'Could not update conditional rule "%1$s": %2$s', 'smoxy' ),
							$expected['name'],
							$patch['error'] ?? ''
						),
					);
				}
				$this->log(
					sprintf(
					/* translators: %s: rule name */
						__( 'Updated conditional rule "%s" to match the plugin default.', 'smoxy' ),
						$expected['name']
					)
				);
			} else {
				$create = $this->client->create_conditional_rule( $zone_id, $expected['payload'] );
				if ( ! $create['ok'] ) {
					return array(
						'ok'      => false,
						'message' => sprintf(
							/* translators: 1: rule name, 2: error message */
							__( 'Could not create conditional rule "%1$s": %2$s', 'smoxy' ),
							$expected['name'],
							$create['error'] ?? ''
						),
					);
				}
				$this->log(
					sprintf(
					/* translators: %s: rule name */
						__( 'Created conditional rule "%s".', 'smoxy' ),
						$expected['name']
					)
				);
				$remote_id       = $this->extract_id( $create['body'] );
				$remote_position = isset( $create['body']['position'] ) && is_numeric( $create['body']['position'] )
					? (int) $create['body']['position']
					: null;
			}

			$reconcile = $this->reconcile_position( $zone_id, $expected, $remote_id, $remote_position );
			if ( ! $reconcile['ok'] ) {
				return $reconcile;
			}
		}

		return array(
			'ok'      => true,
			'message' => '',
		);
	}

	/**
	 * Move a rule to its declared position. No-op when the rule has no
	 * expected_position, the position is already correct, or we don't know
	 * the rule's current id (a drifted rule with no id was already logged
	 * and skipped upstream).
	 *
	 * @param array{name:string, key:string, description:string, expected_position:?int, payload:array<string,mixed>} $expected
	 * @return array{ok:bool, message:string}
	 */
	private function reconcile_position( int $zone_id, array $expected, ?int $remote_id, ?int $remote_position ): array {
		$expected_position = $expected['expected_position'] ?? null;
		if ( null === $expected_position || null === $remote_id ) {
			return array(
				'ok'      => true,
				'message' => '',
			);
		}
		if ( null !== $remote_position && $expected_position === $remote_position ) {
			return array(
				'ok'      => true,
				'message' => '',
			);
		}

		$move = $this->client->patch_conditional_rule_position( $zone_id, $remote_id, $expected_position );
		if ( ! $move['ok'] ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: 1: rule name, 2: expected position, 3: error message */
					__( 'Could not move conditional rule "%1$s" to position %2$d: %3$s', 'smoxy' ),
					$expected['name'],
					$expected_position,
					$move['error'] ?? ''
				),
			);
		}
		$this->log(
			sprintf(
				/* translators: 1: rule name, 2: position number */
				__( 'Moved conditional rule "%1$s" to position %2$d.', 'smoxy' ),
				$expected['name'],
				$expected_position
			)
		);

		return array(
			'ok'      => true,
			'message' => '',
		);
	}

	/**
	 * @param array<int|string,mixed> $body
	 */
	private function extract_id( array $body ): ?int {
		if ( isset( $body['id'] ) && is_numeric( $body['id'] ) ) {
			return (int) $body['id'];
		}
		return null;
	}

	/**
	 * @param array<int|string,mixed> $body
	 */
	private function extract_secret_token( array $body ): ?string {
		if ( isset( $body['configurations']['token'] ) && is_string( $body['configurations']['token'] ) ) {
			return $body['configurations']['token'];
		}
		return null;
	}

	/**
	 * @param array<int|string,mixed> $body
	 * @return list<mixed>
	 */
	private function extract_members( array $body ): array {
		foreach ( array( 'member', 'hydra:member' ) as $key ) {
			if ( isset( $body[ $key ] ) && is_array( $body[ $key ] ) ) {
				return array_values( $body[ $key ] );
			}
		}
		if ( ! empty( $body ) && array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
			return array_values( $body );
		}
		return array();
	}

	private function wp_hostname(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * @return array{ok:false, message:string, steps:list<string>, zone_id:null, secret_token:null}
	 */
	private function fail( string $message ): array {
		return array(
			'ok'           => false,
			'message'      => $message,
			'steps'        => $this->steps,
			'zone_id'      => null,
			'secret_token' => null,
		);
	}

	private function log( string $message ): void {
		$this->steps[] = $message;
	}
}
