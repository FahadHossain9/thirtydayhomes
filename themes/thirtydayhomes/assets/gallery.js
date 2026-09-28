/**
 * The property page's photo viewer.
 *
 * A native <dialog>: the browser gives it Escape to close, focus moved
 * inside, the page behind made inert, and focus handed back on close — the
 * behaviour a hand-built lightbox gets wrong most often. This script only
 * opens it at the photo that was clicked.
 *
 * Enhancement only. Without it the page still shows the cover and up to
 * four more photos; the open buttons stay hidden rather than doing nothing.
 */
( function () {
	'use strict';

	var dialog = document.getElementById( 'tdh-gallery-dialog' );
	var root   = document.querySelector( '[data-tdh-gallery]' );

	if ( ! dialog || ! root || 'function' !== typeof dialog.showModal ) {
		return;
	}

	var opener = null;

	function open( index, from ) {
		opener = from || null;
		dialog.showModal();

		var photo = dialog.querySelector( '[data-tdh-photo="' + index + '"]' );

		if ( '0' === String( index ) ) {
			dialog.scrollTop = 0;
		} else if ( photo ) {
			// Instant, not smooth: the viewer has just appeared, and a long
			// animated scroll through photos is motion nobody asked for.
			photo.scrollIntoView( { block: 'start' } );
		}
	}

	root.querySelectorAll( '[data-tdh-gallery-open]' ).forEach( function ( button ) {
		button.hidden = false;
		button.addEventListener( 'click', function () {
			open( button.getAttribute( 'data-tdh-gallery-open' ), button );
		} );
	} );

	var close = dialog.querySelector( '[data-tdh-gallery-close]' );

	if ( close ) {
		close.addEventListener( 'click', function () { dialog.close(); } );
	}

	// A click on the dim area around the photos closes, as people expect.
	dialog.addEventListener( 'click', function ( event ) {
		if ( event.target === dialog ) {
			dialog.close();
		}
	} );

	dialog.addEventListener( 'close', function () {
		if ( opener ) {
			opener.focus();
		}
	} );
} )();
