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
 * The same four embeds, for the operators who build pages in Elementor (WPP-5).
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
	 * The four, and what each one asks the operator for.
	 *
	 * Shared with the blocks by shape rather than by code, because Elementor's
	 * controls and Gutenberg's attributes are different vocabularies for the
	 * same two questions — and a shared abstraction over two editors' APIs would
	 * be a third thing to keep in step.
	 *
	 * @return array<string, array{title: string, callback: string, product: bool, category: bool, link: bool}>
	 */
	public static function definitions(): array {
		return array(
			'kaiki-booking'  => array(
				'title'    => __( 'Kaiki booking form', 'kaiki-booking' ),
				'callback' => 'booking',
				'product'  => true,
				'category' => false,
				'link'     => false,
			),
			'kaiki-list'     => array(
				'title'    => __( 'Kaiki trips', 'kaiki-booking' ),
				'callback' => 'trip_list',
				'product'  => false,
				'category' => true,
				'link'     => false,
			),
			'kaiki-calendar' => array(
				'title'    => __( 'Kaiki availability calendar', 'kaiki-booking' ),
				'callback' => 'calendar',
				'product'  => true,
				'category' => false,
				'link'     => true,
			),
			'kaiki-enquiry'  => array(
				'title'    => __( 'Kaiki enquiry form', 'kaiki-booking' ),
				'callback' => 'enquiry',
				'product'  => true,
				'category' => false,
				'link'     => false,
			),
		);
	}

	/**
	 * A widget's settings, as the shortcode attributes they stand for.
	 *
	 * Here rather than in the widget class because that class cannot be loaded
	 * without Elementor, and this is the part worth testing. Elementor's
	 * switcher saves `yes` or an empty string; a widget placed before the
	 * calendar had a switch has neither, and it meant what the calendar now
	 * does by default — so absent is on.
	 *
	 * @param array{title: string, callback: string, product: bool, category: bool, link: bool} $definition The widget's definition.
	 * @param array<string, mixed>                                                              $settings   Elementor's saved settings.
	 * @return array<string, string>
	 */
	public static function shortcode_attributes( array $definition, array $settings ): array {
		$atts = array(
			'product'  => isset( $settings['product'] ) ? (string) $settings['product'] : '',
			'category' => isset( $settings['category'] ) ? (string) $settings['category'] : '',
		);

		if ( $definition['link'] ) {
			$atts['link'] = 'yes' === ( $settings['link'] ?? 'yes' ) ? 'trip' : 'none';
		}

		return $atts;
	}
}
