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
