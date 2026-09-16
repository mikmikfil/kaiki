<?php
/**
 * How a trip is booked, for the page's class.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Seo\TripPostType;
use PHPUnit\Framework\TestCase;

/**
 * One template, two kinds of trip: the class says which.
 */
final class BookingTypeTest extends TestCase {

	public function test_a_quote_trip_is_asked_about(): void {
		$this->assertSame( 'request', TripPostType::booking_type( array( 'mode' => 'quote' ) ) );
	}

	public function test_every_other_trip_is_booked(): void {
		$this->assertSame( 'booking', TripPostType::booking_type( array( 'mode' => 'per_seat' ) ) );
		$this->assertSame( 'booking', TripPostType::booking_type( array( 'mode' => 'per_vessel' ) ) );
		$this->assertSame( 'booking', TripPostType::booking_type( array() ) );
	}

	public function test_it_notices_rewrite_rules_that_lost_the_trips(): void {
		$this->assertTrue( TripPostType::rules_have_base( array( 'ekdromes/([^/]+)/?$' => 'index.php?kaiki_trip=$matches[1]' ), 'ekdromes' ) );
		$this->assertFalse( TripPostType::rules_have_base( array( '(.?.+?)(?:/([0-9]+))?/?$' => 'index.php?pagename=$matches[1]' ), 'ekdromes' ) );
	}
}
