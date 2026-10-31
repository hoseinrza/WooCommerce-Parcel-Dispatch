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

	private function register_status( string $slug, string $label ): void {
		add_filter(
			'wc_order_statuses',
			static function ( array $statuses ) use ( $slug, $label ): array {
				$statuses[ 'wc-' . $slug ] = $label;

				return $statuses;
			}
		);
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

	public function test_registered_packed_status_is_eligible_by_default(): void {
		$this->register_status( 'packed', 'بسته بندی شده' );

		$order = $this->order_with_status( 'packed' );

		$this->assertContains( 'packed', $this->policy->allowed_statuses() );
		$this->assertTrue( $this->policy->is_eligible( $order ) );
	}

	public function test_packed_label_with_zwnj_is_detected(): void {
		$this->register_status( 'packed-order', 'بسته‌بندی شده' );

		$order = $this->order_with_status( 'packed-order' );

		$this->assertContains( 'packed-order', $this->policy->allowed_statuses() );
		$this->assertTrue( $this->policy->is_eligible( $order ) );
	}

	public function test_english_packed_label_is_detected_case_insensitively(): void {
		$this->register_status( 'packed', 'Packed' );

		$this->assertTrue( $this->policy->is_eligible( $this->order_with_status( 'packed' ) ) );
	}

	public function test_known_pws_packaged_slug_is_allowed_with_a_different_label(): void {
		$this->register_status( 'pws-packaged', 'آماده خروج' );

		$order = $this->order_with_status( 'pws-packaged' );

		$this->assertContains( 'pws-packaged', $this->policy->allowed_statuses() );
		$this->assertTrue( $this->policy->is_eligible( $order ) );
	}

	public function test_known_pws_ready_to_ship_slug_is_allowed_with_a_different_label(): void {
		$this->register_status( 'pws-ready-to-ship', 'آماده ارسال' );

		$order = $this->order_with_status( 'pws-ready-to-ship' );

		$this->assertContains( 'pws-ready-to-ship', $this->policy->allowed_statuses() );
		$this->assertTrue( $this->policy->is_eligible( $order ) );
	}

	public function test_known_pws_shipping_slug_is_allowed_with_a_different_label(): void {
		$this->register_status( 'pws-shipping', 'در حال ارسال' );

		$order = $this->order_with_status( 'pws-shipping' );

		$this->assertContains( 'pws-shipping', $this->policy->allowed_statuses() );
		$this->assertTrue( $this->policy->is_eligible( $order ) );
	}

	public function test_packed_detection_ignores_other_custom_statuses(): void {
		$this->register_status( 'packing', 'در حال بسته بندی' );

		$order = $this->order_with_status( 'packing' );

		$this->assertNotContains( 'packing', $this->policy->allowed_statuses() );
		$this->assertFalse( $this->policy->is_eligible( $order ) );
	}

	public function test_filter_can_still_remove_the_detected_packed_status(): void {
		$this->register_status( 'packed', 'بسته بندی شده' );

		add_filter(
			'wcpd_dispatch_allowed_order_statuses',
			static fn(): array => array( 'completed' )
		);

		$this->assertFalse( $this->policy->is_eligible( $this->order_with_status( 'packed' ) ) );
	}
}
