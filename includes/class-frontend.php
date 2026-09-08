<?php
/**
 * Front end: visible badge on labeled media.
 *
 * Badges are injected server-side into the HTML (page-cache friendly; the
 * small front.js only shrinks badges on tiny images and, optionally, labels
 * CSS background images). Covered surfaces:
 *
 *  - render_block (images via wp-image-{ID} class, video/audio via block attrs)
 *  - the_content (priority 20: classic editor + Elementor post content)
 *  - elementor/frontend/the_content (Theme Builder templates)
 *  - post_thumbnail_html / wp_get_attachment_image (template-driven images)
 *  - widget_text_content
 *
 * Every path shares one wrapper and a per-image guard, so media is never
 * badged twice.
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
 * Badge rendering.
 */
final class TransparAI_Frontend {

	/**
	 * Labeled attachment IDs rendered during this request, for the JSON-LD
	 * block and the optional page notice in the footer.
	 *
	 * @var array<int, true>
	 */
	private static array $rendered_ids = array();

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_footer', array( self::class, 'print_footer_output' ) );

		/* AI-written content note, ahead of the image filters. */
		add_filter( 'the_content', array( self::class, 'filter_content_notice' ), 5 );

		add_filter( 'render_block', array( self::class, 'filter_block' ), 20, 2 );
		add_filter( 'the_content', array( self::class, 'filter_content' ), 20 );
		add_filter( 'widget_text_content', array( self::class, 'filter_content' ), 20 );
		/*
		 * Fires for all Elementor-rendered output, including Theme Builder
		 * templates. Without Elementor the filter simply never runs.
		 */
		add_filter( 'elementor/frontend/the_content', array( self::class, 'filter_content' ), 20 );

		add_filter( 'post_thumbnail_html', array( self::class, 'filter_thumbnail' ), 20, 3 );
		add_filter( 'wp_get_attachment_image', array( self::class, 'filter_attachment_image' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'filter_image_attributes' ), 20, 2 );

		/*
		 * WPBakery builds some image tags by hand (custom sizes, galleries);
		 * its helper hands us the attachment id to tag them for the class path.
		 * Without WPBakery the filter simply never runs.
		 */
		add_filter( 'vc_wpb_getimagesize', array( self::class, 'filter_wpb_image' ), 20, 2 );

		/*
		 * Elementor custom-size images are resized to hash-suffixed files and
		 * carry no attachment class; this widget filter hands us the settings
		 * with the id. Without Elementor the filter simply never runs.
		 */
		add_filter( 'elementor/image_size/get_attachment_image_html', array( self::class, 'filter_elementor_image' ), 20, 4 );

		/* Invalidate the URL map when labels change. */
		add_action( 'added_post_meta', array( self::class, 'maybe_flush_bg_map' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'maybe_flush_bg_map' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'maybe_flush_bg_map' ), 10, 3 );

		/*
		 * Saved settings change which media get badged (start date) and how
		 * badges render; the URL map and cached pages are stale either way.
		 */
		add_action( 'update_option_' . TransparAI_Options::OPTION, array( self::class, 'flush_caches' ) );
	}

	/**
	 * Whether output filtering applies to the current request.
	 *
	 * Builder front-end editors render a live page into their editing canvas;
	 * badges would get baked into the edited content there, so those requests
	 * are excluded. Without the builders every check is a cheap no-op.
	 */
	private static function should_filter(): bool {
		if ( is_admin() || is_feed() || wp_doing_ajax() || ! TransparAI_Options::enabled( 'badge_enabled' ) ) {
			return false;
		}

		/* Elementor preview iframe and static render mode. */
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['render_mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection of a builder editing context.
			return false;
		}

		/* WPBakery front-end editor (official helpers, raw params as fallback). */
		if ( function_exists( 'vc_is_inline' ) && vc_is_inline() ) {
			return false;
		}
		if ( function_exists( 'vc_is_page_editable' ) && vc_is_page_editable() ) {
			return false;
		}
		if ( isset( $_REQUEST['vc_editable'] ) || ( isset( $_REQUEST['vc_action'] ) && 'vc_inline' === $_REQUEST['vc_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection of a builder editing context.
			return false;
		}

		return true;
	}

	/**
	 * Whether an attachment gets the visible front-end badge: labeled, and
	 * uploaded on or after the optional start date. Media uploaded before the
	 * date stay labeled in the admin only. `post_date` (site timezone) against
	 * the Y-m-d setting compares correctly as plain strings.
	 */
	private static function is_badged( int $attachment_id ): bool {
		if ( ! TransparAI_Meta::is_flagged( $attachment_id ) ) {
			return false;
		}
		$from = TransparAI_Options::get( 'badge_from_date' );
		return '' === $from || (string) get_post_field( 'post_date', $attachment_id ) >= $from;
	}

	/**
	 * Localized badge label (full variant). A custom label may contain the
	 * placeholders {generator} and {site}; when {generator} is used but the
	 * attachment has no known generator (or none is in scope, ID 0), the
	 * translated default label is shown instead of a broken template.
	 *
	 * @param int $attachment_id Attachment ID (0 = shared JS labeling template).
	 */
	public static function badge_label( int $attachment_id = 0 ): string {
		$custom = TransparAI_Options::get( 'badge_text' );
		if ( '' === $custom ) {
			return __( 'AI-generated', 'transparai' );
		}
		if ( str_contains( $custom, '{generator}' ) ) {
			$generator = $attachment_id > 0 ? TransparAI_Meta::get_generator( $attachment_id ) : '';
			if ( '' === $generator ) {
				return __( 'AI-generated', 'transparai' );
			}
			$custom = str_replace( '{generator}', $generator, $custom );
		}
		return str_replace( '{site}', get_bloginfo( 'name' ), $custom );
	}

	/**
	 * Short badge label for small images and library tiles.
	 */
	public static function badge_short_label(): string {
		/* translators: very short label shown on small thumbnails, e.g. "AI" or "KI". */
		return _x( 'AI', 'short badge label', 'transparai' );
	}

	/**
	 * The badge element for an attachment.
	 */
	private static function badge_html( int $attachment_id ): string {
		$label = self::badge_label( $attachment_id );
		if ( TransparAI_Options::enabled( 'badge_show_source' )
			&& ! str_contains( TransparAI_Options::get( 'badge_text' ), '{generator}' ) ) {
			$generator = TransparAI_Meta::get_generator( $attachment_id );
			if ( '' !== $generator ) {
				$label .= ' · ' . $generator;
			}
		}
		$html = '<span class="trai-badge" role="note" data-trai-short="' . esc_attr( self::badge_short_label() ) . '">'
			. esc_html( $label ) . '</span>';

		/**
		 * Filters the badge markup of one attachment.
		 *
		 * @param string $html          Badge HTML.
		 * @param int    $attachment_id Attachment ID.
		 */
		return (string) apply_filters( 'transparai_badge_html', $html, $attachment_id );
	}

	/**
	 * Wrapper CSS classes from the badge settings, honoring the per-image
	 * override where the attachment is known (0 = shared JS labeling template).
	 */
	private static function wrap_classes( string $base, int $attachment_id = 0 ): string {
		$override = $attachment_id > 0 ? TransparAI_Meta::get_badge_position( $attachment_id ) : '';
		$position = in_array( $override, array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' ), true )
			? $override
			: TransparAI_Options::get( 'badge_position' );

		$classes = $base
			. ' trai-pos-' . sanitize_html_class( $position )
			. ' trai-size-' . sanitize_html_class( TransparAI_Options::get( 'badge_size' ) )
			. ' trai-style-' . sanitize_html_class( TransparAI_Options::get( 'badge_style' ) )
			. ' trai-mode-' . sanitize_html_class( TransparAI_Options::get( 'badge_mode' ) );

		if ( 'below' === $override ) {
			$classes .= ' trai-badge-below';
		} elseif ( 'hidden' === $override ) {
			$classes .= ' trai-badge-hidden';
		}
		if ( '' !== $override ) {
			/* An editor picked this placement; the overlay guard keeps off. */
			$classes .= ' trai-badge-manual';
		}

		/**
		 * Filters the wrapper CSS classes of one badge.
		 *
		 * @param string $classes       Space-separated class list.
		 * @param int    $attachment_id Attachment ID (0 for the shared JS labeling template).
		 */
		return (string) apply_filters( 'transparai_badge_wrap_classes', $classes, $attachment_id );
	}

	/**
	 * Wrap a media tag together with its badge. The single place that builds
	 * the wrapper element, so escaping and badge placement cannot drift apart
	 * between the content, featured image and template image paths.
	 *
	 * @param string $classes       Wrapper classes (already assembled).
	 * @param string $html          The media HTML to wrap.
	 * @param int    $attachment_id Attachment ID.
	 */
	private static function wrap_media( string $classes, string $html, int $attachment_id ): string {
		return '<span class="' . esc_attr( $classes ) . '">' . $html . self::badge_html( $attachment_id ) . '</span>';
	}

	/**
	 * Enqueue front-end assets (and the optional background map).
	 */
	public static function enqueue(): void {
		if ( ! self::should_filter() ) {
			return;
		}
		wp_enqueue_style( 'transparai-front', TRANSPARAI_PLUGIN_URL . 'assets/css/front.css', array(), TRANSPARAI_VERSION );
		wp_enqueue_script( 'transparai-front', TRANSPARAI_PLUGIN_URL . 'assets/js/front.js', array(), TRANSPARAI_VERSION, true );

		$data = array(
			/* Background badges carry no attachment ID, so {generator} templates fall back to the default label here. */
			'label'      => self::badge_label(),
			'short'      => self::badge_short_label(),
			'guard'      => TransparAI_Options::enabled( 'badge_guard' ) ? '1' : '',
			'classesBg'  => self::wrap_classes( 'trai-bg-host' ),
			'classesImg' => self::wrap_classes( 'trai-wrap' ),
			'bgMap'      => TransparAI_Options::enabled( 'background_badges' ) ? self::background_map() : array(),
		);
		wp_localize_script( 'transparai-front', 'transparaiFront', $data );
	}

	/* ---------------------------------------------------------------------
	 * Content filters
	 * ------------------------------------------------------------------- */

	/**
	 * Wrap every labeled image with the badge markup. Shared by all content
	 * filters. Two lookups per image tag:
	 *  1. wp-image-{ID} class (classic editor, blocks, builders that use core).
	 *  2. src/data-src matched against the URL map of labeled files, which
	 *     covers hand-built builder markup (Elementor custom sizes, WPBakery
	 *     resizes, carousels with lazyload data-src, raw ACF output).
	 *
	 * The negative lookahead keeps the wrap idempotent: our badge always sits
	 * directly after the image, so repeated filter runs (Elementor fires its
	 * content filter inside the_content, the Pro posts widget nests it again)
	 * never double-wrap.
	 */
	public static function wrap_images( string $content ): string {
		if ( '' === $content || ! str_contains( $content, '<img' ) ) {
			return $content;
		}

		$map = self::url_map();

		$wrapped = preg_replace_callback(
			'/<img\b[^>]*>(?!<span class="trai-badge")/i',
			static function ( array $matches ) use ( $map ): string {
				$tag = $matches[0];

				if ( preg_match( '/\bwp-image-(\d+)\b/', $tag, $class_match ) ) {
					$attachment_id = (int) $class_match[1];
					if ( ! self::is_badged( $attachment_id ) ) {
						return $tag;
					}
				} else {
					if ( array() === $map || ! preg_match( '/\s(?:src|data-src)\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $src_match ) ) {
						return $tag;
					}
					$key = self::normalize_upload_path( $src_match[1] );
					if ( '' === $key || ! isset( $map[ $key ] ) ) {
						return $tag;
					}
					$attachment_id = (int) $map[ $key ];
				}

				self::collect( $attachment_id );

				$classes = self::wrap_classes( 'trai-wrap', $attachment_id );
				if ( str_contains( $tag, 'wp-block-cover__image-background' ) ) {
					/*
					 * Cover backgrounds are absolutely positioned full-bleed
					 * images; the wrapper must take over that role or the
					 * image collapses to a zero-height box.
					 */
					$classes .= ' trai-wrap--fill';
				}

				return self::wrap_media( $classes, $tag, $attachment_id );
			},
			$content
		);

		return null === $wrapped ? $content : $wrapped;
	}

	/**
	 * Normalize an image URL (or upload-relative path) to the map key of its
	 * original file: upload-relative, without size suffix (-300x200), the
	 * -scaled marker or a .webp/.avif conversion suffix. External URLs return
	 * an empty string and can never match. Mirrors the front.js logic.
	 */
	public static function normalize_upload_path( string $url ): string {
		$url = rawurldecode( $url );
		$url = (string) preg_replace( '/[?#].*$/', '', $url );

		$uploads_pos = strpos( $url, '/uploads/' );
		if ( false !== $uploads_pos ) {
			$path = substr( $url, $uploads_pos + 9 );
		} elseif ( ! str_contains( $url, '//' ) && ! str_starts_with( $url, '/' ) ) {
			$path = $url; /* Already upload-relative (map building). */
		} else {
			return '';
		}

		$path = (string) preg_replace( '/(\.(?:jpe?g|png|gif))\.(?:webp|avif)$/i', '$1', $path );
		$path = (string) preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $path );
		$path = (string) preg_replace( '/-scaled(\.[a-z0-9]+)$/i', '$1', $path );

		return $path;
	}

	/**
	 * the_content / widget_text_content / Elementor content filter.
	 *
	 * @param string|mixed $content Content HTML.
	 * @return string|mixed
	 */
	public static function filter_content( $content ) {
		if ( ! is_string( $content ) || ! self::should_filter() ) {
			return $content;
		}
		return self::wrap_images( $content );
	}

	/**
	 * render_block: images via the shared wrapper, video/audio via block attrs.
	 *
	 * @param string|mixed         $content Block HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 * @return string|mixed
	 */
	public static function filter_block( $content, array $block ) {
		if ( ! is_string( $content ) || '' === $content || ! self::should_filter() ) {
			return $content;
		}

		$name = (string) ( $block['blockName'] ?? '' );
		if ( 'core/video' === $name || 'core/audio' === $name ) {
			$attachment_id = isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0;
			if ( $attachment_id && self::is_badged( $attachment_id ) && ! str_contains( $content, 'trai-badge' ) ) {
				$close = strripos( $content, '</figure>' );
				if ( false !== $close ) {
					self::collect( $attachment_id );
					$badge   = self::badge_html( $attachment_id );
					$content = substr( $content, 0, $close ) . $badge . substr( $content, $close );
					$content = (string) preg_replace(
						'/<figure\b/',
						'<figure data-trai="1" ',
						$content,
						1
					);
					$content = '<span class="' . esc_attr( self::wrap_classes( 'trai-avwrap', $attachment_id ) ) . '">' . $content . '</span>';
				}
			}
			return $content;
		}

		return self::wrap_images( $content );
	}

	/**
	 * Featured image badge.
	 *
	 * @param string|mixed $html         Thumbnail HTML.
	 * @param int          $post_id      Post ID.
	 * @param int          $thumbnail_id Attachment ID.
	 * @return string|mixed
	 */
	public static function filter_thumbnail( $html, $post_id, $thumbnail_id ) {
		if ( ! is_string( $html ) || '' === $html || ! self::should_filter() ) {
			return $html;
		}
		$thumbnail_id = (int) $thumbnail_id;
		if ( ! $thumbnail_id || ! self::is_badged( $thumbnail_id ) || str_contains( $html, 'trai-badge' ) ) {
			return $html;
		}
		self::collect( $thumbnail_id );
		return self::wrap_media( self::wrap_classes( 'trai-thumbwrap', $thumbnail_id ), $html, $thumbnail_id );
	}

	/**
	 * Template-driven attachment images (wp_get_attachment_image).
	 *
	 * @param string|mixed $html          Image HTML.
	 * @param int          $attachment_id Attachment ID.
	 * @return string|mixed
	 */
	public static function filter_attachment_image( $html, $attachment_id ) {
		if ( ! is_string( $html ) || '' === $html || ! self::should_filter() ) {
			return $html;
		}
		$attachment_id = (int) $attachment_id;
		if ( ! self::is_badged( $attachment_id ) || str_contains( $html, 'trai-badge' ) ) {
			return $html;
		}
		self::collect( $attachment_id );
		return self::wrap_media( self::wrap_classes( 'trai-wrap', $attachment_id ), $html, $attachment_id );
	}

	/**
	 * Two jobs on core-rendered images:
	 *  1. Ensure the wp-image-{ID} class is present. WordPress core only sets
	 *     it in classic-editor markup; page builders that render through
	 *     wp_get_attachment_image() (Elementor widgets, WPBakery registered
	 *     sizes, core and builder galleries) ship without it. Adding it here
	 *     makes them all visible to the class-based badge path. Front end only.
	 *  2. Optional alt text suffix for assistive technology.
	 *
	 * @param array<string, string>|mixed $attr       Image attributes.
	 * @param WP_Post|null                $attachment Attachment post.
	 * @return array<string, string>|mixed
	 */
	public static function filter_image_attributes( $attr, $attachment ) {
		if ( ! is_array( $attr ) || ! self::should_filter() || ! $attachment instanceof WP_Post ) {
			return $attr;
		}

		$class = isset( $attr['class'] ) ? (string) $attr['class'] : '';
		if ( ! str_contains( $class, 'wp-image-' ) ) {
			$attr['class'] = trim( $class . ' wp-image-' . (int) $attachment->ID );
		}

		if ( ! TransparAI_Options::enabled( 'badge_alt_append' ) ) {
			return $attr;
		}
		if ( ! self::is_badged( (int) $attachment->ID ) ) {
			return $attr;
		}
		$suffix = self::badge_label( (int) $attachment->ID );
		$alt    = isset( $attr['alt'] ) ? (string) $attr['alt'] : '';
		if ( '' === $alt ) {
			$attr['alt'] = $suffix;
		} elseif ( ! str_contains( $alt, $suffix ) ) {
			$attr['alt'] = $alt . ' (' . $suffix . ')';
		}
		return $attr;
	}

	/* ---------------------------------------------------------------------
	 * Background image map (optional, JS-assisted)
	 * ------------------------------------------------------------------- */

	/**
	 * Upload-relative file paths of all labeled images (cached).
	 *
	 * @return string[]
	 */
	public static function background_map(): array {
		return array_keys( self::url_map() );
	}

	/**
	 * Map of labeled images: normalized upload-relative path to attachment ID.
	 * Used by the server-side wrap (src fallback) and, as its key list, by the
	 * optional front-end script. Cached for an hour, capped at 500 images.
	 *
	 * @return array<string, int>
	 */
	public static function url_map(): array {
		$map = get_transient( 'transparai_url_map' );
		if ( is_array( $map ) ) {
			return $map;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'fields'                 => 'ids',
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- hard upper bound for the map, cached for an hour.
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded query (500 rows), cached for an hour.
				'meta_query'             => TransparAI_Meta::meta_query( '1' ),
			)
		);

		$from = TransparAI_Options::get( 'badge_from_date' );
		$map  = array();
		foreach ( $query->posts as $attachment_id ) {
			if ( '' !== $from && (string) get_post_field( 'post_date', (int) $attachment_id ) < $from ) {
				continue;
			}
			$relative = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
			if ( '' === $relative ) {
				continue;
			}
			$key = self::normalize_upload_path( $relative );
			if ( '' !== $key ) {
				$map[ $key ] = (int) $attachment_id;
			}
		}

		set_transient( 'transparai_url_map', $map, HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * WPBakery hands every helper-built image through this filter together
	 * with its attachment id; tag class-less markup (custom sizes, galleries)
	 * for the class-based badge path.
	 *
	 * @param array<string, mixed>|mixed $img       ['thumbnail' => html, 'p_img_large' => src].
	 * @param int|string                 $attach_id Attachment ID.
	 * @return array<string, mixed>|mixed
	 */
	public static function filter_wpb_image( $img, $attach_id ) {
		$attach_id = (int) $attach_id;
		if ( ! is_array( $img ) || empty( $img['thumbnail'] ) || ! is_string( $img['thumbnail'] ) || ! $attach_id || ! self::should_filter() ) {
			return $img;
		}
		$img['thumbnail'] = self::tag_image_html( $img['thumbnail'], $attach_id );
		return $img;
	}

	/**
	 * Elementor widget images (Image, Image Box, Testimonial and several Pro
	 * widgets) pass through this filter with their settings; custom-size
	 * renders produce hash-suffixed files without an attachment class, so the
	 * id from the settings is the only reliable link.
	 *
	 * @param string|mixed $html           Image HTML.
	 * @param array|mixed  $settings       Widget settings.
	 * @param string|mixed $image_size_key Settings key of the size control.
	 * @param string|mixed $image_key      Settings key of the image control.
	 * @return string|mixed
	 */
	public static function filter_elementor_image( $html, $settings, $image_size_key = 'image', $image_key = null ) {
		if ( ! is_string( $html ) || '' === $html || ! is_array( $settings ) || ! self::should_filter() ) {
			return $html;
		}
		$key   = is_string( $image_key ) && '' !== $image_key ? $image_key : ( is_string( $image_size_key ) ? $image_size_key : 'image' );
		$image = $settings[ $key ] ?? null;
		$id    = is_array( $image ) && ! empty( $image['id'] ) ? (int) $image['id'] : 0;
		if ( ! $id ) {
			return $html;
		}
		return self::tag_image_html( $html, $id );
	}

	/**
	 * Add a wp-image-{ID} class to the first img tag of an HTML fragment,
	 * unless one is already present.
	 */
	private static function tag_image_html( string $html, int $attachment_id ): string {
		if ( str_contains( $html, 'wp-image-' ) ) {
			return $html;
		}
		if ( preg_match( '/<img\b[^>]*\bclass=(["\'])/i', $html ) ) {
			return (string) preg_replace( '/(<img\b[^>]*\bclass=)(["\'])/i', '$1$2wp-image-' . $attachment_id . ' ', $html, 1 );
		}
		return (string) preg_replace( '/<img\b/i', '<img class="wp-image-' . $attachment_id . '"', $html, 1 );
	}

	/**
	 * Remember a labeled attachment rendered in this request.
	 */
	private static function collect( int $attachment_id ): void {
		self::$rendered_ids[ $attachment_id ] = true;
	}

	/**
	 * Footer output: JSON-LD for the labeled media of this page and the
	 * optional site-wide notice. Both are server-rendered (page-cache safe)
	 * and only appear when labeled media was actually rendered.
	 */
	public static function print_footer_output(): void {
		if ( array() === self::$rendered_ids || ! self::should_filter() ) {
			return;
		}

		if ( TransparAI_Options::enabled( 'schema_output' ) ) {
			$graph = array();
			foreach ( array_keys( self::$rendered_ids ) as $attachment_id ) {
				$url = wp_get_attachment_url( $attachment_id );
				if ( ! $url ) {
					continue;
				}
				$mime = (string) get_post_mime_type( $attachment_id );
				$type = 'ImageObject';
				if ( str_starts_with( $mime, 'video/' ) ) {
					$type = 'VideoObject';
				} elseif ( str_starts_with( $mime, 'audio/' ) ) {
					$type = 'AudioObject';
				}
				$dst       = 'composite' === TransparAI_Meta::get_type( $attachment_id )
					? 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia'
					: 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';
				$node      = array(
					'@type'             => $type,
					'contentUrl'        => $url,
					'digitalSourceType' => $dst,
				);
				$generator = TransparAI_Meta::get_generator( $attachment_id );
				if ( '' !== $generator ) {
					$node['creator'] = array(
						'@type' => 'SoftwareApplication',
						'name'  => $generator,
					);
				}
				$graph[] = $node;
			}
			if ( array() !== $graph ) {
				$data = array(
					'@context' => 'https://schema.org',
					'@graph'   => $graph,
				);
				echo '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output inside a JSON script tag.
			}
		}

		if ( TransparAI_Options::enabled( 'page_notice' ) ) {
			$text = TransparAI_Options::get( 'page_notice_text' );
			if ( '' === $text ) {
				$text = __( 'This page contains AI-generated media.', 'transparai' );
			}
			echo '<p class="trai-page-notice">' . esc_html( $text ) . '</p>' . "\n";
		}
	}

	/**
	 * Note above content the author marked as AI-written (per-post checkbox).
	 *
	 * @param string|mixed $content Content HTML.
	 * @return string|mixed
	 */
	public static function filter_content_notice( $content ) {
		if ( ! is_string( $content ) || ! self::should_filter() ) {
			return $content;
		}
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		if ( '1' !== get_post_meta( (int) get_the_ID(), TransparAI_Meta::KEY_CONTENT_AI, true ) ) {
			return $content;
		}
		$text = TransparAI_Options::get( 'content_notice_text' );
		if ( '' === $text ) {
			$text = __( 'This text was created with the help of AI.', 'transparai' );
		}
		return '<p class="trai-content-notice">' . esc_html( $text ) . '</p>' . $content;
	}

	/**
	 * Flush the URL map and page caches when a label changes: cached pages
	 * would otherwise keep serving the old badge state.
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public static function maybe_flush_bg_map( $meta_id, $object_id, $meta_key ): void {
		if ( TransparAI_Meta::KEY_FLAG === $meta_key ) {
			delete_transient( 'transparai_url_map' );
			delete_transient( 'transparai_stats' );
			self::purge_page_caches();
		} elseif ( TransparAI_Meta::KEY_BADGE_POS === $meta_key ) {
			/*
			 * The URL map only tracks flag state; a position override just
			 * changes rendered markup, so cached pages are all that go stale.
			 */
			self::purge_page_caches();
		}
	}

	/**
	 * Drop the URL map and page caches after the settings were saved: the
	 * badge start date changes which media the map contains, every other
	 * badge setting changes the cached markup.
	 */
	public static function flush_caches(): void {
		delete_transient( 'transparai_url_map' );
		self::purge_page_caches();
	}

	/**
	 * Ask the common page-cache plugins to drop their caches. Silent no-ops
	 * when a plugin is absent; runs at most once per request (bulk actions).
	 */
	public static function purge_page_caches(): void {
		static $purged = false;
		if ( $purged ) {
			return;
		}
		$purged = true;

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain(); /* WP Rocket. */
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own purge hook.
		}
		if ( function_exists( 'w3tc_flush_posts' ) ) {
			w3tc_flush_posts(); /* W3 Total Cache. */
		} elseif ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache(); /* WP Super Cache. */
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) {
			wpfc_clear_all_cache(); /* WP Fastest Cache. */
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache(); /* SiteGround Optimizer. */
		}
		if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
			do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own purge hook.
		}
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's own purge hook.
		}
		if ( has_action( 'rt_nginx_helper_purge_all' ) ) {
			do_action( 'rt_nginx_helper_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper's own purge hook.
		}
		if ( has_action( 'wphb_clear_page_cache' ) ) {
			do_action( 'wphb_clear_page_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hummingbird's own purge hook.
		}
	}
}
