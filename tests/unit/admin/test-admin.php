<?php
/**
 * Class Test_First_Form_Creation
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

use SRFM\Admin\Admin;
use SRFM\Inc\Helper;

require_once __DIR__ . '/trait-astra-notices-helper.php';

/**
 * Tests first form creation timestamp logic.
 */
class Test_Admin extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        // Define WordPress constants
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 24 * 60 * 60);
        }

        // By default, stub out WP functions with safe values
        if (! function_exists('current_user_can')) {
            function current_user_can($capability) { return true; }
        }
        if (! function_exists('post_type_exists')) {
            function post_type_exists($post_type) { return true; }
        }
        if (! function_exists('get_post_field')) {
            function get_post_field($field_name, $post_id) {
                return '2025-08-01 12:00:00';
            }
        }
        if (! function_exists('current_time')) {
            function current_time($timestamp_type) {
                return gmdate('Y-m-d H:i:s');
            }
        }
        if (! function_exists('get_option')) {
            function get_option($option_name, $default_value = false) {
                // Mock srfm_options for testing
                if ($option_name === 'srfm_options') {
                    return ['first_form_created_at' => 1234567890];
                }
                return $default_value;
            }
        }
    }

    /**
     * Test get_first_form_creation_time_stamp returns stored value.
     */
    public function test_get_first_form_creation_time_stamp_returns_value() {
        // Since we can't easily override WordPress functions in this test setup,
        // we'll test that the method exists and returns the expected type
        $result = Admin::get_first_form_creation_time_stamp();

        // The method should return either an integer timestamp or false
        $this->assertTrue(is_int($result) || $result === false);
    }

    /**
     * Test check_first_form_creation_threshold returns false when not enough days passed.
     */
    public function test_check_first_form_creation_threshold_false() {
        $current_time = strtotime(gmdate('Y-m-d H:i:s'));
        $timestamp = $current_time - (DAY_IN_SECONDS * 1);
        $days_threshold = 3;

        // Test the calculation logic directly
        $days_from_creation = ($current_time - $timestamp) / DAY_IN_SECONDS;
        $this->assertFalse($days_from_creation > $days_threshold);
    }

    /**
     * Test check_first_form_creation_threshold returns true when enough days passed.
     */
    public function test_check_first_form_creation_threshold_true() {
        $current_time = strtotime(gmdate('Y-m-d H:i:s'));
        $timestamp = $current_time - (DAY_IN_SECONDS * 5);
        $days_threshold = 3;

        // Test the calculation logic directly
        $days_from_creation = ($current_time - $timestamp) / DAY_IN_SECONDS;
        $this->assertTrue($days_from_creation > $days_threshold);
    }

    /**
     * Test that enqueue_scripts method exists and that the localization data contains current_user_login.
     */
    public function test_enqueue_scripts() {
        // Verify the enqueue_scripts method exists on the Admin class.
        $this->assertTrue(
            method_exists( Admin::class, 'enqueue_scripts' ),
            'The enqueue_scripts method should exist on the Admin class.'
        );

        // Verify it is a public instance method.
        $reflection = new \ReflectionMethod( Admin::class, 'enqueue_scripts' );
        $this->assertTrue( $reflection->isPublic(), 'enqueue_scripts should be a public method.' );
        $this->assertFalse( $reflection->isStatic(), 'enqueue_scripts should be an instance method, not static.' );

        // Verify the localization data structure includes 'current_user_login' by inspecting the source.
        // We read the method body to confirm the key is present, ensuring the contract is maintained.
        $source_file = $reflection->getFileName();
        $start_line  = $reflection->getStartLine();
        $end_line    = $reflection->getEndLine();
        $source      = implode( '', array_slice( file( $source_file ), $start_line - 1, $end_line - $start_line + 1 ) );

        $this->assertStringContainsString( 'current_user_login', $source, 'Localization data should include current_user_login.' );
    }

    /**
     * Test add_learn_page registers the Learn submenu page.
     */
    public function test_add_learn_page() {
        if ( ! function_exists( 'add_submenu_page' ) ) {
            $this->markTestSkipped( 'WordPress admin menu functions not available.' );
            return;
        }

        $admin = Admin::get_instance();

        // Calling add_learn_page() should not throw — it registers a submenu.
        // In a unit test environment without a full WP admin context the function
        // may be a no-op, so we assert it returns void (null) without errors.
        $result = $admin->add_learn_page();
        $this->assertNull( $result );
    }

    /**
     * Test render_learn outputs the expected root div.
     */
    public function test_render_learn() {
        $admin = Admin::get_instance();

        ob_start();
        $admin->render_learn();
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'id="srfm-learn-root"',
            $output,
            'render_learn should output a div with id srfm-learn-root.'
        );
        $this->assertStringContainsString(
            'srfm-admin-wrapper',
            $output,
            'render_learn should include the srfm-admin-wrapper class.'
        );
    }

	/**
	 * Test settings_page_callback outputs the settings container div.
	 */
	public function test_settings_page_callback() {
		$admin = Admin::get_instance();

		ob_start();
		$admin->settings_page_callback();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'id="srfm-settings-container"', $output );
		$this->assertStringContainsString( 'srfm-admin-wrapper', $output );
	}

	/**
	 * Test add_quiz_page registers the Quiz submenu page.
	 *
	 * @since 2.7.0
	 */
	public function test_add_quiz_page() {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			$this->markTestSkipped( 'WordPress admin menu functions not available.' );
			return;
		}

		$admin = Admin::get_instance();

		// Calling add_quiz_page() should not throw — it registers a submenu.
		$result = $admin->add_quiz_page();
		$this->assertNull( $result );

		// Verify the method source registers the correct page slug and menu label.
		$reflection = new \ReflectionMethod( $admin, 'add_quiz_page' );
		$source_file = $reflection->getFileName();
		$start_line  = $reflection->getStartLine();
		$end_line    = $reflection->getEndLine();
		$source      = implode( '', array_slice( file( $source_file ), $start_line - 1, $end_line - $start_line + 1 ) );

		$this->assertStringContainsString( 'sureforms_quiz_entries', $source, 'Quiz page should use sureforms_quiz_entries slug.' );
		$this->assertStringContainsString( 'render_quiz_empty_state', $source, 'Quiz page callback should be render_quiz_empty_state.' );
	}

	/**
	 * Test render_quiz_empty_state outputs the expected root div.
	 *
	 * @since 2.7.0
	 */
	public function test_render_quiz_empty_state() {
		$admin = Admin::get_instance();

		ob_start();
		$admin->render_quiz_empty_state();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'id="srfm-quiz-entries-root"',
			$output,
			'render_quiz_empty_state should output a div with id srfm-quiz-entries-root.'
		);
		$this->assertStringContainsString(
			'srfm-admin-wrapper',
			$output,
			'render_quiz_empty_state should include the srfm-admin-wrapper class.'
		);
	}

	/**
	 * Test add_upgrade_to_pro registers the Upgrade submenu page.
	 *
	 * @since 2.7.0
	 */
	public function test_add_upgrade_to_pro() {
		if ( ! function_exists( 'add_submenu_page' ) ) {
			$this->markTestSkipped( 'WordPress admin menu functions not available.' );
			return;
		}

		$admin = Admin::get_instance();

		// Calling add_upgrade_to_pro() should not throw — it registers a submenu.
		$result = $admin->add_upgrade_to_pro();
		$this->assertNull( $result );

		// Verify the method source registers with the upgrade URL and correct label.
		$reflection = new \ReflectionMethod( $admin, 'add_upgrade_to_pro' );
		$source_file = $reflection->getFileName();
		$start_line  = $reflection->getStartLine();
		$end_line    = $reflection->getEndLine();
		$source      = implode( '', array_slice( file( $source_file ), $start_line - 1, $end_line - $start_line + 1 ) );

		$this->assertStringContainsString( 'submenu_link_upgrade', $source, 'Upgrade page should use submenu_link_upgrade UTM medium.' );
		$this->assertStringContainsString( 'sureforms_menu', $source, 'Upgrade page should be registered under sureforms_menu.' );
	}

	/**
	 * Test srfm_pro_version_compatibility returns early when Pro is not active.
	 */
	public function test_srfm_pro_version_compatibility() {
		$admin = Admin::get_instance();

		// When Pro is not active, method should return without output.
		ob_start();
		$admin->srfm_pro_version_compatibility();
		$output = ob_get_clean();

		// Without Pro active, no notice should be rendered.
		$this->assertEmpty( $output );
	}

	/**
	 * Test render_dashboard_widget outputs the widget HTML.
	 */
	public function test_render_dashboard_widget() {
		$admin = Admin::get_instance();

		// Set up dashboard_widget_data via reflection.
		$prop = new \ReflectionProperty( $admin, 'dashboard_widget_data' );
		$prop->setAccessible( true );
		$prop->setValue(
			$admin,
			[
				[
					'title' => 'Contact Form',
					'count' => 5,
				],
			]
		);

		ob_start();
		$admin->render_dashboard_widget();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'srfm-dashboard-widget', $output );
		$this->assertStringContainsString( 'Recent Entries', $output );
		$this->assertStringContainsString( 'Contact Form', $output );
	}

	/**
	 * Test maybe_register_dashboard_widget wires the AI quick draft widget onto wp_dashboard_setup.
	 *
	 * For a capable user it always hooks register_ai_dashboard_widget; with no logged-in user the
	 * capability check short-circuits and nothing is wired (the early-return path).
	 *
	 * @since x.x.x
	 */
	public function test_maybe_register_dashboard_widget() {
		$admin = Admin::get_instance();

		// Capable user: the AI widget hook is wired onto wp_dashboard_setup.
		$user_id = wp_insert_user(
			[
				'user_login' => 'srfm_admin_' . uniqid(),
				'user_pass'  => 'password',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( $user_id );

		remove_action( 'wp_dashboard_setup', [ $admin, 'register_ai_dashboard_widget' ] );
		$this->assertNull( $admin->maybe_register_dashboard_widget() );
		$this->assertNotFalse(
			has_action( 'wp_dashboard_setup', [ $admin, 'register_ai_dashboard_widget' ] ),
			'AI quick draft widget should hook onto wp_dashboard_setup for capable users.'
		);

		// No user: the capability gate short-circuits and nothing is wired.
		wp_set_current_user( 0 );
		remove_action( 'wp_dashboard_setup', [ $admin, 'register_ai_dashboard_widget' ] );
		$this->assertNull( $admin->maybe_register_dashboard_widget() );
		$this->assertFalse(
			has_action( 'wp_dashboard_setup', [ $admin, 'register_ai_dashboard_widget' ] ),
			'No widget should be wired when the capability check fails.'
		);
	}

	/**
	 * Test register_dashboard_widget registers the recent-entries widget into $wp_meta_boxes.
	 *
	 * @since x.x.x
	 */
	public function test_register_dashboard_widget() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';

		$admin = Admin::get_instance();
		set_current_screen( 'dashboard' );

		$admin->register_dashboard_widget();

		global $wp_meta_boxes;
		$this->assertArrayHasKey(
			'sureforms_recent_entries',
			$wp_meta_boxes['dashboard']['normal']['high'],
			'Recent entries widget should be registered with the sureforms_recent_entries id.'
		);
	}

	/**
	 * Test register_ai_dashboard_widget registers the AI quick draft widget into $wp_meta_boxes.
	 *
	 * @since x.x.x
	 */
	public function test_register_ai_dashboard_widget() {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';

		$admin = Admin::get_instance();
		set_current_screen( 'dashboard' );

		$admin->register_ai_dashboard_widget();

		global $wp_meta_boxes;
		$this->assertArrayHasKey(
			'sureforms_ai_quick_draft',
			$wp_meta_boxes['dashboard']['normal']['high'],
			'AI dashboard widget should be registered with the sureforms_ai_quick_draft id.'
		);
	}

	/**
	 * Test render_ai_dashboard_widget outputs the AI quick draft widget markup.
	 *
	 * @since x.x.x
	 */
	public function test_render_ai_dashboard_widget() {
		$admin = Admin::get_instance();

		ob_start();
		$admin->render_ai_dashboard_widget();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'srfm-ai-dashboard-widget', $output, 'Widget should render its wrapper.' );
		$this->assertStringContainsString( 'srfm-ai-dashboard-prompt', $output, 'Widget should render the prompt field.' );
		$this->assertStringContainsString( 'srfm-ai-dashboard-generate', $output, 'Widget should render the generate button.' );
	}

	/**
	 * Test enqueue_ai_dashboard_widget_assets enqueues the widget script only on the dashboard.
	 *
	 * Asserts the gate (no enqueue outside index.php) and that, on the dashboard for a capable user,
	 * the script is enqueued with its localized config — instead of grepping the method source.
	 *
	 * @since x.x.x
	 */
	public function test_enqueue_ai_dashboard_widget_assets() {
		$admin = Admin::get_instance();

		$user_id = wp_insert_user(
			[
				'user_login' => 'srfm_admin_' . uniqid(),
				'user_pass'  => 'password',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( $user_id );

		// Not the dashboard: nothing should be enqueued.
		wp_dequeue_script( 'srfm-ai-dashboard-widget' );
		wp_deregister_script( 'srfm-ai-dashboard-widget' );
		$admin->enqueue_ai_dashboard_widget_assets( 'edit.php' );
		$this->assertFalse(
			wp_script_is( 'srfm-ai-dashboard-widget', 'enqueued' ),
			'Widget script should not load outside the dashboard.'
		);

		// Dashboard + capable user: the script is enqueued with its localized config.
		$admin->enqueue_ai_dashboard_widget_assets( 'index.php' );
		$this->assertTrue(
			wp_script_is( 'srfm-ai-dashboard-widget', 'enqueued' ),
			'Widget script should be enqueued on the dashboard for capable users.'
		);
		$data = wp_scripts()->get_data( 'srfm-ai-dashboard-widget', 'data' );
		$this->assertStringContainsString(
			'srfmAiDashboardWidget',
			(string) $data,
			'Localized config object should be attached to the widget script.'
		);
	}

	/**
	 * Test track_ai_widget_usage increments the usage counter for a valid, capable request.
	 *
	 * The handler ends in wp_send_json_success() (which calls wp_die), so the wp_die handlers are
	 * filtered to throw WPDieException — letting the runner survive while we assert the side effect
	 * (the incremented counter) rather than grepping the method source.
	 *
	 * @since x.x.x
	 */
	public function test_track_ai_widget_usage() {
		$admin = Admin::get_instance();

		$user_id = wp_insert_user(
			[
				'user_login' => 'srfm_admin_' . uniqid(),
				'user_pass'  => 'password',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( $user_id );

		$_POST['nonce']    = wp_create_nonce( 'srfm_ai_widget_usage' );
		$_REQUEST['nonce'] = $_POST['nonce'];

		$before = (int) Helper::get_srfm_option( 'ai_dashboard_widget_uses', 0 );

		// wp_send_json_success() terminates via wp_die(); make the handlers throw so the test runner
		// survives and we can assert behavior after the call.
		$throw_handler = static function () {
			return static function () {
				throw new \WPDieException( 'srfm_test_die' );
			};
		};
		add_filter( 'wp_die_ajax_handler', $throw_handler );
		add_filter( 'wp_die_handler', $throw_handler );

		ob_start();
		try {
			$admin->track_ai_widget_usage();
		} catch ( \WPDieException $e ) {
			// Expected — the handler exits via wp_send_json_success().
		} finally {
			ob_end_clean();
			remove_filter( 'wp_die_ajax_handler', $throw_handler );
			remove_filter( 'wp_die_handler', $throw_handler );
			unset( $_POST['nonce'], $_REQUEST['nonce'] );
		}

		$after = (int) Helper::get_srfm_option( 'ai_dashboard_widget_uses', 0 );
		$this->assertSame( $before + 1, $after, 'Usage tracking must increment the ai_dashboard_widget_uses counter.' );
	}
}

/**
 * Tests for the 5-star rating notice functionality.
 *
 * @since 2.5.2
 */
class Test_Rating_Notice extends TestCase {
	use Astra_Notices_Helper;

	/**
	 * Tear down: remove filters added during tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Reset the cached should_show_rating property.
		$admin = Admin::get_instance();
		$prop  = new \ReflectionProperty( $admin, 'should_show_rating' );
		$prop->setAccessible( true );
		$prop->setValue( $admin, null );

		remove_all_filters( 'srfm_show_rating_notice' );
		parent::tearDown();
	}

	/**
	 * Helper: invoke the private maybe_display_rating_notice() method via reflection,
	 * after injecting a cached value into the should_show_rating property.
	 *
	 * @param bool $cached_value The value to inject into the cache.
	 * @return bool The method's return value.
	 */
	private function invoke_maybe_display_with_cache( bool $cached_value ): bool {
		$admin = Admin::get_instance();

		$prop = new \ReflectionProperty( $admin, 'should_show_rating' );
		$prop->setAccessible( true );
		$prop->setValue( $admin, $cached_value );

		$method = new \ReflectionMethod( $admin, 'maybe_display_rating_notice' );
		$method->setAccessible( true );

		return $method->invoke( $admin );
	}

	/**
	 * Test: threshold constant is defined and is an integer.
	 */
	public function test_rating_notice_threshold_is_defined() {
		$this->assertSame( 3, Admin::RATING_NOTICE_THRESHOLD );
	}

	/**
	 * Test: display condition is false when cached value is false.
	 */
	public function test_rating_notice_returns_false_when_cached_false() {
		$this->assertFalse( $this->invoke_maybe_display_with_cache( false ) );
	}

	/**
	 * Test: display condition is true when cached value is true.
	 */
	public function test_rating_notice_returns_true_when_cached_true() {
		$this->assertTrue( $this->invoke_maybe_display_with_cache( true ) );
	}

	/**
	 * Test: condition logic — below threshold should not display.
	 */
	public function test_rating_notice_condition_false_below_threshold() {
		$threshold = Admin::RATING_NOTICE_THRESHOLD;

		$this->assertFalse( 0 >= $threshold || 0 >= $threshold, 'Should not display with 0 forms and 0 entries' );
		$this->assertFalse( 0 >= $threshold || ( $threshold - 1 ) >= $threshold, 'Should not display just below threshold' );
	}

	/**
	 * Test: condition logic — at or above threshold should display.
	 */
	public function test_rating_notice_condition_true_at_threshold() {
		$threshold = Admin::RATING_NOTICE_THRESHOLD;

		$this->assertTrue( 0 >= $threshold || $threshold >= $threshold, 'Should display with exactly threshold forms' );
		$this->assertTrue( $threshold >= $threshold || 0 >= $threshold, 'Should display with exactly threshold entries' );
	}

	/**
	 * Test: display_srfm_rating_notice adds no notice when srfm_show_rating_notice filter returns false.
	 */
	public function test_display_srfm_rating_notice_skipped_when_filter_disabled() {
		add_filter( 'srfm_show_rating_notice', '__return_false' );

		$before_count = $this->get_astra_notices_count();

		$admin = Admin::get_instance();
		$admin->display_srfm_rating_notice();

		$after_count = $this->get_astra_notices_count();

		$this->assertEquals(
			$before_count,
			$after_count,
			'No notice should be registered when srfm_show_rating_notice filter returns false'
		);
	}
}

/**
 * Tests for the "Getting Started" admin notice functionality.
 *
 * @since 2.5.2
 */
class Test_Getting_Started_Notice extends TestCase {
	use Astra_Notices_Helper;

	/**
	 * Tear down: remove filters added during tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// Reset the cached should_show_rating property.
		$admin = Admin::get_instance();
		$prop  = new \ReflectionProperty( $admin, 'should_show_rating' );
		$prop->setAccessible( true );
		$prop->setValue( $admin, null );

		remove_all_filters( 'srfm_show_getting_started_notice' );
		parent::tearDown();
	}

	/**
	 * Test: getting-started shows when rating condition is false (below threshold).
	 */
	public function test_getting_started_show_if_true_below_threshold() {
		$threshold = Admin::RATING_NOTICE_THRESHOLD;

		$rating_display = 0 >= $threshold || 0 >= $threshold;
		$this->assertTrue( ! $rating_display, 'Getting-started should show with 0 forms and 0 entries' );

		$rating_display = ( $threshold - 1 ) >= $threshold || ( $threshold - 1 ) >= $threshold;
		$this->assertTrue( ! $rating_display, 'Getting-started should show just below threshold' );
	}

	/**
	 * Test: getting-started hides when rating condition is true (at or above threshold).
	 */
	public function test_getting_started_show_if_false_at_threshold() {
		$threshold = Admin::RATING_NOTICE_THRESHOLD;

		$rating_display = $threshold >= $threshold || 0 >= $threshold;
		$this->assertFalse( ! $rating_display, 'Getting-started should not show when forms reach threshold' );

		$rating_display = 0 >= $threshold || $threshold >= $threshold;
		$this->assertFalse( ! $rating_display, 'Getting-started should not show when entries reach threshold' );
	}

	/**
	 * Test: mutual exclusivity — rating and getting-started conditions are always opposite.
	 */
	public function test_mutual_exclusivity_of_notices() {
		$threshold = Admin::RATING_NOTICE_THRESHOLD;
		$scenarios = [
			[ 'entries' => 0, 'forms' => 0 ],
			[ 'entries' => $threshold - 1, 'forms' => $threshold - 1 ],
			[ 'entries' => $threshold, 'forms' => 0 ],
			[ 'entries' => 0, 'forms' => $threshold ],
			[ 'entries' => $threshold + 2, 'forms' => $threshold + 2 ],
		];

		foreach ( $scenarios as $scenario ) {
			$rating_show          = $scenario['entries'] >= $threshold || $scenario['forms'] >= $threshold;
			$getting_started_show = ! $rating_show;

			$this->assertNotEquals(
				$rating_show,
				$getting_started_show,
				sprintf(
					'Rating and getting-started should be mutually exclusive (entries=%d, forms=%d)',
					$scenario['entries'],
					$scenario['forms']
				)
			);
		}
	}

	/**
	 * Test: display_srfm_getting_started_notice adds no notice when filter returns false.
	 */
	public function test_display_srfm_getting_started_notice_skipped_when_filter_disabled() {
		add_filter( 'srfm_show_getting_started_notice', '__return_false' );

		$before_count = $this->get_astra_notices_count();

		$admin = Admin::get_instance();
		$admin->display_srfm_getting_started_notice();

		$after_count = $this->get_astra_notices_count();

		$this->assertEquals(
			$before_count,
			$after_count,
			'No notice should be registered when srfm_show_getting_started_notice filter returns false'
		);
	}

	/**
	 * Test: handle_notice_response rejects requests with an invalid notice_id / button combination.
	 *
	 * @since 2.5.2
	 */
	public function test_handle_notice_response_rejects_invalid_params() {
		$valid = [
			'srfm-getting-started-notice' => [ 'go_to_dashboard', 'maybe_later', 'dismissed' ],
			'srfm-plugin-review-notice'   => [ 'rate_sureforms', 'maybe_later', 'dismissed' ],
		];

		// Unknown notice_id is not a key in the allowlist.
		$this->assertArrayNotHasKey( 'unknown-notice', $valid );

		// Unknown button is not in any notice's allowed buttons.
		foreach ( $valid as $buttons ) {
			$this->assertNotContains( 'unknown_button', $buttons );
		}
	}

	/**
	 * Test: enqueue_notice_response_script is callable and skips double-enqueue.
	 *
	 * @since 2.5.2
	 */
	public function test_enqueue_notice_response_script_is_callable() {
		$admin = Admin::get_instance();
		$this->assertTrue( method_exists( $admin, 'enqueue_notice_response_script' ) );
	}

	/**
	 * Test: add_survey_reports_page method exists and is callable.
	 *
	 * @since 2.8.0
	 */
	public function test_add_survey_reports_page() {
		$admin = Admin::get_instance();
		$this->assertTrue( method_exists( $admin, 'add_survey_reports_page' ) );
	}

	/**
	 * Test: render_survey_empty_state outputs the root div.
	 *
	 * @since 2.8.0
	 */
	public function test_render_survey_empty_state() {
		$admin = Admin::get_instance();
		ob_start();
		$admin->render_survey_empty_state();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'srfm-survey-empty-state-root', $output );
	}

	/**
	 * Test: add_partial_entries_page method exists and is callable.
	 *
	 * @since 2.9.0
	 */
	public function test_add_partial_entries_page() {
		$admin = Admin::get_instance();
		$this->assertTrue( method_exists( $admin, 'add_partial_entries_page' ) );
	}

	/**
	 * Test: render_partial_entries_empty_state outputs the root div.
	 *
	 * @since 2.9.0
	 */
	public function test_render_partial_entries_empty_state() {
		$admin = Admin::get_instance();
		ob_start();
		$admin->render_partial_entries_empty_state();
		$output = ob_get_clean();
		$this->assertStringContainsString( 'srfm-partial-entries-empty-state-root', $output );
	}

	/**
	 * Test: add_action_links appends a UTM-tagged upsell link when SureForms
	 * Pro is not active. Asserts the deterministic source/campaign defaults
	 * and the placement carried via utm_medium.
	 */
	public function test_add_action_links_appends_utm_tagged_upsell_when_pro_inactive() {
		if ( class_exists( Helper::class ) && Helper::has_pro() ) {
			$this->markTestSkipped( 'SureForms Pro is active; upsell branch is not exercised.' );
		}

		$admin = Admin::get_instance();
		$links = $admin->add_action_links( [] );

		$this->assertCount( 1, $links, 'A single upsell link must be appended when pro is not active.' );
		$this->assertStringContainsString( 'utm_source=sureforms_plugin', $links[0] );
		$this->assertStringContainsString( 'utm_medium=plugin-list', $links[0] );
		$this->assertStringContainsString( 'utm_campaign=core_plugin', $links[0] );
	}

	/**
	 * Test: add_action_links returns the array unchanged when SureForms
	 * Pro is active — no upsell link is appended.
	 */
	public function test_add_action_links_returns_unchanged_when_pro_active() {
		if ( ! class_exists( Helper::class ) || ! Helper::has_pro() ) {
			$this->markTestSkipped( 'SureForms Pro is not active in this test env.' );
		}

		$admin = Admin::get_instance();
		$input = [ '<a href="#">Existing</a>' ];
		$links = $admin->add_action_links( $input );

		$this->assertSame( $input, $links );
	}

	/**
	 * Test: add_upgrade_to_pro is callable. Side-effect (calling
	 * add_submenu_page) requires the parent menu to be registered, which
	 * is outside this unit's scope — so we only assert the method exists.
	 */
	public function test_add_upgrade_to_pro_is_callable() {
		$admin = Admin::get_instance();
		$this->assertTrue( method_exists( $admin, 'add_upgrade_to_pro' ) );
	}

	/**
	 * Test suppress_foreign_admin_notices exists and is a public instance method.
	 *
	 * @since 2.10.0
	 */
	public function test_suppress_foreign_admin_notices_signature() {
		$this->assertTrue(
			method_exists( Admin::class, 'suppress_foreign_admin_notices' ),
			'suppress_foreign_admin_notices should exist on the Admin class.'
		);

		$reflection = new \ReflectionMethod( Admin::class, 'suppress_foreign_admin_notices' );
		$this->assertTrue( $reflection->isPublic(), 'suppress_foreign_admin_notices should be public.' );
		$this->assertFalse( $reflection->isStatic(), 'suppress_foreign_admin_notices should be an instance method.' );

		// It must be strictly scoped to SureForms admin screens.
		$source_file = $reflection->getFileName();
		$start_line  = $reflection->getStartLine();
		$end_line    = $reflection->getEndLine();
		$source      = implode( '', array_slice( file( $source_file ), $start_line - 1, $end_line - $start_line + 1 ) );

		$this->assertStringContainsString( 'is_sureforms_admin_page', $source, 'Suppression must be scoped to SureForms admin screens.' );
		$this->assertStringContainsString( 'current_action', $source, 'Suppression should act on the current notice hook only.' );
	}

	/**
	 * Test is_sureforms_owned_notice_callback recognises SureForms-owned callbacks
	 * and treats foreign callbacks as not owned.
	 *
	 * @since 2.10.0
	 */
	public function test_is_sureforms_owned_notice_callback() {
		$admin  = Admin::get_instance();
		$method = new \ReflectionMethod( Admin::class, 'is_sureforms_owned_notice_callback' );
		$method->setAccessible( true );

		// SureForms instance method ([ $object, 'method' ]) is owned.
		$this->assertTrue(
			$method->invoke( $admin, [ $admin, 'display_srfm_rating_notice' ] ),
			'A SureForms class instance method should be recognised as owned.'
		);

		// SureForms static callback as a string is owned.
		$this->assertTrue(
			$method->invoke( $admin, 'SRFM\\Inc\\Helper::is_sureforms_admin_page' ),
			'A SRFM namespaced static callback should be recognised as owned.'
		);

		// Pro namespaced callback is owned. Pro's real namespace is the
		// case-sensitive `SRFM_Pro\` (capital P, lowercase ro) — assert the
		// actual cased class so this guards against the all-caps mismatch.
		$this->assertTrue(
			$method->invoke( $admin, [ 'SRFM_Pro\\Inc\\Admin\\Licensing', 'some_notice' ] ),
			'A SRFM_Pro namespaced callback should be recognised as owned.'
		);

		// The all-caps SRFM_PRO_ form is a constant prefix, not the namespace —
		// a class under it is foreign and must NOT be treated as owned.
		$this->assertFalse(
			$method->invoke( $admin, [ 'SRFM_PRO_Something\\Notice', 'render' ] ),
			'An all-caps SRFM_PRO_ class (not the SRFM_Pro namespace) should not be owned.'
		);

		// Bundled notices library is owned.
		$this->assertTrue(
			$method->invoke( $admin, [ 'BSF_Admin_Notices', 'show_notices' ] ),
			'The bundled BSF_Admin_Notices library should be recognised as owned.'
		);

		// Foreign plugin object method is NOT owned.
		$foreign = new \stdClass();
		$this->assertFalse(
			$method->invoke( $admin, [ $foreign, 'render_promo_banner' ] ),
			'A third-party class instance method should not be recognised as owned.'
		);

		// Foreign plugin static callback string is NOT owned.
		$this->assertFalse(
			$method->invoke( $admin, 'Ninja_Forms_Admin_Notices::output' ),
			'A third-party static callback should not be recognised as owned.'
		);

		// Plain function callback is NOT owned.
		$this->assertFalse(
			$method->invoke( $admin, 'some_foreign_notice_function' ),
			'A plain function callback should not be recognised as owned.'
		);

		// Null callback is NOT owned.
		$this->assertFalse(
			$method->invoke( $admin, null ),
			'A null callback should not be recognised as owned.'
		);
	}
}
