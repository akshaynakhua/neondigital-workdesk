<?php
/**
 * Activity history. Meaningful actions also count towards the day's attendance record.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Activity {

	const LABELS = array(
		'task_created'     => 'created the task',
		'team_deleted'     => 'deleted a team',
		'project_deleted'  => 'deleted a project',
		'client_deleted'   => 'deleted a client',
		'task_updated'     => 'updated the task',
		'status_changed'   => 'changed status',
		'task_completed'   => 'completed the task',
		'task_rejected'    => 'rejected the task',
		'status_migrated'  => 'status updated by the v1.5 upgrade',
		'session_resumed'  => 'opened WorkDesk (signed-in session)',
		'remark_added'     => 'added a remark',
		'followup_created' => 'created a follow-up',
		'reassigned'       => 'reassigned the task',
		'teams_changed'    => 'changed the teams',
		'handed_off'       => 'handed off the task',
		'member_handoff'   => 'handed the task to another member',
		'reassigned'       => 'reassigned the task',
		'picked_up'        => 'picked up the task',
		'released'         => 'released the task to the team pool',
		'rescheduled'      => 'rescheduled the task',
		'links_changed'    => 'changed links',
		'task_archived'    => 'archived the task',
		'client_message'   => 'received a client message',
		'chat_message'     => 'sent a message to the client',
		'chat_activated'   => 'opened the chat with the client',
		'deliverable_added' => 'added a deliverable',
		'package_usage'    => 'updated package usage',
		'package_linked'   => 'changed the package scope',
		'task_imported'    => 'imported the task',
		'login'            => 'logged in',
		'client_created'   => 'added a client',
		'client_updated'   => 'updated a client',
		'leave_applied'    => 'applied for leave',
		'leave_approved'   => 'approved leave',
		'leave_rejected'   => 'rejected leave',
		'leave_cancelled'  => 'cancelled leave',
	);

	public static function log( $user_id, $task_id, $action, $details = '', $meaningful = true ) {
		global $wpdb;
		$now = NDWD_Helpers::now();
		$wpdb->insert( NDWD_Helpers::table( 'activity' ), array(
			'user_id'       => (int) $user_id,
			'task_id'       => $task_id ? (int) $task_id : null,
			'action'        => $action,
			'details'       => is_array( $details ) ? wp_json_encode( $details ) : (string) $details,
			'is_meaningful' => $meaningful ? 1 : 0,
			'activity_date' => $now->format( 'Y-m-d' ),
			'created_at'    => $now->format( 'Y-m-d H:i:s' ),
		) );
		if ( $meaningful && $user_id ) {
			NDWD_Attendance::record_activity( $user_id );
		}
		do_action( 'ndwd_activity_logged', $user_id, $task_id, $action );
	}

	public static function for_task( $task_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . NDWD_Helpers::table( 'activity' ) . ' WHERE task_id = %d ORDER BY id DESC LIMIT 200', $task_id
		) );
		return array_map( array( __CLASS__, 'format' ), $rows );
	}

	public static function format( $row ) {
		$details = json_decode( (string) $row->details, true );
		return array(
			'id'        => (int) $row->id,
			'user_id'   => (int) $row->user_id,
			'user'      => (int) $row->user_id ? NDWD_Helpers::user_name( $row->user_id ) : 'WorkDesk',
			'task_id'   => $row->task_id ? (int) $row->task_id : null,
			'action'    => $row->action,
			'label'     => isset( self::LABELS[ $row->action ] ) ? self::LABELS[ $row->action ] : $row->action,
			'details'   => is_array( $details ) ? $details : ( $row->details ? array( 'text' => $row->details ) : array() ),
			'created_at' => $row->created_at,
			'task_title' => isset( $row->task_title ) ? $row->task_title : null,
		);
	}

	/**
	 * Recent activity, optionally limited to users and/or tasks visible to a team scope.
	 */
	public static function recent( $args = array() ) {
		global $wpdb;
		$a     = NDWD_Helpers::table( 'activity' );
		$tk    = NDWD_Helpers::table( 'tasks' );
		$where = array( 'a.is_meaningful = 1' );
		$vals  = array();
		if ( ! empty( $args['user_ids'] ) ) {
			$where[] = 'a.user_id IN (' . NDWD_Helpers::in_placeholders( $args['user_ids'] ) . ')';
			$vals    = array_merge( $vals, $args['user_ids'] );
		}
		if ( ! empty( $args['from'] ) ) {
			$where[] = 'a.activity_date >= %s';
			$vals[]  = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[] = 'a.activity_date <= %s';
			$vals[]  = $args['to'];
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 5000, (int) $args['limit'] ) ) : 20;
		$sql   = "SELECT a.*, t.title AS task_title FROM $a a LEFT JOIN $tk t ON t.id = a.task_id WHERE " . implode( ' AND ', $where ) . " ORDER BY a.id DESC LIMIT $limit";
		$rows  = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		return array_map( array( __CLASS__, 'format' ), $rows );
	}
}
