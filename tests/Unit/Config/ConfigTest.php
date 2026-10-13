<?php
/**
 * Exporter and importer tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Config;

use Brain\Monkey\Functions;
use OrderFlowWC\Config\Exporter;
use OrderFlowWC\Config\ImportResult;
use OrderFlowWC\Config\Importer;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;
use OrderFlowWC\Transitions\Rules;

/**
 * Export shape, merge, replace, unknown roles and idempotence.
 *
 * @covers \OrderFlowWC\Config\Exporter
 * @covers \OrderFlowWC\Config\Importer
 * @covers \OrderFlowWC\Config\ImportResult
 *
 * Separate processes: the test defines wc_get_order_statuses(), which would otherwise leak into
 * Repository tests that rely on the function being absent.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ConfigTest extends TestCase {

	/**
	 * In-memory options.
	 *
	 * @var array<string, mixed>
	 */
	private array $options = array();

	/**
	 * Order counts by slug.
	 *
	 * @var array<string, int>
	 */
	private array $order_counts = array();

	/**
	 * Stub options, roles, order statuses and order counts.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->options      = array();
		$this->order_counts = array();

		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( string $name, $value ): bool {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static function ( string $key ): string {
				return (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); // Like core, "*" becomes empty.
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data, int $flags = 0 ) {
				return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test double.
			}
		);
		Functions\when( 'wp_roles' )->alias(
			static function (): object {
				return new class() {
					/**
					 * Role names.
					 *
					 * @return array<string, string>
					 */
					public function get_names(): array {
						return array(
							'administrator' => 'Administrator',
							'shop_manager'  => 'Shop manager',
						);
					}
				};
			}
		);
		Functions\when( 'wc_get_order_statuses' )->alias(
			function (): array {
				$statuses = array(
					'wc-pending'    => 'Pending',
					'wc-processing' => 'Processing',
					'wc-on-hold'    => 'On hold',
				);
				$stored   = $this->options['ofwc_statuses'] ?? array();
				foreach ( is_array( $stored ) ? array_keys( $stored ) : array() as $slug ) {
					$statuses[ 'wc-' . $slug ] = (string) $slug;
				}
				return $statuses;
			}
		);
		Functions\when( 'wc_get_orders' )->alias(
			function ( array $args ): object {
				$slug = substr( (string) ( $args['status'] ?? '' ), 3 );
				return (object) array( 'total' => $this->order_counts[ $slug ] ?? 0 );
			}
		);
	}

	/**
	 * Seed statuses and rules.
	 */
	private function seed(): void {
		$repo = new Repository();
		$repo->save(
			array(
				'slug'  => 'proof',
				'label' => 'Proof sent',
				'color' => '#112233',
				'paid'  => true,
			)
		);
		$repo->save(
			array(
				'slug'  => 'printing',
				'label' => 'Printing',
			)
		);
		( new Rules( $repo ) )->save_all(
			array(
				array(
					'from'            => 'proof',
					'to'              => 'printing',
					'roles'           => array( 'shop_manager' ),
					'require_comment' => true,
				),
				array(
					'from'  => '*',
					'to'    => 'proof',
					'roles' => array( 'administrator' ),
				),
			)
		);
		$this->options['ofwc_settings'] = array(
			'logo_id'       => 77,
			'store_details' => 'Acme Ltd',
		);
	}

	/**
	 * Wipe the data, as another site would have it.
	 */
	private function wipe(): void {
		$this->options = array();
		Repository::flush_cache();
	}

	/**
	 * The document has the documented shape and no logo.
	 */
	public function test_export_shape(): void {
		$this->seed();
		$doc = ( new Exporter() )->build( '2026-10-12T10:00:00+00:00' );

		$this->assertSame( 'order-flow-for-woocommerce', $doc['format'] );
		$this->assertSame( 1, $doc['version'] );
		$this->assertSame( '0.1.0', $doc['plugin_version'] );
		$this->assertSame( '2026-10-12T10:00:00+00:00', $doc['exported_at'] );
		$this->assertCount( 2, $doc['statuses'] );
		$this->assertCount( 2, $doc['transitions'] );
		$this->assertSame( array( 'store_details' => 'Acme Ltd' ), $doc['settings'] );
		$this->assertStringNotContainsString( 'logo_id', (string) json_encode( $doc ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test.
	}

	/**
	 * File names are safe and predictable.
	 */
	public function test_filename(): void {
		$this->assertSame( 'ofwc-config-shop.example.com-2026-10-12.json', Exporter::filename( 'Shop.Example.com', '2026-10-12' ) );
		$this->assertSame( 'ofwc-config-site-2026-10-12.json', Exporter::filename( '', '2026-10-12' ) );
	}

	/**
	 * Export, wipe, import, export gives identical statuses and transitions.
	 */
	public function test_round_trip_is_identical(): void {
		$this->seed();
		$first = ( new Exporter() )->build( 'x' );
		$json  = ( new Exporter() )->to_json( 'x' );

		$this->wipe();
		$result = ( new Importer() )->import_json( $json, Importer::MODE_REPLACE );
		$this->assertInstanceOf( ImportResult::class, $result );
		$this->assertSame( array( 'proof', 'printing' ), $result->added );

		$second = ( new Exporter() )->build( 'x' );
		$this->assertSame( $first['statuses'], $second['statuses'] );
		$this->assertSame( $first['transitions'], $second['transitions'] );
		$this->assertSame( 'Acme Ltd', $second['settings']['store_details'] );

		// Idempotent: a second import changes nothing.
		$again = ( new Importer() )->import_json( $json, Importer::MODE_REPLACE );
		$this->assertInstanceOf( ImportResult::class, $again );
		$this->assertSame( array(), $again->added );
		$this->assertSame( array(), $again->updated );
		$this->assertSame( array( 'proof', 'printing' ), $again->unchanged );
		$this->assertFalse( $again->changed() );
		$third = ( new Exporter() )->build( 'x' );
		$this->assertSame( $first['statuses'], $third['statuses'] );
		$this->assertSame( $first['transitions'], $third['transitions'] );
	}

	/**
	 * Merge keeps statuses that are not in the file and updates matches by slug.
	 */
	public function test_merge_adds_and_updates(): void {
		$this->seed();
		$doc    = array(
			'format'   => 'order-flow-for-woocommerce',
			'version'  => 1,
			'statuses' => array(
				array(
					'slug'  => 'proof',
					'label' => 'Proof ready',
				),
				array(
					'slug'  => 'shipped-ext',
					'label' => 'Shipped',
				),
			),
		);
		$result = ( new Importer() )->import( $doc, Importer::MODE_MERGE );

		$this->assertInstanceOf( ImportResult::class, $result );
		$this->assertSame( array( 'shipped-ext' ), $result->added );
		$this->assertSame( array( 'proof' ), $result->updated );
		$this->assertSame( array(), $result->removed );

		$repo = new Repository();
		Repository::flush_cache();
		$this->assertSame( 'Proof ready', $repo->get( 'proof' )['label'] ?? '' );
		$this->assertNotNull( $repo->get( 'printing' ) );
		$this->assertSame( 2, $result->rules_saved, 'Existing rules stay in merge mode' );
	}

	/**
	 * Replace removes empty statuses and keeps the ones that hold orders.
	 */
	public function test_replace_removes_only_statuses_without_orders(): void {
		$this->seed();
		$this->order_counts = array( 'printing' => 3 );
		$doc                = array(
			'format'   => 'order-flow-for-woocommerce',
			'version'  => 1,
			'statuses' => array(
				array(
					'slug'  => 'brand-new',
					'label' => 'Brand new',
				),
			),
		);
		$result             = ( new Importer() )->import( $doc, Importer::MODE_REPLACE );

		$this->assertInstanceOf( ImportResult::class, $result );
		$this->assertSame( array( 'brand-new' ), $result->added );
		$this->assertSame( array( 'proof' ), $result->removed );
		$this->assertSame( array( 'printing' => 3 ), $result->kept );

		Repository::flush_cache();
		$repo = new Repository();
		$this->assertNull( $repo->get( 'proof' ) );
		$this->assertNotNull( $repo->get( 'printing' ) );
		$this->assertStringContainsString( 'printing (3)', $result->summary() );
	}

	/**
	 * Roles missing on this site are dropped and reported.
	 */
	public function test_unknown_roles_are_dropped(): void {
		$doc    = array(
			'format'      => 'order-flow-for-woocommerce',
			'version'     => 1,
			'statuses'    => array(
				array(
					'slug'  => 'a-one',
					'label' => 'A one',
				),
				array(
					'slug'  => 'b-two',
					'label' => 'B two',
				),
			),
			'transitions' => array(
				array(
					'from'  => 'a-one',
					'to'    => 'b-two',
					'roles' => array( 'shop_manager', 'warehouse_staff', 'designer' ),
				),
				array(
					'from'  => 'ghost',
					'to'    => 'b-two',
					'roles' => array( 'shop_manager' ),
				),
			),
		);
		$result = ( new Importer() )->import( $doc, Importer::MODE_MERGE );

		$this->assertInstanceOf( ImportResult::class, $result );
		$this->assertSame( array( 'warehouse_staff', 'designer' ), $result->dropped_roles );
		$this->assertSame( 1, $result->rules_saved );
		$this->assertSame( 1, $result->rules_skipped );
		$rules = ( new Rules() )->all();
		$this->assertSame( array( 'shop_manager' ), $rules[0]['roles'] );
		$this->assertStringContainsString( 'warehouse_staff', $result->summary() );
	}

	/**
	 * Invalid statuses are skipped with a reason and valid ones still import.
	 */
	public function test_invalid_entries_are_skipped(): void {
		$doc    = array(
			'format'   => 'order-flow-for-woocommerce',
			'version'  => 1,
			'statuses' => array(
				array(
					'slug'  => 'processing',
					'label' => 'Core clash',
				),
				'garbage',
				array(
					'slug'  => 'ok-one',
					'label' => 'Fine',
				),
				array(
					'slug'  => 'ok-one',
					'label' => 'Again',
				),
				array(
					'slug'  => 'no-label',
					'label' => '',
				),
			),
		);
		$result = ( new Importer() )->import( $doc, Importer::MODE_MERGE );

		$this->assertInstanceOf( ImportResult::class, $result );
		$this->assertSame( array( 'ok-one' ), $result->added );
		$this->assertSame( array( 'processing', '#2', 'ok-one', 'no-label' ), array_keys( $result->skipped ) );
	}

	/**
	 * Envelope checks.
	 */
	public function test_rejects_bad_documents(): void {
		$importer = new Importer();
		$cases    = array(
			'not json'     => array( 'nope', 'ofwc_import_json' ),
			'wrong format' => array( '{"format":"other","version":1}', 'ofwc_import_format' ),
			'future'       => array( '{"format":"order-flow-for-woocommerce","version":9}', 'ofwc_import_version' ),
			'bad statuses' => array( '{"format":"order-flow-for-woocommerce","version":1,"statuses":"x"}', 'ofwc_import_statuses' ),
			'too big'      => array( str_repeat( 'a', Importer::MAX_BYTES + 1 ), 'ofwc_import_size' ),
		);
		foreach ( $cases as $label => $case ) {
			$result = $importer->import_json( $case[0], Importer::MODE_MERGE );
			$this->assertInstanceOf( \WP_Error::class, $result, $label );
			$this->assertSame( $case[1], $result->get_error_code(), $label );
		}
		$mode = $importer->import_json( '{"format":"order-flow-for-woocommerce","version":1}', 'wipe' );
		$this->assertInstanceOf( \WP_Error::class, $mode );
		$this->assertSame( 'ofwc_import_mode', $mode->get_error_code() );
	}

	/**
	 * Replace mode with a file that has no statuses section leaves every status alone.
	 */
	public function test_replace_without_statuses_section_keeps_statuses(): void {
		$this->seed();
		$doc    = array(
			'format'      => 'order-flow-for-woocommerce',
			'version'     => 1,
			'transitions' => array(),
		);
		$result = ( new Importer() )->import( $doc, Importer::MODE_REPLACE );

		$this->assertInstanceOf( ImportResult::class, $result );
		$this->assertSame( array(), $result->removed );
		Repository::flush_cache();
		$repo = new Repository();
		$this->assertNotNull( $repo->get( 'proof' ) );
		$this->assertNotNull( $repo->get( 'printing' ) );
	}

	/**
	 * Replace mode with a file that has no transitions section leaves every rule alone; an empty section clears them.
	 */
	public function test_replace_without_transitions_section_keeps_rules(): void {
		$this->seed();
		$doc    = array(
			'format'   => 'order-flow-for-woocommerce',
			'version'  => 1,
			'statuses' => array(
				array(
					'slug'  => 'proof',
					'label' => 'Proof sent',
				),
				array(
					'slug'  => 'printing',
					'label' => 'Printing',
				),
			),
		);
		$result = ( new Importer() )->import( $doc, Importer::MODE_REPLACE );

		$this->assertInstanceOf( ImportResult::class, $result );
		$this->assertSame( 2, $result->rules_saved );
		$this->assertCount( 2, ( new Rules() )->all() );

		$doc['transitions'] = array();
		( new Importer() )->import( $doc, Importer::MODE_REPLACE );
		$this->assertCount( 0, ( new Rules() )->all(), 'An explicit empty section replaces the rules' );
	}

	/**
	 * A status text longer than the limit is skipped with a reason; so is a huge store details text.
	 */
	public function test_import_refuses_oversized_texts(): void {
		$doc    = array(
			'format'   => 'order-flow-for-woocommerce',
			'version'  => 1,
			'statuses' => array(
				array(
					'slug'  => 'big-one',
					'label' => str_repeat( 'L', 900000 ),
				),
				array(
					'slug'  => 'fine-one',
					'label' => 'Fine',
				),
			),
			'settings' => array( 'store_details' => str_repeat( 'D', 200000 ) ),
		);
		$result = ( new Importer() )->import( $doc, Importer::MODE_MERGE );

		$this->assertInstanceOf( ImportResult::class, $result );
		$this->assertSame( array( 'fine-one' ), $result->added );
		$this->assertArrayHasKey( 'big-one', $result->skipped );
		$this->assertArrayHasKey( 'store_details', $result->skipped );
		$this->assertFalse( $result->settings_updated );
	}
}
