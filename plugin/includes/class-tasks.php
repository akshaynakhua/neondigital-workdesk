<?php
/**
 * Task model: create, edit, status, remarks, follow-ups, links, and worksheet queries.
 *
 * The daily worksheet is never materialised. A task is a single row; its
 * visibility on day D is computed from scheduled_date / status / completed_at / rejected_at,
 * so carry-forward needs no copies and repeated runs cannot duplicate anything.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Tasks {

	/* ------------------------------------------------------------------ *
	 * Loading
	 * ------------------------------------------------------------------ */

	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'tasks' ) . ' WHERE id = %d', $id ) );
		if ( ! $row ) {
			return null;
		}
		$list = self::hydrate( array( $row ) );
		return $list[0];
	}

	/** Attach assignee ids, tag ids and counts to raw rows. */
	public static function hydrate( $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return array();
		}
		$ids = array_map( function ( $r ) {
			return (int) $r->id;
		}, $rows );
		$in  = NDWD_Helpers::in_placeholders( $ids );

		$assignees = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT task_id, user_id FROM ' . NDWD_Helpers::table( 'task_assignees' ) . " WHERE task_id IN ($in)", $ids ) ) as $a ) {
			$assignees[ $a->task_id ][] = (int) $a->user_id;
		}
		$teams = array();
		$past  = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT task_id, team_id, status FROM ' . NDWD_Helpers::table( 'task_teams' ) . " WHERE task_id IN ($in) ORDER BY added_at, team_id", $ids ) ) as $a ) {
			if ( 'active' === $a->status ) {
				$teams[ $a->task_id ][] = (int) $a->team_id;
			} else {
				$past[ $a->task_id ][] = (int) $a->team_id;
			}
		}
		$tags = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT task_id, tag_id FROM ' . NDWD_Helpers::table( 'task_tags' ) . " WHERE task_id IN ($in)", $ids ) ) as $a ) {
			$tags[ $a->task_id ][] = (int) $a->tag_id;
		}
		$counts = array();
		foreach ( array( 'task_links' => 'links', 'remarks' => 'remarks' ) as $table => $key ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT task_id, COUNT(*) c FROM ' . NDWD_Helpers::table( $table ) . " WHERE task_id IN ($in) GROUP BY task_id", $ids ) ) as $a ) {
				$counts[ $a->task_id ][ $key ] = (int) $a->c;
			}
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT parent_id, COUNT(*) c FROM ' . NDWD_Helpers::table( 'tasks' ) . " WHERE parent_id IN ($in) AND is_archived = 0 GROUP BY parent_id", $ids ) ) as $a ) {
			$counts[ $a->parent_id ]['followups'] = (int) $a->c;
		}
		foreach ( $rows as $r ) {
			$r->assignee_ids = isset( $assignees[ $r->id ] ) ? $assignees[ $r->id ] : array();
			// Lead team first; a lead team without a task_teams row (older data) still counts.
			$tids = isset( $teams[ $r->id ] ) ? $teams[ $r->id ] : array();
			if ( $r->team_id ) {
				$tids = array_merge( array( (int) $r->team_id ), array_diff( $tids, array( (int) $r->team_id ) ) );
			}
			$r->team_ids      = array_values( $tids );
			$r->past_team_ids = isset( $past[ $r->id ] ) ? array_values( array_diff( $past[ $r->id ], $tids ) ) : array();
			$r->tag_ids      = isset( $tags[ $r->id ] ) ? $tags[ $r->id ] : array();
			$r->counts       = isset( $counts[ $r->id ] ) ? $counts[ $r->id ] : array();
		}
		return $rows;
	}

	public static function is_overdue( $t, $now = null ) {
		if ( NDWD_Helpers::is_closed( $t->status ) || empty( $t->due_date ) ) {
			return false;
		}
		$now   = $now ? $now : NDWD_Helpers::now();
		$today = $now->format( 'Y-m-d' );
		if ( $t->due_date < $today ) {
			return true;
		}
		return $t->due_date === $today && ! empty( $t->due_time ) && $t->due_time < $now->format( 'H:i:s' );
	}

	public static function to_array( $t, $detail = false ) {
		$uid   = get_current_user_id();
		$today = NDWD_Helpers::today();
		$out   = array(
			'id'             => (int) $t->id,
			'parent_id'      => $t->parent_id ? (int) $t->parent_id : null,
			'title'          => $t->title,
			'description'    => (string) $t->description,
			'project_id'     => $t->project_id ? (int) $t->project_id : null,
			'client_id'      => ! empty( $t->client_id ) ? (int) $t->client_id : null,
			'team_id'        => $t->team_id ? (int) $t->team_id : null,
			'team_ids'       => $t->team_ids,
			'past_team_ids'  => $t->past_team_ids,
			'priority'       => $t->priority,
			'status'         => $t->status,
			'work_type'      => $t->work_type,
			'scheduled_date' => $t->scheduled_date,
			'due_date'       => $t->due_date,
			'due_time'       => $t->due_time ? substr( $t->due_time, 0, 5 ) : null,
			'reminder_at'    => $t->reminder_at ? substr( str_replace( ' ', 'T', $t->reminder_at ), 0, 16 ) : null,
			'completed_at'   => $t->completed_at,
			'rejected_at'    => isset( $t->rejected_at ) ? $t->rejected_at : null,
			'created_by'     => (int) $t->created_by,
			'created_by_name' => NDWD_Helpers::user_name( $t->created_by ),
			'created_at'     => $t->created_at,
			'updated_at'     => $t->updated_at,
			'source'         => $t->source,
			'assignee_ids'   => $t->assignee_ids,
			'assignee_names' => array_map( array( 'NDWD_Helpers', 'user_name' ), $t->assignee_ids ),
			'tag_ids'        => $t->tag_ids,
			'counts'         => (object) $t->counts,
			'is_overdue'     => self::is_overdue( $t ),
			'is_carried'     => ! NDWD_Helpers::is_closed( $t->status ) && $t->scheduled_date < $today,
			'can_edit'       => NDWD_Permissions::can_edit_task( $t, $uid ),
			'can_work'       => NDWD_Permissions::can_work_on_task( $t, $uid ),
			'can_archive'    => NDWD_Permissions::can_archive_task( $t, $uid ),
			'can_pickup'     => NDWD_Workflow::can_pickup( $t, $uid ),
			'package'        => NDWD_Packages::task_summary( $t ),
		);
		if ( $detail ) {
			global $wpdb;
			$out['handoff_from'] = NDWD_Permissions::handoff_teams( $t, $uid );
			$out['can_handoff']  = (bool) $out['handoff_from'];
			$out['can_reassign'] = NDWD_Permissions::can_reassign_task( $t, $uid );
			$out['can_member_handoff'] = NDWD_Workflow::can_member_handoff( $t, $uid );
			$out['can_release']  = $out['can_reassign'] && ! empty( $t->assignee_ids ) && ! empty( $t->team_id ) && ! NDWD_Helpers::is_closed( $t->status );
			$out['claimed_by']   = ! empty( $t->claimed_by ) ? NDWD_Helpers::user_name( $t->claimed_by ) : '';
			$out['claimed_at']   = ! empty( $t->claimed_at ) ? $t->claimed_at : null;
			$out['handoffs']     = self::handoffs( $t->id );
			$out['links']   = array_map( function ( $l ) {
				return array( 'id' => (int) $l->id, 'url' => $l->url, 'label' => $l->label );
			}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'task_links' ) . ' WHERE task_id = %d ORDER BY id', $t->id ) ) );
			$out['remarks'] = array_map( function ( $r ) {
				return array( 'id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'user' => NDWD_Helpers::user_name( $r->user_id ), 'body' => $r->body, 'created_at' => $r->created_at );
			}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'remarks' ) . ' WHERE task_id = %d ORDER BY id DESC', $t->id ) ) );
			$out['activity'] = NDWD_Activity::for_task( $t->id );
			$followups       = self::hydrate( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'tasks' ) . ' WHERE parent_id = %d AND is_archived = 0 ORDER BY scheduled_date, id', $t->id ) ) );
			$out['followups'] = array();
			foreach ( $followups as $f ) {
				if ( NDWD_Permissions::can_view_task( $f, $uid ) ) {
					$out['followups'][] = self::to_array( $f );
				}
			}
			$out['parent'] = null;
			if ( $t->parent_id ) {
				$p = self::get( $t->parent_id );
				if ( $p && NDWD_Permissions::can_view_task( $p, $uid ) ) {
					$out['parent'] = array( 'id' => (int) $p->id, 'title' => $p->title, 'status' => $p->status );
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Validation
	 * ------------------------------------------------------------------ */

	/**
	 * Validates and normalises input. $partial = true for updates (only provided keys).
	 *
	 * @return array|WP_Error
	 */
	public static function validate( $in, $partial = false ) {
		global $wpdb;
		$d = array();

		if ( ! $partial || array_key_exists( 'title', $in ) ) {
			$title = sanitize_text_field( isset( $in['title'] ) ? $in['title'] : '' );
			if ( '' === $title ) {
				return new WP_Error( 'ndwd_invalid', 'Task / Activity title is required.', array( 'status' => 400 ) );
			}
			$d['title'] = mb_substr( $title, 0, 255 );
		}
		if ( array_key_exists( 'description', $in ) ) {
			$d['description'] = sanitize_textarea_field( (string) $in['description'] );
		}
		if ( array_key_exists( 'project_id', $in ) ) {
			$pid = absint( $in['project_id'] );
			if ( $pid && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $pid ) ) ) {
				return new WP_Error( 'ndwd_invalid', 'Unknown project / client.', array( 'status' => 400 ) );
			}
			$d['project_id'] = $pid ? $pid : null;
		}
		if ( array_key_exists( 'client_id', $in ) ) {
			$cid = absint( $in['client_id'] );
			if ( $cid && ! NDWD_Clients::get( $cid ) ) {
				return new WP_Error( 'ndwd_invalid', 'Unknown client.', array( 'status' => 400 ) );
			}
			$d['client_id'] = $cid ? $cid : null;
		}
		if ( array_key_exists( 'team_id', $in ) ) {
			$tid = absint( $in['team_id'] );
			if ( $tid && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'teams' ) . ' WHERE id = %d AND is_active = 1', $tid ) ) ) {
				return new WP_Error( 'ndwd_invalid', 'Unknown or inactive team.', array( 'status' => 400 ) );
			}
			$d['team_id']  = $tid ? $tid : null;
			$d['team_ids'] = $tid ? array( $tid ) : array();
		}
		if ( array_key_exists( 'team_ids', $in ) ) {
			// Several teams: the first one is the lead team (tasks.team_id).
			$ids = array_values( array_unique( NDWD_Helpers::int_list( $in['team_ids'] ) ) );
			if ( count( $ids ) > 20 ) {
				return new WP_Error( 'ndwd_invalid', 'A task can be assigned to at most 20 teams.', array( 'status' => 400 ) );
			}
			if ( $ids ) {
				$found = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'teams' ) . ' WHERE is_active = 1 AND id IN (' . NDWD_Helpers::in_placeholders( $ids ) . ')', $ids ) ) );
				if ( count( $found ) !== count( $ids ) ) {
					return new WP_Error( 'ndwd_invalid', 'Unknown or inactive team.', array( 'status' => 400 ) );
				}
			}
			$d['team_ids'] = $ids;
			$d['team_id']  = $ids ? $ids[0] : null;
		}
		if ( array_key_exists( 'assignee_ids', $in ) ) {
			$ids = NDWD_Helpers::int_list( $in['assignee_ids'] );
			foreach ( $ids as $uid ) {
				if ( ! get_userdata( $uid ) || ! NDWD_Permissions::can( 'ndwd_access', $uid ) ) {
					return new WP_Error( 'ndwd_invalid', 'One of the selected assignees is not an active member.', array( 'status' => 400 ) );
				}
			}
			$d['assignee_ids'] = $ids;
		}
		if ( array_key_exists( 'tag_ids', $in ) ) {
			$ids = NDWD_Helpers::int_list( $in['tag_ids'] );
			if ( $ids ) {
				$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'tags' ) . ' WHERE id IN (' . NDWD_Helpers::in_placeholders( $ids ) . ')', $ids ) ) );
			}
			$d['tag_ids'] = $ids;
		}
		if ( ! $partial || array_key_exists( 'priority', $in ) ) {
			$p = isset( $in['priority'] ) ? sanitize_key( $in['priority'] ) : 'medium';
			if ( ! in_array( $p, NDWD_Helpers::PRIORITY_KEYS, true ) ) {
				return new WP_Error( 'ndwd_invalid', 'Invalid priority.', array( 'status' => 400 ) );
			}
			$d['priority'] = $p;
		}
		if ( ! $partial || array_key_exists( 'work_type', $in ) ) {
			$w     = isset( $in['work_type'] ) ? sanitize_key( $in['work_type'] ) : 'office';
			$types = NDWD_Helpers::work_types();
			if ( ! isset( $types[ $w ] ) ) {
				return new WP_Error( 'ndwd_invalid', 'Invalid work type.', array( 'status' => 400 ) );
			}
			$d['work_type'] = $w;
		}
		if ( ! $partial || array_key_exists( 'scheduled_date', $in ) ) {
			$s = isset( $in['scheduled_date'] ) && '' !== $in['scheduled_date'] ? NDWD_Helpers::sanitize_date( $in['scheduled_date'] ) : NDWD_Helpers::today();
			if ( ! $s ) {
				return new WP_Error( 'ndwd_invalid', 'Scheduled date is invalid.', array( 'status' => 400 ) );
			}
			$d['scheduled_date'] = $s;
		}
		foreach ( array( 'due_date' => 'sanitize_date', 'due_time' => 'sanitize_time', 'reminder_at' => 'sanitize_datetime' ) as $key => $fn ) {
			if ( array_key_exists( $key, $in ) ) {
				$raw = is_string( $in[ $key ] ) ? trim( $in[ $key ] ) : '';
				if ( '' === $raw ) {
					$d[ $key ] = null;
					continue;
				}
				$v = call_user_func( array( 'NDWD_Helpers', $fn ), $raw );
				if ( ! $v ) {
					return new WP_Error( 'ndwd_invalid', 'Invalid ' . str_replace( '_', ' ', $key ) . '.', array( 'status' => 400 ) );
				}
				$d[ $key ] = $v;
			}
		}
		if ( isset( $d['due_date'], $d['scheduled_date'] ) && $d['due_date'] < $d['scheduled_date'] ) {
			return new WP_Error( 'ndwd_invalid', 'Due date cannot be before the scheduled date.', array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'links', $in ) ) {
			$links = array();
			foreach ( (array) $in['links'] as $l ) {
				$url = is_array( $l ) && isset( $l['url'] ) ? $l['url'] : '';
				if ( '' === trim( (string) $url ) ) {
					continue;
				}
				$clean = NDWD_Helpers::sanitize_link_url( $url );
				if ( ! $clean ) {
					return new WP_Error( 'ndwd_invalid', 'Invalid link: ' . sanitize_text_field( $url ) . ' (only http/https links are allowed).', array( 'status' => 400 ) );
				}
				$links[] = array(
					'id'    => isset( $l['id'] ) ? absint( $l['id'] ) : 0,
					'url'   => $clean,
					'label' => mb_substr( sanitize_text_field( isset( $l['label'] ) ? $l['label'] : '' ), 0, 200 ),
				);
			}
			if ( count( $links ) > 30 ) {
				return new WP_Error( 'ndwd_invalid', 'A task can have at most 30 links.', array( 'status' => 400 ) );
			}
			$d['links'] = $links;
		}
		if ( array_key_exists( 'remarks', $in ) ) {
			$d['remarks'] = sanitize_textarea_field( (string) $in['remarks'] );
		}
		return $d;
	}

	/* ------------------------------------------------------------------ *
	 * Writes
	 * ------------------------------------------------------------------ */

	/**
	 * Creates a task. New tasks always start as Pending unless an import
	 * explicitly preserves the source status ($opts['status']).
	 *
	 * @return object|WP_Error
	 */
	public static function create( $input, $user_id, $opts = array() ) {
		global $wpdb;
		$d = self::validate( $input );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$ctx = self::project_context( $input, $d );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$d         = $ctx;
		$assignees = isset( $d['assignee_ids'] ) ? $d['assignee_ids'] : array();
		$team_id   = isset( $d['team_id'] ) ? $d['team_id'] : null;
		$team_ids  = isset( $d['team_ids'] ) ? $d['team_ids'] : array();
		if ( ! $assignees && ! $team_id ) {
			$assignees = array( (int) $user_id );
		}
		if ( empty( $opts['skip_assign_check'] ) && ! NDWD_Permissions::can_assign( $assignees, $team_ids, $user_id ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You can only assign work to members of your own teams.', array( 'status' => 403 ) );
		}
		$client = self::match_client( isset( $d['project_id'] ) ? $d['project_id'] : null, ! empty( $d['client_id'] ) ? $d['client_id'] : null );
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		// Optional client package: checked (including its limits) before anything is saved.
		$pkg = NDWD_Packages::validate_task_link( $input, $client, $d['scheduled_date'], $d['title'], $user_id, null, isset( $d['project_id'] ) ? (int) $d['project_id'] : 0 );
		if ( is_wp_error( $pkg ) ) {
			return $pkg;
		}

		$status = 'pending';
		if ( ! empty( $opts['status'] ) && isset( NDWD_Helpers::STATUSES[ $opts['status'] ] ) ) {
			$status = $opts['status'];
		}
		$now = NDWD_Helpers::now_mysql();
		$row = array(
			'parent_id'      => isset( $opts['parent_id'] ) ? (int) $opts['parent_id'] : null,
			'title'          => $d['title'],
			'description'    => isset( $d['description'] ) ? $d['description'] : '',
			'project_id'     => isset( $d['project_id'] ) ? $d['project_id'] : null,
			'client_id'      => $client,
			'team_id'        => $team_id,
			'priority'       => $d['priority'],
			'status'         => $status,
			'work_type'      => $d['work_type'],
			'scheduled_date' => $d['scheduled_date'],
			'due_date'       => isset( $d['due_date'] ) ? $d['due_date'] : null,
			'due_time'       => isset( $d['due_time'] ) ? $d['due_time'] : null,
			'reminder_at'    => isset( $d['reminder_at'] ) ? $d['reminder_at'] : null,
			'reminder_sent'  => 0,
			'completed_at'   => 'completed' === $status ? ( isset( $opts['completed_at'] ) ? $opts['completed_at'] : $now ) : null,
			'completed_by'   => 'completed' === $status ? (int) $user_id : null,
			'rejected_at'    => 'rejected' === $status ? $now : null,
			'rejected_by'    => 'rejected' === $status ? (int) $user_id : null,
			'client_package_id' => $pkg['client_package_id'],
			'package_item_id' => $pkg['package_item_id'],
			'package_scope'  => $pkg['package_scope'],
			'package_note'   => $pkg['package_note'],
			'source'         => isset( $opts['source'] ) ? $opts['source'] : 'manual',
			'import_hash'    => isset( $opts['import_hash'] ) ? $opts['import_hash'] : null,
			'created_by'     => (int) $user_id,
			'created_at'     => $now,
			'updated_at'     => $now,
		);
		if ( false === $wpdb->insert( NDWD_Helpers::table( 'tasks' ), $row ) ) {
			return new WP_Error( 'ndwd_db', 'Could not save the task.', array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;

		self::sync_assignees( $id, $assignees );
		self::sync_teams( $id, $team_ids, $user_id );
		self::sync_tags( $id, isset( $d['tag_ids'] ) ? $d['tag_ids'] : array() );
		if ( ! empty( $d['links'] ) ) {
			self::sync_links( $id, $d['links'], $user_id );
		}
		if ( ! empty( $d['remarks'] ) ) {
			self::insert_remark( $id, $user_id, $d['remarks'] );
		}
		if ( '' !== trim( $pkg['package_note'] ) || '' !== $pkg['_approve'] ) {
			self::insert_remark( $id, $user_id, NDWD_Packages::remark_text( $pkg ) );
		}

		$action = isset( $opts['activity'] ) ? $opts['activity'] : 'task_created';
		NDWD_Activity::log( $user_id, $id, $action, array( 'title' => $row['title'] ), empty( $opts['not_meaningful'] ) );
		NDWD_Packages::sync_task( $id, $user_id, $pkg['_approve'] );

		$task = self::get( $id );
		if ( empty( $opts['silent'] ) ) {
			NDWD_Notifications::task_assigned( $task, self::notify_targets( $task ), $user_id, ! empty( $opts['parent_id'] ) ? 'followup' : 'assigned' );
		}
		return $task;
	}

	/** Users who should hear about a task: assignees, or team members for team-only tasks. */
	public static function notify_targets( $task ) {
		if ( $task->assignee_ids ) {
			return $task->assignee_ids;
		}
		return NDWD_Permissions::members_of( NDWD_Permissions::task_teams( $task ) );
	}

	/**
	 * Client for a task given its project and chosen client. A project that belongs to a
	 * client decides the client; a different explicit client is refused. Client-only tasks
	 * (no project) and projects without a client keep the chosen client.
	 *
	 * @return int|null|WP_Error
	 */
	/**
	 * Phase 5: a task created from a project dashboard. From a main project's dashboard the task goes
	 * to that project or one of its own sub-projects (nothing else). From a sub-project's dashboard it
	 * goes to that sub-project only; its main project follows from the hierarchy and cannot be
	 * changed. The client always follows the project.
	 *
	 * @return array|WP_Error The task fields, corrected.
	 */
	private static function project_context( $input, $d ) {
		global $wpdb;
		$ctx = absint( $input['context_project_id'] ?? 0 );
		if ( ! $ctx ) {
			return $d;
		}
		$t   = NDWD_Helpers::table( 'projects' );
		$cp  = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, parent_id, client_id FROM $t WHERE id = %d", $ctx ) ); // phpcs:ignore
		if ( ! $cp ) {
			return new WP_Error( 'ndwd_invalid', 'Unknown project.', array( 'status' => 400 ) );
		}
		$pid = isset( $d['project_id'] ) && $d['project_id'] ? (int) $d['project_id'] : (int) $cp->id;
		if ( $cp->parent_id ) {
			if ( $pid !== (int) $cp->id ) {
				return new WP_Error( 'ndwd_project_locked', 'A task created in the sub-project ' . $cp->name . ' belongs to it. Its project cannot be changed here.', array( 'status' => 409 ) );
			}
		} elseif ( $pid !== (int) $cp->id && (int) $wpdb->get_var( $wpdb->prepare( "SELECT parent_id FROM $t WHERE id = %d", $pid ) ) !== (int) $cp->id ) { // phpcs:ignore
			return new WP_Error( 'ndwd_project_locked', 'From the ' . $cp->name . ' dashboard a task can go to that project or one of its own sub-projects only.', array( 'status' => 409 ) );
		}
		$d['project_id'] = $pid;
		$root            = $cp->parent_id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT client_id FROM $t WHERE id = %d", $cp->parent_id ) ) : (int) $cp->client_id; // phpcs:ignore
		$client          = (int) $cp->client_id ? (int) $cp->client_id : $root;
		if ( $client ) {
			$d['client_id'] = $client;
		}
		return $d;
	}

	private static function match_client( $project_id, $client_id ) {
		$pc = $project_id ? NDWD_Clients::for_project( $project_id ) : null;
		if ( ! $pc ) {
			return $client_id ? (int) $client_id : null;
		}
		if ( $client_id && (int) $client_id !== $pc ) {
			return new WP_Error( 'ndwd_invalid', 'The selected project belongs to a different client. Choose a project of this client, or no project.', array( 'status' => 400 ) );
		}
		return $pc;
	}

	/**
	 * @param array $context Internal only (never request input): 'authorized' => true when
	 *                       the caller already checked rights (leave reassignment), 'reason'
	 *                       and 'leave_id' are recorded in the reassignment history.
	 * @return object|WP_Error
	 */
	public static function update( $id, $input, $user_id, $context = array() ) {
		global $wpdb;
		$task = self::get( $id );
		if ( ! $task || $task->is_archived ) {
			return new WP_Error( 'ndwd_not_found', 'Task not found.', array( 'status' => 404 ) );
		}
		$authorized = ! empty( $context['authorized'] );
		if ( ! $authorized && ! NDWD_Permissions::can_edit_task( $task, $user_id ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You do not have permission to edit this task.', array( 'status' => 403 ) );
		}
		unset( $input['status'], $input['remarks'] ); // Status has its own endpoint; remarks are append-only.
		$d = self::validate( $input, true );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$sched = isset( $d['scheduled_date'] ) ? $d['scheduled_date'] : $task->scheduled_date;
		$due   = array_key_exists( 'due_date', $d ) ? $d['due_date'] : $task->due_date;
		if ( $due && $due < $sched ) {
			return new WP_Error( 'ndwd_invalid', 'Due date cannot be before the scheduled date.', array( 'status' => 400 ) );
		}

		$new_assignees = array_key_exists( 'assignee_ids', $d ) ? $d['assignee_ids'] : $task->assignee_ids;
		$new_teams     = array_key_exists( 'team_ids', $d ) ? $d['team_ids'] : $task->team_ids;
		if ( array_key_exists( 'team_ids', $d ) && $d['team_ids'] && $task->team_id && in_array( (int) $task->team_id, $d['team_ids'], true ) ) {
			$d['team_id'] = (int) $task->team_id; // Keep the current lead team while it stays assigned.
		}
		$new_team = array_key_exists( 'team_id', $d ) ? $d['team_id'] : $task->team_id;
		if ( ! $new_assignees && ! $new_team ) {
			return new WP_Error( 'ndwd_invalid', 'A task needs at least one assignee or a team.', array( 'status' => 400 ) );
		}
		$assign_changed = array_key_exists( 'assignee_ids', $d ) && self::set_differs( $task->assignee_ids, $d['assignee_ids'] );
		$teams_changed  = array_key_exists( 'team_ids', $d ) && self::set_differs( $task->team_ids, $d['team_ids'] );
		if ( ! $authorized && ( $assign_changed || $teams_changed ) ) {
			// Only the people and teams being added or removed need the right to assign;
			// assignees left as they were (e.g. added earlier by an Admin) are not re-checked.
			$touched_people = array_merge( array_diff( $new_assignees, $task->assignee_ids ), array_diff( $task->assignee_ids, $new_assignees ) );
			$added_teams    = array_values( array_diff( $new_teams, $task->team_ids ) );
			if ( ! NDWD_Permissions::can_assign( $touched_people, $added_teams, $user_id ) || ( ! $new_assignees && ! NDWD_Permissions::can_assign( array(), $new_teams, $user_id ) ) ) {
				return new WP_Error( 'ndwd_forbidden', 'You can only assign work to members of your own teams.', array( 'status' => 403 ) );
			}
			// Taking a task away from someone else is a separate, explicitly granted permission.
			$taken_from = array_diff( $task->assignee_ids, $new_assignees, array( (int) $user_id ) );
			if ( $taken_from && ! NDWD_Permissions::can_reassign_task( $task, $user_id ) ) {
				return new WP_Error( 'ndwd_forbidden', 'You do not have permission to manage or reassign other members\' tasks.', array( 'status' => 403 ) );
			}
		}

		// Client and project stay consistent. Only checked when either one changes, so
		// older tasks with a mismatched pair can still be edited.
		$new_project = array_key_exists( 'project_id', $d ) ? $d['project_id'] : $task->project_id;
		$new_client  = array_key_exists( 'client_id', $d ) ? $d['client_id'] : $task->client_id;
		$project_moved = (int) $new_project !== (int) $task->project_id;
		$client_moved  = (int) $new_client !== (int) $task->client_id;
		if ( $project_moved || $client_moved ) {
			$pc = $new_project ? NDWD_Clients::for_project( $new_project ) : null;
			if ( $pc && (int) $new_client !== $pc ) {
				if ( ! $client_moved ) {
					$d['client_id'] = $pc; // New project chosen: its client follows.
				} elseif ( ! $project_moved ) {
					$d['project_id'] = null; // Client changed: the other client's project no longer applies.
				} else {
					return new WP_Error( 'ndwd_invalid', 'The selected project belongs to a different client. Choose a project of this client, or no project.', array( 'status' => 400 ) );
				}
			}
		}

		// Package link: re-checked when it, the client or the date changes. A package of the
		// previous client is dropped when the task moves to another client.
		$final_client = array_key_exists( 'client_id', $d ) ? $d['client_id'] : $task->client_id;
		$pkg_keys     = array_intersect_key( $input, array_flip( array( 'client_package_id', 'package_item_id', 'package_scope', 'package_note', 'package_override', 'package_override_reason' ) ) );
		if ( $task->client_package_id && (int) $final_client !== (int) $task->client_id && ! array_key_exists( 'client_package_id', $pkg_keys ) ) {
			$pkg_keys['client_package_id'] = 0;
			$pkg_keys['package_scope']     = '';
		}
		$pkg = null;
		if ( $pkg_keys || (int) $final_client !== (int) $task->client_id || ( isset( $d['scheduled_date'] ) && $d['scheduled_date'] !== $task->scheduled_date && $task->client_package_id ) ) {
			$pkg = NDWD_Packages::validate_task_link( $pkg_keys, $final_client, $sched, isset( $d['title'] ) ? $d['title'] : $task->title, $user_id, $task, (int) ( array_key_exists( 'project_id', $d ) ? $d['project_id'] : $task->project_id ) );
			if ( is_wp_error( $pkg ) ) {
				return $pkg;
			}
			foreach ( array( 'client_package_id', 'package_item_id', 'package_scope', 'package_note' ) as $k ) {
				$d[ $k ] = $pkg[ $k ];
			}
		}

		$fields  = array( 'title', 'description', 'project_id', 'client_id', 'team_id', 'priority', 'work_type', 'scheduled_date', 'due_date', 'due_time', 'reminder_at', 'client_package_id', 'package_item_id', 'package_scope', 'package_note' );
		$update  = array();
		$changes = array();
		foreach ( $fields as $f ) {
			if ( ! array_key_exists( $f, $d ) ) {
				continue;
			}
			$old = $task->$f;
			$new = $d[ $f ];
			if ( (string) $old !== (string) $new ) {
				$update[ $f ]  = $new;
				$changes[ $f ] = array( 'from' => $old, 'to' => $new );
			}
		}
		if ( isset( $update['reminder_at'] ) || array_key_exists( 'reminder_at', $update ) ) {
			$update['reminder_sent'] = 0;
		}
		if ( $update ) {
			$update['updated_at'] = NDWD_Helpers::now_mysql();
			$wpdb->update( NDWD_Helpers::table( 'tasks' ), $update, array( 'id' => $id ) );
		}

		$removed = array();
		$added   = array();
		if ( $assign_changed ) {
			$added   = array_values( array_diff( $d['assignee_ids'], $task->assignee_ids ) );
			$removed = array_values( array_diff( $task->assignee_ids, $d['assignee_ids'] ) );
			self::sync_assignees( $id, $d['assignee_ids'] );
			$details = array(
				'from' => array_map( array( 'NDWD_Helpers', 'user_name' ), $task->assignee_ids ),
				'to'   => array_map( array( 'NDWD_Helpers', 'user_name' ), $d['assignee_ids'] ),
			);
			if ( ! empty( $context['reason'] ) ) {
				$details['reason'] = $context['reason'];
			}
			if ( ! empty( $context['leave_id'] ) ) {
				$details['leave_id'] = (int) $context['leave_id'];
			}
			NDWD_Activity::log( $user_id, $id, 'reassigned', $details );
		}
		if ( $teams_changed ) {
			self::sync_teams( $id, $d['team_ids'], $user_id );
			NDWD_Activity::log( $user_id, $id, 'teams_changed', array(
				'teams' => array(
					'from' => array_map( array( __CLASS__, 'team_name' ), $task->team_ids ),
					'to'   => array_map( array( __CLASS__, 'team_name' ), $d['team_ids'] ),
				),
			) );
		}
		if ( array_key_exists( 'tag_ids', $d ) && self::set_differs( $task->tag_ids, $d['tag_ids'] ) ) {
			self::sync_tags( $id, $d['tag_ids'] );
			$changes['tags'] = array( 'from' => count( $task->tag_ids ), 'to' => count( $d['tag_ids'] ) );
		}
		if ( array_key_exists( 'links', $d ) && self::sync_links( $id, $d['links'], $user_id ) ) {
			NDWD_Activity::log( $user_id, $id, 'links_changed', array( 'count' => count( $d['links'] ) ) );
		}
		if ( isset( $changes['scheduled_date'] ) || isset( $changes['due_date'] ) ) {
			NDWD_Activity::log( $user_id, $id, 'rescheduled', array_intersect_key( $changes, array( 'scheduled_date' => 1, 'due_date' => 1, 'due_time' => 1 ) ) );
		}
		$pkg_changes = array_intersect_key( $changes, array( 'client_package_id' => 1, 'package_item_id' => 1, 'package_scope' => 1, 'package_note' => 1 ) );
		if ( $pkg_changes || ( $pkg && '' !== $pkg['_approve'] ) ) {
			NDWD_Activity::log( $user_id, $id, 'package_linked', array_filter( array( 'scope' => isset( $pkg_changes['package_scope'] ) ? $pkg_changes['package_scope'] : '', 'note' => isset( $pkg_changes['package_note'] ) ? 'edited' : '' ) ) );
			if ( '' !== trim( $pkg['package_note'] ) || '' !== $pkg['_approve'] ) {
				self::insert_remark( $id, $user_id, NDWD_Packages::remark_text( $pkg ) );
			}
		}
		NDWD_Packages::sync_task( $id, $user_id, $pkg ? $pkg['_approve'] : '' );
		$other = array_diff_key( $changes, array( 'scheduled_date' => 1, 'due_date' => 1, 'due_time' => 1 ), $teams_changed ? array( 'team_id' => 1 ) : array(), $pkg_changes );
		if ( $other ) {
			$readable = array();
			foreach ( $other as $f => $c ) {
				if ( in_array( $f, array( 'description' ), true ) ) {
					$readable[ $f ] = 'edited';
					continue;
				}
				$readable[ $f ] = $c;
			}
			NDWD_Activity::log( $user_id, $id, 'task_updated', $readable );
		}

		$fresh = self::get( $id );
		if ( $added ) {
			NDWD_Notifications::task_assigned( $fresh, $added, $user_id, 'reassigned' );
		}
		if ( $removed ) {
			NDWD_Notifications::notify_users( $removed, $fresh, 'unassigned', NDWD_Helpers::user_name( $user_id ) . ' removed you from: ' . $fresh->title, $user_id );
		}
		$important = array_intersect_key( $changes, array( 'priority' => 1, 'scheduled_date' => 1, 'due_date' => 1, 'due_time' => 1, 'title' => 1 ) );
		if ( $important ) {
			$what = implode( ', ', array_map( function ( $k ) {
				return str_replace( '_', ' ', $k );
			}, array_keys( $important ) ) );
			$recipients = array_diff( array_unique( array_merge( $fresh->assignee_ids, array( (int) $fresh->created_by ) ) ), $added );
			NDWD_Notifications::notify_users( $recipients, $fresh, 'updated', NDWD_Helpers::user_name( $user_id ) . " updated $what: " . $fresh->title, $user_id );
			if ( isset( $changes['priority'] ) && 'urgent' === $fresh->priority ) {
				NDWD_Notifications::notify_users( $fresh->assignee_ids, $fresh, 'urgent', 'Marked URGENT: ' . $fresh->title, $user_id );
			}
		}
		return $fresh;
	}

	/** @return object|WP_Error */
	public static function set_status( $id, $status, $user_id, $note = '' ) {
		global $wpdb;
		$task = self::get( $id );
		if ( ! $task || $task->is_archived ) {
			return new WP_Error( 'ndwd_not_found', 'Task not found.', array( 'status' => 404 ) );
		}
		if ( isset( NDWD_Helpers::LEGACY_STATUSES[ $status ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'The Blocked status has been replaced by Rejected. Reload WorkDesk and choose Pending, In Process, Rejected or Completed.', array( 'status' => 400 ) );
		}
		if ( ! isset( NDWD_Helpers::STATUSES[ $status ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Invalid status.', array( 'status' => 400 ) );
		}
		if ( ! NDWD_Permissions::can_work_on_task( $task, $user_id ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You do not have permission to change this task.', array( 'status' => 403 ) );
		}
		if ( $status === $task->status ) {
			return $task;
		}
		$now    = NDWD_Helpers::now_mysql();
		$update = array( 'status' => $status, 'updated_at' => $now );
		if ( 'completed' === $status ) {
			$update['completed_at'] = $now;
			$update['completed_by'] = (int) $user_id;
		} else {
			$update['completed_at'] = null;
			$update['completed_by'] = null;
		}
		// Rejected closes the task like Completed (no carry-forward); reopening clears it.
		$update['rejected_at'] = 'rejected' === $status ? $now : null;
		$update['rejected_by'] = 'rejected' === $status ? (int) $user_id : null;
		$wpdb->update( NDWD_Helpers::table( 'tasks' ), $update, array( 'id' => $id ) );
		NDWD_Packages::sync_task( $id, $user_id );
		$action = 'completed' === $status ? 'task_completed' : ( 'rejected' === $status ? 'task_rejected' : 'status_changed' );
		NDWD_Activity::log( $user_id, $id, $action, array(
			'from' => NDWD_Helpers::status_label( $task->status ),
			'to'   => NDWD_Helpers::status_label( $status ),
		) );
		$note = sanitize_textarea_field( (string) $note );
		if ( '' !== $note ) {
			self::insert_remark( $id, $user_id, $note );
		}
		$fresh      = self::get( $id );
		$recipients = array_unique( array_merge( $fresh->assignee_ids, array( (int) $fresh->created_by ) ) );
		NDWD_Notifications::notify_users( $recipients, $fresh, 'status', NDWD_Helpers::user_name( $user_id ) . ' set "' . $fresh->title . '" to ' . NDWD_Helpers::status_label( $status ), $user_id );
		return $fresh;
	}

	public static function add_remark( $id, $body, $user_id ) {
		$task = self::get( $id );
		if ( ! $task || $task->is_archived ) {
			return new WP_Error( 'ndwd_not_found', 'Task not found.', array( 'status' => 404 ) );
		}
		if ( ! NDWD_Permissions::can_view_task( $task, $user_id ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You cannot comment on this task.', array( 'status' => 403 ) );
		}
		$body = sanitize_textarea_field( (string) $body );
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'ndwd_invalid', 'Remark cannot be empty.', array( 'status' => 400 ) );
		}
		self::insert_remark( $id, $user_id, $body );
		NDWD_Activity::log( $user_id, $id, 'remark_added', array( 'text' => mb_substr( $body, 0, 140 ) ) );
		$recipients = array_unique( array_merge( $task->assignee_ids, array( (int) $task->created_by ) ) );
		NDWD_Notifications::notify_users( $recipients, $task, 'remark', NDWD_Helpers::user_name( $user_id ) . ' added a remark on: ' . $task->title, $user_id );
		return self::get( $id );
	}

	public static function insert_remark( $task_id, $user_id, $body ) {
		global $wpdb;
		$wpdb->insert( NDWD_Helpers::table( 'remarks' ), array(
			'task_id'    => (int) $task_id,
			'user_id'    => (int) $user_id,
			'body'       => $body,
			'created_at' => NDWD_Helpers::now_mysql(),
		) );
	}

	/** Creates a separate, linked task. Works from completed tasks too. */
	public static function create_followup( $parent_id, $input, $user_id ) {
		$parent = self::get( $parent_id );
		if ( ! $parent || $parent->is_archived ) {
			return new WP_Error( 'ndwd_not_found', 'Original task not found.', array( 'status' => 404 ) );
		}
		if ( ! NDWD_Permissions::can_view_task( $parent, $user_id ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You cannot create follow-ups for this task.', array( 'status' => 403 ) );
		}
		if ( ! array_key_exists( 'project_id', $input ) ) {
			$input['project_id'] = $parent->project_id;
		}
		if ( isset( $input['notes'] ) && ! isset( $input['description'] ) ) {
			$input['description'] = $input['notes'];
		}
		$task = self::create( $input, $user_id, array( 'parent_id' => $parent->id, 'source' => 'followup', 'activity' => 'task_created' ) );
		if ( ! is_wp_error( $task ) ) {
			NDWD_Activity::log( $user_id, $parent->id, 'followup_created', array( 'followup_id' => (int) $task->id, 'title' => $task->title, 'scheduled_date' => $task->scheduled_date ) );
		}
		return $task;
	}

	public static function archive( $id, $user_id ) {
		global $wpdb;
		$task = self::get( $id );
		if ( ! $task ) {
			return new WP_Error( 'ndwd_not_found', 'Task not found.', array( 'status' => 404 ) );
		}
		if ( ! NDWD_Permissions::can_archive_task( $task, $user_id ) ) {
			return new WP_Error( 'ndwd_forbidden', 'Only an Admin or someone allowed to archive tasks for this team can archive a task.', array( 'status' => 403 ) );
		}
		$wpdb->update( NDWD_Helpers::table( 'tasks' ), array( 'is_archived' => 1, 'updated_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $id ) );
		NDWD_Activity::log( $user_id, $id, 'task_archived', array( 'title' => $task->title ) );
		NDWD_Packages::sync_task( $id, $user_id );
		return true;
	}

	/**
	 * Hands a task from one of its teams to another team once the first team's part is done.
	 * The handing-off team stays on record (status 'handed_off'), its members leave the
	 * assignee list unless they were picked for the receiving team or stay on through
	 * another team, the receiving team becomes the lead team and the task restarts as
	 * Pending. The previous status, completion time and assignees are kept in task_handoffs.
	 *
	 * @return object|WP_Error
	 */
	public static function handoff( $id, $input, $user_id ) {
		global $wpdb;
		$task = self::get( $id );
		if ( ! $task || $task->is_archived ) {
			return new WP_Error( 'ndwd_not_found', 'Task not found.', array( 'status' => 404 ) );
		}
		$allowed = NDWD_Permissions::handoff_teams( $task, $user_id );
		if ( ! $allowed ) {
			return new WP_Error( 'ndwd_forbidden', 'Only a team manager of this task\'s team or an Admin can hand it off.', array( 'status' => 403 ) );
		}
		$from = isset( $input['from_team_id'] ) ? absint( $input['from_team_id'] ) : 0;
		if ( ! $from ) {
			$from = in_array( (int) $task->team_id, $allowed, true ) ? (int) $task->team_id : $allowed[0];
		}
		if ( ! in_array( $from, $allowed, true ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You can only hand off from a team you manage on this task.', array( 'status' => 403 ) );
		}
		$to = isset( $input['to_team_id'] ) ? absint( $input['to_team_id'] ) : 0;
		if ( ! $to || $to === $from ) {
			return new WP_Error( 'ndwd_invalid', 'Choose the team that receives the task (a different team).', array( 'status' => 400 ) );
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'teams' ) . ' WHERE id = %d AND is_active = 1', $to ) ) ) {
			return new WP_Error( 'ndwd_invalid', 'Unknown or inactive receiving team.', array( 'status' => 400 ) );
		}
		$active_ids = array_values( array_filter( NDWD_Permissions::members_of( array( $to ) ), function ( $u ) {
			return NDWD_Permissions::can( 'ndwd_access', $u );
		} ) );
		$to_members = isset( $input['member_ids'] ) ? NDWD_Helpers::int_list( $input['member_ids'] ) : array();
		foreach ( $to_members as $u ) {
			if ( ! in_array( $u, $active_ids, true ) ) {
				return new WP_Error( 'ndwd_invalid', 'Every selected member must be an active member of the receiving team.', array( 'status' => 400 ) );
			}
		}
		if ( ! $to_members ) {
			$to_members = $active_ids; // Nobody picked: the whole receiving team.
		}
		$note  = isset( $input['note'] ) ? sanitize_textarea_field( (string) $input['note'] ) : '';
		$links = NDWD_Workflow::clean_links( isset( $input['links'] ) ? $input['links'] : array() );
		if ( is_wp_error( $links ) ) {
			return $links;
		}

		$from_members = NDWD_Permissions::members_of( array( $from ) );
		$stay_via     = NDWD_Permissions::members_of( array_values( array_diff( NDWD_Permissions::task_teams( $task ), array( $from ) ) ) );
		$assignees    = array();
		foreach ( $task->assignee_ids as $a ) {
			if ( ! in_array( $a, $from_members, true ) || in_array( $a, $stay_via, true ) || in_array( $a, $to_members, true ) ) {
				$assignees[] = $a;
			}
		}
		$assignees = array_values( array_unique( array_merge( $assignees, $to_members ) ) );

		$now = NDWD_Helpers::now_mysql();
		$wpdb->insert( NDWD_Helpers::table( 'task_handoffs' ), array(
			'task_id'           => (int) $task->id,
			'from_team_id'      => $from,
			'to_team_id'        => $to,
			'handed_by'         => (int) $user_id,
			'from_status'       => $task->status,
			'from_completed_at' => $task->completed_at,
			'from_assignees'    => wp_json_encode( array_map( 'intval', $task->assignee_ids ) ),
			'to_assignees'      => wp_json_encode( $assignees ),
			'note'              => $note,
			'handoff_type'      => 'team',
			'to_status'         => 'pending',
			'links'             => $links ? wp_json_encode( $links ) : null,
			'created_at'        => $now,
		) );
		$tt = NDWD_Helpers::table( 'task_teams' );
		foreach ( array( $from => 'handed_off', $to => 'active' ) as $team => $status ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tt WHERE task_id = %d AND team_id = %d", $task->id, $team ) ); // phpcs:ignore
			$row    = array( 'status' => $status, 'handed_off_at' => 'handed_off' === $status ? $now : null );
			if ( $exists ) {
				$wpdb->update( $tt, $row, array( 'task_id' => (int) $task->id, 'team_id' => $team ) );
			} else {
				$wpdb->insert( $tt, $row + array( 'task_id' => (int) $task->id, 'team_id' => $team, 'added_by' => (int) $user_id, 'added_at' => $now ) );
			}
		}
		$wpdb->update( NDWD_Helpers::table( 'tasks' ), array(
			'team_id'      => $to,
			'status'       => 'pending',
			'completed_at' => null,
			'completed_by' => null,
			'rejected_at'  => null,
			'rejected_by'  => null,
			'claimed_by'   => null,
			'claimed_at'   => null,
			'updated_at'   => $now,
		), array( 'id' => $task->id ) );
		self::sync_assignees( $task->id, $assignees );
		NDWD_Packages::sync_task( $task->id, $user_id );
		NDWD_Workflow::add_links( $task->id, $links, $user_id );

		$details = array(
			'from_team' => self::team_name( $from ),
			'to_team'   => self::team_name( $to ),
			'to'        => implode( ', ', array_map( array( 'NDWD_Helpers', 'user_name' ), $to_members ) ),
		);
		if ( 'pending' !== $task->status ) {
			$details['status'] = array( 'from' => NDWD_Helpers::status_label( $task->status ), 'to' => NDWD_Helpers::status_label( 'pending' ) );
		}
		if ( '' !== $note ) {
			$details['note'] = mb_substr( $note, 0, 140 );
		}
		if ( $links ) {
			$details['links'] = count( $links );
		}
		self::insert_remark( $task->id, $user_id, NDWD_Workflow::remark( 'Team-to-Team Handoff', self::team_name( $from ), self::team_name( $to ), $user_id, $now, $note, $links ) );
		NDWD_Activity::log( $user_id, $task->id, 'handed_off', $details );

		$fresh   = self::get( $task->id );
		$actor   = NDWD_Helpers::user_name( $user_id );
		$removed = array_values( array_diff( $task->assignee_ids, $assignees ) );
		NDWD_Notifications::notify_users( $to_members, $fresh, 'reassigned', $actor . ' handed off "' . $fresh->title . '" from ' . self::team_name( $from ) . ' to ' . self::team_name( $to ), $user_id, null, null, 'team_handoff' );
		if ( $removed ) {
			NDWD_Notifications::notify_users( $removed, $fresh, 'unassigned', $actor . ' handed off "' . $fresh->title . '" to ' . self::team_name( $to ) . '. Your part is recorded as done.', $user_id, null, null, 'team_handoff' );
		}
		return $fresh;
	}

	/** Handoff history of a task, oldest first. */
	public static function handoffs( $task_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'task_handoffs' ) . ' WHERE task_id = %d ORDER BY created_at, id', $task_id ) );
		return array_map( function ( $h ) {
			$names = function ( $json ) {
				$ids = json_decode( (string) $json, true );
				return array_map( array( 'NDWD_Helpers', 'user_name' ), is_array( $ids ) ? $ids : array() );
			};
			return array(
				'id'                => (int) $h->id,
				'from_team_id'      => (int) $h->from_team_id,
				'from_team'         => self::team_name( $h->from_team_id ),
				'to_team_id'        => (int) $h->to_team_id,
				'to_team'           => self::team_name( $h->to_team_id ),
				'handed_by'         => (int) $h->handed_by,
				'handed_by_name'    => NDWD_Helpers::user_name( $h->handed_by ),
				'from_status'       => $h->from_status,
				'from_completed_at' => $h->from_completed_at,
				'from_assignees'    => $names( $h->from_assignees ),
				'to_assignees'      => $names( $h->to_assignees ),
				'note'              => (string) $h->note,
				'type'              => isset( $h->handoff_type ) ? $h->handoff_type : 'team',
				'type_label'        => NDWD_Workflow::type_label( isset( $h->handoff_type ) ? $h->handoff_type : 'team' ),
				'from_user'         => ! empty( $h->from_user_id ) ? NDWD_Helpers::user_name( $h->from_user_id ) : '',
				'to_user'           => ! empty( $h->to_user_id ) ? NDWD_Helpers::user_name( $h->to_user_id ) : '',
				'to_status'         => isset( $h->to_status ) ? (string) $h->to_status : '',
				'links'             => ! empty( $h->links ) ? (array) json_decode( (string) $h->links, true ) : array(),
				'created_at'        => $h->created_at,
			);
		}, $rows );
	}

	private static $team_names = array();

	public static function team_name( $team_id ) {
		global $wpdb;
		$team_id = (int) $team_id;
		if ( ! isset( self::$team_names[ $team_id ] ) ) {
			$name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . NDWD_Helpers::table( 'teams' ) . ' WHERE id = %d', $team_id ) );
			self::$team_names[ $team_id ] = null !== $name ? $name : 'Team #' . $team_id;
		}
		return self::$team_names[ $team_id ];
	}

	private static function set_differs( $a, $b ) {
		$a = array_map( 'intval', $a );
		$b = array_map( 'intval', $b );
		sort( $a );
		sort( $b );
		return $a !== $b;
	}

	public static function sync_assignees( $task_id, $ids ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'task_assignees' );
		$wpdb->delete( $table, array( 'task_id' => $task_id ) );
		$now = NDWD_Helpers::now_mysql();
		if ( ! $ids ) {
			// Back in the team pool: anyone in the team may claim it again.
			$wpdb->update( NDWD_Helpers::table( 'tasks' ), array( 'claimed_by' => null, 'claimed_at' => null ), array( 'id' => $task_id ) );
		}
		foreach ( array_unique( $ids ) as $uid ) {
			$wpdb->insert( $table, array( 'task_id' => $task_id, 'user_id' => (int) $uid, 'assigned_at' => $now ) );
		}
	}

	/**
	 * Makes the task's active teams match $ids. Teams that handed the task off stay on
	 * record unless they are assigned again (then they become active once more).
	 */
	private static function sync_teams( $task_id, $ids, $user_id ) {
		global $wpdb;
		$table    = NDWD_Helpers::table( 'task_teams' );
		$existing = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT team_id, status FROM $table WHERE task_id = %d", $task_id ) ) as $r ) { // phpcs:ignore
			$existing[ (int) $r->team_id ] = $r->status;
		}
		$now = NDWD_Helpers::now_mysql();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $tid ) {
			if ( ! isset( $existing[ $tid ] ) ) {
				$wpdb->insert( $table, array( 'task_id' => (int) $task_id, 'team_id' => $tid, 'status' => 'active', 'added_by' => (int) $user_id, 'added_at' => $now ) );
			} elseif ( 'active' !== $existing[ $tid ] ) {
				$wpdb->update( $table, array( 'status' => 'active', 'handed_off_at' => null ), array( 'task_id' => (int) $task_id, 'team_id' => $tid ) );
			}
		}
		foreach ( $existing as $tid => $status ) {
			if ( 'active' === $status && ! in_array( $tid, $ids, true ) ) {
				$wpdb->delete( $table, array( 'task_id' => (int) $task_id, 'team_id' => $tid ) );
			}
		}
	}

	private static function sync_tags( $task_id, $ids ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'task_tags' );
		$wpdb->delete( $table, array( 'task_id' => $task_id ) );
		foreach ( array_unique( $ids ) as $tid ) {
			$wpdb->insert( $table, array( 'task_id' => $task_id, 'tag_id' => (int) $tid ) );
		}
	}

	/** Adds, edits and removes links to match $links. Returns true when anything changed. */
	private static function sync_links( $task_id, $links, $user_id ) {
		global $wpdb;
		$table    = NDWD_Helpers::table( 'task_links' );
		$existing = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE task_id = %d", $task_id ) ) as $l ) {
			$existing[ (int) $l->id ] = $l;
		}
		$changed = false;
		$keep    = array();
		foreach ( $links as $l ) {
			if ( $l['id'] && isset( $existing[ $l['id'] ] ) ) {
				$keep[] = $l['id'];
				$e      = $existing[ $l['id'] ];
				if ( $e->url !== $l['url'] || $e->label !== $l['label'] ) {
					$wpdb->update( $table, array( 'url' => $l['url'], 'label' => $l['label'] ), array( 'id' => $l['id'] ) );
					$changed = true;
				}
				continue;
			}
			$wpdb->insert( $table, array(
				'task_id'    => $task_id,
				'url'        => $l['url'],
				'label'      => $l['label'],
				'created_by' => (int) $user_id,
				'created_at' => NDWD_Helpers::now_mysql(),
			) );
			$changed = true;
		}
		foreach ( array_diff( array_keys( $existing ), $keep ) as $remove ) {
			$wpdb->delete( $table, array( 'id' => $remove ) );
			$changed = true;
		}
		return $changed;
	}

	/* ------------------------------------------------------------------ *
	 * Queries
	 * ------------------------------------------------------------------ */

	/**
	 * SQL condition for "visible on worksheet date D":
	 * scheduled on/before D, created on/before D (for historic views),
	 * and not completed or rejected before D. Completed and Rejected tasks therefore
	 * appear only on the day they were closed and never carry forward.
	 */
	public static function worksheet_condition( $date, &$vals ) {
		$vals[] = $date;
		$vals[] = $date;
		$vals[] = $date;
		$vals[] = $date;
		return '(t.scheduled_date <= %s AND DATE(t.created_at) <= %s AND (t.completed_at IS NULL OR DATE(t.completed_at) >= %s) AND (t.rejected_at IS NULL OR DATE(t.rejected_at) >= %s))';
	}

	public static function overdue_condition( &$vals ) {
		$now    = NDWD_Helpers::now();
		$vals[] = $now->format( 'Y-m-d' );
		$vals[] = $now->format( 'Y-m-d' );
		$vals[] = $now->format( 'H:i:s' );
		return '(' . NDWD_Helpers::open_sql() . " AND t.due_date IS NOT NULL AND (t.due_date < %s OR (t.due_date = %s AND t.due_time IS NOT NULL AND t.due_time < %s)))";
	}

	/** Visibility restriction for a user. Returns '' when the user can see everything. */
	public static function visibility_condition( $user_id, &$vals ) {
		$teams = NDWD_Permissions::teams_with( 'view_team_tasks', $user_id );
		if ( null === $teams ) {
			return '';
		}
		$ta     = NDWD_Helpers::table( 'task_assignees' );
		$parts  = array( 't.created_by = %d', "EXISTS (SELECT 1 FROM $ta va WHERE va.task_id = t.id AND va.user_id = %d)" );
		$vals[] = $user_id;
		$vals[] = $user_id;
		$mine   = NDWD_Permissions::user_teams( $user_id );
		if ( $mine ) {
			// Unassigned work of the user's own teams (the shared pool).
			$parts[] = '(' . self::in_teams_condition( $mine, $vals ) . " AND NOT EXISTS (SELECT 1 FROM $ta pa WHERE pa.task_id = t.id))";
		}
		if ( $teams ) {
			// Any team on the task, including teams that handed it on.
			$parts[] = 't.team_id IN (' . NDWD_Helpers::in_placeholders( $teams ) . ')';
			$parts[] = 'EXISTS (SELECT 1 FROM ' . NDWD_Helpers::table( 'task_teams' ) . ' vt WHERE vt.task_id = t.id AND vt.team_id IN (' . NDWD_Helpers::in_placeholders( $teams ) . '))';
			$vals    = array_merge( $vals, $teams, $teams );
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/** SQL: the task is currently assigned to one of $team_ids (lead team or another active team). */
	private static function in_teams_condition( $team_ids, &$vals ) {
		$in   = NDWD_Helpers::in_placeholders( $team_ids );
		$vals = array_merge( $vals, $team_ids, $team_ids );
		return "(t.team_id IN ($in) OR EXISTS (SELECT 1 FROM " . NDWD_Helpers::table( 'task_teams' ) . " it WHERE it.task_id = t.id AND it.status = 'active' AND it.team_id IN ($in)))";
	}

	/** "Mine": assigned to me, or a team task (no individual assignee) of one of my teams. */
	public static function mine_condition( $user_id, &$vals ) {
		$ta     = NDWD_Helpers::table( 'task_assignees' );
		$vals[] = $user_id;
		$sql    = "(EXISTS (SELECT 1 FROM $ta ma WHERE ma.task_id = t.id AND ma.user_id = %d)";
		$teams  = NDWD_Permissions::user_teams( $user_id );
		if ( $teams ) {
			$sql .= ' OR (' . self::in_teams_condition( $teams, $vals ) . " AND NOT EXISTS (SELECT 1 FROM $ta na WHERE na.task_id = t.id))";
		}
		return $sql . ')';
	}

	/**
	 * Builds WHERE clauses from filters. Shared by the task list, dashboards and calendar.
	 *
	 * @return array [ sql_where, values ]
	 */
	public static function build_where( $f, $user_id ) {
		$ta    = NDWD_Helpers::table( 'task_assignees' );
		$tt    = NDWD_Helpers::table( 'task_tags' );
		$where = array( 't.is_archived = 0' );
		$vals  = array();

		// '_system' is only ever set internally (scheduled report generation), never from request input.
		$vis = empty( $f['_system'] ) ? self::visibility_condition( $user_id, $vals ) : '';
		if ( $vis ) {
			$where[] = $vis;
		}
		if ( ! empty( $f['scope'] ) && 'mine' === $f['scope'] ) {
			$where[] = self::mine_condition( $user_id, $vals );
		}

		$today = NDWD_Helpers::today();
		$view  = isset( $f['view'] ) ? $f['view'] : 'all';
		if ( isset( NDWD_Helpers::LEGACY_STATUSES[ $view ] ) ) {
			$view = 'rejected'; // Saved "Blocked" filter links now open the Rejected list.
		}
		switch ( $view ) {
			case 'today':
			case 'worksheet':
				$date    = ! empty( $f['date'] ) && NDWD_Helpers::valid_date( $f['date'] ) ? $f['date'] : $today;
				$where[] = self::worksheet_condition( $date, $vals );
				break;
			case 'due_today':
				$where[] = NDWD_Helpers::open_sql() . " AND t.due_date = %s";
				$vals[]  = $today;
				break;
			case 'upcoming':
				$where[] = NDWD_Helpers::open_sql() . " AND t.scheduled_date > %s";
				$vals[]  = $today;
				break;
			case 'overdue':
				$where[] = self::overdue_condition( $vals );
				break;
			case 'carried':
				$where[] = NDWD_Helpers::open_sql() . " AND t.scheduled_date < %s";
				$vals[]  = $today;
				break;
			case 'completed':
			case 'pending':
			case 'in_process':
			case 'rejected':
				$where[] = 't.status = %s';
				$vals[]  = $view;
				break;
			case 'rejected_today':
				$where[] = "t.status = 'rejected' AND DATE(t.rejected_at) = %s";
				$vals[]  = $today;
				break;
			case 'urgent':
			case 'high':
				$where[] = NDWD_Helpers::open_sql() . " AND t.priority = %s";
				$vals[]  = $view;
				break;
			case 'open':
				$where[] = NDWD_Helpers::open_sql();
				break;
			case 'followups':
				$where[] = 't.parent_id IS NOT NULL';
				break;
		}

		if ( ! empty( $f['status'] ) ) {
			$st = array_map( function ( $s ) {
				return isset( NDWD_Helpers::LEGACY_STATUSES[ $s ] ) ? 'rejected' : $s;
			}, (array) $f['status'] );
			$st = array_values( array_unique( array_intersect( $st, array_keys( NDWD_Helpers::STATUSES ) ) ) );
			if ( $st ) {
				$where[] = 't.status IN (' . implode( ',', array_fill( 0, count( $st ), '%s' ) ) . ')';
				$vals    = array_merge( $vals, $st );
			}
		}
		if ( ! empty( $f['priority'] ) ) {
			$pr = array_values( array_intersect( (array) $f['priority'], NDWD_Helpers::PRIORITY_KEYS ) );
			if ( $pr ) {
				$where[] = 't.priority IN (' . implode( ',', array_fill( 0, count( $pr ), '%s' ) ) . ')';
				$vals    = array_merge( $vals, $pr );
			}
		}
		if ( ! empty( $f['work_type'] ) ) {
			$where[] = 't.work_type = %s';
			$vals[]  = sanitize_key( $f['work_type'] );
		}
		if ( ! empty( $f['team_id'] ) ) {
			$where[] = self::in_teams_condition( array( absint( $f['team_id'] ) ), $vals );
		}
		// Strict team scope: tasks of these teams only, never through a member's other teams.
		if ( ! empty( $f['team_only'] ) ) {
			$where[] = self::in_teams_condition( array_map( 'absint', (array) $f['team_only'] ), $vals );
		}
		if ( ! empty( $f['pool'] ) ) {
			$where[] = NDWD_Helpers::open_sql() . " AND t.team_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM $ta pl WHERE pl.task_id = t.id)";
		}
		if ( ! empty( $f['team_ids'] ) && is_array( $f['team_ids'] ) ) {
			$where[] = '(' . self::in_teams_condition( $f['team_ids'], $vals ) . " OR EXISTS (SELECT 1 FROM $ta xa JOIN " . NDWD_Helpers::table( 'team_members' ) . ' xm ON xm.user_id = xa.user_id WHERE xa.task_id = t.id AND xm.team_id IN (' . NDWD_Helpers::in_placeholders( $f['team_ids'] ) . ')))';
			$vals    = array_merge( $vals, $f['team_ids'] );
		}
		if ( ! empty( $f['client_id'] ) ) {
			$where[] = 't.client_id = %d';
			$vals[]  = absint( $f['client_id'] );
		}
		if ( ! empty( $f['project_id'] ) ) {
			$where[] = 't.project_id = %d';
			$vals[]  = absint( $f['project_id'] );
		}
		if ( ! empty( $f['member_id'] ) ) {
			$where[] = "EXISTS (SELECT 1 FROM $ta fa WHERE fa.task_id = t.id AND fa.user_id = %d)";
			$vals[]  = absint( $f['member_id'] );
		}
		if ( ! empty( $f['tag_id'] ) ) {
			$where[] = "EXISTS (SELECT 1 FROM $tt ft WHERE ft.task_id = t.id AND ft.tag_id = %d)";
			$vals[]  = absint( $f['tag_id'] );
		}
		if ( ! empty( $f['from'] ) && NDWD_Helpers::valid_date( $f['from'] ) ) {
			$where[] = 't.scheduled_date >= %s';
			$vals[]  = $f['from'];
		}
		if ( ! empty( $f['to'] ) && NDWD_Helpers::valid_date( $f['to'] ) ) {
			$where[] = 't.scheduled_date <= %s';
			$vals[]  = $f['to'];
		}
		if ( ! empty( $f['in_scope'] ) && is_array( $f['in_scope'] ) ) {
			// On the worksheet at some point during [from, to].
			$where[] = '(t.scheduled_date <= %s AND DATE(t.created_at) <= %s AND (t.completed_at IS NULL OR DATE(t.completed_at) >= %s) AND (t.rejected_at IS NULL OR DATE(t.rejected_at) >= %s))';
			$vals[]  = $f['in_scope'][1];
			$vals[]  = $f['in_scope'][1];
			$vals[]  = $f['in_scope'][0];
			$vals[]  = $f['in_scope'][0];
		}
		if ( ! empty( $f['user_ids'] ) && is_array( $f['user_ids'] ) ) {
			$where[] = "EXISTS (SELECT 1 FROM $ta ua WHERE ua.task_id = t.id AND ua.user_id IN (" . NDWD_Helpers::in_placeholders( $f['user_ids'] ) . '))';
			$vals    = array_merge( $vals, $f['user_ids'] );
		}
		if ( ! empty( $f['q'] ) ) {
			global $wpdb;
			$like    = '%' . $wpdb->esc_like( sanitize_text_field( $f['q'] ) ) . '%';
			$where[] = '(t.title LIKE %s OR t.description LIKE %s OR t.id = %d)';
			$vals[]  = $like;
			$vals[]  = $like;
			$vals[]  = absint( ltrim( $f['q'], '#' ) );
		}
		return array( implode( ' AND ', $where ), $vals );
	}

	public static function query( $f, $user_id ) {
		global $wpdb;
		list( $where, $vals ) = self::build_where( $f, $user_id );
		$table = NDWD_Helpers::table( 'tasks' );
		$limit = isset( $f['per_page'] ) ? max( 1, min( 500, (int) $f['per_page'] ) ) : 200;
		$page  = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		$off   = ( $page - 1 ) * $limit;

		$order = "(t.status IN ('completed','rejected')) ASC, FIELD(t.priority,'urgent','high','medium','low'), (t.due_date IS NULL), t.due_date, (t.due_time IS NULL), t.due_time, t.scheduled_date, t.id";
		if ( isset( $f['view'] ) && 'completed' === $f['view'] ) {
			$order = 't.completed_at DESC';
		}
		$count_sql = "SELECT COUNT(*) FROM $table t WHERE $where";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore
		$sql       = "SELECT t.* FROM $table t WHERE $where ORDER BY $order LIMIT $limit OFFSET $off";
		$rows      = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		$rows      = self::hydrate( $rows );
		return array(
			'total' => $total,
			'page'  => $page,
			'items' => array_map( array( __CLASS__, 'to_array' ), $rows ),
		);
	}

	/** Raw hydrated rows (no pagination) for statistics and exports. */
	public static function rows( $f, $user_id ) {
		global $wpdb;
		list( $where, $vals ) = self::build_where( $f, $user_id );
		$sql  = 'SELECT t.* FROM ' . NDWD_Helpers::table( 'tasks' ) . " t WHERE $where ORDER BY t.scheduled_date, t.id";
		$rows = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		return self::hydrate( $rows );
	}
}
