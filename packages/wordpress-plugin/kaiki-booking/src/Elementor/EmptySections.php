<?php
/**
 * Sections with nothing from Kaiki in them, left out of the page.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Elementor;

use Kaiki\Booking\Trip\Trip;

defined( 'ABSPATH' ) || exit;

/**
 * One template, and a trip that has no itinerary: the «Itinerary» section
 * should not be there — heading and all — for that trip, and should be there
 * for the next one.
 *
 * ## On the server, not in CSS
 *
 * Elementor draws an element's children first and only then asks
 * `elementor/frontend/{type}/should_render` whether to print it. So every
 * container keeps a small tally while its children render — how many Kaiki
 * trip widgets it holds, and how many of them had something to show — and a
 * container whose Kaiki widgets all came out empty answers «no». Its markup
 * never reaches the page. Nested containers each keep their own tally, so a
 * column whose sections all emptied goes too, and one with a single filled
 * section stays.
 *
 * ## Only what Kaiki decides
 *
 * A container with no Kaiki widget is never touched; a container with any
 * filled one always stays. Each container has a switch (Advanced → «Kaiki:
 * hide when empty», on by default) for the section that must stay regardless.
 * In the Elementor editor nothing is hidden: the template is being designed,
 * and the empty pieces show a dashed box saying what is missing.
 */
final class EmptySections {

	/** The container control. */
	public const CONTROL = 'kaiki_hide_empty';

	/**
	 * Open tallies, innermost last.
	 *
	 * @var list<array{kaiki: int, filled: int}>
	 */
	private static array $frames = array();

	/** Hook in. */
	public static function register(): void {
		add_action( 'elementor/frontend/before_render', array( self::class, 'open' ) );

		foreach ( array( 'container', 'section', 'column' ) as $type ) {
			add_filter( "elementor/frontend/{$type}/should_render", array( self::class, 'close' ), 20, 2 );
		}

		add_action( 'elementor/element/after_section_end', array( self::class, 'add_control' ), 10, 2 );
	}

	/**
	 * A layout element starts rendering: open its tally.
	 *
	 * @param object $element The Elementor element.
	 */
	public static function open( $element ): void {
		if ( self::is_layout( $element ) ) {
			self::$frames[] = array(
				'kaiki'  => 0,
				'filled' => 0,
			);
		}
	}

	/**
	 * A Kaiki trip widget has rendered: count it in every open container.
	 *
	 * @param bool $filled Whether it had something to show.
	 */
	public static function report( bool $filled ): void {
		foreach ( self::$frames as $i => $frame ) {
			self::$frames[ $i ]['kaiki']  = $frame['kaiki'] + 1;
			self::$frames[ $i ]['filled'] = $frame['filled'] + ( $filled ? 1 : 0 );
		}
	}

	/**
	 * A layout element has rendered its children: print it or not.
	 *
	 * @param bool   $should_render Elementor's answer so far.
	 * @param object $element       The Elementor element.
	 */
	public static function close( $should_render, $element ): bool {
		if ( ! self::is_layout( $element ) || array() === self::$frames ) {
			return (bool) $should_render;
		}

		$frame    = array_pop( self::$frames );
		$settings = method_exists( $element, 'get_settings' ) ? $element->get_settings() : array();
		$enabled  = 'no' !== ( is_array( $settings ) ? ( $settings[ self::CONTROL ] ?? 'yes' ) : 'yes' );

		return (bool) $should_render && ! self::hide( $frame['kaiki'], $frame['filled'], $enabled, Trip::in_editor() );
	}

	/**
	 * Should a container go? Pure, so it is testable.
	 *
	 * @param int  $kaiki     Kaiki trip widgets inside it, at any depth.
	 * @param int  $filled    How many of them had something to show.
	 * @param bool $enabled   The container's own switch.
	 * @param bool $in_editor Whether a builder's editor is rendering.
	 */
	public static function hide( int $kaiki, int $filled, bool $enabled, bool $in_editor ): bool {
		return $enabled && ! $in_editor && $kaiki > 0 && 0 === $filled;
	}

	/**
	 * The switch, in each container's Advanced tab.
	 *
	 * @param object $element    The Elementor element.
	 * @param string $section_id The section that just ended.
	 */
	public static function add_control( $element, $section_id ): void {
		if ( '_section_responsive' !== $section_id || ! self::is_layout( $element ) || ! method_exists( $element, 'start_controls_section' ) ) {
			return;
		}

		$element->start_controls_section(
			'kaiki_empty_section',
			array(
				'label' => __( 'Kaiki', 'kaiki-booking' ),
				'tab'   => 'advanced',
			)
		);

		$element->add_control(
			self::CONTROL,
			array(
				'label'        => __( 'Hide when Kaiki has nothing for it', 'kaiki-booking' ),
				'type'         => 'switcher',
				'default'      => 'yes',
				'return_value' => 'yes',
				'description'  => __( 'On a trip page, this section is left out when every Kaiki widget in it is empty — for example a trip with no itinerary. The editor always shows it.', 'kaiki-booking' ),
			)
		);

		$element->end_controls_section();
	}

	/** Forget every open tally; for tests. */
	public static function reset(): void {
		self::$frames = array();
	}

	/**
	 * Is this a container, a section or a column?
	 *
	 * @param object $element The Elementor element.
	 */
	private static function is_layout( $element ): bool {
		return is_object( $element ) && method_exists( $element, 'get_type' ) && in_array( $element->get_type(), array( 'container', 'section', 'column' ), true );
	}
}
