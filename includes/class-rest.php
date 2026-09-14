<?php
/**
 * REST API (namespace transparai/v1): the external interface for headless
 * setups, agency tooling and audits. The admin screens keep their own
 * admin-ajax endpoints; nothing here is public, every route needs an
 * authenticated user with upload rights, and every write checks the
 * capability on the object itself.
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
 * REST routes.
 */
final class TransparAI_REST {

	public const NAMESPACE = 'transparai/v1';

	private const ACTIONS  = array( 'flag', 'unflag', 'confirm', 'dismiss', 'human_capture', 'human_creation', 'human_remove' );
	private const STATUSES = array( 'flagged', 'detected', 'human', 'labeled', 'all' );

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Route definitions. Argument validation lives in the route args, so the
	 * REST server rejects bad input before a handler runs and /wp-json
	 * documents the API on its own.
	 */
	public static function register_routes(): void {
		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/media',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'list_media' ),
				'permission_callback' => array( self::class, 'can_manage_media' ),
				'args'                => array(
					'status'   => array(
						'type'    => 'string',
						'enum'    => self::STATUSES,
						'default' => 'flagged',
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				),
				'schema'              => array( self::class, 'item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/media/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'get_media' ),
					'permission_callback' => array( self::class, 'can_edit_item' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'update_media' ),
					'permission_callback' => array( self::class, 'can_edit_item' ),
					'args'                => array_merge(
						$id_arg,
						array(
							'action'    => array(
								'type'     => 'string',
								'enum'     => self::ACTIONS,
								'required' => true,
							),
							'generator' => array(
								'type'              => 'string',
								'sanitize_callback' => 'sanitize_text_field',
							),
							'type'      => array(
								'type' => 'string',
								'enum' => array( 'generated', 'composite' ),
							),
						)
					),
				),
				'schema' => array( self::class, 'item_schema' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/media/(?P<id>\d+)/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'scan_media' ),
				'permission_callback' => array( self::class, 'can_edit_item' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/report',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'report' ),
				'permission_callback' => array( self::class, 'can_manage_options' ),
				'args'                => array(
					'status' => array(
						'type'    => 'string',
						'enum'    => self::STATUSES,
						'default' => 'all',
					),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------- */

	/**
	 * Route gate: the media library capability.
	 */
	public static function can_manage_media(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Route gate for one attachment: library capability plus edit rights on
	 * that object, so a looser route never widens what a user can change.
	 *
	 * @param WP_REST_Request|mixed $request Request.
	 */
	public static function can_edit_item( $request ): bool {
		$id = (int) ( is_object( $request ) ? $request['id'] : 0 );
		return current_user_can( 'upload_files' )
			&& $id > 0
			&& 'attachment' === get_post_type( $id )
			&& current_user_can( 'edit_post', $id );
	}

	/**
	 * Route gate for the site-wide report.
	 */
	public static function can_manage_options(): bool {
		return current_user_can( 'manage_options' );
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	/**
	 * GET /media: paginated audit rows.
	 *
	 * @param WP_REST_Request|mixed $request Request.
	 * @return array<string, mixed>
	 */
	public static function list_media( $request ): array {
		$status   = (string) $request['status'];
		$page     = max( 1, (int) $request['page'] );
		$per_page = min( 100, max( 1, (int) $request['per_page'] ) );

		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => $per_page,
				'paged'                  => $page,
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded, paginated API query.
				'meta_query'             => TransparAI_Meta::meta_query( in_array( $status, self::STATUSES, true ) ? $status : '1' ) ?? TransparAI_Meta::meta_query( '1' ),
			)
		);

		$items = array();
		foreach ( $query->posts as $id ) {
			$items[] = TransparAI_Meta::audit_row( (int) $id );
		}

		return array(
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => (int) $query->found_posts,
		);
	}

	/**
	 * GET /media/{id}: row, history and what the files carry.
	 *
	 * @param WP_REST_Request|mixed $request Request.
	 * @return array<string, mixed>
	 */
	public static function get_media( $request ): array {
		$id = (int) $request['id'];
		return self::item( $id, true );
	}

	/**
	 * POST /media/{id}: apply one action.
	 *
	 * @param WP_REST_Request|mixed $request Request.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function update_media( $request ) {
		$id     = (int) $request['id'];
		$action = (string) $request['action'];
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			return new WP_Error( 'transparai_unknown_action', __( 'Unknown action.', 'transparai' ), array( 'status' => 400 ) );
		}

		$generator = (string) ( $request['generator'] ?? '' );
		if ( '' !== $generator && in_array( $action, array( 'flag', 'confirm' ), true ) ) {
			update_post_meta( $id, TransparAI_Meta::KEY_GENERATOR, sanitize_text_field( $generator ) );
		}
		$type = (string) ( $request['type'] ?? '' );
		if ( 'composite' === $type || 'generated' === $type ) {
			update_post_meta( $id, TransparAI_Meta::KEY_TYPE, $type );
		}

		switch ( $action ) {
			case 'flag':
				TransparAI_Meta::flag( $id, 'rest' );
				break;
			case 'unflag':
				TransparAI_Meta::unflag( $id );
				break;
			case 'confirm':
				TransparAI_Meta::confirm( $id );
				break;
			case 'dismiss':
				TransparAI_Meta::dismiss( $id );
				break;
			case 'human_capture':
				TransparAI_Meta::mark_human( $id, 'capture', 'rest' );
				break;
			case 'human_creation':
				TransparAI_Meta::mark_human( $id, 'creation', 'rest' );
				break;
			case 'human_remove':
				TransparAI_Meta::unmark_human( $id );
				break;
		}

		return self::item( $id, false );
	}

	/**
	 * POST /media/{id}/scan: re-check the file.
	 *
	 * @param WP_REST_Request|mixed $request Request.
	 * @return array<string, mixed>
	 */
	public static function scan_media( $request ): array {
		$id   = (int) $request['id'];
		$scan = TransparAI_Scanner::scan_attachment( $id );
		return array(
			'status' => $scan['status'],
			'result' => $scan['result'],
			'item'   => self::item( $id, false ),
		);
	}

	/**
	 * GET /report: the canonical audit record with its document hash.
	 *
	 * @param WP_REST_Request|mixed $request Request.
	 * @return array<string, mixed>
	 */
	public static function report( $request ): array {
		return TransparAI_Meta::report( (string) $request['status'] );
	}

	/**
	 * One attachment as the API returns it.
	 *
	 * @return array<string, mixed>
	 */
	private static function item( int $id, bool $with_files ): array {
		$item = TransparAI_Meta::audit_row( $id );

		$item['human']         = TransparAI_Meta::human_type( $id );
		$item['detected']      = TransparAI_Meta::is_detected( $id );
		$item['badge_pos']     = TransparAI_Meta::get_badge_position( $id );
		$item['write_error']   = '' !== (string) get_post_meta( $id, TransparAI_Meta::KEY_WRITE_ERROR, true );
		$item['history']       = TransparAI_Meta::history( $id );
		$item['expected_type'] = TransparAI_Writer::expected_token( $id );

		if ( $with_files ) {
			$inspect       = TransparAI_Writer::inspect( $id );
			$item['files'] = $inspect['files'];
			$item['terms'] = $inspect['terms'];
		}
		return $item;
	}

	/**
	 * Schema of one media item (self-description for /wp-json).
	 *
	 * @return array<string, mixed>
	 */
	public static function item_schema(): array {
		$string = array( 'type' => 'string' );
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'transparai-media',
			'type'       => 'object',
			'properties' => array(
				'ID'            => array( 'type' => 'integer' ),
				'file'          => $string,
				'status'        => array(
					'type' => 'string',
					'enum' => array( 'flagged', 'detected', 'human' ),
				),
				'type'          => $string,
				'source'        => $string,
				'generator'     => $string,
				'confidence'    => $string,
				'marked_by'     => $string,
				'last_event'    => $string,
				'human'         => $string,
				'detected'      => array( 'type' => 'boolean' ),
				'history'       => array( 'type' => 'array' ),
				'expected_type' => $string,
			),
		);
	}
}
