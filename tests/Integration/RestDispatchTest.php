<?php

namespace WCPD\Tests\Integration;

class RestDispatchTest extends RestTestCase {

	private function dispatch_notes( int $order_id, string $barcode ): array {
		$notes = wc_get_order_notes(
			array(
				'order_id' => $order_id,
				'type'     => 'internal',
			)
		);

		return array_values(
			array_filter(
				$notes,
				static fn( $note ): bool => str_contains( $note->content, $barcode )
			)
		);
	}

	public function test_dispatch_registers_the_parcel_and_returns_201(): void {
		$this->as_operator();
		$order = $this->create_order( '1234567890' );

		$response = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => $order->get_id(),
			)
		);

		$this->assertSame( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( '1234567890', $data['dispatch']['barcode'] );
		$this->assertSame( $order->get_id(), $data['dispatch']['order_id'] );
		$this->assertSame( $this->operator_id, $data['dispatch']['user_id'] );
		$this->assertSame( $order->get_id(), $data['order']['id'] );

		$this->assertSame( $data['dispatch'], $this->repository->find_by_barcode( '1234567890' ) );
		$this->assertCount( 1, $this->dispatch_notes( $order->get_id(), '1234567890' ) );
	}

	public function test_dispatch_normalizes_the_barcode(): void {
		$this->as_operator();
		$order = $this->create_order( '0012345678' );

		$response = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => " ۰۰۱۲۳۴۵۶۷۸ ",
				'order_id' => $order->get_id(),
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '0012345678', $response->get_data()['dispatch']['barcode'] );
	}

	public function test_dispatch_requires_a_barcode(): void {
		$this->as_operator();
		$order = $this->create_order( '1234567890' );

		$response = $this->api( 'POST', '/wcpd/v1/dispatch', array( 'order_id' => $order->get_id() ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_dispatch_requires_an_order_id(): void {
		$this->as_operator();
		$this->create_order( '1234567890' );

		$response = $this->api( 'POST', '/wcpd/v1/dispatch', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_dispatch_rejects_an_invalid_order_id(): void {
		$this->as_operator();
		$this->create_order( '1234567890' );

		$response = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => 0,
			)
		);

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_dispatch_does_not_trust_the_client_order_id(): void {
		$this->as_operator();
		$order = $this->create_order( '1234567890' );
		$other = $this->create_order( '5555555555' );

		$response = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => $other->get_id(),
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpd_order_mismatch', $response->get_data()['code'] );
		$this->assertNull( $this->repository->find_by_barcode( '1234567890' ) );
		$this->assertCount( 0, $this->dispatch_notes( $order->get_id(), '1234567890' ) );
	}

	public function test_dispatch_rejects_an_unknown_barcode(): void {
		$this->as_operator();

		$response = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '9999999999',
				'order_id' => 1,
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpd_order_not_found', $response->get_data()['code'] );
	}

	public function test_dispatch_rejects_an_ineligible_order(): void {
		$this->as_operator();
		$order = $this->create_order( '1234567890', 'on-hold' );

		$response = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => $order->get_id(),
			)
		);

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'wcpd_order_not_eligible', $response->get_data()['code'] );
	}

	public function test_dispatch_rejects_multiple_matching_orders(): void {
		$this->as_operator();
		$first = $this->create_order( '1234567890' );
		$this->create_order( '1234567890' );

		$response = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => $first->get_id(),
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpd_multiple_orders', $response->get_data()['code'] );
	}

	public function test_dispatch_rejects_a_duplicate_request(): void {
		$this->as_operator();
		$order = $this->create_order( '1234567890' );

		$payload = array(
			'barcode'  => '1234567890',
			'order_id' => $order->get_id(),
		);

		$this->api( 'POST', '/wcpd/v1/dispatch', $payload );
		$response = $this->api( 'POST', '/wcpd/v1/dispatch', $payload );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpd_already_dispatched', $response->get_data()['code'] );
		$this->assertSame( '1234567890', $response->get_data()['data']['dispatch']['barcode'] );
		$this->assertCount( 1, $this->repository->history_for_order( $order->get_id() ) );
		$this->assertCount( 1, $this->dispatch_notes( $order->get_id(), '1234567890' ) );
	}

	public function test_dispatch_records_the_authenticated_operator(): void {
		$second_operator = $this->create_user_with_role( 'shop_manager' );
		$this->as_operator( $second_operator );
		$order = $this->create_order( '1234567890' );

		$this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => $order->get_id(),
			)
		);

		$this->assertSame(
			$second_operator,
			$this->repository->find_by_barcode( '1234567890' )['user_id']
		);
	}
}
