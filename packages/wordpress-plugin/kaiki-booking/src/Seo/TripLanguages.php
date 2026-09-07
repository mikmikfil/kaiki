<?php
/**
 * Which languages this site wants a trip page in.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Seo;

use Kaiki\Booking\Locale\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * One post per product, or one per product per language (WPP-6, WPP-13).
 *
 * The sync feed returns translations unresolved — `{"el": …, "en": …}` in one
 * response — for exactly this: a site running WPML or Polylang wants a Greek
 * page and an English page, linked to each other, and a site running neither
 * wants one page in whatever language the site is.
 *
 * ## The single-language site is the common case and gets the simple answer
 *
 * Most operators installing this plugin have a Greek site and nothing else.
 * They get one post per trip, at the platform's own slug, in Greek. Nothing
 * here runs a multilingual code path to arrive at that.
 *
 * ## Both plugins are asked the same question and answer it differently
 *
 * Polylang exposes `pll_languages_list()`; WPML exposes the `wpml_active_languages`
 * filter. Neither is loaded on most sites, so both are behind existence checks —
 * and a site with *both* is a site with a bigger problem than this file, so
 * Polylang wins because it is asked first and the two must not be combined into
 * a list with one language twice in it.
 *
 * ## An unmapped language is dropped, never guessed
 *
 * A site with Greek, English and German gets Greek and English pages. There is
 * no German in the feed, and a German page rendered from the English
 * translation is worse than no German page — it is a page a German visitor
 * bounces off and a crawler indexes as English content on a German URL.
 */
final class TripLanguages {

	/**
	 * The languages to create posts in, always at least one.
	 *
	 * @param  array<int, string> $available What the feed says the operator publishes.
	 * @return array<int, string>
	 */
	public static function wanted( array $available ): array {
		$supported = array();

		foreach ( $available as $locale ) {
			if ( in_array( $locale, array( 'el', 'en' ), true ) ) {
				$supported[] = $locale;
			}
		}

		if ( array() === $supported ) {
			$supported = array( 'en' );
		}

		$site = self::site_languages();

		if ( array() === $site ) {
			// No multilingual plugin: one language, the one the site is in —
			// and if the operator does not publish that language, the one they
			// do, because a site with no page at all is the worse answer.
			$primary = self::primary();

			return in_array( $primary, $supported, true ) ? array( $primary ) : array( $supported[0] );
		}

		$wanted = array();

		foreach ( $site as $locale ) {
			if ( in_array( $locale, $supported, true ) ) {
				$wanted[] = $locale;
			}
		}

		return array() === $wanted ? array( $supported[0] ) : $wanted;
	}

	/**
	 * The site's own language, which is the one whose slugs go unsuffixed.
	 *
	 * {@see Locale::site()} rather than {@see Locale::current()}: the sync runs
	 * in WP-Cron, where there is no request, no visitor and no language switcher
	 * — so "the current language" is whatever the last thing to touch the global
	 * left behind, which is not a thing to name a URL after.
	 */
	public static function primary(): string {
		return Locale::site();
	}

	/**
	 * What the installed multilingual plugin publishes, mapped to `el`/`en`.
	 *
	 * An empty list means "no multilingual plugin", which is a different
	 * statement from "a multilingual plugin with no languages" — but the two
	 * lead to the same place, so they are not distinguished.
	 *
	 * @return array<int, string>
	 */
	private static function site_languages(): array {
		$slugs = array();

		if ( function_exists( 'pll_languages_list' ) ) {
			$list = pll_languages_list();

			$slugs = is_array( $list ) ? $list : array();
		} elseif ( function_exists( 'apply_filters' ) && has_filter( 'wpml_active_languages' ) ) {
			// WPML's own filter, which only WPML answers. It cannot carry our
			// prefix, because it is not our hook — we are the caller here.
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			$list = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

			if ( is_array( $list ) ) {
				$slugs = array_keys( $list );
			}
		}

		$out = array();

		foreach ( $slugs as $slug ) {
			$locale = Locale::of( (string) $slug );

			if ( null !== $locale && ! in_array( $locale, $out, true ) ) {
				$out[] = $locale;
			}
		}

		return $out;
	}
}
