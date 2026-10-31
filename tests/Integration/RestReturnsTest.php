<?php

namespace WCPD\Tests\Integration;

class RestReturnsTest extends RestTestCase {

	private function dispatch(): array {
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

		return array( $order, $response->get_data()['dispatch'] );
	}

	private function return_notes( int $order_id ): array {
		return array_values(
			array_filter(
				wc_get_order_notes(
					array(
						'order_id' => $order_id,
						'type'     => 'internal',
					)
				),
				static fn( $note ): bool => str_contains( $note->content, 'به انبار بازگشت' )
			)
		);
	}

	public function test_return_requires_authentication(): void {
		wp_set_current_user( 0 );

		$response = $this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => 1 ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_return_rejects_a_missing_nonce(): void {
		$this->as_operator();

		$response = $this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => 1 ), false );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpd_invalid_nonce', $response->get_data()['code'] );
	}

	public function test_user_without_capability_cannot_return(): void {
		list( $order, $dispatch ) = $this->dispatch();

		$this->as_operator( $this->create_user_with_role( 'subscriber' ) );
		$response = $this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => $dispatch['id'] ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpd_forbidden', $response->get_data()['code'] );
		$this->assertNotNull( $this->repository->find_by_barcode( '1234567890' ) );
		$this->assertCount( 0, $this->return_notes( $order->get_id() ) );
	}

	public function test_operator_marks_a_dispatch_as_returned(): void {
		list( $order, $dispatch ) = $this->dispatch();

		$response = $this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => $dispatch['id'] ) );

		$this->assertSame( 200, $response->get_status() );

		$returned = $response->get_data()['dispatch'];
		$this->assertSame( $dispatch['id'], $returned['id'] );
		$this->assertSame( '1234567890', $returned['barcode'] );
		$this->assertNotNull( $returned['returned_at'] );
		$this->assertSame( $this->operator_id, $returned['returned_by'] );
		$this->assertSame( $this->operator_id, $returned['operator']['id'] );

		$this->assertNull( $this->repository->find_by_barcode( '1234567890' ) );
		$this->assertCount( 1, $this->return_notes( $order->get_id() ) );
	}

	public function test_return_of_an_unknown_dispatch_is_not_found(): void {
		$this->as_operator();

		$response = $this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => 999999 ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'wcpd_dispatch_not_found', $response->get_data()['code'] );
	}

	public function test_returning_twice_is_rejected(): void {
		list( $order, $dispatch ) = $this->dispatch();
		$this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => $dispatch['id'] ) );

		$response = $this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => $dispatch['id'] ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'wcpd_already_returned', $response->get_data()['code'] );
		$this->assertCount( 1, $this->return_notes( $order->get_id() ) );
	}

	public function test_dispatch_id_rejects_type_confusion(): void {
		$this->as_operator();

		$response = $this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => array( 1 ) ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_dispatch_id_is_required(): void {
		$this->as_operator();

		$response = $this->api( 'POST', '/wcpd/v1/returns' );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_the_parcel_can_be_dispatched_again_after_a_return(): void {
		list( $order, $dispatch ) = $this->dispatch();
		$this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => $dispatch['id'] ) );

		$second = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => $order->get_id(),
			)
		);

		$this->assertSame( 201, $second->get_status() );
		$this->assertNotSame( $dispatch['id'], $second->get_data()['dispatch']['id'] );
		$this->assertNull( $second->get_data()['dispatch']['returned_at'] );
	}

	public function test_history_exposes_the_return_state(): void {
		list( $order, $dispatch ) = $this->dispatch();
		$this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => $dispatch['id'] ) );
		$this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => $order->get_id(),
			)
		);

		$items = $this->api( 'GET', '/wcpd/v1/dispatches' )->get_data()['items'];

		$this->assertCount( 2, $items );
		$this->assertSame( 2, $this->api( 'GET', '/wcpd/v1/dispatches' )->get_data()['total'] );

		$by_id = array_column( $items, null, 'id' );
		$this->assertNotNull( $by_id[ $dispatch['id'] ]['returned_at'] );
		$this->assertSame( $this->operator_id, $by_id[ $dispatch['id'] ]['returned_by'] );
		$this->assertNull( array_values( array_diff_key( $by_id, array( $dispatch['id'] => true ) ) )[0]['returned_at'] );
	}

	public function test_history_filter_by_barcode_includes_returned_rows(): void {
		list( $order, $dispatch ) = $this->dispatch();
		$this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => $dispatch['id'] ) );

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 1, $response->get_data()['total'] );
		$this->assertNotNull( $response->get_data()['items'][0]['returned_at'] );
	}
}
