<?php
/**
 * Sections with nothing from Kaiki in them.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Elementor\EmptySections;
use PHPUnit\Framework\TestCase;

/**
 * A container of Kaiki widgets that all came out empty is left out; anything
 * else is left alone.
 */
final class EmptySectionsTest extends TestCase {

	public function test_a_container_whose_kaiki_widgets_are_all_empty_goes(): void {
		$this->assertTrue( EmptySections::hide( 1, 0, true, false ) );
		$this->assertTrue( EmptySections::hide( 3, 0, true, false ) );
	}

	public function test_one_filled_widget_keeps_the_container(): void {
		$this->assertFalse( EmptySections::hide( 3, 1, true, false ) );
	}

	public function test_a_container_with_no_kaiki_widget_is_never_touched(): void {
		$this->assertFalse( EmptySections::hide( 0, 0, true, false ) );
	}

	public function test_the_switch_and_the_editor_keep_it(): void {
		$this->assertFalse( EmptySections::hide( 2, 0, false, false ) );
		$this->assertFalse( EmptySections::hide( 2, 0, true, true ) );
	}

	public function test_nested_containers_keep_their_own_tally(): void {
		EmptySections::reset();

		$outer = new FakeElement( 'container' );
		$inner = new FakeElement( 'container' );

		EmptySections::open( $outer );
		EmptySections::open( $inner );
		EmptySections::report( false );
		$this->assertFalse( EmptySections::close( true, $inner ), 'The inner section with only an empty widget goes.' );

		EmptySections::open( new FakeElement( 'widget' ) );
		EmptySections::report( true );
		$this->assertTrue( EmptySections::close( true, $outer ), 'The outer one also holds a filled widget, so it stays.' );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- A test double used only here.

/**
 * Enough of an Elementor element for the tally.
 */
final class FakeElement {

	/**
	 * @param string $type Element type.
	 */
	public function __construct( private string $type ) {}

	/** Element type. */
	public function get_type(): string {
		return $this->type;
	}

	/**
	 * Settings.
	 *
	 * @return array<string, string>
	 */
	public function get_settings(): array {
		return array();
	}
}
