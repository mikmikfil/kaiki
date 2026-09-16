<?php
/**
 * The three shortcodes an operator can use.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Shortcodes;

use Kaiki\Booking\Assets\Bundle;
use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * `[kaiki_booking]`, `[kaiki_list]`, `[kaiki_enquiry]`.
 *
 * WPP-4 fixed four. The availability calendar was taken out of the plugin on
 * 2026-09-11 by the product owner's decision: operators get the booking form,
 * the trip list and the enquiry form, and nothing that only shows dates.
 *
 * The plugin's whole promise is here: an operator pastes one of these into a
 * page and takes a booking. Blocks and Elementor widgets, which come next, are
 * nicer ways of producing the same three strings — WPP-5 fixes them as
 * *server-rendered wrappers around the shortcodes*, so this file is the one
 * rendering path and there is never a second opinion about the markup.
 *
 * ## The messages are for two different people
 *
 * A visitor who meets a misconfigured shortcode should see nothing alarming and
 * nothing about us. An **editor** — somebody who can edit this page — should see
 * exactly what is wrong and what a correct one looks like, because the person
 * who pasted it is the operator or their nephew, at night, once, with nobody to
 * ask. A shortcode that silently rendered nothing would be an afternoon of their
 * life.
 *
 * ## Everything is escaped where it is output
 *
 * WPP-11, and it is not ceremony: a `product` attribute is a string a page
 * editor typed, and page editors paste strange things.
 */
final class Shortcodes {

	/**
	 * Register all three.
	 */
	public static function register(): void {
		add_shortcode( 'kaiki_booking', array( self::class, 'booking' ) );
		add_shortcode( 'kaiki_list', array( self::class, 'trip_list' ) );
		add_shortcode( 'kaiki_enquiry', array( self::class, 'enquiry' ) );
	}

	/**
	 * `[kaiki_booking product="uuid"]` — the booking form itself.
	 *
	 * `product` may be left out **on a page that is about one trip**, which is
	 * what makes this usable from a template: see {@see CurrentTrip}. A page
	 * that names no trip and is about none still gets the notice below.
	 *
	 * @param  array<string, string>|string $atts The shortcode attributes.
	 * @return string
	 */
	public static function booking( $atts ): string {
		$atts = shortcode_atts( array( 'product' => '' ), self::attributes( $atts ), 'kaiki_booking' );

		$product = self::product( $atts['product'] );

		if ( '' === $product ) {
			return self::misconfigured(
				/* translators: %s: an example of a correct shortcode. */
				__( 'This booking form needs to know which trip it is for. Use %s, with the trip id from your Kaiki panel.', 'kaiki-booking' ),
				// Unchanged wording, and on purpose. The id is still what is
				// missing; that a trip page can now supply it by itself is not
				// something to explain to somebody who is looking at a page
				// which evidently is not one.
				'[kaiki_booking product="…"]'
			);
		}

		return self::wrap( Bundle::embed( 'booking', array( 'product' => $product ) ) );
	}

	/**
	 * `[kaiki_list category="shared"]` — the operator's trips as a grid.
	 *
	 * The category is optional and an unknown one renders an empty list rather
	 * than an error (WGT-6): an operator writes it into a page once, and the
	 * page outlives the trips it was written for.
	 *
	 * @param  array<string, string>|string $atts The shortcode attributes.
	 * @return string
	 */
	public static function trip_list( $atts ): string {
		$atts = shortcode_atts( array( 'category' => '' ), self::attributes( $atts ), 'kaiki_list' );

		return self::wrap( Bundle::embed( 'list', array( 'category' => sanitize_key( $atts['category'] ) ) ) );
	}

	/**
	 * `[kaiki_enquiry]` — the form for a trip with no published price.
	 *
	 * `product` is optional here: an enquiry about the fleet in general is a
	 * real thing an operator wants on a contact page.
	 *
	 * @param  array<string, string>|string $atts The shortcode attributes.
	 * @return string
	 */
	public static function enquiry( $atts ): string {
		$atts = shortcode_atts( array( 'product' => '' ), self::attributes( $atts ), 'kaiki_enquiry' );

		return self::wrap( Bundle::embed( 'enquiry', array( 'product' => self::product( $atts['product'] ) ) ) );
	}

	/**
	 * The trip this embed is for: the one written into the shortcode, or the one
	 * the page is about.
	 *
	 * The attribute wins when it is there, always — a template that resolves a
	 * trip must never override an id somebody typed, because the typed one is
	 * the more specific statement and the only one an operator can see.
	 *
	 * The fallback is validated by {@see self::uuid()} like the attribute is.
	 * It comes from `get_post_meta` rather than from a page editor, which is a
	 * reason to expect it to be well formed and not a reason to trust it: the
	 * meta is writable by anything else on the site.
	 *
	 * @param string $attribute The `product` attribute as typed, possibly empty.
	 */
	private static function product( string $attribute ): string {
		$product = self::uuid( $attribute );

		return '' === $product ? self::uuid( CurrentTrip::uuid() ) : $product;
	}

	/**
	 * `shortcode_atts` wants an array, and WordPress hands it a string when the
	 * shortcode was written with no attributes at all.
	 *
	 * @param  array<string, string>|string $atts Whatever WordPress passed.
	 * @return array<string, string>
	 */
	private static function attributes( $atts ): array {
		return is_array( $atts ) ? $atts : array();
	}

	/**
	 * A uuid, or nothing.
	 *
	 * Checked rather than trusted: the value came from a page editor, and this
	 * is the difference between an attribute and an injection.
	 *
	 * @param string $value The attribute as typed.
	 */
	private static function uuid( string $value ): string {
		$value = strtolower( trim( $value ) );

		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value )
			? $value
			: '';
	}

	/**
	 * The container the embed goes in.
	 *
	 * Scoped `kaiki-` class and nothing else (WPP-2): the plugin adds no global
	 * CSS, and this element exists so an operator's theme has something to
	 * position without reaching inside the widget — which it could not anyway,
	 * through the shadow boundary.
	 *
	 * @param string $embed The script tag, already escaped.
	 */
	private static function wrap( string $embed ): string {
		if ( '' === $embed ) {
			return self::unconfigured();
		}

		return '<div class="kaiki-embed">' . $embed . '</div>';
	}

	/**
	 * The plugin has no key yet.
	 *
	 * @return string
	 */
	private static function unconfigured(): string {
		return self::misconfigured(
			/* translators: %s: the name of the settings page. */
			__( 'Kaiki Booking has no key yet. Add one under %s and this will start working.', 'kaiki-booking' ),
			__( 'Settings → Kaiki Booking', 'kaiki-booking' )
		);
	}

	/**
	 * Something is wrong with how this was written into the page.
	 *
	 * An editor gets the reason; a visitor gets a neutral sentence and no
	 * evidence that anything is broken. `current_user_can` is the split, not the
	 * login state: a subscriber who is signed in is still a visitor.
	 *
	 * @param string $message A message with one `%s` in it.
	 * @param string $detail  What to put in the `%s`.
	 */
	private static function misconfigured( string $message, string $detail ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return sprintf(
				'<div class="kaiki-embed kaiki-embed-empty">%s</div>',
				esc_html__( 'Bookings are briefly unavailable. Please try again in a moment, or contact us directly.', 'kaiki-booking' )
			);
		}

		return sprintf(
			'<div class="kaiki-embed kaiki-embed-notice"><strong>%s</strong> %s</div>',
			esc_html__( 'Only you can see this:', 'kaiki-booking' ),
			esc_html( sprintf( $message, $detail ) )
		);
	}

	/**
	 * Does this page use the plugin at all?
	 *
	 * Used by the block and Elementor wrappers, which need the same answer.
	 */
	public static function is_configured(): bool {
		return Settings::is_configured();
	}
}
