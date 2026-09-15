<?php
/**
 * First-run setup: three steps on the settings page, each of which writes
 * something real (a scan, the badge look, the file-writing choice), with a
 * journal that can take every write back. No separate screen, no tour, no
 * pointers: the page the user will work with is the onboarding.
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
 * Setup card and its journal.
 */
final class TransparAI_Setup {

	public const OPT_DONE     = 'transparai_setup_done';
	public const OPT_JOURNAL  = 'transparai_setup_journal';
	public const REDIRECT     = 'transparai_activation_redirect';
	public const USER_SKIPPED = 'transparai_setup_skipped';
	private const NONCE       = 'transparai_setup';
	private const JOURNAL_MAX = 20;

	/** Settings keys each step may change (nothing else is ever written by the setup). */
	private const STEP_KEYS = array(
		'badge'   => array( 'badge_style', 'badge_position', 'badge_mode' ),
		'writing' => array( 'write_xmp', 'write_iim', 'write_human', 'auto_repair' ),
	);

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'maybe_redirect' ) );
		add_action( 'admin_post_transparai_setup', array( self::class, 'handle' ) );
	}

	/**
	 * Activation hook: remember to open the settings page once.
	 */
	public static function flag_redirect(): void {
		if ( ! self::done() ) {
			set_transient( self::REDIRECT, 1, 30 );
		}
	}

	/**
	 * Whether the pending activation redirect should happen now. The
	 * transient is consumed either way, so a blocked redirect never fires
	 * on a later request.
	 */
	public static function should_redirect(): bool {
		if ( ! get_transient( self::REDIRECT ) ) {
			return false;
		}
		delete_transient( self::REDIRECT );
		if ( wp_doing_ajax() || is_network_admin() || ! current_user_can( 'manage_options' ) || self::done() ) {
			return false;
		}
		/* A bulk activation of several plugins must not be hijacked. */
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection of the bulk activation screen.
			return false;
		}
		return true;
	}

	/**
	 * Redirect to the settings page after a fresh activation.
	 */
	public static function maybe_redirect(): void {
		if ( ! self::should_redirect() ) {
			return;
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Whether the setup was finished on this site.
	 */
	public static function done(): bool {
		return (bool) get_option( self::OPT_DONE, false );
	}

	/**
	 * Whether the card shows for the current user on this request.
	 */
	public static function visible(): bool {
		if ( isset( $_GET['setup'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen switch.
			return true;
		}
		if ( self::done() ) {
			return false;
		}
		return '1' !== (string) get_user_meta( get_current_user_id(), self::USER_SKIPPED, true );
	}

	/**
	 * Settings page URL with the setup card open.
	 */
	public static function url( string $extra = '' ): string {
		return admin_url( 'admin.php?page=transparai-settings&setup=1' . $extra );
	}

	/* ---------------------------------------------------------------------
	 * Journal
	 * ------------------------------------------------------------------- */

	/**
	 * The journal, oldest entry first.
	 *
	 * @return array<int, array{step:string, before:array<string, string>, at:int, by:int}>
	 */
	public static function journal(): array {
		$journal = get_option( self::OPT_JOURNAL, array() );
		return is_array( $journal ) ? array_values( $journal ) : array();
	}

	/**
	 * Apply a step's settings: snapshot the affected keys first, then write.
	 * The snapshot is what undo() restores; nothing outside STEP_KEYS is touched.
	 *
	 * @param string                $step   badge|writing.
	 * @param array<string, string> $values Raw values for that step's keys.
	 */
	public static function apply( string $step, array $values ): void {
		$keys = self::STEP_KEYS[ $step ] ?? array();
		if ( array() === $keys ) {
			return;
		}
		$current = TransparAI_Options::all();
		$before  = array();
		foreach ( $keys as $key ) {
			$before[ $key ]  = $current[ $key ];
			$current[ $key ] = $values[ $key ] ?? '0';
		}
		$clean = TransparAI_Options::sanitize( $current );

		$journal   = self::journal();
		$journal[] = array(
			'step'   => $step,
			'before' => $before,
			'at'     => time(),
			'by'     => get_current_user_id(),
		);
		if ( count( $journal ) > self::JOURNAL_MAX ) {
			$journal = array_slice( $journal, -self::JOURNAL_MAX );
		}
		update_option( self::OPT_JOURNAL, $journal, false );
		update_option( TransparAI_Options::OPTION, $clean );
		TransparAI_Meta::log_site( 'setup-' . $step, array( 'keys' => $keys ) );
	}

	/**
	 * Take the last step back: restore its snapshot and drop the entry.
	 *
	 * @return string The step that was undone, '' when the journal was empty.
	 */
	public static function undo(): string {
		$journal = self::journal();
		$last    = array_pop( $journal );
		if ( ! is_array( $last ) ) {
			return '';
		}
		$current = TransparAI_Options::all();
		foreach ( (array) ( $last['before'] ?? array() ) as $key => $value ) {
			if ( array_key_exists( $key, $current ) ) {
				$current[ $key ] = (string) $value;
			}
		}
		update_option( TransparAI_Options::OPTION, TransparAI_Options::sanitize( $current ) );
		update_option( self::OPT_JOURNAL, $journal, false );
		TransparAI_Meta::log_site( 'setup-undo', array( 'step' => (string) ( $last['step'] ?? '' ) ) );
		return (string) ( $last['step'] ?? '' );
	}

	/* ---------------------------------------------------------------------
	 * Request handling
	 * ------------------------------------------------------------------- */

	/**
	 * admin-post handler for every setup form.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'transparai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( (string) $_POST['step'] ) ) : '';
		switch ( $step ) {
			case 'badge':
			case 'writing':
				$values = array();
				foreach ( self::STEP_KEYS[ $step ] as $key ) {
					$values[ $key ] = isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( (string) $_POST[ $key ] ) ) : '0';
				}
				self::apply( $step, $values );
				break;
			case 'undo':
				self::undo();
				break;
			case 'finish':
				update_option( self::OPT_DONE, time(), false );
				TransparAI_Meta::log_site( 'setup-finished' );
				wp_safe_redirect( admin_url( 'admin.php?page=transparai-settings&settings-updated=1' ) );
				exit;
			case 'skip':
				update_user_meta( get_current_user_id(), self::USER_SKIPPED, '1' );
				wp_safe_redirect( admin_url( 'admin.php?page=transparai-settings' ) );
				exit;
		}
		wp_safe_redirect( self::url( '&done=' . rawurlencode( $step ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Card
	 * ------------------------------------------------------------------- */

	/**
	 * Badge preview with the real front-end styles: a neutral sample area
	 * plus the badge, classed like the front-end wrapper. admin.js swaps the
	 * classes live when a badge control in the enclosing form changes.
	 *
	 * @param array<string, string> $options Current options.
	 */
	public static function preview( array $options ): void {
		?>
		<div class="trai-setup-preview-wrap">
			<span class="trai-badge-preview trai-wrap trai-pos-<?php echo esc_attr( $options['badge_position'] ); ?> trai-size-<?php echo esc_attr( $options['badge_size'] ); ?> trai-style-<?php echo esc_attr( $options['badge_style'] ); ?> trai-mode-<?php echo esc_attr( $options['badge_mode'] ); ?>">
				<span class="trai-setup-sample" aria-hidden="true"></span>
				<span class="trai-badge" role="note" data-trai-short="<?php echo esc_attr( TransparAI_Frontend::badge_short_label() ); ?>"><?php echo esc_html( TransparAI_Frontend::badge_label() ); ?></span>
			</span>
		</div>
		<?php
	}

	/**
	 * The setup card on the settings page.
	 */
	public static function render_card(): void {
		if ( ! self::visible() ) {
			return;
		}
		$options = TransparAI_Options::all();
		$stats   = TransparAI_Scanner::stats();
		$journal = self::journal();
		$done    = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( (string) $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only marker after the redirect.
		$action  = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<section class="trai-card trai-setup">
			<h2 class="trai-card-title"><?php esc_html_e( 'Setup in three steps', 'transparai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Each step writes something real and can be taken back with the undo button. Nothing is labeled without your review, and no request leaves your server.', 'transparai' ); ?></p>

			<ol class="trai-setup-steps">
				<li class="trai-setup-step">
					<h3><?php esc_html_e( '1. Scan the existing library', 'transparai' ); ?></h3>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: number of media files, 2: number already scanned. */
								__( '%1$d media files, %2$d of them scanned. Clear declarations are labeled, strong signals wait in the review queue.', 'transparai' ),
								(int) $stats['total'],
								(int) $stats['scanned']
							)
						);
						?>
					</p>
					<p><button type="button" class="trai-btn trai-setup-scan"><?php esc_html_e( 'Scan now', 'transparai' ); ?></button></p>
				</li>

				<li class="trai-setup-step">
					<h3><?php esc_html_e( '2. Choose the badge look', 'transparai' ); ?></h3>
					<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" class="trai-setup-form">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="action" value="transparai_setup" />
						<input type="hidden" name="step" value="badge" />
						<?php self::preview( $options ); ?>
						<p>
							<?php
							$styles = array(
								'dark'      => __( 'Dark', 'transparai' ),
								'light'     => __( 'Light', 'transparai' ),
								'outline'   => __( 'Outline', 'transparai' ),
								'icon-only' => __( 'Icon only (short label)', 'transparai' ),
							);
							foreach ( $styles as $value => $label ) :
								?>
								<label class="trai-setup-choice"><input type="radio" name="badge_style" value="<?php echo esc_attr( $value ); ?>" <?php checked( $options['badge_style'], $value ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</p>
						<p>
							<select name="badge_position">
								<?php
								$positions = array(
									'top-left'     => __( 'Top left', 'transparai' ),
									'top-right'    => __( 'Top right', 'transparai' ),
									'bottom-left'  => __( 'Bottom left', 'transparai' ),
									'bottom-right' => __( 'Bottom right', 'transparai' ),
								);
								foreach ( $positions as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options['badge_position'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<select name="badge_mode">
								<option value="overlay" <?php selected( $options['badge_mode'], 'overlay' ); ?>><?php esc_html_e( 'Overlay on the image', 'transparai' ); ?></option>
								<option value="caption" <?php selected( $options['badge_mode'], 'caption' ); ?>><?php esc_html_e( 'Caption line below the image', 'transparai' ); ?></option>
							</select>
							<button type="submit" class="trai-btn"><?php esc_html_e( 'Save badge look', 'transparai' ); ?></button>
							<?php
							if ( 'badge' === $done ) :
								?>
								<span class="trai-setup-done"><?php esc_html_e( 'Saved.', 'transparai' ); ?></span><?php endif; ?>
						</p>
					</form>
				</li>

				<li class="trai-setup-step">
					<h3><?php esc_html_e( '3. Decide about the files', 'transparai' ); ?></h3>
					<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" class="trai-setup-form">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="action" value="transparai_setup" />
						<input type="hidden" name="step" value="writing" />
						<p><label><input type="checkbox" name="write_xmp" value="1" <?php checked( $options['write_xmp'], '1' ); ?> /> <?php esc_html_e( 'Write the machine-readable declaration into labeled image files (XMP, all sizes; merged into existing metadata, removable)', 'transparai' ); ?></label></p>
						<p><label><input type="checkbox" name="write_iim" value="1" <?php checked( $options['write_iim'], '1' ); ?> /> <?php esc_html_e( 'Mirror it into IPTC-IIM for JPEG files without another IPTC block', 'transparai' ); ?></label></p>
						<p><label><input type="checkbox" name="write_human" value="1" <?php checked( $options['write_human'], '1' ); ?> /> <?php esc_html_e( 'Also write digitalCapture or digitalCreation for media declared as not AI-made', 'transparai' ); ?></label></p>
						<p><label><input type="checkbox" name="auto_repair" value="1" <?php checked( $options['auto_repair'], '1' ); ?> /> <?php esc_html_e( 'Restore declarations that image optimizers strip (hourly sweep)', 'transparai' ); ?></label></p>
						<p>
							<button type="submit" class="trai-btn"><?php esc_html_e( 'Save file settings', 'transparai' ); ?></button>
							<?php
							if ( 'writing' === $done ) :
								?>
								<span class="trai-setup-done"><?php esc_html_e( 'Saved.', 'transparai' ); ?></span><?php endif; ?>
						</p>
					</form>
				</li>
			</ol>

			<p class="trai-actions trai-setup-footer">
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" class="trai-setup-inline">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="transparai_setup" />
					<input type="hidden" name="step" value="finish" />
					<button type="submit" class="trai-btn"><?php esc_html_e( 'Finish setup', 'transparai' ); ?></button>
				</form>
				<?php if ( array() !== $journal ) : ?>
					<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" class="trai-setup-inline">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="action" value="transparai_setup" />
						<input type="hidden" name="step" value="undo" />
						<button type="submit" class="trai-btn trai-btn--ghost">
							<?php
							$last = $journal[ count( $journal ) - 1 ];
							echo esc_html(
								sprintf(
									/* translators: %s: name of the setup step (badge or writing). */
									__( 'Undo last step (%s)', 'transparai' ),
									'badge' === $last['step'] ? __( 'badge look', 'transparai' ) : __( 'file settings', 'transparai' )
								)
							);
							?>
						</button>
					</form>
				<?php endif; ?>
				<?php if ( ! self::done() ) : ?>
					<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" class="trai-setup-inline">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="action" value="transparai_setup" />
						<input type="hidden" name="step" value="skip" />
						<button type="submit" class="button-link"><?php esc_html_e( 'Not now', 'transparai' ); ?></button>
					</form>
				<?php endif; ?>
			</p>
		</section>
		<?php
	}
}
