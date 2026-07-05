<?php

namespace Smoxy\WP\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * The configuration rules the plugin keeps in sync on the bound smoxy zone
 * (shown as "Conditional Rules" in the smoxy dashboard).
 *
 * Three rules turn off the full smoxy site configuration (`enabled = false`
 * settings override) for requests that must never be served from cache:
 * logged-in WordPress users, WooCommerce/account paths, and the wp-admin area.
 *
 * A fourth rule narrows the static cache key for images to URI only and stops
 * further rule evaluation so the cheap, high-cache-hit image path is not
 * re-keyed by subsequent rules.
 *
 * Rules are returned in the order they should appear on the zone — images
 * first at order #1 so the stop flag short-circuits the rest for image
 * URLs that don't need WP-aware bypass logic anyway.
 *
 * The rule `name` is the stable lookup key the audit page uses to find the
 * rule on the zone — do not rename casually.
 */
class RuleDefinitions {


	public const KEY_IMAGES      = 'images';
	public const KEY_LOGGED_IN   = 'logged_in';
	public const KEY_WOOCOMMERCE = 'woocommerce';
	public const KEY_WP_ADMIN    = 'wp_admin';

	/**
	 * @return array<string, array{name:string, key:string, description:string, expected_order:?int, payload:array<string,mixed>}>
	 */
	public static function all(): array {
		return array(
			self::KEY_IMAGES      => self::images(),
			self::KEY_LOGGED_IN   => self::logged_in(),
			self::KEY_WOOCOMMERCE => self::woocommerce(),
			self::KEY_WP_ADMIN    => self::wp_admin(),
		);
	}

	/**
	 * `order` is 1-based and honored on create; setting it on update
	 * re-sequences the sibling rules so the sequence stays contiguous.
	 * `expected_order` declares the desired slot for the audit; null means
	 * "we don't care". Rules without it omit `order` from the payload so
	 * the hub appends them after the current highest order.
	 *
	 * The cache-key override is `settingsOverrides.cachingStaticCacheKey` —
	 * `varyByHostname: false` reduces the static cache key to URI only
	 * (URI is always included by the edge and cannot be disabled).
	 *
	 * @return array{name:string, key:string, description:string, expected_order:?int, payload:array<string,mixed>}
	 */
	public static function images(): array {
		$name = 'WordPress: cache images on URI only';
		return array(
			'name'           => $name,
			'key'            => self::KEY_IMAGES,
			'description'    => __( 'For image responses, narrows the cache key to the URI (no host variance) and stops further rule evaluation so images are not re-keyed by downstream rules.', 'smoxy' ),
			'expected_order' => 1,
			'payload'        => array(
				'name'              => $name,
				'description'       => 'Managed by the smoxy WordPress plugin.',
				'stopOnMatch'       => true,
				'enabled'           => true,
				'order'             => 1,
				'conditions'        => array(
					'logic'      => 'and',
					'conditions' => array(
						array(
							'field'    => 'uri',
							'operator' => 'matches',
							'value'    => '/.+\.(png|jpeg|jpg|gif|webp|avif|svg)$',
						),
					),
				),
				'settingsOverrides' => array(
					'cachingStaticCacheKey' => array(
						'varyByHostname' => false,
					),
				),
			),
		);
	}

	/**
	 * The WP logged-in cookie name is `wordpress_logged_in_` + md5(siteurl);
	 * deterministic per site but varies across sites, which is exactly why the
	 * proxy can't include it in the cache key — we match its presence instead.
	 */
	public static function logged_in_cookie_name(): string {
		$siteurl = (string) get_site_option( 'siteurl' );
		return 'wordpress_logged_in_' . md5( $siteurl );
	}

	/**
	 * @return array{name:string, key:string, description:string, expected_order:?int, payload:array<string,mixed>}
	 */
	public static function logged_in(): array {
		$name = 'WordPress: bypass cache for logged-in users';
		return array(
			'name'           => $name,
			'key'            => self::KEY_LOGGED_IN,
			'description'    => __( 'Disables smoxy whenever the WordPress logged-in cookie is present.', 'smoxy' ),
			'expected_order' => null,
			'payload'        => array(
				'name'              => $name,
				'description'       => 'Managed by the smoxy WordPress plugin.',
				'stopOnMatch'       => false,
				'enabled'           => true,
				'conditions'        => array(
					'logic'      => 'or',
					'conditions' => array(
						array(
							'field'    => 'cookie',
							'target'   => self::logged_in_cookie_name(),
							'operator' => 'exists',
						),
					),
				),
				'settingsOverrides' => array(
					'enabled' => false,
				),
			),
		);
	}

	/**
	 * @return array{name:string, key:string, description:string, expected_order:?int, payload:array<string,mixed>}
	 */
	public static function woocommerce(): array {
		$name = 'WordPress: bypass cache for WooCommerce and account paths';
		return array(
			'name'           => $name,
			'key'            => self::KEY_WOOCOMMERCE,
			'description'    => __( 'Disables smoxy on cart, checkout, my-account, product pages and add-to-cart requests.', 'smoxy' ),
			'expected_order' => null,
			'payload'        => array(
				'name'              => $name,
				'description'       => 'Managed by the smoxy WordPress plugin.',
				'stopOnMatch'       => false,
				'enabled'           => true,
				'conditions'        => array(
					'logic'      => 'or',
					'conditions' => array(
						array(
							'field'    => 'uri',
							'operator' => 'matches',
							'value'    => '^/(cart|my-account/*|checkout|wc-api/*|addons|logout|lost-password|product/*)',
						),
						array(
							'field'    => 'queryParam',
							'target'   => 'add-to-cart',
							'operator' => 'exists',
						),
						array(
							'field'    => 'queryParam',
							'target'   => 'wc-api',
							'operator' => 'exists',
						),
					),
				),
				'settingsOverrides' => array(
					'enabled' => false,
				),
			),
		);
	}

	/**
	 * @return array{name:string, key:string, description:string, expected_order:?int, payload:array<string,mixed>}
	 */
	public static function wp_admin(): array {
		$name = 'WordPress: bypass cache for wp-admin';
		return array(
			'name'           => $name,
			'key'            => self::KEY_WP_ADMIN,
			'description'    => __( 'Disables smoxy on every request to the wp-admin backend.', 'smoxy' ),
			'expected_order' => null,
			'payload'        => array(
				'name'              => $name,
				'description'       => 'Managed by the smoxy WordPress plugin.',
				'stopOnMatch'       => false,
				'enabled'           => true,
				'conditions'        => array(
					'logic'      => 'and',
					'conditions' => array(
						array(
							'field'    => 'uri',
							'operator' => 'contains',
							'value'    => 'wp-admin',
						),
					),
				),
				'settingsOverrides' => array(
					'enabled' => false,
				),
			),
		);
	}
}
