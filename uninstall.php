<?php
/**
 * Uninstall handler.
 *
 * By default the AI labels stay in the database (reinstalling restores them)
 * and metadata already written into image files stays in the files. When the
 * "delete all plugin data" setting is enabled, every option, transient and
 * attachment meta this plugin created is removed, on every site of a
 * multisite network.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove plugin data for the current site.
 */
function transparai_uninstall_site(): void {
	$settings = get_option( 'transparai_settings', array() );
	$purge    = is_array( $settings ) && ! empty( $settings['delete_on_uninstall'] );

	wp_clear_scheduled_hook( 'transparai_verify_markings' );

	delete_transient( 'transparai_stats' );
	delete_transient( 'transparai_bg_map' );

	if ( ! $purge ) {
		return;
	}

	delete_option( 'transparai_settings' );
	delete_option( 'transparai_verify_cursor' );
	delete_option( 'transparai_repair_report' );

	global $wpdb;

	$meta_keys = array(
		'_transparai_ai',
		'_transparai_detected',
		'_transparai_dismissed',
		'_transparai_type',
		'_transparai_source',
		'_transparai_generator',
		'_transparai_confidence',
		'_transparai_evidence',
		'_transparai_marked_by',
		'_transparai_scanned',
		'_transparai_unreadable',
		'_transparai_fingerprint',
		'_transparai_write_error',
	);

	foreach ( $meta_keys as $meta_key ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup across all attachments; delete_post_meta_by_key() would do the same query.
	}
}

if ( is_multisite() ) {
	$transparai_site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $transparai_site_ids as $transparai_site_id ) {
		switch_to_blog( (int) $transparai_site_id );
		transparai_uninstall_site();
		restore_current_blog();
	}
} else {
	transparai_uninstall_site();
}
