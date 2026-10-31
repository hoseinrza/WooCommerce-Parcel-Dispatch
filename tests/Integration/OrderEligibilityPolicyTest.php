<?php

namespace WCPD\Tests\Integration;

use WCPD\Dispatch\OrderEligibilityPolicy;

class OrderEligibilityPolicyTest extends IntegrationTestCase {

	private OrderEligibilityPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new OrderEligibilityPolicy();
	}

	private function order_with_status( string $status ): \WC_Order {
		return $this->create_order( '1234567890', $status );
	}

	public function test_processing_orders_are_eligible(): void {
		$this->assertTrue( $this->policy->is_eligible( $this->order_with_status( 'processing' ) ) );
	}

	public function test_completed_orders_are_eligible(): void {
		$this->assertTrue( $this->policy->is_eligible( $this->order_with_status( 'completed' ) ) );
	}

	/**
	 * @dataProvider ineligible_statuses
	 */
	public function test_ineligible_statuses_are_rejected( string $status ): void {
		$this->assertFalse( $this->policy->is_eligible( $this->order_with_status( $status ) ) );
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

	public function test_default_allowed_statuses_are_processing_and_completed(): void {
		$this->assertSame( array( 'processing', 'completed' ), $this->policy->allowed_statuses() );
	}

	public function test_allowed_statuses_are_configurable_by_filter(): void {
		add_filter(
			'wcpd_dispatch_allowed_order_statuses',
			static fn(): array => array( 'completed' )
		);

		$this->assertSame( array( 'completed' ), $this->policy->allowed_statuses() );
		$this->assertFalse( $this->policy->is_eligible( $this->order_with_status( 'processing' ) ) );
		$this->assertTrue( $this->policy->is_eligible( $this->order_with_status( 'completed' ) ) );
	}

	public function test_filter_can_allow_additional_statuses(): void {
		add_filter(
			'wcpd_dispatch_allowed_order_statuses',
			static fn( array $statuses ): array => array_merge( $statuses, array( 'on-hold' ) )
		);

		$this->assertTrue( $this->policy->is_eligible( $this->order_with_status( 'on-hold' ) ) );
	}

	public function test_filter_values_may_use_the_wc_prefix(): void {
		add_filter(
			'wcpd_dispatch_allowed_order_statuses',
			static fn(): array => array( 'wc-on-hold', 'wc-processing' )
		);

		$this->assertSame( array( 'on-hold', 'processing' ), $this->policy->allowed_statuses() );
		$this->assertTrue( $this->policy->is_eligible( $this->order_with_status( 'on-hold' ) ) );
	}

	public function test_duplicate_filter_values_are_removed(): void {
		add_filter(
			'wcpd_dispatch_allowed_order_statuses',
			static fn(): array => array( 'processing', 'processing', 'wc-processing' )
		);

		$this->assertSame( array( 'processing' ), $this->policy->allowed_statuses() );
	}
}
