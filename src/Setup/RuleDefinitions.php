<?php

namespace Smoxy\WP\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * The conditional rules the plugin keeps in sync on the bound smoxy zone.
 *
 * Three rules turn off the full smoxy site configuration (`enabled = false`)
 * for requests that must never be served from cache: logged-in WordPress users,
 * WooCommerce/account paths, and the wp-admin area.
 *
 * A fourth rule narrows the cache key for static images to URI only and stops
 * further conditional-rule evaluation so the cheap, high-cache-hit image path
 * is not re-keyed by subsequent rules.
 *
 * Rules are returned in the order they should appear on the zone — images
 * first at position #1 so the stop flag short-circuits the rest for image
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
	 * @return array<string, array{name:string, key:string, description:string, expected_position:?int, payload:array<string,mixed>}>
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
	 * The hub's v2 API ignores `position` on POST and assigns the next slot;
	 * the bootstrap reconciles the rule's position afterwards via a dedicated
	 * PATCH (see Client::patch_conditional_rule_position). `expected_position`
	 * declares the desired slot; null means "we don't care".
	 *
	 * The cache-key action is the v2 `vary_cache` object — setting
	 * host_vary_enabled=false and cookie_vary_params=[] reduces the cache key
	 * to URI only (URI is always included by the edge).
	 *
	 * @return array{name:string, key:string, description:string, expected_position:?int, payload:array<string,mixed>}
	 */
	public static function images(): array {
		$name = 'WordPress: cache images on URI only';
		return array(
			'name'              => $name,
			'key'               => self::KEY_IMAGES,
			'description'       => __( 'For image responses, narrows the cache key to the URI (no host or cookie variance) and stops further conditional-rule evaluation so images are not re-keyed by downstream rules.', 'smoxy' ),
			'expected_position' => 1,
			'payload'           => array(
				'name'        => $name,
				'description' => 'Managed by the smoxy WordPress plugin.',
				'stop'        => true,
				'enabled'     => true,
				'expressions' => array(
					'condition' => 'and',
					'rules'     => array(
						array(
							'field'    => 'uri',
							'target'   => '',
							'operator' => 'matches',
							'value'    => '/.+\.(png|jpeg|jpg|gif|webp|avif|svg)$',
						),
					),
				),
				'rules'       => array(
					array(
						'name'  => 'vary_cache',
						'value' => array(
							'host_vary_enabled'  => false,
							'cookie_vary_params' => array(),
						),
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
	 * @return array{name:string, key:string, description:string, expected_position:?int, payload:array<string,mixed>}
	 */
	public static function logged_in(): array {
		$name = 'WordPress: bypass cache for logged-in users';
		return array(
			'name'              => $name,
			'key'               => self::KEY_LOGGED_IN,
			'description'       => __( 'Disables smoxy whenever the WordPress logged-in cookie is present.', 'smoxy' ),
			'expected_position' => null,
			'payload'           => array(
				'name'        => $name,
				'description' => 'Managed by the smoxy WordPress plugin.',
				'stop'        => false,
				'enabled'     => true,
				'expressions' => array(
					'condition' => 'or',
					'rules'     => array(
						array(
							'field'    => 'cookies',
							'target'   => self::logged_in_cookie_name(),
							'operator' => 'exists',
							'value'    => '',
						),
					),
				),
				'rules'       => array(
					array(
						'name'  => 'enabled',
						'value' => false,
					),
				),
			),
		);
	}

	/**
	 * @return array{name:string, key:string, description:string, expected_position:?int, payload:array<string,mixed>}
	 */
	public static function woocommerce(): array {
		$name = 'WordPress: bypass cache for WooCommerce and account paths';
		return array(
			'name'              => $name,
			'key'               => self::KEY_WOOCOMMERCE,
			'description'       => __( 'Disables smoxy on cart, checkout, my-account, product pages and add-to-cart requests.', 'smoxy' ),
			'expected_position' => null,
			'payload'           => array(
				'name'        => $name,
				'description' => 'Managed by the smoxy WordPress plugin.',
				'stop'        => false,
				'enabled'     => true,
				'expressions' => array(
					'condition' => 'or',
					'rules'     => array(
						array(
							'field'    => 'uri',
							'target'   => '',
							'operator' => 'matches',
							'value'    => '^/(cart|my-account/*|checkout|wc-api/*|addons|logout|lost-password|product/*)',
						),
						array(
							'field'    => 'args',
							'target'   => 'add-to-cart',
							'operator' => 'exists',
							'value'    => '',
						),
						array(
							'field'    => 'args',
							'target'   => 'wc-api',
							'operator' => 'exists',
							'value'    => '',
						),
					),
				),
				'rules'       => array(
					array(
						'name'  => 'enabled',
						'value' => false,
					),
				),
			),
		);
	}

	/**
	 * @return array{name:string, key:string, description:string, expected_position:?int, payload:array<string,mixed>}
	 */
	public static function wp_admin(): array {
		$name = 'WordPress: bypass cache for wp-admin';
		return array(
			'name'              => $name,
			'key'               => self::KEY_WP_ADMIN,
			'description'       => __( 'Disables smoxy on every request to the wp-admin backend.', 'smoxy' ),
			'expected_position' => null,
			'payload'           => array(
				'name'        => $name,
				'description' => 'Managed by the smoxy WordPress plugin.',
				'stop'        => false,
				'enabled'     => true,
				'expressions' => array(
					'condition' => 'and',
					'rules'     => array(
						array(
							'field'    => 'uri',
							'target'   => '',
							'operator' => 'contains',
							'value'    => 'wp-admin',
						),
					),
				),
				'rules'       => array(
					array(
						'name'  => 'enabled',
						'value' => false,
					),
				),
			),
		);
	}
}
