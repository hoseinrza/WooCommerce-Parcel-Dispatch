<?php

namespace WCPD\Tests\Integration;

use WC_Order;

class SmokeTest extends \WP_UnitTestCase {

	public function test_wordpress_and_woocommerce_are_loaded(): void {
		$this->assertTrue( function_exists( 'wc_get_orders' ) );
		$this->assertTrue( class_exists( WC_Order::class ) );
		$this->assertTrue( class_exists( \WC_Install::class ) );
	}

	public function test_woocommerce_order_tables_exist(): void {
		global $wpdb;

		$posts = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}posts'" );
		$orders = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}wc_orders'" );

		$this->assertSame( "{$wpdb->prefix}posts", $posts );
		$this->assertSame( "{$wpdb->prefix}wc_orders", $orders );
	}

	public function test_can_create_order_with_post_barcode_meta(): void {
		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->update_meta_data( 'post_barcode', '0123456789' );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( '0123456789', $reloaded->get_meta( 'post_barcode', true ) );
	}
}
