<?php

use Smoxy\WP\Purger;
use Smoxy\WP\Settings;

/**
 * Tests for Smoxy\WP\Attachments — per-URL BAN of an attachment's full
 * image plus every generated thumbnail size.
 *
 * Image responses are served as static files by the web server and never
 * pass through PHP, so a tag header cannot be attached at the origin.
 * Attachments falls back to a URL BAN per size, deduped and flushed once
 * at shutdown.
 */
class AttachmentsTest extends WP_UnitTestCase {


	/** @var list<string> */
	private array $banned = array();

	public function set_up(): void {
		parent::set_up();

		$this->banned = array();

		update_option( Settings::OPTION_NAME, array( 'secret_key' => 'test-token' ) );

		// purge_urls() goes through Requests::request_multiple(), which
		// bypasses the WP HTTP API — and therefore the pre_http_request
		// filter every other test in this suite uses. The Purger exposes a
		// dedicated short-circuit filter for exactly this reason.
		add_filter( 'smoxy_pre_purge_urls', array( $this, 'capture_purge_urls' ), 10, 2 );

		// Manually firing `shutdown` in a test would otherwise close PHPUnit's
		// own output buffers and trip the risky-test guard.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
	}

	public function tear_down(): void {
		remove_filter( 'smoxy_pre_purge_urls', array( $this, 'capture_purge_urls' ), 10 );
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Records the URL set the Purger is about to dispatch in parallel and
	 * returns a synthetic all-success result array so no real HTTP fires.
	 *
	 * @param mixed        $preempt
	 * @param list<string> $urls
	 * @return array<string,array{ok:bool,message:string}>
	 */
	public function capture_purge_urls( $preempt, array $urls ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $preempt is part of the filter signature.
		foreach ( $urls as $url ) {
			$this->banned[] = $url;
		}
		$results = array();
		foreach ( $urls as $url ) {
			$results[ $url ] = array(
				'ok'      => true,
				'message' => '',
			);
		}
		return $results;
	}

	public function test_metadata_update_bans_full_url_and_every_size(): void {
		$attachment_id = $this->make_image_attachment( '2024/01/photo.jpg' );

		// wp_update_attachment_metadata fires the filter Attachments listens to.
		wp_update_attachment_metadata( $attachment_id, $this->image_metadata( '2024/01/photo.jpg' ) );

		do_action( 'shutdown' );

		$upload = wp_get_upload_dir();
		$base   = trailingslashit( (string) $upload['baseurl'] ) . '2024/01/';

		$this->assertContains( $base . 'photo.jpg', $this->banned, 'Full-size image URL must be invalidated' );
		$this->assertContains( $base . 'photo-150x150.jpg', $this->banned, 'Thumbnail size must be invalidated' );
		$this->assertContains( $base . 'photo-300x200.jpg', $this->banned, 'Medium size must be invalidated' );
		$this->assertContains( $base . 'photo-1024x683.jpg', $this->banned, 'Large size must be invalidated' );
	}

	public function test_metadata_update_dispatches_in_a_single_parallel_batch(): void {
		$attachment_id = $this->make_image_attachment( '2024/05/batch.jpg' );
		wp_update_attachment_metadata( $attachment_id, $this->image_metadata( '2024/05/batch.jpg' ) );

		$batches = 0;
		add_filter(
			'smoxy_pre_purge_urls',
			static function ( $preempt, array $urls ) use ( &$batches ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				++$batches;
				return $preempt;
			},
			5,
			2
		);

		do_action( 'shutdown' );

		$this->assertSame( 1, $batches, 'All sizes must dispatch via a single parallel batch, not one BAN per URL' );
	}

	public function test_delete_attachment_bans_all_known_sizes(): void {
		$attachment_id = $this->make_image_attachment( '2024/02/banner.jpg' );
		wp_update_attachment_metadata( $attachment_id, $this->image_metadata( '2024/02/banner.jpg' ) );

		// Drop everything queued by the metadata update so we only see the
		// delete-driven BANs.
		do_action( 'shutdown' );
		$this->banned = array();

		wp_delete_attachment( $attachment_id, true );
		do_action( 'shutdown' );

		$upload = wp_get_upload_dir();
		$base   = trailingslashit( (string) $upload['baseurl'] ) . '2024/02/';

		$this->assertContains( $base . 'banner.jpg', $this->banned );
		$this->assertContains( $base . 'banner-150x150.jpg', $this->banned );
		$this->assertContains( $base . 'banner-300x200.jpg', $this->banned );
	}

	public function test_duplicate_metadata_updates_dedupe_to_single_ban_per_url(): void {
		$attachment_id = $this->make_image_attachment( '2024/03/dup.jpg' );

		wp_update_attachment_metadata( $attachment_id, $this->image_metadata( '2024/03/dup.jpg' ) );
		wp_update_attachment_metadata( $attachment_id, $this->image_metadata( '2024/03/dup.jpg' ) );
		wp_update_attachment_metadata( $attachment_id, $this->image_metadata( '2024/03/dup.jpg' ) );

		do_action( 'shutdown' );

		$this->assertSame(
			array_values( array_unique( $this->banned ) ),
			$this->banned,
			'URLs must be unique across BAN calls'
		);
	}

	public function test_non_image_attachment_does_not_trigger_ban(): void {
		$attachment_id = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'spec.pdf',
				'guid'           => 'http://example.org/wp-content/uploads/2024/01/spec.pdf',
			)
		);

		// Non-image attachments don't normally carry a sizes array; passing
		// one would still be ignored because the mime check short-circuits.
		wp_update_attachment_metadata( $attachment_id, array( 'file' => '2024/01/spec.pdf' ) );

		do_action( 'shutdown' );

		$this->assertCount( 0, $this->banned, 'Non-image attachments must not invalidate any URLs' );
	}

	public function test_metadata_filter_returns_value_unchanged(): void {
		$attachment_id = $this->make_image_attachment( '2024/04/passthrough.jpg' );
		$meta          = $this->image_metadata( '2024/04/passthrough.jpg' );

		wp_update_attachment_metadata( $attachment_id, $meta );

		$stored = wp_get_attachment_metadata( $attachment_id );
		$this->assertSame( $meta['file'], $stored['file'] ?? null, 'Filter must not mutate the metadata it observes' );
		$this->assertSame( $meta['sizes'], $stored['sizes'] ?? null );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function make_image_attachment( string $relative_path ): int {
		$upload = wp_get_upload_dir();
		$guid   = trailingslashit( (string) $upload['baseurl'] ) . $relative_path;

		$id = (int) self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => basename( $relative_path ),
				'guid'           => $guid,
			)
		);

		// Pin wp_get_attachment_url() to a deterministic upload-relative URL
		// so the test does not depend on guid fallback heuristics.
		update_post_meta( $id, '_wp_attached_file', $relative_path );

		return $id;
	}

	/**
	 * Hand-rolled metadata array shaped like what wp_generate_attachment_metadata()
	 * would produce — avoids needing a real file on disk during the test.
	 *
	 * @return array<string,mixed>
	 */
	private function image_metadata( string $relative_path ): array {
		$stem = pathinfo( $relative_path, PATHINFO_FILENAME );
		$ext  = pathinfo( $relative_path, PATHINFO_EXTENSION );

		return array(
			'file'   => $relative_path,
			'width'  => 2048,
			'height' => 1365,
			'sizes'  => array(
				'thumbnail' => array(
					'file'      => sprintf( '%s-150x150.%s', $stem, $ext ),
					'width'     => 150,
					'height'    => 150,
					'mime-type' => 'image/jpeg',
				),
				'medium'    => array(
					'file'      => sprintf( '%s-300x200.%s', $stem, $ext ),
					'width'     => 300,
					'height'    => 200,
					'mime-type' => 'image/jpeg',
				),
				'large'     => array(
					'file'      => sprintf( '%s-1024x683.%s', $stem, $ext ),
					'width'     => 1024,
					'height'    => 683,
					'mime-type' => 'image/jpeg',
				),
			),
		);
	}
}
