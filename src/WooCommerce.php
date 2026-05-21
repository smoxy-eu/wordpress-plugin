<?php

namespace Smoxy\WP;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce-aware invalidation triggers that complement the generic
 * save_post / term / comment hooks in EventBus.
 *
 * Two gaps in the generic flow are filled here:
 *
 *  - Variation saves fire save_post for the variation (a CPT with no public
 *    URL), not for the parent product — so the parent product page and its
 *    archives are not tagged for purge from a variation-only save.
 *  - Stock changes flowing through Woo's data store (admin stock fields,
 *    REST writes, order-driven decrement) may not trigger save_post, so the
 *    cached product and category pages would otherwise miss the update.
 *
 * Every hook this class subscribes to is either a Woo-specific action or a
 * CPT-scoped variant of save_post that only fires for `product_variation`
 * posts — none of them dispatch on a vanilla WordPress site, so registering
 * the listeners unconditionally is a no-op when WooCommerce is not active.
 */
class WooCommerce {

	public function register(): void {
		add_action( 'save_post_product', array( $this, 'on_product_saved' ), 10, 2 );
		add_action( 'save_post_product_variation', array( $this, 'on_variation_saved' ), 10, 2 );

		// WC's CRUD data store fires these on every product/variation update
		// — including meta-only changes like price edits — where save_post
		// might be skipped. They are the reliable signal for cache invalidation.
		add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 10, 1 );
		add_action( 'woocommerce_new_product', array( $this, 'on_product_updated' ), 10, 1 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_variation_updated' ), 10, 1 );

		add_action( 'woocommerce_product_set_stock', array( $this, 'on_product_stock_changed' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_variation_stock_changed' ), 10, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_product_stock_status_changed' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_variation_stock_status_changed' ), 10, 3 );
	}

	public function on_product_saved( int $product_id, \WP_Post $product ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $product is part of the save_post_{$type} signature.
		$this->queue_product_purge( $product_id );
	}

	public function on_product_updated( int $product_id ): void {
		$this->queue_product_purge( $product_id );
	}

	public function on_variation_updated( int $variation_id ): void {
		$this->queue_parent_purge( $variation_id );
	}

	public function on_variation_saved( int $variation_id, \WP_Post $variation ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $variation is part of the save_post_{$type} signature.
		$this->queue_parent_purge( $variation_id );
	}

	/**
	 * @param mixed $product WC_Product instance when WooCommerce is loaded;
	 *                       duck-typed so callers in unit tests can pass an ID.
	 */
	public function on_product_stock_changed( $product ): void {
		$this->queue_product_purge( $this->resolve_id( $product ) );
	}

	/**
	 * @param mixed $variation WC_Product_Variation when Woo is loaded.
	 */
	public function on_variation_stock_changed( $variation ): void {
		$this->queue_parent_purge( $this->resolve_id( $variation ) );
	}

	/**
	 * @param mixed  $product_id   Product ID (Woo passes int).
	 * @param string $stock_status New status. Unused; the tag set is identical regardless.
	 * @param mixed  $product      WC_Product (newer Woo). Unused — id is authoritative.
	 */
	public function on_product_stock_status_changed( $product_id, string $stock_status = '', $product = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature matches the Woo action.
		$this->queue_product_purge( (int) $product_id );
	}

	/**
	 * @param mixed  $variation_id Variation ID (Woo passes int).
	 * @param string $stock_status New status. Unused.
	 * @param mixed  $product      WC_Product_Variation. Unused.
	 */
	public function on_variation_stock_status_changed( $variation_id, string $stock_status = '', $product = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature matches the Woo action.
		$this->queue_parent_purge( (int) $variation_id );
	}

	private function queue_product_purge( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}
		$post = get_post( $product_id );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return;
		}
		do_action( 'smoxy_post_updated', $product_id, $post );
	}

	private function queue_parent_purge( int $child_id ): void {
		if ( $child_id <= 0 ) {
			return;
		}
		$parent_id = (int) wp_get_post_parent_id( $child_id );
		$this->queue_product_purge( $parent_id );
	}

	/**
	 * @param mixed $product
	 */
	private function resolve_id( $product ): int {
		if ( is_int( $product ) ) {
			return $product;
		}
		if ( is_numeric( $product ) ) {
			return (int) $product;
		}
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			return (int) $product->get_id();
		}
		return 0;
	}
}
