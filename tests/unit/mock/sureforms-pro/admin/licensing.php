<?php
/**
 * Minimal stand-in for SRFM_Pro\Admin\Licensing.
 *
 * The free plugin only ever reaches Pro licensing through class_exists() plus
 * duck typing, so tests that need a "Pro is installed" world can load this.
 *
 * Declaring this class is global and permanent for the PHP process, which would
 * break the tests that assert the no-Pro path - so only load it from a test
 * marked @runInSeparateProcess.
 *
 * @package sureforms
 */

namespace SRFM_Pro\Admin;

/**
 * Records how is_license_active() was called so tests can assert on it.
 */
class Licensing {

	/**
	 * Every $allow_remote_check value is_license_active() was called with.
	 *
	 * @var array<bool>
	 */
	public static $remote_check_calls = [];

	/**
	 * What is_license_active() should return.
	 *
	 * @var bool
	 */
	public static $is_active = true;

	/**
	 * Singleton accessor, mirroring the Get_Instance trait.
	 *
	 * @return self
	 */
	public static function get_instance() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Mirrors the real signature, and records the argument it was given.
	 *
	 * @param bool $allow_remote_check Whether a cache miss may block on the API.
	 * @return bool
	 */
	public static function is_license_active( $allow_remote_check = true ) {
		self::$remote_check_calls[] = $allow_remote_check;
		return self::$is_active;
	}

	/**
	 * Returns a stub client exposing settings()->license_key.
	 *
	 * @return object
	 */
	public static function licensing_setup() {
		return new class() {
			/**
			 * Stub settings object.
			 *
			 * @return object
			 */
			public function settings() {
				return (object) [ 'license_key' => 'mock-license-key' ];
			}
		};
	}
}
