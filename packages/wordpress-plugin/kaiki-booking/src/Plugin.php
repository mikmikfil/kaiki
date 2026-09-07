<?php
/**
 * The plugin's registration, in one place.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking;

use Kaiki\Booking\Blocks\Blocks;
use Kaiki\Booking\Elementor\Widgets;
use Kaiki\Booking\Http\Webhook;
use Kaiki\Booking\Rest\Trips;
use Kaiki\Booking\Seo\Sync;
use Kaiki\Booking\Seo\TripPostType;
use Kaiki\Booking\Shortcodes\Shortcodes;
use Kaiki\Booking\Settings\SettingsPage;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the plugin hooks into WordPress, and nothing that does work.
 *
 * A registration class rather than a scattering of `add_action` calls across
 * files: an operator's support question is usually "what does this plugin do to
 * my site", and the honest answer should be readable in one screen.
 */
final class Plugin {

	/**
	 * Register everything. Called once, from the plugin file.
	 */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_translations' ) );

		// Registered on every request, admin or not: a webhook arrives with no
		// user, no cookie and no admin context, and a route registered only in
		// `is_admin()` would never exist when Kaiki called.
		Webhook::register();

		// The four shortcodes (WPP-4). Registered always, because a shortcode in
		// a page is rendered on the front end and previewed in the editor.
		Shortcodes::register();

		// The same four embeds through the two editors an operator may be using
		// (WPP-5). Each registration is a no-op when its editor is absent, which
		// is the ordinary case for both.
		Blocks::register();
		Widgets::register();

		// The trip picker the block editor needs. Capability-gated, and it
		// returns nothing a publishable key could not already read.
		Trips::register();

		// The SEO trip pages (WPP-6), and the only part of this plugin that
		// touches the secret key. Both calls are no-ops when the operator has
		// not switched the feature on — no post type, no schedule, and nothing
		// that reads a secret.
		TripPostType::register();
		Sync::register();

		if ( is_admin() ) {
			SettingsPage::register();
		}
	}

	/**
	 * The Greek translations.
	 *
	 * WPP-3 asks for the settings page to say what it says **in both locales**,
	 * and WPP-13 maps a site running WPML, Polylang or plain `el_GR` onto `el`.
	 * This is the WordPress half of that: an operator whose admin is in Greek
	 * reads the plugin in Greek, with no setting to find.
	 */
	public static function load_translations(): void {
		load_plugin_textdomain( 'kaiki-booking', false, dirname( plugin_basename( FILE ) ) . '/languages' );
	}
}
