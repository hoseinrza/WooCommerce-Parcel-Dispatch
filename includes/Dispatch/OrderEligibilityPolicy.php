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
	 * Registered statuses with these labels are treated as "packed" and
	 * allowed by default, regardless of their slug.
	 */
	private const PACKED_STATUS_LABELS = array( 'بسته بندی شده', 'packed' );

	/**
	 * Known status slugs that are allowed even when their label changes.
	 */
	private const KNOWN_STATUS_SLUGS = array( 'pws-packaged', 'pws-ready-to-ship', 'pws-shipping' );

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
		$statuses = apply_filters( self::ALLOWED_STATUSES_FILTER, $this->default_allowed_statuses() );

		if ( ! is_array( $statuses ) ) {
			$statuses = $this->default_allowed_statuses();
		}

		$normalized = array_map(
			static fn( $status ): string => str_replace( 'wc-', '', (string) $status ),
			$statuses
		);

		return array_values( array_unique( array_filter( $normalized ) ) );
	}

	/**
	 * @return string[] Default statuses plus any registered known status.
	 */
	private function default_allowed_statuses(): array {
		$statuses = self::DEFAULT_ALLOWED_STATUSES;

		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$normalized_slug = str_replace( 'wc-', '', (string) $slug );

			if ( $this->is_packed_label( (string) $label ) || in_array( $normalized_slug, self::KNOWN_STATUS_SLUGS, true ) ) {
				$statuses[] = (string) $slug;
			}
		}

		return $statuses;
	}

	private function is_packed_label( string $label ): bool {
		$normalized = $this->normalize_label( $label );

		foreach ( self::PACKED_STATUS_LABELS as $packed_label ) {
			if ( $normalized === $this->normalize_label( $packed_label ) ) {
				return true;
			}
		}

		return false;
	}

	private function normalize_label( string $label ): string {
		$label = str_replace( array( "\xE2\x80\x8C", "\xC2\xA0" ), ' ', $label );
		$label = preg_replace( '/\s+/u', ' ', $label );

		return strtolower( trim( (string) $label ) );
	}

	public function is_eligible( WC_Order $order ): bool {
		return in_array( $order->get_status(), $this->allowed_statuses(), true );
	}
}
