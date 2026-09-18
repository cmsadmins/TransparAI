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

	public function test_badge_from_date_gates_older_uploads(): void {
		global $trai_test_options, $trai_test_meta;
		$trai_test_options['transparai_settings'] = array( 'badge_from_date' => '2026-06-01' );
		$this->seed_map( array() );

		update_post_meta( 60, TransparAI_Meta::KEY_FLAG, '1' );
		update_post_meta( 61, TransparAI_Meta::KEY_FLAG, '1' );
		$trai_test_meta[60]['_test_post_date'] = '2026-05-31 23:59:59';
		$trai_test_meta[61]['_test_post_date'] = '2026-06-01 00:00:00';

		$old = '<img class="wp-image-60" src="/wp-content/uploads/2026/05/old.jpg">';
		$new = '<img class="wp-image-61" src="/wp-content/uploads/2026/06/new.jpg">';

		$this->assertStringNotContainsString( 'trai-badge', TransparAI_Frontend::wrap_images( $old ), 'Uploaded before the start date: admin label only' );
		$this->assertSame( 1, substr_count( TransparAI_Frontend::wrap_images( $new ), 'trai-badge' ) );

		// Empty date restores the previous behavior: everything labeled is badged.
		$trai_test_options['transparai_settings'] = array();
		$this->assertSame( 1, substr_count( TransparAI_Frontend::wrap_images( $old ), 'trai-badge' ) );
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

	/**
	 * Reset the per-request collector between tests (private static state).
	 */
	private function reset_rendered(): void {
		$prop = new ReflectionProperty( TransparAI_Frontend::class, 'rendered_ids' );
		if ( PHP_VERSION_ID < 80100 ) {
			// No effect since 8.1, deprecated (= test error) since 8.5.
			$prop->setAccessible( true );
		}
		$prop->setValue( null, array() );
	}

	public function test_footer_schema_and_page_notice(): void {
		global $trai_test_options, $trai_test_meta, $trai_test_current_post;
		$this->reset_rendered();
		$trai_test_options['transparai_settings'] = array(
			'badge_enabled' => '1',
			'schema_output' => '1',
			'page_notice'   => '1',
		);

		$this->seed_map( array( '2026/09/ai.jpg' => 77 ) );
		$trai_test_meta[77]['_test_url']              = 'https://example.test/wp-content/uploads/2026/09/ai.jpg';
		$trai_test_meta[77]['_transparai_generator']  = 'Midjourney';
		$trai_test_meta[77]['_transparai_type']       = 'generated';

		// Nothing rendered yet: footer must stay empty.
		ob_start();
		TransparAI_Frontend::print_footer_output();
		$this->assertSame( '', ob_get_clean() );

		TransparAI_Frontend::wrap_images( '<img src="/wp-content/uploads/2026/09/ai.jpg">' );

		ob_start();
		TransparAI_Frontend::print_footer_output();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'application/ld+json', $out );
		$this->assertStringContainsString( 'trai-page-notice', $out );
		preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $out, $matches );
		$data = json_decode( $matches[1], true );
		$this->assertIsArray( $data );
		$this->assertSame( 'ImageObject', $data['@graph'][0]['@type'] );
		$this->assertSame( 'https://schema.org/TrainedAlgorithmicMediaDigitalSource', $data['@graph'][0]['digitalSourceType'] );
		$this->assertSame(
			'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia',
			$data['@graph'][0]['additionalProperty']['value']
		);
		$this->assertSame( 'https://example.test/wp-content/uploads/2026/09/ai.jpg#transparai', $data['@graph'][0]['@id'] );
		$this->assertSame( 'Midjourney', $data['@graph'][0]['creator']['name'] );
		$this->reset_rendered();
	}

	public function test_page_notice_and_schema_work_with_the_badge_off(): void {
		global $trai_test_options, $trai_test_meta;
		$this->reset_rendered();
		$trai_test_options['transparai_settings'] = array(
			'badge_enabled'    => '0',
			'badge_alt_append' => '1',
			'schema_output'    => '1',
			'page_notice'      => '1',
			'page_notice_text' => 'Some media here is AI-made.',
		);
		$this->seed_map( array( '2026/09/ai.jpg' => 77 ) );
		$trai_test_meta[77]['_test_url'] = 'https://example.test/wp-content/uploads/2026/09/ai.jpg';

		$tag = '<img class="wp-image-77" src="/wp-content/uploads/2026/09/ai.jpg">';
		$this->assertSame( $tag, TransparAI_Frontend::wrap_images( $tag ), 'No badge markup while the badge is off' );
		$attachment     = new WP_Post();
		$attachment->ID = 77;
		$attr           = TransparAI_Frontend::filter_image_attributes( array( 'alt' => 'A tree' ), $attachment );
		$this->assertSame( 'A tree', $attr['alt'], 'Alt append belongs to the badge and stays off with it' );

		ob_start();
		TransparAI_Frontend::print_footer_output();
		$out = (string) ob_get_clean();
		$this->assertStringContainsString( 'TrainedAlgorithmicMediaDigitalSource', $out, 'Structured data does not depend on the badge' );
		$this->assertStringContainsString( '<p class="trai-page-notice" role="note"><span class="trai-page-notice__media">Some media here is AI-made.</span></p>', $out );
		$this->reset_rendered();
	}

	public function test_footer_notice_lines_merge_into_one_paragraph(): void {
		global $trai_test_options, $trai_test_filters;
		$this->reset_rendered();
		$trai_test_options['transparai_settings'] = array(
			'badge_enabled' => '1',
			'page_notice'   => '1',
		);
		$trai_test_filters['transparai_footer_notices'][] = static function ( array $lines ): array {
			$lines['chat']    = 'You are chatting with an AI system.';
			$lines['systems'] = 'This site uses AI systems: Example.';
			$lines['empty']   = '';
			return $lines;
		};

		ob_start();
		TransparAI_Frontend::print_footer_output();
		$out = (string) ob_get_clean();
		$this->assertSame( 1, substr_count( $out, '<p class="trai-page-notice"' ), 'One paragraph for every source' );
		$this->assertStringNotContainsString( 'trai-page-notice__media', $out, 'No media line without rendered media' );
		$this->assertStringContainsString( '<span class="trai-page-notice__chat">You are chatting with an AI system.</span> <span class="trai-page-notice__systems">This site uses AI systems: Example.</span>', $out );
		$this->assertStringNotContainsString( 'trai-page-notice__empty', $out, 'Empty lines are dropped' );
		$this->reset_rendered();
	}

	public function test_image_attributes_injection_and_alt_append(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array(
			'badge_enabled'    => '1',
			'badge_alt_append' => '1',
		);
		update_post_meta( 90, TransparAI_Meta::KEY_FLAG, '1' );

		$attachment     = new WP_Post();
		$attachment->ID = 90;

		$attr = TransparAI_Frontend::filter_image_attributes(
			array(
				'class' => 'attachment-large size-large',
				'alt'   => 'A house',
			),
			$attachment
		);
		$this->assertStringContainsString( 'wp-image-90', $attr['class'] );
		$this->assertStringContainsString( '(AI-generated)', $attr['alt'] );

		// Existing wp-image class is left alone; alt is not doubled.
		$attr = TransparAI_Frontend::filter_image_attributes( $attr, $attachment );
		$this->assertSame( 1, substr_count( $attr['class'], 'wp-image-90' ) );
		$this->assertSame( 1, substr_count( $attr['alt'], 'AI-generated' ) );
	}

	public function test_video_block_gets_caption_wrap(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_enabled' => '1' );
		update_post_meta( 91, TransparAI_Meta::KEY_FLAG, '1' );

		$html = '<figure class="wp-block-video"><video src="/v.mp4"></video></figure>';
		$out  = TransparAI_Frontend::filter_block( $html, array( 'blockName' => 'core/video', 'attrs' => array( 'id' => 91 ) ) );

		$this->assertStringContainsString( 'trai-avwrap', $out );
		$this->assertSame( 1, substr_count( $out, 'trai-badge' ) );

		// Unflagged id: untouched.
		$clean = TransparAI_Frontend::filter_block( $html, array( 'blockName' => 'core/video', 'attrs' => array( 'id' => 92 ) ) );
		$this->assertSame( $html, $clean );
	}

	public function test_builder_editor_guards_disable_wrapping(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_enabled' => '1' );
		$this->seed_map( array( '2026/09/ai.jpg' => 77 ) );
		$html = '<img src="/wp-content/uploads/2026/09/ai.jpg">';

		$_GET['elementor-preview'] = '27';
		$this->assertSame( $html, TransparAI_Frontend::filter_content( $html ), 'No badges inside the Elementor preview' );
		unset( $_GET['elementor-preview'] );

		$_REQUEST['vc_editable'] = 'true';
		$this->assertSame( $html, TransparAI_Frontend::filter_content( $html ), 'No badges inside the WPBakery editor' );
		unset( $_REQUEST['vc_editable'] );

		$this->assertStringContainsString( 'trai-badge', TransparAI_Frontend::filter_content( $html ) );
	}

	public function test_badge_labels_use_custom_text(): void {
		global $trai_test_options;
		$this->assertSame( 'AI-generated', TransparAI_Frontend::badge_label() );
		$trai_test_options['transparai_settings'] = array( 'badge_text' => 'Machine made' );
		$this->assertSame( 'Machine made', TransparAI_Frontend::badge_label() );
		$this->assertSame( 'AI', TransparAI_Frontend::badge_short_label() );
	}

	public function test_badge_label_replaces_placeholders(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_text' => '{generator} prompted by {site}' );
		update_post_meta( 91, TransparAI_Meta::KEY_GENERATOR, 'Nano Banana Pro' );

		$this->assertSame( 'Nano Banana Pro prompted by Test Site', TransparAI_Frontend::badge_label( 91 ) );
	}

	public function test_badge_label_falls_back_without_generator(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_text' => '{generator} prompted by {site}' );

		$this->assertSame( 'AI-generated', TransparAI_Frontend::badge_label( 92 ), 'No generator meta: default label' );
		$this->assertSame( 'AI-generated', TransparAI_Frontend::badge_label(), 'No attachment in scope (JS template): default label' );
	}

	public function test_badge_label_site_only_template_works_without_id(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_text' => 'AI image via {site}' );

		$this->assertSame( 'AI image via Test Site', TransparAI_Frontend::badge_label() );
	}

	public function test_generator_template_suppresses_show_source_append(): void {
		global $trai_test_options;
		$this->seed_map( array() );
		update_post_meta( 93, TransparAI_Meta::KEY_FLAG, '1' );
		update_post_meta( 93, TransparAI_Meta::KEY_GENERATOR, 'Midjourney' );
		$trai_test_options['transparai_settings'] = array(
			'badge_text'        => 'Made with {generator}',
			'badge_show_source' => '1',
		);

		$out = TransparAI_Frontend::wrap_images( '<img class="wp-image-93" src="/wp-content/uploads/2026/09/g.jpg">' );

		$this->assertSame( 1, substr_count( $out, 'Midjourney' ), 'Generator must not be appended a second time' );
		$this->assertStringContainsString( 'Made with Midjourney', $out );
	}

	public function test_per_image_override_changes_position_class(): void {
		$this->seed_map( array() );
		update_post_meta( 77, TransparAI_Meta::KEY_FLAG, '1' );
		update_post_meta( 77, TransparAI_Meta::KEY_BADGE_POS, 'top-left' );

		$out = TransparAI_Frontend::wrap_images( '<img class="wp-image-77" src="/wp-content/uploads/2026/09/ai.jpg">' );

		$this->assertStringContainsString( 'trai-pos-top-left', $out );
		$this->assertStringContainsString( 'trai-badge-manual', $out, 'Manual overrides carry the marker that keeps the overlay guard off' );
		$this->assertStringNotContainsString( 'trai-pos-bottom-right', $out );
	}

	public function test_per_image_override_below_and_hidden_keep_markup(): void {
		$this->seed_map( array() );
		update_post_meta( 41, TransparAI_Meta::KEY_FLAG, '1' );
		$html = '<img class="wp-image-41" src="/wp-content/uploads/2026/09/b.jpg">';

		update_post_meta( 41, TransparAI_Meta::KEY_BADGE_POS, 'below' );
		$this->assertStringContainsString( 'trai-badge-below', TransparAI_Frontend::wrap_images( $html ) );

		update_post_meta( 41, TransparAI_Meta::KEY_BADGE_POS, 'hidden' );
		$hidden = TransparAI_Frontend::wrap_images( $html );
		$this->assertStringContainsString( 'trai-badge-hidden', $hidden );
		$this->assertStringContainsString( 'class="trai-badge"', $hidden, 'Hidden is CSS-only; the markup stays for schema output' );
	}

	public function test_badge_output_filters_apply(): void {
		$this->seed_map( array() );
		update_post_meta( 42, TransparAI_Meta::KEY_FLAG, '1' );

		add_filter(
			'transparai_badge_html',
			static function ( string $html, int $attachment_id ): string {
				return $html . '<!--badge-' . $attachment_id . '-->';
			}
		);
		add_filter(
			'transparai_badge_wrap_classes',
			static function ( string $classes ): string {
				return $classes . ' custom-class';
			}
		);

		$out = TransparAI_Frontend::wrap_images( '<img class="wp-image-42" src="/wp-content/uploads/2026/09/f.jpg">' );

		$this->assertStringContainsString( '<!--badge-42-->', $out );
		$this->assertStringContainsString( 'custom-class', $out );
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


	public function test_public_label_media_filter_and_bricks_element(): void {
		global $trai_test_options, $trai_test_filters;
		$trai_test_options['transparai_settings'] = array( 'badge_enabled' => '1' );
		update_post_meta( 7, TransparAI_Meta::KEY_FLAG, '1' );
		$html = '<div><img class="wp-image-7" src="/wp-content/uploads/2026/09/seven.jpg"></div>';

		TransparAI_Frontend::init();
		$this->assertArrayHasKey( 'transparai_label_media', $trai_test_filters, 'The public filter is registered' );
		$this->assertArrayHasKey( 'bricks/frontend/render_element', $trai_test_filters );
		$this->assertStringContainsString( 'trai-badge', apply_filters( 'transparai_label_media', $html ) );

		$this->assertStringContainsString( 'trai-badge', TransparAI_Frontend::filter_bricks_element( $html, null ) );
		$this->assertSame( '', TransparAI_Frontend::filter_bricks_element( '', null ) );
		$this->assertSame( 5, TransparAI_Frontend::filter_bricks_element( 5, null ), 'Non-string output passes through' );

		if ( ! function_exists( 'bricks_is_builder' ) ) {
			function bricks_is_builder() { // phpcs:ignore Generic.Functions.OpeningFunctionBraceKernighanRitchie.ContentAfterBrace
				return true;
			}
		}
		$this->assertSame( $html, TransparAI_Frontend::filter_bricks_element( $html, null ), 'Untouched inside the Bricks builder' );
	}

	/**
	 * The whole point of the colour settings is that they are opt-in: an
	 * installation that never touches them must not get a single extra
	 * declaration, otherwise the neutral look would shift under people who
	 * never asked for colours.
	 */
	public function test_badge_css_vars_stay_empty_on_the_defaults(): void {
		$this->assertSame( array(), TransparAI_Frontend::badge_css_vars() );

		global $trai_test_options;
		$trai_test_options['transparai_settings'] = TransparAI_Options::defaults();
		$this->assertSame( array(), TransparAI_Frontend::badge_css_vars(), 'Saved defaults behave like no settings at all' );
	}

	public function test_badge_css_vars_map_the_settings(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array(
			'badge_color'      => '#FF6800',
			'badge_text_color' => '#ffffff',
			'badge_opacity'    => '70',
		);
		$this->assertSame(
			array(
				'--trai-badge-bg'      => '#ff6800',
				'--trai-badge-fg'      => '#ffffff',
				'--trai-badge-opacity' => '0.7',
			),
			TransparAI_Frontend::badge_css_vars()
		);

		$trai_test_options['transparai_settings'] = array( 'badge_opacity' => '0' );
		$this->assertSame( array( '--trai-badge-opacity' => '0' ), TransparAI_Frontend::badge_css_vars(), 'Fully transparent is a real setting, not an empty one' );

		// Written straight into the option by WP-CLI, an import or by hand.
		$trai_test_options['transparai_settings'] = array( 'badge_color' => 'red;}body{display:none' );
		$this->assertSame( array(), TransparAI_Frontend::badge_css_vars(), 'A value that never went through sanitize() is still checked' );
	}

	public function test_human_badge_is_not_rewrapped_on_repeated_runs(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_enabled' => '1', 'human_badge' => '1' );
		update_post_meta( 9, TransparAI_Meta::KEY_HUMAN, TransparAI_Meta::DST_CAPTURE );
		$html  = '<p><img class="wp-image-9" src="/wp-content/uploads/2026/09/photo.jpg"></p>';
		$once  = TransparAI_Frontend::wrap_images( $html );
		$twice = TransparAI_Frontend::wrap_images( TransparAI_Frontend::wrap_images( $once ) );
		$this->assertSame( 1, substr_count( $once, 'trai-badge--human' ) );
		$this->assertSame( $once, $twice, 'The human badge must be as idempotent as the AI badge' );
	}
}
