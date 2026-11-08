<?php
/**
 * Scheduled job bookkeeping and the rolling data-retention cleanup.
 *
 * NDWD_Jobs keeps one row per (job, period) — e.g. ("monthly_report", "2026-09") — so a
 * job runs at most once successfully per period even when WP-Cron and a server cron
 * fire together. Failed runs are retried later with back-off and the Admin is
 * notified. Settings → Scheduler shows the log.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Jobs {

	const MAX_ATTEMPTS = 8;

	public static function table() {
		return NDWD_Helpers::table( 'jobs' );
	}

	public static function get( $job, $period ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE job = %s AND period = %s', $job, $period ) );
	}

	/**
	 * Claims a run. Returns false when the period is already done, another run is in
	 * progress, or a retry is not due yet. The claim is atomic (unique key / conditional update).
	 */
	public static function claim( $job, $period ) {
		global $wpdb;
		$t   = self::table();
		$now = NDWD_Helpers::now_mysql();
		$ins = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $t (job, period, status, attempts, started_at) VALUES (%s, %s, 'running', 1, %s)", $job, $period, $now ) );
		if ( $ins ) {
			return true;
		}
		$row = self::get( $job, $period );
		if ( ! $row || 'done' === $row->status ) {
			return false;
		}
		$stale = NDWD_Helpers::now()->modify( '-30 minutes' )->format( 'Y-m-d H:i:s' );
		if ( 'running' === $row->status && $row->started_at > $stale ) {
			return false;
		}
		if ( 'running' !== $row->status && $row->next_attempt_at && $row->next_attempt_at > $now ) {
			return false;
		}
		return (bool) $wpdb->query( $wpdb->prepare(
			"UPDATE $t SET status = 'running', attempts = attempts + 1, started_at = %s WHERE id = %d AND status = %s AND attempts = %d",
			$now, $row->id, $row->status, $row->attempts
		) );
	}

	/**
	 * Records the outcome. $status: done | failed | waiting. Failed and waiting runs are
	 * retried after $retry_hours; failures notify the Admin (first and last attempt).
	 */
	public static function finish( $job, $period, $status, $message = '', $retry_hours = 3 ) {
		global $wpdb;
		$row = self::get( $job, $period );
		if ( ! $row ) {
			return;
		}
		$give_up = 'failed' === $status && (int) $row->attempts >= self::MAX_ATTEMPTS;
		$wpdb->update( self::table(), array(
			'status'          => $give_up ? 'gave_up' : $status,
			'message'         => mb_substr( (string) $message, 0, 2000 ),
			'finished_at'     => NDWD_Helpers::now_mysql(),
			'next_attempt_at' => 'done' === $status || $give_up ? null : NDWD_Helpers::now()->modify( '+' . max( 1, (int) $retry_hours ) . ' hours' )->format( 'Y-m-d H:i:s' ),
		), array( 'id' => $row->id ) );
		if ( 'failed' === $status && ( 1 === (int) $row->attempts || $give_up ) ) {
			self::alert( $job, $period, $message, $give_up, (int) $row->attempts );
		}
	}

	/** In-app notification (and email when possible) to Admins about a failed job. */
	public static function alert( $job, $period, $message, $final = false, $attempt = 1 ) {
		$label = self::label( $job );
		$msg   = $label . ' (' . $period . ') failed' . ( $final ? ' and will not be retried automatically' : ' — it will be retried automatically' ) . ': ' . $message;
		$users = array_map( 'intval', get_users( array( 'capability' => 'ndwd_manage_settings', 'fields' => 'ID' ) ) );
		foreach ( $users as $uid ) {
			NDWD_Notifications::notify_users( array( $uid ), null, 'job_failed', mb_substr( $msg, 0, 480 ), 0, 'job:' . $job . ':' . $period . ':' . $attempt . ':' . $uid, '#/admin/settings' );
		}
		$s = NDWD_Notifications::settings();
		if ( ! empty( $s['email_enabled'] ) ) {
			foreach ( NDWD_Reports::report_recipients() as $email ) {
				NDWD_Notifications::send_to( $email, '[WorkDesk] Scheduled job failed: ' . $label, NDWD_Notifications::template( 'Scheduled job failed', '<p style="color:#374151">' . esc_html( $msg ) . '</p>', NDWD_App::url() . '#/admin/settings', 'Open scheduler status' ), 'job_failed', 'job-mail:' . $job . ':' . $period . ':' . $attempt . ':' . md5( $email ) );
			}
		}
	}

	public static function label( $job ) {
		$labels = array(
			'monthly_report'   => 'Monthly reports',
			'retention'        => 'Data retention cleanup',
			'fy_notice'        => 'Invoice archive reminder',
			'temp_cleanup'     => 'Temporary file cleanup',
		);
		return isset( $labels[ $job ] ) ? $labels[ $job ] : $job;
	}

	/** Recent job runs for the Settings page. */
	public static function status() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY started_at DESC, id DESC LIMIT 40' ); // phpcs:ignore
		return array_map( function ( $r ) {
			return array(
				'job'             => $r->job,
				'label'           => self::label( $r->job ),
				'period'          => $r->period,
				'status'          => $r->status,
				'attempts'        => (int) $r->attempts,
				'message'         => (string) $r->message,
				'started_at'      => $r->started_at,
				'finished_at'     => $r->finished_at,
				'next_attempt_at' => $r->next_attempt_at,
			);
		}, $rows );
	}

	/* ------------------------------------------------------------------ *
	 * Jobs (called from NDWD_Cron::run)
	 * ------------------------------------------------------------------ */

	/**
	 * Previous month's reports: generate the pack (if not already generated) and email it to
	 * the Admin. Starts after 01:00 IST on the 1st; retried every 3 hours until delivered.
	 */
	public static function monthly_report() {
		$now = NDWD_Helpers::now();
		if ( (int) $now->format( 'j' ) === 1 && (int) $now->format( 'G' ) < 1 ) {
			return 'waiting until 01:00 IST';
		}
		$ym = NDWD_Reports::previous_month();
		if ( ! self::claim( 'monthly_report', $ym ) ) {
			return 'not due';
		}
		return self::deliver_month( $ym, 'monthly_report' );
	}

	/** Generates (if needed) and emails the report pack for $ym, recording the job outcome. */
	public static function deliver_month( $ym, $job ) {
		global $wpdb;
		$report = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'reports' ) . " WHERE period = %s AND status IN ('success','partial') AND purged_at IS NULL ORDER BY version DESC LIMIT 1", $ym ) );
		if ( ! $report ) {
			$gen = NDWD_Reports::generate( $ym, 'auto', 0 );
			if ( is_wp_error( $gen ) || 'failed' === $gen['status'] ) {
				$msg = is_wp_error( $gen ) ? $gen->get_error_message() : ( $gen['error'] ? $gen['error'] : 'Report generation failed.' );
				self::finish( $job, $ym, 'failed', 'Generation: ' . $msg, 3 );
				return 'failed: ' . $msg;
			}
			$report = (object) array( 'id' => $gen['id'], 'email_status' => null, 'confirmed_at' => null );
		}
		if ( 'sent' === $report->email_status ) {
			self::finish( $job, $ym, 'done', 'Generated and emailed earlier.' );
			return 'done';
		}
		if ( empty( NDWD_Notifications::settings()['email_enabled'] ) ) {
			self::finish( $job, $ym, 'waiting', 'Report generated. Email sending is turned off in Settings, so it was not emailed. Download it from Monthly Archives and mark it as saved, or turn email on.', 24 );
			return 'waiting for email to be enabled';
		}
		if ( ! NDWD_Reports::report_recipients() ) {
			self::finish( $job, $ym, 'waiting', 'Report generated. Set "Monthly report email" in Settings so it can be emailed.', 24 );
			return 'waiting for email address';
		}
		$res = NDWD_Reports::email_report( (int) $report->id );
		if ( is_wp_error( $res ) ) {
			self::finish( $job, $ym, 'failed', 'Email: ' . $res->get_error_message(), 3 );
			return 'failed';
		}
		if ( 'sent' !== $res['email_status'] ) {
			self::finish( $job, $ym, 'failed', 'Report generated but the email could not be sent: ' . $res['email_error'], 3 );
			return 'email failed';
		}
		self::finish( $job, $ym, 'done', 'Generated and emailed to ' . implode( ', ', NDWD_Reports::report_recipients() ) . '.' );
		return 'done';
	}

	/** Monthly retention cleanup (after the monthly report job had its chance). */
	public static function retention() {
		$now = NDWD_Helpers::now();
		if ( (int) $now->format( 'j' ) === 1 && (int) $now->format( 'G' ) < 2 ) {
			return 'waiting until 02:00 IST';
		}
		$period = $now->format( 'Y-m' );
		if ( ! self::claim( 'retention', $period ) ) {
			return 'not due';
		}
		$res = NDWD_Retention::run();
		$msg = NDWD_Retention::summary( $res );
		if ( $res['blocked'] ) {
			// Some months are kept until their reports are delivered; try again tomorrow.
			self::finish( 'retention', $period, 'waiting', $msg, 24 );
		} else {
			self::finish( 'retention', $period, 'done', $msg );
		}
		return $msg;
	}

	/** Daily: removes temporary export files and expired financial-year ZIPs. */
	public static function temp_cleanup() {
		$day = NDWD_Helpers::today();
		if ( ! self::claim( 'temp_cleanup', $day ) ) {
			return 'not due';
		}
		$n = NDWD_Files::clean_tmp( 2 ) + NDWD_Accounts::clean_expired_archives();
		self::finish( 'temp_cleanup', $day, 'done', $n . ' file(s) removed.' );
		return $n;
	}
}

/**
 * Rolling retention of operational history.
 *
 * Kept for N calendar months (Settings, minimum 6): attendance, activity log, completed
 * and archived tasks with their remarks/links, notifications, email log and stored
 * report snapshots. Each record is judged by its own date: on 1 July with 6 months,
 * records dated before 1 January are eligible; newer ones stay until their own turn.
 * A month's records are deleted only after that month's reports were emailed to the
 * Admin or the Admin confirmed saving them. Never touched: invoices, payments,
 * expenses, ledger entries, clients, teams, members, leave records, open tasks, and
 * tasks referenced by an invoice.
 */
class NDWD_Retention {

	public static function months() {
		$s = NDWD_Notifications::settings();
		return max( 6, isset( $s['retention_months'] ) ? (int) $s['retention_months'] : 6 );
	}

	/** First day that is kept. Everything dated before it is eligible. */
	public static function cutoff( $now = null ) {
		$now = $now ? $now : NDWD_Helpers::now();
		return $now->modify( 'first day of this month' )->modify( '-' . self::months() . ' months' )->format( 'Y-m-01' );
	}

	/** Months (YYYY-MM) before the cutoff that still hold operational data. */
	private static function eligible_months( $cutoff ) {
		global $wpdb;
		$t      = function ( $n ) {
			return NDWD_Helpers::table( $n );
		};
		$mins   = array(
			$wpdb->get_var( $wpdb->prepare( "SELECT MIN(att_date) FROM {$t('attendance')} WHERE att_date < %s", $cutoff ) ),
			$wpdb->get_var( $wpdb->prepare( "SELECT MIN(activity_date) FROM {$t('activity')} WHERE activity_date < %s", $cutoff ) ),
			$wpdb->get_var( $wpdb->prepare( "SELECT MIN(DATE(completed_at)) FROM {$t('tasks')} WHERE status = 'completed' AND completed_at < %s", $cutoff ) ),
			$wpdb->get_var( $wpdb->prepare( "SELECT MIN(DATE(updated_at)) FROM {$t('tasks')} WHERE is_archived = 1 AND updated_at < %s", $cutoff ) ),
			$wpdb->get_var( $wpdb->prepare( "SELECT MIN(DATE(created_at)) FROM {$t('notifications')} WHERE created_at < %s", $cutoff ) ),
			$wpdb->get_var( $wpdb->prepare( "SELECT MIN(DATE(created_at)) FROM {$t('email_log')} WHERE created_at < %s", $cutoff ) ),
			$wpdb->get_var( $wpdb->prepare( "SELECT MIN(CONCAT(period, '-01')) FROM {$t('reports')} WHERE purged_at IS NULL AND CONCAT(period, '-01') < %s", $cutoff ) ),
		);
		$mins = array_filter( $mins );
		if ( ! $mins ) {
			return array();
		}
		$out = array();
		$m   = new DateTimeImmutable( substr( min( $mins ), 0, 7 ) . '-01', NDWD_Helpers::tz() );
		while ( $m->format( 'Y-m-d' ) < $cutoff ) {
			$out[] = $m->format( 'Y-m' );
			$m     = $m->modify( '+1 month' );
		}
		return $out;
	}

	/** Task ids whose own date falls in [$from, $to] and that may be removed. */
	private static function removable_tasks( $from, $to ) {
		global $wpdb;
		$tasks = NDWD_Helpers::table( 'tasks' );
		$items = NDWD_Helpers::table( 'invoice_items' );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
			"SELECT t.id FROM $tasks t WHERE (
				(t.status = 'completed' AND DATE(t.completed_at) BETWEEN %s AND %s)
				OR (t.is_archived = 1 AND t.status <> 'completed' AND DATE(t.updated_at) BETWEEN %s AND %s)
			)
			AND NOT EXISTS (SELECT 1 FROM $items i WHERE i.task_id = t.id)
			AND NOT EXISTS (SELECT 1 FROM $tasks c WHERE c.parent_id = t.id AND c.is_archived = 0 AND c.status NOT IN ('completed','rejected'))",
			$from, $to, $from, $to
		) ) );
	}

	/** Counts (or deletes, when $apply) one month's eligible records. */
	private static function month( $ym, $apply ) {
		global $wpdb;
		$t     = function ( $n ) {
			return NDWD_Helpers::table( $n );
		};
		list( $from, $to ) = NDWD_Helpers::month_range( $ym );
		$counts            = array();
		$task_ids          = self::removable_tasks( $from, $to );
		$counts['tasks']   = count( $task_ids );
		$simple            = array(
			'attendance'    => array( 'attendance', 'att_date BETWEEN %s AND %s' ),
			'activity'      => array( 'activity', 'activity_date BETWEEN %s AND %s' ),
			'notifications' => array( 'notifications', 'DATE(created_at) BETWEEN %s AND %s' ),
			'email_log'     => array( 'email_log', 'DATE(created_at) BETWEEN %s AND %s' ),
		);
		foreach ( $simple as $key => $def ) {
			$sql            = 'FROM ' . $t( $def[0] ) . ' WHERE ' . $def[1];
			$counts[ $key ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) $sql", $from, $to ) ); // phpcs:ignore
			if ( $apply && $counts[ $key ] ) {
				$wpdb->query( $wpdb->prepare( "DELETE $sql", $from, $to ) ); // phpcs:ignore
			}
		}
		$reports            = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t('reports')} WHERE period = %s AND purged_at IS NULL AND status IN ('success','partial')", $ym ) );
		$counts['report_snapshots'] = count( $reports );
		if ( $apply ) {
			foreach ( $reports as $rid ) {
				NDWD_Reports::purge_files( (int) $rid );
			}
			foreach ( array_chunk( $task_ids, 200 ) as $chunk ) {
				$in = NDWD_Helpers::in_placeholders( $chunk );
				foreach ( array( 'task_assignees', 'task_tags', 'task_links', 'remarks', 'activity', 'notifications' ) as $child ) {
					$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . $t( $child ) . " WHERE task_id IN ($in)", $chunk ) ); // phpcs:ignore
				}
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$t('tasks')} WHERE id IN ($in)", $chunk ) ); // phpcs:ignore
			}
		}
		return $counts;
	}

	/** Deletes eligible records month by month, only for months whose reports were delivered. */
	public static function run( $apply = true ) {
		$cutoff = self::cutoff();
		$out    = array( 'cutoff' => $cutoff, 'months' => self::months(), 'cleaned' => array(), 'blocked' => array() );
		foreach ( self::eligible_months( $cutoff ) as $ym ) {
			if ( ! NDWD_Reports::delivered( $ym ) ) {
				$out['blocked'][ $ym ] = 'Kept: the reports for this month have not been emailed to the Admin or confirmed as saved yet.';
				if ( $apply ) {
					// Make sure the reports exist and try to deliver them; cleanup happens on a later run.
					NDWD_Jobs::claim( 'monthly_report', $ym ) && NDWD_Jobs::deliver_month( $ym, 'monthly_report' );
				}
				continue;
			}
			$out['cleaned'][ $ym ] = self::month( $ym, $apply );
		}
		$out['total'] = 0;
		foreach ( $out['cleaned'] as $c ) {
			$out['total'] += array_sum( $c );
		}
		if ( $apply ) {
			global $wpdb;
			// Job log rows older than 13 months.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . NDWD_Jobs::table() . " WHERE started_at < %s AND status IN ('done','gave_up')", NDWD_Helpers::now()->modify( '-13 months' )->format( 'Y-m-d H:i:s' ) ) );
		}
		return $out;
	}

	/** What the next cleanup would do (nothing is deleted). */
	public static function preview() {
		$r = self::run( false );
		return array(
			'months'  => $r['months'],
			'cutoff'  => $r['cutoff'],
			'cleaned' => $r['cleaned'],
			'blocked' => $r['blocked'],
			'total'   => $r['total'],
		);
	}

	public static function summary( $r ) {
		$parts = array( 'Cutoff ' . $r['cutoff'] . ': ' . $r['total'] . ' record(s) removed from ' . count( $r['cleaned'] ) . ' month(s)' );
		if ( $r['blocked'] ) {
			$parts[] = 'kept until delivered: ' . implode( ', ', array_keys( $r['blocked'] ) );
		}
		return implode( '; ', $parts ) . '.';
	}
}
