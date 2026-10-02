<?php
/**
 * EU icon badge style tests.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class EuIconTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_style_and_variants_are_accepted_by_sanitize(): void {
		$clean = TransparAI_Options::sanitize(
			array(
				'badge_style'    => 'eu-icon',
				'badge_eu_icon'  => 'basic',
				'badge_eu_color' => 'white',
			)
		);
		$this->assertSame( 'eu-icon', $clean['badge_style'] );
		$this->assertSame( 'basic', $clean['badge_eu_icon'] );
		$this->assertSame( 'white', $clean['badge_eu_color'] );

		$clean = TransparAI_Options::sanitize( array( 'badge_eu_color' => 'orange' ) );
		$this->assertSame( 'black', $clean['badge_eu_color'], 'unknown colours fall back to the default' );
	}

	public function test_plain_styles_render_text_only(): void {
		$html = TransparAI_Frontend::badge_element( 'AI-generated', 'AI' );
		$this->assertSame( '<span class="trai-badge" role="note" data-trai-short="AI">AI-generated</span>', $html );
		$this->assertSame( '', TransparAI_Frontend::eu_icon_url( 'generated' ) );
	}

	public function test_eu_style_picks_the_icon_by_label_type(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_style' => 'eu-icon' );

		$this->assertStringEndsWith( 'assets/img/eu/ai-generated-black.svg', TransparAI_Frontend::eu_icon_url( 'generated' ) );
		$this->assertStringEndsWith( 'assets/img/eu/ai-modified-black.svg', TransparAI_Frontend::eu_icon_url( 'composite' ) );

		$html = TransparAI_Frontend::badge_element( 'AI-generated <b>', 'AI', 'composite' );
		$this->assertStringContainsString( '<img class="trai-eu" src="https://example.test/wp-content/plugins/transparai/assets/img/eu/ai-modified-black.svg" alt="" aria-hidden="true" />', $html );
		$this->assertStringContainsString( '<span class="trai-badge-text">AI-generated &lt;b&gt;</span>', $html, 'the readable text stays, escaped' );

		$trai_test_options['transparai_settings'] = array(
			'badge_style'    => 'eu-icon',
			'badge_eu_icon'  => 'basic',
			'badge_eu_color' => 'white',
		);
		$this->assertStringEndsWith( 'ai-basic-white.svg', TransparAI_Frontend::eu_icon_url( 'composite' ) );
	}

	public function test_bundled_icons_exist_and_carry_no_scripts(): void {
		foreach ( array( 'basic', 'generated', 'modified' ) as $name ) {
			foreach ( array( 'black', 'white' ) as $color ) {
				$path = dirname( __DIR__, 2 ) . "/assets/img/eu/ai-{$name}-{$color}.svg";
				$this->assertFileExists( $path );
				$svg = (string) file_get_contents( $path );
				$this->assertStringStartsWith( '<?xml', $svg );
				$this->assertDoesNotMatchRegularExpression( '/<script|href=|url\(/i', $svg );
			}
		}
	}
}
