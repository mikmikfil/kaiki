<?php
/**
 * WPP-13's resolution order.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Locale\Locale;
use Kaiki\Booking\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Which language the widget is asked for, and why the order is the order.
 *
 * WPML and Polylang know which language **this page** is in. The site locale
 * only knows what the admin is in — and on a bilingual site those two disagree
 * constantly, which would mean serving a Greek visitor an English booking form
 * on a Greek page.
 *
 * The plugin's own constants cannot be undefined once set, so WPML's branch is
 * covered by the e2e run on a real site rather than here; what this file holds
 * is the mapping and the pinning, which is where the mistakes are.
 */
final class LocaleTest extends TestCase {

	protected function setUp(): void {
		kaiki_test_reset();

		unset( $GLOBALS['kaiki_test_polylang'] );
	}

	public function test_a_pinned_language_wins_over_everything(): void {
		// For the operator whose site is English and whose guests are Greek.
		// They exist, and the setting is for them.
		$GLOBALS['kaiki_test_locale'] = 'en_US';

		update_option( Settings::OPTION, array( 'locale_mode' => 'el' ) );

		$this->assertSame( 'el', Locale::current() );
	}

	public function test_a_greek_site_gets_greek_without_configuring_anything(): void {
		$GLOBALS['kaiki_test_locale'] = 'el_GR';

		update_option( Settings::OPTION, array( 'locale_mode' => 'auto' ) );

		$this->assertSame( 'el', Locale::current() );
	}

	public function test_an_english_site_gets_english(): void {
		$GLOBALS['kaiki_test_locale'] = 'en_GB';

		update_option( Settings::OPTION, array( 'locale_mode' => 'auto' ) );

		$this->assertSame( 'en', Locale::current() );
	}

	public function test_a_language_kaiki_does_not_speak_falls_back_to_english(): void {
		// One-sided on purpose: a page in German served in English is readable
		// by more of its visitors than one served in Greek.
		$GLOBALS['kaiki_test_locale'] = 'de_DE';

		update_option( Settings::OPTION, array( 'locale_mode' => 'auto' ) );

		$this->assertSame( 'en', Locale::current() );
	}

	public function test_polylang_is_asked_before_the_site_locale(): void {
		// Polylang answers per page; the site locale answers per site. On a
		// bilingual site the second is wrong half the time.
		$GLOBALS['kaiki_test_locale']   = 'en_US';
		$GLOBALS['kaiki_test_polylang'] = 'el';

		update_option( Settings::OPTION, array( 'locale_mode' => 'auto' ) );

		$this->assertSame( 'el', Locale::current() );

		unset( $GLOBALS['kaiki_test_polylang'] );
	}
}
