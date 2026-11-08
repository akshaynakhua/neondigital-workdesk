<?php
/**
 * Task responsibility workflows and organisation-wide visibility.
 *
 * Four separate workflows change who is responsible for a task:
 * - assign to a team (with an optional initial assignee): NDWD_Tasks::create / update;
 * - member handoff: one member passes their task to another member of the same team;
 * - team-to-team handoff: NDWD_Tasks::handoff;
 * - pool pickup: a member claims an unassigned team task (atomic, one winner).
 *
 * Every one of them writes a permanent task_handoffs row, a labelled remark and an activity
 * entry; nothing is overwritten. A task is never copied, so its ID, client, project, priority
 * and due date stay as they are.
 *
 * Work Overview shows task facts of the whole organisation to every member (read-only, no
 * remarks). Seeing a task there gives no right to open or change it: those stay with
 * NDWD_Permissions, enforced on the server.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Workflow {

	const TYPES = array(
		'team'     => 'Team-to-Team Handoff',
		'member'   => 'Internal Member Handoff',
		'reassign' => 'Task Reassigned',
		'pickup'   => 'Task Pickup',
		'release'  => 'Released to Team Pool',
	);

	const LINKS_MAX = 10;

	public static function routes() {
		$member = array( 'NDWD_REST', 'member' );
		foreach ( array(
			'/tasks/(?P<id>\d+)/handoff-member' => 'rest_member_handoff',
			'/tasks/(?P<id>\d+)/pickup'         => 'rest_pickup',
			'/tasks/(?P<id>\d+)/release'        => 'rest_release',
		) as $path => $cb ) {
			register_rest_route( NDWD_REST::NS, $path, array( 'methods' => 'POST', 'callback' => array( __CLASS__, $cb ), 'permission_callback' => $member ) );
		}
		register_rest_route( NDWD_REST::NS, '/work-overview', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_overview' ), 'permission_callback' => $member ) );
	}

	private static function err( $msg, $status = 400, $code = 'ndwd_invalid' ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	private static function body( WP_REST_Request $r ) {
		$b = json_decode( (string) $r->get_body(), true );
		return is_array( $b ) ? $b : array();
	}

	private static function respond( $res ) {
		return is_wp_error( $res ) ? $res : rest_ensure_response( NDWD_Tasks::to_array( $res, true ) );
	}

	public static function rest_member_handoff( WP_REST_Request $r ) {
		return self::respond( self::member_handoff( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_pickup( WP_REST_Request $r ) {
		return self::respond( self::pickup( (int) $r['id'], get_current_user_id() ) );
	}

	public static function rest_release( WP_REST_Request $r ) {
		return self::respond( self::release( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_overview( WP_REST_Request $r ) {
		return rest_ensure_response( self::overview( $r->get_query_params(), get_current_user_id() ) );
	}

	public static function type_label( $type ) {
		return isset( self::TYPES[ $type ] ) ? self::TYPES[ $type ] : self::TYPES['team'];
	}

	/** @return array|WP_Error [{url, label}], http(s) only. */
	public static function clean_links( $links ) {
		$out = array();
		foreach ( (array) $links as $l ) {
			$url   = trim( (string) ( is_array( $l ) && isset( $l['url'] ) ? $l['url'] : '' ) );
			$label = mb_substr( sanitize_text_field( (string) ( is_array( $l ) && isset( $l['label'] ) ? $l['label'] : '' ) ), 0, 200 );
			if ( '' === $url ) {
				continue;
			}
			$clean = esc_url_raw( $url, array( 'http', 'https' ) );
			if ( '' === $clean || ! wp_parse_url( $clean, PHP_URL_HOST ) ) {
				return self::err( '"' . mb_substr( $url, 0, 80 ) . '" is not a valid web link. Use a full address starting with http:// or https://.' );
			}
			$out[] = array( 'url' => $clean, 'label' => $label );
		}
		if ( count( $out ) > self::LINKS_MAX ) {
			return self::err( 'Attach at most ' . self::LINKS_MAX . ' reference links.' );
		}
		return $out;
	}

	/** Handoff links also join the task's reference links, so the receiver finds them there. */
	public static function add_links( $task_id, $links, $user_id ) {
		global $wpdb;
		foreach ( $links as $l ) {
			$wpdb->insert( NDWD_Helpers::table( 'task_links' ), array( 'task_id' => (int) $task_id, 'url' => $l['url'], 'label' => $l['label'], 'created_by' => (int) $user_id, 'created_at' => NDWD_Helpers::now_mysql() ) );
		}
	}

	/** Clearly labelled remark text. Remarks are append-only, so this history is never overwritten. */
	public static function remark( $title, $from, $to, $user_id, $when, $note, $links ) {
		$lines = array( $title );
		if ( '' !== $from ) {
			$lines[] = 'From: ' . $from;
		}
		if ( '' !== $to ) {
			$lines[] = 'To: ' . $to;
		}
		$lines[] = 'Initiated by: ' . NDWD_Helpers::user_name( $user_id );
		$lines[] = 'Time: ' . date_i18n( 'j F Y, g:i A', strtotime( $when ) );
		if ( '' !== trim( (string) $note ) ) {
			$lines[] = 'Remark: ' . $note;
		}
		foreach ( (array) $links as $l ) {
			$lines[] = 'Reference: ' . ( '' !== $l['label'] ? $l['label'] . ' - ' : '' ) . $l['url'];
		}
		return implode( "\n", $lines );
	}

	private static function who( $user_id, $team_id ) {
		return NDWD_Helpers::user_name( $user_id ) . ( $team_id ? ' (' . NDWD_Tasks::team_name( $team_id ) . ')' : '' );
	}

	/** Active members of the task's current teams: the people it can move to within the team. */
	public static function eligible_members( $task ) {
		$ids = NDWD_Permissions::members_of( NDWD_Permissions::task_teams( $task ) );
		return array_values( array_filter( array_map( 'intval', $ids ), function ( $u ) {
			return NDWD_Permissions::is_active( $u ) && NDWD_Permissions::can( 'ndwd_access', $u );
		} ) );
	}

	private static function open_team_task( $task ) {
		return $task && empty( $task->is_archived ) && ! NDWD_Helpers::is_closed( $task->status ) && NDWD_Permissions::task_teams( $task );
	}

	/** Unassigned, open work of a team the user belongs to. */
	public static function can_pickup( $task, $user_id ) {
		return self::open_team_task( $task ) && empty( $task->assignee_ids )
			&& (bool) array_intersect( NDWD_Permissions::task_teams( $task ), NDWD_Permissions::user_teams( $user_id ) );
	}

	/** Their own assigned task, or anyone's with "Manage / reassign other members' tasks". */
	public static function can_member_handoff( $task, $user_id ) {
		if ( ! self::open_team_task( $task ) ) {
			return false;
		}
		return in_array( (int) $user_id, $task->assignee_ids, true ) || NDWD_Permissions::can_reassign_task( $task, $user_id );
	}

	/**
	 * Moves responsibility from one member to another member of the same team. A person passing
	 * on their own task is an internal handoff; anyone else needs the reassign permission and it
	 * is recorded as a reassignment.
	 *
	 * @return object|WP_Error Fresh task.
	 */
	public static function member_handoff( $id, $in, $user_id ) {
		$task = NDWD_Tasks::get( $id );
		if ( ! $task || $task->is_archived ) {
			return self::err( 'Task not found.', 404, 'ndwd_not_found' );
		}
		$to   = absint( isset( $in['to_user_id'] ) ? $in['to_user_id'] : 0 );
		$from = absint( isset( $in['from_user_id'] ) ? $in['from_user_id'] : 0 );
		if ( ! $from ) {
			if ( in_array( (int) $user_id, $task->assignee_ids, true ) ) {
				$from = (int) $user_id;
			} elseif ( 1 === count( $task->assignee_ids ) ) {
				$from = (int) $task->assignee_ids[0];
			}
		}
		$own = $from === (int) $user_id && in_array( $from, $task->assignee_ids, true );
		if ( ! $own && ! NDWD_Permissions::can_reassign_task( $task, $user_id ) ) {
			return self::err( 'You do not have permission to manage or reassign other members\' tasks.', 403, 'ndwd_forbidden' );
		}
		if ( ! self::open_team_task( $task ) ) {
			return self::err( 'Only an open task of a team can be handed to another member.' );
		}
		if ( $from && ! in_array( $from, $task->assignee_ids, true ) ) {
			return self::err( NDWD_Helpers::user_name( $from ) . ' is not assigned to this task.' );
		}
		if ( ! $from && $task->assignee_ids ) {
			return self::err( 'Choose which assigned member is passing on the task.' );
		}
		if ( ! $to || $to === $from ) {
			return self::err( 'Choose the member who receives the task (someone else).' );
		}
		if ( ! in_array( $to, self::eligible_members( $task ), true ) ) {
			return self::err( 'The receiving member must be an active member of this task\'s team.' );
		}
		$note  = isset( $in['note'] ) ? sanitize_textarea_field( (string) $in['note'] ) : '';
		$links = self::clean_links( isset( $in['links'] ) ? $in['links'] : array() );
		if ( is_wp_error( $links ) ) {
			return $links;
		}
		$assignees = array_values( array_unique( array_merge( array_values( array_diff( $task->assignee_ids, array( $from ) ) ), array( $to ) ) ) );
		$type      = $own ? 'member' : 'reassign';
		$team      = (int) $task->team_id ? (int) $task->team_id : (int) NDWD_Permissions::task_teams( $task )[0];
		NDWD_Tasks::sync_assignees( $task->id, $assignees );
		global $wpdb;
		$wpdb->update( NDWD_Helpers::table( 'tasks' ), array( 'updated_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $task->id ) );
		self::record( $task, $type, $team, $team, $from, $to, $user_id, $assignees, $note, $links, self::who( $from, $team ), self::who( $to, $team ) );
		$fresh = NDWD_Tasks::get( $task->id );
		$actor = NDWD_Helpers::user_name( $user_id );
		NDWD_Notifications::notify_users( array( $to ), $fresh, 'reassigned', $actor . ' handed "' . $fresh->title . '" to you' . ( '' !== $note ? ': ' . mb_substr( $note, 0, 200 ) : '' ), $user_id, null, null, 'member_handoff' );
		if ( $from ) {
			NDWD_Notifications::notify_users( array( $from ), $fresh, 'unassigned', $actor . ' moved "' . $fresh->title . '" to ' . NDWD_Helpers::user_name( $to ), $user_id, null, null, 'member_handoff' );
		}
		return $fresh;
	}

	/**
	 * Claims an unassigned team task. The conditional UPDATE is the lock: only the request that
	 * changes the row wins, a second one at the same moment gets a clear refusal.
	 *
	 * @return object|WP_Error Fresh task.
	 */
	public static function pickup( $id, $user_id ) {
		global $wpdb;
		$task = NDWD_Tasks::get( $id );
		if ( ! $task || $task->is_archived ) {
			return self::err( 'Task not found.', 404, 'ndwd_not_found' );
		}
		if ( ! array_intersect( NDWD_Permissions::task_teams( $task ), NDWD_Permissions::user_teams( $user_id ) ) ) {
			return self::err( 'Only members of this task\'s team can pick it up.', 403, 'ndwd_forbidden' );
		}
		if ( ! self::can_pickup( $task, $user_id ) ) {
			return self::err( $task->assignee_ids ? 'This task has already been taken by ' . implode( ', ', array_map( array( 'NDWD_Helpers', 'user_name' ), $task->assignee_ids ) ) . '.' : 'Only open team tasks can be picked up.', 409, 'ndwd_already_claimed' );
		}
		$now = NDWD_Helpers::now_mysql();
		$won = $wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_Helpers::table( 'tasks' ) . ' SET claimed_by = %d, claimed_at = %s, updated_at = %s WHERE id = %d AND claimed_by IS NULL', $user_id, $now, $now, $id ) );
		if ( ! $won ) {
			$fresh = NDWD_Tasks::get( $id );
			return self::err( 'This task was just taken by ' . ( $fresh && $fresh->claimed_by ? NDWD_Helpers::user_name( $fresh->claimed_by ) : 'someone else' ) . '.', 409, 'ndwd_already_claimed' );
		}
		NDWD_Tasks::sync_assignees( $id, array( (int) $user_id ) );
		$team = in_array( (int) $task->team_id, NDWD_Permissions::user_teams( $user_id ), true ) ? (int) $task->team_id : (int) current( array_intersect( NDWD_Permissions::task_teams( $task ), NDWD_Permissions::user_teams( $user_id ) ) );
		self::record( $task, 'pickup', $team, $team, null, $user_id, $user_id, array( (int) $user_id ), '', array(), 'Team pool (' . NDWD_Tasks::team_name( $team ) . ')', self::who( $user_id, $team ) );
		$fresh = NDWD_Tasks::get( $id );
		if ( (int) $task->created_by !== (int) $user_id ) {
			NDWD_Notifications::notify_users( array( (int) $task->created_by ), $fresh, 'reassigned', NDWD_Helpers::user_name( $user_id ) . ' picked up "' . $fresh->title . '"', $user_id, null, null, 'pickup' );
		}
		return $fresh;
	}

	/** The scheduler handing an unclaimed team task to one person. Recorded like any reassignment. */
	public static function system_assign( $task, $user_id, $team_id, $note ) {
		global $wpdb;
		$now = NDWD_Helpers::now_mysql();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_Helpers::table( 'tasks' ) . ' SET claimed_by = %d, claimed_at = %s, updated_at = %s WHERE id = %d AND claimed_by IS NULL', $user_id, $now, $now, $task->id ) );
		NDWD_Tasks::sync_assignees( $task->id, array( (int) $user_id ) );
		self::record( $task, 'reassign', $team_id, $team_id, null, $user_id, 0, array( (int) $user_id ), $note, array(), 'Team pool (' . NDWD_Tasks::team_name( $team_id ) . ')', self::who( $user_id, $team_id ) );
		return NDWD_Tasks::get( $task->id );
	}

	/** Back to the team's shared pool (managers and admins with the reassign permission). */
	public static function release( $id, $in, $user_id ) {
		$task = NDWD_Tasks::get( $id );
		if ( ! $task || $task->is_archived ) {
			return self::err( 'Task not found.', 404, 'ndwd_not_found' );
		}
		if ( ! NDWD_Permissions::can_reassign_task( $task, $user_id ) ) {
			return self::err( 'You do not have permission to manage or reassign other members\' tasks.', 403, 'ndwd_forbidden' );
		}
		if ( ! self::open_team_task( $task ) || ! $task->assignee_ids ) {
			return self::err( 'Only an assigned, open task of a team can be released to the pool.' );
		}
		$note = isset( $in['note'] ) ? sanitize_textarea_field( (string) $in['note'] ) : '';
		$team = (int) $task->team_id ? (int) $task->team_id : (int) NDWD_Permissions::task_teams( $task )[0];
		$from = $task->assignee_ids;
		NDWD_Tasks::sync_assignees( $id, array() );
		self::record( $task, 'release', $team, $team, 1 === count( $from ) ? $from[0] : null, null, $user_id, array(), $note, array(), implode( ', ', array_map( array( 'NDWD_Helpers', 'user_name' ), $from ) ), 'Team pool (' . NDWD_Tasks::team_name( $team ) . ')' );
		$fresh = NDWD_Tasks::get( $id );
		NDWD_Notifications::notify_users( $from, $fresh, 'unassigned', NDWD_Helpers::user_name( $user_id ) . ' released "' . $fresh->title . '" to the team pool', $user_id, null, null, 'release' );
		NDWD_Notifications::notify_users( NDWD_Permissions::members_of( array( $team ) ), $fresh, 'assigned', '"' . $fresh->title . '" is available to pick up in ' . NDWD_Tasks::team_name( $team ), $user_id, null, null, 'team_available' );
		return $fresh;
	}

	/** Writes the permanent history: handoff row, labelled remark, activity entry. */
	private static function record( $task, $type, $from_team, $to_team, $from_user, $to_user, $actor, $assignees, $note, $links, $from_text, $to_text ) {
		global $wpdb;
		$now = NDWD_Helpers::now_mysql();
		$wpdb->insert( NDWD_Helpers::table( 'task_handoffs' ), array(
			'task_id'           => (int) $task->id,
			'from_team_id'      => (int) $from_team,
			'to_team_id'        => (int) $to_team,
			'handed_by'         => (int) $actor,
			'from_status'       => $task->status,
			'from_completed_at' => $task->completed_at,
			'from_assignees'    => wp_json_encode( array_map( 'intval', $task->assignee_ids ) ),
			'to_assignees'      => wp_json_encode( array_map( 'intval', $assignees ) ),
			'note'              => $note,
			'handoff_type'      => $type,
			'from_user_id'      => $from_user ? (int) $from_user : null,
			'to_user_id'        => $to_user ? (int) $to_user : null,
			'to_status'         => $task->status,
			'links'             => $links ? wp_json_encode( $links ) : null,
			'created_at'        => $now,
		) );
		self::add_links( $task->id, $links, $actor );
		NDWD_Tasks::insert_remark( $task->id, $actor, self::remark( 'Task #' . $task->id . ' - ' . self::type_label( $type ), $from_text, $to_text, $actor, $now, $note, $links ) );
		$action = array( 'member' => 'member_handoff', 'reassign' => 'reassigned', 'pickup' => 'picked_up', 'release' => 'released' );
		$det    = array( 'from' => $from_text, 'to' => $to_text );
		if ( '' !== $note ) {
			$det['note'] = mb_substr( $note, 0, 140 );
		}
		if ( $links ) {
			$det['links'] = count( $links );
		}
		NDWD_Activity::log( $actor, $task->id, $action[ $type ], $det );
	}

	/* ------------------------------------------------------------------ *
	 * Projects
	 * ------------------------------------------------------------------ */

	/** @return object|null|WP_Error The parent project: a main project of the same client. */
	public static function check_parent( $parent_id, $project_id, $client_id ) {
		global $wpdb;
		if ( ! $parent_id ) {
			return null;
		}
		$t = NDWD_Helpers::table( 'projects' );
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $parent_id ) ); // phpcs:ignore
		if ( ! $p ) {
			return self::err( 'Unknown parent project.' );
		}
		if ( $project_id && (int) $project_id === (int) $p->id ) {
			return self::err( 'A project cannot be its own parent.' );
		}
		if ( $p->parent_id ) {
			return self::err( 'Sub-projects sit directly under a main project. "' . $p->name . '" is itself a sub-project.' );
		}
		if ( $project_id && $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE parent_id = %d", $project_id ) ) ) { // phpcs:ignore
			return self::err( 'This project has its own sub-projects, so it must stay a main project.' );
		}
		if ( null !== $client_id && (int) $client_id !== (int) $p->client_id ) {
			return self::err( 'A sub-project must belong to the same client as its parent project.' );
		}
		return $p;
	}

	/* ------------------------------------------------------------------ *
	 * Team dashboard helpers
	 * ------------------------------------------------------------------ */

	/** Activity on the team's own tasks only: a shared member's work for another team stays out. */
	public static function team_activity( $team_id, $limit = 15 ) {
		global $wpdb;
		$act = NDWD_Helpers::table( 'activity' );
		$tt  = NDWD_Helpers::table( 'task_teams' );
		$tk  = NDWD_Helpers::table( 'tasks' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.* FROM $act a WHERE a.task_id IN (SELECT id FROM $tk WHERE team_id = %d UNION SELECT task_id FROM $tt WHERE team_id = %d) ORDER BY a.id DESC LIMIT %d", // phpcs:ignore
			$team_id, $team_id, $limit
		) );
		return array_map( array( 'NDWD_Activity', 'format' ), $rows );
	}

	/** Searchable, sortable, paginated task list of the team dashboard. */
	public static function team_tasks( $base, $f, $user_id ) {
		$rows = NDWD_Tasks::rows( $base + array( 'from' => isset( $f['from'] ) ? $f['from'] : '', 'to' => isset( $f['to'] ) ? $f['to'] : '' ), $user_id );
		return self::page_of( $rows, $f, $user_id );
	}

	/* ------------------------------------------------------------------ *
	 * Work Overview
	 * ------------------------------------------------------------------ */

	private static function sort_value( $t, $key ) {
		switch ( $key ) {
			case 'title':
				return mb_strtolower( $t->title );
			case 'status':
				return $t->status;
			case 'priority':
				return array_search( $t->priority, array( 'urgent', 'high', 'medium', 'low' ), true );
			case 'due':
				return $t->due_date ? $t->due_date : '9999-12-31';
			default:
				return $t->scheduled_date;
		}
	}

	/** @return array {total, page, per_page, items} Read-only facts of the requested page. */
	private static function page_of( $rows, $f, $user_id ) {
		$key = isset( $f['sort'] ) ? sanitize_key( $f['sort'] ) : 'date';
		$dir = isset( $f['dir'] ) && 'asc' === $f['dir'] ? 1 : -1;
		usort( $rows, function ( $a, $b ) use ( $key, $dir ) {
			$x = self::sort_value( $a, $key );
			$y = self::sort_value( $b, $key );
			if ( $x == $y ) { // phpcs:ignore
				return ( (int) $b->id - (int) $a->id );
			}
			return ( $x < $y ? -1 : 1 ) * $dir;
		} );
		$per   = isset( $f['per_page'] ) ? max( 5, min( 100, (int) $f['per_page'] ) ) : 25;
		$total = count( $rows );
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$page  = min( $page, max( 1, (int) ceil( $total / $per ) ) );
		$items = array();
		$proj  = NDWD_Stats::lookup( 'projects' );
		$cli   = NDWD_Stats::lookup( 'clients' );
		foreach ( array_slice( $rows, ( $page - 1 ) * $per, $per ) as $t ) {
			$items[] = array(
				'id'             => (int) $t->id,
				'title'          => $t->title,
				'status'         => $t->status,
				'priority'       => $t->priority,
				'team_ids'       => array_values( array_map( 'intval', NDWD_Permissions::task_teams( $t ) ) ),
				'teams'          => array_map( array( 'NDWD_Tasks', 'team_name' ), NDWD_Permissions::task_teams( $t ) ),
				'assignee_ids'   => array_map( 'intval', $t->assignee_ids ),
				'assignees'      => array_map( array( 'NDWD_Helpers', 'user_name' ), $t->assignee_ids ),
				'client'         => $t->client_id && isset( $cli[ $t->client_id ] ) ? $cli[ $t->client_id ] : '',
				'project'        => $t->project_id && isset( $proj[ $t->project_id ] ) ? $proj[ $t->project_id ] : '',
				'scheduled_date' => $t->scheduled_date,
				'due_date'       => $t->due_date,
				'completed_at'   => $t->completed_at,
				'is_overdue'     => NDWD_Tasks::is_overdue( $t ),
				'is_mine'        => in_array( (int) $user_id, $t->assignee_ids, true ),
				'can_open'       => NDWD_Permissions::can_view_task( $t, $user_id ),
				'can_pickup'     => self::can_pickup( $t, $user_id ),
			);
		}
		return array( 'total' => $total, 'page' => $page, 'per_page' => $per, 'items' => $items );
	}

	/**
	 * Organisation-wide, read-only. Filters: from, to, team_id, member_id, client_id, project_id,
	 * priority, status, q, view (due_today, overdue, urgent, upcoming), sort, dir, page.
	 */
	public static function overview( $f, $user_id ) {
		$today = NDWD_Helpers::today();
		$from  = ! empty( $f['from'] ) && NDWD_Helpers::valid_date( $f['from'] ) ? $f['from'] : substr( $today, 0, 8 ) . '01';
		$to    = ! empty( $f['to'] ) && NDWD_Helpers::valid_date( $f['to'] ) ? $f['to'] : $today;
		$base  = array( '_system' => true );
		if ( ! empty( $f['team_id'] ) ) {
			$base['team_only'] = array( absint( $f['team_id'] ) );
		}
		foreach ( array( 'member_id', 'client_id', 'project_id', 'q' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$base[ $k ] = $f[ $k ];
			}
		}
		if ( ! empty( $f['priority'] ) ) {
			$base['priority'] = sanitize_key( $f['priority'] );
		}
		$now      = NDWD_Helpers::now();
		$all      = NDWD_Tasks::rows( $base + array( 'in_scope' => array( $from, $to ) ), $user_id );
		$upcoming = NDWD_Tasks::rows( $base + array( 'view' => 'upcoming' ), $user_id );
		// Counts ignore the status / quick filters so the cards always explain the whole selection.
		$c = array( 'total' => count( $all ), 'pending' => 0, 'in_process' => 0, 'rejected' => 0, 'completed' => 0, 'urgent' => 0, 'overdue' => 0, 'due_today' => 0, 'upcoming' => count( $upcoming ), 'unassigned' => 0 );
		$members = array();
		$teams   = array();
		foreach ( NDWD_Stats::active_members() as $u ) {
			$members[ (int) $u->ID ] = self::tally( array( 'user_id' => (int) $u->ID, 'name' => $u->display_name ) );
		}
		foreach ( $all as $t ) {
			$open = ! NDWD_Helpers::is_closed( $t->status );
			$over = NDWD_Tasks::is_overdue( $t, $now );
			$done = $t->completed_at && substr( $t->completed_at, 0, 10 ) >= $from && substr( $t->completed_at, 0, 10 ) <= $to;
			$flag = array( 'open' => $open, 'over' => $over, 'done' => $done );
			if ( isset( $c[ $t->status ] ) ) {
				$c[ $t->status ]++;
			}
			$c['urgent']    += $open && 'urgent' === $t->priority ? 1 : 0;
			$c['overdue']   += $over ? 1 : 0;
			$c['due_today'] += $open && $t->due_date === $today ? 1 : 0;
			if ( ! $t->assignee_ids ) {
				$c['unassigned']++;
			}
			foreach ( $t->assignee_ids as $a ) {
				if ( isset( $members[ $a ] ) ) {
					self::count_into( $members[ $a ], $t, $flag );
				}
			}
			foreach ( NDWD_Permissions::task_teams( $t ) as $tid ) {
				if ( ! isset( $teams[ $tid ] ) ) {
					$teams[ $tid ] = self::tally( array( 'team_id' => (int) $tid, 'name' => NDWD_Tasks::team_name( $tid ) ) );
				}
				self::count_into( $teams[ $tid ], $t, $flag );
			}
		}
		foreach ( array( &$members, &$teams ) as &$list ) {
			foreach ( $list as &$row ) {
				$row['completion_pct'] = $row['total'] ? round( $row['completed'] * 100 / $row['total'], 1 ) : 0;
			}
			unset( $row );
		}
		unset( $list );
		$max_open = 1;
		foreach ( $members as $m ) {
			$max_open = max( $max_open, $m['open'] );
		}
		// The task list applies the status and quick filters on top.
		$rows = 'upcoming' === ( isset( $f['view'] ) ? $f['view'] : '' ) ? $upcoming : $all;
		$view = isset( $f['view'] ) ? sanitize_key( $f['view'] ) : '';
		$rows = array_values( array_filter( $rows, function ( $t ) use ( $f, $view, $today, $now ) {
			if ( ! empty( $f['status'] ) && $t->status !== $f['status'] ) {
				return false;
			}
			if ( 'overdue' === $view ) {
				return NDWD_Tasks::is_overdue( $t, $now );
			}
			if ( 'due_today' === $view ) {
				return ! NDWD_Helpers::is_closed( $t->status ) && $t->due_date === $today;
			}
			if ( 'urgent' === $view ) {
				return ! NDWD_Helpers::is_closed( $t->status ) && 'urgent' === $t->priority;
			}
			return true;
		} ) );
		usort( $members, function ( $a, $b ) {
			return $b['open'] - $a['open'] ? $b['open'] - $a['open'] : strcmp( $a['name'], $b['name'] );
		} );
		usort( $teams, function ( $a, $b ) {
			return strcmp( $a['name'], $b['name'] );
		} );
		return array(
			'from'     => $from,
			'to'       => $to,
			'counts'   => $c,
			'pct'      => $c['total'] ? round( $c['completed'] * 100 / $c['total'], 1 ) : 0,
			'members'  => array_values( $members ),
			'teams'    => array_values( $teams ),
			'max_open' => $max_open,
			'tasks'    => self::page_of( $rows, $f, $user_id ),
			'options'  => self::options(),
		);
	}

	private static function tally( $row ) {
		return $row + array( 'total' => 0, 'open' => 0, 'pending' => 0, 'in_process' => 0, 'rejected' => 0, 'completed' => 0, 'urgent' => 0, 'overdue' => 0, 'completion_pct' => 0 );
	}

	private static function count_into( &$row, $t, $flag ) {
		$row['total']++;
		if ( isset( $row[ $t->status ] ) ) {
			$row[ $t->status ]++;
		}
		$row['open']    += $flag['open'] ? 1 : 0;
		$row['overdue'] += $flag['over'] ? 1 : 0;
		$row['urgent']  += $flag['open'] && 'urgent' === $t->priority ? 1 : 0;
	}

	/** Filter lists for every member; bootstrap limits clients and projects to what they may open. */
	private static function options() {
		global $wpdb;
		$list = function ( $name, $where = '' ) use ( $wpdb ) {
			return array_map( function ( $r ) {
				return array( 'id' => (int) $r->id, 'name' => $r->name );
			}, $wpdb->get_results( 'SELECT id, name FROM ' . NDWD_Helpers::table( $name ) . " $where ORDER BY name" ) ); // phpcs:ignore
		};
		return array(
			'teams'    => $list( 'teams', 'WHERE is_active = 1' ),
			'clients'  => $list( 'clients' ),
			'projects' => $list( 'projects' ),
			'members'  => array_map( function ( $u ) {
				return array( 'id' => (int) $u->ID, 'name' => $u->display_name );
			}, NDWD_Stats::active_members() ),
		);
	}
}
