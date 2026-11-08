<?php
/**
 * Website enquiries and client onboarding.
 *
 * neondigital.in posts each enquiry to /wp-json/workdesk/v1/enquiries, signed with a shared secret.
 * Staff work a shared queue: New → Picked Up → Contacted → Follow-up → Qualified → Won or Lost.
 * Once won, a secure, expiring onboarding link creates or updates exactly one client record.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Enquiries {

	const STATUSES = array( 'new' => 'New', 'picked_up' => 'Picked Up', 'contacted' => 'Contacted', 'follow_up' => 'Follow-up', 'qualified' => 'Qualified', 'won' => 'Won/Converted', 'lost' => 'Lost/Closed' );
	const SKEW     = 300;
	const INVITE_DAYS = 14;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'init', array( __CLASS__, 'maybe_public_form' ), 5 );
	}

	public static function t( $n ) {
		return NDWD_Helpers::table( $n );
	}

	private static function route( $path, $methods, $cb, $cap = 'ndwd_handle_enquiries' ) {
		register_rest_route( NDWD_REST::NS, $path, array(
			'methods'             => $methods,
			'callback'            => array( __CLASS__, $cb ),
			'permission_callback' => function () use ( $cap ) {
				return is_user_logged_in() && NDWD_Permissions::can( $cap );
			},
		) );
	}

	public static function routes() {
		register_rest_route( 'workdesk/v1', '/enquiries', array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'intake' ), 'permission_callback' => '__return_true' ) );
		self::route( '/enquiries', 'GET', 'index' );
		self::route( '/enquiries/settings', 'GET', 'get_settings', 'ndwd_manage_enquiries' );
		self::route( '/enquiries/settings', 'POST', 'save_settings', 'ndwd_manage_enquiries' );
		self::route( '/enquiries/settings/rotate', 'POST', 'rotate_key', 'ndwd_manage_enquiries' );
		self::route( '/enquiries/(?P<id>\d+)', 'GET', 'show' );
		self::route( '/enquiries/(?P<id>\d+)', 'PUT,PATCH', 'update' );
		self::route( '/enquiries/(?P<id>\d+)/pickup', 'POST', 'pickup' );
		self::route( '/enquiries/(?P<id>\d+)/remarks', 'POST', 'remark' );
		self::route( '/enquiries/(?P<id>\d+)/contact', 'POST', 'contact_attempt' );
		self::route( '/enquiries/(?P<id>\d+)/onboarding', 'POST', 'send_onboarding' );
		self::route( '/enquiries/(?P<id>\d+)/onboarding/file', 'GET', 'onboarding_file' );
	}

	private static function err( $msg, $status = 400, $code = 'ndwd_invalid' ) {
		return new WP_Error( $code, $msg, array( 'status' => $status ) );
	}

	/* ---------------------------- Intake from the website ---------------------------- */

	public static function secret() {
		$s = (string) get_option( 'ndwd_enquiry_secret', '' );
		return '' !== $s ? NDWD_WS::unseal( $s ) : '';
	}

	public static function intake( WP_REST_Request $r ) {
		global $wpdb;
		$body   = (string) $r->get_body();
		$secret = self::secret();
		$ts     = (int) $r->get_header( 'x_nd_timestamp' );
		$sig    = (string) $r->get_header( 'x_nd_signature' );
		if ( '' === $secret || abs( time() - $ts ) > self::SKEW || ! hash_equals( hash_hmac( 'sha256', $ts . '.' . $body, $secret ), $sig ) ) {
			NDWD_WS::audit( 'enquiry_rejected', array( 'kind' => 'api', 'details' => array( 'reason' => 'signature' ) ) );
			return self::err( 'Invalid signature.', 401, 'ndwd_bad_signature' );
		}
		$key = 'ndwd_enq_rate_' . md5( NDWD_WS::ip() );
		$n   = (int) get_transient( $key );
		if ( $n >= 120 ) {
			return self::err( 'Too many requests.', 429, 'ndwd_rate_limited' );
		}
		set_transient( $key, $n + 1, 300 );
		$b    = json_decode( $body, true );
		$b    = is_array( $b ) ? $b : array();
		$name = mb_substr( trim( sanitize_text_field( (string) ( $b['name'] ?? '' ) ) ), 0, 190 );
		$email = sanitize_email( (string) ( $b['email'] ?? '' ) );
		$phone = mb_substr( sanitize_text_field( (string) ( $b['phone'] ?? '' ) ), 0, 40 );
		if ( '' === $name || ( '' === $email && '' === $phone ) ) {
			return self::err( 'A name and an email or phone number are required.', 422 );
		}
		$ref  = mb_substr( sanitize_text_field( (string) ( $b['reference'] ?? '' ) ), 0, 120 );
		$hash = hash( 'sha256', '' !== $ref ? 'ref:' . $ref : $body );
		$dup  = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'enquiries' ) . ' WHERE dedupe_hash = %s', $hash ) ); // phpcs:ignore
		if ( $dup ) {
			return rest_ensure_response( array( 'ok' => true, 'id' => (int) $dup, 'duplicate' => true ) );
		}
		$now = NDWD_Helpers::now_mysql();
		$ok  = $wpdb->insert( self::t( 'enquiries' ), array(
			'name' => $name, 'company' => mb_substr( sanitize_text_field( (string) ( $b['company'] ?? '' ) ), 0, 190 ), 'email' => $email, 'phone' => $phone,
			'service' => mb_substr( sanitize_text_field( (string) ( $b['service'] ?? '' ) ), 0, 190 ), 'message' => sanitize_textarea_field( (string) ( $b['message'] ?? '' ) ), 'source' => mb_substr( sanitize_text_field( (string) ( $b['source'] ?? 'website' ) ), 0, 60 ), 'source_page' => esc_url_raw( (string) ( $b['page'] ?? '' ), array( 'http', 'https' ) ),
			'status' => 'new', 'dedupe_hash' => $hash, 'created_at' => $now, 'updated_at' => $now,
		) );
		if ( ! $ok ) {
			return self::err( 'Could not save the enquiry.', 500, 'ndwd_error' );
		}
		$id = (int) $wpdb->insert_id;
		self::event( $id, 'received', 0, '', 'new', 'From ' . ( $b['source'] ?? 'website' ) );
		return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
	}

	/* ---------------------------- Staff ---------------------------- */

	private static function event( $id, $type, $user_id, $from = '', $to = '', $note = '' ) {
		global $wpdb;
		$wpdb->insert( self::t( 'enquiry_events' ), array( 'enquiry_id' => (int) $id, 'type' => $type, 'user_id' => (int) $user_id, 'from_val' => mb_substr( (string) $from, 0, 190 ), 'to_val' => mb_substr( (string) $to, 0, 190 ), 'note' => $note, 'created_at' => NDWD_Helpers::now_mysql() ) );
	}

	private static function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'enquiries' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	private static function is_manager( $uid = 0 ) {
		return NDWD_Permissions::can( 'ndwd_manage_enquiries', $uid ? $uid : get_current_user_id() );
	}

	/** Only the owner or a manager changes an enquiry that is picked up. */
	private static function can_act( $e ) {
		$uid = get_current_user_id();
		return self::is_manager( $uid ) || (int) $e->owner_id === $uid;
	}

	private static function out( $e ) {
		$o = array(
			'id' => (int) $e->id, 'name' => $e->name, 'company' => $e->company, 'email' => $e->email, 'phone' => $e->phone, 'service' => $e->service, 'message' => (string) $e->message, 'source' => $e->source, 'source_page' => $e->source_page,
			'status' => $e->status, 'status_label' => self::STATUSES[ $e->status ] ?? $e->status, 'owner_id' => $e->owner_id ? (int) $e->owner_id : null, 'owner_name' => $e->owner_id ? NDWD_Helpers::user_name( (int) $e->owner_id ) : '', 'follow_up_on' => $e->follow_up_on, 'client_id' => $e->client_id ? (int) $e->client_id : null, 'created_at' => $e->created_at, 'updated_at' => $e->updated_at,
		);
		return $o;
	}

	public static function index( WP_REST_Request $r ) {
		global $wpdb;
		$t    = self::t( 'enquiries' );
		$vals = array();
		$sql  = '1=1';
		if ( isset( self::STATUSES[ (string) $r['status'] ] ) ) {
			$sql   .= ' AND status = %s';
			$vals[] = $r['status'];
		} elseif ( 'open' === $r['status'] ) {
			$sql .= " AND status NOT IN ('won','lost')";
		}
		if ( 'mine' === $r['owner'] ) {
			$sql   .= ' AND owner_id = %d';
			$vals[] = get_current_user_id();
		} elseif ( 'unassigned' === $r['owner'] ) {
			$sql .= ' AND owner_id IS NULL';
		}
		if ( $r['q'] ) {
			$like = '%' . $wpdb->esc_like( sanitize_text_field( (string) $r['q'] ) ) . '%';
			$sql .= ' AND (name LIKE %s OR company LIKE %s OR email LIKE %s OR phone LIKE %s)';
			$vals = array_merge( $vals, array( $like, $like, $like, $like ) );
		}
		$page = max( 1, absint( $r['page'] ) );
		$cnt  = $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $sql", $vals ) : "SELECT COUNT(*) FROM $t WHERE $sql"; // phpcs:ignore
		$tot  = (int) $wpdb->get_var( $cnt ); // phpcs:ignore
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $sql ORDER BY id DESC LIMIT 20 OFFSET %d", array_merge( $vals, array( ( $page - 1 ) * 20 ) ) ) ); // phpcs:ignore
		return rest_ensure_response( array( 'items' => array_map( array( __CLASS__, 'out' ), $rows ), 'page' => $page, 'pages' => (int) ceil( $tot / 20 ), 'total' => $tot, 'statuses' => self::STATUSES, 'is_manager' => self::is_manager() ) );
	}

	public static function show( WP_REST_Request $r ) {
		global $wpdb;
		$e = self::row( (int) $r['id'] );
		if ( ! $e ) {
			return self::err( 'Enquiry not found.', 404, 'ndwd_not_found' );
		}
		$ev = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'enquiry_events' ) . ' WHERE enquiry_id = %d ORDER BY id DESC', $e->id ) ); // phpcs:ignore
		$out = self::out( $e );
		$out['history'] = array_map( function ( $x ) {
			return array( 'type' => $x->type, 'who' => $x->user_id ? NDWD_Helpers::user_name( (int) $x->user_id ) : 'System', 'from' => $x->from_val, 'to' => $x->to_val, 'note' => (string) $x->note, 'at' => $x->created_at );
		}, $ev );
		$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT id, email, expires_at, submitted_at, client_id, answers FROM ' . self::t( 'onboarding_invites' ) . ' WHERE enquiry_id = %d ORDER BY id DESC LIMIT 1', $e->id ) ); // phpcs:ignore
		$out['onboarding'] = $inv ? array( 'email' => $inv->email, 'expires_at' => $inv->expires_at, 'submitted_at' => $inv->submitted_at, 'client_id' => $inv->client_id ? (int) $inv->client_id : null, 'answers' => json_decode( (string) $inv->answers, true ) ) : null;
		$out['can_act'] = self::can_act( $e );
		return rest_ensure_response( $out );
	}

	/** First person wins. The conditional update means two people cannot both pick it up. */
	public static function pickup( WP_REST_Request $r ) {
		global $wpdb;
		$uid = get_current_user_id();
		$n   = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'enquiries' ) . " SET owner_id = %d, status = CASE WHEN status = 'new' THEN 'picked_up' ELSE status END, updated_at = %s WHERE id = %d AND owner_id IS NULL", $uid, NDWD_Helpers::now_mysql(), (int) $r['id'] ) ); // phpcs:ignore
		if ( ! $n ) {
			return self::err( self::row( (int) $r['id'] ) ? 'Someone else has already picked this up.' : 'Enquiry not found.', self::row( (int) $r['id'] ) ? 409 : 404, 'ndwd_conflict' );
		}
		self::event( (int) $r['id'], 'pickup', $uid, '', NDWD_Helpers::user_name( $uid ) );
		return self::show( $r );
	}

	public static function update( WP_REST_Request $r ) {
		global $wpdb;
		$e = self::row( (int) $r['id'] );
		if ( ! $e ) {
			return self::err( 'Enquiry not found.', 404, 'ndwd_not_found' );
		}
		if ( ! self::can_act( $e ) ) {
			return self::err( 'Pick up this enquiry first, or ask its owner.', 403, 'ndwd_forbidden' );
		}
		$b   = json_decode( (string) $r->get_body(), true );
		$b   = is_array( $b ) ? $b : array();
		$uid = get_current_user_id();
		$row = array( 'updated_at' => NDWD_Helpers::now_mysql() );
		if ( isset( $b['status'] ) && $b['status'] !== $e->status ) {
			if ( ! isset( self::STATUSES[ $b['status'] ] ) ) {
				return self::err( 'Unknown status.' );
			}
			$row['status'] = $b['status'];
			self::event( $e->id, 'status', $uid, self::STATUSES[ $e->status ], self::STATUSES[ $b['status'] ], sanitize_textarea_field( (string) ( $b['note'] ?? '' ) ) );
		}
		if ( array_key_exists( 'follow_up_on', $b ) && (string) $b['follow_up_on'] !== (string) $e->follow_up_on ) {
			$d = (string) $b['follow_up_on'];
			if ( '' !== $d && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ) {
				return self::err( 'Choose a valid date.' );
			}
			$row['follow_up_on'] = '' === $d ? null : $d;
			self::event( $e->id, 'follow_up', $uid, (string) $e->follow_up_on, $d );
		}
		if ( array_key_exists( 'owner_id', $b ) && (int) $b['owner_id'] !== (int) $e->owner_id ) {
			$to = absint( $b['owner_id'] );
			if ( $to && ( ! get_userdata( $to ) || ! NDWD_Permissions::can( 'ndwd_handle_enquiries', $to ) ) ) {
				return self::err( 'That person cannot handle enquiries.' );
			}
			$row['owner_id'] = $to ? $to : null;
			self::event( $e->id, 'assign', $uid, $e->owner_id ? NDWD_Helpers::user_name( (int) $e->owner_id ) : 'Nobody', $to ? NDWD_Helpers::user_name( $to ) : 'Nobody' );
		}
		$wpdb->update( self::t( 'enquiries' ), $row, array( 'id' => $e->id ) );
		return self::show( $r );
	}

	/** Internal only. Nothing here is ever sent to a client or to Workspace. */
	public static function remark( WP_REST_Request $r ) {
		$e = self::row( (int) $r['id'] );
		if ( ! $e ) {
			return self::err( 'Enquiry not found.', 404, 'ndwd_not_found' );
		}
		$b    = json_decode( (string) $r->get_body(), true );
		$text = sanitize_textarea_field( (string) ( is_array( $b ) ? ( $b['note'] ?? '' ) : '' ) );
		if ( '' === trim( $text ) ) {
			return self::err( 'Write a remark first.' );
		}
		self::event( $e->id, 'remark', get_current_user_id(), '', '', $text );
		return self::show( $r );
	}

	public static function contact_attempt( WP_REST_Request $r ) {
		global $wpdb;
		$e = self::row( (int) $r['id'] );
		if ( ! $e ) {
			return self::err( 'Enquiry not found.', 404, 'ndwd_not_found' );
		}
		if ( ! self::can_act( $e ) ) {
			return self::err( 'Pick up this enquiry first, or ask its owner.', 403, 'ndwd_forbidden' );
		}
		$b  = json_decode( (string) $r->get_body(), true );
		$b  = is_array( $b ) ? $b : array();
		$ch = in_array( $b['channel'] ?? '', array( 'call', 'email', 'whatsapp', 'meeting', 'other' ), true ) ? $b['channel'] : 'call';
		self::event( $e->id, 'contact', get_current_user_id(), '', $ch, sanitize_textarea_field( (string) ( $b['note'] ?? '' ) ) );
		if ( in_array( $e->status, array( 'new', 'picked_up' ), true ) ) {
			$wpdb->update( self::t( 'enquiries' ), array( 'status' => 'contacted', 'updated_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $e->id ) );
			self::event( $e->id, 'status', get_current_user_id(), self::STATUSES[ $e->status ], self::STATUSES['contacted'] );
		}
		return self::show( $r );
	}

	/* ---------------------------- Settings ---------------------------- */

	public static function default_fields() {
		return array(
			array( 'key' => 'business_name', 'label' => 'Business name', 'type' => 'text', 'required' => true, 'on' => true ),
			array( 'key' => 'contact_person', 'label' => 'Contact person', 'type' => 'text', 'required' => true, 'on' => true ),
			array( 'key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'on' => true ),
			array( 'key' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'required' => true, 'on' => true ),
			array( 'key' => 'address', 'label' => 'Address', 'type' => 'textarea', 'required' => false, 'on' => true ),
			array( 'key' => 'industry', 'label' => 'Industry', 'type' => 'text', 'required' => false, 'on' => true ),
			array( 'key' => 'services', 'label' => 'Required services', 'type' => 'textarea', 'required' => false, 'on' => true ),
			array( 'key' => 'project_details', 'label' => 'Project details', 'type' => 'textarea', 'required' => false, 'on' => true ),
			array( 'key' => 'billing_name', 'label' => 'Billing name', 'type' => 'text', 'required' => false, 'on' => true ),
			array( 'key' => 'gstin', 'label' => 'GSTIN, if applicable', 'type' => 'text', 'required' => false, 'on' => true ),
			array( 'key' => 'documents', 'label' => 'Documents', 'type' => 'file', 'required' => false, 'on' => true ),
		);
	}

	public static function fields() {
		$saved = get_option( 'ndwd_onboarding_fields' );
		return is_array( $saved ) && $saved ? $saved : self::default_fields();
	}

	public static function get_settings() {
		return rest_ensure_response( array( 'has_key' => '' !== self::secret(), 'endpoint' => rest_url( 'workdesk/v1/enquiries' ), 'fields' => self::fields() ) );
	}

	public static function save_settings( WP_REST_Request $r ) {
		$b   = json_decode( (string) $r->get_body(), true );
		$out = array();
		$core = array( 'business_name', 'contact_person', 'email', 'phone' ); // The client record needs these.
		foreach ( (array) ( is_array( $b ) ? ( $b['fields'] ?? array() ) : array() ) as $f ) {
			$key = sanitize_key( (string) ( $f['key'] ?? '' ) );
			$lab = mb_substr( trim( sanitize_text_field( (string) ( $f['label'] ?? '' ) ) ), 0, 190 );
			if ( '' === $key || '' === $lab ) {
				continue;
			}
			$out[] = array( 'key' => $key, 'label' => $lab, 'type' => in_array( $f['type'] ?? '', array( 'text', 'email', 'tel', 'textarea', 'file' ), true ) ? $f['type'] : 'text', 'required' => in_array( $key, $core, true ) || ! empty( $f['required'] ), 'on' => in_array( $key, $core, true ) || ! empty( $f['on'] ) );
		}
		foreach ( $core as $k ) {
			if ( ! in_array( $k, array_column( $out, 'key' ), true ) ) {
				return self::err( 'The form must keep business name, contact person, email and phone.' );
			}
		}
		update_option( 'ndwd_onboarding_fields', $out, false );
		return self::get_settings();
	}

	/** The secret is shown once, here, and stored sealed. */
	public static function rotate_key() {
		$secret = bin2hex( random_bytes( 32 ) );
		update_option( 'ndwd_enquiry_secret', NDWD_WS::seal( $secret ), false );
		NDWD_WS::audit( 'enquiry_key_rotated', array(), get_current_user_id() );
		return rest_ensure_response( array( 'secret' => $secret ) );
	}

	/* ---------------------------- Onboarding ---------------------------- */

	public static function send_onboarding( WP_REST_Request $r ) {
		global $wpdb;
		$e = self::row( (int) $r['id'] );
		if ( ! $e ) {
			return self::err( 'Enquiry not found.', 404, 'ndwd_not_found' );
		}
		if ( ! self::can_act( $e ) ) {
			return self::err( 'Only the owner can send this.', 403, 'ndwd_forbidden' );
		}
		if ( 'won' !== $e->status ) {
			return self::err( 'Mark the enquiry as Won first.', 409, 'ndwd_conflict' );
		}
		$b     = json_decode( (string) $r->get_body(), true );
		$email = sanitize_email( (string) ( is_array( $b ) ? ( $b['email'] ?? $e->email ) : $e->email ) );
		if ( ! is_email( $email ) ) {
			return self::err( 'Enter a valid email address.' );
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'onboarding_invites' ) . ' SET expires_at = %s WHERE enquiry_id = %d AND submitted_at IS NULL', $now, $e->id ) ); // phpcs:ignore
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore
		$wpdb->insert( self::t( 'onboarding_invites' ), array( 'enquiry_id' => (int) $e->id, 'email' => $email, 'token_hash' => hash( 'sha256', $token ), 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::INVITE_DAYS * DAY_IN_SECONDS ), 'created_by' => get_current_user_id(), 'created_at' => $now ) );
		$url  = add_query_arg( 'ndwd_onboard', $token, home_url( '/' ) );
		$sent = false;
		if ( class_exists( 'NDWD_Notifications' ) && method_exists( 'NDWD_Notifications', 'send_to' ) ) {
			$sent = (bool) NDWD_Notifications::send_to( array( $email ), 'Your NeonDigital onboarding form', '<p>Hello ' . esc_html( $e->name ) . ',</p><p>Please complete your onboarding form:</p><p><a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p><p>This link works for ' . self::INVITE_DAYS . ' days.</p>', 'onboarding' );
		}
		self::event( $e->id, 'onboarding', get_current_user_id(), '', $email, $sent ? 'Form sent' : 'Link created. Email was not sent.' );
		return rest_ensure_response( array( 'url' => $url, 'emailed' => $sent, 'expires_days' => self::INVITE_DAYS ) );
	}

	private static function live_invite( $token ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'onboarding_invites' ) . ' WHERE token_hash = %s AND submitted_at IS NULL AND expires_at > %s', hash( 'sha256', (string) $token ), NDWD_Helpers::now_mysql() ) ); // phpcs:ignore
	}

	public static function maybe_public_form() {
		if ( empty( $_GET['ndwd_onboard'] ) ) { // phpcs:ignore
			return;
		}
		nocache_headers();
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
		$token = sanitize_text_field( wp_unslash( $_GET['ndwd_onboard'] ) ); // phpcs:ignore
		$inv   = self::live_invite( $token );
		$msg   = '';
		$done  = false;
		$vals  = array();
		if ( $inv && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$key = 'ndwd_onb_' . md5( NDWD_WS::ip() );
			$n   = (int) get_transient( $key );
			set_transient( $key, $n + 1, 900 );
			$vals = wp_unslash( $_POST ); // phpcs:ignore
			$res  = $n >= 20 ? new WP_Error( 'rate', 'Too many attempts. Please try again later.' ) : self::submit( $inv, $vals, $_FILES ); // phpcs:ignore
			if ( is_wp_error( $res ) ) {
				$msg = $res->get_error_message();
			} else {
				$done = true;
			}
		}
		$e = function ( $v ) {
			return esc_html( (string) $v );
		};
		status_header( $inv || $done ? 200 : 404 );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Client onboarding</title><style>body{margin:0;background:#f4f5f7;color:#111;font:16px/1.5 system-ui,sans-serif}main{max-width:560px;margin:0 auto;padding:20px 16px}.c{background:#fff;border:1px solid #e3e5e8;border-radius:10px;padding:18px}label{display:block;margin-bottom:12px;font-size:14px;font-weight:600}input,textarea{display:block;width:100%;box-sizing:border-box;font:16px system-ui;margin-top:4px;padding:10px;border:1px solid #c9ccd1;border-radius:7px;font-weight:400}button{width:100%;min-height:46px;font:600 15px system-ui;border:0;border-radius:7px;background:#111;color:#fff;cursor:pointer}.e{background:#fbe6e4;color:#b3261e;padding:10px;border-radius:7px;margin-bottom:12px}</style></head><body><main><h1>Client onboarding</h1><div class="c">';
		if ( $done ) {
			echo '<p>Thank you. We have received your details and will be in touch.</p>';
		} elseif ( ! $inv ) {
			echo '<p>This link has expired or has already been used. Please ask NeonDigital for a new one.</p>';
		} else {
			if ( $msg ) {
				echo '<p class="e" role="alert">' . $e( $msg ) . '</p>'; // phpcs:ignore
			}
			echo '<form method="post" enctype="multipart/form-data">';
			foreach ( self::fields() as $f ) {
				if ( empty( $f['on'] ) ) {
					continue;
				}
				$name = esc_attr( $f['key'] );
				$req  = ! empty( $f['required'] ) ? ' required' : '';
				$val  = isset( $vals[ $f['key'] ] ) && is_string( $vals[ $f['key'] ] ) ? $vals[ $f['key'] ] : ( 'email' === $f['key'] ? $inv->email : '' );
				echo '<label>' . $e( $f['label'] ) . ( $req ? ' *' : '' );
				if ( 'textarea' === $f['type'] ) {
					echo '<textarea name="' . $name . '" rows="3"' . $req . '>' . esc_textarea( $val ) . '</textarea>'; // phpcs:ignore
				} elseif ( 'file' === $f['type'] ) {
					echo '<input type="file" name="' . $name . '"' . $req . '>'; // phpcs:ignore
				} else {
					echo '<input type="' . esc_attr( $f['type'] ) . '" name="' . $name . '" value="' . esc_attr( $val ) . '"' . $req . '>'; // phpcs:ignore
				}
				echo '</label>';
			}
			echo '<button type="submit">Submit</button></form>';
		}
		echo '</div></main></body></html>';
		exit;
	}

	/** Validates, then creates or updates one client and records it all. @return int|WP_Error client id */
	public static function submit( $inv, $in, $files = array() ) {
		global $wpdb;
		$answers = array();
		foreach ( self::fields() as $f ) {
			if ( empty( $f['on'] ) ) {
				continue;
			}
			$k = $f['key'];
			if ( 'file' === $f['type'] ) {
				if ( ! empty( $files[ $k ]['tmp_name'] ) && UPLOAD_ERR_OK === (int) $files[ $k ]['error'] ) {
					$name = sanitize_file_name( (string) $files[ $k ]['name'] );
					$type = NDWD_Files::type_of( $name );
					if ( ! $type || (int) $files[ $k ]['size'] > 20 * MB_IN_BYTES ) {
						return new WP_Error( 'file', 'That file type or size is not allowed.' );
					}
					$real = strtolower( (string) ( new finfo( FILEINFO_MIME_TYPE ) )->file( $files[ $k ]['tmp_name'] ) );
					if ( false !== strpos( $real, 'php' ) || false !== strpos( $real, 'html' ) ) {
						return new WP_Error( 'file', 'That file type or size is not allowed.' );
					}
					$dir  = NDWD_Files::dir( 'onboarding' );
					$safe = wp_generate_password( 24, false ) . '-' . $name;
					if ( ! @move_uploaded_file( $files[ $k ]['tmp_name'], $dir . '/' . $safe ) && ! @copy( $files[ $k ]['tmp_name'], $dir . '/' . $safe ) ) { // phpcs:ignore
						return new WP_Error( 'file', 'The file could not be saved.' );
					}
					$answers[ $k ] = array( 'file' => $safe, 'name' => $name );
				} elseif ( ! empty( $f['required'] ) ) {
					return new WP_Error( 'field', 'Please attach ' . $f['label'] . '.' );
				}
				continue;
			}
			$v = is_scalar( $in[ $k ] ?? '' ) ? trim( (string) ( $in[ $k ] ?? '' ) ) : '';
			$v = 'textarea' === $f['type'] ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
			if ( ! empty( $f['required'] ) && '' === $v ) {
				return new WP_Error( 'field', 'Please fill in ' . $f['label'] . '.' );
			}
			if ( 'email' === $f['type'] && '' !== $v && ! is_email( $v ) ) {
				return new WP_Error( 'field', $f['label'] . ' needs a valid email address.' );
			}
			$answers[ $k ] = $v;
		}
		$email = sanitize_email( (string) ( $answers['email'] ?? '' ) );
		$phone = (string) ( $answers['phone'] ?? '' );
		$digits = preg_replace( '/\D/', '', $phone );
		$c_t   = self::t( 'clients' );
		// Same client twice means one record: match on email, then on the phone number's digits.
		$cid = $email ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $c_t WHERE email = %s ORDER BY id ASC LIMIT 1", $email ) ) : 0; // phpcs:ignore
		if ( ! $cid && strlen( $digits ) >= 7 ) {
			foreach ( $wpdb->get_results( "SELECT id, phone FROM $c_t WHERE phone <> ''" ) as $row ) { // phpcs:ignore
				if ( preg_replace( '/\D/', '', $row->phone ) === $digits ) {
					$cid = (int) $row->id;
					break;
				}
			}
		}
		$bname = (string) ( $answers['business_name'] ?? '' );
		if ( ! $cid && '' !== $bname ) {
			$cid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $c_t WHERE LOWER(name) = LOWER(%s) ORDER BY id ASC LIMIT 1", $bname ) ); // phpcs:ignore
		}
		$gstin = strtoupper( preg_replace( '/\s+/', '', (string) ( $answers['gstin'] ?? '' ) ) );
		if ( '' !== $gstin && ! preg_match( '/^[0-9]{2}[A-Z0-9]{13}$/', $gstin ) ) {
			return new WP_Error( 'field', 'GSTIN should be 15 characters: a 2-digit state code and 13 letters or digits.' );
		}
		$uid  = (int) $inv->created_by;
		$data = array( 'name' => $bname, 'company' => $bname, 'contact_person' => (string) ( $answers['contact_person'] ?? '' ), 'phone' => $phone, 'email' => $email );
		if ( $cid ) {
			unset( $data['name'] ); // An existing client keeps its name; empty answers do not erase details.
			$data = array_filter( $data, 'strlen' );
		}
		// The enquiry's owner becomes responsible for a new client and can see it from then on.
		$owner = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT owner_id FROM ' . self::t( 'enquiries' ) . ' WHERE id = %d', $inv->enquiry_id ) ); // phpcs:ignore
		if ( ! $cid && $owner ) {
			$data['member_ids'] = array( $owner );
		}
		$saved = NDWD_Clients::save( $data, $uid, $cid );
		// save() ends by reading the record back as this employee, which can be refused after it saved.
		if ( is_wp_error( $saved ) && in_array( $saved->get_error_code(), array( 'ndwd_invalid', 'ndwd_db' ), true ) ) {
			return $saved;
		}
		if ( $cid && $owner && ! $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM ' . NDWD_Helpers::table( 'client_members' ) . ' WHERE client_id = %d AND user_id = %d', $cid, $owner ) ) ) { // phpcs:ignore
			$wpdb->insert( NDWD_Helpers::table( 'client_members' ), array( 'client_id' => $cid, 'user_id' => $owner ) );
		}
		// save() returns the record as this employee may see it, which can be nothing. Find it directly.
		$client = $cid ? $cid : (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $c_t WHERE LOWER(name) = LOWER(%s) ORDER BY id ASC LIMIT 1", $bname ) ); // phpcs:ignore
		// Billing details come from the client, so they are stored whoever sent the form.
		$billing = array_filter( array( 'billing_name' => mb_substr( (string) ( $answers['billing_name'] ?? '' ), 0, 160 ), 'billing_address' => (string) ( $answers['address'] ?? '' ), 'gstin' => $gstin ), 'strlen' );
		if ( $billing ) {
			$wpdb->update( $c_t, $billing, array( 'id' => $client ) );
			NDWD_Clients::flush();
		}
		$now    = NDWD_Helpers::now_mysql();
		$wpdb->update( self::t( 'onboarding_invites' ), array( 'submitted_at' => $now, 'client_id' => $client, 'answers' => wp_json_encode( $answers ) ), array( 'id' => $inv->id ) );
		$wpdb->update( self::t( 'enquiries' ), array( 'client_id' => $client, 'updated_at' => $now ), array( 'id' => $inv->enquiry_id ) );
		self::event( $inv->enquiry_id, 'onboarding', 0, '', 'Completed', ( $cid ? 'Updated existing client #' : 'Created client #' ) . $client );
		NDWD_WS::audit( 'onboarding_completed', array( 'entity' => 'client', 'entity_id' => $client, 'actor' => 'Client' ) );
		return $client;
	}

	public static function onboarding_file( WP_REST_Request $r ) {
		global $wpdb;
		$inv = $wpdb->get_row( $wpdb->prepare( 'SELECT answers FROM ' . self::t( 'onboarding_invites' ) . ' WHERE enquiry_id = %d AND submitted_at IS NOT NULL ORDER BY id DESC LIMIT 1', (int) $r['id'] ) ); // phpcs:ignore
		$a   = $inv ? json_decode( (string) $inv->answers, true ) : array();
		$k   = sanitize_key( (string) $r['field'] );
		if ( empty( $a[ $k ]['file'] ) ) {
			return self::err( 'File not found.', 404, 'ndwd_not_found' );
		}
		$path = NDWD_Files::dir( 'onboarding' ) . '/' . basename( $a[ $k ]['file'] );
		if ( ! is_file( $path ) ) {
			return self::err( 'File not found.', 404, 'ndwd_not_found' );
		}
		NDWD_Files::send_file( $path, $a[ $k ]['name'] );
		exit;
	}
}
