<?php
/**
 * Transitions tab: the matrix editor.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

use OrderFlowWC\Transitions\Rules;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Edits the transition rules. Writes go through admin-post.php with a nonce and manage_woocommerce.
 */
final class TransitionsTab implements TabInterface {

	public const ACTION_SAVE = 'ofwc_save_transitions';

	/**
	 * Transition rules.
	 *
	 * @var Rules
	 */
	private Rules $rules;

	/**
	 * Constructor.
	 *
	 * @param Rules|null $rules Transition rules.
	 */
	public function __construct( ?Rules $rules = null ) {
		$this->rules = $rules ?? new Rules();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'ofwc_admin_tabs', array( $this, 'add_tab' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
	}

	/**
	 * Add this tab to the page.
	 *
	 * @param mixed $tabs Tabs registered so far.
	 * @return mixed
	 */
	public function add_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}
		$tabs[ $this->id() ] = $this;
		return $tabs;
	}

	/**
	 * Tab slug.
	 */
	public function id(): string {
		return 'transitions';
	}

	/**
	 * Tab title.
	 */
	public function label(): string {
		return __( 'Transitions', 'order-flow-for-woocommerce' );
	}

	/**
	 * Render the policy text and the rules form.
	 */
	public function render(): void {
		$statuses = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$slug              = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
			$statuses[ $slug ] = $label;
		}
		$roles = wp_roles()->get_names();

		echo '<div class="ofwc-policy">';
		echo '<p>' . esc_html__( 'Control who may move an order into or out of your custom statuses.', 'order-flow-for-woocommerce' ) . '</p>';
		echo '<ul class="ofwc-policy-list">';
		echo '<li>' . esc_html__( 'Changes between two WooCommerce statuses are never restricted.', 'order-flow-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'A change into or out of a custom status needs a matching rule that lists one of the user\'s roles.', 'order-flow-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Administrators can always make any change.', 'order-flow-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( 'Rules apply to people working in wp-admin (order screen, bulk actions, Order Flow buttons). Gateways, the REST API, cron and WP-CLI are not blocked.', 'order-flow-for-woocommerce' ) . '</li>';
		echo '<li>' . esc_html__( '"Require comment" asks for a note with the change. Bulk actions skip such orders.', 'order-flow-for-woocommerce' ) . '</li>';
		echo '</ul></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ofwc-form ofwc-rules-form">';
		wp_nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '">';

		echo '<table class="widefat striped ofwc-rules"><thead><tr>';
		foreach ( array( __( 'From', 'order-flow-for-woocommerce' ), __( 'To', 'order-flow-for-woocommerce' ), __( 'Roles', 'order-flow-for-woocommerce' ), __( 'Require comment', 'order-flow-for-woocommerce' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody class="ofwc-rules-body">';
		$index = 0;
		foreach ( $this->rules->all() as $rule ) {
			$this->render_row( (string) $index, $rule, $statuses, $roles );
			++$index;
		}
		echo '</tbody></table>';

		echo '<template class="ofwc-rule-template">';
		$this->render_row(
			'__INDEX__',
			array(
				'from'            => Rules::ANY,
				'to'              => '',
				'roles'           => array(),
				'require_comment' => false,
			),
			$statuses,
			$roles
		);
		echo '</template>';

		echo '<p><button type="button" class="button ofwc-add-rule" data-next="' . esc_attr( (string) $index ) . '">' . esc_html__( 'Add rule', 'order-flow-for-woocommerce' ) . '</button></p>';
		submit_button( __( 'Save transitions', 'order-flow-for-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * One table row.
	 *
	 * @param string                                                                  $index    Row index or the template placeholder.
	 * @param array{from: string, to: string, roles: string[], require_comment: bool} $rule     Rule values.
	 * @param array<string, string>                                                   $statuses Status slug => label.
	 * @param array<int|string, string>                                               $roles    Role key => name.
	 */
	private function render_row( string $index, array $rule, array $statuses, array $roles ): void {
		$base = 'rules[' . $index . ']';
		echo '<tr class="ofwc-rule">';

		echo '<td><select name="' . esc_attr( $base ) . '[from]">';
		printf( '<option value="*"%1$s>%2$s</option>', selected( Rules::ANY, $rule['from'], false ), esc_html__( 'Any status', 'order-flow-for-woocommerce' ) );
		foreach ( $statuses as $slug => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $slug ), selected( $slug, $rule['from'], false ), esc_html( $label ) );
		}
		echo '</select></td>';

		echo '<td><select name="' . esc_attr( $base ) . '[to]">';
		foreach ( $statuses as $slug => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $slug ), selected( $slug, $rule['to'], false ), esc_html( $label ) );
		}
		echo '</select></td>';

		echo '<td>';
		foreach ( $roles as $key => $name ) {
			printf(
				'<label class="ofwc-check ofwc-role"><input type="checkbox" name="%1$s[roles][]" value="%2$s"%3$s> %4$s</label>',
				esc_attr( $base ),
				esc_attr( (string) $key ),
				checked( in_array( $key, $rule['roles'], true ), true, false ),
				esc_html( translate_user_role( $name ) )
			);
		}
		echo '</td>';

		printf(
			'<td><label><input type="checkbox" name="%1$s[require_comment]" value="1"%2$s> %3$s</label></td>',
			esc_attr( $base ),
			checked( $rule['require_comment'], true, false ),
			esc_html__( 'Require comment', 'order-flow-for-woocommerce' )
		);

		echo '<td><button type="button" class="button-link ofwc-remove-rule">' . esc_html__( 'Remove', 'order-flow-for-woocommerce' ) . '</button></td>';
		echo '</tr>';
	}

	/**
	 * Save the submitted rules.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_SAVE );

		$raw = array();
		if ( isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ) {
			// Each value is sanitized in Rules::sanitize() against the registered statuses and roles.
			$raw = array_values( wp_unslash( $_POST['rules'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized field by field in Rules::sanitize().
		}
		$this->rules->save_all( $raw );

		Page::flash( 'success', __( 'Transitions saved.', 'order-flow-for-woocommerce' ) );
		Page::redirect( 'transitions' );
	}
}
