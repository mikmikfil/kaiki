<?php
/**
 * The operator's own look, from the settings screen to the script tag.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Assets\Appearance;
use Kaiki\Booking\Assets\Bundle;
use Kaiki\Booking\Settings\Settings;
use Kaiki\Booking\Settings\SettingsPage;
use Kaiki\Booking\Shortcodes\Shortcodes;
use PHPUnit\Framework\TestCase;

/**
 * «Appearance», decided 2026-09-11: «As in Kaiki» or «My own».
 *
 * Two things here must not be wrong. «As in Kaiki» must emit **nothing**, so a
 * site that never opened the section renders what it rendered before the
 * section existed. And whatever reaches the script tag must be a colour, a
 * number or a font name and nothing else — the values end up in CSS inside the
 * widget, and a field that could carry `;}` could carry anything.
 */
final class AppearanceTest extends TestCase {

	private const UUID = 'f9ff7002-402d-44da-b102-77bffb8b68ea';

	private const APPEARANCE_ATTRIBUTES = array( 'data-primary', 'data-on-primary', 'data-text', 'data-background', 'data-font', 'data-radius' );

	protected function setUp(): void {
		kaiki_test_reset();
		Bundle::reset();
	}

	public function test_as_in_kaiki_emits_no_appearance_at_all(): void {
		// Every value filled in, and still nothing: the choice is what decides.
		self::configure(
			array(
				'appearance' => 'kaiki',
				'primary'    => '#0b3d91',
				'text'       => '#222222',
				'background' => '#fafafa',
				'font_mode'  => 'theme',
				'radius'     => 12,
			)
		);

		$html = Shortcodes::booking( array( 'product' => self::UUID ) );

		foreach ( self::APPEARANCE_ATTRIBUTES as $attribute ) {
			$this->assertStringNotContainsString( $attribute . '=', $html );
		}
	}

	public function test_a_site_that_never_saved_the_section_is_as_in_kaiki(): void {
		self::configure( array() );

		$this->assertSame( array(), Appearance::attributes() );
	}

	public function test_my_own_emits_exactly_the_attributes_the_widget_reads(): void {
		self::configure(
			array(
				'appearance' => 'custom',
				'primary'    => '#0b3d91',
				'text'       => '#222222',
				'background' => '#fafafa',
				'font_mode'  => 'theme',
				'radius'     => 12,
			)
		);

		$this->assertSame(
			array(
				'data-mount'      => 'booking',
				'data-product'    => self::UUID,
				'data-primary'    => '#0b3d91',
				'data-on-primary' => '#ffffff',
				'data-text'       => '#222222',
				'data-background' => '#fafafa',
				'data-font'       => 'inherit',
				'data-radius'     => '12',
			),
			self::attributes_of( Shortcodes::booking( array( 'product' => self::UUID ) ) )
		);
	}

	public function test_my_own_emits_only_what_was_set(): void {
		// A radius of 0 is set; an empty colour is not. Kaiki's own font is the
		// widget's default and needs no attribute.
		self::configure(
			array(
				'appearance' => 'custom',
				'primary'    => '',
				'text'       => '',
				'background' => '#fafafa',
				'font_mode'  => 'kaiki',
				'radius'     => 0,
			)
		);

		$attributes = self::attributes_of( Shortcodes::trip_list( array() ) );

		$this->assertSame( '#fafafa', $attributes['data-background'] ?? null );
		$this->assertSame( '0', $attributes['data-radius'] ?? null );

		foreach ( array( 'data-primary', 'data-on-primary', 'data-text', 'data-font' ) as $absent ) {
			$this->assertArrayNotHasKey( $absent, $attributes );
		}
	}

	public function test_a_font_the_theme_loads_is_passed_by_name(): void {
		self::configure(
			array(
				'appearance' => 'custom',
				'font_mode'  => 'custom',
				'font_name'  => 'Open Sans',
			)
		);

		$this->assertSame( 'Open Sans', self::attributes_of( Shortcodes::trip_list( array() ) )['data-font'] ?? null );
	}

	public function test_every_widget_on_the_page_gets_the_appearance(): void {
		// Each tag is its own widget (WGT-8); only the first carries the `src`.
		self::configure(
			array(
				'appearance' => 'custom',
				'primary'    => '#0b3d91',
			)
		);

		$first  = Shortcodes::booking( array( 'product' => self::UUID ) );
		$second = Shortcodes::calendar( array( 'product' => self::UUID ) );

		$this->assertStringContainsString( 'data-primary="#0b3d91"', $first );
		$this->assertStringContainsString( 'data-primary="#0b3d91"', $second );
		$this->assertStringNotContainsString( 'src=', $second );
	}

	public function test_the_button_text_is_whichever_colour_reads_better(): void {
		// A pale yellow button with white text is the mistake a colour picker
		// makes easy and a guest on a phone in the sun cannot read.
		$this->assertSame( Appearance::DARK_TEXT, Appearance::on_primary( '#fff3a0' ) );
		$this->assertSame( Appearance::DARK_TEXT, Appearance::on_primary( '#ffffff' ) );
		$this->assertSame( Appearance::DARK_TEXT, Appearance::on_primary( '#ff8800' ) );

		$this->assertSame( Appearance::LIGHT_TEXT, Appearance::on_primary( '#0b3d91' ) );
		$this->assertSame( Appearance::LIGHT_TEXT, Appearance::on_primary( '#0b4f4a' ) );
		$this->assertSame( Appearance::LIGHT_TEXT, Appearance::on_primary( '#000000' ) );
	}

	public function test_the_sanitiser_keeps_only_six_digit_colours_in_lower_case(): void {
		$saved = SettingsPage::sanitize(
			array(
				'primary'    => ' #0B4F4A ',
				'text'       => '#fff',
				'background' => 'red',
			)
		);

		$this->assertSame( '#0b4f4a', $saved['primary'] );
		$this->assertSame( '', $saved['text'] );
		$this->assertSame( '', $saved['background'] );

		foreach ( array( '#12345g', '#0b4f4a;}', 'rgb(0,0,0)', '0b4f4a', '#0b4f4a4a' ) as $bad ) {
			$this->assertSame( '', SettingsPage::sanitize( array( 'primary' => $bad ) )['primary'], $bad );
		}
	}

	public function test_the_sanitiser_clamps_the_corner_roundness(): void {
		$cases = array(
			'12'  => 12,
			'0'   => 0,
			'-5'  => 0,
			'45'  => 30,
			'7.6' => 8,
			''    => '',
			'abc' => '',
		);

		foreach ( $cases as $input => $expected ) {
			$this->assertSame( $expected, SettingsPage::sanitize( array( 'radius' => (string) $input ) )['radius'], "radius={$input}" );
		}
	}

	public function test_the_sanitiser_filters_the_font_name(): void {
		$saved = SettingsPage::sanitize(
			array(
				'font_mode' => 'custom',
				'font_name' => ' Open Sans"; } body { color: red ',
			)
		);

		$this->assertSame( 'custom', $saved['font_mode'] );
		$this->assertSame( 'Open Sans body color red', $saved['font_name'] );

		$long = SettingsPage::sanitize( array( 'font_name' => str_repeat( 'Abc-', 30 ) ) )['font_name'];

		$this->assertSame( 60, strlen( $long ) );
	}

	public function test_the_sanitiser_falls_back_on_choices_it_does_not_know(): void {
		$saved = SettingsPage::sanitize(
			array(
				'appearance' => 'fancy',
				'font_mode'  => 'comic',
			)
		);

		$this->assertSame( 'kaiki', $saved['appearance'] );
		$this->assertSame( 'kaiki', $saved['font_mode'] );

		// «A font my theme loads» with nothing usable in the name is not a
		// choice anybody made.
		$nameless = SettingsPage::sanitize(
			array(
				'font_mode' => 'custom',
				'font_name' => '";{}',
			)
		);

		$this->assertSame( 'kaiki', $nameless['font_mode'] );
		$this->assertSame( '', $nameless['font_name'] );
	}

	public function test_the_sanitiser_keeps_my_own_values_while_as_in_kaiki_is_chosen(): void {
		// So switching back to «My own» brings them back.
		$saved = SettingsPage::sanitize(
			array(
				'appearance' => 'kaiki',
				'primary'    => '#0b3d91',
				'radius'     => '4',
			)
		);

		$this->assertSame( 'kaiki', $saved['appearance'] );
		$this->assertSame( '#0b3d91', $saved['primary'] );
		$this->assertSame( 4, $saved['radius'] );
	}

	public function test_the_reader_checks_the_stored_values_again(): void {
		// A value that reached the option some other way than the settings
		// screen is still a colour or nothing by the time it is in a page.
		self::configure(
			array(
				'appearance' => 'custom',
				'primary'    => '#000000;background:url(x)',
				'font_mode'  => 'custom',
				'font_name'  => 'Evil"><script>',
				'radius'     => '99',
			)
		);

		$settings = Settings::all();

		$this->assertSame( '', $settings['primary'] );
		$this->assertSame( 'Evilscript', $settings['font_name'] );
		$this->assertSame( 30, $settings['radius'] );
	}

	public function test_the_reader_has_defaults_for_an_empty_option(): void {
		self::configure( array() );

		$settings = Settings::all();

		$this->assertSame( 'kaiki', $settings['appearance'] );
		$this->assertSame( '', $settings['primary'] );
		$this->assertSame( '', $settings['text'] );
		$this->assertSame( '', $settings['background'] );
		$this->assertSame( 'kaiki', $settings['font_mode'] );
		$this->assertSame( '', $settings['font_name'] );
		$this->assertNull( $settings['radius'] );
	}

	/**
	 * Save the key and the given appearance.
	 *
	 * @param array<string, mixed> $appearance The appearance fields.
	 */
	private static function configure( array $appearance ): void {
		update_option(
			Settings::OPTION,
			array(
				'publishable_key' => 'pk_live_abc',
				'api_base'        => 'https://book.kaiki.gr',
				'locale_mode'     => 'en',
			) + $appearance
		);
	}

	/**
	 * The `data-` attributes of the one script tag in some markup, other than
	 * the two every tag carries.
	 *
	 * @param string $html The shortcode's output.
	 * @return array<string, string>
	 */
	private static function attributes_of( string $html ): array {
		preg_match_all( '/ (data-[a-z-]+)="([^"]*)"/', $html, $found, PREG_SET_ORDER );

		$attributes = array();

		foreach ( $found as $match ) {
			$attributes[ $match[1] ] = html_entity_decode( $match[2], ENT_QUOTES );
		}

		unset( $attributes['data-key'], $attributes['data-locale'] );

		return $attributes;
	}
}
