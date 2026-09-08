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

		/* Per-post "content is AI-written" flag (the editor checkbox). */
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
					'sanitize_callback' => array( self::class, 'sanitize_flag' ),
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ): bool {
						return current_user_can( 'edit_post', (int) $post_id );
					},
				)
			);
		}
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
	 * @param string $status flagged|detected|all.
	 * @return array<int, array<string, string|int>>
	 */
	public static function audit_rows( string $status = 'flagged' ): array {
		$meta_query = self::meta_query( in_array( $status, array( 'detected', 'all' ), true ) ? $status : '1' );

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
			'status'          => self::is_flagged( $attachment_id ) ? 'flagged' : 'detected',
			'type'            => self::get_type( $attachment_id ),
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
	 * @param string $action flag|unflag|confirm|dismiss.
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
				default:
					continue 2;
			}
			++$count;
		}
		return $count;
	}
}
