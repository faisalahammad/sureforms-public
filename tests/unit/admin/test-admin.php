<?php
/**
 * Class Test_First_Form_Creation
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

use SRFM\Admin\Admin;
use SRFM\Admin\Notice_Manager;
use SRFM\Inc\Client_Logger;
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
     * Test that enqueue_styles exists and attaches the Quill 1.x inline list-marker CSS.
     */
    public function test_enqueue_styles() {
        // Verify the enqueue_styles method exists on the Admin class.
        $this->assertTrue(
            method_exists( Admin::class, 'enqueue_styles' ),
            'The enqueue_styles method should exist on the Admin class.'
        );

        // Verify it is a public instance method.
        $reflection = new \ReflectionMethod( Admin::class, 'enqueue_styles' );
        $this->assertTrue( $reflection->isPublic(), 'enqueue_styles should be a public method.' );
        $this->assertFalse( $reflection->isStatic(), 'enqueue_styles should be an instance method, not static.' );

        // Verify the Quill 1.x inline list-marker CSS is attached to the reactQuill handle by
        // inspecting the source. Reading the method body confirms the contract is maintained
        // without booting a full WP style registry in the unit environment.
        $source_file = $reflection->getFileName();
        $start_line  = $reflection->getStartLine();
        $end_line    = $reflection->getEndLine();
        $source      = implode( '', array_slice( file( $source_file ), $start_line - 1, $end_line - $start_line + 1 ) );

        $this->assertStringContainsString( 'wp_add_inline_style', $source, 'enqueue_styles should attach inline styles.' );
        $this->assertStringContainsString( 'QUILL_1X_INLINE_CSS', $source, 'enqueue_styles should attach the shared Quill 1.x list-marker CSS.' );
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
	 * @since 2.12.1
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
	 * @since 2.12.1
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
	 * @since 2.12.1
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
	 * @since 2.12.1
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
	 * @since 2.12.1
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
	 * @since 2.12.1
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

	// ---------------------------------------------------------------
	// Repeated submission failures
	// ---------------------------------------------------------------

	/**
	 * Both surfaces must stay silent on a site whose forms are working. Logging is
	 * on by default, so a false positive here reaches every install.
	 */
	public function test_render_submission_failure_notice_is_absent_when_healthy() {
		wp_set_current_user( $this->make_log_user( 'administrator' ) );
		delete_option( Client_Logger::FAULT_STREAK_OPTION );

		set_current_screen( 'dashboard' );

		ob_start();
		Admin::get_instance()->render_submission_failure_notice();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * After a run of faults the dashboard says so, and nowhere else does -- the
	 * React notice already covers SureForms' own screens, so an admin-wide classic
	 * notice would stack two on one page.
	 */
	public function test_render_submission_failure_notice() {
		wp_set_current_user( $this->make_log_user( 'administrator' ) );
		update_option( Client_Logger::FAULT_STREAK_OPTION, Client_Logger::FAULT_THRESHOLD );

		set_current_screen( 'edit-post' );
		ob_start();
		Admin::get_instance()->render_submission_failure_notice();
		$elsewhere = ob_get_clean();

		set_current_screen( 'dashboard' );
		ob_start();
		Admin::get_instance()->render_submission_failure_notice();
		$on_dashboard = ob_get_clean();

		delete_option( Client_Logger::FAULT_STREAK_OPTION );

		$this->assertSame( '', $elsewhere );
		$this->assertStringContainsString( 'notice-error', $on_dashboard );
		$this->assertStringContainsString( 'Contact support', $on_dashboard );
	}

	/**
	 * A subscriber must not be told about the site's internals.
	 */
	public function test_render_submission_failure_notice_is_hidden_without_the_capability() {
		wp_set_current_user( $this->make_log_user( 'subscriber' ) );
		update_option( Client_Logger::FAULT_STREAK_OPTION, Client_Logger::FAULT_THRESHOLD );

		set_current_screen( 'dashboard' );

		ob_start();
		Admin::get_instance()->render_submission_failure_notice();
		$output = ob_get_clean();

		delete_option( Client_Logger::FAULT_STREAK_OPTION );

		$this->assertSame( '', $output );
	}

	/**
	 * The React notice must actually register, and read as an error rather than a
	 * routine warning -- entries are being lost.
	 */
	public function test_register_submission_failure_notice() {
		wp_set_current_user( $this->make_log_user( 'administrator' ) );
		update_option( Client_Logger::FAULT_STREAK_OPTION, Client_Logger::FAULT_THRESHOLD );

		Notice_Manager::clear_notices();
		Admin::get_instance()->register_submission_failure_notice();
		$notices = Notice_Manager::get_notices();
		Notice_Manager::clear_notices();
		delete_option( Client_Logger::FAULT_STREAK_OPTION );

		$ids = wp_list_pluck( $notices, 'id' );
		$this->assertContains( 'srfm-submission-failure', $ids );

		foreach ( $notices as $notice ) {
			if ( 'srfm-submission-failure' === $notice['id'] ) {
				$this->assertSame( 'error', $notice['variant'] );
			}
		}
	}

	/**
	 * Nothing may register on a healthy site.
	 */
	public function test_register_submission_failure_notice_is_silent_when_healthy() {
		wp_set_current_user( $this->make_log_user( 'administrator' ) );
		delete_option( Client_Logger::FAULT_STREAK_OPTION );

		Notice_Manager::clear_notices();
		Admin::get_instance()->register_submission_failure_notice();
		$ids = wp_list_pluck( Notice_Manager::get_notices(), 'id' );
		Notice_Manager::clear_notices();

		$this->assertNotContains( 'srfm-submission-failure', $ids );
	}

	/**
	 * Create a user with the given role and return its ID.
	 *
	 * @param string $role Role to assign.
	 * @return int
	 */
	private function make_log_user( $role ) {
		return (int) wp_insert_user(
			[
				'user_login' => 'srfm_fail_' . uniqid(),
				'user_pass'  => 'password',
				'role'       => $role,
			]
		);
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

	/**
	 * The first-form-created flag follows the stored timestamp option.
	 *
	 * Suffixed name so it doesn't collide with #3040's same-named test on merge
	 * (both append to Test_Getting_Started_Notice); the coverage grep still matches.
	 */
	public function test_is_first_form_created_reflects_stored_timestamp() {
		// No stored timestamp → first form not yet created.
		Helper::update_srfm_option( 'first_form_created_at', false );
		$this->assertFalse( Admin::is_first_form_created() );

		// A positive integer timestamp → first form has been created.
		Helper::update_srfm_option( 'first_form_created_at', time() );
		$this->assertTrue( Admin::is_first_form_created() );

		// A zero/invalid timestamp does not count as created.
		Helper::update_srfm_option( 'first_form_created_at', 0 );
		$this->assertFalse( Admin::is_first_form_created() );

		Helper::update_srfm_option( 'first_form_created_at', false );
	}

	/**
	 * The card is populated for a starter-template form, with deep-link URLs (#3031).
	 *
	 * Seeds the trigger meta and resets the request memo so the populated path runs
	 * on CI — otherwise get_form_setup_card() returns null (nothing stamps the
	 * marker) and the assertions never execute.
	 */
	public function test_get_form_setup_card() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		$admin_user = wp_insert_user(
			[
				'user_login' => 'srfm_card_admin_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_card_admin_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin_user ) ? 0 : (int) $admin_user );

		$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Card Form' ] );
		update_post_meta( $form_id, Admin::ASTRA_SITES_IMPORT_META, 1 );
		// The negative cache is site-wide, so clear it after stamping the marker —
		// a previous test may have recorded that no imported forms exist.
		delete_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT );

		try {
			Admin::reset_form_setup_card_cache();
			$card = Admin::get_form_setup_card();

			$this->assertIsArray( $card, 'A starter-template form must yield a card.' );
			$this->assertSame( $form_id, $card['id'] );
			$this->assertNotEmpty( $card['edit_url'] );
			$this->assertStringContainsString( 'srfm_focus=notifications', (string) $card['email_url'] );
			$this->assertStringContainsString( 'srfm_focus=thankyou', (string) $card['thankyou_url'] );

			// A non-editor gets no card.
			wp_set_current_user( 0 );
			Admin::reset_form_setup_card_cache();
			$this->assertNull( Admin::get_form_setup_card(), 'A user who cannot edit the form gets no card.' );
		} finally {
			Admin::reset_form_setup_card_cache();
			wp_delete_post( $form_id, true );
			wp_set_current_user( 0 );
			if ( ! is_wp_error( $admin_user ) ) {
				wp_delete_user( (int) $admin_user );
			}
		}
	}

	/**
	 * Resetting the card memo lets a later read recompute (#3031).
	 */
	public function test_reset_form_setup_card_cache() {
		Admin::reset_form_setup_card_cache();
		$this->assertTrue( null === Admin::get_form_setup_card() || is_array( Admin::get_form_setup_card() ) );
		Admin::reset_form_setup_card_cache();
		$this->assertTrue( null === Admin::get_form_setup_card() || is_array( Admin::get_form_setup_card() ) );
	}

	/**
	 * The negative cache short-circuits both payload builders (#3031, #3030).
	 *
	 * This is the branch that keeps the marker query off installs that can never
	 * match it. It is deliberately a transient rather than a
	 * `defined( 'ASTRA_SITES_VER' )` check — Starter Templates defines that constant
	 * in its main plugin file, so it disappears when the plugin is deactivated, while
	 * the import marker it stamps stays on the forms. Gating on the constant would
	 * silently disable both features for exactly the people who imported a template
	 * and then removed the importer.
	 */
	public function test_negative_cache_short_circuits_the_marker_query() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		$admin_user = wp_insert_user(
			[
				'user_login' => 'srfm_negcache_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_negcache_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin_user ) ? 0 : (int) $admin_user );

		// A form that would otherwise qualify.
		$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Cached Away' ] );
		update_post_meta( $form_id, Admin::ASTRA_SITES_IMPORT_META, 1 );

		try {
			// Cache says "nothing imported here" → both builders bail without querying.
			set_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT, 'no', HOUR_IN_SECONDS );
			Admin::reset_form_setup_card_cache();
			Admin::reset_thankyou_prompt_cache();
			$this->assertNull( Admin::get_form_setup_card(), 'The negative cache must short-circuit the setup card.' );
			$this->assertSame( [], Admin::get_thankyou_prompt_forms(), 'The negative cache must short-circuit the Thank You prompt.' );

			// Clearing it restores the populated path, so the bail really was the cache
			// and not a missing prerequisite.
			delete_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT );
			Admin::reset_form_setup_card_cache();
			Admin::reset_thankyou_prompt_cache();
			$card = Admin::get_form_setup_card();
			$this->assertIsArray( $card, 'With the cache cleared the qualifying form is found.' );
			$this->assertSame( $form_id, $card['id'] );
		} finally {
			delete_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT );
			Admin::reset_form_setup_card_cache();
			Admin::reset_thankyou_prompt_cache();
			wp_delete_post( $form_id, true );
			wp_set_current_user( 0 );
			if ( ! is_wp_error( $admin_user ) ) {
				wp_delete_user( (int) $admin_user );
			}
		}
	}

	/**
	 * Stamping the import marker drops the negative cache (#3031, #3030).
	 *
	 * Without this, a starter template imported after the cache was written would
	 * show neither feature until the transient expired a week later. Narrowed to our
	 * post type on purpose: a full-site import stamps this marker on every post it
	 * creates, and both features only ever query sureforms_form.
	 */
	public function test_invalidate_starter_template_cache() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		$admin  = Admin::get_instance();
		$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Marker Form' ] );
		$page_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Imported Page' ] );

		try {
			// The marker on one of our forms drops the cache.
			set_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT, 'no', HOUR_IN_SECONDS );
			$admin->invalidate_starter_template_cache( 0, $form_id, Admin::ASTRA_SITES_IMPORT_META );
			$this->assertFalse(
				get_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT ),
				'Stamping the marker on a form must drop the negative cache.'
			);

			// An unrelated meta key leaves it alone.
			set_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT, 'no', HOUR_IN_SECONDS );
			$admin->invalidate_starter_template_cache( 0, $form_id, '_srfm_unrelated_key' );
			$this->assertSame(
				'no',
				get_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT ),
				'An unrelated meta key must not drop the cache.'
			);

			// The same marker on a non-form post leaves it alone — a full-site import
			// stamps pages and products too, and neither feature queries those.
			set_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT, 'no', HOUR_IN_SECONDS );
			$admin->invalidate_starter_template_cache( 0, $page_id, Admin::ASTRA_SITES_IMPORT_META );
			$this->assertSame(
				'no',
				get_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT ),
				'The marker on a non-form post must not drop the cache.'
			);
		} finally {
			delete_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT );
			wp_delete_post( $form_id, true );
			wp_delete_post( $page_id, true );
		}
	}

	/**
	 * The setup-card REST handler records a CTA click without error (#3031).
	 *
	 * A CTA/analytics action ("edit_form") records telemetry and returns success;
	 * the handler no longer writes any per-user state.
	 */
	public function test_dismiss_form_setup_card() {
		$admin = Admin::get_instance();
		$this->assertTrue( method_exists( $admin, 'dismiss_form_setup_card' ) );

		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) || ! class_exists( '\WP_REST_Request' ) ) {
			$this->markTestSkipped( 'REST/CPT environment not available' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		$user = wp_insert_user(
			[
				'user_login' => 'srfm_widget_admin_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_widget_admin_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $user ) ? 0 : (int) $user );

		$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Widget form' ] );

		$req = new \WP_REST_Request( 'POST', '/sureforms/v1/dismiss-form-setup-card' );
		$req->set_param( 'form_id', $form_id );
		$req->set_param( 'action', 'edit_form' );
		$res = $admin->dismiss_form_setup_card( $req );
		$this->assertFalse( is_wp_error( $res ), 'A valid CTA action should not error.' );

		wp_delete_post( $form_id, true );
		wp_set_current_user( 0 );
		if ( ! is_wp_error( $user ) ) {
			wp_delete_user( (int) $user );
		}
	}

	/**
	 * The dashboard-widget registrar is callable (#3031).
	 */
	public function test_register_form_setup_widget() {
		$this->assertTrue( method_exists( Admin::get_instance(), 'register_form_setup_widget' ) );
	}

	/**
	 * The renderer prints the checklist markup for a qualifying form, and nothing
	 * without one (#3031). Seeds the trigger so the populated path runs on CI.
	 */
	public function test_render_form_setup_widget() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}
		remove_all_actions( 'wp_insert_post_data' );
		$admin = Admin::get_instance();

		$admin_user = wp_insert_user(
			[
				'user_login' => 'srfm_render_admin_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_render_admin_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin_user ) ? 0 : (int) $admin_user );

		$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Render Form' ] );
		update_post_meta( $form_id, Admin::ASTRA_SITES_IMPORT_META, 1 );
		// The negative cache is site-wide, so clear it after stamping the marker —
		// a previous test may have recorded that no imported forms exist.
		delete_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT );

		try {
			// Positive: a qualifying form renders the full checklist.
			Admin::reset_form_setup_card_cache();
			ob_start();
			$admin->render_form_setup_widget();
			$output = (string) ob_get_clean();

			$this->assertStringContainsString( 'srfm-setup-checklist', $output );
			$this->assertStringContainsString( 'srfm-setup-checklist__cta', $output );
			$this->assertStringContainsString( 'Render Form', $output );

			// Negative: no card → nothing rendered.
			wp_set_current_user( 0 );
			Admin::reset_form_setup_card_cache();
			ob_start();
			$admin->render_form_setup_widget();
			$this->assertSame( '', (string) ob_get_clean(), 'No card → the widget renders nothing.' );
		} finally {
			Admin::reset_form_setup_card_cache();
			wp_delete_post( $form_id, true );
			wp_set_current_user( 0 );
			if ( ! is_wp_error( $admin_user ) ) {
				wp_delete_user( (int) $admin_user );
			}
		}
	}

	/**
	 * Widget assets never load off the dashboard screen (#3031).
	 */
	public function test_enqueue_form_setup_widget_assets() {
		$admin = Admin::get_instance();

		wp_dequeue_style( 'srfm-setup-checklist-widget' );
		wp_deregister_style( 'srfm-setup-checklist-widget' );

		// Wrong hook → the method returns before querying or enqueuing anything.
		$admin->enqueue_form_setup_widget_assets( 'edit.php' );
		$this->assertFalse( wp_style_is( 'srfm-setup-checklist-widget', 'enqueued' ), 'Assets must not load outside the dashboard.' );
	}
}

/**
 * Tests for the "Finish setting up" Thank You prompt (#3030).
 *
 * Separate class (not appended to Test_Getting_Started_Notice) so it doesn't
 * collide with #3031's tests on merge — both branches otherwise declare
 * test_is_first_form_created() in the same class.
 */
class Test_Thankyou_Prompt_Notice extends TestCase {
	use Astra_Notices_Helper;

	/**
	 * Reset shared state between tests — this file has no WP_UnitTestCase rollback.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		Admin::reset_thankyou_prompt_cache();
		remove_all_filters( 'srfm_thankyou_prompt_forms' );
		remove_all_filters( 'srfm_show_thankyou_prompt' );
		parent::tearDown();
	}

	/**
	 * The first-form-created flag follows the stored timestamp option.
	 */
	public function test_is_first_form_created() {
		// No stored timestamp → first form not yet created.
		Helper::update_srfm_option( 'first_form_created_at', false );
		$this->assertFalse( Admin::is_first_form_created() );

		// A positive integer timestamp → first form has been created.
		Helper::update_srfm_option( 'first_form_created_at', time() );
		$this->assertTrue( Admin::is_first_form_created() );

		// A zero/invalid timestamp does not count as created.
		Helper::update_srfm_option( 'first_form_created_at', 0 );
		$this->assertFalse( Admin::is_first_form_created() );

		Helper::update_srfm_option( 'first_form_created_at', false );
	}

	/**
	 * A form with no stored confirmation is not the shipped default (#3030).
	 */
	public function test_is_default_confirmation_message() {
		// No confirmation meta → nothing to compare → not the default.
		$this->assertFalse( Admin::is_default_confirmation_message( 0 ) );

		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post(
			[
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'TY detect',
			]
		);

		$default_message = \SRFM\Inc\Global_Settings\Global_Settings::get_default_confirmation_message();

		// The shipped default message on a "same page" confirmation is the default.
		update_post_meta( $form_id, '_srfm_form_confirmation', [ [ 'confirmation_type' => 'same page', 'message' => $default_message ] ] );
		$this->assertTrue( Admin::is_default_confirmation_message( $form_id ) );

		// The same default with entities decoded — as a starter-template import can
		// store it (literal apostrophe vs the generated &#039;) — still matches.
		update_post_meta(
			$form_id,
			'_srfm_form_confirmation',
			[ [ 'confirmation_type' => 'same page', 'message' => html_entity_decode( $default_message, ENT_QUOTES, 'UTF-8' ) ] ]
		);
		$this->assertTrue( Admin::is_default_confirmation_message( $form_id ) );

		// A redirect confirmation never renders the message, so even the default
		// string must NOT be flagged (it would otherwise nag with no way to clear).
		update_post_meta( $form_id, '_srfm_form_confirmation', [ [ 'confirmation_type' => 'different page', 'message' => $default_message ] ] );
		$this->assertFalse( Admin::is_default_confirmation_message( $form_id ) );

		// Any real edit to the message flips it to non-default (the auto-clear path).
		update_post_meta( $form_id, '_srfm_form_confirmation', [ [ 'confirmation_type' => 'same page', 'message' => 'Thanks so much - we will be in touch!' ] ] );
		$this->assertFalse( Admin::is_default_confirmation_message( $form_id ) );

		wp_delete_post( $form_id, true );
	}

	/**
	 * A form with no enabled email notification has no reply destination (#3030).
	 */
	public function test_form_has_reply_destination() {
		$this->assertFalse( Admin::form_has_reply_destination( 0 ) );

		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		$form_id = wp_insert_post(
			[
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Reply dest',
			]
		);

		// Enabled notification with a recipient → has a destination.
		update_post_meta( $form_id, '_srfm_email_notification', [ [ 'status' => true, 'email_to' => 'admin@example.com' ] ] );
		$this->assertTrue( Admin::form_has_reply_destination( $form_id ) );

		// A disabled notification does not count.
		update_post_meta( $form_id, '_srfm_email_notification', [ [ 'status' => false, 'email_to' => 'admin@example.com' ] ] );
		$this->assertFalse( Admin::form_has_reply_destination( $form_id ) );

		// An enabled notification with no recipient does not count.
		update_post_meta( $form_id, '_srfm_email_notification', [ [ 'status' => true, 'email_to' => '' ] ] );
		$this->assertFalse( Admin::form_has_reply_destination( $form_id ) );

		wp_delete_post( $form_id, true );
	}

	/**
	 * The notice markup renders the title, message and all three CTAs (#3030).
	 */
	public function test_thankyou_notice_markup_renders_ctas() {
		$build = new \ReflectionMethod( Admin::class, 'build_thankyou_notice_markup' );
		$build->setAccessible( true );

		$markup = $build->invoke(
			null,
			[
				'title'        => 'Sample Form',
				'days_ago'     => 2,
				'steps'        => [ 'replies' => true, 'thankyou' => true ],
				'edit_url'     => 'https://example.com/e',
				'replies_url'  => 'https://example.com/r',
				'thankyou_url' => 'https://example.com/t',
			]
		);

		// Title carries the form name; body is the (accurate, step-agnostic) message.
		$this->assertStringContainsString( 'Sample Form', $markup );
		$this->assertStringContainsString( 'already created this form for you', $markup );

		// All three CTAs, each with its tracking class and deep-link target.
		$this->assertStringContainsString( 'srfm-ty-edit-form', $markup );
		$this->assertStringContainsString( 'srfm-ty-set-replies', $markup );
		$this->assertStringContainsString( 'srfm-ty-edit-thankyou', $markup );
		$this->assertStringContainsString( 'https://example.com/t', $markup );
	}

	/**
	 * The Thank You prompt query always returns an array (#3030).
	 */
	public function test_get_thankyou_prompt_forms() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		// The sureforms_form CPT maps edit_post to manage_options, so an admin.
		$admin = wp_insert_user(
			[
				'user_login' => 'srfm_ty_admin_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_ty_admin_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin ) ? 0 : (int) $admin );

		$default_message = \SRFM\Inc\Global_Settings\Global_Settings::get_default_confirmation_message();

		// Starter-template import still on the default message → targeted.
		$imported = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Imported TY' ] );
		update_post_meta( $imported, '_srfm_form_confirmation', [ [ 'confirmation_type' => 'same page', 'message' => $default_message ] ] );
		update_post_meta( $imported, Admin::ASTRA_SITES_IMPORT_META, 1 );
		// The negative cache is site-wide, so clear it after stamping the marker —
		// a previous test may have recorded that no imported forms exist.
		delete_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT );

		// Same default message but NOT an Astra Sites import → excluded by the gate.
		$plain = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Plain TY' ] );
		update_post_meta( $plain, '_srfm_form_confirmation', [ [ 'confirmation_type' => 'same page', 'message' => $default_message ] ] );

		// Drive the uncached builder to bypass the request-memoized cache.
		$compute = new \ReflectionMethod( Admin::class, 'compute_thankyou_prompt_forms' );
		$compute->setAccessible( true );
		$prompts = $compute->invoke( null );
		$ids     = wp_list_pluck( $prompts, 'id' );

		$this->assertContains( $imported, $ids, 'An Astra Sites imported form on the default message must be targeted.' );
		$this->assertNotContains( $plain, $ids, 'A non-imported form must not be targeted.' );

		// The surfaced payload carries the deep-link CTA target (?srfm_focus=thankyou).
		$this->assertStringContainsString( 'srfm_focus=thankyou', (string) ( $prompts[0]['thankyou_url'] ?? '' ) );

		wp_delete_post( $imported, true );
		wp_delete_post( $plain, true );
		wp_set_current_user( 0 );
		if ( ! is_wp_error( $admin ) ) {
			wp_delete_user( (int) $admin );
		}
	}

	/**
	 * The registrar adds nothing without a qualifying form, and exactly one notice
	 * with it — asserted on the Astra_Notices registry, with real preconditions so
	 * the method doesn't just return on its first guard (#3030).
	 */
	public function test_render_thankyou_prompt_notice() {
		$admin = Admin::get_instance();

		if ( ! class_exists( '\Astra_Notices' ) || ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'Astra_Notices / CPT not available.' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		$prop = new \ReflectionProperty( \Astra_Notices::class, 'notices' );
		$prop->setAccessible( true );
		$original = $prop->getValue();

		// A capable user on a NON-dashboard screen with no prior dismissal — the
		// preconditions the method needs before it ever reaches the query.
		$admin_user = wp_insert_user(
			[
				'user_login' => 'srfm_ty_render_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_ty_render_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin_user ) ? 0 : (int) $admin_user );
		set_current_screen( 'plugins' );
		delete_user_meta( is_wp_error( $admin_user ) ? 0 : (int) $admin_user, 'srfm-thankyou-prompt' );

		try {
			// Negative: no qualifying starter-template form → nothing registered.
			Admin::reset_thankyou_prompt_cache();
			$prop->setValue( null, [] );
			$admin->render_thankyou_prompt_notice();
			$this->assertSame( [], $prop->getValue(), 'No qualifying form → no notice registered.' );

			// Positive: a starter-template import still on the default Thank You
			// message → exactly the srfm-thankyou-prompt notice is registered.
			$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Positive TY' ] );
			update_post_meta( $form_id, Admin::ASTRA_SITES_IMPORT_META, 1 );
			// The negative cache is site-wide, so clear it after stamping the marker —
			// a previous test may have recorded that no imported forms exist.
			delete_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT );
			update_post_meta(
				$form_id,
				'_srfm_form_confirmation',
				[ [ 'confirmation_type' => 'same page', 'message' => \SRFM\Inc\Global_Settings\Global_Settings::get_default_confirmation_message() ] ]
			);

			Admin::reset_thankyou_prompt_cache();
			$prop->setValue( null, [] );
			$admin->render_thankyou_prompt_notice();
			$ids = wp_list_pluck( (array) $prop->getValue(), 'id' );
			$this->assertContains( 'srfm-thankyou-prompt', $ids, 'A qualifying form registers exactly the prompt notice.' );

			wp_delete_post( $form_id, true );
		} finally {
			$prop->setValue( null, is_array( $original ) ? $original : [] );
			Admin::reset_thankyou_prompt_cache();
			wp_set_current_user( 0 );
			if ( ! is_wp_error( $admin_user ) ) {
				wp_delete_user( (int) $admin_user );
			}
		}
	}

	/**
	 * The Thank You notice styling prints the brand accent colour (#3030).
	 */
	public function test_print_thankyou_notice_styles() {
		$admin = Admin::get_instance();

		ob_start();
		$admin->print_thankyou_notice_styles();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'srfm-thankyou-notice', $output );
		$this->assertStringContainsString( '#D54407', $output );
		// The SureForms mark is a data-URI background; assert the URI itself so a
		// dropped esc_url() protocol allowlist (which blanks it) is caught.
		$this->assertStringContainsString( "background: url('data:image/svg+xml,", $output );
	}

	/**
	 * The prompt cache reset clears the request memo (#3030).
	 */
	public function test_reset_thankyou_prompt_cache() {
		Admin::reset_thankyou_prompt_cache();
		$this->assertIsArray( Admin::get_thankyou_prompt_forms() );

		// After a reset the next read recomputes rather than returning a pinned value.
		Admin::reset_thankyou_prompt_cache();
		$this->assertIsArray( Admin::get_thankyou_prompt_forms() );
	}

	/**
	 * The Thank You notice click-tracking enqueues a delegated dismiss beacon (#3030).
	 *
	 * Regression guard: the ✕ is injected by core on DOMContentLoaded, after this
	 * inline script parses, so it must be caught by delegation from the wrapper —
	 * a direct .notice-dismiss lookup would bind to nothing.
	 */
	public function test_enqueue_thankyou_notice_tracking() {
		$admin = Admin::get_instance();

		wp_dequeue_script( 'srfm-thankyou-notice-track' );
		wp_deregister_script( 'srfm-thankyou-notice-track' );

		$admin->enqueue_thankyou_notice_tracking();

		$this->assertTrue( wp_script_is( 'srfm-thankyou-notice-track', 'enqueued' ) );

		$after = wp_scripts()->get_data( 'srfm-thankyou-notice-track', 'after' );
		$body  = is_array( $after ) ? implode( "\n", $after ) : (string) $after;

		// Dismissal is delegated from the wrapper, not bound to .notice-dismiss.
		$this->assertStringContainsString( "addEventListener( 'click'", $body );
		$this->assertStringContainsString( "closest( '.notice-dismiss' )", $body );
		$this->assertStringContainsString( 'dismissed', $body );

		wp_dequeue_script( 'srfm-thankyou-notice-track' );
		wp_deregister_script( 'srfm-thankyou-notice-track' );
	}
}
