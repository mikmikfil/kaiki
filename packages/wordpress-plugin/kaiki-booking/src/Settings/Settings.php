<?php
/**
 * Reading and writing what the operator configured.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's settings, as one typed reader (WPP-3, ADR-0013 Option A).
 *
 * ## Two options, and the split is the security model
 *
 * `kaiki_settings` holds everything an operator configured and everything the
 * front end may see. `kaiki_secret_key` holds the one thing it may not, in an
 * option of its own — **so that no code path can accidentally hand the secret
 * to a template by passing "the settings"**. That is not a hypothetical: a
 * helper that returns the whole settings array to a view is the obvious
 * convenience, and it is exactly how a secret ends up in page source.
 *
 * ADR-0013 Option A: a standard installation stores a publishable key and
 * nothing else. The secret exists only when the operator switched the SEO pages
 * on, is read only from the cron and webhook paths, and {@see self::secret_key()}
 * is the only reader.
 */
final class Settings {

	public const OPTION = 'kaiki_settings';

	public const SECRET_OPTION = 'kaiki_secret_key';

	public const WEBHOOK_SECRET_OPTION = 'kaiki_webhook_secret';

	/**
	 * Everything safe to hand to anything.
	 *
	 * The appearance fields are read through the same validators the settings
	 * screen saves them with, so a value that reached the option some other way —
	 * an import, a migration, somebody's `update_option` — is still a colour or
	 * nothing by the time it is written into a page.
	 *
	 * @return array{publishable_key: string, api_base: string, locale_mode: string, cache_ttl: int, seo_pages: bool, trip_base: string, appearance: 'kaiki'|'custom', primary: string, text: string, background: string, font_mode: 'theme'|'kaiki'|'custom', font_name: string, radius: int|null, show_vessel: bool}
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array(
			'publishable_key' => self::text( $stored, 'publishable_key' ),
			// Where the platform lives. Configurable because a developer needs a
			// local one, and because an operator on a custom domain is still
			// talking to the same API. Never a per-request value: a URL a page
			// could set is a page that can point the plugin at a stranger.
			'api_base'        => self::text( $stored, 'api_base', 'https://book.kaiki.gr' ),
			'locale_mode'     => self::text( $stored, 'locale_mode', 'auto' ),
			'cache_ttl'       => self::ttl( $stored ),
			'seo_pages'       => ! empty( $stored['seo_pages'] ),
			'trip_base'       => self::text( $stored, 'trip_base', 'tours' ),
			// «As in Kaiki» unless the operator chose otherwise: the branding
			// they set in their Kaiki panel is already the widget's look, and a
			// plugin that overrode it by default would make that panel lie.
			'appearance'      => self::appearance_mode( $stored['appearance'] ?? null ),
			'primary'         => self::colour( $stored['primary'] ?? null ),
			'text'            => self::colour( $stored['text'] ?? null ),
			'background'      => self::colour( $stored['background'] ?? null ),
			'font_mode'       => self::font_mode( $stored['font_mode'] ?? null ),
			'font_name'       => self::font_name( $stored['font_name'] ?? null ),
			'radius'          => self::radius( $stored['radius'] ?? null ),
			// On unless it was switched off. Absent reads as on rather than
			// through `! empty()`, because every option saved before this
			// setting existed has no such key — and none of those operators
			// chose to hide their boat.
			'show_vessel'     => ! array_key_exists( 'show_vessel', $stored ) || ! empty( $stored['show_vessel'] ),
		);
	}

	/**
	 * `kaiki` or `custom`, and `kaiki` for anything else.
	 *
	 * @param mixed $value The stored or submitted value.
	 * @return 'kaiki'|'custom'
	 */
	public static function appearance_mode( $value ): string {
		return 'custom' === $value ? 'custom' : 'kaiki';
	}

	/**
	 * `theme`, `kaiki` or `custom`, and `kaiki` for anything else.
	 *
	 * The fallback is Kaiki's font rather than the theme's because it is the one
	 * that changes nothing: an operator who switched to their own appearance to
	 * change a colour should not find the font changed as well.
	 *
	 * @param mixed $value The stored or submitted value.
	 * @return 'theme'|'kaiki'|'custom'
	 */
	public static function font_mode( $value ): string {
		return in_array( $value, array( 'theme', 'kaiki', 'custom' ), true ) ? $value : 'kaiki';
	}

	/**
	 * A `#rrggbb` colour in lower case, or an empty string meaning "not set".
	 *
	 * Six digits and nothing else — no names, no `rgb()`, no three-digit short
	 * form. The value ends up in a CSS custom property inside the widget, and a
	 * format this narrow is one that cannot carry anything but a colour there.
	 *
	 * @param mixed $value The stored or submitted value.
	 */
	public static function colour( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = strtolower( trim( $value ) );

		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $value ) ? $value : '';
	}

	/**
	 * The name of a font the theme already loads, reduced to letters, digits,
	 * spaces and hyphens, at most 60 characters.
	 *
	 * Filtered rather than refused, because `Open Sans` and `Open Sans;` are the
	 * same font to the operator who typed them. What survives is a name that
	 * cannot close a CSS declaration or a quoted string, which is the whole risk
	 * of a free-text font field. ASCII only: font-family names that matter here
	 * are the theme's own, and those are Latin.
	 *
	 * @param mixed $value The stored or submitted value.
	 */
	public static function font_name( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = (string) preg_replace( '/[^A-Za-z0-9 \-]/', '', $value );
		$value = trim( (string) preg_replace( '/\s+/', ' ', $value ) );

		return trim( substr( $value, 0, 60 ) );
	}

	/**
	 * Corner roundness in whole pixels, 0 to 30, or null meaning "not set".
	 *
	 * Clamped rather than refused, like the cache lifetime: somebody who typed
	 * 50 wanted "very round", and 30 is the roundest that still reads as a
	 * button rather than a pill with the text falling out of it.
	 *
	 * @param mixed $value The stored or submitted value.
	 */
	public static function radius( $value ): ?int {
		if ( is_int( $value ) ) {
			return max( 0, min( 30, $value ) );
		}

		if ( ! is_string( $value ) || ! is_numeric( trim( $value ) ) ) {
			return null;
		}

		return max( 0, min( 30, (int) round( (float) trim( $value ) ) ) );
	}

	/**
	 * The key every front-end render uses, and the only one they may.
	 */
	public static function publishable_key(): string {
		return self::all()['publishable_key'];
	}

	/**
	 * Where the platform lives, with no trailing slash.
	 */
	public static function api_base(): string {
		return untrailingslashit( self::all()['api_base'] );
	}

	/**
	 * How long a catalogue read is remembered, in seconds.
	 */
	public static function cache_ttl(): int {
		return self::all()['cache_ttl'];
	}

	/**
	 * Has the operator switched WPP-6's trip pages on?
	 */
	public static function seo_pages_enabled(): bool {
		return self::all()['seo_pages'];
	}

	/**
	 * The permalink base the trip pages live under (WPP-6), default `tours`.
	 *
	 * Configurable because the word is already taken on plenty of sites — by a
	 * page, by a WooCommerce category, by another plugin's post type — and two
	 * things claiming one URL is a 404 on whichever loses.
	 */
	public static function trip_base(): string {
		return self::all()['trip_base'];
	}

	/**
	 * `auto`, `el` or `en` — what the operator chose on the settings page.
	 */
	public static function locale_mode(): string {
		return self::all()['locale_mode'];
	}

	/**
	 * The secret key, and the only place it is ever read.
	 *
	 * WPP-3 item 2: server-side, from WP-Cron and the inbound webhook handler
	 * only. It is not in {@see self::all()} and it never will be — if a caller
	 * needs it, it must say so by name, in a file somebody reviewed.
	 */
	public static function secret_key(): string {
		if ( ! self::seo_pages_enabled() ) {
			// Not merely empty: switching the feature off means the key is not
			// in use, whatever is still in the database from before.
			return '';
		}

		$stored = get_option( self::SECRET_OPTION, '' );

		return is_string( $stored ) ? trim( $stored ) : '';
	}

	/**
	 * The shared secret the inbound webhook is verified with (WPP-8).
	 *
	 * In an option of its own for the same reason the API secret is: it must be
	 * impossible to hand to a template by passing "the settings". It is a
	 * weaker credential — it authenticates a call *to* this site and can read
	 * nothing — but it is still the thing that stops a stranger flushing an
	 * operator's cache on a loop, and there is no reason for it to travel with
	 * the values a page renders.
	 */
	public static function webhook_secret(): string {
		$stored = get_option( self::WEBHOOK_SECRET_OPTION, '' );

		return is_string( $stored ) ? trim( $stored ) : '';
	}

	/**
	 * Is the plugin configured enough to render anything?
	 */
	public static function is_configured(): bool {
		return '' !== self::publishable_key() && '' !== self::api_base();
	}

	/**
	 * One text field, trimmed, or the fallback when it is absent or blank.
	 *
	 * @param array<string, mixed> $stored   The raw option.
	 * @param string               $key      The field.
	 * @param string               $fallback The value when it is absent or wrong.
	 */
	private static function text( array $stored, string $key, string $fallback = '' ): string {
		$value = $stored[ $key ] ?? null;

		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : $fallback;
	}

	/**
	 * The cache lifetime, bounded rather than trusted.
	 *
	 * @param array<string, mixed> $stored The raw option.
	 */
	private static function ttl( array $stored ): int {
		$value = (int) ( $stored['cache_ttl'] ?? 300 );

		// Bounded rather than trusted. Zero would mean an API call per page view
		// on a site that gets traffic, and a day would mean an operator's price
		// change taking a day to appear — the webhook busts the cache anyway
		// (WPP-7), so a long TTL is safe and a short one is only expensive.
		return max( 60, min( 86400, $value ) );
	}
}
