<?php
/**
 * Compatibility matrix helper (development only, not shipped).
 *
 * php bin/matrix-validate.php plugincheck <plugin-check.csv>
 */

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '';

if ( 'plugincheck' === $mode ) {
	$errors   = 0;
	$warnings = 0;
	$agg      = array();
	if ( ! is_file( $file ) ) {
		fwrite( STDERR, "no csv\n" );
		exit( 2 );
	}
	$fh     = fopen( $file, 'r' );
	$header = fgetcsv( $fh, 0, ',', '"', '' );
	while ( false !== ( $row = fgetcsv( $fh, 0, ',', '"', '' ) ) ) {
		if ( ! is_array( $header ) || count( $row ) !== count( $header ) ) {
			continue;
		}
		$r = array_combine( $header, $row );
		if ( 'ERROR' === ( $r['type'] ?? '' ) ) {
			++$errors;
		} elseif ( 'WARNING' === ( $r['type'] ?? '' ) ) {
			++$warnings;
		}
		$key = ( $r['type'] ?? '' ) . ' ' . ( $r['code'] ?? '' ) . ' | ' . ( $r['file'] ?? '' ) . ':' . ( $r['line'] ?? '' ) . ' | ' . preg_replace( '/\s+/', ' ', (string) ( $r['message'] ?? '' ) );
		$agg[] = $key;
	}
	fclose( $fh );
	file_put_contents( $file . '.summary.txt', implode( "\n", $agg ) . "\n" );
	echo "errors=$errors warnings=$warnings\n";
	exit( 0 );
}

fwrite( STDERR, "usage: matrix-validate.php plugincheck <file>\n" );
exit( 64 );
