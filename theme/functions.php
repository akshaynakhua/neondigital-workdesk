<?php
/**
 * NeonDigital WorkDesk theme.
 *
 * The site's front page becomes the WorkDesk application. All data and
 * behaviour live in the NeonDigital WorkDesk plugin; this theme supplies the
 * branding (logo assets, favicon, branded wp-login.php) and the app routing.
 */

defined( 'ABSPATH' ) || exit;

define( 'NDWD_THEME_VERSION', '1.13.0' );

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
} );

/** With this theme active the app lives on the home page. */
add_filter( 'ndwd_app_url', function () {
	return home_url( '/' );
} );

/** Logos come from this theme (the supplied NeonDigital artwork). */
add_filter( 'ndwd_logos', function ( $logos ) {
	$base = get_template_directory_uri() . '/assets/img/';
	return array(
		'mark'     => $base . 'logo-mark.png',
		'wordmark' => $base . 'logo-wordmark.png',
		'full'     => $base . 'logo-full.png',
		'favicon'  => $base . 'favicon.png',
	);
} );

add_action( 'ndwd_head', function () {
	echo '<link rel="apple-touch-icon" href="' . esc_url( get_template_directory_uri() . '/assets/img/apple-touch-icon.png' ) . '">' . "\n";
} );

add_action( 'wp_head', function () {
	echo '<link rel="icon" href="' . esc_url( get_template_directory_uri() . '/assets/img/favicon.png' ) . '">' . "\n";
} );

/* ---------------------------------------------------------------------
 * Branded wp-login.php (used for password reset and as a fallback).
 * ------------------------------------------------------------------- */
add_action( 'login_enqueue_scripts', function () {
	wp_enqueue_style( 'ndwd-login', get_template_directory_uri() . '/assets/css/login.css', array(), NDWD_THEME_VERSION );
	$logo = esc_url( get_template_directory_uri() . '/assets/img/logo-full.png' );
	wp_add_inline_style( 'ndwd-login', '#login h1 a{background-image:url(' . $logo . ');}' );
} );
add_filter( 'login_headerurl', function () {
	return home_url( '/' );
} );
add_filter( 'login_headertext', function () {
	return 'NeonDigital WorkDesk';
} );
add_action( 'login_head', function () {
	echo '<link rel="icon" href="' . esc_url( get_template_directory_uri() . '/assets/img/favicon.png' ) . '">';
} );

/* ---------------------------------------------------------------------
 * Keep the front end private: this site is an internal application.
 * ------------------------------------------------------------------- */
add_filter( 'wp_robots', function ( $robots ) {
	$robots['noindex']  = true;
	$robots['nofollow'] = true;
	return $robots;
} );
// Hide the public REST user listing from logged-out visitors.
add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );
// No feeds for an internal app.
foreach ( array( 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom' ) as $feed ) {
	add_action( $feed, function () {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}, 1 );
}

/** Admin notice when the plugin is missing. */
add_action( 'admin_notices', function () {
	if ( ! class_exists( 'NDWD_App' ) && current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p><strong>NeonDigital WorkDesk theme:</strong> please install and activate the <em>NeonDigital WorkDesk</em> plugin. The theme needs it to run the application.</p></div>';
	}
} );

/** Renders the application, or a setup screen when the plugin is not active. */
function ndwd_theme_render() {
	if ( class_exists( 'NDWD_App' ) ) {
		NDWD_App::render();
		return;
	}
	status_header( 503 );
	?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body class="ndwd-setup-page">
<main class="ndwd-setup"><div class="box">
	<img src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/logo-full.png' ); ?>" alt="NeonDigital">
	<h1>WorkDesk is not set up yet</h1>
	<p>Activate the <strong>NeonDigital WorkDesk</strong> plugin to start using the application.</p>
	<?php if ( current_user_can( 'activate_plugins' ) ) : ?>
		<a class="btn" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">Go to Plugins</a>
	<?php else : ?>
		<a class="btn" href="<?php echo esc_url( wp_login_url() ); ?>">Administrator sign in</a>
	<?php endif; ?>
</div></main>
<?php wp_footer(); ?>
</body></html>
	<?php
}
