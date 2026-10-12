<?php
/**
 * Applies a configuration file to the site.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Config;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Transitions\Rules;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import modes:
 *
 * - merge: statuses in the file are added or, matched by slug, updated. Nothing else is removed.
 *   Transition rules from the file are merged into the existing ones (same from and to: the file wins).
 * - replace: as merge, and statuses missing from the file are removed, but only when no order is in
 *   them; otherwise they stay and are reported. Transition rules are replaced by the file's.
 *
 * Every status passes Repository::sanitize() and validate(), every rule passes Rules::sanitize().
 * Importing the same file twice changes nothing.
 */
final class Importer {

	public const MODE_MERGE   = 'merge';
	public const MODE_REPLACE = 'replace';
	public const MAX_BYTES    = 1048576;
	public const MAX_STATUSES = 200;

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
	 * Parse and import a JSON document.
	 *
	 * @param string $json Raw JSON.
	 * @param string $mode One of the MODE_ constants.
	 * @return ImportResult|WP_Error
	 */
	public function import_json( string $json, string $mode ) {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new WP_Error( 'ofwc_import_size', __( 'The file is larger than 1 MB.', 'order-flow-for-woocommerce' ) );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'ofwc_import_json', __( 'The file is not valid JSON.', 'order-flow-for-woocommerce' ) );
		}
		return $this->import( $data, $mode );
	}

	/**
	 * Import a decoded document.
	 *
	 * @param array<mixed> $data Decoded document.
	 * @param string       $mode One of the MODE_ constants.
	 * @return ImportResult|WP_Error
	 */
	public function import( array $data, string $mode ) {
		$checked = $this->check( $data, $mode );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$result   = new ImportResult();
		$replace  = self::MODE_REPLACE === $mode;
		$in_file  = array();
		$incoming = isset( $data['statuses'] ) && is_array( $data['statuses'] ) ? array_values( $data['statuses'] ) : array();

		foreach ( $incoming as $index => $row ) {
			$this->import_status( $row, (int) $index, $result, $in_file );
		}

		if ( $replace ) {
			$this->remove_missing( $in_file, $result );
		}

		$this->import_transitions( isset( $data['transitions'] ) && is_array( $data['transitions'] ) ? $data['transitions'] : array(), $replace, $result );

		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) && isset( $data['settings']['store_details'] ) && is_string( $data['settings']['store_details'] ) ) {
			$this->import_settings( $data['settings']['store_details'], $replace, $result );
		}

		Repository::flush_cache();
		return $result;
	}

	/**
	 * Validate the document envelope.
	 *
	 * @param array<mixed> $data Decoded document.
	 * @param string       $mode Import mode.
	 * @return true|WP_Error
	 */
	private function check( array $data, string $mode ) {
		if ( self::MODE_MERGE !== $mode && self::MODE_REPLACE !== $mode ) {
			return new WP_Error( 'ofwc_import_mode', __( 'Unknown import mode.', 'order-flow-for-woocommerce' ) );
		}
		if ( ( $data['format'] ?? '' ) !== Exporter::FORMAT ) {
			return new WP_Error( 'ofwc_import_format', __( 'This file is not an Order Flow export.', 'order-flow-for-woocommerce' ) );
		}
		$version = $data['version'] ?? 0;
		if ( ! is_int( $version ) || $version < 1 || $version > Exporter::VERSION ) {
			return new WP_Error( 'ofwc_import_version', __( 'This export was made by a newer version of the plugin. Update the plugin and try again.', 'order-flow-for-woocommerce' ) );
		}
		if ( isset( $data['statuses'] ) && ( ! is_array( $data['statuses'] ) || count( $data['statuses'] ) > self::MAX_STATUSES ) ) {
			return new WP_Error( 'ofwc_import_statuses', __( 'The statuses section is invalid or too large.', 'order-flow-for-woocommerce' ) );
		}
		return true;
	}

	/**
	 * Add or update one status.
	 *
	 * @param mixed               $row     Entry from the file.
	 * @param int                 $index   Position in the file.
	 * @param ImportResult        $result  Result collector.
	 * @param array<string, true> $in_file Slugs seen in the file (filled here).
	 */
	private function import_status( $row, int $index, ImportResult $result, array &$in_file ): void {
		if ( ! is_array( $row ) ) {
			$result->skipped[ '#' . ( $index + 1 ) ] = __( 'not an object', 'order-flow-for-woocommerce' );
			return;
		}
		$clean = $this->repository->sanitize( $row );
		$slug  = (string) $clean['slug'];
		$key   = '' === $slug ? '#' . ( $index + 1 ) : $slug;

		if ( isset( $in_file[ $slug ] ) && '' !== $slug ) {
			$result->skipped[ $key ] = __( 'listed twice', 'order-flow-for-woocommerce' );
			return;
		}

		$existing = '' === $slug ? null : $this->repository->get( $slug );
		if ( null !== $existing && $existing === $clean ) {
			$in_file[ $slug ]    = true;
			$result->unchanged[] = $slug;
			return;
		}

		$saved = $this->repository->save( $row, null === $existing ? null : $slug );
		if ( is_wp_error( $saved ) ) {
			$result->skipped[ $key ] = $saved->get_error_message();
			return;
		}
		$in_file[ $slug ] = true;
		if ( null === $existing ) {
			$result->added[] = $slug;
		} else {
			$result->updated[] = $slug;
		}
	}

	/**
	 * Replace mode: drop statuses that are not in the file and hold no orders.
	 *
	 * @param array<string, true> $in_file Slugs present in the file.
	 * @param ImportResult        $result  Result collector.
	 */
	private function remove_missing( array $in_file, ImportResult $result ): void {
		foreach ( array_keys( $this->repository->all() ) as $slug ) {
			if ( isset( $in_file[ $slug ] ) ) {
				continue;
			}
			// A slug the file listed but that failed validation must not be deleted.
			if ( isset( $result->skipped[ $slug ] ) ) {
				continue;
			}
			$count = $this->repository->count_orders( $slug );
			if ( $count > 0 ) {
				$result->kept[ $slug ] = $count;
				continue;
			}
			$deleted = $this->repository->delete( $slug );
			if ( is_wp_error( $deleted ) ) {
				$result->skipped[ $slug ] = $deleted->get_error_message();
				continue;
			}
			$result->removed[] = $slug;
		}
	}

	/**
	 * Store the transition rules.
	 *
	 * @param array<mixed> $incoming Rules from the file.
	 * @param bool         $replace  Replace instead of merge.
	 * @param ImportResult $result   Result collector.
	 */
	private function import_transitions( array $incoming, bool $replace, ImportResult $result ): void {
		$known = array_keys( wp_roles()->get_names() );
		$given = array();
		$total = 0;

		foreach ( $incoming as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			++$total;
			$roles = array();
			foreach ( isset( $rule['roles'] ) && is_array( $rule['roles'] ) ? $rule['roles'] : array() as $role ) {
				if ( ! is_string( $role ) ) {
					continue;
				}
				if ( in_array( $role, $known, true ) ) {
					$roles[] = $role;
				} elseif ( ! in_array( $role, $result->dropped_roles, true ) ) {
					$result->dropped_roles[] = $role;
				}
			}
			$rule['roles'] = $roles;
			$given[]       = $rule;
		}

		$clean = $this->rules->sanitize( $given );
		if ( $replace ) {
			$final = $clean;
		} else {
			$final = array();
			foreach ( $this->rules->all() as $rule ) {
				$final[ $rule['from'] . '>' . $rule['to'] ] = $rule;
			}
			foreach ( $clean as $rule ) {
				$final[ $rule['from'] . '>' . $rule['to'] ] = $rule;
			}
			$final = array_values( $final );
		}

		$this->rules->save_all( $final );
		$result->rules_saved   = count( $final );
		$result->rules_skipped = max( 0, $total - count( $clean ) );
	}

	/**
	 * Store the store-details text, leaving the logo alone.
	 *
	 * @param string       $details Text from the file.
	 * @param bool         $replace Replace mode writes empty text too.
	 * @param ImportResult $result  Result collector.
	 */
	private function import_settings( string $details, bool $replace, ImportResult $result ): void {
		if ( '' === $details && ! $replace ) {
			return;
		}
		$settings = get_option( 'ofwc_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$clean    = wp_kses_post( $details );
		if ( ( $settings['store_details'] ?? '' ) === $clean ) {
			return;
		}
		$settings['store_details'] = $clean;
		update_option( 'ofwc_settings', $settings, true );
		$result->settings_updated = true;
	}
}
