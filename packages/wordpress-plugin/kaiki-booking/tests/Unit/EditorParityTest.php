<?php
/**
 * Three editors, one rendering path.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Tests\Unit;

use Kaiki\Booking\Assets\Bundle;
use Kaiki\Booking\Blocks\Blocks;
use Kaiki\Booking\Elementor\Widgets;
use Kaiki\Booking\Settings\Settings;
use Kaiki\Booking\Shortcodes\Shortcodes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * WPP-5: blocks and Elementor widgets are **server-rendered wrappers around the
 * shortcodes**, and this file is what keeps that sentence true.
 *
 * The failure it prevents is not dramatic. It is somebody adding an attribute to
 * the block, rendering it in the block's own callback because that is where they
 * were working, and the Elementor version quietly not having it — six months
 * later, in a support ticket from an operator who uses the other editor.
 *
 * Elementor's own widget class cannot be constructed here (it extends a class
 * that only exists when Elementor does), so what is asserted is the wiring: the
 * definitions name callbacks that exist on `Shortcodes`, and the same four
 * across both editors.
 */
final class EditorParityTest extends TestCase {

	private const UUID = 'f9ff7002-402d-44da-b102-77bffb8b68ea';

	protected function setUp(): void {
		kaiki_test_reset();
		Bundle::reset();

		update_option(
			Settings::OPTION,
			array(
				'publishable_key' => 'pk_live_abc',
				'api_base'        => 'https://book.kaiki.gr',
				'locale_mode'     => 'en',
			)
		);
	}

	public function test_every_block_renders_through_a_shortcode_that_exists(): void {
		$blocks = ( new ReflectionClass( Blocks::class ) )->getConstant( 'BLOCKS' );

		$this->assertIsArray( $blocks );
		$this->assertCount( 4, $blocks );

		foreach ( $blocks as $name => $block ) {
			$this->assertTrue(
				method_exists( Shortcodes::class, $block['callback'] ),
				"The {$name} block renders through Shortcodes::{$block['callback']}(), which does not exist."
			);
		}
	}

	public function test_every_elementor_widget_renders_through_a_shortcode_that_exists(): void {
		foreach ( Widgets::definitions() as $slug => $definition ) {
			$this->assertTrue(
				method_exists( Shortcodes::class, $definition['callback'] ),
				"The {$slug} widget renders through Shortcodes::{$definition['callback']}(), which does not exist."
			);
		}
	}

	public function test_the_two_editors_offer_the_same_four_things(): void {
		// An operator who moves from Gutenberg to Elementor should not discover
		// that one of their embeds does not exist there.
		$blocks = ( new ReflectionClass( Blocks::class ) )->getConstant( 'BLOCKS' );

		$block_callbacks  = array_column( (array) $blocks, 'callback' );
		$widget_callbacks = array_column( Widgets::definitions(), 'callback' );

		sort( $block_callbacks );
		sort( $widget_callbacks );

		$this->assertSame( $block_callbacks, $widget_callbacks );
	}

	public function test_the_two_editors_ask_for_the_same_things(): void {
		// Same question, two vocabularies. A block that took a category and an
		// Elementor widget that did not would be the drift this file exists to
		// prevent.
		$blocks  = (array) ( new ReflectionClass( Blocks::class ) )->getConstant( 'BLOCKS' );
		$widgets = Widgets::definitions();

		foreach ( $blocks as $block ) {
			$match = null;

			foreach ( $widgets as $widget ) {
				if ( $widget['callback'] === $block['callback'] ) {
					$match = $widget;
				}
			}

			$this->assertNotNull( $match, "No Elementor widget renders {$block['callback']}." );
			$this->assertSame( $block['product'], $match['product'], "The {$block['callback']} embeds disagree about `product`." );
			$this->assertSame( $block['category'], $match['category'], "The {$block['callback']} embeds disagree about `category`." );
			$this->assertSame( $block['link'], $match['link'], "The {$block['callback']} embeds disagree about `link`." );
		}
	}

	public function test_only_the_calendar_block_has_the_link_switch_and_it_starts_on(): void {
		Blocks::register_blocks();

		$registered = $GLOBALS['kaiki_test_blocks'];

		$this->assertSame(
			array(
				'type'    => 'boolean',
				'default' => true,
			),
			$registered['kaiki/calendar']['attributes']['link'] ?? null
		);

		foreach ( array( 'kaiki/booking', 'kaiki/list', 'kaiki/enquiry' ) as $name ) {
			$this->assertArrayNotHasKey( 'link', $registered[ $name ]['attributes'], "{$name} has a link switch it cannot use." );
		}
	}

	public function test_the_calendar_block_renders_what_the_calendar_shortcode_renders(): void {
		// Through the registered render callback itself, so this is the path a
		// real page takes: on, off, and a block saved before the switch existed.
		Blocks::register_blocks();

		$render = $GLOBALS['kaiki_test_blocks']['kaiki/calendar']['render_callback'];

		$cases = array(
			'on'           => array(
				array(
					'product' => self::UUID,
					'link'    => true,
				),
				'trip',
			),
			'off'          => array(
				array(
					'product' => self::UUID,
					'link'    => false,
				),
				'none',
			),
			'saved before' => array( array( 'product' => self::UUID ), 'trip' ),
		);

		foreach ( $cases as $label => list( $attributes, $link ) ) {
			Bundle::reset();

			$from_block = $render( $attributes );

			Bundle::reset();

			$from_shortcode = Shortcodes::calendar(
				array(
					'product' => self::UUID,
					'link'    => $link,
				)
			);

			$this->assertSame( $from_shortcode, $from_block, "The calendar block ({$label}) and shortcode disagree." );
		}
	}

	public function test_the_elementor_calendar_passes_its_switch_to_the_shortcode(): void {
		$definitions = Widgets::definitions();
		$calendar    = $definitions['kaiki-calendar'];

		// Elementor's switcher saves `yes` or an empty string.
		$this->assertSame( 'trip', Widgets::shortcode_attributes( $calendar, array( 'link' => 'yes' ) )['link'] );
		$this->assertSame( 'none', Widgets::shortcode_attributes( $calendar, array( 'link' => '' ) )['link'] );
		// A widget placed before the switch existed meant what the calendar now
		// does by default.
		$this->assertSame( 'trip', Widgets::shortcode_attributes( $calendar, array() )['link'] );

		// And nothing else is handed a `link` it would ignore.
		$this->assertArrayNotHasKey( 'link', Widgets::shortcode_attributes( $definitions['kaiki-booking'], array( 'link' => 'yes' ) ) );
	}

	public function test_the_elementor_and_block_calendars_render_the_same_markup(): void {
		$blocks = (array) ( new ReflectionClass( Blocks::class ) )->getConstant( 'BLOCKS' );
		$widget = Widgets::definitions()['kaiki-calendar'];

		foreach ( array( true, false ) as $on ) {
			Bundle::reset();

			$from_block = Shortcodes::calendar(
				Blocks::shortcode_attributes(
					$blocks['calendar'],
					array(
						'product' => self::UUID,
						'link'    => $on,
					)
				)
			);

			Bundle::reset();

			$from_elementor = Shortcodes::calendar(
				Widgets::shortcode_attributes(
					$widget,
					array(
						'product' => self::UUID,
						'link'    => $on ? 'yes' : '',
					)
				)
			);

			$this->assertSame( $from_block, $from_elementor );
			$this->assertSame( $on, str_contains( $from_block, 'data-link="trip"' ) );
		}
	}

	public function test_a_block_and_a_shortcode_with_the_same_settings_render_the_same_markup(): void {
		// The assertion the whole arrangement is for. A block's render callback
		// **is** the shortcode's callback, so this cannot fail while that is
		// true — and it fails loudly the day somebody "just adds a little
		// markup" to one of them.
		$from_shortcode = Shortcodes::booking( array( 'product' => self::UUID ) );

		Bundle::reset();

		$from_block = call_user_func(
			array( Shortcodes::class, 'booking' ),
			array(
				'product'  => self::UUID,
				'category' => '',
			)
		);

		$this->assertSame( $from_shortcode, $from_block );
	}
}
