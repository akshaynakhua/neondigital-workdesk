<?php
/**
 * Every front-end request renders the WorkDesk application. The app's own
 * hash router (#/dashboard, #/tasks/12, …) handles navigation.
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_front_page() && ! is_home() && ! is_404() ) {
	// Posts, pages and archives are not part of the app.
	wp_safe_redirect( home_url( '/' ) );
	exit;
}
ndwd_theme_render();
