<?php
/**
 * AI systems in use: a bundled registry of WordPress plugins with AI
 * features, matched locally against the installed plugins, plus manual
 * declarations and an optional visitor notice.
 *
 * The list ships with the plugin (data/ai-systems.json). Nothing is fetched,
 * nothing is sent; that is the difference to registries that phone home.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI systems registry and inventory.
 */
final class TransparAI_Systems {

	/** One small option (autoload on: the front-end notice reads it). */
	public const OPTION = 'transparai_systems';

	public const CATEGORIES = array( 'content', 'image', 'chatbot', 'translation', 'personalisation', 'seo', 'search', 'audio_video', 'assistant', 'other' );

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		/* Both fire after the active_plugins option changed, so a plain rescan sees the new state. */
		add_action( 'activated_plugin', array( self::class, 'rescan' ) );
		add_action( 'deactivated_plugin', array( self::class, 'rescan' ) );

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_footer', array( self::class, 'print_notice' ) );
	}

	/* ---------------------------------------------------------------------
	 * Registry
	 * ------------------------------------------------------------------- */

	/**
	 * Translated category labels.
	 *
	 * @return array<string, string>
	 */
	public static function category_labels(): array {
		return array(
			'content'         => __( 'Text generation', 'transparai' ),
			'image'           => __( 'Image generation', 'transparai' ),
			'chatbot'         => __( 'Chatbot', 'transparai' ),
			'translation'     => __( 'Translation', 'transparai' ),
			'personalisation' => __( 'Personalisation', 'transparai' ),
			'seo'             => __( 'SEO assistant', 'transparai' ),
			'search'          => __( 'Search and recommendations', 'transparai' ),
			'audio_video'     => __( 'Audio and video', 'transparai' ),
			'assistant'       => __( 'Editor assistant', 'transparai' ),
			'other'           => __( 'Other', 'transparai' ),
		);
	}

	/**
	 * The bundled registry keyed by id, merged with chatbot vendors an AI
	 * answers in (the chatbot list already knows their plugin slugs).
	 *
	 * @return array<string, array{id:string, name:string, category:string, article:string, risk:string, url:string, slugs:string[]}>
	 */
	public static function registry(): array {
		static $registry = null;
		if ( null !== $registry ) {
			return $registry;
		}
		$registry = array();
		$path     = TRANSPARAI_PLUGIN_DIR . 'data/ai-systems.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin file, not a remote request.
		$json = is_readable( $path ) ? file_get_contents( $path ) : false;
		$data = false === $json ? null : json_decode( $json, true );
		foreach ( (array) ( $data['systems'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
				continue;
			}
			$id              = sanitize_key( (string) $entry['id'] );
			$registry[ $id ] = self::normalise( $id, $entry );
		}
		if ( class_exists( 'TransparAI_Chatbot' ) ) {
			foreach ( TransparAI_Chatbot::vendors() as $id => $vendor ) {
				if ( isset( $registry[ $id ] ) || ! in_array( $vendor['staffing'], array( 'ai', 'mixed' ), true ) ) {
					continue;
				}
				$registry[ $id ] = self::normalise(
					$id,
					array(
						'name'     => $vendor['name'],
						'category' => 'chatbot',
						'article'  => 'Art. 50(1)',
						'slugs'    => $vendor['slugs'],
					)
				);
			}
		}
		/**
		 * Filters the AI systems registry (add a house-made tool, adjust a category).
		 *
		 * @param array $registry Systems keyed by id.
		 */
		$registry = (array) apply_filters( 'transparai_systems_registry', $registry );
		return $registry;
	}

	/**
	 * One registry record with every field present and sane.
	 *
	 * @param array<string, mixed> $entry Raw entry.
	 * @return array{id:string, name:string, category:string, article:string, risk:string, url:string, slugs:string[]}
	 */
	private static function normalise( string $id, array $entry ): array {
		$category = (string) ( $entry['category'] ?? 'other' );
		$risk     = (string) ( $entry['risk'] ?? 'limited' );
		return array(
			'id'       => $id,
			'name'     => sanitize_text_field( (string) ( $entry['name'] ?? $id ) ),
			'category' => in_array( $category, self::CATEGORIES, true ) ? $category : 'other',
			'article'  => sanitize_text_field( (string) ( $entry['article'] ?? 'Art. 4' ) ),
			'risk'     => in_array( $risk, array( 'minimal', 'limited', 'high' ), true ) ? $risk : 'limited',
			'url'      => esc_url_raw( (string) ( $entry['url'] ?? '' ) ),
			'slugs'    => array_values( array_filter( array_map( 'strval', (array) ( $entry['slugs'] ?? array() ) ) ) ),
		);
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------- */

	/**
	 * The stored state, normalised.
	 *
	 * @return array{detected:array<string, string>, manual:array<string, array{name:string, category:string, slug:string}>, visible:array<string, bool>, scanned_at:int}
	 */
	public static function state(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$detected = array();
		foreach ( (array) ( $raw['detected'] ?? array() ) as $id => $evidence ) {
			$detected[ sanitize_key( (string) $id ) ] = (string) $evidence;
		}
		$manual = array();
		foreach ( (array) ( $raw['manual'] ?? array() ) as $id => $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['name'] ) ) {
				$category                               = (string) ( $entry['category'] ?? 'other' );
				$manual[ sanitize_key( (string) $id ) ] = array(
					'name'     => (string) $entry['name'],
					'category' => in_array( $category, self::CATEGORIES, true ) ? $category : 'other',
					'slug'     => (string) ( $entry['slug'] ?? '' ),
				);
			}
		}
		$visible = array();
		foreach ( (array) ( $raw['visible'] ?? array() ) as $id => $on ) {
			if ( $on ) {
				$visible[ sanitize_key( (string) $id ) ] = true;
			}
		}
		return array(
			'detected'   => $detected,
			'manual'     => $manual,
			'visible'    => $visible,
			'scanned_at' => (int) ( $raw['scanned_at'] ?? 0 ),
		);
	}

	/**
	 * Persist a state array.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private static function save( array $state ): void {
		update_option( self::OPTION, $state, true );
	}

	/**
	 * When the last scan ran (0 = never).
	 */
	public static function scanned_at(): int {
		return self::state()['scanned_at'];
	}

	/**
	 * First visit: scan once. Plugin (de)activation keeps the result current.
	 */
	public static function maybe_scan(): void {
		if ( 0 === self::scanned_at() ) {
			self::scan();
		}
	}

	/**
	 * Plugin (de)activation hook: rescan quietly. Never throws, the hook
	 * runs inside the plugin sandbox.
	 */
	public static function rescan(): void {
		self::scan( false );
	}

	/**
	 * Match active plugins (and network-active ones) against the registry;
	 * chatbot findings of AI-staffed vendors count as detected too.
	 *
	 * @param bool $log Whether to write a site-log entry.
	 * @return array<string, string> id => evidence.
	 */
	public static function scan( bool $log = true ): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'get_site_option' ) && is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$dirs = array();
		foreach ( $active as $basename ) {
			$dirs[ strtolower( (string) strtok( (string) $basename, '/' ) ) ] = (string) $basename;
		}

		$detected = array();
		foreach ( self::registry() as $id => $system ) {
			foreach ( $system['slugs'] as $slug ) {
				if ( isset( $dirs[ strtolower( $slug ) ] ) ) {
					/* translators: %s: plugin file. */
					$detected[ $id ] = sprintf( __( 'plugin %s', 'transparai' ), $dirs[ strtolower( $slug ) ] );
					break;
				}
			}
		}
		if ( class_exists( 'TransparAI_Chatbot' ) ) {
			foreach ( TransparAI_Chatbot::findings() as $id => $finding ) {
				if ( ! isset( $detected[ $id ] ) && in_array( $finding['staffing'], array( 'ai', 'mixed' ), true ) && isset( self::registry()[ $id ] ) ) {
					$detected[ $id ] = (string) $finding['evidence'];
				}
			}
		}

		$state               = self::state();
		$changed             = array_keys( $detected ) !== array_keys( $state['detected'] );
		$state['detected']   = $detected;
		$state['scanned_at'] = time();
		self::save( $state );
		if ( $log ) {
			TransparAI_Meta::log_site( 'systems-scanned', array( 'count' => count( $detected ) ) );
		}
		if ( $changed ) {
			self::purge();
		}
		return $detected;
	}

	/**
	 * Declare a system by hand. Returns its id.
	 */
	public static function declare( string $name, string $category, string $slug = '' ): string {
		$name = mb_substr( sanitize_text_field( $name ), 0, 100 );
		if ( '' === $name ) {
			return '';
		}
		$slug     = sanitize_key( $slug );
		$id       = 'manual-' . sanitize_key( '' !== $slug ? $slug : sanitize_title( $name ) );
		$category = in_array( $category, self::CATEGORIES, true ) ? $category : 'other';

		$state                  = self::state();
		$state['manual'][ $id ] = array(
			'name'     => $name,
			'category' => $category,
			'slug'     => $slug,
		);
		self::save( $state );
		TransparAI_Meta::log_site( 'systems-declared', array( 'id' => $id ) );
		return $id;
	}

	/**
	 * Remove a manual declaration (and its visibility).
	 */
	public static function undeclare( string $id ): void {
		$id    = sanitize_key( $id );
		$state = self::state();
		if ( ! isset( $state['manual'][ $id ] ) ) {
			return;
		}
		unset( $state['manual'][ $id ], $state['visible'][ $id ] );
		self::save( $state );
		TransparAI_Meta::log_site( 'systems-undeclared', array( 'id' => $id ) );
		self::purge();
	}

	/**
	 * Set which systems the visitor notice names. Ids not in the inventory
	 * are dropped.
	 *
	 * @param string[] $ids Visible ids.
	 */
	public static function set_visible( array $ids ): void {
		$state   = self::state();
		$known   = self::all();
		$visible = array();
		foreach ( $ids as $id ) {
			$id = sanitize_key( (string) $id );
			if ( isset( $known[ $id ] ) ) {
				$visible[ $id ] = true;
			}
		}
		if ( $visible === $state['visible'] ) {
			return;
		}
		$state['visible'] = $visible;
		self::save( $state );
		TransparAI_Meta::log_site( 'systems-visibility', array( 'count' => count( $visible ) ) );
		self::purge();
	}

	/**
	 * Every detected and declared system with its registry data.
	 *
	 * @return array<string, array{id:string, name:string, category:string, article:string, risk:string, url:string, source:string, evidence:string, visible:bool}>
	 */
	public static function all(): array {
		$state    = self::state();
		$registry = self::registry();
		$out      = array();
		foreach ( $state['detected'] as $id => $evidence ) {
			if ( ! isset( $registry[ $id ] ) ) {
				continue; /* Registry changed since the scan. */
			}
			$out[ $id ] = array_merge(
				$registry[ $id ],
				array(
					'source'   => 'detected',
					'evidence' => $evidence,
					'visible'  => isset( $state['visible'][ $id ] ),
				)
			);
		}
		foreach ( $state['manual'] as $id => $entry ) {
			$out[ $id ] = array(
				'id'       => $id,
				'name'     => $entry['name'],
				'category' => $entry['category'],
				'article'  => 'chatbot' === $entry['category'] ? 'Art. 50(1)' : 'Art. 4',
				'risk'     => 'limited',
				'url'      => '',
				'slugs'    => '' !== $entry['slug'] ? array( $entry['slug'] ) : array(),
				'source'   => 'manual',
				'evidence' => __( 'declared by hand', 'transparai' ),
				'visible'  => isset( $state['visible'][ $id ] ),
			);
		}
		return $out;
	}

	/**
	 * Systems the visitor notice names: only with the notice switched on.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function visible(): array {
		if ( ! TransparAI_Options::enabled( 'systems_notice' ) ) {
			return array();
		}
		return array_filter(
			self::all(),
			static function ( array $system ): bool {
				return $system['visible'];
			}
		);
	}

	/**
	 * Number of inventoried systems.
	 */
	public static function count(): int {
		return count( self::all() );
	}

	/**
	 * Active plugins the registry does not know that look AI-related from
	 * their own name and description. Suggestions only; nothing is declared
	 * from this list without a click. Admin only (reads plugin headers).
	 *
	 * @return array<int, array{slug:string, file:string, name:string}>
	 */
	public static function possibly_ai(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			if ( ! defined( 'ABSPATH' ) || ! is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				return array();
			}
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'get_site_option' ) && is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$known = array();
		foreach ( self::registry() as $system ) {
			foreach ( $system['slugs'] as $slug ) {
				$known[ strtolower( $slug ) ] = true;
			}
		}
		foreach ( self::state()['manual'] as $entry ) {
			if ( '' !== $entry['slug'] ) {
				$known[ strtolower( $entry['slug'] ) ] = true;
			}
		}
		$needles = array( 'openai', 'chatgpt', 'gpt-4', 'gpt-5', 'gpt4', 'claude', 'gemini', 'llm', 'large language model', 'stable diffusion', 'midjourney', 'dall-e', 'dalle', 'generative ai', 'text-to-image', 'text to image', 'machine learning', 'deepl', 'anthropic', 'mistral', 'ai writer', 'ai assistant', 'ai content', 'ai image', 'ai chat', 'chatbot', 'ai-powered', 'ai powered', 'artificial intelligence', 'neural', 'text-to-speech', 'text to speech', 'ai translation', 'ai generated', 'ai-generated' );

		$plugins = (array) get_plugins();
		$out     = array();
		foreach ( $active as $file ) {
			$file = (string) $file;
			$slug = strtolower( (string) strtok( $file, '/' ) );
			if ( isset( $known[ $slug ] ) || ! isset( $plugins[ $file ] ) || 'transparai' === $slug ) {
				continue;
			}
			$header = (array) $plugins[ $file ];
			$text   = strtolower( (string) ( $header['Name'] ?? '' ) . ' ' . (string) ( $header['Description'] ?? '' ) );
			foreach ( $needles as $needle ) {
				if ( str_contains( $text, $needle ) ) {
					$out[] = array(
						'slug' => $slug,
						'file' => $file,
						'name' => wp_strip_all_tags( (string) ( $header['Name'] ?? $slug ) ),
					);
					break;
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------- */

	/**
	 * The notice text for a set of systems.
	 *
	 * @param array<string, array<string, mixed>> $systems Systems.
	 */
	public static function notice_text( array $systems ): string {
		$names = array();
		foreach ( $systems as $system ) {
			$names[] = (string) $system['name'];
		}
		$list = implode( ', ', $names );
		$text = TransparAI_Options::get( 'systems_notice_text' );
		if ( '' === $text ) {
			/* translators: %s: comma-separated names of AI systems. */
			$text = __( 'This site uses AI systems: %s.', 'transparai' );
		}
		$text = str_contains( $text, '%s' ) ? sprintf( $text, $list ) : rtrim( $text ) . ' ' . $list;
		/**
		 * Filters the AI systems notice.
		 *
		 * @param string $text    Plain-text notice.
		 * @param array  $systems Visible systems keyed by id.
		 */
		return (string) apply_filters( 'transparai_systems_notice', $text, $systems );
	}

	/**
	 * Front-end stylesheet when the notice will print.
	 */
	public static function enqueue(): void {
		if ( array() === self::visible() ) {
			return;
		}
		wp_enqueue_style( 'transparai-front', TRANSPARAI_PLUGIN_URL . 'assets/css/front.css', array(), TRANSPARAI_VERSION );
		if ( 'banner' === TransparAI_Options::get( 'systems_notice_style' ) ) {
			wp_enqueue_script( 'transparai-front', TRANSPARAI_PLUGIN_URL . 'assets/js/front.js', array(), TRANSPARAI_VERSION, true );
		}
	}

	/**
	 * Print the visitor notice (wp_footer). Server-rendered, cache-safe.
	 */
	public static function print_notice(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}
		$systems = self::visible();
		if ( array() === $systems ) {
			return;
		}
		$text  = self::notice_text( $systems );
		$style = TransparAI_Options::get( 'systems_notice_style' );
		if ( 'banner' === $style ) {
			$html = TransparAI_Notice::render(
				array(
					'text'    => $text,
					'variant' => 'banner',
					'type'    => 'systems',
				)
			);
			echo $html . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes; the result is filterable by design.
			return;
		}
		if ( 'badge' === $style ) {
			echo '<aside class="trai-systems-notice trai-systems-notice--badge" role="note">' . esc_html( $text ) . '</aside>' . "\n";
			return;
		}
		echo '<p class="trai-page-notice trai-systems-notice">' . esc_html( $text ) . '</p>' . "\n";
	}

	/**
	 * Cached pages carry the old notice; ask the cache plugins to drop them.
	 */
	private static function purge(): void {
		if ( class_exists( 'TransparAI_Frontend' ) ) {
			TransparAI_Frontend::purge_page_caches();
		}
	}
}
