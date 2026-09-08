<?php
/**
 * Media library UI: checkbox and detection info in the attachment details,
 * badges, filters (grid + list, incl. the review queue), bulk actions and
 * notices.
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
 * Media library integration.
 */
final class TransparAI_Media_Library {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'attachment_fields_to_edit', array( self::class, 'attachment_fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( self::class, 'attachment_save' ), 10, 2 );

		add_filter( 'wp_prepare_attachment_for_js', array( self::class, 'prepare_js' ), 10, 2 );
		add_filter( 'ajax_query_attachments_args', array( self::class, 'ajax_filter' ) );
		add_action( 'wp_ajax_transparai_bulk', array( self::class, 'ajax_bulk' ) );
		add_action( 'wp_ajax_transparai_inspect', array( self::class, 'ajax_inspect' ) );
		add_action( 'wp_enqueue_media', array( self::class, 'enqueue_media_assets' ) );

		add_filter( 'manage_media_columns', array( self::class, 'media_column' ) );
		add_action( 'manage_media_custom_column', array( self::class, 'media_column_content' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( self::class, 'list_filter_dropdown' ) );
		add_action( 'pre_get_posts', array( self::class, 'list_filter_query' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_list_assets' ) );

		add_filter( 'bulk_actions-upload', array( self::class, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( self::class, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'bulk_notice' ) );
	}

	/**
	 * Media mime types this UI applies to.
	 */
	private static function applies( WP_Post $post ): bool {
		$mime = (string) $post->post_mime_type;
		return str_starts_with( $mime, 'image/' ) || str_starts_with( $mime, 'video/' ) || str_starts_with( $mime, 'audio/' );
	}

	/**
	 * Checkbox + detection details in the attachment fields.
	 *
	 * @param array<string, mixed> $fields Form fields.
	 * @param WP_Post              $post   Attachment.
	 * @return array<string, mixed>
	 */
	public static function attachment_fields( array $fields, WP_Post $post ): array {
		if ( ! self::applies( $post ) ) {
			return $fields;
		}

		$id      = (int) $post->ID;
		$checked = TransparAI_Meta::is_flagged( $id ) ? ' checked="checked"' : '';
		$name    = 'attachments[' . $id . '][transparai_ai]';

		$html = '<label style="display:inline-flex;align-items:center;gap:6px;margin-top:2px;">'
			. '<input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . $checked . ' /> '
			. esc_html__( 'Media is AI-generated', 'transparai' ) . '</label>';

		$detail = self::detection_summary( $id );
		if ( '' !== $detail ) {
			$html .= '<span class="trai-detail">' . $detail . '</span>';
		}

		if ( '' !== (string) get_post_meta( $id, TransparAI_Meta::KEY_WRITE_ERROR, true ) ) {
			$html .= '<span class="trai-write-error">'
				. esc_html__( 'The file metadata could not be updated (file not writable). The label state in WordPress and the metadata inside the file may differ.', 'transparai' )
				. '</span>';
		}

		if ( TransparAI_Meta::is_detected( $id ) ) {
			$html .= '<span class="trai-review" data-id="' . esc_attr( (string) $id ) . '">'
				. '<button type="button" class="button button-small trai-confirm">' . esc_html__( 'Confirm AI label', 'transparai' ) . '</button> '
				. '<button type="button" class="button button-small trai-dismiss">' . esc_html__( 'Not AI, dismiss', 'transparai' ) . '</button>'
				. '</span>';
		}

		$html .= '<span class="trai-recheck-wrap"><button type="button" class="button-link trai-recheck" data-id="' . esc_attr( (string) $id ) . '">'
			. esc_html__( 'Re-check file metadata', 'transparai' ) . '</button></span>';

		if ( TransparAI_Delivery::enabled() && TransparAI_Meta::is_flagged( $id ) ) {
			$last  = TransparAI_Delivery::last_result( $id );
			$html .= '<span class="trai-delivery-wrap"><button type="button" class="button-link trai-delivery" data-id="' . esc_attr( (string) $id ) . '">'
				. esc_html__( 'Check delivery', 'transparai' ) . '</button>';
			if ( null !== $last ) {
				$html .= '<span class="trai-delivery-result">' . esc_html( TransparAI_Delivery::verdict_label( $last['verdict'] ) ) . '</span>';
			} else {
				$html .= '<span class="trai-delivery-result"></span>';
			}
			$html .= '</span>';
		}

		$html .= '<span class="trai-inspect-wrap"><button type="button" class="button-link trai-inspect" data-id="' . esc_attr( (string) $id ) . '">'
			. esc_html__( 'Show file metadata', 'transparai' ) . '</button><div class="trai-inspect-out" hidden></div></span>';

		$fields['transparai_ai'] = array(
			'label' => __( 'AI content', 'transparai' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => esc_html__( 'Controls the front-end badge, the machine-readable file metadata and the media library filter.', 'transparai' ),
		);

		if ( TransparAI_Meta::is_flagged( $id ) ) {
			$current = TransparAI_Meta::get_badge_position( $id );
			$choices = array(
				''             => __( 'Default (site setting)', 'transparai' ),
				'top-left'     => __( 'Top left', 'transparai' ),
				'top-right'    => __( 'Top right', 'transparai' ),
				'bottom-left'  => __( 'Bottom left', 'transparai' ),
				'bottom-right' => __( 'Bottom right', 'transparai' ),
				'below'        => __( 'Caption line below the image', 'transparai' ),
				'hidden'       => __( 'Hide the visible badge on this image', 'transparai' ),
			);
			$select  = '<select name="attachments[' . $id . '][transparai_badge_pos]">';
			foreach ( $choices as $value => $label ) {
				$select .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
			}
			$select .= '</select>';

			$fields['transparai_badge_pos'] = array(
				'label' => __( 'Badge position', 'transparai' ),
				'input' => 'html',
				'html'  => $select,
				'helps' => esc_html__( 'Overrides this image only. Use it if a theme overlay covers the badge here.', 'transparai' ),
			);

			$fields['transparai_generator'] = array(
				'label' => __( 'AI generator', 'transparai' ),
				'input' => 'html',
				'html'  => '<input type="text" class="widefat" name="attachments[' . $id . '][transparai_generator]" value="'
					. esc_attr( TransparAI_Meta::get_generator( $id ) ) . '" />',
				'helps' => esc_html__( 'Name of the AI tool, e.g. "Midjourney". Shown by the badge source option and the {generator} placeholder.', 'transparai' ),
			);
		}

		return $fields;
	}

	/**
	 * Escaped one-line summary of the stored detection result.
	 */
	private static function detection_summary( int $attachment_id ): string {
		$source = (string) get_post_meta( $attachment_id, TransparAI_Meta::KEY_SOURCE, true );
		if ( '' === $source ) {
			return '';
		}
		$generator  = TransparAI_Meta::get_generator( $attachment_id );
		$confidence = (string) get_post_meta( $attachment_id, TransparAI_Meta::KEY_CONFIDENCE, true );
		$evidence   = (string) get_post_meta( $attachment_id, TransparAI_Meta::KEY_EVIDENCE, true );

		$labels = array(
			'certain' => __( 'certain', 'transparai' ),
			'likely'  => __( 'likely', 'transparai' ),
			'hint'    => __( 'hint', 'transparai' ),
		);

		$parts = array();
		if ( '' !== $generator ) {
			$parts[] = esc_html( $generator );
		}
		$parts[] = esc_html( $source );
		if ( isset( $labels[ $confidence ] ) ) {
			$parts[] = esc_html( $labels[ $confidence ] );
		}

		$summary = implode( ' · ', $parts );
		if ( '' !== $evidence ) {
			$summary .= '<br /><em title="' . esc_attr( $evidence ) . '">' . esc_html( wp_html_excerpt( $evidence, 90, '…' ) ) . '</em>';
		}
		return $summary;
	}

	/**
	 * Persist the checkbox.
	 *
	 * @param array<string, mixed> $post       Attachment data.
	 * @param array<string, mixed> $attachment Form values.
	 * @return array<string, mixed>
	 */
	public static function attachment_save( array $post, array $attachment ): array {
		if ( ! isset( $post['ID'] ) ) {
			return $post;
		}
		$id = (int) $post['ID'];
		if ( ! empty( $attachment['transparai_ai'] ) ) {
			if ( ! TransparAI_Meta::is_flagged( $id ) ) {
				TransparAI_Meta::flag( $id, 'manual' );
			}
		} elseif ( TransparAI_Meta::is_flagged( $id ) ) {
			TransparAI_Meta::unflag( $id );
		}
		if ( isset( $attachment['transparai_badge_pos'] ) ) {
			$position = TransparAI_Meta::sanitize_badge_pos( $attachment['transparai_badge_pos'] );
			if ( '' === $position ) {
				delete_post_meta( $id, TransparAI_Meta::KEY_BADGE_POS );
			} else {
				update_post_meta( $id, TransparAI_Meta::KEY_BADGE_POS, $position );
			}
		}
		if ( isset( $attachment['transparai_generator'] ) ) {
			$generator = sanitize_text_field( wp_unslash( (string) $attachment['transparai_generator'] ) );
			if ( '' === $generator ) {
				delete_post_meta( $id, TransparAI_Meta::KEY_GENERATOR );
			} else {
				update_post_meta( $id, TransparAI_Meta::KEY_GENERATOR, $generator );
			}
		}
		return $post;
	}

	/**
	 * Expose flag state to the media grid JS.
	 *
	 * @param array<string, mixed> $response   JS attachment model.
	 * @param WP_Post              $attachment Attachment.
	 * @return array<string, mixed>
	 */
	public static function prepare_js( array $response, WP_Post $attachment ): array {
		$response['traiFlag']     = TransparAI_Meta::is_flagged( (int) $attachment->ID );
		$response['traiDetected'] = TransparAI_Meta::is_detected( (int) $attachment->ID );
		return $response;
	}

	/**
	 * Grid filter (media modal AJAX query).
	 *
	 * @param array<string, mixed> $args Query args.
	 * @return array<string, mixed>
	 */
	public static function ajax_filter( array $args ): array {
		if ( isset( $_REQUEST['query']['transparai_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter of the media grid query.
			$value = sanitize_key( wp_unslash( (string) $_REQUEST['query']['transparai_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			$query = TransparAI_Meta::meta_query( $value );
			if ( null !== $query ) {
				$args['meta_query'] = $query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- user-requested library filter.
			}
		}
		return $args;
	}

	/**
	 * AJAX endpoint for the grid bulk buttons and the review actions.
	 */
	public static function ajax_bulk(): void {
		check_ajax_referer( 'transparai_bulk' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'transparai' ) ), 403 );
		}
		$ids    = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$action = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( (string) $_POST['op'] ) ) : '';
		if ( ! in_array( $action, array( 'flag', 'unflag', 'confirm', 'dismiss' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'transparai' ) ), 400 );
		}
		wp_send_json_success( array( 'count' => TransparAI_Meta::bulk_apply( $ids, $action ) ) );
	}

	/**
	 * What the plugin knows about one file, as HTML for the details panel.
	 *
	 * The label state in WordPress and the declaration inside the file can drift
	 * apart (an optimizer stripped it, a format cannot carry it, a write failed),
	 * and until now nothing in the admin showed which of the two you were looking
	 * at. This reads the files and says it plainly.
	 */
	public static function ajax_inspect(): void {
		check_ajax_referer( 'transparai_bulk' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'transparai' ) ), 403 );
		}

		$attachment_id = isset( $_POST['attachment'] ) ? absint( wp_unslash( $_POST['attachment'] ) ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'transparai' ) ), 400 );
		}

		wp_send_json_success( array( 'html' => self::inspect_html( $attachment_id ) ) );
	}

	/**
	 * Escaped markup of the inspection panel.
	 */
	private static function inspect_html( int $attachment_id ): string {
		$data = TransparAI_Writer::inspect( $attachment_id );
		$html = '';

		$html .= '<h4>' . esc_html__( 'Files', 'transparai' ) . '</h4><ul class="trai-inspect-files">';
		foreach ( $data['files'] as $file ) {
			if ( null === $file['marked'] ) {
				$state = __( 'format cannot carry the declaration', 'transparai' );
			} elseif ( $file['marked'] ) {
				$state = __( 'declaration present', 'transparai' );
			} else {
				$state = __( 'declaration missing', 'transparai' );
			}
			$html .= '<li><code>' . esc_html( $file['name'] ) . '</code> <span>' . esc_html( $state ) . '</span></li>';
		}
		if ( array() === $data['files'] ) {
			$html .= '<li>' . esc_html__( 'No readable file found for this attachment.', 'transparai' ) . '</li>';
		}
		$html .= '</ul>';

		$html .= '<h4>' . esc_html__( 'Digital source type in the main file', 'transparai' ) . '</h4>';
		$html .= '<p>' . ( array() === $data['terms'] ? esc_html__( 'None declared.', 'transparai' ) : esc_html( implode( ', ', $data['terms'] ) ) ) . '</p>';

		$evidence = (string) get_post_meta( $attachment_id, TransparAI_Meta::KEY_EVIDENCE, true );
		if ( '' !== $evidence ) {
			$html .= '<h4>' . esc_html__( 'Detection evidence', 'transparai' ) . '</h4>';
			$html .= '<p>' . esc_html( $evidence ) . '</p>';
		}

		$history = TransparAI_Meta::history( $attachment_id );
		if ( array() !== $history ) {
			$html .= '<h4>' . esc_html__( 'History', 'transparai' ) . '</h4><ul class="trai-inspect-history">';
			foreach ( array_reverse( $history ) as $entry ) {
				$when = 0 === $entry['t'] ? '' : gmdate( 'Y-m-d H:i', $entry['t'] ) . ' UTC';
				$who  = 0 === $entry['u'] ? __( 'system', 'transparai' ) : ( get_userdata( $entry['u'] )->display_name ?? '#' . $entry['u'] );
				$line = '' === $entry['s']
					? sprintf( '%1$s: %2$s (%3$s)', $when, $entry['e'], $who )
					/* translators: 1: date, 2: event name, 3: source, 4: user name. */
					: sprintf( '%1$s: %2$s, %3$s (%4$s)', $when, $entry['e'], $entry['s'], $who );
				$html .= '<li>' . esc_html( $line ) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '<h4>' . esc_html__( 'XMP packet of the main file', 'transparai' ) . '</h4>';
		if ( '' === $data['xmp'] ) {
			$html .= '<p>' . esc_html__( 'This file carries no XMP block.', 'transparai' ) . '</p>';
		} else {
			$html .= '<pre class="trai-inspect-xmp">' . esc_html( mb_substr( $data['xmp'], 0, 4000 ) ) . '</pre>';
		}

		return $html;
	}

	/**
	 * Assets + inline data for the media modal / grid.
	 */
	public static function enqueue_media_assets(): void {
		wp_enqueue_style( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/css/admin.css', array(), TRANSPARAI_VERSION );

		/* The short tile label ("AI"/"KI") is locale-dependent, so it is inlined. */
		wp_add_inline_style(
			'transparai-admin',
			'.attachment.trai-flag .thumbnail::after{content:"' . esc_attr( TransparAI_Frontend::badge_short_label() ) . '";}'
			. '.attachment.trai-detected .thumbnail::after{content:"' . esc_attr( TransparAI_Frontend::badge_short_label() ) . '?";}'
		);

		wp_enqueue_script( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery', 'media-views' ), TRANSPARAI_VERSION, true );
		self::localize_admin();
	}

	/**
	 * Nonces and strings for admin.js. Both surfaces that load the script
	 * (media modal/grid and the attachment edit screen) hand it the same data.
	 */
	private static function localize_admin(): void {
		wp_localize_script(
			'transparai-admin',
			'transparaiAdmin',
			array(
				'nonce'     => wp_create_nonce( 'transparai_bulk' ),
				'scanNonce' => wp_create_nonce( TransparAI_Scanner::NONCE ),
				'labels'    => self::js_labels(),
			)
		);
	}

	/**
	 * Localized strings shared by every admin.js surface.
	 *
	 * @return array<string, string>
	 */
	private static function js_labels(): array {
		return array(
			'filterAll'      => __( 'AI status: all', 'transparai' ),
			'filterOnly'     => __( 'Only AI-labeled', 'transparai' ),
			'filterDetected' => __( 'Detected, needs review', 'transparai' ),
			'filterNone'     => __( 'Without AI label', 'transparai' ),
			'bulkOn'         => __( 'Mark as AI-generated', 'transparai' ),
			'bulkOff'        => __( 'Remove AI label', 'transparai' ),
			'selectFirst'    => __( 'Please select media first.', 'transparai' ),
			'updateFailed'   => __( 'Updating the AI label failed.', 'transparai' ),
			'recheckDone'    => __( 'Result', 'transparai' ),
			'recheckClean'   => __( 'No AI provenance signals found in the file.', 'transparai' ),
			'inspectShow'    => __( 'Show file metadata', 'transparai' ),
			'inspectHide'    => __( 'Hide file metadata', 'transparai' ),
			/* translators: 1: number of files checked, 2: intact count, 3: stripped count. */
			'deliverySample' => __( '%1$d checked: %2$d delivered with the declaration, %3$d without.', 'transparai' ),
		);
	}

	/**
	 * List view column.
	 *
	 * @param array<string, string> $cols Columns.
	 * @return array<string, string>
	 */
	public static function media_column( array $cols ): array {
		$cols['transparai'] = __( 'AI', 'transparai' );
		return $cols;
	}

	/**
	 * List view column content.
	 *
	 * @param string $col Column key.
	 * @param int    $id  Attachment ID.
	 */
	public static function media_column_content( string $col, int $id ): void {
		if ( 'transparai' !== $col ) {
			return;
		}
		if ( TransparAI_Meta::is_flagged( $id ) ) {
			$generator = TransparAI_Meta::get_generator( $id );
			echo '<span class="trai-list-badge" title="' . esc_attr( $generator ) . '">' . esc_html( TransparAI_Frontend::badge_short_label() ) . '</span>';
			if ( '' !== $generator ) {
				echo '<span class="trai-list-generator">' . esc_html( $generator ) . '</span>';
			}
		} elseif ( TransparAI_Meta::is_detected( $id ) ) {
			echo '<span class="trai-list-badge trai-list-badge--review" title="' . esc_attr__( 'Detected, needs review', 'transparai' ) . '">' . esc_html( TransparAI_Frontend::badge_short_label() ) . '?</span>';
		}
	}

	/**
	 * List view filter dropdown.
	 */
	public static function list_filter_dropdown( string $post_type ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		$value = isset( $_GET['transparai_filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['transparai_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		echo '<select name="transparai_filter">'
			. '<option value=""' . selected( $value, '', false ) . '>' . esc_html__( 'AI status: all', 'transparai' ) . '</option>'
			. '<option value="1"' . selected( $value, '1', false ) . '>' . esc_html__( 'Only AI-labeled', 'transparai' ) . '</option>'
			. '<option value="detected"' . selected( $value, 'detected', false ) . '>' . esc_html__( 'Detected, needs review', 'transparai' ) . '</option>'
			. '<option value="0"' . selected( $value, '0', false ) . '>' . esc_html__( 'Without AI label', 'transparai' ) . '</option>'
			. '</select>';
	}

	/**
	 * Apply the list filter to the main query.
	 */
	public static function list_filter_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'attachment' !== $query->get( 'post_type' ) || ! isset( $_GET['transparai_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
			return;
		}
		$meta_query = TransparAI_Meta::meta_query( sanitize_key( wp_unslash( (string) $_GET['transparai_filter'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		if ( null !== $meta_query ) {
			$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- user-requested library filter.
		}
	}

	/**
	 * List view and attachment edit screen assets.
	 */
	public static function enqueue_list_assets( string $hook ): void {
		$is_attachment_edit = 'post.php' === $hook && 'attachment' === get_post_type( absint( $_GET['post'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen detection.
		if ( 'upload.php' === $hook || $is_attachment_edit ) {
			wp_enqueue_style( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/css/admin.css', array(), TRANSPARAI_VERSION );
		}
		if ( $is_attachment_edit ) {
			wp_enqueue_script( 'transparai-admin', TRANSPARAI_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), TRANSPARAI_VERSION, true );
			self::localize_admin();
		}
	}

	/**
	 * Native bulk actions (list view).
	 *
	 * @param array<string, string> $actions Actions.
	 * @return array<string, string>
	 */
	public static function bulk_actions( array $actions ): array {
		$actions['transparai_flag']    = __( 'Mark as AI-generated', 'transparai' );
		$actions['transparai_unflag']  = __( 'Remove AI label', 'transparai' );
		$actions['transparai_confirm'] = __( 'Confirm detected AI label', 'transparai' );
		$actions['transparai_dismiss'] = __( 'Dismiss detected AI label', 'transparai' );
		return $actions;
	}

	/**
	 * Handle a native bulk action.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action key.
	 * @param array  $ids      Attachment IDs.
	 */
	public static function handle_bulk( string $redirect, string $action, array $ids ): string {
		$map = array(
			'transparai_flag'    => 'flag',
			'transparai_unflag'  => 'unflag',
			'transparai_confirm' => 'confirm',
			'transparai_dismiss' => 'dismiss',
		);
		if ( ! isset( $map[ $action ] ) ) {
			return $redirect;
		}
		$count = TransparAI_Meta::bulk_apply( array_map( 'intval', $ids ), $map[ $action ] );
		return add_query_arg( 'transparai_bulk_done', $count, $redirect );
	}

	/**
	 * Success notice after a bulk action.
	 */
	public static function bulk_notice(): void {
		if ( ! isset( $_REQUEST['transparai_bulk_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only success notice.
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		$count = absint( wp_unslash( $_REQUEST['transparai_bulk_done'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only success notice.
		echo '<div class="notice notice-success is-dismissible"><p>'
			/* translators: %d: number of updated media files. */
			. esc_html( sprintf( _n( 'AI label updated for %d media file.', 'AI label updated for %d media files.', $count, 'transparai' ), $count ) )
			. '</p></div>';
	}
}
