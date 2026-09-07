<?php
/**
 * What the plugin remembers between requests.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Cache;

use Kaiki\Booking\Locale\Locale;
use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Transients, keyed so nothing can be served to the wrong person (WPP-7).
 *
 * ## Two entries per read, and the second is the interesting one
 *
 * The **fresh** entry expires on the operator's TTL and is what a page serves.
 * The **last-good** entry expires in a week and is served only when Kaiki cannot
 * be reached at all. A stale trip list is better than a hole in an operator's
 * page; a stale seat count is a boat sold twice, which is why `Client` decides
 * whether a read may fall back and this class only stores what it is given.
 *
 * ## The key is what stops a leak between operators
 *
 * A cache key is a promise about who may see the entry. It carries the path, the
 * query, the **locale** and a hash of the publishable key — so a site that
 * changes its key, or serves two languages, or is somehow configured for two
 * operators, cannot serve one's catalogue to the other's page. The key is
 * hashed, never stored in the clear: a transient name ends up in `wp_options`,
 * which is in every database backup an agency emails around.
 *
 * ## Busted by the webhook, not by being short
 *
 * WPP-7. The TTL is a backstop; the platform tells the site when something
 * changed. That is why the TTL can be generous — a long one costs nothing when
 * correctness comes from the webhook.
 */
final class Cache {

	public const PREFIX = 'kaiki_';

	private const LAST_GOOD_PREFIX = 'kaiki_lg_';

	/**
	 * A week. Long enough to cover an outage nobody is awake for.
	 */
	private const LAST_GOOD_TTL = 604800;

	/**
	 * The key for one read.
	 *
	 * @param string               $path  The API path.
	 * @param array<string, mixed> $query The query parameters.
	 */
	public static function key( string $path, array $query = array() ): string {
		ksort( $query );

		return substr(
			hash(
				'sha256',
				implode(
					'|',
					array(
						$path,
						wp_json_encode( $query ),
						Locale::current(),
						// The key identifies the operator. Hashed with everything
						// else, so the transient name says nothing about it.
						Settings::publishable_key(),
					)
				)
			),
			0,
			32
		);
	}

	/**
	 * The fresh entry for a read, if it has not expired.
	 *
	 * @param  string $key A key from {@see self::key()}.
	 * @return array<mixed>|null
	 */
	public static function get( string $key ): ?array {
		$value = get_transient( self::PREFIX . $key );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Remember an answer twice: once on the operator's TTL, once for a week.
	 *
	 * @param string       $key   A key from {@see self::key()}.
	 * @param array<mixed> $value The decoded response.
	 */
	public static function put( string $key, array $value ): void {
		set_transient( self::PREFIX . $key, $value, Settings::cache_ttl() );
		set_transient( self::LAST_GOOD_PREFIX . $key, $value, self::LAST_GOOD_TTL );
	}

	/**
	 * The last answer Kaiki gave for this read, however old.
	 *
	 * @param  string $key A key from {@see self::key()}.
	 * @return array<mixed>|null
	 */
	public static function last_good( string $key ): ?array {
		$value = get_transient( self::LAST_GOOD_PREFIX . $key );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Forget everything, which is what the webhook asks for.
	 *
	 * Coarse on purpose. The platform's message says *something* about this
	 * operator changed, and working out which of a hundred cached reads it
	 * touched would be a second implementation of the catalogue's shape — one
	 * that is wrong the first time a field moves. Re-reading a trip list is one
	 * request; serving a wrong one is a wrong price on a page.
	 *
	 * The **last-good** entries survive. They are the outage safety net, and an
	 * operator changing a title should not remove it.
	 */
	public static function flush(): int {
		global $wpdb;

		$like          = $wpdb->esc_like( '_transient_' . self::PREFIX ) . '%';
		$timeout_like  = $wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%';
		$spare         = $wpdb->esc_like( '_transient_' . self::LAST_GOOD_PREFIX ) . '%';
		$spare_timeout = $wpdb->esc_like( '_transient_timeout_' . self::LAST_GOOD_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- There is no API that deletes transients by prefix, and an object cache has no enumeration either.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE ( option_name LIKE %s OR option_name LIKE %s )
				   AND option_name NOT LIKE %s
				   AND option_name NOT LIKE %s",
				$like,
				$timeout_like,
				$spare,
				$spare_timeout
			)
		);

		// A persistent object cache holds transients in memory rather than in
		// the options table, so the query above deleted nothing there. Flushing
		// the group is the only instrument WordPress offers.
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}

		return is_int( $deleted ) ? $deleted : 0;
	}
}
