<?php
/**
 * The webhook's verification, which must not be wrong.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Http\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * WPP-8's refusals, against the header Kaiki actually sends.
 *
 * The expected signature is computed here the way `docs/api.md` §8.3 and the
 * platform's `WebhookSignature::sign()` compute it — `v1=` and an HMAC over
 * `"{timestamp}.{body}"` — and handed to the plugin's own {@see Webhook::verify()}.
 * That is the point: the previous receiver agreed with a test that computed a
 * bare hex digest its own way, and refused every delivery Kaiki ever made.
 *
 * What is **not** tested here is the route end to end: that needs
 * `WP_REST_Request`, and a stub of it would be a second WordPress.
 */
final class WebhookSignatureTest extends TestCase {

	private const SECRET = 'a3f1c0de9b8e7d6c5b4a39281706f5e4d3c2b1a0987654321fedcba012345678';

	private const BODY = '{"id":"5f0c2d9e","event":"product.updated","data":{"product":{"uuid":"7c9e6679"}}}';

	private const NOW = 1785312062;

	protected function setUp(): void {
		kaiki_test_reset();
	}

	/**
	 * What Kaiki's `WebhookSignature::sign()` produces.
	 *
	 * @param string $secret    The secret.
	 * @param int    $timestamp The signed time.
	 * @param string $body      The raw body.
	 */
	private static function kaiki_sign( string $secret, int $timestamp, string $body ): string {
		return 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
	}

	public function test_a_signature_as_kaiki_sends_it_is_accepted(): void {
		$header = self::kaiki_sign( self::SECRET, self::NOW, self::BODY );

		$this->assertTrue( Webhook::verify( self::SECRET, $header, (string) self::NOW, self::BODY, self::NOW ) );
	}

	public function test_either_signature_is_enough_during_a_rotation(): void {
		// §8.3: `v1=<new>,v1=<old>` for a day, so the site that still holds the
		// old secret keeps working until somebody pastes the new one.
		$header = self::kaiki_sign( 'the-new-secret', self::NOW, self::BODY ) . ', ' . self::kaiki_sign( self::SECRET, self::NOW, self::BODY );

		$this->assertTrue( Webhook::verify( self::SECRET, $header, (string) self::NOW, self::BODY, self::NOW ) );
	}

	public function test_the_wrong_secret_is_refused(): void {
		$header = self::kaiki_sign( 'somebody-else', self::NOW, self::BODY );

		$this->assertFalse( Webhook::verify( self::SECRET, $header, (string) self::NOW, self::BODY, self::NOW ) );
	}

	public function test_a_bare_hex_digest_is_refused(): void {
		// The format this receiver used to expect, and Kaiki never sent. No
		// scheme prefix is not a signature of any version we know.
		$header = hash_hmac( 'sha256', self::NOW . '.' . self::BODY, self::SECRET );

		$this->assertFalse( Webhook::verify( self::SECRET, $header, (string) self::NOW, self::BODY, self::NOW ) );
	}

	public function test_an_unknown_scheme_is_refused(): void {
		$header = 'v0=' . hash_hmac( 'sha256', self::NOW . '.' . self::BODY, self::SECRET );

		$this->assertFalse( Webhook::verify( self::SECRET, $header, (string) self::NOW, self::BODY, self::NOW ) );
	}

	public function test_a_body_altered_after_signing_is_refused(): void {
		$header = self::kaiki_sign( self::SECRET, self::NOW, self::BODY );

		$this->assertFalse( Webhook::verify( self::SECRET, $header, (string) self::NOW, str_replace( 'updated', 'published', self::BODY ), self::NOW ) );
	}

	public function test_the_timestamp_is_part_of_what_is_signed(): void {
		// Signed at one time, presented as another: a replay with the header
		// edited to look fresh.
		$header = self::kaiki_sign( self::SECRET, self::NOW - 600, self::BODY );

		$this->assertFalse( Webhook::verify( self::SECRET, $header, (string) self::NOW, self::BODY, self::NOW ) );
	}

	public function test_an_empty_header_or_secret_is_refused(): void {
		$header = self::kaiki_sign( '', self::NOW, self::BODY );

		$this->assertFalse( Webhook::verify( self::SECRET, '', (string) self::NOW, self::BODY, self::NOW ) );
		$this->assertFalse( Webhook::verify( '', $header, (string) self::NOW, self::BODY, self::NOW ) );
	}

	/**
	 * A correctly signed delivery, judged only on how old it is.
	 *
	 * @dataProvider timestamps
	 *
	 * @param int  $offset   Seconds from now the delivery was signed.
	 * @param bool $expected Whether it is accepted.
	 */
	public function test_only_a_recent_timestamp_is_accepted( int $offset, bool $expected ): void {
		$signed = self::NOW + $offset;
		$header = self::kaiki_sign( self::SECRET, $signed, self::BODY );

		$this->assertSame( $expected, Webhook::verify( self::SECRET, $header, (string) $signed, self::BODY, self::NOW ) );
	}

	/**
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public static function timestamps(): array {
		return array(
			'now'               => array( 0, true ),
			'four minutes ago'  => array( -240, true ),
			'five minutes ago'  => array( -300, true ),
			'six minutes ago'   => array( -360, false ),
			// A clock ahead of ours is as suspicious as one behind.
			'six minutes ahead' => array( 360, false ),
		);
	}

	/**
	 * @dataProvider malformed_timestamps
	 *
	 * @param string $timestamp What arrived in `Kaiki-Timestamp`.
	 */
	public function test_a_malformed_timestamp_is_refused( string $timestamp ): void {
		$header = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . self::BODY, self::SECRET );

		$this->assertFalse( Webhook::verify( self::SECRET, $header, $timestamp, self::BODY, self::NOW ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_timestamps(): array {
		return array(
			'not a number' => array( 'yesterday' ),
			'empty'        => array( '' ),
			// `ctype_digit` is what rejects it; a `(int)` cast alone would not.
			'negative'     => array( '-100' ),
		);
	}
}
