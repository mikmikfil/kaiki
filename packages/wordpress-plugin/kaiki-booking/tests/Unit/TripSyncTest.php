<?php
/**
 * What the SEO sync decides (WPP-6).
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Seo\TripContent;
use Kaiki\Booking\Seo\TripLanguages;
use Kaiki\Booking\Seo\TripPostType;
use Kaiki\Booking\Seo\TripRepository;
use Kaiki\Booking\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The sync's judgement, without WordPress.
 *
 * Every case here is one an operator would find out about weeks later and blame
 * on something else: a URL that stopped working, a summary they rewrote that
 * changed back, a trip that came off the site when they only meant to hide it
 * for the winter.
 */
final class TripSyncTest extends TestCase {

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
	}

	/**
	 * One live row of the feed.
	 *
	 * @param  array<string, mixed> $overrides What this test cares about.
	 * @return array<string, mixed>
	 */
	private function row( array $overrides = array() ): array {
		return array_merge(
			array(
				'uuid'         => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
				'slug'         => 'kroyaziera-aigina',
				'status'       => 'active',
				'tombstone'    => false,
				'updated_at'   => '2026-06-18T08:12:00Z',
				'content_hash' => '9f2c1a7e4b8d0356',
				'translations' => array(
					'el' => array(
						'title'       => 'Κρουαζιέρα Αίγινα',
						'summary'     => 'Ολοήμερη κρουαζιέρα.',
						'description' => '<p>Αναχωρούμε από τη Ζέα.</p>',
						'includes'    => array( 'Γεύμα', 'Ποτά' ),
					),
					'en' => array(
						'title'   => 'Aegina Cruise',
						'summary' => 'A full day out.',
					),
				),
				'product'      => array(
					'duration_minutes' => 480,
					'max_pax'          => 12,
					'vessel'           => array( 'name' => 'Θάλασσα' ),
					'meeting_point'    => array( 'name' => 'Ζέα' ),
					'seo'              => array( 'canonical_url' => 'https://book.kaiki.gr/aegean/kroyaziera-aigina' ),
				),
			),
			$overrides
		);
	}

	public function test_a_new_product_becomes_a_published_post_with_real_content_and_a_mount(): void {
		$this->assertSame( 'created', TripRepository::apply( $this->row(), 'el' ) );

		$post = $GLOBALS['kaiki_test_posts'][1];

		$this->assertSame( 'publish', $post['post_status'] );
		$this->assertSame( 'Κρουαζιέρα Αίγινα', $post['post_title'] );
		$this->assertSame( 'kroyaziera-aigina', $post['post_name'] );

		// The crawler's half: prose and facts, in the markup, with no JavaScript.
		$this->assertStringContainsString( 'Αναχωρούμε από τη Ζέα', $post['post_content'] );
		$this->assertStringContainsString( 'Θάλασσα', $post['post_content'] );
		$this->assertStringContainsString( 'Γεύμα', $post['post_content'] );

		// The visitor's half: the widget, at the end, keyed by uuid.
		$this->assertStringContainsString(
			'[kaiki_booking product="7c9e6679-7425-40de-944b-e07fc1f90ae7"]',
			$post['post_content']
		);
	}

	public function test_the_operator_html_is_filtered_rather_than_escaped_or_trusted(): void {
		$row = $this->row();

		$row['translations']['el']['description'] = '<p>Καλώς ήρθατε</p><script>alert(1)</script>';

		TripRepository::apply( $row, 'el' );

		$content = $GLOBALS['kaiki_test_posts'][1]['post_content'];

		// Escaped would print the tags at the visitor; unfiltered would make
		// this plugin a way to put script on somebody's site through the API.
		$this->assertStringContainsString( '<p>Καλώς ήρθατε</p>', $content );
		$this->assertStringNotContainsString( '<script>', $content );
	}

	public function test_a_trip_switched_off_is_unpublished_and_keeps_everything_else(): void {
		TripRepository::apply( $this->row(), 'el' );

		$outcome = TripRepository::apply(
			$this->row(
				array(
					'status'       => 'inactive',
					'content_hash' => '9f2c1a7e4b8d0356',
				)
			),
			'el'
		);

		$this->assertSame( 'unpublished', $outcome );
		$this->assertSame( 'draft', $GLOBALS['kaiki_test_posts'][1]['post_status'] );
		// One post, not a second one: the URL, the comments and the ranking are
		// what an operator loses if this creates `slug-2` next season.
		$this->assertCount( 1, $GLOBALS['kaiki_test_posts'] );
	}

	public function test_a_deleted_product_is_trashed_rather_than_destroyed(): void {
		TripRepository::apply( $this->row(), 'el' );

		$outcome = TripRepository::apply(
			array(
				'uuid'       => '7c9e6679-7425-40de-944b-e07fc1f90ae7',
				'slug'       => 'kroyaziera-aigina',
				'status'     => 'archived',
				'tombstone'  => true,
				'deleted_at' => '2026-08-01T10:00:00Z',
				'updated_at' => '2026-08-01T10:00:00Z',
			),
			'el'
		);

		$this->assertSame( 'trashed', $outcome );
		$this->assertSame( 'trash', $GLOBALS['kaiki_test_posts'][1]['post_status'] );
		// Still there, and still recognisable: a restore on the platform must
		// find this post rather than make a second one.
		$this->assertSame(
			'7c9e6679-7425-40de-944b-e07fc1f90ae7',
			$GLOBALS['kaiki_test_post_meta'][1][ TripPostType::META_UUID ]
		);
	}

	public function test_a_tombstone_for_a_product_this_site_never_had_is_not_a_failure(): void {
		$outcome = TripRepository::apply(
			array(
				'uuid'      => 'aa11bb22-cc33-4d44-8e55-ff6677889900',
				'slug'      => 'never-seen',
				'status'    => 'archived',
				'tombstone' => true,
			),
			'el'
		);

		$this->assertSame( 'skipped', $outcome );
		$this->assertSame( array(), $GLOBALS['kaiki_test_posts'] );
	}

	public function test_an_unchanged_product_is_left_alone(): void {
		TripRepository::apply( $this->row(), 'el' );

		$this->assertSame( 'unchanged', TripRepository::apply( $this->row(), 'el' ) );
	}

	public function test_a_summary_the_operator_rewrote_survives_the_next_sync(): void {
		TripRepository::apply( $this->row(), 'el' );

		// The operator edits the excerpt in WordPress.
		$GLOBALS['kaiki_test_posts'][1]['post_excerpt'] = 'Η δική μας περιγραφή.';

		// And the trip changes on the platform, so the row is not skipped.
		TripRepository::apply(
			$this->row(
				array(
					'content_hash' => 'aaaabbbbccccdddd',
					'translations' => array(
						'el' => array(
							'title'   => 'Κρουαζιέρα Αίγινα',
							'summary' => 'Καινούργια περίληψη από το Kaiki.',
						),
					),
				)
			),
			'el'
		);

		$this->assertSame( 'Η δική μας περιγραφή.', $GLOBALS['kaiki_test_posts'][1]['post_excerpt'] );
	}

	public function test_a_summary_the_operator_never_touched_keeps_following_the_platform(): void {
		TripRepository::apply( $this->row(), 'el' );

		TripRepository::apply(
			$this->row(
				array(
					'content_hash' => 'aaaabbbbccccdddd',
					'translations' => array(
						'el' => array(
							'title'   => 'Κρουαζιέρα Αίγινα',
							'summary' => 'Καινούργια περίληψη.',
						),
					),
				)
			),
			'el'
		);

		$this->assertSame( 'Καινούργια περίληψη.', $GLOBALS['kaiki_test_posts'][1]['post_excerpt'] );
	}

	public function test_the_url_never_changes_after_the_post_exists(): void {
		TripRepository::apply( $this->row(), 'el' );

		TripRepository::apply(
			$this->row(
				array(
					'slug'         => 'kroyaziera-aigina-agkistri',
					'content_hash' => 'aaaabbbbccccdddd',
				)
			),
			'el'
		);

		// Renaming a trip on the platform must not break every link anybody has
		// shared — WordPress leaves no redirect behind.
		$this->assertSame( 'kroyaziera-aigina', $GLOBALS['kaiki_test_posts'][1]['post_name'] );
	}

	public function test_a_language_the_operator_has_not_translated_gets_no_page(): void {
		$row = $this->row(
			array(
				'translations' => array(
					'el' => array( 'title' => 'Κρουαζιέρα' ),
					'en' => array( 'title' => null ),
				),
			)
		);

		$this->assertSame( 'created', TripRepository::apply( $row, 'el' ) );
		// Not an English page with a Greek title on it.
		$this->assertSame( 'skipped', TripRepository::apply( $row, 'en' ) );
		$this->assertCount( 1, $GLOBALS['kaiki_test_posts'] );
	}

	public function test_two_languages_are_two_posts_at_two_addresses(): void {
		TripRepository::apply( $this->row(), 'el' );
		TripRepository::apply( $this->row(), 'en' );

		$this->assertCount( 2, $GLOBALS['kaiki_test_posts'] );
		$this->assertSame( 'Κρουαζιέρα Αίγινα', $GLOBALS['kaiki_test_posts'][1]['post_title'] );
		$this->assertSame( 'Aegina Cruise', $GLOBALS['kaiki_test_posts'][2]['post_title'] );

		// Greek is the site's language here, so it keeps the platform's slug and
		// English is suffixed. Sharing one slug would have WordPress resolve the
		// collision by appending `-2` to whichever was written second.
		$this->assertSame( 'kroyaziera-aigina', $GLOBALS['kaiki_test_posts'][1]['post_name'] );
		$this->assertSame( 'kroyaziera-aigina-en', $GLOBALS['kaiki_test_posts'][2]['post_name'] );
	}

	public function test_the_canonical_points_at_kaiki_when_the_operator_has_hosted_pages(): void {
		TripRepository::apply( $this->row(), 'el' );

		$this->assertSame(
			'https://book.kaiki.gr/aegean/kroyaziera-aigina',
			$GLOBALS['kaiki_test_post_meta'][1][ TripPostType::META_CANONICAL ]
		);
	}

	public function test_a_trip_that_was_never_published_is_created_as_a_draft(): void {
		TripRepository::apply( $this->row( array( 'status' => 'draft' ) ), 'el' );

		$this->assertSame( 'draft', $GLOBALS['kaiki_test_posts'][1]['post_status'] );
	}

	public function test_a_single_language_site_gets_one_page_in_its_own_language(): void {
		$this->assertSame( array( 'el' ), TripLanguages::wanted( array( 'el', 'en' ) ) );
	}

	public function test_a_language_the_operator_does_not_publish_falls_back_rather_than_producing_nothing(): void {
		// The site is Greek; the operator publishes only English.
		$this->assertSame( array( 'en' ), TripLanguages::wanted( array( 'en' ) ) );
	}

	public function test_a_meta_description_falls_back_to_the_summary(): void {
		$meta = TripContent::meta( $this->row(), 'el' );

		$this->assertSame( 'Κρουαζιέρα Αίγινα', $meta['title'] );
		$this->assertSame( 'Ολοήμερη κρουαζιέρα.', $meta['description'] );
	}
}
