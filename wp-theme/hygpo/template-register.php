<?php
/**
 * Template Name: Register
 *
 * Themed registration screen. Account creation is handled by WordPress core
 * (Settings → General → "Anyone can register") or your membership/registration
 * plugin — this template only provides the styled form and posts to
 * wp-login.php?action=register (core's handler). If a plugin owns registration,
 * replace the form below with that plugin's shortcode; the wrapper styling
 * still applies.
 *
 * @package Hygpo
 */

if ( is_user_logged_in() ) {
	wp_safe_redirect( hygpo_profile_url() );
	exit;
}

$can_register = (bool) get_option( 'users_can_register' );
$reg_action   = esc_url( wp_registration_url() ); // wp-login.php?action=register
$reg_state    = isset( $_GET['registration'] ) ? sanitize_key( wp_unslash( $_GET['registration'] ) ) : '';

get_header();
?>

<main class="auth">
	<div class="auth-head">
		<h1><?php esc_html_e( 'Create your account', 'hygpo' ); ?></h1>
		<p><?php esc_html_e( 'Join to unlock and translate prompts.', 'hygpo' ); ?></p>
	</div>

	<?php if ( ! $can_register ) : ?>

		<div class="auth-alert" role="alert">
			<?php esc_html_e( 'Registration is currently closed.', 'hygpo' ); ?>
		</div>

	<?php else : ?>

		<?php if ( 'success' === $reg_state ) : ?>
			<div class="auth-alert ok" role="status">
				<?php esc_html_e( 'Check your email to confirm your account and set a password.', 'hygpo' ); ?>
			</div>
		<?php endif; ?>

		<?php
		/**
		 * Membership plugins can take over the whole form by hooking this filter
		 * and returning their own shortcode/markup (e.g. a Coinsnap or members
		 * plugin). Falls back to core's register form.
		 */
		$custom = apply_filters( 'hygpo_register_form', '' );
		if ( $custom ) {
			echo $custom; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-provided markup
		} else {
		?>
		<form id="hygpo-registerform" class="auth-form" action="<?php echo $reg_action; // phpcs:ignore ?>" method="post">
			<p class="auth-field">
				<label for="user_login"><?php esc_html_e( 'Username', 'hygpo' ); ?></label>
				<input type="text" name="user_login" id="user_login" autocomplete="username" required>
			</p>
			<p class="auth-field">
				<label for="user_email"><?php esc_html_e( 'Email', 'hygpo' ); ?></label>
				<input type="email" name="user_email" id="user_email" autocomplete="email" required>
			</p>
			<p class="auth-note">
				<?php esc_html_e( 'A password will be emailed to you, or set by your registration plugin.', 'hygpo' ); ?>
			</p>
			<p class="auth-submit">
				<button type="submit" class="btn btn-primary"><?php esc_html_e( 'Create account', 'hygpo' ); ?></button>
			</p>
		</form>
		<?php } ?>

	<?php endif; ?>

	<div class="auth-links center">
		<span><?php esc_html_e( 'Already have an account?', 'hygpo' ); ?></span>
		<a class="accent" href="<?php echo esc_url( hygpo_login_url() ); ?>"><?php esc_html_e( 'Log in', 'hygpo' ); ?></a>
	</div>
</main>

<?php
get_footer();
