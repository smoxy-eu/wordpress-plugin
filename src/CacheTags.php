<?php

namespace Smoxy\WP;

defined( 'ABSPATH' ) || exit;

class CacheTags {


	public const HEADER = 'X-Cache-Tags';

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'send_header' ), 99 );
	}

	public function send_header(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || headers_sent() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$tags = $this->collect_tags();
		if ( empty( $tags ) ) {
			return;
		}

		header( sprintf( '%s: %s', self::HEADER, implode( ',', $tags ) ) );
	}

	/**
	 * @return list<string>
	 */
	private function collect_tags(): array {
		$tags = array();

		if ( is_feed() ) {
			$tags[] = 'feed';
		}

		if ( is_front_page() || is_home() ) {
			$tags[] = 'home';
		}

		if ( is_author() ) {
			$id = (int) get_queried_object_id();
			if ( $id > 0 ) {
				$tags[] = 'a-' . $id;
			}
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$tid = (int) get_queried_object_id();
			if ( $tid > 0 ) {
				$tags[] = 't-' . $tid;
			}
		}

		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( $id > 0 ) {
				$tags[] = 'p-' . $id;
			}
		} else {
			global $wp_query;
			if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) {
				foreach ( $wp_query->posts as $post ) {
					if ( $post instanceof \WP_Post ) {
						$pid = (int) $post->ID;
					} elseif ( is_object( $post ) && isset( $post->ID ) ) {
						$pid = (int) $post->ID;
					} else {
						$pid = (int) $post;
					}
					if ( $pid > 0 ) {
						$tags[] = 'p-' . $pid;
					}
				}
			}
		}

		return array_values( array_unique( $tags ) );
	}
}
