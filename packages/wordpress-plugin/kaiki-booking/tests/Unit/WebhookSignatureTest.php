<?php
/**
 * The webhook's verification, which must not be wrong.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * WPP-8's three refusals, checked one at a time.
 *
 * The signature itself is verified through the same expression the handler uses,
 * reached by reflection rather than retyped — a test that recomputed the HMAC
 * its own way would pass against a handler that computed a different one, which
 * is the failure mode that matters here.
 *
 * What is **not** tested here is the handler end to end: that needs
 * `WP_REST_Request`, and a stub of it would be a second WordPress. The e2e run
 * exercises the route; this exercises the arithmetic.
 */
final class WebhookSignatureTest extends TestCase {

	private const SECRET = 'whsec_a_shared_secret';

	protected function setUp(): void {
		kaiki_test_reset();
	}

	public function test_a_signature_over_the_timestamp_and_body_matches(): void {
		$timestamp = (string) time();
		$body      = '{"event_id":"evt_1","type":"catalogue.changed"}';

		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET );

		$this->assertTrue( hash_equals( $signature, hash_hmac( 'sha256', $timestamp . '.' . $body, self::SECRET ) ) );
	}

	public function test_a_body_altered_after_signing_does_not_match(): void {
		// The whole point of signing the body rather than a header: a proxy, a
		// content filter or an attacker changing one character invalidates it.
		$timestamp = (string) time();
		$signed    = hash_hmac( 'sha256', $timestamp . '.{"event_id":"evt_1"}', self::SECRET );
		$tampered  = hash_hmac( 'sha256', $timestamp . '.{"event_id":"evt_2"}', self::SECRET );

		$this->assertFalse( hash_equals( $signed, $tampered ) );
	}

	public function test_the_timestamp_is_part_of_what_is_signed(): void {
		// Otherwise a captured request replays for ever: the body has not
		// changed, so the signature stays valid indefinitely.
		$body = '{"event_id":"evt_1"}';

		$now   = hash_hmac( 'sha256', '1000000000.' . $body, self::SECRET );
		$later = hash_hmac( 'sha256', '1000000060.' . $body, self::SECRET );

		$this->assertFalse( hash_equals( $now, $later ) );
	}

	/**
	 * @dataProvider timestamps
	 */
	public function test_only_a_recent_timestamp_is_accepted( string $timestamp, bool $expected ): void {
		$method = new ReflectionMethod( \Kaiki\Booking\Http\Webhook::class, 'timestamp_is_fresh' );
		$method->setAccessible( true );

		$this->assertSame( $expected, $method->invoke( null, $timestamp ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function timestamps(): array {
		$now = time();

		return array(
			'now'               => array( (string) $now, true ),
			'a minute ago'      => array( (string) ( $now - 60 ), true ),
			'four minutes ago'  => array( (string) ( $now - 240 ), true ),
			'six minutes ago'   => array( (string) ( $now - 360 ), false ),
			// A clock ahead of ours is as suspicious as one behind, and a
			// sender whose clock is wrong is a sender we cannot verify.
			'six minutes ahead' => array( (string) ( $now + 360 ), false ),
			'not a number'      => array( 'yesterday', false ),
			'empty'             => array( '', false ),
			// Negative, because `ctype_digit` is what rejects it and a `(int)`
			// cast alone would have accepted `-1` as a plausible time.
			'negative'          => array( '-100', false ),
		);
	}
}
