<?php
/**
 * The cache key, which is a promise about who may see an entry.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Cache\Cache;
use Kaiki\Booking\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * WPP-7's cache, and the four things that must never share an entry.
 *
 * A cache key is not a performance detail. On a site serving two languages, or
 * one whose key has been changed, the wrong entry is somebody else's catalogue
 * on an operator's page — and it would look completely normal.
 */
final class CacheKeyTest extends TestCase {

	protected function setUp(): void {
		kaiki_test_reset();

		update_option(
			Settings::OPTION,
			array(
				'publishable_key' => 'pk_live_first',
				'api_base'        => 'https://book.kaiki.gr',
				'locale_mode'     => 'en',
				'cache_ttl'       => 300,
			)
		);
	}

	public function test_the_same_read_gives_the_same_key(): void {
		$this->assertSame(
			Cache::key( '/products', array( 'per_page' => 24 ) ),
			Cache::key( '/products', array( 'per_page' => 24 ) )
		);
	}

	public function test_the_order_of_the_query_does_not_change_the_key(): void {
		// Otherwise two call sites asking the same question in a different order
		// each pay for their own request, for ever.
		$this->assertSame(
			Cache::key(
				'/products',
				array(
					'category' => 'shared',
					'per_page' => 24,
				)
			),
			Cache::key(
				'/products',
				array(
					'per_page' => 24,
					'category' => 'shared',
				)
			)
		);
	}

	public function test_a_different_path_is_a_different_key(): void {
		$this->assertNotSame(
			Cache::key( '/products' ),
			Cache::key( '/availability' )
		);
	}

	public function test_a_different_operator_is_a_different_key(): void {
		// The one that matters. A site reconfigured with another operator's key
		// must not serve the first one's catalogue out of the cache.
		$first = Cache::key( '/products' );

		update_option(
			Settings::OPTION,
			array(
				'publishable_key' => 'pk_live_second',
				'api_base'        => 'https://book.kaiki.gr',
				'locale_mode'     => 'en',
			)
		);

		$this->assertNotSame( $first, Cache::key( '/products' ) );
	}

	public function test_a_different_language_is_a_different_key(): void {
		$english = Cache::key( '/products' );

		update_option(
			Settings::OPTION,
			array(
				'publishable_key' => 'pk_live_first',
				'api_base'        => 'https://book.kaiki.gr',
				'locale_mode'     => 'el',
			)
		);

		$this->assertNotSame( $english, Cache::key( '/products' ) );
	}

	public function test_the_key_says_nothing_about_the_operator(): void {
		// A transient name lands in `wp_options`, which is in every database
		// backup an agency emails around. The key is a hash for that reason.
		$key = Cache::key( '/products' );

		$this->assertStringNotContainsString( 'pk_live_first', $key );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $key );
	}

	public function test_the_last_good_entry_outlives_the_fresh_one(): void {
		// The outage safety net: a stale trip list is better than a hole in an
		// operator's page.
		$key = Cache::key( '/products' );

		Cache::put( $key, array( 'data' => array( 'a trip' ) ) );

		$this->assertNotNull( Cache::get( $key ) );

		delete_transient( Cache::PREFIX . $key );

		$this->assertNull( Cache::get( $key ) );
		$this->assertNotNull( Cache::last_good( $key ) );
	}
}
