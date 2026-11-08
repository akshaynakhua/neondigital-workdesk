<?php
/**
 * Shared helpers: Asia/Kolkata time, table names, option lists, sanitisation.
 *
 * All DATETIME columns are stored as Asia/Kolkata local time (the app is
 * single-timezone by design), independent of the WordPress timezone setting.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Helpers {

	const STATUSES = array(
		'pending'    => 'Pending',
		'in_process' => 'In Process',
		'rejected'   => 'Rejected',
		'completed'  => 'Completed',
	);

	/** Statuses that close a task: it stays on the worksheet only on the day it was closed and never carries forward. */
	const CLOSED_STATUSES = array( 'completed', 'rejected' );

	/** Retired statuses kept readable in history. v1.5 migrates stored 'blocked' tasks to 'pending' (still open, still carried). */
	const LEGACY_STATUSES = array( 'blocked' => 'Blocked' );
	const LEGACY_STATUS_TARGET = 'pending';

	public static function is_closed( $status ) {
		return in_array( $status, self::CLOSED_STATUSES, true );
	}

	/** SQL: task alias $a is open (neither completed nor rejected). */
	public static function open_sql( $a = 't' ) {
		return ( $a ? $a . '.' : '' ) . "status NOT IN ('completed','rejected')";
	}

	/** Date (Y-m-d) a task was closed — completed or rejected — or null while it is open. */
	public static function closed_date( $t ) {
		if ( ! empty( $t->completed_at ) ) {
			return substr( $t->completed_at, 0, 10 );
		}
		return ! empty( $t->rejected_at ) ? substr( $t->rejected_at, 0, 10 ) : null;
	}

	const PRIORITY_KEYS = array( 'urgent', 'high', 'medium', 'low' );

	public static function tz() {
		static $tz = null;
		if ( null === $tz ) {
			$tz = new DateTimeZone( NDWD_TZ );
		}
		return $tz;
	}

	/** Current time in Asia/Kolkata. Filterable so tests can simulate midnight roll-over. */
	public static function now() {
		$override = apply_filters( 'ndwd_now', null );
		if ( $override ) {
			return new DateTimeImmutable( $override, self::tz() );
		}
		return new DateTimeImmutable( 'now', self::tz() );
	}

	public static function today() {
		return self::now()->format( 'Y-m-d' );
	}

	public static function now_mysql() {
		return self::now()->format( 'Y-m-d H:i:s' );
	}

	/** A stored WorkDesk time (Asia/Kolkata wall time) as an ISO 8601 UTC moment. */
	public static function to_utc_iso( $mysql ) {
		try {
			return ( new DateTimeImmutable( (string) $mysql, self::tz() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
		} catch ( Exception $e ) {
			return (string) $mysql;
		}
	}

	/**
	 * One password rule for WorkDesk and Neon Workspace: at least 6 characters with an uppercase
	 * letter, a lowercase letter and a number. @return string Empty when fine.
	 */
	public static function password_problem( $p ) {
		$p = (string) $p;
		if ( strlen( $p ) < 6 || ! preg_match( '/[A-Z]/', $p ) || ! preg_match( '/[a-z]/', $p ) || ! preg_match( '/[0-9]/', $p ) ) {
			return 'Use at least 6 characters, with an uppercase letter, a lowercase letter and a number.';
		}
		return '';
	}

	/** The same rule on WordPress's reset-password and profile screens. Hashing is unchanged. */
	public static function password_hooks() {
		add_action( 'validate_password_reset', function ( $errors ) {
			$p = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore
			if ( '' !== $p && self::password_problem( $p ) ) {
				$errors->add( 'ndwd_weak_password', self::password_problem( $p ) );
			}
		} );
		add_action( 'user_profile_update_errors', function ( $errors ) {
			$p = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : ''; // phpcs:ignore
			if ( '' !== $p && self::password_problem( $p ) ) {
				$errors->add( 'ndwd_weak_password', self::password_problem( $p ) );
			}
		} );
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'ndwd_' . $name;
	}

	public static function valid_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $value ) );
		return checkdate( $m, $d, $y );
	}

	public static function sanitize_date( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		return self::valid_date( $value ) ? $value : null;
	}

	public static function sanitize_time( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value, $m ) ) {
			return sprintf( '%02d:%02d:00', (int) $m[1], (int) $m[2] );
		}
		return null;
	}

	/** Accepts "Y-m-d H:i" or "Y-m-dTH:i" (datetime-local input). */
	public static function sanitize_datetime( $value ) {
		$value = is_string( $value ) ? trim( str_replace( 'T', ' ', $value ) ) : '';
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2}) (\d{1,2}:\d{2})(:\d{2})?$/', $value, $m ) && self::valid_date( $m[1] ) ) {
			$t = self::sanitize_time( $m[2] );
			return $t ? $m[1] . ' ' . $t : null;
		}
		return null;
	}

	/** Only absolute http/https URLs are accepted. */
	public static function sanitize_link_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( ! $clean || ! filter_var( $clean, FILTER_VALIDATE_URL ) || ! preg_match( '#^https?://#i', $clean ) ) {
			return null;
		}
		$host = wp_parse_url( $clean, PHP_URL_HOST );
		return $host ? $clean : null;
	}

	public static function priorities() {
		$defaults = array( 'urgent' => 'Urgent', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low' );
		$saved    = get_option( 'ndwd_priority_labels', array() );
		foreach ( self::PRIORITY_KEYS as $k ) {
			if ( ! empty( $saved[ $k ] ) ) {
				$defaults[ $k ] = $saved[ $k ];
			}
		}
		return $defaults;
	}

	public static function work_types() {
		$types = get_option( 'ndwd_work_types' );
		if ( ! is_array( $types ) || ! $types ) {
			$types = array( 'office' => 'Office', 'field' => 'Field / On-site', 'remote' => 'Remote', 'other' => 'Other' );
		}
		return $types;
	}

	public static function status_label( $s ) {
		if ( isset( self::STATUSES[ $s ] ) ) {
			return self::STATUSES[ $s ];
		}
		return isset( self::LEGACY_STATUSES[ $s ] ) ? self::LEGACY_STATUSES[ $s ] : $s;
	}

	public static function priority_label( $p ) {
		$all = self::priorities();
		return isset( $all[ $p ] ) ? $all[ $p ] : $p;
	}

	public static function work_type_label( $w ) {
		$all = self::work_types();
		return isset( $all[ $w ] ) ? $all[ $w ] : $w;
	}

	public static function int_list( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
	}

	public static function in_placeholders( $list ) {
		return implode( ',', array_fill( 0, count( $list ), '%d' ) );
	}

	public static function user_name( $user_id ) {
		static $cache = array();
		$user_id = (int) $user_id;
		if ( ! isset( $cache[ $user_id ] ) ) {
			$u                  = $user_id ? get_userdata( $user_id ) : null;
			$cache[ $user_id ] = $u ? $u->display_name : 'Unknown';
		}
		return $cache[ $user_id ];
	}

	/** Month boundaries for "YYYY-MM". */
	public static function month_range( $ym ) {
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $ym . '-01', self::tz() );
		if ( ! $start ) {
			return null;
		}
		return array( $start->format( 'Y-m-d' ), $start->modify( 'last day of this month' )->format( 'Y-m-d' ) );
	}

	/** Returns [from, to] for daily/weekly/monthly periods around $date (weeks start Monday). */
	public static function period_range( $period, $date ) {
		$d = self::valid_date( $date ) ? new DateTimeImmutable( $date, self::tz() ) : self::now();
		if ( 'weekly' === $period ) {
			$start = $d->modify( 'monday this week' );
			return array( $start->format( 'Y-m-d' ), $start->modify( '+6 days' )->format( 'Y-m-d' ) );
		}
		if ( 'monthly' === $period ) {
			return array( $d->format( 'Y-m-01' ), $d->format( 'Y-m-t' ) );
		}
		return array( $d->format( 'Y-m-d' ), $d->format( 'Y-m-d' ) );
	}
}
