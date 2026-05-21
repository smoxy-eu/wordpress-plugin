<?php
/**
 * Plugin Name:       smoxy Proxy
 * Plugin URI:        https://www.smoxy.eu
 * Description:       Connects WordPress to the smoxy Proxy cache service — purges and invalidates cached pages on the edge when content changes.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Tested up to:      7.0
 * Requires PHP:      8.0
 * Author:            smoxy
 * Author URI:        https://www.smoxy.eu
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       smoxy
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SMOXY_VERSION', '1.0.1' );
define( 'SMOXY_PLUGIN_FILE', __FILE__ );
define( 'SMOXY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMOXY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'Smoxy\\WP\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
				return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = SMOXY_PLUGIN_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		( new \Smoxy\WP\Plugin() )->boot();
	}
);
