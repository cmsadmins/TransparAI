<?php
/** Run with wp eval-file on disposable LOCAL WordPress; rolls back all DB writes. */
if ( 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Local WordPress required.' );
}
if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}
if ( ! class_exists( 'TransparAI_Media_Library' ) ) {
	require_once WP_PLUGIN_DIR . '/transparai/transparai.php';
	require_once WP_PLUGIN_DIR . '/transparai/admin/class-media-library.php';
}
if ( ! has_action( 'wp_ajax_transparai_media_action' ) ) {
	TransparAI_Media_Library::init();
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin = $admins[0] ?? null;
if ( ! $admin ) {
	throw new RuntimeException( 'Local test administrator required.' );
}
wp_set_current_user( $admin->ID );
global $wpdb;
foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->options ) as $table ) {
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
	if ( 'InnoDB' !== $engine ) {
		throw new RuntimeException( 'InnoDB test tables required.' );
	}
}
function trai_media_check( bool $ok, string $case ): void {
	if ( ! $ok ) {
		throw new RuntimeException( 'Media action regression: ' . $case );
	}
}
function trai_media_request( int $id, string $op, string $nonce = '' ): array {
	$_POST    = array( 'attachment' => $id, 'op' => $op, '_wpnonce' => $nonce ?: wp_create_nonce( 'transparai_bulk' ) );
	$_REQUEST = $_POST;
	ob_start();
	try {
		do_action( 'wp_ajax_transparai_media_action' );
	} catch ( RuntimeException $error ) {
		if ( 'trai_ajax_end' !== $error->getMessage() ) {
			ob_end_clean();
			throw $error;
		}
	}
	$body = (string) ob_get_clean();
	return '-1' === $body ? array( 'success' => false ) : json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
}
add_filter( 'wp_die_ajax_handler', static function () {
	return static function ( $message = '' ): void {
		if ( '-1' === (string) $message ) { echo '-1'; }
		throw new RuntimeException( 'trai_ajax_end' );
	};
} );
// This fixture has no site-media path: writer never touches an existing upload.
add_filter( 'pre_option_transparai_settings', static function () {
	return array( 'autodetect' => false, 'write_xmp' => false, 'write_iim' => false, 'write_human' => false, 'mode_likely' => 'queue' );
} );
$counts = array();
foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->options ) as $table ) {
	$counts[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
}
$cases = array();
$fixture = tempnam( sys_get_temp_dir(), 'trai-media-test-' );
file_put_contents( $fixture, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9XcAAAAASUVORK5CYII=' ) );
$wpdb->query( 'START TRANSACTION' );
try {
	$id = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png', 'post_title' => 'TransparAI media actions fixture', 'post_author' => $admin->ID ) );
	trai_media_check( $id > 0, 'fixture' );
	$r = trai_media_request( $id, 'flag' );
	trai_media_check( $r['success'] && TransparAI_Meta::is_flagged( $id ) && false !== strpos( $r['data']['html'], 'data-op="unflag"' ), 'flag' );
	$cases['flag_and_fresh_cell'] = 'ok';
	$r = trai_media_request( $id, 'unflag' );
	trai_media_check( $r['success'] && ! TransparAI_Meta::is_flagged( $id ), 'unflag' );
	$cases['unflag'] = 'ok';
	TransparAI_Meta::queue( $id, array( 'is_ai' => true, 'source' => 'fixture', 'confidence' => 'likely' ) );
	$r = trai_media_request( $id, 'confirm' );
	trai_media_check( $r['success'] && TransparAI_Meta::is_flagged( $id ) && ! TransparAI_Meta::is_detected( $id ), 'confirm' );
	$cases['confirm'] = 'ok';
	trai_media_check( ! trai_media_request( $id, 'dismiss' )['success'] && TransparAI_Meta::is_flagged( $id ), 'stale review' );
	$cases['stale_review_rejected'] = 'ok';
	trai_media_request( $id, 'unflag' );
	TransparAI_Meta::queue( $id, array( 'is_ai' => true, 'source' => 'fixture', 'confidence' => 'likely' ) );
	$r = trai_media_request( $id, 'dismiss' );
	trai_media_check( $r['success'] && ! TransparAI_Meta::is_detected( $id ) && '1' === get_post_meta( $id, TransparAI_Meta::KEY_DISMISSED, true ), 'dismiss' );
	$cases['dismiss'] = 'ok';
	TransparAI_Meta::mark_human( $id, 'capture' );
	$r = trai_media_request( $id, 'human_remove' );
	trai_media_check( $r['success'] && ! TransparAI_Meta::is_human( $id ), 'human remove' );
	$cases['human_remove'] = 'ok';
	trai_media_check( ! trai_media_request( $id, 'flag', 'invalid-nonce' )['success'] && ! TransparAI_Meta::is_flagged( $id ), 'nonce' );
	$cases['invalid_nonce_rejected'] = 'ok';
	trai_media_check( ! trai_media_request( $id, 'invented' )['success'], 'unknown op' );
	$cases['unknown_action_rejected'] = 'ok';
	trai_media_check( ! trai_media_request( $id, 'recheck' )['success'], 'missing file recheck' );
	$cases['missing_file_recheck_rejected'] = 'ok';
	update_post_meta( $id, '_wp_attached_file', $fixture );
	$r = trai_media_request( $id, 'recheck' );
	trai_media_check( $r['success'] && __( 'No AI provenance signals found in the file.', 'transparai' ) === $r['data']['message'], 'clean recheck' );
	$cases['clean_file_recheck'] = 'ok';
	TransparAI_Meta::dismiss( $id );
	$detect = static function ( $result, $path ) use ( $fixture ) {
		return $path === $fixture ? array( 'is_ai' => true, 'type' => 'generated', 'source' => 'fixture', 'generator' => 'Fixture', 'confidence' => 'likely', 'evidence' => 'Fixture signal' ) : $result;
	};
	add_filter( 'transparai_detection_result', $detect, 10, 2 );
	$r = trai_media_request( $id, 'recheck' );
	remove_filter( 'transparai_detection_result', $detect, 10 );
	trai_media_check( $r['success'] && TransparAI_Meta::is_detected( $id ) && '' === get_post_meta( $id, TransparAI_Meta::KEY_DISMISSED, true ), 'dismissed recheck' );
	$cases['dismissed_detection_can_be_rechecked'] = 'ok';
	$document = wp_insert_post( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'application/pdf', 'post_title' => 'Document fixture' ) );
	trai_media_check( ! trai_media_request( $document, 'flag' )['success'] && '' === TransparAI_Media_Library::list_actions( $document ), 'document' );
	$cases['unsupported_media_rejected'] = 'ok';
	$article = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Article fixture' ) );
	trai_media_check( ! trai_media_request( $article, 'flag' )['success'], 'article' );
	$cases['non_attachment_rejected'] = 'ok';
	wp_set_current_user( 0 );
	trai_media_check( ! trai_media_request( $id, 'flag' )['success'] && ! TransparAI_Meta::is_flagged( $id ), 'anonymous' );
	$cases['anonymous_rejected'] = 'ok';
	wp_set_current_user( $admin->ID );
	$deny = static function ( $caps, $cap, $user, $args ) use ( $id ) {
		return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $id ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $deny, 99, 4 );
	trai_media_check( ! trai_media_request( $id, 'flag' )['success'] && '' === TransparAI_Media_Library::list_actions( $id ), 'per attachment permission' );
	remove_filter( 'map_meta_cap', $deny, 99 );
	$cases['attachment_permission_rejected'] = 'ok';
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
	unlink( $fixture );
}
foreach ( $counts as $table => $before ) {
	trai_media_check( $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ), 'rollback row counts' );
}
echo wp_json_encode( $cases ) . "\n";
