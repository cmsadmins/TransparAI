<?php
/**
 * Delivery check: does the machine-readable declaration survive the way out?
 *
 * Writing the XMP block into a file is only half the job. Optimizing CDNs and
 * image proxies routinely re-encode images at the edge and drop every metadata
 * block while doing so, and from the admin that is invisible: the file on disk
 * is perfect, and what a visitor downloads is bare.
 *
 * The check fetches one image over its own public URL and reads the bytes that
 * are actually served. It is off by default, never runs on its own and never
 * talks to anything but this site: the target URL must resolve to the same host
 * as home_url(), otherwise nothing is requested at all.
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
 * Verifies that the in-file declaration reaches the visitor.
 */
final class TransparAI_Delivery {

	public const VERDICT_INTACT      = 'intact';
	public const VERDICT_STRIPPED    = 'stripped';
	public const VERDICT_UNREACHABLE = 'unreachable';
	public const VERDICT_UNMARKED    = 'unmarked';
	public const VERDICT_FOREIGN     = 'foreign-host';

	/**
	 * Register hooks (admin-only: the check never runs on a front-end request).
	 */
	public static function init(): void {
		add_action( 'wp_ajax_transparai_delivery', array( self::class, 'ajax_check' ) );
	}

	/**
	 * Whether the site owner switched the check on.
	 */
	public static function enabled(): bool {
		return TransparAI_Options::enabled( 'delivery_check' );
	}

	/**
	 * Check one attachment, or a sample, on explicit request.
	 */
	public static function ajax_check(): void {
		check_ajax_referer( 'transparai_bulk' );

		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'message' => __( 'The delivery check is switched off.', 'transparai' ) ), 403 );
		}

		$sample = isset( $_POST['sample'] ) ? absint( wp_unslash( $_POST['sample'] ) ) : 0;

		if ( $sample > 0 ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You are not allowed to do that.', 'transparai' ) ), 403 );
			}
			wp_send_json_success( self::check_sample( $sample ) );
		}

		$attachment_id = isset( $_POST['attachment'] ) ? absint( wp_unslash( $_POST['attachment'] ) ) : 0;
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'transparai' ) ), 400 );
		}

		$result = self::check( $attachment_id );
		self::remember( $attachment_id, $result['verdict'] );

		wp_send_json_success(
			array(
				'verdict' => $result['verdict'],
				'label'   => self::verdict_label( $result['verdict'] ),
				'message' => $result['message'],
			)
		);
	}

	/**
	 * Check one attachment.
	 *
	 * @return array{verdict:string, url:string, code:int, message:string}
	 */
	public static function check( int $attachment_id ): array {
		$url  = (string) wp_get_attachment_url( $attachment_id );
		$file = (string) get_attached_file( $attachment_id );

		if ( '' === $url || '' === $file || ! file_exists( $file ) ) {
			return self::result( self::VERDICT_UNREACHABLE, $url, 0, __( 'This attachment has no file.', 'transparai' ) );
		}

		if ( ! self::is_own_host( $url ) ) {
			/*
			 * Media served from a third-party host (an offloading plugin, an
			 * external bucket) is deliberately out of scope: this check exists
			 * to inspect our own delivery, not to request anything elsewhere.
			 */
			return self::result( self::VERDICT_FOREIGN, $url, 0, __( 'The file is served from another host, so it is not checked.', 'transparai' ) );
		}

		$head   = TransparAI_Parsers::read_head( $file, 64 );
		$format = null === $head ? '' : self::format_of( $head );
		if ( '' === $format || ! TransparAI_Writer::file_is_marked( $file ) ) {
			return self::result( self::VERDICT_UNMARKED, $url, 0, __( 'The file on disk carries no AI declaration, so there is nothing to compare.', 'transparai' ) );
		}

		/* Cache buster: we want what is produced now, not what a proxy stored earlier. */
		$response = wp_remote_get(
			add_query_arg( 'transparai-check', (string) time(), $url ),
			array(
				'timeout'   => 20,
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::result( self::VERDICT_UNREACHABLE, $url, 0, $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );

		if ( $code >= 400 || '' === $body ) {
			return self::result( self::VERDICT_UNREACHABLE, $url, $code, __( 'The image could not be fetched over its public URL.', 'transparai' ) );
		}

		$served = self::format_of( $body );
		if ( '' === $served ) {
			return self::result( self::VERDICT_UNREACHABLE, $url, $code, __( 'The response was not a readable image.', 'transparai' ) );
		}

		$xmp   = TransparAI_Writer::xmp_from_data( $body, $served );
		$terms = null === $xmp ? array() : TransparAI_Parsers::xmp_digital_source_types( $xmp );
		$ok    = in_array( 'trainedalgorithmicmedia', $terms, true ) || in_array( 'compositewithtrainedalgorithmicmedia', $terms, true );

		return self::result(
			$ok ? self::VERDICT_INTACT : self::VERDICT_STRIPPED,
			$url,
			$code,
			$ok
				? __( 'The declaration is present in the delivered file.', 'transparai' )
				: __( 'The delivered file carries no declaration. Something between WordPress and the visitor re-encodes the image, usually an optimizing CDN or a proxy.', 'transparai' )
		);
	}

	/**
	 * Check a sample of labeled attachments.
	 *
	 * @param int $limit How many files to look at.
	 * @return array{checked:int, intact:int, stripped:int, other:int}
	 */
	public static function check_sample( int $limit = 5 ): array {
		$ids = ( new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ),
				'fields'                 => 'ids',
				'orderby'                => 'rand',
				'posts_per_page'         => max( 1, min( 20, $limit ) ),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- explicit sample check, run on request only.
				'meta_query'             => TransparAI_Meta::meta_query( '1' ),
			)
		) )->posts;

		$summary = array(
			'checked'  => 0,
			'intact'   => 0,
			'stripped' => 0,
			'other'    => 0,
		);

		foreach ( $ids as $id ) {
			$verdict = self::check( (int) $id )['verdict'];
			++$summary['checked'];

			if ( self::VERDICT_INTACT === $verdict ) {
				++$summary['intact'];
			} elseif ( self::VERDICT_STRIPPED === $verdict ) {
				++$summary['stripped'];
			} else {
				++$summary['other'];
			}
		}

		return $summary;
	}

	/**
	 * Human-readable label for one verdict.
	 */
	public static function verdict_label( string $verdict ): string {
		switch ( $verdict ) {
			case self::VERDICT_INTACT:
				return __( 'Delivered with the declaration intact', 'transparai' );
			case self::VERDICT_STRIPPED:
				return __( 'Delivered without the declaration', 'transparai' );
			case self::VERDICT_UNMARKED:
				return __( 'Nothing to check', 'transparai' );
			case self::VERDICT_FOREIGN:
				return __( 'Served from another host', 'transparai' );
			default:
				return __( 'Could not be fetched', 'transparai' );
		}
	}

	/**
	 * The last recorded result of an attachment, or null.
	 *
	 * @return array{t:int, verdict:string}|null
	 */
	public static function last_result( int $attachment_id ): ?array {
		$stored = json_decode( (string) get_post_meta( $attachment_id, TransparAI_Meta::KEY_DELIVERY, true ), true );
		if ( ! is_array( $stored ) || ! isset( $stored['verdict'] ) ) {
			return null;
		}
		return array(
			't'       => (int) ( $stored['t'] ?? 0 ),
			'verdict' => (string) $stored['verdict'],
		);
	}

	/**
	 * Store a verdict and build the response array.
	 *
	 * @return array{verdict:string, url:string, code:int, message:string}
	 */
	private static function result( string $verdict, string $url, int $code, string $message ): array {
		return array(
			'verdict' => $verdict,
			'url'     => $url,
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Persist a verdict on the attachment and note it in the history.
	 */
	public static function remember( int $attachment_id, string $verdict ): void {
		update_post_meta(
			$attachment_id,
			TransparAI_Meta::KEY_DELIVERY,
			(string) wp_json_encode(
				array(
					't'       => time(),
					'verdict' => $verdict,
				)
			)
		);
		TransparAI_Meta::record( $attachment_id, 'delivery-' . $verdict, 'check' );
	}

	/**
	 * Container format of raw bytes, restricted to what we can read XMP from.
	 *
	 * @return string jpeg|png|webp|avif|'' (not readable here).
	 */
	private static function format_of( string $data ): string {
		$format = TransparAI_Parsers::sniff( $data );
		if ( 'bmff' === $format ) {
			return in_array( substr( $data, 8, 4 ), array( 'avif', 'avis' ), true ) ? 'avif' : '';
		}
		return in_array( $format, array( 'jpeg', 'png', 'webp' ), true ) ? $format : '';
	}

	/**
	 * Whether a URL points at this site.
	 */
	private static function is_own_host( string $url ): bool {
		$target = wp_parse_url( $url, PHP_URL_HOST );
		$home   = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $target ) && is_string( $home ) && strtolower( $target ) === strtolower( $home );
	}
}
