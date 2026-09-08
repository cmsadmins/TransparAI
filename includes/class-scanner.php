<?php
/**
 * Detection orchestration: scan on upload, bulk library scan in AJAX batches
 * with a time budget, and single-attachment re-checks.
 *
 * A detection is applied according to its confidence and the configured mode:
 * 'certain' results may auto-flag, 'likely' results default to the review
 * queue, 'hint' results only ever queue. Manual decisions always win: an
 * existing label is never overwritten and a dismissed detection stays
 * dismissed.
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
 * Upload hook + batch scanner.
 */
final class TransparAI_Scanner {

	private const BATCH_SIZE  = 20;
	private const TIME_BUDGET = 10.0; /* Seconds per AJAX batch request. */

	/** Nonce action of both scan endpoints; the admin scripts create it under this name. */
	public const NONCE = 'transparai_scan';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'add_attachment', array( self::class, 'on_upload' ), 20 );
		add_action( 'wp_ajax_transparai_scan_batch', array( self::class, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_transparai_recheck', array( self::class, 'ajax_recheck' ) );
	}

	/**
	 * Mime types the scanner covers.
	 *
	 * @return string[]
	 */
	public static function mime_types(): array {
		$types = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/heic' );
		if ( TransparAI_Options::enabled( 'detect_av' ) ) {
			$types = array_merge( $types, array( 'video/mp4', 'video/quicktime', 'audio/mpeg', 'audio/mp4' ) );
		}
		return $types;
	}

	/**
	 * Scan a fresh upload.
	 */
	public static function on_upload( int $attachment_id ): void {
		if ( ! TransparAI_Options::enabled( 'autodetect' ) ) {
			return;
		}
		if ( ! in_array( (string) get_post_mime_type( $attachment_id ), self::mime_types(), true ) ) {
			return;
		}
		self::scan_attachment( $attachment_id );
	}

	/**
	 * Scan one attachment and apply the result.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{status:string, result:array|null} status: flagged|queued|clean|skipped|unreadable.
	 */
	public static function scan_attachment( int $attachment_id ): array {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			update_post_meta( $attachment_id, TransparAI_Meta::KEY_UNREADABLE, 'missing_file' );
			update_post_meta( $attachment_id, TransparAI_Meta::KEY_SCANNED, (string) time() );
			return array(
				'status' => 'unreadable',
				'result' => null,
			);
		}
		if ( ! is_readable( $file ) ) {
			update_post_meta( $attachment_id, TransparAI_Meta::KEY_UNREADABLE, 'no_permission' );
			update_post_meta( $attachment_id, TransparAI_Meta::KEY_SCANNED, (string) time() );
			return array(
				'status' => 'unreadable',
				'result' => null,
			);
		}
		delete_post_meta( $attachment_id, TransparAI_Meta::KEY_UNREADABLE );

		$result = TransparAI_Detector::detect_file( $file );

		/*
		 * WordPress' big-image scaling re-encodes large uploads into the
		 * "-scaled" attached file, which drops all metadata, and image
		 * optimizers strip it from the attached file too. The untouched
		 * pre-scale original next to it still carries the declaration.
		 */
		if ( null === $result ) {
			$original = wp_get_original_image_path( $attachment_id );
			if ( is_string( $original ) && $original !== $file && is_readable( $original ) ) {
				$result = TransparAI_Detector::detect_file( $original );
				if ( null !== $result ) {
					$result['evidence'] = mb_substr( $result['evidence'] . ' [from the pre-scale original ' . wp_basename( $original ) . ']', 0, 500 );
				}
			}
		}

		update_post_meta( $attachment_id, TransparAI_Meta::KEY_SCANNED, (string) time() );

		if ( null === $result || empty( $result['is_ai'] ) ) {
			return array(
				'status' => 'clean',
				'result' => null,
			);
		}

		$status = self::apply_result( $attachment_id, $result );
		return array(
			'status' => $status,
			'result' => $result,
		);
	}

	/**
	 * Apply a detection result according to configuration and review state.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $result        Detector result.
	 * @return string flagged|queued|skipped.
	 */
	public static function apply_result( int $attachment_id, array $result ): string {
		$status = self::planned_status( $attachment_id, $result );

		if ( 'flagged' === $status ) {
			TransparAI_Meta::store_result( $attachment_id, $result );
			TransparAI_Meta::flag( $attachment_id, 'auto' );
			return 'flagged';
		}
		if ( 'queued' === $status ) {
			TransparAI_Meta::queue( $attachment_id, $result );
			return 'queued';
		}

		/* Already labeled by hand: keep the decision, refresh the evidence. */
		if ( TransparAI_Meta::is_flagged( $attachment_id ) ) {
			TransparAI_Meta::store_result( $attachment_id, $result );
		}
		return 'skipped';
	}

	/**
	 * What a detection result would do, without touching the database.
	 *
	 * The single place that holds the flag/queue/skip policy: apply_result()
	 * acts on it and the CLI dry run previews it, so a preview can never
	 * promise something a real scan would not do.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $result        Detector result.
	 * @return string flagged|queued|skipped.
	 */
	public static function planned_status( int $attachment_id, array $result ): string {
		/* Manual decisions win: existing label or dismissed detection stay untouched. */
		if ( TransparAI_Meta::is_flagged( $attachment_id ) ) {
			return 'skipped';
		}
		if ( '1' === get_post_meta( $attachment_id, TransparAI_Meta::KEY_DISMISSED, true ) ) {
			return 'skipped';
		}

		$confidence = (string) ( $result['confidence'] ?? 'likely' );
		$mode       = 'hint' === $confidence
			? 'queue'
			: TransparAI_Options::get( 'certain' === $confidence ? 'mode_certain' : 'mode_likely' );

		if ( 'off' === $mode ) {
			return 'skipped';
		}
		return 'flag' === $mode ? 'flagged' : 'queued';
	}

	/**
	 * Empty tally for a scan run.
	 *
	 * @return array<string, int>
	 */
	public static function empty_stats(): array {
		return array(
			'processed'  => 0,
			'flagged'    => 0,
			'queued'     => 0,
			'skipped'    => 0,
			'clean'      => 0,
			'unreadable' => 0,
		);
	}

	/**
	 * Count one scan status into a tally. Shared by the admin batch and the
	 * CLI so a status cannot land in different buckets depending on the caller.
	 *
	 * @param array<string, int> $stats  Current tally.
	 * @param string             $status Status from scan_attachment().
	 * @return array<string, int>
	 */
	public static function tally( array $stats, string $status ): array {
		++$stats['processed'];
		$bucket = isset( $stats[ $status ] ) && 'processed' !== $status ? $status : 'clean';
		++$stats[ $bucket ];
		return $stats;
	}

	/**
	 * AJAX: process one scan batch of the media library.
	 *
	 * Request: offset (int), mode ('missing' scans unscanned only, 'all' rescans).
	 * Response: processed, flagged, queued, clean, unreadable, offset, remaining.
	 */
	public static function ajax_scan_batch(): void {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'transparai' ) ), 403 );
		}

		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
		$mode   = isset( $_POST['mode'] ) && 'all' === sanitize_key( wp_unslash( (string) $_POST['mode'] ) ) ? 'all' : 'missing';

		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => self::mime_types(),
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'posts_per_page'         => self::BATCH_SIZE,
			'offset'                 => $offset,
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
		);
		if ( 'missing' === $mode ) {
			$args['offset']     = 0; /* Scanned items drop out of the query themselves. */
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded batch query (20 rows) in an explicit admin scan.
				array(
					'key'     => TransparAI_Meta::KEY_SCANNED,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$query = new WP_Query( $args );
		$stats = self::empty_stats();

		$started = microtime( true );
		foreach ( $query->posts as $attachment_id ) {
			$scan  = self::scan_attachment( (int) $attachment_id );
			$stats = self::tally( $stats, $scan['status'] );
			if ( microtime( true ) - $started > self::TIME_BUDGET ) {
				break; /* Partial batch: the client continues with the returned offset. */
			}
		}

		$next_offset = 'missing' === $mode ? 0 : $offset + $stats['processed'];
		$total       = (int) $query->found_posts;
		$remaining   = 'missing' === $mode
			? max( 0, $total - $stats['processed'] )
			: max( 0, $total - $next_offset );

		wp_send_json_success(
			array_merge(
				$stats,
				array(
					'offset'    => $next_offset,
					'remaining' => $remaining,
				)
			)
		);
	}

	/**
	 * AJAX: re-check a single attachment (button in the attachment details).
	 */
	public static function ajax_recheck(): void {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'transparai' ) ), 403 );
		}
		$attachment_id = isset( $_POST['attachment'] ) ? absint( wp_unslash( $_POST['attachment'] ) ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'transparai' ) ), 400 );
		}

		/* A re-check is an explicit user request: lift a previous dismissal. */
		delete_post_meta( $attachment_id, TransparAI_Meta::KEY_DISMISSED );

		$scan   = self::scan_attachment( $attachment_id );
		$result = $scan['result'];

		wp_send_json_success(
			array(
				'status'     => $scan['status'],
				'generator'  => $result['generator'] ?? '',
				'source'     => $result['source'] ?? '',
				'confidence' => $result['confidence'] ?? '',
				'evidence'   => $result['evidence'] ?? '',
			)
		);
	}

	/**
	 * Library statistics for the settings page (cached for 60 s).
	 *
	 * @return array{total:int, flagged:int, detected:int, scanned:int}
	 */
	public static function stats(): array {
		$cached = get_transient( 'transparai_stats' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$count_query = static function ( array $extra ): int {
			$query = new WP_Query(
				array_merge(
					array(
						'post_type'              => 'attachment',
						'post_status'            => 'inherit',
						'fields'                 => 'ids',
						'posts_per_page'         => 1,
						'no_found_rows'          => false,
						'update_post_term_cache' => false,
					),
					$extra
				)
			);
			return (int) $query->found_posts;
		};

		$stats = array(
			'total'    => $count_query( array( 'post_mime_type' => self::mime_types() ) ),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin statistics, cached for 60 s.
			'flagged'  => $count_query( array( 'meta_query' => TransparAI_Meta::meta_query( '1' ) ) ),
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin statistics, cached for 60 s.
			'detected' => $count_query( array( 'meta_query' => TransparAI_Meta::meta_query( 'detected' ) ) ),
			'scanned'  => $count_query(
				array(
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin statistics, cached for 60 s.
					'meta_query' => array(
						array(
							'key'     => TransparAI_Meta::KEY_SCANNED,
							'compare' => 'EXISTS',
						),
					),
				)
			),
		);

		set_transient( 'transparai_stats', $stats, MINUTE_IN_SECONDS );
		return $stats;
	}
}
