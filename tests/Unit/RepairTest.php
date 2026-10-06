<?php
/**
 * Auto-repair: fingerprint change detection against real temp files.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class RepairTest extends TestCase {

	/**
	 * Temp files to clean up.
	 *
	 * @var string[]
	 */
	private array $temp_files = array();

	protected function setUp(): void {
		trai_test_reset();
	}

	protected function tearDown(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();
	}

	private function attach_temp_file( int $attachment_id, string $content ): string {
		global $trai_test_meta;
		$path = sys_get_temp_dir() . '/trai-repair-' . uniqid() . '.jpg';
		file_put_contents( $path, $content );
		$this->temp_files[]                             = $path;
		$trai_test_meta[ $attachment_id ]['_test_file'] = $path;
		return $path;
	}

	public function test_files_changed_lifecycle(): void {
		$path = $this->attach_temp_file( 60, 'original content' );

		$this->assertTrue( TransparAI_Repair::files_changed( 60 ), 'No fingerprint stored yet counts as changed' );

		TransparAI_Repair::remember( 60 );
		$this->assertFalse( TransparAI_Repair::files_changed( 60 ), 'Untouched file matches its fingerprint' );

		// An optimizer rewrites the file: size changes.
		file_put_contents( $path, 'rewritten and now much longer content' );
		$this->assertTrue( TransparAI_Repair::files_changed( 60 ) );

		TransparAI_Repair::remember( 60 );
		$this->assertFalse( TransparAI_Repair::files_changed( 60 ) );
	}

	public function test_changed_file_count_counts_as_changed(): void {
		global $trai_test_meta;
		$this->attach_temp_file( 61, 'main file' );
		TransparAI_Repair::remember( 61 );

		// A new size variant appears in the metadata afterwards.
		$extra = $this->attach_temp_file( 62, 'variant' );
		$trai_test_meta[61]['_test_metadata'] = array(
			'sizes' => array(
				'medium' => array( 'file' => basename( $extra ) ),
			),
		);
		// The variant lives in the same directory as the main file.
		$this->assertTrue( TransparAI_Repair::files_changed( 61 ) );
	}

	public function test_init_schedules_the_sweep_once(): void {
		global $trai_test_cron;

		TransparAI_Repair::init();
		$first = $trai_test_cron[ TransparAI_Repair::CRON_HOOK ] ?? null;
		$this->assertNotNull( $first, 'A site that never ran the activation hook still gets the sweep' );

		TransparAI_Repair::init();
		$this->assertSame( $first, $trai_test_cron[ TransparAI_Repair::CRON_HOOK ], 'Scheduling stays idempotent' );

		TransparAI_Repair::unschedule();
		$this->assertArrayNotHasKey( TransparAI_Repair::CRON_HOOK, $trai_test_cron );
	}

	public function test_edited_copy_carries_label_to_new_attachment(): void {
		global $trai_test_meta;
		$trai_test_meta[70] = array(
			TransparAI_Meta::KEY_FLAG      => '1',
			TransparAI_Meta::KEY_TYPE      => 'composite',
			TransparAI_Meta::KEY_SOURCE    => 'c2pa',
			TransparAI_Meta::KEY_MARKED_BY => 'auto',
		);
		$meta = array( 'file' => '2026/10/edited.jpg' );

		$this->assertSame( $meta, TransparAI_Repair::on_edited_copy( $meta, 71, 70 ) );
		$this->assertSame( '1', get_post_meta( 71, TransparAI_Meta::KEY_FLAG, true ) );
		$this->assertSame( 'composite', get_post_meta( 71, TransparAI_Meta::KEY_TYPE, true ) );
		$this->assertSame( 'c2pa', get_post_meta( 71, TransparAI_Meta::KEY_SOURCE, true ) );
		$this->assertSame( '', get_post_meta( 71, TransparAI_Meta::KEY_HUMAN, true ), 'Absent keys stay absent' );
	}

	public function test_edited_copy_ignores_unlabeled_source_and_self(): void {
		global $trai_test_meta;
		$trai_test_meta[72] = array( TransparAI_Meta::KEY_FLAG => '1' );
		TransparAI_Repair::on_edited_copy( array(), 72, 72 );
		TransparAI_Repair::on_edited_copy( array(), 74, 73 );
		$this->assertArrayNotHasKey( 74, $trai_test_meta );
	}

	public function test_optimizer_done_accepts_id_post_and_array(): void {
		global $trai_test_meta;
		$trai_test_meta[75] = array( TransparAI_Meta::KEY_FLAG => '1' );
		$post     = new WP_Post();
		$post->ID = 75;
		foreach ( array( 75, '75', $post, array( 'attachment_id' => 75 ), array( 'id' => '75' ), 'nonsense', null ) as $argument ) {
			TransparAI_Repair::on_optimizer_done( $argument );
		}
		$this->assertTrue( true, 'No type error for any argument shape' );
	}
}
