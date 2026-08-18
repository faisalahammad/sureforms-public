<?php
/**
 * Class Test_Post_Types
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Post_Types;

class Test_Post_Types extends TestCase {

	/**
	 * Test register_post_metas is callable.
	 */
	public function test_register_post_metas_callable() {
		$post_types = new Post_Types();
		$post_types->register_post_metas();
		$this->assertTrue( true );
	}

	/**
	 * Test sanitize_form_restriction_data sanitizes meta value.
	 */
	public function test_sanitize_form_restriction_data() {
		$post_types = new Post_Types();
		$result = $post_types->sanitize_form_restriction_data( '' );
		$this->assertIsString( $result );
	}

	/**
	 * Test sureforms_normalize_meta_for_rest normalizes confirmation meta.
	 */
	public function test_sureforms_normalize_meta_for_rest() {
		$post_types = new Post_Types();

		$form_id = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'Normalize Meta Test',
			]
		);

		// Store confirmation meta with missing boolean fields.
		$confirmation = [
			[
				'id'                => 1,
				'confirmation_type' => 'same page',
				'message'           => '<p>Thank you</p>',
			],
		];
		update_post_meta( $form_id, '_srfm_form_confirmation', $confirmation );

		$post     = get_post( $form_id );
		$response = new WP_REST_Response( [ 'meta' => [] ] );

		$result = $post_types->sureforms_normalize_meta_for_rest( $response, $post );

		$data = $result->get_data();
		$this->assertIsArray( $data['meta']['_srfm_form_confirmation'] );
		// Missing booleans should be normalized to false.
		$this->assertFalse( $data['meta']['_srfm_form_confirmation'][0]['hide_copy'] );
		$this->assertFalse( $data['meta']['_srfm_form_confirmation'][0]['hide_download_all'] );

		wp_delete_post( $form_id, true );
	}

	/**
	 * Test sureforms_normalize_meta_for_rest fixes broken SVG data URIs.
	 */
	public function test_sureforms_normalize_meta_for_rest_fixes_svg_data_uri() {
		$post_types = new Post_Types();

		$form_id = wp_insert_post(
			[
				'post_type'   => 'sureforms_form',
				'post_status' => 'publish',
				'post_title'  => 'SVG URI Test',
			]
		);

		// Simulate DOMDocument stripping 'data:' prefix.
		$confirmation = [
			[
				'id'      => 1,
				'message' => '<img src="image/svg+xml;base64,abc123" />',
			],
		];
		update_post_meta( $form_id, '_srfm_form_confirmation', $confirmation );

		$post     = get_post( $form_id );
		$response = new WP_REST_Response( [ 'meta' => [] ] );

		$result = $post_types->sureforms_normalize_meta_for_rest( $response, $post );

		$data = $result->get_data();
		$this->assertStringContainsString( 'data:image/svg+xml;base64', $data['meta']['_srfm_form_confirmation'][0]['message'] );

		wp_delete_post( $form_id, true );
	}

	public function test_register_post_types() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}

		$post_types = new Post_Types();
		$post_types->register_post_types();

		$this->assertTrue( post_type_exists( SRFM_FORMS_POST_TYPE ) );

		$post_type_obj = get_post_type_object( SRFM_FORMS_POST_TYPE );
		$this->assertTrue( $post_type_obj->public );
		$this->assertTrue( $post_type_obj->show_in_rest );
		$this->assertTrue( post_type_supports( SRFM_FORMS_POST_TYPE, 'title' ) );
		$this->assertTrue( post_type_supports( SRFM_FORMS_POST_TYPE, 'editor' ) );
		$this->assertTrue( post_type_supports( SRFM_FORMS_POST_TYPE, 'custom-fields' ) );
	}

	public function test_register_post_metas() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}

		$post_types = new Post_Types();
		$post_types->register_post_metas();

		// Verify scalar metas are registered with correct types.
		$expected_metas = [
			'_srfm_use_label_as_placeholder' => 'boolean',
			'_srfm_submit_button_text'       => 'string',
			'_srfm_is_inline_button'         => 'boolean',
			'_srfm_submit_width_backend'     => 'string',
			'_srfm_button_border_radius'     => 'integer',
			'_srfm_submit_alignment'         => 'string',
			'_srfm_submit_alignment_backend' => 'string',
			'_srfm_submit_width'             => 'string',
			'_srfm_inherit_theme_button'     => 'boolean',
			'_srfm_additional_classes'       => 'string',
			'_srfm_submit_type'              => 'string',
			'_srfm_captcha_security_type'    => 'string',
			'_srfm_form_recaptcha'           => 'string',
			'_srfm_is_ai_generated'          => 'boolean',
		];

		foreach ( $expected_metas as $meta_key => $expected_type ) {
			$registered = registered_meta_key_exists( 'post', $meta_key, SRFM_FORMS_POST_TYPE );
			$this->assertTrue( $registered, "Meta key {$meta_key} should be registered." );
		}

		// Verify object metas are registered.
		$this->assertTrue( registered_meta_key_exists( 'post', '_srfm_form_custom_css', SRFM_FORMS_POST_TYPE ), '_srfm_form_custom_css should be registered.' );
		$this->assertTrue( registered_meta_key_exists( 'post', '_srfm_instant_form_settings', SRFM_FORMS_POST_TYPE ), '_srfm_instant_form_settings should be registered.' );
		$this->assertTrue( registered_meta_key_exists( 'post', '_srfm_forms_styling', SRFM_FORMS_POST_TYPE ), '_srfm_forms_styling should be registered.' );
	}

	public function test_register_post_metas_sanitize_callback_for_custom_css() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}

		$post_types = new Post_Types();
		$post_types->register_post_metas();

		// Custom CSS should strip script tags but preserve safe CSS-related HTML.
		$result = sanitize_meta( '_srfm_form_custom_css', '<style>.test{color:red}</style><script>alert(1)</script>', 'post', SRFM_FORMS_POST_TYPE );
		$this->assertStringNotContainsString( '<script>', $result );
	}

	public function test_register_post_metas_sanitize_callback_for_instant_form() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}

		$post_types = new Post_Types();
		$post_types->register_post_metas();

		$input = [
			'site_logo'              => 'https://example.com/logo.png',
			'site_logo_id'           => 42,
			'cover_type'             => 'image',
			'cover_color'            => '#111C44',
			'cover_image'            => 'https://example.com/cover.jpg',
			'cover_image_id'         => 10,
			'bg_type'                => 'color',
			'bg_color'               => '#ffffff',
			'bg_image'               => 'https://example.com/bg.jpg',
			'bg_image_id'            => 5,
			'enable_instant_form'    => true,
			'form_container_width'   => 800,
			'single_page_form_title' => false,
			'use_banner_as_page_background' => true,
		];

		$result = sanitize_meta( '_srfm_instant_form_settings', $input, 'post', SRFM_FORMS_POST_TYPE );

		$this->assertSame( 'https://example.com/logo.png', $result['site_logo'] );
		$this->assertSame( 42, $result['site_logo_id'] );
		$this->assertSame( 'image', $result['cover_type'] );
		$this->assertTrue( $result['enable_instant_form'] );
		$this->assertSame( 800, $result['form_container_width'] );
		$this->assertFalse( $result['single_page_form_title'] );
		$this->assertTrue( $result['use_banner_as_page_background'] );
	}

	public function test_register_post_metas_instant_form_rejects_invalid_url() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}

		$post_types = new Post_Types();
		$post_types->register_post_metas();

		$input = [
			'site_logo'  => 'javascript:alert(1)',
			'cover_image' => '<script>xss</script>',
		];

		$result = sanitize_meta( '_srfm_instant_form_settings', $input, 'post', SRFM_FORMS_POST_TYPE );

		$this->assertSame( '', $result['site_logo'] );
		$this->assertSame( '', $result['cover_image'] );
	}

	public function test_register_post_metas_instant_form_returns_empty_for_non_array() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}

		$post_types = new Post_Types();
		$post_types->register_post_metas();

		$result = sanitize_meta( '_srfm_instant_form_settings', 'not-an-array', 'post', SRFM_FORMS_POST_TYPE );

		$this->assertSame( [], $result );
	}

	/**
	 * The admin bar "+ New" menu gets a Form node for users who can create forms (#3026).
	 */
	public function test_add_new_form_to_admin_bar_menu_adds_node() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}
		if ( ! class_exists( 'WP_Admin_Bar' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		}

		$post_types = new Post_Types();
		$post_types->register_post_types();
		add_filter( 'show_admin_bar', '__return_true' );

		// An admin can create forms (the CPT maps create_posts to manage_options).
		$admin = wp_insert_user(
			[
				'user_login' => 'srfm_bar_admin_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_bar_admin_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin ) ? 0 : (int) $admin );

		$bar = new WP_Admin_Bar();
		$post_types->add_new_form_to_admin_bar_menu( $bar );

		$node = $bar->get_node( 'new-' . SRFM_FORMS_POST_TYPE );
		$this->assertNotNull( $node, 'Admin should get a Form node under "+ New".' );
		$this->assertSame( 'new-content', $node->parent );
		$this->assertStringContainsString( 'post-new.php?post_type=' . SRFM_FORMS_POST_TYPE, (string) $node->href );

		// A user without the create capability gets no node.
		$subscriber = wp_insert_user(
			[
				'user_login' => 'srfm_bar_sub_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_bar_sub_' . wp_rand() . '@example.com',
				'role'       => 'subscriber',
			]
		);
		wp_set_current_user( is_wp_error( $subscriber ) ? 0 : (int) $subscriber );

		$bar_sub = new WP_Admin_Bar();
		$post_types->add_new_form_to_admin_bar_menu( $bar_sub );
		$this->assertNull( $bar_sub->get_node( 'new-' . SRFM_FORMS_POST_TYPE ), 'A non-privileged user must not get the Form node.' );

		remove_filter( 'show_admin_bar', '__return_true' );
		wp_set_current_user( 0 );
		if ( ! is_wp_error( $admin ) ) {
			wp_delete_user( (int) $admin );
		}
		if ( ! is_wp_error( $subscriber ) ) {
			wp_delete_user( (int) $subscriber );
		}
	}

	/**
	 * The admin bar gets an "Edit Form" node only while viewing a form (#3026 sibling).
	 */
	public function test_add_edit_form_to_admin_bar_menu() {
		if ( ! defined( 'SRFM_FORMS_POST_TYPE' ) ) {
			$this->markTestSkipped( 'SRFM_FORMS_POST_TYPE not defined' );
		}
		if ( ! class_exists( 'WP_Admin_Bar' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		}

		$post_types = new Post_Types();
		$post_types->register_post_types();
		add_filter( 'show_admin_bar', '__return_true' );

		$admin = wp_insert_user(
			[
				'user_login' => 'srfm_edit_bar_' . wp_rand(),
				'user_pass'  => 'password',
				'user_email' => 'srfm_edit_bar_' . wp_rand() . '@example.com',
				'role'       => 'administrator',
			]
		);
		wp_set_current_user( is_wp_error( $admin ) ? 0 : (int) $admin );

		global $post;

		// Viewing a form → an "Edit Form" node is added.
		$form_id = wp_insert_post( [ 'post_type' => SRFM_FORMS_POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Bar edit form' ] );
		$post    = get_post( $form_id );

		$bar = new WP_Admin_Bar();
		$post_types->add_edit_form_to_admin_bar_menu( $bar );

		$node = $bar->get_node( 'edit-form' );
		$this->assertNotNull( $node, 'Viewing a form should add an Edit Form node.' );
		$this->assertStringContainsString( 'Edit Form', (string) $node->title );

		// Viewing a non-form post → no node.
		$page_id = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Not a form' ] );
		$post    = get_post( $page_id );

		$bar_other = new WP_Admin_Bar();
		$post_types->add_edit_form_to_admin_bar_menu( $bar_other );
		$this->assertNull( $bar_other->get_node( 'edit-form' ), 'A non-form post must not add the Edit Form node.' );

		$post = null;
		remove_filter( 'show_admin_bar', '__return_true' );
		wp_delete_post( $form_id, true );
		wp_delete_post( $page_id, true );
		wp_set_current_user( 0 );
		if ( ! is_wp_error( $admin ) ) {
			wp_delete_user( (int) $admin );
		}
	}
}
