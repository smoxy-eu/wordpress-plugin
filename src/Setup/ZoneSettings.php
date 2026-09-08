<?php

namespace Smoxy\WP\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * The zone-level cache settings the plugin keeps in sync, alongside the
 * conditional rules in RuleDefinitions.
 *
 * These are applied when the plugin creates a zone during setup. A zone the
 * user picked instead of creating is never touched silently — Audit reports
 * any difference as drift and the settings page offers a one-click fix, the
 * same flow the conditional rules use.
 *
 * `cachingAdditionalContentTypes` is compared as a subset rather than for
 * equality: extra classes the user enabled themselves (`json`, say) are not
 * drift, and the fix adds what is missing without removing them.
 */
class ZoneSettings {


	public const FIELD_CONTENT_TYPES  = 'cachingAdditionalContentTypes';
	public const FIELD_MANAGED_PARAMS = 'cachingManagedIgnoredUrlParamsEnabled';
	public const FIELD_STRIP_TAGS     = 'stripCacheTagHeaders';

	/**
	 * Response classes worth caching on a WordPress site. css/js/font are
	 * immutable, content-hashed and identical for every visitor; xml_text is
	 * what makes RSS/Atom cacheable, without which the `feed` cache tag this
	 * plugin emits has nothing to purge. `json` is deliberately absent — WP
	 * REST responses are frequently per-user.
	 *
	 * @return list<string>
	 */
	public static function content_types(): array {
		return array( 'css', 'js', 'font', 'xml_text' );
	}

	/**
	 * @return array<string, array{label:string, description:string, value:mixed, subset:bool}>
	 */
	public static function managed(): array {
		return array(
			self::FIELD_CONTENT_TYPES  => array(
				'label'       => __( 'Cacheable content types', 'smoxy' ),
				'description' => __( 'Caches stylesheets, JavaScript and web fonts at the edge, plus XML so RSS/Atom feeds can be cached and purged by the feed tag.', 'smoxy' ),
				'value'       => self::content_types(),
				'subset'      => true,
			),
			self::FIELD_MANAGED_PARAMS => array(
				'label'       => __( 'Managed ignored URL parameters', 'smoxy' ),
				'description' => __( 'Strips advertising and campaign parameters (utm_*, gclid, fbclid, ...) from the cache key, so the same page shares one cache entry across campaigns.', 'smoxy' ),
				'value'       => true,
				'subset'      => false,
			),
			self::FIELD_STRIP_TAGS     => array(
				'label'       => __( 'Strip cache-tag headers', 'smoxy' ),
				'description' => __( 'Removes the X-Cache-Tags header this plugin sets from responses before they reach visitors. smoxy still reads it first.', 'smoxy' ),
				'value'       => true,
				'subset'      => false,
			),
		);
	}

	/**
	 * The managed settings as an API payload, for zone creation and for the
	 * one-click repair.
	 *
	 * When repairing, pass the zone as returned by the hub so the content-type
	 * list is merged with whatever the zone already has instead of replacing
	 * it. Omit it (zone creation) to send the plugin defaults verbatim.
	 *
	 * @param array<int|string,mixed>|null $remote
	 * @return array<string,mixed>
	 */
	public static function payload( ?array $remote = null ): array {
		$payload = array();
		foreach ( self::managed() as $field => $spec ) {
			$payload[ $field ] = $spec['value'];
		}

		if ( null !== $remote ) {
			$payload[ self::FIELD_CONTENT_TYPES ] = self::merge_content_types(
				$remote[ self::FIELD_CONTENT_TYPES ] ?? null
			);
		}

		return $payload;
	}

	/**
	 * Union of the plugin's classes and whatever the zone already caches,
	 * so a repair never switches off a class the user turned on.
	 *
	 * @param mixed $remote_value
	 * @return list<string>
	 */
	public static function merge_content_types( $remote_value ): array {
		$existing = array();
		if ( is_array( $remote_value ) ) {
			foreach ( $remote_value as $item ) {
				if ( is_string( $item ) && '' !== $item ) {
					$existing[] = $item;
				}
			}
		}

		$merged = array_merge( $existing, self::content_types() );
		sort( $merged );
		return array_values( array_unique( $merged ) );
	}
}
