<?php
/**
 * Client work requests, deliverables, approvals and comments.
 *
 * Package usage goes through the existing ledger (package_usage), never a second counter:
 *   submit  → one reserved "hold" row (no task yet), when a unit is available;
 *   approve → the hold is released and the new task takes its reserved unit through
 *             NDWD_Packages::sync_task, inside one transaction, so the unit is counted once;
 *   reject / cancel → the hold (or the task's unit, via the task being rejected) is released;
 *   task completed → consumed, by the existing task sync. Revisions touch nothing.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_Requests {

	const STATUSES = array(
		'submitted'   => 'Submitted',
		'in_review'   => 'In Review',
		'approved'    => 'Approved',
		'in_progress' => 'In Progress',
		'completed'   => 'Completed',
		'rejected'    => 'Rejected',
		'cancelled'   => 'Cancelled',
	);

	const DELIVERABLE_STATUSES = array(
		'pending_approval'   => 'Awaiting approval',
		'approved'           => 'Approved',
		'rejected'           => 'Rejected',
		'revision_requested' => 'Revision requested',
	);

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	public static function comments( $entity, $id, $include_internal = false ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . NDWD_WS::t( 'comments' ) . ' WHERE entity = %s AND entity_id = %d' . ( $include_internal ? '' : ' AND is_internal = 0' ) . ' ORDER BY id ASC';
		return NDWD_WS_Attachments::on_comments( array_map( function ( $c ) use ( $include_internal ) {
			$o = array( 'id' => (int) $c->id, 'author_type' => $c->author_type, 'author_name' => $c->author_name, 'body' => $c->body, 'created_at' => $c->created_at );
			if ( $include_internal ) {
				$o['is_internal'] = (bool) $c->is_internal;
			}
			return $o;
		}, $wpdb->get_results( $wpdb->prepare( $sql, $entity, $id ) ) ), $include_internal ); // phpcs:ignore
	}

	/** $admin adds the internal fields; the default shape is what a client may see. */
	public static function to_array( $r, $admin = false ) {
		$item    = $r->client_package_id && $r->item_id ? NDWD_Packages::item( $r->client_package_id, $r->item_id ) : null;
		$project = $r->project_id ? self::project( $r->project_id ) : null;
		$links   = json_decode( (string) $r->reference_links, true );
		$out     = array(
			'id'              => (int) $r->id,
			'external_id'     => $r->external_id,
			'project_id'      => $r->project_id ? (int) $r->project_id : null,
			'project_name'    => $project ? $project->name : '',
			'parent_project_id'   => $project && $project->parent_id ? (int) $project->parent_id : null,
			'parent_project_name' => $project && $project->parent_id && ( $pp = self::project( (int) $project->parent_id ) ) ? $pp->name : '',
			'attachments'     => NDWD_WS_Attachments::for_entity( 'request', (int) $r->id, $admin ),
			'service_name'    => $item ? $item->service_name : '',
			'title'           => $r->title,
			'description'     => (string) $r->description,
			'instructions'    => (string) $r->instructions,
			'reference_links' => is_array( $links ) ? $links : array(),
			'preferred_date'  => $r->preferred_date,
			'requested_by'    => $r->requested_by,
			'status'          => $r->status,
			'status_label'    => self::STATUSES[ $r->status ],
			'review_note'     => (string) $r->review_note,
			'chat_active'     => $r->task_id ? NDWD_WS_TaskLink::chat_active( (int) $r->task_id ) : false,
			'created_at'      => $r->created_at,
			'updated_at'      => $r->updated_at,
		);
		if ( $admin ) {
			$conn   = NDWD_WS::connection( $r->connection_id );
			$client = NDWD_Clients::get( $r->client_id );
			$out   += array(
				'client_id'         => (int) $r->client_id,
				'client_name'       => $client ? $client->name : '',
				'connection_id'     => (int) $r->connection_id,
				'client_package_id' => $r->client_package_id ? (int) $r->client_package_id : null,
				'item_id'           => $r->item_id ? (int) $r->item_id : null,
				'unit_held'         => (bool) $r->hold_usage_id,
				'over_limit'        => (bool) $r->over_limit,
				'reviewed_by'       => $r->reviewed_by ? NDWD_Helpers::user_name( $r->reviewed_by ) : '',
				'task_id'           => $r->task_id ? (int) $r->task_id : null,
				'queue_team_id'     => $r->queue_team_id ? (int) $r->queue_team_id : null,
				'queue_entered_at'  => $r->queue_entered_at,
				'escalated_at'      => $r->escalated_at,
				'escalated_to'      => $r->escalated_to ? NDWD_Helpers::user_name( (int) $r->escalated_to ) : '',
				'default_team_id'   => $conn && $conn->default_team_id ? (int) $conn->default_team_id : null,
				'default_team_mode' => $conn ? $conn->default_team_mode : 'pool',
				'default_member_id' => $conn && $conn->default_member_id ? (int) $conn->default_member_id : null,
				'comments'          => self::comments( 'request', $r->id, true ),
			);
		}
		return $out;
	}

	private static function project( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/**
	 * Client submission. Duplicate external_id returns the original request (idempotent).
	 *
	 * @return array|WP_Error {request: row, created: bool}
	 */
	public static function submit( $conn, $in ) {
		global $wpdb;
		$t   = NDWD_WS::t( 'requests' );
		$ext = isset( $in['external_id'] ) ? sanitize_text_field( (string) $in['external_id'] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $ext ) ) {
			return NDWD_WS::err( 'A valid external_id is required.' );
		}
		$find = function () use ( $wpdb, $t, $conn, $ext ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE connection_id = %d AND external_id = %s", $conn->id, $ext ) ); // phpcs:ignore
		};
		$dup = $find();
		if ( $dup ) {
			return array( 'request' => $dup, 'created' => false );
		}
		$title = mb_substr( sanitize_text_field( isset( $in['title'] ) ? (string) $in['title'] : '' ), 0, 255 );
		if ( '' === $title ) {
			return NDWD_WS::err( 'Give the request a title.' );
		}
		$project_id = absint( isset( $in['project_id'] ) ? $in['project_id'] : 0 );
		if ( $project_id ) {
			$home = NDWD_WS::connection_for_project( $project_id, (int) $conn->client_id );
			$own  = self::project( $project_id );
			if ( ! $own || (int) $own->client_id !== (int) $conn->client_id || (int) $own->is_archived ) {
				return NDWD_WS::err( 'Project not found.', 404, 'ndwd_not_found' );
			}
			if ( ! $home || (int) $home->id !== (int) $conn->id ) {
				// A real project of this client, but it lives in another of their Workspaces.
				return NDWD_WS::err( 'This project is not in this Workspace. Open it from the Workspace it belongs to and send the request there.', 404, 'ndwd_project_elsewhere' );
			}
			if ( ! NDWD_WS_Projects::project_open( $conn, $project_id ) ) {
				return NDWD_WS::err( 'No Workspace package assigned to this project.', 403, 'ndwd_project_locked' );
			}
		}
		$alloc_id = absint( isset( $in['client_package_id'] ) ? $in['client_package_id'] : 0 );
		$item_id  = absint( isset( $in['item_id'] ) ? $in['item_id'] : 0 );
		$alloc    = $alloc_id ? NDWD_Packages::allocation( $alloc_id ) : null;
		if ( $alloc_id && ( ! $alloc || (int) $alloc->client_id !== (int) $conn->client_id ) ) {
			return NDWD_WS::err( 'Package not found.', 404, 'ndwd_not_found' );
		}
		$item = $alloc && $item_id ? NDWD_Packages::item( $alloc_id, $item_id ) : null;
		if ( $item_id && ! $item ) {
			return NDWD_WS::err( 'Service not found in this package.', 404, 'ndwd_not_found' );
		}
		$links = NDWD_Workflow::clean_links( isset( $in['reference_links'] ) ? (array) $in['reference_links'] : array() );
		if ( is_wp_error( $links ) ) {
			return $links;
		}
		$files = NDWD_WS_Attachments::validate( $in['attachments'] ?? array() );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$date = isset( $in['preferred_date'] ) ? NDWD_Helpers::sanitize_date( $in['preferred_date'] ) : null;
		$now  = NDWD_Helpers::now_mysql();
		$by   = mb_substr( sanitize_text_field( isset( $in['requested_by'] ) ? (string) $in['requested_by'] : '' ), 0, 190 );
		$ok   = $wpdb->insert( $t, array(
			'connection_id'     => (int) $conn->id,
			'client_id'         => (int) $conn->client_id,
			'external_id'       => $ext,
			'project_id'        => $project_id ? $project_id : null,
			'client_package_id' => $alloc_id ? $alloc_id : null,
			'item_id'           => $item ? (int) $item->id : null,
			'title'             => $title,
			'description'       => sanitize_textarea_field( isset( $in['description'] ) ? (string) $in['description'] : '' ),
			'instructions'      => sanitize_textarea_field( isset( $in['instructions'] ) ? (string) $in['instructions'] : '' ),
			'reference_links'   => wp_json_encode( $links ),
			'preferred_date'    => $date ? $date : null,
			'requested_by'      => $by,
			'status'            => 'submitted',
			'created_at'        => $now,
			'updated_at'        => $now,
		) );
		if ( ! $ok ) { // Lost a race on the unique key: return the winner.
			$dup = $find();
			return $dup ? array( 'request' => $dup, 'created' => false ) : NDWD_WS::err( 'Could not save the request.', 500, 'ndwd_error' );
		}
		$id = (int) $wpdb->insert_id;
		if ( $alloc && $item ) {
			self::hold( $alloc, $item, $id );
		}
		if ( $files ) {
			NDWD_WS_Attachments::save( $files, array( 'connection_id' => $conn->id, 'client_id' => (int) $conn->client_id, 'project_id' => $project_id, 'entity' => 'request', 'entity_id' => $id, 'by_type' => 'client', 'author' => $by ) );
		}
		NDWD_WS::audit( 'work_request_submitted', array( 'kind' => 'api', 'connection_id' => $conn->id, 'entity' => 'request', 'entity_id' => $id, 'actor' => $by ) );
		self::ensure_task( self::get( $id ), true );
		self::stage( self::get( $id ), 'received' );
		return array( 'request' => self::get( $id ), 'created' => true );
	}

	/** Staff member behind automatic actions: whoever set the connection up, or the first administrator. */
	private static function fallback_user( $conn ) {
		if ( $conn && $conn->created_by && NDWD_Permissions::is_active( (int) $conn->created_by ) ) {
			return (int) $conn->created_by;
		}
		$admins = get_users( array( 'role' => 'administrator', 'fields' => 'ID', 'number' => 1 ) );
		return $admins ? (int) $admins[0] : 0;
	}

	public static function team_leader( $team_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . NDWD_Helpers::table( 'team_members' ) . ' WHERE team_id = %d AND is_manager = 1 ORDER BY user_id ASC LIMIT 1', $team_id ) ); // phpcs:ignore
	}

	/**
	 * Every request has exactly one task, from the moment it arrives. An individual route goes to that
	 * member's list; a team route goes to the team's unassigned queue and starts the one-hour timer.
	 *
	 * @return int Task id, 0 on failure.
	 */
	public static function ensure_task( $r, $notify = true ) {
		global $wpdb;
		if ( ! $r ) {
			return 0;
		}
		if ( $r->task_id ) {
			return (int) $r->task_id;
		}
		$conn    = NDWD_WS::connection( $r->connection_id );
		$team_id = (int) $conn->default_team_id;
		$member  = (int) $conn->default_member_id;
		if ( $team_id && ! $member && 'leader' === $conn->default_team_mode ) {
			$member = self::team_leader( $team_id );
		}
		$actor = self::fallback_user( $conn );
		$links = json_decode( (string) $r->reference_links, true );
		$input = array(
			'title'          => $r->title,
			'description'    => trim( $r->description . ( $r->instructions ? "\n\nInstructions: " . $r->instructions : '' ) . "\n\nFrom client work request #" . $r->id . ( $r->requested_by ? ', sent by ' . $r->requested_by : '' ) ),
			'client_id'      => (int) $r->client_id,
			'project_id'     => $r->project_id ? (int) $r->project_id : 0,
			'team_id'        => $team_id,
			'assignee_ids'   => $member ? array( $member ) : ( $team_id ? array() : array( $actor ) ),
			'priority'       => 'medium',
			'scheduled_date' => NDWD_Helpers::today(),
			'due_date'       => $r->preferred_date ? $r->preferred_date : '',
			'links'          => is_array( $links ) ? $links : array(),
		);
		$task = NDWD_Tasks::create( $input, $actor, array( 'skip_assign_check' => true, 'silent' => true, 'source' => 'client_request' ) );
		if ( is_wp_error( $task ) ) {
			NDWD_WS::audit( 'work_request_task_failed', array( 'kind' => 'api', 'connection_id' => $conn->id, 'entity' => 'request', 'entity_id' => $r->id, 'details' => array( 'error' => $task->get_error_message() ) ) );
			return 0;
		}
		$pool = $team_id && ! $task->assignee_ids;
		$wpdb->update( NDWD_WS::t( 'requests' ), array( 'task_id' => (int) $task->id, 'queue_team_id' => $pool ? $team_id : null, 'queue_entered_at' => $pool ? NDWD_Helpers::now_mysql() : null ), array( 'id' => $r->id ) );
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT task_id FROM ' . NDWD_WS::t( 'task_client' ) . ' WHERE task_id = %d', $task->id ) ) ) { // phpcs:ignore
			$wpdb->insert( NDWD_WS::t( 'task_client' ), array( 'task_id' => (int) $task->id, 'visible' => 1, 'show_status' => 1, 'show_deadline' => 1, 'show_assignee' => 1, 'show_comments' => 1, 'show_calendar' => 1, 'updated_by' => (int) $actor, 'updated_at' => NDWD_Helpers::now_mysql() ) );
		}
		if ( $notify ) {
			$who = NDWD_Tasks::notify_targets( $task );
			NDWD_Notifications::notify_users( $who, $task, 'client_request', ( $pool ? 'New client request in your team queue: ' : 'New client request assigned to you: ' ) . $task->title, 0, 'creq:' . $r->id, null, $pool ? 'team_available' : 'assigned' );
			if ( ! $pool ) {
				self::stage( self::get( $r->id ), 'assigned' );
			}
		}
		return (int) $task->id;
	}

	/** One client-facing step. Carries no internal data; a rejection carries its stated reason. */
	public static function stage( $r, $stage ) {
		if ( ! $r ) {
			return;
		}
		$data = array( 'request_id' => (int) $r->id, 'status' => $r->status, 'stage' => $stage );
		if ( 'rejected' === $stage && '' !== (string) $r->review_note ) {
			$data['note'] = mb_substr( (string) $r->review_note, 0, 500 );
		}
		NDWD_WS_Webhooks::emit( 'work_request.status_changed', $r->connection_id, $data );
	}

	/**
	 * Requests nobody picked up within an hour of entering the team's queue go to the team leader.
	 * One atomic update decides, so two runs of the scheduler cannot escalate twice.
	 *
	 * @return array{escalated:int,no_leader:int}
	 */
	public static function escalate_due( $minutes = 60 ) {
		global $wpdb;
		$t    = NDWD_WS::t( 'requests' );
		$now  = NDWD_Helpers::now_mysql();
		$cut  = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - $minutes * MINUTE_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE queue_entered_at IS NOT NULL AND queue_entered_at <= %s AND escalated_at IS NULL AND task_id IS NOT NULL AND status NOT IN ('rejected','cancelled','completed') ORDER BY id ASC LIMIT 100", $cut ) ); // phpcs:ignore
		$out  = array( 'escalated' => 0, 'no_leader' => 0 );
		foreach ( $rows as $r ) {
			$task = NDWD_Tasks::get( (int) $r->task_id );
			if ( ! $task || $task->is_archived || NDWD_Helpers::is_closed( $task->status ) || $task->assignee_ids ) {
				$wpdb->update( $t, array( 'queue_entered_at' => null ), array( 'id' => $r->id ) ); // Picked up or closed: the timer stops.
				continue;
			}
			$leader = self::team_leader( (int) $r->queue_team_id );
			if ( ! $leader ) {
				$out['no_leader']++;
				if ( '' === (string) $r->escalation_note ) {
					$wpdb->update( $t, array( 'escalation_note' => 'No team leader to escalate to' ), array( 'id' => $r->id ) );
					NDWD_WS::audit( 'work_request_no_leader', array( 'kind' => 'admin', 'connection_id' => $r->connection_id, 'entity' => 'request', 'entity_id' => $r->id ) );
					NDWD_Notifications::notify_users( array( self::fallback_user( NDWD_WS::connection( $r->connection_id ) ) ), $task, 'escalated', 'Client request has waited over an hour and its team has no leader: ' . $task->title, 0, 'creq-noleader:' . $r->id );
				}
				continue;
			}
			$won = $wpdb->query( $wpdb->prepare( "UPDATE $t SET escalated_at = %s, escalated_to = %d, escalation_note = %s WHERE id = %d AND escalated_at IS NULL", $now, $leader, 'Nobody picked this up within one hour', $r->id ) ); // phpcs:ignore
			if ( ! $won ) {
				continue;
			}
			NDWD_Workflow::system_assign( $task, $leader, (int) $r->queue_team_id, 'Assigned automatically: nobody in the team picked up this client request within one hour.' );
			$fresh = NDWD_Tasks::get( $task->id );
			NDWD_Notifications::notify_users( array( $leader ), $fresh, 'escalated', 'Client request assigned to you as team leader, nobody picked it up within an hour: ' . $fresh->title, 0, 'creq-esc:' . $r->id, null, 'reassigned' );
			NDWD_Notifications::notify_users( array_diff( NDWD_Permissions::members_of( array( (int) $r->queue_team_id ) ), array( $leader ) ), $fresh, 'escalated', 'Client request went to the team leader after one hour: ' . $fresh->title, 0, 'creq-esc-t:' . $r->id, null, 'release' );
			NDWD_WS::audit( 'work_request_escalated', array( 'kind' => 'admin', 'connection_id' => $r->connection_id, 'entity' => 'request', 'entity_id' => $r->id, 'details' => array( 'to' => $leader, 'task_id' => (int) $task->id ) ) );
			$out['escalated']++;
		}
		return $out;
	}

	/** Reserves a unit for a pending request; flags it for review when none is left. */
	private static function hold( $alloc, $item, $request_id ) {
		global $wpdb;
		$cycle = NDWD_Packages::current_cycle( $alloc );
		$t     = NDWD_WS::t( 'requests' );
		if ( ! $cycle || 'active' !== $alloc->status ) {
			$wpdb->update( $t, array( 'over_limit' => 1 ), array( 'id' => $request_id ) );
			return;
		}
		NDWD_Packages::ensure_cycle( $alloc, $cycle );
		$b = NDWD_Packages::balance( $alloc, $item, $cycle );
		if ( $b['remaining'] < 1 ) {
			$wpdb->update( $t, array( 'over_limit' => 1 ), array( 'id' => $request_id ) );
			return;
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->insert( NDWD_Helpers::table( 'package_usage' ), array(
			'client_package_id' => (int) $alloc->id,
			'item_id'           => (int) $item->id,
			'cycle_start'       => $cycle[0],
			'quantity'          => 1,
			'state'             => 'reserved',
			'note'              => 'Held for client work request #' . $request_id,
			'created_at'        => $now,
			'updated_at'        => $now,
		) );
		$wpdb->update( $t, array( 'hold_usage_id' => (int) $wpdb->insert_id ), array( 'id' => $request_id ) );
	}

	private static function release_hold( $r, $reason ) {
		global $wpdb;
		if ( ! $r->hold_usage_id ) {
			return;
		}
		$now = NDWD_Helpers::now_mysql();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_Helpers::table( 'package_usage' ) . " SET state = 'released', released_at = %s, release_reason = %s, updated_at = %s WHERE id = %d AND state = 'reserved'", $now, $reason, $now, $r->hold_usage_id ) ); // phpcs:ignore
		$wpdb->update( NDWD_WS::t( 'requests' ), array( 'hold_usage_id' => null ), array( 'id' => $r->id ) );
	}

	private static function set_status( $r, $status, $extra = array() ) {
		global $wpdb;
		$wpdb->update( NDWD_WS::t( 'requests' ), array_merge( $extra, array( 'status' => $status, 'updated_at' => NDWD_Helpers::now_mysql() ) ), array( 'id' => $r->id ) );
		NDWD_WS_Webhooks::emit( 'work_request.status_changed', $r->connection_id, array( 'request_id' => (int) $r->id, 'status' => $status ) );
	}

	/**
	 * Review by WorkDesk. approve creates exactly one task and routes it:
	 * team pool (team only), team leader (team + the team's manager) or an individual.
	 *
	 * @param array $in decision (approve|reject|in_review), note, team_id, team_mode, assignee_id,
	 *                  scheduled_date, due_date, priority, client_package_id, item_id, package_override, package_override_reason.
	 * @return object|WP_Error
	 */
	public static function review( $id, $in, $user_id ) {
		$r = self::get( $id );
		if ( ! $r ) {
			return NDWD_WS::err( 'Work request not found.', 404, 'ndwd_not_found' );
		}
		$decision = isset( $in['decision'] ) ? (string) $in['decision'] : '';
		$note     = mb_substr( sanitize_textarea_field( isset( $in['note'] ) ? (string) $in['note'] : '' ), 0, 2000 );
		if ( ! in_array( $r->status, array( 'submitted', 'in_review' ), true ) ) {
			return NDWD_WS::err( 'This request has already been reviewed.', 409, 'ndwd_conflict' );
		}
		$reviewed = array( 'reviewed_by' => (int) $user_id, 'reviewed_at' => NDWD_Helpers::now_mysql(), 'review_note' => $note );
		if ( 'in_review' === $decision ) {
			self::set_status( $r, 'in_review', array( 'reviewed_by' => (int) $user_id ) );
			self::stage( self::get( $id ), 'reviewed' );
		} elseif ( 'reject' === $decision ) {
			if ( '' === trim( $note ) ) {
				return NDWD_WS::err( 'Give the reason for rejecting this request.' );
			}
			self::release_hold( $r, 'work request rejected' );
			self::set_status( $r, 'rejected', $reviewed );
			self::stage( self::get( $id ), 'rejected' );
			if ( $r->task_id ) {
				NDWD_Tasks::set_status( (int) $r->task_id, 'rejected', $user_id, $note );
			}
		} elseif ( 'approve' === $decision ) {
			$task = self::approve( $r, $in, $user_id, $reviewed );
			if ( is_wp_error( $task ) ) {
				return $task;
			}
			self::stage( self::get( $id ), 'reviewed' );
		} else {
			return NDWD_WS::err( 'Unknown decision.' );
		}
		NDWD_WS::audit( 'work_request_' . $decision, array( 'connection_id' => $r->connection_id, 'entity' => 'request', 'entity_id' => $r->id, 'details' => array( 'note' => $note ) ), $user_id );
		return self::get( $id );
	}

	/** Accepting a request whose task already exists: moves the unit to the task and any new routing. */
	private static function approve_existing( $r, $in, $user_id, $reviewed ) {
		global $wpdb;
		$task = NDWD_Tasks::get( (int) $r->task_id );
		if ( ! $task ) {
			return NDWD_WS::err( 'The task for this request no longer exists.', 409, 'ndwd_conflict' );
		}
		$alloc_id = array_key_exists( 'client_package_id', $in ) ? absint( $in['client_package_id'] ) : (int) $r->client_package_id;
		$item_id  = array_key_exists( 'item_id', $in ) ? absint( $in['item_id'] ) : (int) $r->item_id;
		$input    = array();
		if ( ! empty( $in['team_id'] ) && absint( $in['team_id'] ) !== (int) $task->team_id ) {
			$input['team_id'] = absint( $in['team_id'] );
		}
		$assignee = absint( $in['assignee_id'] ?? 0 );
		if ( ! $assignee && ! empty( $in['team_id'] ) && 'leader' === ( $in['team_mode'] ?? '' ) ) {
			$assignee = self::team_leader( absint( $in['team_id'] ) );
			if ( ! $assignee ) {
				return NDWD_WS::err( 'This team has no team manager. Route the work to the team pool or a member.' );
			}
		}
		if ( $assignee ) {
			$input['assignee_ids'] = array( $assignee );
		}
		if ( ! empty( $in['due_date'] ) ) {
			$input['due_date'] = $in['due_date'];
		}
		if ( $alloc_id ) {
			$input['client_package_id']       = $alloc_id;
			$input['package_item_id']         = $item_id;
			$input['package_scope']           = $item_id ? 'included' : ( $in['package_scope'] ?? 'retainer' );
			$input['package_override']        = ! empty( $in['package_override'] );
			$input['package_override_reason'] = $in['package_override_reason'] ?? '';
		}
		$wpdb->query( 'START TRANSACTION' );
		self::release_hold( $r, 'work request approved: unit moved to its task' );
		if ( $input ) {
			$res = NDWD_Tasks::update( $task->id, $input, $user_id, array( 'authorized' => true ) );
			if ( is_wp_error( $res ) ) {
				$wpdb->query( 'ROLLBACK' );
				return $res;
			}
		}
		self::set_status( $r, 'approved', array_merge( $reviewed, array( 'client_package_id' => $alloc_id ? $alloc_id : null, 'item_id' => $item_id ? $item_id : null, 'hold_usage_id' => null ) ) );
		$wpdb->query( 'COMMIT' );
		return NDWD_Tasks::get( $task->id );
	}

	private static function approve( $r, $in, $user_id, $reviewed ) {
		global $wpdb;
		if ( $r->task_id ) {
			return self::approve_existing( $r, $in, $user_id, $reviewed );
		}
		$conn      = NDWD_WS::connection( $r->connection_id );
		$team_id   = array_key_exists( 'team_id', $in ) ? absint( $in['team_id'] ) : (int) $conn->default_team_id;
		$assignee  = array_key_exists( 'assignee_id', $in ) ? absint( $in['assignee_id'] ) : (int) $conn->default_member_id;
		$team_mode = isset( $in['team_mode'] ) ? (string) $in['team_mode'] : $conn->default_team_mode;
		if ( ! $team_id && ! $assignee ) {
			return NDWD_WS::err( 'Choose a team or a member to route this work to.' );
		}
		if ( $team_id && ! $assignee && 'leader' === $team_mode ) {
			$leader = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . NDWD_Helpers::table( 'team_members' ) . ' WHERE team_id = %d AND is_manager = 1 ORDER BY user_id ASC LIMIT 1', $team_id ) ); // phpcs:ignore
			if ( ! $leader ) {
				return NDWD_WS::err( 'This team has no team manager. Route the work to the team pool or a member.' );
			}
			$assignee = (int) $leader;
		}
		$alloc_id = array_key_exists( 'client_package_id', $in ) ? absint( $in['client_package_id'] ) : (int) $r->client_package_id;
		$item_id  = array_key_exists( 'item_id', $in ) ? absint( $in['item_id'] ) : (int) $r->item_id;
		$input    = array(
			'title'          => $r->title,
			'description'    => trim( $r->description . ( $r->instructions ? "\n\nInstructions: " . $r->instructions : '' ) . "\n\nFrom client work request #" . $r->id ),
			'client_id'      => (int) $r->client_id,
			'project_id'     => $r->project_id ? (int) $r->project_id : 0,
			'team_id'        => $team_id,
			'assignee_ids'   => $assignee ? array( $assignee ) : array(),
			'priority'       => isset( $in['priority'] ) ? $in['priority'] : 'medium',
			'scheduled_date' => ! empty( $in['scheduled_date'] ) ? $in['scheduled_date'] : NDWD_Helpers::today(),
			'due_date'       => ! empty( $in['due_date'] ) ? $in['due_date'] : ( $r->preferred_date ? $r->preferred_date : '' ),
		);
		if ( $alloc_id ) {
			$input['client_package_id']       = $alloc_id;
			$input['package_item_id']         = $item_id;
			$input['package_scope']           = $item_id ? 'included' : ( isset( $in['package_scope'] ) ? $in['package_scope'] : 'retainer' );
			$input['package_override']        = ! empty( $in['package_override'] );
			$input['package_override_reason'] = isset( $in['package_override_reason'] ) ? $in['package_override_reason'] : '';
		} elseif ( ! empty( $in['package_scope'] ) ) {
			$input['package_scope'] = $in['package_scope'];
		}
		$wpdb->query( 'START TRANSACTION' );
		self::release_hold( $r, 'work request approved: unit moved to its task' );
		$task = NDWD_Tasks::create( $input, $user_id, array( 'skip_assign_check' => true ) );
		if ( is_wp_error( $task ) ) {
			$wpdb->query( 'ROLLBACK' );
			return $task;
		}
		self::set_status( $r, 'approved', array_merge( $reviewed, array( 'task_id' => (int) $task->id, 'client_package_id' => $alloc_id ? $alloc_id : null, 'item_id' => $item_id ? $item_id : null, 'hold_usage_id' => null ) ) );
		$wpdb->query( 'COMMIT' );
		// A task born from the client's own request starts visible to that client, with its status and
		// deadline. Staff can narrow this on the task itself.
		$wpdb->insert( NDWD_WS::t( 'task_client' ), array( 'task_id' => (int) $task->id, 'visible' => 1, 'show_status' => 1, 'show_deadline' => 1, 'show_comments' => 1, 'show_calendar' => 1, 'updated_by' => (int) $user_id, 'updated_at' => NDWD_Helpers::now_mysql() ) );
		return $task;
	}

	/** The client may cancel until WorkDesk has approved or rejected the request. */
	public static function cancel( $conn, $id, $actor ) {
		$r = self::get( $id );
		if ( ! $r || (int) $r->connection_id !== (int) $conn->id ) {
			return NDWD_WS::err( 'Work request not found.', 404, 'ndwd_not_found' );
		}
		if ( 'cancelled' === $r->status ) {
			return $r;
		}
		if ( ! in_array( $r->status, array( 'submitted', 'in_review' ), true ) ) {
			return NDWD_WS::err( 'This request is already being worked on. Comment on it to ask NeonDigital to stop.', 409, 'ndwd_conflict' );
		}
		self::release_hold( $r, 'work request cancelled by client' );
		self::set_status( $r, 'cancelled' );
		if ( $r->task_id ) {
			NDWD_Tasks::archive( (int) $r->task_id, self::fallback_user( $conn ) );
		}
		NDWD_WS::audit( 'work_request_cancelled', array( 'kind' => 'api', 'connection_id' => $conn->id, 'entity' => 'request', 'entity_id' => $id, 'actor' => $actor ) );
		return self::get( $id );
	}

	/** Keeps a request's status in step with its task. Called from the activity hook. */
	public static function on_task_activity( $task, $action ) {
		global $wpdb;
		if ( ! in_array( $action, array( 'status_changed', 'task_completed', 'task_rejected', 'task_archived', 'picked_up', 'reassigned', 'member_handoff', 'handed_off' ), true ) ) {
			return;
		}
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'requests' ) . ' WHERE task_id = %d', $task->id ) ); // phpcs:ignore
		if ( ! $r ) {
			return;
		}
		if ( in_array( $action, array( 'picked_up', 'reassigned', 'member_handoff', 'handed_off' ), true ) ) {
			self::stage( $r, 'assigned' );
			return;
		}
		// A task reopened before anyone accepted the request does not count as accepting it.
		$pending = in_array( $r->status, array( 'submitted', 'in_review' ), true ) ? $r->status : 'approved';
		$map     = array( 'pending' => $pending, 'in_process' => 'in_progress', 'completed' => 'completed', 'rejected' => 'rejected' );
		$want    = 'task_archived' === $action || (int) $task->is_archived ? 'cancelled' : ( isset( $map[ $task->status ] ) ? $map[ $task->status ] : $r->status );
		if ( $want !== $r->status ) {
			// Work started without a formal review: the held unit moves to the task now, counted once.
			if ( $r->hold_usage_id && in_array( $want, array( 'in_progress', 'completed' ), true ) ) {
				self::release_hold( $r, 'work started: unit moved to its task' );
				NDWD_Tasks::update( $task->id, array( 'client_package_id' => (int) $r->client_package_id, 'package_item_id' => (int) $r->item_id, 'package_scope' => 'included' ), self::fallback_user( NDWD_WS::connection( $r->connection_id ) ), array( 'authorized' => true ) );
			}
			if ( in_array( $want, array( 'rejected', 'cancelled' ), true ) && $r->hold_usage_id ) {
				self::release_hold( $r, 'task closed' );
			}
			self::set_status( $r, $want );
			$stages = array( 'in_progress' => 'started', 'completed' => 'completed', 'rejected' => 'rejected' );
			if ( isset( $stages[ $want ] ) ) {
				self::stage( self::get( $r->id ), $stages[ $want ] );
			}
		}
	}

	public static function add_comment( $conn, $entity, $id, $in, $author_type = 'client', $user_id = 0 ) {
		global $wpdb;
		$body = sanitize_textarea_field( isset( $in['body'] ) ? (string) $in['body'] : '' );
		if ( '' === trim( $body ) ) {
			return NDWD_WS::err( 'Write a comment first.' );
		}
		$t   = NDWD_WS::t( 'comments' );
		$ext = isset( $in['external_id'] ) ? sanitize_text_field( (string) $in['external_id'] ) : '';
		if ( '' !== $ext ) {
			$dup = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE connection_id = %d AND external_id = %s", $conn->id, $ext ) ); // phpcs:ignore
			if ( $dup ) {
				return (int) $dup;
			}
		}
		$internal = 'agency' === $author_type && ! empty( $in['is_internal'] );
		$files    = NDWD_WS_Attachments::validate( $in['attachments'] ?? array() );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$wpdb->insert( $t, array(
			'connection_id' => (int) $conn->id,
			'entity'        => $entity,
			'entity_id'     => (int) $id,
			'author_type'   => $author_type,
			'author_id'     => $user_id ? (int) $user_id : null,
			'author_name'   => mb_substr( sanitize_text_field( isset( $in['author_name'] ) ? (string) $in['author_name'] : ( $user_id ? NDWD_Helpers::user_name( $user_id ) : '' ) ), 0, 190 ),
			'body'          => $body,
			'is_internal'   => $internal ? 1 : 0,
			'external_id'   => '' !== $ext ? $ext : null,
			'created_at'    => NDWD_Helpers::now_mysql(),
		) );
		$cid = (int) $wpdb->insert_id;
		if ( $files ) {
			$pid = 0;
			if ( 'task' === $entity ) {
				$tk  = NDWD_Tasks::get( (int) $id );
				$pid = $tk ? (int) $tk->project_id : 0;
			} elseif ( 'request' === $entity ) {
				$rq  = self::get( (int) $id );
				$pid = $rq ? (int) $rq->project_id : 0;
			}
			$saved = NDWD_WS_Attachments::save( $files, array( 'connection_id' => $conn->id, 'client_id' => (int) $conn->client_id, 'project_id' => $pid, 'entity' => 'comment', 'entity_id' => $cid, 'is_internal' => $internal, 'by_type' => $author_type, 'by_id' => $user_id, 'author' => $in['author_name'] ?? ( $user_id ? NDWD_Helpers::user_name( $user_id ) : '' ) ) );
			if ( is_wp_error( $saved ) ) {
				$wpdb->delete( $t, array( 'id' => $cid ) ); // Never a message that says a file is attached when it is not.
				return $saved;
			}
		}
		if ( 'agency' === $author_type && ! $internal && 'task' !== $entity ) {
			NDWD_WS_Webhooks::emit( 'work_request.commented', $conn->id, array( 'entity' => $entity, 'entity_id' => (int) $id ) );
		}
		return $cid;
	}

	/* ---------------------------- Deliverables ---------------------------- */

	public static function deliverable( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'deliverables' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	public static function deliverable_array( $d, $with_history = false ) {
		global $wpdb;
		$p   = $d->project_id ? self::project( $d->project_id ) : null;
		$out = array(
			'id'           => (int) $d->id,
			'project_id'   => $d->project_id ? (int) $d->project_id : null,
			'project_name' => $p ? $p->name : '',
			'task_id'      => $d->task_id ? (int) $d->task_id : null,
			'request_id'   => $d->request_id ? (int) $d->request_id : null,
			'title'        => $d->title,
			'description'  => (string) $d->description,
			'file_url'     => $d->file_url,
			'revision'     => (int) $d->revision,
			'status'       => $d->status,
			'status_label' => self::DELIVERABLE_STATUSES[ $d->status ],
			'client_visible' => (bool) $d->client_visible,
			'attachments'  => NDWD_WS_Attachments::for_entity( 'deliverable', (int) $d->id ),
			'due_date'     => $d->due_date,
			'created_at'   => $d->created_at,
			'updated_at'   => $d->updated_at,
		);
		if ( $with_history ) {
			$out['approvals'] = array_map( function ( $a ) {
				return array( 'revision' => (int) $a->revision, 'decision' => $a->decision, 'comment' => (string) $a->comment, 'actor_name' => $a->actor_name, 'created_at' => $a->created_at );
			}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'approvals' ) . ' WHERE deliverable_id = %d ORDER BY id ASC', $d->id ) ) ); // phpcs:ignore
			$out['comments']  = self::comments( 'deliverable', $d->id );
		}
		return $out;
	}

	/**
	 * Agency side. With an id and new_revision: a new revision that asks for approval again.
	 * A revision never touches package usage.
	 */
	public static function save_deliverable( $id, $in, $user_id ) {
		global $wpdb;
		$t   = NDWD_WS::t( 'deliverables' );
		$old = $id ? self::deliverable( $id ) : null;
		if ( $id && ! $old ) {
			return NDWD_WS::err( 'Deliverable not found.', 404, 'ndwd_not_found' );
		}
		$conn = NDWD_WS::connection( $old ? $old->connection_id : absint( isset( $in['connection_id'] ) ? $in['connection_id'] : 0 ) );
		if ( ! $conn ) {
			return NDWD_WS::err( 'Choose a connected client.' );
		}
		$title = mb_substr( sanitize_text_field( isset( $in['title'] ) ? (string) $in['title'] : ( $old ? $old->title : '' ) ), 0, 255 );
		if ( '' === $title ) {
			return NDWD_WS::err( 'Give the deliverable a title.' );
		}
		$project_id = array_key_exists( 'project_id', $in ) ? absint( $in['project_id'] ) : ( $old ? (int) $old->project_id : 0 );
		if ( $project_id ) {
			$p = self::project( $project_id );
			if ( ! $p || (int) $p->client_id !== (int) $conn->client_id ) {
				return NDWD_WS::err( 'That project does not belong to this client.', 403, 'ndwd_forbidden' );
			}
		}
		$opt = function ( $k ) use ( $in, $old ) {
			return array_key_exists( $k, $in ) ? ( absint( $in[ $k ] ) ? absint( $in[ $k ] ) : null ) : ( $old ? $old->$k : null );
		};
		$due = array_key_exists( 'due_date', $in ) ? NDWD_Helpers::sanitize_date( $in['due_date'] ) : ( $old ? $old->due_date : null );
		$now = NDWD_Helpers::now_mysql();
		$files = NDWD_WS_Attachments::validate( ! empty( $in['attachment'] ) ? array( $in['attachment'] ) : ( $in['attachments'] ?? array() ) );
		if ( is_wp_error( $files ) ) {
			return $files;
		}
		$row = array(
			'title'       => $title,
			'description' => sanitize_textarea_field( isset( $in['description'] ) ? (string) $in['description'] : ( $old ? (string) $old->description : '' ) ),
			'file_url'    => isset( $in['file_url'] ) ? esc_url_raw( (string) $in['file_url'], array( 'http', 'https' ) ) : ( $old ? $old->file_url : '' ),
			'project_id'  => $project_id ? $project_id : null,
			'task_id'     => $opt( 'task_id' ),
			'request_id'  => $opt( 'request_id' ),
			'due_date'    => $due ? $due : null,
			'client_visible' => array_key_exists( 'client_visible', $in ) ? ( empty( $in['client_visible'] ) ? 0 : 1 ) : ( $old ? (int) $old->client_visible : 1 ),
			'updated_at'  => $now,
		);
		if ( $old ) {
			if ( ! empty( $in['new_revision'] ) ) {
				$row['revision'] = (int) $old->revision + 1;
				$row['status']   = 'pending_approval';
			}
			$wpdb->update( $t, $row, array( 'id' => $old->id ) );
			$id = (int) $old->id;
		} else {
			$wpdb->insert( $t, array_merge( $row, array( 'connection_id' => (int) $conn->id, 'client_id' => (int) $conn->client_id, 'created_by' => (int) $user_id, 'created_at' => $now ) ) );
			$id = (int) $wpdb->insert_id;
		}
		if ( $files ) {
			NDWD_WS_Attachments::save( $files, array( 'connection_id' => $conn->id, 'client_id' => (int) $conn->client_id, 'project_id' => $project_id, 'entity' => 'deliverable', 'entity_id' => $id, 'by_type' => 'agency', 'by_id' => $user_id, 'note' => ! empty( $in['new_revision'] ) || ! $old ? 'Revision ' . ( $old ? (int) $old->revision + ( empty( $in['new_revision'] ) ? 0 : 1 ) : 1 ) : '' ) );
		}
		NDWD_WS::audit( $old ? 'deliverable_updated' : 'deliverable_created', array( 'connection_id' => $conn->id, 'entity' => 'deliverable', 'entity_id' => $id ), $user_id );
		if ( $row['client_visible'] ) {
			NDWD_WS_Webhooks::emit( 'deliverable.uploaded', $conn->id, array( 'deliverable_id' => $id ) );
		}
		return self::deliverable( $id );
	}

	/**
	 * Client decision on the current revision. Idempotent on external_id. A decision never
	 * changes usage: normal revisions consume nothing extra.
	 */
	public static function decide( $conn, $id, $in ) {
		global $wpdb;
		$d = self::deliverable( $id );
		if ( ! $d || (int) $d->connection_id !== (int) $conn->id ) {
			return NDWD_WS::err( 'Deliverable not found.', 404, 'ndwd_not_found' );
		}
		$map      = array( 'approve' => 'approved', 'reject' => 'rejected', 'revision' => 'revision_requested' );
		$decision = isset( $in['decision'] ) ? (string) $in['decision'] : '';
		if ( ! isset( $map[ $decision ] ) ) {
			return NDWD_WS::err( 'Unknown decision.' );
		}
		$ext = isset( $in['external_id'] ) ? sanitize_text_field( (string) $in['external_id'] ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $ext ) ) {
			return NDWD_WS::err( 'A valid external_id is required.' );
		}
		$a_t = NDWD_WS::t( 'approvals' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $a_t WHERE deliverable_id = %d AND external_id = %s", $id, $ext ) ) ) { // phpcs:ignore
			return $d;
		}
		$comment = sanitize_textarea_field( isset( $in['comment'] ) ? (string) $in['comment'] : '' );
		if ( 'approve' !== $decision && '' === trim( $comment ) ) {
			return NDWD_WS::err( 'Explain what needs to change.' );
		}
		$won = $wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_WS::t( 'deliverables' ) . " SET status = %s, updated_at = %s WHERE id = %d AND status = 'pending_approval'", $map[ $decision ], NDWD_Helpers::now_mysql(), $id ) ); // phpcs:ignore
		if ( ! $won ) {
			return NDWD_WS::err( 'This revision has already been decided.', 409, 'ndwd_conflict' );
		}
		$actor = mb_substr( sanitize_text_field( isset( $in['actor_name'] ) ? (string) $in['actor_name'] : '' ), 0, 190 );
		$wpdb->insert( $a_t, array( 'deliverable_id' => (int) $id, 'revision' => (int) $d->revision, 'decision' => $decision, 'comment' => $comment, 'actor_name' => $actor, 'external_id' => $ext, 'created_at' => NDWD_Helpers::now_mysql() ) );
		NDWD_WS::audit( 'deliverable_' . $decision, array( 'kind' => 'api', 'connection_id' => $conn->id, 'entity' => 'deliverable', 'entity_id' => $id, 'actor' => $actor ) );
		return self::deliverable( $id );
	}
}
