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
 * ## What Kaiki actually sends
 *
 * `docs/api.md` §8.2 and §8.3, and nothing else — this file once checked an
 * `X-Kaiki-Signature` header holding a bare hex digest, which Kaiki has never
 * sent, so every delivery was refused. The real shape:
 *
 * - `Kaiki-Timestamp: 1785312062` — unix seconds.
 * - `Kaiki-Signature: v1=<hex>` — `HMAC-SHA256(secret, "{timestamp}.{raw body}")`.
 *   During a secret rotation there are two, comma-separated, and **either**
 *   matching is enough.
 * - `Kaiki-Delivery-Id` — stable across retries; the idempotency key.
 * - `Kaiki-Event` — also in the body as `event`.
 *
 * The secret is the one the Kaiki panel shows once when the operator creates
 * the webhook (Ρυθμίσεις → Webhooks), pasted here as «Μυστικό ενημερώσεων».
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
 * captured request being replayed next year. A **seen delivery id** is what stops
 * the same delivery being processed twice, which is not an attack but the
 * ordinary behaviour of a sender that retries.
 *
 * ## 2xx quickly, then the work — and the heavy work is not here at all
 *
 * WPP-8, and §8.4 gives the sender ten seconds. A catalogue event flushes the
 * cache (one delete query) and asks for a trip sync, which {@see \Kaiki\Booking\Seo\Sync}
 * *schedules* rather than runs: four pages of a hundred products inside this
 * request would be a timeout, and a timeout is a retry, and a retry is another
 * sync.
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
	 * Fires after a verified catalogue event, for the sync to schedule itself.
	 */
	public const CATALOGUE_ACTION = 'kaiki_catalogue_changed';

	/**
	 * The only signature scheme Kaiki sends (§8.3).
	 */
	private const SCHEME = 'v1=';

	/**
	 * Five minutes, from WPP-8 and §8.3.
	 */
	private const TOLERANCE = 300;

	/**
	 * How long a delivered id is remembered, so a retry is a no-op.
	 *
	 * Longer than the tolerance, because a request that arrives at the edge of
	 * the window and is retried immediately must still be recognised.
	 */
	private const SEEN_TTL = 900;

	/**
	 * The events that mean the catalogue on this site is out of date.
	 */
	private const CATALOGUE_EVENTS = array( 'product.published', 'product.updated', 'product.unpublished' );

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
	 * Verify a delivery, then act on it.
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
		// would be the mistake this file exists to avoid. WordPress normalises
		// header names, so `kaiki-signature` finds `Kaiki-Signature`.
		$body      = (string) $request->get_body();
		$timestamp = (string) $request->get_header( 'kaiki-timestamp' );
		$signature = (string) $request->get_header( 'kaiki-signature' );

		if ( ! self::timestamp_is_fresh( $timestamp ) ) {
			return new WP_Error( 'kaiki_stale', __( 'That request is too old.', 'kaiki-booking' ), array( 'status' => 400 ) );
		}

		if ( ! self::verify( $secret, $signature, $timestamp, $body ) ) {
			// Recorded rather than merely refused: a stream of forged calls from
			// one address is otherwise invisible, and the address is the only
			// thing that makes it investigable.
			self::log_refusal();

			return new WP_Error( 'kaiki_bad_signature', __( 'That request could not be verified.', 'kaiki-booking' ), array( 'status' => 401 ) );
		}

		$payload = json_decode( $body, true );

		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'kaiki_bad_payload', __( 'That request could not be read.', 'kaiki-booking' ), array( 'status' => 400 ) );
		}

		return self::receive( $payload, (string) $request->get_header( 'kaiki-delivery-id' ) );
	}

	/**
	 * Does any signature in the header match, and is the timestamp fresh?
	 *
	 * Both halves, because either alone is not verification: a valid signature
	 * with an old timestamp is a replay, and a fresh timestamp with no valid
	 * signature is anybody at all. The platform's `WebhookSignature::verify()`
	 * is the same routine, and the two are meant to be read side by side.
	 *
	 * @param string   $secret    The update secret.
	 * @param string   $header    `Kaiki-Signature`: one or more `v1=<hex>`, comma-separated.
	 * @param string   $timestamp `Kaiki-Timestamp`.
	 * @param string   $body      The raw body.
	 * @param int|null $now       The clock, for a test.
	 */
	public static function verify( string $secret, string $header, string $timestamp, string $body, ?int $now = null ): bool {
		if ( '' === $secret || ! self::timestamp_is_fresh( $timestamp, $now ) ) {
			return false;
		}

		$expected = self::SCHEME . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

		foreach ( explode( ',', $header ) as $candidate ) {
			// `hash_equals`, never `===`: an early return on the first wrong byte
			// tells a forger how much of their guess was right.
			if ( hash_equals( $expected, trim( $candidate ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Act on a verified payload.
	 *
	 * Public so the part after verification can be tested without a request;
	 * a delivery reaches it only through {@see self::handle()}.
	 *
	 * @param  array<string, mixed> $payload     The verified body.
	 * @param  string               $delivery_id `Kaiki-Delivery-Id`, or '' to use the body's `id`.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function receive( array $payload, string $delivery_id = '' ) {
		if ( '' === $delivery_id && isset( $payload['id'] ) && is_scalar( $payload['id'] ) ) {
			$delivery_id = (string) $payload['id'];
		}

		if ( '' === $delivery_id ) {
			return new WP_Error( 'kaiki_bad_payload', __( 'That request could not be read.', 'kaiki-booking' ), array( 'status' => 400 ) );
		}

		$seen = Cache::PREFIX . 'seen_' . md5( $delivery_id );

		if ( false !== get_transient( $seen ) ) {
			// A retry of something already handled. 200, because from the
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

		$event     = isset( $payload['event'] ) && is_string( $payload['event'] ) ? $payload['event'] : '';
		$catalogue = in_array( $event, self::CATALOGUE_EVENTS, true );

		if ( $catalogue ) {
			// The trip lists and trip pages this site has cached are now wrong.
			// Coarse on purpose — see `Cache::flush()`.
			Cache::flush();
		}

		// After the flush, which no longer touches this marker (`Cache::flush()`
		// deletes cache keys only) but once did, and let every retry through.
		set_transient( $seen, 1, self::SEEN_TTL );

		if ( $catalogue ) {
			/**
			 * Fires after a verified catalogue event.
			 *
			 * The SEO sync (WPP-6) listens here and schedules a run, which keeps
			 * the webhook path and the cron path the same code.
			 *
			 * @param array<string, mixed> $payload The verified payload.
			 */
			do_action( 'kaiki_catalogue_changed', $payload );
		}

		/**
		 * Fires after any verified delivery from Kaiki, catalogue or not.
		 *
		 * A booking event is acknowledged and otherwise ignored by this plugin;
		 * a site that wants to do something with one can do it here.
		 *
		 * @param array<string, mixed> $payload The verified payload.
		 */
		do_action( 'kaiki_webhook_received', $payload );

		return new WP_REST_Response(
			array(
				'received' => true,
				'handled'  => $catalogue,
			),
			200
		);
	}

	/**
	 * Is this delivery recent enough to act on?
	 *
	 * @param string   $timestamp The unix seconds the sender signed.
	 * @param int|null $now       The clock, for a test.
	 */
	private static function timestamp_is_fresh( string $timestamp, ?int $now = null ): bool {
		if ( '' === $timestamp || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		return abs( ( $now ?? time() ) - (int) $timestamp ) <= self::TOLERANCE;
	}

	/**
	 * Record that somebody sent us something we could not verify (PAY-7's rule,
	 * applied here).
	 */
	private static function log_refusal(): void {
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
	}
}
