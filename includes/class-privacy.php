<?php
/**
 * Suggested text for the site's privacy policy (Settings, Privacy, Policy Guide).
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
 * Privacy policy guide entry.
 */
final class TransparAI_Privacy {

	/**
	 * Register the policy text.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
	}

	/**
	 * Hand the suggested paragraphs to WordPress.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content( 'TransparAI', wp_kses_post( wpautop( self::text(), false ) ) );
	}

	/**
	 * The suggested text, one paragraph per line.
	 */
	public static function text(): string {
		return __( 'This site uses TransparAI to detect and label AI-generated media and to document its use of AI systems. The plugin runs entirely on this server: it does not create accounts, sends no telemetry and makes no request to any external service. Media files are read locally to find provenance metadata (such as Content Credentials or the IPTC digital source type) and, when a file is labeled, that declaration is written into the file.', 'transparai' )
			. "\n\n"
			. __( 'The plugin stores its results in the WordPress database: per media file the label state, the detected signals and a history of label decisions that records the user ID and display name of the editor who made them, plus a short site log of administrative events. Reviewers of AI-written text are recorded by name and date. No data about site visitors is collected, and no cookies are set. The only HTTP request the plugin can make is the optional delivery check, which fetches one image from this site itself to verify that a CDN keeps the declaration.', 'transparai' );
	}
}
