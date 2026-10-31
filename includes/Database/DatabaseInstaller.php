<?php
/**
 * Creates and upgrades the dispatch table.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Database;

final class DatabaseInstaller {

	public const DB_VERSION              = '1.1.0';
	public const DB_VERSION_OPTION       = 'wcpd_db_version';
	public const STORAGE_TIMEZONE_OPTION = 'wcpd_storage_timezone';

	private const STORAGE_TIMEZONE_SITE = 'site';

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'wcpd_dispatches';
	}

	public function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $this->schema() );

		if ( self::STORAGE_TIMEZONE_SITE !== get_option( self::STORAGE_TIMEZONE_OPTION ) ) {
			$this->convert_dispatched_at_to_site_time();
			update_option( self::STORAGE_TIMEZONE_OPTION, self::STORAGE_TIMEZONE_SITE );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Rows written before 1.0.6 were stored in UTC; shift them once to the site timezone.
	 */
	private function convert_dispatched_at_to_site_time(): void {
		global $wpdb;

		$table = self::table_name();
		$rows  = $wpdb->get_results( "SELECT id, dispatched_at FROM {$table}", ARRAY_A );

		foreach ( $rows as $row ) {
			$local = get_date_from_gmt( (string) $row['dispatched_at'], 'Y-m-d H:i:s' );

			if ( $local !== $row['dispatched_at'] ) {
				$wpdb->update(
					$table,
					array( 'dispatched_at' => $local ),
					array( 'id' => (int) $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
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
