<?php
/**
 * PDF files for email attachments.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Pdf;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Value;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the `ofwc_email_attachments` filter: renders the document into a temporary file named after
 * the document, in a random subfolder.
 *
 * The folder is the system temporary directory (outside the web root). When that is not writable the
 * fallback is `uploads/ofwc-tmp/`, protected for both Apache 2.2 and 2.4 and with an index file. The file is
 * deleted as soon as `wp_mail` reports success or failure; a daily cron event removes anything older than a
 * day (a queueing SMTP plugin, a request that died). When no folder can be written, the email goes out
 * without the document and a note on the order says why.
 *
 * @phpstan-import-type Status from Repository
 */
final class Attachments {

	public const DIR        = TempDirectory::DIR;
	public const MAX_AGE    = DAY_IN_SECONDS;
	public const CRON_HOOK  = 'ofwc_cleanup_tmp';
	public const HTACCESS   = TempDirectory::HTACCESS;
	public const INDEX_FILE = TempDirectory::INDEX_FILE;

	/**
	 * PDF renderer.
	 *
	 * @var Generator
	 */
	private Generator $generator;

	/**
	 * Status storage.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Files created in this request that mail has not reported on yet.
	 *
	 * @var string[]
	 */
	private array $pending = array();

	/**
	 * Why the last attempt produced no file, '' when it did.
	 *
	 * @var string
	 */
	private string $failure = '';

	/**
	 * Source of the PDF: order, status slug and status config in, binary PDF and file name out. Replaceable in tests.
	 *
	 * @var callable|null
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 * @param callable|null   $source     Optional replacement for the renderer: fn( WC_Order, string, array ): array{0: string, 1: string}.
	 */
	public function __construct( ?Repository $repository = null, ?callable $source = null ) {
		$this->repository = $repository ?? new Repository();
		$this->generator  = new Generator( $this->repository );
		$this->source     = $source;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'ofwc_email_attachments', array( $this, 'filter' ), 10, 3 );
		add_action( 'wp_mail_succeeded', array( $this, 'on_mail_succeeded' ) );
		add_action( 'wp_mail_failed', array( $this, 'on_mail_failed' ) );
		add_action( self::CRON_HOOK, array( $this, 'purge_old' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
	}

	/**
	 * Make sure the daily cleanup event exists.
	 */
	public function schedule_cleanup(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Add the document to the attachment list.
	 *
	 * @param mixed $paths  Attachment paths so far.
	 * @param mixed $order  Order.
	 * @param mixed $status Status slug.
	 * @return mixed
	 */
	public function filter( $paths, $order = null, $status = '' ) {
		if ( ! is_array( $paths ) || ! $order instanceof WC_Order || ! is_string( $status ) ) {
			return $paths;
		}
		$config = $this->repository->get( $status );
		if ( null === $config || ! $config['pdf_enabled'] ) {
			return $paths;
		}
		$file = $this->write( $order, $status, $config );
		if ( '' !== $file ) {
			$paths[] = $file;
		} elseif ( '' !== $this->failure ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: reason the PDF was not attached. */
					__( 'Order Flow: the status email was sent without the PDF document. %s', 'order-flow-for-woocommerce' ),
					$this->failure
				)
			);
		}
		return $paths;
	}

	/**
	 * Mail was sent: delete the PDFs it carried.
	 *
	 * @param mixed $mail_data Data passed to wp_mail_succeeded.
	 */
	public function on_mail_succeeded( $mail_data = null ): void {
		$this->delete_attachments_of( $mail_data );
	}

	/**
	 * Mail failed: delete the PDFs it carried.
	 *
	 * @param mixed $error WP_Error with the mail data in its error data.
	 */
	public function on_mail_failed( $error = null ): void {
		$data = $error instanceof \WP_Error ? $error->get_error_data() : null;
		$this->delete_attachments_of( $data );
	}

	/**
	 * Delete every file of ours listed in the attachments of a mail.
	 *
	 * @param mixed $mail_data Mail data array.
	 */
	private function delete_attachments_of( $mail_data ): void {
		if ( ! is_array( $mail_data ) || ! isset( $mail_data['attachments'] ) ) {
			return;
		}
		$list = $mail_data['attachments'];
		foreach ( is_array( $list ) ? $list : array( $list ) as $path ) {
			if ( is_string( $path ) && $this->is_ours( $path ) ) {
				$this->delete_file( $path );
			}
		}
	}

	/**
	 * Delete PDFs older than a day, in both possible folders.
	 */
	public function purge_old(): void {
		foreach ( $this->candidate_roots() as $root ) {
			$files = glob( $root . '/*/*.pdf' );
			if ( false === $files ) {
				continue;
			}
			foreach ( $files as $file ) {
				$time = filemtime( $file );
				if ( false !== $time && $time < time() - self::MAX_AGE ) {
					$this->delete_file( $file );
				}
			}
		}
	}

	/**
	 * Render and store the PDF.
	 *
	 * @param WC_Order             $order  Order.
	 * @param string               $slug   Status slug.
	 * @param array<string, mixed> $config Status config.
	 * @phpstan-param Status $config
	 * @return string File path or ''.
	 */
	private function write( WC_Order $order, string $slug, array $config ): string {
		$this->failure = '';
		$dir           = $this->directory();
		if ( '' === $dir ) {
			$this->failure = __( 'No writable temporary folder is available.', 'order-flow-for-woocommerce' );
			return '';
		}
		list( $pdf, $name ) = $this->render( $order, $slug, $config );
		if ( '' === $pdf ) {
			$this->failure = Generator::explain( $this->generator->failure() );
			return '';
		}
		// A random subdirectory keeps the path unguessable while the customer sees a clean file name.
		$sub = $dir . '/' . wp_generate_password( 16, false );
		if ( ! wp_mkdir_p( $sub ) ) {
			$this->failure = __( 'No writable temporary folder is available.', 'order-flow-for-woocommerce' );
			return '';
		}
		$path = $sub . '/' . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary file.
		if ( false === file_put_contents( $path, $pdf ) ) {
			$this->remove_dir( $sub );
			$this->failure = __( 'The temporary file could not be written.', 'order-flow-for-woocommerce' );
			return '';
		}
		$this->pending[] = $path;
		return $path;
	}

	/**
	 * Render the document: binary PDF and file name, both empty on failure.
	 *
	 * @param WC_Order             $order  Order.
	 * @param string               $slug   Status slug.
	 * @param array<string, mixed> $config Status config.
	 * @phpstan-param Status $config
	 * @return array{0: string, 1: string}
	 */
	private function render( WC_Order $order, string $slug, array $config ): array {
		if ( null !== $this->source ) {
			$made = call_user_func( $this->source, $order, $slug, $config );
			return is_array( $made ) && isset( $made[0], $made[1] ) ? array( Value::string( $made[0] ), Value::string( $made[1] ) ) : array( '', '' );
		}
		$pdf = $this->generator->render_pdf( $order, $slug );
		return array( $pdf, '' === $pdf ? '' : $this->generator->filename( $order, $config ) );
	}

	/**
	 * Folders that may hold our files, in order of preference.
	 *
	 * @return string[] Paths without trailing slash.
	 */
	private function candidate_roots(): array {
		return ( new TempDirectory() )->roots();
	}

	/**
	 * First usable folder: the system temporary directory, else uploads. Both get an index file and a deny rule.
	 *
	 * @return string Path without trailing slash, '' when none can be written.
	 */
	private function directory(): string {
		return ( new TempDirectory() )->path();
	}

	/**
	 * Whether a path is a file this class created: "<anything>/ofwc-tmp/<random>/<name>.pdf".
	 *
	 * @param string $path File path.
	 */
	private function is_ours( string $path ): bool {
		return 'pdf' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) )
			&& self::DIR === basename( dirname( $path, 2 ) )
			&& false === strpos( $path, '..' );
	}

	/**
	 * Delete one file and its per-file subfolder.
	 *
	 * @param string $path File path.
	 */
	private function delete_file( string $path ): void {
		wp_delete_file( $path );
		$this->remove_dir( dirname( $path ) );
		$this->pending = array_values( array_diff( $this->pending, array( $path ) ) );
	}

	/**
	 * Remove an empty per-file subdirectory of the temp directory.
	 *
	 * @param string $sub Subdirectory path.
	 */
	private function remove_dir( string $sub ): void {
		if ( self::DIR !== basename( dirname( $sub ) ) || ! is_dir( $sub ) ) {
			return;
		}
		$left = glob( $sub . '/*' );
		if ( is_array( $left ) && array() === $left ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Empty temp directory.
			rmdir( $sub );
		}
	}
}
