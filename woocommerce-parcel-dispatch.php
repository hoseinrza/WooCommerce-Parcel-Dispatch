<?php
/**
 * Plugin Name: WooCommerce Parcel Dispatch
 * Description: ثبت خروج مرسولات انبار با اسکن بارکد پستی سفارش‌های ووکامرس.
 * Version:     1.0.2
 * Author:      AmirHossein Rezazadeh
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woocommerce-parcel-dispatch
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * WC requires at least: 8.0
 * WC tested up to: 11.2
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'WCPD_VERSION', '1.0.2' );
define( 'WCPD_FILE', __FILE__ );
define( 'WCPD_DIR', __DIR__ );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'WCPD\\';

		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		( new \WCPD\Database\DatabaseInstaller() )->install();
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		load_plugin_textdomain( 'woocommerce-parcel-dispatch', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_orders' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}

					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'افزونه «خروج مرسولات» به ووکامرس نیاز دارد. برای استفاده، ووکامرس را نصب و فعال کنید.', 'woocommerce-parcel-dispatch' );
					echo '</p></div>';
				}
			);

			return;
		}

		\WCPD\Plugin::boot( __FILE__ );
	}
);
