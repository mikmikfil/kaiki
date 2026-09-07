<?php
/**
 * Enough of `WP_Error` for `is_wp_error()` to recognise one.
 *
 * In a file of its own because the WordPress standard forbids a file that
 * declares both functions and classes, and the rest of the bootstrap is forty
 * functions.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.NamingConventions.ValidFunctionName.FunctionDoubleUnderscore -- A constructor.
// phpcs:disable PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.FunctionDoubleUnderscore -- A constructor.

/**
 * A failure, as WordPress passes one around.
 */
class WP_Error {

	/**
	 * A code and a message, which is all anything here reads.
	 *
	 * @param string $code    The code.
	 * @param string $message The message.
	 */
	public function __construct( public string $code = '', public string $message = '' ) {
	}
}
