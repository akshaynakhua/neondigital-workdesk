<?php
/**
 * What a client can see of each task, two-way task comments, and the lightweight "pulse" that lets
 * both apps refresh without a manual reload.
 *
 * Default for every task: not visible. Each detail has its own switch: task, comments, assignee,
 * deadline, calendar, handoff. Internal remarks live in other tables and are never read here;
 * comments marked internal are filtered out of everything the client side receives.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_TaskLink {

	const FLAGS = array( 'visible', 'show_description', 'show_assignee', 'show_deadline', 'show_status', 'show_comments', 'show_calendar', 'show_handoff' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	private static function internal( $path, $methods, $cb ) {
		register_rest_route( NDWD_REST::NS, '/workspace' . $path, array(
			'methods'             => $methods,
			'callback'            => array( __CLASS__, $cb ),
			'permission_callback' => function () {
				return is_user_logged_in() && NDWD_Permissions::can( 'ndwd_access' );
			},
		) );
	}

	public static function routes() {
		self::internal( '/tasks/(?P<id>\d+)/client', 'GET', 'agency_get' );
		self::internal( '/tasks/(?P<id>\d+)/client', 'PUT,PATCH', 'agency_save' );
		self::internal( '/tasks/(?P<id>\d+)/client/comments', 'POST', 'agency_comment' );
		self::internal( '/tasks/(?P<id>\d+)/client/pulse', 'GET', 'agency_pulse' );
		self::internal( '/tasks/(?P<id>\d+)/chat', 'POST', 'chat_activate' );
		register_rest_route( NDWD_REST::NS, '/workspace/settings/test', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'test_connection' ), 'permission_callback' => function () {
			return is_user_logged_in() && NDWD_Permissions::can( 'ndwd_manage_workspace' );
		} ) );
		register_rest_route( NDWD_REST::NS, '/workspace/settings/health', array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'health' ), 'permission_callback' => function () {
			return is_user_logged_in() && NDWD_Permissions::can( 'ndwd_manage_workspace' );
		} ) );
	}

	/* ---------------------------- Visibility ---------------------------- */

	public static function flags( $task_id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'task_client' ) . ' WHERE task_id = %d', $task_id ) ); // phpcs:ignore
		$o = array( 'exists' => (bool) $r );
		foreach ( self::FLAGS as $f ) {
			$o[ $f ] = $r ? (bool) $r->$f : false;
		}
		$o['client_description'] = $r ? (string) $r->client_description : '';
		$o['chat_active_at']     = $r ? $r->chat_active_at : null;
		return $o;
	}

	public static function chat_active( $task_id ) {
		return (bool) self::flags( $task_id )['chat_active_at'];
	}

	private static function from_request( $task_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE task_id = %d', $task_id ) ); // phpcs:ignore
	}

	/** A request's chat waits for a team member to start it. Other shared tasks follow their comment switch. */
	public static function chat_open( $task ) {
		$f = self::flags( $task->id );
		if ( ! $f['visible'] || ! $f['show_comments'] ) {
			return false;
		}
		return ! self::from_request( $task->id ) || (bool) $f['chat_active_at'];
	}

	/** Chat Now. Repeating it changes nothing and tells the client once. */
	public static function chat_activate( WP_REST_Request $r ) {
		global $wpdb;
		$t = self::task( (int) $r['id'] );
		if ( ! $t || ! NDWD_Permissions::can_view_task( $t ) ) {
			return new WP_Error( 'ndwd_not_found', 'Task not found.', array( 'status' => 404 ) );
		}
		$conn = NDWD_WS::connection_for_task( $t );
		if ( ! $conn || ! $conn->linked_at ) {
			return new WP_Error( 'ndwd_invalid', 'This client is not connected to Workspace.', array( 'status' => 409 ) );
		}
		$uid = get_current_user_id();
		$now = NDWD_Helpers::now_mysql();
		if ( ! self::flags( $t->id )['exists'] ) {
			$wpdb->insert( NDWD_WS::t( 'task_client' ), array( 'task_id' => (int) $t->id, 'visible' => 1, 'show_status' => 1, 'show_comments' => 1, 'updated_by' => $uid, 'updated_at' => $now ) );
		}
		$won = $wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'task_client' ) . ' SET chat_active_at = %s, chat_active_by = %d, visible = 1, show_comments = 1, updated_at = %s WHERE task_id = %d AND chat_active_at IS NULL', $now, $uid, $now, $t->id ) ); // phpcs:ignore
		if ( $won ) {
			NDWD_Activity::log( $uid, $t->id, 'chat_activated', array() );
			NDWD_WS_Webhooks::emit( 'task.chat_opened', $conn->id, array( 'task_id' => (int) $t->id, 'request_id' => self::request_id_of( $t->id ) ) );
			NDWD_WS::audit( 'chat_activated', array( 'connection_id' => $conn->id, 'entity' => 'task', 'entity_id' => $t->id ), $uid );
		}
		return self::agency_get( $r );
	}

	/** Who hears about a client's message: the people on the task and whoever opened the chat. */
	public static function on_client_message( $task, $comment_id, $in ) {
		global $wpdb;
		$task   = NDWD_Tasks::get( (int) $task->id ); // With its assignees and teams.
		$opened = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT chat_active_by FROM ' . NDWD_WS::t( 'task_client' ) . ' WHERE task_id = %d', $task->id ) ); // phpcs:ignore
		$who    = array_merge( NDWD_Tasks::notify_targets( $task ), $opened ? array( $opened ) : array() );
		$who    = array_values( array_filter( array_unique( array_map( 'intval', $who ) ), function ( $u ) use ( $task ) {
			return NDWD_Permissions::can_view_task( $task, $u );
		} ) );
		$by     = sanitize_text_field( (string) ( $in['author_name'] ?? 'The client' ) );
		NDWD_Notifications::notify_users( $who, $task, 'client_message', ( '' !== $by ? $by : 'The client' ) . ' sent a message on: ' . $task->title, 0, 'cmsg:' . $comment_id );
		NDWD_Activity::log( 0, $task->id, 'client_message', array( 'from' => $by ), false );
	}

	private static function task( $id ) {
		return NDWD_Tasks::get( $id ); // With assignees and teams, as the permission checks expect.
	}

	public static function agency_save( WP_REST_Request $r ) {
		$t = self::task( (int) $r['id'] );
		if ( ! $t || ! NDWD_Permissions::can_edit_task( $t ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You cannot change this task.', array( 'status' => 403 ) );
		}
		$b = json_decode( (string) $r->get_body(), true );
		self::apply( $t, is_array( $b ) ? $b : array(), get_current_user_id() );
		return self::agency_get( $r );
	}

	/**
	 * Saves the per-task switches, from the task form or the task drawer. A task with no row is
	 * internal. Turning the task off leaves its other switches stored, and exposes nothing.
	 */
	public static function apply( $t, $in, $user_id ) {
		global $wpdb;
		$old = self::flags( $t->id );
		if ( ! $old['exists'] && empty( $in['visible'] ) ) {
			return false; // Nothing to record: internal is the default.
		}
		$row = array( 'updated_by' => (int) $user_id, 'updated_at' => NDWD_Helpers::now_mysql() );
		foreach ( self::FLAGS as $f ) {
			$row[ $f ] = array_key_exists( $f, $in ) ? ( empty( $in[ $f ] ) ? 0 : 1 ) : ( $old[ $f ] ? 1 : 0 );
		}
		$row['client_description'] = array_key_exists( 'client_description', $in ) ? sanitize_textarea_field( (string) $in['client_description'] ) : $old['client_description'];
		$changed = array();
		foreach ( self::FLAGS as $f ) {
			if ( (bool) $row[ $f ] !== $old[ $f ] ) {
				$changed[ $f ] = (bool) $row[ $f ];
			}
		}
		if ( $old['exists'] ) {
			$wpdb->update( NDWD_WS::t( 'task_client' ), $row, array( 'task_id' => $t->id ) );
		} else {
			$wpdb->insert( NDWD_WS::t( 'task_client' ), array_merge( $row, array( 'task_id' => (int) $t->id ) ) );
		}
		if ( $changed ) {
			$conn = NDWD_WS::connection_for_task( $t );
			NDWD_WS::audit( 'task_visibility_changed', array( 'connection_id' => $conn ? $conn->id : null, 'entity' => 'task', 'entity_id' => $t->id, 'details' => $changed ), $user_id );
			if ( $conn && isset( $changed['visible'] ) ) {
				NDWD_WS_Webhooks::emit( 'task.visibility_changed', $conn->id, array( 'task_id' => (int) $t->id ) );
			}
		}
		return true;
	}

	/* ---------------------------- Comments ---------------------------- */

	public static function agency_get( WP_REST_Request $r ) {
		global $wpdb;
		$t = self::task( (int) $r['id'] );
		if ( ! $t || ! NDWD_Permissions::can_view_task( $t ) ) {
			return new WP_Error( 'ndwd_not_found', 'Task not found.', array( 'status' => 404 ) );
		}
		$conn = NDWD_WS::connection_for_task( $t );
		$c    = NDWD_WS::t( 'comments' );
		$wpdb->query( $wpdb->prepare( "UPDATE $c SET agency_read_at = %s WHERE ( ( entity = 'task' AND entity_id = %d ) OR ( entity = 'request' AND entity_id = %d ) ) AND author_type = 'client' AND agency_read_at IS NULL", NDWD_Helpers::now_mysql(), $t->id, self::request_id_of( $t->id ) ? self::request_id_of( $t->id ) : -1 ) ); // phpcs:ignore
		return rest_ensure_response( array(
			'flags'     => self::flags( $t->id ),
			'connected' => (bool) $conn,
			'workspace_name' => $conn ? (string) $conn->workspace_name : '',
			'chat_required' => self::from_request( $t->id ),
			'chat_open' => self::chat_open( $t ),
			'request'   => NDWD_WS_Projects::request_for_task( $t ),
			'deliverables' => NDWD_WS_Projects::deliverables_for_task( $t ),
			'can_edit'  => NDWD_Permissions::can_edit_task( $t ),
			'comments'  => self::comment_rows( $t->id, true ),
			'attachment_rules' => NDWD_WS_Attachments::rules(),
			'project'   => $t->project_id ? NDWD_WS_TaskLink::project_brief( (int) $t->project_id ) : null,
			'stamp'     => self::thread_stamp( $t->id ),
		) );
	}

	/** The work request a task was made from, if any. */
	public static function request_id_of( $task_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE task_id = %d', $task_id ) ); // phpcs:ignore
	}

	/**
	 * The one conversation of a task, as both apps show it. A task made from a work request also
	 * carries anything said on the request itself, in time order, so WorkDesk and Workspace list
	 * exactly the same client-visible messages.
	 */
	public static function comment_rows( $task_id, $include_internal ) {
		global $wpdb;
		$req  = self::request_id_of( $task_id );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, author_type, author_name, body, is_internal, created_at, client_read_at, agency_read_at FROM ' . NDWD_WS::t( 'comments' ) . " WHERE ( ( entity = 'task' AND entity_id = %d ) OR ( entity = 'request' AND entity_id = %d ) )" . ( $include_internal ? '' : ' AND is_internal = 0' ) . ' ORDER BY created_at ASC, id ASC', $task_id, $req ? $req : -1 ) ); // phpcs:ignore
		return NDWD_WS_Attachments::on_comments( array_map( function ( $x ) use ( $include_internal ) {
			$o = array( 'id' => (int) $x->id, 'author_type' => $x->author_type, 'author_name' => $x->author_name, 'body' => $x->body, 'created_at' => $x->created_at, 'read' => (bool) ( 'client' === $x->author_type ? $x->agency_read_at : $x->client_read_at ) );
			if ( $include_internal ) {
				$o['is_internal'] = (bool) $x->is_internal;
			}
			return $o;
		}, $rows ), $include_internal );
	}

	private static function thread_stamp( $task_id ) {
		global $wpdb;
		// The chat opening moves the number as well, so the other side's panel redraws without a reload.
		$req = self::request_id_of( $task_id );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM ' . NDWD_WS::t( 'comments' ) . " WHERE ( entity = 'task' AND entity_id = %d ) OR ( entity = 'request' AND entity_id = %d )", $task_id, $req ? $req : -1 ) ) + ( self::chat_active( $task_id ) ? 1000000000 : 0 ); // phpcs:ignore
	}

	public static function agency_pulse( WP_REST_Request $r ) {
		return rest_ensure_response( array( 'stamp' => self::thread_stamp( (int) $r['id'] ) ) );
	}

	public static function agency_comment( WP_REST_Request $r ) {
		$t = self::task( (int) $r['id'] );
		if ( ! $t || ! NDWD_Permissions::can_view_task( $t ) ) {
			return new WP_Error( 'ndwd_not_found', 'Task not found.', array( 'status' => 404 ) );
		}
		$conn = NDWD_WS::connection_for_task( $t );
		if ( ! $conn ) {
			return new WP_Error( 'ndwd_invalid', 'This client is not connected to Workspace.', array( 'status' => 409 ) );
		}
		$b = json_decode( (string) $r->get_body(), true );
		$b = is_array( $b ) ? $b : array();
		// Anything not visibly public stays internal: a task or comment switch off forces it.
		$f = self::flags( $t->id );
		$b['is_internal'] = ! empty( $b['is_internal'] ) || ! $f['visible'] || ! $f['show_comments'];
		$uid = get_current_user_id();
		$cid = NDWD_WS_Requests::add_comment( $conn, 'task', $t->id, $b, 'agency', $uid );
		if ( is_wp_error( $cid ) ) {
			return $cid;
		}
		if ( empty( $b['is_internal'] ) ) {
			NDWD_WS_Webhooks::emit( 'task.commented', $conn->id, array( 'task_id' => (int) $t->id, 'request_id' => self::request_id_of( $t->id ) ) );
			NDWD_Activity::log( $uid, $t->id, 'chat_message', array( 'to' => 'client' ), false );
		} else {
			NDWD_Notifications::notify_users( NDWD_Tasks::notify_targets( $t ), $t, 'remark', NDWD_Helpers::user_name( $uid ) . ' added an internal remark on: ' . $t->title, $uid, 'crem:' . (int) $cid );
		}
		return self::agency_get( $r );
	}

	/* ---------------------------- Server-to-server ---------------------------- */

	/** Public fields of one visible task, honouring each detail switch. */
	public static function client_task( $conn, $task ) {
		global $wpdb;
		$f   = self::flags( $task->id );
		$out = array( 'id' => (int) $task->id, 'origin' => 'NeonDigital WorkDesk', 'chat_open' => self::chat_open( $task ), 'title' => $task->title, 'status' => null, 'status_label' => null, 'description' => null, 'priority' => $task->priority, 'project_id' => $task->project_id ? (int) $task->project_id : null, 'show_comments' => $f['show_comments'], 'show_calendar' => $f['show_calendar'], 'due_date' => null, 'scheduled_date' => null, 'calendar_date' => null, 'completed_at' => null );
		if ( $f['show_status'] ) {
			$out['status']       = $task->status;
			$out['status_label'] = NDWD_Helpers::status_label( $task->status );
			$out['completed_at'] = $task->completed_at ?? null;
		}
		// Only the wording written for the client. The internal description is never sent.
		if ( $f['show_description'] && '' !== $f['client_description'] ) {
			$out['description'] = $f['client_description'];
		}
		if ( $f['show_deadline'] ) {
			$out['due_date']       = $task->due_date;
			$out['scheduled_date'] = $task->scheduled_date;
		}
		if ( $f['show_calendar'] ) {
			$out['calendar_date'] = $task->due_date ? $task->due_date : $task->scheduled_date;
		}
		if ( $f['show_assignee'] ) {
			$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . NDWD_Helpers::table( 'task_assignees' ) . ' WHERE task_id = %d', $task->id ) ); // phpcs:ignore
			$out['assignees'] = array_map( function ( $u ) {
				return NDWD_Helpers::user_name( (int) $u );
			}, $ids );
			$out['team'] = ! empty( $task->team_id ) ? NDWD_Tasks::team_name( (int) $task->team_id ) : '';
		}
		$out['deliverables'] = NDWD_WS_Projects::client_deliverables_for_task( $conn, (int) $task->id );
		$out['timeline'] = $f['show_handoff'] ? self::timeline( $task->id ) : array();
		return $out;
	}

	/** Assignment events only: who it moved to and when. Handoff notes are internal and left out. */
	private static function timeline( $task_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT to_team_id, to_user_id, handoff_type, created_at FROM ' . NDWD_Helpers::table( 'task_handoffs' ) . ' WHERE task_id = %d ORDER BY id ASC', $task_id ) ); // phpcs:ignore
		return array_map( function ( $h ) {
			return array( 'type' => $h->handoff_type, 'to' => $h->to_user_id ? NDWD_Helpers::user_name( (int) $h->to_user_id ) : 'Team', 'at' => $h->created_at );
		}, $rows );
	}

	/** Main project › sub-project names for the conversation header. */
	public static function project_brief( $project_id ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, parent_id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $project_id ) ); // phpcs:ignore
		if ( ! $p ) {
			return null;
		}
		$par = $p->parent_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, name FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $p->parent_id ) ) : null; // phpcs:ignore
		return array( 'id' => (int) $p->id, 'name' => $p->name, 'parent' => $par ? array( 'id' => (int) $par->id, 'name' => $par->name ) : null );
	}

	public static function client_comments( $conn, $task ) {
		global $wpdb;
		$f = self::flags( $task->id );
		if ( ! $f['show_comments'] ) {
			return array();
		}
		$c = NDWD_WS::t( 'comments' );
		$req = self::request_id_of( $task->id );
		$wpdb->query( $wpdb->prepare( "UPDATE $c SET client_read_at = %s WHERE ( ( entity = 'task' AND entity_id = %d ) OR ( entity = 'request' AND entity_id = %d ) ) AND connection_id = %d AND author_type = 'agency' AND is_internal = 0 AND client_read_at IS NULL", NDWD_Helpers::now_mysql(), $task->id, $req ? $req : -1, $conn->id ) ); // phpcs:ignore
		return self::comment_rows( $task->id, false );
	}

	/**
	 * A cheap "has anything changed?" number for a client: their public comments, tasks' changes and
	 * webhook events. The client app polls this and only reloads when it moves.
	 */
	public static function pulse( $conn ) {
		global $wpdb;
		$c = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM ' . NDWD_WS::t( 'comments' ) . ' WHERE connection_id = %d AND is_internal = 0', $conn->id ) ); // phpcs:ignore
		$h = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id),0) FROM ' . NDWD_WS::t( 'webhooks' ) . ' WHERE connection_id = %d', $conn->id ) ); // phpcs:ignore
		$t = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(updated_at),\'\') FROM ' . NDWD_Helpers::table( 'tasks' ) . ' WHERE client_id = %d', $conn->client_id ) ); // phpcs:ignore
		$a = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(h.id),0) FROM ' . NDWD_Helpers::table( 'task_handoffs' ) . ' h JOIN ' . NDWD_Helpers::table( 'tasks' ) . ' t ON t.id = h.task_id WHERE t.client_id = %d', $conn->client_id ) ); // phpcs:ignore
		return array( 'stamp' => $c . '.' . $h . '.' . $a . '.' . strtotime( $t . ' UTC' ), 'poll_seconds' => 8 );
	}

	/* ---------------------------- Connection health ---------------------------- */

	public static function health() {
		global $wpdb;
		$api = NDWD_WS::t( 'api_log' );
		$hk  = NDWD_WS::t( 'webhooks' );
		return rest_ensure_response( array(
			'api_version'    => 'v1',
			'last_sync'      => $wpdb->get_var( "SELECT MAX(created_at) FROM $api WHERE status < 400" ), // phpcs:ignore
			'last_webhook'   => $wpdb->get_var( "SELECT MAX(created_at) FROM $hk WHERE status = 'delivered'" ), // phpcs:ignore
			'failed_requests' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $api WHERE status >= 400 AND created_at > %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ), // phpcs:ignore
			'pending_webhooks' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $hk WHERE status <> 'delivered'" ), // phpcs:ignore
			'connections'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'connections' ) ), // phpcs:ignore
		) );
	}

	/**
	 * Checks the two things a client's link depends on, and says plainly which one failed. Only this
	 * admin screen sees the detail. Redirects are not followed, because a redirect turns the signed
	 * POST into a GET and is what produces "No route was found" on the other side.
	 */
	public static function test_connection() {
		$url = NDWD_WS::workspace_url();
		if ( '' === $url ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => 'Enter the Workspace address first.' ) );
		}
		$res = wp_remote_get( $url . '/wp-json/neon-workspace/v1/plans', array( 'timeout' => 10, 'redirection' => 0 ) );
		if ( is_wp_error( $res ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => 'Could not reach that address.' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 300 && $code < 400 ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => 'That address redirects to ' . esc_url_raw( (string) wp_remote_retrieve_header( $res, 'location' ) ) . '. Save the final address, because a redirect breaks signed requests.' ) );
		}
		if ( 200 !== $code ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => 'That address answered with status ' . $code . ', so it does not look like Neon Workspace.' ) );
		}
		$hook = wp_remote_request( $url . '/wp-json/neon-workspace/v1/webhooks/workdesk', array( 'method' => 'OPTIONS', 'timeout' => 10, 'redirection' => 0 ) );
		if ( is_wp_error( $hook ) || 200 !== (int) wp_remote_retrieve_response_code( $hook ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => 'Neon Workspace answered, but its webhook route is missing. Check that Neon Workspace Core is active and up to date.' ) );
		}
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Neon Workspace answered at that address and its webhook route is available.' ) );
	}
}
