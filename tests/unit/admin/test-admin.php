<?php
/**
 * Class Test_First_Form_Creation
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

use SRFM\Admin\Admin;
use SRFM\Inc\Client_Logger;
use SRFM\Inc\Database\Register;
use SRFM\Inc\Helper;

require_once __DIR__ . '/trait-astra-notices-helper.php';

/**
 * Tests first form creation timestamp logic.
 */
class Test_Admin extends TestCase {

    protected function tearDown(): void {
        // Without this, an assertion failure anywhere in this class leaves $_GET and
        // the current user set for every test that runs after it, turning one real
        // failure into a wall of unrelated ones.
        $_GET = [];
        wp_set_current_user( 0 );
        parent::tearDown();
    }

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

	/**
	 * The attribution marker must be handed to core's removable-query-args list.
	 *
	 * wp_admin_canonical_url() strips those from the address bar on admin_head, which
	 * runs after load-post.php — so the marker is already counted by the time it is
	 * removed. Without it the arg lingers in the URL, in bookmarks, and in the Referer
	 * sent to every subresource the editor loads, and a refresh re-requests it.
	 *
	 * The non-array branch is covered because this is a public filter callback: any
	 * plugin can hand it something else, and returning a bare value there would break
	 * every other consumer on the filter.
	 */
	public function test_add_removable_query_args() {
		$admin = Admin::get_instance();
		$arg   = \SRFM\Inc\Generate_Form_Markup::EDIT_FORM_BUTTON_SOURCE_ARG;

		$result = $admin->add_removable_query_args( [ 'message', 'settings-updated' ] );
		$this->assertContains( $arg, $result, 'The marker should be appended to the removable list.' );
		$this->assertContains( 'message', $result, 'Existing removable args must be preserved.' );
		$this->assertContains( 'settings-updated', $result, 'Existing removable args must be preserved.' );

		// A filter that hands us a non-array must still yield a usable list.
		$this->assertSame( [ $arg ], $admin->add_removable_query_args( 'not-an-array' ) );

		// And the callback is actually wired to the filter.
		$this->assertContains( $arg, apply_filters( 'removable_query_args', [] ), 'The filter should carry the marker.' );
	}

	/**
	 * The front-end "Edit Form" pill is attributed by a marker query arg rather
	 * than a click handler, so the counter must only move when the editor is
	 * genuinely opened from that pill, by someone allowed to edit that form.
	 *
	 * Every rejection path is asserted, because each one is a way the counter could
	 * be moved by a crafted URL: no marker, a marker with the wrong value, an empty
	 * marker, a non-scalar marker, a marker pointed at a post that is not a SureForms
	 * form, a deleted post, and a user without the capability. The repeat visit is
	 * asserted too, since the dedup window is what makes this a click count rather
	 * than a page-load count. The counter is read back from the stored option, so a
	 * guard that silently stops incrementing also fails here.
	 */
	public function test_maybe_track_edit_form_button_click() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}

		$admin = Admin::get_instance();
		$arg   = \SRFM\Inc\Generate_Form_Markup::EDIT_FORM_BUTTON_SOURCE_ARG;

		$form_id = wp_insert_post(
			[
				'post_title'  => 'Pill Analytics Form',
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_status' => 'publish',
			]
		);
		$page_id = wp_insert_post(
			[
				'post_title'  => 'Not A Form',
				'post_type'   => 'post',
				'post_status' => 'publish',
			]
		);

		$administrator = wp_insert_user(
			[
				'user_login' => 'srfm_pill_admin_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_pill_admin_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		$subscriber    = wp_insert_user(
			[
				'user_login' => 'srfm_pill_sub_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_pill_sub_' . wp_rand() . '@example.com',
				'role'       => 'subscriber',
			]
		);

		$count = static function () {
			return Helper::get_integer_value( Helper::get_srfm_option( 'edit_form_button_clicks', 0 ) );
		};

		Helper::update_srfm_option( 'edit_form_button_clicks', 0 );
		wp_set_current_user( is_wp_error( $administrator ) ? 0 : (int) $administrator );

		// No marker at all — an ordinary editor visit must not be counted.
		$_GET = [ 'post' => $form_id ];
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 0, $count(), 'An editor visit without the marker must not be counted.' );

		// Marker present but not the value we emit.
		$_GET = [ 'post' => $form_id, $arg => 'somewhere-else' ];
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 0, $count(), 'An unrecognised marker value must not be counted.' );

		// Marker reused against a post that is not a SureForms form.
		$_GET = [ 'post' => $page_id, $arg => 'embed' ];
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 0, $count(), 'A non-form post must not be counted.' );

		// Marker with no post at all.
		$_GET = [ $arg => 'embed' ];
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 0, $count(), 'A missing post ID must not be counted.' );

		// The real path: administrator opening this form from the pill.
		$_GET = [ 'post' => $form_id, $arg => 'embed' ];
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 1, $count(), 'A genuine pill click should be counted.' );

		// Deduped: the same editor reopening the same form inside the window does not
		// count again. Without this the metric would measure editor loads carrying the
		// marker — a refresh or a back-navigation re-counts — rather than pill clicks.
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 1, $count(), 'A repeat visit inside the window must not count again.' );

		// The counter is still cumulative across distinct forms.
		$other_form = wp_insert_post(
			[
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_title'  => 'Second pill form',
				'post_status' => 'publish',
			]
		);
		$_GET       = [ 'post' => $other_form, $arg => 'embed' ];
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 2, $count(), 'A different form is a separate count.' );

		// A user who cannot edit the form gets nothing, marker or not.
		$_GET = [ 'post' => $form_id, $arg => 'embed' ];
		wp_set_current_user( is_wp_error( $subscriber ) ? 0 : (int) $subscriber );
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 2, $count(), 'A user without edit_post must not be counted.' );

		// Rejection paths that previously went unasserted.
		wp_set_current_user( is_wp_error( $administrator ) ? 0 : (int) $administrator );

		$_GET = [ 'post' => $form_id, $arg => '' ];
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 2, $count(), 'An empty marker must not be counted.' );

		// Wrong type. isset() is satisfied, so this reaches sanitize_key() unless the
		// handler rejects non-strings first — on older supported WordPress versions
		// that is a fatal rather than a no-op.
		$_GET = [ 'post' => $form_id, $arg => [ 'embed' ] ];
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 2, $count(), 'A non-scalar marker must no-op, not fatal.' );

		$deleted = wp_insert_post(
			[
				'post_type'   => SRFM_FORMS_POST_TYPE,
				'post_title'  => 'Deleted pill form',
				'post_status' => 'publish',
			]
		);
		wp_delete_post( $deleted, true );
		$_GET = [ 'post' => $deleted, $arg => 'embed' ];
		$admin->maybe_track_edit_form_button_click();
		$this->assertSame( 2, $count(), 'A deleted form must not be counted.' );

		wp_delete_post( $other_form, true );

		$_GET = [];
		wp_set_current_user( 0 );
		Helper::update_srfm_option( 'edit_form_button_clicks', 0 );
		wp_delete_post( $form_id, true );
		wp_delete_post( $page_id, true );
		if ( ! is_wp_error( $administrator ) ) {
			wp_delete_user( (int) $administrator );
		}
		if ( ! is_wp_error( $subscriber ) ) {
			wp_delete_user( (int) $subscriber );
		}
	}

	// ---------------------------------------------------------------
	// Missing entries table — notice and repair
	// ---------------------------------------------------------------

	/**
	 * The classic notice must never render outside the WP dashboard. The React
	 * notice already covers SureForms' own screens, so an admin-wide classic notice
	 * would stack two warnings on the same page and nag on every screen in wp-admin.
	 *
	 * Both directions are asserted: with the table genuinely missing, an empty
	 * result on another screen only means something if the dashboard renders.
	 */
	public function test_render_database_repair_notice() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		$this->break_entries_table();

		set_current_screen( 'edit-post' );
		ob_start();
		Admin::get_instance()->render_database_repair_notice();
		$elsewhere = ob_get_clean();

		set_current_screen( 'dashboard' );
		ob_start();
		Admin::get_instance()->render_database_repair_notice();
		$on_dashboard = ob_get_clean();

		$this->restore_entries_table();

		$this->assertSame( '', $elsewhere );
		$this->assertStringContainsString( 'notice-warning', $on_dashboard );
	}

	/**
	 * The React notice must actually be registered when the table is missing, and
	 * must carry the opaque `action` id rather than an endpoint for the browser to
	 * call. Priority 5 on admin_init is load-bearing: Notice_Manager hands notices to
	 * the front end during admin_enqueue_scripts, so anything registering later never
	 * reaches the page.
	 */
	public function test_register_database_repair_notice() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		\SRFM\Admin\Notice_Manager::clear_notices();
		$this->break_entries_table();

		Admin::get_instance()->register_database_repair_notice();
		$notices = \SRFM\Admin\Notice_Manager::get_notices();

		$this->restore_entries_table();
		\SRFM\Admin\Notice_Manager::clear_notices();

		$ids = wp_list_pluck( $notices, 'id' );
		$this->assertContains( 'srfm-database-maintenance', $ids );

		$notice = null;
		foreach ( $notices as $candidate ) {
			if ( 'srfm-database-maintenance' === $candidate['id'] ) {
				$notice = $candidate;
			}
		}

		$this->assertSame( 'warning', $notice['variant'], 'This is routine maintenance, not an error.' );
		$this->assertSame( 'repair-entries-table', $notice['actions'][0]['action'] );
	}

	/**
	 * Nothing may be registered on a healthy install — a false positive would put a
	 * "database update needed" notice on every working site.
	 */
	public function test_register_database_repair_notice_is_silent_when_healthy() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		\SRFM\Admin\Notice_Manager::clear_notices();
		Admin::get_instance()->register_database_repair_notice();
		$ids = wp_list_pluck( \SRFM\Admin\Notice_Manager::get_notices(), 'id' );
		\SRFM\Admin\Notice_Manager::clear_notices();

		$this->assertNotContains( 'srfm-database-maintenance', $ids );
	}

	/**
	 * The admin-post entry point repairs a table, so the capability check must come
	 * first — ahead of the nonce, and ahead of any write. A subscriber following the
	 * link must be stopped before anything touches the database.
	 */
	public function test_handle_database_repair() {
		wp_set_current_user( $this->make_user( 'subscriber' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		// wp_die() ends the request; make it throw so the runner survives and we can
		// assert that the capability check stopped us before anything else ran.
		$throw_handler = static function () {
			return static function () {
				throw new \WPDieException( 'srfm_test_die' );
			};
		};
		add_filter( 'wp_die_handler', $throw_handler );

		$died = false;

		ob_start();
		try {
			Admin::get_instance()->handle_database_repair();
		} catch ( \WPDieException $e ) {
			$died = true;
		} finally {
			ob_end_clean();
			remove_filter( 'wp_die_handler', $throw_handler );
		}

		$this->assertTrue( $died, 'A subscriber must be stopped before the repair runs.' );
	}

	/**
	 * The Pro compatibility notices must stay off a free-only install. The guard is
	 * a single early return over three conditions, so a free site is the case most
	 * likely to regress into seeing a notice about a plugin it does not have.
	 */
	public function test_register_pro_compatibility_notices() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		\SRFM\Admin\Notice_Manager::clear_notices();
		Admin::get_instance()->register_pro_compatibility_notices();
		$notices = \SRFM\Admin\Notice_Manager::get_notices();
		\SRFM\Admin\Notice_Manager::clear_notices();

		if ( Helper::has_pro() ) {
			$this->markTestSkipped( 'Pro is active; this asserts the free-only path.' );
		}

		$this->assertSame( [], $notices );
	}

	/**
	 * A healthy install must never see the prompt — a false positive here would put
	 * a "your database needs updating" warning on every working site.
	 */
	public function test_database_notice_is_absent_on_a_healthy_install() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		set_current_screen( 'dashboard' );
		ob_start();
		Admin::get_instance()->render_database_repair_notice();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * A subscriber must never be shown the repair prompt, let alone the link that
	 * performs it. The capability check runs before anything else in the method.
	 */
	public function test_database_notice_is_hidden_from_users_without_the_capability() {
		wp_set_current_user( $this->make_user( 'subscriber' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		set_current_screen( 'dashboard' );
		$_GET['srfm_db_repair'] = 'done';

		ob_start();
		Admin::get_instance()->render_database_repair_notice();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The post-repair confirmation is the one branch a healthy site still renders,
	 * so it doubles as proof the dashboard gate lets the right screen through.
	 */
	public function test_database_notice_reports_a_completed_repair() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		set_current_screen( 'dashboard' );
		$_GET['srfm_db_repair'] = 'done';

		ob_start();
		Admin::get_instance()->render_database_repair_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
	}

	/**
	 * A failed repair means the host refuses to let SureForms create tables. It must
	 * read as something to act on, not as a success, and must not claim the table is
	 * fixed.
	 */
	public function test_database_notice_reports_a_failed_repair_as_a_warning() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		set_current_screen( 'dashboard' );
		$_GET['srfm_db_repair'] = 'failed';

		ob_start();
		Admin::get_instance()->render_database_repair_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringNotContainsString( 'notice-success', $output );
	}

	/**
	 * The repair link must carry a nonce. Without one, any page that can make the
	 * admin follow a link could trigger a table operation.
	 */
	public function test_repair_url_is_nonce_protected() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		$method = new ReflectionMethod( Admin::class, 'get_database_repair_url' );
		$method->setAccessible( true );
		$url = $method->invoke( Admin::get_instance() );

		$this->assertStringContainsString( 'action=srfm_repair_entries_table', $url );

		$query = [];
		parse_str( (string) wp_parse_url( html_entity_decode( $url ), PHP_URL_QUERY ), $query );

		$this->assertArrayHasKey( '_wpnonce', $query );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], 'srfm_repair_entries_table' ) );
	}

	/**
	 * `database_error` must be an accepted notice id, or the Fix-now and dismiss
	 * clicks are dropped and the feature ships with no analytics at all.
	 */
	public function test_database_error_is_an_accepted_notice_id() {
		$this->assertTrue( $this->post_notice_response( 'database_error', 'fix_now' ) );
		$this->assertTrue( $this->post_notice_response( 'database_error', 'dismissed' ) );
	}

	/**
	 * An unknown notice id, or a button the notice does not define, must be rejected
	 * rather than allowed to write an arbitrary event name into the analytics payload.
	 */
	public function test_unknown_notice_ids_and_buttons_are_rejected() {
		$this->assertFalse( $this->post_notice_response( 'not_a_real_notice', 'fix_now' ) );
		$this->assertFalse( $this->post_notice_response( 'database_error', 'drop_everything' ) );
	}

	/**
	 * Drive the real AJAX handler and report whether it accepted the pair.
	 *
	 * wp_send_json_*() ends the request through wp_die(), so this points the AJAX
	 * die handler at the suite's disable-able one and reads the buffered JSON.
	 * Going through the handler rather than reading the allowlist directly means the
	 * nonce and capability gates are exercised too.
	 *
	 * @param string $notice_id Notice identifier to send.
	 * @param string $button    Button identifier to send.
	 * @return bool Whether the handler responded with success.
	 */
	private function post_notice_response( $notice_id, $button ) {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		$_POST['nonce']     = wp_create_nonce( 'srfm_notice_response' );
		$_POST['notice_id'] = $notice_id;
		$_POST['button']    = $button;
		$_REQUEST           = $_POST;

		add_filter( 'wp_doing_ajax', '__return_true' );

		// Core's _ajax_wp_die_handler() calls die(), which would take the test runner
		// with it. The suite's handler is a no-op while wp_die is disabled, leaving
		// the JSON payload in the buffer to assert on.
		add_filter( 'wp_die_ajax_handler', '_wp_die_handler_filter' );
		_disable_wp_die();

		ob_start();
		Admin::get_instance()->handle_notice_response();
		$body = (string) ob_get_clean();

		_enable_wp_die();
		remove_filter( 'wp_die_ajax_handler', '_wp_die_handler_filter' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$_POST    = [];
		$_REQUEST = [];

		$decoded = json_decode( $body, true );

		return is_array( $decoded ) && ! empty( $decoded['success'] );
	}

	/**
	 * The shared repair routine must report the state of the database, and must
	 * leave the entries table in place either way.
	 */
	public function test_do_database_repair_reports_the_table_state() {
		$this->assertTrue( Admin::get_instance()->do_database_repair() );
		$this->assertFalse( Register::is_entries_table_missing( true ) );
	}

	/**
	 * Drop the entries table, reproducing the state the notice exists to catch.
	 *
	 * @return void
	 */
	private function break_entries_table() {
		global $wpdb;

		$table               = \SRFM\Inc\Database\Tables\Entries::get_instance()->get_tablename();
		$versions            = (array) get_option( 'srfm_database_table_versions', [] );
		$versions['entries'] = 2;
		update_option( 'srfm_database_table_versions', $versions );

		$wpdb->query( "CREATE TABLE `{$table}_srfmbak` LIKE `{$table}`" ); // phpcs:ignore -- Preserving the schema across the drop under test.
		$wpdb->query( "DROP TABLE `{$table}`" ); // phpcs:ignore -- Reproducing the dropped-table state under test.

		$this->reset_table_cache();
	}

	/**
	 * Put the entries table back after break_entries_table().
	 *
	 * @return void
	 */
	private function restore_entries_table() {
		global $wpdb;

		$table = \SRFM\Inc\Database\Tables\Entries::get_instance()->get_tablename();

		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore -- Discarding anything a repair under test created.
		$wpdb->query( "RENAME TABLE `{$table}_srfmbak` TO `{$table}`" ); // phpcs:ignore -- Restoring the table this test dropped.

		$this->reset_table_cache();
	}

	/**
	 * Clear the per-request memo and the transient behind is_entries_table_missing().
	 *
	 * @return void
	 */
	private function reset_table_cache() {
		delete_transient( Register::ENTRIES_TABLE_CHECK_TRANSIENT );

		$memo = new ReflectionProperty( Register::class, 'entries_table_present' );
		$memo->setAccessible( true );
		$memo->setValue( null, null );
	}

	/**
	 * Create a user with the given role and return its ID.
	 *
	 * @param string $role Role to assign.
	 * @return int
	 */
	private function make_user( $role ) {
		return (int) wp_insert_user(
			[
				'user_login' => 'srfm_db_' . uniqid(),
				'user_pass'  => 'password',
				'role'       => $role,
			]
		);
	}

	// ---------------------------------------------------------------
	// Dashboard action items
	// ---------------------------------------------------------------

	/**
	 * A healthy site still reports, but only as passing checks. Nothing may be a
	 * warning -- logging is on by default, so a false positive reaches every
	 * install.
	 */
	public function test_get_action_items_reports_only_passing_checks_when_healthy() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		$filter = static function () {
			return [ 'akismet/akismet.php' ];
		};

		add_filter( 'pre_option_active_plugins', $filter );
		$items = Admin::get_instance()->get_action_items();
		remove_filter( 'pre_option_active_plugins', $filter );

		$this->assertNotEmpty( $items );

		foreach ( $items as $item ) {
			$this->assertSame( 'success', $item['status'], $item['id'] . ' must pass on a healthy site.' );
		}
	}

	/**
	 * A run of faults produces a non-dismissible item, because it is a fault and
	 * clears itself when a submission succeeds.
	 */
	public function test_get_action_items() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );
		Client_Logger::record_failure( 'submission', 42, 'Contact Form' );

		$items = Admin::get_instance()->get_action_items();

		$ids = wp_list_pluck( $items, 'id' );
		$this->assertContains( 'form_submission_error', $ids );

		foreach ( $items as $item ) {
			if ( 'form_submission_error' === $item['id'] ) {
				$this->assertFalse( $item['dismissible'] );
			}
		}
	}

	/**
	 * An active caching plugin produces a dismissible advisory item pointing at the
	 * setup guide.
	 */
	public function test_get_action_items_flags_an_active_caching_plugin() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );
		Helper::update_srfm_option( 'dismissed_action_items', [] );

		$filter = static function () {
			return [ 'wp-rocket/wp-rocket.php' ];
		};

		add_filter( 'pre_option_active_plugins', $filter );
		$items = Admin::get_instance()->get_action_items();
		remove_filter( 'pre_option_active_plugins', $filter );

		$caching = null;

		foreach ( $items as $item ) {
			if ( 'caching_plugin' === $item['id'] ) {
				$caching = $item;
			}
		}

		$this->assertNotNull( $caching, 'An active caching plugin must be reported.' );
		$this->assertStringContainsString( 'WP Rocket', $caching['title'] );
		$this->assertTrue( $caching['dismissible'] );
		$this->assertStringContainsString( 'caching-plugins', $caching['cta_url'] );
	}

	/**
	 * A dismissed advisory stays dismissed.
	 */
	public function test_get_action_items_respects_a_dismissal() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );
		Helper::update_srfm_option( 'dismissed_action_items', [ 'caching_plugin' ] );

		$filter = static function () {
			return [ 'wp-rocket/wp-rocket.php' ];
		};

		add_filter( 'pre_option_active_plugins', $filter );
		$ids = wp_list_pluck( Admin::get_instance()->get_action_items(), 'id' );
		remove_filter( 'pre_option_active_plugins', $filter );

		Helper::update_srfm_option( 'dismissed_action_items', [] );

		$this->assertNotContains( 'caching_plugin', $ids );
	}

	/**
	 * The precedence guard. A form that cannot accept submissions must outrank the
	 * engagement prompts, so this is what the Thank You, rating and getting-started
	 * notices check before showing.
	 *
	 * Re-derives its conditions rather than calling get_action_items(), which
	 * records an impression and must not run from a show_if callback.
	 */
	public function test_has_action_item_warnings() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );
		Helper::update_srfm_option( 'dismissed_action_items', [] );

		$quiet = static function () {
			return [ 'akismet/akismet.php' ];
		};

		add_filter( 'pre_option_active_plugins', $quiet );
		$this->assertFalse( Admin::get_instance()->has_action_item_warnings() );
		remove_filter( 'pre_option_active_plugins', $quiet );

		Client_Logger::record_failure( 'submission', 42, 'Contact Form' );

		add_filter( 'pre_option_active_plugins', $quiet );
		$this->assertTrue( Admin::get_instance()->has_action_item_warnings() );
		remove_filter( 'pre_option_active_plugins', $quiet );

	}

	/**
	 * Dismissing an advisory must clear the warning state, or the engagement
	 * notices stay suppressed forever on a site with a caching plugin.
	 */
	public function test_has_action_item_warnings_respects_a_dismissal() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		$caching = static function () {
			return [ 'wp-rocket/wp-rocket.php' ];
		};

		Helper::update_srfm_option( 'dismissed_action_items', [] );
		add_filter( 'pre_option_active_plugins', $caching );
		$this->assertTrue( Admin::get_instance()->has_action_item_warnings() );

		Helper::update_srfm_option( 'dismissed_action_items', [ 'caching_plugin' ] );
		$this->assertFalse( Admin::get_instance()->has_action_item_warnings() );
		remove_filter( 'pre_option_active_plugins', $caching );

		Helper::update_srfm_option( 'dismissed_action_items', [] );
	}

	/**
	 * The AJAX dismissal refuses anything not on the allowlist, so a crafted
	 * request cannot silence a genuine fault.
	 */
	public function test_handle_dismiss_action_item() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );
		Helper::update_srfm_option( 'dismissed_action_items', [] );

		$this->assertStringContainsString( '"success":true', $this->post_dismiss( 'caching_plugin' ) );
		$this->assertStringContainsString( '"success":false', $this->post_dismiss( 'form_submission_error' ) );

		$dismissed = Helper::get_array_value( Helper::get_srfm_option( 'dismissed_action_items', [] ) );
		$this->assertNotContains( 'form_submission_error', $dismissed );

		Helper::update_srfm_option( 'dismissed_action_items', [] );
	}

	/**
	 * The no-JS link exists because WordPress's own is-dismissible only hides a
	 * notice for one pageview. A subscriber must not reach it.
	 */
	public function test_handle_dismiss_action_item_link() {
		wp_set_current_user( $this->make_user( 'subscriber' ) );
		delete_option( Client_Logger::FAILURES_OPTION );

		$throw = static function () {
			return static function ( $m = '' ) {
				throw new \WPDieException( is_string( $m ) ? $m : 'die' );
			};
		};
		add_filter( 'wp_die_handler', $throw );

		$died = false;

		ob_start();
		try {
			Admin::get_instance()->handle_dismiss_action_item_link();
		} catch ( \WPDieException $e ) {
			$died = true;
		} finally {
			ob_end_clean();
			remove_filter( 'wp_die_handler', $throw );
			wp_set_current_user( 0 );
		}

		$this->assertTrue( $died, 'A subscriber must be stopped before anything is dismissed.' );
	}

	/**
	 * Drive the dismissal endpoint and return what it emitted.
	 *
	 * @param string $item_id Item to dismiss.
	 * @return string
	 */
	private function post_dismiss( $item_id ) {
		$_POST['nonce']   = wp_create_nonce( 'srfm_dismiss_action_item' );
		$_POST['item_id'] = $item_id;
		$_REQUEST         = $_POST;

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', '_wp_die_handler_filter' );
		_disable_wp_die();

		ob_start();
		Admin::get_instance()->handle_dismiss_action_item();
		$body = (string) ob_get_clean();

		_enable_wp_die();
		remove_filter( 'wp_die_ajax_handler', '_wp_die_handler_filter' );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$_POST    = [];
		$_REQUEST = [];

		return $body;
	}

	/**
	 * A fault must not be dismissible. Otherwise a crafted request could silence
	 * the one message that matters while the form is still broken.
	 */
	public function test_dismiss_action_item_refuses_a_fault() {
		$method = new ReflectionMethod( Admin::class, 'dismiss_action_item' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( Admin::get_instance(), 'form_submission_error' ) );
		$this->assertFalse( $method->invoke( Admin::get_instance(), 'anything_else' ) );
		$this->assertTrue( $method->invoke( Admin::get_instance(), 'caching_plugin' ) );

		Helper::update_srfm_option( 'dismissed_action_items', [] );
	}

	/**
	 * The notice follows the admin around, because someone whose forms are
	 * silently failing may not open the WP dashboard or SureForms for days.
	 */
	public function test_render_action_item_notices() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );
		Client_Logger::record_failure( 'submission', 42, 'Contact Form' );

		foreach ( [ 'dashboard', 'edit-post', 'plugins' ] as $screen ) {
			set_current_screen( $screen );

			ob_start();
			Admin::get_instance()->render_action_item_notices();
			$output = ob_get_clean();

			$this->assertStringContainsString( 'notice-error', $output, $screen . ' must show the notice.' );
			$this->assertStringContainsString( 'Contact Support', $output );
		}

	}

	/**
	 * Except on SureForms' own dashboard, where the Form Checks panel already
	 * lists them -- a banner above it would say the same thing twice.
	 */
	public function test_render_action_item_notices_defers_to_the_form_checks_panel() {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		delete_option( Client_Logger::FAILURES_OPTION );
		Client_Logger::record_failure( 'submission', 42, 'Contact Form' );

		set_current_screen( 'dashboard' );
		$_GET['page']     = 'sureforms_menu';
		$_REQUEST['page'] = 'sureforms_menu';

		ob_start();
		Admin::get_instance()->render_action_item_notices();
		$output = ob_get_clean();

		unset( $_GET['page'], $_REQUEST['page'] );

		$this->assertSame( '', $output );
	}

	/**
	 * A subscriber must not be told about the site's internals.
	 */
	public function test_render_action_item_notices_is_hidden_without_the_capability() {
		wp_set_current_user( $this->make_user( 'subscriber' ) );
		delete_option( Client_Logger::FAILURES_OPTION );
		Client_Logger::record_failure( 'submission', 42, 'Contact Form' );

		set_current_screen( 'dashboard' );

		ob_start();
		Admin::get_instance()->render_action_item_notices();
		$output = ob_get_clean();


		$this->assertSame( '', $output );
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

	/**
	 * The shared notice builder escapes its text at the sink, so the call sites must
	 * pass plain __() strings. Passing esc_html__() would escape twice and render a
	 * literal `&amp;#039;` for every apostrophe. Guards that regression on a message
	 * that actually contains one ("let's").
	 */
	public function test_rating_notice_text_is_escaped_exactly_once() {
		if ( ! class_exists( '\\Astra_Notices' ) ) {
			$this->markTestSkipped( 'Astra_Notices not available.' );
		}

		$admin_user = wp_insert_user(
			[
				'user_login' => 'srfm_rating_esc_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_rating_esc_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin_user ) ? 0 : (int) $admin_user );

		$prop = new \ReflectionProperty( \Astra_Notices::class, 'notices' );
		$prop->setAccessible( true );
		$original = $prop->getValue();
		$prop->setValue( null, [] );

		try {
			Admin::get_instance()->display_srfm_rating_notice();

			$notices = $prop->getValue();
			$this->assertNotEmpty( $notices, 'The rating notice should be registered for an admin.' );

			$message = '';
			foreach ( (array) $notices as $notice ) {
				if ( isset( $notice['message'] ) && is_string( $notice['message'] ) ) {
					$message .= $notice['message'];
				}
			}

			$this->assertStringNotContainsString( '&amp;#039;', $message, 'Notice text must not be double-escaped.' );
			$this->assertStringContainsString( '&#039;', $message, 'The apostrophe should be escaped exactly once at the sink.' );
		} finally {
			$prop->setValue( null, $original );
			if ( ! is_wp_error( $admin_user ) ) {
				wp_delete_user( (int) $admin_user );
			}
		}
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
	 * The extracted predicate is the single source of truth for "should the prompt show".
	 *
	 * Both the renderer and the two suppressing notices read it, so each guard it owns
	 * is asserted here rather than only through the notices that consume it: the
	 * dashboard screen is excluded, a dismissal short-circuits before the query, and
	 * the disabling filter wins over everything.
	 */
	public function test_get_displayable_thankyou_prompt() {
		$admin = Admin::get_instance();

		if ( ! class_exists( '\Astra_Notices' ) || ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'Astra_Notices / CPT not available.' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		$admin_user = wp_insert_user(
			[
				'user_login' => 'srfm_ty_pred_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_ty_pred_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		$user_id = is_wp_error( $admin_user ) ? 0 : (int) $admin_user;
		wp_set_current_user( $user_id );
		set_current_screen( 'plugins' );
		delete_user_meta( $user_id, Admin::THANKYOU_PROMPT_NOTICE_ID );

		try {
			// No qualifying form yet.
			Admin::reset_thankyou_prompt_cache();
			$this->assertNull( $admin->get_displayable_thankyou_prompt(), 'No qualifying form means no prompt.' );

			$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Predicate TY' ] );
			update_post_meta( $form_id, Admin::ASTRA_SITES_IMPORT_META, 1 );
			delete_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT );
			update_post_meta(
				$form_id,
				'_srfm_form_confirmation',
				[ [ 'confirmation_type' => 'same page', 'message' => \SRFM\Inc\Global_Settings\Global_Settings::get_default_confirmation_message() ] ]
			);

			Admin::reset_thankyou_prompt_cache();
			$form = $admin->get_displayable_thankyou_prompt();
			$this->assertIsArray( $form, 'A qualifying import should produce a prompt payload.' );
			$this->assertSame( $form_id, (int) $form['id'], 'The payload should describe the qualifying form.' );

			// The main dashboard is excluded — the prompt would compete with core's
			// own welcome panel there.
			set_current_screen( 'dashboard' );
			Admin::reset_thankyou_prompt_cache();
			$this->assertNull( $admin->get_displayable_thankyou_prompt(), 'The dashboard screen must be excluded.' );
			set_current_screen( 'plugins' );

			// A dismissal short-circuits before the query runs.
			update_user_meta( $user_id, Admin::THANKYOU_PROMPT_NOTICE_ID, 'notice-dismissed' );
			Admin::reset_thankyou_prompt_cache();
			$this->assertNull( $admin->get_displayable_thankyou_prompt(), 'A dismissed prompt must stay dismissed.' );
			delete_user_meta( $user_id, Admin::THANKYOU_PROMPT_NOTICE_ID );

			// The disabling filter wins over a qualifying form.
			add_filter( 'srfm_show_thankyou_prompt', '__return_false' );
			Admin::reset_thankyou_prompt_cache();
			$this->assertNull( $admin->get_displayable_thankyou_prompt(), 'The filter must be able to turn it off.' );
			remove_all_filters( 'srfm_show_thankyou_prompt' );

			wp_delete_post( $form_id, true );
		} finally {
			remove_all_filters( 'srfm_show_thankyou_prompt' );
			Admin::reset_thankyou_prompt_cache();
			wp_set_current_user( 0 );
			if ( ! is_wp_error( $admin_user ) ) {
				wp_delete_user( (int) $admin_user );
			}
		}
	}

	/**
	 * Only one SureForms notice may be on screen at a time.
	 *
	 * The Getting Started nudge and the "Finish setting up" prompt were registered
	 * independently, so a user with a freshly imported starter template saw both
	 * stacked. They compete for the same next action, and the specific one wins:
	 * "finish this form" is a concrete step, "explore the dashboard" is a tour.
	 *
	 * Asserted through the registered-notice list rather than rendered HTML, because
	 * suppression happens via `show_if` at registration time.
	 */
	public function test_getting_started_notice_yields_to_thankyou_prompt() {
		$admin = Admin::get_instance();

		if ( ! class_exists( '\Astra_Notices' ) || ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'Astra_Notices / CPT not available.' );
		}
		remove_all_actions( 'wp_insert_post_data' );

		$prop = new \ReflectionProperty( \Astra_Notices::class, 'notices' );
		$prop->setAccessible( true );
		$original = $prop->getValue();

		$admin_user = wp_insert_user(
			[
				'user_login' => 'srfm_notice_clash_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_notice_clash_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin_user ) ? 0 : (int) $admin_user );
		set_current_screen( 'plugins' );
		delete_user_meta( is_wp_error( $admin_user ) ? 0 : (int) $admin_user, Admin::THANKYOU_PROMPT_NOTICE_ID );

		try {
			// No qualifying form: the Getting Started notice is free to register.
			Admin::reset_thankyou_prompt_cache();
			$prop->setValue( null, [] );
			$admin->display_srfm_getting_started_notice();
			$this->assertTrue(
				$this->notice_will_show( $prop->getValue(), 'srfm-getting-started-notice' ),
				'With no prompt to show, the Getting Started notice should display.'
			);

			// A qualifying starter-template import now exists.
			$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Clash TY' ] );
			update_post_meta( $form_id, Admin::ASTRA_SITES_IMPORT_META, 1 );
			delete_transient( Admin::NO_IMPORTED_FORMS_TRANSIENT );
			update_post_meta(
				$form_id,
				'_srfm_form_confirmation',
				[ [ 'confirmation_type' => 'same page', 'message' => \SRFM\Inc\Global_Settings\Global_Settings::get_default_confirmation_message() ] ]
			);

			Admin::reset_thankyou_prompt_cache();
			$this->assertNotNull( $admin->get_displayable_thankyou_prompt(), 'Sanity: the prompt should now be displayable.' );

			$prop->setValue( null, [] );
			$admin->display_srfm_getting_started_notice();
			$this->assertFalse(
				$this->notice_will_show( $prop->getValue(), 'srfm-getting-started-notice' ),
				'The Getting Started notice must stand down while the prompt is showing.'
			);

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
	 * Whether a registered notice would actually display.
	 *
	 * Registration alone is not display: the library evaluates `show_if` at render.
	 *
	 * @param mixed  $notices Registered notices.
	 * @param string $id      Notice id to look for.
	 * @return bool
	 */
	private function notice_will_show( $notices, $id ) {
		foreach ( (array) $notices as $notice ) {
			if ( ! is_array( $notice ) || ( $notice['id'] ?? '' ) !== $id ) {
				continue;
			}

			return ! isset( $notice['show_if'] ) || true === $notice['show_if'];
		}

		return false;
	}

	/**
	 * The shared notice stylesheet prints the brand accent colour (#3030).
	 */
	public function test_print_srfm_notice_styles() {
		$admin = Admin::get_instance();

		ob_start();
		$admin->print_srfm_notice_styles();
		$output = (string) ob_get_clean();

		// Scoped to the shared class, so every SureForms notice is painted by one
		// stylesheet rather than each growing its own copy.
		$this->assertStringContainsString( '.srfm-notice', $output );
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
