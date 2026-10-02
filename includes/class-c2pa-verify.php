<?php
/**
 * C2PA signature verification: the COSE_Sign1 signature over the claim, the
 * hashes of every referenced assertion, the signer's certificate profile and
 * chain up to the C2PA trust list, and an RFC 3161 time stamp (sigTst2/sigTst)
 * with its own CMS signature and TSA chain.
 *
 * Runs on PHP 7.4 with the OpenSSL extension; Ed25519 uses libsodium (or the
 * sodium_compat polyfill WordPress ships). Not covered: revocation (OCSP and
 * CRL), ingredient manifests and BMFF hashes. The result names what was
 * checked, so a "trusted" verdict never claims more than that.
 *
 * Trust anchors: the C2PA Conformance Program trust lists (data/c2pa/,
 * CC-BY-4.0, see data/c2pa/SOURCE.md), extendable through the filters
 * transparai_c2pa_trust_anchors and transparai_c2pa_tsa_anchors.
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
 * C2PA manifest signature verifier.
 */
final class TransparAI_C2PA_Verify {

	/* COSE algorithm identifiers (RFC 9053) the C2PA specification allows. */
	private const COSE_ALGS = array(
		-7  => array( 'ecdsa', 'sha256' ),
		-35 => array( 'ecdsa', 'sha384' ),
		-36 => array( 'ecdsa', 'sha512' ),
		-37 => array( 'pss', 'sha256' ),
		-38 => array( 'pss', 'sha384' ),
		-39 => array( 'pss', 'sha512' ),
		-8  => array( 'eddsa', '' ),
	);

	/* Hash algorithm OIDs (DER content bytes) used by time stamp tokens. */
	private const DIGEST_OIDS = array(
		"\x60\x86\x48\x01\x65\x03\x04\x02\x01" => 'sha256',
		"\x60\x86\x48\x01\x65\x03\x04\x02\x02" => 'sha384',
		"\x60\x86\x48\x01\x65\x03\x04\x02\x03" => 'sha512',
		"\x2b\x0e\x03\x02\x1a"                 => 'sha1',
	);

	private const OID_TST_INFO       = "\x2a\x86\x48\x86\xf7\x0d\x01\x09\x10\x01\x04";
	private const OID_MESSAGE_DIGEST = "\x2a\x86\x48\x86\xf7\x0d\x01\x09\x04";
	private const OID_RSA_ENCRYPTION = "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";

	/* Extended key usages the C2PA specification accepts for claim signers. */
	private const SIGNER_EKUS = array( 'E-mail Protection', '1.3.6.1.5.5.7.3.4', 'Document Signing', '1.3.6.1.5.5.7.3.36', '1.3.6.1.4.1.62558.2.1' );

	/**
	 * Verify the active manifest of a manifest store.
	 *
	 * @param string $store JUMBF manifest store bytes.
	 * @return array{sig:string, reason:string, issuer:string, tst:string, tsa:string, tsa_trusted:bool}
	 *         sig: trusted (valid, chain ends at a trust anchor), untrusted (valid, signer not on the
	 *         trust list), invalid (a check failed, reason says which), unsupported (cannot be judged).
	 */
	public static function verify( string $store ): array {
		$out = array(
			'sig'         => 'unsupported',
			'reason'      => '',
			'issuer'      => '',
			'tst'         => '',
			'tsa'         => '',
			'tsa_trusted' => false,
		);

		$manifest = self::active_manifest( $store );
		if ( null === $manifest ) {
			$out['reason'] = 'parse_error';
			return $out;
		}

		$claim = TransparAI_C2PA::cbor_decode( $manifest['claim'] );
		$cose  = TransparAI_C2PA::cbor_decode( $manifest['signature'] );
		if ( ! is_array( $claim ) || ! is_array( $cose ) || count( $cose ) < 4 || ! is_string( $cose[0] ) || ! is_string( $cose[3] ) ) {
			$out['reason'] = 'parse_error';
			return $out;
		}

		/* 1. Every assertion the claim references must hash to the stored value. */
		$assertions = self::check_assertions( $claim, $manifest['assertions'] );
		if ( '' !== $assertions ) {
			return self::invalid( $out, $assertions );
		}

		/* 2. The COSE signature over the claim. */
		$protected_header = TransparAI_C2PA::cbor_decode( $cose[0] );
		$unprotected      = is_array( $cose[1] ) ? $cose[1] : array();
		if ( ! is_array( $protected_header ) || ! isset( $protected_header[1] ) || ! is_int( $protected_header[1] ) ) {
			$out['reason'] = 'parse_error';
			return $out;
		}
		if ( ! isset( self::COSE_ALGS[ $protected_header[1] ] ) ) {
			$out['reason'] = 'unknown_alg';
			return $out;
		}
		$chain = self::cert_chain( $protected_header, $unprotected );
		if ( array() === $chain ) {
			return self::invalid( $out, 'no_certificate' );
		}
		$out['issuer'] = self::issuer_name( $chain[0] );

		$to_be_signed = self::sig_structure( 'Signature1', $cose[0], $manifest['claim'] );
		$signature_ok = self::verify_signature( $protected_header[1], $to_be_signed, $cose[3], $chain[0] );
		if ( null === $signature_ok ) {
			$out['reason'] = 'crypto_unavailable';
			return $out;
		}
		if ( ! $signature_ok ) {
			return self::invalid( $out, 'signature' );
		}

		/* 3. The signing certificate's profile. */
		$leaf = openssl_x509_parse( self::pem( $chain[0] ) );
		if ( ! is_array( $leaf ) ) {
			return self::invalid( $out, 'certificate' );
		}
		if ( ! self::signer_profile_ok( $leaf ) ) {
			return self::invalid( $out, 'certificate_profile' );
		}

		/* 4. Time: a valid time stamp fixes the moment of signing, otherwise "now". */
		$time  = time();
		$token = self::time_stamp_token( $unprotected );
		if ( null !== $token ) {
			$payload = 'sigTst2' === $token['kind'] ? self::cbor_bstr( $cose[3] ) : $manifest['claim'];
			$imprint = self::sig_structure( 'CounterSignature', $cose[0], $payload );
			$stamp   = self::verify_time_stamp( $token['token'], $imprint );
			if ( null === $stamp ) {
				return self::invalid( $out, 'time_stamp' );
			}
			$time               = $stamp['time'];
			$out['tst']         = gmdate( 'Y-m-d\TH:i:s\Z', $stamp['time'] );
			$out['tsa']         = $stamp['tsa'];
			$out['tsa_trusted'] = $stamp['trusted'];
			if ( ! $stamp['trusted'] ) {
				$time = time(); /* An unverified clock proves nothing about the signing moment. */
			}
		}

		/* 5. The chain: validity at that time, signatures, and a trust anchor at its end. */
		$trust = self::chain_trust( $chain, self::anchors( 'signer' ), $time );
		if ( 'expired' === $trust ) {
			return self::invalid( $out, 'certificate_expired' );
		}
		if ( 'broken' === $trust ) {
			return self::invalid( $out, 'certificate_chain' );
		}
		$out['sig'] = 'trusted' === $trust ? 'trusted' : 'untrusted';
		return $out;
	}

	/**
	 * Mark a result invalid with a reason code.
	 *
	 * @param array<string, mixed> $out    Result so far.
	 * @param string               $reason Reason code.
	 * @return array<string, mixed>
	 */
	private static function invalid( array $out, string $reason ): array {
		$out['sig']    = 'invalid';
		$out['reason'] = $reason;
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Manifest parts
	 * ------------------------------------------------------------------- */

	/**
	 * Claim bytes, signature bytes and assertion boxes of the active manifest.
	 *
	 * Assertion hashes cover the assertion superbox without its own box
	 * header (the description box plus the content boxes), which is what is
	 * stored here per label.
	 *
	 * @return array{claim:string, signature:string, assertions:array<string, string>}|null
	 */
	private static function active_manifest( string $store ): ?array {
		$top = TransparAI_C2PA::jumbf_boxes( $store, 0, strlen( $store ) );
		if ( null === $top || ! isset( $top[0] ) || 'c2pa' !== $top[0]['label'] ) {
			return null;
		}
		$manifests = TransparAI_C2PA::jumbf_boxes( $store, $top[0]['start'], $top[0]['end'] );
		if ( null === $manifests ) {
			return null;
		}
		$manifests = array_values( array_filter( $manifests, static fn( array $box ): bool => 'jumb' === $box['type'] ) );
		if ( array() === $manifests ) {
			return null;
		}
		$active = $manifests[ count( $manifests ) - 1 ];
		$parts  = TransparAI_C2PA::jumbf_boxes( $store, $active['start'], $active['end'] );
		if ( null === $parts ) {
			return null;
		}

		$out = array(
			'claim'      => '',
			'signature'  => '',
			'assertions' => array(),
		);
		foreach ( $parts as $part ) {
			if ( 'jumb' !== $part['type'] ) {
				continue;
			}
			if ( str_starts_with( $part['label'], 'c2pa.claim' ) ) {
				$out['claim'] = self::cbor_content( $store, $part );
			} elseif ( 'c2pa.signature' === $part['label'] ) {
				$out['signature'] = self::cbor_content( $store, $part );
			} elseif ( 'c2pa.assertions' === $part['label'] ) {
				$list = TransparAI_C2PA::jumbf_boxes( $store, $part['start'], $part['end'] );
				foreach ( (array) $list as $assertion ) {
					if ( 'jumb' === $assertion['type'] && '' !== $assertion['label'] ) {
						$out['assertions'][ $assertion['label'] ] = substr( $store, $assertion['offset'] + $assertion['head'], $assertion['end'] - $assertion['offset'] - $assertion['head'] );
					}
				}
			}
		}
		return '' === $out['claim'] || '' === $out['signature'] ? null : $out;
	}

	/**
	 * Raw bytes of the cbor content box inside a superbox.
	 *
	 * @param array<string, mixed> $box Superbox.
	 */
	private static function cbor_content( string $store, array $box ): string {
		foreach ( (array) TransparAI_C2PA::jumbf_boxes( $store, $box['start'], $box['end'] ) as $child ) {
			if ( 'cbor' === $child['type'] ) {
				return substr( $store, $child['start'], $child['end'] - $child['start'] );
			}
		}
		return '';
	}

	/**
	 * Check the hashed URIs of the claim against the assertion boxes.
	 *
	 * @param array<string, mixed>  $claim      Decoded claim.
	 * @param array<string, string> $assertions Label => hashed bytes.
	 * @return string '' when all match, else a reason code.
	 */
	private static function check_assertions( array $claim, array $assertions ): string {
		$refs = array();
		foreach ( array( 'created_assertions', 'gathered_assertions', 'assertions' ) as $key ) {
			if ( isset( $claim[ $key ] ) && is_array( $claim[ $key ] ) ) {
				$refs = array_merge( $refs, $claim[ $key ] );
			}
		}
		if ( array() === $refs ) {
			return 'no_assertions';
		}
		$default = is_string( $claim['alg'] ?? null ) ? strtolower( $claim['alg'] ) : 'sha256';
		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) || ! is_string( $ref['url'] ?? null ) || ! is_string( $ref['hash'] ?? null ) ) {
				return 'assertion_reference';
			}
			$label = (string) substr( $ref['url'], (int) strrpos( $ref['url'], '/' ) + 1 );
			if ( ! isset( $assertions[ $label ] ) ) {
				return 'assertion_missing';
			}
			$alg = is_string( $ref['alg'] ?? null ) ? strtolower( $ref['alg'] ) : $default;
			if ( ! in_array( $alg, array( 'sha256', 'sha384', 'sha512' ), true ) ) {
				return 'assertion_alg';
			}
			if ( ! hash_equals( $ref['hash'], hash( $alg, $assertions[ $label ], true ) ) ) {
				return 'assertion_hash';
			}
		}
		return '';
	}

	/* ---------------------------------------------------------------------
	 * COSE
	 * ------------------------------------------------------------------- */

	/**
	 * The certificates of the x5chain header (label 33), leaf first, DER.
	 *
	 * @param array<int|string, mixed> $protected_header   Protected header.
	 * @param array<int|string, mixed> $unprotected Unprotected header.
	 * @return string[]
	 */
	private static function cert_chain( array $protected_header, array $unprotected ): array {
		$chain = $protected_header[33] ?? $unprotected[33] ?? $unprotected['x5chain'] ?? null;
		if ( is_string( $chain ) ) {
			return array( $chain );
		}
		if ( ! is_array( $chain ) ) {
			return array();
		}
		return array_values( array_filter( $chain, 'is_string' ) );
	}

	/**
	 * CBOR Sig_structure (RFC 9052 section 4.4): context, body protected,
	 * external AAD (empty), payload. Signature1 for the claim signature,
	 * CounterSignature for what a C2PA time stamp covers.
	 */
	private static function sig_structure( string $context, string $protected_header, string $payload ): string {
		return "\x84" . self::cbor_head( 3, strlen( $context ) ) . $context
			. self::cbor_bstr( $protected_header ) . "\x40" . self::cbor_bstr( $payload );
	}

	/**
	 * A CBOR byte string.
	 */
	private static function cbor_bstr( string $bytes ): string {
		return self::cbor_head( 2, strlen( $bytes ) ) . $bytes;
	}

	/**
	 * A CBOR head for a major type and length.
	 */
	private static function cbor_head( int $major, int $length ): string {
		if ( $length < 24 ) {
			return chr( ( $major << 5 ) | $length );
		}
		if ( $length < 256 ) {
			return chr( ( $major << 5 ) | 24 ) . chr( $length );
		}
		if ( $length < 65536 ) {
			return chr( ( $major << 5 ) | 25 ) . pack( 'n', $length );
		}
		return chr( ( $major << 5 ) | 26 ) . pack( 'N', $length );
	}

	/**
	 * Verify a COSE signature with the leaf certificate's public key.
	 *
	 * @return bool|null Null when the platform lacks the needed primitive.
	 */
	private static function verify_signature( int $alg, string $data, string $signature, string $leaf ): ?bool {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return null;
		}
		list( $kind, $hash ) = self::COSE_ALGS[ $alg ];

		if ( 'eddsa' === $kind ) {
			if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
				return null;
			}
			$spki = self::spki( $leaf );
			if ( null === $spki || strlen( $signature ) !== 64 ) {
				return false;
			}
			try {
				return sodium_crypto_sign_verify_detached( $signature, $data, substr( $spki['key'], -32 ) );
			} catch ( Throwable $e ) {
				return false;
			}
		}

		if ( 'pss' === $kind ) {
			return self::verify_pss( $data, $signature, $leaf, $hash );
		}

		$key = openssl_pkey_get_public( self::pem( $leaf ) );
		if ( false === $key || strlen( $signature ) % 2 || ! self::key_is( $key, OPENSSL_KEYTYPE_EC ) ) {
			return false;
		}
		$half = intdiv( strlen( $signature ), 2 );
		$der  = self::der_sequence( self::der_integer( substr( $signature, 0, $half ) ) . self::der_integer( substr( $signature, $half ) ) );
		return 1 === openssl_verify( $data, $der, $key, $hash );
	}

	/**
	 * RSASSA-PSS verification (RFC 8017 section 8.1.2, MGF1 with the same hash,
	 * salt length = hash length as C2PA requires).
	 *
	 * PHP's OpenSSL binding has no PSS padding mode and does not load keys with
	 * the id-RSASSA-PSS algorithm identifier, so the key is re-wrapped as plain
	 * rsaEncryption, the raw RSA operation runs through OPENSSL_NO_PADDING and
	 * the encoding is checked here.
	 */
	private static function verify_pss( string $message, string $signature, string $leaf, string $hash ): bool {
		$spki = self::spki( $leaf );
		if ( null === $spki ) {
			return false;
		}
		$rsa = self::der_sequence(
			self::der_sequence( "\x06" . chr( strlen( self::OID_RSA_ENCRYPTION ) ) . self::OID_RSA_ENCRYPTION . "\x05\x00" )
			. "\x03" . self::der_length( strlen( $spki['key'] ) + 1 ) . "\x00" . $spki['key']
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM encoding of a public key.
		$key = openssl_pkey_get_public( "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $rsa ), 64, "\n" ) . "-----END PUBLIC KEY-----\n" );
		if ( false === $key ) {
			return false;
		}
		$details = openssl_pkey_get_details( $key );
		$bits    = (int) ( $details['bits'] ?? 0 );
		if ( $bits < 2048 || strlen( $signature ) !== intdiv( $bits + 7, 8 ) ) {
			return false;
		}
		if ( ! openssl_public_decrypt( $signature, $encoded, $key, OPENSSL_NO_PADDING ) ) {
			return false;
		}

		$em_bits = $bits - 1;
		$em_len  = intdiv( $em_bits + 7, 8 );
		$encoded = substr( $encoded, -$em_len );
		$h_len   = strlen( hash( $hash, '', true ) );
		if ( $em_len < 2 * $h_len + 2 || "\xbc" !== substr( $encoded, -1 ) ) {
			return false;
		}
		$masked = substr( $encoded, 0, $em_len - $h_len - 1 );
		$digest = substr( $encoded, $em_len - $h_len - 1, $h_len );
		$mask   = '';
		$needed = strlen( $masked );
		for ( $counter = 0; strlen( $mask ) < $needed; $counter++ ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed, Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- MGF1 grows the mask until it covers the block.
			$mask .= hash( $hash, $digest . pack( 'N', $counter ), true );
		}
		$block    = $masked ^ substr( $mask, 0, strlen( $masked ) );
		$block[0] = chr( ord( $block[0] ) & ( 0xFF >> ( 8 * $em_len - $em_bits ) ) );
		$padding  = $em_len - 2 * $h_len - 2;
		if ( '' !== trim( substr( $block, 0, $padding ), "\x00" ) || "\x01" !== $block[ $padding ] ) {
			return false;
		}
		$salt = substr( $block, $padding + 1 );
		return hash_equals( $digest, hash( $hash, str_repeat( "\x00", 8 ) . hash( $hash, $message, true ) . $salt, true ) );
	}

	/* ---------------------------------------------------------------------
	 * Certificates
	 * ------------------------------------------------------------------- */

	/**
	 * The signer's certificate must carry a C2PA signing usage and not be a CA.
	 *
	 * @param array<string, mixed> $cert openssl_x509_parse() result.
	 */
	private static function signer_profile_ok( array $cert ): bool {
		$extensions = (array) ( $cert['extensions'] ?? array() );
		if ( str_contains( (string) ( $extensions['basicConstraints'] ?? '' ), 'CA:TRUE' ) ) {
			return false;
		}
		$eku = (string) ( $extensions['extendedKeyUsage'] ?? '' );
		foreach ( self::SIGNER_EKUS as $usage ) {
			if ( str_contains( $eku, $usage ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Walk the chain from the leaf to a trust anchor.
	 *
	 * @param string[] $chain   DER certificates, leaf first, intermediates after.
	 * @param string[] $anchors PEM trust anchors.
	 * @param int      $time    Moment the certificates must be valid at.
	 * @return string trusted|untrusted|expired|broken
	 */
	private static function chain_trust( array $chain, array $anchors, int $time ): string {
		$current = self::pem( $chain[0] );
		$pool    = array_map( array( self::class, 'pem' ), array_slice( $chain, 1 ) );

		for ( $depth = 0; $depth < 6; $depth++ ) {
			$info = openssl_x509_parse( $current );
			if ( ! is_array( $info ) ) {
				return 'broken';
			}
			if ( $time < (int) $info['validFrom_time_t'] || $time > (int) $info['validTo_time_t'] ) {
				return 'expired';
			}
			foreach ( $anchors as $anchor ) {
				if ( self::same_cert( $current, $anchor ) || self::issued_by( $current, $info, $anchor ) ) {
					return 'trusted';
				}
			}
			$next = null;
			foreach ( $pool as $index => $candidate ) {
				if ( self::issued_by( $current, $info, $candidate ) ) {
					$next = $candidate;
					unset( $pool[ $index ] );
					break;
				}
			}
			if ( null === $next ) {
				/* Self-signed end of the shipped chain, or a missing intermediate. */
				return self::issued_by( $current, $info, $current ) || array() === $pool ? 'untrusted' : 'broken';
			}
			$current = $next;
		}
		return 'untrusted';
	}

	/**
	 * Whether $cert was issued and signed by $issuer.
	 *
	 * @param array<string, mixed> $info openssl_x509_parse() of $cert.
	 */
	private static function issued_by( string $cert, array $info, string $issuer ): bool {
		$issuer_info = openssl_x509_parse( $issuer );
		if ( ! is_array( $issuer_info ) || $issuer_info['subject'] !== $info['issuer'] ) {
			return false;
		}
		return self::cert_signed_by( self::der( $cert ), self::der( $issuer ) );
	}

	/**
	 * Whether a certificate's signature verifies with the issuer's key.
	 *
	 * Done by hand rather than with openssl_x509_verify(): on PHP 7.4 that
	 * function knows RSA keys only and raises a warning for every EC, PSS or
	 * Ed25519 key, while C2PA chains use all of them.
	 */
	private static function cert_signed_by( string $cert, string $issuer ): bool {
		$outer = self::tlv( $cert, 0, strlen( $cert ) );
		$parts = null === $outer ? array() : self::children( $cert, $outer['start'], $outer['end'] );
		if ( count( $parts ) < 3 || 0x03 !== $parts[2]['tag'] ) {
			return false;
		}
		$tbs       = substr( $cert, $parts[0]['offset'], $parts[0]['end'] - $parts[0]['offset'] );
		$alg       = self::children( $cert, $parts[1]['start'], $parts[1]['end'] );
		$oid       = isset( $alg[0] ) ? self::content( $cert, $alg[0] ) : '';
		$signature = substr( $cert, $parts[2]['start'] + 1, $parts[2]['end'] - $parts[2]['start'] - 1 );

		$ecdsa = array(
			"\x2a\x86\x48\xce\x3d\x04\x03\x02" => 'sha256',
			"\x2a\x86\x48\xce\x3d\x04\x03\x03" => 'sha384',
			"\x2a\x86\x48\xce\x3d\x04\x03\x04" => 'sha512',
		);
		$rsa   = array(
			"\x2a\x86\x48\x86\xf7\x0d\x01\x01\x0b" => 'sha256',
			"\x2a\x86\x48\x86\xf7\x0d\x01\x01\x0c" => 'sha384',
			"\x2a\x86\x48\x86\xf7\x0d\x01\x01\x0d" => 'sha512',
		);

		if ( isset( $ecdsa[ $oid ] ) || isset( $rsa[ $oid ] ) ) {
			$key = openssl_pkey_get_public( self::pem( $issuer ) );
			if ( false === $key ) {
				return false;
			}
			/* Names repeat across CAs; a key of another type is simply not the issuer (PHP 7.4 would warn). */
			if ( ! self::key_is( $key, isset( $ecdsa[ $oid ] ) ? OPENSSL_KEYTYPE_EC : OPENSSL_KEYTYPE_RSA ) ) {
				return false;
			}
			return 1 === openssl_verify( $tbs, $signature, $key, $ecdsa[ $oid ] ?? $rsa[ $oid ] );
		}
		if ( "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x0a" === $oid ) {
			/* RSASSA-PSS: the hash sits in the parameters ([0] hashAlgorithm). */
			$hash = '';
			if ( isset( $alg[1] ) ) {
				foreach ( self::children( $cert, $alg[1]['start'], $alg[1]['end'] ) as $param ) {
					if ( 0xA0 === $param['tag'] ) {
						$hash_alg = self::tlv( $cert, $param['start'], $param['end'] );
						$hash_oid = null === $hash_alg ? array() : self::children( $cert, $hash_alg['start'], $hash_alg['end'] );
						$hash     = isset( $hash_oid[0] ) ? ( self::DIGEST_OIDS[ self::content( $cert, $hash_oid[0] ) ] ?? '' ) : '';
					}
				}
			}
			return in_array( $hash, array( 'sha256', 'sha384', 'sha512' ), true ) && self::verify_pss( $tbs, $signature, $issuer, $hash );
		}
		if ( "\x2b\x65\x70" === $oid ) {
			$spki = self::spki( $issuer );
			if ( null === $spki || ! function_exists( 'sodium_crypto_sign_verify_detached' ) || 64 !== strlen( $signature ) ) {
				return false;
			}
			try {
				return sodium_crypto_sign_verify_detached( $signature, $tbs, substr( $spki['key'], -32 ) );
			} catch ( Throwable $e ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * PEM certificate to DER (DER passes through).
	 */
	private static function der( string $cert ): string {
		if ( ! str_starts_with( $cert, '-----BEGIN' ) ) {
			return $cert;
		}
		return (string) base64_decode( (string) preg_replace( '/-----[^-]+-----|\s+/', '', $cert ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- PEM decoding of a certificate.
	}

	/**
	 * Whether an OpenSSL key is of the given type (OPENSSL_KEYTYPE_*).
	 *
	 * @param resource|OpenSSLAsymmetricKey $key  Public key.
	 * @param int                           $type Expected type.
	 */
	private static function key_is( $key, int $type ): bool {
		$details = openssl_pkey_get_details( $key );
		return is_array( $details ) && $type === (int) $details['type'];
	}

	/**
	 * Whether two PEM certificates are the same certificate.
	 */
	private static function same_cert( string $a, string $b ): bool {
		return openssl_x509_fingerprint( $a, 'sha256' ) === openssl_x509_fingerprint( $b, 'sha256' );
	}

	/**
	 * Trust anchors as PEM strings.
	 *
	 * @param string $kind signer|tsa.
	 * @return string[]
	 */
	public static function anchors( string $kind ): array {
		static $cache = array();
		if ( ! isset( $cache[ $kind ] ) ) {
			$file = TRANSPARAI_PLUGIN_DIR . 'data/c2pa/' . ( 'tsa' === $kind ? 'C2PA-TSA-TRUST-LIST.pem' : 'C2PA-TRUST-LIST.pem' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin data file.
			$bundle         = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
			$cache[ $kind ] = self::split_pem( $bundle );
		}
		if ( 'tsa' === $kind ) {
			/**
			 * Filters the trust anchors for C2PA time stamp authorities.
			 *
			 * @param string[] $anchors PEM certificates.
			 */
			return (array) apply_filters( 'transparai_c2pa_tsa_anchors', $cache[ $kind ] );
		}
		/**
		 * Filters the trust anchors for C2PA claim signers.
		 *
		 * @param string[] $anchors PEM certificates.
		 */
		return (array) apply_filters( 'transparai_c2pa_trust_anchors', $cache[ $kind ] );
	}

	/**
	 * Individual PEM certificates from a bundle.
	 *
	 * @return string[]
	 */
	public static function split_pem( string $bundle ): array {
		preg_match_all( '/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $bundle, $matches );
		return array_map( static fn( string $pem ): string => $pem . "\n", $matches[0] );
	}

	/**
	 * DER certificate to PEM.
	 */
	private static function pem( string $der ): string {
		if ( str_starts_with( $der, '-----BEGIN' ) ) {
			return $der;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PEM encoding of a certificate.
		return "-----BEGIN CERTIFICATE-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END CERTIFICATE-----\n";
	}

	/**
	 * Common name (or organisation) of a certificate's issuer.
	 */
	private static function issuer_name( string $der ): string {
		$info = openssl_x509_parse( self::pem( $der ) );
		if ( ! is_array( $info ) ) {
			return '';
		}
		$issuer = (array) ( $info['issuer'] ?? array() );
		$name   = $issuer['CN'] ?? $issuer['O'] ?? '';
		return is_array( $name ) ? (string) reset( $name ) : (string) $name;
	}

	/**
	 * The raw public key bits of a DER certificate (subjectPublicKeyInfo BIT STRING content without the unused-bits byte).
	 *
	 * @return array{key:string}|null
	 */
	private static function spki( string $der ): ?array {
		$cert = self::tlv( $der, 0, strlen( $der ) );
		$tbs  = null === $cert ? null : self::tlv( $der, $cert['start'], $cert['end'] );
		if ( null === $tbs ) {
			return null;
		}
		$fields = self::children( $der, $tbs['start'], $tbs['end'] );
		if ( isset( $fields[0] ) && 0xA0 === $fields[0]['tag'] ) {
			array_shift( $fields );
		}
		$spki = $fields[5] ?? null;
		if ( null === $spki ) {
			return null;
		}
		$parts = self::children( $der, $spki['start'], $spki['end'] );
		if ( ! isset( $parts[1] ) || 0x03 !== $parts[1]['tag'] ) {
			return null;
		}
		return array( 'key' => substr( $der, $parts[1]['start'] + 1, $parts[1]['end'] - $parts[1]['start'] - 1 ) );
	}

	/* ---------------------------------------------------------------------
	 * RFC 3161 time stamp
	 * ------------------------------------------------------------------- */

	/**
	 * The first time stamp token in the unprotected header.
	 *
	 * @param array<int|string, mixed> $unprotected Unprotected header.
	 * @return array{kind:string, token:string}|null
	 */
	private static function time_stamp_token( array $unprotected ): ?array {
		foreach ( array( 'sigTst2', 'sigTst' ) as $kind ) {
			$token = $unprotected[ $kind ]['tstTokens'][0]['val'] ?? null;
			if ( is_string( $token ) && '' !== $token ) {
				return array(
					'kind'  => $kind,
					'token' => $token,
				);
			}
		}
		return null;
	}

	/**
	 * Verify a time stamp token for the given data.
	 *
	 * Checks the message imprint, the CMS signature over the signed
	 * attributes (with the messageDigest attribute matching the TSTInfo) and
	 * the TSA certificate chain against the TSA trust list.
	 *
	 * @return array{time:int, tsa:string, trusted:bool}|null Null when the token does not hold.
	 */
	private static function verify_time_stamp( string $token, string $data ): ?array {
		$info   = self::tlv( $token, 0, strlen( $token ) );
		$fields = null === $info ? array() : self::children( $token, $info['start'], $info['end'] );
		/*
		 * Older writers store the whole TimeStampResp (PKIStatusInfo, then
		 * the token) instead of the bare token; unwrap it.
		 */
		if ( isset( $fields[0], $fields[1] ) && 0x30 === $fields[0]['tag'] && 0x30 === $fields[1]['tag'] ) {
			$token  = substr( $token, $fields[1]['offset'], $fields[1]['end'] - $fields[1]['offset'] );
			$info   = self::tlv( $token, 0, strlen( $token ) );
			$fields = null === $info ? array() : self::children( $token, $info['start'], $info['end'] );
		}
		if ( ! isset( $fields[1] ) || 0xA0 !== $fields[1]['tag'] ) {
			return null;
		}
		$signed_data = self::tlv( $token, $fields[1]['start'], $fields[1]['end'] );
		$sd          = null === $signed_data ? array() : self::children( $token, $signed_data['start'], $signed_data['end'] );
		if ( count( $sd ) < 4 ) {
			return null;
		}

		/* encapContentInfo: eContentType id-ct-TSTInfo, [0] OCTET STRING TSTInfo. */
		$encap = self::children( $token, $sd[2]['start'], $sd[2]['end'] );
		if ( ! isset( $encap[1] ) || self::OID_TST_INFO !== self::content( $token, $encap[0] ) ) {
			return null;
		}
		$octets = self::tlv( $token, $encap[1]['start'], $encap[1]['end'] );
		if ( null === $octets ) {
			return null;
		}
		$tst_info = self::content( $token, $octets );
		$tst      = self::children( $tst_info, 0, strlen( $tst_info ) );
		$root     = isset( $tst[0] ) ? self::children( $tst_info, $tst[0]['start'], $tst[0]['end'] ) : array();
		if ( count( $root ) < 5 ) {
			return null;
		}

		/* messageImprint: hash of the data under the named algorithm. */
		$imprint  = self::children( $tst_info, $root[2]['start'], $root[2]['end'] );
		$alg_id   = isset( $imprint[0] ) ? self::children( $tst_info, $imprint[0]['start'], $imprint[0]['end'] ) : array();
		$alg_name = isset( $alg_id[0] ) ? ( self::DIGEST_OIDS[ self::content( $tst_info, $alg_id[0] ) ] ?? '' ) : '';
		if ( '' === $alg_name || 'sha1' === $alg_name || ! isset( $imprint[1] ) || ! hash_equals( self::content( $tst_info, $imprint[1] ), hash( $alg_name, $data, true ) ) ) {
			return null;
		}
		$gen_time = self::generalized_time( self::content( $tst_info, $root[4] ) );
		if ( null === $gen_time ) {
			return null;
		}

		/* certificates [0] and the single signerInfo. */
		$certs   = array();
		$signers = null;
		foreach ( array_slice( $sd, 3 ) as $node ) {
			if ( 0xA0 === $node['tag'] ) {
				foreach ( self::children( $token, $node['start'], $node['end'] ) as $cert ) {
					$certs[] = substr( $token, $cert['offset'], $cert['end'] - $cert['offset'] );
				}
			} elseif ( 0x31 === $node['tag'] ) {
				$signers = self::children( $token, $node['start'], $node['end'] );
			}
		}
		if ( empty( $signers ) || array() === $certs ) {
			return null;
		}
		$signer = self::children( $token, $signers[0]['start'], $signers[0]['end'] );
		$attrs  = null;
		foreach ( $signer as $index => $node ) {
			if ( 0xA0 === $node['tag'] ) {
				$attrs = $index;
				break;
			}
		}
		if ( null === $attrs || ! isset( $signer[ $attrs + 2 ] ) ) {
			return null;
		}
		$digest_alg = self::children( $token, $signer[ $attrs - 1 ]['start'], $signer[ $attrs - 1 ]['end'] );
		$digest     = isset( $digest_alg[0] ) ? ( self::DIGEST_OIDS[ self::content( $token, $digest_alg[0] ) ] ?? '' ) : '';
		if ( '' === $digest || 'sha1' === $digest ) {
			return null;
		}

		/* The messageDigest attribute must cover the TSTInfo. */
		$message_digest = '';
		foreach ( self::children( $token, $signer[ $attrs ]['start'], $signer[ $attrs ]['end'] ) as $attribute ) {
			$pair = self::children( $token, $attribute['start'], $attribute['end'] );
			if ( isset( $pair[1] ) && self::OID_MESSAGE_DIGEST === self::content( $token, $pair[0] ) ) {
				$values         = self::children( $token, $pair[1]['start'], $pair[1]['end'] );
				$message_digest = isset( $values[0] ) ? self::content( $token, $values[0] ) : '';
			}
		}
		if ( '' === $message_digest || ! hash_equals( $message_digest, hash( $digest, $tst_info, true ) ) ) {
			return null;
		}

		/* The signature covers the signed attributes, DER-encoded as a SET. */
		$signed_attrs = "\x31" . substr( $token, $signer[ $attrs ]['offset'] + 1, $signer[ $attrs ]['end'] - $signer[ $attrs ]['offset'] - 1 );
		$signature    = self::content( $token, $signer[ $attrs + 2 ] );
		$tsa_cert     = null;
		$sig_alg      = self::children( $token, $signer[ $attrs + 1 ]['start'], $signer[ $attrs + 1 ]['end'] );
		$sig_oid      = isset( $sig_alg[0] ) ? self::content( $token, $sig_alg[0] ) : '';
		$key_type     = str_starts_with( $sig_oid, '*HÎ=' ) ? OPENSSL_KEYTYPE_EC : OPENSSL_KEYTYPE_RSA;
		foreach ( $certs as $cert ) {
			$key = openssl_pkey_get_public( self::pem( $cert ) );
			if ( false !== $key && self::key_is( $key, $key_type ) && 1 === openssl_verify( $signed_attrs, $signature, $key, $digest ) ) {
				$tsa_cert = $cert;
				break;
			}
		}
		if ( null === $tsa_cert ) {
			return null;
		}

		$chain = array_merge( array( $tsa_cert ), array_values( array_filter( $certs, static fn( string $cert ): bool => $cert !== $tsa_cert ) ) );
		$trust = self::chain_trust( $chain, self::anchors( 'tsa' ), $gen_time );
		if ( 'expired' === $trust || 'broken' === $trust ) {
			return null;
		}
		$subject = openssl_x509_parse( self::pem( $tsa_cert ) );
		$name    = is_array( $subject ) ? ( $subject['subject']['CN'] ?? $subject['subject']['O'] ?? '' ) : '';
		return array(
			'time'    => $gen_time,
			'tsa'     => is_array( $name ) ? (string) reset( $name ) : (string) $name,
			'trusted' => 'trusted' === $trust,
		);
	}

	/**
	 * Parse a GeneralizedTime (YYYYMMDDHHMMSS[.f]Z) to a Unix timestamp.
	 */
	private static function generalized_time( string $value ): ?int {
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(?:\.\d+)?Z$/', $value, $m ) ) {
			return null;
		}
		return gmmktime( (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/* ---------------------------------------------------------------------
	 * DER
	 * ------------------------------------------------------------------- */

	/**
	 * One TLV at $pos: tag, header offset, content range.
	 *
	 * @return array{tag:int, offset:int, start:int, end:int}|null
	 */
	private static function tlv( string $der, int $pos, int $limit ): ?array {
		if ( $pos + 2 > $limit ) {
			return null;
		}
		$tag    = ord( $der[ $pos ] );
		$length = ord( $der[ $pos + 1 ] );
		$head   = 2;
		if ( $length & 0x80 ) {
			$bytes = $length & 0x7F;
			if ( $bytes < 1 || $bytes > 4 || $pos + 2 + $bytes > $limit ) {
				return null;
			}
			$length = 0;
			for ( $i = 0; $i < $bytes; $i++ ) {
				$length = ( $length << 8 ) | ord( $der[ $pos + 2 + $i ] );
			}
			$head += $bytes;
		}
		if ( $pos + $head + $length > $limit ) {
			return null;
		}
		return array(
			'tag'    => $tag,
			'offset' => $pos,
			'start'  => $pos + $head,
			'end'    => $pos + $head + $length,
		);
	}

	/**
	 * The TLVs directly inside a range.
	 *
	 * @return array<int, array{tag:int, offset:int, start:int, end:int}>
	 */
	private static function children( string $der, int $start, int $end ): array {
		$out = array();
		$pos = $start;
		while ( $pos < $end ) {
			$node = self::tlv( $der, $pos, $end );
			if ( null === $node ) {
				return array();
			}
			$out[] = $node;
			$pos   = $node['end'];
		}
		return $out;
	}

	/**
	 * Content bytes of a TLV.
	 *
	 * @param array{tag:int, offset:int, start:int, end:int} $node TLV.
	 */
	private static function content( string $der, array $node ): string {
		return substr( $der, $node['start'], $node['end'] - $node['start'] );
	}

	/**
	 * DER length field.
	 */
	private static function der_length( int $length ): string {
		if ( $length < 0x80 ) {
			return chr( $length );
		}
		$bytes = ltrim( pack( 'N', $length ), "\x00" );
		return chr( 0x80 | strlen( $bytes ) ) . $bytes;
	}

	/**
	 * DER SEQUENCE around encoded content.
	 */
	private static function der_sequence( string $content ): string {
		return "\x30" . self::der_length( strlen( $content ) ) . $content;
	}

	/**
	 * DER INTEGER from an unsigned big-endian value.
	 */
	private static function der_integer( string $value ): string {
		$value = ltrim( $value, "\x00" );
		if ( '' === $value || ord( $value[0] ) & 0x80 ) {
			$value = "\x00" . $value;
		}
		return "\x02" . self::der_length( strlen( $value ) ) . $value;
	}
}
