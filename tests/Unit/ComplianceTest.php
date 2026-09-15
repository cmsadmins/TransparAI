<?php
/**
 * Compliance core: assessment and checklist storage, readiness factors and
 * score, milestone dates, site-log labels and the report summary.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ComplianceTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_questions_and_checklist_are_well_formed(): void {
		$questions = TransparAI_Compliance::questions();
		$this->assertCount( 6, $questions );
		foreach ( $questions as $id => $question ) {
			$this->assertMatchesRegularExpression( '/^[a-z_]+$/', $id );
			$this->assertNotEmpty( $question['text'] );
			$this->assertNotEmpty( $question['duty'] );
			$this->assertNotEmpty( $question['action'] );
			$this->assertStringStartsWith( 'transparai', $question['page'] );
		}
		$this->assertCount( 5, TransparAI_Compliance::literacy_items() );
	}

	public function test_assessment_keeps_only_known_ids_and_yes_no(): void {
		global $trai_test_user_name;
		$trai_test_user_name = 'Editor';
		TransparAI_Compliance::save_assessment(
			array(
				'chatbot'   => 'yes',
				'ai_text'   => 'maybe',
				'ai_images' => 'no',
				'foreign'   => 'yes',
			)
		);
		$answers = TransparAI_Compliance::assessment();
		$this->assertSame( 'yes', $answers['chatbot'] );
		$this->assertSame( '', $answers['ai_text'], 'Unknown values are dropped' );
		$this->assertSame( 'no', $answers['ai_images'] );
		$this->assertArrayNotHasKey( 'foreign', $answers );
		$this->assertFalse( TransparAI_Compliance::assessment_complete() );
		$this->assertSame( array( 'chatbot' ), array_keys( TransparAI_Compliance::applicable() ) );
		$this->assertSame( 'Editor', TransparAI_Compliance::state()['assessment_by'] );

		$log = TransparAI_Meta::site_log();
		$this->assertSame( 'assessment-saved', $log[ count( $log ) - 1 ]['e'] );
		$this->assertSame( 2, $log[ count( $log ) - 1 ]['d']['answered'] );
	}

	public function test_literacy_round_trip_and_completeness(): void {
		TransparAI_Compliance::save_literacy(
			array(
				'staff_informed' => '1',
				'unknown_item'   => '1',
			)
		);
		$this->assertSame( 1, TransparAI_Compliance::literacy_done_count() );
		$this->assertFalse( TransparAI_Compliance::literacy_complete() );
		$this->assertArrayNotHasKey( 'unknown_item', TransparAI_Compliance::literacy() );

		$all = array_fill_keys( array_keys( TransparAI_Compliance::literacy_items() ), '1' );
		TransparAI_Compliance::save_literacy( $all );
		$this->assertTrue( TransparAI_Compliance::literacy_complete() );

		TransparAI_Compliance::save_literacy( array() );
		$this->assertSame( 0, TransparAI_Compliance::literacy_done_count(), 'Missing ids mean unchecked' );
	}

	public function test_score_starts_at_zero_and_factors_flip_on_decisions(): void {
		global $trai_test_options, $trai_test_query_posts;
		$this->assertSame( 0, TransparAI_Compliance::score() );
		$this->assertSame( 'action', TransparAI_Compliance::traffic( 0 ) );

		$by_id = static function (): array {
			$out = array();
			foreach ( TransparAI_Compliance::factors() as $factor ) {
				$out[ $factor['id'] ] = $factor['met'];
			}
			return $out;
		};

		$this->assertFalse( $by_id()['text'] );
		TransparAI_Compliance::save_assessment( array( 'ai_text' => 'no' ) );
		$this->assertTrue( $by_id()['text'], 'Declaring "no AI text" satisfies the text check' );

		TransparAI_Compliance::save_assessment( array( 'ai_text' => 'yes' ) );
		$this->assertFalse( $by_id()['text'] );
		$trai_test_query_posts = array( 7 );
		$this->assertTrue( $by_id()['text'], 'A post with an AI level satisfies the text check' );
		$this->assertSame( 1, TransparAI_Compliance::count_level( TransparAI_Compliance::AI_VALUES ) );

		$this->assertFalse( $by_id()['chatbot'] );
		$trai_test_options['transparai_settings'] = array( 'chatbot_answer' => 'no' );
		$this->assertTrue( $by_id()['chatbot'] );

		$this->assertFalse( $by_id()['article4'] );
		TransparAI_Compliance::save_assessment( array_fill_keys( array_keys( TransparAI_Compliance::questions() ), 'no' ) );
		TransparAI_Compliance::save_literacy( array_fill_keys( array_keys( TransparAI_Compliance::literacy_items() ), '1' ) );
		$this->assertTrue( $by_id()['article4'] );

		$score = TransparAI_Compliance::score();
		$this->assertGreaterThanOrEqual( 50, $score );
		$this->assertLessThanOrEqual( 100, $score );
	}

	public function test_traffic_light_thresholds(): void {
		$this->assertSame( 'good', TransparAI_Compliance::traffic( 80 ) );
		$this->assertSame( 'good', TransparAI_Compliance::traffic( 100 ) );
		$this->assertSame( 'attention', TransparAI_Compliance::traffic( 79 ) );
		$this->assertSame( 'attention', TransparAI_Compliance::traffic( 50 ) );
		$this->assertSame( 'action', TransparAI_Compliance::traffic( 49 ) );
		$this->assertNotSame( '', TransparAI_Compliance::traffic_label( 'good' ) );
	}

	public function test_milestones_are_sorted_and_carry_the_adopted_dates(): void {
		$dates = array_column( TransparAI_Compliance::milestones(), 'date' );
		$sorted = $dates;
		sort( $sorted );
		$this->assertSame( $sorted, $dates );
		$this->assertContains( '2025-02-02', $dates, 'Article 4 literacy and prohibited practices' );
		$this->assertContains( '2026-08-02', $dates, 'General application including Article 50' );
		$this->assertContains( '2027-08-02', $dates, 'Annex I high-risk' );
		foreach ( TransparAI_Compliance::milestones() as $milestone ) {
			$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $milestone['date'] );
			$this->assertNotEmpty( $milestone['title'] );
		}
	}

	public function test_every_logged_event_has_a_label(): void {
		$labels = TransparAI_Compliance::event_labels();
		$root   = dirname( __DIR__, 2 );
		$events = array();
		foreach ( array( 'includes', 'admin' ) as $dir ) {
			foreach ( (array) glob( $root . '/' . $dir . '/*.php' ) as $file ) {
				/* Dynamic ids ('bulk-' . $action, 'setup-' . $step) end in a dash and are listed below. */
				preg_match_all( "/log_site\(\s*'([a-z-]*[a-z])'/", (string) file_get_contents( (string) $file ), $m );
				foreach ( $m[1] as $event ) {
					$events[] = $event;
				}
			}
		}
		$this->assertNotEmpty( $events );
		foreach ( array_unique( $events ) as $event ) {
			$this->assertArrayHasKey( $event, $labels, 'No label for site-log event ' . $event );
		}
		/* Dynamic ids: bulk actions and setup steps. */
		foreach ( array( 'bulk-flag', 'bulk-unflag', 'bulk-confirm', 'bulk-dismiss', 'bulk-human_capture', 'bulk-human_creation', 'bulk-human_remove', 'setup-badge', 'setup-writing' ) as $event ) {
			$this->assertArrayHasKey( $event, $labels );
		}
		$this->assertSame( 'some-new-thing', TransparAI_Compliance::event_label( 'some-new-thing' ), 'Unknown ids pass through' );
	}

	public function test_summary_and_content_counts_have_every_key(): void {
		$counts = TransparAI_Compliance::content_counts();
		foreach ( TransparAI_Meta::CONTENT_LEVELS as $level ) {
			$this->assertSame( 0, $counts[ $level ] );
		}
		$this->assertSame( 0, $counts['ai'] );

		$summary = TransparAI_Compliance::summary();
		foreach ( array( 'score', 'traffic', 'factors', 'assessment', 'literacy', 'systems', 'content', 'notices' ) as $key ) {
			$this->assertArrayHasKey( $key, $summary );
		}
		$this->assertIsInt( $summary['score'] );
		$this->assertSame( '', $summary['assessment']['at'], 'Nothing saved, no timestamp' );
		$this->assertSame( 5, $summary['literacy']['total'] );
	}
}
