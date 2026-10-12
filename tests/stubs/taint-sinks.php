<?php
/**
 * Psalm taint annotations for WordPress database sinks that the WordPress stubs leave unannotated.
 *
 * Loaded only by Psalm. Never shipped.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

// phpcs:ignoreFile -- Static analysis stub, not runtime code.

/**
 * Stub of wpdb with SQL taint sinks.
 */
class wpdb {
	/**
	 * @psalm-taint-sink sql $query
	 * @param string $query Query.
	 * @return int|bool
	 */
	public function query( $query ) {}

	/**
	 * @psalm-taint-sink sql $query
	 * @param string|null $query Query.
	 * @param string      $output Output.
	 * @return array|object|null
	 */
	public function get_results( $query = null, $output = OBJECT ) {}

	/**
	 * @psalm-taint-sink sql $query
	 * @param string|null $query Query.
	 * @param int         $x Column.
	 * @param int         $y Row.
	 * @return string|null
	 */
	public function get_var( $query = null, $x = 0, $y = 0 ) {}

	/**
	 * @psalm-taint-sink sql $query
	 * @param string|null $query Query.
	 * @param string      $output Output.
	 * @param int         $y Row.
	 * @return array|object|null
	 */
	public function get_row( $query = null, $output = OBJECT, $y = 0 ) {}

	/**
	 * @psalm-taint-sink sql $query
	 * @param string|null $query Query.
	 * @param int         $x Column.
	 * @return array
	 */
	public function get_col( $query = null, $x = 0 ) {}

	/**
	 * @psalm-taint-sink sql $query
	 * @psalm-taint-escape sql
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Values.
	 * @return string|void
	 */
	public function prepare( $query, ...$args ) {}
}

/**
 * @psalm-flow ($value) -> return
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {}

/**
 * @psalm-flow ($value) -> return
 * @param mixed $value Value.
 * @return mixed
 */
function stripslashes_deep( $value ) {}

/**
 * @psalm-taint-sink header $location
 * @param string $location URL.
 * @param int    $status Status.
 * @param string $x_redirect_by By.
 * @return bool
 */
function wp_redirect( $location, $status = 302, $x_redirect_by = 'WordPress' ) {}

/**
 * @psalm-taint-sink ssrf $url
 * @param string $url URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_remote_get( $url, $args = array() ) {}

/**
 * @psalm-taint-sink ssrf $url
 * @param string $url URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_remote_head( $url, $args = array() ) {}

/**
 * @psalm-taint-sink ssrf $url
 * @param string $url URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_remote_request( $url, $args = array() ) {}

/**
 * @psalm-taint-sink ssrf $url
 * @param string $url URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_safe_remote_get( $url, $args = array() ) {}

/**
 * @psalm-taint-sink ssrf $url
 * @param string $url URL.
 * @param array  $args Args.
 * @return array|WP_Error
 */
function wp_safe_remote_head( $url, $args = array() ) {}
