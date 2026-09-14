<?php
/**
 * First-run setup: the activation redirect guards, the journal that
 * snapshots every step, and undo that restores exactly that snapshot.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class SetupTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_redirect_fires_once_and_only_when_allowed(): void {
		global $trai_test_can;
		$this->assertFalse( TransparAI_Setup::should_redirect(), 'No flag, no redirect' );

		TransparAI_Setup::flag_redirect();
		$this->assertTrue( TransparAI_Setup::should_redirect() );
		$this->assertFalse( TransparAI_Setup::should_redirect(), 'The flag is consumed' );

		TransparAI_Setup::flag_redirect();
		$_GET['activate-multi'] = '1';
		$this->assertFalse( TransparAI_Setup::should_redirect(), 'Bulk activation is never hijacked' );
		unset( $_GET['activate-multi'] );

		TransparAI_Setup::flag_redirect();
		$trai_test_can = false;
		$this->assertFalse( TransparAI_Setup::should_redirect(), 'Capability required' );
		$trai_test_can = true;

		update_option( TransparAI_Setup::OPT_DONE, time() );
		TransparAI_Setup::flag_redirect();
		$this->assertFalse( get_transient( TransparAI_Setup::REDIRECT ), 'Finished setup sets no flag' );
	}

	public function test_visibility_per_user_and_site(): void {
		global $trai_test_user;
		$trai_test_user = 2;
		$this->assertTrue( TransparAI_Setup::visible() );

		update_user_meta( 2, TransparAI_Setup::USER_SKIPPED, '1' );
		$this->assertFalse( TransparAI_Setup::visible(), 'Skipped by this user' );
		$_GET['setup'] = '1';
		$this->assertTrue( TransparAI_Setup::visible(), 'Explicit request always shows it' );
		unset( $_GET['setup'] );

		update_option( TransparAI_Setup::OPT_DONE, time() );
		$this->assertFalse( TransparAI_Setup::visible() );
	}

	public function test_apply_snapshots_and_undo_restores(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array(
			'badge_style' => 'dark',
			'write_xmp'   => '1',
		);

		TransparAI_Setup::apply( 'badge', array( 'badge_style' => 'light', 'badge_position' => 'top-left', 'badge_mode' => 'caption' ) );
		$this->assertSame( 'light', TransparAI_Options::get( 'badge_style' ) );
		$this->assertSame( 'top-left', TransparAI_Options::get( 'badge_position' ) );
		$this->assertSame( '1', TransparAI_Options::get( 'write_xmp' ), 'Other keys untouched' );

		TransparAI_Setup::apply( 'writing', array( 'write_xmp' => '0', 'write_iim' => '1' ) );
		$this->assertSame( '0', TransparAI_Options::get( 'write_xmp' ) );
		$this->assertSame( '0', TransparAI_Options::get( 'write_human' ), 'Unchecked boxes are off, not left at their default' );
		$this->assertCount( 2, TransparAI_Setup::journal() );
		$this->assertSame( array( 'write_xmp' => '1', 'write_iim' => '1', 'write_human' => '1', 'auto_repair' => '1' ), TransparAI_Setup::journal()[1]['before'] );

		$this->assertSame( 'writing', TransparAI_Setup::undo() );
		$this->assertSame( '1', TransparAI_Options::get( 'write_xmp' ), 'Snapshot restored' );
		$this->assertSame( 'light', TransparAI_Options::get( 'badge_style' ), 'Earlier step stays' );

		$this->assertSame( 'badge', TransparAI_Setup::undo() );
		$this->assertSame( 'dark', TransparAI_Options::get( 'badge_style' ) );
		$this->assertSame( '', TransparAI_Setup::undo(), 'Empty journal' );

		$events = array_column( TransparAI_Meta::site_log(), 'e' );
		$this->assertSame( array( 'setup-badge', 'setup-writing', 'setup-undo', 'setup-undo' ), $events );
	}

	public function test_apply_ignores_unknown_steps_and_foreign_keys(): void {
		TransparAI_Setup::apply( 'nope', array( 'badge_style' => 'light' ) );
		$this->assertSame( array(), TransparAI_Setup::journal() );

		TransparAI_Setup::apply( 'badge', array( 'badge_style' => 'light', 'delete_on_uninstall' => '1' ) );
		$this->assertSame( '0', TransparAI_Options::get( 'delete_on_uninstall' ), 'Only the step keys are written' );
	}
}
