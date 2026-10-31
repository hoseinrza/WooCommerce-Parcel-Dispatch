<?php
/**
 * REST API endpoints for barcode lookup, dispatch, and history.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Rest;

use WC_Order;
use WCPD\Dispatch\DispatchService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class DispatchController {

	public const REST_NAMESPACE = 'wcpd/v1';

	/**
	 * Filter the capability required to look up and dispatch parcels.
	 *
	 * Warehouse-specific roles can be granted a dedicated capability and wired
	 * in with:
	 *
	 *     add_filter( 'wcpd_dispatch_capability', fn() => 'scan_parcels' );
	 */
	public const CAPABILITY_FILTER = 'wcpd_dispatch_capability';

	private const ERROR_STATUSES = array(
		'wcpd_invalid_barcode'     => 400,
		'wcpd_order_not_found'     => 404,
		'wcpd_multiple_orders'     => 409,
		'wcpd_already_dispatched'  => 409,
		'wcpd_order_mismatch'      => 409,
		'wcpd_order_not_eligible'  => 422,
		'wcpd_barcode_mismatch'    => 422,
		'wcpd_dispatch_failed'     => 500,
		'wcpd_dispatch_not_found'  => 404,
		'wcpd_already_returned'    => 409,
		'wcpd_return_failed'       => 500,
	);

	public function __construct( private DispatchService $service ) {}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/barcode/lookup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'lookup' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'barcode' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static fn( $value ) => is_string( $value ) ? trim( $value ) : $value,
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/dispatch',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'dispatch' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'barcode'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static fn( $value ) => is_string( $value ) ? trim( $value ) : $value,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'order_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/returns',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'returns' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'dispatch_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/dispatches',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'barcode'  => array(
						'type'              => 'string',
						'sanitize_callback' => static fn( $value ) => is_string( $value ) ? trim( $value ) : $value,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'order_id' => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'operator' => array(
						'type'              => 'integer',
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * Authorizes the request: authenticated user, valid REST nonce, capability.
	 *
	 * @return true|WP_Error
	 */
	public function permission_check( WP_REST_Request $request ): true|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'wcpd_unauthorized',
				__( 'برای انجام این عملیات باید وارد شوید.', 'woocommerce-parcel-dispatch' ),
				array( 'status' => 401 )
			);
		}

		if ( ! $this->nonce_is_valid( $request ) ) {
			return new WP_Error(
				'wcpd_invalid_nonce',
				__( 'نشست شما منقضی شده است. صفحه را دوباره بارگذاری کنید.', 'woocommerce-parcel-dispatch' ),
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( $this->capability() ) ) {
			return new WP_Error(
				'wcpd_forbidden',
				__( 'شما اجازه خروج مرسوله را ندارید.', 'woocommerce-parcel-dispatch' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function lookup( WP_REST_Request $request ) {
		$order = $this->service->locate( (string) $request->get_param( 'barcode' ) );

		if ( $order instanceof WP_Error ) {
			return $this->error( $order );
		}

		return new WP_REST_Response( array( 'order' => $this->present_order( $order ) ), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function dispatch( WP_REST_Request $request ) {
		$result = $this->service->dispatch(
			(string) $request->get_param( 'barcode' ),
			(int) $request->get_param( 'order_id' ),
			get_current_user_id()
		);

		if ( $result instanceof WP_Error ) {
			return $this->error( $result );
		}

		return new WP_REST_Response(
			array(
				'dispatch' => $result['dispatch'],
				'order'    => $this->present_order( $result['order'] ),
			),
			201
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function returns( WP_REST_Request $request ) {
		$result = $this->service->return_to_warehouse(
			(int) $request->get_param( 'dispatch_id' ),
			get_current_user_id()
		);

		if ( $result instanceof WP_Error ) {
			return $this->error( $result );
		}

		return new WP_REST_Response( array( 'dispatch' => $this->present_dispatch( $result ) ), 200 );
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function history( WP_REST_Request $request ) {
		$filters = array_filter(
			array(
				'barcode'  => $request->get_param( 'barcode' ),
				'order_id' => $request->get_param( 'order_id' ),
				'user_id'  => $request->get_param( 'operator' ),
			),
			static fn( $value ): bool => null !== $value && '' !== $value
		);

		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = (int) ( $request->get_param( 'per_page' ) ?: 20 );

		$result = $this->service->history( $filters, $page, $per_page );

		return new WP_REST_Response(
			array(
				'items'    => array_map( array( $this, 'present_dispatch' ), $result['items'] ),
				'total'    => $result['total'],
				'page'     => $page,
				'per_page' => $per_page,
				'pages'    => (int) ceil( $result['total'] / $per_page ),
			),
			200
		);
	}

	private function nonce_is_valid( WP_REST_Request $request ): bool {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}

		return is_string( $nonce ) && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	private function capability(): string {
		return (string) apply_filters( self::CAPABILITY_FILTER, 'manage_woocommerce' );
	}

	private function error( WP_Error $error ): WP_Error {
		$code = $error->get_error_code();
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		// Never overwrite a status the service chose explicitly (e.g. 401/403).
		$data['status'] = $data['status'] ?? ( self::ERROR_STATUSES[ $code ] ?? 500 );

		return new WP_Error( $code, $error->get_error_message(), $data );
	}

	private function present_order( WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
			);
		}

		$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		if ( '' === $customer_name ) {
			$customer_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}

		$phone = $order->get_billing_phone();

		if ( '' === $phone ) {
			$phone = $order->get_shipping_phone();
		}

		return array(
			'id'               => $order->get_id(),
			'number'           => (string) $order->get_order_number(),
			'edit_url'         => $order->get_edit_order_url(),
			'status'           => $order->get_status(),
			'status_label'     => wc_get_order_status_name( $order->get_status() ),
			'barcode'          => (string) $order->get_meta( 'post_barcode', true ),
			'customer'         => array(
				'name'  => $customer_name,
				'phone' => $phone,
			),
			'shipping_address' => $this->format_address( $order ),
			'items'            => $items,
		);
	}

	private function format_address( WC_Order $order ): string {
		$shipping = array_filter(
			array(
				trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				$order->get_shipping_company(),
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
				$order->get_shipping_city(),
				$order->get_shipping_state(),
				$order->get_shipping_postcode(),
				$order->get_shipping_country(),
			),
			static fn( $line ): bool => '' !== (string) $line
		);

		if ( array() === $shipping ) {
			$shipping = array_filter(
				array(
					trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					$order->get_billing_company(),
					$order->get_billing_address_1(),
					$order->get_billing_address_2(),
					$order->get_billing_city(),
					$order->get_billing_state(),
					$order->get_billing_postcode(),
					$order->get_billing_country(),
				),
				static fn( $line ): bool => '' !== (string) $line
			);
		}

		return implode( "\n", $shipping );
	}

	private function present_dispatch( array $record ): array {
		$user = get_userdata( $record['user_id'] );

		$record['operator'] = array(
			'id'   => $record['user_id'],
			'name' => false !== $user
				? $user->display_name
				/* translators: %d: user ID */
				: sprintf( __( 'کاربر #%d', 'woocommerce-parcel-dispatch' ), $record['user_id'] ),
		);

		$order                    = wc_get_order( $record['order_id'] );
		$record['order_edit_url'] = $order instanceof WC_Order ? $order->get_edit_order_url() : '';

		return $record;
	}
}
