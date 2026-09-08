<?php
/**
 * admin.js gets its strings from a single localized set. wp_localize_script()
 * replaces a previously registered object instead of merging into it, so a
 * second surface localizing the same handle with a shorter label set silently
 * removes strings the script needs, and the first labels.<key>.replace() call
 * throws (this happened to the library scan: another plugin firing
 * wp_enqueue_media on the settings page overwrote the scan labels and the
 * batch loop stopped after the first request).
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class AdminLabelsTest extends TestCase {

	public function test_every_label_used_by_admin_js_is_localized(): void {
		$js  = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/admin.js' );
		$php = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/class-media-library.php' );

		preg_match_all( '/\blabels\.([A-Za-z0-9_]+)/', $js, $used );
		$this->assertNotEmpty( $used[1], 'No labels.* usage found in admin.js.' );

		foreach ( array_unique( $used[1] ) as $key ) {
			$this->assertMatchesRegularExpression(
				"/'" . preg_quote( $key, '/' ) . "'\s*=>/",
				$php,
				'admin.js uses labels.' . $key . ', but js_labels() does not provide it.'
			);
		}
	}

	public function test_settings_page_does_not_localize_its_own_label_set(): void {
		$settings = (string) file_get_contents( dirname( __DIR__, 2 ) . '/admin/class-settings.php' );
		$this->assertStringNotContainsString( 'wp_localize_script', $settings );
	}
}
