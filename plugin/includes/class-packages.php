<?php
/**
 * Service packages: the service catalogue, client package allocations, billing cycles and
 * the usage ledger that links tasks to package units.
 *
 * Rules
 * - A package template (ndwd_packages + package_items) is copied into a client allocation
 *   when it is assigned. Every allocation has its own quantities and counters, so two
 *   clients on the same template never share units, and editing the template changes
 *   neither existing allocations nor issued invoices.
 * - Usage is a ledger (package_usage), never a bare counter. A task linked to an included
 *   service holds one live row: "reserved" while the task is open, "consumed" once it is
 *   completed, "released" when it is rejected, archived, unlinked or moved. The unique
 *   live_task_id key allows only one live row per task, so saving, editing or reassigning
 *   a task can never deduct twice. Released rows stay as history.
 * - Monthly allocations count usage per billing cycle. A cycle starts on the allocation's
 *   start day each month (the month's last day when shorter), using Asia/Kolkata dates.
 *   A new cycle starts at zero; earlier cycles keep their usage and their own limits
 *   (package_cycles). Fixed-duration and one-time allocations have one cycle that never
 *   resets. Paused, cancelled or expired allocations stay in the cycle they stopped in,
 *   unless "reset while paused" is switched on for that allocation.
 * - remaining = allocated + adjustments − reserved − consumed. Linking a task when nothing
 *   remains is refused, unless an Accounts user approves the extra work with a reason.
 * - Retainer-scope and additional-scope tasks are tracked but take no units. Whether any
 *   work is charged is decided on the invoice, never automatically.
 * - An allocation can belong to one project (project_id). Projects and sub-projects never share
 *   units: a task can only use a package of its own project, or an older client-wide one
 *   (project_id empty) kept from before 1.11.
 * - Carry-forward is manual: an Accounts user reviews a finished cycle's unused units and accepts
 *   or rejects them per service. Accepted units reach the next cycle as a ledger adjustment,
 *   rejected ones stay on record, and each service's cycle can be decided only once.
 * - Prices are only returned to people with the Accounts permission; anyone who can see a
 *   client sees its packages' services and balances (needed to plan and create tasks).
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Packages {

	const TYPES = array(
		'monthly'  => 'Monthly',
		'fixed'    => 'Fixed duration',
		'one_time' => 'One-time',
	);

	const RESET_RULES = array(
		'monthly' => 'Reset at the start of every billing cycle',
		'none'    => 'No reset',
	);

	const SCOPES = array(
		'included'   => 'Included service',
		'retainer'   => 'Retainer scope',
		'additional' => 'Additional scope',
	);

	const STATUSES = array(
		'active'    => 'Active',
		'upcoming'  => 'Starts later',
		'paused'    => 'Paused',
		'expired'   => 'Expired',
		'inactive'  => 'Deactivated',
		'cancelled' => 'Cancelled',
		'archived'  => 'Archived',
	);

	/** slug, name, unit, plural, keywords used to suggest the service from a task title. */
	const DEFAULT_SERVICES = array(
		array( 'video_editing', 'Video Editing', 'video', 'videos', 'video,videos,reel,reels,shorts,youtube' ),
		array( 'poster_design', 'Poster Design', 'poster', 'posters', 'poster,posters,banner,flyer,creative' ),
		array( 'carousel_design', 'Carousel Design', 'carousel', 'carousels', 'carousel,carousels' ),
		array( 'website_development', 'Website Development', 'project', 'projects', 'website,websites,web page,webpage,landing page' ),
		array( 'logo_design', 'Logo Design', 'logo', 'logos', 'logo,logos' ),
		array( 'custom', 'Other / Custom Service', 'unit', 'units', '' ),
	);

	private static $cache = array();

	private static function t( $name ) {
		return NDWD_Helpers::table( $name );
	}

	public static function flush() {
		self::$cache = array();
	}

	private static function err( $msg, $status = 400, $code = 'ndwd_invalid', $extra = array() ) {
		return new WP_Error( $code, $msg, array_merge( array( 'status' => $status ), $extra ) );
	}

	private static function can_manage( $user_id = 0 ) {
		return NDWD_Permissions::can( 'ndwd_manage_accounts', $user_id );
	}

	/* ------------------------------------------------------------------ *
	 * Service catalogue
	 * ------------------------------------------------------------------ */

	/** Adds the standard services that are missing. Never changes or removes existing ones. */
	public static function seed_services() {
		global $wpdb;
		$t = self::t( 'package_services' );
		foreach ( self::DEFAULT_SERVICES as $i => $s ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE slug = %s", $s[0] ) ) ) { // phpcs:ignore
				$wpdb->insert( $t, array( 'slug' => $s[0], 'name' => $s[1], 'unit' => $s[2], 'unit_plural' => $s[3], 'keywords' => $s[4], 'sort' => $i, 'created_at' => NDWD_Helpers::now_mysql() ) );
			}
		}
	}

	public static function service_array( $s ) {
		return array(
			'id'          => (int) $s->id,
			'slug'        => $s->slug,
			'name'        => $s->name,
			'unit'        => $s->unit,
			'unit_plural' => $s->unit_plural,
			'keywords'    => $s->keywords,
			'is_active'   => (bool) $s->is_active,
		);
	}

	public static function service( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! isset( self::$cache['svc'][ $id ] ) ) {
			self::$cache['svc'][ $id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'package_services' ) . ' WHERE id = %d', $id ) );
		}
		return self::$cache['svc'][ $id ];
	}

	public static function services( $active_only = false ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'package_services' ) . ( $active_only ? ' WHERE is_active = 1' : '' ) . ' ORDER BY sort, name' ); // phpcs:ignore
		return array_map( array( __CLASS__, 'service_array' ), $rows );
	}

	public static function save_service( $in, $user_id, $id = 0 ) {
		global $wpdb;
		$t   = self::t( 'package_services' );
		$old = $id ? self::service( $id ) : null;
		if ( $id && ! $old ) {
			return self::err( 'Service not found.', 404, 'ndwd_not_found' );
		}
		$get = function ( $k, $len, $def ) use ( $in, $old ) {
			return isset( $in[ $k ] ) ? mb_substr( sanitize_text_field( (string) $in[ $k ] ), 0, $len ) : ( $old ? $old->$k : $def );
		};
		$name = $get( 'name', 120, '' );
		if ( '' === $name ) {
			return self::err( 'Enter the service name.' );
		}
		$dup = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE LOWER(name) = LOWER(%s) AND id <> %d", $name, $id ) ); // phpcs:ignore
		if ( $dup ) {
			return self::err( 'A service with this name already exists.' );
		}
		$unit   = $get( 'unit', 40, 'unit' );
		$plural = $get( 'unit_plural', 40, '' );
		$row    = array(
			'name'        => $name,
			'unit'        => '' !== $unit ? $unit : 'unit',
			'unit_plural' => '' !== $plural ? $plural : ( '' !== $unit ? $unit . 's' : 'units' ),
			'keywords'    => mb_substr( implode( ',', array_filter( array_map( 'trim', explode( ',', strtolower( $get( 'keywords', 255, '' ) ) ) ) ) ), 0, 255 ),
			'is_active'   => isset( $in['is_active'] ) ? ( $in['is_active'] ? 1 : 0 ) : ( $old ? (int) $old->is_active : 1 ),
		);
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			NDWD_Accounts::audit( 'service', $id, 'service_updated', $user_id, array( 'name' => $name ) );
		} else {
			$row['slug']       = mb_substr( sanitize_title( $name ), 0, 50 ) . '-' . wp_generate_password( 4, false, false );
			$row['sort']       = 100 + (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore
			$row['created_at'] = NDWD_Helpers::now_mysql();
			$wpdb->insert( $t, $row );
			$id = (int) $wpdb->insert_id;
			NDWD_Accounts::audit( 'service', $id, 'service_created', $user_id, array( 'name' => $name, 'unit' => $row['unit'] ) );
		}
		self::flush();
		return self::service_array( self::service( $id ) );
	}

	/** Best service for a task title among $items (rows with service_id): the one whose keywords match most. */
	public static function detect_item( $title, $items ) {
		$title = ' ' . strtolower( preg_replace( '/[^a-z0-9]+/i', ' ', (string) $title ) ) . ' ';
		$best  = array( 0, array() );
		foreach ( $items as $it ) {
			$svc = self::service( is_array( $it ) ? $it['service_id'] : $it->service_id );
			if ( ! $svc || '' === $svc->keywords ) {
				continue;
			}
			$score = 0;
			foreach ( explode( ',', $svc->keywords ) as $k ) {
				$k = trim( $k );
				if ( '' !== $k && false !== strpos( $title, ' ' . $k . ' ' ) ) {
					$score++;
				}
			}
			if ( $score > $best[0] ) {
				$best = array( $score, array( $it ) );
			} elseif ( $score && $score === $best[0] ) {
				$best[1][] = $it;
			}
		}
		if ( 1 !== count( $best[1] ) ) {
			return null;
		}
		$it = $best[1][0];
		return (int) ( is_array( $it ) ? $it['id'] : $it->id );
	}

	/**
	 * Validates a list of [service_id, quantity, notes] (template or allocation items).
	 *
	 * @return array|WP_Error
	 */
	public static function clean_items( $items ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $items as $i => $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$sid = absint( isset( $it['service_id'] ) ? $it['service_id'] : 0 );
			$svc = $sid ? self::service( $sid ) : null;
			if ( ! $svc ) {
				return self::err( 'Service ' . ( $i + 1 ) . ': choose a service.' );
			}
			if ( isset( $seen[ $sid ] ) ) {
				return self::err( '"' . $svc->name . '" is listed twice. Combine the quantities in one line.' );
			}
			$q = isset( $it['quantity'] ) && '' !== $it['quantity'] ? $it['quantity'] : 0;
			if ( ! is_numeric( $q ) || (int) $q != $q || (int) $q < 0 || (int) $q > 10000 ) { // phpcs:ignore
				return self::err( '"' . $svc->name . '": the quantity must be a whole number from 0 to 10000.' );
			}
			$seen[ $sid ] = true;
			$out[]        = array(
				'id'         => absint( isset( $it['id'] ) ? $it['id'] : 0 ),
				'service_id' => $sid,
				'quantity'   => (int) $q,
				'notes'      => mb_substr( sanitize_text_field( isset( $it['notes'] ) ? (string) $it['notes'] : '' ), 0, 255 ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Templates (extra fields on ndwd_packages, items in package_items)
	 * ------------------------------------------------------------------ */

	public static function template_items( $package_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'package_items' ) . ' WHERE package_id = %d ORDER BY sort, id', $package_id ) );
		return array_values( array_filter( array_map( function ( $r ) {
			$svc = self::service( $r->service_id );
			return $svc ? array( 'id' => (int) $r->id, 'service_id' => (int) $r->service_id, 'service_name' => $svc->name, 'unit' => $svc->unit, 'unit_plural' => $svc->unit_plural, 'quantity' => (int) $r->quantity, 'notes' => $r->notes ) : null;
		}, $rows ) ) );
	}

	/** Extra template columns for NDWD_Accounts::save_package(). @return array|WP_Error */
	public static function template_fields( $in, $old ) {
		$type = isset( $in['package_type'] ) ? sanitize_key( $in['package_type'] ) : ( $old ? $old->package_type : 'monthly' );
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return self::err( 'Choose the package type: Monthly, Fixed duration or One-time.' );
		}
		$row = array( 'package_type' => $type );
		foreach ( array( 'start_date', 'end_date' ) as $k ) {
			$v = array_key_exists( $k, $in ) ? (string) $in[ $k ] : ( $old ? (string) $old->$k : '' );
			if ( '' !== $v && ! NDWD_Helpers::valid_date( $v ) ) {
				return self::err( 'Enter a valid ' . str_replace( '_', ' ', $k ) . '.' );
			}
			$row[ $k ] = '' !== $v ? $v : null;
		}
		if ( $row['start_date'] && $row['end_date'] && $row['end_date'] < $row['start_date'] ) {
			return self::err( 'The end date cannot be before the start date.' );
		}
		$dur = isset( $in['duration_months'] ) ? absint( $in['duration_months'] ) : ( $old ? (int) $old->duration_months : 0 );
		if ( $dur > 120 ) {
			return self::err( 'Duration can be at most 120 months.' );
		}
		$row['duration_months'] = 'fixed' === $type ? $dur : 0;
		$reset                  = isset( $in['reset_rule'] ) ? sanitize_key( $in['reset_rule'] ) : ( $old ? $old->reset_rule : 'monthly' );
		$row['reset_rule']      = 'monthly' === $type && 'none' !== $reset ? 'monthly' : 'none';
		foreach ( array( 'scope', 'terms', 'remarks' ) as $k ) {
			$row[ $k ] = mb_substr( sanitize_textarea_field( isset( $in[ $k ] ) ? (string) $in[ $k ] : ( $old ? (string) $old->$k : '' ) ), 0, 4000 );
		}
		return $row;
	}

	/** Replaces the template's service lines. Existing client allocations keep their own copy. */
	public static function save_template_items( $package_id, $items ) {
		global $wpdb;
		$t = self::t( 'package_items' );
		$wpdb->delete( $t, array( 'package_id' => (int) $package_id ) );
		foreach ( $items as $i => $it ) {
			$wpdb->insert( $t, array( 'package_id' => (int) $package_id, 'service_id' => $it['service_id'], 'quantity' => $it['quantity'], 'notes' => $it['notes'], 'sort' => $i ) );
		}
	}

	/** Fields added to NDWD_Accounts::package_array(). */
	public static function template_extra( $p ) {
		global $wpdb;
		return array(
			'package_type'    => isset( $p->package_type ) ? $p->package_type : 'monthly',
			'type_label'      => isset( self::TYPES[ $p->package_type ] ) ? self::TYPES[ $p->package_type ] : '',
			'duration_months' => (int) $p->duration_months,
			'start_date'      => $p->start_date,
			'end_date'        => $p->end_date,
			'reset_rule'      => $p->reset_rule,
			'scope'           => (string) $p->scope,
			'terms'           => (string) $p->terms,
			'remarks'         => (string) $p->remarks,
			'items'           => self::template_items( $p->id ),
			'assigned'        => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'client_packages' ) . " WHERE package_id = %d AND status <> 'cancelled'", $p->id ) ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Allocations and cycles
	 * ------------------------------------------------------------------ */

	public static function allocation( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! isset( self::$cache['alloc'][ $id ] ) ) {
			self::$cache['alloc'][ $id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'client_packages' ) . ' WHERE id = %d', $id ) );
		}
		return self::$cache['alloc'][ $id ];
	}

	public static function items( $alloc_id, $active_only = false ) {
		global $wpdb;
		$alloc_id = (int) $alloc_id;
		if ( ! isset( self::$cache['items'][ $alloc_id ] ) ) {
			self::$cache['items'][ $alloc_id ] = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'client_package_items' ) . ' WHERE client_package_id = %d ORDER BY sort, id', $alloc_id ) );
		}
		$rows = self::$cache['items'][ $alloc_id ];
		return $active_only ? array_values( array_filter( $rows, function ( $r ) {
			return (int) $r->is_active;
		} ) ) : $rows;
	}

	public static function item( $alloc_id, $item_id ) {
		foreach ( self::items( $alloc_id ) as $it ) {
			if ( (int) $it->id === (int) $item_id ) {
				return $it;
			}
		}
		return null;
	}

	/** Stored status, or Expired / Starts later derived from the dates for an active allocation. */
	public static function effective_status( $a ) {
		$today = NDWD_Helpers::today();
		if ( 'active' !== $a->status ) {
			return $a->status;
		}
		if ( $a->end_date && $a->end_date < $today ) {
			return 'expired';
		}
		return $a->start_date > $today ? 'upcoming' : 'active';
	}

	private static function anchor( $y, $m, $day ) {
		$last = (int) ( new DateTimeImmutable( sprintf( '%04d-%02d-01', $y, $m ) ) )->format( 't' );
		return sprintf( '%04d-%02d-%02d', $y, $m, min( $day, $last ) );
	}

	/** True when usage of this allocation is counted per billing cycle. */
	public static function resets( $a ) {
		return 'monthly' === $a->package_type && 'monthly' === $a->reset_rule;
	}

	/**
	 * [start, end] of the billing cycle that contains $date, or null when the date is outside
	 * the allocation's start and end dates.
	 */
	public static function cycle_for( $a, $date ) {
		if ( ! $date || $date < $a->start_date || ( $a->end_date && $date > $a->end_date ) ) {
			return null;
		}
		if ( ! self::resets( $a ) ) {
			return array( $a->start_date, $a->end_date ? $a->end_date : '9999-12-31' );
		}
		$day   = (int) substr( $a->start_date, 8, 2 );
		$y     = (int) substr( $date, 0, 4 );
		$m     = (int) substr( $date, 5, 2 );
		$start = self::anchor( $y, $m, $day );
		if ( $start > $date ) {
			$m--;
			if ( $m < 1 ) {
				$m = 12;
				$y--;
			}
			$start = self::anchor( $y, $m, $day );
		}
		$ny = $m < 12 ? $y : $y + 1;
		$nm = $m < 12 ? $m + 1 : 1;
		$end = ( new DateTimeImmutable( self::anchor( $ny, $nm, $day ) ) )->modify( '-1 day' )->format( 'Y-m-d' );
		if ( $a->end_date && $end > $a->end_date ) {
			$end = $a->end_date;
		}
		return array( max( $start, $a->start_date ), $end );
	}

	/**
	 * The date whose cycle is "current". Active: today. Paused, cancelled or expired: the day
	 * it stopped (so its counters do not reset), unless reset-while-paused is on.
	 */
	public static function reference_date( $a ) {
		$today = NDWD_Helpers::today();
		$d     = $today;
		if ( 'active' !== $a->status && ! ( 'paused' === $a->status && (int) $a->reset_when_paused ) && $a->status_changed_at ) {
			$d = min( $today, substr( $a->status_changed_at, 0, 10 ) );
		}
		if ( $a->end_date && $d > $a->end_date ) {
			$d = $a->end_date;
		}
		return $d < $a->start_date ? $a->start_date : $d;
	}

	public static function current_cycle( $a ) {
		return self::cycle_for( $a, self::reference_date( $a ) );
	}

	public static function cycle_label( $a, $cycle ) {
		if ( ! self::resets( $a ) ) {
			return $a->end_date ? date_i18n( 'j M Y', strtotime( $a->start_date ) ) . ' – ' . date_i18n( 'j M Y', strtotime( $a->end_date ) ) : 'From ' . date_i18n( 'j M Y', strtotime( $a->start_date ) ) . ' (no reset)';
		}
		return date_i18n( 'j M', strtotime( $cycle[0] ) ) . ' – ' . date_i18n( 'j M Y', strtotime( $cycle[1] ) );
	}

	/**
	 * The cycle row, created on first use with the quantities in force at that moment. Its
	 * snapshot keeps earlier cycles' limits even after the allocation is adjusted later.
	 */
	public static function ensure_cycle( $a, $cycle ) {
		global $wpdb;
		$t   = self::t( 'package_cycles' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE client_package_id = %d AND cycle_start = %s", $a->id, $cycle[0] ) ); // phpcs:ignore
		if ( $row ) {
			return $row;
		}
		$snap = array();
		foreach ( self::items( $a->id, true ) as $it ) {
			$snap[ (int) $it->id ] = (int) $it->quantity;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (client_package_id, cycle_start, cycle_end, allocated, created_at) VALUES (%d, %s, %s, %s, %s)", $a->id, $cycle[0], $cycle[1], wp_json_encode( (object) $snap ), NDWD_Helpers::now_mysql() ) ); // phpcs:ignore
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE client_package_id = %d AND cycle_start = %s", $a->id, $cycle[0] ) ); // phpcs:ignore
	}

	/** Quantity allocated to $item in a cycle: the cycle's snapshot, else the current quantity. */
	private static function allocated( $item, $cycle_row ) {
		$snap = $cycle_row ? json_decode( (string) $cycle_row->allocated, true ) : null;
		if ( is_array( $snap ) && isset( $snap[ (int) $item->id ] ) ) {
			return (int) $snap[ (int) $item->id ];
		}
		return (int) $item->is_active ? (int) $item->quantity : 0;
	}

	/** [item_id => [reserved, consumed, adjusted]] for one cycle. */
	public static function totals( $alloc_id, $cycle_start ) {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT item_id, state, SUM(quantity) q FROM ' . self::t( 'package_usage' ) . " WHERE client_package_id = %d AND cycle_start = %s AND state IN ('reserved','consumed','adjustment') GROUP BY item_id, state", $alloc_id, $cycle_start ) );
		foreach ( $rows as $r ) {
			if ( ! isset( $out[ (int) $r->item_id ] ) ) {
				$out[ (int) $r->item_id ] = array( 'reserved' => 0, 'consumed' => 0, 'adjusted' => 0 );
			}
			$out[ (int) $r->item_id ][ 'adjustment' === $r->state ? 'adjusted' : $r->state ] = (int) $r->q;
		}
		return $out;
	}

	/** Balance of one service in one cycle. */
	public static function balance( $a, $item, $cycle ) {
		global $wpdb;
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'package_cycles' ) . ' WHERE client_package_id = %d AND cycle_start = %s', $a->id, $cycle[0] ) );
		$tot  = self::totals( $a->id, $cycle[0] );
		$u    = isset( $tot[ (int) $item->id ] ) ? $tot[ (int) $item->id ] : array( 'reserved' => 0, 'consumed' => 0, 'adjusted' => 0 );
		$all  = self::allocated( $item, $row );
		return array(
			'allocated' => $all,
			'adjusted'  => $u['adjusted'],
			'reserved'  => $u['reserved'],
			'consumed'  => $u['consumed'],
			'remaining' => $all + $u['adjusted'] - $u['reserved'] - $u['consumed'],
		);
	}

	/** Retainer / additional / over-limit task counts in a cycle. */
	private static function scope_counts( $a, $cycle ) {
		global $wpdb;
		$out = array( 'retainer' => 0, 'additional' => 0, 'over_limit' => 0 );
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT package_scope s, COUNT(*) c FROM ' . self::t( 'tasks' ) . " WHERE client_package_id = %d AND is_archived = 0 AND status <> 'rejected' AND package_scope IN ('retainer','additional') AND scheduled_date BETWEEN %s AND %s GROUP BY package_scope", $a->id, $cycle[0], $cycle[1] ) ) as $r ) {
			$out[ $r->s ] = (int) $r->c;
		}
		$out['over_limit'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'package_usage' ) . " WHERE client_package_id = %d AND cycle_start = %s AND over_limit = 1 AND state IN ('reserved','consumed')", $a->id, $cycle[0] ) );
		return $out;
	}

	/** Earlier cycles of a resetting allocation, newest first. */
	private static function history( $a, $before, $limit = 12 ) {
		global $wpdb;
		$starts = $wpdb->get_col( $wpdb->prepare(
			'SELECT cycle_start FROM ' . self::t( 'package_cycles' ) . ' WHERE client_package_id = %d AND cycle_start < %s UNION SELECT DISTINCT cycle_start FROM ' . self::t( 'package_usage' ) . ' WHERE client_package_id = %d AND cycle_start < %s',
			$a->id, $before, $a->id, $before
		) );
		$starts = array_values( array_unique( $starts ) );
		rsort( $starts );
		$out = array();
		foreach ( array_slice( $starts, 0, $limit ) as $s ) {
			$cycle = self::cycle_for( $a, $s );
			$cycle = $cycle ? $cycle : array( $s, $s );
			$items = array();
			foreach ( self::items( $a->id ) as $it ) {
				$b = self::balance( $a, $it, $cycle );
				if ( $b['allocated'] || $b['reserved'] || $b['consumed'] || $b['adjusted'] ) {
					$items[] = array_merge( array( 'item_id' => (int) $it->id, 'service_name' => $it->service_name, 'unit_plural' => $it->unit_plural ), $b );
				}
			}
			$out[] = array_merge( array( 'start' => $cycle[0], 'end' => $cycle[1], 'label' => self::cycle_label( $a, $cycle ), 'items' => $items ), self::scope_counts( $a, $cycle ) );
		}
		return $out;
	}

	/**
	 * Allocation for the API. $opts: price (bool), history (bool), entries (bool).
	 */
	public static function allocation_array( $a, $opts = array() ) {
		$cycle  = self::current_cycle( $a );
		$crow   = $cycle ? self::ensure_cycle_if_running( $a, $cycle ) : null;
		$client = NDWD_Clients::get( $a->client_id );
		$eff    = self::effective_status( $a );
		$items  = array();
		foreach ( self::items( $a->id ) as $it ) {
			$b = $cycle ? self::balance( $a, $it, $cycle ) : array( 'allocated' => (int) $it->quantity, 'adjusted' => 0, 'reserved' => 0, 'consumed' => 0, 'remaining' => (int) $it->quantity );
			if ( ! (int) $it->is_active && ! $b['reserved'] && ! $b['consumed'] && ! $b['adjusted'] ) {
				continue;
			}
			$svc     = self::service( $it->service_id );
			$items[] = array_merge( array(
				'id'           => (int) $it->id,
				'service_id'   => (int) $it->service_id,
				'service_name' => $it->service_name,
				'unit'         => $it->unit,
				'unit_plural'  => $it->unit_plural,
				'quantity'     => (int) $it->quantity,
				'notes'        => $it->notes,
				'is_active'    => (bool) $it->is_active,
				'keywords'     => $svc ? $svc->keywords : '',
			), $b );
		}
		$project = ! empty( $a->project_id ) ? self::project_row( $a->project_id ) : null;
		$out = array(
			'id'                => (int) $a->id,
			'client_id'         => (int) $a->client_id,
			'client_name'       => $client ? $client->name : '',
			'project_id'        => $project ? (int) $project->id : null,
			'project_name'      => $project ? $project->name : '',
			'parent_inactive'   => 'active' === $eff && ( ( $client && ! (int) $client->is_active ) || ( $project && ( ! (int) $project->is_active || (int) $project->is_archived ) ) ),
			'carry'             => self::carry_state( $a, $cycle ),
			'package_id'        => $a->package_id ? (int) $a->package_id : null,
			'name'              => $a->name,
			'package_type'      => $a->package_type,
			'type_label'        => isset( self::TYPES[ $a->package_type ] ) ? self::TYPES[ $a->package_type ] : $a->package_type,
			'billing_period'    => $a->billing_period,
			'period_label'      => isset( NDWD_Accounts::PERIODS[ $a->billing_period ] ) ? NDWD_Accounts::PERIODS[ $a->billing_period ] : '',
			'start_date'        => $a->start_date,
			'end_date'          => $a->end_date,
			'reset_rule'        => $a->reset_rule,
			'resets'            => self::resets( $a ),
			'reset_when_paused' => (bool) $a->reset_when_paused,
			'scope'             => (string) $a->scope,
			'terms'             => (string) $a->terms,
			'remarks'           => (string) $a->remarks,
			'status'            => $a->status,
			'effective_status'  => $eff,
			'status_label'      => self::STATUSES[ $eff ],
			'can_link'          => 'active' === $a->status && ! ( $a->end_date && $a->end_date < NDWD_Helpers::today() ),
			'cycle'             => $cycle ? array( 'start' => $cycle[0], 'end' => $cycle[1], 'label' => self::cycle_label( $a, $cycle ) ) : null,
			'items'             => $items,
			'counts'            => $cycle ? self::scope_counts( $a, $cycle ) : array( 'retainer' => 0, 'additional' => 0, 'over_limit' => 0 ),
		);
		if ( ! empty( $opts['price'] ) ) {
			$out['price'] = (float) $a->price;
		}
		if ( ! empty( $opts['history'] ) ) {
			$out['history'] = $cycle && self::resets( $a ) ? self::history( $a, $cycle[0] ) : array();
		}
		if ( ! empty( $opts['entries'] ) ) {
			$out['entries'] = self::entries( $a );
		}
		return $out;
	}

	/** Cycle row only while the cycle is running or past (no rows for future cycles). */
	private static function ensure_cycle_if_running( $a, $cycle ) {
		return $cycle[0] <= NDWD_Helpers::today() ? self::ensure_cycle( $a, $cycle ) : null;
	}

	/** Usage ledger of an allocation, newest first (Accounts view). */
	public static function entries( $a, $limit = 200 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT u.*, t.title FROM ' . self::t( 'package_usage' ) . ' u LEFT JOIN ' . self::t( 'tasks' ) . ' t ON t.id = u.task_id WHERE u.client_package_id = %d ORDER BY u.id DESC LIMIT %d', $a->id, $limit ) );
		return array_map( function ( $u ) use ( $a ) {
			$it = self::item( $a->id, $u->item_id );
			return array(
				'id'             => (int) $u->id,
				'task_id'        => $u->task_id ? (int) $u->task_id : null,
				'task_title'     => (string) $u->title,
				'service_name'   => $it ? $it->service_name : '',
				'cycle_start'    => $u->cycle_start,
				'quantity'       => (int) $u->quantity,
				'state'          => $u->state,
				'over_limit'     => (bool) $u->over_limit,
				'approved_by'    => $u->approved_by ? NDWD_Helpers::user_name( $u->approved_by ) : '',
				'note'           => (string) $u->note,
				'created_by'     => NDWD_Helpers::user_name( $u->created_by ),
				'created_at'     => $u->created_at,
				'consumed_at'    => $u->consumed_at,
				'released_at'    => $u->released_at,
				'release_reason' => $u->release_reason,
			);
		}, $rows );
	}

	/** Allocations of a client, active ones first. */
	public static function for_client( $client_id, $opts = array() ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'client_packages' ) . " WHERE client_id = %d ORDER BY CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 ELSE 2 END, start_date DESC, id DESC", $client_id ) );
		return array_map( function ( $a ) use ( $opts ) {
			self::$cache['alloc'][ (int) $a->id ] = $a;
			return self::allocation_array( $a, $opts );
		}, $rows );
	}

	/** Additional / out-of-package work of a client that is not tied to a package. */
	public static function unpackaged_additional( $client_id, $limit = 50 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, title, status, scheduled_date, completed_at, package_note FROM ' . self::t( 'tasks' ) . " WHERE client_id = %d AND is_archived = 0 AND package_scope = 'additional' ORDER BY scheduled_date DESC, id DESC LIMIT %d", $client_id, $limit ) );
		return array_map( function ( $r ) {
			return array( 'id' => (int) $r->id, 'title' => $r->title, 'status' => $r->status, 'scheduled_date' => $r->scheduled_date, 'completed_at' => $r->completed_at, 'note' => (string) $r->package_note );
		}, $rows );
	}

	private static function project_row( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( ! isset( self::$cache['project'][ $id ] ) ) {
			self::$cache['project'][ $id ] = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'projects' ) . ' WHERE id = %d', $id ) );
		}
		return self::$cache['project'][ $id ];
	}

	/** @return int|null|WP_Error The project id for an allocation of this client (0 / empty = none). */
	private static function check_project( $project_id, $client_id ) {
		$project_id = absint( $project_id );
		if ( ! $project_id ) {
			return null;
		}
		$p = self::project_row( $project_id );
		if ( ! $p || (int) $p->client_id !== (int) $client_id ) {
			return self::err( 'The selected project belongs to another client.' );
		}
		return $project_id;
	}

	/** Every allocation with its current cycle, for the Accounts utilisation overview. */
	public static function utilization( $f = array() ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::t( 'client_packages' ) . ' ORDER BY client_id, start_date DESC, id DESC' ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $a ) {
			self::$cache['alloc'][ (int) $a->id ] = $a;
			$eff = self::effective_status( $a );
			if ( ! empty( $f['status'] ) && $f['status'] !== $eff ) {
				continue;
			}
			if ( empty( $f['status'] ) && in_array( $eff, array( 'cancelled', 'archived', 'inactive' ), true ) ) {
				continue;
			}
			if ( ! empty( $f['project_id'] ) && (int) $a->project_id !== (int) $f['project_id'] ) {
				continue;
			}
			$out[] = self::allocation_array( $a, array( 'price' => true ) );
		}
		return $out;
	}

	/**
	 * Assigns a package to a client: copies the template (when given) and applies the
	 * client-specific price, dates, quantities and scope.
	 *
	 * @return array|WP_Error
	 */
	public static function assign( $client_id, $in, $user_id ) {
		global $wpdb;
		$client = NDWD_Clients::get( $client_id );
		if ( ! $client ) {
			return self::err( 'Client not found.', 404, 'ndwd_not_found' );
		}
		$tpl = null;
		if ( ! empty( $in['package_id'] ) ) {
			$tpl = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'packages' ) . ' WHERE id = %d', absint( $in['package_id'] ) ) );
			if ( ! $tpl ) {
				return self::err( 'Package template not found.' );
			}
			if ( ! (int) $tpl->is_active ) {
				return self::err( '"' . $tpl->name . '" is inactive, so it cannot be used for new assignments.' );
			}
		}
		$project_id = self::check_project( isset( $in['project_id'] ) ? $in['project_id'] : 0, $client_id );
		if ( is_wp_error( $project_id ) ) {
			return $project_id;
		}
		$pick = function ( $k, $tk = null ) use ( $in, $tpl ) {
			$tk = $tk ? $tk : $k;
			return array_key_exists( $k, $in ) ? $in[ $k ] : ( $tpl ? $tpl->$tk : null );
		};
		$name = mb_substr( sanitize_text_field( (string) $pick( 'name' ) ), 0, 160 );
		if ( '' === $name ) {
			return self::err( 'Enter the package name.' );
		}
		$type = sanitize_key( (string) ( $pick( 'package_type' ) ? $pick( 'package_type' ) : 'monthly' ) );
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return self::err( 'Choose the package type.' );
		}
		$price = NDWD_Accounts::paise( null === $pick( 'price', 'amount' ) ? 0 : $pick( 'price', 'amount' ) );
		if ( null === $price || $price < 0 ) {
			return self::err( 'Enter a price of 0 or more.' );
		}
		$period = sanitize_key( (string) $pick( 'billing_period' ) );
		if ( ! isset( NDWD_Accounts::PERIODS[ $period ] ) ) {
			$period = '';
		}
		$start = (string) $pick( 'start_date' );
		$start = '' !== $start ? NDWD_Helpers::sanitize_date( $start ) : NDWD_Helpers::today();
		if ( ! $start ) {
			return self::err( 'Enter a valid start date.' );
		}
		$end = (string) $pick( 'end_date' );
		if ( '' === $end && 'fixed' === $type && $tpl && (int) $tpl->duration_months > 0 ) {
			$end = ( new DateTimeImmutable( $start ) )->modify( '+' . (int) $tpl->duration_months . ' months' )->modify( '-1 day' )->format( 'Y-m-d' );
		}
		if ( '' !== $end && ! NDWD_Helpers::valid_date( $end ) ) {
			return self::err( 'Enter a valid end date.' );
		}
		if ( '' !== $end && $end < $start ) {
			return self::err( 'The end date cannot be before the start date.' );
		}
		if ( 'fixed' === $type && '' === $end ) {
			return self::err( 'A fixed-duration package needs an end date.' );
		}
		$items = self::clean_items( array_key_exists( 'items', $in ) ? $in['items'] : ( $tpl ? self::template_items( $tpl->id ) : array() ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$reset = sanitize_key( (string) ( $pick( 'reset_rule' ) ? $pick( 'reset_rule' ) : 'monthly' ) );
		$now   = NDWD_Helpers::now_mysql();
		$row   = array(
			'client_id'         => (int) $client_id,
			'project_id'        => $project_id,
			'package_id'        => $tpl ? (int) $tpl->id : null,
			'name'              => $name,
			'package_type'      => $type,
			'price'             => NDWD_Accounts::rupees( $price ),
			'billing_period'    => $period,
			'start_date'        => $start,
			'end_date'          => '' !== $end ? $end : null,
			'reset_rule'        => 'monthly' === $type && 'none' !== $reset ? 'monthly' : 'none',
			'reset_when_paused' => ! empty( $in['reset_when_paused'] ) ? 1 : 0,
			'status'            => 'active',
			'status_changed_at' => $now,
			'created_by'        => (int) $user_id,
			'created_at'        => $now,
			'updated_at'        => $now,
		);
		foreach ( array( 'scope', 'terms', 'remarks' ) as $k ) {
			$row[ $k ] = mb_substr( sanitize_textarea_field( (string) $pick( $k ) ), 0, 4000 );
		}
		if ( false === $wpdb->insert( self::t( 'client_packages' ), $row ) ) {
			return self::err( 'Could not save the package allocation.', 500, 'ndwd_db' );
		}
		$id = (int) $wpdb->insert_id;
		self::insert_items( $id, $items );
		self::flush();
		$a = self::allocation( $id );
		if ( $start <= NDWD_Helpers::today() && self::current_cycle( $a ) ) {
			self::ensure_cycle( $a, self::current_cycle( $a ) );
		}
		NDWD_Accounts::audit( 'client_package', $id, 'package_assigned', $user_id, array( 'client' => $client->name, 'package' => $name, 'template' => $tpl ? $tpl->name : '', 'price' => $row['price'], 'start' => $start, 'end' => $row['end_date'], 'services' => self::items_text( $id ) ) );
		return self::allocation_array( $a, array( 'price' => true, 'history' => true ) );
	}

	private static function insert_items( $alloc_id, $items, $from_sort = 0 ) {
		global $wpdb;
		foreach ( $items as $i => $it ) {
			$svc = self::service( $it['service_id'] );
			$wpdb->insert( self::t( 'client_package_items' ), array(
				'client_package_id' => (int) $alloc_id,
				'service_id'        => $it['service_id'],
				'service_name'      => $svc->name,
				'unit'              => $svc->unit,
				'unit_plural'       => $svc->unit_plural,
				'quantity'          => $it['quantity'],
				'notes'             => $it['notes'],
				'sort'              => $from_sort + $i,
			) );
		}
	}

	private static function items_text( $alloc_id ) {
		return implode( ', ', array_map( function ( $it ) {
			return $it->quantity . ' ' . $it->service_name;
		}, self::items( $alloc_id, true ) ) );
	}

	/**
	 * Authorised changes to a client's allocation: price, dates, status, scope and the
	 * quantities. The current cycle takes the new quantities; earlier cycles keep theirs.
	 *
	 * @return array|WP_Error
	 */
	public static function update_allocation( $id, $in, $user_id ) {
		global $wpdb;
		$a = self::allocation( $id );
		if ( ! $a ) {
			return self::err( 'Package allocation not found.', 404, 'ndwd_not_found' );
		}
		$row     = array();
		$changes = array();
		if ( array_key_exists( 'project_id', $in ) ) {
			$pid = self::check_project( $in['project_id'], $a->client_id );
			if ( is_wp_error( $pid ) ) {
				return $pid;
			}
			if ( (int) $pid !== (int) $a->project_id ) {
				// Tasks already using the package must stay valid: moving it may not strand them.
				$other = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'tasks' ) . ' WHERE client_package_id = %d AND is_archived = 0 AND ( project_id IS NULL OR project_id <> %d )', $id, (int) $pid ) );
				if ( $pid && $other ) {
					return self::err( $other . ' task(s) using this package belong to another project. Move them first, or keep the package where it is.' );
				}
				$row['project_id'] = $pid;
			}
		}
		if ( isset( $in['name'] ) ) {
			$row['name'] = mb_substr( sanitize_text_field( (string) $in['name'] ), 0, 160 );
			if ( '' === $row['name'] ) {
				return self::err( 'Enter the package name.' );
			}
		}
		if ( isset( $in['price'] ) ) {
			$p = NDWD_Accounts::paise( $in['price'] );
			if ( null === $p || $p < 0 ) {
				return self::err( 'Enter a price of 0 or more.' );
			}
			$row['price'] = NDWD_Accounts::rupees( $p );
		}
		if ( isset( $in['billing_period'] ) ) {
			$row['billing_period'] = isset( NDWD_Accounts::PERIODS[ $in['billing_period'] ] ) ? $in['billing_period'] : '';
		}
		if ( isset( $in['start_date'] ) && $in['start_date'] !== $a->start_date ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'package_usage' ) . ' WHERE client_package_id = %d', $id ) );
			if ( $used ) {
				return self::err( 'The start date cannot change once the package has usage, because it decides the billing cycles. Change the end date, or assign a new package.' );
			}
			$d = NDWD_Helpers::sanitize_date( (string) $in['start_date'] );
			if ( ! $d ) {
				return self::err( 'Enter a valid start date.' );
			}
			$row['start_date'] = $d;
		}
		if ( array_key_exists( 'end_date', $in ) ) {
			$e = (string) $in['end_date'];
			if ( '' !== $e && ! NDWD_Helpers::valid_date( $e ) ) {
				return self::err( 'Enter a valid end date.' );
			}
			$row['end_date'] = '' !== $e ? $e : null;
		}
		$start = isset( $row['start_date'] ) ? $row['start_date'] : $a->start_date;
		$end   = array_key_exists( 'end_date', $row ) ? $row['end_date'] : $a->end_date;
		if ( $end && $end < $start ) {
			return self::err( 'The end date cannot be before the start date.' );
		}
		if ( 'fixed' === $a->package_type && ! $end ) {
			return self::err( 'A fixed-duration package needs an end date.' );
		}
		if ( isset( $in['reset_rule'] ) && 'monthly' === $a->package_type ) {
			$row['reset_rule'] = 'none' === $in['reset_rule'] ? 'none' : 'monthly';
		}
		if ( isset( $in['reset_when_paused'] ) ) {
			$row['reset_when_paused'] = $in['reset_when_paused'] ? 1 : 0;
		}
		foreach ( array( 'scope', 'terms', 'remarks' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$row[ $k ] = mb_substr( sanitize_textarea_field( (string) $in[ $k ] ), 0, 4000 );
			}
		}
		if ( isset( $in['status'] ) && $in['status'] !== $a->status ) {
			if ( ! in_array( $in['status'], array( 'active', 'paused', 'inactive', 'cancelled', 'archived' ), true ) ) {
				return self::err( 'Status must be Active, Paused, Deactivated, Cancelled or Archived.' );
			}
			$row['status']            = $in['status'];
			$row['status_changed_at'] = NDWD_Helpers::now_mysql();
		}
		$items = null;
		if ( isset( $in['items'] ) ) {
			$items = self::clean_items( $in['items'] );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			foreach ( $items as $it ) {
				if ( $it['id'] && ! self::item( $id, $it['id'] ) ) {
					return self::err( 'Unknown service line.' );
				}
			}
		}
		foreach ( $row as $k => $v ) {
			if ( (string) $a->$k !== (string) $v ) {
				$changes[ $k ] = array( 'from' => $a->$k, 'to' => $v );
			}
		}
		$before = self::items_text( $id );
		if ( $changes ) {
			$row['updated_at'] = NDWD_Helpers::now_mysql();
			$wpdb->update( self::t( 'client_packages' ), $row, array( 'id' => $id ) );
		}
		if ( null !== $items ) {
			$keep = array();
			$new  = array();
			foreach ( $items as $it ) {
				if ( $it['id'] ) {
					$wpdb->update( self::t( 'client_package_items' ), array( 'quantity' => $it['quantity'], 'notes' => $it['notes'], 'is_active' => 1 ), array( 'id' => $it['id'] ) );
					$keep[] = $it['id'];
				} else {
					$existing = null;
					foreach ( self::items( $id ) as $old ) {
						if ( (int) $old->service_id === $it['service_id'] ) {
							$existing = $old;
						}
					}
					if ( $existing ) {
						$wpdb->update( self::t( 'client_package_items' ), array( 'quantity' => $it['quantity'], 'notes' => $it['notes'], 'is_active' => 1 ), array( 'id' => $existing->id ) );
						$keep[] = (int) $existing->id;
					} else {
						$new[] = $it;
					}
				}
			}
			// Removed services are deactivated, not deleted: their usage history stays readable.
			foreach ( self::items( $id ) as $old ) {
				if ( ! in_array( (int) $old->id, $keep, true ) ) {
					$wpdb->update( self::t( 'client_package_items' ), array( 'is_active' => 0 ), array( 'id' => $old->id ) );
				}
			}
			self::insert_items( $id, $new, 100 );
		}
		self::flush();
		$a     = self::allocation( $id );
		$after = self::items_text( $id );
		if ( $before !== $after ) {
			$changes['services'] = array( 'from' => $before, 'to' => $after );
			$cycle = self::current_cycle( $a );
			if ( $cycle && $cycle[0] <= NDWD_Helpers::today() ) {
				$crow = self::ensure_cycle( $a, $cycle );
				$snap = array();
				foreach ( self::items( $id, true ) as $it ) {
					$snap[ (int) $it->id ] = (int) $it->quantity;
				}
				$wpdb->update( self::t( 'package_cycles' ), array( 'allocated' => wp_json_encode( (object) $snap ) ), array( 'id' => $crow->id ) );
			}
		}
		if ( $changes ) {
			NDWD_Accounts::audit( 'client_package', $id, 'package_adjusted', $user_id, $changes );
		}
		return self::allocation_array( $a, array( 'price' => true, 'history' => true, 'entries' => true ) );
	}

	/** Adds (or removes, with a negative quantity) units in the current cycle, with a note. */
	public static function adjust_usage( $id, $in, $user_id ) {
		global $wpdb;
		$a = self::allocation( $id );
		if ( ! $a ) {
			return self::err( 'Package allocation not found.', 404, 'ndwd_not_found' );
		}
		$item = self::item( $id, absint( isset( $in['item_id'] ) ? $in['item_id'] : 0 ) );
		if ( ! $item ) {
			return self::err( 'Choose the service to adjust.' );
		}
		$q = isset( $in['quantity'] ) ? $in['quantity'] : 0;
		if ( ! is_numeric( $q ) || (int) $q != $q || 0 === (int) $q || abs( (int) $q ) > 1000 ) { // phpcs:ignore
			return self::err( 'Enter a whole number of units to add (or a negative number to remove), not 0.' );
		}
		$note = mb_substr( sanitize_textarea_field( isset( $in['note'] ) ? (string) $in['note'] : '' ), 0, 1000 );
		if ( '' === trim( $note ) ) {
			return self::err( 'Give the reason for the adjustment.' );
		}
		$cycle = self::current_cycle( $a );
		if ( ! $cycle ) {
			return self::err( 'This package has no current period to adjust.' );
		}
		self::ensure_cycle( $a, $cycle );
		$now = NDWD_Helpers::now_mysql();
		$wpdb->insert( self::t( 'package_usage' ), array( 'client_package_id' => (int) $id, 'item_id' => (int) $item->id, 'task_id' => null, 'live_task_id' => null, 'cycle_start' => $cycle[0], 'quantity' => (int) $q, 'state' => 'adjustment', 'approved_by' => (int) $user_id, 'note' => $note, 'created_by' => (int) $user_id, 'created_at' => $now, 'updated_at' => $now ) );
		NDWD_Accounts::audit( 'client_package', $id, 'usage_adjusted', $user_id, array( 'service' => $item->service_name, 'quantity' => (int) $q, 'cycle' => $cycle[0], 'note' => $note ) );
		return self::allocation_array( $a, array( 'price' => true, 'history' => true, 'entries' => true ) );
	}

	/**
	 * Deletes an allocation only when it is an unused draft: nothing was ever reserved, consumed
	 * or adjusted on it, and no task, invoice or carry-forward refers to it.
	 *
	 * @return array|WP_Error With $dry, what blocks the delete.
	 */
	public static function delete_allocation( $id, $user_id, $dry = false ) {
		global $wpdb;
		$a = self::allocation( $id );
		if ( ! $a ) {
			return self::err( 'Package allocation not found.', 404, 'ndwd_not_found' );
		}
		$blocks = array();
		foreach ( array(
			'usage record(s)'        => array( 'package_usage', 'client_package_id' ),
			'linked task(s)'         => array( 'tasks', 'client_package_id' ),
			'invoice line(s)'        => array( 'invoice_items', 'client_package_id' ),
			'invoice(s)'             => array( 'invoices', 'client_package_id' ),
			'carry-forward decision(s)' => array( 'package_carryovers', 'client_package_id' ),
		) as $label => $src ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( $src[0] ) . ' WHERE ' . $src[1] . ' = %d', $id ) ); // phpcs:ignore
			if ( $n ) {
				$blocks[] = $n . ' ' . $label;
			}
		}
		if ( $dry ) {
			return array( 'name' => $a->name, 'links' => (object) array_combine( $blocks, array_map( '__return_empty_string', $blocks ) ), 'blocked' => (bool) $blocks, 'notes' => array( 'Only an unused draft can be deleted.' ) );
		}
		if ( $blocks ) {
			return self::err( 'This package has history (' . implode( ', ', $blocks ) . '), so it cannot be deleted. Cancel or archive it instead.', 409, 'ndwd_has_history' );
		}
		$wpdb->delete( self::t( 'client_package_items' ), array( 'client_package_id' => $id ) );
		$wpdb->delete( self::t( 'package_cycles' ), array( 'client_package_id' => $id ) );
		$wpdb->delete( self::t( 'client_packages' ), array( 'id' => $id ) );
		NDWD_Accounts::audit( 'client_package', $id, 'package_deleted', $user_id, array( 'package' => $a->name ) );
		self::flush();
		return array( 'deleted' => true );
	}

	/* ------------------------------------------------------------------ *
	 * Carry-forward (manual)
	 * ------------------------------------------------------------------ */

	/** The finished cycle whose unused units can be reviewed now, or null. */
	private static function carry_cycle( $a, $cycle = null ) {
		if ( ! self::resets( $a ) || 'active' !== $a->status ) {
			return null;
		}
		$cycle = $cycle ? $cycle : self::current_cycle( $a );
		if ( ! $cycle || $cycle[0] <= $a->start_date ) {
			return null;
		}
		$prev_day = ( new DateTimeImmutable( $cycle[0] ) )->modify( '-1 day' )->format( 'Y-m-d' );
		$from     = self::cycle_for( $a, $prev_day );
		return $from ? array( 'from' => $from, 'to' => $cycle ) : null;
	}

	/** Review of a finished cycle: unused units per service and any decision already taken. */
	public static function carry_review( $a, $cycle = null ) {
		global $wpdb;
		$c = self::carry_cycle( $a, $cycle );
		if ( ! $c ) {
			return null;
		}
		$done = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'package_carryovers' ) . ' WHERE client_package_id = %d AND from_cycle = %s', $a->id, $c['from'][0] ) ) as $d ) {
			$done[ (int) $d->item_id ] = $d;
		}
		$items = array();
		foreach ( self::items( $a->id, true ) as $it ) {
			$b      = self::balance( $a, $it, $c['from'] );
			$unused = max( 0, (int) $b['remaining'] );
			if ( ! $unused && ! isset( $done[ (int) $it->id ] ) ) {
				continue;
			}
			$d       = isset( $done[ (int) $it->id ] ) ? $done[ (int) $it->id ] : null;
			$items[] = array(
				'item_id'      => (int) $it->id,
				'service_name' => $it->service_name,
				'unit_plural'  => $it->unit_plural,
				'unused'       => $d ? (int) $d->unused : $unused,
				// Never more than one cycle's own quantity, so units cannot pile up month after month.
				'max'          => min( $unused, max( 0, (int) $b['allocated'] ) ),
				'decided'      => (bool) $d,
				'accepted'     => $d ? (int) $d->accepted : 0,
				'rejected'     => $d ? (int) $d->rejected : 0,
			);
		}
		return array( 'from' => self::cycle_label( $a, $c['from'] ), 'to' => self::cycle_label( $a, $c['to'] ), 'from_cycle' => $c['from'][0], 'to_cycle' => $c['to'][0], 'items' => $items );
	}

	/** Light flag for lists: is a decision waiting? */
	private static function carry_state( $a, $cycle ) {
		$r = self::resets( $a ) && 'active' === $a->status ? self::carry_review( $a, $cycle ) : null;
		if ( ! $r ) {
			return null;
		}
		return array( 'pending' => count( array_filter( $r['items'], function ( $i ) {
			return ! $i['decided'];
		} ) ), 'from' => $r['from'], 'to' => $r['to'] );
	}

	/**
	 * Records the decision for a finished cycle: decisions = [{item_id, accept}]. The unique key on
	 * (item_id, from_cycle) stops a second decision even from two requests at the same moment.
	 *
	 * @return array|WP_Error Allocation.
	 */
	public static function carry_forward( $id, $in, $user_id ) {
		global $wpdb;
		$a = self::allocation( $id );
		if ( ! $a ) {
			return self::err( 'Package allocation not found.', 404, 'ndwd_not_found' );
		}
		$review = self::carry_review( $a );
		if ( ! $review ) {
			return self::err( 'Only an active package that resets monthly, with a finished cycle, can carry units forward.' );
		}
		if ( isset( $in['from_cycle'] ) && $in['from_cycle'] !== $review['from_cycle'] ) {
			return self::err( 'Only the cycle that just finished (' . $review['from'] . ') can be carried forward.' );
		}
		$note = mb_substr( sanitize_textarea_field( isset( $in['note'] ) ? (string) $in['note'] : '' ), 0, 500 );
		$want = array();
		foreach ( (array) ( isset( $in['decisions'] ) ? $in['decisions'] : array() ) as $d ) {
			$want[ absint( isset( $d['item_id'] ) ? $d['item_id'] : 0 ) ] = isset( $d['accept'] ) ? (int) $d['accept'] : 0;
		}
		$now   = NDWD_Helpers::now_mysql();
		$saved = 0;
		foreach ( $review['items'] as $it ) {
			if ( ! array_key_exists( $it['item_id'], $want ) ) {
				continue;
			}
			if ( $it['decided'] ) {
				return self::err( $it['service_name'] . ': this cycle was already decided (' . $it['accepted'] . ' carried, ' . $it['rejected'] . ' archived).', 409, 'ndwd_already_decided' );
			}
			$accept = $want[ $it['item_id'] ];
			if ( $accept < 0 || $accept > $it['max'] ) {
				return self::err( $it['service_name'] . ': carry between 0 and ' . $it['max'] . ' ' . $it['unit_plural'] . '.' );
			}
			$ins = $wpdb->query( $wpdb->prepare(
				'INSERT IGNORE INTO ' . self::t( 'package_carryovers' ) . ' (client_package_id, item_id, from_cycle, to_cycle, unused, accepted, rejected, note, decided_by, decided_at) VALUES (%d, %d, %s, %s, %d, %d, %d, %s, %d, %s)',
				$id, $it['item_id'], $review['from_cycle'], $review['to_cycle'], $it['unused'], $accept, $it['unused'] - $accept, $note, $user_id, $now
			) );
			if ( ! $ins ) {
				return self::err( $it['service_name'] . ': this cycle was already decided.', 409, 'ndwd_already_decided' );
			}
			$saved++;
			if ( $accept ) {
				$c = self::cycle_for( $a, $review['to_cycle'] );
				self::ensure_cycle( $a, $c );
				$wpdb->insert( self::t( 'package_usage' ), array( 'client_package_id' => (int) $id, 'item_id' => $it['item_id'], 'task_id' => null, 'live_task_id' => null, 'cycle_start' => $review['to_cycle'], 'quantity' => $accept, 'state' => 'adjustment', 'approved_by' => (int) $user_id, 'note' => 'Carried forward from ' . $review['from'] . ( '' !== $note ? ': ' . $note : '' ), 'created_by' => (int) $user_id, 'created_at' => $now, 'updated_at' => $now ) );
			}
			NDWD_Accounts::audit( 'client_package', $id, 'units_carried', $user_id, array( 'service' => $it['service_name'], 'from' => $review['from'], 'to' => $review['to'], 'unused' => $it['unused'], 'accepted' => $accept, 'rejected' => $it['unused'] - $accept ) );
		}
		if ( ! $saved ) {
			return self::err( 'Choose what to do with at least one service.' );
		}
		self::flush();
		return self::allocation_array( self::allocation( $id ), array( 'price' => true, 'history' => true, 'entries' => true ) );
	}

	/** Carry-forward history of an allocation, newest first. */
	public static function carry_history( $id ) {
		global $wpdb;
		return array_map( function ( $r ) {
			return array( 'from_cycle' => $r->from_cycle, 'to_cycle' => $r->to_cycle, 'service_name' => $r->service_name, 'unused' => (int) $r->unused, 'accepted' => (int) $r->accepted, 'rejected' => (int) $r->rejected, 'note' => (string) $r->note, 'by' => NDWD_Helpers::user_name( $r->decided_by ), 'at' => $r->decided_at );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, i.service_name FROM ' . self::t( 'package_carryovers' ) . ' c JOIN ' . self::t( 'client_package_items' ) . ' i ON i.id = c.item_id WHERE c.client_package_id = %d ORDER BY c.from_cycle DESC, c.id', $id ) ) );
	}

	/** Daily: creates the cycle row of every running monthly allocation (the monthly reset). */
	public static function roll_cycles() {
		global $wpdb;
		$today = NDWD_Helpers::today();
		$made  = 0;
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'client_packages' ) . " WHERE status = 'active' AND package_type = 'monthly' AND reset_rule = 'monthly' AND start_date <= %s AND ( end_date IS NULL OR end_date >= %s )", $today, $today ) );
		foreach ( $rows as $a ) {
			$cycle = self::cycle_for( $a, $today );
			if ( ! $cycle ) {
				continue;
			}
			$had = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'package_cycles' ) . ' WHERE client_package_id = %d AND cycle_start = %s', $a->id, $cycle[0] ) );
			if ( ! $had ) {
				self::ensure_cycle( $a, $cycle );
				$made++;
			}
		}
		return $made;
	}

	/* ------------------------------------------------------------------ *
	 * Tasks
	 * ------------------------------------------------------------------ */

	private static function live( $task_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'package_usage' ) . ' WHERE live_task_id = %d', $task_id ) );
	}

	/**
	 * Normalises the package fields of a task before it is saved.
	 *
	 * $in may contain client_package_id, package_item_id, package_scope, package_note,
	 * package_override and package_override_reason. Unset keys keep the task's values.
	 * Returns the four task columns plus '_approve' (the over-limit approval reason, if any).
	 *
	 * @return array|WP_Error
	 */
	public static function validate_task_link( $in, $client_id, $sched, $title, $user_id, $task = null, $project_id = null ) {
		$has      = function ( $k ) use ( $in ) {
			return array_key_exists( $k, $in );
		};
		$alloc_id = $has( 'client_package_id' ) ? absint( $in['client_package_id'] ) : ( $task ? (int) $task->client_package_id : 0 );
		$scope    = $has( 'package_scope' ) ? sanitize_key( (string) $in['package_scope'] ) : ( $task ? (string) $task->package_scope : '' );
		$item_id  = $has( 'package_item_id' ) ? absint( $in['package_item_id'] ) : ( $task ? (int) $task->package_item_id : 0 );
		$note     = $has( 'package_note' ) ? mb_substr( sanitize_textarea_field( (string) $in['package_note'] ), 0, 1000 ) : ( $task ? (string) $task->package_note : '' );
		if ( '' !== $scope && ! isset( self::SCOPES[ $scope ] ) ) {
			return self::err( 'Unknown package scope.' );
		}
		if ( ! $alloc_id ) {
			// No package: the task can still be marked as additional (out-of-package) work.
			return array( 'client_package_id' => null, 'package_item_id' => null, 'package_scope' => 'additional' === $scope ? 'additional' : '', 'package_note' => $note, '_approve' => '' );
		}
		$a = self::allocation( $alloc_id );
		if ( ! $a ) {
			return self::err( 'Unknown client package.' );
		}
		if ( ! $client_id || (int) $a->client_id !== (int) $client_id ) {
			return self::err( 'The selected package belongs to another client. Choose a package of this task\'s client, or no package.' );
		}
		$same_alloc = $task && (int) $task->client_package_id === (int) $alloc_id;
		$for_project = null !== $project_id ? (int) $project_id : ( $task ? (int) $task->project_id : 0 );
		if ( ! empty( $a->project_id ) && (int) $a->project_id !== $for_project ) {
			$owner = self::project_row( $a->project_id );
			return self::err( '"' . $a->name . '" belongs to project "' . ( $owner ? $owner->name : '#' . $a->project_id ) . '". Choose a package of this task\'s project, or mark the task Additional scope.' );
		}
		if ( ! $same_alloc && 'active' !== $a->status ) {
			return self::err( '"' . $a->name . '" is ' . strtolower( self::STATUSES[ $a->status ] ) . ', so new tasks cannot use it.' );
		}
		$cycle = self::cycle_for( $a, $sched );
		if ( ! $cycle && ! $same_alloc ) {
			return self::err( 'The task date (' . date_i18n( 'j M Y', strtotime( $sched ) ) . ') is outside the period of "' . $a->name . '" (' . date_i18n( 'j M Y', strtotime( $a->start_date ) ) . ( $a->end_date ? ' – ' . date_i18n( 'j M Y', strtotime( $a->end_date ) ) : ' onwards' ) . ').' );
		}
		if ( '' === $scope ) {
			$scope = $item_id ? 'included' : 'retainer';
		}
		if ( 'included' !== $scope ) {
			return array( 'client_package_id' => (int) $alloc_id, 'package_item_id' => null, 'package_scope' => $scope, 'package_note' => $note, '_approve' => '' );
		}
		if ( ! $item_id ) {
			$item_id = (int) self::detect_item( $title, self::items( $alloc_id, true ) );
		}
		$item = $item_id ? self::item( $alloc_id, $item_id ) : null;
		if ( ! $item ) {
			return self::err( 'Choose which service of "' . $a->name . '" this task uses, or mark it as Retainer or Additional scope.' );
		}
		$same_item = $task && $same_alloc && (int) $task->package_item_id === (int) $item->id;
		if ( ! (int) $item->is_active && ! $same_item ) {
			return self::err( '"' . $item->service_name . '" is no longer part of this package.' );
		}
		$approve = '';
		if ( ! empty( $in['package_override'] ) ) {
			if ( ! self::can_manage( $user_id ) ) {
				return self::err( 'Only Accounts users can approve work beyond the package limit. Mark the task as Additional scope instead.', 403, 'ndwd_forbidden' );
			}
			$approve = mb_substr( sanitize_textarea_field( isset( $in['package_override_reason'] ) ? (string) $in['package_override_reason'] : '' ), 0, 500 );
			if ( '' === trim( $approve ) ) {
				return self::err( 'Give the reason for approving work beyond the package limit.' );
			}
		}
		// A new unit is needed unless the task already holds one of this service in this cycle.
		$status = $task ? $task->status : 'pending';
		if ( 'rejected' !== $status && $cycle ) {
			$live  = $task ? self::live( $task->id ) : null;
			$needs = ! ( $live && (int) $live->item_id === (int) $item->id && (int) $live->client_package_id === (int) $alloc_id && ( 'consumed' === $live->state || $live->cycle_start === $cycle[0] ) );
			if ( $needs ) {
				$b = self::balance( $a, $item, $cycle );
				if ( $b['remaining'] < 1 && '' === $approve ) {
					return self::err(
						'"' . $a->name . '" has no ' . $item->unit_plural . ' left for ' . $item->service_name . ' in ' . self::cycle_label( $a, $cycle ) . ' (' . ( $b['allocated'] + $b['adjusted'] ) . ' included, ' . $b['consumed'] . ' used, ' . $b['reserved'] . ' reserved). Mark the task as Additional scope, or an Accounts user can approve the extra work.',
						409,
						'ndwd_package_limit',
						array( 'balance' => $b )
					);
				}
			}
		}
		return array( 'client_package_id' => (int) $alloc_id, 'package_item_id' => (int) $item->id, 'package_scope' => 'included', 'package_note' => $note, '_approve' => $approve );
	}

	/**
	 * Brings a task's usage row in line with the task (idempotent). Called after every save,
	 * status change, handoff and archive.
	 *   - not linked to an included service, rejected or archived: its live row is released;
	 *   - open: one reserved unit; completed: consumed; reopened: back to reserved;
	 *   - moved to another service or package: the old unit is released, a new one taken.
	 * A unit taken when nothing remains is flagged over_limit (approved_by = the approver).
	 */
	public static function sync_task( $task_id, $user_id, $approve = '' ) {
		global $wpdb;
		$t    = self::t( 'package_usage' );
		$task = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'tasks' ) . ' WHERE id = %d', $task_id ) );
		$live = self::live( $task_id );
		$want = null;
		if ( $task && ! (int) $task->is_archived && 'rejected' !== $task->status && 'included' === $task->package_scope && $task->client_package_id && $task->package_item_id ) {
			$a    = self::allocation( $task->client_package_id );
			$item = $a ? self::item( $a->id, $task->package_item_id ) : null;
			if ( $a && $item ) {
				$cycle = self::cycle_for( $a, $task->scheduled_date );
				if ( ! $cycle ) {
					$cycle = $live && (int) $live->client_package_id === (int) $a->id ? self::cycle_for( $a, $live->cycle_start ) : self::current_cycle( $a );
				}
				if ( $cycle ) {
					$want = array( 'a' => $a, 'item' => $item, 'cycle' => $cycle, 'state' => 'completed' === $task->status ? 'consumed' : 'reserved' );
				}
			}
		}
		$now = NDWD_Helpers::now_mysql();
		if ( $live && ( ! $want || (int) $live->client_package_id !== (int) $want['a']->id || (int) $live->item_id !== (int) $want['item']->id ) ) {
			if ( ! $task ) {
				$reason = 'task deleted';
			} elseif ( (int) $task->is_archived ) {
				$reason = 'task archived';
			} elseif ( 'rejected' === $task->status ) {
				$reason = 'task rejected';
			} else {
				$reason = $want ? 'task moved to another package service' : 'task no longer uses an included service';
			}
			$done = $wpdb->query( $wpdb->prepare( "UPDATE $t SET state = 'released', live_task_id = NULL, released_at = %s, release_reason = %s, updated_at = %s WHERE id = %d AND live_task_id IS NOT NULL", $now, $reason, $now, $live->id ) ); // phpcs:ignore
			if ( $done ) {
				self::log( $user_id, $task_id, $live, 'released', $reason );
			}
			$live = null;
		}
		if ( ! $want ) {
			return;
		}
		if ( $live ) {
			$upd = array();
			if ( $live->state !== $want['state'] ) {
				$upd['state']       = $want['state'];
				$upd['consumed_at'] = 'consumed' === $want['state'] ? $now : null;
			}
			// While reserved, the unit follows the task's date into another cycle.
			if ( 'reserved' === $want['state'] && $live->cycle_start !== $want['cycle'][0] ) {
				self::ensure_cycle( $want['a'], $want['cycle'] );
				$upd['cycle_start'] = $want['cycle'][0];
			}
			if ( $upd ) {
				$upd['updated_at'] = $now;
				$wpdb->update( $t, $upd, array( 'id' => $live->id ) );
				self::log( $user_id, $task_id, $live, isset( $upd['state'] ) ? $upd['state'] : 'moved to ' . $upd['cycle_start'], '' );
			}
			return;
		}
		self::ensure_cycle( $want['a'], $want['cycle'] );
		$b    = self::balance( $want['a'], $want['item'], $want['cycle'] );
		$over = $b['remaining'] < 1;
		$ok   = $wpdb->insert( $t, array(
			'client_package_id' => (int) $want['a']->id,
			'item_id'           => (int) $want['item']->id,
			'task_id'           => (int) $task_id,
			'live_task_id'      => (int) $task_id,
			'cycle_start'       => $want['cycle'][0],
			'quantity'          => 1,
			'state'             => $want['state'],
			'over_limit'        => $over ? 1 : 0,
			'approved_by'       => $over && '' !== $approve ? (int) $user_id : null,
			'note'              => $over ? ( '' !== $approve ? $approve : 'Taken beyond the package limit when the task was reopened or re-dated.' ) : '',
			'created_by'        => (int) $user_id,
			'created_at'        => $now,
			'updated_at'        => $now,
			'consumed_at'       => 'consumed' === $want['state'] ? $now : null,
		) );
		if ( $ok ) {
			$row = self::live( $task_id );
			self::log( $user_id, $task_id, $row, $want['state'], $over ? 'beyond the package limit' . ( '' !== $approve ? ' (approved: ' . $approve . ')' : '' ) : '' );
			if ( $over ) {
				NDWD_Accounts::audit( 'client_package', $want['a']->id, 'over_limit_usage', $user_id, array( 'task_id' => (int) $task_id, 'service' => $want['item']->service_name, 'approved' => $approve ) );
			}
		}
	}

	private static function log( $user_id, $task_id, $row, $what, $note ) {
		$a  = $row ? self::allocation( $row->client_package_id ) : null;
		$it = $row && $a ? self::item( $a->id, $row->item_id ) : null;
		NDWD_Activity::log( $user_id, $task_id, 'package_usage', array_filter( array(
			'package' => $a ? $a->name : '',
			'service' => $it ? $it->service_name : '',
			'usage'   => $what,
			'note'    => $note,
		) ), false );
	}

	/** Package fields of a task for the API (no prices). */
	public static function task_summary( $t ) {
		if ( empty( $t->client_package_id ) && empty( $t->package_scope ) ) {
			return null;
		}
		$a    = $t->client_package_id ? self::allocation( $t->client_package_id ) : null;
		$item = $a && $t->package_item_id ? self::item( $a->id, $t->package_item_id ) : null;
		$live = $item ? self::live( $t->id ) : null;
		return array(
			'client_package_id' => $a ? (int) $a->id : null,
			'package_name'      => $a ? $a->name : '',
			'item_id'           => $item ? (int) $item->id : null,
			'service_name'      => $item ? $item->service_name : '',
			'unit'              => $item ? $item->unit : '',
			'scope'             => (string) $t->package_scope,
			'scope_label'       => isset( self::SCOPES[ $t->package_scope ] ) ? ( $a || 'additional' !== $t->package_scope ? self::SCOPES[ $t->package_scope ] : 'Additional / out of package' ) : '',
			'note'              => (string) $t->package_note,
			'usage'             => $live ? $live->state : ( $item ? 'released' : '' ),
			'over_limit'        => $live ? (bool) $live->over_limit : false,
		);
	}

	/** Remark text recording a task's package scope and reason. */
	public static function remark_text( $cols ) {
		if ( empty( $cols['package_scope'] ) && empty( $cols['client_package_id'] ) ) {
			return '';
		}
		$a    = $cols['client_package_id'] ? self::allocation( $cols['client_package_id'] ) : null;
		$item = $a && $cols['package_item_id'] ? self::item( $a->id, $cols['package_item_id'] ) : null;
		$head = ( $a ? $a->name . ' — ' : 'No package — ' ) . ( isset( self::SCOPES[ $cols['package_scope'] ] ) ? self::SCOPES[ $cols['package_scope'] ] : '' ) . ( $item ? ': ' . $item->service_name : '' );
		return 'Package scope: ' . $head . ( '' !== trim( (string) $cols['package_note'] ) ? "\n" . $cols['package_note'] : '' ) . ( ! empty( $cols['_approve'] ) ? "\nExtra work approved beyond the package limit: " . $cols['_approve'] : '' );
	}

	/* ------------------------------------------------------------------ *
	 * REST (ndwd/v1)
	 * ------------------------------------------------------------------ */

	public static function routes() {
		$c      = __CLASS__;
		$member = array( 'NDWD_REST', 'member' );
		$acct   = array( 'NDWD_Accounts_REST', 'allowed' );
		$reg    = function ( $path, $methods, $cb, $perm ) use ( $c ) {
			register_rest_route( NDWD_REST::NS, $path, array( 'methods' => $methods, 'callback' => array( $c, $cb ), 'permission_callback' => $perm ) );
		};
		$reg( '/package-services', 'GET', 'rest_services', $member );
		$reg( '/package-services', 'POST', 'rest_save_service', $acct );
		$reg( '/package-services/(?P<id>\d+)', 'PUT', 'rest_save_service', $acct );
		$reg( '/clients/(?P<id>\d+)/packages', 'GET', 'rest_client_packages', $member );
		$reg( '/clients/(?P<id>\d+)/packages', 'POST', 'rest_assign', $acct );
		$reg( '/client-packages/(?P<id>\d+)', 'GET', 'rest_allocation', $member );
		$reg( '/client-packages/(?P<id>\d+)', 'PUT', 'rest_update', $acct );
		$reg( '/client-packages/(?P<id>\d+)/adjust', 'POST', 'rest_adjust', $acct );
		$reg( '/client-packages/(?P<id>\d+)', 'DELETE', 'rest_delete', $acct );
		$reg( '/client-packages/(?P<id>\d+)/carry-forward', 'GET', 'rest_carry_review', $acct );
		$reg( '/client-packages/(?P<id>\d+)/carry-forward', 'POST', 'rest_carry_forward', $acct );
		$reg( '/packages/utilization', 'GET', 'rest_utilization', $acct );
	}

	private static function body( WP_REST_Request $r ) {
		$b = $r->get_json_params();
		return is_array( $b ) ? $b : $r->get_body_params();
	}

	private static function out( $d ) {
		return is_wp_error( $d ) ? $d : rest_ensure_response( $d );
	}

	public static function rest_services() {
		return self::out( array( 'services' => self::services(), 'scopes' => self::SCOPES, 'types' => self::TYPES, 'reset_rules' => self::RESET_RULES, 'statuses' => self::STATUSES ) );
	}

	public static function rest_save_service( WP_REST_Request $r ) {
		return self::out( self::save_service( self::body( $r ), get_current_user_id(), isset( $r['id'] ) ? (int) $r['id'] : 0 ) );
	}

	public static function rest_client_packages( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$cid = (int) $r['id'];
		if ( ! NDWD_Clients::can_view( $cid, $uid ) ) {
			return self::err( 'Client not found or you do not have access.', 404, 'ndwd_not_found' );
		}
		$acct = self::can_manage( $uid );
		return self::out( array(
			'packages'   => self::for_client( $cid, array( 'price' => $acct, 'history' => (bool) $r->get_param( 'history' ) ) ),
			'additional' => $r->get_param( 'history' ) ? self::unpackaged_additional( $cid ) : array(),
			'can_manage' => $acct,
		) );
	}

	public static function rest_allocation( WP_REST_Request $r ) {
		$uid = get_current_user_id();
		$a   = self::allocation( (int) $r['id'] );
		if ( ! $a || ! NDWD_Clients::can_view( $a->client_id, $uid ) ) {
			return self::err( 'Package not found or you do not have access.', 404, 'ndwd_not_found' );
		}
		$acct = self::can_manage( $uid );
		return self::out( self::allocation_array( $a, array( 'price' => $acct, 'history' => true, 'entries' => $acct ) ) );
	}

	public static function rest_assign( WP_REST_Request $r ) {
		return self::out( self::assign( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_update( WP_REST_Request $r ) {
		return self::out( self::update_allocation( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_adjust( WP_REST_Request $r ) {
		return self::out( self::adjust_usage( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_delete( WP_REST_Request $r ) {
		return self::out( self::delete_allocation( (int) $r['id'], get_current_user_id(), (bool) $r->get_param( 'dry' ) ) );
	}

	public static function rest_carry_review( WP_REST_Request $r ) {
		$a = self::allocation( (int) $r['id'] );
		if ( ! $a ) {
			return self::err( 'Package allocation not found.', 404, 'ndwd_not_found' );
		}
		return self::out( array( 'review' => self::carry_review( $a ), 'history' => self::carry_history( $a->id ) ) );
	}

	public static function rest_carry_forward( WP_REST_Request $r ) {
		return self::out( self::carry_forward( (int) $r['id'], self::body( $r ), get_current_user_id() ) );
	}

	public static function rest_utilization( WP_REST_Request $r ) {
		return self::out( self::utilization( array( 'status' => sanitize_key( (string) $r->get_param( 'status' ) ) ) ) );
	}
}
