<?php
/**
 * Auto-repair: keeps the in-file labeling alive when image optimizers,
 * media replacers or thumbnail regeneration rewrite the files underneath
 * a labeled attachment.
 *
 * Two complementary paths:
 *  1. `wp_update_attachment_metadata` at a very late priority with a per-file
 *     fingerprint (size + mtime), catches optimizers that update metadata.
 *  2. An hourly cron sweep over labeled attachments (batch with cursor)
 *     that catches rewrites which never touch attachment metadata.
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
 * Integrity verification + repair.
 */
final class TransparAI_Repair {

	public const CRON_HOOK   = 'transparai_verify_markings';
	private const CRON_BATCH = 25;
	private const OPT_CURSOR = 'transparai_verify_cursor';
	private const OPT_REPORT = 'transparai_repair_report';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'wp_update_attachment_metadata', array( self::class, 'on_metadata_update' ), PHP_INT_MAX - 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( self::class, 'on_metadata_generate' ), 20, 2 );
		add_action( self::CRON_HOOK, array( self::class, 'verify_batch' ) );
	}

	/**
	 * Schedule the hourly verification sweep.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 15 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled sweep.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Store the current file fingerprint of an attachment.
	 */
	public static function remember( int $attachment_id ): void {
		update_post_meta( $attachment_id, TransparAI_Meta::KEY_FINGERPRINT, self::fingerprint( $attachment_id ) );
	}

	/**
	 * size+mtime per file, cheap change detection without re-parsing.
	 *
	 * @return array<string, array{size:int, mtime:int}>
	 */
	private static function fingerprint( int $attachment_id ): array {
		// An optimizer may have rewritten the files earlier in this same
		// request; without this, filesize/filemtime can serve stale values
		// from PHP's stat cache and a change goes unnoticed.
		clearstatcache();
		$fingerprint = array();
		foreach ( TransparAI_Writer::attachment_files( $attachment_id ) as $path ) {
			// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- files may vanish mid-loop; a zero entry is fine.
			$fingerprint[ basename( $path ) ] = array(
				'size'  => (int) @filesize( $path ),
				'mtime' => (int) @filemtime( $path ),
			);
			// phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $fingerprint;
	}

	/**
	 * Whether the attachment's files changed since the stored fingerprint.
	 * A changed file count (new sizes, converted formats) also counts.
	 */
	public static function files_changed( int $attachment_id ): bool {
		$stored = get_post_meta( $attachment_id, TransparAI_Meta::KEY_FINGERPRINT, true );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return true;
		}
		$current = self::fingerprint( $attachment_id );
		if ( count( $current ) !== count( $stored ) ) {
			return true;
		}
		foreach ( $current as $name => $entry ) {
			if ( ! isset( $stored[ $name ] ) ) {
				return true;
			}
			if ( (int) $stored[ $name ]['size'] !== $entry['size'] || (int) $stored[ $name ]['mtime'] !== $entry['mtime'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Late `wp_update_attachment_metadata` filter: optimizers and media
	 * replacers run through this, re-apply the labeling when files drifted.
	 *
	 * @param array|mixed $metadata      Attachment metadata.
	 * @param int         $attachment_id Attachment ID.
	 * @return array|mixed
	 */
	public static function on_metadata_update( $metadata, int $attachment_id ) {
		if ( self::active() && TransparAI_Meta::is_flagged( $attachment_id ) && self::files_changed( $attachment_id ) ) {
			TransparAI_Writer::sync_attachment( $attachment_id );
		}
		return $metadata;
	}

	/**
	 * `wp_generate_attachment_metadata` (priority 20, after core and most
	 * optimizer size generation): unconditional re-sync on regeneration.
	 *
	 * @param array|mixed $metadata      Attachment metadata.
	 * @param int         $attachment_id Attachment ID.
	 * @return array|mixed
	 */
	public static function on_metadata_generate( $metadata, int $attachment_id ) {
		if ( self::active() && TransparAI_Meta::is_flagged( $attachment_id ) ) {
			TransparAI_Writer::sync_attachment( $attachment_id );
		}
		return $metadata;
	}

	/**
	 * Hourly sweep: verify a batch of labeled attachments, repair when the
	 * in-file declaration went missing.
	 */
	public static function verify_batch(): void {
		if ( ! self::active() ) {
			return;
		}

		$cursor = absint( get_option( self::OPT_CURSOR, 0 ) );
		$query  = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => self::CRON_BATCH,
				'offset'                 => $cursor,
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded hourly cron batch (25 rows).
				'meta_query'             => TransparAI_Meta::meta_query( '1' ),
			)
		);

		$report = get_option( self::OPT_REPORT, array() );
		if ( ! is_array( $report ) ) {
			$report = array();
		}
		$report = array_merge(
			array(
				'checked'  => 0,
				'repaired' => 0,
				'failed'   => 0,
			),
			$report
		);

		foreach ( $query->posts as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			++$report['checked'];

			$needs_repair = false;
			if ( self::files_changed( $attachment_id ) ) {
				foreach ( TransparAI_Writer::attachment_files( $attachment_id ) as $path ) {
					if ( ! TransparAI_Writer::file_is_marked( $path ) ) {
						$needs_repair = true;
						break;
					}
				}
				if ( ! $needs_repair ) {
					self::remember( $attachment_id ); /* Files changed but marks survived. */
				}
			}

			if ( $needs_repair ) {
				$stats = TransparAI_Writer::sync_attachment( $attachment_id );
				if ( $stats['failed'] > 0 ) {
					++$report['failed'];
				} else {
					++$report['repaired'];
				}
			}
		}

		$processed = count( $query->posts );
		$total     = (int) $query->found_posts;
		if ( $cursor + $processed >= $total || 0 === $processed ) {
			update_option( self::OPT_CURSOR, 0, false ); /* Sweep complete: start over next hour. */
			$report['completed_at'] = time();
		} else {
			update_option( self::OPT_CURSOR, $cursor + $processed, false );
		}
		$report['updated_at'] = time();
		update_option( self::OPT_REPORT, $report, false );

		/* Reset counters once a full sweep finished, so the report shows the last complete pass. */
		if ( isset( $report['completed_at'] ) && $report['completed_at'] === $report['updated_at'] ) {
			update_option(
				self::OPT_REPORT,
				array(
					'checked'       => 0,
					'repaired'      => 0,
					'failed'        => 0,
					'last_checked'  => $report['checked'],
					'last_repaired' => $report['repaired'],
					'last_failed'   => $report['failed'],
					'completed_at'  => $report['completed_at'],
					'updated_at'    => $report['updated_at'],
				),
				false
			);
		}
	}

	/**
	 * Last repair report for the settings page.
	 *
	 * @return array<string, int>
	 */
	public static function report(): array {
		$report = get_option( self::OPT_REPORT, array() );
		return is_array( $report ) ? $report : array();
	}

	/**
	 * Whether repair is active (setting + writing enabled).
	 */
	private static function active(): bool {
		return TransparAI_Options::enabled( 'auto_repair' ) && TransparAI_Options::enabled( 'write_xmp' );
	}
}
