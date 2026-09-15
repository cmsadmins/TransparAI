<?php
/**
 * Settings page (Media submenu): badge appearance, detection behavior,
 * file metadata options, library scan with progress, statistics and the
 * auto-repair report.
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
 * Settings screen.
 */
final class TransparAI_Settings {

	/** Top-level menu slug (dashboard) and this page's slug under it. */
	public const MENU = 'transparai';
	public const PAGE = 'transparai-settings';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_post_transparai_export', array( self::class, 'export_csv' ) );
		add_action( 'admin_post_transparai_print', array( self::class, 'print_view' ) );
		add_filter( 'plugin_action_links_' . TRANSPARAI_PLUGIN_BASENAME, array( self::class, 'action_links' ) );
	}

	/**
	 * Stream the audit export as CSV.
	 *
	 * Same rows as `wp transparai status --format=csv`, for everyone who does
	 * not have shell access to the site.
	 */
	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this.', 'transparai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'transparai_export' );

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		if ( ! in_array( $status, array( 'flagged', 'detected', 'human', 'labeled', 'all' ), true ) ) {
			$status = 'all';
		}

		$report  = TransparAI_Meta::report( $status );
		$columns = array_merge( TransparAI_Meta::audit_columns(), array( 'history' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=transparai-audit-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'The export could not be started.', 'transparai' ) );
		}

		/* BOM so spreadsheets read UTF-8, semicolons for the locales that expect them. */
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output stream for the CSV download.
		fputcsv( $out, $columns, ';' );
		foreach ( $report['items'] as $row ) {
			$line = array();
			foreach ( $columns as $column ) {
				$line[] = self::csv_cell( (string) ( $row[ $column ] ?? '' ) );
			}
			fputcsv( $out, $line, ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream for the CSV download, WP_Filesystem cannot stream a response.
		exit;
	}

	/**
	 * A cell that cannot become a formula when the file is opened in a
	 * spreadsheet (CSV injection: a file name starting with "=" or "@").
	 */
	private static function csv_cell( string $value ): string {
		return 1 === preg_match( '/^[=+\-@\t\r]/', ltrim( $value ) ) ? "'" . $value : $value;
	}

	/**
	 * Print view of the audit report: one self-contained page with the
	 * document hash, the guidance basis and the stated limitations, meant
	 * for the browser's print-to-PDF. No PDF library, nothing to maintain.
	 */
	public static function print_view(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export this.', 'transparai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'transparai_print' );

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all';
		if ( ! in_array( $status, array( 'flagged', 'detected', 'human', 'labeled', 'all' ), true ) ) {
			$status = 'all';
		}
		$report  = TransparAI_Meta::report( $status );
		$columns = array_merge( TransparAI_Meta::audit_columns(), array( 'history' ) );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8" />
<title><?php echo esc_html( sprintf( 'TransparAI %s', __( 'audit report', 'transparai' ) ) ); ?></title>
<style>
body{font:13px/1.5 'Open Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1a1a1a;margin:32px;}
h1{font-size:20px;margin:0 0 4px;}h1 span{color:#FF6800;}
table{border-collapse:collapse;width:100%;margin-top:16px;font-size:11px;}
th,td{border:1px solid #e0e0e0;padding:4px 6px;text-align:left;vertical-align:top;word-break:break-word;}
th{background:#f6f7f7;}
.meta{margin:12px 0;padding:8px 12px;background:#f6f7f7;}
.meta code{word-break:break-all;}
.no-print{margin:12px 0;}
@media print{.no-print{display:none;}body{margin:12mm;}}
</style>
</head>
<body>
<h1>Transpar<span>AI</span> <?php esc_html_e( 'audit report', 'transparai' ); ?></h1>
<p><?php echo esc_html( $report['site'] ); ?>, <?php echo esc_html( $report['generated_at'] ); ?>, <?php echo esc_html( sprintf( '%d %s', count( $report['items'] ), __( 'items', 'transparai' ) ) ); ?><?php echo $report['truncated'] ? ' (' . esc_html( $report['truncated_note'] ) . ')' : ''; ?></p>
<div class="meta">
<p><strong><?php esc_html_e( 'Not legal advice, no liability', 'transparai' ); ?>:</strong> <?php echo esc_html( TransparAI_Dashboard::disclaimer_text() ); ?></p>
<p><strong><?php esc_html_e( 'Document hash', 'transparai' ); ?>:</strong> <code><?php echo esc_html( $report['document_hash'] ); ?></code><br />
		<?php esc_html_e( 'sha256 over the facts below without timestamps: two reports of an unchanged site carry the same hash.', 'transparai' ); ?></p>
<p><strong><?php esc_html_e( 'Basis', 'transparai' ); ?>:</strong> <?php echo esc_html( $report['guidance_basis'] ); ?></p>
<p><strong><?php esc_html_e( 'Limitations', 'transparai' ); ?>:</strong></p>
<ul>
		<?php
		foreach ( $report['limitations'] as $limitation ) :
			?>
	<li><?php echo esc_html( self::limitation_text( $limitation ) ); ?></li><?php endforeach; ?></ul>
</div>
<p class="no-print"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print or save as PDF', 'transparai' ); ?></button></p>
				<?php self::print_compliance_sections( $report ); ?>
<h2><?php esc_html_e( 'Media audit list', 'transparai' ); ?></h2>
<table>
<thead><tr>
		<?php
		foreach ( $columns as $column ) :
			?>
	<th><?php echo esc_html( $column ); ?></th><?php endforeach; ?></tr></thead>
<tbody>
		<?php foreach ( $report['items'] as $row ) : ?>
<tr>
			<?php
			foreach ( $columns as $column ) :
				?>
	<td><?php echo esc_html( (string) ( $row[ $column ] ?? '' ) ); ?></td><?php endforeach; ?></tr>
		<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
		<?php
		exit;
	}


	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array<int, string> $links Action links.
	 * @return array<int, string>
	 */
	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'transparai' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Register the single settings option.
	 */
	public static function register(): void {
		register_setting(
			'transparai',
			TransparAI_Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'TransparAI_Options', 'sanitize' ),
			)
		);
	}

	/**
	 * Page assets (scan loop lives in admin.js, gated by the page markup).
	 * The menu itself is registered by TransparAI_Dashboard.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'transparai_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/css/admin.css', array(), TRANSPARAI_VERSION );
		/* The setup card previews the badge with the real front-end styles. */
		wp_enqueue_style( 'transparai-front', TRANSPARAI_PLUGIN_URL . 'assets/css/front.css', array(), TRANSPARAI_VERSION );
		wp_enqueue_script( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), TRANSPARAI_VERSION, true );
		TransparAI_Media_Library::localize_admin();
	}

	/**
	 * Render the settings page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options = TransparAI_Options::all();
		$tabs    = array(
			'badge'     => __( 'Visible badge', 'transparai' ),
			'text'      => __( 'AI-written text', 'transparai' ),
			'chatbot'   => __( 'Chatbot disclosure', 'transparai' ),
			'systems'   => __( 'AI systems', 'transparai' ),
			'detection' => __( 'Automatic detection', 'transparai' ),
			'files'     => __( 'File metadata', 'transparai' ),
			'uninstall' => __( 'Uninstall', 'transparai' ),
		);
		$active  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'badge'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch.
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'badge';
		}

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only success notice after the Settings API redirect.
			add_settings_error( 'transparai_messages', 'transparai_saved', __( 'Settings saved.', 'transparai' ), 'updated' );
		}
		settings_errors( 'transparai_messages' );
		?>
		<div class="wrap trai-settings">
			<?php
			self::render_header();
			TransparAI_Setup::render_card();
			?>

			<form method="post" action="options.php">
				<?php settings_fields( 'transparai' ); ?>
				<nav class="nav-tab-wrapper trai-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'transparai' ); ?>">
					<?php foreach ( $tabs as $tab_id => $tab_label ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'tab', $tab_id, admin_url( 'admin.php?page=' . self::PAGE ) ) ); ?>" class="nav-tab<?php echo $tab_id === $active ? ' nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr( $tab_id ); ?>"><?php echo esc_html( $tab_label ); ?></a>
					<?php endforeach; ?>
				</nav>

				<section class="trai-card trai-tab-panel"<?php self::panel( 'badge', $active ); ?>>
				<h2 class="trai-card-title"><?php esc_html_e( 'Visible badge', 'transparai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show badge', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'badge_enabled' ); ?>" value="1" <?php checked( $options['badge_enabled'], '1' ); ?> />
							<?php esc_html_e( 'Show a visible badge on labeled media in the front end', 'transparai' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="trai-badge-from"><?php esc_html_e( 'Start date', 'transparai' ); ?></label></th>
						<td>
							<input type="date" id="trai-badge-from" name="<?php self::name( 'badge_from_date' ); ?>" value="<?php echo esc_attr( $options['badge_from_date'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Only media uploaded on or after this date get the front-end badge; earlier media stay labeled in the admin only. Leave empty to badge all labeled media.', 'transparai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="trai-badge-text"><?php esc_html_e( 'Badge text', 'transparai' ); ?></label></th>
						<td>
							<input type="text" id="trai-badge-text" class="regular-text" name="<?php self::name( 'badge_text' ); ?>" value="<?php echo esc_attr( $options['badge_text'] ); ?>" placeholder="<?php esc_attr_e( 'AI-generated', 'transparai' ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Leave empty for the translated default label.', 'transparai' ); ?>
								<?php
								printf(
									/* translators: 1: {generator} placeholder (do not translate), 2: {site} placeholder (do not translate), 3: example template. */
									esc_html__( 'Placeholders: %1$s (detected or manually set generator) and %2$s (site title), e.g. %3$s. Images without a known generator fall back to the default label.', 'transparai' ),
									'<code>{generator}</code>',
									'<code>{site}</code>',
									'<code>' . esc_html( '{generator} prompted by {site}' ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Appearance', 'transparai' ); ?></th>
						<td>
							<?php
							TransparAI_Setup::preview( $options );
							self::select(
								'badge_mode',
								$options['badge_mode'],
								array(
									'overlay' => __( 'Overlay on the image', 'transparai' ),
									'caption' => __( 'Caption line below the image', 'transparai' ),
								)
							);
							self::select(
								'badge_position',
								$options['badge_position'],
								array(
									'top-left'     => __( 'Top left', 'transparai' ),
									'top-right'    => __( 'Top right', 'transparai' ),
									'bottom-left'  => __( 'Bottom left', 'transparai' ),
									'bottom-right' => __( 'Bottom right', 'transparai' ),
								)
							);
							self::select(
								'badge_style',
								$options['badge_style'],
								array(
									'dark'      => __( 'Dark', 'transparai' ),
									'light'     => __( 'Light', 'transparai' ),
									'outline'   => __( 'Outline', 'transparai' ),
									'icon-only' => __( 'Icon only (short label)', 'transparai' ),
								)
							);
							self::select(
								'badge_size',
								$options['badge_size'],
								array(
									'small'  => __( 'Small', 'transparai' ),
									'medium' => __( 'Medium', 'transparai' ),
									'large'  => __( 'Large', 'transparai' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Extras', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'badge_show_source' ); ?>" value="1" <?php checked( $options['badge_show_source'], '1' ); ?> />
							<?php esc_html_e( 'Show the detected generator in the badge (e.g. "AI-generated · Midjourney"; ignored when the badge text contains {generator})', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'badge_alt_append' ); ?>" value="1" <?php checked( $options['badge_alt_append'], '1' ); ?> />
							<?php esc_html_e( 'Append the label to the image alt text (screen readers)', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'badge_guard' ); ?>" value="1" <?php checked( $options['badge_guard'], '1' ); ?> />
							<?php esc_html_e( 'Automatically move a badge to another corner, or below the image, if a theme overlay covers it (recommended, needs JavaScript)', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'background_badges' ); ?>" value="1" <?php checked( $options['background_badges'], '1' ); ?> />
							<?php esc_html_e( 'Also label images printed without an attachment ID (ACF fields, sliders, builders) and CSS backgrounds (experimental, needs JavaScript)', 'transparai' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Not AI', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'human_badge' ); ?>" value="1" <?php checked( $options['human_badge'], '1' ); ?> />
							<?php esc_html_e( 'Also show a badge on media declared as camera photo or human work (the structured data carries the declaration either way)', 'transparai' ); ?></label>
							<p><input type="text" class="regular-text" name="<?php self::name( 'human_badge_text' ); ?>" value="<?php echo esc_attr( $options['human_badge_text'] ); ?>" placeholder="<?php esc_attr_e( 'Human made', 'transparai' ); ?>" /></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page notice', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'page_notice' ); ?>" value="1" <?php checked( $options['page_notice'], '1' ); ?> />
							<?php esc_html_e( 'Add a short note at the end of pages that contain labeled media', 'transparai' ); ?></label>
							<p><input type="text" class="regular-text" name="<?php self::name( 'page_notice_text' ); ?>" value="<?php echo esc_attr( $options['page_notice_text'] ); ?>" placeholder="<?php esc_attr_e( 'This page contains AI-generated media.', 'transparai' ); ?>" /></p>
						</td>
					</tr>
				</table>
				</section>

				<section class="trai-card trai-tab-panel"<?php self::panel( 'text', $active ); ?>>
				<h2 class="trai-card-title"><?php esc_html_e( 'AI-written text', 'transparai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Every post carries an AI level (none, AI-assisted, AI-generated, AI-generated and reviewed), set in the editor sidebar, in Quick Edit or in bulk. AI levels show a note on the post; the shortcode [transparai_notice] and the "AI notice" block place it anywhere by hand.', 'transparai' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="trai-content-notice"><?php esc_html_e( 'Note text', 'transparai' ); ?></label></th>
						<td>
							<input type="text" id="trai-content-notice" class="regular-text" name="<?php self::name( 'content_notice_text' ); ?>" value="<?php echo esc_attr( $options['content_notice_text'] ); ?>" placeholder="<?php esc_attr_e( 'This text was generated by AI.', 'transparai' ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty for a translated wording per level ("created with the help of AI", "generated by AI", "generated by AI and reviewed by a person").', 'transparai' ); ?></p>
							<?php
							self::select(
								'content_notice_position',
								$options['content_notice_position'],
								array(
									'before' => __( 'Ahead of the content', 'transparai' ),
									'after'  => __( 'After the content', 'transparai' ),
									'both'   => __( 'Ahead of and after the content', 'transparai' ),
								)
							);
							self::select(
								'content_notice_style',
								$options['content_notice_style'],
								array(
									'block'  => __( 'Block (own line)', 'transparai' ),
									'inline' => __( 'Inline (inside text)', 'transparai' ),
									'banner' => __( 'Banner (dismissible)', 'transparai' ),
									'badge'  => __( 'Badge (small chip)', 'transparai' ),
									'modal'  => __( 'Modal (button opens a dialog)', 'transparai' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post titles', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'content_title_badge' ); ?>" value="1" <?php checked( $options['content_title_badge'], '1' ); ?> />
							<?php esc_html_e( 'Append a small AI badge to the title of AI-written posts in lists and archives', 'transparai' ); ?></label>
							<p class="description"><?php esc_html_e( 'The badge is HTML inside the title. Themes that print titles unescaped inside attributes should leave this off.', 'transparai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Review', 'transparai' ); ?></th>
						<td>
							<input type="text" class="regular-text" name="<?php self::name( 'content_responsible' ); ?>" value="<?php echo esc_attr( $options['content_responsible'] ); ?>" placeholder="<?php esc_attr_e( 'Name of the person responsible', 'transparai' ); ?>" />
							<p class="description"><?php esc_html_e( 'Default for the "responsible person" of reviewed texts; each post can name someone else. The reviewer, date and a fingerprint of the reviewed text are recorded with the post.', 'transparai' ); ?></p>
							<label><input type="checkbox" name="<?php self::name( 'content_show_reviewer' ); ?>" value="1" <?php checked( $options['content_show_reviewer'], '1' ); ?> />
							<?php esc_html_e( 'Name the reviewer and the review date in the public note and in the structured data', 'transparai' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'New posts', 'transparai' ); ?></th>
						<td>
							<?php
							self::select( 'content_default_level', $options['content_default_level'], TransparAI_Notice::level_labels() );
							?>
							<p class="description"><?php esc_html_e( 'Preselected level for posts created from now on. Existing posts are never reclassified.', 'transparai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Excerpts and feeds', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'content_excerpt_notice' ); ?>" value="1" <?php checked( $options['content_excerpt_notice'], '1' ); ?> />
							<?php esc_html_e( 'Append the note as plain text to excerpts (archives, teasers, related posts)', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'feed_notice' ); ?>" value="1" <?php checked( $options['feed_notice'], '1' ); ?> />
							<?php esc_html_e( 'Add the note to RSS feed items (content, summary and a machine-readable dc:description element)', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'feed_title_prefix' ); ?>" value="1" <?php checked( $options['feed_title_prefix'], '1' ); ?> />
							<?php esc_html_e( 'Prefix feed item titles of AI-written posts with [AI]', 'transparai' ); ?></label>
						</td>
					</tr>
				</table>
				</section>

				<section class="trai-card trai-tab-panel"<?php self::panel( 'chatbot', $active ); ?>>
				<h2 class="trai-card-title"><?php esc_html_e( 'Chatbot disclosure', 'transparai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Visitors must be told when they talk to an AI system, at the latest when the conversation starts. Your answer decides; what the plugin finds on the site only informs it, because most chat widgets are live chats where a person answers and a false "this is an AI" would mislead visitors. Detection is local: active plugins, theme snippets, the scripts a page registers, and what your own browser saw as an administrator. No request leaves the server.', 'transparai' ); ?></p>
				<?php $findings = TransparAI_Chatbot::findings(); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Found on this site', 'transparai' ); ?></th>
						<td>
							<?php if ( array() === $findings ) : ?>
								<p><?php esc_html_e( 'No known chat or chatbot widget found so far. Open a few pages of your site while logged in; the check runs in your browser and reports here.', 'transparai' ); ?></p>
							<?php else : ?>
								<ul class="trai-findings">
									<?php
									$staffing_labels = array(
										'ai'      => __( 'a bot answers', 'transparai' ),
										'human'   => __( 'people answer', 'transparai' ),
										'mixed'   => __( 'live chat with optional bot', 'transparai' ),
										'unknown' => __( 'staffing unknown', 'transparai' ),
									);
									foreach ( $findings as $finding ) :
										?>
										<li><strong><?php echo esc_html( $finding['name'] ); ?></strong> (<?php echo esc_html( $staffing_labels[ $finding['staffing'] ] ?? $finding['staffing'] ); ?>), <?php echo esc_html( $finding['evidence'] ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Does this site run a chat?', 'transparai' ); ?></th>
						<td>
							<?php
							self::select(
								'chatbot_answer',
								$options['chatbot_answer'],
								array(
									'unknown' => __( 'Not decided yet', 'transparai' ),
									'yes'     => __( 'Yes', 'transparai' ),
									'no'      => __( 'No', 'transparai' ),
								)
							);
							self::select(
								'chatbot_staffing',
								$options['chatbot_staffing'],
								array(
									'ai'    => __( 'An AI answers', 'transparai' ),
									'mixed' => __( 'People answer, an AI sometimes', 'transparai' ),
									'human' => __( 'Only people answer', 'transparai' ),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'The notice appears only with "Yes" and an AI that answers at least sometimes.', 'transparai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="trai-chatbot-text"><?php esc_html_e( 'Notice', 'transparai' ); ?></label></th>
						<td>
							<input type="text" id="trai-chatbot-text" class="regular-text" name="<?php self::name( 'chatbot_notice_text' ); ?>" value="<?php echo esc_attr( $options['chatbot_notice_text'] ); ?>" placeholder="<?php esc_attr_e( 'You are chatting with an AI system.', 'transparai' ); ?>" />
							<?php
							self::select(
								'chatbot_output',
								$options['chatbot_output'],
								array(
									'chat'   => __( 'As the first message of the bot (AI Engine; otherwise next to the widget)', 'transparai' ),
									'badge'  => __( 'Next to the chat widget', 'transparai' ),
									'footer' => __( 'As a line at the end of every page', 'transparai' ),
								)
							);
							?>
						</td>
					</tr>
				</table>
				</section>

				<section class="trai-card trai-tab-panel"<?php self::panel( 'systems', $active ); ?>>
				<h2 class="trai-card-title"><?php esc_html_e( 'AI systems in use', 'transparai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The plugin matches your installed plugins against a bundled list of known AI tools and lets you declare others by hand. Which of them visitors get told about is decided per system on the AI Systems page; nothing is switched on from a finding alone. The list ships with the plugin, nothing is fetched.', 'transparai' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Visitor notice', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'systems_notice' ); ?>" value="1" <?php checked( $options['systems_notice'], '1' ); ?> />
							<?php esc_html_e( 'Show a notice naming the AI systems marked as visible on every page', 'transparai' ); ?></label>
							<p>
							<?php
							self::select(
								'systems_notice_style',
								$options['systems_notice_style'],
								array(
									'footer' => __( 'Line at the end of the page', 'transparai' ),
									'badge'  => __( 'Small badge in a corner', 'transparai' ),
									'banner' => __( 'Banner (dismissible)', 'transparai' ),
								)
							);
							?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="trai-systems-notice"><?php esc_html_e( 'Notice text', 'transparai' ); ?></label></th>
						<td>
							<input type="text" id="trai-systems-notice" class="regular-text" name="<?php self::name( 'systems_notice_text' ); ?>" value="<?php echo esc_attr( $options['systems_notice_text'] ); ?>" placeholder="<?php /* translators: %s: names of the visible AI systems. */ esc_attr_e( 'This site uses AI systems: %s.', 'transparai' ); ?>" />
							<p class="description"><?php /* translators: %s is the literal placeholder token shown to the user. */ esc_html_e( 'Leave empty for the translated default. %s is replaced with the names of the visible systems.', 'transparai' ); ?></p>
							<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=transparai-systems' ) ); ?>"><?php esc_html_e( 'Manage detected and declared AI systems', 'transparai' ); ?></a></p>
						</td>
					</tr>
				</table>
				</section>

				<section class="trai-card trai-tab-panel"<?php self::panel( 'detection', $active ); ?>>
				<h2 class="trai-card-title"><?php esc_html_e( 'Automatic detection', 'transparai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Detect on upload', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'autodetect' ); ?>" value="1" <?php checked( $options['autodetect'], '1' ); ?> />
							<?php esc_html_e( 'Inspect new uploads for AI provenance (C2PA, XMP/IPTC, generator signatures)', 'transparai' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Standard declarations', 'transparai' ); ?></th>
						<td>
							<?php
							self::select(
								'mode_certain',
								$options['mode_certain'],
								array(
									'flag'  => __( 'Label automatically', 'transparai' ),
									'queue' => __( 'Put into review queue', 'transparai' ),
									'off'   => __( 'Ignore', 'transparai' ),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Files that explicitly declare AI origin (IPTC DigitalSourceType, AI claim in C2PA, AIGC declaration).', 'transparai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Generator signatures', 'transparai' ); ?></th>
						<td>
							<?php
							self::select(
								'mode_likely',
								$options['mode_likely'],
								array(
									'flag'  => __( 'Label automatically', 'transparai' ),
									'queue' => __( 'Put into review queue', 'transparai' ),
									'off'   => __( 'Ignore', 'transparai' ),
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Strong signals without a formal declaration (Stable Diffusion parameters, ComfyUI workflows, generator names in EXIF/XMP, camera-written C2PA).', 'transparai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'More sources', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'detect_av' ); ?>" value="1" <?php checked( $options['detect_av'], '1' ); ?> />
							<?php esc_html_e( 'Also scan video and audio uploads (MP4/MOV C2PA, MP3 AIGC declarations)', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'filename_hints' ); ?>" value="1" <?php checked( $options['filename_hints'], '1' ); ?> />
							<?php esc_html_e( 'Use filename patterns as review hints (never labels automatically)', 'transparai' ); ?></label>
						</td>
					</tr>
				</table>
				<?php self::render_scan_card( false ); ?>
				</section>

				<section class="trai-card trai-tab-panel"<?php self::panel( 'files', $active ); ?>>
				<h2 class="trai-card-title"><?php esc_html_e( 'Machine-readable file metadata', 'transparai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Write into files', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'write_xmp' ); ?>" value="1" <?php checked( $options['write_xmp'], '1' ); ?> />
							<?php esc_html_e( 'Write the IPTC DigitalSourceType as XMP into labeled JPEG, PNG, WebP and AVIF files (all size variants)', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'write_iim' ); ?>" value="1" <?php checked( $options['write_iim'], '1' ); ?> />
							<?php esc_html_e( 'Also mirror it into IPTC-IIM (JPEG, only when the file has no other IPTC block)', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'write_human' ); ?>" value="1" <?php checked( $options['write_human'], '1' ); ?> />
							<?php esc_html_e( 'Also write digitalCapture or digitalCreation into media you declared as not AI-made, but never over a declaration another tool or a camera already wrote', 'transparai' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-repair', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'auto_repair' ); ?>" value="1" <?php checked( $options['auto_repair'], '1' ); ?> />
							<?php esc_html_e( 'Restore the metadata when image optimizers or regeneration strip it (hourly integrity sweep)', 'transparai' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Delivery check', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'delivery_check' ); ?>" value="1" <?php checked( $options['delivery_check'], '1' ); ?> />
							<?php esc_html_e( 'Allow checking whether the declaration survives delivery', 'transparai' ); ?></label>
							<p class="description"><?php esc_html_e( 'Adds a button to the attachment details and here. On click, the plugin fetches that image over its own public URL and compares the delivered bytes with the file on disk, which is the only way to notice that an optimizing CDN re-encodes your images and drops the declaration on the way out. The request goes to your own site and nowhere else, and it only ever happens when you press the button.', 'transparai' ); ?></p>
							<?php if ( '1' === $options['delivery_check'] ) : ?>
								<p class="trai-actions">
									<button type="button" class="trai-btn trai-btn--ghost" id="trai-delivery-sample"><?php esc_html_e( 'Check a sample of five files', 'transparai' ); ?></button>
									<span class="trai-delivery-result"></span>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Structured data', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'schema_output' ); ?>" value="1" <?php checked( $options['schema_output'], '1' ); ?> />
							<?php esc_html_e( 'Add Schema.org JSON-LD with the IPTC digital source type for the labeled media of each page (search engines read it without opening the files)', 'transparai' ); ?></label>
						</td>
					</tr>
				</table>
				</section>

				<section class="trai-card trai-tab-panel"<?php self::panel( 'uninstall', $active ); ?>>
				<h2 class="trai-card-title"><?php esc_html_e( 'Uninstall', 'transparai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Data removal', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'delete_on_uninstall' ); ?>" value="1" <?php checked( $options['delete_on_uninstall'], '1' ); ?> />
							<?php esc_html_e( 'Delete all plugin data (labels, detection results, settings) when the plugin is uninstalled', 'transparai' ); ?></label>
						</td>
					</tr>
				</table>
				</section>

				<p class="submit"><button type="submit" class="trai-btn"><?php esc_html_e( 'Save changes', 'transparai' ); ?></button></p>
			</form>

			<?php
			TransparAI_Dashboard::render_disclaimer();
			self::render_footer();
			?>
		</div>
		<?php
	}

	/**
	 * The record keeps TransparAI_Meta::LIMITATIONS in English so the document
	 * hash is the same in every locale; the print view shows them translated.
	 */
	private static function limitation_text( string $limitation ): string {
		$translated = array(
			__( 'Detection relies on metadata embedded by generators; files whose metadata was stripped carry no signal.', 'transparai' ),
			__( 'A declaration records a statement by the site operator; it is not cryptographic proof of origin.', 'transparai' ),
			__( 'Text disclosure levels are entered by editors; the review fingerprint shows whether content changed since the review, not whether the review was correct.', 'transparai' ),
			__( 'The readiness score, the self-assessment and the AI literacy checklist summarise the plugin state and the operator\'s own answers; they are not a legal assessment.', 'transparai' ),
		);
		$index      = array_search( $limitation, TransparAI_Meta::LIMITATIONS, true );
		return false === $index ? $limitation : ( $translated[ $index ] ?? $limitation );
	}

	/**
	 * Compliance sections of the print view: score, assessment, checklist,
	 * AI systems, notice status, AI-written content and recent activity.
	 * Plain HTML, plain words (no glyph entities), everything escaped.
	 *
	 * @param array<string, mixed> $report The report record.
	 */
	private static function print_compliance_sections( array $report ): void {
		$c = isset( $report['compliance'] ) && is_array( $report['compliance'] ) ? $report['compliance'] : array();
		if ( array() === $c ) {
			return;
		}
		$yes    = __( 'Yes', 'transparai' );
		$no     = __( 'No', 'transparai' );
		$levels = TransparAI_Notice::level_labels();
		?>
<h2><?php esc_html_e( 'Readiness score', 'transparai' ); ?></h2>
<p><strong><?php echo esc_html( (string) ( $c['score'] ?? 0 ) ); ?> / 100</strong> (<?php echo esc_html( TransparAI_Compliance::traffic_label( (string) ( $c['traffic'] ?? '' ) ) ); ?>)</p>
<table><thead><tr><th><?php esc_html_e( 'Check', 'transparai' ); ?></th><th><?php esc_html_e( 'Done', 'transparai' ); ?></th></tr></thead><tbody>
		<?php foreach ( (array) ( $c['factors'] ?? array() ) as $factor ) : ?>
<tr><td><?php echo esc_html( (string) $factor['label'] ); ?></td><td><?php echo esc_html( ! empty( $factor['met'] ) ? $yes : $no ); ?></td></tr>
		<?php endforeach; ?>
</tbody></table>

<h2><?php esc_html_e( 'Self-assessment', 'transparai' ); ?></h2>
		<?php if ( '' !== (string) ( $c['assessment']['at'] ?? '' ) ) : ?>
<p><?php echo esc_html( sprintf( '%s: %s, %s', __( 'Saved', 'transparai' ), (string) $c['assessment']['at'], (string) $c['assessment']['by'] ) ); ?></p>
		<?php endif; ?>
<table><thead><tr><th><?php esc_html_e( 'Question', 'transparai' ); ?></th><th><?php esc_html_e( 'Answer', 'transparai' ); ?></th><th><?php esc_html_e( 'What the AI Act says', 'transparai' ); ?></th></tr></thead><tbody>
		<?php foreach ( TransparAI_Compliance::questions() as $id => $question ) : ?>
			<?php $answer = (string) ( $c['assessment']['answers'][ $id ] ?? '' ); ?>
<tr><td><?php echo esc_html( $question['text'] ); ?></td><td><?php echo esc_html( 'yes' === $answer ? $yes : ( 'no' === $answer ? $no : __( 'not answered', 'transparai' ) ) ); ?></td><td><?php echo esc_html( $question['duty'] ); ?></td></tr>
		<?php endforeach; ?>
</tbody></table>

<h2><?php esc_html_e( 'AI literacy checklist (Article 4)', 'transparai' ); ?></h2>
<p><?php echo esc_html( sprintf( '%d / %d', (int) ( $c['literacy']['done'] ?? 0 ), (int) ( $c['literacy']['total'] ?? 0 ) ) ); ?><?php echo '' !== (string) ( $c['literacy']['at'] ?? '' ) ? esc_html( sprintf( ', %s: %s, %s', __( 'Saved', 'transparai' ), (string) $c['literacy']['at'], (string) $c['literacy']['by'] ) ) : ''; ?></p>
<table><thead><tr><th><?php esc_html_e( 'Item', 'transparai' ); ?></th><th><?php esc_html_e( 'Done', 'transparai' ); ?></th></tr></thead><tbody>
		<?php foreach ( TransparAI_Compliance::literacy_items() as $id => $label ) : ?>
<tr><td><?php echo esc_html( $label ); ?></td><td><?php echo esc_html( ! empty( $c['literacy']['items'][ $id ] ) ? $yes : $no ); ?></td></tr>
		<?php endforeach; ?>
</tbody></table>

<h2><?php esc_html_e( 'AI systems in use', 'transparai' ); ?></h2>
		<?php if ( array() === (array) ( $c['systems'] ?? array() ) ) : ?>
<p><?php esc_html_e( 'None detected or declared.', 'transparai' ); ?></p>
		<?php else : ?>
<table><thead><tr><th><?php esc_html_e( 'System', 'transparai' ); ?></th><th><?php esc_html_e( 'Category', 'transparai' ); ?></th><th><?php esc_html_e( 'Articles', 'transparai' ); ?></th><th><?php esc_html_e( 'Source', 'transparai' ); ?></th><th><?php esc_html_e( 'Visitors told', 'transparai' ); ?></th></tr></thead><tbody>
			<?php foreach ( (array) $c['systems'] as $system ) : ?>
<tr><td><?php echo esc_html( (string) $system['name'] ); ?></td><td><?php echo esc_html( TransparAI_Systems::category_labels()[ $system['category'] ] ?? (string) $system['category'] ); ?></td><td><?php echo esc_html( (string) $system['article'] ); ?></td><td><?php echo esc_html( 'manual' === $system['source'] ? __( 'declared by hand', 'transparai' ) : __( 'Detected', 'transparai' ) ); ?></td><td><?php echo esc_html( ! empty( $system['visible'] ) ? $yes : $no ); ?></td></tr>
			<?php endforeach; ?>
</tbody></table>
		<?php endif; ?>

<h2><?php esc_html_e( 'Disclosure notices', 'transparai' ); ?></h2>
<table><tbody>
		<?php
		$notice_labels = array(
			'content_notice_style'    => __( 'Text note style', 'transparai' ),
			'content_notice_position' => __( 'Text note position', 'transparai' ),
			'badge_enabled'           => __( 'Visible badge on labeled media', 'transparai' ),
			'page_notice'             => __( 'Page notice on pages with labeled media', 'transparai' ),
			'chatbot_answer'          => __( 'Chatbot question answered with', 'transparai' ),
			'chatbot_active'          => __( 'Chatbot notice active', 'transparai' ),
			'systems_notice'          => __( 'AI systems notice', 'transparai' ),
			'schema_output'           => __( 'Structured data (Schema.org)', 'transparai' ),
		);
		foreach ( (array) ( $c['notices'] ?? array() ) as $key => $value ) :
			?>
<tr><td><?php echo esc_html( $notice_labels[ $key ] ?? (string) $key ); ?></td><td><?php echo esc_html( is_bool( $value ) ? ( $value ? $yes : $no ) : (string) $value ); ?></td></tr>
		<?php endforeach; ?>
</tbody></table>

<h2><?php esc_html_e( 'AI-written content', 'transparai' ); ?></h2>
<p>
		<?php
		foreach ( (array) ( $c['content']['counts'] ?? array() ) as $level => $count ) {
			echo esc_html( ( $levels[ $level ] ?? (string) $level ) . ': ' . (string) $count ) . ' ';
		}
		?>
</p>
		<?php if ( array() !== (array) ( $c['content']['items'] ?? array() ) ) : ?>
<table><thead><tr><th>ID</th><th><?php esc_html_e( 'Title', 'transparai' ); ?></th><th><?php esc_html_e( 'Type', 'transparai' ); ?></th><th><?php esc_html_e( 'Level', 'transparai' ); ?></th><th><?php esc_html_e( 'Reviewed by', 'transparai' ); ?></th><th><?php esc_html_e( 'Reviewed on', 'transparai' ); ?></th><th><?php esc_html_e( 'Review current', 'transparai' ); ?></th></tr></thead><tbody>
			<?php foreach ( (array) $c['content']['items'] as $item ) : ?>
<tr><td><?php echo esc_html( (string) $item['ID'] ); ?></td><td><?php echo esc_html( (string) $item['title'] ); ?></td><td><?php echo esc_html( (string) $item['type'] ); ?></td><td><?php echo esc_html( $levels[ $item['level'] ] ?? (string) $item['level'] ); ?></td><td><?php echo esc_html( (string) $item['reviewed_by'] ); ?></td><td><?php echo esc_html( (string) $item['reviewed_on'] ); ?></td><td><?php echo esc_html( '' === (string) $item['reviewed_on'] ? '' : ( ! empty( $item['review_current'] ) ? $yes : $no ) ); ?></td></tr>
			<?php endforeach; ?>
</tbody></table>
		<?php endif; ?>

<h2><?php esc_html_e( 'Recent activity', 'transparai' ); ?></h2>
<table><thead><tr><th><?php esc_html_e( 'When', 'transparai' ); ?></th><th><?php esc_html_e( 'Event', 'transparai' ); ?></th><th><?php esc_html_e( 'By', 'transparai' ); ?></th></tr></thead><tbody>
		<?php foreach ( (array) ( $report['log'] ?? array() ) as $entry ) : ?>
<tr><td><?php echo esc_html( gmdate( 'c', (int) $entry['t'] ) ); ?></td><td><?php echo esc_html( TransparAI_Compliance::event_label( (string) $entry['e'] ) ); ?></td><td><?php echo esc_html( '' !== (string) $entry['n'] ? (string) $entry['n'] : ( (int) $entry['u'] > 0 ? '#' . (int) $entry['u'] : '' ) ); ?></td></tr>
		<?php endforeach; ?>
</tbody></table>
		<?php
	}

	/**
	 * Logo header shared by every TransparAI admin screen.
	 */
	public static function render_header(): void {
		?>
			<h1 class="trai-logo">
				<?php echo self::logo_mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG built from constants, no user input. ?>
				<span class="trai-logo-text">Transpar<span class="trai-logo-ai">AI</span></span>
			</h1>
		<?php
	}

	/**
	 * Library counters with the review, export and setup links.
	 */
	public static function render_status_card(): void {
		$stats = TransparAI_Scanner::stats();
		?>
			<section class="trai-card">
				<h2 class="trai-card-title"><?php esc_html_e( 'Library status', 'transparai' ); ?></h2>
				<div class="trai-stats">
					<div class="trai-stat">
						<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
						<span class="trai-stat-label"><?php esc_html_e( 'Media files', 'transparai' ); ?></span>
					</div>
					<div class="trai-stat">
						<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $stats['flagged'] ) ); ?></span>
						<span class="trai-stat-label"><?php esc_html_e( 'Labeled as AI', 'transparai' ); ?></span>
					</div>
					<div class="trai-stat<?php echo $stats['detected'] > 0 ? ' trai-stat--action' : ''; ?>">
						<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $stats['detected'] ) ); ?></span>
						<span class="trai-stat-label"><?php esc_html_e( 'Waiting for review', 'transparai' ); ?></span>
					</div>
					<div class="trai-stat">
						<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $stats['scanned'] ) ); ?></span>
						<span class="trai-stat-label"><?php esc_html_e( 'Scanned', 'transparai' ); ?></span>
					</div>
				</div>
				<p class="trai-actions">
					<?php if ( $stats['detected'] > 0 ) : ?>
						<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&transparai_filter=detected' ) ); ?>"><?php esc_html_e( 'Open review queue', 'transparai' ); ?></a>
					<?php endif; ?>
					<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=transparai_export&status=all' ), 'transparai_export' ) ); ?>"><?php esc_html_e( 'Export audit CSV', 'transparai' ); ?></a>
						<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=transparai_print&status=all' ), 'transparai_print' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print view', 'transparai' ); ?></a>
						<?php if ( ! TransparAI_Setup::visible() ) : ?>
							<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( TransparAI_Setup::url() ); ?>"><?php esc_html_e( 'Open setup', 'transparai' ); ?></a>
						<?php endif; ?>
				</p>
				<p class="description"><?php esc_html_e( 'The export lists every labeled, declared and pending file with its detection source, confidence, full history and the person behind each change. The print view adds a document hash over the facts, the guidance basis and the stated limitations; the same record is available at /wp-json/transparai/v1/report.', 'transparai' ); ?></p>
			</section>
		<?php
	}

	/**
	 * Batched library scan with progress and the last auto-repair sweep.
	 *
	 * @param bool $card Own card (images page) or a block inside the
	 *                   detection settings tab.
	 */
	public static function render_scan_card( bool $card = true ): void {
		$report = TransparAI_Repair::report();
		?>
			<?php if ( $card ) : ?>
			<section class="trai-card">
				<h2 class="trai-card-title"><?php esc_html_e( 'Scan existing library', 'transparai' ); ?></h2>
			<?php else : ?>
				<h3 class="trai-card-subtitle"><?php esc_html_e( 'Scan existing library', 'transparai' ); ?></h3>
			<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Reads the metadata of your existing media files (C2PA, XMP/IPTC, generator signatures) in small batches. Nothing leaves your server.', 'transparai' ); ?></p>
				<p class="trai-actions">
					<button type="button" class="trai-btn" id="trai-scan-start" data-mode="missing"><?php esc_html_e( 'Scan new/unscanned media', 'transparai' ); ?></button>
					<button type="button" class="trai-btn trai-btn--ghost" id="trai-scan-all" data-mode="all"><?php esc_html_e( 'Rescan everything', 'transparai' ); ?></button>
					<button type="button" class="trai-btn trai-btn--ghost" id="trai-scan-stop" hidden><?php esc_html_e( 'Pause', 'transparai' ); ?></button>
				</p>
				<div id="trai-scan-progress" hidden>
					<div class="trai-progress"><div class="trai-progress-bar" style="width:0"></div></div>
					<p class="trai-progress-text"></p>
				</div>

				<?php if ( array() !== $report && isset( $report['completed_at'] ) ) : ?>
					<p class="trai-report">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: checked count, 2: repaired count, 3: failed count, 4: human time diff. */
								__( 'Auto-repair, last full sweep: %1$d labeled files checked, %2$d repaired, %3$d failed (%4$s ago).', 'transparai' ),
								(int) ( $report['last_checked'] ?? $report['checked'] ?? 0 ),
								(int) ( $report['last_repaired'] ?? $report['repaired'] ?? 0 ),
								(int) ( $report['last_failed'] ?? $report['failed'] ?? 0 ),
								human_time_diff( (int) $report['completed_at'] )
							)
						);
						?>
					</p>
				<?php endif; ?>
			<?php if ( $card ) : ?>
			</section>
			<?php endif; ?>
		<?php
	}

	/**
	 * Brand footer shared by every TransparAI admin screen.
	 */
	public static function render_footer(): void {
		?>
			<footer class="trai-footer">
				<span class="trai-footer-brand">Transpar<span class="trai-logo-ai">AI</span> <?php echo esc_html( TRANSPARAI_VERSION ); ?></span>
				<nav class="trai-footer-links" aria-label="<?php esc_attr_e( 'TransparAI links', 'transparai' ); ?>">
					<a href="https://www.cms-admins.de/" target="_blank" rel="noopener">cms-admins.de</a>
					<a href="mailto:TransparAI@cms-admins.de">TransparAI@cms-admins.de</a>
					<a href="https://wordpress.org/support/plugin/transparai/" target="_blank" rel="noopener"><?php esc_html_e( 'Support forum', 'transparai' ); ?></a>
				</nav>
			</footer>
		<?php
	}

	/**
	 * The TransparAI mark as inline SVG: photo frame with mountains and sun,
	 * the orange AI corner badge and the scan line underneath. Vector twin of
	 * the wp.org icon so the brand is identical everywhere.
	 */
	public static function logo_mark(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" class="trai-logo-mark" viewBox="0 0 49 46" width="42" height="39" role="img" aria-hidden="true" focusable="false">'
			. '<rect x="3.2" y="4.2" width="41.6" height="31.6" fill="#fffffe" stroke="#1a1a1a" stroke-width="1.6"/>'
			. '<circle cx="32.8" cy="14" r="3.4" fill="#a0a0a0"/>'
			. '<path d="M4 35 L19.2 14 L28.8 35 Z" fill="#1a1a1a"/>'
			. '<path d="M20 35 L31.2 19.4 L44 35 Z" fill="#3d3d3d"/>'
			. '<rect x="2.4" y="41.4" width="43.2" height="2.2" fill="#ff6800"/>'
			. '<rect x="32.3" y="31" width="16.3" height="9.6" rx="1.2" fill="#ff6800"/>'
			. '<path d="M36.9 38.3 L38.85 33.3 L40.8 38.3 M37.6 36.6 h2.5 M43.7 33.3 v5" fill="none" stroke="#fffffe" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg>';
	}

	/**
	 * Echo the id and hidden state of a settings tab panel.
	 */
	private static function panel( string $id, string $active ): void {
		echo ' id="trai-tab-' . esc_attr( $id ) . '"' . ( $id === $active ? '' : ' hidden' );
	}

	/**
	 * Echo a settings field name attribute value.
	 */
	private static function name( string $key ): void {
		echo esc_attr( TransparAI_Options::OPTION . '[' . $key . ']' );
	}

	/**
	 * Echo a select control.
	 *
	 * @param string                $key     Setting key.
	 * @param string                $current Current value.
	 * @param array<string, string> $choices value => label.
	 */
	private static function select( string $key, string $current, array $choices ): void {
		echo '<select name="' . esc_attr( TransparAI_Options::OPTION . '[' . $key . ']' ) . '">';
		foreach ( $choices as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
	}
}
