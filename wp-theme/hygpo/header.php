<?php
/**
 * Header — sticky nav with in-bar search (desktop) and a tap-to-open
 * full-width search on mobile. Only "Login" remains as a link.
 *
 * @package Hygpo
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<link rel="profile" href="https://gmpg.org/xfn/11">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="site-header">
	<div class="nav-inner">
		<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
			<span class="brand-name"><?php bloginfo( 'name' ); ?></span>
			<span class="brand-dot" aria-hidden="true"></span>
		</a>

		<?php // Desktop: [mwf_search] inline in the nav. ?>
		<div class="nav-search" role="search">
			<?php echo hygpo_search( 'context="nav"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode/plugin output ?>
		</div>

		<div class="nav-right">
			<button type="button" class="nav-icon-btn" id="hygpo-search-toggle"
				aria-label="<?php esc_attr_e( 'Toggle search', 'hygpo' ); ?>"
				aria-expanded="false" aria-controls="hygpo-mobile-search">&#9906;</button>
			<a class="btn-login" href="<?php echo esc_url( is_user_logged_in() ? hygpo_profile_url() : hygpo_login_url() ); ?>">
				<?php echo is_user_logged_in() ? esc_html__( 'Profile', 'hygpo' ) : esc_html__( 'Login', 'hygpo' ); ?>
			</a>
		</div>
	</div>

	<?php // Mobile: full-width search drops below the bar so the input is fully visible. ?>
	<div class="nav-mobile-search" id="hygpo-mobile-search" role="search">
		<?php echo hygpo_search( 'context="mobile"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
</header>

<div class="site-content" id="content">
