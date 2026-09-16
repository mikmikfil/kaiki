<?php
/**
 * Linking a trip's pages to each other, and not linking what should not be.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Seo\TripPostType;
use Kaiki\Booking\Seo\TripTranslations;
use Kaiki\Booking\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * WPML, and the site with no translation plugin at all.
 *
 * ## Why there is no Polylang test here
 *
 * `TripTranslations` chooses between the two the same way the rest of the plugin
 * does — `function_exists`, Polylang first — and a function defined for one test
 * in this process is defined for every test after it. Defining
 * `pll_languages_list()` in the bootstrap would therefore make the WPML branch
 * unreachable in the whole suite, which is the branch an operator actually
 * asked for.
 *
 * The Polylang path is two calls with no branching in them
 * (`pll_set_post_language` then `pll_save_post_translations`); the part that can
 * be wrong is the slug mapping, and that is shared with WPML and covered below.
 */
final class TripTranslationsTest extends TestCase {

	private const UUID = 'f9ff7002-402d-44da-b102-77bffb8b68ea';

	/**
	 * Every `wpml_set_element_language_details` the code sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $told = array();

	protected function setUp(): void {
		kaiki_test_reset();
		kaiki_test_reset_posts();

		$this->told = array();

		update_option(
			Settings::OPTION,
			array(
				'publishable_key' => 'pk_live_abc',
				'api_base'        => 'https://book.kaiki.gr',
				// The site's own language, which decides which page is the
				// source WPML hangs the others off.
				'locale_mode'     => 'el',
			)
		);
	}

	public function test_it_tells_wpml_the_greek_page_is_the_original(): void {
		$el = $this->trip_post( 'el' );
		$en = $this->trip_post( 'en' );

		$this->pretend_wpml( array( 'el', 'en' ) );

		TripTranslations::link( self::UUID, array( 'el', 'en' ) );

		$this->assertCount( 2, $this->told );

		$source = $this->told[0];

		$this->assertSame( $el, $source['element_id'] );
		$this->assertSame( 'el', $source['language_code'] );
		$this->assertNull( $source['source_language_code'], 'The original is translated from nothing.' );
		$this->assertSame( 'post_' . TripPostType::POST_TYPE, $source['element_type'] );

		$translation = $this->told[1];

		$this->assertSame( $en, $translation['element_id'] );
		$this->assertSame( 'en', $translation['language_code'] );
		$this->assertSame( 'el', $translation['source_language_code'] );
	}

	public function test_both_pages_land_in_one_translation_group(): void {
		// The trid is the group. Two pages with different trids are two
		// untranslated pages, which is the bug this exists to fix.
		$this->trip_post( 'el' );
		$this->trip_post( 'en' );

		$this->pretend_wpml( array( 'el', 'en' ) );

		TripTranslations::link( self::UUID, array( 'el', 'en' ) );

		$trids = array_column( $this->told, 'trid' );

		$this->assertSame( array( 77, 77 ), $trids );
	}

	public function test_it_uses_the_language_codes_the_site_actually_has(): void {
		// A site whose English is `en-GB` must be told `en-GB`. Handing it our
		// internal `en` files the post under a language that does not exist
		// there — which is how a page disappears from every language at once.
		$this->trip_post( 'el' );
		$this->trip_post( 'en' );

		$this->pretend_wpml( array( 'el', 'en-GB' ) );

		TripTranslations::link( self::UUID, array( 'el', 'en' ) );

		$this->assertSame(
			array( 'el', 'en-GB' ),
			array_column( $this->told, 'language_code' )
		);
	}

	public function test_a_site_with_no_translation_plugin_is_left_alone(): void {
		// The common case: one Greek site, one post per trip, and nothing to
		// tell anybody. No hooks are registered, so none may be called.
		$this->trip_post( 'el' );

		TripTranslations::link( self::UUID, array( 'el' ) );

		$this->assertSame( array(), $this->told );
	}

	public function test_a_language_with_no_page_yet_is_not_linked(): void {
		// The first run writes Greek and English in order, and a resumable sync
		// can stop between them. A group must not name a post that is not there.
		$el = $this->trip_post( 'el' );

		$this->pretend_wpml( array( 'el', 'en' ) );

		TripTranslations::link( self::UUID, array( 'el', 'en' ) );

		$this->assertCount( 1, $this->told );
		$this->assertSame( $el, $this->told[0]['element_id'] );
	}

	public function test_it_does_not_link_another_trip_s_pages(): void {
		$el = $this->trip_post( 'el' );

		$other = wp_insert_post( array( 'post_type' => TripPostType::POST_TYPE ) );
		update_post_meta( $other, TripPostType::META_UUID, '11111111-2222-3333-4444-555555555555' );
		update_post_meta( $other, TripPostType::META_LANG, 'en' );

		$this->pretend_wpml( array( 'el', 'en' ) );

		TripTranslations::link( self::UUID, array( 'el', 'en' ) );

		$this->assertSame( array( $el ), array_column( $this->told, 'element_id' ) );
	}

	/**
	 * A synced trip page in one language.
	 */
	private function trip_post( string $locale ): int {
		$id = wp_insert_post( array( 'post_type' => TripPostType::POST_TYPE ) );

		update_post_meta( $id, TripPostType::META_UUID, self::UUID );
		update_post_meta( $id, TripPostType::META_LANG, $locale );

		return (int) $id;
	}

	/**
	 * Stand in for WPML: the languages it publishes, the trid it keeps, and a
	 * record of everything it was told.
	 *
	 * @param array<int, string> $codes The site's own language codes.
	 */
	private function pretend_wpml( array $codes ): void {
		$languages = array();

		foreach ( $codes as $code ) {
			$languages[ $code ] = array( 'code' => $code );
		}

		// The filtered value and the arguments are ignored on purpose: WPML
		// answers from its own tables, not from what it was handed.
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		add_filter(
			'wpml_active_languages',
			static fn( $value, $args = array() ) => $languages
		);

		// A group id, handed back for any post. Constant because the assertion
		// worth making is that both pages get the *same* one.
		add_filter( 'wpml_element_trid', static fn( $value, $id = 0, $type = '' ) => 77 );
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		add_action(
			'wpml_set_element_language_details',
			function ( array $details ): void {
				$this->told[] = $details;
			}
		);
	}
}
