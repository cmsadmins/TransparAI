<?php
/**
 * Per-post disclosure of AI-written text in the admin: the editor control
 * (classic meta box or block editor panel), the list column with filter and
 * sorting, and Quick Edit and Bulk Edit for the level.
 *
 * The front-end output lives in TransparAI_Notice.
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
 * Editor and list integration for the text disclosure level.
 */
final class TransparAI_Content_Label {

	private const NONCE    = 'transparai_content_label';
	private const QE_NONCE = 'transparai_content_qe';
	private const COLUMN   = 'transparai';
	private const FIELD    = 'transparai_content_level';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_box' ) );
		add_action( 'save_post', array( self::class, 'save' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_panel' ) );

		/* Lists: registered on admin_init, when every custom post type exists. */
		add_action( 'admin_init', array( self::class, 'register_columns' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_list_assets' ) );
		add_action( 'quick_edit_custom_box', array( self::class, 'quick_edit_box' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( self::class, 'bulk_edit_box' ), 10, 2 );
		add_action( 'bulk_edit_posts', array( self::class, 'save_bulk' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_query' ) );
		add_filter( 'posts_clauses', array( self::class, 'sort_clauses' ), 10, 2 );
	}

	/**
	 * Public post types except attachments.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/* ---------------------------------------------------------------------
	 * Editor
	 * ------------------------------------------------------------------- */

	/**
	 * Side meta box. Where the block editor can save meta over REST (post
	 * types with custom-fields support) the sidebar panel takes over and the
	 * box is hidden there; otherwise the box is the block editor's fallback.
	 */
	public static function add_box(): void {
		foreach ( self::post_types() as $type ) {
			add_meta_box(
				'transparai-content',
				__( 'TransparAI', 'transparai' ),
				array( self::class, 'render' ),
				$type,
				'side',
				'default',
				post_type_supports( $type, 'custom-fields' ) ? array( '__back_compat_meta_box' => true ) : null
			);
		}
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, 'transparai_content_nonce' );
		$level = TransparAI_Meta::get_content_level( $post->ID );
		if ( '' === $level && 'auto-draft' === $post->post_status ) {
			/* Site default for new posts only; existing posts stay visibly unclassified. */
			$level = TransparAI_Options::get( 'content_default_level' );
		}
		$responsible = (string) get_post_meta( $post->ID, TransparAI_Meta::KEY_CONTENT_RESPONSIBLE, true );
		$stamp       = TransparAI_Meta::content_review( $post->ID );
		?>
		<p>
			<label for="trai-content-level"><strong><?php esc_html_e( 'AI in this text', 'transparai' ); ?></strong></label><br />
			<select id="trai-content-level" name="<?php echo esc_attr( self::FIELD ); ?>" class="widefat">
				<?php foreach ( TransparAI_Notice::level_labels() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $level, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="trai-content-responsible"><?php esc_html_e( 'Responsible person', 'transparai' ); ?></label><br />
			<input type="text" id="trai-content-responsible" class="widefat" name="transparai_content_responsible" value="<?php echo esc_attr( $responsible ); ?>" placeholder="<?php echo esc_attr( TransparAI_Options::get( 'content_responsible' ) ); ?>" />
		</p>
		<?php if ( null !== $stamp ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: reviewer name, 2: review date. */
						__( 'Reviewed by %1$s on %2$s.', 'transparai' ),
						'' !== $stamp['by'] ? $stamp['by'] : $stamp['responsible'],
						$stamp['on']
					)
				);
				if ( ! TransparAI_Meta::is_review_current( $post->ID ) ) {
					echo ' ' . esc_html__( 'The text or its images changed since; save with the reviewed level again after checking.', 'transparai' );
				}
				?>
			</p>
		<?php endif; ?>
		<p class="description"><?php esc_html_e( 'AI levels show a note on the post. The wording, position, excerpt and feed options are in the TransparAI settings.', 'transparai' ); ?></p>
		<?php
	}

	/**
	 * Block editor: document sidebar panel, only where the post type can
	 * save meta through REST.
	 */
	public static function enqueue_panel(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = $screen instanceof WP_Screen ? (string) $screen->post_type : '';
		if ( '' === $type || ! in_array( $type, self::post_types(), true ) || ! post_type_supports( $type, 'custom-fields' ) ) {
			return;
		}
		wp_enqueue_script(
			'transparai-content-panel',
			TRANSPARAI_PLUGIN_URL . 'assets/js/content-panel.js',
			array( 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data' ),
			TRANSPARAI_VERSION,
			true
		);
		$levels = array();
		foreach ( TransparAI_Notice::level_labels() as $value => $label ) {
			$levels[] = array(
				'value' => $value,
				'label' => $label,
			);
		}
		wp_localize_script(
			'transparai-content-panel',
			'transparaiContent',
			array(
				'keyLevel'       => TransparAI_Meta::KEY_CONTENT_AI,
				'keyResponsible' => TransparAI_Meta::KEY_CONTENT_RESPONSIBLE,
				'keyReview'      => TransparAI_Meta::KEY_CONTENT_REVIEW,
				'reviewedLevel'  => TransparAI_Meta::LEVEL_REVIEWED,
				'defaultLevel'   => TransparAI_Options::get( 'content_default_level' ),
				'levels'         => $levels,
				'title'          => __( 'TransparAI', 'transparai' ),
				'level'          => __( 'AI in this text', 'transparai' ),
				'responsible'    => __( 'Responsible person', 'transparai' ),
				'placeholder'    => TransparAI_Options::get( 'content_responsible' ),
				/* translators: 1: reviewer name, 2: review date. */
				'reviewed'       => __( 'Reviewed by %1$s on %2$s.', 'transparai' ),
				'help'           => __( 'AI levels show a note on the post. Wording and placement are set in the TransparAI settings.', 'transparai' ),
			)
		);
	}

	/**
	 * Persist the meta box (classic editor) or the Quick Edit field.
	 *
	 * @param int|mixed $post_id Post ID.
	 */
	public static function save( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['transparai_content_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( (string) $_POST['transparai_content_nonce'] ) ), self::NONCE ) ) {
			$responsible = isset( $_POST['transparai_content_responsible'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['transparai_content_responsible'] ) ) : '';
			if ( '' === $responsible ) {
				delete_post_meta( $post_id, TransparAI_Meta::KEY_CONTENT_RESPONSIBLE );
			} else {
				update_post_meta( $post_id, TransparAI_Meta::KEY_CONTENT_RESPONSIBLE, $responsible );
			}
			self::apply_level( $post_id );
			return;
		}

		/* Quick Edit posts through admin-ajax with its own nonce; only the level is offered there. */
		if ( isset( $_POST['transparai_content_qe_nonce'] )
			&& wp_verify_nonce( sanitize_key( wp_unslash( (string) $_POST['transparai_content_qe_nonce'] ) ), self::QE_NONCE ) ) {
			self::apply_level( $post_id );
		}
	}

	/**
	 * Read the level field of the current request and store it.
	 */
	private static function apply_level( int $post_id ): void {
		if ( ! isset( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the caller.
			return;
		}
		$level = TransparAI_Meta::sanitize_content_level( sanitize_key( wp_unslash( (string) $_POST[ self::FIELD ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the caller.
		TransparAI_Meta::set_content_level( $post_id, $level );
	}

	/* ---------------------------------------------------------------------
	 * Lists
	 * ------------------------------------------------------------------- */

	/**
	 * Column, sortable header and the Quick Edit assets for every post type.
	 */
	public static function register_columns(): void {
		foreach ( self::post_types() as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', array( self::class, 'add_column' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( self::class, 'column_content' ), 10, 2 );
			add_filter( 'manage_edit-' . $type . '_sortable_columns', array( self::class, 'sortable_column' ) );
		}
	}

	/**
	 * Add the column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function add_column( array $columns ): array {
		$columns[ self::COLUMN ] = __( 'AI text', 'transparai' );
		return $columns;
	}

	/**
	 * Column cell: a chip plus a hidden marker Quick Edit reads its value from.
	 * Reading the value from a data attribute, not from the (translated) chip
	 * text, is what keeps a Quick Edit save from silently resetting the level.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function column_content( string $column, int $post_id ): void {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$level  = TransparAI_Meta::get_content_level( $post_id );
		$labels = TransparAI_Notice::level_labels();
		echo '<span class="trai-qe" data-trai-level="' . esc_attr( $level ) . '" hidden></span>';
		if ( '' === $level ) {
			echo '<span class="trai-level-chip trai-level-chip--none" aria-hidden="true">&ndash;</span>';
			return;
		}
		$class = TransparAI_Meta::level_is_ai( $level ) ? ' trai-level-chip--ai' : '';
		echo '<span class="trai-level-chip' . esc_attr( $class ) . '">' . esc_html( $labels[ $level ] ?? $level ) . '</span>';
		if ( TransparAI_Meta::LEVEL_REVIEWED === $level && ! TransparAI_Meta::is_review_current( $post_id ) ) {
			echo '<br /><span class="description">' . esc_html__( 'changed since review', 'transparai' ) . '</span>';
		}
	}

	/**
	 * Make the column sortable.
	 *
	 * @param array<string, string> $columns Sortable columns.
	 * @return array<string, string>
	 */
	public static function sortable_column( array $columns ): array {
		$columns[ self::COLUMN ] = self::COLUMN;
		return $columns;
	}

	/**
	 * Sort by level with a LEFT JOIN, so posts without the meta stay in the
	 * list (a meta_key orderby would drop them).
	 *
	 * @param array<string, string> $clauses SQL clauses.
	 * @param WP_Query              $query   Query.
	 * @return array<string, string>
	 */
	public static function sort_clauses( array $clauses, WP_Query $query ): array {
		if ( ! is_admin() || ! $query->is_main_query() || self::COLUMN !== $query->get( 'orderby' ) ) {
			return $clauses;
		}
		global $wpdb;
		$order              = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		$clauses['join']   .= $wpdb->prepare( " LEFT JOIN {$wpdb->postmeta} trai_level ON trai_level.post_id = {$wpdb->posts}.ID AND trai_level.meta_key = %s", TransparAI_Meta::KEY_CONTENT_AI );
		$clauses['orderby'] = 'trai_level.meta_value ' . $order . ', ' . $wpdb->posts . '.post_date DESC';
		return $clauses;
	}

	/**
	 * Filter dropdown above the list.
	 *
	 * @param string $post_type Current post type.
	 */
	public static function filter_dropdown( string $post_type ): void {
		if ( ! in_array( $post_type, self::post_types(), true ) ) {
			return;
		}
		$current = isset( $_GET['transparai_level'] ) ? sanitize_key( wp_unslash( (string) $_GET['transparai_level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$options = array(
			''             => __( 'All AI levels', 'transparai' ),
			'ai'           => __( 'Any AI involvement', 'transparai' ),
			'unclassified' => __( 'Not classified', 'transparai' ),
		);
		foreach ( TransparAI_Meta::CONTENT_LEVELS as $level ) {
			$options[ $level ] = TransparAI_Notice::level_labels()[ $level ];
		}
		echo '<select name="transparai_level">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Apply the list filter.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || empty( $_GET['transparai_level'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
			return;
		}
		$value = sanitize_key( wp_unslash( (string) $_GET['transparai_level'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		$key   = TransparAI_Meta::KEY_CONTENT_AI;
		if ( 'ai' === $value ) {
			$meta = array(
				'key'     => $key,
				'value'   => array( TransparAI_Meta::LEVEL_ASSISTED, TransparAI_Meta::LEVEL_GEN, TransparAI_Meta::LEVEL_REVIEWED, '1' ),
				'compare' => 'IN',
			);
		} elseif ( 'unclassified' === $value ) {
			$meta = array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			);
		} elseif ( in_array( $value, TransparAI_Meta::CONTENT_LEVELS, true ) ) {
			$meta = array(
				'key'     => $key,
				'value'   => TransparAI_Meta::LEVEL_GEN === $value ? array( $value, '1' ) : array( $value ),
				'compare' => 'IN',
			);
		} else {
			return;
		}
		$query->set( 'meta_query', array( $meta ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- explicit admin list filter.
	}

	/**
	 * Quick Edit field (rendered once per screen, filled by quick-edit.js).
	 *
	 * @param string $column    Column name.
	 * @param string $post_type Post type.
	 */
	public static function quick_edit_box( string $column, string $post_type ): void {
		if ( self::COLUMN !== $column || ! in_array( $post_type, self::post_types(), true ) ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right trai-inline-edit">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'AI text', 'transparai' ); ?></span>
					<select name="<?php echo esc_attr( self::FIELD ); ?>">
						<?php foreach ( TransparAI_Notice::level_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php wp_nonce_field( self::QE_NONCE, 'transparai_content_qe_nonce', false ); ?>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Bulk Edit field. Needs the core `bulk_edit_posts` action (WordPress
	 * 6.3); on older versions no field is offered rather than one that
	 * silently does not save. "No change" and "remove" are two different
	 * answers, so they are two different values.
	 *
	 * @param string $column    Column name.
	 * @param string $post_type Post type.
	 */
	public static function bulk_edit_box( string $column, string $post_type ): void {
		if ( self::COLUMN !== $column || ! in_array( $post_type, self::post_types(), true ) || ! self::bulk_edit_supported() ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right trai-inline-edit">
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'AI text', 'transparai' ); ?></span>
					<select name="<?php echo esc_attr( self::FIELD ); ?>_bulk">
						<option value=""><?php esc_html_e( '&mdash; No change &mdash;', 'transparai' ); ?></option>
						<option value="clear"><?php esc_html_e( 'Remove classification', 'transparai' ); ?></option>
						<?php foreach ( TransparAI_Meta::CONTENT_LEVELS as $value ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( TransparAI_Notice::level_labels()[ $value ] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Whether core offers the bulk edit save hook.
	 */
	private static function bulk_edit_supported(): bool {
		return version_compare( (string) get_bloginfo( 'version' ), '6.3', '>=' );
	}

	/**
	 * Bulk Edit save (core checked the `bulk-posts` nonce already).
	 *
	 * @param int[]                $updated Updated post IDs.
	 * @param array<string, mixed> $shared  Shared request data.
	 */
	public static function save_bulk( array $updated, array $shared ): void {
		$raw = $shared[ self::FIELD . '_bulk' ] ?? '';
		if ( ! is_string( $raw ) || '' === $raw ) {
			return;
		}
		$value = sanitize_key( wp_unslash( $raw ) );
		if ( 'clear' !== $value && ! in_array( $value, TransparAI_Meta::CONTENT_LEVELS, true ) ) {
			return;
		}
		foreach ( $updated as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			TransparAI_Meta::set_content_level( $post_id, 'clear' === $value ? '' : $value );
		}
	}

	/**
	 * Quick Edit script and the list styles on edit.php.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function enqueue_list_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || ! in_array( (string) $screen->post_type, self::post_types(), true ) ) {
			return;
		}
		wp_enqueue_style( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/css/admin.css', array(), TRANSPARAI_VERSION );
		wp_enqueue_script( 'transparai-quick-edit', TRANSPARAI_PLUGIN_URL . 'assets/js/quick-edit.js', array( 'inline-edit-post' ), TRANSPARAI_VERSION, true );
	}
}
