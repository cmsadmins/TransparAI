<?php
/**
 * Labelled link preview image: which posts get one, the variant that
 * follows the badge settings, scheduling instead of drawing in a request,
 * the SEO plugin filters, the drawing itself and the cleanup.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class OgImageTest extends TestCase {

	private const POST       = 11;
	private const ATTACHMENT = 5;

	private string $dir = '';

	protected function setUp(): void {
		global $trai_test_options, $trai_test_current_post;
		trai_test_reset();
		$this->dir = sys_get_temp_dir() . '/trai-og-' . uniqid();
		mkdir( $this->dir );
		copy( trai_fixture( 'base.jpg' ), $this->dir . '/photo.jpg' );

		$trai_test_options[ TransparAI_Options::OPTION ] = array( 'og_image_label' => '1' );
		$trai_test_current_post                          = self::POST;
		update_post_meta( self::POST, '_thumbnail_id', self::ATTACHMENT );
		update_post_meta( self::ATTACHMENT, '_test_file', $this->dir . '/photo.jpg' );
		update_post_meta( self::ATTACHMENT, '_test_url', 'https://example.test/wp-content/uploads/photo.jpg' );
		update_post_meta( self::ATTACHMENT, TransparAI_Meta::KEY_FLAG, '1' );
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	private function set_options( array $options ): void {
		global $trai_test_options;
		$trai_test_options[ TransparAI_Options::OPTION ] = array_merge( $trai_test_options[ TransparAI_Options::OPTION ], $options );
	}

	public function test_variant_follows_every_setting_that_changes_the_picture(): void {
		$base = TransparAI_Og_Image::variant( TransparAI_Og_Image::spec( self::ATTACHMENT ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $base );

		$changes = array(
			array( 'badge_style' => 'light' ),
			array( 'badge_size' => 'large' ),
			array( 'badge_position' => 'top-left' ),
			array( 'badge_text' => 'Made with AI' ),
			array( 'badge_color' => '#ff6800' ),
			array( 'badge_text_color' => '#000000' ),
			array( 'badge_opacity' => '60' ),
		);
		foreach ( $changes as $change ) {
			$this->set_options( $change );
			$this->assertNotSame( $base, TransparAI_Og_Image::variant( TransparAI_Og_Image::spec( self::ATTACHMENT ) ), (string) wp_json_encode( $change ) );
			$this->set_options( array_fill_keys( array_keys( $change ), TransparAI_Options::defaults()[ key( $change ) ] ) );
		}
		$this->assertSame( $base, TransparAI_Og_Image::variant( TransparAI_Og_Image::spec( self::ATTACHMENT ) ), 'back to the same settings, same file' );

		$this->set_options( array( 'page_notice' => '1' ) );
		$this->assertSame( $base, TransparAI_Og_Image::variant( TransparAI_Og_Image::spec( self::ATTACHMENT ) ), 'unrelated settings keep the file' );

		update_post_meta( self::ATTACHMENT, TransparAI_Meta::KEY_BADGE_POS, 'top-right' );
		$this->assertNotSame( $base, TransparAI_Og_Image::variant( TransparAI_Og_Image::spec( self::ATTACHMENT ) ), 'per-image position' );
	}

	public function test_only_badged_images_qualify(): void {
		$this->assertTrue( TransparAI_Og_Image::applies( self::ATTACHMENT ) );

		update_post_meta( self::ATTACHMENT, TransparAI_Meta::KEY_BADGE_POS, 'hidden' );
		$this->assertFalse( TransparAI_Og_Image::applies( self::ATTACHMENT ), 'badge hidden for this image' );
		delete_post_meta( self::ATTACHMENT, TransparAI_Meta::KEY_BADGE_POS );

		$this->set_options( array( 'badge_enabled' => '0' ) );
		$this->assertFalse( TransparAI_Og_Image::applies( self::ATTACHMENT ), 'badge switched off' );
		$this->set_options( array( 'badge_enabled' => '1' ) );

		delete_post_meta( self::ATTACHMENT, TransparAI_Meta::KEY_FLAG );
		$this->assertFalse( TransparAI_Og_Image::applies( self::ATTACHMENT ), 'not labeled' );
	}

	public function test_a_missing_copy_is_scheduled_not_drawn_and_filters_keep_the_original(): void {
		global $trai_test_cron;
		$this->assertNull( TransparAI_Og_Image::current() );
		$this->assertSame( array( self::ATTACHMENT ), $trai_test_cron[ TransparAI_Og_Image::EVENT ] );
		$this->assertSame( array(), (array) glob( $this->dir . '/*-og-*' ), 'nothing drawn during the request' );
		$this->assertSame( 'https://example.test/original.jpg', TransparAI_Og_Image::filter_url( 'https://example.test/original.jpg' ) );
		$this->assertSame( 800, TransparAI_Og_Image::filter_width( 800 ) );
	}

	public function test_generated_copy_is_served_marked_and_replaced(): void {
		if ( ! TransparAI_Og_Image::supported() ) {
			$this->markTestSkipped( 'GD with FreeType is not available.' );
		}
		TransparAI_Og_Image::generate( self::ATTACHMENT );
		$stored = get_post_meta( self::ATTACHMENT, TransparAI_Og_Image::META, true );
		$path   = $this->dir . '/' . $stored['file'];

		$this->assertMatchesRegularExpression( '/^photo-og-[0-9a-f]{8}\.jpg$/', $stored['file'] );
		$size = getimagesize( $path );
		$this->assertSame( array( 1200, 630, IMAGETYPE_JPEG ), array( $size[0], $size[1], $size[2] ) );
		$this->assertTrue( TransparAI_Writer::file_is_marked( $path ), 'the copy carries the declaration too' );

		$copy = TransparAI_Og_Image::current();
		$url  = 'https://example.test/wp-content/uploads/' . $stored['file'];
		$this->assertSame( $url, $copy['url'] );
		$this->assertSame( $url, TransparAI_Og_Image::filter_url( 'https://example.test/original.jpg' ) );
		$this->assertSame( 1200, TransparAI_Og_Image::filter_width( 800 ) );
		$this->assertSame( 630, TransparAI_Og_Image::filter_height( 800 ) );
		$this->assertSame( 'image/jpeg', TransparAI_Og_Image::filter_type( 'image/png' ) );
		$this->assertSame(
			array(
				'alt'    => 'Alt',
				'url'    => $url,
				'width'  => 1200,
				'height' => 630,
				'type'   => 'image/jpeg',
			),
			TransparAI_Og_Image::filter_rank_math(
				array(
					'alt' => 'Alt',
					'url' => 'https://example.test/original.jpg',
				)
			)
		);
		$this->assertSame( $url, TransparAI_Og_Image::filter_aioseo_facebook( array() )['og:image'] );
		$this->assertSame( $url, TransparAI_Og_Image::filter_aioseo_twitter( array() )['twitter:image'] );

		ob_start();
		TransparAI_Og_Image::print_tags();
		$this->assertStringContainsString( '<meta property="og:image" content="' . $url . '" />', (string) ob_get_clean() );

		/* New settings: new file, the previous one stays one generation. */
		$this->set_options( array( 'badge_style' => 'light' ) );
		TransparAI_Og_Image::generate( self::ATTACHMENT );
		$second = get_post_meta( self::ATTACHMENT, TransparAI_Og_Image::META, true );
		$this->assertNotSame( $stored['file'], $second['file'] );
		$this->assertSame( $stored['file'], $second['previous'] );
		$this->assertFileExists( $path );

		$this->set_options( array( 'badge_style' => 'outline' ) );
		TransparAI_Og_Image::generate( self::ATTACHMENT );
		$this->assertFileDoesNotExist( $path, 'the copy before the previous one is removed' );

		/* Label removed: copies go. */
		delete_post_meta( self::ATTACHMENT, TransparAI_Meta::KEY_FLAG );
		TransparAI_Og_Image::on_meta_change( 0, self::ATTACHMENT, TransparAI_Meta::KEY_FLAG );
		$this->assertSame( array(), (array) glob( $this->dir . '/*-og-*' ) );
		$this->assertSame( '', get_post_meta( self::ATTACHMENT, TransparAI_Og_Image::META, true ) );
	}

	public function test_failed_drawing_is_not_retried_for_the_same_variant(): void {
		global $trai_test_cron;
		update_post_meta( self::ATTACHMENT, '_test_file', $this->dir . '/missing.jpg' );
		TransparAI_Og_Image::generate( self::ATTACHMENT );
		$this->assertNotSame( '', get_post_meta( self::ATTACHMENT, TransparAI_Og_Image::META_FAILED, true ) );

		$trai_test_cron = array();
		$this->assertNull( TransparAI_Og_Image::current() );
		$this->assertArrayNotHasKey( TransparAI_Og_Image::EVENT, $trai_test_cron );
	}

	public function test_off_by_default(): void {
		$this->assertSame( '0', TransparAI_Options::defaults()['og_image_label'] );
		$this->set_options( array( 'og_image_label' => '0' ) );
		$this->assertNull( TransparAI_Og_Image::current() );
	}
}
