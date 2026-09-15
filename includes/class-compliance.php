<?php
/**
 * Compliance state: self-assessment, Article 4 literacy checklist, readiness
 * score, enforcement timeline and the site-log labels.
 *
 * Everything here is operator declaration plus counts the plugin can take
 * from its own data. The score is a technical self-check, never a legal
 * assessment, and the copy says so wherever it appears.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compliance core.
 */
final class TransparAI_Compliance {

	/** One option (autoload off): answers and checklist with who and when. */
	public const OPTION = 'transparai_compliance';

	/** Meta values that mean "AI was involved" (the legacy checkbox value included). */
	public const AI_VALUES = array( TransparAI_Meta::LEVEL_ASSISTED, TransparAI_Meta::LEVEL_GEN, TransparAI_Meta::LEVEL_REVIEWED, '1' );

	/**
	 * Register hooks. The widget and pages live in TransparAI_Dashboard; the
	 * core has nothing to hook on the front end.
	 */
	public static function init(): void {
		/* Nothing to register: pure state and calculations. */
	}

	/* ---------------------------------------------------------------------
	 * Definitions
	 * ------------------------------------------------------------------- */

	/**
	 * The six self-assessment questions. Answers are yes or no; a "sometimes"
	 * adds nothing to which obligations apply.
	 *
	 * @return array<string, array{text:string, hint:string, articles:string[], action:string, page:string}>
	 */
	public static function questions(): array {
		return array(
			'chatbot'         => array(
				'text'     => __( 'Does your site run a chatbot or virtual assistant that talks to visitors?', 'transparai' ),
				'hint'     => __( 'Examples: a support chat answered by an AI, an assistant widget, a chat plugin with an AI mode.', 'transparai' ),
				'articles' => array( 'Art. 50(1)' ),
				'action'   => __( 'Answer the chatbot question in the settings; the notice follows your answer.', 'transparai' ),
				'page'     => 'transparai-settings&tab=chatbot',
			),
			'ai_text'         => array(
				'text'     => __( 'Does your site publish text that was generated or substantially assisted by AI?', 'transparai' ),
				'hint'     => __( 'Examples: blog posts drafted with a language model, product descriptions, AI-assisted copy. Article 50(4) applies to text published to inform the public on matters of public interest; the AI level documents your practice either way.', 'transparai' ),
				'articles' => array( 'Art. 50(4)' ),
				'action'   => __( 'Set the AI level on each post (editor sidebar, Quick Edit or Bulk Edit) so the note appears.', 'transparai' ),
				'page'     => 'transparai-content',
			),
			'ai_images'       => array(
				'text'     => __( 'Does your site use AI-generated images or other AI-generated media?', 'transparai' ),
				'hint'     => __( 'Examples: images from ChatGPT, Gemini, Firefly, Midjourney or Stable Diffusion, AI-edited photos. Article 50(2) obliges the provider of the generator to mark such media; the plugin preserves that marking in your files and adds the visible disclosure Article 50(4) asks of deepfake deployers.', 'transparai' ),
				'articles' => array( 'Art. 50(2)', 'Art. 50(4)' ),
				'action'   => __( 'Scan the media library, confirm the review queue and keep the visible badge and file metadata on.', 'transparai' ),
				'page'     => 'transparai-images',
			),
			'personalisation' => array(
				'text'     => __( 'Does your site use AI for personalisation, recommendations or audience targeting?', 'transparai' ),
				'hint'     => __( 'Examples: product recommendations, personalised content, behavioural targeting.', 'transparai' ),
				'articles' => array( 'Art. 4' ),
				'action'   => __( 'Declare the tool on the AI Systems page and decide whether visitors are told about it.', 'transparai' ),
				'page'     => 'transparai-systems',
			),
			'translation'     => array(
				'text'     => __( 'Does your site use AI translation to serve content in other languages?', 'transparai' ),
				'hint'     => __( 'Examples: DeepL, machine-translated pages from a multilingual plugin, AI translation add-ons.', 'transparai' ),
				'articles' => array( 'Art. 4' ),
				'action'   => __( 'Declare the translation tool on the AI Systems page; consider a notice on translated pages.', 'transparai' ),
				'page'     => 'transparai-systems',
			),
			'synthetic_media' => array(
				'text'     => __( 'Does your site generate or host synthetic audio, video or deepfake-style content?', 'transparai' ),
				'hint'     => __( 'Examples: AI voice-overs, AI-generated video, cloned voices, digital avatars.', 'transparai' ),
				'articles' => array( 'Art. 50(2)', 'Art. 50(4)' ),
				'action'   => __( 'Label the media in the library (video and audio are detected too) and add an explicit note on affected pages.', 'transparai' ),
				'page'     => 'transparai-images',
			),
		);
	}

	/**
	 * Article 4 checklist items, in display order.
	 *
	 * @return array<string, string>
	 */
	public static function literacy_items(): array {
		return array(
			'staff_informed'     => __( 'Staff and contributors know which AI tools this site uses and what they are used for', 'transparai' ),
			'policy_documented'  => __( 'An internal policy on AI use (what is allowed, what needs review) is written down', 'transparai' ),
			'training_completed' => __( 'People who work with AI tools have had training or guidance on their capabilities and limits', 'transparai' ),
			'review_scheduled'   => __( 'A date is set to review AI tool usage, the policy and these declarations', 'transparai' ),
			'visitors_informed'  => __( 'Visitors are told about AI-generated content and AI systems on this site', 'transparai' ),
		);
	}

	/**
	 * Key dates of Regulation (EU) 2024/1689 as adopted (Article 113).
	 * Kept in one place so an amendment is a one-line change.
	 *
	 * @return array<int, array{date:string, title:string, text:string}>
	 */
	public static function milestones(): array {
		return array(
			array(
				'date'  => '2024-08-01',
				'title' => __( 'AI Act enters into force', 'transparai' ),
				'text'  => __( 'Regulation (EU) 2024/1689 is in force; its obligations start applying in stages.', 'transparai' ),
			),
			array(
				'date'  => '2025-02-02',
				'title' => __( 'General provisions and prohibited practices apply', 'transparai' ),
				'text'  => __( 'Chapters I and II: the AI literacy duty for providers and deployers (Article 4) and the ban on prohibited AI practices (Article 5).', 'transparai' ),
			),
			array(
				'date'  => '2025-08-02',
				'title' => __( 'General-purpose AI and governance rules apply', 'transparai' ),
				'text'  => __( 'Obligations for providers of general-purpose AI models, notifying authorities, governance and penalties (Chapters V, VII and XII).', 'transparai' ),
			),
			array(
				'date'  => '2026-08-02',
				'title' => __( 'General application, including Article 50', 'transparai' ),
				'text'  => __( 'Transparency obligations for chatbots, AI-generated content and deepfakes (Article 50) and most remaining provisions apply.', 'transparai' ),
			),
			array(
				'date'  => '2027-08-02',
				'title' => __( 'High-risk AI in regulated products', 'transparai' ),
				'text'  => __( 'Obligations for high-risk AI systems that are safety components of products covered by Annex I (Article 6(1)).', 'transparai' ),
			),
		);
	}

	/**
	 * Translated labels for site-log event ids. Unknown ids render as they are.
	 *
	 * @return array<string, string>
	 */
	public static function event_labels(): array {
		return array(
			'settings-saved'      => __( 'Settings saved', 'transparai' ),
			'scan-started'        => __( 'Library scan started', 'transparai' ),
			'scan-finished'       => __( 'Library scan finished', 'transparai' ),
			'sweep-finished'      => __( 'Auto-repair sweep finished', 'transparai' ),
			'bulk-flag'           => __( 'Bulk action: labeled as AI', 'transparai' ),
			'bulk-unflag'         => __( 'Bulk action: label removed', 'transparai' ),
			'bulk-confirm'        => __( 'Bulk action: review confirmed', 'transparai' ),
			'bulk-dismiss'        => __( 'Bulk action: review dismissed', 'transparai' ),
			'bulk-human_capture'  => __( 'Bulk action: declared as camera photo', 'transparai' ),
			'bulk-human_creation' => __( 'Bulk action: declared as human work', 'transparai' ),
			'bulk-human_remove'   => __( 'Bulk action: declaration removed', 'transparai' ),
			'setup-badge'         => __( 'Setup: badge look chosen', 'transparai' ),
			'setup-writing'       => __( 'Setup: file writing chosen', 'transparai' ),
			'setup-undo'          => __( 'Setup: last step undone', 'transparai' ),
			'setup-finished'      => __( 'Setup finished', 'transparai' ),
			'assessment-saved'    => __( 'Self-assessment saved', 'transparai' ),
			'literacy-saved'      => __( 'AI literacy checklist saved', 'transparai' ),
			'systems-scanned'     => __( 'AI systems scanned', 'transparai' ),
			'systems-declared'    => __( 'AI system declared', 'transparai' ),
			'systems-undeclared'  => __( 'AI system declaration removed', 'transparai' ),
			'systems-visibility'  => __( 'AI systems notice visibility changed', 'transparai' ),
		);
	}

	/**
	 * Label for one event id.
	 */
	public static function event_label( string $event ): string {
		return self::event_labels()[ $event ] ?? $event;
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------- */

	/**
	 * The stored state, normalised.
	 *
	 * @return array{assessment:array<string, string>, assessment_at:int, assessment_by:string, literacy:array<string, bool>, literacy_at:int, literacy_by:string}
	 */
	public static function state(): array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$answers = array();
		foreach ( array_keys( self::questions() ) as $id ) {
			$value          = (string) ( $raw['assessment'][ $id ] ?? '' );
			$answers[ $id ] = in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';
		}
		$literacy = array();
		foreach ( array_keys( self::literacy_items() ) as $id ) {
			$literacy[ $id ] = ! empty( $raw['literacy'][ $id ] );
		}
		return array(
			'assessment'    => $answers,
			'assessment_at' => (int) ( $raw['assessment_at'] ?? 0 ),
			'assessment_by' => (string) ( $raw['assessment_by'] ?? '' ),
			'literacy'      => $literacy,
			'literacy_at'   => (int) ( $raw['literacy_at'] ?? 0 ),
			'literacy_by'   => (string) ( $raw['literacy_by'] ?? '' ),
		);
	}

	/**
	 * Store the assessment answers (unknown ids and values are dropped).
	 *
	 * @param array<string, mixed> $raw id => yes|no.
	 */
	public static function save_assessment( array $raw ): void {
		$state   = self::state();
		$answers = array();
		foreach ( array_keys( self::questions() ) as $id ) {
			$value          = isset( $raw[ $id ] ) ? sanitize_key( (string) $raw[ $id ] ) : '';
			$answers[ $id ] = in_array( $value, array( 'yes', 'no' ), true ) ? $value : '';
		}
		$state['assessment']    = $answers;
		$state['assessment_at'] = time();
		$state['assessment_by'] = self::current_user_name();
		update_option( self::OPTION, $state, false );
		TransparAI_Meta::log_site( 'assessment-saved', array( 'answered' => count( array_filter( $answers ) ) ) );
	}

	/**
	 * Store the checklist (unknown ids are dropped, missing ids mean unchecked).
	 *
	 * @param array<string, mixed> $raw id => anything truthy.
	 */
	public static function save_literacy( array $raw ): void {
		$state    = self::state();
		$literacy = array();
		foreach ( array_keys( self::literacy_items() ) as $id ) {
			$literacy[ $id ] = ! empty( $raw[ $id ] );
		}
		$state['literacy']    = $literacy;
		$state['literacy_at'] = time();
		$state['literacy_by'] = self::current_user_name();
		update_option( self::OPTION, $state, false );
		TransparAI_Meta::log_site( 'literacy-saved', array( 'done' => count( array_filter( $literacy ) ) ) );
	}

	/**
	 * Answers keyed by question id ('' = unanswered).
	 *
	 * @return array<string, string>
	 */
	public static function assessment(): array {
		return self::state()['assessment'];
	}

	/**
	 * Whether every question has an answer.
	 */
	public static function assessment_complete(): bool {
		return ! in_array( '', self::assessment(), true );
	}

	/**
	 * Questions answered with yes, with their obligations and actions.
	 *
	 * @return array<string, array{text:string, hint:string, articles:string[], action:string, page:string}>
	 */
	public static function applicable(): array {
		$out = array();
		foreach ( self::assessment() as $id => $answer ) {
			if ( 'yes' === $answer ) {
				$out[ $id ] = self::questions()[ $id ];
			}
		}
		return $out;
	}

	/**
	 * Checklist state keyed by item id.
	 *
	 * @return array<string, bool>
	 */
	public static function literacy(): array {
		return self::state()['literacy'];
	}

	/**
	 * Number of ticked checklist items.
	 */
	public static function literacy_done_count(): int {
		return count( array_filter( self::literacy() ) );
	}

	/**
	 * Whether every checklist item is ticked.
	 */
	public static function literacy_complete(): bool {
		return self::literacy_done_count() === count( self::literacy_items() );
	}

	/* ---------------------------------------------------------------------
	 * Counts
	 * ------------------------------------------------------------------- */

	/**
	 * Public post types that carry an AI level (attachments excluded).
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = function_exists( 'get_post_types' ) ? (array) get_post_types( array( 'public' => true ) ) : array( 'post', 'page' );
		$types = array_values( array_map( 'strval', $types ) );
		return array_values( array_diff( $types, array( 'attachment' ) ) );
	}

	/**
	 * Number of posts per AI level (published, all public types), plus 'ai'
	 * for any level and 'none' for explicit "no AI" declarations.
	 *
	 * @return array<string, int>
	 */
	public static function content_counts(): array {
		$counts = array();
		foreach ( TransparAI_Meta::CONTENT_LEVELS as $level ) {
			$counts[ $level ] = self::count_level( array( $level ) );
		}
		$counts['ai'] = self::count_level( self::AI_VALUES );
		return $counts;
	}

	/**
	 * One found_posts query for a set of level values (self::AI_VALUES = any AI level).
	 *
	 * @param string[] $values Meta values.
	 */
	public static function count_level( array $values ): int {
		$query = new WP_Query(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the level is post meta by design; one indexed key.
					array(
						'key'     => TransparAI_Meta::KEY_CONTENT_AI,
						'value'   => $values,
						'compare' => 'IN',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Posts with an AI level for the report (capped).
	 *
	 * @return array<int, array{ID:int, title:string, type:string, level:string, reviewed_by:string, reviewed_on:string, review_current:bool}>
	 */
	public static function content_items( int $limit = TransparAI_Meta::REPORT_LIMIT ): array {
		$query = new WP_Query(
			array(
				'post_type'              => self::post_types(),
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => $limit,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the level is post meta by design; one indexed key.
					array(
						'key'     => TransparAI_Meta::KEY_CONTENT_AI,
						'value'   => self::AI_VALUES,
						'compare' => 'IN',
					),
				),
			)
		);
		$items = array();
		foreach ( (array) $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			$stamp   = TransparAI_Meta::content_review( $post_id );
			$items[] = array(
				'ID'             => $post_id,
				'title'          => (string) get_the_title( $post_id ),
				'type'           => (string) get_post_type( $post_id ),
				'level'          => TransparAI_Meta::get_content_level( $post_id ),
				'reviewed_by'    => null === $stamp ? '' : ( '' !== $stamp['responsible'] ? $stamp['responsible'] : $stamp['by'] ),
				'reviewed_on'    => null === $stamp ? '' : $stamp['on'],
				'review_current' => null === $stamp ? false : TransparAI_Meta::is_review_current( $post_id ),
			);
		}
		return $items;
	}

	/* ---------------------------------------------------------------------
	 * Score
	 * ------------------------------------------------------------------- */

	/**
	 * The readiness factors. Each one asks for a decision or an artefact,
	 * never for a switched-on setting alone.
	 *
	 * @return array<int, array{id:string, label:string, met:bool, page:string, action:string}>
	 */
	public static function factors(): array {
		$answers = self::assessment();
		$stats   = class_exists( 'TransparAI_Scanner' ) ? TransparAI_Scanner::stats() : array();
		$flagged = (int) ( $stats['flagged'] ?? 0 );
		$pending = (int) ( $stats['detected'] ?? 0 );

		$factors = array(
			array(
				'id'     => 'text',
				'label'  => __( 'AI-written text is declared: either the assessment says none is published, or at least one post carries an AI level', 'transparai' ),
				'met'    => 'no' === ( $answers['ai_text'] ?? '' ) || self::count_level( self::AI_VALUES ) > 0,
				'page'   => 'transparai-content',
				'action' => __( 'Set the AI level on your AI-written posts, or answer the text question with no.', 'transparai' ),
			),
			array(
				'id'     => 'images',
				'label'  => __( 'AI media is labeled: either the assessment says none is used, or the badge is on, at least one file is labeled and nothing waits in the review queue', 'transparai' ),
				'met'    => 'no' === ( $answers['ai_images'] ?? '' ) || ( TransparAI_Options::enabled( 'badge_enabled' ) && $flagged > 0 && 0 === $pending ),
				'page'   => 'transparai-images',
				'action' => __( 'Scan the library, work through the review queue and keep the visible badge on.', 'transparai' ),
			),
			array(
				'id'     => 'chatbot',
				'label'  => __( 'The chatbot question is answered (yes or no), so the chat notice follows a decision', 'transparai' ),
				'met'    => 'unknown' !== TransparAI_Options::get( 'chatbot_answer' ),
				'page'   => 'transparai-settings&tab=chatbot',
				'action' => __( 'Answer whether the site runs a chat and who answers in it.', 'transparai' ),
			),
			array(
				'id'     => 'article4',
				'label'  => __( 'Self-assessment and Article 4 checklist are complete', 'transparai' ),
				'met'    => self::assessment_complete() && self::literacy_complete(),
				'page'   => 'transparai-assessment',
				'action' => __( 'Answer the six questions and tick the checklist items that apply.', 'transparai' ),
			),
		);

		if ( class_exists( 'TransparAI_Systems' ) ) {
			$systems   = TransparAI_Systems::all();
			$visible   = TransparAI_Systems::visible();
			$factors[] = array(
				'id'     => 'systems',
				'label'  => __( 'AI systems are inventoried: the plugin list was scanned and every detected or declared system has a visibility decision', 'transparai' ),
				'met'    => TransparAI_Systems::scanned_at() > 0 && ( array() === $systems || ( TransparAI_Options::enabled( 'systems_notice' ) && array() !== $visible ) ),
				'page'   => 'transparai-systems',
				'action' => __( 'Run the scan on the AI Systems page and decide which systems visitors are told about.', 'transparai' ),
			);
		}

		return $factors;
	}

	/**
	 * Readiness score 0 to 100: share of factors met. Live, no cache.
	 */
	public static function score(): int {
		$factors = self::factors();
		if ( array() === $factors ) {
			return 0;
		}
		$met = count( array_filter( array_column( $factors, 'met' ) ) );
		return (int) round( $met / count( $factors ) * 100 );
	}

	/**
	 * Traffic-light bucket for a score.
	 */
	public static function traffic( int $score ): string {
		if ( $score >= 80 ) {
			return 'good';
		}
		return $score >= 50 ? 'attention' : 'action';
	}

	/**
	 * Translated label for a traffic-light bucket.
	 */
	public static function traffic_label( string $status ): string {
		$labels = array(
			'good'      => __( 'Good', 'transparai' ),
			'attention' => __( 'Needs attention', 'transparai' ),
		);
		return $labels[ $status ] ?? __( 'Action required', 'transparai' );
	}

	/* ---------------------------------------------------------------------
	 * Report
	 * ------------------------------------------------------------------- */

	/**
	 * The compliance part of the audit report.
	 *
	 * @return array<string, mixed>
	 */
	public static function summary(): array {
		$state   = self::state();
		$score   = self::score();
		$factors = array();
		foreach ( self::factors() as $factor ) {
			$factors[] = array(
				'id'    => $factor['id'],
				'label' => $factor['label'],
				'met'   => $factor['met'],
			);
		}
		$systems = array();
		if ( class_exists( 'TransparAI_Systems' ) ) {
			foreach ( TransparAI_Systems::all() as $system ) {
				$systems[] = array(
					'id'       => $system['id'],
					'name'     => $system['name'],
					'category' => $system['category'],
					'article'  => $system['article'],
					'source'   => $system['source'],
					'visible'  => $system['visible'],
				);
			}
		}
		return array(
			'score'      => $score,
			'traffic'    => self::traffic( $score ),
			'factors'    => $factors,
			'assessment' => array(
				'answers' => $state['assessment'],
				'at'      => $state['assessment_at'] > 0 ? gmdate( 'c', $state['assessment_at'] ) : '',
				'by'      => $state['assessment_by'],
			),
			'literacy'   => array(
				'items' => $state['literacy'],
				'done'  => self::literacy_done_count(),
				'total' => count( self::literacy_items() ),
				'at'    => $state['literacy_at'] > 0 ? gmdate( 'c', $state['literacy_at'] ) : '',
				'by'    => $state['literacy_by'],
			),
			'systems'    => $systems,
			'content'    => array(
				'counts' => self::content_counts(),
				'items'  => self::content_items(),
			),
			'notices'    => array(
				'content_notice_style'    => TransparAI_Options::get( 'content_notice_style' ),
				'content_notice_position' => TransparAI_Options::get( 'content_notice_position' ),
				'badge_enabled'           => TransparAI_Options::enabled( 'badge_enabled' ),
				'page_notice'             => TransparAI_Options::enabled( 'page_notice' ),
				'chatbot_answer'          => TransparAI_Options::get( 'chatbot_answer' ),
				'chatbot_active'          => class_exists( 'TransparAI_Chatbot' ) && TransparAI_Chatbot::active(),
				'systems_notice'          => TransparAI_Options::enabled( 'systems_notice' ),
				'schema_output'           => TransparAI_Options::enabled( 'schema_output' ),
			),
		);
	}

	/**
	 * Display name of the current user for the who/when stamps.
	 */
	private static function current_user_name(): string {
		$user = wp_get_current_user();
		return $user instanceof WP_User ? (string) $user->display_name : '';
	}
}
