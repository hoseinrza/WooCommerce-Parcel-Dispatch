<?php
/**
 * Order status eligibility rules for parcel dispatch.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Dispatch;

use WC_Order;

final class OrderEligibilityPolicy {

	public const DEFAULT_ALLOWED_STATUSES = array( 'processing', 'completed' );

	/**
	 * Filter the order statuses that may be dispatched.
	 *
	 * Usage:
	 *
	 *     add_filter(
	 *         'wcpd_dispatch_allowed_order_statuses',
	 *         function ( array $statuses ) {
	 *             $statuses[] = 'on-hold';
	 *             return $statuses;
	 *         }
	 *     );
	 *
	 * Statuses may be given with or without the `wc-` prefix.
	 */
	public const ALLOWED_STATUSES_FILTER = 'wcpd_dispatch_allowed_order_statuses';

	/**
	 * @return string[] Normalized status slugs without the `wc-` prefix.
	 */
	public function allowed_statuses(): array {
		$statuses = apply_filters( self::ALLOWED_STATUSES_FILTER, self::DEFAULT_ALLOWED_STATUSES );

		if ( ! is_array( $statuses ) ) {
			$statuses = self::DEFAULT_ALLOWED_STATUSES;
		}

		$normalized = array_map(
			static fn( $status ): string => str_replace( 'wc-', '', (string) $status ),
			$statuses
		);

		return array_values( array_unique( array_filter( $normalized ) ) );
	}

	public function is_eligible( WC_Order $order ): bool {
		return in_array( $order->get_status(), $this->allowed_statuses(), true );
	}
}
