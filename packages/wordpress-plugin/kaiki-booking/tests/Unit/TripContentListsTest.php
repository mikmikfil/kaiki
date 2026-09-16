<?php
/**
 * The trip's own lists, read from Kaiki.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Trip\Trip;
use PHPUnit\Framework\TestCase;

/**
 * Highlights, included, not included, bring and the itinerary — all optional in
 * Kaiki, and an empty one is simply an empty list.
 */
final class TripContentListsTest extends TestCase {

	protected function setUp(): void {
		kaiki_test_reset();
	}

	public function test_every_list_is_read_from_its_kaiki_field(): void {
		$product = array(
			'highlights'    => array( 'Three coves', '' ),
			'includes'      => "Coffee\nSnorkels",
			'excludes'      => array(),
			'what_to_bring' => null,
		);

		$this->assertSame( array( 'Three coves' ), Trip::items( $product, 'highlights' ) );
		$this->assertSame( array( 'Coffee', 'Snorkels' ), Trip::items( $product, 'includes' ) );
		$this->assertSame( array(), Trip::items( $product, 'excludes' ) );
		$this->assertSame( array(), Trip::items( $product, 'bring' ) );
	}

	public function test_an_itinerary_stop_carries_its_time_description_and_length(): void {
		$steps = Trip::itinerary(
			array(
				'itinerary_stops' => array(
					array(
						'key'              => 'a',
						'name'             => 'Board',
						'time'             => '09:00',
						'description'      => null,
						'duration_minutes' => null,
					),
					array(
						'key'              => 'b',
						'name'             => 'Blue Cave',
						'description'      => 'Swim inside.',
						'duration_minutes' => 45,
					),
					array(
						'key'  => 'c',
						'name' => '',
					),
				),
			)
		);

		$this->assertCount( 2, $steps );
		$this->assertSame( '09:00', $steps[0]['time'] );
		$this->assertSame( 'Swim inside.', $steps[1]['description'] );
		$this->assertSame( '45 minutes', $steps[1]['duration'] );
	}
}
