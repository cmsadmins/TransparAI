<?php
/**
 * Container parser tests.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ParsersTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_sniff_detects_all_formats(): void {
		$this->assertSame( 'png', TransparAI_Parsers::sniff( (string) file_get_contents( trai_fixture( 'base.png' ) ) ) );
		$this->assertSame( 'jpeg', TransparAI_Parsers::sniff( (string) file_get_contents( trai_fixture( 'base.jpg' ) ) ) );
		$this->assertSame( 'webp', TransparAI_Parsers::sniff( (string) file_get_contents( trai_fixture( 'base.webp' ) ) ) );
		$this->assertSame( 'bmff', TransparAI_Parsers::sniff( (string) file_get_contents( trai_fixture( 'c2pa.mp4' ) ) ) );
		$this->assertSame( 'mp3', TransparAI_Parsers::sniff( (string) file_get_contents( trai_fixture( 'aigc.mp3' ) ) ) );
		$this->assertSame( '', TransparAI_Parsers::sniff( 'this is not a media file at all' ) );
	}

	public function test_jpeg_segments_roundtrip(): void {
		$data     = (string) file_get_contents( trai_fixture( 'midjourney.jpg' ) );
		$segments = TransparAI_Parsers::jpeg_segments( $data );
		$this->assertNotNull( $segments );
		$this->assertSame( $data, TransparAI_Parsers::jpeg_build( $segments ) );
	}

	public function test_jpeg_xmp_extraction(): void {
		$segments = TransparAI_Parsers::jpeg_segments( (string) file_get_contents( trai_fixture( 'xmp-li.jpg' ) ) );
		$this->assertNotNull( $segments );
		$xmp = TransparAI_Parsers::jpeg_xmp( $segments );
		$this->assertNotNull( $xmp );
		$this->assertStringContainsString( 'DigitalSourceType', $xmp );
	}

	public function test_jpeg_comments_and_c2pa(): void {
		$com = TransparAI_Parsers::jpeg_segments( (string) file_get_contents( trai_fixture( 'com-comfyui.jpg' ) ) );
		$this->assertNotNull( $com );
		$comments = TransparAI_Parsers::jpeg_comments( $com );
		$this->assertCount( 1, $comments );
		$this->assertStringContainsString( 'ComfyUI', $comments[0] );

		$c2pa = TransparAI_Parsers::jpeg_segments( (string) file_get_contents( trai_fixture( 'c2pa.jpg' ) ) );
		$this->assertNotNull( $c2pa );
		$this->assertTrue( TransparAI_Parsers::jpeg_has_c2pa( $c2pa ) );
		$this->assertFalse( TransparAI_Parsers::jpeg_has_c2pa( $com ) );
	}

	public function test_png_chunks_roundtrip_and_text_decoding(): void {
		$data   = (string) file_get_contents( trai_fixture( 'novelai.png' ) );
		$chunks = TransparAI_Parsers::png_chunks( $data );
		$this->assertNotNull( $chunks );
		$this->assertSame( $data, TransparAI_Parsers::png_build( $chunks ) );

		$texts = TransparAI_Parsers::png_text_chunks( $chunks );
		$this->assertSame( 'NovelAI', $texts['Software'] );
		// zTXt payloads are inflated transparently.
		$this->assertStringContainsString( '"prompt"', $texts['Comment'] );
	}

	public function test_png_itxt_xmp_and_cabx(): void {
		$xmp_chunks = TransparAI_Parsers::png_chunks( (string) file_get_contents( trai_fixture( 'xmp-dst.png' ) ) );
		$this->assertNotNull( $xmp_chunks );
		$xmp = TransparAI_Parsers::png_xmp( $xmp_chunks );
		$this->assertNotNull( $xmp );
		$this->assertStringContainsString( 'trainedAlgorithmicMedia', $xmp );

		$c2pa_chunks = TransparAI_Parsers::png_chunks( (string) file_get_contents( trai_fixture( 'c2pa-openai.png' ) ) );
		$this->assertNotNull( $c2pa_chunks );
		$this->assertTrue( TransparAI_Parsers::png_has_c2pa( $c2pa_chunks ) );
		$this->assertFalse( TransparAI_Parsers::png_has_c2pa( $xmp_chunks ) );
	}

	public function test_webp_chunks_roundtrip_and_xmp(): void {
		$data   = (string) file_get_contents( trai_fixture( 'xmp.webp' ) );
		$chunks = TransparAI_Parsers::webp_chunks( $data );
		$this->assertNotNull( $chunks );
		$this->assertSame( $data, TransparAI_Parsers::webp_build( $chunks ) );

		$xmp = TransparAI_Parsers::webp_xmp( $chunks );
		$this->assertNotNull( $xmp );
		$this->assertStringContainsString( 'trainedAlgorithmicMedia', $xmp );
	}

	public function test_webp_make_vp8x_derives_dimensions(): void {
		$chunks = TransparAI_Parsers::webp_chunks( (string) file_get_contents( trai_fixture( 'base.webp' ) ) );
		$this->assertNotNull( $chunks );
		$vp8x = TransparAI_Parsers::webp_make_vp8x( $chunks );
		$this->assertNotNull( $vp8x );
		$this->assertSame( 10, strlen( $vp8x ) );
		$this->assertSame( 0x04, ord( $vp8x[0] ) & 0x04 ); // XMP flag set.
	}

	public function test_bmff_scan_finds_c2pa_uuid(): void {
		$scan = TransparAI_Parsers::bmff_scan( (string) file_get_contents( trai_fixture( 'c2pa.mp4' ) ) );
		$this->assertTrue( $scan['c2pa'] );
		$this->assertFalse( $scan['xmp'] );
	}

	public function test_id3_frames_and_txxx_description(): void {
		$frames = TransparAI_Parsers::id3_frames( (string) file_get_contents( trai_fixture( 'aigc.mp3' ) ) );
		$this->assertNotEmpty( $frames );
		$this->assertSame( 'TXXX', $frames[0]['id'] );
		$this->assertSame( 'aigc', TransparAI_Parsers::id3_txxx_description( $frames[0]['data'] ) );
	}

	public function test_syncsafe_decoding(): void {
		$this->assertSame( 0, TransparAI_Parsers::syncsafe( "\x00\x00\x00\x00" ) );
		$this->assertSame( 128, TransparAI_Parsers::syncsafe( "\x00\x00\x01\x00" ) );
		$this->assertSame( 0x0FFFFFFF, TransparAI_Parsers::syncsafe( "\x7F\x7F\x7F\x7F" ) );
	}

	public function test_xmp_digital_source_types_all_three_forms(): void {
		$element = TransparAI_Parsers::xmp_digital_source_types(
			'<Iptc4xmpExt:DigitalSourceType>http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia</Iptc4xmpExt:DigitalSourceType>'
		);
		$this->assertSame( array( 'trainedalgorithmicmedia' ), $element );

		$attribute = TransparAI_Parsers::xmp_digital_source_types(
			'<rdf:Description iptcExt:DigitalSourceType="http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia"/>'
		);
		$this->assertSame( array( 'compositewithtrainedalgorithmicmedia' ), $attribute );

		$list = TransparAI_Parsers::xmp_digital_source_types(
			'<x:DigitalSourceType><rdf:Bag><rdf:li>http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia</rdf:li></rdf:Bag></x:DigitalSourceType>'
		);
		$this->assertSame( array( 'trainedalgorithmicmedia' ), $list );

		$this->assertSame( array(), TransparAI_Parsers::xmp_digital_source_types( '<rdf:Description xmp:CreatorTool="Lightroom"/>' ) );
	}

	public function test_c2pa_claim_generator_extraction(): void {
		$payload = (string) file_get_contents( trai_fixture( 'sidecar.jpg.c2pa' ) );
		$this->assertStringContainsString( 'c2patool', TransparAI_Parsers::c2pa_claim_generator( $payload ) );
		$this->assertSame( '', TransparAI_Parsers::c2pa_claim_generator( 'no key in here' ) );
	}

	public function test_c2pa_v2_claim_generator_info_and_declared_dst(): void {
		$segments = TransparAI_Parsers::jpeg_segments( (string) file_get_contents( trai_fixture( 'c2pa-gemini.jpg' ) ) );
		$this->assertNotNull( $segments );
		$payload = TransparAI_Parsers::jpeg_app11_payload( $segments );
		$this->assertSame( 'Google C2PA Core Generator Library', TransparAI_Parsers::c2pa_claim_generator( $payload ) );
		$this->assertSame( 'generated', TransparAI_Parsers::c2pa_digital_source_type( $payload ) );
		$this->assertSame( '', TransparAI_Parsers::c2pa_digital_source_type( 'plain composite text without the vocabulary path' ) );
	}
}
