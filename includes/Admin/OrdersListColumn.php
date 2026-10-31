<?php
/**
 * Adds the warehouse dispatch state column to the WooCommerce orders list.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Admin;

use WC_Order;
use WCPD\Database\DispatchRepository;
use WCPD\Plugin;

final class OrdersListColumn {

	public const COLUMN = 'wcpd_dispatch_state';

	public function __construct(
		private DispatchRepository $repository,
		private string $plugin_file
	) {}

	public function register(): void {
		add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_hpos' ), 10, 2 );

		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy' ), 10, 2 );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$result = array();

		foreach ( $columns as $key => $label ) {
			if ( 'wc_actions' === $key ) {
				$result[ self::COLUMN ] = __( 'خروج از انبار', 'woocommerce-parcel-dispatch' );
			}

			$result[ $key ] = $label;
		}

		if ( ! isset( $result[ self::COLUMN ] ) ) {
			$result[ self::COLUMN ] = __( 'خروج از انبار', 'woocommerce-parcel-dispatch' );
		}

		return $result;
	}

	/**
	 * @param string   $column Column identifier.
	 * @param WC_Order $order  Order of the current row.
	 */
	public function render_hpos( string $column, $order ): void {
		if ( self::COLUMN !== $column || ! $order instanceof WC_Order ) {
			return;
		}

		$this->render( $order->get_id() );
	}

	/**
	 * @param string $column   Column identifier.
	 * @param int    $order_id Post ID of the current row.
	 */
	public function render_legacy( string $column, $order_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}

		$this->render( absint( $order_id ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return;
		}

		$screens = array( 'edit-shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop_order' );
		}

		if ( ! in_array( $screen->id, $screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'wcpd-orders',
			plugins_url( 'assets/css/orders.css', $this->plugin_file ),
			array( 'dashicons' ),
			Plugin::VERSION
		);
	}

	private function render( int $order_id ): void {
		$dispatched = $this->repository->is_order_dispatched( $order_id );
		$label      = $dispatched
			? __( 'خروج خورده', 'woocommerce-parcel-dispatch' )
			: __( 'خروج نخورده', 'woocommerce-parcel-dispatch' );

		printf(
			'<span class="wcpd-order-flag wcpd-order-flag--%1$s" title="%2$s"><span class="dashicons %3$s" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span></span>',
			$dispatched ? 'yes' : 'no',
			esc_attr( $label ),
			$dispatched ? 'dashicons-yes-alt' : 'dashicons-dismiss'
		);
	}
}
