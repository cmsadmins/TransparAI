<?php
/**
 * Settings sanitization: booleans, enum fallbacks, text limits, unknown keys.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_non_array_input_returns_defaults(): void {
		$this->assertSame( TransparAI_Options::defaults(), TransparAI_Options::sanitize( 'garbage' ) );
		$this->assertSame( TransparAI_Options::defaults(), TransparAI_Options::sanitize( null ) );
	}

	public function test_booleans_normalize_to_zero_or_one(): void {
		$clean = TransparAI_Options::sanitize(
			array(
				'badge_enabled' => 'yes',
				'badge_guard'   => '',
				'write_xmp'     => '',
				'schema_output' => '0',
			)
		);
		$this->assertSame( '1', $clean['badge_enabled'], 'Any truthy value becomes "1"' );
		$this->assertSame( '0', $clean['badge_guard'] );
		$this->assertSame( '0', $clean['write_xmp'] );
		$this->assertSame( '0', $clean['schema_output'] );
		$this->assertSame( '0', $clean['page_notice'], 'Missing boolean falls back to off' );
	}

	/**
	 * Every on/off setting must be listed as a boolean in sanitize(), otherwise
	 * saving it silently keeps the default and the checkbox never sticks. That
	 * is invisible in the code and only shows up when someone uses the screen,
	 * so the check runs over the defaults instead of a hand-written list.
	 */
	public function test_every_on_off_default_is_saved_as_a_boolean(): void {
		$switches = array_keys(
			array_filter(
				TransparAI_Options::defaults(),
				static function ( $value, $key ): bool {
					return in_array( $value, array( '0', '1' ), true ) && 'badge_from_date' !== $key;
				},
				ARRAY_FILTER_USE_BOTH
			)
		);

		$raw = array();
		foreach ( $switches as $key ) {
			$raw[ $key ] = '1';
		}
		$clean = TransparAI_Options::sanitize( $raw );

		foreach ( $switches as $key ) {
			$this->assertSame( '1', $clean[ $key ], sprintf( 'Setting "%s" is not handled as a boolean in sanitize()', $key ) );
		}
	}

	public function test_enums_fall_back_to_default_on_invalid_value(): void {
		$clean = TransparAI_Options::sanitize(
			array(
				'badge_position' => 'under-the-bed',
				'badge_style'    => 'light',
				'mode_certain'   => 'explode',
			)
		);
		$this->assertSame( 'bottom-right', $clean['badge_position'] );
		$this->assertSame( 'light', $clean['badge_style'] );
		$this->assertSame( 'flag', $clean['mode_certain'] );
	}

	public function test_text_fields_are_trimmed_stripped_and_limited(): void {
		$clean = TransparAI_Options::sanitize(
			array(
				'badge_text'          => '  <b>KI</b>  ',
				'content_notice_text' => str_repeat( 'x', 500 ),
				'unknown_key'         => 'evil',
			)
		);
		$this->assertSame( 'KI', $clean['badge_text'] );
		$this->assertSame( 300, mb_strlen( $clean['content_notice_text'] ) );
		$this->assertArrayNotHasKey( 'unknown_key', $clean, 'Unknown keys are dropped' );
	}

	public function test_badge_from_date_accepts_only_valid_dates(): void {
		$this->assertSame( '2026-09-01', TransparAI_Options::sanitize( array( 'badge_from_date' => '2026-09-01' ) )['badge_from_date'] );
		$this->assertSame( '', TransparAI_Options::sanitize( array( 'badge_from_date' => '' ) )['badge_from_date'] );
		$this->assertSame( '', TransparAI_Options::sanitize( array() )['badge_from_date'] );
		$this->assertSame( '', TransparAI_Options::sanitize( array( 'badge_from_date' => '01.09.2026' ) )['badge_from_date'], 'Non-ISO format is rejected' );
		$this->assertSame( '', TransparAI_Options::sanitize( array( 'badge_from_date' => '2026-13-01' ) )['badge_from_date'], 'Impossible date is rejected' );
		$this->assertSame( '', TransparAI_Options::sanitize( array( 'badge_from_date' => '2026-09-01<script>' ) )['badge_from_date'] );
	}

	public function test_badge_colors_accept_only_full_hex(): void {
		$this->assertSame( '#ff6800', TransparAI_Options::sanitize( array( 'badge_color' => '#FF6800' ) )['badge_color'], 'Case is normalised' );
		$this->assertSame( '#123abc', TransparAI_Options::sanitize( array( 'badge_text_color' => '  #123abc  ' ) )['badge_text_color'] );
		$this->assertSame( '', TransparAI_Options::sanitize( array( 'badge_color' => '' ) )['badge_color'] );
		$this->assertSame( '', TransparAI_Options::sanitize( array() )['badge_color'], 'Missing key stays empty' );
		$this->assertSame( '', TransparAI_Options::sanitize( array( 'badge_color' => '#fff' ) )['badge_color'], 'Shorthand is rejected' );
		$this->assertSame( '', TransparAI_Options::sanitize( array( 'badge_color' => 'red' ) )['badge_color'] );
		$this->assertSame( '', TransparAI_Options::sanitize( array( 'badge_color' => '#ff6800;}body{display:none' ) )['badge_color'], 'No way out of the CSS declaration' );
	}

	public function test_badge_opacity_is_clamped_to_percent(): void {
		$this->assertSame( '100', TransparAI_Options::sanitize( array() )['badge_opacity'], 'Missing key keeps the badge fully opaque' );
		$this->assertSame( '70', TransparAI_Options::sanitize( array( 'badge_opacity' => '70' ) )['badge_opacity'] );
		$this->assertSame( '0', TransparAI_Options::sanitize( array( 'badge_opacity' => '-5' ) )['badge_opacity'] );
		$this->assertSame( '100', TransparAI_Options::sanitize( array( 'badge_opacity' => '500' ) )['badge_opacity'] );
		$this->assertSame( '100', TransparAI_Options::sanitize( array( 'badge_opacity' => 'abc' ) )['badge_opacity'], 'Unreadable input keeps the default' );
	}

	public function test_get_survives_foreign_value_types(): void {
		global $trai_test_options;
		// WP-CLI, migrations and other plugins can write non-strings.
		$trai_test_options['transparai_settings'] = array(
			'page_notice'  => 1,
			'badge_text'   => 42,
			'badge_style'  => array( 'unexpected' ),
			'badge_guard'  => true,
		);

		$this->assertSame( '1', TransparAI_Options::get( 'page_notice' ) );
		$this->assertTrue( TransparAI_Options::enabled( 'page_notice' ), 'An integer 1 still counts as enabled' );
		$this->assertSame( '42', TransparAI_Options::get( 'badge_text' ) );
		$this->assertSame( '', TransparAI_Options::get( 'badge_style' ), 'A non-scalar falls back to unset' );
		$this->assertSame( '1', TransparAI_Options::get( 'badge_guard' ) );
	}

	public function test_get_and_enabled_merge_over_defaults(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_style' => 'outline' );

		$this->assertSame( 'outline', TransparAI_Options::get( 'badge_style' ) );
		$this->assertTrue( TransparAI_Options::enabled( 'badge_enabled' ), 'Defaults fill missing keys' );
		$this->assertSame( '', TransparAI_Options::get( 'nonexistent' ) );
	}
}
