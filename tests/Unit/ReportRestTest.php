<?php
/**
 * Audit trail and API: the previous-state and name fields of the history,
 * the site log cap, the paginated audit rows, the report with its stable
 * document hash, and the REST handlers with their object-level permission.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ReportRestTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_history_records_previous_state_and_name(): void {
		global $trai_test_user, $trai_test_user_name;
		$trai_test_user      = 3;
		$trai_test_user_name = 'Eva Editor';

		update_post_meta( 5, TransparAI_Meta::KEY_DETECTED, '1' );
		TransparAI_Meta::flag( 5, 'manual', 'confirmed' );
		TransparAI_Meta::mark_human( 5, 'capture' );

		$history = TransparAI_Meta::history( 5 );
		$this->assertSame( 'detected', $history[0]['p'], 'Confirmed from a pending detection' );
		$this->assertSame( 'ai', $history[1]['p'], 'Declared human while labeled AI' );
		$this->assertSame( 'Eva Editor', $history[1]['n'] );
		$this->assertStringContainsString( 'human-capture (from ai, manual) by Eva Editor', TransparAI_Meta::history_text( 5 ) );
	}

	public function test_history_keeps_fifty_events(): void {
		for ( $i = 0; $i < 60; $i++ ) {
			TransparAI_Meta::record( 6, 'repaired', 'sweep' );
		}
		$this->assertCount( 50, TransparAI_Meta::history( 6 ) );
	}

	public function test_site_log_is_capped_and_records_settings_keys(): void {
		TransparAI_Meta::log_settings_change( array( 'badge_style' => 'dark' ), array( 'badge_style' => 'light', 'feed_notice' => '1' ) );
		$log = TransparAI_Meta::site_log();
		$this->assertSame( 'settings-saved', $log[0]['e'] );
		$this->assertSame( array( 'badge_style', 'feed_notice' ), $log[0]['d']['keys'] );

		for ( $i = 0; $i < 210; $i++ ) {
			TransparAI_Meta::log_site( 'scan-finished', array( 'mode' => 'all' ) );
		}
		$this->assertCount( 200, TransparAI_Meta::site_log() );
	}

	public function test_audit_rows_paginate_and_report_hash_is_stable(): void {
		global $trai_test_query_posts, $trai_test_query_args, $trai_test_meta;
		$ids = range( 100, 349 ); /* 250 rows: two pages of 200. */
		foreach ( $ids as $id ) {
			update_post_meta( $id, TransparAI_Meta::KEY_FLAG, '1' );
			$trai_test_meta[ $id ]['_wp_attached_file'] = '2026/09/f' . $id . '.jpg';
		}
		$trai_test_query_posts = $ids;

		$rows = TransparAI_Meta::audit_rows( 'flagged' );
		$this->assertCount( 250, $rows );
		$this->assertGreaterThanOrEqual( 2, count( $trai_test_query_args ), 'Fetched in pages, not in one unbounded query' );
		$this->assertSame( 200, $trai_test_query_args[0]['posts_per_page'] );

		$this->assertCount( 10, TransparAI_Meta::audit_rows( 'flagged', 10 ), 'Limit caps the rows' );

		$a = TransparAI_Meta::report( 'all' );
		$b = TransparAI_Meta::report( 'all' );
		$this->assertSame( $a['document_hash'], $b['document_hash'], 'Unchanged site, same hash' );
		$this->assertNotSame( $a['generated_at'], '' );
		$this->assertFalse( $a['truncated'] );
		$this->assertSame( 250, $a['counts']['flagged'] );
		$this->assertNotEmpty( $a['guidance_basis'] );
		$this->assertCount( 4, $a['limitations'] );
		$this->assertArrayHasKey( 'compliance', $a );
		$this->assertArrayHasKey( 'log', $a );

		TransparAI_Meta::unflag( 100 );
		$this->assertNotSame( $a['document_hash'], TransparAI_Meta::report( 'all' )['document_hash'], 'A changed fact changes the hash' );
	}

	public function test_report_truncates_at_the_limit(): void {
		global $trai_test_query_posts;
		$trai_test_query_posts = range( 1, TransparAI_Meta::REPORT_LIMIT + 5 );
		foreach ( $trai_test_query_posts as $id ) {
			update_post_meta( $id, TransparAI_Meta::KEY_FLAG, '1' );
		}
		$report = TransparAI_Meta::report( 'flagged' );
		$this->assertTrue( $report['truncated'] );
		$this->assertCount( TransparAI_Meta::REPORT_LIMIT, $report['items'] );
	}

	public function test_rest_permissions_check_the_object(): void {
		global $trai_test_can, $trai_test_routes;
		TransparAI_REST::register_routes();
		$this->assertArrayHasKey( 'transparai/v1/media', $trai_test_routes );
		$this->assertArrayHasKey( 'transparai/v1/report', $trai_test_routes );
		$this->assertSame( array( 'flag', 'unflag', 'confirm', 'dismiss', 'human_capture', 'human_creation', 'human_remove' ), $trai_test_routes['transparai/v1/media/(?P<id>\d+)'][1]['args']['action']['enum'] );

		$this->assertTrue( TransparAI_REST::can_edit_item( new WP_REST_Request( array( 'id' => 7 ) ) ) );
		$this->assertFalse( TransparAI_REST::can_edit_item( new WP_REST_Request( array( 'id' => 0 ) ) ), 'No object, no access' );
		$trai_test_can = false;
		$this->assertFalse( TransparAI_REST::can_edit_item( new WP_REST_Request( array( 'id' => 7 ) ) ) );
		$this->assertFalse( TransparAI_REST::can_manage_media() );
	}

	public function test_rest_handlers_apply_actions_and_list_pages(): void {
		global $trai_test_query_posts;
		$item = TransparAI_REST::update_media( new WP_REST_Request( array( 'id' => 40, 'action' => 'flag', 'generator' => 'Flux', 'type' => 'composite' ) ) );
		$this->assertSame( 'flagged', $item['status'] );
		$this->assertSame( 'composite', $item['type'] );
		$this->assertSame( 'Flux', $item['generator'] );
		$this->assertSame( 'rest', $item['marked_by'] );
		$this->assertSame( 'flagged', $item['history'][0]['e'] );

		$item = TransparAI_REST::update_media( new WP_REST_Request( array( 'id' => 40, 'action' => 'human_creation' ) ) );
		$this->assertSame( 'human', $item['status'] );
		$this->assertSame( 'digitalCreation', $item['human'] );

		$this->assertInstanceOf( WP_Error::class, TransparAI_REST::update_media( new WP_REST_Request( array( 'id' => 40, 'action' => 'explode' ) ) ) );

		$trai_test_query_posts = array( 40, 41, 42 );
		$list                  = TransparAI_REST::list_media( new WP_REST_Request( array( 'status' => 'all', 'page' => 2, 'per_page' => 2 ) ) );
		$this->assertSame( 3, $list['total'] );
		$this->assertCount( 1, $list['items'] );
		$this->assertSame( 42, $list['items'][0]['ID'] );

		$detail = TransparAI_REST::get_media( new WP_REST_Request( array( 'id' => 40 ) ) );
		$this->assertArrayHasKey( 'files', $detail );
		$this->assertSame( 'digitalCreation', $detail['expected_type'] );
	}
}
