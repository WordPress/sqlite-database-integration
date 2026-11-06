/* global ClipboardJS */
( function() {
	'use strict';

	const button = document.querySelector( '.sqlite-integration .sqlite-copy-path' );
	if ( ! button || typeof ClipboardJS === 'undefined' ) {
		return;
	}

	const status = document.querySelector( '.sqlite-integration .sqlite-copy-status' );
	const clipboard = new ClipboardJS( button );
	button.hidden = false;

	clipboard.on( 'success', function( event ) {
		status.textContent = status.dataset.success;
		event.clearSelection();
	} );
	clipboard.on( 'error', function() {
		status.textContent = status.dataset.error;
	} );
	button.closest( 'details' ).addEventListener( 'toggle', function() {
		status.textContent = '';
	} );
}() );
