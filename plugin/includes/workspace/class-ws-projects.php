<?php
/**
 * Client → Workspaces → Projects → Packages → Add-ons.
 *
 * The master catalogue lives in Workspace and is mirrored here. Assigning a package to a project
 * copies its name, features and price into that project's own row, so a later change to the master
 * never touches an existing agreement, and a price agreed for one client never touches the master.
 *
 * Access is decided per project (see access()): a project with no live package is locked, and only
 * that project. A migration moves one project from the agency arrangement to its own subscription.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_Projects {

	const FREQUENCIES = array( 'month' => 'Monthly', 'year' => 'Yearly', 'one_time' => 'One-time', 'agency' => 'Agency arrangement' );
	const NO_PACKAGE  = 'No Workspace package assigned to this project.';
	const REMIND_AT   = array( 7, 3, 1 ); // Days before a project's grace period ends.

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
		self::route( '/catalogue', 'GET', 'rest_catalogue' );
		self::route( '/catalogue/sync', 'POST', 'rest_catalogue_pull' );
		self::route( '/catalogue/(?P<id>\d+)/history', 'GET', 'rest_catalogue_history' );
		self::route( '/clients/(?P<client_id>\d+)/tree', 'GET', 'rest_tree' );
		self::route( '/all', 'GET', 'rest_all' );
		self::route( '/assignments', 'GET', 'rest_assignments' );
		self::route( '/projects/(?P<id>\d+)/workspace', 'POST', 'rest_link' );
		self::route( '/projects/(?P<id>\d+)/package', 'POST', 'rest_assign' );
		self::route( '/projects/(?P<id>\d+)/history', 'GET', 'rest_history' );
		self::route( '/projects/(?P<id>\d+)/migrate', 'POST', 'rest_migrate' );
		self::route( '/assignments/(?P<id>\d+)', 'PUT,PATCH', 'rest_assignment_update' );
		self::route( '/assignments/(?P<id>\d+)', 'DELETE', 'rest_assignment_remove' );
		self::route( '/migrations', 'GET', 'rest_migrations' );
		self::route( '/migrations/(?P<id>\d+)', 'PUT,PATCH', 'rest_migration_update' );
		self::route( '/migrations/(?P<id>\d+)/reverse', 'POST', 'rest_migration_reverse' );
		self::route( '/connections/(?P<id>\d+)/reconnect', 'POST', 'rest_reconnect' );
		self::route( '/subscriptions', 'GET', 'rest_subscriptions' );
		self::route( '/subscriptions/(?P<uid>[A-Za-z0-9_-]{8,64})', 'POST', 'rest_subscription_save' );
		self::route( '/entitlements/overview', 'GET', 'rest_entitlements' );
		self::route( '/tasks/(?P<id>\d+)/request/review', 'POST', 'rest_task_review', 'ndwd_access' );
		self::route( '/tasks/(?P<id>\d+)/deliverables', 'POST', 'rest_task_deliverable', 'ndwd_access' );
		self::route( '/tasks/(?P<id>\d+)/deliverables/(?P<did>\d+)', 'PUT,PATCH', 'rest_task_deliverable', 'ndwd_access' );
	}

	private static function body( WP_REST_Request $r ) {
		$b = json_decode( (string) $r->get_body(), true );
		return is_array( $b ) ? $b : array();
	}

	private static function out( $res ) {
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	private static function money( $v ) {
		return null === $v || '' === $v ? null : (float) $v;
	}

	private static function project_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/* ---------------------------- Upgrade ---------------------------- */

	/**
	 * v16. One client may now hold several connections. Projects a client could already see keep
	 * working through a recorded "existing arrangement", and requests waiting for review get the
	 * task that new requests get on arrival. Nothing is deleted. To go back, restore the database
	 * backup, or remove the extra connections and put the unique key back.
	 */
	public static function upgrade_v16() {
		global $wpdb;
		$t = NDWD_WS::t( 'connections' );
		$wpdb->suppress_errors( true );
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM $t" ) as $i ) { // phpcs:ignore
			if ( 'client_id' === ( $i->Key_name ?? '' ) && 0 === (int) ( $i->Non_unique ?? 1 ) ) {
				$wpdb->query( "ALTER TABLE $t DROP INDEX client_id" ); // phpcs:ignore
				$wpdb->query( "ALTER TABLE $t ADD INDEX client_id (client_id)" ); // phpcs:ignore
				break;
			}
		}
		$wpdb->suppress_errors( false );
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'visibility' ) . " WHERE entity = 'project'" ) as $v ) { // phpcs:ignore
			$conn = NDWD_WS::connection( (int) $v->connection_id );
			if ( $conn ) {
				self::grant_existing( $conn, (int) $v->entity_id, 0 );
			}
		}
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'requests' ) . " WHERE task_id IS NULL AND status IN ('submitted','in_review')" ) as $r ) { // phpcs:ignore
			if ( NDWD_WS::connection( (int) $r->connection_id ) ) {
				NDWD_WS_Requests::ensure_task( $r, false );
			}
		}
	}

	/**
	 * v19: main projects stop falling back to the client's main Workspace. Every project that was
	 * showing in a Workspace only through that fallback is pinned to it now, so clients keep seeing
	 * exactly what they saw. New projects then start with no Workspace until staff assign one.
	 */
	public static function upgrade_v19() {
		global $wpdb;
		$vis  = NDWD_WS::t( 'visibility' );
		$now  = NDWD_Helpers::now_mysql();
		$rows = $wpdb->get_results( 'SELECT id, client_id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE parent_id IS NULL AND client_id IS NOT NULL AND client_id > 0' ); // phpcs:ignore
		$n    = 0;
		foreach ( $rows as $p ) {
			if ( NDWD_WS::explicit_connection_id( (int) $p->id ) ) {
				continue;
			}
			$c = NDWD_WS::connection_for_client( (int) $p->client_id );
			if ( ! $c ) {
				continue;
			}
			$wpdb->insert( $vis, array( 'connection_id' => (int) $c->id, 'entity' => 'project', 'entity_id' => (int) $p->id, 'created_at' => $now ) );
			++$n;
		}
		if ( $n ) {
			NDWD_WS::audit( 'projects_pinned_v19', array( 'kind' => 'system', 'details' => array( 'projects' => $n ) ) );
		}
	}

	/** A project that was already open before project packages existed stays open, and says why. */
	public static function grant_existing( $conn, $project_id, $user_id ) {
		global $wpdb;
		$t = NDWD_WS::t( 'project_packages' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE project_id = %d", $project_id ) ) ) { // phpcs:ignore
			return;
		}
		$alloc = $wpdb->get_row( $wpdb->prepare( 'SELECT id, name FROM ' . NDWD_Helpers::table( 'client_packages' ) . " WHERE project_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $project_id ) ); // phpcs:ignore
		$now   = NDWD_Helpers::now_mysql();
		$wpdb->insert( $t, array(
			'connection_id'     => (int) $conn->id,
			'client_id'         => (int) $conn->client_id,
			'project_id'        => $project_id,
			'kind'              => 'legacy',
			'name'              => $alloc ? $alloc->name : 'Existing agency arrangement',
			'billing_frequency' => 'agency',
			'start_date'        => null,
			'status'            => 'active',
			'client_package_id' => $alloc ? (int) $alloc->id : null,
			'notes'             => 'Kept from before project packages',
			'created_by'        => (int) $user_id,
			'created_at'        => $now,
			'updated_at'        => $now,
		) );
	}

	public static function on_visibility_saved( $conn, $before, $after, $user_id ) {
		global $wpdb;
		foreach ( array_diff( $after, $before ) as $pid ) {
			self::grant_existing( $conn, (int) $pid, $user_id );
		}
		foreach ( array_diff( $before, $after ) as $pid ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'project_packages' ) . " SET status = 'removed', ended_at = %s, ended_by = %d WHERE project_id = %d AND kind = 'legacy' AND status = 'active'", NDWD_Helpers::now_mysql(), (int) $user_id, (int) $pid ) ); // phpcs:ignore
		}
	}

	/* ---------------------------- Master catalogue ---------------------------- */

	private static function catalogue_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'catalogue' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/**
	 * Idempotent: same content, same version. A change makes a new version and keeps the old one in
	 * history. Each entry also keeps one row in the Accounts template list, so no second list exists.
	 *
	 * @param array $items What Workspace sent.
	 * @param bool  $full  The list is complete, so anything missing has been withdrawn.
	 */
	public static function upsert_catalogue( $items, $full = false ) {
		global $wpdb;
		$t    = NDWD_WS::t( 'catalogue' );
		$out  = array( 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0 );
		$seen = array();
		$now  = NDWD_Helpers::now_mysql();
		foreach ( array_slice( (array) $items, 0, 500 ) as $i ) {
			$kind = 'addon' === ( $i['kind'] ?? '' ) ? 'addon' : 'plan';
			$code = mb_substr( sanitize_key( (string) ( $i['code'] ?? '' ) ), 0, 60 );
			if ( '' === $code ) {
				continue;
			}
			$seen[ $kind . '|' . $code ] = true;
			$feat = array();
			foreach ( (array) ( $i['features'] ?? array() ) as $fk => $fv ) {
				if ( isset( NDWD_WS::FEATURES[ $fk ] ) ) {
					$feat[ $fk ] = isset( $fv['limit'] ) ? array( 'limit' => absint( $fv['limit'] ) ) : array();
				}
			}
			ksort( $feat );
			$row = array(
				'name'           => mb_substr( sanitize_text_field( (string) ( $i['name'] ?? $code ) ), 0, 160 ),
				'description'    => sanitize_textarea_field( (string) ( $i['description'] ?? '' ) ),
				'price'          => self::money( $i['price'] ?? null ),
				'annual_price'   => self::money( $i['annual_price'] ?? null ),
				'billing_period' => 'year' === ( $i['billing_period'] ?? '' ) ? 'year' : 'month',
				'currency'       => mb_substr( sanitize_text_field( (string) ( $i['currency'] ?? 'INR' ) ), 0, 3 ),
				'features'       => wp_json_encode( (object) $feat ),
				'feature_code'   => isset( NDWD_WS::FEATURES[ $i['feature_code'] ?? '' ] ) ? $i['feature_code'] : '',
				'usage_limit'    => isset( $i['usage_limit'] ) && '' !== $i['usage_limit'] ? (int) $i['usage_limit'] : null,
				'duration_days'  => isset( $i['duration_days'] ) && '' !== $i['duration_days'] ? absint( $i['duration_days'] ) : null,
				'grace_days'     => min( 365, absint( $i['grace_days'] ?? 7 ) ),
				'is_trial'       => empty( $i['is_trial'] ) ? 0 : 1,
				'is_public'      => isset( $i['is_public'] ) && empty( $i['is_public'] ) ? 0 : 1,
				'is_active'      => isset( $i['is_active'] ) && empty( $i['is_active'] ) ? 0 : 1,
			);
			$hash = hash( 'sha256', wp_json_encode( array_map( function ( $v ) {
				return null === $v ? null : (string) $v;
			}, $row ) ) );
			$old  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE kind = %s AND code = %s", $kind, $code ) ); // phpcs:ignore
			if ( $old && hash_equals( (string) $old->content_hash, $hash ) ) {
				$wpdb->update( $t, array( 'synced_at' => $now ), array( 'id' => $old->id ) );
				$out['unchanged']++;
				continue;
			}
			$row['content_hash'] = $hash;
			$row['synced_at']    = $now;
			if ( $old ) {
				$row['version'] = (int) $old->version + 1;
				$wpdb->update( $t, $row, array( 'id' => $old->id ) );
				$id = (int) $old->id;
				$out['updated']++;
			} else {
				$row['version'] = 1;
				$wpdb->insert( $t, array_merge( $row, array( 'kind' => $kind, 'code' => $code ) ) );
				$id = (int) $wpdb->insert_id;
				$out['created']++;
			}
			$wpdb->insert( NDWD_WS::t( 'catalogue_history' ), array( 'catalogue_id' => $id, 'version' => $row['version'], 'snapshot' => wp_json_encode( $row ), 'created_at' => $now ) );
			self::sync_template( $id );
		}
		if ( $full ) {
			foreach ( $wpdb->get_results( "SELECT * FROM $t WHERE is_active = 1" ) as $c ) { // phpcs:ignore
				if ( ! isset( $seen[ $c->kind . '|' . $c->code ] ) ) {
					$wpdb->update( $t, array( 'is_active' => 0, 'version' => (int) $c->version + 1, 'content_hash' => '', 'synced_at' => $now ), array( 'id' => $c->id ) );
					$wpdb->insert( NDWD_WS::t( 'catalogue_history' ), array( 'catalogue_id' => (int) $c->id, 'version' => (int) $c->version + 1, 'snapshot' => wp_json_encode( array( 'withdrawn' => true, 'name' => $c->name ) ), 'created_at' => $now ) );
					self::sync_template( (int) $c->id );
					$out['withdrawn']++;
				}
			}
		}
		if ( $out['created'] || $out['updated'] || $out['withdrawn'] ) {
			NDWD_WS::audit( 'catalogue_synced', array( 'kind' => 'api', 'details' => $out ) );
		}
		return $out;
	}

	/** The Accounts template for an entry. Existing allocations hold their own copies, so nothing else moves. */
	private static function sync_template( $id ) {
		global $wpdb;
		$c = self::catalogue_row( $id );
		if ( ! $c ) {
			return;
		}
		$t   = NDWD_Helpers::table( 'packages' );
		$now = NDWD_Helpers::now_mysql();
		$row = array(
			'name'           => mb_substr( ( 'addon' === $c->kind ? 'Workspace add-on: ' : 'Workspace: ' ) . $c->name, 0, 160 ),
			'description'    => (string) $c->description,
			'amount'         => null === $c->price ? 0 : (float) $c->price,
			'billing_period' => 'year' === $c->billing_period ? 'yearly' : 'monthly',
			'package_type'   => 'monthly',
			'reset_rule'     => 'none',
			'is_active'      => (int) $c->is_active && ! (int) $c->is_trial ? 1 : 0,
			'updated_at'     => $now,
		);
		if ( $c->template_id && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id = %d", $c->template_id ) ) ) { // phpcs:ignore
			$wpdb->update( $t, $row, array( 'id' => $c->template_id ) );
			return;
		}
		$wpdb->insert( $t, array_merge( $row, array( 'created_at' => $now ) ) );
		$wpdb->update( NDWD_WS::t( 'catalogue' ), array( 'template_id' => (int) $wpdb->insert_id ), array( 'id' => $id ) );
	}

	private static function catalogue_array( $c ) {
		$feat = array_keys( (array) json_decode( (string) $c->features, true ) );
		return array(
			'id'             => (int) $c->id,
			'kind'           => $c->kind,
			'code'           => $c->code,
			'name'           => $c->name,
			'description'    => (string) $c->description,
			'price'          => self::money( $c->price ),
			'annual_price'   => self::money( $c->annual_price ),
			'billing_period' => $c->billing_period,
			'currency'       => $c->currency,
			'features'       => $feat,
			'feature_labels' => array_values( array_map( function ( $k ) {
				return NDWD_WS::FEATURES[ $k ] ?? $k;
			}, $feat ) ),
			'feature_code'   => $c->feature_code,
			'usage_limit'    => null === $c->usage_limit ? null : (int) $c->usage_limit,
			'duration_days'  => null === $c->duration_days ? null : (int) $c->duration_days,
			'grace_days'     => (int) $c->grace_days,
			'is_trial'       => (bool) $c->is_trial,
			'is_public'      => (bool) $c->is_public,
			'is_active'      => (bool) $c->is_active,
			'version'        => (int) $c->version,
			'synced_at'      => $c->synced_at,
		);
	}

	public static function rest_catalogue() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'catalogue' ) . " ORDER BY kind DESC, is_active DESC, name ASC" ); // phpcs:ignore
		return rest_ensure_response( array( 'items' => array_map( array( __CLASS__, 'catalogue_array' ), $rows ), 'frequencies' => self::FREQUENCIES, 'can_price' => NDWD_Permissions::can( 'ndwd_manage_accounts' ) ) );
	}

	public static function rest_catalogue_history( WP_REST_Request $r ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT version, snapshot, created_at FROM ' . NDWD_WS::t( 'catalogue_history' ) . ' WHERE catalogue_id = %d ORDER BY version DESC LIMIT 50', (int) $r['id'] ) ); // phpcs:ignore
		return rest_ensure_response( array( 'items' => array_map( function ( $h ) {
			return array( 'version' => (int) $h->version, 'at' => $h->created_at, 'data' => json_decode( (string) $h->snapshot, true ) );
		}, $rows ) ) );
	}

	/** Asks Workspace for its catalogue now, instead of waiting for its next push. */
	public static function rest_catalogue_pull() {
		$res = self::ws_call( 'GET', '/connect/catalogue' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( self::upsert_catalogue( (array) ( $res['items'] ?? array() ), true ) );
	}

	/**
	 * A signed call to Workspace with the site credential. It is only used for what cannot wait for
	 * a webhook, and never while Workspace is calling here.
	 *
	 * @return array|WP_Error
	 */
	public static function ws_call( $method, $path, $body = null ) {
		$base = NDWD_WS::workspace_url();
		list( $key, $secret ) = NDWD_WS::site_credentials();
		if ( '' === $base || '' === (string) $key || '' === (string) $secret ) {
			return NDWD_WS::err( 'Set up the Workspace connection first, in Settings → Integrations.', 409, 'ndwd_ws_unconfigured' );
		}
		$route = '/neon-workspace/v1' . $path;
		$json  = null === $body ? '' : wp_json_encode( $body );
		$ts    = (string) time();
		$nonce = bin2hex( random_bytes( 12 ) );
		$res   = wp_remote_request( $base . '/wp-json' . $route, array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json', 'X-Neon-Key' => $key, 'X-Neon-Timestamp' => $ts, 'X-Neon-Nonce' => $nonce, 'X-Neon-Signature' => NDWD_WS_Webhooks::sign( $secret, $method, $route, $json, $ts, $nonce ) ),
			'body'        => null === $body ? null : $json,
		) );
		if ( is_wp_error( $res ) ) {
			return NDWD_WS::err( 'Neon Workspace could not be reached, so nothing was changed.', 502, 'ndwd_ws_down' );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['message'] ) ? sanitize_text_field( $data['message'] ) : 'Neon Workspace refused this (status ' . $code . '), so nothing was changed.';
			$e = NDWD_WS::err( $msg, 401 === $code || $code >= 500 ? 502 : $code, 'ndwd_ws_refused' );
			$e->add_data( array( 'status' => 401 === $code || $code >= 500 ? 502 : $code, 'remote_code' => is_array( $data ) ? sanitize_key( (string) ( $data['code'] ?? '' ) ) : '' ) );
			return $e;
		}
		return is_array( $data ) ? $data : array();
	}

	/* ---------------------------- Projects and access ---------------------------- */

	/** Every project of this Workspace that the client can know about. */
	public static function projects_of( $conn ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE client_id = %d AND is_archived = 0 ORDER BY name ASC', $conn->client_id ) ); // phpcs:ignore
		return array_values( array_filter( $rows, function ( $p ) use ( $conn ) {
			$home = NDWD_WS::connection_for_project( (int) $p->id, (int) $p->client_id );
			return $home && (int) $home->id === (int) $conn->id;
		} ) );
	}

	/** Moves a project (with its sub-projects) to one of its client's Workspaces. A project is never in two. */
	public static function link_project( $project_id, $connection_id, $user_id ) {
		global $wpdb;
		$p    = self::project_row( $project_id );
		$conn = NDWD_WS::connection( $connection_id );
		if ( ! $p || ! $conn ) {
			return NDWD_WS::err( 'Project or Workspace not found.', 404, 'ndwd_not_found' );
		}
		if ( (int) $p->client_id !== (int) $conn->client_id ) {
			return NDWD_WS::err( 'That project belongs to another client.', 403, 'ndwd_forbidden' );
		}
		$t   = NDWD_WS::t( 'visibility' );
		$now = NDWD_Helpers::now_mysql();
		$cur = $wpdb->get_var( $wpdb->prepare( "SELECT connection_id FROM $t WHERE entity = 'project' AND entity_id = %d", $project_id ) ); // phpcs:ignore
		if ( (int) $cur === (int) $conn->id ) {
			return true;
		}
		// Phase 4: one main project per Workspace. A sub-project may join its own parent's Workspace,
		// never another main project's, so no project's records appear inside an unrelated one.
		if ( ! NDWD_WS::connection_fits_project( (int) $conn->id, (int) $project_id ) ) {
			return NDWD_WS::err( 'That Workspace belongs to another main project. Each main project has its own Workspace; give this one its own instead.', 409, 'ndwd_mixed_projects' );
		}
		$prev = NDWD_WS::connection_for_project( (int) $project_id, (int) $p->client_id );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE entity = 'project' AND entity_id = %d", $project_id ) ); // phpcs:ignore
		$wpdb->insert( $t, array( 'connection_id' => (int) $conn->id, 'entity' => 'project', 'entity_id' => (int) $project_id, 'created_at' => $now ) );
		$wpdb->update( NDWD_WS::t( 'project_packages' ), array( 'connection_id' => (int) $conn->id ), array( 'project_id' => (int) $project_id, 'status' => 'active' ) );
		// Sub-projects that follow this project move with it; one with its own Workspace keeps it.
		foreach ( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE parent_id = %d', $project_id ) ) ) as $sub ) { // phpcs:ignore
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT connection_id FROM $t WHERE entity = 'project' AND entity_id = %d", $sub ) ) ) { // phpcs:ignore
				$wpdb->update( NDWD_WS::t( 'project_packages' ), array( 'connection_id' => (int) $conn->id ), array( 'project_id' => $sub, 'status' => 'active' ) );
				NDWD_WS_Webhooks::emit( 'project.updated', $conn->id, array( 'project_id' => $sub ) );
			}
		}
		NDWD_WS::audit( 'project_workspace_changed', array( 'connection_id' => $conn->id, 'entity' => 'project', 'entity_id' => $project_id, 'details' => array( 'from' => $cur ? (int) $cur : null, 'to' => (int) $conn->id ) ), $user_id );
		NDWD_WS_Webhooks::emit( 'project.updated', $conn->id, array( 'project_id' => (int) $project_id ) );
		if ( $prev && (int) $prev->id !== (int) $conn->id ) {
			NDWD_WS_Webhooks::emit( 'project.updated', (int) $prev->id, array( 'project_id' => (int) $project_id ) );
		}
		return true;
	}

	private static function live_plan( $project_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'project_packages' ) . " WHERE project_id = %d AND status = 'active' AND kind IN ('plan','legacy') ORDER BY id DESC LIMIT 1", $project_id ) ); // phpcs:ignore
	}

	private static function open_migration( $project_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'migrations' ) . " WHERE project_id = %d AND status IN ('pending','active','expired') ORDER BY id DESC LIMIT 1", $project_id ) ); // phpcs:ignore
	}

	/**
	 * The one rule for one project:
	 *   migration waiting      → open until its own grace period ends, then locked;
	 *   migration activated    → open while its subscription runs;
	 *   package (or add-on)    → open under that package's dates and grace;
	 *   nothing                → locked.
	 * Agency packages also need the agency feature live. A migrated project does not.
	 *
	 * @return array {open,state,message,assignment,migration,days_left}
	 */
	public static function access( $conn, $project_id ) {
		$today = NDWD_Helpers::today();
		$m     = self::open_migration( $project_id );
		$out   = array( 'open' => false, 'state' => 'no_package', 'message' => self::NO_PACKAGE, 'assignment' => null, 'migration' => $m, 'days_left' => null );
		if ( $m ) {
			$left = (int) floor( ( strtotime( $m->grace_ends_on ) - strtotime( $today ) ) / DAY_IN_SECONDS );
			if ( 'active' === $m->status ) {
				$locked = $m->period_end && 'locked' === NDWD_WS::lifecycle( null, $m->period_end, (int) $m->grace_days );
				return array_merge( $out, array( 'open' => ! $locked, 'state' => $locked ? 'subscription_lapsed' : 'subscribed', 'message' => $locked ? 'The subscription for this project has ended.' : '' ) );
			}
			if ( 'pending' === $m->status && $left >= 0 ) {
				return array_merge( $out, array( 'open' => true, 'state' => 'grace', 'message' => '', 'days_left' => $left ) );
			}
			return array_merge( $out, array( 'state' => 'migration_expired', 'message' => 'The grace period for this project has ended. Activate its subscription to unlock it.', 'days_left' => min( 0, $left ) ) );
		}
		$p = self::live_plan( $project_id );
		if ( ! $p ) {
			// Phase 4: a sub-project without a package of its own, in its parent's Workspace, uses the
			// parent's access. One given its own Workspace needs its own package there.
			$parent = (int) NDWD_WS::root_project_id( $project_id );
			if ( $parent && $parent !== (int) $project_id && ! NDWD_WS::explicit_connection_id( $project_id ) ) {
				$pa = self::access( $conn, $parent );
				return array_merge( $pa, array( 'inherited' => true, 'migration' => null ) );
			}
			return $out;
		}
		$out['assignment'] = $p;
		$state = NDWD_WS::lifecycle( $p->start_date, $p->expires_on, (int) $p->grace_days );
		if ( 'scheduled' === $state ) {
			return array_merge( $out, array( 'state' => 'scheduled', 'message' => 'This project\'s package starts on ' . $p->start_date . '.' ) );
		}
		if ( 'locked' === $state ) {
			return array_merge( $out, array( 'state' => 'expired', 'message' => 'The package for this project has ended.' ) );
		}
		if ( (int) $conn->agency_connect && ! NDWD_WS_API::feature_live( $conn, 'agency_connect' ) ) {
			return array_merge( $out, array( 'state' => 'feature_locked', 'message' => 'NeonDigital Connect is not active on this account.' ) );
		}
		return array_merge( $out, array( 'open' => true, 'state' => $state, 'message' => '' ) );
	}

	public static function project_open( $conn, $project_id ) {
		return self::access( $conn, $project_id )['open'];
	}

	/** What the client may know about a project's access: no staff notes, no agreement internals. */
	public static function client_access( $a ) {
		$out = array( 'state' => $a['state'], 'message' => $a['message'], 'days_left' => $a['days_left'], 'inherited' => ! empty( $a['inherited'] ) );
		$p   = $a['assignment'];
		if ( $p && $a['open'] ) {
			$out['package'] = array( 'name' => $p->name, 'expires_on' => $p->expires_on );
		}
		$m = $a['migration'];
		if ( $m && in_array( $m->status, array( 'pending', 'active', 'expired' ), true ) ) {
			$price = null !== $m->custom_price ? (float) $m->custom_price : ( null !== $m->original_price ? (float) $m->original_price : null );
			$out['migration'] = array( 'id' => (int) $m->id, 'status' => $m->status, 'plan_code' => $m->plan_code, 'package_name' => $m->package_name, 'price' => $price, 'billing_cycle' => $m->billing_cycle, 'grace_ends_on' => $m->grace_ends_on, 'period_end' => $m->period_end );
		}
		return $out;
	}

	public static function independent_count( $conn ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_WS::t( 'migrations' ) . " WHERE connection_id = %d AND status IN ('pending','active','expired')", $conn->id ) ); // phpcs:ignore
	}

	/* ---------------------------- Package assignment ---------------------------- */

	public static function assignment_public( $p ) {
		return self::assignment_array( $p );
	}

	private static function assignment_array( $p ) {
		$effective = null !== $p->custom_price ? (float) $p->custom_price : self::money( $p->original_price );
		return array(
			'id'                => (int) $p->id,
			'connection_id'     => (int) $p->connection_id,
			'client_id'         => (int) $p->client_id,
			'project_id'        => (int) $p->project_id,
			'kind'              => $p->kind,
			'catalogue_id'      => $p->catalogue_id ? (int) $p->catalogue_id : null,
			'catalogue_code'    => $p->catalogue_code,
			'catalogue_version' => (int) $p->catalogue_version,
			'name'              => $p->name,
			'features'          => array_keys( (array) json_decode( (string) $p->features, true ) ),
			'original_price'    => self::money( $p->original_price ),
			'custom_price'      => self::money( $p->custom_price ),
			'price'             => $effective,
			'billing_frequency' => $p->billing_frequency,
			'billing_label'     => self::FREQUENCIES[ $p->billing_frequency ] ?? $p->billing_frequency,
			'quantity'          => (int) $p->quantity,
			'usage_limit'       => null === $p->usage_limit ? null : (int) $p->usage_limit,
			'start_date'        => $p->start_date,
			'expires_on'        => $p->expires_on,
			'grace_days'        => (int) $p->grace_days,
			'status'            => $p->status,
			'state'             => 'active' === $p->status ? NDWD_WS::lifecycle( $p->start_date, $p->expires_on, (int) $p->grace_days ) : $p->status,
			'client_package_id' => $p->client_package_id ? (int) $p->client_package_id : null,
			'notes'             => (string) $p->notes,
			'created_by'        => $p->created_by ? NDWD_Helpers::user_name( (int) $p->created_by ) : '',
			'created_at'        => $p->created_at,
			'ended_at'          => $p->ended_at,
		);
	}

	private static function clean_terms( $in, $defaults ) {
		$start = isset( $in['start_date'] ) && '' !== $in['start_date'] ? NDWD_Helpers::sanitize_date( $in['start_date'] ) : $defaults['start_date'];
		$end   = array_key_exists( 'expires_on', $in ) ? ( '' !== (string) $in['expires_on'] ? NDWD_Helpers::sanitize_date( $in['expires_on'] ) : null ) : $defaults['expires_on'];
		if ( ! $start || ( array_key_exists( 'expires_on', $in ) && '' !== (string) $in['expires_on'] && ! $end ) ) {
			return NDWD_WS::err( 'Enter valid dates.' );
		}
		if ( $end && $end < $start ) {
			return NDWD_WS::err( 'The expiry date is before the start date.' );
		}
		$freq = $in['billing_frequency'] ?? $defaults['billing_frequency'];
		if ( ! in_array( $freq, array( 'month', 'year', 'one_time' ), true ) ) {
			return NDWD_WS::err( 'Choose monthly, yearly or one-time billing.' );
		}
		$custom = array_key_exists( 'custom_price', $in ) ? ( '' === (string) $in['custom_price'] || null === $in['custom_price'] ? null : $in['custom_price'] ) : $defaults['custom_price'];
		if ( null !== $custom && ( ! is_numeric( $custom ) || (float) $custom < 0 ) ) {
			return NDWD_WS::err( 'Enter a price of 0 or more.' );
		}
		return array(
			'start_date'        => $start,
			'expires_on'        => $end,
			'billing_frequency' => $freq,
			'custom_price'      => null === $custom ? null : round( (float) $custom, 2 ),
			'grace_days'        => min( 365, absint( $in['grace_days'] ?? $defaults['grace_days'] ) ),
			'quantity'          => max( 1, absint( $in['quantity'] ?? $defaults['quantity'] ) ),
			'usage_limit'       => array_key_exists( 'usage_limit', $in ) ? ( '' === (string) $in['usage_limit'] ? null : absint( $in['usage_limit'] ) ) : $defaults['usage_limit'],
			'notes'             => mb_substr( sanitize_text_field( (string) ( $in['notes'] ?? $defaults['notes'] ) ), 0, 255 ),
		);
	}

	private static function price_from_catalogue( $c, $freq ) {
		if ( 'year' === $freq && null !== $c->annual_price ) {
			return (float) $c->annual_price;
		}
		return null === $c->price ? null : (float) $c->price;
	}

	/** The Accounts record of this agreement, so invoicing keeps working from the same place. */
	private static function open_allocation( $client_id, $project_id, $c, $terms, $price, $user_id ) {
		$alloc = NDWD_Packages::assign( $client_id, array(
			'package_id'     => $c->template_id ? (int) $c->template_id : 0,
			'project_id'     => $project_id,
			'name'           => $c->name,
			'package_type'   => 'one_time' === $terms['billing_frequency'] ? 'one_time' : 'monthly',
			'price'          => null === $price ? 0 : $price,
			'billing_period' => array( 'month' => 'monthly', 'year' => 'yearly', 'one_time' => '' )[ $terms['billing_frequency'] ],
			'start_date'     => $terms['start_date'],
			'end_date'       => $terms['expires_on'] ? $terms['expires_on'] : '',
			'reset_rule'     => 'none',
			'items'          => array(),
		), $user_id );
		return is_wp_error( $alloc ) ? $alloc : (int) $alloc['id'];
	}

	private static function close_allocation( $id, $user_id, $status = 'inactive' ) {
		if ( $id && NDWD_Packages::allocation( $id ) ) {
			$res = NDWD_Packages::update_allocation( (int) $id, array( 'status' => $status ), $user_id );
			NDWD_Packages::flush();
			return ! is_wp_error( $res );
		}
		return false;
	}

	/**
	 * Assigns a catalogue package or add-on to one project. A new package replaces the live one and
	 * the old row stays as history. Only staff with Accounts access may set a price of their own.
	 *
	 * @return array|WP_Error
	 */
	public static function assign( $project_id, $in, $user_id ) {
		global $wpdb;
		$p = self::project_row( $project_id );
		if ( ! $p || ! $p->client_id ) {
			return NDWD_WS::err( 'A package can only go on a project that belongs to a client.', 404, 'ndwd_not_found' );
		}
		$conn = NDWD_WS::connection_for_project( $project_id, (int) $p->client_id );
		if ( ! $conn ) {
			return NDWD_WS::err( 'This client has no Workspace yet. Invite them first.', 409, 'ndwd_no_workspace' );
		}
		$c = self::catalogue_row( absint( $in['catalogue_id'] ?? 0 ) );
		if ( ! $c ) {
			return NDWD_WS::err( 'Choose a package from the catalogue.' );
		}
		if ( ! (int) $c->is_active ) {
			return NDWD_WS::err( '"' . $c->name . '" has been withdrawn, so it cannot be assigned.' );
		}
		if ( self::open_migration( $project_id ) ) {
			return NDWD_WS::err( 'This project is moving to its own subscription. Correct or reverse the migration instead.', 409, 'ndwd_conflict' );
		}
		$terms = self::clean_terms( $in, array( 'start_date' => NDWD_Helpers::today(), 'expires_on' => null, 'billing_frequency' => 'month', 'custom_price' => null, 'grace_days' => (int) $c->grace_days, 'quantity' => 1, 'usage_limit' => $c->usage_limit, 'notes' => '' ) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$original = self::price_from_catalogue( $c, $terms['billing_frequency'] );
		if ( null !== $terms['custom_price'] && null !== $original && abs( $terms['custom_price'] - $original ) < 0.005 ) {
			$terms['custom_price'] = null; // Same as the catalogue, so nothing was customised.
		}
		if ( null !== $terms['custom_price'] && ! user_can( $user_id, 'ndwd_manage_accounts' ) && ! NDWD_Permissions::can( 'ndwd_manage_accounts', $user_id ) ) {
			return NDWD_WS::err( 'Only staff with Accounts access can set a custom price.', 403, 'ndwd_forbidden' );
		}
		if ( null === $terms['custom_price'] && null === $original ) {
			return NDWD_WS::err( 'This package has no catalogue price yet, so enter the price for this project. It can be 0.' );
		}
		$t = NDWD_WS::t( 'project_packages' );
		if ( 'addon' === $c->kind ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE project_id = %d AND catalogue_id = %d AND status = 'active'", $project_id, $c->id ) ) ) { // phpcs:ignore
				return NDWD_WS::err( 'This add-on is already on this project.', 409, 'ndwd_conflict' );
			}
			if ( ! self::live_plan( $project_id ) ) {
				return NDWD_WS::err( 'Assign a package to this project before adding an add-on.', 409, 'ndwd_conflict' );
			}
		}
		$price = null !== $terms['custom_price'] ? $terms['custom_price'] : $original;
		$alloc = self::open_allocation( (int) $p->client_id, $project_id, $c, $terms, $price, $user_id );
		if ( is_wp_error( $alloc ) ) {
			return $alloc;
		}
		$now = NDWD_Helpers::now_mysql();
		if ( 'plan' === $c->kind ) {
			$old = self::live_plan( $project_id );
			if ( $old ) {
				$wpdb->update( $t, array( 'status' => 'replaced', 'ended_at' => $now, 'ended_by' => (int) $user_id, 'updated_at' => $now ), array( 'id' => $old->id ) );
				self::close_allocation( $old->client_package_id, $user_id );
			}
		}
		$wpdb->insert( $t, array(
			'connection_id'     => (int) $conn->id,
			'client_id'         => (int) $p->client_id,
			'project_id'        => (int) $project_id,
			'kind'              => $c->kind,
			'catalogue_id'      => (int) $c->id,
			'catalogue_code'    => $c->code,
			'catalogue_version' => (int) $c->version,
			'name'              => $c->name,
			'features'          => 'addon' === $c->kind ? wp_json_encode( array( $c->feature_code => array() ) ) : $c->features,
			'original_price'    => $original,
			'custom_price'      => $terms['custom_price'],
			'billing_frequency' => $terms['billing_frequency'],
			'quantity'          => $terms['quantity'],
			'usage_limit'       => $terms['usage_limit'],
			'start_date'        => $terms['start_date'],
			'expires_on'        => $terms['expires_on'],
			'grace_days'        => $terms['grace_days'],
			'status'            => 'active',
			'client_package_id' => $alloc,
			'notes'             => $terms['notes'],
			'created_by'        => (int) $user_id,
			'created_at'        => $now,
			'updated_at'        => $now,
		) );
		$id = (int) $wpdb->insert_id;
		NDWD_WS::audit( 'project_package_assigned', array( 'connection_id' => $conn->id, 'entity' => 'project', 'entity_id' => $project_id, 'details' => array( 'assignment' => $id, 'package' => $c->code, 'version' => (int) $c->version, 'original' => $original, 'custom' => $terms['custom_price'] ) ), $user_id );
		self::sync_entitlements( $conn, $user_id );
		NDWD_WS_Webhooks::emit( 'project.package_changed', $conn->id, array( 'project_id' => (int) $project_id ) );
		return self::assignment_array( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ) ); // phpcs:ignore
	}

	public static function update_assignment( $id, $in, $user_id ) {
		global $wpdb;
		$t = NDWD_WS::t( 'project_packages' );
		$a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $a || 'active' !== $a->status ) {
			return NDWD_WS::err( 'Only a live assignment can be changed.', 404, 'ndwd_not_found' );
		}
		$terms = self::clean_terms( $in, array( 'start_date' => $a->start_date ? $a->start_date : NDWD_Helpers::today(), 'expires_on' => $a->expires_on, 'billing_frequency' => 'agency' === $a->billing_frequency ? 'month' : $a->billing_frequency, 'custom_price' => $a->custom_price, 'grace_days' => $a->grace_days, 'quantity' => $a->quantity, 'usage_limit' => $a->usage_limit, 'notes' => $a->notes ) );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		if ( null !== $terms['custom_price'] && (string) self::money( $a->custom_price ) !== (string) (float) $terms['custom_price'] && ! NDWD_Permissions::can( 'ndwd_manage_accounts', $user_id ) ) {
			return NDWD_WS::err( 'Only staff with Accounts access can set a custom price.', 403, 'ndwd_forbidden' );
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->update( $t, array_merge( $terms, array( 'updated_at' => $now ) ), array( 'id' => $id ) );
		if ( $a->client_package_id ) {
			$price = null !== $terms['custom_price'] ? $terms['custom_price'] : self::money( $a->original_price );
			NDWD_Packages::update_allocation( (int) $a->client_package_id, array( 'price' => null === $price ? 0 : $price, 'end_date' => $terms['expires_on'] ? $terms['expires_on'] : '' ), $user_id );
			NDWD_Packages::flush();
		}
		NDWD_WS::audit( 'project_package_updated', array( 'connection_id' => $a->connection_id, 'entity' => 'project', 'entity_id' => $a->project_id, 'details' => array( 'assignment' => (int) $id, 'from' => array_intersect_key( (array) $a, $terms ), 'to' => $terms ) ), $user_id );
		$conn = NDWD_WS::connection( (int) $a->connection_id );
		self::sync_entitlements( $conn, $user_id );
		NDWD_WS_Webhooks::emit( 'project.package_changed', $conn->id, array( 'project_id' => (int) $a->project_id ) );
		return self::assignment_array( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ) ); // phpcs:ignore
	}

	/** Ends the assignment and locks the project if it was the package. The row stays as history. */
	public static function remove_assignment( $id, $user_id ) {
		global $wpdb;
		$t = NDWD_WS::t( 'project_packages' );
		$a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
		if ( ! $a || 'active' !== $a->status ) {
			return NDWD_WS::err( 'Only a live assignment can be removed.', 404, 'ndwd_not_found' );
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->update( $t, array( 'status' => 'removed', 'ended_at' => $now, 'ended_by' => (int) $user_id, 'updated_at' => $now ), array( 'id' => $id ) );
		self::close_allocation( $a->client_package_id, $user_id );
		NDWD_WS::audit( 'project_package_removed', array( 'connection_id' => $a->connection_id, 'entity' => 'project', 'entity_id' => $a->project_id, 'details' => array( 'assignment' => (int) $id, 'name' => $a->name ) ), $user_id );
		$conn = NDWD_WS::connection( (int) $a->connection_id );
		if ( $conn ) {
			self::sync_entitlements( $conn, $user_id );
			NDWD_WS_Webhooks::emit( 'project.package_changed', $conn->id, array( 'project_id' => (int) $a->project_id ) );
		}
		return array( 'ok' => true );
	}

	/**
	 * Workspace features follow the live packages. A feature that came from a package carries the note
	 * "package", and only those rows change here. Whatever staff set by hand stays as they set it.
	 * Opening one project never opens another: project access is decided by access().
	 */
	public static function sync_entitlements( $conn, $user_id ) {
		global $wpdb;
		$want = array();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'project_packages' ) . " WHERE connection_id = %d AND status = 'active' AND kind IN ('plan','addon')", $conn->id ) ); // phpcs:ignore
		foreach ( $rows as $a ) {
			$codes = array_keys( (array) json_decode( (string) $a->features, true ) );
			foreach ( array_merge( $codes, array( 'agency_connect', 'calendar_agency' ) ) as $code ) {
				if ( ! isset( NDWD_WS::FEATURES[ $code ] ) ) {
					continue;
				}
				$cur = $want[ $code ] ?? null;
				$end = $a->expires_on ? ( $a->expires_on ) : null;
				if ( $cur ) {
					$end = null === $cur['expires_on'] || null === $end ? null : max( $cur['expires_on'], $end );
				}
				$start = $cur && $cur['starts_on'] && $a->start_date ? min( $cur['starts_on'], $a->start_date ) : ( $cur ? $cur['starts_on'] : $a->start_date );
				$want[ $code ] = array( 'feature_code' => $code, 'enabled' => true, 'starts_on' => $start, 'expires_on' => $end, 'grace_days' => (int) $a->grace_days, 'usage_limit' => null, 'notes' => 'package' );
			}
		}
		$list = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'entitlements' ) . ' WHERE connection_id = %d', $conn->id ) ) as $e ) { // phpcs:ignore
			if ( isset( $want[ $e->feature_code ] ) ) {
				if ( 'package' !== (string) $e->notes && (int) $e->enabled ) {
					unset( $want[ $e->feature_code ] ); // Staff already granted this by hand.
				}
			} elseif ( 'package' === (string) $e->notes && (int) $e->enabled ) {
				$list[] = array( 'feature_code' => $e->feature_code, 'enabled' => false, 'starts_on' => $e->starts_on, 'expires_on' => $e->expires_on, 'notes' => 'package' );
			}
		}
		$list = array_merge( $list, array_values( $want ) );
		if ( $list ) {
			NDWD_WS::save_entitlements( $conn, $list, $user_id );
		}
	}

	/* ---------------------------- Migration ---------------------------- */

	private static function migration_array( $m ) {
		global $wpdb;
		$client = NDWD_Clients::get( (int) $m->client_id );
		$p      = self::project_row( (int) $m->project_id );
		$conn   = NDWD_WS::connection( (int) $m->connection_id );
		$left   = (int) floor( ( strtotime( $m->grace_ends_on ) - strtotime( NDWD_Helpers::today() ) ) / DAY_IN_SECONDS );
		$audit  = $wpdb->get_results( $wpdb->prepare( 'SELECT action, actor, details, created_at FROM ' . NDWD_WS::t( 'audit' ) . " WHERE entity = 'migration' AND entity_id = %d ORDER BY id DESC LIMIT 30", $m->id ) ); // phpcs:ignore
		return array(
			'id'             => (int) $m->id,
			'project_id'     => (int) $m->project_id,
			'project_name'   => $p ? $p->name : '',
			'client_id'      => (int) $m->client_id,
			'client_name'    => $client ? $client->name : '',
			'connection_id'  => (int) $m->connection_id,
			'workspace_name' => $conn ? (string) $conn->workspace_name : '',
			'previous'       => json_decode( (string) $m->previous, true ),
			'plan_code'      => $m->plan_code,
			'package_name'   => $m->package_name,
			'original_price' => self::money( $m->original_price ),
			'custom_price'   => self::money( $m->custom_price ),
			'billing_cycle'  => $m->billing_cycle,
			'grace_days'     => (int) $m->grace_days,
			'grace_ends_on'  => $m->grace_ends_on,
			'days_left'      => $left,
			'status'         => $m->status,
			'period_end'     => $m->period_end,
			'notes'          => (string) $m->notes,
			'created_by'     => $m->created_by ? NDWD_Helpers::user_name( (int) $m->created_by ) : '',
			'created_at'     => $m->created_at,
			'activated_at'   => $m->activated_at,
			'expired_at'     => $m->expired_at,
			'reversed_at'    => $m->reversed_at,
			'reverse_reason' => (string) $m->reverse_reason,
			'history'        => $audit,
		);
	}

	/**
	 * Moves ONE project to its own subscription. Everything else about the client stays as it was.
	 * The unique open_key makes a second migration of the same project impossible, even in a race.
	 *
	 * @return array|WP_Error
	 */
	public static function migrate( $project_id, $in, $user_id ) {
		global $wpdb;
		$p = self::project_row( $project_id );
		if ( ! $p || ! $p->client_id ) {
			return NDWD_WS::err( 'Project not found.', 404, 'ndwd_not_found' );
		}
		$conn = NDWD_WS::connection_for_project( $project_id, (int) $p->client_id );
		if ( ! $conn || 'active' !== $conn->status || ! $conn->linked_at ) {
			return NDWD_WS::err( 'This project needs a connected Workspace to move to.', 409, 'ndwd_no_workspace' );
		}
		$c = self::catalogue_row( absint( $in['catalogue_id'] ?? 0 ) );
		if ( ! $c || 'plan' !== $c->kind || ! (int) $c->is_active || (int) $c->is_trial ) {
			return NDWD_WS::err( 'Choose an independent Workspace package from the catalogue.' );
		}
		$cycle = 'year' === ( $in['billing_cycle'] ?? '' ) ? 'year' : 'month';
		$grace = isset( $in['grace_days'] ) && '' !== $in['grace_days'] ? min( 365, absint( $in['grace_days'] ) ) : (int) $c->grace_days;
		$orig  = self::price_from_catalogue( $c, $cycle );
		$custom = isset( $in['custom_price'] ) && '' !== (string) $in['custom_price'] ? $in['custom_price'] : null;
		if ( null !== $custom && ( ! is_numeric( $custom ) || (float) $custom < 0 ) ) {
			return NDWD_WS::err( 'Enter a price of 0 or more.' );
		}
		$custom = null === $custom ? null : round( (float) $custom, 2 );
		if ( null !== $custom && null !== $orig && abs( $custom - $orig ) < 0.005 ) {
			$custom = null;
		}
		if ( null !== $custom && ! NDWD_Permissions::can( 'ndwd_manage_accounts', $user_id ) ) {
			return NDWD_WS::err( 'Only staff with Accounts access can set a custom price.', 403, 'ndwd_forbidden' );
		}
		if ( null === $custom && null === $orig ) {
			return NDWD_WS::err( 'This package has no catalogue price yet, so enter the price for this project. It can be 0.' );
		}
		$now  = NDWD_Helpers::now_mysql();
		$prev = self::live_plan( $project_id );
		$wpdb->suppress_errors( true );
		$ok = $wpdb->insert( NDWD_WS::t( 'migrations' ), array(
			'connection_id'  => (int) $conn->id,
			'client_id'      => (int) $p->client_id,
			'project_id'     => (int) $project_id,
			'open_key'       => (int) $project_id,
			'previous'       => wp_json_encode( $prev ? array( 'assignment_id' => (int) $prev->id, 'name' => $prev->name, 'kind' => $prev->kind, 'price' => null !== $prev->custom_price ? (float) $prev->custom_price : self::money( $prev->original_price ), 'billing' => $prev->billing_frequency, 'start_date' => $prev->start_date, 'expires_on' => $prev->expires_on, 'client_package_id' => $prev->client_package_id ? (int) $prev->client_package_id : null ) : array( 'name' => 'No package' ) ),
			'assignment_id'  => $prev ? (int) $prev->id : null,
			'catalogue_id'   => (int) $c->id,
			'plan_code'      => $c->code,
			'package_name'   => $c->name,
			'original_price' => $orig,
			'custom_price'   => $custom,
			'billing_cycle'  => $cycle,
			'grace_days'     => $grace,
			'grace_ends_on'  => gmdate( 'Y-m-d', strtotime( NDWD_Helpers::today() ) + $grace * DAY_IN_SECONDS ),
			'status'         => 'pending',
			'notes'          => mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 0, 1000 ),
			'created_by'     => (int) $user_id,
			'created_at'     => $now,
			'updated_at'     => $now,
		) );
		$wpdb->suppress_errors( false );
		if ( ! $ok ) {
			return NDWD_WS::err( 'This project is already being moved to its own subscription.', 409, 'ndwd_conflict' );
		}
		$id = (int) $wpdb->insert_id;
		// The agency agreement ends here, so the two billing arrangements never run together.
		if ( $prev ) {
			$wpdb->update( NDWD_WS::t( 'project_packages' ), array( 'status' => 'migrated', 'ended_at' => $now, 'ended_by' => (int) $user_id, 'updated_at' => $now ), array( 'id' => $prev->id ) );
			self::close_allocation( $prev->client_package_id, $user_id );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'project_packages' ) . " WHERE project_id = %d AND kind = 'addon' AND status = 'active'", $project_id ) ) as $add ) { // phpcs:ignore
			$wpdb->update( NDWD_WS::t( 'project_packages' ), array( 'status' => 'migrated', 'ended_at' => $now, 'ended_by' => (int) $user_id, 'updated_at' => $now ), array( 'id' => $add->id ) );
			self::close_allocation( $add->client_package_id, $user_id );
		}
		self::log_migration( 'migration_started', $id, $conn->id, $user_id, array( 'project_id' => (int) $project_id, 'package' => $c->code, 'original' => $orig, 'custom' => $custom, 'grace_days' => $grace ) );
		self::sync_entitlements( $conn, $user_id );
		NDWD_WS_Webhooks::emit( 'project.migration_started', $conn->id, array( 'project_id' => (int) $project_id, 'migration_id' => $id, 'days_left' => $grace ) );
		self::tell_client( $conn, $p, $grace, $c->name );
		return self::migration_array( self::migration( $id ) );
	}

	private static function tell_client( $conn, $p, $grace, $package ) {
		global $wpdb;
		$email = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT accepted_email FROM ' . NDWD_WS::t( 'invitations' ) . " WHERE connection_id = %d AND accepted_email <> '' ORDER BY id DESC LIMIT 1", $conn->id ) ); // phpcs:ignore
		if ( '' === $email ) {
			return;
		}
		$url  = NDWD_WS::workspace_url() . '/app/#/agency/projects/' . (int) $p->id;
		$html = NDWD_Notifications::template( 'Your project is moving to its own subscription', '<p>NeonDigital\'s agency arrangement for <b>' . esc_html( $p->name ) . '</b> is ending. All its data stays in place. To keep using it, activate the <b>' . esc_html( $package ) . '</b> subscription within ' . (int) $grace . ' days. After that only this project locks, and nothing is deleted.</p>', $url, 'Review and activate' );
		NDWD_Notifications::send_to( $email, 'Your project is moving to its own subscription', $html, 'workspace_migration' );
	}

	private static function log_migration( $action, $id, $conn_id, $user_id, $details = array(), $kind = 'admin' ) {
		NDWD_WS::audit( $action, array( 'kind' => $kind, 'connection_id' => $conn_id, 'entity' => 'migration', 'entity_id' => $id, 'details' => $details ), $user_id );
	}

	public static function migration( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'migrations' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/** Correcting a migration still under way: its grace period, price or package. */
	public static function update_migration( $id, $in, $user_id ) {
		global $wpdb;
		$m = self::migration( $id );
		if ( ! $m ) {
			return NDWD_WS::err( 'Migration not found.', 404, 'ndwd_not_found' );
		}
		if ( ! in_array( $m->status, array( 'pending', 'expired' ), true ) ) {
			return NDWD_WS::err( 'Only a migration waiting for activation can be corrected.', 409, 'ndwd_conflict' );
		}
		$row = array();
		if ( isset( $in['grace_days'] ) && '' !== $in['grace_days'] ) {
			$row['grace_days']    = min( 365, absint( $in['grace_days'] ) );
			$row['grace_ends_on'] = gmdate( 'Y-m-d', strtotime( substr( $m->created_at, 0, 10 ) ) + $row['grace_days'] * DAY_IN_SECONDS );
			if ( 'expired' === $m->status && $row['grace_ends_on'] >= NDWD_Helpers::today() ) {
				$row['status']     = 'pending';
				$row['expired_at'] = null;
				$row['reminders']  = null;
			}
		}
		if ( array_key_exists( 'custom_price', $in ) ) {
			$v = '' === (string) $in['custom_price'] ? null : $in['custom_price'];
			if ( null !== $v && ( ! is_numeric( $v ) || (float) $v < 0 ) ) {
				return NDWD_WS::err( 'Enter a price of 0 or more.' );
			}
			if ( ! NDWD_Permissions::can( 'ndwd_manage_accounts', $user_id ) ) {
				return NDWD_WS::err( 'Only staff with Accounts access can set a custom price.', 403, 'ndwd_forbidden' );
			}
			$row['custom_price'] = null === $v ? null : round( (float) $v, 2 );
		}
		if ( ! empty( $in['catalogue_id'] ) ) {
			$c = self::catalogue_row( absint( $in['catalogue_id'] ) );
			if ( ! $c || 'plan' !== $c->kind || ! (int) $c->is_active ) {
				return NDWD_WS::err( 'Choose an active package.' );
			}
			$row += array( 'catalogue_id' => (int) $c->id, 'plan_code' => $c->code, 'package_name' => $c->name, 'original_price' => self::price_from_catalogue( $c, $m->billing_cycle ) );
		}
		if ( ! $row ) {
			return NDWD_WS::err( 'Nothing to change.' );
		}
		$row['updated_at'] = NDWD_Helpers::now_mysql();
		$wpdb->update( NDWD_WS::t( 'migrations' ), $row, array( 'id' => $id ) );
		self::log_migration( 'migration_corrected', $id, $m->connection_id, $user_id, array( 'changes' => $row ) );
		NDWD_WS_Webhooks::emit( 'project.package_changed', (int) $m->connection_id, array( 'project_id' => (int) $m->project_id ) );
		return self::migration_array( self::migration( $id ) );
	}

	/** Puts the project back on the arrangement it had. A subscription already paid must be refunded by hand. */
	public static function reverse_migration( $id, $in, $user_id ) {
		global $wpdb;
		$m = self::migration( $id );
		if ( ! $m ) {
			return NDWD_WS::err( 'Migration not found.', 404, 'ndwd_not_found' );
		}
		if ( 'reversed' === $m->status ) {
			return self::migration_array( $m );
		}
		$why = trim( sanitize_textarea_field( (string) ( $in['reason'] ?? '' ) ) );
		if ( '' === $why ) {
			return NDWD_WS::err( 'Give the reason for reversing this migration.' );
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->update( NDWD_WS::t( 'migrations' ), array( 'status' => 'reversed', 'open_key' => null, 'reversed_at' => $now, 'reversed_by' => (int) $user_id, 'reverse_reason' => $why, 'updated_at' => $now ), array( 'id' => $id ) );
		// Everything this migration ended on the project comes back, the add-ons with the package.
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'project_packages' ) . " WHERE project_id = %d AND status = 'migrated'", $m->project_id ) ) as $a ) { // phpcs:ignore
			$wpdb->update( NDWD_WS::t( 'project_packages' ), array( 'status' => 'active', 'ended_at' => null, 'ended_by' => null, 'updated_at' => $now ), array( 'id' => (int) $a->id ) );
			if ( $a->client_package_id ) {
				self::close_allocation( (int) $a->client_package_id, $user_id, 'active' );
			}
		}
		self::log_migration( 'migration_reversed', $id, $m->connection_id, $user_id, array( 'reason' => $why, 'was' => $m->status ) );
		$conn = NDWD_WS::connection( (int) $m->connection_id );
		if ( $conn ) {
			self::sync_entitlements( $conn, $user_id );
			NDWD_WS_Webhooks::emit( 'project.migration_reversed', $conn->id, array( 'project_id' => (int) $m->project_id, 'migration_id' => (int) $id ) );
		}
		return self::migration_array( self::migration( $id ) );
	}

	/**
	 * Called by Workspace after the subscription for this project is paid or activated. Repeats are
	 * harmless. A reversed migration refuses, and the client sees that instead of being charged twice.
	 */
	public static function activate_migration( $conn, $id, $in ) {
		global $wpdb;
		$m = self::migration( $id );
		if ( ! $m || (int) $m->connection_id !== (int) $conn->id ) {
			return NDWD_WS::err( 'Migration not found.', 404, 'ndwd_not_found' );
		}
		if ( 'reversed' === $m->status ) {
			return NDWD_WS::err( 'NeonDigital has reversed this move. No subscription is needed.', 409, 'ndwd_migration_reversed' );
		}
		$end = isset( $in['period_end'] ) ? NDWD_Helpers::sanitize_date( $in['period_end'] ) : null;
		$now = NDWD_Helpers::now_mysql();
		$row = array( 'status' => 'active', 'period_end' => $end ? $end : null, 'external_ref' => mb_substr( sanitize_text_field( (string) ( $in['external_ref'] ?? '' ) ), 0, 64 ), 'expired_at' => null, 'updated_at' => $now );
		if ( ! $m->activated_at ) {
			$row['activated_at'] = $now;
		}
		$wpdb->update( NDWD_WS::t( 'migrations' ), $row, array( 'id' => $id ) );
		self::log_migration( 'active' === $m->status ? 'migration_renewed' : 'migration_activated', $id, $conn->id, 0, array( 'period_end' => $row['period_end'], 'ref' => $row['external_ref'] ), 'api' );
		$p = self::project_row( (int) $m->project_id );
		NDWD_Notifications::notify_users( array( (int) $m->created_by ), null, 'updated', 'Subscription activated for project ' . ( $p ? $p->name : '#' . $m->project_id ), 0, 'mig-act:' . $id . ':' . $row['period_end'], '#/workspace/packages/migrations' );
		return array( 'ok' => true, 'status' => 'active', 'access' => self::client_access( self::access( $conn, (int) $m->project_id ) ) );
	}

	/* ---------------------------- Scheduled work ---------------------------- */

	public static function tick() {
		return array( 'requests' => NDWD_WS_Requests::escalate_due(), 'migrations' => self::migrations_due() );
	}

	/** Reminders before a project's own grace period ends, then only that project locks. */
	public static function migrations_due() {
		global $wpdb;
		$today = NDWD_Helpers::today();
		$out   = array( 'reminded' => 0, 'expired' => 0, 'lapsed' => 0 );
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'migrations' ) . " WHERE status = 'pending'" ) as $m ) { // phpcs:ignore
			$left = (int) floor( ( strtotime( $m->grace_ends_on ) - strtotime( $today ) ) / DAY_IN_SECONDS );
			if ( $left < 0 ) {
				$won = $wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'migrations' ) . " SET status = 'expired', expired_at = %s, updated_at = %s WHERE id = %d AND status = 'pending'", NDWD_Helpers::now_mysql(), NDWD_Helpers::now_mysql(), $m->id ) ); // phpcs:ignore
				if ( $won ) {
					self::log_migration( 'migration_expired', (int) $m->id, $m->connection_id, 0 );
					NDWD_WS_Webhooks::emit( 'project.locked', (int) $m->connection_id, array( 'project_id' => (int) $m->project_id, 'migration_id' => (int) $m->id ) );
					NDWD_Notifications::notify_users( array( (int) $m->created_by ), null, 'updated', 'A migrated project was locked: its grace period ended with no subscription.', 0, 'mig-exp:' . $m->id, '#/workspace/packages/migrations' );
					$out['expired']++;
				}
				continue;
			}
			$sent = (array) json_decode( (string) $m->reminders, true );
			foreach ( self::REMIND_AT as $at ) {
				if ( $left <= $at && ! in_array( $at, $sent, true ) && (int) $m->grace_days > $at ) {
					$sent[] = $at;
					$wpdb->update( NDWD_WS::t( 'migrations' ), array( 'reminders' => wp_json_encode( $sent ) ), array( 'id' => $m->id ) );
					NDWD_WS_Webhooks::emit( 'project.migration_reminder', (int) $m->connection_id, array( 'project_id' => (int) $m->project_id, 'migration_id' => (int) $m->id, 'days_left' => $left ) );
					self::log_migration( 'migration_reminder_sent', (int) $m->id, $m->connection_id, 0, array( 'days_left' => $left ) );
					$out['reminded']++;
					break;
				}
			}
		}
		return $out;
	}

	/* ---------------------------- Task panels ---------------------------- */

	public static function request_for_task( $t ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE task_id = %d', $t->id ) ); // phpcs:ignore
		return $r ? array_merge( NDWD_WS_Requests::to_array( $r, true ), array( 'progress' => self::request_progress( $r ) ) ) : null;
	}

	public static function request_progress( $r ) {
		$task  = $r->task_id ? NDWD_Tasks::get( (int) $r->task_id ) : null;
		$steps = array(
			array( 'key' => 'received', 'label' => 'Request received', 'done' => true, 'at' => $r->created_at ),
			array( 'key' => 'reviewed', 'label' => 'Request reviewed', 'done' => (bool) $r->reviewed_by || ! in_array( $r->status, array( 'submitted' ), true ), 'at' => $r->reviewed_at ),
			array( 'key' => 'assigned', 'label' => 'Work assigned', 'done' => $task && $task->assignee_ids ),
			array( 'key' => 'started', 'label' => 'Work started', 'done' => in_array( $r->status, array( 'in_progress', 'completed' ), true ) ),
			array( 'key' => 'completed', 'label' => 'Work completed', 'done' => 'completed' === $r->status ),
		);
		if ( 'rejected' === $r->status ) {
			$steps[] = array( 'key' => 'rejected', 'label' => 'Rejected', 'done' => true, 'at' => $r->reviewed_at, 'note' => (string) $r->review_note );
		}
		return $steps;
	}

	public static function deliverables_for_task( $t ) {
		global $wpdb;
		return array_map( function ( $d ) {
			return NDWD_WS_Requests::deliverable_array( $d, true );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'deliverables' ) . ' WHERE task_id = %d ORDER BY id DESC', $t->id ) ) ); // phpcs:ignore
	}

	public static function client_deliverables_for_task( $conn, $task_id ) {
		global $wpdb;
		return array_map( array( 'NDWD_WS_Requests', 'deliverable_array' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'deliverables' ) . ' WHERE task_id = %d AND connection_id = %d AND client_visible = 1 ORDER BY id DESC', $task_id, $conn->id ) ) ); // phpcs:ignore
	}

	public static function rest_task_review( WP_REST_Request $r ) {
		global $wpdb;
		$t = NDWD_Tasks::get( (int) $r['id'] );
		if ( ! $t || ! NDWD_Permissions::can_edit_task( $t ) ) {
			return NDWD_WS::err( 'You cannot change this task.', 403, 'ndwd_forbidden' );
		}
		$q = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE task_id = %d', $t->id ) ); // phpcs:ignore
		if ( ! $q ) {
			return NDWD_WS::err( 'This task did not come from a client request.', 404, 'ndwd_not_found' );
		}
		$res = NDWD_WS_Requests::review( (int) $q->id, self::body( $r ), get_current_user_id() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( NDWD_WS_Requests::to_array( $res, true ) );
	}

	public static function rest_task_deliverable( WP_REST_Request $r ) {
		$t = NDWD_Tasks::get( (int) $r['id'] );
		if ( ! $t || ! NDWD_Permissions::can_edit_task( $t ) ) {
			return NDWD_WS::err( 'You cannot change this task.', 403, 'ndwd_forbidden' );
		}
		$conn = NDWD_WS::connection_for_task( $t );
		if ( ! $conn ) {
			return NDWD_WS::err( 'This client has no Workspace, so a deliverable has nowhere to go.', 409, 'ndwd_no_workspace' );
		}
		$did = isset( $r['did'] ) ? (int) $r['did'] : 0;
		if ( $did ) {
			$d = NDWD_WS_Requests::deliverable( $did );
			if ( ! $d || (int) $d->task_id !== (int) $t->id ) {
				return NDWD_WS::err( 'Deliverable not found.', 404, 'ndwd_not_found' );
			}
		}
		$b = array_merge( self::body( $r ), array( 'connection_id' => (int) $conn->id, 'task_id' => (int) $t->id, 'project_id' => (int) $t->project_id ) );
		global $wpdb;
		$q = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE task_id = %d', $t->id ) ); // phpcs:ignore
		if ( $q ) {
			$b['request_id'] = (int) $q;
		}
		$res = NDWD_WS_Requests::save_deliverable( $did, $b, get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $did ) {
			NDWD_Activity::log( get_current_user_id(), $t->id, 'deliverable_added', array( 'title' => $res->title ) );
		}
		return rest_ensure_response( NDWD_WS_Requests::deliverable_array( $res, true ) );
	}

	/* ---------------------------- Screens ---------------------------- */

	private static function project_tree( $conn, $p ) {
		global $wpdb;
		$a    = self::access( $conn, (int) $p->id );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'project_packages' ) . " WHERE project_id = %d AND status = 'active' ORDER BY kind DESC, id DESC", $p->id ) ); // phpcs:ignore
		$plan = null;
		$adds = array();
		foreach ( $rows as $x ) {
			if ( 'addon' === $x->kind ) {
				$adds[] = self::assignment_array( $x );
			} elseif ( ! $plan ) {
				$plan = self::assignment_array( $x );
			}
		}
		return array(
			'id'         => (int) $p->id,
			'name'       => $p->name,
			'state'      => $a['state'],
			'open'       => $a['open'],
			'message'    => $a['message'],
			'package'    => $plan,
			'addons'     => $adds,
			'migration'  => $a['migration'] ? array( 'id' => (int) $a['migration']->id, 'status' => $a['migration']->status, 'package_name' => $a['migration']->package_name, 'grace_ends_on' => $a['migration']->grace_ends_on ) : null,
		);
	}

	private static function connection_tree( $c ) {
		$key = NDWD_WS_Manage::status_key( $c );
		$dir = self::directory_row( (string) $c->workspace_uid );
		return array(
			'connection_id'  => (int) $c->id,
			'workspace_uid'  => (string) $c->workspace_uid,
			'workspace_name' => (string) $c->workspace_name,
			'status'         => $key,
			'status_label'   => NDWD_WS_Manage::STATUS_LABELS[ $key ],
			'active'         => 'active' === $c->status,
			'linked_at'      => $c->linked_at,
			'disconnected_at' => $c->disconnected_at,
			'last_seen_at'   => $c->last_seen_at,
			'plan_code'      => $dir ? $dir->plan_code : '',
			'projects'       => array_map( function ( $p ) use ( $c ) {
				return self::project_tree( $c, $p );
			}, self::projects_of( $c ) ),
		);
	}

	private static function directory_row( $uid ) {
		global $wpdb;
		return '' === $uid ? null : $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'directory' ) . ' WHERE workspace_uid = %s', $uid ) ); // phpcs:ignore
	}

	/** Same digest Workspace sends, so a person's address is matched without being shared. */
	private static function owner_hash( $email ) {
		list( , $secret ) = NDWD_WS::site_credentials();
		return '' === (string) $secret || '' === (string) $email ? '' : hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), $secret );
	}

	/** owner hash → client, from each client's email and every email that accepted one of their invitations. */
	private static function client_hash_map() {
		global $wpdb;
		$map = array();
		foreach ( $wpdb->get_results( 'SELECT id, email FROM ' . NDWD_Helpers::table( 'clients' ) . " WHERE email <> ''" ) as $c ) { // phpcs:ignore
			$map[ self::owner_hash( $c->email ) ] = (int) $c->id;
		}
		foreach ( $wpdb->get_results( 'SELECT c.client_id, i.accepted_email FROM ' . NDWD_WS::t( 'invitations' ) . ' i JOIN ' . NDWD_WS::t( 'connections' ) . " c ON c.id = i.connection_id WHERE i.accepted_email <> ''" ) as $x ) { // phpcs:ignore
			$map[ self::owner_hash( $x->accepted_email ) ] = (int) $x->client_id;
		}
		unset( $map[''] );
		return $map;
	}

	public static function rest_tree( WP_REST_Request $r ) {
		global $wpdb;
		$client = NDWD_Clients::get( (int) $r['client_id'] );
		if ( ! $client ) {
			return NDWD_WS::err( 'Client not found.', 404, 'ndwd_not_found' );
		}
		$conns = NDWD_WS::connections_for_client( $client->id );
		$out   = array_map( array( __CLASS__, 'connection_tree' ), $conns );
		$known = wp_list_pluck( $out, 'workspace_uid' );
		$extra = array();
		$map   = self::client_hash_map();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'directory' ) . " WHERE owner_hash <> ''" ) as $d ) { // phpcs:ignore
			if ( ( $map[ $d->owner_hash ] ?? 0 ) === (int) $client->id && ! in_array( $d->workspace_uid, $known, true ) ) {
				$extra[] = array( 'workspace_uid' => $d->workspace_uid, 'name' => $d->name, 'plan_code' => $d->plan_code, 'status' => $d->subscription_status, 'period_end' => $d->period_end );
			}
		}
		return rest_ensure_response( array(
			'client'     => array( 'id' => (int) $client->id, 'name' => $client->name, 'company' => (string) $client->company, 'email' => (string) $client->email, 'phone' => (string) $client->phone, 'contact_person' => (string) $client->contact_person ),
			'workspaces' => $out,
			'independent' => $extra,
		) );
	}

	/** All Workspaces: every known Workspace with its client, active ones by default. */
	public static function rest_all( WP_REST_Request $r ) {
		global $wpdb;
		$inactive = (bool) $r->get_param( 'show_inactive' );
		$map      = self::client_hash_map();
		$conns    = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'connections' ) . ' ORDER BY id ASC' ) as $c ) { // phpcs:ignore
			if ( '' !== (string) $c->workspace_uid ) {
				$conns[ $c->workspace_uid ] = $c;
			}
		}
		$items = array();
		$seen  = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'directory' ) . ' ORDER BY name ASC LIMIT 1000' ) as $d ) { // phpcs:ignore
			$c   = $conns[ $d->workspace_uid ] ?? null;
			$cid = $c ? (int) $c->client_id : ( $map[ $d->owner_hash ] ?? 0 );
			$cl  = $cid ? NDWD_Clients::get( $cid ) : null;
			$seen[ $d->workspace_uid ] = true;
			$items[] = self::workspace_row( $d->workspace_uid, $d->name, $d, $c, $cl );
		}
		foreach ( $conns as $uid => $c ) { // Linked, not yet in the directory.
			if ( ! isset( $seen[ $uid ] ) ) {
				$items[] = self::workspace_row( $uid, $c->workspace_name, null, $c, NDWD_Clients::get( (int) $c->client_id ) );
			}
		}
		$all   = count( $items );
		$items = array_values( array_filter( $items, function ( $x ) use ( $inactive ) {
			return $inactive || $x['active'];
		} ) );
		return rest_ensure_response( array( 'items' => $items, 'total' => $all, 'hidden' => $all - count( $items ) ) );
	}

	private static function workspace_row( $uid, $name, $d, $c, $cl ) {
		$key = $c ? NDWD_WS_Manage::status_key( $c ) : 'independent';
		return array(
			'workspace_uid' => $uid,
			'name'          => $name,
			'account_type'  => $d ? $d->account_type : 'agency',
			'plan_code'     => $d ? $d->plan_code : '',
			'subscription_status' => $d ? $d->subscription_status : '',
			'period_end'    => $d ? $d->period_end : null,
			'sharing_consent_at' => $d ? $d->sharing_consent_at : null,
			'synced_at'     => $d ? $d->synced_at : null,
			'client_id'     => $cl ? (int) $cl->id : null,
			'client_name'   => $cl ? $cl->name : '',
			'connection_id' => $c ? (int) $c->id : null,
			'status'        => $key,
			'status_label'  => $c ? NDWD_WS_Manage::STATUS_LABELS[ $key ] : 'Independent',
			'active'        => ! $c || ! in_array( $c->status, array( 'disconnected' ), true ) && ! $c->archived_at,
			'projects'      => $c ? count( self::projects_of( $c ) ) : 0,
		);
	}

	public static function rest_assignments( WP_REST_Request $r ) {
		global $wpdb;
		$q    = $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'project_packages' ) . " WHERE status = 'active' ORDER BY id DESC LIMIT 500" ); // phpcs:ignore
		$rows = array();
		foreach ( $q as $a ) {
			$p      = self::project_row( (int) $a->project_id );
			$cl     = NDWD_Clients::get( (int) $a->client_id );
			$conn   = NDWD_WS::connection( (int) $a->connection_id );
			$rows[] = array_merge( self::assignment_array( $a ), array( 'project_name' => $p ? $p->name : '', 'client_name' => $cl ? $cl->name : '', 'workspace_name' => $conn ? (string) $conn->workspace_name : '' ) );
		}
		return rest_ensure_response( array( 'items' => $rows ) );
	}

	public static function rest_link( WP_REST_Request $r ) {
		$pid  = (int) $r['id'];
		$to   = absint( self::body( $r )['connection_id'] ?? 0 );
		$was  = NDWD_WS::explicit_connection_id( $pid );
		$res  = self::link_project( $pid, $to, get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $was !== $to ) {
			self::tell_project_added( NDWD_WS::connection( $to ), $pid, get_current_user_id() );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Tells the client a project now appears in one of their Workspaces: an in-app notification that
	 * opens it (through the webhook) and an email. Only for a Workspace the client already uses.
	 */
	public static function tell_project_added( $conn, $project_id, $user_id ) {
		$p = self::project_row( (int) $project_id );
		if ( ! $conn || ! $p || ! $conn->linked_at || 'active' !== $conn->status ) {
			return;
		}
		$title = 'A new project, ' . $p->name . ', has been added to your Neon Workspace.';
		NDWD_WS_Webhooks::emit( 'project.assigned', $conn->id, array( 'project_id' => (int) $p->id, 'title' => $title ) );
		$email = NDWD_WS::account_email( (int) $conn->client_id );
		if ( is_email( $email ) ) {
			$html = NDWD_Notifications::template( $p->name . ' has been added to your Neon Workspace', '<p>' . esc_html( $title ) . '</p><p>Open <b>' . esc_html( (string) $conn->workspace_name ) . '</b> from the Workspace switcher to see it.</p>', NDWD_WS::workspace_url() . '/app/', 'Open Neon Workspace' );
			NDWD_Notifications::send_to( $email, $p->name . ' has been added to your Neon Workspace', $html, 'workspace_project_added', 'wsproj:' . $conn->id . ':' . $p->id );
		}
		NDWD_WS::audit( 'project_added_notified', array( 'connection_id' => $conn->id, 'entity' => 'project', 'entity_id' => (int) $p->id ), $user_id );
	}

	public static function rest_assign( WP_REST_Request $r ) {
		return self::out( self::assign( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_history( WP_REST_Request $r ) {
		global $wpdb;
		$id = (int) $r['id'];
		return rest_ensure_response( array(
			'assignments' => array_map( array( __CLASS__, 'assignment_array' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'project_packages' ) . ' WHERE project_id = %d ORDER BY id DESC', $id ) ) ), // phpcs:ignore
			'migrations'  => array_map( array( __CLASS__, 'migration_array' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'migrations' ) . ' WHERE project_id = %d ORDER BY id DESC', $id ) ) ), // phpcs:ignore
		) );
	}

	public static function rest_assignment_update( WP_REST_Request $r ) {
		return self::out( self::update_assignment( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_assignment_remove( WP_REST_Request $r ) {
		return self::out( self::remove_assignment( (int) $r['id'], get_current_user_id() ) );
	}

	public static function rest_migrate( WP_REST_Request $r ) {
		return self::out( self::migrate( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_migrations() {
		global $wpdb;
		return rest_ensure_response( array( 'items' => array_map( array( __CLASS__, 'migration_array' ), $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'migrations' ) . ' ORDER BY id DESC LIMIT 300' ) ) ) ); // phpcs:ignore
	}

	public static function rest_migration_update( WP_REST_Request $r ) {
		return self::out( self::update_migration( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_migration_reverse( WP_REST_Request $r ) {
		return self::out( self::reverse_migration( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	/**
	 * Reactivates a disconnected link without a new invitation. New credentials reach Workspace over
	 * the site-signed call; if it cannot be reached, nothing changes on either side.
	 */
	public static function reconnect( $id, $user_id ) {
		global $wpdb;
		$c = NDWD_WS::connection( $id );
		if ( ! $c ) {
			return NDWD_WS::err( 'Connection not found.', 404, 'ndwd_not_found' );
		}
		if ( 'disconnected' !== $c->status ) {
			return NDWD_WS::err( 'This Workspace is not disconnected.', 409, 'ndwd_conflict' );
		}
		if ( ! $c->workspace_uid ) {
			return NDWD_WS::err( 'This client never finished linking. Send an invitation instead.', 409, 'ndwd_conflict' );
		}
		$key    = 'wdc_' . bin2hex( random_bytes( 12 ) );
		$secret = bin2hex( random_bytes( 32 ) );
		$res    = self::ws_call( 'POST', '/connect/reactivate', array( 'workspace_uid' => $c->workspace_uid, 'api_key' => $key, 'api_secret' => $secret ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->update( NDWD_WS::t( 'connections' ), array( 'status' => 'active', 'api_key' => $key, 'api_secret' => NDWD_WS::seal( $secret ), 'disconnected_at' => null, 'archived_at' => null, 'updated_at' => $now ), array( 'id' => $c->id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'invitations' ) . ' SET revoked_at = %s WHERE connection_id = %d AND accepted_at IS NULL AND revoked_at IS NULL', $now, $c->id ) ); // phpcs:ignore
		NDWD_WS::audit( 'workspace_reconnected', array( 'connection_id' => $c->id, 'details' => array( 'workspace_uid' => $c->workspace_uid ) ), $user_id );
		NDWD_WS_Webhooks::emit( 'entitlements.updated', $c->id, array() );
		return array( 'ok' => true );
	}

	public static function rest_reconnect( WP_REST_Request $r ) {
		return self::out( self::reconnect( (int) $r['id'], get_current_user_id() ) );
	}

	/** Workspace Subscriptions: everything known about each, with one row per Workspace. */
	public static function rest_subscriptions() {
		global $wpdb;
		$map   = self::client_hash_map();
		$items = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'directory' ) . ' ORDER BY name ASC LIMIT 1000' ) as $d ) { // phpcs:ignore
			$c   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'connections' ) . ' WHERE workspace_uid = %s', $d->workspace_uid ) ); // phpcs:ignore
			$cid = $c ? (int) $c->client_id : ( $map[ $d->owner_hash ] ?? 0 );
			$cl  = $cid ? NDWD_Clients::get( $cid ) : null;
			$cat = '' !== (string) $d->plan_code ? $wpdb->get_row( $wpdb->prepare( 'SELECT name FROM ' . NDWD_WS::t( 'catalogue' ) . " WHERE kind = 'plan' AND code = %s", $d->plan_code ) ) : null; // phpcs:ignore
			$end = $d->extended_until ? $d->extended_until : $d->period_end;
			$items[] = array(
				'kind'          => 'workspace',
				'workspace_uid' => $d->workspace_uid,
				'workspace_name' => $d->name,
				'account_type'  => $d->account_type,
				'client_id'     => $cl ? (int) $cl->id : null,
				'client_name'   => $cl ? $cl->name : '',
				'business_name' => $cl && '' !== (string) $cl->company ? $cl->company : ( $cl ? $cl->name : $d->name ),
				'email'         => $cl ? (string) $cl->email : '',
				'phone'         => $cl ? (string) $cl->phone : '',
				'plan_code'     => $d->plan_code,
				'plan_name'     => $cat ? $cat->name : $d->plan_code,
				'status'        => $d->subscription_status,
				'billing_cycle' => $d->billing_cycle,
				'period_start'  => $d->period_start,
				'period_end'    => $d->period_end,
				'extended_until' => $d->extended_until,
				'grace_days'    => null === $d->grace_days ? null : (int) $d->grace_days,
				'renews_on'     => $end,
				'state'         => $end ? NDWD_WS::lifecycle( null, $end, (int) $d->grace_days ) : '',
				'projects'      => $c ? array_map( function ( $p ) use ( $c ) {
					$t = self::project_tree( $c, $p );
					return array( 'id' => $t['id'], 'name' => $t['name'], 'state' => $t['state'], 'package' => $t['package'] ? $t['package']['name'] : '' );
				}, self::projects_of( $c ) ) : array(),
				'manageable'    => 'agency' !== $d->account_type,
				'synced_at'     => $d->synced_at,
			);
		}
		return rest_ensure_response( array( 'items' => $items ) );
	}

	/** Staff changing plan or dates. Workspace holds the subscription, so the change happens there. */
	public static function rest_subscription_save( WP_REST_Request $r ) {
		global $wpdb;
		$uid = (string) $r['uid'];
		$d   = self::directory_row( $uid );
		if ( ! $d ) {
			return NDWD_WS::err( 'Workspace not found.', 404, 'ndwd_not_found' );
		}
		$b   = self::body( $r );
		$res = self::ws_call( 'POST', '/connect/subscription', array_merge( array_intersect_key( $b, array_flip( array( 'plan_code', 'billing_cycle', 'period_end', 'extended_until', 'grace_days', 'status' ) ) ), array( 'workspace_uid' => $uid, 'actor' => NDWD_Helpers::user_name( get_current_user_id() ) ) ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! empty( $res['directory'] ) ) {
			$x = $res['directory'];
			$wpdb->update( NDWD_WS::t( 'directory' ), array( 'plan_code' => sanitize_key( (string) ( $x['plan_code'] ?? '' ) ), 'subscription_status' => sanitize_key( (string) ( $x['subscription_status'] ?? '' ) ), 'period_start' => NDWD_Helpers::sanitize_date( $x['period_start'] ?? '' ) ?: null, 'period_end' => NDWD_Helpers::sanitize_date( $x['period_end'] ?? '' ) ?: null, 'billing_cycle' => sanitize_key( (string) ( $x['billing_cycle'] ?? '' ) ), 'grace_days' => isset( $x['grace_days'] ) ? absint( $x['grace_days'] ) : null, 'extended_until' => NDWD_Helpers::sanitize_date( $x['extended_until'] ?? '' ) ?: null, 'synced_at' => NDWD_Helpers::now_mysql() ), array( 'workspace_uid' => $uid ) );
		}
		NDWD_WS::audit( 'workspace_subscription_changed', array( 'entity' => 'workspace', 'details' => array( 'workspace_uid' => $uid, 'changes' => array_diff_key( $b, array( 'actor' => 1 ) ) ) ), get_current_user_id() );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** One row per client, each Workspace with its projects and features. Editing stays on one screen. */
	public static function rest_entitlements() {
		global $wpdb;
		$by = array();
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . NDWD_WS::t( 'connections' ) . " WHERE status <> 'disconnected' ORDER BY id ASC" ) as $c ) { // phpcs:ignore
			$cl = NDWD_Clients::get( (int) $c->client_id );
			if ( ! $cl ) {
				continue;
			}
			if ( ! isset( $by[ $cl->id ] ) ) {
				$by[ $cl->id ] = array( 'client_id' => (int) $cl->id, 'client_name' => $cl->name, 'company' => (string) $cl->company, 'workspaces' => array() );
			}
			$feats = array_values( array_filter( NDWD_WS::entitlements( $c ), function ( $e ) {
				return $e['enabled'];
			} ) );
			$by[ $cl->id ]['workspaces'][] = array(
				'connection_id'  => (int) $c->id,
				'workspace_name' => '' !== (string) $c->workspace_name ? $c->workspace_name : 'Not linked yet',
				'status'         => $c->status,
				'grace_days'     => (int) $c->grace_days,
				'features'       => $feats,
				'projects'       => array_map( function ( $p ) use ( $c ) {
					$t = self::project_tree( $c, $p );
					return array( 'id' => $t['id'], 'name' => $t['name'], 'state' => $t['state'], 'package' => $t['package'], 'addons' => $t['addons'] );
				}, self::projects_of( $c ) ),
			);
		}
		$items = array_values( $by );
		usort( $items, function ( $a, $b ) {
			return strcasecmp( $a['client_name'], $b['client_name'] );
		} );
		return rest_ensure_response( array( 'items' => $items ) );
	}
}
