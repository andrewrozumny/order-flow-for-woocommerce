<?php
/**
 * Prints what a snapshot records about a PDF: page count, page sizes, font names and the text of every page.
 * Usage: php pdfinfo.php file.pdf   (needs smalot/pdfparser, installed in the clone by `bin/destructive.sh prepare`)
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.WP.GlobalVariablesOverride, Universal.Operators.DisallowShortTernary -- Command-line test helper that runs outside WordPress.

$autoload = getenv( 'PDFINFO_AUTOLOAD' ) ?: '/cache/tools/vendor/autoload.php';
require $autoload;

$file = $argv[1] ?? '';
if ( '' === $file || ! is_readable( $file ) ) {
	fwrite( STDERR, "usage: php pdfinfo.php file.pdf\n" );
	exit( 2 );
}

$bytes = (string) file_get_contents( $file );
$out   = array(
	'is_pdf' => str_starts_with( $bytes, '%PDF-' ),
	'bytes'  => strlen( $bytes ) > 0 ? 'non-empty' : 'empty',
);
try {
	$parser = new \Smalot\PdfParser\Parser();
	$pdf    = $parser->parseContent( $bytes );
	$pages  = $pdf->getPages();
	$sizes  = array();
	$text   = array();
	foreach ( $pages as $page ) {
		$details = $page->getDetails();
		$box     = $details['MediaBox'] ?? array();
		$sizes[] = array_map(
			static fn( $v ): float => round( (float) $v, 1 ),
			is_array( $box ) ? array_values( $box ) : array()
		);
		$lines   = preg_split( '/\R/', trim( (string) $page->getText() ) ) ?: array();
		$text[]  = array_values( array_filter( array_map( 'trim', $lines ), static fn( $l ): bool => '' !== $l ) );
	}
	$fonts = array();
	foreach ( $pdf->getFonts() as $font ) {
		$name           = (string) $font->getName();
		$name           = (string) preg_replace( '/^[A-Z]{6}\+/', '', $name );
		$fonts[ $name ] = true;
	}
	ksort( $fonts );
	$out['pages']      = count( $pages );
	$out['page_sizes'] = $sizes;
	$out['fonts']      = array_keys( $fonts );
	$out['text']       = $text;
} catch ( \Throwable $e ) {
	$out['error'] = get_class( $e );
}
echo json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ) . "\n";
