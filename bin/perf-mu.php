<?php
/**
 * Plugin Name: Order Flow perf harness (development only, not shipped)
 *
 * Signs a request in as a chosen user (header X-Perf-User) and measures it: time, peak memory, queries, fatal errors,
 * and with SAVEQUERIES the slowest query, repeated query shapes and EXPLAIN. The result goes to the file named in the
 * header X-Perf-Out (or the environment variable PERF_OUT for WP-CLI processes). A few read-only helper answers are
 * available under ?perf=nonce|order|urls.
 */

$ofp_user = isset( $_SERVER['HTTP_X_PERF_USER'] ) ? (int) $_SERVER['HTTP_X_PERF_USER'] : (int) getenv( 'PERF_USER' );
if ( $ofp_user > 0 ) {
	add_filter(
		'determine_current_user',
		static function () use ( $ofp_user ) {
			return $ofp_user;
		},
		99
	);
	// wp-admin pages ask wp_validate_auth_cookie(); a request that carries the header is treated as signed in.
	if ( ! function_exists( 'wp_validate_auth_cookie' ) ) {
		/**
		 * Signed-in user of the request.
		 *
		 * @param string $cookie Unused.
		 * @param string $scheme Unused.
		 * @return int
		 */
		function wp_validate_auth_cookie( $cookie = '', $scheme = '' ) { // phpcs:ignore
			unset( $cookie, $scheme );
			return isset( $_SERVER['HTTP_X_PERF_USER'] ) ? (int) $_SERVER['HTTP_X_PERF_USER'] : (int) getenv( 'PERF_USER' );
		}
	}
}

$ofp_out = isset( $_SERVER['HTTP_X_PERF_OUT'] ) ? (string) $_SERVER['HTTP_X_PERF_OUT'] : (string) getenv( 'PERF_OUT' );
if ( '' !== $ofp_out ) {
	$ofp_t0    = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
	$ofp_saveq = defined( 'SAVEQUERIES' ) && SAVEQUERIES;
	$ofp_boot  = microtime( true );
	register_shutdown_function(
		static function () use ( $ofp_out, $ofp_t0, $ofp_boot, $ofp_saveq ): void {
			$err   = error_get_last();
			$fatal = null;
			if ( $err && in_array( $err['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ), true ) ) {
				$fatal = $err;
				@ini_set( 'memory_limit', '512M' ); // phpcs:ignore
				@set_time_limit( 120 ); // phpcs:ignore
			}
			$m = array(
				'wall_ms'      => round( ( microtime( true ) - $ofp_t0 ) * 1000, 1 ),
				'plugins_ms'   => round( ( microtime( true ) - $ofp_boot ) * 1000, 1 ),
				'peak_bytes'   => memory_get_peak_usage( true ),
				'peak_used'    => memory_get_peak_usage( false ),
				'memory_limit' => ini_get( 'memory_limit' ),
				'max_exec'     => ini_get( 'max_execution_time' ),
				'sapi'         => PHP_SAPI,
			);
			if ( $fatal ) {
				$msg                = (string) $fatal['message'];
				$m['fatal']         = substr( $msg, 0, 300 );
				$m['fatal_kind']    = false !== stripos( $msg, 'Allowed memory size' ) ? 'memory' : ( false !== stripos( $msg, 'Maximum execution time' ) ? 'timeout' : 'fatal' );
				$m['fatal_where']   = ( $fatal['file'] ?? '' ) . ':' . ( $fatal['line'] ?? '' );
			}
			global $wpdb;
			if ( isset( $wpdb ) && $wpdb instanceof wpdb ) {
				$m['queries'] = (int) $wpdb->num_queries;
				if ( $ofp_saveq && ! $fatal && is_array( $wpdb->queries ) ) {
					$m['analysis'] = ofp_analyse( $wpdb, '1' === ( $_SERVER['HTTP_X_PERF_EXPLAIN'] ?? '1' ) );
				}
			}
			file_put_contents( $ofp_out, wp_json_encode( $m, JSON_PARTIAL_OUTPUT_ON_ERROR ) ); // phpcs:ignore
		}
	);
}

/**
 * Normalise a query to its shape.
 *
 * @param string $sql Query.
 */
function ofp_shape( string $sql ): string {
	$s = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
	$s = preg_replace( '/\b\d+(?:\.\d+)?\b/', '?', (string) $s );
	$s = preg_replace( '/IN\s*\((?:\s*\?\s*,?)+\)/i', 'IN (?)', (string) $s );
	$s = preg_replace( '/\s+/', ' ', (string) $s );
	return trim( (string) $s );
}

/**
 * Query analysis: slowest query, repeated shapes, EXPLAIN of every distinct SELECT.
 *
 * @param wpdb $wpdb    Database.
 * @param bool $explain Whether to run EXPLAIN.
 * @return array<string, mixed>
 */
function ofp_analyse( wpdb $wpdb, bool $explain ): array {
	$rows   = $wpdb->queries;
	$slow   = null;
	$total  = 0.0;
	$shapes = array();
	foreach ( $rows as $q ) {
		$ms     = (float) $q[1] * 1000;
		$total += $ms;
		$sh     = ofp_shape( (string) $q[0] );
		if ( ! isset( $shapes[ $sh ] ) ) {
			$shapes[ $sh ] = array( 'n' => 0, 'ms' => 0.0, 'sample' => (string) $q[0], 'caller' => (string) $q[2], 'max_ms' => 0.0 );
		}
		++$shapes[ $sh ]['n'];
		$shapes[ $sh ]['ms'] += $ms;
		if ( $ms > $shapes[ $sh ]['max_ms'] ) {
			$shapes[ $sh ]['max_ms'] = $ms;
			$shapes[ $sh ]['sample'] = (string) $q[0];
			$shapes[ $sh ]['caller'] = (string) $q[2];
		}
		if ( null === $slow || $ms > $slow['ms'] ) {
			$slow = array( 'ms' => round( $ms, 2 ), 'sql' => substr( (string) $q[0], 0, 700 ), 'caller' => (string) $q[2] );
		}
	}
	$res = array(
		'count'    => count( $rows ),
		'sql_ms'   => round( $total, 1 ),
		'slowest'  => $slow,
		'shapes'   => count( $shapes ),
		'n_plus_1' => array(),
		'explain'  => array(),
	);
	foreach ( $shapes as $d ) {
		if ( $d['n'] > 10 ) {
			$res['n_plus_1'][] = array( 'n' => $d['n'], 'ms' => round( $d['ms'], 1 ), 'sql' => substr( $d['sample'], 0, 400 ), 'caller' => $d['caller'] );
		}
	}
	usort( $res['n_plus_1'], static fn( $a, $b ) => $b['n'] <=> $a['n'] );
	if ( ! $explain ) {
		return $res;
	}
	$tbl_rows = array();
	$wpdb->suppress_errors( true );
	foreach ( $shapes as $d ) {
		$sql = ltrim( $d['sample'] );
		if ( 0 !== stripos( $sql, 'SELECT' ) && 0 !== stripos( $sql, 'WITH' ) ) {
			continue;
		}
		$plan = $wpdb->get_results( 'EXPLAIN ' . $sql, ARRAY_A ); // phpcs:ignore
		if ( ! is_array( $plan ) ) {
			continue;
		}
		foreach ( $plan as $p ) {
			$t = (string) ( $p['table'] ?? '' );
			if ( '' === $t || '<' === $t[0] ) {
				continue;
			}
			if ( ! isset( $tbl_rows[ $t ] ) ) {
				$tbl_rows[ $t ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $t ) );
			}
			$type             = (string) ( $p['type'] ?? '' );
			$res['explain'][] = array(
				'table'    => $t,
				'type'     => $type,
				'key'      => $p['key'] ?? null,
				'rows'     => (int) ( $p['rows'] ?? 0 ),
				'tbl_rows' => $tbl_rows[ $t ],
				'extra'    => substr( (string) ( $p['Extra'] ?? '' ), 0, 120 ),
				'flag'     => ( 'ALL' === $type && $tbl_rows[ $t ] > 10000 ) ? 'full_scan' : '',
				'n'        => $d['n'],
				'ms'       => round( $d['max_ms'], 2 ),
				'sql'      => substr( $sql, 0, 600 ),
				'caller'   => $d['caller'],
			);
		}
	}
	$wpdb->suppress_errors( false );
	return $res;
}

// Read-only helpers for the driver.
add_action(
	'init',
	static function (): void {
		if ( ! isset( $_GET['perf'] ) || ! is_string( $_GET['perf'] ) ) { // phpcs:ignore
			return;
		}
		$what = sanitize_key( wp_unslash( $_GET['perf'] ) ); // phpcs:ignore
		$out  = array( 'user' => get_current_user_id() );
		if ( 'nonce' === $what ) {
			$out['nonce'] = wp_create_nonce( isset( $_GET['action'] ) && is_string( $_GET['action'] ) ? wp_unslash( $_GET['action'] ) : '' ); // phpcs:ignore
		} elseif ( 'order' === $what ) {
			$order         = wc_get_order( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore
			$out['status'] = $order instanceof WC_Order ? $order->get_status() : '';
		} elseif ( 'urls' === $what ) {
			$n                 = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0; // phpcs:ignore
			$out['orders']     = wc_get_endpoint_url( 'orders', $n > 0 ? (string) $n : '', wc_get_page_permalink( 'myaccount' ) );
			$out['view_order'] = wc_get_endpoint_url( 'view-order', (string) ( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ), wc_get_page_permalink( 'myaccount' ) ); // phpcs:ignore
		}
		nocache_headers();
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $out ); // phpcs:ignore
		exit;
	},
	99
);
