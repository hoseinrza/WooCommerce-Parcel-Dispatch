<?php
/**
 * PHPUnit bootstrap: loads the real WordPress test library with WooCommerce active.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'WP_TESTS_DIR', dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit' );
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );

$GLOBALS['wp_tests_options'] = array(
	'active_plugins' => array( 'woocommerce/woocommerce.php' ),
	'permalink_structure' => '/%postname%/',
);

require WP_TESTS_DIR . '/includes/bootstrap.php';

// The plugin activation hook does not run in the test bootstrap, so install
// WooCommerce tables, roles, and pages explicitly (same approach as WC's own suite).
if ( class_exists( 'WC_Install' ) ) {
	update_option( 'woocommerce_db_version', null );
	update_option( 'woocommerce_version', null );
	WC_Install::install();

	// WooCommerce custom tables survive the WordPress test installer, so clear
	// order data from previous runs to keep exact-match lookups deterministic.
	global $wpdb;
	foreach ( array( 'wc_orders', 'wc_orders_meta', 'wc_orders_addresses', 'wc_orders_operational', 'wc_order_product_lookup', 'wc_order_stats' ) as $wcpd_table ) {
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}{$wcpd_table}" );
	}
}

$wcpd_installer = new \WCPD\Database\DatabaseInstaller();
$wcpd_installer->install();

global $wpdb;
$wpdb->query( 'TRUNCATE TABLE ' . \WCPD\Database\DatabaseInstaller::table_name() );
