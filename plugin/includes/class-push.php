<?php
/**
 * Mobile push notifications (PWA + standard Web Push), on top of the existing notifications.
 *
 * Every in-app notification is created by NDWD_Notifications::create_in_app(); a new row there adds one
 * push_queue row per device of that recipient, and the existing scheduler sends the queue on each run.
 * So a dedupe key already taken creates no push either, and in-app, email and push stay independent.
 * The queue row is unique per notification and device, and is claimed with one conditional UPDATE;
 * temporary failures wait and retry, and a subscription the push service reports as gone is removed
 * without touching the notification history.
 *
 * Endpoints and keys stay on the server: the API never returns them, and errors name only the host.
 * Lock-screen text is generic by default; a member may opt in to task titles.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Push {

	const OPTION_KEYS  = 'ndwd_vapid_keys';
	const META_OFF     = 'ndwd_push_off';
	const META_PREFS   = 'ndwd_push_prefs';
	const META_DETAILS = 'ndwd_push_details';

	const MAX_ATTEMPTS = 6;
	const MAX_DEVICES  = 20;
	const FAIL_LIMIT   = 5;     // Consecutive permanent refusals before a device is removed.
	const TTL          = 86400; // Seconds a push service keeps an undelivered message; also the queue's limit.
	const BATCH        = 100;
	const RUN_SECONDS  = 60;    // Below the scheduler's lock, however slow the network is.
	const STALE        = 300;   // Seconds before a claim from a crashed run can be taken over.

	/** Preference categories, all on by default. */
	const CATEGORIES = array(
		'assignments'    => 'New task assignments',
		'member_handoff' => 'Internal member handoffs',
		'team_handoff'   => 'Team-to-team handoffs',
		'team_available' => 'Unassigned team tasks available',
		'pickups'        => 'Task pickups and reassignments',
		'updates'        => 'New remarks and important updates',
		'due'            => 'Due-date reminders and overdue tasks',
		'reminders'      => 'Scheduled reminders and follow-ups',
		'alerts'         => 'Approvals and important alerts',
	);

	/** Notification type or explicit event => [category, title, lock-screen text, urgency]. %s is " (#id)". */
	const EVENTS = array(
		'assigned'       => array( 'assignments', 'New task assigned', 'You have a new task%s.', 'high' ),
		'urgent'         => array( 'assignments', 'Urgent task', 'An urgent task needs your attention%s.', 'high' ),
		'member_handoff' => array( 'member_handoff', 'Task handed off', 'A task handoff involves you%s.', 'high' ),
		'team_handoff'   => array( 'team_handoff', 'Team handoff', 'A task moved between teams%s.', 'high' ),
		'team_available' => array( 'team_available', 'Team task available', 'A team task is available to pick up%s.', 'normal' ),
		'pickup'         => array( 'pickups', 'Task picked up', 'A team task was picked up%s.', 'normal' ),
		'release'        => array( 'pickups', 'Task released', 'A task went back to the team pool%s.', 'normal' ),
		'reassigned'     => array( 'pickups', 'Task reassigned', 'A task was reassigned to you%s.', 'high' ),
		'unassigned'     => array( 'pickups', 'Task reassigned', 'A task was moved away from you%s.', 'normal' ),
		'remark'         => array( 'updates', 'New remark', 'A task has a new remark%s.', 'normal' ),
		'updated'        => array( 'updates', 'Task updated', 'A task was updated%s.', 'normal' ),
		'status'         => array( 'updates', 'Task status changed', 'A task changed status%s.', 'normal' ),
		'due'            => array( 'due', 'Task due soon', 'A task is due soon%s.', 'high' ),
		'overdue'        => array( 'due', 'Task overdue', 'A task is overdue%s.', 'high' ),
		'reminder'       => array( 'reminders', 'Reminder', 'You have a scheduled reminder%s.', 'high' ),
		'followup'       => array( 'reminders', 'New follow-up', 'A follow-up was created for you%s.', 'normal' ),
		'client_request' => array( 'assignments', 'New client request', 'A client sent a new request%s.', 'high' ),
		'client_message' => array( 'updates', 'New client message', 'A client sent you a message%s.', 'high' ),
		'escalated'      => array( 'pickups', 'Client request escalated', 'A client request was escalated%s.', 'high' ),
		'leave'          => array( 'alerts', 'Leave update', 'There is a leave request or decision to review.', 'normal' ),
	);
	const DEFAULT_EVENT = array( 'alerts', 'WorkDesk alert', 'There is an update for you in WorkDesk.', 'normal' );

	/** Hosts of the browsers' own push services. The server only ever posts to these. */
	const PUSH_HOSTS = array( 'fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 1 );
		add_action( 'ndwd_head', array( __CLASS__, 'head' ), 5 );
		add_action( 'delete_user', array( __CLASS__, 'forget_user' ) );
	}

	public static function routes() {
		$ns     = NDWD_REST::NS;
		$member = array( 'NDWD_REST', 'member' );
		foreach ( array(
			array( '/me/push', 'GET', 'rest_state' ),
			array( '/me/push/settings', 'POST', 'rest_settings' ),
			array( '/me/push/subscribe', 'POST', 'rest_subscribe' ),
			array( '/me/push/check', 'POST', 'rest_check' ),
			array( '/me/push/test', 'POST', 'rest_test' ),
			array( '/me/push/devices/(?P<id>\d+)', 'DELETE', 'rest_remove' ),
		) as $r ) {
			register_rest_route( $ns, $r[0], array( 'methods' => $r[1], 'callback' => array( __CLASS__, $r[2] ), 'permission_callback' => $member ) );
		}
		// A browser may replace its subscription with no page open. The service worker has no sign-in, so
		// it proves ownership with the old endpoint and auth secret, which only that browser and we hold.
		register_rest_route( $ns, '/push/rotate', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_rotate' ), 'permission_callback' => '__return_true' ) );
	}

	/* ------------------------------------------------------------------ *
	 * PWA files
	 * ------------------------------------------------------------------ */

	/** Path the service worker controls: the whole site, so the app works at / or /workdesk/. */
	public static function scope() {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return $path ? $path : '/';
	}

	public static function sw_url() {
		return add_query_arg( 'ndwd_sw', '1', home_url( '/' ) );
	}

	public static function manifest_url() {
		return add_query_arg( 'ndwd_manifest', '1', home_url( '/' ) );
	}

	/**
	 * Serves the service worker and manifest from the site root. A worker's scope cannot be wider than
	 * its own folder, so it cannot run from the plugin folder. Both are public and hold no user data.
	 */
	public static function serve() {
		if ( isset( $_GET['ndwd_sw'] ) ) { // phpcs:ignore
			self::no_cache();
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Service-Worker-Allowed: ' . self::scope() );
			readfile( NDWD_DIR . 'assets/js/sw.js' ); // phpcs:ignore
			exit;
		}
		if ( isset( $_GET['ndwd_manifest'] ) ) { // phpcs:ignore
			self::no_cache();
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( self::manifest(), JSON_UNESCAPED_SLASHES ); // phpcs:ignore
			exit;
		}
	}

	private static function no_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Page-cache plugins on shared hosting must not keep these.
		}
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
	}

	public static function manifest() {
		$icon = function ( $file, $size, $purpose ) {
			return array( 'src' => NDWD_URL . 'assets/img/pwa/' . $file, 'sizes' => $size . 'x' . $size, 'type' => 'image/png', 'purpose' => $purpose );
		};
		return array(
			'id'               => self::scope(),
			'name'             => 'NeonDigital WorkDesk',
			'short_name'       => 'WorkDesk',
			'description'      => 'Tasks, handoffs, reminders and team work for NeonDigital.',
			'start_url'        => NDWD_App::url(),
			'scope'            => self::scope(),
			'display'          => 'standalone',
			'orientation'      => 'any',
			'background_color' => '#111214',
			'theme_color'      => '#111214',
			'icons'            => array(
				$icon( 'icon-192.png', 192, 'any' ),
				$icon( 'icon-512.png', 512, 'any' ),
				$icon( 'maskable-192.png', 192, 'maskable' ),
				$icon( 'maskable-512.png', 512, 'maskable' ),
			),
		);
	}

	/** Also on the sign-in screen, so WorkDesk can be installed before signing in. */
	public static function head() {
		echo '<link rel="manifest" href="' . esc_url( self::manifest_url() ) . '">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="WorkDesk"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
		if ( ! file_exists( get_template_directory() . '/assets/img/apple-touch-icon.png' ) ) {
			echo '<link rel="apple-touch-icon" href="' . esc_url( NDWD_URL . 'assets/img/pwa/icon-192.png' ) . '">' . "\n";
		}
	}

	/* ------------------------------------------------------------------ *
	 * VAPID keys
	 * ------------------------------------------------------------------ */

	/**
	 * The key pair: from wp-config.php when defined there, otherwise created once and kept in a
	 * non-autoloaded option that no API returns.
	 *
	 * @return array{public:string,private:string,source:string}|null
	 */
	public static function keys() {
		if ( ! NDWD_WebPush::available() ) {
			return null;
		}
		if ( defined( 'NDWD_VAPID_PUBLIC_KEY' ) && defined( 'NDWD_VAPID_PRIVATE_KEY' ) ) {
			return NDWD_WebPush::valid_pair( NDWD_VAPID_PUBLIC_KEY, NDWD_VAPID_PRIVATE_KEY ) ? array( 'public' => NDWD_VAPID_PUBLIC_KEY, 'private' => NDWD_VAPID_PRIVATE_KEY, 'source' => 'wp-config' ) : null;
		}
		global $wpdb;
		$read = function () use ( $wpdb ) {
			$row = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", self::OPTION_KEYS ) );
			$row = $row ? json_decode( $row, true ) : null;
			return is_array( $row ) && ! empty( $row['public'] ) && ! empty( $row['private'] ) ? $row : null;
		};
		$k = $read();
		if ( ! $k ) {
			try {
				$new = NDWD_WebPush::generate_keys();
			} catch ( Throwable $e ) {
				error_log( 'NDWD push: ' . $e->getMessage() ); // phpcs:ignore
				return null;
			}
			// One atomic insert decides, so two first requests can never leave different keys in use.
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $wpdb->options (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION_KEYS, wp_json_encode( $new ) ) );
			wp_cache_delete( 'notoptions', 'options' );
			$k = $read();
		}
		return $k ? array( 'public' => $k['public'], 'private' => $k['private'], 'source' => 'database' ) : null;
	}

	/** Contact the push services see. Apple refuses a token without a real one. */
	public static function subject() {
		if ( defined( 'NDWD_VAPID_SUBJECT' ) && NDWD_VAPID_SUBJECT ) {
			return NDWD_VAPID_SUBJECT;
		}
		$s    = NDWD_Notifications::settings();
		$mail = ! empty( $s['email_from'] ) && is_email( $s['email_from'] ) ? $s['email_from'] : get_option( 'admin_email' );
		return 'mailto:' . $mail;
	}

	/* ------------------------------------------------------------------ *
	 * Member preferences
	 * ------------------------------------------------------------------ */

	public static function prefs( $uid ) {
		$saved = get_user_meta( $uid, self::META_PREFS, true );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( array_keys( self::CATEGORIES ) as $c ) {
			$out[ $c ] = ! isset( $saved[ $c ] ) || (bool) $saved[ $c ];
		}
		return $out;
	}

	public static function event_info( $event ) {
		return isset( self::EVENTS[ $event ] ) ? self::EVENTS[ $event ] : self::DEFAULT_EVENT;
	}

	public static function wants( $uid, $event ) {
		if ( get_user_meta( $uid, self::META_OFF, true ) ) {
			return false;
		}
		$prefs = self::prefs( $uid );
		return ! empty( $prefs[ self::event_info( $event )[0] ] );
	}

	/* ------------------------------------------------------------------ *
	 * Queue
	 * ------------------------------------------------------------------ */

	/**
	 * Queues one notification for each device of its recipient. Called once per new in-app row.
	 *
	 * @return int Rows queued.
	 */
	public static function enqueue( $notification_id, $uid, $event ) {
		global $wpdb;
		if ( ! $notification_id || ! self::wants( $uid, $event ) ) {
			return 0;
		}
		$q   = NDWD_Helpers::table( 'push_queue' );
		$now = NDWD_Helpers::now_mysql();
		$n   = 0;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'push_subscriptions' ) . ' WHERE user_id = %d', $uid ) ) as $sid ) { // phpcs:ignore
			$n += (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $q (notification_id, subscription_id, user_id, event, status, attempts, next_attempt_at, created_at) VALUES (%d, %d, %d, %s, 'pending', 0, %s, %s)", $notification_id, $sid, $uid, $event, $now, $now ) ); // phpcs:ignore
		}
		return $n;
	}

	/** Scheduler job: sends what is due. @return array Counts by outcome. */
	public static function process_queue( $limit = self::BATCH ) {
		global $wpdb;
		$out  = array( 'sent' => 0, 'retry' => 0, 'failed' => 0, 'skipped' => 0, 'removed' => 0, 'expired' => 0 );
		$t    = NDWD_Helpers::table( 'push_queue' );
		$now  = NDWD_Helpers::now_mysql();
		$old  = NDWD_Helpers::now()->modify( '-' . self::STALE . ' seconds' )->format( 'Y-m-d H:i:s' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE ( status IN ('pending','retry') AND next_attempt_at <= %s ) OR ( status = 'sending' AND claimed_at < %s ) ORDER BY id LIMIT %d", $now, $old, $limit ) ); // phpcs:ignore
		if ( ! $rows ) {
			return $out;
		}
		$keys  = self::keys();
		$start = microtime( true );
		foreach ( $rows as $row ) {
			if ( ! $keys || microtime( true ) - $start > self::RUN_SECONDS ) {
				break; // Whatever is left stays queued for the next run.
			}
			$won = $wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'sending', claimed_at = %s, attempts = attempts + 1 WHERE id = %d AND status = %s AND attempts = %d", $now, $row->id, $row->status, $row->attempts ) ); // phpcs:ignore
			if ( $won ) {
				$out[ self::deliver_row( $row, $keys ) ]++;
			}
		}
		return $out;
	}

	/** @return string sent, retry, failed, skipped, removed or expired. */
	private static function deliver_row( $row, $keys ) {
		global $wpdb;
		$t      = NDWD_Helpers::table( 'push_queue' );
		$finish = function ( $status, $error = null ) use ( $wpdb, $t, $row ) {
			$wpdb->update( $t, array( 'status' => $status, 'last_error' => $error, 'finished_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $row->id ) );
			return $status;
		};
		$sub = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'push_subscriptions' ) . ' WHERE id = %d', $row->subscription_id ) ); // phpcs:ignore
		$n   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'notifications' ) . ' WHERE id = %d', $row->notification_id ) ); // phpcs:ignore
		// Checked again at send time: the device may have changed hands, or the member been deactivated.
		if ( ! $sub || ! $n || (int) $sub->user_id !== (int) $n->user_id ) {
			return $finish( 'skipped', 'Device or notification no longer matches.' );
		}
		$uid = (int) $n->user_id;
		if ( ! NDWD_Permissions::is_active( $uid ) || ! user_can( $uid, 'ndwd_access' ) || ! self::wants( $uid, $row->event ) ) {
			return $finish( 'skipped', 'Recipient no longer receives this.' );
		}
		if ( $n->deleted_at || $n->is_read ) {
			return $finish( 'skipped', 'Already read or removed in the app.' );
		}
		if ( ( new DateTimeImmutable( $row->created_at, NDWD_Helpers::tz() ) )->getTimestamp() < NDWD_Helpers::now()->getTimestamp() - self::TTL ) {
			return $finish( 'expired', 'Too old to be useful.' );
		}
		$res = self::post( $sub, self::payload( $n, $row->event, $uid ), $keys, self::event_info( $row->event )[3], 'n' . $n->id );
		if ( 'sent' === $res['result'] ) {
			return $finish( 'sent' );
		}
		if ( 'gone' === $res['result'] ) {
			self::delete_subscription( $sub->id );
			return 'removed'; // The queue rows went with the device.
		}
		$attempts = (int) $row->attempts + 1;
		if ( 'retry' === $res['result'] && $attempts < self::MAX_ATTEMPTS ) {
			$wait = max( (int) $res['retry_after'], min( HOUR_IN_SECONDS, 60 * pow( 2, $attempts ) ) );
			$wpdb->update( $t, array( 'status' => 'retry', 'last_error' => $res['error'], 'next_attempt_at' => NDWD_Helpers::now()->modify( '+' . (int) $wait . ' seconds' )->format( 'Y-m-d H:i:s' ) ), array( 'id' => $row->id ) );
			return 'retry';
		}
		return $finish( 'failed', $res['error'] );
	}

	/** What the device shows. Encrypted for that device only; generic unless the member opted in to titles. */
	public static function payload( $n, $event, $uid ) {
		$info = self::event_info( $event );
		$ref  = $n->task_id ? ' (#' . (int) $n->task_id . ')' : '';
		$body = get_user_meta( $uid, self::META_DETAILS, true ) ? mb_substr( $n->message, 0, 140 ) : sprintf( $info[2], $ref );
		return array(
			'title' => $info[1],
			'body'  => $body,
			'url'   => NDWD_App::url() . ( '' !== (string) $n->link ? $n->link : '#/dashboard' ),
			'tag'   => 'ndwd-n' . (int) $n->id, // The same tag replaces, so a repeat send can never show twice.
			'icon'  => NDWD_URL . 'assets/img/pwa/icon-192.png',
			'badge' => NDWD_URL . 'assets/img/pwa/badge-96.png',
		);
	}

	/**
	 * One encrypted POST to the browser's push service.
	 *
	 * @return array{result:string,error:?string,retry_after:int} result: sent, gone, retry or failed.
	 */
	private static function post( $sub, $payload, $keys, $urgency, $topic ) {
		global $wpdb;
		$host = (string) wp_parse_url( $sub->endpoint, PHP_URL_HOST );
		$subs = NDWD_Helpers::table( 'push_subscriptions' );
		try {
			$body = NDWD_WebPush::encrypt( wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), NDWD_WebPush::b64u_decode( $sub->p256dh ), NDWD_WebPush::b64u_decode( $sub->auth ) );
			$auth = NDWD_WebPush::vapid_header( $sub->endpoint, $keys['public'], $keys['private'], self::subject() );
		} catch ( Throwable $e ) {
			return array( 'result' => 'failed', 'error' => $e->getMessage(), 'retry_after' => 0 );
		}
		$resp = wp_safe_remote_post( $sub->endpoint, array(
			'timeout'     => 8,
			'redirection' => 0,
			'headers'     => array(
				'Authorization'    => $auth,
				'TTL'              => (string) self::TTL,
				'Urgency'          => $urgency,
				'Topic'            => $topic,
				'Content-Type'     => 'application/octet-stream',
				'Content-Encoding' => 'aes128gcm',
			),
			'body'        => $body,
		) );
		if ( is_wp_error( $resp ) ) {
			return array( 'result' => 'retry', 'error' => 'Could not reach ' . $host . '.', 'retry_after' => 0 );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code >= 200 && $code < 300 ) {
			$wpdb->update( $subs, array( 'last_success_at' => NDWD_Helpers::now_mysql(), 'fail_count' => 0 ), array( 'id' => $sub->id ) );
			return array( 'result' => 'sent', 'error' => null, 'retry_after' => 0 );
		}
		if ( 404 === $code || 410 === $code ) {
			return array( 'result' => 'gone', 'error' => 'HTTP ' . $code . ' from ' . $host, 'retry_after' => 0 );
		}
		$error = 'HTTP ' . $code . ' from ' . $host;
		if ( 408 === $code || 429 === $code || $code >= 500 ) {
			$after = wp_remote_retrieve_header( $resp, 'retry-after' );
			return array( 'result' => 'retry', 'error' => $error, 'retry_after' => is_numeric( $after ) ? min( 6 * HOUR_IN_SECONDS, (int) $after ) : 0 );
		}
		// Refused outright (400, 401, 403, 413…). Repeated refusals mean this device can't be reached.
		$fails = (int) $sub->fail_count + 1;
		if ( $fails >= self::FAIL_LIMIT ) {
			return array( 'result' => 'gone', 'error' => $error, 'retry_after' => 0 );
		}
		$wpdb->update( $subs, array( 'fail_count' => $fails ), array( 'id' => $sub->id ) );
		return array( 'result' => 'failed', 'error' => $error, 'retry_after' => 0 );
	}

	/** Removes a device and its queue rows. The notification history is a separate table and stays. */
	public static function delete_subscription( $id ) {
		global $wpdb;
		$wpdb->delete( NDWD_Helpers::table( 'push_queue' ), array( 'subscription_id' => (int) $id ) );
		$wpdb->delete( NDWD_Helpers::table( 'push_subscriptions' ), array( 'id' => (int) $id ) );
	}

	public static function forget_user( $uid ) {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'push_subscriptions' ) . ' WHERE user_id = %d', $uid ) ) as $sid ) { // phpcs:ignore
			self::delete_subscription( $sid );
		}
	}

	/** Scheduler job: finished queue rows are only working data, kept a week for the status card. */
	public static function prune() {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NDWD_Helpers::table( 'push_queue' ) . " WHERE status IN ('sent','failed','skipped','expired') AND finished_at < %s", NDWD_Helpers::now()->modify( '-7 days' )->format( 'Y-m-d H:i:s' ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Subscriptions
	 * ------------------------------------------------------------------ */

	/** Only https URLs of a known push service: the server posts to this URL, so it must not be free-form. */
	public static function valid_endpoint( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 1000 || ! wp_http_validate_url( $url ) ) {
			return false;
		}
		$p = wp_parse_url( $url );
		if ( empty( $p['scheme'] ) || 'https' !== $p['scheme'] || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['port'] ) ) {
			return false;
		}
		$host = strtolower( $p['host'] );
		foreach ( (array) apply_filters( 'ndwd_push_hosts', self::PUSH_HOSTS ) as $allowed ) {
			if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
				return true;
			}
		}
		return false;
	}

	/** @return array|WP_Error Validated endpoint, p256dh, auth from a browser's PushSubscription JSON. */
	private static function read_subscription( $b ) {
		$endpoint = isset( $b['endpoint'] ) ? $b['endpoint'] : '';
		$p256dh   = isset( $b['keys']['p256dh'] ) ? $b['keys']['p256dh'] : '';
		$auth     = isset( $b['keys']['auth'] ) ? $b['keys']['auth'] : '';
		if ( ! self::valid_endpoint( $endpoint ) ) {
			return new WP_Error( 'ndwd_push_endpoint', 'This browser\'s push service is not supported.', array( 'status' => 400 ) );
		}
		$raw_auth = NDWD_WebPush::b64u_decode( $auth );
		if ( ! NDWD_WebPush::valid_public( NDWD_WebPush::b64u_decode( $p256dh ) ) || ! $raw_auth || 16 !== strlen( $raw_auth ) ) {
			return new WP_Error( 'ndwd_push_keys', 'The browser sent an invalid subscription.', array( 'status' => 400 ) );
		}
		return array( 'endpoint' => $endpoint, 'hash' => hash( 'sha256', $endpoint ), 'p256dh' => $p256dh, 'auth' => $auth );
	}

	private static function find_by_endpoint( $endpoint ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'push_subscriptions' ) . ' WHERE endpoint_hash = %s', hash( 'sha256', (string) $endpoint ) ) ); // phpcs:ignore
	}

	/** One member's devices, without endpoints or keys. */
	public static function devices( $uid ) {
		global $wpdb;
		return array_map( function ( $d ) {
			return array(
				'id'              => (int) $d->id,
				'label'           => $d->label,
				'created_at'      => $d->created_at,
				'last_seen_at'    => $d->last_seen_at,
				'last_success_at' => $d->last_success_at,
			);
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT id, label, created_at, last_seen_at, last_success_at FROM ' . NDWD_Helpers::table( 'push_subscriptions' ) . ' WHERE user_id = %d ORDER BY id', $uid ) ) ); // phpcs:ignore
	}

	/**
	 * Saves this browser's subscription for $uid. A device that signs in as someone else moves to them
	 * only if it proves it holds the same keys; knowing an endpoint is not enough.
	 *
	 * @return object|WP_Error The device row.
	 */
	public static function subscribe( $uid, $body ) {
		global $wpdb;
		$s = self::read_subscription( $body );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$t     = NDWD_Helpers::table( 'push_subscriptions' );
		$now   = NDWD_Helpers::now_mysql();
		$label = isset( $body['label'] ) ? mb_substr( sanitize_text_field( (string) $body['label'] ), 0, 100 ) : '';
		$label = '' !== $label ? $label : 'Device';
		$row   = self::find_by_endpoint( $s['endpoint'] );
		if ( $row && (int) $row->user_id !== (int) $uid && ! ( hash_equals( $row->auth, $s['auth'] ) && hash_equals( $row->p256dh, $s['p256dh'] ) ) ) {
			return new WP_Error( 'ndwd_push_owner', 'This subscription cannot be used.', array( 'status' => 403 ) );
		}
		if ( ! $row ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE user_id = %d", $uid ) ); // phpcs:ignore
			if ( $count >= self::MAX_DEVICES ) {
				return new WP_Error( 'ndwd_push_limit', 'You have too many devices registered. Remove one first.', array( 'status' => 409 ) );
			}
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (user_id, endpoint, endpoint_hash, p256dh, auth, label, fail_count, created_at, last_seen_at) VALUES (%d, %s, %s, %s, %s, %s, 0, %s, %s)", $uid, $s['endpoint'], $s['hash'], $s['p256dh'], $s['auth'], $label, $now, $now ) ); // phpcs:ignore
		} else {
			if ( (int) $row->user_id !== (int) $uid ) {
				// Whatever was queued for the previous person must not reach the next one.
				$wpdb->delete( NDWD_Helpers::table( 'push_queue' ), array( 'subscription_id' => (int) $row->id ) );
			}
			$wpdb->update( $t, array( 'user_id' => $uid, 'p256dh' => $s['p256dh'], 'auth' => $s['auth'], 'label' => $label, 'fail_count' => 0, 'last_seen_at' => $now ), array( 'id' => $row->id ) );
		}
		return self::find_by_endpoint( $s['endpoint'] );
	}

	/* ------------------------------------------------------------------ *
	 * REST
	 * ------------------------------------------------------------------ */

	private static function state( $uid ) {
		$keys = self::keys();
		$cats = array();
		foreach ( self::CATEGORIES as $k => $label ) {
			$cats[] = array( 'key' => $k, 'label' => $label );
		}
		return array(
			'configured'   => (bool) $keys,
			'public_key'   => $keys ? $keys['public'] : '',
			'enabled'      => ! get_user_meta( $uid, self::META_OFF, true ),
			'show_details' => (bool) get_user_meta( $uid, self::META_DETAILS, true ),
			'prefs'        => self::prefs( $uid ),
			'categories'   => $cats,
			'devices'      => self::devices( $uid ),
		);
	}

	public static function rest_state() {
		return rest_ensure_response( self::state( get_current_user_id() ) );
	}

	public static function rest_settings( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$b   = (array) $r->get_json_params();
		if ( isset( $b['enabled'] ) ) {
			if ( $b['enabled'] ) {
				delete_user_meta( $uid, self::META_OFF );
			} else {
				update_user_meta( $uid, self::META_OFF, 1 );
			}
		}
		if ( isset( $b['show_details'] ) ) {
			if ( $b['show_details'] ) {
				update_user_meta( $uid, self::META_DETAILS, 1 );
			} else {
				delete_user_meta( $uid, self::META_DETAILS );
			}
		}
		if ( isset( $b['prefs'] ) && is_array( $b['prefs'] ) ) {
			$p = self::prefs( $uid );
			foreach ( array_keys( self::CATEGORIES ) as $c ) {
				if ( isset( $b['prefs'][ $c ] ) ) {
					$p[ $c ] = (bool) $b['prefs'][ $c ];
				}
			}
			update_user_meta( $uid, self::META_PREFS, $p );
		}
		return rest_ensure_response( self::state( $uid ) );
	}

	public static function rest_subscribe( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$row = self::subscribe( $uid, (array) $r->get_json_params() );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return rest_ensure_response( array( 'device_id' => (int) $row->id ) + self::state( $uid ) );
	}

	/**
	 * Called when the app opens with a subscription in the browser. "mine" keeps it; "unknown" tells the
	 * browser to drop it, which is how a removal from another device reaches this one. A device now signed
	 * in as someone else stops receiving the previous person's notifications here.
	 */
	public static function rest_check( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$b   = (array) $r->get_json_params();
		$row = self::find_by_endpoint( isset( $b['endpoint'] ) ? $b['endpoint'] : '' );
		$own = $row && hash_equals( $row->auth, isset( $b['auth'] ) ? (string) $b['auth'] : '' );
		if ( $own && (int) $row->user_id === (int) $uid ) {
			$wpdb->update( NDWD_Helpers::table( 'push_subscriptions' ), array( 'last_seen_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $row->id ) );
			return rest_ensure_response( array( 'status' => 'mine', 'device_id' => (int) $row->id ) );
		}
		if ( $own ) {
			self::delete_subscription( $row->id );
		}
		return rest_ensure_response( array( 'status' => 'unknown' ) );
	}

	public static function rest_remove( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$id  = (int) $r['id'];
		// The owner is part of the lookup: someone else's id looks exactly like one that does not exist.
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'push_subscriptions' ) . ' WHERE id = %d AND user_id = %d', $id, $uid ) ) ) { // phpcs:ignore
			return new WP_Error( 'ndwd_not_found', 'Device not found.', array( 'status' => 404 ) );
		}
		self::delete_subscription( $id );
		return rest_ensure_response( self::state( $uid ) );
	}

	/** Sends straight away to the member's own devices, for checking a device. */
	public static function rest_test() {
		global $wpdb;
		$uid  = get_current_user_id();
		$keys = self::keys();
		if ( ! $keys ) {
			return new WP_Error( 'ndwd_push_config', 'Push is not configured on this server.', array( 'status' => 503 ) );
		}
		$out = array( 'sent' => 0, 'failed' => 0, 'removed' => 0, 'error' => null );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'push_subscriptions' ) . ' WHERE user_id = %d', $uid ) ) as $sub ) { // phpcs:ignore
			$res = self::post( $sub, array( 'title' => 'WorkDesk test', 'body' => 'Push notifications work on this device.', 'url' => NDWD_App::url() . '#/profile', 'tag' => 'ndwd-test', 'icon' => NDWD_URL . 'assets/img/pwa/icon-192.png', 'badge' => NDWD_URL . 'assets/img/pwa/badge-96.png' ), $keys, 'high', 'test' );
			if ( 'sent' === $res['result'] ) {
				$out['sent']++;
			} elseif ( 'gone' === $res['result'] ) {
				self::delete_subscription( $sub->id );
				$out['removed']++;
			} else {
				$out['failed']++;
				$out['error'] = $res['error'];
			}
		}
		return rest_ensure_response( $out + self::state( $uid ) );
	}

	public static function rest_rotate( WP_REST_Request $r ) {
		global $wpdb;
		$b   = (array) $r->get_json_params();
		$row = self::find_by_endpoint( isset( $b['old_endpoint'] ) ? $b['old_endpoint'] : '' );
		$s   = self::read_subscription( $b );
		if ( ! $row || is_wp_error( $s ) || ! hash_equals( $row->auth, isset( $b['old_auth'] ) ? (string) $b['old_auth'] : '' ) ) {
			return new WP_Error( 'ndwd_not_found', 'Unknown subscription.', array( 'status' => 404 ) );
		}
		$taken = self::find_by_endpoint( $s['endpoint'] );
		if ( $taken && (int) $taken->id !== (int) $row->id ) {
			return new WP_Error( 'ndwd_push_owner', 'This subscription cannot be used.', array( 'status' => 403 ) );
		}
		$wpdb->update( NDWD_Helpers::table( 'push_subscriptions' ), array( 'endpoint' => $s['endpoint'], 'endpoint_hash' => $s['hash'], 'p256dh' => $s['p256dh'], 'auth' => $s['auth'], 'fail_count' => 0, 'last_seen_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $row->id ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Compact state for Settings. Holds no key material. */
	public static function status() {
		global $wpdb;
		$keys  = self::keys();
		$q     = NDWD_Helpers::table( 'push_queue' );
		$subs  = NDWD_Helpers::table( 'push_subscriptions' );
		$since = NDWD_Helpers::now()->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		$count = function ( $where, $arg = null ) use ( $wpdb, $q ) {
			$sql = "SELECT COUNT(*) FROM $q WHERE $where";
			return (int) $wpdb->get_var( null === $arg ? $sql : $wpdb->prepare( $sql, $arg ) ); // phpcs:ignore
		};
		return array(
			'configured' => (bool) $keys,
			'source'     => $keys ? $keys['source'] : '',
			'subject'    => self::subject(),
			'https'      => 0 === strpos( home_url( '/' ), 'https://' ),
			'devices'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $subs" ), // phpcs:ignore
			'members'    => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM $subs" ), // phpcs:ignore
			'queued'     => $count( "status IN ('pending','retry','sending')" ),
			'sent_24h'   => $count( "status = 'sent' AND finished_at >= %s", $since ),
			'failed_24h' => $count( "status = 'failed' AND finished_at >= %s", $since ),
			'last_error' => (string) $wpdb->get_var( "SELECT last_error FROM $q WHERE status IN ('failed','retry') AND last_error IS NOT NULL ORDER BY id DESC LIMIT 1" ), // phpcs:ignore
		);
	}
}
