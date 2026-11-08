<?php
/**
 * Server cron entry point: the recommended way to run WorkDesk's scheduler, from a hosting cron job
 * that runs a PHP command every minute. Command line only, so it needs no secret key:
 *
 *   php /path/to/wp-content/plugins/neondigital-workdesk/bin/cron.php
 *
 * Runs WorkDesk's scheduled jobs without any web request or signed-in user. Prints one line of
 * JSON (no secrets) and exits non-zero when a job failed to run.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit( 'Command line only.' );
}

$dir = __DIR__;
for ( $i = 0; $i < 8 && ! is_file( $dir . '/wp-load.php' ); $i++ ) {
	$dir = dirname( $dir );
}
if ( ! is_file( $dir . '/wp-load.php' ) ) {
	fwrite( STDERR, "wp-load.php not found.\n" );
	exit( 2 );
}

// wp-load.php expects these on a web request; a cron job has neither.
$_SERVER['HTTP_HOST']      = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';

require $dir . '/wp-load.php';

if ( ! class_exists( 'NDWD_Cron' ) ) {
	fwrite( STDERR, "NeonDigital WorkDesk is not active.\n" );
	exit( 3 );
}

$log = NDWD_Cron::run( 'php_cli', true );
echo wp_json_encode( $log ), "\n";
exit( isset( $log['error'] ) ? 1 : 0 );
