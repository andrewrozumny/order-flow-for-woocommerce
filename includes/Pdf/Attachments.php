<?php
/**
 * PDF files for email attachments.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Pdf;

use OrderFlowWC\Statuses\Repository;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the `ofwc_email_attachments` filter: renders the document into uploads/ofwc-tmp/
 * under a random name and deletes the file at shutdown.
 */
final class Attachments {

	public const DIR     = 'ofwc-tmp';
	public const MAX_AGE = DAY_IN_SECONDS;

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
	 * Files to delete at shutdown.
	 *
	 * @var string[]
	 */
	private array $pending = array();

	/**
	 * Constructor.
	 *
	 * @param Repository|null $repository Status storage.
	 */
	public function __construct( ?Repository $repository = null ) {
		$this->repository = $repository ?? new Repository();
		$this->generator  = new Generator( $this->repository );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'ofwc_email_attachments', array( $this, 'filter' ), 10, 3 );
	}

	/**
	 * Add the document to the attachment list.
	 *
	 * @param mixed  $paths  Attachment paths so far.
	 * @param mixed  $order  Order.
	 * @param string $status Status slug.
	 * @return mixed
	 */
	public function filter( $paths, $order = null, $status = '' ) {
		if ( ! is_array( $paths ) || ! $order instanceof WC_Order || ! is_string( $status ) ) {
			return $paths;
		}
		$config = $this->repository->get( $status );
		if ( null === $config || empty( $config['pdf_enabled'] ) ) {
			return $paths;
		}
		$file = $this->write( $order, $status, $config );
		if ( '' !== $file ) {
			$paths[] = $file;
		}
		return $paths;
	}

	/**
	 * Render and store the PDF.
	 *
	 * @param WC_Order             $order  Order.
	 * @param string               $slug   Status slug.
	 * @param array<string, mixed> $config Status config.
	 * @return string File path or ''.
	 */
	private function write( WC_Order $order, string $slug, array $config ): string {
		$dir = $this->directory();
		if ( '' === $dir ) {
			return '';
		}
		$pdf = $this->generator->render_pdf( $order, $slug );
		if ( '' === $pdf ) {
			return '';
		}
		$name = preg_replace( '/\.pdf$/', '', $this->generator->filename( $order, $config ) ) . '-' . wp_generate_password( 12, false ) . '.pdf';
		$path = $dir . '/' . $name;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary file in uploads.
		if ( false === file_put_contents( $path, $pdf ) ) {
			return '';
		}
		if ( array() === $this->pending ) {
			add_action( 'shutdown', array( $this, 'cleanup' ) );
		}
		$this->pending[] = $path;
		return $path;
	}

	/**
	 * Delete the files created in this request.
	 */
	public function cleanup(): void {
		foreach ( $this->pending as $path ) {
			wp_delete_file( $path );
		}
		$this->pending = array();
	}

	/**
	 * Temp directory, created with an index file and a deny rule. Old leftovers are purged.
	 *
	 * @return string Path without trailing slash, '' on failure.
	 */
	private function directory(): string {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) || ! is_string( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = untrailingslashit( $uploads['basedir'] ) . '/' . self::DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protecting a directory in uploads.
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		// phpcs:enable
		$this->purge( $dir );
		return $dir;
	}

	/**
	 * Remove PDFs older than a day (left by a request that died before shutdown).
	 *
	 * @param string $dir Directory.
	 */
	private function purge( string $dir ): void {
		$files = glob( $dir . '/*.pdf' );
		if ( false === $files ) {
			return;
		}
		foreach ( $files as $file ) {
			$time = filemtime( $file );
			if ( false !== $time && $time < time() - self::MAX_AGE ) {
				wp_delete_file( $file );
			}
		}
	}
}
