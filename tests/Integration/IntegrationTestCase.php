<?php

namespace WCPD\Tests\Integration;

use WC_Order;
use WP_UnitTestCase;

abstract class IntegrationTestCase extends WP_UnitTestCase {

	protected function create_order( string $barcode = null, string $status = 'processing' ): WC_Order {
		$order = new WC_Order();
		$order->set_status( $status );
		$order->set_billing_first_name( 'Test' );
		$order->set_billing_last_name( 'Customer' );
		$order->set_billing_phone( '09121234567' );
		$order->set_billing_address_1( 'Valiasr St, No. 12' );
		$order->set_billing_city( 'Tehran' );
		$order->set_shipping_first_name( 'Test' );
		$order->set_shipping_last_name( 'Customer' );
		$order->set_shipping_address_1( 'Valiasr St, No. 12' );
		$order->set_shipping_city( 'Tehran' );

		if ( null !== $barcode ) {
			$order->update_meta_data( 'post_barcode', $barcode );
		}

		$order->save();

		return $order;
	}

	/**
	 * Switches WooCommerce order storage for the current test.
	 */
	protected function set_hpos( bool $enabled ): void {
		update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
		update_option( 'woocommerce_custom_orders_table_enabled', $enabled ? 'yes' : 'no' );
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	}

	protected function hpos_enabled(): bool {
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}
