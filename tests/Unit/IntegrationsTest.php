<?php
/**
 * Context integrations: producer meta matching, the public mark action,
 * manual labels never overwritten.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class IntegrationsTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_mark_flags_with_context_source(): void {
		TransparAI_Integrations::mark( 30, 'My Generator' );

		$this->assertTrue( TransparAI_Meta::is_flagged( 30 ) );
		$this->assertSame( 'context', get_post_meta( 30, TransparAI_Meta::KEY_SOURCE, true ) );
		$this->assertSame( 'My Generator', TransparAI_Meta::get_generator( 30 ) );
		$this->assertSame( 'certain', get_post_meta( 30, TransparAI_Meta::KEY_CONFIDENCE, true ) );
	}

	public function test_mark_never_overwrites_an_existing_label(): void {
		TransparAI_Meta::flag( 31, 'manual' );
		TransparAI_Meta::store_result( 31, array( 'generator' => 'Original' ) );

		TransparAI_Integrations::mark( 31, 'Other Producer' );
		$this->assertSame( 'Original', TransparAI_Meta::get_generator( 31 ), 'Existing label wins' );
	}

	public function test_mark_rejects_invalid_ids(): void {
		TransparAI_Integrations::mark( 0, 'Nobody' );
		$this->assertFalse( TransparAI_Meta::is_flagged( 0 ) );
	}

	public function test_foreign_meta_matching(): void {
		TransparAI_Integrations::on_foreign_meta( 1, 40, '_mwai_purpose', 'generated' );
		$this->assertTrue( TransparAI_Meta::is_flagged( 40 ) );
		$this->assertSame( 'AI Engine', TransparAI_Meta::get_generator( 40 ) );

		TransparAI_Integrations::on_foreign_meta( 1, 41, 'mwai_model', 'dall-e-3' );
		$this->assertSame( 'AI Engine (dall-e-3)', TransparAI_Meta::get_generator( 41 ) );

		TransparAI_Integrations::on_foreign_meta( 1, 42, '_aipkit_generated_image', '1' );
		$this->assertSame( 'AI Power', TransparAI_Meta::get_generator( 42 ) );

		TransparAI_Integrations::on_foreign_meta( 1, 43, 'ai_generated', '1' );
		$this->assertSame( 'WordPress AI', TransparAI_Meta::get_generator( 43 ) );

		TransparAI_Integrations::on_foreign_meta( 1, 44, '_mwai_purpose', 'edited' );
		$this->assertFalse( TransparAI_Meta::is_flagged( 44 ), 'Non-generated purpose is ignored' );

		TransparAI_Integrations::on_foreign_meta( 1, 45, 'unrelated_key', '1' );
		$this->assertFalse( TransparAI_Meta::is_flagged( 45 ) );
	}

	/**
	 * Elementor AI and Divi AI write no meta of their own; the AJAX action
	 * that carries the upload is the only signal.
	 */
	public function test_upload_context_matching(): void {
		global $trai_test_doing_ajax;
		$trai_test_doing_ajax = true;

		$_REQUEST['action'] = 'ai_upload_image';
		TransparAI_Integrations::on_upload_context( 50 );
		$this->assertSame( 'Elementor AI', TransparAI_Meta::get_generator( 50 ) );

		$_REQUEST['action'] = 'et_ai_upload_image';
		TransparAI_Integrations::on_upload_context( 51 );
		$this->assertSame( 'Divi AI', TransparAI_Meta::get_generator( 51 ) );

		$_REQUEST['action'] = 'upload-attachment';
		TransparAI_Integrations::on_upload_context( 52 );
		$this->assertFalse( TransparAI_Meta::is_flagged( 52 ), 'A plain media upload is not a producer' );

		/* Outside an AJAX request the action parameter means nothing. */
		$trai_test_doing_ajax = false;
		$_REQUEST['action']   = 'et_ai_upload_image';
		TransparAI_Integrations::on_upload_context( 53 );
		$this->assertFalse( TransparAI_Meta::is_flagged( 53 ) );

		unset( $_REQUEST['action'] );
	}
}
