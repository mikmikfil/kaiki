<?php
/**
 * The trip shortcodes: the pieces of a trip page.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Trip;

use const Kaiki\Booking\FILE;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a trip page shows besides the booking form, one shortcode each.
 *
 * Built so an operator can lay out a trip page in any builder — an Elementor
 * template, a block theme, a theme file — and have it fill itself in for
 * whichever trip is being viewed. Each takes an optional `product` and
 * otherwise reads the trip from the page ({@see Trip::uuid()}).
 *
 * ## Markup, not an embed
 *
 * Unlike the booking form these are plain HTML on the operator's own page, so
 * search engines read them and the theme can style them. Every class is
 * `kaiki-trip-*` and the stylesheet that comes with them touches nothing
 * else (WPP-2): a starting look, meant to be overridden, loaded only on a
 * page that uses one.
 *
 * ## Silence for visitors, a hint for editors
 *
 * A piece with nothing to show — no meeting point, no photos — renders
 * nothing for a visitor, and says what it is waiting for to someone who can
 * edit the page.
 */
final class TripShortcodes {

	/**
	 * Shortcode tag => callback.
	 *
	 * @var array<string, string>
	 */
	public const TAGS = array(
		'kaiki_trip_title'         => 'title',
		'kaiki_trip_facts'         => 'facts',
		'kaiki_trip_gallery'       => 'gallery',
		'kaiki_trip_description'   => 'description',
		'kaiki_trip_price'         => 'price',
		'kaiki_trip_list'          => 'item_list',
		'kaiki_trip_itinerary'     => 'itinerary',
		'kaiki_trip_meeting_point' => 'meeting_point',
		'kaiki_trip_cancellation'  => 'cancellation',
		'kaiki_trips'              => 'trips',
		'kaiki_trip_field'         => 'field',
		'kaiki_fleet'              => 'fleet',
	);

	/**
	 * The facts `[kaiki_trip_facts]` can show, in the order it shows them.
	 */
	public const FACTS = array( 'category', 'duration', 'departure', 'meeting_point', 'vessel', 'capacity' );

	/**
	 * How `[kaiki_trips]` can lay its cards out. The first is the default.
	 *
	 * Cards: photo above, text below. Horizontal: photo beside the text.
	 * Overlay: text over the photo. Minimal: no photo, text only.
	 */
	public const TRIP_LAYOUTS = array( 'cards', 'horizontal', 'overlay', 'minimal' );

	/**
	 * How `[kaiki_fleet]` can lay its boats out. The first is the default.
	 *
	 * Rows: photo beside, alternating sides. Rows-left: photo always left.
	 * Grid: cards. Overlay: name and type over the photo. Compact: a small
	 * photo, a line of numbers, no amenities list.
	 */
	public const FLEET_LAYOUTS = array( 'rows', 'rows-left', 'grid', 'overlay', 'compact' );

	/**
	 * A layout attribute, held to its list.
	 *
	 * @param string             $value   As typed.
	 * @param array<int, string> $allowed The layouts, default first.
	 */
	public static function layout( string $value, array $allowed ): string {
		$value = strtolower( trim( $value ) );

		return in_array( $value, $allowed, true ) ? $value : $allowed[0];
	}

	/**
	 * Register the shortcodes and the stylesheet they bring.
	 */
	public static function register(): void {
		foreach ( self::TAGS as $tag => $callback ) {
			add_shortcode( $tag, array( self::class, $callback ) );
		}

		add_action( 'wp_enqueue_scripts', array( self::class, 'register_style' ), 5 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_on_trip_pages' ) );

		// Elementor registers and enqueues on hooks of its own; the editor's
		// preview frame is a page where none of the above has decided anything.
		add_action( 'elementor/frontend/after_register_styles', array( self::class, 'register_style' ) );
		add_action( 'elementor/preview/enqueue_styles', array( self::class, 'enqueue_in_editor' ) );
		add_filter( 'body_class', array( self::class, 'editor_body_class' ) );
	}

	/**
	 * The stylesheet inside Elementor's preview frame.
	 */
	public static function enqueue_in_editor(): void {
		self::register_style();
		wp_enqueue_style( 'kaiki-trip' );
	}

	/**
	 * In a builder's preview, the class a trip page would carry for the trip
	 * being previewed, so «booking only» and «request only» parts of a
	 * template show as they will on that trip instead of both at once.
	 *
	 * @param array<int, string> $classes The body's classes.
	 * @return array<int, string>
	 */
	public static function editor_body_class( array $classes ): array {
		if ( ! Trip::in_editor() || in_array( 'kaiki-trip--booking', $classes, true ) || in_array( 'kaiki-trip--request', $classes, true ) ) {
			return $classes;
		}

		$product = Trip::product( Trip::uuid() );

		if ( $product ) {
			$classes[] = 'kaiki-trip-preview';
			$classes[] = 'quote' === ( $product['mode'] ?? '' ) ? 'kaiki-trip--request' : 'kaiki-trip--booking';
		}

		return $classes;
	}

	/**
	 * Registered everywhere, enqueued only where used.
	 */
	public static function register_style(): void {
		if ( wp_style_is( 'kaiki-trip', 'registered' ) ) {
			return;
		}

		wp_register_style( 'kaiki-trip', plugins_url( 'assets/trip.css', FILE ), array(), (string) filemtime( dirname( FILE ) . '/assets/trip.css' ) );
	}

	/**
	 * In the head on trip pages, so they do not flash unstyled; anywhere else
	 * a shortcode enqueues it as it renders and WordPress prints it late.
	 */
	public static function enqueue_on_trip_pages(): void {
		if ( is_singular( \Kaiki\Booking\Seo\TripPostType::POST_TYPE ) ) {
			wp_enqueue_style( 'kaiki-trip' );
		}
	}

	/**
	 * `[kaiki_trip_title tag="h1" back="yes" summary="yes"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function title( $atts = array() ): string {
		$atts    = self::atts(
			$atts,
			array(
				'tag'       => 'h1',
				'summary'   => 'yes',
				'back'      => 'no',
				'back_text' => __( '← All trips', 'kaiki-booking' ),
				'back_url'  => '',
			),
			'kaiki_trip_title'
		);
		$product = self::product( $atts );

		if ( ! $product ) {
			return self::empty_piece( __( 'Trip title', 'kaiki-booking' ) );
		}

		$post_id = Trip::post_id( (string) $product['uuid'] );
		$title   = $post_id ? get_the_title( $post_id ) : (string) ( $product['title'] ?? '' );
		$summary = $post_id && has_excerpt( $post_id ) ? get_the_excerpt( $post_id ) : (string) ( $product['summary'] ?? '' );
		$tag     = in_array( $atts['tag'], array( 'h1', 'h2', 'h3', 'h4', 'p', 'div' ), true ) ? $atts['tag'] : 'h1';
		$back    = '' !== $atts['back_url'] ? $atts['back_url'] : home_url( '/' );

		$html  = '<div class="kaiki-trip-title">';
		$html .= self::yes( $atts['back'] ) ? sprintf( '<a class="kaiki-trip-title__back" href="%s">%s</a>', esc_url( $back ), esc_html( $atts['back_text'] ) ) : '';
		$html .= sprintf( '<%1$s class="kaiki-trip-title__heading">%2$s</%1$s>', $tag, esc_html( $title ) );
		$html .= self::yes( $atts['summary'] ) && '' !== $summary ? '<p class="kaiki-trip-title__summary">' . esc_html( $summary ) . '</p>' : '';

		return self::wrap( $html . '</div>' );
	}

	/**
	 * `[kaiki_trip_facts show="duration,departure,meeting_point,vessel,capacity"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function facts( $atts = array() ): string {
		$atts    = self::atts( $atts, array( 'show' => 'duration,departure,meeting_point,vessel,capacity' ), 'kaiki_trip_facts' );
		$product = self::product( $atts );

		if ( ! $product ) {
			return self::empty_piece( __( 'Trip facts', 'kaiki-booking' ) );
		}

		$chips = array();

		foreach ( self::fact_keys( $atts['show'] ) as $fact ) {
			$label = self::fact( $product, $fact );

			if ( '' !== $label ) {
				$chips[] = sprintf(
					'<li class="kaiki-trip-facts__item kaiki-trip-facts__item--%s">%s<span>%s</span></li>',
					esc_attr( $fact ),
					Icons::svg( $fact ),
					esc_html( $label )
				);
			}
		}

		if ( ! $chips ) {
			return self::empty_piece( __( 'Trip facts', 'kaiki-booking' ) );
		}

		return self::wrap( '<ul class="kaiki-trip-facts">' . implode( '', $chips ) . '</ul>' );
	}

	/**
	 * `[kaiki_trip_gallery layout="mosaic|grid|single" max="5"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function gallery( $atts = array() ): string {
		$atts    = self::atts(
			$atts,
			array(
				'layout' => 'mosaic',
				'max'    => '5',
			),
			'kaiki_trip_gallery'
		);
		$product = self::product( $atts );
		$images  = array();

		foreach ( (array) ( $product['images'] ?? array() ) as $image ) {
			if ( is_array( $image ) && ! empty( $image['url'] ) ) {
				$images[] = array(
					'url' => (string) $image['url'],
					'alt' => (string) ( $image['alt'] ?? '' ),
				);
			}
		}

		if ( ! $images && ! empty( $product['hero_image_url'] ) ) {
			$images[] = array(
				'url' => (string) $product['hero_image_url'],
				'alt' => '',
			);
		}

		if ( ! $images ) {
			return self::empty_piece( __( 'Trip photos', 'kaiki-booking' ) );
		}

		$layout = in_array( $atts['layout'], array( 'mosaic', 'grid', 'single' ), true ) ? $atts['layout'] : 'mosaic';
		$all    = $images;
		$images = array_slice( $images, 0, self::gallery_count( $layout, count( $images ), (int) $atts['max'] ) );
		$hidden = count( $all ) - count( $images );

		/*
		 * Every photo opens in a lightbox, and the slideshow holds all of the
		 * trip's photos, not only the tiles. Elementor's own lightbox, through
		 * its data attributes: on a page Elementor draws it is already loaded,
		 * with arrows, swipe and full screen, so nothing new is shipped. Without
		 * Elementor the same links simply open the photo.
		 */
		$slideshow = 'kaiki-' . substr( md5( (string) ( $product['uuid'] ?? wp_json_encode( $all ) ) ), 0, 10 );
		$link      = static fn ( array $image, string $inner, string $extra = '' ): string => sprintf(
			'<a class="kaiki-trip-gallery__link" href="%s" data-elementor-open-lightbox="yes" data-elementor-lightbox-slideshow="%s" data-elementor-lightbox-title="%s"%s>%s</a>',
			esc_url( $image['url'] ),
			esc_attr( $slideshow ),
			esc_attr( $image['alt'] ),
			$extra,
			$inner
		);

		$html = sprintf( '<div class="kaiki-trip-gallery kaiki-trip-gallery--%s kaiki-trip-gallery--n%d">', esc_attr( $layout ), count( $images ) );

		foreach ( $images as $n => $image ) {
			$inner = sprintf(
				'<img src="%s" alt="%s" %s decoding="async">',
				esc_url( $image['url'] ),
				esc_attr( $image['alt'] ),
				0 === $n ? 'fetchpriority="high"' : 'loading="lazy"'
			);

			if ( $hidden > 0 && count( $images ) - 1 === $n ) {
				/* translators: %d: how many more photos the lightbox holds. */
				$inner .= '<span class="kaiki-trip-gallery__more">' . esc_html( sprintf( __( '+%d photos', 'kaiki-booking' ), $hidden ) ) . '</span>';
			}

			$html .= '<figure class="kaiki-trip-gallery__item">' . $link( $image, $inner ) . '</figure>';
		}

		// The rest, for the slideshow only.
		foreach ( array_slice( $all, count( $images ) ) as $image ) {
			$html .= $link( $image, '', ' hidden aria-hidden="true" tabindex="-1"' );
		}

		return self::wrap( $html . '</div>' );
	}

	/**
	 * How many photos a gallery layout shows.
	 *
	 * The mosaic is one large photo beside two or four small ones; with three
	 * small ones a cell would stay empty, so four photos show as three.
	 *
	 * @param string $layout    mosaic|grid|single.
	 * @param int    $available Photos the trip has.
	 * @param int    $max       The `max` attribute.
	 */
	public static function gallery_count( string $layout, int $available, int $max ): int {
		$max = $max > 0 ? $max : 5;

		if ( 'single' === $layout ) {
			return min( 1, $available );
		}

		$count = min( $available, $max );

		if ( 'mosaic' === $layout ) {
			$count = min( $count, 5 );
			$count = 4 === $count ? 3 : $count;
		}

		return $count;
	}

	/**
	 * `[kaiki_trip_description]` — the trip's text from Kaiki, as paragraphs.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function description( $atts = array() ): string {
		$atts = self::atts( $atts, array(), 'kaiki_trip_description' );
		$text = trim( (string) ( self::product( $atts )['description'] ?? '' ) );

		if ( '' === $text ) {
			return self::empty_piece( __( 'Trip description', 'kaiki-booking' ) );
		}

		return self::wrap( '<div class="kaiki-trip-description">' . wpautop( esc_html( $text ) ) . '</div>' );
	}

	/**
	 * `[kaiki_trip_price prefix="from" unit="yes"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function price( $atts = array() ): string {
		$atts    = self::atts(
			$atts,
			array(
				'prefix' => __( 'from', 'kaiki-booking' ),
				'unit'   => 'yes',
			),
			'kaiki_trip_price'
		);
		$product = self::product( $atts );

		if ( ! $product ) {
			return self::empty_piece( __( 'Trip price', 'kaiki-booking' ) );
		}

		return self::wrap( self::price_html( $product, $atts['prefix'], self::yes( $atts['unit'] ) ) );
	}

	/**
	 * The price block, shared with the trip cards.
	 *
	 * @param array<string, mixed> $product   The trip.
	 * @param string               $prefix    «from».
	 * @param bool                 $with_unit Whether to add «/ person».
	 */
	public static function price_html( array $product, string $prefix, bool $with_unit ): string {
		if ( empty( $product['from_price_formatted'] ) ) {
			return '<div class="kaiki-trip-price kaiki-trip-price--quote"><span class="kaiki-trip-price__amount">' . esc_html__( 'On request', 'kaiki-booking' ) . '</span></div>';
		}

		$unit = 'per_seat' === ( $product['mode'] ?? '' ) ? __( '/ person', 'kaiki-booking' ) : __( '/ boat', 'kaiki-booking' );

		return sprintf(
			'<div class="kaiki-trip-price">%s<span class="kaiki-trip-price__amount">%s</span>%s</div>',
			'' !== $prefix ? '<span class="kaiki-trip-price__prefix">' . esc_html( $prefix ) . '</span>' : '',
			esc_html( (string) $product['from_price_formatted'] ),
			$with_unit ? '<span class="kaiki-trip-price__unit">' . esc_html( $unit ) . '</span>' : ''
		);
	}

	/**
	 * `[kaiki_trip_list source="includes|excludes|bring|highlights"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function item_list( $atts = array() ): string {
		$atts   = self::atts( $atts, array( 'source' => 'includes' ), 'kaiki_trip_list' );
		$source = in_array( $atts['source'], array( 'includes', 'excludes', 'bring', 'highlights' ), true ) ? $atts['source'] : 'includes';
		$items  = Trip::items( self::product( $atts ), $source );

		if ( ! $items ) {
			return self::empty_piece( __( 'A list about the trip (nothing entered for this trip yet)', 'kaiki-booking' ) );
		}

		$icon = array(
			'includes'   => 'check',
			'excludes'   => 'cross',
			'bring'      => 'bag',
			'highlights' => 'star',
		)[ $source ];

		$html = sprintf( '<ul class="kaiki-trip-list kaiki-trip-list--%s">', esc_attr( $source ) );

		foreach ( $items as $item ) {
			$html .= '<li class="kaiki-trip-list__item">' . Icons::svg( $icon ) . '<span>' . esc_html( $item ) . '</span></li>';
		}

		return self::wrap( $html . '</ul>' );
	}

	/**
	 * `[kaiki_trip_itinerary]` — the day, stop by stop.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function itinerary( $atts = array() ): string {
		$atts  = self::atts( $atts, array(), 'kaiki_trip_itinerary' );
		$steps = Trip::itinerary( self::product( $atts ) );

		if ( ! $steps ) {
			return self::empty_piece( __( 'Itinerary (nothing entered for this trip yet)', 'kaiki-booking' ) );
		}

		$html = '<ol class="kaiki-trip-itinerary">';

		foreach ( $steps as $step ) {
			$details = trim( (string) ( $step['description'] ?? '' ) );
			$length  = trim( (string) ( $step['duration'] ?? '' ) );

			$html .= sprintf(
				'<li class="kaiki-trip-itinerary__step"><span class="kaiki-trip-itinerary__time">%s</span><span class="kaiki-trip-itinerary__text">%s%s%s</span></li>',
				esc_html( (string) ( $step['time'] ?? '' ) ),
				esc_html( (string) ( $step['text'] ?? '' ) ),
				'' !== $length ? ' <span class="kaiki-trip-itinerary__duration">· ' . esc_html( $length ) . '</span>' : '',
				'' !== $details ? '<span class="kaiki-trip-itinerary__description">' . esc_html( $details ) . '</span>' : ''
			);
		}

		return self::wrap( $html . '</ol>' );
	}

	/**
	 * `[kaiki_trip_meeting_point instructions="yes" map="yes"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function meeting_point( $atts = array() ): string {
		$atts  = self::atts(
			$atts,
			array(
				'instructions' => 'yes',
				'map'          => 'yes',
				'map_text'     => __( 'Open in maps →', 'kaiki-booking' ),
			),
			'kaiki_trip_meeting_point'
		);
		$point = self::product( $atts )['meeting_point'] ?? null;

		if ( ! is_array( $point ) || empty( $point['name'] ) ) {
			return self::empty_piece( __( 'Meeting point', 'kaiki-booking' ) );
		}

		$html  = '<div class="kaiki-trip-meeting">' . Icons::svg( 'meeting_point' ) . '<div class="kaiki-trip-meeting__body">';
		$html .= '<strong class="kaiki-trip-meeting__name">' . esc_html( (string) $point['name'] ) . '</strong>';
		$html .= ! empty( $point['address'] ) ? '<span class="kaiki-trip-meeting__address">' . esc_html( (string) $point['address'] ) . '</span>' : '';
		$html .= self::yes( $atts['instructions'] ) && ! empty( $point['instructions'] ) ? '<p class="kaiki-trip-meeting__instructions">' . esc_html( (string) $point['instructions'] ) . '</p>' : '';
		$html .= self::yes( $atts['map'] ) && ! empty( $point['maps_url'] ) ? sprintf( '<a class="kaiki-trip-meeting__map" href="%s" target="_blank" rel="noopener">%s</a>', esc_url( (string) $point['maps_url'] ), esc_html( $atts['map_text'] ) ) : '';

		return self::wrap( $html . '</div></div>' );
	}

	/**
	 * `[kaiki_trip_cancellation tiers="yes"]`
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function cancellation( $atts = array() ): string {
		$atts   = self::atts( $atts, array( 'tiers' => 'yes' ), 'kaiki_trip_cancellation' );
		$policy = self::product( $atts )['cancellation_policy'] ?? null;

		if ( ! is_array( $policy ) ) {
			return self::empty_piece( __( 'Cancellation policy', 'kaiki-booking' ) );
		}

		$html  = '<div class="kaiki-trip-cancellation">';
		$html .= ! empty( $policy['summary'] ) ? '<p class="kaiki-trip-cancellation__summary">' . esc_html( (string) $policy['summary'] ) . '</p>' : '';

		if ( self::yes( $atts['tiers'] ) && ! empty( $policy['tiers'] ) && is_array( $policy['tiers'] ) ) {
			$html .= '<ul class="kaiki-trip-cancellation__tiers">';

			foreach ( $policy['tiers'] as $tier ) {
				$percent = (int) ( $tier['refund_percent'] ?? 0 );
				$html   .= sprintf(
					'<li class="kaiki-trip-cancellation__tier"><span>%s</span><span class="kaiki-trip-cancellation__refund%s">%s</span></li>',
					/* translators: %d: days before departure. */
					esc_html( sprintf( __( 'Up to %d days before', 'kaiki-booking' ), (int) ( $tier['days_before'] ?? 0 ) ) ),
					$percent > 0 ? '' : ' is-none',
					/* translators: %d: a percentage. */
					esc_html( $percent > 0 ? sprintf( __( '%d%% refund', 'kaiki-booking' ), $percent ) : __( 'No refund', 'kaiki-booking' ) )
				);
			}

			$html .= '</ul>';
		}

		return self::wrap( $html . '</div>' );
	}

	/**
	 * `[kaiki_trips limit="6" category="sunset,shared_half_day" exclude_current="yes" cta="…"]`
	 *
	 * Cards that open the trip's page on this site when it has one — unlike
	 * `[kaiki_list]`, whose cards open Kaiki's booking page.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function trips( $atts = array() ): string {
		$atts = self::atts(
			$atts,
			array(
				'limit'           => '0',
				'category'        => '',
				'exclude_current' => 'no',
				'cta'             => __( 'View & book →', 'kaiki-booking' ),
				'summary'         => 'yes',
				'facts'           => 'yes',
				'layout'          => 'cards',
				'badge'           => 'yes',
				'price'           => 'yes',
				'photo'           => 'yes',
			),
			'kaiki_trips'
		);

		$current  = self::yes( $atts['exclude_current'] ) ? Trip::uuid() : '';
		$products = self::select_trips( Trip::products(), array_filter( array_map( 'trim', explode( ',', $atts['category'] ) ) ), $current, (int) $atts['limit'] );

		if ( ! $products ) {
			return self::empty_piece( __( 'Trip cards (no trips match)', 'kaiki-booking' ) );
		}

		$html = '<div class="kaiki-trips kaiki-trips--' . self::layout( $atts['layout'], self::TRIP_LAYOUTS ) . '">';

		foreach ( $products as $product ) {
			$html .= self::card( $product, $atts );
		}

		return self::wrap( $html . '</div>' );
	}

	/**
	 * Which trips a card grid shows: by category, without the current trip,
	 * featured first, then Kaiki's order, then the limit.
	 *
	 * @param list<array<string, mixed>> $products   Kaiki's list.
	 * @param array<int, string>         $categories Wanted categories, or none for all.
	 * @param string                     $exclude    A uuid to leave out.
	 * @param int                        $limit      0 for all.
	 * @return list<array<string, mixed>>
	 */
	public static function select_trips( array $products, array $categories, string $exclude, int $limit ): array {
		$selected = array();

		foreach ( $products as $position => $product ) {
			if ( ( '' !== $exclude && ( $product['uuid'] ?? '' ) === $exclude )
				|| ( $categories && ! in_array( (string) ( $product['category'] ?? '' ), $categories, true ) ) ) {
				continue;
			}

			$selected[] = array( empty( $product['is_featured'] ) ? 1 : 0, (int) ( $product['sort_order'] ?? 0 ), $position, $product );
		}

		sort( $selected );
		$selected = array_column( $selected, 3 );

		return $limit > 0 ? array_slice( $selected, 0, $limit ) : $selected;
	}

	/**
	 * One trip card.
	 *
	 * @param array<string, mixed>  $product   The trip.
	 * @param array<string, string> $atts      The grid's attributes.
	 * @param array<string, string> $overrides `url`, `price_html` or `departure`, for a search result.
	 */
	public static function card( array $product, array $atts, array $overrides = array() ): string {
		$url = (string) ( $overrides['url'] ?? Trip::url( $product ) );

		// The operator's own label from Kaiki («Popular»), else the type of trip.
		$label = ! empty( $product['badge'] ) ? (string) $product['badge'] : Trip::category( (string) ( $product['category'] ?? '' ) );
		$badge = (string) apply_filters( 'kaiki_trip_badge', $label, Trip::post_id( (string) ( $product['uuid'] ?? '' ) ), $product );

		$facts = '';

		if ( self::yes( $atts['facts'] ) ) {
			foreach ( array( 'duration', 'departure', 'vessel' ) as $fact ) {
				$label  = self::fact( $product, $fact, true );
				$facts .= '' !== $label ? '<span class="kaiki-trip-card__fact">' . Icons::svg( $fact ) . esc_html( $label ) . '</span>' : '';
			}
		}

		return sprintf(
			'<a class="kaiki-trip-card" href="%1$s"><div class="kaiki-trip-card__media">%2$s%3$s</div><div class="kaiki-trip-card__body"><h3 class="kaiki-trip-card__title">%4$s</h3>%5$s%6$s<div class="kaiki-trip-card__foot">%7$s<span class="kaiki-trip-card__cta">%8$s</span></div></div></a>',
			esc_url( $url ),
			self::yes( $atts['photo'] ) && ! empty( $product['hero_image_url'] ) ? '<img src="' . esc_url( (string) $product['hero_image_url'] ) . '" alt="" loading="lazy" decoding="async">' : '',
			self::yes( $atts['badge'] ) && '' !== $badge ? '<span class="kaiki-trip-card__badge">' . esc_html( $badge ) . '</span>' : '',
			esc_html( (string) ( $product['title'] ?? '' ) ),
			self::yes( $atts['summary'] ) && ! empty( $product['summary'] ) ? '<p class="kaiki-trip-card__summary">' . esc_html( (string) $product['summary'] ) . '</p>' : '',
			( '' !== $facts ? '<div class="kaiki-trip-card__facts">' . $facts . '</div>' : '' ) . (string) ( $overrides['departure'] ?? '' ),
			self::yes( $atts['price'] ) ? (string) ( $overrides['price_html'] ?? self::price_html( $product, __( 'from', 'kaiki-booking' ), true ) ) : '<span></span>',
			esc_html( $atts['cta'] )
		);
	}

	/**
	 * One fact about a trip as a visitor reads it, or ''.
	 *
	 * @param array<string, mixed> $product The trip.
	 * @param string               $fact    One of {@see FACTS}.
	 * @param bool                 $short   The card's shorter wording.
	 */
	public static function fact( array $product, string $fact, bool $short = false ): string {
		switch ( $fact ) {
			case 'category':
				return Trip::category( (string) ( $product['category'] ?? '' ) );
			case 'duration':
				return Trip::duration( (int) ( $product['duration_minutes'] ?? 0 ) );
			case 'departure':
				$time = (string) ( $product['default_start_time'] ?? '' );
				/* translators: %s: a departure time such as 09:30. */
				return '' === $time ? '' : ( $short ? $time : sprintf( __( 'Departs %s', 'kaiki-booking' ), $time ) );
			case 'meeting_point':
				return (string) ( $product['meeting_point']['name'] ?? '' );
			case 'vessel':
				return (string) ( $product['vessel']['name'] ?? '' );
			case 'capacity':
				$max = (int) ( $product['max_pax'] ?? 0 );
				/* translators: %d: the most guests a trip takes. */
				return $max > 0 ? sprintf( __( 'Up to %d guests', 'kaiki-booking' ), $max ) : '';
		}

		return '';
	}

	/**
	 * The `show` attribute as known fact keys, in the order given.
	 *
	 * @param string $show Comma-separated keys.
	 * @return list<string>
	 */
	public static function fact_keys( string $show ): array {
		$keys = array_map( 'trim', explode( ',', strtolower( $show ) ) );

		return array_values( array_unique( array_intersect( $keys, self::FACTS ) ) );
	}

	/**
	 * `[kaiki_trip_field key="vessel.name" format="text|list|html|url|image" before="" after=""]`
	 *
	 * Any value in Kaiki's record of the trip, by its path — so everything
	 * Kaiki knows can go into a template without being typed again, including
	 * values no other piece shows (`booking_window.max_advance_days`,
	 * `vessel.specs.engine`, `age_bands`). Two computed keys as well:
	 * `booking_type` (`booking` or `request`) and `price_line`.
	 *
	 * Nothing for a visitor when the value is empty, so the `before` and
	 * `after` labels disappear with it.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function field( $atts = array() ): string {
		$atts  = self::atts(
			$atts,
			array(
				'key'    => '',
				'format' => 'text',
				'before' => '',
				'after'  => '',
			),
			'kaiki_trip_field'
		);
		$value = self::path( self::product( $atts ), $atts['key'] );
		$html  = self::format_value( $value, $atts['format'] );

		if ( '' === $html ) {
			return '';
		}

		return esc_html( $atts['before'] ) . $html . esc_html( $atts['after'] );
	}

	/**
	 * A value from the product by dotted path, with the computed keys.
	 *
	 * @param array<string, mixed> $product The trip.
	 * @param string               $path    For example `meeting_point.name`.
	 * @return mixed
	 */
	public static function path( array $product, string $path ) {
		$path = trim( $path );

		if ( '' === $path || ! $product ) {
			return null;
		}

		if ( 'booking_type' === $path ) {
			return 'quote' === ( $product['mode'] ?? '' ) ? 'request' : 'booking';
		}

		if ( 'price_line' === $path ) {
			if ( empty( $product['from_price_formatted'] ) ) {
				return __( 'On request', 'kaiki-booking' );
			}

			$unit = 'per_seat' === ( $product['mode'] ?? '' ) ? __( '/ person', 'kaiki-booking' ) : __( '/ boat', 'kaiki-booking' );

			return __( 'from', 'kaiki-booking' ) . ' ' . $product['from_price_formatted'] . ' ' . $unit;
		}

		$value = $product;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	/**
	 * A value as markup.
	 *
	 * @param mixed  $value  From {@see path()}.
	 * @param string $format text|list|html|url|image.
	 */
	public static function format_value( $value, string $format ): string {
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			$value = $value ? __( 'Yes', 'kaiki-booking' ) : __( 'No', 'kaiki-booking' );
		}

		if ( is_array( $value ) ) {
			// A list of photos or of named things reads by its url or its name.
			$items = array();

			foreach ( $value as $item ) {
				if ( is_array( $item ) ) {
					$item = $item['url'] ?? $item['name'] ?? $item['label'] ?? $item['title'] ?? null;
				}

				if ( is_scalar( $item ) && '' !== (string) $item ) {
					$items[] = (string) $item;
				}
			}

			if ( ! $items ) {
				return '';
			}

			if ( 'image' === $format ) {
				return implode( '', array_map( static fn ( string $url ): string => '<img src="' . esc_url( $url ) . '" alt="" loading="lazy">', $items ) );
			}

			if ( 'text' === $format ) {
				return esc_html( implode( ', ', $items ) );
			}

			return '<ul class="kaiki-trip-field-list"><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
		}

		$value = (string) $value;

		switch ( $format ) {
			case 'html':
				return wpautop( wp_kses_post( $value ) );
			case 'url':
				return esc_url( $value );
			case 'image':
				return '<img src="' . esc_url( $value ) . '" alt="" loading="lazy">';
			case 'list':
				return '<ul class="kaiki-trip-field-list"><li>' . implode( '</li><li>', array_map( 'esc_html', Trip::to_list( $value ) ) ) . '</li></ul>';
		}

		return esc_html( $value );
	}

	/**
	 * `[kaiki_fleet layout="rows|grid" photos="yes" specs="yes" amenities="yes" trips="yes"]`
	 *
	 * Every boat that runs a published trip, from Kaiki: type, name,
	 * description, the numbers a guest asks about, amenities, and the trips it
	 * runs. A boat with no photo of its own shows its first trip's.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function fleet( $atts = array() ): string {
		$atts    = self::atts(
			$atts,
			array(
				'layout'    => 'rows',
				'photos'    => 'yes',
				'specs'     => 'yes',
				'amenities' => 'yes',
				'trips'     => 'yes',
			),
			'kaiki_fleet'
		);
		$vessels = Trip::vessels();

		if ( ! $vessels ) {
			return self::empty_piece( __( 'Fleet (no boat runs a published trip yet)', 'kaiki-booking' ) );
		}

		$layout = self::layout( $atts['layout'], self::FLEET_LAYOUTS );
		$html   = '<div class="kaiki-fleet kaiki-fleet--' . $layout . '">';

		foreach ( $vessels as $vessel ) {
			$html .= self::vessel( $vessel, $atts );
		}

		return self::wrap( $html . '</div>' );
	}

	/**
	 * One boat.
	 *
	 * @param array<string, mixed>  $vessel The boat, from {@see Trip::vessels()}.
	 * @param array<string, string> $atts   The fleet's attributes.
	 */
	private static function vessel( array $vessel, array $atts ): string {
		$name  = (string) ( $vessel['name'] ?? '' );
		$photo = (string) ( $vessel['images'][0]['url'] ?? $vessel['trip_photo'] ?? '' );
		$specs = is_array( $vessel['specs'] ?? null ) ? $vessel['specs'] : array();
		$html  = '<article class="kaiki-vessel">';

		if ( self::yes( $atts['photos'] ) && '' !== $photo ) {
			$html .= '<div class="kaiki-vessel__media"><img src="' . esc_url( $photo ) . '" alt="' . esc_attr( $name ) . '" loading="lazy" decoding="async"></div>';
		}

		$html .= '<div class="kaiki-vessel__body">';

		$type  = Trip::vessel_type( (string) ( $vessel['type'] ?? '' ) );
		$html .= '' !== $type ? '<span class="kaiki-vessel__type">' . esc_html( $type ) . '</span>' : '';
		$html .= '<h3 class="kaiki-vessel__name">' . esc_html( $name ) . '</h3>';
		$html .= ! empty( $vessel['description'] ) ? '<p class="kaiki-vessel__description">' . esc_html( (string) $vessel['description'] ) . '</p>' : '';

		if ( self::yes( $atts['specs'] ) ) {
			$cells = self::vessel_specs( $vessel, $specs );

			if ( $cells ) {
				$html .= '<dl class="kaiki-vessel__specs">';

				foreach ( $cells as $label => $value ) {
					$html .= '<div class="kaiki-vessel__spec"><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd></div>';
				}

				$html .= '</dl>';
			}
		}

		if ( self::yes( $atts['amenities'] ) && ! empty( $specs['amenities'] ) && is_array( $specs['amenities'] ) ) {
			$items = array_filter( array_map( static fn ( $key ): string => Trip::amenity( (string) $key ), $specs['amenities'] ) );

			if ( $items ) {
				$html .= '<ul class="kaiki-vessel__amenities"><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
			}
		}

		if ( self::yes( $atts['trips'] ) && ! empty( $vessel['trips'] ) ) {
			$links = array();

			foreach ( $vessel['trips'] as $trip ) {
				$links[] = '' !== $trip['url']
					? '<a href="' . esc_url( $trip['url'] ) . '">' . esc_html( $trip['title'] ) . '</a>'
					: esc_html( $trip['title'] );
			}

			$html .= '<p class="kaiki-vessel__trips"><span>' . esc_html__( 'Trips:', 'kaiki-booking' ) . '</span> ' . implode( ', ', $links ) . '</p>';
		}

		return $html . '</div></article>';
	}

	/**
	 * The numbers a guest asks about a boat, labelled.
	 *
	 * @param array<string, mixed> $vessel The boat.
	 * @param array<string, mixed> $specs  Its specs.
	 * @return array<string, string>
	 */
	public static function vessel_specs( array $vessel, array $specs ): array {
		$cells = array(
			__( 'Guests', 'kaiki-booking' )         => ! empty( $vessel['capacity_max'] ) ? (string) (int) $vessel['capacity_max'] : '',
			__( 'Length', 'kaiki-booking' )         => ! empty( $vessel['length_m'] ) ? number_format_i18n( (float) $vessel['length_m'], 1 ) . ' m' : '',
			__( 'Crew', 'kaiki-booking' )           => ! empty( $vessel['crew_count'] ) ? (string) (int) $vessel['crew_count'] : '',
			__( 'Year built', 'kaiki-booking' )     => ! empty( $specs['year_built'] ) ? (string) (int) $specs['year_built'] : '',
			/* translators: %d: knots. */
			__( 'Cruising speed', 'kaiki-booking' ) => ! empty( $specs['cruising_speed_kn'] ) ? sprintf( __( '%d kn', 'kaiki-booking' ), (int) $specs['cruising_speed_kn'] ) : '',
			__( 'Engine', 'kaiki-booking' )         => ! empty( $specs['engine'] ) ? (string) $specs['engine'] : '',
		);

		return array_filter( $cells, static fn ( string $value ): bool => '' !== $value );
	}

	/**
	 * «yes», «1», «true», «on» — how people write a switch in a shortcode.
	 *
	 * @param mixed $value The attribute.
	 */
	public static function yes( $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( 'yes', '1', 'true', 'on' ), true );
	}

	/**
	 * The attributes, with `product` always present.
	 *
	 * @param array<string, string>|string $atts     What WordPress passed.
	 * @param array<string, string>        $defaults The shortcode's own.
	 * @param string                       $tag      The shortcode.
	 * @return array<string, string>
	 */
	private static function atts( $atts, array $defaults, string $tag ): array {
		return array_map( 'strval', shortcode_atts( $defaults + array( 'product' => '' ), is_array( $atts ) ? $atts : array(), $tag ) );
	}

	/**
	 * The trip these attributes are about.
	 *
	 * @param array<string, string> $atts Parsed attributes.
	 * @return array<string, mixed>
	 */
	private static function product( array $atts ): array {
		return Trip::product( Trip::uuid( $atts['product'] ) );
	}

	/**
	 * Output, with the stylesheet it needs.
	 *
	 * @param string $html Escaped markup.
	 */
	private static function wrap( string $html ): string {
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'kaiki-trip' );
		}

		return $html;
	}

	/**
	 * Nothing to show: nothing for a visitor, a labelled box for an editor.
	 *
	 * @param string $what What this piece would show.
	 */
	private static function empty_piece( string $what ): string {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return self::wrap( '<div class="kaiki-trip-placeholder">' . esc_html( $what ) . '</div>' );
	}
}
