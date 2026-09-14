<?php
/**
 * Non-AI declaration (camera photo, human digital work): the meta model, the
 * scanner skip, the in-file write that never overrides a foreign declaration,
 * and the front end (structured data always, badge opt-in).
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class HumanLabelTest extends TestCase {

	/** @var string[] */
	private array $temp_files = array();

	protected function setUp(): void {
		trai_test_reset();
		$prop = new ReflectionProperty( TransparAI_Frontend::class, 'rendered_ids' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, array() );
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
	}

	private function temp_copy( string $fixture, string $extension ): string {
		$path = sys_get_temp_dir() . '/trai-human-' . uniqid() . '.' . $extension;
		copy( trai_fixture( $fixture ), $path );
		$this->temp_files[] = $path;
		return $path;
	}

	public function test_sanitize_accepts_short_forms_and_tokens(): void {
		$this->assertSame( 'digitalCapture', TransparAI_Meta::sanitize_human( 'capture' ) );
		$this->assertSame( 'digitalCapture', TransparAI_Meta::sanitize_human( 'DigitalCapture' ) );
		$this->assertSame( 'digitalCreation', TransparAI_Meta::sanitize_human( 'creation' ) );
		$this->assertSame( '', TransparAI_Meta::sanitize_human( 'trainedAlgorithmicMedia' ), 'AI tokens are not a non-AI declaration' );
		$this->assertSame( '', TransparAI_Meta::sanitize_human( null ) );
	}

	public function test_declaration_and_label_exclude_each_other(): void {
		update_post_meta( 5, TransparAI_Meta::KEY_DETECTED, '1' );
		TransparAI_Meta::mark_human( 5, 'capture', 'cli' );

		$this->assertTrue( TransparAI_Meta::is_human( 5 ) );
		$this->assertSame( 'digitalCapture', TransparAI_Meta::human_type( 5 ) );
		$this->assertFalse( TransparAI_Meta::is_detected( 5 ), 'A pending detection is settled by the declaration' );
		$this->assertSame( 'cli', get_post_meta( 5, TransparAI_Meta::KEY_MARKED_BY, true ) );
		$this->assertSame( 'human-capture', TransparAI_Meta::last_change( 5 )['e'] );

		TransparAI_Meta::flag( 5, 'manual' );
		$this->assertFalse( TransparAI_Meta::is_human( 5 ), 'The AI label withdraws the declaration' );

		TransparAI_Meta::mark_human( 5, 'creation' );
		$this->assertFalse( TransparAI_Meta::is_flagged( 5 ), 'The declaration withdraws the AI label' );

		TransparAI_Meta::unmark_human( 5 );
		$this->assertFalse( TransparAI_Meta::is_human( 5 ) );
		$this->assertSame( 'human-removed', TransparAI_Meta::last_change( 5 )['e'] );
	}

	public function test_scanner_never_overrules_a_declaration(): void {
		TransparAI_Meta::mark_human( 9, 'capture' );
		$result = array(
			'is_ai'      => true,
			'type'       => 'generated',
			'source'     => 'c2pa',
			'generator'  => 'OpenAI',
			'confidence' => 'certain',
			'evidence'   => 'x',
		);
		$this->assertSame( 'skipped', TransparAI_Scanner::planned_status( 9, $result ) );
		$this->assertSame( 'skipped', TransparAI_Scanner::apply_result( 9, $result ) );
		$this->assertTrue( TransparAI_Meta::is_human( 9 ) );
	}

	public function test_bulk_and_queries_know_the_declaration(): void {
		$this->assertSame( 2, TransparAI_Meta::bulk_apply( array( 21, 22 ), 'human_capture' ) );
		$this->assertTrue( TransparAI_Meta::is_human( 22 ) );
		$this->assertSame( 1, TransparAI_Meta::bulk_apply( array( 22 ), 'human_remove' ) );
		$this->assertFalse( TransparAI_Meta::is_human( 22 ) );

		$this->assertSame( TransparAI_Meta::KEY_HUMAN, TransparAI_Meta::meta_query( 'human' )[0]['key'] );
		$this->assertCount( 3, TransparAI_Meta::meta_query( 'labeled' ) );
		$this->assertSame( 'human', TransparAI_Meta::audit_row( 21 )['status'] );
		$this->assertSame( 'digitalCapture', TransparAI_Meta::audit_row( 21 )['type'] );
	}

	public function test_declaration_is_written_only_into_silent_files(): void {
		global $trai_test_meta, $trai_test_options;
		$trai_test_options['transparai_settings'] = array(
			'write_xmp'   => '1',
			'write_human' => '1',
		);

		$clean   = $this->temp_copy( 'base.jpg', 'jpg' );
		$foreign = $this->temp_copy( 'xmp-attr.jpg', 'jpg' );

		$trai_test_meta[30]['_test_file']     = $clean;
		$trai_test_meta[30]['_test_metadata'] = array(
			'sizes' => array( 'medium' => array( 'file' => basename( $foreign ) ) ),
		);
		update_post_meta( 30, TransparAI_Meta::KEY_HUMAN, 'digitalCapture' );

		$this->assertSame( 'digitalCapture', TransparAI_Writer::expected_token( 30 ) );
		$stats = TransparAI_Writer::sync_attachment( 30 );

		$this->assertSame( 1, $stats['written'], 'The silent file gets the declaration' );
		$this->assertSame( 1, $stats['skipped'], 'The file with a foreign source type is left alone' );
		$this->assertTrue( TransparAI_Writer::file_is_marked( $clean, 'digitalCapture' ) );
		$this->assertFalse( TransparAI_Writer::file_is_marked( $clean, 'trainedAlgorithmicMedia' ) );
		$this->assertTrue( TransparAI_Writer::file_is_marked( $foreign ), 'Any known token counts without a specific one' );
		$this->assertFalse( TransparAI_Writer::file_is_marked( $foreign, 'digitalCapture' ) );

		/* Withdrawing the declaration removes exactly what we wrote. */
		delete_post_meta( 30, TransparAI_Meta::KEY_HUMAN );
		TransparAI_Writer::sync_attachment( 30 );
		$this->assertFalse( TransparAI_Writer::file_is_marked( $clean ) );

		/* With the option off, nothing is written for a declaration. */
		$trai_test_options['transparai_settings']['write_human'] = '0';
		update_post_meta( 30, TransparAI_Meta::KEY_HUMAN, 'digitalCapture' );
		$this->assertSame( '', TransparAI_Writer::expected_token( 30 ) );
	}

	public function test_front_end_emits_structured_data_without_badge_and_badge_on_request(): void {
		global $trai_test_options, $trai_test_meta;
		$trai_test_options['transparai_settings'] = array(
			'badge_enabled' => '1',
			'schema_output' => '1',
		);
		set_transient( 'transparai_url_map', array() );
		update_post_meta( 40, TransparAI_Meta::KEY_HUMAN, 'digitalCreation' );
		$trai_test_meta[40]['_test_url'] = 'https://example.test/wp-content/uploads/2026/09/drawing.png';

		$tag = '<img class="wp-image-40" src="/wp-content/uploads/2026/09/drawing.png">';
		$this->assertSame( $tag, TransparAI_Frontend::wrap_images( $tag ), 'No badge unless enabled' );

		ob_start();
		TransparAI_Frontend::print_footer_output();
		$out = (string) ob_get_clean();
		preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $out, $m );
		$data = json_decode( $m[1] ?? '', true );
		$this->assertSame( 'https://schema.org/DigitalCreationDigitalSource', $data['@graph'][0]['digitalSourceType'] );
		$this->assertStringEndsWith( 'digitalCreation', $data['@graph'][0]['additionalProperty']['value'] );
		$this->assertArrayNotHasKey( 'creator', $data['@graph'][0] );

		$trai_test_options['transparai_settings']['human_badge']      = '1';
		$trai_test_options['transparai_settings']['human_badge_text'] = 'Drawn by hand';
		$wrapped                                                        = TransparAI_Frontend::wrap_images( $tag );
		$this->assertStringContainsString( 'trai-badge trai-badge--human', $wrapped );
		$this->assertStringContainsString( 'Drawn by hand', $wrapped );
	}
}
