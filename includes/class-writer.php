<?php
/**
 * Machine-readable labeling: writes the IPTC DigitalSourceType as XMP into
 * JPEG (APP1), PNG (iTXt) and WebP (RIFF) files — original plus every size
 * variant — and optionally mirrors it into IPTC-IIM (JPEG APP13).
 *
 * Foreign metadata is preserved: when a file already carries an XMP packet,
 * the declaration is MERGED in as an own rdf:Description block tagged with
 * rdf:about="urn:transparai:dst"; unmarking removes exactly what this plugin
 * wrote and nothing else. All writes are atomic (temp file, structural
 * validation, rename).
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XMP/IIM writer.
 */
final class TransparAI_Writer {

	private const DST_URI_GENERATED = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';
	private const DST_URI_COMPOSITE = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia';
	private const MARKER_ABOUT      = 'urn:transparai:dst';

	/**
	 * Register hooks: keep files in sync with the flag meta.
	 */
	public static function init(): void {
		add_action( 'added_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta_change' ), 10, 3 );
	}

	/**
	 * Meta change dispatcher.
	 *
	 * @param int|int[] $meta_id   Meta ID(s).
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public static function on_meta_change( $meta_id, $object_id, $meta_key ): void {
		if ( TransparAI_Meta::KEY_FLAG === $meta_key ) {
			self::sync_attachment( (int) $object_id );
		}
	}

	/**
	 * Bring all files of an attachment in line with its flag state.
	 *
	 * @return array{written:int, removed:int, failed:int, skipped:int}
	 */
	public static function sync_attachment( int $attachment_id ): array {
		$stats = array(
			'written' => 0,
			'removed' => 0,
			'failed'  => 0,
			'skipped' => 0,
		);

		if ( ! TransparAI_Options::enabled( 'write_xmp' ) ) {
			return $stats;
		}

		$flagged = TransparAI_Meta::is_flagged( $attachment_id );
		$type    = TransparAI_Meta::get_type( $attachment_id );

		foreach ( self::attachment_files( $attachment_id ) as $path ) {
			$format = self::writable_format( $path );
			if ( '' === $format ) {
				++$stats['skipped'];
				continue;
			}
			if ( ! wp_is_writable( $path ) ) {
				++$stats['failed'];
				continue;
			}
			$ok = $flagged
				? self::write_file( $path, $format, $type )
				: self::remove_file( $path, $format );
			if ( $ok ) {
				++$stats[ $flagged ? 'written' : 'removed' ];
			} else {
				++$stats['failed'];
			}
		}

		TransparAI_Repair::remember( $attachment_id );

		return $stats;
	}

	/**
	 * All existing files of an attachment (original, original_image, sizes).
	 *
	 * @return string[]
	 */
	public static function attachment_files( int $attachment_id ): array {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return array();
		}
		$paths = array( $file );
		/** @var array<string, mixed>|false $meta */
		$meta = wp_get_attachment_metadata( $attachment_id );
		$dir  = dirname( $file );
		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['original_image'] ) && is_string( $meta['original_image'] ) ) {
				$paths[] = $dir . '/' . $meta['original_image'];
			}
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$paths[] = $dir . '/' . $size['file'];
					}
				}
			}
		}
		return array_values( array_filter( array_unique( $paths ), 'file_exists' ) );
	}

	/**
	 * Whether a single file currently declares an AI DigitalSourceType.
	 * Used by the integrity verification (auto-repair).
	 */
	public static function file_is_marked( string $path ): bool {
		$format = self::writable_format( $path );
		if ( '' === $format ) {
			return true; // Unsupported formats are never "missing" their mark.
		}
		$xmp = self::extract_xmp( $path, $format );
		if ( null === $xmp ) {
			return false;
		}
		$terms = TransparAI_Parsers::xmp_digital_source_types( $xmp );
		return in_array( 'trainedalgorithmicmedia', $terms, true )
			|| in_array( 'compositewithtrainedalgorithmicmedia', $terms, true );
	}

	/* ---------------------------------------------------------------------
	 * Packet building / merging
	 * ------------------------------------------------------------------- */

	/**
	 * The rdf:Description block this plugin injects (identifiable marker).
	 */
	private static function description_block( string $type ): string {
		$uri = 'composite' === $type ? self::DST_URI_COMPOSITE : self::DST_URI_GENERATED;
		return '<rdf:Description rdf:about="' . self::MARKER_ABOUT . '"'
			. ' xmlns:Iptc4xmpExt="http://iptc.org/std/Iptc4xmpExt/2008-02-29/">'
			. '<Iptc4xmpExt:DigitalSourceType>' . $uri . '</Iptc4xmpExt:DigitalSourceType>'
			. '</rdf:Description>';
	}

	/**
	 * A complete standalone XMP packet (used when the file has none).
	 */
	private static function full_packet( string $type ): string {
		return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="TransparAI/' . TRANSPARAI_VERSION . '">' . "\n"
			. ' <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n"
			. '  ' . self::description_block( $type ) . "\n"
			. ' </rdf:RDF>' . "\n"
			. '</x:xmpmeta>' . "\n"
			. str_repeat( str_repeat( ' ', 63 ) . "\n", 2 )
			. '<?xpacket end="w"?>';
	}

	/**
	 * Merge the declaration into an existing packet (or report no-op/failure).
	 *
	 * @return array{action:string, xmp:string} action: keep|replace.
	 */
	private static function merge_packet( string $xmp, string $type ): array {
		$terms   = TransparAI_Parsers::xmp_digital_source_types( $xmp );
		$ai_term = 'composite' === $type ? 'compositewithtrainedalgorithmicmedia' : 'trainedalgorithmicmedia';
		if ( in_array( $ai_term, $terms, true ) ) {
			return array(
				'action' => 'keep',
				'xmp'    => $xmp,
			);
		}

		// Already injected with the other type? Replace our block.
		$stripped = self::strip_own_block( $xmp );

		$pos = strripos( $stripped, '</rdf:RDF>' );
		if ( false === $pos ) {
			// Packet without an rdf:RDF close is unusual — leave it alone and
			// signal that a standalone packet cannot be merged.
			return array(
				'action' => 'keep',
				'xmp'    => $xmp,
			);
		}

		$merged = substr( $stripped, 0, $pos ) . self::description_block( $type ) . substr( $stripped, $pos );
		return array(
			'action' => 'replace',
			'xmp'    => $merged,
		);
	}

	/**
	 * Remove the plugin's own injected description block from a packet.
	 */
	private static function strip_own_block( string $xmp ): string {
		$pattern = '#<rdf:Description rdf:about="' . preg_quote( self::MARKER_ABOUT, '#' ) . '".*?</rdf:Description>\s*#s';
		return (string) preg_replace( $pattern, '', $xmp );
	}

	/**
	 * Whether a packet is entirely ours (safe to drop on unmark).
	 */
	private static function is_own_packet( string $xmp ): bool {
		return str_contains( $xmp, 'x:xmptk="TransparAI/' );
	}

	/* ---------------------------------------------------------------------
	 * Per-format write/remove
	 * ------------------------------------------------------------------- */

	/**
	 * Detect the writable container format of a file.
	 *
	 * @return string jpeg|png|webp|'' (unsupported).
	 */
	private static function writable_format( string $path ): string {
		$head = TransparAI_Parsers::read_head( $path, 64 );
		if ( null === $head ) {
			return '';
		}
		$format = TransparAI_Parsers::sniff( $head );
		return in_array( $format, array( 'jpeg', 'png', 'webp' ), true ) ? $format : '';
	}

	/**
	 * Extract the current XMP packet of a file (full read for WebP tails).
	 */
	private static function extract_xmp( string $path, string $format ): ?string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local media file, not a remote request.
		$data = file_get_contents( $path );
		if ( false === $data ) {
			return null;
		}
		switch ( $format ) {
			case 'jpeg':
				$segments = TransparAI_Parsers::jpeg_segments( $data );
				return null === $segments ? null : TransparAI_Parsers::jpeg_xmp( $segments );
			case 'png':
				$chunks = TransparAI_Parsers::png_chunks( $data );
				return null === $chunks ? null : TransparAI_Parsers::png_xmp( $chunks );
			case 'webp':
				$chunks = TransparAI_Parsers::webp_chunks( $data );
				return null === $chunks ? null : TransparAI_Parsers::webp_xmp( $chunks );
		}
		return null;
	}

	/**
	 * Write the declaration into one file.
	 */
	public static function write_file( string $path, string $format, string $type ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local media file, not a remote request.
		$data = file_get_contents( $path );
		if ( false === $data ) {
			return false;
		}

		switch ( $format ) {
			case 'jpeg':
				return self::jpeg_write( $path, $data, $type );
			case 'png':
				return self::png_write( $path, $data, $type );
			case 'webp':
				return self::webp_write( $path, $data, $type );
		}
		return false;
	}

	/**
	 * Remove the plugin's declaration from one file.
	 */
	public static function remove_file( string $path, string $format ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local media file, not a remote request.
		$data = file_get_contents( $path );
		if ( false === $data ) {
			return false;
		}

		switch ( $format ) {
			case 'jpeg':
				return self::jpeg_remove( $path, $data );
			case 'png':
				return self::png_remove( $path, $data );
			case 'webp':
				return self::webp_remove( $path, $data );
		}
		return false;
	}

	/* --- JPEG ---------------------------------------------------------- */

	private static function jpeg_write( string $path, string $data, string $type ): bool {
		$segments = TransparAI_Parsers::jpeg_segments( $data );
		if ( null === $segments ) {
			return false;
		}

		$existing = TransparAI_Parsers::jpeg_xmp( $segments );
		if ( null !== $existing ) {
			$merge = self::merge_packet( $existing, $type );
			if ( 'keep' === $merge['action'] ) {
				return self::jpeg_maybe_add_iim( $path, $data, $segments, $type, false );
			}
			$new_payload = TransparAI_Parsers::XMP_HEADER_JPEG . $merge['xmp'];
			if ( strlen( $new_payload ) + 2 > 65535 ) {
				return false;
			}
			$replaced = false;
			foreach ( $segments as $i => $segment ) {
				if ( 0xE1 === $segment['marker'] && str_starts_with( $segment['payload'], TransparAI_Parsers::XMP_HEADER_JPEG ) ) {
					$segments[ $i ]['bytes']   = "\xFF\xE1" . pack( 'n', strlen( $new_payload ) + 2 ) . $new_payload;
					$segments[ $i ]['payload'] = $new_payload;
					$replaced                  = true;
					break;
				}
			}
			if ( ! $replaced ) {
				return false;
			}
			return self::jpeg_maybe_add_iim( $path, TransparAI_Parsers::jpeg_build( $segments ), $segments, $type, true );
		}

		$packet  = self::full_packet( $type );
		$payload = TransparAI_Parsers::XMP_HEADER_JPEG . $packet;
		if ( strlen( $payload ) + 2 > 65535 ) {
			return false;
		}
		$app1 = array(
			'marker'  => 0xE1,
			'bytes'   => "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload,
			'payload' => $payload,
		);

		$out      = array();
		$inserted = false;
		foreach ( $segments as $segment ) {
			$is_app = is_int( $segment['marker'] ) && $segment['marker'] >= 0xE0 && $segment['marker'] <= 0xEF;
			if ( ! $inserted && ! $is_app && 0xD8 !== $segment['marker'] ) {
				$out[]    = $app1;
				$inserted = true;
			}
			$out[] = $segment;
		}
		if ( ! $inserted ) {
			return false;
		}

		return self::jpeg_maybe_add_iim( $path, TransparAI_Parsers::jpeg_build( $out ), $out, $type, true );
	}

	/**
	 * Optionally mirror the declaration into a self-built APP13 (IPTC-IIM)
	 * segment — only when the file has no foreign APP13 (corruption safety),
	 * then perform the atomic write.
	 *
	 * @param string $path     Target path.
	 * @param string $data     Current (possibly already XMP-modified) bytes.
	 * @param array  $segments Segment list matching $data.
	 * @param string $type     generated|composite.
	 * @param bool   $dirty    Whether $data differs from the file on disk.
	 */
	private static function jpeg_maybe_add_iim( string $path, string $data, array $segments, string $type, bool $dirty ): bool {
		if ( TransparAI_Options::enabled( 'write_iim' ) && ! TransparAI_Parsers::jpeg_has_app13( $segments ) ) {
			$app13 = self::build_app13( $type );
			$out   = array();
			$done  = false;
			foreach ( $segments as $segment ) {
				$is_app = is_int( $segment['marker'] ) && $segment['marker'] >= 0xE0 && $segment['marker'] <= 0xEF;
				if ( ! $done && ! $is_app && 0xD8 !== $segment['marker'] ) {
					$out[] = array(
						'marker'  => 0xED,
						'bytes'   => $app13,
						'payload' => substr( $app13, 4 ),
					);
					$done  = true;
				}
				$out[] = $segment;
			}
			if ( $done ) {
				$data  = TransparAI_Parsers::jpeg_build( $out );
				$dirty = true;
			}
		}

		if ( ! $dirty ) {
			return true;
		}
		return self::atomic_write( $path, $data, 'jpeg' );
	}

	/**
	 * Build a minimal APP13 segment (Photoshop IRB, IPTC-IIM 1:90, 2:0, 2:40).
	 */
	private static function build_app13( string $type ): string {
		$token = 'DigitalSourceType='
			. ( 'composite' === $type ? 'compositeWithTrainedAlgorithmicMedia' : 'trainedAlgorithmicMedia' );

		$iim  = "\x1C\x01\x5A" . pack( 'n', 3 ) . "\x1B\x25\x47";     // 1:90 coded character set = UTF-8.
		$iim .= "\x1C\x02\x00" . pack( 'n', 2 ) . "\x00\x04";          // 2:0 record version 4.
		$iim .= "\x1C\x02\x28" . pack( 'n', strlen( $token ) ) . $token; // 2:40 special instructions.

		$resource = "8BIM\x04\x04\x00\x00" . pack( 'N', strlen( $iim ) ) . $iim;
		if ( strlen( $iim ) % 2 ) {
			$resource .= "\x00";
		}

		$payload = TransparAI_Parsers::PSIR_HEADER . $resource;
		return "\xFF\xED" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
	}

	/**
	 * Whether an APP13 payload is exactly the one this plugin builds.
	 */
	private static function is_own_app13( string $payload ): bool {
		if ( ! str_starts_with( $payload, TransparAI_Parsers::PSIR_HEADER ) ) {
			return false;
		}
		return str_contains( $payload, 'DigitalSourceType=trainedAlgorithmicMedia' )
			|| str_contains( $payload, 'DigitalSourceType=compositeWithTrainedAlgorithmicMedia' );
	}

	private static function jpeg_remove( string $path, string $data ): bool {
		$segments = TransparAI_Parsers::jpeg_segments( $data );
		if ( null === $segments ) {
			return false;
		}

		$dirty = false;
		$out   = array();
		foreach ( $segments as $segment ) {
			if ( 0xE1 === $segment['marker'] && str_starts_with( $segment['payload'], TransparAI_Parsers::XMP_HEADER_JPEG ) ) {
				$xmp = substr( $segment['payload'], strlen( TransparAI_Parsers::XMP_HEADER_JPEG ) );
				if ( self::is_own_packet( $xmp ) ) {
					$dirty = true;
					continue; // Drop our standalone packet.
				}
				$stripped = self::strip_own_block( $xmp );
				if ( $stripped !== $xmp ) {
					$payload            = TransparAI_Parsers::XMP_HEADER_JPEG . $stripped;
					$segment['bytes']   = "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
					$segment['payload'] = $payload;
					$dirty              = true;
				}
				$out[] = $segment;
				continue;
			}
			if ( 0xED === $segment['marker'] && self::is_own_app13( $segment['payload'] ) ) {
				$dirty = true;
				continue; // Drop our own IIM segment.
			}
			$out[] = $segment;
		}

		if ( ! $dirty ) {
			return true;
		}
		return self::atomic_write( $path, TransparAI_Parsers::jpeg_build( $out ), 'jpeg' );
	}

	/* --- PNG ----------------------------------------------------------- */

	private static function png_write( string $path, string $data, string $type ): bool {
		$chunks = TransparAI_Parsers::png_chunks( $data );
		if ( null === $chunks || array() === $chunks || 'IHDR' !== $chunks[0]['type'] ) {
			return false;
		}

		$existing_index = null;
		foreach ( $chunks as $i => $chunk ) {
			if ( 'iTXt' === $chunk['type'] && str_starts_with( $chunk['data'], "XML:com.adobe.xmp\x00" ) ) {
				$existing_index = $i;
				break;
			}
		}

		if ( null !== $existing_index ) {
			$itxt = $chunks[ $existing_index ]['data'];
			$xmp  = self::itxt_xmp_payload( $itxt );
			if ( null === $xmp ) {
				return false;
			}
			$merge = self::merge_packet( $xmp, $type );
			if ( 'keep' === $merge['action'] ) {
				return true;
			}
			$chunks[ $existing_index ]['data'] = self::build_itxt_xmp( $merge['xmp'] );
		} else {
			$new_chunk = array(
				'type' => 'iTXt',
				'data' => self::build_itxt_xmp( self::full_packet( $type ) ),
			);
			array_splice( $chunks, 1, 0, array( $new_chunk ) );
		}

		return self::atomic_write( $path, TransparAI_Parsers::png_build( $chunks ), 'png' );
	}

	private static function png_remove( string $path, string $data ): bool {
		$chunks = TransparAI_Parsers::png_chunks( $data );
		if ( null === $chunks ) {
			return false;
		}

		$dirty = false;
		$out   = array();
		foreach ( $chunks as $chunk ) {
			if ( 'iTXt' === $chunk['type'] && str_starts_with( $chunk['data'], "XML:com.adobe.xmp\x00" ) ) {
				$xmp = self::itxt_xmp_payload( $chunk['data'] );
				if ( null !== $xmp ) {
					if ( self::is_own_packet( $xmp ) ) {
						$dirty = true;
						continue;
					}
					$stripped = self::strip_own_block( $xmp );
					if ( $stripped !== $xmp ) {
						$chunk['data'] = self::build_itxt_xmp( $stripped );
						$dirty         = true;
					}
				}
			}
			$out[] = $chunk;
		}

		if ( ! $dirty ) {
			return true;
		}
		return self::atomic_write( $path, TransparAI_Parsers::png_build( $out ), 'png' );
	}

	/**
	 * iTXt payload for an uncompressed XMP packet.
	 */
	private static function build_itxt_xmp( string $xmp ): string {
		return "XML:com.adobe.xmp\x00\x00\x00\x00\x00" . $xmp;
	}

	/**
	 * Extract the XMP text from an XML:com.adobe.xmp iTXt payload.
	 */
	private static function itxt_xmp_payload( string $itxt ): ?string {
		$rest = substr( $itxt, strlen( "XML:com.adobe.xmp\x00" ) );
		if ( strlen( $rest ) < 2 ) {
			return null;
		}
		$comp_flag = ord( $rest[0] );
		$rest      = substr( $rest, 2 );
		$lang_end  = strpos( $rest, "\x00" );
		if ( false === $lang_end ) {
			return null;
		}
		$rest      = substr( $rest, $lang_end + 1 );
		$trans_end = strpos( $rest, "\x00" );
		if ( false === $trans_end ) {
			return null;
		}
		$text = substr( $rest, $trans_end + 1 );
		if ( 1 === $comp_flag ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt chunk data must not raise a warning.
			$inflated = @gzuncompress( $text, 1048576 );
			if ( false === $inflated ) {
				return null;
			}
			$text = $inflated;
		}
		return $text;
	}

	/* --- WebP ---------------------------------------------------------- */

	private static function webp_write( string $path, string $data, string $type ): bool {
		$chunks = TransparAI_Parsers::webp_chunks( $data );
		if ( null === $chunks || array() === $chunks ) {
			return false;
		}

		$xmp_index = null;
		foreach ( $chunks as $i => $chunk ) {
			if ( 'XMP ' === $chunk['fourcc'] ) {
				$xmp_index = $i;
				break;
			}
		}

		if ( null !== $xmp_index ) {
			$merge = self::merge_packet( $chunks[ $xmp_index ]['data'], $type );
			if ( 'keep' === $merge['action'] ) {
				return true;
			}
			$chunks[ $xmp_index ]['data'] = $merge['xmp'];
		} else {
			$chunks[] = array(
				'fourcc' => 'XMP ',
				'data'   => self::full_packet( $type ),
			);
		}

		$chunks = self::webp_ensure_vp8x( $chunks, true );
		if ( null === $chunks ) {
			return false;
		}

		return self::atomic_write( $path, TransparAI_Parsers::webp_build( $chunks ), 'webp' );
	}

	private static function webp_remove( string $path, string $data ): bool {
		$chunks = TransparAI_Parsers::webp_chunks( $data );
		if ( null === $chunks ) {
			return false;
		}

		$dirty = false;
		$out   = array();
		foreach ( $chunks as $chunk ) {
			if ( 'XMP ' === $chunk['fourcc'] ) {
				$xmp = $chunk['data'];
				if ( self::is_own_packet( $xmp ) ) {
					$dirty = true;
					continue;
				}
				$stripped = self::strip_own_block( $xmp );
				if ( $stripped !== $xmp ) {
					$chunk['data'] = $stripped;
					$dirty         = true;
				}
				$out[] = $chunk;
				continue;
			}
			$out[] = $chunk;
		}

		if ( ! $dirty ) {
			return true;
		}

		$has_xmp = false;
		foreach ( $out as $chunk ) {
			if ( 'XMP ' === $chunk['fourcc'] ) {
				$has_xmp = true;
				break;
			}
		}
		$final = self::webp_ensure_vp8x( $out, $has_xmp );
		if ( null === $final ) {
			return false;
		}

		return self::atomic_write( $path, TransparAI_Parsers::webp_build( $final ), 'webp' );
	}

	/**
	 * Ensure a correct VP8X chunk exists and its XMP flag matches reality.
	 *
	 * @param array $chunks  RIFF chunks.
	 * @param bool  $has_xmp Whether an XMP chunk is present.
	 * @return array|null
	 */
	private static function webp_ensure_vp8x( array $chunks, bool $has_xmp ): ?array {
		$vp8x_index = null;
		foreach ( $chunks as $i => $chunk ) {
			if ( 'VP8X' === $chunk['fourcc'] ) {
				$vp8x_index = $i;
				break;
			}
		}

		if ( null !== $vp8x_index ) {
			$payload = $chunks[ $vp8x_index ]['data'];
			if ( strlen( $payload ) < 10 ) {
				return null;
			}
			$flags                         = ord( $payload[0] );
			$flags                         = $has_xmp ? ( $flags | 0x04 ) : ( $flags & ~0x04 );
			$chunks[ $vp8x_index ]['data'] = chr( $flags ) . substr( $payload, 1 );
			return $chunks;
		}

		if ( ! $has_xmp ) {
			return $chunks;
		}

		$vp8x = TransparAI_Parsers::webp_make_vp8x( $chunks );
		if ( null === $vp8x ) {
			return null;
		}
		array_unshift(
			$chunks,
			array(
				'fourcc' => 'VP8X',
				'data'   => $vp8x,
			)
		);
		return $chunks;
	}

	/* ---------------------------------------------------------------------
	 * Atomic write
	 * ------------------------------------------------------------------- */

	/**
	 * Validate the rebuilt bytes structurally, then atomically replace the file.
	 */
	private static function atomic_write( string $path, string $data, string $format ): bool {
		switch ( $format ) {
			case 'jpeg':
				if ( null === TransparAI_Parsers::jpeg_segments( $data ) ) {
					return false;
				}
				break;
			case 'png':
				$chunks = TransparAI_Parsers::png_chunks( $data );
				if ( null === $chunks || array() === $chunks || 'IEND' !== $chunks[ count( $chunks ) - 1 ]['type'] ) {
					return false;
				}
				break;
			case 'webp':
				$chunks = TransparAI_Parsers::webp_chunks( $data );
				if ( null === $chunks ) {
					return false;
				}
				$riff = unpack( 'V', substr( $data, 4, 4 ) );
				if ( strlen( $data ) !== 8 + $riff[1] ) {
					return false;
				}
				break;
			default:
				return false;
		}

		$tmp = $path . '.transparai-tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- atomic replace of a local media file; WP_Filesystem cannot guarantee atomicity.
		if ( file_put_contents( $tmp, $data ) !== strlen( $data ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validation only; failure path is handled.
		if ( function_exists( 'getimagesize' ) && false === @getimagesize( $tmp ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		// rename() on purpose: the replacement must be atomic so a concurrent
		// request can never read a half-written image file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- see above; failure path handled.
		if ( ! @rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );
			return false;
		}
		return true;
	}
}
