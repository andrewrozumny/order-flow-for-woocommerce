<?php
/**
 * Minimal WC_Email test double: the unit suite runs without WooCommerce.
 *
 * @package OrderFlowWC
 */

// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.NamingConventions.ValidFunctionName, WordPress.Files.FileName, Generic.Files.OneObjectStructurePerFile, WordPress.NamingConventions.PrefixAllGlobals, Squiz.Commenting, Universal.Files.SeparateFunctionsFromOO

/**
 * Test double with the parts of WC_Email the plugin relies on.
 */
class WC_Email {

	/**
	 * Settings, key => value (stands in for the stored email options).
	 *
	 * @var array<string, mixed>
	 */
	public static array $options = array();

	public $id;
	public $title;
	public $description;
	public $customer_email = false;
	public $template_html;
	public $template_plain;
	public $template_base;
	public $placeholders = array();
	public $object;
	public $recipient;
	public $enabled;
	public $form_fields = array();

	/**
	 * Sent messages captured by the double.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static array $sent = array();

	public function __construct() {
		$this->init_form_fields();
		$this->enabled = $this->get_option( 'enabled' );
	}

	public function init_form_fields() {
	}

	public function set_object( $object ) {
		$this->object = $object;
	}

	public function get_option( $key, $empty_value = null ) {
		if ( array_key_exists( $key, self::$options ) ) {
			return self::$options[ $key ];
		}
		return $this->form_fields[ $key ]['default'] ?? $empty_value;
	}

	public function is_enabled() {
		return 'yes' === $this->enabled;
	}

	public function get_email_type_options() {
		return array(
			'plain' => 'Plain text',
			'html'  => 'HTML',
		);
	}

	public function get_recipient() {
		return (string) $this->recipient;
	}

	public function get_attachments() {
		return apply_filters( 'woocommerce_email_attachments', array(), $this->id, $this->object, $this );
	}

	public function get_headers() {
		return '';
	}

	public function get_content() {
		return $this->get_content_html();
	}

	public function get_content_html() {
		return '';
	}

	public function get_content_plain() {
		return '';
	}

	public function setup_locale() {
	}

	public function restore_locale() {
	}

	public function send( $to, $subject, $message, $headers, $attachments ) {
		self::$sent[] = compact( 'to', 'subject', 'message', 'headers', 'attachments' ) + array( 'id' => $this->id );
		return true;
	}
}
