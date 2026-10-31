<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package WooCommerce Parcel Dispatch
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wcpd_table = $wpdb->prefix . 'wcpd_dispatches';

$wpdb->query( "DROP TABLE IF EXISTS `{$wcpd_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

delete_option( 'wcpd_db_version' );
