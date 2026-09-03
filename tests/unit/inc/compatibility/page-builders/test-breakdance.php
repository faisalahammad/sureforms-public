<?php
/**
 * Class Test_Breakdance
 *
 * @package sureforms
 */

use SRFM\Inc\Compatibility\Page_Builders\Breakdance;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Breakdance renders the whole page through its own template_include filter and
 * discards nothing; SureForms then replaces the template and discards all of it,
 * including the wp_head() call that printed every stylesheet. These tests cover
 * standing that filter down on a single form, and leaving it alone everywhere
 * else.
 */
class Test_Breakdance extends TestCase {

	/**
	 * Breakdance's callback and priority, exactly as it registers them.
	 *
	 * @var string
	 */
	private const CALLBACK = 'Breakdance\ActionsFilters\template_include';

	/**
	 * @var int
	 */
	private const PRIORITY = 1000000;

	/**
	 * Register a stand-in for Breakdance's filter.
	 *
	 * add_filter() takes the callback as a string and never resolves it, so this
	 * exercises the real registration without Breakdance installed. The name and
	 * priority have to match what Breakdance uses, which is the whole point: a
	 * typo in either makes remove_filter() a no-op, and the page renders unstyled
	 * with nothing in any log.
	 */
	private function register_breakdance_filter() {
		add_filter( 'template_include', self::CALLBACK, self::PRIORITY );
	}

	/**
	 * Query state to put back, so one test cannot leak a view into the next.
	 *
	 * @var array<string,mixed>
	 */
	private $previous_query = [];

	/**
	 * Put the main query on a singular view of the given post.
	 *
	 * go_to() belongs to WP_UnitTestCase and this suite uses Yoast's TestCase, so
	 * the query globals are driven directly -- the same approach as
	 * Test_Generate_Form_Markup.
	 *
	 * @param int $post_id Post to be the queried object.
	 */
	private function make_singular( $post_id ) {
		global $wp_query;

		$this->previous_query = [
			'is_singular'       => $wp_query->is_singular,
			'queried_object'    => $wp_query->get_queried_object(),
			'queried_object_id' => $wp_query->get_queried_object_id(),
		];

		$wp_query->is_singular       = true;
		$wp_query->queried_object    = get_post( $post_id );
		$wp_query->queried_object_id = $post_id;
	}

	/**
	 * Clean up whatever a test registered.
	 */
	protected function tear_down(): void {
		remove_filter( 'template_include', self::CALLBACK, self::PRIORITY );

		if ( ! empty( $this->previous_query ) ) {
			global $wp_query;
			$wp_query->is_singular       = $this->previous_query['is_singular'];
			$wp_query->queried_object    = $this->previous_query['queried_object'];
			$wp_query->queried_object_id = $this->previous_query['queried_object_id'];
			$this->previous_query        = [];
		}

		parent::tear_down();
	}

	/**
	 * On a single form, Breakdance's filter is taken out of the chain.
	 */
	public function test_stand_down_on_instant_form() {
		$form_id = wp_insert_post(
			[
				'post_title'  => 'Breakdance Styling Form',
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_status' => 'publish',
			]
		);

		$this->make_singular( $form_id );
		$this->assertTrue( is_singular( SRFM_FORMS_POST_TYPE ), 'Test setup: the query must be a single form.' );

		$this->register_breakdance_filter();
		$this->assertNotFalse(
			has_filter( 'template_include', self::CALLBACK ),
			'Test setup: the stand-in filter must be registered before standing it down.'
		);

		Breakdance::get_instance()->stand_down_on_instant_form();

		$this->assertFalse(
			has_filter( 'template_include', self::CALLBACK ),
			'Breakdance must not buffer a page SureForms is about to replace.'
		);

		wp_delete_post( $form_id, true );
	}

	/**
	 * Everywhere else, Breakdance is left completely alone.
	 *
	 * Breakdance is how the rest of the site is built. Removing its renderer on a
	 * page it owns would take the entire layout down, which is far worse than the
	 * bug being fixed -- so this asserts the negative as carefully as the positive.
	 */
	public function test_stand_down_on_instant_form_leaves_other_pages_alone() {
		$page_id = wp_insert_post(
			[
				'post_title'  => 'An Ordinary Page',
				'post_type'   => 'page',
				'post_status' => 'publish',
			]
		);

		$this->make_singular( $page_id );
		$this->assertFalse( is_singular( SRFM_FORMS_POST_TYPE ), 'Test setup: this must not be a form page.' );

		$this->register_breakdance_filter();

		Breakdance::get_instance()->stand_down_on_instant_form();

		$this->assertNotFalse(
			has_filter( 'template_include', self::CALLBACK ),
			'Breakdance must keep rendering every page it owns.'
		);

		wp_delete_post( $page_id, true );
	}

	/**
	 * With Breakdance absent, the hook is left exactly as it was.
	 *
	 * The overwhelming majority of installs have no Breakdance at all, so the
	 * common path must touch nothing.
	 */
	public function test_stand_down_on_instant_form_does_nothing_without_breakdance() {
		$form_id = wp_insert_post(
			[
				'post_title'  => 'Form Without Breakdance',
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_status' => 'publish',
			]
		);

		$this->make_singular( $form_id );

		$before = has_filter( 'template_include' );

		Breakdance::get_instance()->stand_down_on_instant_form();

		$this->assertSame(
			$before,
			has_filter( 'template_include' ),
			'Nothing on template_include may change when Breakdance is not installed.'
		);

		wp_delete_post( $form_id, true );
	}
}
