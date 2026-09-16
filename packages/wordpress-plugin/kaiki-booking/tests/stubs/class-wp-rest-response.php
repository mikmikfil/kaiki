<?php
/**
 * Enough of `WP_REST_Response` for a test to read the status and the body.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore -- A constructor.
// phpcs:disable PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.FunctionDoubleUnderscore -- A constructor.

/**
 * A REST answer, as a route callback returns one.
 */
class WP_REST_Response {

	/**
	 * The body and the status, which is all anything here reads.
	 *
	 * @param mixed $data   The body.
	 * @param int   $status The HTTP status.
	 */
	public function __construct( public $data = null, public int $status = 200 ) {
	}
}
