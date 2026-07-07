<?php
/**
 * Footer.
 *
 * @package Hygpo
 */
?>
</div><!-- .site-content -->

<footer class="site-footer">
	<div class="copy">
		&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>
		&mdash; <?php esc_html_e( 'Universal prompt gallery.', 'hygpo' ); ?>
	</div>
	<?php
	if ( has_nav_menu( 'footer' ) ) {
		wp_nav_menu( array(
			'theme_location' => 'footer',
			'container'      => 'nav',
			'menu_class'     => 'footer-menu',
			'depth'          => 1,
			'fallback_cb'    => false,
		) );
	} else {
		echo '<nav>';
		echo '<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'hygpo' ) . '</a>';
		echo '</nav>';
	}
	?>
</footer>

<?php wp_footer(); ?>
</body>
</html>
