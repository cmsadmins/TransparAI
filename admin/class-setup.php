<?php
/**
 * First-run setup: one card on the settings page that names the three things
 * worth doing once and points at the section that does each of them. The
 * controls themselves live in the settings tabs below, so no option is
 * offered twice on the same screen.
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
 * Setup card.
 */
final class TransparAI_Setup {

	public const OPT_DONE     = 'transparai_setup_done';
	public const REDIRECT     = 'transparai_activation_redirect';
	public const USER_SKIPPED = 'transparai_setup_skipped';
	private const NONCE       = 'transparai_setup';

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

	/**
	 * admin-post handler. The card itself never writes a setting: every
	 * option it talks about is saved by the settings form below it, so the
	 * only things to handle are finishing the setup and hiding the card.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'transparai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( (string) $_POST['step'] ) ) : '';
		if ( 'finish' === $step ) {
			update_option( self::OPT_DONE, time(), false );
			TransparAI_Meta::log_site( 'setup-finished' );
		} elseif ( 'skip' === $step ) {
			update_user_meta( get_current_user_id(), self::USER_SKIPPED, '1' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=transparai-settings' ) );
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
		$stats  = TransparAI_Scanner::stats();
		$action = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<section class="trai-card trai-setup">
			<h2 class="trai-card-title"><?php esc_html_e( 'Setup in three steps', 'transparai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Three things to settle once. Each button opens the section below that does it. Nothing is labeled without your review, and no request leaves your server.', 'transparai' ); ?></p>

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
					<p><?php esc_html_e( 'Style, position and placement of the visible badge, with a live preview of the result.', 'transparai' ); ?></p>
					<p><button type="button" class="trai-btn trai-btn--ghost trai-setup-jump" data-tab="badge"><?php esc_html_e( 'Open the badge settings', 'transparai' ); ?></button></p>
				</li>

				<li class="trai-setup-step">
					<h3><?php esc_html_e( '3. Decide about the files', 'transparai' ); ?></h3>
					<p><?php esc_html_e( 'Whether the machine-readable declaration goes into the image files themselves, and whether it is restored after an image optimizer strips it.', 'transparai' ); ?></p>
					<p><button type="button" class="trai-btn trai-btn--ghost trai-setup-jump" data-tab="files"><?php esc_html_e( 'Open the file settings', 'transparai' ); ?></button></p>
				</li>
			</ol>

			<p class="trai-actions trai-setup-footer">
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" class="trai-setup-inline">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="transparai_setup" />
					<input type="hidden" name="step" value="finish" />
					<button type="submit" class="trai-btn"><?php esc_html_e( 'Finish setup', 'transparai' ); ?></button>
				</form>
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
