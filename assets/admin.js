/**
 * Confirms destructive actions that carry a data-confirm message.
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-confirm]' );

		if ( ! button ) {
			return;
		}

		if ( ! window.confirm( button.getAttribute( 'data-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
}() );
