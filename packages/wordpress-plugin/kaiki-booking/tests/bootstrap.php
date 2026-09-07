<?php
/**
 * Enough WordPress for the plugin's pure logic to be tested without WordPress.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

/**
 * ## Why stubs rather than a WordPress test harness
 *
 * WPP-15 and ADR-0015 put the plugin's real testing in a Playwright run against
 * a real site, deliberately: a plugin that works in a clean laboratory and not
 * in a themed site with a page builder on it has not been tested at all.
 *
 * But that run needs a WordPress site somebody has to provide, and there are a
 * handful of decisions in this plugin that must not be wrong and do not need
 * WordPress to check — the webhook's signature, its replay window, the cache key
 * that keeps two operators apart, and the locale order. Leaving those to a suite
 * that cannot run yet would mean shipping unverified HMAC verification.
 *
 * So: about forty lines of stubs, an in-memory options table, and the plugin's
 * own classes under test. It is not a WordPress test harness and does not
 * pretend to be one — anything that needs WordPress to *behave* like WordPress
 * belongs in the Playwright run, and this file exists so that the arithmetic and
 * the comparisons do not have to wait for it.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * The in-memory stand-ins for `wp_options` and the transient store.
 *
 * @var array<string, mixed> $kaiki_test_options
 */
$GLOBALS['kaiki_test_options']    = array();
$GLOBALS['kaiki_test_transients'] = array();

/**
 * Empty everything between tests.
 */
function kaiki_test_reset(): void {
	$GLOBALS['kaiki_test_options']    = array();
	$GLOBALS['kaiki_test_transients'] = array();
}

/**
 * @param string $option  The name.
 * @param mixed  $default_value What to return when it is absent.
 * @return mixed
 */
function get_option( string $option, $default_value = false ) {
	return $GLOBALS['kaiki_test_options'][ $option ] ?? $default_value;
}

/**
 * @param string $option The name.
 * @param mixed  $value  The value.
 */
function update_option( string $option, $value ): bool {
	$GLOBALS['kaiki_test_options'][ $option ] = $value;

	return true;
}

function delete_option( string $option ): bool {
	unset( $GLOBALS['kaiki_test_options'][ $option ] );

	return true;
}

/**
 * @param string $transient The name.
 * @return mixed
 */
function get_transient( string $transient ) {
	$entry = $GLOBALS['kaiki_test_transients'][ $transient ] ?? null;

	if ( null === $entry ) {
		return false;
	}

	if ( $entry['expires'] > 0 && $entry['expires'] < time() ) {
		unset( $GLOBALS['kaiki_test_transients'][ $transient ] );

		return false;
	}

	return $entry['value'];
}

/**
 * @param string $transient  The name.
 * @param mixed  $value      The value.
 * @param int    $expiration Seconds, or 0 for never.
 */
function set_transient( string $transient, $value, int $expiration = 0 ): bool {
	$GLOBALS['kaiki_test_transients'][ $transient ] = array(
		'value'   => $value,
		'expires' => $expiration > 0 ? time() + $expiration : 0,
	);

	return true;
}

function delete_transient( string $transient ): bool {
	unset( $GLOBALS['kaiki_test_transients'][ $transient ] );

	return true;
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function get_locale(): string {
	return $GLOBALS['kaiki_test_locale'] ?? 'en_US';
}

/**
 * @param mixed $data The value to encode.
 * @return string|false
 */
function wp_json_encode( $data ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This *is* the stand-in for the alternative.
	return json_encode( $data );
}

/**
 * Polylang's own function, in the global namespace where `function_exists`
 * looks for it.
 *
 * Defined always, and answering `false` unless a test says otherwise — which is
 * indistinguishable from Polylang being installed and having no opinion about
 * this page, and is the branch the plugin has to handle anyway.
 *
 * @param  string $field Which part of the language Polylang should return.
 * @return string|false
 */
function pll_current_language( string $field = 'slug' ) {
	unset( $field );

	return $GLOBALS['kaiki_test_polylang'] ?? false;
}
