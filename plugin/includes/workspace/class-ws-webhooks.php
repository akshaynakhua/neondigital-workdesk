<?php
/**
 * Outgoing webhooks to Workspace: a durable queue with signing, retries and a dead state.
 * Events carry ids only. Workspace fetches what it may see through the signed API, so a webhook
 * can never leak internal data and agency records are never copied into Workspace.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_Webhooks {

	const BACKOFF = array( 60, 300, 1800, 7200, 43200, 86400 );

	public static function init() {
		add_action( 'ndwd_activity_logged', array( __CLASS__, 'on_activity' ), 10, 3 );
		add_action( 'ndwd_fin_audit', array( __CLASS__, 'on_fin_audit' ), 10, 3 );
		add_action( 'ndwd_project_saved', array( __CLASS__, 'on_project' ), 10, 2 );
	}

	/** Queues an event for a linked connection. Connections not yet linked are skipped. */
	public static function emit( $event, $connection_id, $data = array() ) {
		global $wpdb;
		$conn = NDWD_WS::connection( $connection_id );
		if ( ! $conn || ! $conn->workspace_uid || ! $conn->api_key ) {
			return false;
		}
		$id  = 'evt_' . bin2hex( random_bytes( 12 ) );
		$now = NDWD_Helpers::now_mysql();
		$wpdb->insert( NDWD_WS::t( 'webhooks' ), array(
			'event_id'        => $id,
			'event'           => $event,
			'connection_id'   => (int) $connection_id,
			'payload'         => wp_json_encode( array( 'event_id' => $id, 'event' => $event, 'api_version' => 'v1', 'workspace_uid' => $conn->workspace_uid, 'occurred_at' => $now, 'data' => (object) $data ) ),
			'status'          => 'pending',
			'next_attempt_at' => $now,
			'created_at'      => $now,
		) );
		return $id;
	}

	public static function on_activity( $user_id, $task_id, $action ) {
		if ( ! $task_id ) {
			return;
		}
		$task = NDWD_Tasks::get( $task_id );
		if ( ! $task ) {
			return;
		}
		NDWD_WS_Requests::on_task_activity( $task, $action );
		$map = array(
			'task_created'   => 'task.created',
			'task_updated'   => 'task.updated',
			'rescheduled'    => 'task.updated',
			'status_changed' => 'task.updated',
			'task_completed' => 'task.completed',
			'task_rejected'  => 'task.updated',
			'reassigned'     => 'task.assigned',
			'handed_off'     => 'task.assigned',
			'member_handoff' => 'task.assigned',
			'picked_up'      => 'task.assigned',
			'released'       => 'task.assigned',
		);
		if ( ! isset( $map[ $action ] ) || empty( $task->client_id ) ) {
			return;
		}
		$conn = NDWD_WS::connection_for_task( $task );
		if ( $conn && $conn->workspace_uid && NDWD_WS_API::task_visible( $conn, $task ) ) {
			self::emit( $map[ $action ], $conn->id, array( 'task_id' => (int) $task_id, 'project_id' => (int) $task->project_id ) );
		}
	}

	public static function on_fin_audit( $entity, $id, $action ) {
		if ( 'invoice' === $entity ) {
			// Creating an invoice shares nothing. Only an invoice someone explicitly sent keeps in step.
			NDWD_WS_Manage::on_invoice_change( (int) $id, (string) $action );
		} elseif ( 'client_package' === $entity ) {
			$a    = NDWD_Packages::allocation( $id );
			$conn = $a ? NDWD_WS::connection_for_client( $a->client_id ) : null;
			if ( $conn ) {
				self::emit( 'usage_adjusted' === $action ? 'usage.updated' : 'package.updated', $conn->id, array( 'client_package_id' => (int) $id ) );
			}
		}
	}

	public static function on_project( $project_id, $is_new ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $project_id ) ); // phpcs:ignore
		if ( ! $p || ! $p->client_id ) {
			return;
		}
		$conn = NDWD_WS::connection_for_project( (int) $project_id, (int) $p->client_id );
		if ( $conn && in_array( (int) $project_id, NDWD_WS::visible_ids( $conn, 'project' ), true ) ) {
			self::emit( $is_new ? 'project.created' : ( (int) $p->is_active ? 'project.updated' : 'project.completed' ), $conn->id, array( 'project_id' => (int) $project_id ) );
		}
	}

	public static function sign( $secret, $method, $route, $body, $ts, $nonce, $query = '' ) {
		return hash_hmac( 'sha256', strtoupper( $method ) . "\n" . $route . "\n" . $query . "\n" . $ts . "\n" . $nonce . "\n" . hash( 'sha256', (string) $body ), $secret );
	}

	/** Delivers due events. Runs from the WorkDesk scheduler; safe to call repeatedly. */
	public static function process( $limit = 25 ) {
		global $wpdb;
		$t    = NDWD_WS::t( 'webhooks' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'pending' AND next_attempt_at <= %s ORDER BY id ASC LIMIT %d", NDWD_Helpers::now_mysql(), $limit ) ); // phpcs:ignore
		$sent = 0;
		$fail = 0;
		foreach ( $rows as $w ) {
			if ( self::deliver( $w ) ) {
				$sent++;
			} else {
				$fail++;
			}
		}
		return array( 'sent' => $sent, 'failed' => $fail );
	}

	public static function deliver( $w ) {
		global $wpdb;
		$t    = NDWD_WS::t( 'webhooks' );
		$conn = NDWD_WS::connection( $w->connection_id );
		$base = NDWD_WS::workspace_url();
		$code = 0;
		$err  = '';
		if ( ! $conn || ! $conn->api_key || '' === $base ) {
			$err = 'Connection or Workspace address missing.';
		} else {
			$route = '/neon-workspace/v1/webhooks/workdesk';
			$ts    = time();
			$nonce = bin2hex( random_bytes( 12 ) );
			$sig   = self::sign( NDWD_WS::unseal( $conn->api_secret ), 'POST', $route, $w->payload, $ts, $nonce );
			$res   = self::post_following( $base . '/wp-json' . $route, array(
				'timeout'     => 10,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json', 'X-Neon-Key' => $conn->api_key, 'X-Neon-Timestamp' => $ts, 'X-Neon-Nonce' => $nonce, 'X-Neon-Signature' => $sig, 'X-Neon-Event' => $w->event ),
				'body'        => $w->payload,
			) );
			if ( is_wp_error( $res ) ) {
				$err = $res->get_error_message();
			} else {
				$code = (int) wp_remote_retrieve_response_code( $res );
				if ( $code < 200 || $code >= 300 ) {
					$err = 'HTTP ' . $code;
				}
			}
		}
		$attempts = (int) $w->attempts + 1;
		if ( '' === $err ) {
			$wpdb->update( $t, array( 'status' => 'delivered', 'attempts' => $attempts, 'last_status' => $code, 'last_error' => '', 'delivered_at' => NDWD_Helpers::now_mysql(), 'next_attempt_at' => null ), array( 'id' => $w->id ) );
			return true;
		}
		$dead = $attempts > count( self::BACKOFF );
		$wait = $dead ? 0 : self::BACKOFF[ $attempts - 1 ];
		$wpdb->update( $t, array(
			'status'          => $dead ? 'dead' : 'pending',
			'attempts'        => $attempts,
			'last_status'     => $code,
			'last_error'      => mb_substr( $err, 0, 255 ),
			'next_attempt_at' => $dead ? null : gmdate( 'Y-m-d H:i:s', strtotime( NDWD_Helpers::now_mysql() ) + $wait ),
		), array( 'id' => $w->id ) );
		return false;
	}

	/**
	 * WordPress would turn this POST into a GET on a 302, which reaches no route. Redirects on the
	 * same host (http to https, www to bare) are followed with the POST intact. Any other is a failure.
	 */
	private static function post_following( $url, $args ) {
		$host = function ( $u ) {
			return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) ) );
		};
		for ( $hop = 0; $hop < 3; $hop++ ) {
			$res = wp_remote_post( $url, $args );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				return $res;
			}
			$to = (string) wp_remote_retrieve_header( $res, 'location' );
			if ( '' === $to || $host( $to ) !== $host( $url ) ) {
				return new WP_Error( 'ndwd_redirect', 'The Workspace address redirects to another site.' );
			}
			$url = $to;
		}
		return new WP_Error( 'ndwd_redirect', 'Too many redirects from the Workspace address.' );
	}

	/** Failed webhook recovery: puts a dead or waiting event back in the queue. */
	public static function retry( $id, $user_id ) {
		global $wpdb;
		$t = NDWD_WS::t( 'webhooks' );
		$w = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $w ) {
			return NDWD_WS::err( 'Webhook not found.', 404, 'ndwd_not_found' );
		}
		if ( 'delivered' === $w->status ) {
			return NDWD_WS::err( 'This event was already delivered.', 409 );
		}
		$wpdb->update( $t, array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $id ) );
		NDWD_WS::audit( 'webhook_requeued', array( 'connection_id' => $w->connection_id, 'entity' => 'webhook', 'entity_id' => $id ), $user_id );
		return true;
	}

	public static function prune() {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', strtotime( NDWD_Helpers::now_mysql() ) - 90 * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NDWD_WS::t( 'nonces' ) . ' WHERE expires_at < %d', time() ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NDWD_WS::t( 'api_log' ) . ' WHERE created_at < %s', $cut ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NDWD_WS::t( 'webhooks' ) . " WHERE status = 'delivered' AND created_at < %s", $cut ) ); // phpcs:ignore
		return 'pruned';
	}
}
