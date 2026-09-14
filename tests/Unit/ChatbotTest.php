<?php
/**
 * Chatbot disclosure: the bundled vendor list, local detection over plugin
 * slugs, theme files and script text, the "answer leads" rule, the AI
 * Engine first-message injection and the client report filter.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ChatbotTest extends TestCase {

	/** @var string */
	private string $theme = '';

	protected function setUp(): void {
		trai_test_reset();
	}

	protected function tearDown(): void {
		if ( '' !== $this->theme ) {
			foreach ( glob( $this->theme . '/*' ) ?: array() as $file ) {
				unlink( $file );
			}
			rmdir( $this->theme );
			$this->theme = '';
		}
	}

	public function test_vendor_list_is_bundled_and_sane(): void {
		$vendors = TransparAI_Chatbot::vendors();
		$this->assertGreaterThan( 30, count( $vendors ) );
		$this->assertSame( 'human', $vendors['tawkto']['staffing'], 'A pure live chat must never be called an AI' );
		$this->assertSame( 'ai', $vendors['ai-engine']['staffing'] );
		foreach ( $vendors as $vendor ) {
			$this->assertNotEmpty( $vendor['slugs'] + $vendor['hosts'] + $vendor['globals'] + $vendor['selectors'], $vendor['id'] . ' needs at least one signal' );
			foreach ( $vendor['hosts'] as $host ) {
				$this->assertGreaterThan( 6, strlen( $host ), 'Too generic a host would match prose: ' . $host );
			}
		}
	}

	public function test_match_text_needs_a_real_signal(): void {
		$this->assertSame( array( 'intercom' ), TransparAI_Chatbot::match_text( '<script src="https://widget.intercom.io/widget/abc"></script>' ) );
		$this->assertSame( array( 'crisp' ), TransparAI_Chatbot::match_text( 'window.$crisp = []; window.CRISP_WEBSITE_ID = "x";' ) );
		$this->assertSame( array(), TransparAI_Chatbot::match_text( 'We compared Intercom and Drift in this blog post.' ), 'Prose mentions are not evidence' );
		$this->assertSame( array(), TransparAI_Chatbot::match_text( '' ) );
	}

	public function test_detect_plugins_by_directory_slug(): void {
		global $trai_test_options;
		$trai_test_options['active_plugins'] = array( 'tidio-live-chat/tidio-live-chat.php', 'akismet/akismet.php', 'Zopim-Live-Chat/zopim.php' );
		$found                               = TransparAI_Chatbot::detect_plugins();
		$this->assertArrayHasKey( 'tidio', $found );
		$this->assertArrayHasKey( 'zendesk', $found, 'Directory match is case-insensitive' );
		$this->assertArrayNotHasKey( 'intercom', $found );
		$this->assertStringContainsString( 'plugin tidio-live-chat', $found['tidio'] );
	}

	public function test_detect_static_reads_theme_files_and_snippet_options(): void {
		global $trai_test_theme_dir, $trai_test_options;
		$this->theme         = sys_get_temp_dir() . '/trai-theme-' . uniqid();
		$trai_test_theme_dir = $this->theme;
		mkdir( $this->theme );
		file_put_contents( $this->theme . '/footer.php', '<?php wp_footer(); ?><script src="https://embed.tawk.to/123/default"></script>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$trai_test_options['ihaf_insert_header'] = "<script>window.Tawk_API = {}; window.HubSpotConversations.widget.load();</script>";

		$found = TransparAI_Chatbot::detect_static();
		$this->assertSame( 'theme footer.php', $found['tawkto'] );
		$this->assertSame( 'option ihaf_insert_header', $found['hubspot'] );
	}

	public function test_answer_leads_and_output_falls_back(): void {
		global $trai_test_options;
		$this->assertFalse( TransparAI_Chatbot::active(), 'Undecided means no notice' );

		$trai_test_options['transparai_settings'] = array(
			'chatbot_answer'   => 'yes',
			'chatbot_staffing' => 'human',
		);
		$this->assertFalse( TransparAI_Chatbot::active(), 'People answering is not an AI system' );

		$trai_test_options['transparai_settings']['chatbot_staffing'] = 'mixed';
		$this->assertTrue( TransparAI_Chatbot::active() );

		$trai_test_options['transparai_settings']['chatbot_output'] = 'chat';
		$this->assertSame( 'badge', TransparAI_Chatbot::effective_output(), 'No AI Engine: the first-message route falls back to the badge' );
		$trai_test_options['transparai_settings']['chatbot_output'] = 'footer';
		$this->assertSame( 'footer', TransparAI_Chatbot::effective_output() );

		ob_start();
		TransparAI_Chatbot::print_footer_notice();
		$this->assertStringContainsString( 'trai-chat-notice', (string) ob_get_clean() );
	}

	public function test_ai_engine_first_message_is_idempotent(): void {
		global $trai_test_options;
		$trai_test_options['transparai_settings'] = array(
			'chatbot_answer'      => 'yes',
			'chatbot_staffing'    => 'ai',
			'chatbot_output'      => 'chat',
			'chatbot_notice_text' => 'This chat is an AI.',
		);
		$params = TransparAI_Chatbot::ai_engine_params( array( 'startSentence' => 'Hi! How can I help?' ) );
		$this->assertSame( 'This chat is an AI. Hi! How can I help?', $params['startSentence'] );
		$this->assertSame( $params, TransparAI_Chatbot::ai_engine_params( $params ), 'Second run adds nothing' );
		$this->assertSame( 'This chat is an AI.', TransparAI_Chatbot::ai_engine_params( array() )['startSentence'] );

		$trai_test_options['transparai_settings']['chatbot_answer'] = 'no';
		$this->assertSame( array( 'startSentence' => 'Hi' ), TransparAI_Chatbot::ai_engine_params( array( 'startSentence' => 'Hi' ) ), 'Off means untouched' );
	}

	public function test_client_report_only_stores_known_ids(): void {
		$ids = TransparAI_Chatbot::filter_known( array( 'tidio', 'evil<script>', 'tidio', 'chatbase', 'nope' ) );
		$this->assertSame( array( 'tidio', 'chatbase' ), $ids );
		$this->assertCount( 2, TransparAI_Chatbot::filter_known( array( 'tidio', 'crisp', 'drift' ), 2 ), 'Capped' );

		TransparAI_Chatbot::remember( array( 'tidio' => 'seen in the browser' ) );
		$findings = TransparAI_Chatbot::findings();
		$this->assertSame( 'Tidio', $findings['tidio']['name'] );
		$this->assertSame( 'mixed', $findings['tidio']['staffing'] );
	}
}
