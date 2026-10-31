<?php
/**
 * Plugin composition root: wires services and registers WordPress hooks.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD;

use WCPD\Admin\DispatchPage;
use WCPD\Barcode\BarcodeNormalizer;
use WCPD\Barcode\BarcodeOrderFinder;
use WCPD\Database\DatabaseInstaller;
use WCPD\Database\DispatchRepository;
use WCPD\Dispatch\DispatchService;
use WCPD\Dispatch\DispatchValidator;
use WCPD\Dispatch\OrderEligibilityPolicy;
use WCPD\Rest\DispatchController;

final class Plugin {

	public const VERSION     = '1.0.3';
	public const TEXT_DOMAIN = 'woocommerce-parcel-dispatch';

	private DispatchController $controller;
	private DispatchPage $admin_page;
	private DatabaseInstaller $installer;

	public function __construct( string $plugin_file ) {
		$repository = new DispatchRepository();

		$service = new DispatchService(
			new BarcodeNormalizer(),
			new BarcodeOrderFinder(),
			new DispatchValidator( new OrderEligibilityPolicy(), $repository ),
			$repository
		);

		$this->controller = new DispatchController( $service );
		$this->admin_page = new DispatchPage( $plugin_file );
		$this->installer  = new DatabaseInstaller();
	}

	public static function boot( string $plugin_file ): self {
		$plugin = new self( $plugin_file );

		add_action( 'rest_api_init', array( $plugin, 'register_rest_routes' ) );

		add_action( 'admin_menu', array( $plugin, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $plugin, 'register_admin_assets' ) );
		add_action( 'admin_init', array( $plugin, 'maybe_upgrade' ) );

		return $plugin;
	}

	public function register_rest_routes(): void {
		$this->controller->register_routes();
	}

	public function register_admin_menu(): void {
		$this->admin_page->add_menu();
	}

	public function register_admin_assets( string $hook_suffix ): void {
		$this->admin_page->enqueue_assets( $hook_suffix );
	}

	public function maybe_upgrade(): void {
		if ( DatabaseInstaller::DB_VERSION !== get_option( DatabaseInstaller::DB_VERSION_OPTION ) ) {
			$this->installer->install();
		}
	}
}
