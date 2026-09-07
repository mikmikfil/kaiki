<?php
/**
 * The one way the plugin talks to Kaiki.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Api;

use Kaiki\Booking\Cache\Cache;
use Kaiki\Booking\Locale\Locale;
use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One client, one cache, one failure path (WPP-2, WPP-7, WPP-14).
 *
 * Three call sites with three opinions about caching and failure is how a plugin
 * comes to show a stale price on one page and a fatal error on another. So
 * everything the plugin reads goes through here.
 *
 * ## It asks; it never works anything out
 *
 * WPP-2. No price arithmetic, no availability logic, no second opinion about
 * what a departure costs — the same rule the widget lives under (WGT-13), for
 * the same reason: two implementations of a price is one wrong invoice.
 *
 * ## The publishable key, and only the publishable key
 *
 * ADR-0013 Option A. This class has no way to reach the secret: it does not name
 * it, and {@see Settings::secret_key()} is confined to the three files
 * `SecretKeyScanner` allows. A sync that needs the secret passes it in
 * explicitly, from a file somebody reviewed.
 *
 * ## A bounded deadline, because a slow API must not be a slow site
 *
 * A plugin that holds a page render open while Kaiki thinks is a plugin that
 * makes the whole site feel broken during an incident — and it gets deactivated,
 * which is worse for the operator than a missing booking form.
 */
final class Client {

	/**
	 * Seconds. Short enough that a page still renders; long enough for a real
	 * answer over a slow connection from a Greek shared host.
	 */
	private const TIMEOUT = 6;

	/**
	 * A catalogue read, cached.
	 *
	 * @param string               $path  An API path, beginning with a slash.
	 * @param array<string, mixed> $query Query parameters.
	 */
	public static function get( string $path, array $query = array() ): ApiResult {
		return self::request( $path, $query, true );
	}

	/**
	 * A read that must never be stale.
	 *
	 * Availability is the case: a guest shown seats that are gone books a boat
	 * that is full, and WGT-17's sixty seconds is already the compromise. The
	 * "serve the last good response" rule below is for catalogue reads and
	 * nothing else, and this is how a caller says so.
	 *
	 * @param string               $path  An API path, beginning with a slash.
	 * @param array<string, mixed> $query Query parameters.
	 */
	public static function get_fresh( string $path, array $query = array() ): ApiResult {
		return self::request( $path, $query, false );
	}

	/**
	 * One read, cached or not, with every failure turned into a value.
	 *
	 * @param string               $path      An API path, beginning with a slash.
	 * @param array<string, mixed> $query     Query parameters.
	 * @param bool                 $cacheable Whether the answer may be remembered.
	 */
	private static function request( string $path, array $query, bool $cacheable ): ApiResult {
		if ( ! Settings::is_configured() ) {
			return ApiResult::failed( 'refused' );
		}

		$query['locale'] = $query['locale'] ?? Locale::current();

		$key = Cache::key( $path, $query );

		if ( $cacheable ) {
			$cached = Cache::get( $key );

			if ( null !== $cached ) {
				return ApiResult::ok( $cached );
			}
		}

		$response = wp_remote_get(
			add_query_arg( $query, Settings::api_base() . '/api/v1' . $path ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization'   => 'Bearer ' . Settings::publishable_key(),
					'Accept'          => 'application/json',
					'Accept-Language' => Locale::current(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::fallback( $key, 'unreachable', $cacheable );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			// A refused key is a configuration problem, not an outage. Serving
			// yesterday's catalogue would hide it until the operator noticed
			// bookings had stopped.
			return ApiResult::failed( 'refused' );
		}

		if ( 200 !== $code ) {
			return self::fallback( $key, 'unexpected', $cacheable );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return self::fallback( $key, 'unexpected', $cacheable );
		}

		if ( $cacheable ) {
			Cache::put( $key, $decoded );
		}

		return ApiResult::ok( $decoded );
	}

	/**
	 * The last good answer, when there is one and staleness is acceptable.
	 *
	 * A stale trip list is better than a hole in an operator's page, and this is
	 * the one place in the plugin where that is true. It is not true of
	 * availability, which is why `$cacheable` gates it.
	 *
	 * @param string $key       The cache key this read would have used.
	 * @param string $error     The failure to report if there is nothing stored.
	 * @param bool   $cacheable Whether this read is allowed to be stale.
	 */
	private static function fallback( string $key, string $error, bool $cacheable ): ApiResult {
		if ( $cacheable ) {
			$stale = Cache::last_good( $key );

			if ( null !== $stale ) {
				return ApiResult::ok( $stale, true );
			}
		}

		return ApiResult::failed( $error );
	}
}
