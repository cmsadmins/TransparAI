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

	public function test_url_points_to_the_settings_screen_under_the_top_level_menu(): void {
		$this->assertStringContainsString( 'admin.php?page=transparai-settings&setup=1', TransparAI_Setup::url() );
		$this->assertStringNotContainsString( 'upload.php', TransparAI_Setup::url() );
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
}
