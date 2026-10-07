<?php
/**
 * Setup assistant: step order and what each step stores.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class WizardTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_step_order(): void {
		$this->assertSame( array( 'welcome', 'assessment', 'images', 'badge', 'files', 'text', 'done' ), array_keys( TransparAI_Wizard::steps() ) );
		$this->assertSame( 'assessment', TransparAI_Wizard::next_step( 'welcome' ) );
		$this->assertSame( '', TransparAI_Wizard::next_step( 'done' ) );
		$this->assertSame( 'badge', TransparAI_Wizard::previous_step( 'files' ) );
		$this->assertSame( '', TransparAI_Wizard::previous_step( 'welcome' ) );
		$this->assertStringEndsWith( 'admin.php?page=transparai-setup&step=badge', TransparAI_Wizard::url( 'badge' ) );
	}

	public function test_assessment_step_stores_the_answers(): void {
		TransparAI_Wizard::save_step(
			'assessment',
			array(
				'assessment' => array(
					'chatbot'  => 'yes',
					'ai_text'  => 'no',
					'invented' => 'yes',
				),
			)
		);
		$answers = TransparAI_Compliance::assessment();
		$this->assertSame( 'yes', $answers['chatbot'] );
		$this->assertSame( 'no', $answers['ai_text'] );
		$this->assertArrayNotHasKey( 'invented', $answers );
	}

	public function test_images_step_sets_the_detection_policy(): void {
		TransparAI_Wizard::save_step( 'images', array( 'detection' => 'review' ) );
		$this->assertSame( 'queue', TransparAI_Options::get( 'mode_certain' ) );
		TransparAI_Wizard::save_step( 'images', array( 'detection' => 'auto' ) );
		$this->assertSame( 'flag', TransparAI_Options::get( 'mode_certain' ) );
		$this->assertSame( 'queue', TransparAI_Options::get( 'mode_likely' ) );
	}

	public function test_badge_step_goes_through_the_sanitizer(): void {
		TransparAI_Wizard::save_step(
			'badge',
			array(
				'badge_enabled'  => '1',
				'badge_style'    => 'eu-icon',
				'badge_position' => 'top-left',
				'badge_mode'     => 'caption',
				'badge_size'     => 'huge',
				'from_ai_act'    => '1',
			)
		);
		$this->assertSame( 'eu-icon', TransparAI_Options::get( 'badge_style' ) );
		$this->assertSame( 'top-left', TransparAI_Options::get( 'badge_position' ) );
		$this->assertSame( 'caption', TransparAI_Options::get( 'badge_mode' ) );
		$this->assertSame( 'medium', TransparAI_Options::get( 'badge_size' ), 'an unknown size falls back to the default' );
		$this->assertSame( '2026-08-02', TransparAI_Options::get( 'badge_from_date' ) );

		TransparAI_Wizard::save_step( 'badge', array( 'badge_style' => 'dark' ) );
		$this->assertSame( '0', TransparAI_Options::get( 'badge_enabled' ), 'an unchecked box switches the badge off' );
		$this->assertSame( '', TransparAI_Options::get( 'badge_from_date' ) );
	}

	public function test_files_step_toggles(): void {
		TransparAI_Wizard::save_step(
			'files',
			array(
				'write_xmp'   => '1',
				'auto_repair' => '1',
			)
		);
		$this->assertSame( '1', TransparAI_Options::get( 'write_xmp' ) );
		$this->assertSame( '0', TransparAI_Options::get( 'write_iim' ) );
		$this->assertSame( '1', TransparAI_Options::get( 'auto_repair' ) );
		$this->assertSame( '0', TransparAI_Options::get( 'schema_output' ) );
	}

	public function test_text_step_follows_the_assessment(): void {
		TransparAI_Wizard::save_step( 'assessment', array( 'assessment' => array( 'chatbot' => 'no' ) ) );
		TransparAI_Wizard::save_step( 'text', array() );
		$this->assertSame( 'no', TransparAI_Options::get( 'chatbot_answer' ) );

		TransparAI_Wizard::save_step( 'assessment', array( 'assessment' => array( 'chatbot' => 'yes' ) ) );
		TransparAI_Wizard::save_step(
			'text',
			array(
				'chatbot_staffing'        => 'ai',
				'content_notice_position' => 'after',
			)
		);
		$this->assertSame( 'yes', TransparAI_Options::get( 'chatbot_answer' ) );
		$this->assertSame( 'ai', TransparAI_Options::get( 'chatbot_staffing' ) );
		$this->assertSame( 'after', TransparAI_Options::get( 'content_notice_position' ) );
	}
}
