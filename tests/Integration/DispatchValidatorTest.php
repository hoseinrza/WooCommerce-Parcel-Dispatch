<?php

namespace WCPD\Tests\Integration;

use WCPD\Database\DispatchRepository;
use WCPD\Dispatch\DispatchValidator;
use WCPD\Dispatch\OrderEligibilityPolicy;

class DispatchValidatorTest extends DatabaseTestCase {

	private DispatchRepository $repository;
	private DispatchValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->repository = new DispatchRepository();
		$this->validator  = new DispatchValidator( new OrderEligibilityPolicy(), $this->repository );
	}

	public function test_valid_processing_order_passes(): void {
		$order = $this->create_order( '1234567890', 'processing' );

		$this->assertTrue( $this->validator->validate( $order, '1234567890' ) );
	}

	public function test_valid_completed_order_passes(): void {
		$order = $this->create_order( '1234567890', 'completed' );

		$this->assertTrue( $this->validator->validate( $order, '1234567890' ) );
	}

	/**
	 * @dataProvider ineligible_statuses
	 */
	public function test_ineligible_order_status_is_rejected( string $status ): void {
		$order = $this->create_order( '1234567890', $status );

		$result = $this->validator->validate( $order, '1234567890' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_order_not_eligible', $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['order_status'] );
		$this->assertNotSame( '', $result->get_error_message() );
	}

	public function ineligible_statuses(): array {
		return array(
			'pending'   => array( 'pending' ),
			'on-hold'   => array( 'on-hold' ),
			'cancelled' => array( 'cancelled' ),
			'failed'    => array( 'failed' ),
			'refunded'  => array( 'refunded' ),
		);
	}

	public function test_missing_barcode_metadata_is_rejected(): void {
		$order = $this->create_order( null, 'processing' );

		$result = $this->validator->validate( $order, '1234567890' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_barcode_mismatch', $result->get_error_code() );
	}

	public function test_empty_barcode_metadata_is_rejected(): void {
		$order = $this->create_order( '', 'processing' );

		$result = $this->validator->validate( $order, '1234567890' );

		$this->assertSame( 'wcpd_barcode_mismatch', $result->get_error_code() );
	}

	public function test_different_barcode_metadata_is_rejected(): void {
		$order = $this->create_order( '0000000000', 'processing' );

		$result = $this->validator->validate( $order, '1234567890' );

		$this->assertSame( 'wcpd_barcode_mismatch', $result->get_error_code() );
	}

	public function test_previously_dispatched_barcode_is_rejected_with_previous_dispatch(): void {
		$order = $this->create_order( '1234567890', 'processing' );
		$this->repository->create( '1234567890', $order->get_id(), 5, '2026-10-30 10:00:00' );

		$result = $this->validator->validate( $order, '1234567890' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'wcpd_already_dispatched', $result->get_error_code() );

		$previous = $result->get_error_data()['dispatch'];
		$this->assertSame( '1234567890', $previous['barcode'] );
		$this->assertSame( 5, $previous['user_id'] );
		$this->assertSame( '2026-10-30 10:00:00', $previous['dispatched_at'] );
	}

	public function test_already_dispatched_is_reported_before_status_eligibility(): void {
		$order = $this->create_order( '1234567890', 'cancelled' );
		$this->repository->create( '1234567890', $order->get_id(), 5, '2026-10-30 10:00:00' );

		$result = $this->validator->validate( $order, '1234567890' );

		$this->assertSame( 'wcpd_already_dispatched', $result->get_error_code() );
	}
}
