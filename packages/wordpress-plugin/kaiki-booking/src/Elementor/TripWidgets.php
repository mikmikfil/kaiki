<?php
/**
 * The trip-page widgets for Elementor.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Elementor;

use Kaiki\Booking\Trip\TripShortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Every trip shortcode as an Elementor widget, in a panel category of its own.
 *
 * For Elementor **Free** as much as Pro: drop the widgets into a template,
 * leave «Trip id» empty, and each trip page fills them in — the same rule the
 * shortcodes follow. Registration is a no-op without Elementor, like
 * {@see Widgets}.
 */
final class TripWidgets {

	/** The panel category. */
	public const CATEGORY = 'kaiki-trip';

	/** Hook in. */
	public static function register(): void {
		add_action( 'elementor/elements/categories_registered', array( self::class, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( self::class, 'register_widgets' ) );
	}

	/**
	 * The «Kaiki · Trip page» category.
	 *
	 * @param object $manager Elementor's elements manager.
	 */
	public static function register_category( $manager ): void {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'add_category' ) ) {
			return;
		}

		$manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( 'Kaiki · Trip page', 'kaiki-booking' ),
				'icon'  => 'eicon-calendar',
			)
		);
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

		require_once __DIR__ . '/trip-widget-classes.php';

		foreach ( self::classes() as $class ) {
			$manager->register( new $class() );
		}
	}

	/**
	 * Shortcode tag => widget class, one for every trip shortcode.
	 *
	 * @return array<string, string>
	 */
	public static function classes(): array {
		return array(
			'kaiki_trip_title'         => __NAMESPACE__ . '\Trip_Title_Widget',
			'kaiki_trip_facts'         => __NAMESPACE__ . '\Trip_Facts_Widget',
			'kaiki_trip_gallery'       => __NAMESPACE__ . '\Trip_Gallery_Widget',
			'kaiki_trip_description'   => __NAMESPACE__ . '\Trip_Description_Widget',
			'kaiki_trip_price'         => __NAMESPACE__ . '\Trip_Price_Widget',
			'kaiki_trip_list'          => __NAMESPACE__ . '\Trip_List_Widget',
			'kaiki_trip_itinerary'     => __NAMESPACE__ . '\Trip_Itinerary_Widget',
			'kaiki_trip_meeting_point' => __NAMESPACE__ . '\Trip_Meeting_Point_Widget',
			'kaiki_trip_cancellation'  => __NAMESPACE__ . '\Trip_Cancellation_Widget',
			'kaiki_trips'              => __NAMESPACE__ . '\Trips_Widget',
			'kaiki_trip_field'         => __NAMESPACE__ . '\Trip_Field_Widget',
			'kaiki_fleet'              => __NAMESPACE__ . '\Fleet_Widget',
			'kaiki_search'             => __NAMESPACE__ . '\Search_Widget',
			'kaiki_search_results'     => __NAMESPACE__ . '\Search_Results_Widget',
		);
	}

	/**
	 * Every trip shortcode has a widget, and no widget renders a shortcode
	 * that does not exist. Asserted by a test; here so the test has one call.
	 */
	public static function covers_every_shortcode(): bool {
		return array_keys( self::classes() ) === array_merge( array_keys( TripShortcodes::TAGS ), array( 'kaiki_search', 'kaiki_search_results' ) );
	}
}
