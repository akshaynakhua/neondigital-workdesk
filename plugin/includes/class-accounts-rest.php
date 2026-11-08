<?php
/**
 * REST routes for Accounts (ndwd/v1/accounts/...). Every route requires the
 * ndwd_manage_accounts capability; members and team managers get 403.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Accounts_REST {

	public static function allowed() {
		return is_user_logged_in() && NDWD_Permissions::can( 'ndwd_manage_accounts' );
	}

	/** Deleting needs Accounts access plus the separate "Delete records" capability. */
	public static function can_delete() {
		return self::allowed() && NDWD_Permissions::can( 'ndwd_delete_records' );
	}

	private static function r( $path, $methods, $cb, $perm = 'allowed' ) {
		register_rest_route( NDWD_REST::NS, '/accounts' . $path, array(
			'methods'             => $methods,
			'callback'            => array( __CLASS__, $cb ),
			'permission_callback' => array( __CLASS__, $perm ),
		) );
	}

	public static function routes() {
		self::r( '/overview', 'GET', 'overview' );
		self::r( '/settings', 'GET', 'get_settings' );
		self::r( '/settings', 'PUT', 'save_settings' );
		self::r( '/settings/qr', 'GET', 'qr' );
		self::r( '/settings/qr', 'POST', 'save_qr' );
		self::r( '/settings/qr', 'DELETE', 'remove_qr' );
		self::r( '/settings/logo', 'GET', 'logo' );
		self::r( '/settings/logo', 'POST', 'save_logo' );
		self::r( '/settings/logo', 'DELETE', 'remove_logo' );
		self::r( '/payment-accounts', 'GET', 'payment_accounts' );
		self::r( '/payment-accounts', 'POST', 'save_payment_account' );
		self::r( '/payment-accounts/(?P<id>\d+)', 'PUT', 'save_payment_account' );
		self::r( '/packages', 'GET', 'packages' );
		self::r( '/packages', 'POST', 'save_package' );
		self::r( '/packages/(?P<id>\d+)', 'PUT', 'save_package' );
		self::r( '/packages/(?P<id>\d+)', 'DELETE', 'delete_package', 'can_delete' );
		self::r( '/clients/(?P<id>\d+)/billable', 'GET', 'billable' );
		self::r( '/clients/(?P<id>\d+)/available-income', 'GET', 'available_income' );
		self::r( '/clients/(?P<id>\d+)/billable-expenses', 'GET', 'billable_expenses' );
		self::r( '/clients/(?P<id>\d+)/outstanding', 'GET', 'outstanding' );
		self::r( '/clients/(?P<id>\d+)/receive-payment', 'POST', 'receive_payment' );
		self::r( '/invoices', 'GET', 'invoices' );
		self::r( '/invoices', 'POST', 'create_invoice' );
		self::r( '/invoices/preview', 'POST', 'preview' );
		self::r( '/invoices/(?P<id>\d+)', 'GET', 'invoice' );
		self::r( '/invoices/(?P<id>\d+)', 'PUT', 'update_invoice' );
		self::r( '/invoices/(?P<id>\d+)/cancel', 'POST', 'cancel_invoice' );
		self::r( '/invoices/(?P<id>\d+)/replace', 'POST', 'replace_invoice' );
		self::r( '/invoices/(?P<id>\d+)/pdf', 'GET', 'invoice_pdf' );
		self::r( '/invoices/(?P<id>\d+)/share', 'GET', 'share' );
		self::r( '/invoices/(?P<id>\d+)/share-log', 'POST', 'share_log' );
		self::r( '/invoices/(?P<id>\d+)/email', 'POST', 'email' );
		self::r( '/invoices/(?P<id>\d+)/payments', 'POST', 'add_payment' );
		self::r( '/payments/(?P<id>\d+)/void', 'POST', 'void_payment' );
		self::r( '/payments/(?P<id>\d+)/transfer', 'POST', 'transfer_payment' );
		self::r( '/income', 'GET', 'incomes' );
		self::r( '/income', 'POST', 'save_income' );
		self::r( '/income/(?P<id>\d+)', 'PUT', 'save_income' );
		self::r( '/income/(?P<id>\d+)/void', 'POST', 'void_income' );
		self::r( '/income/(?P<id>\d+)/allocate', 'POST', 'allocate_income' );
		self::r( '/expenses', 'GET', 'expenses' );
		self::r( '/expenses', 'POST', 'save_expense' );
		self::r( '/expenses/(?P<id>\d+)', 'PUT', 'save_expense' );
		self::r( '/expenses/(?P<id>\d+)', 'DELETE', 'delete_expense' );
		self::r( '/expenses/(?P<id>\d+)/receipt', 'GET', 'receipt' );
		self::r( '/entries', 'POST', 'save_entry' );
		self::r( '/entries/(?P<id>\d+)/void', 'POST', 'void_entry' );
		self::r( '/ledger', 'GET', 'ledger' );
		self::r( '/export', 'POST', 'export' );
		self::r( '/archives', 'GET', 'archives' );
		self::r( '/archives', 'POST', 'build_archive' );
		self::r( '/archives/(?P<id>\d+)/download', 'GET', 'download_archive' );
		self::r( '/archives/(?P<id>\d+)/confirm', 'POST', 'confirm_archive' );
	}

	private static function uid() {
		return get_current_user_id();
	}

	private static function body( WP_REST_Request $r ) {
		$b = $r->get_json_params();
		return is_array( $b ) ? $b : $r->get_body_params();
	}

	private static function out( $d ) {
		return is_wp_error( $d ) ? $d : rest_ensure_response( $d );
	}

	private static function filters( WP_REST_Request $r ) {
		$f = array();
		foreach ( array( 'fy', 'from', 'to', 'month', 'type', 'method', 'party', 'client_id', 'project_id', 'source', 'kind', 'status', 'category', 'q', 'state', 'billable' ) as $k ) {
			$v = $r->get_param( $k );
			if ( null !== $v && '' !== $v ) {
				$f[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		return $f;
	}

	/** After a successful save, keeps a typed "Other" account when "Save for future use" was ticked. */
	private static function remember( $res, $b ) {
		if ( ! is_wp_error( $res ) && ! empty( $b['save_account'] ) && ! empty( $b['destination'] ) ) {
			NDWD_Accounts::remember_account( $b['destination'], isset( $b['method'] ) ? (string) $b['method'] : '', self::uid() );
		}
		return $res;
	}

	public static function payment_accounts() {
		return self::out( NDWD_Accounts::payment_accounts() );
	}

	public static function save_payment_account( WP_REST_Request $r ) {
		$res = NDWD_Accounts::save_payment_account( self::body( $r ), self::uid(), isset( $r['id'] ) ? (int) $r['id'] : 0 );
		return self::out( is_wp_error( $res ) ? $res : NDWD_Accounts::payment_accounts() );
	}

	public static function overview( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::overview( (string) $r->get_param( 'fy' ) ) );
	}

	public static function get_settings() {
		return self::out( array(
			'settings'   => NDWD_Accounts::settings(),
			'methods'    => NDWD_Accounts::METHODS,
			'categories' => NDWD_Accounts::categories(),
			'periods'    => NDWD_Accounts::PERIODS,
			'entry_types' => array_map( function ( $t ) {
				return $t[0];
			}, NDWD_Accounts::ENTRY_TYPES ),
			// New income is recorded under Income; "Other Money Received" stays readable for old entries.
			'entry_types_new' => array_map( function ( $t ) {
				return $t[0];
			}, array_diff_key( NDWD_Accounts::ENTRY_TYPES, array( 'other_income' => 1 ) ) ),
			'income_sources' => NDWD_Accounts::income_sources(),
			'income_builtin' => NDWD_Accounts::INCOME_SOURCES,
			'package_types'  => NDWD_Packages::TYPES,
			'package_scopes' => NDWD_Packages::SCOPES,
			'destinations' => NDWD_Accounts::destinations(),
			'payment_accounts' => NDWD_Accounts::payment_accounts(),
			'account_types'    => NDWD_Accounts::ACCOUNT_TYPES,
			'method_account_types' => NDWD_Accounts::METHOD_ACCOUNT_TYPES,
			'can_delete'  => NDWD_Permissions::can( 'ndwd_delete_records' ),
			'reports'    => NDWD_Accounts::REPORTS,
			'statuses'   => NDWD_Accounts::INVOICE_STATUSES,
			'fy_list'    => NDWD_Accounts::fy_list(),
			'current_fy' => NDWD_Accounts::fy_of( NDWD_Helpers::today() ),
			'zip'        => class_exists( 'ZipArchive' ),
			'email_enabled' => ! empty( NDWD_Notifications::settings()['email_enabled'] ),
		) );
	}

	public static function save_settings( WP_REST_Request $r ) {
		$res = NDWD_Accounts::save_settings( self::body( $r ), self::uid() );
		return is_wp_error( $res ) ? $res : self::get_settings();
	}

	public static function logo() {
		return self::out( NDWD_Accounts::stream_logo() );
	}

	public static function save_logo( WP_REST_Request $r ) {
		$res = NDWD_Accounts::save_logo( self::body( $r ), self::uid() );
		return is_wp_error( $res ) ? $res : self::get_settings();
	}

	public static function remove_logo() {
		NDWD_Accounts::remove_logo( self::uid() );
		return self::get_settings();
	}

	public static function qr() {
		return self::out( NDWD_Accounts::stream_qr() );
	}

	public static function save_qr( WP_REST_Request $r ) {
		$res = NDWD_Accounts::save_qr( self::body( $r ), self::uid() );
		return is_wp_error( $res ) ? $res : self::get_settings();
	}

	public static function remove_qr() {
		NDWD_Accounts::remove_qr( self::uid() );
		return self::get_settings();
	}

	public static function delete_package( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::delete_package( (int) $r['id'], self::uid(), (bool) $r->get_param( 'dry' ) ) );
	}

	public static function packages() {
		return self::out( NDWD_Accounts::packages() );
	}

	public static function save_package( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::save_package( self::body( $r ), self::uid(), isset( $r['id'] ) ? (int) $r['id'] : 0 ) );
	}

	public static function billable( WP_REST_Request $r ) {
		$from = NDWD_Helpers::sanitize_date( (string) $r->get_param( 'from' ) );
		$to   = NDWD_Helpers::sanitize_date( (string) $r->get_param( 'to' ) );
		return self::out( NDWD_Accounts::billable( (int) $r['id'], $from, $to, absint( $r->get_param( 'exclude_invoice' ) ) ) );
	}

	public static function available_income( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::available_income( (int) $r['id'], absint( $r->get_param( 'project_id' ) ), absint( $r->get_param( 'exclude_invoice' ) ) ) );
	}

	public static function billable_expenses( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::billable_expenses( (int) $r['id'], absint( $r->get_param( 'project_id' ) ), absint( $r->get_param( 'exclude_invoice' ) ), (bool) $r->get_param( 'all' ) ) );
	}

	public static function outstanding( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::outstanding_invoices( (int) $r['id'], absint( $r->get_param( 'exclude_invoice' ) ) ) );
	}

	public static function receive_payment( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( self::remember( NDWD_Accounts::receive_payment( (int) $r['id'], $b, self::uid() ), $b ) );
	}

	public static function allocate_income( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Accounts::allocate_income( (int) $r['id'], isset( $b['allocations'] ) ? $b['allocations'] : array(), self::uid() ) );
	}

	public static function invoices( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::invoices( self::filters( $r ) ) );
	}

	public static function preview( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::preview( self::body( $r ), self::uid() ) );
	}

	public static function create_invoice( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::create_invoice( self::body( $r ), self::uid() ) );
	}

	public static function invoice( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::invoice( (int) $r['id'] ) );
	}

	public static function update_invoice( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::update_invoice( (int) $r['id'], self::body( $r ), self::uid() ) );
	}

	public static function cancel_invoice( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Accounts::cancel_invoice( (int) $r['id'], isset( $b['reason'] ) ? $b['reason'] : '', self::uid(), ! empty( $b['acknowledge_payments'] ) ) );
	}

	public static function replace_invoice( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::replace_invoice( (int) $r['id'], self::body( $r ), self::uid() ) );
	}

	public static function transfer_payment( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::transfer_payment( (int) $r['id'], self::uid() ) );
	}

	public static function invoice_pdf( WP_REST_Request $r ) {
		return NDWD_Accounts::stream_invoice_pdf( (int) $r['id'] );
	}

	public static function share( WP_REST_Request $r ) {
		$l = $r->get_param( 'link' );
		return self::out( NDWD_Accounts::share( (int) $r['id'], null === $l ? null : (bool) (int) $l ) );
	}

	public static function share_log( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Accounts::log_share( (int) $r['id'], isset( $b['channel'] ) ? sanitize_key( $b['channel'] ) : '', self::uid() ) );
	}

	public static function email( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Accounts::email_invoice( (int) $r['id'], ! empty( $b['link'] ), self::uid(), isset( $b['recipients'] ) && is_array( $b['recipients'] ) ? $b['recipients'] : null ) );
	}

	public static function incomes( WP_REST_Request $r ) {
		$f = self::filters( $r );
		if ( $r->get_param( 'voided' ) ) {
			$f['voided'] = 1;
		}
		return self::out( NDWD_Accounts::incomes( $f ) );
	}

	public static function save_income( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( self::remember( NDWD_Accounts::save_income( $b, self::uid(), isset( $r['id'] ) ? (int) $r['id'] : 0 ), $b ) );
	}

	public static function void_income( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Accounts::void_income( (int) $r['id'], isset( $b['reason'] ) ? $b['reason'] : '', self::uid() ) );
	}

	public static function add_payment( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( self::remember( NDWD_Accounts::add_payment( (int) $r['id'], $b, self::uid() ), $b ) );
	}

	public static function void_payment( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Accounts::void_payment( (int) $r['id'], isset( $b['reason'] ) ? $b['reason'] : '', self::uid() ) );
	}

	public static function expenses( WP_REST_Request $r ) {
		$f    = self::filters( $r );
		$list = NDWD_Accounts::expenses( $f );
		$cat  = array();
		$mon  = array();
		$tot  = 0;
		foreach ( $list as $e ) {
			$p                       = NDWD_Accounts::paise( $e['amount'] );
			$tot                    += $p;
			$cat[ $e['category'] ]   = ( isset( $cat[ $e['category'] ] ) ? $cat[ $e['category'] ] : 0 ) + $p;
			$mk                      = substr( $e['expense_date'], 0, 7 );
			$mon[ $mk ]              = ( isset( $mon[ $mk ] ) ? $mon[ $mk ] : 0 ) + $p;
		}
		arsort( $cat );
		krsort( $mon );
		return self::out( array(
			'items'       => $list,
			'total'       => NDWD_Accounts::rupees( $tot ),
			'by_category' => array_map( function ( $k, $v ) {
				return array( 'category' => $k, 'total' => NDWD_Accounts::rupees( $v ) );
			}, array_keys( $cat ), array_values( $cat ) ),
			'by_month'    => array_map( function ( $k, $v ) {
				return array( 'month' => $k, 'total' => NDWD_Accounts::rupees( $v ) );
			}, array_keys( $mon ), array_values( $mon ) ),
		) );
	}

	public static function save_expense( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( self::remember( NDWD_Accounts::save_expense( $b, self::uid(), isset( $r['id'] ) ? (int) $r['id'] : 0 ), $b ) );
	}

	public static function delete_expense( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::delete_expense( (int) $r['id'], self::uid() ) );
	}

	public static function receipt( WP_REST_Request $r ) {
		return NDWD_Accounts::stream_receipt( (int) $r['id'] );
	}

	public static function save_entry( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( self::remember( NDWD_Accounts::save_entry( $b, self::uid() ), $b ) );
	}

	public static function void_entry( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::void_entry( (int) $r['id'], self::uid() ) );
	}

	public static function ledger( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::ledger( self::filters( $r ) ) );
	}

	public static function export( WP_REST_Request $r ) {
		return NDWD_Accounts::export( self::body( $r ) );
	}

	public static function archives() {
		return self::out( array( 'archives' => NDWD_Accounts::archives(), 'retention' => NDWD_Accounts::retention_status(), 'fy_list' => NDWD_Accounts::fy_list() ) );
	}

	public static function build_archive( WP_REST_Request $r ) {
		$b = self::body( $r );
		return self::out( NDWD_Accounts::build_archive( isset( $b['fy'] ) ? sanitize_text_field( $b['fy'] ) : '', self::uid() ) );
	}

	public static function download_archive( WP_REST_Request $r ) {
		return NDWD_Accounts::stream_archive( (int) $r['id'], self::uid() );
	}

	public static function confirm_archive( WP_REST_Request $r ) {
		return self::out( NDWD_Accounts::confirm_archive( (int) $r['id'], self::uid() ) );
	}
}
