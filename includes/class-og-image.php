<?php
/**
 * Link preview image: a 1200 x 630 copy of a post's featured image with the
 * AI badge drawn in, handed to social networks as og:image.
 *
 * Networks strip file metadata when a link is shared, so the declaration in
 * the image file never reaches the preview. The copy carries the visible
 * badge exactly as the site shows it (text, style, colours, opacity, size,
 * position, EU icon) plus the IPTC declaration in its own XMP. The original
 * file is never touched.
 *
 * Files are drawn with GD in a cron event, never while a visitor's request
 * is served: a page without a current copy schedules one and keeps the
 * regular og:image until it exists. Every change to the badge settings
 * yields a new file name, so networks fetch the new picture instead of
 * serving a cached one.
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
 * Labelled link preview images.
 */
final class TransparAI_Og_Image {

	public const WIDTH  = 1200;
	public const HEIGHT = 630;

	/** Post meta: {file, variant, previous} of the current copy. */
	public const META = '_transparai_og';

	/** Post meta: the variant whose drawing failed, so it is not retried on every view. */
	public const META_FAILED = '_transparai_og_failed';

	/** Cron hook that draws one copy. */
	public const EVENT = 'transparai_og_generate';

	/** Bumped when the drawing itself changes, so existing copies are redrawn. */
	private const RENDER_VERSION = '1';

	/**
	 * Badge size on the preview relative to the site's CSS pixels. Feeds show
	 * the 1200 px image at roughly 400 to 500 px, so three times the CSS size
	 * reads like the badge on the page.
	 */
	private const SCALE = 3;

	/** Supersampling of the badge drawing; GD only antialiases text. */
	private const SUPERSAMPLE = 4;

	/**
	 * CSS sizes of the badge per size setting (assets/css/front.css):
	 * font px, vertical and horizontal padding px, EU icon height px.
	 */
	private const SIZES = array(
		'small'  => array( 9, 3, 5, 16 ),
		'medium' => array( 11, 4, 7, 22 ),
		'large'  => array( 13, 5, 9, 30 ),
	);

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( self::EVENT, array( self::class, 'generate' ) );
		add_action( 'delete_attachment', array( self::class, 'delete_files' ) );
		add_action( 'added_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );

		if ( ! TransparAI_Options::enabled( 'og_image_label' ) ) {
			return;
		}
		add_filter( 'wpseo_opengraph_image', array( self::class, 'filter_url' ) );
		add_filter( 'wpseo_opengraph_image_width', array( self::class, 'filter_width' ) );
		add_filter( 'wpseo_opengraph_image_height', array( self::class, 'filter_height' ) );
		add_filter( 'wpseo_opengraph_image_type', array( self::class, 'filter_type' ) );
		add_filter( 'wpseo_twitter_image', array( self::class, 'filter_url' ) );
		add_filter( 'rank_math/opengraph/facebook/image_array', array( self::class, 'filter_rank_math' ) );
		add_filter( 'rank_math/opengraph/twitter/image_array', array( self::class, 'filter_rank_math' ) );
		add_filter( 'aioseo_facebook_tags', array( self::class, 'filter_aioseo_facebook' ) );
		add_filter( 'aioseo_twitter_tags', array( self::class, 'filter_aioseo_twitter' ) );
		add_action( 'wp_head', array( self::class, 'print_tags' ), 5 );
	}

	/**
	 * Whether this server can draw the copy: GD with JPEG output and FreeType.
	 */
	public static function supported(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' ) && function_exists( 'imagettftext' ) && function_exists( 'imagecreatefromstring' );
	}

	/**
	 * An SEO plugin that prints its own Open Graph tags. Those this class
	 * filters get the labelled URL; with any of them active the fallback tags
	 * stay off, so a page never carries two og:image tags.
	 */
	public static function seo_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' ) || defined( 'SLIM_SEO_VER' );
	}

	/* ---------------------------------------------------------------------
	 * Which image, which look
	 * ------------------------------------------------------------------- */

	/**
	 * Whether an attachment gets a labelled copy: it shows the AI badge on
	 * the site and is not hidden per image.
	 */
	public static function applies( int $attachment_id ): bool {
		return $attachment_id > 0
			&& TransparAI_Options::enabled( 'badge_enabled' )
			&& TransparAI_Frontend::is_badged( $attachment_id )
			&& 'hidden' !== TransparAI_Meta::get_badge_position( $attachment_id )
			&& wp_attachment_is_image( $attachment_id );
	}

	/**
	 * Everything the drawing depends on, mirrored from the badge settings.
	 *
	 * @return array{style:string, size:string, position:string, text:string, short:string, icon:string, bg:string, fg:string, opacity:int, source:string}
	 */
	public static function spec( int $attachment_id ): array {
		$style    = TransparAI_Options::get( 'badge_style' );
		$size     = TransparAI_Options::get( 'badge_size' );
		$position = TransparAI_Meta::get_badge_position( $attachment_id );
		if ( ! in_array( $position, array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' ), true ) ) {
			$position = TransparAI_Options::get( 'badge_position' ); /* '' (site setting) and 'below' (caption line). */
		}
		$colors = TransparAI_Frontend::badge_css_vars();
		$icon   = '';
		if ( 'eu-icon' === $style ) {
			$url  = TransparAI_Frontend::eu_icon_url( TransparAI_Meta::get_type( $attachment_id ) );
			$icon = (string) preg_replace( '/\.svg$/', '.png', basename( $url ) );
		}
		return array(
			'style'    => $style,
			'size'     => isset( self::SIZES[ $size ] ) ? $size : 'medium',
			'position' => $position,
			'text'     => TransparAI_Frontend::badge_text( $attachment_id ),
			'short'    => TransparAI_Frontend::badge_short_label(),
			'icon'     => $icon,
			'bg'       => $colors['--trai-badge-bg'] ?? '',
			'fg'       => $colors['--trai-badge-fg'] ?? '',
			'opacity'  => isset( $colors['--trai-badge-opacity'] ) ? (int) round( (float) $colors['--trai-badge-opacity'] * 100 ) : 100,
			'source'   => self::source_file( $attachment_id ),
		);
	}

	/**
	 * Short fingerprint of a spec, part of the file name.
	 *
	 * ponytail: one copy per image. A site that serves the badge text in
	 * several languages redraws the copy whenever the language of the
	 * viewed page changes; per-language copies if that ever matters.
	 *
	 * @param array<string, mixed> $spec Spec from spec().
	 */
	public static function variant( array $spec ): string {
		return substr( md5( self::RENDER_VERSION . '|' . (string) wp_json_encode( $spec ) ), 0, 8 );
	}

	/**
	 * The smallest stored file that still covers 1200 x 630, else the
	 * original: decoding a 6000 px original costs far more memory.
	 */
	private static function source_file( int $attachment_id ): string {
		$file = (string) get_attached_file( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );
		$best = '';
		$area = PHP_INT_MAX;
		foreach ( (array) ( is_array( $meta ) ? ( $meta['sizes'] ?? array() ) : array() ) as $size ) {
			$width  = (int) ( $size['width'] ?? 0 );
			$height = (int) ( $size['height'] ?? 0 );
			if ( $width >= self::WIDTH && $height >= self::HEIGHT && $width * $height < $area && ! empty( $size['file'] ) ) {
				$best = (string) $size['file'];
				$area = $width * $height;
			}
		}
		return '' === $best ? $file : trailingslashit( dirname( $file ) ) . $best;
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------- */

	/**
	 * The labelled copy for the page being viewed, null when there is none
	 * (yet). A missing or outdated copy is scheduled here.
	 *
	 * @return array{url:string, width:int, height:int}|null
	 */
	public static function current(): ?array {
		if ( ! TransparAI_Options::enabled( 'og_image_label' ) || ! self::supported() || ! is_singular() ) {
			return null;
		}
		$attachment_id = (int) get_post_thumbnail_id( (int) get_queried_object_id() );
		if ( ! self::applies( $attachment_id ) ) {
			return null;
		}
		$variant = self::variant( self::spec( $attachment_id ) );
		$stored  = get_post_meta( $attachment_id, self::META, true );
		if ( is_array( $stored ) && ( $stored['variant'] ?? '' ) === $variant && is_file( self::path( $attachment_id, (string) $stored['file'] ) ) ) {
			return array(
				'url'    => trailingslashit( dirname( (string) wp_get_attachment_url( $attachment_id ) ) ) . rawurlencode( (string) $stored['file'] ),
				'width'  => self::WIDTH,
				'height' => self::HEIGHT,
			);
		}
		if ( get_post_meta( $attachment_id, self::META_FAILED, true ) !== $variant ) {
			self::schedule( $attachment_id );
		}
		return null;
	}

	/**
	 * Yoast SEO: the og:image and twitter:image URL.
	 *
	 * @param mixed $url URL the SEO plugin resolved.
	 * @return mixed
	 */
	public static function filter_url( $url ) {
		$copy = self::current();
		return null === $copy ? $url : $copy['url'];
	}

	/**
	 * Yoast SEO: og:image:width of the copy.
	 *
	 * @param mixed $value Value Yoast resolved for the original.
	 * @return mixed
	 */
	public static function filter_width( $value ) {
		return null === self::current() ? $value : self::WIDTH;
	}

	/**
	 * Yoast SEO: og:image:height of the copy.
	 *
	 * @param mixed $value Value Yoast resolved for the original.
	 * @return mixed
	 */
	public static function filter_height( $value ) {
		return null === self::current() ? $value : self::HEIGHT;
	}

	/**
	 * Yoast SEO: og:image:type of the copy.
	 *
	 * @param mixed $value Value Yoast resolved for the original.
	 * @return mixed
	 */
	public static function filter_type( $value ) {
		return null === self::current() ? $value : 'image/jpeg';
	}

	/**
	 * Rank Math: the image record of og:image and twitter:image, size and
	 * type included (its plain URL filter drops them).
	 *
	 * @param mixed $attachment Image record: url, width, height, type, alt.
	 * @return mixed
	 */
	public static function filter_rank_math( $attachment ) {
		$copy = self::current();
		if ( null === $copy ) {
			return $attachment;
		}
		return array_merge(
			is_array( $attachment ) ? $attachment : array(),
			array(
				'url'    => $copy['url'],
				'width'  => self::WIDTH,
				'height' => self::HEIGHT,
				'type'   => 'image/jpeg',
			)
		);
	}

	/**
	 * All in One SEO: Facebook tags.
	 *
	 * @param mixed $tags Tag name => value.
	 * @return mixed
	 */
	public static function filter_aioseo_facebook( $tags ) {
		$copy = self::current();
		if ( null === $copy || ! is_array( $tags ) ) {
			return $tags;
		}
		$tags['og:image']            = $copy['url'];
		$tags['og:image:secure_url'] = str_starts_with( $copy['url'], 'https://' ) ? $copy['url'] : '';
		$tags['og:image:width']      = self::WIDTH;
		$tags['og:image:height']     = self::HEIGHT;
		return $tags;
	}

	/**
	 * All in One SEO: X (Twitter) tags.
	 *
	 * @param mixed $tags Tag name => value.
	 * @return mixed
	 */
	public static function filter_aioseo_twitter( $tags ) {
		$copy = self::current();
		if ( null === $copy || ! is_array( $tags ) ) {
			return $tags;
		}
		$tags['twitter:image'] = $copy['url'];
		return $tags;
	}

	/**
	 * Own tags for sites without an SEO plugin that prints Open Graph.
	 */
	public static function print_tags(): void {
		if ( self::seo_plugin_active() ) {
			return;
		}
		$copy = self::current();
		if ( null === $copy ) {
			return;
		}
		printf(
			'<meta property="og:image" content="%1$s" />' . "\n" . '<meta property="og:image:width" content="%2$d" />' . "\n" . '<meta property="og:image:height" content="%3$d" />' . "\n" . '<meta name="twitter:image" content="%1$s" />' . "\n",
			esc_url( $copy['url'] ),
			(int) $copy['width'],
			(int) $copy['height']
		);
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle
	 * ------------------------------------------------------------------- */

	/**
	 * Schedule drawing one copy (once; a pending event is not duplicated).
	 */
	public static function schedule( int $attachment_id ): void {
		if ( false === wp_next_scheduled( self::EVENT, array( $attachment_id ) ) ) {
			wp_schedule_single_event( time(), self::EVENT, array( $attachment_id ) );
		}
	}

	/**
	 * Label changes: an image that lost its label loses its copies; any
	 * other change redraws on the next view (variant comparison).
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public static function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id );
		if ( TransparAI_Meta::KEY_FLAG !== $meta_key && TransparAI_Meta::KEY_BADGE_POS !== $meta_key ) {
			return;
		}
		if ( ! self::applies( (int) $object_id ) ) {
			self::delete_files( (int) $object_id );
		}
	}

	/**
	 * Draw the copy of one attachment (cron). Keeps the previous file one
	 * more generation, because cached pages may still point at it.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function generate( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( ! self::supported() || ! self::applies( $attachment_id ) ) {
			return;
		}
		$spec    = self::spec( $attachment_id );
		$variant = self::variant( $spec );
		$stored  = get_post_meta( $attachment_id, self::META, true );
		$stored  = is_array( $stored ) ? $stored : array();
		if ( ( $stored['variant'] ?? '' ) === $variant && is_file( self::path( $attachment_id, (string) ( $stored['file'] ?? '' ) ) ) ) {
			return;
		}

		$base = sanitize_file_name( pathinfo( (string) get_attached_file( $attachment_id ), PATHINFO_FILENAME ) );
		$file = $base . '-og-' . $variant . '.jpg';
		if ( ! self::render( $spec, self::path( $attachment_id, $file ) ) ) {
			update_post_meta( $attachment_id, self::META_FAILED, $variant );
			return;
		}
		if ( TransparAI_Options::enabled( 'write_xmp' ) ) {
			TransparAI_Writer::write_file( self::path( $attachment_id, $file ), 'jpeg', TransparAI_Meta::get_type( $attachment_id ) );
		}

		$previous = (string) ( $stored['file'] ?? '' );
		$stale    = (string) ( $stored['previous'] ?? '' );
		if ( '' !== $stale && $stale !== $file && $stale !== $previous ) {
			wp_delete_file( self::path( $attachment_id, $stale ) );
		}
		update_post_meta(
			$attachment_id,
			self::META,
			array(
				'file'     => $file,
				'variant'  => $variant,
				'previous' => $previous !== $file ? $previous : $stale,
			)
		);
		delete_post_meta( $attachment_id, self::META_FAILED );
	}

	/**
	 * Remove the copies of one attachment and their meta.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function delete_files( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		$stored        = get_post_meta( $attachment_id, self::META, true );
		if ( is_array( $stored ) ) {
			foreach ( array( 'file', 'previous' ) as $key ) {
				$name = (string) ( $stored[ $key ] ?? '' );
				if ( '' !== $name ) {
					wp_delete_file( self::path( $attachment_id, $name ) );
				}
			}
			delete_post_meta( $attachment_id, self::META );
		}
		delete_post_meta( $attachment_id, self::META_FAILED );
	}

	/**
	 * Absolute path of a copy next to the original. Only plain file names
	 * written by this class are accepted.
	 */
	private static function path( int $attachment_id, string $file ): string {
		if ( '' === $file || basename( $file ) !== $file ) {
			return '';
		}
		return trailingslashit( dirname( (string) get_attached_file( $attachment_id ) ) ) . $file;
	}

	/* ---------------------------------------------------------------------
	 * Drawing
	 * ------------------------------------------------------------------- */

	/**
	 * Draw the preview: source cropped to 1200 x 630 like a hard-cropped
	 * WordPress size (cover, centred), badge on top, saved as JPEG.
	 *
	 * @param array<string, mixed> $spec Spec from spec().
	 */
	public static function render( array $spec, string $target ): bool {
		$source = (string) $spec['source'];
		if ( '' === $target || ! is_readable( $source ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local media file, not a remote request.
		$bytes = file_get_contents( $source );
		$photo = false === $bytes ? false : @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt file is reported as a failed copy, not as a PHP warning.
		if ( false === $photo ) {
			return false;
		}

		$canvas = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		$src_w  = imagesx( $photo );
		$src_h  = imagesy( $photo );
		$ratio  = min( $src_w / self::WIDTH, $src_h / self::HEIGHT );
		$crop_w = (int) round( self::WIDTH * $ratio );
		$crop_h = (int) round( self::HEIGHT * $ratio );
		imagefill( $canvas, 0, 0, (int) imagecolorallocate( $canvas, 255, 255, 255 ) ); /* Transparent PNGs on white. */
		imagecopyresampled( $canvas, $photo, 0, 0, (int) ( ( $src_w - $crop_w ) / 2 ), (int) ( ( $src_h - $crop_h ) / 2 ), self::WIDTH, self::HEIGHT, $crop_w, $crop_h );
		imagedestroy( $photo );

		$badge = self::badge( $spec );
		if ( null !== $badge ) {
			$margin = 8 * self::SCALE;
			$w      = imagesx( $badge );
			$h      = imagesy( $badge );
			$x      = str_ends_with( (string) $spec['position'], 'left' ) ? $margin : self::WIDTH - $margin - $w;
			$y      = str_starts_with( (string) $spec['position'], 'top' ) ? $margin : self::HEIGHT - $margin - $h;
			imagealphablending( $canvas, true );
			imagecopy( $canvas, $badge, $x, $y, 0, 0, $w, $h );
			imagedestroy( $badge );
		}

		$ok = imagejpeg( $canvas, $target, 85 );
		imagedestroy( $canvas );
		return $ok;
	}

	/**
	 * The badge as a transparent image, drawn at four times its size and
	 * scaled down for smooth edges, with the badge opacity applied.
	 *
	 * @param array<string, mixed> $spec Spec from spec().
	 * @return GdImage|resource|null
	 */
	private static function badge( array $spec ) {
		list( $font_px, $pad_y, $pad_x, $icon_h ) = self::SIZES[ (string) $spec['size'] ];

		if ( 'eu-icon' === $spec['style'] ) {
			$icon = @imagecreatefrompng( TRANSPARAI_PLUGIN_DIR . 'assets/img/eu/' . basename( (string) $spec['icon'] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing icon means no badge, not a warning.
			if ( false === $icon ) {
				return null;
			}
			$h     = $icon_h * self::SCALE;
			$w     = (int) round( imagesx( $icon ) * $h / imagesy( $icon ) );
			$badge = self::transparent( $w, $h );
			imagecopyresampled( $badge, $icon, 0, 0, 0, 0, $w, $h, imagesx( $icon ), imagesy( $icon ) );
			imagedestroy( $icon );
			return self::fade( $badge, (int) $spec['opacity'] );
		}

		$icon_only = 'icon-only' === $spec['style'];
		if ( $icon_only ) {
			list( $font_px, $pad_y, $pad_x ) = array( 10, 3, 5 );
		}
		$light   = 'light' === $spec['style'];
		$outline = 'outline' === $spec['style'];
		$font    = TRANSPARAI_PLUGIN_DIR . 'assets/fonts/OpenSans-' . ( $icon_only ? 'Bold' : 'Regular' ) . '.ttf';
		$k       = self::SCALE * self::SUPERSAMPLE;
		$size    = $font_px * $k * 0.75; /* GD sizes text in points at 96 dpi. */
		$border  = $outline ? $k : 0;
		$text    = self::fit( $icon_only ? (string) $spec['short'] : (string) $spec['text'], $font, $size, ( self::WIDTH - 16 * self::SCALE - 2 * $pad_x * self::SCALE ) * self::SUPERSAMPLE );

		$box    = imagettfbbox( $size, 0, $font, $text );
		$text_w = false === $box ? 0 : (int) ( $box[2] - $box[0] );
		$w      = $text_w + 2 * $pad_x * $k + 2 * $border;
		$h      = $font_px * $k + 2 * $pad_y * $k + 2 * $border;
		$big    = self::transparent( $w, $h );
		$fg     = self::color( (string) $spec['fg'], $light ? array( 0, 0, 0, 1.0 ) : array( 255, 255, 255, 1.0 ) );
		$bg     = $outline ? array( 0, 0, 0, 0.0 ) : self::color( (string) $spec['bg'], $light ? array( 255, 255, 254, 0.88 ) : array( 0, 0, 0, 0.7 ) );
		$radius = 4 * $k;
		$ring   = $outline ? self::color( (string) $spec['fg'], array( 255, 255, 254, 0.9 ) ) : $bg;

		self::rounded( $big, 0, 0, $w, $h, $radius, $ring );
		if ( $outline ) {
			self::rounded( $big, $border, $border, $w - $border, $h - $border, max( 0, $radius - $border ), $bg );
		}

		imagealphablending( $big, true );
		$baseline = (int) round( $border + $pad_y * $k + 0.888 * $font_px * $k ); /* line-height 1: Open Sans ascent 1.069 em minus half the extra leading. */
		$left     = $border + $pad_x * $k - ( false === $box ? 0 : (int) $box[0] );
		if ( $outline ) {
			/* text-shadow: 0 0 3px rgba(0,0,0,.7), approximated by a ring of offset copies. */
			$shadow = self::allocate( $big, array( 0, 0, 0, 0.35 ) );
			foreach ( array( array( -1, 0 ), array( 1, 0 ), array( 0, -1 ), array( 0, 1 ) ) as list( $dx, $dy ) ) {
				imagettftext( $big, $size, 0, $left + $dx * $k, $baseline + $dy * $k, $shadow, $font, $text );
			}
		}
		imagettftext( $big, $size, 0, $left, $baseline, self::allocate( $big, $fg ), $font, $text );

		$small = self::transparent( (int) ceil( $w / self::SUPERSAMPLE ), (int) ceil( $h / self::SUPERSAMPLE ) );
		imagecopyresampled( $small, $big, 0, 0, 0, 0, imagesx( $small ), imagesy( $small ), $w, $h );
		imagedestroy( $big );
		return self::fade( $small, (int) $spec['opacity'] );
	}

	/**
	 * Shorten text with an ellipsis until it fits a width.
	 */
	private static function fit( string $text, string $font, float $size, int $max ): string {
		$box = imagettfbbox( $size, 0, $font, $text );
		if ( false === $box || $box[2] - $box[0] <= $max ) {
			return $text;
		}
		while ( mb_strlen( $text ) > 1 ) {
			$text = mb_substr( $text, 0, -1 );
			$box  = imagettfbbox( $size, 0, $font, rtrim( $text ) . "\u{2026}" );
			if ( false !== $box && $box[2] - $box[0] <= $max ) {
				break;
			}
		}
		return rtrim( $text ) . "\u{2026}";
	}

	/**
	 * A fully transparent true-colour image that keeps its alpha channel
	 * (a resource before PHP 8).
	 *
	 * @return GdImage
	 */
	private static function transparent( int $w, int $h ) {
		$image = imagecreatetruecolor( max( 1, $w ), max( 1, $h ) );
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		imagefilledrectangle( $image, 0, 0, max( 1, $w ), max( 1, $h ), (int) imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
		return $image;
	}

	/**
	 * A filled rounded rectangle, written over (not blended with) what is
	 * there, so overlapping corner pieces do not darken.
	 *
	 * @param GdImage|resource           $image Image.
	 * @param array{0:int, 1:int, 2:int, 3:float} $rgba Colour.
	 */
	private static function rounded( $image, int $x1, int $y1, int $x2, int $y2, int $r, array $rgba ): void {
		imagealphablending( $image, false );
		$color = self::allocate( $image, $rgba );
		$r     = min( $r, (int) floor( ( $x2 - $x1 ) / 2 ), (int) floor( ( $y2 - $y1 ) / 2 ) );
		imagefilledrectangle( $image, $x1 + $r, $y1, $x2 - $r - 1, $y2 - 1, $color );
		imagefilledrectangle( $image, $x1, $y1 + $r, $x2 - 1, $y2 - $r - 1, $color );
		if ( $r > 0 ) {
			foreach ( array( array( $x1 + $r, $y1 + $r ), array( $x2 - $r - 1, $y1 + $r ), array( $x1 + $r, $y2 - $r - 1 ), array( $x2 - $r - 1, $y2 - $r - 1 ) ) as list( $cx, $cy ) ) {
				imagefilledellipse( $image, $cx, $cy, 2 * $r, 2 * $r, $color );
			}
		}
	}

	/**
	 * Allocate an RGBA colour (alpha 0 = transparent, 1 = opaque).
	 *
	 * @param GdImage|resource                    $image Image.
	 * @param array{0:int, 1:int, 2:int, 3:float} $rgba  Colour.
	 */
	private static function allocate( $image, array $rgba ): int {
		return (int) imagecolorallocatealpha( $image, $rgba[0], $rgba[1], $rgba[2], (int) round( 127 * ( 1 - $rgba[3] ) ) );
	}

	/**
	 * A custom #rrggbb colour (opaque, as on the site) or the style's own.
	 *
	 * @param array{0:int, 1:int, 2:int, 3:float} $fallback Style colour.
	 * @return array{0:int, 1:int, 2:int, 3:float}
	 */
	private static function color( string $hex, array $fallback ): array {
		if ( 1 !== preg_match( '/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m ) ) {
			return $fallback;
		}
		return array( (int) hexdec( $m[1] ), (int) hexdec( $m[2] ), (int) hexdec( $m[3] ), 1.0 );
	}

	/**
	 * Apply the badge opacity to every pixel's alpha.
	 *
	 * @param GdImage|resource $image Image with alpha.
	 * @return GdImage|resource
	 */
	private static function fade( $image, int $opacity ) {
		if ( $opacity >= 100 ) {
			return $image;
		}
		$factor = max( 0, $opacity ) / 100;
		$w      = imagesx( $image );
		$h      = imagesy( $image );
		imagealphablending( $image, false );
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$rgba  = imagecolorat( $image, $x, $y );
				$alpha = 127 - (int) round( ( 127 - ( ( $rgba >> 24 ) & 0x7F ) ) * $factor );
				imagesetpixel( $image, $x, $y, (int) imagecolorallocatealpha( $image, ( $rgba >> 16 ) & 0xFF, ( $rgba >> 8 ) & 0xFF, $rgba & 0xFF, $alpha ) );
			}
		}
		return $image;
	}
}
