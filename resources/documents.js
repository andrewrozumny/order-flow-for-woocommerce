( function ( $ ) {
	'use strict';

	$( function () {
		var frame;
		var $id = $( '#ofwc-logo-id' );
		var $preview = $( '#ofwc-logo-preview' );

		$( '#ofwc-logo-pick' ).on( 'click', function ( event ) {
			event.preventDefault();
			if ( ! frame ) {
				frame = wp.media( {
					title: window.ofwcDocuments.title,
					button: { text: window.ofwcDocuments.button },
					library: { type: 'image' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;
					$id.val( attachment.id );
					$preview.attr( 'src', url ).prop( 'hidden', false );
				} );
			}
			frame.open();
		} );

		$( '#ofwc-logo-clear' ).on( 'click', function ( event ) {
			event.preventDefault();
			$id.val( 0 );
			$preview.prop( 'hidden', true ).attr( 'src', '' );
		} );
	} );
}( window.jQuery ) );
