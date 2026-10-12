<?php
/**
 * One WooCommerce email for one status and one recipient type.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Emails;

use OrderFlowWC\Placeholders;
use WC_Email;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Status email. Subject, heading and body come from the status configuration,
 * which is edited under WooCommerce > Order Flow. The WooCommerce email settings
 * only hold the on/off switch, the admin recipient and the email type.
 *
 * Themes override the layout at yourtheme/woocommerce/emails/ofwc-status.php.
 */
class StatusEmail extends WC_Email {

	/**
	 * Recipient type: customer or admin.
	 *
	 * @var string
	 */
	private string $recipient_type;

	/**
	 * Status configuration (see Repository).
	 *
	 * @var array<string, mixed>
	 */
	private array $status;

	/**
	 * Comment entered with the status change.
	 *
	 * @var string
	 */
	private string $note = '';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $status         Status configuration.
	 * @param string               $recipient_type customer or admin.
	 */
	public function __construct( array $status, string $recipient_type ) {
		$this->status         = $status;
		$this->recipient_type = 'admin' === $recipient_type ? 'admin' : 'customer';

		$slug  = $this->status_string( 'slug' );
		$label = $this->status_string( 'label' );

		$this->id             = self::email_id( $slug, $this->recipient_type );
		$this->customer_email = 'customer' === $this->recipient_type;
		$this->title          = 'customer' === $this->recipient_type
			/* translators: %s: status label */
			? sprintf( __( 'Order Flow: %s (customer)', 'order-flow-for-woocommerce' ), $label )
			/* translators: %s: status label */
			: sprintf( __( 'Order Flow: %s (admin)', 'order-flow-for-woocommerce' ), $label );
		$this->description    = __( 'Texts are edited under WooCommerce > Order Flow.', 'order-flow-for-woocommerce' );
		$this->template_base  = OFWC_PATH . 'templates/';
		$this->template_html  = 'emails/ofwc-status.php';
		$this->template_plain = 'emails/plain/ofwc-status.php';
		$this->placeholders   = array(
			'{order_number}'  => '',
			'{status}'        => $label,
			'{customer_name}' => '',
			'{note}'          => '',
			'{order_date}'    => '',
			'{order_total}'   => '',
		);

		parent::__construct();

		if ( 'admin' === $this->recipient_type ) {
			$this->recipient = (string) $this->get_option( 'recipient', (string) get_option( 'admin_email' ) );
		}
	}

	/**
	 * Email id for a status and recipient type.
	 *
	 * @param string $slug Status slug.
	 * @param string $type customer or admin.
	 */
	public static function email_id( string $slug, string $type ): string {
		return 'ofwc_' . $slug . '_' . $type;
	}

	/**
	 * The status slug this email belongs to.
	 */
	public function status_slug(): string {
		return $this->status_string( 'slug' );
	}

	/**
	 * Settings fields: only what WooCommerce must know. Texts live in the status.
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'order-flow-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'order-flow-for-woocommerce' ),
				'default' => 'yes',
			),
		);
		if ( 'admin' === $this->recipient_type ) {
			$this->form_fields['recipient'] = array(
				'title'       => __( 'Recipient(s)', 'order-flow-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Enter recipients (comma separated) for this email.', 'order-flow-for-woocommerce' ),
				'placeholder' => '',
				'default'     => (string) get_option( 'admin_email' ),
				'desc_tip'    => true,
			);
		}
		$this->form_fields['email_type'] = array(
			'title'       => __( 'Email type', 'order-flow-for-woocommerce' ),
			'type'        => 'select',
			'description' => __( 'Choose which format of email to send.', 'order-flow-for-woocommerce' ),
			'default'     => 'html',
			'class'       => 'email_type wc-enhanced-select',
			'options'     => $this->get_email_type_options(),
			'desc_tip'    => true,
		);
	}

	/**
	 * Enabled in WooCommerce and switched on for this recipient in the status.
	 */
	public function is_enabled() {
		$flag = 'admin' === $this->recipient_type ? 'email_admin' : 'email_customer';
		return parent::is_enabled() && ! empty( $this->status[ $flag ] );
	}

	/**
	 * Default subject (the status text; used by WooCommerce for the settings placeholder).
	 */
	public function get_default_subject() {
		return $this->status_string( 'email_subject' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading() {
		return $this->status_string( 'email_heading' );
	}

	/**
	 * Subject with the placeholders replaced.
	 */
	public function get_subject() {
		$subject = $this->fill( $this->status_string( 'email_subject' ), false );
		return (string) apply_filters( 'woocommerce_email_subject_' . $this->id, $subject, $this->object, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Same filter WooCommerce applies for its own emails.
	}

	/**
	 * Heading with the placeholders replaced.
	 */
	public function get_heading() {
		$heading = $this->fill( $this->status_string( 'email_heading' ), false );
		return (string) apply_filters( 'woocommerce_email_heading_' . $this->id, $heading, $this->object, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Same filter WooCommerce applies for its own emails.
	}

	/**
	 * Body as HTML, placeholders replaced (values escaped).
	 */
	public function get_body_html(): string {
		return wpautop( wp_kses_post( $this->fill( $this->status_string( 'email_body' ), true ) ) );
	}

	/**
	 * Body as plain text.
	 */
	public function get_body_plain(): string {
		$text = $this->fill( $this->status_string( 'email_body' ), false );
		$text = (string) preg_replace( '#<(br|/p)\s*/?>#i', "\n", $text );
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Attachments: WooCommerce filter plus the document(s) added through ofwc_email_attachments.
	 *
	 * @return array<int, string>
	 */
	public function get_attachments(): array {
		$attachments = (array) parent::get_attachments();
		if ( $this->object instanceof WC_Order && ! empty( $this->status['pdf_enabled'] ) && ! empty( $this->status['pdf_attach'] ) ) {
			$extra = apply_filters( 'ofwc_email_attachments', array(), $this->object, $this->status_slug() );
			if ( is_array( $extra ) ) {
				$attachments = array_merge( $attachments, $extra );
			}
		}
		return array_values( array_filter( $attachments, 'is_string' ) );
	}

	/**
	 * HTML content.
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			$this->template_args( false ),
			'',
			$this->template_base
		);
	}

	/**
	 * Plain text content.
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			$this->template_args( true ),
			'',
			$this->template_base
		);
	}

	/**
	 * Send this email for an order that entered the status.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $note  Comment entered with the status change.
	 * @return bool Whether an email was sent.
	 */
	public function trigger_status( WC_Order $order, string $note = '' ): bool {
		$this->setup_locale();
		$this->object = $order;
		$this->note   = $note;
		if ( 'customer' === $this->recipient_type ) {
			$this->recipient = $order->get_billing_email();
		}

		$sent = false;
		if ( $this->is_enabled() && '' !== (string) $this->get_recipient() ) {
			$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
		return $sent;
	}

	/**
	 * Template variables.
	 *
	 * @param bool $plain_text Plain text template.
	 * @return array<string, mixed>
	 */
	private function template_args( bool $plain_text ): array {
		return array(
			'order'         => $this->object,
			'email_heading' => $this->get_heading(),
			'body'          => $plain_text ? $this->get_body_plain() : $this->get_body_html(),
			'sent_to_admin' => 'admin' === $this->recipient_type,
			'plain_text'    => $plain_text,
			'email'         => $this,
		);
	}

	/**
	 * Replace the placeholders; without an order (settings screen) the text is returned as is.
	 *
	 * @param string $text Text with placeholders.
	 * @param bool   $html True when the result goes into HTML.
	 */
	private function fill( string $text, bool $html ): string {
		if ( ! $this->object instanceof WC_Order ) {
			return $text;
		}
		return Placeholders::replace( $text, $this->object, $this->status_string( 'label' ), $this->note, $html );
	}

	/**
	 * String value from the status configuration.
	 *
	 * @param string $key Config key.
	 */
	private function status_string( string $key ): string {
		$value = $this->status[ $key ] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}
}
