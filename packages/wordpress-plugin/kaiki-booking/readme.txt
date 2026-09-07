=== Kaiki Booking ===
Contributors: kaiki
Tags: booking, boat, tours, reservations, cruises
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take boat trip bookings on your own WordPress site, from your Kaiki account.

== Description ==

Kaiki is a booking platform for boat operators. This plugin puts your trips and
a working booking form on your own WordPress site: pick a date, pick a party,
pay, done — on your pages, in your theme, in Greek or English.

It is a thin client. Your trips, prices, availability and bookings all live in
Kaiki; the plugin shows them and nothing more. It never touches WooCommerce, and
it stores no guest details in WordPress.

= What you get =

* Shortcodes: `[kaiki_booking product="…"]`, `[kaiki_list]`, `[kaiki_calendar product="…"]`, `[kaiki_enquiry]`
* Gutenberg blocks and Elementor widgets, with the same options
* Optional trip pages, so search engines find your trips on your own site too
* Greek and English, following WPML, Polylang or your site language

== Installation ==

1. Upload and activate the plugin.
2. Go to **Settings → Kaiki Booking** and paste your publishable key. You will
   find it in your Kaiki panel under API keys.
3. Press **Test the connection**.
4. Put a shortcode, a block or an Elementor widget on any page.

== Frequently Asked Questions ==

= Do I need a secret key? =

No. A standard installation stores a publishable key and nothing else, and the
plugin is fully functional that way. A secret key is needed only if you switch
on the optional trip pages, and even then it is used only by this site's own
scheduled task. Never paste it into a page, a post or a theme file.

= Will it work with my theme? =

The booking form draws itself inside a shadow root, which means your theme's
styles cannot reach into it and its styles cannot leak out onto your pages. It
is tested against Woodmart, Astra and Hello Elementor.

= Does it slow my site down? =

The booking script loads only on pages that actually use it, and your trips are
remembered on your site between visits. Kaiki tells your site when something
changes, so what visitors see is current without asking every time.

== Changelog ==

= 0.1.0 =
* First release: settings, connection test, Greek and English.
