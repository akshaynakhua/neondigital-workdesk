<?php
/**
 * Accounts: packages, invoices, payments, expenses, other transactions, passbook,
 * financial reports and financial-year archives.
 *
 * Rules
 * - Only people with the ndwd_manage_accounts capability (Admins by default) can read
 *   or change anything here. The REST layer enforces it on every route.
 * - Amounts are stored as DECIMAL(14,2) and calculated in paise to avoid rounding drift.
 * - An invoice keeps a snapshot of the client's billing details, the business details
 *   and its lines at the time of issue. Changing a package price or a client's details
 *   later never changes an existing invoice.
 * - Generating an invoice is not money received: only recorded payments (and other
 *   income entries) count as received in the passbook and the overview.
 * - Income lists all money received. Every invoice payment has exactly one Income row
 *   (income.payment_id is unique) and appears once in the passbook (as the payment).
 *   Manual income (no invoice) is its own passbook row. Recording income against an
 *   invoice records a payment on that invoice, so nothing is counted twice.
 * - Advances: manual income of a client (money already received, e.g. an advance) can be
 *   adjusted against that client's invoices through the allocation ledger
 *   (income_allocations). An allocation settles part of an invoice but is never new
 *   income: the Income row and its single passbook credit stay as recorded. The sum of a
 *   receipt's active allocations can never exceed its amount; cancelling an invoice or
 *   voiding the income releases its allocations.
 * - One client payment can settle several invoices: it is recorded once as manual income
 *   (Client Payment) and allocated to the invoices through the same ledger; any rest stays
 *   available as the client's advance.
 * - Billable expenses: an expense marked billable (with a client) can be added to one active
 *   invoice as a reimbursable line. The expense itself is never changed or moved: it stays in
 *   Expenses, and only the client's payment of the invoice is income. Cancelling the invoice
 *   frees the expense for billing again.
 * - Previous outstanding invoices can be shown on a new invoice as a summary (a snapshot, never
 *   new revenue). Consolidation is separate and explicit: the old invoice's remaining balance
 *   is transferred to the new invoice as a "brought forward" line and the old invoice becomes
 *   Consolidated (settled by transfer), in one transaction, so the receivable is never counted
 *   twice. Cancelling the new invoice restores the old balances.
 * - Every material change (create, edit, cancel, payment, void, expense change) is
 *   written to the financial audit trail.
 * - Indian financial year: 1 April – 31 March. Invoices and PDFs are never deleted
 *   automatically; the Admin is reminded to download the year's archive before the
 *   retention deadline (31 March + 1 month, plus a configurable grace period).
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Accounts {

	const METHODS = array(
		'upi'           => 'UPI',
		'bank_transfer' => 'Bank transfer',
		'cash'          => 'Cash',
		'card'          => 'Card',
		'other'         => 'Other',
	);

	const EXPENSE_CATEGORIES = array(
		'Software and subscriptions',
		'Hosting and domains',
		'Advertising and marketing',
		'Client project expenses',
		'Travel and transportation',
		'Equipment',
		'Contractor/freelancer payments',
		'Office expenses',
		'Other',
	);

	const INVOICE_STATUSES = array(
		'unpaid'    => 'Unpaid',
		'partial'   => 'Partially Paid',
		'paid'      => 'Paid',
		'consolidated' => 'Consolidated',
		'cancelled' => 'Cancelled',
	);

	const ENTRY_TYPES = array(
		'refund'        => array( 'Refund Paid', 'out' ),
		'refund_in'     => array( 'Refund Received', 'in' ),
		'other_income'  => array( 'Other Money Received', 'in' ),
		'other_payment' => array( 'Other Money Paid', 'out' ),
	);

	// Built-in income categories. Admins add their own in Settings or with "Custom Category".
	const INCOME_SOURCES = array( 'Client Payment', 'Advance Payment', 'Extra Work', 'Travel Reimbursement', 'Other Income' );

	const QR_TYPES = array( 'png', 'jpg', 'jpeg', 'webp' );
	const QR_MAX   = 2097152; // 2 MB

	const PERIODS = array( '' => 'One-time', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'half_yearly' => 'Half-yearly', 'yearly' => 'Yearly' );

	const RECEIPT_TYPES = array( 'pdf', 'jpg', 'jpeg', 'png', 'webp' );
	const RECEIPT_MAX   = 5242880; // 5 MB

	private static function t( $name ) {
		return NDWD_Helpers::table( $name );
	}

	/* ------------------------------------------------------------------ *
	 * Money, dates, financial year
	 * ------------------------------------------------------------------ */

	/** Amount in paise (integer) from user input; null when invalid. */
	public static function paise( $v ) {
		if ( is_string( $v ) ) {
			$v = str_replace( array( ',', ' ', '₹' ), '', $v );
		}
		if ( '' === $v || null === $v || ! is_numeric( $v ) ) {
			return null;
		}
		return (int) round( (float) $v * 100 );
	}

	public static function rupees( $paise ) {
		return round( $paise / 100, 2 );
	}

	/** Indian digit grouping: 1234567.5 → "12,34,567.50". */
	public static function inr( $amount, $symbol = '' ) {
		$neg = $amount < 0;
		list( $int, $dec ) = explode( '.', number_format( abs( (float) $amount ), 2, '.', '' ) );
		if ( strlen( $int ) > 3 ) {
			$int = preg_replace( '/\B(?=(\d{2})+(?!\d))/', ',', substr( $int, 0, -3 ) ) . ',' . substr( $int, -3 );
		}
		return ( $neg ? '-' : '' ) . $symbol . $int . '.' . $dec;
	}

	/** "2026-27" for any date from 1 Apr 2026 to 31 Mar 2027. */
	public static function fy_of( $date ) {
		$y = (int) substr( $date, 0, 4 );
		$m = (int) substr( $date, 5, 2 );
		$start = $m >= 4 ? $y : $y - 1;
		return $start . '-' . substr( (string) ( $start + 1 ), 2 );
	}

	public static function valid_fy( $fy ) {
		return is_string( $fy ) && preg_match( '/^(\d{4})-(\d{2})$/', $fy, $m ) && substr( (string) ( $m[1] + 1 ), 2 ) === $m[2];
	}

	/** [ 'YYYY-04-01', 'YYYY+1-03-31' ] */
	public static function fy_range( $fy ) {
		$start = (int) substr( $fy, 0, 4 );
		return array( $start . '-04-01', ( $start + 1 ) . '-03-31' );
	}

	/** Retention deadline: one month after the financial year ends (30 April). */
	public static function fy_deadline( $fy ) {
		return ( (int) substr( $fy, 0, 4 ) + 1 ) . '-04-30';
	}

	/* ------------------------------------------------------------------ *
	 * Settings (business details, templates, opening balance)
	 * ------------------------------------------------------------------ */

	public static function default_settings() {
		return array(
			'business_name'        => 'NeonDigital',
			'address'              => '',
			'gstin'                => '',
			'phone'                => '',
			'email'                => '',
			'website'              => '',
			'bank_name'            => '',
			'account_name'         => '',
			'account_number'       => '',
			'ifsc'                 => '',
			'upi_id'               => '',
			'payment_instructions' => '',
			'payment_accounts'     => '',
			'upi_qr'               => '',
			'business_logo'        => '',
			'invoice_prefix'       => 'ND',
			'due_days'             => 15,
			'tax_label'            => 'GST',
			'tax_rate'             => 0,
			'opening_balance'      => '',
			'opening_date'         => '',
			'fy_grace_days'        => 30,
			'archive_expiry_hours' => 24,
			'include_pdf_link'     => 1,
			'wa_template'          => "Hi {client_name},\n\nPlease find your invoice {invoice_number} for ₹{invoice_total} from NeonDigital.\n\nKindly review the invoice and let us know if you have any questions. We appreciate your business.\n\nThank you,\nTeam NeonDigital",
			'email_subject'        => 'Invoice {invoice_number} | NeonDigital',
			'email_body'           => "Dear {client_name},\n\nPlease find your invoice {invoice_number} for ₹{invoice_total} issued by NeonDigital.\n\nKindly review the invoice and arrange payment by the due date mentioned in the invoice.\n\nIf you have any questions regarding the invoice, please contact us.\n\nThank you for your continued support.\n\nBest regards,\nTeam NeonDigital",
			'expense_categories'   => array(),
			'income_sources'       => array(),
		);
	}

	public static function settings() {
		$s = get_option( 'ndwd_accounts', array() );
		return array_merge( self::default_settings(), is_array( $s ) ? $s : array() );
	}

	public static function save_settings( $in, $user_id ) {
		$s   = self::settings();
		$old = $s;
		foreach ( array( 'business_name' => 160, 'phone' => 40, 'website' => 190, 'bank_name' => 120, 'account_name' => 160, 'account_number' => 40, 'ifsc' => 20, 'upi_id' => 120, 'invoice_prefix' => 10, 'tax_label' => 40, 'email_subject' => 255 ) as $k => $len ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = mb_substr( sanitize_text_field( (string) $in[ $k ] ), 0, $len );
			}
		}
		foreach ( array( 'address', 'payment_instructions', 'payment_accounts', 'wa_template', 'email_body' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = mb_substr( sanitize_textarea_field( (string) $in[ $k ] ), 0, 4000 );
			}
		}
		if ( isset( $in['email'] ) ) {
			$e = trim( (string) $in['email'] );
			if ( '' !== $e && ! is_email( $e ) ) {
				return new WP_Error( 'ndwd_invalid', 'Business email is not valid.', array( 'status' => 400 ) );
			}
			$s['email'] = sanitize_email( $e );
		}
		if ( isset( $in['gstin'] ) ) {
			$g = strtoupper( preg_replace( '/\s+/', '', (string) $in['gstin'] ) );
			if ( '' !== $g && ! preg_match( '/^[0-9]{2}[A-Z0-9]{13}$/', $g ) ) {
				return new WP_Error( 'ndwd_invalid', 'Business GSTIN should be 15 characters.', array( 'status' => 400 ) );
			}
			$s['gstin'] = $g;
		}
		if ( isset( $in['invoice_prefix'] ) && ! preg_match( '/^[A-Za-z0-9-]{1,10}$/', $s['invoice_prefix'] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Invoice prefix: 1–10 letters, digits or hyphens.', array( 'status' => 400 ) );
		}
		foreach ( array( 'due_days' => array( 0, 365 ), 'fy_grace_days' => array( 0, 365 ), 'archive_expiry_hours' => array( 1, 168 ) ) as $k => $lim ) {
			if ( isset( $in[ $k ] ) ) {
				$s[ $k ] = max( $lim[0], min( $lim[1], absint( $in[ $k ] ) ) );
			}
		}
		if ( isset( $in['tax_rate'] ) ) {
			$r = (float) $in['tax_rate'];
			if ( $r < 0 || $r > 100 ) {
				return new WP_Error( 'ndwd_invalid', 'Tax rate must be between 0 and 100.', array( 'status' => 400 ) );
			}
			$s['tax_rate'] = round( $r, 2 );
		}
		if ( isset( $in['include_pdf_link'] ) ) {
			$s['include_pdf_link'] = $in['include_pdf_link'] ? 1 : 0;
		}
		if ( array_key_exists( 'opening_balance', $in ) ) {
			if ( '' === trim( (string) $in['opening_balance'] ) ) {
				$s['opening_balance'] = '';
				$s['opening_date']    = '';
			} else {
				$p = self::paise( $in['opening_balance'] );
				$d = NDWD_Helpers::sanitize_date( isset( $in['opening_date'] ) ? (string) $in['opening_date'] : '' );
				if ( null === $p || ! $d ) {
					return new WP_Error( 'ndwd_invalid', 'Opening balance needs an amount and the date it applies from.', array( 'status' => 400 ) );
				}
				$s['opening_balance'] = self::rupees( $p );
				$s['opening_date']    = $d;
			}
		}
		if ( isset( $in['expense_categories'] ) && is_array( $in['expense_categories'] ) ) {
			$s['expense_categories'] = array_values( array_unique( array_filter( array_map( function ( $c ) {
				return mb_substr( sanitize_text_field( (string) $c ), 0, 80 );
			}, $in['expense_categories'] ) ) ) );
		}
		if ( isset( $in['income_sources'] ) && is_array( $in['income_sources'] ) ) {
			$s['income_sources'] = array_values( array_unique( array_filter( array_map( function ( $c ) {
				return mb_substr( sanitize_text_field( (string) $c ), 0, 80 );
			}, $in['income_sources'] ) ) ) );
		}
		update_option( 'ndwd_accounts', $s, false );
		$changed = array();
		foreach ( $s as $k => $v ) {
			if ( ! isset( $old[ $k ] ) || $old[ $k ] !== $v ) {
				$changed[] = $k;
			}
		}
		if ( $changed ) {
			self::audit( 'settings', 0, 'settings_updated', $user_id, array( 'fields' => $changed ) );
		}
		return $s;
	}

	public static function income_sources() {
		$s = self::settings();
		return array_values( array_unique( array_merge( self::INCOME_SOURCES, (array) $s['income_sources'] ) ) );
	}

	/** Remembers a new custom income category (chosen with "Custom Category" on an entry). */
	private static function remember_income_category( $name ) {
		if ( '' === $name || in_array( $name, self::income_sources(), true ) ) {
			return;
		}
		$s = get_option( 'ndwd_accounts', array() );
		$s = is_array( $s ) ? $s : array();
		$s['income_sources']   = isset( $s['income_sources'] ) ? (array) $s['income_sources'] : array();
		$s['income_sources'][] = $name;
		update_option( 'ndwd_accounts', $s, false );
	}

	public static function categories() {
		$s = self::settings();
		return array_values( array_unique( array_merge( self::EXPENSE_CATEGORIES, (array) $s['expense_categories'] ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Payment accounts (Accounts -> Settings -> Payment accounts)
	 *
	 * The saved accounts money is received into / paid from. Transactions keep the
	 * account name they were saved with (the "destination" text), so renaming or
	 * deactivating an account never rewrites history: a rename keeps the old name as
	 * an alias, and deactivated accounts only disappear from the pickers.
	 * ------------------------------------------------------------------ */

	const ACCOUNT_TYPES = array(
		'bank'  => 'Bank account',
		'upi'   => 'UPI',
		'card'  => 'Credit / debit card',
		'cash'  => 'Cash',
		'other' => 'Other',
	);

	/** Payment method => the account type it normally moves money through. */
	const METHOD_ACCOUNT_TYPES = array(
		'upi'           => 'upi',
		'bank_transfer' => 'bank',
		'cash'          => 'cash',
		'card'          => 'card',
	);

	/** All saved accounts (active and inactive). The first read seeds the list from the earlier settings. */
	public static function payment_accounts() {
		$list = get_option( 'ndwd_payment_accounts', null );
		if ( ! is_array( $list ) ) {
			$list = array();
			$s    = self::settings();
			$id   = 0;
			$bank = '' !== (string) $s['account_number'] ? trim( ( $s['bank_name'] ? $s['bank_name'] : 'Bank' ) . ' A/c XX' . substr( preg_replace( '/\s+/', '', $s['account_number'] ), -4 ) ) : null;
			foreach ( self::legacy_destinations( $s ) as $name ) {
				$type = 'other';
				$ref  = '';
				if ( 'Cash' === $name ) {
					$type = 'cash';
				} elseif ( $bank === $name ) {
					$type = 'bank';
					$ref  = (string) $s['ifsc'];
				} elseif ( '' !== (string) $s['upi_id'] && 'UPI ' . $s['upi_id'] === $name ) {
					$type = 'upi';
					$ref  = (string) $s['upi_id'];
				} elseif ( preg_match( '/\bupi\b|@/i', $name ) ) {
					$type = 'upi';
				} elseif ( preg_match( '/\b(card|visa|master ?card|rupay|amex)\b/i', $name ) ) {
					$type = 'card';
				} elseif ( preg_match( '#\b(bank|a/c|acct|account|current|savings)\b#i', $name ) ) {
					$type = 'bank';
				}
				$list[] = array( 'id' => ++$id, 'name' => $name, 'type' => $type, 'ref' => $ref, 'active' => true, 'aliases' => array() );
			}
			update_option( 'ndwd_payment_accounts', $list, false );
		}
		return array_values( array_map( function ( $a ) {
			return array(
				'id'      => (int) $a['id'],
				'name'    => (string) $a['name'],
				'type'    => isset( self::ACCOUNT_TYPES[ $a['type'] ] ) ? $a['type'] : 'other',
				'ref'     => isset( $a['ref'] ) ? (string) $a['ref'] : '',
				'active'  => ! empty( $a['active'] ),
				'aliases' => isset( $a['aliases'] ) ? array_values( (array) $a['aliases'] ) : array(),
			);
		}, $list ) );
	}

	/** The account a name or earlier name refers to, or null. Case-insensitive. */
	public static function account_by_name( $name, $exclude_id = 0 ) {
		$key = mb_strtolower( trim( (string) $name ) );
		if ( '' === $key ) {
			return null;
		}
		foreach ( self::payment_accounts() as $a ) {
			if ( (int) $a['id'] === (int) $exclude_id ) {
				continue;
			}
			foreach ( array_merge( array( $a['name'] ), $a['aliases'] ) as $n ) {
				if ( mb_strtolower( $n ) === $key ) {
					return $a;
				}
			}
		}
		return null;
	}

	/** Adds ($id = 0) or edits a saved account: display name, type, reference, active. */
	public static function save_payment_account( $in, $user_id, $id = 0 ) {
		$list = self::payment_accounts();
		$idx  = null;
		foreach ( $list as $i => $a ) {
			if ( $id && (int) $a['id'] === (int) $id ) {
				$idx = $i;
			}
		}
		if ( $id && null === $idx ) {
			return new WP_Error( 'ndwd_not_found', 'Account not found.', array( 'status' => 404 ) );
		}
		$cur  = null === $idx ? array( 'id' => 0, 'name' => '', 'type' => 'other', 'ref' => '', 'active' => true, 'aliases' => array() ) : $list[ $idx ];
		$name = isset( $in['name'] ) ? mb_substr( trim( sanitize_text_field( (string) $in['name'] ) ), 0, 160 ) : $cur['name'];
		$type = isset( $in['type'] ) ? sanitize_key( (string) $in['type'] ) : $cur['type'];
		$ref  = isset( $in['ref'] ) ? mb_substr( trim( sanitize_text_field( (string) $in['ref'] ) ), 0, 120 ) : $cur['ref'];
		if ( '' === $name ) {
			return new WP_Error( 'ndwd_invalid', 'Enter the account display name.', array( 'status' => 400 ) );
		}
		if ( ! isset( self::ACCOUNT_TYPES[ $type ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Choose the account type.', array( 'status' => 400 ) );
		}
		$dup = self::account_by_name( $name, $cur['id'] );
		if ( $dup ) {
			return new WP_Error( 'ndwd_invalid', 'An account named "' . $dup['name'] . '" already exists' . ( $dup['active'] ? '' : ' (inactive: activate it instead)' ) . '.', array( 'status' => 400 ) );
		}
		$was = $cur;
		if ( $cur['id'] && $name !== $cur['name'] && ! in_array( $cur['name'], $cur['aliases'], true ) ) {
			$cur['aliases'][] = $cur['name']; // Transactions saved under the old name still belong to this account.
		}
		$cur['aliases'] = array_values( array_diff( $cur['aliases'], array( $name ) ) );
		$cur['name']    = $name;
		$cur['type']    = $type;
		$cur['ref']     = $ref;
		if ( isset( $in['active'] ) ) {
			$cur['active'] = filter_var( $in['active'], FILTER_VALIDATE_BOOLEAN );
		}
		if ( null === $idx ) {
			$cur['id'] = $list ? max( wp_list_pluck( $list, 'id' ) ) + 1 : 1;
			$list[]    = $cur;
		} else {
			$list[ $idx ] = $cur;
		}
		update_option( 'ndwd_payment_accounts', array_values( $list ), false );
		$keys = array_flip( array( 'name', 'type', 'ref', 'active' ) );
		self::audit( 'payment_account', $cur['id'], $id ? 'updated' : 'created', $user_id, $id ? array( 'from' => array_intersect_key( $was, $keys ), 'to' => array_intersect_key( $cur, $keys ) ) : array_intersect_key( $cur, $keys ) );
		return $cur;
	}

	/**
	 * "Save this account for future use" on an Income / Expense / payment form: adds the
	 * typed account unless one with that name (or an earlier name) already exists.
	 */
	public static function remember_account( $name, $method, $user_id ) {
		$name = mb_substr( trim( sanitize_text_field( (string) $name ) ), 0, 160 );
		if ( '' === $name || self::account_by_name( $name ) ) {
			return null;
		}
		$type = isset( self::METHOD_ACCOUNT_TYPES[ $method ] ) ? self::METHOD_ACCOUNT_TYPES[ $method ] : 'other';
		$res  = self::save_payment_account( array( 'name' => $name, 'type' => $type ), $user_id );
		return is_wp_error( $res ) ? null : $res;
	}

	/** Names of the active saved accounts, offered as Received into / Paid from. */
	public static function destinations( $s = null ) {
		return array_values( wp_list_pluck( array_filter( self::payment_accounts(), function ( $a ) {
			return $a['active'];
		} ), 'name' ) );
	}

	/**
	 * Accounts as they were offered before saved accounts existed (bank account, UPI ID,
	 * extra lines in Settings, plus cash); only used once, to seed the saved list.
	 */
	private static function legacy_destinations( $s = null ) {
		$s   = $s ? $s : self::settings();
		$out = array();
		if ( '' !== (string) $s['account_number'] ) {
			$out[] = trim( ( $s['bank_name'] ? $s['bank_name'] : 'Bank' ) . ' A/c XX' . substr( preg_replace( '/\s+/', '', $s['account_number'] ), -4 ) );
		}
		if ( '' !== (string) $s['upi_id'] ) {
			$out[] = 'UPI ' . $s['upi_id'];
		}
		foreach ( preg_split( "/\r\n|\n|\r/", (string) $s['payment_accounts'] ) as $l ) {
			if ( '' !== trim( $l ) ) {
				$out[] = mb_substr( trim( $l ), 0, 160 );
			}
		}
		$out[] = 'Cash';
		return array_values( array_unique( $out ) );
	}

	private static function destination_in( $in, $old = '' ) {
		return isset( $in['destination'] ) ? mb_substr( sanitize_text_field( (string) $in['destination'] ), 0, 160 ) : (string) $old;
	}

	/* ------------------------------------------------------------------ *
	 * UPI QR code (Accounts -> Settings -> Payment details)
	 * ------------------------------------------------------------------ */

	/**
	 * Saves the business UPI QR image. It is re-encoded as a JPEG with GD (which also
	 * proves it is a real image) so the PDF writer can embed it. Earlier QR files are
	 * kept, because issued invoices refer to the QR that was current when they were made.
	 */
	public static function save_qr( $in, $user_id ) {
		$name = sanitize_file_name( isset( $in['name'] ) ? (string) $in['name'] : 'qr' );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::QR_TYPES, true ) ) {
			return new WP_Error( 'ndwd_invalid', 'The QR code must be a PNG, JPG or WEBP image.', array( 'status' => 400 ) );
		}
		$bytes = base64_decode( preg_replace( '#^data:[^;]+;base64,#', '', isset( $in['data'] ) ? (string) $in['data'] : '' ), true );
		if ( ! $bytes ) {
			return new WP_Error( 'ndwd_invalid', 'The image could not be read.', array( 'status' => 400 ) );
		}
		if ( strlen( $bytes ) > self::QR_MAX ) {
			return new WP_Error( 'ndwd_invalid', 'The QR image can be at most 2 MB.', array( 'status' => 400 ) );
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP ), true ) ) {
			return new WP_Error( 'ndwd_invalid', 'The file is not a valid PNG, JPG or WEBP image.', array( 'status' => 400 ) );
		}
		if ( $info[0] < 100 || $info[1] < 100 || $info[0] > 4000 || $info[1] > 4000 ) {
			return new WP_Error( 'ndwd_invalid', 'The QR image should be between 100 and 4000 pixels on each side.', array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			if ( IMAGETYPE_JPEG !== $info[2] ) {
				return new WP_Error( 'ndwd_env', 'This server cannot convert images (PHP GD is missing). Upload the QR as a JPG.', array( 'status' => 400 ) );
			}
			$jpg = $bytes;
		} else {
			$src = @imagecreatefromstring( $bytes ); // phpcs:ignore
			if ( ! $src ) {
				return new WP_Error( 'ndwd_invalid', 'The image could not be decoded.', array( 'status' => 400 ) );
			}
			$w   = imagesx( $src );
			$h   = imagesy( $src );
			$dst = imagecreatetruecolor( $w, $h );
			imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) ); // Transparent areas become white.
			imagecopy( $dst, $src, 0, 0, 0, 0, $w, $h );
			ob_start();
			imagejpeg( $dst, null, 92 );
			$jpg = ob_get_clean();
			imagedestroy( $src );
			imagedestroy( $dst );
		}
		$rel = 'branding/upi-qr-' . wp_generate_password( 12, false ) . '.jpg';
		NDWD_Files::dir( dirname( $rel ) );
		if ( false === file_put_contents( NDWD_Reports::base_dir() . '/' . $rel, $jpg ) ) { // phpcs:ignore
			return new WP_Error( 'ndwd_io', 'Could not store the QR image.', array( 'status' => 500 ) );
		}
		$s           = self::settings();
		$s['upi_qr'] = $rel;
		update_option( 'ndwd_accounts', $s, false );
		self::audit( 'settings', 0, 'upi_qr_uploaded', $user_id, array( 'file' => basename( $rel ) ) );
		return $s;
	}

	/** Optional business logo for invoices. Stored as JPEG (the PDF writer embeds JPEG), white background. */
	public static function save_logo( $in, $user_id ) {
		$name = sanitize_file_name( isset( $in['name'] ) ? (string) $in['name'] : 'logo' );
		if ( ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), self::QR_TYPES, true ) ) {
			return new WP_Error( 'ndwd_invalid', 'The logo must be a PNG, JPG or WEBP image.', array( 'status' => 400 ) );
		}
		$bytes = base64_decode( preg_replace( '#^data:[^;]+;base64,#', '', isset( $in['data'] ) ? (string) $in['data'] : '' ), true );
		if ( ! $bytes || strlen( $bytes ) > self::QR_MAX ) {
			return new WP_Error( 'ndwd_invalid', 'The logo could not be read, or is larger than 2 MB.', array( 'status' => 400 ) );
		}
		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore
		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP ), true ) ) {
			return new WP_Error( 'ndwd_invalid', 'The file is not a valid PNG, JPG or WEBP image.', array( 'status' => 400 ) );
		}
		if ( $info[0] < 32 || $info[1] < 32 || $info[0] > 4000 || $info[1] > 4000 ) {
			return new WP_Error( 'ndwd_invalid', 'The logo should be between 32 and 4000 pixels on each side.', array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			if ( IMAGETYPE_JPEG !== $info[2] ) {
				return new WP_Error( 'ndwd_env', 'This server cannot convert images (PHP GD is missing). Upload the logo as a JPG.', array( 'status' => 400 ) );
			}
			$jpg = $bytes;
		} else {
			$src = @imagecreatefromstring( $bytes ); // phpcs:ignore
			if ( ! $src ) {
				return new WP_Error( 'ndwd_invalid', 'The image could not be decoded.', array( 'status' => 400 ) );
			}
			$w   = imagesx( $src );
			$h   = imagesy( $src );
			$dst = imagecreatetruecolor( $w, $h );
			imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
			imagecopy( $dst, $src, 0, 0, 0, 0, $w, $h );
			ob_start();
			imagejpeg( $dst, null, 92 );
			$jpg = ob_get_clean();
			imagedestroy( $src );
			imagedestroy( $dst );
		}
		$rel = 'branding/logo-' . wp_generate_password( 12, false ) . '.jpg';
		NDWD_Files::dir( dirname( $rel ) );
		if ( false === file_put_contents( NDWD_Reports::base_dir() . '/' . $rel, $jpg ) ) { // phpcs:ignore
			return new WP_Error( 'ndwd_io', 'Could not store the logo.', array( 'status' => 500 ) );
		}
		$s                  = self::settings();
		$s['business_logo'] = $rel;
		update_option( 'ndwd_accounts', $s, false );
		self::audit( 'settings', 0, 'business_logo_uploaded', $user_id, array( 'file' => basename( $rel ) ) );
		return $s;
	}

	/** Removes the logo from new invoices. The file stays: issued invoices keep showing theirs. */
	public static function remove_logo( $user_id ) {
		$s                  = self::settings();
		$s['business_logo'] = '';
		update_option( 'ndwd_accounts', $s, false );
		self::audit( 'settings', 0, 'business_logo_removed', $user_id );
		return $s;
	}

	public static function stream_logo() {
		$s    = self::settings();
		$path = $s['business_logo'] ? NDWD_Files::path( $s['business_logo'] ) : null;
		if ( ! $path || ! is_readable( $path ) ) {
			return new WP_Error( 'ndwd_not_found', 'No logo uploaded.', array( 'status' => 404 ) );
		}
		NDWD_Files::send_file( $path, 'logo.jpg' );
	}

	public static function remove_qr( $user_id ) {
		$s = self::settings();
		if ( '' !== $s['upi_qr'] ) {
			$s['upi_qr'] = '';
			update_option( 'ndwd_accounts', $s, false );
			self::audit( 'settings', 0, 'upi_qr_removed', $user_id );
		}
		return $s;
	}

	public static function stream_qr() {
		$s    = self::settings();
		$path = $s['upi_qr'] ? NDWD_Files::path( $s['upi_qr'] ) : null;
		if ( ! $path ) {
			return new WP_Error( 'ndwd_not_found', 'No UPI QR code is configured.', array( 'status' => 404 ) );
		}
		NDWD_Files::send_file( $path, 'upi-qr.jpg' );
	}

	/* ------------------------------------------------------------------ *
	 * Audit
	 * ------------------------------------------------------------------ */

	public static function audit( $entity, $id, $action, $user_id, $details = array() ) {
		global $wpdb;
		$wpdb->insert( self::t( 'fin_audit' ), array(
			'entity'     => $entity,
			'entity_id'  => (int) $id,
			'action'     => $action,
			'user_id'    => (int) $user_id,
			'details'    => wp_json_encode( $details ),
			'created_at' => NDWD_Helpers::now_mysql(),
		) );
		do_action( 'ndwd_fin_audit', $entity, $id, $action );
	}

	public static function audit_for( $entity, $id ) {
		global $wpdb;
		return array_map( function ( $a ) {
			return array( 'action' => $a->action, 'user' => NDWD_Helpers::user_name( $a->user_id ), 'details' => json_decode( (string) $a->details, true ), 'created_at' => $a->created_at );
		}, $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'fin_audit' ) . ' WHERE entity = %s AND entity_id = %d ORDER BY id DESC LIMIT 200', $entity, $id ) ) );
	}

	/* ------------------------------------------------------------------ *
	 * Packages
	 * ------------------------------------------------------------------ */

	public static function package_array( $p ) {
		return array(
			'id'             => (int) $p->id,
			'name'           => $p->name,
			'description'    => (string) $p->description,
			'amount'         => (float) $p->amount,
			'services'       => array_values( array_filter( array_map( 'trim', explode( "\n", (string) $p->services ) ) ) ),
			'billing_period' => $p->billing_period,
			'is_active'      => (bool) $p->is_active,
		) + NDWD_Packages::template_extra( $p );
	}

	public static function packages() {
		global $wpdb;
		return array_map( array( __CLASS__, 'package_array' ), $wpdb->get_results( 'SELECT * FROM ' . self::t( 'packages' ) . ' ORDER BY is_active DESC, name' ) ); // phpcs:ignore
	}

	public static function save_package( $in, $user_id, $id = 0 ) {
		global $wpdb;
		$t   = self::t( 'packages' );
		$old = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ) : null;
		if ( $id && ! $old ) {
			return new WP_Error( 'ndwd_not_found', 'Package not found.', array( 'status' => 404 ) );
		}
		$name = mb_substr( sanitize_text_field( isset( $in['name'] ) ? (string) $in['name'] : ( $old ? $old->name : '' ) ), 0, 160 );
		if ( '' === $name ) {
			return new WP_Error( 'ndwd_invalid', 'Package name is required.', array( 'status' => 400 ) );
		}
		$amount = self::paise( isset( $in['amount'] ) ? $in['amount'] : ( $old ? $old->amount : null ) );
		if ( null === $amount || $amount < 0 ) {
			return new WP_Error( 'ndwd_invalid', 'Enter a package amount of 0 or more.', array( 'status' => 400 ) );
		}
		$period = isset( $in['billing_period'] ) ? sanitize_key( $in['billing_period'] ) : ( $old ? $old->billing_period : '' );
		if ( ! isset( self::PERIODS[ $period ] ) ) {
			$period = '';
		}
		$services = isset( $in['services'] ) ? ( is_array( $in['services'] ) ? $in['services'] : explode( "\n", (string) $in['services'] ) ) : ( $old ? explode( "\n", (string) $old->services ) : array() );
		$extra    = NDWD_Packages::template_fields( $in, $old );
		if ( is_wp_error( $extra ) ) {
			return $extra;
		}
		$items = isset( $in['items'] ) ? NDWD_Packages::clean_items( $in['items'] ) : null;
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$row      = array(
			'name'           => $name,
			'description'    => sanitize_textarea_field( isset( $in['description'] ) ? (string) $in['description'] : ( $old ? (string) $old->description : '' ) ),
			'amount'         => self::rupees( $amount ),
			'services'       => implode( "\n", array_filter( array_map( function ( $x ) {
				return mb_substr( sanitize_text_field( (string) $x ), 0, 200 );
			}, $services ) ) ),
			'billing_period' => $period,
			'is_active'      => isset( $in['is_active'] ) ? ( $in['is_active'] ? 1 : 0 ) : ( $old ? (int) $old->is_active : 1 ),
			'updated_at'     => NDWD_Helpers::now_mysql(),
		) + $extra;
		if ( $id ) {
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			// Existing invoices and client allocations keep their own copy of the terms.
			self::audit( 'package', $id, 'package_updated', $user_id, array( 'amount' => array( 'from' => (float) $old->amount, 'to' => $row['amount'] ), 'name' => $row['name'], 'type' => $row['package_type'] ) );
		} else {
			$row['created_by'] = (int) $user_id;
			$row['created_at'] = $row['updated_at'];
			$wpdb->insert( $t, $row );
			$id = (int) $wpdb->insert_id;
			self::audit( 'package', $id, 'package_created', $user_id, array( 'name' => $name, 'amount' => $row['amount'], 'type' => $row['package_type'] ) );
		}
		if ( null !== $items ) {
			NDWD_Packages::save_template_items( $id, $items );
		}
		return self::package_array( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ) );
	}

	/** Deletes a package that no invoice refers to; used packages can only be deactivated. */
	public static function delete_package( $id, $user_id, $dry = false ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'packages' ) . ' WHERE id = %d', $id ) );
		if ( ! $p ) {
			return new WP_Error( 'ndwd_not_found', 'Package not found.', array( 'status' => 404 ) );
		}
		$used  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'invoices' ) . ' WHERE package_id = %d', $id ) );
		$alloc = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'client_packages' ) . ' WHERE package_id = %d', $id ) );
		$links = array_filter( array( 'invoices' => $used, 'client packages' => $alloc ) );
		if ( $dry ) {
			return array( 'name' => $p->name, 'links' => $links, 'blocked' => (bool) $links );
		}
		if ( $links ) {
			return new WP_Error( 'ndwd_in_use', 'This package is used on ' . $used . ' invoice(s) and assigned to ' . $alloc . ' client(s), so it is kept for the history. Mark it inactive instead.', array( 'status' => 409 ) );
		}
		$wpdb->delete( self::t( 'packages' ), array( 'id' => $id ) );
		$wpdb->delete( self::t( 'package_items' ), array( 'package_id' => $id ) );
		self::audit( 'package', $id, 'package_deleted', $user_id, array( 'name' => $p->name ) );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ *
	 * Billable work
	 * ------------------------------------------------------------------ */

	/** Non-cancelled invoices that already bill each task: [task_id => [invoice_no, ...]]. */
	private static function billed_map( $task_ids, $exclude_invoice = 0 ) {
		global $wpdb;
		$out = array();
		if ( ! $task_ids ) {
			return $out;
		}
		$vals = array_merge( $task_ids, array( (int) $exclude_invoice ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT i.task_id, v.invoice_no, v.id FROM ' . self::t( 'invoice_items' ) . ' i JOIN ' . self::t( 'invoices' ) . " v ON v.id = i.invoice_id WHERE i.task_id IN (" . NDWD_Helpers::in_placeholders( $task_ids ) . ") AND v.status <> 'cancelled' AND v.id <> %d",
			$vals
		) );
		foreach ( $rows as $r ) {
			$out[ (int) $r->task_id ][] = array( 'id' => (int) $r->id, 'invoice_no' => $r->invoice_no );
		}
		return $out;
	}

	/**
	 * Completed work for a client (its own client_id or its project's client), with any
	 * invoice that already bills it. Optional completion date range.
	 */
	public static function billable( $client_id, $from = '', $to = '', $exclude_invoice = 0 ) {
		global $wpdb;
		if ( ! NDWD_Clients::get( $client_id ) ) {
			return new WP_Error( 'ndwd_not_found', 'Client not found.', array( 'status' => 404 ) );
		}
		$tasks = self::t( 'tasks' );
		$sql   = "SELECT t.id, t.title, t.completed_at, t.scheduled_date, t.project_id, t.client_package_id, t.package_item_id, t.package_scope, t.package_note FROM $tasks t LEFT JOIN " . self::t( 'projects' ) . " p ON p.id = t.project_id
			WHERE t.status = 'completed' AND ( t.client_id = %d OR ( t.client_id IS NULL AND p.client_id = %d ) )";
		$vals  = array( $client_id, $client_id );
		if ( $from && $to ) {
			$sql   .= ' AND DATE(t.completed_at) BETWEEN %s AND %s';
			$vals[] = $from;
			$vals[] = $to;
		}
		$sql .= ' ORDER BY t.completed_at DESC LIMIT 500';
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore
		$ids    = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$billed = self::billed_map( $ids, $exclude_invoice );
		$proj   = NDWD_Stats::lookup( 'projects' );
		// Latest remarks of each task, for the work log.
		$remarks = array();
		if ( $ids ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT task_id, body FROM ' . self::t( 'remarks' ) . ' WHERE task_id IN (' . NDWD_Helpers::in_placeholders( $ids ) . ') ORDER BY id DESC', $ids ) ) as $rm ) {
				if ( ! isset( $remarks[ $rm->task_id ] ) || count( $remarks[ $rm->task_id ] ) < 3 ) {
					$remarks[ $rm->task_id ][] = mb_substr( $rm->body, 0, 300 );
				}
			}
		}
		return array_map( function ( $r ) use ( $billed, $proj, $remarks ) {
			$pkg = NDWD_Packages::task_summary( $r );
			return array(
				'id'           => (int) $r->id,
				'title'        => $r->title,
				'completed_at' => $r->completed_at,
				'project_id'   => $r->project_id ? (int) $r->project_id : null,
				'project'      => $r->project_id && isset( $proj[ $r->project_id ] ) ? $proj[ $r->project_id ] : '',
				'billed_in'    => isset( $billed[ (int) $r->id ] ) ? $billed[ (int) $r->id ] : array(),
				'package'      => $pkg,
				'remarks'      => isset( $remarks[ $r->id ] ) ? $remarks[ $r->id ] : array(),
				// Suggestion only: included and retainer work default to "Included in package".
				'suggested'    => $pkg && in_array( $pkg['scope'], array( 'included', 'retainer' ), true ) && $pkg['client_package_id'] ? 'included' : 'custom',
			);
		}, $rows );
	}

	/** Active (non-cancelled) invoices that bill each expense: [expense_id => [{id, invoice_no}]]. */
	private static function expense_billed_map( $expense_ids, $exclude_invoice = 0 ) {
		global $wpdb;
		$out = array();
		if ( ! $expense_ids ) {
			return $out;
		}
		$vals = array_merge( $expense_ids, array( (int) $exclude_invoice ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT i.expense_id, v.invoice_no, v.id FROM ' . self::t( 'invoice_items' ) . ' i JOIN ' . self::t( 'invoices' ) . ' v ON v.id = i.invoice_id WHERE i.expense_id IN (' . NDWD_Helpers::in_placeholders( $expense_ids ) . ") AND v.status <> 'cancelled' AND v.id <> %d",
			$vals
		) );
		foreach ( $rows as $r ) {
			$out[ (int) $r->expense_id ][] = array( 'id' => (int) $r->id, 'invoice_no' => $r->invoice_no );
		}
		return $out;
	}

	/** Client an expense belongs to: its own client, or its project's client. */
	private static function expense_client( $e ) {
		return $e->client_id ? (int) $e->client_id : ( $e->project_id ? (int) NDWD_Clients::for_project( $e->project_id ) : 0 );
	}

	/**
	 * Expenses of a client for invoicing. By default only expenses marked billable; with $all,
	 * also the client's other expenses so they can be reviewed and marked billable first.
	 * Each carries the invoices that already bill it (billed_in).
	 */
	public static function billable_expenses( $client_id, $project_id = 0, $exclude_invoice = 0, $all = false ) {
		global $wpdb;
		if ( ! NDWD_Clients::get( $client_id ) ) {
			return new WP_Error( 'ndwd_not_found', 'Client not found.', array( 'status' => 404 ) );
		}
		$e    = self::t( 'expenses' );
		$sql  = "SELECT e.* FROM $e e LEFT JOIN " . self::t( 'projects' ) . ' p ON p.id = e.project_id WHERE e.deleted_at IS NULL AND ( e.client_id = %d OR ( e.client_id IS NULL AND p.client_id = %d ) )';
		$vals = array( $client_id, $client_id );
		if ( ! $all ) {
			$sql .= ' AND e.billable = 1';
		}
		if ( $project_id ) {
			$sql   .= ' AND e.project_id = %d';
			$vals[] = absint( $project_id );
		}
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY e.expense_date DESC, e.id DESC LIMIT 500', $vals ) ); // phpcs:ignore
		$billed = self::expense_billed_map( array_map( 'intval', wp_list_pluck( $rows, 'id' ) ), $exclude_invoice );
		$proj   = NDWD_Stats::lookup( 'projects' );
		return array_map( function ( $r ) use ( $billed, $proj ) {
			$a              = self::expense_array( $r );
			$a['project']   = $r->project_id && isset( $proj[ $r->project_id ] ) ? $proj[ $r->project_id ] : '';
			$a['billed_in'] = isset( $billed[ (int) $r->id ] ) ? $billed[ (int) $r->id ] : array();
			return $a;
		}, $rows );
	}

	/** Money actually received on an invoice (payments only), in paise: settled minus advances and transfers. */
	private static function received_paise( $v ) {
		return self::paise( $v->amount_paid ) - self::paise( $v->amount_adjusted ) - self::paise( isset( $v->amount_transferred ) ? $v->amount_transferred : 0 );
	}

	/**
	 * Open invoices of a client with a balance (Unpaid, Partially Paid, overdue), oldest first.
	 * The balance comes from recorded payments, allocations and transfers, never from the total.
	 */
	public static function outstanding_invoices( $client_id, $exclude_invoice = 0 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'invoices' ) . " WHERE client_id = %d AND status IN ('unpaid','partial') AND id <> %d ORDER BY due_date, id", $client_id, $exclude_invoice ) );
		$out  = array();
		foreach ( $rows as $v ) {
			$a = self::invoice_array( $v );
			if ( $a['balance'] > 0 ) {
				$out[] = $a;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Invoices
	 * ------------------------------------------------------------------ */

	public static function client_snapshot( $c ) {
		return array(
			'id'               => (int) $c->id,
			'name'             => $c->billing_name ? $c->billing_name : $c->name,
			'client_name'      => $c->name,
			'company'          => $c->company,
			'contact'          => $c->billing_contact ? $c->billing_contact : $c->contact_person,
			'whatsapp'         => $c->billing_whatsapp,
			'email'            => $c->billing_email,
			'emails'           => NDWD_Clients::billing_emails( $c ),
			'address'          => (string) $c->billing_address,
			'gstin'            => $c->gstin,
		);
	}

	private static function business_snapshot() {
		$s = self::settings();
		$o = array();
		foreach ( array( 'business_name', 'address', 'gstin', 'phone', 'email', 'website', 'bank_name', 'account_name', 'account_number', 'ifsc', 'upi_id', 'upi_qr', 'business_logo' ) as $k ) {
			$o[ $k ] = $s[ $k ];
		}
		return $o;
	}

	/**
	 * Validates invoice input and computes the lines and totals (in paise).
	 *
	 * @return array|WP_Error
	 */
	public static function prepare( $in, $user_id, $exclude_invoice = 0 ) {
		$client = NDWD_Clients::get( absint( isset( $in['client_id'] ) ? $in['client_id'] : 0 ) );
		if ( ! $client ) {
			return new WP_Error( 'ndwd_invalid', 'Select a client.', array( 'status' => 400 ) );
		}
		$s     = self::settings();
		$today = NDWD_Helpers::today();
		$date  = NDWD_Helpers::sanitize_date( isset( $in['invoice_date'] ) ? (string) $in['invoice_date'] : '' );
		$date  = $date ? $date : $today;
		$due   = NDWD_Helpers::sanitize_date( isset( $in['due_date'] ) ? (string) $in['due_date'] : '' );
		$due   = $due ? $due : ( new DateTimeImmutable( $date ) )->modify( '+' . (int) $s['due_days'] . ' days' )->format( 'Y-m-d' );
		if ( $due < $date ) {
			return new WP_Error( 'ndwd_invalid', 'The due date cannot be before the invoice date.', array( 'status' => 400 ) );
		}
		$pkg_name   = mb_substr( sanitize_text_field( isset( $in['package_name'] ) ? (string) $in['package_name'] : '' ), 0, 160 );
		$pkg_amount = self::paise( isset( $in['package_amount'] ) && '' !== $in['package_amount'] ? $in['package_amount'] : 0 );
		$pkg_id     = absint( isset( $in['package_id'] ) ? $in['package_id'] : 0 );
		$alloc_id   = absint( isset( $in['client_package_id'] ) ? $in['client_package_id'] : 0 );
		if ( $alloc_id ) {
			$alloc = NDWD_Packages::allocation( $alloc_id );
			if ( ! $alloc || (int) $alloc->client_id !== (int) $client->id ) {
				return new WP_Error( 'ndwd_invalid', 'The selected client package belongs to another client.', array( 'status' => 400 ) );
			}
			if ( ! $pkg_id && $alloc->package_id ) {
				$pkg_id = (int) $alloc->package_id;
			}
		}
		$has_pkg = $pkg_amount > 0 || '' !== $pkg_name || $alloc_id;
		if ( null === $pkg_amount || $pkg_amount < 0 ) {
			return new WP_Error( 'ndwd_invalid', 'Package amount must be 0 or more.', array( 'status' => 400 ) );
		}
		if ( $pkg_amount > 0 && '' === $pkg_name ) {
			return new WP_Error( 'ndwd_invalid', 'Enter the package name.', array( 'status' => 400 ) );
		}
		// Several packages, each with its own name, price, terms and service period. A single
		// package sent the older way stays one package without a period.
		$packages = array();
		if ( ! empty( $in['packages'] ) && is_array( $in['packages'] ) ) {
			$packages = self::prepare_packages( $in['packages'], $client, $date, $exclude_invoice );
			if ( is_wp_error( $packages ) ) {
				return $packages;
			}
			$pkg_amount = array_sum( wp_list_pluck( $packages, 'amount' ) );
			$pkg_name   = mb_substr( implode( ', ', wp_list_pluck( $packages, 'name' ) ), 0, 160 );
			$pkg_id     = (int) $packages[0]['package_id'];
			$alloc_id   = (int) $packages[0]['client_package_id'];
			$has_pkg    = (bool) $packages;
		} elseif ( $pkg_amount > 0 || '' !== $pkg_name ) {
			$packages[] = array( 'name' => $pkg_name, 'amount' => $pkg_amount, 'package_id' => $pkg_id ? $pkg_id : null, 'client_package_id' => $alloc_id ? $alloc_id : null, 'package_type' => '', 'billing_month' => '', 'period_start' => null, 'period_end' => null, 'terms' => '' );
		}
		// Work Log groups: client-facing headings. Tasks point at a group by its number.
		$groups = array();
		foreach ( (array) ( isset( $in['groups'] ) ? $in['groups'] : array() ) as $g ) {
			$heading = mb_substr( sanitize_text_field( is_array( $g ) && isset( $g['heading'] ) ? (string) $g['heading'] : '' ), 0, 160 );
			$grem    = mb_substr( sanitize_textarea_field( is_array( $g ) && isset( $g['remarks'] ) ? (string) $g['remarks'] : '' ), 0, 1000 );
			if ( '' === $heading ) {
				return new WP_Error( 'ndwd_invalid', 'Give every Work Log group a heading, or remove the group.', array( 'status' => 400 ) );
			}
			$groups[] = array( 'heading' => $heading, 'remarks' => $grem );
		}
		$lines    = array();
		$subtotal = $pkg_amount;
		$task_ids = array();
		$overrides = array();
		foreach ( (array) ( isset( $in['items'] ) ? $in['items'] : array() ) as $i => $it ) {
			$task_id   = absint( isset( $it['task_id'] ) ? $it['task_id'] : 0 );
			$treatment = isset( $it['treatment'] ) && 'included' === $it['treatment'] ? 'included' : 'custom';
			$desc      = mb_substr( sanitize_text_field( isset( $it['description'] ) ? (string) $it['description'] : '' ), 0, 255 );
			$amount    = 'included' === $treatment ? 0 : self::paise( isset( $it['amount'] ) ? $it['amount'] : null );
			$remarks   = mb_substr( sanitize_textarea_field( isset( $it['remarks'] ) ? (string) $it['remarks'] : '' ), 0, 1000 );
			$done_on   = null;
			if ( $task_id ) {
				$task = NDWD_Tasks::get( $task_id );
				$tcl  = $task ? ( $task->client_id ? (int) $task->client_id : ( $task->project_id ? NDWD_Clients::for_project( $task->project_id ) : 0 ) ) : 0;
				if ( ! $task || 'completed' !== $task->status || (int) $tcl !== (int) $client->id ) {
					return new WP_Error( 'ndwd_invalid', 'Task #' . $task_id . ' is not completed work of this client.', array( 'status' => 400 ) );
				}
				if ( in_array( $task_id, $task_ids, true ) ) {
					return new WP_Error( 'ndwd_invalid', 'Task #' . $task_id . ' is listed twice.', array( 'status' => 400 ) );
				}
				$task_ids[] = $task_id;
				$desc       = '' !== $desc ? $desc : $task->title;
				$done_on    = $task->completed_at ? substr( $task->completed_at, 0, 10 ) : null;
				if ( 'included' === $treatment && ! $has_pkg ) {
					return new WP_Error( 'ndwd_invalid', '"' . $desc . '" is marked "Included in package", but the invoice has no package.', array( 'status' => 400 ) );
				}
				$reason = isset( $it['override_reason'] ) ? mb_substr( sanitize_text_field( (string) $it['override_reason'] ), 0, 255 ) : '';
				if ( '' !== $reason ) {
					$overrides[ $task_id ] = $reason;
				}
			} elseif ( 'included' === $treatment && ! $has_pkg ) {
				return new WP_Error( 'ndwd_invalid', 'Line ' . ( $i + 1 ) . ' is marked "Included in package", but the invoice has no package.', array( 'status' => 400 ) );
			}
			if ( '' === $desc ) {
				return new WP_Error( 'ndwd_invalid', 'Line ' . ( $i + 1 ) . ': enter a description.', array( 'status' => 400 ) );
			}
			if ( 'custom' === $treatment && ( null === $amount || $amount <= 0 ) ) {
				return new WP_Error( 'ndwd_invalid', '"' . $desc . '": enter an amount greater than 0, or mark it "Included in package".', array( 'status' => 400 ) );
			}
			$group_no = absint( isset( $it['group'] ) ? $it['group'] : 0 );
			if ( $group_no > count( $groups ) ) {
				return new WP_Error( 'ndwd_invalid', '"' . $desc . '" points at a Work Log group that does not exist.', array( 'status' => 400 ) );
			}
			$subtotal += $amount;
			$lines[]   = array( 'task_id' => $task_id ? $task_id : null, 'description' => $desc, 'treatment' => $treatment, 'amount' => $amount, 'override_reason' => isset( $overrides[ $task_id ] ) ? $overrides[ $task_id ] : '', 'remarks' => $remarks, 'completed_on' => $done_on, 'group_no' => $group_no );
		}
		// Reimbursable client expenses: each expense on one active invoice only. The expense
		// row is only read; it stays in Expenses unchanged.
		$exp_ids = array();
		$exp_sub = 0;
		foreach ( (array) ( isset( $in['expenses'] ) ? $in['expenses'] : array() ) as $x ) {
			$eid = absint( is_array( $x ) && isset( $x['expense_id'] ) ? $x['expense_id'] : 0 );
			if ( ! $eid ) {
				continue;
			}
			$e = self::expense_row( $eid );
			if ( ! $e || self::expense_client( $e ) !== (int) $client->id ) {
				return new WP_Error( 'ndwd_invalid', 'Expense #' . $eid . ' is not an expense of this client.', array( 'status' => 400 ) );
			}
			if ( ! (int) $e->billable ) {
				return new WP_Error( 'ndwd_invalid', '"' . $e->title . '" is not marked billable to the client. Review it and mark it billable first.', array( 'status' => 400 ) );
			}
			if ( in_array( $eid, $exp_ids, true ) ) {
				return new WP_Error( 'ndwd_invalid', '"' . $e->title . '" is listed twice.', array( 'status' => 400 ) );
			}
			$proposed = self::paise( null !== $e->billable_amount ? $e->billable_amount : $e->amount );
			$amt      = isset( $x['amount'] ) && '' !== $x['amount'] ? self::paise( $x['amount'] ) : $proposed;
			if ( null === $amt || $amt <= 0 ) {
				return new WP_Error( 'ndwd_invalid', '"' . $e->title . '": enter a billable amount greater than 0.', array( 'status' => 400 ) );
			}
			$reason = mb_substr( sanitize_text_field( isset( $x['adjust_reason'] ) ? (string) $x['adjust_reason'] : '' ), 0, 255 );
			if ( $amt !== $proposed && '' === $reason ) {
				return new WP_Error( 'ndwd_invalid', '"' . $e->title . '": give the reason for billing Rs. ' . self::inr( self::rupees( $amt ) ) . ' instead of Rs. ' . self::inr( self::rupees( $proposed ) ) . '.', array( 'status' => 400 ) );
			}
			$desc       = mb_substr( sanitize_text_field( isset( $x['description'] ) ? (string) $x['description'] : '' ), 0, 255 );
			$exp_ids[]  = $eid;
			$exp_sub   += $amt;
			$subtotal  += $amt;
			$lines[]    = array( 'task_id' => null, 'expense_id' => $eid, 'description' => '' !== $desc ? $desc : $e->title, 'treatment' => 'expense', 'amount' => $amt, 'override_reason' => $amt !== $proposed ? $reason : '', 'remarks' => (string) $e->billing_remarks, 'completed_on' => $e->expense_date, 'original_amount' => self::paise( $e->amount ) );
		}
		foreach ( self::expense_billed_map( $exp_ids, $exclude_invoice ) as $eid => $list ) {
			$e = self::expense_row( $eid );
			return new WP_Error( 'ndwd_already_billed', '"' . $e->title . '" is already billed in ' . implode( ', ', wp_list_pluck( $list, 'invoice_no' ) ) . '. An expense can be billed only once; it becomes available again if that invoice is cancelled.', array( 'status' => 409, 'expense_id' => $eid ) );
		}
		// Previous outstanding invoices: shown as a summary (a snapshot, never revenue), or
		// explicitly consolidated: their remaining balance is brought forward outside tax.
		$cons    = array_values( array_unique( array_filter( array_map( 'absint', (array) ( isset( $in['consolidate'] ) ? $in['consolidate'] : array() ) ) ) ) );
		$show    = array_values( array_unique( array_filter( array_map( 'absint', (array) ( isset( $in['previous'] ) ? $in['previous'] : array() ) ) ) ) );
		$prev    = array();
		$carry   = array();
		$carried = 0;
		foreach ( array_values( array_unique( array_merge( $show, $cons ) ) ) as $vid ) {
			$o = self::invoice_row( $vid );
			if ( ! $o || (int) $o->client_id !== (int) $client->id || $vid === (int) $exclude_invoice ) {
				return new WP_Error( 'ndwd_invalid', 'Invoice #' . $vid . ' is not an earlier invoice of this client.', array( 'status' => 400 ) );
			}
			$bal = self::paise( $o->total ) - self::paise( $o->amount_paid );
			if ( ! in_array( $o->status, array( 'unpaid', 'partial' ), true ) || $bal <= 0 ) {
				return new WP_Error( 'ndwd_invalid', $o->invoice_no . ' is ' . self::INVOICE_STATUSES[ $o->status ] . ' with no open balance; only unpaid or partially paid invoices can be shown or consolidated.', array( 'status' => 400 ) );
			}
			if ( in_array( $vid, $cons, true ) ) {
				$carry[ $vid ] = $bal;
				$carried      += $bal;
				$lines[]       = array( 'task_id' => null, 'source_invoice_id' => $vid, 'description' => 'Balance brought forward from ' . $o->invoice_no . ' dated ' . date_i18n( 'j M Y', strtotime( $o->invoice_date ) ), 'treatment' => 'carried', 'amount' => $bal, 'override_reason' => '', 'remarks' => 'Invoice total Rs. ' . self::inr( $o->total ) . ', settled Rs. ' . self::inr( $o->amount_paid ) . ' before consolidation', 'completed_on' => null );
				continue;
			}
			$prev[] = array( 'id' => (int) $o->id, 'invoice_no' => $o->invoice_no, 'invoice_date' => $o->invoice_date, 'due_date' => $o->due_date, 'total' => (float) $o->total, 'received' => self::rupees( self::received_paise( $o ) ), 'adjusted' => (float) $o->amount_adjusted, 'balance' => self::rupees( $bal ), 'status' => $o->status, 'status_label' => self::INVOICE_STATUSES[ $o->status ], 'overdue' => self::is_overdue( $o ) );
		}
		$cons_reason = mb_substr( sanitize_textarea_field( isset( $in['consolidate_reason'] ) ? (string) $in['consolidate_reason'] : '' ), 0, 500 );
		if ( $carry && '' === trim( $cons_reason ) ) {
			return new WP_Error( 'ndwd_invalid', 'Give the reason for consolidating the earlier invoice balance(s) into this invoice.', array( 'status' => 400 ) );
		}
		if ( $subtotal + $carried <= 0 ) {
			return new WP_Error( 'ndwd_invalid', 'The invoice total is zero. Add a package amount or a line with an amount.', array( 'status' => 400 ) );
		}
		// The same completed work must not be billed twice, unless explicitly overridden with a reason.
		$billed = self::billed_map( $task_ids, $exclude_invoice );
		foreach ( $billed as $tid => $list ) {
			if ( empty( $overrides[ $tid ] ) ) {
				return new WP_Error( 'ndwd_already_billed', 'Task #' . $tid . ' is already billed in ' . implode( ', ', wp_list_pluck( $list, 'invoice_no' ) ) . '. Remove it, or give an override reason to bill it again.', array( 'status' => 409, 'task_id' => $tid ) );
			}
		}
		$discount = self::paise( isset( $in['discount'] ) && '' !== $in['discount'] ? $in['discount'] : 0 );
		if ( null === $discount || $discount < 0 || $discount > $subtotal ) {
			return new WP_Error( 'ndwd_invalid', 'Discount must be between 0 and the subtotal.', array( 'status' => 400 ) );
		}
		$rate = isset( $in['tax_rate'] ) && '' !== $in['tax_rate'] ? (float) $in['tax_rate'] : 0;
		if ( $rate < 0 || $rate > 100 ) {
			return new WP_Error( 'ndwd_invalid', 'Tax rate must be between 0 and 100.', array( 'status' => 400 ) );
		}
		$taxable = $subtotal - $discount;
		$tax     = (int) round( $taxable * $rate / 100 );
		$allocs  = self::check_allocations( isset( $in['allocations'] ) ? $in['allocations'] : array(), (int) $client->id, $taxable + $tax + $carried, $exclude_invoice );
		if ( is_wp_error( $allocs ) ) {
			return $allocs;
		}
		$adjusted = array_sum( wp_list_pluck( $allocs, 'amount' ) );
		$summary  = null;
		if ( $prev ) {
			// Advance credit = the client's unallocated manual income left after this invoice's adjustments.
			$credit = 0;
			foreach ( self::available_income( (int) $client->id, 0, $exclude_invoice ) as $x ) {
				$credit += self::paise( $x['available'] );
			}
			$summary = array(
				'as_of'            => $date,
				'invoices'         => $prev,
				'previous_balance' => self::rupees( array_sum( array_map( function ( $r ) {
					return self::paise( $r['balance'] );
				}, $prev ) ) ),
				'advance_credit'   => self::rupees( max( 0, $credit - $adjusted ) ),
				'current_balance'  => self::rupees( $taxable + $tax + $carried - $adjusted ),
			);
		}
		// Billing details default to the client record; a replacement invoice may carry corrected ones.
		$billing = self::client_snapshot( $client );
		if ( ! empty( $in['billing'] ) && is_array( $in['billing'] ) ) {
			foreach ( array( 'name' => 160, 'contact' => 160, 'whatsapp' => 40, 'email' => 190, 'gstin' => 15 ) as $k => $len ) {
				if ( isset( $in['billing'][ $k ] ) ) {
					$billing[ $k ] = mb_substr( sanitize_text_field( (string) $in['billing'][ $k ] ), 0, $len );
				}
			}
			if ( isset( $in['billing']['address'] ) ) {
				$billing['address'] = mb_substr( sanitize_textarea_field( (string) $in['billing']['address'] ), 0, 1000 );
			}
			if ( '' !== $billing['email'] && ! is_email( $billing['email'] ) ) {
				return new WP_Error( 'ndwd_invalid', 'The billing email is not valid.', array( 'status' => 400 ) );
			}
			if ( '' === trim( $billing['name'] ) ) {
				return new WP_Error( 'ndwd_invalid', 'Enter the billing name.', array( 'status' => 400 ) );
			}
		}
		// Recipients for this invoice only; the client's saved billing details are not changed.
		if ( isset( $in['recipients'] ) && is_array( $in['recipients'] ) ) {
			$list = NDWD_Clients::clean_emails( $in['recipients'], 'Recipient' );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			list( $to )        = NDWD_Clients::split_emails( $list );
			$billing['emails'] = $list;
			$billing['email']  = $to ? $to[0] : '';
		} elseif ( ! empty( $in['billing']['email'] ) && empty( $billing['emails'] ) ) {
			$billing['emails'] = array( array( 'email' => $billing['email'], 'type' => 'to' ) );
		}
		return array(
			'billing'      => $billing,
			'client'       => $client,
			'invoice_date' => $date,
			'due_date'     => $due,
			'package_id'   => $pkg_id ? $pkg_id : null,
			'client_package_id' => $alloc_id ? $alloc_id : null,
			'package_name' => $pkg_name,
			'package'      => $pkg_amount,
			'packages'     => $packages,
			'groups'       => $groups,
			'lines'        => $lines,
			'subtotal'     => $subtotal,
			'discount'     => $discount,
			'discount_label' => mb_substr( sanitize_text_field( isset( $in['discount_label'] ) ? (string) $in['discount_label'] : '' ), 0, 160 ),
			'tax_label'    => $rate > 0 ? mb_substr( sanitize_text_field( isset( $in['tax_label'] ) && '' !== $in['tax_label'] ? (string) $in['tax_label'] : $s['tax_label'] ), 0, 40 ) : '',
			'tax_rate'     => round( $rate, 2 ),
			'tax'          => $tax,
			'total'        => $taxable + $tax + $carried,
			'expense_subtotal' => $exp_sub,
			'carried'      => $carried,
			'carry'        => $carry,
			'consolidate_reason' => $cons_reason,
			'prev_summary' => $summary,
			'notes'        => sanitize_textarea_field( isset( $in['notes'] ) ? (string) $in['notes'] : '' ),
			'work_remarks' => mb_substr( sanitize_textarea_field( isset( $in['work_remarks'] ) ? (string) $in['work_remarks'] : '' ), 0, 4000 ),
			'payment_instructions' => sanitize_textarea_field( isset( $in['payment_instructions'] ) ? (string) $in['payment_instructions'] : $s['payment_instructions'] ),
			'overrides'    => $overrides,
			'allocations'  => $allocs,
			'adjusted'     => $adjusted,
		);
	}

	/**
	 * Validates the packages of one invoice. Billing a month here never takes package units: usage
	 * follows tasks and the cycle of their own dates, so a November advance on an October invoice
	 * leaves both months' counters as they are.
	 *
	 * @return array|WP_Error Packages with amounts in paise and their service periods.
	 */
	private static function prepare_packages( $list, $client, $invoice_date, $exclude_invoice = 0 ) {
		global $wpdb;
		$out  = array();
		$seen = array();
		foreach ( $list as $i => $x ) {
			$n      = $i + 1;
			$name   = mb_substr( sanitize_text_field( isset( $x['name'] ) ? (string) $x['name'] : '' ), 0, 160 );
			$amount = self::paise( isset( $x['amount'] ) && '' !== $x['amount'] ? $x['amount'] : 0 );
			if ( null === $amount || $amount < 0 ) {
				return new WP_Error( 'ndwd_invalid', 'Package ' . $n . ': the price must be 0 or more.', array( 'status' => 400 ) );
			}
			$alloc_id = absint( isset( $x['client_package_id'] ) ? $x['client_package_id'] : 0 );
			$tpl_id   = absint( isset( $x['package_id'] ) ? $x['package_id'] : 0 );
			$alloc    = null;
			if ( $alloc_id ) {
				$alloc = NDWD_Packages::allocation( $alloc_id );
				if ( ! $alloc || (int) $alloc->client_id !== (int) $client->id ) {
					return new WP_Error( 'ndwd_invalid', 'Package ' . $n . ' belongs to another client.', array( 'status' => 400 ) );
				}
				if ( ! $exclude_invoice && in_array( $alloc->status, array( 'cancelled', 'archived', 'inactive' ), true ) ) {
					return new WP_Error( 'ndwd_invalid', '"' . $alloc->name . '" is ' . strtolower( NDWD_Packages::STATUSES[ $alloc->status ] ) . ', so it cannot be billed on a new invoice.', array( 'status' => 400 ) );
				}
				$tpl_id = $tpl_id ? $tpl_id : (int) $alloc->package_id;
				$name   = '' !== $name ? $name : $alloc->name;
			}
			if ( '' === $name ) {
				return new WP_Error( 'ndwd_invalid', 'Package ' . $n . ': enter the package name.', array( 'status' => 400 ) );
			}
			$type = sanitize_key( isset( $x['package_type'] ) ? (string) $x['package_type'] : ( $alloc ? $alloc->package_type : '' ) );
			$type = isset( NDWD_Packages::TYPES[ $type ] ) ? $type : '';
			if ( ! $type && $tpl_id ) {
				$type = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT package_type FROM ' . self::t( 'packages' ) . ' WHERE id = %d', $tpl_id ) );
			}
			$month = isset( $x['billing_month'] ) ? (string) $x['billing_month'] : '';
			$start = NDWD_Helpers::sanitize_date( isset( $x['period_start'] ) ? (string) $x['period_start'] : '' );
			$end   = NDWD_Helpers::sanitize_date( isset( $x['period_end'] ) ? (string) $x['period_end'] : '' );
			$start = $start ? $start : null;
			$end   = $end ? $end : null;
			if ( 'monthly' === $type ) {
				$month = '' !== $month ? $month : substr( $invoice_date, 0, 7 );
				if ( ! preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
					return new WP_Error( 'ndwd_invalid', '"' . $name . '": choose a valid billing month.', array( 'status' => 400 ) );
				}
				$limit = ( new DateTimeImmutable( $invoice_date ) )->modify( 'first day of +12 months' )->format( 'Y-m' );
				if ( $month > $limit ) {
					return new WP_Error( 'ndwd_invalid', '"' . $name . '": the billing month can be at most 12 months after the invoice date.', array( 'status' => 400 ) );
				}
				list( $m_from, $m_to ) = NDWD_Helpers::month_range( $month );
				$start = $m_from;
				$end   = $m_to;
				if ( $alloc ) {
					if ( $alloc->start_date > $m_to || ( $alloc->end_date && $alloc->end_date < $m_from ) ) {
						return new WP_Error( 'ndwd_invalid', '"' . $name . '" is not running in ' . date_i18n( 'F Y', strtotime( $m_from ) ) . ' (' . $alloc->start_date . ( $alloc->end_date ? ' to ' . $alloc->end_date : ' onwards' ) . ').', array( 'status' => 400 ) );
					}
					$probe = $month . '-' . str_pad( min( (int) substr( $alloc->start_date, 8, 2 ), (int) gmdate( 't', strtotime( $m_from ) ) ), 2, '0', STR_PAD_LEFT );
					$cycle = NDWD_Packages::cycle_for( $alloc, max( $probe, $alloc->start_date ) );
					if ( $cycle && NDWD_Packages::resets( $alloc ) ) {
						$start = $cycle[0];
						$end   = $cycle[1];
					}
				}
			} else {
				$month = '';
				if ( $alloc && ! $start ) {
					$start = $alloc->start_date;
					$end   = $alloc->end_date;
				}
				if ( $start && $end && $end < $start ) {
					return new WP_Error( 'ndwd_invalid', '"' . $name . '": the service period ends before it starts.', array( 'status' => 400 ) );
				}
			}
			if ( $alloc_id && '' !== $month ) {
				if ( isset( $seen[ $alloc_id . ':' . $month ] ) ) {
					return new WP_Error( 'ndwd_invalid', '"' . $name . '" is listed twice for ' . $month . '.', array( 'status' => 400 ) );
				}
				$seen[ $alloc_id . ':' . $month ] = true;
			}
			$out[] = array(
				'name'              => $name,
				'amount'            => $amount,
				'package_id'        => $tpl_id ? $tpl_id : null,
				'client_package_id' => $alloc_id ? $alloc_id : null,
				'package_type'      => $type,
				'billing_month'     => $month,
				'period_start'      => $start,
				'period_end'        => $end,
				'terms'             => mb_substr( sanitize_textarea_field( isset( $x['terms'] ) ? (string) $x['terms'] : ( $alloc ? (string) $alloc->terms : '' ) ), 0, 2000 ),
			);
		}
		return $out;
	}

	/** Package line of an invoice for the preview, PDF and detail page. */
	private static function package_line( $x ) {
		return array( 'task_id' => null, 'expense_id' => null, 'source_invoice_id' => null, 'original_amount' => null, 'description' => $x['name'], 'treatment' => 'package', 'amount' => self::rupees( $x['amount'] ), 'override_reason' => '', 'remarks' => '', 'completed_on' => null, 'group_no' => 0, 'client_package_id' => $x['client_package_id'], 'billing_month' => $x['billing_month'], 'period_start' => $x['period_start'], 'period_end' => $x['period_end'], 'terms' => $x['terms'] );
	}

	/**
	 * Account position shown with a previous-outstanding summary: current balance + previous −
	 * advance credit. The whole statement is as on the invoice date (snapshot), so later
	 * payments never mix live and old figures.
	 */
	private static function account_summary( $balance, $ps ) {
		if ( ! $ps || empty( $ps['invoices'] ) ) {
			return null;
		}
		$balance = isset( $ps['current_balance'] ) ? self::paise( $ps['current_balance'] ) : $balance;
		$prev    = self::paise( $ps['previous_balance'] );
		$credit = self::paise( $ps['advance_credit'] );
		return array_merge( $ps, array(
			'current_balance' => self::rupees( $balance ),
			'total_payable'   => self::rupees( max( 0, $balance + $prev - $credit ) ),
		) );
	}

	/** Preview without saving: the numbers and lines exactly as they would be issued. */
	public static function preview( $in, $user_id ) {
		$p = self::prepare( $in, $user_id );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$s = self::settings();
		return array(
			'invoice_no'   => self::next_number( self::fy_of( $p['invoice_date'] ), true ),
			'fy'           => self::fy_of( $p['invoice_date'] ),
			'invoice_date' => $p['invoice_date'],
			'due_date'     => $p['due_date'],
			'client'       => $p['billing'],
			'business'     => self::business_snapshot(),
			'package_name' => $p['package_name'],
			'package_amount' => self::rupees( $p['package'] ),
			'groups'       => $p['groups'],
			'discount_label' => $p['discount_label'],
			'packages'     => array_map( array( __CLASS__, 'package_line' ), $p['packages'] ),
			'items'        => array_map( function ( $l ) {
				return array( 'group_no' => isset( $l['group_no'] ) ? (int) $l['group_no'] : 0, 'task_id' => $l['task_id'], 'expense_id' => isset( $l['expense_id'] ) ? $l['expense_id'] : null, 'source_invoice_id' => isset( $l['source_invoice_id'] ) ? $l['source_invoice_id'] : null, 'original_amount' => isset( $l['original_amount'] ) ? self::rupees( $l['original_amount'] ) : null, 'description' => $l['description'], 'treatment' => $l['treatment'], 'amount' => self::rupees( $l['amount'] ), 'override_reason' => $l['override_reason'], 'remarks' => $l['remarks'], 'completed_on' => $l['completed_on'] );
			}, $p['lines'] ),
			'expense_subtotal' => self::rupees( $p['expense_subtotal'] ),
			'carried_forward'  => self::rupees( $p['carried'] ),
			'account_summary'  => self::account_summary( $p['total'] - $p['adjusted'], $p['prev_summary'] ),
			'subtotal'     => self::rupees( $p['subtotal'] ),
			'discount'     => self::rupees( $p['discount'] ),
			'tax_label'    => $p['tax_label'],
			'tax_rate'     => $p['tax_rate'],
			'tax_amount'   => self::rupees( $p['tax'] ),
			'total'        => self::rupees( $p['total'] ),
			'amount_paid'     => self::rupees( $p['adjusted'] ),
			'amount_adjusted' => self::rupees( $p['adjusted'] ),
			'amount_received' => 0,
			'allocations'  => array_map( function ( $a ) {
				return array( 'income_id' => $a['income_id'], 'amount' => self::rupees( $a['amount'] ), 'description' => $a['description'], 'received_on' => $a['received_on'] );
			}, $p['allocations'] ),
			'balance'      => self::rupees( $p['total'] - $p['adjusted'] ),
			'notes'        => $p['notes'],
			'work_remarks' => $p['work_remarks'],
			'payment_instructions' => $p['payment_instructions'],
			'settings_missing' => self::missing_business_details( $s ),
		);
	}

	public static function missing_business_details( $s = null ) {
		$s = $s ? $s : self::settings();
		$m = array();
		// The business address and logo are optional: their absence is never a warning.
		foreach ( array( 'phone' => 'phone', 'email' => 'email' ) as $k => $l ) {
			if ( '' === trim( (string) $s[ $k ] ) ) {
				$m[] = $l;
			}
		}
		if ( '' === $s['account_number'] && '' === $s['upi_id'] && '' === trim( (string) $s['payment_instructions'] ) ) {
			$m[] = 'bank / UPI payment details';
		}
		return $m;
	}

	/** Next invoice number in a financial year, e.g. ND/2026-27/0007. Numbers are never reused. */
	public static function next_number( $fy, $peek = false ) {
		global $wpdb;
		$s    = self::settings();
		$last = $wpdb->get_col( $wpdb->prepare( 'SELECT invoice_no FROM ' . self::t( 'invoices' ) . ' WHERE fy = %s', $fy ) );
		$max  = 0;
		foreach ( $last as $no ) {
			if ( preg_match( '/(\d+)$/', $no, $m ) ) {
				$max = max( $max, (int) $m[1] );
			}
		}
		return $s['invoice_prefix'] . '/' . $fy . '/' . str_pad( (string) ( $max + 1 ), 4, '0', STR_PAD_LEFT );
	}

	/**
	 * Creates an invoice, its lines and its PDF. If the PDF cannot be written the
	 * invoice is not kept, so there is never a record without its document.
	 *
	 * @return array|WP_Error Invoice.
	 */
	public static function create_invoice( $in, $user_id, $replaces = null ) {
		global $wpdb;
		$p = self::prepare( $in, $user_id, $replaces ? (int) $replaces->id : 0 );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$fy  = self::fy_of( $p['invoice_date'] );
		$now = NDWD_Helpers::now_mysql();
		$id  = 0;
		for ( $try = 0; $try < 5 && ! $id; $try++ ) {
			$no = self::next_number( $fy );
			$ok = $wpdb->insert( self::t( 'invoices' ), array(
				'invoice_no'           => $no,
				'fy'                   => $fy,
				'client_id'            => (int) $p['client']->id,
				'client_snapshot'      => wp_json_encode( $p['billing'] ),
				'business_snapshot'    => wp_json_encode( self::business_snapshot() ),
				'package_id'           => $p['package_id'],
				'client_package_id'    => $p['client_package_id'],
				'package_name'         => $p['package_name'],
				'package_amount'       => self::rupees( $p['package'] ),
				'invoice_date'         => $p['invoice_date'],
				'due_date'             => $p['due_date'],
				'subtotal'             => self::rupees( $p['subtotal'] ),
				'discount'             => self::rupees( $p['discount'] ),
				'discount_label'       => $p['discount_label'],
				'work_groups'          => $p['groups'] ? wp_json_encode( $p['groups'] ) : null,
				'tax_label'            => $p['tax_label'],
				'tax_rate'             => $p['tax_rate'],
				'tax_amount'           => self::rupees( $p['tax'] ),
				'total'                => self::rupees( $p['total'] ),
				'expense_subtotal'     => self::rupees( $p['expense_subtotal'] ),
				'carried_forward'      => self::rupees( $p['carried'] ),
				'prev_summary'         => $p['prev_summary'] ? wp_json_encode( $p['prev_summary'] ) : null,
				'amount_paid'          => 0,
				'status'               => 'unpaid',
				'notes'                => $p['notes'],
				'work_remarks'         => $p['work_remarks'],
				'payment_instructions' => $p['payment_instructions'],
				'created_by'           => (int) $user_id,
				'created_at'           => $now,
				'updated_at'           => $now,
				'replaces_id'          => $replaces ? (int) $replaces->id : null,
			) );
			if ( $ok ) {
				$id = (int) $wpdb->insert_id;
			}
		}
		if ( ! $id ) {
			return new WP_Error( 'ndwd_db', 'Could not save the invoice. Please try again.', array( 'status' => 500 ) );
		}
		$sort = 0;
		foreach ( $p['packages'] as $x ) {
			$wpdb->insert( self::t( 'invoice_items' ), array( 'invoice_id' => $id, 'task_id' => null, 'description' => $x['name'], 'treatment' => 'package', 'amount' => self::rupees( $x['amount'] ), 'client_package_id' => $x['client_package_id'], 'package_id' => $x['package_id'], 'package_type' => $x['package_type'], 'billing_month' => $x['billing_month'], 'period_start' => $x['period_start'], 'period_end' => $x['period_end'], 'terms' => $x['terms'], 'sort' => $sort++ ) );
		}
		foreach ( $p['lines'] as $l ) {
			$wpdb->insert( self::t( 'invoice_items' ), array( 'invoice_id' => $id, 'task_id' => $l['task_id'], 'description' => $l['description'], 'treatment' => $l['treatment'], 'amount' => self::rupees( $l['amount'] ), 'override_reason' => $l['override_reason'], 'remarks' => $l['remarks'], 'completed_on' => $l['completed_on'], 'expense_id' => isset( $l['expense_id'] ) ? $l['expense_id'] : null, 'source_invoice_id' => isset( $l['source_invoice_id'] ) ? $l['source_invoice_id'] : null, 'original_amount' => isset( $l['original_amount'] ) ? self::rupees( $l['original_amount'] ) : null, 'group_no' => isset( $l['group_no'] ) ? (int) $l['group_no'] : 0, 'sort' => $sort++ ) );
		}
		$undo = function () use ( $wpdb, $id ) {
			self::undo_consolidation( $id );
			$wpdb->delete( self::t( 'income_allocations' ), array( 'invoice_id' => $id ) );
			$wpdb->delete( self::t( 'invoice_items' ), array( 'invoice_id' => $id ) );
			$wpdb->delete( self::t( 'invoices' ), array( 'id' => $id ) );
		};
		// Two invoices saved at the same moment must not both bill one expense.
		$exp_ids = array_values( array_filter( array_column( $p['lines'], 'expense_id' ) ) );
		if ( $exp_ids && array_filter( self::expense_billed_map( $exp_ids, $replaces ? (int) $replaces->id : 0 ), function ( $l ) {
			return count( $l ) > 1;
		} ) ) {
			$undo();
			return new WP_Error( 'ndwd_already_billed', 'One of the selected expenses was just billed on another invoice. Nothing was saved; reload and try again.', array( 'status' => 409 ) );
		}
		// Consolidation: each earlier invoice's remaining balance moves here, all or nothing.
		foreach ( $p['carry'] as $vid => $bal ) {
			$o    = self::invoice_row( $vid );
			$done = $o && self::paise( $o->total ) - self::paise( $o->amount_paid ) === $bal
				? $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'invoices' ) . " SET amount_transferred = %s, consolidated_into = %d, updated_at = %s WHERE id = %d AND consolidated_into IS NULL AND status IN ('unpaid','partial')", self::rupees( $bal ), $id, NDWD_Helpers::now_mysql(), $vid ) )
				: 0;
			if ( ! $done ) {
				$undo();
				return new WP_Error( 'ndwd_conflict', ( $o ? $o->invoice_no : 'An earlier invoice' ) . ' changed (payment or consolidation) while this invoice was being made. Nothing was saved; reload and try again.', array( 'status' => 409 ) );
			}
			self::refresh_status( $vid );
		}
		if ( $p['allocations'] ) {
			$res = self::insert_allocations( $id, (int) $p['client']->id, $p['allocations'], $user_id );
			if ( is_wp_error( $res ) ) {
				$undo();
				return $res;
			}
			self::refresh_status( $id );
		}
		$pdf = self::write_pdf( $id );
		if ( is_wp_error( $pdf ) ) {
			$undo();
			return new WP_Error( 'ndwd_pdf', 'The invoice PDF could not be created, so the invoice was not saved: ' . $pdf->get_error_message(), array( 'status' => 500 ) );
		}
		self::audit( 'invoice', $id, 'invoice_created', $user_id, array( 'invoice_no' => $no, 'total' => self::rupees( $p['total'] ), 'client' => $p['client']->name, 'overrides' => $p['overrides'], 'replacement_for' => $replaces ? $replaces->invoice_no : '', 'advance_adjusted' => $p['adjusted'] ? self::rupees( $p['adjusted'] ) : '', 'reimbursable_expenses' => $p['expense_subtotal'] ? self::rupees( $p['expense_subtotal'] ) : '', 'previous_balance_shown' => $p['prev_summary'] ? $p['prev_summary']['previous_balance'] : '' ) );
		foreach ( $p['carry'] as $vid => $bal ) {
			$o = self::invoice_row( $vid );
			$d = array( 'from' => $o->invoice_no, 'to' => $no, 'amount' => self::rupees( $bal ), 'reason' => $p['consolidate_reason'] );
			self::audit( 'invoice', $vid, 'balance_consolidated_out', $user_id, $d );
			self::audit( 'invoice', $id, 'balance_consolidated_in', $user_id, $d );
			self::write_pdf( $vid );
		}
		return self::invoice( $id );
	}

	/**
	 * Reverses consolidation into $id: the earlier invoices get their balances back and are
	 * Unpaid / Partially Paid again. @return string[] invoice numbers restored.
	 */
	private static function undo_consolidation( $id, $user_id = 0, $reason = '' ) {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'invoices' ) . ' WHERE consolidated_into = %d', $id ) ) as $o ) {
			$wpdb->update( self::t( 'invoices' ), array( 'amount_transferred' => 0, 'consolidated_into' => null, 'updated_at' => NDWD_Helpers::now_mysql() ), array( 'id' => (int) $o->id ) );
			self::refresh_status( $o->id );
			if ( $user_id ) {
				self::audit( 'invoice', $o->id, 'consolidation_reversed', $user_id, array( 'amount' => (float) $o->amount_transferred, 'reason' => $reason ) );
				self::write_pdf( $o->id );
			}
			$out[] = $o->invoice_no;
		}
		return $out;
	}

	/**
	 * Cancel-and-reissue: creates the corrected invoice and cancels the original in one
	 * database transaction, so there is never a cancelled invoice without its replacement
	 * (or the other way round). Payments stay on the original; they are never refunded or
	 * duplicated automatically.
	 */
	public static function replace_invoice( $id, $in, $user_id ) {
		global $wpdb;
		$old = self::invoice_row( $id );
		if ( ! $old ) {
			return new WP_Error( 'ndwd_not_found', 'Invoice not found.', array( 'status' => 404 ) );
		}
		if ( 'cancelled' === $old->status ) {
			return new WP_Error( 'ndwd_invalid', 'This invoice is already cancelled' . ( $old->replaced_by_id ? ' and replaced' : '' ) . '.', array( 'status' => 400 ) );
		}
		if ( $old->consolidated_into ) {
			return new WP_Error( 'ndwd_invalid', $old->invoice_no . ' is consolidated into ' . self::invoice_ref( $old->consolidated_into )['invoice_no'] . '. Cancel that invoice first to restore its balance.', array( 'status' => 400 ) );
		}
		if ( self::paise( $old->carried_forward ) > 0 ) {
			return new WP_Error( 'ndwd_invalid', $old->invoice_no . ' carries balances of earlier invoices. Cancel it (the earlier balances are restored) and create a new invoice instead.', array( 'status' => 400 ) );
		}
		$reason = sanitize_textarea_field( isset( $in['cancel_reason'] ) ? (string) $in['cancel_reason'] : '' );
		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'ndwd_invalid', 'Give the reason for cancelling ' . $old->invoice_no . '.', array( 'status' => 400 ) );
		}
		$received = self::received_paise( $old );
		if ( $received > 0 && empty( $in['acknowledge_payments'] ) ) {
			return new WP_Error( 'ndwd_has_payments', $old->invoice_no . ' has payments of Rs. ' . self::inr( self::rupees( $received ) ) . '. Confirm that they stay recorded on the cancelled invoice (refund or move them separately).', array( 'status' => 409 ) );
		}
		$wpdb->query( 'START TRANSACTION' );
		// The original's advances are released first, so the replacement can take them again.
		$released = self::release_allocations( $id, 'Invoice ' . $old->invoice_no . ' cancelled and replaced', $user_id );
		$new      = self::create_invoice( $in, $user_id, $old );
		if ( is_wp_error( $new ) ) {
			$wpdb->query( 'ROLLBACK' );
			self::restore_allocations( $released );
			self::refresh_status( $id );
			return $new;
		}
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'invoices' ) . " SET status = 'cancelled', cancelled_at = %s, cancelled_by = %d, cancel_reason = %s, replaced_by_id = %d, updated_at = %s WHERE id = %d AND status <> 'cancelled'", NDWD_Helpers::now_mysql(), $user_id, $reason, $new['id'], NDWD_Helpers::now_mysql(), $id ) );
		if ( ! $done ) {
			$wpdb->query( 'ROLLBACK' );
			$wpdb->delete( self::t( 'income_allocations' ), array( 'invoice_id' => (int) $new['id'] ) );
			self::restore_allocations( $released );
			self::refresh_status( $id );
			$f = NDWD_Files::path( self::pdf_rel( (object) array( 'fy' => $new['fy'], 'invoice_no' => $new['invoice_no'] ) ) );
			if ( $f ) {
				@unlink( $f ); // phpcs:ignore
			}
			return new WP_Error( 'ndwd_conflict', $old->invoice_no . ' changed while the replacement was being made. Nothing was saved; reload and try again.', array( 'status' => 409 ) );
		}
		$wpdb->query( 'COMMIT' );
		self::refresh_status( $id );
		self::audit( 'invoice', $id, 'invoice_cancelled', $user_id, array( 'reason' => $reason, 'replaced_by' => $new['invoice_no'], 'advances_released' => $released ? self::rupees( array_sum( wp_list_pluck( $released, 'paise' ) ) ) : '' ) );
		self::write_pdf( $id );
		self::write_pdf( $new['id'] );
		return self::invoice( $new['id'] );
	}

	public static function invoice_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'invoices' ) . ' WHERE id = %d', $id ) );
	}

	public static function items( $id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'invoice_items' ) . ' WHERE invoice_id = %d ORDER BY sort, id', $id ) );
	}

	public static function is_overdue( $v ) {
		return in_array( $v->status, array( 'unpaid', 'partial' ), true ) && $v->due_date < NDWD_Helpers::today();
	}

	public static function invoice_array( $v, $detail = false ) {
		$client = json_decode( (string) $v->client_snapshot, true );
		$out    = array(
			'id'           => (int) $v->id,
			'invoice_no'   => $v->invoice_no,
			'fy'           => $v->fy,
			'client_id'    => (int) $v->client_id,
			'client_name'  => isset( $client['name'] ) ? $client['name'] : '',
			'invoice_date' => $v->invoice_date,
			'due_date'     => $v->due_date,
			'total'        => (float) $v->total,
			'amount_paid'  => (float) $v->amount_paid,
			'amount_adjusted' => (float) $v->amount_adjusted,
			'amount_received' => self::rupees( self::received_paise( $v ) ),
			'amount_transferred' => (float) $v->amount_transferred,
			'expense_subtotal' => (float) $v->expense_subtotal,
			'carried_forward' => (float) $v->carried_forward,
			'consolidated_into' => self::invoice_ref( $v->consolidated_into ),
			'balance'      => self::rupees( self::paise( $v->total ) - self::paise( $v->amount_paid ) ),
			'status'       => $v->status,
			'status_label' => self::INVOICE_STATUSES[ $v->status ],
			'is_overdue'   => self::is_overdue( $v ),
			'has_pdf'      => '' !== $v->pdf_path,
			'created_at'   => $v->created_at,
			'replaces'     => self::invoice_ref( $v->replaces_id ),
			'replaced_by'  => self::invoice_ref( $v->replaced_by_id ),
		);
		if ( $detail ) {
			global $wpdb;
			$out['client']               = $client;
			$out['business']             = json_decode( (string) $v->business_snapshot, true );
			$out['package_name']         = $v->package_name;
			$out['package_amount']       = (float) $v->package_amount;
			$out['subtotal']             = (float) $v->subtotal;
			$out['discount']             = (float) $v->discount;
			$out['discount_label']       = (string) $v->discount_label;
			$out['groups']               = $v->work_groups ? (array) json_decode( (string) $v->work_groups, true ) : array();
			$out['tax_label']            = $v->tax_label;
			$out['tax_rate']             = (float) $v->tax_rate;
			$out['tax_amount']           = (float) $v->tax_amount;
			$out['notes']                = (string) $v->notes;
			$out['work_remarks']         = (string) $v->work_remarks;
			$out['client_package_id']    = $v->client_package_id ? (int) $v->client_package_id : null;
			$out['payment_instructions'] = (string) $v->payment_instructions;
			$out['created_by']           = NDWD_Helpers::user_name( $v->created_by );
			$out['cancelled_at']         = $v->cancelled_at;
			$out['cancel_reason']        = (string) $v->cancel_reason;
			$out['cancelled_by']         = $v->cancelled_by ? NDWD_Helpers::user_name( $v->cancelled_by ) : '';
			$out['package_id']           = $v->package_id ? (int) $v->package_id : null;
			$out['items']                = array_map( function ( $i ) {
				return array( 'id' => (int) $i->id, 'task_id' => $i->task_id ? (int) $i->task_id : null, 'expense_id' => $i->expense_id ? (int) $i->expense_id : null, 'source_invoice' => self::invoice_ref( $i->source_invoice_id ), 'original_amount' => null !== $i->original_amount ? (float) $i->original_amount : null, 'description' => $i->description, 'treatment' => $i->treatment, 'amount' => (float) $i->amount, 'override_reason' => $i->override_reason, 'remarks' => (string) $i->remarks, 'completed_on' => $i->completed_on, 'group_no' => (int) $i->group_no, 'client_package_id' => $i->client_package_id ? (int) $i->client_package_id : null, 'package_id' => $i->package_id ? (int) $i->package_id : null, 'package_type' => (string) $i->package_type, 'billing_month' => (string) $i->billing_month, 'period_start' => $i->period_start, 'period_end' => $i->period_end, 'terms' => (string) $i->terms );
			}, self::items( $v->id ) );
			$ps                          = $v->prev_summary ? json_decode( (string) $v->prev_summary, true ) : null;
			$out['account_summary']      = self::account_summary( self::paise( $v->total ) - self::paise( $v->amount_paid ), $ps );
			$out['consolidated_from']    = array_map( function ( $o ) {
				return array( 'id' => (int) $o->id, 'invoice_no' => $o->invoice_no, 'amount' => (float) $o->amount_transferred );
			}, $wpdb->get_results( $wpdb->prepare( 'SELECT id, invoice_no, amount_transferred FROM ' . self::t( 'invoices' ) . ' WHERE consolidated_into = %d ORDER BY id', $v->id ) ) );
			$out['payments']             = array_map( array( __CLASS__, 'payment_array' ), $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'payments' ) . ' WHERE invoice_id = %d ORDER BY paid_on, id', $v->id ) ) );
			$out['allocations']          = self::allocations_of_invoice( $v->id, true );
			$out['audit']                = self::audit_for( 'invoice', $v->id );
		}
		return $out;
	}

	/** [id, invoice_no] of a linked invoice, or null. */
	private static function invoice_ref( $id ) {
		static $cache = array();
		if ( ! $id ) {
			return null;
		}
		if ( ! isset( $cache[ $id ] ) ) {
			global $wpdb;
			$no           = $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_no FROM ' . self::t( 'invoices' ) . ' WHERE id = %d', $id ) );
			$cache[ $id ] = $no ? array( 'id' => (int) $id, 'invoice_no' => $no ) : null;
		}
		return $cache[ $id ];
	}

	public static function invoice( $id ) {
		$v = self::invoice_row( $id );
		return $v ? self::invoice_array( $v, true ) : new WP_Error( 'ndwd_not_found', 'Invoice not found.', array( 'status' => 404 ) );
	}

	public static function invoices( $f = array() ) {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::t( 'invoices' ) . ' WHERE 1=1';
		$vals = array();
		if ( ! empty( $f['fy'] ) && self::valid_fy( $f['fy'] ) ) {
			$sql   .= ' AND fy = %s';
			$vals[] = $f['fy'];
		}
		if ( ! empty( $f['client_id'] ) ) {
			$sql   .= ' AND client_id = %d';
			$vals[] = absint( $f['client_id'] );
		}
		if ( ! empty( $f['status'] ) ) {
			if ( 'overdue' === $f['status'] ) {
				$sql   .= " AND status IN ('unpaid','partial') AND due_date < %s";
				$vals[] = NDWD_Helpers::today();
			} elseif ( isset( self::INVOICE_STATUSES[ $f['status'] ] ) ) {
				$sql   .= ' AND status = %s';
				$vals[] = $f['status'];
			}
		}
		if ( ! empty( $f['q'] ) ) {
			// Invoice number, client, project (of the billed tasks) or status.
			$q      = trim( (string) $f['q'] );
			$like   = '%' . $wpdb->esc_like( $q ) . '%';
			$sql   .= ' AND (invoice_no LIKE %s OR client_snapshot LIKE %s OR client_id IN (SELECT id FROM ' . self::t( 'clients' ) . ' WHERE name LIKE %s)'
				. ' OR id IN (SELECT ii.invoice_id FROM ' . self::t( 'invoice_items' ) . ' ii JOIN ' . self::t( 'tasks' ) . ' tk ON tk.id = ii.task_id JOIN ' . self::t( 'projects' ) . ' pr ON pr.id = tk.project_id WHERE pr.name LIKE %s)';
			array_push( $vals, $like, $like, $like, $like );
			$st = array_keys( array_filter( self::INVOICE_STATUSES, function ( $l ) use ( $q ) {
				return false !== mb_stripos( $l, $q );
			} ) );
			if ( $st ) {
				$sql .= " OR status IN ('" . implode( "','", array_map( 'esc_sql', $st ) ) . "')";
			}
			if ( false !== mb_stripos( 'overdue', $q ) ) {
				$sql   .= " OR (status IN ('unpaid','partial') AND due_date < %s)";
				$vals[] = NDWD_Helpers::today();
			}
			$sql .= ')';
		}
		$sql .= ' ORDER BY invoice_date DESC, id DESC LIMIT 500';
		$rows = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		return array_map( array( __CLASS__, 'invoice_array' ), $rows );
	}

	/** Non-financial edits after issue: due date, notes, payment instructions. Amounts never change. */
	public static function update_invoice( $id, $in, $user_id ) {
		global $wpdb;
		$v = self::invoice_row( $id );
		if ( ! $v ) {
			return new WP_Error( 'ndwd_not_found', 'Invoice not found.', array( 'status' => 404 ) );
		}
		if ( 'cancelled' === $v->status ) {
			return new WP_Error( 'ndwd_invalid', 'A cancelled invoice cannot be edited.', array( 'status' => 400 ) );
		}
		if ( $v->consolidated_into && isset( $in['allocations'] ) ) {
			return new WP_Error( 'ndwd_invalid', 'This invoice is consolidated: its balance is settled on ' . self::invoice_ref( $v->consolidated_into )['invoice_no'] . '.', array( 'status' => 400 ) );
		}
		$row     = array();
		$changes = array();
		if ( isset( $in['due_date'] ) ) {
			$d = NDWD_Helpers::sanitize_date( (string) $in['due_date'] );
			if ( ! $d || $d < $v->invoice_date ) {
				return new WP_Error( 'ndwd_invalid', 'The due date must be on or after the invoice date.', array( 'status' => 400 ) );
			}
			$row['due_date'] = $d;
		}
		foreach ( array( 'notes', 'payment_instructions' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$row[ $k ] = sanitize_textarea_field( (string) $in[ $k ] );
			}
		}
		foreach ( $row as $k => $val ) {
			if ( (string) $v->$k !== (string) $val ) {
				$changes[ $k ] = array( 'from' => $v->$k, 'to' => $val );
			}
		}
		$realloc = false;
		if ( isset( $in['allocations'] ) && is_array( $in['allocations'] ) ) {
			$res = self::reallocate( $v, $in['allocations'], $user_id );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$realloc = $res;
		}
		if ( ! $changes && ! $realloc ) {
			return self::invoice( $id );
		}
		if ( $changes ) {
			$row['updated_at'] = NDWD_Helpers::now_mysql();
			$wpdb->update( self::t( 'invoices' ), $row, array( 'id' => $id ) );
			self::audit( 'invoice', $id, 'invoice_edited', $user_id, $changes );
		}
		self::write_pdf( $id );
		return self::invoice( $id );
	}

	public static function cancel_invoice( $id, $reason, $user_id, $acknowledge_payments = false ) {
		global $wpdb;
		$v = self::invoice_row( $id );
		if ( ! $v ) {
			return new WP_Error( 'ndwd_not_found', 'Invoice not found.', array( 'status' => 404 ) );
		}
		$reason = sanitize_textarea_field( (string) $reason );
		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'ndwd_invalid', 'Give a reason for cancelling the invoice.', array( 'status' => 400 ) );
		}
		if ( $v->consolidated_into && 'cancelled' !== $v->status ) {
			return new WP_Error( 'ndwd_invalid', $v->invoice_no . ' is consolidated into ' . self::invoice_ref( $v->consolidated_into )['invoice_no'] . '. Cancel that invoice first to restore this balance.', array( 'status' => 400 ) );
		}
		// Payments are money actually received: cancelling never erases or refunds them.
		// Advances adjusted against the invoice are released back to their income entries.
		$received = self::received_paise( $v );
		if ( $received > 0 && ! $acknowledge_payments ) {
			return new WP_Error( 'ndwd_has_payments', 'This invoice has payments of Rs. ' . self::inr( self::rupees( $received ) ) . '. Confirm that they stay recorded on the cancelled invoice; record a refund or move them to a replacement separately.', array( 'status' => 409 ) );
		}
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'invoices' ) . " SET status = 'cancelled', cancelled_at = %s, cancelled_by = %d, cancel_reason = %s, updated_at = %s WHERE id = %d AND status <> 'cancelled'", NDWD_Helpers::now_mysql(), $user_id, $reason, NDWD_Helpers::now_mysql(), $id ) );
		if ( $done ) {
			$released = self::release_allocations( $id, 'Invoice ' . $v->invoice_no . ' cancelled', $user_id );
			$restored = self::undo_consolidation( $id, $user_id, 'Invoice ' . $v->invoice_no . ' cancelled' );
			self::refresh_status( $id );
			self::audit( 'invoice', $id, 'invoice_cancelled', $user_id, array( 'reason' => $reason, 'payments_kept' => $received > 0 ? self::rupees( $received ) : '', 'advances_released' => $released ? self::rupees( array_sum( wp_list_pluck( $released, 'paise' ) ) ) : '', 'balances_restored_to' => implode( ', ', $restored ), 'expenses_released' => $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::t( 'invoice_items' ) . ' WHERE invoice_id = %d AND expense_id IS NOT NULL', $id ) ) ? 'yes' : '' ) );
			self::write_pdf( $id );
		}
		return self::invoice( $id );
	}

	/** Recomputes amount paid and status from the (non-voided) payments. */
	public static function refresh_status( $id ) {
		global $wpdb;
		$v = self::invoice_row( $id );
		if ( ! $v ) {
			return;
		}
		$paid  = self::paise( $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . self::t( 'payments' ) . ' WHERE invoice_id = %d AND voided_at IS NULL', $id ) ) );
		$adj   = self::paise( $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . self::t( 'income_allocations' ) . ' WHERE invoice_id = %d AND released_at IS NULL', $id ) ) );
		$trf   = $v->consolidated_into ? self::paise( $v->amount_transferred ) : 0;
		// amount_paid = everything settled (payments + advances adjusted + balance transferred
		// to a consolidated invoice); amount_adjusted = the advance part.
		$set   = $paid + $adj + $trf;
		$row   = array( 'amount_paid' => self::rupees( $set ), 'amount_adjusted' => self::rupees( $adj ), 'updated_at' => NDWD_Helpers::now_mysql() );
		if ( 'cancelled' !== $v->status ) {
			$total         = self::paise( $v->total );
			$row['status'] = $set >= $total ? ( $trf ? 'consolidated' : 'paid' ) : ( $set > 0 ? 'partial' : 'unpaid' );
		}
		$wpdb->update( self::t( 'invoices' ), $row, array( 'id' => $id ) );
		self::allocations_of_income( 'flush' );
	}

	/* ------------------------------------------------------------------ *
	 * Advance allocations (money already received, adjusted against invoices)
	 * ------------------------------------------------------------------ */

	/** Active (not released) allocations as paise, keyed by income id; optionally excluding one invoice. */
	private static function allocated_by_income( $income_ids = null, $exclude_invoice = 0 ) {
		global $wpdb;
		$sql = 'SELECT income_id, SUM(amount) a FROM ' . self::t( 'income_allocations' ) . ' WHERE released_at IS NULL';
		if ( $exclude_invoice ) {
			$sql .= $wpdb->prepare( ' AND invoice_id <> %d', $exclude_invoice );
		}
		if ( is_array( $income_ids ) ) {
			if ( ! $income_ids ) {
				return array();
			}
			$sql .= ' AND income_id IN (' . implode( ',', array_map( 'absint', $income_ids ) ) . ')';
		}
		$out = array();
		foreach ( $wpdb->get_results( $sql . ' GROUP BY income_id' ) as $r ) { // phpcs:ignore
			$out[ (int) $r->income_id ] = self::paise( $r->a );
		}
		return $out;
	}

	/** Income that can be adjusted against invoices: manual (not an invoice payment), active, with a client. */
	private static function allocatable_income( $id ) {
		$x = self::income_row( $id );
		return $x && ! $x->voided_at && ! $x->payment_id && $x->client_id ? $x : null;
	}

	/**
	 * Eligible income of a client for invoice creation: manual income of that client with an
	 * available (unallocated) balance, optionally only of one project. With $exclude_invoice,
	 * that invoice's own allocations count as available (editing / replacing it) and are
	 * returned as allocated_here.
	 */
	public static function available_income( $client_id, $project_id = 0, $exclude_invoice = 0 ) {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::t( 'income' ) . ' WHERE voided_at IS NULL AND payment_id IS NULL AND client_id = %d';
		$vals = array( absint( $client_id ) );
		if ( $project_id ) {
			$sql   .= ' AND project_id = %d';
			$vals[] = absint( $project_id );
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY received_on, id', $vals ) ); // phpcs:ignore
		$ids   = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$other = self::allocated_by_income( $ids, $exclude_invoice );
		$here  = array();
		if ( $exclude_invoice ) {
			foreach ( self::allocations_of_invoice( $exclude_invoice ) as $a ) {
				$here[ (int) $a->income_id ] = ( isset( $here[ (int) $a->income_id ] ) ? $here[ (int) $a->income_id ] : 0 ) + self::paise( $a->amount );
			}
		}
		$out = array();
		foreach ( $rows as $x ) {
			$amt   = self::paise( $x->amount );
			$used  = isset( $other[ (int) $x->id ] ) ? $other[ (int) $x->id ] : 0;
			$mine  = isset( $here[ (int) $x->id ] ) ? $here[ (int) $x->id ] : 0;
			$avail = $amt - $used;
			if ( $avail <= 0 && ! $mine ) {
				continue;
			}
			$a                   = self::income_array( $x );
			$a['allocated']      = self::rupees( $used );
			$a['available']      = self::rupees( max( 0, $avail ) );
			$a['allocated_here'] = self::rupees( $mine );
			$out[]               = $a;
		}
		return $out;
	}

	/**
	 * Validates requested allocations [{income_id, amount}] for an invoice of $client_id that
	 * has $total payable (paise) of which $received is already paid. Allocations of
	 * $exclude_invoice count as free (that invoice is being edited or replaced).
	 *
	 * @return array|WP_Error [{income_id, amount (paise), description, received_on}]
	 */
	private static function check_allocations( $req, $client_id, $total, $exclude_invoice = 0, $received = 0 ) {
		if ( ! is_array( $req ) ) {
			return array();
		}
		$out  = array();
		$seen = array();
		$sum  = 0;
		foreach ( $req as $a ) {
			$iid = absint( is_array( $a ) && isset( $a['income_id'] ) ? $a['income_id'] : 0 );
			$amt = self::paise( is_array( $a ) && isset( $a['amount'] ) ? $a['amount'] : null );
			if ( ! $iid || 0 === $amt ) {
				continue;
			}
			$x = self::allocatable_income( $iid );
			if ( ! $x || (int) $x->client_id !== (int) $client_id ) {
				return new WP_Error( 'ndwd_invalid', 'Income entry #' . $iid . ' cannot be adjusted against this invoice: only active manual income of the same client can be used.', array( 'status' => 400 ) );
			}
			if ( isset( $seen[ $iid ] ) ) {
				return new WP_Error( 'ndwd_invalid', '"' . $x->description . '" is listed twice. Allocate it once with the combined amount.', array( 'status' => 400 ) );
			}
			if ( null === $amt || $amt < 0 ) {
				return new WP_Error( 'ndwd_invalid', 'Enter a valid amount to adjust from "' . $x->description . '".', array( 'status' => 400 ) );
			}
			$used  = self::allocated_by_income( array( $iid ), $exclude_invoice );
			$avail = self::paise( $x->amount ) - ( isset( $used[ $iid ] ) ? $used[ $iid ] : 0 );
			if ( $amt > $avail ) {
				return new WP_Error( 'ndwd_over_allocated', '"' . $x->description . '" has only Rs. ' . self::inr( self::rupees( max( 0, $avail ) ) ) . ' available (Rs. ' . self::inr( $x->amount ) . ' received, the rest already adjusted against other invoices).', array( 'status' => 409 ) );
			}
			$seen[ $iid ] = true;
			$sum         += $amt;
			$out[]        = array( 'income_id' => $iid, 'amount' => $amt, 'description' => $x->description, 'received_on' => $x->received_on );
		}
		if ( $sum > $total - $received ) {
			return new WP_Error( 'ndwd_invalid', 'Advances adjusted (Rs. ' . self::inr( self::rupees( $sum ) ) . ') are more than the amount payable on the invoice (Rs. ' . self::inr( self::rupees( $total - $received ) ) . ').', array( 'status' => 400 ) );
		}
		return $out;
	}

	/**
	 * Writes validated allocations. Afterwards each receipt is re-checked, so two invoices
	 * saved at the same moment can never take the same money twice; on a conflict this
	 * invoice's new rows are removed again.
	 */
	private static function insert_allocations( $invoice_id, $client_id, $allocs, $user_id ) {
		global $wpdb;
		$now = NDWD_Helpers::now_mysql();
		$ids = array();
		foreach ( $allocs as $a ) {
			$wpdb->insert( self::t( 'income_allocations' ), array( 'income_id' => $a['income_id'], 'invoice_id' => (int) $invoice_id, 'client_id' => (int) $client_id, 'amount' => self::rupees( $a['amount'] ), 'created_by' => (int) $user_id, 'created_at' => $now ) );
			$ids[] = (int) $wpdb->insert_id;
		}
		$used = self::allocated_by_income( wp_list_pluck( $allocs, 'income_id' ) );
		foreach ( $allocs as $a ) {
			$x = self::income_row( $a['income_id'] );
			if ( ! $x || $x->voided_at || $used[ $a['income_id'] ] > self::paise( $x->amount ) ) {
				$wpdb->query( 'DELETE FROM ' . self::t( 'income_allocations' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore
				return new WP_Error( 'ndwd_over_allocated', '"' . ( $x ? $x->description : '#' . $a['income_id'] ) . '" was just adjusted elsewhere and no longer has enough available. Reload and try again.', array( 'status' => 409 ) );
			}
		}
		foreach ( $allocs as $a ) {
			self::audit( 'income', $a['income_id'], 'income_allocated', $user_id, array( 'invoice_id' => (int) $invoice_id, 'amount' => self::rupees( $a['amount'] ) ) );
		}
		return true;
	}

	/** Releases the active allocations of an invoice (or of an income entry). @return array [{id, invoice_id, income_id, paise}] */
	private static function release_allocations( $invoice_id, $reason, $user_id, $income_id = 0 ) {
		global $wpdb;
		$col  = $income_id ? 'income_id' : 'invoice_id';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'income_allocations' ) . " WHERE $col = %d AND released_at IS NULL", $income_id ? $income_id : $invoice_id ) ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $a ) {
			$wpdb->update( self::t( 'income_allocations' ), array( 'released_at' => NDWD_Helpers::now_mysql(), 'released_by' => (int) $user_id, 'release_reason' => mb_substr( $reason, 0, 255 ) ), array( 'id' => (int) $a->id ) );
			self::audit( 'income', $a->income_id, 'allocation_released', $user_id, array( 'invoice_id' => (int) $a->invoice_id, 'amount' => (float) $a->amount, 'reason' => $reason ) );
			$out[] = array( 'id' => (int) $a->id, 'invoice_id' => (int) $a->invoice_id, 'income_id' => (int) $a->income_id, 'paise' => self::paise( $a->amount ) );
		}
		return $out;
	}

	/** Undoes release_allocations() when the operation that released them failed. */
	private static function restore_allocations( $released ) {
		global $wpdb;
		foreach ( (array) $released as $a ) {
			$wpdb->update( self::t( 'income_allocations' ), array( 'released_at' => null, 'released_by' => null, 'release_reason' => '' ), array( 'id' => $a['id'] ) );
		}
	}

	/** Allocations of an invoice: active rows, or (as arrays) the full history including released ones. */
	public static function allocations_of_invoice( $invoice_id, $array = false ) {
		global $wpdb;
		$t    = self::t( 'income_allocations' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT a.*, i.description, i.received_on, i.amount income_amount, i.reference FROM $t a LEFT JOIN " . self::t( 'income' ) . ' i ON i.id = a.income_id WHERE a.invoice_id = %d' . ( $array ? '' : ' AND a.released_at IS NULL' ) . ' ORDER BY a.id', $invoice_id ) ); // phpcs:ignore
		if ( ! $array ) {
			return $rows;
		}
		return array_map( function ( $a ) {
			return array( 'id' => (int) $a->id, 'income_id' => (int) $a->income_id, 'amount' => (float) $a->amount, 'description' => (string) $a->description, 'received_on' => $a->received_on, 'income_amount' => (float) $a->income_amount, 'reference' => (string) $a->reference, 'created_at' => $a->created_at, 'created_by' => NDWD_Helpers::user_name( $a->created_by ), 'released_at' => $a->released_at, 'release_reason' => (string) $a->release_reason );
		}, $rows );
	}

	/**
	 * Replaces an issued invoice's advance allocations with a new set (edit). The old set is
	 * released and the new one written; if writing fails the old set is restored, so money
	 * is never counted on two invoices or lost from both.
	 *
	 * @return bool|WP_Error true when something changed.
	 */
	private static function reallocate( $v, $req, $user_id ) {
		$cur = array();
		foreach ( self::allocations_of_invoice( $v->id ) as $a ) {
			$cur[ (int) $a->income_id ] = ( isset( $cur[ (int) $a->income_id ] ) ? $cur[ (int) $a->income_id ] : 0 ) + self::paise( $a->amount );
		}
		$received = self::paise( $v->amount_paid ) - self::paise( $v->amount_adjusted );
		$new      = self::check_allocations( $req, (int) $v->client_id, self::paise( $v->total ), (int) $v->id, $received );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$want = array();
		foreach ( $new as $a ) {
			$want[ $a['income_id'] ] = $a['amount'];
		}
		ksort( $cur );
		ksort( $want );
		if ( $cur === $want ) {
			return false;
		}
		$released = self::release_allocations( $v->id, 'Advances re-allocated on edit', $user_id );
		if ( $new ) {
			$res = self::insert_allocations( $v->id, (int) $v->client_id, $new, $user_id );
			if ( is_wp_error( $res ) ) {
				self::restore_allocations( $released );
				return $res;
			}
		}
		self::refresh_status( $v->id );
		self::audit( 'invoice', $v->id, 'advances_reallocated', $user_id, array( 'from' => self::rupees( array_sum( $cur ) ), 'to' => self::rupees( array_sum( $want ) ) ) );
		return true;
	}

	/**
	 * Validates [{invoice_id, amount}] for spreading $available (paise) of a client's money over
	 * its open invoices. @return array|WP_Error [{invoice_id, amount (paise), invoice_no}]
	 */
	private static function check_invoice_split( $req, $client_id, $available ) {
		$out  = array();
		$sum  = 0;
		foreach ( (array) $req as $a ) {
			$vid = absint( is_array( $a ) && isset( $a['invoice_id'] ) ? $a['invoice_id'] : 0 );
			$amt = self::paise( is_array( $a ) && isset( $a['amount'] ) ? $a['amount'] : null );
			if ( ! $vid || 0 === $amt || ( is_array( $a ) && isset( $a['amount'] ) && '' === trim( (string) $a['amount'] ) ) ) {
				continue;
			}
			$v = self::invoice_row( $vid );
			if ( ! $v || (int) $v->client_id !== (int) $client_id ) {
				return new WP_Error( 'ndwd_invalid', 'Invoice #' . $vid . ' is not an invoice of this client.', array( 'status' => 400 ) );
			}
			if ( isset( $out[ $vid ] ) ) {
				return new WP_Error( 'ndwd_invalid', $v->invoice_no . ' is listed twice.', array( 'status' => 400 ) );
			}
			if ( ! in_array( $v->status, array( 'unpaid', 'partial' ), true ) ) {
				return new WP_Error( 'ndwd_invalid', $v->invoice_no . ' is ' . self::INVOICE_STATUSES[ $v->status ] . '; only unpaid or partially paid invoices can receive an allocation.', array( 'status' => 400 ) );
			}
			if ( null === $amt || $amt < 0 ) {
				return new WP_Error( 'ndwd_invalid', 'Enter a valid amount for ' . $v->invoice_no . '.', array( 'status' => 400 ) );
			}
			$bal = self::paise( $v->total ) - self::paise( $v->amount_paid );
			if ( $amt > $bal ) {
				return new WP_Error( 'ndwd_over_allocated', 'Rs. ' . self::inr( self::rupees( $amt ) ) . ' is more than the balance of ' . $v->invoice_no . ' (Rs. ' . self::inr( self::rupees( $bal ) ) . ').', array( 'status' => 400 ) );
			}
			$sum         += $amt;
			$out[ $vid ]  = array( 'invoice_id' => $vid, 'amount' => $amt, 'invoice_no' => $v->invoice_no );
		}
		if ( $sum > $available ) {
			return new WP_Error( 'ndwd_over_allocated', 'Allocations (Rs. ' . self::inr( self::rupees( $sum ) ) . ') are more than the money available (Rs. ' . self::inr( self::rupees( $available ) ) . ').', array( 'status' => 400 ) );
		}
		return array_values( $out );
	}

	/**
	 * Applies (part of) one received income entry to several invoices. The income row is not
	 * changed: it stays one receipt in Income and the passbook; the rest stays an advance.
	 * All rows are re-checked after writing; on any conflict all of them are removed again.
	 */
	public static function allocate_income( $income_id, $req, $user_id ) {
		global $wpdb;
		$x = self::allocatable_income( $income_id );
		if ( ! $x ) {
			return new WP_Error( 'ndwd_invalid', 'Only active manual income of a client can be allocated to invoices.', array( 'status' => 400 ) );
		}
		$used  = self::allocated_by_income( array( (int) $x->id ) );
		$avail = self::paise( $x->amount ) - ( isset( $used[ (int) $x->id ] ) ? $used[ (int) $x->id ] : 0 );
		$split = self::check_invoice_split( $req, (int) $x->client_id, $avail );
		if ( is_wp_error( $split ) ) {
			return $split;
		}
		if ( ! $split ) {
			return new WP_Error( 'ndwd_invalid', 'Enter the amount to allocate to at least one invoice.', array( 'status' => 400 ) );
		}
		$now = NDWD_Helpers::now_mysql();
		$ids = array();
		foreach ( $split as $a ) {
			$wpdb->insert( self::t( 'income_allocations' ), array( 'income_id' => (int) $x->id, 'invoice_id' => $a['invoice_id'], 'client_id' => (int) $x->client_id, 'amount' => self::rupees( $a['amount'] ), 'created_by' => (int) $user_id, 'created_at' => $now ) );
			$ids[] = (int) $wpdb->insert_id;
		}
		$bad  = '';
		$used = self::allocated_by_income( array( (int) $x->id ) );
		if ( $used[ (int) $x->id ] > self::paise( $x->amount ) ) {
			$bad = '"' . $x->description . '" was just allocated elsewhere.';
		}
		foreach ( $split as $a ) {
			self::refresh_status( $a['invoice_id'] );
			$v = self::invoice_row( $a['invoice_id'] );
			if ( ! $bad && ( self::paise( $v->amount_paid ) > self::paise( $v->total ) || ! in_array( $v->status, array( 'unpaid', 'partial', 'paid' ), true ) ) ) {
				$bad = $v->invoice_no . ' changed meanwhile.';
			}
		}
		if ( $bad ) {
			$wpdb->query( 'DELETE FROM ' . self::t( 'income_allocations' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore
			foreach ( $split as $a ) {
				self::refresh_status( $a['invoice_id'] );
			}
			return new WP_Error( 'ndwd_conflict', $bad . ' Nothing was allocated; reload and try again.', array( 'status' => 409 ) );
		}
		foreach ( $split as $a ) {
			self::audit( 'income', $x->id, 'income_allocated', $user_id, array( 'invoice_id' => $a['invoice_id'], 'amount' => self::rupees( $a['amount'] ) ) );
			self::audit( 'invoice', $a['invoice_id'], 'payment_allocated', $user_id, array( 'income_id' => (int) $x->id, 'amount' => self::rupees( $a['amount'] ), 'received_on' => $x->received_on, 'reference' => $x->reference ) );
			self::write_pdf( $a['invoice_id'] );
		}
		$left = self::paise( $x->amount ) - $used[ (int) $x->id ];
		return array( 'income' => self::income_array( self::income_row( $x->id ) ), 'allocated' => self::rupees( array_sum( wp_list_pluck( $split, 'amount' ) ) ), 'advance_left' => self::rupees( $left ), 'invoices' => array_map( function ( $a ) {
			return self::invoice_array( self::invoice_row( $a['invoice_id'] ) );
		}, $split ) );
	}

	/**
	 * One payment received from a client, optionally spread over several of its open invoices.
	 * It is recorded once as manual income (Client Payment) and allocated; the unallocated
	 * rest stays the client's advance. If allocating fails, the receipt is not kept either.
	 */
	public static function receive_payment( $client_id, $in, $user_id ) {
		global $wpdb;
		if ( ! NDWD_Clients::get( $client_id ) ) {
			return new WP_Error( 'ndwd_not_found', 'Client not found.', array( 'status' => 404 ) );
		}
		$amount = self::paise( isset( $in['amount'] ) ? $in['amount'] : null );
		if ( null === $amount || $amount <= 0 ) {
			return new WP_Error( 'ndwd_invalid', 'Enter the amount received (greater than 0).', array( 'status' => 400 ) );
		}
		$req   = isset( $in['allocations'] ) ? $in['allocations'] : array();
		$split = self::check_invoice_split( $req, $client_id, $amount );
		if ( is_wp_error( $split ) ) {
			return $split;
		}
		$in['client_id']  = $client_id;
		$in['invoice_id'] = 0;
		$in['source']     = isset( $in['source'] ) && '' !== $in['source'] ? $in['source'] : 'Client Payment';
		if ( ! isset( $in['description'] ) || '' === trim( (string) $in['description'] ) ) {
			$in['description'] = $split ? 'Payment for ' . implode( ', ', wp_list_pluck( $split, 'invoice_no' ) ) : 'Advance payment';
		}
		$x = self::save_income( $in, $user_id );
		if ( is_wp_error( $x ) ) {
			return $x;
		}
		if ( ! $split ) {
			return array( 'income' => $x, 'allocated' => 0, 'advance_left' => $x['amount'], 'invoices' => array() );
		}
		$res = self::allocate_income( $x['id'], $req, $user_id );
		if ( is_wp_error( $res ) ) {
			$wpdb->delete( self::t( 'income' ), array( 'id' => (int) $x['id'] ) );
			self::audit( 'income', $x['id'], 'income_discarded', $user_id, array( 'reason' => 'Allocation failed: ' . $res->get_error_message() ) );
			return $res;
		}
		return $res;
	}

	/* ------------------------------------------------------------------ *
	 * Payments
	 * ------------------------------------------------------------------ */

	public static function payment_array( $p ) {
		return array(
			'id'          => (int) $p->id,
			'invoice_id'  => (int) $p->invoice_id,
			'amount'      => (float) $p->amount,
			'paid_on'     => $p->paid_on,
			'method'      => $p->method,
			'method_label' => isset( self::METHODS[ $p->method ] ) ? self::METHODS[ $p->method ] : $p->method,
			'destination' => (string) $p->destination,
			'reference'   => $p->reference,
			'notes'       => (string) $p->notes,
			'created_by'  => NDWD_Helpers::user_name( $p->created_by ),
			'created_at'  => $p->created_at,
			'voided_at'   => $p->voided_at,
			'void_reason' => (string) $p->void_reason,
		);
	}

	public static function add_payment( $invoice_id, $in, $user_id ) {
		global $wpdb;
		$v = self::invoice_row( $invoice_id );
		if ( ! $v ) {
			return new WP_Error( 'ndwd_not_found', 'Invoice not found.', array( 'status' => 404 ) );
		}
		if ( 'cancelled' === $v->status ) {
			return new WP_Error( 'ndwd_invalid', 'Payments cannot be recorded on a cancelled invoice.', array( 'status' => 400 ) );
		}
		if ( 'consolidated' === $v->status ) {
			return new WP_Error( 'ndwd_invalid', $v->invoice_no . ' is consolidated: record the payment on ' . self::invoice_ref( $v->consolidated_into )['invoice_no'] . '.', array( 'status' => 400 ) );
		}
		$amount  = self::paise( isset( $in['amount'] ) ? $in['amount'] : null );
		$balance = self::paise( $v->total ) - self::paise( $v->amount_paid );
		if ( null === $amount || $amount <= 0 ) {
			return new WP_Error( 'ndwd_invalid', 'Enter a payment amount greater than 0.', array( 'status' => 400 ) );
		}
		if ( $amount > $balance ) {
			return new WP_Error( 'ndwd_invalid', 'The amount is more than the balance due (₹' . self::inr( self::rupees( $balance ) ) . ').', array( 'status' => 400 ) );
		}
		$date = NDWD_Helpers::sanitize_date( isset( $in['paid_on'] ) ? (string) $in['paid_on'] : '' );
		if ( ! $date || $date > NDWD_Helpers::today() ) {
			return new WP_Error( 'ndwd_invalid', 'Enter the payment date (not in the future).', array( 'status' => 400 ) );
		}
		$method = isset( $in['method'] ) ? sanitize_key( $in['method'] ) : '';
		if ( ! isset( self::METHODS[ $method ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Choose the payment method.', array( 'status' => 400 ) );
		}
		$reference = mb_substr( sanitize_text_field( isset( $in['reference'] ) ? (string) $in['reference'] : '' ), 0, 120 );
		$project   = absint( isset( $in['project_id'] ) ? $in['project_id'] : 0 );
		if ( $project && ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::t( 'projects' ) . ' WHERE id = %d', $project ) ) ) {
			return new WP_Error( 'ndwd_invalid', 'Unknown project.', array( 'status' => 400 ) );
		}
		// The same payment entered twice (double click, or recorded from Income and from the
		// invoice) is refused unless the user confirms it really is a second payment.
		if ( empty( $in['confirm_duplicate'] ) ) {
			$dup = '' !== $reference
				? $wpdb->get_row( $wpdb->prepare( 'SELECT p.*, v.invoice_no FROM ' . self::t( 'payments' ) . ' p JOIN ' . self::t( 'invoices' ) . ' v ON v.id = p.invoice_id WHERE p.voided_at IS NULL AND p.reference = %s LIMIT 1', $reference ) )
				: $wpdb->get_row( $wpdb->prepare( 'SELECT p.*, v.invoice_no FROM ' . self::t( 'payments' ) . ' p JOIN ' . self::t( 'invoices' ) . ' v ON v.id = p.invoice_id WHERE p.voided_at IS NULL AND p.invoice_id = %d AND p.amount = %s AND p.paid_on = %s LIMIT 1', $invoice_id, self::rupees( $amount ), $date ) );
			if ( $dup ) {
				return new WP_Error( 'ndwd_duplicate', 'A payment of Rs. ' . self::inr( $dup->amount ) . ' dated ' . date_i18n( 'j M Y', strtotime( $dup->paid_on ) ) . ( '' !== $reference ? ' with reference ' . $reference : '' ) . ' is already recorded on ' . $dup->invoice_no . '. Confirm only if this is a separate payment.', array( 'status' => 409 ) );
			}
		}
		$wpdb->insert( self::t( 'payments' ), array(
			'invoice_id' => (int) $invoice_id,
			'amount'     => self::rupees( $amount ),
			'paid_on'    => $date,
			'method'     => $method,
			'destination' => self::destination_in( $in ),
			'reference'  => $reference,
			'notes'      => sanitize_textarea_field( isset( $in['notes'] ) ? (string) $in['notes'] : '' ),
			'created_by' => (int) $user_id,
			'created_at' => NDWD_Helpers::now_mysql(),
		) );
		$pid = (int) $wpdb->insert_id;
		// Its Income row: same money, linked by payment_id (unique), never a second receipt.
		$source = mb_substr( sanitize_text_field( isset( $in['source'] ) && '' !== $in['source'] ? (string) $in['source'] : 'Client Payment' ), 0, 80 );
		$now    = NDWD_Helpers::now_mysql();
		$wpdb->insert( self::t( 'income' ), array(
			'amount'      => self::rupees( $amount ),
			'description' => mb_substr( sanitize_text_field( isset( $in['description'] ) && '' !== trim( (string) $in['description'] ) ? (string) $in['description'] : 'Payment for ' . $v->invoice_no ), 0, 255 ),
			'source'      => $source,
			'received_on' => $date,
			'method'      => $method,
			'destination' => self::destination_in( $in ),
			'reference'   => $reference,
			'client_id'   => (int) $v->client_id,
			'project_id'  => $project ? $project : null,
			'invoice_id'  => (int) $invoice_id,
			'payment_id'  => $pid,
			'remarks'     => sanitize_textarea_field( isset( $in['notes'] ) ? (string) $in['notes'] : '' ),
			'created_by'  => (int) $user_id,
			'created_at'  => $now,
			'updated_at'  => $now,
		) );
		self::refresh_status( $invoice_id );
		self::audit( 'invoice', $invoice_id, 'payment_recorded', $user_id, array( 'payment_id' => $pid, 'amount' => self::rupees( $amount ), 'method' => $method, 'paid_on' => $date ) );
		self::write_pdf( $invoice_id );
		return self::invoice( $invoice_id );
	}

	public static function void_payment( $payment_id, $reason, $user_id ) {
		global $wpdb;
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'payments' ) . ' WHERE id = %d', $payment_id ) );
		if ( ! $p || $p->voided_at ) {
			return new WP_Error( 'ndwd_not_found', 'Payment not found or already voided.', array( 'status' => 404 ) );
		}
		$reason = sanitize_textarea_field( (string) $reason );
		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'ndwd_invalid', 'Give a reason for voiding the payment.', array( 'status' => 400 ) );
		}
		$wpdb->update( self::t( 'payments' ), array( 'voided_at' => NDWD_Helpers::now_mysql(), 'voided_by' => (int) $user_id, 'void_reason' => $reason ), array( 'id' => $payment_id ) );
		$wpdb->update( self::t( 'income' ), array( 'voided_at' => NDWD_Helpers::now_mysql(), 'voided_by' => (int) $user_id, 'void_reason' => $reason ), array( 'payment_id' => (int) $payment_id ) );
		self::refresh_status( $p->invoice_id );
		self::audit( 'invoice', $p->invoice_id, 'payment_voided', $user_id, array( 'payment_id' => (int) $payment_id, 'amount' => (float) $p->amount, 'reason' => $reason ) );
		self::write_pdf( $p->invoice_id );
		return self::invoice( $p->invoice_id );
	}

	/**
	 * Adjustment after cancel-and-reissue: moves a payment recorded on a cancelled invoice
	 * to the invoice that replaced it. The same receipt is re-linked (never duplicated), so
	 * the passbook still shows the money once.
	 */
	public static function transfer_payment( $payment_id, $user_id ) {
		global $wpdb;
		$p   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'payments' ) . ' WHERE id = %d AND voided_at IS NULL', $payment_id ) );
		$old = $p ? self::invoice_row( $p->invoice_id ) : null;
		if ( ! $p || ! $old ) {
			return new WP_Error( 'ndwd_not_found', 'Payment not found.', array( 'status' => 404 ) );
		}
		$new = $old->replaced_by_id ? self::invoice_row( $old->replaced_by_id ) : null;
		if ( 'cancelled' !== $old->status || ! $new || 'cancelled' === $new->status ) {
			return new WP_Error( 'ndwd_invalid', 'Only payments on a cancelled invoice can be moved, and only to its active replacement.', array( 'status' => 400 ) );
		}
		$balance = self::paise( $new->total ) - self::paise( $new->amount_paid );
		if ( self::paise( $p->amount ) > $balance ) {
			return new WP_Error( 'ndwd_invalid', 'The payment (Rs. ' . self::inr( $p->amount ) . ') is more than the balance due on ' . $new->invoice_no . ' (Rs. ' . self::inr( self::rupees( $balance ) ) . '). Record a refund for the difference instead.', array( 'status' => 400 ) );
		}
		$wpdb->update( self::t( 'payments' ), array( 'invoice_id' => (int) $new->id ), array( 'id' => (int) $payment_id ) );
		$wpdb->update( self::t( 'income' ), array( 'invoice_id' => (int) $new->id, 'client_id' => (int) $new->client_id, 'updated_at' => NDWD_Helpers::now_mysql() ), array( 'payment_id' => (int) $payment_id ) );
		self::refresh_status( $old->id );
		self::refresh_status( $new->id );
		$d = array( 'payment_id' => (int) $payment_id, 'amount' => (float) $p->amount, 'from' => $old->invoice_no, 'to' => $new->invoice_no );
		self::audit( 'invoice', $old->id, 'payment_moved_to_replacement', $user_id, $d );
		self::audit( 'invoice', $new->id, 'payment_moved_from_original', $user_id, $d );
		self::write_pdf( $old->id );
		self::write_pdf( $new->id );
		return self::invoice( $old->id );
	}

	/* ------------------------------------------------------------------ *
	 * Income
	 * ------------------------------------------------------------------ */

	public static function income_array( $x ) {
		static $clients = array(), $projects = null;
		if ( $x->client_id && ! array_key_exists( (int) $x->client_id, $clients ) ) {
			$clients[ (int) $x->client_id ] = NDWD_Clients::get( $x->client_id );
		}
		if ( $x->project_id && null === $projects ) {
			$projects = NDWD_Stats::lookup( 'projects' );
		}
		$c = $x->client_id ? $clients[ (int) $x->client_id ] : null;
		$p = $x->project_id ? (array) $projects : array();
		return array(
			'id'           => (int) $x->id,
			'amount'       => (float) $x->amount,
			'description'  => $x->description,
			'source'       => $x->source,
			'received_on'  => $x->received_on,
			'method'       => $x->method,
			'method_label' => isset( self::METHODS[ $x->method ] ) ? self::METHODS[ $x->method ] : '',
			'destination'  => (string) $x->destination,
			'reference'    => (string) $x->reference,
			'client_id'    => $x->client_id ? (int) $x->client_id : null,
			'client_name'  => $c ? $c->name : '',
			'project_id'   => $x->project_id ? (int) $x->project_id : null,
			'project_name' => $x->project_id && isset( $p[ $x->project_id ] ) ? $p[ $x->project_id ] : '',
			'invoice'      => $x->invoice_id ? self::invoice_ref( $x->invoice_id ) : null,
			'payment_id'   => $x->payment_id ? (int) $x->payment_id : null,
			'kind'         => $x->payment_id ? 'invoice_payment' : 'manual',
			'allocations'  => $x->payment_id ? array() : self::allocations_of_income( $x->id ),
			'remarks'      => (string) $x->remarks,
			'created_by'   => NDWD_Helpers::user_name( $x->created_by ),
			'created_at'   => $x->created_at,
			'voided_at'    => $x->voided_at,
			'void_reason'  => (string) $x->void_reason,
		);
	}

	/** Active allocations of an income entry: [{invoice: {id, invoice_no}, amount}]. Cached per request. */
	private static function allocations_of_income( $income_id ) {
		static $map = null;
		if ( 'flush' === $income_id ) {
			$map = null;
			return array();
		}
		if ( null === $map ) {
			global $wpdb;
			$map = array();
			foreach ( $wpdb->get_results( 'SELECT income_id, invoice_id, amount FROM ' . self::t( 'income_allocations' ) . ' WHERE released_at IS NULL ORDER BY id' ) as $a ) { // phpcs:ignore
				$map[ (int) $a->income_id ][] = array( 'invoice' => self::invoice_ref( $a->invoice_id ), 'amount' => (float) $a->amount );
			}
		}
		return isset( $map[ (int) $income_id ] ) ? $map[ (int) $income_id ] : array();
	}

	public static function income_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'income' ) . ' WHERE id = %d', $id ) );
	}

	/**
	 * Income list with totals. Filters (all combinable): fy / month / from+to, source,
	 * client_id, project_id, method, kind (invoice | manual | advance), state (active |
	 * voided | all; legacy voided=1 = all) and q (description, reference, remarks, client,
	 * project or invoice number). Totals, counts and exports use exactly the listed rows.
	 */
	public static function incomes( $f = array() ) {
		global $wpdb;
		$t    = self::t( 'income' );
		$sql  = "SELECT * FROM $t WHERE 1=1";
		$vals = array();
		$state = isset( $f['state'] ) ? sanitize_key( $f['state'] ) : ( empty( $f['voided'] ) ? 'active' : 'all' );
		if ( 'voided' === $state ) {
			$sql .= ' AND voided_at IS NOT NULL';
		} elseif ( 'all' !== $state ) {
			$sql .= ' AND voided_at IS NULL';
		}
		list( $from, $to ) = self::range_from_filters( $f );
		if ( $from ) {
			$sql   .= ' AND received_on BETWEEN %s AND %s';
			$vals[] = $from;
			$vals[] = $to;
		}
		foreach ( array( 'client_id', 'project_id' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$sql   .= " AND $k = %d";
				$vals[] = absint( $f[ $k ] );
			}
		}
		if ( ! empty( $f['source'] ) ) {
			$sql   .= ' AND source = %s';
			$vals[] = sanitize_text_field( $f['source'] );
		}
		if ( ! empty( $f['method'] ) ) {
			$sql   .= ' AND method = %s';
			$vals[] = sanitize_key( $f['method'] );
		}
		if ( ! empty( $f['kind'] ) ) {
			if ( 'manual' === $f['kind'] ) {
				$sql .= ' AND payment_id IS NULL';
			} elseif ( 'advance' === $f['kind'] ) {
				$sql .= ' AND payment_id IS NULL AND id IN (SELECT income_id FROM ' . self::t( 'income_allocations' ) . ' WHERE released_at IS NULL)';
			} else {
				$sql .= ' AND payment_id IS NOT NULL';
			}
		}
		if ( ! empty( $f['q'] ) ) {
			$like   = '%' . $wpdb->esc_like( trim( (string) $f['q'] ) ) . '%';
			$sql   .= ' AND (description LIKE %s OR reference LIKE %s OR remarks LIKE %s OR source LIKE %s'
				. ' OR client_id IN (SELECT id FROM ' . self::t( 'clients' ) . ' WHERE name LIKE %s)'
				. ' OR project_id IN (SELECT id FROM ' . self::t( 'projects' ) . ' WHERE name LIKE %s)'
				. ' OR invoice_id IN (SELECT id FROM ' . self::t( 'invoices' ) . ' WHERE invoice_no LIKE %s))';
			array_push( $vals, $like, $like, $like, $like, $like, $like, $like );
		}
		$sql .= ' ORDER BY received_on DESC, id DESC';
		$rows = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		$tot  = array( 'all' => 0, 'invoice_payments' => 0, 'manual' => 0, 'allocated' => 0, 'unallocated' => 0 );
		$alloc = self::allocated_by_income();
		$src  = array();
		$mon  = array();
		foreach ( $rows as $x ) {
			if ( $x->voided_at ) {
				continue;
			}
			$p                    = self::paise( $x->amount );
			$tot['all']          += $p;
			$tot[ $x->payment_id ? 'invoice_payments' : 'manual' ] += $p;
			if ( ! $x->payment_id && $x->client_id ) {
				$used                = isset( $alloc[ (int) $x->id ] ) ? $alloc[ (int) $x->id ] : 0;
				$tot['allocated']   += $used;
				$tot['unallocated'] += max( 0, $p - $used );
			}
			$src[ $x->source ]    = ( isset( $src[ $x->source ] ) ? $src[ $x->source ] : 0 ) + $p;
			$mk                   = substr( $x->received_on, 0, 7 );
			$mon[ $mk ]           = ( isset( $mon[ $mk ] ) ? $mon[ $mk ] : 0 ) + $p;
		}
		// Reporting split (same as the overview): money received for invoices = invoice payments +
		// advances adjusted on invoices; other income = manual income not adjusted on invoices.
		$tot['received_invoices'] = $tot['invoice_payments'] + $tot['allocated'];
		$tot['other']             = $tot['manual'] - $tot['allocated'];
		arsort( $src );
		krsort( $mon );
		list( $from, $to ) = self::range_from_filters( $f );
		return array(
			'from'      => $from,
			'to'        => $to,
			'count'     => count( $rows ),
			'items'     => array_map( function ( $x ) use ( $alloc ) {
				$a = self::income_array( $x );
				if ( ! $x->payment_id ) {
					$used           = isset( $alloc[ (int) $x->id ] ) ? $alloc[ (int) $x->id ] : 0;
					$a['allocated'] = self::rupees( $used );
					$a['available'] = $x->voided_at || ! $x->client_id ? 0 : self::rupees( max( 0, self::paise( $x->amount ) - $used ) );
				}
				return $a;
			}, $rows ),
			'totals'    => array_map( array( __CLASS__, 'rupees' ), $tot ),
			'by_source' => array_map( function ( $k, $v ) {
				return array( 'source' => $k, 'total' => self::rupees( $v ) );
			}, array_keys( $src ), array_values( $src ) ),
			'by_month'  => array_map( function ( $k, $v ) {
				return array( 'month' => $k, 'total' => self::rupees( $v ) );
			}, array_keys( $mon ), array_values( $mon ) ),
		);
	}

	/**
	 * Records or edits income.
	 * - With a related invoice (new entries only): recorded as a payment on that invoice,
	 *   which creates the linked Income row and updates the invoice balance and status.
	 * - Without an invoice: manual income, its own passbook credit.
	 * Client and project are each optional; when both are given the project must belong to
	 * that client (or to no client).
	 * Editing an invoice payment's Income row changes only its description, source,
	 * project and remarks: amount, date and method belong to the payment (void and
	 * re-record it to correct them).
	 *
	 * @return array|WP_Error Income.
	 */
	public static function save_income( $in, $user_id, $id = 0 ) {
		global $wpdb;
		$t   = self::t( 'income' );
		$old = $id ? self::income_row( $id ) : null;
		if ( $id && ( ! $old || $old->voided_at ) ) {
			return new WP_Error( 'ndwd_not_found', 'Income entry not found or voided.', array( 'status' => 404 ) );
		}
		$get = function ( $k, $def = '' ) use ( $in, $old ) {
			return array_key_exists( $k, $in ) ? $in[ $k ] : ( $old ? $old->$k : $def );
		};
		$desc = mb_substr( sanitize_text_field( (string) $get( 'description' ) ), 0, 255 );
		if ( '' === $desc ) {
			return new WP_Error( 'ndwd_invalid', 'Enter the income description / reason.', array( 'status' => 400 ) );
		}
		$source = mb_substr( sanitize_text_field( (string) $get( 'source', 'Other Income' ) ), 0, 80 );
		$source = '' === $source || 'Custom Category' === $source ? 'Other Income' : $source;
		$client = absint( $get( 'client_id', 0 ) );
		if ( $client && ! NDWD_Clients::get( $client ) ) {
			return new WP_Error( 'ndwd_invalid', 'Unknown client.', array( 'status' => 400 ) );
		}
		$project = absint( $get( 'project_id', 0 ) );
		if ( $project ) {
			$pc = $wpdb->get_row( $wpdb->prepare( 'SELECT id, client_id FROM ' . self::t( 'projects' ) . ' WHERE id = %d', $project ) );
			if ( ! $pc ) {
				return new WP_Error( 'ndwd_invalid', 'Unknown project.', array( 'status' => 400 ) );
			}
			if ( $client && $pc->client_id && (int) $pc->client_id !== $client ) {
				return new WP_Error( 'ndwd_invalid', 'The selected project belongs to a different client. Choose a project of this client, or no project.', array( 'status' => 400 ) );
			}
		}
		$remarks = sanitize_textarea_field( (string) $get( 'remarks' ) );

		if ( $old && $old->payment_id ) {
			foreach ( array( 'amount', 'received_on', 'method', 'reference', 'invoice_id' ) as $k ) {
				if ( array_key_exists( $k, $in ) && (string) $in[ $k ] !== '' && (string) $in[ $k ] !== (string) $old->$k && ! ( 'amount' === $k && self::paise( $in[ $k ] ) === self::paise( $old->amount ) ) ) {
					return new WP_Error( 'ndwd_invalid', 'This income is a payment on an invoice: its amount, date, method, reference and invoice are kept with the payment. Void the payment and record it again to correct them.', array( 'status' => 400 ) );
				}
			}
			$row = array( 'description' => $desc, 'source' => $source, 'project_id' => $project ? $project : null, 'remarks' => $remarks, 'updated_at' => NDWD_Helpers::now_mysql() );
			self::remember_income_category( $source );
			return self::finish_income_edit( $old, $row, $user_id );
		}

		$invoice_id = absint( $get( 'invoice_id', 0 ) );
		if ( $invoice_id && ! $old ) {
			$v = self::invoice_row( $invoice_id );
			if ( ! $v ) {
				return new WP_Error( 'ndwd_invalid', 'Unknown invoice.', array( 'status' => 400 ) );
			}
			if ( $client && (int) $v->client_id !== $client ) {
				return new WP_Error( 'ndwd_invalid', 'Invoice ' . $v->invoice_no . ' belongs to another client.', array( 'status' => 400 ) );
			}
			$res = self::add_payment( $invoice_id, array(
				'amount'            => $get( 'amount', null ),
				'paid_on'           => $get( 'received_on' ),
				'method'            => $get( 'method' ),
				'destination'       => $get( 'destination' ),
				'reference'         => $get( 'reference' ),
				'notes'             => $remarks,
				'source'            => $source,
				'description'       => $desc,
				'project_id'        => $project,
				'confirm_duplicate' => ! empty( $in['confirm_duplicate'] ),
			), $user_id );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE invoice_id = %d AND payment_id IS NOT NULL ORDER BY id DESC LIMIT 1", $invoice_id ) ); // phpcs:ignore
			return self::income_array( $row );
		}
		if ( $invoice_id && $old && (int) $old->invoice_id !== $invoice_id ) {
			return new WP_Error( 'ndwd_invalid', 'To record this money against an invoice, void this entry and record the payment on the invoice.', array( 'status' => 400 ) );
		}

		$amount = self::paise( $get( 'amount', null ) );
		if ( null === $amount || $amount <= 0 ) {
			return new WP_Error( 'ndwd_invalid', 'Enter an amount greater than 0.', array( 'status' => 400 ) );
		}
		$date = NDWD_Helpers::sanitize_date( (string) $get( 'received_on' ) );
		if ( ! $date || $date > NDWD_Helpers::today() ) {
			return new WP_Error( 'ndwd_invalid', 'Enter the date the money was received (not in the future).', array( 'status' => 400 ) );
		}
		$method = sanitize_key( (string) $get( 'method' ) );
		if ( ! isset( self::METHODS[ $method ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Choose the payment method.', array( 'status' => 400 ) );
		}
		$reference = mb_substr( sanitize_text_field( (string) $get( 'reference' ) ), 0, 120 );
		if ( ! $old && empty( $in['confirm_duplicate'] ) ) {
			$dup = '' !== $reference
				? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE voided_at IS NULL AND reference = %s LIMIT 1", $reference ) ) // phpcs:ignore
				: $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE voided_at IS NULL AND payment_id IS NULL AND amount = %s AND received_on = %s AND description = %s LIMIT 1", self::rupees( $amount ), $date, $desc ) ); // phpcs:ignore
			if ( $dup ) {
				return new WP_Error( 'ndwd_duplicate', 'Income of Rs. ' . self::inr( $dup->amount ) . ' received ' . date_i18n( 'j M Y', strtotime( $dup->received_on ) ) . ( '' !== $reference ? ' with reference ' . $reference : ' for "' . $dup->description . '"' ) . ' is already recorded. Confirm only if this is a separate receipt.', array( 'status' => 409 ) );
			}
		}
		$row = array(
			'amount'      => self::rupees( $amount ),
			'description' => $desc,
			'source'      => $source,
			'received_on' => $date,
			'method'      => $method,
			'destination' => self::destination_in( $in, $old ? $old->destination : '' ),
			'reference'   => $reference,
			'client_id'   => $client ? $client : null,
			'project_id'  => $project ? $project : null,
			'remarks'     => $remarks,
			'updated_at'  => NDWD_Helpers::now_mysql(),
		);
		if ( $old ) {
			// Money already adjusted against invoices must stay covered by this receipt.
			$used = self::allocated_by_income( array( (int) $old->id ) );
			$used = isset( $used[ (int) $old->id ] ) ? $used[ (int) $old->id ] : 0;
			if ( $used && $amount < $used ) {
				return new WP_Error( 'ndwd_invalid', 'Rs. ' . self::inr( self::rupees( $used ) ) . ' of this income is adjusted against invoices, so the amount cannot be less than that. Release or change those allocations on the invoices first.', array( 'status' => 400 ) );
			}
			if ( $used && (int) $old->client_id !== (int) $client ) {
				return new WP_Error( 'ndwd_invalid', 'This income is adjusted against invoices of its client, so the client cannot be changed. Release those allocations on the invoices first.', array( 'status' => 400 ) );
			}
			self::remember_income_category( $source );
			return self::finish_income_edit( $old, $row, $user_id );
		}
		self::remember_income_category( $source );
		$row['created_by'] = (int) $user_id;
		$row['created_at'] = $row['updated_at'];
		$wpdb->insert( $t, $row );
		$id = (int) $wpdb->insert_id;
		self::audit( 'income', $id, 'income_recorded', $user_id, array( 'amount' => $row['amount'], 'source' => $source, 'date' => $date ) );
		return self::income_array( self::income_row( $id ) );
	}

	private static function finish_income_edit( $old, $row, $user_id ) {
		global $wpdb;
		$changes = array();
		foreach ( $row as $k => $val ) {
			if ( 'updated_at' !== $k && (string) $old->$k !== (string) $val ) {
				$changes[ $k ] = array( 'from' => $old->$k, 'to' => $val );
			}
		}
		if ( $changes ) {
			$wpdb->update( self::t( 'income' ), $row, array( 'id' => $old->id ) );
			self::audit( 'income', $old->id, 'income_updated', $user_id, $changes );
		}
		return self::income_array( self::income_row( $old->id ) );
	}

	/** Voids income. An invoice payment's income is voided by voiding the payment itself. */
	public static function void_income( $id, $reason, $user_id ) {
		global $wpdb;
		$x = self::income_row( $id );
		if ( ! $x || $x->voided_at ) {
			return new WP_Error( 'ndwd_not_found', 'Income entry not found or already voided.', array( 'status' => 404 ) );
		}
		$reason = sanitize_textarea_field( (string) $reason );
		if ( '' === trim( $reason ) ) {
			return new WP_Error( 'ndwd_invalid', 'Give a reason for voiding the income.', array( 'status' => 400 ) );
		}
		if ( $x->payment_id ) {
			$res = self::void_payment( $x->payment_id, $reason, $user_id );
			return is_wp_error( $res ) ? $res : self::income_array( self::income_row( $id ) );
		}
		$wpdb->update( self::t( 'income' ), array( 'voided_at' => NDWD_Helpers::now_mysql(), 'voided_by' => (int) $user_id, 'void_reason' => $reason ), array( 'id' => $id ) );
		// Money that was never really received cannot settle invoices: release its allocations.
		$released = self::release_allocations( 0, 'Income voided: ' . $reason, $user_id, (int) $id );
		foreach ( array_unique( wp_list_pluck( $released, 'invoice_id' ) ) as $inv ) {
			self::refresh_status( $inv );
			self::audit( 'invoice', $inv, 'advance_released', $user_id, array( 'income_id' => (int) $id, 'reason' => 'Income voided: ' . $reason ) );
			self::write_pdf( $inv );
		}
		self::audit( 'income', $id, 'income_voided', $user_id, array( 'amount' => (float) $x->amount, 'reason' => $reason ) );
		return self::income_array( self::income_row( $id ) );
	}

	/* ------------------------------------------------------------------ *
	 * Expenses
	 * ------------------------------------------------------------------ */

	public static function expense_row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'expenses' ) . ' WHERE id = %d AND deleted_at IS NULL', $id ) );
	}

	public static function expense_array( $e ) {
		return array(
			'billable'        => (bool) (int) $e->billable,
			'billable_amount' => null !== $e->billable_amount ? (float) $e->billable_amount : (float) $e->amount,
			'billing_remarks' => (string) $e->billing_remarks,
			'id'           => (int) $e->id,
			'title'        => $e->title,
			'description'  => (string) $e->description,
			'category'     => $e->category,
			'amount'       => (float) $e->amount,
			'expense_date' => $e->expense_date,
			'vendor'       => $e->vendor,
			'method'       => $e->method,
			'method_label' => isset( self::METHODS[ $e->method ] ) ? self::METHODS[ $e->method ] : '',
			'destination'  => (string) $e->destination,
			'reference'    => $e->reference,
			'has_receipt'  => '' !== $e->receipt_path,
			'receipt_name' => $e->receipt_name,
			'client_id'    => $e->client_id ? (int) $e->client_id : null,
			'project_id'   => $e->project_id ? (int) $e->project_id : null,
			'notes'        => (string) $e->notes,
			'status'       => $e->status,
			'paid_on'      => $e->paid_on,
			'fy'           => self::fy_of( $e->expense_date ),
			'created_by'   => NDWD_Helpers::user_name( $e->created_by ),
			'created_at'   => $e->created_at,
		);
	}

	public static function expenses( $f = array() ) {
		global $wpdb;
		$sql  = 'SELECT * FROM ' . self::t( 'expenses' ) . ' WHERE deleted_at IS NULL';
		$vals = array();
		list( $from, $to ) = self::range_from_filters( $f );
		if ( $from ) {
			$sql   .= ' AND expense_date BETWEEN %s AND %s';
			$vals[] = $from;
			$vals[] = $to;
		}
		if ( ! empty( $f['category'] ) ) {
			$sql   .= ' AND category = %s';
			$vals[] = sanitize_text_field( $f['category'] );
		}
		if ( ! empty( $f['status'] ) && in_array( $f['status'], array( 'paid', 'unpaid' ), true ) ) {
			$sql   .= ' AND status = %s';
			$vals[] = $f['status'];
		}
		if ( ! empty( $f['billable'] ) ) {
			$sql .= ' AND billable = 1';
		}
		if ( ! empty( $f['q'] ) ) {
			$like   = '%' . $wpdb->esc_like( trim( (string) $f['q'] ) ) . '%';
			$sql   .= ' AND (title LIKE %s OR description LIKE %s OR vendor LIKE %s OR reference LIKE %s OR category LIKE %s OR notes LIKE %s'
				. ' OR client_id IN (SELECT id FROM ' . self::t( 'clients' ) . ' WHERE name LIKE %s)'
				. ' OR project_id IN (SELECT id FROM ' . self::t( 'projects' ) . ' WHERE name LIKE %s))';
			array_push( $vals, $like, $like, $like, $like, $like, $like, $like, $like );
		}
		$sql .= ' ORDER BY expense_date DESC, id DESC LIMIT 1000';
		$rows   = $vals ? $wpdb->get_results( $wpdb->prepare( $sql, $vals ) ) : $wpdb->get_results( $sql ); // phpcs:ignore
		$billed = self::expense_billed_map( array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		return array_map( function ( $r ) use ( $billed ) {
			$a              = self::expense_array( $r );
			$a['billed_in'] = isset( $billed[ (int) $r->id ] ) ? $billed[ (int) $r->id ] : array();
			return $a;
		}, $rows );
	}

	public static function save_expense( $in, $user_id, $id = 0 ) {
		global $wpdb;
		$t   = self::t( 'expenses' );
		$old = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d AND deleted_at IS NULL", $id ) ) : null;
		if ( $id && ! $old ) {
			return new WP_Error( 'ndwd_not_found', 'Expense not found.', array( 'status' => 404 ) );
		}
		$get = function ( $k, $def = '' ) use ( $in, $old ) {
			return array_key_exists( $k, $in ) ? $in[ $k ] : ( $old ? $old->$k : $def );
		};
		$title = mb_substr( sanitize_text_field( (string) $get( 'title' ) ), 0, 200 );
		if ( '' === $title ) {
			return new WP_Error( 'ndwd_invalid', 'Expense title is required.', array( 'status' => 400 ) );
		}
		$amount = self::paise( $get( 'amount', null ) );
		if ( null === $amount || $amount <= 0 ) {
			return new WP_Error( 'ndwd_invalid', 'Enter an amount greater than 0.', array( 'status' => 400 ) );
		}
		$date = NDWD_Helpers::sanitize_date( (string) $get( 'expense_date' ) );
		if ( ! $date ) {
			return new WP_Error( 'ndwd_invalid', 'Enter the expense date.', array( 'status' => 400 ) );
		}
		$category = mb_substr( sanitize_text_field( (string) $get( 'category', 'Other' ) ), 0, 80 );
		$category = '' === $category ? 'Other' : $category;
		$status   = 'paid' === $get( 'status', 'unpaid' ) ? 'paid' : 'unpaid';
		$paid_on  = null;
		$method   = sanitize_key( (string) $get( 'method' ) );
		if ( '' !== $method && ! isset( self::METHODS[ $method ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Unknown payment method.', array( 'status' => 400 ) );
		}
		if ( 'paid' === $status ) {
			$paid_on = NDWD_Helpers::sanitize_date( (string) $get( 'paid_on' ) );
			if ( ! $paid_on ) {
				return new WP_Error( 'ndwd_invalid', 'A paid expense needs the date it was paid.', array( 'status' => 400 ) );
			}
			if ( '' === $method ) {
				return new WP_Error( 'ndwd_invalid', 'A paid expense needs the payment method.', array( 'status' => 400 ) );
			}
		}
		$client_id = absint( $get( 'client_id', 0 ) );
		if ( $client_id && ! NDWD_Clients::get( $client_id ) ) {
			return new WP_Error( 'ndwd_invalid', 'Unknown client.', array( 'status' => 400 ) );
		}
		$project_id = absint( $get( 'project_id', 0 ) );
		if ( $project_id ) {
			$pcl = NDWD_Clients::for_project( $project_id );
			if ( $client_id && $pcl && $pcl !== $client_id ) {
				return new WP_Error( 'ndwd_invalid', 'The selected project belongs to a different client.', array( 'status' => 400 ) );
			}
		}
		// Billable to client: needs a client (directly or through the project). The billable
		// amount defaults to the expense amount; it is what an invoice proposes, nothing more.
		$billable = ! empty( $get( 'billable', 0 ) ) && 'false' !== $get( 'billable', 0 ) ? 1 : 0;
		$b_amount = null;
		if ( $billable ) {
			if ( ! $client_id && ! ( $project_id && NDWD_Clients::for_project( $project_id ) ) ) {
				return new WP_Error( 'ndwd_invalid', 'Choose the client (or a client project) this expense is billable to.', array( 'status' => 400 ) );
			}
			$raw      = $get( 'billable_amount', null );
			$b_amount = null === $raw || '' === $raw ? $amount : self::paise( $raw );
			if ( null === $b_amount || $b_amount <= 0 ) {
				return new WP_Error( 'ndwd_invalid', 'Enter a billable amount greater than 0.', array( 'status' => 400 ) );
			}
		}
		// Once on an active invoice, the expense keeps its client and stays billable until that invoice is cancelled.
		if ( $old ) {
			$on = self::expense_billed_map( array( (int) $old->id ) );
			if ( $on ) {
				$nos = implode( ', ', wp_list_pluck( $on[ (int) $old->id ], 'invoice_no' ) );
				if ( ! $billable || self::expense_client( (object) array( 'client_id' => $client_id, 'project_id' => $project_id ) ) !== self::expense_client( $old ) ) {
					return new WP_Error( 'ndwd_invalid', 'This expense is billed in ' . $nos . ', so its client and billable status cannot change. Cancel that invoice first.', array( 'status' => 400 ) );
				}
			}
		}
		$row = array(
			'title'        => $title,
			'description'  => sanitize_textarea_field( (string) $get( 'description' ) ),
			'category'     => $category,
			'amount'       => self::rupees( $amount ),
			'expense_date' => $date,
			'vendor'       => mb_substr( sanitize_text_field( (string) $get( 'vendor' ) ), 0, 160 ),
			'method'       => $method,
			'destination'  => self::destination_in( $in, $old ? $old->destination : '' ),
			'reference'    => mb_substr( sanitize_text_field( (string) $get( 'reference' ) ), 0, 120 ),
			'client_id'    => $client_id ? $client_id : null,
			'project_id'   => $project_id ? $project_id : null,
			'billable'     => $billable,
			'billable_amount' => null !== $b_amount ? self::rupees( $b_amount ) : null,
			'billing_remarks' => mb_substr( sanitize_text_field( (string) $get( 'billing_remarks' ) ), 0, 255 ),
			'notes'        => sanitize_textarea_field( (string) $get( 'notes' ) ),
			'status'       => $status,
			'paid_on'      => $paid_on,
			'updated_at'   => NDWD_Helpers::now_mysql(),
		);
		if ( ! empty( $in['receipt'] ) && is_array( $in['receipt'] ) ) {
			$r = self::store_receipt( $in['receipt'] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$row['receipt_path'] = $r['path'];
			$row['receipt_name'] = $r['name'];
		}
		if ( $id ) {
			$changes = array();
			foreach ( $row as $k => $val ) {
				if ( 'updated_at' !== $k && (string) $old->$k !== (string) $val ) {
					$changes[ $k ] = array( 'from' => $old->$k, 'to' => $val );
				}
			}
			$wpdb->update( $t, $row, array( 'id' => $id ) );
			if ( $changes ) {
				self::audit( 'expense', $id, 'expense_updated', $user_id, $changes );
			}
		} else {
			$row['created_by'] = (int) $user_id;
			$row['created_at'] = $row['updated_at'];
			$wpdb->insert( $t, $row );
			$id = (int) $wpdb->insert_id;
			self::audit( 'expense', $id, 'expense_created', $user_id, array( 'title' => $title, 'amount' => $row['amount'], 'status' => $status ) );
		}
		return self::expense_array( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) ) );
	}

	/** Soft delete: the row stays for the audit trail but leaves every total. */
	public static function delete_expense( $id, $user_id ) {
		global $wpdb;
		$on = self::expense_billed_map( array( (int) $id ) );
		if ( $on ) {
			return new WP_Error( 'ndwd_invalid', 'This expense is billed in ' . implode( ', ', wp_list_pluck( $on[ (int) $id ], 'invoice_no' ) ) . ' and cannot be deleted. Cancel that invoice first.', array( 'status' => 400 ) );
		}
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'expenses' ) . ' SET deleted_at = %s WHERE id = %d AND deleted_at IS NULL', NDWD_Helpers::now_mysql(), $id ) );
		if ( ! $done ) {
			return new WP_Error( 'ndwd_not_found', 'Expense not found.', array( 'status' => 404 ) );
		}
		self::audit( 'expense', $id, 'expense_deleted', $user_id );
		return array( 'ok' => true );
	}

	/** Saves an uploaded receipt (base64 data) into the protected storage folder. */
	private static function store_receipt( $r ) {
		$name = sanitize_file_name( isset( $r['name'] ) ? (string) $r['name'] : 'receipt' );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::RECEIPT_TYPES, true ) ) {
			return new WP_Error( 'ndwd_invalid', 'Receipts can be PDF, JPG, PNG or WEBP files.', array( 'status' => 400 ) );
		}
		$data  = isset( $r['data'] ) ? (string) $r['data'] : '';
		$data  = preg_replace( '#^data:[^;]+;base64,#', '', $data );
		$bytes = base64_decode( $data, true );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'ndwd_invalid', 'The receipt file could not be read.', array( 'status' => 400 ) );
		}
		if ( strlen( $bytes ) > self::RECEIPT_MAX ) {
			return new WP_Error( 'ndwd_invalid', 'Receipts can be at most 5 MB.', array( 'status' => 400 ) );
		}
		$magic = array( 'pdf' => '%PDF', 'png' => "\x89PNG", 'jpg' => "\xFF\xD8", 'jpeg' => "\xFF\xD8", 'webp' => 'RIFF' );
		if ( 0 !== strpos( $bytes, $magic[ $ext ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'The file content does not match its type.', array( 'status' => 400 ) );
		}
		$rel = 'receipts/' . NDWD_Helpers::now()->format( 'Y' ) . '/' . wp_generate_password( 12, false ) . '.' . $ext;
		NDWD_Files::dir( dirname( $rel ) );
		if ( false === file_put_contents( NDWD_Reports::base_dir() . '/' . $rel, $bytes ) ) { // phpcs:ignore
			return new WP_Error( 'ndwd_io', 'Could not store the receipt.', array( 'status' => 500 ) );
		}
		return array( 'path' => $rel, 'name' => mb_substr( $name, 0, 190 ) );
	}

	public static function stream_receipt( $id ) {
		global $wpdb;
		$e    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'expenses' ) . ' WHERE id = %d', $id ) );
		$path = $e && $e->receipt_path ? NDWD_Files::path( $e->receipt_path ) : null;
		if ( ! $path ) {
			return new WP_Error( 'ndwd_not_found', 'Receipt not found.', array( 'status' => 404 ) );
		}
		NDWD_Files::send_file( $path, $e->receipt_name ? $e->receipt_name : basename( $path ) );
	}

	/* ------------------------------------------------------------------ *
	 * Other transactions (refunds, other income / payments)
	 * ------------------------------------------------------------------ */

	public static function save_entry( $in, $user_id ) {
		global $wpdb;
		$type = isset( $in['entry_type'] ) ? sanitize_key( $in['entry_type'] ) : '';
		if ( ! isset( self::ENTRY_TYPES[ $type ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Choose the transaction type.', array( 'status' => 400 ) );
		}
		$amount = self::paise( isset( $in['amount'] ) ? $in['amount'] : null );
		$date   = NDWD_Helpers::sanitize_date( isset( $in['entry_date'] ) ? (string) $in['entry_date'] : '' );
		if ( null === $amount || $amount <= 0 || ! $date ) {
			return new WP_Error( 'ndwd_invalid', 'Enter an amount greater than 0 and the date.', array( 'status' => 400 ) );
		}
		$method = isset( $in['method'] ) ? sanitize_key( $in['method'] ) : '';
		if ( '' !== $method && ! isset( self::METHODS[ $method ] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Unknown payment method.', array( 'status' => 400 ) );
		}
		$invoice_id = absint( isset( $in['invoice_id'] ) ? $in['invoice_id'] : 0 );
		$linked     = $invoice_id ? self::invoice_row( $invoice_id ) : null;
		if ( $invoice_id && ! $linked ) {
			return new WP_Error( 'ndwd_invalid', 'Unknown invoice.', array( 'status' => 400 ) );
		}
		if ( $linked && empty( $in['client_id'] ) ) {
			$in['client_id'] = (int) $linked->client_id;
		}
		$wpdb->insert( self::t( 'fin_entries' ), array(
			'entry_date'  => $date,
			'entry_type'  => $type,
			'direction'   => self::ENTRY_TYPES[ $type ][1],
			'amount'      => self::rupees( $amount ),
			'party'       => mb_substr( sanitize_text_field( isset( $in['party'] ) ? (string) $in['party'] : '' ), 0, 160 ),
			'client_id'   => absint( isset( $in['client_id'] ) ? $in['client_id'] : 0 ) ? absint( $in['client_id'] ) : null,
			'invoice_id'  => $invoice_id ? $invoice_id : null,
			'method'      => $method,
			'destination' => self::destination_in( $in ),
			'reference'   => mb_substr( sanitize_text_field( isset( $in['reference'] ) ? (string) $in['reference'] : '' ), 0, 120 ),
			'description' => sanitize_textarea_field( isset( $in['description'] ) ? (string) $in['description'] : '' ),
			'created_by'  => (int) $user_id,
			'created_at'  => NDWD_Helpers::now_mysql(),
		) );
		$id = (int) $wpdb->insert_id;
		self::audit( 'entry', $id, 'entry_created', $user_id, array( 'type' => $type, 'amount' => self::rupees( $amount ) ) );
		if ( $linked ) {
			self::audit( 'invoice', $linked->id, 'transaction_linked', $user_id, array( 'type' => self::ENTRY_TYPES[ $type ][0], 'amount' => self::rupees( $amount ), 'date' => $date ) );
		}
		return array( 'id' => $id );
	}

	public static function void_entry( $id, $user_id ) {
		global $wpdb;
		$done = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::t( 'fin_entries' ) . ' SET voided_at = %s WHERE id = %d AND voided_at IS NULL', NDWD_Helpers::now_mysql(), $id ) );
		if ( ! $done ) {
			return new WP_Error( 'ndwd_not_found', 'Transaction not found.', array( 'status' => 404 ) );
		}
		self::audit( 'entry', $id, 'entry_voided', $user_id );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ *
	 * Passbook / ledger
	 * ------------------------------------------------------------------ */

	/** [from, to] from fy / month / from+to filters; [null, null] for no date limit. */
	public static function range_from_filters( $f ) {
		if ( ! empty( $f['from'] ) && ! empty( $f['to'] ) && NDWD_Helpers::valid_date( $f['from'] ) && NDWD_Helpers::valid_date( $f['to'] ) ) {
			return array( $f['from'], $f['to'] );
		}
		if ( ! empty( $f['month'] ) && preg_match( '/^\d{4}-\d{2}$/', $f['month'] ) ) {
			return NDWD_Helpers::month_range( $f['month'] );
		}
		if ( ! empty( $f['fy'] ) && self::valid_fy( $f['fy'] ) ) {
			return self::fy_range( $f['fy'] );
		}
		return array( null, null );
	}

	/**
	 * Passbook: actual money in and out only (invoice payments, paid expenses, refunds and
	 * other recorded transactions). Issuing or cancelling an invoice is not a cash movement,
	 * so it never appears here; that history stays on the invoice.
	 *
	 * Rows are ordered by date, then by when they were recorded, then by kind and id, so the
	 * running balance is deterministic:
	 *   balance = opening balance + cumulative credits - cumulative debits.
	 * Without a configured opening balance the running balance starts from 0.00.
	 */
	public static function ledger( $f = array() ) {
		global $wpdb;
		$rows = array();
		$inv  = array();
		foreach ( $wpdb->get_results( 'SELECT id, invoice_no, client_id, client_snapshot FROM ' . self::t( 'invoices' ) ) as $v ) { // phpcs:ignore
			$c             = json_decode( (string) $v->client_snapshot, true );
			$inv[ $v->id ] = array( $v->invoice_no, isset( $c['name'] ) ? $c['name'] : '', (int) $v->client_id );
		}
		$income_of = array();
		foreach ( $wpdb->get_results( 'SELECT id, payment_id, source FROM ' . self::t( 'income' ) . ' WHERE payment_id IS NOT NULL' ) as $x ) { // phpcs:ignore
			$income_of[ (int) $x->payment_id ] = (int) $x->id;
		}
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::t( 'payments' ) . ' WHERE voided_at IS NULL' ) as $p ) { // phpcs:ignore
			$i      = isset( $inv[ $p->invoice_id ] ) ? $inv[ $p->invoice_id ] : array( '#' . $p->invoice_id, '', 0 );
			$rows[] = array( 'date' => $p->paid_on, 'type' => 'payment', 'type_label' => 'Payment Received', 'reference' => '' !== $p->reference ? $p->reference : 'PMT-' . str_pad( $p->id, 4, '0', STR_PAD_LEFT ), 'party' => $i[1], 'client_id' => $i[2], 'description' => 'Payment for ' . $i[0] . ( $p->notes ? ' — ' . $p->notes : '' ), 'in' => (float) $p->amount, 'out' => 0, 'method' => $p->method, 'destination' => (string) $p->destination, 'invoice' => array( 'id' => (int) $p->invoice_id, 'invoice_no' => $i[0] ), 'source' => array( 'kind' => 'payment', 'id' => (int) $p->id ), 'income_id' => isset( $income_of[ (int) $p->id ] ) ? $income_of[ (int) $p->id ] : null, 'sort' => $p->created_at . '|1|' . str_pad( $p->id, 10, '0', STR_PAD_LEFT ) );
		}
		// Manual income (invoice payments are already listed above, once).
		$cl = array();
		// Invoices each receipt is adjusted against. Unallocated income simply has none.
		$adjusted = array();
		foreach ( $wpdb->get_results( 'SELECT income_id, invoice_id, SUM(amount) amt FROM ' . self::t( 'income_allocations' ) . ' WHERE released_at IS NULL GROUP BY income_id, invoice_id ORDER BY MIN(id)' ) as $al ) { // phpcs:ignore
			if ( isset( $inv[ $al->invoice_id ] ) ) {
				$adjusted[ (int) $al->income_id ][] = array( 'id' => (int) $al->invoice_id, 'invoice_no' => $inv[ $al->invoice_id ][0], 'amount' => (float) $al->amt );
			}
		}
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::t( 'income' ) . ' WHERE voided_at IS NULL AND payment_id IS NULL' ) as $x ) { // phpcs:ignore
			if ( $x->client_id && ! isset( $cl[ $x->client_id ] ) ) {
				$c                   = NDWD_Clients::get( $x->client_id );
				$cl[ $x->client_id ] = $c ? $c->name : '';
			}
			$rows[] = array( 'date' => $x->received_on, 'type' => 'income', 'type_label' => 'Income Received', 'reference' => '' !== $x->reference ? $x->reference : 'INC-' . str_pad( $x->id, 4, '0', STR_PAD_LEFT ), 'party' => $x->client_id ? $cl[ $x->client_id ] : '', 'client_id' => $x->client_id ? (int) $x->client_id : 0, 'description' => $x->source . ' — ' . $x->description . ( $x->remarks ? ' (' . $x->remarks . ')' : '' ), 'in' => (float) $x->amount, 'out' => 0, 'method' => $x->method, 'destination' => (string) $x->destination, 'invoice' => isset( $adjusted[ (int) $x->id ] ) ? $adjusted[ (int) $x->id ][0] : null, 'invoices' => isset( $adjusted[ (int) $x->id ] ) ? $adjusted[ (int) $x->id ] : array(), 'source' => array( 'kind' => 'income', 'id' => (int) $x->id ), 'income_id' => (int) $x->id, 'sort' => $x->created_at . '|4|' . str_pad( $x->id, 10, '0', STR_PAD_LEFT ) );
		}
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::t( 'expenses' ) . " WHERE deleted_at IS NULL AND status = 'paid'" ) as $e ) { // phpcs:ignore
			$rows[] = array( 'date' => $e->paid_on ? $e->paid_on : $e->expense_date, 'type' => 'expense', 'type_label' => 'Expense Paid', 'reference' => '' !== $e->reference ? $e->reference : 'EXP-' . str_pad( $e->id, 4, '0', STR_PAD_LEFT ), 'party' => $e->vendor, 'client_id' => $e->client_id ? (int) $e->client_id : 0, 'description' => $e->title . ' (' . $e->category . ')', 'in' => 0, 'out' => (float) $e->amount, 'method' => $e->method, 'destination' => (string) $e->destination, 'invoice' => null, 'source' => array( 'kind' => 'expense', 'id' => (int) $e->id ), 'sort' => $e->created_at . '|2|' . str_pad( $e->id, 10, '0', STR_PAD_LEFT ) );
		}
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::t( 'fin_entries' ) . ' WHERE voided_at IS NULL' ) as $x ) { // phpcs:ignore
			if ( ! isset( self::ENTRY_TYPES[ $x->entry_type ] ) ) {
				continue;
			}
			$in     = 'in' === $x->direction;
			$rows[] = array( 'date' => $x->entry_date, 'type' => $x->entry_type, 'type_label' => self::ENTRY_TYPES[ $x->entry_type ][0], 'reference' => '' !== $x->reference ? $x->reference : 'TXN-' . str_pad( $x->id, 4, '0', STR_PAD_LEFT ), 'party' => $x->party ? $x->party : ( $x->invoice_id && isset( $inv[ $x->invoice_id ] ) ? $inv[ $x->invoice_id ][1] : '' ), 'client_id' => $x->client_id ? (int) $x->client_id : 0, 'description' => (string) $x->description, 'in' => $in ? (float) $x->amount : 0, 'out' => $in ? 0 : (float) $x->amount, 'method' => $x->method, 'destination' => (string) $x->destination, 'invoice' => $x->invoice_id && isset( $inv[ $x->invoice_id ] ) ? array( 'id' => (int) $x->invoice_id, 'invoice_no' => $inv[ $x->invoice_id ][0] ) : null, 'source' => array( 'kind' => 'entry', 'id' => (int) $x->id ), 'entry_id' => (int) $x->id, 'sort' => $x->created_at . '|3|' . str_pad( $x->id, 10, '0', STR_PAD_LEFT ) );
		}
		usort( $rows, function ( $a, $b ) {
			return strcmp( $a['date'] . '|' . $a['sort'], $b['date'] . '|' . $b['sort'] );
		} );

		$s        = self::settings();
		$has_open = '' !== (string) $s['opening_balance'] && $s['opening_date'];
		$open     = $has_open ? self::paise( $s['opening_balance'] ) : 0;
		$bal      = $open;
		foreach ( $rows as &$r ) {
			if ( $has_open && $r['date'] < $s['opening_date'] ) {
				$r['balance'] = null; // Before the opening balance date: shown, not counted.
			} else {
				$bal         += self::paise( $r['in'] ) - self::paise( $r['out'] );
				$r['balance'] = self::rupees( $bal );
			}
			if ( ! isset( $r['invoices'] ) ) {
				$r['invoices'] = $r['invoice'] ? array( $r['invoice'] ) : array();
			}
			$r['method_label'] = isset( self::METHODS[ $r['method'] ] ) ? self::METHODS[ $r['method'] ] : '';
			unset( $r['sort'] );
		}
		unset( $r );

		list( $from, $to ) = self::range_from_filters( $f );
		// Balance brought forward into the period and carried out of it (all transactions).
		$period_open  = $open;
		$period_close = $bal;
		if ( $from ) {
			$period_close = $open;
			foreach ( $rows as $r ) {
				if ( null === $r['balance'] ) {
					continue;
				}
				if ( $r['date'] < $from ) {
					$period_open = self::paise( $r['balance'] );
				}
				if ( $r['date'] <= $to ) {
					$period_close = self::paise( $r['balance'] );
				}
			}
		}
		$type   = isset( $f['type'] ) ? sanitize_key( $f['type'] ) : '';
		$method = isset( $f['method'] ) ? sanitize_key( $f['method'] ) : '';
		$party  = isset( $f['party'] ) ? mb_strtolower( trim( (string) $f['party'] ) ) : '';
		$client = isset( $f['client_id'] ) ? absint( $f['client_id'] ) : 0;
		$rows   = array_values( array_filter( $rows, function ( $r ) use ( $from, $to, $type, $method, $party, $client ) {
			if ( $from && ( $r['date'] < $from || $r['date'] > $to ) ) {
				return false;
			}
			if ( $type && $r['type'] !== $type ) {
				return false;
			}
			if ( $method && $r['method'] !== $method ) {
				return false;
			}
			if ( $client && (int) $r['client_id'] !== $client ) {
				return false;
			}
			return ! $party || false !== strpos( mb_strtolower( $r['party'] ), $party );
		} ) );
		$tot = array( 'in' => 0, 'out' => 0 );
		foreach ( $rows as $r ) {
			$tot['in']  += self::paise( $r['in'] );
			$tot['out'] += self::paise( $r['out'] );
		}
		$account = trim( implode( ' · ', array_filter( array( $s['account_name'], $s['bank_name'], '' !== (string) $s['account_number'] ? 'A/c XX' . substr( preg_replace( '/\s+/', '', $s['account_number'] ), -4 ) : '' ) ) ) );
		return array(
			'rows'            => $rows,
			'totals'          => array_map( array( __CLASS__, 'rupees' ), $tot ),
			'net'             => self::rupees( $tot['in'] - $tot['out'] ),
			'opening_balance' => $has_open ? array( 'amount' => (float) $s['opening_balance'], 'date' => $s['opening_date'] ) : null,
			'period_opening'  => self::rupees( $period_open ),
			'period_closing'  => self::rupees( $period_close ),
			'filtered'        => (bool) ( $type || $method || $party || $client ),
			'from'            => $from,
			'to'              => $to,
			'account'         => $account,
		);
	}

	/** Passbook PDF: same rows and totals as the on-screen passbook. @return true|WP_Error */
	public static function passbook_pdf( $f, $path, $period ) {
		$l   = self::ledger( $f );
		$pdf = new NDWD_PDF_Writer( 'Passbook', true );
		$pdf->heading( 'Accounts Passbook', 16 );
		$meta = array( 'Period: ' . $period );
		if ( '' !== $l['account'] ) {
			$meta[] = 'Account: ' . $l['account'];
		}
		$meta[] = 'Opening balance: Rs. ' . self::inr( $l['period_opening'] ) . ( $l['opening_balance'] || $l['from'] ? '' : ' (no opening balance configured)' );
		if ( $l['filtered'] ) {
			$meta[] = 'Filtered view: totals cover the listed rows only; balances are the account balance after each row.';
		}
		$pdf->paragraph( implode( '   |   ', $meta ), 9 );
		$rows = array_map( function ( $r ) {
			$desc = trim( ( $r['party'] ? $r['party'] . ' — ' : '' ) . $r['description'] );
			$pay  = trim( $r['method_label'] . ( $r['destination'] ? ' · ' . $r['destination'] : '' ) );
			return array( date_i18n( 'd M Y', strtotime( $r['date'] ) ), $r['type_label'], $r['reference'], $desc, $pay, $r['invoice'] ? $r['invoice']['invoice_no'] : '', $r['in'] ? self::inr( $r['in'] ) : '', $r['out'] ? self::inr( $r['out'] ) : '', null === $r['balance'] ? '' : self::inr( $r['balance'] ) );
		}, $l['rows'] );
		$pdf->table( array( 'Date', 'Type', 'Reference', 'Description', 'Method / Account', 'Invoice', 'Credit (Rs.)', 'Debit (Rs.)', 'Balance (Rs.)' ), $rows, array( 0.9, 1.15, 1.25, 3.2, 1.6, 1.25, 1, 1, 1.05 ), 7.5, array( 'L', 'L', 'L', 'L', 'L', 'L', 'R', 'R', 'R' ) );
		$pdf->stats( array(
			array( 'Opening balance', 'Rs. ' . self::inr( $l['period_opening'] ) ),
			array( 'Total credits', 'Rs. ' . self::inr( $l['totals']['in'] ) ),
			array( 'Total debits', 'Rs. ' . self::inr( $l['totals']['out'] ) ),
			array( 'Closing balance', 'Rs. ' . self::inr( $l['period_closing'] ) ),
		) );
		$pdf->paragraph( 'Only money actually received or paid is listed. Invoices issued or cancelled are not cash movements and appear in the Invoices section. Generated ' . NDWD_Helpers::now()->format( 'd M Y, h:i A' ) . ' IST by ' . NDWD_Helpers::user_name( get_current_user_id() ) . '.', 8 );
		return $pdf->save( $path );
	}

	/* ------------------------------------------------------------------ *
	 * Overview
	 * ------------------------------------------------------------------ */

	/**
	 * Current available funds (cash / bank position, not profit): the passbook balance as of
	 * today = opening balance + money actually received − money actually paid. Each payment,
	 * manual income (advance) and paid expense is a single passbook row, so advances later
	 * allocated to invoices, unpaid invoices and unpaid expenses never change it. Rows dated
	 * before the opening-balance date or after today are not counted. This is the one
	 * consolidated business balance: payment method and account are labels on each
	 * transaction and never hold balances of their own.
	 */
	public static function available_funds() {
		$today = NDWD_Helpers::today();
		$l     = self::ledger( array( 'from' => '2000-01-01', 'to' => $today ) );
		return array(
			'balance' => $l['period_closing'],
			'as_of'   => $today,
			'opening' => $l['opening_balance'],
		);
	}

	public static function overview( $fy ) {
		global $wpdb;
		$fy = self::valid_fy( $fy ) ? $fy : self::fy_of( NDWD_Helpers::today() );
		list( $from, $to ) = self::fy_range( $fy );
		$today = NDWD_Helpers::today();
		$inv   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'invoices' ) . " WHERE invoice_date BETWEEN %s AND %s AND status <> 'cancelled'", $from, $to ) );
		$k     = array( 'invoiced' => 0, 'received' => 0, 'received_payments' => 0, 'received_allocated' => 0, 'other_income' => 0, 'total_income' => 0, 'net' => 0, 'outstanding' => 0, 'outstanding_count' => 0, 'overdue' => 0, 'overdue_count' => 0, 'expenses' => 0, 'expenses_paid' => 0, 'expenses_unpaid' => 0, 'invoices' => count( $inv ), 'invoiced_expenses' => 0, 'billable_unbilled' => 0 );
		foreach ( $inv as $v ) {
			// Balances brought forward by consolidation were invoiced on the earlier invoice: not new revenue.
			$k['invoiced']          += self::paise( $v->total ) - self::paise( $v->carried_forward );
			$k['invoiced_expenses'] += self::paise( $v->expense_subtotal );
			$bal            = self::paise( $v->total ) - self::paise( $v->amount_paid );
			$k['outstanding'] += $bal;
			if ( $bal > 0 && in_array( $v->status, array( 'unpaid', 'partial' ), true ) ) {
				$k['outstanding_count']++;
				if ( $v->due_date < $today ) {
					$k['overdue'] += $bal;
					$k['overdue_count']++;
				}
			}
		}
		// Received from invoices = payments dated within the year (whatever year the invoice
		// belongs to) + the part of the year's manual income (advances) adjusted against invoices.
		// The allocated part moves from "other income" to "received from invoices", so total
		// income never changes when money already received is allocated.
		$k['received_payments'] = self::paise( $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount),0) FROM ' . self::t( 'payments' ) . ' WHERE voided_at IS NULL AND paid_on BETWEEN %s AND %s', $from, $to ) ) );
		$alloc = $wpdb->get_results( $wpdb->prepare( 'SELECT a.amount, i.received_on d FROM ' . self::t( 'income_allocations' ) . ' a JOIN ' . self::t( 'income' ) . ' i ON i.id = a.income_id WHERE a.released_at IS NULL AND i.voided_at IS NULL AND i.payment_id IS NULL AND i.received_on BETWEEN %s AND %s', $from, $to ) );
		foreach ( $alloc as $a ) {
			$k['received_allocated'] += self::paise( $a->amount );
		}
		$k['received'] = $k['received_payments'] + $k['received_allocated'];
		// Other income = manual income (not invoice payments) not allocated to invoices, plus
		// legacy "Other Money Received" entries.
		$other = array_merge(
			$wpdb->get_results( $wpdb->prepare( 'SELECT amount, received_on d FROM ' . self::t( 'income' ) . ' WHERE voided_at IS NULL AND payment_id IS NULL AND received_on BETWEEN %s AND %s', $from, $to ) ),
			$wpdb->get_results( $wpdb->prepare( 'SELECT amount, entry_date d FROM ' . self::t( 'fin_entries' ) . " WHERE voided_at IS NULL AND entry_type = 'other_income' AND entry_date BETWEEN %s AND %s", $from, $to ) )
		);
		foreach ( $other as $o ) {
			$k['other_income'] += self::paise( $o->amount );
		}
		$k['other_income'] -= $k['received_allocated'];
		$k['total_income']  = $k['received'] + $k['other_income'];
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT amount, status FROM ' . self::t( 'expenses' ) . ' WHERE deleted_at IS NULL AND expense_date BETWEEN %s AND %s', $from, $to ) ) as $e ) {
			$k['expenses'] += self::paise( $e->amount );
			$k[ 'paid' === $e->status ? 'expenses_paid' : 'expenses_unpaid' ] += self::paise( $e->amount );
		}
		$months = array();
		$m      = new DateTimeImmutable( $from );
		for ( $i = 0; $i < 12; $i++ ) {
			$months[ $m->format( 'Y-m' ) ] = array( 'month' => $m->format( 'Y-m' ), 'label' => $m->format( 'M Y' ), 'invoiced' => 0, 'received' => 0, 'other_income' => 0, 'income' => 0, 'expenses' => 0 );
			$m = $m->modify( '+1 month' );
		}
		foreach ( $inv as $v ) {
			$months[ substr( $v->invoice_date, 0, 7 ) ]['invoiced'] += self::paise( $v->total ) - self::paise( $v->carried_forward );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT amount, paid_on FROM ' . self::t( 'payments' ) . ' WHERE voided_at IS NULL AND paid_on BETWEEN %s AND %s', $from, $to ) ) as $p ) {
			$months[ substr( $p->paid_on, 0, 7 ) ]['received'] += self::paise( $p->amount );
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT amount, expense_date FROM ' . self::t( 'expenses' ) . ' WHERE deleted_at IS NULL AND expense_date BETWEEN %s AND %s', $from, $to ) ) as $e ) {
			$months[ substr( $e->expense_date, 0, 7 ) ]['expenses'] += self::paise( $e->amount );
		}
		foreach ( $other as $o ) {
			$months[ substr( $o->d, 0, 7 ) ]['other_income'] += self::paise( $o->amount );
		}
		foreach ( $alloc as $a ) {
			$months[ substr( $a->d, 0, 7 ) ]['received']     += self::paise( $a->amount );
			$months[ substr( $a->d, 0, 7 ) ]['other_income'] -= self::paise( $a->amount );
		}
		foreach ( $months as &$row ) {
			$row['income'] = $row['received'] + $row['other_income'];
			foreach ( array( 'invoiced', 'received', 'other_income', 'income', 'expenses' ) as $c ) {
				$row[ $c ] = self::rupees( $row[ $c ] );
			}
		}
		unset( $row );
		$k['net'] = $k['total_income'] - $k['expenses_paid'];
		// Billable expenses of the year not yet on an active invoice (reimbursement still to be claimed).
		$bx = $wpdb->get_results( $wpdb->prepare( 'SELECT id, amount, billable_amount FROM ' . self::t( 'expenses' ) . ' WHERE deleted_at IS NULL AND billable = 1 AND expense_date BETWEEN %s AND %s', $from, $to ) );
		$bm = self::expense_billed_map( array_map( 'intval', wp_list_pluck( $bx, 'id' ) ) );
		foreach ( $bx as $e ) {
			if ( ! isset( $bm[ (int) $e->id ] ) ) {
				$k['billable_unbilled'] += self::paise( null !== $e->billable_amount ? $e->billable_amount : $e->amount );
			}
		}
		$ledger = self::ledger( array( 'fy' => $fy ) );
		$money  = $ledger['rows'];
		foreach ( array( 'invoiced', 'received', 'received_payments', 'received_allocated', 'other_income', 'total_income', 'net', 'outstanding', 'overdue', 'expenses', 'expenses_paid', 'expenses_unpaid', 'invoiced_expenses', 'billable_unbilled' ) as $c ) {
			$k[ $c ] = self::rupees( $k[ $c ] );
		}
		return array(
			'fy'                  => $fy,
			'from'                => $from,
			'to'                  => $to,
			'kpis'                => $k,
			'funds'               => self::available_funds(),
			'months'              => array_values( $months ),
			'recent_invoices'     => array_slice( self::invoices( array( 'fy' => $fy ) ), 0, 6 ),
			'recent_transactions' => array_slice( array_reverse( $money ), 0, 8 ),
			'fy_list'             => self::fy_list(),
			'retention'           => self::retention_status(),
			'missing_details'     => self::missing_business_details(),
		);
	}

	/** Financial years that have any invoice, expense or payment, plus the current one. */
	public static function fy_list() {
		global $wpdb;
		$dates = array_merge(
			$wpdb->get_col( 'SELECT DISTINCT invoice_date FROM ' . self::t( 'invoices' ) ), // phpcs:ignore
			$wpdb->get_col( 'SELECT DISTINCT received_on FROM ' . self::t( 'income' ) . ' WHERE voided_at IS NULL' ), // phpcs:ignore
			$wpdb->get_col( 'SELECT DISTINCT expense_date FROM ' . self::t( 'expenses' ) . ' WHERE deleted_at IS NULL' ), // phpcs:ignore
			array( NDWD_Helpers::today() )
		);
		$out = array_values( array_unique( array_map( array( __CLASS__, 'fy_of' ), $dates ) ) );
		rsort( $out );
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Invoice PDF
	 * ------------------------------------------------------------------ */

	private static function pdf_rel( $v ) {
		return 'invoices/' . $v->fy . '/' . sanitize_file_name( str_replace( '/', '-', $v->invoice_no ) ) . '.pdf';
	}

	public static function pdf_filename( $v ) {
		return 'Invoice-' . str_replace( '/', '-', $v->invoice_no ) . '.pdf';
	}

	/** Writes (or re-writes) the invoice PDF from the stored snapshot. */
	public static function write_pdf( $id ) {
		global $wpdb;
		$v = self::invoice_row( $id );
		if ( ! $v ) {
			return new WP_Error( 'ndwd_not_found', 'Invoice not found.' );
		}
		$rel = self::pdf_rel( $v );
		NDWD_Files::dir( dirname( $rel ) );
		$res = NDWD_Invoice_PDF::render( self::invoice_array( $v, true ), NDWD_Reports::base_dir() . '/' . $rel );
		if ( true === $res && $v->pdf_path !== $rel ) {
			$wpdb->update( self::t( 'invoices' ), array( 'pdf_path' => $rel ), array( 'id' => $id ) );
		}
		return $res;
	}

	/** Path to the invoice PDF, regenerating it from the snapshot if the file went missing. */
	public static function pdf_path( $id ) {
		$v = self::invoice_row( $id );
		if ( ! $v ) {
			return null;
		}
		$path = $v->pdf_path ? NDWD_Files::path( $v->pdf_path ) : null;
		if ( ! $path && true === self::write_pdf( $id ) ) {
			$v    = self::invoice_row( $id );
			$path = NDWD_Files::path( $v->pdf_path );
		}
		return $path;
	}

	public static function stream_invoice_pdf( $id, $public = false ) {
		$v    = self::invoice_row( $id );
		$path = $v ? self::pdf_path( $id ) : null;
		if ( ! $path ) {
			return new WP_Error( 'ndwd_not_found', 'Invoice PDF not found.', array( 'status' => 404 ) );
		}
		NDWD_Files::send_file( $path, self::pdf_filename( $v ), $public );
	}

	/* ------------------------------------------------------------------ *
	 * WhatsApp / email
	 * ------------------------------------------------------------------ */

	private static function fill( $tpl, $v, $client ) {
		return strtr( $tpl, array(
			'{client_name}'    => $client['name'],
			'{invoice_number}' => $v->invoice_no,
			'{invoice_total}'  => self::inr( $v->total ),
			'{due_date}'       => date_i18n( 'j M Y', strtotime( $v->due_date ) ),
			'{balance_due}'    => self::inr( self::rupees( self::paise( $v->total ) - self::paise( $v->amount_paid ) ) ),
		) );
	}

	/** Prefilled WhatsApp and email drafts for an invoice (nothing is sent here). */
	public static function share( $id, $with_link = null ) {
		$v = self::invoice_row( $id );
		if ( ! $v ) {
			return new WP_Error( 'ndwd_not_found', 'Invoice not found.', array( 'status' => 404 ) );
		}
		$s      = self::settings();
		$client = json_decode( (string) $v->client_snapshot, true );
		$with   = null === $with_link ? ! empty( $s['include_pdf_link'] ) : (bool) $with_link;
		$link   = $with ? NDWD_Files::signed_url( 'invoice', $id, 30 ) : '';
		$wa     = self::fill( $s['wa_template'], $v, $client ) . ( $link ? "\n\nInvoice PDF: " . $link : '' );
		$body   = self::fill( $s['email_body'], $v, $client ) . ( $link ? "\n\nInvoice PDF: " . $link : '' );
		$subj   = self::fill( $s['email_subject'], $v, $client );
		$num    = NDWD_Clients::wa_number( isset( $client['whatsapp'] ) ? $client['whatsapp'] : '' );
		$cur    = NDWD_Clients::get( $v->client_id );
		// Recipients saved with the invoice; older invoices (single email) fall back to the
		// client's current list.
		$list = ! empty( $client['emails'] ) && is_array( $client['emails'] ) ? $client['emails'] : ( ! empty( $client['email'] ) ? array( array( 'email' => $client['email'], 'type' => 'to' ) ) : ( $cur ? NDWD_Clients::billing_emails( $cur ) : array() ) );
		list( $to, $cc ) = NDWD_Clients::split_emails( $list );
		$email  = implode( ', ', $to );
		$group  = $cur ? (string) $cur->billing_wa_group : '';
		return array(
			'invoice_no' => $v->invoice_no,
			'pdf_link'   => $link,
			'whatsapp'   => array(
				'number' => $num,
				'text'   => $wa,
				'url'    => $num ? 'https://wa.me/' . $num . '?text=' . rawurlencode( $wa ) : '',
				'missing' => $num ? '' : ( $group ? 'This client has no billing WhatsApp number. Open the billing group below and paste the message there.' : 'This client has no billing WhatsApp number. Add it in Clients → Edit.' ),
				// Opening the group link only opens WhatsApp; nothing is sent and nobody is invited.
				'group_url' => $group,
			),
			'email'      => array(
				'to'      => $email,
				'to_list' => $to,
				'cc_list' => $cc,
				'recipients' => $list,
				'client_recipients' => $cur ? NDWD_Clients::billing_emails( $cur ) : array(),
				'subject' => $subj,
				'body'    => $body,
				'mailto'  => $email ? 'mailto:' . implode( ',', array_map( 'rawurlencode', $to ) ) . '?' . ( $cc ? 'cc=' . implode( ',', array_map( 'rawurlencode', $cc ) ) . '&' : '' ) . 'subject=' . rawurlencode( $subj ) . '&body=' . rawurlencode( $body ) : '',
				'missing' => $email ? '' : 'This client has no billing email address. Add it in Clients → Edit.',
				'can_send' => (bool) $email && ! empty( NDWD_Notifications::settings()['email_enabled'] ),
			),
		);
	}

	/** Sends the invoice email through WorkDesk's mail setup with the PDF attached. */
	/**
	 * Sends the invoice email. $recipients (optional) overrides the To / CC list for this
	 * send only; neither the invoice nor the client's billing details are changed.
	 */
	public static function email_invoice( $id, $with_link, $user_id, $recipients = null ) {
		$share = self::share( $id, $with_link );
		if ( is_wp_error( $share ) ) {
			return $share;
		}
		$to  = $share['email']['to_list'];
		$cc  = $share['email']['cc_list'];
		$bcc = array();
		if ( is_array( $recipients ) ) {
			$list = NDWD_Clients::clean_emails( $recipients, 'Recipient' );
			if ( is_wp_error( $list ) ) {
				return $list;
			}
			list( $to, $cc, $bcc ) = NDWD_Clients::split_emails( $list );
		}
		if ( ! $to ) {
			return new WP_Error( 'ndwd_invalid', $share['email']['missing'] ? $share['email']['missing'] : 'Add at least one "To" recipient.', array( 'status' => 400 ) );
		}
		if ( empty( NDWD_Notifications::settings()['email_enabled'] ) ) {
			return new WP_Error( 'ndwd_invalid', 'Email sending is turned off in Settings. Use "Open email draft" and attach the PDF instead.', array( 'status' => 400 ) );
		}
		$path = self::pdf_path( $id );
		if ( ! $path ) {
			return new WP_Error( 'ndwd_not_found', 'Invoice PDF not found.', array( 'status' => 404 ) );
		}
		// Attach under the readable invoice file name.
		$v    = self::invoice_row( $id );
		$copy = NDWD_Files::dir( 'tmp' ) . '/' . wp_generate_password( 8, false ) . '-' . self::pdf_filename( $v );
		copy( $path, $copy );
		$html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111827;line-height:1.55">' . nl2br( esc_html( $share['email']['body'] ) ) . '</div>';
		$sent = NDWD_Notifications::send_to( $to, $share['email']['subject'], $html, 'invoice', null, array( $copy ), 0, $cc, $bcc );
		@unlink( $copy ); // phpcs:ignore
		if ( true !== $sent ) {
			return new WP_Error( 'ndwd_mail', 'The email was not sent: ' . NDWD_Notifications::last_error(), array( 'status' => 502 ) );
		}
		self::audit( 'invoice', $id, 'invoice_emailed', $user_id, array( 'to' => implode( ', ', $to ), 'cc' => implode( ', ', $cc ), 'bcc' => implode( ', ', $bcc ) ) );
		return array( 'ok' => true, 'message' => 'Invoice ' . $share['invoice_no'] . ' emailed to ' . implode( ', ', $to ) . ( $cc ? ' (CC ' . implode( ', ', $cc ) . ')' : '' ) . ' with the PDF attached.' );
	}

	/** Records that the WhatsApp draft was opened (the actual send happens in WhatsApp). */
	public static function log_share( $id, $channel, $user_id ) {
		self::audit( 'invoice', $id, 'whatsapp' === $channel ? 'whatsapp_opened' : 'email_draft_opened', $user_id );
		return array( 'ok' => true );
	}

	/* ------------------------------------------------------------------ *
	 * Financial-year archive (ZIP) and retention reminders
	 * ------------------------------------------------------------------ */

	public static function retention_status() {
		global $wpdb;
		$s     = self::settings();
		$today = NDWD_Helpers::today();
		$out   = array();
		foreach ( $wpdb->get_results( 'SELECT fy, COUNT(*) n FROM ' . self::t( 'invoices' ) . ' GROUP BY fy ORDER BY fy DESC' ) as $r ) { // phpcs:ignore
			$deadline  = self::fy_deadline( $r->fy );
			$final     = ( new DateTimeImmutable( $deadline ) )->modify( '+' . (int) $s['fy_grace_days'] . ' days' )->format( 'Y-m-d' );
			$confirmed = $wpdb->get_row( $wpdb->prepare( 'SELECT confirmed_at, confirmed_by FROM ' . self::t( 'fy_archives' ) . ' WHERE fy = %s AND confirmed_at IS NOT NULL ORDER BY confirmed_at DESC LIMIT 1', $r->fy ) );
			list( , $fy_end ) = self::fy_range( $r->fy );
			$stage     = $today <= $fy_end ? 'current' : ( $today <= $deadline ? 'extra_month' : ( $today <= $final ? 'grace' : 'eligible' ) );
			$out[]     = array(
				'fy'           => $r->fy,
				'invoices'     => (int) $r->n,
				'deadline'     => $deadline,
				'grace_until'  => $final,
				'stage'        => $stage,
				'days_left'    => (int) ( ( strtotime( $deadline ) - strtotime( $today ) ) / DAY_IN_SECONDS ),
				'confirmed_at' => $confirmed ? $confirmed->confirmed_at : null,
				'confirmed_by' => $confirmed ? NDWD_Helpers::user_name( $confirmed->confirmed_by ) : null,
			);
		}
		return $out;
	}

	public static function archives() {
		global $wpdb;
		return array_map( function ( $a ) {
			return array(
				'id'            => (int) $a->id,
				'fy'            => $a->fy,
				'status'        => $a->status,
				'size'          => (int) $a->size,
				'error'         => (string) $a->error,
				'created_by'    => NDWD_Helpers::user_name( $a->created_by ),
				'created_at'    => $a->created_at,
				'expires_at'    => $a->expires_at,
				'downloaded_at' => $a->downloaded_at,
				'confirmed_at'  => $a->confirmed_at,
				'confirmed_by'  => $a->confirmed_by ? NDWD_Helpers::user_name( $a->confirmed_by ) : null,
				'name'          => 'NeonDigital-FY-' . $a->fy . '-archive.zip',
			);
		}, $wpdb->get_results( 'SELECT * FROM ' . self::t( 'fy_archives' ) . ' ORDER BY id DESC LIMIT 50' ) ); // phpcs:ignore
	}

	/** Builds the ZIP for a financial year. The file expires after the configured hours. */
	public static function build_archive( $fy, $user_id ) {
		global $wpdb;
		if ( ! self::valid_fy( $fy ) ) {
			return new WP_Error( 'ndwd_invalid', 'Choose a financial year like 2026-27.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'ndwd_env', 'The PHP ZipArchive extension is not available on this server, so ZIP files cannot be created. Ask your host to enable it.', array( 'status' => 500 ) );
		}
		@set_time_limit( 300 ); // phpcs:ignore
		$s   = self::settings();
		$now = NDWD_Helpers::now();
		$wpdb->insert( self::t( 'fy_archives' ), array( 'fy' => $fy, 'status' => 'running', 'created_by' => (int) $user_id, 'created_at' => $now->format( 'Y-m-d H:i:s' ) ) );
		$aid = (int) $wpdb->insert_id;
		$rel = 'fy-archives/NeonDigital-FY-' . $fy . '-' . wp_generate_password( 10, false ) . '.zip';
		NDWD_Files::dir( 'fy-archives' );
		$path = NDWD_Reports::base_dir() . '/' . $rel;
		$err  = '';
		try {
			list( $from, $to ) = self::fy_range( $fy );
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				throw new Exception( 'Could not create the ZIP file. Check that wp-content/uploads is writable.' );
			}
			$invoices = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'invoices' ) . ' WHERE fy = %s ORDER BY invoice_date, id', $fy ) );
			$index    = array();
			$pdfs     = 0;
			foreach ( $invoices as $v ) {
				$p = self::pdf_path( $v->id );
				if ( $p ) {
					$zip->addFile( $p, 'invoices/' . self::pdf_filename( $v ) );
					$pdfs++;
				}
				$c       = json_decode( (string) $v->client_snapshot, true );
				$index[] = array( $v->invoice_no, $v->invoice_date, $v->due_date, $c['name'], isset( $c['gstin'] ) ? $c['gstin'] : '', (float) $v->subtotal, (float) $v->discount, (float) $v->tax_amount, (float) $v->total, (float) $v->amount_paid, self::rupees( self::paise( $v->total ) - self::paise( $v->amount_paid ) ), self::INVOICE_STATUSES[ $v->status ], $p ? 'invoices/' . self::pdf_filename( $v ) : 'PDF missing' );
			}
			$tables = self::report_tables( 'all', array( 'fy' => $fy ) );
			$head   = array( 'Invoice no', 'Invoice date', 'Due date', 'Client', 'Client GSTIN', 'Subtotal', 'Discount', 'Tax', 'Total', 'Paid', 'Balance', 'Status', 'PDF file' );
			$tmp    = NDWD_Files::dir( 'tmp' );
			$files  = array(
				'invoice-index.csv'          => array( 'csv', array( array( 'Invoice index', $head, $index ) ) ),
				'invoice-index.xlsx'         => array( 'xlsx', array( array( 'Invoice index', $head, $index ) ) ),
				'passbook-ledger.csv'        => array( 'csv', array( $tables['ledger'] ) ),
				'payment-history.csv'        => array( 'csv', array( $tables['payments'] ) ),
				'income.csv'                 => array( 'csv', array( $tables['income'] ) ),
				'expenses.csv'               => array( 'csv', array( $tables['expenses'] ) ),
				'financial-summary.xlsx'     => array( 'xlsx', array_values( $tables ) ),
			);
			$made = array();
			foreach ( $files as $name => $def ) {
				if ( 'xlsx' === $def[0] && ! NDWD_XLSX_Writer::available() ) {
					continue;
				}
				$f  = $tmp . '/' . wp_generate_password( 8, false ) . '-' . $name;
				$ok = NDWD_Exports::write( $def[0], $f, $def[1], 'NeonDigital FY ' . $fy, $from . ' to ' . $to );
				if ( true !== $ok ) {
					throw new Exception( 'Could not write ' . $name );
				}
				$zip->addFromString( $name, file_get_contents( $f ) ); // phpcs:ignore
				@unlink( $f ); // phpcs:ignore
				$made[] = $name;
			}
			$readme  = "NeonDigital — Financial Year Archive\r\n=====================================\r\n\r\n";
			$readme .= 'Financial year : FY ' . $fy . ' (' . $from . ' to ' . $to . ")\r\n";
			$readme .= 'Exported on    : ' . $now->format( 'd M Y H:i' ) . " IST\r\n";
			$readme .= 'Exported by    : ' . NDWD_Helpers::user_name( $user_id ) . "\r\n";
			$readme .= 'Invoices       : ' . count( $invoices ) . ' (' . $pdfs . " PDF files)\r\n\r\nIncluded files\r\n--------------\r\n";
			$readme .= "invoices/                 One PDF per invoice, named by invoice number.\r\n";
			$desc    = array(
				'invoice-index.csv'      => 'List of all invoices with totals, payments, balance and status.',
				'invoice-index.xlsx'     => 'The same invoice list as an Excel workbook.',
				'passbook-ledger.csv'    => 'Passbook: money actually received and paid (payments, paid expenses, refunds and other transactions) with running balance. Invoices issued are listed in invoices.csv, not here.',
				'payment-history.csv'    => 'Every payment recorded against invoices, including voided payments (marked).',
				'income.csv'             => 'All income received (invoice payments and manual income), including voided entries (marked).',
				'expenses.csv'           => 'Expense records dated in this financial year.',
				'financial-summary.xlsx' => 'All of the above tables in one workbook.',
			);
			foreach ( $made as $m ) {
				$readme .= str_pad( $m, 26 ) . $desc[ $m ] . "\r\n";
			}
			$readme .= "\r\nNote: an invoice being issued is not money received. Only payments and other income entries count as received.\r\n";
			$readme .= 'Keep this archive for as long as the law requires (GST records: at least 72 months from the due date of the annual return).' . "\r\n";
			$zip->addFromString( 'README.txt', $readme );
			if ( ! $zip->close() ) {
				throw new Exception( 'The ZIP file could not be finalised.' );
			}
		} catch ( Throwable $e ) {
			$err = $e->getMessage();
			@unlink( $path ); // phpcs:ignore
		}
		$expires = $now->modify( '+' . (int) $s['archive_expiry_hours'] . ' hours' )->format( 'Y-m-d H:i:s' );
		$wpdb->update( self::t( 'fy_archives' ), array(
			'status'     => $err ? 'failed' : 'ready',
			'error'      => $err ? $err : null,
			'file'       => $err ? '' : $rel,
			'size'       => $err ? 0 : filesize( $path ),
			'expires_at' => $err ? null : $expires,
		), array( 'id' => $aid ) );
		self::audit( 'archive', $aid, $err ? 'archive_failed' : 'archive_created', $user_id, array( 'fy' => $fy, 'error' => $err ) );
		if ( $err ) {
			return new WP_Error( 'ndwd_zip', 'The archive could not be created: ' . $err, array( 'status' => 500 ) );
		}
		foreach ( self::archives() as $a ) {
			if ( $a['id'] === $aid ) {
				return $a;
			}
		}
		return array( 'id' => $aid );
	}

	public static function stream_archive( $id, $user_id ) {
		global $wpdb;
		$a    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'fy_archives' ) . " WHERE id = %d AND status = 'ready'", $id ) );
		$path = $a ? NDWD_Files::path( $a->file ) : null;
		if ( ! $path ) {
			return new WP_Error( 'ndwd_not_found', 'This archive has expired or is no longer available. Generate it again.', array( 'status' => 404 ) );
		}
		$wpdb->update( self::t( 'fy_archives' ), array( 'downloaded_at' => NDWD_Helpers::now_mysql() ), array( 'id' => $id ) );
		self::audit( 'archive', $id, 'archive_downloaded', $user_id, array( 'fy' => $a->fy ) );
		NDWD_Files::send_file( $path, 'NeonDigital-FY-' . $a->fy . '-archive.zip' );
	}

	public static function confirm_archive( $id, $user_id ) {
		global $wpdb;
		$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'fy_archives' ) . " WHERE id = %d AND status IN ('ready','expired')", $id ) );
		if ( ! $a || ! $a->downloaded_at ) {
			return new WP_Error( 'ndwd_invalid', 'Download the archive and check it before confirming.', array( 'status' => 400 ) );
		}
		$wpdb->update( self::t( 'fy_archives' ), array( 'confirmed_at' => NDWD_Helpers::now_mysql(), 'confirmed_by' => (int) $user_id ), array( 'id' => $id ) );
		self::audit( 'archive', $id, 'archive_confirmed', $user_id, array( 'fy' => $a->fy ) );
		return array( 'ok' => true );
	}

	/** Deletes expired archive ZIPs (the archive record and its confirmation stay). */
	public static function clean_expired_archives() {
		global $wpdb;
		$n = 0;
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::t( 'fy_archives' ) . " WHERE status = 'ready' AND expires_at < %s", NDWD_Helpers::now_mysql() ) ) as $a ) {
			$p = NDWD_Files::path( $a->file );
			if ( $p ) {
				@unlink( $p ); // phpcs:ignore
				$n++;
			}
			$wpdb->update( self::t( 'fy_archives' ), array( 'status' => 'expired', 'file' => '' ), array( 'id' => $a->id ) );
		}
		return $n;
	}

	/**
	 * Reminds Admins to download and confirm a financial year's archive: 30 and 7 days
	 * before the retention deadline, on the deadline, and 7 days before the grace period
	 * ends — each only while the year is not confirmed. Records are never deleted.
	 */
	public static function fy_notices() {
		$today = NDWD_Helpers::today();
		$sent  = 0;
		foreach ( self::retention_status() as $r ) {
			if ( $r['confirmed_at'] ) {
				continue;
			}
			$stages = array(
				'30d'   => ( new DateTimeImmutable( $r['deadline'] ) )->modify( '-30 days' )->format( 'Y-m-d' ),
				'7d'    => ( new DateTimeImmutable( $r['deadline'] ) )->modify( '-7 days' )->format( 'Y-m-d' ),
				'due'   => $r['deadline'],
				'grace' => ( new DateTimeImmutable( $r['grace_until'] ) )->modify( '-7 days' )->format( 'Y-m-d' ),
			);
			$due_stage = '';
			foreach ( $stages as $k => $d ) {
				if ( $today >= $d ) {
					$due_stage = $k;
				}
			}
			if ( ! $due_stage || $today > $r['grace_until'] || ! NDWD_Jobs::claim( 'fy_notice', $r['fy'] . ':' . $due_stage ) ) {
				continue;
			}
			$msg = 'FY ' . $r['fy'] . ' invoice archive: ' . $r['invoices'] . ' invoice(s). Retention deadline ' . date_i18n( 'j M Y', strtotime( $r['deadline'] ) ) . ' (grace period until ' . date_i18n( 'j M Y', strtotime( $r['grace_until'] ) ) . '). Download the financial-year archive ZIP and confirm it in Accounts → Financial reports. Records are not deleted automatically.';
			foreach ( array_map( 'intval', get_users( array( 'capability' => 'ndwd_manage_accounts', 'fields' => 'ID' ) ) ) as $uid ) {
				NDWD_Notifications::notify_users( array( $uid ), null, 'fy_archive', mb_substr( $msg, 0, 480 ), 0, 'fy:' . $r['fy'] . ':' . $due_stage . ':' . $uid, '#/accounts/reports' );
			}
			if ( ! empty( NDWD_Notifications::settings()['email_enabled'] ) ) {
				foreach ( NDWD_Reports::report_recipients() as $email ) {
					NDWD_Notifications::send_to( $email, '[WorkDesk] Download the FY ' . $r['fy'] . ' invoice archive', NDWD_Notifications::template( 'Financial-year archive reminder', '<p style="color:#374151">' . esc_html( $msg ) . '</p>', NDWD_App::url() . '#/accounts/reports', 'Open financial reports' ), 'fy_archive', 'fy-mail:' . $r['fy'] . ':' . $due_stage . ':' . md5( $email ) );
				}
			}
			NDWD_Jobs::finish( 'fy_notice', $r['fy'] . ':' . $due_stage, 'done', 'Reminder sent.' );
			$sent++;
		}
		return $sent;
	}

	/* ------------------------------------------------------------------ *
	 * Financial reports
	 * ------------------------------------------------------------------ */

	/** Tables for financial exports: ledger, invoices, payments, expenses, summary. */
	public static function report_tables( $which, $f ) {
		global $wpdb;
		list( $from, $to ) = self::range_from_filters( $f );
		$out = array();
		if ( in_array( $which, array( 'all', 'ledger' ), true ) ) {
			$l    = self::ledger( $f );
			$rows = array( array( '', 'Opening balance', '', '', '', '', '', '', '', '', $l['period_opening'] ) );
			foreach ( $l['rows'] as $r ) {
				$rows[] = array( $r['date'], $r['type_label'], $r['reference'], $r['party'], $r['description'], $r['in'] ? $r['in'] : '', $r['out'] ? $r['out'] : '', $r['method_label'], $r['destination'], implode( ', ', wp_list_pluck( $r['invoices'], 'invoice_no' ) ), null === $r['balance'] ? '' : $r['balance'] );
			}
			$rows[] = array( 'TOTAL', '', '', '', 'Credits − debits = ' . $l['net'], $l['totals']['in'], $l['totals']['out'], '', '', 'Closing balance', $l['period_closing'] );
			$out['ledger'] = array( 'Passbook', array( 'Date', 'Type', 'Reference', 'Client / Payee', 'Description / Remarks', 'Money In (Credit)', 'Money Out (Debit)', 'Payment Method', 'Account / Destination', 'Linked Invoice', 'Running Balance' ), $rows, array( 1, 1.3, 1.3, 1.6, 2.6, 1.1, 1.1, 1, 1.4, 1.2, 1.2 ) );
		}
		if ( in_array( $which, array( 'all', 'invoices' ), true ) ) {
			$rows = array();
			foreach ( self::invoices( $f ) as $v ) {
				if ( $from && ( $v['invoice_date'] < $from || $v['invoice_date'] > $to ) ) {
					continue;
				}
				$rows[] = array( $v['invoice_no'], $v['invoice_date'], $v['due_date'], $v['client_name'], $v['total'], $v['amount_received'], $v['balance'], $v['status_label'] . ( $v['is_overdue'] ? ' (overdue)' : '' ) . ( $v['consolidated_into'] ? ' into ' . $v['consolidated_into']['invoice_no'] : '' ), $v['amount_adjusted'], $v['expense_subtotal'], $v['carried_forward'] );
			}
			$out['invoices'] = array( 'Invoices', array( 'Invoice no', 'Date', 'Due', 'Client', 'Total', 'Paid', 'Balance', 'Status', 'Advance adjusted', 'Reimbursable expenses', 'Brought forward' ), $rows, array( 1.5, 0.9, 0.9, 1.9, 0.9, 0.9, 0.9, 1.3, 0.9, 1, 1 ) );
		}
		if ( in_array( $which, array( 'all', 'payments' ), true ) ) {
			$sql  = 'SELECT p.*, v.invoice_no, v.client_snapshot FROM ' . self::t( 'payments' ) . ' p JOIN ' . self::t( 'invoices' ) . ' v ON v.id = p.invoice_id';
			$rows = $from ? $wpdb->get_results( $wpdb->prepare( $sql . ' WHERE p.paid_on BETWEEN %s AND %s ORDER BY p.paid_on, p.id', $from, $to ) ) : $wpdb->get_results( $sql . ' ORDER BY p.paid_on, p.id' ); // phpcs:ignore
			$out['payments'] = array( 'Payments', array( 'Date', 'Invoice', 'Client', 'Amount', 'Method', 'Reference', 'Notes', 'Status' ), array_map( function ( $p ) {
				$c = json_decode( (string) $p->client_snapshot, true );
				return array( $p->paid_on, $p->invoice_no, $c['name'], (float) $p->amount, isset( self::METHODS[ $p->method ] ) ? self::METHODS[ $p->method ] : $p->method, $p->reference, (string) $p->notes, $p->voided_at ? 'VOIDED: ' . $p->void_reason : 'Recorded' );
			}, $rows ), array( 1, 1.6, 2, 1, 1, 1.4, 2, 1.4 ) );
		}
		if ( in_array( $which, array( 'all', 'income' ), true ) ) {
			// The on-screen filters (search, client, category, method, kind, state) apply as listed;
			// the financial report keeps its historical "all entries incl. voided" default.
			$inc  = self::incomes( 'income' === $which && ( isset( $f['state'] ) || isset( $f['q'] ) ) ? $f : array_merge( array( 'state' => 'all' ), $f ) );
			$out['income'] = array( 'Income', array( 'Date', 'Income Category', 'Description', 'Client', 'Project', 'Invoice', 'Amount', 'Method', 'Reference', 'Remarks', 'Status', 'Adjusted against invoices' ), array_map( function ( $x ) {
				$adj = $x['allocations'] ? implode( ', ', array_map( function ( $a ) {
					return ( $a['invoice'] ? $a['invoice']['invoice_no'] : '' ) . ' ' . self::inr( $a['amount'] );
				}, $x['allocations'] ) ) : '';
				return array( $x['received_on'], $x['source'], $x['description'], $x['client_name'], $x['project_name'], $x['invoice'] ? $x['invoice']['invoice_no'] : '', $x['amount'], $x['method_label'], $x['reference'], $x['remarks'], $x['voided_at'] ? 'VOIDED: ' . $x['void_reason'] : ( $x['payment_id'] ? 'Invoice payment' : 'Recorded' ), $adj );
			}, $inc['items'] ), array( 1, 1.3, 2, 1.5, 1.3, 1.3, 1, 1, 1.2, 1.6, 1.2, 1.6 ) );
			$out['income_sources'] = array( 'Income by category', array( 'Income Category', 'Total' ), array_map( function ( $x ) {
				return array( $x['source'], $x['total'] );
			}, $inc['by_source'] ), array( 3, 1 ) );
		}
		if ( in_array( $which, array( 'all', 'expenses' ), true ) ) {
			$out['expenses'] = array( 'Expenses', array( 'Date', 'Title', 'Category', 'Vendor / payee', 'Amount', 'Status', 'Paid on', 'Method', 'Reference', 'Notes', 'Billable to client', 'Billed in' ), array_map( function ( $e ) {
				return array( $e['expense_date'], $e['title'], $e['category'], $e['vendor'], $e['amount'], 'paid' === $e['status'] ? 'Paid' : 'Unpaid', (string) $e['paid_on'], $e['method_label'], $e['reference'], $e['notes'], $e['billable'] ? $e['billable_amount'] : '', implode( ', ', wp_list_pluck( $e['billed_in'], 'invoice_no' ) ) );
			}, self::expenses( $f ) ), array( 1, 2.2, 1.4, 1.4, 0.9, 0.8, 0.9, 0.9, 1.1, 1.6, 1, 1.1 ) );
			$cat = array();
			foreach ( self::expenses( $f ) as $e ) {
				$cat[ $e['category'] ] = ( isset( $cat[ $e['category'] ] ) ? $cat[ $e['category'] ] : 0 ) + self::paise( $e['amount'] );
			}
			arsort( $cat );
			$out['categories'] = array( 'Expenses by category', array( 'Category', 'Total' ), array_map( function ( $k, $v ) {
				return array( $k, self::rupees( $v ) );
			}, array_keys( $cat ), array_values( $cat ) ), array( 3, 1 ) );
		}
		if ( in_array( $which, array( 'all', 'summary' ), true ) && ! empty( $f['fy'] ) ) {
			$o = self::overview( $f['fy'] );
			$k = $o['kpis'];
			$out['summary'] = array( 'Summary', array( 'Metric', 'Amount (INR)' ), array(
				array( 'Total invoiced (FY ' . $o['fy'] . ')', $k['invoiced'] ),
				array( 'Total received from invoices (payments dated in the year + advances of the year adjusted on invoices)', $k['received'] ),
				array( '  of which invoice payments', $k['received_payments'] ),
				array( '  of which advances adjusted on invoices', $k['received_allocated'] ),
				array( 'Other income (manual income not adjusted on invoices)', $k['other_income'] ),
				array( 'Total income received', $k['total_income'] ),
				array( 'Outstanding (' . $k['outstanding_count'] . ' invoices)', $k['outstanding'] ),
				array( 'Overdue (' . $k['overdue_count'] . ' invoices)', $k['overdue'] ),
				array( 'Expenses (all)', $k['expenses'] ),
				array( 'Expenses paid', $k['expenses_paid'] ),
				array( 'Expenses unpaid', $k['expenses_unpaid'] ),
				array( 'Net (total income − expenses paid)', $k['net'] ),
				array( 'Current available funds (passbook balance on ' . $o['funds']['as_of'] . ')', $o['funds']['balance'] ),
			), array( 3, 1 ) );
			$out['monthly'] = array( 'Monthly summary', array( 'Month', 'Invoiced', 'Received (invoices)', 'Other income', 'Total income', 'Expenses' ), array_map( function ( $m ) {
				return array( $m['label'], $m['invoiced'], $m['received'], $m['other_income'], $m['income'], $m['expenses'] );
			}, $o['months'] ), array( 1.5, 1, 1, 1, 1, 1 ) );
		}
		return $out;
	}

	const REPORTS = array(
		'summary'  => 'Financial summary & monthly income/expenses',
		'ledger'   => 'Passbook / transaction ledger',
		'invoices' => 'Invoices',
		'payments' => 'Payments received',
		'income'   => 'Income received (all sources)',
		'expenses' => 'Expenses (with category totals)',
	);

	public static function export( $in ) {
		$which  = isset( $in['report'] ) ? sanitize_key( $in['report'] ) : '';
		$format = isset( $in['format'] ) ? sanitize_key( $in['format'] ) : '';
		if ( ! isset( self::REPORTS[ $which ] ) || ! in_array( $format, NDWD_Exports::FORMATS, true ) ) {
			return new WP_Error( 'ndwd_invalid', 'Choose a report and a format.', array( 'status' => 400 ) );
		}
		$f = array();
		foreach ( array( 'fy', 'from', 'to', 'month', 'type', 'method', 'party', 'client_id', 'project_id', 'source', 'kind', 'category', 'status', 'q', 'state' ) as $k ) {
			if ( isset( $in[ $k ] ) && '' !== $in[ $k ] ) {
				$f[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}
		if ( ! empty( $in['voided'] ) && empty( $f['state'] ) ) {
			$f['state'] = 'all';
		}
		if ( 'summary' === $which && empty( $f['fy'] ) ) {
			$f['fy'] = self::fy_of( NDWD_Helpers::today() );
		}
		if ( 'xlsx' === $format && ! NDWD_XLSX_Writer::available() ) {
			return new WP_Error( 'ndwd_env', 'Excel export needs the PHP ZipArchive extension. Choose CSV or PDF.', array( 'status' => 500 ) );
		}
		list( $from, $to ) = self::range_from_filters( $f );
		$sub  = $from ? ( ! empty( $f['fy'] ) && empty( $f['month'] ) && empty( $f['from'] ) ? 'FY ' . $f['fy'] . ' (' . $from . ' to ' . $to . ')' : $from . ' to ' . $to ) : 'All dates';
		$name = 'neondigital-' . $which . '-' . ( ! empty( $f['fy'] ) ? 'FY' . $f['fy'] : ( $from ? $from . '-to-' . $to : 'all' ) ) . '.' . $format;
		if ( 'ledger' === $which && 'pdf' === $format ) {
			return NDWD_Files::send_temp( $name, function ( $path ) use ( $f, $sub ) {
				return self::passbook_pdf( $f, $path, $sub );
			} );
		}
		$tables = array_values( self::report_tables( $which, $f ) );
		return NDWD_Files::send_temp( $name, function ( $path ) use ( $format, $tables, $which, $sub ) {
			return NDWD_Exports::write( $format, $path, $tables, self::REPORTS[ $which ], $sub . ' · Amounts in INR' );
		} );
	}
}
