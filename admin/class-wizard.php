<?php
/**
 * Setup assistant: a guided first run in seven steps, one screen each,
 * every step saved on the server before the next one opens. It writes the
 * same options and answers the settings, assessment and images screens
 * write, so nothing it sets is hidden from the regular screens later.
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
 * Setup assistant.
 */
final class TransparAI_Wizard {

	public const PAGE   = 'transparai-setup';
	private const NONCE = 'transparai_wizard';

	/* Start of the Article 50 obligations; the badge start date offered in step 4. */
	private const AI_ACT_DATE = '2026-08-02';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_post_transparai_wizard', array( self::class, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * The assistant sits in the menu until the setup is finished; afterwards
	 * it stays reachable from the dashboard and the settings page.
	 */
	public static function menu(): void {
		add_submenu_page(
			TransparAI_Setup::done() ? '' : TransparAI_Dashboard::MENU,
			__( 'Setup assistant', 'transparai' ),
			__( 'Setup', 'transparai' ),
			'manage_options',
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * URL of a step.
	 */
	public static function url( string $step = '' ): string {
		return admin_url( 'admin.php?page=' . self::PAGE . ( '' !== $step ? '&step=' . $step : '' ) );
	}

	/**
	 * The steps in order, id => title.
	 *
	 * @return array<string, string>
	 */
	public static function steps(): array {
		return array(
			'welcome'    => __( 'Start', 'transparai' ),
			'assessment' => __( 'Your site', 'transparai' ),
			'images'     => __( 'Images', 'transparai' ),
			'badge'      => __( 'Visible label', 'transparai' ),
			'files'      => __( 'Files', 'transparai' ),
			'text'       => __( 'Text and chat', 'transparai' ),
			'done'       => __( 'Done', 'transparai' ),
		);
	}

	/**
	 * The step after the given one ('' after the last).
	 */
	public static function next_step( string $step ): string {
		$ids = array_keys( self::steps() );
		$at  = array_search( $step, $ids, true );
		return false === $at || ! isset( $ids[ $at + 1 ] ) ? '' : $ids[ $at + 1 ];
	}

	/**
	 * The step before the given one ('' before the first).
	 */
	public static function previous_step( string $step ): string {
		$ids = array_keys( self::steps() );
		$at  = array_search( $step, $ids, true );
		return false === $at || 0 === $at ? '' : $ids[ $at - 1 ];
	}

	/**
	 * Assets: the admin styles, the front-end styles for the badge preview,
	 * and the admin script with its scan loop and live preview.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! str_ends_with( $hook, '_page_' . self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/css/admin.css', array(), TRANSPARAI_VERSION );
		wp_enqueue_style( 'transparai-front', TRANSPARAI_PLUGIN_URL . 'assets/css/front.css', array(), TRANSPARAI_VERSION );
		wp_enqueue_script( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), TRANSPARAI_VERSION, true );
		TransparAI_Media_Library::localize_admin();
	}

	/* ---------------------------------------------------------------------
	 * Saving
	 * ------------------------------------------------------------------- */

	/**
	 * admin-post handler: save the step, then open the next one.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'transparai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( (string) $_POST['step'] ) ) : '';
		$raw  = isset( $_POST['wizard'] ) && is_array( $_POST['wizard'] ) ? map_deep( wp_unslash( $_POST['wizard'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by map_deep() right here.

		if ( isset( $_POST['skip_assistant'] ) ) {
			update_user_meta( get_current_user_id(), TransparAI_Setup::USER_SKIPPED, '1' );
			wp_safe_redirect( TransparAI_Dashboard::url( TransparAI_Dashboard::MENU ) );
			exit;
		}

		self::save_step( $step, $raw );

		if ( 'done' === $step ) {
			update_option( TransparAI_Setup::OPT_DONE, time(), false );
			TransparAI_Meta::log_site( 'setup-finished' );
			wp_safe_redirect( TransparAI_Dashboard::url( TransparAI_Dashboard::MENU, '&saved=1' ) );
			exit;
		}
		$next = self::next_step( $step );
		wp_safe_redirect( self::url( '' !== $next ? $next : 'done' ) );
		exit;
	}

	/**
	 * Store what one step collected. Unknown keys never reach the options:
	 * every value passes the same sanitizer as the settings form.
	 *
	 * @param string               $step Step id.
	 * @param array<string, mixed> $raw  Submitted wizard fields.
	 */
	public static function save_step( string $step, array $raw ): void {
		switch ( $step ) {
			case 'assessment':
				TransparAI_Compliance::save_assessment( (array) ( $raw['assessment'] ?? array() ) );
				return;

			case 'images':
				$review = 'review' === ( $raw['detection'] ?? '' );
				self::save_options(
					array(
						'autodetect'   => '1',
						'mode_certain' => $review ? 'queue' : 'flag',
						'mode_likely'  => 'queue',
					)
				);
				return;

			case 'badge':
				self::save_options(
					array(
						'badge_enabled'   => empty( $raw['badge_enabled'] ) ? '0' : '1',
						'badge_style'     => (string) ( $raw['badge_style'] ?? '' ),
						'badge_position'  => (string) ( $raw['badge_position'] ?? '' ),
						'badge_mode'      => (string) ( $raw['badge_mode'] ?? '' ),
						'badge_size'      => (string) ( $raw['badge_size'] ?? '' ),
						'badge_from_date' => empty( $raw['from_ai_act'] ) ? '' : self::AI_ACT_DATE,
					)
				);
				return;

			case 'files':
				self::save_options(
					array(
						'write_xmp'     => empty( $raw['write_xmp'] ) ? '0' : '1',
						'write_iim'     => empty( $raw['write_iim'] ) ? '0' : '1',
						'auto_repair'   => empty( $raw['auto_repair'] ) ? '0' : '1',
						'schema_output' => empty( $raw['schema_output'] ) ? '0' : '1',
					)
				);
				return;

			case 'text':
				$values = array();
				if ( isset( $raw['content_notice_position'] ) ) {
					$values['content_notice_position'] = (string) $raw['content_notice_position'];
				}
				if ( isset( $raw['chatbot_staffing'] ) ) {
					$values['chatbot_answer']   = 'yes';
					$values['chatbot_staffing'] = (string) $raw['chatbot_staffing'];
				} elseif ( 'no' === ( TransparAI_Compliance::assessment()['chatbot'] ?? '' ) ) {
					$values['chatbot_answer'] = 'no';
				}
				if ( array() !== $values ) {
					self::save_options( $values );
				}
				return;
		}
	}

	/**
	 * Merge values into the settings and store them through the sanitizer.
	 *
	 * @param array<string, string> $values Option key => value.
	 */
	private static function save_options( array $values ): void {
		update_option( TransparAI_Options::OPTION, TransparAI_Options::sanitize( array_merge( TransparAI_Options::all(), $values ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------- */

	/**
	 * Render the assistant.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$steps = self::steps();
		$step  = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( (string) $_GET['step'] ) ) : 'welcome'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only step switch.
		if ( ! isset( $steps[ $step ] ) ) {
			$step = 'welcome';
		}
		$options = TransparAI_Options::all();
		$index   = (int) array_search( $step, array_keys( $steps ), true );
		?>
		<div class="wrap trai-settings trai-wizard">
			<?php TransparAI_Settings::render_header(); ?>
			<h2 class="trai-page-title"><?php esc_html_e( 'Setup assistant', 'transparai' ); ?></h2>

			<ol class="trai-wizard-steps" aria-label="<?php esc_attr_e( 'Setup steps', 'transparai' ); ?>">
				<?php foreach ( array_values( $steps ) as $i => $title ) : ?>
					<li class="<?php echo esc_attr( $i < $index ? 'is-done' : ( $i === $index ? 'is-current' : '' ) ); ?>"<?php echo $i === $index ? ' aria-current="step"' : ''; ?>>
						<span class="trai-wizard-num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
						<span class="trai-wizard-label"><?php echo esc_html( $title ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<section class="trai-card trai-wizard-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="transparai_wizard" />
					<input type="hidden" name="step" value="<?php echo esc_attr( $step ); ?>" />
					<?php
					switch ( $step ) {
						case 'assessment':
							self::step_assessment();
							break;
						case 'images':
							self::step_images( $options );
							break;
						case 'badge':
							self::step_badge( $options );
							break;
						case 'files':
							self::step_files( $options );
							break;
						case 'text':
							self::step_text( $options );
							break;
						case 'done':
							self::step_done( $options );
							break;
						default:
							self::step_welcome();
					}
					self::nav( $step );
					?>
				</form>
			</section>
			<?php TransparAI_Settings::render_footer(); ?>
		</div>
		<?php
	}

	/**
	 * Back link, primary button and the way out.
	 */
	private static function nav( string $step ): void {
		$previous = self::previous_step( $step );
		$label    = 'done' === $step ? __( 'Finish setup', 'transparai' ) : ( 'welcome' === $step ? __( 'Start', 'transparai' ) : __( 'Save and continue', 'transparai' ) );
		?>
		<p class="trai-actions trai-wizard-nav">
			<?php if ( '' !== $previous ) : ?>
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( self::url( $previous ) ); ?>"><?php esc_html_e( 'Back', 'transparai' ); ?></a>
			<?php endif; ?>
			<button type="submit" class="trai-btn"><?php echo esc_html( $label ); ?></button>
			<?php if ( 'done' !== $step ) : ?>
				<button type="submit" name="skip_assistant" value="1" class="button-link trai-wizard-skip"><?php esc_html_e( 'Not now, I will set it up myself', 'transparai' ); ?></button>
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * Step 1.
	 */
	private static function step_welcome(): void {
		?>
		<h3><?php esc_html_e( 'Welcome to TransparAI', 'transparai' ); ?></h3>
		<p><?php esc_html_e( 'In six short steps the assistant sets up what the EU AI Act asks of a website that uses AI: it finds the AI images already in your media library, adds a visible label where one is due, keeps the machine-readable marking in your files and, if your site has them, sets up the notes for AI-written text and for a chatbot.', 'transparai' ); ?></p>
		<ul class="trai-wizard-facts">
			<li><?php esc_html_e( 'Everything runs on your server. No account, no telemetry, no request to an outside service.', 'transparai' ); ?></li>
			<li><?php esc_html_e( 'Every choice can be changed later in the settings; nothing here is final.', 'transparai' ); ?></li>
			<li><?php esc_html_e( 'The plugin is a technical tool and gives no legal advice. Whether a duty applies to your site is your decision.', 'transparai' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Step 2: the six assessment questions.
	 */
	private static function step_assessment(): void {
		$answers = TransparAI_Compliance::assessment();
		?>
		<h3><?php esc_html_e( 'What does your site use?', 'transparai' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Six questions decide which of the following steps matter for you. The answers also appear in the self-assessment and in the compliance report.', 'transparai' ); ?></p>
		<?php foreach ( TransparAI_Compliance::questions() as $id => $question ) : ?>
			<fieldset class="trai-wizard-question">
				<legend><strong><?php echo esc_html( $question['text'] ); ?></strong></legend>
				<p class="description"><?php echo esc_html( $question['hint'] ); ?></p>
				<label><input type="radio" name="wizard[assessment][<?php echo esc_attr( $id ); ?>]" value="yes" <?php checked( 'yes', $answers[ $id ] ?? '' ); ?> /> <?php esc_html_e( 'Yes', 'transparai' ); ?></label>
				<label><input type="radio" name="wizard[assessment][<?php echo esc_attr( $id ); ?>]" value="no" <?php checked( 'no', $answers[ $id ] ?? '' ); ?> /> <?php esc_html_e( 'No', 'transparai' ); ?></label>
			</fieldset>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Step 3: detection mode and the library scan.
	 *
	 * @param array<string, string> $options Current options.
	 */
	private static function step_images( array $options ): void {
		$review = 'queue' === $options['mode_certain'];
		?>
		<h3><?php esc_html_e( 'Find the AI images in your library', 'transparai' ); ?></h3>
		<p><?php esc_html_e( 'The plugin reads the provenance data AI generators leave in their files: Content Credentials (C2PA, signature checked), the IPTC digital source type and the generation parameters of local tools. It never guesses from image size, file size or file name.', 'transparai' ); ?></p>
		<fieldset class="trai-wizard-question">
			<legend><strong><?php esc_html_e( 'What should happen with a clear AI declaration?', 'transparai' ); ?></strong></legend>
			<label><input type="radio" name="wizard[detection]" value="auto" <?php checked( ! $review ); ?> /> <?php esc_html_e( 'Label it right away; only uncertain findings wait in the review queue (recommended)', 'transparai' ); ?></label><br />
			<label><input type="radio" name="wizard[detection]" value="review" <?php checked( $review ); ?> /> <?php esc_html_e( 'Put every finding into the review queue; I confirm each one myself', 'transparai' ); ?></label>
		</fieldset>
		<?php
		TransparAI_Settings::render_scan_card( false );
		?>
		<p class="description"><?php esc_html_e( 'The scan runs in small batches while this page is open and can be paused; you can also continue and scan later from the AI Images screen. New uploads are checked automatically from now on.', 'transparai' ); ?></p>
		<?php
	}

	/**
	 * Step 4: the visible badge with a live preview.
	 *
	 * @param array<string, string> $options Current options.
	 */
	private static function step_badge( array $options ): void {
		$selects = array(
			'badge_style'    => array(
				__( 'Look', 'transparai' ),
				array(
					'eu-icon'   => __( 'Official EU icon (Commission, June 2026)', 'transparai' ),
					'dark'      => __( 'Dark', 'transparai' ),
					'light'     => __( 'Light', 'transparai' ),
					'outline'   => __( 'Outline', 'transparai' ),
					'icon-only' => __( 'Icon only (short label)', 'transparai' ),
				),
			),
			'badge_position' => array(
				__( 'Position', 'transparai' ),
				array(
					'bottom-right' => __( 'Bottom right', 'transparai' ),
					'bottom-left'  => __( 'Bottom left', 'transparai' ),
					'top-right'    => __( 'Top right', 'transparai' ),
					'top-left'     => __( 'Top left', 'transparai' ),
				),
			),
			'badge_mode'     => array(
				__( 'Placement', 'transparai' ),
				array(
					'overlay' => __( 'Overlay on the image', 'transparai' ),
					'caption' => __( 'Caption line below the image', 'transparai' ),
				),
			),
			'badge_size'     => array(
				__( 'Size', 'transparai' ),
				array(
					'small'  => __( 'Small', 'transparai' ),
					'medium' => __( 'Medium', 'transparai' ),
					'large'  => __( 'Large', 'transparai' ),
				),
			),
		);
		?>
		<h3><?php esc_html_e( 'How should the visible label look?', 'transparai' ); ?></h3>
		<p><?php esc_html_e( 'The EU AI Act asks for a label that is noticeable at the first look, on or right next to the image. The badge appears on every labeled image, in the content, in featured images, in page builders and in WooCommerce.', 'transparai' ); ?></p>
		<?php TransparAI_Setup::preview( $options ); ?>
		<div class="trai-wizard-grid">
			<?php foreach ( $selects as $key => $select ) : ?>
				<label class="trai-badge-field">
					<span><?php echo esc_html( $select[0] ); ?></span>
					<select name="wizard[<?php echo esc_attr( $key ); ?>]">
						<?php foreach ( $select[1] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $options[ $key ], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endforeach; ?>
		</div>
		<p>
			<label><input type="checkbox" name="wizard[badge_enabled]" value="1" <?php checked( '1', $options['badge_enabled'] ); ?> /> <?php esc_html_e( 'Show the visible badge', 'transparai' ); ?></label><br />
			<label><input type="checkbox" name="wizard[from_ai_act]" value="1" <?php checked( self::AI_ACT_DATE, $options['badge_from_date'] ); ?> /> <?php esc_html_e( 'Only for media uploaded since 2 August 2026, when Article 50 started to apply (older content is not covered retroactively; the file metadata is written either way)', 'transparai' ); ?></label>
		</p>
		<?php
	}

	/**
	 * Step 5: what goes into the files.
	 *
	 * @param array<string, string> $options Current options.
	 */
	private static function step_files( array $options ): void {
		$toggles = array(
			'write_xmp'     => __( 'Write the IPTC digital source type into labeled files (XMP, every image size). Search engines and other tools read it, and it travels with the image.', 'transparai' ),
			'write_iim'     => __( 'Also mirror it into the classic IPTC block of JPEG files, for older tools.', 'transparai' ),
			'auto_repair'   => __( 'Restore the declaration when an image optimizer or a regenerated thumbnail strips it (hourly check).', 'transparai' ),
			'schema_output' => __( 'Add structured data (Schema.org digitalSourceType) for labeled media to the page, for search engines and AI answer engines.', 'transparai' ),
		);
		?>
		<h3><?php esc_html_e( 'Machine-readable marking', 'transparai' ); ?></h3>
		<p><?php esc_html_e( 'The marking in the file is what lets other systems recognise AI content without looking at your page. Existing metadata is kept; removing a label removes exactly what the plugin wrote.', 'transparai' ); ?></p>
		<?php foreach ( $toggles as $key => $label ) : ?>
			<p><label><input type="checkbox" name="wizard[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( '1', $options[ $key ] ); ?> /> <?php echo esc_html( $label ); ?></label></p>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Step 6: only what the assessment made relevant.
	 *
	 * @param array<string, string> $options Current options.
	 */
	private static function step_text( array $options ): void {
		$answers = TransparAI_Compliance::assessment();
		$text    = 'yes' === ( $answers['ai_text'] ?? '' );
		$chat    = 'yes' === ( $answers['chatbot'] ?? '' );
		?>
		<h3><?php esc_html_e( 'AI-written text and chatbots', 'transparai' ); ?></h3>
		<?php if ( ! $text && ! $chat ) : ?>
			<p><?php esc_html_e( 'According to your answers your site publishes no AI-written text and runs no chatbot, so there is nothing to set up here. Both can be switched on later in the settings.', 'transparai' ); ?></p>
		<?php endif; ?>

		<?php if ( $text ) : ?>
			<fieldset class="trai-wizard-question">
				<legend><strong><?php esc_html_e( 'Where should the note on AI-written posts appear?', 'transparai' ); ?></strong></legend>
				<p class="description"><?php esc_html_e( 'Each post gets an AI level (no AI, assisted, generated, generated and reviewed) in the editor; the note follows that level.', 'transparai' ); ?></p>
				<?php
				foreach ( array(
					'before' => __( 'Before the text', 'transparai' ),
					'after'  => __( 'After the text', 'transparai' ),
					'both'   => __( 'Before and after', 'transparai' ),
					'manual' => __( 'Only where I place the block or shortcode', 'transparai' ),
				) as $value => $label ) :
					?>
					<label><input type="radio" name="wizard[content_notice_position]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $options['content_notice_position'], $value ); ?> /> <?php echo esc_html( $label ); ?></label><br />
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>

		<?php if ( $chat ) : ?>
			<fieldset class="trai-wizard-question">
				<legend><strong><?php esc_html_e( 'Who answers in your chat?', 'transparai' ); ?></strong></legend>
				<p class="description"><?php esc_html_e( 'Visitors must know when they talk to an AI. The notice appears in the chat or next to it, depending on the chat plugin.', 'transparai' ); ?></p>
				<?php
				foreach ( array(
					'ai'    => __( 'An AI answers', 'transparai' ),
					'mixed' => __( 'An AI first, a person takes over when needed', 'transparai' ),
					'human' => __( 'Only people answer (no AI notice)', 'transparai' ),
				) as $value => $label ) :
					?>
					<label><input type="radio" name="wizard[chatbot_staffing]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $options['chatbot_staffing'], $value ); ?> /> <?php echo esc_html( $label ); ?></label><br />
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>
		<?php
	}

	/**
	 * Step 7: what was set, the score and what is left.
	 *
	 * @param array<string, string> $options Current options.
	 */
	private static function step_done( array $options ): void {
		$stats      = TransparAI_Scanner::stats();
		$applicable = TransparAI_Compliance::applicable();
		$score      = TransparAI_Compliance::score();
		$styles     = array(
			'eu-icon'   => __( 'Official EU icon', 'transparai' ),
			'dark'      => __( 'Dark', 'transparai' ),
			'light'     => __( 'Light', 'transparai' ),
			'outline'   => __( 'Outline', 'transparai' ),
			'icon-only' => __( 'Icon only', 'transparai' ),
		);
		?>
		<h3><?php esc_html_e( 'Your setup', 'transparai' ); ?></h3>
		<ul class="trai-wizard-facts">
			<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of scanned media files, 2: number of media files. */
						__( 'Media library: %1$d of %2$d files scanned.', 'transparai' ),
						(int) $stats['scanned'],
						(int) $stats['total']
					)
				);
				?>
			</li>
			<li>
				<?php
				echo esc_html(
					'1' === $options['badge_enabled']
						/* translators: %s: badge style name. */
						? sprintf( __( 'Visible badge: on, style "%s".', 'transparai' ), $styles[ $options['badge_style'] ] ?? $options['badge_style'] )
						: __( 'Visible badge: off.', 'transparai' )
				);
				?>
			</li>
			<li><?php echo esc_html( '1' === $options['write_xmp'] ? __( 'File metadata: written into labeled files.', 'transparai' ) : __( 'File metadata: not written.', 'transparai' ) ); ?></li>
			<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: readiness score from 0 to 100. */
						__( 'Readiness score: %d of 100.', 'transparai' ),
						$score
					)
				);
				?>
			</li>
		</ul>

		<?php if ( array() !== $applicable ) : ?>
			<h3><?php esc_html_e( 'What your answers make relevant', 'transparai' ); ?></h3>
			<ul class="trai-wizard-todo">
				<?php foreach ( $applicable as $item ) : ?>
					<li>
						<strong><?php echo esc_html( $item['label'] ); ?>:</strong>
						<?php echo esc_html( $item['action'] ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $item['page'] ) ); ?>"><?php esc_html_e( 'Open', 'transparai' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'The dashboard keeps the score and the open steps in view. This assistant stays available from the dashboard and the settings.', 'transparai' ); ?></p>
		<?php
	}
}
