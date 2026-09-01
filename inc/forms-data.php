<?php
/**
 * Sureforms get forms title and Ids.
 *
 * @package sureforms.
 * @since 0.0.1
 */

namespace SRFM\Inc;

use SRFM\Inc\Database\Tables\Entries;
use SRFM\Inc\Traits\Get_Instance;
use WP_Error;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Load Defaults Class.
 *
 * @since 0.0.1
 */
class Forms_Data {
	use Get_Instance;

	/**
	 * Constructor
	 *
	 * @since 0.0.1
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_custom_endpoint' ] );
	}

	/**
	 * Add custom API Route load-form-defaults
	 *
	 * @return void
	 * @since 0.0.1
	 */
	public function register_custom_endpoint() {
		register_rest_route(
			'sureforms/v1',
			'/forms-data',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'load_forms' ],
				'permission_callback' => [ $this, 'get_form_permissions_check' ],
			]
		);
	}

	/**
	 * Checks whether a given request has permission to read the form.
	 *
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 * @since 0.0.1
	 */
	public function get_form_permissions_check() {
		if ( Helper::current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_cannot_view',
			__( 'Sorry, you are not allowed to view the form.', 'sureforms' ),
			[ 'status' => \rest_authorization_required_code() ]
		);
	}

	/**
	 * Handle Form status
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response
	 * @since 0.0.1
	 */
	public function load_forms( $request ) {

		$nonce = Helper::get_string_value( $request->get_header( 'X-WP-Nonce' ) );

		if ( ! wp_verify_nonce( sanitize_text_field( $nonce ), 'wp_rest' ) ) {
			wp_send_json_error(
				[
					'data'   => __( 'Nonce verification failed.', 'sureforms' ),
					'status' => false,
				]
			);
		}

		$args = [
			'post_type'      => 'sureforms_form',
			'post_status'    => 'publish',
			'posts_per_page' => -1, // Retrieve all posts.
		];

		$form_posts = get_posts( $args );

		$data = [];

		foreach ( $form_posts as $post ) {
			$data[] = [
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'content' => $post->post_content,
			];
		}

		return new WP_REST_Response( $data );
	}

	/**
	 * Get forms list for the forms listing page.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 * @since 2.0.0
	 */
	public function get_forms_list( $request ) {
		$nonce = sanitize_text_field( Helper::get_string_value( $request->get_header( 'X-WP-Nonce' ) ) );

		Helper::verify_nonce_and_capabilities( 'rest', $nonce, 'wp_rest' );

		// Get and validate request parameters.
		$page   = max( 1, Helper::get_integer_value( $request->get_param( 'page' ) ) );
		$status = sanitize_text_field( $request->get_param( 'status' ) );

		// Get per_page from option first, then request parameter, with fallback to 10.
		$saved_per_page   = Helper::get_srfm_option( 'forms_per_page', 10 );
		$request_per_page = $request->get_param( 'per_page' );
		$per_page         = $request_per_page ? min( 100, max( 1, Helper::get_integer_value( $request_per_page ) ) ) : $saved_per_page;

		// Save per_page to option if it came from request.
		if ( $request_per_page && 'trash' !== $status && 1 < $request_per_page ) {
			Helper::update_srfm_option( 'forms_per_page', $per_page );
		}

		$search    = sanitize_text_field( $request->get_param( 'search' ) );
		$orderby   = sanitize_text_field( $request->get_param( 'orderby' ) );
		$order     = sanitize_text_field( $request->get_param( 'order' ) );
		$date_from = sanitize_text_field( $request->get_param( 'after' ) );
		$date_to   = sanitize_text_field( $request->get_param( 'before' ) );

		// Build query arguments.
		$args = [
			'post_type'      => SRFM_FORMS_POST_TYPE,
			'post_status'    => 'any' === $status ? [ 'publish', 'draft' ] : $status,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => $orderby,
			'order'          => $order,
		];

		// Add date range filtering.
		if ( ! empty( $date_from ) || ! empty( $date_to ) ) {
			$date_query = [];
			// Handle 'after' date.
			if ( ! empty( $date_from ) ) {
				$date_query['after'] = $date_from;
			}

			// Handle 'before' date - add 1 day to include the full end date.
			if ( ! empty( $date_to ) ) {
				$end_date = new \DateTime( $date_to );
				$end_date->add( new \DateInterval( 'P1D' ) ); // Add 1 day.
				$date_query['before'] = $end_date->format( 'Y-m-d' );
			}

			$date_query['inclusive'] = true;
			$args['date_query']      = [ $date_query ];
		}

		// Add search parameter.
		if ( ! empty( $search ) ) {
			if ( is_numeric( $search ) ) {
				// Numeric search: match by form ID and title (e.g., "2024 Survey").
				$numeric_search = $search;
				$where_filter   = static function ( $where, $query ) use ( $numeric_search ) {
					if ( ! $query->get( 'srfm_numeric_search' ) ) {
						return $where;
					}
					global $wpdb;
					$where .= $wpdb->prepare(
						" AND ({$wpdb->posts}.ID = %d OR {$wpdb->posts}.post_title LIKE %s)",
						absint( $numeric_search ),
						'%' . $wpdb->esc_like( $numeric_search ) . '%'
					);
					return $where;
				};

				add_filter( 'posts_where', $where_filter, 10, 2 );
				$args['srfm_numeric_search'] = true;
			} else {
				// Text search: match by title only.
				$args['s']              = $search;
				$args['search_columns'] = [ 'post_title' ];
			}
		}

		// Execute query — use try/finally to guarantee filter cleanup.
		try {
			// Derived metrics can't be sorted by WP_Query, so they take a
			// compute-sort-paginate pass instead. Only while tracking is on: with the
			// feature off the columns are hidden, and a stored or hand-crafted request
			// would run that expensive pass to order rows nobody can see. It also
			// returns null when the site has more forms than the pass will scan.
			$response_data  = null;
			$is_metric_sort = in_array( $orderby, [ 'views', 'conversion_rate' ], true );

			if ( $is_metric_sort && Form_Views::get_instance()->is_tracking_enabled() ) {
				$response_data = $this->get_forms_sorted_by_metric( $args, $orderby, $order, $page, Helper::get_integer_value( $per_page ) );
			}

			// True only when the metric sort actually ran. It returns null and falls back
			// to date order when the feature is off or the site is past the sort ceiling;
			// the table needs to know so it does not leave an active sort arrow on a
			// column whose order was silently ignored.
			$metric_sort_applied = $is_metric_sort && null !== $response_data;

			if ( null === $response_data ) {
				if ( $is_metric_sort ) {
					// WP_Query would silently discard 'views'/'conversion_rate' and fall
					// back to post_date anyway. Say so explicitly so the behaviour is in
					// the code rather than in core's tolerance for unknown keys.
					$args['orderby'] = 'date';
				}

				$query = new \WP_Query( $args );

				$forms = [];
				/**
				 * Post object from the query.
				 *
				 * @var \WP_Post $post */
				foreach ( $query->posts as $post ) {
					$forms[] = $this->prepare_form_for_listing( $post );
				}

				$response_data = [
					'forms'        => $forms,
					'total'        => Helper::get_integer_value( $query->found_posts ),
					'total_pages'  => Helper::get_integer_value( $query->max_num_pages ),
					'current_page' => $page,
					'per_page'     => $per_page,
				];
			}
		} finally {
			if ( ! empty( $search ) && is_numeric( $search ) && isset( $where_filter ) ) {
				remove_filter( 'posts_where', $where_filter, 10 );
			}
		}

		// Travels with the rows so the table gates its columns on the same evaluation
		// that produced them. The localized `srfm_admin` flag is only a page-load
		// snapshot: toggle the setting in another tab and the open list would keep
		// rendering columns while every row came back empty, which reads as data loss.
		$response_data['views_enabled'] = Form_Views::get_instance()->is_tracking_enabled();

		// Lets the table reset a stale metric sort arrow when the order silently fell
		// back to date (feature off, or past the metric-sort ceiling).
		$response_data['metric_sort_applied'] = $metric_sort_applied;

		return new WP_REST_Response( $response_data, 200 );
	}

	/**
	 * Sort the forms list by a derived metric (views or conversion rate) and paginate.
	 *
	 * WP_Query can't order by views (missing-meta forms would drop out) or by the
	 * derived conversion rate at all, so we fetch every matching form id, compute the
	 * metric, sort in PHP, then slice the requested page. Form counts are small in
	 * practice; revisit with a grouped query if a site accumulates thousands of forms.
	 *
	 * @param array<string,mixed> $args     Base WP_Query args (filters/search), pagination ignored.
	 * @param string              $orderby  Either 'views' or 'conversion_rate'.
	 * @param string              $order    'asc' or 'desc'.
	 * @param int                 $page     Current page (1-based).
	 * @param int                 $per_page Items per page.
	 * @since x.x.x
	 * @return array<string,mixed>|null Response payload, or null when the site has more
	 *                                  forms than this pass will scan and the caller
	 *                                  should fall back to ordinary ordering.
	 */
	private function get_forms_sorted_by_metric( $args, $orderby, $order, $page, $per_page ) {
		$id_args                   = $args;
		$id_args['posts_per_page'] = -1;
		$id_args['paged']          = 1;
		$id_args['fields']         = 'ids';
		$id_args['orderby']        = 'ID';
		$id_args['order']          = 'DESC';

		$id_query = new \WP_Query( $id_args );

		/**
		 * Largest number of forms this path will sort before giving up.
		 *
		 * The pass is one entry COUNT per form, so cost grows linearly with the form
		 * count while only one page is ever displayed. Past this ceiling the request
		 * returns null so the caller falls back to ordinary date ordering rather than
		 * firing thousands of queries to render ten rows.
		 *
		 * @param int $limit Maximum forms to sort in PHP. Default 500.
		 * @since x.x.x
		 */
		$limit = Helper::get_integer_value( apply_filters( 'srfm_forms_metric_sort_limit', 500 ) );

		if ( $limit > 0 && count( $id_query->posts ) > $limit ) {
			/**
			 * Fires when the metric sort is skipped because the site has too many forms.
			 *
			 * Announced rather than skipped silently: a list that quietly ignores the
			 * column the user clicked reads as a broken sort, not as a deliberate
			 * ceiling. Gives a site owner something to hook if they hit it.
			 *
			 * @param string $orderby Requested metric, 'views' or 'conversion_rate'.
			 * @param int    $count   Number of forms that would have been sorted.
			 * @param int    $limit   The ceiling in force.
			 * @since x.x.x
			 */
			do_action( 'srfm_forms_metric_sort_skipped', $orderby, count( $id_query->posts ), $limit );

			return null;
		}

		// `fields => ids` skips the post-meta cache priming that a normal WP_Query does,
		// so prime it once for all matched forms — otherwise each get_views() below is a
		// separate get_post_meta() query (N+1). Entry counts are still one COUNT per form;
		// acceptable for typical form volumes, revisit with a grouped query if needed.
		if ( ! empty( $id_query->posts ) ) {
			$prime_ids = array_map(
				static function ( $post ) {
					return (int) ( $post instanceof \WP_Post ? $post->ID : $post );
				},
				$id_query->posts
			);
			update_meta_cache( 'post', $prime_ids );

			// `fields => ids` skips the post cache, so the get_post() in the pagination
			// loop below would be one query per displayed row. Prime it here instead.
			// Terms and meta are handled separately, hence both flags false.
			_prime_post_caches( $prime_ids, false, false );
		}

		$rows = [];
		foreach ( $id_query->posts as $post_id ) {
			$form_id = Helper::get_integer_value( $post_id );

			// Same three arguments the render path passes. Calling this with only the
			// form ID left $post_date_gmt empty, so strtotime() returned false, the
			// "form is younger than the window" shortcut could never be taken, and the
			// windowed COUNT ran for every row — both a second query per form and, for
			// any entry whose created_at predates the form's post_date (an import, a
			// migration, a restored backup), a different number than the column shows.
			// The comment below promises order and display can never disagree; passing
			// different arguments here is what made them disagree.
			$post    = get_post( $form_id );
			$metrics = $this->calculate_form_metrics(
				$form_id,
				$post->post_date_gmt ?? '',
				Helper::get_integer_value( Entries::get_total_entries_by_status( 'all', $form_id ) )
			);

			// Same helper the column renders from, so the order always matches the
			// numbers on screen. An unmeasurable rate sorts as -1 rather than 0, so
			// the dash rows group below a genuine 0% instead of tying with it.
			$metric = 'views' === $orderby
				? (float) $metrics['views']
				: ( null === $metrics['conversion_rate'] ? -1.0 : (float) $metrics['conversion_rate'] );

			$rows[] = [
				'id'     => $form_id,
				'metric' => $metric,
			];
		}

		// Sort by metric, tie-break on id (desc) for a stable order.
		$direction = 'asc' === strtolower( $order ) ? 1 : -1;
		usort(
			$rows,
			static function ( $a, $b ) use ( $direction ) {
				if ( $a['metric'] === $b['metric'] ) {
					return $b['id'] <=> $a['id'];
				}
				return ( $a['metric'] <=> $b['metric'] ) * $direction;
			}
		);

		$total       = count( $rows );
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		$offset      = ( $page - 1 ) * $per_page;
		$page_rows   = array_slice( $rows, max( 0, $offset ), $per_page );

		$forms = [];
		foreach ( $page_rows as $row ) {
			$post = get_post( $row['id'] );
			if ( $post instanceof \WP_Post ) {
				$forms[] = $this->prepare_form_for_listing( $post );
			}
		}

		return [
			'forms'        => $forms,
			'total'        => $total,
			'total_pages'  => $total_pages,
			'current_page' => $page,
			'per_page'     => $per_page,
		];
	}

	/**
	 * The tracking-window boundary as a datetime string comparable to `created_at`.
	 *
	 * `created_at` is written by MySQL (`DEFAULT CURRENT_TIMESTAMP`) and compared in
	 * the session time zone, not UTC, so a bare `gmdate()` of a PHP timestamp is off
	 * by the MySQL/PHP clock offset — permanently mis-counting entries near the
	 * boundary. Entries::get_entries_count_after() solves this by reading
	 * `SELECT NOW()`, but it does so on every call, which would be one extra query
	 * per row on a listing page. The offset cannot change within a request, so it is
	 * resolved once and reused.
	 *
	 * @param int $window_start Unix timestamp.
	 * @return string Datetime string in MySQL's frame of reference.
	 * @since x.x.x
	 */
	private static function window_boundary_sql( $window_start ) {
		static $offset_seconds = null;

		if ( null === $offset_seconds ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading the server clock; a cached value would defeat the purpose.
			$mysql_now = $wpdb->get_var( 'SELECT NOW()' );
			$mysql_ts  = $mysql_now ? strtotime( (string) $mysql_now ) : false;

			// Fall back to no adjustment rather than a wild offset if NOW() is
			// unreadable or unparseable — a UTC-configured server needs none anyway.
			$offset_seconds = false === $mysql_ts ? 0 : $mysql_ts - time();
		}

		return gmdate( 'Y-m-d H:i:s', $window_start + $offset_seconds );
	}

	/**
	 * Views and conversion rate for one form.
	 *
	 * The single source of truth for both the rendered value and the sorted metric.
	 * They were computed separately at first, and drifted: the sort used all-time
	 * entries while the column used entries from the tracking window, so a form
	 * rendering a dash sorted as though its rate were several hundred percent.
	 * Anything needing these numbers must come through here.
	 *
	 * Returns `null` for the rate rather than a number whenever it cannot be
	 * measured — tracking off, window never opened, no views yet, or more entries
	 * than views. That last case is not possible in reality (every entry needs a
	 * view first), so it means the view count is incomplete and any percentage
	 * would be invented; the table renders the dash instead. `0.0` is reserved for
	 * a real measurement of zero.
	 *
	 * @param int    $form_id       Form post ID.
	 * @param string $post_date_gmt Form creation date, GMT. Used to skip a redundant count.
	 * @param int    $entries_all_time All-time entry count, when the caller already has it.
	 * @return array{views:int,conversion_rate:float|null}
	 * @since x.x.x
	 */
	private function calculate_form_metrics( $form_id, $post_date_gmt = '', $entries_all_time = null ) {
		$none = [
			'views'           => 0,
			'conversion_rate' => null,
		];

		$window_start = Form_Views::get_instance()->get_tracking_started_at();

		// A zero stamp means counting never started, so there is nothing to divide by
		// and no window to measure against. Guarded as well as the display toggle
		// because the two are written by different paths: the toggle could be forced
		// on by a direct option write that never ran maybe_start_tracking(), and
		// gmdate() on a zero timestamp would silently widen the window to 1970 and
		// count every entry the form has ever had.
		if ( ! Form_Views::get_instance()->is_tracking_enabled() || $window_start <= 0 ) {
			return $none;
		}

		$views = Form_Views::get_instance()->get_views( $form_id );

		if ( $views <= 0 ) {
			return $none;
		}

		// Compare like with like. The Entries column is all-time, but views only start
		// accruing when tracking opens, so the rate counts entries from that same
		// moment — otherwise a form that existed beforehand divides years of entries by
		// days of views and reports a rate that is pure noise.
		$form_created = strtotime( (string) $post_date_gmt );

		if ( null !== $entries_all_time && $form_created && $form_created >= $window_start ) {
			// The form is younger than the window, so every entry it has is already
			// inside the window and the caller's all-time count is the same number.
			// Skips a second COUNT per row on the listing.
			$entries_since = Helper::get_integer_value( $entries_all_time );
		} else {
			$entries_since = Helper::get_integer_value(
				Entries::get_total_entries_by_status(
					'all',
					$form_id,
					[
						[
							[
								'key'     => 'created_at',
								'compare' => '>=',
								'value'   => self::window_boundary_sql( $window_start ),
							],
						],
					]
				)
			);
		}

		if ( $entries_since > $views ) {
			return [
				'views'           => $views,
				'conversion_rate' => null,
			];
		}

		return [
			'views'           => $views,
			'conversion_rate' => round( $entries_since / $views * 100, 1 ),
		];
	}

	/**
	 * Prepare a single form for the listing response.
	 *
	 * @param \WP_Post $post Post object.
	 * @return array<mixed> Prepared form data for listing.
	 * @since 2.0.0
	 */
	private function prepare_form_for_listing( $post ) {
		$form_id = $post->ID;

		// Get entries count.
		$entries_count = Helper::get_integer_value( Entries::get_total_entries_by_status( 'all', $form_id ) );

		// Views and conversion rate come from the same helper the sort path uses, so the
		// column can never order by a different number than it displays.
		$metrics         = $this->calculate_form_metrics( $form_id, $post->post_date_gmt, $entries_count );
		$views           = $metrics['views'];
		$conversion_rate = $metrics['conversion_rate'];

		return [
			'id'              => $form_id,
			'title'           => $post->post_title,
			'status'          => $post->post_status,
			'date_created'    => mysql_to_rfc3339( $post->post_date ),
			'date_modified'   => mysql_to_rfc3339( $post->post_modified ),
			'entries_count'   => $entries_count,
			'views'           => $views,
			'conversion_rate' => $conversion_rate,
			'shortcode'       => "[sureforms id='{$form_id}']",
			'edit_url'        => admin_url( "post.php?post={$form_id}&action=edit" ),
			'frontend_url'    => get_permalink( $form_id ),
		];
	}
}
