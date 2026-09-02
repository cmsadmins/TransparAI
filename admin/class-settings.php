<?php
/**
 * Settings page (Media submenu): badge appearance, detection behavior,
 * file metadata options, library scan with progress, statistics and the
 * auto-repair report.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen.
 */
final class TransparAI_Settings {

	private const PAGE = 'transparai';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . TRANSPARAI_PLUGIN_BASENAME, array( self::class, 'action_links' ) );
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
			'<a href="' . esc_url( admin_url( 'upload.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'transparai' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Add the submenu page under Media.
	 */
	public static function add_page(): void {
		add_submenu_page(
			'upload.php',
			__( 'TransparAI', 'transparai' ),
			__( 'TransparAI', 'transparai' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render' )
		);
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
	 */
	public static function enqueue( string $hook ): void {
		if ( 'media_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/css/admin.css', array(), TRANSPARAI_VERSION );
		wp_enqueue_script( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), TRANSPARAI_VERSION, true );
		wp_localize_script(
			'transparai-admin',
			'transparaiAdmin',
			array(
				'nonce'     => wp_create_nonce( 'transparai_bulk' ),
				'scanNonce' => wp_create_nonce( 'transparai_scan' ),
				'labels'    => array(
					/* translators: 1: processed count, 2: flagged count, 3: queued count. */
					'scanProgress' => __( '%1$d scanned, %2$d auto-labeled, %3$d queued for review.', 'transparai' ),
					'scanDone'     => __( 'Scan complete.', 'transparai' ),
					'scanFailed'   => __( 'Scan request failed. You can restart to continue.', 'transparai' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options = TransparAI_Options::all();
		$stats   = TransparAI_Scanner::stats();
		$report  = TransparAI_Repair::report();

		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only success notice after the Settings API redirect.
			add_settings_error( 'transparai_messages', 'transparai_saved', __( 'Settings saved.', 'transparai' ), 'updated' );
		}
		settings_errors( 'transparai_messages' );
		?>
		<div class="wrap trai-settings">
			<h1><?php esc_html_e( 'TransparAI', 'transparai' ); ?></h1>

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
				<?php if ( $stats['detected'] > 0 ) : ?>
					<p><a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&transparai_filter=detected' ) ); ?>"><?php esc_html_e( 'Open review queue', 'transparai' ); ?></a></p>
				<?php endif; ?>
			</section>

			<section class="trai-card">
				<h2 class="trai-card-title"><?php esc_html_e( 'Scan existing library', 'transparai' ); ?></h2>
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
			</section>

			<form method="post" action="options.php">
				<?php settings_fields( 'transparai' ); ?>

				<section class="trai-card">
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
						<th scope="row"><label for="trai-badge-text"><?php esc_html_e( 'Badge text', 'transparai' ); ?></label></th>
						<td>
							<input type="text" id="trai-badge-text" class="regular-text" name="<?php self::name( 'badge_text' ); ?>" value="<?php echo esc_attr( $options['badge_text'] ); ?>" placeholder="<?php esc_attr_e( 'AI-generated', 'transparai' ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave empty for the translated default label.', 'transparai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Appearance', 'transparai' ); ?></th>
						<td>
							<?php
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
							<?php esc_html_e( 'Show the detected generator in the badge (e.g. "AI-generated · Midjourney")', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'badge_alt_append' ); ?>" value="1" <?php checked( $options['badge_alt_append'], '1' ); ?> />
							<?php esc_html_e( 'Append the label to the image alt text (screen readers)', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'background_badges' ); ?>" value="1" <?php checked( $options['background_badges'], '1' ); ?> />
							<?php esc_html_e( 'Also label images printed without an attachment ID (ACF fields, sliders, builders) and CSS backgrounds (experimental, needs JavaScript)', 'transparai' ); ?></label>
						</td>
					</tr>
				</table>
				</section>

				<section class="trai-card">
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
				</section>

				<section class="trai-card">
				<h2 class="trai-card-title"><?php esc_html_e( 'Machine-readable file metadata', 'transparai' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Write into files', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'write_xmp' ); ?>" value="1" <?php checked( $options['write_xmp'], '1' ); ?> />
							<?php esc_html_e( 'Write the IPTC DigitalSourceType as XMP into labeled JPEG, PNG and WebP files (all size variants)', 'transparai' ); ?></label><br />
							<label><input type="checkbox" name="<?php self::name( 'write_iim' ); ?>" value="1" <?php checked( $options['write_iim'], '1' ); ?> />
							<?php esc_html_e( 'Also mirror it into IPTC-IIM (JPEG, only when the file has no other IPTC block)', 'transparai' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-repair', 'transparai' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php self::name( 'auto_repair' ); ?>" value="1" <?php checked( $options['auto_repair'], '1' ); ?> />
							<?php esc_html_e( 'Restore the metadata when image optimizers or regeneration strip it (hourly integrity sweep)', 'transparai' ); ?></label>
						</td>
					</tr>
				</table>
				</section>

				<section class="trai-card">
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
		</div>
		<?php
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
