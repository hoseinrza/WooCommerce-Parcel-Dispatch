<?php
/**
 * Finds WooCommerce orders by postal barcode.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Barcode;

use Automattic\WooCommerce\Utilities\OrderUtil;
use WC_Order;

final class BarcodeOrderFinder {

	/**
	 * Query var used to pass the barcode to the legacy (CPT) datastore filter.
	 */
	private const LEGACY_QUERY_VAR = 'wcpd_post_barcode';

	/**
	 * Returns every order whose `post_barcode` metadata equals the barcode exactly.
	 *
	 * Uses wc_get_orders() in both storage modes. HPOS supports meta_query directly;
	 * the legacy CPT datastore only accepts metadata through its documented
	 * `woocommerce_order_data_store_cpt_get_orders_query` filter.
	 *
	 * @return WC_Order[]
	 */
	public function find( string $barcode ): array {
		if ( '' === $barcode ) {
			return array();
		}

		$args = array(
			'limit'   => -1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		);

		if ( $this->uses_hpos() ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => 'post_barcode',
					'value'   => $barcode,
					'compare' => '=',
				),
			);

			$orders = wc_get_orders( $args );
		} else {
			$args[ self::LEGACY_QUERY_VAR ] = $barcode;

			$filter = static function ( array $wp_query_args, array $query_vars ): array {
				if ( isset( $query_vars[ self::LEGACY_QUERY_VAR ] ) ) {
					$wp_query_args['meta_query'][] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'key'     => 'post_barcode',
						'value'   => $query_vars[ self::LEGACY_QUERY_VAR ],
						'compare' => '=',
					);
				}

				return $wp_query_args;
			};

			add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $filter, 10, 2 );

			try {
				$orders = wc_get_orders( $args );
			} finally {
				remove_filter( 'woocommerce_order_data_store_cpt_get_orders_query', $filter, 10 );
			}
		}

		// Database collations can be case-insensitive; enforce exact equality here.
		return array_values(
			array_filter(
				$orders,
				static fn( WC_Order $order ): bool => (string) $order->get_meta( 'post_barcode', true ) === $barcode
			)
		);
	}

	private function uses_hpos(): bool {
		return class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
