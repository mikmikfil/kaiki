<?php
/**
 * The trip-page Elementor widgets, declared only when Elementor exists.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Elementor;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Background;
use Elementor\Group_Control_Border;
use Elementor\Group_Control_Box_Shadow;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use Kaiki\Booking\Trip\TripShortcodes;

defined( 'ABSPATH' ) || exit;

/*
 * Not autoloadable, for the reason `widget-class.php` gives: these extend a
 * class that exists only while Elementor runs. Required from inside the
 * `elementor/widgets/register` hook by {@see TripWidgets}.
 */
if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Generic.Classes.OpeningBraceSameLine.ContentAfterBrace

/**
 * One trip piece as a widget: the shortcode's attributes as content controls,
 * and style controls that write to the piece's own `kaiki-trip-*` classes.
 *
 * Rendering is the shortcode's callback and nothing else, so a widget and a
 * shortcode with the same settings print the same markup.
 */
abstract class Trip_Widget extends Widget_Base {

	/** The widget's slug. */
	protected const SLUG = '';

	/** The shortcode tag it renders. */
	protected const TAG = '';

	/** An Elementor icon. */
	protected const ICON = 'eicon-info-box';

	/**
	 * Elementor's own signature (see `widget-class.php` for why that matters).
	 *
	 * @param array<string, mixed>      $data Elementor's data.
	 * @param array<string, mixed>|null $args Elementor's args.
	 */
	public function __construct( $data = array(), $args = null ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Pins Elementor's signature.
		parent::__construct( $data, $args );
	}

	/** The machine name. */
	public function get_name(): string {
		return static::SLUG;
	}

	/** The panel icon. */
	public function get_icon(): string {
		return static::ICON;
	}

	/**
	 * The «Kaiki · Trip page» panel category.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		return array( TripWidgets::CATEGORY );
	}

	/**
	 * Search words in the panel.
	 *
	 * @return array<int, string>
	 */
	public function get_keywords(): array {
		return array( 'kaiki', 'trip', 'εκδρομή', static::TAG );
	}

	/**
	 * The pieces' stylesheet, declared so Elementor loads it wherever the
	 * widget is — including its editor, which renders widgets without ever
	 * running the shortcode's own late enqueue into a page head.
	 *
	 * @return array<int, string>
	 */
	public function get_style_depends(): array {
		return array( 'kaiki-trip' );
	}

	/** The widget's controls. */
	protected function register_controls(): void {
		$this->start_controls_section(
			'kaiki_content',
			array(
				'label' => __( 'Kaiki', 'kaiki-booking' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);

		$this->content_controls();

		$this->add_control(
			'product',
			array(
				'label'       => __( 'Trip id', 'kaiki-booking' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'separator'   => 'before',
				'description' => __( 'Leave empty on a trip template: each trip page fills in its own. In this editor the first trip is shown as a preview.', 'kaiki-booking' ),
			)
		);

		$this->end_controls_section();

		$this->style_controls();
	}

	/** The piece's own content controls. */
	protected function content_controls(): void {
	}

	/** The piece's style controls. */
	protected function style_controls(): void {
	}

	/**
	 * The settings as shortcode attributes.
	 *
	 * @param array<string, mixed> $settings Elementor's settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		unset( $settings );

		return array();
	}

	/** Print the piece. */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$settings = is_array( $settings ) ? $settings : array();
		$atts     = $this->attributes( $settings ) + array( 'product' => (string) ( $settings['product'] ?? '' ) );

		self::emit( (string) call_user_func( array( TripShortcodes::class, TripShortcodes::TAGS[ static::TAG ] ), $atts ) );
	}

	/**
	 * Print a piece's markup and tell the surrounding sections whether it had
	 * anything ({@see EmptySections}). An editor's dashed placeholder box does
	 * not count as content.
	 *
	 * @param string $html Escaped markup.
	 */
	protected static function emit( string $html ): void {
		EmptySections::report( '' !== trim( $html ) && ! str_contains( $html, 'kaiki-trip-placeholder' ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the shortcode.
		echo $html;
	}

	/**
	 * An empty piece prints nothing at all — not even Elementor's wrapper.
	 */
	protected function should_print_empty(): bool {
		return false;
	}

	/**
	 * A style section.
	 *
	 * @param string $id    Section id.
	 * @param string $label Section label.
	 */
	protected function style_section( string $id, string $label ): void {
		$this->start_controls_section(
			$id,
			array(
				'label' => $label,
				'tab'   => Controls_Manager::TAB_STYLE,
			)
		);
	}

	/**
	 * A colour control.
	 *
	 * @param string $id       Control id.
	 * @param string $label    Label.
	 * @param string $selector Selector below the widget.
	 * @param string $property CSS property or custom property.
	 */
	protected function colour( string $id, string $label, string $selector, string $property = 'color' ): void {
		$this->add_control(
			$id,
			array(
				'label'     => $label,
				'type'      => Controls_Manager::COLOR,
				'selectors' => array( '{{WRAPPER}} ' . $selector => $property . ': {{VALUE}};' ),
			)
		);
	}

	/**
	 * A typography group.
	 *
	 * @param string $id       Control id.
	 * @param string $label    Label.
	 * @param string $selector Selector below the widget.
	 */
	protected function typography( string $id, string $label, string $selector ): void {
		$this->add_group_control(
			Group_Control_Typography::get_type(),
			array(
				'name'     => $id,
				'label'    => $label,
				'selector' => '{{WRAPPER}} ' . $selector,
			)
		);
	}

	/**
	 * A pixel slider.
	 *
	 * @param string $id       Control id.
	 * @param string $label    Label.
	 * @param string $selector Selector below the widget.
	 * @param string $property CSS property.
	 * @param int    $max      Upper bound.
	 * @param bool   $responsive Per device.
	 */
	protected function pixels( string $id, string $label, string $selector, string $property, int $max = 60, bool $responsive = false ): void {
		$args = array(
			'label'      => $label,
			'type'       => Controls_Manager::SLIDER,
			'size_units' => array( 'px' ),
			'range'      => array(
				'px' => array(
					'min' => 0,
					'max' => $max,
				),
			),
			'selectors'  => array( '{{WRAPPER}} ' . $selector => $property . ': {{SIZE}}{{UNIT}};' ),
		);

		if ( $responsive ) {
			$this->add_responsive_control( $id, $args );
		} else {
			$this->add_control( $id, $args );
		}
	}

	/**
	 * A yes/no switch.
	 *
	 * @param string $id      Control id.
	 * @param string $label   Label.
	 * @param string $initial 'yes' or ''..
	 */
	protected function switch( string $id, string $label, string $initial = 'yes' ): void {
		$this->add_control(
			$id,
			array(
				'label'        => $label,
				'type'         => Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => $initial,
			)
		);
	}

	/**
	 * A switcher's value as a shortcode attribute.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $id       Control id.
	 */
	protected static function yes_no( array $settings, string $id ): string {
		return 'yes' === ( $settings[ $id ] ?? '' ) ? 'yes' : 'no';
	}

	/**
	 * Text alignment for a block.
	 *
	 * @param string $selector Selector below the widget.
	 */
	protected function alignment( string $selector ): void {
		$this->add_responsive_control(
			'align',
			array(
				'label'     => __( 'Alignment', 'kaiki-booking' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => __( 'Left', 'kaiki-booking' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Centre', 'kaiki-booking' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'kaiki-booking' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'selectors' => array( '{{WRAPPER}} ' . $selector => 'text-align: {{VALUE}};' ),
			)
		);
	}
}

/**
 * `[kaiki_trip_title]`
 */
final class Trip_Title_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-title';
	protected const TAG  = 'kaiki_trip_title';
	protected const ICON = 'eicon-heading';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip title', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->add_control(
			'tag',
			array(
				'label'   => __( 'HTML tag', 'kaiki-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'h1',
				'options' => array(
					'h1'  => 'H1',
					'h2'  => 'H2',
					'h3'  => 'H3',
					'div' => 'div',
				),
			)
		);
		$this->switch( 'summary', __( 'Show the summary', 'kaiki-booking' ) );
		$this->switch( 'back', __( 'Show a link back', 'kaiki-booking' ), '' );
		$this->add_control(
			'back_text',
			array(
				'label'     => __( 'Link text', 'kaiki-booking' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( '← All trips', 'kaiki-booking' ),
				'condition' => array( 'back' => 'yes' ),
			)
		);
		$this->add_control(
			'back_url',
			array(
				'label'     => __( 'Link address', 'kaiki-booking' ),
				'type'      => Controls_Manager::URL,
				'condition' => array( 'back' => 'yes' ),
			)
		);
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_title', __( 'Title', 'kaiki-booking' ) );
		$this->alignment( '.kaiki-trip-title' );
		$this->colour( 'title_color', __( 'Colour', 'kaiki-booking' ), '.kaiki-trip-title__heading' );
		$this->typography( 'title_typography', __( 'Typography', 'kaiki-booking' ), '.kaiki-trip-title__heading' );
		$this->pixels( 'title_spacing', __( 'Space below', 'kaiki-booking' ), '.kaiki-trip-title__heading', 'margin-bottom', 60, true );
		$this->end_controls_section();

		$this->style_section( 'style_summary', __( 'Summary', 'kaiki-booking' ) );
		$this->colour( 'summary_color', __( 'Colour', 'kaiki-booking' ), '.kaiki-trip-title__summary' );
		$this->typography( 'summary_typography', __( 'Typography', 'kaiki-booking' ), '.kaiki-trip-title__summary' );
		$this->end_controls_section();

		$this->style_section( 'style_back', __( 'Link back', 'kaiki-booking' ) );
		$this->colour( 'back_color', __( 'Colour', 'kaiki-booking' ), '.kaiki-trip-title__back' );
		$this->colour( 'back_hover_color', __( 'Hover colour', 'kaiki-booking' ), '.kaiki-trip-title__back:hover' );
		$this->typography( 'back_typography', __( 'Typography', 'kaiki-booking' ), '.kaiki-trip-title__back' );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		$atts = array(
			'tag'     => (string) ( $settings['tag'] ?? 'h1' ),
			'summary' => self::yes_no( $settings, 'summary' ),
			'back'    => self::yes_no( $settings, 'back' ),
		);

		if ( ! empty( $settings['back_text'] ) ) {
			$atts['back_text'] = (string) $settings['back_text'];
		}

		if ( ! empty( $settings['back_url']['url'] ) ) {
			$atts['back_url'] = (string) $settings['back_url']['url'];
		}

		return $atts;
	}
}

/**
 * `[kaiki_trip_facts]`
 */
final class Trip_Facts_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-facts';
	protected const TAG  = 'kaiki_trip_facts';
	protected const ICON = 'eicon-bullet-list';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip facts', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->add_control(
			'show',
			array(
				'label'       => __( 'Show', 'kaiki-booking' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'default'     => array( 'duration', 'departure', 'meeting_point', 'vessel', 'capacity' ),
				'options'     => array(
					'category'      => __( 'Type of trip', 'kaiki-booking' ),
					'duration'      => __( 'Duration', 'kaiki-booking' ),
					'departure'     => __( 'Departure time', 'kaiki-booking' ),
					'meeting_point' => __( 'Meeting point', 'kaiki-booking' ),
					'vessel'        => __( 'Boat', 'kaiki-booking' ),
					'capacity'      => __( 'Maximum guests', 'kaiki-booking' ),
				),
			)
		);
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_facts', __( 'Facts', 'kaiki-booking' ) );
		$this->add_responsive_control(
			'justify',
			array(
				'label'     => __( 'Alignment', 'kaiki-booking' ),
				'type'      => Controls_Manager::CHOOSE,
				'options'   => array(
					'flex-start' => array(
						'title' => __( 'Left', 'kaiki-booking' ),
						'icon'  => 'eicon-h-align-left',
					),
					'center'     => array(
						'title' => __( 'Centre', 'kaiki-booking' ),
						'icon'  => 'eicon-h-align-center',
					),
					'flex-end'   => array(
						'title' => __( 'Right', 'kaiki-booking' ),
						'icon'  => 'eicon-h-align-right',
					),
				),
				'selectors' => array( '{{WRAPPER}} .kaiki-trip-facts' => 'justify-content: {{VALUE}};' ),
			)
		);
		$this->pixels( 'gap', __( 'Gap', 'kaiki-booking' ), '.kaiki-trip-facts', 'gap', 40 );
		$this->colour( 'item_background', __( 'Background', 'kaiki-booking' ), '.kaiki-trip-facts__item', 'background-color' );
		$this->colour( 'item_color', __( 'Text colour', 'kaiki-booking' ), '.kaiki-trip-facts__item' );
		$this->colour( 'icon_color', __( 'Icon colour', 'kaiki-booking' ), '.kaiki-trip-facts__item .kaiki-trip-icon' );
		$this->typography( 'item_typography', __( 'Typography', 'kaiki-booking' ), '.kaiki-trip-facts__item' );
		$this->pixels( 'item_radius', __( 'Corner radius', 'kaiki-booking' ), '.kaiki-trip-facts__item', 'border-radius', 40 );
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'item_border',
				'selector' => '{{WRAPPER}} .kaiki-trip-facts__item',
			)
		);
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		return array( 'show' => implode( ',', array_map( 'strval', (array) ( $settings['show'] ?? array() ) ) ) );
	}
}

/**
 * `[kaiki_trip_gallery]`
 */
final class Trip_Gallery_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-gallery';
	protected const TAG  = 'kaiki_trip_gallery';
	protected const ICON = 'eicon-gallery-grid';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip photos', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'kaiki-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'mosaic',
				'options' => array(
					'mosaic' => __( 'One large, four small', 'kaiki-booking' ),
					'grid'   => __( 'Grid', 'kaiki-booking' ),
					'single' => __( 'The main photo only', 'kaiki-booking' ),
				),
			)
		);
		$this->add_control(
			'max',
			array(
				'label'     => __( 'Most photos', 'kaiki-booking' ),
				'type'      => Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 30,
				'default'   => 6,
				'condition' => array( 'layout' => 'grid' ),
			)
		);
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_gallery', __( 'Photos', 'kaiki-booking' ) );
		$this->pixels( 'height', __( 'Height', 'kaiki-booking' ), '.kaiki-trip-gallery', '--kaiki-gallery-height', 900, true );
		$this->pixels( 'gap', __( 'Gap', 'kaiki-booking' ), '.kaiki-trip-gallery', '--kaiki-gallery-gap', 40 );
		$this->pixels( 'radius', __( 'Corner radius', 'kaiki-booking' ), '.kaiki-trip-gallery__item', 'border-radius', 40 );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		return array(
			'layout' => (string) ( $settings['layout'] ?? 'mosaic' ),
			'max'    => (string) (int) ( $settings['max'] ?? 5 ),
		);
	}
}

/**
 * `[kaiki_trip_description]`
 */
final class Trip_Description_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-description';
	protected const TAG  = 'kaiki_trip_description';
	protected const ICON = 'eicon-text';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip description', 'kaiki-booking' );
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_text', __( 'Text', 'kaiki-booking' ) );
		$this->alignment( '.kaiki-trip-description' );
		$this->colour( 'text_color', __( 'Colour', 'kaiki-booking' ), '.kaiki-trip-description' );
		$this->typography( 'text_typography', __( 'Typography', 'kaiki-booking' ), '.kaiki-trip-description' );
		$this->pixels( 'paragraph_spacing', __( 'Space between paragraphs', 'kaiki-booking' ), '.kaiki-trip-description p', 'margin-bottom', 60 );
		$this->end_controls_section();
	}
}

/**
 * `[kaiki_trip_price]`
 */
final class Trip_Price_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-price';
	protected const TAG  = 'kaiki_trip_price';
	protected const ICON = 'eicon-price-list';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip price', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->add_control(
			'prefix',
			array(
				'label'   => __( 'Before the price', 'kaiki-booking' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'from', 'kaiki-booking' ),
			)
		);
		$this->switch( 'unit', __( 'Show «/ person»', 'kaiki-booking' ) );
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_price', __( 'Price', 'kaiki-booking' ) );
		$this->colour( 'amount_color', __( 'Price colour', 'kaiki-booking' ), '.kaiki-trip-price__amount' );
		$this->typography( 'amount_typography', __( 'Price typography', 'kaiki-booking' ), '.kaiki-trip-price__amount' );
		$this->colour( 'small_color', __( 'Small text colour', 'kaiki-booking' ), '.kaiki-trip-price__prefix, {{WRAPPER}} .kaiki-trip-price__unit' );
		$this->typography( 'small_typography', __( 'Small text typography', 'kaiki-booking' ), '.kaiki-trip-price__prefix, {{WRAPPER}} .kaiki-trip-price__unit' );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		return array(
			'prefix' => (string) ( $settings['prefix'] ?? '' ),
			'unit'   => self::yes_no( $settings, 'unit' ),
		);
	}
}

/**
 * `[kaiki_trip_list]`
 */
final class Trip_List_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-list';
	protected const TAG  = 'kaiki_trip_list';
	protected const ICON = 'eicon-checkbox';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip list (included, bring…)', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->add_control(
			'source',
			array(
				'label'       => __( 'List', 'kaiki-booking' ),
				'type'        => Controls_Manager::SELECT,
				'default'     => 'includes',
				'options'     => array(
					'includes'   => __( 'What is included', 'kaiki-booking' ),
					'excludes'   => __( 'What is not included', 'kaiki-booking' ),
					'bring'      => __( 'What to bring', 'kaiki-booking' ),
					'highlights' => __( 'Highlights', 'kaiki-booking' ),
				),
				'description' => __( 'From Kaiki, or from your own fields through the kaiki_trip_list filter.', 'kaiki-booking' ),
			)
		);
		$this->add_responsive_control(
			'columns',
			array(
				'label'          => __( 'Columns', 'kaiki-booking' ),
				'type'           => Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 3,
				'default'        => 1,
				'mobile_default' => 1,
				'selectors'      => array( '{{WRAPPER}} .kaiki-trip-list' => '--kaiki-list-columns: {{VALUE}};' ),
			)
		);
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_list', __( 'List', 'kaiki-booking' ) );
		$this->pixels( 'row_gap', __( 'Space between items', 'kaiki-booking' ), '.kaiki-trip-list', 'row-gap', 40 );
		$this->colour( 'icon_color', __( 'Icon colour', 'kaiki-booking' ), '.kaiki-trip-list__item .kaiki-trip-icon' );
		$this->pixels( 'icon_size', __( 'Icon size', 'kaiki-booking' ), '.kaiki-trip-list__item .kaiki-trip-icon', 'width', 40 );
		$this->colour( 'text_color', __( 'Text colour', 'kaiki-booking' ), '.kaiki-trip-list__item' );
		$this->typography( 'text_typography', __( 'Typography', 'kaiki-booking' ), '.kaiki-trip-list__item' );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		return array( 'source' => (string) ( $settings['source'] ?? 'includes' ) );
	}
}

/**
 * `[kaiki_trip_itinerary]`
 */
final class Trip_Itinerary_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-itinerary';
	protected const TAG  = 'kaiki_trip_itinerary';
	protected const ICON = 'eicon-time-line';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip itinerary', 'kaiki-booking' );
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_itinerary', __( 'Itinerary', 'kaiki-booking' ) );
		$this->colour( 'dot_color', __( 'Dot colour', 'kaiki-booking' ), '.kaiki-trip-itinerary', '--kaiki-trip-accent' );
		$this->colour( 'line_color', __( 'Line colour', 'kaiki-booking' ), '.kaiki-trip-itinerary', '--kaiki-trip-line' );
		$this->colour( 'time_color', __( 'Time colour', 'kaiki-booking' ), '.kaiki-trip-itinerary__time' );
		$this->typography( 'time_typography', __( 'Time typography', 'kaiki-booking' ), '.kaiki-trip-itinerary__time' );
		$this->colour( 'text_color', __( 'Text colour', 'kaiki-booking' ), '.kaiki-trip-itinerary__text' );
		$this->typography( 'text_typography', __( 'Text typography', 'kaiki-booking' ), '.kaiki-trip-itinerary__text' );
		$this->end_controls_section();
	}
}

/**
 * `[kaiki_trip_meeting_point]`
 */
final class Trip_Meeting_Point_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-meeting-point';
	protected const TAG  = 'kaiki_trip_meeting_point';
	protected const ICON = 'eicon-map-pin';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Meeting point', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->switch( 'instructions', __( 'Show the directions', 'kaiki-booking' ) );
		$this->switch( 'map', __( 'Show a map link', 'kaiki-booking' ) );
		$this->add_control(
			'map_text',
			array(
				'label'     => __( 'Map link text', 'kaiki-booking' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => __( 'Open in maps →', 'kaiki-booking' ),
				'condition' => array( 'map' => 'yes' ),
			)
		);
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_box', __( 'Box', 'kaiki-booking' ) );
		$this->add_group_control(
			Group_Control_Background::get_type(),
			array(
				'name'     => 'box_background',
				'types'    => array( 'classic', 'gradient' ),
				'selector' => '{{WRAPPER}} .kaiki-trip-meeting',
			)
		);
		$this->add_responsive_control(
			'box_padding',
			array(
				'label'      => __( 'Padding', 'kaiki-booking' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .kaiki-trip-meeting' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->pixels( 'box_radius', __( 'Corner radius', 'kaiki-booking' ), '.kaiki-trip-meeting', 'border-radius', 40 );
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'box_border',
				'selector' => '{{WRAPPER}} .kaiki-trip-meeting',
			)
		);
		$this->end_controls_section();

		$this->style_section( 'style_text', __( 'Text', 'kaiki-booking' ) );
		$this->colour( 'icon_color', __( 'Icon colour', 'kaiki-booking' ), '.kaiki-trip-meeting > .kaiki-trip-icon' );
		$this->colour( 'name_color', __( 'Name colour', 'kaiki-booking' ), '.kaiki-trip-meeting__name' );
		$this->typography( 'name_typography', __( 'Name typography', 'kaiki-booking' ), '.kaiki-trip-meeting__name' );
		$this->colour( 'text_color', __( 'Text colour', 'kaiki-booking' ), '.kaiki-trip-meeting__address, {{WRAPPER}} .kaiki-trip-meeting__instructions' );
		$this->typography( 'text_typography', __( 'Text typography', 'kaiki-booking' ), '.kaiki-trip-meeting__address, {{WRAPPER}} .kaiki-trip-meeting__instructions' );
		$this->colour( 'link_color', __( 'Link colour', 'kaiki-booking' ), '.kaiki-trip-meeting__map' );
		$this->colour( 'link_hover_color', __( 'Link hover colour', 'kaiki-booking' ), '.kaiki-trip-meeting__map:hover' );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		$atts = array(
			'instructions' => self::yes_no( $settings, 'instructions' ),
			'map'          => self::yes_no( $settings, 'map' ),
		);

		if ( ! empty( $settings['map_text'] ) ) {
			$atts['map_text'] = (string) $settings['map_text'];
		}

		return $atts;
	}
}

/**
 * `[kaiki_trip_cancellation]`
 */
final class Trip_Cancellation_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-cancellation';
	protected const TAG  = 'kaiki_trip_cancellation';
	protected const ICON = 'eicon-undo';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Cancellation policy', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->switch( 'tiers', __( 'Show the refund steps', 'kaiki-booking' ) );
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_policy', __( 'Policy', 'kaiki-booking' ) );
		$this->colour( 'summary_color', __( 'Summary colour', 'kaiki-booking' ), '.kaiki-trip-cancellation__summary' );
		$this->typography( 'summary_typography', __( 'Summary typography', 'kaiki-booking' ), '.kaiki-trip-cancellation__summary' );
		$this->colour( 'tier_border', __( 'Step border colour', 'kaiki-booking' ), '.kaiki-trip-cancellation__tier', 'border-color' );
		$this->colour( 'tier_background', __( 'Step background', 'kaiki-booking' ), '.kaiki-trip-cancellation__tier', 'background-color' );
		$this->pixels( 'tier_radius', __( 'Step corner radius', 'kaiki-booking' ), '.kaiki-trip-cancellation__tier', 'border-radius', 30 );
		$this->colour( 'refund_color', __( 'Refund colour', 'kaiki-booking' ), '.kaiki-trip-cancellation__refund' );
		$this->typography( 'tier_typography', __( 'Step typography', 'kaiki-booking' ), '.kaiki-trip-cancellation__tier' );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		return array( 'tiers' => self::yes_no( $settings, 'tiers' ) );
	}
}

/**
 * `[kaiki_trips]`
 */
final class Trips_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trips';
	protected const TAG  = 'kaiki_trips';
	protected const ICON = 'eicon-posts-grid';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip cards', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->add_control(
			'limit',
			array(
				'label'       => __( 'How many', 'kaiki-booking' ),
				'type'        => Controls_Manager::NUMBER,
				'min'         => 0,
				'default'     => 0,
				'description' => __( '0 shows every trip.', 'kaiki-booking' ),
			)
		);
		$this->add_control(
			'category',
			array(
				'label'       => __( 'Only these types', 'kaiki-booking' ),
				'type'        => Controls_Manager::SELECT2,
				'multiple'    => true,
				'label_block' => true,
				'options'     => array(
					'shared_half_day'  => __( 'Half day', 'kaiki-booking' ),
					'shared_full_day'  => __( 'Full day', 'kaiki-booking' ),
					'private_half_day' => __( 'Private, half day', 'kaiki-booking' ),
					'private_full_day' => __( 'Private, full day', 'kaiki-booking' ),
					'sunset'           => __( 'Sunset', 'kaiki-booking' ),
					'custom'           => __( 'Special', 'kaiki-booking' ),
				),
			)
		);
		$this->switch( 'exclude_current', __( 'Leave out the trip being viewed', 'kaiki-booking' ), '' );
		$this->add_control(
			'layout',
			array(
				'label'     => __( 'Layout', 'kaiki-booking' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => 'cards',
				'separator' => 'before',
				'options'   => array(
					'cards'      => __( 'Cards: photo above', 'kaiki-booking' ),
					'horizontal' => __( 'Horizontal: photo beside', 'kaiki-booking' ),
					'overlay'    => __( 'Overlay: text over the photo', 'kaiki-booking' ),
					'minimal'    => __( 'Minimal: no photo', 'kaiki-booking' ),
				),
			)
		);
		$this->add_responsive_control(
			'ratio',
			array(
				'label'     => __( 'Photo shape', 'kaiki-booking' ),
				'type'      => Controls_Manager::SELECT,
				'default'   => '',
				'options'   => array(
					''     => __( 'Layout default', 'kaiki-booking' ),
					'1/1'  => __( 'Square', 'kaiki-booking' ),
					'4/3'  => '4:3',
					'3/2'  => '3:2',
					'16/9' => '16:9',
					'4/5'  => __( 'Portrait 4:5', 'kaiki-booking' ),
				),
				'condition' => array( 'layout!' => 'minimal' ),
				'selectors' => array( '{{WRAPPER}} .kaiki-trip-card__media' => 'aspect-ratio: {{VALUE}};' ),
			)
		);
		$this->switch( 'photo', __( 'Show the photo', 'kaiki-booking' ) );
		$this->switch( 'badge', __( 'Show the label on the photo', 'kaiki-booking' ) );
		$this->switch( 'summary', __( 'Show the summary', 'kaiki-booking' ) );
		$this->switch( 'facts', __( 'Show duration, time and boat', 'kaiki-booking' ) );
		$this->switch( 'price', __( 'Show the price', 'kaiki-booking' ) );
		$this->add_control(
			'cta',
			array(
				'label'   => __( 'Card link text', 'kaiki-booking' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'View & book →', 'kaiki-booking' ),
			)
		);
		$this->add_responsive_control(
			'columns',
			array(
				'label'          => __( 'Columns', 'kaiki-booking' ),
				'type'           => Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 3,
				'default'        => 3,
				'tablet_default' => 2,
				'mobile_default' => 1,
				'selectors'      => array( '{{WRAPPER}} .kaiki-trips' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
			)
		);
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_card', __( 'Card', 'kaiki-booking' ) );
		$this->pixels( 'gap', __( 'Gap', 'kaiki-booking' ), '.kaiki-trips', 'gap', 60, true );
		$this->colour( 'card_background', __( 'Background', 'kaiki-booking' ), '.kaiki-trip-card', 'background-color' );
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'card_border',
				'selector' => '{{WRAPPER}} .kaiki-trip-card',
			)
		);
		$this->pixels( 'card_radius', __( 'Corner radius', 'kaiki-booking' ), '.kaiki-trip-card', 'border-radius', 40 );
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow',
				'selector' => '{{WRAPPER}} .kaiki-trip-card',
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_hover_shadow',
				'label'    => __( 'Hover shadow', 'kaiki-booking' ),
				'selector' => '{{WRAPPER}} .kaiki-trip-card:hover',
			)
		);
		$this->end_controls_section();

		$this->style_section( 'style_card_text', __( 'Card text', 'kaiki-booking' ) );
		$this->colour( 'title_color', __( 'Title colour', 'kaiki-booking' ), '.kaiki-trip-card__title' );
		$this->typography( 'title_typography', __( 'Title typography', 'kaiki-booking' ), '.kaiki-trip-card__title' );
		$this->colour( 'summary_color', __( 'Summary colour', 'kaiki-booking' ), '.kaiki-trip-card__summary, {{WRAPPER}} .kaiki-trip-card__facts' );
		$this->colour( 'price_color', __( 'Price colour', 'kaiki-booking' ), '.kaiki-trip-card .kaiki-trip-price__amount' );
		$this->typography( 'price_typography', __( 'Price typography', 'kaiki-booking' ), '.kaiki-trip-card .kaiki-trip-price__amount' );
		$this->colour( 'cta_color', __( 'Link colour', 'kaiki-booking' ), '.kaiki-trip-card__cta' );
		$this->colour( 'cta_hover_color', __( 'Link hover colour', 'kaiki-booking' ), '.kaiki-trip-card:hover .kaiki-trip-card__cta' );
		$this->colour( 'overlay_color', __( 'Overlay shade', 'kaiki-booking' ), '.kaiki-trips--overlay .kaiki-trip-card', '--kaiki-overlay' );
		$this->colour( 'badge_background', __( 'Label background', 'kaiki-booking' ), '.kaiki-trip-card__badge', 'background-color' );
		$this->colour( 'badge_color', __( 'Label colour', 'kaiki-booking' ), '.kaiki-trip-card__badge' );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		$atts = array(
			'limit'           => (string) (int) ( $settings['limit'] ?? 0 ),
			'category'        => implode( ',', array_map( 'strval', (array) ( $settings['category'] ?? array() ) ) ),
			'exclude_current' => self::yes_no( $settings, 'exclude_current' ),
			'summary'         => self::yes_no( $settings, 'summary' ),
			'facts'           => self::yes_no( $settings, 'facts' ),
			'layout'          => (string) ( $settings['layout'] ?? 'cards' ),
			'photo'           => self::yes_no( $settings, 'photo' ),
			'badge'           => self::yes_no( $settings, 'badge' ),
			'price'           => self::yes_no( $settings, 'price' ),
		);

		if ( ! empty( $settings['cta'] ) ) {
			$atts['cta'] = (string) $settings['cta'];
		}

		return $atts;
	}
}

/**
 * `[kaiki_trip_field]` — any value Kaiki has for the trip.
 */
final class Trip_Field_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-trip-field';
	protected const TAG  = 'kaiki_trip_field';
	protected const ICON = 'eicon-database';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Kaiki field (any trip detail)', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->add_control(
			'key',
			array(
				'label'   => __( 'Detail', 'kaiki-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'title',
				'options' => array(
					'title'                              => __( 'Title', 'kaiki-booking' ),
					'summary'                            => __( 'Summary', 'kaiki-booking' ),
					'description'                        => __( 'Description', 'kaiki-booking' ),
					'price_line'                         => __( 'Price line', 'kaiki-booking' ),
					'from_price_formatted'               => __( 'Price', 'kaiki-booking' ),
					'booking_type'                       => __( 'How it is booked', 'kaiki-booking' ),
					'default_start_time'                 => __( 'Departure time', 'kaiki-booking' ),
					'duration_minutes'                   => __( 'Duration in minutes', 'kaiki-booking' ),
					'min_pax'                            => __( 'Minimum guests', 'kaiki-booking' ),
					'max_pax'                            => __( 'Maximum guests', 'kaiki-booking' ),
					'vessel.name'                        => __( 'Boat', 'kaiki-booking' ),
					'vessel.description'                 => __( 'Boat description', 'kaiki-booking' ),
					'vessel.capacity_max'                => __( 'Boat capacity', 'kaiki-booking' ),
					'vessel.length_m'                    => __( 'Boat length (m)', 'kaiki-booking' ),
					'vessel.crew_count'                  => __( 'Crew', 'kaiki-booking' ),
					'vessel.specs.year_built'            => __( 'Year built', 'kaiki-booking' ),
					'vessel.specs.engine'                => __( 'Engine', 'kaiki-booking' ),
					'meeting_point.name'                 => __( 'Meeting point', 'kaiki-booking' ),
					'meeting_point.address'              => __( 'Meeting point address', 'kaiki-booking' ),
					'meeting_point.instructions'         => __( 'How to find the meeting point', 'kaiki-booking' ),
					'meeting_point.maps_url'             => __( 'Map link', 'kaiki-booking' ),
					'includes'                           => __( 'What is included', 'kaiki-booking' ),
					'excludes'                           => __( 'What is not included', 'kaiki-booking' ),
					'what_to_bring'                      => __( 'What to bring', 'kaiki-booking' ),
					'images'                             => __( 'Photos', 'kaiki-booking' ),
					'hero_image_url'                     => __( 'Main photo', 'kaiki-booking' ),
					'cancellation_policy.name'           => __( 'Cancellation policy name', 'kaiki-booking' ),
					'cancellation_policy.summary'        => __( 'Cancellation policy', 'kaiki-booking' ),
					'booking_window.max_advance_days'    => __( 'Bookable days ahead', 'kaiki-booking' ),
					'booking_window.min_lead_time_hours' => __( 'Hours notice needed', 'kaiki-booking' ),
					'check_in_offset_minutes'            => __( 'Arrive minutes early', 'kaiki-booking' ),
					'booking_url'                        => __( 'Kaiki booking page', 'kaiki-booking' ),
					'custom'                             => __( 'Other (type the path)', 'kaiki-booking' ),
				),
			)
		);
		$this->add_control(
			'custom_key',
			array(
				'label'       => __( 'Path', 'kaiki-booking' ),
				'type'        => Controls_Manager::TEXT,
				'placeholder' => 'vessel.specs.cruising_speed_kn',
				'description' => __( 'Any field of the trip in Kaiki\'s API, with dots between levels.', 'kaiki-booking' ),
				'condition'   => array( 'key' => 'custom' ),
			)
		);
		$this->add_control(
			'format',
			array(
				'label'   => __( 'Show as', 'kaiki-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'text',
				'options' => array(
					'text'  => __( 'Text', 'kaiki-booking' ),
					'list'  => __( 'List', 'kaiki-booking' ),
					'html'  => __( 'Paragraphs', 'kaiki-booking' ),
					'image' => __( 'Photo', 'kaiki-booking' ),
					'url'   => __( 'Address only', 'kaiki-booking' ),
				),
			)
		);
		$this->add_control(
			'html_tag',
			array(
				'label'   => __( 'HTML tag', 'kaiki-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'div',
				'options' => array(
					'h1'   => 'H1',
					'h2'   => 'H2',
					'h3'   => 'H3',
					'h4'   => 'H4',
					'p'    => 'p',
					'span' => 'span',
					'div'  => 'div',
				),
			)
		);
		$this->add_control(
			'before',
			array(
				'label' => __( 'Text before', 'kaiki-booking' ),
				'type'  => Controls_Manager::TEXT,
			)
		);
		$this->add_control(
			'after',
			array(
				'label' => __( 'Text after', 'kaiki-booking' ),
				'type'  => Controls_Manager::TEXT,
			)
		);
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_text', __( 'Text', 'kaiki-booking' ) );
		$this->alignment( '.kaiki-trip-field' );
		$this->colour( 'text_color', __( 'Colour', 'kaiki-booking' ), '.kaiki-trip-field' );
		$this->typography( 'text_typography', __( 'Typography', 'kaiki-booking' ), '.kaiki-trip-field' );
		$this->pixels( 'image_radius', __( 'Photo corner radius', 'kaiki-booking' ), '.kaiki-trip-field img', 'border-radius', 40 );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		$key = (string) ( $settings['key'] ?? '' );

		return array(
			'key'    => 'custom' === $key ? (string) ( $settings['custom_key'] ?? '' ) : $key,
			'format' => (string) ( $settings['format'] ?? 'text' ),
			'before' => (string) ( $settings['before'] ?? '' ),
			'after'  => (string) ( $settings['after'] ?? '' ),
		);
	}

	/** Print the value inside the chosen tag, or nothing when it is empty. */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$settings = is_array( $settings ) ? $settings : array();
		$html     = TripShortcodes::field( $this->attributes( $settings ) + array( 'product' => (string) ( $settings['product'] ?? '' ) ) );

		if ( '' === $html ) {
			self::emit( '' );

			return;
		}

		$tag = in_array( $settings['html_tag'] ?? 'div', array( 'h1', 'h2', 'h3', 'h4', 'p', 'span', 'div' ), true ) ? $settings['html_tag'] : 'div';

		self::emit( sprintf( '<%1$s class="kaiki-trip-field">%2$s</%1$s>', $tag, $html ) );
	}
}

/**
 * `[kaiki_fleet]` — the boats, from Kaiki.
 */
final class Fleet_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-fleet';
	protected const TAG  = 'kaiki_fleet';
	protected const ICON = 'eicon-gallery-justified';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Fleet', 'kaiki-booking' );
	}

	/** Content. */
	protected function content_controls(): void {
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'kaiki-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'rows',
				'options' => array(
					'rows'      => __( 'Rows: photo beside, alternating', 'kaiki-booking' ),
					'rows-left' => __( 'Rows: photo always on the left', 'kaiki-booking' ),
					'grid'      => __( 'Cards in a grid', 'kaiki-booking' ),
					'overlay'   => __( 'Overlay: name over the photo', 'kaiki-booking' ),
					'compact'   => __( 'Compact list', 'kaiki-booking' ),
				),
			)
		);
		$this->add_responsive_control(
			'columns',
			array(
				'label'          => __( 'Columns', 'kaiki-booking' ),
				'type'           => Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 3,
				'default'        => 3,
				'tablet_default' => 2,
				'mobile_default' => 1,
				'condition'      => array( 'layout' => array( 'grid', 'overlay' ) ),
				'selectors'      => array( '{{WRAPPER}} .kaiki-fleet--grid, {{WRAPPER}} .kaiki-fleet--overlay' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
			)
		);
		$this->switch( 'photos', __( 'Show the photo', 'kaiki-booking' ) );
		$this->switch( 'specs', __( 'Show the numbers (guests, length, crew…)', 'kaiki-booking' ) );
		$this->switch( 'amenities', __( 'Show the amenities', 'kaiki-booking' ) );
		$this->switch( 'trips', __( 'Show the trips it runs', 'kaiki-booking' ) );
	}

	/** Style. */
	protected function style_controls(): void {
		$this->style_section( 'style_card', __( 'Card', 'kaiki-booking' ) );
		$this->pixels( 'gap', __( 'Gap', 'kaiki-booking' ), '.kaiki-fleet', 'gap', 80, true );
		$this->colour( 'card_background', __( 'Background', 'kaiki-booking' ), '.kaiki-vessel', 'background-color' );
		$this->pixels( 'card_radius', __( 'Corner radius', 'kaiki-booking' ), '.kaiki-vessel', 'border-radius', 40 );
		$this->add_group_control(
			Group_Control_Border::get_type(),
			array(
				'name'     => 'card_border',
				'selector' => '{{WRAPPER}} .kaiki-vessel',
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'card_shadow',
				'selector' => '{{WRAPPER}} .kaiki-vessel',
			)
		);
		$this->pixels( 'photo_height', __( 'Photo height', 'kaiki-booking' ), '.kaiki-vessel__media', 'min-height', 700, true );
		$this->add_responsive_control(
			'body_padding',
			array(
				'label'      => __( 'Padding', 'kaiki-booking' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .kaiki-vessel__body' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->end_controls_section();

		$this->style_section( 'style_text', __( 'Text', 'kaiki-booking' ) );
		$this->colour( 'overlay_color', __( 'Overlay shade', 'kaiki-booking' ), '.kaiki-fleet--overlay .kaiki-vessel', '--kaiki-overlay' );
		$this->colour( 'type_color', __( 'Type colour', 'kaiki-booking' ), '.kaiki-vessel__type' );
		$this->typography( 'type_typography', __( 'Type typography', 'kaiki-booking' ), '.kaiki-vessel__type' );
		$this->colour( 'name_color', __( 'Name colour', 'kaiki-booking' ), '.kaiki-vessel__name' );
		$this->typography( 'name_typography', __( 'Name typography', 'kaiki-booking' ), '.kaiki-vessel__name' );
		$this->colour( 'text_color', __( 'Text colour', 'kaiki-booking' ), '.kaiki-vessel__description, {{WRAPPER}} .kaiki-vessel__trips' );
		$this->typography( 'text_typography', __( 'Text typography', 'kaiki-booking' ), '.kaiki-vessel__description' );
		$this->colour( 'link_color', __( 'Link colour', 'kaiki-booking' ), '.kaiki-vessel__trips a' );
		$this->colour( 'link_hover_color', __( 'Link hover colour', 'kaiki-booking' ), '.kaiki-vessel__trips a:hover' );
		$this->end_controls_section();

		$this->style_section( 'style_specs', __( 'Numbers and amenities', 'kaiki-booking' ) );
		$this->colour( 'spec_background', __( 'Number box background', 'kaiki-booking' ), '.kaiki-vessel__spec', 'background-color' );
		$this->colour( 'spec_label_color', __( 'Label colour', 'kaiki-booking' ), '.kaiki-vessel__spec dt' );
		$this->colour( 'spec_value_color', __( 'Number colour', 'kaiki-booking' ), '.kaiki-vessel__spec dd' );
		$this->typography( 'spec_value_typography', __( 'Number typography', 'kaiki-booking' ), '.kaiki-vessel__spec dd' );
		$this->colour( 'amenity_background', __( 'Amenity background', 'kaiki-booking' ), '.kaiki-vessel__amenities li', 'background-color' );
		$this->colour( 'amenity_color', __( 'Amenity colour', 'kaiki-booking' ), '.kaiki-vessel__amenities li' );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		return array(
			'layout'    => (string) ( $settings['layout'] ?? 'rows' ),
			'photos'    => self::yes_no( $settings, 'photos' ),
			'specs'     => self::yes_no( $settings, 'specs' ),
			'amenities' => self::yes_no( $settings, 'amenities' ),
			'trips'     => self::yes_no( $settings, 'trips' ),
		);
	}
}

/**
 * `[kaiki_search]` — the search bar.
 */
final class Search_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-search';
	protected const TAG  = 'kaiki_search';
	protected const ICON = 'eicon-search';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip search bar', 'kaiki-booking' );
	}

	/** The bar is about no single trip, so it has no «Trip id». */
	protected function register_controls(): void {
		$this->start_controls_section(
			'kaiki_content',
			array(
				'label' => __( 'Kaiki', 'kaiki-booking' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'results',
			array(
				'label'       => __( 'Results page', 'kaiki-booking' ),
				'type'        => Controls_Manager::URL,
				'description' => __( 'The page with the «Trip search results» widget. Empty: this same page.', 'kaiki-booking' ),
			)
		);
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'kaiki-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'inline',
				'options' => array(
					'inline'  => __( 'In one row', 'kaiki-booking' ),
					'stacked' => __( 'One field under the other', 'kaiki-booking' ),
				),
			)
		);
		$this->add_control(
			'button',
			array(
				'label'   => __( 'Button text', 'kaiki-booking' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'Search', 'kaiki-booking' ),
			)
		);
		$this->switch( 'filters', __( 'Show type and port, if switched on in Kaiki', 'kaiki-booking' ) );
		$this->end_controls_section();

		$this->style_section( 'style_bar', __( 'Bar', 'kaiki-booking' ) );
		$this->colour( 'bar_background', __( 'Background', 'kaiki-booking' ), '.kaiki-search', 'background-color' );
		$this->pixels( 'bar_radius', __( 'Corner radius', 'kaiki-booking' ), '.kaiki-search', 'border-radius', 40 );
		$this->add_responsive_control(
			'bar_padding',
			array(
				'label'      => __( 'Padding', 'kaiki-booking' ),
				'type'       => Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array( '{{WRAPPER}} .kaiki-search' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_group_control(
			Group_Control_Box_Shadow::get_type(),
			array(
				'name'     => 'bar_shadow',
				'selector' => '{{WRAPPER}} .kaiki-search',
			)
		);
		$this->colour( 'label_color', __( 'Label colour', 'kaiki-booking' ), '.kaiki-search__label' );
		$this->colour( 'field_background', __( 'Field background', 'kaiki-booking' ), '.kaiki-search input, {{WRAPPER}} .kaiki-search select', 'background-color' );
		$this->pixels( 'field_radius', __( 'Field corner radius', 'kaiki-booking' ), '.kaiki-search input, {{WRAPPER}} .kaiki-search select, {{WRAPPER}} .kaiki-search__button', 'border-radius', 30 );
		$this->end_controls_section();

		$this->style_section( 'style_button', __( 'Button', 'kaiki-booking' ) );
		$this->colour( 'button_background', __( 'Background', 'kaiki-booking' ), '.kaiki-search__button', 'background-color' );
		$this->colour( 'button_color', __( 'Text colour', 'kaiki-booking' ), '.kaiki-search__button' );
		$this->colour( 'button_hover_background', __( 'Hover background', 'kaiki-booking' ), '.kaiki-search__button:hover', 'background-color' );
		$this->typography( 'button_typography', __( 'Typography', 'kaiki-booking' ), '.kaiki-search__button' );
		$this->end_controls_section();
	}

	/** Print the bar. */
	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$settings = is_array( $settings ) ? $settings : array();

		$html = \Kaiki\Booking\Trip\Search::bar(
			array(
				'results' => (string) ( $settings['results']['url'] ?? '' ),
				'layout'  => (string) ( $settings['layout'] ?? 'inline' ),
				'button'  => (string) ( $settings['button'] ?? '' ),
				'filters' => self::yes_no( $settings, 'filters' ),
			)
		);

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the shortcode.
	}
}

/**
 * `[kaiki_search_results]` — the trips for the searched day and party.
 */
final class Search_Results_Widget extends Trip_Widget {
	protected const SLUG = 'kaiki-search-results';
	protected const TAG  = 'kaiki_search_results';
	protected const ICON = 'eicon-archive-posts';

	/** Panel title. */
	public function get_title(): string {
		return __( 'Trip search results', 'kaiki-booking' );
	}

	/** Controls: the trip cards' own display options. */
	protected function register_controls(): void {
		$this->start_controls_section(
			'kaiki_content',
			array(
				'label' => __( 'Kaiki', 'kaiki-booking' ),
				'tab'   => Controls_Manager::TAB_CONTENT,
			)
		);
		$this->add_control(
			'note',
			array(
				'type' => Controls_Manager::RAW_HTML,
				'raw'  => esc_html__( 'Before anyone searches, this shows every trip. After a search: the trips for that date and party, with the price for the party.', 'kaiki-booking' ),
			)
		);
		$this->add_control(
			'layout',
			array(
				'label'   => __( 'Layout', 'kaiki-booking' ),
				'type'    => Controls_Manager::SELECT,
				'default' => 'cards',
				'options' => array(
					'cards'      => __( 'Cards: photo above', 'kaiki-booking' ),
					'horizontal' => __( 'Horizontal: photo beside', 'kaiki-booking' ),
					'overlay'    => __( 'Overlay: text over the photo', 'kaiki-booking' ),
					'minimal'    => __( 'Minimal: no photo', 'kaiki-booking' ),
				),
			)
		);
		$this->add_responsive_control(
			'columns',
			array(
				'label'          => __( 'Columns', 'kaiki-booking' ),
				'type'           => Controls_Manager::NUMBER,
				'min'            => 1,
				'max'            => 3,
				'default'        => 3,
				'tablet_default' => 2,
				'mobile_default' => 1,
				'selectors'      => array( '{{WRAPPER}} .kaiki-trips' => 'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));' ),
			)
		);
		$this->switch( 'summary', __( 'Show the summary', 'kaiki-booking' ) );
		$this->switch( 'facts', __( 'Show duration, time and boat', 'kaiki-booking' ) );
		$this->add_control(
			'cta',
			array(
				'label'   => __( 'Card link text', 'kaiki-booking' ),
				'type'    => Controls_Manager::TEXT,
				'default' => __( 'View & book →', 'kaiki-booking' ),
			)
		);
		$this->end_controls_section();

		$this->style_section( 'style_text', __( 'Card text', 'kaiki-booking' ) );
		$this->colour( 'summary_line_color', __( 'Result count colour', 'kaiki-booking' ), '.kaiki-search-summary' );
		$this->colour( 'title_color', __( 'Title colour', 'kaiki-booking' ), '.kaiki-trip-card__title' );
		$this->colour( 'price_color', __( 'Price colour', 'kaiki-booking' ), '.kaiki-trip-card .kaiki-trip-price__amount' );
		$this->colour( 'cta_color', __( 'Link colour', 'kaiki-booking' ), '.kaiki-trip-card__cta' );
		$this->end_controls_section();
	}

	/**
	 * Attributes.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, string>
	 */
	protected function attributes( array $settings ): array {
		return array(
			'layout'  => (string) ( $settings['layout'] ?? 'cards' ),
			'summary' => self::yes_no( $settings, 'summary' ),
			'facts'   => self::yes_no( $settings, 'facts' ),
			'cta'     => (string) ( $settings['cta'] ?? '' ),
		);
	}

	/** Print the results. */
	protected function render(): void {
		$settings = $this->get_settings_for_display();

		self::emit( \Kaiki\Booking\Trip\Search::results( array_filter( $this->attributes( is_array( $settings ) ? $settings : array() ), 'strlen' ) ) );
	}
}
