<?php
/**
 * WP-CLI commands: bulk-scan, label, audit-export and metadata verification,
 * plus the compliance state (text levels, assessment, AI systems, report),
 * built for agencies and large libraries.
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
 * Manage AI labels and the EU AI Act compliance state.
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

		$ids   = $this->all_ids( $query_args );
		$total = count( $ids );
		if ( 0 === $total ) {
			WP_CLI::success( 'Nothing to scan.' );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning media', $total );
		$stats    = TransparAI_Scanner::empty_stats();

		foreach ( $ids as $id ) {
			$id     = (int) $id;
			$status = $dry_run ? self::preview_attachment( $id ) : TransparAI_Scanner::scan_attachment( $id )['status'];
			$stats  = TransparAI_Scanner::tally( $stats, $status );
			$progress->tick();
		}
		$progress->finish();

		WP_CLI::success(
			sprintf(
				'%d scanned: %d flagged, %d queued for review, %d skipped, %d clean, %d unreadable.%s',
				$total,
				$stats['flagged'],
				$stats['queued'],
				$stats['skipped'],
				$stats['clean'],
				$stats['unreadable'],
				$dry_run ? ' (dry run, nothing changed)' : ''
			)
		);
	}

	/**
	 * Report what a scan would do to one attachment, changing nothing.
	 *
	 * Mirrors TransparAI_Scanner::scan_attachment() without its writes and
	 * asks the scanner itself what it would decide, so the preview cannot
	 * drift away from the real run.
	 *
	 * @param int $id Attachment ID.
	 * @return string flagged|queued|skipped|clean|unreadable.
	 */
	private static function preview_attachment( int $id ): string {
		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
			WP_CLI::log( sprintf( '#%d unreadable, would be skipped.', $id ) );
			return 'unreadable';
		}

		$result = TransparAI_Detector::detect_file( $file );
		if ( null === $result || empty( $result['is_ai'] ) ) {
			return 'clean';
		}

		$status = TransparAI_Scanner::planned_status( $id, $result );
		$label  = '' !== $result['generator'] ? $result['generator'] : $result['source'];
		WP_CLI::log( sprintf( '#%d would be %s: %s (%s)', $id, $status, $label, $result['evidence'] ) );
		return $status;
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
	 * Declare attachments as not AI-made (camera photo or human digital work), or withdraw that.
	 *
	 * The declaration removes any AI label, keeps the scanner from re-queuing the
	 * file, and (with file writing enabled) writes digitalCapture or
	 * digitalCreation into files that carry no other digital source type.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more attachment IDs.
	 *
	 * [--type=<type>]
	 * : capture (default) for a camera photo, creation for human digital work.
	 *
	 * [--remove]
	 * : Withdraw the declaration instead.
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai human 12 13 --type=capture
	 *
	 * @param array $args       Attachment IDs.
	 * @param array $assoc_args Flags.
	 */
	public function human( array $args, array $assoc_args ): void {
		$type   = isset( $assoc_args['type'] ) ? sanitize_key( (string) $assoc_args['type'] ) : 'capture';
		$remove = ! empty( $assoc_args['remove'] );
		if ( ! $remove && '' === TransparAI_Meta::sanitize_human( $type ) ) {
			WP_CLI::error( 'Unknown --type, use capture or creation.' );
		}
		$count = 0;
		foreach ( $args as $id ) {
			$id = absint( $id );
			if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
				WP_CLI::warning( sprintf( '#%s is not an attachment, skipped.', $id ) );
				continue;
			}
			if ( $remove ) {
				TransparAI_Meta::unmark_human( $id );
			} else {
				TransparAI_Meta::mark_human( $id, $type, 'cli' );
			}
			++$count;
		}
		WP_CLI::success( sprintf( $remove ? '%d declaration(s) removed.' : '%d attachment(s) declared as not AI-made.', $count ) );
	}

	/**
	 * Readiness score and the checks behind it.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json or yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai score
	 *     wp transparai score --format=json
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function score( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- fixed WP-CLI command signature.
		$score = TransparAI_Compliance::score();
		$rows  = array();
		foreach ( TransparAI_Compliance::factors() as $factor ) {
			$rows[] = array(
				'check' => $factor['id'],
				'met'   => $factor['met'] ? 'yes' : 'no',
				'label' => $factor['label'],
			);
		}
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' === $format ) {
			WP_CLI::line( sprintf( 'Readiness score: %d/100 (%s)', $score, TransparAI_Compliance::traffic( $score ) ) );
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'check', 'met', 'label' ) );
	}

	/**
	 * The audit report as JSON: media rows with their history, the compliance
	 * summary, the site log and the document hash (the same record the admin
	 * print view and GET /transparai/v1/report render).
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : flagged, detected, human, labeled or all (default).
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai report > transparai-report.json
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function report( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- fixed WP-CLI command signature.
		$status = sanitize_key( (string) ( $assoc_args['status'] ?? 'all' ) );
		if ( ! in_array( $status, array( 'flagged', 'detected', 'human', 'labeled', 'all' ), true ) ) {
			WP_CLI::error( 'Unknown --status, use flagged, detected, human, labeled or all.' );
		}
		WP_CLI::line( (string) wp_json_encode( TransparAI_Meta::report( $status ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * List published posts that carry an AI level, or set the level on posts.
	 *
	 * The reviewed level stamps the review with the user the command runs as,
	 * so pass --user=<login> for a stamp that names a person.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Post IDs to change. Without any, the posts with an AI level are listed (first 5000).
	 *
	 * [--level=<level>]
	 * : none, assisted, generated or generated_reviewed. Required with IDs unless --remove is given.
	 *
	 * [--remove]
	 * : Remove the classification from the given posts (a review stamp stays).
	 *
	 * [--format=<format>]
	 * : table (default), csv, json, ids or count.
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai content --format=csv > ai-content.csv
	 *     wp transparai content 42 43 --level=assisted
	 *     wp transparai content 42 --level=generated_reviewed --user=editor
	 *
	 * @param array $args       Post IDs.
	 * @param array $assoc_args Flags.
	 */
	public function content( array $args, array $assoc_args ): void {
		if ( array() === $args ) {
			$rows = TransparAI_Compliance::content_items();
			foreach ( $rows as &$row ) {
				$row['review_current'] = $row['review_current'] ? 'yes' : 'no';
			}
			unset( $row );
			self::print_rows( $assoc_args, $rows, array( 'ID', 'type', 'level', 'title', 'reviewed_by', 'reviewed_on', 'review_current' ) );
			return;
		}

		$remove = isset( $assoc_args['remove'] );
		$level  = $remove ? '' : TransparAI_Meta::sanitize_content_level( (string) ( $assoc_args['level'] ?? '' ) );
		if ( ! $remove && '' === $level ) {
			WP_CLI::error( 'Pass --level=none|assisted|generated|generated_reviewed, or --remove.' );
		}
		$types = TransparAI_Compliance::post_types();
		$count = 0;
		foreach ( $args as $id ) {
			$id = absint( $id );
			if ( ! $id || ! in_array( (string) get_post_type( $id ), $types, true ) ) {
				WP_CLI::warning( sprintf( '#%s is not a public post, skipped.', $id ) );
				continue;
			}
			TransparAI_Meta::set_content_level( $id, $level );
			++$count;
		}
		if ( $remove ) {
			WP_CLI::success( sprintf( '%d classification(s) removed.', $count ) );
			return;
		}
		WP_CLI::success( sprintf( '%d post(s) set to %s.', $count, $level ) );
	}

	/**
	 * Self-assessment and Article 4 checklist: show them, or record answers.
	 *
	 * Answers and ticks carry the name of the user the command runs as, so
	 * pass --user=<login> for a stamp that names a person.
	 *
	 * ## OPTIONS
	 *
	 * [--answer=<list>]
	 * : Comma-separated <question>:<yes|no> pairs, e.g. chatbot:no,ai_text:yes, with the
	 * question IDs this command lists. Unlisted questions keep their answer.
	 *
	 * [--tick=<list>]
	 * : Checklist item IDs done, comma-separated, or all or none; unlisted items count as not done.
	 *
	 * [--format=<format>]
	 * : table (default), csv, json or yaml.
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai assessment
	 *     wp transparai assessment --answer=chatbot:no,ai_text:yes --user=editor
	 *     wp transparai assessment --tick=all
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function assessment( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- fixed WP-CLI command signature.
		if ( isset( $assoc_args['answer'] ) ) {
			$answers = TransparAI_Compliance::assessment();
			foreach ( explode( ',', (string) $assoc_args['answer'] ) as $pair ) {
				$parts = explode( ':', trim( $pair ), 2 );
				$id    = sanitize_key( $parts[0] );
				$value = sanitize_key( $parts[1] ?? '' );
				if ( ! array_key_exists( $id, $answers ) || ! in_array( $value, array( 'yes', 'no' ), true ) ) {
					WP_CLI::error( sprintf( 'Unknown answer "%s", use <question>:yes or <question>:no.', $pair ) );
				}
				$answers[ $id ] = $value;
			}
			TransparAI_Compliance::save_assessment( $answers );
			WP_CLI::log( 'Assessment saved.' );
		}

		if ( isset( $assoc_args['tick'] ) ) {
			$items = array_keys( TransparAI_Compliance::literacy_items() );
			$list  = (string) $assoc_args['tick'];
			if ( 'all' === $list ) {
				$done = $items;
			} elseif ( 'none' === $list ) {
				$done = array();
			} else {
				$done = array_map( 'sanitize_key', explode( ',', $list ) );
				foreach ( array_diff( $done, $items ) as $unknown ) {
					WP_CLI::error( sprintf( 'Unknown checklist item "%s".', $unknown ) );
				}
			}
			TransparAI_Compliance::save_literacy( array_fill_keys( $done, true ) );
			WP_CLI::log( 'Checklist saved.' );
		}

		$state = TransparAI_Compliance::state();
		$rows  = array();
		foreach ( TransparAI_Compliance::questions() as $id => $question ) {
			$rows[] = array(
				'item'  => $id,
				'kind'  => 'question',
				'state' => '' === $state['assessment'][ $id ] ? 'open' : $state['assessment'][ $id ],
				'label' => $question['label'],
			);
		}
		foreach ( TransparAI_Compliance::literacy_items() as $id => $label ) {
			$rows[] = array(
				'item'  => $id,
				'kind'  => 'checklist',
				'state' => $state['literacy'][ $id ] ? 'done' : 'open',
				'label' => $label,
			);
		}
		\WP_CLI\Utils\format_items( (string) ( $assoc_args['format'] ?? 'table' ), $rows, array( 'item', 'kind', 'state', 'label' ) );
	}

	/**
	 * Inventory of AI systems: plugins matched against the bundled registry
	 * plus manual declarations. Runs the first scan by itself.
	 *
	 * ## OPTIONS
	 *
	 * [--rescan]
	 * : Match the active plugins against the registry again first.
	 *
	 * [--format=<format>]
	 * : table (default), csv, json, ids or count.
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai systems --rescan
	 *     wp transparai systems --format=json
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 */
	public function systems( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- fixed WP-CLI command signature.
		if ( isset( $assoc_args['rescan'] ) || 0 === TransparAI_Systems::scanned_at() ) {
			$found = TransparAI_Systems::scan();
			WP_CLI::log( sprintf( 'Scan finished: %d system(s) detected.', count( $found ) ) );
		}
		$rows = array();
		foreach ( TransparAI_Systems::all() as $system ) {
			$rows[] = array(
				'ID'       => $system['id'],
				'name'     => $system['name'],
				'category' => $system['category'],
				'article'  => $system['article'],
				'source'   => $system['source'],
				'visible'  => $system['visible'] ? 'yes' : 'no',
				'evidence' => $system['evidence'],
			);
		}
		self::print_rows( $assoc_args, $rows, array( 'ID', 'name', 'category', 'article', 'source', 'visible', 'evidence' ) );
	}

	/**
	 * Declare an AI system by hand (a tool the registry does not know).
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Name shown in the inventory and the visitor notice.
	 *
	 * [--category=<category>]
	 * : content, image, chatbot, translation, personalisation, seo, search, audio_video, assistant or other (default).
	 *
	 * [--slug=<slug>]
	 * : Plugin directory slug, if the tool is a plugin.
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai systems-declare "House Recommender" --category=personalisation
	 *
	 * @subcommand systems-declare
	 *
	 * @param array $args       The name.
	 * @param array $assoc_args Flags.
	 */
	public function systems_declare( array $args, array $assoc_args ): void {
		$category = sanitize_key( (string) ( $assoc_args['category'] ?? 'other' ) );
		if ( ! in_array( $category, TransparAI_Systems::CATEGORIES, true ) ) {
			WP_CLI::error( 'Unknown --category, use one of: ' . implode( ', ', TransparAI_Systems::CATEGORIES ) . '.' );
		}
		$id = TransparAI_Systems::declare( (string) $args[0], $category, (string) ( $assoc_args['slug'] ?? '' ) );
		if ( '' === $id ) {
			WP_CLI::error( 'The name is empty.' );
		}
		WP_CLI::success( sprintf( 'Declared as %s (not named in the visitor notice yet, see systems-visible).', $id ) );
	}

	/**
	 * Remove manual declarations.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : System IDs as listed by `wp transparai systems` (manual-... entries only).
	 *
	 * @subcommand systems-undeclare
	 *
	 * @param array $args       System IDs.
	 * @param array $assoc_args Flags (unused).
	 */
	public function systems_undeclare( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- fixed WP-CLI command signature.
		$manual = TransparAI_Systems::state()['manual'];
		$count  = 0;
		foreach ( $args as $id ) {
			$id = sanitize_key( (string) $id );
			if ( ! isset( $manual[ $id ] ) ) {
				WP_CLI::warning( sprintf( '%s is not a manual declaration, skipped.', $id ) );
				continue;
			}
			TransparAI_Systems::undeclare( $id );
			++$count;
		}
		WP_CLI::success( sprintf( '%d declaration(s) removed.', $count ) );
	}

	/**
	 * Decide which systems the visitor notice names. The given IDs replace
	 * the current selection; without any, nothing is named.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : System IDs as listed by `wp transparai systems`.
	 *
	 * [--all]
	 * : Name every inventoried system.
	 *
	 * ## EXAMPLES
	 *
	 *     wp transparai systems-visible ai-engine manual-house-recommender
	 *     wp transparai systems-visible --all
	 *
	 * @subcommand systems-visible
	 *
	 * @param array $args       System IDs.
	 * @param array $assoc_args Flags.
	 */
	public function systems_visible( array $args, array $assoc_args ): void {
		$known = array_keys( TransparAI_Systems::all() );
		$ids   = isset( $assoc_args['all'] ) ? $known : array_map( 'sanitize_key', array_map( 'strval', $args ) );
		foreach ( array_diff( $ids, $known ) as $unknown ) {
			WP_CLI::warning( sprintf( '%s is not in the inventory, dropped.', $unknown ) );
		}
		TransparAI_Systems::set_visible( $ids );
		$count = count( array_intersect( $ids, $known ) );
		if ( ! TransparAI_Options::enabled( 'systems_notice' ) ) {
			WP_CLI::warning( 'The AI systems notice is switched off in the settings, so visitors see nothing yet.' );
		}
		WP_CLI::success( sprintf( '%d system(s) named in the visitor notice.', $count ) );
	}

	/**
	 * Print rows in the requested --format, with the bare ID list for ids.
	 *
	 * @param array<string, mixed>             $assoc_args Flags.
	 * @param array<int, array<string, mixed>> $rows       Rows.
	 * @param string[]                         $columns    Columns in order.
	 */
	private static function print_rows( array $assoc_args, array $rows, array $columns ): void {
		$format = sanitize_key( (string) ( $assoc_args['format'] ?? 'table' ) );
		if ( 'ids' === $format ) {
			// The ids format prints the items themselves, so it needs the bare
			// list; handing it the full rows would print "Array" per entry.
			WP_CLI::log( implode( ' ', wp_list_pluck( $rows, 'ID' ) ) );
			return;
		}
		\WP_CLI\Utils\format_items( $format, $rows, $columns );
	}

	/**
	 * List labeled or detected attachments (audit export).
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : flagged (default), detected, human, labeled (flagged or human) or all.
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
		self::print_rows( $assoc_args, TransparAI_Meta::audit_rows( $status ), TransparAI_Meta::audit_columns() );
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
					break; /* sync_attachment covered all files of this attachment. */
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
	 * Check whether the in-file declaration survives delivery to a visitor.
	 *
	 * Fetches the given images over their own public URLs and compares the
	 * delivered bytes with the files on disk. Requires the delivery check to be
	 * enabled in the settings; nothing but this site is ever requested.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs. Without any, a random sample of labeled images is used.
	 *
	 * [--sample=<n>]
	 * : How many labeled images to sample when no IDs are given. Default 5.
	 *
	 * @subcommand verify-delivery
	 *
	 * @param array $args       Attachment IDs.
	 * @param array $assoc_args Flags.
	 */
	public function verify_delivery( array $args, array $assoc_args ): void {
		if ( ! TransparAI_Delivery::enabled() ) {
			WP_CLI::error( 'The delivery check is switched off. Enable it under TransparAI, Settings, File metadata first.' );
		}

		if ( array() === $args ) {
			$sample  = isset( $assoc_args['sample'] ) ? (int) $assoc_args['sample'] : 5;
			$summary = TransparAI_Delivery::check_sample( $sample );
			WP_CLI::success(
				sprintf(
					'%d checked: %d delivered with the declaration, %d without, %d not comparable.',
					$summary['checked'],
					$summary['intact'],
					$summary['stripped'],
					$summary['other']
				)
			);
			return;
		}

		$intact   = 0;
		$stripped = 0;
		$other    = 0;
		foreach ( $args as $id ) {
			$id     = (int) $id;
			$result = TransparAI_Delivery::check( $id );
			TransparAI_Delivery::remember( $id, $result['verdict'] );

			if ( TransparAI_Delivery::VERDICT_INTACT === $result['verdict'] ) {
				++$intact;
			} elseif ( TransparAI_Delivery::VERDICT_STRIPPED === $result['verdict'] ) {
				++$stripped;
			} else {
				++$other;
			}
			WP_CLI::log( sprintf( '#%d %s: %s', $id, $result['verdict'], $result['message'] ) );
		}

		if ( $stripped > 0 ) {
			WP_CLI::warning( sprintf( '%d file(s) reach visitors without their declaration.', $stripped ) );
			return;
		}

		/* Never report success for files that could not be compared at all: an
			unreachable URL says nothing about the declaration, and claiming it
			does would hide exactly the problem this command exists to find. */
		if ( 0 === $intact ) {
			WP_CLI::warning( sprintf( 'Nothing could be compared (%d file(s) unreachable, served elsewhere or unmarked).', $other ) );
			return;
		}
		if ( $other > 0 ) {
			WP_CLI::success( sprintf( '%d file(s) keep their declaration; %d could not be compared.', $intact, $other ) );
			return;
		}
		WP_CLI::success( 'Every checked file keeps its declaration on the way out.' );
	}

	/**
	 * IDs of all labeled attachments.
	 *
	 * @return int[]
	 */
	private function flagged_ids(): array {
		return $this->all_ids(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- explicit CLI bulk operation.
				'meta_query'             => TransparAI_Meta::meta_query( '1' ),
			)
		);
	}

	/**
	 * All matching attachment IDs, fetched in pages of 500 so a large
	 * library never becomes one unbounded query.
	 *
	 * @param array<string, mixed> $args WP_Query arguments without pagination.
	 * @return int[]
	 */
	private function all_ids( array $args ): array {
		$ids  = array();
		$page = 1;
		do {
			$args['posts_per_page'] = 500; // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- page size of a CLI walk, looped until exhausted.
			$args['paged']          = $page;
			$batch                  = ( new WP_Query( $args ) )->posts;
			foreach ( $batch as $id ) {
				$ids[] = (int) $id;
			}
			$fetched = count( $batch );
			++$page;
		} while ( 500 === $fetched );
		return $ids;
	}
}
