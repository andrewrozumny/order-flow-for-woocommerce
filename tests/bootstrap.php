<?php
/**
 * PHPUnit bootstrap.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'OFWC_VERSION', '0.1.0' );
define( 'OFWC_FILE', ABSPATH . 'order-flow-for-woocommerce.php' );
define( 'OFWC_PATH', ABSPATH );
define( 'OFWC_URL', 'https://example.test/wp-content/plugins/order-flow-for-woocommerce/' );

require_once ABSPATH . 'vendor/autoload.php';
require_once ABSPATH . 'includes/autoload.php';

// Minimal WP_Error stand-in: the unit suite runs without WordPress.
if ( ! class_exists( 'WP_Error' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Test double for a WordPress core class.
	/**
	 * Test double for the WordPress core class.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private string $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private string $message;

		/**
		 * Error data.
		 *
		 * @var mixed
		 */
		private $data;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 * @param mixed  $data    Error data.
		 */
		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}

		/**
		 * Error code.
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Error message.
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}
