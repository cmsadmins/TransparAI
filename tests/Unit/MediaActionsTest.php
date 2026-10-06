<?php
/** Media list actions must be available without opening attachment details. */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/admin/class-media-library.php';

final class MediaActionsTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_unlabeled_media_has_label_and_edit_actions(): void {
		$html = TransparAI_Media_Library::list_actions( 42 );
		$this->assertStringContainsString( 'data-op="flag"', $html );
		$this->assertStringContainsString( 'post=42', $html );
		$this->assertStringContainsString( 'data-id="42"', $html );
		$this->assertStringNotContainsString( 'data-op="unflag"', $html );
	}

	public function test_flagged_media_offers_removal(): void {
		update_post_meta( 42, TransparAI_Meta::KEY_FLAG, '1' );
		$html = TransparAI_Media_Library::list_actions( 42 );
		$this->assertStringContainsString( 'data-op="unflag"', $html );
		$this->assertStringNotContainsString( 'data-op="flag"', $html );
	}

	public function test_detection_offers_confirm_and_dismiss(): void {
		update_post_meta( 42, TransparAI_Meta::KEY_DETECTED, '1' );
		$html = TransparAI_Media_Library::list_actions( 42 );
		$this->assertStringContainsString( 'data-op="confirm"', $html );
		$this->assertStringContainsString( 'data-op="dismiss"', $html );
		$this->assertStringNotContainsString( 'data-op="flag"', $html );
	}

	public function test_read_only_user_has_no_actions(): void {
		$GLOBALS['trai_test_can'] = false;
		$this->assertSame( '', TransparAI_Media_Library::list_actions( 42 ) );
	}

	public function test_documents_do_not_offer_media_label_actions(): void {
		update_post_meta( 42, '_test_mime', 'application/pdf' );
		$this->assertSame( '', TransparAI_Media_Library::list_actions( 42 ) );
	}

	public function test_human_declaration_can_be_removed_or_changed_to_ai(): void {
		update_post_meta( 42, TransparAI_Meta::KEY_HUMAN, TransparAI_Meta::DST_CAPTURE );
		$html = TransparAI_Media_Library::list_actions( 42 );
		$this->assertStringContainsString( 'data-op="human_remove"', $html );
		$this->assertStringContainsString( 'data-op="flag"', $html );
	}

	public function test_file_write_error_remains_visible(): void {
		update_post_meta( 42, TransparAI_Meta::KEY_WRITE_ERROR, 'not_writable' );
		$this->assertStringContainsString( 'trai-write-error', TransparAI_Media_Library::list_actions( 42 ) );
	}
}
