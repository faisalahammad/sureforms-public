<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package sureforms
 */

// if ( PHP_MAJOR_VERSION >= 8 ) {
// 	echo 'The scaffolded tests cannot currently be run on PHP 8.0+. See https://github.com/wp-cli/scaffold-command/issues/285' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
// 	exit( 1 );
// }

$_tests_dir = getenv( 'WP_TESTS_DIR' );

/**
 * Load PHPUnit Polyfills for the WP testing suite.
 *
 * @see https://github.com/WordPress/wordpress-develop/pull/1563/
 */
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( dirname( __FILE__ ) ) . '/sureforms.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

/*
 * Keep the suite from silently dying at status 0.
 *
 * wp_send_json_*() ends in a bare `die;` unless wp_doing_ajax() is true, and the WP
 * test suite only routes wp_die_handler - not wp_die_ajax_handler - to a throwing
 * handler. Any test reaching a JSON responder therefore killed the whole run mid-way
 * while PHPUnit still exited 0, so CI reported success on a truncated suite.
 *
 * wp_doing_ajax() is only forced true underneath wp_send_json(), not suite-wide:
 * forcing it globally changes plugin behaviour and cost 3 extra failures. A closure
 * is used rather than '__return_true' because several tests call
 * remove_filter( 'wp_doing_ajax', '__return_true' ), which shares its callback id
 * and would silently remove this one too.
 */
add_filter(
	'wp_doing_ajax',
	static function ( $doing_ajax ) {
		if ( $doing_ajax ) {
			return true;
		}

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( isset( $frame['function'] ) && 'wp_send_json' === $frame['function'] ) {
				return true;
			}
		}

		return false;
	}
);
add_filter(
	'wp_die_ajax_handler',
	static function () {
		return static function ( $message ) {
			throw new \WPDieException( is_scalar( $message ) ? (string) $message : '' );
		};
	}
);

/*
 * Tests marked @runInSeparateProcess re-enter this bootstrap in a child process, and
 * the WP test bootstrap drops and recreates every table when it runs. That wiped the
 * database out from under the parent run, so later DB-backed tests failed for reasons
 * that had nothing to do with them. The parent has already installed by this point,
 * so let the children inherit this and reuse the same tables.
 */
putenv( 'WP_TESTS_SKIP_INSTALL=1' );

require_once __DIR__ . '/includes/class-srfm-unit-test-case.php';
