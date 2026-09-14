<?php
/**
 * WooCommerce: variation images, e-mail rendering and the product text note.
 *
 * Product, shop-loop, related and cross-sell images already run through
 * wp_get_attachment_image() and carry their badge server-side. What that
 * cannot cover: a variation swap rewrites the gallery image in place (the
 * badge of the parent image would stay on a different photo), the zoom and
 * lightbox clone the image outside the gallery, and HTML e-mails must never
 * carry an absolutely positioned overlay. All hooks are no-ops without
 * WooCommerce.
 *
 * @package   TransparAI
 * @author    Patrick Schlesinger
 * @copyright 2026 Patrick Schlesinger
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce integration.
 */
final class TransparAI_WooCommerce {

	/**
	 * True while WooCommerce renders an e-mail.
	 *
	 * @var bool
	 */
	private static bool $muted = false;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'before_woocommerce_init', array( self::class, 'declare_compatibility' ) );
		add_filter( 'woocommerce_available_variation', array( self::class, 'variation_data' ) );

		/*
		 * E-mail clients drop or misplace positioned overlays, so a badge
		 * there is either invisible or bare text in the wrong spot. Header
		 * and footer bracket every WooCommerce e-mail template.
		 */
		add_action( 'woocommerce_email_header', array( self::class, 'mute' ), 1 );
		add_action( 'woocommerce_email_footer', array( self::class, 'unmute' ), PHP_INT_MAX );

		/* After the product meta (40) and before the sharing links (50). */
		add_action( 'woocommerce_single_product_summary', array( self::class, 'product_notice' ), 45 );
	}

	/**
	 * High-Performance Order Storage: this plugin touches no orders, and
	 * without the declaration WooCommerce lists it as incompatible anyway.
	 */
	public static function declare_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TRANSPARAI_PLUGIN_FILE, true );
		}
	}

	/**
	 * Hand the label state of the variation image to the front-end script,
	 * which listens to WooCommerce's own found_variation and reset_data
	 * events (they fire in AJAX mode too, where no inline JSON exists).
	 * Null is meaningful: a variation without its own image must not keep
	 * the parent image's badge.
	 *
	 * @param array<string, mixed>|mixed $data Variation data (product and variation objects are not needed).
	 * @return array<string, mixed>|mixed
	 */
	public static function variation_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$image_id           = (int) ( $data['image_id'] ?? 0 );
		$data['transparai'] = $image_id > 0 ? TransparAI_Frontend::public_label( $image_id ) : null;
		return $data;
	}

	/**
	 * Start of an e-mail template.
	 */
	public static function mute(): void {
		self::$muted = true;
	}

	/**
	 * End of an e-mail template.
	 */
	public static function unmute(): void {
		self::$muted = false;
	}

	/**
	 * Whether badge output is suspended (e-mail rendering).
	 */
	public static function is_muted(): bool {
		return self::$muted;
	}

	/**
	 * The text disclosure note in the product summary. The description tab
	 * gets the automatic note through the_content as well; marking the
	 * post as placed keeps it from appearing twice on one product page.
	 */
	public static function product_notice(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}
		$post_id = (int) get_the_ID();
		$text    = TransparAI_Notice::text( $post_id );
		if ( '' === $text ) {
			return;
		}
		TransparAI_Notice::mark_placed( $post_id );
		echo TransparAI_Notice::render( array( 'text' => $text ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes.
	}
}
