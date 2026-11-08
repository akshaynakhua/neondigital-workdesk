<?php
/**
 * Authorisation rules. Every REST handler goes through these checks.
 *
 * Two global roles exist: Admin (every capability) and Member (basic access).
 * Everything a non-admin may do with other people's work is decided per team:
 * each team stores which permissions its managers and its ordinary members have,
 * and a person's rights in a team follow their position in that team. Someone can
 * therefore manage one team and be an ordinary member of another.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Permissions {

	/** Team-scoped permissions with labels (shown in Create / Edit Team). */
	const TEAM_PERMS = array(
		'view_team_tasks'      => 'See all tasks of this team',
		'assign_tasks'         => 'Create tasks for and assign them to team members',
		'edit_team_tasks'      => 'Edit any task of this team',
		'reassign_tasks'       => 'Manage / reassign other members\' tasks in this team',
		'archive_tasks'        => 'Archive tasks of this team',
		'view_reports'         => 'View team attendance, productivity and reports',
		'approve_leave'        => 'Approve or reject leave of team members and reassign their work',
		'view_clients'         => 'See the clients assigned to this team',
		'view_client_contacts' => 'See contact details (phone, email) of those clients',
	);

	const DEFAULT_TEAM_PERMS = array(
		'manager' => array( 'view_team_tasks' => true, 'assign_tasks' => true, 'edit_team_tasks' => true, 'reassign_tasks' => true, 'archive_tasks' => false, 'view_reports' => true, 'approve_leave' => true, 'view_clients' => true, 'view_client_contacts' => true ),
		'member'  => array( 'view_team_tasks' => true, 'assign_tasks' => true, 'edit_team_tasks' => false, 'reassign_tasks' => false, 'archive_tasks' => false, 'view_reports' => false, 'approve_leave' => false, 'view_clients' => true, 'view_client_contacts' => false ),
	);

	private static $cache = array();

	/** Clears per-request caches (after team or membership changes, and in tests). */
	public static function flush() {
		self::$cache = array();
	}

	public static function can( $cap, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id || ! self::is_active( $user_id ) ) {
			return false;
		}
		return user_can( $user_id, $cap );
	}

	public static function is_active( $user_id ) {
		return ! get_user_meta( $user_id, 'ndwd_inactive', true );
	}

	/** Company-wide visibility (Admins and anyone granted "View all"). */
	public static function is_admin( $user_id = 0 ) {
		return self::can( 'ndwd_view_all', $user_id );
	}

	/** Normalises a team's stored permissions, falling back to the defaults. */
	public static function team_permissions( $raw ) {
		$stored = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		$out    = array();
		foreach ( self::DEFAULT_TEAM_PERMS as $level => $defaults ) {
			foreach ( $defaults as $perm => $def ) {
				$out[ $level ][ $perm ] = isset( $stored[ $level ][ $perm ] ) ? (bool) $stored[ $level ][ $perm ] : $def;
			}
			// Teams saved before 1.11 had one "Edit and reassign" switch: keep what it allowed.
			if ( ! isset( $stored[ $level ]['reassign_tasks'] ) && isset( $stored[ $level ]['edit_team_tasks'] ) ) {
				$out[ $level ]['reassign_tasks'] = (bool) $stored[ $level ]['edit_team_tasks'];
			}
		}
		return $out;
	}

	/** [team_id => ['manager' => bool, 'perms' => [...]]] for the user's active teams. */
	public static function memberships( $user_id ) {
		global $wpdb;
		$key = 'm' . $user_id;
		if ( ! isset( self::$cache[ $key ] ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				'SELECT tm.team_id, tm.is_manager, t.permissions FROM ' . NDWD_Helpers::table( 'team_members' ) . ' tm JOIN ' . NDWD_Helpers::table( 'teams' ) . ' t ON t.id = tm.team_id WHERE tm.user_id = %d AND t.is_active = 1',
				$user_id
			) );
			$out = array();
			foreach ( $rows as $r ) {
				$perms                       = self::team_permissions( $r->permissions );
				$out[ (int) $r->team_id ] = array( 'manager' => (bool) $r->is_manager, 'perms' => $perms[ $r->is_manager ? 'manager' : 'member' ] );
			}
			self::$cache[ $key ] = $out;
		}
		return self::$cache[ $key ];
	}

	/** Whether the user has $perm in team $team_id. Admins have every permission everywhere. */
	public static function team_can( $team_id, $perm, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( self::is_admin( $user_id ) ) {
			return true;
		}
		if ( ! self::can( 'ndwd_access', $user_id ) ) {
			return false;
		}
		$m = self::memberships( $user_id );
		return isset( $m[ (int) $team_id ] ) && ! empty( $m[ (int) $team_id ]['perms'][ $perm ] );
	}

	/** Team ids where the user has $perm, or null for admins (= every team). */
	public static function teams_with( $perm, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( self::is_admin( $user_id ) ) {
			return null;
		}
		if ( ! self::can( 'ndwd_access', $user_id ) ) {
			return array();
		}
		$out = array();
		foreach ( self::memberships( $user_id ) as $tid => $m ) {
			if ( ! empty( $m['perms'][ $perm ] ) ) {
				$out[] = $tid;
			}
		}
		return $out;
	}

	/** True when the user has $perm in at least one team (or is an admin). */
	public static function has_team_perm( $perm, $user_id = 0 ) {
		$t = self::teams_with( $perm, $user_id );
		return null === $t || (bool) $t;
	}

	/** Team ids the user belongs to (any position). */
	public static function user_teams( $user_id ) {
		global $wpdb;
		$key = 't' . $user_id;
		if ( ! isset( self::$cache[ $key ] ) ) {
			self::$cache[ $key ] = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
				'SELECT team_id FROM ' . NDWD_Helpers::table( 'team_members' ) . ' WHERE user_id = %d', $user_id
			) ) );
		}
		return self::$cache[ $key ];
	}

	/** Team ids where the user is a team manager. */
	public static function managed_teams( $user_id ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			'SELECT team_id FROM ' . NDWD_Helpers::table( 'team_members' ) . ' WHERE user_id = %d AND is_manager = 1', $user_id
		) ) );
	}

	/** Members of the given teams. */
	public static function members_of( $team_ids ) {
		global $wpdb;
		if ( ! $team_ids ) {
			return array();
		}
		return array_values( array_unique( array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT user_id FROM ' . NDWD_Helpers::table( 'team_members' ) . ' WHERE team_id IN (' . NDWD_Helpers::in_placeholders( $team_ids ) . ')',
			$team_ids
		) ) ) ) );
	}

	/** Users who share a team with $user_id (including themself). */
	public static function teammates( $user_id ) {
		$ids   = self::members_of( self::user_teams( $user_id ) );
		$ids[] = (int) $user_id;
		return array_values( array_unique( $ids ) );
	}

	/** Teams currently working on a task (its lead team plus any others it is assigned to). */
	public static function task_teams( $task ) {
		$ids = isset( $task->team_ids ) ? $task->team_ids : array();
		if ( ! empty( $task->team_id ) && ! in_array( (int) $task->team_id, $ids, true ) ) {
			$ids[] = (int) $task->team_id;
		}
		return $ids;
	}

	/** True when the user has $perm in any of $team_ids. */
	private static function any_team_can( $team_ids, $perm, $user_id ) {
		foreach ( $team_ids as $tid ) {
			if ( self::team_can( $tid, $perm, $user_id ) ) {
				return true;
			}
		}
		return false;
	}

	public static function can_view_task( $task, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $task || ! self::can( 'ndwd_access', $user_id ) ) {
			return false;
		}
		if ( self::is_admin( $user_id ) ) {
			return true;
		}
		if ( (int) $task->created_by === (int) $user_id || in_array( (int) $user_id, $task->assignee_ids, true ) ) {
			return true;
		}
		// Unassigned work of a team: every member of that team sees it in the shared pool.
		if ( empty( $task->assignee_ids ) && array_intersect( self::task_teams( $task ), self::user_teams( $user_id ) ) ) {
			return true;
		}
		// Teams that handed the task on keep seeing it (history of their part).
		$past = isset( $task->past_team_ids ) ? $task->past_team_ids : array();
		return self::any_team_can( array_merge( self::task_teams( $task ), $past ), 'view_team_tasks', $user_id );
	}

	/** Full edit: title, assignees, team, dates, priority, tags, links. */
	public static function can_edit_task( $task, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! self::can_view_task( $task, $user_id ) ) {
			return false;
		}
		if ( self::can( 'ndwd_edit_all_tasks', $user_id ) || (int) $task->created_by === (int) $user_id ) {
			return true;
		}
		if ( self::any_team_can( self::task_teams( $task ), 'edit_team_tasks', $user_id ) ) {
			return true;
		}
		return in_array( (int) $user_id, $task->assignee_ids, true );
	}

	/**
	 * "Manage / reassign other members' tasks": change who is responsible for someone else's
	 * task. Being able to see or edit a task is not enough; it is granted explicitly (the
	 * capability, or the team permission in one of the task's teams).
	 */
	public static function can_reassign_task( $task, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $task || ! self::can( 'ndwd_access', $user_id ) ) {
			return false;
		}
		if ( self::is_admin( $user_id ) || self::can( 'ndwd_edit_all_tasks', $user_id ) || self::can( 'ndwd_reassign_tasks', $user_id ) ) {
			return true;
		}
		return self::any_team_can( self::task_teams( $task ), 'reassign_tasks', $user_id );
	}

	/**
	 * Teams of this task the user may hand the task on from. Admins (and holders of
	 * "Edit and reassign any task") may hand off from any of the task's teams; otherwise
	 * the user must be a team manager of that team with "Edit and reassign" there.
	 */
	public static function handoff_teams( $task, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $task || ! empty( $task->is_archived ) || ! self::can_view_task( $task, $user_id ) ) {
			return array();
		}
		$teams = self::task_teams( $task );
		if ( self::is_admin( $user_id ) || self::can( 'ndwd_edit_all_tasks', $user_id ) ) {
			return $teams;
		}
		$m = self::memberships( $user_id );
		return array_values( array_filter( $teams, function ( $tid ) use ( $m ) {
			return isset( $m[ $tid ] ) && $m[ $tid ]['manager'] && ! empty( $m[ $tid ]['perms']['edit_team_tasks'] );
		} ) );
	}

	/** Archiving: Admins, people granted "Archive any task", or the team's permission. */
	public static function can_archive_task( $task, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! self::can_view_task( $task, $user_id ) ) {
			return false;
		}
		if ( self::can( 'ndwd_archive_tasks', $user_id ) ) {
			return true;
		}
		return self::any_team_can( self::task_teams( $task ), 'archive_tasks', $user_id );
	}

	/** Status changes, remarks and follow-ups: anyone who can see the task and is involved, or an editor. */
	public static function can_work_on_task( $task, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( self::can_edit_task( $task, $user_id ) ) {
			return true;
		}
		// Team task with no individual assignee: any member of its teams may work on it.
		return self::can_view_task( $task, $user_id ) && empty( $task->assignee_ids )
			&& (bool) array_intersect( self::task_teams( $task ), self::user_teams( $user_id ) );
	}

	/**
	 * Whether the user may assign work to the given users/teams. Assigning to yourself is
	 * always allowed; assigning to others needs "assign tasks" in a team you share with them.
	 * $team_ids may be a single id (older callers) or a list.
	 */
	public static function can_assign( $assignees, $team_ids, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( self::can( 'ndwd_edit_all_tasks', $user_id ) || self::is_admin( $user_id ) ) {
			return true;
		}
		$team_ids     = array_filter( array_map( 'intval', (array) $team_ids ) );
		$assign_teams = self::teams_with( 'assign_tasks', $user_id );
		$allowed      = self::members_of( $assign_teams );
		foreach ( $assignees as $a ) {
			if ( (int) $a !== (int) $user_id && ! in_array( (int) $a, $allowed, true ) ) {
				return false;
			}
		}
		foreach ( $team_ids as $team_id ) {
			if ( ! in_array( $team_id, self::user_teams( $user_id ), true ) ) {
				return false;
			}
			// A team task without individual assignees reaches every team member.
			if ( ! $assignees && ! in_array( $team_id, $assign_teams, true ) ) {
				return false;
			}
		}
		return true;
	}

	/** Teams whose tasks the user may see on the team dashboard. null = all. */
	public static function visible_team_ids( $user_id = 0 ) {
		return self::teams_with( 'view_team_tasks', $user_id );
	}

	/** Members whose attendance/productivity the user may see individually. */
	public static function can_view_member_reports( $target_id, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( (int) $target_id === (int) $user_id ) {
			return true;
		}
		$teams = self::teams_with( 'view_reports', $user_id );
		if ( null === $teams ) {
			return true;
		}
		return (bool) array_intersect( $teams, self::user_teams( $target_id ) );
	}

	/** Whether $user_id may approve leave for $member_id (never their own). */
	public static function can_approve_leave_for( $member_id, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( (int) $member_id === (int) $user_id || ! self::can( 'ndwd_access', $user_id ) ) {
			return false;
		}
		$teams = self::teams_with( 'approve_leave', $user_id );
		return null === $teams || (bool) array_intersect( $teams, self::user_teams( $member_id ) );
	}

	/**
	 * Capability flags for the UI. Global capabilities plus "derived" ones that say
	 * whether the user has a team permission in at least one team. The server always
	 * re-checks the specific team on every request.
	 */
	public static function ui_caps( $user_id ) {
		$caps = array();
		foreach ( array_keys( NDWD_Installer::all_caps() ) as $cap ) {
			$caps[ $cap ] = self::can( $cap, $user_id );
		}
		$caps['ndwd_view_team']     = self::has_team_perm( 'view_team_tasks', $user_id );
		$caps['ndwd_view_reports']  = self::has_team_perm( 'view_reports', $user_id );
		$caps['ndwd_approve_leave'] = self::has_team_perm( 'approve_leave', $user_id );
		$caps['ndwd_assign_others'] = self::is_admin( $user_id ) || self::can( 'ndwd_edit_all_tasks', $user_id ) || self::has_team_perm( 'assign_tasks', $user_id );
		return $caps;
	}

	public static function role_of( $user_id ) {
		$u = get_userdata( $user_id );
		if ( ! $u ) {
			return '';
		}
		if ( in_array( 'administrator', $u->roles, true ) ) {
			return 'administrator';
		}
		foreach ( array( 'ndwd_admin', 'ndwd_member' ) as $r ) {
			if ( in_array( $r, $u->roles, true ) ) {
				return $r;
			}
		}
		// A retired "Manager" role that has not been migrated yet behaves as Member.
		return in_array( 'ndwd_manager', $u->roles, true ) ? 'ndwd_member' : ( $u->roles ? reset( $u->roles ) : '' );
	}
}
