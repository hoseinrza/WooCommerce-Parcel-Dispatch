<?php
/**
 * Dispatch use cases: barcode lookup and dispatch registration.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Dispatch;

use WC_Order;
use WCPD\Barcode\BarcodeNormalizer;
use WCPD\Barcode\BarcodeOrderFinder;
use WCPD\Database\DispatchRepository;
use WP_Error;

final class DispatchService {

	public function __construct(
		private BarcodeNormalizer $normalizer,
		private BarcodeOrderFinder $finder,
		private DispatchValidator $validator,
		private DispatchRepository $repository
	) {}

	/**
	 * Resolves the single eligible order for a scanned barcode.
	 *
	 * @return WC_Order|WP_Error
	 */
	public function locate( string $raw_barcode ): WC_Order|WP_Error {
		$barcode = $this->normalizer->normalize( $raw_barcode );

		if ( '' === $barcode ) {
			return new WP_Error(
				'wcpd_invalid_barcode',
				__( 'بارکد واردشده معتبر نیست.', 'woocommerce-parcel-dispatch' )
			);
		}

		$orders = $this->finder->find( $barcode );

		if ( array() === $orders ) {
			return new WP_Error(
				'wcpd_order_not_found',
				sprintf(
					/* translators: %s: postal barcode */
					__( 'سفارشی با بارکد %s پیدا نشد.', 'woocommerce-parcel-dispatch' ),
					$barcode
				),
				array( 'barcode' => $barcode )
			);
		}

		if ( count( $orders ) > 1 ) {
			return new WP_Error(
				'wcpd_multiple_orders',
				sprintf(
					/* translators: %d: number of orders */
					__( 'برای این بارکد %d سفارش یافت شد؛ داده‌ها ناسازگارند و ثبت خروج انجام نشد.', 'woocommerce-parcel-dispatch' ),
					count( $orders )
				),
				array(
					'barcode'   => $barcode,
					'order_ids' => array_map( static fn( WC_Order $order ): int => $order->get_id(), $orders ),
				)
			);
		}

		$validation = $this->validator->validate( $orders[0], $barcode );

		return $validation instanceof WP_Error ? $validation : $orders[0];
	}

	/**
	 * Registers a dispatch for the order resolved from the barcode.
	 *
	 * The order ID supplied by the client is never trusted: it must match the
	 * order the barcode resolves to, and all validation is repeated here.
	 *
	 * @return array{dispatch: array, order: WC_Order}|WP_Error
	 */
	public function dispatch( string $raw_barcode, int $order_id, int $user_id ): array|WP_Error {
		$order = $this->locate( $raw_barcode );

		if ( $order instanceof WP_Error ) {
			return $order;
		}

		if ( $order->get_id() !== $order_id ) {
			return new WP_Error(
				'wcpd_order_mismatch',
				__( 'شناسه سفارش ارسال‌شده با سفارش این بارکد مطابقت ندارد.', 'woocommerce-parcel-dispatch' ),
				array( 'order_id' => $order->get_id() )
			);
		}

		$barcode       = $this->normalizer->normalize( $raw_barcode );
		$dispatched_at = current_time( 'mysql' );

		$id = $this->repository->create( $barcode, $order->get_id(), $user_id, $dispatched_at );

		if ( 0 === $id ) {
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

			return new WP_Error(
				'wcpd_dispatch_failed',
				__( 'ثبت خروج مرسوله ناموفق بود. دوباره تلاش کنید.', 'woocommerce-parcel-dispatch' )
			);
		}

		$dispatch = array(
			'id'            => $id,
			'barcode'       => $barcode,
			'order_id'      => $order->get_id(),
			'user_id'       => $user_id,
			'dispatched_at' => $dispatched_at,
		);

		$order->add_order_note( $this->note_text( $dispatch ), 0, false );

		return array(
			'dispatch' => $dispatch,
			'order'    => $order,
		);
	}

	/**
	 * Paginated dispatch history with optional filters.
	 *
	 * @param array{barcode?:string,order_id?:int,user_id?:int} $filters
	 * @return array{items: array, total: int}
	 */
	public function history( array $filters = array(), int $page = 1, int $per_page = 20 ): array {
		if ( isset( $filters['barcode'] ) ) {
			$filters['barcode'] = $this->normalizer->normalize( (string) $filters['barcode'] );
		}

		return $this->repository->paginate( $filters, $page, $per_page );
	}

	private function note_text( array $dispatch ): string {
		return sprintf(
			/* translators: 1: barcode, 2: operator display name, 3: operator ID, 4: timestamp in the site timezone */
			__( 'مرسوله خروج خورد. بارکد: %1$s. اپراتور: %2$s (#%3$d). زمان ثبت: %4$s.', 'woocommerce-parcel-dispatch' ),
			$dispatch['barcode'],
			$this->operator_name( $dispatch['user_id'] ),
			$dispatch['user_id'],
			$dispatch['dispatched_at']
		);
	}

	private function operator_name( int $user_id ): string {
		$user = get_userdata( $user_id );

		if ( false === $user ) {
			/* translators: %d: user ID */
			return sprintf( __( 'کاربر #%d', 'woocommerce-parcel-dispatch' ), $user_id );
		}

		return $user->display_name;
	}
}
