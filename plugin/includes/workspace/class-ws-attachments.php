<?php
/**
 * Attachments shared between WorkDesk and Neon Workspace: files on chat messages, work requests,
 * project updates and deliverables.
 *
 * One store, the existing protected storage folder (NDWD_Files). Files are saved under random names
 * with no public URL; every read goes through a route that checks who is asking. Staff read with
 * their WorkDesk session. A client reads through Neon Workspace's signed call for their own
 * connection, and only files that are not internal and whose record they can already see.
 * Google Drive in Neon Workspace stays the business's own file store and is not touched.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_WS_Attachments {

	const MAX_TOTAL = 5242880; // 5 MB per message or upload, under PHP's usual 8 MB request limit once encoded.
	const MAX_FILES = 5;
	const ENTITIES  = array( 'comment', 'request', 'project', 'deliverable' );

	/** Allowed types: extension => [MIME type, leading bytes, or null for plain text]. */
	const TYPES = array(
		'pdf'  => array( 'application/pdf', '%PDF' ),
		'png'  => array( 'image/png', "\x89PNG" ),
		'jpg'  => array( 'image/jpeg', "\xFF\xD8\xFF" ),
		'jpeg' => array( 'image/jpeg', "\xFF\xD8\xFF" ),
		'gif'  => array( 'image/gif', 'GIF8' ),
		'webp' => array( 'image/webp', 'RIFF' ),
		'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04" ),
		'xlsx' => array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "PK\x03\x04" ),
		'pptx' => array( 'application/vnd.openxmlformats-officedocument.presentationml.presentation', "PK\x03\x04" ),
		'zip'  => array( 'application/zip', "PK\x03\x04" ),
		'txt'  => array( 'text/plain', null ),
		'csv'  => array( 'text/csv', null ),
	);
	/** Shown in the browser when asked; everything else always downloads. */
	const PREVIEW = array( 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$staff = function () {
			return is_user_logged_in() && NDWD_Permissions::can( 'ndwd_access' );
		};
		register_rest_route( NDWD_REST::NS, '/workspace/attachments/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_download' ), 'permission_callback' => $staff ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_delete' ), 'permission_callback' => $staff ),
		) );
		register_rest_route( NDWD_REST::NS, '/workspace/projects/(?P<id>\d+)/attachments', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_project_list' ), 'permission_callback' => $staff ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_project_add' ), 'permission_callback' => $staff ),
		) );
	}

	public static function rules() {
		return array( 'types' => array_keys( self::TYPES ), 'max_total' => self::MAX_TOTAL, 'max_files' => self::MAX_FILES );
	}

	/* ---------------------------- Validation and storage ---------------------------- */

	/**
	 * Checks every file before anything is saved, so a message is never stored with half its files.
	 *
	 * @param array $files [{name, data(base64 or data: URL)}]
	 * @return array|WP_Error Decoded files.
	 */
	public static function validate( $files ) {
		$files = is_array( $files ) ? array_values( array_filter( $files, 'is_array' ) ) : array();
		if ( count( $files ) > self::MAX_FILES ) {
			return NDWD_WS::err( 'Attach at most ' . self::MAX_FILES . ' files at a time.' );
		}
		$out   = array();
		$total = 0;
		foreach ( $files as $f ) {
			$name = sanitize_file_name( (string) ( $f['name'] ?? '' ) );
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( '' === $name || ! isset( self::TYPES[ $ext ] ) ) {
				return NDWD_WS::err( 'This file type cannot be attached. Allowed: ' . implode( ', ', array_keys( self::TYPES ) ) . '.' );
			}
			$bytes = base64_decode( preg_replace( '#^data:[^;,]*;base64,#', '', (string) ( $f['data'] ?? '' ) ), true );
			if ( false === $bytes || '' === $bytes ) {
				return NDWD_WS::err( $name . ' could not be read.' );
			}
			$total += strlen( $bytes );
			if ( $total > self::MAX_TOTAL ) {
				return NDWD_WS::err( 'Attachments can be at most 5 MB in total.', 413, 'ndwd_too_large' );
			}
			$magic = self::TYPES[ $ext ][1];
			if ( null === $magic ? false !== strpos( $bytes, "\0" ) : 0 !== strpos( $bytes, $magic ) || ( 'webp' === $ext && 'WEBP' !== substr( $bytes, 8, 4 ) ) ) {
				return NDWD_WS::err( 'The content of ' . $name . ' does not match its file type.' );
			}
			$out[] = array( 'name' => mb_substr( $name, 0, 190 ), 'ext' => $ext, 'mime' => self::TYPES[ $ext ][0], 'bytes' => $bytes );
		}
		return $out;
	}

	/**
	 * Saves already validated files against one record.
	 *
	 * @param array $ctx {connection_id, client_id, project_id, entity, entity_id, is_internal, by_type, by_id, author, note}
	 * @return array|WP_Error Saved attachment rows (public shape, staff view).
	 */
	public static function save( $decoded, $ctx ) {
		global $wpdb;
		$saved = array();
		$now   = NDWD_Helpers::now_mysql();
		foreach ( $decoded as $f ) {
			$rel = 'ws-attachments/' . gmdate( 'Y/m' ) . '/' . wp_generate_password( 24, false ) . '.' . $f['ext'];
			NDWD_Files::dir( dirname( $rel ) );
			if ( false === file_put_contents( NDWD_Reports::base_dir() . '/' . $rel, $f['bytes'] ) ) { // phpcs:ignore
				return NDWD_WS::err( 'Could not store ' . $f['name'] . '.', 500, 'ndwd_io' );
			}
			$wpdb->insert( NDWD_WS::t( 'attachments' ), array(
				'connection_id'    => ! empty( $ctx['connection_id'] ) ? (int) $ctx['connection_id'] : null,
				'client_id'        => (int) ( $ctx['client_id'] ?? 0 ),
				'project_id'       => ! empty( $ctx['project_id'] ) ? (int) $ctx['project_id'] : null,
				'entity'           => $ctx['entity'],
				'entity_id'        => (int) $ctx['entity_id'],
				'name'             => $f['name'],
				'mime'             => $f['mime'],
				'size'             => strlen( $f['bytes'] ),
				'path'             => $rel,
				'sha256'           => hash( 'sha256', $f['bytes'] ),
				'is_internal'      => empty( $ctx['is_internal'] ) ? 0 : 1,
				'uploaded_by_type' => 'client' === ( $ctx['by_type'] ?? '' ) ? 'client' : 'agency',
				'uploaded_by'      => ! empty( $ctx['by_id'] ) ? (int) $ctx['by_id'] : null,
				'author_name'      => mb_substr( sanitize_text_field( (string) ( $ctx['author'] ?? '' ) ), 0, 190 ),
				'note'             => mb_substr( sanitize_text_field( (string) ( $ctx['note'] ?? '' ) ), 0, 255 ),
				'created_at'       => $now,
			) );
			$saved[] = self::to_array( self::get( (int) $wpdb->insert_id ), true );
		}
		if ( $saved ) {
			NDWD_WS::audit( 'attachments_added', array( 'kind' => 'client' === ( $ctx['by_type'] ?? '' ) ? 'api' : 'admin', 'connection_id' => $ctx['connection_id'] ?? null, 'entity' => $ctx['entity'], 'entity_id' => (int) $ctx['entity_id'], 'details' => array( 'files' => wp_list_pluck( $saved, 'name' ), 'internal' => ! empty( $ctx['is_internal'] ) ) ), (int) ( $ctx['by_id'] ?? 0 ) );
		}
		return $saved;
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'attachments' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ) ); // phpcs:ignore
	}

	public static function to_array( $a, $staff = false ) {
		$o = array( 'id' => (int) $a->id, 'name' => $a->name, 'type' => $a->mime, 'ext' => strtolower( pathinfo( $a->name, PATHINFO_EXTENSION ) ), 'size' => (int) $a->size, 'uploaded_by_type' => $a->uploaded_by_type, 'author_name' => $a->author_name, 'note' => $a->note, 'created_at' => $a->created_at, 'preview' => in_array( strtolower( pathinfo( $a->name, PATHINFO_EXTENSION ) ), self::PREVIEW, true ) );
		if ( $staff ) {
			$o['is_internal'] = (bool) $a->is_internal;
		}
		return $o;
	}

	/**
	 * Files of several records of one kind, keyed by record id. Internal ones only for staff.
	 *
	 * @return array<int, array[]>
	 */
	public static function for_entities( $entity, $ids, $staff = false ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'attachments' ) . ' WHERE entity = %s AND deleted_at IS NULL AND entity_id IN (' . implode( ',', $ids ) . ')' . ( $staff ? '' : ' AND is_internal = 0' ) . ' ORDER BY id ASC', $entity ) ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $a ) {
			$out[ (int) $a->entity_id ][] = self::to_array( $a, $staff );
		}
		return $out;
	}

	public static function for_entity( $entity, $id, $staff = false ) {
		return self::for_entities( $entity, array( $id ), $staff )[ (int) $id ] ?? array();
	}

	/** Adds 'attachments' to each comment row (rows carry 'id'). */
	public static function on_comments( $rows, $staff = false ) {
		$by = self::for_entities( 'comment', wp_list_pluck( $rows, 'id' ), $staff );
		foreach ( $rows as &$c ) {
			$c['attachments'] = $by[ (int) $c['id'] ] ?? array();
		}
		return $rows;
	}

	/* ---------------------------- Who may read ---------------------------- */

	private static function comment( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_WS::t( 'comments' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	/** The task a file belongs to, if any, for the staff permission check. */
	private static function task_of( $a ) {
		$task_id = 0;
		if ( 'comment' === $a->entity ) {
			$c = self::comment( (int) $a->entity_id );
			if ( $c && 'task' === $c->entity ) {
				$task_id = (int) $c->entity_id;
			} elseif ( $c && 'request' === $c->entity ) {
				$r       = NDWD_WS_Requests::get( (int) $c->entity_id );
				$task_id = $r ? (int) $r->task_id : 0;
			}
		} elseif ( 'request' === $a->entity ) {
			$r       = NDWD_WS_Requests::get( (int) $a->entity_id );
			$task_id = $r ? (int) $r->task_id : 0;
		} elseif ( 'deliverable' === $a->entity ) {
			$d       = NDWD_WS_Requests::deliverable( (int) $a->entity_id );
			$task_id = $d ? (int) $d->task_id : 0;
		}
		return $task_id ? NDWD_Tasks::get( $task_id ) : null;
	}

	public static function staff_can( $a, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( NDWD_Permissions::can( 'ndwd_manage_workspace', $user_id ) ) {
			return true;
		}
		if ( 'project' === $a->entity ) {
			return NDWD_WS_Hub::can_view();
		}
		$t = self::task_of( $a );
		return $t && NDWD_Permissions::can_view_task( $t, $user_id );
	}

	/**
	 * A client may read a file only through the Workspace the record lives in, and only when the
	 * record itself is one they can see. Internal files are never readable, whatever the id.
	 */
	public static function client_can( $conn, $a ) {
		if ( ! $a || (int) $a->is_internal || (int) $a->client_id !== (int) $conn->client_id ) {
			return false;
		}
		switch ( $a->entity ) {
			case 'comment':
				$c = self::comment( (int) $a->entity_id );
				if ( ! $c || (int) $c->is_internal || (int) $c->connection_id !== (int) $conn->id ) {
					return false;
				}
				if ( 'task' === $c->entity ) {
					$t = NDWD_Tasks::get( (int) $c->entity_id );
					return $t && NDWD_WS_API::task_visible( $conn, $t ) && NDWD_WS_TaskLink::flags( $t->id )['show_comments'];
				}
				if ( 'request' === $c->entity || 'deliverable' === $c->entity ) {
					$r = 'request' === $c->entity ? NDWD_WS_Requests::get( (int) $c->entity_id ) : NDWD_WS_Requests::deliverable( (int) $c->entity_id );
					return $r && (int) $r->connection_id === (int) $conn->id;
				}
				return false;
			case 'request':
				$r = NDWD_WS_Requests::get( (int) $a->entity_id );
				return $r && (int) $r->connection_id === (int) $conn->id;
			case 'deliverable':
				$d = NDWD_WS_Requests::deliverable( (int) $a->entity_id );
				return $d && (int) $d->connection_id === (int) $conn->id && (int) $d->client_visible;
			case 'project':
				$home = NDWD_WS::connection_for_project( (int) $a->entity_id );
				return $home && (int) $home->id === (int) $conn->id && NDWD_WS_Projects::project_open( $conn, (int) $a->entity_id );
		}
		return false;
	}

	/** Streams the file with its stored type and ends the request. */
	public static function stream( $a, $inline = false ) {
		$path = NDWD_Files::path( $a->path );
		if ( ! $path ) {
			return NDWD_WS::err( 'This file is no longer available.', 404, 'ndwd_not_found' );
		}
		$inline = $inline && in_array( strtolower( pathinfo( $a->name, PATHINFO_EXTENSION ) ), self::PREVIEW, true );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		$safe = str_replace( array( '"', "\r", "\n" ), '', $a->name );
		header( 'Content-Type: ' . $a->mime );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode( $safe ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
		header( 'Access-Control-Expose-Headers: Content-Disposition' );
		readfile( $path ); // phpcs:ignore
		exit;
	}

	/* ---------------------------- Staff routes ---------------------------- */

	public static function rest_download( WP_REST_Request $r ) {
		$a = self::get( (int) $r['id'] );
		if ( ! $a || ! self::staff_can( $a ) ) {
			return NDWD_WS::err( 'File not found.', 404, 'ndwd_not_found' );
		}
		return self::stream( $a, (bool) $r->get_param( 'inline' ) );
	}

	public static function rest_delete( WP_REST_Request $r ) {
		global $wpdb;
		$a   = self::get( (int) $r['id'] );
		$uid = get_current_user_id();
		if ( ! $a || ! self::staff_can( $a ) ) {
			return NDWD_WS::err( 'File not found.', 404, 'ndwd_not_found' );
		}
		if ( ! NDWD_Permissions::can( 'ndwd_manage_workspace' ) && ! ( 'agency' === $a->uploaded_by_type && (int) $a->uploaded_by === $uid ) ) {
			return NDWD_WS::err( 'Only the person who added this file, or a Workspace manager, can remove it.', 403, 'ndwd_forbidden' );
		}
		$wpdb->update( NDWD_WS::t( 'attachments' ), array( 'deleted_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $a->id ) );
		NDWD_WS::audit( 'attachment_removed', array( 'connection_id' => $a->connection_id, 'entity' => $a->entity, 'entity_id' => (int) $a->entity_id, 'details' => array( 'name' => $a->name ) ), $uid );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	private static function project_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'projects' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore
	}

	public static function rest_project_list( WP_REST_Request $r ) {
		if ( ! NDWD_WS_Hub::can_view() ) {
			return NDWD_WS::err( 'Project not found.', 404, 'ndwd_not_found' );
		}
		return rest_ensure_response( array( 'items' => self::for_entity( 'project', (int) $r['id'], true ), 'rules' => self::rules() ) );
	}

	/** A project update file: shared with the client, or internal. Saved against this project only. */
	public static function rest_project_add( WP_REST_Request $r ) {
		$p = self::project_row( (int) $r['id'] );
		if ( ! $p || ! NDWD_WS_Hub::can_view() || ! ( NDWD_Permissions::can( 'ndwd_manage_projects' ) || NDWD_Permissions::can( 'ndwd_manage_workspace' ) ) ) {
			return NDWD_WS::err( 'You cannot add files to this project.', 403, 'ndwd_forbidden' );
		}
		$b    = json_decode( (string) $r->get_body(), true );
		$b    = is_array( $b ) ? $b : array();
		$list = isset( $b['files'] ) ? (array) $b['files'] : array( array( 'name' => $b['name'] ?? '', 'data' => $b['data'] ?? '' ) );
		$dec  = self::validate( $list );
		if ( is_wp_error( $dec ) ) {
			return $dec;
		}
		if ( ! $dec ) {
			return NDWD_WS::err( 'Choose a file first.' );
		}
		$conn = $p->client_id ? NDWD_WS::connection_for_project( (int) $p->id, (int) $p->client_id ) : null;
		$uid  = get_current_user_id();
		$res  = self::save( $dec, array( 'connection_id' => $conn ? $conn->id : null, 'client_id' => (int) $p->client_id, 'project_id' => (int) $p->id, 'entity' => 'project', 'entity_id' => (int) $p->id, 'is_internal' => ! empty( $b['is_internal'] ), 'by_type' => 'agency', 'by_id' => $uid, 'author' => NDWD_Helpers::user_name( $uid ), 'note' => $b['note'] ?? '' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $conn && empty( $b['is_internal'] ) ) {
			NDWD_WS_Webhooks::emit( 'project.file_shared', $conn->id, array( 'project_id' => (int) $p->id ) );
		}
		return rest_ensure_response( array( 'items' => $res ) );
	}

	/* ---------------------------- Client side (signed, through Neon Workspace) ---------------------------- */

	public static function client_download( $conn, $id ) {
		$a = self::get( (int) $id );
		if ( ! $a || ! self::client_can( $conn, $a ) ) {
			return NDWD_WS::err( 'File not found.', 404, 'ndwd_not_found' );
		}
		return self::stream( $a );
	}

	/** A client's file on a project of this Workspace. Never internal. */
	public static function client_project_add( $conn, $project_id, $in ) {
		$home = NDWD_WS::connection_for_project( (int) $project_id );
		if ( ! $home || (int) $home->id !== (int) $conn->id ) {
			return NDWD_WS::err( 'This project is not in this Workspace.', 404, 'ndwd_not_found' );
		}
		if ( ! NDWD_WS_Projects::project_open( $conn, (int) $project_id ) ) {
			return NDWD_WS::err( 'No Workspace package assigned to this project.', 403, 'ndwd_project_locked' );
		}
		$dec = self::validate( $in['attachments'] ?? array() );
		if ( is_wp_error( $dec ) ) {
			return $dec;
		}
		if ( ! $dec ) {
			return NDWD_WS::err( 'Choose a file first.' );
		}
		$res = self::save( $dec, array( 'connection_id' => $conn->id, 'client_id' => (int) $conn->client_id, 'project_id' => (int) $project_id, 'entity' => 'project', 'entity_id' => (int) $project_id, 'is_internal' => false, 'by_type' => 'client', 'author' => $in['author_name'] ?? '', 'note' => $in['note'] ?? '' ) );
		return is_wp_error( $res ) ? $res : array( 'items' => array_map( function ( $x ) {
			unset( $x['is_internal'] );
			return $x;
		}, $res ) );
	}
}
