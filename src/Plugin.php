<?php

namespace Smoxy\WP;

defined( 'ABSPATH' ) || exit;

class Plugin {


	public function boot(): void {
		( new Settings() )->register();
		( new CacheTags() )->register();
		( new EventBus() )->register();
		( new Attachments() )->register();
		( new WooCommerce() )->register();

		add_filter(
			'plugin_action_links_' . plugin_basename( SMOXY_PLUGIN_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * @param array<int|string, string> $links
	 * @return array<int|string, string>
	 */
	public function add_settings_link( array $links ): array {
		$url  = admin_url( 'admin.php?page=' . Settings::SETTINGS_SLUG );
		$link = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'smoxy' ) );
		array_unshift( $links, $link );
		return $links;
	}
}
