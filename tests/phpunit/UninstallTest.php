<?php

/**
 * Tests for the plugin's uninstall.php sweep.
 *
 * uninstall.php runs at file scope, so we exercise it in a separate PHP
 * process — that way the @runInSeparateProcess flag isolates the
 * WP_UNINSTALL_PLUGIN constant definition (and any side effects) from
 * the rest of the suite. The process inherits this PHPUnit bootstrap,
 * so WordPress + the test DB are available.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallTest extends WP_UnitTestCase {


	public function test_uninstall_removes_options_and_notice_transients(): void {
		// Seed state that uninstall.php must remove.
		update_option( 'smoxy_settings', array( 'secret_key' => 'will-be-deleted' ) );
		update_option(
			'smoxy_connection_status',
			array(
				'ok'         => true,
				'checked_at' => time(),
			)
		);

		// Two user-scoped notice transients, mimicking what handle_purge*()
		// writes after a form submit.
		set_transient(
			'smoxy_purge_notice_42',
			array(
				'ok'      => true,
				'message' => 'done',
			),
			60
		);
		set_transient(
			'smoxy_purge_notice_7',
			array(
				'ok'      => false,
				'message' => 'oops',
			),
			60
		);

		$this->assertNotEmpty( get_option( 'smoxy_settings' ) );
		$this->assertNotEmpty( get_option( 'smoxy_connection_status' ) );
		$this->assertNotFalse( get_transient( 'smoxy_purge_notice_42' ) );

		// uninstall.php guards on WP_UNINSTALL_PLUGIN before doing anything.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( 'smoxy_settings', false ) );
		$this->assertFalse( get_option( 'smoxy_connection_status', false ) );
		$this->assertFalse( get_transient( 'smoxy_purge_notice_42' ) );
		$this->assertFalse( get_transient( 'smoxy_purge_notice_7' ) );

		// Belt-and-braces: assert no orphan rows are left in wp_options for
		// either the transient value or its timeout sibling.
		global $wpdb;
		$leftover = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_smoxy\\_purge\\_notice\\_%' OR option_name LIKE '\\_transient\\_timeout\\_smoxy\\_purge\\_notice\\_%'"
		);
		$this->assertSame( 0, $leftover, 'No smoxy_purge_notice transient rows should remain after uninstall' );
	}
}
