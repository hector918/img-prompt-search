<?php
/**
 * 404 — also triggered intentionally for unpublished / orphan image
 * attachments (privacy). Keep it dignified with clear ways back.
 *
 * @package Hygpo
 */

get_header();
?>

<main class="nf">
	<div class="code">404</div>
	<h1><?php esc_html_e( "This image isn't public.", 'hygpo' ); ?></h1>
	<p><?php esc_html_e( 'The page or image you’re after may be unpublished or private. Try searching, or head back to the gallery home.', 'hygpo' ); ?></p>
	<div class="actions">
		<a class="btn btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php esc_html_e( 'Back to home', 'hygpo' ); ?>
		</a>
		<a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/' ) ); ?>#hygpo-mobile-search">
			<?php esc_html_e( 'Search images', 'hygpo' ); ?>
		</a>
	</div>
</main>

<?php
get_footer();
