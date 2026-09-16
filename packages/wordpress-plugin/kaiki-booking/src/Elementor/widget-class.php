<?php
/**
 * The Elementor widget class, declared only when Elementor exists.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use Kaiki\Booking\Shortcodes\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Not a file to autoload.
 *
 * `Widget_Base` does not exist on a site without Elementor, and a class
 * extending a missing class is a fatal error the moment the autoloader is asked
 * for it. So this file is `require`d from inside the `elementor/widgets/register`
 * hook — which only fires when Elementor is running — and the class is
 * deliberately **not** reachable through PSR-4 by being defined here rather than
 * in a file named after it.
 */
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

// phpcs:disable Generic.Classes.OpeningBraceSameLine.ContentAfterBrace

/**
 * One Kaiki embed, as an Elementor widget.
 *
 * ## One class per widget, and Elementor's constructor untouched
 *
 * Elementor builds a widget twice: once when it is registered, and again for
 * every placed copy, with `new ( get_class( $registered ) )( $data, $args )`.
 * A single class taking the slug and definition as constructor arguments
 * worked for the first and was a fatal `TypeError` for the second — so every
 * page holding a Kaiki widget crashed when saved or rendered. The slug is
 * therefore a constant on a subclass, and the definition is looked up from it.
 */
abstract class Widget extends Widget_Base {

	/**
	 * The widget's slug, a key of {@see Widgets::definitions()}.
	 */
	protected const SLUG = '';

	/**
	 * What this widget renders and what it asks for.
	 *
	 * @var array{title: string, callback: string, product: bool, category: bool}
	 */
	private array $definition;

	/**
	 * Elementor's own signature, because Elementor is the one calling it.
	 *
	 * @param array<string, mixed>      $data Elementor's own data.
	 * @param array<string, mixed>|null $args Elementor's own args.
	 */
	public function __construct( $data = array(), $args = null ) {
		$this->definition = Widgets::definitions()[ static::SLUG ];

		parent::__construct( $data, $args );
	}

	/**
	 * The widget's machine name.
	 */
	public function get_name(): string {
		return static::SLUG;
	}

	/**
	 * What an operator sees in the panel.
	 */
	public function get_title(): string {
		return $this->definition['title'];
	}

	/**
	 * Elementor's own icon set.
	 */
	public function get_icon(): string {
		return 'eicon-calendar';
	}

	/**
	 * Which panel category it appears under.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return array( 'general' );
	}

	/**
	 * The same two questions the blocks ask, in Elementor's vocabulary.
	 */
	protected function register_controls(): void {
		$this->start_controls_section(
			'kaiki_content',
			array(
				'label' => __( 'Kaiki', 'kaiki-booking' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		if ( $this->definition['product'] ) {
			$this->add_control(
				'product',
				array(
					'label'       => __( 'Trip id', 'kaiki-booking' ),
					'type'        => Controls_Manager::TEXT,
					'description' => __( 'From your Kaiki panel, on the trip. Leave empty on a trip template and the page fills it in.', 'kaiki-booking' ),
					'default'     => '',

					/*
					 * Elementor's dynamic tags, so the id can come from a custom
					 * field rather than be typed. Left on even though
					 * `CurrentTrip` already resolves an empty one: the fallback
					 * reads `_kaiki_uuid` and an operator whose trip id lives in
					 * a field of their own needs a way to point at it that does
					 * not involve renaming their data to suit us.
					 *
					 * Harmless without Elementor Pro — the free editor ignores
					 * the key and renders the plain text field.
					 */
					'dynamic'     => array( 'active' => true ),
				)
			);
		}

		if ( 'booking' === $this->definition['callback'] ) {
			$this->add_control(
				'compact',
				array(
					'label'       => __( 'Trip details in the form', 'kaiki-booking' ),
					'type'        => Controls_Manager::SELECT,
					'default'     => '',
					'options'     => array(
						''    => __( 'Automatic', 'kaiki-booking' ),
						'yes' => __( 'Hide (compact form)', 'kaiki-booking' ),
						'no'  => __( 'Show', 'kaiki-booking' ),
					),
					'description' => __( 'The trip, duration, port and boat lines above the calendar. Automatic hides them on a trip page, which already shows them.', 'kaiki-booking' ),
				)
			);
		}

		if ( $this->definition['category'] ) {
			$this->add_control(
				'category',
				array(
					'label'       => __( 'Category', 'kaiki-booking' ),
					'type'        => Controls_Manager::TEXT,
					'description' => __( 'Leave empty to show every trip.', 'kaiki-booking' ),
					'default'     => '',
				)
			);
		}

		$this->end_controls_section();
	}

	/**
	 * The shortcode's own callback, and nothing else.
	 *
	 * Echoed rather than returned because that is Elementor's contract, and the
	 * string is already escaped — every attribute went through `esc_attr` on the
	 * way out of the shortcode.
	 */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		$html = call_user_func(
			array( Shortcodes::class, $this->definition['callback'] ),
			Widgets::shortcode_attributes( $this->definition, is_array( $settings ) ? $settings : array() )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped at the point of output inside the shortcode; escaping again would show the markup as text.
		echo $html;
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Same reason as above: none of these may be autoloadable.

/**
 * `[kaiki_booking]` as a widget.
 */
final class Booking_Widget extends Widget {
	protected const SLUG = 'kaiki-booking';
}

/**
 * `[kaiki_list]` as a widget.
 */
final class List_Widget extends Widget {
	protected const SLUG = 'kaiki-list';
}

/**
 * `[kaiki_enquiry]` as a widget.
 */
final class Enquiry_Widget extends Widget {
	protected const SLUG = 'kaiki-enquiry';
}
