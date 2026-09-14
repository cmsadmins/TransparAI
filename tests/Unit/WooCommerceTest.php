<?php
/**
 * WooCommerce integration: variation data for the front-end script, the
 * e-mail mute that suspends badge output, and the product note that keeps
 * the automatic note from appearing twice.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class WooCommerceTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
		TransparAI_WooCommerce::unmute();
		$prop = new ReflectionProperty( TransparAI_Frontend::class, 'rendered_ids' );
		if ( PHP_VERSION_ID < 80100 ) {
			$prop->setAccessible( true );
		}
		$prop->setValue( null, array() );
	}

	public function test_variation_data_carries_the_label_or_an_explicit_null(): void {
		global $trai_test_options, $trai_test_meta;
		$trai_test_options['transparai_settings'] = array(
			'badge_enabled' => '1',
			'badge_text'    => '{generator} image',
		);
		set_transient( 'transparai_url_map', array() );
		update_post_meta( 7, TransparAI_Meta::KEY_FLAG, '1' );
		update_post_meta( 7, TransparAI_Meta::KEY_GENERATOR, 'DALL-E' );
		$trai_test_meta[7]['_wp_attached_file'] = '2026/09/red-scaled.jpg';

		$data = TransparAI_WooCommerce::variation_data( array( 'image_id' => 7 ) );
		$this->assertSame( 'ai', $data['transparai']['kind'] );
		$this->assertSame( 'DALL-E image', $data['transparai']['label'] );
		$this->assertSame( '2026/09/red.jpg', $data['transparai']['path'], 'Normalized path so the lightbox clone can be matched' );
		$this->assertStringContainsString( 'trai-wrap', $data['transparai']['classes'] );
		$this->assertFalse( $data['transparai']['human'] );

		$this->assertNull( TransparAI_WooCommerce::variation_data( array( 'image_id' => 8 ) )['transparai'], 'Unlabeled image: nothing to show' );
		$this->assertNull( TransparAI_WooCommerce::variation_data( array( 'image_id' => 0 ) )['transparai'], 'No own image: the parent badge must not stick' );
		$this->assertSame( 'x', TransparAI_WooCommerce::variation_data( 'x' ), 'Foreign shapes pass through' );

		/* Declared human: only with the opt-in badge, and then as human. */
		update_post_meta( 9, TransparAI_Meta::KEY_HUMAN, 'digitalCapture' );
		$this->assertNull( TransparAI_WooCommerce::variation_data( array( 'image_id' => 9 ) )['transparai'] );
		$trai_test_options['transparai_settings']['human_badge'] = '1';
		$human                                                    = TransparAI_WooCommerce::variation_data( array( 'image_id' => 9 ) )['transparai'];
		$this->assertSame( 'human', $human['kind'] );
		$this->assertSame( 'Human made', $human['label'] );
	}

	public function test_email_rendering_mutes_badges(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array( 'badge_enabled' => '1' );
		set_transient( 'transparai_url_map', array() );
		update_post_meta( 12, TransparAI_Meta::KEY_FLAG, '1' );
		$tag = '<img class="wp-image-12" src="/wp-content/uploads/2026/09/a.jpg">';

		$this->assertStringContainsString( 'trai-badge', TransparAI_Frontend::filter_content( $tag ) );

		TransparAI_WooCommerce::mute();
		$this->assertTrue( TransparAI_WooCommerce::is_muted() );
		$this->assertSame( $tag, TransparAI_Frontend::filter_content( $tag ), 'No overlay markup inside an e-mail' );
		$this->assertSame( $tag, TransparAI_Frontend::filter_attachment_image( $tag, 12 ) );
		$this->assertNull( TransparAI_WooCommerce::variation_data( array( 'image_id' => 12 ) )['transparai'] );

		TransparAI_WooCommerce::unmute();
		$this->assertStringContainsString( 'trai-badge', TransparAI_Frontend::filter_content( $tag ) );
	}

	public function test_placed_note_silences_the_automatic_one(): void {
		global $trai_test_posts, $trai_test_current_post;
		$post                    = new WP_Post();
		$post->ID                = 60;
		$trai_test_posts[60]     = $post;
		$trai_test_current_post  = 60;
		update_post_meta( 60, TransparAI_Meta::KEY_CONTENT_AI, 'generated' );

		$this->assertStringContainsString( 'trai-notice', TransparAI_Notice::filter_content( '<p>Desc</p>' ) );
		TransparAI_Notice::mark_placed( 60 );
		$this->assertSame( '<p>Desc</p>', TransparAI_Notice::filter_content( '<p>Desc</p>' ) );
	}
}
