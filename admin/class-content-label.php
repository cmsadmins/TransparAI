<?php
/**
 * Per-post AI content labeling: a small side meta box with one checkbox.
 * Marked posts get a note ahead of their content in the front end
 * (rendered by TransparAI_Frontend::filter_content_notice()).
 *
 * Works in the block editor as a regular meta box, no build step needed.
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
 * Editor checkbox for AI-written content.
 */
final class TransparAI_Content_Label {

	private const NONCE = 'transparai_content_label';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add_box' ) );
		add_action( 'save_post', array( self::class, 'save' ) );
	}

	/**
	 * Add the side meta box to every public post type except attachments.
	 */
	public static function add_box(): void {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		add_meta_box(
			'transparai-content',
			__( 'TransparAI', 'transparai' ),
			array( self::class, 'render' ),
			array_values( $types ),
			'side',
			'default'
		);
	}

	/**
	 * Render the checkbox.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, 'transparai_content_nonce' );
		$checked = get_post_meta( $post->ID, TransparAI_Meta::KEY_CONTENT_AI, true );
		?>
		<label>
			<input type="checkbox" name="transparai_content_ai" value="1" <?php checked( $checked, '1' ); ?> />
			<?php esc_html_e( 'This content is AI-generated', 'transparai' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Shows a short note ahead of the content. The wording can be changed in the TransparAI settings.', 'transparai' ); ?></p>
		<?php
	}

	/**
	 * Persist the checkbox.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! isset( $_POST['transparai_content_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( wp_unslash( (string) $_POST['transparai_content_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$flag = TransparAI_Meta::sanitize_flag( isset( $_POST['transparai_content_ai'] ) ? wp_unslash( $_POST['transparai_content_ai'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_flag() normalizes the value to '1' or ''.
		if ( '1' === $flag ) {
			update_post_meta( $post_id, TransparAI_Meta::KEY_CONTENT_AI, '1' );
		} else {
			delete_post_meta( $post_id, TransparAI_Meta::KEY_CONTENT_AI );
		}
	}
}
