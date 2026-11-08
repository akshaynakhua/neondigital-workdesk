<?php
/**
 * Change Business Type (1.19). Staff with Workspace management rights change a connected client
 * Workspace's business type through a controlled conversion:
 *   current type → new type → reason → client consent → Neon Workspace's preview of what is kept
 *   and removed → confirmation → conversion (Workspace takes and verifies a backup, then converts in
 *   one transaction) → history here and on the Workspace.
 * WorkDesk never deletes Workspace data itself; it asks Neon Workspace over the signed site channel.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_BType {

	/** Business types Neon Workspace offers. Kept in step with NWC_Catalog::selectable(). */
	const TYPES = array(
		'dance_studio'  => 'Dance studio / academy',
		'music_academy' => 'Music academy / studio',
		'restaurant'    => 'Restaurant',
		'cafe'          => 'Cafe',
		'hotel'         => 'Hotel / hospitality',
		'gym'           => 'Gym / fitness centre',
		'salon'         => 'Salon / beauty / spa',
		'real_estate'   => 'Real estate agency / broker / developer',
		'other'         => 'Other business',
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		foreach ( array(
			array( '', 'GET', 'info' ),
			array( '/preview', 'POST', 'preview' ),
			array( '/convert', 'POST', 'convert' ),
			array( '/restore', 'POST', 'restore' ),
		) as $r ) {
			register_rest_route( NDWD_REST::NS, '/workspace/connections/(?P<id>\d+)/business-type' . $r[0], array(
				'methods'             => $r[1],
				'callback'            => array( __CLASS__, $r[2] ),
				'permission_callback' => function () {
					return is_user_logged_in() && NDWD_Permissions::can( 'ndwd_manage_workspace' );
				},
			) );
		}
	}

	private static function body( WP_REST_Request $r ) {
		$b = json_decode( (string) $r->get_body(), true );
		return is_array( $b ) ? $b : array();
	}

	/** @return object|WP_Error A linked connection. */
	private static function conn( WP_REST_Request $r ) {
		$c = NDWD_WS::connection( (int) $r['id'] );
		if ( ! $c ) {
			return NDWD_WS::err( 'Workspace connection not found.', 404, 'ndwd_not_found' );
		}
		if ( ! $c->linked_at || '' === (string) $c->workspace_uid ) {
			return NDWD_WS::err( 'This Workspace is not linked yet. The client must accept the invitation first.', 409, 'ndwd_not_linked' );
		}
		return $c;
	}

	private static function current_type( $c ) {
		return NDWD_WS::business_type( $c );
	}

	private static function log_rows( $c ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'business_type_log' ) . ' WHERE connection_id = %d ORDER BY id DESC LIMIT 50', $c->id ) ); // phpcs:ignore
		return array_map( function ( $x ) {
			$u = $x->staff_id ? get_userdata( (int) $x->staff_id ) : null;
			return array( 'id' => (int) $x->id, 'from' => $x->from_category, 'from_label' => self::TYPES[ $x->from_category ] ?? $x->from_category, 'to' => $x->to_category, 'to_label' => self::TYPES[ $x->to_category ] ?? $x->to_category, 'reason' => (string) $x->reason, 'consent_note' => (string) $x->consent_note, 'consent_by' => $x->consent_by, 'consent_at' => $x->consent_at, 'staff' => $u ? $u->display_name : '', 'status' => $x->status, 'error' => (string) $x->error, 'created_at' => $x->created_at, 'completed_at' => $x->completed_at, 'restored_at' => $x->restored_at, 'ws_conversion_id' => $x->ws_conversion_id ? (int) $x->ws_conversion_id : null );
		}, $rows );
	}

	public static function info( WP_REST_Request $r ) {
		$c = NDWD_WS::connection( (int) $r['id'] );
		if ( ! $c ) {
			return NDWD_WS::err( 'Workspace connection not found.', 404, 'ndwd_not_found' );
		}
		$cur = $c->workspace_uid ? self::current_type( $c ) : '';
		return rest_ensure_response( array( 'linked' => (bool) $c->linked_at, 'current' => $cur, 'current_label' => self::TYPES[ $cur ] ?? ( '' === $cur ? 'Not known yet' : $cur ), 'types' => self::TYPES, 'history' => self::log_rows( $c ) ) );
	}

	private static function target( $b ) {
		$to = sanitize_key( (string) ( $b['to'] ?? '' ) );
		return isset( self::TYPES[ $to ] ) ? $to : NDWD_WS::err( 'Choose a valid business type.' );
	}

	public static function preview( WP_REST_Request $r ) {
		$c = self::conn( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$to = self::target( self::body( $r ) );
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		$res = NDWD_WS_Projects::ws_call( 'POST', '/connect/business-type/preview', array( 'workspace_uid' => $c->workspace_uid, 'to' => $to ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/**
	 * Body: to, reason, client_consent (true), consent_by (who agreed), consent_at, consent_note,
	 * confirm ("CHANGE"). Every attempt is recorded, including refusals from Workspace.
	 */
	public static function convert( WP_REST_Request $r ) {
		global $wpdb;
		$c = self::conn( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$b  = self::body( $r );
		$to = self::target( $b );
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		$reason = trim( sanitize_textarea_field( (string) ( $b['reason'] ?? '' ) ) );
		if ( mb_strlen( $reason ) < 5 ) {
			return NDWD_WS::err( 'Give the reason for this change.' );
		}
		$by = mb_substr( trim( sanitize_text_field( (string) ( $b['consent_by'] ?? '' ) ) ), 0, 190 );
		if ( empty( $b['client_consent'] ) || '' === $by ) {
			return NDWD_WS::err( 'Record who at the client agreed to this change.', 422, 'ndwd_consent_required' );
		}
		if ( 'CHANGE' !== (string) ( $b['confirm'] ?? '' ) ) {
			return NDWD_WS::err( 'Type CHANGE to confirm.', 422, 'ndwd_confirm_required' );
		}
		$consent_at = NDWD_Helpers::sanitize_datetime( (string) ( $b['consent_at'] ?? '' ) );
		$consent_at = $consent_at ? $consent_at : NDWD_Helpers::now_mysql();
		$note = sanitize_textarea_field( (string) ( $b['consent_note'] ?? '' ) );
		$from = self::current_type( $c );
		$now  = NDWD_Helpers::now_mysql();
		$wpdb->insert( NDWD_WS::t( 'business_type_log' ), array( 'connection_id' => (int) $c->id, 'client_id' => (int) $c->client_id, 'workspace_uid' => $c->workspace_uid, 'from_category' => $from, 'to_category' => $to, 'reason' => $reason, 'consent_note' => $note, 'consent_by' => $by, 'consent_at' => $consent_at, 'staff_id' => get_current_user_id(), 'status' => 'started', 'created_at' => $now ) );
		$log  = (int) $wpdb->insert_id;
		$me   = wp_get_current_user();
		$res  = NDWD_WS_Projects::ws_call( 'POST', '/connect/business-type/convert', array( 'workspace_uid' => $c->workspace_uid, 'to' => $to, 'reason' => $reason, 'client_consent' => true, 'consent_note' => trim( 'Agreed by ' . $by . '. ' . $note ), 'consent_at' => NDWD_Helpers::to_utc_iso( $consent_at ), 'staff_name' => $me->display_name ) );
		if ( is_wp_error( $res ) ) {
			$wpdb->update( NDWD_WS::t( 'business_type_log' ), array( 'status' => 'failed', 'error' => mb_substr( $res->get_error_message(), 0, 1000 ), 'completed_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $log ) );
			NDWD_WS::audit( 'business_type_change_failed', array( 'connection_id' => $c->id, 'entity' => 'workspace', 'details' => array( 'from' => $from, 'to' => $to, 'error' => $res->get_error_message() ) ), get_current_user_id() );
			return $res;
		}
		$wpdb->update( NDWD_WS::t( 'business_type_log' ), array( 'status' => 'completed', 'from_category' => sanitize_key( (string) ( $res['from'] ?? $from ) ), 'ws_conversion_id' => absint( $res['conversion_id'] ?? 0 ), 'result' => wp_json_encode( $res['removed'] ?? array() ), 'completed_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $log ) );
		$wpdb->update( NDWD_WS::t( 'directory' ), array( 'business_category' => $to ), array( 'workspace_uid' => $c->workspace_uid ) );
		$wpdb->update( NDWD_WS::t( 'connections' ), array( 'business_category' => $to ), array( 'id' => $c->id ) );
		NDWD_WS::audit( 'business_type_changed', array( 'connection_id' => $c->id, 'entity' => 'workspace', 'details' => array( 'from' => $res['from'] ?? $from, 'to' => $to, 'reason' => $reason, 'consent_by' => $by, 'log' => $log ) ), get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true, 'log_id' => $log, 'from' => $res['from'] ?? $from, 'to' => $to, 'removed' => $res['removed'] ?? array(), 'backup_verified' => ! empty( $res['backup_verified'] ) ) );
	}

	/** Undo the latest completed conversion from the Workspace's verified backup. */
	public static function restore( WP_REST_Request $r ) {
		global $wpdb;
		$c = self::conn( $r );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$b   = self::body( $r );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'business_type_log' ) . " WHERE id = %d AND connection_id = %d AND status = 'completed'", absint( $b['log_id'] ?? 0 ), $c->id ) ); // phpcs:ignore
		if ( ! $row || ! $row->ws_conversion_id ) {
			return NDWD_WS::err( 'No completed change with that number for this Workspace.', 404, 'ndwd_not_found' );
		}
		$res = NDWD_WS_Projects::ws_call( 'POST', '/connect/business-type/restore', array( 'workspace_uid' => $c->workspace_uid, 'conversion_id' => (int) $row->ws_conversion_id ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$wpdb->update( NDWD_WS::t( 'business_type_log' ), array( 'status' => 'restored', 'restored_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $row->id ) );
		$wpdb->update( NDWD_WS::t( 'directory' ), array( 'business_category' => $row->from_category ), array( 'workspace_uid' => $c->workspace_uid ) );
		$wpdb->update( NDWD_WS::t( 'connections' ), array( 'business_category' => $row->from_category ), array( 'id' => $c->id ) );
		NDWD_WS::audit( 'business_type_restored', array( 'connection_id' => $c->id, 'entity' => 'workspace', 'details' => array( 'back_to' => $row->from_category, 'log' => (int) $row->id ) ), get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true, 'category' => $row->from_category ) );
	}
}
