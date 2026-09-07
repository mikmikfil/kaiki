<?php
/**
 * The sync loop: paging, resuming, and not losing anything (WPP-6).
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Seo\Sync;
use Kaiki\Booking\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * What one row at a time cannot show.
 *
 * The failures here are the ones that report success: a run interrupted at page
 * three that starts again from page one for ever, a high-water mark that steps
 * over products nobody wrote, a webhook and a cron arriving together and each
 * creating the same post. None of them raise anything; all of them end with an
 * operator saying some of their trips are missing and nobody able to say which.
 */
final class SyncRunTest extends TestCase {

	protected function setUp(): void {
		kaiki_test_reset();
		kaiki_test_reset_posts();

		update_option(
			Settings::OPTION,
			array(
				'publishable_key' => 'pk_test_abcdefghijklmnop',
				'api_base'        => 'https://book.kaiki.gr',
				'locale_mode'     => 'el',
				'seo_pages'       => true,
			)
		);

		update_option( Settings::SECRET_OPTION, 'sk_test_zyxwvutsrqponmlk' );
	}

	/**
	 * One page of the feed.
	 *
	 * @param  list<string> $slugs  A product per slug.
	 * @param  string|null  $next   The next cursor, or null for the last page.
	 * @param  string       $marker The `meta.sync_cursor` this page reports.
	 * @return array<string, mixed>
	 */
	private function page( array $slugs, ?string $next, string $marker ): array {
		$rows = array();

		foreach ( $slugs as $slug ) {
			$rows[] = array(
				'uuid'         => md5( $slug ),
				'slug'         => $slug,
				'status'       => 'active',
				'tombstone'    => false,
				'updated_at'   => $marker,
				'content_hash' => substr( md5( $slug . 'content' ), 0, 16 ),
				'translations' => array(
					'el' => array( 'title' => 'Εκδρομή ' . $slug ),
				),
				'product'      => array( 'duration_minutes' => 120 ),
			);
		}

		return array(
			'data'       => $rows,
			'pagination' => array(
				'per_page'    => 100,
				'has_more'    => null !== $next,
				'next_cursor' => $next,
			),
			'meta'       => array(
				'sync_cursor'    => $marker,
				'timezone'       => 'Europe/Athens',
				'default_locale' => 'el',
				'locales'        => array( 'el', 'en' ),
			),
		);
	}

	public function test_a_clean_run_writes_every_page_and_moves_the_mark_once(): void {
		kaiki_test_http_queue( 200, $this->page( array( 'a', 'b' ), 'cursor-2', '2026-06-01T00:00:00Z' ) );
		kaiki_test_http_queue( 200, $this->page( array( 'c' ), null, '2026-06-02T00:00:00Z' ) );

		$counts = Sync::run();

		$this->assertSame( 3, $counts['created'] );
		$this->assertCount( 3, $GLOBALS['kaiki_test_posts'] );

		// The traversal finished, so there is nothing to resume and the mark is
		// the newest thing actually sent.
		$this->assertSame( '', get_option( Sync::CURSOR_OPTION ) );
		$this->assertSame( '2026-06-02T00:00:00Z', get_option( Sync::MARK_OPTION ) );
	}

	public function test_a_run_interrupted_by_an_outage_resumes_at_the_page_it_stopped_on(): void {
		kaiki_test_http_queue( 200, $this->page( array( 'a' ), 'cursor-2', '2026-06-01T00:00:00Z' ) );
		// Nothing queued for the second page: the platform went away.

		$first = Sync::run();

		$this->assertSame( 1, $first['created'] );
		$this->assertSame( 'cursor-2', get_option( Sync::CURSOR_OPTION ) );

		// **The mark did not move.** Advancing it here would ask next time for
		// changes *after* products this run never wrote, and they would never be
		// seen again.
		$this->assertSame( false, get_option( Sync::MARK_OPTION ) );

		$status = get_option( Sync::STATUS_OPTION );

		$this->assertSame( 'unreachable', $status['error'] );

		// The lock is released, so the next hour's run is not blocked by a
		// failure this one already reported.
		kaiki_test_http_queue( 200, $this->page( array( 'b' ), null, '2026-06-02T00:00:00Z' ) );

		$second = Sync::run();

		$this->assertSame( 1, $second['created'] );
		$this->assertCount( 2, $GLOBALS['kaiki_test_posts'] );

		// It asked for the cursor it stopped on rather than starting again.
		$resumed = end( $GLOBALS['kaiki_test_http_requests'] );

		$this->assertStringContainsString( 'cursor=cursor-2', $resumed['url'] );
	}

	public function test_the_same_page_applied_twice_writes_nothing_the_second_time(): void {
		kaiki_test_http_queue( 200, $this->page( array( 'a', 'b' ), null, '2026-06-01T00:00:00Z' ) );

		Sync::run();

		kaiki_test_http_queue( 200, $this->page( array( 'a', 'b' ), null, '2026-06-01T00:00:00Z' ) );

		$again = Sync::run();

		$this->assertSame( 0, $again['created'] );
		$this->assertSame( 2, $again['unchanged'] );
		$this->assertCount( 2, $GLOBALS['kaiki_test_posts'] );
	}

	public function test_the_next_run_asks_only_for_what_changed(): void {
		kaiki_test_http_queue( 200, $this->page( array( 'a' ), null, '2026-06-01T00:00:00Z' ) );

		Sync::run();

		kaiki_test_http_queue( 200, $this->page( array(), null, '2026-06-01T00:00:00Z' ) );

		Sync::run();

		$second = end( $GLOBALS['kaiki_test_http_requests'] );

		$this->assertStringContainsString( 'updated_since=', $second['url'] );
		$this->assertStringContainsString( '2026-06-01', urldecode( $second['url'] ) );
	}

	public function test_it_sends_the_secret_as_a_bearer_token_and_no_origin(): void {
		kaiki_test_http_queue( 200, $this->page( array( 'a' ), null, '2026-06-01T00:00:00Z' ) );

		Sync::run();

		$request = $GLOBALS['kaiki_test_http_requests'][0];

		$this->assertSame( 'Bearer sk_test_zyxwvutsrqponmlk', $request['headers']['Authorization'] );

		// The platform refuses a secret key presented with an `Origin`, because a
		// browser always sends one. Never sending one is why this works at all.
		$this->assertArrayNotHasKey( 'Origin', $request['headers'] );
	}

	public function test_a_refused_key_stops_rather_than_writing_anything(): void {
		kaiki_test_http_queue( 403, array( 'error' => array( 'code' => 'secret_key_required' ) ) );

		$counts = Sync::run();

		$this->assertSame( 0, $counts['created'] );
		$this->assertSame( array(), $GLOBALS['kaiki_test_posts'] );

		$status = get_option( Sync::STATUS_OPTION );

		$this->assertSame( 'refused', $status['error'] );
	}

	public function test_it_does_nothing_and_asks_for_nothing_when_the_feature_is_off(): void {
		update_option(
			Settings::OPTION,
			array(
				'publishable_key' => 'pk_test_abcdefghijklmnop',
				'api_base'        => 'https://book.kaiki.gr',
				'seo_pages'       => false,
			)
		);

		Sync::run();

		// ADR-0013 Option A: no call, and therefore no secret key required.
		$this->assertSame( array(), $GLOBALS['kaiki_test_http_requests'] );
	}

	public function test_two_triggers_at_once_do_not_both_run(): void {
		// A webhook arriving while cron is mid-run is two `wp_insert_post` calls
		// for a product that does not exist yet, and WordPress makes both.
		kaiki_test_http_queue( 200, $this->page( array( 'a' ), null, '2026-06-01T00:00:00Z' ) );

		Sync::run();

		set_transient( 'kaiki_sync_lock', 1, 600 );

		kaiki_test_http_queue( 200, $this->page( array( 'a' ), null, '2026-06-01T00:00:00Z' ) );

		$blocked = Sync::run();

		$this->assertSame( 0, $blocked['unchanged'] );
		$this->assertCount( 1, $GLOBALS['kaiki_test_http_requests'] );
	}
}
