<?php
/**
 * Plugin Name:       TransparAI
 * Plugin URI:        https://wordpress.org/plugins/transparai/
 * Description:       Detect and label AI-generated images: automatic C2PA and IPTC detection, visible AI badge, machine-readable EU AI Act (Art. 50) disclosure.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            Patrick Schlesinger
 * Author URI:        https://www.cms-admins.de/
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
	require_once TRANSPARAI_PLUGIN_DIR . 'admin/class-content-label.php';
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
		 * Register all component hooks (plugins_loaded callback).
		 */
		public static function boot(): void {
			TransparAI_Meta::init();
			TransparAI_Scanner::init();
			TransparAI_Writer::init();
			TransparAI_Repair::init();
			TransparAI_Frontend::init();
			TransparAI_Integrations::init();

			if ( is_admin() ) {
				TransparAI_Media_Library::init();
				TransparAI_Settings::init();
				TransparAI_Content_Label::init();
			}

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				TransparAI_CLI::register();
			}
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
