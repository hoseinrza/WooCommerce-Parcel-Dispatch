<?php
/**
 * Warehouse dispatch screen: scanner form, order confirmation and history.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Admin;

use WCPD\Plugin;
use WCPD\Rest\DispatchController;

final class DispatchPage {

	public const SLUG = 'wcpd-dispatch';

	private const HOOK_SUFFIX = 'woocommerce_page_' . self::SLUG;

	public function __construct( private string $plugin_file ) {}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'خروج مرسولات', 'woocommerce-parcel-dispatch' ),
			__( 'خروج مرسولات', 'woocommerce-parcel-dispatch' ),
			$this->capability(),
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( self::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wcpd-admin',
			plugins_url( 'assets/css/admin.css', $this->plugin_file ),
			array(),
			Plugin::VERSION
		);

		wp_enqueue_script(
			'wcpd-dispatch',
			plugins_url( 'assets/js/dispatch.js', $this->plugin_file ),
			array( 'wp-api-fetch' ),
			Plugin::VERSION,
			true
		);

		wp_localize_script(
			'wcpd-dispatch',
			'wcpdData',
			array(
				'restUrl'  => esc_url_raw( rest_url( DispatchController::REST_NAMESPACE . '/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'pageSize' => 20,
				'i18n'     => array(
					'lookingUp'      => __( 'در حال جست‌وجوی بارکد…', 'woocommerce-parcel-dispatch' ),
					'lookingUpHint'  => __( 'در حال جست‌وجو', 'woocommerce-parcel-dispatch' ),
					'dispatching'    => __( 'در حال ثبت خروج…', 'woocommerce-parcel-dispatch' ),
					'dispatched'     => __( 'خروج مرسوله با موفقیت ثبت شد.', 'woocommerce-parcel-dispatch' ),
					'cancelled'      => __( 'عملیات لغو شد. بارکد بعدی را اسکن کنید.', 'woocommerce-parcel-dispatch' ),
					'notFound'       => __( 'سفارشی با این بارکد پیدا نشد.', 'woocommerce-parcel-dispatch' ),
					'requestFailed'  => __( 'ارتباط با سرور برقرار نشد. دوباره تلاش کنید.', 'woocommerce-parcel-dispatch' ),
					'emptyHistory'   => __( 'هنوز مرسوله‌ای خروج نخورده است.', 'woocommerce-parcel-dispatch' ),
					'loadFailed'     => __( 'بارگیری تاریخچه ناموفق بود.', 'woocommerce-parcel-dispatch' ),
					'orderFound'     => __( 'سفارش پیدا شد. برای ثبت خروج، دکمه «تأیید خروج مرسوله» را بزنید.', 'woocommerce-parcel-dispatch' ),
					'previousDispatch' => __( 'ثبت قبلی: %1$s — %2$s', 'woocommerce-parcel-dispatch' ),
					'previous'       => __( 'قبلی', 'woocommerce-parcel-dispatch' ),
					'next'           => __( 'بعدی', 'woocommerce-parcel-dispatch' ),
					'pageOf'         => __( 'صفحه %1$s از %2$s', 'woocommerce-parcel-dispatch' ),
					'noItems'        => __( 'این سفارش قلمی ندارد.', 'woocommerce-parcel-dispatch' ),
					'notDispatched'  => __( 'هنوز خروج نخورده است.', 'woocommerce-parcel-dispatch' ),
					'alreadyScanned' => __( 'این بارکد قبلاً خروج خورده است.', 'woocommerce-parcel-dispatch' ),
				),
			)
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'شما اجازه دسترسی به این صفحه را ندارید.', 'woocommerce-parcel-dispatch' ) );
		}
		?>
		<div class="wrap wcpd-wrap" dir="rtl">
			<header class="wcpd-hero">
				<span class="wcpd-brand-mark" aria-hidden="true">
					<span class="dashicons dashicons-archive"></span>
				</span>
				<div class="wcpd-hero-copy">
					<span class="wcpd-brand-kicker"><?php esc_html_e( 'WooCommerce Parcel Dispatch', 'woocommerce-parcel-dispatch' ); ?></span>
					<h1 class="wp-heading-inline"><?php esc_html_e( 'خروج مرسولات', 'woocommerce-parcel-dispatch' ); ?></h1>
					<p class="wcpd-intro">
						<?php esc_html_e( 'بارکد پستی را با بارکدخوان اسکن کنید یا دستی وارد کنید و Enter بزنید. خروج مرسوله فقط پس از تأیید شما ثبت می‌شود.', 'woocommerce-parcel-dispatch' ); ?>
					</p>
				</div>
			</header>
			<hr class="wp-header-end" />

			<noscript>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'برای استفاده از این صفحه باید جاوااسکریپت مرورگر فعال باشد.', 'woocommerce-parcel-dispatch' ); ?></p>
				</div>
			</noscript>

			<form id="wcpd-scan-form" class="wcpd-scan-form" autocomplete="off">
				<label for="wcpd-barcode" class="wcpd-scan-label">
					<?php esc_html_e( 'بارکد پستی مرسوله', 'woocommerce-parcel-dispatch' ); ?>
				</label>
				<div class="wcpd-scan-row">
					<input
						type="text"
						id="wcpd-barcode"
						name="barcode"
						class="wcpd-barcode-input"
						inputmode="numeric"
						autocomplete="off"
						autocapitalize="off"
						spellcheck="false"
						autofocus
						maxlength="100"
						required
						aria-describedby="wcpd-scan-help"
					/>
					<button type="submit" class="button button-primary button-large" id="wcpd-lookup">
						<?php esc_html_e( 'جست‌وجو', 'woocommerce-parcel-dispatch' ); ?>
					</button>
				</div>
				<p id="wcpd-scan-help" class="wcpd-scan-help">
					<?php esc_html_e( 'پس از هر اسکن، بارکد به‌صورت خودکار پاک و آماده اسکن بعدی می‌شود.', 'woocommerce-parcel-dispatch' ); ?>
				</p>
			</form>

			<div id="wcpd-message" class="wcpd-message" role="status" aria-live="polite" hidden></div>

			<section id="wcpd-order" class="wcpd-card wcpd-order" hidden aria-labelledby="wcpd-order-title">
				<h2 id="wcpd-order-title" class="wcpd-card-title">
					<?php esc_html_e( 'اطلاعات سفارش', 'woocommerce-parcel-dispatch' ); ?>
				</h2>

				<dl class="wcpd-order-grid">
					<div class="wcpd-field">
						<dt><?php esc_html_e( 'شماره سفارش', 'woocommerce-parcel-dispatch' ); ?></dt>
						<dd id="wcpd-order-number">—</dd>
					</div>
					<div class="wcpd-field">
						<dt><?php esc_html_e( 'وضعیت سفارش', 'woocommerce-parcel-dispatch' ); ?></dt>
						<dd id="wcpd-order-status">—</dd>
					</div>
					<div class="wcpd-field">
						<dt><?php esc_html_e( 'نام مشتری', 'woocommerce-parcel-dispatch' ); ?></dt>
						<dd id="wcpd-order-customer">—</dd>
					</div>
					<div class="wcpd-field">
						<dt><?php esc_html_e( 'تلفن مشتری', 'woocommerce-parcel-dispatch' ); ?></dt>
						<dd id="wcpd-order-phone">—</dd>
					</div>
					<div class="wcpd-field wcpd-field-wide">
						<dt><?php esc_html_e( 'نشانی ارسال', 'woocommerce-parcel-dispatch' ); ?></dt>
						<dd id="wcpd-order-address">—</dd>
					</div>
					<div class="wcpd-field">
						<dt><?php esc_html_e( 'بارکد پستی', 'woocommerce-parcel-dispatch' ); ?></dt>
						<dd id="wcpd-order-barcode">—</dd>
					</div>
					<div class="wcpd-field">
						<dt><?php esc_html_e( 'وضعیت خروج', 'woocommerce-parcel-dispatch' ); ?></dt>
						<dd id="wcpd-order-dispatch-status">—</dd>
					</div>
					<div class="wcpd-field wcpd-field-wide">
						<dt><?php esc_html_e( 'اقلام سفارش', 'woocommerce-parcel-dispatch' ); ?></dt>
						<dd>
							<ul id="wcpd-order-items" class="wcpd-order-items"></ul>
						</dd>
					</div>
				</dl>

				<div class="wcpd-actions">
					<button type="button" class="button button-primary button-hero" id="wcpd-confirm">
						<?php esc_html_e( 'تأیید خروج مرسوله', 'woocommerce-parcel-dispatch' ); ?>
					</button>
					<button type="button" class="button button-secondary button-hero" id="wcpd-cancel">
						<?php esc_html_e( 'لغو', 'woocommerce-parcel-dispatch' ); ?>
					</button>
					<a id="wcpd-open-order" class="button button-secondary button-hero wcpd-open-order" target="_blank" rel="noopener noreferrer" hidden>
						<?php esc_html_e( 'باز کردن سفارش', 'woocommerce-parcel-dispatch' ); ?>
					</a>
				</div>
			</section>

			<section class="wcpd-card wcpd-history" aria-labelledby="wcpd-history-title">
				<h2 id="wcpd-history-title" class="wcpd-card-title">
					<?php esc_html_e( 'تاریخچه خروج مرسولات', 'woocommerce-parcel-dispatch' ); ?>
				</h2>

				<form id="wcpd-history-filters" class="wcpd-history-filters" autocomplete="off">
					<label class="screen-reader-text" for="wcpd-filter-barcode">
						<?php esc_html_e( 'فیلتر بارکد', 'woocommerce-parcel-dispatch' ); ?>
					</label>
					<input
						type="text"
						id="wcpd-filter-barcode"
						name="barcode"
						placeholder="<?php esc_attr_e( 'بارکد', 'woocommerce-parcel-dispatch' ); ?>"
						maxlength="100"
					/>

					<label class="screen-reader-text" for="wcpd-filter-order">
						<?php esc_html_e( 'فیلتر شماره سفارش', 'woocommerce-parcel-dispatch' ); ?>
					</label>
					<input
						type="number"
						id="wcpd-filter-order"
						name="order_id"
						min="1"
						placeholder="<?php esc_attr_e( 'شناسه سفارش', 'woocommerce-parcel-dispatch' ); ?>"
					/>

					<label class="screen-reader-text" for="wcpd-filter-operator">
						<?php esc_html_e( 'فیلتر اپراتور', 'woocommerce-parcel-dispatch' ); ?>
					</label>
					<input
						type="number"
						id="wcpd-filter-operator"
						name="operator"
						min="1"
						placeholder="<?php esc_attr_e( 'شناسه اپراتور', 'woocommerce-parcel-dispatch' ); ?>"
					/>

					<button type="submit" class="button"><?php esc_html_e( 'اعمال فیلتر', 'woocommerce-parcel-dispatch' ); ?></button>
					<button type="reset" class="button-link" id="wcpd-filter-reset"><?php esc_html_e( 'حذف فیلترها', 'woocommerce-parcel-dispatch' ); ?></button>
				</form>

				<div class="wcpd-table-scroll">
					<table class="widefat striped wcpd-history-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'بارکد', 'woocommerce-parcel-dispatch' ); ?></th>
								<th scope="col"><?php esc_html_e( 'سفارش', 'woocommerce-parcel-dispatch' ); ?></th>
								<th scope="col"><?php esc_html_e( 'اپراتور', 'woocommerce-parcel-dispatch' ); ?></th>
								<th scope="col"><?php esc_html_e( 'زمان ثبت', 'woocommerce-parcel-dispatch' ); ?></th>
							</tr>
						</thead>
						<tbody id="wcpd-history-body">
							<tr>
								<td colspan="4"><?php esc_html_e( 'در حال بارگیری…', 'woocommerce-parcel-dispatch' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<nav id="wcpd-history-pagination" class="wcpd-pagination" aria-label="<?php esc_attr_e( 'صفحه‌بندی تاریخچه', 'woocommerce-parcel-dispatch' ); ?>">
					<button type="button" class="button" id="wcpd-history-prev">
						<?php esc_html_e( 'قبلی', 'woocommerce-parcel-dispatch' ); ?>
					</button>
					<span id="wcpd-history-page" class="wcpd-page-indicator"></span>
					<button type="button" class="button" id="wcpd-history-next">
						<?php esc_html_e( 'بعدی', 'woocommerce-parcel-dispatch' ); ?>
					</button>
				</nav>
			</section>
		</div>
		<?php
	}

	private function capability(): string {
		return (string) apply_filters( DispatchController::CAPABILITY_FILTER, 'manage_woocommerce' );
	}
}
