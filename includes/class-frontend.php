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
 * @package TransparAI
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
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );

		add_filter( 'render_block', array( self::class, 'filter_block' ), 20, 2 );
		add_filter( 'the_content', array( self::class, 'filter_content' ), 20 );
		add_filter( 'widget_text_content', array( self::class, 'filter_content' ), 20 );
		// Fires for all Elementor-rendered output, including Theme Builder
		// templates. Without Elementor the filter simply never runs.
		add_filter( 'elementor/frontend/the_content', array( self::class, 'filter_content' ), 20 );

		add_filter( 'post_thumbnail_html', array( self::class, 'filter_thumbnail' ), 20, 3 );
		add_filter( 'wp_get_attachment_image', array( self::class, 'filter_attachment_image' ), 20, 2 );
		add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'filter_image_attributes' ), 20, 2 );

		// Invalidate the background map when labels change.
		add_action( 'added_post_meta', array( self::class, 'maybe_flush_bg_map' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'maybe_flush_bg_map' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'maybe_flush_bg_map' ), 10, 3 );
	}

	/**
	 * Whether output filtering applies to the current request.
	 */
	private static function should_filter(): bool {
		return ! is_admin() && ! is_feed() && ! wp_doing_ajax() && TransparAI_Options::enabled( 'badge_enabled' );
	}

	/**
	 * Localized badge label (full variant).
	 */
	public static function badge_label(): string {
		$custom = TransparAI_Options::get( 'badge_text' );
		if ( '' !== $custom ) {
			return $custom;
		}
		return __( 'AI-generated', 'transparai' );
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
		$label = self::badge_label();
		if ( TransparAI_Options::enabled( 'badge_show_source' ) ) {
			$generator = TransparAI_Meta::get_generator( $attachment_id );
			if ( '' !== $generator ) {
				$label .= ' · ' . $generator;
			}
		}
		return '<span class="trai-badge" role="note" data-trai-short="' . esc_attr( self::badge_short_label() ) . '">'
			. esc_html( $label ) . '</span>';
	}

	/**
	 * Wrapper CSS classes from the badge settings.
	 */
	private static function wrap_classes( string $base ): string {
		return $base
			. ' trai-pos-' . sanitize_html_class( TransparAI_Options::get( 'badge_position' ) )
			. ' trai-size-' . sanitize_html_class( TransparAI_Options::get( 'badge_size' ) )
			. ' trai-style-' . sanitize_html_class( TransparAI_Options::get( 'badge_style' ) )
			. ' trai-mode-' . sanitize_html_class( TransparAI_Options::get( 'badge_mode' ) );
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
			'label'   => self::badge_label(),
			'short'   => self::badge_short_label(),
			'classes' => self::wrap_classes( 'trai-bg-host' ),
			'bgMap'   => TransparAI_Options::enabled( 'background_badges' ) ? self::background_map() : array(),
		);
		wp_localize_script( 'transparai-front', 'transparaiFront', $data );
	}

	/* ---------------------------------------------------------------------
	 * Content filters
	 * ------------------------------------------------------------------- */

	/**
	 * Wrap every labeled image (identified by its wp-image-{ID} class) with
	 * the badge markup. Shared by all content filters.
	 */
	public static function wrap_images( string $content ): string {
		if ( '' === $content || ! str_contains( $content, 'wp-image-' ) ) {
			return $content;
		}
		if ( ! preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
			return $content;
		}

		foreach ( array_unique( $matches[1] ) as $id ) {
			$id = (int) $id;
			if ( ! TransparAI_Meta::is_flagged( $id ) ) {
				continue;
			}
			$pattern = '/(<img\b[^>]*\bwp-image-' . $id . '\b[^>]*>)(?!<span class="trai-badge")/';
			$content = (string) preg_replace(
				$pattern,
				'<span class="' . esc_attr( self::wrap_classes( 'trai-wrap' ) ) . '">$1' . self::badge_html( $id ) . '</span>',
				$content,
				1
			);
		}

		return $content;
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
			if ( $attachment_id && TransparAI_Meta::is_flagged( $attachment_id ) && ! str_contains( $content, 'trai-badge' ) ) {
				$close = strripos( $content, '</figure>' );
				if ( false !== $close ) {
					$badge   = self::badge_html( $attachment_id );
					$content = substr( $content, 0, $close ) . $badge . substr( $content, $close );
					$content = (string) preg_replace(
						'/<figure\b/',
						'<figure data-trai="1" ',
						$content,
						1
					);
					$content = '<span class="' . esc_attr( self::wrap_classes( 'trai-avwrap' ) ) . '">' . $content . '</span>';
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
		if ( ! $thumbnail_id || ! TransparAI_Meta::is_flagged( $thumbnail_id ) || str_contains( $html, 'trai-badge' ) ) {
			return $html;
		}
		return '<span class="' . esc_attr( self::wrap_classes( 'trai-thumbwrap' ) ) . '">' . $html . self::badge_html( $thumbnail_id ) . '</span>';
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
		if ( ! TransparAI_Meta::is_flagged( $attachment_id ) || str_contains( $html, 'trai-badge' ) ) {
			return $html;
		}
		return '<span class="' . esc_attr( self::wrap_classes( 'trai-wrap' ) ) . '">' . $html . self::badge_html( $attachment_id ) . '</span>';
	}

	/**
	 * Optional alt text suffix for assistive technology.
	 *
	 * @param array<string, string>|mixed $attr       Image attributes.
	 * @param WP_Post|null                $attachment Attachment post.
	 * @return array<string, string>|mixed
	 */
	public static function filter_image_attributes( $attr, $attachment ) {
		if ( ! is_array( $attr ) || ! self::should_filter() || ! TransparAI_Options::enabled( 'badge_alt_append' ) ) {
			return $attr;
		}
		if ( ! $attachment instanceof WP_Post || ! TransparAI_Meta::is_flagged( (int) $attachment->ID ) ) {
			return $attr;
		}
		$suffix = self::badge_label();
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
		$map = get_transient( 'transparai_bg_map' );
		if ( is_array( $map ) ) {
			return $map;
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'fields'                 => 'ids',
				'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- hard upper bound for the JS map, cached for an hour.
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded query (500 rows), cached for an hour.
				'meta_query'             => TransparAI_Meta::meta_query( '1' ),
			)
		);

		$map = array();
		foreach ( $query->posts as $attachment_id ) {
			$relative = (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true );
			if ( '' !== $relative ) {
				$map[] = $relative;
			}
		}

		set_transient( 'transparai_bg_map', $map, HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * Flush the background map when a label changes.
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public static function maybe_flush_bg_map( $meta_id, $object_id, $meta_key ): void {
		if ( TransparAI_Meta::KEY_FLAG === $meta_key ) {
			delete_transient( 'transparai_bg_map' );
			delete_transient( 'transparai_stats' );
		}
	}
}
