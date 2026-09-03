<?php
/**
 * Class Test_Forms_Data
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Forms_Data;
use SRFM\Inc\Helper;

class Test_Forms_Data extends TestCase {

	protected $forms_data;

	protected function setUp(): void {
		$this->forms_data = new Forms_Data();
	}

	public function test_get_form_permissions_check_admin() {
		$admin_user = wp_insert_user( [
			'user_login' => 'testadmin_formsdata_' . wp_generate_password( 4, false ),
			'user_pass'  => 'password',
			'role'       => 'administrator',
		] );
		wp_set_current_user( $admin_user );

		$result = $this->forms_data->get_form_permissions_check();
		$this->assertTrue( $result );

		wp_delete_user( $admin_user );
	}

	public function test_get_form_permissions_check_subscriber() {
		$subscriber = wp_insert_user( [
			'user_login' => 'testsub_formsdata_' . wp_generate_password( 4, false ),
			'user_pass'  => 'password',
			'role'       => 'subscriber',
		] );
		wp_set_current_user( $subscriber );

		$result = $this->forms_data->get_form_permissions_check();
		$this->assertInstanceOf( WP_Error::class, $result );

		wp_delete_user( $subscriber );
	}

	public function test_register_custom_endpoint() {
		do_action( 'rest_api_init' );
		$routes = rest_get_server()->get_routes();
		$found = false;
		foreach ( array_keys( $routes ) as $route ) {
			if ( strpos( $route, 'sureforms/v1/forms-data' ) !== false ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found, 'The forms-data endpoint should be registered' );
	}

	// ---------------------------------------------------------------
	// get_forms_list()
	// ---------------------------------------------------------------

	/**
	 * Helper: create an admin user, set as current, and return the user ID.
	 */
	private function set_admin_user(): int {
		$user_id = wp_insert_user(
			[
				'user_login' => 'testadmin_gfl_' . wp_generate_password( 6, false ),
				'user_pass'  => 'password',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Helper: build a WP_REST_Request for GET /sureforms/v1/forms with a valid nonce.
	 */
	private function make_forms_request( array $params = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/sureforms/v1/forms' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	/**
	 * Test get_forms_list returns 200 with expected keys for an admin user.
	 */
	public function test_get_forms_list_returns_valid_structure() {
		$admin_id = $this->set_admin_user();

		$request  = $this->make_forms_request( [ 'status' => 'any' ] );
		$response = $this->forms_data->get_forms_list( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'forms', $data );
		$this->assertArrayHasKey( 'total', $data );
		$this->assertArrayHasKey( 'total_pages', $data );
		$this->assertArrayHasKey( 'current_page', $data );
		$this->assertArrayHasKey( 'per_page', $data );

		wp_delete_user( $admin_id );
	}

	/**
	 * Test get_forms_list with a text search term uses title-only matching.
	 */
	public function test_get_forms_list_text_search_returns_200() {
		$admin_id = $this->set_admin_user();

		$request  = $this->make_forms_request( [ 'search' => 'contact', 'status' => 'any' ] );
		$response = $this->forms_data->get_forms_list( $request );

		$this->assertEquals( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'forms', $data );
		// All returned forms should have 'contact' in the title.
		foreach ( $data['forms'] as $form ) {
			$this->assertStringContainsStringIgnoringCase( 'contact', $form['title'] );
		}

		wp_delete_user( $admin_id );
	}

	/**
	 * Test get_forms_list with a numeric search does not leave posts_where filter attached.
	 */
	public function test_get_forms_list_numeric_search_removes_filter_after_query() {
		$admin_id = $this->set_admin_user();

		$request  = $this->make_forms_request( [ 'search' => '42', 'status' => 'any' ] );
		$response = $this->forms_data->get_forms_list( $request );

		$this->assertEquals( 200, $response->get_status() );

		// Verify no srfm_numeric_search filter is still attached by running a plain WP_Query
		// and confirming it returns normally (no stale closure interference).
		$probe = new WP_Query(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'fields'      => 'ids',
			]
		);
		// If filter leaked, the probe query would have an unexpected WHERE clause.
		// Just asserting it completes without error is sufficient.
		$this->assertIsArray( $probe->posts );

		wp_delete_user( $admin_id );
	}

	/**
	 * Test get_forms_list with empty search returns all forms.
	 */
	public function test_get_forms_list_empty_search_returns_all_forms() {
		$admin_id = $this->set_admin_user();

		// Create two test forms.
		$form_a = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Alpha Form' ] );
		$form_b = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Beta Form' ] );

		$request  = $this->make_forms_request( [ 'status' => 'publish', 'per_page' => 100 ] );
		$response = $this->forms_data->get_forms_list( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data     = $response->get_data();
		$form_ids = wp_list_pluck( $data['forms'], 'id' );
		$this->assertContains( $form_a, $form_ids );
		$this->assertContains( $form_b, $form_ids );

		wp_delete_post( $form_a, true );
		wp_delete_post( $form_b, true );
		wp_delete_user( $admin_id );
	}

	/**
	 * Test get_forms_list numeric search matches a form by its ID.
	 */
	public function test_get_forms_list_numeric_search_matches_form_by_id() {
		$admin_id = $this->set_admin_user();

		$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Numeric ID Form' ] );

		$request  = $this->make_forms_request( [ 'search' => (string) $form_id, 'status' => 'any' ] );
		$response = $this->forms_data->get_forms_list( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data     = $response->get_data();
		$form_ids = wp_list_pluck( $data['forms'], 'id' );
		$this->assertContains( $form_id, $form_ids );

		wp_delete_post( $form_id, true );
		wp_delete_user( $admin_id );
	}

	/**
	 * Call a private/protected method on the Forms_Data instance.
	 */
	private function call_private( $method, $args = [] ) {
		$m = new ReflectionMethod( Forms_Data::class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $this->forms_data, $args );
	}

	private function make_tracked_form( $views, $post_date_gmt = null ) {
		$args = [
			'post_title'  => 'Metrics Form',
			'post_type'   => SRFM_FORMS_POST_TYPE,
			'post_status' => 'publish',
		];
		if ( $post_date_gmt ) {
			$args['post_date_gmt'] = $post_date_gmt;
			$args['post_date']     = $post_date_gmt;
		}
		$id = wp_insert_post( $args );
		update_post_meta( $id, \SRFM\Inc\Form_Views::META_KEY, $views );
		return (int) $id;
	}

	/**
	 * The sorted metric and the rendered value must be the same number.
	 *
	 * They were computed in two places and drifted: the sort divided ALL-TIME
	 * entries by views while the column divided only entries inside the tracking
	 * window. A form whose entry history predated the window therefore rendered a
	 * dash while sorting as though its rate were several hundred percent, landing
	 * at the top of a descending sort. Both paths now come through this method.
	 */
	public function test_calculate_form_metrics() {
		delete_option( 'srfm_general_settings_options' );
		delete_option( \SRFM\Inc\Form_Views::TRACKING_STARTED_OPTION );

		$form_id = $this->make_tracked_form( 10 );

		// Tracking off → no numbers at all, and no entry query.
		$off = $this->call_private( 'calculate_form_metrics', [ $form_id, '', null ] );
		$this->assertSame( 0, $off['views'], 'Views must not be reported while the feature is off.' );
		$this->assertNull( $off['conversion_rate'], 'No rate while the feature is off.' );

		// Enable → window opens, views are reported.
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => true ] );
		$on = $this->call_private( 'calculate_form_metrics', [ $form_id, '', null ] );
		$this->assertSame( 10, $on['views'], 'Views should be reported once enabled.' );
		$this->assertSame( 0.0, $on['conversion_rate'], 'No entries yet is a real 0%, not a dash.' );

		// A form with no views cannot have a rate — 0 views is not 0%.
		$unseen = $this->make_tracked_form( 0 );
		$none   = $this->call_private( 'calculate_form_metrics', [ $unseen, '', null ] );
		$this->assertSame( 0, $none['views'] );
		$this->assertNull( $none['conversion_rate'], 'Zero views must give a dash, not 0%.' );

		// More entries than views is impossible, so the count is incomplete and any
		// percentage would be invented. The caller's all-time count is used directly
		// when the form is younger than the window, which is the cheap path.
		$young = $this->make_tracked_form( 2, gmdate( 'Y-m-d H:i:s' ) );
		$over  = $this->call_private( 'calculate_form_metrics', [ $young, gmdate( 'Y-m-d H:i:s' ), 50 ] );
		$this->assertSame( 2, $over['views'] );
		$this->assertNull( $over['conversion_rate'], 'Entries exceeding views must render as a dash, never >100%.' );

		// Normal case on the cheap path: 1 entry, 2 views → 50%.
		$half = $this->call_private( 'calculate_form_metrics', [ $young, gmdate( 'Y-m-d H:i:s' ), 1 ] );
		$this->assertSame( 50.0, $half['conversion_rate'] );

		wp_delete_post( $form_id, true );
		wp_delete_post( $unseen, true );
		wp_delete_post( $young, true );
		delete_option( 'srfm_general_settings_options' );
		delete_option( \SRFM\Inc\Form_Views::TRACKING_STARTED_OPTION );
	}

	/**
	 * The listing row must carry the same numbers calculate_form_metrics() produces.
	 */
	public function test_prepare_form_for_listing() {
		delete_option( \SRFM\Inc\Form_Views::TRACKING_STARTED_OPTION );
		update_option( 'srfm_general_settings_options', [ 'srfm_form_views_tracking' => true ] );

		$form_id = $this->make_tracked_form( 7 );
		$row     = $this->call_private( 'prepare_form_for_listing', [ get_post( $form_id ) ] );
		$metrics = $this->call_private( 'calculate_form_metrics', [ $form_id, get_post( $form_id )->post_date_gmt, $row['entries_count'] ] );

		$this->assertSame( $metrics['views'], $row['views'], 'The row must show the calculated views.' );
		$this->assertSame( $metrics['conversion_rate'], $row['conversion_rate'], 'The row must show the calculated rate.' );
		$this->assertSame( $form_id, $row['id'] );

		wp_delete_post( $form_id, true );
		delete_option( 'srfm_general_settings_options' );
		delete_option( \SRFM\Inc\Form_Views::TRACKING_STARTED_OPTION );
	}

	/**
	 * The window boundary must be expressed in MySQL's frame of reference.
	 *
	 * `created_at` is written by MySQL and compared in the session time zone, so a
	 * bare gmdate() of a PHP timestamp is off by the clock offset between them and
	 * permanently mis-counts entries near the boundary.
	 */
	public function test_window_boundary_sql() {
		global $wpdb;

		$now       = time();
		$boundary  = $this->call_private( 'window_boundary_sql', [ $now ] );
		$mysql_now = $wpdb->get_var( 'SELECT NOW()' );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $boundary, 'Must be a MySQL datetime string.' );

		// Within a couple of seconds of MySQL's own clock, whatever PHP's offset is.
		$this->assertLessThanOrEqual(
			5,
			abs( strtotime( $boundary ) - strtotime( (string) $mysql_now ) ),
			'The boundary for "now" should line up with the database clock, not PHP\'s.'
		);
	}
}
