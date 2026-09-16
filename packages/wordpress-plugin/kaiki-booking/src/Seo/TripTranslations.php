<?php
/**
 * Telling WPML or Polylang that two trip pages are one trip.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Seo;

use Kaiki\Booking\Locale\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * The Greek page and the English page, linked (WPP-6).
 *
 * ## What was missing
 *
 * {@see Sync} already creates one post per language per trip, and
 * {@see TripRepository} already keys them on uuid *and* language so the two
 * never overwrite each other. What neither did was **say so to the translation
 * plugin**. WPML and Polylang each keep their own record of which post is a
 * translation of which; a post nobody told them about is, to them, an untranslated
 * post in the default language.
 *
 * The visible cost was the language switcher: a visitor on the Greek page of a
 * trip clicked EN and did not arrive at the English page of that trip, because
 * as far as the switcher knew there wasn't one. Two correct pages, no road
 * between them.
 *
 * ## Both plugins, in the same order as everywhere else
 *
 * Polylang is asked first, exactly as in {@see TripLanguages::site_languages()},
 * and for the same reason: a site with both installed has a bigger problem than
 * this file, and the two must not both write. Each is behind a check for its own
 * API, so a single-language site runs none of this.
 *
 * ## The slugs are the site's, not ours
 *
 * Internally a language is `el` or `en` — two values, mapped strictly, because
 * that is what the platform publishes. A site's own slugs are whatever the
 * operator set up: `el`, `en`, `en-gb`, `en_US`. Handing our `en` to a site
 * whose English is `en-gb` sets a language that does not exist there, and WPML
 * files the post under nothing at all. So every code written below is read back
 * out of the installed plugin and matched through {@see Locale::of()}.
 *
 * ## Run on every sync, and safe to
 *
 * Both APIs are declarative — *this post is in this language, and belongs to
 * this group* — rather than incremental, so applying the same answer again is a
 * no-op. That matters because the sync is resumable and a page can be applied
 * twice.
 */
final class TripTranslations {

	/**
	 * Link one trip's pages to each other.
	 *
	 * Called once per product, after every language has been written, because a
	 * translation group cannot be declared one member at a time — Polylang wants
	 * the whole map, and WPML wants a source to point the others at.
	 *
	 * @param string             $uuid      The product's uuid.
	 * @param array<int, string> $languages The languages written, as `el`/`en`.
	 */
	public static function link( string $uuid, array $languages ): void {
		$slugs = self::slugs();

		if ( array() === $slugs ) {
			// No multilingual plugin. One post, in the site's only language, and
			// nothing to tell anybody.
			return;
		}

		$posts = array();

		foreach ( $languages as $language ) {
			$id = TripRepository::find( $uuid, $language );

			if ( null !== $id && isset( $slugs[ $language ] ) ) {
				$posts[ $slugs[ $language ] ] = $id;
			}
		}

		if ( array() === $posts ) {
			return;
		}

		if ( function_exists( 'pll_set_post_language' ) && function_exists( 'pll_save_post_translations' ) ) {
			self::link_polylang( $posts );

			return;
		}

		if ( has_filter( 'wpml_element_trid' ) ) {
			self::link_wpml( $posts );
		}
	}

	/**
	 * Polylang: a language per post, then the group.
	 *
	 * The language has to be set on every post *before* the group is saved —
	 * `pll_save_post_translations` matches its keys against the languages the
	 * posts are already in and silently drops any that disagree.
	 *
	 * The group is saved even for a single post. Polylang treats a one-language
	 * group as exactly that, and it costs one call to stay uniform rather than
	 * to branch.
	 *
	 * @param array<string, int> $posts Site language slug to post id.
	 */
	private static function link_polylang( array $posts ): void {
		foreach ( $posts as $slug => $id ) {
			pll_set_post_language( $id, $slug );
		}

		pll_save_post_translations( $posts );
	}

	/**
	 * WPML: a source, a `trid`, and the rest hung off it.
	 *
	 * WPML's model is a group id — the `trid` — plus, for every member that is
	 * not the original, which language it was translated *from*. So the site's
	 * own language goes first and is written as a source: either into the group
	 * this trip already has, or into a new one when `wpml_element_trid` answers
	 * with nothing.
	 *
	 * The trid is read back rather than assumed, because on the first run there
	 * was none to read before the source was written.
	 *
	 * @param array<string, int> $posts Site language slug to post id.
	 */
	private static function link_wpml( array $posts ): void {
		$source = self::source_slug( $posts );
		$type   = 'post_' . TripPostType::POST_TYPE;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook; we are the caller.
		$trid = apply_filters( 'wpml_element_trid', null, $posts[ $source ], $type );

		self::tell_wpml( $posts[ $source ], $type, $source, is_numeric( $trid ) ? (int) $trid : null, null );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook; we are the caller.
		$trid = apply_filters( 'wpml_element_trid', null, $posts[ $source ], $type );

		if ( ! is_numeric( $trid ) ) {
			// WPML did not take the source. Hanging translations off a group
			// that does not exist would file them under a null trid, which is
			// how a post disappears from every language at once.
			return;
		}

		foreach ( $posts as $slug => $id ) {
			if ( $slug === $source ) {
				continue;
			}

			self::tell_wpml( $id, $type, $slug, (int) $trid, $source );
		}
	}

	/**
	 * One post's language details, in WPML's vocabulary.
	 *
	 * @param int         $id     The post.
	 * @param string      $type   WPML's element type, `post_<post type>`.
	 * @param string      $slug   The site's language code for this post.
	 * @param int|null    $trid   The translation group, or null to start one.
	 * @param string|null $source The language this was translated from, or null for the original.
	 */
	private static function tell_wpml( int $id, string $type, string $slug, ?int $trid, ?string $source ): void {
		// WPML's own hook; we are the caller, so it cannot carry our prefix.
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $id,
				'element_type'         => $type,
				'trid'                 => $trid,
				'language_code'        => $slug,
				'source_language_code' => $source,
			)
		);
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	}

	/**
	 * Which of these pages is the original.
	 *
	 * The site's own language, when it is among them — that is the page written
	 * at the unsuffixed slug, and the one an operator thinks of as the page.
	 * Otherwise the first there is, because WPML needs *a* source and a group
	 * with none is a group it will not build.
	 *
	 * @param array<string, int> $posts Site language slug to post id.
	 */
	private static function source_slug( array $posts ): string {
		$slugs   = self::slugs();
		$primary = TripLanguages::primary();

		if ( isset( $slugs[ $primary ], $posts[ $slugs[ $primary ] ] ) ) {
			return $slugs[ $primary ];
		}

		return (string) array_key_first( $posts );
	}

	/**
	 * Our `el`/`en`, mapped to the codes this site actually uses.
	 *
	 * Empty when no multilingual plugin is installed, which is the common case
	 * and the one that needs none of this.
	 *
	 * The first slug wins when a site has two that both map to ours — `en` and
	 * `en-gb` together — because there is one English page to file and filing it
	 * twice is worse than filing it under the one the operator listed first.
	 *
	 * @return array<string, string>
	 */
	private static function slugs(): array {
		$codes = array();

		if ( function_exists( 'pll_languages_list' ) ) {
			$list = pll_languages_list();

			$codes = is_array( $list ) ? $list : array();
		} elseif ( has_filter( 'wpml_active_languages' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own hook; we are the caller.
			$list = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

			$codes = is_array( $list ) ? array_keys( $list ) : array();
		}

		$out = array();

		foreach ( $codes as $code ) {
			$code   = (string) $code;
			$locale = Locale::of( $code );

			if ( null !== $locale && ! isset( $out[ $locale ] ) ) {
				$out[ $locale ] = $code;
			}
		}

		return $out;
	}
}
