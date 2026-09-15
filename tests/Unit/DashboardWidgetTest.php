<?php
/**
 * WordPress dashboard widgets: the helpers behind the readiness widget (next
 * milestone) and the numbers widget (last scan, human-made count).
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/admin/class-dashboard.php';

final class DashboardWidgetTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_next_milestone_is_the_first_one_still_ahead(): void {
		$next = TransparAI_Dashboard::next_milestone();
		$this->assertNotNull( $next );

		$ahead = array();
		$all   = array();
		foreach ( TransparAI_Compliance::milestones() as $milestone ) {
			$all[] = $milestone['title'];
			if ( strtotime( $milestone['date'] . ' 00:00:00 UTC' ) > time() ) {
				$ahead[] = $milestone['title'];
			}
		}
		$this->assertContains( $next['title'], $all );
		$this->assertNotSame( '', $next['when'] );
		if ( array() === $ahead ) {
			$this->assertTrue( $next['past'], 'All dates passed: the last milestone, marked in force' );
			$this->assertSame( end( $all ), $next['title'] );
		} else {
			$this->assertFalse( $next['past'] );
			$this->assertSame( $ahead[0], $next['title'] );
		}
	}

	public function test_last_event_time_reads_the_newest_matching_entry(): void {
		$this->assertSame( 0, TransparAI_Dashboard::last_event_time( 'scan-finished' ), 'Nothing logged yet' );

		TransparAI_Meta::log_site( 'scan-started' );
		TransparAI_Meta::log_site( 'scan-finished', array( 'mode' => 'library' ) );
		TransparAI_Meta::log_site( 'settings-saved' );

		$log = TransparAI_Meta::site_log();
		$this->assertSame( $log[1]['t'], TransparAI_Dashboard::last_event_time( 'scan-finished' ) );
		$this->assertSame( 0, TransparAI_Dashboard::last_event_time( 'sweep-finished' ) );
	}

	public function test_library_stats_carry_the_human_made_count(): void {
		$stats = TransparAI_Scanner::stats();
		foreach ( array( 'total', 'flagged', 'detected', 'human', 'scanned' ) as $key ) {
			$this->assertArrayHasKey( $key, $stats );
			$this->assertIsInt( $stats[ $key ] );
		}
	}
}
