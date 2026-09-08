<?php
/**
 * Meta layer: flag lifecycle, review transitions, bulk permissions,
 * media library query fragments.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class MetaTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_flag_sets_label_and_clears_detection(): void {
		update_post_meta( 5, TransparAI_Meta::KEY_DETECTED, '1' );
		TransparAI_Meta::flag( 5, 'auto' );

		$this->assertTrue( TransparAI_Meta::is_flagged( 5 ) );
		$this->assertFalse( TransparAI_Meta::is_detected( 5 ) );
		$this->assertSame( 'auto', get_post_meta( 5, TransparAI_Meta::KEY_MARKED_BY, true ) );

		TransparAI_Meta::unflag( 5 );
		$this->assertFalse( TransparAI_Meta::is_flagged( 5 ) );
		$this->assertSame( '', get_post_meta( 5, TransparAI_Meta::KEY_MARKED_BY, true ) );
	}

	public function test_confirm_only_acts_on_pending_detections(): void {
		TransparAI_Meta::confirm( 7 );
		$this->assertFalse( TransparAI_Meta::is_flagged( 7 ), 'Nothing pending, nothing confirmed' );

		TransparAI_Meta::queue( 7, array( 'source' => 'c2pa', 'confidence' => 'likely' ) );
		$this->assertTrue( TransparAI_Meta::is_detected( 7 ) );

		TransparAI_Meta::confirm( 7 );
		$this->assertTrue( TransparAI_Meta::is_flagged( 7 ) );
		$this->assertFalse( TransparAI_Meta::is_detected( 7 ) );
	}

	public function test_dismiss_remembers_the_decision(): void {
		TransparAI_Meta::queue( 8, array( 'source' => 'c2pa' ) );
		TransparAI_Meta::dismiss( 8 );

		$this->assertFalse( TransparAI_Meta::is_detected( 8 ) );
		$this->assertSame( '1', get_post_meta( 8, TransparAI_Meta::KEY_DISMISSED, true ) );
	}

	public function test_store_result_sanitizes_and_limits_evidence(): void {
		TransparAI_Meta::store_result(
			9,
			array(
				'type'       => 'composite',
				'source'     => 'png-chunk',
				'generator'  => "  ComfyUI\n",
				'confidence' => 'likely',
				'evidence'   => str_repeat( 'e', 900 ),
			)
		);
		$this->assertSame( 'composite', TransparAI_Meta::get_type( 9 ) );
		$this->assertSame( 'ComfyUI', TransparAI_Meta::get_generator( 9 ) );
		$this->assertSame( 500, mb_strlen( (string) get_post_meta( 9, TransparAI_Meta::KEY_EVIDENCE, true ) ) );
	}

	public function test_get_type_falls_back_to_generated(): void {
		$this->assertSame( 'generated', TransparAI_Meta::get_type( 55 ) );
		update_post_meta( 55, TransparAI_Meta::KEY_TYPE, 'weird' );
		$this->assertSame( 'generated', TransparAI_Meta::get_type( 55 ) );
	}

	public function test_bulk_apply_respects_permissions_and_counts(): void {
		global $trai_test_can;

		$count = TransparAI_Meta::bulk_apply( array( 1, 2, 3 ), 'flag' );
		$this->assertSame( 3, $count );
		$this->assertTrue( TransparAI_Meta::is_flagged( 2 ) );

		$count = TransparAI_Meta::bulk_apply( array( 1, 2 ), 'unknown-op' );
		$this->assertSame( 0, $count, 'Unknown action touches nothing' );

		$trai_test_can = false;
		$count         = TransparAI_Meta::bulk_apply( array( 1, 2, 3 ), 'unflag' );
		$this->assertSame( 0, $count, 'Without edit_post nothing is changed' );
		$this->assertTrue( TransparAI_Meta::is_flagged( 1 ) );
	}

	public function test_meta_query_shapes(): void {
		$this->assertSame( '1', TransparAI_Meta::meta_query( '1' )[0]['value'] );
		$this->assertSame( TransparAI_Meta::KEY_DETECTED, TransparAI_Meta::meta_query( 'detected' )[0]['key'] );
		$all = TransparAI_Meta::meta_query( 'all' );
		$this->assertSame( 'OR', $all['relation'] );
		$this->assertSame( TransparAI_Meta::KEY_FLAG, $all[0]['key'] );
		$this->assertSame( TransparAI_Meta::KEY_DETECTED, $all[1]['key'] );
		$unflagged = TransparAI_Meta::meta_query( '0' );
		$this->assertSame( 'AND', $unflagged['relation'] );
		$this->assertNull( TransparAI_Meta::meta_query( 'nonsense' ) );
	}

	public function test_sanitize_flag_accepts_only_truthy_one(): void {
		$this->assertSame( '1', TransparAI_Meta::sanitize_flag( '1' ) );
		$this->assertSame( '1', TransparAI_Meta::sanitize_flag( 1 ) );
		$this->assertSame( '1', TransparAI_Meta::sanitize_flag( true ) );
		$this->assertSame( '', TransparAI_Meta::sanitize_flag( 'yes' ) );
		$this->assertSame( '', TransparAI_Meta::sanitize_flag( 0 ) );
	}

	public function test_sanitize_badge_pos_accepts_only_known_values(): void {
		foreach ( array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'below', 'hidden' ) as $value ) {
			$this->assertSame( $value, TransparAI_Meta::sanitize_badge_pos( $value ) );
		}
		$this->assertSame( '', TransparAI_Meta::sanitize_badge_pos( 'center' ) );
		$this->assertSame( '', TransparAI_Meta::sanitize_badge_pos( '' ) );
		$this->assertSame( '', TransparAI_Meta::sanitize_badge_pos( null ) );
	}

	public function test_history_records_the_review_lifecycle(): void {
		global $trai_test_user;
		$trai_test_user = 7;

		TransparAI_Meta::queue( 70, array( 'source' => 'c2pa', 'confidence' => 'likely' ) );
		TransparAI_Meta::confirm( 70 );
		TransparAI_Meta::unflag( 70 );

		$events = array_column( TransparAI_Meta::history( 70 ), 'e' );
		$this->assertSame( array( 'queued', 'confirmed', 'unflagged' ), $events );

		$last = TransparAI_Meta::last_change( 70 );
		$this->assertSame( 'unflagged', $last['e'] );
		$this->assertSame( 7, $last['u'], 'The editor who made the change is recorded' );
	}

	public function test_history_keeps_only_the_last_ten_events(): void {
		for ( $i = 0; $i < 14; $i++ ) {
			TransparAI_Meta::record( 71, 'repaired', 'sweep' );
		}

		$this->assertCount( 10, TransparAI_Meta::history( 71 ) );
	}

	public function test_history_survives_a_corrupted_meta_value(): void {
		update_post_meta( 72, TransparAI_Meta::KEY_HISTORY, 'not json' );
		$this->assertSame( array(), TransparAI_Meta::history( 72 ) );

		TransparAI_Meta::record( 72, 'flagged', 'manual' );
		$this->assertCount( 1, TransparAI_Meta::history( 72 ) );
	}

	public function test_audit_row_carries_the_last_change(): void {
		TransparAI_Meta::queue( 73, array( 'source' => 'png-chunk', 'generator' => 'ComfyUI', 'confidence' => 'certain' ) );
		TransparAI_Meta::confirm( 73 );

		$row = TransparAI_Meta::audit_row( 73 );
		$this->assertSame( array_keys( $row ), TransparAI_Meta::audit_columns(), 'Row keys and export columns stay in sync' );
		$this->assertSame( 'flagged', $row['status'] );
		$this->assertSame( 'ComfyUI', $row['generator'] );
		$this->assertSame( 'confirmed', $row['last_event'] );
		$this->assertNotSame( '', $row['last_event_at'] );
	}

	public function test_get_badge_position_revalidates_stored_values(): void {
		$this->assertSame( '', TransparAI_Meta::get_badge_position( 60 ) );

		update_post_meta( 60, TransparAI_Meta::KEY_BADGE_POS, 'sideways' );
		$this->assertSame( '', TransparAI_Meta::get_badge_position( 60 ), 'Corrupted stored value falls back to the site setting' );

		update_post_meta( 60, TransparAI_Meta::KEY_BADGE_POS, 'top-left' );
		$this->assertSame( 'top-left', TransparAI_Meta::get_badge_position( 60 ) );
	}
}
