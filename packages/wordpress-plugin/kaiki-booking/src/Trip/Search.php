<?php
/**
 * Searching the catalogue from the operator's own site.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Trip;

use Kaiki\Booking\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Kaiki's search bar and its results, on a WordPress page.
 *
 * ## Two pieces, so the bar can live in a hero
 *
 * `[kaiki_search]` is the bar: a date, a party, and whichever of type and
 * port the operator has switched on in Kaiki. It is an ordinary GET form, so
 * it works without JavaScript, the results are a URL a guest can share, and
 * the back button behaves. `[kaiki_search_results]` reads that URL and asks
 * `GET /search`. Put both on one page, or point the bar at a results page with
 * `results="/…/"`.
 *
 * ## The price is the party's
 *
 * Exactly as on Kaiki's own search: what four people pay on that date, not a
 * from-price. A quote trip is listed with no price. Each card opens the trip's
 * page on this site with the date carried along, so the booking form starts
 * on that day.
 *
 * ## Filters the operator switched off stay off
 *
 * The bar draws only the filters Kaiki reports as enabled, and Kaiki ignores
 * any other in the query string anyway.
 */
final class Search {

	/** Query-string names — prefixed, because `date` and `type` are WordPress's. */
	public const PARAM_DATE = 'kaiki_date';
	public const PARAM_PAX  = 'kaiki_pax';
	public const PARAM_TYPE = 'kaiki_type';
	public const PARAM_PORT = 'kaiki_port';

	/** Product types Kaiki's search accepts. */
	private const TYPES = array( 'shared_half_day', 'shared_full_day', 'private_half_day', 'private_full_day', 'sunset', 'custom' );

	/** Hook in. */
	public static function register(): void {
		add_shortcode( 'kaiki_search', array( self::class, 'bar' ) );
		add_shortcode( 'kaiki_search_results', array( self::class, 'results' ) );
	}

	/**
	 * `[kaiki_search results="" button="" layout="inline|stacked"]` — the bar.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function bar( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'results' => '',
				'button'  => __( 'Search', 'kaiki-booking' ),
				'layout'  => 'inline',
				'filters' => 'yes',
			),
			is_array( $atts ) ? $atts : array(),
			'kaiki_search'
		);

		$query   = self::query();
		$action  = '' !== trim( (string) $atts['results'] ) ? (string) $atts['results'] : (string) strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Only the path, escaped below.
		$enabled = TripShortcodes::yes( $atts['filters'] ) ? self::enabled_filters() : array();
		$layout  = 'stacked' === $atts['layout'] ? 'stacked' : 'inline';
		$today   = wp_date( 'Y-m-d' );

		$html  = '<form class="kaiki-search kaiki-search--' . $layout . '" method="get" action="' . esc_url( $action ) . '" role="search">';
		$html .= self::field( __( 'Date', 'kaiki-booking' ), '<input type="date" name="' . self::PARAM_DATE . '" value="' . esc_attr( $query['date'] ) . '" min="' . esc_attr( (string) $today ) . '" required>' );
		$html .= self::field( __( 'Guests', 'kaiki-booking' ), '<input type="number" name="' . self::PARAM_PAX . '" value="' . esc_attr( (string) $query['pax'] ) . '" min="1" max="99" inputmode="numeric">' );

		if ( in_array( 'type', $enabled, true ) ) {
			$options = '<option value="">' . esc_html__( 'Any type', 'kaiki-booking' ) . '</option>';

			foreach ( self::types_offered() as $type ) {
				$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $query['type'], $type, false ), esc_html( Trip::category( $type ) ) );
			}

			$html .= self::field( __( 'Type of trip', 'kaiki-booking' ), '<select name="' . self::PARAM_TYPE . '">' . $options . '</select>' );
		}

		$ports = in_array( 'port', $enabled, true ) ? self::ports_offered() : array();

		if ( count( $ports ) > 1 ) {
			$options = '<option value="">' . esc_html__( 'Any port', 'kaiki-booking' ) . '</option>';

			foreach ( $ports as $uuid => $name ) {
				$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $uuid ), selected( $query['port'], $uuid, false ), esc_html( $name ) );
			}

			$html .= self::field( __( 'Departing from', 'kaiki-booking' ), '<select name="' . self::PARAM_PORT . '">' . $options . '</select>' );
		}

		$html .= '<button type="submit" class="kaiki-search__button">' . esc_html( (string) $atts['button'] ) . '</button>';

		return self::wrap( $html . '</form>' );
	}

	/**
	 * `[kaiki_search_results layout="cards" …]` — the trips for the searched
	 * date and party, as cards. With no search yet, every trip.
	 *
	 * Takes the same display attributes as `[kaiki_trips]`.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public static function results( $atts = array() ): string {
		$atts  = is_array( $atts ) ? $atts : array();
		$query = self::query();

		if ( '' === $query['date'] ) {
			return TripShortcodes::trips( $atts );
		}

		$params = array(
			'date' => $query['date'],
			'pax'  => $query['pax'],
		);

		if ( '' !== $query['type'] ) {
			$params['type'] = $query['type'];
		}

		if ( '' !== $query['port'] ) {
			$params['port'] = $query['port'];
		}

		// Fresh, not cached: this answer is about seats, and a cached «6 left»
		// is how a guest walks into a full boat.
		$result = Client::get_fresh( '/search', $params );

		if ( ! $result->succeeded() ) {
			return self::wrap( '<p class="kaiki-search-empty">' . esc_html__( 'The search is not available right now. Please try again in a moment.', 'kaiki-booking' ) . '</p>' );
		}

		$rows = array_values( array_filter( $result->payload(), 'is_array' ) );

		// «20 Σεπτεμβρίου 2026»: the site's own date format is often the English «F j, Y».
		$day = (string) wp_date( 'j F Y', (int) strtotime( $query['date'] . ' 12:00:00' ) );
		/* translators: 1: a date, 2: a number of guests. */
		$summary = 1 === $query['pax'] ? sprintf( __( '%s · 1 guest', 'kaiki-booking' ), $day ) : sprintf( __( '%1$s · %2$d guests', 'kaiki-booking' ), $day, $query['pax'] );

		if ( ! $rows ) {
			return self::wrap(
				'<div class="kaiki-search-summary">' . esc_html( $summary ) . '</div>'
				. '<p class="kaiki-search-empty">' . esc_html__( 'No trip can take this party on that date. Try another day, or fewer guests.', 'kaiki-booking' ) . '</p>'
			);
		}

		$defaults = array(
			'cta'     => __( 'View & book →', 'kaiki-booking' ),
			'summary' => 'yes',
			'facts'   => 'yes',
			'layout'  => 'cards',
			'badge'   => 'yes',
			'price'   => 'yes',
			'photo'   => 'yes',
		);
		$atts     = array_map( 'strval', $atts + $defaults );

		$html = '<div class="kaiki-search-summary">'
			/* translators: %d: how many trips were found. */
			. esc_html( 1 === count( $rows ) ? __( '1 trip', 'kaiki-booking' ) : sprintf( __( '%d trips', 'kaiki-booking' ), count( $rows ) ) ) . ' · ' . esc_html( $summary )
			. '</div><div class="kaiki-trips kaiki-trips--' . TripShortcodes::layout( $atts['layout'], TripShortcodes::TRIP_LAYOUTS ) . '">';

		foreach ( $rows as $row ) {
			$product = is_array( $row['product'] ?? null ) ? $row['product'] : array();

			if ( ! $product ) {
				continue;
			}

			$url = Trip::url( $product );
			$url = '' !== $url ? add_query_arg( self::PARAM_DATE, $query['date'], $url ) : $url;

			$html .= TripShortcodes::card(
				$product,
				$atts,
				array(
					'url'        => $url,
					'price_html' => self::party_price( $row, $query['pax'] ),
					'departure'  => self::departure( $row ),
				)
			);
		}

		return self::wrap( $html . '</div>' );
	}

	/**
	 * The search as the URL carries it, each value checked.
	 *
	 * @return array{date: string, pax: int, type: string, port: string}
	 */
	public static function query(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A public GET search; nothing is written.
		$date = isset( $_GET[ self::PARAM_DATE ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAM_DATE ] ) ) : '';
		$pax  = isset( $_GET[ self::PARAM_PAX ] ) ? (int) $_GET[ self::PARAM_PAX ] : 2;
		$type = isset( $_GET[ self::PARAM_TYPE ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::PARAM_TYPE ] ) ) : '';
		$port = isset( $_GET[ self::PARAM_PORT ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAM_PORT ] ) ) : '';
		// phpcs:enable

		return self::clean( $date, $pax, $type, $port );
	}

	/**
	 * Values held to what Kaiki accepts. Pure, so it is testable.
	 *
	 * @param string $date A date.
	 * @param int    $pax  Guests.
	 * @param string $type A product type.
	 * @param string $port A meeting-point uuid.
	 * @return array{date: string, pax: int, type: string, port: string}
	 */
	public static function clean( string $date, int $pax, string $type, string $port ): array {
		$valid_date = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) );

		return array(
			'date' => $valid_date ? $date : '',
			'pax'  => max( 1, min( 99, $pax ) ),
			'type' => in_array( $type, self::TYPES, true ) ? $type : '',
			'port' => Trip::valid_uuid( $port ),
		);
	}

	/**
	 * «for 4 guests 220,00 €», or «On request».
	 *
	 * @param array<string, mixed> $row One search result.
	 * @param int                  $pax The party.
	 */
	private static function party_price( array $row, int $pax ): string {
		if ( 'on_request' === ( $row['availability'] ?? '' ) || empty( $row['party_price_formatted'] ) ) {
			return '<div class="kaiki-trip-price kaiki-trip-price--quote"><span class="kaiki-trip-price__amount">' . esc_html__( 'On request', 'kaiki-booking' ) . '</span></div>';
		}

		return sprintf(
			'<div class="kaiki-trip-price"><span class="kaiki-trip-price__prefix">%s</span><span class="kaiki-trip-price__amount">%s</span></div>',
			/* translators: %d: a number of guests. */
			esc_html( 1 === $pax ? __( 'for 1 guest', 'kaiki-booking' ) : sprintf( __( 'for %d guests', 'kaiki-booking' ), $pax ) ),
			esc_html( (string) $row['party_price_formatted'] )
		);
	}

	/**
	 * «Departs 09:00 · 6 seats», or ''.
	 *
	 * @param array<string, mixed> $row One search result.
	 */
	private static function departure( array $row ): string {
		$next = $row['next_departure'] ?? null;

		if ( ! is_array( $next ) || empty( $next['local_time'] ) ) {
			return '';
		}

		$seats = (int) ( $next['seats_available'] ?? 0 );
		/* translators: %s: a departure time. */
		$label = sprintf( __( 'Departs %s', 'kaiki-booking' ), (string) $next['local_time'] );

		if ( $seats > 0 && $seats <= 10 ) {
			/* translators: %d: seats left. */
			$label .= ' · ' . ( 1 === $seats ? __( '1 seat left', 'kaiki-booking' ) : sprintf( __( '%d seats left', 'kaiki-booking' ), $seats ) );
		}

		return '<span class="kaiki-trip-card__departure">' . esc_html( $label ) . '</span>';
	}

	/**
	 * The filters the operator has switched on in Kaiki.
	 *
	 * Read from a search's own `meta` rather than guessed; cached, because
	 * it changes only when the operator changes a setting.
	 *
	 * @return list<string>
	 */
	private static function enabled_filters(): array {
		$result = Client::get(
			'/search',
			array(
				'date' => (string) wp_date( 'Y-m-d' ),
				'pax'  => 1,
			)
		);

		$raw = $result->succeeded() && isset( $result->payload_meta()['filters_enabled'] ) ? $result->payload_meta()['filters_enabled'] : array( 'date', 'party' );

		return array_values( array_filter( (array) $raw, 'is_string' ) );
	}

	/**
	 * The types the published trips actually have, so the list never offers
	 * a choice that finds nothing.
	 *
	 * @return list<string>
	 */
	private static function types_offered(): array {
		$types = array();

		foreach ( Trip::products() as $product ) {
			$type = (string) ( $product['category'] ?? '' );

			if ( in_array( $type, self::TYPES, true ) && ! in_array( $type, $types, true ) ) {
				$types[] = $type;
			}
		}

		return $types;
	}

	/**
	 * The meeting points of the published trips.
	 *
	 * @return array<string, string>
	 */
	private static function ports_offered(): array {
		$ports = array();

		foreach ( Trip::products() as $product ) {
			$point = $product['meeting_point'] ?? null;

			if ( is_array( $point ) && ! empty( $point['uuid'] ) && ! empty( $point['name'] ) ) {
				$ports[ (string) $point['uuid'] ] = (string) $point['name'];
			}
		}

		return $ports;
	}

	/**
	 * A labelled control.
	 *
	 * @param string $label The label.
	 * @param string $input The control's markup, escaped.
	 */
	private static function field( string $label, string $input ): string {
		return '<label class="kaiki-search__field"><span class="kaiki-search__label">' . esc_html( $label ) . '</span>' . $input . '</label>';
	}

	/**
	 * Output, with the stylesheet.
	 *
	 * @param string $html Escaped markup.
	 */
	private static function wrap( string $html ): string {
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'kaiki-trip' );
		}

		return $html;
	}
}
