<?php
/**
 * The trip page as an Elementor template the plugin creates by itself.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Elementor;

use Kaiki\Booking\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A ready trip template, so an operator on Elementor starts from a finished
 * page instead of an empty one.
 *
 * ## Created once, then theirs
 *
 * The first time an administrator loads the dashboard with Elementor active
 * and trip pages switched on, the plugin saves «Trip page (Kaiki)» under
 * Templates and remembers its id. It is an ordinary Elementor template: the
 * operator edits it, and the plugin never writes to it again. Deleting it
 * brings a fresh one back on the next dashboard load — which is also how an
 * operator starts over.
 *
 * ## Rendered for every trip
 *
 * Elementor Free has no Theme Builder, so {@see \Kaiki\Booking\Seo\TripPostType::template()}
 * renders this template around each trip page. Every widget in it leaves
 * «Trip id» empty and reads the trip being viewed. A theme's own
 * `kaiki/single-trip.php` still wins, as before.
 *
 * ## Neutral on purpose
 *
 * No colours or fonts are set: the template takes the site's Elementor kit,
 * and the plugin's stylesheet supplies a quiet starting look. Layout (columns,
 * spacing, the sticky booking card) is what it ships.
 */
final class TripTemplate {

	/** Option holding the template's post id. */
	public const OPTION = 'kaiki_elementor_trip_template';

	/** Hook in. */
	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'ensure' ) );
	}

	/**
	 * Create the template when there is none to use.
	 */
	public static function ensure(): void {
		if ( ! Settings::seo_pages_enabled() || ! current_user_can( 'manage_options' ) || ! self::elementor_ready() || self::id() > 0 ) {
			return;
		}

		self::create();
	}

	/**
	 * The template's id, if it still exists and is published.
	 */
	public static function id(): int {
		$id = (int) get_option( self::OPTION );

		return $id > 0 && 'publish' === get_post_status( $id ) && 'elementor_library' === get_post_type( $id ) ? $id : 0;
	}

	/**
	 * The rendered template for the trip in the loop, or '' when Elementor
	 * or the template is not there.
	 */
	public static function render(): string {
		$id = self::id();

		if ( 0 === $id || ! self::elementor_ready() ) {
			return '';
		}

		// With WPML or Polylang, the template's translation for the page's
		// language: the operator translates the template's own words (its
		// headings) once, and the Kaiki widgets inside it already speak the
		// visitor's language. Falls back to the original where there is none.
		$id = self::translated( $id );

		// Without Elementor's element cache for this one render. With it, a
		// container is cached around a placeholder for each Kaiki widget and the
		// widgets are drawn afterwards — so a section cannot know its pieces came
		// out empty ({@see EmptySections}), and the cache saves nothing here
		// anyway: every piece differs on every trip page.
		$no_cache = static fn (): string => 'disable';

		add_filter( 'pre_option_elementor_element_cache_ttl', $no_cache );

		try {
			return (string) \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $id, true );
		} finally {
			remove_filter( 'pre_option_elementor_element_cache_ttl', $no_cache );
		}
	}

	/**
	 * The template in the current language, when a translation plugin has one.
	 *
	 * @param int $id The template.
	 */
	public static function translated( int $id ): int {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
		$translated = (int) apply_filters( 'wpml_object_id', $id, 'elementor_library', true );

		if ( $translated <= 0 && function_exists( 'pll_get_post' ) ) {
			$translated = (int) pll_get_post( $id );
		}

		return $translated > 0 && 'publish' === get_post_status( $translated ) ? $translated : $id;
	}

	/**
	 * Save a new template and remember it.
	 */
	public static function create(): int {
		$document = \Elementor\Plugin::instance()->documents->create(
			'page',
			array(
				'post_title'  => __( 'Trip page (Kaiki)', 'kaiki-booking' ),
				'post_status' => 'publish',
			)
		);

		if ( ! is_object( $document ) || ! method_exists( $document, 'save' ) ) {
			return 0;
		}

		$document->save( array( 'elements' => self::elements() ) );

		$id = (int) $document->get_main_id();

		update_option( self::OPTION, $id );

		return $id;
	}

	/**
	 * Elementor is loaded far enough to create and render documents.
	 */
	private static function elementor_ready(): bool {
		return class_exists( '\Elementor\Plugin' ) && did_action( 'elementor/loaded' ) && isset( \Elementor\Plugin::instance()->documents );
	}

	/**
	 * The template's content, in Elementor's saved-data shape. Pure data, so
	 * it is testable without Elementor.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function elements(): array {
		$trips = array(
			'limit'           => 3,
			'exclude_current' => 'yes',
			'summary'         => 'yes',
			'facts'           => 'yes',
			'cta'             => __( 'View & book →', 'kaiki-booking' ),
			'columns'         => 3,
			'columns_tablet'  => 2,
			'columns_mobile'  => 1,
		);

		return array(
			self::container(
				array( 'padding' => self::box( 24, 20, 32, 20 ) ),
				array(
					self::widget(
						'kaiki-trip-title',
						array(
							'tag'       => 'h1',
							'summary'   => 'yes',
							'back'      => 'yes',
							'back_text' => __( '← All trips', 'kaiki-booking' ),
						)
					),
					self::widget( 'kaiki-trip-facts', array( 'show' => array( 'category', 'duration', 'departure', 'meeting_point', 'vessel', 'capacity' ) ) ),
					self::widget( 'kaiki-trip-gallery', array( 'layout' => 'mosaic' ) ),
				)
			),
			self::container(
				array( 'padding' => self::box( 8, 20, 72, 20 ) ),
				array(
					self::container(
						array(
							'flex_direction'        => 'row',
							'flex_direction_tablet' => 'column',
							'flex_wrap'             => 'nowrap',
							'flex_gap'              => self::gap( 48 ),
							'flex_align_items'      => 'flex-start',
						),
						array(
							self::container(
								array(
									'width'        => self::percent( 62 ),
									'width_tablet' => self::percent( 100 ),
									'flex_gap'     => self::gap( 0 ),
								),
								array(
									self::section( __( 'The trip', 'kaiki-booking' ), self::widget( 'kaiki-trip-description', array() ) ),
									self::section( __( 'Highlights', 'kaiki-booking' ), self::widget( 'kaiki-trip-list', array( 'source' => 'highlights' ) ) ),
									self::section( __( 'Itinerary', 'kaiki-booking' ), self::widget( 'kaiki-trip-itinerary', array() ) ),
									self::section( __( 'What is included', 'kaiki-booking' ), self::widget( 'kaiki-trip-list', array( 'source' => 'includes' ) ) ),
									self::section( __( 'What is not included', 'kaiki-booking' ), self::widget( 'kaiki-trip-list', array( 'source' => 'excludes' ) ) ),
									self::section( __( 'What to bring', 'kaiki-booking' ), self::widget( 'kaiki-trip-list', array( 'source' => 'bring' ) ) ),
									self::section(
										__( 'The boat', 'kaiki-booking' ),
										self::widget(
											'kaiki-trip-field',
											array(
												'key'      => 'vessel.name',
												'html_tag' => 'h3',
											)
										),
										self::widget(
											'kaiki-trip-field',
											array(
												'key'    => 'vessel.description',
												'format' => 'html',
											)
										)
									),
									self::section( __( 'Meeting point', 'kaiki-booking' ), self::widget( 'kaiki-trip-meeting-point', array() ) ),
									self::section( __( 'Cancellation policy', 'kaiki-booking' ), self::widget( 'kaiki-trip-cancellation', array() ) ),
								),
								true
							),
							self::container(
								array(
									'width'           => self::percent( 38 ),
									'width_tablet'    => self::percent( 100 ),
									'css_classes'     => 'kaiki-trip-aside',
									'flex_align_self' => 'flex-start',
								),
								array(
									self::container(
										array(
											'css_classes'  => 'kaiki-trip-book',
											'flex_gap'     => self::gap( 14 ),
											'padding'      => self::box( 24, 24, 24, 24 ),
											'border_border' => 'solid',
											'border_width' => self::box( 1, 1, 1, 1 ),
											'border_color' => '#E5E7EB',
											'border_radius' => self::box( 16, 16, 16, 16 ),
										),
										array(
											self::widget(
												'kaiki-trip-price',
												array(
													'prefix' => __( 'from', 'kaiki-booking' ),
													'unit' => 'yes',
													'_css_classes' => 'kaiki-only-booking',
												)
											),
											self::widget( 'kaiki-booking', array( 'product' => '' ) ),
										),
										true
									),
								),
								true
							),
						),
						true
					),
				)
			),
			self::container(
				array(
					'padding'               => self::box( 64, 20, 80, 20 ),
					'background_background' => 'classic',
					'background_color'      => '#F6F7F9',
				),
				array(
					self::widget(
						'heading',
						array(
							'title'       => __( 'You may also like', 'kaiki-booking' ),
							'header_size' => 'h2',
						)
					),
					self::widget( 'kaiki-trips', $trips ),
				)
			),
		);
	}

	/**
	 * A heading and its piece, in a container that hides itself when the
	 * piece has nothing to show (see `trip.css`).
	 *
	 * @param string               $title     The heading.
	 * @param array<string, mixed> ...$pieces The widgets.
	 * @return array<string, mixed>
	 */
	private static function section( string $title, array ...$pieces ): array {
		return self::container(
			array(
				'css_classes' => 'kaiki-trip-section',
				'flex_gap'    => self::gap( 14 ),
				'padding'     => self::box( 24, 0, 24, 0 ),
			),
			array_merge(
				array(
					self::widget(
						'heading',
						array(
							'title'       => $title,
							'header_size' => 'h2',
						)
					),
				),
				$pieces
			),
			true
		);
	}

	/**
	 * A container.
	 *
	 * @param array<string, mixed>       $settings Its settings.
	 * @param list<array<string, mixed>> $children Its elements.
	 * @param bool                       $inner    Nested.
	 * @return array<string, mixed>
	 */
	private static function container( array $settings, array $children, bool $inner = false ): array {
		return array(
			'id'       => self::id_for( $settings, $children ),
			'elType'   => 'container',
			'isInner'  => $inner,
			'settings' => $settings + array(
				'content_width'  => $inner ? 'full' : 'boxed',
				'flex_direction' => 'column',
			),
			'elements' => $children,
		);
	}

	/**
	 * A widget.
	 *
	 * @param string               $type     Widget name.
	 * @param array<string, mixed> $settings Its settings.
	 * @return array<string, mixed>
	 */
	private static function widget( string $type, array $settings ): array {
		return array(
			'id'         => self::id_for( $settings, $type ),
			'elType'     => 'widget',
			'widgetType' => $type,
			'isInner'    => false,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * A seven-character element id, unique within the template.
	 *
	 * @param mixed ...$seed Anything that tells elements apart.
	 */
	private static function id_for( ...$seed ): string {
		static $count = 0;

		++$count;

		return substr( md5( (string) wp_json_encode( $seed ) . $count ), 0, 7 );
	}

	/**
	 * Padding or a border width.
	 *
	 * @param int $top    Top.
	 * @param int $right  Right.
	 * @param int $bottom Bottom.
	 * @param int $left   Left.
	 * @return array<string, mixed>
	 */
	private static function box( int $top, int $right, int $bottom, int $left ): array {
		return array(
			'unit'     => 'px',
			'top'      => (string) $top,
			'right'    => (string) $right,
			'bottom'   => (string) $bottom,
			'left'     => (string) $left,
			'isLinked' => false,
		);
	}

	/**
	 * A flex gap.
	 *
	 * @param int $size Pixels.
	 * @return array<string, mixed>
	 */
	private static function gap( int $size ): array {
		return array(
			'unit'   => 'px',
			'size'   => $size,
			'column' => (string) $size,
			'row'    => (string) $size,
		);
	}

	/**
	 * A width.
	 *
	 * @param int $size Percent.
	 * @return array<string, mixed>
	 */
	private static function percent( int $size ): array {
		return array(
			'unit' => '%',
			'size' => $size,
		);
	}
}
