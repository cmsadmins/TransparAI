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
}
