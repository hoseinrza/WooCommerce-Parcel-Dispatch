<?php

namespace WCPD\Tests\Integration;

class RestAuthTest extends RestTestCase {

	public function test_lookup_requires_authentication(): void {
		wp_set_current_user( 0 );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_dispatch_requires_authentication(): void {
		wp_set_current_user( 0 );

		$response = $this->api( 'POST', '/wcpd/v1/dispatch', array( 'barcode' => '1234567890', 'order_id' => 1 ) );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_history_requires_authentication(): void {
		wp_set_current_user( 0 );

		$response = $this->api( 'GET', '/wcpd/v1/dispatches' );

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_user_without_capability_is_forbidden(): void {
		$this->as_operator( $this->create_user_with_role( 'subscriber' ) );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpd_forbidden', $response->get_data()['code'] );
	}

	public function test_missing_nonce_is_rejected(): void {
		$this->as_operator();

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ), false );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpd_invalid_nonce', $response->get_data()['code'] );
	}

	public function test_invalid_nonce_is_rejected(): void {
		$this->as_operator();

		$request = new \WP_REST_Request( 'POST', '/wcpd/v1/barcode/lookup' );
		$request->set_param( 'barcode', '1234567890' );
		$request->set_header( 'X-WP-Nonce', 'not-a-valid-nonce' );

		$response = rest_do_request( $request );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'wcpd_invalid_nonce', $response->get_data()['code'] );
	}

	public function test_shop_manager_with_nonce_can_lookup(): void {
		$this->as_operator();
		$this->create_order( '1234567890' );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_capability_is_configurable_by_filter(): void {
		add_filter(
			'wcpd_dispatch_capability',
			static fn(): string => 'read'
		);

		$this->as_operator( $this->create_user_with_role( 'subscriber' ) );
		$this->create_order( '1234567890' );

		$response = $this->api( 'POST', '/wcpd/v1/barcode/lookup', array( 'barcode' => '1234567890' ) );

		$this->assertSame( 200, $response->get_status() );
	}
}
