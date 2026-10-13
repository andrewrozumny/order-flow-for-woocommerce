<?php
/**
 * Router for PHP's built-in web server (development only, not shipped): static files as they are, PHP files
 * and everything else the way WordPress rewrite rules would, including the /sub/ prefix of a sub-directory network.
 */
$root = (string) $_SERVER['DOCUMENT_ROOT'];
$path = (string) parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$path = rawurldecode( $path );

$candidates = array( $path );
if ( preg_match( '#^/[_0-9a-zA-Z-]+(/(?:wp-(?:content|admin|includes)/.*|[^/]*\.php))$#', $path, $m ) ) {
	$candidates[] = $m[1];
}
foreach ( $candidates as $candidate ) {
	$file = $root . $candidate;
	if ( is_file( $file ) ) {
		if ( ! str_ends_with( $file, '.php' ) ) {
			return false;
		}
		$_SERVER['SCRIPT_NAME']     = $candidate;
		$_SERVER['PHP_SELF']        = $candidate;
		$_SERVER['SCRIPT_FILENAME'] = $file;
		chdir( dirname( $file ) );
		require $file;
		return true;
	}
}
if ( is_dir( $root . $path ) && is_file( $root . rtrim( $path, '/' ) . '/index.php' ) && '/' !== $path ) {
	$file = $root . rtrim( $path, '/' ) . '/index.php';
	$_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php';
	$_SERVER['PHP_SELF']    = $_SERVER['SCRIPT_NAME'];
	$_SERVER['SCRIPT_FILENAME'] = $file;
	chdir( dirname( $file ) );
	require $file;
	return true;
}
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
chdir( $root );
require $root . '/index.php';
return true;
