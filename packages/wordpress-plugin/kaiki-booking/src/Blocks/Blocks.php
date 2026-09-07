<?php
/**
 * Gutenberg blocks, which are the shortcodes with a mouse.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Blocks;

use Kaiki\Booking\Shortcodes\Shortcodes;

use const Kaiki\Booking\FILE;
use const Kaiki\Booking\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * Four blocks, each a server-rendered wrapper around its shortcode (WPP-5).
 *
 * ## "Server-rendered wrappers" is the load-bearing phrase
 *
 * The tempting alternative is a JavaScript block that mounts the widget in the
 * editor. It demonstrates better and it gives you **two rendering paths to keep
 * in step for ever** — and the editor one puts a working booking form inside a
 * page editor, which is how an operator accidentally makes a real booking while
 * laying out a page.
 *
 * So every block's `render_callback` calls the shortcode's own callback. There
 * is one rendering path in this plugin and this is not it.
 *
 * ## No build step
 *
 * The editor script is plain JavaScript against the `wp.*` globals WordPress
 * already ships. `@wordpress/scripts` would give JSX and a bundler, and would
 * add a Node build to a PHP plugin that has none — for four blocks whose entire
 * interface is a select and a text field. A build nobody can run is a block
 * nobody can fix.
 */
final class Blocks {

	/**
	 * The four, and which shortcode each one is.
	 *
	 * Public so `EditorParityTest` can hold it against Elementor's list: the
	 * failure worth preventing is somebody adding an attribute to the block and
	 * the Elementor version quietly not having it, discovered six months later
	 * by an operator who uses the other editor.
	 *
	 * @var array<string, array{callback: string, product: bool, category: bool}>
	 */
	public const BLOCKS = array(
		'booking'  => array(
			'callback' => 'booking',
			'product'  => true,
			'category' => false,
		),
		'list'     => array(
			'callback' => 'trip_list',
			'product'  => false,
			'category' => true,
		),
		'calendar' => array(
			'callback' => 'calendar',
			'product'  => true,
			'category' => false,
		),
		'enquiry'  => array(
			'callback' => 'enquiry',
			'product'  => true,
			'category' => false,
		),
	);

	/**
	 * Register the blocks, and the editor script that draws them.
	 */
	public static function register(): void {
		// Gutenberg may not be there at all: a classic-editor site, or one where
		// blocks are switched off. Nothing below should raise anything then.
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'register_editor_script' ) );
	}

	/**
	 * Each block, with its own render callback.
	 */
	public static function register_blocks(): void {
		foreach ( self::BLOCKS as $name => $block ) {
			register_block_type(
				'kaiki/' . $name,
				array(
					'api_version'     => 2,
					'editor_script'   => 'kaiki-blocks',
					'attributes'      => array(
						'product'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'category' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
					// The shortcode's own callback. Not a copy of it, not a
					// second implementation, and not a template that happens to
					// produce the same markup today.
					'render_callback' => static function ( array $attributes ) use ( $block ): string {
						return call_user_func(
							array( Shortcodes::class, $block['callback'] ),
							array(
								'product'  => isset( $attributes['product'] ) ? (string) $attributes['product'] : '',
								'category' => isset( $attributes['category'] ) ? (string) $attributes['category'] : '',
							)
						);
					},
				)
			);
		}
	}

	/**
	 * The editor script, and the trips it needs to offer.
	 */
	public static function register_editor_script(): void {
		wp_register_script(
			'kaiki-blocks',
			plugins_url( 'assets/blocks.js', FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-api-fetch' ),
			VERSION,
			true
		);

		// The JavaScript half of the translations. A plugin that shipped only a
		// `.mo` would have a Greek settings page and an English block panel on the
		// same site: `wp.i18n.__` reads a JSON file, never the `.mo`, and
		// `tools/build-translations.php` writes both from the one `.po`.
		wp_set_script_translations( 'kaiki-blocks', 'kaiki-booking', dirname( FILE ) . '/languages' );

		wp_enqueue_script( 'kaiki-blocks' );
	}
}
