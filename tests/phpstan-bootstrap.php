<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines plugin and WordPress runtime constants that PHPStan's static scan
 * cannot resolve from `define()` calls. Values are sentinels — only the symbol
 * existence matters for analysis.
 *
 * @package TransparAI
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', false );
defined( 'WP_CLI' ) || define( 'WP_CLI', false );

defined( 'TRANSPARAI_VERSION' ) || define( 'TRANSPARAI_VERSION', '0.0.0-dev' );
defined( 'TRANSPARAI_PLUGIN_DIR' ) || define( 'TRANSPARAI_PLUGIN_DIR', __DIR__ . '/' );
defined( 'TRANSPARAI_PLUGIN_URL' ) || define( 'TRANSPARAI_PLUGIN_URL', 'https://example.test/wp-content/plugins/transparai/' );
defined( 'TRANSPARAI_PLUGIN_FILE' ) || define( 'TRANSPARAI_PLUGIN_FILE', __DIR__ . '/transparai.php' );
defined( 'TRANSPARAI_PLUGIN_BASENAME' ) || define( 'TRANSPARAI_PLUGIN_BASENAME', 'transparai/transparai.php' );
