<?php
/**
 * Which trip the page being rendered is about, when nobody said.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Shortcodes;

use Kaiki\Booking\Seo\TripPostType;

defined( 'ABSPATH' ) || exit;

/**
 * The trip id for the current post, for a shortcode written without one.
 *
 * ## Why this exists
 *
 * `[kaiki_booking product="…"]` names its trip, and for a page an operator
 * writes by hand that is right: one page, one trip, the id typed once. It stops
 * being right the moment the page is a **template**. A template is written once
 * and rendered for every trip there is, so the one thing it cannot carry is the
 * id of one of them — and an Elementor or block template with a hard-coded
 * `product` shows the same booking form on all of them.
 *
 * So a shortcode with no `product` stops being a mistake and becomes a
 * question: *which trip is this page about?* This class is the answer, and the
 * misconfiguration notice is what happens when there isn't one.
 *
 * ## `_kaiki_uuid`, and deliberately nothing else
 *
 * The sync already writes that meta key on every post it creates
 * ({@see TripPostType::META_UUID}), so a template over the plugin's own
 * `kaiki_trip` posts works with no setup at all. The lookup is by meta key
 * rather than by post type on purpose: an operator who keeps their trips as
 * their own pages, or in their theme's post type, adds the same custom field
 * and the same template works for them too. Tying it to `kaiki_trip` would have
 * made the feature useless to exactly the operators who already have a site.
 *
 * Nothing is guessed. There is no "the only trip on the page" fallback and no
 * search by title: a booking form that quietly picks a trip is a booking form
 * that sells the wrong one, and the failure is silent and the money is real.
 *
 * ## In the editor
 *
 * Elementor renders a template against a preview post and sets up the loop
 * before calling a widget, so `get_the_ID()` answers there the same way it does
 * for a visitor. A template previewed against a post with no trip on it gets
 * the same notice an editor would see on the live page, which is the honest
 * answer rather than a blank.
 */
final class CurrentTrip {

	/**
	 * The current post's trip id, or an empty string.
	 *
	 * Not cached: `get_post_meta` is served from WordPress's own object cache
	 * after the first read, and a static here would be wrong the second time a
	 * loop renders — which is exactly what a template does.
	 */
	public static function uuid(): string {
		$post_id = get_the_ID();

		if ( ! is_int( $post_id ) || $post_id <= 0 ) {
			return '';
		}

		return (string) get_post_meta( $post_id, TripPostType::META_UUID, true );
	}
}
