<?php
/**
 * The four shortcodes, and what they do when they are written wrongly.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Assets\Bundle;
use Kaiki\Booking\Settings\Settings;
use Kaiki\Booking\Shortcodes\Shortcodes;
use PHPUnit\Framework\TestCase;

/**
 * WPP-4's four, and the three ways an operator gets them wrong.
 *
 * The interesting assertions are not that a correct shortcode renders. They are
 * that a **wrong** one is survivable: no fatal, nothing alarming for a visitor,
 * and a useful sentence for the person who can fix it — because the person who
 * pasted it is the operator or their nephew, at night, once, with nobody to ask.
 */
final class ShortcodeTest extends TestCase {

	private const UUID = 'f9ff7002-402d-44da-b102-77bffb8b68ea';

	protected function setUp(): void {
		kaiki_test_reset();
		Bundle::reset();

		unset( $GLOBALS['kaiki_test_can'] );

		update_option(
			Settings::OPTION,
			array(
				'publishable_key' => 'pk_live_abc',
				'api_base'        => 'https://book.kaiki.gr',
				'locale_mode'     => 'en',
			)
		);
	}

	public function test_the_booking_shortcode_renders_the_widget_embed(): void {
		$html = Shortcodes::booking( array( 'product' => self::UUID ) );

		$this->assertStringContainsString( 'src="https://book.kaiki.gr/widget/kaiki-widget.js"', $html );
		$this->assertStringContainsString( 'data-mount="booking"', $html );
		$this->assertStringContainsString( 'data-product="' . self::UUID . '"', $html );
		$this->assertStringContainsString( 'data-key="pk_live_abc"', $html );
	}

	public function test_it_embeds_the_alias_and_never_a_version(): void {
		// ADR-0011: a release reaches an operator without anybody editing
		// anything, which only works while the embed names the alias.
		$this->assertStringNotContainsString( '/widget/v', Bundle::url() );
		$this->assertStringEndsWith( '/widget/kaiki-widget.js', Bundle::url() );
	}

	public function test_a_second_shortcode_does_not_load_the_bundle_twice(): void {
		$first  = Shortcodes::booking( array( 'product' => self::UUID ) );
		$second = Shortcodes::trip_list( array() );

		$this->assertStringContainsString( 'src=', $first );
		// The second tag still exists — the widget mounts one instance per
		// `data-key` tag (WGT-8) — but it does not fetch the bundle again.
		$this->assertStringNotContainsString( 'src=', $second );
		$this->assertStringContainsString( 'data-mount="list"', $second );
	}

	public function test_a_page_with_no_shortcode_loads_nothing(): void {
		// The difference between a plugin an agency recommends and one they rip
		// out. Asserted against the source rather than against a hook registry,
		// because the claim is that the plugin **never** enqueues the bundle
		// globally — and a stub that answered "no hook fired" would be agreeing
		// with itself.
		//
		// It is also why the tag is written into the shortcode's output rather
		// than enqueued: the widget mounts where its script tag is (WGT-7), and
		// an enqueued one would put the booking form in the footer.
		$files = glob( dirname( __DIR__, 2 ) . '/src/*/*.php' );

		$this->assertNotEmpty( $files );

		foreach ( (array) $files as $file ) {
			// Comments stripped first. `Bundle`'s own docblock explains why it
			// does *not* enqueue, and an assertion that read docblocks would
			// fail on the sentence saying it does the right thing.
			$this->assertStringNotContainsString(
				'wp_enqueue_script',
				self::code_of( (string) $file ),
				basename( (string) $file ) . ' enqueues the bundle; it must be written by the shortcode that needs it.'
			);
		}
	}

	public function test_a_missing_product_tells_an_editor_what_to_write(): void {
		$GLOBALS['kaiki_test_can'] = true;

		$html = Shortcodes::booking( array() );

		$this->assertStringContainsString( 'kaiki_booking product', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_a_missing_product_tells_a_visitor_nothing(): void {
		// A visitor must not learn that the site is misconfigured, and must not
		// be shown instructions they cannot act on.
		$html = Shortcodes::booking( array() );

		$this->assertStringNotContainsString( 'kaiki_booking product', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'Bookings are briefly unavailable', $html );
	}

	public function test_a_product_that_is_not_a_uuid_is_refused(): void {
		// The attribute is a string a page editor typed, and page editors paste
		// strange things. This is the difference between an attribute and an
		// injection.
		$GLOBALS['kaiki_test_can'] = true;

		$html = Shortcodes::booking( array( 'product' => '"><script>alert(1)</script>' ) );

		$this->assertStringNotContainsString( '<script>alert', $html );
	}

	public function test_an_unknown_category_is_an_empty_list_rather_than_an_error(): void {
		// WGT-6. An operator writes `category` into a page once, and the page
		// outlives the trips it was written for.
		$html = Shortcodes::trip_list( array( 'category' => 'a-category-nobody-has' ) );

		$this->assertStringContainsString( 'data-mount="list"', $html );
		$this->assertStringContainsString( 'data-category="a-category-nobody-has"', $html );
	}

	public function test_the_enquiry_shortcode_works_without_a_product(): void {
		// An enquiry about the fleet in general is a real thing to put on a
		// contact page.
		$html = Shortcodes::enquiry( array() );

		$this->assertStringContainsString( 'data-mount="enquiry"', $html );
		$this->assertStringNotContainsString( 'data-product', $html );
	}

	public function test_nothing_renders_a_script_before_a_key_is_saved(): void {
		delete_option( Settings::OPTION );

		$html = Shortcodes::trip_list( array() );

		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_an_unconfigured_plugin_tells_an_editor_where_to_go(): void {
		delete_option( Settings::OPTION );

		$GLOBALS['kaiki_test_can'] = true;

		$html = Shortcodes::trip_list( array() );

		$this->assertStringContainsString( 'Settings', $html );
	}

	public function test_every_shortcode_carries_the_scoped_class_and_nothing_else(): void {
		// WPP-2: no global CSS beyond a `kaiki-` namespace, and nothing that
		// could reach a theme's own elements.
		foreach ( array( Shortcodes::booking( array( 'product' => self::UUID ) ), Shortcodes::trip_list( array() ) ) as $html ) {
			$this->assertMatchesRegularExpression( '/class="kaiki-[a-z -]+"/', $html );
			$this->assertSame( 1, preg_match_all( '/class="/', $html ) );
		}
	}

	/**
	 * A file with its comments removed.
	 *
	 * @param string $path The file to read.
	 */
	private static function code_of( string $path ): string {
		$kept = '';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a file on disk in a test, not fetching a URL.
		foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}

				$kept .= $token[1];

				continue;
			}

			$kept .= $token;
		}

		return $kept;
	}
}
