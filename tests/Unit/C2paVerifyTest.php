<?php
/**
 * C2PA signature verification tests: every COSE algorithm the specification
 * allows, the certificate chain against trust anchors, RFC 3161 time stamps
 * in both storage forms, and tampered manifests.
 *
 * Fixtures: alg-*.jpg and tsa-es256.jpg signed with the c2pa-rs test
 * certificates (test-roots.pem is their root bundle), adobe-20220124-*.jpg
 * from the C2PA public test files (CC BY-SA 4.0, see the fixtures README).
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class C2paVerifyTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	private function verify_file( string $fixture ): array {
		$store = TransparAI_C2PA::store_from_data( (string) file_get_contents( trai_fixture( $fixture ) ), 'jpeg' );
		$this->assertIsString( $store );
		return TransparAI_C2PA_Verify::verify( $store );
	}

	private function trust_test_roots(): void {
		add_filter(
			'transparai_c2pa_trust_anchors',
			static fn( array $anchors ): array => array_merge( $anchors, TransparAI_C2PA_Verify::split_pem( (string) file_get_contents( trai_fixture( 'test-roots.pem' ) ) ) )
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function algorithms(): array {
		$out = array();
		foreach ( array( 'es256', 'es384', 'es512', 'ps256', 'ps384', 'ps512', 'ed25519' ) as $alg ) {
			$out[ $alg ] = array( "alg-{$alg}.jpg" );
		}
		return $out;
	}

	/**
	 * @dataProvider algorithms
	 */
	public function test_every_algorithm_verifies_and_stays_untrusted_without_its_root( string $fixture ): void {
		$result = $this->verify_file( $fixture );
		$this->assertSame( 'untrusted', $result['sig'], $result['reason'] );
		$this->assertSame( 'Intermediate CA', $result['issuer'] );
	}

	/**
	 * @dataProvider algorithms
	 */
	public function test_every_algorithm_is_trusted_once_its_root_is_an_anchor( string $fixture ): void {
		$this->trust_test_roots();
		$this->assertSame( 'trusted', $this->verify_file( $fixture )['sig'] );
	}

	public function test_bundled_trust_lists_load(): void {
		$this->assertGreaterThanOrEqual( 20, count( TransparAI_C2PA_Verify::anchors( 'signer' ) ) );
		$this->assertGreaterThanOrEqual( 15, count( TransparAI_C2PA_Verify::anchors( 'tsa' ) ) );
		foreach ( TransparAI_C2PA_Verify::anchors( 'signer' ) as $pem ) {
			$this->assertIsArray( openssl_x509_parse( $pem ) );
		}
	}

	public function test_rfc3161_time_stamp_is_verified(): void {
		$this->trust_test_roots();
		$result = $this->verify_file( 'tsa-es256.jpg' );
		$this->assertSame( 'trusted', $result['sig'] );
		$this->assertSame( '2026-10-02T11:24:23Z', $result['tst'] );
		$this->assertStringContainsString( 'DigiCert', $result['tsa'] );
		$this->assertFalse( $result['tsa_trusted'], 'the public DigiCert TSA is not on the C2PA TSA list' );
	}

	public function test_ecdsa_time_stamp_from_google(): void {
		$result = $this->verify_file( 'gemini-tsa-es256.jpg' );
		$this->assertSame( 'trusted', $result['sig'], $result['reason'] );
		$this->assertSame( '2026-10-21T03:13:55Z', $result['tst'] );
		$this->assertSame( 'Google Core Time Stamping Authority T12', $result['tsa'] );
		$this->assertTrue( $result['tsa_trusted'] );
	}

	public function test_legacy_manifest_with_time_stamp_response(): void {
		$result = $this->verify_file( 'adobe-20220124-C.jpg' );
		$this->assertSame( 'untrusted', $result['sig'], $result['reason'] );
		$this->assertSame( '2023-01-24T14:48:56Z', $result['tst'] );
	}

	public function test_tampered_manifests_are_invalid(): void {
		$this->assertSame( 'signature', $this->verify_file( 'adobe-20220124-E-sig-CA.jpg' )['reason'] );
		$this->assertSame( 'assertion_hash', $this->verify_file( 'adobe-20220124-E-uri-CA.jpg' )['reason'] );

		/* An edited assertion in our own fixture. */
		$data  = (string) file_get_contents( trai_fixture( 'alg-es256.jpg' ) );
		$this->assertSame( 1, substr_count( $data, 'c2pa.created' ) );
		$data  = str_replace( 'c2pa.created', 'c2pa.cReated', $data );
		$store = TransparAI_C2PA::store_from_data( $data, 'jpeg' );
		$this->assertSame( 'assertion_hash', TransparAI_C2PA_Verify::verify( (string) $store )['reason'] );

		/* A flipped byte in the COSE signature. */
		$data      = (string) file_get_contents( trai_fixture( 'alg-ed25519.jpg' ) );
		$store     = (string) TransparAI_C2PA::store_from_data( $data, 'jpeg' );
		$signature = $this->cose_signature( $store );
		$at        = strpos( $data, $signature );
		$this->assertIsInt( $at );
		$data[ $at + 5 ] = chr( ord( $data[ $at + 5 ] ) ^ 0x01 );
		$result          = TransparAI_C2PA_Verify::verify( (string) TransparAI_C2PA::store_from_data( $data, 'jpeg' ) );
		$this->assertSame( 'invalid', $result['sig'] );
		$this->assertSame( 'signature', $result['reason'] );
	}

	public function test_summary_carries_the_verdict_and_the_detector_downgrades_a_bad_signature(): void {
		$info = TransparAI_C2PA::summary( (string) file_get_contents( trai_fixture( 'alg-es256.jpg' ) ), 'jpeg' );
		$this->assertSame( 'match', $info['hash'] );
		$this->assertSame( 'untrusted', $info['sig'] );

		$data      = (string) file_get_contents( trai_fixture( 'alg-es256.jpg' ) );
		$signature = $this->cose_signature( (string) TransparAI_C2PA::store_from_data( $data, 'jpeg' ) );
		$at        = (int) strpos( $data, $signature );

		$data[ $at + 3 ] = chr( ord( $data[ $at + 3 ] ) ^ 0x01 );
		$path            = sys_get_temp_dir() . '/trai-sig-' . uniqid() . '.jpg';
		file_put_contents( $path, $data );
		$result = TransparAI_Detector::detect_file( $path );
		unlink( $path );

		$this->assertSame( 'likely', $result['confidence'] );
		$this->assertStringContainsString( 'signature check failed', $result['evidence'] );
	}

	/**
	 * Chains from tests/fixtures/c2pa/pki/ (scripts/make-pki-fixtures.sh in the workspace).
	 *
	 * @return array<string, array{string[], string, string}> chain (leaf first), anchor, expected verdict.
	 */
	public function chains(): array {
		return array(
			'valid chain'                     => array( array( 'leaf' ), 'root', 'trusted' ),
			'issued by a non-CA signer'       => array( array( 'leaf-from-leaf', 'leaf' ), 'root', 'broken' ),
			'issued by a non-CA, no pool'     => array( array( 'leaf-from-leaf' ), 'leaf', 'untrusted' ),
			'anchor outside its validity'     => array( array( 'leaf-expired-root' ), 'root-expired', 'untrusted' ),
			'CA without keyCertSign'          => array( array( 'leaf-no-certsign', 'ca-no-certsign' ), 'root', 'broken' ),
			'path length exceeded'            => array( array( 'leaf-deep', 'ca-sub', 'ca-pathlen0' ), 'root', 'broken' ),
			'path length kept by one CA less' => array( array( 'ca-sub', 'ca-pathlen0' ), 'root', 'trusted' ),
		);
	}

	/**
	 * @dataProvider chains
	 *
	 * @param string[] $chain Fixture names, leaf first.
	 */
	public function test_chain_checks_every_issuer( array $chain, string $anchor, string $expected ): void {
		$method = new ReflectionMethod( TransparAI_C2PA_Verify::class, 'chain_trust' );
		$method->setAccessible( true );
		$der = array_map( fn( string $name ): string => $this->pki_der( $name ), $chain );
		$this->assertSame( $expected, $method->invoke( null, $der, array( $this->pki_pem( $anchor ) ), time() ) );
	}

	public function test_signer_profile_rejects_misused_certificates(): void {
		$method = new ReflectionMethod( TransparAI_C2PA_Verify::class, 'signer_profile_ok' );
		$method->setAccessible( true );
		$profile = fn( string $name ): bool => $method->invoke( null, openssl_x509_parse( $this->pki_pem( $name ) ) );

		$this->assertTrue( $profile( 'leaf' ) );
		$this->assertFalse( $profile( 'root' ), 'a CA is no signer' );
		$this->assertFalse( $profile( 'leaf-certsign' ), 'keyCertSign on a signer' );
		$this->assertFalse( $profile( 'leaf-timestamping' ), 'time stamping EKU on a signer' );
		$this->assertFalse( $profile( 'leaf-any-eku' ), 'anyExtendedKeyUsage on a signer' );
		$this->assertFalse( $profile( 'leaf-negative' ), 'negative serial number' );
	}

	public function test_ecdsa_algorithm_must_match_the_key_curve(): void {
		$data = 'Sig_structure stand-in';
		$this->assertTrue( $this->ecdsa_verdict( 'prime256v1', 32, $data ), 'ES256 with a P-256 key' );
		$this->assertFalse( $this->ecdsa_verdict( 'secp384r1', 48, $data ), 'ES256 with a P-384 key' );
	}

	public function test_eddsa_needs_an_ed25519_key(): void {
		$pair = sodium_crypto_sign_keypair();
		$data = 'Sig_structure stand-in';
		$sig  = sodium_crypto_sign_detached( $data, sodium_crypto_sign_secretkey( $pair ) );
		$key  = sodium_crypto_sign_publickey( $pair );

		$method = new ReflectionMethod( TransparAI_C2PA_Verify::class, 'verify_signature' );
		$method->setAccessible( true );
		$this->assertTrue( $method->invoke( null, -8, $data, $sig, $this->der_cert( self::ED25519_ALG, $key ) ) );
		$this->assertFalse( $method->invoke( null, -8, $data, $sig, $this->der_cert( self::EC_P256_ALG, $key ) ), 'Ed25519 key bits under an EC key OID' );
	}

	public function test_ed25519_certificate_signature_carries_no_parameters(): void {
		$pair   = sodium_crypto_sign_keypair();
		$issuer = $this->der_cert( self::ED25519_ALG, sodium_crypto_sign_publickey( $pair ) );
		$tbs    = $this->der_tbs( self::ED25519_ALG, str_repeat( "\x01", 32 ) );
		$sig    = sodium_crypto_sign_detached( $tbs, sodium_crypto_sign_secretkey( $pair ) );

		$method = new ReflectionMethod( TransparAI_C2PA_Verify::class, 'cert_signed_by' );
		$method->setAccessible( true );
		$plain = $this->der( 0x30, $tbs . self::ED25519_ALG . $this->der( 0x03, "\x00" . $sig ) );
		$this->assertTrue( $method->invoke( null, $plain, $issuer ) );
		$with_null = $this->der( 0x30, $tbs . $this->der( 0x30, "\x06\x03\x2b\x65\x70" . "\x05\x00" ) . $this->der( 0x03, "\x00" . $sig ) );
		$this->assertFalse( $method->invoke( null, $with_null, $issuer ), 'NULL parameters after the Ed25519 OID' );
	}

	/* AlgorithmIdentifier SEQUENCEs: Ed25519 (RFC 8410) and ecPublicKey with P-256. */
	private const ED25519_ALG = "\x30\x05\x06\x03\x2b\x65\x70";
	private const EC_P256_ALG = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

	/**
	 * Sign with a fresh key on the given curve and verify as COSE ES256.
	 */
	private function ecdsa_verdict( string $curve, int $half, string $data ): bool {
		$key  = openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => $curve,
			)
		);
		$csr  = openssl_csr_new( array( 'commonName' => 'Curve Test' ), $key, array( 'digest_alg' => 'sha256' ) );
		$cert = openssl_csr_sign( $csr, null, $key, 1, array( 'digest_alg' => 'sha256' ) );
		openssl_x509_export( $cert, $pem );
		openssl_sign( $data, $der_sig, $key, 'sha256' );

		/* DER SEQUENCE { INTEGER r, INTEGER s } to the fixed-size r||s COSE form. */
		$raw = '';
		$pos = 2;
		for ( $i = 0; $i < 2; $i++ ) {
			$len  = ord( $der_sig[ $pos + 1 ] );
			$int  = ltrim( substr( $der_sig, $pos + 2, $len ), "\x00" );
			$raw .= str_pad( $int, $half, "\x00", STR_PAD_LEFT );
			$pos += 2 + $len;
		}

		$method = new ReflectionMethod( TransparAI_C2PA_Verify::class, 'verify_signature' );
		$method->setAccessible( true );
		$der = (string) base64_decode( (string) preg_replace( '/-----[^-]+-----|\s+/', '', $pem ) );
		return (bool) $method->invoke( null, -7, $data, $raw, $der );
	}

	/**
	 * A minimal certificate: TBS with the given key, any signature.
	 */
	private function der_cert( string $alg, string $key ): string {
		return $this->der( 0x30, $this->der_tbs( $alg, $key ) . self::ED25519_ALG . $this->der( 0x03, "\x00" . str_repeat( "\x00", 64 ) ) );
	}

	/**
	 * TBSCertificate with serial, algorithm, empty names and validity, and the key.
	 */
	private function der_tbs( string $alg, string $key ): string {
		$empty = $this->der( 0x30, '' );
		$spki  = $this->der( 0x30, $alg . $this->der( 0x03, "\x00" . $key ) );
		return $this->der( 0x30, $this->der( 0x02, "\x01" ) . self::ED25519_ALG . $empty . $empty . $empty . $spki );
	}

	/**
	 * DER TLV with short or long length.
	 */
	private function der( int $tag, string $content ): string {
		$len = strlen( $content );
		$enc = $len < 0x80 ? chr( $len ) : ( $len < 0x100 ? "\x81" . chr( $len ) : "\x82" . pack( 'n', $len ) );
		return chr( $tag ) . $enc . $content;
	}

	private function pki_pem( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . "/fixtures/c2pa/pki/{$name}.pem" );
	}

	private function pki_der( string $name ): string {
		return (string) base64_decode( (string) preg_replace( '/-----[^-]+-----|\s+/', '', $this->pki_pem( $name ) ) );
	}

	/**
	 * The raw COSE signature bytes of the active manifest.
	 */
	private function cose_signature( string $store ): string {
		$top       = TransparAI_C2PA::jumbf_boxes( $store, 0, strlen( $store ) );
		$manifests = TransparAI_C2PA::jumbf_boxes( $store, $top[0]['start'], $top[0]['end'] );
		$active    = end( $manifests );
		foreach ( TransparAI_C2PA::jumbf_boxes( $store, $active['start'], $active['end'] ) as $part ) {
			if ( 'c2pa.signature' === $part['label'] ) {
				$cbor = TransparAI_C2PA::jumbf_boxes( $store, $part['start'], $part['end'] )[0];
				$cose = TransparAI_C2PA::cbor_decode( substr( $store, $cbor['start'], $cbor['end'] - $cbor['start'] ) );
				return $cose[3];
			}
		}
		$this->fail( 'no signature box' );
	}
}
