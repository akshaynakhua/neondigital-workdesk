<?php
/**
 * Scheduled jobs: reminders, due/overdue alerts, daily summaries, monthly reports and
 * email, rolling data retention, invoice archive reminders and temp-file cleanup.
 *
 * The recommended driver is a server cron job, every minute, running bin/cron.php with PHP on the
 * command line (no secret key). The key-protected REST endpoint /wp-json/ndwd/v1/cron?key=... is the
 * alternative for hosts that can only call a URL. Neither needs a signed-in user or any site traffic.
 * WP-Cron stays as a traffic-dependent fallback and stands down while either of them is running.
 * All of them, and "Run jobs now", go through run(); only the recorded source differs.
 *
 * Reminders, due and overdue alerts go through scheduled_jobs: each delivery has one row (unique
 * run_key), a run claims it with one conditional UPDATE, and in-app and email are delivered
 * separately so one failing never blocks the other. Failed emails are retried with a growing
 * wait. Anything due during an outage is processed by the next run, with its delay recorded.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Cron {

	const HOOK = 'ndwd_tick';

	const MAX_ATTEMPTS  = 6;
	const DELAYED_AFTER = 180; // Seconds late before a delivery counts as delayed.
	const FULL_EVERY    = 240; // Seconds between the heavier scans when run every minute.
	const LOCK_FOR      = 240;
	const LOCK          = 'ndwd_tick_lock';

	const SOURCES        = array( 'php_cli', 'http', 'wp_cron', 'manual' );
	const HEALTH_MINUTES = 5;    // Default health window: a driver silent for longer is not "running".
	const WP_CRON_EVERY  = 300;
	const FAILURE_WINDOW = 3600; // Seconds a failed delivery or report keeps the status at Scheduler error.

	/** The run in progress, so a fatal error can still release the lock and record the failure. */
	private static $running = null;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		add_action( self::HOOK, array( __CLASS__, 'wp_tick' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedules( $s ) {
		$s['ndwd_five_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 minutes (WorkDesk)' );
		return $s;
	}

	public static function schedule() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'ndwd_five_minutes', self::HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** WP-Cron runs the same jobs only while no server cron is doing so. */
	public static function wp_tick() {
		if ( '' !== self::running_source() ) {
			return array( 'skipped' => 'A server cron is running.' );
		}
		return self::run( 'wp_cron', true );
	}

	/**
	 * @param string $source   Who started the run: php_cli, http, wp_cron or manual. Only a real
	 *                         command-line process can be php_cli; anything unknown counts as manual.
	 * @param bool   $throttle True for the every-minute drivers: reminders and monthly reports run
	 *                         each time, the heavier scans at most every few minutes.
	 * @return array Log of what ran.
	 */
	public static function run( $source = 'manual', $throttle = false ) {
		$source = in_array( $source, self::SOURCES, true ) && ( 'php_cli' !== $source || 'cli' === PHP_SAPI ) ? $source : 'manual';
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$stamp = self::take_lock();
		if ( ! $stamp ) {
			return array( 'skipped' => 'Another run is in progress.' );
		}
		$log           = array();
		$start_ts      = time();
		self::$running = array( 'source' => $source, 'stamp' => $stamp, 'ts' => $start_ts );
		register_shutdown_function( array( __CLASS__, 'after_crash' ) );
		try {
			update_option( 'ndwd_cron_last_start', array( 'at' => NDWD_Helpers::now_mysql(), 'source' => $source, 'ts' => $start_ts ), false );
			$full = ! $throttle || time() - (int) get_option( 'ndwd_cron_last_full' ) >= self::FULL_EVERY;
			$jobs = array( 'reminders' => array( __CLASS__, 'reminders' ) );
			if ( $full ) {
				$jobs['due_overdue'] = array( __CLASS__, 'due_and_overdue' );
			}
			$jobs['deliveries']     = array( __CLASS__, 'process_jobs' );
			$jobs['daily_summary']  = array( __CLASS__, 'daily_summaries' );
			$jobs['monthly_report'] = array( 'NDWD_Jobs', 'monthly_report' );
			$jobs['push']           = array( 'NDWD_Push', 'process_queue' ); // Sends what the jobs above just created.
			$jobs['ws_connect']     = array( 'NDWD_WS_Projects', 'tick' ); // Before webhooks, so what it queues goes out in this run.
			$jobs['ws_webhooks']    = array( 'NDWD_WS_Webhooks', 'process' );
			if ( $full ) {
				$jobs += array( 'job_cleanup' => array( __CLASS__, 'prune_jobs' ), 'retention' => array( 'NDWD_Jobs', 'retention' ), 'fy_notice' => array( 'NDWD_Accounts', 'fy_notices' ), 'package_cycles' => array( 'NDWD_Packages', 'roll_cycles' ), 'temp_cleanup' => array( 'NDWD_Jobs', 'temp_cleanup' ), 'push_cleanup' => array( 'NDWD_Push', 'prune' ) );
				$jobs['ws_prune'] = array( 'NDWD_WS_Webhooks', 'prune' );
				update_option( 'ndwd_cron_last_full', time(), false );
			}
			// Each job is isolated so one failure never stops the others.
			foreach ( $jobs as $k => $cb ) {
				try {
					$log[ $k ] = call_user_func( $cb );
				} catch ( Throwable $e ) {
					$log[ $k ]    = 'error: ' . $e->getMessage();
					$log['error'] = $k . ': ' . $e->getMessage();
				}
			}
			self::record_run( $source, $start_ts, $log );
		} finally {
			self::$running = null;
			self::release_lock( $stamp );
		}
		return $log;
	}

	/** @return int|false The lock's value when this run now holds it. One atomic statement decides. */
	private static function take_lock() {
		global $wpdb;
		$now = time();
		// Not add_option(): its INSERT ... ON DUPLICATE KEY UPDATE lets two racing runs both "win".
		$won = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, (string) $now ) );
		self::forget_lock_cache();
		if ( $won ) {
			return $now;
		}
		$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", self::LOCK ) );
		if ( $now - $held < self::LOCK_FOR ) {
			return false;
		}
		// A crashed run's lock: only one run can take it over.
		$took = $wpdb->query( $wpdb->prepare( "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $now, self::LOCK, (string) $held ) );
		self::forget_lock_cache();
		return $took ? $now : false;
	}

	/** Releases the lock only if it is still this run's, never one taken over from a slow run. */
	private static function release_lock( $stamp ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name = %s AND option_value = %s", self::LOCK, (string) $stamp ) );
		self::forget_lock_cache();
	}

	private static function forget_lock_cache() {
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/** Shutdown handler: a fatal error or exit inside a job must not hold the lock or look healthy. */
	public static function after_crash() {
		if ( ! self::$running ) {
			return;
		}
		$run           = self::$running;
		self::$running = null;
		$last          = error_get_last();
		self::record_run( $run['source'], $run['ts'], array( 'error' => 'The run stopped unexpectedly' . ( $last ? ': ' . $last['message'] : '.' ) ) );
		self::release_lock( $run['stamp'] );
	}

	/** Stores the outcome overall and for its source, so each driver's health is judged on its own runs. */
	private static function record_run( $source, $start_ts, $log ) {
		$done = NDWD_Helpers::now_mysql();
		$ok   = empty( $log['error'] );
		update_option( 'ndwd_last_cron', array( 'at' => $done, 'source' => $source, 'log' => $log ), false );
		if ( $ok ) {
			update_option( 'ndwd_cron_last_ok', $done, false );
		}
		$runs = self::runs();
		$rec  = isset( $runs[ $source ] ) ? $runs[ $source ] : array();
		if ( ! empty( $rec['ts'] ) && $start_ts - $rec['ts'] <= DAY_IN_SECONDS ) {
			$rec['interval'] = max( 1, $start_ts - $rec['ts'] );
		}
		$rec['ts']     = $start_ts;
		$rec['at']     = $done;
		$rec['failed'] = ! $ok;
		if ( $ok ) {
			$rec['ok_ts'] = time();
			$rec['ok_at'] = $done;
		}
		if ( 'php_cli' === $source && PHP_BINARY ) {
			$rec['binary'] = PHP_BINARY; // The interpreter this server really uses, for the Settings command.
		}
		$runs[ $source ] = $rec;
		update_option( 'ndwd_cron_runs', $runs, false );
	}

	private static function runs() {
		$runs = get_option( 'ndwd_cron_runs' );
		return is_array( $runs ) ? $runs : array();
	}

	/** Minutes without a successful run after which a driver no longer counts as running. */
	public static function health_minutes() {
		$s = NDWD_Notifications::settings();
		return ! empty( $s['scheduler_health_minutes'] ) ? (int) $s['scheduler_health_minutes'] : self::HEALTH_MINUTES;
	}

	/** The independent driver with a recent success (php_cli before http), or '' when there is none. */
	private static function running_source() {
		$runs   = self::runs();
		$window = self::health_minutes() * MINUTE_IN_SECONDS;
		foreach ( array( 'php_cli', 'http' ) as $src ) {
			if ( ! empty( $runs[ $src ]['ok_ts'] ) && self::is_recent( $runs[ $src ]['ok_ts'], $window ) ) {
				return $src;
			}
		}
		return '';
	}

	/** A timestamp from the future (clock change) is not evidence of a recent run. */
	private static function is_recent( $ts, $window ) {
		$age = time() - (int) $ts;
		return $age >= -60 && $age <= $window;
	}

	public static function source_label( $source ) {
		$labels = array(
			'php_cli' => 'PHP CLI cron',
			'http'    => 'URL cron',
			'wp_cron' => 'WP-Cron',
			'wp-cron' => 'WP-Cron',
			'manual'  => 'Run jobs now',
			'server'  => 'Server cron (before 1.12.0)',
		);
		return isset( $labels[ $source ] ) ? $labels[ $source ] : (string) $source;
	}

	/**
	 * The command for a hosting panel's cron job, built from this site's real paths. The binary comes
	 * from a PHP CLI run that really happened here, else from a path that exists and is executable.
	 * Web PHP (lsphp, php-fpm) is never offered. With neither, command is null: nothing is guessed.
	 *
	 * @return array Holds no secrets.
	 */
	public static function cli_command() {
		$script = wp_normalize_path( NDWD_DIR . 'bin/cron.php' );
		$real   = @realpath( $script ); // phpcs:ignore
		if ( $real ) {
			$script = wp_normalize_path( $real );
		}
		$runs   = self::runs();
		$binary = ! empty( $runs['php_cli']['binary'] ) ? (string) $runs['php_cli']['binary'] : '';
		$source = $binary ? 'confirmed' : '';
		if ( ! $binary ) {
			foreach ( self::php_candidates() as $path ) {
				if ( preg_match( '/^php(\d[\d.]*)?(\.exe)?$/i', basename( str_replace( '\\', '/', $path ) ) ) && @is_file( $path ) && @is_executable( $path ) ) { // phpcs:ignore
					$binary = $path;
					$source = 'detected';
					break;
				}
			}
		}
		$binary    = wp_normalize_path( $binary );
		$readable  = @is_readable( $script ); // phpcs:ignore
		$from_home = preg_match( '#^/home/[^/]+/(.+)$#', $script, $m ) ? $m[1] : null;
		return array(
			'script'         => $script,
			'script_ok'      => $readable,
			'script_from_home' => $from_home,
			'binary'         => $binary ? $binary : null,
			'binary_source'  => $binary ? $source : null,
			'confirmed_at'   => 'confirmed' === $source && ! empty( $runs['php_cli']['at'] ) ? $runs['php_cli']['at'] : null,
			'command'        => $binary && $readable ? self::shell_arg( $binary ) . ' ' . self::shell_arg( $script ) : null,
		);
	}

	private static function php_candidates() {
		$list = array( PHP_BINARY, PHP_BINDIR . '/php', '/opt/alt/php' . PHP_MAJOR_VERSION . PHP_MINOR_VERSION . '/usr/bin/php', '/usr/local/bin/php', '/usr/bin/php' );
		return array_filter( (array) apply_filters( 'ndwd_php_binary_candidates', $list ) );
	}

	private static function shell_arg( $arg ) {
		if ( preg_match( '#^[\w@%+=:,./\\-]+$#', $arg ) ) {
			return $arg;
		}
		return '\\' === DIRECTORY_SEPARATOR ? '"' . $arg . '"' : "'" . str_replace( "'", "'\\''", $arg ) . "'";
	}

	/** Queues a delivery once. @return bool False when run_key already exists. */
	public static function enqueue( $kind, $key, $task_id, $message, $when ) {
		global $wpdb;
		return (bool) $wpdb->query( $wpdb->prepare(
			'INSERT IGNORE INTO ' . NDWD_Helpers::table( 'scheduled_jobs' ) . " (kind, run_key, task_id, message, scheduled_for, status, created_at) VALUES (%s, %s, %d, %s, %s, 'scheduled', %s)",
			$kind, mb_substr( $key, 0, 190 ), $task_id, mb_substr( $message, 0, 500 ), $when, NDWD_Helpers::now_mysql()
		) );
	}

	/** Queues reminders that have come due. A rescheduled reminder gets a new key and a new row. */
	private static function reminders() {
		global $wpdb;
		$table = NDWD_Helpers::table( 'tasks' );
		$due   = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, reminder_at FROM $table WHERE reminder_sent = 0 AND reminder_at IS NOT NULL AND reminder_at <= %s AND is_archived = 0 AND status NOT IN ('completed','rejected') ORDER BY reminder_at LIMIT 500",
			NDWD_Helpers::now_mysql()
		) );
		$sent = 0;
		foreach ( $due as $t ) {
			// Claim the reminder atomically so concurrent runs cannot both queue it.
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET reminder_sent = 1 WHERE id = %d AND reminder_sent = 0", $t->id ) );
			if ( ! $claimed ) {
				continue;
			}
			self::enqueue( 'reminder', 'reminder:' . $t->id . ':' . $t->reminder_at, (int) $t->id, 'Reminder: ' . $t->title, $t->reminder_at );
			$sent++;
		}
		return $sent;
	}

	/** The delivery's task is gone, closed, or (for a reminder) was moved to another time. */
	private static function no_longer_due( $job, $task ) {
		if ( ! $task || $task->is_archived || NDWD_Helpers::is_closed( $task->status ) ) {
			return true;
		}
		return 'reminder' === $job->kind && (string) $task->reminder_at !== (string) $job->scheduled_for;
	}

	/**
	 * Claims and delivers queued jobs. Safe to run concurrently: a job is taken only by the run
	 * whose conditional UPDATE changed it. A run that died mid-job is picked up after 10 minutes.
	 *
	 * @return array Counts.
	 */
	public static function process_jobs( $limit = 200 ) {
		global $wpdb;
		$t     = NDWD_Helpers::table( 'scheduled_jobs' );
		$now   = NDWD_Helpers::now_mysql();
		$stale = wp_date( 'Y-m-d H:i:s', time() - 600, NDWD_Helpers::tz() );
		$out   = array( 'delivered' => 0, 'retry' => 0, 'failed' => 0, 'cancelled' => 0 );
		// A send interrupted by a crash must not hold its dedupe key for ever.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_Helpers::table( 'email_log' ) . " SET status = 'failed', error = 'Interrupted while sending.', dedupe_key = NULL WHERE status = 'sending' AND created_at < %s", $stale ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $t WHERE status = 'scheduled' OR ( status = 'retry' AND next_attempt_at <= %s ) OR ( status = 'processing' AND claimed_at < %s ) ORDER BY scheduled_for LIMIT %d", // phpcs:ignore
			$now, $stale, $limit
		) );
		foreach ( $rows as $job ) {
			$won = $wpdb->query( $wpdb->prepare(
				"UPDATE $t SET status = 'processing', claimed_at = %s, attempts = attempts + 1 WHERE id = %d AND status = %s AND attempts = %d", // phpcs:ignore
				$now, $job->id, $job->status, $job->attempts
			) );
			if ( ! $won ) {
				continue;
			}
			$out[ self::deliver_job( $job ) ]++;
		}
		return $out;
	}

	/** @return string delivered, retry, failed or cancelled. */
	private static function deliver_job( $job ) {
		global $wpdb;
		$t    = NDWD_Helpers::table( 'scheduled_jobs' );
		$task = $job->task_id ? NDWD_Tasks::get( $job->task_id ) : null;
		$now  = NDWD_Helpers::now_mysql();
		if ( self::no_longer_due( $job, $task ) ) {
			$wpdb->update( $t, array( 'status' => 'cancelled', 'finished_at' => $now, 'last_error' => 'Task closed, archived or rescheduled before delivery.' ), array( 'id' => $job->id ) );
			return 'cancelled';
		}
		$created = 0;
		$sent    = 0;
		$failed  = 0;
		foreach ( NDWD_Tasks::notify_targets( $task ) as $uid ) {
			$uid = (int) $uid;
			if ( ! NDWD_Permissions::is_active( $uid ) ) {
				continue;
			}
			$r        = NDWD_Notifications::deliver( $uid, $task, $job->kind, $job->message, mb_substr( $job->run_key . ':' . $uid, 0, 190 ) );
			$created += $r['in_app'] ? 1 : 0;
			$sent    += 'sent' === $r['email'] ? 1 : 0;
			$failed  += 'failed' === $r['email'] ? 1 : 0;
		}
		$attempts = (int) $job->attempts + 1;
		$delay    = max( 0, time() - ( new DateTimeImmutable( $job->scheduled_for, NDWD_Helpers::tz() ) )->getTimestamp() );
		$row      = array(
			'inapp_created' => (int) $job->inapp_created + $created,
			'email_sent'    => (int) $job->email_sent + $sent,
			'email_failed'  => $failed,
			'email_status'  => $failed ? 'failed' : ( $sent || (int) $job->email_sent ? 'sent' : 'skipped' ),
			'last_error'    => $failed ? NDWD_Notifications::last_error() : null,
		);
		if ( ! $job->finished_at ) {
			$row['delay_seconds'] = $delay;
		}
		$status = 'delivered';
		if ( $failed && $attempts < self::MAX_ATTEMPTS ) {
			$status                 = 'retry';
			$row['next_attempt_at'] = wp_date( 'Y-m-d H:i:s', time() + 60 * pow( 2, $attempts ), NDWD_Helpers::tz() );
		} elseif ( $failed ) {
			$status = 'failed';
		}
		$row['status']      = $status;
		$row['finished_at'] = $now;
		$wpdb->update( $t, $row, array( 'id' => $job->id ) );
		return $status;
	}

	/** Finished delivery rows are only a monitoring record: kept 180 days. Failed ones stay. */
	public static function prune_jobs() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NDWD_Helpers::table( 'scheduled_jobs' ) . " WHERE status IN ('delivered','cancelled') AND finished_at < %s", wp_date( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS, NDWD_Helpers::tz() ) ) );
	}

	/** Compact scheduler state for Settings. Holds no secrets. */
	public static function health() {
		global $wpdb;
		$t      = NDWD_Helpers::table( 'scheduled_jobs' );
		$now    = NDWD_Helpers::now_mysql();
		$counts = array( 'scheduled' => 0, 'processing' => 0, 'retry' => 0, 'delivered' => 0, 'failed' => 0, 'cancelled' => 0 );
		foreach ( $wpdb->get_results( "SELECT status, COUNT(*) n FROM $t GROUP BY status" ) as $r ) { // phpcs:ignore
			$counts[ $r->status ] = (int) $r->n;
		}
		$last    = get_option( 'ndwd_last_cron' );
		$ok      = get_option( 'ndwd_cron_last_ok' );
		$runs    = self::runs();
		$tasks   = NDWD_Helpers::table( 'tasks' );
		$future  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, MIN(reminder_at) nxt FROM $tasks WHERE reminder_sent = 0 AND reminder_at IS NOT NULL AND reminder_at > %s AND is_archived = 0 AND status NOT IN ('completed','rejected')", $now ) ); // phpcs:ignore
		$overdue = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tasks WHERE reminder_sent = 0 AND reminder_at IS NOT NULL AND reminder_at <= %s AND is_archived = 0 AND status NOT IN ('completed','rejected')", $now ) ); // phpcs:ignore
		$minutes = self::health_minutes();
		$window  = $minutes * MINUTE_IN_SECONDS;
		$running = self::running_source();
		$error   = self::recent_failure( $last );
		$wp_rec  = isset( $runs['wp_cron'] ) ? $runs['wp_cron'] : array();
		// Run jobs now never decides the status: it proves nothing about the next run.
		if ( '' !== $error ) {
			$status = 'error';
		} elseif ( 'php_cli' === $running ) {
			$status = 'server_cron';
		} elseif ( 'http' === $running ) {
			$status = 'http_cron';
		} elseif ( ! empty( $wp_rec['ok_ts'] ) && self::is_recent( $wp_rec['ok_ts'], $window + self::WP_CRON_EVERY ) ) {
			$status = 'wp_cron_only';
		} else {
			$status = 'not_detected';
		}
		$labels = array(
			'server_cron'  => 'Server cron running',
			'http_cron'    => 'HTTP cron running',
			'wp_cron_only' => 'WP-Cron fallback only',
			'not_detected' => 'Cron not detected',
			'error'        => 'Scheduler error',
		);
		$legacy      = get_option( 'ndwd_cron_last_server' );
		$independent = array();
		foreach ( array( 'php_cli', 'http' ) as $src ) {
			if ( ! empty( $runs[ $src ]['ok_at'] ) ) {
				$independent[ $runs[ $src ]['ok_at'] ] = self::source_label( $src );
			}
		}
		if ( ! $independent && $legacy ) {
			$independent[ $legacy ] = self::source_label( 'server' );
		}
		krsort( $independent );
		$seen = $independent ? 'Last server run: ' . reset( $independent ) . ' at ' . key( $independent ) . '.' : 'No server cron run has been recorded.';
		$details = array(
			'server_cron'  => 'bin/cron.php ran successfully on the command line within the last ' . $minutes . ' minutes.',
			'http_cron'    => 'The URL endpoint ran successfully within the last ' . $minutes . ' minutes. No PHP CLI cron has run in that time.',
			'wp_cron_only' => 'No server cron has succeeded in the last ' . $minutes . ' minutes, so jobs run only when someone visits the site. ' . $seen,
			'not_detected' => 'No scheduled run has succeeded in the last ' . $minutes . ' minutes. Run jobs now does not count. ' . $seen,
			'error'        => rtrim( $error, '.' ) . '.' . ( $running ? ' ' . self::source_label( $running ) . ' itself is still running.' : '' ),
		);
		if ( 'not_detected' === $status && ! $runs && $legacy && self::is_recent( strtotime( $legacy . ' ' . NDWD_Helpers::tz()->getName() ), $window ) ) {
			$details['not_detected'] = 'A server run was recorded just before this update, without its method. The next run will confirm it.';
		}
		$next_note = '';
		if ( $running ) {
			$rec      = $runs[ $running ];
			$interval = ! empty( $rec['interval'] ) ? (int) $rec['interval'] : 60;
			$next_ts  = (int) $rec['ts'] + (int) ceil( max( 1, time() - (int) $rec['ts'] ) / $interval ) * $interval;
			$next_run = wp_date( 'Y-m-d H:i:s', $next_ts, NDWD_Helpers::tz() );
		} else {
			$next_wp   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? false : wp_next_scheduled( self::HOOK );
			$next_run  = $next_wp ? wp_date( 'Y-m-d H:i:s', $next_wp, NDWD_Helpers::tz() ) : null;
			$next_note = $next_wp ? ( $next_wp <= time() ? 'WP-Cron: overdue, waiting for a site visit' : 'WP-Cron: needs a site visit at or after this time' ) : 'Nothing will run until a cron job is set up';
		}
		$sources = array();
		foreach ( self::SOURCES as $src ) {
			$rec             = isset( $runs[ $src ] ) ? $runs[ $src ] : array();
			$sources[ $src ] = array(
				'label'        => self::source_label( $src ),
				'last_run'     => isset( $rec['at'] ) ? $rec['at'] : null,
				'last_success' => isset( $rec['ok_at'] ) ? $rec['ok_at'] : null,
				'failed'       => ! empty( $rec['failed'] ),
			);
		}
		return array(
			'status'          => $status,
			'status_label'    => $labels[ $status ],
			'status_detail'   => $details[ $status ],
			'running'         => $running,
			'health_minutes'  => $minutes,
			'sources'         => $sources,
			'last_run'        => $last ? $last['at'] : null,
			'last_source'     => $last && isset( $last['source'] ) ? self::source_label( $last['source'] ) : '',
			'last_source_key' => $last && isset( $last['source'] ) ? $last['source'] : '',
			'last_success'    => $ok ? $ok : null,
			'next_run'        => $next_run,
			'next_run_note'   => $next_note,
			'next_reminder'   => $future && $future->nxt ? $future->nxt : null,
			'upcoming'        => $future ? (int) $future->n : 0,
			'pending'         => $counts['scheduled'] + $counts['processing'] + $counts['retry'] + $overdue,
			'counts'          => $counts,
			'failed'          => $counts['failed'],
			'delayed'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE delay_seconds > %d", self::DELAYED_AFTER ) ), // phpcs:ignore
			'email_sent'      => (int) $wpdb->get_var( "SELECT COALESCE(SUM(email_sent),0) FROM $t" ), // phpcs:ignore
			'inapp_created'   => (int) $wpdb->get_var( "SELECT COALESCE(SUM(inapp_created),0) FROM $t" ), // phpcs:ignore
			'retried'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE attempts > 1" ), // phpcs:ignore
			'recent_failures' => array_map( function ( $r ) {
				return array( 'kind' => $r->kind, 'task_id' => (int) $r->task_id, 'attempts' => (int) $r->attempts, 'error' => (string) $r->last_error, 'at' => $r->finished_at );
			}, $wpdb->get_results( "SELECT kind, task_id, attempts, last_error, finished_at FROM $t WHERE status = 'failed' ORDER BY id DESC LIMIT 5" ) ), // phpcs:ignore
		);
	}

	/** @return string Why the status is Scheduler error, or '' when nothing failed recently. */
	private static function recent_failure( $last ) {
		global $wpdb;
		$tz    = NDWD_Helpers::tz();
		$start = get_option( 'ndwd_cron_last_start' );
		$done  = $last ? strtotime( $last['at'] . ' ' . $tz->getName() ) : 0;
		if ( $last && ! empty( $last['log']['error'] ) && self::is_recent( $done, self::FAILURE_WINDOW ) ) {
			return 'The last run (' . self::source_label( $last['source'] ) . ') failed: ' . $last['log']['error'];
		}
		// Started, never finished and past the lock's life: killed before it could record anything.
		if ( ! empty( $start['ts'] ) && $start['ts'] > $done && time() - $start['ts'] >= self::LOCK_FOR && self::is_recent( $start['ts'], self::FAILURE_WINDOW ) ) {
			return 'A run (' . self::source_label( $start['source'] ) . ') started and never finished.';
		}
		$since = wp_date( 'Y-m-d H:i:s', time() - self::FAILURE_WINDOW, $tz );
		$jobs  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_Helpers::table( 'scheduled_jobs' ) . " WHERE status = 'failed' AND finished_at >= %s", $since ) ); // phpcs:ignore
		$packs = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_Jobs::table() . " WHERE status IN ('failed','gave_up') AND finished_at >= %s", $since ) ); // phpcs:ignore
		if ( $jobs || $packs ) {
			return trim( ( $jobs ? $jobs . ' delivery(ies) failed after every retry in the last hour. ' : '' ) . ( $packs ? $packs . ' report job(s) failed in the last hour.' : '' ) );
		}
		return '';
	}

	private static function due_and_overdue() {
		global $wpdb;
		$table = NDWD_Helpers::table( 'tasks' );
		$today = NDWD_Helpers::today();
		$count = 0;

		$due_today = NDWD_Tasks::hydrate( $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table WHERE is_archived = 0 AND status NOT IN ('completed','rejected') AND due_date = %s AND scheduled_date <= %s", $today, $today
		) ) );
		foreach ( $due_today as $t ) {
			$when = $t->due_time ? ' at ' . substr( $t->due_time, 0, 5 ) : '';
			self::enqueue( 'due', 'due:' . $t->id . ':' . $today, (int) $t->id, 'Due today' . $when . ': ' . $t->title, NDWD_Helpers::now_mysql() );
			$count++;
		}

		$vals    = array();
		$cond    = str_replace( 't.', '', NDWD_Tasks::overdue_condition( $vals ) );
		$overdue = NDWD_Tasks::hydrate( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE is_archived = 0 AND $cond", $vals ) ) ); // phpcs:ignore
		foreach ( $overdue as $t ) {
			// One overdue alert per task per day.
			self::enqueue( 'overdue', 'overdue:' . $t->id . ':' . $today, (int) $t->id, 'Overdue: ' . $t->title . ' (due ' . $t->due_date . ')', NDWD_Helpers::now_mysql() );
			$count++;
		}
		return $count;
	}

	private static function daily_summaries() {
		$s = NDWD_Notifications::settings();
		if ( empty( $s['email_enabled'] ) || empty( $s['email_types']['daily_summary'] ) ) {
			return 'disabled';
		}
		$now  = NDWD_Helpers::now();
		$hour = isset( $s['daily_summary_hour'] ) ? (int) $s['daily_summary_hour'] : 9;
		if ( (int) $now->format( 'G' ) < $hour ) {
			return 'not yet';
		}
		$today = $now->format( 'Y-m-d' );
		if ( get_option( 'ndwd_summary_done' ) === $today ) {
			return 'already sent';
		}
		$sent = 0;
		foreach ( NDWD_Stats::active_members() as $u ) {
			if ( get_user_meta( $u->ID, 'ndwd_email_optout', true ) ) {
				continue;
			}
			$list = NDWD_Tasks::rows( array( 'scope' => 'mine', 'view' => 'today', '_system' => true ), $u->ID );
			$open = array_filter( $list, function ( $t ) {
				return ! NDWD_Helpers::is_closed( $t->status );
			} );
			if ( ! $open ) {
				continue;
			}
			$items = '';
			foreach ( array_slice( $open, 0, 15 ) as $t ) {
				$flag   = NDWD_Tasks::is_overdue( $t, $now ) ? ' <strong style="color:#dc2626">OVERDUE</strong>' : ( $t->scheduled_date < $today ? ' <span style="color:#6b7280">(carried forward)</span>' : '' );
				$items .= '<li style="margin:4px 0">' . esc_html( $t->title ) . ' — ' . esc_html( NDWD_Helpers::priority_label( $t->priority ) ) . $flag . '</li>';
			}
			$body = '<p style="color:#374151">You have ' . count( $open ) . ' open task(s) on today\'s worksheet.</p><ul style="padding-left:18px;color:#111827">' . $items . '</ul>';
			if ( true === NDWD_Notifications::send( $u->ID, '[WorkDesk] Your worksheet for ' . $now->format( 'd M Y' ), NDWD_Notifications::template( 'Good morning, ' . $u->display_name, $body, NDWD_App::url() . '#/worksheet', 'Open worksheet' ), 'daily_summary', 'summary:' . $u->ID . ':' . $today ) ) {
				$sent++;
			}
		}
		update_option( 'ndwd_summary_done', $today, false );
		return $sent;
	}
}
