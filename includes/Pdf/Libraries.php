<?php
/**
 * Loader for the bundled, namespace-prefixed PDF libraries.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The dompdf library and its dependencies live in vendor-prefixed/ under the OrderFlowWC\Vendor\ namespace, so a copy of the
 * same libraries bundled by another plugin can never be mixed with ours. The loader answers only for that
 * namespace and is appended to the autoloader chain, never prepended.
 */
final class Libraries {

	public const NAMESPACE_PREFIX = 'OrderFlowWC\\Vendor\\';

	/**
	 * Class name to file, read from the generated class map.
	 *
	 * @var array<string, string>|null
	 */
	private static ?array $map = null;

	/**
	 * Whether the loader is registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Directory that holds the prefixed libraries.
	 */
	public static function directory(): string {
		return OFWC_PATH . 'vendor-prefixed';
	}

	/**
	 * Register the loader once and report whether the libraries are present.
	 */
	public static function load(): bool {
		if ( ! self::$registered ) {
			if ( null === self::map() ) {
				return false;
			}
			spl_autoload_register( array( self::class, 'autoload' ) );
			self::$registered = true;
		}
		return class_exists( \OrderFlowWC\Vendor\Dompdf\Dompdf::class );
	}

	/**
	 * Autoload callback.
	 *
	 * @param string $class_name Class name.
	 */
	public static function autoload( string $class_name ): void {
		if ( 0 !== strncmp( $class_name, self::NAMESPACE_PREFIX, strlen( self::NAMESPACE_PREFIX ) ) ) {
			return;
		}
		$map = self::map();
		if ( null !== $map && isset( $map[ $class_name ] ) && is_readable( $map[ $class_name ] ) ) {
			require_once $map[ $class_name ];
		}
	}

	/**
	 * Class map with absolute paths, or null when the libraries are not installed.
	 *
	 * @return array<string, string>|null
	 */
	private static function map(): ?array {
		if ( null !== self::$map ) {
			return self::$map;
		}
		$file = self::directory() . '/composer/autoload_classmap.php';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$raw = include $file;
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$map = array();
		foreach ( $raw as $name => $path ) {
			if ( is_string( $name ) && is_string( $path ) ) {
				$map[ $name ] = $path;
			}
		}
		self::$map = $map;
		return $map;
	}
}
