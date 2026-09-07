<?php
/**
 * The widget's script tag, and where it does not appear.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Assets;

use Kaiki\Booking\Locale\Locale;
use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The embed, rendered where a shortcode is and nowhere else (WPP-2, WPP-4).
 *
 * ## On demand, not globally
 *
 * A booking widget's script on an operator's contact page is weight nobody asked
 * for and a line in their performance report. WordPress makes the lazy way easy
 * — hook `wp_enqueue_scripts` and be done — and only the shortcode callback
 * knows whether this page needs it.
 *
 * ## Written out rather than enqueued, and that is on purpose
 *
 * `wp_enqueue_script` puts the tag in the head or the footer, and the widget
 * mounts **where its script tag is** (WGT-7). An enqueued bundle would render
 * the booking form at the bottom of the page instead of where the operator put
 * the shortcode — which is the one thing the one-line embed promises.
 *
 * So the tag is written into the shortcode's own output, with its attributes
 * escaped, and a static flag stops a second shortcode writing a second copy.
 * Two widgets on one page share one bundle, one HTTP cache and one branding
 * fetch, which is what the widget's own client already arranges.
 */
final class Bundle {

	/**
	 * Has the bundle already been written into this page?
	 *
	 * @var bool
	 */
	private static bool $printed = false;

	/**
	 * The embed for one mount, or an empty string when nothing can be rendered.
	 *
	 * @param string                $mount   `booking`, `list`, `calendar` or `enquiry`.
	 * @param array<string, string> $data    Extra `data-` attributes, unescaped.
	 */
	public static function embed( string $mount, array $data = array() ): string {
		if ( ! Settings::is_configured() ) {
			return '';
		}

		$attributes = array(
			'data-key'    => Settings::publishable_key(),
			'data-mount'  => $mount,
			'data-locale' => Locale::current(),
		);

		foreach ( $data as $name => $value ) {
			if ( '' !== $value ) {
				$attributes[ 'data-' . $name ] = $value;
			}
		}

		// The first embed on the page carries the `src`; the rest carry only
		// their attributes, because the bundle finds **every** script with a
		// `data-key` on the page and mounts one widget per tag (WGT-8).
		$src = self::$printed ? '' : sprintf( ' src="%s"', esc_url( self::url() ) );

		self::$printed = true;

		$rendered = '';

		foreach ( $attributes as $name => $value ) {
			$rendered .= sprintf( ' %s="%s"', esc_attr( $name ), esc_attr( $value ) );
		}

		return sprintf( '<script%s%s></script>', $src, $rendered );
	}

	/**
	 * The alias, never a versioned path.
	 *
	 * ADR-0011: an operator's embed names the alias so that a release reaches
	 * them without anybody editing anything. A plugin that pinned a version
	 * would be a plugin whose users run whatever was current when they last
	 * updated — which is the situation the alias exists to prevent.
	 */
	public static function url(): string {
		return Settings::api_base() . '/widget/kaiki-widget.js';
	}

	/**
	 * Forget that anything was printed. For tests, and for nothing else.
	 */
	public static function reset(): void {
		self::$printed = false;
	}
}
