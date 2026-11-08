<?php
/**
 * Workspace Management admin API (namespace ndwd/v1, cookie + nonce like the rest of the app).
 * Also: agency calendar events and the internal operations dashboard.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_Admin {

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
		self::route( '/settings', 'GET', 'get_settings' );
		self::route( '/settings', 'POST', 'save_settings' );
		self::route( '/settings/rotate', 'POST', 'rotate' );
		self::route( '/connections', 'GET', 'connections' );
		self::route( '/connections/(?P<id>\d+)', 'GET', 'connection' );
		self::route( '/connections/(?P<id>\d+)', 'PUT,PATCH', 'update_connection' );
		self::route( '/invitations', 'POST', 'invite' );
		self::route( '/invitations/(?P<id>\d+)/revoke', 'POST', 'revoke' );
		self::route( '/directory', 'GET', 'directory' );
		self::route( '/requests', 'GET', 'requests' );
		self::route( '/requests/(?P<id>\d+)', 'GET', 'request' );
		self::route( '/requests/(?P<id>\d+)/review', 'POST', 'review' );
		self::route( '/requests/(?P<id>\d+)/comments', 'POST', 'comment' );
		self::route( '/deliverables', 'GET', 'deliverables' );
		self::route( '/deliverables', 'POST', 'save_deliverable' );
		self::route( '/deliverables/(?P<id>\d+)', 'PUT,PATCH', 'save_deliverable' );
		self::route( '/leads', 'GET', 'leads', 'ndwd_view_consolidated' );
		self::route( '/bookings', 'GET', 'bookings', 'ndwd_view_consolidated' );
		self::route( '/retention/purge', 'POST', 'purge', 'ndwd_view_consolidated' );
		self::route( '/logs/(?P<kind>api|webhooks|audit|backups)', 'GET', 'logs' );
		self::route( '/webhooks/(?P<id>\d+)/retry', 'POST', 'webhook_retry' );
		self::route( '/webhooks/run', 'POST', 'webhook_run' );
		self::route( '/backups', 'POST', 'backup_add' );
		self::route( '/events', 'POST', 'save_event', 'ndwd_access' );
		self::route( '/events/(?P<id>\d+)', 'PUT,PATCH', 'save_event', 'ndwd_access' );
		self::route( '/events/(?P<id>\d+)', 'DELETE', 'delete_event', 'ndwd_access' );
		self::route( '/calendar', 'GET', 'calendar', 'ndwd_access' );
		self::route( '/ops', 'GET', 'ops', 'ndwd_access' );
	}

	private static function body( WP_REST_Request $r ) {
		$b = json_decode( (string) $r->get_body(), true );
		return is_array( $b ) ? $b : array();
	}

	private static function uid() {
		return get_current_user_id();
	}

	private static function out( $res ) {
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/* ---------------------------- Settings ---------------------------- */

	public static function get_settings() {
		$s = NDWD_WS::settings();
		list( $key, $secret ) = NDWD_WS::site_credentials();
		return rest_ensure_response( array(
			'workspace_url'      => NDWD_WS::workspace_url(),
			'url_from_constant'  => defined( 'NDWD_WS_WORKSPACE_URL' ),
			'site_key'           => '' !== (string) $key ? substr( (string) $key, 0, 4 ) . '…' . substr( (string) $key, -4 ) : '', // Masked: the full key is shown once, when created.
			'has_secret'         => '' !== $secret,
			'keys_from_constant' => defined( 'NDWD_WS_SITE_KEY' ),
			'retention_days'     => (int) $s['retention_days'],
			'features'           => NDWD_WS::FEATURES,
			'request_statuses'   => NDWD_WS_Requests::STATUSES,
		) );
	}

	public static function save_settings( WP_REST_Request $r ) {
		$b = self::body( $r );
		$s = NDWD_WS::settings();
		if ( isset( $b['workspace_url'] ) ) {
			$url = esc_url_raw( trim( (string) $b['workspace_url'] ), array( 'http', 'https' ) );
			if ( '' !== trim( (string) $b['workspace_url'] ) && ! wp_parse_url( $url, PHP_URL_HOST ) ) {
				return NDWD_WS::err( 'Enter the full Workspace address, for example https://workspace.neondigital.in' );
			}
			$s['workspace_url'] = untrailingslashit( $url );
		}
		if ( isset( $b['retention_days'] ) ) {
			$s['retention_days'] = max( 30, min( 3650, absint( $b['retention_days'] ) ) );
		}
		update_option( NDWD_WS::OPT, $s, false );
		NDWD_WS::audit( 'settings_updated', array( 'details' => array( 'workspace_url' => $s['workspace_url'], 'retention_days' => $s['retention_days'] ) ), self::uid() );
		return self::get_settings();
	}

	/** The secret appears only in this response. */
	public static function rotate() {
		if ( defined( 'NDWD_WS_SITE_KEY' ) ) {
			return NDWD_WS::err( 'The site key is set in wp-config.php. Change it there.' );
		}
		return rest_ensure_response( NDWD_WS::rotate_site_credentials( self::uid() ) );
	}

	/* ---------------------------- Connections ---------------------------- */

	private static function conn_array( $c ) {
		$client = NDWD_Clients::get( $c->client_id );
		return array(
			'id'                => (int) $c->id,
			'client_id'         => (int) $c->client_id,
			'client_name'       => $client ? $client->name : '',
			'workspace_uid'     => $c->workspace_uid,
			'workspace_name'    => $c->workspace_name,
			'status'            => $c->status,
			'agency_connect'    => (bool) $c->agency_connect,
			'grace_days'        => (int) $c->grace_days,
			'package_start'     => $c->package_start,
			'package_end'       => $c->package_end,
			'extended_until'    => $c->extended_until,
			'package_state'     => NDWD_WS::package_state( $c ),
			'default_team_id'   => $c->default_team_id ? (int) $c->default_team_id : null,
			'default_team_mode' => $c->default_team_mode,
			'default_member_id' => $c->default_member_id ? (int) $c->default_member_id : null,
			'perms'             => NDWD_WS::perms( $c ),
			'linked_at'         => $c->linked_at,
			'last_seen_at'      => $c->last_seen_at,
		);
	}

	public static function connections() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'connections' ) . ' ORDER BY id DESC' ); // phpcs:ignore
		return rest_ensure_response( array( 'items' => array_map( function ( $c ) {
			$a             = self::conn_array( $c );
			$a['features'] = array_values( array_filter( NDWD_WS::entitlements( $c ), function ( $e ) {
				return $e['enabled'];
			} ) );
			return $a;
		}, $rows ) ) );
	}

	public static function connection( WP_REST_Request $r ) {
		global $wpdb;
		$c = NDWD_WS::connection( (int) $r['id'] );
		if ( ! $c ) {
			return NDWD_WS::err( 'Connection not found.', 404, 'ndwd_not_found' );
		}
		$projects = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, is_active, is_archived FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE client_id = %d ORDER BY name', $c->client_id ) ); // phpcs:ignore
		$tasks    = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, status, project_id FROM ' . NDWD_Helpers::table( 'tasks' ) . ' WHERE client_id = %d AND is_archived = 0 ORDER BY id DESC LIMIT 200', $c->client_id ) ); // phpcs:ignore
		$invites  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, email, expires_at, accepted_at, revoked_at, created_at FROM ' . NDWD_WS::t( 'invitations' ) . ' WHERE connection_id = %d ORDER BY id DESC', $c->id ) ); // phpcs:ignore
		return rest_ensure_response( array_merge( self::conn_array( $c ), array(
			'entitlements'     => NDWD_WS::entitlements( $c ),
			'visible_projects' => NDWD_WS::visible_ids( $c, 'project' ),
			'visible_tasks'    => NDWD_WS::visible_ids( $c, 'task' ),
			'projects'         => $projects,
			'tasks'            => $tasks,
			'invitations'      => $invites,
		) ) );
	}

	public static function update_connection( WP_REST_Request $r ) {
		$res = NDWD_WS::save_connection( (int) $r['id'], self::body( $r ), self::uid() );
		return is_wp_error( $res ) ? $res : self::connection( $r );
	}

	public static function invite( WP_REST_Request $r ) {
		return self::out( NDWD_WS::invite( self::body( $r ), self::uid() ) );
	}

	public static function revoke( WP_REST_Request $r ) {
		global $wpdb;
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'invitations' ) . ' SET revoked_at = %s WHERE id = %d AND accepted_at IS NULL AND revoked_at IS NULL', NDWD_Helpers::now_mysql(), (int) $r['id'] ) ); // phpcs:ignore
		if ( ! $done ) {
			return NDWD_WS::err( 'This invitation is already used or revoked.', 409 );
		}
		NDWD_WS::audit( 'invitation_revoked', array( 'entity' => 'invitation', 'entity_id' => (int) $r['id'] ), self::uid() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function directory() {
		global $wpdb;
		return rest_ensure_response( array( 'items' => $wpdb->get_results( 'SELECT workspace_uid, name, account_type, plan_code, subscription_status, period_end, sharing_consent_at, synced_at FROM ' . NDWD_WS::t( 'directory' ) . ' ORDER BY name ASC LIMIT 1000' ) ) ); // phpcs:ignore
	}

	/* ---------------------------- Work requests ---------------------------- */

	public static function requests( WP_REST_Request $r ) {
		global $wpdb;
		$status = sanitize_key( (string) $r->get_param( 'status' ) );
		$sql    = 'SELECT * FROM ' . NDWD_WS::t( 'requests' );
		$rows   = $status ? $wpdb->get_results( $wpdb->prepare( $sql . ' WHERE status = %s ORDER BY id DESC LIMIT 300', $status ) ) : $wpdb->get_results( $sql . ' ORDER BY id DESC LIMIT 300' ); // phpcs:ignore
		return rest_ensure_response( array( 'items' => array_map( function ( $q ) {
			return NDWD_WS_Requests::to_array( $q, true );
		}, $rows ) ) );
	}

	public static function request( WP_REST_Request $r ) {
		$q = NDWD_WS_Requests::get( (int) $r['id'] );
		return $q ? rest_ensure_response( NDWD_WS_Requests::to_array( $q, true ) ) : NDWD_WS::err( 'Work request not found.', 404, 'ndwd_not_found' );
	}

	public static function review( WP_REST_Request $r ) {
		$res = NDWD_WS_Requests::review( (int) $r['id'], self::body( $r ), self::uid() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( NDWD_WS_Requests::to_array( $res, true ) );
	}

	public static function comment( WP_REST_Request $r ) {
		$q = NDWD_WS_Requests::get( (int) $r['id'] );
		if ( ! $q ) {
			return NDWD_WS::err( 'Work request not found.', 404, 'ndwd_not_found' );
		}
		$res = NDWD_WS_Requests::add_comment( NDWD_WS::connection( $q->connection_id ), 'request', $q->id, self::body( $r ), 'agency', self::uid() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( NDWD_WS_Requests::to_array( NDWD_WS_Requests::get( $q->id ), true ) );
	}

	public static function deliverables( WP_REST_Request $r ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'deliverables' ) . ' ORDER BY id DESC LIMIT 300' ); // phpcs:ignore
		return rest_ensure_response( array( 'items' => array_map( function ( $d ) {
			$client = NDWD_Clients::get( $d->client_id );
			return array_merge( NDWD_WS_Requests::deliverable_array( $d, true ), array( 'client_name' => $client ? $client->name : '', 'connection_id' => (int) $d->connection_id ) );
		}, $rows ) ) );
	}

	public static function save_deliverable( WP_REST_Request $r ) {
		$res = NDWD_WS_Requests::save_deliverable( isset( $r['id'] ) ? (int) $r['id'] : 0, self::body( $r ), self::uid() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( NDWD_WS_Requests::deliverable_array( $res, true ) );
	}

	/* ---------------------------- Consolidated data ---------------------------- */

	private static function csv( $name, $rows ) {
		$fh = fopen( 'php://temp', 'w+' );
		if ( $rows ) {
			fputcsv( $fh, array_keys( (array) $rows[0] ) );
			foreach ( $rows as $row ) {
				// A leading = + - @ would run as a formula in a spreadsheet.
				fputcsv( $fh, array_map( function ( $v ) {
					return is_string( $v ) && preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
				}, (array) $row ) );
			}
		}
		rewind( $fh );
		return array( 'filename' => $name, 'csv' => stream_get_contents( $fh ) );
	}

	/** Every read and export of consolidated records is logged. */
	private static function consolidated( WP_REST_Request $r, $table, $date_col ) {
		global $wpdb;
		$t    = NDWD_WS::t( $table );
		$q    = trim( sanitize_text_field( (string) $r->get_param( 'q' ) ) );
		$uid  = sanitize_text_field( (string) $r->get_param( 'workspace_uid' ) );
		$sql  = "SELECT * FROM $t WHERE 1=1";
		$vals = array();
		if ( '' !== $uid ) {
			$sql   .= ' AND workspace_uid = %s';
			$vals[] = $uid;
		}
		if ( '' !== $q ) {
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$sql   .= ' AND (business_name LIKE %s OR customer_name LIKE %s OR email LIKE %s OR contact_number LIKE %s)';
			$vals   = array_merge( $vals, array( $like, $like, $like, $like ) );
		}
		$sql .= " ORDER BY $date_col DESC LIMIT 1000";
		$rows = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		$export = (bool) $r->get_param( 'export' );
		NDWD_WS::audit( $export ? 'consolidated_export' : 'consolidated_view', array( 'kind' => $export ? 'export' : 'data_access', 'entity' => $table, 'details' => array( 'rows' => count( $rows ), 'q' => $q ) ), self::uid() );
		if ( $export ) {
			return rest_ensure_response( self::csv( $table . '-' . NDWD_Helpers::today() . '.csv', $rows ) );
		}
		return rest_ensure_response( array( 'items' => $rows, 'retention_days' => (int) NDWD_WS::settings()['retention_days'] ) );
	}

	public static function leads( WP_REST_Request $r ) {
		return self::consolidated( $r, 'leads', 'lead_date' );
	}

	public static function bookings( WP_REST_Request $r ) {
		return self::consolidated( $r, 'bookings', 'booking_at' );
	}

	/** Deliberate retention purge: only on request, only consolidated copies older than the policy. */
	public static function purge( WP_REST_Request $r ) {
		global $wpdb;
		$b = self::body( $r );
		if ( empty( $b['confirm'] ) ) {
			return NDWD_WS::err( 'Confirm the purge.' );
		}
		$days = (int) NDWD_WS::settings()['retention_days'];
		$cut  = gmdate( 'Y-m-d H:i:s', strtotime( NDWD_Helpers::now_mysql() ) - $days * DAY_IN_SECONDS );
		$a    = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NDWD_WS::t( 'leads' ) . ' WHERE synced_at < %s', $cut ) ); // phpcs:ignore
		$c    = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NDWD_WS::t( 'bookings' ) . ' WHERE synced_at < %s', $cut ) ); // phpcs:ignore
		NDWD_WS::audit( 'retention_purge', array( 'kind' => 'data_access', 'details' => array( 'leads' => $a, 'bookings' => $c, 'older_than_days' => $days ) ), self::uid() );
		return rest_ensure_response( array( 'leads' => (int) $a, 'bookings' => (int) $c ) );
	}

	/* ---------------------------- Logs ---------------------------- */

	public static function logs( WP_REST_Request $r ) {
		global $wpdb;
		$kind = (string) $r['kind'];
		$map  = array( 'api' => 'api_log', 'webhooks' => 'webhooks', 'audit' => 'audit', 'backups' => 'backup_log' );
		$sql  = 'SELECT * FROM ' . NDWD_WS::t( $map[ $kind ] );
		$cnt  = 'SELECT COUNT(*) FROM ' . NDWD_WS::t( $map[ $kind ] );
		$sub  = sanitize_key( (string) $r->get_param( 'kind_filter' ) );
		$page = max( 1, absint( $r->get_param( 'page' ) ) );
		$off  = ( $page - 1 ) * 20;
		if ( 'audit' === $kind && $sub ) {
			$tot  = (int) $wpdb->get_var( $wpdb->prepare( $cnt . ' WHERE kind = %s', $sub ) ); // phpcs:ignore
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' WHERE kind = %s ORDER BY id DESC LIMIT 20 OFFSET %d', $sub, $off ) ); // phpcs:ignore
		} else {
			$tot  = (int) $wpdb->get_var( $cnt ); // phpcs:ignore
			$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id DESC LIMIT 20 OFFSET %d', $off ) ); // phpcs:ignore
		}
		if ( 'webhooks' === $kind ) {
			foreach ( $rows as $w ) {
				unset( $w->payload );
			}
		}
		return rest_ensure_response( array( 'items' => $rows, 'page' => $page, 'pages' => (int) ceil( $tot / 20 ), 'total' => $tot ) );
	}

	public static function webhook_retry( WP_REST_Request $r ) {
		$res = NDWD_WS_Webhooks::retry( (int) $r['id'], self::uid() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( array( 'ok' => true ) );
	}

	public static function webhook_run() {
		return rest_ensure_response( NDWD_WS_Webhooks::process() );
	}

	/** A record of a backup taken with the host's tools. This screen takes no backup itself. */
	public static function backup_add( WP_REST_Request $r ) {
		global $wpdb;
		$b    = self::body( $r );
		$date = NDWD_Helpers::sanitize_date( $b['taken_on'] ?? '' );
		if ( ! $date ) {
			return NDWD_WS::err( 'Enter the date of the backup.' );
		}
		$wpdb->insert( NDWD_WS::t( 'backup_log' ), array(
			'backup_type'      => mb_substr( sanitize_key( (string) ( $b['backup_type'] ?? 'database' ) ), 0, 30 ),
			'taken_on'         => $date,
			'location_note'    => mb_substr( sanitize_text_field( (string) ( $b['location_note'] ?? '' ) ), 0, 255 ),
			'restore_verified' => empty( $b['restore_verified'] ) ? 0 : 1,
			'notes'            => sanitize_textarea_field( (string) ( $b['notes'] ?? '' ) ),
			'created_by'       => self::uid(),
			'created_at'       => NDWD_Helpers::now_mysql(),
		) );
		$backup_id = (int) $wpdb->insert_id;
		NDWD_WS::audit( 'backup_logged', array( 'entity' => 'backup', 'entity_id' => $backup_id ), self::uid() );
		return rest_ensure_response( array( 'id' => $backup_id ) );
	}

	/* ---------------------------- Agency calendar ---------------------------- */

	private static function can_edit_event( $e ) {
		$u = self::uid();
		return NDWD_Permissions::can( 'ndwd_view_all', $u ) || NDWD_Permissions::can( 'ndwd_manage_workspace', $u ) || ( $e && (int) $e->created_by === (int) $u );
	}

	public static function save_event( WP_REST_Request $r ) {
		global $wpdb;
		$t  = NDWD_WS::t( 'events' );
		$id = isset( $r['id'] ) ? (int) $r['id'] : 0;
		$e  = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ) : null; // phpcs:ignore
		if ( $id && ! $e ) {
			return NDWD_WS::err( 'Event not found.', 404, 'ndwd_not_found' );
		}
		if ( $e && ! self::can_edit_event( $e ) ) {
			return NDWD_WS::err( 'You cannot change this event.', 403, 'ndwd_forbidden' );
		}
		$b     = self::body( $r );
		$title = mb_substr( sanitize_text_field( (string) ( $b['title'] ?? '' ) ), 0, 255 );
		$start = NDWD_Helpers::sanitize_datetime( str_replace( 'T', ' ', (string) ( $b['starts_at'] ?? '' ) ) );
		if ( '' === $title || ! $start ) {
			return NDWD_WS::err( 'Give the event a title and a start time.' );
		}
		$client_id  = absint( $b['client_id'] ?? 0 );
		$project_id = absint( $b['project_id'] ?? 0 );
		if ( $project_id ) {
			$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $project_id ) ); // phpcs:ignore
			if ( $client_id && (int) $owner !== $client_id ) {
				return NDWD_WS::err( 'That project belongs to another client.' );
			}
			$client_id = $client_id ? $client_id : (int) $owner;
		}
		$vis = 'client' === ( $b['visibility'] ?? '' ) ? 'client' : 'internal';
		if ( 'client' === $vis && ! $client_id ) {
			return NDWD_WS::err( 'A client-visible event needs a client.' );
		}
		if ( 'client' === $vis && ! NDWD_Permissions::can( 'ndwd_manage_workspace', self::uid() ) ) {
			return NDWD_WS::err( 'Only Workspace managers can share events with clients.', 403, 'ndwd_forbidden' );
		}
		$now = NDWD_Helpers::now_mysql();
		$end = NDWD_Helpers::sanitize_datetime( str_replace( 'T', ' ', (string) ( $b['ends_at'] ?? '' ) ) );
		$row = array(
			'title'       => $title,
			'event_type'  => in_array( $b['event_type'] ?? '', array( 'meeting', 'client_meeting', 'milestone', 'deliverable', 'internal' ), true ) ? $b['event_type'] : 'meeting',
			'visibility'  => $vis,
			'client_id'   => $client_id ? $client_id : null,
			'project_id'  => $project_id ? $project_id : null,
			'team_id'     => absint( $b['team_id'] ?? 0 ) ? absint( $b['team_id'] ) : null,
			'member_id'   => absint( $b['member_id'] ?? 0 ) ? absint( $b['member_id'] ) : null,
			'starts_at'   => $start,
			'ends_at'     => $end ? $end : null,
			'description' => sanitize_textarea_field( (string) ( $b['description'] ?? '' ) ),
			'updated_at'  => $now,
		);
		if ( $e ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'created_by' => self::uid(), 'created_at' => $now ) ) );
			$id = (int) $wpdb->insert_id;
		}
		self::notify_event( $e, $row, $id, $e ? 'updated' : 'created' );
		return rest_ensure_response( array( 'id' => $id ) );
	}

	/** Only client-visible events reach Workspace. An event made internal is announced as deleted. */
	private static function notify_event( $old, $row, $id, $what ) {
		$cid = ! empty( $row['client_id'] ) ? $row['client_id'] : ( $old ? $old->client_id : 0 );
		$pid = ! empty( $row['project_id'] ) ? (int) $row['project_id'] : 0;
		$c   = $pid ? NDWD_WS::connection_for_project( $pid, (int) $cid ) : ( $cid ? NDWD_WS::connection_for_client( $cid ) : null );
		if ( ! $c ) {
			return;
		}
		$is_client  = 'client' === $row['visibility'];
		$was_client = $old && 'client' === $old->visibility;
		if ( $is_client ) {
			NDWD_WS_Webhooks::emit( 'calendar.' . $what, $c->id, array( 'event_id' => (int) $id ) );
		} elseif ( $was_client ) {
			NDWD_WS_Webhooks::emit( 'calendar.deleted', $c->id, array( 'event_id' => (int) $id ) );
		}
	}

	public static function delete_event( WP_REST_Request $r ) {
		global $wpdb;
		$t = NDWD_WS::t( 'events' );
		$e = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", (int) $r['id'] ) ); // phpcs:ignore
		if ( ! $e ) {
			return NDWD_WS::err( 'Event not found.', 404, 'ndwd_not_found' );
		}
		if ( ! self::can_edit_event( $e ) ) {
			return NDWD_WS::err( 'You cannot delete this event.', 403, 'ndwd_forbidden' );
		}
		$wpdb->delete( $t, array( 'id' => $e->id ) );
		if ( 'client' === $e->visibility && $e->client_id ) {
			$c = NDWD_WS::connection_for_client( $e->client_id );
			if ( $c ) {
				NDWD_WS_Webhooks::emit( 'calendar.deleted', $c->id, array( 'event_id' => (int) $e->id ) );
			}
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Agency calendar. Tasks go through the existing visibility rules; events and requests are
	 * limited for members to their teams, their own events and clients they can see.
	 */
	public static function calendar( WP_REST_Request $r ) {
		global $wpdb;
		$uid  = self::uid();
		$from = NDWD_Helpers::sanitize_date( $r->get_param( 'from' ) );
		$to   = NDWD_Helpers::sanitize_date( $r->get_param( 'to' ) );
		if ( ! $from || ! $to ) {
			return NDWD_WS::err( 'Give a date range.' );
		}
		$team    = absint( $r->get_param( 'team_id' ) );
		$project = absint( $r->get_param( 'project_id' ) );
		$client  = absint( $r->get_param( 'client_id' ) );
		$member  = absint( $r->get_param( 'member_id' ) );
		$view    = sanitize_key( (string) $r->get_param( 'view' ) ); // project | client | consolidated.
		$all     = NDWD_Permissions::can( 'ndwd_view_all', $uid ) || NDWD_Permissions::can( 'ndwd_manage_workspace', $uid );
		$out     = array();

		$f = array( 'from' => $from, 'to' => $to, 'per_page' => 500 );
		foreach ( array( 'team_id' => $team, 'project_id' => $project, 'client_id' => $client, 'member_id' => $member ) as $k => $v ) {
			if ( $v ) {
				$f[ $k ] = $v;
			}
		}
		$res = NDWD_Tasks::query( $f, $uid );
		foreach ( 'client' === $view ? array() : $res['items'] as $t ) {
			if ( 'project' === $view && empty( $t['project_id'] ) ) {
				continue;
			}
			$out[] = array( 'id' => 'task-' . $t['id'], 'kind' => 'task', 'title' => $t['title'], 'date' => $t['due_date'] ? $t['due_date'] : $t['scheduled_date'], 'status' => $t['status'], 'task_id' => $t['id'] );
		}

		$teams = NDWD_Permissions::user_teams( $uid );
		$sql   = 'SELECT * FROM ' . NDWD_WS::t( 'events' ) . ' WHERE starts_at >= %s AND starts_at <= %s';
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY starts_at ASC LIMIT 500', $from . ' 00:00:00', $to . ' 23:59:59' ) ) as $e ) { // phpcs:ignore
			if ( ( $team && (int) $e->team_id !== $team ) || ( $project && (int) $e->project_id !== $project ) || ( $client && (int) $e->client_id !== $client ) || ( $member && (int) $e->member_id !== $member ) ) {
				continue;
			}
			if ( ! $all && (int) $e->created_by !== (int) $uid && (int) $e->member_id !== (int) $uid && ! in_array( (int) $e->team_id, array_map( 'intval', (array) $teams ), true ) ) {
				continue;
			}
			if ( ( 'client' === $view && 'client' !== $e->visibility ) || ( 'project' === $view && ! $e->project_id ) ) {
				continue;
			}
			$out[] = array( 'id' => 'event-' . $e->id, 'event_id' => (int) $e->id, 'kind' => 'event', 'title' => $e->title, 'date' => substr( $e->starts_at, 0, 10 ), 'starts_at' => $e->starts_at, 'ends_at' => $e->ends_at, 'event_type' => $e->event_type, 'visibility' => $e->visibility, 'client_id' => $e->client_id ? (int) $e->client_id : null, 'project_id' => $e->project_id ? (int) $e->project_id : null, 'team_id' => $e->team_id ? (int) $e->team_id : null, 'member_id' => $e->member_id ? (int) $e->member_id : null, 'description' => (string) $e->description );
		}
		if ( $all && 'client' !== $view ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'requests' ) . " WHERE preferred_date BETWEEN %s AND %s AND status IN ('submitted','in_review')", $from, $to ) ) as $q ) { // phpcs:ignore
				if ( ( $project && (int) $q->project_id !== $project ) || ( $client && (int) $q->client_id !== $client ) ) {
					continue;
				}
				$out[] = array( 'id' => 'req-' . $q->id, 'kind' => 'request', 'title' => 'Request: ' . $q->title, 'date' => $q->preferred_date, 'status' => $q->status, 'request_id' => (int) $q->id );
			}
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'deliverables' ) . ' WHERE due_date BETWEEN %s AND %s', $from, $to ) ) as $d ) { // phpcs:ignore
				if ( ( $project && (int) $d->project_id !== $project ) || ( $client && (int) $d->client_id !== $client ) ) {
					continue;
				}
				$out[] = array( 'id' => 'del-' . $d->id, 'kind' => 'deliverable', 'title' => $d->title, 'date' => $d->due_date, 'status' => $d->status );
			}
		}
		return rest_ensure_response( array( 'items' => $out, 'truncated' => $res['total'] > count( $res['items'] ) ) );
	}

	/* ---------------------------- Operations dashboard ---------------------------- */

	/**
	 * Admins see the whole agency; finance needs Accounts; members see their teams' workload only.
	 */
	public static function ops() {
		global $wpdb;
		$uid     = self::uid();
		$all     = NDWD_Permissions::can( 'ndwd_view_all', $uid );
		$finance = NDWD_Permissions::can( 'ndwd_manage_accounts', $uid );
		$tasks   = NDWD_Helpers::table( 'tasks' );
		$today   = NDWD_Helpers::today();
		$teams   = $all ? null : array_map( 'intval', (array) NDWD_Permissions::user_teams( $uid ) );
		if ( ! $all && ! $teams ) {
			return rest_ensure_response( array( 'scope' => 'none' ) );
		}
		$tt    = NDWD_Helpers::table( 'task_teams' );
		$scope = $all ? '' : ' AND t.id IN (SELECT task_id FROM ' . $tt . ' WHERE team_id IN (' . implode( ',', $teams ) . '))';
		$by    = array();
		foreach ( $wpdb->get_results( "SELECT t.status, COUNT(*) n FROM $tasks t WHERE t.is_archived = 0$scope GROUP BY t.status" ) as $row ) { // phpcs:ignore
			$by[ $row->status ] = (int) $row->n;
		}
		$labels = array();
		foreach ( NDWD_Helpers::STATUSES as $k => $l ) {
			$labels[] = array( 'status' => $k, 'label' => $l, 'count' => $by[ $k ] ?? 0 );
		}
		$open = NDWD_Helpers::open_sql( 't' );
		$week = gmdate( 'Y-m-d', strtotime( $today ) + 7 * DAY_IN_SECONDS );
		$tot  = array_sum( $by );
		$done = ( $by['completed'] ?? 0 );
		$out  = array(
			'scope'          => $all ? 'all' : 'teams',
			'due_today'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tasks t WHERE t.is_archived = 0 AND $open AND t.due_date = %s$scope", $today ) ), // phpcs:ignore
			'active_today'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tasks t WHERE t.is_archived = 0 AND $open AND t.updated_at >= %s$scope", $today . ' 00:00:00' ) ), // phpcs:ignore
			'urgent'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tasks t WHERE t.is_archived = 0 AND $open AND t.priority = 'urgent'$scope" ), // phpcs:ignore
			'upcoming'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tasks t WHERE t.is_archived = 0 AND $open AND t.due_date > %s AND t.due_date <= %s$scope", $today, $week ) ), // phpcs:ignore
			'completion'     => $tot ? (int) round( $done * 100 / $tot ) : 0,
			'pending_enquiries' => NDWD_Permissions::can( 'ndwd_handle_enquiries', $uid ) ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . NDWD_Helpers::table( 'enquiries' ) . " WHERE status NOT IN ('won','lost')" ) : null, // phpcs:ignore
			'tasks_by_status' => $labels,
			'overdue'        => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tasks t WHERE t.is_archived = 0 AND $open AND t.due_date < %s$scope", $today ) ), // phpcs:ignore
			'deadlines'      => $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.title, t.due_date, t.status FROM $tasks t WHERE t.is_archived = 0 AND $open AND t.due_date >= %s$scope ORDER BY t.due_date ASC LIMIT 8", $today ) ), // phpcs:ignore
		);
		$a_t = NDWD_Helpers::table( 'task_assignees' );
		$out['workload'] = array_map( function ( $w ) {
			return array( 'user_id' => (int) $w->user_id, 'name' => NDWD_Helpers::user_name( $w->user_id ), 'open_tasks' => (int) $w->n );
		}, $wpdb->get_results( "SELECT a.user_id, COUNT(*) n FROM $a_t a JOIN $tasks t ON t.id = a.task_id WHERE t.is_archived = 0 AND $open$scope GROUP BY a.user_id ORDER BY n DESC LIMIT 12" ) ); // phpcs:ignore
		if ( $all ) {
			$p    = NDWD_Helpers::table( 'projects' );
			$req  = NDWD_WS::t( 'requests' );
			$conn = NDWD_WS::t( 'connections' );
			$out += array(
				'total_clients'        => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . NDWD_Helpers::table( 'clients' ) . ' WHERE is_active = 1' ), // phpcs:ignore
				'active_agency_clients' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $conn WHERE status = 'active'" ), // phpcs:ignore
				'active_projects'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $p WHERE is_active = 1 AND is_archived = 0" ), // phpcs:ignore
				'completed_projects'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $p WHERE is_active = 0 AND is_archived = 0" ), // phpcs:ignore
				'pending_requests'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $req WHERE status IN ('submitted','in_review')" ), // phpcs:ignore
				'recent_activity'      => array_map( function ( $x ) {
					return array( 'who' => NDWD_Helpers::user_name( $x->user_id ), 'action' => $x->action, 'task_id' => $x->task_id ? (int) $x->task_id : null, 'at' => $x->created_at );
				}, $wpdb->get_results( 'SELECT user_id, action, task_id, created_at FROM ' . NDWD_Helpers::table( 'activity' ) . " WHERE is_meaningful = 1 AND action NOT IN ('login','session_resumed') ORDER BY id DESC LIMIT 10" ) ), // phpcs:ignore
			);
		}
		if ( $finance ) {
			$inv   = NDWD_Helpers::table( 'invoices' );
			$usage = NDWD_Packages::utilization();
			$month = substr( $today, 0, 7 );
			$out['finance'] = array(
				'invoiced_this_month' => (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(total),0) FROM $inv WHERE status <> 'cancelled' AND invoice_date LIKE %s", $month . '%' ) ), // phpcs:ignore
				'received_this_month' => (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . NDWD_Helpers::table( 'income' ) . ' WHERE received_on LIKE %s AND voided_at IS NULL', $month . '%' ) ), // phpcs:ignore
				'pending_payments'    => (float) $wpdb->get_var( "SELECT COALESCE(SUM(total - amount_paid - amount_adjusted - amount_transferred),0) FROM $inv WHERE status NOT IN ('cancelled','paid')" ), // phpcs:ignore
			);
			$out['package_usage'] = is_array( $usage ) && isset( $usage['items'] ) ? array_slice( $usage['items'], 0, 8 ) : ( is_array( $usage ) ? array_slice( $usage, 0, 8 ) : array() );
		}
		return rest_ensure_response( $out );
	}
}
