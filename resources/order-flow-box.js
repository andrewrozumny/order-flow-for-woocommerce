( function ( $ ) {
	'use strict';

	var data = window.ofwcFlow || {};

	/**
	 * Transitions tab: add and remove rule rows.
	 */
	function initRulesEditor() {
		var $form = $( '.ofwc-rules-form' );
		if ( ! $form.length ) {
			return;
		}
		var $body = $form.find( '.ofwc-rules-body' );
		var $button = $form.find( '.ofwc-add-rule' );
		var template = $form.find( '.ofwc-rule-template' ).html() || '';

		$button.on( 'click', function () {
			var next = parseInt( $button.attr( 'data-next' ), 10 ) || 0;
			$body.append( template.replace( /__INDEX__/g, String( next ) ) );
			$button.attr( 'data-next', String( next + 1 ) );
		} );

		$form.on( 'click', '.ofwc-remove-rule', function () {
			$( this ).closest( 'tr' ).remove();
		} );
	}

	/**
	 * Order screen: keep the core status dropdown to the statuses the user may choose.
	 */
	function initStatusFilter() {
		var allowed = data.allowedStatuses;
		var $select = $( '#order_status' );
		if ( ! $.isArray( allowed ) || ! $select.length ) {
			return;
		}
		$select.find( 'option' ).each( function () {
			if ( $.inArray( this.value, allowed ) === -1 ) {
				$( this ).remove();
			}
		} );
		$select.trigger( 'change.select2' );
	}

	/**
	 * Order screen: transition buttons and the comment field.
	 */
	function initBox() {
		var $box = $( '.ofwc-box' );
		if ( ! $box.length ) {
			return;
		}
		var $row = $box.find( '.ofwc-comment-row' );
		var $comment = $box.find( '#ofwc_comment' );
		var $marker = $box.find( '.ofwc-required' );
		var required = $.isArray( data.commentRequired ) ? data.commentRequired : [];

		function mark( needed ) {
			if ( needed ) {
				$marker.removeAttr( 'hidden' );
				$comment.attr( 'aria-required', 'true' );
			} else {
				$marker.attr( 'hidden', 'hidden' );
				$comment.removeAttr( 'aria-required' );
				$row.removeClass( 'ofwc-comment-missing' );
			}
		}

		$( '#order_status' ).on( 'change', function () {
			mark( $.inArray( this.value, required ) !== -1 );
		} );

		$box.on( 'click', '.ofwc-transition', function () {
			var $btn = $( this );
			var needed = '1' === String( $btn.attr( 'data-comment-required' ) );
			mark( needed );
			if ( needed && '' === $.trim( String( $comment.val() ) ) ) {
				$row.addClass( 'ofwc-comment-missing' );
				$comment.trigger( 'focus' );
				return;
			}
			var $form = $( '<form method="post"></form>' ).attr( 'action', data.adminPost || '' );
			var fields = {
				action: data.action,
				order_id: $box.attr( 'data-order' ),
				ofwc_nonce: $box.attr( 'data-nonce' ),
				ofwc_to: $btn.attr( 'data-to' ),
				ofwc_comment: $comment.val()
			};
			$.each( fields, function ( name, value ) {
				$( '<input type="hidden">' ).attr( 'name', name ).val( value ).appendTo( $form );
			} );
			$form.appendTo( document.body );
			$form.trigger( 'submit' );
		} );
	}

	$( function () {
		initRulesEditor();
		initStatusFilter();
		initBox();
	} );
}( window.jQuery ) );
