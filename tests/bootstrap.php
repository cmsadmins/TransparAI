<?php
/**
 * PHPUnit bootstrap: minimal WordPress stubs so the parser, detector and
 * writer classes run without a WordPress install.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'TRANSPARAI_VERSION' ) ) {
	define( 'TRANSPARAI_VERSION', '1.0.0-test' );
}
if ( ! defined( 'TRANSPARAI_PLUGIN_DIR' ) ) {
	define( 'TRANSPARAI_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'TRANSPARAI_PLUGIN_URL' ) ) {
	define( 'TRANSPARAI_PLUGIN_URL', 'https://example.test/wp-content/plugins/transparai/' );
}
if ( ! defined( 'TRANSPARAI_PLUGIN_FILE' ) ) {
	define( 'TRANSPARAI_PLUGIN_FILE', dirname( __DIR__ ) . '/transparai.php' );
}
if ( ! defined( 'TRANSPARAI_PLUGIN_BASENAME' ) ) {
	define( 'TRANSPARAI_PLUGIN_BASENAME', 'transparai/transparai.php' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

/* PHP 8 string helpers. WordPress polyfills these globally since 5.9; the
   unit tests run without WordPress, so PHP 7.4 needs them here. */
if ( ! function_exists( 'str_contains' ) ) {
	function str_contains( $haystack, $needle ) {
		return '' === $needle || false !== strpos( $haystack, $needle );
	}
}
if ( ! function_exists( 'str_starts_with' ) ) {
	function str_starts_with( $haystack, $needle ) {
		return 0 === strncmp( $haystack, $needle, strlen( $needle ) );
	}
}
if ( ! function_exists( 'str_ends_with' ) ) {
	function str_ends_with( $haystack, $needle ) {
		return '' === $needle || substr( $haystack, -strlen( $needle ) ) === $needle;
	}
}

global $trai_test_options, $trai_test_meta, $trai_test_filters, $trai_test_transients, $trai_test_cron, $trai_test_user, $trai_test_http;
$trai_test_http       = null;
$trai_test_cron       = array();
$trai_test_user       = 0;
$trai_test_options    = array();
$trai_test_meta       = array();
$trai_test_filters    = array();
$trai_test_transients = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		global $trai_test_options;
		return $trai_test_options[ $name ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = '' ) {
		global $trai_test_options;
		$trai_test_options[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		global $trai_test_options;
		unset( $trai_test_options[ $name ] );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $name ) {
		global $trai_test_transients;
		return $trai_test_transients[ $name ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $name, $value, $ttl = 0 ) {
		global $trai_test_transients;
		$trai_test_transients[ $name ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $name ) {
		global $trai_test_transients;
		unset( $trai_test_transients[ $name ] );
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );
		global $trai_test_filters;
		if ( isset( $trai_test_filters[ $tag ] ) ) {
			foreach ( $trai_test_filters[ $tag ] as $callback ) {
				$args[0] = call_user_func_array( $callback, $args );
			}
		}
		return $args[0];
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		global $trai_test_filters;
		$trai_test_filters[ $tag ][] = $callback;
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		return true;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {
		return null;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url ) {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $message;
		public function __construct( $code = '', $message = '' ) {
			$this->message = $message;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		global $trai_test_http;
		if ( $trai_test_http instanceof WP_Error ) {
			return $trai_test_http;
		}
		return is_array( $trai_test_http ) ? $trai_test_http : array(
			'response' => array( 'code' => 404 ),
			'body'     => '',
		);
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return $response['response']['code'] ?? 0;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return $response['body'] ?? '';
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		global $trai_test_user;
		return (int) ( $trai_test_user ?? 0 );
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		global $trai_test_cron;
		return $trai_test_cron[ $hook ] ?? false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $timestamp, $recurrence, $hook ) {
		global $trai_test_cron;
		$trai_test_cron[ $hook ] = $timestamp;
		return true;
	}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook ) {
		global $trai_test_cron;
		unset( $trai_test_cron[ $hook ] );
		return true;
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		global $trai_test_meta;
		$trai_test_meta[ $post_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) {
		global $trai_test_meta;
		if ( ! isset( $trai_test_meta[ $post_id ][ $key ] ) ) {
			return $single ? '' : array();
		}
		return $single ? $trai_test_meta[ $post_id ][ $key ] : array( $trai_test_meta[ $post_id ][ $key ] );
	}
}
if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( $post_id, $key ) {
		global $trai_test_meta;
		unset( $trai_test_meta[ $post_id ][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id = 0 ) {
		return 'attachment';
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		global $trai_test_can;
		return $trai_test_can ?? true;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return 'Test Site';
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular() {
		return true;
	}
}
if ( ! function_exists( 'in_the_loop' ) ) {
	function in_the_loop() {
		return true;
	}
}
if ( ! function_exists( 'is_main_query' ) ) {
	function is_main_query() {
		return true;
	}
}
if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID() {
		global $trai_test_current_post;
		return (int) ( $trai_test_current_post ?? 0 );
	}
}
if ( ! function_exists( 'has_action' ) ) {
	function has_action( $tag ) {
		return false;
	}
}
if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( $post_id = 0 ) {
		global $trai_test_meta;
		return $trai_test_meta[ $post_id ]['_test_mime'] ?? 'image/jpeg';
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $post_id ) {
		global $trai_test_meta;
		return $trai_test_meta[ $post_id ][ '_test_' . $field ] ?? '';
	}
}
if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $post_id ) {
		global $trai_test_meta;
		return $trai_test_meta[ $post_id ]['_test_url'] ?? false;
	}
}
if ( ! function_exists( 'is_feed' ) ) {
	function is_feed() {
		return false;
	}
}
if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() {
		return false;
	}
}
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $post_id ) {
		global $trai_test_meta;
		return $trai_test_meta[ $post_id ]['_test_file'] ?? false;
	}
}
if ( ! function_exists( 'wp_get_original_image_path' ) ) {
	function wp_get_original_image_path( $post_id ) {
		global $trai_test_meta;
		return $trai_test_meta[ $post_id ]['_test_original_path'] ?? false;
	}
}
if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( $path, $suffix = '' ) {
		return basename( $path, $suffix );
	}
}
if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	function wp_get_attachment_metadata( $post_id ) {
		global $trai_test_meta;
		return $trai_test_meta[ $post_id ]['_test_metadata'] ?? array();
	}
}
if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) {
		return is_writable( $path );
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return is_string( $value ) ? trim( strip_tags( $value ) ) : '';
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $value ) {
		return is_string( $value ) ? trim( strip_tags( $value ) ) : '';
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}
}
if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $value ) {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $value );
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}
if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = '' ) {
		return $text;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Minimal stand-in for the core post object.
	 */
	class WP_Post { // phpcs:ignore Generic.Classes.OpeningBraceSameLine.ContentAfterBrace
		/** @var int */
		public $ID = 0;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-options.php';
require_once dirname( __DIR__ ) . '/includes/class-meta.php';
require_once dirname( __DIR__ ) . '/includes/class-parsers.php';
require_once dirname( __DIR__ ) . '/includes/class-detector.php';
require_once dirname( __DIR__ ) . '/includes/class-scanner.php';
require_once dirname( __DIR__ ) . '/includes/class-integrations.php';
require_once dirname( __DIR__ ) . '/includes/class-repair.php';
require_once dirname( __DIR__ ) . '/includes/class-writer.php';
require_once dirname( __DIR__ ) . '/includes/class-frontend.php';
require_once dirname( __DIR__ ) . '/includes/class-delivery.php';

/**
 * Reset all in-memory stores between tests.
 */
function trai_test_reset(): void {
	global $trai_test_options, $trai_test_meta, $trai_test_filters, $trai_test_transients, $trai_test_can, $trai_test_current_post, $trai_test_cron, $trai_test_user, $trai_test_http;
	$trai_test_http         = null;
	$trai_test_cron         = array();
	$trai_test_user         = 0;
	$trai_test_options      = array();
	$trai_test_meta         = array();
	$trai_test_filters      = array();
	$trai_test_transients   = array();
	$trai_test_can          = true;
	$trai_test_current_post = 0;
	$_GET                   = array();
	$_REQUEST               = array();
}

/**
 * Path to a generated fixture file.
 */
function trai_fixture( string $name ): string {
	$path = __DIR__ . '/fixtures/generated/' . $name;
	if ( ! file_exists( $path ) ) {
		fwrite( STDERR, "Fixture missing: {$name}, run `php tests/fixtures/make-fixtures.php` first.\n" );
		exit( 1 );
	}
	return $path;
}
