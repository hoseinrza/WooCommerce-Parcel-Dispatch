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
	 * Persian, Arabic-Indic and full-width digits mapped to ASCII digits.
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
		'０' => '0',
		'１' => '1',
		'２' => '2',
		'３' => '3',
		'４' => '4',
		'５' => '5',
		'６' => '6',
		'７' => '7',
		'８' => '8',
		'９' => '9',
	);

	/**
	 * Letters of the Persian (Standard / ISIRI 2955) keyboard layout mapped back to the
	 * ASCII key a HID scanner pressed. Postal barcodes are ASCII, so Persian letters in
	 * a scan mean the host keyboard layout translated the keystrokes. Scans taken while
	 * the legacy Persian layout is active are not recovered.
	 */
	private const PERSIAN_KEY_MAP = array(
		'ض' => 'Q',
		'ص' => 'W',
		'ث' => 'E',
		'ق' => 'R',
		'ف' => 'T',
		'غ' => 'Y',
		'ع' => 'U',
		'ه' => 'I',
		'خ' => 'O',
		'چ' => 'P',
		'ش' => 'A',
		'س' => 'S',
		'ی' => 'D',
		'ي' => 'D',
		'ب' => 'F',
		'ل' => 'G',
		'ا' => 'H',
		'ج' => 'J',
		'ک' => 'K',
		'ك' => 'K',
		'گ' => 'L',
		'ط' => 'Z',
		'ز' => 'X',
		'ظ' => 'C',
		'ت' => 'V',
		'ن' => 'B',
		'ر' => 'N',
		'د' => 'M',
	);

	/**
	 * Zero-width, bidi and other format characters that never belong to a barcode.
	 */
	private const INVISIBLE_PATTERN = '/[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}]/u';

	/**
	 * Non-ASCII spaces folded to a plain space so trimming and the internal-space
	 * rejection keep one behaviour.
	 */
	private const SPACE_PATTERN = '/[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]/u';

	/**
	 * Returns the canonical barcode, or an empty string when the input is unusable.
	 */
	public function normalize( string $barcode ): string {
		$barcode = $this->strip_invisible( $barcode );

		if ( '' === $barcode || strlen( $barcode ) > self::MAX_LENGTH ) {
			return '';
		}

		if ( ! preg_match( '/^[^\s\x00-\x1F\x7F]+$/u', $barcode ) ) {
			return '';
		}

		return strtr( $barcode, self::DIGIT_MAP + self::PERSIAN_KEY_MAP );
	}

	/**
	 * Removes characters the scanner or the operating system adds around a code.
	 */
	private function strip_invisible( string $barcode ): string {
		$folded   = (string) preg_replace( self::SPACE_PATTERN, ' ', $barcode );
		$stripped = (string) preg_replace( self::INVISIBLE_PATTERN, '', $folded );

		return trim( $stripped );
	}
}
