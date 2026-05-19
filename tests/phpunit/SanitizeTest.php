<?php

use Smoxy\WP\Settings;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class SanitizeTest extends TestCase {


	public function test_sanitize_trims_whitespace_from_secret_key(): void {
		$settings = new Settings();
		$output   = $settings->sanitize( array( 'secret_key' => "  abc123\n" ) );

		$this->assertSame( 'abc123', $output['secret_key'] );
	}

	public function test_sanitize_defaults_missing_secret_key_to_empty_string(): void {
		$settings = new Settings();
		$output   = $settings->sanitize( array() );

		$this->assertSame( '', $output['secret_key'] );
	}
}
