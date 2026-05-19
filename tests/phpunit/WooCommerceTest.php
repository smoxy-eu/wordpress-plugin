<?php

use Smoxy\WP\Purger;
use Smoxy\WP\Settings;

/**
 * Tests for Smoxy\WP\WooCommerce — the Woo-specific invalidation triggers.
 *
 * The test environment does not have WooCommerce installed; that is the point.
 * We exercise the hooks Woo would fire by dispatching them with do_action and
 * primitive arguments, and assert that the parent product / product is queued
 * for a BAN. Because the hook names are Woo-specific (or save_post variants
 * scoped to a `product_variation` CPT), nothing fires on a vanilla WP site —
 * registration is inert in that case.
 */
class WooCommerceTest extends WP_UnitTestCase {


	/** @var array<int,array{url:string,args:array<string,mixed>}> */
	private array $http_calls = array();

	public function set_up(): void {
		parent::set_up();

		$this->http_calls = array();

		update_option( Settings::OPTION_NAME, array( 'secret_key' => 'test-token' ) );

		add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );

		// WP's default shutdown handler closes every active output buffer, which
		// trips PHPUnit's "risky test" check when we fire `shutdown` manually.
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );

		// product_variation is a Woo CPT. Register a stand-in so wp_insert_post()
		// accepts the type and save_post_product_variation fires.
		if ( ! post_type_exists( 'product_variation' ) ) {
			register_post_type(
				'product_variation',
				array(
					'public'       => false,
					'hierarchical' => false,
				)
			);
		}
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
		delete_option( Settings::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * @param false|array|\WP_Error $preempt
	 * @param array<string,mixed>   $args
	 */
	public function capture_http( $preempt, $args, string $url ) {
		if ( str_starts_with( $url, Purger::INGRESS_URL ) ) {
			$this->http_calls[] = array(
				'url'  => $url,
				'args' => $args,
			);
		}
		return $preempt;
	}

	public function test_variation_save_purges_parent_product(): void {
		$parent_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->flush_and_clear();

		wp_insert_post(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_parent' => $parent_id,
				'post_title'  => 'Variation #1',
			)
		);
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls, 'Variation save must trigger a BAN that includes the parent product' );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $parent_id, $tags );
	}

	public function test_product_stock_change_purges_product(): void {
		$product_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->flush_and_clear();

		// Woo fires this with a WC_Product. Our handler accepts an int too.
		do_action( 'woocommerce_product_set_stock', $product_id );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $product_id, $tags );
	}

	public function test_variation_stock_change_purges_parent_product(): void {
		$parent_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$variation_id = wp_insert_post(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_parent' => $parent_id,
				'post_title'  => 'Variation',
			)
		);
		$this->flush_and_clear();

		do_action( 'woocommerce_variation_set_stock', $variation_id );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $parent_id, $tags );
	}

	public function test_product_stock_status_change_purges_product(): void {
		$product_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->flush_and_clear();

		do_action( 'woocommerce_product_set_stock_status', $product_id, 'outofstock', null );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $product_id, $tags );
	}

	public function test_variation_stock_status_change_purges_parent_product(): void {
		$parent_id    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$variation_id = wp_insert_post(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_parent' => $parent_id,
				'post_title'  => 'Variation',
			)
		);
		$this->flush_and_clear();

		do_action( 'woocommerce_variation_set_stock_status', $variation_id, 'instock', null );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $parent_id, $tags );
	}

	public function test_unpublished_product_stock_change_does_not_purge(): void {
		$product_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		do_action( 'woocommerce_product_set_stock', $product_id );
		do_action( 'shutdown' );

		$this->assertCount( 0, $this->http_calls );
	}

	public function test_variation_without_parent_is_a_noop(): void {
		// Orphan variation (no post_parent). Nothing to purge.
		$variation_id = wp_insert_post(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_title'  => 'Orphan',
			)
		);
		$this->flush_and_clear();

		do_action( 'woocommerce_variation_set_stock', $variation_id );
		do_action( 'shutdown' );

		$this->assertCount( 0, $this->http_calls );
	}

	public function test_stock_change_accepts_wc_product_like_object(): void {
		$product_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->flush_and_clear();

		// Stand-in for WC_Product — only the get_id() method is used.
		$product = new class( $product_id ) {

			public function __construct( private int $id ) {}

			public function get_id(): int {
				return $this->id;
			}
		};

		do_action( 'woocommerce_product_set_stock', $product );
		do_action( 'shutdown' );

		$this->assertCount( 1, $this->http_calls );
		$tags = $this->tags_from( $this->http_calls[0] );
		$this->assertContains( 'p-' . $product_id, $tags );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function flush_and_clear(): void {
		do_action( 'shutdown' );
		$this->http_calls = array();
	}

	/**
	 * @param array{url:string,args:array<string,mixed>} $call
	 * @return string[]
	 */
	private function tags_from( array $call ): array {
		$headers = $call['args']['headers'] ?? array();
		$raw     = isset( $headers['tags'] ) ? (string) $headers['tags'] : '';
		return '' === $raw ? array() : explode( ',', $raw );
	}
}
