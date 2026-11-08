<?php
/**
 * Projects dashboard and the project page (Phase 2).
 *
 * Workspace and package assignment happen on the project, not on the client. A project belongs to
 * one client (projects.client_id) and to one Workspace (its visibility link, or the client's main
 * Workspace when it has none). A sub-project follows its parent unless it is given its own Workspace.
 *
 * Every write here goes through the functions that already existed for those rules:
 *   NDWD_WS_Projects::link_project / assign / update_assignment / remove_assignment / migrate,
 *   NDWD_WS::invite, NDWD_Packages::assign. This class only joins them up for one project.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_Hub {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	private static function route( $path, $methods, $cb, $perm ) {
		register_rest_route( NDWD_REST::NS, $path, array(
			'methods'             => $methods,
			'callback'            => array( __CLASS__, $cb ),
			'permission_callback' => $perm,
		) );
	}

	public static function can_view() {
		return is_user_logged_in() && ( NDWD_Permissions::can( 'ndwd_manage_projects' ) || NDWD_Permissions::can( 'ndwd_manage_workspace' ) );
	}

	public static function routes() {
		$view = array( __CLASS__, 'can_view' );
		$ws   = function () {
			return is_user_logged_in() && NDWD_Permissions::can( 'ndwd_manage_workspace' );
		};
		$proj = function () {
			return is_user_logged_in() && NDWD_Permissions::can( 'ndwd_manage_projects' );
		};
		self::route( '/project-hub', 'GET', 'rest_dashboard', $view );
		self::route( '/project-hub/(?P<id>\d+)', 'GET', 'rest_project', $view );
		self::route( '/project-hub/(?P<id>\d+)/client', 'POST', 'rest_set_client', $proj );
		self::route( '/project-hub/(?P<id>\d+)/new-workspace', 'POST', 'rest_new_workspace', $ws );
		self::route( '/clients/(?P<client_id>\d+)/assign-project', 'POST', 'rest_assign_to_client', $proj );
	}

	private static function body( WP_REST_Request $r ) {
		$b = json_decode( (string) $r->get_body(), true );
		return is_array( $b ) ? $b : array();
	}

	const HISTORY_LABELS = array(
		'project_workspace_changed'    => 'Workspace assigned',
		'workspace_provisioned'        => 'New Workspace added to the client account',
		'project_added_notified'       => 'Client notified',
		'project_package_assigned'     => 'Package assigned',
		'project_package_updated'      => 'Package changed',
		'project_package_removed'      => 'Package removed',
		'project_client_changed'       => 'Client changed',
		'invitation_created'           => 'Invitation created',
	);

	private static function project( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	private static function status_of( $p ) {
		return (int) $p->is_archived ? 'archived' : ( (int) $p->is_active ? 'active' : 'inactive' );
	}

	/** The explicit Workspace link of a project, if staff chose one. */
	public static function explicit_connection( $project_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT connection_id FROM ' . NDWD_WS::t( 'visibility' ) . " WHERE entity = 'project' AND entity_id = %d ORDER BY created_at DESC LIMIT 1", $project_id ) ); // phpcs:ignore
	}

	private static function conn_brief( $c ) {
		if ( ! $c ) {
			return null;
		}
		$key = NDWD_WS_Manage::status_key( $c );
		return array(
			'connection_id' => (int) $c->id,
			'name'          => '' !== (string) $c->workspace_name ? (string) $c->workspace_name : 'Workspace (invitation not accepted yet)',
			'workspace_uid' => (string) $c->workspace_uid,
			'status'        => $key,
			'status_label'  => NDWD_WS_Manage::STATUS_LABELS[ $key ] ?? $key,
			'active'        => 'active' === $c->status && ! $c->archived_at,
			'linked'        => (bool) $c->linked_at,
			'business_type' => NDWD_WS::business_type( $c ),
			'business_type_label' => NDWD_WS::business_type_label( NDWD_WS::business_type( $c ) ),
		);
	}

	/** Package line for lists: the live plan's name and the project's access state. */
	private static function package_brief( $conn, $project_id ) {
		global $wpdb;
		if ( ! $conn ) {
			return array( 'name' => '', 'state' => 'no_workspace', 'open' => false, 'addons' => 0 );
		}
		$a    = NDWD_WS_Projects::access( $conn, $project_id );
		$adds = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'project_packages' ) . " WHERE project_id = %d AND status = 'active' AND kind = 'addon'", $project_id ) ); // phpcs:ignore
		$name = $a['assignment'] ? $a['assignment']->name : ( $a['migration'] ? $a['migration']->package_name . ' (own subscription)' : '' );
		return array( 'name' => (string) $name, 'state' => $a['state'], 'open' => (bool) $a['open'], 'message' => $a['message'], 'addons' => $adds );
	}

	/** GET /project-hub?q=&client_id=&connection_id=&status=active|inactive|archived|all */
	public static function rest_dashboard( WP_REST_Request $r ) {
		global $wpdb;
		$t      = NDWD_Helpers::table( 'projects' );
		$q      = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		$client = absint( $r->get_param( 'client_id' ) );
		$conn_f = absint( $r->get_param( 'connection_id' ) );
		$status = sanitize_key( (string) $r->get_param( 'status' ) );
		$status = '' === $status ? 'active' : $status;
		$where  = array( 'parent_id IS NULL' );
		$vals   = array();
		if ( '' !== $q ) {
			$where[] = '(name LIKE %s OR client LIKE %s)';
			$vals[]  = '%' . $wpdb->esc_like( $q ) . '%';
			$vals[]  = '%' . $wpdb->esc_like( $q ) . '%';
		}
		if ( $client ) {
			$where[] = 'client_id = %d';
			$vals[]  = $client;
		}
		if ( 'active' === $status ) {
			$where[] = 'is_active = 1 AND is_archived = 0';
		} elseif ( 'inactive' === $status ) {
			$where[] = 'is_active = 0 AND is_archived = 0';
		} elseif ( 'archived' === $status ) {
			$where[] = 'is_archived = 1';
		}
		$sql  = "SELECT * FROM $t WHERE " . implode( ' AND ', $where ) . ' ORDER BY name ASC LIMIT 500';
		$rows = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		$tt   = NDWD_Helpers::table( 'tasks' );
		$out  = array();
		$ws   = NDWD_Permissions::can( 'ndwd_manage_workspace' );
		foreach ( $rows as $p ) {
			$conn = $p->client_id ? NDWD_WS::connection_for_project( (int) $p->id, (int) $p->client_id ) : null;
			if ( $conn_f && ( ! $conn || (int) $conn->id !== $conn_f ) ) {
				continue;
			}
			$cl    = $p->client_id ? NDWD_Clients::get( (int) $p->client_id ) : null;
			$out[] = array(
				'id'         => (int) $p->id,
				'name'       => $p->name,
				'status'     => self::status_of( $p ),
				'client'     => $cl ? array( 'id' => (int) $cl->id, 'name' => $cl->name ) : null,
				'workspace'  => $ws ? self::conn_brief( $conn ) : null,
				'package'    => $ws ? self::package_brief( $conn, (int) $p->id ) : null,
				'sub_count'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE parent_id = %d AND is_archived = 0", $p->id ) ), // phpcs:ignore
				'open_tasks' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tt WHERE is_archived = 0 AND status NOT IN ('completed','rejected') AND ( project_id = %d OR project_id IN (SELECT id FROM $t WHERE parent_id = %d) )", $p->id, $p->id ) ), // phpcs:ignore
			);
		}
		$conns = array();
		if ( $ws ) {
			foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'connections' ) . ' WHERE archived_at IS NULL ORDER BY workspace_name ASC' ) as $c ) { // phpcs:ignore
				$conns[] = self::conn_brief( $c );
			}
		}
		return rest_ensure_response( array( 'items' => $out, 'workspaces' => $conns, 'can_manage_workspace' => $ws ) );
	}

	/** Everything the project page shows. */
	public static function project_payload( $id ) {
		global $wpdb;
		$p = self::project( $id );
		if ( ! $p ) {
			return NDWD_WS::err( 'Project not found.', 404, 'ndwd_not_found' );
		}
		$t      = NDWD_Helpers::table( 'projects' );
		$ws     = NDWD_Permissions::can( 'ndwd_manage_workspace' );
		$cl     = $p->client_id ? NDWD_Clients::get( (int) $p->client_id ) : null;
		$parent = $p->parent_id ? self::project( (int) $p->parent_id ) : null;
		$conn   = $p->client_id ? NDWD_WS::connection_for_project( (int) $p->id, (int) $p->client_id ) : null;
		$explicit = self::explicit_connection( (int) $p->id );
		$subs   = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE parent_id = %d ORDER BY name ASC", $p->id ) ) as $s ) { // phpcs:ignore
			$sc     = $s->client_id ? NDWD_WS::connection_for_project( (int) $s->id, (int) $s->client_id ) : null;
			$own    = NDWD_WS::explicit_connection_id( (int) $s->id );
			$oc     = $own ? NDWD_WS::connection( $own ) : null;
			$subs[] = array( 'id' => (int) $s->id, 'name' => $s->name, 'status' => self::status_of( $s ), 'description' => (string) $s->description, 'package' => $ws ? self::package_brief( $sc, (int) $s->id ) : null, 'own_workspace' => $oc && ( ! $conn || (int) $oc->id !== (int) $conn->id ) ? ( '' !== (string) $oc->workspace_name ? $oc->workspace_name : 'invitation sent' ) : '', 'open_requests' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'requests' ) . " WHERE project_id = %d AND status IN ('submitted','in_review')", $s->id ) ) ); // phpcs:ignore
		}
		$tt    = NDWD_Helpers::table( 'tasks' );
		$tasks = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM $tt WHERE project_id = %d AND is_archived = 0 GROUP BY status", $p->id ) ) as $x ) { // phpcs:ignore
			$tasks[ $x->status ] = (int) $x->n;
		}
		$out = array(
			'project'   => array( 'id' => (int) $p->id, 'name' => $p->name, 'description' => (string) $p->description, 'status' => self::status_of( $p ), 'is_active' => (bool) $p->is_active, 'is_archived' => (bool) $p->is_archived, 'created_at' => $p->created_at, 'parent' => $parent ? array( 'id' => (int) $parent->id, 'name' => $parent->name ) : null ),
			'client'    => $cl ? array( 'id' => (int) $cl->id, 'name' => $cl->name, 'company' => (string) $cl->company, 'email' => (string) $cl->email ) : null,
			'subprojects' => $subs,
			'tasks'     => array( 'by_status' => (object) $tasks, 'total' => array_sum( $tasks ) ),
			'service_packages' => $cl ? array_values( array_filter( NDWD_Packages::for_client( (int) $cl->id ), function ( $a ) use ( $p ) {
				return (int) ( $a['project_id'] ?? 0 ) === (int) $p->id;
			} ) ) : array(),
			'can'       => array( 'manage_workspace' => $ws, 'manage_accounts' => NDWD_Permissions::can( 'ndwd_manage_accounts' ), 'manage_projects' => NDWD_Permissions::can( 'ndwd_manage_projects' ) ),
		);
		// Phase 4: this project's own records only. A main project's page never lists a sub-project's
		// requests, deliverables or files, and a sub-project's page never lists its parent's.
		$reqs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE project_id = %d ORDER BY id DESC LIMIT 100', $p->id ) ); // phpcs:ignore
		$out['requests'] = array_map( function ( $q ) {
			return array( 'id' => (int) $q->id, 'title' => $q->title, 'status' => $q->status, 'status_label' => NDWD_WS_Requests::STATUSES[ $q->status ] ?? $q->status, 'task_id' => $q->task_id ? (int) $q->task_id : null, 'requested_by' => $q->requested_by, 'created_at' => $q->created_at );
		}, $reqs );
		$dels = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'deliverables' ) . ' WHERE project_id = %d ORDER BY id DESC LIMIT 100', $p->id ) ); // phpcs:ignore
		$out['deliverables'] = array_map( function ( $d ) {
			return array_merge( NDWD_WS_Requests::deliverable_array( $d ), array( 'client_visible' => (bool) $d->client_visible ) );
		}, $dels );
		$out['attachments'] = NDWD_WS_Attachments::for_entity( 'project', (int) $p->id, true );
		$out['attachment_rules'] = NDWD_WS_Attachments::rules();
		if ( $ws ) {
			$out['workspace'] = self::conn_brief( $conn );
			$out['workspace_source'] = $explicit ? 'project' : ( $parent && $conn ? 'parent' : 'none' );
			// Only Workspaces this project may join: its own, its parent's, or one holding no main project yet.
			$out['available_workspaces'] = $cl ? array_values( array_map( array( __CLASS__, 'conn_brief' ), array_filter( NDWD_WS::connections_for_client( (int) $cl->id ), function ( $c ) use ( $p ) {
				return ! $c->archived_at && NDWD_WS::connection_fits_project( (int) $c->id, (int) $p->id );
			} ) ) ) : array();
			$out['account_email'] = $cl ? NDWD_WS::account_email( (int) $cl->id ) : '';
			$out['business_types'] = array_map( function ( $k, $v ) {
				return array( 'key' => $k, 'label' => $v );
			}, array_keys( NDWD_WS_BType::TYPES ), array_values( NDWD_WS_BType::TYPES ) );
			$out['assignment_history'] = array_map( function ( $h ) {
				$c = $h->connection_id ? NDWD_WS::connection( (int) $h->connection_id ) : null;
				$d = json_decode( (string) $h->details, true );
				return array( 'action' => $h->action, 'label' => self::HISTORY_LABELS[ $h->action ] ?? $h->action, 'workspace' => $c ? (string) $c->workspace_name : '', 'by' => $h->user_id ? NDWD_Helpers::user_name( (int) $h->user_id ) : ( $h->actor ? $h->actor : 'System' ), 'at' => $h->created_at, 'details' => is_array( $d ) ? $d : array() );
			}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'audit' ) . " WHERE entity = 'project' AND entity_id = %d AND action IN ('" . implode( "','", array_keys( self::HISTORY_LABELS ) ) . "') ORDER BY id DESC LIMIT 50", $p->id ) ) ); // phpcs:ignore
			$plan = null;
			$adds = array();
			if ( $conn ) {
				$a = NDWD_WS_Projects::access( $conn, (int) $p->id );
				foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'project_packages' ) . " WHERE project_id = %d AND status = 'active' ORDER BY kind DESC, id DESC", $p->id ) ) as $x ) { // phpcs:ignore
					$row = NDWD_WS_Projects::assignment_public( $x );
					if ( 'addon' === $x->kind ) {
						$adds[] = $row;
					} elseif ( ! $plan ) {
						$plan = $row;
					}
				}
				$out['access'] = array( 'state' => $a['state'], 'open' => $a['open'], 'message' => $a['message'], 'days_left' => $a['days_left'], 'inherited' => ! empty( $a['inherited'] ), 'inherited_package' => ! empty( $a['inherited'] ) && $a['assignment'] ? (string) $a['assignment']->name : '' );
				$m = $a['migration'];
				$out['migration'] = $m ? array( 'id' => (int) $m->id, 'status' => $m->status, 'package_name' => $m->package_name, 'grace_ends_on' => $m->grace_ends_on, 'period_end' => $m->period_end ) : null;
			}
			$out['package'] = $plan;
			$out['addons']  = $adds;
		}
		return $out;
	}

	public static function rest_project( WP_REST_Request $r ) {
		$res = self::project_payload( (int) $r['id'] );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/**
	 * The one client rule, used by the project form, the project page and the client page.
	 * A project with a live Workspace package or an open migration keeps its client until those
	 * are removed, so no agreement ends up under the wrong client. Otherwise its Workspace link is
	 * cleared (it belonged to the old client) and its sub-projects move with it.
	 *
	 * @return true|WP_Error
	 */
	public static function check_client_change( $project_id, $new_client_id ) {
		global $wpdb;
		$p = self::project( $project_id );
		if ( ! $p || (int) $p->client_id === (int) $new_client_id ) {
			return true;
		}
		$ids = array_merge( array( (int) $p->id ), array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE parent_id = %d', $p->id ) ) ) ); // phpcs:ignore
		$in  = implode( ',', $ids );
		$pk  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'project_packages' ) . " WHERE project_id IN ($in) AND status = 'active'" ); // phpcs:ignore
		$mg  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'migrations' ) . " WHERE project_id IN ($in) AND status IN ('pending','active','expired')" ); // phpcs:ignore
		$sp  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . NDWD_Helpers::table( 'client_packages' ) . " WHERE project_id IN ($in) AND status IN ('active','paused')" ); // phpcs:ignore
		if ( $pk || $mg || $sp ) {
			return NDWD_WS::err( 'This project still has active packages for its current client. End or remove them first, then change the client.', 409, 'ndwd_project_has_packages' );
		}
		return true;
	}

	/** After a client change: the old Workspace link goes, sub-projects follow the parent. */
	public static function after_client_change( $project_id, $old_client_id, $user_id ) {
		global $wpdb;
		$p = self::project( $project_id );
		if ( ! $p || (int) $p->client_id === (int) $old_client_id ) {
			return;
		}
		$t    = NDWD_Helpers::table( 'projects' );
		$subs = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $t WHERE parent_id = %d", $p->id ) ) ); // phpcs:ignore
		foreach ( array_merge( array( (int) $p->id ), $subs ) as $pid ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NDWD_WS::t( 'visibility' ) . " WHERE entity = 'project' AND entity_id = %d", $pid ) ); // phpcs:ignore
		}
		if ( $subs ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET client_id = %d, client = %s WHERE parent_id = %d", (int) $p->client_id, (string) $p->client, $p->id ) ); // phpcs:ignore
		}
		NDWD_WS::audit( 'project_client_changed', array( 'entity' => 'project', 'entity_id' => (int) $p->id, 'details' => array( 'from' => (int) $old_client_id ?: null, 'to' => $p->client_id ? (int) $p->client_id : null ) ), $user_id );
		if ( $old_client_id ) {
			foreach ( NDWD_WS::connections_for_client( (int) $old_client_id ) as $c ) {
				NDWD_WS_Webhooks::emit( 'project.updated', (int) $c->id, array( 'project_id' => (int) $p->id ) );
			}
		}
	}

	/** Same relationship from either side. @return true|WP_Error */
	public static function set_client( $project_id, $client_id, $user_id ) {
		global $wpdb;
		$p = self::project( $project_id );
		if ( ! $p ) {
			return NDWD_WS::err( 'Project not found.', 404, 'ndwd_not_found' );
		}
		if ( $p->parent_id ) {
			return NDWD_WS::err( 'A sub-project always belongs to its parent project\'s client. Change the client on "' . self::project( (int) $p->parent_id )->name . '".', 409, 'ndwd_sub_project' );
		}
		$client = $client_id ? NDWD_Clients::get( $client_id ) : null;
		if ( $client_id && ! $client ) {
			return NDWD_WS::err( 'Client not found.', 404, 'ndwd_not_found' );
		}
		if ( (int) $p->client_id === (int) $client_id ) {
			return true; // Already so: nothing to duplicate.
		}
		$ok = self::check_client_change( $project_id, $client_id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$wpdb->update( NDWD_Helpers::table( 'projects' ), array( 'client_id' => $client ? (int) $client->id : null, 'client' => $client ? $client->name : '' ), array( 'id' => $p->id ) );
		self::after_client_change( $project_id, (int) $p->client_id, $user_id );
		if ( $client ) {
			NDWD_Installer::backfill_task_clients( (int) $p->id );
		}
		do_action( 'ndwd_project_saved', (int) $p->id, false );
		return true;
	}

	public static function rest_set_client( WP_REST_Request $r ) {
		$res = self::set_client( (int) $r['id'], absint( self::body( $r )['client_id'] ?? 0 ), get_current_user_id() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::project_payload( (int) $r['id'] ) );
	}

	public static function rest_assign_to_client( WP_REST_Request $r ) {
		$pid = absint( self::body( $r )['project_id'] ?? 0 );
		$res = self::set_client( $pid, (int) $r['client_id'], get_current_user_id() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true, 'project_id' => $pid ) );
	}

	/**
	 * A new, separate Workspace for this project: an invitation for the client's email. When the
	 * client accepts, Neon Workspace creates a new Workspace named after the project and this project
	 * moves into it. The client switches between their Workspaces from the top switcher.
	 */
	public static function rest_new_workspace( WP_REST_Request $r ) {
		$p = self::project( (int) $r['id'] );
		if ( ! $p ) {
			return NDWD_WS::err( 'Project not found.', 404, 'ndwd_not_found' );
		}
		if ( ! $p->client_id ) {
			return NDWD_WS::err( 'Assign a client to this project first.', 409, 'ndwd_no_client' );
		}
		// One Workspace per main project: refuse a second one instead of making a duplicate. A project
		// still sharing an older Workspace with another main project may be given its own.
		$own = NDWD_WS::explicit_connection_id( (int) $p->id );
		$oc  = $own ? NDWD_WS::connection( $own ) : null;
		if ( $oc && ! $oc->archived_at && 'disconnected' !== $oc->status && NDWD_WS::connection_roots( $own ) === array( NDWD_WS::root_project_id( (int) $p->id ) ) ) {
			return NDWD_WS::err( 'This ' . ( $p->parent_id ? 'sub-project' : 'project' ) . ' already has its own Workspace, ' . ( '' !== (string) $oc->workspace_name ? $oc->workspace_name : 'waiting for the client to accept' ) . '.', 409, 'ndwd_already_assigned' );
		}
		$b      = self::body( $r );
		$client = NDWD_Clients::get( (int) $p->client_id );
		$email  = trim( (string) ( $b['email'] ?? '' ) );
		if ( '' === $email ) {
			global $wpdb;
			$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT i.accepted_email FROM ' . NDWD_WS::t( 'invitations' ) . ' i JOIN ' . NDWD_WS::t( 'connections' ) . " c ON c.id = i.connection_id WHERE c.client_id = %d AND i.accepted_email <> '' ORDER BY i.id DESC LIMIT 1", $p->client_id ) ); // phpcs:ignore
			$email = '' !== $email ? $email : (string) $client->email;
		}
		$name = mb_substr( trim( sanitize_text_field( (string) ( $b['workspace_name'] ?? '' ) ) ), 0, 190 );
		// The new Workspace starts with the same agency access as the client's main one. From then on
		// each Workspace's features and dates are changed on their own, in Workspace details.
		$main  = NDWD_WS::connection_for_client( (int) $p->client_id );
		$start = array();
		if ( $main ) {
			$start = array(
				'perms'          => NDWD_WS::perms( $main ),
				'agency_connect' => (bool) $main->agency_connect,
				'grace_days'     => (int) $main->grace_days,
				'package_start'  => $main->package_start,
				'package_end'    => $main->package_end,
				'entitlements'   => array_map( function ( $e ) {
					return array_intersect_key( $e, array_flip( array( 'feature_code', 'enabled', 'starts_on', 'expires_on', 'usage_limit' ) ) );
				}, NDWD_WS::entitlements( $main ) ),
			);
		}
		return rest_ensure_response( NDWD_WS::invite( $start + array(
			'client_id'      => (int) $p->client_id,
			'email'          => $email,
			'additional'     => true,
			'for_project'    => (int) $p->id,
			'workspace_name' => '' !== $name ? $name : $p->name,
			'business_category' => sanitize_key( (string) ( $b['business_category'] ?? '' ) ),
			'project_ids'    => array( (int) $p->id ),
			'valid_days'     => $b['valid_days'] ?? 14,
			'send_email'     => ! empty( $b['send_email'] ),
		), get_current_user_id() ) );
	}
}
