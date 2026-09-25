<?php
/**
 * Base test case for tests that need WordPress fixtures but not WP_UnitTestCase.
 *
 * @package sureforms
 */

/**
 * Adds factory() to the PHPUnit Polyfills test case.
 *
 * Most of this suite extends Yoast's TestCase rather than WP_UnitTestCase, and
 * cleans up after itself by hand instead of relying on a per-test transaction.
 * Seven classes were written against self::factory() anyway and errored with
 * "Call to undefined method", accounting for 64 of the suite's failures.
 *
 * Extending WP_UnitTestCase instead would bring its whole fixture - the
 * transaction rollback those tear_down() methods already duplicate, and the
 * _doing_it_wrong() trap that fails a test on any incorrect-usage notice. This
 * hands over the factory and nothing else.
 */
abstract class SRFM_Unit_Test_Case extends \Yoast\PHPUnitPolyfills\TestCases\TestCase {

	/**
	 * Shared WordPress fixture factory, matching WP_UnitTestCase_Base::factory().
	 *
	 * @return WP_UnitTest_Factory
	 */
	public static function factory() {
		static $factory = null;

		if ( null === $factory ) {
			$factory = new WP_UnitTest_Factory();
		}

		return $factory;
	}
}
