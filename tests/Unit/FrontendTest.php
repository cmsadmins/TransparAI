<?php
/**
 * Front-end wrap tests: URL normalization and the two-stage image matching
 * (wp-image class, src/data-src against the URL map) that covers builder
 * markup without attachment classes.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class FrontendTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	/**
	 * Seed the cached URL map and flag the mapped attachments.
	 *
	 * @param array<string, int> $map path => attachment id.
	 */
	private function seed_map( array $map ): void {
		set_transient( 'transparai_url_map', $map );
		foreach ( $map as $id ) {
			update_post_meta( $id, TransparAI_Meta::KEY_FLAG, '1' );
		}
	}

	public function test_normalize_upload_path(): void {
		$cases = array(
			'http://example.test/wp-content/uploads/2026/09/pic-300x200.jpg' => '2026/09/pic.jpg',
			'https://example.test/wp-content/uploads/2026/09/pic-scaled.jpeg' => '2026/09/pic.jpeg',
			'/wp-content/uploads/2026/09/pic.jpg.webp?ver=5#frag'             => '2026/09/pic.jpg',
			'2026/09/pic-scaled.jpg'                                          => '2026/09/pic.jpg',
			'https://cdn.example.com/external/pic.jpg'                        => '',
			'/some/theme/assets/pic.jpg'                                      => '',
		);
		foreach ( $cases as $input => $expected ) {
			$this->assertSame( $expected, TransparAI_Frontend::normalize_upload_path( $input ), 'Input: ' . $input );
		}
	}

	public function test_wrap_by_class_and_by_map(): void {
		$this->seed_map( array( '2026/09/ai.jpg' => 77 ) );
		update_post_meta( 55, TransparAI_Meta::KEY_FLAG, '1' );

		$html = '<p><img class="wp-image-55" src="/wp-content/uploads/2026/09/other.jpg"></p>'
			. '<p><img src="/wp-content/uploads/2026/09/ai-768x512.jpg" alt="builder"></p>'
			. '<p><img src="/wp-content/uploads/2026/09/unknown.jpg"></p>';

		$out = TransparAI_Frontend::wrap_images( $html );

		$this->assertSame( 2, substr_count( $out, 'trai-badge' ), 'Class hit and map hit must be wrapped, unknown src must not' );
		$this->assertStringContainsString( 'unknown.jpg"></p>', $out );
	}

	public function test_data_src_lazyload_matches_map(): void {
		$this->seed_map( array( '2026/09/slide.jpg' => 88 ) );

		$html = '<img class="swiper-slide-image swiper-lazy" data-src="/wp-content/uploads/2026/09/slide-1024x683.jpg" alt="carousel">';
		$out  = TransparAI_Frontend::wrap_images( $html );

		$this->assertSame( 1, substr_count( $out, 'trai-badge' ) );
	}

	public function test_unflagged_class_wins_over_map(): void {
		// The attachment id in the class is authoritative; a map entry for the
		// same path must not overrule an unflagged id.
		$this->seed_map( array( '2026/09/ai.jpg' => 77 ) );
		delete_post_meta( 77, TransparAI_Meta::KEY_FLAG );
		set_transient( 'transparai_url_map', array( '2026/09/ai.jpg' => 77 ) );

		$html = '<img class="wp-image-77" src="/wp-content/uploads/2026/09/ai.jpg">';
		$this->assertStringNotContainsString( 'trai-badge', TransparAI_Frontend::wrap_images( $html ) );
	}

	public function test_repeated_runs_stay_idempotent(): void {
		$this->seed_map( array( '2026/09/ai.jpg' => 77 ) );

		$html = '<img src="/wp-content/uploads/2026/09/ai.jpg">';
		$once = TransparAI_Frontend::wrap_images( $html );
		$twice = TransparAI_Frontend::wrap_images( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, 'trai-badge' ) );
	}

	public function test_tolerates_stray_quote_markup(): void {
		// WPBakery 8.7.3 emits class="vc_single_image-img"" (double quote bug).
		$this->seed_map( array( '2026/09/ai.jpg' => 77 ) );

		$html = '<img width="640" height="480" src="/wp-content/uploads/2026/09/ai.jpg" class="vc_single_image-img"" />';
		$out  = TransparAI_Frontend::wrap_images( $html );

		$this->assertSame( 1, substr_count( $out, 'trai-badge' ) );
	}

	public function test_cover_background_wrap_gets_fill_class(): void {
		// Cover backgrounds are absolutely positioned; the wrapper must take
		// over the full-bleed role, plain images must not get the class.
		$this->seed_map( array() );
		update_post_meta( 23, TransparAI_Meta::KEY_FLAG, '1' );

		$cover = '<img class="wp-block-cover__image-background wp-image-23" src="/wp-content/uploads/2026/09/x.jpg">';
		$this->assertStringContainsString( 'trai-wrap--fill', TransparAI_Frontend::wrap_images( $cover ) );

		$plain = '<img class="wp-image-23" src="/wp-content/uploads/2026/09/x.jpg">';
		$this->assertStringNotContainsString( 'trai-wrap--fill', TransparAI_Frontend::wrap_images( $plain ) );
	}

	public function test_wpb_image_filter_tags_classless_markup(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_enabled' => '1' );

		$img = array( 'thumbnail' => '<img width="400" height="300" src="/wp-content/uploads/2026/09/x-400x300.jpg" alt="" title="" />' );
		$out = TransparAI_Frontend::filter_wpb_image( $img, 42 );
		$this->assertStringContainsString( 'class="wp-image-42"', $out['thumbnail'] );

		$img = array( 'thumbnail' => '<img class="vc_single_image-img attachment-large" src="/x.jpg" />' );
		$out = TransparAI_Frontend::filter_wpb_image( $img, 42 );
		$this->assertStringContainsString( 'class="wp-image-42 vc_single_image-img attachment-large"', $out['thumbnail'] );

		$img = array( 'thumbnail' => '<img class="wp-image-7" src="/x.jpg" />' );
		$out = TransparAI_Frontend::filter_wpb_image( $img, 42 );
		$this->assertStringNotContainsString( 'wp-image-42', $out['thumbnail'] );
	}
}
