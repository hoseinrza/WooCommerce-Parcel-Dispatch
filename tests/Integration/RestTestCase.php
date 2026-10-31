<?php

namespace WCPD\Tests\Integration;

use WC_Product_Simple;
use WCPD\Barcode\BarcodeNormalizer;
use WCPD\Barcode\BarcodeOrderFinder;
use WCPD\Database\DispatchRepository;
use WCPD\Dispatch\DispatchService;
use WCPD\Dispatch\DispatchValidator;
use WCPD\Dispatch\OrderEligibilityPolicy;
use WCPD\Rest\DispatchController;
use WP_REST_Request;
use WP_REST_Response;

abstract class RestTestCase extends DatabaseTestCase {

	protected DispatchRepository $repository;
	protected DispatchService $service;
	protected DispatchController $controller;
	protected int $operator_id;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new DispatchRepository();
		$this->service    = new DispatchService(
			new BarcodeNormalizer(),
			new BarcodeOrderFinder(),
			new DispatchValidator( new OrderEligibilityPolicy(), $this->repository ),
			$this->repository
		);
		$this->controller = new DispatchController( $this->service );

		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		rest_get_server();
		do_action( 'rest_api_init' );

		$this->operator_id = self::factory()->user->create(
			array(
				'role'         => 'shop_manager',
				'display_name' => 'Operator Alice',
			)
		);
	}

	protected function as_operator( int $user_id = null ): void {
		wp_set_current_user( $user_id ?? $this->operator_id );
	}

	protected function api( string $method, string $path, array $params = array(), bool $with_nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $path );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( $with_nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		return rest_do_request( $request );
	}

	protected function create_order_with_item( string $barcode, string $status = 'processing' ): \WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( 'Parcel Item' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = $this->create_order( $barcode, $status );
		$order->add_product( $product, 2 );
		$order->save();

		return $order;
	}

	protected function create_user_with_role( string $role ): int {
		return self::factory()->user->create( array( 'role' => $role ) );
	}
}
