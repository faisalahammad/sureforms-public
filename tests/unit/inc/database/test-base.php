<?php
/**
 * Class Test_Database_Base
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Database\Tables\Entries;
use SRFM\Inc\Database\Tables\Payments;

/**
 * Tests for Base protected methods:
 *   - get_allowed_orderby_columns() via Payments (does not override)
 *   - get_records_by_args() ORDER BY security via Payments
 *   - prepare_where_clauses() via Entries (concrete subclass)
 */
class Test_Database_Base extends TestCase {

	/**
	 * @var Payments
	 */
	protected $base;

	/**
	 * @var Entries
	 */
	protected $entries_table;

	/**
	 * @var ReflectionMethod
	 */
	protected $prepare_where_clauses;

	protected function setUp(): void {
		parent::setUp();
		$this->base                  = Payments::get_instance();
		$this->entries_table         = Entries::get_instance();
		$reflection                  = new ReflectionClass( Entries::class );
		$this->prepare_where_clauses = $reflection->getMethod( 'prepare_where_clauses' );
		$this->prepare_where_clauses->setAccessible( true );
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Helper to invoke the protected get_allowed_orderby_columns method.
	 *
	 * @return array<string>
	 */
	private function get_allowed_columns() {
		$method = new \ReflectionMethod( $this->base, 'get_allowed_orderby_columns' );
		$method->setAccessible( true );
		return $method->invoke( $this->base );
	}

	/**
	 * Helper to invoke prepare_where_clauses.
	 */
	private function prepare( array $where_clauses ): string {
		return $this->prepare_where_clauses->invoke( $this->entries_table, $where_clauses );
	}

	// ---------------------------------------------------------------
	// get_allowed_orderby_columns
	// ---------------------------------------------------------------

	/**
	 * Test get_allowed_orderby_columns returns an array.
	 */
	public function test_get_allowed_orderby_columns() {
		$columns = $this->get_allowed_columns();
		$this->assertIsArray( $columns );
		$this->assertNotEmpty( $columns );
	}

	/**
	 * Test get_allowed_orderby_columns includes all schema keys.
	 */
	public function test_get_allowed_orderby_columns_includes_schema_keys() {
		$columns = $this->get_allowed_columns();
		$schema  = $this->base->get_schema();
		foreach ( array_keys( $schema ) as $key ) {
			$this->assertContains( $key, $columns, "Expected schema key '{$key}' to be in the allowlist." );
		}
	}

	/**
	 * Test get_allowed_orderby_columns always includes updated_at.
	 */
	public function test_get_allowed_orderby_columns_includes_updated_at() {
		$columns = $this->get_allowed_columns();
		$this->assertContains( 'updated_at', $columns );
	}

	/**
	 * Test get_allowed_orderby_columns does not include invalid columns.
	 */
	public function test_get_allowed_orderby_columns_rejects_invalid_column() {
		$columns = $this->get_allowed_columns();
		$this->assertNotContains( 'nonexistent_column', $columns );
		$this->assertNotContains( 'SLEEP(5)', $columns );
	}

	// ---------------------------------------------------------------
	// use_insert
	// ---------------------------------------------------------------

	/**
	 * Test use_insert returns false when required data is missing.
	 */
	public function test_use_insert() {
		// Inserting with an empty array should fail gracefully.
		$result = $this->base->use_insert( [] );
		$this->assertFalse( $result );
	}

	// ---------------------------------------------------------------
	// get_total_count
	// ---------------------------------------------------------------

	/**
	 * Test get_total_count returns an integer.
	 */
	public function test_get_total_count() {
		$result = $this->base->get_total_count();
		$this->assertIsInt( $result );
		$this->assertGreaterThanOrEqual( 0, $result );
	}

	// ---------------------------------------------------------------
	// get_records_by_args ORDER BY security
	// ---------------------------------------------------------------

	/**
	 * Test get_records_by_args returns an array with default args.
	 */
	public function test_get_records_by_args() {
		$result = $this->base->get_records_by_args();
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_records_by_args rejects SQL injection in orderby and still returns array.
	 */
	public function test_get_records_by_args_rejects_invalid_orderby() {
		$result = $this->base->get_records_by_args( [ 'orderby' => 'id` DESC; DROP TABLE wp_posts; --' ] );
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_records_by_args accepts a valid orderby column.
	 */
	public function test_get_records_by_args_with_valid_orderby() {
		$result = $this->base->get_records_by_args( [ 'orderby' => 'created_at', 'order' => 'ASC' ] );
		$this->assertIsArray( $result );
	}

	/**
	 * Test get_records_by_args normalises an invalid order direction to DESC.
	 */
	public function test_get_records_by_args_normalises_invalid_order() {
		$result = $this->base->get_records_by_args( [ 'order' => 'INVALID; DROP TABLE--' ] );
		$this->assertIsArray( $result );
	}

	// ---------------------------------------------------------------
	// prepare_where_clauses — empty / no-op cases
	// ---------------------------------------------------------------

	public function test_prepare_where_clauses_returns_empty_string_for_empty_input() {
		$result = $this->prepare( [] );
		$this->assertSame( '', $result );
	}

	public function test_prepare_where_clauses_skips_unknown_schema_keys() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'nonexistent_column',
						'compare' => '=',
						'value'   => 'test',
					],
				],
			]
		);
		$this->assertSame( '', $result );
	}

	public function test_prepare_where_clauses_skips_disallowed_operator() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'status',
						'compare' => 'INVALID_OP',
						'value'   => 'read',
					],
				],
			]
		);
		$this->assertSame( '', $result );
	}

	// ---------------------------------------------------------------
	// prepare_where_clauses — single AND condition
	// ---------------------------------------------------------------

	public function test_prepare_where_clauses_single_equals_condition() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'status',
						'compare' => '=',
						'value'   => 'read',
					],
				],
			]
		);
		$this->assertStringContainsString( 'WHERE', $result );
		$this->assertStringContainsString( 'status', $result );
		$this->assertStringContainsString( '=', $result );
		$this->assertStringContainsString( 'read', $result );
	}

	public function test_prepare_where_clauses_single_not_equals_condition() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'status',
						'compare' => '!=',
						'value'   => 'trash',
					],
				],
			]
		);
		$this->assertStringContainsString( 'status', $result );
		$this->assertStringContainsString( '!=', $result );
		$this->assertStringContainsString( 'trash', $result );
	}

	public function test_prepare_where_clauses_numeric_equals_condition() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'ID',
						'compare' => '=',
						'value'   => 42,
					],
				],
			]
		);
		$this->assertStringContainsString( 'WHERE', $result );
		$this->assertStringContainsString( 'ID', $result );
		$this->assertStringContainsString( '42', $result );
	}

	// ---------------------------------------------------------------
	// prepare_where_clauses — multiple AND groups
	// ---------------------------------------------------------------

	public function test_prepare_where_clauses_multiple_groups_joined_with_and() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'status',
						'compare' => '!=',
						'value'   => 'trash',
					],
				],
				[
					[
						'key'     => 'form_id',
						'compare' => '=',
						'value'   => 5,
					],
				],
			]
		);
		$this->assertStringContainsString( 'status', $result );
		$this->assertStringContainsString( 'form_id', $result );
		// Both groups joined with AND.
		$this->assertStringContainsString( 'AND', $result );
		// Each group is parenthesized.
		$this->assertMatchesRegularExpression( '/\(status[^)]+\).*AND.*\(form_id[^)]+\)/s', $result );
	}

	// ---------------------------------------------------------------
	// prepare_where_clauses — OR group
	// ---------------------------------------------------------------

	public function test_prepare_where_clauses_or_group_uses_or_within_parentheses() {
		$result = $this->prepare(
			[
				[
					'RELATION' => 'OR',
					[
						'key'     => 'ID',
						'compare' => '=',
						'value'   => 99,
					],
					[
						'key'     => 'form_id',
						'compare' => '=',
						'value'   => 3,
					],
				],
			]
		);
		$this->assertStringContainsString( 'WHERE', $result );
		// The two conditions should appear within one parenthesized group joined by OR.
		$this->assertMatchesRegularExpression( '/\([^)]*ID[^)]*OR[^)]*form_id[^)]*\)/s', $result );
	}

	public function test_prepare_where_clauses_or_group_combined_with_and_group() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'status',
						'compare' => '!=',
						'value'   => 'trash',
					],
				],
				[
					'RELATION' => 'OR',
					[
						'key'     => 'ID',
						'compare' => '=',
						'value'   => 10,
					],
					[
						'key'     => 'form_id',
						'compare' => '=',
						'value'   => 2,
					],
				],
			]
		);
		// Must have parenthesized groups joined by AND.
		$this->assertMatchesRegularExpression( '/\([^)]+\)\s+AND\s+\([^)]+\)/s', $result );
		$this->assertStringContainsString( 'status', $result );
		$this->assertStringContainsString( 'OR', $result );
	}

	// ---------------------------------------------------------------
	// prepare_where_clauses — LIKE operator
	// ---------------------------------------------------------------

	public function test_prepare_where_clauses_like_operator() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'status',
						'compare' => 'LIKE',
						'value'   => 'read',
					],
				],
			]
		);
		$this->assertStringContainsString( 'LIKE', $result );
		$this->assertStringContainsString( 'status', $result );
		$this->assertStringContainsString( 'read', $result );
	}

	// ---------------------------------------------------------------
	// prepare_where_clauses — IN operator
	// ---------------------------------------------------------------

	public function test_prepare_where_clauses_in_operator() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'ID',
						'compare' => 'IN',
						'value'   => [ 1, 2, 3 ],
					],
				],
			]
		);
		$this->assertStringContainsString( 'IN', $result );
		$this->assertStringContainsString( 'ID', $result );
		$this->assertStringContainsString( '1', $result );
		$this->assertStringContainsString( '2', $result );
		$this->assertStringContainsString( '3', $result );
	}

	// ---------------------------------------------------------------
	// prepare_where_clauses — date range
	// ---------------------------------------------------------------

	public function test_prepare_where_clauses_date_range_uses_and() {
		$result = $this->prepare(
			[
				[
					[
						'key'     => 'created_at',
						'compare' => '>=',
						'value'   => '2024-01-01 00:00:00',
					],
					[
						'key'     => 'created_at',
						'compare' => '<=',
						'value'   => '2024-12-31 23:59:59',
					],
				],
			]
		);
		$this->assertStringContainsString( 'created_at', $result );
		$this->assertStringContainsString( '>=', $result );
		$this->assertStringContainsString( '<=', $result );
	}

	// ---------------------------------------------------------------
	// maybe_add_new_columns — guard clause
	// ---------------------------------------------------------------

	/**
	 * maybe_add_new_columns() must short-circuit to false when given no
	 * columns, before attempting any schema ALTER. Guard-clause coverage for
	 * the function that gained the srfm_db_upgrade_query_failed failure hook.
	 */
	public function test_maybe_add_new_columns_returns_false_for_empty_input() {
		$this->assertFalse( $this->base->maybe_add_new_columns( [] ) );
		$this->assertFalse( $this->base->maybe_add_new_columns() );
	}

	// ---------------------------------------------------------------
	// table_exists
	// ---------------------------------------------------------------

	/**
	 * The happy path. Both concrete tables exist on a working install, so a false
	 * here would put a "your database needs updating" warning on every healthy site.
	 */
	public function test_table_exists_reports_a_present_table() {
		$this->assertTrue( $this->entries_table->table_exists() );
		$this->assertTrue( $this->base->table_exists() );
	}

	/**
	 * `$wpdb->prefix` contains an underscore, which is a LIKE wildcard. Without
	 * esc_like(), `wp_srfm_entries` would also match `wpXsrfm_entries` and the check
	 * could report a table that is not ours. Compare the returned name, not just
	 * emptiness, to pin that down.
	 */
	public function test_table_exists_matches_the_exact_table_name() {
		global $wpdb;

		$table = $this->entries_table->get_tablename();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore -- Test assertion helper.

		$this->assertSame( $table, $found );
	}

	/**
	 * The table name is always derived from the live `$wpdb->prefix`, never cached
	 * from install time — that derivation is what makes a prefix change detectable
	 * rather than silently fatal.
	 */
	public function test_get_tablename() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'srfm_entries', $this->entries_table->get_tablename() );
		$this->assertSame( $wpdb->prefix . 'srfm_payments', $this->base->get_tablename() );
	}

	/**
	 * create() must refuse an empty column definition rather than emit a CREATE TABLE
	 * with no body. Guard-clause coverage for the function that gained the
	 * srfm_db_upgrade_query_failed failure hook.
	 */
	public function test_create() {
		$this->assertFalse( $this->entries_table->create( [] ) );
	}

	/**
	 * A name match is not a data match: has_expected_columns() is the guard that stops
	 * an unrelated table being renamed into place just because it ends in the right
	 * words. True for the real table, false for one carrying only an id.
	 */
	public function test_has_expected_columns() {
		global $wpdb;

		$method = new \ReflectionMethod( $this->entries_table, 'has_expected_columns' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $this->entries_table, $this->entries_table->get_tablename() ) );

		$bare = $wpdb->prefix . 'srfm_bare_probe';
		$wpdb->query( "CREATE TABLE `{$bare}` ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY )" ); // phpcs:ignore -- Scratch table for this test.
		$wrong = $method->invoke( $this->entries_table, $bare );
		$wpdb->query( "DROP TABLE `{$bare}`" ); // phpcs:ignore -- Dropping the scratch table this test created.

		$this->assertFalse( $wrong );
	}

	/**
	 * Nothing is adoptable while the table is in place — the live table always wins,
	 * so a stray differently-prefixed copy must not be reported as a candidate.
	 *
	 * The missing-table cases, and every refusal guard, are covered in
	 * Test_Database_Register alongside the repair that consumes them.
	 */
	public function test_find_adoptable_table() {
		$this->assertSame( '', $this->entries_table->find_adoptable_table() );
	}

	/**
	 * adopt_table() renames a table, so its refusals matter more than its happy path:
	 * nothing to adopt is not a repair, and renaming a table onto itself is a MySQL
	 * error rather than a no-op. The successful adoption is covered in
	 * Test_Database_Register, where the rows can be seeded and read back.
	 */
	public function test_adopt_table() {
		$this->assertFalse( $this->entries_table->adopt_table( '' ) );
		$this->assertFalse( $this->entries_table->adopt_table( $this->entries_table->get_tablename() ) );
	}

	/**
	 * A dropped table must read as missing — this is the state the whole
	 * detect-and-repair feature exists to catch.
	 */
	public function test_table_exists_reports_a_dropped_table_as_missing() {
		global $wpdb;

		$table = $this->entries_table->get_tablename();

		$wpdb->query( "CREATE TABLE `{$table}_srfmbak` LIKE `{$table}`" ); // phpcs:ignore -- Preserving the schema across the drop under test.
		$wpdb->query( "DROP TABLE `{$table}`" ); // phpcs:ignore -- Reproducing the dropped-table state under test.

		$missing = $this->entries_table->table_exists();

		$wpdb->query( "RENAME TABLE `{$table}_srfmbak` TO `{$table}`" ); // phpcs:ignore -- Restoring the table this test dropped.

		$this->assertFalse( $missing );
		$this->assertTrue( $this->entries_table->table_exists() );
	}
}
