<?php
/**
 * Plugin settings: defaults, access, sanitization.
 *
 * All settings live in a single option (`transparai_settings`).
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
 * Central settings store.
 */
final class TransparAI_Options {

	public const OPTION = 'transparai_settings';

	/**
	 * Default values for every known setting.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			/* Visible badge. */
			'badge_enabled'           => '1',
			'badge_mode'              => 'overlay', /* overlay | caption. */
			'badge_text'              => '', /* Empty = translated default label. */
			'badge_position'          => 'bottom-right', /* top-left | top-right | bottom-left | bottom-right. */
			'badge_style'             => 'dark', /* dark | light | outline | icon-only. */
			'badge_size'              => 'medium', /* small | medium | large. */
			'badge_from_date'         => '', /* Y-m-d; only media uploaded on/after this date get the front-end badge. Empty = all. */
			'badge_show_source'       => '0', /* Append detected generator name to the badge. */
			'badge_alt_append'        => '1', /* Append note to image alt text (screen readers get the disclosure too). */
			'badge_guard'             => '1', /* JS: move badges that a theme overlay covers. */
			'background_badges'       => '0', /* Experimental: label CSS background images via JS map. */
			'page_notice'             => '0', /* Site-wide footer note on pages containing labeled media. */
			'page_notice_text'        => '', /* Empty = translated default. */
			'human_badge'             => '0', /* Visible badge on media declared as not AI-made. */
			'human_badge_text'        => '', /* Empty = translated default "Human made". */
			/* AI-written text: per-post disclosure levels. */
			'content_notice_text'     => '', /* Custom note for all AI levels; empty = translated default per level. */
			'content_notice_position' => 'before', /* before | after | both. */
			'content_notice_style'    => 'block', /* block | inline | banner (dismissible) | badge | modal. */
			'content_title_badge'     => '0', /* Append a small AI badge to the post title in the loop. */
			'feed_title_prefix'       => '0', /* Prefix feed item titles of AI-written posts with [AI]. */
			'content_default_level'   => '', /* Preselected level for new posts only; '' = none. */
			'content_responsible'     => '', /* Default name of the person responsible for reviewed texts. */
			'content_show_reviewer'   => '0', /* Append "reviewed by {name} on {date}" to the note. */
			'content_excerpt_notice'  => '0', /* Also append a plain-text line to excerpts (archives, teasers). */
			'feed_notice'             => '1', /* Plain-text note in RSS/Atom items plus dc:description; the content note skips feeds. */

			/* Chatbot disclosure: the operator's answer leads, detection only informs it. */
			'chatbot_answer'          => 'unknown', /* unknown | yes | no: does the site run a chat? */
			'chatbot_staffing'        => 'mixed', /* ai | human | mixed: who answers in it. */
			'chatbot_notice_text'     => '', /* Empty = translated default. */
			'chatbot_output'          => 'chat', /* chat (first bot message, AI Engine; falls back to badge) | badge (next to the widget) | footer. */

			/* AI systems in use (bundled local registry, nothing is fetched). */
			'systems_notice'          => '1', /* Front-end notice naming the AI systems switched to visible; prints nothing while none is. */
			'systems_notice_style'    => 'footer', /* footer | badge | banner. */
			'systems_notice_text'     => '', /* Empty = translated default; %s = list of names. */

			/* Automatic detection. */
			'autodetect'              => '1',
			'mode_certain'            => 'flag', /* flag | queue | off. */
			'mode_likely'             => 'queue', /* flag | queue | off. */
			'filename_hints'          => '0', /* Tier 3: filename patterns (suggestions only). */
			'detect_av'               => '1', /* Also scan video/audio uploads. */

			/* Machine-readable file metadata. */
			'write_xmp'               => '1',
			'write_iim'               => '1', /* Mirror into IPTC-IIM (JPEG, only when safe). */
			'write_human'             => '1', /* Also write digitalCapture/digitalCreation for declared non-AI media (never over a foreign declaration). */
			'auto_repair'             => '1', /* Re-write metadata stripped by optimizers. */
			'delivery_check'          => '0', /* Opt-in: fetch our own image URL to see whether the declaration survives delivery. */
			'schema_output'           => '1', /* JSON-LD digitalSourceType for labeled media in the page. */

			/* Housekeeping. */
			'delete_on_uninstall'     => '0',
		);
	}

	/**
	 * Get one setting (with default fallback).
	 *
	 * The stored option is not necessarily what the settings screen wrote:
	 * WP-CLI, a migration or another plugin can put an integer or an array in
	 * there. Coercing here keeps a foreign value from turning into a fatal
	 * front-end error under strict types; anything not scalar falls back to
	 * the empty string, which every caller already treats as "not set".
	 */
	public static function get( string $key ): string {
		$value = self::all()[ $key ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether a boolean-style setting is enabled.
	 */
	public static function enabled( string $key ): bool {
		return '1' === self::get( $key );
	}

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array<string, string>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Sanitize a raw settings array (Settings API callback).
	 *
	 * Unknown keys are dropped; enum values fall back to their default.
	 *
	 * @param mixed $raw Raw input.
	 * @return array<string, string>
	 */
	public static function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return self::defaults();
		}

		$defaults = self::defaults();
		$enums    = array(
			'badge_mode'              => array( 'overlay', 'caption' ),
			'badge_position'          => array( 'top-left', 'top-right', 'bottom-left', 'bottom-right' ),
			'badge_style'             => array( 'dark', 'light', 'outline', 'icon-only' ),
			'badge_size'              => array( 'small', 'medium', 'large' ),
			'mode_certain'            => array( 'flag', 'queue', 'off' ),
			'mode_likely'             => array( 'flag', 'queue', 'off' ),

			'content_notice_position' => array( 'before', 'after', 'both' ),
			'content_notice_style'    => array( 'block', 'inline', 'banner', 'badge', 'modal' ),
			'systems_notice_style'    => array( 'footer', 'badge', 'banner' ),
			'content_default_level'   => array_merge( array( '' ), TransparAI_Meta::CONTENT_LEVELS ),
			'chatbot_answer'          => array( 'unknown', 'yes', 'no' ),
			'chatbot_staffing'        => array( 'ai', 'human', 'mixed' ),
			'chatbot_output'          => array( 'chat', 'badge', 'footer' ),
		);
		$booleans = array(
			'badge_enabled',
			'badge_show_source',
			'badge_alt_append',
			'badge_guard',
			'background_badges',
			'page_notice',
			'human_badge',
			'write_human',
			'content_show_reviewer',
			'content_excerpt_notice',
			'feed_notice',
			'content_title_badge',
			'feed_title_prefix',
			'systems_notice',
			'autodetect',
			'filename_hints',
			'detect_av',
			'write_xmp',
			'write_iim',
			'auto_repair',
			'delivery_check',
			'schema_output',
			'delete_on_uninstall',
		);

		$clean = array();
		foreach ( $defaults as $key => $default ) {
			if ( in_array( $key, $booleans, true ) ) {
				$clean[ $key ] = empty( $raw[ $key ] ) ? '0' : '1';
				continue;
			}
			if ( isset( $enums[ $key ] ) ) {
				$value         = isset( $raw[ $key ] ) ? sanitize_key( (string) $raw[ $key ] ) : $default;
				$clean[ $key ] = in_array( $value, $enums[ $key ], true ) ? $value : $default;
				continue;
			}
			if ( 'badge_from_date' === $key ) {
				$value         = isset( $raw[ $key ] ) ? trim( (string) $raw[ $key ] ) : '';
				$valid         = 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m )
					&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
				$clean[ $key ] = $valid ? $value : '';
				continue;
			}
			if ( in_array( $key, array( 'badge_text', 'page_notice_text', 'content_notice_text', 'content_responsible', 'human_badge_text', 'chatbot_notice_text', 'systems_notice_text' ), true ) ) {
				$value         = isset( $raw[ $key ] ) ? sanitize_text_field( (string) $raw[ $key ] ) : '';
				$clean[ $key ] = mb_substr( $value, 0, in_array( $key, array( 'badge_text', 'content_responsible', 'human_badge_text' ), true ) ? 100 : 300 );
				continue;
			}
			$clean[ $key ] = $default;
		}

		return $clean;
	}
}
