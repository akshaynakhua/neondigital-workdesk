<?php
/**
 * Invoice PDF (A4 portrait) drawn with the dependency-free PDF writer.
 *
 * Shows only details that exist in the invoice snapshot: business details come from
 * Accounts → Settings (nothing is invented), client details from the client's billing
 * fields at issue time. The base-14 PDF fonts have no "₹" glyph, so amounts are printed
 * as "Rs." with Indian digit grouping. Long names, addresses and line items wrap, and
 * every block moves to a new page instead of running into the footer.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_Invoice_PDF {

	const BOTTOM = 772; // Lowest y for content; the footer sits below it.

	private static function money( $v ) {
		return 'Rs. ' . NDWD_Accounts::inr( $v );
	}

	private static function date( $d ) {
		return $d ? date_i18n( 'j M Y', strtotime( $d ) ) : '';
	}

	private static function lines( $text ) {
		$out = array();
		foreach ( preg_split( "/\r\n|\n|\r/", (string) $text ) as $l ) {
			if ( '' !== trim( $l ) ) {
				$out[] = trim( $l );
			}
		}
		return $out;
	}

	/** "1 Nov 2026 – 30 Nov 2026" in a package's line; the PDF fonts print the dash as "to". */
	private static function period( $it ) {
		if ( empty( $it['period_start'] ) ) {
			return '';
		}
		return 'Service period: ' . self::date( $it['period_start'] ) . ( ! empty( $it['period_end'] ) && '9999-12-31' !== $it['period_end'] ? ' to ' . self::date( $it['period_end'] ) : ' onwards' );
	}

	/**
	 * Lines in printing order. Packages first (service summary), then each Work Log group under its
	 * heading, then everything else. Older invoices without groups print as they always did.
	 *
	 * @return array[] Sections: {title, remarks, items}.
	 */
	private static function sections( $inv ) {
		$groups = isset( $inv['groups'] ) && is_array( $inv['groups'] ) ? $inv['groups'] : array();
		if ( ! $groups ) {
			return array( array( 'title' => '', 'remarks' => '', 'items' => $inv['items'] ) );
		}
		$pack  = array();
		$by    = array();
		$other = array();
		foreach ( $inv['items'] as $it ) {
			$no = isset( $it['group_no'] ) ? (int) $it['group_no'] : 0;
			if ( 'package' === $it['treatment'] ) {
				$pack[] = $it;
			} elseif ( $no && isset( $groups[ $no - 1 ] ) ) {
				$by[ $no ][] = $it;
			} else {
				$other[] = $it;
			}
		}
		$out = array();
		if ( $pack ) {
			$out[] = array( 'title' => 'Services', 'remarks' => '', 'items' => $pack );
		}
		foreach ( $groups as $i => $g ) {
			if ( ! empty( $by[ $i + 1 ] ) ) {
				$out[] = array( 'title' => $g['heading'], 'remarks' => isset( $g['remarks'] ) ? $g['remarks'] : '', 'items' => $by[ $i + 1 ] );
			}
		}
		if ( $other ) {
			$out[] = array( 'title' => 'Other items', 'remarks' => '', 'items' => $other );
		}
		return $out;
	}

	/** @return true|WP_Error */
	public static function render( $inv, $path ) {
		$b   = is_array( $inv['business'] ) ? $inv['business'] : array();
		$c   = is_array( $inv['client'] ) ? $inv['client'] : array();
		$pdf = new NDWD_PDF_Writer( 'Invoice ' . $inv['invoice_no'], false, false );
		$m   = $pdf->margin();
		$w   = $pdf->page_width();
		$r   = $w - $m;
		$cancelled = 'cancelled' === $inv['status'];

		$band = function () use ( $pdf, $w ) {
			$pdf->set_fill( 17, 18, 20 );
			$pdf->rect( 0, 0, $w, 6, true );
			$pdf->set_fill( 45, 212, 168 );
			$pdf->rect( 0, 6, $w, 2, true );
		};
		$footer = function () use ( $pdf, $m, $b ) {
			$pdf->font( false, 7.5 );
			$pdf->color( 156, 163, 175 );
			$pdf->text( $m, 800, 'This is a computer-generated invoice.' . ( ! empty( $b['business_name'] ) ? ' Thank you for your business with ' . $b['business_name'] . '.' : '' ) );
		};
		// Starts a new page when $h more points do not fit; returns the y to continue at.
		$need = function ( $y, $h ) use ( $pdf, $band, $footer, $m, $r, $inv ) {
			if ( $y + $h <= self::BOTTOM ) {
				return $y;
			}
			$footer();
			$pdf->add_page();
			$band();
			$pdf->font( false, 8 );
			$pdf->color( 107, 114, 128 );
			$pdf->text_right( $r, 26, 'Invoice ' . $inv['invoice_no'] . ' (continued)' );
			return 44;
		};
		$band();

		// Business details (left).
		$y = 46;
		$logo = ! empty( $b['business_logo'] ) ? NDWD_Files::path( $b['business_logo'] ) : null;
		if ( $logo && is_readable( $logo ) && ( $ls = @getimagesize( $logo ) ) ) { // phpcs:ignore
			$lh = 42;
			$lw = min( 170, $lh * $ls[0] / max( 1, $ls[1] ) );
			$lh = $lw * $ls[1] / max( 1, $ls[0] );
			if ( $pdf->image_jpeg( $logo, $m, $y - 16, $lw, $lh ) ) {
				$y += (int) $lh + 2;
			}
		}
		$pdf->font( true, 20 );
		$pdf->color( 17, 24, 39 );
		$ly = $y;
		foreach ( $pdf->wrap( ! empty( $b['business_name'] ) ? $b['business_name'] : 'NeonDigital', 300 ) as $i => $bn ) {
			$pdf->text( $m, $ly, $bn );
			$ly += 22;
		}
		$ly   -= 8;
		$info  = self::lines( isset( $b['address'] ) ? $b['address'] : '' );
		$contact = array_filter( array( isset( $b['phone'] ) ? $b['phone'] : '', isset( $b['email'] ) ? $b['email'] : '', isset( $b['website'] ) ? $b['website'] : '' ) );
		if ( $contact ) {
			$info[] = implode( '  ·  ', $contact );
		}
		if ( ! empty( $b['gstin'] ) ) {
			$info[] = 'GSTIN: ' . $b['gstin'];
		}
		$pdf->font( false, 8.5 );
		$pdf->color( 75, 85, 99 );
		foreach ( $info as $l ) {
			foreach ( $pdf->wrap( $l, 290 ) as $wl ) {
				$pdf->text( $m, $ly, $wl );
				$ly += 11;
			}
		}

		// Title and invoice meta (right).
		$y = 46;
		$pdf->font( true, 18 );
		$pdf->color( 31, 143, 134 );
		$pdf->text_right( $r, $y, ! empty( $b['gstin'] ) ? 'TAX INVOICE' : 'INVOICE' );
		$meta = array(
			array( 'Invoice no.', $inv['invoice_no'] ),
			array( 'Invoice date', self::date( $inv['invoice_date'] ) ),
			array( 'Due date', self::date( $inv['due_date'] ) ),
			array( 'Financial year', 'FY ' . $inv['fy'] ),
			array( 'Status', $inv['status_label'] . ( $inv['is_overdue'] ? ' (overdue)' : '' ) ),
		);
		if ( ! empty( $inv['replaces'] ) ) {
			$meta[] = array( 'Replaces', $inv['replaces']['invoice_no'] );
		}
		$my = $y + 18;
		foreach ( $meta as $row ) {
			$pdf->font( false, 8.5 );
			$pdf->color( 107, 114, 128 );
			$pdf->text( $r - 200, $my, $row[0] );
			$pdf->font( true, 9 );
			$pdf->color( 'Status' === $row[0] && $cancelled ? 220 : 17, 'Status' === $row[0] && $cancelled ? 38 : 24, 'Status' === $row[0] && $cancelled ? 38 : 39 );
			$pdf->text_right( $r, $my, $row[1] );
			$my += 13;
		}
		$y = max( $ly, $my ) + 10;

		// Cancellation notice.
		if ( $cancelled ) {
			$note = 'Cancelled on ' . self::date( substr( (string) $inv['cancelled_at'], 0, 10 ) ) . ( ! empty( $inv['replaced_by'] ) ? ' — replaced by ' . $inv['replaced_by']['invoice_no'] : '' ) . '. Reason: ' . $inv['cancel_reason'];
			$pdf->font( false, 8.5 );
			$nl = array_slice( $pdf->wrap( $note, $r - $m - 20 ), 0, 4 );
			$nh = 22 + 11 * count( $nl );
			$pdf->set_fill( 254, 242, 242 );
			$pdf->rect( $m, $y, $r - $m, $nh, true );
			$pdf->font( true, 10 );
			$pdf->color( 220, 38, 38 );
			$pdf->text( $m + 10, $y + 14, 'CANCELLED — NOT PAYABLE' );
			$pdf->font( false, 8.5 );
			$ny = $y + 26;
			foreach ( $nl as $l ) {
				$pdf->text( $m + 10, $ny, $l );
				$ny += 11;
			}
			$y += $nh + 10;
		}

		// Bill to.
		$bw   = $r - $m - 20;
		$pdf->font( true, 11 );
		$name = $pdf->wrap( isset( $c['name'] ) ? $c['name'] : '', $bw );
		$bill = array();
		if ( ! empty( $c['contact'] ) ) {
			$bill[] = 'Attn: ' . $c['contact'];
		}
		$bill = array_merge( $bill, self::lines( isset( $c['address'] ) ? $c['address'] : '' ) );
		$cc   = array_filter( array( ! empty( $c['whatsapp'] ) ? 'WhatsApp: ' . $c['whatsapp'] : '', ! empty( $c['email'] ) ? 'Email: ' . $c['email'] : '' ) );
		if ( $cc ) {
			$bill[] = implode( '  ·  ', $cc );
		}
		if ( ! empty( $c['gstin'] ) ) {
			$bill[] = 'GSTIN: ' . $c['gstin'];
		}
		$pdf->font( false, 8.5 );
		$wrapped = array();
		foreach ( $bill as $l ) {
			$wrapped = array_merge( $wrapped, $pdf->wrap( $l, $bw ) );
		}
		$bh = 26 + 13 * count( $name ) + 11 * count( $wrapped );
		$pdf->set_fill( 240, 253, 250 );
		$pdf->rect( $m, $y, $r - $m, $bh, true );
		$pdf->font( true, 8 );
		$pdf->color( 31, 143, 134 );
		$pdf->text( $m + 10, $y + 14, 'BILL TO' );
		$pdf->font( true, 11 );
		$pdf->color( 17, 24, 39 );
		$by = $y + 28;
		foreach ( $name as $l ) {
			$pdf->text( $m + 10, $by, $l );
			$by += 13;
		}
		$pdf->font( false, 8.5 );
		$pdf->color( 55, 65, 81 );
		foreach ( $wrapped as $l ) {
			$pdf->text( $m + 10, $by, $l );
			$by += 11;
		}
		$y += $bh + 16;

		// Line items.
		$cols   = array( $m, $m + 24, $r - 200, $r - 95 );
		$header = function ( $y ) use ( $pdf, $m, $r, $cols ) {
			$pdf->set_fill( 31, 143, 134 );
			$pdf->rect( $m, $y, $r - $m, 18, true );
			$pdf->font( true, 8.5 );
			$pdf->color( 255, 255, 255 );
			$pdf->text( $cols[0] + 6, $y + 12, '#' );
			$pdf->text( $cols[1] + 4, $y + 12, 'Description' );
			$pdf->text( $cols[2] + 4, $y + 12, 'Type' );
			$pdf->text_right( $r - 6, $y + 12, 'Amount' );
			return $y + 18;
		};
		$y = $header( $need( $y, 40 ) );
		$n = 0;
		foreach ( self::sections( $inv ) as $section ) {
			if ( '' !== $section['title'] ) {
				$ny = $need( $y, 40 );
				if ( $ny !== $y ) {
					$y = $header( $ny );
				}
				$pdf->set_fill( 232, 245, 243 );
				$pdf->rect( $m, $y, $r - $m, 17, true );
				$pdf->font( true, 9 );
				$pdf->color( 21, 101, 95 );
				$pdf->text( $m + 6, $y + 12, $section['title'] );
				$y += 17;
			}
			foreach ( $section['items'] as $it ) {
			$n++;
			$pdf->font( 'package' === $it['treatment'], 9 );
			$desc = $pdf->wrap( $it['description'], $cols[2] - $cols[1] - 10 );
			$pdf->font( false, 7.5 );
			// Work log details under the description: completion date, remarks, re-billing reason.
			$sub = array();
			if ( ! empty( $it['completed_on'] ) ) {
				$sub[] = 'Completed ' . self::date( $it['completed_on'] );
			}
			if ( 'package' === $it['treatment'] ) {
				if ( self::period( $it ) ) {
					$sub[] = self::period( $it );
				}
				foreach ( self::lines( isset( $it['terms'] ) ? $it['terms'] : '' ) as $tl ) {
					$sub = array_merge( $sub, $pdf->wrap( 'Terms: ' . $tl, $cols[2] - $cols[1] - 10 ) );
				}
			}
			foreach ( self::lines( isset( $it['remarks'] ) ? $it['remarks'] : '' ) as $rl ) {
				$sub = array_merge( $sub, $pdf->wrap( $rl, $cols[2] - $cols[1] - 10 ) );
			}
			if ( 'expense' === $it['treatment'] ) {
				$sub = array( 'Expense dated ' . self::date( $it['completed_on'] ) . ( isset( $it['original_amount'] ) && null !== $it['original_amount'] && abs( $it['original_amount'] - $it['amount'] ) > 0.001 ? ' · original amount ' . self::money( $it['original_amount'] ) : '' ) );
				foreach ( self::lines( isset( $it['remarks'] ) ? $it['remarks'] : '' ) as $rl ) {
					$sub = array_merge( $sub, $pdf->wrap( $rl, $cols[2] - $cols[1] - 10 ) );
				}
			}
			if ( ! empty( $it['override_reason'] ) ) {
				$sub = array_merge( $sub, $pdf->wrap( ( 'expense' === $it['treatment'] ? 'Amount adjusted: ' : 'Billed again: ' ) . $it['override_reason'], $cols[2] - $cols[1] - 10 ) );
			}
			$rh   = 9 + 11 * count( $desc ) + 9 * count( $sub );
			$ny   = $need( $y, $rh );
			if ( $ny !== $y ) {
				$y = $header( $ny );
			}
			if ( 0 === $n % 2 ) {
				$pdf->set_fill( 246, 247, 249 );
				$pdf->rect( $m, $y, $r - $m, $rh, true );
			}
			$label = 'package' === $it['treatment'] ? 'Package' : ( 'included' === $it['treatment'] ? 'Included in Package' : ( 'expense' === $it['treatment'] ? 'Reimbursable expense' : ( 'carried' === $it['treatment'] ? 'Previous balance' : ( $it['task_id'] ? 'Additional work' : 'Custom item' ) ) ) );
			$pdf->font( false, 8.5 );
			$pdf->color( 107, 114, 128 );
			$pdf->text( $cols[0] + 6, $y + 12, (string) $n );
			$pdf->font( 'package' === $it['treatment'], 9 );
			$pdf->color( 17, 24, 39 );
			$dy = $y + 12;
			foreach ( $desc as $dl ) {
				$pdf->text( $cols[1] + 4, $dy, $dl );
				$dy += 11;
			}
			if ( $sub ) {
				$pdf->font( false, 7.5 );
				$pdf->color( 107, 114, 128 );
				foreach ( $sub as $sl ) {
					$pdf->text( $cols[1] + 4, $dy - 2, $sl );
					$dy += 9;
				}
			}
			$inc = 'included' === $it['treatment'];
			$pdf->font( $inc, 8 );
			$pdf->color( $inc ? 31 : 75, $inc ? 143 : 85, $inc ? 134 : 99 );
			$pdf->text( $cols[2] + 4, $y + 12, $label );
			$pdf->font( false, 9 );
			$pdf->color( $inc ? 107 : 17, $inc ? 114 : 24, $inc ? 128 : 39 );
			$pdf->text_right( $r - 6, $y + 12, $inc ? 'No extra charge' : self::money( $it['amount'] ) );
			$y += $rh;
			}
			if ( '' !== (string) $section['remarks'] ) {
				$pdf->font( false, 8 );
				$pdf->color( 75, 85, 99 );
				foreach ( self::lines( $section['remarks'] ) as $srl ) {
					foreach ( $pdf->wrap( $srl, $r - $m - 12 ) as $wl ) {
						$y = $need( $y, 11 );
						$pdf->text( $m + 6, $y + 9, $wl );
						$y += 11;
					}
				}
				$y += 3;
			}
		}
		$pdf->stroke( 209, 213, 219 );
		$pdf->line( $m, $y, $r, $y );
		$y += 14;

		// Totals (right column).
		$tot = array();
		$exp = isset( $inv['expense_subtotal'] ) ? (float) $inv['expense_subtotal'] : 0;
		if ( $exp > 0 ) {
			$tot[] = array( 'Services & package', self::money( $inv['subtotal'] - $exp ) );
			$tot[] = array( 'Reimbursable expenses', self::money( $exp ) );
		}
		$tot[] = array( 'Subtotal', self::money( $inv['subtotal'] ) );
		if ( $inv['discount'] > 0 ) {
			$tot[] = array( ! empty( $inv['discount_label'] ) ? 'Discount: ' . $inv['discount_label'] : 'Discount', '- ' . self::money( $inv['discount'] ) );
		}
		if ( $inv['tax_rate'] > 0 ) {
			$tot[] = array( ( $inv['tax_label'] ? $inv['tax_label'] : 'Tax' ) . ' @ ' . rtrim( rtrim( number_format( $inv['tax_rate'], 2 ), '0' ), '.' ) . '%', self::money( $inv['tax_amount'] ) );
		}
		if ( ! empty( $inv['carried_forward'] ) && $inv['carried_forward'] > 0 ) {
			$tot[] = array( 'Current invoice total', self::money( $inv['total'] - $inv['carried_forward'] ) );
			$tot[] = array( 'Previous balance brought forward', self::money( $inv['carried_forward'] ) );
		}
		$tot[] = array( 'Grand total', self::money( $inv['total'] ), true );
		if ( $inv['amount_adjusted'] > 0 ) {
			$tot[] = array( 'Advance adjusted', '- ' . self::money( $inv['amount_adjusted'] ) );
			$tot[] = array( 'Amount paid', self::money( $inv['amount_received'] ) );
		} else {
			$tot[] = array( 'Amount paid', self::money( $inv['amount_paid'] ) );
		}
		if ( ! empty( $inv['amount_transferred'] ) && $inv['amount_transferred'] > 0 && ! empty( $inv['consolidated_into'] ) ) {
			$tot[] = array( 'Transferred to ' . $inv['consolidated_into']['invoice_no'], '- ' . self::money( $inv['amount_transferred'] ) );
		}
		$tot[] = array( $cancelled ? 'Balance due (cancelled)' : 'Balance due', self::money( $cancelled ? 0 : $inv['balance'] ), true );

		// Payment details and UPI QR (left column).
		$pay = array();
		if ( ! empty( $b['account_name'] ) ) {
			$pay[] = 'Account name: ' . $b['account_name'];
		}
		if ( ! empty( $b['bank_name'] ) ) {
			$pay[] = 'Bank: ' . $b['bank_name'];
		}
		if ( ! empty( $b['account_number'] ) ) {
			$pay[] = 'A/c no.: ' . $b['account_number'] . ( ! empty( $b['ifsc'] ) ? '   IFSC: ' . $b['ifsc'] : '' );
		}
		if ( ! empty( $b['upi_id'] ) ) {
			$pay[] = 'UPI ID: ' . $b['upi_id'];
		}
		$pay   = array_merge( $pay, self::lines( $inv['payment_instructions'] ) );
		$qr    = ! $cancelled && ! empty( $b['upi_qr'] ) ? NDWD_Files::path( $b['upi_qr'] ) : null;
		$qs    = 84;
		$lw    = $r - 250 - $m - ( $qr ? $qs + 12 : 0 );
		$pdf->font( false, 8.5 );
		$payw = array();
		foreach ( $pay as $l ) {
			$payw = array_merge( $payw, $pdf->wrap( $l, $lw ) );
		}
		$left_h = $pay || $qr ? 14 + max( 11 * count( $payw ), $qr ? $qs + 12 : 0 ) : 0;
		$y      = $need( $y, max( 18 * count( $tot ), min( $left_h, 200 ) ) );
		$ty     = $y;
		foreach ( $tot as $t ) {
			$big = ! empty( $t[2] );
			if ( $big ) {
				$pdf->set_fill( 240, 253, 250 );
				$pdf->rect( $r - 230, $ty - 12, 230, 18, true );
			}
			$pdf->font( $big, $big ? 10 : 9 );
			$pdf->color( $big ? 17 : 75, $big ? 24 : 85, $big ? 39 : 99 );
			$pdf->text( $r - 222, $ty, $t[0] );
			$pdf->text_right( $r - 6, $ty, $t[1] );
			$ty += 18;
		}
		$ny = $y;
		if ( $pay || $qr ) {
			$pdf->font( true, 8 );
			$pdf->color( 31, 143, 134 );
			$pdf->text( $m, $ny, 'PAYMENT DETAILS' );
			$ny += 13;
			if ( $qr && $pdf->image_jpeg( $qr, $m, $ny - 6, $qs, $qs ) ) {
				$pdf->font( false, 7 );
				$pdf->color( 107, 114, 128 );
				$pdf->text( $m + 10, $ny + $qs + 4, 'Scan to pay with UPI' );
				$px = $m + $qs + 12;
			} else {
				$qr = null;
				$px = $m;
			}
			$pdf->font( false, 8.5 );
			$pdf->color( 55, 65, 81 );
			$py = $ny;
			foreach ( $payw as $l ) {
				$py = $need( $py, 11 );
				$pdf->text( $px, $py, $l );
				$py += 11;
			}
			$ny = max( $py, $qr ? $ny + $qs + 12 : 0 ) + 6;
		}
		$y = max( $ty, $ny ) + 8;

		// Previous outstanding balance: a statement of earlier open invoices, not part of this invoice's total.
		$as = ! $cancelled && ! empty( $inv['account_summary'] ) ? $inv['account_summary'] : null;
		if ( $as ) {
			$y = $need( $y, 60 );
			$pdf->font( true, 8 );
			$pdf->color( 31, 143, 134 );
			$pdf->text( $m, $y, 'PREVIOUS OUTSTANDING BALANCE (AS ON ' . strtoupper( self::date( $as['as_of'] ) ) . ')' );
			$y += 13;
			$pc = array( $m, $m + 120, $m + 195, $r - 225, $r - 150, $r - 75 );
			$pdf->font( true, 7.5 );
			$pdf->color( 107, 114, 128 );
			foreach ( array( 'Invoice', 'Date', 'Due' ) as $i => $h ) {
				$pdf->text( $pc[ $i ] + 2, $y, $h );
			}
			$pdf->text_right( $pc[4] - 6, $y, 'Invoice amount' );
			$pdf->text_right( $pc[5] - 6, $y, 'Received' );
			$pdf->text_right( $r - 6, $y, 'Outstanding' );
			$y += 11;
			$pdf->font( false, 8.5 );
			$pdf->color( 55, 65, 81 );
			foreach ( $as['invoices'] as $o ) {
				$y = $need( $y, 11 );
				$pdf->text( $pc[0] + 2, $y, $o['invoice_no'] . ( $o['overdue'] ? ' (overdue)' : '' ) );
				$pdf->text( $pc[1] + 2, $y, self::date( $o['invoice_date'] ) );
				$pdf->text( $pc[2] + 2, $y, self::date( $o['due_date'] ) );
				$pdf->text_right( $pc[4] - 6, $y, self::money( $o['total'] ) );
				$pdf->text_right( $pc[5] - 6, $y, self::money( $o['received'] + $o['adjusted'] ) );
				$pdf->text_right( $r - 6, $y, self::money( $o['balance'] ) );
				$y += 11;
			}
			$y += 6;
			$rows = array(
				array( 'Current invoice balance', self::money( $as['current_balance'] ) ),
				array( 'Previous outstanding balance', self::money( $as['previous_balance'] ) ),
			);
			if ( $as['advance_credit'] > 0 ) {
				$rows[] = array( 'Available advance credit', '- ' . self::money( $as['advance_credit'] ) );
			}
			$rows[] = array( 'Total account balance payable', self::money( $as['total_payable'] ), true );
			foreach ( $rows as $t ) {
				$y   = $need( $y, 18 );
				$big = ! empty( $t[2] );
				if ( $big ) {
					$pdf->set_fill( 240, 253, 250 );
					$pdf->rect( $r - 230, $y - 12, 230, 18, true );
				}
				$pdf->font( $big, $big ? 10 : 9 );
				$pdf->color( $big ? 17 : 75, $big ? 24 : 85, $big ? 39 : 99 );
				$pdf->text( $r - 222, $y, $t[0] );
				$pdf->text_right( $r - 6, $y, $t[1] );
				$y += 18;
			}
			$pdf->font( false, 7.5 );
			$pdf->color( 107, 114, 128 );
			foreach ( $pdf->wrap( 'Earlier invoices are listed for information only; they keep their own numbers and payments and are not included in this invoice total.', $r - $m ) as $wl ) {
				$y = $need( $y, 10 );
				$pdf->text( $m, $y, $wl );
				$y += 10;
			}
			$y += 6;
		}

		// Work log remarks.
		$work = self::lines( isset( $inv['work_remarks'] ) ? $inv['work_remarks'] : '' );
		if ( $work ) {
			$y = $need( $y, 30 );
			$pdf->font( true, 8 );
			$pdf->color( 31, 143, 134 );
			$pdf->text( $m, $y, 'WORK LOG REMARKS' );
			$y += 12;
			$pdf->font( false, 8.5 );
			$pdf->color( 55, 65, 81 );
			foreach ( $work as $l ) {
				foreach ( $pdf->wrap( $l, $r - $m ) as $wl ) {
					$y = $need( $y, 11 );
					$pdf->text( $m, $y, $wl );
					$y += 11;
				}
			}
			$y += 8;
		}

		// Notes and terms.
		$notes = self::lines( $inv['notes'] );
		if ( $notes ) {
			$y = $need( $y, 30 );
			$pdf->font( true, 8 );
			$pdf->color( 31, 143, 134 );
			$pdf->text( $m, $y, 'NOTES & TERMS' );
			$y += 12;
			$pdf->font( false, 8.5 );
			$pdf->color( 55, 65, 81 );
			foreach ( $notes as $l ) {
				foreach ( $pdf->wrap( $l, $r - $m ) as $wl ) {
					$y = $need( $y, 11 );
					$pdf->text( $m, $y, $wl );
					$y += 11;
				}
			}
			$y += 8;
		}

		// Payments received.
		$active = array_filter( isset( $inv['payments'] ) ? $inv['payments'] : array(), function ( $p ) {
			return ! $p['voided_at'];
		} );
		if ( $active ) {
			$y = $need( $y, 30 );
			$pdf->font( true, 8 );
			$pdf->color( 31, 143, 134 );
			$pdf->text( $m, $y, 'PAYMENTS RECEIVED' );
			$y += 12;
			$pdf->font( false, 8.5 );
			$pdf->color( 55, 65, 81 );
			foreach ( $active as $p ) {
				$y = $need( $y, 11 );
				$pdf->text( $m, $y, self::date( $p['paid_on'] ) . '  ·  ' . $p['method_label'] . ( $p['reference'] ? '  ·  Ref ' . $p['reference'] : '' ) );
				$pdf->text_right( $m + 330, $y, self::money( $p['amount'] ) );
				$y += 11;
			}
		}

		$footer();
		return $pdf->save( $path );
	}
}
