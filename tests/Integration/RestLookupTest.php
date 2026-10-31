<?php

namespace WCPD\Tests\Integration;

class RestLookupTest extends RestTestCase {

	public function test_lookup_returns_order_details(): void {
		$this->as_operator();
		$order = $this->create_order_with_item( '1234567890' );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertSame( $order->get_id(), $data['order']['id'] );
		$this->assertSame( (string) $order->get_id(), (string) $data['order']['number'] );
		$this->assertSame( 'processing', $data['order']['status'] );
		$this->assertSame( '1234567890', $data['order']['barcode'] );
		$this->assertSame( 'Test Customer', $data['order']['customer']['name'] );
		$this->assertSame( '09121234567', $data['order']['customer']['phone'] );
		$this->assertStringContainsString( 'Valiasr St, No. 12', $data['order']['shipping_address'] );
		$this->assertSame( 'Parcel Item', $data['order']['items'][0]['name'] );
		$this->assertSame( 2, $data['order']['items'][0]['quantity'] );
	}

	public function test_lookup_accepts_persian_digits_and_whitespace(): void {
		$this->as_operator();
		$order = $this->create_order( '1234567890' );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => " ۱۲۳۴۵۶۷۸۹۰\r\n" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $order->get_id(), $response->get_data()['order']['id'] );
	}

	public function test_lookup_requires_a_barcode(): void {
		$this->as_operator();

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array() );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_lookup_rejects_a_non_scalar_barcode(): void {
		$this->as_operator();

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => array( '123' ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_lookup_rejects_an_unusable_barcode(): void {
		$this->as_operator();

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => "  \t " ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'wcpd_invalid_barcode', $response->get_data()['code'] );
	}

	public function test_lookup_returns_not_found_for_unknown_barcode(): void {
		$this->as_operator();

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '9999999999' ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpd_order_not_found', $response->get_data()['code'] );
	}

	public function test_lookup_reports_multiple_matching_orders(): void {
		$this->as_operator();
		$this->create_order( '1234567890' );
		$this->create_order( '1234567890' );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpd_multiple_orders', $response->get_data()['code'] );
		$this->assertCount( 2, $response->get_data()['data']['order_ids'] );
	}

	public function test_lookup_rejects_an_ineligible_order(): void {
		$this->as_operator();
		$this->create_order( '1234567890', 'cancelled' );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wcpd_order_not_eligible', $response->get_data()['code'] );
		$this->assertSame( 'cancelled', $response->get_data()['data']['order_status'] );
	}

	public function test_lookup_falls_back_to_shipping_customer_details(): void {
		$this->as_operator();

		$order = new \WC_Order();
		$order->set_shipping_first_name( 'Sara' );
		$order->set_shipping_last_name( 'Moradi' );
		$order->set_shipping_phone( '09350001122' );
		$order->set_status( 'processing' );
		$order->update_meta_data( 'post_barcode', 'SHIP-ONLY-1' );
		$order->save();

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => 'SHIP-ONLY-1' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Sara Moradi', $response->get_data()['order']['customer']['name'] );
		$this->assertSame( '09350001122', $response->get_data()['order']['customer']['phone'] );
	}

	public function test_lookup_returns_previous_dispatch_information(): void {
		$this->as_operator();
		$order = $this->create_order( '1234567890' );
		$this->repository->create( '1234567890', $order->get_id(), $this->operator_id, '2026-10-30 10:00:00' );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpd_already_dispatched', $response->get_data()['code'] );
		$this->assertSame(
			'2026-10-30 10:00:00',
			$response->get_data()['data']['dispatch']['dispatched_at']
		);
	}
}
