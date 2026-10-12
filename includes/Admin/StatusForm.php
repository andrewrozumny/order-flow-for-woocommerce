<?php
/**
 * Statuses tab: the add and edit form.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

use OrderFlowWC\Placeholders;
use OrderFlowWC\Statuses\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The form for one status: general settings, emails and the PDF document.
 *
 * @phpstan-import-type Status from Repository
 */
final class StatusForm {

	use FormFields;

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @param Repository $repository Status storage.
	 */
	public function __construct( Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Render the add or edit form.
	 *
	 * @param string|null $editing Slug being edited, null for a new status.
	 */
	public function render( ?string $editing ): void {
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
		wp_nonce_field( StatusesTab::ACTION_SAVE );
		echo '<input type="hidden" name="action" value="' . esc_attr( StatusesTab::ACTION_SAVE ) . '">';
		echo '<input type="hidden" name="editing" value="' . esc_attr( (string) $editing ) . '">';

		$this->render_general( $status, $is_new );
		$this->render_emails( $status );
		$this->render_pdf( $status );

		submit_button( $is_new ? __( 'Add status', 'order-flow-for-woocommerce' ) : __( 'Save status', 'order-flow-for-woocommerce' ) );
		printf( '<a href="%1$s">%2$s</a>', esc_url( Page::url( 'statuses' ) ), esc_html__( 'Back to statuses', 'order-flow-for-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * General settings.
	 *
	 * @param array<string, mixed> $status Status.
	 * @phpstan-param Status $status
	 * @param bool                 $is_new Whether the status is new.
	 */
	private function render_general( array $status, bool $is_new ): void {
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
		$this->checkbox( 'paid', __( 'Count as paid', 'order-flow-for-woocommerce' ), $status['paid'], __( 'Orders in this status are treated as paid and included in reports. If off, they are left out of the revenue in WooCommerce Analytics.', 'order-flow-for-woocommerce' ) );
		$this->checkbox( 'show_in_account', __( 'Show to the customer', 'order-flow-for-woocommerce' ), $status['show_in_account'], __( 'If off, customers see "Processing" instead of this label: in My Account, in the store APIs and in emails to the customer.', 'order-flow-for-woocommerce' ) );
		$this->checkbox( 'reduce_stock', __( 'Reduce stock', 'order-flow-for-woocommerce' ), $status['reduce_stock'], __( 'Reduce stock levels when an order enters this status.', 'order-flow-for-woocommerce' ) );
		$this->row_end();

		echo '</tbody></table>';
	}

	/**
	 * Email settings.
	 *
	 * @param array<string, mixed> $status Status.
	 * @phpstan-param Status $status
	 */
	private function render_emails( array $status ): void {
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
	}

	/**
	 * PDF document settings.
	 *
	 * @param array<string, mixed> $status Status.
	 * @phpstan-param Status $status
	 */
	private function render_pdf( array $status ): void {
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
}
