<?php
/**
 * Template Name: Profile
 *
 * Logged-in user's profile. Currently a placeholder empty state — unlocked
 * galleries / saved prompts can be wired in later. Requires login.
 *
 * @package Hygpo
 */

if ( ! is_user_logged_in() ) {
	wp_safe_redirect( add_query_arg( 'redirect_to', urlencode( get_permalink() ), hygpo_login_url() ) );
	exit;
}

$user    = wp_get_current_user();
$initial = strtoupper( mb_substr( $user->display_name ? $user->display_name : $user->user_login, 0, 1 ) );

get_header();
?>

<main class="profile">

	<header class="profile-head">
		<div class="profile-avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></div>
		<div class="profile-id">
			<h1><?php esc_html_e( 'Your profile', 'hygpo' ); ?></h1>
			<p>
				<?php
				/* translators: %s: user display name. */
				printf( esc_html__( 'Signed in as %s', 'hygpo' ), '<strong>' . esc_html( $user->display_name ) . '</strong>' );
				?>
			</p>
		</div>
		<a class="btn btn-ghost profile-logout" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">
			<?php esc_html_e( 'Log out', 'hygpo' ); ?>
		</a>
	</header>

	<?php
	// Placeholder empty state. Replace with unlocked-galleries / saved-prompts
	// listing when that data is available from the plugin.
	?>
	<div class="empty-state">
		<div class="empty-icon" aria-hidden="true">&#9700;</div>
		<h2><?php esc_html_e( 'Nothing here yet', 'hygpo' ); ?></h2>
		<p><?php esc_html_e( 'Your unlocked galleries and saved prompts will show up here. This area is a placeholder for now.', 'hygpo' ); ?></p>
		<a class="btn btn-primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php esc_html_e( 'Browse galleries', 'hygpo' ); ?>
		</a>
	</div>

</main>

<?php
get_footer();
