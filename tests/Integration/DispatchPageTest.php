<?php

namespace WCPD\Tests\Integration;

use WCPD\Admin\DispatchPage;

class DispatchPageTest extends IntegrationTestCase {

	private DispatchPage $page;
	private int $operator_id;

	protected function setUp(): void {
		parent::setUp();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// The WP_Scripts queue is shared across tests; start each test unqueued.
		wp_dequeue_script( 'wcpd-dispatch' );
		wp_dequeue_style( 'wcpd-admin' );

		$this->page = new DispatchPage( dirname( __DIR__, 2 ) . '/woocommerce-parcel-dispatch.php' );
		$this->operator_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
	}

	public function test_menu_is_registered_under_woocommerce_for_operators(): void {
		wp_set_current_user( $this->operator_id );

		$this->page->add_menu();

		global $submenu;
		$slugs = array_column( $submenu['woocommerce'], 2 );

		$this->assertContains( 'wcpd-dispatch', $slugs );
	}

	public function test_menu_capability_is_configurable_by_filter(): void {
		add_filter( 'wcpd_dispatch_capability', static fn(): string => 'read' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->page->add_menu();

		global $submenu;
		$slugs = array_column( $submenu['woocommerce'], 2 );

		$this->assertContains( 'wcpd-dispatch', $slugs );
	}

	public function test_page_renders_scanner_and_history_markup(): void {
		wp_set_current_user( $this->operator_id );

		ob_start();
		$this->page->render_page();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="wcpd-barcode"', $html );
		$this->assertStringContainsString( 'autofocus', $html );
		$this->assertStringContainsString( 'autocomplete="off"', $html );
		$this->assertStringContainsString( 'id="wcpd-scan-form"', $html );
		$this->assertStringContainsString( 'id="wcpd-confirm"', $html );
		$this->assertStringContainsString( 'id="wcpd-history-body"', $html );
		$this->assertStringContainsString( 'id="wcpd-history-filters"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
	}

	public function test_assets_are_enqueued_only_on_the_dispatch_page(): void {
		wp_set_current_user( $this->operator_id );

		$this->page->enqueue_assets( 'woocommerce_page_wcpd-dispatch' );

		$this->assertTrue( wp_script_is( 'wcpd-dispatch', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'wcpd-admin', 'enqueued' ) );

		$inline = wp_scripts()->get_data( 'wcpd-dispatch', 'data' );
		$this->assertStringContainsString( 'wcpdData', (string) $inline );
		$this->assertStringContainsString( 'restUrl', (string) $inline );
		$this->assertStringContainsString( 'nonce', (string) $inline );
	}

	public function test_assets_are_not_enqueued_on_other_pages(): void {
		wp_set_current_user( $this->operator_id );

		$this->page->enqueue_assets( 'edit.php' );

		$this->assertFalse( wp_script_is( 'wcpd-dispatch', 'enqueued' ) );
	}
}
