<?php
/**
 * Detection engine tests: every rule has a fixture, every known
 * false-positive trap has a negative probe.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class DetectorTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	/**
	 * @return array<string, array{string, string, string, string}>
	 */
	public function positive_fixtures(): array {
		return array(
			'a1111 parameters chunk'   => array( 'a1111.png', 'png-chunk', 'likely', 'generated' ),
			'a1111 img2img composite'  => array( 'a1111-img2img.png', 'png-chunk', 'likely', 'composite' ),
			'comfyui workflow'         => array( 'comfyui.png', 'png-chunk', 'likely', 'generated' ),
			'novelai software chunk'   => array( 'novelai.png', 'png-chunk', 'likely', 'generated' ),
			'xmp dst element form'     => array( 'xmp-dst.png', 'xmp-dst', 'certain', 'generated' ),
			'xmp dst attribute form'   => array( 'xmp-attr.jpg', 'xmp-dst', 'certain', 'composite' ),
			'xmp dst rdf:li form'      => array( 'xmp-li.jpg', 'xmp-dst', 'certain', 'generated' ),
			'c2pa openai claim'        => array( 'c2pa-openai.png', 'c2pa', 'certain', 'generated' ),
			'c2pa firefly claim jpeg'  => array( 'c2pa.jpg', 'c2pa', 'certain', 'generated' ),
			'c2pa camera only likely'  => array( 'c2pa-camera.png', 'c2pa', 'likely', 'generated' ),
			'midjourney xmp signature' => array( 'midjourney.jpg', 'xmp', 'likely', 'generated' ),
			'iim google credit'        => array( 'iim-google.jpg', 'iim', 'certain', 'generated' ),
			'com segment comfyui'      => array( 'com-comfyui.jpg', 'com', 'likely', 'generated' ),
			'webp xmp dst'             => array( 'xmp.webp', 'xmp-dst', 'certain', 'generated' ),
			'bmff c2pa uuid'           => array( 'c2pa.mp4', 'c2pa', 'certain', 'generated' ),
			'id3 aigc declaration'     => array( 'aigc.mp3', 'id3', 'certain', 'generated' ),
			'c2pa sidecar'             => array( 'sidecar.jpg', 'sidecar', 'likely', 'generated' ),
		);
	}

	/**
	 * @dataProvider positive_fixtures
	 */
	public function test_positive_detection( string $fixture, string $source, string $confidence, string $type ): void {
		$result = TransparAI_Detector::detect_file( trai_fixture( $fixture ) );
		$this->assertNotNull( $result, "Expected a detection for {$fixture}" );
		$this->assertTrue( $result['is_ai'] );
		$this->assertSame( $source, $result['source'], "Source mismatch for {$fixture}: " . $result['evidence'] );
		$this->assertSame( $confidence, $result['confidence'], "Confidence mismatch for {$fixture}" );
		$this->assertSame( $type, $result['type'], "Type mismatch for {$fixture}" );
		$this->assertNotSame( '', $result['evidence'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function negative_fixtures(): array {
		return array(
			'plain png'                     => array( 'base.png' ),
			'plain jpeg'                    => array( 'base.jpg' ),
			'plain webp'                    => array( 'base.webp' ),
			'spanish "imagenes" com text'   => array( 'negative-imagenes.jpg' ),
			'camera xmp without dst'        => array( 'negative-camera.jpg' ),
			'firefly festival / aerospace'  => array( 'negative-firefly.jpg' ),
			'negative-list dst (capture)'   => array( 'dst-negative.png' ),
		);
	}

	/**
	 * @dataProvider negative_fixtures
	 */
	public function test_negative_probes_stay_clean( string $fixture ): void {
		$result = TransparAI_Detector::detect_file( trai_fixture( $fixture ) );
		$this->assertNull( $result, "False positive on {$fixture}: " . wp_json_encode_stub( $result ) );
	}

	public function test_detection_result_filter_can_veto(): void {
		add_filter(
			'transparai_detection_result',
			static function ( $result ) {
				return null;
			}
		);
		$this->assertNull( TransparAI_Detector::detect_file( trai_fixture( 'xmp-dst.png' ) ) );
	}

	public function test_signatures_filter_can_extend(): void {
		add_filter(
			'transparai_signatures',
			static function ( array $signatures ): array {
				$signatures[] = array(
					'pattern'   => '/my custom generator/i',
					'generator' => 'Custom',
				);
				return $signatures;
			}
		);
		// The COM fixture text does not contain the custom marker; the stock rule still wins.
		$result = TransparAI_Detector::detect_file( trai_fixture( 'com-comfyui.jpg' ) );
		$this->assertNotNull( $result );
		$this->assertSame( 'ComfyUI', $result['generator'] );
	}

	public function test_filename_hints_only_when_enabled(): void {
		$dir  = sys_get_temp_dir() . '/trai-test-' . uniqid();
		mkdir( $dir );
		$path = $dir . '/gemini_generated_image_abc123.png';
		copy( trai_fixture( 'base.png' ), $path );

		$this->assertNull( TransparAI_Detector::detect_file( $path ), 'Hints must be off by default' );

		update_option( TransparAI_Options::OPTION, array( 'filename_hints' => '1' ) );
		$result = TransparAI_Detector::detect_file( $path );
		$this->assertNotNull( $result );
		$this->assertSame( 'filename', $result['source'] );
		$this->assertSame( 'hint', $result['confidence'] );

		unlink( $path );
		rmdir( $dir );
	}
}

/**
 * Tiny helper for failure messages (bootstrap has no wp_json_encode).
 *
 * @param mixed $value Value.
 */
function wp_json_encode_stub( $value ): string {
	return (string) json_encode( $value );
}
