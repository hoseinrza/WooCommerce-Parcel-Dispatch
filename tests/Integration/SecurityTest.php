<?php

namespace WCPD\Tests\Integration;

use WP_REST_Request;

/**
 * Adversarial coverage for the REST surface: CSRF, authorization, injection,
 * type confusion, data exposure, and method restrictions.
 */
class SecurityTest extends RestTestCase {

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

	private function request( string $method, string $path, array $params = array(), ?string $nonce = null ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( null !== $nonce ) {
			$request->set_header( 'X-WP-Nonce', $nonce );
		}

		return rest_do_request( $request );
	}

	public function test_nonce_minted_for_another_action_is_rejected(): void {
		$this->as_operator();
		$this->create_order( '1234567890' );

		$response = $this->request(
			'POST',
			'/wcpd/v1/barcode/lookup',
			array( 'barcode' => '1234567890' ),
			wp_create_nonce( 'wcpd_unrelated_action' )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpd_invalid_nonce', $response->get_data()['code'] );
	}

	public function test_nonce_minted_for_another_user_is_rejected(): void {
		$this->as_operator();
		$this->create_order( '1234567890' );
		$foreign_nonce = wp_create_nonce( 'wp_rest' );

		wp_set_current_user( $this->create_user_with_role( 'shop_manager' ) );

		$response = $this->request(
			'POST',
			'/wcpd/v1/barcode/lookup',
			array( 'barcode' => '1234567890' ),
			$foreign_nonce
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpd_invalid_nonce', $response->get_data()['code'] );
	}

	public function test_subscriber_with_a_valid_nonce_is_forbidden_everywhere(): void {
		$this->as_operator();
		$this->create_order( '1234567890' );
		$this->as_operator( $this->create_user_with_role( 'subscriber' ) );

		$lookup = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );
		$dispatch = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array( 'barcode' => '1234567890', 'order_id' => 1 )
		);
		$history = $this->api( 'GET', '/wcpd/v1/dispatches' );

		$this->assertSame( 403, $lookup->get_status() );
		$this->assertSame( 403, $dispatch->get_status() );
		$this->assertSame( 403, $history->get_status() );
		$this->assertNull( $this->repository->find_by_barcode( '1234567890' ) );
	}

	public function test_anonymous_lookup_does_not_leak_order_data(): void {
		$this->create_order( '1234567890' );
		wp_set_current_user( 0 );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertArrayNotHasKey( 'order', $response->get_data() );
		$this->assertArrayNotHasKey( 'customer', $response->get_data() );
	}

	public function test_csrf_failure_does_not_write_a_dispatch_or_note(): void {
		$this->as_operator();
		$order = $this->create_order( '1234567890' );

		$response = $this->request(
			'POST',
			'/wcpd/v1/dispatch',
			array( 'barcode' => '1234567890', 'order_id' => $order->get_id() ),
			wp_create_nonce( 'wcpd_unrelated_action' )
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertNull( $this->repository->find_by_barcode( '1234567890' ) );
		$this->assertCount( 0, $this->dispatch_notes( $order->get_id(), '1234567890' ) );
	}

	public function test_barcode_filter_rejects_sql_injection_payloads(): void {
		$this->as_operator();
		$this->repository->create( 'ABC123', 42, $this->operator_id, '2026-10-31 08:00:00' );

		foreach ( array( "' OR 1=1 -- ", '" OR "1"="1', 'ABC%', '%', '_' ) as $payload ) {
			$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( 'barcode' => $payload ) );

			$this->assertSame( 200, $response->get_status(), $payload );
			$this->assertSame( 0, $response->get_data()['total'], $payload );
		}

		$this->assertNotNull( $this->repository->find_by_barcode( 'ABC123' ) );
		$this->assertCount( 1, $this->api( 'GET', '/wcpd/v1/dispatches' )->get_data()['items'] );
	}

	public function test_numeric_filters_reject_injection_and_non_numeric_values(): void {
		$this->as_operator();

		foreach ( array( 'order_id' => '1 OR 1=1', 'operator' => '1; DROP TABLE wp_wcpd_dispatches' ) as $key => $payload ) {
			$response = $this->api( 'GET', '/wcpd/v1/dispatches', array( $key => $payload ) );

			$this->assertSame( 400, $response->get_status(), $key );
		}

		$this->assertSame( 400, $this->api( 'GET', '/wcpd/v1/dispatches', array( 'page' => '1; DROP TABLE x' ) )->get_status() );
		$this->assertSame( 400, $this->api( 'GET', '/wcpd/v1/dispatches', array( 'per_page' => -5 ) )->get_status() );
	}

	public function test_lookup_treats_injection_payloads_as_literal_barcodes(): void {
		$this->as_operator();
		$this->create_order( 'SAFE123' );

		foreach ( array( "1'OR'1'='1", "1'OR'1'='1'--", '1;DROP', '1/*x*/OR/*x*/1=1' ) as $payload ) {
			$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => $payload ) );

			$this->assertSame( 404, $response->get_status(), $payload );
			$this->assertSame( 'wcpd_order_not_found', $response->get_data()['code'], $payload );
		}

		$this->assertSame( 'SAFE123', $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => 'SAFE123' ) )->get_data()['order']['barcode'] );
		$this->assertSame( 0, $this->api( 'GET', '/wcpd/v1/dispatches' )->get_data()['total'] );
	}

	public function test_barcode_matching_is_case_sensitive(): void {
		$this->as_operator();
		$this->create_order( 'AbC123' );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => 'abc123' ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_oversized_and_control_character_barcodes_are_rejected(): void {
		$this->as_operator();

		$too_long = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => str_repeat( '9', 101 ) ) );
		$control = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => "123\n456" ) );

		$this->assertSame( 400, $too_long->get_status() );
		$this->assertSame( 'wcpd_invalid_barcode', $too_long->get_data()['code'] );
		$this->assertSame( 400, $control->get_status() );
		$this->assertSame( 'wcpd_invalid_barcode', $control->get_data()['code'] );
	}

	public function test_required_parameters_reject_type_confusion(): void {
		$this->as_operator();
		$this->create_order( '1234567890' );

		$barcode_array = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => array( '1234567890' ) ) );
		$order_id_array = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array( 'barcode' => '1234567890', 'order_id' => array( 1 ) )
		);

		$this->assertSame( 400, $barcode_array->get_status() );
		$this->assertSame( 400, $order_id_array->get_status() );
		$this->assertNull( $this->repository->find_by_barcode( '1234567890' ) );
	}

	public function test_rest_endpoints_reject_wrong_http_methods(): void {
		$this->as_operator();

		$get_dispatch = $this->request( 'GET', '/wcpd/v1/dispatch', array(), wp_create_nonce( 'wp_rest' ) );
		$post_history = $this->request( 'POST', '/wcpd/v1/dispatches', array(), wp_create_nonce( 'wp_rest' ) );
		$get_returns  = $this->request( 'GET', '/wcpd/v1/returns', array(), wp_create_nonce( 'wp_rest' ) );

		$this->assertContains( $get_dispatch->get_status(), array( 404, 405 ) );
		$this->assertContains( $post_history->get_status(), array( 404, 405 ) );
		$this->assertContains( $get_returns->get_status(), array( 404, 405 ) );
	}

	public function test_subscriber_cannot_return_even_with_a_valid_nonce(): void {
		$order    = $this->create_order( '1234567890' );
		$this->as_operator();
		$dispatch = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => $order->get_id(),
			)
		)->get_data()['dispatch'];

		$this->as_operator( $this->create_user_with_role( 'subscriber' ) );
		$response = $this->api( 'POST', '/wcpd/v1/returns', array( 'dispatch_id' => $dispatch['id'] ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpd_forbidden', $response->get_data()['code'] );
		$this->assertNotNull( $this->repository->find_by_barcode( '1234567890' ) );
		$this->assertCount(
			0,
			array_filter(
				$this->dispatch_notes( $order->get_id(), '1234567890' ),
				static fn( $note ): bool => str_contains( $note->content, 'به انبار بازگشت' )
			)
		);
	}

	public function test_csrf_failure_does_not_mark_a_dispatch_returned(): void {
		$order    = $this->create_order( '1234567890' );
		$this->as_operator();
		$dispatch = $this->api(
			'POST',
			'/wcpd/v1/dispatch',
			array(
				'barcode'  => '1234567890',
				'order_id' => $order->get_id(),
			)
		)->get_data()['dispatch'];

		$response = $this->request(
			'POST',
			'/wcpd/v1/returns',
			array( 'dispatch_id' => $dispatch['id'] ),
			wp_create_nonce( 'wcpd_unrelated_action' )
		);

		$this->assertSame( 403, $response->get_status() );
		$active = $this->repository->find_by_barcode( '1234567890' );
		$this->assertNotNull( $active );
		$this->assertNull( $active['returned_at'] );
		$this->assertSame(
			0,
			count(
				array_filter(
					$this->dispatch_notes( $order->get_id(), '1234567890' ),
					static fn( $note ): bool => str_contains( $note->content, 'به انبار بازگشت' )
				)
			)
		);
	}
}
