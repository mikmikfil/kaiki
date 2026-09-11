<?php
/**
 * The operator's own look, as the attributes the widget reads.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Assets;

use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The «Appearance» settings, turned into `data-` attributes on every embed.
 *
 * ## Only what was set, and nothing at all for «As in Kaiki»
 *
 * The widget already has a look: the branding the operator set in their Kaiki
 * panel. Every attribute here is an **override** of one part of it, so an
 * attribute that is absent means "use Kaiki's", and the default setting emits
 * none — a site that never opened this section renders exactly what it rendered
 * before the section existed.
 *
 * ## The button's text colour is not a setting
 *
 * It is worked out from the button colour, because the only right answer is
 * "whichever can be read", and asking an operator for it is asking them to get
 * it wrong: a pale yellow button with white text is the mistake a colour picker
 * makes easy and a guest on a phone in the sun cannot read.
 */
final class Appearance {

	/**
	 * White, for dark buttons.
	 */
	public const LIGHT_TEXT = '#ffffff';

	/**
	 * Near-black, for light buttons. Not pure black, which looks harsher than
	 * the contrast it buys.
	 */
	public const DARK_TEXT = '#111111';

	/**
	 * The attributes for every embed, without the `data-` prefix `Bundle::embed`
	 * adds.
	 *
	 * @return array<string, string>
	 */
	public static function attributes(): array {
		$settings = Settings::all();

		if ( 'custom' !== $settings['appearance'] ) {
			return array();
		}

		$attributes = array();

		if ( '' !== $settings['primary'] ) {
			$attributes['primary']    = $settings['primary'];
			$attributes['on-primary'] = self::on_primary( $settings['primary'] );
		}

		if ( '' !== $settings['text'] ) {
			$attributes['text'] = $settings['text'];
		}

		if ( '' !== $settings['background'] ) {
			$attributes['background'] = $settings['background'];
		}

		// `inherit` is the widget's word for "the page's font": it cannot see the
		// theme's font through the shadow boundary, but it can inherit it. Kaiki's
		// own font is the widget's default, so that choice needs no attribute.
		if ( 'theme' === $settings['font_mode'] ) {
			$attributes['font'] = 'inherit';
		} elseif ( 'custom' === $settings['font_mode'] && '' !== $settings['font_name'] ) {
			$attributes['font'] = $settings['font_name'];
		}

		if ( null !== $settings['radius'] ) {
			$attributes['radius'] = (string) $settings['radius'];
		}

		return $attributes;
	}

	/**
	 * White or near-black, whichever reads better on the given colour.
	 *
	 * WCAG 2's contrast ratio, `(lighter + 0.05) / (darker + 0.05)` over relative
	 * luminance, computed against both candidates. A tie goes to white.
	 *
	 * @param string $colour A `#rrggbb` colour.
	 */
	public static function on_primary( string $colour ): string {
		$luminance = self::luminance( $colour );

		$against_light = self::contrast( $luminance, self::luminance( self::LIGHT_TEXT ) );
		$against_dark  = self::contrast( $luminance, self::luminance( self::DARK_TEXT ) );

		return $against_light >= $against_dark ? self::LIGHT_TEXT : self::DARK_TEXT;
	}

	/**
	 * WCAG 2 relative luminance of a `#rrggbb` colour, 0 for black to 1 for white.
	 *
	 * @param string $colour A `#rrggbb` colour.
	 */
	private static function luminance( string $colour ): float {
		$channels = array_map(
			static function ( string $pair ): float {
				$value = hexdec( $pair ) / 255;

				// The sRGB transfer curve, undone: screen values are not
				// proportional to light, and luminance has to be.
				return $value <= 0.03928 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
			},
			str_split( substr( $colour, 1, 6 ), 2 )
		);

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * The contrast ratio of two luminances, from 1 (none) to 21 (black on white).
	 *
	 * @param float $first  One luminance.
	 * @param float $second The other.
	 */
	private static function contrast( float $first, float $second ): float {
		return ( max( $first, $second ) + 0.05 ) / ( min( $first, $second ) + 0.05 );
	}
}
