<?php
/**
 * Plugin Name:       TransparAI: EU AI Act Compliance, AI Disclosure & AI Image Detection
 * Plugin URI:        https://wordpress.org/plugins/transparai/
 * Description:       Detect AI images via C2PA and IPTC, disclose AI content and chatbots, track EU AI Act readiness with a score, self-assessment and compliance report.
 * Version:           1.1.1
 * Requires at least: 6.2
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

defined( 'TRANSPARAI_VERSION' ) || define( 'TRANSPARAI_VERSION', '1.1.1' );
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
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-delivery.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-frontend.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-notice.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-woocommerce.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-rest.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-chatbot.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-integrations.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-compliance.php';
require_once TRANSPARAI_PLUGIN_DIR . 'includes/class-systems.php';

if ( is_admin() ) {
	require_once TRANSPARAI_PLUGIN_DIR . 'admin/class-media-library.php';
	require_once TRANSPARAI_PLUGIN_DIR . 'admin/class-settings.php';
	require_once TRANSPARAI_PLUGIN_DIR . 'admin/class-content-label.php';
	require_once TRANSPARAI_PLUGIN_DIR . 'admin/class-setup.php';
	require_once TRANSPARAI_PLUGIN_DIR . 'admin/class-dashboard.php';
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

			if ( is_admin() ) {
				TransparAI_Delivery::init();
			}
			TransparAI_Frontend::init();
			TransparAI_Notice::init();
			TransparAI_WooCommerce::init();
			TransparAI_REST::init();
			TransparAI_Chatbot::init();
			TransparAI_Integrations::init();
			TransparAI_Compliance::init();
			TransparAI_Systems::init();

			if ( is_admin() ) {
				TransparAI_Dashboard::init();
				TransparAI_Media_Library::init();
				TransparAI_Settings::init();
				TransparAI_Content_Label::init();
				TransparAI_Setup::init();
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
			if ( class_exists( 'TransparAI_Setup' ) ) {
				TransparAI_Setup::flag_redirect();
			}
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
