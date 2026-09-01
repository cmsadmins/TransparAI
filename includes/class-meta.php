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
 * @package TransparAI
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

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( self::class, 'register_meta' ) );
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
	 */
	public static function flag( int $attachment_id, string $marked_by = 'manual' ): void {
		update_post_meta( $attachment_id, self::KEY_FLAG, '1' );
		update_post_meta( $attachment_id, self::KEY_MARKED_BY, sanitize_key( $marked_by ) );
		delete_post_meta( $attachment_id, self::KEY_DETECTED );
	}

	/**
	 * Remove the confirmed AI label (and any pending detection state).
	 */
	public static function unflag( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::KEY_FLAG );
		delete_post_meta( $attachment_id, self::KEY_DETECTED );
		delete_post_meta( $attachment_id, self::KEY_MARKED_BY );
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
			self::flag( $attachment_id, 'auto' );
		}
	}

	/**
	 * Dismiss a queued detection (review rejection); re-scans will not re-queue.
	 */
	public static function dismiss( int $attachment_id ): void {
		delete_post_meta( $attachment_id, self::KEY_DETECTED );
		update_post_meta( $attachment_id, self::KEY_DISMISSED, '1' );
	}

	/**
	 * Meta query fragment for the media library filters.
	 *
	 * @param string $value '1' flagged, '0' unflagged, 'detected' pending review.
	 * @return array<int|string, mixed>|null
	 */
	public static function meta_query( string $value ): ?array {
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
