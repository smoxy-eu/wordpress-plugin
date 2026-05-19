<?php

namespace Smoxy\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribes to WordPress events that mutate cacheable content, re-broadcasts
 * them as smoxy_* actions for extensibility, and flushes the collected tag set
 * once at shutdown so a single request triggers one BAN call.
 */
class EventBus {


	/** @var array<string,true> */
	private array $tags = array();

	private bool $flushall = false;

	public function register(): void {
		$this->register_wp_hooks();
		$this->register_smoxy_listeners();

		add_action( 'shutdown', array( $this, 'flush' ), 100 );
	}

	/* ------------------------------------------------------------------
	 * WP hooks -> smoxy_* actions
	 * ------------------------------------------------------------------ */

	private function register_wp_hooks(): void {
		// Posts.
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'wp_trash_post', array( $this, 'on_delete_post' ) );

		// Comments.
		add_action( 'comment_post', array( $this, 'on_comment_post' ), 10, 2 );
		add_action( 'edit_comment', array( $this, 'on_comment_change' ) );
		add_action( 'wp_set_comment_status', array( $this, 'on_comment_change' ) );
		add_action( 'deleted_comment', array( $this, 'on_comment_change' ) );

		// Terms.
		add_action( 'created_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'edited_term', array( $this, 'on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( $this, 'on_term_change' ), 10, 3 );

		// Users / authors.
		add_action( 'profile_update', array( $this, 'on_user_change' ) );
		add_action( 'deleted_user', array( $this, 'on_user_change' ) );

		// Site-wide changes.
		add_action( 'wp_update_nav_menu', array( $this, 'on_flush_all' ) );
		add_action( 'update_option_sidebars_widgets', array( $this, 'on_flush_all' ) );
		add_action( 'switch_theme', array( $this, 'on_flush_all' ) );
		add_action( 'customize_save_after', array( $this, 'on_flush_all' ) );
		add_action( 'activated_plugin', array( $this, 'on_flush_all' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_flush_all' ) );
		add_action( 'upgrader_process_complete', array( $this, 'on_flush_all' ) );

		foreach ( array( 'blogname', 'blogdescription', 'home', 'siteurl', 'WPLANG', 'permalink_structure' ) as $option ) {
			add_action( "update_option_{$option}", array( $this, 'on_flush_all' ) );
		}
	}

	public function on_transition_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( $this->should_skip_post( $post->ID ) ) {
			return;
		}
		do_action( 'smoxy_post_updated', (int) $post->ID, $post );
	}

	public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $update is part of the save_post hook signature.
		if ( $this->should_skip_post( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		do_action( 'smoxy_post_updated', $post_id, $post );
	}

	public function on_delete_post( int $post_id ): void {
		if ( $this->should_skip_post( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		do_action( 'smoxy_post_deleted', $post_id, $post );
	}

	public function on_comment_post( int $comment_id, int|string $approved ): void {
		if ( 'spam' === $approved ) {
			return;
		}
		$this->fire_comment_event( $comment_id );
	}

	public function on_comment_change( int|string $comment_id ): void {
		$this->fire_comment_event( (int) $comment_id );
	}

	private function fire_comment_event( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}
		$post_id = (int) $comment->comment_post_ID;
		if ( $post_id <= 0 ) {
			return;
		}
		do_action( 'smoxy_comment_changed', $post_id, $comment );
	}

	public function on_term_change( int $term_id, int $tt_id, string $taxonomy ): void {
		do_action( 'smoxy_term_changed', $term_id, $taxonomy );
	}

	public function on_user_change( int $user_id ): void {
		do_action( 'smoxy_author_changed', $user_id );
	}

	public function on_flush_all(): void {
		do_action( 'smoxy_flush_all' );
	}

	/* ------------------------------------------------------------------
	 * smoxy_* actions -> collected tags
	 * ------------------------------------------------------------------ */

	private function register_smoxy_listeners(): void {
		add_action( 'smoxy_post_updated', array( $this, 'collect_post_tags' ), 10, 2 );
		add_action( 'smoxy_post_deleted', array( $this, 'collect_post_tags' ), 10, 2 );
		add_action( 'smoxy_comment_changed', array( $this, 'collect_comment_tags' ), 10, 1 );
		add_action( 'smoxy_term_changed', array( $this, 'collect_term_tags' ), 10, 1 );
		add_action( 'smoxy_author_changed', array( $this, 'collect_author_tags' ), 10, 1 );
		add_action( 'smoxy_flush_all', array( $this, 'collect_flush_all' ) );
	}

	public function collect_post_tags( int $post_id, \WP_Post $post ): void {
		$this->add_tag( 'p-' . $post_id );
		$this->add_tag( 'home' );
		$this->add_tag( 'feed' );

		foreach ( get_object_taxonomies( $post ) as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $tid ) {
					$this->add_tag( 't-' . (int) $tid );
				}
			}
		}

		if ( (int) $post->post_author > 0 ) {
			$this->add_tag( 'a-' . (int) $post->post_author );
		}
	}

	public function collect_comment_tags( int $post_id ): void {
		$this->add_tag( 'p-' . $post_id );
	}

	public function collect_term_tags( int $term_id ): void {
		$this->add_tag( 't-' . $term_id );
		$this->add_tag( 'home' );
	}

	public function collect_author_tags( int $user_id ): void {
		$this->add_tag( 'a-' . $user_id );
	}

	public function collect_flush_all(): void {
		$this->flushall = true;
	}

	/* ------------------------------------------------------------------
	 * Shutdown flush
	 * ------------------------------------------------------------------ */

	public function flush(): void {
		if ( $this->flushall ) {
			( new Purger() )->purge_all();
			$this->reset();
			return;
		}

		if ( empty( $this->tags ) ) {
			return;
		}

		$tags = array_keys( $this->tags );
		( new Purger() )->purge_tags( $tags );
		$this->reset();
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function add_tag( string $tag ): void {
		if ( '' === $tag ) {
			return;
		}
		$this->tags[ $tag ] = true;
	}

	private function reset(): void {
		$this->tags     = array();
		$this->flushall = false;
	}

	private function should_skip_post( int $post_id ): bool {
		return wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id );
	}
}
