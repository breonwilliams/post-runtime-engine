<?php
/**
 * Base Unit Test Case for Post Runtime Engine.
 *
 * Mirrors FRE's tests/Unit/UnitTestCase.php pattern. Uses Brain\Monkey
 * to mock WordPress functions so unit tests can run without a real WP
 * install. Each subclass that needs additional mocks should override
 * `setup_common_mocks()` and call parent::setup_common_mocks() first.
 *
 * @package PostRuntimeEngine\Tests\Unit
 */

namespace PRE\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Base unit test case with Brain\Monkey setup + WordPress option mocking.
 *
 * The option-store mock is the most important piece — PRE persists CPTs
 * and groupings to wp_options, so a working get_option/update_option
 * pair is the prerequisite for testing the registry classes. This base
 * provides an in-memory option store keyed by option name; subclasses
 * can read/write it freely and reset it between tests.
 */
abstract class UnitTestCase extends TestCase {

    /**
     * In-memory option store. Keyed by option name. Reset before each test.
     *
     * @var array
     */
    protected $options = array();

    /**
     * In-memory post-meta store. Keyed by "{$post_id}|{$meta_key}". Reset before each test.
     *
     * @var array
     */
    protected $post_meta = array();

    /**
     * In-memory transient store. Reset before each test.
     *
     * @var array
     */
    protected $transients = array();

    /**
     * Set up Brain\Monkey + reset in-memory stores before each test.
     */
    protected function set_up() {
        parent::set_up();
        Monkey\setUp();

        // Reset stores to a clean slate per test.
        $this->options = array();
        $this->post_meta = array();
        $this->transients = array();

        $this->setup_common_mocks();
    }

    /**
     * Tear down Brain\Monkey after each test.
     */
    protected function tear_down() {
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * Set up the WordPress function mocks every PRE test needs.
     *
     * Subclasses can extend this. Most subclasses won't need to —
     * registry/validator tests work directly against the in-memory
     * stores set up here.
     */
    protected function setup_common_mocks() {
        // ---------------------------------------------------------------
        // Sanitization functions — light-weight implementations so they
        // pass through expected values without dragging in WP core.
        // ---------------------------------------------------------------
        Functions\when( 'sanitize_key' )->alias( function ( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
        } );

        Functions\when( 'sanitize_text_field' )->alias( function ( $str ) {
            return trim( strip_tags( (string) $str ) );
        } );

        Functions\when( 'sanitize_title' )->alias( function ( $str ) {
            return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $str ) );
        } );

        Functions\when( 'wp_unslash' )->alias( function ( $value ) {
            if ( is_array( $value ) ) {
                return array_map( 'stripslashes_deep', $value );
            }
            if ( is_string( $value ) ) {
                return stripslashes( $value );
            }
            return $value;
        } );

        Functions\when( 'esc_html' )->alias( function ( $str ) {
            return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
        } );

        Functions\when( 'esc_attr' )->alias( function ( $str ) {
            return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' );
        } );

        Functions\when( 'esc_url' )->alias( function ( $url ) {
            return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
        } );

        Functions\when( 'esc_url_raw' )->alias( function ( $url ) {
            return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
        } );

        Functions\when( 'wp_kses_post' )->alias( function ( $str ) {
            // Test-environment shim: strip script tags but otherwise pass through.
            return preg_replace( '#<script\b[^>]*>.*?</script>#is', '', (string) $str );
        } );

        // ---------------------------------------------------------------
        // Translation — return the string as-is.
        // ---------------------------------------------------------------
        Functions\when( '__' )->returnArg( 1 );
        Functions\when( '_x' )->returnArg( 1 );
        Functions\when( '_n' )->returnArg( 1 );
        Functions\when( 'esc_html__' )->returnArg( 1 );
        Functions\when( 'esc_attr__' )->returnArg( 1 );
        Functions\when( '_e' )->alias( function ( $text ) {
            echo (string) $text;
        } );
        Functions\when( 'esc_html_e' )->alias( function ( $text ) {
            echo htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
        } );

        // ---------------------------------------------------------------
        // Option API — backed by the in-memory $this->options store.
        // ---------------------------------------------------------------
        $self = $this;
        Functions\when( 'get_option' )->alias( function ( $option, $default = false ) use ( $self ) {
            return array_key_exists( $option, $self->options ) ? $self->options[ $option ] : $default;
        } );

        Functions\when( 'update_option' )->alias( function ( $option, $value, $autoload = null ) use ( $self ) {
            $self->options[ $option ] = $value;
            return true;
        } );

        Functions\when( 'add_option' )->alias( function ( $option, $value, $deprecated = '', $autoload = 'yes' ) use ( $self ) {
            if ( array_key_exists( $option, $self->options ) ) {
                return false; // matches WP behavior: add_option is a no-op when option exists
            }
            $self->options[ $option ] = $value;
            return true;
        } );

        Functions\when( 'delete_option' )->alias( function ( $option ) use ( $self ) {
            if ( ! array_key_exists( $option, $self->options ) ) {
                return false;
            }
            unset( $self->options[ $option ] );
            return true;
        } );

        // ---------------------------------------------------------------
        // Post meta API — backed by $this->post_meta. Keys are
        // composed as "{$post_id}|{$meta_key}" to match WP's
        // (post_id, meta_key, meta_value) tuple model.
        // ---------------------------------------------------------------
        Functions\when( 'get_post_meta' )->alias( function ( $post_id, $key = '', $single = false ) use ( $self ) {
            if ( '' === $key ) {
                // Return all meta for this post (rarely used in PRE; provide empty default).
                return array();
            }
            $store_key = (string) $post_id . '|' . $key;
            if ( ! array_key_exists( $store_key, $self->post_meta ) ) {
                return $single ? '' : array();
            }
            $value = $self->post_meta[ $store_key ];
            return $single ? $value : array( $value );
        } );

        Functions\when( 'update_post_meta' )->alias( function ( $post_id, $key, $value ) use ( $self ) {
            $store_key = (string) $post_id . '|' . $key;
            $self->post_meta[ $store_key ] = $value;
            return true;
        } );

        Functions\when( 'delete_post_meta' )->alias( function ( $post_id, $key ) use ( $self ) {
            $store_key = (string) $post_id . '|' . $key;
            if ( ! array_key_exists( $store_key, $self->post_meta ) ) {
                return false;
            }
            unset( $self->post_meta[ $store_key ] );
            return true;
        } );

        // ---------------------------------------------------------------
        // Transient API — backed by $this->transients. PRE uses
        // transients for renderer caching; tests can verify cache
        // invalidation by inspecting this store.
        // ---------------------------------------------------------------
        Functions\when( 'get_transient' )->alias( function ( $key ) use ( $self ) {
            return array_key_exists( $key, $self->transients ) ? $self->transients[ $key ] : false;
        } );

        Functions\when( 'set_transient' )->alias( function ( $key, $value, $expiration = 0 ) use ( $self ) {
            $self->transients[ $key ] = $value;
            return true;
        } );

        Functions\when( 'delete_transient' )->alias( function ( $key ) use ( $self ) {
            if ( ! array_key_exists( $key, $self->transients ) ) {
                return false;
            }
            unset( $self->transients[ $key ] );
            return true;
        } );

        // ---------------------------------------------------------------
        // Hooks — return a no-op for register-type calls so plugin code
        // that wires hooks at construction doesn't blow up under test.
        // Tests that need to verify hook registration should use
        // Functions\expect() in the specific test, not the global mock.
        // ---------------------------------------------------------------
        Functions\when( 'add_action' )->justReturn();
        Functions\when( 'add_filter' )->justReturn();
        Functions\when( 'remove_action' )->justReturn();
        Functions\when( 'remove_filter' )->justReturn();
        Functions\when( 'do_action' )->justReturn();
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value; // identity passthrough
        } );

        // ---------------------------------------------------------------
        // Capability checks — assume the test user can do anything by
        // default. Tests that need to verify cap-gate behavior should
        // override this in their own setup.
        // ---------------------------------------------------------------
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\when( 'is_admin' )->justReturn( false );

        // ---------------------------------------------------------------
        // Post-type existence check — used by PCPTPages_CPT_Registry::unregister
        // to decide whether to flush rewrite rules. Default to false so
        // tests don't get unexpected side effects; tests that need to
        // simulate "WP knows about this CPT" can override per-test.
        // ---------------------------------------------------------------
        Functions\when( 'post_type_exists' )->justReturn( false );
        Functions\when( 'unregister_post_type' )->justReturn( true );
        Functions\when( 'flush_rewrite_rules' )->justReturn( null );

        // ---------------------------------------------------------------
        // Misc — light shims for utilities PRE classes touch.
        // ---------------------------------------------------------------
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = array() ) {
            if ( is_object( $args ) ) {
                $args = get_object_vars( $args );
            }
            if ( ! is_array( $args ) ) {
                $args = array();
            }
            return array_merge( (array) $defaults, $args );
        } );

        Functions\when( 'absint' )->alias( function ( $value ) {
            return abs( intval( $value ) );
        } );

        // WP_Error mock — load only if the real class isn't already
        // present (e.g. when running alongside other plugins that
        // declare it).
        if ( ! class_exists( '\\WP_Error' ) ) {
            require_once __DIR__ . '/Mocks/WP_Error.php';
        }
    }
}
