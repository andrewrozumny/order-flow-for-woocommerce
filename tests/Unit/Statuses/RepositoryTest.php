<?php
/**
 * Status repository tests.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Statuses;

use Brain\Monkey\Functions;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Tests\Unit\TestCase;
use WP_Error;

/**
 * Sanitizing, validation and storage rules.
 *
 * @covers \OrderFlowWC\Statuses\Repository
 */
final class RepositoryTest extends TestCase {

	/**
	 * A status that passes validation.
	 *
	 * @return array<string, mixed>
	 */
	private function valid(): array {
		return array(
			'slug'  => 'awaiting-proof',
			'label' => 'Awaiting proof',
		);
	}

	/**
	 * Make get_option return a stored set.
	 *
	 * @param array<string, mixed> $stored Stored statuses.
	 */
	private function stored( array $stored ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $fallback = false ) use ( $stored ) {
				return 'ofwc_statuses' === $name ? $stored : $fallback;
			}
		);
	}

	/**
	 * Sanitize fills every default field.
	 */
	public function test_sanitize_fills_defaults(): void {
		$clean = ( new Repository() )->sanitize( $this->valid() );

		$this->assertSame( 'awaiting-proof', $clean['slug'] );
		$this->assertSame( '#777777', $clean['color'] );
		$this->assertSame( 'dashicons-marker', $clean['icon'] );
		$this->assertFalse( $clean['paid'] );
		$this->assertTrue( $clean['show_in_account'] );
		$this->assertSame( '{status}', $clean['pdf_title'] );
		$this->assertNotSame( '', $clean['email_subject'] );
		$this->assertSame( array_keys( ( new Repository() )->defaults() ), array_keys( $clean ) );
	}

	/**
	 * Sanitize repairs wrong types and unknown values.
	 */
	public function test_sanitize_repairs_values(): void {
		$clean = ( new Repository() )->sanitize(
			array(
				'slug'          => ' WC-Proof ',
				'label'         => '  Proof  ',
				'color'         => 'red',
				'icon'          => 'dashicons-nope',
				'paid'          => '1',
				'position'      => '7',
				'email_subject' => '',
			)
		);

		$this->assertSame( 'proof', $clean['slug'] );
		$this->assertSame( 'Proof', $clean['label'] );
		$this->assertSame( '#777777', $clean['color'] );
		$this->assertSame( 'dashicons-marker', $clean['icon'] );
		$this->assertTrue( $clean['paid'] );
		$this->assertSame( 7, $clean['position'] );
		$this->assertSame( 'Your {site_title} order {order_number} is now {status}', $clean['email_subject'] );
	}

	/**
	 * A well-formed hex color is kept, lowercased.
	 */
	public function test_sanitize_keeps_valid_color(): void {
		$clean = ( new Repository() )->sanitize( array( 'color' => '#A1B2C3' ) );
		$this->assertSame( '#a1b2c3', $clean['color'] );
	}

	/**
	 * A valid new status passes.
	 */
	public function test_valid_status_passes(): void {
		$this->stored( array() );
		$repo = new Repository();
		$this->assertTrue( $repo->validate( $repo->sanitize( $this->valid() ) ) );
	}

	/**
	 * Slugs with bad characters are rejected.
	 */
	public function test_bad_slug_format_rejected(): void {
		$this->stored( array() );
		$repo = new Repository();
		foreach ( array( 'Bad Slug', 'under_score', '-lead', 'trail-', 'ünï' ) as $slug ) {
			$result = $repo->validate( $repo->sanitize( array_merge( $this->valid(), array( 'slug' => $slug ) ) ) );
			$this->assertInstanceOf( WP_Error::class, $result, $slug );
			$this->assertSame( 'ofwc_slug_format', $result->get_error_code(), $slug );
		}
	}

	/**
	 * A missing slug is rejected.
	 */
	public function test_empty_slug_rejected(): void {
		$this->stored( array() );
		$repo   = new Repository();
		$result = $repo->validate( $repo->sanitize( array( 'label' => 'X' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ofwc_slug_required', $result->get_error_code() );
	}

	/**
	 * Eighteen characters is one too many.
	 */
	public function test_slug_too_long_rejected(): void {
		$this->stored( array() );
		$repo = new Repository();

		$ok = $repo->validate( $repo->sanitize( array_merge( $this->valid(), array( 'slug' => str_repeat( 'a', 17 ) ) ) ) );
		$this->assertTrue( $ok );

		$result = $repo->validate( $repo->sanitize( array_merge( $this->valid(), array( 'slug' => str_repeat( 'a', 18 ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ofwc_slug_length', $result->get_error_code() );
	}

	/**
	 * Core WooCommerce slugs are reserved.
	 */
	public function test_core_slug_rejected(): void {
		$this->stored( array() );
		$repo = new Repository();
		foreach ( Repository::CORE_SLUGS as $slug ) {
			$result = $repo->validate( $repo->sanitize( array_merge( $this->valid(), array( 'slug' => $slug ) ) ) );
			$this->assertInstanceOf( WP_Error::class, $result, $slug );
			$this->assertSame( 'ofwc_slug_core', $result->get_error_code(), $slug );
		}
	}

	/**
	 * The label is required.
	 */
	public function test_label_required(): void {
		$this->stored( array() );
		$repo   = new Repository();
		$result = $repo->validate( $repo->sanitize( array( 'slug' => 'ok' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ofwc_label_required', $result->get_error_code() );
	}

	/**
	 * A second status with the same slug is rejected when creating.
	 */
	public function test_duplicate_slug_rejected(): void {
		$this->stored( array( 'awaiting-proof' => $this->valid() ) );
		$repo   = new Repository();
		$result = $repo->validate( $repo->sanitize( $this->valid() ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ofwc_slug_duplicate', $result->get_error_code() );
	}

	/**
	 * Editing keeps the slug and passes; changing the slug is refused.
	 */
	public function test_edit_rules(): void {
		$this->stored( array( 'awaiting-proof' => $this->valid() ) );
		$repo  = new Repository();
		$clean = $repo->sanitize( $this->valid() );

		$this->assertTrue( $repo->validate( $clean, 'awaiting-proof' ) );

		$renamed = $repo->sanitize( array_merge( $this->valid(), array( 'slug' => 'other' ) ) );
		$result  = $repo->validate( $renamed, 'awaiting-proof' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ofwc_slug_immutable', $result->get_error_code() );
	}

	/**
	 * Saving stores the sanitized status keyed by slug and assigns the next position.
	 */
	public function test_save_stores_status(): void {
		$this->stored(
			array(
				'first' => array_merge(
					$this->valid(),
					array(
						'slug'     => 'first',
						'position' => 4,
					)
				),
			)
		);
		Functions\expect( 'update_option' )
			->once()
			->withArgs(
				static function ( string $name, array $value, $autoload ): bool {
					return 'ofwc_statuses' === $name
						&& isset( $value['awaiting-proof'], $value['first'] )
						&& 5 === $value['awaiting-proof']['position']
						&& true === $autoload;
				}
			)
			->andReturn( true );

		$this->assertTrue( ( new Repository() )->save( $this->valid() ) );
	}

	/**
	 * Saving an invalid status returns the validation error and writes nothing.
	 */
	public function test_save_invalid_does_not_write(): void {
		$this->stored( array() );
		Functions\expect( 'update_option' )->never();

		$result = ( new Repository() )->save(
			array(
				'slug'  => 'pending',
				'label' => 'X',
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * The full list is sorted by position, then slug.
	 */
	public function test_all_sorted_by_position(): void {
		$this->stored(
			array(
				'b' => array(
					'slug'     => 'b',
					'label'    => 'B',
					'position' => 2,
				),
				'c' => array(
					'slug'     => 'c',
					'label'    => 'C',
					'position' => 1,
				),
				'a' => array(
					'slug'     => 'a',
					'label'    => 'A',
					'position' => 2,
				),
			)
		);
		$this->assertSame( array( 'c', 'a', 'b' ), array_keys( ( new Repository() )->all() ) );
	}

	/**
	 * A label becomes a valid, short slug.
	 */
	public function test_slug_from_label(): void {
		$repo = new Repository();
		$this->assertSame( 'awaiting-proof', $repo->slug_from_label( 'Awaiting Proof!' ) );
		$this->assertSame( 'a-very-long-statu', $repo->slug_from_label( 'A very long status label here' ) );
		$this->assertSame( '', $repo->slug_from_label( '!!!' ) );
	}

	/**
	 * Deleting without a target while orders exist is refused.
	 */
	public function test_delete_requires_target_when_orders_exist(): void {
		$this->stored( array( 'awaiting-proof' => $this->valid() ) );
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'total' => 3 ) );
		Functions\expect( 'update_option' )->never();

		$result = ( new Repository() )->delete( 'awaiting-proof' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ofwc_target_required', $result->get_error_code() );
	}

	/**
	 * Deleting an unused status removes it and the transition rules that mention it.
	 */
	public function test_delete_unused_status_cleans_transitions(): void {
		$rules = array(
			array(
				'from'            => 'awaiting-proof',
				'to'              => 'processing',
				'roles'           => array(),
				'require_comment' => false,
			),
			array(
				'from'            => 'processing',
				'to'              => 'completed',
				'roles'           => array(),
				'require_comment' => false,
			),
		);
		Functions\when( 'get_option' )->alias(
			function ( string $name, $fallback = false ) use ( $rules ) {
				if ( 'ofwc_transitions' === $name ) {
					return $rules;
				}
				return 'ofwc_statuses' === $name ? array( 'awaiting-proof' => $this->valid() ) : $fallback;
			}
		);
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'total' => 0 ) );
		Functions\expect( 'update_option' )->twice()->andReturn( true );

		$this->assertTrue( ( new Repository() )->delete( 'awaiting-proof' ) );
	}
}
