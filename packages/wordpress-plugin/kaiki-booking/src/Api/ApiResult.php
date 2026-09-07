<?php
/**
 * What a call to Kaiki gave back, including the ways it did not.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Api;

defined( 'ABSPATH' ) || exit;

/**
 * A result, or a failure that a page can survive (WPP-14).
 *
 * > *"The plugin surfaces API errors as a localised admin notice for editors and
 * > a neutral message for visitors. A platform outage MUST NOT produce a PHP
 * > fatal error or a blank page."*
 *
 * Returning a value rather than throwing is what makes that easy to obey. An
 * exception in a shortcode callback is a fatal error on an operator's page —
 * WordPress does not catch it for you — and every call site would have to
 * remember a `try`. A caller that ignores the failure here renders an empty list
 * instead of a white screen, which is the right way round for the mistake to go.
 */
final class ApiResult {

	/**
	 * Made through {@see self::ok()} and {@see self::failed()}, never directly.
	 *
	 * @param array<mixed>|null $data  The decoded payload, when there is one.
	 * @param string            $error A machine-readable failure, or ''.
	 * @param bool              $stale Whether this came from the last-good store.
	 */
	private function __construct(
		public readonly ?array $data,
		public readonly string $error,
		public readonly bool $stale,
	) {}

	/**
	 * An answer.
	 *
	 * @param array<mixed> $data  The decoded payload.
	 * @param bool         $stale Whether it came from the last-good store.
	 */
	public static function ok( array $data, bool $stale = false ): self {
		return new self( $data, '', $stale );
	}

	/**
	 * A failure, named so a caller can tell them apart.
	 *
	 * `unreachable`, `refused`, `unexpected`. Three because they need three
	 * different sentences and, for an editor, three different actions.
	 *
	 * @param string $error Which of the three it is.
	 */
	public static function failed( string $error ): self {
		return new self( null, $error, false );
	}

	/**
	 * Did the call succeed?
	 *
	 * Named `succeeded` rather than `ok` because {@see self::ok()} is the
	 * factory, and PHP will not have both.
	 */
	public function succeeded(): bool {
		return '' === $this->error;
	}

	/**
	 * The payload's `data` key, or an empty array.
	 *
	 * Every Kaiki response is `{"data": …}`, and a caller wanting the inside of
	 * that should not have to check twice.
	 *
	 * @return array<mixed>
	 */
	public function payload(): array {
		$data = $this->data['data'] ?? null;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * What to show a visitor: nothing about us, and nothing alarming.
	 */
	public function visitor_message(): string {
		return __( 'Bookings are briefly unavailable. Please try again in a moment, or contact us directly.', 'kaiki-booking' );
	}

	/**
	 * What to show an editor: which of the three problems it is.
	 */
	public function editor_message(): string {
		switch ( $this->error ) {
			case 'unreachable':
				return __( 'Kaiki Booking: this site could not reach Kaiki. Visitors are seeing a short message instead of the booking form.', 'kaiki-booking' );

			case 'refused':
				return __( 'Kaiki Booking: Kaiki refused the key. Check it under Settings, and check that this site is on the key\'s list of allowed addresses.', 'kaiki-booking' );

			default:
				return __( 'Kaiki Booking: Kaiki answered unexpectedly. If this continues, the address under Settings may be wrong.', 'kaiki-booking' );
		}
	}
}
