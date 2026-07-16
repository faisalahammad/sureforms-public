<?php
/**
 * Tests for Form_Metadata trait.
 *
 * @package sureforms
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use SRFM\Inc\Abilities\Forms\Create_Form;

/**
 * Test_Form_Metadata class.
 *
 * Uses Create_Form as a concrete class that uses the Form_Metadata trait.
 */
class Test_Form_Metadata extends TestCase {

	/**
	 * Ability instance that uses the trait.
	 *
	 * @var Create_Form
	 */
	protected $ability;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->ability = new Create_Form();
	}

	/**
	 * Test apply_metadata_overrides processes metadata.
	 */
	public function test_apply_metadata_overrides() {
		$reflection = new \ReflectionMethod( $this->ability, 'apply_metadata_overrides' );
		$reflection->setAccessible( true );
		$this->assertTrue( $reflection->isProtected() );
	}

	/**
	 * Test formStyling.disableDefaultStyles maps to the _srfm_forms_styling meta flag.
	 */
	public function test_form_styling_disable_default_styles_maps_to_meta() {
		$reflection = new \ReflectionMethod( $this->ability, 'apply_metadata_overrides' );
		$reflection->setAccessible( true );

		// Enable it, and confirm it merges into existing styling data.
		$result = $reflection->invoke(
			$this->ability,
			[ '_srfm_forms_styling' => [ 'primary_color' => '#111C44' ] ],
			[ 'formStyling' => [ 'disableDefaultStyles' => true ] ]
		);
		$this->assertTrue( $result['_srfm_forms_styling']['disable_default_styles'] );
		$this->assertSame( '#111C44', $result['_srfm_forms_styling']['primary_color'] );

		// Explicit false is respected (not dropped like empty values).
		$result = $reflection->invoke(
			$this->ability,
			[],
			[ 'formStyling' => [ 'disableDefaultStyles' => false ] ]
		);
		$this->assertFalse( $result['_srfm_forms_styling']['disable_default_styles'] );
	}

	/**
	 * styling.submitAlignment and the formStyling object map independently in
	 * one apply_metadata_overrides() call — guards the documented split.
	 */
	public function test_styling_and_form_styling_map_independently() {
		$reflection = new \ReflectionMethod( $this->ability, 'apply_metadata_overrides' );
		$reflection->setAccessible( true );

		$result = $reflection->invoke(
			$this->ability,
			[],
			[
				'styling'     => [ 'submitAlignment' => 'center' ],
				'formStyling' => [
					'primaryColor'         => '#123456',
					'disableDefaultStyles' => true,
				],
			]
		);

		$this->assertSame( 'center', $result['_srfm_submit_alignment'] );
		$this->assertSame( '#123456', $result['_srfm_forms_styling']['primary_color'] );
		$this->assertTrue( $result['_srfm_forms_styling']['disable_default_styles'] );
	}
}
