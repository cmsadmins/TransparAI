<?php
/**
 * Style locks for everything a visitor, an editor or a reviewer reads:
 * no em dashes, no emojis, and no request to any external host.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class PublishedTextTest extends TestCase {

	/**
	 * @return string[]
	 */
	private function files(): array {
		$root  = dirname( __DIR__, 2 );
		$files = array( $root . '/readme.txt', $root . '/README.md', $root . '/transparai.php', $root . '/uninstall.php' );
		foreach ( array( 'includes', 'admin', 'assets/js', 'assets/css', 'data', 'blocks/notice' ) as $dir ) {
			foreach ( (array) glob( $root . '/' . $dir . '/*.{php,js,css,json}', GLOB_BRACE ) as $file ) {
				$files[] = (string) $file;
			}
		}
		return $files;
	}

	public function test_no_em_dashes_or_emojis_in_published_text(): void {
		foreach ( $this->files() as $file ) {
			$text = (string) file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/\x{2014}/u', $text, 'Em dash in ' . basename( $file ) );
			$this->assertDoesNotMatchRegularExpression( '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text, 'Emoji in ' . basename( $file ) );
		}
	}

	public function test_no_remote_requests_outside_the_delivery_check(): void {
		$root = dirname( __DIR__, 2 );
		foreach ( array( 'includes', 'admin' ) as $dir ) {
			foreach ( (array) glob( $root . '/' . $dir . '/*.php' ) as $file ) {
				if ( str_ends_with( (string) $file, 'class-delivery.php' ) ) {
					continue; /* The opt-in delivery check fetches the site's own image URL. */
				}
				$text = (string) file_get_contents( (string) $file );
				$this->assertDoesNotMatchRegularExpression( '/wp_(safe_)?remote_(get|post|request|head)|curl_init|file_get_contents\(\s*[\'"]https?:/', $text, 'Remote request in ' . basename( (string) $file ) );
			}
		}
	}
}
