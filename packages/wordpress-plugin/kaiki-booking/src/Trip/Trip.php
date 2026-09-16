<?php
/**
 * The trip a trip shortcode is about, and what Kaiki knows about it.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Trip;

use Kaiki\Booking\Api\Client;
use Kaiki\Booking\Locale\Locale;
use Kaiki\Booking\Seo\TripPostType;
use Kaiki\Booking\Seo\TripRepository;
use Kaiki\Booking\Settings\Settings;
use Kaiki\Booking\Shortcodes\CurrentTrip;

defined( 'ABSPATH' ) || exit;

/**
 * Reading one trip for the trip shortcodes (title, gallery, facts, …).
 *
 * ## Which trip
 *
 * The same rule as `[kaiki_booking]`: an explicit `product` attribute, else
 * the trip page being viewed ({@see CurrentTrip}). That is what lets one
 * Elementor template, or one theme file, serve every trip — verified
 * 2026-09-16 on an Elementor Free template rendered around two different
 * trips: `get_the_ID()` is the trip, not the template.
 *
 * One difference, and only for these display pieces: **inside a page
 * builder's editor** a template has no trip, so they show the first synced
 * trip instead of a row of empty boxes. The booking form never does this —
 * a form that picked a trip by itself could sell the wrong one.
 *
 * ## What Kaiki knows
 *
 * `GET /products/{uuid}` through the plugin's cached client, so a page with
 * eight trip pieces is one request, and none once cached.
 */
final class Trip {

	/**
	 * The trip id to render, or an empty string.
	 *
	 * @param string $attribute The `product` attribute as typed, possibly empty.
	 */
	public static function uuid( string $attribute = '' ): string {
		$explicit = self::valid_uuid( $attribute );

		if ( '' !== $explicit ) {
			return $explicit;
		}

		$current = self::valid_uuid( CurrentTrip::uuid() );

		if ( '' !== $current || ! self::in_editor() ) {
			return $current;
		}

		return self::preview_uuid();
	}

	/**
	 * A uuid, or nothing. The value came from a page editor.
	 *
	 * @param string $value The attribute as typed.
	 */
	public static function valid_uuid( string $value ): string {
		$value = strtolower( trim( $value ) );

		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ? $value : '';
	}

	/**
	 * Kaiki's full record of one trip, or an empty array.
	 *
	 * @param string $uuid The trip.
	 * @return array<string, mixed>
	 */
	public static function product( string $uuid ): array {
		if ( '' === $uuid ) {
			return array();
		}

		$result = Client::get( '/products/' . rawurlencode( $uuid ) );

		return $result->succeeded() ? $result->payload() : array();
	}

	/**
	 * Every published trip's list record, in Kaiki's order.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function products(): array {
		$result = Client::get( '/products' );

		if ( ! $result->succeeded() ) {
			return array();
		}

		return array_values( array_filter( $result->payload(), 'is_array' ) );
	}

	/**
	 * The post a trip was synced into, in the visitor's language.
	 *
	 * @param string $uuid The trip.
	 */
	public static function post_id( string $uuid ): int {
		if ( '' === $uuid || ! Settings::seo_pages_enabled() ) {
			return 0;
		}

		return (int) TripRepository::find( $uuid, Locale::current() );
	}

	/**
	 * Where a trip card links: this site's trip page when there is one,
	 * otherwise Kaiki's own page for it.
	 *
	 * @param array<string, mixed> $product A list record.
	 */
	public static function url( array $product ): string {
		$post_id = self::post_id( (string) ( $product['uuid'] ?? '' ) );

		if ( $post_id > 0 && 'publish' === get_post_status( $post_id ) ) {
			return (string) get_permalink( $post_id );
		}

		return (string) ( $product['booking_url'] ?? '' );
	}

	/**
	 * A list about the trip — what is included, what to bring.
	 *
	 * Filtered, because an operator's site often knows things Kaiki does not:
	 * `kaiki_trip_list` receives Kaiki's items, the source and the trip post,
	 * and a theme can hand back its own (from its own custom fields, say). `highlights`
	 * has no Kaiki field at all and exists for exactly that.
	 *
	 * @param array<string, mixed> $product The trip.
	 * @param string               $source  includes|excludes|bring|highlights.
	 * @return list<string>
	 */
	public static function items( array $product, string $source ): array {
		$fields = array(
			'includes'   => 'includes',
			'excludes'   => 'excludes',
			'bring'      => 'what_to_bring',
			'highlights' => 'highlights',
		);

		$raw   = isset( $fields[ $source ] ) ? ( $product[ $fields[ $source ] ] ?? null ) : null;
		$items = self::to_list( $raw );

		$filtered = apply_filters( 'kaiki_trip_list', $items, $source, self::post_id( (string) ( $product['uuid'] ?? '' ) ), $product );

		return self::to_list( $filtered );
	}

	/**
	 * The day's timeline, as time and text pairs. Filtered like {@see items()}.
	 *
	 * @param array<string, mixed> $product The trip.
	 * @return list<array{time: string, text: string}>
	 */
	public static function itinerary( array $product ): array {
		$steps = array();

		foreach ( (array) ( $product['itinerary_stops'] ?? array() ) as $stop ) {
			if ( is_array( $stop ) && ! empty( $stop['name'] ) ) {
				$steps[] = array(
					'time'        => (string) ( $stop['time'] ?? $stop['local_time'] ?? '' ),
					'text'        => (string) $stop['name'],
					'description' => is_string( $stop['description'] ?? null ) ? $stop['description'] : '',
					'duration'    => ! empty( $stop['duration_minutes'] ) ? self::duration( (int) $stop['duration_minutes'] ) : '',
				);
			}
		}

		$filtered = apply_filters( 'kaiki_trip_itinerary', $steps, self::post_id( (string) ( $product['uuid'] ?? '' ) ), $product );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_array' ) ) : $steps;
	}

	/**
	 * Strings, or a string with one item per line, as a clean list.
	 *
	 * @param mixed $value What a field or a filter returned.
	 * @return list<string>
	 */
	public static function to_list( $value ): array {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\R/u', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
				$items[] = trim( (string) $item );
			}
		}

		return $items;
	}

	/**
	 * «4 hours», «3½ hours», «90 minutes» — minutes as a visitor reads them.
	 *
	 * @param int $minutes The duration.
	 */
	public static function duration( int $minutes ): string {
		if ( $minutes <= 0 ) {
			return '';
		}

		if ( $minutes < 60 || ( 0 !== $minutes % 60 && 30 !== $minutes % 60 ) ) {
			/* translators: %d: a number of minutes. */
			return sprintf( __( '%d minutes', 'kaiki-booking' ), $minutes );
		}

		$hours = intdiv( $minutes, 60 );
		$half  = 30 === $minutes % 60;

		if ( 1 === $hours && ! $half ) {
			return __( '1 hour', 'kaiki-booking' );
		}

		/* translators: %s: a number of hours, possibly with a ½. */
		return sprintf( __( '%s hours', 'kaiki-booking' ), $hours . ( $half ? '½' : '' ) );
	}

	/**
	 * A trip category as a visitor reads it.
	 *
	 * @param string $category Kaiki's category value.
	 */
	public static function category( string $category ): string {
		$labels = array(
			'shared_half_day'  => __( 'Half day', 'kaiki-booking' ),
			'shared_full_day'  => __( 'Full day', 'kaiki-booking' ),
			'private_half_day' => __( 'Private', 'kaiki-booking' ),
			'private_full_day' => __( 'Private', 'kaiki-booking' ),
			'sunset'           => __( 'Sunset', 'kaiki-booking' ),
			'custom'           => __( 'Special', 'kaiki-booking' ),
		);

		return $labels[ $category ] ?? '';
	}

	/**
	 * The boats that run the published trips, each with the trips it runs.
	 *
	 * The public API has no list of vessels — a boat reaches a guest only
	 * through a trip — so the fleet is read off the catalogue, and a boat with
	 * no published trip is, correctly, not on it.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function vessels(): array {
		$vessels = array();

		foreach ( self::products() as $product ) {
			$vessel = $product['vessel'] ?? null;

			if ( ! is_array( $vessel ) || empty( $vessel['uuid'] ) ) {
				continue;
			}

			$key = (string) $vessel['uuid'];

			if ( ! isset( $vessels[ $key ] ) ) {
				$vessels[ $key ] = $vessel + array(
					'trips'      => array(),
					'trip_photo' => (string) ( $product['hero_image_url'] ?? '' ),
				);
			}

			$vessels[ $key ]['trips'][] = array(
				'title' => (string) ( $product['title'] ?? '' ),
				'url'   => self::url( $product ),
			);
		}

		return array_values( $vessels );
	}

	/**
	 * A boat type as a visitor reads it. Kaiki's own labels.
	 *
	 * @param string $type Kaiki's vessel type.
	 */
	public static function vessel_type( string $type ): string {
		$labels = array(
			'catamaran'         => __( 'Catamaran', 'kaiki-booking' ),
			'sailing_yacht'     => __( 'Sailing yacht', 'kaiki-booking' ),
			'motor'             => __( 'Motor boat', 'kaiki-booking' ),
			'rib'               => __( 'RIB', 'kaiki-booking' ),
			'traditional_kaiki' => __( 'Traditional kaiki', 'kaiki-booking' ),
		);

		return $labels[ $type ] ?? '';
	}

	/**
	 * An amenity as a visitor reads it. Kaiki's own labels.
	 *
	 * @param string $amenity Kaiki's amenity value.
	 */
	public static function amenity( string $amenity ): string {
		$labels = array(
			'shade_canopy'          => __( 'Shade canopy', 'kaiki-booking' ),
			'sun_deck'              => __( 'Sun deck', 'kaiki-booking' ),
			'air_conditioning'      => __( 'Air conditioning', 'kaiki-booking' ),
			'cabin'                 => __( 'Cabin', 'kaiki-booking' ),
			'wc'                    => __( 'Toilet', 'kaiki-booking' ),
			'swim_ladder'           => __( 'Swim ladder', 'kaiki-booking' ),
			'freshwater_shower'     => __( 'Freshwater shower', 'kaiki-booking' ),
			'snorkelling_gear'      => __( 'Snorkelling gear', 'kaiki-booking' ),
			'paddleboard'           => __( 'Paddleboard', 'kaiki-booking' ),
			'fishing_gear'          => __( 'Fishing gear', 'kaiki-booking' ),
			'beach_towels'          => __( 'Beach towels', 'kaiki-booking' ),
			'fridge'                => __( 'Fridge', 'kaiki-booking' ),
			'drinking_water'        => __( 'Drinking water', 'kaiki-booking' ),
			'galley'                => __( 'Galley', 'kaiki-booking' ),
			'barbecue'              => __( 'Barbecue', 'kaiki-booking' ),
			'coffee_machine'        => __( 'Coffee machine', 'kaiki-booking' ),
			'sound_system'          => __( 'Sound system', 'kaiki-booking' ),
			'usb_charging'          => __( 'USB charging', 'kaiki-booking' ),
			'wifi'                  => __( 'Wi-Fi', 'kaiki-booking' ),
			'child_life_jackets'    => __( 'Child life jackets', 'kaiki-booking' ),
			'wheelchair_accessible' => __( 'Wheelchair accessible', 'kaiki-booking' ),
			'pet_friendly'          => __( 'Pets welcome', 'kaiki-booking' ),
		);

		return $labels[ $amenity ] ?? '';
	}

	/**
	 * Is a page builder rendering this for its editor rather than a visitor?
	 */
	public static function in_editor(): bool {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only, to decide what to preview.
		if ( isset( $_GET['elementor-preview'] ) ) {
			return true;
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->editor ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			return true;
		}

		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * The trip an editor previews a template against: the first synced one,
	 * else the first in the catalogue.
	 */
	private static function preview_uuid(): string {
		if ( Settings::seo_pages_enabled() ) {
			$first = get_posts(
				array(
					'post_type'   => TripPostType::POST_TYPE,
					'numberposts' => 1,
					'fields'      => 'ids',
					'orderby'     => 'title',
					'order'       => 'ASC',
				)
			);

			if ( $first ) {
				return self::valid_uuid( (string) get_post_meta( (int) $first[0], TripPostType::META_UUID, true ) );
			}
		}

		$products = self::products();

		return self::valid_uuid( (string) ( $products[0]['uuid'] ?? '' ) );
	}
}
