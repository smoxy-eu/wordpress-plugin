<?php
/**
 * Plugin Name:       smoxy
 * Plugin URI:        https://www.smoxy.eu
 * Description:       Connects WordPress to the smoxy cache service — purges and invalidates cached pages on the edge when content changes.
 * Version:           1.1.0
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

define( 'SMOXY_VERSION', '1.1.0' );
define( 'SMOXY_PLUGIN_FILE', __FILE__ );
define( 'SMOXY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMOXY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

$smoxy_composer_autoload = SMOXY_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $smoxy_composer_autoload ) ) {
	require_once $smoxy_composer_autoload;

	if ( class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
		$smoxy_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			'https://github.com/smoxy-eu/wordpress-plugin/',
			SMOXY_PLUGIN_FILE,
			'smoxy'
		);
		if ( $smoxy_update_checker instanceof \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\BaseChecker ) {
			$smoxy_vcs_api = $smoxy_update_checker->getVcsApi();
			if ( $smoxy_vcs_api instanceof \YahnisElsts\PluginUpdateChecker\v5p6\Vcs\GitHubApi ) {
				$smoxy_vcs_api->enableReleaseAssets( '/^smoxy-\d+\.\d+\.\d+\.zip$/' );
			}
			unset( $smoxy_vcs_api );
		}
		unset( $smoxy_update_checker );
	}
}
unset( $smoxy_composer_autoload );

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
