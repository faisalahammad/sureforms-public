<?php
/**
 * SureForms Database Tables Register Class.
 *
 * @link       https://sureforms.com
 * @since      0.0.10
 * @package    SureForms
 * @author     SureForms <https://sureforms.com/>
 */

namespace SRFM\Inc\Database;

use SRFM\Inc\Database\Tables\Entries;
use SRFM\Inc\Database\Tables\Payments;
use SRFM\Inc\Helper;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * SureForms Database Tables Register Class
 *
 * @since 0.0.13
 */
class Register {
	/**
	 * Transient caching a healthy entries-table check.
	 *
	 * Only the healthy answer is ever cached. A missing table is re-queried every
	 * time, so the warning disappears the moment the table is back rather than
	 * lingering for the rest of the TTL.
	 *
	 * @since x.x.x
	 */
	public const ENTRIES_TABLE_CHECK_TRANSIENT = 'srfm_entries_table_present';

	/**
	 * Per-request memo for the entries-table check.
	 *
	 * The check is consulted from three places in one admin page load (notice
	 * registration, notice rendering, analytics), and this keeps that to a single
	 * transient read.
	 *
	 * @var bool|null
	 * @since x.x.x
	 */
	private static $entries_table_present = null;

	/**
	 * Init database registration.
	 *
	 * @since 0.0.13
	 * @return void
	 */
	public static function init() {
		/*
		 * ### Here, order is important. ###
		 * 1. Start the DB upgrade which also manages the internal versioning of each tables.
		 * 2. Init create method, which create the table if the table does not exists.
		 * 3. Init maybe_add_new_columns, it only runs if we have new columns definition and DB is upgradable ( has new version ).
		 * 4. Init maybe_rename_columns, it only runs if got any columns to rename and DB is upgradable ( has new version ).
		 * 5. Finally, stop the DB upgrade and update the current version in option table.
		 *
		 * Replaced self::get_db_tables() to static::get_db_tables() for allowing overrides.
		 * @since 1.13.0
		 */
		foreach ( static::get_db_tables() as $instance ) {
			$instance->start_db_upgrade();

			if ( $instance->is_db_upgradable() ) {
				// Only execute below methods if DB is upgradable.
				$instance->create( $instance->get_columns_definition() );
				$instance->maybe_add_new_columns( $instance->get_new_columns_definition() );
				$instance->maybe_rename_columns( $instance->get_columns_to_rename() );
			}

			// Stop the upgrade process of current table and move to next.
			$instance->stop_db_upgrade();
		}
	}

	/**
	 * Returns an array of instances/objects of our custom tables.
	 *
	 * @since 0.0.13
	 * @return array<string,\SRFM\Inc\Database\Base>
	 */
	public static function get_db_tables() {
		return [
			'entries'  => Entries::get_instance(),
			'payments' => Payments::get_instance(),
		];
	}

	/**
	 * Whether the entries table is missing from the database.
	 *
	 * A dropped table is never recreated on its own: `srfm_database_table_versions`
	 * still records the current version, so start_db_upgrade() marks the table
	 * non-upgradable and create() early-returns. Nothing else in the plugin notices,
	 * and every submission silently fails to save. This is what surfaces that.
	 *
	 * Returns false while the stored version is absent — Register::init() is going
	 * to create the table on this very request, so reporting it missing would show
	 * a "your database needs updating" notice during a perfectly normal install.
	 *
	 * @param bool $force Skip both caches and re-query.
	 * @since x.x.x
	 * @return bool
	 */
	public static function is_entries_table_missing( $force = false ) {
		if ( ! $force && null !== self::$entries_table_present ) {
			return ! self::$entries_table_present;
		}

		if ( ! $force && get_transient( self::ENTRIES_TABLE_CHECK_TRANSIENT ) ) {
			self::$entries_table_present = true;
			return false;
		}

		$versions = Helper::get_array_value( get_option( 'srfm_database_table_versions', [] ) );

		// No recorded version means this is a fresh install or an upgrade in
		// progress; init() creates the table this request. Nothing to warn about.
		if ( empty( $versions['entries'] ) ) {
			self::$entries_table_present = true;
			return false;
		}

		$present = Entries::get_instance()->table_exists();

		// Cache only the healthy answer. See the transient's docblock.
		if ( $present ) {
			set_transient( self::ENTRIES_TABLE_CHECK_TRANSIENT, 1, DAY_IN_SECONDS );
		} else {
			delete_transient( self::ENTRIES_TABLE_CHECK_TRANSIENT );
		}

		self::$entries_table_present = $present;

		return ! $present;
	}

	/**
	 * Recreate the entries table when it is missing.
	 *
	 * Clearing the stored version is the whole repair: start_db_upgrade() then
	 * treats the table as new, and init() runs the same ordered steps it runs on a
	 * fresh install. Reusing init() rather than re-implementing those steps is
	 * deliberate — the repair path cannot drift from the install path.
	 *
	 * Only the `entries` key is removed. The option is shared with the payments
	 * table and, when Pro is active, three more; clearing the whole option would
	 * force an unrelated re-upgrade pass across all of them from free-plugin code.
	 *
	 * The result is a fresh existence check, never create()'s return value. A
	 * repair that reported success on the strength of create() alone could record
	 * the version again while the table was still missing — which would hide the
	 * problem permanently and be worse than doing nothing.
	 *
	 * @since x.x.x
	 * @return bool True when the table exists afterwards.
	 */
	public static function repair_entries_table() {
		$entries = Entries::get_instance();

		// Prefer adopting the site's own data over manufacturing an empty table.
		// A changed table prefix leaves the rows behind under the old name, and
		// creating a fresh table would strand every stored entry while looking like
		// a successful repair.
		$adoptable = $entries->find_adoptable_table();

		if ( '' !== $adoptable && $entries->adopt_table( $adoptable ) ) {
			// The adopted table already carries its schema, so the stored version
			// stays as-is; init() would have nothing to do. Just refresh the cache.
			return ! self::is_entries_table_missing( true );
		}

		$versions = Helper::get_array_value( get_option( 'srfm_database_table_versions', [] ) );

		unset( $versions['entries'] );
		update_option( 'srfm_database_table_versions', $versions );

		static::init();

		return ! self::is_entries_table_missing( true );
	}

	/**
	 * The entries table sitting under a different prefix, if there is one.
	 *
	 * Exposed so the notice can say whether the repair will bring existing entries
	 * back with it or start from empty — the difference matters a great deal to
	 * whoever is about to click the button.
	 *
	 * @since x.x.x
	 * @return string Full table name, or '' when there is nothing to adopt.
	 */
	public static function get_adoptable_entries_table() {
		if ( ! self::is_entries_table_missing() ) {
			return '';
		}

		return Entries::get_instance()->find_adoptable_table();
	}
}
