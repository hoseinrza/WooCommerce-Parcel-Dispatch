<?php

namespace WCPD\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WCPD\Barcode\BarcodeNormalizer;

class BarcodeNormalizerTest extends TestCase {

	private BarcodeNormalizer $normalizer;

	protected function setUp(): void {
		parent::setUp();
		$this->normalizer = new BarcodeNormalizer();
	}

	public function test_valid_barcode_is_returned_unchanged(): void {
		$this->assertSame( '12345678901234567890', $this->normalizer->normalize( '12345678901234567890' ) );
	}

	public function test_empty_barcode_is_invalid(): void {
		$this->assertSame( '', $this->normalizer->normalize( '' ) );
	}

	public function test_whitespace_only_barcode_is_invalid(): void {
		$this->assertSame( '', $this->normalizer->normalize( "  \t\n " ) );
	}

	public function test_leading_and_trailing_whitespace_is_trimmed(): void {
		$this->assertSame( '12345678901234567890', $this->normalizer->normalize( " \r\n12345678901234567890\r\n " ) );
	}

	public function test_persian_digits_are_converted_to_english_digits(): void {
		$this->assertSame( '1234567890', $this->normalizer->normalize( '۱۲۳۴۵۶۷۸۹۰' ) );
	}

	public function test_arabic_indic_digits_are_converted_to_english_digits(): void {
		$this->assertSame( '1234567890', $this->normalizer->normalize( '١٢٣٤٥٦٧٨٩٠' ) );
	}

	public function test_full_width_digits_are_converted_to_english_digits(): void {
		$this->assertSame( '0123456789', $this->normalizer->normalize( '０１２３４５６７８９' ) );
	}

	public function test_full_width_zero_leading_a_barcode_is_preserved(): void {
		$this->assertSame( '00123456', $this->normalizer->normalize( '００１２３４５６' ) );
	}

	public function test_full_width_digits_mixed_with_letters_are_converted(): void {
		$this->assertSame( 'RR123456789IR', $this->normalizer->normalize( 'RR１２３４５６７８９IR' ) );
	}

	public function test_mixed_digit_scripts_are_converted(): void {
		$this->assertSame( '1203', $this->normalizer->normalize( '۱2٠3' ) );
	}

	public function test_leading_zeros_are_preserved(): void {
		$this->assertSame( '0000123456', $this->normalizer->normalize( '0000123456' ) );
	}

	public function test_leading_zeros_in_persian_digits_are_preserved(): void {
		$this->assertSame( '00123456', $this->normalizer->normalize( '۰۰۱۲۳۴۵۶' ) );
	}

	public function test_meaningful_letters_and_punctuation_are_preserved(): void {
		$this->assertSame( 'RR123456789IR', $this->normalizer->normalize( 'RR123456789IR' ) );
		$this->assertSame( 'IR-1234-5678', $this->normalizer->normalize( 'IR-1234-5678' ) );
	}

	public function test_internal_whitespace_is_rejected(): void {
		$this->assertSame( '', $this->normalizer->normalize( '1234 5678' ) );
	}

	public function test_control_characters_are_rejected(): void {
		$this->assertSame( '', $this->normalizer->normalize( "1234\x00\x075678" ) );
	}

	public function test_barcode_longer_than_column_is_rejected(): void {
		$this->assertSame( '', $this->normalizer->normalize( str_repeat( '9', 101 ) ) );
	}

	public function test_barcode_at_maximum_length_is_accepted(): void {
		$barcode = str_repeat( '9', 100 );
		$this->assertSame( $barcode, $this->normalizer->normalize( $barcode ) );
	}

	public function test_barcode_with_invalid_utf8_is_rejected(): void {
		$this->assertSame( '', $this->normalizer->normalize( "\xFF123456" ) );
	}

	public function test_result_is_always_a_string(): void {
		$this->assertIsString( $this->normalizer->normalize( '000123' ) );
		$this->assertIsString( $this->normalizer->normalize( '۱۲۳' ) );
		$this->assertIsString( $this->normalizer->normalize( '' ) );
	}

	public function test_bidi_controls_are_removed(): void {
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "1234567890123\u{200F}" ) );
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "\u{200E}1234567890123" ) );
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "\u{FEFF}1234567890123\u{202E}" ) );
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "1234567\u{202D}890123" ) );
	}

	public function test_zero_width_characters_are_removed_everywhere(): void {
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "12345678\u{200B}90123" ) );
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "123456789012\u{200C}3" ) );
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "12345\u{200D}67890123" ) );
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "1234\u{2060}567890123" ) );
	}

	public function test_soft_hyphen_is_removed(): void {
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "123456789012\u{00AD}3" ) );
	}

	public function test_unicode_spaces_are_trimmed(): void {
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "\u{00A0}1234567890123\u{00A0}" ) );
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "1234567890123\u{202F}" ) );
		$this->assertSame( '1234567890123', $this->normalizer->normalize( "\u{3000}1234567890123" ) );
	}

	public function test_internal_unicode_whitespace_is_still_rejected(): void {
		$this->assertSame( '', $this->normalizer->normalize( "1234\u{00A0}5678" ) );
	}

	public function test_letters_scanned_under_a_persian_keyboard_layout_are_recovered(): void {
		$this->assertSame( 'RR123456789IR', $this->normalizer->normalize( 'قق123456789هق' ) );
		$this->assertSame( 'RB123456789IR', $this->normalizer->normalize( 'قن123456789هق' ) );
		$this->assertSame( 'CB123456789IR', $this->normalizer->normalize( 'ظن123456789هق' ) );
		$this->assertSame( 'GX123456789IR', $this->normalizer->normalize( 'لز123456789هق' ) );
		$this->assertSame( 'LX123456789IR', $this->normalizer->normalize( 'گز123456789هق' ) );
	}

	public function test_letter_recovery_combines_with_persian_digits(): void {
		$this->assertSame( 'RR123456789IR', $this->normalizer->normalize( 'قق۱۲۳۴۵۶۷۸۹هق' ) );
	}

	public function test_persian_letters_always_recover_as_uppercase(): void {
		$this->assertSame( 'RR123456789IR', $this->normalizer->normalize( 'قق123456789هق' ) );
		$this->assertMatchesRegularExpression( '/^[A-Z0-9]+$/', $this->normalizer->normalize( 'قق123456789هق' ) );
	}

	public function test_english_barcodes_are_not_changed_by_letter_recovery(): void {
		$this->assertSame( 'RR123456789IR', $this->normalizer->normalize( 'RR123456789IR' ) );
		$this->assertSame( 'rr123456789ir', $this->normalizer->normalize( 'rr123456789ir' ) );
		$this->assertSame( 'IR-1234-5678', $this->normalizer->normalize( 'IR-1234-5678' ) );
	}

	public function test_a_scan_of_only_invisible_characters_is_invalid(): void {
		$this->assertSame( '', $this->normalizer->normalize( "\u{200F}\u{200B}" ) );
	}
}
