<?php
/**
 * Statuses tab screens.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use OrderFlowWC\Admin\StatusesTab;
use OrderFlowWC\Tests\Unit\TestCase;

/**
 * The list, the form and the delete screen render without a notice, a warning or a deprecation.
 *
 * @covers \OrderFlowWC\Admin\StatusesTab
 * @covers \OrderFlowWC\Admin\StatusList
 * @covers \OrderFlowWC\Admin\StatusForm
 * @covers \OrderFlowWC\Admin\StatusDelete
 * @covers \OrderFlowWC\Admin\FormFields
 *
 * Separate processes: the test defines wc_get_order_statuses(), which would otherwise leak into
 * Repository tests that rely on the function being absent.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class StatusScreensTest extends TestCase {

	/**
	 * Request data of the tab.
	 *
	 * @var array<string, string>
	 */
	private array $query = array();

	/**
	 * Stub the WordPress output helpers and make every PHP diagnostic an exception.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'sanitize_key' )->alias( 'strtolower' );
		Functions\when( 'sanitize_html_class' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ): string => 'https://shop.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ): string => $url . '?' . http_build_query( $args ) );
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->alias(
			static function ( string $text ): void {
				echo '<input type="submit" value="' . $text . '">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test double.
			}
		);
		Functions\when( 'checked' )->alias(
			static function ( $current, $value = true ): string {
				return (string) $current === (string) $value ? ' checked' : '';
			}
		);
		Functions\when( 'number_format_i18n' )->returnArg();
		Functions\when( '_n' )->alias( static fn( string $one, string $many, int $n ): string => 1 === $n ? $one : $many );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-processing' => 'Processing',
				'wc-proof'      => 'Awaiting proof',
			)
		);
		Functions\when( 'wc_get_orders' )->justReturn( (object) array( 'total' => 3 ) );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wp_count_posts' )->justReturn( (object) array() );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $fallback = false ) {
				return 'ofwc_statuses' === $name
					? array(
						'proof' => array(
							'slug'            => 'proof',
							'label'           => 'Awaiting proof',
							'show_in_account' => false,
						),
					)
					: $fallback;
			}
		);
	}

	/**
	 * Render one view and fail on any PHP diagnostic.
	 *
	 * @param string $view  View name.
	 * @param string $slug  Status slug.
	 * @return string Output.
	 */
	private function screen( string $view, string $slug = '' ): string {
		$_GET = array(
			'view'   => $view,
			'status' => $slug,
		);
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Turns every diagnostic into a test failure.
			static function ( int $no, string $message ): bool {
				throw new \ErrorException( $message, 0, $no ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test.
			}
		);
		ob_start();
		try {
			( new StatusesTab() )->render();
			return (string) ob_get_contents();
		} finally {
			ob_end_clean();
			restore_error_handler();
			$_GET = array();
		}
	}

	/**
	 * Add form: general, emails and PDF sections, the placeholder help, the submit button.
	 */
	public function test_add_form_renders(): void {
		$html = $this->screen( 'add' );

		$this->assertStringContainsString( '<h3>General</h3>', $html );
		$this->assertStringContainsString( '<h3>Emails</h3>', $html );
		$this->assertStringContainsString( '<h3>PDF document</h3>', $html );
		$this->assertStringContainsString( 'Placeholders: {order_number}', $html );
		$this->assertStringContainsString( 'name="action" value="ofwc_save_status"', $html );
	}

	/**
	 * Edit form for an existing status.
	 */
	public function test_edit_form_renders(): void {
		$html = $this->screen( 'edit', 'proof' );

		$this->assertStringContainsString( 'value="Awaiting proof"', $html );
		$this->assertStringContainsString( 'readonly', $html );
	}

	/**
	 * The list carries a swatch class instead of an inline style, and the prefixed slug.
	 */
	public function test_list_renders_without_inline_styles(): void {
		$html = $this->screen( 'list' );

		$this->assertStringContainsString( 'ofwc-swatch ofwc-swatch-proof', $html );
		$this->assertStringContainsString( '<code>wc-proof</code>', $html );
		$this->assertStringNotContainsString( 'style=', $html );
	}

	/**
	 * Delete screen with orders: where they go, what happens, the customer notice choice (off).
	 */
	public function test_delete_screen_lists_the_effects_and_offers_the_notice_choice(): void {
		$html = $this->screen( 'delete', 'proof' );

		$this->assertStringContainsString( 'Move orders to', $html );
		$this->assertStringContainsString( 'What happens', $html );
		$this->assertStringContainsString( 'Stock is not changed', $html );
		$this->assertStringContainsString( 'name="notify_customers"', $html );
		$this->assertStringNotContainsString( 'name="notify_customers" value="1" checked', $html );
	}
}
