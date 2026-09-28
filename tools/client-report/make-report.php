<?php
/**
 * Build a client report .docx from a content file in this folder.
 *
 *     D:\xampp\php\php.exe tools\client-report\make-report.php milestone-2
 *
 * Reads tools/client-report/<name>.php and writes the .docx it names to the
 * repository root. Word, Google Docs and LibreOffice all open the result.
 *
 * No libraries: this machine's PHP has no ZipArchive, so the .docx container
 * (a ZIP) is written by hand with uncompressed entries, which the format
 * allows. Entry names use forward slashes; Word rejects backslashes.
 *
 * This folder is not deployed — tools/deploy.sh syncs only the plugin and
 * the theme.
 */

declare( strict_types = 1 );

$name = $argv[1] ?? '';

if ( ! preg_match( '/^[a-z0-9-]+$/', $name ) || ! is_file( __DIR__ . "/{$name}.php" ) ) {
	fwrite( STDERR, "Usage: php tools/client-report/make-report.php <content-file-name>\n" );
	exit( 1 );
}

$spec = require __DIR__ . "/{$name}.php";
$out  = dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . $spec['file'];

/* ── Paragraphs ─────────────────────────────────────────────────────────── */

function esc( string $s ): string {
	return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/*
 * Brand colours, from themes/thirtydayhomes/assets/design-tokens.css.
 * Sizes are half-points (22 = 11pt); spacing and widths are twentieths of a
 * point (1134 = 2 cm). The text column is 12240 − 2 × 1134 = 9972 wide.
 */
const NAVY     = '0C192B';
const GOLD     = 'D7B967';
const GOLD_INK = '8A7433';
const SAND     = 'F2EAD5';
const CREAM    = 'FAF8F2';
const MUTED    = '687384';
const LINE     = 'E4E5E6';
const TEXT_W   = 9972;

/** One run of text. */
function run( string $text, int $size, string $colour, bool $bold = false ): string {
	return '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
		. ( $bold ? '<w:b/><w:bCs/>' : '' )
		. '<w:color w:val="' . $colour . '"/>'
		. '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>'
		. '<w:t xml:space="preserve">' . esc( $text ) . '</w:t></w:r>';
}

/**
 * Text whose web addresses become clickable links. Each address gets its
 * own relationship in word/_rels/document.xml.rels (rId2 onwards).
 */
function linked( string $text, int $size, string $colour ): string {
	global $links;
	$out   = '';
	$parts = preg_split( '~(https?://[^\s]+?)(?=[.,;:)]?(?:\s|$))~', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
	foreach ( $parts as $i => $part ) {
		if ( '' === $part ) {
			continue;
		}
		if ( 0 === $i % 2 ) {
			$out .= run( $part, $size, $colour );
			continue;
		}
		$id = $links[ $part ] ??= 'rId' . ( count( $links ) + 2 );
		$out .= '<w:hyperlink r:id="' . $id . '" w:history="1"><w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:b/><w:bCs/>'
			. '<w:color w:val="1D5FA8"/><w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/><w:u w:val="single"/></w:rPr>'
			. '<w:t xml:space="preserve">' . esc( $part ) . '</w:t></w:r></w:hyperlink>';
	}
	return $out;
}

/** One paragraph: extra pPr (borders, shading, indent) plus its runs. */
function para( string $runs, int $before, int $after, string $ppr_extra = '', int $hang = 0 ): string {
	return '<w:p><w:pPr>' . $ppr_extra
		. '<w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="276" w:lineRule="auto"/>'
		. ( $hang ? '<w:ind w:left="' . $hang . '" w:hanging="' . $hang . '"/>' : '' )
		. '</w:pPr>' . $runs . '</w:p>';
}

/** A one-cell, full-width table with a fill: the cover band and the callouts. */
function band( string $inner, string $fill, string $edge = '' ): string {
	$borders = $edge
		? '<w:tcBorders><w:left w:val="single" w:sz="24" w:space="0" w:color="' . $edge . '"/></w:tcBorders>'
		: '';
	return '<w:tbl><w:tblPr><w:tblW w:w="' . TEXT_W . '" w:type="dxa"/>'
		. '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>'
		. '<w:tblCellMar><w:top w:w="220" w:type="dxa"/><w:left w:w="340" w:type="dxa"/><w:bottom w:w="220" w:type="dxa"/><w:right w:w="340" w:type="dxa"/></w:tblCellMar>'
		. '</w:tblPr><w:tblGrid><w:gridCol w:w="' . TEXT_W . '"/></w:tblGrid>'
		. '<w:tr><w:trPr><w:cantSplit/></w:trPr><w:tc><w:tcPr><w:tcW w:w="' . TEXT_W . '" w:type="dxa"/>' . $borders
		. '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/></w:tcPr>'
		. $inner . '</w:tc></w:tr></w:tbl>'
		. para( '', 0, 0 );
}

$links = [];
$body  = '';
$step  = 0;
$cover = [ 'title' => '', 'sub' => '' ];

foreach ( $spec['rows'] as [ $kind, $text ] ) {

	if ( 'title' === $kind || 'sub' === $kind ) {
		$cover[ $kind ] = $text;
		continue;
	}

	// Numbered steps restart under every heading.
	if ( 'h1' === $kind || 'h2' === $kind || 'role' === $kind ) {
		$step = 0;
	}

	$body .= match ( $kind ) {
		'h1'   => para(
			run( $text, 32, NAVY, true ), 480, 200,
			'<w:keepNext/><w:pBdr><w:bottom w:val="single" w:sz="12" w:space="6" w:color="' . GOLD . '"/></w:pBdr>'
		),
		'h2'   => para( run( strtoupper( $text ), 20, GOLD_INK, true ), 280, 100, '<w:keepNext/>' ),
		'li'   => para(
			run( "■\t", 14, GOLD ) . linked( $text, 22, NAVY ), 0, 100,
			'<w:tabs><w:tab w:val="left" w:pos="340"/></w:tabs>', 340
		),
		'step' => para(
			run( ++$step . "\t", 22, GOLD_INK, true ) . linked( $text, 22, NAVY ), 0, 100,
			'<w:tabs><w:tab w:val="left" w:pos="400"/></w:tabs>', 400
		),
		'note' => band( para( linked( $text, 21, NAVY ), 0, 0 ), SAND, GOLD ),
		// A role's opening band: "Title|one line under it".
		'role' => band(
			para( run( explode( '|', $text )[0], 30, 'FFFFFF', true ), 60, 40 )
			. para( linked( explode( '|', $text . '|' )[1], 20, 'DAE0E7' ), 0, 60 ),
			NAVY, GOLD
		),
		default => para( linked( $text, 22, MUTED ), 0, 140 ),
	};
}

$head = band(
	para( run( 'THIRTYDAYHOMES', 18, GOLD, true ), 120, 60 )
	. para( run( $cover['title'], 48, 'FFFFFF', true ), 0, 60 )
	. para( run( $cover['sub'], 24, 'BCC6D1' ), 0, 120 ),
	NAVY
);

$footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
	. '<w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="4" w:space="6" w:color="' . LINE . '"/></w:pBdr>'
	. '<w:tabs><w:tab w:val="right" w:pos="' . TEXT_W . '"/></w:tabs></w:pPr>'
	. run( 'ThirtyDayHomes  ·  ' . $cover['title'], 16, MUTED )
	. run( "\tPage ", 16, MUTED )
	. '<w:r><w:rPr><w:color w:val="' . MUTED . '"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="begin"/></w:r>'
	. '<w:r><w:rPr><w:color w:val="' . MUTED . '"/><w:sz w:val="16"/></w:rPr><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
	. '<w:r><w:rPr><w:color w:val="' . MUTED . '"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="separate"/></w:r>'
	. run( '1', 16, MUTED )
	. '<w:r><w:rPr><w:color w:val="' . MUTED . '"/><w:sz w:val="16"/></w:rPr><w:fldChar w:fldCharType="end"/></w:r>'
	. '</w:p></w:ftr>';

$document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
	. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
	. '<w:body>' . $head . $body
	. '<w:sectPr><w:footerReference w:type="default" r:id="rId1"/><w:pgSz w:w="12240" w:h="15840"/>'
	. '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/></w:sectPr>'
	. '</w:body></w:document>';

$types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
	. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
	. '<Default Extension="xml" ContentType="application/xml"/>'
	. '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
	. '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
	. '</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
	. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
	. '</Relationships>';

$doc_rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
	. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
	. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
	. implode( '', array_map(
		fn( $url, $id ) => '<Relationship Id="' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="' . esc( $url ) . '" TargetMode="External"/>',
		array_keys( $links ),
		$links
	) )
	. '</Relationships>';

/* ── The ZIP container, uncompressed ───────────────────────────────────── */

/**
 * @param array<string,string> $files Entry name => bytes. [Content_Types].xml first.
 */
function zip_stored( array $files ): string {

	// A fixed DOS timestamp: rebuilding unchanged content gives identical bytes.
	$time = 0;
	$date = ( ( 2026 - 1980 ) << 9 ) | ( 1 << 5 ) | 1;

	$data    = '';
	$central = '';

	foreach ( $files as $name => $bytes ) {

		$crc    = crc32( $bytes );
		$size   = strlen( $bytes );
		$offset = strlen( $data );

		$data .= pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $time, $date, $crc, $size, $size, strlen( $name ), 0 )
			. $name . $bytes;

		$central .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $time, $date, $crc, $size, $size, strlen( $name ), 0, 0, 0, 0, 0, $offset )
			. $name;
	}

	$end = pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $files ), count( $files ), strlen( $central ), strlen( $data ), 0 );

	return $data . $central . $end;
}

$zip = zip_stored(
	[
		'[Content_Types].xml' => $types,
		'_rels/.rels'         => $rels,
		'word/document.xml'   => $document,
		'word/_rels/document.xml.rels' => $doc_rels,
		'word/footer1.xml'    => $footer,
	]
);

if ( false === file_put_contents( $out, $zip ) ) {
	fwrite( STDERR, "Could not write {$out} — is it open in Word?\n" );
	exit( 1 );
}

echo "Wrote {$out} (" . number_format( strlen( $zip ) ) . " bytes)\n";
