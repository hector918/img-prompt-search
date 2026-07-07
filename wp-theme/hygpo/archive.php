<?php
/**
 * Archive — list of galleries (Posts). Featured-image covers only. No prompts.
 *
 * @package Hygpo
 */

get_header();
?>

<header class="archive-head">
	<h1>
		<?php
		if ( is_category() || is_tag() || is_tax() ) {
			single_term_title();
		} elseif ( is_search() ) {
			/* translators: %s: search query. */
			printf( esc_html__( 'Results for “%s”', 'hygpo' ), esc_html( get_search_query() ) );
		} else {
			esc_html_e( 'Explore galleries', 'hygpo' );
		}
		?>
	</h1>
	<p><?php esc_html_e( 'Browse the latest image sets. Open one to unlock its prompts.', 'hygpo' ); ?></p>
</header>

<?php if ( have_posts() ) : ?>

	<div class="card-grid">
		<?php while ( have_posts() ) : the_post(); ?>
			<a class="post-card<?php echo ( function_exists( 'mwf_f_cover_masked' ) && mwf_f_cover_masked( get_the_ID() ) ) ? ' is-masked' : ''; ?>" href="<?php the_permalink(); ?>">
				<?php hygpo_card_cover( get_the_ID() ); ?>
				<div class="body">
					<div class="title"><?php the_title(); ?></div>
					<?php hygpo_card_tags( get_the_ID() ); ?>
				</div>
			</a>
		<?php endwhile; ?>
	</div>

	<?php
	the_posts_pagination( array(
		'mid_size'  => 1,
		'prev_text' => esc_html__( '←', 'hygpo' ),
		'next_text' => esc_html__( '→', 'hygpo' ),
	) );
	?>

<?php else : ?>

	<div class="card-grid">
		<p style="grid-column:1/-1;color:var(--text-3);">
			<?php esc_html_e( 'No galleries yet.', 'hygpo' ); ?>
		</p>
	</div>

<?php endif; ?>

<?php
get_footer();
