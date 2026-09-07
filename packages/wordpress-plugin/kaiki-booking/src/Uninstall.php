<?php
/**
 * The cleanup, in a class so it can be read and tested.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking;

defined( 'ABSPATH' ) || exit;

use Kaiki\Booking\Seo\Sync;
use Kaiki\Booking\Seo\TripPostType;
use Kaiki\Booking\Settings\Settings;

/**
 * Everything the plugin ever wrote, removed (WPP-11).
 *
 * Loaded by `uninstall.php` without the autoloader, because WordPress runs that
 * file in a bare process where the plugin has not booted. So this class must
 * not depend on anything the plugin does at runtime.
 */
final class Uninstall {

	/**
	 * The transient prefix every cached API read uses.
	 *
	 * Named here as well as in the cache itself, and that duplication is
	 * deliberate: uninstall runs in a process where the cache class may not be
	 * loaded, and a cleanup that silently skipped the transients would leave
	 * exactly the rows this exists to remove.
	 */
	public const TRANSIENT_PREFIX = 'kaiki_';

	/**
	 * Remove every option, transient and schedule the plugin created.
	 */
	public static function run(): void {
		require_once __DIR__ . '/Settings/Settings.php';
		require_once __DIR__ . '/Seo/Sync.php';
		require_once __DIR__ . '/Seo/TripPostType.php';

		delete_option( Settings::OPTION );
		delete_option( Settings::SECRET_OPTION );
		delete_option( Settings::WEBHOOK_SECRET_OPTION );

		// The sync's own bookkeeping (#116): where the traversal was, what it
		// asked for, how far it has got and how the last run went.
		delete_option( Sync::CURSOR_OPTION );
		delete_option( Sync::SINCE_OPTION );
		delete_option( Sync::MARK_OPTION );
		delete_option( Sync::STATUS_OPTION );
		delete_option( TripPostType::BASE_OPTION );

		self::delete_transients();

		// A site-wide install has one row per site, and a multisite network
		// deleting the plugin should not leave the other ninety-nine behind.
		if ( is_multisite() ) {
			self::delete_network_options();
		}

		// The real hook name, from the class that schedules it. A literal here
		// was wrong for a whole milestone — `kaiki_sync_products`, which nothing
		// ever scheduled — and the failure of a `wp_clear_scheduled_hook` on a
		// hook that does not exist is silence.
		wp_clear_scheduled_hook( Sync::HOOK );
	}

	/**
	 * ## The trip posts are deliberately left behind
	 *
	 * They are an operator's pages: linked to from their own site, shared, and
	 * indexed. Deleting a hundred of them because a plugin was removed — perhaps
	 * to reinstall it, perhaps by a host doing housekeeping — is the one
	 * irreversible thing this file could do, and it would be done silently. The
	 * same reasoning the sync itself follows: unpublish, trash, never destroy.
	 *
	 * They become invisible when the post type stops being registered, and they
	 * come back if the plugin does. `readme.txt` says so, because an operator
	 * who wants them gone should be told how rather than surprised.
	 */

	/**
	 * Transients, by prefix.
	 *
	 * `delete_transient` needs a name, and the cache keys carry a hash of what
	 * was asked for — so there is no list to walk. A direct query on the options
	 * table is the documented way to do this, and uninstall is the one place it
	 * is justified: it runs once, on deletion, and the alternative is leaving
	 * rows behind for ever.
	 */
	private static function delete_transients(): void {
		global $wpdb;

		$like         = $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%';
		$timeout_like = $wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall, once, and there is no API that deletes by prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like, $timeout_like ) );
	}

	/**
	 * The network-wide copies, on a multisite install.
	 */
	private static function delete_network_options(): void {
		delete_site_option( Settings::OPTION );
		delete_site_option( Settings::SECRET_OPTION );
		delete_site_option( Settings::WEBHOOK_SECRET_OPTION );
	}
}
