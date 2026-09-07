<?php
/**
 * Plugin Name:       Kaiki Booking
 * Plugin URI:        https://kaiki.gr
 * Description:       Take boat trip bookings on your own WordPress site. Shortcodes, blocks and Elementor widgets for the Kaiki booking platform.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Kaiki
 * Author URI:        https://kaiki.gr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kaiki-booking
 * Domain Path:       /languages
 *
 * @package Kaiki\Booking
 */

/**
 * The plugin's front door, and everything it deliberately is not.
 *
 * ## PHP 8.1, and that is not a preference
 *
 * The platform is 8.4 everywhere (ADR-0014). This is not, because it runs on
 * whatever the operator's Greek shared host offers — and half of them are on
 * 8.1. A plugin that needs 8.3 is a plugin those operators cannot install and
 * will not understand why. `phpstan.neon` excludes this directory from the main
 * run for the same reason: one analysis cannot hold two language versions.
 *
 * ## No framework, and a thin client of the public API
 *
 * WPP-1 and WPP-2. Everything the plugin shows comes from `/api/v1`; it computes
 * no price, decides no availability and touches no WooCommerce cart. Two
 * implementations of a price is one wrong invoice, and the widget already learnt
 * that lesson under WGT-13.
 *
 * ## It refuses to run rather than fatal
 *
 * A `require` of a missing autoloader on an operator's live site is a white
 * screen on their booking page. The guard below turns that into an admin notice
 * they can read and act on, which is WPP-14 applied to the plugin's own
 * installation rather than to the API.
 */

declare( strict_types = 1 );

namespace Kaiki\Booking;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';

const FILE = __FILE__;

/**
 * Composer's autoloader, or an admin notice saying it is missing.
 *
 * The zip built by the release workflow always carries `vendor/`. A checkout
 * does not until somebody runs `composer install`, and that somebody is a
 * developer who can read a notice — an operator never sees this path.
 */
if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Kaiki Booking cannot start: its dependencies are missing. Run composer install in the plugin directory, or install the release zip instead of a checkout.', 'kaiki-booking' )
			);
		}
	);

	return;
}

require_once __DIR__ . '/vendor/autoload.php';

Plugin::boot();
