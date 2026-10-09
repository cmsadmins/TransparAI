<?php
/**
 * C2PA manifest reading: JUMBF boxes, a minimal CBOR decoder, the data hash
 * assertion checked against the file bytes, and the signer's certificate
 * subject.
 *
 * What this is NOT: a signature verifier. No COSE signature is checked, no
 * certificate chain is built, no trust list is consulted. The result says
 * "the manifest describes exactly these bytes" (or not) and "the manifest
 * names this signer and this generator", always as a claim taken from the
 * file, never as cryptographic proof. Everything stays WordPress-free and
 * side-effect-free so it is unit-testable like the parsers.
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
 * C2PA manifest store reader.
 */
final class TransparAI_C2PA {

	/**
	 * Largest manifest store this reader buffers. Real stores (with
	 * thumbnails) sit in the tens of kilobytes to a few megabytes.
	 */
	public const MAX_STORE_BYTES = 8388608;

	/**
	 * Largest file that is hashed. Beyond this the result is reported as
	 * unsupported rather than exhausting PHP's memory.
	 */
	public const MAX_FILE_BYTES = 67108864;

	/**
	 * Hash algorithms the C2PA specification allows for c2pa.hash.data.
	 */
	private const ALGS = array(
		'sha256' => 'sha256',
		'sha384' => 'sha384',
		'sha512' => 'sha512',
	);

	/**
	 * CBOR decoding budget counters (reset per decode call).
	 *
	 * @var int
	 */
	private static $cbor_items = 0;

	/* ---------------------------------------------------------------------
	 * Public API
	 * ------------------------------------------------------------------- */

	/**
	 * Everything the plugin reports about the manifest store of one file.
	 *
	 * @param string $data   Complete file bytes, with this plugin's own XMP/IIM
	 *                       additions already removed (see TransparAI_Writer::without_own_marks()).
	 * @param string $format jpeg|png|webp.
	 * @return array{hash:string, reason:string, alg:string, sig:string, sig_reason:string, issuer:string, tst:string, tsa:string, tsa_trusted:bool, signer_cn:string, signer_o:string, generator:string, when:string, manifests:int}|null
	 *         Null when the file carries no manifest store.
	 */
	public static function summary( string $data, string $format ): ?array {
		$store = self::store_from_data( $data, $format );
		if ( null === $store ) {
			return null;
		}

		$info = array(
			'hash'        => 'unsupported',
			'reason'      => '',
			'alg'         => '',
			'sig'         => 'unsupported',
			'sig_reason'  => '',
			'issuer'      => '',
			'tst'         => '',
			'tsa'         => '',
			'tsa_trusted' => false,
			'signer_cn'   => '',
			'signer_o'    => '',
			'generator'   => '',
			'when'        => '',
			'manifests'   => 0,
		);

		if ( '' === $store ) {
			$info['reason'] = 'truncated';
			return $info;
		}

		$parsed = self::parse_store( $store );
		if ( null === $parsed ) {
			$info['reason'] = 'parse_error';
			return $info;
		}

		$verdict             = TransparAI_C2PA_Verify::verify( $store );
		$info['sig']         = $verdict['sig'];
		$info['sig_reason']  = $verdict['reason'];
		$info['issuer']      = $verdict['issuer'];
		$info['tst']         = $verdict['tst'];
		$info['tsa']         = $verdict['tsa'];
		$info['tsa_trusted'] = $verdict['tsa_trusted'];

		$info['manifests'] = $parsed['manifests'];
		$info['generator'] = $parsed['generator'];
		$info['when']      = $parsed['when'];
		$info['signer_cn'] = $parsed['signer_cn'];
		$info['signer_o']  = $parsed['signer_o'];

		if ( null === $parsed['hash_data'] ) {
			$info['reason'] = 'no_hash_data';
			return $info;
		}

		$check          = self::hash_check( $data, $parsed['hash_data'], $parsed['claim_alg'] );
		$info['hash']   = $check['state'];
		$info['reason'] = $check['reason'];
		$info['alg']    = $check['alg'];
		return $info;
	}

	/**
	 * Whether a file on disk carries a C2PA manifest (presence only).
	 */
	public static function present( string $path ): bool {
		$head = TransparAI_Parsers::read_head( $path );
		if ( null === $head || '' === $head ) {
			return false;
		}
		switch ( TransparAI_Parsers::sniff( $head ) ) {
			case 'jpeg':
				$segments = TransparAI_Parsers::jpeg_segments( $head );
				return null !== $segments && TransparAI_Parsers::jpeg_has_c2pa( $segments );
			case 'png':
				$chunks = TransparAI_Parsers::png_metadata_chunks( $path );
				return null !== $chunks && TransparAI_Parsers::png_has_c2pa( $chunks );
			case 'webp':
				return self::webp_file_has_chunk( $path, 'C2PA' );
			case 'bmff':
				return TransparAI_Parsers::bmff_scan( $head )['c2pa'];
			case '':
				return self::is_text( $path ) && null !== self::store_from_text( (string) TransparAI_Parsers::read_head( $path, self::MAX_FILE_BYTES ) );
		}
		return false;
	}

	/**
	 * Decode one CBOR item (RFC 8949), null on anything malformed or over budget.
	 *
	 * Tags are dropped (their content is returned), indefinite-length items are
	 * rejected, floats are decoded, maps become associative arrays keyed by
	 * their int or string keys.
	 *
	 * @param string $data CBOR bytes.
	 * @return mixed Decoded value, or null.
	 */
	public static function cbor_decode( string $data ) {
		if ( strlen( $data ) > self::MAX_STORE_BYTES ) {
			return null;
		}
		self::$cbor_items = 0;
		$pos              = 0;
		try {
			return self::cbor_item( $data, $pos, 0 );
		} catch ( RuntimeException $e ) {
			return null;
		}
	}

	/**
	 * Subject common name and organisation of a DER-encoded X.509 certificate.
	 *
	 * @return array{cn:string, o:string}
	 */
	public static function x509_subject( string $der ): array {
		$empty = array(
			'cn' => '',
			'o'  => '',
		);
		try {
			$cert = self::der_tlv( $der, 0, strlen( $der ) );
			if ( 0x30 !== $cert['tag'] ) {
				return $empty;
			}
			$tbs = self::der_tlv( $der, $cert['start'], $cert['end'] );
			if ( 0x30 !== $tbs['tag'] ) {
				return $empty;
			}
			/* tbsCertificate: [0] version?, serial, sigalg, issuer, validity, subject, ... */
			$pos   = $tbs['start'];
			$index = 0;
			while ( $pos < $tbs['end'] ) {
				$field = self::der_tlv( $der, $pos, $tbs['end'] );
				$pos   = $field['end'];
				if ( 0xA0 === $field['tag'] ) {
					continue;
				}
				if ( 4 === $index ) {
					return self::der_name( $der, $field['start'], $field['end'] );
				}
				++$index;
			}
		} catch ( RuntimeException $e ) {
			return $empty;
		}
		return $empty;
	}

	/* ---------------------------------------------------------------------
	 * Manifest store extraction per container
	 * ------------------------------------------------------------------- */

	/**
	 * The JUMBF manifest store bytes inside raw file data.
	 *
	 * @return string|null Null without a store, '' when a store is present but cannot be reassembled.
	 */
	public static function store_from_data( string $data, string $format ): ?string {
		switch ( $format ) {
			case 'jpeg':
				$segments = TransparAI_Parsers::jpeg_segments( $data );
				if ( null === $segments || ! TransparAI_Parsers::jpeg_has_c2pa( $segments ) ) {
					return null;
				}
				return self::jpeg_app11_store( $segments );
			case 'png':
				return self::png_find_chunk( $data, 'caBX' );
			case 'webp':
				return self::webp_find_chunk( $data, 'C2PA' );
			case 'text':
				return self::store_from_text( $data );
		}
		return null;
	}

	/**
	 * Whether a file is plain text by name; text has no magic bytes to sniff.
	 */
	public static function is_text( string $path ): bool {
		return 'txt' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * The manifest store of a plain text (C2PA 2.4 section A.8).
	 *
	 * The wrapper is U+FEFF followed by one variation selector per byte,
	 * U+FE00 to U+FE0F for 0 to 15 and U+E0100 to U+E01EF for 16 to 255. The
	 * bytes spell "C2PATXT", a NUL, version 1, a big-endian length and the
	 * JUMBF store; padding may follow. A run with another magic is text (emoji
	 * use selectors too); a second wrapper makes the text ambiguous.
	 *
	 * @return string|null Null without a wrapper, '' when a wrapper is malformed or repeated.
	 */
	public static function store_from_text( string $data ): ?string {
		if ( ! str_contains( $data, "\xEF\xBB\xBF" ) ) {
			return null;
		}
		/* Possessive: a long run as a backtracking repeat would exhaust the PCRE JIT stack. */
		$runs = preg_match_all( '/\xEF\xBB\xBF((?:\xEF\xB8[\x80-\x8F]|\xF3\xA0[\x84-\x86][\x80-\xBF]|\xF3\xA0\x87[\x80-\xAF])++)/', $data, $matches );
		if ( ! $runs ) {
			return null;
		}

		$map = array();
		for ( $byte = 0; $byte < 256; $byte++ ) {
			$n           = $byte - 16;
			$key         = $byte < 16 ? "\xEF\xB8" . chr( 0x80 + $byte ) : "\xF3\xA0" . chr( 0x84 + ( $n >> 6 ) ) . chr( 0x80 + ( $n & 0x3F ) );
			$map[ $key ] = chr( $byte );
		}

		$store = null;
		foreach ( $matches[1] as $run ) {
			$bytes = strtr( $run, $map );
			if ( strlen( $bytes ) < 13 || "C2PATXT\0" !== substr( $bytes, 0, 8 ) || 1 !== ord( $bytes[8] ) ) {
				continue;
			}
			if ( null !== $store ) {
				return '';
			}
			$length = (int) unpack( 'N', substr( $bytes, 9, 4 ) )[1];
			$body   = (string) substr( $bytes, 13, $length );
			$store  = $length >= 8 && $length <= self::MAX_STORE_BYTES && strlen( $body ) === $length && unpack( 'N', $body )[1] === $length ? $body : '';
		}
		return $store;
	}

	/**
	 * Reassemble the JUMBF store from JPEG APP11 segments (ISO 19566-5).
	 *
	 * Each APP11 payload: "JP", box instance number En (2 bytes), packet
	 * sequence number Z (4 bytes), then LBox and TBox of the box the packet
	 * belongs to, repeated in every packet, followed by the next slice of the
	 * box. Packets are grouped by En and ordered by Z.
	 *
	 * @param array<int, array{marker:int|string, bytes:string, payload:string}> $segments JPEG segments.
	 * @return string '' when the segments do not form one complete box.
	 */
	private static function jpeg_app11_store( array $segments ): string {
		$groups = array();
		foreach ( $segments as $segment ) {
			if ( 0xEB !== $segment['marker'] ) {
				continue;
			}
			$payload = $segment['payload'];
			if ( strlen( $payload ) < 16 || 'JP' !== substr( $payload, 0, 2 ) ) {
				continue;
			}
			$en = unpack( 'n', substr( $payload, 2, 2 ) )[1];
			$z  = unpack( 'N', substr( $payload, 4, 4 ) )[1];
			if ( ! isset( $groups[ $en ] ) ) {
				$groups[ $en ] = array(
					'head'    => substr( $payload, 8, 8 ),
					'packets' => array(),
				);
			}
			$groups[ $en ]['packets'][ $z ] = substr( $payload, 16 );
		}
		if ( array() === $groups ) {
			return '';
		}

		/* The store is the group whose box is a JUMBF superbox; take the first such one. */
		foreach ( $groups as $group ) {
			if ( 'jumb' !== substr( $group['head'], 4, 4 ) ) {
				continue;
			}
			ksort( $group['packets'] );
			$store = $group['head'] . implode( '', $group['packets'] );
			$lbox  = unpack( 'N', substr( $store, 0, 4 ) )[1];
			if ( $lbox !== strlen( $store ) || $lbox > self::MAX_STORE_BYTES ) {
				return '';
			}
			return $store;
		}
		return '';
	}

	/**
	 * One PNG chunk's data by type, walking the chunk table without copying image data.
	 */
	private static function png_find_chunk( string $data, string $type ): ?string {
		if ( TransparAI_Parsers::PNG_SIGNATURE !== substr( $data, 0, 8 ) ) {
			return null;
		}
		$pos    = 8;
		$length = strlen( $data );
		while ( $pos + 8 <= $length ) {
			$size = unpack( 'N', substr( $data, $pos, 4 ) )[1];
			$kind = substr( $data, $pos + 4, 4 );
			if ( $kind === $type ) {
				if ( $size > self::MAX_STORE_BYTES || $pos + 8 + $size > $length ) {
					return '';
				}
				return substr( $data, $pos + 8, $size );
			}
			if ( 'IEND' === $kind ) {
				break;
			}
			$pos += 12 + $size;
		}
		return null;
	}

	/**
	 * One WebP RIFF chunk's data by FourCC ('' when present but cut off or oversized).
	 */
	private static function webp_find_chunk( string $data, string $fourcc ): ?string {
		if ( strlen( $data ) < 12 || 'RIFF' !== substr( $data, 0, 4 ) || 'WEBP' !== substr( $data, 8, 4 ) ) {
			return null;
		}
		$pos    = 12;
		$length = strlen( $data );
		while ( $pos + 8 <= $length ) {
			$kind = substr( $data, $pos, 4 );
			$size = unpack( 'V', substr( $data, $pos + 4, 4 ) )[1];
			if ( $kind === $fourcc ) {
				if ( $size > self::MAX_STORE_BYTES || $pos + 8 + $size > $length ) {
					return '';
				}
				return substr( $data, $pos + 8, $size );
			}
			$pos += 8 + $size + ( $size % 2 );
		}
		return null;
	}

	/**
	 * Whether a WebP file on disk has a chunk of the given FourCC, seeking over
	 * chunk bodies so a large image costs no memory.
	 */
	private static function webp_file_has_chunk( string $path, string $fourcc ): bool {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- local media file; an unreadable path reads as "absent".
		if ( false === $handle ) {
			return false;
		}
		$found = false;
		$riff  = (string) fread( $handle, 12 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- local media file, not a remote request.
		if ( 12 === strlen( $riff ) && 'RIFF' === substr( $riff, 0, 4 ) && 'WEBP' === substr( $riff, 8, 4 ) ) {
			while ( true ) {
				$header = (string) fread( $handle, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- local media file, not a remote request.
				if ( 8 !== strlen( $header ) ) {
					break;
				}
				if ( $fourcc === substr( $header, 0, 4 ) ) {
					$found = true;
					break;
				}
				$size = unpack( 'V', substr( $header, 4, 4 ) )[1];
				if ( -1 === fseek( $handle, $size + ( $size % 2 ), SEEK_CUR ) ) {
					break;
				}
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- local media file, not a remote request.
		return $found;
	}

	/* ---------------------------------------------------------------------
	 * JUMBF
	 * ------------------------------------------------------------------- */

	/**
	 * The boxes between two offsets.
	 *
	 * A 'jumb' superbox is returned with the label from its description box
	 * and a content range that starts after that description box.
	 *
	 * @return array<int, array{type:string, label:string, offset:int, head:int, start:int, end:int}>|null
	 */
	public static function jumbf_boxes( string $data, int $start, int $end ): ?array {
		$out = array();
		$pos = $start;
		while ( $pos < $end ) {
			$box = self::jumbf_box( $data, $pos, $end );
			if ( null === $box ) {
				return null;
			}
			$pos = $box['end'];
			if ( 'jumb' === $box['type'] ) {
				$desc = self::jumbf_box( $data, $box['start'], $box['end'] );
				if ( null === $desc || 'jumd' !== $desc['type'] ) {
					return null;
				}
				$box['label'] = self::jumbf_label( substr( $data, $desc['start'], $desc['end'] - $desc['start'] ) );
				$box['start'] = $desc['end'];
			}
			$out[] = $box;
		}
		return $out;
	}

	/**
	 * Header of one box at $pos.
	 *
	 * @return array{type:string, label:string, offset:int, head:int, start:int, end:int}|null
	 */
	private static function jumbf_box( string $data, int $pos, int $end ): ?array {
		if ( $pos + 8 > $end ) {
			return null;
		}
		$lbox = unpack( 'N', substr( $data, $pos, 4 ) )[1];
		$type = substr( $data, $pos + 4, 4 );
		$head = 8;
		if ( 1 === $lbox ) {
			if ( $pos + 16 > $end ) {
				return null;
			}
			$hi = unpack( 'N', substr( $data, $pos + 8, 4 ) )[1];
			$lo = unpack( 'N', substr( $data, $pos + 12, 4 ) )[1];
			if ( 0 !== $hi ) {
				return null;
			}
			$lbox = $lo;
			$head = 16;
		} elseif ( 0 === $lbox ) {
			$lbox = $end - $pos;
		}
		if ( $lbox < $head || $pos + $lbox > $end ) {
			return null;
		}
		return array(
			'type'   => $type,
			'label'  => '',
			'offset' => $pos,
			'head'   => $head,
			'start'  => $pos + $head,
			'end'    => $pos + $lbox,
		);
	}

	/**
	 * The label inside a JUMBF description box payload.
	 */
	private static function jumbf_label( string $desc ): string {
		if ( strlen( $desc ) < 17 ) {
			return '';
		}
		$toggles = ord( $desc[16] );
		if ( ! ( $toggles & 0x02 ) ) {
			return '';
		}
		$nul = strpos( $desc, "\x00", 17 );
		return false === $nul ? '' : substr( $desc, 17, $nul - 17 );
	}

	/**
	 * First box whose label starts with $prefix.
	 *
	 * @param array<int, array{type:string, label:string, start:int, end:int}> $boxes Boxes.
	 */
	private static function jumbf_find( array $boxes, string $prefix ): ?array {
		foreach ( $boxes as $box ) {
			if ( 'jumb' === $box['type'] && str_starts_with( $box['label'], $prefix ) ) {
				return $box;
			}
		}
		return null;
	}

	/**
	 * The decoded CBOR of the 'cbor' content box inside a superbox, or null.
	 *
	 * @return mixed
	 */
	private static function jumbf_cbor( string $data, array $box ) {
		$children = self::jumbf_boxes( $data, $box['start'], $box['end'] );
		if ( null === $children ) {
			return null;
		}
		foreach ( $children as $child ) {
			if ( 'cbor' === $child['type'] ) {
				return self::cbor_decode( substr( $data, $child['start'], $child['end'] - $child['start'] ) );
			}
		}
		return null;
	}

	/**
	 * The facts this plugin reads from a manifest store: the active manifest's
	 * hash assertion, generator, first action time and signer subject.
	 *
	 * @return array{manifests:int, hash_data:array|null, claim_alg:string, generator:string, when:string, signer_cn:string, signer_o:string}|null
	 */
	public static function parse_store( string $store ): ?array {
		$top = self::jumbf_boxes( $store, 0, strlen( $store ) );
		if ( null === $top ) {
			return null;
		}
		$root = self::jumbf_find( $top, 'c2pa' );
		if ( null === $root ) {
			return null;
		}
		$manifests = self::jumbf_boxes( $store, $root['start'], $root['end'] );
		if ( null === $manifests ) {
			return null;
		}
		$manifests = array_values( array_filter( $manifests, static fn( array $box ): bool => 'jumb' === $box['type'] ) );
		if ( array() === $manifests ) {
			return null;
		}

		/* The active manifest is the last one in the store (C2PA 2.x, section 11). */
		$active = $manifests[ count( $manifests ) - 1 ];
		$parts  = self::jumbf_boxes( $store, $active['start'], $active['end'] );
		if ( null === $parts ) {
			return null;
		}

		$out = array(
			'manifests' => count( $manifests ),
			'hash_data' => null,
			'claim_alg' => '',
			'generator' => '',
			'when'      => '',
			'signer_cn' => '',
			'signer_o'  => '',
		);

		$assertions = self::jumbf_find( $parts, 'c2pa.assertions' );
		if ( null !== $assertions ) {
			$list = self::jumbf_boxes( $store, $assertions['start'], $assertions['end'] );
			if ( null !== $list ) {
				$hash = self::jumbf_find( $list, 'c2pa.hash.data' );
				if ( null !== $hash ) {
					$decoded = self::jumbf_cbor( $store, $hash );
					if ( is_array( $decoded ) ) {
						$out['hash_data'] = $decoded;
					}
				}
				$actions = self::jumbf_find( $list, 'c2pa.actions' );
				if ( null !== $actions ) {
					$decoded = self::jumbf_cbor( $store, $actions );
					$when    = $decoded['actions'][0]['when'] ?? '';
					if ( is_string( $when ) ) {
						$out['when'] = self::printable( $when, 40 );
					}
				}
			}
		}

		$claim = self::jumbf_find( $parts, 'c2pa.claim' );
		if ( null !== $claim ) {
			$decoded = self::jumbf_cbor( $store, $claim );
			if ( is_array( $decoded ) ) {
				$out['claim_alg'] = is_string( $decoded['alg'] ?? null ) ? $decoded['alg'] : '';
				$out['generator'] = self::claim_generator( $decoded );
			}
		}

		$signature = self::jumbf_find( $parts, 'c2pa.signature' );
		if ( null !== $signature ) {
			$cose = self::jumbf_cbor( $store, $signature );
			$cert = self::cose_signer_cert( $cose );
			if ( '' !== $cert ) {
				$subject          = self::x509_subject( $cert );
				$out['signer_cn'] = self::printable( $subject['cn'], 120 );
				$out['signer_o']  = self::printable( $subject['o'], 120 );
			}
		}

		return $out;
	}

	/**
	 * Generator name from a decoded claim: claim_generator_info[0].name (+ version), else claim_generator.
	 *
	 * @param array<string, mixed> $claim Decoded claim map.
	 */
	private static function claim_generator( array $claim ): string {
		$info = $claim['claim_generator_info'] ?? null;
		if ( is_array( $info ) ) {
			$first = $info[0] ?? $info;
			if ( is_array( $first ) && is_string( $first['name'] ?? null ) ) {
				$name = $first['name'];
				if ( is_string( $first['version'] ?? null ) && '' !== $first['version'] ) {
					$name .= ' ' . $first['version'];
				}
				return self::printable( $name, 120 );
			}
		}
		$legacy = $claim['claim_generator'] ?? null;
		return is_string( $legacy ) ? self::printable( $legacy, 120 ) : '';
	}

	/**
	 * The first certificate of the COSE_Sign1 x5chain header (label 33), DER bytes or ''.
	 *
	 * @param mixed $cose Decoded COSE_Sign1 (tag already dropped): [protected bstr, unprotected map, payload, signature].
	 */
	private static function cose_signer_cert( $cose ): string {
		if ( ! is_array( $cose ) || count( $cose ) < 2 || ! is_string( $cose[0] ?? null ) ) {
			return '';
		}
		$protected   = self::cbor_decode( $cose[0] );
		$unprotected = $cose[1];
		foreach ( array( $unprotected, $protected ) as $headers ) {
			if ( ! is_array( $headers ) ) {
				continue;
			}
			$chain = $headers[33] ?? $headers['x5chain'] ?? null;
			if ( is_array( $chain ) ) {
				$chain = $chain[0] ?? null;
			}
			if ( is_string( $chain ) && '' !== $chain ) {
				return $chain;
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * Data hash
	 * ------------------------------------------------------------------- */

	/**
	 * Check the c2pa.hash.data assertion against the file bytes.
	 *
	 * @param string               $data      Complete file bytes (own marks removed).
	 * @param array<string, mixed> $hash_data Decoded assertion: alg?, hash, exclusions?[{start,length}].
	 * @param string               $claim_alg The claim's default algorithm when the assertion names none.
	 * @return array{state:string, reason:string, alg:string} state: match|mismatch|unsupported.
	 */
	public static function hash_check( string $data, array $hash_data, string $claim_alg ): array {
		$alg = strtolower( (string) ( is_string( $hash_data['alg'] ?? null ) ? $hash_data['alg'] : $claim_alg ) );
		if ( '' === $alg ) {
			$alg = 'sha256';
		}
		if ( ! isset( self::ALGS[ $alg ] ) ) {
			return self::check_result( 'unsupported', 'unknown_alg', $alg );
		}
		$expected = $hash_data['hash'] ?? null;
		if ( ! is_string( $expected ) || '' === $expected ) {
			return self::check_result( 'unsupported', 'no_hash_data', $alg );
		}

		$ranges = array();
		foreach ( (array) ( $hash_data['exclusions'] ?? array() ) as $exclusion ) {
			if ( ! is_array( $exclusion ) || ! is_int( $exclusion['start'] ?? null ) || ! is_int( $exclusion['length'] ?? null ) ) {
				return self::check_result( 'unsupported', 'bad_exclusions', $alg );
			}
			$ranges[] = array( $exclusion['start'], $exclusion['length'] );
		}
		usort( $ranges, static fn( array $a, array $b ): int => $a[0] <=> $b[0] );

		$length = strlen( $data );
		$pos    = 0;
		$ctx    = hash_init( self::ALGS[ $alg ] );
		foreach ( $ranges as list( $start, $len ) ) {
			if ( $start < $pos || $len < 0 || $start + $len > $length ) {
				return self::check_result( 'unsupported', 'bad_exclusions', $alg );
			}
			if ( $start > $pos ) {
				hash_update( $ctx, substr( $data, $pos, $start - $pos ) );
			}
			$pos = $start + $len;
		}
		if ( $pos < $length ) {
			hash_update( $ctx, substr( $data, $pos ) );
		}
		$actual = hash_final( $ctx, true );

		return self::check_result( hash_equals( $expected, $actual ) ? 'match' : 'mismatch', '', $alg );
	}

	/**
	 * @return array{state:string, reason:string, alg:string}
	 */
	private static function check_result( string $state, string $reason, string $alg ): array {
		return array(
			'state'  => $state,
			'reason' => $reason,
			'alg'    => $alg,
		);
	}

	/* ---------------------------------------------------------------------
	 * CBOR (RFC 8949), the subset C2PA manifests use
	 * ------------------------------------------------------------------- */

	/**
	 * @return mixed
	 * @throws RuntimeException On malformed input or exhausted budget.
	 */
	private static function cbor_item( string $data, int &$pos, int $depth ) {
		if ( $depth > 32 || ++self::$cbor_items > 200000 ) {
			throw new RuntimeException( 'cbor budget' );
		}
		if ( $pos >= strlen( $data ) ) {
			throw new RuntimeException( 'cbor eof' );
		}
		$initial = ord( $data[ $pos++ ] );
		$major   = $initial >> 5;
		$info    = $initial & 0x1F;

		if ( 7 === $major ) {
			switch ( $info ) {
				case 20:
					return false;
				case 21:
					return true;
				case 22:
				case 23:
					return null;
				case 25:
					self::cbor_take( $data, $pos, 2 );
					return 0.0;
				case 26:
					return unpack( 'G', self::cbor_take( $data, $pos, 4 ) )[1];
				case 27:
					return unpack( 'E', self::cbor_take( $data, $pos, 8 ) )[1];
			}
			if ( $info < 24 ) {
				return $info;
			}
			if ( 24 === $info ) {
				return ord( self::cbor_take( $data, $pos, 1 ) );
			}
			throw new RuntimeException( 'cbor simple' );
		}

		$arg = self::cbor_arg( $data, $pos, $info );

		switch ( $major ) {
			case 0:
				return $arg;
			case 1:
				return -1 - $arg;
			case 2:
			case 3:
				return self::cbor_take( $data, $pos, $arg );
			case 4:
				if ( $arg > 10000 ) {
					throw new RuntimeException( 'cbor array' );
				}
				$list = array();
				for ( $i = 0; $i < $arg; $i++ ) {
					$list[] = self::cbor_item( $data, $pos, $depth + 1 );
				}
				return $list;
			case 5:
				if ( $arg > 10000 ) {
					throw new RuntimeException( 'cbor map' );
				}
				$map = array();
				for ( $i = 0; $i < $arg; $i++ ) {
					$key = self::cbor_item( $data, $pos, $depth + 1 );
					if ( ! is_int( $key ) && ! is_string( $key ) ) {
						throw new RuntimeException( 'cbor key' );
					}
					$map[ $key ] = self::cbor_item( $data, $pos, $depth + 1 );
				}
				return $map;
		}
		/* Major type 6: a tag, whose content is what the manifest means. */
		return self::cbor_item( $data, $pos, $depth + 1 );
	}

	/**
	 * The argument of a CBOR head (count, length, value or tag number).
	 *
	 * @throws RuntimeException On indefinite length or an oversized 64-bit value.
	 */
	private static function cbor_arg( string $data, int &$pos, int $info ): int {
		if ( $info < 24 ) {
			return $info;
		}
		switch ( $info ) {
			case 24:
				return ord( self::cbor_take( $data, $pos, 1 ) );
			case 25:
				return unpack( 'n', self::cbor_take( $data, $pos, 2 ) )[1];
			case 26:
				return unpack( 'N', self::cbor_take( $data, $pos, 4 ) )[1];
			case 27:
				$value = unpack( 'J', self::cbor_take( $data, $pos, 8 ) )[1];
				if ( $value < 0 ) {
					throw new RuntimeException( 'cbor uint64' );
				}
				return $value;
		}
		throw new RuntimeException( 'cbor indefinite' );
	}

	/**
	 * Consume $length bytes.
	 *
	 * @throws RuntimeException When fewer bytes remain.
	 */
	private static function cbor_take( string $data, int &$pos, int $length ): string {
		if ( $length < 0 || $pos + $length > strlen( $data ) ) {
			throw new RuntimeException( 'cbor length' );
		}
		$bytes = substr( $data, $pos, $length );
		$pos  += $length;
		return $bytes;
	}

	/* ---------------------------------------------------------------------
	 * DER (X.509 subject only)
	 * ------------------------------------------------------------------- */

	/**
	 * Tag, content range and end offset of the TLV at $pos.
	 *
	 * @return array{tag:int, start:int, end:int}
	 * @throws RuntimeException On malformed input.
	 */
	private static function der_tlv( string $der, int $pos, int $limit ): array {
		if ( $pos + 2 > $limit ) {
			throw new RuntimeException( 'der eof' );
		}
		$tag  = ord( $der[ $pos ] );
		$len  = ord( $der[ $pos + 1 ] );
		$head = 2;
		if ( $len & 0x80 ) {
			$bytes = $len & 0x7F;
			if ( $bytes < 1 || $bytes > 4 || $pos + 2 + $bytes > $limit ) {
				throw new RuntimeException( 'der length' );
			}
			$len = 0;
			for ( $i = 0; $i < $bytes; $i++ ) {
				$len = ( $len << 8 ) | ord( $der[ $pos + 2 + $i ] );
			}
			$head += $bytes;
		}
		if ( $pos + $head + $len > $limit ) {
			throw new RuntimeException( 'der overrun' );
		}
		return array(
			'tag'   => $tag,
			'start' => $pos + $head,
			'end'   => $pos + $head + $len,
		);
	}

	/**
	 * CN and O from a Name (SEQUENCE OF SET OF AttributeTypeAndValue).
	 *
	 * @return array{cn:string, o:string}
	 * @throws RuntimeException On malformed input.
	 */
	private static function der_name( string $der, int $start, int $end ): array {
		$out = array(
			'cn' => '',
			'o'  => '',
		);
		$pos = $start;
		while ( $pos < $end ) {
			$set = self::der_tlv( $der, $pos, $end );
			$pos = $set['end'];
			$p   = $set['start'];
			while ( $p < $set['end'] ) {
				$atv = self::der_tlv( $der, $p, $set['end'] );
				$p   = $atv['end'];
				$oid = self::der_tlv( $der, $atv['start'], $atv['end'] );
				if ( 0x06 !== $oid['tag'] ) {
					continue;
				}
				$value = self::der_tlv( $der, $oid['end'], $atv['end'] );
				$text  = substr( $der, $value['start'], $value['end'] - $value['start'] );
				if ( 0x1E === $value['tag'] && function_exists( 'mb_convert_encoding' ) ) {
					$text = (string) mb_convert_encoding( $text, 'UTF-8', 'UTF-16BE' );
				}
				$id = substr( $der, $oid['start'], $oid['end'] - $oid['start'] );
				if ( "\x55\x04\x03" === $id && '' === $out['cn'] ) {
					$out['cn'] = $text;
				} elseif ( "\x55\x04\x0A" === $id && '' === $out['o'] ) {
					$out['o'] = $text;
				}
			}
		}
		return $out;
	}

	/**
	 * Printable text, control characters removed, capped.
	 */
	private static function printable( string $text, int $max ): string {
		$text = (string) preg_replace( '/[^\P{C}\t]/u', '', $text );
		return mb_substr( trim( $text ), 0, $max );
	}
}
