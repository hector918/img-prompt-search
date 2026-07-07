<?php
/**
 * Single post = the gallery page (the most important template).
 *
 * The post body contains [mwf_gallery] (and the paywall plugin injects the
 * fixed floating unlock/translate control). We MUST run the_content() so the
 * shortcodes execute — never filter them out, never truncate.
 *
 * Container is wide (~1180px) and has NO transform / overflow:hidden on any
 * ancestor, so the plugin's position:fixed float is never clipped (spec 2.2).
 *
 * @package Hygpo
 */

get_header();

while ( have_posts() ) :
	the_post();
	?>

	<main class="post-main">

		<a class="post-back" href="<?php echo esc_url( get_post_type_archive_link( 'post' ) ? get_post_type_archive_link( 'post' ) : home_url( '/' ) ); ?>">
			&larr; <?php esc_html_e( 'Explore', 'hygpo' ); ?>
		</a>

		<h1 class="post-title"><?php the_title(); ?></h1>

		<?php
		// Status chip is populated by theme.js after render, reading the plugin's
		// .mwf-gallery.is-paid / .is-locked state (theme never decides paid state).
		?>
		<div class="post-status-slot" data-hygpo-status hidden></div>

		<?php
		// Gallery stage: wraps the [mwf_gallery] + intro text + float button.
		// the_content() executes all shortcodes in the post body.
		?>
		<div class="gallery-stage entry-content">
			<?php the_content(); ?>
		</div>

		<?php
		wp_link_pages( array(
			'before' => '<nav class="pagination" aria-label="' . esc_attr__( 'Gallery pages', 'hygpo' ) . '">',
			'after'  => '</nav>',
		) );
		?>

	</main>

	<?php
endwhile;

get_footer();
