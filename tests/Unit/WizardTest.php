<?php
/**
 * Setup assistant: step order and what each screen stores.
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
		$this->assertSame( array( 'start', 'label', 'done' ), array_keys( TransparAI_Wizard::steps() ) );
		$this->assertSame( 'label', TransparAI_Wizard::next_step( 'start' ) );
		$this->assertSame( '', TransparAI_Wizard::next_step( 'done' ) );
		$this->assertSame( 'label', TransparAI_Wizard::previous_step( 'done' ) );
		$this->assertSame( '', TransparAI_Wizard::previous_step( 'start' ) );
		$this->assertStringEndsWith( 'admin.php?page=transparai-setup&step=label', TransparAI_Wizard::url( 'label' ) );
	}

	public function test_start_screen_stores_the_answers_and_the_chatbot_answer(): void {
		TransparAI_Wizard::save_step(
			'start',
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
		$this->assertSame( 'yes', TransparAI_Options::get( 'chatbot_answer' ), 'the chatbot notice follows the answer' );
		$this->assertSame( 'mixed', TransparAI_Options::get( 'chatbot_staffing' ), 'staffing keeps its default' );

		TransparAI_Wizard::save_step( 'start', array( 'assessment' => array( 'chatbot' => 'no' ) ) );
		$this->assertSame( 'no', TransparAI_Options::get( 'chatbot_answer' ) );

		TransparAI_Wizard::save_step( 'start', array( 'assessment' => array( 'chatbot' => 'maybe' ) ) );
		$this->assertSame( 'no', TransparAI_Options::get( 'chatbot_answer' ), 'an unknown value leaves the answer alone' );
	}

	public function test_label_screen_sets_policy_badge_and_files_through_the_sanitizer(): void {
		TransparAI_Wizard::save_step(
			'label',
			array(
				'detection'      => 'review',
				'badge_enabled'  => '1',
				'badge_style'    => 'eu-icon',
				'badge_position' => 'top-left',
				'badge_mode'     => 'caption',
				'badge_size'     => 'huge',
				'from_ai_act'    => '1',
				'write_xmp'      => '1',
				'auto_repair'    => '1',
			)
		);
		$this->assertSame( '1', TransparAI_Options::get( 'autodetect' ) );
		$this->assertSame( 'queue', TransparAI_Options::get( 'mode_certain' ) );
		$this->assertSame( 'queue', TransparAI_Options::get( 'mode_likely' ) );
		$this->assertSame( 'eu-icon', TransparAI_Options::get( 'badge_style' ) );
		$this->assertSame( 'top-left', TransparAI_Options::get( 'badge_position' ) );
		$this->assertSame( 'caption', TransparAI_Options::get( 'badge_mode' ) );
		$this->assertSame( 'medium', TransparAI_Options::get( 'badge_size' ), 'an unknown size falls back to the default' );
		$this->assertSame( '2026-08-02', TransparAI_Options::get( 'badge_from_date' ) );
		$this->assertSame( '1', TransparAI_Options::get( 'write_xmp' ) );
		$this->assertSame( '0', TransparAI_Options::get( 'write_iim' ), 'an unchecked box switches the option off' );
		$this->assertSame( '1', TransparAI_Options::get( 'auto_repair' ) );
		$this->assertSame( '0', TransparAI_Options::get( 'schema_output' ) );

		TransparAI_Wizard::save_step( 'label', array( 'detection' => 'auto', 'badge_style' => 'dark' ) );
		$this->assertSame( 'flag', TransparAI_Options::get( 'mode_certain' ) );
		$this->assertSame( '0', TransparAI_Options::get( 'badge_enabled' ), 'an unchecked box switches the badge off' );
		$this->assertSame( '', TransparAI_Options::get( 'badge_from_date' ) );
	}

	public function test_unknown_step_stores_nothing(): void {
		$before = TransparAI_Options::all();
		TransparAI_Wizard::save_step( 'text', array( 'content_notice_position' => 'after' ) );
		$this->assertSame( $before, TransparAI_Options::all() );
	}
}
