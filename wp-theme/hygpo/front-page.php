<?php
/**
 * Front page — compact search-first hero + tag filter + latest galleries.
 *
 * Set Settings → Reading → "Your homepage displays" to a static page to use
 * this; or it renders on the site root automatically when posts page is root.
 *
 * @package Hygpo
 */

get_header();
?>

<main>

	<section class="hero">
		<span class="hero-pill">
			<span class="d" aria-hidden="true"></span>
			<?php esc_html_e( 'Universal prompt gallery', 'hygpo' ); ?>
		</span>
		<h1><?php esc_html_e( 'Browse images. Reveal the prompt.', 'hygpo' ); ?></h1>

		<?php
		// Hero = a TAG FILTER (chips), not a search box. The search lives in the
		// nav. Chips are the most-used post tags and link to their gallery archive.
		$hero_tags = get_terms( array(
			'taxonomy'   => 'post_tag',
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 8,
			'hide_empty' => true,
		) );

		if ( ! empty( $hero_tags ) && ! is_wp_error( $hero_tags ) ) :
			?>
			<div class="tag-filter">
				<?php foreach ( $hero_tags as $t ) : ?>
					<a class="tag-chip" href="<?php echo esc_url( get_term_link( $t ) ); ?>">
						<?php echo esc_html( $t->name ); ?>
					</a>
				<?php endforeach; ?>
			</div>
			<p class="hero-hint"><?php esc_html_e( 'Filter by tag, or search from the bar above — results are images only.', 'hygpo' ); ?></p>
			<?php
		else :
			// No tags yet: gentle prompt to use the nav search.
			echo '<p class="hero-hint">' . esc_html__( 'Search from the bar above — results are images only.', 'hygpo' ) . '</p>';
		endif;
		?>
	</section>

	<?php
	// Search results feed. The [mwf_search] inputs (nav/mobile) drive this mount:
	// each search prepends a coordinate-masonry section here (newest on top);
	// Latest galleries below stays as the permanent bottom of the stack.
	// The frontend plugin's feed controller owns everything inside #mwf-feed.
	?>
	<div id="mwf-feed" class="mwf-feed" aria-live="polite"></div>

	<?php
	// Latest galleries (standard post loop, featured-image covers).
	$latest = new WP_Query( array(
		'post_type'           => 'post',
		'posts_per_page'      => 12,
		'ignore_sticky_posts' => 1,
	) );
	if ( $latest->have_posts() ) :
	?>
	<section class="section">
		<div class="section-head">
			<h2><?php esc_html_e( 'Latest galleries', 'hygpo' ); ?></h2>
			<a href="<?php echo esc_url( get_post_type_archive_link( 'post' ) ? get_post_type_archive_link( 'post' ) : home_url( '/blog/' ) ); ?>">
				<?php esc_html_e( 'View all →', 'hygpo' ); ?>
			</a>
		</div>
		<div class="masonry">
			<?php while ( $latest->have_posts() ) : $latest->the_post(); ?>
				<a class="card<?php echo ( function_exists( 'mwf_f_cover_masked' ) && mwf_f_cover_masked( get_the_ID() ) ) ? ' is-masked' : ''; ?>" href="<?php the_permalink(); ?>">
					<div class="thumb">
						<?php
						if ( has_post_thumbnail() ) {
							the_post_thumbnail( 'hygpo-card', array( 'loading' => 'lazy', 'alt' => esc_attr( get_the_title() ) ) );
						} else {
							echo '<div class="cover placeholder" style="aspect-ratio:3/4;" aria-hidden="true"></div>';
						}
						?>
					</div>
					<div class="title"><?php the_title(); ?></div>
				</a>
			<?php endwhile; ?>
		</div>
	</section>
	<?php
	endif;
	wp_reset_postdata();
	?>

</main>

<?php
get_footer();
