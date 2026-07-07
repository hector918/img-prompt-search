<?php
/**
 * Page template — also the host for a standalone "Search" page that contains
 * the [mwf_search] shortcode. the_content() runs the shortcode in a clean,
 * wide container.
 *
 * @package Hygpo
 */

get_header();

while ( have_posts() ) :
	the_post();

	$content      = get_the_content();
	$is_search    = has_shortcode( $content, 'mwf_search' );
	$wrap_class   = $is_search ? 'search-page' : 'page-body';
	?>

	<main class="<?php echo esc_attr( $wrap_class ); ?>">
		<?php if ( $is_search ) : ?>
			<h1 class="page-title"><?php the_title(); ?></h1>
			<p class="page-sub"><?php esc_html_e( 'Search images by mood, subject, or style — results are images only.', 'hygpo' ); ?></p>
		<?php else : ?>
			<h1><?php the_title(); ?></h1>
		<?php endif; ?>

		<div class="entry-content">
			<?php the_content(); ?>
		</div>
	</main>

	<?php
endwhile;

get_footer();
