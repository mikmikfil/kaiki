<?php
/**
 * Which of the two languages this page is in.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Locale;

use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WPP-13's resolution order, and nothing beyond it.
 *
 * > *"WPML, then Polylang, then the WordPress site locale, mapped to `el` or
 * > `en`; unmapped locales fall back to `en`."*
 *
 * The order is not arbitrary. A site with WPML has told WordPress which language
 * *this page* is in, and the site locale is only what the admin is in — on a
 * bilingual site those two disagree constantly, and using the second would serve
 * a Greek visitor an English booking form on a Greek page.
 *
 * An operator who wants neither can pin the language on the settings page, which
 * is the `auto` / `el` / `en` choice. Pinning is for the operator whose site is
 * English but whose guests are Greek, and they exist.
 */
final class Locale {

	public const GREEK = 'el';

	public const ENGLISH = 'en';

	/**
	 * The locale to ask the API for, and to hand the widget.
	 */
	public static function current(): string {
		$pinned = Settings::locale_mode();

		if ( self::GREEK === $pinned || self::ENGLISH === $pinned ) {
			return $pinned;
		}

		return self::map( self::detect() );
	}

	/**
	 * The **site's** language, ignoring whatever page we happen to be on.
	 *
	 * The sync (WPP-6) runs in WP-Cron, where there is no request, no visitor
	 * and no language switcher — so {@see self::current()} would return whatever
	 * the last thing to touch WPML's global left behind, and that is not a thing
	 * to name an operator's URLs after. A pinned locale still wins, because an
	 * operator who pinned one meant it.
	 */
	public static function site(): string {
		$pinned = Settings::locale_mode();

		if ( self::GREEK === $pinned || self::ENGLISH === $pinned ) {
			return $pinned;
		}

		return self::map( (string) get_locale() );
	}

	/**
	 * One language slug, mapped strictly — `null` when it is neither of ours.
	 *
	 * The difference from {@see self::map()} matters exactly once: when deciding
	 * which languages to *create pages in*. A site publishing Greek, English and
	 * German must not get a German page rendered from the English translation —
	 * that is a page a German visitor bounces off and a crawler indexes as
	 * English content on a German URL. Rendering *this* page falls back to
	 * English instead, because a page in a language the visitor may not read is
	 * still better than no page.
	 *
	 * @param string $locale A WordPress locale, or a language slug.
	 */
	public static function of( string $locale ): ?string {
		$locale = strtolower( $locale );

		if ( str_starts_with( $locale, 'el' ) || str_starts_with( $locale, 'gr' ) ) {
			return self::GREEK;
		}

		return str_starts_with( $locale, 'en' ) ? self::ENGLISH : null;
	}

	/**
	 * The raw locale WordPress or a translation plugin believes we are in.
	 */
	private static function detect(): string {
		// WPML. `ICL_LANGUAGE_CODE` is already `el` or `en` rather than a full
		// locale, and it is per-page rather than per-site, which is the whole
		// reason it comes first.
		if ( defined( 'ICL_LANGUAGE_CODE' ) ) {
			return (string) constant( 'ICL_LANGUAGE_CODE' );
		}

		// Polylang. Asked through its function rather than its constant,
		// because the constant is not defined on every request.
		if ( function_exists( 'pll_current_language' ) ) {
			$polylang = pll_current_language( 'slug' );

			if ( is_string( $polylang ) && '' !== $polylang ) {
				return $polylang;
			}
		}

		return (string) get_locale();
	}

	/**
	 * Anything Greek is `el`; everything else is `en`.
	 *
	 * The fallback is deliberate and one-sided: an operator's page in a language
	 * Kaiki does not speak is better served in English than in a language the
	 * visitor may not read at all.
	 *
	 * @param string $locale A WordPress locale, or a language slug.
	 */
	private static function map( string $locale ): string {
		$locale = strtolower( $locale );

		return str_starts_with( $locale, 'el' ) || str_starts_with( $locale, 'gr' )
			? self::GREEK
			: self::ENGLISH;
	}
}
