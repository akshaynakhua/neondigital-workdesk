<?php
/**
 * Dashboard and productivity statistics, always computed from task rows.
 *
 * Definitions (also returned to the UI so they are visible to users):
 * - In scope for a period: the task was on the worksheet at some point in the
 *   period (scheduled on/before the period end, created on/before the end, and
 *   not completed before the period start).
 * - Completion % = tasks completed within the period / tasks in scope × 100.
 * - Team totals count each task once (distinct task id), even when shared by
 *   several assignees. Individual figures count the tasks a member is assigned to,
 *   so the sum of individual figures can exceed the team total for shared tasks.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Stats {

	const DEFINITIONS = array(
		'in_scope'   => 'Assigned / in scope: tasks that were on the worksheet at any time in the period (scheduled on or before the period end and not completed before it started).',
		'completion' => 'Completion % = tasks completed during the period ÷ tasks in scope for the period × 100.',
		'carry'      => 'Carry-forward: in-scope tasks that were still open on a day after their scheduled date during the period.',
		'overdue'    => 'Overdue: open tasks whose due date (and due time, if set) has passed.',
		'shared'     => 'Team totals count each task once even if it has several assignees. Individual figures count every task a member is assigned to.',
		'attendance' => 'Attendance day = a date with at least one successful login. Active day = a date with at least one meaningful work action. A login alone is not treated as a full working day.',
	);

	/** Classifies a list of rows for a period [from, to] against reference "now". */
	public static function summarise( $rows, $from, $to ) {
		$now   = NDWD_Helpers::now();
		$today = $now->format( 'Y-m-d' );
		$ref   = $to < $today ? $to : $today;
		$s     = array(
			'in_scope'      => 0,
			'completed'     => 0,
			'open_at_end'   => 0,
			'pending'       => 0,
			'in_process'    => 0,
			'rejected'      => 0,
			'overdue'       => 0,
			'carry_forward' => 0,
			'urgent_open'   => 0,
		);
		foreach ( $rows as $t ) {
			$s['in_scope']++;
			$done_date = $t->completed_at ? substr( $t->completed_at, 0, 10 ) : null;
			$done_in   = $done_date && $done_date >= $from && $done_date <= $to;
			if ( $done_in ) {
				$s['completed']++;
			}
			// Rejected closes a task without completing it: counted as Rejected, never open or carried afterwards.
			$rej_date = empty( $t->rejected_at ) ? null : substr( $t->rejected_at, 0, 10 );
			if ( $rej_date && $rej_date >= $from && $rej_date <= $to ) {
				$s['rejected']++;
			}
			$done_date   = $done_date ? $done_date : $rej_date;
			$open_at_end = ! $done_date || $done_date > $to;
			if ( $open_at_end ) {
				$s['open_at_end']++;
				if ( isset( $s[ $t->status ] ) && ! NDWD_Helpers::is_closed( $t->status ) ) {
					$s[ $t->status ]++;
				}
				if ( 'urgent' === $t->priority ) {
					$s['urgent_open']++;
				}
				if ( $t->due_date && ( $t->due_date < $ref || ( $ref === $today && NDWD_Tasks::is_overdue( $t, $now ) ) ) ) {
					$s['overdue']++;
				}
			}
			// Carried: open on some day d in [from,to] with d > scheduled_date.
			$first_carry_day = max( $from, gmdate( 'Y-m-d', strtotime( $t->scheduled_date . ' +1 day' ) ) );
			if ( $first_carry_day <= min( $to, $today ) && ( ! $done_date || $done_date >= $first_carry_day ) ) {
				$s['carry_forward']++;
			}
		}
		$s['completion_pct'] = $s['in_scope'] ? round( $s['completed'] * 100 / $s['in_scope'], 1 ) : 0;
		return $s;
	}

	/* ------------------------------------------------------------------ */

	public static function my_dashboard( $user_id ) {
		$today = NDWD_Helpers::today();
		$month = array( substr( $today, 0, 8 ) . '01', $today );

		$open = NDWD_Tasks::rows( array( 'scope' => 'mine', 'view' => 'open' ), $user_id );
		$now  = NDWD_Helpers::now();
		$c    = array(
			'active'     => 0,
			'due_today'  => 0,
			'pending'    => 0,
			'in_process' => 0,
			'rejected'   => 0,
			'urgent'     => 0,
			'overdue'    => 0,
			'upcoming'   => 0,
			'carried'    => 0,
		);
		// Rejected tasks are closed, so they are not in the open list: count the ones rejected today.
		$c['rejected'] = count( NDWD_Tasks::rows( array( 'scope' => 'mine', 'view' => 'rejected_today' ), $user_id ) );
		$reminders = array();
		foreach ( $open as $t ) {
			if ( $t->scheduled_date > $today ) {
				$c['upcoming']++;
				continue;
			}
			$c['active']++;
			$c[ $t->status ] = isset( $c[ $t->status ] ) ? $c[ $t->status ] + 1 : 1;
			if ( $t->due_date === $today ) {
				$c['due_today']++;
			}
			if ( 'urgent' === $t->priority ) {
				$c['urgent']++;
			}
			if ( NDWD_Tasks::is_overdue( $t, $now ) ) {
				$c['overdue']++;
			}
			if ( $t->scheduled_date < $today ) {
				$c['carried']++;
			}
		}
		foreach ( $open as $t ) {
			if ( $t->reminder_at && ! $t->reminder_sent ) {
				$reminders[] = array( 'task_id' => (int) $t->id, 'title' => $t->title, 'reminder_at' => $t->reminder_at );
			}
		}
		usort( $reminders, function ( $a, $b ) {
			return strcmp( $a['reminder_at'], $b['reminder_at'] );
		} );

		$month_rows       = NDWD_Tasks::rows( array( 'scope' => 'mine', 'in_scope' => $month ), $user_id );
		$ms               = self::summarise( $month_rows, $month[0], $month[1] );
		$completed_today  = 0;
		foreach ( $month_rows as $t ) {
			if ( $t->completed_at && substr( $t->completed_at, 0, 10 ) === $today ) {
				$completed_today++;
			}
		}
		$c['completed_today'] = $completed_today;
		$c['completed_month'] = $ms['completed'];

		$upcoming = NDWD_Tasks::query( array( 'scope' => 'mine', 'view' => 'upcoming', 'per_page' => 8 ), $user_id );
		$overdue  = NDWD_Tasks::query( array( 'scope' => 'mine', 'view' => 'overdue', 'per_page' => 8 ), $user_id );

		return array(
			'date'           => $today,
			'counts'         => $c,
			'completion'     => array( 'pct' => $ms['completion_pct'], 'completed' => $ms['completed'], 'in_scope' => $ms['in_scope'], 'period' => $month ),
			'upcoming'       => $upcoming['items'],
			'overdue'        => $overdue['items'],
			'reminders'      => array_slice( $reminders, 0, 8 ),
			'activity'       => NDWD_Activity::recent( array( 'user_ids' => array( $user_id ), 'limit' => 12 ) ),
			'attendance'     => self::attendance_today( array( $user_id ) ),
			'definitions'    => self::DEFINITIONS,
		);
	}

	/** Members in scope for a team view. */
	public static function scope_members( $user_id, $team_id = 0 ) {
		global $wpdb;
		$visible = NDWD_Permissions::visible_team_ids( $user_id );
		$tm      = NDWD_Helpers::table( 'team_members' );
		if ( $team_id ) {
			if ( null !== $visible && ! in_array( (int) $team_id, $visible, true ) ) {
				return array();
			}
			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $tm WHERE team_id = %d", $team_id ) ) );
		}
		if ( null === $visible ) {
			return wp_list_pluck( self::active_members(), 'ID' );
		}
		if ( ! $visible ) {
			return array( (int) $user_id );
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM $tm WHERE team_id IN (" . NDWD_Helpers::in_placeholders( $visible ) . ')', $visible ) ) );
	}

	public static function active_members() {
		$users = get_users( array( 'capability' => 'ndwd_access', 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name', 'user_email' ) ) );
		return array_values( array_filter( $users, function ( $u ) {
			return NDWD_Permissions::is_active( $u->ID );
		} ) );
	}

	/**
	 * Team dashboard. Filters: team_id, member_id, project_id, priority, status, from, to.
	 */
	public static function team_dashboard( $user_id, $f ) {
		$today   = NDWD_Helpers::today();
		$team_id = ! empty( $f['team_id'] ) ? absint( $f['team_id'] ) : 0;
		$visible = NDWD_Permissions::visible_team_ids( $user_id );
		if ( null !== $visible ) {
			// Everyone below Admin works in one selected team of their own.
			if ( $team_id && ! in_array( $team_id, $visible, true ) ) {
				return new WP_Error( 'ndwd_forbidden', 'You cannot view this team.', array( 'status' => 403 ) );
			}
			if ( ! $team_id && $visible ) {
				$team_id = (int) $visible[0];
			}
		}
		$members = self::scope_members( $user_id, $team_id );
		if ( ! empty( $f['member_id'] ) ) {
			$mid = absint( $f['member_id'] );
			if ( ! in_array( $mid, $members, true ) && ! NDWD_Permissions::can( 'ndwd_view_all', $user_id ) ) {
				return new WP_Error( 'ndwd_forbidden', 'You cannot view this member.', array( 'status' => 403 ) );
			}
			$members = array( $mid );
		}

		$from = ! empty( $f['from'] ) && NDWD_Helpers::valid_date( $f['from'] ) ? $f['from'] : substr( $today, 0, 8 ) . '01';
		$to   = ! empty( $f['to'] ) && NDWD_Helpers::valid_date( $f['to'] ) ? $f['to'] : $today;

		$base = array();
		foreach ( array( 'project_id', 'client_id', 'priority', 'status', 'q' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$base[ $k ] = $f[ $k ];
			}
		}
		if ( $team_id ) {
			$base['team_only'] = array( $team_id );
		} elseif ( null !== $visible ) {
			$base['scope'] = 'mine';
		}
		if ( ! empty( $f['member_id'] ) ) {
			$base['member_id'] = absint( $f['member_id'] );
		}

		// Snapshot of open work right now.
		$open = NDWD_Tasks::rows( $base + array( 'view' => 'open' ), $user_id );
		$now  = NDWD_Helpers::now();
		$c    = array( 'active' => 0, 'due_today' => 0, 'pending' => 0, 'in_process' => 0, 'rejected' => 0, 'urgent' => 0, 'overdue' => 0, 'upcoming' => 0, 'carried' => 0 );
		$c['rejected'] = count( NDWD_Tasks::rows( $base + array( 'view' => 'rejected_today' ), $user_id ) );
		$load = array();
		foreach ( $members as $m ) {
			$load[ $m ] = array( 'user_id' => $m, 'name' => NDWD_Helpers::user_name( $m ), 'open' => 0, 'overdue' => 0, 'urgent' => 0, 'in_process' => 0, 'upcoming' => 0, 'completed' => 0, 'in_scope' => 0 );
		}
		foreach ( $open as $t ) {
			$is_up = $t->scheduled_date > $today;
			if ( $is_up ) {
				$c['upcoming']++;
			} else {
				$c['active']++;
				$c[ $t->status ]++;
				if ( $t->due_date === $today ) {
					$c['due_today']++;
				}
				if ( 'urgent' === $t->priority ) {
					$c['urgent']++;
				}
				if ( NDWD_Tasks::is_overdue( $t, $now ) ) {
					$c['overdue']++;
				}
				if ( $t->scheduled_date < $today ) {
					$c['carried']++;
				}
			}
			foreach ( $t->assignee_ids as $a ) {
				if ( ! isset( $load[ $a ] ) ) {
					continue;
				}
				if ( $is_up ) {
					$load[ $a ]['upcoming']++;
					continue;
				}
				$load[ $a ]['open']++;
				if ( 'in_process' === $t->status ) {
					$load[ $a ]['in_process']++;
				}
				if ( 'urgent' === $t->priority ) {
					$load[ $a ]['urgent']++;
				}
				if ( NDWD_Tasks::is_overdue( $t, $now ) ) {
					$load[ $a ]['overdue']++;
				}
			}
		}

		// Period figures (distinct tasks for the team; per-assignee for members).
		$period_rows = NDWD_Tasks::rows( $base + array( 'in_scope' => array( $from, $to ) ), $user_id );
		$ps          = self::summarise( $period_rows, $from, $to );
		foreach ( $period_rows as $t ) {
			$done = $t->completed_at && substr( $t->completed_at, 0, 10 ) >= $from && substr( $t->completed_at, 0, 10 ) <= $to; // Completed only; Rejected is not "done".
			foreach ( $t->assignee_ids as $a ) {
				if ( isset( $load[ $a ] ) ) {
					$load[ $a ]['in_scope']++;
					if ( $done ) {
						$load[ $a ]['completed']++;
					}
				}
			}
		}
		foreach ( $load as &$l ) {
			$l['completion_pct'] = $l['in_scope'] ? round( $l['completed'] * 100 / $l['in_scope'], 1 ) : 0;
		}
		unset( $l );
		$c['completed'] = $ps['completed'];

		$upcoming = NDWD_Tasks::query( $base + array( 'view' => 'upcoming', 'per_page' => 10 ), $user_id );

		return array(
			'date'        => $today,
			'period'      => array( $from, $to ),
			'counts'      => $c,
			'completion'  => array( 'pct' => $ps['completion_pct'], 'completed' => $ps['completed'], 'in_scope' => $ps['in_scope'] ),
			'workload'    => array_values( $load ),
			'team_id'     => $team_id,
			'team_ids'    => null === $visible ? null : $visible,
			'upcoming'    => $upcoming['items'],
			'pool'        => $team_id ? NDWD_Tasks::query( array( 'team_only' => array( $team_id ), 'pool' => 1, 'per_page' => 25 ), $user_id )['items'] : array(),
			'tasks'       => NDWD_Workflow::team_tasks( $base, $f, $user_id ),
			'activity'    => $team_id ? NDWD_Workflow::team_activity( $team_id, 15 ) : ( $members ? NDWD_Activity::recent( array( 'user_ids' => $members, 'limit' => 15 ) ) : array() ),
			'attendance'  => self::attendance_today( $members ),
			'definitions' => self::DEFINITIONS,
		);
	}

	public static function attendance_today( $user_ids ) {
		$today = NDWD_Helpers::today();
		$rows  = array();
		foreach ( NDWD_Attendance::rows( $today, $today, $user_ids ) as $r ) {
			$rows[ (int) $r->user_id ] = NDWD_Attendance::format_row( $r );
		}
		$list = array();
		foreach ( $user_ids as $u ) {
			$list[] = isset( $rows[ $u ] ) ? $rows[ $u ] : array( 'user_id' => (int) $u, 'name' => NDWD_Helpers::user_name( $u ), 'date' => $today, 'first_login' => null, 'last_login' => null, 'login_count' => 0, 'activity_count' => 0 );
		}
		$present = count( array_filter( $list, function ( $r ) {
			return $r['login_count'] > 0;
		} ) );
		$active  = count( array_filter( $list, function ( $r ) {
			return $r['activity_count'] > 0;
		} ) );
		return array( 'total' => count( $list ), 'present' => $present, 'active' => $active, 'rows' => $list );
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Productivity report for [from, to].
	 *
	 * @param array $member_ids Members to report individually.
	 * @param array $base       Extra task filters (team_ids etc.).
	 * @param int   $user_id    Viewer (0 + $system for scheduled jobs).
	 */
	public static function productivity( $from, $to, $member_ids, $base, $user_id, $system = false ) {
		if ( $system ) {
			$base['_system'] = true;
		}
		$rows = NDWD_Tasks::rows( $base + array( 'in_scope' => array( $from, $to ) ), $user_id );
		$team = self::summarise( $rows, $from, $to );

		$by_member = array();
		foreach ( $member_ids as $m ) {
			$mine = array_filter( $rows, function ( $t ) use ( $m ) {
				return in_array( (int) $m, $t->assignee_ids, true );
			} );
			$by_member[] = array( 'user_id' => (int) $m, 'name' => NDWD_Helpers::user_name( $m ) ) + self::summarise( $mine, $from, $to );
		}

		$teams    = self::lookup( 'teams' );
		$projects = self::lookup( 'projects' );
		$by_team  = array();
		$by_proj  = array();
		$series   = array();
		$d        = new DateTimeImmutable( $from, NDWD_Helpers::tz() );
		$end      = new DateTimeImmutable( $to, NDWD_Helpers::tz() );
		while ( $d <= $end ) {
			$series[ $d->format( 'Y-m-d' ) ] = 0;
			$d                               = $d->modify( '+1 day' );
		}
		foreach ( $rows as $t ) {
			$done = $t->completed_at ? substr( $t->completed_at, 0, 10 ) : null;
			$in   = $done && $done >= $from && $done <= $to;
			$tk   = $t->team_id && isset( $teams[ $t->team_id ] ) ? $teams[ $t->team_id ] : 'No team';
			$pk   = $t->project_id && isset( $projects[ $t->project_id ] ) ? $projects[ $t->project_id ] : 'No project';
			// In-scope tasks were not completed before $from, so "not completed in period" means open at period end.
			self::bump( $by_team, $tk, $in );
			self::bump( $by_proj, $pk, $in );
			if ( $in && isset( $series[ $done ] ) ) {
				$series[ $done ]++;
			}
		}

		$att = NDWD_Attendance::summary( $from, $to, $member_ids );
		$att_by = array();
		foreach ( $att as $a ) {
			$att_by[ $a['user_id'] ] = $a;
		}
		foreach ( $by_member as &$m ) {
			$a = isset( $att_by[ $m['user_id'] ] ) ? $att_by[ $m['user_id'] ] : array();
			$m['attendance_days'] = isset( $a['attendance_days'] ) ? $a['attendance_days'] : 0;
			$m['active_days']     = isset( $a['active_days'] ) ? $a['active_days'] : 0;
			$m['login_count']     = isset( $a['login_count'] ) ? $a['login_count'] : 0;
			$m['activity_count']  = isset( $a['activity_count'] ) ? $a['activity_count'] : 0;
			$m['leave_days']      = isset( $a['leave_days'] ) ? $a['leave_days'] : 0;
		}
		unset( $m );

		$daily = array();
		foreach ( $series as $day => $n ) {
			$daily[] = array( 'date' => $day, 'completed' => $n );
		}
		return array(
			'from'        => $from,
			'to'          => $to,
			'team'        => $team,
			'members'     => $by_member,
			'by_team'     => array_values( $by_team ),
			'by_project'  => array_values( $by_proj ),
			'daily'       => $daily,
			'attendance'  => $att,
			'definitions' => self::DEFINITIONS,
			'rows'        => $rows,
		);
	}

	private static function bump( &$bucket, $key, $completed ) {
		if ( ! isset( $bucket[ $key ] ) ) {
			$bucket[ $key ] = array( 'name' => $key, 'in_scope' => 0, 'completed' => 0, 'open' => 0 );
		}
		$bucket[ $key ]['in_scope']++;
		$bucket[ $key ][ $completed ? 'completed' : 'open' ]++;
	}

	public static function lookup( $table ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( 'SELECT id, name FROM ' . NDWD_Helpers::table( $table ) ) as $r ) { // phpcs:ignore
			$out[ (int) $r->id ] = $r->name;
		}
		return $out;
	}
}
