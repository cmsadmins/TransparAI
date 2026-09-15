<?php
/**
 * AI systems: bundled registry sanity, local detection, declarations,
 * visibility gating, suggestions and the notice text.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class SystemsTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	public function test_bundled_registry_is_consistent(): void {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/data/ai-systems.json' ), true );
		$this->assertIsArray( $json );
		$this->assertGreaterThanOrEqual( 100, count( $json['systems'] ) );

		$ids   = array();
		$slugs = array();
		foreach ( $json['systems'] as $entry ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9-]+$/', $entry['id'] );
			$this->assertArrayNotHasKey( $entry['id'], $ids, 'Duplicate id ' . $entry['id'] );
			$ids[ $entry['id'] ] = true;
			$this->assertContains( $entry['category'], TransparAI_Systems::CATEGORIES, $entry['id'] );
			$this->assertContains( $entry['risk'], array( 'minimal', 'limited', 'high' ), $entry['id'] );
			$this->assertNotEmpty( $entry['slugs'], $entry['id'] );
			$this->assertStringStartsWith( 'https://wordpress.org/plugins/', $entry['url'], $entry['id'] );
			foreach ( $entry['slugs'] as $slug ) {
				$this->assertArrayNotHasKey( $slug, $slugs, 'Slug ' . $slug . ' appears twice' );
				$slugs[ $slug ] = $entry['id'];
			}
			foreach ( array( $entry['name'], $entry['article'] ) as $text ) {
				$this->assertDoesNotMatchRegularExpression( '/[\x{2014}\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $text, 'No em dashes or emojis in ' . $entry['id'] );
			}
		}

		$registry = TransparAI_Systems::registry();
		$this->assertArrayHasKey( 'ai-engine', $registry );
		$this->assertSame( 'chatbot', $registry['chatbot-chatgpt']['category'] );
	}

	public function test_scan_matches_active_and_network_active_plugins(): void {
		global $trai_test_options;
		$trai_test_options['active_plugins']               = array( 'ai-engine/ai-engine.php', 'hello-dolly/hello.php' );
		$trai_test_options['site:active_sitewide_plugins'] = array( 'wordpress-seo/wp-seo.php' => 1 );

		$detected = TransparAI_Systems::scan();
		$this->assertArrayHasKey( 'ai-engine', $detected );
		$this->assertArrayNotHasKey( 'hello-dolly', $detected );
		$this->assertGreaterThan( 0, TransparAI_Systems::scanned_at() );

		$log = TransparAI_Meta::site_log();
		$this->assertSame( 'systems-scanned', $log[ count( $log ) - 1 ]['e'] );

		$all = TransparAI_Systems::all();
		$this->assertSame( 'detected', $all['ai-engine']['source'] );
		$this->assertFalse( $all['ai-engine']['visible'] );
		$this->assertSame( array(), TransparAI_Systems::visible(), 'Nothing is visible until a system is ticked' );
	}

	public function test_declare_visibility_and_undeclare(): void {
		global $trai_test_options;
		$id = TransparAI_Systems::declare( 'House Recommender', 'personalisation' );
		$this->assertSame( 'manual-house-recommender', $id );
		$this->assertSame( '', TransparAI_Systems::declare( '   ', 'other' ), 'Empty names are rejected' );

		$all = TransparAI_Systems::all();
		$this->assertSame( 'manual', $all[ $id ]['source'] );
		$this->assertSame( 'Art. 4', $all[ $id ]['article'] );
		$this->assertSame( 1, TransparAI_Systems::count() );

		TransparAI_Systems::set_visible( array( $id, 'not-a-system' ) );
		$this->assertTrue( TransparAI_Systems::all()[ $id ]['visible'] );
		$trai_test_options['transparai_settings'] = array( 'systems_notice' => '0' );
		$this->assertSame( array(), TransparAI_Systems::visible(), 'Notice option switched off' );

		$trai_test_options['transparai_settings'] = array();
		$visible                                   = TransparAI_Systems::visible();
		$this->assertSame( array( $id ), array_keys( $visible ) );
		$this->assertSame( 'This site uses AI systems: House Recommender.', TransparAI_Systems::notice_text( $visible ) );

		$trai_test_options['transparai_settings']['systems_notice_text'] = 'AI on board: %s';
		$this->assertSame( 'AI on board: House Recommender', TransparAI_Systems::notice_text( $visible ) );

		TransparAI_Systems::undeclare( $id );
		$this->assertSame( 0, TransparAI_Systems::count() );
		$log = TransparAI_Meta::site_log();
		$this->assertSame( 'systems-undeclared', $log[ count( $log ) - 1 ]['e'] );
	}

	public function test_possibly_ai_ranks_by_keywords_and_skips_known_slugs(): void {
		global $trai_test_options, $trai_test_plugins;
		$trai_test_options['active_plugins'] = array( 'foo-writer/foo.php', 'contact-form/cf.php', 'ai-engine/ai-engine.php' );
		$trai_test_plugins                   = array(
			'foo-writer/foo.php'       => array(
				'Name'        => 'Foo GPT Writer',
				'Description' => 'Write posts with OpenAI and ChatGPT.',
			),
			'contact-form/cf.php'      => array(
				'Name'        => 'Contact Form',
				'Description' => 'A form.',
			),
			'ai-engine/ai-engine.php'  => array(
				'Name'        => 'AI Engine',
				'Description' => 'ChatGPT everywhere.',
			),
		);
		$out = TransparAI_Systems::possibly_ai();
		$this->assertCount( 1, $out, 'Known registry slugs and non-AI plugins are skipped' );
		$this->assertSame( 'foo-writer', $out[0]['slug'] );
	}
}
