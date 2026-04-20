<?php
/**
 * WooCommerce helper utilities (shared across flows).
 *
 * @package WooTelegram_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin WooCommerce helper layer.
 */
class WooTG_WC_Helper {

	/**
	 * Get product categories.
	 *
	 * @return list<array{id:int,name:string}>
	 */
	public static function get_categories( int $limit = 20 ): array {
		$limit = max( 1, absint( $limit ) );

		// Defensive: if WooCommerce isn't loaded, avoid taxonomy queries in future contexts.
		if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => $limit,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$out = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
				continue;
			}

			$out[] = array(
				'id'   => absint( $term->term_id ),
				'name' => isset( $term->name ) ? (string) $term->name : '',
			);
		}

		return $out;
	}
}

