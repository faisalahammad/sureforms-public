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
			// Only take the derived-metric path while tracking is on. With the feature
			// off the columns are hidden, so a stored or hand-crafted request to sort by
			// them would run the expensive compute-sort-paginate pass to order rows
			// nobody can see; fall through to the default ordering instead.
			if ( in_array( $orderby, [ 'views', 'conversion_rate' ], true ) && Form_Views::get_instance()->is_tracking_enabled() ) {
				// Derived metrics can't be sorted by WP_Query; compute across all
				// matching forms, sort, then paginate.
				$response_data = $this->get_forms_sorted_by_metric( $args, $orderby, $order, $page, Helper::get_integer_value( $per_page ) );
			} else {
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
	 * @return array<string,mixed> Response payload matching get_forms_list().
	 */
	private function get_forms_sorted_by_metric( $args, $orderby, $order, $page, $per_page ) {
		$id_args                   = $args;
		$id_args['posts_per_page'] = -1;
		$id_args['paged']          = 1;
		$id_args['fields']         = 'ids';
		$id_args['orderby']        = 'ID';
		$id_args['order']          = 'DESC';

		$id_query = new \WP_Query( $id_args );

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
		}

		$rows = [];
		foreach ( $id_query->posts as $post_id ) {
			$form_id = Helper::get_integer_value( $post_id );
			$views   = Form_Views::get_instance()->get_views( $form_id );
			$entries = Helper::get_integer_value( Entries::get_total_entries_by_status( 'all', $form_id ) );
			$metric  = 'views' === $orderby ? (float) $views : ( $views > 0 ? $entries / $views * 100 : 0.0 );

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

		// Views (impressions) and derived conversion rate. Clamp to 100%: entries are
		// all-time while views only accrue from when this feature shipped, so early on a
		// form can have more entries than counted views (which would read >100%).
		//
		// Skipped entirely when tracking is off — the columns are hidden then, so this
		// would be a meta read per form on every listing request for values nothing
		// renders.
		$views = 0;
		// null means "not computable yet", which the table renders as a dash. 0.0 would
		// claim a real measurement of zero.
		$conversion_rate = null;

		$window_start = Form_Views::get_instance()->get_tracking_started_at();

		// A zero stamp means counting never started, so there is nothing to divide by
		// and no window to measure against. Guarded as well as the display toggle
		// because the two are written by different paths: the toggle could be forced
		// on by a direct option write that never ran maybe_start_tracking(), and
		// gmdate() on a zero timestamp would silently widen the window to 1970 and
		// count every entry the form has ever had.
		if ( Form_Views::get_instance()->is_tracking_enabled() && $window_start > 0 ) {
			$views = Form_Views::get_instance()->get_views( $form_id );

			// Compare like with like. The Entries column is all-time, but views only
			// start accruing when tracking opens, so the rate counts entries from that
			// same moment — otherwise a form that existed beforehand divides years of
			// entries by days of views and reports a rate that is pure noise.
			//
			// No clamp to the form's own creation date: a form cannot have entries
			// from before it existed, so `created_at >= window` already excludes them.
			$entries_since = Helper::get_integer_value(
				Entries::get_total_entries_by_status(
					'all',
					$form_id,
					[
						[
							[
								'key'     => 'created_at',
								'compare' => '>=',
								'value'   => gmdate( 'Y-m-d H:i:s', $window_start ),
							],
						],
					]
				)
			);

			// More entries than views is impossible — every entry needs a view first —
			// so the count is incomplete and any percentage would be invented. null tells
			// the table to show its "no data yet" dash rather than a clamped 100%.
			if ( $views > 0 && $entries_since <= $views ) {
				$conversion_rate = round( $entries_since / $views * 100, 1 );
			} elseif ( $views > 0 ) {
				$conversion_rate = null;
			}
		}

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
