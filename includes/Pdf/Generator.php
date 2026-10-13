<?php
/**
 * PDF document rendering.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Pdf;

use OrderFlowWC\Placeholders;
use OrderFlowWC\Vendor\Dompdf\Dompdf;
use OrderFlowWC\Vendor\Dompdf\Options;
use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Value;
use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the HTML of an order document from the template and turns it into a PDF with dompdf.
 *
 * Themes override the template at `yourtheme/order-flow-for-woocommerce/pdf/document.php`. The template
 * receives one array, `$ofwc_doc`, with the keys: order, status, title, status_label, order_number,
 * date, logo_src, store_details, text, billing, shipping, items, totals, site_url.
 *
 * @phpstan-import-type Status from Repository
 */
final class Generator {

	public const MAX_LOGO_BYTES = 2097152;
	public const FONT_CACHE_DIR = 'ofwc-fonts';

	/**
	 * Seconds a PDF may take. A shorter limit of the server is raised to this, a longer one is left alone.
	 */
	public const TIME_LIMIT = 120;

	/**
	 * Rough memory dompdf needs for a document, measured on dompdf 3.1 with PHP 8.4 inside WordPress: it grows a little
	 * faster than the number of order lines. A straight line through the two measured limits (370 lines in a hard
	 * 128 MB, 710 lines in 256 MB) with 5 percent on top, a floor for short documents, plus a part per character of
	 * the status text. The estimate refuses a document before dompdf runs out of memory, and errs on the side of
	 * refusing: about 340 lines fit in 128 MB and 660 in 256 MB.
	 */
	public const MEMORY_FLOOR    = 16777216;
	public const MEMORY_OFFSET   = 20971520;
	public const MEMORY_PER_LINE = 420000;
	public const MEMORY_PER_CHAR = 130;

	public const FAILURE_NONE   = '';
	public const FAILURE_TEMP   = 'temp';
	public const FAILURE_SIZE   = 'size';
	public const FAILURE_RENDER = 'render';

	/**
	 * Why the last render produced nothing: one of the FAILURE_ constants.
	 *
	 * @var string
	 */
	private string $failure = self::FAILURE_NONE;

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
	 * Document title for a status, placeholders replaced.
	 *
	 * @param WC_Order             $order  Order.
	 * @param array<string, mixed> $status Status config.
	 * @phpstan-param Status $status
	 */
	public function title( WC_Order $order, array $status ): string {
		$label = $status['label'];
		$title = trim( Placeholders::replace( $status['pdf_title'], $order, $label ) );
		return '' === $title ? $label : $title;
	}

	/**
	 * Base name of the download: "{title-slug}-{order number}.pdf".
	 *
	 * @param WC_Order             $order  Order.
	 * @param array<string, mixed> $status Status config.
	 * @phpstan-param Status $status
	 */
	public function filename( WC_Order $order, array $status ): string {
		$slug = sanitize_title( $this->title( $order, $status ) );
		if ( '' === $slug ) {
			$slug = $status['slug'];
		}
		return sanitize_file_name( $slug . '-' . $order->get_order_number() . '.pdf' );
	}

	/**
	 * Render the document HTML.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $slug  Custom status slug (without the wc- prefix).
	 */
	public function render_html( WC_Order $order, string $slug ): string {
		$status = $this->repository->get( $slug );
		if ( null === $status ) {
			return '';
		}
		$label = $status['label'];
		$date  = $order->get_date_created();

		/**
		 * Filters the variables handed to the document template.
		 *
		 * @param array<string, mixed> $doc   Template variables.
		 * @param WC_Order             $order Order.
		 * @param string               $slug  Status slug.
		 */
		$ofwc_doc = apply_filters(
			'ofwc_pdf_document_data',
			array(
				'order'         => $order,
				'status'        => $status,
				'title'         => $this->title( $order, $status ),
				'status_label'  => $label,
				'order_number'  => $order->get_order_number(),
				'date'          => null === $date ? '' : wc_format_datetime( $date ),
				'logo_src'      => $this->logo_data_uri(),
				'store_details' => $this->store_details_html(),
				'text'          => wpautop( wp_kses_post( Placeholders::replace( $status['pdf_text'], $order, $label, '', true ) ) ),
				'billing'       => $order->get_formatted_billing_address( '' ),
				'shipping'      => $order->get_formatted_shipping_address( '' ),
				'items'         => $this->item_rows( $order ),
				'totals'        => $order->get_order_item_totals(),
				'site_url'      => home_url(),
			),
			$order,
			$slug
		);

		$template = locate_template( 'order-flow-for-woocommerce/pdf/document.php' );
		if ( '' === $template ) {
			$template = OFWC_PATH . 'templates/pdf/document.php';
		}

		ob_start();
		include $template;
		return (string) ob_get_clean();
	}

	/**
	 * Render the document as a PDF.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $slug  Custom status slug.
	 * @return string Binary PDF, empty string when dompdf is missing or the status is unknown.
	 */
	public function render_pdf( WC_Order $order, string $slug ): string {
		$this->failure = self::FAILURE_NONE;
		$status        = $this->repository->get( $slug );
		if ( null === $status ) {
			return '';
		}
		if ( ! $this->fits_in_memory( count( $order->get_items() ), strlen( $status['pdf_text'] ) ) ) {
			$this->failure = self::FAILURE_SIZE;
			return '';
		}
		$html = $this->render_html( $order, $slug );
		if ( '' === $html ) {
			return '';
		}
		return $this->pdf_from_html( $html );
	}

	/**
	 * Why the last render produced nothing.
	 *
	 * @return string One of the FAILURE_ constants, '' when nothing went wrong.
	 */
	public function failure(): string {
		return $this->failure;
	}

	/**
	 * Plain-language reason for a failed render, for the person who asked for the document.
	 *
	 * @param string $failure One of the FAILURE_ constants.
	 */
	public static function explain( string $failure ): string {
		if ( self::FAILURE_TEMP === $failure ) {
			return __( 'The PDF could not be created: this server has no writable temporary folder. Ask your host to make the PHP temporary directory writable, or make the uploads folder writable.', 'order-flow-for-woocommerce' );
		}
		if ( self::FAILURE_SIZE === $failure ) {
			return __( 'The PDF could not be created: the order or the status text is too large for the memory this server gives PHP. Ask your host to raise memory_limit, or shorten the text of the document.', 'order-flow-for-woocommerce' );
		}
		return __( 'The PDF could not be created.', 'order-flow-for-woocommerce' );
	}

	/**
	 * Memory a document of this size needs, in bytes (an estimate).
	 *
	 * @param int $lines Order lines.
	 * @param int $chars Characters of the status text.
	 */
	public static function estimate_memory( int $lines, int $chars ): int {
		return max( self::MEMORY_FLOOR, $lines * self::MEMORY_PER_LINE - self::MEMORY_OFFSET ) + $chars * self::MEMORY_PER_CHAR;
	}

	/**
	 * Raise the memory limit the way wp-admin does and never lower the time limit.
	 */
	public static function raise_limits(): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$time = (int) ini_get( 'max_execution_time' );
		if ( $time > 0 && $time < self::TIME_LIMIT && function_exists( 'set_time_limit' ) ) {
			set_time_limit( self::TIME_LIMIT ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Only ever raises the limit, for one document.
		}
	}

	/**
	 * Whether a document of this size is likely to fit in the memory that is left. Unlimited memory always fits.
	 *
	 * @param int $lines Order lines.
	 * @param int $chars Characters of the status text.
	 */
	public function fits_in_memory( int $lines, int $chars ): bool {
		self::raise_limits();
		$limit = self::memory_limit();
		if ( $limit <= 0 ) {
			return true;
		}
		return self::estimate_memory( $lines, $chars ) <= $limit - memory_get_usage();
	}

	/**
	 * The memory limit in bytes, 0 when there is none.
	 */
	public static function memory_limit(): int {
		$raw = ini_get( 'memory_limit' );
		if ( '' === $raw || '-1' === $raw ) {
			return 0;
		}
		return function_exists( 'wp_convert_hr_to_bytes' ) ? max( 0, wp_convert_hr_to_bytes( $raw ) ) : 0;
	}

	/**
	 * Turn HTML into a PDF.
	 *
	 * @param string $html Complete HTML document.
	 * @return string Binary PDF, empty when dompdf is not available.
	 */
	public function pdf_from_html( string $html ): string {
		$this->failure = self::FAILURE_NONE;
		if ( ! Libraries::load() ) {
			return '';
		}
		$temp = ( new TempDirectory() )->path();
		if ( '' === $temp ) {
			$this->failure = self::FAILURE_TEMP;
			return '';
		}
		self::raise_limits();

		$uploads = wp_upload_dir();
		$base    = $uploads['basedir'];
		$chroot  = array( ABSPATH, OFWC_PATH );
		if ( '' !== $base ) {
			$chroot[] = $base;
		}

		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isPhpEnabled', false );
		$options->set( 'chroot', $chroot );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$options->set( 'tempDir', $temp );
		$cache = $this->font_cache_dir( $base );
		if ( '' !== $cache ) {
			$options->set( 'fontCache', $cache );
		}

		try {
			$dompdf = new Dompdf( $options );
			$dompdf->setPaper( 'A4', 'portrait' );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->render();
			return $dompdf->output();
		} catch ( \Throwable $e ) {
			$this->failure = self::FAILURE_RENDER;
			return '';
		}
	}

	/**
	 * Font cache directory: always inside uploads, never inside the plugin folder.
	 *
	 * @param string $uploads_base Uploads base directory.
	 * @return string Path, or '' when uploads is not usable (dompdf then keeps its default).
	 */
	private function font_cache_dir( string $uploads_base ): string {
		if ( '' === $uploads_base ) {
			return '';
		}
		$dir = trailingslashit( $uploads_base ) . self::FONT_CACHE_DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		return $dir;
	}

	/**
	 * Logo as a base64 data URI (never a remote URL), or ''.
	 */
	private function logo_data_uri(): string {
		$path = $this->logo_path();
		if ( '' === $path || ! is_readable( $path ) ) {
			return '';
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_LOGO_BYTES ) {
			return '';
		}
		$type = wp_check_filetype( $path );
		$mime = (string) $type['type'];
		if ( ! in_array( $mime, array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
			return '';
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( false === $data ) {
			return '';
		}
		return 'data:' . $mime . ';base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Embedding an image in HTML.
	}

	/**
	 * Local path of the logo: the setting, else the WooCommerce email header image if it is local.
	 */
	private function logo_path(): string {
		$settings = get_option( 'ofwc_settings', array() );
		$logo_id  = is_array( $settings ) ? Value::int( $settings['logo_id'] ?? 0 ) : 0;
		if ( $logo_id > 0 ) {
			$file = get_attached_file( $logo_id );
			if ( is_string( $file ) && '' !== $file ) {
				return $file;
			}
		}

		$url = Value::option_string( 'woocommerce_email_header_image' );
		if ( '' === $url ) {
			return '';
		}
		$uploads = wp_upload_dir();
		$baseurl = $uploads['baseurl'];
		$basedir = $uploads['basedir'];
		if ( '' === $baseurl || '' === $basedir || 0 !== strpos( $url, $baseurl . '/' ) ) {
			return '';
		}
		$relative = substr( $url, strlen( $baseurl ) );
		if ( false !== strpos( $relative, '..' ) ) {
			return '';
		}
		return $basedir . $relative;
	}

	/**
	 * Store details block as safe HTML: the setting, else built from the WooCommerce store address.
	 */
	public function store_details_html(): string {
		$settings = get_option( 'ofwc_settings', array() );
		$custom   = is_array( $settings ) ? trim( Value::string( $settings['store_details'] ?? '' ) ) : '';
		if ( '' !== $custom ) {
			return wpautop( wp_kses_post( $custom ) );
		}

		$lines   = array();
		$lines[] = '<strong>' . esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '</strong>';
		foreach ( array( 'woocommerce_store_address', 'woocommerce_store_address_2' ) as $key ) {
			$value = trim( Value::option_string( $key ) );
			if ( '' !== $value ) {
				$lines[] = esc_html( $value );
			}
		}
		$city = trim( trim( Value::option_string( 'woocommerce_store_postcode' ) ) . ' ' . trim( Value::option_string( 'woocommerce_store_city' ) ) );
		if ( '' !== $city ) {
			$lines[] = esc_html( $city );
		}
		$country = $this->country_name( Value::option_string( 'woocommerce_default_country' ) );
		if ( '' !== $country ) {
			$lines[] = esc_html( $country );
		}
		return implode( '<br>', $lines );
	}

	/**
	 * Country name from a WooCommerce "CC:STATE" value.
	 *
	 * @param string $value Option value.
	 */
	private function country_name( string $value ): string {
		$code = strtoupper( explode( ':', $value )[0] );
		if ( '' === $code || ! function_exists( 'WC' ) ) {
			return '';
		}
		$countries = WC()->countries->get_countries();
		return isset( $countries[ $code ] ) ? html_entity_decode( Value::string( $countries[ $code ] ), ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * Line item rows for the template.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{name: string, sku: string, qty: int, price: string, total: string}>
	 */
	private function item_rows( WC_Order $order ): array {
		$rows = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			$rows[]  = array(
				'name'  => $item->get_name(),
				'sku'   => $product instanceof \WC_Product ? $product->get_sku() : '',
				'qty'   => $item->get_quantity(),
				'price' => wc_price( $order->get_item_subtotal( $item, false, true ), array( 'currency' => $order->get_currency() ) ),
				'total' => $order->get_formatted_line_subtotal( $item ),
			);
		}
		return $rows;
	}
}
