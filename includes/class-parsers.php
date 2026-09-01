<?php
/**
 * Pure-PHP container parsers: JPEG segments, PNG chunks, WebP RIFF,
 * ISO-BMFF boxes, ID3v2 frames, XMP packet handling.
 *
 * Deliberately WordPress-free (static, no hooks, no I/O side effects) so the
 * detection and writing logic is unit-testable without a WordPress install.
 * Detection never runs regexes over raw file bytes; it extracts real metadata
 * blocks first and matches on those.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Container parsing toolbox.
 */
final class TransparAI_Parsers {

	public const XMP_HEADER_JPEG = "http://ns.adobe.com/xap/1.0/\x00";
	public const EXIF_HEADER     = "Exif\x00\x00";
	public const PSIR_HEADER     = "Photoshop 3.0\x00";

	public const C2PA_BMFF_UUID = "\xd8\xfe\xc3\xd6\x1b\x0e\x48\x3c\x92\x97\x58\x28\x87\x7e\xc4\x81";
	public const XMP_BMFF_UUID  = "\xbe\x7a\xcf\xcb\x97\xa9\x42\xe8\x9c\x71\x99\x94\x91\xe3\xaf\xac";

	/**
	 * Number of bytes read from the head (and, for WebP, the tail) of a file.
	 */
	public const READ_BYTES = 524288;

	/**
	 * Read up to READ_BYTES from the start of a file.
	 */
	public static function read_head( string $path, int $bytes = self::READ_BYTES ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local media file, not a remote request.
		$data = file_get_contents( $path, false, null, 0, $bytes );
		return false === $data ? null : $data;
	}

	/**
	 * Read up to $bytes from the end of a file.
	 */
	public static function read_tail( string $path, int $bytes = self::READ_BYTES ): ?string {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$size = filesize( $path );
		if ( false === $size || $size <= 0 ) {
			return null;
		}
		$offset = max( 0, $size - $bytes );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local media file, not a remote request.
		$data = file_get_contents( $path, false, null, $offset, $bytes );
		return false === $data ? null : $data;
	}

	/**
	 * Sniff the container format from leading bytes.
	 *
	 * @return string jpeg|png|webp|bmff|mp3|'' (unknown).
	 */
	public static function sniff( string $data ): string {
		if ( strlen( $data ) < 12 ) {
			return '';
		}
		if ( "\xFF\xD8" === substr( $data, 0, 2 ) ) {
			return 'jpeg';
		}
		if ( "\x89PNG\r\n\x1a\n" === substr( $data, 0, 8 ) ) {
			return 'png';
		}
		if ( 'RIFF' === substr( $data, 0, 4 ) && 'WEBP' === substr( $data, 8, 4 ) ) {
			return 'webp';
		}
		if ( 'ftyp' === substr( $data, 4, 4 ) ) {
			return 'bmff';
		}
		if ( 'ID3' === substr( $data, 0, 3 ) || ( "\xFF" === $data[0] && 0xE0 === ( ord( $data[1] ) & 0xE0 ) ) ) {
			return 'mp3';
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * JPEG
	 * ------------------------------------------------------------------- */

	/**
	 * Split a JPEG byte stream into its segments.
	 *
	 * Each entry: array{marker:int|string, bytes:string, payload:string}.
	 * The final entry has marker 'SOS' and carries the entropy-coded rest.
	 * Standalone markers (TEM, RSTn) carry empty payloads.
	 *
	 * @return array<int, array{marker:int|string, bytes:string, payload:string}>|null
	 */
	public static function jpeg_segments( string $data ): ?array {
		if ( "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
			return null;
		}
		$segments = array();
		$offset   = 2;
		$length   = strlen( $data );

		while ( $offset + 2 <= $length ) {
			if ( "\xFF" !== $data[ $offset ] ) {
				return null;
			}
			$marker = ord( $data[ $offset + 1 ] );

			if ( 0xD8 === $marker || 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) ) {
				$segments[] = array(
					'marker'  => $marker,
					'bytes'   => substr( $data, $offset, 2 ),
					'payload' => '',
				);
				$offset    += 2;
				continue;
			}

			if ( 0xDA === $marker ) {
				$segments[] = array(
					'marker'  => 'SOS',
					'bytes'   => substr( $data, $offset ),
					'payload' => '',
				);
				return $segments;
			}

			if ( 0xD9 === $marker ) {
				$segments[] = array(
					'marker'  => $marker,
					'bytes'   => substr( $data, $offset, 2 ),
					'payload' => '',
				);
				return $segments;
			}

			if ( $offset + 4 > $length ) {
				break;
			}
			$size = unpack( 'n', substr( $data, $offset + 2, 2 ) );
			$size = $size[1];
			if ( $size < 2 ) {
				return null;
			}
			$segment = substr( $data, $offset, 2 + $size );
			if ( strlen( $segment ) < 2 + $size ) {
				break;
			}
			$segments[] = array(
				'marker'  => $marker,
				'bytes'   => $segment,
				'payload' => substr( $segment, 4 ),
			);
			$offset    += 2 + $size;
		}

		return $segments;
	}

	/**
	 * Extract the XMP packet from JPEG segments (APP1 with XMP header).
	 */
	public static function jpeg_xmp( array $segments ): ?string {
		foreach ( $segments as $segment ) {
			if ( 0xE1 === $segment['marker'] && str_starts_with( $segment['payload'], self::XMP_HEADER_JPEG ) ) {
				return substr( $segment['payload'], strlen( self::XMP_HEADER_JPEG ) );
			}
		}
		return null;
	}

	/**
	 * Extract the raw EXIF block from JPEG segments (APP1 with Exif header).
	 */
	public static function jpeg_exif_raw( array $segments ): ?string {
		foreach ( $segments as $segment ) {
			if ( 0xE1 === $segment['marker'] && str_starts_with( $segment['payload'], self::EXIF_HEADER ) ) {
				return substr( $segment['payload'], strlen( self::EXIF_HEADER ) );
			}
		}
		return null;
	}

	/**
	 * Extract all JPEG comment (COM) segment payloads.
	 *
	 * @return string[]
	 */
	public static function jpeg_comments( array $segments ): array {
		$comments = array();
		foreach ( $segments as $segment ) {
			if ( 0xFE === $segment['marker'] && '' !== $segment['payload'] ) {
				$comments[] = $segment['payload'];
			}
		}
		return $comments;
	}

	/**
	 * Whether JPEG segments contain a C2PA/JUMBF APP11 segment.
	 */
	public static function jpeg_has_c2pa( array $segments ): bool {
		foreach ( $segments as $segment ) {
			if ( 0xEB !== $segment['marker'] ) {
				continue;
			}
			$payload = $segment['payload'];
			if ( str_contains( $payload, 'jumb' ) || str_contains( $payload, 'jumd' ) || stripos( $payload, 'c2pa' ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether JPEG segments contain an APP13 (Photoshop IRB / IPTC-IIM) segment.
	 */
	public static function jpeg_has_app13( array $segments ): bool {
		foreach ( $segments as $segment ) {
			if ( 0xED === $segment['marker'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Concatenated APP11 payloads (C2PA manifests span multiple segments).
	 */
	public static function jpeg_app11_payload( array $segments ): string {
		$payload = '';
		foreach ( $segments as $segment ) {
			if ( 0xEB === $segment['marker'] ) {
				$payload .= $segment['payload'];
			}
		}
		return $payload;
	}

	/**
	 * Rebuild JPEG bytes from a segment list.
	 */
	public static function jpeg_build( array $segments ): string {
		$out = "\xFF\xD8";
		foreach ( $segments as $segment ) {
			if ( 0xD8 === $segment['marker'] ) {
				continue;
			}
			$out .= $segment['bytes'];
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * PNG
	 * ------------------------------------------------------------------- */

	/**
	 * Split a PNG byte stream into chunks.
	 *
	 * Tolerates a truncated stream (head reads): returns the chunks that fit.
	 *
	 * @return array<int, array{type:string, data:string}>|null
	 */
	public static function png_chunks( string $data ): ?array {
		if ( "\x89PNG\r\n\x1a\n" !== substr( $data, 0, 8 ) ) {
			return null;
		}
		$chunks = array();
		$offset = 8;
		$length = strlen( $data );

		while ( $offset + 8 <= $length ) {
			$size = unpack( 'N', substr( $data, $offset, 4 ) );
			$size = $size[1];
			$type = substr( $data, $offset + 4, 4 );
			if ( ! preg_match( '/^[A-Za-z]{4}$/', $type ) ) {
				break;
			}
			$chunk_data = substr( $data, $offset + 8, $size );
			if ( strlen( $chunk_data ) < $size ) {
				break;
			}
			$chunks[] = array(
				'type' => $type,
				'data' => $chunk_data,
			);
			$offset  += 12 + $size;
			if ( 'IEND' === $type ) {
				break;
			}
		}

		return $chunks;
	}

	/**
	 * Decode all PNG text chunks (tEXt, zTXt, iTXt) into keyword => text pairs.
	 *
	 * @param array<int, array{type:string, data:string}> $chunks PNG chunks.
	 * @return array<string, string>
	 */
	public static function png_text_chunks( array $chunks ): array {
		$texts = array();
		foreach ( $chunks as $chunk ) {
			$type = $chunk['type'];
			$data = $chunk['data'];

			if ( 'tEXt' === $type ) {
				$null_pos = strpos( $data, "\x00" );
				if ( false === $null_pos ) {
					continue;
				}
				$texts[ substr( $data, 0, $null_pos ) ] = substr( $data, $null_pos + 1 );
				continue;
			}

			if ( 'zTXt' === $type ) {
				$null_pos = strpos( $data, "\x00" );
				if ( false === $null_pos || strlen( $data ) < $null_pos + 3 ) {
					continue;
				}
				$keyword    = substr( $data, 0, $null_pos );
				$compressed = substr( $data, $null_pos + 2 );
				$inflated   = self::inflate( $compressed );
				if ( null !== $inflated ) {
					$texts[ $keyword ] = $inflated;
				}
				continue;
			}

			if ( 'iTXt' === $type ) {
				$null_pos = strpos( $data, "\x00" );
				if ( false === $null_pos || strlen( $data ) < $null_pos + 3 ) {
					continue;
				}
				$keyword   = substr( $data, 0, $null_pos );
				$comp_flag = ord( $data[ $null_pos + 1 ] );
				$rest      = substr( $data, $null_pos + 3 );
				$lang_end  = strpos( $rest, "\x00" );
				if ( false === $lang_end ) {
					continue;
				}
				$rest2     = substr( $rest, $lang_end + 1 );
				$trans_end = strpos( $rest2, "\x00" );
				if ( false === $trans_end ) {
					continue;
				}
				$text = substr( $rest2, $trans_end + 1 );
				if ( 1 === $comp_flag ) {
					$inflated = self::inflate( $text );
					if ( null === $inflated ) {
						continue;
					}
					$text = $inflated;
				}
				$texts[ $keyword ] = $text;
			}
		}
		return $texts;
	}

	/**
	 * Whether PNG chunks include a C2PA manifest chunk (caBX).
	 *
	 * @param array<int, array{type:string, data:string}> $chunks PNG chunks.
	 */
	public static function png_has_c2pa( array $chunks ): bool {
		foreach ( $chunks as $chunk ) {
			if ( 'caBX' === $chunk['type'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The caBX payload, if present.
	 *
	 * @param array<int, array{type:string, data:string}> $chunks PNG chunks.
	 */
	public static function png_c2pa_payload( array $chunks ): string {
		foreach ( $chunks as $chunk ) {
			if ( 'caBX' === $chunk['type'] ) {
				return $chunk['data'];
			}
		}
		return '';
	}

	/**
	 * The XMP packet from an iTXt chunk with keyword XML:com.adobe.xmp.
	 *
	 * @param array<int, array{type:string, data:string}> $chunks PNG chunks.
	 */
	public static function png_xmp( array $chunks ): ?string {
		$texts = self::png_text_chunks( $chunks );
		return $texts['XML:com.adobe.xmp'] ?? null;
	}

	/**
	 * Build one PNG chunk (length + type + data + CRC).
	 */
	public static function png_build_chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * Rebuild PNG bytes from a chunk list.
	 *
	 * @param array<int, array{type:string, data:string}> $chunks PNG chunks.
	 */
	public static function png_build( array $chunks ): string {
		$out = "\x89PNG\r\n\x1a\n";
		foreach ( $chunks as $chunk ) {
			$out .= self::png_build_chunk( $chunk['type'], $chunk['data'] );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * WebP (RIFF)
	 * ------------------------------------------------------------------- */

	/**
	 * Split a WebP byte stream into RIFF chunks.
	 *
	 * @param bool $tolerate_truncation Return the chunks that fit instead of null.
	 * @return array<int, array{fourcc:string, data:string}>|null
	 */
	public static function webp_chunks( string $data, bool $tolerate_truncation = false ): ?array {
		if ( strlen( $data ) < 12 || 'RIFF' !== substr( $data, 0, 4 ) || 'WEBP' !== substr( $data, 8, 4 ) ) {
			return null;
		}
		$chunks = array();
		$offset = 12;
		$length = strlen( $data );

		while ( $offset + 8 <= $length ) {
			$fourcc = substr( $data, $offset, 4 );
			$size   = unpack( 'V', substr( $data, $offset + 4, 4 ) );
			$size   = $size[1];
			$chunk  = substr( $data, $offset + 8, $size );
			if ( strlen( $chunk ) < $size ) {
				return $tolerate_truncation ? $chunks : null;
			}
			$chunks[] = array(
				'fourcc' => $fourcc,
				'data'   => $chunk,
			);
			$offset  += 8 + $size + ( $size % 2 );
		}

		return $chunks;
	}

	/**
	 * The XMP chunk payload ('XMP ') from WebP chunks.
	 *
	 * @param array<int, array{fourcc:string, data:string}> $chunks RIFF chunks.
	 */
	public static function webp_xmp( array $chunks ): ?string {
		foreach ( $chunks as $chunk ) {
			if ( 'XMP ' === $chunk['fourcc'] ) {
				return $chunk['data'];
			}
		}
		return null;
	}

	/**
	 * The raw EXIF chunk payload from WebP chunks.
	 *
	 * @param array<int, array{fourcc:string, data:string}> $chunks RIFF chunks.
	 */
	public static function webp_exif_raw( array $chunks ): ?string {
		foreach ( $chunks as $chunk ) {
			if ( 'EXIF' === $chunk['fourcc'] ) {
				$payload = $chunk['data'];
				if ( str_starts_with( $payload, self::EXIF_HEADER ) ) {
					$payload = substr( $payload, strlen( self::EXIF_HEADER ) );
				}
				return $payload;
			}
		}
		return null;
	}

	/**
	 * Whether WebP chunks include a C2PA chunk.
	 *
	 * @param array<int, array{fourcc:string, data:string}> $chunks RIFF chunks.
	 */
	public static function webp_has_c2pa( array $chunks ): bool {
		foreach ( $chunks as $chunk ) {
			if ( 'C2PA' === $chunk['fourcc'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rebuild WebP bytes from a chunk list.
	 *
	 * @param array<int, array{fourcc:string, data:string}> $chunks RIFF chunks.
	 */
	public static function webp_build( array $chunks ): string {
		$body = '';
		foreach ( $chunks as $chunk ) {
			$body .= $chunk['fourcc'] . pack( 'V', strlen( $chunk['data'] ) ) . $chunk['data'];
			if ( strlen( $chunk['data'] ) % 2 ) {
				$body .= "\x00";
			}
		}
		return 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body;
	}

	/**
	 * Build a VP8X chunk payload for a chunk list that lacks one.
	 *
	 * Derives canvas size from the VP8/VP8L bitstream and mirrors the
	 * feature flags of the present chunks. Returns null when the size
	 * cannot be determined.
	 *
	 * @param array<int, array{fourcc:string, data:string}> $chunks RIFF chunks.
	 */
	public static function webp_make_vp8x( array $chunks ): ?string {
		$width  = 0;
		$height = 0;
		$alpha  = false;
		$anim   = false;
		$icc    = false;
		$exif   = false;

		foreach ( $chunks as $chunk ) {
			$payload = $chunk['data'];
			switch ( $chunk['fourcc'] ) {
				case 'VP8 ':
					if ( strlen( $payload ) >= 10 && 0x9D === ord( $payload[3] ) && 0x01 === ord( $payload[4] ) && 0x2A === ord( $payload[5] ) ) {
						$w      = unpack( 'v', substr( $payload, 6, 2 ) );
						$h      = unpack( 'v', substr( $payload, 8, 2 ) );
						$width  = $w[1] & 0x3FFF;
						$height = $h[1] & 0x3FFF;
					}
					break;
				case 'VP8L':
					if ( strlen( $payload ) >= 5 && 0x2F === ord( $payload[0] ) ) {
						$bits   = unpack( 'V', substr( $payload, 1, 4 ) );
						$bits   = $bits[1];
						$width  = ( $bits & 0x3FFF ) + 1;
						$height = ( ( $bits >> 14 ) & 0x3FFF ) + 1;
						$alpha  = 1 === ( ( $bits >> 28 ) & 1 );
					}
					break;
				case 'ALPH':
					$alpha = true;
					break;
				case 'ANIM':
				case 'ANMF':
					$anim = true;
					break;
				case 'ICCP':
					$icc = true;
					break;
				case 'EXIF':
					$exif = true;
					break;
			}
		}

		if ( ! $width || ! $height ) {
			return null;
		}

		$flags = 0x04; // XMP present.
		if ( $icc ) {
			$flags |= 0x20;
		}
		if ( $alpha ) {
			$flags |= 0x10;
		}
		if ( $exif ) {
			$flags |= 0x08;
		}
		if ( $anim ) {
			$flags |= 0x02;
		}

		$w1 = $width - 1;
		$h1 = $height - 1;
		return chr( $flags ) . "\x00\x00\x00"
			. chr( $w1 & 0xFF ) . chr( ( $w1 >> 8 ) & 0xFF ) . chr( ( $w1 >> 16 ) & 0xFF )
			. chr( $h1 & 0xFF ) . chr( ( $h1 >> 8 ) & 0xFF ) . chr( ( $h1 >> 16 ) & 0xFF );
	}

	/* ---------------------------------------------------------------------
	 * ISO-BMFF (MP4 / MOV / M4A / HEIC / AVIF)
	 * ------------------------------------------------------------------- */

	/**
	 * Scan top-level BMFF boxes for C2PA / XMP uuid boxes.
	 *
	 * Works on a head read: iterates as far as the data reaches.
	 *
	 * @return array{c2pa:bool, xmp:bool}
	 */
	public static function bmff_scan( string $data ): array {
		$found  = array(
			'c2pa' => false,
			'xmp'  => false,
		);
		$offset = 0;
		$length = strlen( $data );

		while ( $offset + 8 <= $length ) {
			$size = unpack( 'N', substr( $data, $offset, 4 ) );
			$size = $size[1];
			$type = substr( $data, $offset + 4, 4 );
			$head = 8;

			if ( 1 === $size ) {
				if ( $offset + 16 > $length ) {
					break;
				}
				$parts = unpack( 'Nhigh/Nlow', substr( $data, $offset + 8, 8 ) );
				$size  = ( $parts['high'] * 4294967296 ) + $parts['low'];
				$head  = 16;
			} elseif ( 0 === $size ) {
				$size = $length - $offset;
			}

			if ( $size < $head || ! preg_match( '/^[\x20-\x7E]{4}$/', $type ) ) {
				break;
			}

			if ( 'uuid' === $type && $offset + $head + 16 <= $length ) {
				$uuid = substr( $data, $offset + $head, 16 );
				if ( self::C2PA_BMFF_UUID === $uuid ) {
					$found['c2pa'] = true;
				}
				if ( self::XMP_BMFF_UUID === $uuid ) {
					$found['xmp'] = true;
				}
			}

			$offset += $size;
		}

		return $found;
	}

	/**
	 * The XMP payload of a BMFF XMP uuid box, if fully inside the data.
	 */
	public static function bmff_xmp( string $data ): ?string {
		$offset = 0;
		$length = strlen( $data );

		while ( $offset + 8 <= $length ) {
			$size = unpack( 'N', substr( $data, $offset, 4 ) );
			$size = $size[1];
			$type = substr( $data, $offset + 4, 4 );
			$head = 8;
			if ( 1 === $size ) {
				if ( $offset + 16 > $length ) {
					break;
				}
				$parts = unpack( 'Nhigh/Nlow', substr( $data, $offset + 8, 8 ) );
				$size  = ( $parts['high'] * 4294967296 ) + $parts['low'];
				$head  = 16;
			} elseif ( 0 === $size ) {
				$size = $length - $offset;
			}
			if ( $size < $head || ! preg_match( '/^[\x20-\x7E]{4}$/', $type ) ) {
				break;
			}
			if ( 'uuid' === $type && $offset + $head + 16 <= $length ) {
				$uuid = substr( $data, $offset + $head, 16 );
				if ( self::XMP_BMFF_UUID === $uuid && $offset + $size <= $length ) {
					return substr( $data, $offset + $head + 16, $size - $head - 16 );
				}
			}
			$offset += $size;
		}

		return null;
	}

	/* ---------------------------------------------------------------------
	 * MP3 / ID3v2
	 * ------------------------------------------------------------------- */

	/**
	 * Parse ID3v2 frames from the head of an MP3 file.
	 *
	 * @return array<int, array{id:string, data:string}>
	 */
	public static function id3_frames( string $data ): array {
		if ( 'ID3' !== substr( $data, 0, 3 ) || strlen( $data ) < 10 ) {
			return array();
		}
		$major     = ord( $data[3] );
		$tag_size  = self::syncsafe( substr( $data, 6, 4 ) );
		$end       = min( strlen( $data ), 10 + $tag_size );
		$offset    = 10;
		$frames    = array();
		$syncsafe4 = ( $major >= 4 );

		while ( $offset + 10 <= $end ) {
			$id = substr( $data, $offset, 4 );
			if ( ! preg_match( '/^[A-Z0-9]{4}$/', $id ) ) {
				break;
			}
			$size_raw = substr( $data, $offset + 4, 4 );
			$size     = $syncsafe4 ? self::syncsafe( $size_raw ) : unpack( 'N', $size_raw )[1];
			if ( $size <= 0 || $offset + 10 + $size > $end ) {
				break;
			}
			$frames[] = array(
				'id'   => $id,
				'data' => substr( $data, $offset + 10, $size ),
			);
			$offset  += 10 + $size;
		}

		return $frames;
	}

	/**
	 * Decode a 4-byte syncsafe integer.
	 */
	public static function syncsafe( string $bytes ): int {
		if ( strlen( $bytes ) < 4 ) {
			return 0;
		}
		return ( ( ord( $bytes[0] ) & 0x7F ) << 21 )
			| ( ( ord( $bytes[1] ) & 0x7F ) << 14 )
			| ( ( ord( $bytes[2] ) & 0x7F ) << 7 )
			| ( ord( $bytes[3] ) & 0x7F );
	}

	/**
	 * The description string of a TXXX frame (any text encoding, lossy for UTF-16).
	 */
	public static function id3_txxx_description( string $frame_data ): string {
		if ( '' === $frame_data ) {
			return '';
		}
		$encoding = ord( $frame_data[0] );
		$body     = substr( $frame_data, 1 );
		if ( 1 === $encoding || 2 === $encoding ) {
			$null_pos    = strpos( $body, "\x00\x00" );
			$description = false === $null_pos ? $body : substr( $body, 0, $null_pos );
			$description = str_replace( array( "\xFF\xFE", "\xFE\xFF", "\x00" ), '', $description );
		} else {
			$null_pos    = strpos( $body, "\x00" );
			$description = false === $null_pos ? $body : substr( $body, 0, $null_pos );
		}
		return $description;
	}

	/* ---------------------------------------------------------------------
	 * XMP handling
	 * ------------------------------------------------------------------- */

	/**
	 * Extract all IPTC DigitalSourceType values from an XMP packet.
	 *
	 * Understands the element form, the attribute form, and rdf Bag/Seq/Alt
	 * list forms, with any namespace prefix (matching on the value URI or the
	 * DigitalSourceType local name).
	 *
	 * @return string[] Lowercased digitalsourcetype terms (last URI path part).
	 */
	public static function xmp_digital_source_types( string $xmp ): array {
		$values = array();

		// Element form: <prefix:DigitalSourceType>VALUE</...>, possibly wrapping rdf:li items.
		if ( preg_match_all( '#<[A-Za-z0-9_.-]+:DigitalSourceType\b[^>]*>(.*?)</[A-Za-z0-9_.-]+:DigitalSourceType>#s', $xmp, $matches ) ) {
			foreach ( $matches[1] as $inner ) {
				if ( preg_match_all( '#<rdf:li[^>]*>(.*?)</rdf:li>#s', $inner, $items ) ) {
					foreach ( $items[1] as $item ) {
						$values[] = $item;
					}
				} else {
					$values[] = $inner;
				}
			}
		}

		// Attribute form: prefix:DigitalSourceType="VALUE".
		if ( preg_match_all( '#[A-Za-z0-9_.-]+:DigitalSourceType\s*=\s*"([^"]*)"#', $xmp, $matches ) ) {
			foreach ( $matches[1] as $value ) {
				$values[] = $value;
			}
		}

		$terms = array();
		foreach ( $values as $value ) {
			$value = strtolower( trim( strip_tags( $value ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- class must stay WordPress-free for unit tests.
			if ( '' === $value ) {
				continue;
			}
			$slash = strrpos( $value, '/' );
			if ( false !== $slash ) {
				$value = substr( $value, $slash + 1 );
			}
			$terms[] = $value;
		}

		return array_values( array_unique( $terms ) );
	}

	/**
	 * Best-effort claim generator extraction from raw C2PA/JUMBF bytes.
	 *
	 * The manifest is CBOR; a full parser is out of scope. The generator name
	 * follows the text key "claim_generator", grab the printable run after it.
	 */
	public static function c2pa_claim_generator( string $payload ): string {
		$pos = strpos( $payload, 'claim_generator' );
		if ( false === $pos ) {
			return '';
		}
		$after = substr( $payload, $pos + strlen( 'claim_generator' ), 160 );
		if ( preg_match( '/[\x20-\x7E]{3,80}/', $after, $match ) ) {
			$candidate = trim( $match[0] );
			$candidate = preg_replace( '/^[^A-Za-z0-9]+/', '', $candidate );
			$candidate = preg_replace( '/[^A-Za-z0-9)\]]+$/', '', (string) $candidate );
			return substr( (string) $candidate, 0, 80 );
		}
		return '';
	}

	/**
	 * zlib inflate with a size guard.
	 */
	private static function inflate( string $data ): ?string {
		if ( ! function_exists( 'gzuncompress' ) ) {
			return null;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- corrupt chunk data must not raise a warning.
		$inflated = @gzuncompress( $data, 1048576 );
		return false === $inflated ? null : $inflated;
	}
}
