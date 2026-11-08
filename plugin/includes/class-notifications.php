<?php
/**
 * In-app notifications and email delivery (wp_mail), with logging and de-duplication.
 *
 * Dedupe keys are UNIQUE columns, inserted with INSERT IGNORE, so a scheduled
 * job that runs twice cannot create a second reminder or email.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Notifications {

	/** Notification type => email setting key. */
	const EMAIL_MAP = array(
		'assigned'      => 'assigned',
		'followup'      => 'assigned',
		'reassigned'    => 'reassigned',
		'unassigned'    => 'reassigned',
		'reminder'      => 'reminder',
		'due'           => 'reminder',
		'overdue'       => 'reminder',
		'updated'       => 'updated',
		'urgent'        => 'updated',
		'status'        => 'updated',
		'remark'        => 'updated',
		'leave'         => 'leave',
		'client_request' => 'assigned',
		'client_message' => 'updated',
		'escalated'     => 'reassigned',
	);

	private static $last_mail_error = '';

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ) );
		add_action( 'wp_mail_failed', function ( $error ) {
			self::$last_mail_error = $error instanceof WP_Error ? $error->get_error_message() : 'Unknown mail error';
		} );
	}

	public static function settings() {
		$s = get_option( 'ndwd_settings', array() );
		return is_array( $s ) ? $s : array();
	}

	public static function smtp_password() {
		if ( defined( 'NDWD_SMTP_PASSWORD' ) ) {
			return NDWD_SMTP_PASSWORD;
		}
		return (string) get_option( 'ndwd_smtp_pass', '' );
	}

	/** Applies SMTP settings when a host is configured. Credentials stay server-side. */
	public static function configure_smtp( $phpmailer ) {
		$s = self::settings();
		if ( ! empty( $s['email_from'] ) && is_email( $s['email_from'] ) ) {
			$phpmailer->From     = $s['email_from'];
			$phpmailer->FromName = ! empty( $s['email_from_name'] ) ? $s['email_from_name'] : get_bloginfo( 'name' );
		}
		if ( empty( $s['smtp_host'] ) ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host = $s['smtp_host'];
		$phpmailer->Port = ! empty( $s['smtp_port'] ) ? (int) $s['smtp_port'] : 587;
		if ( ! empty( $s['smtp_secure'] ) && in_array( $s['smtp_secure'], array( 'tls', 'ssl' ), true ) ) {
			$phpmailer->SMTPSecure = $s['smtp_secure'];
		}
		if ( ! empty( $s['smtp_user'] ) ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $s['smtp_user'];
			$phpmailer->Password = self::smtp_password();
		}
	}

	/** Configuration health shown as a warning banner to administrators. */
	public static function email_status() {
		$s        = self::settings();
		$warnings = array();
		if ( empty( $s['email_enabled'] ) ) {
			$warnings[] = 'Email notifications are turned off. In-app notifications still work.';
		} else {
			if ( empty( $s['email_from'] ) || ! is_email( $s['email_from'] ) ) {
				$warnings[] = 'No valid "From" email address is set.';
			}
			if ( empty( $s['smtp_host'] ) ) {
				$warnings[] = 'No SMTP server configured; WordPress will use the host\'s PHP mail(), which is often unreliable on shared hosting.';
			} elseif ( ! empty( $s['smtp_user'] ) && '' === self::smtp_password() ) {
				$warnings[] = 'SMTP username is set but no SMTP password is configured.';
			}
		}
		// Recent failures are reported separately so the UI can let an admin dismiss
		// them until a newer failure (higher log id) occurs.
		global $wpdb;
		$fail = $wpdb->get_row( $wpdb->prepare(
			'SELECT COUNT(*) AS n, MAX(id) AS last_id FROM ' . NDWD_Helpers::table( 'email_log' ) . " WHERE status = 'failed' AND created_at >= %s",
			NDWD_Helpers::now()->modify( '-1 day' )->format( 'Y-m-d H:i:s' )
		) );
		$recent_fail = $fail ? (int) $fail->n : 0;
		return array(
			'enabled'    => ! empty( $s['email_enabled'] ),
			'configured' => ! empty( $s['email_enabled'] ) && ! empty( $s['email_from'] ) && ! empty( $s['smtp_host'] ),
			'warnings'   => $warnings,
			'failures'   => $recent_fail ? array(
				'count'   => $recent_fail,
				'last_id' => (int) $fail->last_id,
				'message' => $recent_fail . ' email(s) failed in the last 24 hours. See Settings → Email log.',
			) : null,
		);
	}

	/* ------------------------------------------------------------------ */

	public static function task_assigned( $task, $user_ids, $actor_id, $type = 'assigned' ) {
		if ( ! $task ) {
			return;
		}
		$actor = NDWD_Helpers::user_name( $actor_id );
		$when  = $task->scheduled_date === NDWD_Helpers::today() ? 'today' : 'for ' . $task->scheduled_date;
		$verbs = array(
			'assigned'   => "$actor assigned you a task $when",
			'reassigned' => "$actor reassigned a task to you ($when)",
			'followup'   => "$actor created a follow-up for you $when",
		);
		$msg = ( isset( $verbs[ $type ] ) ? $verbs[ $type ] : $verbs['assigned'] ) . ': ' . $task->title;
		self::notify_users( $user_ids, $task, $type, $msg, $actor_id, null, null, 'assigned' === $type && ! $task->assignee_ids ? 'team_available' : null );
		if ( 'urgent' === $task->priority ) {
			self::notify_users( $user_ids, $task, 'urgent', 'URGENT task: ' . $task->title, $actor_id );
		}
	}

	/**
	 * Creates in-app notifications (and emails where enabled) for each user except the actor.
	 *
	 * @param string|null $dedupe Base dedupe key; the user id is appended per recipient.
	 * @param string|null $event  Push category event when more specific than the type (see NDWD_Push::EVENTS).
	 */
	public static function notify_users( $user_ids, $task, $type, $message, $actor_id = 0, $dedupe = null, $link = null, $event = null ) {
		foreach ( array_unique( array_map( 'intval', (array) $user_ids ) ) as $uid ) {
			if ( ! $uid || $uid === (int) $actor_id || ! NDWD_Permissions::is_active( $uid ) ) {
				continue;
			}
			$key = $dedupe ? mb_substr( $dedupe . ':' . $uid, 0, 190 ) : null;
			if ( ! self::create_in_app( $uid, $task, $type, $message, $key, $link, $event ) ) {
				continue; // Duplicate: already notified.
			}
			if ( isset( self::EMAIL_MAP[ $type ] ) ) {
				self::maybe_email_task( $uid, $task, $type, $message, $key, $link );
			}
		}
	}

	/** Saves one recipient's in-app notification and queues its push. @return bool False when the key already exists. */
	public static function create_in_app( $uid, $task, $type, $message, $key = null, $link = null, $event = null ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'notifications' );
		$made  = (bool) $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO $table (user_id, task_id, type, message, link, is_read, dedupe_key, created_at) VALUES (%d, %d, %s, %s, %s, 0, " . ( $key ? '%s' : 'NULL' ) . ', %s)',
			array_merge(
				array( $uid, $task ? (int) $task->id : 0, $type, mb_substr( $message, 0, 500 ), null !== $link ? $link : ( $task ? '#/tasks/' . (int) $task->id : '' ) ),
				$key ? array( $key ) : array(),
				array( NDWD_Helpers::now_mysql() )
			)
		) );
		if ( $made ) {
			try {
				NDWD_Push::enqueue( (int) $wpdb->insert_id, $uid, $event ? $event : $type );
			} catch ( Throwable $e ) { // Push must never get in the way of the notification itself.
				error_log( 'NDWD push queue failed: ' . $e->getMessage() ); // phpcs:ignore
			}
		}
		return $made;
	}

	/**
	 * Delivers one scheduled notification to one recipient. The in-app row and the email are
	 * independent: a mail failure leaves the in-app notification in place and can be retried
	 * alone, and a key already present never sends or creates a second copy.
	 *
	 * @return array{in_app:bool,email:string} email: sent, failed, duplicate or skipped.
	 */
	public static function deliver( $uid, $task, $type, $message, $key ) {
		$in_app = false;
		try {
			$in_app = self::create_in_app( $uid, $task, $type, $message, $key );
		} catch ( Throwable $e ) {
			error_log( 'NDWD in-app notification failed: ' . $e->getMessage() ); // phpcs:ignore
		}
		$email = 'skipped';
		if ( isset( self::EMAIL_MAP[ $type ] ) ) {
			try {
				$res   = self::maybe_email_task( $uid, $task, $type, $message, $key );
				$email = null === $res ? 'skipped' : ( 'duplicate' === $res ? 'duplicate' : ( $res ? 'sent' : 'failed' ) );
			} catch ( Throwable $e ) {
				self::$last_mail_error = $e->getMessage();
				$email                 = 'failed';
			}
		}
		return array( 'in_app' => $in_app, 'email' => $email );
	}

	private static function maybe_email_task( $user_id, $task, $type, $message, $dedupe, $link = null ) {
		$s     = self::settings();
		$etype = self::EMAIL_MAP[ $type ];
		if ( empty( $s['email_enabled'] ) || empty( $s['email_types'][ $etype ] ) ) {
			return null;
		}
		if ( get_user_meta( $user_id, 'ndwd_email_optout', true ) ) {
			return null;
		}
		$rows = '';
		if ( $task ) {
			$rows = self::detail_rows( array(
				'Task'      => '#' . $task->id . ' ' . $task->title,
				'Status'    => NDWD_Helpers::status_label( $task->status ),
				'Priority'  => NDWD_Helpers::priority_label( $task->priority ),
				'Scheduled' => $task->scheduled_date,
				'Due'       => trim( (string) $task->due_date . ' ' . ( $task->due_time ? substr( $task->due_time, 0, 5 ) : '' ) ),
				'Work type' => NDWD_Helpers::work_type_label( $task->work_type ),
			) );
		}
		$url = NDWD_App::url() . ( null !== $link ? $link : ( $task ? '#/tasks/' . (int) $task->id : '' ) );
		return self::send( $user_id, '[WorkDesk] ' . $message, self::template( $message, $rows, $url, $task ? 'Open task' : 'Open WorkDesk' ), $type, $dedupe ? 'mail:' . $dedupe : null );
	}

	public static function detail_rows( $pairs ) {
		$html = '';
		foreach ( $pairs as $k => $v ) {
			if ( '' === (string) $v ) {
				continue;
			}
			$html .= '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;white-space:nowrap">' . esc_html( $k ) . '</td><td style="padding:6px 0;color:#111827">' . esc_html( $v ) . '</td></tr>';
		}
		return $html ? '<table style="border-collapse:collapse;margin:16px 0;font-size:14px">' . $html . '</table>' : '';
	}

	public static function template( $heading, $body_html, $url, $button ) {
		return '<div style="font-family:Inter,Segoe UI,Arial,sans-serif;background:#f4f6f8;padding:24px">'
			. '<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e5e7eb">'
			. '<div style="background:#111214;padding:16px 24px;color:#5eead4;font-weight:700;font-size:16px">NeonDigital WorkDesk</div>'
			. '<div style="padding:24px"><p style="margin:0 0 8px;font-size:16px;color:#111827;font-weight:600">' . esc_html( $heading ) . '</p>'
			. $body_html
			. '<p style="margin:20px 0 0"><a href="' . esc_url( $url ) . '" style="background:linear-gradient(135deg,#2dd4a8,#4f6cf0);color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;display:inline-block">' . esc_html( $button ) . '</a></p>'
			. '</div></div><p style="text-align:center;color:#9ca3af;font-size:12px">You receive this because you are a WorkDesk member.</p></div>';
	}

	/**
	 * Sends one email, logging the attempt. Returns true/false/'duplicate'.
	 */
	public static function send( $user_id, $subject, $html, $type, $dedupe = null, $attachments = array() ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return false;
		}
		return self::send_to( $user->user_email, $subject, $html, $type, $dedupe, $attachments, $user_id );
	}

	/**
	 * Sends one email and logs it. A dedupe key makes the send happen at most once
	 * successfully: a failed attempt releases its key so a later retry can send.
	 *
	 * @return bool|string true, false, or 'duplicate' when already sent.
	 */
	/**
	 * @param string|string[] $email One address, or several "To" addresses.
	 * @param string[]        $cc    Optional CC addresses.
	 */
	public static function send_to( $email, $subject, $html, $type, $dedupe = null, $attachments = array(), $user_id = 0, $cc = array(), $bcc = array() ) {
		global $wpdb;
		$to_list = array_values( array_filter( array_map( 'trim', (array) $email ) ) );
		$cc      = array_values( array_filter( array_map( 'trim', (array) $cc ) ) );
		foreach ( array_merge( $to_list, $cc ) as $addr ) {
			if ( ! is_email( $addr ) ) {
				self::$last_mail_error = 'Invalid recipient address.';
				return false;
			}
		}
		if ( ! $to_list ) {
			self::$last_mail_error = 'Invalid recipient address.';
			return false;
		}
		$email = implode( ', ', $to_list );
		$log = NDWD_Helpers::table( 'email_log' );
		$key = $dedupe ? mb_substr( $dedupe, 0, 190 ) : null;
		$ok  = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO $log (user_id, recipient, subject, type, status, dedupe_key, created_at) VALUES (" . ( $user_id ? '%d' : 'NULL' ) . ", %s, %s, %s, 'sending', " . ( $key ? '%s' : 'NULL' ) . ', %s)',
			array_merge( $user_id ? array( $user_id ) : array(), array( mb_substr( $email . ( $cc ? ' (cc ' . implode( ', ', $cc ) . ')' : '' ), 0, 190 ), mb_substr( $subject, 0, 255 ), $type ), $key ? array( $key ) : array(), array( NDWD_Helpers::now_mysql() ) )
		) );
		if ( ! $ok ) {
			return 'duplicate';
		}
		$log_id                = (int) $wpdb->insert_id;
		self::$last_mail_error = '';
		$headers               = array( 'Content-Type: text/html; charset=UTF-8' );
		foreach ( $cc as $addr ) {
			$headers[] = 'Cc: ' . $addr;
		}
		foreach ( array_filter( array_map( 'trim', (array) $bcc ), 'is_email' ) as $addr ) {
			$headers[] = 'Bcc: ' . $addr;
		}
		$sent                  = wp_mail( count( $to_list ) > 1 ? $to_list : $to_list[0], $subject, $html, $headers, $attachments );
		$update                = array(
			'status' => $sent ? 'sent' : 'failed',
			'error'  => $sent ? null : ( self::$last_mail_error ? self::$last_mail_error : 'wp_mail() returned false' ),
		);
		if ( ! $sent ) {
			$update['dedupe_key'] = null;
			self::$last_mail_error = $update['error'];
		}
		$wpdb->update( $log, $update, array( 'id' => $log_id ) );
		return $sent;
	}

	public static function last_error() {
		return self::$last_mail_error ? self::$last_mail_error : 'Email could not be sent.';
	}

	/** Test email to the current admin. Returns [ok, message]. */
	public static function send_test( $user_id ) {
		$res = self::send( $user_id, '[WorkDesk] Test email', self::template( 'Your WorkDesk email settings work.', '<p style="color:#374151">Sent at ' . esc_html( NDWD_Helpers::now_mysql() ) . ' (Asia/Kolkata).</p>', NDWD_App::url(), 'Open WorkDesk' ), 'test' );
		if ( true === $res ) {
			return array( true, 'Test email handed to the mail server. Check the inbox (and spam folder).' );
		}
		return array( false, 'Sending failed: ' . ( self::$last_mail_error ? self::$last_mail_error : 'wp_mail() returned false' ) );
	}

	/* ------------------------------------------------------------------ */

	public static function for_user( $user_id, $limit = 30, $unread_only = false ) {
		global $wpdb;
		$sql = 'SELECT * FROM ' . NDWD_Helpers::table( 'notifications' ) . ' WHERE user_id = %d AND deleted_at IS NULL' . ( $unread_only ? ' AND is_read = 0' : '' ) . ' ORDER BY id DESC LIMIT %d';
		return array_map( function ( $n ) {
			return array(
				'id'         => (int) $n->id,
				'task_id'    => $n->task_id ? (int) $n->task_id : null,
				'type'       => $n->type,
				'message'    => $n->message,
				'link'       => $n->link,
				'is_read'    => (bool) $n->is_read,
				'created_at' => $n->created_at,
			);
		}, $wpdb->get_results( $wpdb->prepare( $sql, $user_id, $limit ) ) ); // phpcs:ignore
	}

	public static function unread_count( $user_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . NDWD_Helpers::table( 'notifications' ) . ' WHERE user_id = %d AND is_read = 0 AND deleted_at IS NULL', $user_id ) );
	}

	public static function mark_read( $user_id, $ids = null ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'notifications' );
		if ( null === $ids ) {
			return $wpdb->query( $wpdb->prepare( "UPDATE $table SET is_read = 1 WHERE user_id = %d AND is_read = 0 AND deleted_at IS NULL", $user_id ) );
		}
		$ids = NDWD_Helpers::int_list( $ids );
		if ( ! $ids ) {
			return 0;
		}
		return $wpdb->query( $wpdb->prepare( "UPDATE $table SET is_read = 1 WHERE user_id = %d AND deleted_at IS NULL AND id IN (" . NDWD_Helpers::in_placeholders( $ids ) . ')', array_merge( array( $user_id ), $ids ) ) );
	}

	/**
	 * Removes notifications from one user's visible history. Rows are soft-deleted
	 * (deleted_at) and scoped to that user, so other recipients are unaffected and the
	 * dedupe key stays taken: a scheduled job cannot re-create a removed notification.
	 * Retention cleanup purges the rows later with the rest of the month's history.
	 *
	 * @param int[]|null $ids       Ids to remove; null with $read_only removes all read ones.
	 * @param bool       $read_only Only remove notifications already marked read.
	 */
	public static function remove( $user_id, $ids = null, $read_only = false ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'notifications' );
		$where = 'user_id = %d AND deleted_at IS NULL' . ( $read_only ? ' AND is_read = 1' : '' );
		$args  = array( NDWD_Helpers::now_mysql(), $user_id );
		if ( null !== $ids ) {
			$ids = NDWD_Helpers::int_list( $ids );
			if ( ! $ids ) {
				return 0;
			}
			$where .= ' AND id IN (' . NDWD_Helpers::in_placeholders( $ids ) . ')';
			$args   = array_merge( $args, $ids );
		} elseif ( ! $read_only ) {
			return 0; // Never remove everything implicitly; unread items need explicit ids.
		}
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET deleted_at = %s WHERE $where", $args ) ); // phpcs:ignore
	}

	public static function email_log( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, recipient, subject, type, status, error, created_at FROM ' . NDWD_Helpers::table( 'email_log' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	}
}
