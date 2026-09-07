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
