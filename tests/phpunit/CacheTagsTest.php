<?php

use Smoxy\WP\CacheTags;

/**
 * Feature-level tests for CacheTags::send_header().
 *
 * For each page type (singular, front page, feed, author archive,
 * category/tag archive, archive loop) we drive WP into the matching query
 * state with go_to(), call send_header(), and assert on the X-Cache-Tags
 * header that was emitted. Skip conditions (admin, AJAX, cron, empty query)
 * must produce no header at all.
 *
 * The Smoxy\WP\header() stub in tests/_header-stub.php captures emissions
 * into CacheTagsTestHeaderCapture since CLI PHP would otherwise drop them.
 */
class CacheTagsTest extends WP_UnitTestCase {


	public function set_up(): void {
		parent::set_up();
		CacheTagsTestHeaderCapture::reset();
	}

	public function tear_down(): void {
		// Reset admin-screen state set by the skip-path tests so it doesn't
		// leak into other tests.
		set_current_screen( 'front' );
		parent::tear_down();
	}

	/* ------------------------------------------------------------------
	 * Happy paths — page type -> emitted tags
	 * ------------------------------------------------------------------ */

	public function test_singular_post_emits_post_tag(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( (string) get_permalink( $post_id ) );

		( new CacheTags() )->send_header();

		$this->assertContains( 'p-' . $post_id, $this->captured_tags() );
	}

	public function test_front_page_emits_home_tag(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( home_url( '/' ) );

		( new CacheTags() )->send_header();

		$this->assertContains( 'home', $this->captured_tags() );
	}

	public function test_feed_emits_feed_tag(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		// The default test install has no rewrite rules, so /feed/ does not
		// resolve to is_feed(); use the query-var form instead.
		$this->go_to( home_url( '/?feed=rss2' ) );

		( new CacheTags() )->send_header();

		$this->assertContains( 'feed', $this->captured_tags() );
	}

	public function test_author_archive_emits_author_tag(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);
		$this->go_to( get_author_posts_url( $author_id ) );

		( new CacheTags() )->send_header();

		$this->assertContains( 'a-' . $author_id, $this->captured_tags() );
	}

	public function test_category_archive_emits_term_tag(): void {
		$cat_id = self::factory()->category->create( array( 'name' => 'CT Cat' ) );
		self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_category' => array( $cat_id ),
			)
		);
		$this->go_to( (string) get_term_link( $cat_id, 'category' ) );

		( new CacheTags() )->send_header();

		$this->assertContains( 't-' . $cat_id, $this->captured_tags() );
	}

	public function test_tag_archive_emits_term_tag(): void {
		$tag_id  = self::factory()->tag->create( array( 'name' => 'CT Tag' ) );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_set_post_terms( $post_id, array( (int) $tag_id ), 'post_tag' );
		$this->go_to( (string) get_term_link( (int) $tag_id, 'post_tag' ) );

		( new CacheTags() )->send_header();

		$this->assertContains( 't-' . $tag_id, $this->captured_tags() );
	}

	public function test_archive_loop_emits_tag_for_every_post_in_query(): void {
		$cat_id = self::factory()->category->create( array( 'name' => 'CT Loop' ) );
		$post_a = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_category' => array( $cat_id ),
			)
		);
		$post_b = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_category' => array( $cat_id ),
			)
		);
		$this->go_to( (string) get_term_link( $cat_id, 'category' ) );

		( new CacheTags() )->send_header();

		$tags = $this->captured_tags();
		$this->assertContains( 't-' . $cat_id, $tags );
		$this->assertContains( 'p-' . $post_a, $tags );
		$this->assertContains( 'p-' . $post_b, $tags );
	}

	public function test_header_contains_only_expected_prefixes(): void {
		// Ensures every emitted tag matches one of the four known shapes
		// (home|feed|p-\d+|t-\d+|a-\d+) — catches accidental "leak" of
		// unrelated strings into the header value.
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( (string) get_permalink( $post_id ) );

		( new CacheTags() )->send_header();

		foreach ( $this->captured_tags() as $tag ) {
			$this->assertMatchesRegularExpression(
				'/^(home|feed|p-\d+|t-\d+|a-\d+)$/',
				$tag,
				sprintf( 'Unexpected tag format: %s', $tag )
			);
		}
	}

	/* ------------------------------------------------------------------
	 * Skip paths — no header emitted
	 * ------------------------------------------------------------------ */

	public function test_admin_context_emits_no_header(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( (string) get_permalink( $post_id ) );
		CacheTagsTestHeaderCapture::reset();

		set_current_screen( 'edit.php' );
		( new CacheTags() )->send_header();

		$this->assertEmpty( CacheTagsTestHeaderCapture::$captured );
	}

	public function test_ajax_context_emits_no_header(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( (string) get_permalink( $post_id ) );
		CacheTagsTestHeaderCapture::reset();

		add_filter( 'wp_doing_ajax', '__return_true' );
		try {
			( new CacheTags() )->send_header();
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		$this->assertEmpty( CacheTagsTestHeaderCapture::$captured );
	}

	public function test_cron_context_emits_no_header(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( (string) get_permalink( $post_id ) );
		CacheTagsTestHeaderCapture::reset();

		add_filter( 'wp_doing_cron', '__return_true' );
		try {
			( new CacheTags() )->send_header();
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}

		$this->assertEmpty( CacheTagsTestHeaderCapture::$captured );
	}

	public function test_empty_query_emits_no_header(): void {
		// 404 — no matching post, no archive branch fires, wp_query->posts is
		// empty, so collect_tags() returns [] and send_header() short-circuits.
		$this->go_to( home_url( '/?p=999999' ) );

		( new CacheTags() )->send_header();

		$this->assertEmpty( CacheTagsTestHeaderCapture::$captured );
	}

	public function test_non_get_request_emits_no_header(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( (string) get_permalink( $post_id ) );
		CacheTagsTestHeaderCapture::reset();

		$previous                  = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		try {
			( new CacheTags() )->send_header();
		} finally {
			if ( null === $previous ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $previous;
			}
		}

		$this->assertEmpty( CacheTagsTestHeaderCapture::$captured );
	}

	public function test_already_sent_headers_skip_emission(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( (string) get_permalink( $post_id ) );
		CacheTagsTestHeaderCapture::reset();

		CacheTagsTestHeaderCapture::$headers_sent = true;
		try {
			( new CacheTags() )->send_header();
		} finally {
			CacheTagsTestHeaderCapture::$headers_sent = false;
		}

		$this->assertEmpty( CacheTagsTestHeaderCapture::$captured );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Parse the captured X-Cache-Tags header back into an array of tags.
	 *
	 * @return string[]
	 */
	private function captured_tags(): array {
		$prefix = CacheTags::HEADER . ': ';
		foreach ( CacheTagsTestHeaderCapture::$captured as $raw ) {
			if ( str_starts_with( $raw, $prefix ) ) {
				return explode( ',', substr( $raw, strlen( $prefix ) ) );
			}
		}
		return array();
	}
}
