<?php
/**
 * CSV worksheet import: preview → column mapping → member mapping → validation → commit.
 *
 * The CSV is read in the browser and posted as text, so nothing is stored on
 * disk. Rows are never silently discarded: blank, invalid and duplicate rows
 * are all reported back with their row numbers and reasons.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Import {

	const FIELDS = array(
		'title'          => 'Task / Activity',
		'assignees'      => 'Assigned To',
		'priority'       => 'Priority',
		'due_time'       => 'Due Time',
		'status'         => 'Status',
		'report'         => 'Report',
		'remarks'        => 'Remarks',
		'scheduled_date' => 'Date / Scheduled',
		'due_date'       => 'Due Date',
		'description'    => 'Description',
		'project'        => 'Project / Client',
		'work_type'      => 'Work Type',
	);

	const MAX_BYTES = 2097152;

	private static $synonyms = array(
		'title'          => array( 'task / activity', 'task/activity', 'task', 'activity', 'task name', 'title', 'work' ),
		'assignees'      => array( 'assigned to', 'assigned', 'assignee', 'owner', 'responsible', 'member' ),
		'priority'       => array( 'priority' ),
		'due_time'       => array( 'due time', 'time', 'deadline time' ),
		'status'         => array( 'status', 'state' ),
		'report'         => array( 'report', 'update', 'progress' ),
		'remarks'        => array( 'remarks', 'remark', 'comments', 'comment', 'notes', 'note' ),
		'scheduled_date' => array( 'date', 'scheduled', 'scheduled date', 'task date' ),
		'due_date'       => array( 'due date', 'deadline', 'due' ),
		'description'    => array( 'description', 'details' ),
		'project'        => array( 'project', 'client', 'project / client', 'client / project' ),
		'work_type'      => array( 'work type', 'type', 'location' ),
	);

	private static function norm( $s ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $s ) ) );
	}

	/** Parses CSV text into rows (handles quoted multi-line cells and ; or tab delimiters). */
	public static function parse( $text ) {
		$text = (string) $text;
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		$sample = implode( "\n", array_slice( preg_split( "/\r\n|\n|\r/", $text ), 0, 25 ) );
		$delims = array( ',' => substr_count( $sample, ',' ), ';' => substr_count( $sample, ';' ), "\t" => substr_count( $sample, "\t" ) );
		arsort( $delims );
		$delim = key( $delims );

		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $text );
		rewind( $fh );
		$rows = array();
		while ( false !== ( $r = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) ) {
			$rows[] = array_map( function ( $c ) {
				return trim( (string) $c );
			}, $r );
		}
		fclose( $fh );
		return $rows;
	}

	private static function is_blank( $row ) {
		return '' === implode( '', $row );
	}

	/** Locates the header row (skipping title rows and blanks) and suggests a mapping. */
	public static function detect_header( $rows ) {
		$best       = -1;
		$best_score = 0;
		foreach ( array_slice( $rows, 0, 20, true ) as $i => $row ) {
			$score = 0;
			foreach ( $row as $cell ) {
				$n = self::norm( $cell );
				foreach ( self::$synonyms as $syn ) {
					if ( in_array( $n, $syn, true ) ) {
						$score++;
						break;
					}
				}
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $i;
			}
		}
		if ( $best_score < 2 ) {
			return array( -1, array() );
		}
		$mapping = array();
		foreach ( $rows[ $best ] as $col => $cell ) {
			$n = self::norm( $cell );
			foreach ( self::$synonyms as $field => $syn ) {
				if ( ! isset( $mapping[ $field ] ) && in_array( $n, $syn, true ) ) {
					$mapping[ $field ] = $col;
					break;
				}
			}
		}
		return array( $best, $mapping );
	}

	public static function split_names( $s ) {
		$parts = preg_split( '/\s*(?:,|;|\/|&|\+|\band\b)\s*/i', (string) $s );
		return array_values( array_unique( array_filter( array_map( 'trim', $parts ), 'strlen' ) ) );
	}

	/** Best-effort member match by display name, first name, login or email. */
	public static function match_member( $name, $members ) {
		$n = self::norm( $name );
		if ( '' === $n ) {
			return 0;
		}
		foreach ( $members as $m ) {
			if ( in_array( $n, array( self::norm( $m->display_name ), self::norm( $m->user_login ), self::norm( $m->user_email ) ), true ) ) {
				return (int) $m->ID;
			}
		}
		$hits = array();
		foreach ( $members as $m ) {
			$first = self::norm( strtok( $m->display_name, ' ' ) );
			if ( $first === $n || self::norm( get_user_meta( $m->ID, 'first_name', true ) ) === $n ) {
				$hits[] = (int) $m->ID;
			}
		}
		return 1 === count( $hits ) ? $hits[0] : 0;
	}

	public static function parse_status( $s ) {
		$n = self::norm( $s );
		if ( '' === $n ) {
			return 'pending';
		}
		if ( preg_match( '/^(done|complete|completed|finished|closed|yes|✓|✔)/u', $n ) ) {
			return 'completed';
		}
		if ( preg_match( '/(in ?process|in ?progress|ongoing|wip|working|started)/', $n ) ) {
			return 'in_process';
		}
		if ( preg_match( '/^(reject|declin|cancel|dropped)/', $n ) ) {
			return 'rejected';
		}
		// "Blocked"/on-hold rows stay open (Pending) and keep carrying forward; Blocked is no longer a status.
		return 'pending';
	}

	public static function parse_priority( $s, $default ) {
		$n = self::norm( $s );
		$map = array( 'urgent' => 'urgent', 'critical' => 'urgent', 'p0' => 'urgent', 'p1' => 'high', 'high' => 'high', 'medium' => 'medium', 'normal' => 'medium', 'p2' => 'medium', 'low' => 'low', 'p3' => 'low' );
		return isset( $map[ $n ] ) ? $map[ $n ] : $default;
	}

	public static function parse_time( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s ) {
			return array( null, '' );
		}
		if ( preg_match( '/^(\d{1,2})(?:[:.](\d{2}))?\s*(am|pm|a\.m\.|p\.m\.)?$/i', $s, $m ) ) {
			$h   = (int) $m[1];
			$min = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
			$ap  = isset( $m[3] ) ? strtolower( str_replace( '.', '', $m[3] ) ) : '';
			if ( 'pm' === $ap && $h < 12 ) {
				$h += 12;
			} elseif ( 'am' === $ap && 12 === $h ) {
				$h = 0;
			}
			if ( $h < 24 && $min < 60 && ( $ap || isset( $m[2] ) && '' !== $m[2] ) ) {
				return array( sprintf( '%02d:%02d', $h, $min ), '' );
			}
		}
		return array( null, 'Due time "' . $s . '" not recognised; kept in remarks.' );
	}

	public static function parse_date( $s ) {
		$s = trim( (string) $s );
		if ( '' === $s ) {
			return null;
		}
		if ( NDWD_Helpers::valid_date( $s ) ) {
			return $s;
		}
		if ( preg_match( '#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2,4})$#', $s, $m ) ) {
			$y = (int) $m[3];
			$y = $y < 100 ? 2000 + $y : $y;
			// Indian format: day first.
			if ( checkdate( (int) $m[2], (int) $m[1], $y ) ) {
				return sprintf( '%04d-%02d-%02d', $y, $m[2], $m[1] );
			}
		}
		$ts = strtotime( $s );
		return $ts ? gmdate( 'Y-m-d', $ts ) : null;
	}

	/**
	 * Builds normalised import items from rows + mapping.
	 */
	private static function build_items( $rows, $header_idx, $mapping, $member_map, $opts ) {
		global $wpdb;
		$get = function ( $row, $field ) use ( $mapping ) {
			return isset( $mapping[ $field ] ) && '' !== $mapping[ $field ] && null !== $mapping[ $field ] && isset( $row[ (int) $mapping[ $field ] ] ) ? $row[ (int) $mapping[ $field ] ] : '';
		};
		$projects = array();
		foreach ( $wpdb->get_results( 'SELECT id, name, client FROM ' . NDWD_Helpers::table( 'projects' ) ) as $p ) { // phpcs:ignore
			$projects[ self::norm( $p->name ) ] = (int) $p->id;
			if ( $p->client ) {
				$projects[ self::norm( $p->client ) ] = (int) $p->id;
			}
		}
		$work_types = array();
		foreach ( NDWD_Helpers::work_types() as $k => $label ) {
			$work_types[ self::norm( $label ) ] = $k;
			$work_types[ $k ]                   = $k;
		}
		$work_types['field'] = isset( $work_types['field'] ) ? $work_types['field'] : 'other';
		$work_types['on-site'] = $work_types['field'];
		$work_types['onsite']  = $work_types['field'];

		$default_date = ! empty( $opts['scheduled_date'] ) && NDWD_Helpers::valid_date( $opts['scheduled_date'] ) ? $opts['scheduled_date'] : NDWD_Helpers::today();
		$items        = array();
		foreach ( $rows as $i => $row ) {
			if ( $i <= $header_idx ) {
				continue;
			}
			$line = $i + 1;
			$item = array( 'row' => $line, 'state' => 'ok', 'messages' => array(), 'raw' => $row );
			if ( self::is_blank( $row ) ) {
				$item['state']      = 'blank';
				$item['messages'][] = 'Blank row';
				$items[]            = $item;
				continue;
			}
			$title = $get( $row, 'title' );
			if ( '' === $title ) {
				$item['state']      = 'invalid';
				$item['messages'][] = 'Missing Task / Activity title';
			}
			$assignees = array();
			$names     = self::split_names( $get( $row, 'assignees' ) );
			foreach ( $names as $n ) {
				$key = self::norm( $n );
				if ( ! empty( $member_map[ $key ] ) ) {
					$assignees[] = (int) $member_map[ $key ];
				} else {
					$item['messages'][] = 'Assignee "' . $n . '" not mapped to a member';
				}
			}
			list( $time, $time_msg ) = self::parse_time( $get( $row, 'due_time' ) );
			if ( $time_msg ) {
				$item['messages'][] = $time_msg;
			}
			$sched_raw = $get( $row, 'scheduled_date' );
			$sched     = self::parse_date( $sched_raw );
			if ( '' !== $sched_raw && ! $sched ) {
				$item['messages'][] = 'Date "' . $sched_raw . '" not recognised; default date used';
			}
			$due_raw = $get( $row, 'due_date' );
			$due     = self::parse_date( $due_raw );
			$sched   = $sched ? $sched : $default_date;
			if ( $due && $due < $sched ) {
				$due = $sched;
			}
			$wt_raw = self::norm( $get( $row, 'work_type' ) );
			$remark = array();
			foreach ( array( 'report' => 'Report', 'remarks' => 'Remarks' ) as $f => $label ) {
				$v = $get( $row, $f );
				if ( '' !== $v ) {
					$remark[] = $label . ': ' . $v;
				}
			}
			if ( $time_msg ) {
				$remark[] = 'Original due time: ' . $get( $row, 'due_time' );
			}
			$proj = self::norm( $get( $row, 'project' ) );

			$item['data'] = array(
				'title'          => mb_substr( $title, 0, 255 ),
				'description'    => $get( $row, 'description' ),
				'assignee_ids'   => array_values( array_unique( $assignees ) ),
				'team_id'        => ! empty( $opts['team_id'] ) ? absint( $opts['team_id'] ) : 0,
				'priority'       => self::parse_priority( $get( $row, 'priority' ), ! empty( $opts['default_priority'] ) ? $opts['default_priority'] : 'medium' ),
				'work_type'      => isset( $work_types[ $wt_raw ] ) ? $work_types[ $wt_raw ] : 'office',
				'scheduled_date' => $sched,
				'due_date'       => $due ? $due : '',
				'due_time'       => $time ? $time : '',
				'project_id'     => isset( $projects[ $proj ] ) ? $projects[ $proj ] : 0,
				'remarks'        => implode( "\n", $remark ),
			);
			$item['status'] = ! empty( $opts['preserve_status'] ) ? self::parse_status( $get( $row, 'status' ) ) : 'pending';
			if ( ! $item['data']['assignee_ids'] && ! $item['data']['team_id'] && 'invalid' !== $item['state'] ) {
				$item['messages'][] = 'No mapped assignee — will be assigned to you (the importer)';
			}
			$assign_key   = $item['data']['assignee_ids'];
			sort( $assign_key );
			$item['hash'] = sha1( self::norm( $title ) . '|' . $sched . '|' . implode( ',', $assign_key ) . '|' . $item['data']['due_time'] );
			if ( 'ok' === $item['state'] ) {
				$dup = $wpdb->get_var( $wpdb->prepare(
					'SELECT id FROM ' . NDWD_Helpers::table( 'tasks' ) . ' WHERE is_archived = 0 AND (import_hash = %s OR (LOWER(title) = %s AND scheduled_date = %s)) LIMIT 1',
					$item['hash'], self::norm( $title ), $sched
				) );
				if ( $dup ) {
					$item['state']        = 'duplicate';
					$item['duplicate_of'] = (int) $dup;
					$item['messages'][]   = 'Looks like existing task #' . $dup;
				}
			}
			$items[] = $item;
		}
		// Duplicates inside the file itself.
		$seen = array();
		foreach ( $items as &$it ) {
			if ( 'ok' !== $it['state'] ) {
				continue;
			}
			if ( isset( $seen[ $it['hash'] ] ) ) {
				$it['state']      = 'duplicate';
				$it['messages'][] = 'Same as row ' . $seen[ $it['hash'] ] . ' in this file';
			} else {
				$seen[ $it['hash'] ] = $it['row'];
			}
		}
		unset( $it );
		return $items;
	}

	/** @return array|WP_Error */
	public static function preview( $text, $mapping = null, $member_map = array(), $opts = array() ) {
		if ( strlen( (string) $text ) > self::MAX_BYTES ) {
			return new WP_Error( 'ndwd_invalid', 'CSV is larger than 2 MB. Split it into smaller files.', array( 'status' => 400 ) );
		}
		$rows = self::parse( $text );
		if ( ! $rows ) {
			return new WP_Error( 'ndwd_invalid', 'The file contains no rows.', array( 'status' => 400 ) );
		}
		list( $header_idx, $suggested ) = self::detect_header( $rows );
		if ( isset( $opts['header_row'] ) && '' !== $opts['header_row'] && null !== $opts['header_row'] ) {
			$header_idx = max( 0, min( count( $rows ) - 1, (int) $opts['header_row'] - 1 ) );
		}
		if ( $header_idx < 0 ) {
			return new WP_Error( 'ndwd_invalid', 'Could not find a header row (e.g. "Task / Activity", "Assigned To"). Set the header row number manually.', array( 'status' => 400, 'rows' => array_slice( $rows, 0, 10 ) ) );
		}
		$mapping = is_array( $mapping ) && $mapping ? $mapping : $suggested;

		$members = get_users( array( 'capability' => 'ndwd_access', 'fields' => array( 'ID', 'display_name', 'user_login', 'user_email' ) ) );
		$names   = array();
		if ( isset( $mapping['assignees'] ) && '' !== $mapping['assignees'] ) {
			foreach ( $rows as $i => $row ) {
				if ( $i <= $header_idx || ! isset( $row[ (int) $mapping['assignees'] ] ) ) {
					continue;
				}
				foreach ( self::split_names( $row[ (int) $mapping['assignees'] ] ) as $n ) {
					$names[ self::norm( $n ) ] = $n;
				}
			}
		}
		$member_suggestions = array();
		foreach ( $names as $key => $n ) {
			$member_suggestions[] = array(
				'key'      => $key,
				'name'     => $n,
				'user_id'  => isset( $member_map[ $key ] ) ? (int) $member_map[ $key ] : self::match_member( $n, $members ),
			);
		}
		$map_for_items = array();
		foreach ( $member_suggestions as $s ) {
			$map_for_items[ $s['key'] ] = $s['user_id'];
		}
		$items  = self::build_items( $rows, $header_idx, $mapping, $map_for_items, $opts );
		$counts = array( 'ok' => 0, 'invalid' => 0, 'duplicate' => 0, 'blank' => 0 );
		foreach ( $items as $it ) {
			$counts[ $it['state'] ]++;
		}
		return array(
			'header_row' => $header_idx + 1,
			'header'     => $rows[ $header_idx ],
			'skipped_top_rows' => $header_idx,
			'mapping'    => (object) $mapping,
			'fields'     => self::FIELDS,
			'members'    => $member_suggestions,
			'counts'     => $counts,
			'total'      => count( $items ),
			'items'      => array_map( function ( $it ) {
				unset( $it['hash'] );
				return $it;
			}, array_slice( $items, 0, 500 ) ),
		);
	}

	/** @return array|WP_Error Summary. */
	public static function commit( $text, $mapping, $member_map, $opts, $user_id ) {
		$preview = self::preview( $text, $mapping, $member_map, $opts );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$rows = self::parse( $text );
		$map  = array();
		foreach ( $preview['members'] as $m ) {
			$map[ $m['key'] ] = $m['user_id'];
		}
		$items   = self::build_items( $rows, $preview['header_row'] - 1, (array) $preview['mapping'], $map, $opts );
		$summary = array( 'imported' => 0, 'skipped_duplicates' => 0, 'invalid' => 0, 'blank' => 0, 'failed' => 0, 'details' => array(), 'task_ids' => array() );
		$import_dups = ! empty( $opts['import_duplicates'] );
		foreach ( $items as $it ) {
			if ( 'blank' === $it['state'] ) {
				$summary['blank']++;
				continue;
			}
			if ( 'invalid' === $it['state'] ) {
				$summary['invalid']++;
				$summary['details'][] = array( 'row' => $it['row'], 'result' => 'invalid', 'message' => implode( '; ', $it['messages'] ) );
				continue;
			}
			if ( 'duplicate' === $it['state'] && ! $import_dups ) {
				$summary['skipped_duplicates']++;
				$summary['details'][] = array( 'row' => $it['row'], 'result' => 'duplicate', 'message' => implode( '; ', $it['messages'] ) );
				continue;
			}
			$task = NDWD_Tasks::create( $it['data'], $user_id, array(
				'source'            => 'import',
				'status'            => $it['status'],
				'import_hash'       => $it['hash'],
				'activity'          => 'task_imported',
				'silent'            => empty( $opts['notify'] ),
				'skip_assign_check' => true,
			) );
			if ( is_wp_error( $task ) ) {
				$summary['failed']++;
				$summary['details'][] = array( 'row' => $it['row'], 'result' => 'failed', 'message' => $task->get_error_message() );
				continue;
			}
			$summary['imported']++;
			$summary['task_ids'][] = (int) $task->id;
			if ( $it['messages'] ) {
				$summary['details'][] = array( 'row' => $it['row'], 'result' => 'imported', 'message' => 'Task #' . $task->id . ': ' . implode( '; ', $it['messages'] ) );
			}
		}
		$summary['total_rows'] = count( $items );
		return $summary;
	}
}
