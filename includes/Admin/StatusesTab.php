<?php
/**
 * Statuses tab: list, add/edit form, delete.
 *
 * @package OrderFlowWC
 */

declare(strict_types=1);

namespace OrderFlowWC\Admin;

use OrderFlowWC\Statuses\Repository;
use OrderFlowWC\Transitions\Guard;
use OrderFlowWC\Transitions\Notices;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the custom statuses. All writes go through admin-post.php with a nonce
 * and the Order Flow configuration capability (see Page::capability()).
 *
 * @phpstan-import-type Status from Repository
 */
final class StatusesTab implements TabInterface {

	public const ACTION_SAVE   = 'ofwc_save_status';
	public const ACTION_DELETE = 'ofwc_delete_status';

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
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'ofwc_admin_tabs', array( $this, 'add_tab' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( $this, 'handle_delete' ) );
	}

	/**
	 * Add this tab to the page.
	 *
	 * @param mixed $tabs Tabs registered so far.
	 * @return mixed
	 */
	public function add_tab( $tabs ) {
		if ( ! is_array( $tabs ) ) {
			return $tabs;
		}
		$tabs[ $this->id() ] = $this;
		return $tabs;
	}

	/**
	 * Tab slug.
	 */
	public function id(): string {
		return 'statuses';
	}

	/**
	 * Tab title.
	 */
	public function label(): string {
		return __( 'Statuses', 'order-flow-for-woocommerce' );
	}

	/**
	 * Render the tab: list, form or delete confirmation.
	 */
	public function render(): void {
		$view = isset( $_GET['view'] ) && is_string( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.
		$slug = isset( $_GET['status'] ) && is_string( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch.

		if ( 'add' === $view ) {
			( new StatusForm( $this->repository ) )->render( null );
		} elseif ( 'edit' === $view && null !== $this->repository->get( $slug ) ) {
			( new StatusForm( $this->repository ) )->render( $slug );
		} elseif ( 'delete' === $view && null !== $this->repository->get( $slug ) ) {
			( new StatusDelete( $this->repository ) )->render( $slug );
		} else {
			( new StatusList( $this->repository ) )->render();
		}
	}

	/**
	 * Handle the save form.
	 */
	public function handle_save(): void {
		Page::authorize();
		check_admin_referer( self::ACTION_SAVE );

		$editing = isset( $_POST['editing'] ) && is_string( $_POST['editing'] ) ? sanitize_key( wp_unslash( $_POST['editing'] ) ) : '';
		$input   = array(
			'label'         => isset( $_POST['label'] ) && is_string( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
			'slug'          => isset( $_POST['slug'] ) && is_string( $_POST['slug'] ) ? sanitize_text_field( wp_unslash( $_POST['slug'] ) ) : '',
			'color'         => isset( $_POST['color'] ) && is_string( $_POST['color'] ) ? sanitize_text_field( wp_unslash( $_POST['color'] ) ) : '',
			'icon'          => isset( $_POST['icon'] ) && is_string( $_POST['icon'] ) ? sanitize_text_field( wp_unslash( $_POST['icon'] ) ) : '',
			'position'      => isset( $_POST['position'] ) && is_scalar( $_POST['position'] ) ? (int) $_POST['position'] : 0,
			'email_subject' => isset( $_POST['email_subject'] ) && is_string( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '',
			'email_heading' => isset( $_POST['email_heading'] ) && is_string( $_POST['email_heading'] ) ? sanitize_text_field( wp_unslash( $_POST['email_heading'] ) ) : '',
			'email_body'    => isset( $_POST['email_body'] ) && is_string( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : '',
			'pdf_title'     => isset( $_POST['pdf_title'] ) && is_string( $_POST['pdf_title'] ) ? sanitize_text_field( wp_unslash( $_POST['pdf_title'] ) ) : '',
			'pdf_text'      => isset( $_POST['pdf_text'] ) && is_string( $_POST['pdf_text'] ) ? wp_kses_post( wp_unslash( $_POST['pdf_text'] ) ) : '',
		);
		foreach ( array( 'paid', 'show_in_account', 'reduce_stock', 'email_customer', 'email_admin', 'pdf_enabled', 'pdf_attach' ) as $flag ) {
			$input[ $flag ] = isset( $_POST[ $flag ] );
		}

		if ( '' === $editing && '' === $input['slug'] ) {
			$input['slug'] = $this->repository->slug_from_label( $input['label'] );
		}
		if ( '' !== $editing ) {
			$input['slug'] = $editing;
		}

		$result = $this->repository->save( $input, '' === $editing ? null : $editing );
		if ( $result instanceof WP_Error ) {
			set_transient( 'ofwc_form_' . get_current_user_id(), $input, MINUTE_IN_SECONDS * 5 );
			Page::flash( 'error', $result->get_error_message() );
			$args = '' === $editing ? array( 'view' => 'add' ) : array(
				'view'   => 'edit',
				'status' => $editing,
			);
			Page::redirect( 'statuses', $args );
			return;
		}

		Page::flash( 'success', __( 'Status saved.', 'order-flow-for-woocommerce' ) );
		Page::redirect( 'statuses' );
	}

	/**
	 * Handle the delete form.
	 */
	public function handle_delete(): void {
		Page::authorize();
		check_admin_referer( self::ACTION_DELETE );

		$slug   = isset( $_POST['status'] ) && is_string( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$target = isset( $_POST['target'] ) && is_string( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';
		$notify = isset( $_POST['notify_customers'] );

		// The orders move as the shop's own work, whoever may configure Order Flow.
		$result = null;
		Guard::system(
			function () use ( &$result, $slug, $target, $notify ): void {
				$result = $this->repository->delete( $slug, $target, $notify );
			}
		);

		if ( $result instanceof WP_Error && 'ofwc_delete_pending' === $result->get_error_code() ) {
			Notices::add( 'info', $result->get_error_message() );
			Page::redirect( 'statuses' );
			return;
		}
		if ( $result instanceof WP_Error ) {
			Page::flash( 'error', $result->get_error_message() );
			Page::redirect(
				'statuses',
				array(
					'view'   => 'delete',
					'status' => $slug,
				)
			);
			return;
		}

		Page::flash( 'success', __( 'Status deleted.', 'order-flow-for-woocommerce' ) );
		Page::redirect( 'statuses' );
	}
}
