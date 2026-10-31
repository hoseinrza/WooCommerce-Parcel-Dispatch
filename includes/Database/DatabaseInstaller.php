<?php
/**
 * Creates and upgrades the dispatch table.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Database;

final class DatabaseInstaller {

	public const DB_VERSION        = '1.0.0';
	public const DB_VERSION_OPTION = 'wcpd_db_version';

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'wcpd_dispatches';
	}

	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $this->schema() );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	private function schema(): string {
		global $wpdb;

		$table = self::table_name();
		$collate = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: one field per line, two spaces after PRIMARY KEY.
		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			barcode varchar(100) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			dispatched_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY unique_barcode (barcode),
			KEY order_id (order_id),
			KEY user_id (user_id)
		) ENGINE=InnoDB {$collate};";
	}
}
