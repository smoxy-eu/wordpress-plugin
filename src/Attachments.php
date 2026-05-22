<?php

namespace Smoxy\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Invalidates cached image responses when an attachment's file contents or
 * generated size variants change.
 *
 * smoxy tags cached responses by reading headers off origin replies, but
 * /wp-content/uploads/ is served as static files directly by the web server
 * and never passes through PHP — so a per-attachment `x-cache-tags` header
 * cannot be attached to image responses from here. We fall back to a URL
 * BAN of every size that WordPress currently knows about.
 *
 * Hooks:
 *  - wp_update_attachment_metadata fires after WP (re)generates the size
 *    variants for an attachment. It is the reliable signal for new uploads,
 *    Regenerate Thumbnails, and the built-in image editor — all of which
 *    funnel through wp_generate_attachment_metadata + this filter.
 *  - delete_attachment fires BEFORE the file is unlinked, so the sizes
 *    map is still intact and can be enumerated.
 *
 * URLs are deduped and flushed at shutdown so a single request issues at
 * most one BAN per URL, even if multiple hooks touch the same attachment.
 */
class Attachments {

	/** @var array<string,true> */
	private array $urls = array();

	public function register(): void {
		// wp_update_attachment_metadata is a filter; we must return the value.
		add_filter( 'wp_update_attachment_metadata', array( $this, 'on_metadata_updated' ), 10, 2 );
		add_action( 'delete_attachment', array( $this, 'on_attachment_deleted' ), 10, 1 );

		add_action( 'shutdown', array( $this, 'flush' ), 99 );
	}

	/**
	 * @param mixed $metadata Attachment metadata array (or unexpected scalar from a third-party filter).
	 * @return mixed
	 */
	public function on_metadata_updated( $metadata, int $attachment_id ) {
		$this->queue_attachment_urls( $attachment_id, is_array( $metadata ) ? $metadata : array() );
		return $metadata;
	}

	public function on_attachment_deleted( int $attachment_id ): void {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$this->queue_attachment_urls( $attachment_id, is_array( $metadata ) ? $metadata : array() );
	}

	/**
	 * @param array<string,mixed> $metadata
	 */
	private function queue_attachment_urls( int $attachment_id, array $metadata ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( '' !== $mime && ! str_starts_with( $mime, 'image/' ) ) {
			return;
		}

		$full_url = wp_get_attachment_url( $attachment_id );
		if ( is_string( $full_url ) && '' !== $full_url ) {
			$this->urls[ $full_url ] = true;
		}

		// wp_get_original_image_url() returns the pre-`-scaled` original on
		// WP 5.3+ when the upload was downscaled by big_image_size_threshold.
		if ( function_exists( 'wp_get_original_image_url' ) ) {
			$original = wp_get_original_image_url( $attachment_id );
			if ( is_string( $original ) && '' !== $original ) {
				$this->urls[ $original ] = true;
			}
		}

		$file  = isset( $metadata['file'] ) ? (string) $metadata['file'] : '';
		$sizes = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		if ( '' === $file || empty( $sizes ) ) {
			return;
		}

		$upload = wp_get_upload_dir();
		if ( ! is_array( $upload ) || empty( $upload['baseurl'] ) ) {
			return;
		}

		$dir  = dirname( $file );
		$base = trailingslashit( (string) $upload['baseurl'] );
		if ( '.' !== $dir && '' !== $dir ) {
			$base .= trailingslashit( $dir );
		}

		foreach ( $sizes as $size ) {
			if ( ! is_array( $size ) || empty( $size['file'] ) ) {
				continue;
			}
			$this->urls[ $base . (string) $size['file'] ] = true;
		}
	}

	public function flush(): void {
		if ( empty( $this->urls ) ) {
			return;
		}

		( new Purger() )->purge_urls( array_keys( $this->urls ) );
		$this->urls = array();
	}
}
