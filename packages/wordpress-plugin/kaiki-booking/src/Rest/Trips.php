<?php
/**
 * The trip list the block editor picks from.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

namespace Kaiki\Booking\Rest;

use Kaiki\Booking\Api\Client;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * `GET /kaiki/v1/trips` — so an operator picks a trip instead of pasting a uuid.
 *
 * ## Why this route exists at all
 *
 * It is the difference between a plugin an operator can use and one they have to
 * ask their web person to configure. **Nobody knows a uuid.** They know "the
 * sunset one".
 *
 * ## It is capability-gated, and it still returns nothing private
 *
 * Two locks, and the second is the one that matters. The capability check keeps
 * a visitor out of an editor's convenience. But the route also returns **only
 * what a publishable key could already read** — it proxies `GET /products` with
 * the `pk_`, so even a hole in the first lock leaks nothing that is not already
 * on the operator's own public page.
 *
 * That is a deliberate ordering: a route whose safety depended solely on a
 * capability check is a route one plugin conflict away from being public.
 */
final class Trips {

	public const NAMESPACE = 'kaiki/v1';

	public const ROUTE = '/trips';

	/**
	 * Register the route.
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					self::NAMESPACE,
					self::ROUTE,
					array(
						'methods'             => 'GET',
						'callback'            => array( self::class, 'handle' ),
						'permission_callback' => static function (): bool {
							// Whoever can put a block on a page. Not `manage_options`:
							// an editor lays out pages and does not administer the site.
							return current_user_can( 'edit_posts' );
						},
					)
				);
			}
		);
	}

	/**
	 * The operator's trips, as the editor needs them: a name and an id.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle(): WP_REST_Response {
		$result = Client::get( '/products', array( 'per_page' => 100 ) );

		if ( ! $result->succeeded() ) {
			// A failure is a value, not an exception (WPP-14). The editor shows
			// it and the block can still be saved with whatever it already had —
			// an operator should not lose a page because Kaiki was slow.
			return new WP_REST_Response(
				array(
					'trips' => array(),
					'error' => $result->editor_message(),
				),
				200
			);
		}

		$trips = array();

		foreach ( $result->payload() as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}

			$trips[] = array(
				'uuid'  => isset( $product['uuid'] ) ? (string) $product['uuid'] : '',
				'title' => isset( $product['title'] ) ? (string) $product['title'] : '',
			);
		}

		return new WP_REST_Response(
			array(
				'trips' => $trips,
				'error' => '',
			),
			200
		);
	}
}
