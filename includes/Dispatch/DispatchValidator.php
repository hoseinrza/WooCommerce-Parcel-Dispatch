<?php
/**
 * Validates whether an order may be dispatched.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Dispatch;

use WC_Order;
use WCPD\Database\DispatchRepository;
use WP_Error;

final class DispatchValidator {

	public function __construct(
		private OrderEligibilityPolicy $policy,
		private DispatchRepository $repository
	) {}

	/**
	 * @return true|WP_Error WP_Error carries the previous dispatch in its data
	 *                       for the `wcpd_already_dispatched` case.
	 */
	public function validate( WC_Order $order, string $barcode ): true|WP_Error {
		$existing = $this->repository->find_by_barcode( $barcode );

		if ( null !== $existing ) {
			return new WP_Error(
				'wcpd_already_dispatched',
				sprintf(
					/* translators: %s: postal barcode */
					__( 'بارکد %s قبلاً خروج خورده است.', 'woocommerce-parcel-dispatch' ),
					$barcode
				),
				array( 'dispatch' => $existing )
			);
		}

		$stored = $order->get_meta( 'post_barcode', true );

		if ( ! is_string( $stored ) || '' === $stored || $stored !== $barcode ) {
			return new WP_Error(
				'wcpd_barcode_mismatch',
				__( 'بارکد ثبت‌شده روی این سفارش با بارکد اسکن‌شده مطابقت ندارد.', 'woocommerce-parcel-dispatch' )
			);
		}

		if ( ! $this->policy->is_eligible( $order ) ) {
			return new WP_Error(
				'wcpd_order_not_eligible',
				sprintf(
					/* translators: 1: order status label, 2: comma separated list of allowed statuses */
					__( 'وضعیت سفارش «%1$s» اجازه خروج مرسوله را نمی‌دهد. وضعیت‌های مجاز: %2$s.', 'woocommerce-parcel-dispatch' ),
					wc_get_order_status_name( $order->get_status() ),
					$this->allowed_status_labels()
				),
				array( 'order_status' => $order->get_status() )
			);
		}

		return true;
	}

	private function allowed_status_labels(): string {
		$labels = array_map(
			static fn( string $status ): string => wc_get_order_status_name( $status ),
			$this->policy->allowed_statuses()
		);

		return implode( '، ', $labels );
	}
}
