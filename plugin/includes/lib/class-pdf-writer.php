<?php
/**
 * Small dependency-free PDF writer for tabular reports.
 *
 * Uses the PDF base-14 Helvetica fonts (WinAnsi encoding), so no font files
 * are needed. Characters outside Windows-1252 (e.g. Devanagari) are
 * transliterated or replaced with "?" — a documented limitation.
 */

defined( 'ABSPATH' ) || exit;

class NDWD_PDF_Writer {

	private $w;
	private $h;
	private $margin = 36;
	private $pages  = array();
	private $buf    = '';
	private $y      = 0;
	private $font   = 'F1';
	private $size   = 10;
	private $title;
	private $bar = true;
	private $images = array();

	/** Helvetica glyph widths (1/1000 em) for ASCII 32..126. */
	private static $widths = array(
		278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
		1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
		333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
		556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
	);

	public function __construct( $title = '', $landscape = true, $bar = true ) {
		$this->w     = $landscape ? 842 : 595;
		$this->h     = $landscape ? 595 : 842;
		$this->title = $title;
		$this->bar   = $bar;
		$this->add_page();
	}

	public function add_page() {
		if ( '' !== $this->buf || $this->pages ) {
			$this->pages[] = $this->buf;
		}
		$this->buf = '';
		$this->y   = $this->margin;
		if ( $this->title && $this->bar ) {
			$this->set_fill( 17, 18, 20 );
			$this->rect( 0, 0, $this->w, 24, true );
			$this->color( 94, 234, 212 );
			$this->font( true, 9 );
			$this->text( $this->margin, 15, 'NeonDigital WorkDesk' );
			$this->color( 220, 220, 220 );
			$this->font( false, 9 );
			$this->text_right( $this->w - $this->margin, 15, $this->title );
			$this->color( 17, 24, 39 );
			$this->y = 44;
		}
	}

	public function enc( $s ) {
		$s = (string) $s;
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'CP1252//TRANSLIT//IGNORE', $s ); // phpcs:ignore
			if ( false !== $out ) {
				return $out;
			}
		}
		return preg_replace( '/[^\x20-\x7E]/', '?', $s );
	}

	private static function esc( $s ) {
		return str_replace( array( '\\', '(', ')', "\r", "\n" ), array( '\\\\', '\\(', '\\)', ' ', ' ' ), $s );
	}

	public function width( $s, $size = null, $bold = null ) {
		$size = $size ? $size : $this->size;
		$bold = null === $bold ? 'F2' === $this->font : $bold;
		$s    = $this->enc( $s );
		$w    = 0;
		$len  = strlen( $s );
		for ( $i = 0; $i < $len; $i++ ) {
			$o  = ord( $s[ $i ] );
			$w += ( $o >= 32 && $o <= 126 ) ? self::$widths[ $o - 32 ] : 556;
		}
		return $w * $size / 1000 * ( $bold ? 1.07 : 1 );
	}

	public function font( $bold, $size ) {
		$this->font = $bold ? 'F2' : 'F1';
		$this->size = $size;
	}

	public function color( $r, $g, $b ) {
		$this->buf .= sprintf( "%.3F %.3F %.3F rg\n", $r / 255, $g / 255, $b / 255 );
	}

	public function set_fill( $r, $g, $b ) {
		$this->color( $r, $g, $b );
	}

	public function stroke( $r, $g, $b ) {
		$this->buf .= sprintf( "%.3F %.3F %.3F RG\n", $r / 255, $g / 255, $b / 255 );
	}

	public function rect( $x, $y, $w, $h, $fill = false ) {
		$this->buf .= sprintf( "%.2F %.2F %.2F %.2F re %s\n", $x, $this->h - $y - $h, $w, $h, $fill ? 'f' : 'S' );
	}

	public function line( $x1, $y1, $x2, $y2 ) {
		$this->buf .= sprintf( "0.5 w %.2F %.2F m %.2F %.2F l S\n", $x1, $this->h - $y1, $x2, $this->h - $y2 );
	}

	public function text( $x, $y, $s ) {
		$this->buf .= sprintf( "BT /%s %.1F Tf %.2F %.2F Td (%s) Tj ET\n", $this->font, $this->size, $x, $this->h - $y, self::esc( $this->enc( $s ) ) );
	}

	public function text_right( $x, $y, $s ) {
		$this->text( $x - $this->width( $s ), $y, $s );
	}

	/**
	 * Draws a baseline JPEG file into the box ($x, $y top-left, $w x $h points).
	 *
	 * @return bool False when the file is missing or not a JPEG.
	 */
	public function image_jpeg( $path, $x, $y, $w, $h ) {
		$info = $path && is_readable( $path ) ? @getimagesize( $path ) : false; // phpcs:ignore
		if ( ! $info || IMAGETYPE_JPEG !== $info[2] ) {
			return false;
		}
		$key = md5( $path );
		if ( ! isset( $this->images[ $key ] ) ) {
			$this->images[ $key ] = array(
				'name' => 'Im' . ( count( $this->images ) + 1 ),
				'data' => file_get_contents( $path ), // phpcs:ignore
				'w'    => (int) $info[0],
				'h'    => (int) $info[1],
				'cs'   => isset( $info['channels'] ) && 1 === (int) $info['channels'] ? '/DeviceGray' : ( isset( $info['channels'] ) && 4 === (int) $info['channels'] ? '/DeviceCMYK' : '/DeviceRGB' ),
			);
		}
		$this->buf .= sprintf( "q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $this->h - $y - $h, $this->images[ $key ]['name'] );
		return true;
	}

	/** Splits text into lines that fit $max points. */
	public function wrap( $s, $max ) {
		$out = array();
		foreach ( preg_split( "/\r\n|\n|\r/", (string) $s ) as $para ) {
			$line = '';
			foreach ( preg_split( '/\s+/', trim( $para ) ) as $word ) {
				$try = '' === $line ? $word : $line . ' ' . $word;
				if ( $this->width( $try ) <= $max ) {
					$line = $try;
					continue;
				}
				if ( '' !== $line ) {
					$out[] = $line;
				}
				while ( $this->width( $word ) > $max && mb_strlen( $word ) > 1 ) {
					$cut = mb_strlen( $word );
					while ( $cut > 1 && $this->width( mb_substr( $word, 0, $cut ) ) > $max ) {
						$cut--;
					}
					$out[] = mb_substr( $word, 0, $cut );
					$word  = mb_substr( $word, $cut );
				}
				$line = $word;
			}
			$out[] = $line;
		}
		return $out ? $out : array( '' );
	}

	public function ensure( $height ) {
		if ( $this->y + $height > $this->h - $this->margin - 14 ) {
			$this->add_page();
		}
	}

	public function heading( $s, $size = 16 ) {
		$this->ensure( $size + 30 );
		$this->font( true, $size );
		$this->color( 17, 24, 39 );
		$this->y += $size;
		$this->text( $this->margin, $this->y, $s );
		$this->y += 8;
		$this->stroke( 45, 212, 168 );
		$this->line( $this->margin, $this->y, $this->margin + 60, $this->y );
		$this->y += 10;
	}

	public function paragraph( $s, $size = 9.5, $rgb = array( 55, 65, 81 ) ) {
		$this->font( false, $size );
		$this->color( $rgb[0], $rgb[1], $rgb[2] );
		foreach ( $this->wrap( $s, $this->w - 2 * $this->margin ) as $line ) {
			$this->ensure( $size + 4 );
			$this->y += $size + 3;
			$this->text( $this->margin, $this->y, $line );
		}
		$this->y += 6;
	}

	/** Row of KPI boxes: [ [label, value], ... ]. */
	public function stats( $items ) {
		$n   = max( 1, count( $items ) );
		$gap = 8;
		$bw  = ( $this->w - 2 * $this->margin - ( $n - 1 ) * $gap ) / $n;
		$this->ensure( 52 );
		foreach ( array_values( $items ) as $i => $it ) {
			$x = $this->margin + $i * ( $bw + $gap );
			$this->set_fill( 240, 253, 250 );
			$this->rect( $x, $this->y, $bw, 44, true );
			$this->color( 107, 114, 128 );
			$this->font( false, 8 );
			$this->text( $x + 8, $this->y + 14, $it[0] );
			$this->color( 17, 24, 39 );
			$this->font( true, 15 );
			$this->text( $x + 8, $this->y + 35, (string) $it[1] );
		}
		$this->y += 56;
	}

	/**
	 * Table with header repeated on each page and wrapped cells.
	 *
	 * @param array $widths Relative column widths.
	 */
	public function table( $header, $rows, $widths = array(), $size = 8, $align = array() ) {
		$total = $this->w - 2 * $this->margin;
		$n     = count( $header );
		if ( ! $widths ) {
			$widths = array_fill( 0, $n, 1 );
		}
		$sum = array_sum( $widths );
		$cw  = array_map( function ( $w ) use ( $sum, $total ) {
			return $w / $sum * $total;
		}, $widths );
		$pad = 4;
		$lh  = $size + 3;

		$draw_header = function () use ( $header, $cw, $pad, $size, $total, $align ) {
			$this->ensure( 40 );
			$this->set_fill( 31, 143, 134 );
			$this->rect( $this->margin, $this->y, $total, $size + 10, true );
			$this->color( 255, 255, 255 );
			$this->font( true, $size );
			$x = $this->margin;
			foreach ( $header as $i => $h ) {
				$lines = $this->wrap( $h, $cw[ $i ] - 2 * $pad );
				if ( isset( $align[ $i ] ) && 'R' === $align[ $i ] ) {
					$this->text_right( $x + $cw[ $i ] - $pad, $this->y + $size + 3, $lines[0] );
				} else {
					$this->text( $x + $pad, $this->y + $size + 3, $lines[0] );
				}
				$x += $cw[ $i ];
			}
			$this->y += $size + 10;
		};
		$draw_header();

		if ( ! $rows ) {
			$this->font( false, $size );
			$this->color( 107, 114, 128 );
			$this->y += $lh + 2;
			$this->text( $this->margin + $pad, $this->y, 'No records for this period.' );
			$this->y += 10;
			return;
		}
		foreach ( $rows as $ri => $row ) {
			$row = array_values( (array) $row );
			$this->font( false, $size );
			$cells = array();
			$max   = 1;
			foreach ( $cw as $i => $w ) {
				$lines       = $this->wrap( isset( $row[ $i ] ) ? $row[ $i ] : '', $w - 2 * $pad );
				$lines       = array_slice( $lines, 0, 8 );
				$cells[ $i ] = $lines;
				$max         = max( $max, count( $lines ) );
			}
			$rh = $max * $lh + 6;
			if ( $this->y + $rh > $this->h - $this->margin - 14 ) {
				$this->add_page();
				$draw_header();
				$this->font( false, $size );
			}
			if ( $ri % 2 ) {
				$this->set_fill( 246, 247, 249 );
				$this->rect( $this->margin, $this->y, $total, $rh, true );
			}
			$this->color( 31, 41, 55 );
			$x = $this->margin;
			foreach ( $cells as $i => $lines ) {
				foreach ( $lines as $li => $l ) {
					if ( isset( $align[ $i ] ) && 'R' === $align[ $i ] ) {
						$this->text_right( $x + $cw[ $i ] - $pad, $this->y + 3 + ( $li + 1 ) * $lh - 2, $l );
					} else {
						$this->text( $x + $pad, $this->y + 3 + ( $li + 1 ) * $lh - 2, $l );
					}
				}
				$x += $cw[ $i ];
			}
			$this->y += $rh;
		}
		$this->stroke( 229, 231, 235 );
		$this->line( $this->margin, $this->y, $this->margin + $total, $this->y );
		$this->y += 14;
	}

	public function get_y() {
		return $this->y;
	}

	public function set_y( $y ) {
		$this->y = $y;
	}

	public function page_width() {
		return $this->w;
	}

	public function page_height() {
		return $this->h;
	}

	public function margin() {
		return $this->margin;
	}

	public function space( $pts ) {
		$this->y += $pts;
	}

	/** Builds the PDF and writes it. @return true|WP_Error */
	public function save( $path ) {
		$this->pages[] = $this->buf;
		$this->buf     = '';
		$count         = count( $this->pages );

		$objects   = array();
		$objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
		$objects[] = ''; // Pages, filled below.
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
		$objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		$objects[] = '<< /Title (' . self::esc( $this->enc( $this->title ) ) . ') /Producer (NeonDigital WorkDesk) >>';
		$xobjects  = '';
		foreach ( $this->images as $im ) {
			$objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h'] . ' /ColorSpace ' . $im['cs'] . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $im['data'] ) . " >>\nstream\n" . $im['data'] . "\nendstream";
			$xobjects .= ' /' . $im['name'] . ' ' . count( $objects ) . ' 0 R';
		}
		$xobjects = '' !== $xobjects ? ' /XObject <<' . $xobjects . ' >>' : '';
		$kids     = array();
		foreach ( $this->pages as $i => $content ) {
			// Footer with page numbers (known only now).
			$footer   = sprintf( "0.420 0.447 0.502 rg BT /F1 8.0 Tf %.2F %.2F Td (%s) Tj ET\n", $this->margin, 18, self::esc( 'Generated ' . NDWD_Helpers::now()->format( 'd M Y H:i' ) . ' IST' ) );
			$label    = 'Page ' . ( $i + 1 ) . ' of ' . $count;
			$footer  .= sprintf( "BT /F1 8.0 Tf %.2F %.2F Td (%s) Tj ET\n", $this->w - $this->margin - $this->width( $label, 8, false ), 18, $label );
			$stream   = $content . $footer;
			$data     = function_exists( 'gzcompress' ) ? gzcompress( $stream ) : $stream;
			$filter   = function_exists( 'gzcompress' ) ? ' /Filter /FlateDecode' : '';
			$objects[] = '<< /Length ' . strlen( $data ) . $filter . " >>\nstream\n" . $data . "\nendstream";
			$content_id = count( $objects );
			$objects[]  = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->w . ' ' . $this->h . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . $xobjects . ' >> /Contents ' . $content_id . ' 0 R >>';
			$kids[]     = count( $objects ) . ' 0 R';
		}
		$objects[1] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $count . ' >>';

		$pdf     = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $i => $o ) {
			$offsets[ $i + 1 ] = strlen( $pdf );
			$pdf              .= ( $i + 1 ) . " 0 obj\n" . $o . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$pdf .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( "%010d 00000 n \n", $off );
		}
		$pdf .= 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

		$ok = file_put_contents( $path, $pdf ); // phpcs:ignore
		return $ok ? true : new WP_Error( 'ndwd_pdf', 'Could not write the PDF file.' );
	}
}
