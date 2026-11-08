<?php
/**
 * Monthly archives (XLSX, CSV, PDF) with protected downloads.
 *
 * Every generation creates a new version folder (YYYY-MM/vN). Existing
 * archives are never overwritten. Each file's success or failure is recorded
 * individually; the run is "success" only when every file was written.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Reports {

	public static function init() {
		add_action( 'admin_post_ndwd_download', array( __CLASS__, 'download' ) );
		// No admin_post_nopriv_ handler: logged-out requests are rejected by WordPress.
	}

	/* ------------------------------------------------------------------ *
	 * Storage
	 * ------------------------------------------------------------------ */

	public static function base_dir() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . get_option( 'ndwd_reports_dir', 'ndwd-reports' );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		self::protect( $dir );
		return $dir;
	}

	public static function protect( $dir ) {
		$files = array(
			'.htaccess'  => "# Deny all direct access (Apache 2.2 and 2.4)\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
			'web.config' => '<?xml version="1.0" encoding="UTF-8"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
		);
		foreach ( $files as $name => $content ) {
			if ( ! file_exists( $dir . '/' . $name ) ) {
				@file_put_contents( $dir . '/' . $name, $content ); // phpcs:ignore
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * Generation
	 * ------------------------------------------------------------------ */

	public static function previous_month() {
		return NDWD_Helpers::now()->modify( 'first day of last month' )->format( 'Y-m' );
	}

	public static function has_success( $ym ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . NDWD_Helpers::table( 'reports' ) . " WHERE period = %s AND status IN ('success','partial')", $ym ) );
	}

	/**
	 * Generates the archive for month $ym.
	 *
	 * @return array|WP_Error Report record.
	 */
	public static function generate( $ym, $trigger = 'auto', $user_id = 0 ) {
		global $wpdb;
		$range = preg_match( '/^\d{4}-\d{2}$/', (string) $ym ) ? NDWD_Helpers::month_range( $ym ) : null;
		if ( ! $range ) {
			return new WP_Error( 'ndwd_invalid', 'Month must be in YYYY-MM format.', array( 'status' => 400 ) );
		}
		if ( $range[0] > NDWD_Helpers::today() ) {
			return new WP_Error( 'ndwd_invalid', 'Reports can only be generated for past or current months.', array( 'status' => 400 ) );
		}

		// Lock (add_option is atomic thanks to the unique option_name). Stale locks expire after 15 minutes.
		$lock = 'ndwd_report_lock_' . $ym;
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < 900 ) {
				return new WP_Error( 'ndwd_busy', 'A report for this month is already being generated.', array( 'status' => 409 ) );
			}
			update_option( $lock, time(), false );
		}

		@set_time_limit( 300 ); // phpcs:ignore
		$table   = NDWD_Helpers::table( 'reports' );
		$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM $table WHERE period = %s", $ym ) );
		$wpdb->insert( $table, array(
			'period'       => $ym,
			'version'      => $version,
			'status'       => 'running',
			'trigger_type' => $trigger,
			'generated_by' => $user_id ? (int) $user_id : null,
			'started_at'   => NDWD_Helpers::now_mysql(),
		) );
		$report_id = (int) $wpdb->insert_id;

		$files = array();
		$error = '';
		try {
			$files = self::build_files( $ym, $range, $version );
		} catch ( Throwable $e ) {
			$error = $e->getMessage();
		}
		$ok     = array_filter( $files, function ( $f ) {
			return 'ok' === $f['status'];
		} );
		$failed = array_filter( $files, function ( $f ) {
			return 'ok' !== $f['status'];
		} );
		if ( ! $files || ! $ok ) {
			$status = 'failed';
		} elseif ( $failed || $error ) {
			$status = 'partial';
		} else {
			$status = 'success';
		}
		if ( ! $error && $failed ) {
			$error = implode( '; ', array_unique( wp_list_pluck( $failed, 'error' ) ) );
		}
		$wpdb->update( $table, array(
			'status'      => $status,
			'files'       => wp_json_encode( array_values( $files ) ),
			'error'       => $error ? mb_substr( $error, 0, 2000 ) : null,
			'finished_at' => NDWD_Helpers::now_mysql(),
		), array( 'id' => $report_id ) );
		delete_option( $lock );

		if ( 'failed' !== $status ) {
			self::notify_available( $ym, $version, $report_id );
		}
		return self::get( $report_id );
	}

	private static function build_files( $ym, $range, $version ) {
		global $wpdb;
		list( $from, $to ) = $range;
		$dir_rel = $ym . '/v' . $version;
		$dir     = self::base_dir() . '/' . $dir_rel;
		if ( ! wp_mkdir_p( $dir . '/members' ) ) {
			throw new Exception( 'Could not create the report folder. Check that wp-content/uploads is writable.' );
		}
		self::protect( $dir );

		// Members: everyone active plus anyone assigned to in-scope work.
		$member_ids = wp_list_pluck( NDWD_Stats::active_members(), 'ID' );
		$prod       = NDWD_Stats::productivity( $from, $to, array(), array(), 0, true );
		foreach ( $prod['rows'] as $t ) {
			$member_ids = array_merge( $member_ids, $t->assignee_ids );
		}
		$member_ids = array_values( array_unique( array_map( 'intval', $member_ids ) ) );
		$prod       = NDWD_Stats::productivity( $from, $to, $member_ids, array(), 0, true );
		$rows       = $prod['rows'];

		$lookups = array(
			'teams'    => NDWD_Stats::lookup( 'teams' ),
			'projects' => self::projects(),
			'tags'     => NDWD_Stats::lookup( 'tags' ),
			'clients'  => NDWD_Stats::lookup( 'clients' ),
		);
		$task_ids = array_map( function ( $t ) {
			return (int) $t->id;
		}, $rows );

		$links   = array();
		$remarks = array();
		if ( $task_ids ) {
			$in = NDWD_Helpers::in_placeholders( $task_ids );
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT task_id, url, label FROM ' . NDWD_Helpers::table( 'task_links' ) . " WHERE task_id IN ($in)", $task_ids ) ) as $l ) {
				$links[ $l->task_id ][] = ( $l->label ? $l->label . ': ' : '' ) . $l->url;
			}
			$remarks = $wpdb->get_results( $wpdb->prepare( 'SELECT r.*, t.title FROM ' . NDWD_Helpers::table( 'remarks' ) . ' r JOIN ' . NDWD_Helpers::table( 'tasks' ) . " t ON t.id = r.task_id WHERE r.task_id IN ($in) AND DATE(r.created_at) BETWEEN %s AND %s ORDER BY r.created_at", array_merge( $task_ids, array( $from, $to ) ) ) );
		}
		$activity   = NDWD_Activity::recent( array( 'from' => $from, 'to' => $to, 'limit' => 5000 ) );
		$attendance = array_map( array( 'NDWD_Attendance', 'format_row' ), NDWD_Attendance::rows( $from, $to ) );

		$task_header = array( 'ID', 'Task / Activity', 'Description', 'Status', 'Priority', 'Work type', 'Project', 'Client', 'Team', 'Assigned to', 'Tags', 'Scheduled', 'Due date', 'Due time', 'Completed at', 'Created by', 'Created at', 'Follow-up of', 'Carried forward', 'Overdue', 'Links', 'Remarks' );
		$remark_cnt  = array();
		foreach ( $remarks as $r ) {
			$remark_cnt[ $r->task_id ] = isset( $remark_cnt[ $r->task_id ] ) ? $remark_cnt[ $r->task_id ] + 1 : 1;
		}
		$task_row = function ( $t ) use ( $lookups, $links, $remark_cnt, $from, $to ) {
			$p         = $t->project_id && isset( $lookups['projects'][ $t->project_id ] ) ? $lookups['projects'][ $t->project_id ] : array( '', '' );
			if ( ! empty( $t->client_id ) && isset( $lookups['clients'][ $t->client_id ] ) ) {
				$p[1] = $lookups['clients'][ $t->client_id ];
			}
			$done      = NDWD_Helpers::closed_date( $t );
			$open_end  = ! $done || $done > $to;
			$carried   = $t->scheduled_date < $to && ( ! $done || $done > max( $from, $t->scheduled_date ) );
			return array(
				(int) $t->id,
				$t->title,
				(string) $t->description,
				$open_end ? ( NDWD_Helpers::is_closed( $t->status ) ? 'Open at month end (' . strtolower( NDWD_Helpers::status_label( $t->status ) ) . ' ' . $done . ')' : NDWD_Helpers::status_label( $t->status ) ) : NDWD_Helpers::status_label( $t->status ),
				NDWD_Helpers::priority_label( $t->priority ),
				NDWD_Helpers::work_type_label( $t->work_type ),
				$p[0],
				$p[1],
				$t->team_id && isset( $lookups['teams'][ $t->team_id ] ) ? $lookups['teams'][ $t->team_id ] : '',
				implode( ', ', array_map( array( 'NDWD_Helpers', 'user_name' ), $t->assignee_ids ) ),
				implode( ', ', array_filter( array_map( function ( $id ) use ( $lookups ) {
					return isset( $lookups['tags'][ $id ] ) ? $lookups['tags'][ $id ] : '';
				}, $t->tag_ids ) ) ),
				$t->scheduled_date,
				(string) $t->due_date,
				$t->due_time ? substr( $t->due_time, 0, 5 ) : '',
				(string) $t->completed_at,
				NDWD_Helpers::user_name( $t->created_by ),
				$t->created_at,
				$t->parent_id ? '#' . $t->parent_id : '',
				$carried ? 'Yes' : 'No',
				$open_end && $t->due_date && $t->due_date < min( $to, NDWD_Helpers::today() ) ? 'Yes' : 'No',
				isset( $links[ $t->id ] ) ? implode( "\n", $links[ $t->id ] ) : '',
				isset( $remark_cnt[ $t->id ] ) ? $remark_cnt[ $t->id ] : 0,
			);
		};
		$all_rows = array_map( $task_row, $rows );
		$by_col   = function ( $col, $value, $neg = false ) use ( $all_rows ) {
			return array_values( array_filter( $all_rows, function ( $r ) use ( $col, $value, $neg ) {
				return $neg ? $r[ $col ] !== $value : $r[ $col ] === $value;
			} ) );
		};

		$prod_header = array( 'Member', 'Tasks assigned (in scope)', 'Completed', 'Open at month end', 'Pending', 'In Process', 'Rejected', 'Overdue', 'Carry-forward', 'Completion %', 'Attendance days', 'Active days', 'Login count', 'Activity count', 'Leave days' );
		$prod_rows   = array();
		foreach ( $prod['members'] as $m ) {
			$prod_rows[] = array( $m['name'], $m['in_scope'], $m['completed'], $m['open_at_end'], $m['pending'], $m['in_process'], $m['rejected'], $m['overdue'], $m['carry_forward'], $m['completion_pct'], $m['attendance_days'], $m['active_days'], $m['login_count'], $m['activity_count'], $m['leave_days'] );
		}
		$tm          = $prod['team'];
		$prod_rows[] = array( 'TEAM TOTAL (distinct tasks)', $tm['in_scope'], $tm['completed'], $tm['open_at_end'], $tm['pending'], $tm['in_process'], $tm['rejected'], $tm['overdue'], $tm['carry_forward'], $tm['completion_pct'], '', '', '', '', '', '' );

		$att_header  = array( 'Date', 'Member', 'First login', 'Last login', 'Login count', 'Activity count', 'First activity', 'Last activity' );
		$att_rows    = array_map( function ( $a ) {
			return array( $a['date'], $a['name'], (string) $a['first_login'], (string) $a['last_login'], $a['login_count'], $a['activity_count'], (string) $a['first_activity'], (string) $a['last_activity'] );
		}, $attendance );
		$act_header  = array( 'Time', 'Member', 'Action', 'Task ID', 'Task', 'Details' );
		$act_rows    = array_map( function ( $a ) {
			return array( $a['created_at'], $a['user'], $a['label'], $a['task_id'] ? $a['task_id'] : '', (string) $a['task_title'], self::details_text( $a['details'] ) );
		}, array_reverse( $activity ) );
		$rem_header  = array( 'Time', 'Task ID', 'Task', 'Author', 'Remark' );
		$rem_rows    = array_map( function ( $r ) {
			return array( $r->created_at, (int) $r->task_id, $r->title, NDWD_Helpers::user_name( $r->user_id ), $r->body );
		}, $remarks );

		$prefix = 'neondigital-workdesk-' . $ym;
		$out    = array();
		$record = function ( $name, $type, $result, $user_id = null, $label = '' ) use ( &$out, $dir, $dir_rel ) {
			$ok    = true === $result;
			$out[] = array(
				'name'    => $name,
				'path'    => $dir_rel . '/' . $name,
				'type'    => $type,
				'label'   => $label ? $label : $name,
				'user_id' => $user_id,
				'status'  => $ok ? 'ok' : 'failed',
				'size'    => $ok && file_exists( $dir . '/' . $name ) ? filesize( $dir . '/' . $name ) : 0,
				'error'   => $ok ? '' : ( is_wp_error( $result ) ? $result->get_error_message() : 'Unknown error' ),
			);
		};

		// Excel workbook.
		$x = new NDWD_XLSX_Writer();
		$x->add_sheet( 'Summary', array( 'Metric', 'Value' ), self::summary_pairs( $ym, $from, $to, $tm, $prod ) );
		$x->add_sheet( 'All Tasks', $task_header, $all_rows );
		$x->add_sheet( 'Completed', $task_header, $by_col( 3, 'Completed' ) );
		$x->add_sheet( 'Pending', $task_header, $by_col( 3, 'Completed', true ) );
		$x->add_sheet( 'Overdue', $task_header, $by_col( 19, 'Yes' ) );
		$x->add_sheet( 'Follow-ups', $task_header, $by_col( 17, '', true ) );
		$x->add_sheet( 'Remarks', $rem_header, $rem_rows );
		$x->add_sheet( 'Productivity', $prod_header, $prod_rows );
		$x->add_sheet( 'Attendance', $att_header, $att_rows );
		$x->add_sheet( 'Activity History', $act_header, $act_rows );
		$leave_rows = array_map( function ( $l ) {
			return array( (int) $l->id, NDWD_Helpers::user_name( $l->user_id ), isset( NDWD_Leaves::TYPES[ $l->leave_type ] ) ? NDWD_Leaves::TYPES[ $l->leave_type ] : $l->leave_type, $l->start_date, $l->end_date, NDWD_Leaves::days( $l->start_date, $l->end_date ), NDWD_Leaves::STATUSES[ $l->status ], (string) $l->reason, $l->reviewed_by ? NDWD_Helpers::user_name( $l->reviewed_by ) : '', (string) $l->reviewed_at, (string) $l->review_note, (string) $l->cancelled_at );
		}, NDWD_Leaves::rows_between( $from, $to ) );
		$x->add_sheet( 'Leave', array( 'ID', 'Member', 'Type', 'Start', 'End', 'Days', 'Status', 'Reason', 'Reviewed by', 'Reviewed at', 'Review note', 'Cancelled at' ), $leave_rows );
		$x->add_sheet( 'Definitions', array( 'Term', 'Definition' ), array_map( null, array_keys( NDWD_Stats::DEFINITIONS ), array_values( NDWD_Stats::DEFINITIONS ) ) );
		$record( $prefix . '-team.xlsx', 'xlsx', $x->save( $dir . '/' . $prefix . '-team.xlsx' ), null, 'Team workbook (Excel)' );

		// CSVs.
		$record( $prefix . '-team-tasks.csv', 'csv', self::csv( $dir . '/' . $prefix . '-team-tasks.csv', $task_header, $all_rows ), null, 'All tasks (CSV)' );
		$record( $prefix . '-team-productivity.csv', 'csv', self::csv( $dir . '/' . $prefix . '-team-productivity.csv', $prod_header, $prod_rows ), null, 'Productivity (CSV)' );
		$record( $prefix . '-team-attendance.csv', 'csv', self::csv( $dir . '/' . $prefix . '-team-attendance.csv', $att_header, $att_rows ), null, 'Attendance (CSV)' );
		$record( $prefix . '-team-activity.csv', 'csv', self::csv( $dir . '/' . $prefix . '-team-activity.csv', $act_header, $act_rows ), null, 'Activity history (CSV)' );

		// Team PDF.
		$record( $prefix . '-team.pdf', 'pdf', self::team_pdf( $dir . '/' . $prefix . '-team.pdf', $ym, $from, $to, $prod, $all_rows, $attendance ), null, 'Team report (PDF)' );

		// Individual reports.
		foreach ( $prod['members'] as $m ) {
			$mine = array_values( array_filter( $rows, function ( $t ) use ( $m ) {
				return in_array( (int) $m['user_id'], $t->assignee_ids, true );
			} ) );
			$m_att = array_values( array_filter( $attendance, function ( $a ) use ( $m ) {
				return (int) $a['user_id'] === (int) $m['user_id'];
			} ) );
			if ( ! $mine && ! $m_att ) {
				continue;
			}
			$u    = get_userdata( $m['user_id'] );
			$slug = sanitize_file_name( $u ? $u->user_login : 'member-' . $m['user_id'] );
			$name = 'members/' . $prefix . '-' . $slug;
			$mrows = array_map( $task_row, $mine );
			$record( $name . '.csv', 'csv', self::csv( $dir . '/' . $name . '.csv', $task_header, $mrows ), (int) $m['user_id'], $m['name'] . ' — tasks (CSV)' );
			$record( $name . '.pdf', 'pdf', self::member_pdf( $dir . '/' . $name . '.pdf', $ym, $m, $mrows, $m_att ), (int) $m['user_id'], $m['name'] . ' — report (PDF)' );
		}
		return $out;
	}

	private static function projects() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( 'SELECT id, name, client FROM ' . NDWD_Helpers::table( 'projects' ) ) as $p ) { // phpcs:ignore
			$out[ (int) $p->id ] = array( $p->name, $p->client );
		}
		return $out;
	}

	private static function details_text( $d ) {
		if ( ! $d ) {
			return '';
		}
		$parts = array();
		foreach ( $d as $k => $v ) {
			if ( is_array( $v ) && isset( $v['from'] ) ) {
				$v = ( is_array( $v['from'] ) ? implode( ', ', $v['from'] ) : $v['from'] ) . ' → ' . ( is_array( $v['to'] ) ? implode( ', ', $v['to'] ) : $v['to'] );
			} elseif ( is_array( $v ) ) {
				$v = implode( ', ', array_map( 'strval', $v ) );
			}
			$parts[] = $k . ': ' . $v;
		}
		return implode( '; ', $parts );
	}

	private static function summary_pairs( $ym, $from, $to, $tm, $prod ) {
		return array(
			array( 'Reporting month', $ym ),
			array( 'Period', $from . ' to ' . $to . ' (Asia/Kolkata)' ),
			array( 'Generated at', NDWD_Helpers::now_mysql() . ' IST' ),
			array( 'Tasks in scope (distinct)', $tm['in_scope'] ),
			array( 'Completed in month', $tm['completed'] ),
			array( 'Open at month end', $tm['open_at_end'] ),
			array( 'Pending', $tm['pending'] ),
			array( 'In Process', $tm['in_process'] ),
			array( 'Rejected', $tm['rejected'] ),
			array( 'Overdue at month end', $tm['overdue'] ),
			array( 'Carry-forward tasks', $tm['carry_forward'] ),
			array( 'Team completion %', $tm['completion_pct'] ),
			array( 'Members reported', count( $prod['members'] ) ),
		);
	}

	/** Writes a UTF-8 CSV (with BOM for Excel), neutralising spreadsheet formula injection. */
	public static function csv( $path, $header, $rows ) {
		$fh = @fopen( $path, 'w' ); // phpcs:ignore
		if ( ! $fh ) {
			return new WP_Error( 'ndwd_csv', 'Could not write ' . basename( $path ) );
		}
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, $header );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'csv_safe' ), array_values( (array) $r ) ) );
		}
		fclose( $fh );
		return true;
	}

	public static function csv_safe( $v ) {
		if ( is_string( $v ) && '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $v;
		}
		return $v;
	}

	private static function team_pdf( $path, $ym, $from, $to, $prod, $all_rows, $attendance ) {
		$month = DateTimeImmutable::createFromFormat( '!Y-m', $ym )->format( 'F Y' );
		$pdf   = new NDWD_PDF_Writer( 'Monthly report — ' . $month );
		$tm    = $prod['team'];
		$pdf->heading( 'Team report: ' . $month, 20 );
		$pdf->paragraph( 'Period ' . $from . ' to ' . $to . ' (Asia/Kolkata). Team figures count each task once even when it has several assignees.' );
		$pdf->stats( array(
			array( 'Tasks in scope', $tm['in_scope'] ),
			array( 'Completed', $tm['completed'] ),
			array( 'Open at month end', $tm['open_at_end'] ),
			array( 'Overdue', $tm['overdue'] ),
			array( 'Carry-forward', $tm['carry_forward'] ),
			array( 'Completion', $tm['completion_pct'] . '%' ),
		) );
		$pdf->stats( array(
			array( 'Pending', $tm['pending'] ),
			array( 'In Process', $tm['in_process'] ),
			array( 'Rejected', $tm['rejected'] ),
			array( 'Urgent (open)', $tm['urgent_open'] ),
		) );
		$pdf->heading( 'How figures are calculated', 12 );
		foreach ( NDWD_Stats::DEFINITIONS as $d ) {
			$pdf->paragraph( '• ' . $d, 8.5 );
		}

		$pdf->heading( 'Individual productivity & attendance', 14 );
		$rows = array();
		foreach ( $prod['members'] as $m ) {
			$rows[] = array( $m['name'], $m['in_scope'], $m['completed'], $m['open_at_end'], $m['overdue'], $m['carry_forward'], $m['completion_pct'] . '%', $m['attendance_days'], $m['active_days'], $m['login_count'], $m['activity_count'] );
		}
		$pdf->table( array( 'Member', 'Assigned', 'Completed', 'Open', 'Overdue', 'Carried', 'Completion', 'Att. days', 'Active days', 'Logins', 'Activities' ), $rows, array( 3, 1, 1, 1, 1, 1, 1.1, 1, 1.1, 1, 1.1 ) );

		$pdf->heading( 'Workload by team and project', 14 );
		$wl = array();
		foreach ( $prod['by_team'] as $b ) {
			$wl[] = array( 'Team', $b['name'], $b['in_scope'], $b['completed'], $b['open'] );
		}
		foreach ( $prod['by_project'] as $b ) {
			$wl[] = array( 'Project', $b['name'], $b['in_scope'], $b['completed'], $b['open'] );
		}
		$pdf->table( array( 'Type', 'Name', 'In scope', 'Completed', 'Open' ), $wl, array( 1, 4, 1, 1, 1 ) );

		$pdf->heading( 'Attendance log', 14 );
		$pdf->table( array( 'Date', 'Member', 'First login', 'Last login', 'Logins', 'Activities' ), array_map( function ( $a ) {
			return array( $a['date'], $a['name'], (string) $a['first_login'], (string) $a['last_login'], $a['login_count'], $a['activity_count'] );
		}, array_reverse( $attendance ) ), array( 1.2, 3, 1, 1, 1, 1 ) );

		$pdf->add_page();
		$pdf->heading( 'Task details', 14 );
		$pdf->table( array( 'ID', 'Task / Activity', 'Assigned to', 'Status', 'Priority', 'Work type', 'Scheduled', 'Due', 'Completed', 'Carried' ), array_map( function ( $r ) {
			return array( $r[0], $r[1], $r[9], $r[3], $r[4], $r[5], $r[11], trim( $r[12] . ' ' . $r[13] ), substr( (string) $r[14], 0, 16 ), $r[18] );
		}, $all_rows ), array( 0.6, 4, 2.2, 1.1, 1, 1.2, 1.2, 1.4, 1.4, 0.8 ), 7.5 );
		return $pdf->save( $path );
	}

	private static function member_pdf( $path, $ym, $m, $rows, $att ) {
		$month = DateTimeImmutable::createFromFormat( '!Y-m', $ym )->format( 'F Y' );
		$pdf   = new NDWD_PDF_Writer( $m['name'] . ' — ' . $month );
		$pdf->heading( $m['name'] . ': ' . $month, 18 );
		$pdf->stats( array(
			array( 'Assigned', $m['in_scope'] ),
			array( 'Completed', $m['completed'] ),
			array( 'Open', $m['open_at_end'] ),
			array( 'Overdue', $m['overdue'] ),
			array( 'Carry-forward', $m['carry_forward'] ),
			array( 'Completion', $m['completion_pct'] . '%' ),
		) );
		$pdf->stats( array(
			array( 'Attendance days', $m['attendance_days'] ),
			array( 'Active days', $m['active_days'] ),
			array( 'Logins', $m['login_count'] ),
			array( 'Meaningful activities', $m['activity_count'] ),
		) );
		$pdf->paragraph( NDWD_Stats::DEFINITIONS['completion'] . ' ' . NDWD_Stats::DEFINITIONS['attendance'], 8.5 );
		$pdf->heading( 'Tasks', 14 );
		$pdf->table( array( 'ID', 'Task / Activity', 'Status', 'Priority', 'Work type', 'Scheduled', 'Due', 'Completed', 'Carried' ), array_map( function ( $r ) {
			return array( $r[0], $r[1], $r[3], $r[4], $r[5], $r[11], trim( $r[12] . ' ' . $r[13] ), substr( (string) $r[14], 0, 16 ), $r[18] );
		}, $rows ), array( 0.6, 5, 1.1, 1, 1.2, 1.2, 1.4, 1.4, 0.8 ), 8 );
		$pdf->heading( 'Attendance', 14 );
		$pdf->table( array( 'Date', 'First login', 'Last login', 'Logins', 'Activities' ), array_map( function ( $a ) {
			return array( $a['date'], (string) $a['first_login'], (string) $a['last_login'], $a['login_count'], $a['activity_count'] );
		}, array_reverse( $att ) ) );
		return $pdf->save( $path );
	}

	private static function notify_available( $ym, $version, $report_id ) {
		$users = get_users( array( 'capability__in' => array( 'ndwd_manage_reports' ), 'fields' => 'ID' ) );
		$msg   = 'Monthly report for ' . $ym . ( $version > 1 ? ' (version ' . $version . ')' : '' ) . ' is ready to download.';
		foreach ( $users as $uid ) {
			NDWD_Notifications::notify_users( array( (int) $uid ), null, 'monthly_report', $msg, 0, 'report:' . $ym . ':v' . $version );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . NDWD_Helpers::table( 'notifications' ) . " SET link = '#/archives' WHERE type = 'monthly_report' AND dedupe_key LIKE %s", 'report:' . $wpdb->esc_like( $ym . ':v' . $version ) . ':%' ) );
	}

	/** Who receives the monthly report email: Settings → "Monthly report email", else the site admin email. */
	public static function report_recipients() {
		$s    = NDWD_Notifications::settings();
		$list = array_filter( array_map( 'trim', explode( ',', isset( $s['report_email'] ) ? (string) $s['report_email'] : '' ) ), 'is_email' );
		if ( ! $list && is_email( get_option( 'admin_email' ) ) ) {
			$list = array( get_option( 'admin_email' ) );
		}
		return array_values( array_unique( $list ) );
	}

	/** Attachments above this size are replaced by a secure ZIP download link. */
	const ATTACH_LIMIT = 7340032; // 7 MB

	/**
	 * Emails a report pack to the configured Admin address(es): the team workbook, team
	 * PDF and team CSVs attached when they fit, plus a signed link (30 days) to a ZIP of
	 * every file including member reports. Records the outcome on the report.
	 *
	 * @return array|WP_Error Updated report.
	 */
	public static function email_report( $report_id, $manual = false ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'reports' );
		$r     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $report_id ) );
		if ( ! $r || ! in_array( $r->status, array( 'success', 'partial' ), true ) ) {
			return new WP_Error( 'ndwd_not_found', 'Only a generated report can be emailed.', array( 'status' => 404 ) );
		}
		$to = self::report_recipients();
		if ( ! $to ) {
			$wpdb->update( $table, array( 'email_status' => 'failed', 'email_error' => 'No report email address is configured.', 'email_attempts' => (int) $r->email_attempts + 1 ), array( 'id' => $report_id ) );
			return new WP_Error( 'ndwd_invalid', 'Set "Monthly report email" in Settings first.', array( 'status' => 400 ) );
		}
		$files  = json_decode( (string) $r->files, true );
		$attach = array();
		$size   = 0;
		$names  = array();
		foreach ( (array) $files as $f ) {
			if ( 'ok' !== $f['status'] || ! empty( $f['user_id'] ) ) {
				continue;
			}
			$path = NDWD_Files::path( $f['path'] );
			if ( $path && $size + filesize( $path ) <= self::ATTACH_LIMIT ) {
				$attach[] = $path;
				$size    += filesize( $path );
				$names[]  = $f['label'];
			}
		}
		$month = DateTimeImmutable::createFromFormat( '!Y-m', $r->period )->format( 'F Y' );
		$link  = NDWD_Files::signed_url( 'report_zip', $report_id, 30 );
		$body  = '<p style="color:#374151">The WorkDesk reports for <b>' . esc_html( $month ) . '</b> (' . esc_html( $r->period ) . ', version ' . (int) $r->version . ') were generated on ' . esc_html( $r->finished_at ) . ' IST.</p>'
			. ( $attach ? '<p style="color:#374151">Attached: ' . esc_html( implode( ', ', $names ) ) . '.</p>' : '<p style="color:#374151">The files are too large to attach, so use the download link below.</p>' )
			. '<p style="color:#374151">Download the complete pack (work and tasks, team performance, member-wise reports, attendance, leave and activity) as a ZIP. The link works for 30 days and does not need a login, so do not forward this email.</p>'
			. '<p style="color:#6b7280;font-size:12px">Operational history older than the retention period is removed only after this month\'s reports have been delivered. If you keep these files, nothing is lost.</p>';
		$html  = NDWD_Notifications::template( 'Monthly reports: ' . $month, $body, $link, 'Download all reports (ZIP)' );
		$sent  = 0;
		$errs  = array();
		$attempt = (int) $r->email_attempts + 1;
		foreach ( $to as $email ) {
			$res = NDWD_Notifications::send_to( $email, '[WorkDesk] Monthly reports for ' . $month, $html, 'monthly_report', 'report-mail:' . $report_id . ':' . md5( $email ) . ( $manual ? ':m' . time() : '' ), $attach );
			if ( true === $res || 'duplicate' === $res ) {
				$sent++;
			} else {
				$errs[] = $email . ': ' . NDWD_Notifications::last_error();
			}
		}
		$ok = $sent === count( $to );
		$wpdb->update( $table, array(
			'email_status'   => $ok ? 'sent' : ( $sent ? 'partial' : 'failed' ),
			'email_error'    => $errs ? mb_substr( implode( '; ', $errs ), 0, 2000 ) : null,
			'email_attempts' => $attempt,
			'emailed_at'     => $ok ? NDWD_Helpers::now_mysql() : $r->emailed_at,
		), array( 'id' => $report_id ) );
		return self::get( $report_id );
	}

	/** Admin confirms the reports for this month are saved elsewhere. */
	public static function confirm( $report_id, $user_id ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'reports' );
		if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE id = %d AND status IN ('success','partial')", $report_id ) ) ) {
			return new WP_Error( 'ndwd_not_found', 'Report not found.', array( 'status' => 404 ) );
		}
		$wpdb->update( $table, array( 'confirmed_at' => NDWD_Helpers::now_mysql(), 'confirmed_by' => (int) $user_id ), array( 'id' => $report_id ) );
		return self::get( $report_id );
	}

	/**
	 * Whether month $ym is safely preserved outside the live data: a successful report
	 * exists and it was emailed to the Admin or the Admin confirmed saving it.
	 */
	public static function delivered( $ym ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . NDWD_Helpers::table( 'reports' ) . " WHERE period = %s AND status IN ('success','partial') AND ( email_status = 'sent' OR confirmed_at IS NOT NULL )",
			$ym
		) );
	}

	/** Streams a ZIP of every file in a report (signed link from the monthly email). */
	public static function stream_zip( $report_id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'reports' ) . " WHERE id = %d AND status IN ('success','partial') AND purged_at IS NULL", $report_id ) );
		if ( ! $r || ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ndwd_not_found', 'These reports are no longer stored on the server.' );
		}
		$files = json_decode( (string) $r->files, true );
		$name  = 'neondigital-workdesk-' . $r->period . '-v' . (int) $r->version . '-reports.zip';
		return NDWD_Files::send_temp( $name, function ( $path ) use ( $files ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				return new WP_Error( 'ndwd_zip', 'Could not create the ZIP file.' );
			}
			foreach ( (array) $files as $f ) {
				$p = 'ok' === $f['status'] ? NDWD_Files::path( $f['path'] ) : null;
				if ( $p ) {
					$zip->addFile( $p, $f['name'] );
				}
			}
			$zip->close();
			return true;
		} );
	}

	/** Deletes the stored files of a report (retention). The record and its log stay. */
	public static function purge_files( $report_id ) {
		global $wpdb;
		$table = NDWD_Helpers::table( 'reports' );
		$r     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $report_id ) );
		if ( ! $r || $r->purged_at ) {
			return 0;
		}
		$n = 0;
		foreach ( (array) json_decode( (string) $r->files, true ) as $f ) {
			$p = NDWD_Files::path( $f['path'] );
			if ( $p && @unlink( $p ) ) { // phpcs:ignore
				$n++;
			}
		}
		$wpdb->update( $table, array( 'purged_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $report_id ) );
		return $n;
	}

	/* ------------------------------------------------------------------ */

	public static function get( $id ) {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'reports' ) . ' WHERE id = %d', $id ) );
		return $r ? self::format( $r ) : null;
	}

	public static function format( $r ) {
		$files = json_decode( (string) $r->files, true );
		$files = is_array( $files ) ? $files : array();
		$uid   = get_current_user_id();
		$all   = NDWD_Permissions::can( 'ndwd_manage_reports' );
		$list  = array();
		foreach ( $files as $i => $f ) {
			if ( ! $all && (int) $f['user_id'] !== $uid ) {
				continue;
			}
			$f['index'] = $i;
			// Downloaded through the REST route with fetch() (see WD.download in core.js).
			$f['download'] = 'ok' === $f['status'] && ! $r->purged_at ? 'archives/' . (int) $r->id . '/files/' . $i : null;
			unset( $f['path'] );
			$list[] = $f;
		}
		return array(
			'id'           => (int) $r->id,
			'period'       => $r->period,
			'version'      => (int) $r->version,
			'status'       => $r->status,
			'trigger'      => $r->trigger_type,
			'generated_by' => $r->generated_by ? NDWD_Helpers::user_name( $r->generated_by ) : 'Scheduler',
			'started_at'   => $r->started_at,
			'finished_at'  => $r->finished_at,
			'error'        => $r->error,
			'files'        => $list,
			'email_status' => isset( $r->email_status ) ? $r->email_status : null,
			'email_error'  => isset( $r->email_error ) ? $r->email_error : null,
			'emailed_at'   => isset( $r->emailed_at ) ? $r->emailed_at : null,
			'confirmed_at' => isset( $r->confirmed_at ) ? $r->confirmed_at : null,
			'purged_at'    => isset( $r->purged_at ) ? $r->purged_at : null,
		);
	}

	public static function all() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . NDWD_Helpers::table( 'reports' ) . ' ORDER BY period DESC, version DESC LIMIT 120' ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			$f = self::format( $r );
			if ( $f['files'] || NDWD_Permissions::can( 'ndwd_manage_reports' ) ) {
				$out[] = $f;
			}
		}
		return $out;
	}

	/** REST: streams one archive file after the same checks as the legacy download. */
	public static function stream_file( $report_id, $index ) {
		global $wpdb;
		$r     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'reports' ) . ' WHERE id = %d', $report_id ) );
		$files = $r ? json_decode( (string) $r->files, true ) : array();
		if ( ! $r || ! isset( $files[ $index ] ) || 'ok' !== $files[ $index ]['status'] ) {
			return new WP_Error( 'ndwd_not_found', 'File not found.', array( 'status' => 404 ) );
		}
		$f = $files[ $index ];
		if ( ! NDWD_Permissions::can( 'ndwd_manage_reports' ) && (int) $f['user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'ndwd_forbidden', 'You do not have permission to download this file.', array( 'status' => 403 ) );
		}
		$path = $r->purged_at ? null : NDWD_Files::path( $f['path'] );
		if ( ! $path ) {
			return new WP_Error( 'ndwd_not_found', $r->purged_at ? 'This report was removed from the server under the data retention policy.' : 'The file is missing from storage. Generate the report again.', array( 'status' => 404 ) );
		}
		NDWD_Files::send_file( $path, basename( $f['name'] ) );
	}

	/** Legacy admin-post download (links created before 1.2). Authenticated and capability-checked. */
	public static function download() {
		$report_id = isset( $_GET['report'] ) ? absint( $_GET['report'] ) : 0; // phpcs:ignore
		$index     = isset( $_GET['file'] ) ? absint( $_GET['file'] ) : -1; // phpcs:ignore
		if ( ! is_user_logged_in() || ! NDWD_Permissions::can( 'ndwd_access' ) ) {
			wp_die( 'Please sign in.', 'Forbidden', array( 'response' => 403 ) );
		}
		check_admin_referer( 'ndwd_download_' . $report_id );
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . NDWD_Helpers::table( 'reports' ) . ' WHERE id = %d', $report_id ) );
		$files = $r ? json_decode( (string) $r->files, true ) : array();
		if ( ! $r || ! isset( $files[ $index ] ) || 'ok' !== $files[ $index ]['status'] ) {
			wp_die( 'File not found.', 'Not found', array( 'response' => 404 ) );
		}
		$f = $files[ $index ];
		if ( ! NDWD_Permissions::can( 'ndwd_manage_reports' ) && (int) $f['user_id'] !== get_current_user_id() ) {
			wp_die( 'You do not have permission to download this file.', 'Forbidden', array( 'response' => 403 ) );
		}
		$base = realpath( self::base_dir() );
		$path = realpath( self::base_dir() . '/' . $f['path'] );
		if ( ! $base || ! $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) || ! is_readable( $path ) ) {
			wp_die( 'File is missing from storage.', 'Not found', array( 'response' => 404 ) );
		}
		$types = array( 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=utf-8', 'pdf' => 'application/pdf' );
		nocache_headers();
		header( 'Content-Type: ' . ( isset( $types[ $f['type'] ] ) ? $types[ $f['type'] ] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . basename( $f['name'] ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		readfile( $path ); // phpcs:ignore
		exit;
	}
}
