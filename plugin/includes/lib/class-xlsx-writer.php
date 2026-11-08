<?php
/**
 * Minimal, dependency-free XLSX (Office Open XML) writer.
 * Requires PHP's ZipArchive extension (enabled on most shared hosts).
 */

defined( 'ABSPATH' ) || exit;

class NDWD_XLSX_Writer {

	private $sheets = array();

	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * @param string $name    Sheet name (max 31 chars, no []:*?/\).
	 * @param array  $header  Column titles.
	 * @param array  $rows    Rows of scalar values.
	 * @param array  $widths  Optional column widths (characters).
	 */
	public function add_sheet( $name, $header, $rows, $widths = array() ) {
		$name = mb_substr( preg_replace( '/[\[\]\:\*\?\/\\\\]/', ' ', $name ), 0, 31 );
		$used = wp_list_pluck( $this->sheets, 'name' );
		$base = $name;
		$i    = 2;
		while ( in_array( $name, $used, true ) ) {
			$name = mb_substr( $base, 0, 28 ) . ' ' . $i++;
		}
		$this->sheets[] = array( 'name' => $name, 'header' => $header, 'rows' => $rows, 'widths' => $widths );
	}

	public static function col( $i ) {
		$s = '';
		$i++;
		while ( $i > 0 ) {
			$m = ( $i - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$i = (int) ( ( $i - $m ) / 26 );
		}
		return $s;
	}

	private static function x( $v ) {
		$v = (string) $v;
		$v = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v );
		if ( null === $v ) {
			$v = '';
		}
		return htmlspecialchars( mb_substr( $v, 0, 32000 ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	private function sheet_xml( $sheet ) {
		$cols  = count( $sheet['header'] );
		$xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>';
		if ( $cols ) {
			$xml .= '<cols>';
			for ( $c = 0; $c < $cols; $c++ ) {
				$w    = isset( $sheet['widths'][ $c ] ) ? (float) $sheet['widths'][ $c ] : self::guess_width( $sheet, $c );
				$xml .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . $w . '" customWidth="1"/>';
			}
			$xml .= '</cols>';
		}
		$xml .= '<sheetData>';
		$all  = array_merge( array( $sheet['header'] ), $sheet['rows'] );
		foreach ( $all as $r => $row ) {
			$xml .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( array_values( (array) $row ) as $c => $val ) {
				$ref   = self::col( $c ) . ( $r + 1 );
				$style = 0 === $r ? ' s="1"' : ' s="2"';
				if ( null === $val || '' === $val ) {
					$xml .= '<c r="' . $ref . '"' . $style . '/>';
				} elseif ( $r > 0 && ( is_int( $val ) || is_float( $val ) ) ) {
					$xml .= '<c r="' . $ref . '"' . $style . '><v>' . $val . '</v></c>';
				} else {
					$xml .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . self::x( $val ) . '</t></is></c>';
				}
			}
			$xml .= '</row>';
		}
		$xml .= '</sheetData>';
		if ( $cols && count( $all ) > 1 ) {
			$xml .= '<autoFilter ref="A1:' . self::col( $cols - 1 ) . count( $all ) . '"/>';
		}
		$xml .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>';
		return $xml;
	}

	private static function guess_width( $sheet, $c ) {
		$max = mb_strlen( (string) $sheet['header'][ $c ] );
		foreach ( array_slice( $sheet['rows'], 0, 200 ) as $row ) {
			$row = array_values( (array) $row );
			if ( isset( $row[ $c ] ) ) {
				$max = max( $max, min( 60, mb_strlen( (string) $row[ $c ] ) ) );
			}
		}
		return max( 8, min( 60, $max + 2 ) );
	}

	/** @return true|WP_Error */
	public function save( $path ) {
		if ( ! self::available() ) {
			return new WP_Error( 'ndwd_xlsx', 'The PHP ZipArchive extension is not available on this server, so .xlsx files cannot be created.' );
		}
		if ( ! $this->sheets ) {
			$this->add_sheet( 'Sheet1', array( 'No data' ), array() );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'ndwd_xlsx', 'Could not create the .xlsx file.' );
		}
		$n            = count( $this->sheets );
		$overrides    = '';
		$wb_sheets    = '';
		$wb_rels      = '';
		foreach ( $this->sheets as $i => $s ) {
			$id         = $i + 1;
			$overrides .= '<Override PartName="/xl/worksheets/sheet' . $id . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wb_sheets .= '<sheet name="' . self::x( $s['name'] ) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
			$wb_rels   .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
			$zip->addFromString( 'xl/worksheets/sheet' . $id . '.xml', $this->sheet_xml( $s ) );
		}
		$wb_rels .= '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
			. '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
			. $overrides . '</Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
			. '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
			. '</Relationships>' );
		$zip->addFromString( 'docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
			. '<dc:title>NeonDigital WorkDesk Report</dc:title><dc:creator>NeonDigital WorkDesk</dc:creator>'
			. '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created></cp:coreProperties>' );
		$zip->addFromString( 'docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>NeonDigital WorkDesk</Application></Properties>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wb_rels . '</Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>' . $wb_sheets . '</sheets></workbook>' );
		$zip->addFromString( 'xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
			. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FF1F8F86"/><bgColor indexed="64"/></patternFill></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>' );
		if ( ! $zip->close() ) {
			return new WP_Error( 'ndwd_xlsx', 'Could not finalise the .xlsx file.' );
		}
		return file_exists( $path ) && filesize( $path ) > 0 ? true : new WP_Error( 'ndwd_xlsx', 'The .xlsx file was not written.' );
	}
}
