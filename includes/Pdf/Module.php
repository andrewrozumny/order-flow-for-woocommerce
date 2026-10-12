<?php
/**
 * PDF documents module.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Pdf;

use OrderFlowWC\Admin\DocumentsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the PDF download endpoint, buttons, email attachments and the Documents tab.
 */
final class Module {

	/**
	 * Register hooks.
	 */
	public function register(): void {
		( new Download() )->register();
		( new Buttons() )->register();
		( new Attachments() )->register();
		( new DocumentsTab() )->register();
	}
}
