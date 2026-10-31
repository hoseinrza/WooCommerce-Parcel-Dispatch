<?php
/**
 * Normalizes scanned or typed postal barcodes.
 *
 * @package WooCommerce Parcel Dispatch
 */

declare( strict_types=1 );

namespace WCPD\Barcode;

final class BarcodeNormalizer {

	private const MAX_LENGTH = 100;

	/**
	 * Persian and Arabic-Indic digits mapped to ASCII digits.
	 */
	private const DIGIT_MAP = array(
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
	);

	/**
	 * Returns the canonical barcode, or an empty string when the input is unusable.
	 */
	public function normalize( string $barcode ): string {
		$barcode = trim( $barcode );

		if ( '' === $barcode || strlen( $barcode ) > self::MAX_LENGTH ) {
			return '';
		}

		if ( ! preg_match( '/^[^\s\x00-\x1F\x7F]+$/u', $barcode ) ) {
			return '';
		}

		return strtr( $barcode, self::DIGIT_MAP );
	}
}
