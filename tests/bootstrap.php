<?php
/**
 * PHPUnit Bootstrap File for Post Runtime Engine Tests.
 *
 * Modeled on Form Runtime Engine's tests/bootstrap.php so test conventions
 * stay consistent across the two plugins. Two test modes are supported:
 *
 *   - Unit (default): Brain\Monkey mocks the WordPress functions PRE
 *     touches. Fast, deterministic, no DB, no WP install required.
 *
 *   - Integration: a real WordPress test instance loads PRE as a plugin
 *     so registry persistence, post meta, REST endpoints, and template
 *     rendering can be exercised end-to-end. Requires a one-time install
 *     of the WP test library — see bin/install-wp-tests.sh.
 *
 * Selected via the TEST_SUITE env var (composer scripts pass it in).
 *
 * @package PostRuntimeEngine\Tests
 */

if ( ! defined( 'PRE_TESTING' ) ) {
    define( 'PRE_TESTING', true );
}

if ( ! defined( 'PRE_TEST_PLUGIN_DIR' ) ) {
    define( 'PRE_TEST_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Load Composer autoloader (shared by both modes).
$composer_autoload = PRE_TEST_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $composer_autoload ) ) {
    echo "Error: Run 'composer install' from " . PRE_TEST_PLUGIN_DIR . " before running tests.\n";
    exit( 1 );
}
require_once $composer_autoload;

// Dispatch on TEST_SUITE — composer scripts set this so the right
// bootstrap path runs even when phpunit is invoked directly.
$test_suite = getenv( 'TEST_SUITE' ) ?: 'unit';

if ( $test_suite === 'integration' ) {
    pre_bootstrap_integration_tests();
} else {
    pre_bootstrap_unit_tests();
}

/**
 * Bootstrap unit tests with Brain\Monkey.
 *
 * Defines the PRE_* constants the plugin code references and loads the
 * autoloader so individual test files can `require_once` the class
 * under test.
 */
function pre_bootstrap_unit_tests() {
    require_once PRE_TEST_PLUGIN_DIR . 'vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', '/tmp/wordpress/' );
    }
    if ( ! defined( 'PRE_VERSION' ) ) {
        define( 'PRE_VERSION', '0.3.0' );
    }
    if ( ! defined( 'PRE_DATA_VERSION' ) ) {
        define( 'PRE_DATA_VERSION', '0.1.0' );
    }
    if ( ! defined( 'PRE_PLUGIN_DIR' ) ) {
        define( 'PRE_PLUGIN_DIR', PRE_TEST_PLUGIN_DIR );
    }
    if ( ! defined( 'PRE_PLUGIN_URL' ) ) {
        define( 'PRE_PLUGIN_URL', 'http://example.com/wp-content/plugins/post-runtime-engine/' );
    }
    if ( ! defined( 'PRE_PLUGIN_BASENAME' ) ) {
        define( 'PRE_PLUGIN_BASENAME', 'post-runtime-engine/post-runtime-engine.php' );
    }
    if ( ! defined( 'PRE_PLUGIN_FILE' ) ) {
        define( 'PRE_PLUGIN_FILE', PRE_TEST_PLUGIN_DIR . 'post-runtime-engine.php' );
    }
    if ( ! defined( 'PRE_REST_NAMESPACE' ) ) {
        define( 'PRE_REST_NAMESPACE', 'post-runtime/v1' );
    }
    if ( ! defined( 'PRE_REST_BASE' ) ) {
        define( 'PRE_REST_BASE', 'connector' );
    }

    require_once PRE_TEST_PLUGIN_DIR . 'includes/class-pre-autoloader.php';
}

/**
 * Bootstrap integration tests against a real WordPress test instance.
 *
 * Mirrors FRE's pattern. Resolves the WP test library location from the
 * WP_TESTS_DIR env var or a few common defaults, fails with a clear
 * install hint if not found, then hooks the plugin into muplugins_loaded
 * so the plugin's normal init flow runs against the test WP instance.
 */
function pre_bootstrap_integration_tests() {
    $wp_tests_dir = getenv( 'WP_TESTS_DIR' );

    if ( ! $wp_tests_dir ) {
        $candidates = array(
            '/tmp/wordpress-tests-lib',
            getenv( 'HOME' ) . '/.wp-tests/wordpress-tests-lib',
            dirname( PRE_TEST_PLUGIN_DIR, 5 ) . '/tests/phpunit',
        );

        foreach ( $candidates as $candidate ) {
            if ( file_exists( $candidate . '/includes/functions.php' ) ) {
                $wp_tests_dir = $candidate;
                break;
            }
        }
    }

    if ( ! $wp_tests_dir ) {
        echo "Error: WordPress test framework not found.\n";
        echo "\nInstall it with:\n";
        echo "  composer install-wp-tests\n";
        echo "\nOr point WP_TESTS_DIR at an existing install:\n";
        echo "  WP_TESTS_DIR=/path/to/wordpress-tests-lib composer test:integration\n";
        echo "\nFor unit tests (no WP install required), run:\n";
        echo "  composer test:unit\n";
        exit( 1 );
    }

    require_once $wp_tests_dir . '/includes/functions.php';

    // Load the plugin file itself once WP has booted to the muplugins_loaded
    // stage. tests_add_filter() is provided by the test framework's
    // functions.php loaded above.
    tests_add_filter( 'muplugins_loaded', function () {
        require PRE_TEST_PLUGIN_DIR . 'post-runtime-engine.php';
    } );

    require $wp_tests_dir . '/includes/bootstrap.php';
}
