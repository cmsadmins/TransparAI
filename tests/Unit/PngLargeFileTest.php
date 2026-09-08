<?php
/**
 * PNG allows metadata chunks after the image data, and generated images are
 * routinely larger than the head buffer, so the declaration of a large file
 * sits outside the first READ_BYTES. Reading only the head missed it.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class PngLargeFileTest extends TestCase {

	/** @var string */
	private $dir = '';

	protected function setUp(): void {
		trai_test_reset();
		$this->dir = sys_get_temp_dir() . '/trai-png-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	private function chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * A PNG whose XMP packet sits behind $padding bytes of filler.
	 */
	private function png_with_trailing_xmp( int $padding ): string {
		$xmp = '<?xpacket begin="" id="W5M0MpCehiHzreSzNTczkc9d"?>'
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
			. '<rdf:Description rdf:about="" xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/">'
			. '<Iptc4xmpExt:DigitalSourceType>http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia</Iptc4xmpExt:DigitalSourceType>'
			. '</rdf:Description></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

		$png  = TransparAI_Parsers::PNG_SIGNATURE;
		$png .= $this->chunk( 'IHDR', pack( 'NNCCCCC', 1, 1, 8, 2, 0, 0, 0 ) );
		$png .= $this->chunk( 'IDAT', (string) gzcompress( "\x00\xff\x00\x00" ) );
		$png .= $this->chunk( 'tEXt', "Comment\x00" . str_repeat( 'x', $padding ) );
		$png .= $this->chunk( 'iTXt', "XML:com.adobe.xmp\x00\x00\x00\x00\x00" . $xmp );
		$png .= $this->chunk( 'IEND', '' );

		$path = $this->dir . '/trailing-' . $padding . '.png';
		file_put_contents( $path, $png );
		return $path;
	}

	public function test_declaration_behind_the_head_buffer_is_found(): void {
		$path = $this->png_with_trailing_xmp( TransparAI_Parsers::READ_BYTES + 100000 );
		$this->assertGreaterThan( TransparAI_Parsers::READ_BYTES, filesize( $path ) );

		$result = TransparAI_Detector::detect_file( $path );
		$this->assertNotNull( $result, 'XMP behind READ_BYTES must still be detected' );
		$this->assertSame( 'xmp-dst', $result['source'] );
		$this->assertSame( 'certain', $result['confidence'] );
	}

	public function test_small_file_still_detected(): void {
		$result = TransparAI_Detector::detect_file( $this->png_with_trailing_xmp( 10 ) );
		$this->assertNotNull( $result );
		$this->assertSame( 'xmp-dst', $result['source'] );
	}

	public function test_image_data_is_never_buffered(): void {
		$path  = $this->png_with_trailing_xmp( TransparAI_Parsers::READ_BYTES + 100000 );
		$chunks = TransparAI_Parsers::png_metadata_chunks( $path );
		$this->assertNotNull( $chunks );
		$this->assertSame( array(), array_filter( $chunks, static fn( $c ) => 'IDAT' === $c['type'] ) );
	}

	public function test_non_png_returns_null(): void {
		$path = $this->dir . '/not.png';
		file_put_contents( $path, 'plain text' );
		$this->assertNull( TransparAI_Parsers::png_metadata_chunks( $path ) );
		$this->assertNull( TransparAI_Parsers::png_metadata_chunks( $this->dir . '/missing.png' ) );
	}
}
