<?php
/**
 * REST API (namespace ndwd/v1). Cookie authentication + X-WP-Nonce, capability
 * checks in every permission callback, and object-level checks in handlers.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_REST {

	const NS = 'ndwd/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ------------------------------------------------------------------ *
	 * Permission callbacks
	 * ------------------------------------------------------------------ */

	public static function member() {
		return is_user_logged_in() && NDWD_Permissions::can( 'ndwd_access' );
	}

	private static function cap( $cap ) {
		return function () use ( $cap ) {
			return is_user_logged_in() && NDWD_Permissions::can( $cap );
		};
	}

	/** Permission callback for deletes: the management capability plus "Delete records". */
	private static function delete_cap( $cap ) {
		return function () use ( $cap ) {
			return is_user_logged_in() && NDWD_Permissions::can( $cap ) && NDWD_Permissions::can( 'ndwd_delete_records' );
		};
	}

	/** Permission callback: the user has $perm in at least one team (the handler checks which). */
	private static function team_perm( $perm ) {
		return function () use ( $perm ) {
			return self::member() && NDWD_Permissions::has_team_perm( $perm );
		};
	}

	private static function route( $path, $methods, $cb, $perm = null ) {
		register_rest_route( self::NS, $path, array(
			'methods'             => $methods,
			'callback'            => $cb,
			'permission_callback' => $perm ? $perm : array( __CLASS__, 'member' ),
		) );
	}

	public static function routes() {
		$c = __CLASS__;
		self::route( '/bootstrap', 'GET', array( $c, 'bootstrap' ) );
		self::route( '/me/preferences', 'POST', array( $c, 'preferences' ) );

		self::route( '/tasks', 'GET', array( $c, 'list_tasks' ) );
		self::route( '/tasks', 'POST', array( $c, 'create_task' ) );
		self::route( '/tasks/(?P<id>\d+)', 'GET', array( $c, 'get_task' ) );
		self::route( '/tasks/(?P<id>\d+)', 'PUT,PATCH', array( $c, 'update_task' ) );
		self::route( '/tasks/(?P<id>\d+)', 'DELETE', array( $c, 'archive_task' ) );
		self::route( '/tasks/(?P<id>\d+)/status', 'POST', array( $c, 'task_status' ) );
		self::route( '/tasks/(?P<id>\d+)/remarks', 'POST', array( $c, 'task_remark' ) );
		self::route( '/tasks/(?P<id>\d+)/followups', 'POST', array( $c, 'task_followup' ) );
		self::route( '/tasks/(?P<id>\d+)/handoff', 'POST', array( $c, 'task_handoff' ) );
		NDWD_Workflow::routes();

		self::route( '/dashboard/me', 'GET', array( $c, 'dashboard_me' ) );
		self::route( '/dashboard/team', 'GET', array( $c, 'dashboard_team' ), self::team_perm( 'view_team_tasks' ) );
		self::route( '/calendar', 'GET', array( $c, 'calendar' ) );

		self::route( '/notifications', 'GET', array( $c, 'notifications' ) );
		self::route( '/notifications/read', 'POST', array( $c, 'notifications_read' ) );
		self::route( '/notifications/delete', 'POST', array( $c, 'notifications_delete' ) );
		self::route( '/notifications/clear-read', 'POST', array( $c, 'notifications_clear_read' ) );

		self::route( '/attendance', 'GET', array( $c, 'attendance' ) );
		self::route( '/attendance/presence', 'POST', array( $c, 'attendance_presence' ) );
		self::route( '/reports/productivity', 'GET', array( $c, 'productivity' ) );
		self::route( '/activity', 'GET', array( $c, 'activity' ) );

		self::route( '/members', 'GET', array( $c, 'members' ), self::cap( 'ndwd_manage_members' ) );
		self::route( '/members', 'POST', array( $c, 'create_member' ), self::cap( 'ndwd_manage_members' ) );
		self::route( '/members/(?P<id>\d+)', 'PUT,PATCH', array( $c, 'update_member' ), self::cap( 'ndwd_manage_members' ) );

		self::route( '/teams', 'GET', array( $c, 'teams' ) );
		self::route( '/teams', 'POST', array( $c, 'save_team' ), self::cap( 'ndwd_manage_teams' ) );
		self::route( '/teams/(?P<id>\d+)', 'PUT,PATCH', array( $c, 'save_team' ), self::cap( 'ndwd_manage_teams' ) );
		self::route( '/teams/(?P<id>\d+)/members', 'PUT', array( $c, 'team_members' ), self::cap( 'ndwd_manage_teams' ) );
		self::route( '/teams/(?P<id>\d+)', 'DELETE', array( $c, 'delete_team' ), self::delete_cap( 'ndwd_manage_teams' ) );
		self::route( '/projects/(?P<id>\d+)', 'DELETE', array( $c, 'delete_project' ), self::delete_cap( 'ndwd_manage_projects' ) );
		self::route( '/clients/(?P<id>\d+)', 'DELETE', array( $c, 'delete_client' ), self::delete_cap( 'ndwd_manage_clients' ) );

		self::route( '/projects', 'POST', array( $c, 'save_project' ), self::cap( 'ndwd_manage_projects' ) );
		self::route( '/projects/(?P<id>\d+)', 'PUT,PATCH', array( $c, 'save_project' ), self::cap( 'ndwd_manage_projects' ) );
		self::route( '/tags', 'POST', array( $c, 'save_tag' ), self::cap( 'ndwd_manage_projects' ) );
		self::route( '/tags/(?P<id>\d+)', 'PUT,PATCH', array( $c, 'save_tag' ), self::cap( 'ndwd_manage_projects' ) );
		self::route( '/tags/(?P<id>\d+)', 'DELETE', array( $c, 'delete_tag' ), self::cap( 'ndwd_manage_projects' ) );

		self::route( '/settings', 'GET', array( $c, 'get_settings' ), self::cap( 'ndwd_manage_settings' ) );
		self::route( '/settings', 'PUT', array( $c, 'save_settings' ), self::cap( 'ndwd_manage_settings' ) );
		self::route( '/settings/test-email', 'POST', array( $c, 'test_email' ), self::cap( 'ndwd_manage_settings' ) );
		self::route( '/settings/run-cron', 'POST', array( $c, 'run_cron_now' ), self::cap( 'ndwd_manage_settings' ) );

		self::route( '/archives', 'GET', array( $c, 'archives' ) );
		self::route( '/archives/generate', 'POST', array( $c, 'generate_archive' ), self::cap( 'ndwd_manage_reports' ) );
		self::route( '/archives/(?P<id>\d+)/files/(?P<file>\d+)', 'GET', array( $c, 'archive_file' ) );
		self::route( '/archives/(?P<id>\d+)/confirm', 'POST', array( $c, 'archive_confirm' ), self::cap( 'ndwd_manage_reports' ) );
		self::route( '/archives/(?P<id>\d+)/email', 'POST', array( $c, 'archive_email' ), self::cap( 'ndwd_manage_reports' ) );
		self::route( '/reports/export', 'POST', array( $c, 'export_report' ) );
		self::route( '/jobs', 'GET', array( $c, 'jobs' ), self::cap( 'ndwd_manage_settings' ) );

		self::route( '/import/preview', 'POST', array( $c, 'import_preview' ), self::cap( 'ndwd_import' ) );
		self::route( '/import/commit', 'POST', array( $c, 'import_commit' ), self::cap( 'ndwd_import' ) );

		self::route( '/clients', 'GET', array( $c, 'clients' ) );
		self::route( '/clients/(?P<id>\d+)', 'GET', array( $c, 'get_client' ) );
		self::route( '/clients', 'POST', array( $c, 'save_client' ), self::cap( 'ndwd_manage_clients' ) );
		self::route( '/clients/(?P<id>\d+)', 'PUT,PATCH', array( $c, 'save_client' ), self::cap( 'ndwd_manage_clients' ) );

		self::route( '/leaves', 'GET', array( $c, 'leaves' ) );
		self::route( '/leaves', 'POST', array( $c, 'apply_leave' ) );
		self::route( '/leaves/(?P<id>\d+)/decision', 'POST', array( $c, 'decide_leave' ), self::team_perm( 'approve_leave' ) );
		self::route( '/leaves/(?P<id>\d+)/cancel', 'POST', array( $c, 'cancel_leave' ) );
		self::route( '/leaves/(?P<id>\d+)/tasks', 'GET', array( $c, 'leave_tasks' ), self::team_perm( 'approve_leave' ) );
		self::route( '/leaves/(?P<id>\d+)/reassign', 'POST', array( $c, 'leave_reassign' ), self::team_perm( 'approve_leave' ) );

		NDWD_Accounts_REST::routes();
		NDWD_Packages::routes();
		NDWD_Profile::routes();
		NDWD_Push::routes();

		// Server cron endpoint, protected by a secret key (not by login).
		register_rest_route( self::NS, '/cron', array(
			'methods'             => 'GET,POST',
			'callback'            => array( $c, 'cron' ),
			'permission_callback' => function ( $req ) {
				$key = (string) $req->get_param( 'key' );
				return '' !== $key && hash_equals( (string) get_option( 'ndwd_cron_key' ), $key );
			},
		) );
	}

	private static function uid() {
		return get_current_user_id();
	}

	private static function out( $data ) {
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return rest_ensure_response( $data );
	}

	/* ------------------------------------------------------------------ *
	 * Bootstrap
	 * ------------------------------------------------------------------ */

	public static function bootstrap() {
		global $wpdb;
		$uid  = self::uid();
		$user = wp_get_current_user();
		$caps    = NDWD_Permissions::ui_caps( $uid );
		$any     = NDWD_Permissions::can( 'ndwd_edit_all_tasks' ) || NDWD_Permissions::is_admin();
		$mates   = NDWD_Permissions::members_of( NDWD_Permissions::teams_with( 'assign_tasks' ) );
		$mates[] = $uid;
		$members = array();
		foreach ( get_users( array( 'capability' => 'ndwd_access', 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name' ) ) ) as $u ) {
			$pf        = NDWD_Profile::get( $u->ID );
			$members[] = array(
				'id'         => (int) $u->ID,
				'name'       => $u->display_name,
				'avatar'     => $pf['avatar'],
				'color'      => $pf['color'],
				'active'     => NDWD_Permissions::is_active( $u->ID ),
				'assignable' => NDWD_Permissions::is_active( $u->ID ) && ( $any || in_array( (int) $u->ID, $mates, true ) ),
				'teams'      => NDWD_Permissions::user_teams( $u->ID ),
			);
		}
		$is_admin = $caps['ndwd_manage_settings'];
		return self::out( array(
			'user'          => array(
				'id'       => $uid,
				'name'     => $user->display_name,
				'email'    => $user->user_email,
				'handle'   => (string) get_user_meta( $uid, NDWD_Profile::META_HANDLE, true ),
				'role'     => NDWD_Permissions::role_of( $uid ),
				'teams'    => NDWD_Permissions::user_teams( $uid ),
				'managed'  => NDWD_Permissions::managed_teams( $uid ),
				'team_perms' => (object) array_map( function ( $m ) {
					return $m['perms'];
				}, NDWD_Permissions::memberships( $uid ) ),
				'theme'    => get_user_meta( $uid, 'ndwd_theme', true ) ? get_user_meta( $uid, 'ndwd_theme', true ) : 'system',
				'email_optout' => (bool) get_user_meta( $uid, 'ndwd_email_optout', true ),
			),
			'caps'          => $caps,
			'today'         => NDWD_Helpers::today(),
			'now'           => NDWD_Helpers::now()->format( 'Y-m-d\TH:i:s' ),
			'timezone'      => NDWD_TZ,
			'statuses'      => NDWD_Helpers::STATUSES,
			'priorities'    => NDWD_Helpers::priorities(),
			'work_types'    => NDWD_Helpers::work_types(),
			'members'       => $members,
			'teams'         => self::team_list( false ),
			'projects'      => self::project_list(),
			'clients'       => NDWD_Clients::all( $uid ),
			'team_perm_labels' => NDWD_Permissions::TEAM_PERMS,
			'leave_types'   => NDWD_Leaves::TYPES,
			'avatar_colors' => NDWD_Profile::COLORS,
			'package_scopes' => NDWD_Packages::SCOPES,
			'leave_pending' => count( NDWD_Leaves::query( $uid, 'review', 'pending' ) ),
			'tags'          => $wpdb->get_results( 'SELECT id, name, color FROM ' . NDWD_Helpers::table( 'tags' ) . ' ORDER BY name', ARRAY_A ), // phpcs:ignore
			'unread'        => NDWD_Notifications::unread_count( $uid ),
			'email_status'  => $is_admin ? NDWD_Notifications::email_status() : null,
			'logout_url'    => wp_specialchars_decode( wp_logout_url( NDWD_App::url() ) ), // wp_logout_url() is HTML-escaped; the UI escapes it again.
			'admin_url'     => current_user_can( 'manage_options' ) ? admin_url() : null,
		) );
	}

	/** Projects; the client of a project is only named when the viewer may see that client. */
	private static function project_list() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, name, client, client_id, description, is_active, parent_id, is_archived FROM ' . NDWD_Helpers::table( 'projects' ) . ' ORDER BY name', ARRAY_A ); // phpcs:ignore
		$uid  = self::uid();
		foreach ( $rows as &$p ) {
			if ( ( $p['client_id'] && ! NDWD_Clients::can_view( $p['client_id'], $uid ) ) || ( ! $p['client_id'] && $p['client'] && ! NDWD_Clients::sees_all( $uid ) ) ) {
				$p['client_id'] = null;
				$p['client']    = '';
			}
		}
		return $rows;
	}

	/** Rejects a client the user may not see (used when tasks are filtered by or linked to clients). */
	private static function check_client( $client_id ) {
		if ( $client_id && ! NDWD_Clients::can_view( $client_id, self::uid() ) ) {
			return new WP_Error( 'ndwd_not_found', 'Client not found or you do not have access.', array( 'status' => 404 ) );
		}
		return true;
	}

	public static function preferences( WP_REST_Request $r ) {
		$theme = sanitize_key( $r->get_param( 'theme' ) );
		if ( in_array( $theme, array( 'light', 'dark', 'system' ), true ) ) {
			update_user_meta( self::uid(), 'ndwd_theme', $theme );
		}
		if ( null !== $r->get_param( 'email_optout' ) ) {
			update_user_meta( self::uid(), 'ndwd_email_optout', $r->get_param( 'email_optout' ) ? 1 : 0 );
		}
		return self::out( array( 'ok' => true ) );
	}

	/* ------------------------------------------------------------------ *
	 * Tasks
	 * ------------------------------------------------------------------ */

	private static function task_filters( WP_REST_Request $r ) {
		$f = array();
		foreach ( array( 'view', 'scope', 'work_type' ) as $k ) {
			if ( $r->get_param( $k ) ) {
				$f[ $k ] = sanitize_key( $r->get_param( $k ) );
			}
		}
		foreach ( array( 'date', 'from', 'to' ) as $k ) {
			$v = NDWD_Helpers::sanitize_date( (string) $r->get_param( $k ) );
			if ( $v ) {
				$f[ $k ] = $v;
			}
		}
		foreach ( array( 'team_id', 'member_id', 'project_id', 'client_id', 'tag_id', 'page', 'per_page' ) as $k ) {
			if ( $r->get_param( $k ) ) {
				$f[ $k ] = absint( $r->get_param( $k ) );
			}
		}
		foreach ( array( 'status', 'priority' ) as $k ) {
			$v = $r->get_param( $k );
			if ( $v ) {
				$f[ $k ] = array_map( 'sanitize_key', is_array( $v ) ? $v : explode( ',', $v ) );
			}
		}
		if ( $r->get_param( 'q' ) ) {
			$f['q'] = sanitize_text_field( $r->get_param( 'q' ) );
		}
		return $f;
	}

	public static function list_tasks( WP_REST_Request $r ) {
		$f  = self::task_filters( $r );
		$ok = self::check_client( isset( $f['client_id'] ) ? $f['client_id'] : 0 );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return self::out( NDWD_Tasks::query( $f, self::uid() ) );
	}

	private static function body( WP_REST_Request $r ) {
		$b = $r->get_json_params();
		return is_array( $b ) ? $b : $r->get_body_params();
	}

	public static function create_task( WP_REST_Request $r ) {
		$b = self::body( $r );
		unset( $b['status'] ); // New tasks always start as Pending.
		$ok = self::check_client( isset( $b['client_id'] ) ? absint( $b['client_id'] ) : 0 );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$t = NDWD_Tasks::create( $b, self::uid() );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		if ( isset( $b['client_visibility'] ) && is_array( $b['client_visibility'] ) ) {
			NDWD_WS_TaskLink::apply( $t, $b['client_visibility'], self::uid() );
		}
		return self::out( NDWD_Tasks::to_array( $t, true ) );
	}

	private static function visible_task( $id ) {
		$t = NDWD_Tasks::get( $id );
		if ( ! $t || $t->is_archived || ! NDWD_Permissions::can_view_task( $t ) ) {
			return new WP_Error( 'ndwd_not_found', 'Task not found or you do not have access.', array( 'status' => 404 ) );
		}
		return $t;
	}

	public static function get_task( WP_REST_Request $r ) {
		$t = self::visible_task( (int) $r['id'] );
		return is_wp_error( $t ) ? $t : self::out( NDWD_Tasks::to_array( $t, true ) );
	}

	public static function update_task( WP_REST_Request $r ) {
		$t = self::visible_task( (int) $r['id'] );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$b = self::body( $r );
		if ( isset( $b['client_id'] ) && absint( $b['client_id'] ) !== (int) $t->client_id ) {
			$ok = self::check_client( absint( $b['client_id'] ) );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		$res = NDWD_Tasks::update( $t->id, $b, self::uid() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( isset( $b['client_visibility'] ) && is_array( $b['client_visibility'] ) && NDWD_Permissions::can_edit_task( $res ) ) {
			NDWD_WS_TaskLink::apply( $res, $b['client_visibility'], self::uid() );
		}
		return self::out( NDWD_Tasks::to_array( $res, true ) );
	}

	public static function archive_task( WP_REST_Request $r ) {
		$t = self::visible_task( (int) $r['id'] );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$res = NDWD_Tasks::archive( $t->id, self::uid() );
		return is_wp_error( $res ) ? $res : self::out( array( 'ok' => true ) );
	}

	public static function task_status( WP_REST_Request $r ) {
		$t = self::visible_task( (int) $r['id'] );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$b   = self::body( $r );
		$res = NDWD_Tasks::set_status( $t->id, isset( $b['status'] ) ? sanitize_key( $b['status'] ) : '', self::uid(), isset( $b['note'] ) ? $b['note'] : '' );
		return is_wp_error( $res ) ? $res : self::out( NDWD_Tasks::to_array( $res, true ) );
	}

	public static function task_remark( WP_REST_Request $r ) {
		$t = self::visible_task( (int) $r['id'] );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$b   = self::body( $r );
		$res = NDWD_Tasks::add_remark( $t->id, isset( $b['body'] ) ? $b['body'] : '', self::uid() );
		return is_wp_error( $res ) ? $res : self::out( NDWD_Tasks::to_array( $res, true ) );
	}

	public static function task_followup( WP_REST_Request $r ) {
		$t = self::visible_task( (int) $r['id'] );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$b = self::body( $r );
		unset( $b['status'] );
		$res = NDWD_Tasks::create_followup( $t->id, $b, self::uid() );
		return is_wp_error( $res ) ? $res : self::out( NDWD_Tasks::to_array( $res, true ) );
	}

	/** Hand the task from one team to another (team managers of the task's team, Admins). */
	public static function task_handoff( WP_REST_Request $r ) {
		$t = self::visible_task( (int) $r['id'] );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		$res = NDWD_Tasks::handoff( $t->id, self::body( $r ), self::uid() );
		return is_wp_error( $res ) ? $res : self::out( NDWD_Tasks::to_array( $res, true ) );
	}

	/* ------------------------------------------------------------------ *
	 * Dashboards, calendar, notifications
	 * ------------------------------------------------------------------ */

	public static function dashboard_me() {
		return self::out( NDWD_Stats::my_dashboard( self::uid() ) );
	}

	public static function dashboard_team( WP_REST_Request $r ) {
		$f = self::task_filters( $r );
		if ( ! empty( $f['member_id'] ) && $f['member_id'] !== self::uid() && ! NDWD_Permissions::can_view_member_reports( $f['member_id'] ) ) {
			return new WP_Error( 'ndwd_forbidden', 'You are not allowed to filter by other members.', array( 'status' => 403 ) );
		}
		return self::out( NDWD_Stats::team_dashboard( self::uid(), $f ) );
	}

	public static function calendar( WP_REST_Request $r ) {
		$month = preg_match( '/^\d{4}-\d{2}$/', (string) $r->get_param( 'month' ) ) ? $r->get_param( 'month' ) : NDWD_Helpers::now()->format( 'Y-m' );
		$range = NDWD_Helpers::month_range( $month );
		if ( ! $range ) {
			return new WP_Error( 'ndwd_invalid', 'Invalid month.', array( 'status' => 400 ) );
		}
		$f              = self::task_filters( $r );
		$f['from']      = $range[0];
		$f['to']        = $range[1];
		$f['per_page']  = 500;
		unset( $f['view'] );
		$res  = NDWD_Tasks::query( $f, self::uid() );
		$days = array();
		foreach ( $res['items'] as $t ) {
			$days[ $t['scheduled_date'] ][] = $t;
		}
		return self::out( array( 'month' => $month, 'from' => $range[0], 'to' => $range[1], 'days' => (object) $days, 'total' => $res['total'] ) );
	}

	public static function notifications( WP_REST_Request $r ) {
		return self::out( array(
			'items'  => NDWD_Notifications::for_user( self::uid(), min( 100, max( 5, (int) $r->get_param( 'limit' ) ? (int) $r->get_param( 'limit' ) : 30 ) ), (bool) $r->get_param( 'unread' ) ),
			'unread' => NDWD_Notifications::unread_count( self::uid() ),
		) );
	}

	public static function notifications_read( WP_REST_Request $r ) {
		$b = self::body( $r );
		NDWD_Notifications::mark_read( self::uid(), ! empty( $b['all'] ) ? null : ( isset( $b['ids'] ) ? $b['ids'] : array() ) );
		return self::out( array( 'unread' => NDWD_Notifications::unread_count( self::uid() ) ) );
	}

	public static function notifications_delete( WP_REST_Request $r ) {
		$b = self::body( $r );
		$n = NDWD_Notifications::remove( self::uid(), isset( $b['ids'] ) ? (array) $b['ids'] : array() );
		return self::out( array( 'removed' => $n, 'unread' => NDWD_Notifications::unread_count( self::uid() ) ) );
	}

	public static function notifications_clear_read( WP_REST_Request $r ) {
		$n = NDWD_Notifications::remove( self::uid(), null, true );
		return self::out( array( 'removed' => $n, 'unread' => NDWD_Notifications::unread_count( self::uid() ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Reports, attendance, activity
	 * ------------------------------------------------------------------ */

	/** Resolves which members the viewer may report on. */
	private static function report_members( WP_REST_Request $r ) {
		return self::report_scope( self::uid(), absint( $r->get_param( 'member_id' ) ), absint( $r->get_param( 'team_id' ) ) );
	}

	/**
	 * Members (and task filter) a user may report on. Admins: everyone; people with
	 * "view reports" in some teams: those teams; everyone else: themselves only.
	 *
	 * @return array|WP_Error [ member_ids, base task filters ]
	 */
	public static function report_scope( $uid, $member_id, $team_id ) {
		if ( $member_id ) {
			if ( ! NDWD_Permissions::can_view_member_reports( $member_id, $uid ) ) {
				return new WP_Error( 'ndwd_forbidden', 'You cannot view reports for this member.', array( 'status' => 403 ) );
			}
			return array( array( $member_id ), $team_id ? array( 'team_only' => array( $team_id ) ) : array() );
		}
		$managed = NDWD_Permissions::teams_with( 'view_reports', $uid );
		if ( null === $managed ) {
			return array( NDWD_Stats::scope_members( $uid, $team_id ), $team_id ? array( 'team_only' => array( $team_id ) ) : array() );
		}
		// Without "view reports" in any team (or for a team they may not report on): own figures only.
		if ( ! $managed || ( $team_id && ! in_array( $team_id, $managed, true ) ) ) {
			return array( array( $uid ), array( 'scope' => 'mine' ) );
		}
		$members   = NDWD_Permissions::members_of( $team_id ? array( $team_id ) : $managed );
		$members[] = $uid;
		return array( array_values( array_unique( $members ) ), array( 'team_ids' => $team_id ? array( $team_id ) : $managed ) );
	}

	private static function period( WP_REST_Request $r ) {
		$from = NDWD_Helpers::sanitize_date( (string) $r->get_param( 'from' ) );
		$to   = NDWD_Helpers::sanitize_date( (string) $r->get_param( 'to' ) );
		if ( $from && $to && $from <= $to ) {
			return array( $from, $to );
		}
		$p = sanitize_key( $r->get_param( 'period' ) );
		return NDWD_Helpers::period_range( in_array( $p, array( 'daily', 'weekly', 'monthly' ), true ) ? $p : 'monthly', (string) $r->get_param( 'date' ) );
	}

	public static function productivity( WP_REST_Request $r ) {
		$m = self::report_members( $r );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		list( $from, $to ) = self::period( $r );
		$base              = $m[1];
		if ( absint( $r->get_param( 'member_id' ) ) ) {
			$base['member_id'] = absint( $r->get_param( 'member_id' ) );
		}
		if ( absint( $r->get_param( 'project_id' ) ) ) {
			$base['project_id'] = absint( $r->get_param( 'project_id' ) );
		}
		$res = NDWD_Stats::productivity( $from, $to, $m[0], $base, self::uid() );
		unset( $res['rows'] );
		return self::out( $res );
	}

	/** The app reports genuine use (a click or keypress) after the IST date changed in an open tab. */
	public static function attendance_presence() {
		return self::out( array( 'recorded' => NDWD_Attendance::touch_session( self::uid() ), 'date' => NDWD_Helpers::today() ) );
	}

	public static function attendance( WP_REST_Request $r ) {
		$m = self::report_members( $r );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		list( $from, $to ) = self::period( $r );
		return self::out( array(
			'from'        => $from,
			'to'          => $to,
			'summary'     => NDWD_Attendance::summary( $from, $to, $m[0] ),
			'rows'        => array_map( array( 'NDWD_Attendance', 'format_row' ), NDWD_Attendance::rows( $from, $to, $m[0] ) ),
			'definitions' => array( 'attendance' => NDWD_Stats::DEFINITIONS['attendance'] ),
		) );
	}

	public static function activity( WP_REST_Request $r ) {
		$m = self::report_members( $r );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		list( $from, $to ) = self::period( $r );
		return self::out( array( 'from' => $from, 'to' => $to, 'items' => $m[0] ? NDWD_Activity::recent( array( 'user_ids' => $m[0], 'from' => $from, 'to' => $to, 'limit' => 300 ) ) : array() ) );
	}

	/* ------------------------------------------------------------------ *
	 * Members (administration)
	 * ------------------------------------------------------------------ */

	public static function members() {
		$out = array();
		foreach ( get_users( array( 'role__in' => array( 'administrator', 'ndwd_admin', 'ndwd_manager', 'ndwd_member' ), 'orderby' => 'display_name' ) ) as $u ) {
			$out[] = self::member_array( $u );
		}
		// Also users granted ndwd_access directly.
		$ids = wp_list_pluck( $out, 'id' );
		foreach ( get_users( array( 'capability' => 'ndwd_access', 'exclude' => $ids ) ) as $u ) {
			$out[] = self::member_array( $u );
		}
		$caps = NDWD_Installer::all_caps();
		unset( $caps['ndwd_access'] );
		return self::out( array( 'members' => $out, 'caps' => $caps, 'roles' => array( 'ndwd_member' => 'Member', 'ndwd_admin' => 'Admin' ) ) );
	}

	private static function member_array( $u ) {
		global $wpdb;
		$teams = $wpdb->get_results( $wpdb->prepare( 'SELECT team_id, is_manager FROM ' . NDWD_Helpers::table( 'team_members' ) . ' WHERE user_id = %d', $u->ID ) );
		$extra = array();
		$role  = NDWD_Permissions::role_of( $u->ID );
		$base  = in_array( $role, array( 'ndwd_member', 'ndwd_admin' ), true ) ? NDWD_Installer::role_caps( $role ) : array();
		foreach ( array_keys( NDWD_Installer::all_caps() ) as $cap ) {
			if ( ! empty( $u->caps[ $cap ] ) && empty( $base[ $cap ] ) ) {
				$extra[] = $cap;
			}
		}
		$last = get_user_meta( $u->ID, 'ndwd_last_login', true );
		return array(
			'id'           => (int) $u->ID,
			'login'        => $u->user_login,
			'name'         => $u->display_name,
			'email'        => $u->user_email,
			'role'         => $role,
			'is_wp_admin'  => in_array( 'administrator', (array) $u->roles, true ),
			'active'       => NDWD_Permissions::is_active( $u->ID ),
			'extra_caps'   => $extra,
			'effective'    => array_values( array_filter( array_keys( NDWD_Installer::all_caps() ), function ( $c ) use ( $u ) {
				return user_can( $u, $c );
			} ) ),
			'teams'        => array_map( function ( $t ) {
				return array( 'team_id' => (int) $t->team_id, 'is_manager' => (bool) $t->is_manager );
			}, $teams ),
			'registered'   => $u->user_registered,
			'last_login'   => $last ? $last : null,
		);
	}

	public static function create_member( WP_REST_Request $r ) {
		$b     = self::body( $r );
		$email = sanitize_email( isset( $b['email'] ) ? $b['email'] : '' );
		$login = sanitize_user( isset( $b['login'] ) && $b['login'] ? $b['login'] : strtok( $email, '@' ), true );
		$name  = sanitize_text_field( isset( $b['name'] ) ? $b['name'] : '' );
		$role  = isset( $b['role'] ) ? sanitize_key( $b['role'] ) : 'ndwd_member';
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'ndwd_invalid', 'A valid email address is required.', array( 'status' => 400 ) );
		}
		if ( '' === $name ) {
			return new WP_Error( 'ndwd_invalid', 'Name is required.', array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'ndwd_invalid', 'A user with this email already exists.', array( 'status' => 400 ) );
		}
		if ( ! $login || username_exists( $login ) ) {
			return new WP_Error( 'ndwd_invalid', 'That username is not available.', array( 'status' => 400 ) );
		}
		$check = self::check_role_change( null, $role, array() );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		$password = isset( $b['password'] ) ? (string) $b['password'] : '';
		$send     = '' === $password || ! empty( $b['send_invite'] );
		if ( '' !== $password && NDWD_Helpers::password_problem( $password ) ) {
			return new WP_Error( 'ndwd_invalid', NDWD_Helpers::password_problem( $password ), array( 'status' => 400 ) );
		}
		$parts = explode( ' ', $name, 2 );
		$id    = wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'display_name' => $name,
			'first_name'   => $parts[0],
			'last_name'    => isset( $parts[1] ) ? $parts[1] : '',
			'user_pass'    => '' !== $password ? $password : wp_generate_password( 24, true, true ),
			'role'         => $role,
		) );
		if ( is_wp_error( $id ) ) {
			return new WP_Error( 'ndwd_invalid', $id->get_error_message(), array( 'status' => 400 ) );
		}
		update_user_meta( $id, 'show_admin_bar_front', 'false' );
		$invite = null;
		if ( $send ) {
			wp_new_user_notification( $id, null, 'user' );
			$invite = 'A "set your password" email was sent to ' . $email . ' (requires working email).';
		}
		self::apply_teams( $id, isset( $b['teams'] ) ? $b['teams'] : array() );
		return self::out( array( 'member' => self::member_array( get_userdata( $id ) ), 'message' => $invite ) );
	}

	/**
	 * Prevents privilege escalation: only holders of ndwd_grant_admin may assign
	 * the Admin role or admin capabilities, nobody may change their own
	 * role/capabilities/active state, and only WP administrators may edit WP administrators.
	 */
	private static function check_role_change( $target_id, $role, $caps ) {
		$me = self::uid();
		if ( $target_id && (int) $target_id === $me ) {
			return new WP_Error( 'ndwd_forbidden', 'You cannot change your own role, permissions or status.', array( 'status' => 403 ) );
		}
		if ( $target_id && user_can( $target_id, 'manage_options' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ndwd_forbidden', 'Only a site administrator can change another administrator.', array( 'status' => 403 ) );
		}
		if ( null !== $role && ! in_array( $role, array( 'ndwd_member', 'ndwd_admin' ), true ) ) {
			return new WP_Error( 'ndwd_invalid', 'Invalid role.', array( 'status' => 400 ) );
		}
		$grant = NDWD_Permissions::can( 'ndwd_grant_admin' );
		if ( 'ndwd_admin' === $role && ! $grant ) {
			return new WP_Error( 'ndwd_forbidden', 'You are not allowed to grant administrative rights.', array( 'status' => 403 ) );
		}
		foreach ( $caps as $cap ) {
			if ( ! array_key_exists( $cap, NDWD_Installer::all_caps() ) ) {
				return new WP_Error( 'ndwd_invalid', 'Unknown permission: ' . $cap, array( 'status' => 400 ) );
			}
			if ( ! $grant || ! NDWD_Permissions::can( $cap ) ) {
				return new WP_Error( 'ndwd_forbidden', 'You are not allowed to grant the permission "' . $cap . '".', array( 'status' => 403 ) );
			}
		}
		return true;
	}

	public static function update_member( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		$u  = get_userdata( $id );
		if ( ! $u ) {
			return new WP_Error( 'ndwd_not_found', 'Member not found.', array( 'status' => 404 ) );
		}
		$b = self::body( $r );
		if ( user_can( $id, 'manage_options' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'ndwd_forbidden', 'Only a site administrator can edit another administrator.', array( 'status' => 403 ) );
		}
		$data = array( 'ID' => $id );
		if ( isset( $b['name'] ) && '' !== trim( $b['name'] ) ) {
			$data['display_name'] = sanitize_text_field( $b['name'] );
		}
		if ( isset( $b['email'] ) ) {
			$email = sanitize_email( $b['email'] );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'ndwd_invalid', 'Invalid email address.', array( 'status' => 400 ) );
			}
			$owner = email_exists( $email );
			if ( $owner && (int) $owner !== $id ) {
				return new WP_Error( 'ndwd_invalid', 'That email is used by another account.', array( 'status' => 400 ) );
			}
			$data['user_email'] = $email;
		}

		$role_changes = isset( $b['role'] ) && $b['role'] !== NDWD_Permissions::role_of( $id ) && ! in_array( 'administrator', $u->roles, true );
		$new_caps     = isset( $b['extra_caps'] ) && is_array( $b['extra_caps'] ) ? array_values( array_map( 'sanitize_key', $b['extra_caps'] ) ) : null;
		$active_set   = isset( $b['active'] );
		if ( $role_changes || null !== $new_caps || $active_set ) {
			$current = self::member_array( $u );
			$added   = null !== $new_caps ? array_values( array_diff( $new_caps, $current['extra_caps'] ) ) : array();
			$removed = null !== $new_caps ? array_values( array_diff( $current['extra_caps'], $new_caps ) ) : array();
			$changes = $role_changes || $added || $removed || ( $active_set && (bool) $b['active'] !== $current['active'] );
			if ( $changes ) {
				$check = self::check_role_change( $id, $role_changes ? sanitize_key( $b['role'] ) : null, $added );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
				// Removing admin-level rights from someone also requires grant rights.
				if ( ( $removed || ( $role_changes && 'ndwd_admin' === $current['role'] ) ) && ! NDWD_Permissions::can( 'ndwd_grant_admin' ) ) {
					return new WP_Error( 'ndwd_forbidden', 'You are not allowed to remove permissions.', array( 'status' => 403 ) );
				}
			}
		}
		if ( count( $data ) > 1 ) {
			$res = wp_update_user( $data );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'ndwd_invalid', $res->get_error_message(), array( 'status' => 400 ) );
			}
		}
		$u = get_userdata( $id );
		if ( $role_changes ) {
			$u->set_role( sanitize_key( $b['role'] ) );
		}
		if ( null !== $new_caps ) {
			foreach ( array_keys( NDWD_Installer::all_caps() ) as $cap ) {
				$role_has = ! empty( NDWD_Installer::role_caps( NDWD_Permissions::role_of( $id ) )[ $cap ] );
				if ( in_array( $cap, $new_caps, true ) && ! $role_has ) {
					$u->add_cap( $cap );
				} elseif ( isset( $u->caps[ $cap ] ) ) {
					$u->remove_cap( $cap );
				}
			}
		}
		if ( $active_set ) {
			if ( $b['active'] ) {
				delete_user_meta( $id, 'ndwd_inactive' );
			} else {
				update_user_meta( $id, 'ndwd_inactive', 1 );
				WP_Session_Tokens::get_instance( $id )->destroy_all();
			}
		}
		if ( isset( $b['teams'] ) && NDWD_Permissions::can( 'ndwd_manage_teams' ) ) {
			self::apply_teams( $id, $b['teams'] );
		}
		return self::out( array( 'member' => self::member_array( get_userdata( $id ) ) ) );
	}

	/** $teams: [ {team_id, is_manager} ] — replaces memberships for the user. */
	private static function apply_teams( $user_id, $teams ) {
		global $wpdb;
		if ( ! is_array( $teams ) || ! NDWD_Permissions::can( 'ndwd_manage_teams' ) ) {
			return;
		}
		$table = NDWD_Helpers::table( 'team_members' );
		// Manager positions are set in Create / Edit Team; keep them unless explicitly given.
		$was = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT team_id, is_manager FROM $table WHERE user_id = %d", $user_id ) ) as $row ) {
			$was[ (int) $row->team_id ] = (int) $row->is_manager;
		}
		$wpdb->delete( $table, array( 'user_id' => $user_id ) );
		foreach ( $teams as $t ) {
			$tid = absint( is_array( $t ) ? ( isset( $t['team_id'] ) ? $t['team_id'] : 0 ) : $t );
			if ( $tid ) {
				$mgr = is_array( $t ) && array_key_exists( 'is_manager', $t ) ? ( $t['is_manager'] ? 1 : 0 ) : ( isset( $was[ $tid ] ) ? $was[ $tid ] : 0 );
				$wpdb->replace( $table, array( 'team_id' => $tid, 'user_id' => $user_id, 'is_manager' => $mgr, 'added_at' => NDWD_Helpers::now_mysql() ) );
			}
		}
		NDWD_Permissions::flush();
		NDWD_Clients::flush();
	}

	/* ------------------------------------------------------------------ *
	 * Teams, projects, tags
	 * ------------------------------------------------------------------ */

	private static function team_list( $include_inactive = true ) {
		global $wpdb;
		$teams = $wpdb->get_results( 'SELECT * FROM ' . NDWD_Helpers::table( 'teams' ) . ( $include_inactive ? '' : ' WHERE is_active = 1' ) . ' ORDER BY name' ); // phpcs:ignore
		$mem   = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_Helpers::table( 'team_members' ) ) as $m ) { // phpcs:ignore
			$mem[ $m->team_id ][] = array( 'user_id' => (int) $m->user_id, 'is_manager' => (bool) $m->is_manager );
		}
		return array_map( function ( $t ) use ( $mem ) {
			return array(
				'id'          => (int) $t->id,
				'name'        => $t->name,
				'description' => (string) $t->description,
				'color'       => $t->color,
				'is_active'   => (bool) $t->is_active,
				'members'     => isset( $mem[ $t->id ] ) ? $mem[ $t->id ] : array(),
				'permissions' => NDWD_Permissions::team_permissions( $t->permissions ),
			);
		}, $teams );
	}

	public static function teams() {
		return self::out( self::team_list( NDWD_Permissions::can( 'ndwd_manage_teams' ) ) );
	}

	public static function save_team( WP_REST_Request $r ) {
		global $wpdb;
		$b    = self::body( $r );
		$id   = isset( $r['id'] ) ? (int) $r['id'] : 0;
		$name = sanitize_text_field( isset( $b['name'] ) ? $b['name'] : '' );
		if ( ! $id && '' === $name ) {
			return new WP_Error( 'ndwd_invalid', 'Team name is required.', array( 'status' => 400 ) );
		}
		$row = array( 'updated_at' => NDWD_Helpers::now_mysql() );
		if ( '' !== $name ) {
			$row['name'] = mb_substr( $name, 0, 120 );
		}
		if ( isset( $b['description'] ) ) {
			$row['description'] = sanitize_textarea_field( $b['description'] );
		}
		if ( isset( $b['color'] ) && sanitize_hex_color( $b['color'] ) ) {
			$row['color'] = sanitize_hex_color( $b['color'] );
		}
		if ( isset( $b['is_active'] ) ) {
			$row['is_active'] = $b['is_active'] ? 1 : 0;
		}
		if ( isset( $b['permissions'] ) && is_array( $b['permissions'] ) ) {
			$row['permissions'] = wp_json_encode( NDWD_Permissions::team_permissions( $b['permissions'] ) );
		}
		$table = NDWD_Helpers::table( 'teams' );
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
		} else {
			$row['created_by'] = self::uid();
			$row['created_at'] = $row['updated_at'];
			$wpdb->insert( $table, $row );
			$id = (int) $wpdb->insert_id;
		}
		if ( isset( $b['members'] ) && is_array( $b['members'] ) ) {
			self::set_team_members( $id, $b['members'] );
		}
		return self::out( self::team_list() );
	}

	public static function team_members( WP_REST_Request $r ) {
		$b = self::body( $r );
		self::set_team_members( (int) $r['id'], isset( $b['members'] ) ? (array) $b['members'] : array() );
		return self::out( self::team_list() );
	}

	private static function set_team_members( $team_id, $members ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'team_members' );
		$wpdb->delete( $table, array( 'team_id' => $team_id ) );
		foreach ( $members as $m ) {
			$uid = absint( is_array( $m ) ? ( isset( $m['user_id'] ) ? $m['user_id'] : 0 ) : $m );
			if ( $uid && get_userdata( $uid ) ) {
				$wpdb->replace( $table, array( 'team_id' => $team_id, 'user_id' => $uid, 'is_manager' => is_array( $m ) && ! empty( $m['is_manager'] ) ? 1 : 0, 'added_at' => NDWD_Helpers::now_mysql() ) );
			}
		}
		NDWD_Permissions::flush();
		NDWD_Clients::flush();
	}

	public static function save_project( WP_REST_Request $r ) {
		global $wpdb;
		$b   = self::body( $r );
		$id  = isset( $r['id'] ) ? (int) $r['id'] : 0;
		$row = array();
		foreach ( array( 'name' => 160, 'client' => 160 ) as $k => $len ) {
			if ( isset( $b[ $k ] ) ) {
				$row[ $k ] = mb_substr( sanitize_text_field( $b[ $k ] ), 0, $len );
			}
		}
		if ( isset( $b['description'] ) ) {
			$row['description'] = sanitize_textarea_field( $b['description'] );
		}
		if ( isset( $b['is_active'] ) ) {
			$row['is_active'] = $b['is_active'] ? 1 : 0;
		}
		if ( array_key_exists( 'client_id', $b ) ) {
			$cid    = absint( $b['client_id'] );
			$client = $cid ? NDWD_Clients::get( $cid ) : null;
			if ( $cid && ! $client ) {
				return new WP_Error( 'ndwd_invalid', 'Unknown client.', array( 'status' => 400 ) );
			}
			$row['client_id'] = $client ? $cid : null;
			$row['client']    = $client ? $client->name : '';
		}
		if ( isset( $b['status'] ) ) {
			$row['is_active']   = 'active' === $b['status'] ? 1 : 0;
			$row['is_archived'] = 'archived' === $b['status'] ? 1 : 0;
		}
		if ( array_key_exists( 'parent_id', $b ) ) {
			$parent = NDWD_Workflow::check_parent( absint( $b['parent_id'] ), $id, array_key_exists( 'client_id', $row ) ? $row['client_id'] : null );
			if ( is_wp_error( $parent ) ) {
				return $parent;
			}
			$row['parent_id'] = $parent ? $parent->id : null;
			if ( $parent && ! array_key_exists( 'client_id', $row ) ) {
				$row['client_id'] = $parent->client_id;
				$row['client']    = $parent->client;
			}
		}
		$table  = NDWD_Helpers::table( 'projects' );
		$is_new = ! $id;
		$old_client = $id ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT client_id FROM $table WHERE id = %d", $id ) ) : 0; // phpcs:ignore
		if ( $id && array_key_exists( 'client_id', $row ) ) {
			$ok = NDWD_WS_Hub::check_client_change( $id, (int) $row['client_id'] );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}
		if ( $id ) {
			$wpdb->update( $table, $row, array( 'id' => $id ) );
			if ( array_key_exists( 'client_id', $row ) ) {
				NDWD_WS_Hub::after_client_change( $id, $old_client, self::uid() );
			}
		} else {
			if ( empty( $row['name'] ) ) {
				return new WP_Error( 'ndwd_invalid', 'Project name is required.', array( 'status' => 400 ) );
			}
			$row['created_at'] = NDWD_Helpers::now_mysql();
			$wpdb->insert( $table, $row );
			$id = (int) $wpdb->insert_id;
		}
		do_action( 'ndwd_project_saved', $id, $is_new );
		if ( ! empty( $row['client_id'] ) ) {
			NDWD_Installer::backfill_task_clients( $id );
		}
		if ( ! empty( $b['package'] ) && is_array( $b['package'] ) && ! empty( $row['client_id'] ) ) {
			// Optional package assigned while creating the project: same rules as assigning it later.
			if ( ! NDWD_Permissions::can( 'ndwd_manage_accounts' ) ) {
				return new WP_Error( 'ndwd_forbidden', 'Only Accounts users can assign packages. The project was saved without one.', array( 'status' => 403 ) );
			}
			$assigned = NDWD_Packages::assign( (int) $row['client_id'], array_merge( $b['package'], array( 'project_id' => $id ) ), self::uid() );
			if ( is_wp_error( $assigned ) ) {
				return $assigned;
			}
		}
		return self::out( self::project_list() );
	}

	public static function save_tag( WP_REST_Request $r ) {
		global $wpdb;
		$b     = self::body( $r );
		$id    = isset( $r['id'] ) ? (int) $r['id'] : 0;
		$name  = mb_substr( sanitize_text_field( isset( $b['name'] ) ? $b['name'] : '' ), 0, 80 );
		$color = isset( $b['color'] ) && sanitize_hex_color( $b['color'] ) ? sanitize_hex_color( $b['color'] ) : '#60a5fa';
		$table = NDWD_Helpers::table( 'tags' );
		if ( '' === $name ) {
			return new WP_Error( 'ndwd_invalid', 'Tag name is required.', array( 'status' => 400 ) );
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE name = %s AND id <> %d", $name, $id ) );
		if ( $exists ) {
			return new WP_Error( 'ndwd_invalid', 'A tag with this name already exists.', array( 'status' => 400 ) );
		}
		if ( $id ) {
			$wpdb->update( $table, array( 'name' => $name, 'color' => $color ), array( 'id' => $id ) );
		} else {
			$wpdb->insert( $table, array( 'name' => $name, 'color' => $color ) );
		}
		return self::out( $wpdb->get_results( "SELECT id, name, color FROM $table ORDER BY name", ARRAY_A ) ); // phpcs:ignore
	}

	/**
	 * Safe delete for teams, projects and clients. Anything other records depend on
	 * (tasks, invoices, payments, expenses, transactions) blocks the delete, so history
	 * and reports never lose their links; those records can still be made inactive.
	 * With ?dry=1 only reports what is linked, for the confirmation dialog.
	 */
	private static function delete_record( $kind, WP_REST_Request $r ) {
		global $wpdb;
		$id  = (int) $r['id'];
		$t   = function ( $n ) {
			return NDWD_Helpers::table( $n );
		};
		$def = array(
			'team'    => array( 'teams', array( 'tasks' => array( 'tasks', 'team_id' ), 'task team assignments' => array( 'task_teams', 'team_id' ), 'task handoffs' => array( 'task_handoffs', 'to_team_id' ) ), array( array( 'team_members', 'team_id' ), array( 'client_teams', 'team_id' ) ) ),
			'project' => array( 'projects', array( 'tasks' => array( 'tasks', 'project_id' ), 'expenses' => array( 'expenses', 'project_id' ), 'sub-projects' => array( 'projects', 'parent_id' ), 'packages' => array( 'client_packages', 'project_id' ) ), array() ),
			'client'  => array( 'clients', array( 'Workspace connections' => array( 'ws_connections', 'client_id' ), 'enquiries' => array( 'enquiries', 'client_id' ), 'tasks' => array( 'tasks', 'client_id' ), 'projects' => array( 'projects', 'client_id' ), 'invoices' => array( 'invoices', 'client_id' ), 'expenses' => array( 'expenses', 'client_id' ), 'transactions' => array( 'fin_entries', 'client_id' ) ), array( array( 'client_teams', 'client_id' ), array( 'client_members', 'client_id' ) ) ),
		);
		list( $table, $deps, $cleanup ) = $def[ $kind ];
		$name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . $t( $table ) . ' WHERE id = %d', $id ) );
		if ( null === $name ) {
			return new WP_Error( 'ndwd_not_found', ucfirst( $kind ) . ' not found.', array( 'status' => 404 ) );
		}
		$links = array();
		foreach ( $deps as $label => $d ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( $d[0] ) . ' WHERE ' . $d[1] . ' = %d', $id ) );
			if ( $n ) {
				$links[ $label ] = $n;
			}
		}
		$notes = array();
		if ( 'team' === $kind ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( 'team_members' ) . ' WHERE team_id = %d', $id ) );
			if ( $n ) {
				$notes[] = $n . ' member(s) will be removed from this team (their accounts are not affected).';
			}
		}
		if ( $r->get_param( 'dry' ) ) {
			return self::out( array( 'name' => $name, 'links' => $links, 'blocked' => (bool) $links, 'notes' => $notes ) );
		}
		if ( $links ) {
			$parts = array();
			foreach ( $links as $k => $n ) {
				$parts[] = $n . ' ' . $k;
			}
			return new WP_Error( 'ndwd_in_use', '"' . $name . '" is linked to ' . implode( ', ', $parts ) . ', so it is kept for history and reports. Mark it inactive instead.', array( 'status' => 409 ) );
		}
		foreach ( $cleanup as $c ) {
			$wpdb->delete( $t( $c[0] ), array( $c[1] => $id ) );
		}
		$wpdb->delete( $t( $table ), array( 'id' => $id ) );
		NDWD_Permissions::flush();
		NDWD_Clients::flush();
		NDWD_Activity::log( self::uid(), 0, $kind . '_deleted', array( 'id' => $id, 'name' => $name ) );
		return self::out( array( 'ok' => true ) );
	}

	public static function delete_team( WP_REST_Request $r ) {
		return self::delete_record( 'team', $r );
	}

	public static function delete_project( WP_REST_Request $r ) {
		return self::delete_record( 'project', $r );
	}

	public static function delete_client( WP_REST_Request $r ) {
		return self::delete_record( 'client', $r );
	}

	public static function delete_tag( WP_REST_Request $r ) {
		global $wpdb;
		$wpdb->delete( NDWD_Helpers::table( 'task_tags' ), array( 'tag_id' => (int) $r['id'] ) );
		$wpdb->delete( NDWD_Helpers::table( 'tags' ), array( 'id' => (int) $r['id'] ) );
		return self::out( $wpdb->get_results( 'SELECT id, name, color FROM ' . NDWD_Helpers::table( 'tags' ) . ' ORDER BY name', ARRAY_A ) ); // phpcs:ignore
	}

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function get_settings() {
		$s = NDWD_Notifications::settings();
		unset( $s['smtp_pass'] );
		$s['smtp_password_set']      = '' !== NDWD_Notifications::smtp_password();
		$s['smtp_password_constant'] = defined( 'NDWD_SMTP_PASSWORD' );
		$last                        = get_option( 'ndwd_last_cron' );
		$next                        = wp_next_scheduled( NDWD_Cron::HOOK );
		return self::out( array(
			'settings'     => $s,
			'work_types'   => NDWD_Helpers::work_types(),
			'priorities'   => NDWD_Helpers::priorities(),
			'email_status' => NDWD_Notifications::email_status(),
			'email_log'    => NDWD_Notifications::email_log( 50 ),
			'push'         => NDWD_Push::status(),
			'jobs'         => NDWD_Jobs::status(),
			'admin_email'  => get_option( 'admin_email' ),
			'retention'    => NDWD_Retention::preview(),
			'cron'         => array(
				'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
				'last_run'         => $last ? $last : null,
				'next_run'         => $next ? wp_date( 'Y-m-d H:i:s', $next, NDWD_Helpers::tz() ) : null,
				'wp_cron_url'      => site_url( 'wp-cron.php?doing_wp_cron' ),
				'endpoint'         => add_query_arg( 'key', rawurlencode( get_option( 'ndwd_cron_key' ) ), rest_url( self::NS . '/cron' ) ),
				'php_cli'          => NDWD_Cron::cli_command(),
				'health'           => NDWD_Cron::health(),
			),
			'environment'  => array(
				'zip'      => class_exists( 'ZipArchive' ),
				'iconv'    => function_exists( 'iconv' ),
				'php'      => PHP_VERSION,
				'writable' => wp_is_writable( NDWD_Reports::base_dir() ),
			),
		) );
	}

	public static function save_settings( WP_REST_Request $r ) {
		$b = self::body( $r );
		$s = NDWD_Notifications::settings();
		foreach ( array( 'email_enabled', 'delete_on_uninstall' ) as $k ) {
			if ( isset( $b[ $k ] ) ) {
				$s[ $k ] = $b[ $k ] ? 1 : 0;
			}
		}
		if ( isset( $b['report_email'] ) ) {
			$list = array_filter( array_map( 'trim', explode( ',', (string) $b['report_email'] ) ) );
			foreach ( $list as $e ) {
				if ( ! is_email( $e ) ) {
					return new WP_Error( 'ndwd_invalid', 'Monthly report email: "' . $e . '" is not a valid email address.', array( 'status' => 400 ) );
				}
			}
			$s['report_email'] = implode( ', ', array_map( 'sanitize_email', $list ) );
		}
		if ( isset( $b['retention_months'] ) ) {
			$s['retention_months'] = max( 6, min( 36, absint( $b['retention_months'] ) ) );
		}
		foreach ( array( 'email_from_name', 'smtp_host', 'smtp_user' ) as $k ) {
			if ( isset( $b[ $k ] ) ) {
				$s[ $k ] = sanitize_text_field( $b[ $k ] );
			}
		}
		if ( isset( $b['email_from'] ) ) {
			$s['email_from'] = sanitize_email( $b['email_from'] );
		}
		if ( isset( $b['smtp_port'] ) ) {
			$s['smtp_port'] = max( 1, min( 65535, absint( $b['smtp_port'] ) ) );
		}
		if ( isset( $b['smtp_secure'] ) && in_array( $b['smtp_secure'], array( '', 'tls', 'ssl' ), true ) ) {
			$s['smtp_secure'] = $b['smtp_secure'];
		}
		if ( isset( $b['daily_summary_hour'] ) ) {
			$s['daily_summary_hour'] = max( 0, min( 23, absint( $b['daily_summary_hour'] ) ) );
		}
		if ( isset( $b['scheduler_health_minutes'] ) ) {
			$s['scheduler_health_minutes'] = max( 2, min( 180, absint( $b['scheduler_health_minutes'] ) ) );
		}
		if ( isset( $b['email_types'] ) && is_array( $b['email_types'] ) ) {
			foreach ( array( 'assigned', 'reassigned', 'reminder', 'updated', 'leave', 'daily_summary', 'monthly_report' ) as $k ) {
				$s['email_types'][ $k ] = ! empty( $b['email_types'][ $k ] ) ? 1 : 0;
			}
		}
		update_option( 'ndwd_settings', $s, false );
		if ( isset( $b['smtp_password'] ) && '' !== $b['smtp_password'] && ! defined( 'NDWD_SMTP_PASSWORD' ) ) {
			update_option( 'ndwd_smtp_pass', (string) $b['smtp_password'], false );
		}
		if ( ! empty( $b['clear_smtp_password'] ) ) {
			delete_option( 'ndwd_smtp_pass' );
		}
		if ( isset( $b['work_types'] ) && is_array( $b['work_types'] ) ) {
			$types = array();
			foreach ( $b['work_types'] as $key => $label ) {
				$key   = sanitize_key( is_array( $label ) ? $label['key'] : $key );
				$label = sanitize_text_field( is_array( $label ) ? $label['label'] : $label );
				if ( $key && $label ) {
					$types[ $key ] = $label;
				}
			}
			if ( $types ) {
				update_option( 'ndwd_work_types', $types );
			}
		}
		if ( isset( $b['priorities'] ) && is_array( $b['priorities'] ) ) {
			$labels = array();
			foreach ( NDWD_Helpers::PRIORITY_KEYS as $k ) {
				if ( ! empty( $b['priorities'][ $k ] ) ) {
					$labels[ $k ] = sanitize_text_field( $b['priorities'][ $k ] );
				}
			}
			update_option( 'ndwd_priority_labels', $labels );
		}
		if ( ! empty( $b['regenerate_cron_key'] ) ) {
			update_option( 'ndwd_cron_key', wp_generate_password( 32, false ) );
		}
		return self::get_settings();
	}

	public static function test_email() {
		list( $ok, $msg ) = NDWD_Notifications::send_test( self::uid() );
		return self::out( array( 'ok' => $ok, 'message' => $msg ) );
	}

	public static function run_cron_now() {
		return self::out( array( 'log' => NDWD_Cron::run() ) );
	}

	public static function cron() {
		ignore_user_abort( true );
		return self::out( array( 'ok' => true, 'log' => NDWD_Cron::run( 'http', true ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Archives & import
	 * ------------------------------------------------------------------ */

	public static function archives() {
		return self::out( array(
			'reports'  => NDWD_Reports::all(),
			'previous' => NDWD_Reports::previous_month(),
			'can_generate' => NDWD_Permissions::can( 'ndwd_manage_reports' ),
		) );
	}

	/** Streams one archive file to a signed-in user who may download it. */
	public static function archive_file( WP_REST_Request $r ) {
		return NDWD_Reports::stream_file( (int) $r['id'], (int) $r['file'] );
	}

	/** Admin confirms they have saved this month's reports (lets its data be cleaned up later). */
	public static function archive_confirm( WP_REST_Request $r ) {
		return self::out( NDWD_Reports::confirm( (int) $r['id'], self::uid() ) );
	}

	public static function archive_email( WP_REST_Request $r ) {
		return self::out( NDWD_Reports::email_report( (int) $r['id'], true ) );
	}

	public static function export_report( WP_REST_Request $r ) {
		return NDWD_Exports::export( self::body( $r ), self::uid() );
	}

	public static function jobs() {
		return self::out( NDWD_Jobs::status() );
	}

	public static function generate_archive( WP_REST_Request $r ) {
		$b   = self::body( $r );
		$res = NDWD_Reports::generate( isset( $b['month'] ) ? sanitize_text_field( $b['month'] ) : NDWD_Reports::previous_month(), 'manual', self::uid() );
		return self::out( $res );
	}

	/* ------------------------------------------------------------------ *
	 * Clients & leave
	 * ------------------------------------------------------------------ */

	public static function clients() {
		return self::out( NDWD_Clients::all( self::uid() ) );
	}

	public static function get_client( WP_REST_Request $r ) {
		return self::out( NDWD_Clients::one( (int) $r['id'], self::uid() ) );
	}

	public static function save_client( WP_REST_Request $r ) {
		$res = NDWD_Clients::save( self::body( $r ), self::uid(), isset( $r['id'] ) ? (int) $r['id'] : 0 );
		return is_wp_error( $res ) ? $res : self::out( array( 'client' => $res, 'clients' => NDWD_Clients::all( self::uid() ) ) );
	}

	public static function leaves( WP_REST_Request $r ) {
		$uid    = self::uid();
		$status = sanitize_key( (string) $r->get_param( 'status' ) );
		return self::out( array(
			'mine'       => NDWD_Leaves::query( $uid, 'mine' ),
			'review'     => NDWD_Leaves::query( $uid, 'review', $status ),
			'can_review' => NDWD_Permissions::has_team_perm( 'approve_leave' ),
			'types'      => NDWD_Leaves::TYPES,
			'today'      => NDWD_Helpers::today(),
		) );
	}

	public static function apply_leave( WP_REST_Request $r ) {
		return self::out( NDWD_Leaves::apply( self::body( $r ), self::uid() ) );
	}

	public static function decide_leave( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Leaves::decide( (int) $r['id'], isset( $b['decision'] ) ? sanitize_key( $b['decision'] ) : '', isset( $b['note'] ) ? $b['note'] : '', self::uid() ) );
	}

	public static function cancel_leave( WP_REST_Request $r ) {
		return self::out( NDWD_Leaves::cancel( (int) $r['id'], self::uid() ) );
	}

	public static function leave_tasks( WP_REST_Request $r ) {
		return self::out( NDWD_Leaves::tasks_for( (int) $r['id'], self::uid() ) );
	}

	public static function leave_reassign( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Leaves::reassign( (int) $r['id'], isset( $b['task_ids'] ) ? $b['task_ids'] : array(), isset( $b['to_user'] ) ? $b['to_user'] : 0, self::uid() ) );
	}

	public static function import_preview( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Import::preview(
			isset( $b['csv'] ) ? (string) $b['csv'] : '',
			isset( $b['mapping'] ) && is_array( $b['mapping'] ) ? $b['mapping'] : null,
			isset( $b['members'] ) && is_array( $b['members'] ) ? array_map( 'absint', $b['members'] ) : array(),
			isset( $b['options'] ) && is_array( $b['options'] ) ? $b['options'] : array()
		) );
	}

	public static function import_commit( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Import::commit(
			isset( $b['csv'] ) ? (string) $b['csv'] : '',
			isset( $b['mapping'] ) && is_array( $b['mapping'] ) ? $b['mapping'] : null,
			isset( $b['members'] ) && is_array( $b['members'] ) ? array_map( 'absint', $b['members'] ) : array(),
			isset( $b['options'] ) && is_array( $b['options'] ) ? $b['options'] : array(),
			self::uid()
		) );
	}
}
