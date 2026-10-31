<?php

namespace WCPD\Tests\Integration;

use WCPD\Database\DatabaseInstaller;
use WCPD\Database\DispatchRepository;

class DispatchRepositoryTest extends DatabaseTestCase {

	private DispatchRepository $repository;

	protected function setUp(): void {
		parent::setUp();
		( new DatabaseInstaller() )->install();
		$this->repository = new DispatchRepository();
	}

	private function count_rows(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DatabaseInstaller::table_name() );
	}

	public function test_create_persists_a_dispatch_record(): void {
		$id = $this->repository->create( '1234567890', 42, 7, '2026-10-31 08:15:00' );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 1, $this->count_rows() );
	}

	public function test_create_returns_the_inserted_id(): void {
		$id = $this->repository->create( '1234567890', 42, 7, '2026-10-31 08:15:00' );

		$this->assertSame( (int) $id, (int) $this->repository->find_by_barcode( '1234567890' )['id'] );
	}

	public function test_find_by_barcode_returns_the_record(): void {
		$this->repository->create( '0012345678', 42, 7, '2026-10-31 08:15:00' );

		$record = $this->repository->find_by_barcode( '0012345678' );

		$this->assertSame(
			array(
				'id'            => $record['id'],
				'barcode'       => '0012345678',
				'order_id'      => 42,
				'user_id'       => 7,
				'dispatched_at' => '2026-10-31 08:15:00',
			),
			$record
		);
	}

	public function test_find_by_barcode_returns_null_for_unknown_barcode(): void {
		$this->assertNull( $this->repository->find_by_barcode( '0000000000' ) );
	}

	public function test_find_by_barcode_does_not_match_a_different_barcode(): void {
		$this->repository->create( '0012345678', 42, 7, '2026-10-31 08:15:00' );

		$this->assertNull( $this->repository->find_by_barcode( '12345678' ) );
	}

	public function test_history_for_order_returns_every_parcel_oldest_first(): void {
		$this->repository->create( '111', 42, 7, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 42, 8, '2026-10-31 09:00:00' );
		$this->repository->create( '333', 43, 7, '2026-10-31 10:00:00' );

		$history = $this->repository->history_for_order( 42 );

		$this->assertSame( array( '111', '222' ), array_column( $history, 'barcode' ) );
	}

	public function test_history_for_unknown_order_is_empty(): void {
		$this->assertSame( array(), $this->repository->history_for_order( 999 ) );
	}

	public function test_exists_detects_a_registered_barcode(): void {
		$this->repository->create( '1234567890', 42, 7, '2026-10-31 08:15:00' );

		$this->assertTrue( $this->repository->exists( '1234567890' ) );
		$this->assertFalse( $this->repository->exists( '0000000000' ) );
	}

	public function test_stores_user_id_and_timestamp_exactly(): void {
		$this->repository->create( '1234567890', 42, 99, '2026-01-02 03:04:05' );

		$record = $this->repository->find_by_barcode( '1234567890' );

		$this->assertSame( 99, $record['user_id'] );
		$this->assertSame( '2026-01-02 03:04:05', $record['dispatched_at'] );
	}

	public function test_database_rejects_duplicate_barcode_rows(): void {
		global $wpdb;

		$this->repository->create( '1234567890', 42, 7, '2026-10-31 08:15:00' );

		$wpdb->suppress_errors( true );
		$inserted = $wpdb->insert(
			DatabaseInstaller::table_name(),
			array(
				'barcode'       => '1234567890',
				'order_id'      => 43,
				'user_id'       => 8,
				'dispatched_at' => '2026-10-31 09:00:00',
			),
			array( '%s', '%d', '%d', '%s' )
		);
		$last_error = $wpdb->last_error;
		$wpdb->suppress_errors( false );

		$this->assertFalse( $inserted );
		$this->assertStringContainsString( 'Duplicate entry', $last_error );
		$this->assertSame( 1, $this->count_rows() );
	}

	public function test_create_returns_zero_when_database_insert_fails(): void {
		$this->repository->create( '1234567890', 42, 7, '2026-10-31 08:15:00' );
		global $wpdb;
		$wpdb->suppress_errors( true );

		$second = $this->repository->create( '1234567890', 43, 8, '2026-10-31 09:00:00' );

		$wpdb->suppress_errors( false );
		$this->assertSame( 0, $second );
		$this->assertSame( 1, $this->count_rows() );
	}

	public function test_same_order_can_have_multiple_parcels(): void {
		$this->repository->create( '111', 42, 7, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 42, 7, '2026-10-31 08:05:00' );

		$this->assertCount( 2, $this->repository->history_for_order( 42 ) );
	}

	public function test_paginate_returns_newest_first_with_total(): void {
		$this->repository->create( '111', 42, 7, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 43, 8, '2026-10-31 09:00:00' );

		$page = $this->repository->paginate( array(), 1, 20 );

		$this->assertSame( 2, $page['total'] );
		$this->assertSame( array( '222', '111' ), array_column( $page['items'], 'barcode' ) );
	}

	public function test_paginate_honours_page_and_per_page(): void {
		$this->repository->create( '111', 42, 7, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 42, 7, '2026-10-31 09:00:00' );
		$this->repository->create( '333', 42, 7, '2026-10-31 10:00:00' );

		$first = $this->repository->paginate( array(), 1, 2 );
		$last  = $this->repository->paginate( array(), 2, 2 );

		$this->assertSame( 3, $first['total'] );
		$this->assertSame( array( '333', '222' ), array_column( $first['items'], 'barcode' ) );
		$this->assertSame( array( '111' ), array_column( $last['items'], 'barcode' ) );
	}

	public function test_paginate_filters_by_barcode(): void {
		$this->repository->create( '111', 42, 7, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 43, 8, '2026-10-31 09:00:00' );

		$page = $this->repository->paginate( array( 'barcode' => '222' ), 1, 20 );

		$this->assertSame( 1, $page['total'] );
		$this->assertSame( '222', $page['items'][0]['barcode'] );
	}

	public function test_paginate_filters_by_order_id(): void {
		$this->repository->create( '111', 42, 7, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 43, 8, '2026-10-31 09:00:00' );

		$page = $this->repository->paginate( array( 'order_id' => 43 ), 1, 20 );

		$this->assertSame( 1, $page['total'] );
		$this->assertSame( '222', $page['items'][0]['barcode'] );
	}

	public function test_paginate_filters_by_operator(): void {
		$this->repository->create( '111', 42, 7, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 43, 8, '2026-10-31 09:00:00' );

		$page = $this->repository->paginate( array( 'user_id' => 7 ), 1, 20 );

		$this->assertSame( 1, $page['total'] );
		$this->assertSame( '111', $page['items'][0]['barcode'] );
	}

	public function test_paginate_combines_filters(): void {
		$this->repository->create( '111', 42, 7, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 42, 8, '2026-10-31 09:00:00' );
		$this->repository->create( '333', 43, 7, '2026-10-31 10:00:00' );

		$page = $this->repository->paginate(
			array(
				'order_id' => 42,
				'user_id'  => 7,
			),
			1,
			20
		);

		$this->assertSame( 1, $page['total'] );
		$this->assertSame( '111', $page['items'][0]['barcode'] );
	}

	public function test_paginate_clamps_invalid_pagination(): void {
		$this->repository->create( '111', 42, 7, '2026-10-31 08:00:00' );

		$page = $this->repository->paginate( array(), 0, 500 );

		$this->assertSame( 1, $page['total'] );
		$this->assertCount( 1, $page['items'] );
	}
}
