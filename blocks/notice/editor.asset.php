<?php
/**
 * Dependencies of the hand-written (no build step) block editor script.
 *
 * @package TransparAI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
	'version'      => defined( 'TRANSPARAI_VERSION' ) ? TRANSPARAI_VERSION : '1.0.0',
);
