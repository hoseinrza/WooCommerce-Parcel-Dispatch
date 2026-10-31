<?php

namespace WCPD\Tests\Integration;

class RestDispatchesTest extends RestTestCase {

	private function seed(): void {
		$this->repository->create( '111', 42, $this->operator_id, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 43, $this->operator_id, '2026-10-31 09:00:00' );
	}

	public function test_history_returns_paginated_records_newest_first(): void {
		$this->as_operator();
		$this->seed();

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'per_page' => 1 ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 1, $data['page'] );
		$this->assertSame( 1, $data['per_page'] );
		$this->assertSame( 2, $data['pages'] );
		$this->assertSame( '222', $data['items'][0]['barcode'] );
	}

	public function test_history_includes_operator_name(): void {
		$this->as_operator();
		$this->seed();

		$response = $this->api( 'GET', '/wcpd/v1/dispatches' );
		$first = $response->get_data()['items'][0];

		$this->assertSame( $this->operator_id, $first['operator']['id'] );
		$this->assertSame( 'Operator Alice', $first['operator']['name'] );
	}

	public function test_history_filters_by_barcode(): void {
		$this->as_operator();
		$this->seed();

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'barcode' => '111' ) );

		$this->assertSame( 1, $response->get_data()['total'] );
		$this->assertSame( '111', $response->get_data()['items'][0]['barcode'] );
	}

	public function test_history_normalizes_the_barcode_filter(): void {
		$this->as_operator();
		$this->repository->create( '12345', 42, $this->operator_id, '2026-10-31 08:00:00' );

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'barcode' => ' ۱۲۳۴۵ ' ) );

		$this->assertSame( 1, $response->get_data()['total'] );
	}

	public function test_history_with_an_unusable_barcode_filter_returns_no_rows(): void {
		$this->as_operator();
		$this->seed();

		foreach ( array( "' OR 1=1 -- ", '" OR "1"="1', "123\n456" ) as $payload ) {
			$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'barcode' => $payload ) );

			$this->assertSame( 200, $response->get_status(), $payload );
			$this->assertSame( 0, $response->get_data()['total'], $payload );
			$this->assertSame( array(), $response->get_data()['items'], $payload );
		}
	}

	public function test_history_filters_by_order_id(): void {
		$this->as_operator();
		$this->seed();

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'order_id' => 43 ) );

		$this->assertSame( 1, $response->get_data()['total'] );
		$this->assertSame( '222', $response->get_data()['items'][0]['barcode'] );
	}

	public function test_history_includes_the_order_edit_url(): void {
		$this->as_operator();
		$order = $this->create_order( '333' );
		$this->repository->create( '333', $order->get_id(), $this->operator_id, '2026-10-31 10:00:00' );

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'order_id' => $order->get_id() ) );
		$item     = $response->get_data()['items'][0];

		$this->assertSame( $order->get_edit_order_url(), $item['order_edit_url'] );
	}

	public function test_history_leaves_the_edit_url_empty_when_the_order_is_gone(): void {
		$this->as_operator();
		$this->repository->create( '444', 999999, $this->operator_id, '2026-10-31 10:00:00' );

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'order_id' => 999999 ) );

		$this->assertSame( '', $response->get_data()['items'][0]['order_edit_url'] );
	}

	public function test_history_filters_by_operator(): void {
		$this->as_operator();
		$other = $this->create_user_with_role( 'shop_manager' );
		$this->repository->create( '111', 42, $this->operator_id, '2026-10-31 08:00:00' );
		$this->repository->create( '222', 43, $other, '2026-10-31 09:00:00' );

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'operator' => $this->operator_id ) );

		$this->assertSame( 1, $response->get_data()['total'] );
		$this->assertSame( '111', $response->get_data()['items'][0]['barcode'] );
	}

	public function test_history_returns_empty_list(): void {
		$this->as_operator();

		$response = $this->api( 'GET', '/wcpd/v1/dispatches' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['total'] );
		$this->assertSame( array(), $response->get_data()['items'] );
	}

	public function test_history_rejects_invalid_pagination(): void {
		$this->as_operator();

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'per_page' => 500 ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_history_rejects_a_non_numeric_operator_filter(): void {
		$this->as_operator();

		$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'operator' => 'abc' ) );

		$this->assertSame( 400, $response->get_status() );
	}
}
