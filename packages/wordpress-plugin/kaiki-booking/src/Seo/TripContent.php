<?php
/**
 * One sync row, one language, turned into the fields of a post.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * The whole of the sync's judgement, with nothing that touches the database
 * (WPP-6).
 *
 * Separated from {@see TripRepository} on purpose. Deciding what a post should
 * say is where the mistakes are — a missing translation rendering as the word
 * "null", a summary used as a title, an operator's HTML escaped into visible
 * tags — and every one of those is testable with an array and no WordPress at
 * all. What is left in the repository is `wp_insert_post` and two meta writes.
 *
 * ## The page is real content, not a widget in an empty frame
 *
 * WPP-6 asks for *server-rendered content plus a widget mount*, in that order.
 * A page whose body is one `<div>` for JavaScript to fill has nothing for a
 * crawler to index, which is the entire reason the feature exists — the widget
 * is what turns the visitor it brought into a booking.
 *
 * ## An untranslated trip is skipped, not published in the wrong language
 *
 * A product with no English title is not an English page with a Greek title on
 * it. It is a page that should not exist yet, and {@see self::renderable()} says
 * so — the operator's half-finished translation must not become a live URL in a
 * language no visitor asked for.
 */
final class TripContent {

	/**
	 * Is there enough of this language to publish a page in it?
	 *
	 * The title alone, matching the platform's own `requiredTranslations` rule:
	 * a trip with no summary is ordinary, and a trip with no title in this
	 * language is a page with a blank where its name should be.
	 *
	 * @param array<string, mixed> $row    One row of `data` from the feed.
	 * @param string               $locale `el` or `en`.
	 */
	public static function renderable( array $row, string $locale ): bool {
		$title = self::text( $row, $locale, 'title' );

		return '' !== $title;
	}

	/**
	 * The post fields for one product in one language.
	 *
	 * @param  array<string, mixed> $row    One row of `data` from the feed.
	 * @param  string               $locale `el` or `en`.
	 * @return array{post_title: string, post_name: string, post_content: string, post_excerpt: string, post_status: string}
	 */
	public static function fields( array $row, string $locale ): array {
		$product = isset( $row['product'] ) && is_array( $row['product'] ) ? $row['product'] : array();

		return array(
			'post_title'   => self::text( $row, $locale, 'title' ),
			// The platform's slug, per language, so two languages of one trip do
			// not fight over one URL. WordPress would otherwise append `-2` to
			// the second and the operator would never know which was which.
			'post_name'    => self::slug( $row, $locale ),
			'post_content' => self::body( $row, $locale, $product ),
			'post_excerpt' => self::text( $row, $locale, 'summary' ),
			'post_status'  => self::status( $row ),
		);
	}

	/**
	 * `publish` or `draft`, and never `trash`.
	 *
	 * A tombstone and a trip switched off both become a draft: the URL stops
	 * answering, which is what unpublishing means, and the post is still there
	 * for the operator to see. Deleting instead would lose the mapping, so the
	 * next sync after a product is restored would create a second post at
	 * `slug-2` and the old one's inbound links would stay broken.
	 *
	 * @param array<string, mixed> $row One row of `data` from the feed.
	 */
	public static function status( array $row ): string {
		if ( ! empty( $row['tombstone'] ) ) {
			return 'draft';
		}

		return 'active' === ( $row['status'] ?? '' ) ? 'publish' : 'draft';
	}

	/**
	 * The body: the operator's prose, the facts, the lists, then the mount.
	 *
	 * @param array<string, mixed> $row     One row of `data` from the feed.
	 * @param string               $locale  `el` or `en`.
	 * @param array<string, mixed> $product The resolved payload, for the facts.
	 */
	private static function body( array $row, string $locale, array $product ): string {
		$parts = array();

		$description = self::text( $row, $locale, 'description' );

		if ( '' !== $description ) {
			// The operator's own HTML, filtered the way WordPress filters what
			// an editor writes. Escaping it instead would print their tags at
			// the visitor; passing it through unfiltered would make this plugin
			// a way to inject script into a site through the API.
			$parts[] = wp_kses_post( $description );
		}

		$facts = self::facts( $product );

		if ( array() !== $facts ) {
			$parts[] = self::definition_list( $facts );
		}

		foreach ( array( 'includes', 'excludes', 'what_to_bring' ) as $field ) {
			$items = self::list_of( $row, $locale, $field );

			if ( array() === $items ) {
				continue;
			}

			$parts[] = '<h2>' . esc_html( self::heading( $field ) ) . '</h2>' . self::unordered_list( $items );
		}

		$uuid = isset( $row['uuid'] ) ? (string) $row['uuid'] : '';

		if ( '' !== $uuid ) {
			// The mount, last, because everything above it is what a crawler
			// came for and what a visitor reads before deciding to book.
			$parts[] = '[kaiki_booking product="' . esc_attr( $uuid ) . '"]';
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * The language-free facts, which live on `product` rather than in any
	 * translation.
	 *
	 * @param  array<string, mixed> $product The resolved payload.
	 * @return array<string, string>
	 */
	private static function facts( array $product ): array {
		$facts = array();

		$duration = isset( $product['duration_minutes'] ) ? (int) $product['duration_minutes'] : 0;

		if ( $duration > 0 ) {
			$facts[ __( 'Duration', 'kaiki-booking' ) ] = sprintf(
				/* translators: %d: a whole number of minutes. */
				__( '%d minutes', 'kaiki-booking' ),
				$duration
			);
		}

		$port = isset( $product['meeting_point']['name'] ) ? (string) $product['meeting_point']['name'] : '';

		if ( '' !== $port ) {
			$facts[ __( 'Departs from', 'kaiki-booking' ) ] = $port;
		}

		$vessel = isset( $product['vessel']['name'] ) ? (string) $product['vessel']['name'] : '';

		if ( '' !== $vessel ) {
			$facts[ __( 'Boat', 'kaiki-booking' ) ] = $vessel;
		}

		$max = isset( $product['max_pax'] ) ? (int) $product['max_pax'] : 0;

		if ( $max > 0 ) {
			$facts[ __( 'Up to', 'kaiki-booking' ) ] = sprintf(
				/* translators: %d: a whole number of people. */
				__( '%d people', 'kaiki-booking' ),
				$max
			);
		}

		return $facts;
	}

	/**
	 * The facts as a definition list, which is what they are.
	 *
	 * @param array<string, string> $facts Label to value.
	 */
	private static function definition_list( array $facts ): string {
		$out = '<dl class="kaiki-trip-facts">';

		foreach ( $facts as $label => $value ) {
			$out .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}

		return $out . '</dl>';
	}

	/**
	 * One list, escaped.
	 *
	 * @param array<int, string> $items The lines.
	 */
	private static function unordered_list( array $items ): string {
		$out = '<ul>';

		foreach ( $items as $item ) {
			$out .= '<li>' . esc_html( $item ) . '</li>';
		}

		return $out . '</ul>';
	}

	/**
	 * The heading above one of the three lists.
	 *
	 * @param string $field One of the three list fields.
	 */
	private static function heading( string $field ): string {
		switch ( $field ) {
			case 'includes':
				return __( 'What is included', 'kaiki-booking' );
			case 'excludes':
				return __( 'Not included', 'kaiki-booking' );
			default:
				return __( 'What to bring', 'kaiki-booking' );
		}
	}

	/**
	 * The SEO title and description for this language, falling back to the
	 * trip's own words when the operator wrote none.
	 *
	 * @param  array<string, mixed> $row    One row of `data` from the feed.
	 * @param  string               $locale `el` or `en`.
	 * @return array{title: string, description: string}
	 */
	public static function meta( array $row, string $locale ): array {
		$title       = self::text( $row, $locale, 'meta_title' );
		$description = self::text( $row, $locale, 'meta_description' );

		return array(
			'title'       => '' !== $title ? $title : self::text( $row, $locale, 'title' ),
			'description' => '' !== $description ? $description : self::text( $row, $locale, 'summary' ),
		);
	}

	/**
	 * One translatable string, as a string, whatever the feed sent.
	 *
	 * A locale the operator supports but has not filled in arrives as `null`,
	 * which is the difference between "not translated" and "does not exist" —
	 * and `(string) null` is `''`, which is what every caller here wants. The
	 * guard is against an array arriving where a string belongs, which would
	 * otherwise be the string "Array" on a live page.
	 *
	 * @param array<string, mixed> $row    One row of `data` from the feed.
	 * @param string               $locale `el` or `en`.
	 * @param string               $field  The translatable field.
	 */
	private static function text( array $row, string $locale, string $field ): string {
		$value = $row['translations'][ $locale ][ $field ] ?? null;

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * One translatable list field, as a list of non-empty strings.
	 *
	 * @param  array<string, mixed> $row    One row of `data` from the feed.
	 * @param  string               $locale `el` or `en`.
	 * @param  string               $field  The translatable field.
	 * @return array<int, string>
	 */
	private static function list_of( array $row, string $locale, string $field ): array {
		$value = $row['translations'][ $locale ][ $field ] ?? null;

		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();

		foreach ( $value as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$out[] = trim( $item );
			}
		}

		return $out;
	}

	/**
	 * The post slug: the platform's, suffixed per language after the first.
	 *
	 * Unsuffixed for the site's own primary language and suffixed for the rest,
	 * so the common single-language installation gets exactly the URL the
	 * operator sees in their Kaiki panel. Two languages sharing one slug would
	 * be resolved by WordPress appending `-2` to whichever was saved second,
	 * which is stable only until somebody re-syncs in a different order.
	 *
	 * @param array<string, mixed> $row    One row of `data` from the feed.
	 * @param string               $locale `el` or `en`.
	 */
	private static function slug( array $row, string $locale ): string {
		$slug = isset( $row['slug'] ) ? (string) $row['slug'] : '';

		if ( '' === $slug ) {
			return '';
		}

		return TripLanguages::primary() === $locale ? $slug : $slug . '-' . $locale;
	}
}
