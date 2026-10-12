<?php
/**
 * Statuses tab: list, add/edit form, delete.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

use OrderFlowWC\Placeholders;
use OrderFlowWC\Statuses\Repository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the custom statuses. All writes go through admin-post.php with a nonce
 * and the manage_woocommerce capability.
 *
 * @phpstan-import-type Status from Repository
 */
final class StatusesTab implements TabInterface {

	public const ACTION_SAVE   = 'ofwc_save_status';
	public const ACTION_DELETE = 'ofwc_delete_status';

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'ofwc_admin_tabs', array( $this, 'add_tab' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( $this, 'handle_delete' ) );
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
		return 'statuses';
	}

	/**
	 * Tab title.
	 */
	public function label(): string {
		return __( 'Statuses', 'order-flow-for-woocommerce' );
	}

	/**
	 * Render the tab: list, form or delete confirmation.
	 */
	public function render(): void {
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$slug = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.

		if ( 'add' === $view ) {
			$this->render_form( null );
		} elseif ( 'edit' === $view && null !== $this->repository->get( $slug ) ) {
			$this->render_form( $slug );
		} elseif ( 'delete' === $view && null !== $this->repository->get( $slug ) ) {
			$this->render_delete( $slug );
		} else {
			$this->render_list();
		}
	}

	/**
	 * Status list.
	 */
	private function render_list(): void {
		$statuses = $this->repository->all();

		echo '<p>' . esc_html__( 'Custom order statuses appear next to the WooCommerce ones in the order list, the order screen and reports.', 'order-flow-for-woocommerce' ) . '</p>';
		printf(
			'<p><a href="%1$s" class="button button-primary">%2$s</a></p>',
			esc_url( Page::url( 'statuses', array( 'view' => 'add' ) ) ),
			esc_html__( 'Add status', 'order-flow-for-woocommerce' )
		);

		if ( array() === $statuses ) {
			echo '<div class="ofwc-empty"><p>' . esc_html__( 'No custom statuses yet. Add your first one to start building your order flow.', 'order-flow-for-woocommerce' ) . '</p></div>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped ofwc-table"><thead><tr>';
		printf(
			'<th scope="col" class="column-primary">%1$s</th><th scope="col">%2$s</th><th scope="col">%3$s</th><th scope="col" class="column-orders">%4$s</th></tr></thead><tbody>',
			esc_html__( 'Status', 'order-flow-for-woocommerce' ),
			esc_html__( 'Slug', 'order-flow-for-woocommerce' ),
			esc_html__( 'Settings', 'order-flow-for-woocommerce' ),
			esc_html__( 'Orders', 'order-flow-for-woocommerce' )
		);

		foreach ( $statuses as $status ) {
			$slug   = $status['slug'];
			$edit   = Page::url(
				'statuses',
				array(
					'view'   => 'edit',
					'status' => $slug,
				)
			);
			$delete = Page::url(
				'statuses',
				array(
					'view'   => 'delete',
					'status' => $slug,
				)
			);
			$count  = $this->repository->count_orders( $slug );

			echo '<tr><td class="column-primary">';
			printf(
				'<span class="ofwc-swatch" style="background:%1$s"></span><span class="dashicons %2$s ofwc-icon"></span><strong><a href="%3$s">%4$s</a></strong>',
				esc_attr( $status['color'] ),
				esc_attr( $status['icon'] ),
				esc_url( $edit ),
				esc_html( $status['label'] )
			);
			printf(
				'<div class="row-actions visible"><span class="edit"><a href="%1$s">%2$s</a> | </span><span class="delete"><a href="%3$s" class="submitdelete">%4$s</a></span></div>',
				esc_url( $edit ),
				esc_html__( 'Edit', 'order-flow-for-woocommerce' ),
				esc_url( $delete ),
				esc_html__( 'Delete', 'order-flow-for-woocommerce' )
			);
			echo '</td>';
			echo '<td><code>wc-' . esc_html( $slug ) . '</code></td>';
			echo '<td>';
			foreach ( $this->flags( $status ) as $flag ) {
				echo '<span class="ofwc-flag">' . esc_html( $flag ) . '</span> ';
			}
			echo '</td>';
			echo '<td class="column-orders">' . esc_html( number_format_i18n( $count ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Short labels for the enabled flags of a status.
	 *
	 * @param array<string, mixed> $status Status.
	 * @phpstan-param Status $status
	 * @return string[]
	 */
	private function flags( array $status ): array {
		$map = array(
			'paid'            => __( 'Paid', 'order-flow-for-woocommerce' ),
			'show_in_account' => __( 'Visible to customer', 'order-flow-for-woocommerce' ),
			'reduce_stock'    => __( 'Reduces stock', 'order-flow-for-woocommerce' ),
			'email_customer'  => __( 'Customer email', 'order-flow-for-woocommerce' ),
			'email_admin'     => __( 'Admin email', 'order-flow-for-woocommerce' ),
			'pdf_enabled'     => __( 'PDF', 'order-flow-for-woocommerce' ),
		);
		$out = array();
		foreach ( $map as $key => $label ) {
			if ( $status[ $key ] ) {
				$out[] = $label;
			}
		}
		return $out;
	}

	/**
	 * Add or edit form.
	 *
	 * @param string|null $editing Slug being edited, null for a new status.
	 */
	private function render_form( ?string $editing ): void {
		$status = null === $editing ? $this->repository->defaults() : ( $this->repository->get( $editing ) ?? $this->repository->defaults() );

		$posted = get_transient( 'ofwc_form_' . get_current_user_id() );
		if ( is_array( $posted ) ) {
			delete_transient( 'ofwc_form_' . get_current_user_id() );
			$status = $this->repository->sanitize( array_merge( $status, $posted ) );
		}

		$is_new = null === $editing;
		$title  = $is_new ? __( 'Add status', 'order-flow-for-woocommerce' ) : __( 'Edit status', 'order-flow-for-woocommerce' );

		echo '<h2>' . esc_html( $title ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ofwc-form">';
		wp_nonce_field( self::ACTION_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '">';
		echo '<input type="hidden" name="editing" value="' . esc_attr( (string) $editing ) . '">';

		echo '<h3>' . esc_html__( 'General', 'order-flow-for-woocommerce' ) . '</h3><table class="form-table" role="presentation"><tbody>';

		$this->row_start( 'ofwc-label', __( 'Label', 'order-flow-for-woocommerce' ) );
		printf( '<input type="text" id="ofwc-label" name="label" class="regular-text" value="%s" required>', esc_attr( $status['label'] ) );
		$this->row_end();

		$this->row_start( 'ofwc-slug', __( 'Slug', 'order-flow-for-woocommerce' ) );
		printf(
			'<input type="text" id="ofwc-slug" name="slug" class="regular-text code" maxlength="%1$s" value="%2$s"%3$s>',
			esc_attr( (string) Repository::MAX_SLUG_LENGTH ),
			esc_attr( $status['slug'] ),
			$is_new ? '' : ' readonly'
		);
		$this->row_end(
			$is_new
				? sprintf(
					/* translators: %d: maximum slug length. */
					__( 'Lowercase letters, numbers and hyphens, up to %d characters. Leave empty to build it from the label. It cannot be changed later.', 'order-flow-for-woocommerce' ),
					Repository::MAX_SLUG_LENGTH
				)
				: __( 'The slug of an existing status cannot be changed.', 'order-flow-for-woocommerce' )
		);

		$this->row_start( 'ofwc-color', __( 'Color', 'order-flow-for-woocommerce' ) );
		printf( '<input type="text" id="ofwc-color" name="color" class="ofwc-color-field" value="%s" data-default-color="#777777">', esc_attr( $status['color'] ) );
		$this->row_end( __( 'Used for the status badge in the order list.', 'order-flow-for-woocommerce' ) );

		$this->row_start( '', __( 'Icon', 'order-flow-for-woocommerce' ) );
		echo '<fieldset class="ofwc-icons"><legend class="screen-reader-text">' . esc_html__( 'Icon', 'order-flow-for-woocommerce' ) . '</legend>';
		foreach ( Repository::icons() as $class => $name ) {
			printf(
				'<label title="%1$s"><input type="radio" name="icon" value="%2$s"%3$s><span class="dashicons %2$s"></span><span class="screen-reader-text">%1$s</span></label>',
				esc_attr( $name ),
				esc_attr( $class ),
				checked( $status['icon'], $class, false )
			);
		}
		echo '</fieldset>';
		$this->row_end();

		$this->row_start( 'ofwc-position', __( 'Position', 'order-flow-for-woocommerce' ) );
		printf( '<input type="number" id="ofwc-position" name="position" class="small-text" value="%s">', esc_attr( (string) $status['position'] ) );
		$this->row_end( __( 'Statuses are listed after "Processing", lowest number first.', 'order-flow-for-woocommerce' ) );

		$this->row_start( '', __( 'Behavior', 'order-flow-for-woocommerce' ) );
		$this->checkbox( 'paid', __( 'Count as paid', 'order-flow-for-woocommerce' ), $status['paid'], __( 'Orders in this status are treated as paid and included in reports.', 'order-flow-for-woocommerce' ) );
		$this->checkbox( 'show_in_account', __( 'Show to the customer', 'order-flow-for-woocommerce' ), $status['show_in_account'], __( 'If off, customers see "Processing" in My Account instead of this label.', 'order-flow-for-woocommerce' ) );
		$this->checkbox( 'reduce_stock', __( 'Reduce stock', 'order-flow-for-woocommerce' ), $status['reduce_stock'], __( 'Reduce stock levels when an order enters this status.', 'order-flow-for-woocommerce' ) );
		$this->row_end();

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Emails', 'order-flow-for-woocommerce' ) . '</h3><table class="form-table" role="presentation"><tbody>';

		$this->row_start( '', __( 'Send email', 'order-flow-for-woocommerce' ) );
		$this->checkbox( 'email_customer', __( 'To the customer', 'order-flow-for-woocommerce' ), $status['email_customer'] );
		$this->checkbox( 'email_admin', __( 'To the shop admin', 'order-flow-for-woocommerce' ), $status['email_admin'] );
		$this->row_end( __( 'Sent when an order enters this status. Recipients and on/off switches are also listed under WooCommerce > Settings > Emails.', 'order-flow-for-woocommerce' ) );

		$this->row_start( 'ofwc-email-subject', __( 'Subject', 'order-flow-for-woocommerce' ) );
		printf( '<input type="text" id="ofwc-email-subject" name="email_subject" class="large-text" value="%s">', esc_attr( $status['email_subject'] ) );
		$this->row_end();

		$this->row_start( 'ofwc-email-heading', __( 'Heading', 'order-flow-for-woocommerce' ) );
		printf( '<input type="text" id="ofwc-email-heading" name="email_heading" class="large-text" value="%s">', esc_attr( $status['email_heading'] ) );
		$this->row_end();

		$this->row_start( 'ofwc-email-body', __( 'Body', 'order-flow-for-woocommerce' ) );
		printf( '<textarea id="ofwc-email-body" name="email_body" class="large-text" rows="6">%s</textarea>', esc_textarea( $status['email_body'] ) );
		$this->row_end( $this->placeholder_help() );

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'PDF document', 'order-flow-for-woocommerce' ) . '</h3><table class="form-table" role="presentation"><tbody>';

		$this->row_start( '', __( 'Document', 'order-flow-for-woocommerce' ) );
		$this->checkbox( 'pdf_enabled', __( 'Enable PDF for this status', 'order-flow-for-woocommerce' ), $status['pdf_enabled'], __( 'Admins can download it from the order screen. Customers can too if the status is shown to them.', 'order-flow-for-woocommerce' ) );
		$this->checkbox( 'pdf_attach', __( 'Attach to the status emails', 'order-flow-for-woocommerce' ), $status['pdf_attach'] );
		$this->row_end();

		$this->row_start( 'ofwc-pdf-title', __( 'Document title', 'order-flow-for-woocommerce' ) );
		printf( '<input type="text" id="ofwc-pdf-title" name="pdf_title" class="regular-text" value="%s">', esc_attr( $status['pdf_title'] ) );
		$this->row_end( __( 'For example "Approval sheet". Placeholders are allowed.', 'order-flow-for-woocommerce' ) );

		$this->row_start( 'ofwc-pdf-text', __( 'Text on the document', 'order-flow-for-woocommerce' ) );
		printf( '<textarea id="ofwc-pdf-text" name="pdf_text" class="large-text" rows="5">%s</textarea>', esc_textarea( $status['pdf_text'] ) );
		$this->row_end( $this->placeholder_help() );

		echo '</tbody></table>';

		submit_button( $is_new ? __( 'Add status', 'order-flow-for-woocommerce' ) : __( 'Save status', 'order-flow-for-woocommerce' ) );
		printf( '<a href="%1$s">%2$s</a>', esc_url( Page::url( 'statuses' ) ), esc_html__( 'Back to statuses', 'order-flow-for-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * Delete confirmation with a target status when orders exist.
	 *
	 * @param string $slug Status slug.
	 */
	private function render_delete( string $slug ): void {
		$status = $this->repository->get( $slug );
		if ( null === $status ) {
			$this->render_list();
			return;
		}
		$count = $this->repository->count_orders( $slug );

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %s: status label. */
				__( 'Delete status "%s"', 'order-flow-for-woocommerce' ),
				$status['label']
			)
		) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ofwc-form">';
		wp_nonce_field( self::ACTION_DELETE );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE ) . '">';
		echo '<input type="hidden" name="status" value="' . esc_attr( $slug ) . '">';

		if ( $count > 0 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of orders. */
					_n( '%s order is currently in this status. Choose where to move it.', '%s orders are currently in this status. Choose where to move them.', $count, 'order-flow-for-woocommerce' ),
					number_format_i18n( $count )
				)
			) . '</p>';
			echo '<p><label for="ofwc-target">' . esc_html__( 'Move orders to', 'order-flow-for-woocommerce' ) . '</label> ';
			echo '<select id="ofwc-target" name="target" required><option value="">' . esc_html__( 'Select a status', 'order-flow-for-woocommerce' ) . '</option>';
			foreach ( wc_get_order_statuses() as $key => $label ) {
				if ( 'wc-' . $slug === $key ) {
					continue;
				}
				printf( '<option value="%1$s">%2$s</option>', esc_attr( $key ), esc_html( $label ) );
			}
			echo '</select></p>';
		} else {
			echo '<p>' . esc_html__( 'No orders are in this status. Transition rules that mention it will be removed.', 'order-flow-for-woocommerce' ) . '</p>';
		}

		submit_button( __( 'Delete status', 'order-flow-for-woocommerce' ), 'delete' );
		printf( '<a href="%1$s">%2$s</a>', esc_url( Page::url( 'statuses' ) ), esc_html__( 'Cancel', 'order-flow-for-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * Handle the save form.
	 */
	public function handle_save(): void {
		$this->authorize();
		check_admin_referer( self::ACTION_SAVE );

		$editing = isset( $_POST['editing'] ) && is_string( $_POST['editing'] ) ? sanitize_key( wp_unslash( $_POST['editing'] ) ) : '';
		$input   = array(
			'label'         => isset( $_POST['label'] ) && is_string( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
			'slug'          => isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '',
			'color'         => isset( $_POST['color'] ) && is_string( $_POST['color'] ) ? sanitize_text_field( wp_unslash( $_POST['color'] ) ) : '',
			'icon'          => isset( $_POST['icon'] ) && is_string( $_POST['icon'] ) ? sanitize_text_field( wp_unslash( $_POST['icon'] ) ) : '',
			'position'      => isset( $_POST['position'] ) && is_scalar( $_POST['position'] ) ? (int) $_POST['position'] : 0,
			'email_subject' => isset( $_POST['email_subject'] ) && is_string( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '',
			'email_heading' => isset( $_POST['email_heading'] ) && is_string( $_POST['email_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['email_heading'] ) ) : '',
			'email_body'    => isset( $_POST['email_body'] ) && is_string( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : '',
			'pdf_title'     => isset( $_POST['pdf_title'] ) && is_string( $_POST['pdf_title'] ) ? sanitize_text_field( wp_unslash( $_POST['pdf_title'] ) ) : '',
			'pdf_text'      => isset( $_POST['pdf_text'] ) && is_string( $_POST['pdf_text'] ) ? wp_kses_post( wp_unslash( $_POST['pdf_text'] ) ) : '',
		);
		foreach ( array( 'paid', 'show_in_account', 'reduce_stock', 'email_customer', 'email_admin', 'pdf_enabled', 'pdf_attach' ) as $flag ) {
			$input[ $flag ] = isset( $_POST[ $flag ] );
		}

		if ( '' === $editing && '' === $input['slug'] ) {
			$input['slug'] = $this->repository->slug_from_label( $input['label'] );
		}
		if ( '' !== $editing ) {
			$input['slug'] = $editing;
		}

		$result = $this->repository->save( $input, '' === $editing ? null : $editing );
		if ( $result instanceof WP_Error ) {
			set_transient( 'ofwc_form_' . get_current_user_id(), $input, MINUTE_IN_SECONDS * 5 );
			Page::flash( 'error', $result->get_error_message() );
			$args = '' === $editing ? array( 'view' => 'add' ) : array(
				'view'   => 'edit',
				'status' => $editing,
			);
			Page::redirect( 'statuses', $args );
			return;
		}

		Page::flash( 'success', __( 'Status saved.', 'order-flow-for-woocommerce' ) );
		Page::redirect( 'statuses' );
	}

	/**
	 * Handle the delete form.
	 */
	public function handle_delete(): void {
		$this->authorize();
		check_admin_referer( self::ACTION_DELETE );

		$slug   = isset( $_POST['status'] ) && is_string( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$target = isset( $_POST['target'] ) && is_string( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';

		$result = $this->repository->delete( $slug, $target );
		if ( $result instanceof WP_Error ) {
			Page::flash( 'error', $result->get_error_message() );
			Page::redirect(
				'statuses',
				array(
					'view'   => 'delete',
					'status' => $slug,
				)
			);
			return;
		}

		Page::flash( 'success', __( 'Status deleted.', 'order-flow-for-woocommerce' ) );
		Page::redirect( 'statuses' );
	}

	/**
	 * Capability gate for the admin-post handlers. The nonce is checked by the handlers themselves.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Page::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'order-flow-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Placeholder help line.
	 */
	private function placeholder_help(): string {
		return sprintf(
			/* translators: %s: list of placeholders. */
			__( 'Placeholders: %s', 'order-flow-for-woocommerce' ),
			implode( ' ', array_keys( Placeholders::tags() ) )
		);
	}

	/**
	 * Open a settings table row.
	 *
	 * @param string $control ID of the labelled control, empty for none.
	 * @param string $label Row title.
	 */
	private function row_start( string $control, string $label ): void {
		echo '<tr><th scope="row">';
		if ( '' === $control ) {
			echo esc_html( $label );
		} else {
			echo '<label for="' . esc_attr( $control ) . '">' . esc_html( $label ) . '</label>';
		}
		echo '</th><td>';
	}

	/**
	 * Close a settings table row.
	 *
	 * @param string $help Optional help text.
	 */
	private function row_end( string $help = '' ): void {
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Echo a checkbox with a label and optional description.
	 *
	 * @param string $name    Field name.
	 * @param string $label   Label text.
	 * @param bool   $checked Whether it is on.
	 * @param string $help    Optional description.
	 */
	private function checkbox( string $name, string $label, bool $checked, string $help = '' ): void {
		printf(
			'<label class="ofwc-check"><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
		if ( '' !== $help ) {
			echo '<p class="description ofwc-check-help">' . esc_html( $help ) . '</p>';
		}
	}
}
