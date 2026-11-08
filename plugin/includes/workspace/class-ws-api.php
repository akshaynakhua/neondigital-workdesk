<?php
/**
 * Server-to-server API for Neon Workspace (namespace workdesk/v1). The existing ndwd/v1 app API
 * is untouched.
 *
 * Authentication: HMAC-SHA256 over method, route, sorted query, timestamp, nonce and body hash
 * (headers X-Neon-Key, X-Neon-Timestamp, X-Neon-Nonce, X-Neon-Signature). Two credential types:
 *   site key       - trusts the Workspace install: invitation linking and consolidated sync;
 *   connection key - issued at linking, scoped to ONE client. The client is always derived from
 *                    the key, never from the request, so one client cannot ask for another's data.
 * Replay protection: timestamp within 5 minutes and a nonce that can be used once.
 * Output is an allow-list per endpoint. Internal remarks, assignees and notes are never sent.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_API {

	const NS         = 'workdesk/v1';
	const MAX_SKEW   = 300;
	const RATE_LIMIT = 300; // Signed requests per key per minute.

	/** @var object|null Connection of the current request. */
	private static $conn = null;
	private static $key  = '';
	/**
	 * Set once the endpoint has run. WordPress calls permission callbacks again afterwards to build
	 * the Allow header, which must neither burn a nonce nor write refusals to the log.
	 */
	private static $handled = false;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ---------------------------- Auth ---------------------------- */

	private static function deny( $msg, $status, $code, WP_REST_Request $r = null ) {
		self::log( $r, $status, $code );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'mark_handled' ), 1 );
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	private static function log( $r, $status, $error = '' ) {
		global $wpdb;
		$wpdb->insert( NDWD_WS::t( 'api_log' ), array(
			'connection_id' => self::$conn ? (int) self::$conn->id : null,
			'api_key'       => mb_substr( (string) self::$key, 0, 64 ),
			'method'        => $r ? $r->get_method() : '',
			'route'         => $r ? mb_substr( $r->get_route(), 0, 190 ) : '',
			'status'        => (int) $status,
			'error'         => mb_substr( (string) $error, 0, 190 ),
			'ip'            => NDWD_WS::ip(),
			'created_at'    => NDWD_Helpers::now_mysql(),
		) );
	}

	/** The Allow header filter runs at priority 10, after this. */
	public static function mark_handled( $response ) {
		self::$handled = true;
		return $response;
	}

	/** Lets one PHP process (the test suite) serve several signed requests. */
	public static function reset_request_state() {
		self::$handled = false;
		self::$conn    = null;
		self::$key     = '';
		remove_filter( 'rest_post_dispatch', array( __CLASS__, 'mark_handled' ), 1 );
	}

	/** Verifies signature, freshness and nonce. @return true|WP_Error */
	private static function verify( WP_REST_Request $r, $secret ) {
		$ts    = (string) $r->get_header( 'x_neon_timestamp' );
		$nonce = (string) $r->get_header( 'x_neon_nonce' );
		$sig   = (string) $r->get_header( 'x_neon_signature' );
		if ( ! ctype_digit( $ts ) || abs( time() - (int) $ts ) > self::MAX_SKEW ) {
			return self::deny( 'Request timestamp is missing or too old.', 401, 'ndwd_stale', $r );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return self::deny( 'Invalid nonce.', 401, 'ndwd_bad_nonce', $r );
		}
		$q = $r->get_query_params();
		unset( $q['rest_route'] ); // Present only on sites without pretty permalinks; not part of the signature.
		ksort( $q );
		$expected = NDWD_WS_Webhooks::sign( $secret, $r->get_method(), $r->get_route(), $r->get_body(), $ts, $nonce, http_build_query( $q ) );
		if ( '' === $secret || ! hash_equals( $expected, $sig ) ) {
			return self::deny( 'Invalid signature.', 401, 'ndwd_bad_signature', $r );
		}
		global $wpdb;
		$hash = hash( 'sha256', self::$key . '|' . $nonce );
		$wpdb->suppress_errors( true );
		$ok = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . NDWD_WS::t( 'nonces' ) . ' (nonce_hash, expires_at) VALUES (%s, %d)', $hash, time() + 2 * self::MAX_SKEW ) ); // phpcs:ignore
		$wpdb->suppress_errors( false );
		if ( ! $ok ) {
			return self::deny( 'This request was already processed (replay).', 409, 'ndwd_replay', $r );
		}
		return self::rate_ok( self::$key ) ? true : self::deny( 'Too many requests.', 429, 'ndwd_rate_limited', $r );
	}

	private static function rate_ok( $bucket ) {
		$k = 'ndwd_ws_rl_' . md5( $bucket . gmdate( 'YmdHi' ) );
		$n = (int) get_transient( $k );
		set_transient( $k, $n + 1, 120 );
		return $n < self::RATE_LIMIT;
	}

	public static function auth_site( WP_REST_Request $r ) {
		if ( self::$handled ) {
			return true;
		}
		self::$conn = null;
		self::$key  = (string) $r->get_header( 'x_neon_key' );
		list( $key, $secret ) = NDWD_WS::site_credentials();
		if ( '' === $key || '' === $secret || ! hash_equals( $key, self::$key ) ) {
			return self::deny( 'Unknown API key.', 401, 'ndwd_bad_key', $r );
		}
		return self::verify( $r, $secret );
	}

	public static function auth_connection( WP_REST_Request $r ) {
		if ( self::$handled ) {
			return true;
		}
		self::$conn = null;
		self::$key  = (string) $r->get_header( 'x_neon_key' );
		$conn       = '' !== self::$key ? NDWD_WS::connection_by_key( self::$key ) : null;
		if ( ! $conn ) {
			return self::deny( 'Unknown API key.', 401, 'ndwd_bad_key', $r );
		}
		self::$conn = $conn;
		$v = self::verify( $r, NDWD_WS::unseal( $conn->api_secret ) );
		if ( is_wp_error( $v ) ) {
			return $v;
		}
		global $wpdb;
		$wpdb->update( NDWD_WS::t( 'connections' ), array( 'last_seen_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $conn->id ) );
		return true;
	}

	/** A suspended or Agency-Connect-less connection keeps only account-level reads. */
	private static function auth_agency( WP_REST_Request $r, $perm = '', $feature = 'agency_connect' ) {
		if ( self::$handled ) {
			return true;
		}
		$a = self::auth_connection( $r );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		$c = self::$conn;
		if ( 'active' !== $c->status ) {
			return self::deny( 'This account is suspended. Contact NeonDigital.', 403, 'ndwd_suspended', $r );
		}
		if ( ! (int) $c->agency_connect ) {
			return self::deny( 'NeonDigital Connect is not enabled for this client.', 403, 'ndwd_no_agency_connect', $r );
		}
		if ( $feature && ! self::feature_live( $c, $feature ) ) {
			return self::deny( 'This feature is not active for this client.', 403, 'ndwd_feature_locked', $r );
		}
		if ( $perm && ! NDWD_WS::perms( $c )[ $perm ] ) {
			return self::deny( 'NeonDigital has not enabled this for your account.', 403, 'ndwd_not_permitted', $r );
		}
		return true;
	}

	/**
	 * Project reads: a project moved to its own subscription keeps working after the agency feature
	 * ends. Every other project stays locked, which project_open() decides for each one.
	 */
	public static function auth_project( WP_REST_Request $r ) {
		if ( self::$handled ) {
			return true;
		}
		$a = self::auth_connection( $r );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		$c = self::$conn;
		if ( 'active' !== $c->status ) {
			return self::deny( 'This account is suspended. Contact NeonDigital.', 403, 'ndwd_suspended', $r );
		}
		if ( ( ! (int) $c->agency_connect || ! self::feature_live( $c, 'agency_connect' ) ) && ! NDWD_WS_Projects::independent_count( $c ) ) {
			return self::deny( (int) $c->agency_connect ? 'This feature is not active for this client.' : 'NeonDigital Connect is not enabled for this client.', 403, (int) $c->agency_connect ? 'ndwd_feature_locked' : 'ndwd_no_agency_connect', $r );
		}
		return true;
	}

	/**
	 * Listing and opening projects. A client always sees their projects, so this holds even when no
	 * feature is live yet: each project then answers for itself, locked and with no details.
	 */
	public static function auth_project_list( WP_REST_Request $r ) {
		if ( self::$handled ) {
			return true;
		}
		$a = self::auth_connection( $r );
		if ( is_wp_error( $a ) ) {
			return $a;
		}
		$c = self::$conn;
		if ( 'active' !== $c->status ) {
			return self::deny( 'This account is suspended. Contact NeonDigital.', 403, 'ndwd_suspended', $r );
		}
		if ( ! (int) $c->agency_connect && ! NDWD_WS_Projects::independent_count( $c ) ) {
			return self::deny( 'NeonDigital Connect is not enabled for this client.', 403, 'ndwd_no_agency_connect', $r );
		}
		return true;
	}

	/** The one signed call that stands apart from the agency gate: paying for one project. */
	public static function current_connection() {
		return self::$conn;
	}

	public static function feature_live( $conn, $code ) {
		foreach ( NDWD_WS::entitlements( $conn ) as $e ) {
			if ( $e['feature_code'] === $code ) {
				return in_array( $e['state'], array( 'active', 'renewal_due', 'grace' ), true );
			}
		}
		return false;
	}

	private static function route( $path, $methods, $cb, $auth ) {
		register_rest_route( self::NS, $path, array(
			'methods'             => $methods,
			'permission_callback' => $auth,
			'callback'            => function ( WP_REST_Request $r ) use ( $cb ) {
				$res    = call_user_func( array( __CLASS__, $cb ), $r );
				$status = is_wp_error( $res ) ? (int) ( $res->get_error_data()['status'] ?? 400 ) : 200;
				self::log( $r, $status, is_wp_error( $res ) ? $res->get_error_code() : '' );
				self::$handled = true;
				if ( is_wp_error( $res ) ) {
					return $res;
				}
				if ( $res instanceof WP_REST_Response ) {
					$res->set_data( self::utc_times( $res->get_data() ) );
					return $res;
				}
				return rest_ensure_response( self::utc_times( $res ) );
			},
		) );
	}

	/**
	 * WorkDesk stores times as Asia/Kolkata wall-clock time (NDWD_TZ), whatever the WordPress
	 * timezone setting says. Every "..._at" time leaves here converted from that zone to UTC and
	 * marked with "Z", so Workspace converts it once, to the viewer's timezone, and both apps show
	 * the same moment. Stored rows are never rewritten, so old and new messages are both right.
	 * (Up to 1.17 this used get_gmt_from_date(), which follows the WordPress setting: on a site left
	 * at UTC nothing was converted and Workspace showed every message 5h30 late.)
	 */
	private static function utc_times( $v, $key = '' ) {
		if ( is_object( $v ) ) {
			$v = get_object_vars( $v );
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::utc_times( $x, (string) $k );
			}
			return $v;
		}
		if ( is_string( $v ) && ( 'at' === $key || '_at' === substr( $key, -3 ) ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v ) ) {
			return NDWD_Helpers::to_utc_iso( $v );
		}
		return $v;
	}

	public static function routes() {
		$conn = array( __CLASS__, 'auth_connection' );
		$site = array( __CLASS__, 'auth_site' );
		$a    = function ( $perm = '', $feature = 'agency_connect' ) {
			return function ( WP_REST_Request $r ) use ( $perm, $feature ) {
				return self::auth_agency( $r, $perm, $feature );
			};
		};
		self::route( '/health', 'GET', 'health', $site );
		self::route( '/link', 'POST', 'link', $site );
		self::route( '/invitations/preview', 'POST', 'invitation_preview', $site );
		self::route( '/sync/workspaces', 'POST', 'sync_workspaces', $site );
		self::route( '/sync/leads', 'POST', 'sync_leads', $site );
		self::route( '/sync/bookings', 'POST', 'sync_bookings', $site );

		self::route( '/connection/health', 'GET', 'health', $conn );
		self::route( '/client', 'GET', 'client', $conn );
		self::route( '/entitlements', 'GET', 'entitlements', $conn );
		self::route( '/invoices', 'GET', 'invoices', $conn );
		self::route( '/invoices/(?P<id>\d+)/pdf', 'GET', 'invoice_pdf', $conn );

		self::route( '/overview', 'GET', 'overview', $a() );
		self::route( '/packages', 'GET', 'packages', $a() );
		$proj = array( __CLASS__, 'auth_project' );
		self::route( '/sync/catalogue', 'POST', 'sync_catalogue', $site );
		$list = array( __CLASS__, 'auth_project_list' );
		self::route( '/projects', 'GET', 'projects', $list );
		self::route( '/projects/(?P<id>\d+)', 'GET', 'project', $list );
		self::route( '/migrations/(?P<id>\d+)/activate', 'POST', 'migration_activate', $proj );
		self::route( '/tasks', 'GET', 'tasks', $proj );
		self::route( '/tasks/(?P<id>\d+)/comments', 'GET', 'task_comments', $proj );
		self::route( '/tasks/(?P<id>\d+)/comments', 'POST', 'task_comment_add', $proj );
		self::route( '/pulse', 'GET', 'pulse', $proj );
		self::route( '/calendar', 'GET', 'calendar', $a( 'calendar', 'calendar_agency' ) );
		self::route( '/work-requests', 'GET', 'requests', $a() );
		self::route( '/work-requests', 'POST', 'request_create', $a( 'work_requests' ) );
		self::route( '/work-requests/(?P<id>\d+)', 'GET', 'request_one', $a() );
		self::route( '/work-requests/(?P<id>\d+)/comments', 'POST', 'request_comment', $a( 'work_requests' ) );
		self::route( '/work-requests/(?P<id>\d+)/cancel', 'POST', 'request_cancel', $a( 'work_requests' ) );
		self::route( '/deliverables', 'GET', 'deliverables', $a() );
		self::route( '/deliverables/(?P<id>\d+)', 'GET', 'deliverable', $a() );
		self::route( '/deliverables/(?P<id>\d+)/decision', 'POST', 'deliverable_decide', $a( 'approvals' ) );
		self::route( '/deliverables/(?P<id>\d+)/comments', 'POST', 'deliverable_comment', $a( 'approvals' ) );
		self::route( '/attachments/(?P<id>\d+)', 'GET', 'attachment_download', $list );
		self::route( '/projects/(?P<id>\d+)/attachments', 'POST', 'project_attachment_add', $a( 'work_requests' ) );
	}

	private static function body( WP_REST_Request $r ) {
		$b = json_decode( (string) $r->get_body(), true );
		return is_array( $b ) ? $b : array();
	}

	/* ---------------------------- Site endpoints ---------------------------- */

	public static function health() {
		$c = self::$conn;
		return array( 'ok' => true, 'api_version' => 'v1', 'workdesk_version' => NDWD_VERSION, 'time' => time(), 'connection' => $c ? array( 'status' => $c->status, 'agency_connect' => (bool) $c->agency_connect ) : null );
	}

	public static function link( WP_REST_Request $r ) {
		$b   = self::body( $r );
		$res = NDWD_WS::accept( isset( $b['token'] ) ? $b['token'] : '', isset( $b['workspace_uid'] ) ? $b['workspace_uid'] : '', isset( $b['workspace_name'] ) ? $b['workspace_name'] : '', isset( $b['email'] ) ? $b['email'] : '' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::$conn = $res['connection'];
		return array( 'api_key' => $res['api_key'], 'api_secret' => $res['api_secret'], 'client' => self::client_array( $res['connection'] ), 'entitlements' => NDWD_WS::entitlements( $res['connection'] ), 'business_category' => (string) ( $res['connection']->business_category ?? '' ) );
	}

	/** What Workspace may show on the invitation page. The token is the only input it trusts. */
	public static function invitation_preview( WP_REST_Request $r ) {
		$b = self::body( $r );
		return NDWD_WS::preview( isset( $b['token'] ) ? $b['token'] : '' );
	}

	/** Directory of workspaces: only the listed fields, and consent to sharing is recorded here. */
	public static function sync_workspaces( WP_REST_Request $r ) {
		global $wpdb;
		$t = NDWD_WS::t( 'directory' );
		$n = 0;
		foreach ( array_slice( (array) ( self::body( $r )['items'] ?? array() ), 0, 500 ) as $i ) {
			$uid = sanitize_text_field( (string) ( $i['workspace_uid'] ?? '' ) );
			if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $uid ) ) {
				continue;
			}
			$row = array(
				'name'                => mb_substr( sanitize_text_field( (string) ( $i['name'] ?? '' ) ), 0, 190 ),
				'account_type'        => 'agency' === ( $i['account_type'] ?? '' ) ? 'agency' : 'independent',
				'plan_code'           => mb_substr( sanitize_key( (string) ( $i['plan_code'] ?? '' ) ), 0, 60 ),
				'subscription_status' => mb_substr( sanitize_key( (string) ( $i['subscription_status'] ?? '' ) ), 0, 20 ),
				'period_start'        => NDWD_Helpers::sanitize_date( $i['period_start'] ?? '' ) ?: null,
				'period_end'          => NDWD_Helpers::sanitize_date( $i['period_end'] ?? '' ) ?: null,
				'billing_cycle'       => 'year' === ( $i['billing_cycle'] ?? '' ) ? 'year' : ( 'month' === ( $i['billing_cycle'] ?? '' ) ? 'month' : '' ),
				'grace_days'          => isset( $i['grace_days'] ) && '' !== $i['grace_days'] ? absint( $i['grace_days'] ) : null,
				'extended_until'      => NDWD_Helpers::sanitize_date( $i['extended_until'] ?? '' ) ?: null,
				'owner_hash'          => preg_match( '/^[a-f0-9]{64}$/', (string) ( $i['owner_hash'] ?? '' ) ) ? $i['owner_hash'] : '',
				'sharing_consent_at'  => NDWD_Helpers::sanitize_datetime( $i['sharing_consent_at'] ?? '' ) ?: null,
				'business_category'   => mb_substr( sanitize_key( (string) ( $i['business_category'] ?? '' ) ), 0, 60 ),
				'workspace_status'    => mb_substr( sanitize_key( (string) ( $i['workspace_status'] ?? '' ) ), 0, 20 ),
				'synced_at'           => NDWD_Helpers::now_mysql(),
			);
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE workspace_uid = %s", $uid ) ); // phpcs:ignore
			if ( $id ) {
				$wpdb->update( $t, $row, array( 'id' => $id ) );
			} else {
				$wpdb->insert( $t, array_merge( $row, array( 'workspace_uid' => $uid ) ) );
			}
			$n++;
		}
		return array( 'synced' => $n );
	}

	/**
	 * Phase 5: leads and bookings are accepted only from Workspaces NeonDigital manages for a client
	 * (a live agency link), as part of that service. There is no optional sharing switch any more,
	 * and a self-purchased Workspace never sends them.
	 */
	private static function has_consent( $uid ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_WS::t( 'connections' ) . " WHERE workspace_uid = %s AND linked_at IS NOT NULL AND status IN ('active','suspended')", $uid ) ); // phpcs:ignore
	}

	/**
	 * Idempotent upsert keyed on (workspace_uid, source_id). Only allow-listed fields are stored;
	 * anything else in the payload is dropped. {deleted:true} removes the consolidated copy.
	 */
	private static function upsert( $table, $items, $fields ) {
		global $wpdb;
		$t   = NDWD_WS::t( $table );
		$out = array( 'created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0 );
		foreach ( array_slice( (array) $items, 0, 500 ) as $i ) {
			$uid = sanitize_text_field( (string) ( $i['workspace_uid'] ?? '' ) );
			$sid = absint( $i['source_id'] ?? 0 );
			if ( ! $sid || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $uid ) || ! self::has_consent( $uid ) ) {
				$out['skipped']++;
				continue;
			}
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE workspace_uid = %s AND source_id = %d", $uid, $sid ) ); // phpcs:ignore
			if ( ! empty( $i['deleted'] ) ) {
				if ( $id ) {
					$wpdb->delete( $t, array( 'id' => $id ) );
					$out['deleted']++;
				}
				continue;
			}
			$row = array( 'synced_at' => NDWD_Helpers::now_mysql() );
			foreach ( $fields as $f => $len ) {
				if ( 'date' === $len ) {
					$row[ $f ] = NDWD_Helpers::sanitize_datetime( $i[ $f ] ?? '' ) ?: null;
				} elseif ( 'bool' === $len ) {
					$row[ $f ] = empty( $i[ $f ] ) ? 0 : 1;
				} elseif ( 'email' === $f ) {
					$row[ $f ] = sanitize_email( (string) ( $i[ $f ] ?? '' ) );
				} else {
					$row[ $f ] = mb_substr( sanitize_text_field( (string) ( $i[ $f ] ?? '' ) ), 0, $len );
				}
			}
			if ( $id ) {
				$wpdb->update( $t, $row, array( 'id' => $id ) );
				$out['updated']++;
			} else {
				$wpdb->insert( $t, array_merge( $row, array( 'workspace_uid' => $uid, 'source_id' => $sid ) ) );
				$out['created']++;
			}
		}
		return $out;
	}

	/** Master catalogue from Workspace. Safe to repeat: only a real change makes a new version. */
	public static function sync_catalogue( WP_REST_Request $r ) {
		$b = self::body( $r );
		return NDWD_WS_Projects::upsert_catalogue( (array) ( $b['items'] ?? array() ), ! empty( $b['full'] ) );
	}

	public static function migration_activate( WP_REST_Request $r ) {
		return NDWD_WS_Projects::activate_migration( self::$conn, (int) $r['id'], self::body( $r ) );
	}

	public static function sync_leads( WP_REST_Request $r ) {
		return self::upsert( 'leads', self::body( $r )['items'] ?? array(), array( 'business_name' => 190, 'customer_name' => 190, 'contact_number' => 40, 'email' => 190, 'enquiry' => 255, 'lead_type' => 40, 'lead_source' => 60, 'status' => 20, 'lead_date' => 'date', 'assigned_to' => 190, 'marketing_consent' => 'bool' ) );
	}

	public static function sync_bookings( WP_REST_Request $r ) {
		return self::upsert( 'bookings', self::body( $r )['items'] ?? array(), array( 'business_name' => 190, 'customer_name' => 190, 'contact_number' => 40, 'email' => 190, 'service' => 190, 'booking_type' => 40, 'booking_at' => 'date', 'status' => 20 ) );
	}

	/* ---------------------------- Client-scoped reads ---------------------------- */

	public static function client_array( $c ) {
		$client = NDWD_Clients::get( $c->client_id );
		return array(
			'name'           => $client ? $client->name : '',
			'company'        => $client ? $client->company : '',
			'status'         => $c->status,
			'agency_connect' => (bool) $c->agency_connect,
			'package_start'  => $c->package_start,
			'package_end'    => $c->extended_until ? $c->extended_until : $c->package_end,
			'grace_days'     => (int) $c->grace_days,
			'package_state'  => NDWD_WS::package_state( $c ),
			'permissions'    => NDWD_WS::perms( $c ),
			'contact_label'  => 'Contact NeonDigital to Unlock',
			'independent_projects' => NDWD_WS_Projects::independent_count( $c ),
		);
	}

	public static function client() {
		return self::client_array( self::$conn );
	}

	public static function entitlements() {
		return array( 'items' => NDWD_WS::entitlements( self::$conn ), 'client' => self::client_array( self::$conn ) );
	}

	/** Is this task exposed to this connection? Used by reads and by webhooks. */
	public static function task_visible( $conn, $task ) {
		if ( (int) $task->client_id !== (int) $conn->client_id || (int) $task->is_archived ) {
			return false;
		}
		// Only the task's own switch exposes it. New and older tasks have none, so they stay internal.
		$flags = NDWD_WS_TaskLink::flags( $task->id );
		if ( ! $flags['exists'] || ! $flags['visible'] ) {
			return false;
		}
		// Each task shows in one Workspace, and nothing from a locked project reaches the client.
		$home = NDWD_WS::connection_for_task( $task );
		if ( ! $home || (int) $home->id !== (int) $conn->id ) {
			return false;
		}
		return ! $task->project_id || NDWD_WS_Projects::project_open( $conn, (int) $task->project_id );
	}

	private static function visible_tasks( $conn, $project_id = 0 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, status, priority, project_id, team_id, client_id, is_archived, scheduled_date, due_date, completed_at, updated_at FROM ' . NDWD_Helpers::table( 'tasks' ) . ' WHERE client_id = %d AND is_archived = 0 ORDER BY scheduled_date DESC, id DESC LIMIT 500', $conn->client_id ) ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $t ) {
			if ( self::task_visible( $conn, $t ) && ( ! $project_id || (int) $t->project_id === (int) $project_id ) ) {
				$out[] = NDWD_WS_TaskLink::client_task( $conn, $t );
			}
		}
		return $out;
	}

	private static function project_array( $p, $conn ) {
		$tasks = self::visible_tasks( $conn, $p->id );
		$done  = count( array_filter( $tasks, function ( $t ) {
			return 'completed' === $t['status'];
		} ) );
		return array(
			'id'          => (int) $p->id,
			'name'        => $p->name,
			'parent_id'   => $p->parent_id ? (int) $p->parent_id : null,
			'parent_name' => $p->parent_id ? self::project_name( (int) $p->parent_id ) : '',
			'description' => (string) $p->description,
			'status'      => (int) $p->is_archived ? 'archived' : ( (int) $p->is_active ? 'active' : 'completed' ),
			'task_total'  => count( $tasks ),
			'task_done'   => $done,
		);
	}

	private static function project_name( $id ) {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/** Open projects of this Workspace. */
	private static function visible_projects( $conn ) {
		return array_values( array_filter( NDWD_WS_Projects::projects_of( $conn ), function ( $p ) use ( $conn ) {
			return NDWD_WS_Projects::project_open( $conn, (int) $p->id );
		} ) );
	}

	/**
	 * Every project of this Workspace. A locked one carries its name and why it is locked, and
	 * nothing else, so a client sees it exists without seeing any of its work.
	 */
	public static function projects() {
		$c = self::$conn;
		return array( 'items' => array_map( function ( $p ) use ( $c ) {
			$access = NDWD_WS_Projects::access( $c, (int) $p->id );
			$base   = $access['open'] ? self::project_array( $p, $c ) : array( 'id' => (int) $p->id, 'name' => $p->name );
			return array_merge( $base, array( 'parent_id' => $p->parent_id ? (int) $p->parent_id : null, 'locked' => ! $access['open'], 'access' => NDWD_WS_Projects::client_access( $access ) ) );
		}, NDWD_WS_Projects::projects_of( $c ) ) );
	}

	public static function project( WP_REST_Request $r ) {
		global $wpdb;
		$c  = self::$conn;
		$id = (int) $r['id'];
		$p  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d AND client_id = %d AND is_archived = 0', $id, $c->client_id ) ); // phpcs:ignore
		$home = $p ? NDWD_WS::connection_for_project( $id, (int) $c->client_id ) : null;
		if ( ! $p || ! $home || (int) $home->id !== (int) $c->id ) {
			return NDWD_WS::err( 'Project not found.', 404, 'ndwd_not_found' );
		}
		$access = NDWD_WS_Projects::access( $c, $id );
		if ( ! $access['open'] ) {
			return new WP_Error( 'ndwd_project_locked', $access['message'], array( 'status' => 403 ) );
		}
		$deliv = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'deliverables' ) . ' WHERE connection_id = %d AND project_id = %d AND client_visible = 1 ORDER BY id DESC', $c->id, $id ) ); // phpcs:ignore
		$reqs  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE connection_id = %d AND project_id = %d ORDER BY id DESC LIMIT 100', $c->id, $id ) ); // phpcs:ignore
		// Sub-projects of this project that live in this same Workspace (one with its own Workspace is shown there).
		$subs = array_values( array_filter( NDWD_WS_Projects::projects_of( $c ), function ( $x ) use ( $id ) {
			return (int) $x->parent_id === (int) $id;
		} ) );
		return array_merge( self::project_array( $p, $c ), array(
			'parent'       => $p->parent_id ? array( 'id' => (int) $p->parent_id, 'name' => self::project_name( (int) $p->parent_id ) ) : null,
			'subprojects'  => array_map( function ( $x ) use ( $c ) {
				$ac = NDWD_WS_Projects::access( $c, (int) $x->id );
				return array( 'id' => (int) $x->id, 'name' => $x->name, 'locked' => ! $ac['open'] );
			}, $subs ),
			'access'       => NDWD_WS_Projects::client_access( $access ),
			'tasks'        => self::visible_tasks( $c, $id ),
			'requests'     => array_map( array( 'NDWD_WS_Requests', 'to_array' ), $reqs ),
			'deliverables' => array_map( array( 'NDWD_WS_Requests', 'deliverable_array' ), $deliv ),
			'attachments'  => NDWD_WS_Attachments::for_entity( 'project', $id ),
			'attachment_rules' => NDWD_WS_Attachments::rules(),
			'events'       => self::events( $c, '', '', $id ),
		) );
	}

	public static function tasks() {
		return array( 'items' => self::visible_tasks( self::$conn ) );
	}

	private static function own_visible_task( $id ) {
		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'tasks' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
		return $t && self::task_visible( self::$conn, $t ) ? $t : null;
	}

	public static function task_comments( WP_REST_Request $r ) {
		$t = self::own_visible_task( (int) $r['id'] );
		if ( ! $t ) {
			return self::deny( 'Task not found.', 404, 'ndwd_not_found', $r );
		}
		return array( 'task' => NDWD_WS_TaskLink::client_task( self::$conn, $t ), 'comments' => NDWD_WS_TaskLink::client_comments( self::$conn, $t ) );
	}

	public static function task_comment_add( WP_REST_Request $r ) {
		$t = self::own_visible_task( (int) $r['id'] );
		if ( ! $t || ! NDWD_WS_TaskLink::flags( $t->id )['show_comments'] ) {
			return self::deny( 'Comments are not open on this task.', 403, 'ndwd_forbidden', $r );
		}
		if ( ! NDWD_WS_TaskLink::chat_open( $t ) ) {
			return NDWD_WS::err( 'Chat opens once a NeonDigital team member starts it.', 409, 'ndwd_chat_inactive' );
		}
		$b = self::body( $r );
		unset( $b['is_internal'] ); // A client can never post into internal comments.
		$c = NDWD_WS_Requests::add_comment( self::$conn, 'task', $t->id, $b );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		NDWD_WS_TaskLink::on_client_message( $t, (int) $c, $b );
		return array( 'id' => (int) $c, 'comments' => NDWD_WS_TaskLink::client_comments( self::$conn, $t ) );
	}

	public static function pulse() {
		return NDWD_WS_TaskLink::pulse( self::$conn );
	}

	public static function packages() {
		$c   = self::$conn;
		$out = array();
		if ( ! NDWD_WS::perms( $c )['usage'] ) {
			return array( 'items' => array(), 'hidden' => true );
		}
		foreach ( NDWD_Packages::for_client( $c->client_id ) as $a ) {
			$a = is_array( $a ) ? $a : NDWD_Packages::allocation_array( $a );
			$items = array_map( function ( $i ) {
				return array(
					'id'          => $i['id'],
					'service'     => $i['service_name'],
					'unit_plural' => $i['unit_plural'],
					'total'       => $i['allocated'] + $i['adjusted'],
					'reserved'    => $i['reserved'],
					'consumed'    => $i['consumed'],
					'available'   => max( 0, $i['remaining'] ),
				);
			}, array_values( array_filter( $a['items'], function ( $i ) {
				return $i['is_active'];
			} ) ) );
			$out[] = array( 'id' => $a['id'], 'name' => $a['name'], 'type' => $a['type_label'], 'status' => $a['status_label'], 'start_date' => $a['start_date'], 'end_date' => $a['end_date'], 'cycle' => $a['cycle'], 'items' => $items );
		}
		return array( 'items' => $out );
	}

	public static function overview() {
		global $wpdb;
		$c     = self::$conn;
		$tasks = self::visible_tasks( $c );
		$count = function ( $s ) use ( $tasks ) {
			return count( array_filter( $tasks, function ( $t ) use ( $s ) {
				return $t['status'] === $s;
			} ) );
		};
		$today = NDWD_Helpers::today();
		$dead  = array_values( array_filter( $tasks, function ( $t ) use ( $today ) {
			return $t['due_date'] && $t['due_date'] >= $today && ! in_array( (string) $t['status'], NDWD_Helpers::CLOSED_STATUSES, true );
		} ) );
		usort( $dead, function ( $a, $b ) {
			return strcmp( $a['due_date'], $b['due_date'] );
		} );
		$open_requests = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'requests' ) . " WHERE connection_id = %d AND status IN ('submitted','in_review','approved','in_progress')", $c->id ) ); // phpcs:ignore
		$pending       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'deliverables' ) . " WHERE connection_id = %d AND status = 'pending_approval'", $c->id ) ); // phpcs:ignore
		return array(
			'active_projects'   => count( array_filter( self::visible_projects( $c ), function ( $p ) {
				return (int) $p->is_active && ! (int) $p->is_archived;
			} ) ),
			'work_requests'     => $open_requests,
			'pending_tasks'     => $count( 'pending' ),
			'in_progress'       => $count( 'in_process' ),
			'completed'         => $count( 'completed' ),
			'pending_approvals' => $pending,
			'deadlines'         => array_slice( $dead, 0, 8 ),
			'package_state'     => NDWD_WS::package_state( $c ),
		);
	}

	/** Only events marked client-visible, on visible projects. Internal events are never selected. */
	private static function events( $conn, $from, $to, $project_id = 0 ) {
		global $wpdb;
		$sql  = 'SELECT id, title, event_type, project_id, starts_at, ends_at, description FROM ' . NDWD_WS::t( 'events' ) . " WHERE visibility = 'client' AND client_id = %d";
		$vals = array( (int) $conn->client_id );
		if ( $from ) {
			$sql   .= ' AND starts_at >= %s';
			$vals[] = $from . ' 00:00:00';
		}
		if ( $to ) {
			$sql   .= ' AND starts_at <= %s';
			$vals[] = $to . ' 23:59:59';
		}
		if ( $project_id ) {
			$sql   .= ' AND project_id = %d';
			$vals[] = (int) $project_id;
		}
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY starts_at ASC LIMIT 500', $vals ) ) as $e ) { // phpcs:ignore
			if ( ! $e->project_id || NDWD_WS_Projects::project_open( $conn, (int) $e->project_id ) ) {
				$out[] = array( 'id' => 'evt-' . $e->id, 'title' => $e->title, 'type' => $e->event_type, 'project_id' => $e->project_id ? (int) $e->project_id : null, 'start' => $e->starts_at, 'end' => $e->ends_at, 'description' => (string) $e->description );
			}
		}
		return $out;
	}

	public static function calendar( WP_REST_Request $r ) {
		global $wpdb;
		$c    = self::$conn;
		$from = NDWD_Helpers::sanitize_date( $r->get_param( 'from' ) );
		$to   = NDWD_Helpers::sanitize_date( $r->get_param( 'to' ) );
		$in   = function ( $d ) use ( $from, $to ) {
			return $d && ( ! $from || substr( $d, 0, 10 ) >= $from ) && ( ! $to || substr( $d, 0, 10 ) <= $to );
		};
		$out = self::events( $c, $from, $to );
		foreach ( self::visible_tasks( $c ) as $t ) {
			if ( empty( $t['show_calendar'] ) ) {
				continue; // This task's own switch keeps it off the client's calendar.
			}
			if ( $in( $t['calendar_date'] ) ) {
				$out[] = array( 'id' => 'task-' . $t['id'], 'title' => $t['title'], 'type' => 'deadline', 'project_id' => $t['project_id'], 'start' => $t['calendar_date'] . ' 00:00:00', 'end' => null, 'status' => $t['status'] );
			}
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'deliverables' ) . ' WHERE connection_id = %d AND due_date IS NOT NULL AND client_visible = 1', $c->id ) ) as $d ) { // phpcs:ignore
			if ( $d->project_id && ! NDWD_WS_Projects::project_open( $c, (int) $d->project_id ) ) {
				continue;
			}
			if ( $in( $d->due_date ) ) {
				$out[] = array( 'id' => 'deliv-' . $d->id, 'title' => $d->title, 'type' => 'deliverable', 'project_id' => $d->project_id ? (int) $d->project_id : null, 'start' => $d->due_date . ' 00:00:00', 'end' => null, 'status' => $d->status );
			}
		}
		// Phase 5: every item names the project or sub-project it came from, so a consolidated calendar
		// can say where it belongs and open the right dashboard.
		$names = array();
		foreach ( $out as $i => $x ) {
			$pid = (int) ( $x['project_id'] ?? 0 );
			if ( ! $pid ) {
				continue;
			}
			if ( ! isset( $names[ $pid ] ) ) {
				$p             = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name, parent_id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $pid ) ); // phpcs:ignore
				$par           = $p && $p->parent_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT id, name FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $p->parent_id ) ) : null; // phpcs:ignore
				$names[ $pid ] = array( 'project_name' => $p ? (string) $p->name : '', 'parent_id' => $par ? (int) $par->id : null, 'parent_name' => $par ? (string) $par->name : '' );
			}
			$out[ $i ] = array_merge( $x, $names[ $pid ] );
		}
		return array( 'items' => $out );
	}

	public static function requests() {
		global $wpdb;
		return array( 'items' => array_map( array( 'NDWD_WS_Requests', 'to_array' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE connection_id = %d ORDER BY id DESC LIMIT 200', self::$conn->id ) ) ) ); // phpcs:ignore
	}

	private static function own_request( $id ) {
		$q = NDWD_WS_Requests::get( $id );
		return $q && (int) $q->connection_id === (int) self::$conn->id ? $q : null;
	}

	public static function request_one( WP_REST_Request $r ) {
		$q = self::own_request( (int) $r['id'] );
		if ( ! $q ) {
			return NDWD_WS::err( 'Work request not found.', 404, 'ndwd_not_found' );
		}
		// The same single, time-ordered thread WorkDesk shows on the request's task.
		$t      = $q->task_id ? NDWD_Tasks::get( (int) $q->task_id ) : null;
		$thread = $t ? NDWD_WS_TaskLink::client_comments( self::$conn, $t ) : NDWD_WS_Requests::comments( 'request', $q->id );
		return array_merge( NDWD_WS_Requests::to_array( $q ), array( 'comments' => $thread, 'progress' => NDWD_WS_Projects::request_progress( $q ) ) );
	}

	public static function request_create( WP_REST_Request $r ) {
		$res = NDWD_WS_Requests::submit( self::$conn, self::body( $r ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = rest_ensure_response( array_merge( NDWD_WS_Requests::to_array( $res['request'] ), array( 'created' => $res['created'] ) ) );
		$out->set_status( $res['created'] ? 201 : 200 );
		return $out;
	}

	public static function request_comment( WP_REST_Request $r ) {
		$q = self::own_request( (int) $r['id'] );
		if ( ! $q ) {
			return NDWD_WS::err( 'Work request not found.', 404, 'ndwd_not_found' );
		}
		// A request's conversation is the chat on its task, once someone at NeonDigital opens it.
		if ( $q->task_id ) {
			$t = NDWD_Tasks::get( (int) $q->task_id );
			if ( ! $t || ! NDWD_WS_TaskLink::chat_open( $t ) ) {
				return NDWD_WS::err( 'Chat opens once a NeonDigital team member starts it.', 409, 'ndwd_chat_inactive' );
			}
			$b = self::body( $r );
			unset( $b['is_internal'] );
			$c = NDWD_WS_Requests::add_comment( self::$conn, 'task', $t->id, $b );
			if ( ! is_wp_error( $c ) ) {
				NDWD_WS_TaskLink::on_client_message( $t, (int) $c, $b );
			}
			return is_wp_error( $c ) ? $c : array( 'id' => $c );
		}
		$c = NDWD_WS_Requests::add_comment( self::$conn, 'request', $q->id, self::body( $r ) );
		return is_wp_error( $c ) ? $c : array( 'id' => $c );
	}

	public static function request_cancel( WP_REST_Request $r ) {
		$b   = self::body( $r );
		$res = NDWD_WS_Requests::cancel( self::$conn, (int) $r['id'], $b['actor_name'] ?? '' );
		return is_wp_error( $res ) ? $res : NDWD_WS_Requests::to_array( $res );
	}

	public static function deliverables() {
		global $wpdb;
		return array( 'items' => array_map( function ( $d ) {
			return NDWD_WS_Requests::deliverable_array( $d );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'deliverables' ) . ' WHERE connection_id = %d AND client_visible = 1 ORDER BY id DESC LIMIT 200', self::$conn->id ) ) ) ); // phpcs:ignore
	}

	private static function own_deliverable( $id ) {
		$d = NDWD_WS_Requests::deliverable( $id );
		return $d && (int) $d->connection_id === (int) self::$conn->id && (int) $d->client_visible ? $d : null;
	}

	public static function deliverable( WP_REST_Request $r ) {
		$d = self::own_deliverable( (int) $r['id'] );
		return $d ? NDWD_WS_Requests::deliverable_array( $d, true ) : NDWD_WS::err( 'Deliverable not found.', 404, 'ndwd_not_found' );
	}

	public static function deliverable_decide( WP_REST_Request $r ) {
		$res = NDWD_WS_Requests::decide( self::$conn, (int) $r['id'], self::body( $r ) );
		return is_wp_error( $res ) ? $res : NDWD_WS_Requests::deliverable_array( $res, true );
	}

	public static function deliverable_comment( WP_REST_Request $r ) {
		$d = self::own_deliverable( (int) $r['id'] );
		if ( ! $d ) {
			return NDWD_WS::err( 'Deliverable not found.', 404, 'ndwd_not_found' );
		}
		$c = NDWD_WS_Requests::add_comment( self::$conn, 'deliverable', $d->id, self::body( $r ) );
		return is_wp_error( $c ) ? $c : array( 'id' => $c );
	}

	/** Agency invoices: read only, this client only, and only those someone deliberately sent. */
	public static function invoices() {
		global $wpdb;
		$c    = self::$conn;
		$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT invoice_id FROM ' . NDWD_WS::t( 'invoice_shares' ) . ' WHERE connection_id = %d ORDER BY id DESC LIMIT 200', $c->id ) ); // phpcs:ignore
		$rows = array();
		foreach ( $ids as $id ) {
			$v = NDWD_Accounts::invoice_row( (int) $id );
			if ( $v && (int) $v->client_id === (int) $c->client_id ) {
				$rows[] = NDWD_WS_Manage::invoice_payload( $v );
			}
		}
		return array( 'items' => $rows );
	}

	/** A file the client may see, streamed through Neon Workspace's signed call. */
	public static function attachment_download( WP_REST_Request $r ) {
		$a = NDWD_WS_Attachments::get( (int) $r['id'] );
		if ( ! $a || ! NDWD_WS_Attachments::client_can( self::$conn, $a ) ) {
			return NDWD_WS::err( 'File not found.', 404, 'ndwd_not_found' );
		}
		self::log( $r, 200 );
		return NDWD_WS_Attachments::stream( $a );
	}

	public static function project_attachment_add( WP_REST_Request $r ) {
		return NDWD_WS_Attachments::client_project_add( self::$conn, (int) $r['id'], self::body( $r ) );
	}

	public static function invoice_pdf( WP_REST_Request $r ) {
		$c = self::$conn;
		$v = NDWD_Accounts::invoice_row( (int) $r['id'] );
		if ( ! $v || (int) $v->client_id !== (int) $c->client_id || ! NDWD_WS_Manage::share_for( $v->id, $c->id ) ) {
			return NDWD_WS::err( 'Invoice not found.', 404, 'ndwd_not_found' );
		}
		self::log( $r, 200 );
		$res = NDWD_Accounts::stream_invoice_pdf( $v->id );
		return is_wp_error( $res ) ? $res : array();
	}
}
