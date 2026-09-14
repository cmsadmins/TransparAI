<?php
/**
 * Text disclosure levels: normalization of the 1.0.x checkbox value, the
 * review stamp set through the meta hook, and the content fingerprint that
 * lets an approval expire when the post or its images change.
 *
 * @package TransparAI
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class ContentLevelTest extends TestCase {

	protected function setUp(): void {
		trai_test_reset();
	}

	private function seed_post( int $id, string $content = '<p>Hello</p>', string $title = 'Title' ): void {
		global $trai_test_posts;
		$post                     = new WP_Post();
		$post->ID                 = $id;
		$post->post_title         = $title;
		$post->post_content       = $content;
		$trai_test_posts[ $id ]   = $post;
	}

	public function test_sanitize_level_maps_legacy_checkbox_and_rejects_unknown(): void {
		$this->assertSame( 'generated', TransparAI_Meta::sanitize_content_level( '1' ) );
		$this->assertSame( 'generated', TransparAI_Meta::sanitize_content_level( true ) );
		$this->assertSame( 'assisted', TransparAI_Meta::sanitize_content_level( 'assisted' ) );
		$this->assertSame( 'generated_reviewed', TransparAI_Meta::sanitize_content_level( 'generated_reviewed' ) );
		$this->assertSame( '', TransparAI_Meta::sanitize_content_level( 'bogus' ) );
		$this->assertSame( '', TransparAI_Meta::sanitize_content_level( array() ) );
	}

	public function test_none_and_unclassified_stay_apart(): void {
		$this->assertSame( 'none', TransparAI_Meta::sanitize_content_level( 'none' ) );
		$this->assertFalse( TransparAI_Meta::level_is_ai( 'none' ) );
		$this->assertFalse( TransparAI_Meta::level_is_ai( '' ) );
		$this->assertTrue( TransparAI_Meta::level_is_ai( 'assisted' ) );

		TransparAI_Meta::set_content_level( 7, 'none' );
		$this->assertSame( 'none', get_post_meta( 7, TransparAI_Meta::KEY_CONTENT_AI, true ) );
		TransparAI_Meta::set_content_level( 7, '' );
		$this->assertSame( '', get_post_meta( 7, TransparAI_Meta::KEY_CONTENT_AI, true ), 'Empty level removes the meta row' );
	}

	public function test_review_stamp_records_person_date_and_fingerprint(): void {
		global $trai_test_user, $trai_test_user_name;
		$trai_test_user      = 4;
		$trai_test_user_name = 'Editor Eva';
		$this->seed_post( 10, '<p>Text with <img class="wp-image-77" src="a.jpg"></p>' );
		update_post_meta( 10, TransparAI_Meta::KEY_CONTENT_RESPONSIBLE, 'Jane Doe' );

		TransparAI_Meta::on_content_level_change( 1, 10, TransparAI_Meta::KEY_CONTENT_AI, 'generated' );
		$this->assertNull( TransparAI_Meta::content_review( 10 ), 'Only the reviewed level gets a stamp' );

		TransparAI_Meta::on_content_level_change( 1, 10, TransparAI_Meta::KEY_CONTENT_AI, 'generated_reviewed' );
		$stamp = TransparAI_Meta::content_review( 10 );
		$this->assertIsArray( $stamp );
		$this->assertSame( 'Editor Eva', $stamp['by'] );
		$this->assertSame( 4, $stamp['by_id'] );
		$this->assertSame( 'Jane Doe', $stamp['responsible'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $stamp['on'] );
		$this->assertTrue( TransparAI_Meta::is_review_current( 10 ) );

		/* Swapping an embedded image for an AI-labeled one expires the approval. */
		update_post_meta( 77, TransparAI_Meta::KEY_FLAG, '1' );
		$this->assertFalse( TransparAI_Meta::is_review_current( 10 ), 'An image label change invalidates the review' );

		/* Level change away from reviewed keeps the stamp (facts are never destroyed). */
		TransparAI_Meta::set_content_level( 10, 'assisted' );
		$this->assertIsArray( TransparAI_Meta::content_review( 10 ) );
	}

	public function test_fingerprint_changes_with_text(): void {
		$this->seed_post( 11, '<p>One</p>' );
		$a = TransparAI_Meta::content_hash( 11 );
		$this->seed_post( 11, '<p>Two</p>' );
		$this->assertNotSame( $a, TransparAI_Meta::content_hash( 11 ) );
		$this->assertSame( '', TransparAI_Meta::content_hash( 999 ), 'Unknown post has no fingerprint' );
	}

	public function test_dst_schema_serves_both_vocabularies(): void {
		$node = TransparAI_Meta::dst_schema( TransparAI_Meta::DST_COMPOSITE );
		$this->assertSame( 'https://schema.org/CompositeWithTrainedAlgorithmicMediaDigitalSource', $node['digitalSourceType'] );
		$this->assertSame( 'IPTC:DigitalSourceType', $node['additionalProperty']['propertyID'] );
		$this->assertSame( 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeWithTrainedAlgorithmicMedia', $node['additionalProperty']['value'] );
	}
}
