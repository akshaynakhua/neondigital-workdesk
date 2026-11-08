<?php
/**
 * Login-based attendance.
 *
 * - The `wp_login` action fires only on a successful credential login, so page
 *   refreshes and REST polling never count as logins.
 * - One row per member per Asia/Kolkata date (UNIQUE user_id + att_date), updated atomically.
 * - Meaningful work actions increment activity_count on the same row.
 * - Sessions are NOT cut at midnight: a valid sign-in carries on across IST dates.
 *   When a member is signed in on a new IST date and actually opens or uses the app
 *   (page load, or the first click/keypress after the date changed), that date is
 *   recorded as attended once (touch_session). Background polling never counts.
 *   Attendance never depends on task status or task completion.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Attendance {

	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
	}

	public static function on_login( $user_login, $user ) {
		if ( $user instanceof WP_User && user_can( $user, 'ndwd_access' ) ) {
			self::record_login( $user->ID );
		}
	}

	public static function record_login( $user_id ) {
		global $wpdb;
		$now   = NDWD_Helpers::now();
		$table = NDWD_Helpers::table( 'attendance' );
		$ts    = $now->format( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO $table (user_id, att_date, first_login, last_login, login_count, activity_count)
			 VALUES (%d, %s, %s, %s, 1, 0)
			 ON DUPLICATE KEY UPDATE login_count = login_count + 1, last_login = VALUES(last_login),
			 first_login = COALESCE(first_login, VALUES(first_login))",
			$user_id, $now->format( 'Y-m-d' ), $ts, $ts
		) );
		update_user_meta( $user_id, 'ndwd_att_seen', $now->format( 'Y-m-d' ) );
		NDWD_Activity::log( $user_id, null, 'login', '', false );
	}

	/**
	 * Marks today (IST) as attended for a member who is still signed in from an earlier
	 * day. One row per member per date (UNIQUE user_id + att_date); calling it again on
	 * the same date changes nothing, so refreshes and navigation never add duplicates.
	 *
	 * @return bool True when this call recorded the date.
	 */
	public static function touch_session( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( ! $user_id || ! user_can( $user_id, 'ndwd_access' ) || get_user_meta( $user_id, 'ndwd_inactive', true ) ) {
			return false;
		}
		$now   = NDWD_Helpers::now();
		$date  = $now->format( 'Y-m-d' );
		if ( get_user_meta( $user_id, 'ndwd_att_seen', true ) === $date ) {
			return false;
		}
		update_user_meta( $user_id, 'ndwd_att_seen', $date );
		$table = NDWD_Helpers::table( 'attendance' );
		$ts    = $now->format( 'Y-m-d H:i:s' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, login_count FROM $table WHERE user_id = %d AND att_date = %s", $user_id, $date ) ); // phpcs:ignore
		if ( $row && (int) $row->login_count > 0 ) {
			return false; // Already signed in today.
		}
		if ( $row ) {
			// A row from work actions only: the member is signed in today, so mark the date attended.
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET login_count = 1, first_login = COALESCE(first_login, %s), last_login = %s WHERE id = %d AND login_count = 0", $ts, $ts, $row->id ) ); // phpcs:ignore
		} else {
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO $table (user_id, att_date, first_login, last_login, login_count, activity_count)
				 VALUES (%d, %s, %s, %s, 1, 0)
				 ON DUPLICATE KEY UPDATE first_login = COALESCE(first_login, VALUES(first_login))",
				$user_id, $date, $ts, $ts
			) );
		}
		NDWD_Activity::log( $user_id, null, 'session_resumed', '', false );
		return true;
	}

	public static function record_activity( $user_id ) {
		global $wpdb;
		$now   = NDWD_Helpers::now();
		$table = NDWD_Helpers::table( 'attendance' );
		$ts    = $now->format( 'Y-m-d H:i:s' );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO $table (user_id, att_date, login_count, activity_count, first_activity, last_activity)
			 VALUES (%d, %s, 0, 1, %s, %s)
			 ON DUPLICATE KEY UPDATE activity_count = activity_count + 1, last_activity = VALUES(last_activity),
			 first_activity = COALESCE(first_activity, VALUES(first_activity))",
			$user_id, $now->format( 'Y-m-d' ), $ts, $ts
		) );
	}

	/**
	 * Attendance rows between two dates for the given users (null = all).
	 */
	public static function rows( $from, $to, $user_ids = null ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'attendance' );
		$sql   = "SELECT * FROM $table WHERE att_date BETWEEN %s AND %s";
		$vals  = array( $from, $to );
		if ( is_array( $user_ids ) ) {
			if ( ! $user_ids ) {
				return array();
			}
			$sql .= ' AND user_id IN (' . NDWD_Helpers::in_placeholders( $user_ids ) . ')';
			$vals = array_merge( $vals, $user_ids );
		}
		$sql .= ' ORDER BY att_date DESC, user_id';
		return $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore
	}

	/**
	 * Per-member summary. "Attendance days" = dates with at least one successful login.
	 * "Active days" = dates with at least one meaningful activity. They are reported
	 * separately: a login alone is not treated as a full working day.
	 */
	public static function summary( $from, $to, $user_ids ) {
		$out = array();
		foreach ( $user_ids as $uid ) {
			$out[ $uid ] = array(
				'user_id'         => (int) $uid,
				'name'            => NDWD_Helpers::user_name( $uid ),
				'attendance_days' => 0,
				'active_days'     => 0,
				'login_count'     => 0,
				'activity_count'  => 0,
				'leave_days'      => 0,
			);
		}
		foreach ( NDWD_Leaves::approved_days( $from, $to, array_map( 'intval', $user_ids ) ) as $uid => $days ) {
			if ( isset( $out[ $uid ] ) ) {
				$out[ $uid ]['leave_days'] = $days;
			}
		}
		foreach ( self::rows( $from, $to, $user_ids ) as $r ) {
			if ( ! isset( $out[ $r->user_id ] ) ) {
				continue;
			}
			$o = &$out[ $r->user_id ];
			if ( (int) $r->login_count > 0 ) {
				$o['attendance_days']++;
			}
			if ( (int) $r->activity_count > 0 ) {
				$o['active_days']++;
			}
			$o['login_count']    += (int) $r->login_count;
			$o['activity_count'] += (int) $r->activity_count;
			unset( $o );
		}
		return array_values( $out );
	}

	public static function format_row( $r ) {
		return array(
			'user_id'        => (int) $r->user_id,
			'name'           => NDWD_Helpers::user_name( $r->user_id ),
			'date'           => $r->att_date,
			'first_login'    => $r->first_login ? substr( $r->first_login, 11, 5 ) : null,
			'last_login'     => $r->last_login ? substr( $r->last_login, 11, 5 ) : null,
			'login_count'    => (int) $r->login_count,
			'activity_count' => (int) $r->activity_count,
			'first_activity' => $r->first_activity ? substr( $r->first_activity, 11, 5 ) : null,
			'last_activity'  => $r->last_activity ? substr( $r->last_activity, 11, 5 ) : null,
		);
	}
}
