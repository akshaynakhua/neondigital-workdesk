<?php
/**
 * Leave requests: apply → approve/reject → (optional) cancel, plus reassignment of
 * the absent member's open work.
 *
 * Rules
 * - Reviewers are Admins, or people whose position in one of the requester's teams
 *   has the "approve leave" team permission (team managers by default). Nobody
 *   reviews their own leave.
 * - Only the requester can cancel, and only while the leave is Pending or Approved
 *   and today (Asia/Kolkata) is before the start date.
 * - Every step is written to the activity history and notifies the relevant people.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Leaves {

	const STATUSES = array(
		'pending'   => 'Pending',
		'approved'  => 'Approved',
		'rejected'  => 'Rejected',
		'cancelled' => 'Cancelled',
	);

	const TYPES = array(
		'casual' => 'Casual leave',
		'sick'   => 'Sick leave',
		'earned' => 'Earned / annual leave',
		'unpaid' => 'Unpaid leave',
		'other'  => 'Other',
	);

	const MAX_DAYS = 60;

	public static function table() {
		return NDWD_Helpers::table( 'leaves' );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	public static function days( $start, $end ) {
		return (int) ( new DateTimeImmutable( $start ) )->diff( new DateTimeImmutable( $end ) )->days + 1;
	}

	/* ------------------------------------------------------------------ *
	 * Permissions
	 * ------------------------------------------------------------------ */

	public static function can_review( $leave, $user_id ) {
		return $leave && NDWD_Permissions::can_approve_leave_for( $leave->user_id, $user_id );
	}

	public static function can_cancel( $leave, $user_id ) {
		return $leave && (int) $leave->user_id === (int) $user_id
			&& in_array( $leave->status, array( 'pending', 'approved' ), true )
			&& NDWD_Helpers::today() < $leave->start_date;
	}

	/** Users who may review a given member's leave. */
	public static function reviewers_for( $user_id ) {
		$out = array();
		foreach ( get_users( array( 'capability' => 'ndwd_access', 'fields' => 'ID' ) ) as $uid ) {
			$uid = (int) $uid;
			if ( $uid !== (int) $user_id && NDWD_Permissions::is_active( $uid ) && NDWD_Permissions::can_approve_leave_for( $user_id, $uid ) ) {
				$out[] = $uid;
			}
		}
		return $out;
	}

	/** Team managers plus teammates of the requester (for "X is on leave" notices). */
	private static function team_audience( $user_id ) {
		$mates = NDWD_Permissions::teammates( $user_id );
		return array_values( array_diff( array_unique( array_merge( $mates, self::reviewers_for( $user_id ) ) ), array( (int) $user_id ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Formatting & listing
	 * ------------------------------------------------------------------ */

	public static function to_array( $l, $viewer ) {
		return array(
			'id'           => (int) $l->id,
			'user_id'      => (int) $l->user_id,
			'user'         => NDWD_Helpers::user_name( $l->user_id ),
			'type'         => $l->leave_type,
			'type_label'   => isset( self::TYPES[ $l->leave_type ] ) ? self::TYPES[ $l->leave_type ] : $l->leave_type,
			'start_date'   => $l->start_date,
			'end_date'     => $l->end_date,
			'days'         => self::days( $l->start_date, $l->end_date ),
			'reason'       => (string) $l->reason,
			'status'       => $l->status,
			'reviewed_by'  => $l->reviewed_by ? NDWD_Helpers::user_name( $l->reviewed_by ) : null,
			'reviewed_at'  => $l->reviewed_at,
			'review_note'  => (string) $l->review_note,
			'cancelled_at' => $l->cancelled_at,
			'created_at'   => $l->created_at,
			'can_review'   => 'pending' === $l->status && self::can_review( $l, $viewer ),
			'can_reassign' => 'approved' === $l->status && self::can_review( $l, $viewer ) && $l->end_date >= NDWD_Helpers::today(),
			'can_cancel'   => self::can_cancel( $l, $viewer ),
			'started'      => NDWD_Helpers::today() >= $l->start_date,
		);
	}

	/**
	 * @param string $scope 'mine' (own requests) or 'review' (requests the viewer may act on or see as reviewer).
	 */
	public static function query( $viewer, $scope = 'mine', $status = '' ) {
		global $wpdb;
		$t    = self::table();
		$sql  = "SELECT * FROM $t WHERE 1=1";
		$vals = array();
		if ( 'review' === $scope ) {
			$managed = NDWD_Permissions::teams_with( 'approve_leave', $viewer );
			if ( null !== $managed ) {
				if ( ! $managed ) {
					return array();
				}
				$sql .= ' AND user_id IN (SELECT user_id FROM ' . NDWD_Helpers::table( 'team_members' ) . ' WHERE team_id IN (' . NDWD_Helpers::in_placeholders( $managed ) . '))';
				$vals = array_merge( $vals, $managed );
			}
			$sql   .= ' AND user_id <> %d';
			$vals[] = $viewer;
		} else {
			$sql   .= ' AND user_id = %d';
			$vals[] = $viewer;
		}
		if ( $status && isset( self::STATUSES[ $status ] ) ) {
			$sql   .= ' AND status = %s';
			$vals[] = $status;
		}
		$sql .= " ORDER BY (status = 'pending') DESC, start_date DESC, id DESC LIMIT 300";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore
		return array_map( function ( $l ) use ( $viewer ) {
			return self::to_array( $l, $viewer );
		}, $rows );
	}

	/** Approved leave days per member within [from, to]. */
	public static function approved_days( $from, $to, $user_ids ) {
		global $wpdb;
		$out = array();
		if ( ! $user_ids ) {
			return $out;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT user_id, start_date, end_date FROM ' . self::table() . " WHERE status = 'approved' AND start_date <= %s AND end_date >= %s AND user_id IN (" . NDWD_Helpers::in_placeholders( $user_ids ) . ')',
			array_merge( array( $to, $from ), $user_ids )
		) );
		foreach ( $rows as $r ) {
			$s = max( $from, $r->start_date );
			$e = min( $to, $r->end_date );
			$out[ (int) $r->user_id ] = ( isset( $out[ (int) $r->user_id ] ) ? $out[ (int) $r->user_id ] : 0 ) + self::days( $s, $e );
		}
		return $out;
	}

	/** Raw rows overlapping [from, to] — for monthly archives. */
	public static function rows_between( $from, $to ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE start_date <= %s AND end_date >= %s ORDER BY start_date, id', $to, $from ) );
	}

	/* ------------------------------------------------------------------ *
	 * Workflow
	 * ------------------------------------------------------------------ */

	/** @return array|WP_Error */
	public static function apply( $in, $user_id ) {
		global $wpdb;
		$type  = isset( $in['type'] ) ? sanitize_key( $in['type'] ) : '';
		$start = NDWD_Helpers::sanitize_date( isset( $in['start_date'] ) ? (string) $in['start_date'] : '' );
		$end   = NDWD_Helpers::sanitize_date( isset( $in['end_date'] ) && '' !== $in['end_date'] ? (string) $in['end_date'] : (string) $start );
		$why   = sanitize_textarea_field( isset( $in['reason'] ) ? (string) $in['reason'] : '' );
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Please choose a leave type.', array( 'status' => 400 ) );
		}
		if ( ! $start || ! $end ) {
			return new WP_Error( 'ndwd_invalid', 'Please choose valid start and end dates.', array( 'status' => 400 ) );
		}
		if ( $end < $start ) {
			return new WP_Error( 'ndwd_invalid', 'End date cannot be before the start date.', array( 'status' => 400 ) );
		}
		if ( $start < NDWD_Helpers::today() ) {
			return new WP_Error( 'ndwd_invalid', 'Leave cannot start in the past.', array( 'status' => 400 ) );
		}
		if ( self::days( $start, $end ) > self::MAX_DAYS ) {
			return new WP_Error( 'ndwd_invalid', 'A single request can cover at most ' . self::MAX_DAYS . ' days.', array( 'status' => 400 ) );
		}
		if ( '' === trim( $why ) ) {
			return new WP_Error( 'ndwd_invalid', 'Please give a reason for the leave.', array( 'status' => 400 ) );
		}
		$clash = $wpdb->get_row( $wpdb->prepare(
			'SELECT id, start_date, end_date, status FROM ' . self::table() . " WHERE user_id = %d AND status IN ('pending','approved') AND start_date <= %s AND end_date >= %s LIMIT 1",
			$user_id, $end, $start
		) );
		if ( $clash ) {
			return new WP_Error( 'ndwd_invalid', sprintf( 'This overlaps your %s leave request #%d (%s to %s).', strtolower( self::STATUSES[ $clash->status ] ), $clash->id, $clash->start_date, $clash->end_date ), array( 'status' => 409 ) );
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->insert( self::table(), array(
			'user_id'    => (int) $user_id,
			'leave_type' => $type,
			'start_date' => $start,
			'end_date'   => $end,
			'reason'     => $why,
			'status'     => 'pending',
			'created_at' => $now,
			'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'ndwd_db', 'Could not save the leave request.', array( 'status' => 500 ) );
		}
		NDWD_Activity::log( $user_id, null, 'leave_applied', self::details( $id, $type, $start, $end ) );
		$reviewers = self::reviewers_for( $user_id );
		NDWD_Notifications::notify_users( $reviewers, null, 'leave', sprintf( '%s requested %s: %s', NDWD_Helpers::user_name( $user_id ), strtolower( self::TYPES[ $type ] ), self::range_text( $start, $end ) ), $user_id, 'leave:' . $id . ':applied', '#/leave' );
		$out              = self::to_array( self::get( $id ), $user_id );
		$out['reviewers'] = count( $reviewers );
		return $out;
	}

	/** @return array|WP_Error */
	public static function decide( $id, $decision, $note, $user_id ) {
		global $wpdb;
		$l = self::get( $id );
		if ( ! $l ) {
			return new WP_Error( 'ndwd_not_found', 'Leave request not found.', array( 'status' => 404 ) );
		}
		if ( ! self::can_review( $l, $user_id ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You are not allowed to review this leave request.', array( 'status' => 403 ) );
		}
		if ( 'pending' !== $l->status ) {
			return new WP_Error( 'ndwd_invalid', 'This request has already been ' . strtolower( self::STATUSES[ $l->status ] ) . '.', array( 'status' => 409 ) );
		}
		if ( ! in_array( $decision, array( 'approved', 'rejected' ), true ) ) {
			return new WP_Error( 'ndwd_invalid', 'Invalid decision.', array( 'status' => 400 ) );
		}
		$note = sanitize_textarea_field( (string) $note );
		if ( 'rejected' === $decision && '' === trim( $note ) ) {
			return new WP_Error( 'ndwd_invalid', 'Please add a note explaining why the leave is rejected.', array( 'status' => 400 ) );
		}
		$now = NDWD_Helpers::now_mysql();
		// Conditional update: a second reviewer acting at the same moment cannot overwrite the first decision.
		$done = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . self::table() . " SET status = %s, reviewed_by = %d, reviewed_at = %s, review_note = %s, updated_at = %s WHERE id = %d AND status = 'pending'",
			$decision, $user_id, $now, $note, $now, $id
		) );
		if ( ! $done ) {
			return new WP_Error( 'ndwd_invalid', 'This request was already reviewed by someone else.', array( 'status' => 409 ) );
		}
		NDWD_Activity::log( $user_id, null, 'leave_' . $decision, self::details( $id, $l->leave_type, $l->start_date, $l->end_date, array( 'member' => NDWD_Helpers::user_name( $l->user_id ), 'note' => $note ) ) );
		$range = self::range_text( $l->start_date, $l->end_date );
		$who   = NDWD_Helpers::user_name( $user_id );
		NDWD_Notifications::notify_users( array( (int) $l->user_id ), null, 'leave', sprintf( '%s %s your leave: %s%s', $who, $decision, $range, $note ? ' — ' . $note : '' ), $user_id, 'leave:' . $id . ':' . $decision, '#/leave' );
		if ( 'approved' === $decision ) {
			NDWD_Notifications::notify_users( self::team_audience( $l->user_id ), null, 'leave', sprintf( '%s will be on leave: %s', NDWD_Helpers::user_name( $l->user_id ), $range ), $user_id, 'leave:' . $id . ':team', '#/leave' );
			$open = count( self::open_tasks( $l ) );
			if ( $open ) {
				NDWD_Notifications::notify_users( self::reviewers_for( $l->user_id ), null, 'leave', sprintf( '%s has %d open task%s to cover during leave (%s). Reassign them in Leave.', NDWD_Helpers::user_name( $l->user_id ), $open, 1 === $open ? '' : 's', $range ), 0, 'leave:' . $id . ':reassign', '#/leave' );
			}
		}
		return self::to_array( self::get( $id ), $user_id );
	}

	/** @return array|WP_Error */
	public static function cancel( $id, $user_id ) {
		global $wpdb;
		$l = self::get( $id );
		if ( ! $l ) {
			return new WP_Error( 'ndwd_not_found', 'Leave request not found.', array( 'status' => 404 ) );
		}
		if ( (int) $l->user_id !== (int) $user_id ) {
			return new WP_Error( 'ndwd_forbidden', 'You can only cancel your own leave.', array( 'status' => 403 ) );
		}
		if ( ! in_array( $l->status, array( 'pending', 'approved' ), true ) ) {
			return new WP_Error( 'ndwd_invalid', 'Only pending or approved leave can be cancelled.', array( 'status' => 409 ) );
		}
		if ( NDWD_Helpers::today() >= $l->start_date ) {
			return new WP_Error( 'ndwd_invalid', 'This leave has already started and can no longer be cancelled. Ask your manager.', array( 'status' => 409 ) );
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->update( self::table(), array( 'status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now ), array( 'id' => $id ) );
		NDWD_Activity::log( $user_id, null, 'leave_cancelled', self::details( $id, $l->leave_type, $l->start_date, $l->end_date, array( 'was' => $l->status ) ) );
		$audience = 'approved' === $l->status ? self::team_audience( $user_id ) : self::reviewers_for( $user_id );
		NDWD_Notifications::notify_users( $audience, null, 'leave', sprintf( '%s cancelled their %s leave: %s', NDWD_Helpers::user_name( $user_id ), self::STATUSES[ $l->status ], self::range_text( $l->start_date, $l->end_date ) ), $user_id, 'leave:' . $id . ':cancelled', '#/leave' );
		return self::to_array( self::get( $id ), $user_id );
	}

	/** Open (not completed) tasks assigned to the member that are scheduled on or before the leave ends. */
	public static function open_tasks( $l ) {
		global $wpdb;
		$tasks = NDWD_Helpers::table( 'tasks' );
		$ta    = NDWD_Helpers::table( 'task_assignees' );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.* FROM $tasks t JOIN $ta a ON a.task_id = t.id WHERE a.user_id = %d AND t.status NOT IN ('completed','rejected') AND t.is_archived = 0 AND t.scheduled_date <= %s ORDER BY t.scheduled_date, t.id",
			$l->user_id, $l->end_date
		) );
		return NDWD_Tasks::hydrate( $rows );
	}

	/** @return array|WP_Error */
	public static function tasks_for( $id, $user_id ) {
		$l = self::get( $id );
		if ( ! $l || ! self::can_review( $l, $user_id ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You cannot manage work for this leave.', array( 'status' => 403 ) );
		}
		$teams      = NDWD_Permissions::user_teams( $l->user_id );
		$candidates = array();
		foreach ( NDWD_Stats::active_members() as $m ) {
			if ( (int) $m->ID === (int) $l->user_id ) {
				continue;
			}
			$same_team = (bool) array_intersect( $teams, NDWD_Permissions::user_teams( $m->ID ) );
			if ( ! $same_team && ! NDWD_Permissions::is_admin( $user_id ) ) {
				continue;
			}
			$on_leave = self::approved_days( $l->start_date, $l->end_date, array( (int) $m->ID ) );
			$candidates[] = array( 'id' => (int) $m->ID, 'name' => $m->display_name, 'same_team' => $same_team, 'on_leave' => ! empty( $on_leave ) );
		}
		return array(
			'leave'      => self::to_array( $l, $user_id ),
			'tasks'      => array_map( array( 'NDWD_Tasks', 'to_array' ), self::open_tasks( $l ) ),
			'candidates' => $candidates,
		);
	}

	/**
	 * Moves the absent member's place on the selected tasks to another member. Other
	 * assignees are kept; history records who, when, from and to.
	 *
	 * @return array|WP_Error
	 */
	public static function reassign( $id, $task_ids, $to_user, $user_id ) {
		$l = self::get( $id );
		if ( ! $l || ! self::can_review( $l, $user_id ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You cannot reassign work for this leave.', array( 'status' => 403 ) );
		}
		if ( 'approved' !== $l->status ) {
			return new WP_Error( 'ndwd_invalid', 'Work can be reassigned only for approved leave.', array( 'status' => 409 ) );
		}
		$to_user = absint( $to_user );
		if ( ! $to_user || $to_user === (int) $l->user_id || ! NDWD_Permissions::can( 'ndwd_access', $to_user ) ) {
			return new WP_Error( 'ndwd_invalid', 'Choose an active member to take over the work.', array( 'status' => 400 ) );
		}
		$options = self::tasks_for( $id, $user_id );
		if ( is_wp_error( $options ) || ! in_array( $to_user, array_map( 'intval', wp_list_pluck( $options['candidates'], 'id' ) ), true ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You can only hand work to members of the absent member\'s team.', array( 'status' => 403 ) );
		}
		$allowed = wp_list_pluck( self::open_tasks( $l ), 'id' );
		$allowed = array_map( 'intval', $allowed );
		$task_ids = NDWD_Helpers::int_list( $task_ids );
		if ( ! $task_ids ) {
			return new WP_Error( 'ndwd_invalid', 'Select at least one task.', array( 'status' => 400 ) );
		}
		$moved  = array();
		$errors = array();
		foreach ( $task_ids as $tid ) {
			if ( ! in_array( $tid, $allowed, true ) ) {
				$errors[] = '#' . $tid . ' is not an open task of ' . NDWD_Helpers::user_name( $l->user_id );
				continue;
			}
			$task      = NDWD_Tasks::get( $tid );
			$assignees = array_values( array_diff( $task->assignee_ids, array( (int) $l->user_id ) ) );
			if ( ! in_array( $to_user, $assignees, true ) ) {
				$assignees[] = $to_user;
			}
			$res = NDWD_Tasks::update( $tid, array( 'assignee_ids' => $assignees ), $user_id, array(
				'authorized' => true,
				'leave_id'   => (int) $id,
				'reason'     => sprintf( '%s on leave %s', NDWD_Helpers::user_name( $l->user_id ), self::range_text( $l->start_date, $l->end_date ) ),
			) );
			if ( is_wp_error( $res ) ) {
				$errors[] = '#' . $tid . ': ' . $res->get_error_message();
			} else {
				$moved[] = $tid;
			}
		}
		if ( $moved ) {
			NDWD_Notifications::notify_users( array( (int) $l->user_id ), null, 'leave', sprintf( '%s reassigned %d task%s of yours to %s while you are on leave.', NDWD_Helpers::user_name( $user_id ), count( $moved ), 1 === count( $moved ) ? '' : 's', NDWD_Helpers::user_name( $to_user ) ), $user_id, null, '#/leave' );
		}
		return array( 'moved' => $moved, 'errors' => $errors );
	}

	private static function details( $id, $type, $start, $end, $extra = array() ) {
		return array_merge( array( 'leave_id' => (int) $id, 'type' => isset( self::TYPES[ $type ] ) ? self::TYPES[ $type ] : $type, 'dates' => self::range_text( $start, $end ) ), array_filter( $extra, 'strlen' ) );
	}

	public static function range_text( $start, $end ) {
		$f = function ( $d ) {
			return ( new DateTimeImmutable( $d ) )->format( 'j M Y' );
		};
		return $start === $end ? $f( $start ) . ' (1 day)' : $f( $start ) . ' – ' . $f( $end ) . ' (' . self::days( $start, $end ) . ' days)';
	}
}
