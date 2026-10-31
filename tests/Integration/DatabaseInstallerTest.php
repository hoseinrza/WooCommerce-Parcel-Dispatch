<?php

namespace WCPD\Tests\Integration;

use WCPD\Database\DatabaseInstaller;

class DatabaseInstallerTest extends DatabaseTestCase {

	private DatabaseInstaller $installer;

	protected function setUp(): void {
		parent::setUp();
		$this->installer = new DatabaseInstaller();
	}

	private function table(): string {
		return DatabaseInstaller::table_name();
	}

	public function test_install_creates_dispatches_table_from_scratch(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->table() );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table() ) ) );

		$this->installer->install();

		$this->assertSame( $this->table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table() ) ) );
	}

	public function test_table_has_required_columns_with_correct_nullability(): void {
		global $wpdb;

		$this->installer->install();
		$columns = array_column( $wpdb->get_results( 'DESCRIBE ' . $this->table(), ARRAY_A ), null, 'Field' );

		$this->assertSame( 'bigint(20) unsigned', strtolower( $columns['id']['Type'] ) );
		$this->assertSame( 'auto_increment', strtolower( $columns['id']['Extra'] ) );
		$this->assertSame( 'varchar(100)', strtolower( $columns['barcode']['Type'] ) );
		$this->assertSame( 'NO', $columns['barcode']['Null'] );
		$this->assertSame( 'bigint(20) unsigned', strtolower( $columns['order_id']['Type'] ) );
		$this->assertSame( 'NO', $columns['order_id']['Null'] );
		$this->assertSame( 'bigint(20) unsigned', strtolower( $columns['user_id']['Type'] ) );
		$this->assertSame( 'NO', $columns['user_id']['Null'] );
		$this->assertSame( 'datetime', strtolower( $columns['dispatched_at']['Type'] ) );
		$this->assertSame( 'NO', $columns['dispatched_at']['Null'] );
	}

	public function test_barcode_column_has_unique_index(): void {
		global $wpdb;

		$this->installer->install();
		$indexes = $this->indexes_for_column( 'barcode' );

		$this->assertCount( 1, $indexes );
		$this->assertSame( 'unique_barcode', $indexes[0]['Key_name'] );
		$this->assertSame( '0', (string) $indexes[0]['Non_unique'] );
	}

	public function test_order_id_is_indexed_but_not_unique(): void {
		global $wpdb;

		$this->installer->install();
		$indexes = $this->indexes_for_column( 'order_id' );

		$this->assertNotEmpty( $indexes );
		$this->assertSame( '1', (string) $indexes[0]['Non_unique'] );
	}

	public function test_table_uses_innodb(): void {
		global $wpdb;

		$this->installer->install();
		$status = $wpdb->get_row( 'SHOW TABLE STATUS LIKE ' . $wpdb->prepare( '%s', $this->table() ), ARRAY_A );

		$this->assertSame( 'InnoDB', $status['Engine'] );
	}

	public function test_install_is_idempotent(): void {
		global $wpdb;

		$this->installer->install();
		$this->installer->install();

		$wpdb->suppress_errors( true );
		$this->installer->install();
		$wpdb->suppress_errors( false );

		$this->assertSame( $this->table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table() ) ) );
	}

	public function test_install_records_schema_version(): void {
		$this->installer->install();

		$this->assertSame( DatabaseInstaller::DB_VERSION, get_option( DatabaseInstaller::DB_VERSION_OPTION ) );
	}

	public function test_upgrade_converts_legacy_utc_timestamps_to_site_time_once(): void {
		global $wpdb;

		$original_timezone = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Asia/Tehran' );

		try {
			$this->installer->install();
			delete_option( DatabaseInstaller::STORAGE_TIMEZONE_OPTION );
			$wpdb->insert(
				$this->table(),
				array(
					'barcode'       => 'LEGACY-UTC-1',
					'order_id'      => 999,
					'user_id'       => 1,
					'dispatched_at' => '2026-10-31 08:00:00',
				)
			);

			$this->installer->install();
			$this->assertSame( '2026-10-31 11:30:00', $this->stored_time( 'LEGACY-UTC-1' ) );

			$this->installer->install();
			$this->assertSame( '2026-10-31 11:30:00', $this->stored_time( 'LEGACY-UTC-1' ) );
		} finally {
			$wpdb->delete( $this->table(), array( 'barcode' => 'LEGACY-UTC-1' ) );
			update_option( 'timezone_string', $original_timezone );
		}
	}

	private function stored_time( string $barcode ): ?string {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				'SELECT dispatched_at FROM ' . $this->table() . ' WHERE barcode = %s LIMIT 1',
				$barcode
			)
		);
	}

	private function indexes_for_column( string $column ): array {
		global $wpdb;

		$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . $this->table(), ARRAY_A );

		return array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => $column === $row['Column_name']
			)
		);
	}
}
