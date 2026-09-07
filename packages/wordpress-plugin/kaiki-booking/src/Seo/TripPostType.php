<?php
/**
 * The `kaiki_trip` post type and the template that renders it.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Seo;

use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One post type, one permalink base, one template override (WPP-6).
 *
 * ## Not registered when the feature is off
 *
 * WPP-6's own acceptance rule, and it is the stricter reading: a site that never
 * switched trip pages on has no post type, no cron event and no reason to hold a
 * secret key (ADR-0013 Option A). The cost is that a site which switches the
 * feature *off again* leaves its posts in `wp_posts` with a `post_type`
 * WordPress no longer knows — they vanish from the admin and from every URL
 * until it is switched back on, at which point they all return. That is
 * recoverable and it is not data loss; a post type registered on every site
 * whether or not it is used is neither.
 *
 * ## `public` but not in search results
 *
 * `exclude_from_search` is true and `has_archive` is false, deliberately. These
 * pages exist for search engines and for direct links; a trip appearing inside
 * the site's own search box, competing with the operator's hand-written page
 * about the same trip, is the duplicate-content problem this feature is meant to
 * avoid rather than create. The `[kaiki_list]` shortcode is how an operator puts
 * their trips in front of a visitor on this site.
 *
 * ## The permalink base is a setting, and changing it flushes
 *
 * WPP-6 fixes the default at `/tours/`. It has to be configurable because the
 * word is already taken on plenty of sites — by a page, by a WooCommerce
 * product category, by another plugin's post type — and two things claiming one
 * URL is a 404 on whichever loses. WordPress caches rewrite rules, so a base
 * that changed without a flush is a base that 404s until somebody visits the
 * permalinks screen, which nobody does.
 */
final class TripPostType {

	public const POST_TYPE = 'kaiki_trip';

	/**
	 * Where the sync stores what it needs to recognise a post again.
	 *
	 * `uuid` and `lang` together are the identity: one product becomes one post
	 * per language, and a lookup by uuid alone would find the Greek post when
	 * updating the English one.
	 */
	public const META_UUID = '_kaiki_uuid';

	public const META_LANG = '_kaiki_lang';

	public const META_HASH = '_kaiki_content_hash';

	public const META_CANONICAL = '_kaiki_canonical_url';

	/**
	 * The option that remembers which base the rewrite rules were built with.
	 *
	 * Compared on every load, which sounds wasteful and is one option read that
	 * WordPress has already fetched — against a base change that silently 404s
	 * every trip page until somebody happens to re-save permalinks.
	 */
	public const BASE_OPTION = 'kaiki_trip_base_flushed';

	/**
	 * Hook the post type, the flush check, the template and the canonical.
	 */
	public static function register(): void {
		if ( ! Settings::seo_pages_enabled() ) {
			return;
		}

		add_action( 'init', array( self::class, 'register_post_type' ) );
		add_action( 'init', array( self::class, 'flush_if_base_changed' ), 20 );
		add_filter( 'single_template', array( self::class, 'template' ) );
		add_action( 'wp_head', array( self::class, 'canonical' ) );
	}

	/**
	 * The post type itself.
	 */
	public static function register_post_type(): void {
		// phpcs:ignore WordPress.NamingConventions.ValidPostTypeSlug.NotStringLiteral -- A constant, so the sync and the template cannot disagree with the registration.
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Trips', 'kaiki-booking' ),
					'singular_name' => __( 'Trip', 'kaiki-booking' ),
					'menu_name'     => __( 'Kaiki trips', 'kaiki-booking' ),
				),
				'public'              => true,
				'show_ui'             => true,
				// Read-only from the admin's point of view. Every one of these
				// posts is overwritten by the next sync, so an editor's changes
				// would be lost without warning — and a post type that quietly
				// discards work is worse than one that does not offer it.
				'capabilities'        => array(
					'create_posts' => 'do_not_allow',
				),
				'map_meta_cap'        => true,
				'menu_icon'           => 'dashicons-palmtree',
				'exclude_from_search' => true,
				'publicly_queryable'  => true,
				'has_archive'         => false,
				'hierarchical'        => false,
				'rewrite'             => array(
					'slug'       => self::base(),
					'with_front' => false,
				),
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
				// Off. The pages are for visitors and crawlers; exposing an
				// operator's synced catalogue over the REST API would publish
				// the same list a second time, at a URL nobody audits.
				'show_in_rest'        => false,
			)
		);
	}

	/**
	 * The permalink base, sanitised to something that can be one.
	 */
	public static function base(): string {
		$base = Settings::trip_base();

		return '' === $base ? 'tours' : $base;
	}

	/**
	 * Rebuild the rewrite rules when, and only when, the base changed.
	 */
	public static function flush_if_base_changed(): void {
		$base = self::base();

		if ( get_option( self::BASE_OPTION ) === $base ) {
			return;
		}

		update_option( self::BASE_OPTION, $base );

		flush_rewrite_rules( false );
	}

	/**
	 * The theme's template if it has one, otherwise ours.
	 *
	 * WPP-6 names `kaiki/single-trip.php` — a theme that wants these pages to
	 * look like the rest of the site drops a file in and never touches the
	 * plugin. `locate_template` checks the child theme first, which is the whole
	 * reason to use it rather than testing for a path.
	 *
	 * @param  string $template What WordPress was going to use.
	 * @return string
	 */
	public static function template( string $template ): string {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return $template;
		}

		$theme = locate_template( array( 'kaiki/single-trip.php' ) );

		if ( '' !== $theme ) {
			return $theme;
		}

		return dirname( __DIR__, 2 ) . '/templates/single-trip.php';
	}

	/**
	 * Point the crawler at Kaiki's own page for this trip, when there is one.
	 *
	 * The platform calls its hosted product page *"the canonical address of a
	 * trip"* and says this sync is what points at it, so the two pages never
	 * compete for the same query. An operator with no hosted pages gets a null
	 * from the feed and this page is canonical on its own — which is the
	 * arrangement most operators using this feature actually have.
	 *
	 * Emitted only when we have a value, so a site whose SEO plugin already
	 * writes a canonical tag is not given a second one.
	 */
	public static function canonical(): void {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return;
		}

		$url = (string) get_post_meta( (int) get_the_ID(), self::META_CANONICAL, true );

		if ( '' === $url ) {
			return;
		}

		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	}
}
