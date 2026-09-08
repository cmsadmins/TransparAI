<?php
/**
 * Delivery check: does the declaration we wrote reach the visitor?
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase {

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

	/**
	 * A labeled attachment whose file carries the declaration.
	 */
	private function marked_attachment( int $id, string $url = 'https://example.test/wp-content/uploads/ai.jpg' ): string {
		global $trai_test_meta;

		$path = sys_get_temp_dir() . '/trai-delivery-' . uniqid() . '.jpg';
		copy( trai_fixture( 'base.jpg' ), $path );
		$this->temp_files[] = $path;

		TransparAI_Writer::write_file( $path, 'jpeg', 'generated' );

		$trai_test_meta[ $id ]['_test_file'] = $path;
		$trai_test_meta[ $id ]['_test_url']  = $url;

		return $path;
	}

	private function respond_with( string $body, int $code = 200 ): void {
		global $trai_test_http;
		$trai_test_http = array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	public function test_intact_when_the_delivered_bytes_still_carry_the_declaration(): void {
		$path = $this->marked_attachment( 90 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$this->respond_with( (string) file_get_contents( $path ) );

		$result = TransparAI_Delivery::check( 90 );
		$this->assertSame( TransparAI_Delivery::VERDICT_INTACT, $result['verdict'] );
	}

	public function test_stripped_when_the_cdn_re_encoded_the_image(): void {
		$this->marked_attachment( 91 );
		// A re-encoder returns a valid image without any metadata block.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$this->respond_with( (string) file_get_contents( trai_fixture( 'base.jpg' ) ) );

		$result = TransparAI_Delivery::check( 91 );
		$this->assertSame( TransparAI_Delivery::VERDICT_STRIPPED, $result['verdict'] );
	}

	public function test_unreachable_on_a_transport_error(): void {
		global $trai_test_http;
		$this->marked_attachment( 92 );
		$trai_test_http = new WP_Error( 'http_request_failed', 'Connection refused' );

		$result = TransparAI_Delivery::check( 92 );
		$this->assertSame( TransparAI_Delivery::VERDICT_UNREACHABLE, $result['verdict'] );
		$this->assertSame( 'Connection refused', $result['message'] );
	}

	public function test_unreachable_on_an_error_response(): void {
		$this->marked_attachment( 93 );
		$this->respond_with( '', 403 );

		$this->assertSame( TransparAI_Delivery::VERDICT_UNREACHABLE, TransparAI_Delivery::check( 93 )['verdict'] );
	}

	public function test_media_on_another_host_is_never_requested(): void {
		global $trai_test_http;
		$this->marked_attachment( 94, 'https://cdn.example.com/uploads/ai.jpg' );
		$this->respond_with( 'should never be read' );

		$this->assertSame( TransparAI_Delivery::VERDICT_FOREIGN, TransparAI_Delivery::check( 94 )['verdict'] );
	}

	public function test_unmarked_file_has_nothing_to_compare(): void {
		global $trai_test_meta;

		$path = sys_get_temp_dir() . '/trai-delivery-' . uniqid() . '.jpg';
		copy( trai_fixture( 'base.jpg' ), $path );
		$this->temp_files[] = $path;

		$trai_test_meta[95]['_test_file'] = $path;
		$trai_test_meta[95]['_test_url']  = 'https://example.test/wp-content/uploads/plain.jpg';

		$this->assertSame( TransparAI_Delivery::VERDICT_UNMARKED, TransparAI_Delivery::check( 95 )['verdict'] );
	}

	public function test_a_verdict_is_stored_and_shows_up_in_the_history(): void {
		$path = $this->marked_attachment( 96 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test fixture.
		$this->respond_with( (string) file_get_contents( $path ) );

		TransparAI_Delivery::remember( 96, TransparAI_Delivery::check( 96 )['verdict'] );

		$stored = TransparAI_Delivery::last_result( 96 );
		$this->assertSame( TransparAI_Delivery::VERDICT_INTACT, $stored['verdict'] );
		$this->assertSame( 'delivery-intact', TransparAI_Meta::last_change( 96 )['e'] );
	}
}
