<?php

use Smoxy\WP\Api\Client;
use Smoxy\WP\Settings;
use Smoxy\WP\Setup\Audit;
use Smoxy\WP\Setup\ZoneSettings;

/**
 * Covers the managed zone-level cache settings: the definition itself, the
 * drift detection Audit layers on top of it, and the one-click repair on the
 * settings page.
 *
 * Hub API calls are stubbed through pre_http_request so no test touches the
 * network; each test declares the zone body the hub would return.
 */
class ZoneSettingsTest extends WP_UnitTestCase {


	/** @var array<int,array{url:string,method:string,body:mixed}> */
	private array $api_calls = array();

	/** @var array<string,mixed> */
	private array $zone_body = array();

	private int $admin_id = 0;

	public function set_up(): void {
		parent::set_up();

		$this->api_calls = array();
		$this->zone_body = array();
		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		add_filter( 'pre_http_request', array( $this, 'stub_hub_api' ), 10, 3 );

		add_filter(
			'wp_redirect',
			static function ( $location ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Read back in tests, never rendered.
				throw new SmoxyRedirectException( (string) $location );
			},
			1
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_redirect' );
		remove_filter( 'pre_http_request', array( $this, 'stub_hub_api' ), 10 );
		delete_option( Settings::OPTION_NAME );
		$_POST    = array();
		$_REQUEST = array();

		parent::tear_down();
	}

	/**
	 * @param false|array|\WP_Error $preempt
	 * @param array<string,mixed>   $args
	 * @return false|array<string,mixed>
	 */
	public function stub_hub_api( $preempt, $args, string $url ) {
		if ( false === strpos( $url, 'api.smoxy.eu' ) ) {
			return $preempt;
		}

		$this->api_calls[] = array(
			'url'    => $url,
			'method' => (string) ( $args['method'] ?? '' ),
			'body'   => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
		);

		// The rules collection is irrelevant here; every test drives the
		// settings half of the report.
		$body = false !== strpos( $url, '/configuration-rules' ) ? array() : $this->zone_body;

		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/* ------------------------------------------------------------------
	 * The definition
	 * ------------------------------------------------------------------ */

	public function test_payload_carries_every_managed_setting(): void {
		$payload = ZoneSettings::payload();

		$this->assertSame(
			array( 'css', 'js', 'font', 'xml_text' ),
			$payload[ ZoneSettings::FIELD_CONTENT_TYPES ]
		);
		$this->assertTrue( $payload[ ZoneSettings::FIELD_MANAGED_PARAMS ] );
		$this->assertTrue( $payload[ ZoneSettings::FIELD_STRIP_TAGS ] );
	}

	public function test_xml_text_is_enabled_so_the_feed_cache_tag_has_something_to_purge(): void {
		$this->assertContains( 'xml_text', ZoneSettings::content_types() );
	}

	public function test_json_is_not_cached_because_rest_responses_are_often_per_user(): void {
		$this->assertNotContains( 'json', ZoneSettings::content_types() );
	}

	public function test_merge_content_types_keeps_classes_the_user_enabled(): void {
		$merged = ZoneSettings::merge_content_types( array( 'json', 'css' ) );

		$this->assertContains( 'json', $merged, 'A class the user turned on must survive the merge' );
		foreach ( ZoneSettings::content_types() as $expected ) {
			$this->assertContains( $expected, $merged );
		}
	}

	public function test_merge_content_types_dedupes_and_tolerates_a_missing_field(): void {
		// The merge sorts so the payload is deterministic; compare as a set.
		$expected = ZoneSettings::content_types();
		sort( $expected );
		$this->assertSame( $expected, ZoneSettings::merge_content_types( null ) );
		$this->assertSame(
			ZoneSettings::merge_content_types( array( 'css', 'css' ) ),
			ZoneSettings::merge_content_types( array( 'css' ) )
		);
	}

	/* ------------------------------------------------------------------
	 * Drift detection
	 * ------------------------------------------------------------------ */

	public function test_audit_reports_ok_when_the_zone_matches(): void {
		$this->zone_body = array(
			'id'                                    => 2,
			'cachingAdditionalContentTypes'         => array( 'css', 'js', 'font', 'xml_text' ),
			'cachingManagedIgnoredUrlParamsEnabled' => true,
			'stripCacheTagHeaders'                  => true,
		);

		$report = ( new Audit( new Client( 'tok' ) ) )->audit_zone( 2 );

		$this->assertTrue( $report['settings_ok'] );
		foreach ( $report['settings'] as $setting ) {
			$this->assertSame( Audit::STATUS_OK, $setting['status'], $setting['field'] . ' should be OK' );
		}
	}

	public function test_audit_reports_drift_for_a_zone_the_plugin_did_not_create(): void {
		// A stock zone: no extra content classes, managed params off, headers
		// passed through.
		$this->zone_body = array(
			'id'                                    => 2,
			'cachingAdditionalContentTypes'         => array(),
			'cachingManagedIgnoredUrlParamsEnabled' => false,
			'stripCacheTagHeaders'                  => false,
		);

		$report = ( new Audit( new Client( 'tok' ) ) )->audit_zone( 2 );

		$this->assertTrue( $report['settings_ok'] );
		foreach ( $report['settings'] as $setting ) {
			$this->assertSame( Audit::STATUS_DRIFTED, $setting['status'], $setting['field'] . ' should be drifted' );
			$this->assertNotEmpty( $setting['diff'], 'Drift must carry a human-readable reason' );
		}
	}

	public function test_extra_content_classes_are_not_drift(): void {
		$this->zone_body = array(
			'id'                                    => 2,
			// Everything the plugin wants, plus one the user enabled.
			'cachingAdditionalContentTypes'         => array( 'css', 'js', 'font', 'xml_text', 'json' ),
			'cachingManagedIgnoredUrlParamsEnabled' => true,
			'stripCacheTagHeaders'                  => true,
		);

		$report = ( new Audit( new Client( 'tok' ) ) )->audit_zone( 2 );

		$this->assertSame(
			Audit::STATUS_OK,
			$report['settings'][ ZoneSettings::FIELD_CONTENT_TYPES ]['status'],
			'A superset of the plugin defaults is not drift'
		);
	}

	public function test_missing_content_classes_are_named_in_the_diff(): void {
		$this->zone_body = array(
			'id'                                    => 2,
			'cachingAdditionalContentTypes'         => array( 'css', 'js' ),
			'cachingManagedIgnoredUrlParamsEnabled' => true,
			'stripCacheTagHeaders'                  => true,
		);

		$report = ( new Audit( new Client( 'tok' ) ) )->audit_zone( 2 );
		$diff   = (string) $report['settings'][ ZoneSettings::FIELD_CONTENT_TYPES ]['diff'];

		$this->assertStringContainsString( 'font', $diff );
		$this->assertStringContainsString( 'xml_text', $diff );
		$this->assertStringNotContainsString( 'css', $diff, 'Classes already enabled must not be listed as missing' );
	}

	/* ------------------------------------------------------------------
	 * One-click repair
	 * ------------------------------------------------------------------ */

	public function test_apply_patches_the_zone_and_preserves_user_enabled_classes(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'api_token' => 'tok',
				'zone_id'   => 2,
			)
		);
		$this->zone_body = array(
			'id'                                    => 2,
			'cachingAdditionalContentTypes'         => array( 'json' ),
			'cachingManagedIgnoredUrlParamsEnabled' => false,
			'stripCacheTagHeaders'                  => false,
		);

		wp_set_current_user( $this->admin_id );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Settings::SYNC_SETTINGS_ACTION );

		try {
			( new Settings() )->handle_sync_zone_settings();
		} catch ( SmoxyRedirectException $e ) {
			unset( $e );
		}

		$patches = array_values(
			array_filter(
				$this->api_calls,
				static fn( array $call ): bool => 'PATCH' === $call['method']
			)
		);

		$this->assertCount( 1, $patches, 'Applying should issue exactly one zone PATCH' );
		$sent = $patches[0]['body'];

		$this->assertTrue( $sent[ ZoneSettings::FIELD_MANAGED_PARAMS ] );
		$this->assertTrue( $sent[ ZoneSettings::FIELD_STRIP_TAGS ] );
		$this->assertContains( 'json', $sent[ ZoneSettings::FIELD_CONTENT_TYPES ], 'The repair must not drop a class the user enabled' );
		foreach ( ZoneSettings::content_types() as $expected ) {
			$this->assertContains( $expected, $sent[ ZoneSettings::FIELD_CONTENT_TYPES ] );
		}
	}

	public function test_apply_is_blocked_for_non_admins(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'api_token' => 'tok',
				'zone_id'   => 2,
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Settings::SYNC_SETTINGS_ACTION );

		$this->expectException( WPDieException::class );
		( new Settings() )->handle_sync_zone_settings();
	}

	public function test_apply_requires_a_valid_nonce(): void {
		update_option(
			Settings::OPTION_NAME,
			array(
				'api_token' => 'tok',
				'zone_id'   => 2,
			)
		);
		wp_set_current_user( $this->admin_id );
		$_REQUEST['_wpnonce'] = 'not-a-real-nonce';

		$this->expectException( WPDieException::class );
		( new Settings() )->handle_sync_zone_settings();
	}
}
