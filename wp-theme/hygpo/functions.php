<?php
/**
 * Hygpo theme functions.
 *
 * @package Hygpo
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'hygpo_setup' ) ) :
	/**
	 * Theme setup.
	 */
	function hygpo_setup() {
		load_theme_textdomain( 'hygpo', get_template_directory() . '/languages' );

		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'html5', array(
			'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script',
		) );
		add_theme_support( 'responsive-embeds' );

		// Featured image used as the gallery (Post) cover.
		set_post_thumbnail_size( 800, 600, true );
		add_image_size( 'hygpo-card', 800, 0, false ); // card cover, fluid height

		register_nav_menus( array(
			'footer' => __( 'Footer Menu', 'hygpo' ),
		) );
	}
endif;
add_action( 'after_setup_theme', 'hygpo_setup' );

/**
 * Content width.
 */
function hygpo_content_width() {
	$GLOBALS['content_width'] = 1180;
}
add_action( 'after_setup_theme', 'hygpo_content_width', 0 );

/**
 * Enqueue styles and scripts. Everything is local — no external CDNs for the
 * restricted Docker network. Fonts are self-hosted under assets/fonts.
 */
function hygpo_assets() {
	$ver = wp_get_theme()->get( 'Version' );

	// Self-hosted Space Grotesk (heading font). Body uses the system stack.
	wp_enqueue_style( 'hygpo-fonts', get_template_directory_uri() . '/assets/fonts/fonts.css', array(), $ver );

	// Main stylesheet (the theme's style.css header lives here too).
	wp_enqueue_style( 'hygpo-style', get_stylesheet_uri(), array( 'hygpo-fonts' ), $ver );

	// Tiny vanilla JS: mobile search toggle + anchor offset safety. No jQuery dependency.
	wp_enqueue_script( 'hygpo-theme', get_template_directory_uri() . '/assets/theme.js', array(), $ver, true );

	if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'hygpo_assets' );

/**
 * Expose the live nav height to CSS/JS so scroll-margin-top stays in sync.
 * If you change --nav-h in style.css, update the 96 in theme.js too (README §scroll).
 */
function hygpo_head_meta() {
	echo '<meta name="theme-color" content="#fbfaf9">' . "\n";
}
add_action( 'wp_head', 'hygpo_head_meta' );

/**
 * Helper: render the [mwf_search] shortcode, or a graceful fallback if the
 * plugin is inactive (so the theme never shows a raw "[mwf_search]" string).
 */
function hygpo_search( $atts = '' ) {
	if ( shortcode_exists( 'mwf_search' ) ) {
		return do_shortcode( '[mwf_search ' . $atts . ']' );
	}
	// Fallback: a plain GET search form using the .mwf-* hooks so styling matches.
	ob_start(); ?>
	<form role="search" method="get" class="mwf-search mwf-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
		<span class="mwf-search-icon" aria-hidden="true">&#9906;</span>
		<input type="search" class="mwf-search-input" name="s"
			value="<?php echo esc_attr( get_search_query() ); ?>"
			placeholder="<?php esc_attr_e( 'Search images…', 'hygpo' ); ?>" />
		<button type="submit" class="mwf-search-btn"><?php esc_html_e( 'Search', 'hygpo' ); ?></button>
	</form>
	<?php
	return ob_get_clean();
}

/**
 * Pull up to two taxonomy terms for a card (post_tag), for the archive chips.
 */
function hygpo_card_tags( $post_id ) {
	$terms = get_the_terms( $post_id, 'post_tag' );
	if ( empty( $terms ) || is_wp_error( $terms ) ) { return; }
	$terms = array_slice( $terms, 0, 2 );
	echo '<div class="tags">';
	foreach ( $terms as $t ) {
		echo '<span>' . esc_html( $t->name ) . '</span>';
	}
	echo '</div>';
}

/**
 * Card cover: featured image, or a striped placeholder. Never shows prompts.
 */
function hygpo_card_cover( $post_id ) {
	if ( has_post_thumbnail( $post_id ) ) {
		echo '<div class="cover">';
		echo get_the_post_thumbnail( $post_id, 'hygpo-card', array(
			'loading' => 'lazy',
			'alt'     => esc_attr( get_the_title( $post_id ) ),
		) );
		echo '</div>';
	} else {
		echo '<div class="cover placeholder" aria-hidden="true"></div>';
	}
}

/**
 * Auth page URL helpers.
 *
 * The theme prefers themed Pages (using template-login / -register / -profile).
 * Create those Pages and either:
 *   (a) name their slugs "login", "register", "profile" (auto-detected), or
 *   (b) define the IDs via the `hygpo_page_*` filters below.
 * Falls back to WordPress core URLs (wp-login.php) if no matching Page exists.
 */
function hygpo_find_page_by_template( $template ) {
	$pages = get_posts( array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'numberposts' => 1,
		'fields'      => 'ids',
		'meta_key'    => '_wp_page_template',
		'meta_value'  => $template,
	) );
	return ! empty( $pages ) ? (int) $pages[0] : 0;
}

function hygpo_login_url() {
	$id = apply_filters( 'hygpo_page_login', hygpo_find_page_by_template( 'template-login.php' ) );
	return $id ? get_permalink( $id ) : wp_login_url( home_url( '/' ) );
}

function hygpo_register_url() {
	$id = apply_filters( 'hygpo_page_register', hygpo_find_page_by_template( 'template-register.php' ) );
	if ( $id ) { return get_permalink( $id ); }
	return get_option( 'users_can_register' ) ? wp_registration_url() : hygpo_login_url();
}

function hygpo_profile_url() {
	$id = apply_filters( 'hygpo_page_profile', hygpo_find_page_by_template( 'template-profile.php' ) );
	return $id ? get_permalink( $id ) : admin_url( 'profile.php' );
}

/**
 * Body classes used by templates.
 */
function hygpo_body_classes( $classes ) {
	if ( is_singular( 'post' ) ) { $classes[] = 'is-gallery-post'; }
	return $classes;
}
add_filter( 'body_class', 'hygpo_body_classes' );
