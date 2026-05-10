<?php
/**
 * Mock WP_Error class for unit testing.
 *
 * Loaded by tests/Unit/UnitTestCase.php only when the real class isn't
 * present in the runtime (i.e. when running under PHPUnit without WP).
 *
 * @package PostRuntimeEngine\Tests\Unit\Mocks
 */

if ( class_exists( '\\WP_Error' ) ) {
    return;
}

/**
 * Minimal WP_Error mock — same shape as WordPress core's class for the
 * methods PRE actually uses. If a test needs a method this mock doesn't
 * support, add it here rather than mocking around it per-test.
 */
class WP_Error {

    private $errors = array();
    private $error_data = array();

    public function __construct( $code = '', $message = '', $data = '' ) {
        if ( ! empty( $code ) ) {
            $this->add( $code, $message, $data );
        }
    }

    public function add( $code, $message, $data = '' ) {
        $this->errors[ $code ][] = $message;
        if ( ! empty( $data ) ) {
            $this->error_data[ $code ] = $data;
        }
    }

    /**
     * Add error data to an existing error code.
     *
     * Mirrors WordPress core's WP_Error::add_data() signature. The
     * validator uses this to attach contextual data (e.g. the offending
     * field name) to an already-emitted error.
     *
     * @param mixed  $data Error data (array, string, etc.).
     * @param string $code Error code. Defaults to the first error code.
     */
    public function add_data( $data, $code = '' ) {
        if ( empty( $code ) ) {
            $code = $this->get_error_code();
        }
        $this->error_data[ $code ] = $data;
    }

    public function get_error_code() {
        $codes = array_keys( $this->errors );
        return ! empty( $codes ) ? $codes[0] : '';
    }

    public function get_error_codes() {
        return array_keys( $this->errors );
    }

    public function get_error_message( $code = '' ) {
        if ( empty( $code ) ) {
            $code = $this->get_error_code();
        }
        return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
    }

    public function get_error_messages( $code = '' ) {
        if ( empty( $code ) ) {
            $all = array();
            foreach ( $this->errors as $messages ) {
                $all = array_merge( $all, $messages );
            }
            return $all;
        }
        return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
    }

    public function get_error_data( $code = '' ) {
        if ( empty( $code ) ) {
            $code = $this->get_error_code();
        }
        return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
    }

    public function has_errors() {
        return ! empty( $this->errors );
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    /**
     * Check if a variable is a WP_Error instance.
     *
     * @param mixed $thing Variable to check.
     * @return bool
     */
    function is_wp_error( $thing ) {
        return $thing instanceof \WP_Error;
    }
}
