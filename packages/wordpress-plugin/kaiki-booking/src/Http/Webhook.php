<?php
/**
 * The endpoint Kaiki calls when something changed.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Http;

use Kaiki\Booking\Cache\Cache;
use Kaiki\Booking\Settings\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The inbound webhook (WPP-7, WPP-8).
 *
 * ## Verified before parsed, and that ordering is the whole thing
 *
 * A payload parsed before it is verified is a payload an attacker chose. It is
 * one line in the wrong order, no test catches it by accident, and the platform's
 * own `GatewayWebhookController` already states the rule — so this file follows
 * it rather than inventing a second opinion.
 *
 * ## Three refusals, and all three are needed
 *
 * A **wrong signature** is the obvious one. A **stale timestamp** is what stops a
 * captured request being replayed next year — the signature stays valid for ever
 * otherwise, because it is a signature over the body and the body has not
 * changed. A **seen event id** is what stops the same delivery being processed
 * twice inside the five-minute window, which is not an attack but the ordinary
 * behaviour of a sender that retries.
 *
 * ## 2xx quickly, then the work
 *
 * WPP-8. A slow endpoint manufactures the retries it then has to deduplicate,
 * and the work here is one delete query — so "quickly" costs nothing and the
 * ordering is still worth stating, because the next person to add something to
 * this method will be adding it in the wrong place.
 *
 * ## No key, no tenant, no cookie
 *
 * The sender has none of them. `permission_callback` returns true because the
 * signature **is** the authorisation, which is the same shape the platform's
 * gateway webhooks use and is worth saying out loud next to a line that
 * otherwise reads as a missing check.
 */
final class Webhook {

	public const NAMESPACE = 'kaiki/v1';

	public const ROUTE = '/webhook';

	/**
	 * Five minutes, from WPP-8.
	 */
	private const TOLERANCE = 300;

	/**
	 * How long a delivered event id is remembered, so a retry is a no-op.
	 *
	 * Longer than the tolerance, because a request that arrives at the edge of
	 * the window and is retried immediately must still be recognised.
	 */
	private const SEEN_TTL = 900;

	/**
	 * Register the route. On every request, not only in the admin.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					self::NAMESPACE,
					self::ROUTE,
					array(
						'methods'             => 'POST',
						'callback'            => array( self::class, 'handle' ),
						// The HMAC is the authorisation. There is no user, no
						// nonce and no cookie on a server-to-server call, and a
						// permission callback that asked for one would refuse
						// every legitimate delivery.
						'permission_callback' => '__return_true',
					)
				);
			}
		);
	}

	/**
	 * Verify a delivery, then bust the cache.
	 *
	 * @param  WP_REST_Request $request The delivery.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ) {
		$secret = Settings::webhook_secret();

		if ( '' === $secret ) {
			// Nothing configured: refuse rather than accept. An endpoint that
			// accepted anything while unconfigured is an endpoint somebody can
			// use to flush a site's cache continuously.
			return new WP_Error( 'kaiki_not_configured', __( 'This site is not set up to receive updates.', 'kaiki-booking' ), array( 'status' => 503 ) );
		}

		// **The raw body, before anything parses it.** `get_json_params()` here
		// would be the mistake this file exists to avoid.
		$body      = (string) $request->get_body();
		$timestamp = (string) $request->get_header( 'x-kaiki-timestamp' );
		$signature = (string) $request->get_header( 'x-kaiki-signature' );

		if ( ! self::timestamp_is_fresh( $timestamp ) ) {
			return new WP_Error( 'kaiki_stale', __( 'That request is too old.', 'kaiki-booking' ), array( 'status' => 400 ) );
		}

		$expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		if ( ! hash_equals( $expected, $signature ) ) {
			// Recorded rather than merely refused: a stream of forged calls from
			// one address is otherwise invisible, and the address is the only
			// thing that makes it investigable.
			self::log_refusal( $request );

			return new WP_Error( 'kaiki_bad_signature', __( 'That request could not be verified.', 'kaiki-booking' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'kaiki_bad_payload', __( 'That request could not be read.', 'kaiki-booking' ), array( 'status' => 400 ) );
		}

		$event_id = isset( $payload['event_id'] ) ? (string) $payload['event_id'] : '';

		if ( '' === $event_id ) {
			return new WP_Error( 'kaiki_bad_payload', __( 'That request could not be read.', 'kaiki-booking' ), array( 'status' => 400 ) );
		}

		if ( false !== get_transient( Cache::PREFIX . 'seen_' . md5( $event_id ) ) ) {
			// A replay of something already handled. 200, because from the
			// sender's side it succeeded — and answering 4xx would make it
			// retry for ever.
			return new WP_REST_Response(
				array(
					'received'  => true,
					'duplicate' => true,
				),
				200
			);
		}

		set_transient( Cache::PREFIX . 'seen_' . md5( $event_id ), 1, self::SEEN_TTL );

		Cache::flush();

		/**
		 * Fires after a verified update from Kaiki.
		 *
		 * The SEO sync (WPP-6) listens here, which is what keeps the webhook
		 * path and the cron path the same code — a webhook that ran its own
		 * version of the sync would drift from the nightly one.
		 *
		 * @param array<string, mixed> $payload The verified payload.
		 */
		do_action( 'kaiki_webhook_received', $payload );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Is this delivery recent enough to act on?
	 *
	 * @param string $timestamp The unix seconds the sender signed.
	 */
	private static function timestamp_is_fresh( string $timestamp ): bool {
		if ( '' === $timestamp || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		return abs( time() - (int) $timestamp ) <= self::TOLERANCE;
	}

	/**
	 * Record that somebody sent us something we could not verify (PAY-7's rule,
	 * applied here).
	 *
	 * @param WP_REST_Request $request The refused delivery.
	 */
	private static function log_refusal( WP_REST_Request $request ): void {
		$refusals = get_transient( Cache::PREFIX . 'refusals' );
		$refusals = is_array( $refusals ) ? $refusals : array();

		// The address and the time, and nothing from the body — an unverified
		// body is an attacker's text, and storing it would put it in front of
		// an administrator later.
		$refusals[] = array(
			'ip'   => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '',
			'when' => time(),
		);

		set_transient( Cache::PREFIX . 'refusals', array_slice( $refusals, -20 ), DAY_IN_SECONDS );

		unset( $request );
	}
}
