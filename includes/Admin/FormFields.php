<?php
/**
 * Settings table helpers shared by the status screens.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows and checkboxes in the WordPress settings table layout.
 */
trait FormFields {

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
