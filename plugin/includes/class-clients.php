<?php
/**
 * Clients: who the work is for. Projects and tasks link to a client by client_id.
 *
 * Visibility
 * - Admins (View all) and people who manage clients see every client.
 * - Others see a client when it is assigned to them directly, when it is assigned
 *   to one of their teams and their position in that team has "See clients"
 *   (contact details need "See client contact details"), or when it is the client
 *   of a task assigned to them (name only, so their own work makes sense).
 * - Billing details (billing contact, WhatsApp, WhatsApp group, To/CC emails, address,
 *   GSTIN) are only returned to people with the Accounts permission.
 * - Billing emails are a list of {email, type: to|cc}. billing_email always holds the
 *   first "To" address so older code, exports and invoices keep working.
 * Every API response is built through these rules; hidden clients never leave the server.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Clients {

	const BILLING_FIELDS = array( 'billing_name', 'billing_contact', 'billing_whatsapp', 'billing_email', 'billing_wa_group', 'billing_address', 'gstin' );

	const MAX_BILLING_EMAILS = 20;

	/** Decoded billing recipient list of a client row (falls back to the single billing_email). */
	public static function billing_emails( $c ) {
		$list = isset( $c->billing_emails ) ? json_decode( (string) $c->billing_emails, true ) : null;
		if ( ! is_array( $list ) ) {
			$list = '' !== (string) $c->billing_email ? array( array( 'email' => $c->billing_email, 'type' => 'to' ) ) : array();
		}
		return array_values( array_filter( $list, function ( $e ) {
			return is_array( $e ) && ! empty( $e['email'] );
		} ) );
	}

	/**
	 * Validates a recipient list: [{email, type}], type "to" or "cc", no duplicates.
	 *
	 * @return array|WP_Error
	 */
	public static function clean_emails( $list, $label = 'Billing email' ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $list as $i => $e ) {
			$addr = is_array( $e ) ? trim( (string) ( isset( $e['email'] ) ? $e['email'] : '' ) ) : trim( (string) $e );
			if ( '' === $addr ) {
				continue;
			}
			if ( ! is_email( $addr ) ) {
				return new WP_Error( 'ndwd_invalid', $label . ' ' . ( $i + 1 ) . ' (' . sanitize_text_field( $addr ) . ') is not a valid email address.', array( 'status' => 400 ) );
			}
			$key = strtolower( $addr );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = array( 'email' => sanitize_email( $addr ), 'type' => is_array( $e ) && isset( $e['type'] ) && in_array( $e['type'], array( 'cc', 'bcc' ), true ) ? $e['type'] : 'to' );
		}
		if ( count( $out ) > self::MAX_BILLING_EMAILS ) {
			return new WP_Error( 'ndwd_invalid', 'Add at most ' . self::MAX_BILLING_EMAILS . ' billing email addresses.', array( 'status' => 400 ) );
		}
		if ( $out && ! array_filter( $out, function ( $e ) {
			return 'to' === $e['type'];
		} ) ) {
			return new WP_Error( 'ndwd_invalid', 'Mark at least one billing email as "To". CC addresses alone cannot receive the invoice.', array( 'status' => 400 ) );
		}
		return $out;
	}

	/** [to[], cc[]] of a recipient list. */
	public static function split_emails( $list ) {
		$to  = array();
		$cc  = array();
		$bcc = array();
		foreach ( $list as $e ) {
			if ( 'cc' === $e['type'] ) {
				$cc[] = $e['email'];
			} elseif ( 'bcc' === $e['type'] ) {
				$bcc[] = $e['email'];
			} else {
				$to[] = $e['email'];
			}
		}
		return array( $to, $cc, $bcc );
	}

	/** Billing WhatsApp group invite link: https://chat.whatsapp.com/... (or another whatsapp.com link). */
	public static function clean_wa_group( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		$url  = esc_url_raw( $v, array( 'https' ) );
		$host = $url ? strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) : '';
		if ( ! $url || ! preg_match( '/(^|\.)whatsapp\.com$/', $host ) ) {
			return null;
		}
		return mb_substr( $url, 0, 255 );
	}

	private static $levels = array();

	public static function table() {
		return NDWD_Helpers::table( 'clients' );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	public static function for_project( $project_id ) {
		global $wpdb;
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT client_id FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $project_id ) );
		return $id ? (int) $id : null;
	}

	public static function flush() {
		self::$levels = array();
	}

	/** Sees every client with full (non-billing) details. */
	public static function sees_all( $user_id ) {
		return NDWD_Permissions::is_admin( $user_id ) || NDWD_Permissions::can( 'ndwd_manage_clients', $user_id ) || NDWD_Permissions::can( 'ndwd_manage_accounts', $user_id );
	}

	public static function sees_billing( $user_id ) {
		return NDWD_Permissions::can( 'ndwd_manage_accounts', $user_id );
	}

	/**
	 * Access levels for a user: null = every client ('full'); otherwise
	 * [client_id => 'contacts'|'basic'].
	 */
	public static function access_levels( $user_id ) {
		global $wpdb;
		if ( self::sees_all( $user_id ) ) {
			return null;
		}
		if ( isset( self::$levels[ $user_id ] ) ) {
			return self::$levels[ $user_id ];
		}
		$out = array();
		if ( NDWD_Permissions::can( 'ndwd_access', $user_id ) ) {
			$set = function ( $id, $level ) use ( &$out ) {
				$id = (int) $id;
				if ( ! isset( $out[ $id ] ) || 'contacts' === $level ) {
					$out[ $id ] = $level;
				}
			};
			// Clients of tasks assigned to the user: name only.
			$ids = $wpdb->get_col( $wpdb->prepare(
				'SELECT DISTINCT t.client_id FROM ' . NDWD_Helpers::table( 'tasks' ) . ' t JOIN ' . NDWD_Helpers::table( 'task_assignees' ) . ' a ON a.task_id = t.id WHERE a.user_id = %d AND t.client_id IS NOT NULL AND t.is_archived = 0',
				$user_id
			) );
			foreach ( $ids as $id ) {
				$set( $id, 'basic' );
			}
			// Team assignments, subject to the team's permissions for the user's position.
			foreach ( NDWD_Permissions::memberships( $user_id ) as $team_id => $m ) {
				if ( empty( $m['perms']['view_clients'] ) ) {
					continue;
				}
				$level = empty( $m['perms']['view_client_contacts'] ) ? 'basic' : 'contacts';
				foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT client_id FROM ' . NDWD_Helpers::table( 'client_teams' ) . ' WHERE team_id = %d', $team_id ) ) as $id ) {
					$set( $id, $level );
				}
			}
			// Direct assignment.
			foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT client_id FROM ' . NDWD_Helpers::table( 'client_members' ) . ' WHERE user_id = %d', $user_id ) ) as $id ) {
				$set( $id, 'contacts' );
			}
		}
		self::$levels[ $user_id ] = $out;
		return $out;
	}

	/** 'full' | 'contacts' | 'basic' | '' (no access). */
	public static function level( $client_id, $user_id ) {
		$levels = self::access_levels( $user_id );
		if ( null === $levels ) {
			return self::get( $client_id ) ? 'full' : '';
		}
		return isset( $levels[ (int) $client_id ] ) ? $levels[ (int) $client_id ] : '';
	}

	public static function can_view( $client_id, $user_id ) {
		return '' !== self::level( $client_id, $user_id );
	}

	/** SQL fragment limiting client_id column $col to clients the user may see ('' = no limit). */
	public static function sql_limit( $col, $user_id, &$vals ) {
		$levels = self::access_levels( $user_id );
		if ( null === $levels ) {
			return '';
		}
		if ( ! $levels ) {
			return "$col IS NULL";
		}
		$ids  = array_keys( $levels );
		$vals = array_merge( $vals, $ids );
		return "($col IS NULL OR $col IN (" . NDWD_Helpers::in_placeholders( $ids ) . '))';
	}

	public static function assignments( $client_ids = null ) {
		global $wpdb;
		$out = array( 'teams' => array(), 'members' => array() );
		foreach ( $wpdb->get_results( 'SELECT client_id, team_id FROM ' . NDWD_Helpers::table( 'client_teams' ) ) as $r ) { // phpcs:ignore
			$out['teams'][ $r->client_id ][] = (int) $r->team_id;
		}
		foreach ( $wpdb->get_results( 'SELECT client_id, user_id FROM ' . NDWD_Helpers::table( 'client_members' ) ) as $r ) { // phpcs:ignore
			$out['members'][ $r->client_id ][] = (int) $r->user_id;
		}
		return $out;
	}

	/**
	 * Client as seen by $level. Basic: name and company only. Contacts: plus contact
	 * details and notes. Full: plus team/member assignments. Billing is added
	 * separately and only for Accounts users.
	 */
	public static function to_array( $c, $counts = array(), $level = 'full', $billing = false, $assign = null ) {
		$out = array(
			'id'         => (int) $c->id,
			'name'       => $c->name,
			'company'    => $c->company,
			'is_active'  => (bool) $c->is_active,
			'level'      => $level,
			'projects'   => isset( $counts['projects'][ $c->id ] ) ? (int) $counts['projects'][ $c->id ] : 0,
			'open_tasks' => isset( $counts['open'][ $c->id ] ) ? (int) $counts['open'][ $c->id ] : 0,
			'tasks'      => isset( $counts['tasks'][ $c->id ] ) ? (int) $counts['tasks'][ $c->id ] : 0,
		);
		if ( 'basic' !== $level ) {
			$out['contact_person'] = $c->contact_person;
			$out['phone']          = $c->phone;
			$out['email']          = $c->email;
			$out['notes']          = (string) $c->notes;
		}
		if ( 'full' === $level ) {
			$out['created_at'] = $c->created_at;
			$out['updated_at'] = $c->updated_at;
			$out['team_ids']   = $assign && isset( $assign['teams'][ $c->id ] ) ? $assign['teams'][ $c->id ] : array();
			$out['member_ids'] = $assign && isset( $assign['members'][ $c->id ] ) ? $assign['members'][ $c->id ] : array();
		}
		if ( $billing ) {
			foreach ( self::BILLING_FIELDS as $f ) {
				$out[ $f ] = isset( $c->$f ) ? (string) $c->$f : '';
			}
			$out['billing_emails'] = self::billing_emails( $c );
		}
		return $out;
	}

	/** Clients the user may see, shaped for their access level. */
	public static function all( $user_id = 0 ) {
		global $wpdb;
		$user_id = $user_id ? $user_id : get_current_user_id();
		$levels  = self::access_levels( $user_id );
		if ( is_array( $levels ) && ! $levels ) {
			return array();
		}
		$vals  = array();
		$where = is_array( $levels ) ? ' WHERE id IN (' . NDWD_Helpers::in_placeholders( array_keys( $levels ) ) . ')' : '';
		if ( is_array( $levels ) ) {
			$vals = array_keys( $levels );
		}
		$sql  = 'SELECT * FROM ' . self::table() . $where . ' ORDER BY is_active DESC, name';
		$rows = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		$counts = self::counts( $user_id );
		$assign = null === $levels ? self::assignments() : null;
		$bill   = self::sees_billing( $user_id );
		return array_map( function ( $c ) use ( $counts, $levels, $assign, $bill ) {
			return self::to_array( $c, $counts, null === $levels ? 'full' : $levels[ (int) $c->id ], $bill, $assign );
		}, $rows );
	}

	/** Project and task counts; task counts only include tasks the user can see. */
	private static function counts( $user_id ) {
		global $wpdb;
		$counts = array( 'projects' => array(), 'open' => array(), 'tasks' => array() );
		foreach ( $wpdb->get_results( 'SELECT client_id, COUNT(*) c FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE client_id IS NOT NULL GROUP BY client_id' ) as $r ) { // phpcs:ignore
			$counts['projects'][ $r->client_id ] = $r->c;
		}
		$vals = array();
		$vis  = NDWD_Tasks::visibility_condition( $user_id, $vals );
		$sql  = 'SELECT t.client_id, COUNT(*) c, SUM(CASE WHEN t.status <> \'completed\' THEN 1 ELSE 0 END) o FROM ' . NDWD_Helpers::table( 'tasks' ) . ' t WHERE t.client_id IS NOT NULL AND t.is_archived = 0' . ( $vis ? ' AND ' . $vis : '' ) . ' GROUP BY t.client_id';
		foreach ( $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ) as $r ) { // phpcs:ignore
			$counts['tasks'][ $r->client_id ] = $r->c;
			$counts['open'][ $r->client_id ]  = $r->o;
		}
		return $counts;
	}

	/** One client for the user, or a 404-style error when they may not see it. */
	public static function one( $id, $user_id ) {
		$level = self::level( $id, $user_id );
		$c     = $level ? self::get( $id ) : null;
		if ( ! $c ) {
			return new WP_Error( 'ndwd_not_found', 'Client not found or you do not have access.', array( 'status' => 404 ) );
		}
		$assign = 'full' === $level ? self::assignments() : null;
		return self::to_array( $c, self::counts( $user_id ), $level, self::sees_billing( $user_id ), $assign );
	}

	/** Normalises a phone / WhatsApp number. Returns null when invalid. */
	public static function clean_phone( $v ) {
		$v = trim( sanitize_text_field( (string) $v ) );
		if ( '' !== $v && ! preg_match( '/^\+?[0-9\s\-().]{6,20}$/', $v ) ) {
			return null;
		}
		return $v;
	}

	/** wa.me number: digits only, Indian 10-digit numbers get the 91 country code. */
	public static function wa_number( $v ) {
		$d = preg_replace( '/\D+/', '', (string) $v );
		if ( 11 === strlen( $d ) && '0' === $d[0] ) {
			$d = substr( $d, 1 );
		}
		return 10 === strlen( $d ) ? '91' . $d : $d;
	}

	/**
	 * Creates or updates a client.
	 *
	 * @return array|WP_Error The saved client.
	 */
	public static function save( $in, $user_id, $id = 0 ) {
		global $wpdb;
		$existing = $id ? self::get( $id ) : null;
		if ( $id && ! $existing ) {
			return new WP_Error( 'ndwd_not_found', 'Client not found.', array( 'status' => 404 ) );
		}
		$row = array();
		foreach ( array( 'name' => 160, 'company' => 160, 'contact_person' => 160 ) as $k => $len ) {
			if ( array_key_exists( $k, $in ) ) {
				$row[ $k ] = mb_substr( sanitize_text_field( (string) $in[ $k ] ), 0, $len );
			}
		}
		if ( ( ! $id || array_key_exists( 'name', $row ) ) && '' === ( isset( $row['name'] ) ? $row['name'] : '' ) ) {
			return new WP_Error( 'ndwd_invalid', 'Client name is required.', array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'phone', $in ) ) {
			$phone = self::clean_phone( $in['phone'] );
			if ( null === $phone ) {
				return new WP_Error( 'ndwd_invalid', 'Phone number should contain only digits, spaces, +, - or brackets (6–20 characters).', array( 'status' => 400 ) );
			}
			$row['phone'] = $phone;
		}
		foreach ( array( 'email' => 'Please enter a valid email address.', 'billing_email' => 'Please enter a valid billing email address.' ) as $k => $msg ) {
			if ( array_key_exists( $k, $in ) && ( 'email' === $k || ( self::sees_billing( $user_id ) && ! array_key_exists( 'billing_emails', $in ) ) ) ) {
				$email = trim( (string) $in[ $k ] );
				if ( '' !== $email && ! is_email( $email ) ) {
					return new WP_Error( 'ndwd_invalid', $msg, array( 'status' => 400 ) );
				}
				$row[ $k ] = '' === $email ? '' : sanitize_email( $email );
			}
		}
		if ( array_key_exists( 'notes', $in ) ) {
			$row['notes'] = sanitize_textarea_field( (string) $in['notes'] );
		}
		if ( array_key_exists( 'is_active', $in ) ) {
			$row['is_active'] = $in['is_active'] ? 1 : 0;
		}
		// Billing details: only people with the Accounts permission may read or change them.
		if ( self::sees_billing( $user_id ) ) {
			foreach ( array( 'billing_name' => 160, 'billing_contact' => 160 ) as $k => $len ) {
				if ( array_key_exists( $k, $in ) ) {
					$row[ $k ] = mb_substr( sanitize_text_field( (string) $in[ $k ] ), 0, $len );
				}
			}
			if ( array_key_exists( 'billing_whatsapp', $in ) ) {
				$wa = self::clean_phone( $in['billing_whatsapp'] );
				if ( null === $wa ) {
					return new WP_Error( 'ndwd_invalid', 'Billing WhatsApp number should contain only digits, spaces, +, - or brackets.', array( 'status' => 400 ) );
				}
				$row['billing_whatsapp'] = $wa;
			}
			if ( array_key_exists( 'billing_address', $in ) ) {
				$row['billing_address'] = sanitize_textarea_field( (string) $in['billing_address'] );
			}
			// Recipient list (To / CC). A request with only the old single field updates the list too.
			$list = null;
			if ( array_key_exists( 'billing_emails', $in ) ) {
				$list = self::clean_emails( $in['billing_emails'] );
				if ( is_wp_error( $list ) ) {
					return $list;
				}
			} elseif ( array_key_exists( 'billing_email', $row ) ) {
				$list = self::billing_emails( (object) array( 'billing_emails' => $existing ? $existing->billing_emails : '', 'billing_email' => $existing ? $existing->billing_email : '' ) );
				$list = array_values( array_filter( $list, function ( $e ) use ( $existing ) {
					return ! $existing || strtolower( $e['email'] ) !== strtolower( $existing->billing_email );
				} ) );
				if ( '' !== $row['billing_email'] ) {
					array_unshift( $list, array( 'email' => $row['billing_email'], 'type' => 'to' ) );
				}
				$list = self::clean_emails( $list );
				if ( is_wp_error( $list ) ) {
					return $list;
				}
			}
			if ( null !== $list ) {
				list( $to ) = self::split_emails( $list );
				$row['billing_emails'] = wp_json_encode( $list );
				$row['billing_email']  = $to ? $to[0] : '';
			}
			if ( array_key_exists( 'billing_wa_group', $in ) ) {
				$grp = self::clean_wa_group( $in['billing_wa_group'] );
				if ( null === $grp ) {
					return new WP_Error( 'ndwd_invalid', 'Billing WhatsApp group: paste the group invite link, e.g. https://chat.whatsapp.com/AbC123…', array( 'status' => 400 ) );
				}
				$row['billing_wa_group'] = $grp;
			}
			if ( array_key_exists( 'gstin', $in ) ) {
				$g = strtoupper( preg_replace( '/\s+/', '', (string) $in['gstin'] ) );
				if ( '' !== $g && ! preg_match( '/^[0-9]{2}[A-Z0-9]{13}$/', $g ) ) {
					return new WP_Error( 'ndwd_invalid', 'GSTIN should be 15 characters: 2-digit state code followed by 13 letters/digits.', array( 'status' => 400 ) );
				}
				$row['gstin'] = $g;
			}
		}
		if ( isset( $row['name'] ) ) {
			$dup = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE LOWER(name) = LOWER(%s) AND id <> %d', $row['name'], $id ) );
			if ( $dup ) {
				return new WP_Error( 'ndwd_invalid', 'A client with this name already exists.', array( 'status' => 400 ) );
			}
		}
		$row['updated_at'] = NDWD_Helpers::now_mysql();
		if ( $id ) {
			$wpdb->update( self::table(), $row, array( 'id' => $id ) );
			NDWD_Activity::log( $user_id, null, 'client_updated', array( 'client_id' => $id, 'client' => isset( $row['name'] ) ? $row['name'] : $existing->name ) );
		} else {
			$row['created_by'] = (int) $user_id;
			$row['created_at'] = $row['updated_at'];
			if ( false === $wpdb->insert( self::table(), $row ) ) {
				return new WP_Error( 'ndwd_db', 'Could not save the client.', array( 'status' => 500 ) );
			}
			$id = (int) $wpdb->insert_id;
			NDWD_Activity::log( $user_id, null, 'client_created', array( 'client_id' => $id, 'client' => $row['name'] ) );
		}
		// Team / member assignments decide who else may see the client.
		foreach ( array( 'team_ids' => array( 'client_teams', 'team_id' ), 'member_ids' => array( 'client_members', 'user_id' ) ) as $k => $def ) {
			if ( isset( $in[ $k ] ) && is_array( $in[ $k ] ) ) {
				$tbl = NDWD_Helpers::table( $def[0] );
				$wpdb->delete( $tbl, array( 'client_id' => $id ) );
				foreach ( array_unique( array_filter( array_map( 'absint', $in[ $k ] ) ) ) as $v ) {
					$wpdb->insert( $tbl, array( 'client_id' => $id, $def[1] => $v ) );
				}
			}
		}
		self::flush();
		// Keep the legacy free-text client on linked projects in step with the record.
		if ( isset( $row['name'] ) ) {
			$wpdb->update( NDWD_Helpers::table( 'projects' ), array( 'client' => $row['name'] ), array( 'client_id' => $id ) );
		}
		return self::one( $id, $user_id );
	}
}
