<?php

namespace WCPD\Tests\Integration;

use WCPD\Barcode\BarcodeNormalizer;
use WCPD\Barcode\BarcodeOrderFinder;
use WCPD\Database\DispatchRepository;
use WCPD\Dispatch\DispatchService;
use WCPD\Dispatch\DispatchValidator;
use WCPD\Dispatch\OrderEligibilityPolicy;

class DispatchServiceTest extends DatabaseTestCase {

	private DispatchRepository $repository;
	private DispatchService $service;
	private int $operator_id;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new DispatchRepository();
		$this->service    = new DispatchService(
			new BarcodeNormalizer(),
			new BarcodeOrderFinder(),
			new DispatchValidator( new OrderEligibilityPolicy(), $this->repository ),
			$this->repository
		);

		$this->operator_id = self::factory()->user->create(
			array(
				'display_name' => 'Warehouse Alice',
				'role'         => 'shop_manager',
			)
		);
	}

	private function internal_notes( int $order_id ): array {
		return wc_get_order_notes(
			array(
				'order_id' => $order_id,
				'type'     => 'internal',
			)
		);
	}

	/**
	 * Notes written by the dispatch flow (WooCommerce adds its own status notes).
	 */
	private function dispatch_notes( int $order_id, string $barcode ): array {
		return array_values(
			array_filter(
				$this->internal_notes( $order_id ),
				static fn( $note ): bool => str_contains( $note->content, $barcode )
			)
		);
	}

	public function test_locate_returns_the_matching_order(): void {
		$order = $this->create_order( '1234567890' );

		$found = $this->service->locate( '1234567890' );

		$this->assertInstanceOf( \WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	public function test_locate_normalizes_the_scanned_barcode(): void {
		$order = $this->create_order( '1234567890' );

		$found = $this->service->locate( " ۱۲۳۴۵۶۷۸۹۰\r\n" );

		$this->assertInstanceOf( \WC_Order::class, $found );
		$this->assertSame( $order->get_id(), $found->get_id() );
	}

	public function test_locate_rejects_an_invalid_barcode(): void {
		$result = $this->service->locate( '   ' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_invalid_barcode', $result->get_error_code() );
	}

	public function test_locate_reports_an_unknown_barcode(): void {
		$result = $this->service->locate( '9999999999' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_order_not_found', $result->get_error_code() );
	}

	public function test_locate_reports_multiple_matching_orders(): void {
		$this->create_order( '1234567890' );
		$this->create_order( '1234567890' );

		$result = $this->service->locate( '1234567890' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_multiple_orders', $result->get_error_code() );
		$this->assertCount( 2, $result->get_error_data()['order_ids'] );
	}

	public function test_dispatch_registers_the_parcel(): void {
		$order = $this->create_order( '1234567890' );

		$result = $this->service->dispatch( '1234567890', $order->get_id(), $this->operator_id );

		$this->assertIsArray( $result );
		$this->assertSame( '1234567890', $result['dispatch']['barcode'] );
		$this->assertSame( $order->get_id(), $result['dispatch']['order_id'] );
		$this->assertSame( $this->operator_id, $result['dispatch']['user_id'] );
		$this->assertGreaterThan( 0, $result['dispatch']['id'] );
		$this->assertSame( $order->get_id(), $result['order']->get_id() );

		$this->assertSame( $result['dispatch'], $this->repository->find_by_barcode( '1234567890' ) );
	}

	public function test_dispatch_records_the_operator(): void {
		$order = $this->create_order( '1234567890' );

		$this->service->dispatch( '1234567890', $order->get_id(), $this->operator_id );

		$this->assertSame(
			$this->operator_id,
			$this->repository->find_by_barcode( '1234567890' )['user_id']
		);
	}

	public function test_dispatch_records_a_utc_timestamp(): void {
		$order = $this->create_order( '1234567890' );
		$before = time();

		$result = $this->service->dispatch( '1234567890', $order->get_id(), $this->operator_id );
		$after = time();

		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
			$result['dispatch']['dispatched_at']
		);
		$timestamp = strtotime( $result['dispatch']['dispatched_at'] . ' UTC' );
		$this->assertGreaterThanOrEqual( $before - 2, $timestamp );
		$this->assertLessThanOrEqual( $after + 2, $timestamp );
	}

	public function test_dispatch_adds_a_private_order_note(): void {
		$order = $this->create_order( '1234567890' );

		$this->service->dispatch( '1234567890', $order->get_id(), $this->operator_id );

		$notes = $this->dispatch_notes( $order->get_id(), '1234567890' );
		$this->assertCount( 1, $notes );
		$this->assertFalse( (bool) $notes[0]->customer_note );
		$this->assertStringContainsString( '1234567890', $notes[0]->content );
		$this->assertStringContainsString( 'Warehouse Alice', $notes[0]->content );
	}

	public function test_dispatch_does_not_modify_the_order_barcode_metadata(): void {
		$order = $this->create_order( '0012345678' );

		$this->service->dispatch( '0012345678', $order->get_id(), $this->operator_id );

		$this->assertSame( '0012345678', wc_get_order( $order->get_id() )->get_meta( 'post_barcode', true ) );
	}

	public function test_dispatch_keeps_the_order_status_unchanged_and_saves_separately(): void {
		$order  = $this->create_order( '1234567890', 'processing' );
		$before = $order->get_status();

		$result = $this->service->dispatch( '1234567890', $order->get_id(), $this->operator_id );

		$this->assertIsArray( $result );
		$this->assertSame( $before, wc_get_order( $order->get_id() )->get_status() );
		$this->assertNotNull( $this->repository->find_by_barcode( '1234567890' ) );
		$this->assertCount( 1, $this->dispatch_notes( $order->get_id(), '1234567890' ) );
	}

	public function test_dispatch_rejects_an_unknown_barcode(): void {
		$result = $this->service->dispatch( '9999999999', 1, $this->operator_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_order_not_found', $result->get_error_code() );
		$this->assertNull( $this->repository->find_by_barcode( '9999999999' ) );
	}

	public function test_dispatch_rejects_an_order_with_an_ineligible_status(): void {
		$order = $this->create_order( '1234567890', 'cancelled' );

		$result = $this->service->dispatch( '1234567890', $order->get_id(), $this->operator_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_order_not_eligible', $result->get_error_code() );
		$this->assertNull( $this->repository->find_by_barcode( '1234567890' ) );
		$this->assertCount( 0, $this->dispatch_notes( $order->get_id(), '1234567890' ) );
	}

	public function test_dispatch_refuses_when_multiple_orders_match(): void {
		$first  = $this->create_order( '1234567890' );
		$second = $this->create_order( '1234567890' );

		$result = $this->service->dispatch( '1234567890', $first->get_id(), $this->operator_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_multiple_orders', $result->get_error_code() );
		$this->assertNull( $this->repository->find_by_barcode( '1234567890' ) );
		$this->assertCount( 0, $this->dispatch_notes( $first->get_id(), '1234567890' ) );
		$this->assertCount( 0, $this->dispatch_notes( $second->get_id(), '1234567890' ) );
	}

	public function test_dispatch_rejects_a_second_dispatch_of_the_same_barcode(): void {
		$order = $this->create_order( '1234567890' );
		$this->service->dispatch( '1234567890', $order->get_id(), $this->operator_id );

		$result = $this->service->dispatch( '1234567890', $order->get_id(), $this->operator_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_already_dispatched', $result->get_error_code() );
		$this->assertSame( '1234567890', $result->get_error_data()['dispatch']['barcode'] );
		$this->assertCount( 1, $this->repository->history_for_order( $order->get_id() ) );
		$this->assertCount( 1, $this->dispatch_notes( $order->get_id(), '1234567890' ) );
	}

	public function test_dispatch_ignores_an_order_id_that_does_not_match_the_barcode(): void {
		$order = $this->create_order( '1234567890' );
		$other = $this->create_order( '5555555555' );

		$result = $this->service->dispatch( '1234567890', $other->get_id(), $this->operator_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_order_mismatch', $result->get_error_code() );
		$this->assertNull( $this->repository->find_by_barcode( '1234567890' ) );
	}

	public function test_dispatch_reports_failure_when_the_insert_loses_a_race(): void {
		$order = $this->create_order( '1234567890' );
		$this->repository->create( '1234567890', 9999, 3, '2026-10-30 10:00:00' );

		$racing_repository = new class() extends DispatchRepository {
			public function exists( string $barcode ): bool {
				// Simulate a concurrent request that inserted after the pre-check.
				return false;
			}
		};

		$service = new DispatchService(
			new BarcodeNormalizer(),
			new BarcodeOrderFinder(),
			new DispatchValidator( new OrderEligibilityPolicy(), $racing_repository ),
			$racing_repository
		);

		$result = $service->dispatch( '1234567890', $order->get_id(), $this->operator_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_already_dispatched', $result->get_error_code() );
		$this->assertSame( 3, $result->get_error_data()['dispatch']['user_id'] );
		$this->assertCount( 0, $this->dispatch_notes( $order->get_id(), '1234567890' ) );
		$this->assertCount( 1, $this->repository->history_for_order( 9999 ) );
	}

	public function test_dispatch_reports_a_database_error_without_success(): void {
		$order = $this->create_order( '1234567890' );

		$failing_repository = new class() extends DispatchRepository {
			public function create( string $barcode, int $order_id, int $user_id, string $dispatched_at ): int {
				return 0;
			}
		};

		$service = new DispatchService(
			new BarcodeNormalizer(),
			new BarcodeOrderFinder(),
			new DispatchValidator( new OrderEligibilityPolicy(), $failing_repository ),
			$failing_repository
		);

		$result = $service->dispatch( '1234567890', $order->get_id(), $this->operator_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_dispatch_failed', $result->get_error_code() );
		$this->assertCount( 0, $this->dispatch_notes( $order->get_id(), '1234567890' ) );
	}
}
