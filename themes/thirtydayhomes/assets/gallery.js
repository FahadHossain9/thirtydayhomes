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

	var slides   = Array.prototype.slice.call( dialog.querySelectorAll( '[data-tdh-photo]' ) );
	var previous = dialog.querySelector( '[data-tdh-gallery-prev]' );
	var next     = dialog.querySelector( '[data-tdh-gallery-next]' );
	var position = dialog.querySelector( '[data-tdh-gallery-position]' );
	var close    = dialog.querySelector( '[data-tdh-gallery-close]' );

	if ( ! slides.length || ! previous || ! next || ! position || ! close ) {
		return;
	}

	var opener       = null;
	var currentIndex = 0;

	function show( requestedIndex ) {
		var focused = document.activeElement;
		var numericIndex = Number.parseInt( requestedIndex, 10 );

		if ( Number.isNaN( numericIndex ) ) {
			numericIndex = 0;
		}

		currentIndex = Math.max( 0, Math.min( numericIndex, slides.length - 1 ) );

		slides.forEach( function ( slide, slideIndex ) {
			slide.hidden = slideIndex !== currentIndex;
		} );

		position.textContent = slides[ currentIndex ].getAttribute( 'data-position-label' ) || '';
		previous.disabled = 0 === currentIndex;
		next.disabled = slides.length - 1 === currentIndex;

		// A keyboard press may land on an end photo and disable the button
		// that owns focus. Keep focus inside the viewer on the usable direction.
		if ( previous.disabled && previous === focused ) {
			next.focus();
		} else if ( next.disabled && next === focused ) {
			previous.focus();
		}
	}

	function open( index, from ) {
		if ( dialog.open ) {
			return;
		}

		opener = from || null;
		show( index );
		dialog.showModal();
		document.documentElement.classList.add( 'tdh-gallery-is-open' );
		close.focus();
	}

	show( 0 );

	root.querySelectorAll( '[data-tdh-gallery-open]' ).forEach( function ( button ) {
		button.hidden = false;
		button.addEventListener( 'click', function () {
			open( button.getAttribute( 'data-tdh-gallery-open' ), button );
		} );
	} );

	previous.addEventListener( 'click', function () {
		show( currentIndex - 1 );
	} );

	next.addEventListener( 'click', function () {
		show( currentIndex + 1 );
	} );

	close.addEventListener( 'click', function () {
		dialog.close();
	} );

	dialog.addEventListener( 'keydown', function ( event ) {
		if ( 'ArrowLeft' === event.key ) {
			event.preventDefault();
			show( currentIndex - 1 );
		} else if ( 'ArrowRight' === event.key ) {
			event.preventDefault();
			show( currentIndex + 1 );
		} else if ( 'Home' === event.key ) {
			event.preventDefault();
			show( 0 );
		} else if ( 'End' === event.key ) {
			event.preventDefault();
			show( slides.length - 1 );
		}
	} );

	slides.forEach( function ( slide ) {
		var image = slide.querySelector( 'img' );
		var error = slide.querySelector( '[data-tdh-photo-error]' );

		if ( ! image || ! error ) {
			return;
		}

		image.addEventListener( 'error', function () {
			image.hidden = true;
			error.hidden = false;
		} );
	} );

	// A click on the dim area around the photos closes, as people expect.
	dialog.addEventListener( 'click', function ( event ) {
		if ( event.target === dialog ) {
			dialog.close();
		}
	} );

	dialog.addEventListener( 'close', function () {
		document.documentElement.classList.remove( 'tdh-gallery-is-open' );
		if ( opener && opener.isConnected ) {
			opener.focus( { preventScroll: true } );
		}
		opener = null;
	} );
} )();
