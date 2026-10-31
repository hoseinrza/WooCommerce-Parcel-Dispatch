<?php

namespace WCPD\Tests\Integration;

use WCPD\Barcode\BarcodeOrderFinder;

class BarcodeOrderFinderTest extends IntegrationTestCase {

	private BarcodeOrderFinder $finder;

	protected function setUp(): void {
		parent::setUp();
		$this->finder = new BarcodeOrderFinder();
	}

	private function ids( array $orders ): array {
		return array_map( static fn( $order ) => $order->get_id(), $orders );
	}

	public function test_finds_order_by_post_barcode(): void {
		$order = $this->create_order( '12345678901234567890' );

		$found = $this->finder->find( '12345678901234567890' );

		$this->assertCount( 1, $found );
		$this->assertInstanceOf( \WC_Order::class, $found[0] );
		$this->assertSame( $order->get_id(), $found[0]->get_id() );
	}

	public function test_returns_empty_array_when_no_order_matches(): void {
		$this->create_order( '1111111111' );

		$this->assertSame( array(), $this->finder->find( '9999999999' ) );
	}

	public function test_matching_is_exact_and_not_a_prefix_match(): void {
		$this->create_order( '1234567890' );

		$this->assertSame( array(), $this->finder->find( '123456789' ) );
		$this->assertSame( array(), $this->finder->find( '12345678900' ) );
	}

	public function test_unrelated_orders_do_not_match(): void {
		$this->create_order( '2222222222' );

		$this->assertSame( array(), $this->finder->find( '2222222223' ) );
	}

	public function test_order_without_barcode_metadata_is_ignored(): void {
		$this->create_order( null );

		$this->assertSame( array(), $this->finder->find( '1234567890' ) );
	}

	public function test_same_value_under_another_meta_key_is_ignored(): void {
		$order = new \WC_Order();
		$order->set_status( 'processing' );
		$order->update_meta_data( '_post_barcode', '3333333333' );
		$order->update_meta_data( 'post_barcode_number', '3333333333' );
		$order->save();

		$this->assertSame( array(), $this->finder->find( '3333333333' ) );
	}

	public function test_returns_all_orders_when_multiple_match(): void {
		$first  = $this->create_order( '4444444444' );
		$second = $this->create_order( '4444444444' );

		$found = $this->finder->find( '4444444444' );

		$this->assertCount( 2, $found );
		$this->assertSame(
			array( $first->get_id(), $second->get_id() ),
			$this->ids( $found )
		);
	}

	public function test_leading_zeros_are_matched_exactly(): void {
		$order = $this->create_order( '0012345678' );

		$this->assertSame( array(), $this->finder->find( '12345678' ) );
		$found = $this->finder->find( '0012345678' );
		$this->assertSame( array( $order->get_id() ), $this->ids( $found ) );
	}

	public function test_orders_of_any_status_can_be_found(): void {
		$cancelled = $this->create_order( '5555555555', 'cancelled' );
		$failed    = $this->create_order( '6666666666', 'failed' );

		$this->assertSame( array( $cancelled->get_id() ), $this->ids( $this->finder->find( '5555555555' ) ) );
		$this->assertSame( array( $failed->get_id() ), $this->ids( $this->finder->find( '6666666666' ) ) );
	}

	public function test_empty_barcode_returns_no_orders(): void {
		$this->create_order( '1234567890' );

		$this->assertSame( array(), $this->finder->find( '' ) );
	}

	public function test_finds_orders_with_legacy_storage(): void {
		$this->set_hpos( false );
		$order = $this->create_order( '7777777777' );

		$this->assertFalse( $this->hpos_enabled() );
		$this->assertSame( array( $order->get_id() ), $this->ids( $this->finder->find( '7777777777' ) ) );
	}

	public function test_finds_orders_with_hpos_storage(): void {
		$this->set_hpos( true );
		$order = $this->create_order( '8888888888' );

		$this->assertTrue( $this->hpos_enabled() );
		$this->assertSame( array( $order->get_id() ), $this->ids( $this->finder->find( '8888888888' ) ) );
	}
}
