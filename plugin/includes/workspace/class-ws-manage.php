<?php
/**
 * Everything staff do to a client's Workspace relationship from WorkDesk: the status block on a
 * client, the Workspace Clients table, the separate destructive actions, and explicit invoice
 * sharing.
 *
 * The destructive actions are deliberately different things:
 *   Revoke Invitation  - invalidates an unused token. Audit history stays.
 *   Disconnect         - ends the integration and stops sync. Both sides keep their own records.
 *   Archive            - hides the relationship from the default list. Deletes nothing.
 *   Deletion request   - (older records) a request and its assessment.
 *   Delete Workspace   - Phase 5: really deletes an agency-managed Workspace on Neon Workspace (after
 *                        the exact name is typed), then removes WorkDesk's synced copies and the link.
 *                        WorkDesk's own agency records (clients, projects, tasks, invoices, requests
 *                        and their files) stay: they are the agency's business records.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_Manage {

	const STATUS_LABELS = array(
		'not_connected'      => 'Not Connected',
		'invitation_pending' => 'Invitation Pending',
		'connected'          => 'Connected',
		'active'             => 'Active',
		'renewal_due'        => 'Renewal Due',
		'grace'              => 'Grace Period',
		'locked'             => 'Package Expired',
		'suspended'          => 'Deactivated',
		'disconnected'       => 'Disconnected',
		'deleted'            => 'Deleted',
	);

	const SORTS = array(
		'client'   => 'c.name',
		'id'       => 'c.id',
		'status'   => 'conn.status',
		'validity' => 'conn.package_end',
		'sync'     => 'conn.last_seen_at',
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	private static function route( $path, $methods, $cb, $cap = 'ndwd_manage_workspace' ) {
		register_rest_route( NDWD_REST::NS, '/workspace' . $path, array(
			'methods'             => $methods,
			'callback'            => array( __CLASS__, $cb ),
			'permission_callback' => function () use ( $cap ) {
				return is_user_logged_in() && NDWD_Permissions::can( $cap );
			},
		) );
	}

	public static function routes() {
		self::route( '/clients', 'GET', 'table' );
		self::route( '/clients/(?P<client_id>\d+)/status', 'GET', 'client_status' );
		self::route( '/clients/(?P<client_id>\d+)/invite', 'POST', 'client_invite' );
		self::route( '/connections/(?P<id>\d+)/disconnect', 'POST', 'disconnect' );
		self::route( '/connections/(?P<id>\d+)/suspend', 'POST', 'suspend' );
		self::route( '/connections/(?P<id>\d+)/reactivate', 'POST', 'reactivate' );
		self::route( '/connections/(?P<id>\d+)/archive', 'POST', 'archive' );
		self::route( '/connections/(?P<id>\d+)/unarchive', 'POST', 'unarchive' );
		self::route( '/connections/(?P<id>\d+)/logs', 'GET', 'logs' );
		self::route( '/connections/(?P<id>\d+)/deletion', 'GET', 'deletion_view' );
		self::route( '/connections/(?P<id>\d+)/deletion', 'POST', 'deletion_request' );
		self::route( '/deletion/(?P<id>\d+)/decide', 'POST', 'deletion_decide' );
		self::route( '/connections/(?P<id>\d+)/delete-preview', 'GET', 'delete_preview' );
		self::route( '/connections/(?P<id>\d+)/delete', 'POST', 'delete_workspace' );
		self::route( '/invoices/(?P<id>\d+)/send', 'GET', 'invoice_state', 'ndwd_manage_accounts' );
		self::route( '/invoices/(?P<id>\d+)/send', 'POST', 'invoice_send', 'ndwd_manage_accounts' );
	}

	private static function body( WP_REST_Request $r ) {
		$b = json_decode( (string) $r->get_body(), true );
		return is_array( $b ) ? $b : array();
	}

	/* ---------------------------- Status ---------------------------- */

	private static function open_invite( $connection_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'invitations' ) . ' WHERE connection_id = %d AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > %s ORDER BY id DESC LIMIT 1', $connection_id, NDWD_Helpers::now_mysql() ) ); // phpcs:ignore
	}

	/**
	 * One word for a relationship. An invitation is never Connected or Active: that takes an
	 * accepted invitation and a verified link.
	 */
	public static function status_key( $conn, $has_open_invite = null ) {
		if ( ! $conn ) {
			return 'not_connected';
		}
		if ( 'deleted' === $conn->status ) {
			return 'deleted';
		}
		$open = null === $has_open_invite ? (bool) self::open_invite( $conn->id ) : $has_open_invite;
		if ( 'disconnected' === $conn->status ) {
			return $open ? 'invitation_pending' : 'disconnected';
		}
		if ( 'suspended' === $conn->status ) {
			return 'suspended';
		}
		if ( ! $conn->linked_at || ! $conn->api_key ) {
			return $open ? 'invitation_pending' : 'not_connected';
		}
		if ( ! $conn->package_end && ! $conn->extended_until ) {
			return 'connected'; // Linked, with no package dates to report on.
		}
		$state = NDWD_WS::package_state( $conn );
		return in_array( $state, array( 'renewal_due', 'grace', 'locked' ), true ) ? $state : 'active';
	}

	private static function health_of( $conn ) {
		global $wpdb;
		if ( ! $conn || ! $conn->linked_at || 'disconnected' === $conn->status ) {
			return array( 'state' => 'none', 'label' => 'No active link', 'failed_24h' => 0, 'pending_webhooks' => 0 );
		}
		$failed  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'api_log' ) . ' WHERE connection_id = %d AND status >= 400 AND created_at > %s', $conn->id, gmdate( 'Y-m-d H:i:s', strtotime( NDWD_Helpers::now_mysql() ) - DAY_IN_SECONDS ) ) ); // phpcs:ignore
		$waiting = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'webhooks' ) . " WHERE connection_id = %d AND status <> 'delivered'", $conn->id ) ); // phpcs:ignore
		$state   = $failed || $waiting ? 'attention' : 'healthy';
		return array( 'state' => $state, 'label' => 'healthy' === $state ? 'Healthy' : 'Needs attention', 'failed_24h' => $failed, 'pending_webhooks' => $waiting );
	}

	public static function client_status( WP_REST_Request $r ) {
		global $wpdb;
		$client = NDWD_Clients::get( (int) $r['client_id'] );
		if ( ! $client ) {
			return NDWD_WS::err( 'Client not found.', 404, 'ndwd_not_found' );
		}
		$want   = absint( $r->get_param( 'connection_id' ) );
		$conn   = $want ? NDWD_WS::connection( $want ) : NDWD_WS::connection_for_client( $client->id );
		if ( $conn && (int) $conn->client_id !== (int) $client->id ) {
			return NDWD_WS::err( 'That Workspace belongs to another client.', 403, 'ndwd_forbidden' );
		}
		$open   = $conn ? self::open_invite( $conn->id ) : null;
		$key    = self::status_key( $conn, (bool) $open );
		$latest = $conn ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'invitations' ) . ' WHERE connection_id = %d ORDER BY id DESC LIMIT 1', $conn->id ) ) : null; // phpcs:ignore
		$feats  = $conn ? array_values( array_filter( NDWD_WS::entitlements( $conn ), function ( $e ) {
			return $e['enabled'];
		} ) ) : array();
		$pack   = array();
		foreach ( NDWD_Packages::for_client( $client->id ) as $a ) {
			$a      = is_array( $a ) ? $a : NDWD_Packages::allocation_array( $a );
			$pack[] = array( 'name' => $a['name'], 'status' => $a['status_label'], 'start_date' => $a['start_date'], 'end_date' => $a['end_date'] );
		}
		return rest_ensure_response( array(
			'client_id'         => (int) $client->id,
			'client_name'       => $client->name,
			'business_name'     => '' !== (string) $client->company ? $client->company : $client->name,
			'contact_email'     => (string) $client->email,
			'status'            => $key,
			'status_label'      => self::STATUS_LABELS[ $key ],
			'connection_id'     => $conn ? (int) $conn->id : null,
			'workspace_uid'     => $conn ? $conn->workspace_uid : null,
			'workspace_name'    => $conn ? $conn->workspace_name : '',
			'workspace_url'     => NDWD_WS::workspace_url(),
			'invited_email'     => $latest ? $latest->email : '',
			'invitation_id'     => $open ? (int) $open->id : null,
			'invitation_expiry' => $open ? $open->expires_at : null,
			'package_start'     => $conn ? $conn->package_start : null,
			'package_end'       => $conn ? ( $conn->extended_until ? $conn->extended_until : $conn->package_end ) : null,
			'grace_days'        => $conn ? (int) $conn->grace_days : null,
			'package_state'     => $conn ? NDWD_WS::package_state( $conn ) : null,
			'packages'          => $pack,
			'feature_count'     => count( $feats ),
			'features'          => $feats,
			'linked_at'         => $conn ? $conn->linked_at : null,
			'last_sync'         => $conn ? $conn->last_seen_at : null,
			'health'            => self::health_of( $conn ),
			'archived'          => $conn ? (bool) $conn->archived_at : false,
			'has_link'          => $conn && $conn->linked_at && 'disconnected' !== $conn->status,
			'workspaces'        => array_map( function ( $c ) {
				return array( 'id' => (int) $c->id, 'name' => (string) $c->workspace_name, 'status' => $c->status );
			}, NDWD_WS::connections_for_client( $client->id ) ),
		) );
	}

	/** Send and resend: one route. invite() replaces any open invitation, so no duplicate is created. */
	public static function client_invite( WP_REST_Request $r ) {
		$client = NDWD_Clients::get( (int) $r['client_id'] );
		if ( ! $client ) {
			return NDWD_WS::err( 'Client not found.', 404, 'ndwd_not_found' );
		}
		$b     = self::body( $r );
		$conn  = NDWD_WS::connection_for_client( $client->id );
		$email = isset( $b['email'] ) && '' !== trim( (string) $b['email'] ) ? (string) $b['email'] : (string) $client->email;
		if ( '' === $email && $conn ) {
			global $wpdb;
			$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . NDWD_WS::t( 'invitations' ) . ' WHERE connection_id = %d ORDER BY id DESC LIMIT 1', $conn->id ) ); // phpcs:ignore
		}
		$res = NDWD_WS::invite( array( 'client_id' => (int) $client->id, 'email' => $email, 'valid_days' => $b['valid_days'] ?? 14, 'send_email' => ! empty( $b['send_email'] ) ), get_current_user_id() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/* ---------------------------- Workspace Clients table ---------------------------- */

	/**
	 * Server-side search, filter, sort and pagination across all clients. One row per client: the
	 * connection is unique per client, and the invitation and count columns are single values.
	 */
	public static function table( WP_REST_Request $r ) {
		global $wpdb;
		$cl    = NDWD_Helpers::table( 'clients' );
		$co    = NDWD_WS::t( 'connections' );
		$in    = NDWD_WS::t( 'invitations' );
		$en    = NDWD_WS::t( 'entitlements' );
		$now   = NDWD_Helpers::now_mysql();
		$today = NDWD_Helpers::today();
		$open  = "EXISTS (SELECT 1 FROM $in i WHERE i.connection_id = conn.id AND i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > %s)";
		$end   = 'COALESCE(conn.extended_until, conn.package_end)';
		$where = array();
		$vals  = array();
		$q     = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = "(c.name LIKE %s OR c.company LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR conn.workspace_uid LIKE %s OR conn.workspace_name LIKE %s OR EXISTS (SELECT 1 FROM $in i2 WHERE i2.connection_id = conn.id AND i2.email LIKE %s)" . ( ctype_digit( $q ) ? ' OR c.id = %d' : '' ) . ')';
			$vals    = array_merge( $vals, array_fill( 0, 7, $like ) );
			if ( ctype_digit( $q ) ) {
				$vals[] = (int) $q;
			}
		}
		$renew = gmdate( 'Y-m-d', strtotime( $today ) + 14 * DAY_IN_SECONDS );
		switch ( sanitize_key( (string) $r->get_param( 'filter' ) ) ) {
			case 'connected':
				$where[] = "conn.linked_at IS NOT NULL AND conn.status = 'active'";
				break;
			case 'invitation_pending':
				$where[] = $open;
				$vals[]  = $now;
				break;
			case 'not_connected':
				$where[] = "(conn.id IS NULL OR (conn.linked_at IS NULL AND conn.status <> 'disconnected' AND NOT $open))";
				$vals[]  = $now;
				break;
			case 'suspended':
				$where[] = "conn.status = 'suspended'";
				break;
			case 'disconnected':
				$where[] = "conn.status = 'disconnected'";
				break;
			case 'active_package':
				$where[] = "conn.linked_at IS NOT NULL AND ($end IS NOT NULL AND $end >= %s)";
				$vals[]  = $today;
				break;
			case 'expired_package':
				$where[] = "$end IS NOT NULL AND $end < %s";
				$vals[]  = $today;
				break;
			case 'renewal_due':
				$where[] = "$end IS NOT NULL AND $end >= %s AND $end <= %s";
				$vals[]  = $today;
				$vals[]  = $renew;
				break;
			case 'missing_entitlements':
				$where[] = "conn.id IS NOT NULL AND conn.status <> 'disconnected' AND NOT EXISTS (SELECT 1 FROM $en e WHERE e.connection_id = conn.id AND e.enabled = 1)";
				break;
			case 'archived':
				$where[] = 'conn.archived_at IS NOT NULL';
				break;
		}
		$fl = sanitize_key( (string) $r->get_param( 'filter' ) );
		if ( 'archived' !== $fl ) {
			$where[] = '(conn.id IS NULL OR conn.archived_at IS NULL)';
		}
		// Disconnected Workspaces leave the default list. Their records stay, a tick shows them.
		if ( ! $r->get_param( 'show_inactive' ) && ! in_array( $fl, array( 'disconnected', 'archived', 'invitation_pending', 'expired_package' ), true ) ) {
			$where[] = "(conn.id IS NULL OR conn.status NOT IN ('disconnected','deleted'))";
		}
		$sql_where = $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$from      = " FROM $cl c LEFT JOIN $co conn ON conn.client_id = c.id";
		$sort_key  = sanitize_key( (string) $r->get_param( 'sort' ) );
		$sort      = isset( self::SORTS[ $sort_key ] ) ? self::SORTS[ $sort_key ] : 'c.name';
		$dir       = 'desc' === strtolower( (string) $r->get_param( 'dir' ) ) ? 'DESC' : 'ASC';
		$per       = max( 5, min( 100, absint( $r->get_param( 'per_page' ) ) ? absint( $r->get_param( 'per_page' ) ) : 25 ) );
		$page      = max( 1, absint( $r->get_param( 'page' ) ) );
		$count_sql = 'SELECT COUNT(*)' . $from . $sql_where;
		$total     = (int) $wpdb->get_var( $vals ? $wpdb->prepare( $count_sql, $vals ) : $count_sql ); // phpcs:ignore
		$list_sql  = 'SELECT c.id AS client_id, c.name, c.company, c.email, c.phone, conn.id AS conn_id' . $from . $sql_where . " ORDER BY $sort $dir, c.id ASC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $vals, array( $per, ( $page - 1 ) * $per ) ) ) ); // phpcs:ignore
		$items     = array();
		foreach ( $rows as $x ) {
			$conn   = $x->conn_id ? NDWD_WS::connection( $x->conn_id ) : null;
			$invite = $conn ? self::open_invite( $conn->id ) : null;
			$key    = self::status_key( $conn, (bool) $invite );
			$sent   = $conn ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT email FROM ' . NDWD_WS::t( 'invitations' ) . ' WHERE connection_id = %d ORDER BY id DESC LIMIT 1', $conn->id ) ) : ''; // phpcs:ignore
			$items[] = array(
				'client_id'      => (int) $x->client_id,
				'name'           => $x->name,
				'company'        => (string) $x->company,
				'email'          => (string) $x->email,
				'phone'          => (string) $x->phone,
				'connection_id'  => $conn ? (int) $conn->id : null,
				'workspace_uid'  => $conn ? (string) $conn->workspace_uid : '',
				'workspace_name' => $conn ? (string) $conn->workspace_name : '',
				'status'         => $key,
				'status_label'   => self::STATUS_LABELS[ $key ],
				'connect_on'     => $conn ? (bool) $conn->agency_connect : false,
				'invitation_id'  => $invite ? (int) $invite->id : null,
				'invited_email'  => $sent,
				'package_end'    => $conn ? ( $conn->extended_until ? $conn->extended_until : $conn->package_end ) : null,
				'package_state'  => $conn ? NDWD_WS::package_state( $conn ) : null,
				'package_names'  => array_map( function ( $a ) {
					$a = is_array( $a ) ? $a : NDWD_Packages::allocation_array( $a );
					return $a['name'];
				}, NDWD_Packages::for_client( $x->client_id ) ),
				'project_count'  => $conn ? count( NDWD_WS_Projects::projects_of( $conn ) ) : 0,
				'locked_count'   => $conn ? count( array_filter( NDWD_WS_Projects::projects_of( $conn ), function ( $p ) use ( $conn ) {
					return ! NDWD_WS_Projects::project_open( $conn, (int) $p->id );
				} ) ) : 0,
				'feature_count'  => $conn ? count( array_filter( NDWD_WS::entitlements( $conn ), function ( $e ) {
					return $e['enabled'];
				} ) ) : 0,
				'last_sync'      => $conn ? $conn->last_seen_at : null,
				'archived'       => $conn ? (bool) $conn->archived_at : false,
			);
		}
		return rest_ensure_response( array( 'items' => $items, 'total' => $total, 'page' => $page, 'pages' => (int) ceil( $total / $per ), 'per_page' => $per ) );
	}

	/* ---------------------------- Relationship actions ---------------------------- */

	private static function conn_or_error( WP_REST_Request $r ) {
		$c = NDWD_WS::connection( (int) $r['id'] );
		return $c ? $c : NDWD_WS::err( 'Connection not found.', 404, 'ndwd_not_found' );
	}

	/**
	 * Revokes the agency integration and stops future sync. The client's independent Workspace
	 * account and data stay, and so do WorkDesk's records. Workspace is told first, while the key
	 * that signs the message still exists; if that fails, its next signed call is refused anyway.
	 */
	public static function disconnect( WP_REST_Request $r ) {
		global $wpdb;
		$c = self::conn_or_error( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( 'disconnected' === $c->status ) {
			return rest_ensure_response( array( 'ok' => true, 'already' => true ) );
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'invitations' ) . ' SET revoked_at = %s WHERE connection_id = %d AND accepted_at IS NULL AND revoked_at IS NULL', $now, $c->id ) ); // phpcs:ignore
		$told = false;
		if ( $c->workspace_uid && $c->api_key ) {
			$event = NDWD_WS_Webhooks::emit( 'client.disconnected', $c->id, array() );
			$row   = $event ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'webhooks' ) . ' WHERE event_id = %s', $event ) ) : null; // phpcs:ignore
			$told  = $row ? NDWD_WS_Webhooks::deliver( $row ) : false;
		}
		$wpdb->update( NDWD_WS::t( 'connections' ), array( 'status' => 'disconnected', 'disconnected_at' => $now, 'api_key' => null, 'api_secret' => null, 'updated_at' => $now ), array( 'id' => $c->id ) );
		// Nothing further can be signed, so anything still queued can never be delivered.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'webhooks' ) . " SET status = 'dead', last_error = 'Disconnected', next_attempt_at = NULL WHERE connection_id = %d AND status = 'pending'", $c->id ) ); // phpcs:ignore
		NDWD_WS::audit( 'workspace_disconnected', array( 'connection_id' => $c->id, 'details' => array( 'workspace_told' => $told ) ), get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true, 'workspace_told' => $told ) );
	}

	public static function suspend( WP_REST_Request $r ) {
		$res = NDWD_WS::save_connection( (int) $r['id'], array( 'status' => 'suspended' ), get_current_user_id() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true ) );
	}

	public static function reactivate( WP_REST_Request $r ) {
		$res = NDWD_WS::save_connection( (int) $r['id'], array( 'status' => 'active' ), get_current_user_id() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true ) );
	}

	/** Hides the relationship from the default list. Only this flag changes. */
	public static function archive( WP_REST_Request $r ) {
		global $wpdb;
		$c = self::conn_or_error( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( 'active' === $c->status && $c->linked_at ) {
			return NDWD_WS::err( 'Disconnect this Workspace before archiving the link.', 409, 'ndwd_conflict' );
		}
		$wpdb->update( NDWD_WS::t( 'connections' ), array( 'archived_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $c->id ) );
		NDWD_WS::audit( 'workspace_link_archived', array( 'connection_id' => $c->id ), get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function unarchive( WP_REST_Request $r ) {
		global $wpdb;
		$c = self::conn_or_error( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$wpdb->update( NDWD_WS::t( 'connections' ), array( 'archived_at' => null ), array( 'id' => $c->id ) );
		NDWD_WS::audit( 'workspace_link_restored', array( 'connection_id' => $c->id ), get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function logs( WP_REST_Request $r ) {
		global $wpdb;
		$c = self::conn_or_error( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$api  = $wpdb->get_results( $wpdb->prepare( 'SELECT method, route, status, error, created_at FROM ' . NDWD_WS::t( 'api_log' ) . ' WHERE connection_id = %d ORDER BY id DESC LIMIT 50', $c->id ) ); // phpcs:ignore
		$hook = $wpdb->get_results( $wpdb->prepare( 'SELECT id, event, status, attempts, last_error, created_at FROM ' . NDWD_WS::t( 'webhooks' ) . ' WHERE connection_id = %d ORDER BY id DESC LIMIT 50', $c->id ) ); // phpcs:ignore
		return rest_ensure_response( array( 'api' => $api, 'webhooks' => $hook ) );
	}

	/* ---------------------------- Deletion requests ---------------------------- */

	/** What WorkDesk can check itself. Workspace's subscription and data are assessed on that side. */
	private static function assessment( $c ) {
		global $wpdb;
		$t      = function ( $n ) {
			return NDWD_Helpers::table( $n );
		};
		$client = (int) $c->client_id;
		$unpaid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( 'invoices' ) . " WHERE client_id = %d AND status NOT IN ('cancelled','paid','consolidated')", $client ) ); // phpcs:ignore
		$inv    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( 'invoices' ) . ' WHERE client_id = %d', $client ) ); // phpcs:ignore
		$pay    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $t( 'payments' ) . ' p JOIN ' . $t( 'invoices' ) . ' i ON i.id = p.invoice_id WHERE i.client_id = %d AND p.voided_at IS NULL', $client ) ); // phpcs:ignore
		$open   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'requests' ) . " WHERE connection_id = %d AND status IN ('submitted','in_review','approved','in_progress')", $c->id ) ); // phpcs:ignore
		return array(
			'unpaid_invoices' => $unpaid,
			'invoices'        => $inv,
			'payments'        => $pay,
			'open_requests'   => $open,
			'still_connected' => 'active' === $c->status && (bool) $c->linked_at,
			'retention_note'  => 'Issued invoices and payment records are kept for tax and accounting law even after an account is deleted.',
			'manual_checks'   => array( 'Workspace subscription and unpaid subscription invoices', 'Legal holds and open disputes', 'Whether the customer has exported what they need', 'Who owns the Workspace, and whether others are members' ),
		);
	}

	public static function deletion_view( WP_REST_Request $r ) {
		global $wpdb;
		$c = self::conn_or_error( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'deletion_requests' ) . ' WHERE connection_id = %d ORDER BY id DESC', $c->id ) ); // phpcs:ignore
		return rest_ensure_response( array(
			'assessment' => self::assessment( $c ),
			'can_decide' => NDWD_Permissions::can( 'ndwd_delete_records' ),
			'requests'   => array_map( function ( $x ) {
				return array( 'id' => (int) $x->id, 'status' => $x->status, 'reason' => (string) $x->reason, 'outcome' => (string) $x->outcome, 'requested_by' => NDWD_Helpers::user_name( (int) $x->requested_by ), 'created_at' => $x->created_at, 'decided_at' => $x->decided_at, 'assessment' => json_decode( (string) $x->assessment, true ) );
			}, $rows ),
		) );
	}

	/** Records the request and tells the Workspace owners. Nothing is deleted here. */
	public static function deletion_request( WP_REST_Request $r ) {
		global $wpdb;
		$c = self::conn_or_error( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$b = self::body( $r );
		if ( empty( $b['confirm'] ) || '' === trim( (string) ( $b['reason'] ?? '' ) ) ) {
			return NDWD_WS::err( 'Give the reason and confirm the request.' );
		}
		$open = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_WS::t( 'deletion_requests' ) . " WHERE connection_id = %d AND status = 'requested'", $c->id ) ); // phpcs:ignore
		if ( $open ) {
			return NDWD_WS::err( 'A deletion request is already open for this Workspace.', 409, 'ndwd_conflict' );
		}
		$assess = self::assessment( $c );
		$wpdb->insert( NDWD_WS::t( 'deletion_requests' ), array( 'connection_id' => (int) $c->id, 'client_id' => (int) $c->client_id, 'workspace_uid' => (string) $c->workspace_uid, 'reason' => sanitize_textarea_field( (string) $b['reason'] ), 'assessment' => wp_json_encode( $assess ), 'status' => 'requested', 'requested_by' => get_current_user_id(), 'created_at' => NDWD_Helpers::now_mysql() ) );
		$id = (int) $wpdb->insert_id;
		NDWD_WS::audit( 'workspace_deletion_requested', array( 'connection_id' => $c->id, 'entity' => 'deletion_request', 'entity_id' => $id, 'kind' => 'data_access', 'details' => $assess ), get_current_user_id() );
		NDWD_WS_Webhooks::emit( 'workspace.deletion_requested', $c->id, array( 'request_id' => $id ) );
		return rest_ensure_response( array( 'id' => $id, 'assessment' => $assess ) );
	}

	/**
	 * Records what a person decided and did. It deletes nothing: actual deletion happens on the
	 * Workspace install, after the checks, and this records the fact.
	 */
	public static function deletion_decide( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NDWD_Permissions::can( 'ndwd_delete_records' ) ) {
			return NDWD_WS::err( 'You cannot decide deletion requests.', 403, 'ndwd_forbidden' );
		}
		$t   = NDWD_WS::t( 'deletion_requests' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", (int) $r['id'] ) ); // phpcs:ignore
		if ( ! $row ) {
			return NDWD_WS::err( 'Request not found.', 404, 'ndwd_not_found' );
		}
		if ( 'requested' !== $row->status ) {
			return NDWD_WS::err( 'This request was already decided.', 409, 'ndwd_conflict' );
		}
		$b       = self::body( $r );
		$status  = in_array( $b['status'] ?? '', array( 'rejected', 'retained', 'completed' ), true ) ? $b['status'] : '';
		$outcome = sanitize_textarea_field( (string) ( $b['outcome'] ?? '' ) );
		if ( '' === $status || '' === trim( $outcome ) ) {
			return NDWD_WS::err( 'Choose the outcome and describe what was done.' );
		}
		$wpdb->update( $t, array( 'status' => $status, 'outcome' => $outcome, 'decided_by' => get_current_user_id(), 'decided_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $row->id ) );
		NDWD_WS::audit( 'workspace_deletion_' . $status, array( 'connection_id' => $row->connection_id, 'entity' => 'deletion_request', 'entity_id' => $row->id, 'kind' => 'data_access', 'details' => array( 'outcome' => $outcome ) ), get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/* ---------------------------- Delete Workspace (Phase 5) ---------------------------- */

	/** WorkDesk's synced copies of this Workspace's data, removed with it. */
	private static function local_copies( $c ) {
		global $wpdb;
		$uid = (string) $c->workspace_uid;
		$n   = function ( $t ) use ( $wpdb, $uid ) {
			return '' === $uid ? 0 : (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( $t ) . ' WHERE workspace_uid = %s', $uid ) ); // phpcs:ignore
		};
		$projects = array();
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT entity_id FROM ' . NDWD_WS::t( 'visibility' ) . " WHERE connection_id = %d AND entity = 'project'", $c->id ) ) as $pid ) { // phpcs:ignore
			$name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $pid ) ); // phpcs:ignore
			if ( $name ) {
				$projects[] = (string) $name;
			}
		}
		return array( 'leads' => $n( 'leads' ), 'bookings' => $n( 'bookings' ), 'directory' => $n( 'directory' ), 'projects_unassigned' => $projects );
	}

	public static function delete_preview( WP_REST_Request $r ) {
		$c = self::conn_or_error( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$client = NDWD_Clients::get( (int) $c->client_id );
		$out    = array(
			'connection_id'  => (int) $c->id,
			'workspace_name' => (string) $c->workspace_name,
			'workspace_uid'  => (string) $c->workspace_uid,
			'client_name'    => $client ? (string) $client->name : '',
			'status'         => self::status_key( $c ),
			'linked'         => (bool) $c->linked_at && ! empty( $c->api_key ),
			'can_delete'     => NDWD_Permissions::can( 'ndwd_delete_records' ),
			'workdesk'       => self::local_copies( $c ),
			'workdesk_kept'  => array( 'The client, its projects, tasks, work requests, conversations and files in WorkDesk (the agency\'s own records)', 'WorkDesk invoices and payments (tax records)', 'The audit trail, including this deletion' ),
			'workspace'      => null,
			'workspace_error' => '',
		);
		if ( 'deleted' === $c->status ) {
			$out['workspace_error'] = 'This Workspace has already been deleted.';
		} elseif ( $out['linked'] ) {
			$res = NDWD_WS_Projects::ws_call( 'POST', '/connect/workspace/delete-preview', array( 'workspace_uid' => (string) $c->workspace_uid, 'api_key' => (string) $c->api_key ) );
			if ( is_wp_error( $res ) ) {
				$out['workspace_error'] = $res->get_error_message();
			} else {
				$out['workspace'] = $res;
			}
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Deletes for real. The exact Workspace name must be typed. Neon Workspace deletes first (its
	 * financial records go to its restricted archive); only when it confirms does WorkDesk remove
	 * its own copies and close the link. If Neon Workspace cannot be reached or refuses, nothing is
	 * deleted anywhere and the reason is returned.
	 */
	public static function delete_workspace( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NDWD_Permissions::can( 'ndwd_delete_records' ) ) {
			return NDWD_WS::err( 'You need permission to delete records to delete a Workspace.', 403, 'ndwd_forbidden' );
		}
		$c = self::conn_or_error( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( 'deleted' === $c->status ) {
			return NDWD_WS::err( 'This Workspace has already been deleted.', 409, 'ndwd_conflict' );
		}
		$b     = self::body( $r );
		$typed = trim( (string) ( $b['confirm_name'] ?? '' ) );
		$name  = (string) $c->workspace_name;
		if ( '' === $typed || $typed !== $name ) {
			return NDWD_WS::err( 'Type the Workspace name exactly to confirm. Nothing was deleted.', 422, 'ndwd_confirm' );
		}
		$reason = sanitize_textarea_field( (string) ( $b['reason'] ?? '' ) );
		$remote = null;
		$note   = '';
		if ( $c->linked_at && ! empty( $c->api_key ) ) {
			$remote = NDWD_WS_Projects::ws_call( 'POST', '/connect/workspace/delete', array( 'workspace_uid' => (string) $c->workspace_uid, 'api_key' => (string) $c->api_key, 'confirm_name' => $name, 'actor' => NDWD_Helpers::user_name( get_current_user_id() ), 'reason' => $reason ) );
			if ( is_wp_error( $remote ) ) {
				$code = (string) ( $remote->get_error_data()['remote_code'] ?? '' );
				if ( 'nwc_not_found' !== $code ) {
					NDWD_WS::audit( 'workspace_delete_failed', array( 'connection_id' => $c->id, 'kind' => 'data_access', 'details' => array( 'error' => $remote->get_error_message() ) ), get_current_user_id() );
					return NDWD_WS::err( 'Neon Workspace did not delete the Workspace: ' . $remote->get_error_message() . ' Nothing was deleted.', $remote->get_error_data()['status'] ?? 502, 'ndwd_delete_failed' );
				}
				$note   = 'Neon Workspace reports this Workspace no longer exists there.';
				$remote = null;
			}
		} else {
			$note = 'The invitation was never accepted, so no Workspace existed on Neon Workspace.';
		}
		$copies = self::local_copies( $c );
		$now    = NDWD_Helpers::now_mysql();
		$uid    = (string) $c->workspace_uid;
		$wpdb->query( 'START TRANSACTION' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'invitations' ) . ' SET revoked_at = %s WHERE connection_id = %d AND accepted_at IS NULL AND revoked_at IS NULL', $now, $c->id ) ); // phpcs:ignore
		$wpdb->delete( NDWD_WS::t( 'visibility' ), array( 'connection_id' => (int) $c->id ) );
		$wpdb->delete( NDWD_WS::t( 'entitlements' ), array( 'connection_id' => (int) $c->id ) );
		if ( '' !== $uid ) {
			foreach ( array( 'leads', 'bookings', 'directory' ) as $t ) {
				$wpdb->delete( NDWD_WS::t( $t ), array( 'workspace_uid' => $uid ) );
			}
		}
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'webhooks' ) . " SET status = 'dead', last_error = 'Workspace deleted', next_attempt_at = NULL WHERE connection_id = %d AND status <> 'delivered'", $c->id ) ); // phpcs:ignore
		$wpdb->update( NDWD_WS::t( 'connections' ), array( 'status' => 'deleted', 'deleted_at' => $now, 'api_key' => null, 'api_secret' => null, 'archived_at' => $now, 'updated_at' => $now ), array( 'id' => $c->id ) );
		$outcome = array( 'workspace' => $remote, 'workdesk_removed' => $copies, 'note' => $note );
		$wpdb->insert( NDWD_WS::t( 'deletion_requests' ), array( 'connection_id' => (int) $c->id, 'client_id' => (int) $c->client_id, 'workspace_uid' => $uid, 'reason' => '' !== $reason ? $reason : 'Deleted from Workspace Management', 'assessment' => wp_json_encode( $outcome ), 'status' => 'completed', 'outcome' => 'Deleted. ' . ( $remote ? 'Neon Workspace removed ' . array_sum( (array) ( $remote['deleted'] ?? array() ) ) . ' records and ' . (int) ( $remote['files_removed'] ?? 0 ) . ' files and archived the financial records.' : $note ), 'requested_by' => get_current_user_id(), 'decided_by' => get_current_user_id(), 'created_at' => $now, 'decided_at' => $now ) );
		$failed = (bool) $wpdb->last_error;
		$wpdb->query( $failed ? 'ROLLBACK' : 'COMMIT' );
		NDWD_WS::audit( 'workspace_deleted', array( 'connection_id' => $c->id, 'entity' => 'connection', 'entity_id' => $c->id, 'kind' => 'data_access', 'details' => array( 'workspace_uid' => $uid, 'name' => $name, 'workspace' => $remote, 'workdesk_removed' => $copies, 'local_cleanup_failed' => $failed ) ), get_current_user_id() );
		if ( $failed ) {
			return NDWD_WS::err( 'The Workspace was deleted on Neon Workspace, but WorkDesk could not finish removing its own copies. Try again: it continues from here.', 500, 'ndwd_partial' );
		}
		return rest_ensure_response( array( 'ok' => true, 'workspace_name' => $name, 'workspace' => $remote, 'workdesk_removed' => $copies, 'note' => $note ) );
	}

	/* ---------------------------- Sending an invoice ---------------------------- */

	public static function share_for( $invoice_id, $connection_id = 0 ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . NDWD_WS::t( 'invoice_shares' ) . ' WHERE invoice_id = %d';
		$s   = $wpdb->get_row( $wpdb->prepare( $sql, $invoice_id ) ); // phpcs:ignore
		return $s && ( ! $connection_id || (int) $s->connection_id === (int) $connection_id ) ? $s : null;
	}

	/**
	 * The fields a client may see of an invoice. An allow-list, so internal notes, work remarks,
	 * audit entries and staff names can never travel.
	 */
	public static function invoice_payload( $v ) {
		$a     = NDWD_Accounts::invoice_array( $v, true );
		$lines = array_map( function ( $i ) {
			return array(
				'description' => (string) ( $i['description'] ?? '' ),
				'amount'      => (float) ( $i['amount'] ?? 0 ),
			);
		}, (array) $a['items'] );
		return array(
			'id'             => (int) $a['id'],
			'invoice_no'     => $a['invoice_no'],
			'invoice_date'   => $a['invoice_date'],
			'due_date'       => $a['due_date'],
			'package_name'   => (string) $a['package_name'],
			'lines'          => $lines,
			'subtotal'       => (float) $a['subtotal'],
			'discount'       => (float) $a['discount'],
			'tax_label'      => (string) $a['tax_label'],
			'tax_rate'       => (float) $a['tax_rate'],
			'tax_amount'     => (float) $a['tax_amount'],
			'total'          => (float) $a['total'],
			'amount_paid'    => (float) $a['amount_received'],
			'balance'        => (float) $a['balance'],
			'status'         => $a['status'],
			'status_label'   => $a['status_label'],
			'updated_stamp'  => time(),
		);
	}

	private static function send_state( $v ) {
		$conn  = NDWD_WS::connection_for_client( $v->client_id );
		$share = self::share_for( $v->id );
		$live  = $conn && 'active' === $conn->status && $conn->linked_at && $conn->api_key && $conn->workspace_uid;
		return array(
			'can_send'       => (bool) $live && 'consolidated' !== $v->status,
			'reason'         => $live ? '' : ( $conn && 'suspended' === $conn->status ? 'This client\'s Workspace access is suspended.' : 'This client has no connected Workspace, so there is nowhere to send this invoice.' ),
			'workspace_name' => $conn ? (string) $conn->workspace_name : '',
			'workspace_uid'  => $conn ? (string) $conn->workspace_uid : '',
			'sent'           => $share ? array( 'sent_at' => $share->sent_at, 'sent_by' => NDWD_Helpers::user_name( (int) $share->sent_by ), 'send_count' => (int) $share->send_count, 'delivery_status' => $share->delivery_status, 'delivered_at' => $share->delivered_at, 'workspace_uid' => $share->workspace_uid ) : null,
		);
	}

	public static function invoice_state( WP_REST_Request $r ) {
		$v = NDWD_Accounts::invoice_row( (int) $r['id'] );
		return $v ? rest_ensure_response( self::send_state( $v ) ) : NDWD_WS::err( 'Invoice not found.', 404, 'ndwd_not_found' );
	}

	/**
	 * Explicit send, confirmed against the destination. One share row per invoice and a stable id on
	 * the other side, so a resend updates the same copy.
	 */
	public static function invoice_send( WP_REST_Request $r ) {
		global $wpdb;
		if ( ! NDWD_Permissions::can( 'ndwd_manage_workspace' ) ) {
			return NDWD_WS::err( 'You need Workspace Management access to send invoices to Workspace.', 403, 'ndwd_forbidden' );
		}
		$v = NDWD_Accounts::invoice_row( (int) $r['id'] );
		if ( ! $v ) {
			return NDWD_WS::err( 'Invoice not found.', 404, 'ndwd_not_found' );
		}
		$state = self::send_state( $v );
		if ( ! $state['can_send'] ) {
			return NDWD_WS::err( '' !== $state['reason'] ? $state['reason'] : 'This invoice cannot be sent.', 409, 'ndwd_no_workspace' );
		}
		$b = self::body( $r );
		if ( empty( $b['confirm_workspace_uid'] ) || ! hash_equals( $state['workspace_uid'], (string) $b['confirm_workspace_uid'] ) ) {
			return NDWD_WS::err( 'Confirm the Workspace this invoice is going to.', 422, 'ndwd_confirm' );
		}
		$conn = NDWD_WS::connection_for_client( $v->client_id );
		$now  = NDWD_Helpers::now_mysql();
		$old  = self::share_for( $v->id );
		$t    = NDWD_WS::t( 'invoice_shares' );
		if ( $old ) {
			$wpdb->update( $t, array( 'connection_id' => (int) $conn->id, 'workspace_uid' => $conn->workspace_uid, 'sent_by' => get_current_user_id(), 'sent_at' => $now, 'send_count' => (int) $old->send_count + 1, 'delivery_status' => 'pending', 'last_error' => '' ), array( 'id' => $old->id ) );
		} else {
			$wpdb->insert( $t, array( 'invoice_id' => (int) $v->id, 'connection_id' => (int) $conn->id, 'workspace_uid' => $conn->workspace_uid, 'sent_by' => get_current_user_id(), 'sent_at' => $now, 'delivery_status' => 'pending' ) );
		}
		$delivered = self::push( $v, $conn, $old ? 'invoice.resent' : 'invoice.shared' );
		NDWD_WS::audit( $old ? 'invoice_resent_to_workspace' : 'invoice_sent_to_workspace', array( 'connection_id' => $conn->id, 'entity' => 'invoice', 'entity_id' => $v->id, 'details' => array( 'invoice_no' => $v->invoice_no, 'workspace_uid' => $conn->workspace_uid, 'delivered' => $delivered ) ), get_current_user_id() );
		return rest_ensure_response( array_merge( self::send_state( $v ), array( 'delivered' => $delivered ) ) );
	}

	/** Queues the signed event, then tries it straight away. A failure stays queued for retries. */
	private static function push( $v, $conn, $event ) {
		global $wpdb;
		$id  = NDWD_WS_Webhooks::emit( $event, $conn->id, array( 'invoice' => self::invoice_payload( $v ) ) );
		$row = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'webhooks' ) . ' WHERE event_id = %s', $id ) ) : null; // phpcs:ignore
		$ok  = $row ? NDWD_WS_Webhooks::deliver( $row ) : false;
		$wpdb->update( NDWD_WS::t( 'invoice_shares' ), $ok ? array( 'delivery_status' => 'delivered', 'delivered_at' => NDWD_Helpers::now_mysql(), 'last_error' => '' ) : array( 'delivery_status' => 'retrying', 'last_error' => 'Queued for retry' ), array( 'invoice_id' => (int) $v->id ) );
		return (bool) $ok;
	}

	/** Payments, voids and cancellations reach only invoices already sent, and only while connected. */
	public static function on_invoice_change( $invoice_id, $action ) {
		if ( ! in_array( $action, array( 'payment_recorded', 'payment_voided', 'payment_allocated', 'payment_moved_from_original', 'payment_moved_to_replacement', 'invoice_cancelled', 'invoice_edited', 'advance_released', 'advances_reallocated', 'balance_consolidated_in', 'balance_consolidated_out', 'consolidation_reversed' ), true ) ) {
			return;
		}
		$share = self::share_for( $invoice_id );
		$v     = $share ? NDWD_Accounts::invoice_row( $invoice_id ) : null;
		$conn  = $v ? NDWD_WS::connection_for_client( $v->client_id ) : null;
		if ( ! $conn || ! $conn->api_key || (int) $conn->id !== (int) $share->connection_id ) {
			return;
		}
		$id = NDWD_WS_Webhooks::emit( 'payment_recorded' === $action ? 'invoice.paid' : 'invoice.updated', $conn->id, array( 'invoice' => self::invoice_payload( $v ) ) );
		return $id;
	}
}
