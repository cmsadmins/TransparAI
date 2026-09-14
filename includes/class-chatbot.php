<?php
/**
 * Chatbot disclosure (EU AI Act Art. 50(1)): tell visitors they are talking
 * to an AI system, at the latest when the conversation starts.
 *
 * The site operator's answer leads; detection only informs it. Nothing here
 * switches a notice on by itself: most chat widgets are live chats with an
 * optional bot, and telling visitors "this is an AI" while a person answers
 * would be a false statement. Detection is entirely local: active plugins,
 * theme snippets, the scripts WordPress itself registers on a page, and a
 * small client-side check reported by an administrator's own browser. No
 * request leaves the server, not even to the site itself.
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
 * Chatbot detection and notice.
 */
final class TransparAI_Chatbot {

	public const NONCE         = 'transparai_chatbot';
	private const FOUND        = 'transparai_chatbot_found';
	private const SCAN_STAMP   = 'transparai_chatbot_scanned';
	private const FILE_MAX     = 1048576;
	private const FOUND_TTL    = 30 * DAY_IN_SECONDS;
	private const THEME_FILES  = array( 'header.php', 'footer.php', 'functions.php', 'parts/header.html', 'parts/footer.html' );
	private const SNIPPET_OPTS = array( 'ihaf_insert_header', 'ihaf_insert_footer', 'widget_custom_html', 'widget_text' );

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_print_footer_scripts', array( self::class, 'observe_scripts' ), 1 );
		add_action( 'wp_footer', array( self::class, 'print_footer_notice' ) );
		add_filter( 'mwai_chatbot_params', array( self::class, 'ai_engine_params' ), 20 );
		add_action( 'wp_ajax_transparai_chatbot_seen', array( self::class, 'ajax_seen' ) );
	}

	/* ---------------------------------------------------------------------
	 * Vendor data
	 * ------------------------------------------------------------------- */

	/**
	 * The bundled vendor list, keyed by id.
	 *
	 * @return array<string, array{id:string, name:string, staffing:string, slugs:string[], hosts:string[], globals:string[], selectors:string[]}>
	 */
	public static function vendors(): array {
		static $vendors = null;
		if ( null !== $vendors ) {
			return $vendors;
		}
		$vendors = array();
		$path    = TRANSPARAI_PLUGIN_DIR . 'data/chatbots.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin file, not a remote request.
		$json = is_readable( $path ) ? file_get_contents( $path ) : false;
		$data = false === $json ? null : json_decode( $json, true );
		foreach ( (array) ( $data['vendors'] ?? array() ) as $vendor ) {
			if ( ! is_array( $vendor ) || empty( $vendor['id'] ) ) {
				continue;
			}
			$id             = sanitize_key( (string) $vendor['id'] );
			$vendors[ $id ] = array(
				'id'        => $id,
				'name'      => (string) ( $vendor['name'] ?? $id ),
				'staffing'  => in_array( $vendor['staffing'] ?? '', array( 'ai', 'human', 'mixed' ), true ) ? (string) $vendor['staffing'] : 'unknown',
				'slugs'     => array_map( 'strval', (array) ( $vendor['slugs'] ?? array() ) ),
				'hosts'     => array_map( 'strval', (array) ( $vendor['hosts'] ?? array() ) ),
				'globals'   => array_map( 'strval', (array) ( $vendor['globals'] ?? array() ) ),
				'selectors' => array_map( 'strval', (array) ( $vendor['selectors'] ?? array() ) ),
			);
		}
		/**
		 * Filters the chatbot vendor list (add a house-made widget, adjust staffing).
		 *
		 * @param array $vendors Vendors keyed by id.
		 */
		$vendors = (array) apply_filters( 'transparai_chatbot_vendors', $vendors );
		return $vendors;
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------- */

	/**
	 * Whether the notice is on: the operator said yes, and an AI (also) answers.
	 */
	public static function active(): bool {
		return 'yes' === TransparAI_Options::get( 'chatbot_answer' )
			&& in_array( TransparAI_Options::get( 'chatbot_staffing' ), array( 'ai', 'mixed' ), true );
	}

	/**
	 * The notice text.
	 */
	public static function text(): string {
		$text = TransparAI_Options::get( 'chatbot_notice_text' );
		if ( '' === $text ) {
			$text = __( 'You are chatting with an AI system.', 'transparai' );
		}
		/**
		 * Filters the chatbot notice.
		 *
		 * @param string $text Plain text.
		 */
		return (string) apply_filters( 'transparai_chatbot_notice', $text );
	}

	/**
	 * How the notice reaches the visitor: `chat` (first bot message, AI Engine)
	 * falls back to the badge when no supported chat plugin is there.
	 */
	public static function effective_output(): string {
		$output = TransparAI_Options::get( 'chatbot_output' );
		if ( 'chat' === $output && ! self::has_ai_engine() ) {
			return 'badge';
		}
		return in_array( $output, array( 'chat', 'badge', 'footer' ), true ) ? $output : 'badge';
	}

	/**
	 * Whether AI Engine (which offers the params filter) is loaded.
	 */
	public static function has_ai_engine(): bool {
		return class_exists( 'Meow_MWAI_Core' ) || defined( 'MWAI_VERSION' );
	}

	/* ---------------------------------------------------------------------
	 * Detection (local only)
	 * ------------------------------------------------------------------- */

	/**
	 * Vendors whose plugin is active, by directory slug or a known class.
	 *
	 * @return array<string, string> id => evidence.
	 */
	public static function detect_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		if ( function_exists( 'get_site_option' ) && is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$dirs = array();
		foreach ( $active as $basename ) {
			$dirs[ strtolower( (string) strtok( (string) $basename, '/' ) ) ] = (string) $basename;
		}

		$found = array();
		foreach ( self::vendors() as $id => $vendor ) {
			foreach ( $vendor['slugs'] as $slug ) {
				if ( isset( $dirs[ strtolower( $slug ) ] ) ) {
					$found[ $id ] = 'plugin ' . $dirs[ strtolower( $slug ) ];
					break;
				}
			}
		}
		/* Class and constant checks survive renamed directories and mu-plugins. */
		$php = array(
			'ai-engine' => class_exists( 'Meow_MWAI_Core' ) || defined( 'MWAI_VERSION' ),
			'mxchat'    => defined( 'MXCHAT_VERSION' ) || class_exists( 'MxChat_Admin' ),
			'kognetiks' => function_exists( 'chatbot_chatgpt_activate' ) || defined( 'CHATBOT_CHATGPT_VERSION' ),
			'ai-power'  => defined( 'WPAICG_VERSION' ) || class_exists( 'WPAICG\\WPAICG_Base' ),
		);
		foreach ( $php as $id => $present ) {
			if ( $present && ! isset( $found[ $id ] ) ) {
				$found[ $id ] = 'plugin loaded';
			}
		}
		return $found;
	}

	/**
	 * Vendors mentioned in the theme's template files and the usual snippet
	 * options (hand-pasted widget code). Read-only, capped per file, no HTTP.
	 *
	 * @return array<string, string> id => evidence.
	 */
	public static function detect_static(): array {
		$blobs = array();
		$dirs  = array_unique( array_filter( array( get_stylesheet_directory(), get_template_directory() ) ) );
		foreach ( $dirs as $dir ) {
			foreach ( self::THEME_FILES as $file ) {
				$path = rtrim( (string) $dir, '/' ) . '/' . $file;
				if ( is_readable( $path ) && filesize( $path ) <= self::FILE_MAX ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file.
					$blobs[ 'theme ' . $file ] = (string) file_get_contents( $path );
				}
			}
		}
		foreach ( self::SNIPPET_OPTS as $option ) {
			$value = get_option( $option );
			if ( ! empty( $value ) ) {
				$blobs[ 'option ' . $option ] = is_string( $value ) ? $value : (string) wp_json_encode( $value );
			}
		}
		$found = array();
		foreach ( $blobs as $where => $blob ) {
			foreach ( self::match_text( $blob ) as $id ) {
				$found[ $id ] = $found[ $id ] ?? $where;
			}
		}
		return $found;
	}

	/**
	 * Vendor ids whose script host or JavaScript global occurs in a text.
	 *
	 * @return string[]
	 */
	public static function match_text( string $text ): array {
		if ( '' === $text ) {
			return array();
		}
		$ids = array();
		foreach ( self::vendors() as $id => $vendor ) {
			foreach ( $vendor['hosts'] as $host ) {
				if ( '' !== $host && false !== stripos( $text, $host ) ) {
					$ids[] = $id;
					continue 2;
				}
			}
			foreach ( $vendor['globals'] as $global ) {
				/* A global is only evidence when it is used as one: "window.X", "X(" or "X =". */
				if ( '' !== $global && 1 === preg_match( '/(?:window\.|\bvar\s+|\blet\s+|\bconst\s+)?' . preg_quote( $global, '/' ) . '\s*(?:=|\(|\.)/', $text ) ) {
					$ids[] = $id;
					continue 2;
				}
			}
		}
		return $ids;
	}

	/**
	 * Front-end request: look at the scripts WordPress registered for this
	 * page (source URLs and inline data) instead of fetching the page over
	 * HTTP. Once per hour, results kept for 30 days.
	 */
	public static function observe_scripts(): void {
		if ( is_admin() || get_transient( self::SCAN_STAMP ) ) {
			return;
		}
		set_transient( self::SCAN_STAMP, time(), HOUR_IN_SECONDS );
		if ( ! function_exists( 'wp_scripts' ) ) {
			return;
		}
		$found = array();
		foreach ( wp_scripts()->registered as $handle => $script ) {
			$blob = is_string( $script->src ) ? $script->src : '';
			foreach ( array( 'before', 'after', 'data' ) as $key ) {
				$extra = $script->extra[ $key ] ?? '';
				$blob .= "\n" . ( is_array( $extra ) ? implode( "\n", array_map( 'strval', $extra ) ) : (string) $extra );
			}
			foreach ( self::match_text( $blob ) as $id ) {
				$found[ $id ] = $found[ $id ] ?? 'script handle ' . $handle;
			}
		}
		if ( array() !== $found ) {
			self::remember( $found );
		}
	}

	/**
	 * Merge findings into the stored set.
	 *
	 * @param array<string, string> $found id => evidence.
	 */
	public static function remember( array $found ): void {
		$known = self::found();
		foreach ( $found as $id => $evidence ) {
			$known[ $id ] = array(
				'evidence' => (string) $evidence,
				'at'       => time(),
			);
		}
		set_transient( self::FOUND, $known, self::FOUND_TTL );
	}

	/**
	 * Stored findings (scripts, client reports).
	 *
	 * @return array<string, array{evidence:string, at:int}>
	 */
	public static function found(): array {
		$known = get_transient( self::FOUND );
		return is_array( $known ) ? $known : array();
	}

	/**
	 * Everything known right now: live plugin and theme detection plus the
	 * stored script and client findings. Keyed by vendor id.
	 *
	 * @return array<string, array{name:string, staffing:string, evidence:string}>
	 */
	public static function findings(): array {
		$vendors = self::vendors();
		$all     = array();
		foreach ( self::detect_plugins() + self::detect_static() as $id => $evidence ) {
			$all[ $id ] = $evidence;
		}
		foreach ( self::found() as $id => $entry ) {
			$all[ $id ] = $all[ $id ] ?? $entry['evidence'];
		}
		$out = array();
		foreach ( $all as $id => $evidence ) {
			if ( isset( $vendors[ $id ] ) ) {
				$out[ $id ] = array(
					'name'     => $vendors[ $id ]['name'],
					'staffing' => $vendors[ $id ]['staffing'],
					'evidence' => $evidence,
				);
			}
		}
		return $out;
	}

	/**
	 * Keep only vendor ids that exist in the bundled list. Unknown input is
	 * dropped, never stored: the client report can only ever say "seen one
	 * of these", not "seen this free text".
	 *
	 * @param mixed $ids Raw ids.
	 * @return string[]
	 */
	public static function filter_known( $ids, int $max = 25 ): array {
		$known = self::vendors();
		$out   = array();
		foreach ( (array) $ids as $id ) {
			$id = sanitize_key( (string) $id );
			if ( isset( $known[ $id ] ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * An administrator's browser reports which widgets it saw on a page.
	 * Admin-only, nonce-gated, ids validated against the bundled list.
	 */
	public static function ajax_seen(): void {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'transparai' ) ), 403 );
		}
		$ids   = self::filter_known( isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- filter_known() sanitizes every id and drops unknown ones.
		$found = array();
		foreach ( $ids as $id ) {
			$found[ $id ] = 'seen in the browser';
		}
		if ( array() !== $found ) {
			self::remember( $found );
		}
		wp_send_json_success( array( 'stored' => count( $found ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Output
	 * ------------------------------------------------------------------- */

	/**
	 * Front-end script: badge placement for visitors (when active), detection
	 * report for administrators.
	 */
	public static function enqueue(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}
		$report = current_user_can( 'manage_options' );
		$badge  = self::active() && 'badge' === self::effective_output();
		if ( ! $report && ! $badge ) {
			return;
		}
		wp_enqueue_script( 'transparai-chatbot', TRANSPARAI_PLUGIN_URL . 'assets/js/chatbot.js', array(), TRANSPARAI_VERSION, true );
		$vendors = array();
		foreach ( self::vendors() as $id => $vendor ) {
			$vendors[ $id ] = array(
				'globals'   => $vendor['globals'],
				'selectors' => $vendor['selectors'],
			);
		}
		wp_localize_script(
			'transparai-chatbot',
			'transparaiChatbot',
			array(
				'vendors' => $vendors,
				'badge'   => $badge ? '1' : '',
				'text'    => self::text(),
				'report'  => $report ? '1' : '',
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => $report ? wp_create_nonce( self::NONCE ) : '',
			)
		);
		if ( $badge ) {
			wp_enqueue_style( 'transparai-front', TRANSPARAI_PLUGIN_URL . 'assets/css/front.css', array(), TRANSPARAI_VERSION );
		}
	}

	/**
	 * Footer variant: a plain, server-rendered line (page-cache safe).
	 */
	public static function print_footer_notice(): void {
		if ( is_admin() || is_feed() || ! self::active() || 'footer' !== self::effective_output() ) {
			return;
		}
		echo '<p class="trai-page-notice trai-chat-notice">' . esc_html( self::text() ) . '</p>' . "\n";
	}

	/**
	 * AI Engine: put the notice into the first message of the bot, which is
	 * what "before the first interaction" means. Idempotent.
	 *
	 * @param array<string, mixed>|mixed $params Chatbot params.
	 * @return array<string, mixed>|mixed
	 */
	public static function ai_engine_params( $params ) {
		if ( ! is_array( $params ) || ! self::active() || 'chat' !== TransparAI_Options::get( 'chatbot_output' ) ) {
			return $params;
		}
		$text  = self::text();
		$start = isset( $params['startSentence'] ) && is_string( $params['startSentence'] ) ? $params['startSentence'] : '';
		if ( '' === $start ) {
			$params['startSentence'] = $text;
		} elseif ( false === strpos( $start, $text ) ) {
			$params['startSentence'] = $text . ' ' . $start;
		}
		return $params;
	}
}
