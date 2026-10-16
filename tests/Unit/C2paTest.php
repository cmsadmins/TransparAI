<?php
/**
 * C2PA manifest reading, data hash and signer tests against real signed files.
 *
 * The fixtures in tests/fixtures/c2pa/ were produced once with c2patool
 * (see the README there) and are checked in; nothing here signs anything.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class C2paTest extends TestCase {

	/** @var string[] */
	private array $temp_files = array();

	protected function setUp(): void {
		trai_test_reset();
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->temp_files = array();
	}

	private function temp_copy( string $fixture ): string {
		$path = sys_get_temp_dir() . '/trai-c2pa-' . uniqid() . '.' . pathinfo( $fixture, PATHINFO_EXTENSION );
		copy( trai_fixture( $fixture ), $path );
		$this->temp_files[] = $path;
		return $path;
	}

	/**
	 * Fixture, format, and an offset inside the image data (the WebP manifest
	 * sits at the end of the file, so "near the end" would hit excluded bytes).
	 *
	 * @return array<string, array{string, string, int}>
	 */
	public function signed_fixtures(): array {
		return array(
			'jpeg' => array( 'signed.jpg', 'jpeg', -3 ),
			'png'  => array( 'signed.png', 'png', -20 ),
			'webp' => array( 'signed.webp', 'webp', 30 ),
		);
	}

	/**
	 * @dataProvider signed_fixtures
	 */
	public function test_summary_of_untouched_signed_file( string $fixture, string $format, int $offset ): void {
		unset( $offset );
		$info = TransparAI_C2PA::summary( (string) file_get_contents( trai_fixture( $fixture ) ), $format );

		$this->assertNotNull( $info );
		$this->assertSame( 'match', $info['hash'], $info['reason'] );
		$this->assertSame( 'sha256', $info['alg'] );
		$this->assertSame( 1, $info['manifests'] );
		$this->assertSame( 'TransparAI fixture 1.0', $info['generator'] );
		$this->assertSame( '2026-01-01T00:00:00Z', $info['when'] );
		$this->assertSame( 'C2PA Signer', $info['signer_cn'] );
		$this->assertSame( 'C2PA Test Signing Cert', $info['signer_o'] );
	}

	/**
	 * @dataProvider signed_fixtures
	 */
	public function test_edited_pixels_mismatch( string $fixture, string $format, int $offset ): void {
		$data        = (string) file_get_contents( trai_fixture( $fixture ) );
		$at          = $offset < 0 ? strlen( $data ) + $offset : $offset;
		$data[ $at ] = chr( ord( $data[ $at ] ) ^ 0xFF );

		$info = TransparAI_C2PA::summary( $data, $format );
		$this->assertNotNull( $info );
		$this->assertSame( 'mismatch', $info['hash'] );
		$this->assertSame( 'C2PA Signer', $info['signer_cn'], 'Signer is still reported for a mismatching file' );
	}

	public function test_unknown_algorithm_and_missing_hash_assertion_are_unsupported(): void {
		$data = (string) file_get_contents( trai_fixture( 'signed.jpg' ) );

		$renamed = str_replace( 'c2pa.hash.data', 'c2pa.hash.xxxx', $data );
		$info    = TransparAI_C2PA::summary( $renamed, 'jpeg' );
		$this->assertSame( 'unsupported', $info['hash'] );
		$this->assertSame( 'no_hash_data', $info['reason'] );

		/* The alg text strings (claim and hash assertion) are CBOR text of length 6; same length keeps the CBOR valid. */
		$bad  = str_replace( 'fsha256', 'fsha999', $data );
		$info = TransparAI_C2PA::summary( $bad, 'jpeg' );
		$this->assertSame( 'unsupported', $info['hash'] );
		$this->assertSame( 'unknown_alg', $info['reason'] );
	}

	public function test_truncated_store_never_throws(): void {
		$data = (string) file_get_contents( trai_fixture( 'signed.png' ) );
		for ( $cut = 60; $cut < strlen( $data ); $cut += 97 ) {
			$info = TransparAI_C2PA::summary( substr( $data, 0, $cut ), 'png' );
			$this->assertTrue( null === $info || in_array( $info['hash'], array( 'unsupported', 'mismatch' ), true ), "cut at {$cut}" );
		}
		$this->assertNull( TransparAI_C2PA::summary( (string) file_get_contents( trai_fixture( 'base.jpg' ) ), 'jpeg' ) );
	}

	/**
	 * A c2pa.hash.data assertion that matches $data with the given exclusions.
	 *
	 * @param array<int, array{0:int, 1:int}> $exclusions Start and length pairs.
	 * @return array<string, mixed>
	 */
	private static function binding( string $data, array $exclusions ): array {
		usort( $exclusions, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );
		$hashed = '';
		$pos    = 0;
		foreach ( $exclusions as list( $start, $length ) ) {
			$hashed .= substr( $data, $pos, $start - $pos );
			$pos     = $start + $length;
		}
		$hashed .= substr( $data, $pos );
		return array(
			'alg'        => 'sha256',
			'hash'       => hash( 'sha256', $hashed, true ),
			'exclusions' => array_map(
				static fn( array $r ): array => array(
					'start'  => $r[0],
					'length' => $r[1],
				),
				$exclusions
			),
		);
	}

	/**
	 * @dataProvider signed_fixtures
	 */
	public function test_store_ranges_are_whole_containers( string $fixture, string $format, int $offset ): void {
		unset( $offset );
		$data     = (string) file_get_contents( trai_fixture( $fixture ) );
		$location = TransparAI_C2PA::store_location( $data, $format );
		$this->assertNotNull( $location );
		$this->assertNotSame( array(), $location['ranges'] );
		$first = $location['ranges'][0][0];
		$this->assertSame( 'jpeg' === $format ? "\xFF\xEB" : ( 'png' === $format ? 'caBX' : 'C2PA' ), substr( $data, 'png' === $format ? $first + 4 : $first, 'jpeg' === $format ? 2 : 4 ) );

		$parsed = TransparAI_C2PA::parse_store( $location['store'] );
		$ranges = array_map( static fn( array $e ): array => array( $e['start'], $e['length'] ), $parsed['hash_data']['exclusions'] );
		$this->assertSame( 'match', TransparAI_C2PA::hash_check( $data, $parsed['hash_data'], 'sha256', $location['ranges'] )['state'] );
		$this->assertNotSame( array(), $ranges );
	}

	public function test_exclusions_may_not_unbind_the_image(): void {
		$data   = (string) file_get_contents( trai_fixture( 'signed.jpg' ) );
		$store  = TransparAI_C2PA::store_location( $data, 'jpeg' );
		$ranges = $store['ranges'];
		$first  = $ranges[0][0];
		$last   = end( $ranges );
		$block  = array( $first, $last[0] + $last[1] - $first );
		$length = strlen( $data );

		$check = static fn( array $exclusions, array $allowed = array() ): array => TransparAI_C2PA::hash_check( $data, self::binding( $data, $exclusions ), 'sha256', $ranges, $allowed );

		$this->assertSame( 'match', $check( array( $block ) )['state'], 'the store block alone is a valid binding' );

		$tail = $check( array( array( $first, $length - $first ) ) );
		$this->assertSame( array( 'mismatch', 'exclusion_beyond_store' ), array( $tail['state'], $tail['reason'] ), 'store plus image data to the end' );

		$all = $check( array( array( 0, $length ) ) );
		$this->assertSame( array( 'mismatch', 'nothing_hashed' ), array( $all['state'], $all['reason'] ) );

		$none = $check( array() );
		$this->assertSame( array( 'mismatch', 'store_not_excluded' ), array( $none['state'], $none['reason'] ) );

		$scan = $check( array( $block, array( $length - 40, 20 ) ) );
		$this->assertSame( array( 'mismatch', 'exclusion_not_allowed' ), array( $scan['state'], $scan['reason'] ), 'a second exclusion in the scan data' );

		$allowed = $check( array( $block, array( $length - 40, 20 ) ), array( array( $length - 50, 40 ) ) );
		$this->assertSame( 'match', $allowed['state'], 'an exclusion inside an allowed range' );
	}

	public function test_only_jpeg_exif_and_xmp_payloads_may_be_excluded(): void {
		$exif = "Exif\0\0" . str_repeat( 'e', 20 );
		$xmp  = TransparAI_Parsers::XMP_HEADER_JPEG . '<x/>';
		$com  = 'a comment';
		$jpeg = "\xFF\xD8"
			. "\xFF\xE1" . pack( 'n', strlen( $exif ) + 2 ) . $exif
			. "\xFF\xFE" . pack( 'n', strlen( $com ) + 2 ) . $com
			. "\xFF\xE1" . pack( 'n', strlen( $xmp ) + 2 ) . $xmp
			. "\xFF\xDA\0\x02scan\xFF\xD9";

		$this->assertSame(
			array( array( 6, strlen( $exif ) ), array( 6 + strlen( $exif ) + 4 + strlen( $com ) + 4, strlen( $xmp ) ) ),
			TransparAI_C2PA::allowed_ranges( $jpeg, 'jpeg' )
		);
		$this->assertSame( array(), TransparAI_C2PA::allowed_ranges( (string) file_get_contents( trai_fixture( 'signed.png' ) ), 'png' ) );
	}

	public function test_cbor_decoder_vectors(): void {
		$cases = array(
			array( "\x00", 0 ),
			array( "\x17", 23 ),
			array( "\x18\x64", 100 ),
			array( "\x19\x03\xe8", 1000 ),
			array( "\x1a\x00\x0f\x42\x40", 1000000 ),
			array( "\x20", -1 ),
			array( "\x38\x63", -100 ),
			array( "\x44\x01\x02\x03\x04", "\x01\x02\x03\x04" ),
			array( "\x63\x61\x62\x63", 'abc' ),
			array( "\x83\x01\x02\x03", array( 1, 2, 3 ) ),
			array( "\xa2\x61\x61\x01\x61\x62\x82\x02\x03", array( 'a' => 1, 'b' => array( 2, 3 ) ) ),
			array( "\xf4", false ),
			array( "\xf5", true ),
			array( "\xc0\x74\x32\x30\x31\x33\x2d\x30\x33\x2d\x32\x31\x54\x32\x30\x3a\x30\x34\x3a\x30\x30\x5a", '2013-03-21T20:04:00Z' ),
			array( "\xfb\x3f\xf1\x99\x99\x99\x99\x99\x9a", 1.1 ),
		);
		foreach ( $cases as list( $bytes, $expected ) ) {
			$this->assertSame( $expected, TransparAI_C2PA::cbor_decode( $bytes ), bin2hex( $bytes ) );
		}

		$this->assertNull( TransparAI_C2PA::cbor_decode( "\x9f\x01\xff" ), 'indefinite arrays are rejected' );
		$this->assertNull( TransparAI_C2PA::cbor_decode( "\x5a\xff\xff\xff\xff" ), 'oversized length is rejected' );
		$this->assertNull( TransparAI_C2PA::cbor_decode( '' ) );
		$this->assertNull( TransparAI_C2PA::cbor_decode( str_repeat( "\x81", 40 ) . "\x01" ), 'depth budget' );
	}

	/**
	 * @dataProvider signed_fixtures
	 */
	public function test_own_label_does_not_change_the_verdict( string $fixture, string $format, int $offset ): void {
		unset( $offset );
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'write_iim' => '1' );

		$path = $this->temp_copy( $fixture );
		$this->assertTrue( TransparAI_Writer::write_file( $path, $format, 'generated' ) );
		$this->assertTrue( TransparAI_Writer::file_is_marked( $path ) );

		$info = TransparAI_Detector::c2pa_info( $path );
		$this->assertNotNull( $info );
		$this->assertTrue( $info['own_mark'] );
		$this->assertSame( 'match', $info['hash'], $info['reason'] );

		$this->assertTrue( TransparAI_Writer::remove_file( $path, $format ) );
		$this->assertSame( file_get_contents( trai_fixture( $fixture ) ), file_get_contents( $path ), 'unlabeling restores the signed bytes exactly' );
		TransparAI_Detector::detect_file( $path ); /* A fresh detection clears the memo, as every scan does. */
		$info = TransparAI_Detector::c2pa_info( $path );
		$this->assertFalse( $info['own_mark'] );
		$this->assertSame( 'match', $info['hash'] );
	}

	public function test_detector_labels_signed_declaration_and_downgrades_a_mismatch(): void {
		$path   = $this->temp_copy( 'signed.jpg' );
		$result = TransparAI_Detector::detect_file( $path );
		$this->assertNotNull( $result );
		$this->assertSame( 'c2pa', $result['source'] );
		$this->assertSame( 'generated', $result['type'] );
		$this->assertSame( 'certain', $result['confidence'] );

		$data        = (string) file_get_contents( $path );
		$at          = strlen( $data ) - 3;
		$data[ $at ] = chr( ord( $data[ $at ] ) ^ 0xFF );
		file_put_contents( $path, $data );
		clearstatcache();

		$result = TransparAI_Detector::detect_file( $path );
		$this->assertSame( 'likely', $result['confidence'], 'a manifest that does not cover the bytes goes to review' );
		$this->assertStringContainsString( 'does not match', $result['evidence'] );
	}

	/**
	 * A C2PA 2.4 section A.8 text wrapper around $store, one variation selector per byte.
	 */
	private static function text_wrapper( string $store, int $version = 1, ?int $length = null ): string {
		$bytes = "C2PATXT\0" . chr( $version ) . pack( 'N', $length ?? strlen( $store ) ) . $store;
		$out   = "\xEF\xBB\xBF";
		foreach ( str_split( $bytes ) as $char ) {
			$byte = ord( $char );
			$out .= $byte < 16 ? "\xEF\xB8" . chr( 0x80 + $byte ) : "\xF3\xA0" . chr( 0x84 + ( ( $byte - 16 ) >> 6 ) ) . chr( 0x80 + ( ( $byte - 16 ) & 0x3F ) );
		}
		return $out;
	}

	private function es256_store(): string {
		return (string) TransparAI_C2PA::store_from_data( (string) file_get_contents( trai_fixture( 'alg-es256.jpg' ) ), 'jpeg' );
	}

	public function test_text_wrapper_is_read_and_everything_else_is_text(): void {
		$store = $this->es256_store();
		$this->assertGreaterThan( 100, strlen( $store ) );

		$this->assertSame( $store, TransparAI_C2PA::store_from_text( "Written by a model.\n" . self::text_wrapper( $store ) ) );
		$this->assertSame( $store, TransparAI_C2PA::store_from_data( 'x' . self::text_wrapper( $store ) . "\xEF\xB8\x80\xEF\xB8\x80", 'text' ), 'padding after the store is allowed' );

		$this->assertNull( TransparAI_C2PA::store_from_text( 'Plain text without any marker.' ) );
		$this->assertNull( TransparAI_C2PA::store_from_text( "\xEF\xBB\xBFA text with a BOM and a heart \xE2\x9D\xA4\xEF\xB8\x8F." ), 'emoji selectors are text' );
		$this->assertNull( TransparAI_C2PA::store_from_text( self::text_wrapper( $store, 2 ) ), 'unknown version is text' );

		$this->assertSame( '', TransparAI_C2PA::store_from_text( self::text_wrapper( $store ) . ' and ' . self::text_wrapper( $store ) ), 'two wrappers are ambiguous' );
		$this->assertSame( '', TransparAI_C2PA::store_from_text( self::text_wrapper( $store, 1, strlen( $store ) + 10 ) ), 'length beyond the run' );
		$this->assertSame( '', TransparAI_C2PA::store_from_text( self::text_wrapper( substr( $store, 0, 40 ), 1 ) ), 'LBox does not match the length field' );
	}

	public function test_detector_reads_credentials_in_plain_text(): void {
		$text = "A paragraph from a language model.\n" . self::text_wrapper( $this->es256_store() );

		$path = sys_get_temp_dir() . '/trai-c2pa-' . uniqid() . '.txt';
		file_put_contents( $path, $text );
		$this->temp_files[] = $path;

		$result = TransparAI_Detector::detect_file( $path );
		$this->assertNotNull( $result );
		$this->assertSame( 'c2pa', $result['source'] );
		$this->assertSame( 'generated', $result['type'] );
		$this->assertSame( 'likely', $result['confidence'], 'the store was signed for another asset, its data hash cannot match' );

		$info = TransparAI_Detector::c2pa_info( $path );
		$this->assertNotNull( $info );
		$this->assertNotSame( 'match', $info['hash'] );
		$this->assertSame( 'untrusted', $info['sig'], $info['sig_reason'] );
		$this->assertTrue( TransparAI_C2PA::present( $path ) );

		/* The same bytes under another name are not read as text. */
		$other = sys_get_temp_dir() . '/trai-c2pa-' . uniqid() . '.bin';
		file_put_contents( $other, $text );
		$this->temp_files[] = $other;
		$this->assertFalse( TransparAI_C2PA::present( $other ) );
	}

	public function test_scanner_stores_the_manifest_facts_and_scan_fingerprint(): void {
		global $trai_test_meta;
		$path                               = $this->temp_copy( 'signed.png' );
		$trai_test_meta[70]['_test_file']   = $path;
		$trai_test_options                  = null;

		TransparAI_Scanner::scan_attachment( 70 );

		$stored = TransparAI_Meta::c2pa( 70 );
		$this->assertNotNull( $stored );
		$this->assertSame( 'match', $stored['hash'] );
		$this->assertSame( 'C2PA Signer', $stored['signer_cn'] );
		$this->assertSame( basename( $path ), $stored['file'] );
		$this->assertFalse( TransparAI_Repair::changed_since_scan( 70 ) );

		file_put_contents( $path, 'x', FILE_APPEND );
		clearstatcache();
		$this->assertTrue( TransparAI_Repair::changed_since_scan( 70 ) );

		$this->assertNull( TransparAI_Repair::changed_since_scan( 71 ), 'never scanned: unknown' );
		$this->assertNull( TransparAI_Meta::c2pa( 71 ) );
	}

	public function test_inspect_reports_credentials_per_file(): void {
		global $trai_test_meta;
		$main                                 = $this->temp_copy( 'signed.jpg' );
		$thumb                                = $this->temp_copy( 'base.jpg' );
		$trai_test_meta[72]['_test_file']     = $main;
		$trai_test_meta[72]['_test_metadata'] = array( 'sizes' => array( 'thumbnail' => array( 'file' => basename( $thumb ) ) ) );

		$files = TransparAI_Writer::inspect( 72 )['files'];
		$this->assertCount( 2, $files );
		$this->assertTrue( $files[0]['c2pa'] );
		$this->assertFalse( $files[1]['c2pa'] );
	}
}
