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
// A class rather than a function, so it lives in its own file: the WordPress
// standard forbids one file declaring both.
require_once __DIR__ . '/stubs/class-wp-error.php';

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
	$GLOBALS['kaiki_test_blocks']     = array();
}

/**
 * Not WordPress's sanitiser: enough of it that the settings sanitiser runs.
 *
 * @param string $str The value to sanitise.
 */
function sanitize_text_field( string $str ): string {
	return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( $str ) ) );
}

/**
 * @param string $text The value to strip.
 */
function wp_strip_all_tags( string $text ): string {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- This *is* the stand-in for the alternative.
	return strip_tags( $text );
}

/**
 * @param string $url The address to sanitise.
 */
function esc_url_raw( string $url ): string {
	return $url;
}

/**
 * The block registry, which is all `register_block_type` is here: the test
 * wants the attributes a block declares and the callback it renders with.
 *
 * @param  string               $name The block name.
 * @param  array<string, mixed> $args The registration.
 * @return bool
 */
function register_block_type( string $name, array $args = array() ): bool {
	$GLOBALS['kaiki_test_blocks'][ $name ] = $args;

	return true;
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

/**
 * The shortcode registry, which is all `add_shortcode` is.
 *
 * @var array<string, callable>
 */
$GLOBALS['kaiki_test_shortcodes'] = array();

/**
 * @param string   $tag      The shortcode name.
 * @param callable $callback What renders it.
 */
function add_shortcode( string $tag, $callback ): void {
	$GLOBALS['kaiki_test_shortcodes'][ $tag ] = $callback;
}

/**
 * @param  array<string, string> $pairs The defaults.
 * @param  array<string, string> $atts  What was written in the page.
 * @param  string                $shortcode The name, for the filter WordPress fires.
 * @return array<string, string>
 */
function shortcode_atts( array $pairs, array $atts, string $shortcode = '' ): array {
	unset( $shortcode );

	$out = array();

	foreach ( $pairs as $name => $default_value ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? (string) $atts[ $name ] : $default_value;
	}

	return $out;
}

/**
 * Whether the current user may do something.
 *
 * A test sets `$GLOBALS['kaiki_test_can']` to say who is looking. Absent means
 * a visitor, which is the case that matters most: the messages an editor sees
 * must never reach one.
 *
 * @param string $capability The capability being asked about.
 */
function current_user_can( string $capability ): bool {
	unset( $capability );

	return (bool) ( $GLOBALS['kaiki_test_can'] ?? false );
}

/**
 * @param string $text The value to escape.
 */
function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * @param string $text The value to escape.
 */
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

/**
 * @param string $url The value to escape.
 */
function esc_url( string $url ): string {
	return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
}

/**
 * @param string $text   The string to translate.
 * @param string $domain The text domain.
 */
function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
}

/**
 * @param string $text   The string to translate and escape.
 * @param string $domain The text domain.
 */
function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( __( $text, $domain ) );
}

/**
 * @param string $key The value to reduce to a key.
 */
function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
}

/*
|--------------------------------------------------------------------------
| An in-memory `wp_posts`, for the SEO sync (#116)
|--------------------------------------------------------------------------
|
| The same bargain the rest of this file makes, and it is worth restating
| because this part of it is the largest. What the sync decides — whether a
| tombstone trashes or unpublishes, whether an operator's edited excerpt
| survives, whether an interrupted run resumes or restarts, whether one product
| in two languages is two posts or one overwritten twice — is arithmetic over
| arrays, and every one of those decisions is wrong in a way no reviewer would
| notice by reading.
|
| What is *not* here is WordPress: no rewrite rules, no `meta_query` engine
| beyond exact matches, no filters, no capabilities. A theme override, a slug
| collision with a real page, and whether Polylang links two posts as
| translations of each other are all WPP-15's Playwright run against a real
| site, because they are questions about WordPress rather than about this code.
*/

/**
 * @var array<int, array<string, mixed>> $kaiki_test_posts
 */
$GLOBALS['kaiki_test_posts']     = array();
$GLOBALS['kaiki_test_post_meta'] = array();
$GLOBALS['kaiki_test_next_id']   = 1;
$GLOBALS['kaiki_test_hooks']     = array();
$GLOBALS['kaiki_test_cron']      = array();

/**
 * Empty the posts table too.
 */
function kaiki_test_reset_posts(): void {
	$GLOBALS['kaiki_test_posts']         = array();
	$GLOBALS['kaiki_test_post_meta']     = array();
	$GLOBALS['kaiki_test_next_id']       = 1;
	$GLOBALS['kaiki_test_hooks']         = array();
	$GLOBALS['kaiki_test_cron']          = array();
	$GLOBALS['kaiki_test_http']          = array();
	$GLOBALS['kaiki_test_http_requests'] = array();
}

/**
 * @param  array<string, mixed> $postarr  The post.
 * @param  bool                 $wp_error Whether to return an error object.
 * @return int
 */
function wp_insert_post( array $postarr, bool $wp_error = false ) {
	unset( $wp_error );

	$id = $GLOBALS['kaiki_test_next_id']++;

	$GLOBALS['kaiki_test_posts'][ $id ] = array_merge(
		array(
			'ID'           => $id,
			'post_title'   => '',
			'post_name'    => '',
			'post_content' => '',
			'post_excerpt' => '',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		),
		$postarr
	);

	return $id;
}

/**
 * @param  array<string, mixed> $postarr  The changes, including `ID`.
 * @param  bool                 $wp_error Whether to return an error object.
 * @return int
 */
function wp_update_post( array $postarr, bool $wp_error = false ) {
	unset( $wp_error );

	$id = (int) ( $postarr['ID'] ?? 0 );

	if ( ! isset( $GLOBALS['kaiki_test_posts'][ $id ] ) ) {
		return 0;
	}

	$GLOBALS['kaiki_test_posts'][ $id ] = array_merge( $GLOBALS['kaiki_test_posts'][ $id ], $postarr );

	return $id;
}

/**
 * @param int $id The post.
 */
function wp_trash_post( int $id ): bool {
	if ( ! isset( $GLOBALS['kaiki_test_posts'][ $id ] ) ) {
		return false;
	}

	$GLOBALS['kaiki_test_posts'][ $id ]['post_status'] = 'trash';

	return true;
}

/**
 * Exact-match `meta_query` and nothing else, which is all the sync asks for.
 *
 * @param  array<string, mixed> $args The query.
 * @return list<int>
 */
function get_posts( array $args = array() ): array {
	$wanted = array();

	foreach ( (array) ( $args['meta_query'] ?? array() ) as $clause ) {
		if ( is_array( $clause ) && isset( $clause['key'] ) ) {
			$wanted[ (string) $clause['key'] ] = (string) ( $clause['value'] ?? '' );
		}
	}

	$out = array();

	foreach ( $GLOBALS['kaiki_test_posts'] as $id => $post ) {
		if ( isset( $args['post_type'] ) && $post['post_type'] !== $args['post_type'] ) {
			continue;
		}

		$matches = true;

		foreach ( $wanted as $key => $value ) {
			if ( (string) ( $GLOBALS['kaiki_test_post_meta'][ $id ][ $key ] ?? '' ) !== $value ) {
				$matches = false;

				break;
			}
		}

		if ( $matches ) {
			$out[] = (int) $id;
		}
	}

	return $out;
}

/**
 * @param  int $id The post.
 * @return string|false
 */
function get_post_status( int $id ) {
	return isset( $GLOBALS['kaiki_test_posts'][ $id ] )
		? (string) $GLOBALS['kaiki_test_posts'][ $id ]['post_status']
		: false;
}

/**
 * @param  string $field The field name.
 * @param  int    $id    The post.
 * @return string
 */
function get_post_field( string $field, int $id ): string {
	return (string) ( $GLOBALS['kaiki_test_posts'][ $id ][ $field ] ?? '' );
}

/**
 * @param  int    $id     The post.
 * @param  string $key    The meta key.
 * @param  bool   $single Whether to return one value.
 * @return mixed
 */
function get_post_meta( int $id, string $key = '', bool $single = false ) {
	unset( $single );

	return $GLOBALS['kaiki_test_post_meta'][ $id ][ $key ] ?? '';
}

/**
 * @param int    $id    The post.
 * @param string $key   The meta key.
 * @param mixed  $value The value.
 */
function update_post_meta( int $id, string $key, $value ): bool {
	$GLOBALS['kaiki_test_post_meta'][ $id ][ $key ] = $value;

	return true;
}

/**
 * @param mixed $thing Anything.
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/**
 * Not WordPress's filter. Enough of it that a test can tell filtered output
 * from escaped output, which is the distinction the sync gets right or wrong.
 *
 * @param string $data The HTML to filter.
 */
function wp_kses_post( string $data ): string {
	return strip_tags( $data, '<p><br><strong><em><ul><ol><li><h2><h3><a>' );
}

/**
 * @param string $title The value to reduce to a slug.
 */
function sanitize_title( string $title ): string {
	$title = strtolower( trim( $title ) );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title ) ?? '';

	return trim( $title, '-' );
}

/**
 * @param string   $hook     The hook name.
 * @param callable $callback What to run.
 * @param int      $priority The order.
 * @param int      $args     How many arguments.
 */
function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	unset( $priority, $args );

	$GLOBALS['kaiki_test_hooks'][ $hook ][] = $callback;
}

/**
 * @param string   $hook     The hook name.
 * @param callable $callback What to run.
 * @param int      $priority The order.
 * @param int      $args     How many arguments.
 */
function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
	add_action( $hook, $callback, $priority, $args );
}

/**
 * @param string $hook The hook name.
 */
function has_filter( string $hook ): bool {
	return isset( $GLOBALS['kaiki_test_hooks'][ $hook ] );
}

/**
 * @param  string $hook  The hook name.
 * @param  mixed  $value The value being filtered.
 * @return mixed
 */
function apply_filters( string $hook, $value ) {
	unset( $hook );

	return $value;
}

/**
 * @param  string $hook The scheduled hook.
 * @return int|false
 */
function wp_next_scheduled( string $hook ) {
	return $GLOBALS['kaiki_test_cron'][ $hook ] ?? false;
}

/**
 * @param int    $timestamp  When.
 * @param string $recurrence How often.
 * @param string $hook       The hook.
 */
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
	unset( $recurrence );

	$GLOBALS['kaiki_test_cron'][ $hook ] = $timestamp;

	return true;
}

/**
 * @param int    $timestamp When it was scheduled for.
 * @param string $hook      The hook.
 */
function wp_unschedule_event( int $timestamp, string $hook ): bool {
	unset( $timestamp );

	unset( $GLOBALS['kaiki_test_cron'][ $hook ] );

	return true;
}

/*
|--------------------------------------------------------------------------
| An HTTP layer that answers from a queue
|--------------------------------------------------------------------------
|
| `$GLOBALS['kaiki_test_http']` is a list of responses, taken in order. What
| this buys is the only thing about the sync that cannot be tested one row at a
| time: the *loop* — that an interrupted run resumes where it stopped rather
| than restarting, that the high-water mark does not move past products nobody
| wrote, and that a page applied twice changes nothing the second time.
|
| `$GLOBALS['kaiki_test_http_requests']` records the URLs asked for, because
| "did it send the cursor" is the assertion, and the request is where it is.
*/

$GLOBALS['kaiki_test_http']          = array();
$GLOBALS['kaiki_test_http_requests'] = array();

/**
 * Queue one answer.
 *
 * @param int   $code The status.
 * @param array<string, mixed>|null $body The JSON body, or null for something unparseable.
 */
function kaiki_test_http_queue( int $code, ?array $body = null ): void {
	$GLOBALS['kaiki_test_http'][] = array(
		'code' => $code,
		'body' => null === $body ? 'not json' : (string) wp_json_encode( $body ),
	);
}

/**
 * @param  array<string, mixed> $args  The parameters.
 * @param  string               $url   The address.
 * @return string
 */
function add_query_arg( array $args, string $url ): string {
	return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args );
}

/**
 * @param  string               $url  The address.
 * @param  array<string, mixed> $args The request.
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_get( string $url, array $args = array() ) {
	$GLOBALS['kaiki_test_http_requests'][] = array(
		'url'     => $url,
		'headers' => $args['headers'] ?? array(),
	);

	$next = array_shift( $GLOBALS['kaiki_test_http'] );

	if ( null === $next ) {
		return new WP_Error( 'http_request_failed', 'Nothing queued.' );
	}

	return $next;
}

/**
 * @param  array<string, mixed>|WP_Error $response The response.
 * @return int
 */
function wp_remote_retrieve_response_code( $response ): int {
	return is_array( $response ) ? (int) $response['code'] : 0;
}

/**
 * @param  array<string, mixed>|WP_Error $response The response.
 * @return string
 */
function wp_remote_retrieve_body( $response ): string {
	return is_array( $response ) ? (string) $response['body'] : '';
}
