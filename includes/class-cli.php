<?php
/**
 * WP-CLI commands: bulk-scan, label, audit-export and metadata verification,
 * built for agencies and large libraries.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage AI media labels.
 */
final class TransparAI_CLI {

	/**
	 * Register the command namespace.
	 */
	public static function register(): void {
		WP_CLI::add_command( 'transparai', self::class );
	}

	/**
	 * Scan the media library for AI provenance signals.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Rescan everything, including already scanned attachments.
	 *
	 * [--dry-run]
	 * : Only report what would be flagged or queued; change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai scan
	 *     wp transparai scan --all --dry-run
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function scan( array $args, array $assoc_args ): void {
		$rescan  = isset( $assoc_args['all'] );
		$dry_run = isset( $assoc_args['dry-run'] );

		$query_args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => TransparAI_Scanner::mime_types(),
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);
		if ( ! $rescan ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- explicit CLI bulk operation.
			$query_args['meta_query'] = array(
				array(
					'key'     => TransparAI_Meta::KEY_SCANNED,
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$ids   = ( new WP_Query( $query_args ) )->posts;
		$total = count( $ids );
		if ( 0 === $total ) {
			WP_CLI::success( 'Nothing to scan.' );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning media', $total );
		$stats    = array(
			'flagged'    => 0,
			'queued'     => 0,
			'clean'      => 0,
			'unreadable' => 0,
		);

		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $dry_run ) {
				$file   = get_attached_file( $id );
				$result = $file && file_exists( $file ) ? TransparAI_Detector::detect_file( $file ) : null;
				if ( null !== $result ) {
					$label = '' !== $result['generator'] ? $result['generator'] : $result['source'];
					WP_CLI::log( sprintf( '#%d would be %s: %s (%s)', $id, 'certain' === $result['confidence'] ? 'flagged' : 'queued', $label, $result['evidence'] ) );
					++$stats[ 'certain' === $result['confidence'] ? 'flagged' : 'queued' ];
				} else {
					++$stats['clean'];
				}
			} else {
				$scan = TransparAI_Scanner::scan_attachment( $id );
				if ( isset( $stats[ $scan['status'] ] ) ) {
					++$stats[ $scan['status'] ];
				} else {
					++$stats['clean'];
				}
			}
			$progress->tick();
		}
		$progress->finish();

		WP_CLI::success(
			sprintf(
				'%d scanned: %d flagged, %d queued for review, %d clean, %d unreadable.%s',
				$total,
				$stats['flagged'],
				$stats['queued'],
				$stats['clean'],
				$stats['unreadable'],
				$dry_run ? ' (dry run, nothing changed)' : ''
			)
		);
	}

	/**
	 * Label attachments as AI-generated.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more attachment IDs.
	 *
	 * [--source=<text>]
	 * : Note the generator, e.g. "DALL-E 3".
	 *
	 * @param array $args       Attachment IDs.
	 * @param array $assoc_args Flags.
	 */
	public function flag( array $args, array $assoc_args ): void {
		$source = isset( $assoc_args['source'] ) ? sanitize_text_field( (string) $assoc_args['source'] ) : '';
		$count  = 0;
		foreach ( $args as $id ) {
			$id = absint( $id );
			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				WP_CLI::warning( sprintf( '#%s is not an attachment, skipped.', $id ) );
				continue;
			}
			if ( '' !== $source ) {
				update_post_meta( $id, TransparAI_Meta::KEY_GENERATOR, $source );
			}
			TransparAI_Meta::flag( $id, 'cli' );
			++$count;
		}
		WP_CLI::success( sprintf( '%d attachment(s) labeled.', $count ) );
	}

	/**
	 * Remove the AI label from attachments.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more attachment IDs.
	 *
	 * @param array $args       Attachment IDs.
	 * @param array $assoc_args Flags.
	 */
	public function unflag( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- fixed WP-CLI command signature.
		$count = 0;
		foreach ( $args as $id ) {
			$id = absint( $id );
			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			TransparAI_Meta::unflag( $id );
			++$count;
		}
		WP_CLI::success( sprintf( '%d attachment(s) unlabeled.', $count ) );
	}

	/**
	 * List labeled or detected attachments (audit export).
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : flagged (default), detected, or all.
	 *
	 * [--format=<format>]
	 * : table (default), csv, json, ids or count.
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai status --format=csv > ai-media-audit.csv
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = isset( $assoc_args['status'] ) ? sanitize_key( (string) $assoc_args['status'] ) : 'flagged';
		$format = isset( $assoc_args['format'] ) ? sanitize_key( (string) $assoc_args['format'] ) : 'table';

		$meta_query = TransparAI_Meta::meta_query( 'detected' === $status ? 'detected' : '1' );
		if ( 'all' === $status ) {
			$meta_query = array(
				'relation' => 'OR',
				array(
					'key'   => TransparAI_Meta::KEY_FLAG,
					'value' => '1',
				),
				array(
					'key'   => TransparAI_Meta::KEY_DETECTED,
					'value' => '1',
				),
			);
		}

		$ids = ( new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- explicit CLI audit export.
				'meta_query'             => $meta_query,
			)
		) )->posts;

		$rows = array();
		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$rows[] = array(
				'ID'         => $id,
				'file'       => (string) get_post_meta( $id, '_wp_attached_file', true ),
				'status'     => TransparAI_Meta::is_flagged( $id ) ? 'flagged' : 'detected',
				'type'       => TransparAI_Meta::get_type( $id ),
				'source'     => (string) get_post_meta( $id, TransparAI_Meta::KEY_SOURCE, true ),
				'generator'  => TransparAI_Meta::get_generator( $id ),
				'confidence' => (string) get_post_meta( $id, TransparAI_Meta::KEY_CONFIDENCE, true ),
				'marked_by'  => (string) get_post_meta( $id, TransparAI_Meta::KEY_MARKED_BY, true ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'ID', 'file', 'status', 'type', 'source', 'generator', 'confidence', 'marked_by' ) );
	}

	/**
	 * Write the machine-readable metadata into the files of labeled attachments.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Only list the files that would be written.
	 *
	 * [--yes]
	 * : Required for the real write (files are modified in place).
	 *
	 * @subcommand write-meta
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function write_meta( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		if ( ! $dry_run && ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::error( 'This modifies media files in place. Re-run with --yes (or preview with --dry-run).' );
		}

		$ids = $this->flagged_ids();
		if ( array() === $ids ) {
			WP_CLI::success( 'No labeled attachments.' );
			return;
		}

		$written = 0;
		$failed  = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $dry_run ) {
				foreach ( TransparAI_Writer::attachment_files( $id ) as $path ) {
					WP_CLI::log( sprintf( '#%d %s', $id, $path ) );
				}
				continue;
			}
			$stats    = TransparAI_Writer::sync_attachment( $id );
			$written += $stats['written'];
			$failed  += $stats['failed'];
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d labeled attachment(s) listed (dry run).', count( $ids ) ) );
			return;
		}
		WP_CLI::success( sprintf( '%d file(s) written, %d failed.', $written, $failed ) );
	}

	/**
	 * Verify that labeled attachments still carry their in-file metadata.
	 *
	 * ## OPTIONS
	 *
	 * [--repair]
	 * : Re-write missing metadata.
	 *
	 * @subcommand verify-meta
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function verify_meta( array $args, array $assoc_args ): void {
		$repair = isset( $assoc_args['repair'] );

		$missing  = 0;
		$repaired = 0;
		foreach ( $this->flagged_ids() as $id ) {
			$id = (int) $id;
			foreach ( TransparAI_Writer::attachment_files( $id ) as $path ) {
				if ( TransparAI_Writer::file_is_marked( $path ) ) {
					continue;
				}
				++$missing;
				WP_CLI::log( sprintf( '#%d missing mark: %s', $id, $path ) );
				if ( $repair ) {
					$stats = TransparAI_Writer::sync_attachment( $id );
					if ( 0 === $stats['failed'] ) {
						++$repaired;
					}
					break; // sync_attachment covered all files of this attachment.
				}
			}
		}

		if ( 0 === $missing ) {
			WP_CLI::success( 'All labeled attachments carry their in-file metadata.' );
			return;
		}
		WP_CLI::success( sprintf( '%d file(s) missing their mark%s.', $missing, $repair ? ', ' . $repaired . ' attachment(s) repaired' : ' (re-run with --repair to fix)' ) );
	}

	/**
	 * IDs of all labeled attachments.
	 *
	 * @return int[]
	 */
	private function flagged_ids(): array {
		return array_map(
			'intval',
			( new WP_Query(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'fields'                 => 'ids',
					'posts_per_page'         => -1,
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- explicit CLI bulk operation.
					'meta_query'             => TransparAI_Meta::meta_query( '1' ),
				)
			) )->posts
		);
	}
}
