<?php
/**
 * Elementor widgets, which are the shortcodes with a different mouse.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * The same three embeds, for the operators who build pages in Elementor (WPP-5).
 *
 * ## Registered only if Elementor is there
 *
 * The class below extends `\Elementor\Widget_Base`, which does not exist on a
 * site without Elementor — so it is declared **inside a function** that only
 * runs on the `elementor/widgets/register` hook. A file that declared it at the
 * top level would be a fatal error on every site that does not use Elementor,
 * which is most of them.
 *
 * ## The same rendering path, again
 *
 * Every widget's `render()` calls the shortcode's own callback. Three editors,
 * one implementation of the markup: a shortcode, a block and an Elementor widget
 * with the same settings produce **identical** HTML, and a test asserts it,
 * because "the Elementor one does something slightly different" is the failure
 * this arrangement exists to prevent.
 */
final class Widgets {

	/**
	 * Hook in, if there is anything to hook into.
	 */
	public static function register(): void {
		add_action( 'elementor/widgets/register', array( self::class, 'register_widgets' ) );
	}

	/**
	 * Declare and register the widgets.
	 *
	 * @param object $manager Elementor's widget manager.
	 */
	public static function register_widgets( $manager ): void {
		if ( ! class_exists( '\Elementor\Widget_Base' ) || ! is_object( $manager ) || ! method_exists( $manager, 'register' ) ) {
			return;
		}

		require_once __DIR__ . '/widget-class.php';

		foreach ( self::definitions() as $slug => $definition ) {
			$manager->register( new Widget( $slug, $definition ) );
		}
	}

	/**
	 * The three, and what each one asks the operator for.
	 *
	 * Shared with the blocks by shape rather than by code, because Elementor's
	 * controls and Gutenberg's attributes are different vocabularies for the
	 * same two questions — and a shared abstraction over two editors' APIs would
	 * be a third thing to keep in step.
	 *
	 * @return array<string, array{title: string, callback: string, product: bool, category: bool}>
	 */
	public static function definitions(): array {
		return array(
			'kaiki-booking' => array(
				'title'    => __( 'Kaiki booking form', 'kaiki-booking' ),
				'callback' => 'booking',
				'product'  => true,
				'category' => false,
			),
			'kaiki-list'    => array(
				'title'    => __( 'Kaiki trips', 'kaiki-booking' ),
				'callback' => 'trip_list',
				'product'  => false,
				'category' => true,
			),
			'kaiki-enquiry' => array(
				'title'    => __( 'Kaiki enquiry form', 'kaiki-booking' ),
				'callback' => 'enquiry',
				'product'  => true,
				'category' => false,
			),
		);
	}

	/**
	 * A widget's settings, as the shortcode attributes they stand for.
	 *
	 * Here rather than in the widget class because that class cannot be loaded
	 * without Elementor, and this is the part worth testing: `EditorParityTest`
	 * holds it against the block's own mapping.
	 *
	 * @param array{title: string, callback: string, product: bool, category: bool} $definition The widget's definition.
	 * @param array<string, mixed>                                                  $settings   Elementor's saved settings.
	 * @return array<string, string>
	 */
	public static function shortcode_attributes( array $definition, array $settings ): array {
		unset( $definition );

		return array(
			'product'  => isset( $settings['product'] ) ? (string) $settings['product'] : '',
			'category' => isset( $settings['category'] ) ? (string) $settings['category'] : '',
		);
	}
}
