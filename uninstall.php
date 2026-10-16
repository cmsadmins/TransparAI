<?php
/**
 * Uninstall handler.
 *
 * By default the AI labels stay in the database (reinstalling restores them)
 * and metadata already written into image files stays in the files. When the
 * "delete all plugin data" setting is enabled, every option, transient and
 * post meta this plugin created is removed, attachment labels and the
 * per-post content flag alike, on every site of a multisite network.
 *
 * @package   TransparAI
 * @author    Patrick Schlesinger
 * @copyright 2026 Patrick Schlesinger
 * @license   GPL-2.0-or-later https://www.gnu.org/licenses/gpl-2.0.html
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
	delete_transient( 'transparai_url_map' );

	if ( ! $purge ) {
		return;
	}

	delete_option( 'transparai_settings' );
	delete_option( 'transparai_verify_cursor' );
	delete_option( 'transparai_repair_report' );
	delete_option( 'transparai_log' );
	delete_option( 'transparai_setup_done' );
	delete_option( 'transparai_setup_journal' );
	delete_option( 'transparai_compliance' );
	delete_option( 'transparai_systems' );
	delete_metadata( 'user', 0, 'transparai_setup_skipped', '', true );
	delete_metadata( 'user', 0, 'transparai_legal_ack', '', true );
	delete_metadata( 'user', 0, 'transparai_review_hint_dismissed', '', true );

	global $wpdb;

	/* Labelled link preview copies are derived files of this plugin; the originals stay. */
	$transparai_copies = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_transparai_og' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup.
	foreach ( (array) $transparai_copies as $transparai_copy ) {
		$transparai_dir    = dirname( (string) get_attached_file( (int) $transparai_copy->post_id ) );
		$transparai_stored = maybe_unserialize( $transparai_copy->meta_value );
		foreach ( array( 'file', 'previous' ) as $transparai_key ) {
			$transparai_name = is_array( $transparai_stored ) ? (string) ( $transparai_stored[ $transparai_key ] ?? '' ) : '';
			if ( '' !== $transparai_name && basename( $transparai_name ) === $transparai_name ) {
				wp_delete_file( $transparai_dir . '/' . $transparai_name );
			}
		}
	}
	wp_unschedule_hook( 'transparai_og_generate' );

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
		'_transparai_badge_pos',
		'_transparai_content_ai',
		'_transparai_content_responsible',
		'_transparai_content_review',
		'_transparai_history',
		'_transparai_delivery',
		'_transparai_c2pa',
		'_transparai_scan_fp',
		'_transparai_human',
		'_transparai_og',
		'_transparai_og_failed',
	);

	foreach ( $meta_keys as $meta_key ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup across all attachments; delete_post_meta_by_key() would do the same query.
	}
}

if ( is_multisite() ) {
	$transparai_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0, /* Every site; the default stops at 100. */
		)
	);
	foreach ( $transparai_site_ids as $transparai_site_id ) {
		switch_to_blog( (int) $transparai_site_id );
		transparai_uninstall_site();
		restore_current_blog();
	}
} else {
	transparai_uninstall_site();
}
