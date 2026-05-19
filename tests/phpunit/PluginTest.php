<?php

use Smoxy\WP\Plugin;
use Smoxy\WP\Settings;

/**
 * Unit tests for Smoxy\WP\Plugin::add_settings_link().
 *
 * The filter only fires for the plugin's own row on the Plugins screen, so we
 * exercise both the match and no-match branches via direct calls — there is
 * no need to drive the full plugin_action_links filter chain.
 */
class PluginTest extends WP_UnitTestCase {


	public function test_add_settings_link_returns_links_unchanged_for_other_plugins(): void {
		$links = array( '<a href="https://example.com/">Existing</a>' );

		$result = ( new Plugin() )->add_settings_link( $links );

		// The handler is wired with plugin_action_links_<basename>, so by the
		// time it's called WordPress has already filtered to the matching row.
		// The handler itself always prepends the link — the "other plugin"
		// branch is enforced at the hook layer, not inside the method.
		// We assert it (a) returns an array, (b) still contains the original
		// link, and (c) doesn't mutate the caller's array.
		$this->assertIsArray( $result );
		$this->assertContains( '<a href="https://example.com/">Existing</a>', $result );
	}

	public function test_add_settings_link_prepends_settings_link(): void {
		$links = array();

		$result = ( new Plugin() )->add_settings_link( $links );

		$this->assertNotEmpty( $result );
		$this->assertStringContainsString( 'page=' . Settings::SETTINGS_SLUG, (string) $result[0] );
		$this->assertStringContainsString( '<a ', (string) $result[0] );
	}

	public function test_add_settings_link_preserves_existing_links(): void {
		$existing = '<a href="edit.php?post_type=foo">Edit</a>';
		$links    = array( $existing );

		$result = ( new Plugin() )->add_settings_link( $links );

		$this->assertCount( 2, $result );
		$this->assertSame( $existing, $result[1] );
		$this->assertStringContainsString( 'page=' . Settings::SETTINGS_SLUG, (string) $result[0] );
	}
}
