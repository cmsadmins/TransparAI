<?php
/**
 * Attachment meta: registration, flag helpers, review queue state.
 *
 * Two-layer model (detection result and public label are kept apart):
 *  - `_transparai_ai`        confirmed public label ('1' or absent)
 *  - `_transparai_detected`  unconfirmed auto-detection awaiting review ('1' or absent)
 *  - `_transparai_dismissed` reviewer rejected the auto-detection ('1' or absent)
 * plus descriptive keys (type, source, generator, confidence, evidence).
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
 * Meta layer.
 */
final class TransparAI_Meta {

	public const KEY_FLAG        = '_transparai_ai';
	public const KEY_DETECTED    = '_transparai_detected';
	public const KEY_DISMISSED   = '_transparai_dismissed';
	public const KEY_TYPE        = '_transparai_type';
	public const KEY_SOURCE      = '_transparai_source';
	public const KEY_GENERATOR   = '_transparai_generator';
	public const KEY_CONFIDENCE  = '_transparai_confidence';
	public const KEY_EVIDENCE    = '_transparai_evidence';
	public const KEY_MARKED_BY   = '_transparai_marked_by';
	public const KEY_SCANNED     = '_transparai_scanned';
	public const KEY_UNREADABLE  = '_transparai_unreadable';
	public const KEY_FINGERPRINT = '_transparai_fingerprint';
	public const KEY_WRITE_ERROR = '_transparai_write_error';
	public const KEY_BADGE_POS   = '_transparai_badge_pos';
	public const KEY_CONTENT_AI  = '_transparai_content_ai';
	public const KEY_HISTORY     = '_transparai_history';
	public const KEY_DELIVERY    = '_transparai_delivery';
	/* Active non-AI declaration: digitalCapture (camera photo) or digitalCreation (human digital work). */
	public const KEY_HUMAN = '_transparai_human';

	/* Post-level (not attachment) disclosure of AI-written text. */
	public const KEY_CONTENT_RESPONSIBLE = '_transparai_content_responsible';
	public const KEY_CONTENT_REVIEW      = '_transparai_content_review';

	/**
	 * Disclosure levels of a post's text. `''` (never classified) is kept apart
	 * from `none`: "nobody looked" and "a person declared no AI was used" are
	 * two different statements, and an audit asks for the second one.
	 */
	public const LEVEL_NONE     = 'none';
	public const LEVEL_ASSISTED = 'assisted';
	public const LEVEL_GEN      = 'generated';
	public const LEVEL_REVIEWED = 'generated_reviewed';
	public const CONTENT_LEVELS = array( self::LEVEL_NONE, self::LEVEL_ASSISTED, self::LEVEL_GEN, self::LEVEL_REVIEWED );

	/* IPTC digital source type vocabulary, one set for files, JSON-LD and text. */
	public const DST_TRAINED   = 'trainedAlgorithmicMedia';
	public const DST_COMPOSITE = 'compositeWithTrainedAlgorithmicMedia';
	public const DST_CAPTURE   = 'digitalCapture';
	public const DST_CREATION  = 'digitalCreation';
	public const DST_CV_BASE   = 'http://cv.iptc.org/newscodes/digitalsourcetype/';

	/**
	 * Events kept per attachment. Ten covers the whole life of a normal file
	 * (detected, reviewed, labeled, a few repairs) and keeps the meta row small
	 * enough that nothing has to prune it later.
	 */
	private const HISTORY_LIMIT = 10;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		/*
		 * Priority 20: the content flag registers for every public post type,
		 * so custom post types (usually registered at 10) must exist first.
		 */
		add_action( 'init', array( self::class, 'register_meta' ), 20 );

		/*
		 * The review stamp hangs on the meta hooks, not on a save handler: the
		 * classic meta box, the block editor (REST) and a script all end up here,
		 * so the date and reviewer are set no matter which door the level came in.
		 */
		add_action( 'added_post_meta', array( self::class, 'on_content_level_change' ), 10, 4 );
		add_action( 'updated_post_meta', array( self::class, 'on_content_level_change' ), 10, 4 );
	}

	/**
	 * Register REST-exposed attachment meta.
	 */
	public static function register_meta(): void {
		register_post_meta(
			'attachment',
			self::KEY_FLAG,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_flag' ),
				'auth_callback'     => static function (): bool {
					return current_user_can( 'upload_files' );
				},
			)
		);

		foreach ( array( self::KEY_TYPE, self::KEY_SOURCE, self::KEY_GENERATOR, self::KEY_CONFIDENCE ) as $key ) {
			register_post_meta(
				'attachment',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => static function (): bool {
						return current_user_can( 'upload_files' );
					},
				)
			);
		}

		register_post_meta(
			'attachment',
			self::KEY_HUMAN,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_human' ),
				'auth_callback'     => static function (): bool {
					return current_user_can( 'upload_files' );
				},
			)
		);

		register_post_meta(
			'attachment',
			self::KEY_BADGE_POS,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => '',
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_badge_pos' ),
				'auth_callback'     => static function (): bool {
					return current_user_can( 'upload_files' );
				},
			)
		);

		/* Per-post disclosure of AI-written text (level, responsible person, review stamp). */
		$edit_post = static function ( $allowed, $meta_key, $post_id ): bool {
			return current_user_can( 'edit_post', (int) $post_id );
		};
		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			if ( 'attachment' === $post_type ) {
				continue;
			}
			register_post_meta(
				$post_type,
				self::KEY_CONTENT_AI,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => array( self::class, 'sanitize_content_level' ),
					'auth_callback'     => $edit_post,
				)
			);
			register_post_meta(
				$post_type,
				self::KEY_CONTENT_RESPONSIBLE,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => $edit_post,
				)
			);
			/*
			 * The stamp is readable in the editor (so the panel can say "reviewed
			 * by X on Y") but never writable through REST: it is set by the plugin
			 * when the level changes, and a stamp anyone can type is no evidence.
			 */
			register_post_meta(
				$post_type,
				self::KEY_CONTENT_REVIEW,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					'auth_callback'     => '__return_false',
				)
			);
			add_filter( 'rest_prepare_' . $post_type, array( self::class, 'filter_rest_review' ), 10, 2 );
		}
	}

	/**
	 * Strip the reviewer's identity from public REST responses. `auth_callback`
	 * only guards writes; without this, every anonymous /wp-json/wp/v2/posts
	 * request would list who reviewed what.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_Post          $post     Post.
	 * @return WP_REST_Response
	 */
	public static function filter_rest_review( $response, $post ) {
		if ( ! isset( $response->data['meta'][ self::KEY_CONTENT_REVIEW ] ) || current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $response;
		}
		$stamp = self::content_review( (int) $post->ID );
		$response->data['meta'][ self::KEY_CONTENT_REVIEW ] = null === $stamp ? '' : (string) wp_json_encode( array( 'on' => $stamp['on'] ) );
		return $response;
	}

	/**
	 * Normalize a text disclosure level. The 1.0.x checkbox stored '1', which
	 * reads as `generated`; anything unknown is '' (never classified).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_content_level( $value ): string {
		if ( '1' === $value || 1 === $value || true === $value ) {
			return self::LEVEL_GEN;
		}
		$value = is_string( $value ) ? sanitize_key( $value ) : '';
		return in_array( $value, self::CONTENT_LEVELS, true ) ? $value : '';
	}

	/**
	 * Disclosure level of a post ('' when never classified).
	 */
	public static function get_content_level( int $post_id ): string {
		return self::sanitize_content_level( get_post_meta( $post_id, self::KEY_CONTENT_AI, true ) );
	}

	/**
	 * Whether a level means AI took part in the text.
	 */
	public static function level_is_ai( string $level ): bool {
		return in_array( $level, array( self::LEVEL_ASSISTED, self::LEVEL_GEN, self::LEVEL_REVIEWED ), true );
	}

	/**
	 * Set the disclosure level of a post. '' removes the classification; the
	 * review stamp is kept on purpose (documented facts are never destroyed).
	 */
	public static function set_content_level( int $post_id, string $level ): void {
		$level = self::sanitize_content_level( $level );
		if ( '' === $level ) {
			delete_post_meta( $post_id, self::KEY_CONTENT_AI );
			return;
		}
		update_post_meta( $post_id, self::KEY_CONTENT_AI, $level );
	}

	/**
	 * Stamp the review when a post reaches the reviewed level (meta hook).
	 *
	 * @param int    $meta_id  Meta ID.
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    New value.
	 */
	public static function on_content_level_change( $meta_id, $post_id, $meta_key, $value ): void {
		if ( self::KEY_CONTENT_AI !== $meta_key || self::LEVEL_REVIEWED !== self::sanitize_content_level( $value ) ) {
			return;
		}
		$post_id = (int) $post_id;
		$user    = wp_get_current_user();
		$stamp   = array(
			'by'          => $user instanceof WP_User ? (string) $user->display_name : '',
			'by_id'       => get_current_user_id(),
			/* Site-local date: a UTC stamp shows yesterday's date after local midnight. */
			'on'          => (string) current_time( 'Y-m-d' ),
			'responsible' => (string) get_post_meta( $post_id, self::KEY_CONTENT_RESPONSIBLE, true ),
			'hash'        => self::content_hash( $post_id ),
		);
		if ( '' === $stamp['responsible'] ) {
			$stamp['responsible'] = TransparAI_Options::get( 'content_responsible' );
		}
		update_post_meta( $post_id, self::KEY_CONTENT_REVIEW, (string) wp_json_encode( $stamp ) );
	}

	/**
	 * The review stamp of a post, or null.
	 *
	 * @return array{by:string, by_id:int, on:string, responsible:string, hash:string}|null
	 */
	public static function content_review( int $post_id ): ?array {
		$stamp = json_decode( (string) get_post_meta( $post_id, self::KEY_CONTENT_REVIEW, true ), true );
		if ( ! is_array( $stamp ) || empty( $stamp['on'] ) ) {
			return null;
		}
		return array(
			'by'          => (string) ( $stamp['by'] ?? '' ),
			'by_id'       => (int) ( $stamp['by_id'] ?? 0 ),
			'on'          => (string) $stamp['on'],
			'responsible' => (string) ( $stamp['responsible'] ?? '' ),
			'hash'        => (string) ( $stamp['hash'] ?? '' ),
		);
	}

	/**
	 * Fingerprint of what a reviewer signed off: title, text, featured image
	 * and every embedded attachment together with its AI label. Swapping an
	 * image for an AI one after the review changes the hash, so the approval
	 * visibly expires instead of covering content nobody looked at.
	 */
	public static function content_hash( int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$content = (string) $post->post_content;
		$media   = array();
		if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
			foreach ( array_unique( array_map( 'intval', $m[1] ) ) as $id ) {
				$media[ $id ] = self::is_flagged( $id ) ? '1' : '';
			}
		}
		ksort( $media );
		$data = array(
			'title'   => (string) $post->post_title,
			'content' => $content,
			'thumb'   => (int) get_post_thumbnail_id( $post_id ),
			'media'   => $media,
		);
		return hash( 'sha256', (string) wp_json_encode( $data ) );
	}

	/**
	 * Whether the review stamp still matches the post as it is now.
	 */
	public static function is_review_current( int $post_id ): bool {
		$stamp = self::content_review( $post_id );
		return null !== $stamp && '' !== $stamp['hash'] && hash_equals( $stamp['hash'], self::content_hash( $post_id ) );
	}

	/**
	 * Schema.org properties for one IPTC digital source type token: the
	 * schema.org enumeration value `digitalSourceType` expects, plus the IPTC
	 * vocabulary URI as a typed property, so both kinds of consumer are served.
	 *
	 * @param string $token trainedAlgorithmicMedia|compositeWithTrainedAlgorithmicMedia|digitalCapture|digitalCreation.
	 * @return array<string, mixed>
	 */
	public static function dst_schema( string $token ): array {
		return array(
			'digitalSourceType'  => 'https://schema.org/' . ucfirst( $token ) . 'DigitalSource',
			'additionalProperty' => array(
				'@type'      => 'PropertyValue',
				'propertyID' => 'IPTC:DigitalSourceType',
				'value'      => self::DST_CV_BASE . $token,
			),
		);
	}

	/**
	 * Normalize a checkbox-ish value to '1' or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_flag( $value ): string {
		return ( '1' === $value || 1 === $value || true === $value ) ? '1' : '';
	}

	/**
	 * Whether the attachment carries the confirmed AI label.
	 */
	public static function is_flagged( int $attachment_id ): bool {
		return '1' === get_post_meta( $attachment_id, self::KEY_FLAG, true );
	}

	/**
	 * Whether the attachment has an unconfirmed auto-detection.
	 */
	public static function is_detected( int $attachment_id ): bool {
		return '1' === get_post_meta( $attachment_id, self::KEY_DETECTED, true );
	}

	/**
	 * Normalize a non-AI declaration to its IPTC token or ''. Accepts the
	 * short forms `capture` and `creation` as well.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_human( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( in_array( $value, array( 'capture', strtolower( self::DST_CAPTURE ) ), true ) ) {
			return self::DST_CAPTURE;
		}
		if ( in_array( $value, array( 'creation', strtolower( self::DST_CREATION ) ), true ) ) {
			return self::DST_CREATION;
		}
		return '';
	}

	/**
	 * The attachment's non-AI declaration token, or '' when none was made.
	 */
	public static function human_type( int $attachment_id ): string {
		return self::sanitize_human( get_post_meta( $attachment_id, self::KEY_HUMAN, true ) );
	}

	/**
	 * Whether the attachment was actively declared as not AI-made.
	 */
	public static function is_human( int $attachment_id ): bool {
		return '' !== self::human_type( $attachment_id );
	}

	/**
	 * Declare an attachment as a camera photo or human digital work. The
	 * opposite of the AI label, so the label and any pending detection go;
	 * the scanner treats the declaration like a dismissed detection.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $type          capture|creation or the IPTC token.
	 * @param string $marked_by     manual|cli|rest|bulk.
	 */
	public static function mark_human( int $attachment_id, string $type, string $marked_by = 'manual' ): void {
		$token = self::sanitize_human( $type );
		if ( '' === $token ) {
			return;
		}
		delete_post_meta( $attachment_id, self::KEY_FLAG );
		delete_post_meta( $attachment_id, self::KEY_DETECTED );
		update_post_meta( $attachment_id, self::KEY_HUMAN, $token );
		update_post_meta( $attachment_id, self::KEY_MARKED_BY, sanitize_key( $marked_by ) );
		self::record( $attachment_id, 'human-' . ( self::DST_CAPTURE === $token ? 'capture' : 'creation' ), $marked_by );
	}

	/**
	 * Withdraw a non-AI declaration.
	 */
	public static function unmark_human( int $attachment_id ): void {
		if ( ! self::is_human( $attachment_id ) ) {
			return;
		}
		delete_post_meta( $attachment_id, self::KEY_HUMAN );
		self::record( $attachment_id, 'human-removed' );
	}

	/**
	 * Normalize a per-image badge position override to a known value or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize_badge_pos( $value ): string {
		$value   = sanitize_key( (string) $value );
		$allowed = array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'below', 'hidden' );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * Per-image badge position override ('' = use the site setting).
	 */
	public static function get_badge_position( int $attachment_id ): string {
		return self::sanitize_badge_pos( get_post_meta( $attachment_id, self::KEY_BADGE_POS, true ) );
	}

	/**
	 * Content type of the label: 'generated' or 'composite'.
	 */
	public static function get_type( int $attachment_id ): string {
		$type = (string) get_post_meta( $attachment_id, self::KEY_TYPE, true );
		return 'composite' === $type ? 'composite' : 'generated';
	}

	/**
	 * Detected generator name, if any.
	 */
	public static function get_generator( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, self::KEY_GENERATOR, true );
	}

	/**
	 * Set the confirmed AI label.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $marked_by     manual|auto|cli|context|rest.
	 * @param string $event         History event to record: 'flagged' or, when a
	 *                              reviewer approved a queued detection, 'confirmed'.
	 */
	public static function flag( int $attachment_id, string $marked_by = 'manual', string $event = 'flagged' ): void {
		/* An AI label and a "not AI" declaration cannot both stand. */
		delete_post_meta( $attachment_id, self::KEY_HUMAN );
		update_post_meta( $attachment_id, self::KEY_FLAG, '1' );
		update_post_meta( $attachment_id, self::KEY_MARKED_BY, sanitize_key( $marked_by ) );
		delete_post_meta( $attachment_id, self::KEY_DETECTED );
		self::record( $attachment_id, $event, $marked_by );
	}

	/**
	 * Remove the confirmed AI label (and any pending detection state).
	 */
	public static function unflag( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::KEY_FLAG );
		delete_post_meta( $attachment_id, self::KEY_DETECTED );
		delete_post_meta( $attachment_id, self::KEY_MARKED_BY );
		self::record( $attachment_id, 'unflagged' );
	}

	/**
	 * Store an unconfirmed detection for review.
	 *
	 * @param int                   $attachment_id Attachment ID.
	 * @param array<string, mixed>  $result        Detector result.
	 */
	public static function queue( int $attachment_id, array $result ): void {
		update_post_meta( $attachment_id, self::KEY_DETECTED, '1' );
		self::store_result( $attachment_id, $result );
		self::record( $attachment_id, 'queued', (string) ( $result['source'] ?? '' ) );
	}

	/**
	 * Persist the descriptive part of a detector result.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param array<string, mixed> $result        Detector result.
	 */
	public static function store_result( int $attachment_id, array $result ): void {
		update_post_meta( $attachment_id, self::KEY_TYPE, sanitize_key( (string) ( $result['type'] ?? 'generated' ) ) );
		update_post_meta( $attachment_id, self::KEY_SOURCE, sanitize_key( (string) ( $result['source'] ?? '' ) ) );
		update_post_meta( $attachment_id, self::KEY_GENERATOR, sanitize_text_field( (string) ( $result['generator'] ?? '' ) ) );
		update_post_meta( $attachment_id, self::KEY_CONFIDENCE, sanitize_key( (string) ( $result['confidence'] ?? '' ) ) );
		update_post_meta( $attachment_id, self::KEY_EVIDENCE, sanitize_textarea_field( mb_substr( (string) ( $result['evidence'] ?? '' ), 0, 500 ) ) );
	}

	/**
	 * Confirm a queued detection (review approval).
	 */
	public static function confirm( int $attachment_id ): void {
		if ( self::is_detected( $attachment_id ) ) {
			/*
			 * Recorded as its own event: "a person went through the review queue
			 * and approved this" is exactly what an audit asks about, and it
			 * reads differently from a label applied automatically.
			 */
			self::flag( $attachment_id, 'auto', 'confirmed' );
		}
	}

	/**
	 * Dismiss a queued detection (review rejection); re-scans will not re-queue.
	 */
	public static function dismiss( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::KEY_DETECTED );
		update_post_meta( $attachment_id, self::KEY_DISMISSED, '1' );
		self::record( $attachment_id, 'dismissed' );
	}

	/**
	 * Append one event to the attachment's history ring buffer.
	 *
	 * Who did what, and when: the audit trail an agency needs when a client
	 * asks why a file carries (or lost) its AI declaration. Kept in one meta
	 * row per attachment, capped, so no table and no cleanup job is involved.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $event         flagged|confirmed|unflagged|queued|dismissed|repaired|write-failed.
	 * @param string $source        Optional context, for example the detection source or 'cli'.
	 */
	public static function record( int $attachment_id, string $event, string $source = '' ): void {
		$log = json_decode( (string) get_post_meta( $attachment_id, self::KEY_HISTORY, true ), true );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			't' => time(),
			'e' => sanitize_key( $event ),
			'u' => get_current_user_id(),
			's' => sanitize_key( $source ),
		);

		if ( count( $log ) > self::HISTORY_LIMIT ) {
			$log = array_slice( $log, -self::HISTORY_LIMIT );
		}

		update_post_meta( $attachment_id, self::KEY_HISTORY, (string) wp_json_encode( array_values( $log ) ) );
	}

	/**
	 * Read the history of one attachment, oldest entry first.
	 *
	 * @return array<int, array{t:int, e:string, u:int, s:string}>
	 */
	public static function history( int $attachment_id ): array {
		$log = json_decode( (string) get_post_meta( $attachment_id, self::KEY_HISTORY, true ), true );
		if ( ! is_array( $log ) ) {
			return array();
		}

		$out = array();
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$out[] = array(
				't' => (int) ( $entry['t'] ?? 0 ),
				'e' => (string) ( $entry['e'] ?? '' ),
				'u' => (int) ( $entry['u'] ?? 0 ),
				's' => (string) ( $entry['s'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * The most recent history entry, or null when nothing was recorded yet.
	 *
	 * @return array{t:int, e:string, u:int, s:string}|null
	 */
	public static function last_change( int $attachment_id ): ?array {
		$log = self::history( $attachment_id );
		return array() === $log ? null : $log[ count( $log ) - 1 ];
	}

	/**
	 * Columns of one audit row, in export order.
	 *
	 * @return string[]
	 */
	public static function audit_columns(): array {
		return array( 'ID', 'file', 'status', 'type', 'source', 'generator', 'confidence', 'marked_by', 'last_event', 'last_event_at', 'last_event_user' );
	}

	/**
	 * Every labeled or pending attachment as a flat row, for the CSV export in
	 * the admin and for `wp transparai status`. One source, so the audit trail
	 * a client receives cannot differ between the two ways of asking for it.
	 *
	 * @param string $status flagged|detected|human|all.
	 * @return array<int, array<string, string|int>>
	 */
	public static function audit_rows( string $status = 'flagged' ): array {
		$meta_query = self::meta_query( in_array( $status, array( 'detected', 'human', 'all' ), true ) ? $status : '1' );

		$ids = ( new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- explicit audit export, run on request only.
				'meta_query'             => $meta_query,
			)
		) )->posts;

		$rows = array();
		foreach ( $ids as $id ) {
			$rows[] = self::audit_row( (int) $id );
		}

		return $rows;
	}

	/**
	 * One audit row for one attachment.
	 *
	 * @return array<string, string|int>
	 */
	public static function audit_row( int $attachment_id ): array {
		$last = self::last_change( $attachment_id );

		return array(
			'ID'              => $attachment_id,
			'file'            => (string) get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'status'          => self::is_flagged( $attachment_id ) ? 'flagged' : ( self::is_human( $attachment_id ) ? 'human' : 'detected' ),
			'type'            => self::is_human( $attachment_id ) ? self::human_type( $attachment_id ) : self::get_type( $attachment_id ),
			'source'          => (string) get_post_meta( $attachment_id, self::KEY_SOURCE, true ),
			'generator'       => self::get_generator( $attachment_id ),
			'confidence'      => (string) get_post_meta( $attachment_id, self::KEY_CONFIDENCE, true ),
			'marked_by'       => (string) get_post_meta( $attachment_id, self::KEY_MARKED_BY, true ),
			'last_event'      => null === $last ? '' : $last['e'],
			'last_event_at'   => null === $last || 0 === $last['t'] ? '' : gmdate( 'Y-m-d H:i:s', $last['t'] ),
			'last_event_user' => null === $last || 0 === $last['u'] ? '' : (string) $last['u'],
		);
	}

	/**
	 * Meta query fragment for the media library filters.
	 *
	 * @param string $value '1' flagged, '0' unflagged, 'detected' pending review,
	 *                      'all' flagged or pending.
	 * @return array<int|string, mixed>|null
	 */
	public static function meta_query( string $value ): ?array {
		if ( 'all' === $value ) {
			return array(
				'relation' => 'OR',
				array(
					'key'   => self::KEY_FLAG,
					'value' => '1',
				),
				array(
					'key'   => self::KEY_DETECTED,
					'value' => '1',
				),
				array(
					'key'     => self::KEY_HUMAN,
					'value'   => '',
					'compare' => '!=',
				),
			);
		}
		if ( 'human' === $value ) {
			return array(
				array(
					'key'     => self::KEY_HUMAN,
					'value'   => '',
					'compare' => '!=',
				),
			);
		}
		if ( 'labeled' === $value ) {
			/* Everything that carries an in-file declaration: AI label or non-AI declaration. */
			return array(
				'relation' => 'OR',
				array(
					'key'   => self::KEY_FLAG,
					'value' => '1',
				),
				array(
					'key'     => self::KEY_HUMAN,
					'value'   => '',
					'compare' => '!=',
				),
			);
		}
		if ( '1' === $value ) {
			return array(
				array(
					'key'   => self::KEY_FLAG,
					'value' => '1',
				),
			);
		}
		if ( 'detected' === $value ) {
			return array(
				array(
					'key'   => self::KEY_DETECTED,
					'value' => '1',
				),
			);
		}
		if ( '0' === $value ) {
			return array(
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array(
						'key'     => self::KEY_FLAG,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::KEY_FLAG,
						'value'   => '1',
						'compare' => '!=',
					),
				),
				array(
					'key'     => self::KEY_DETECTED,
					'compare' => 'NOT EXISTS',
				),
			);
		}
		return null;
	}

	/**
	 * Apply a bulk action to a list of attachment IDs.
	 *
	 * @param int[]  $ids    Attachment IDs.
	 * @param string $action flag|unflag|confirm|dismiss|human_capture|human_creation|human_remove.
	 * @return int Number of updated attachments.
	 */
	public static function bulk_apply( array $ids, string $action ): int {
		$count = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			switch ( $action ) {
				case 'flag':
					self::flag( $id );
					break;
				case 'unflag':
					self::unflag( $id );
					break;
				case 'confirm':
					self::confirm( $id );
					break;
				case 'dismiss':
					self::dismiss( $id );
					break;
				case 'human_capture':
					self::mark_human( $id, 'capture', 'bulk' );
					break;
				case 'human_creation':
					self::mark_human( $id, 'creation', 'bulk' );
					break;
				case 'human_remove':
					self::unmark_human( $id );
					break;
				default:
					continue 2;
			}
			++$count;
		}
		return $count;
	}
}
