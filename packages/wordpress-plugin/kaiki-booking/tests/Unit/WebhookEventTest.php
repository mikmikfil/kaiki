<?php
/**
 * What a verified delivery does.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Cache\Cache;
use Kaiki\Booking\Http\Webhook;
use Kaiki\Booking\Seo\Sync;
use Kaiki\Booking\Settings\Settings;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Response;

/**
 * A catalogue event empties the cache and asks for a sync; anything else is
 * acknowledged and left alone; a retry is a no-op.
 *
 * The sync is asserted as **scheduled**, not run: a run inside the webhook
 * request is four pages of a hundred products against Kaiki's ten-second
 * timeout, and the stubbed HTTP layer answering nothing is what proves no page
 * was fetched here.
 */
final class WebhookEventTest extends TestCase {

	/**
	 * The `DELETE … LIKE 'kaiki_%'` statements `Cache::flush()` ran.
	 *
	 * @var list<string>
	 */
	private array $queries = array();

	protected function setUp(): void {
		kaiki_test_reset();
		kaiki_test_reset_posts();

		$GLOBALS['kaiki_test_cron_spawned'] = 0;

		$queries         = &$this->queries;
		$GLOBALS['wpdb'] = new class( $queries ) {
			/**
			 * The table name WordPress would give it.
			 *
			 * @var string
			 */
			public string $options = 'wp_options';

			/**
			 * Where statements are written.
			 *
			 * @var list<string>
			 */
			private array $log;

			/**
			 * @param list<string> $log Where statements are written.
			 */
			public function __construct( array &$log ) {
				$this->log = &$log;
			}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			/**
			 * @param string $query The statement.
			 * @param mixed  ...$args Its values.
			 */
			public function prepare( string $query, ...$args ): string {
				return vsprintf( str_replace( '%s', "'%s'", $query ), $args );
			}

			public function query( string $query ): int {
				$this->log[] = $query;

				return 0;
			}
		};

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

		Sync::register();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * A delivery envelope as §8.2 describes it.
	 *
	 * @param  string $event The event name.
	 * @param  string $id    The delivery id.
	 * @return array<string, mixed>
	 */
	private function payload( string $event, string $id = 'd-1' ): array {
		return array(
			'id'          => $id,
			'event'       => $event,
			'api_version' => '1',
			'is_test'     => false,
			'data'        => array(
				'product' => array(
					'uuid'    => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
					'slug'    => 'aegina-agistri',
					'status'  => 'active',
					'deleted' => false,
				),
			),
		);
	}

	/**
	 * @dataProvider catalogue_events
	 *
	 * @param string $event A `product.*` event.
	 */
	public function test_a_catalogue_event_flushes_the_cache_and_schedules_one_sync( string $event ): void {
		set_transient( Cache::PREFIX . 'abc', array( 'stale' ), 3600 );

		$response = Webhook::receive( $this->payload( $event ), 'delivery-1' );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->status );
		$this->assertTrue( $response->data['handled'] );

		$this->assertCount( 1, $this->queries, 'The cache is flushed once.' );
		$this->assertStringContainsString( 'DELETE FROM wp_options', $this->queries[0] );
		// Cache keys only: exactly 32 characters after `kaiki_`, no `%`. A
		// wildcard also deleted the sync's lock and the record of deliveries
		// already handled.
		$this->assertStringContainsString( 'kaiki\\_' . str_repeat( '_', 32 ) . "'", $this->queries[0] );
		$this->assertStringNotContainsString( '%', $this->queries[0] );

		$this->assertNotFalse( wp_next_scheduled( Sync::SOON_HOOK ), 'A one-off sync is scheduled.' );
		$this->assertSame( 1, $GLOBALS['kaiki_test_cron_spawned'], 'WP-Cron is nudged rather than waiting for a visitor.' );
		$this->assertSame( array(), $GLOBALS['kaiki_test_http_requests'], 'Nothing was fetched inside the webhook request.' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function catalogue_events(): array {
		return array(
			'published'   => array( 'product.published' ),
			'updated'     => array( 'product.updated' ),
			'unpublished' => array( 'product.unpublished' ),
		);
	}

	public function test_two_catalogue_events_schedule_one_sync(): void {
		Webhook::receive( $this->payload( 'product.updated', 'a' ), 'a' );
		$first = wp_next_scheduled( Sync::SOON_HOOK );

		$GLOBALS['kaiki_test_cron'][ Sync::SOON_HOOK ] = 12345;

		Webhook::receive( $this->payload( 'product.updated', 'b' ), 'b' );

		$this->assertNotFalse( $first );
		$this->assertSame( 12345, wp_next_scheduled( Sync::SOON_HOOK ), 'The waiting event is left where it is.' );
	}

	public function test_a_booking_event_is_acknowledged_and_changes_nothing(): void {
		$response = Webhook::receive( $this->payload( 'booking.confirmed' ), 'delivery-2' );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->status );
		$this->assertFalse( $response->data['handled'] );
		$this->assertSame( array(), $this->queries );
		$this->assertFalse( wp_next_scheduled( Sync::SOON_HOOK ) );
	}

	public function test_a_retry_of_the_same_delivery_is_a_no_op(): void {
		Webhook::receive( $this->payload( 'product.updated' ), 'same-delivery' );

		unset( $GLOBALS['kaiki_test_cron'][ Sync::SOON_HOOK ] );
		$this->queries = array();

		$response = Webhook::receive( $this->payload( 'product.updated' ), 'same-delivery' );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->status );
		$this->assertTrue( $response->data['duplicate'] );
		$this->assertSame( array(), $this->queries );
		$this->assertFalse( wp_next_scheduled( Sync::SOON_HOOK ) );
	}

	public function test_the_body_id_is_used_when_the_header_is_missing(): void {
		Webhook::receive( $this->payload( 'product.updated', 'body-id' ) );

		$response = Webhook::receive( $this->payload( 'product.updated', 'body-id' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->data['duplicate'] );
	}

	public function test_a_delivery_with_no_id_at_all_is_refused(): void {
		$payload = $this->payload( 'product.updated' );
		unset( $payload['id'] );

		$this->assertInstanceOf( WP_Error::class, Webhook::receive( $payload ) );
	}

	public function test_with_seo_pages_off_the_cache_is_still_flushed_but_nothing_is_scheduled(): void {
		kaiki_test_reset_posts();
		update_option( Settings::OPTION, array( 'seo_pages' => false ) );
		Sync::register();

		$response = Webhook::receive( $this->payload( 'product.updated' ), 'delivery-3' );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertCount( 1, $this->queries );
		$this->assertFalse( wp_next_scheduled( Sync::SOON_HOOK ) );
	}

	public function test_a_sync_already_running_puts_the_webhook_run_back_a_minute(): void {
		set_transient( 'kaiki_sync_lock', 1, 600 );

		Sync::run_soon();

		$this->assertGreaterThan( time(), (int) wp_next_scheduled( Sync::SOON_HOOK ) );
		$this->assertSame( array(), $GLOBALS['kaiki_test_http_requests'] );
	}
}
