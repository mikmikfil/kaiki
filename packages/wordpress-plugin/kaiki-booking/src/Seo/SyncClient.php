<?php
/**
 * The one call in this plugin that uses the secret key.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Seo;

use Kaiki\Booking\Api\ApiResult;
use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /api/v1/sync/products`, server-side and nowhere else (WPP-3, WPP-6).
 *
 * Separate from {@see \Kaiki\Booking\Api\Client} deliberately, and the reason is
 * not tidiness. That class is reached from shortcodes, blocks, Elementor widgets
 * and a REST route — every one of them a front-end path — and it *cannot name
 * the secret*, which `SecretKeyScanner` enforces by grep. If the two shared a
 * method, the guard would have to allow the secret in a file every template
 * calls, and the guard would be worth nothing.
 *
 * So this file is the fourth entry on the allow-list, it is called from the
 * cron and webhook paths only, and it has no cache: a sync reads once and
 * writes posts, and a cached feed would mean a webhook firing a re-sync that
 * re-applies yesterday's catalogue.
 *
 * ## No `Origin`, ever
 *
 * The platform refuses a secret key presented with an `Origin` header —
 * `403 secret_key_in_browser` — because a browser always sends one
 * cross-origin. `wp_remote_get` sends none, which is why this works at all, and
 * why it would stop working the moment somebody moved the call into JavaScript.
 * That is the safety net doing its job rather than a coincidence.
 */
final class SyncClient {

	/**
	 * Longer than the front-end client's six seconds.
	 *
	 * Nobody is waiting for a page here — it is cron — and the feed is a
	 * hundred full product payloads, which on a Greek shared host talking to a
	 * server in another country is not a six-second request.
	 */
	private const TIMEOUT = 30;

	/**
	 * One page of the catalogue feed.
	 *
	 * @param string $cursor        `pagination.next_cursor` from the previous page, or ''.
	 * @param string $updated_since `meta.sync_cursor` from the previous run, or ''.
	 */
	public static function page( string $cursor = '', string $updated_since = '' ): ApiResult {
		$secret = Settings::secret_key();

		if ( '' === $secret ) {
			// Not an outage: the operator has not given us a key, or has turned
			// the feature off. Reported as its own failure so the cron can stop
			// rather than retry an hour later with the same nothing.
			return ApiResult::failed( 'refused' );
		}

		$query = array( 'per_page' => 100 );

		if ( '' !== $cursor ) {
			$query['cursor'] = $cursor;
		}

		if ( '' !== $updated_since ) {
			$query['updated_since'] = $updated_since;
		}

		$response = wp_remote_get(
			add_query_arg( $query, Settings::api_base() . '/api/v1/sync/products' ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return ApiResult::failed( 'unreachable' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			// A refused secret is a configuration problem an operator has to
			// fix, and it is the one this feature fails on most: a key pasted
			// into the wrong field, or revoked in the Kaiki panel and not here.
			return ApiResult::failed( 'refused' );
		}

		if ( 200 !== $code ) {
			return ApiResult::failed( 'unexpected' );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return ApiResult::failed( 'unexpected' );
		}

		return ApiResult::ok( $decoded );
	}
}
