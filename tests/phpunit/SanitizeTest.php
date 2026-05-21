<?php

use Smoxy\WP\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The Settings sanitize_callback is registered via register_setting() and so
 * runs on **every** update_option() — not just options.php form submits.
 * Admin-post handlers write structured fields (api_token, zone_id, etc.) via
 * Settings::update_options(), which in turn calls update_option(); if sanitize
 * dropped any of those keys it would silently wipe API-driven state.
 *
 * These tests pin that pass-through contract.
 */
class SanitizeTest extends TestCase {


	public function test_sanitize_trims_whitespace_from_secret_key(): void {
		$settings = new Settings();
		$output   = $settings->sanitize( array( 'secret_key' => "  abc123\n" ) );

		$this->assertSame( 'abc123', $output['secret_key'] );
	}

	public function test_sanitize_passes_through_api_driven_keys(): void {
		$settings = new Settings();
		$output   = $settings->sanitize(
			array(
				'api_token'       => 'tok-123',
				'organization_id' => 5,
				'zone_id'         => 2606,
				'zone_secret'     => 'banSecret',
			)
		);

		$this->assertSame( 'tok-123', $output['api_token'] );
		$this->assertSame( 5, $output['organization_id'] );
		$this->assertSame( 2606, $output['zone_id'] );
		$this->assertSame( 'banSecret', $output['zone_secret'] );
	}

	public function test_sanitize_returns_empty_when_input_has_no_known_keys(): void {
		$settings = new Settings();
		$output   = $settings->sanitize( array() );

		$this->assertSame( array(), $output );
	}

	public function test_sanitize_non_array_input_falls_back_to_current_options(): void {
		$settings = new Settings();
		$output   = $settings->sanitize( 'not-an-array' );

		// The fallback reads the option from the DB — which is empty in this
		// unit test, so the array is empty, but the contract is the type.
		$this->assertIsArray( $output );
	}
}
