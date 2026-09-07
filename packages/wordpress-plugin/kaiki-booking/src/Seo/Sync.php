<?php
/**
 * The sync itself: cron, webhook, and the loop between them.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Seo;

use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * One code path, two triggers (WPP-6, WPP-7).
 *
 * WP-Cron runs it hourly and the inbound webhook runs it on a change. Both call
 * {@see self::run()} — a webhook that took its own path would drift from the
 * nightly one, and the drift would show up as "it works when I wait and not when
 * I save", which is the hardest kind of bug to be told about.
 *
 * ## Resumable, because a shared host will interrupt it
 *
 * A catalogue of four hundred trips is four pages of a hundred, each of which
 * writes up to two posts per row, on a host that may kill the request at thirty
 * seconds. So the state is in options rather than in a local variable: the page
 * cursor and the run's starting point are saved after **every page**, and the
 * posts already written stay written. The next run picks up at the cursor, and
 * because every write is keyed on uuid and language, a page applied twice
 * changes nothing the second time.
 *
 * ## The high-water mark moves at the end, not during
 *
 * `meta.sync_cursor` is only stored once the traversal finishes. Storing it per
 * page would mean an interrupted run advancing the mark past products it never
 * wrote — the next run would ask for changes *after* them and they would never
 * be seen again. An interrupted run resumes from its page cursor with the same
 * starting point it began with.
 *
 * ## Locked, because cron and a webhook can arrive together
 *
 * Two runs at once is two `wp_insert_post` calls for a product that does not
 * exist yet, and WordPress will happily make both — one at `slug` and one at
 * `slug-2`. The lock is a transient with a short life, so a run killed
 * mid-flight does not leave the feature switched off until somebody notices.
 */
final class Sync {

	public const HOOK = 'kaiki_sync_trips';

	/**
	 * Where the traversal is, so an interrupted run resumes rather than restarts.
	 */
	public const CURSOR_OPTION = 'kaiki_sync_page_cursor';

	/**
	 * What this run asked for, kept for the length of the run.
	 */
	public const SINCE_OPTION = 'kaiki_sync_since';

	/**
	 * The high-water mark, moved only when a traversal completes.
	 */
	public const MARK_OPTION = 'kaiki_sync_mark';

	/**
	 * The last run's outcome, for the settings screen (WPP-14).
	 */
	public const STATUS_OPTION = 'kaiki_sync_status';

	private const LOCK = 'kaiki_sync_lock';

	/**
	 * Long enough that two triggers a second apart do not both run; short
	 * enough that a run killed by a host's time limit does not block the next
	 * hour's.
	 */
	private const LOCK_TTL = 600;

	/**
	 * How many pages one invocation will walk before stopping and leaving the
	 * rest to the next.
	 *
	 * Four hundred products at a hundred a page. A larger number is a longer
	 * request on a host that may not allow one; a smaller one means a big
	 * catalogue takes days to appear.
	 */
	private const PAGES_PER_RUN = 4;

	/**
	 * Hook the cron event and the webhook, or take the schedule away.
	 */
	public static function register(): void {
		if ( ! Settings::seo_pages_enabled() ) {
			// ADR-0013 Option A: with the feature off there is no schedule, no
			// call and no reason to hold a secret key.
			self::unschedule();

			return;
		}

		add_action( self::HOOK, array( self::class, 'run' ) );

		// The webhook's own hook, which {@see \Kaiki\Booking\Http\Webhook} fires
		// after it has verified a delivery. Verification is that file's job and
		// this one trusts it, which is the reason the two are separate.
		add_action( 'kaiki_webhook_received', array( self::class, 'run' ) );

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	/**
	 * Stop the schedule, for when the feature is switched off or removed.
	 */
	public static function unschedule(): void {
		$next = wp_next_scheduled( self::HOOK );

		if ( false !== $next ) {
			wp_unschedule_event( (int) $next, self::HOOK );
		}
	}

	/**
	 * Walk the feed and apply what it says.
	 *
	 * @return array<string, int> How many of each outcome, for a caller that wants to report.
	 */
	public static function run(): array {
		$counts = array(
			'created'     => 0,
			'updated'     => 0,
			'unchanged'   => 0,
			'unpublished' => 0,
			'trashed'     => 0,
			'skipped'     => 0,
		);

		if ( ! Settings::seo_pages_enabled() ) {
			return $counts;
		}

		if ( false !== get_transient( self::LOCK ) ) {
			return $counts;
		}

		set_transient( self::LOCK, 1, self::LOCK_TTL );

		$cursor = (string) get_option( self::CURSOR_OPTION, '' );

		// A run already in progress keeps the starting point it began with. A
		// fresh one takes the high-water mark, which is empty on the first ever
		// run and means "the whole catalogue".
		$since = '' !== $cursor
			? (string) get_option( self::SINCE_OPTION, '' )
			: (string) get_option( self::MARK_OPTION, '' );

		update_option( self::SINCE_OPTION, $since );

		$mark = $since;

		for ( $page = 0; $page < self::PAGES_PER_RUN; $page++ ) {
			$result = SyncClient::page( $cursor, $since );

			if ( ! $result->succeeded() ) {
				// The cursor stays where it is, so the next run resumes here.
				self::record( $result->error, $counts );

				delete_transient( self::LOCK );

				return $counts;
			}

			$body = $result->data ?? array();
			$rows = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();

			$locales = isset( $body['meta']['locales'] ) && is_array( $body['meta']['locales'] )
				? array_map( 'strval', $body['meta']['locales'] )
				: array();

			$languages = TripLanguages::wanted( array_values( $locales ) );

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				foreach ( $languages as $language ) {
					$outcome = TripRepository::apply( $row, $language );

					if ( isset( $counts[ $outcome ] ) ) {
						++$counts[ $outcome ];
					}
				}
			}

			$newest = isset( $body['meta']['sync_cursor'] ) ? (string) $body['meta']['sync_cursor'] : '';

			if ( '' !== $newest ) {
				$mark = $newest;
			}

			$cursor = isset( $body['pagination']['next_cursor'] ) && is_string( $body['pagination']['next_cursor'] )
				? $body['pagination']['next_cursor']
				: '';

			update_option( self::CURSOR_OPTION, $cursor );

			if ( '' === $cursor ) {
				// The traversal finished. **Only now** does the high-water mark
				// move: advancing it per page would step over products an
				// interrupted run never wrote.
				update_option( self::MARK_OPTION, $mark );
				update_option( self::SINCE_OPTION, '' );

				break;
			}
		}

		self::record( '', $counts );

		delete_transient( self::LOCK );

		return $counts;
	}

	/**
	 * What happened, in a form the settings screen can show an operator.
	 *
	 * Stored rather than logged: an operator is not going to read a log file,
	 * and "it says it last ran at four this morning and wrote nothing" is the
	 * only diagnosis most of them will ever need.
	 *
	 * @param string             $error  The failure, or '' for a clean run.
	 * @param array<string, int> $counts What was written.
	 */
	private static function record( string $error, array $counts ): void {
		update_option(
			self::STATUS_OPTION,
			array(
				'at'     => time(),
				'error'  => $error,
				'counts' => $counts,
			)
		);
	}
}
