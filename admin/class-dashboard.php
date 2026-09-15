<?php
/**
 * Top-level admin menu and the compliance screens: dashboard, assessment
 * (with the Article 4 checklist), AI systems, AI content, AI images. The
 * settings screen keeps its own class; this one only registers its menu entry.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu and compliance screens.
 */
final class TransparAI_Dashboard {

	public const MENU = 'transparai';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'wp_dashboard_setup', array( self::class, 'register_widget' ) );
		add_action( 'admin_post_transparai_assessment', array( self::class, 'handle_assessment' ) );
		add_action( 'admin_post_transparai_literacy', array( self::class, 'handle_literacy' ) );
		add_action( 'admin_post_transparai_systems', array( self::class, 'handle_systems' ) );
		add_action( 'admin_post_transparai_legal_ack', array( self::class, 'handle_legal_ack' ) );
	}

	/* ---------------------------------------------------------------------
	 * Menu
	 * ------------------------------------------------------------------- */

	/**
	 * Top-level menu with its screens; Media keeps a link to the images screen.
	 * The menu title must stay exactly "TransparAI": WordPress derives the
	 * page hook prefix (transparai_page_*) from it.
	 */
	public static function menu(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( TransparAI_Settings::logo_mark() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI for the menu icon, the documented way to ship an SVG icon.
		add_menu_page( 'TransparAI', 'TransparAI', 'manage_options', self::MENU, array( self::class, 'render_dashboard' ), $icon, 81 );

		$pages = array(
			self::MENU              => array( __( 'Dashboard', 'transparai' ), 'render_dashboard' ),
			'transparai-assessment' => array( __( 'Assessment', 'transparai' ), 'render_assessment' ),
			'transparai-systems'    => array( __( 'AI Systems', 'transparai' ), 'render_systems' ),
			'transparai-content'    => array( __( 'AI Content', 'transparai' ), 'render_content' ),
			'transparai-images'     => array( __( 'AI Images', 'transparai' ), 'render_images' ),
		);
		foreach ( $pages as $slug => $page ) {
			add_submenu_page( self::MENU, $page[0], $page[0], 'manage_options', $slug, array( self::class, $page[1] ) );
		}
		add_submenu_page( self::MENU, __( 'Settings', 'transparai' ), __( 'Settings', 'transparai' ), 'manage_options', TransparAI_Settings::PAGE, array( 'TransparAI_Settings', 'render' ) );

		/* Where the plugin lived up to 1.0.3: a link, so nobody has to relearn the path. */
		add_submenu_page( 'upload.php', 'TransparAI', 'TransparAI', 'manage_options', 'admin.php?page=transparai-images' );
	}

	/**
	 * Whether a page hook belongs to one of our screens.
	 */
	public static function is_own_hook( string $hook ): bool {
		return 'toplevel_page_' . self::MENU === $hook || str_starts_with( $hook, 'transparai_page_' );
	}

	/**
	 * Admin assets for the compliance screens. The images screen carries the
	 * scan loop and needs the shared admin script with its label set.
	 */
	public static function enqueue( string $hook ): void {
		if ( ! self::is_own_hook( $hook ) && 'index.php' !== $hook ) {
			return; /* index.php: the WordPress dashboard with our widget. */
		}
		wp_enqueue_style( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/css/admin.css', array(), TRANSPARAI_VERSION );
		if ( 'transparai_page_transparai-images' === $hook ) {
			wp_enqueue_script( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), TRANSPARAI_VERSION, true );
			TransparAI_Media_Library::localize_admin();
		}
	}

	/**
	 * URL of one of our screens.
	 */
	public static function url( string $page, string $extra = '' ): string {
		return admin_url( 'admin.php?page=' . $page . $extra );
	}

	/* ---------------------------------------------------------------------
	 * Shared pieces
	 * ------------------------------------------------------------------- */

	/**
	 * Open the wrapper, print the logo header and the "saved" notice.
	 */
	private static function open( string $title ): void {
		?>
		<div class="wrap trai-settings">
			<?php TransparAI_Settings::render_header(); ?>
			<h2 class="trai-page-title"><?php echo esc_html( $title ); ?></h2>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only success notice after an admin-post redirect. ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'transparai' ); ?></p></div>
			<?php endif; ?>
		<?php
	}

	public const USER_LEGAL_ACK = 'transparai_legal_ack';

	/**
	 * The one sentence every screen repeats: this is a tool, not legal advice.
	 */
	public static function disclaimer_text(): string {
		return __( 'TransparAI is a technical tool, not legal advice. It helps you detect, label and document AI use on this site; it cannot tell you whether your site complies with the EU AI Act or any other law. The readiness score, the self-assessment and every notice reflect the plugin\'s own checks and your answers, nothing more. Legal obligations depend on your situation and remain your responsibility; for a legal assessment consult a lawyer. The plugin is provided without warranty of any kind, and the author accepts no liability for its use or for decisions based on it.', 'transparai' );
	}

	/**
	 * Until the current user has acknowledged it once: a warning notice with
	 * an "I understand" button (WordPress moves it under the page title).
	 * Afterwards: the dark disclaimer card at the end of the screen, above
	 * the footer. One of the two is on every TransparAI screen; it never
	 * goes away.
	 */
	public static function render_disclaimer(): void {
		$acknowledged = '1' === (string) get_user_meta( get_current_user_id(), self::USER_LEGAL_ACK, true );
		if ( ! $acknowledged ) :
			?>
			<div class="notice notice-warning trai-legal-notice">
				<p><strong><?php esc_html_e( 'Please read before you rely on anything here.', 'transparai' ); ?></strong> <?php echo esc_html( self::disclaimer_text() ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'transparai_legal_ack' ); ?>
					<input type="hidden" name="action" value="transparai_legal_ack" />
					<p><button type="submit" class="trai-btn"><?php esc_html_e( 'I understand: no legal advice, no liability', 'transparai' ); ?></button></p>
				</form>
			</div>
			<?php
			return;
		endif;
		?>
		<section class="trai-card trai-disclaimer" role="note">
			<h2 class="trai-card-title"><?php esc_html_e( 'Not legal advice, no liability', 'transparai' ); ?></h2>
			<p><?php echo esc_html( self::disclaimer_text() ); ?></p>
		</section>
		<?php
	}

	/**
	 * Remember that this user has read the disclaimer.
	 */
	public static function handle_legal_ack(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transparai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'transparai_legal_ack' );
		update_user_meta( get_current_user_id(), self::USER_LEGAL_ACK, '1' );
		$referer = (string) wp_get_referer();
		wp_safe_redirect( '' !== $referer ? $referer : self::url( self::MENU ) ); /* Back to the screen the button was on. */
		exit;
	}

	/**
	 * The five steps in the order a new site works through them.
	 */
	private static function render_steps_card(): void {
		$steps = array(
			array( __( 'Scan the media library, then confirm or dismiss what lands in the review queue. Labeled files get the visible badge and the machine-readable marking.', 'transparai' ), 'transparai-images' ),
			array( __( 'Give AI-written posts and pages their AI level (editor sidebar, Quick Edit, Bulk Edit) so the visitor note appears.', 'transparai' ), 'transparai-content' ),
			array( __( 'Answer whether the site runs a chat and who answers in it; the chatbot notice follows your answer.', 'transparai' ), TransparAI_Settings::PAGE . '&tab=chatbot' ),
			array( __( 'Check the AI systems the plugin found among your plugins, declare others, and decide which ones visitors are told about.', 'transparai' ), 'transparai-systems' ),
			array( __( 'Answer the six questions of the self-assessment and tick the Article 4 checklist, then export the compliance report for your records.', 'transparai' ), 'transparai-assessment' ),
		);
		?>
		<section class="trai-card">
			<h2 class="trai-card-title"><?php esc_html_e( 'How TransparAI works', 'transparai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The EU AI Act asks for three things on a website: visitors must know when they talk to an AI, AI-generated media must carry a machine-readable marking, and AI-generated text on matters of public interest must be disclosed. This plugin covers the technical side of all three and documents what you decided. Work through the steps in this order; the readiness score above follows along.', 'transparai' ); ?></p>
			<ol class="trai-steps">
				<?php foreach ( $steps as $step ) : ?>
					<li><a href="<?php echo esc_url( self::url( $step[1] ) ); ?>"><?php echo esc_html( $step[0] ); ?></a></li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
	}

	/**
	 * Close the wrapper with the brand footer.
	 */
	private static function close(): void {
		self::render_disclaimer();
		TransparAI_Settings::render_footer();
		echo '</div>';
	}

	/**
	 * The traffic-light chip for a score.
	 */
	private static function traffic_chip( int $score ): string {
		$status = TransparAI_Compliance::traffic( $score );
		return '<span class="trai-traffic trai-traffic--' . esc_attr( $status ) . '">' . esc_html( TransparAI_Compliance::traffic_label( $status ) ) . '</span>';
	}

	/**
	 * Score card: number, chip, open and met factors with their links.
	 */
	private static function render_score_card(): void {
		$factors = TransparAI_Compliance::factors();
		$score   = TransparAI_Compliance::score();
		$met     = array();
		$open    = array();
		foreach ( $factors as $factor ) {
			if ( $factor['met'] ) {
				$met[] = $factor;
			} else {
				$open[] = $factor;
			}
		}
		?>
		<section class="trai-card trai-score-card">
			<h2 class="trai-card-title"><?php esc_html_e( 'Readiness score', 'transparai' ); ?></h2>
			<div class="trai-score">
				<span class="trai-score-number"><?php echo esc_html( (string) $score ); ?></span>
				<span class="trai-score-of">/ 100</span>
				<?php echo self::traffic_chip( $score ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in traffic_chip(). ?>
				<span class="trai-score-summary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: met checks, 2: total checks. */
						__( '%1$d of %2$d checks done', 'transparai' ),
						count( $met ),
						count( $factors )
					)
				);
				?>
				</span>
			</div>
			<p class="description"><?php esc_html_e( 'The score counts the checks this plugin can perform on its own state. It is a technical self-check, not a legal assessment.', 'transparai' ); ?></p>
			<?php if ( array() !== $open ) : ?>
				<h3 class="trai-subtitle"><?php esc_html_e( 'Open', 'transparai' ); ?></h3>
				<ul class="trai-factors">
					<?php foreach ( $open as $factor ) : ?>
						<li class="trai-factor trai-factor--open">
							<span class="trai-factor-state"><?php esc_html_e( 'Open', 'transparai' ); ?></span>
							<span class="trai-factor-text"><?php echo esc_html( $factor['label'] ); ?><br />
							<a href="<?php echo esc_url( self::url( $factor['page'] ) ); ?>"><?php echo esc_html( $factor['action'] ); ?></a></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( array() !== $met ) : ?>
				<h3 class="trai-subtitle"><?php esc_html_e( 'Done', 'transparai' ); ?></h3>
				<ul class="trai-factors">
					<?php foreach ( $met as $factor ) : ?>
						<li class="trai-factor trai-factor--met">
							<span class="trai-factor-state"><?php esc_html_e( 'Done', 'transparai' ); ?></span>
							<span class="trai-factor-text"><?php echo esc_html( $factor['label'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Milestones with "since" or "in" wording.
	 */
	private static function render_timeline_card(): void {
		$now = time();
		?>
		<section class="trai-card">
			<h2 class="trai-card-title"><?php esc_html_e( 'EU AI Act timeline', 'transparai' ); ?></h2>
			<ol class="trai-timeline">
				<?php foreach ( TransparAI_Compliance::milestones() as $milestone ) : ?>
					<?php
					$stamp = (int) strtotime( $milestone['date'] . ' 00:00:00 UTC' );
					$past  = $stamp <= $now;
					?>
					<li class="trai-milestone<?php echo $past ? ' trai-milestone--past' : ''; ?>">
						<span class="trai-milestone-date"><?php echo esc_html( date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $stamp ) ); ?></span>
						<span class="trai-milestone-body">
							<strong><?php echo esc_html( $milestone['title'] ); ?></strong>
							<span class="trai-milestone-when">
							<?php
							if ( $past ) {
								/* translators: %s: human time difference. */
								echo esc_html( sprintf( __( 'in force for %s', 'transparai' ), human_time_diff( $stamp, $now ) ) );
							} else {
								/* translators: %s: human time difference. */
								echo esc_html( sprintf( __( 'in %s', 'transparai' ), human_time_diff( $now, $stamp ) ) );
							}
							?>
							</span><br />
							<?php echo esc_html( $milestone['text'] ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
			<p class="description"><?php esc_html_e( 'Dates per Regulation (EU) 2024/1689, Article 113, as adopted. Amendments can move them.', 'transparai' ); ?></p>
		</section>
		<?php
	}

	/**
	 * The last site-log entries, newest first.
	 */
	private static function render_activity_card( int $limit = 15 ): void {
		$log = array_reverse( TransparAI_Meta::site_log() );
		$log = array_slice( $log, 0, $limit );
		?>
		<section class="trai-card">
			<h2 class="trai-card-title"><?php esc_html_e( 'Recent activity', 'transparai' ); ?></h2>
			<?php if ( array() === $log ) : ?>
				<p class="description"><?php esc_html_e( 'Nothing recorded yet. Scans, settings changes, bulk actions and declarations show up here.', 'transparai' ); ?></p>
			<?php else : ?>
				<table class="widefat striped trai-table">
					<thead><tr>
						<th><?php esc_html_e( 'When', 'transparai' ); ?></th>
						<th><?php esc_html_e( 'Event', 'transparai' ); ?></th>
						<th><?php esc_html_e( 'Details', 'transparai' ); ?></th>
						<th><?php esc_html_e( 'By', 'transparai' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( date_i18n( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ), $entry['t'] ) ); ?></td>
							<td><?php echo esc_html( TransparAI_Compliance::event_label( $entry['e'] ) ); ?></td>
							<td><?php echo esc_html( self::details_text( $entry['d'] ) ); ?></td>
							<td><?php echo esc_html( '' !== $entry['n'] ? $entry['n'] : ( $entry['u'] > 0 ? '#' . $entry['u'] : __( 'system', 'transparai' ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * "key: value, key: value" for small detail arrays.
	 *
	 * @param array<string, mixed> $details Details.
	 */
	private static function details_text( array $details ): string {
		$parts = array();
		foreach ( $details as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ' ', array_map( 'strval', $value ) );
			}
			$parts[] = $key . ': ' . (string) $value;
		}
		return implode( ', ', $parts );
	}

	/* ---------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------- */

	/**
	 * Dashboard: score, counts, quick links, timeline, activity.
	 */
	public static function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$stats   = TransparAI_Scanner::stats();
		$content = TransparAI_Compliance::count_level( TransparAI_Compliance::AI_VALUES );
		$systems = TransparAI_Systems::count();
		self::open( __( 'Dashboard', 'transparai' ) );
		if ( ! TransparAI_Setup::done() ) :
			?>
			<div class="notice notice-info"><p>
				<?php esc_html_e( 'The first-run setup is not finished yet.', 'transparai' ); ?>
				<a href="<?php echo esc_url( TransparAI_Setup::url() ); ?>"><?php esc_html_e( 'Open setup', 'transparai' ); ?></a>
			</p></div>
			<?php
		endif;
		self::render_score_card();
		self::render_steps_card();
		?>
		<section class="trai-card">
			<h2 class="trai-card-title"><?php esc_html_e( 'At a glance', 'transparai' ); ?></h2>
			<div class="trai-stats">
				<a class="trai-stat" href="<?php echo esc_url( self::url( 'transparai-content' ) ); ?>">
					<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $content ) ); ?></span>
					<span class="trai-stat-label"><?php esc_html_e( 'AI-written posts', 'transparai' ); ?></span>
				</a>
				<a class="trai-stat" href="<?php echo esc_url( self::url( 'transparai-images' ) ); ?>">
					<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $stats['flagged'] ) ); ?></span>
					<span class="trai-stat-label"><?php esc_html_e( 'Labeled media', 'transparai' ); ?></span>
				</a>
				<a class="trai-stat<?php echo $stats['detected'] > 0 ? ' trai-stat--action' : ''; ?>" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&transparai_filter=detected' ) ); ?>">
					<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $stats['detected'] ) ); ?></span>
					<span class="trai-stat-label"><?php esc_html_e( 'Waiting for review', 'transparai' ); ?></span>
				</a>
				<a class="trai-stat" href="<?php echo esc_url( self::url( 'transparai-systems' ) ); ?>">
					<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $systems ) ); ?></span>
					<span class="trai-stat-label"><?php esc_html_e( 'AI systems', 'transparai' ); ?></span>
				</a>
			</div>
			<p class="trai-actions">
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( self::url( 'transparai-assessment' ) ); ?>"><?php esc_html_e( 'Self-assessment', 'transparai' ); ?></a>
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( self::url( 'transparai-assessment', '#trai-literacy' ) ); ?>"><?php esc_html_e( 'AI literacy checklist', 'transparai' ); ?></a>
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=transparai_print&status=all' ), 'transparai_print' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Compliance report', 'transparai' ); ?></a>
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=transparai_export&status=all' ), 'transparai_export' ) ); ?>"><?php esc_html_e( 'Export audit CSV', 'transparai' ); ?></a>
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( self::url( TransparAI_Settings::PAGE ) ); ?>"><?php esc_html_e( 'Settings', 'transparai' ); ?></a>
			</p>
		</section>
		<?php
		self::render_timeline_card();
		self::render_activity_card();
		self::close();
	}

	/* ---------------------------------------------------------------------
	 * Assessment
	 * ------------------------------------------------------------------- */

	/**
	 * Six questions on one form, results below, the Article 4 checklist last.
	 */
	public static function render_assessment(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$answers = TransparAI_Compliance::assessment();
		$state   = TransparAI_Compliance::state();
		self::open( __( 'Self-assessment', 'transparai' ) );
		?>
		<section class="trai-card">
			<p><?php esc_html_e( 'Which transparency duties of the EU AI Act apply to a website depends on how it actually uses AI: a site without a chatbot has nothing to disclose about chatbots, a site that publishes AI-written articles does. Answer the six questions with Yes or No as things stand today; the examples under each question show what counts, and if you are unsure, ask the people who produce the content. The result below says for every confirmed use what the AI Act asks and of whom, and which plugin page handles it; complete answers count toward the readiness score and are printed in the compliance report.', 'transparai' ); ?></p>
			<p class="description"><?php esc_html_e( 'Stored on this site only, nothing is sent anywhere. Come back and update the answers whenever the site starts or stops using AI in one of these ways.', 'transparai' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'transparai_assessment' ); ?>
				<input type="hidden" name="action" value="transparai_assessment" />
				<?php foreach ( TransparAI_Compliance::questions() as $id => $question ) : ?>
					<fieldset class="trai-question">
						<legend><?php echo esc_html( $question['text'] ); ?></legend>
						<p class="description"><?php echo esc_html( $question['hint'] ); ?></p>
						<label><input type="radio" name="answers[<?php echo esc_attr( $id ); ?>]" value="yes" <?php checked( $answers[ $id ], 'yes' ); ?> /> <?php esc_html_e( 'Yes', 'transparai' ); ?></label>
						<label><input type="radio" name="answers[<?php echo esc_attr( $id ); ?>]" value="no" <?php checked( $answers[ $id ], 'no' ); ?> /> <?php esc_html_e( 'No', 'transparai' ); ?></label>
					</fieldset>
				<?php endforeach; ?>
				<p class="submit"><button type="submit" class="trai-btn"><?php esc_html_e( 'Save answers', 'transparai' ); ?></button></p>
			</form>
			<?php if ( $state['assessment_at'] > 0 ) : ?>
				<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: name, 2: date. */
						__( 'Last saved by %1$s on %2$s.', 'transparai' ),
						'' !== $state['assessment_by'] ? $state['assessment_by'] : __( 'unknown', 'transparai' ),
						date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $state['assessment_at'] )
					)
				);
				?>
				</p>
			<?php endif; ?>
		</section>
		<?php if ( TransparAI_Compliance::assessment_complete() ) : ?>
			<section class="trai-card">
				<h2 class="trai-card-title"><?php esc_html_e( 'Your to-do list', 'transparai' ); ?></h2>
				<?php $applicable = TransparAI_Compliance::applicable(); ?>
				<?php if ( array() === $applicable ) : ?>
					<p><?php esc_html_e( 'You answered every question with no. Keep the answers current when the site starts using AI; the Article 4 checklist below still documents your awareness.', 'transparai' ); ?></p>
				<?php else : ?>
					<table class="widefat striped trai-table">
						<thead><tr>
							<th><?php esc_html_e( 'Use of AI', 'transparai' ); ?></th>
							<th><?php esc_html_e( 'What the AI Act says', 'transparai' ); ?></th>
							<th><?php esc_html_e( 'Recommended action', 'transparai' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $applicable as $question ) : ?>
							<tr>
								<td><?php echo esc_html( $question['label'] ); ?></td>
								<td><?php echo esc_html( $question['duty'] ); ?></td>
								<td><a href="<?php echo esc_url( self::url( $question['page'] ) ); ?>"><?php echo esc_html( $question['action'] ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Provider is whoever built or trained the AI system, deployer is whoever uses it under their own authority; a website operator is usually the deployer. This overview is a technical aid, not legal advice.', 'transparai' ); ?></p>
			</section>
		<?php else : ?>
			<section class="trai-card">
				<h2 class="trai-card-title"><?php esc_html_e( 'Your to-do list', 'transparai' ); ?></h2>
				<p class="description"><?php esc_html_e( 'This overview appears once all six questions are answered and saved. For every use you confirmed it says what the AI Act asks and of whom, and which plugin page handles it.', 'transparai' ); ?></p>
			</section>
		<?php endif; ?>
		<?php
		self::render_literacy_card();
		self::close();
	}

	/**
	 * Save the assessment.
	 */
	public static function handle_assessment(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transparai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'transparai_assessment' );
		$raw = isset( $_POST['answers'] ) && is_array( $_POST['answers'] ) ? (array) wp_unslash( $_POST['answers'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is whitelisted in save_assessment().
		TransparAI_Compliance::save_assessment( $raw );
		wp_safe_redirect( self::url( 'transparai-assessment', '&saved=1' ) );
		exit;
	}

	/**
	 * Article 4 checklist: its own form on the assessment screen. Article 4
	 * applies regardless of the six answers, so it is not part of that form.
	 */
	private static function render_literacy_card(): void {
		$done  = TransparAI_Compliance::literacy();
		$state = TransparAI_Compliance::state();
		$items = TransparAI_Compliance::literacy_items();
		?>
		<section class="trai-card" id="trai-literacy">
			<h2 class="trai-card-title"><?php esc_html_e( 'AI literacy (Article 4)', 'transparai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Article 4 applies since 2 February 2025 to every provider and deployer, whatever you answered above: you take measures, as far as you can, so that the people who operate or use AI systems on your behalf have a sufficient level of AI literacy, considering their background and the context of use. The Act prescribes no particular training or certificate; this checklist records the measures that usually serve as evidence. It counts toward the readiness score and appears in the compliance report with the name and date of the last save.', 'transparai' ); ?></p>
			<p class="trai-progress-label">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: done, 2: total. */
					__( '%1$d of %2$d done', 'transparai' ),
					TransparAI_Compliance::literacy_done_count(),
					count( $items )
				)
			);
			?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'transparai_literacy' ); ?>
				<input type="hidden" name="action" value="transparai_literacy" />
				<ul class="trai-checklist">
					<?php foreach ( $items as $id => $label ) : ?>
						<li><label><input type="checkbox" name="items[<?php echo esc_attr( $id ); ?>]" value="1" <?php checked( $done[ $id ] ); ?> /> <?php echo esc_html( $label ); ?></label></li>
					<?php endforeach; ?>
				</ul>
				<p class="submit"><button type="submit" class="trai-btn"><?php esc_html_e( 'Save checklist', 'transparai' ); ?></button></p>
			</form>
			<?php if ( $state['literacy_at'] > 0 ) : ?>
				<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: name, 2: date. */
						__( 'Last saved by %1$s on %2$s.', 'transparai' ),
						'' !== $state['literacy_by'] ? $state['literacy_by'] : __( 'unknown', 'transparai' ),
						date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $state['literacy_at'] )
					)
				);
				?>
				</p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Both parts of this screen end up in the compliance report, the document you keep for your records or hand to whoever asks.', 'transparai' ); ?></p>
			<p class="trai-actions">
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=transparai_print&status=all' ), 'transparai_print' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Compliance report', 'transparai' ); ?></a>
			</p>
		</section>
		<?php
	}

	/**
	 * Save the checklist.
	 */
	public static function handle_literacy(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transparai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'transparai_literacy' );
		$raw = isset( $_POST['items'] ) && is_array( $_POST['items'] ) ? (array) wp_unslash( $_POST['items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys are whitelisted, values only tested for truthiness in save_literacy().
		TransparAI_Compliance::save_literacy( $raw );
		wp_safe_redirect( self::url( 'transparai-assessment', '&saved=1#trai-literacy' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * AI systems
	 * ------------------------------------------------------------------- */

	/**
	 * Inventory, visibility, suggestions and manual declarations.
	 */
	public static function render_systems(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		TransparAI_Systems::maybe_scan();
		$systems    = TransparAI_Systems::all();
		$labels     = TransparAI_Systems::category_labels();
		$scanned_at = TransparAI_Systems::scanned_at();
		$action     = esc_url( admin_url( 'admin-post.php' ) );
		self::open( __( 'AI systems in use', 'transparai' ) );
		?>
		<section class="trai-card">
			<p class="description"><?php esc_html_e( 'Installed plugins are matched against a list of known AI tools that ships with this plugin; chatbots an AI answers in are included. Nothing is fetched. Tick the systems visitors should be told about; the notice itself is switched on under Settings, AI systems.', 'transparai' ); ?></p>
			<p class="description">
			<?php
			if ( $scanned_at > 0 ) {
				/* translators: %s: human time difference. */
				echo esc_html( sprintf( __( 'Last scan %s ago.', 'transparai' ), human_time_diff( $scanned_at ) ) );
			}
			if ( ! TransparAI_Options::enabled( 'systems_notice' ) ) {
				echo ' ';
				esc_html_e( 'The visitor notice is currently off.', 'transparai' );
			}
			?>
			</p>
			<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>">
				<?php wp_nonce_field( 'transparai_systems' ); ?>
				<input type="hidden" name="action" value="transparai_systems" />
				<?php if ( array() === $systems ) : ?>
					<p><?php esc_html_e( 'No AI system detected or declared yet.', 'transparai' ); ?></p>
				<?php else : ?>
					<table class="widefat striped trai-table">
						<thead><tr>
							<th><?php esc_html_e( 'Visible', 'transparai' ); ?></th>
							<th><?php esc_html_e( 'System', 'transparai' ); ?></th>
							<th><?php esc_html_e( 'Category', 'transparai' ); ?></th>
							<th><?php esc_html_e( 'Articles', 'transparai' ); ?></th>
							<th><?php esc_html_e( 'Found via', 'transparai' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $systems as $id => $system ) : ?>
							<tr>
								<td><input type="checkbox" name="visible[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $system['visible'] ); ?> aria-label="<?php echo esc_attr( $system['name'] ); ?>" /></td>
								<td>
									<?php if ( '' !== $system['url'] ) : ?>
										<a href="<?php echo esc_url( $system['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $system['name'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $system['name'] ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $labels[ $system['category'] ] ?? $system['category'] ); ?></td>
								<td><?php echo esc_html( $system['article'] ); ?></td>
								<td><?php echo esc_html( $system['evidence'] ); ?></td>
								<td>
									<?php if ( 'manual' === $system['source'] ) : ?>
										<button type="submit" class="trai-btn trai-btn--ghost" name="do" value="undeclare:<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Remove', 'transparai' ); ?></button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<p class="trai-actions">
					<?php if ( array() !== $systems ) : ?>
						<button type="submit" class="trai-btn" name="do" value="visibility"><?php esc_html_e( 'Save visibility', 'transparai' ); ?></button>
					<?php endif; ?>
					<button type="submit" class="trai-btn trai-btn--ghost" name="do" value="rescan"><?php esc_html_e( 'Scan installed plugins again', 'transparai' ); ?></button>
				</p>
			</form>
		</section>

		<?php $suggestions = TransparAI_Systems::possibly_ai(); ?>
		<section class="trai-card">
			<h2 class="trai-card-title"><?php esc_html_e( 'Possibly AI', 'transparai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Active plugins the list does not know whose name or description mentions AI. Suggestions only: declare what really is an AI system, ignore the rest.', 'transparai' ); ?></p>
			<?php if ( array() === $suggestions ) : ?>
				<p><?php esc_html_e( 'No further candidates among the active plugins.', 'transparai' ); ?></p>
			<?php else : ?>
				<table class="widefat striped trai-table">
					<thead><tr>
						<th><?php esc_html_e( 'Plugin', 'transparai' ); ?></th>
						<th><?php esc_html_e( 'Category', 'transparai' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $suggestions as $suggestion ) : ?>
						<tr>
							<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>">
								<?php wp_nonce_field( 'transparai_systems' ); ?>
								<input type="hidden" name="action" value="transparai_systems" />
								<input type="hidden" name="name" value="<?php echo esc_attr( $suggestion['name'] ); ?>" />
								<input type="hidden" name="slug" value="<?php echo esc_attr( $suggestion['slug'] ); ?>" />
								<td><?php echo esc_html( $suggestion['name'] ); ?> <code><?php echo esc_html( $suggestion['slug'] ); ?></code></td>
								<td><select name="category">
									<?php foreach ( $labels as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select></td>
								<td><button type="submit" class="trai-btn trai-btn--ghost" name="do" value="declare"><?php esc_html_e( 'Declare as AI system', 'transparai' ); ?></button></td>
							</form>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<section class="trai-card">
			<h2 class="trai-card-title"><?php esc_html_e( 'Declare another system', 'transparai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'For tools that are not WordPress plugins: an external service, a script, an API your theme calls.', 'transparai' ); ?></p>
			<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" class="trai-inline-form">
				<?php wp_nonce_field( 'transparai_systems' ); ?>
				<input type="hidden" name="action" value="transparai_systems" />
				<input type="text" name="name" class="regular-text" required maxlength="100" placeholder="<?php esc_attr_e( 'Name of the system', 'transparai' ); ?>" />
				<select name="category">
					<?php foreach ( $labels as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="trai-btn" name="do" value="declare"><?php esc_html_e( 'Declare', 'transparai' ); ?></button>
			</form>
		</section>
		<?php
		self::close();
	}

	/**
	 * Systems actions: rescan, visibility, declare, undeclare.
	 */
	public static function handle_systems(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transparai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'transparai_systems' );
		$do = isset( $_POST['do'] ) ? sanitize_text_field( wp_unslash( $_POST['do'] ) ) : '';

		if ( 'rescan' === $do ) {
			TransparAI_Systems::scan();
		} elseif ( 'visibility' === $do ) {
			$ids = isset( $_POST['visible'] ) && is_array( $_POST['visible'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['visible'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized element-wise via array_map.
			TransparAI_Systems::set_visible( $ids );
		} elseif ( 'declare' === $do ) {
			$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
			$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : 'other';
			$slug     = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			TransparAI_Systems::declare( $name, $category, $slug );
		} elseif ( str_starts_with( $do, 'undeclare:' ) ) {
			TransparAI_Systems::undeclare( substr( $do, strlen( 'undeclare:' ) ) );
		}
		wp_safe_redirect( self::url( 'transparai-systems', '&saved=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * AI content and AI images
	 * ------------------------------------------------------------------- */

	/**
	 * Post counts per level with deep links into the post lists.
	 */
	public static function render_content(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$counts = TransparAI_Compliance::content_counts();
		$labels = TransparAI_Notice::level_labels();
		$types  = TransparAI_Compliance::post_types();
		self::open( __( 'AI-written content', 'transparai' ) );
		?>
		<section class="trai-card">
			<p class="description"><?php esc_html_e( 'Every post, page and public custom post type carries an AI level for its text. The level is set in the editor sidebar, in Quick Edit or for many posts at once in Bulk Edit; the lists below are the regular post lists filtered by level, with the same Quick Edit and Bulk Edit tools.', 'transparai' ); ?></p>
			<div class="trai-stats">
				<?php foreach ( array( TransparAI_Meta::LEVEL_ASSISTED, TransparAI_Meta::LEVEL_GEN, TransparAI_Meta::LEVEL_REVIEWED, TransparAI_Meta::LEVEL_NONE ) as $level ) : ?>
					<div class="trai-stat">
						<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $counts[ $level ] ?? 0 ) ); ?></span>
						<span class="trai-stat-label"><?php echo esc_html( $labels[ $level ] ?? $level ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<table class="widefat striped trai-table">
				<thead><tr>
					<th><?php esc_html_e( 'Post type', 'transparai' ); ?></th>
					<th><?php esc_html_e( 'Open list', 'transparai' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $types as $type ) : ?>
					<?php
					$object = get_post_type_object( $type );
					$name   = $object && isset( $object->labels->name ) ? (string) $object->labels->name : $type;
					$base   = admin_url( 'edit.php?post_type=' . rawurlencode( $type ) );
					?>
					<tr>
						<td><?php echo esc_html( $name ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( 'transparai_level', 'ai', $base ) ); ?>"><?php esc_html_e( 'Any AI involvement', 'transparai' ); ?></a> |
							<a href="<?php echo esc_url( add_query_arg( 'transparai_level', TransparAI_Meta::LEVEL_GEN, $base ) ); ?>"><?php echo esc_html( $labels[ TransparAI_Meta::LEVEL_GEN ] ); ?></a> |
							<a href="<?php echo esc_url( add_query_arg( 'transparai_level', TransparAI_Meta::LEVEL_REVIEWED, $base ) ); ?>"><?php echo esc_html( $labels[ TransparAI_Meta::LEVEL_REVIEWED ] ); ?></a> |
							<a href="<?php echo esc_url( add_query_arg( 'transparai_level', TransparAI_Meta::LEVEL_ASSISTED, $base ) ); ?>"><?php echo esc_html( $labels[ TransparAI_Meta::LEVEL_ASSISTED ] ); ?></a> |
							<a href="<?php echo esc_url( add_query_arg( 'transparai_level', 'unclassified', $base ) ); ?>"><?php esc_html_e( 'Not classified', 'transparai' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'To unmark many posts at once, select them in the list, choose Bulk Edit and set the AI level to "No AI used" or clear it. The note wording, position and style live under Settings, AI-written text.', 'transparai' ); ?></p>
			<p class="trai-actions">
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( self::url( TransparAI_Settings::PAGE, '&tab=text' ) ); ?>"><?php esc_html_e( 'Text settings', 'transparai' ); ?></a>
			</p>
		</section>
		<?php
		self::close();
	}

	/**
	 * Library counters, scan and the media list filters.
	 */
	public static function render_images(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$dismissed = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed key.
					array(
						'key'   => TransparAI_Meta::KEY_DISMISSED,
						'value' => '1',
					),
				),
			)
		);
		self::open( __( 'AI images and media', 'transparai' ) );
		TransparAI_Settings::render_status_card();
		TransparAI_Settings::render_scan_card();
		?>
		<section class="trai-card">
			<h2 class="trai-card-title"><?php esc_html_e( 'Media lists', 'transparai' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The media library list view carries the AI column, the filter and the bulk actions (label, unlabel, confirm, dismiss, declare as camera photo or human work).', 'transparai' ); ?></p>
			<p class="trai-actions">
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&transparai_filter=1' ) ); ?>"><?php esc_html_e( 'Labeled as AI', 'transparai' ); ?></a>
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&transparai_filter=detected' ) ); ?>"><?php esc_html_e( 'Review queue', 'transparai' ); ?></a>
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&transparai_filter=human' ) ); ?>"><?php esc_html_e( 'Declared as not AI', 'transparai' ); ?></a>
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( admin_url( 'upload.php?mode=list&transparai_filter=0' ) ); ?>"><?php esc_html_e( 'Unlabeled', 'transparai' ); ?></a>
				<a class="trai-btn trai-btn--ghost" href="<?php echo esc_url( self::url( TransparAI_Settings::PAGE, '&tab=badge' ) ); ?>"><?php esc_html_e( 'Badge settings', 'transparai' ); ?></a>
			</p>
			<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of dismissed files. */
					_n( '%d file was dismissed from the review queue and stays unlabeled until you decide otherwise.', '%d files were dismissed from the review queue and stay unlabeled until you decide otherwise.', (int) $dismissed->found_posts, 'transparai' ),
					(int) $dismissed->found_posts
				)
			);
			?>
			</p>
		</section>
		<?php
		self::close();
	}

	/* ---------------------------------------------------------------------
	 * WordPress dashboard widgets
	 * ------------------------------------------------------------------- */

	/**
	 * Two widgets for administrators, side by side on the WordPress dashboard
	 * and deliberately not overlapping: "Readiness" is the state of the
	 * compliance checks (score, what is still open, the next deadline),
	 * "Numbers" is the inventory (media, texts, systems, last scan, activity).
	 */
	public static function register_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget( 'transparai_readiness', __( 'TransparAI: Readiness', 'transparai' ), array( self::class, 'render_widget' ), null, null, 'normal', 'high' );
		wp_add_dashboard_widget( 'transparai_numbers', __( 'TransparAI: Numbers', 'transparai' ), array( self::class, 'render_numbers_widget' ), null, null, 'side', 'high' );
	}

	/**
	 * Readiness widget: score with traffic light, the open checks with their
	 * next step, the next EU AI Act milestone, links to the compliance screens.
	 */
	public static function render_widget(): void {
		$factors = TransparAI_Compliance::factors();
		$score   = TransparAI_Compliance::score();
		$open    = array();
		foreach ( $factors as $factor ) {
			if ( ! $factor['met'] ) {
				$open[] = $factor;
			}
		}
		$next = self::next_milestone();
		?>
		<div class="trai-widget">
			<p class="trai-widget-score">
				<span class="trai-score-number"><?php echo esc_html( (string) $score ); ?></span>
				<span class="trai-score-of">/ 100</span>
				<?php echo self::traffic_chip( $score ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in traffic_chip(). ?>
				<span class="trai-score-summary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: met checks, 2: total checks. */
						__( '%1$d of %2$d checks done', 'transparai' ),
						count( $factors ) - count( $open ),
						count( $factors )
					)
				);
				?>
				</span>
			</p>
			<?php if ( array() === $open ) : ?>
				<p class="trai-widget-allclear"><?php esc_html_e( 'Every check the plugin can perform is done. Keep the answers current when the site starts or stops using AI.', 'transparai' ); ?></p>
			<?php else : ?>
				<p class="trai-widget-heading"><?php esc_html_e( 'Still open', 'transparai' ); ?></p>
				<ul class="trai-factors trai-widget-factors">
					<?php foreach ( $open as $factor ) : ?>
						<li class="trai-factor trai-factor--open">
							<span class="trai-factor-state"><?php esc_html_e( 'Open', 'transparai' ); ?></span>
							<a class="trai-factor-text" href="<?php echo esc_url( self::url( $factor['page'] ) ); ?>"><?php echo esc_html( $factor['action'] ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( null !== $next ) : ?>
				<p class="trai-widget-milestone">
					<span class="trai-widget-heading"><?php echo esc_html( $next['past'] ? __( 'In force', 'transparai' ) : __( 'Next deadline', 'transparai' ) ); ?></span>
					<strong><?php echo esc_html( $next['title'] ); ?></strong>
					<span class="trai-widget-muted"><?php echo esc_html( $next['when'] ); ?></span>
				</p>
			<?php endif; ?>
			<p class="trai-widget-links">
				<a href="<?php echo esc_url( self::url( self::MENU ) ); ?>"><?php esc_html_e( 'Dashboard', 'transparai' ); ?></a>
				<a href="<?php echo esc_url( self::url( 'transparai-assessment' ) ); ?>"><?php esc_html_e( 'Self-assessment', 'transparai' ); ?></a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=transparai_print&status=all' ), 'transparai_print' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Compliance report', 'transparai' ); ?></a>
			</p>
			<p class="trai-widget-disclaimer"><?php esc_html_e( 'Technical self-check of the plugin state. Not legal advice, no liability.', 'transparai' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Numbers widget: the inventory behind the score. Every number links to
	 * the screen where it can be changed.
	 */
	public static function render_numbers_widget(): void {
		$stats     = TransparAI_Scanner::stats();
		$counts    = TransparAI_Compliance::content_counts();
		$systems   = TransparAI_Systems::count();
		$visible   = count( TransparAI_Systems::visible() );
		$unscanned = max( 0, (int) $stats['total'] - (int) $stats['scanned'] );
		$last_scan = self::last_event_time( 'scan-finished' );
		$log       = array_slice( array_reverse( TransparAI_Meta::site_log() ), 0, 3 );
		$tiles     = array(
			array( (int) $stats['flagged'], __( 'Labeled as AI', 'transparai' ), self::url( 'transparai-images' ), false ),
			array( (int) $stats['detected'], __( 'Waiting for review', 'transparai' ), admin_url( 'upload.php?mode=list&transparai_filter=detected' ), $stats['detected'] > 0 ),
			array( (int) ( $stats['human'] ?? 0 ), __( 'Declared human-made', 'transparai' ), admin_url( 'upload.php?mode=list&transparai_filter=human' ), false ),
			array( $unscanned, __( 'Not scanned yet', 'transparai' ), self::url( TransparAI_Settings::PAGE, '&tab=detection' ), $unscanned > 0 ),
			array( (int) $counts['ai'], __( 'AI-written posts', 'transparai' ), self::url( 'transparai-content' ), false ),
			array( $systems, __( 'AI systems', 'transparai' ), self::url( 'transparai-systems' ), false ),
		);
		?>
		<div class="trai-widget">
			<div class="trai-stats trai-widget-stats">
				<?php foreach ( $tiles as $tile ) : ?>
					<a class="trai-stat<?php echo $tile[3] ? ' trai-stat--action' : ''; ?>" href="<?php echo esc_url( $tile[2] ); ?>">
						<span class="trai-stat-number"><?php echo esc_html( number_format_i18n( $tile[0] ) ); ?></span>
						<span class="trai-stat-label"><?php echo esc_html( $tile[1] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<ul class="trai-widget-facts">
				<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: scanned files, 2: files in the library. */
						__( 'Library: %1$s of %2$s files scanned.', 'transparai' ),
						number_format_i18n( (int) $stats['scanned'] ),
						number_format_i18n( (int) $stats['total'] )
					)
				);
				echo ' ';
				if ( $last_scan > 0 ) {
					/* translators: %s: human time difference. */
					echo esc_html( sprintf( __( 'Last scan finished %s ago.', 'transparai' ), human_time_diff( $last_scan ) ) );
				} else {
					esc_html_e( 'No scan has finished yet.', 'transparai' );
				}
				?>
				</li>
				<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: AI-assisted posts, 2: AI-generated posts, 3: AI-generated and reviewed posts. */
						__( 'Texts: %1$s AI-assisted, %2$s AI-generated, %3$s generated and reviewed.', 'transparai' ),
						number_format_i18n( (int) $counts[ TransparAI_Meta::LEVEL_ASSISTED ] ),
						number_format_i18n( (int) $counts[ TransparAI_Meta::LEVEL_GEN ] ),
						number_format_i18n( (int) $counts[ TransparAI_Meta::LEVEL_REVIEWED ] )
					)
				);
				?>
				</li>
				<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: systems visitors are told about, 2: inventoried systems. */
						__( 'Systems: %1$s of %2$s shown to visitors.', 'transparai' ),
						number_format_i18n( $visible ),
						number_format_i18n( $systems )
					)
				);
				?>
				</li>
			</ul>
			<?php if ( array() !== $log ) : ?>
				<p class="trai-widget-heading"><?php esc_html_e( 'Recent activity', 'transparai' ); ?></p>
				<ul class="trai-widget-log">
					<?php foreach ( $log as $entry ) : ?>
						<li>
							<span class="trai-widget-muted"><?php echo esc_html( date_i18n( (string) get_option( 'date_format', 'Y-m-d' ) . ' ' . (string) get_option( 'time_format', 'H:i' ), $entry['t'] ) ); ?></span>
							<?php echo esc_html( TransparAI_Compliance::event_label( $entry['e'] ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="trai-widget-links">
				<a href="<?php echo esc_url( self::url( 'transparai-images' ) ); ?>"><?php esc_html_e( 'AI Images', 'transparai' ); ?></a>
				<a href="<?php echo esc_url( self::url( 'transparai-content' ) ); ?>"><?php esc_html_e( 'AI Content', 'transparai' ); ?></a>
				<a href="<?php echo esc_url( self::url( 'transparai-systems' ) ); ?>"><?php esc_html_e( 'AI Systems', 'transparai' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * The milestone that matters now: the next one still ahead, or the last
	 * one once all are in force. Null only if the list is empty.
	 *
	 * @return array{title:string, when:string, past:bool}|null
	 */
	public static function next_milestone(): ?array {
		$now  = time();
		$pick = null;
		foreach ( TransparAI_Compliance::milestones() as $milestone ) {
			$stamp = (int) strtotime( $milestone['date'] . ' 00:00:00 UTC' );
			$pick  = array(
				'title' => $milestone['title'],
				'stamp' => $stamp,
				'past'  => $stamp <= $now,
			);
			if ( $stamp > $now ) {
				break;
			}
		}
		if ( null === $pick ) {
			return null;
		}
		$date = date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $pick['stamp'] );
		$diff = human_time_diff( $pick['stamp'], $now );
		if ( $pick['past'] ) {
			/* translators: 1: date, 2: human time difference. */
			$when = sprintf( __( 'since %1$s (%2$s)', 'transparai' ), $date, $diff );
		} else {
			/* translators: 1: date, 2: human time difference. */
			$when = sprintf( __( '%1$s (in %2$s)', 'transparai' ), $date, $diff );
		}
		return array(
			'title' => $pick['title'],
			'past'  => $pick['past'],
			'when'  => $when,
		);
	}

	/**
	 * Timestamp of the newest site-log entry with this event id, 0 if none.
	 */
	public static function last_event_time( string $event ): int {
		foreach ( array_reverse( TransparAI_Meta::site_log() ) as $entry ) {
			if ( $event === $entry['e'] ) {
				return $entry['t'];
			}
		}
		return 0;
	}
}
