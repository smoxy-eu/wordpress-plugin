<?php

namespace Smoxy\WP\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * The three conditional rules the plugin keeps in sync on the bound smoxy
 * zone. Each rule turns off the full smoxy site configuration (`enabled = false`)
 * for requests that must never be served from cache: logged-in WordPress users,
 * WooCommerce/account paths, and the wp-admin area.
 *
 * The rule `name` is the stable lookup key the audit page uses to find the
 * rule on the zone — do not rename casually.
 */
class RuleDefinitions {


	public const KEY_LOGGED_IN   = 'logged_in';
	public const KEY_WOOCOMMERCE = 'woocommerce';
	public const KEY_WP_ADMIN    = 'wp_admin';

	/**
	 * @return array<string, array{name:string, key:string, description:string, payload:array<string,mixed>}>
	 */
	public static function all(): array {
		return array(
			self::KEY_LOGGED_IN   => self::logged_in(),
			self::KEY_WOOCOMMERCE => self::woocommerce(),
			self::KEY_WP_ADMIN    => self::wp_admin(),
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
	 * @return array{name:string, key:string, description:string, payload:array<string,mixed>}
	 */
	public static function logged_in(): array {
		$name = 'WordPress: bypass cache for logged-in users';
		return array(
			'name'        => $name,
			'key'         => self::KEY_LOGGED_IN,
			'description' => __( 'Disables smoxy whenever the WordPress logged-in cookie is present.', 'smoxy' ),
			'payload'     => array(
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
	 * @return array{name:string, key:string, description:string, payload:array<string,mixed>}
	 */
	public static function woocommerce(): array {
		$name = 'WordPress: bypass cache for WooCommerce and account paths';
		return array(
			'name'        => $name,
			'key'         => self::KEY_WOOCOMMERCE,
			'description' => __( 'Disables smoxy on cart, checkout, my-account, product pages and add-to-cart requests.', 'smoxy' ),
			'payload'     => array(
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
	 * @return array{name:string, key:string, description:string, payload:array<string,mixed>}
	 */
	public static function wp_admin(): array {
		$name = 'WordPress: bypass cache for wp-admin';
		return array(
			'name'        => $name,
			'key'         => self::KEY_WP_ADMIN,
			'description' => __( 'Disables smoxy on every request to the wp-admin backend.', 'smoxy' ),
			'payload'     => array(
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
