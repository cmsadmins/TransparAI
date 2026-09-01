<?php
/**
 * Plugin Name:       TransparAI – AI Image Marker & Detector
 * Plugin URI:        https://wordpress.org/plugins/transparai/
 * Description:       Label AI-generated media (EU AI Act, Art. 50): visible badge, machine-readable IPTC/XMP metadata written into the files, and automatic detection of AI images via C2PA, XMP/IPTC and generator signatures.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Patrick Schlesinger
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       transparai
 * Domain Path:       /languages
 *
 * @package   TransparAI
 * @author    Patrick Schlesinger
 * @copyright 2026 Patrick Schlesinger
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
 * @since     1.0.0
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

defined( 'TRANSPARAI_VERSION' ) || define( 'TRANSPARAI_VERSION', '1.0.0' );
defined( 'TRANSPARAI_PLUGIN_FILE' ) || define( 'TRANSPARAI_PLUGIN_FILE', __FILE__ );
defined( 'TRANSPARAI_PLUGIN_DIR' ) || define( 'TRANSPARAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
defined( 'TRANSPARAI_PLUGIN_URL' ) || define( 'TRANSPARAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
defined( 'TRANSPARAI_PLUGIN_BASENAME' ) || define( 'TRANSPARAI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-options.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-meta.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-parsers.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-detector.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-writer.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-scanner.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-repair.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-frontend.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-integrations.php';

if ( is_admin() ) {
	require_once TRANSPARAI_PLUGIN_DIR . 'admin/class-media-library.php';
	require_once TRANSPARAI_PLUGIN_DIR . 'admin/class-settings.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-cli.php';
}

if ( ! class_exists( 'TransparAI' ) ) {
	/**
	 * Plugin bootstrap: wires all components.
	 */
	final class TransparAI {

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		private static ?self $instance = null;

		/**
		 * Get (or create) the singleton instance.
		 */
		public static function get_instance(): self {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Register all component hooks.
		 */
		private function __construct() {
			TransparAI_Meta::init();
			TransparAI_Scanner::init();
			TransparAI_Writer::init();
			TransparAI_Repair::init();
			TransparAI_Frontend::init();
			TransparAI_Integrations::init();

			if ( is_admin() ) {
				TransparAI_Media_Library::init();
				TransparAI_Settings::init();
			}

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				TransparAI_CLI::register();
			}
		}

		/**
		 * plugins_loaded callback.
		 */
		public static function boot(): void {
			self::get_instance();
		}

		/**
		 * Activation: schedule the integrity verification cron.
		 */
		public static function activate(): void {
			TransparAI_Repair::schedule();
		}

		/**
		 * Deactivation: remove scheduled events.
		 */
		public static function deactivate(): void {
			TransparAI_Repair::unschedule();
		}
	}
}

register_activation_hook( __FILE__, array( 'TransparAI', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TransparAI', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'TransparAI', 'boot' ) );
