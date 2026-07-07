<?php
/**
 * Template Name: Login
 *
 * Themed login screen. Auth itself is handled by WordPress core (or your
 * membership plugin) — this template only styles the form. Create a Page,
 * assign this template, and point the nav "Login" link at it (see functions.php
 * → hygpo_login_url filterable helper / README).
 *
 * @package Hygpo
 */

// Already logged in? Send them to the profile page (or home).
if ( is_user_logged_in() ) {
	wp_safe_redirect( hygpo_profile_url() );
	exit;
}

$redirect    = ! empty( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : home_url( '/' );
$login_error = isset( $_GET['login'] ) && 'failed' === $_GET['login'];

get_header();
?>

<main class="auth">
	<div class="auth-head">
		<h1><?php esc_html_e( 'Welcome back', 'hygpo' ); ?></h1>
		<p><?php esc_html_e( 'Log in to unlock and translate prompts.', 'hygpo' ); ?></p>
	</div>

	<?php if ( $login_error ) : ?>
		<div class="auth-alert" role="alert">
			<?php esc_html_e( 'Wrong username or password. Please try again.', 'hygpo' ); ?>
		</div>
	<?php endif; ?>

	<?php
	wp_login_form( array(
		'echo'           => true,
		'redirect'       => $redirect,
		'form_id'        => 'hygpo-loginform',
		'label_username' => __( 'Username or email', 'hygpo' ),
		'label_password' => __( 'Password', 'hygpo' ),
		'label_remember' => __( 'Remember me', 'hygpo' ),
		'label_log_in'   => __( 'Log in', 'hygpo' ),
		'remember'       => true,
	) );
	?>

	<div class="auth-links">
		<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot password?', 'hygpo' ); ?></a>
		<?php if ( get_option( 'users_can_register' ) ) : ?>
			<a class="accent" href="<?php echo esc_url( hygpo_register_url() ); ?>"><?php esc_html_e( 'Create account', 'hygpo' ); ?></a>
		<?php endif; ?>
	</div>
</main>

<?php
get_footer();
