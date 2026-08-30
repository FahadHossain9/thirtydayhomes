/**
 * Saved homes.
 *
 * Storage is this browser only. There is no account system in V1, so there
 * is nothing to sync a saved list to — and a heart that silently forgets on
 * the next device is better than one that pretends to be an account feature.
 * When membership ships, the same buttons point at a REST route instead and
 * this file becomes the offline fallback.
 *
 * The buttons are printed hidden and revealed here, so a visitor without
 * JavaScript never sees a control that cannot work.
 */
( function () {
	'use strict';

	var KEY = 'tdh_saved_homes';

	/**
	 * Private browsing and "block site data" both make localStorage throw on
	 * access rather than return null, so every touch is guarded. A browser
	 * that cannot store gets working buttons that simply do not persist.
	 */
	function read() {
		try {
			var raw = window.localStorage.getItem( KEY );
			var ids = raw ? JSON.parse( raw ) : [];
			return Array.isArray( ids ) ? ids : [];
		} catch ( e ) {
			return [];
		}
	}

	function write( ids ) {
		try {
			window.localStorage.setItem( KEY, JSON.stringify( ids ) );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	function paint( button, isSaved ) {
		button.setAttribute( 'aria-pressed', isSaved ? 'true' : 'false' );
		button.classList.toggle( 'liked', isSaved );
	}

	document.addEventListener( 'DOMContentLoaded', function () {

		var buttons = document.querySelectorAll( '[data-tdh-save]' );

		if ( ! buttons.length ) {
			return;
		}

		var saved = read();

		Array.prototype.forEach.call( buttons, function ( button ) {

			var id = button.getAttribute( 'data-tdh-save' );

			button.hidden = false;
			paint( button, saved.indexOf( id ) !== -1 );

			button.addEventListener( 'click', function () {

				// Re-read on every click: another tab may have changed the
				// list since load, and writing a stale array would silently
				// drop whatever that tab saved.
				var current = read();
				var at = current.indexOf( id );

				if ( at === -1 ) {
					current.push( id );
				} else {
					current.splice( at, 1 );
				}

				write( current );
				paint( button, current.indexOf( id ) !== -1 );
			} );
		} );
	} );
}() );
