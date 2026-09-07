<?php
/**
 * The plugin's own trip page, used when the theme has not overridden it.
 *
 * A theme that wants these pages to look like the rest of the site drops a
 * `kaiki/single-trip.php` into itself and this file is never loaded (WPP-6).
 *
 * ## It leans on the theme rather than replacing it
 *
 * `get_header()` and `get_footer()` mean the operator's own navigation, their
 * logo and their footer are around this page, so a trip does not look like it
 * came from somewhere else. What is between them is the post's own content —
 * which the sync wrote as real HTML, with the widget's shortcode at the end of
 * it — and nothing this file invents.
 *
 * `the_content()` rather than echoing `post_content`: the shortcode has to run,
 * and so do the filters every other plugin on the site has registered. A page
 * that skipped them is a page where the operator's cookie banner, their
 * analytics and their related-posts block all quietly do not appear.
 *
 * @package Kaiki\Booking
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

get_header();

?>
<main id="kaiki-trip" class="kaiki-trip">
	<?php
	while ( have_posts() ) {
		the_post();
		?>
		<article <?php post_class( 'kaiki-trip-article' ); ?>>
			<header class="kaiki-trip-header">
				<h1><?php the_title(); ?></h1>

				<?php if ( has_excerpt() ) : ?>
					<p class="kaiki-trip-summary"><?php echo esc_html( get_the_excerpt() ); ?></p>
				<?php endif; ?>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<div class="kaiki-trip-image"><?php the_post_thumbnail( 'large' ); ?></div>
			<?php endif; ?>

			<div class="kaiki-trip-content">
				<?php the_content(); ?>
			</div>
		</article>
		<?php
	}
	?>
</main>
<?php

get_footer();
