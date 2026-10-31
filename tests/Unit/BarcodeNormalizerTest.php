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
}
