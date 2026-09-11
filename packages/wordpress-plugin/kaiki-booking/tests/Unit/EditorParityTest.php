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
 * definitions name callbacks that exist on `Shortcodes`, the same three across
 * both editors, and the settings each editor saves become the same shortcode
 * attributes and therefore the same markup.
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
		$blocks = self::blocks();

		$this->assertCount( 3, $blocks );

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

	public function test_the_two_editors_offer_the_same_three_things(): void {
		// An operator who moves from Gutenberg to Elementor should not discover
		// that one of their embeds does not exist there. And the calendar is in
		// neither: it was taken out of the plugin on 2026-09-11.
		$block_callbacks  = array_column( self::blocks(), 'callback' );
		$widget_callbacks = array_column( Widgets::definitions(), 'callback' );

		sort( $block_callbacks );
		sort( $widget_callbacks );

		$this->assertSame( array( 'booking', 'enquiry', 'trip_list' ), $block_callbacks );
		$this->assertSame( $block_callbacks, $widget_callbacks );
	}

	public function test_the_two_editors_ask_for_the_same_things(): void {
		// Same question, two vocabularies. A block that took a category and an
		// Elementor widget that did not would be the drift this file exists to
		// prevent.
		foreach ( self::blocks() as $block ) {
			$match = self::widget_for( $block['callback'] );

			$this->assertNotNull( $match, "No Elementor widget renders {$block['callback']}." );
			$this->assertSame( $block['product'], $match['product'], "The {$block['callback']} embeds disagree about `product`." );
			$this->assertSame( $block['category'], $match['category'], "The {$block['callback']} embeds disagree about `category`." );
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

	public function test_every_registered_block_renders_what_its_shortcode_renders(): void {
		// Through the render callback WordPress is actually given, not a copy of
		// what it is meant to do.
		Blocks::register_blocks();

		$this->assertCount( 3, $GLOBALS['kaiki_test_blocks'] );

		foreach ( self::blocks() as $name => $block ) {
			$saved = array(
				'product'  => self::UUID,
				'category' => 'shared',
			);

			Bundle::reset();

			$from_block = $GLOBALS['kaiki_test_blocks'][ 'kaiki/' . $name ]['render_callback']( $saved );

			Bundle::reset();

			$from_shortcode = call_user_func( array( Shortcodes::class, $block['callback'] ), $saved );

			$this->assertSame( $from_shortcode, $from_block, "The {$name} block and its shortcode disagree." );
		}
	}

	public function test_a_block_and_an_elementor_widget_with_the_same_settings_render_the_same_markup(): void {
		foreach ( self::blocks() as $name => $block ) {
			$saved = array(
				'product'  => self::UUID,
				'category' => 'shared',
			);

			$widget = self::widget_for( $block['callback'] );

			$this->assertNotNull( $widget );

			Bundle::reset();

			$from_block = call_user_func( array( Shortcodes::class, $block['callback'] ), Blocks::shortcode_attributes( $block, $saved ) );

			Bundle::reset();

			$from_elementor = call_user_func( array( Shortcodes::class, $widget['callback'] ), Widgets::shortcode_attributes( $widget, $saved ) );

			$this->assertSame( $from_block, $from_elementor, "The {$name} block and its Elementor widget disagree." );
		}
	}

	public function test_neither_editor_keeps_a_setting_left_over_from_the_calendar(): void {
		Blocks::register_blocks();

		foreach ( $GLOBALS['kaiki_test_blocks'] as $name => $registered ) {
			$this->assertSame( array( 'product', 'category' ), array_keys( $registered['attributes'] ), "{$name} registers an attribute nothing reads." );
		}

		foreach ( Widgets::definitions() as $slug => $definition ) {
			$this->assertArrayNotHasKey( 'link', $definition, "{$slug} still has the calendar's link switch." );
		}
	}

	/**
	 * The block definitions.
	 *
	 * @return array<string, array{callback: string, product: bool, category: bool}>
	 */
	private static function blocks(): array {
		return (array) ( new ReflectionClass( Blocks::class ) )->getConstant( 'BLOCKS' );
	}

	/**
	 * The Elementor definition that renders a given shortcode callback.
	 *
	 * @param string $callback The shortcode callback.
	 * @return array{title: string, callback: string, product: bool, category: bool}|null
	 */
	private static function widget_for( string $callback ): ?array {
		foreach ( Widgets::definitions() as $definition ) {
			if ( $definition['callback'] === $callback ) {
				return $definition;
			}
		}

		return null;
	}
}
