<?php
/**
 * Base unit test case.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit;

use Brain\Monkey\Functions;
use OrderFlowWC\Statuses\Repository;
use Yoast\WPTestUtils\BrainMonkey\TestCase as BrainMonkeyTestCase;

/**
 * Brain Monkey base with the i18n/escaping stubs every test needs.
 */
abstract class TestCase extends BrainMonkeyTestCase {

	/**
	 * Stub the WordPress helpers the plugin uses everywhere.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {
				return $thing instanceof \WP_Error;
			}
		);
		Repository::flush_cache();
	}
}
