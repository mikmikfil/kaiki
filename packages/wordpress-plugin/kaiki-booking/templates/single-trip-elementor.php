<?php
/**
 * A trip page drawn by the Elementor template the plugin created.
 *
 * Chosen by `TripPostType::template()` when there is no theme override and the
 * template exists. Inside the loop, so every Kaiki widget in the template reads
 * this trip.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();

echo '<main id="kaiki-trip" class="kaiki-trip">';

while ( have_posts() ) {
	the_post();

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor's own rendered output.
	echo \Kaiki\Booking\Elementor\TripTemplate::render();
}

echo '</main>';

get_footer();
