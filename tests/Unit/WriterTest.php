<?php
/**
 * Writer tests: XMP/IIM writing, merging into foreign packets, clean removal,
 * structural validity after every write.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase {

	/**
	 * Temp files to clean up.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	protected function setUp(): void {
		trai_test_reset();
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();
	}

	private function temp_copy( string $fixture, string $extension ): string {
		$path = sys_get_temp_dir() . '/trai-writer-' . uniqid() . '.' . $extension;
		copy( trai_fixture( $fixture ), $path );
		$this->temp_files[] = $path;
		return $path;
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public function formats(): array {
		return array(
			'jpeg' => array( 'base.jpg', 'jpeg', 'jpg' ),
			'png'  => array( 'base.png', 'png', 'png' ),
			'webp' => array( 'base.webp', 'webp', 'webp' ),
			'avif' => array( 'base.avif', 'avif', 'avif' ),
		);
	}

	public function test_inspect_reports_the_state_of_every_file(): void {
		global $trai_test_meta;

		$main  = $this->temp_copy( 'base.jpg', 'jpg' );
		$extra = $this->temp_copy( 'base.png', 'png' );

		$trai_test_meta[80]['_test_file']     = $main;
		$trai_test_meta[80]['_test_metadata'] = array(
			'sizes' => array( 'medium' => array( 'file' => basename( $extra ) ) ),
		);

		$before = TransparAI_Writer::inspect( 80 );
		$this->assertCount( 2, $before['files'] );
		$this->assertSame( array(), $before['terms'], 'A plain photo declares nothing' );
		$this->assertFalse( $before['files'][0]['marked'] );

		TransparAI_Writer::write_file( $main, 'jpeg', 'generated' );

		$after = TransparAI_Writer::inspect( 80 );
		$this->assertTrue( $after['files'][0]['marked'] );
		$this->assertContains( 'trainedalgorithmicmedia', $after['terms'] );
		$this->assertStringContainsString( 'DigitalSourceType', $after['xmp'] );
	}

	public function test_avif_merge_preserves_foreign_xmp(): void {
		$path = $this->temp_copy( 'foreign-xmp.avif', 'avif' );

		$this->assertTrue( TransparAI_Writer::write_file( $path, 'avif', 'generated' ) );
		$this->assertTrue( TransparAI_Writer::file_is_marked( $path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$xmp = TransparAI_Parsers::bmff_xmp( (string) file_get_contents( $path ) );
		$this->assertNotNull( $xmp );
		$this->assertStringContainsString( 'Darktable 5.2', $xmp, 'Foreign creator tool must survive the merge' );

		$this->assertTrue( TransparAI_Writer::remove_file( $path, 'avif' ) );
		$this->assertFalse( TransparAI_Writer::file_is_marked( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$xmp = TransparAI_Parsers::bmff_xmp( (string) file_get_contents( $path ) );
		$this->assertNotNull( $xmp, 'Foreign XMP box must remain after removing our block' );
		$this->assertStringContainsString( 'Darktable 5.2', $xmp );
	}

	/**
	 * @dataProvider formats
	 */
	public function test_write_then_remove_roundtrip( string $fixture, string $format, string $extension ): void {
		$path = $this->temp_copy( $fixture, $extension );

		$this->assertFalse( TransparAI_Writer::file_is_marked( $path ) );

		$this->assertTrue( TransparAI_Writer::write_file( $path, $format, 'generated' ) );
		$this->assertTrue( TransparAI_Writer::file_is_marked( $path ), "Mark missing after write ({$format})" );
		$this->assert_file_intact( $path, $format, "after write ({$format})" );

		// Writing twice must be a no-op that keeps the file valid.
		$this->assertTrue( TransparAI_Writer::write_file( $path, $format, 'generated' ) );
		$this->assertTrue( TransparAI_Writer::file_is_marked( $path ) );

		$this->assertTrue( TransparAI_Writer::remove_file( $path, $format ) );
		$this->assertFalse( TransparAI_Writer::file_is_marked( $path ), "Mark still present after remove ({$format})" );
		$this->assert_file_intact( $path, $format, "after remove ({$format})" );
	}

	/**
	 * Structural validity: getimagesize() for the classic formats; AVIF is a
	 * structural BMFF shell in the fixtures (getimagesize has no AVIF support
	 * on older PHP anyway), so it is validated by re-sniffing the box chain.
	 */
	private function assert_file_intact( string $path, string $format, string $context ): void {
		if ( 'avif' === $format ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
			$data = (string) file_get_contents( $path );
			$this->assertSame( 'ftyp', substr( $data, 4, 4 ), "Broken BMFF {$context}" );
			$this->assertSame( 'bmff', TransparAI_Parsers::sniff( substr( $data, 0, 64 ) ), "Unsniffable {$context}" );
			return;
		}
		$this->assertNotFalse( getimagesize( $path ), "File corrupt {$context}" );
	}

	public function test_composite_type_writes_composite_uri(): void {
		$path = $this->temp_copy( 'base.png', 'png' );
		$this->assertTrue( TransparAI_Writer::write_file( $path, 'png', 'composite' ) );

		$chunks = TransparAI_Parsers::png_chunks( (string) file_get_contents( $path ) );
		$this->assertNotNull( $chunks );
		$xmp = TransparAI_Parsers::png_xmp( $chunks );
		$this->assertNotNull( $xmp );
		$this->assertStringContainsString( 'compositeWithTrainedAlgorithmicMedia', $xmp );
	}

	public function test_merge_preserves_foreign_xmp(): void {
		$path = $this->temp_copy( 'negative-camera.jpg', 'jpg' );

		$this->assertTrue( TransparAI_Writer::write_file( $path, 'jpeg', 'generated' ) );
		$this->assertTrue( TransparAI_Writer::file_is_marked( $path ) );

		$segments = TransparAI_Parsers::jpeg_segments( (string) file_get_contents( $path ) );
		$this->assertNotNull( $segments );
		$xmp = TransparAI_Parsers::jpeg_xmp( $segments );
		$this->assertNotNull( $xmp );
		$this->assertStringContainsString( 'Adobe Lightroom', $xmp, 'Foreign XMP content must survive the merge' );
		$this->assertStringContainsString( 'urn:transparai:dst', $xmp, 'Own block must be marked' );

		// Removal strips exactly our block; the foreign packet stays.
		$this->assertTrue( TransparAI_Writer::remove_file( $path, 'jpeg' ) );
		$segments = TransparAI_Parsers::jpeg_segments( (string) file_get_contents( $path ) );
		$this->assertNotNull( $segments );
		$xmp = TransparAI_Parsers::jpeg_xmp( $segments );
		$this->assertNotNull( $xmp, 'Foreign packet must not be deleted' );
		$this->assertStringContainsString( 'Adobe Lightroom', $xmp );
		$this->assertStringNotContainsString( 'urn:transparai:dst', $xmp );
		$this->assertFalse( TransparAI_Writer::file_is_marked( $path ) );
	}

	public function test_existing_ai_declaration_is_kept_untouched(): void {
		$path   = $this->temp_copy( 'xmp-dst.png', 'png' );
		$before = (string) file_get_contents( $path );

		$this->assertTrue( TransparAI_Writer::write_file( $path, 'png', 'generated' ) );
		$this->assertSame( $before, (string) file_get_contents( $path ), 'A file that already declares AI origin must not be rewritten' );

		// Removal must NOT strip a foreign declaration either.
		$this->assertTrue( TransparAI_Writer::remove_file( $path, 'png' ) );
		$this->assertSame( $before, (string) file_get_contents( $path ) );
		$this->assertTrue( TransparAI_Writer::file_is_marked( $path ) );
	}

	public function test_jpeg_iim_mirror_only_without_foreign_app13(): void {
		// Fresh JPEG: IIM mirror gets written.
		$path = $this->temp_copy( 'base.jpg', 'jpg' );
		$this->assertTrue( TransparAI_Writer::write_file( $path, 'jpeg', 'generated' ) );
		$segments = TransparAI_Parsers::jpeg_segments( (string) file_get_contents( $path ) );
		$this->assertNotNull( $segments );
		$this->assertTrue( TransparAI_Parsers::jpeg_has_app13( $segments ) );

		// Removal drops our own APP13 again.
		$this->assertTrue( TransparAI_Writer::remove_file( $path, 'jpeg' ) );
		$segments = TransparAI_Parsers::jpeg_segments( (string) file_get_contents( $path ) );
		$this->assertNotNull( $segments );
		$this->assertFalse( TransparAI_Parsers::jpeg_has_app13( $segments ) );

		// JPEG with a foreign APP13 (Google credit): never touched.
		$foreign = $this->temp_copy( 'iim-google.jpg', 'jpg' );
		$before  = (string) file_get_contents( $foreign );
		$this->assertTrue( TransparAI_Writer::write_file( $foreign, 'jpeg', 'generated' ) );
		$after_segments = TransparAI_Parsers::jpeg_segments( (string) file_get_contents( $foreign ) );
		$this->assertNotNull( $after_segments );
		$app13_count = 0;
		foreach ( $after_segments as $segment ) {
			if ( 0xED === $segment['marker'] ) {
				++$app13_count;
			}
		}
		$this->assertSame( 1, $app13_count, 'Foreign APP13 must stay the only IIM block' );
		$this->assertStringContainsString( 'Made with Google AI', (string) file_get_contents( $foreign ) );
		$this->assertNotSame( $before, (string) file_get_contents( $foreign ), 'XMP must still have been added' );
	}

	public function test_webp_write_creates_vp8x_with_xmp_flag(): void {
		$path = $this->temp_copy( 'base.webp', 'webp' );
		$this->assertTrue( TransparAI_Writer::write_file( $path, 'webp', 'generated' ) );

		$chunks = TransparAI_Parsers::webp_chunks( (string) file_get_contents( $path ) );
		$this->assertNotNull( $chunks );
		$this->assertSame( 'VP8X', $chunks[0]['fourcc'] );
		$this->assertSame( 0x04, ord( $chunks[0]['data'][0] ) & 0x04 );

		$this->assertTrue( TransparAI_Writer::remove_file( $path, 'webp' ) );
		$chunks = TransparAI_Parsers::webp_chunks( (string) file_get_contents( $path ) );
		$this->assertNotNull( $chunks );
		foreach ( $chunks as $chunk ) {
			$this->assertNotSame( 'XMP ', $chunk['fourcc'] );
		}
	}
}
