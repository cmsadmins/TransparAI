<?php
/**
 * End-to-end release gate, run inside a live WordPress via:
 *   wp eval-file tests/e2e/run-e2e.php
 *
 * Builds its own throwaway content (two generated images, one block page,
 * one AI-marked post), renders the real pages over loopback HTTP, asserts
 * the badge/schema/notice output in the DOM, verifies the in-file XMP with
 * the plugin's own parsers, exercises the upload auto-detection path, and
 * cleans everything up again. Exits non-zero on the first failure.
 *
 * @package TransparAI
 */

// phpcs:disable Generic.PHP.RequireStrictTypes -- wp eval-file evaluates the
// body after stripping the opening tag; a strict_types declare would then no
// longer be the first statement and fatal. Dev-only script, not shipped.

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// wp eval-file runs this body inside a function scope: top-level variables
// are NOT globals there, so the counter lives in $GLOBALS explicitly.
$GLOBALS['trai_e2e_failures'] = 0;

$trai_e2e_cleanup = array(
	'posts'   => array(),
	'options' => array(),
);

/**
 * Assertion helper.
 *
 * @param bool   $ok    Condition.
 * @param string $label What was checked.
 */
function trai_e2e_check( bool $ok, string $label ): void {
	if ( $ok ) {
		echo '  ok    ' . esc_html( $label ) . "\n";
		return;
	}
	++$GLOBALS['trai_e2e_failures'];
	echo '  FAIL  ' . esc_html( $label ) . "\n";
}

/**
 * Fetch a page over loopback HTTP and return its HTML.
 *
 * The site URL carries the host-mapped port (e.g. localhost:8094) which does
 * not exist inside the container, so the request goes to the local Apache on
 * port 80 with the original host preserved as a Host header.
 */
function trai_e2e_fetch( string $url ): string {
	$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$port     = wp_parse_url( home_url(), PHP_URL_PORT );
	$loopback = (string) preg_replace( '#^https?://[^/]+#', 'http://127.0.0.1', $url );
	$response = wp_remote_get(
		$loopback,
		array(
			'timeout'     => 30,
			'sslverify'   => false,
			'redirection' => 0,
			'headers'     => array( 'Host' => $host . ( $port ? ':' . $port : '' ) ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return '';
	}
	return (string) wp_remote_retrieve_body( $response );
}

/**
 * Create an attachment from a GD-generated JPEG.
 */
function trai_e2e_make_image( string $title, int $seed ): int {
	$img = imagecreatetruecolor( 480, 360 );
	imagefilledrectangle( $img, 0, 0, 480, 360, imagecolorallocate( $img, 40 * $seed % 255, 120, 200 ) );
	imagefilledellipse( $img, 240, 180, 200, 140, imagecolorallocate( $img, 250, 250, 250 ) );
	$tmp = wp_tempnam( 'trai-e2e-' . $seed . '.jpg' );
	imagejpeg( $img, $tmp, 85 );

	$id = media_handle_sideload(
		array(
			'name'     => 'trai-e2e-' . $title . '.jpg',
			'tmp_name' => $tmp,
		),
		0
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
}

echo "TransparAI e2e gate\n";

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

/* ---------------------------------------------------------------------------
 * Stage: settings snapshot + deterministic options
 * ------------------------------------------------------------------------- */

$trai_e2e_cleanup['options']['transparai_settings'] = get_option( 'transparai_settings' );
update_option(
	'transparai_settings',
	TransparAI_Options::sanitize(
		array(
			'badge_enabled' => '1',
			'badge_mode'    => 'overlay',
			'write_xmp'     => '1',
			'schema_output' => '1',
			'page_notice'   => '1',
			'autodetect'    => '1',
			'mode_certain'  => 'flag',
		)
	)
);

/* ---------------------------------------------------------------------------
 * Stage: media (flagged + negative) and the writer chain
 * ------------------------------------------------------------------------- */

$flagged  = trai_e2e_make_image( 'flagged', 3 );
$negative = trai_e2e_make_image( 'negative', 5 );
$trai_e2e_cleanup['posts'][] = $flagged;
$trai_e2e_cleanup['posts'][] = $negative;
trai_e2e_check( $flagged > 0 && $negative > 0, 'test images uploaded' );

TransparAI_Meta::flag( $flagged, 'manual' );
TransparAI_Meta::store_result( $flagged, array( 'generator' => 'E2E Generator', 'type' => 'generated' ) );
TransparAI_Writer::sync_attachment( $flagged );

$marked = true;
foreach ( TransparAI_Writer::attachment_files( $flagged ) as $trai_e2e_path ) {
	$marked = $marked && TransparAI_Writer::file_is_marked( $trai_e2e_path );
}
trai_e2e_check( $marked && array() !== TransparAI_Writer::attachment_files( $flagged ), 'XMP written into every size variant' );
trai_e2e_check( '' === (string) get_post_meta( $flagged, TransparAI_Meta::KEY_WRITE_ERROR, true ), 'no write errors recorded' );

TransparAI_Meta::unflag( $flagged );
TransparAI_Writer::sync_attachment( $flagged );
$unmarked = true;
foreach ( TransparAI_Writer::attachment_files( $flagged ) as $trai_e2e_path ) {
	$unmarked = $unmarked && ! TransparAI_Writer::file_is_marked( $trai_e2e_path );
}
trai_e2e_check( $unmarked, 'unlabeling removes the XMP again' );

TransparAI_Meta::flag( $flagged, 'manual' );
TransparAI_Writer::sync_attachment( $flagged );

/* ---------------------------------------------------------------------------
 * Stage: upload auto-detection (declared dST fixture must auto-flag)
 * ------------------------------------------------------------------------- */

$fixture = WP_PLUGIN_DIR . '/transparai/tests/fixtures/generated/xmp-dst.png';
if ( file_exists( $fixture ) ) {
	$tmp = wp_tempnam( 'trai-e2e-dst.png' );
	copy( $fixture, $tmp );
	$detected = media_handle_sideload(
		array(
			'name'     => 'trai-e2e-dst.png',
			'tmp_name' => $tmp,
		),
		0
	);
	$detected = is_wp_error( $detected ) ? 0 : (int) $detected;
	$trai_e2e_cleanup['posts'][] = $detected;
	trai_e2e_check( $detected > 0 && TransparAI_Meta::is_flagged( $detected ), 'declared-dST upload is auto-flagged on upload' );
	trai_e2e_check( 'auto' === get_post_meta( $detected, TransparAI_Meta::KEY_MARKED_BY, true ), 'auto flag is attributed to the scanner' );
} else {
	trai_e2e_check( false, 'detection fixture present (run `php tests/fixtures/make-fixtures.php`)' );
}

/* ---------------------------------------------------------------------------
 * Stage: block page rendering (badges, schema, notices, negatives)
 * ------------------------------------------------------------------------- */

$flagged_url  = wp_get_attachment_image_url( $flagged, 'large' );
$negative_url = wp_get_attachment_image_url( $negative, 'large' );
$content      =
	'<!-- wp:image {"id":' . $flagged . ',"sizeSlug":"large","linkDestination":"none"} -->'
	. '<figure class="wp-block-image size-large"><img src="' . $flagged_url . '" alt="" class="wp-image-' . $flagged . '"/></figure><!-- /wp:image -->'
	. '<!-- wp:image {"id":' . $negative . ',"sizeSlug":"large","linkDestination":"none"} -->'
	. '<figure class="wp-block-image size-large"><img src="' . $negative_url . '" alt="" class="wp-image-' . $negative . '"/></figure><!-- /wp:image -->'
	. '<!-- wp:paragraph --><p>Inline <img class="wp-image-' . $flagged . '" style="width:48px" src="' . wp_get_attachment_image_url( $flagged, 'thumbnail' ) . '" alt=""/> flow.</p><!-- /wp:paragraph -->'
	. '<!-- wp:cover {"url":"' . $flagged_url . '","id":' . $flagged . ',"dimRatio":50} -->'
	. '<div class="wp-block-cover"><span aria-hidden="true" class="wp-block-cover__background has-background-dim"></span>'
	. '<img class="wp-block-cover__image-background wp-image-' . $flagged . '" alt="" src="' . $flagged_url . '" data-object-fit="cover"/>'
	. '<div class="wp-block-cover__inner-container"><!-- wp:paragraph --><p>Cover</p><!-- /wp:paragraph --></div></div><!-- /wp:cover -->';

$page_id = wp_insert_post(
	array(
		'post_title'   => 'TransparAI e2e page',
		'post_name'    => 'trai-e2e-page-' . time(),
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_content' => $content,
	)
);
$trai_e2e_cleanup['posts'][] = (int) $page_id;

$post_id = wp_insert_post(
	array(
		'post_title'   => 'TransparAI e2e AI text',
		'post_name'    => 'trai-e2e-post-' . time(),
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_content' => '<!-- wp:paragraph --><p>Machine written words.</p><!-- /wp:paragraph -->',
	)
);
$trai_e2e_cleanup['posts'][] = (int) $post_id;
update_post_meta( (int) $post_id, TransparAI_Meta::KEY_CONTENT_AI, '1' );

delete_transient( 'transparai_url_map' );

$html = trai_e2e_fetch( get_permalink( (int) $page_id ) );
trai_e2e_check( '' !== $html, 'block page renders over loopback HTTP' );
trai_e2e_check( 3 === substr_count( $html, 'trai-badge' ), 'exactly one badge per flagged render (standard, inline, cover), negative stays clean, got ' . substr_count( $html, 'trai-badge' ) );
trai_e2e_check( ! str_contains( $html, 'wp-image-' . $negative . '"' ) || ! preg_match( '/<span class="trai-wrap[^>]*>\s*<img[^>]*wp-image-' . $negative . '/', $html ), 'negative image is not wrapped' );
trai_e2e_check( str_contains( $html, 'trai-wrap--fill' ), 'cover background wrap takes the full-bleed role' );
trai_e2e_check( str_contains( $html, 'trai-page-notice' ), 'site-wide page notice appears' );

$schema_ok = false;
if ( preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m ) ) {
	$data      = json_decode( $m[1], true );
	$graph     = is_array( $data ) ? ( $data['@graph'] ?? array() ) : array();
	$schema_ok = array() !== $graph
		&& str_contains( (string) wp_json_encode( $graph ), 'trainedAlgorithmicMedia' )
		&& str_contains( (string) wp_json_encode( $graph ), 'E2E Generator' );
}
trai_e2e_check( $schema_ok, 'schema JSON-LD parses and carries dST + generator' );

$post_html = trai_e2e_fetch( get_permalink( (int) $post_id ) );
trai_e2e_check( str_contains( $post_html, 'trai-content-notice' ), 'AI-written post shows the content note' );

$clean_html = trai_e2e_fetch( home_url( '/?p=999999&nonexist=1' ) );
trai_e2e_check( ! str_contains( $clean_html, 'trai-page-notice' ), 'pages without labeled media get no page notice' );

/* ---------------------------------------------------------------------------
 * Cleanup
 * ------------------------------------------------------------------------- */

foreach ( array_filter( $trai_e2e_cleanup['posts'] ) as $trai_e2e_id ) {
	wp_delete_post( (int) $trai_e2e_id, true );
}
foreach ( $trai_e2e_cleanup['options'] as $trai_e2e_name => $trai_e2e_value ) {
	if ( false === $trai_e2e_value ) {
		delete_option( $trai_e2e_name );
	} else {
		update_option( $trai_e2e_name, $trai_e2e_value );
	}
}
delete_transient( 'transparai_url_map' );
delete_transient( 'transparai_stats' );

if ( $GLOBALS['trai_e2e_failures'] > 0 ) {
	echo 'e2e gate: ' . (int) $GLOBALS['trai_e2e_failures'] . " failure(s)\n";
	exit( 1 );
}
echo "e2e gate: all checks passed\n";
