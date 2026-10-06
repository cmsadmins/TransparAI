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
	 * Attachments whose next metadata update must write the sizes even when
	 * auto repair is off: the copies the block editor's image edits create.
	 *
	 * @var array<int, bool>
	 */
	private static array $pending = array();

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'wp_update_attachment_metadata', array( self::class, 'on_metadata_update' ), PHP_INT_MAX - 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( self::class, 'on_metadata_generate' ), 20, 2 );
		add_filter( 'wp_edited_image_metadata', array( self::class, 'on_edited_copy' ), 20, 3 );
		add_action( self::CRON_HOOK, array( self::class, 'verify_batch' ) );

		/**
		 * Filter the optimizer completion hooks after which the files are
		 * re-checked immediately instead of on the next hourly sweep.
		 *
		 * @param string[] $hooks Action names; each receives the attachment ID (or post) as first argument.
		 */
		$optimizer_hooks = apply_filters(
			'transparai_optimizer_hooks',
			array(
				'shortpixel_image_optimised',
				'after_imagify_optimize_attachment',
				'image_smushed',
				'wp_smush_after_attachment_upload',
				'ewww_image_optimizer_post_optimization',
			)
		);
		foreach ( $optimizer_hooks as $hook ) {
			add_action( $hook, array( self::class, 'on_optimizer_done' ), 20 );
		}

		/*
		 * The activation hook fires once per network-wide activation, so a site
		 * created afterwards would never get the sweep, and its labeled files
		 * would silently stay unrepaired. Scheduling here instead covers those
		 * sites, restored backups and cron entries lost to a migration alike;
		 * the call is idempotent and costs one cached option read.
		 */
		self::schedule();
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
	 * Store the file fingerprint as of the last detection scan.
	 *
	 * Kept apart from the writer's fingerprint: the writer refreshes its own
	 * after every write, while this one answers "did anything touch the files
	 * since the detector last looked at them".
	 */
	public static function remember_scan( int $attachment_id ): void {
		update_post_meta( $attachment_id, TransparAI_Meta::KEY_SCAN_FP, self::fingerprint( $attachment_id ) );
	}

	/**
	 * Whether the files changed since the last detection scan, null when no scan fingerprint exists.
	 */
	public static function changed_since_scan( int $attachment_id ): ?bool {
		$stored = get_post_meta( $attachment_id, TransparAI_Meta::KEY_SCAN_FP, true );
		if ( ! is_array( $stored ) || array() === $stored ) {
			return null;
		}
		return self::fingerprint( $attachment_id ) !== $stored;
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
		$pending = isset( self::$pending[ $attachment_id ] );
		unset( self::$pending[ $attachment_id ] );
		if ( ( $pending || self::active() ) && '' !== TransparAI_Writer::expected_token( $attachment_id ) && self::files_changed( $attachment_id ) ) {
			TransparAI_Writer::sync_attachment( $attachment_id );
		}
		return $metadata;
	}

	/**
	 * `wp_edited_image_metadata`: the block editor's crop and rotate save a
	 * new attachment through the image editor, which strips the in-file
	 * declaration and knows nothing of the original's label. Carry the label
	 * state over; the flag meta hook writes the main file at once and the
	 * metadata update that follows writes the sizes.
	 *
	 * @param array|mixed $metadata          Metadata of the new attachment.
	 * @param int         $new_attachment_id New attachment ID.
	 * @param int         $attachment_id     Edited (source) attachment ID.
	 * @return array|mixed
	 */
	public static function on_edited_copy( $metadata, int $new_attachment_id, int $attachment_id ) {
		if ( $new_attachment_id === $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return $metadata;
		}
		$keys = array(
			TransparAI_Meta::KEY_TYPE,
			TransparAI_Meta::KEY_SOURCE,
			TransparAI_Meta::KEY_GENERATOR,
			TransparAI_Meta::KEY_CONFIDENCE,
			TransparAI_Meta::KEY_EVIDENCE,
			TransparAI_Meta::KEY_MARKED_BY,
			TransparAI_Meta::KEY_DETECTED,
			TransparAI_Meta::KEY_DISMISSED,
			TransparAI_Meta::KEY_HUMAN,
			TransparAI_Meta::KEY_FLAG, /* last: its meta hook triggers the write */
		);
		foreach ( $keys as $key ) {
			$value = get_post_meta( $attachment_id, $key, true );
			if ( '' !== $value && null !== $value && false !== $value ) {
				update_post_meta( $new_attachment_id, $key, $value );
			}
		}
		if ( '' !== TransparAI_Writer::expected_token( $new_attachment_id ) ) {
			self::$pending[ $new_attachment_id ] = true;
		}
		return $metadata;
	}

	/**
	 * An optimizer finished re-encoding: check the files now rather than on
	 * the next hourly sweep.
	 *
	 * @param int|WP_Post|array<string, mixed>|mixed $attachment Attachment ID, post or an array carrying one.
	 */
	public static function on_optimizer_done( $attachment ): void {
		$attachment_id = 0;
		if ( is_numeric( $attachment ) ) {
			$attachment_id = (int) $attachment;
		} elseif ( $attachment instanceof WP_Post ) {
			$attachment_id = (int) $attachment->ID;
		} elseif ( is_array( $attachment ) ) {
			foreach ( array( 'attachment_id', 'id', 'ID', 'post_id' ) as $key ) {
				if ( isset( $attachment[ $key ] ) && is_numeric( $attachment[ $key ] ) ) {
					$attachment_id = (int) $attachment[ $key ];
					break;
				}
			}
		}
		if ( $attachment_id > 0 ) {
			self::on_metadata_update( null, $attachment_id );
		}
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
		if ( self::active() && '' !== TransparAI_Writer::expected_token( $attachment_id ) ) {
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
				'meta_query'             => TransparAI_Meta::meta_query( 'labeled' ),
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
			$token        = TransparAI_Writer::expected_token( $attachment_id );
			/*
			 * A non-AI declaration is never written over a foreign source
			 * type, so for those files any declaration counts as present.
			 */
			$expect = TransparAI_Meta::is_flagged( $attachment_id ) ? $token : '';
			if ( '' !== $token && self::files_changed( $attachment_id ) ) {
				foreach ( TransparAI_Writer::attachment_files( $attachment_id ) as $path ) {
					if ( ! TransparAI_Writer::file_is_marked( $path, $expect ) ) {
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
					TransparAI_Meta::record( $attachment_id, 'write-failed', 'sweep' );
				} else {
					++$report['repaired'];
					TransparAI_Meta::record( $attachment_id, 'repaired', 'sweep' );
				}
			}
		}

		$processed = count( $query->posts );
		$total     = (int) $query->found_posts;
		if ( $cursor + $processed >= $total || 0 === $processed ) {
			update_option( self::OPT_CURSOR, 0, false ); /* Sweep complete: start over next hour. */
			$report['completed_at'] = time();
			TransparAI_Meta::log_site(
				'sweep-finished',
				array(
					'checked'  => (int) $report['checked'],
					'repaired' => (int) $report['repaired'],
					'failed'   => (int) $report['failed'],
				)
			);
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
