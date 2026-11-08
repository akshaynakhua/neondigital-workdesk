<?php
/**
 * Member profile pictures and avatar colours.
 *
 * A picture is validated (type, size, dimensions), cropped to a square, stored as a
 * WordPress media attachment owned by that member and referenced from user meta.
 * Members change only their own picture/colour; people with "Manage members" may
 * change anyone's. Roles, permissions and other member details are never touched.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Profile {

	const META_PIC   = 'ndwd_avatar_id';
	const META_COLOR = 'ndwd_avatar_color';
	const META_HANDLE = 'ndwd_handle';
	const MAX_BYTES  = 2097152; // 2 MB
	const SIZE       = 256;

	/** Preset initials backgrounds (key => hex). An empty value means the automatic colour. */
	const COLORS = array(
		'indigo' => '#5b6cf5',
		'blue'   => '#2563eb',
		'teal'   => '#0d9488',
		'green'  => '#16a34a',
		'amber'  => '#d97706',
		'orange' => '#ea580c',
		'rose'   => '#e11d48',
		'purple' => '#9333ea',
		'slate'  => '#475569',
	);

	public static function routes() {
		$ns   = NDWD_REST::NS;
		$perm = array( __CLASS__, 'can_edit' );
		register_rest_route( $ns, '/me/profile', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'rest_me' ), 'permission_callback' => array( 'NDWD_REST', 'member' ) ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_save_me' ), 'permission_callback' => array( 'NDWD_REST', 'member' ) ),
		) );
		register_rest_route( $ns, '/members/(?P<id>\d+)/avatar', array(
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'rest_upload' ), 'permission_callback' => $perm ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'rest_remove' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( $ns, '/members/(?P<id>\d+)/avatar-color', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_color' ),
			'permission_callback' => $perm,
		) );
	}

	/** Own profile, or anyone's with "Manage members"; the target must be a WorkDesk user. */
	public static function can_edit( WP_REST_Request $r ) {
		if ( ! NDWD_REST::member() ) {
			return false;
		}
		$id = (int) $r['id'];
		if ( ! $id || ! get_userdata( $id ) || ! user_can( $id, 'ndwd_access' ) ) {
			return new WP_Error( 'ndwd_not_found', 'Member not found.', array( 'status' => 404 ) );
		}
		return get_current_user_id() === $id || NDWD_Permissions::can( 'ndwd_manage_members' );
	}

	/**
	 * The member's own profile. Official name and registered email are shown read-only here:
	 * regular members ask Administration to change them (members/ID needs "Manage members").
	 */
	public static function rest_me() {
		$uid = get_current_user_id();
		$u   = get_userdata( $uid );
		$tn  = array();
		foreach ( NDWD_Permissions::memberships( $uid ) as $tid => $m ) {
			$tn[] = array( 'id' => (int) $tid, 'name' => NDWD_Tasks::team_name( $tid ), 'manager' => (bool) $m['manager'] );
		}
		return rest_ensure_response( array_merge( self::get( $uid ), array(
			'name'      => $u->display_name,
			'email'     => $u->user_email,
			'handle'    => (string) get_user_meta( $uid, self::META_HANDLE, true ),
			'role'      => NDWD_Permissions::role_of( $uid ),
			'teams'     => $tn,
			'colors'    => self::COLORS,
			'can_admin' => NDWD_Permissions::can( 'ndwd_manage_members', $uid ),
		) ) );
	}

	/** Saves the display handle: only the handle. Name and email are ignored here on purpose. */
	public static function rest_save_me( WP_REST_Request $r ) {
		$b   = json_decode( (string) $r->get_body(), true );
		$res = self::set_handle( get_current_user_id(), is_array( $b ) && isset( $b['handle'] ) ? (string) $b['handle'] : '' );
		return is_wp_error( $res ) ? $res : self::rest_me();
	}

	/** @return true|WP_Error A display handle is optional, short and unique across members. */
	public static function set_handle( $user_id, $handle ) {
		global $wpdb;
		$handle = strtolower( trim( ltrim( trim( $handle ), '@' ) ) );
		if ( '' === $handle ) {
			delete_user_meta( $user_id, self::META_HANDLE );
			return true;
		}
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._-]{1,29}$/', $handle ) ) {
			return new WP_Error( 'ndwd_invalid', 'A handle has 2 to 30 letters, numbers, dots, dashes or underscores.', array( 'status' => 400 ) );
		}
		$taken = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = %s AND meta_value = %s AND user_id <> %d LIMIT 1", self::META_HANDLE, $handle, $user_id ) );
		if ( $taken ) {
			return new WP_Error( 'ndwd_invalid', 'That handle is already taken.', array( 'status' => 409 ) );
		}
		update_user_meta( $user_id, self::META_HANDLE, $handle );
		return true;
	}

	/** Public profile bits used wherever an avatar is drawn. */
	public static function get( $user_id ) {
		$color = (string) get_user_meta( $user_id, self::META_COLOR, true );
		return array(
			'id'     => (int) $user_id,
			'avatar' => self::picture_url( $user_id ),
			'color'  => isset( self::COLORS[ $color ] ) ? $color : '',
		);
	}

	public static function picture_url( $user_id ) {
		$aid = self::picture_id( $user_id );
		if ( ! $aid ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $aid, 'full' );
		return $url ? $url : null;
	}

	/** The member's current picture attachment, only when it really is their WorkDesk avatar. */
	private static function picture_id( $user_id ) {
		$aid = (int) get_user_meta( $user_id, self::META_PIC, true );
		if ( ! $aid || 'attachment' !== get_post_type( $aid ) || (int) get_post_meta( $aid, '_ndwd_avatar', true ) !== (int) $user_id ) {
			return 0;
		}
		return $aid;
	}

	public static function rest_upload( WP_REST_Request $r ) {
		$res = self::save_picture( (int) $r['id'], $r->get_json_params() ? $r->get_json_params() : $r->get_params() );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::get( (int) $r['id'] ) );
	}

	public static function rest_remove( WP_REST_Request $r ) {
		self::remove_picture( (int) $r['id'] );
		return rest_ensure_response( self::get( (int) $r['id'] ) );
	}

	public static function rest_color( WP_REST_Request $r ) {
		$res = self::set_color( (int) $r['id'], (string) $r->get_param( 'color' ) );
		return is_wp_error( $res ) ? $res : rest_ensure_response( self::get( (int) $r['id'] ) );
	}

	public static function set_color( $user_id, $color ) {
		$color = sanitize_key( $color );
		if ( '' === $color ) {
			delete_user_meta( $user_id, self::META_COLOR );
			return true;
		}
		if ( ! isset( self::COLORS[ $color ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Choose one of the preset colours.', array( 'status' => 400 ) );
		}
		update_user_meta( $user_id, self::META_COLOR, $color );
		return true;
	}

	/**
	 * Saves a picture sent as a base64 data URL ({name, data}). PNG, JPG or WEBP up to
	 * 2 MB; centre-cropped to a 256 px square and stored in the media library.
	 */
	public static function save_picture( $user_id, $in ) {
		$bytes = base64_decode( preg_replace( '#^data:[^;]+;base64,#', '', isset( $in['data'] ) ? (string) $in['data'] : '' ), true );
		if ( ! $bytes ) {
			return new WP_Error( 'ndwd_invalid', 'The image could not be read.', array( 'status' => 400 ) );
		}
		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return new WP_Error( 'ndwd_invalid', 'The picture can be at most 2 MB.', array( 'status' => 400 ) );
		}
		$info  = @getimagesizefromstring( $bytes ); // phpcs:ignore
		$types = array( IMAGETYPE_JPEG => array( 'jpg', 'image/jpeg' ), IMAGETYPE_PNG => array( 'png', 'image/png' ), IMAGETYPE_WEBP => array( 'webp', 'image/webp' ) );
		if ( ! $info || ! isset( $types[ $info[2] ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'The file is not a valid PNG, JPG or WEBP image.', array( 'status' => 400 ) );
		}
		if ( $info[0] < 64 || $info[1] < 64 || $info[0] > 6000 || $info[1] > 6000 ) {
			return new WP_Error( 'ndwd_invalid', 'The picture should be between 64 and 6000 pixels on each side.', array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		list( $ext, $mime ) = $types[ $info[2] ];
		if ( 'image/webp' === $mime && ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			list( $ext, $mime ) = $types[ IMAGETYPE_PNG ];
		}
		$tmp = wp_tempnam( 'ndwd-avatar' );
		file_put_contents( $tmp, $bytes ); // phpcs:ignore
		$ed = wp_get_image_editor( $tmp );
		if ( is_wp_error( $ed ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return new WP_Error( 'ndwd_invalid', 'The server cannot process this image.', array( 'status' => 400 ) );
		}
		$side = min( $info[0], $info[1] );
		$ed->crop( (int) floor( ( $info[0] - $side ) / 2 ), (int) floor( ( $info[1] - $side ) / 2 ), $side, $side, min( $side, self::SIZE ), min( $side, self::SIZE ) );
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return new WP_Error( 'ndwd_io', 'The uploads folder is not writable.', array( 'status' => 500 ) );
		}
		$file  = wp_unique_filename( $up['path'], 'ndwd-avatar-' . $user_id . '-' . wp_generate_password( 10, false, false ) . '.' . $ext );
		$saved = $ed->save( trailingslashit( $up['path'] ) . $file, $mime );
		@unlink( $tmp ); // phpcs:ignore
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'ndwd_io', 'Could not store the picture.', array( 'status' => 500 ) );
		}
		$user = get_userdata( $user_id );
		$aid  = wp_insert_attachment( array(
			'post_mime_type' => $saved['mime-type'],
			'post_title'     => 'Profile picture – ' . $user->display_name,
			'post_status'    => 'inherit',
			'post_author'    => $user_id,
		), $saved['path'], 0, true );
		if ( is_wp_error( $aid ) ) {
			@unlink( $saved['path'] ); // phpcs:ignore
			return new WP_Error( 'ndwd_io', 'Could not store the picture.', array( 'status' => 500 ) );
		}
		wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $saved['path'] ) );
		update_post_meta( $aid, '_ndwd_avatar', $user_id );
		$old = self::picture_id( $user_id );
		update_user_meta( $user_id, self::META_PIC, $aid );
		if ( $old ) {
			wp_delete_attachment( $old, true );
		}
		return $aid;
	}

	/** Removes the member's picture (their initials show again). */
	public static function remove_picture( $user_id ) {
		$old = self::picture_id( $user_id );
		delete_user_meta( $user_id, self::META_PIC );
		if ( $old ) {
			wp_delete_attachment( $old, true );
		}
	}
}
