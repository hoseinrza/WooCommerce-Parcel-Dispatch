<?php

namespace WCPD\Tests\Integration;

use WCPD\Admin\OrdersListColumn;
use WCPD\Database\DispatchRepository;

class OrdersListColumnTest extends DatabaseTestCase {

	private DispatchRepository $repository;
	private OrdersListColumn $column;

	protected function setUp(): void {
		parent::setUp();

		$this->repository = new DispatchRepository();
		$this->column     = new OrdersListColumn(
			$this->repository,
			dirname( __DIR__, 2 ) . '/woocommerce-parcel-dispatch.php'
		);
	}

	private function render_hpos( \WC_Order $order, string $column = OrdersListColumn::COLUMN ): string {
		ob_start();
		$this->column->render_hpos( $column, $order );

		return (string) ob_get_clean();
	}

	public function test_column_is_added_before_the_actions_column(): void {
		$columns = $this->column->add_column(
			array(
				'cb'       => 'CB',
				'order_number' => 'Order',
				'wc_actions'   => 'Actions',
			)
		);

		$this->assertSame(
			array( 'cb', 'order_number', OrdersListColumn::COLUMN, 'wc_actions' ),
			array_keys( $columns )
		);
		$this->assertSame( 'خروج از انبار', $columns[ OrdersListColumn::COLUMN ] );
	}

	public function test_column_is_appended_when_actions_column_is_absent(): void {
		$columns = $this->column->add_column( array( 'order_number' => 'Order' ) );

		$this->assertSame(
			array( 'order_number', OrdersListColumn::COLUMN ),
			array_keys( $columns )
		);
	}

	public function test_dispatched_order_renders_a_check(): void {
		$order = $this->create_order( '1234567890' );
		$this->repository->create( '1234567890', $order->get_id(), 1, '2026-10-31 10:00:00' );

		$html = $this->render_hpos( $order );

		$this->assertStringContainsString( 'wcpd-order-flag--yes', $html );
		$this->assertStringContainsString( 'dashicons-yes-alt', $html );
		$this->assertStringContainsString( 'خروج خورده', $html );
		$this->assertStringNotContainsString( 'dashicons-dismiss', $html );
	}

	public function test_undispatched_order_renders_a_cross(): void {
		$order = $this->create_order( '1234567890' );

		$html = $this->render_hpos( $order );

		$this->assertStringContainsString( 'wcpd-order-flag--no', $html );
		$this->assertStringContainsString( 'dashicons-dismiss', $html );
		$this->assertStringContainsString( 'خروج نخورده', $html );
		$this->assertStringNotContainsString( 'dashicons-yes-alt', $html );
	}

	public function test_other_columns_render_nothing(): void {
		$order = $this->create_order( '1234567890' );

		$this->assertSame( '', $this->render_hpos( $order, 'order_number' ) );
	}

	public function test_legacy_rendering_uses_the_post_id(): void {
		$order = $this->create_order( '1234567890' );
		$this->repository->create( '1234567890', $order->get_id(), 1, '2026-10-31 10:00:00' );

		ob_start();
		$this->column->render_legacy( OrdersListColumn::COLUMN, $order->get_id() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'dashicons-yes-alt', $html );
	}

	public function test_hooks_are_registered_for_hpos_and_legacy(): void {
		$this->column->register();

		$this->assertNotFalse( has_filter( 'woocommerce_shop_order_list_table_columns', array( $this->column, 'add_column' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_shop_order_list_table_custom_column', array( $this->column, 'render_hpos' ) ) );
		$this->assertNotFalse( has_filter( 'manage_edit-shop_order_columns', array( $this->column, 'add_column' ) ) );
		$this->assertNotFalse( has_action( 'manage_shop_order_posts_custom_column', array( $this->column, 'render_legacy' ) ) );
	}
}
