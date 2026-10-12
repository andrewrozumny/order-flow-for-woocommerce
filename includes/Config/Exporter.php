<?php
/**
 * Builds the portable configuration file.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Config;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Transitions\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export format (version 1):
 *
 * { format, version, plugin_version, exported_at, statuses[], transitions[], settings{store_details} }
 *
 * The logo is not exported because attachment ids differ between sites.
 */
final class Exporter {

	public const FORMAT  = 'order-flow-for-woocommerce';
	public const VERSION = 1;

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Transition rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 * @param Rules|null      $rules      Transition rules.
	 */
	public function __construct( ?Repository $repository = null, ?Rules $rules = null ) {
		$this->repository = $repository ?? new Repository();
		$this->rules      = $rules ?? new Rules( $this->repository );
	}

	/**
	 * The export document.
	 *
	 * @param string $exported_at ISO 8601 timestamp; empty uses the current time.
	 * @return array<string, mixed>
	 */
	public function build( string $exported_at = '' ): array {
		$settings = get_option( 'ofwc_settings', array() );
		$details  = is_array( $settings ) && isset( $settings['store_details'] ) && is_string( $settings['store_details'] ) ? $settings['store_details'] : '';

		return array(
			'format'         => self::FORMAT,
			'version'        => self::VERSION,
			'plugin_version' => defined( 'OFWC_VERSION' ) ? OFWC_VERSION : '',
			'exported_at'    => '' === $exported_at ? gmdate( 'c' ) : $exported_at,
			'statuses'       => array_values( $this->repository->all() ),
			'transitions'    => $this->rules->all(),
			'settings'       => array( 'store_details' => $details ),
		);
	}

	/**
	 * The export document as pretty JSON.
	 *
	 * @param string $exported_at ISO 8601 timestamp; empty uses the current time.
	 */
	public function to_json( string $exported_at = '' ): string {
		$json = wp_json_encode( $this->build( $exported_at ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * File name for a download.
	 *
	 * @param string $host Site host.
	 * @param string $date Date, e.g. 2026-10-12.
	 */
	public static function filename( string $host, string $date ): string {
		$host = trim( (string) preg_replace( '/[^a-z0-9.-]+/', '-', strtolower( $host ) ), '-.' );
		$date = (string) preg_replace( '/[^0-9-]+/', '', $date );
		return 'ofwc-config-' . ( '' === $host ? 'site' : $host ) . '-' . $date . '.json';
	}
}
