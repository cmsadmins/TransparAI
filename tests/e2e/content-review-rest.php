<?php
/**
 * REST review regressions for a disposable local/development WordPress.
 * Run: wp --user=<administrator> eval-file tests/e2e/content-review-rest.php
 * Transactions roll back fixture rows; IDs may advance and caches are flushed.
 * Never run this on a production site.
 *
 * @package TransparAI
 */
global $wpdb;
if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
    throw new RuntimeException( 'Local/development environment required.' );
}
define( 'REST_REQUEST', true );
if ( ! class_exists( 'TransparAI_Meta' ) ) {
    require_once dirname( __DIR__, 2 ) . '/includes/class-options.php';
    require_once dirname( __DIR__, 2 ) . '/includes/class-meta.php';
    TransparAI_Meta::init();
    TransparAI_Meta::register_meta();
}

$admin = wp_get_current_user();
if ( ! $admin->exists() || ! user_can( $admin, 'manage_options' ) ) {
    throw new RuntimeException( 'Local test administrator required.' );
}
foreach ( array( $wpdb->posts, $wpdb->postmeta, $wpdb->options ) as $table ) {
    $engine = $wpdb->get_var( $wpdb->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table
    ) );
    if ( 'InnoDB' !== $engine ) {
        throw new RuntimeException( 'Transactional test tables required.' );
    }
}

function trai_check( $condition, $message ) {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}
function trai_request( $route, $params ) {
    $request = new WP_REST_Request( 'POST', $route );
    // Gutenberg uses JSON; exercise the same parameter source and mutation.
    $request->set_header( 'Content-Type', 'application/json' );
    $request->set_body( wp_json_encode( $params ) );
    return rest_do_request( $request );
}
function trai_ok( $response, $status = 200 ) {
    $data = $response->get_data();
    trai_check( $status === $response->get_status(), 'Unexpected REST status/code: ' .
        $response->get_status() . '/' . ( $data['code'] ?? 'none' ) );
}
function trai_new_post( $type = 'post' ) {
    $id = wp_insert_post( array(
        'post_type' => $type, 'post_status' => 'draft',
        'post_title' => 'Local TransparAI regression fixture',
        'post_content' => '<p>Original fixture content.</p>',
        'post_author' => get_current_user_id(),
    ), true );
    trai_check( ! is_wp_error( $id ), 'Fixture creation failed.' );
    return $id;
}

$review = TransparAI_Meta::KEY_CONTENT_REVIEW;
$level = TransparAI_Meta::KEY_CONTENT_AI;
$responsible = TransparAI_Meta::KEY_CONTENT_RESPONSIBLE;
$cases = array(
    'draft_with_empty_review' => function () use ( $review, $level, $responsible ) {
        $id = trai_new_post();
        $response = trai_request( '/wp/v2/posts/' . $id, array(
            'content' => '<p>Saved draft.</p>', 'title' => 'Saved fixture',
            'meta' => array( $level => 'assisted', $responsible => 'Fixture editor', $review => '' ),
        ) );
        trai_ok( $response );
        trai_check( '<p>Saved draft.</p>' === get_post_field( 'post_content', $id ), 'Draft content not saved.' );
        trai_check( 'assisted' === get_post_meta( $id, $level, true ), 'AI level not saved.' );
        trai_check( 'Fixture editor' === get_post_meta( $id, $responsible, true ), 'Other writable meta not saved.' );
        trai_check( ! metadata_exists( 'post', $id, $review ), 'Empty review must not create a stamp.' );
    },
    'publish_and_generate_review' => function () use ( $review, $level ) {
        $id = trai_new_post();
        $response = trai_request( '/wp/v2/posts/' . $id, array(
            'status' => 'publish', 'content' => '<p>Reviewed published fixture.</p>',
            'meta' => array( $level => TransparAI_Meta::LEVEL_REVIEWED, $review => '' ),
        ) );
        trai_ok( $response );
        $stamp = TransparAI_Meta::content_review( $id );
        trai_check( 'publish' === get_post_status( $id ), 'Publication not saved.' );
        trai_check( $stamp && get_current_user_id() === $stamp['by_id'], 'Server review identity missing.' );
        trai_check( TransparAI_Meta::is_review_current( $id ), 'Generated review does not match saved content.' );
        trai_check( $response->get_data()['meta'][ $review ] === get_post_meta( $id, $review, true ), 'Editor did not receive current stamp.' );
    },
    'changed_content_keeps_review_but_invalidates_hash' => function () use ( $review ) {
        $id = trai_new_post();
        TransparAI_Meta::set_content_level( $id, TransparAI_Meta::LEVEL_REVIEWED );
        $original = get_post_meta( $id, $review, true );
        trai_check( TransparAI_Meta::is_review_current( $id ), 'Initial stamp missing.' );
        trai_ok( trai_request( '/wp/v2/posts/' . $id, array(
            'content' => '<p>Changed since review.</p>', 'meta' => array( $review => $original ),
        ) ) );
        trai_check( $original === get_post_meta( $id, $review, true ), 'Existing review overwritten.' );
        trai_check( ! TransparAI_Meta::is_review_current( $id ), 'Changed content still appears reviewed.' );
    },
    'old_client_stamp_cannot_replace_new_server_stamp' => function () use ( $review, $level ) {
        $id = trai_new_post();
        TransparAI_Meta::set_content_level( $id, TransparAI_Meta::LEVEL_REVIEWED );
        $original = get_post_meta( $id, $review, true );
        TransparAI_Meta::set_content_level( $id, TransparAI_Meta::LEVEL_GEN );
        trai_ok( trai_request( '/wp/v2/posts/' . $id, array(
            'content' => '<p>New reviewed version.</p>',
            'meta' => array( $level => TransparAI_Meta::LEVEL_REVIEWED, $review => $original ),
        ) ) );
        trai_check( $original !== get_post_meta( $id, $review, true ), 'Old client stamp replaced server review.' );
        trai_check( TransparAI_Meta::is_review_current( $id ), 'New review does not match new content.' );
    },
    'forged_and_null_reviews_are_ignored' => function () use ( $review ) {
        $id = trai_new_post();
        $forged = wp_json_encode( array( 'by' => 'Forged', 'by_id' => 999, 'on' => '2099-01-01', 'hash' => 'fake' ) );
        trai_ok( trai_request( '/wp/v2/posts/' . $id, array( 'meta' => array( $review => $forged ) ) ) );
        trai_check( ! metadata_exists( 'post', $id, $review ), 'Client created a forged stamp.' );
        TransparAI_Meta::set_content_level( $id, TransparAI_Meta::LEVEL_REVIEWED );
        $original = get_post_meta( $id, $review, true );
        foreach ( array( $forged, null, '' ) as $value ) {
            trai_ok( trai_request( '/wp/v2/posts/' . $id, array( 'meta' => array( $review => $value ) ) ) );
            trai_check( $original === get_post_meta( $id, $review, true ), 'Client altered or deleted the stamp.' );
        }
        trai_check( ! current_user_can( 'edit_post_meta', $id, $review ), 'Protected-meta write permission relaxed.' );
    },
    'create_article_with_editor_meta' => function () use ( $review, $level ) {
        $response = trai_request( '/wp/v2/posts', array(
            'title' => 'Local new article fixture', 'status' => 'draft',
            'meta' => array( $level => 'assisted', $review => '' ),
        ) );
        trai_ok( $response, 201 );
        trai_check( ! metadata_exists( 'post', $response->get_data()['id'], $review ), 'New article received client stamp.' );
    },
    'page_with_empty_review' => function () use ( $review, $level ) {
        $id = trai_new_post( 'page' );
        trai_ok( trai_request( '/wp/v2/pages/' . $id, array(
            'content' => '<p>Saved page fixture.</p>', 'meta' => array( $level => 'assisted', $review => '' ),
        ) ) );
        trai_check( '<p>Saved page fixture.</p>' === get_post_field( 'post_content', $id ), 'Page content not saved.' );
    },
    'anonymous_requests_still_denied_and_identity_hidden' => function () use ( $review ) {
        $id = trai_new_post();
        TransparAI_Meta::set_content_level( $id, TransparAI_Meta::LEVEL_REVIEWED );
        wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
        wp_set_current_user( 0 );
        trai_ok( trai_request( '/wp/v2/posts/' . $id, array( 'meta' => array( $review => '' ) ) ), 401 );
        $public = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $id ) );
        trai_ok( $public );
        $stamp = json_decode( $public->get_data()['meta'][ $review ], true );
        trai_check( array( 'on' ) === array_keys( $stamp ), 'Public REST response leaked reviewer identity.' );
    },
    'other_protected_meta_stays_protected' => function () use ( $review ) {
        register_post_meta( 'post', '_transparai_test_denied', array(
            'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => '__return_false',
        ) );
        $id = trai_new_post();
        $response = trai_request( '/wp/v2/posts/' . $id, array(
            'meta' => array( $review => '', '_transparai_test_denied' => 'not permitted' ),
        ) );
        trai_ok( $response, 403 );
        trai_check( ! metadata_exists( 'post', $id, '_transparai_test_denied' ), 'Other protected meta was changed.' );
    },
);

$counts_before = array(
    $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
    $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
);
$results = array();
foreach ( $cases as $name => $case ) {
    wp_set_current_user( $admin->ID );
    $wpdb->query( 'START TRANSACTION' );
    try {
        $case();
        $results[ $name ] = 'ok';
    } catch ( Throwable $error ) {
        // Only controlled diagnostics: never print content, settings, users or SQL.
        $results[ $name ] = $error->getMessage();
    } finally {
        $wpdb->query( 'ROLLBACK' );
        wp_cache_flush();
    }
}
trai_check( $counts_before === array(
    $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
    $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
), 'Temporary fixture rows were not rolled back.' );
wp_set_current_user( $admin->ID );
echo wp_json_encode( $results ) . PHP_EOL;
if ( array( 'ok' ) !== array_values( array_unique( $results ) ) ) {
    WP_CLI::error( 'REST review regressions failed.' );
}
