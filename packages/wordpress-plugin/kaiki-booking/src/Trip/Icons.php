<?php
/**
 * The line icons beside trip facts and list items.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Trip;

defined( 'ABSPATH' ) || exit;

/**
 * Inline SVG, so a trip page loads no icon font and no second request.
 * `currentColor`, so a theme or Elementor colours them with the text.
 */
final class Icons {

	/**
	 * Path data by name.
	 */
	private const PATHS = array(
		'category'      => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>',
		'star'          => '<path d="M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8-4.3-4.1 5.9-.9z"/>',
		'duration'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'departure'     => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
		'meeting_point' => '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
		'vessel'        => '<path d="M3 17l2 3h14l2-3H3z"/><path d="M12 3v11M12 4l6 10H12"/>',
		'capacity'      => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14a6.5 6.5 0 0 1 3.5 6"/>',
		'check'         => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
		'cross'         => '<path d="M6 6l12 12M18 6L6 18"/>',
		'bag'           => '<path d="M5 8h14l-1 12H6L5 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
	);

	/**
	 * One icon, decorative, or '' for a name there is no icon for.
	 *
	 * @param string $name A key of {@see PATHS}.
	 */
	public static function svg( string $name ): string {
		if ( ! isset( self::PATHS[ $name ] ) ) {
			return '';
		}

		return '<svg class="kaiki-trip-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::PATHS[ $name ] . '</svg>';
	}
}
