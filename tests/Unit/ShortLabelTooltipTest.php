<?php
/** Native tooltip opt-in, full labels and rendering boundaries. @package TransparAI */
declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ShortLabelTooltipTest extends TestCase {
	protected function setUp(): void {
		trai_test_reset();
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array(
			'badge_short_tooltip' => '1',
			'badge_style'         => 'icon-only',
			'badge_mode'          => 'overlay',
		);
		update_post_meta( 55, TransparAI_Meta::KEY_FLAG, '1' );
	}

	private function badge(): string {
		$method = new ReflectionMethod( TransparAI_Frontend::class, 'badge_html' );
		$method->setAccessible( true );
		return $method->invoke( null, 55 );
	}

	public function test_full_label_is_available_without_javascript(): void {
		$this->assertStringContainsString( ' title="AI-generated"', $this->badge() );
		$this->assertStringContainsString( '>AI-generated</span>', $this->badge() );
	}

	public function test_custom_label_is_escaped_in_title_and_text(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings']['badge_text'] = 'AI "generated" <script> & reviewed';
		$label = esc_attr( TransparAI_Frontend::badge_label( 55 ) );
		$this->assertStringContainsString( ' title="' . $label . '"', $this->badge() );
		$this->assertStringNotContainsString( '<script>', $this->badge() );
	}

	public function test_generator_and_placeholders_are_resolved(): void {
		global $trai_test_options;
		update_post_meta( 55, TransparAI_Meta::KEY_GENERATOR, 'Midjourney' );
		$trai_test_options['transparai_settings']['badge_show_source'] = '1';
		$this->assertStringContainsString( ' title="AI-generated · Midjourney"', $this->badge() );
		$trai_test_options['transparai_settings']['badge_text'] = '{generator} · {site}';
		$this->assertStringContainsString( ' title="' . esc_attr( TransparAI_Frontend::badge_label( 55 ) ) . '"', $this->badge() );
	}

	public function test_human_label_uses_its_own_full_text(): void {
		global $trai_test_options;
		delete_post_meta( 55, TransparAI_Meta::KEY_FLAG );
		update_post_meta( 55, TransparAI_Meta::KEY_HUMAN, 'capture' );
		$trai_test_options['transparai_settings']['human_badge_text'] = 'Made by a person';
		$this->assertStringContainsString( ' title="Made by a person"', $this->badge() );
	}

	/** @dataProvider excluded_contexts */
	public function test_tooltip_is_not_added_outside_the_selected_image_overlay( string $key, string $value ): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'][ $key ] = $value;
		$this->assertStringNotContainsString( ' title=', $this->badge() );
	}

	public static function excluded_contexts(): array {
		return array(
			'option off'  => array( 'badge_short_tooltip', '0' ),
			'caption'     => array( 'badge_mode', 'caption' ),
			'dark'        => array( 'badge_style', 'dark' ),
			'light'       => array( 'badge_style', 'light' ),
			'outline'     => array( 'badge_style', 'outline' ),
			'EU icon'     => array( 'badge_style', 'eu-icon' ),
		);
	}

	public function test_attachment_overrides_and_non_images_are_excluded(): void {
		global $trai_test_meta;
		foreach ( array( 'below', 'hidden' ) as $placement ) {
			update_post_meta( 55, TransparAI_Meta::KEY_BADGE_POS, $placement );
			$this->assertStringNotContainsString( ' title=', $this->badge() );
		}
		delete_post_meta( 55, TransparAI_Meta::KEY_BADGE_POS );
		$trai_test_meta[55]['_test_mime'] = 'video/mp4';
		$this->assertStringNotContainsString( ' title=', $this->badge() );
	}

	public function test_setting_is_opt_in_and_sanitized(): void {
		$this->assertSame( '0', TransparAI_Options::defaults()['badge_short_tooltip'] );
		$this->assertSame( '0', TransparAI_Options::sanitize( array() )['badge_short_tooltip'] );
		$this->assertSame( '1', TransparAI_Options::sanitize( array( 'badge_short_tooltip' => '1' ) )['badge_short_tooltip'] );
	}
}
