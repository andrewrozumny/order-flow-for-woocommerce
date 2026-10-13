<?php
/**
 * Writable folder for temporary PDF files.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Pdf;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The folder for temporary files of the PDF generator and of email attachments: the system temporary directory
 * (outside the web root), else `uploads/ofwc-tmp/`. The fallback is protected for both Apache 2.2 and 2.4 and
 * carries an index file.
 */
final class TempDirectory {

	public const DIR        = 'ofwc-tmp';
	public const HTACCESS   = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
	public const INDEX_FILE = "<?php\n// Silence is golden.\n";

	/**
	 * Folders that may hold our files, in order of preference.
	 *
	 * @return string[] Paths without trailing slash.
	 */
	public function roots(): array {
		$roots = array();
		$tmp   = get_temp_dir();
		if ( '' !== $tmp ) {
			$roots[] = untrailingslashit( $tmp ) . '/' . self::DIR;
		}
		$uploads = wp_upload_dir();
		if ( '' !== $uploads['basedir'] ) {
			$roots[] = untrailingslashit( $uploads['basedir'] ) . '/' . self::DIR;
		}
		return $roots;
	}

	/**
	 * First usable folder, with an index file and a deny rule in it.
	 *
	 * @return string Path without trailing slash, '' when none can be written.
	 */
	public function path(): string {
		foreach ( $this->roots() as $dir ) {
			if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
				continue;
			}
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Protecting a directory.
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', self::INDEX_FILE );
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', self::HTACCESS );
			}
			// phpcs:enable
			return $dir;
		}
		return '';
	}
}
