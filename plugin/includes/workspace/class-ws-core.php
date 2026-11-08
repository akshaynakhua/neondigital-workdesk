<?php
/**
 * Workspace integration core: settings and secrets, connections, per-feature entitlements,
 * client visibility, invitations and the audit trail. WorkDesk is the source of truth for all
 * of these; Workspace only ever reads them through the signed API.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS {

	const OPT = 'ndwd_ws_settings';

	/** Feature catalogue shared with Workspace (codes must match its entitlement engine). */
	const FEATURES = array(
		'calendar_personal'  => 'Personal Calendar',
		'calendar_business'  => 'Business Calendar',
		'calendar_project'   => 'Project Calendars',
		'calendar_agency'    => 'Agency Calendar',
		'projects'           => 'Projects & Tasks',
		'crm'                => 'CRM',
		'bookings'           => 'Bookings',
		'team_management'    => 'Team Management',
		'invoice_management' => 'Invoices & Quotations',
		'advanced_analytics' => 'Advanced Analytics',
		'automation'         => 'Automation',
		'customer_portal'    => 'Customer Portal',
		'agency_connect'     => 'NeonDigital Connect',
		'daily_planner'      => 'Daily Planner',
		'meetings'           => 'Meetings',
		'custom_forms'       => 'Custom Forms',
		'registration_forms' => 'Registration Forms',
		'accounts_finance'   => 'Accounts & Finance',
		'google_drive'       => 'Files & Google Drive',
		'reports'            => 'Reports',
	);

	const DEFAULT_PERMS = array(
		'tasks_mode'    => 'selected', // none | selected | project.
		'calendar'      => true,
		'work_requests' => true,
		'approvals'     => true,
		'invoices'      => false,
		'usage'         => true,
	);

	public static function t( $n ) {
		return NDWD_Helpers::table( 'ws_' . $n );
	}

	public static function err( $msg, $status = 400, $code = 'ndwd_invalid' ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/* ---------------------------- Settings and secrets ---------------------------- */

	public static function settings() {
		$s = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array(
			'workspace_url'  => '',
			'site_key'       => '',
			'site_secret'    => '',
			'retention_days' => 730,
		) );
	}

	/** Base URL of the Workspace install. The constant wins; webhooks only ever go here. */
	public static function workspace_url() {
		$u = defined( 'NDWD_WS_WORKSPACE_URL' ) ? NDWD_WS_WORKSPACE_URL : self::settings()['workspace_url'];
		// Someone pasting the API address gets the site address: /wp-json/wp-json/... has no route.
		return untrailingslashit( preg_replace( '#/(wp-json|index\.php)(/.*)?$#i', '', trim( (string) $u ) ) );
	}

	public static function site_credentials() {
		if ( defined( 'NDWD_WS_SITE_KEY' ) && defined( 'NDWD_WS_SITE_SECRET' ) ) {
			return array( NDWD_WS_SITE_KEY, NDWD_WS_SITE_SECRET );
		}
		$s = self::settings();
		return array( $s['site_key'], '' !== $s['site_secret'] ? self::unseal( $s['site_secret'] ) : '' );
	}

	/** Creates (or replaces) the site credential. The secret is returned once and stored sealed. */
	public static function rotate_site_credentials( $user_id ) {
		$s                = self::settings();
		$secret           = bin2hex( random_bytes( 32 ) );
		$s['site_key']    = 'wds_' . bin2hex( random_bytes( 12 ) );
		$s['site_secret'] = self::seal( $secret );
		update_option( self::OPT, $s, false );
		self::audit( 'site_credentials_rotated', array(), $user_id );
		return array( 'site_key' => $s['site_key'], 'site_secret' => $secret );
	}

	private static function enc_key() {
		$base = defined( 'NDWD_WS_ENC_KEY' ) ? NDWD_WS_ENC_KEY : wp_salt( 'auth' );
		return hash( 'sha256', 'ndwd-ws|' . $base, true );
	}

	public static function seal( $plain ) {
		$iv  = random_bytes( 12 );
		$tag = '';
		$c   = openssl_encrypt( $plain, 'aes-256-gcm', self::enc_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return base64_encode( $iv . $tag . $c ); // phpcs:ignore
	}

	public static function unseal( $sealed ) {
		$raw = base64_decode( (string) $sealed, true ); // phpcs:ignore
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$p = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::enc_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $p ? '' : $p;
	}

	/* ---------------------------- Audit ---------------------------- */

	public static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 64 ) : '';
	}

	/**
	 * @param array $ctx connection_id, entity, entity_id, kind (admin|data_access|export|api), actor, details.
	 */
	public static function audit( $action, $ctx = array(), $user_id = 0 ) {
		global $wpdb;
		$wpdb->insert( self::t( 'audit' ), array(
			'kind'          => isset( $ctx['kind'] ) ? $ctx['kind'] : 'admin',
			'action'        => $action,
			'connection_id' => isset( $ctx['connection_id'] ) ? (int) $ctx['connection_id'] : null,
			'entity'        => isset( $ctx['entity'] ) ? $ctx['entity'] : '',
			'entity_id'     => isset( $ctx['entity_id'] ) ? (int) $ctx['entity_id'] : null,
			'user_id'       => (int) $user_id,
			'actor'         => isset( $ctx['actor'] ) ? mb_substr( (string) $ctx['actor'], 0, 190 ) : ( $user_id ? NDWD_Helpers::user_name( $user_id ) : '' ),
			'ip'            => self::ip(),
			'details'       => isset( $ctx['details'] ) ? wp_json_encode( $ctx['details'] ) : null,
			'created_at'    => NDWD_Helpers::now_mysql(),
		) );
	}

	/* ---------------------------- Connections ---------------------------- */

	public static function connection( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'connections' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/**
	 * The client's main connection: their live one, or failing that their oldest. A client can have
	 * several Workspaces, so anything about one project uses connection_for_project().
	 */
	public static function connection_for_client( $client_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'connections' ) . " WHERE client_id = %d ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'suspended' THEN 1 WHEN 'invited' THEN 2 ELSE 3 END, id ASC LIMIT 1", $client_id ) ); // phpcs:ignore
	}

	public static function connections_for_client( $client_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'connections' ) . " WHERE client_id = %d AND status <> 'deleted' ORDER BY id ASC", $client_id ) ); // phpcs:ignore
	}

	/**
	 * The Workspace a project belongs to: its own explicit assignment, or for a sub-project without
	 * one, its parent's. A main project with no assignment has no Workspace.
	 *
	 * Phase 4: main projects no longer fall back to the client's main Workspace, so a new project of
	 * a client never appears inside another project's Workspace by itself. Upgrading to DB 19 pinned
	 * every project that relied on that fallback to the Workspace it was showing in, so nothing that
	 * clients could see before disappears. $client_id is kept for callers; it is no longer needed.
	 */
	public static function connection_for_project( $project_id, $client_id = 0 ) {
		global $wpdb;
		$id = self::explicit_connection_id( $project_id );
		if ( $id ) {
			$c = self::connection( $id );
			if ( $c ) {
				return $c;
			}
		}
		$parent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT parent_id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $project_id ) ); // phpcs:ignore
		if ( $parent && $parent !== (int) $project_id ) {
			return self::connection_for_project( $parent, $client_id );
		}
		return null;
	}

	/** The Workspace staff chose for this project itself, 0 when it has none. */
	public static function explicit_connection_id( $project_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT connection_id FROM ' . self::t( 'visibility' ) . " WHERE entity = 'project' AND entity_id = %d ORDER BY created_at DESC LIMIT 1", $project_id ) ); // phpcs:ignore
	}

	/** The main project a project belongs to: itself, or its parent. */
	public static function root_project_id( $project_id ) {
		global $wpdb;
		$parent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT parent_id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $project_id ) ); // phpcs:ignore
		return $parent ? $parent : (int) $project_id;
	}

	/**
	 * The main projects whose records a Workspace already holds (from its explicitly assigned projects).
	 * A Workspace belongs to one main project; only that project's own sub-projects may join it.
	 *
	 * @return int[]
	 */
	public static function connection_roots( $connection_id ) {
		global $wpdb;
		$roots = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT entity_id FROM ' . self::t( 'visibility' ) . " WHERE entity = 'project' AND connection_id = %d", $connection_id ) ) as $pid ) { // phpcs:ignore
			$roots[ self::root_project_id( (int) $pid ) ] = true;
		}
		return array_map( 'intval', array_keys( $roots ) );
	}

	/** Whether a project may be placed in a Workspace without mixing two main projects. */
	public static function connection_fits_project( $connection_id, $project_id ) {
		$roots = self::connection_roots( $connection_id );
		return ! $roots || array( self::root_project_id( $project_id ) ) === $roots;
	}

	/**
	 * The email of the client's existing Neon Workspace account: the address that accepted one of
	 * their invitations, on a Workspace that is still linked. Empty when they have no account yet.
	 */
	public static function account_email( $client_id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT i.accepted_email FROM ' . self::t( 'invitations' ) . ' i JOIN ' . self::t( 'connections' ) . " c ON c.id = i.connection_id WHERE c.client_id = %d AND i.accepted_at IS NOT NULL AND i.accepted_email <> '' AND c.linked_at IS NOT NULL ORDER BY i.id DESC LIMIT 1", $client_id ) ); // phpcs:ignore
	}

	/**
	 * Adds a Workspace to a client's existing account, with no invitation to accept. WorkDesk makes
	 * the identity and credentials; Neon Workspace creates the Workspace for the account with that
	 * email, owned by it, and tells them in the app. Terms and Privacy were accepted when the account
	 * was made, so nothing is asked again. If Workspace has no account for the email, it says so and
	 * nothing is changed here, so the caller falls back to an invitation.
	 *
	 * @return array|WP_Error|null Null when Workspace has no such account.
	 */
	public static function provision( $conn, $email, $name, $project_ids, $user_id ) {
		global $wpdb;
		$client = NDWD_Clients::get( (int) $conn->client_id );
		$pids   = array_values( array_filter( array_map( 'absint', (array) $project_ids ) ) );
		$first  = $pids ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, name FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $pids[0] ) ) : null; // phpcs:ignore
		$pname  = $first ? (string) $first->name : $name;
		$uid    = 'ws_' . bin2hex( random_bytes( 12 ) );
		$key    = 'wdc_' . bin2hex( random_bytes( 12 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		$title  = 'A new project, ' . $pname . ', has been added to your Neon Workspace.';
		$res    = NDWD_WS_Projects::ws_call( 'POST', '/connect/provision', array(
			'email'          => $email,
			'workspace_uid'  => $uid,
			'workspace_name' => $name,
			'api_key'        => $key,
			'api_secret'     => $secret,
			'client'         => array( 'name' => $client ? (string) $client->name : '' ),
			'entitlements'   => self::entitlements( $conn ),
			'business_category' => (string) ( $conn->business_category ?? '' ),
			'title'          => $title,
			'link'           => $first ? '#/agency/projects/' . (int) $first->id : '#/agency',
		) );
		if ( is_wp_error( $res ) ) {
			return 'nwc_no_account' === ( $res->get_error_data()['remote_code'] ?? '' ) ? null : $res;
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->update( self::t( 'connections' ), array(
			'workspace_uid'   => $uid,
			'workspace_name'  => mb_substr( sanitize_text_field( (string) $name ), 0, 190 ),
			'status'          => 'active',
			'api_key'         => $key,
			'api_secret'      => self::seal( $secret ),
			'linked_at'       => $now,
			'disconnected_at' => null,
			'archived_at'     => null,
			'updated_at'      => $now,
		), array( 'id' => $conn->id ) );
		// An invitation sent for this Workspace before is no longer needed: the account already exists.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'invitations' ) . ' SET revoked_at = %s WHERE connection_id = %d AND accepted_at IS NULL AND revoked_at IS NULL', $now, $conn->id ) ); // phpcs:ignore
		foreach ( $pids as $pid ) {
			NDWD_WS_Projects::link_project( $pid, (int) $conn->id, $user_id );
		}
		$emailed = false;
		if ( is_email( $email ) ) {
			$url     = self::workspace_url() . '/app/';
			$html    = NDWD_Notifications::template( $pname . ' has been added to your Neon Workspace', '<p>' . esc_html( $title ) . '</p><p>Sign in with your usual email and password. Choose <b>' . esc_html( $name ) . '</b> in the Workspace switcher at the top to open it. There is nothing new to accept.</p>', $url, 'Open Neon Workspace' );
			$emailed = (bool) NDWD_Notifications::send_to( $email, $pname . ' has been added to your Neon Workspace', $html, 'workspace_project_added', 'wsadd:' . $conn->id );
		}
		self::audit( 'workspace_provisioned', array( 'connection_id' => $conn->id, 'entity' => 'project', 'entity_id' => $first ? (int) $first->id : null, 'details' => array( 'workspace_uid' => $uid, 'email' => $email, 'projects' => $pids, 'emailed' => $emailed ) ), $user_id );
		return array(
			'provisioned'    => true,
			'connection_id'  => (int) $conn->id,
			'client_id'      => (int) $conn->client_id,
			'workspace_name' => (string) $name,
			'email'          => $email,
			'emailed'        => $emailed,
			'notified'       => true,
			'workspace_id'   => isset( $res['workspace_id'] ) ? (int) $res['workspace_id'] : null,
		);
	}

	/**
	 * Phase 5: the business type chosen when the Workspace was assigned. After the Workspace exists,
	 * its own directory report is the live value (it changes only through the controlled conversion).
	 */
	public static function business_type( $conn ) {
		global $wpdb;
		if ( ! $conn ) {
			return '';
		}
		$live = '' !== (string) $conn->workspace_uid ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT business_category FROM ' . self::t( 'directory' ) . ' WHERE workspace_uid = %s', $conn->workspace_uid ) ) : ''; // phpcs:ignore
		return '' !== $live ? $live : (string) ( $conn->business_category ?? '' );
	}

	public static function business_type_label( $key ) {
		return NDWD_WS_BType::TYPES[ $key ] ?? ( '' === (string) $key ? 'Not chosen' : ucwords( str_replace( '_', ' ', (string) $key ) ) );
	}

	/** v20: connections remember the business type; known values are copied from the directory. */
	public static function upgrade_v20() {
		global $wpdb;
		$c = self::t( 'connections' );
		$d = self::t( 'directory' );
		$wpdb->query( "UPDATE $c SET business_category = COALESCE((SELECT d.business_category FROM $d d WHERE d.workspace_uid = $c.workspace_uid), '') WHERE business_category = '' AND workspace_uid IS NOT NULL" ); // phpcs:ignore
		update_option( 'ndwd_v20_upgraded_at', NDWD_Helpers::now_mysql(), false );
	}

	/** The connection a task's data goes to. */
	public static function connection_for_task( $task ) {
		if ( ! empty( $task->project_id ) ) {
			return self::connection_for_project( (int) $task->project_id, (int) $task->client_id );
		}
		return ! empty( $task->client_id ) ? self::connection_for_client( (int) $task->client_id ) : null;
	}

	public static function connection_by_key( $key ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'connections' ) . ' WHERE api_key = %s', $key ) ); // phpcs:ignore
	}

	public static function perms( $conn ) {
		$p = json_decode( (string) $conn->perms, true );
		return wp_parse_args( is_array( $p ) ? $p : array(), self::DEFAULT_PERMS );
	}

	private static function clean_date( $v ) {
		return is_string( $v ) && '' !== $v && NDWD_Helpers::valid_date( $v ) ? $v : null;
	}

	/** Client-facing lifecycle of the whole agency relationship. */
	public static function package_state( $conn ) {
		return self::lifecycle( $conn->package_start, $conn->extended_until ? $conn->extended_until : $conn->package_end, (int) $conn->grace_days );
	}

	/**
	 * Active → Renewal Due → Grace Period → Locked. A missing expiry means no end date.
	 * The same rules run in Workspace's entitlement engine.
	 */
	public static function lifecycle( $start, $end, $grace ) {
		$today = NDWD_Helpers::today();
		if ( $start && $start > $today ) {
			return 'scheduled';
		}
		if ( ! $end ) {
			return 'active';
		}
		$days = (int) floor( ( strtotime( $end ) - strtotime( $today ) ) / DAY_IN_SECONDS );
		if ( $days > 14 ) {
			return 'active';
		}
		if ( $days >= 0 ) {
			return 'renewal_due';
		}
		return -$days <= max( 0, $grace ) ? 'grace' : 'locked';
	}

	/**
	 * Saves connection settings; each change is audited.
	 *
	 * @return object|WP_Error
	 */
	public static function save_connection( $id, $in, $user_id ) {
		global $wpdb;
		$conn = self::connection( $id );
		if ( ! $conn ) {
			return self::err( 'Connection not found.', 404, 'ndwd_not_found' );
		}
		$row = array();
		if ( isset( $in['status'] ) ) {
			if ( ! in_array( $in['status'], array( 'invited', 'active', 'suspended' ), true ) ) {
				return self::err( 'Unknown account status.' );
			}
			if ( 'active' === $in['status'] && ( ! $conn->linked_at || 'disconnected' === $conn->status ) ) {
				return self::err( 'This client has not accepted the invitation yet.' );
			}
			$row['status'] = $in['status'];
		}
		if ( isset( $in['agency_connect'] ) ) {
			$row['agency_connect'] = $in['agency_connect'] ? 1 : 0;
		}
		if ( isset( $in['grace_days'] ) ) {
			$row['grace_days'] = min( 365, absint( $in['grace_days'] ) );
		}
		foreach ( array( 'package_start', 'package_end', 'extended_until' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$row[ $k ] = self::clean_date( $in[ $k ] );
			}
		}
		if ( ! empty( $row['package_start'] ) && ! empty( $row['package_end'] ) && $row['package_end'] < $row['package_start'] ) {
			return self::err( 'The package expiry date is before its start date.' );
		}
		if ( array_key_exists( 'default_team_id', $in ) ) {
			$row['default_team_id'] = absint( $in['default_team_id'] ) ? absint( $in['default_team_id'] ) : null;
		}
		if ( isset( $in['default_team_mode'] ) ) {
			$row['default_team_mode'] = 'leader' === $in['default_team_mode'] ? 'leader' : 'pool';
		}
		if ( array_key_exists( 'default_member_id', $in ) ) {
			$row['default_member_id'] = absint( $in['default_member_id'] ) ? absint( $in['default_member_id'] ) : null;
		}
		if ( isset( $in['perms'] ) && is_array( $in['perms'] ) ) {
			$cur = self::perms( $conn );
			foreach ( array( 'calendar', 'work_requests', 'approvals', 'invoices', 'usage' ) as $k ) {
				if ( isset( $in['perms'][ $k ] ) ) {
					$cur[ $k ] = (bool) $in['perms'][ $k ];
				}
			}
			if ( isset( $in['perms']['tasks_mode'] ) && in_array( $in['perms']['tasks_mode'], array( 'none', 'selected', 'project' ), true ) ) {
				$cur['tasks_mode'] = $in['perms']['tasks_mode'];
			}
			$row['perms'] = wp_json_encode( $cur );
		}
		if ( $row ) {
			$row['updated_at'] = NDWD_Helpers::now_mysql();
			$wpdb->update( self::t( 'connections' ), $row, array( 'id' => $conn->id ) );
			self::audit( 'connection_updated', array( 'connection_id' => $conn->id, 'details' => array_diff_key( $row, array( 'updated_at' => 1 ) ) ), $user_id );
			$suspended = isset( $row['status'] ) && 'suspended' === $row['status'] && 'suspended' !== $conn->status;
			NDWD_WS_Webhooks::emit( $suspended ? 'client.suspended' : 'client.updated', $conn->id, array() );
		}
		foreach ( array( 'visible_projects' => 'project', 'visible_tasks' => 'task' ) as $k => $entity ) {
			if ( array_key_exists( $k, $in ) ) {
				$res = self::set_visibility( $conn, $entity, (array) $in[ $k ], $user_id );
				if ( is_wp_error( $res ) ) {
					return $res;
				}
			}
		}
		if ( array_key_exists( 'entitlements', $in ) ) {
			$res = self::save_entitlements( $conn, (array) $in['entitlements'], $user_id );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		return self::connection( $conn->id );
	}

	/** Only records of this connection's own client can be exposed. */
	public static function set_visibility( $conn, $entity, $ids, $user_id ) {
		global $wpdb;
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$table = NDWD_Helpers::table( 'project' === $entity ? 'projects' : 'tasks' );
		foreach ( $ids as $eid ) {
			$owner = $wpdb->get_var( $wpdb->prepare( "SELECT client_id FROM $table WHERE id = %d", $eid ) ); // phpcs:ignore
			if ( (int) $owner !== (int) $conn->client_id ) {
				return self::err( ucfirst( $entity ) . ' #' . $eid . ' does not belong to this client.', 403, 'ndwd_forbidden' );
			}
		}
		$vis = self::t( 'visibility' );
		$before = array();
		if ( 'project' === $entity ) {
			foreach ( $ids as $eid ) {
				$other = $wpdb->get_var( $wpdb->prepare( "SELECT connection_id FROM $vis WHERE entity = 'project' AND entity_id = %d AND connection_id <> %d", $eid, $conn->id ) ); // phpcs:ignore
				if ( $other ) {
					return self::err( 'Project #' . $eid . ' already belongs to another Workspace of this client. Move it instead.', 409, 'ndwd_conflict' );
				}
			}
			$before = self::visible_ids( $conn, 'project' );
			// Phase 4: a newly added project may not bring a second main project into this Workspace.
			// Workspaces that already mixed main projects before this rule keep what they have.
			$roots = array();
			foreach ( $ids as $eid ) {
				$roots[ self::root_project_id( $eid ) ] = true;
			}
			if ( count( $roots ) > 1 && array_diff( $ids, $before ) ) {
				return self::err( 'A Workspace holds one main project and its sub-projects. Give the other main project its own Workspace from its project page.', 409, 'ndwd_mixed_projects' );
			}
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM $vis WHERE connection_id = %d AND entity = %s", $conn->id, $entity ) ); // phpcs:ignore
		foreach ( $ids as $eid ) {
			$wpdb->insert( $vis, array( 'connection_id' => (int) $conn->id, 'entity' => $entity, 'entity_id' => $eid, 'created_at' => NDWD_Helpers::now_mysql() ) );
		}
		if ( 'project' === $entity ) {
			NDWD_WS_Projects::on_visibility_saved( $conn, $before, $ids, $user_id );
		}
		self::audit( 'visibility_updated', array( 'connection_id' => $conn->id, 'entity' => $entity, 'details' => array( 'ids' => $ids ) ), $user_id );
		NDWD_WS_Webhooks::emit( 'client.updated', $conn->id, array() );
		return true;
	}

	public static function visible_ids( $conn, $entity ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT entity_id FROM ' . self::t( 'visibility' ) . ' WHERE connection_id = %d AND entity = %s', $conn->id, $entity ) ) ); // phpcs:ignore
	}

	/* ---------------------------- Entitlements ---------------------------- */

	public static function save_entitlements( $conn, $list, $user_id ) {
		global $wpdb;
		$table = self::t( 'entitlements' );
		$clean = array();
		foreach ( $list as $e ) {
			$code = isset( $e['feature_code'] ) ? sanitize_key( $e['feature_code'] ) : '';
			if ( ! isset( self::FEATURES[ $code ] ) ) {
				return self::err( 'Unknown feature "' . $code . '".' );
			}
			$start = self::clean_date( isset( $e['starts_on'] ) ? $e['starts_on'] : null );
			$end   = self::clean_date( isset( $e['expires_on'] ) ? $e['expires_on'] : null );
			if ( $start && $end && $end < $start ) {
				return self::err( self::FEATURES[ $code ] . ': the expiry date is before the start date.' );
			}
			$clean[ $code ] = array(
				'enabled'     => empty( $e['enabled'] ) ? 0 : 1,
				'starts_on'   => $start,
				'expires_on'  => $end,
				'grace_days'  => isset( $e['grace_days'] ) && '' !== $e['grace_days'] ? min( 365, absint( $e['grace_days'] ) ) : null,
				'usage_limit' => isset( $e['usage_limit'] ) && '' !== $e['usage_limit'] ? absint( $e['usage_limit'] ) : null,
				'notes'       => isset( $e['notes'] ) ? mb_substr( sanitize_text_field( $e['notes'] ), 0, 255 ) : '',
			);
		}
		$any = false;
		foreach ( $clean as $code => $row ) {
			$old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE connection_id = %d AND feature_code = %s", $conn->id, $code ) ); // phpcs:ignore
			$row['updated_by'] = (int) $user_id;
			$row['updated_at'] = NDWD_Helpers::now_mysql();
			if ( $old ) {
				$changed = (int) $old->enabled !== $row['enabled'] || $old->starts_on !== $row['starts_on'] || $old->expires_on !== $row['expires_on']
					|| (string) $old->grace_days !== (string) $row['grace_days'] || (string) $old->usage_limit !== (string) $row['usage_limit'] || (string) $old->notes !== $row['notes'];
				if ( ! $changed ) {
					continue;
				}
				$wpdb->update( $table, $row, array( 'id' => $old->id ) );
			} else {
				$wpdb->insert( $table, array_merge( $row, array( 'connection_id' => (int) $conn->id, 'feature_code' => $code ) ) );
			}
			$any = true;
			self::audit( 'entitlement_changed', array(
				'connection_id' => $conn->id,
				'entity'        => 'feature',
				'details'       => array( 'feature' => $code, 'from' => $old ? array( 'enabled' => (int) $old->enabled, 'starts_on' => $old->starts_on, 'expires_on' => $old->expires_on ) : null, 'to' => array( 'enabled' => $row['enabled'], 'starts_on' => $row['starts_on'], 'expires_on' => $row['expires_on'] ) ),
			), $user_id );
		}
		if ( $any ) { // Saving the same values again tells nobody anything, so nothing is sent or logged.
			NDWD_WS_Webhooks::emit( 'entitlements.updated', $conn->id, array() );
		}
		return true;
	}

	/** Every catalogue feature with its own dates and state; unavailable ones are still listed. */
	public static function entitlements( $conn ) {
		global $wpdb;
		$rows = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'entitlements' ) . ' WHERE connection_id = %d', $conn->id ) ) as $r ) { // phpcs:ignore
			$rows[ $r->feature_code ] = $r;
		}
		$out = array();
		foreach ( self::FEATURES as $code => $label ) {
			$r     = isset( $rows[ $code ] ) ? $rows[ $code ] : null;
			$grace = $r && null !== $r->grace_days ? (int) $r->grace_days : (int) $conn->grace_days;
			$on    = $r && (int) $r->enabled;
			$out[] = array(
				'feature_code'   => $code,
				'label'          => $label,
				'enabled'        => (bool) $on,
				'starts_on'      => $r ? $r->starts_on : null,
				'expires_on'     => $r ? $r->expires_on : null,
				'grace_days'     => $grace,
				'grace_override' => $r && null !== $r->grace_days,
				'usage_limit'    => $r && null !== $r->usage_limit ? (int) $r->usage_limit : null,
				'notes'          => $r ? $r->notes : '',
				'state'          => $on ? self::lifecycle( $r->starts_on, $r->expires_on, $grace ) : 'disabled',
			);
		}
		return $out;
	}

	/* ---------------------------- Invitations ---------------------------- */

	/**
	 * Creates the connection (once per client), its settings and a single-use invitation.
	 * Client: existing client_id, or a `new_client` created here exactly once. The plain token is
	 * returned only in this response; only its hash is stored.
	 *
	 * @return array|WP_Error
	 */
	public static function invite( $in, $user_id ) {
		global $wpdb;
		$email = sanitize_email( isset( $in['email'] ) ? $in['email'] : '' );
		if ( ! is_email( $email ) ) {
			return self::err( 'Enter the email address the invitation is for.' );
		}
		if ( '' === self::workspace_url() ) {
			return self::err( 'Set the Workspace address in Settings → Integrations → Neon Workspace first.' );
		}
		if ( '' !== (string) ( $in['business_category'] ?? '' ) && ! isset( NDWD_WS_BType::TYPES[ sanitize_key( (string) $in['business_category'] ) ] ) ) {
			return self::err( 'Choose a business type from the list.', 400, 'ndwd_bad_business_type' );
		}
		$client_id  = absint( isset( $in['client_id'] ) ? $in['client_id'] : 0 );
		$additional = ! empty( $in['additional'] ); // Another Workspace for a client who already has one.
		if ( $client_id && ! $additional ) {
			$existing = self::connection_for_client( $client_id );
			if ( $existing && 'active' === $existing->status && $existing->linked_at ) {
				return self::err( 'This client is already connected. Disconnect the Workspace first to invite again.', 409, 'ndwd_already_connected' );
			}
		}
		if ( ! $client_id && ! empty( $in['new_client'] ) && is_array( $in['new_client'] ) ) {
			$saved = NDWD_Clients::save( $in['new_client'], $user_id );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$client_id = (int) $saved['id'];
		}
		$client = $client_id ? NDWD_Clients::get( $client_id ) : null;
		if ( ! $client ) {
			return self::err( 'Choose a client or enter the new client\'s details.' );
		}
		$now  = NDWD_Helpers::now_mysql();
		$conn = self::connection_for_client( $client_id );
		$for  = absint( $in['for_project'] ?? 0 );
		if ( $for ) {
			// Resending for the same project reuses its open invitation's connection: no duplicate.
			$conn = null;
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, i.project_ids AS pending FROM ' . self::t( 'connections' ) . ' c JOIN ' . self::t( 'invitations' ) . " i ON i.connection_id = c.id WHERE c.client_id = %d AND c.linked_at IS NULL AND c.status = 'invited' AND i.accepted_at IS NULL ORDER BY i.id DESC", $client_id ) ) as $x ) { // phpcs:ignore
				if ( in_array( $for, array_map( 'intval', (array) json_decode( (string) $x->pending, true ) ), true ) ) {
					$conn = self::connection( (int) $x->id );
					break;
				}
			}
		} elseif ( $additional && $conn && $conn->linked_at ) {
			// One open unlinked connection is reused, so resending never makes a duplicate.
			$conn = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'connections' ) . " WHERE client_id = %d AND linked_at IS NULL AND status = 'invited' ORDER BY id ASC LIMIT 1", $client_id ) ); // phpcs:ignore
		}
		$created = false;
		if ( ! $conn ) {
			$created = true;
			$wpdb->insert( self::t( 'connections' ), array(
				'client_id'  => $client_id,
				'status'     => 'invited',
				'perms'      => wp_json_encode( self::DEFAULT_PERMS ),
				'created_by' => (int) $user_id,
				'created_at' => $now,
				'updated_at' => $now,
			) );
			$conn = self::connection( (int) $wpdb->insert_id );
			if ( ! $conn ) {
				return self::err( 'Could not create the connection.', 500, 'ndwd_error' );
			}
		}
		// Optional: allocate a package at the same time, using the existing packages module.
		$allocation = null;
		if ( ! empty( $in['package'] ) && is_array( $in['package'] ) ) {
			$allocation = NDWD_Packages::assign( $client_id, $in['package'], $user_id );
			if ( is_wp_error( $allocation ) ) {
				return $allocation;
			}
		}
		$res = self::save_connection( $conn->id, array_intersect_key( $in, array_flip( array( 'grace_days', 'package_start', 'package_end', 'default_team_id', 'default_team_mode', 'default_member_id', 'perms', 'agency_connect', 'visible_projects', 'visible_tasks', 'entitlements' ) ) ), $user_id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! empty( $in['workspace_name'] ) && ! $conn->linked_at ) { // The name the new Workspace will get.
			$wpdb->update( self::t( 'connections' ), array( 'workspace_name' => mb_substr( sanitize_text_field( (string) $in['workspace_name'] ), 0, 190 ) ), array( 'id' => $conn->id ) );
		}
		if ( ! $conn->linked_at ) { // Phase 5: the business type decides the new Workspace's dashboard and modules.
			$bt = sanitize_key( (string) ( $in['business_category'] ?? '' ) );
			if ( '' !== $bt && ! isset( NDWD_WS_BType::TYPES[ $bt ] ) ) {
				return self::err( 'Choose a business type from the list.', 400, 'ndwd_bad_business_type' );
			}
			if ( '' !== $bt ) {
				$wpdb->update( self::t( 'connections' ), array( 'business_category' => $bt ), array( 'id' => $conn->id ) );
			}
		}
		$pending = array();
		if ( $for ) {
			// Stays in its current Workspace until the client accepts, so they never lose access meanwhile.
			$pending = array_values( array_unique( array_map( 'absint', (array) ( $in['project_ids'] ?? array() ) ) ) );
			$in['project_ids'] = array();
		}
		// Phase 4: a client who already has a Neon Workspace account gets the new Workspace added to it.
		// No second invitation, no second account and no second Terms acceptance. Only when the email
		// is that account's; another address means another person, who needs an invitation. Projects
		// move only once Neon Workspace has made the Workspace, so a failure leaves them where they are.
		$account     = ( $for || $additional ) && ! $conn->linked_at ? self::account_email( $client_id ) : '';
		$use_account = '' !== $account && 0 === strcasecmp( $account, $email );
		$deferred    = array();
		if ( $use_account && ! $for ) {
			$pending  = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $in['project_ids'] ?? array() ) ) ) ) );
			$deferred = isset( $in['project_packages'] ) ? (array) $in['project_packages'] : array();
			$in['project_ids']      = array();
			$in['project_packages'] = array();
		}
		foreach ( $pending as $pid ) { // Checked now, so nothing is made for a project that cannot go there.
			if ( ! NDWD_WS::connection_fits_project( (int) $conn->id, $pid ) ) {
				return self::err( 'A Workspace holds one main project and its sub-projects. Give each main project its own Workspace.', 409, 'ndwd_mixed_projects' );
			}
		}
		// Projects shown in this Workspace, and any package chosen for them on the invitation form.
		foreach ( array_map( 'absint', isset( $in['project_ids'] ) ? (array) $in['project_ids'] : array() ) as $pid ) {
			$linked = NDWD_WS_Projects::link_project( $pid, $conn->id, $user_id );
			if ( is_wp_error( $linked ) ) {
				return $linked;
			}
		}
		foreach ( isset( $in['project_packages'] ) ? (array) $in['project_packages'] : array() as $pp ) {
			$linked = NDWD_WS_Projects::link_project( absint( $pp['project_id'] ?? 0 ), $conn->id, $user_id );
			$done   = is_wp_error( $linked ) ? $linked : NDWD_WS_Projects::assign( absint( $pp['project_id'] ), $pp, $user_id );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		if ( $use_account ) {
			$fresh = self::connection( $conn->id );
			$wname = '' !== (string) $fresh->workspace_name ? (string) $fresh->workspace_name : ( '' !== (string) $client->company ? $client->company : $client->name );
			$done  = self::provision( $fresh, $account, $wname, $pending, $user_id );
			if ( is_wp_error( $done ) && $created ) {
				// Neon Workspace could not add it: leave nothing half-made behind, so a retry starts clean.
				$wpdb->delete( self::t( 'entitlements' ), array( 'connection_id' => (int) $conn->id ) );
				$wpdb->delete( self::t( 'connections' ), array( 'id' => (int) $conn->id ) );
			}
			if ( null !== $done ) {
				if ( ! is_wp_error( $done ) ) {
					foreach ( $deferred as $pp ) {
						$linked = NDWD_WS_Projects::link_project( absint( $pp['project_id'] ?? 0 ), $conn->id, $user_id );
						if ( ! is_wp_error( $linked ) ) {
							NDWD_WS_Projects::assign( absint( $pp['project_id'] ), $pp, $user_id );
						}
					}
				}
				return $done;
			}
			// No account on Neon Workspace after all: an invitation, and the projects move when it is accepted.
			if ( ! $for ) {
				foreach ( $pending as $pid ) {
					NDWD_WS_Projects::link_project( $pid, $conn->id, $user_id );
				}
				$pending = array();
			}
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'invitations' ) . ' SET revoked_at = %s WHERE connection_id = %d AND accepted_at IS NULL AND revoked_at IS NULL', $now, $conn->id ) ); // phpcs:ignore
		$days    = isset( $in['valid_days'] ) ? max( 1, min( 60, absint( $in['valid_days'] ) ) ) : 14;
		$token   = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now ) + $days * DAY_IN_SECONDS );
		$wpdb->insert( self::t( 'invitations' ), array(
			'connection_id' => (int) $conn->id,
			'token_hash'    => hash( 'sha256', $token ),
			'email'         => $email,
			'expires_at'    => $expires,
			'project_ids'   => $pending ? wp_json_encode( $pending ) : null,
			'created_by'    => (int) $user_id,
			'created_at'    => $now,
		) );
		$inv_id = (int) $wpdb->insert_id;
		$url     = self::workspace_url() . '/join/' . $token;
		$emailed = false;
		if ( ! empty( $in['send_email'] ) ) {
			$html    = NDWD_Notifications::template( 'Your Neon Workspace invitation', '<p>NeonDigital has invited ' . esc_html( $client->name ) . ' to Neon Workspace. Set your password to get started.</p><p>This link works once and expires in ' . (int) $days . ' days.</p>', $url, 'Open my invitation' );
			$emailed = (bool) NDWD_Notifications::send_to( $email, 'Your Neon Workspace invitation', $html, 'workspace_invite' );
		}
		self::audit( 'invitation_created', array( 'connection_id' => $conn->id, 'entity' => 'invitation', 'entity_id' => $inv_id, 'details' => array( 'email' => $email, 'valid_days' => $days, 'emailed' => $emailed ) ), $user_id );
		return array(
			'invitation_id' => $inv_id,
			'connection_id' => (int) $conn->id,
			'client_id'     => $client_id,
			'expires_at'    => $expires,
			'url'           => $url,
			'emailed'       => $emailed,
			'workspace_name' => (string) self::connection( $conn->id )->workspace_name,
			'business_category' => (string) self::connection( $conn->id )->business_category,
			'pending_projects' => $pending,
		);
	}

	/**
	 * Details the invitation page shows, read-only, for a valid token. Everything comes from the
	 * WorkDesk record. A bad token learns nothing about any client.
	 *
	 * @return array|WP_Error
	 */
	public static function preview( $token ) {
		global $wpdb;
		$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'invitations' ) . ' WHERE token_hash = %s', hash( 'sha256', (string) $token ) ) ); // phpcs:ignore
		if ( ! $inv || $inv->revoked_at ) {
			return self::err( 'This invitation is not valid. Ask NeonDigital for a new one.', 404, 'ndwd_invite_invalid' );
		}
		if ( $inv->accepted_at ) {
			return self::err( 'This invitation has already been used.', 409, 'ndwd_invite_used' );
		}
		if ( $inv->expires_at < NDWD_Helpers::now_mysql() ) {
			return self::err( 'This invitation has expired. Ask NeonDigital for a new one.', 410, 'ndwd_invite_expired' );
		}
		$conn   = self::connection( $inv->connection_id );
		$client = $conn ? NDWD_Clients::get( $conn->client_id ) : null;
		if ( ! $client ) {
			return self::err( 'This invitation is not valid. Ask NeonDigital for a new one.', 404, 'ndwd_invite_invalid' );
		}
		$out = array(
			'email'         => $inv->email,
			'name'          => '' !== (string) $client->contact_person ? $client->contact_person : $client->name,
			'business_name' => '' !== (string) $client->company ? $client->company : $client->name,
			'expires_at'    => $inv->expires_at,
		);
		if ( ! $conn->linked_at && '' !== (string) $conn->workspace_name ) {
			$out['workspace_name'] = (string) $conn->workspace_name; // Only when staff named the new Workspace.
		}
		return $out;
	}

	/**
	 * Consumes an invitation. One atomic UPDATE decides the winner, so a token can only be used
	 * once. It links to the invitation's existing client and never creates a client.
	 *
	 * @return array|WP_Error
	 */
	public static function accept( $token, $uid, $name, $email ) {
		global $wpdb;
		$inv_t = self::t( 'invitations' );
		$inv   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $inv_t WHERE token_hash = %s", hash( 'sha256', (string) $token ) ) ); // phpcs:ignore
		$now   = NDWD_Helpers::now_mysql();
		if ( ! $inv || $inv->revoked_at ) {
			return self::err( 'This invitation is not valid.', 404, 'ndwd_invite_invalid' );
		}
		if ( $inv->accepted_at ) {
			// A retry after a lost response: the same Workspace identity gets the same answer, not a second link.
			$done = self::connection( $inv->connection_id );
			if ( $done && 'active' === $done->status && $done->api_key && hash_equals( (string) $done->workspace_uid, (string) $uid ) && 0 === strcasecmp( (string) $inv->accepted_email, (string) $email ) ) {
				return array( 'connection' => $done, 'api_key' => $done->api_key, 'api_secret' => self::unseal( $done->api_secret ) );
			}
			return self::err( 'This invitation has already been used.', 409, 'ndwd_invite_used' );
		}
		if ( $inv->expires_at < $now ) {
			return self::err( 'This invitation has expired. Ask NeonDigital for a new one.', 410, 'ndwd_invite_expired' );
		}
		if ( '' !== $inv->email && 0 !== strcasecmp( $inv->email, (string) $email ) ) {
			self::audit( 'invitation_email_mismatch', array( 'kind' => 'api', 'connection_id' => $inv->connection_id, 'entity' => 'invitation', 'entity_id' => $inv->id, 'details' => array( 'email' => $email ) ) );
			return self::err( 'This invitation was sent to a different email address.', 403, 'ndwd_invite_email' );
		}
		$uid  = sanitize_text_field( (string) $uid );
		$conn = self::connection( $inv->connection_id );
		if ( ! $conn || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $uid ) ) {
			return self::err( 'Invalid workspace.' );
		}
		$other = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'connections' ) . ' WHERE workspace_uid = %s AND id <> %d', $uid, $conn->id ) ); // phpcs:ignore
		if ( $other ) {
			return self::err( 'This workspace is already linked to another NeonDigital client.', 409, 'ndwd_invite_conflict' );
		}
		$won = $wpdb->query( $wpdb->prepare( "UPDATE $inv_t SET accepted_at = %s, accepted_email = %s WHERE id = %d AND accepted_at IS NULL AND revoked_at IS NULL", $now, (string) $email, $inv->id ) ); // phpcs:ignore
		if ( ! $won ) {
			return self::err( 'This invitation has already been used.', 409, 'ndwd_invite_used' );
		}
		$key    = 'wdc_' . bin2hex( random_bytes( 12 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		$wpdb->update( self::t( 'connections' ), array(
			'workspace_uid'  => $uid,
			'workspace_name' => mb_substr( sanitize_text_field( (string) $name ), 0, 190 ),
			'status'         => 'active',
			'api_key'        => $key,
			'api_secret'     => self::seal( $secret ),
			'linked_at'      => $now,
			'disconnected_at' => null,
			'archived_at'    => null,
			'updated_at'     => $now,
		), array( 'id' => $conn->id ) );
		self::audit( 'client_linked', array( 'kind' => 'api', 'connection_id' => $conn->id, 'actor' => (string) $email, 'details' => array( 'workspace_uid' => $uid ) ) );
		foreach ( array_map( 'absint', (array) json_decode( (string) $inv->project_ids, true ) ) as $pid ) { // Projects this Workspace was made for.
			NDWD_WS_Projects::link_project( $pid, (int) $conn->id, 0 );
		}
		return array( 'connection' => self::connection( $conn->id ), 'api_key' => $key, 'api_secret' => $secret );
	}
}
