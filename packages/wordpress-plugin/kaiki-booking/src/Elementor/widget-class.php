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
 */
class Widget extends Widget_Base {

	/**
	 * The widget's slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * What this widget renders and what it asks for.
	 *
	 * @var array{title: string, callback: string, product: bool, category: bool, link: bool}
	 */
	private array $definition;

	/**
	 * Elementor constructs widgets itself, so the two Kaiki arguments come
	 * first and its own two keep their defaults.
	 *
	 * @param string                                                                            $slug       The widget name.
	 * @param array{title: string, callback: string, product: bool, category: bool, link: bool} $definition What it renders.
	 * @param array<string, mixed>                                                              $data       Elementor's own data.
	 * @param array<string, mixed>|null                                                         $args       Elementor's own args.
	 */
	public function __construct( string $slug, array $definition, array $data = array(), $args = null ) {
		$this->slug       = $slug;
		$this->definition = $definition;

		parent::__construct( $data, $args );
	}

	/**
	 * The widget's machine name.
	 */
	public function get_name(): string {
		return $this->slug;
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
					'description' => __( 'From your Kaiki panel, on the trip.', 'kaiki-booking' ),
					'default'     => '',
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

		if ( $this->definition['link'] ) {
			// On by default, like the shortcode and the block.
			$this->add_control(
				'link',
				array(
					'label'        => __( 'Days lead to the booking', 'kaiki-booking' ),
					'type'         => Controls_Manager::SWITCHER,
					'description'  => __( 'A day with room opens the trip on Kaiki, with that day already chosen.', 'kaiki-booking' ),
					'return_value' => 'yes',
					'default'      => 'yes',
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
