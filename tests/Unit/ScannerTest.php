<?php
/**
 * Scanner: confidence-to-action matrix, manual decisions winning over
 * automation, the full scan path against real fixture files, mime gating.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ScannerTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	private function result( string $confidence ): array {
		return array(
			'is_ai'      => true,
			'type'       => 'generated',
			'source'     => 'c2pa',
			'generator'  => 'TestGen',
			'confidence' => $confidence,
			'evidence'   => 'unit',
		);
	}

	public function test_certain_flags_by_default_and_likely_queues(): void {
		$this->assertSame( 'flagged', TransparAI_Scanner::apply_result( 10, $this->result( 'certain' ) ) );
		$this->assertTrue( TransparAI_Meta::is_flagged( 10 ) );

		$this->assertSame( 'queued', TransparAI_Scanner::apply_result( 11, $this->result( 'likely' ) ) );
		$this->assertTrue( TransparAI_Meta::is_detected( 11 ) );
		$this->assertFalse( TransparAI_Meta::is_flagged( 11 ) );
	}

	public function test_hint_always_queues_even_when_modes_flag(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array(
			'mode_certain' => 'flag',
			'mode_likely'  => 'flag',
		);
		$this->assertSame( 'queued', TransparAI_Scanner::apply_result( 12, $this->result( 'hint' ) ) );
	}

	public function test_off_mode_skips(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'mode_likely' => 'off' );
		$this->assertSame( 'skipped', TransparAI_Scanner::apply_result( 13, $this->result( 'likely' ) ) );
		$this->assertFalse( TransparAI_Meta::is_detected( 13 ) );
	}

	public function test_manual_decisions_win(): void {
		TransparAI_Meta::flag( 14, 'manual' );
		$this->assertSame( 'skipped', TransparAI_Scanner::apply_result( 14, $this->result( 'certain' ) ) );
		$this->assertSame( 'manual', get_post_meta( 14, TransparAI_Meta::KEY_MARKED_BY, true ), 'Existing label untouched' );

		TransparAI_Meta::dismiss( 15 );
		$this->assertSame( 'skipped', TransparAI_Scanner::apply_result( 15, $this->result( 'certain' ) ) );
		$this->assertFalse( TransparAI_Meta::is_flagged( 15 ) );
	}

	public function test_scan_attachment_full_path_against_fixture(): void {
		global $trai_test_meta;
		// Declared IPTC digital source type -> certain -> auto flag.
		$trai_test_meta[20]['_test_file'] = trai_fixture( 'xmp-dst.png' );

		$scan = TransparAI_Scanner::scan_attachment( 20 );
		$this->assertSame( 'flagged', $scan['status'] );
		$this->assertTrue( TransparAI_Meta::is_flagged( 20 ) );
		$this->assertNotSame( '', get_post_meta( 20, TransparAI_Meta::KEY_SCANNED, true ) );

		// Clean file stays clean.
		$trai_test_meta[21]['_test_file'] = trai_fixture( 'negative-imagenes.jpg' );
		$this->assertSame( 'clean', TransparAI_Scanner::scan_attachment( 21 )['status'] );
		$this->assertFalse( TransparAI_Meta::is_flagged( 21 ) );
	}

	public function test_scan_falls_back_to_the_pre_scale_original(): void {
		global $trai_test_meta;
		// Attached "-scaled" file was re-encoded/optimized and carries nothing;
		// the untouched pre-scale original still declares its AI origin.
		$trai_test_meta[30]['_test_file']          = trai_fixture( 'base.jpg' );
		$trai_test_meta[30]['_test_original_path'] = trai_fixture( 'c2pa-gemini.jpg' );

		$scan = TransparAI_Scanner::scan_attachment( 30 );
		$this->assertSame( 'flagged', $scan['status'] );
		$this->assertStringContainsString( 'pre-scale original', $scan['result']['evidence'] );

		// Without an original the attached file's result stands.
		$trai_test_meta[31]['_test_file'] = trai_fixture( 'base.jpg' );
		$this->assertSame( 'clean', TransparAI_Scanner::scan_attachment( 31 )['status'] );
	}

	public function test_scan_attachment_missing_file_is_unreadable(): void {
		global $trai_test_meta;
		$trai_test_meta[22]['_test_file'] = '/nonexistent/nowhere.jpg';

		$this->assertSame( 'unreadable', TransparAI_Scanner::scan_attachment( 22 )['status'] );
		$this->assertSame( 'missing_file', get_post_meta( 22, TransparAI_Meta::KEY_UNREADABLE, true ) );
	}

	public function test_tally_keeps_skipped_out_of_the_clean_bucket(): void {
		$stats = TransparAI_Scanner::empty_stats();
		foreach ( array( 'flagged', 'queued', 'skipped', 'clean', 'unreadable', 'something-new' ) as $status ) {
			$stats = TransparAI_Scanner::tally( $stats, $status );
		}

		$this->assertSame( 6, $stats['processed'] );
		$this->assertSame( 1, $stats['skipped'], 'A skipped attachment is not a clean one' );
		$this->assertSame( 2, $stats['clean'], 'Only the clean and the unknown status land in clean' );
		$this->assertSame( 1, $stats['flagged'] );
		$this->assertSame( 1, $stats['queued'] );
		$this->assertSame( 1, $stats['unreadable'] );
	}

	public function test_planned_status_matches_apply_result_without_writing(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'mode_likely' => 'off' );

		$this->assertSame( 'skipped', TransparAI_Scanner::planned_status( 30, $this->result( 'likely' ) ) );
		$this->assertSame( 'flagged', TransparAI_Scanner::planned_status( 30, $this->result( 'certain' ) ) );
		$this->assertFalse( TransparAI_Meta::is_flagged( 30 ), 'A preview never writes' );
		$this->assertSame( '', get_post_meta( 30, TransparAI_Meta::KEY_SCANNED, true ) );

		// The real run must agree with what the preview announced.
		$this->assertSame( 'flagged', TransparAI_Scanner::apply_result( 30, $this->result( 'certain' ) ) );
	}

	public function test_mime_types_follow_av_setting(): void {
		global $trai_test_options;
		$this->assertContains( 'video/mp4', TransparAI_Scanner::mime_types(), 'AV scanning is on by default' );

		$trai_test_options['transparai_settings'] = array( 'detect_av' => '0' );
		$this->assertNotContains( 'video/mp4', TransparAI_Scanner::mime_types() );
		$this->assertContains( 'image/avif', TransparAI_Scanner::mime_types() );
	}
}
