/**
 * The property page: "Ask the owner" kept in reach.
 *
 * The rent and one gold button follow the reader — in the side column on a
 * desktop, in a bar along the bottom on a phone — until the inquiry form
 * itself is on screen, when both step aside so there is one filled button
 * in view (G3a design review). This file does only that, plus one
 * courtesy: pressing the button moves focus to the form's first field, so
 * a keyboard user lands where the typing starts.
 *
 * Nothing depends on it. Without the script the button is a plain link to
 * #inquire, and the bar simply stays.
 */
( function () {
	'use strict';

	var target = document.getElementById( 'inquire' );

	if ( ! target ) {
		return;
	}

	var root = document.documentElement;
	var asks = document.querySelectorAll( '[data-tdh-ask]' );

	// Focus follows the jump. The browser scrolls to #inquire on its own;
	// the timeout lets that happen first so the focus does not fight it.
	Array.prototype.forEach.call( asks, function ( ask ) {
		ask.addEventListener( 'click', function () {
			window.setTimeout( function () {
				var first = target.querySelector( 'input:not([type="hidden"]), select, textarea, a[href], button' );
				if ( first ) {
					first.focus( { preventScroll: true } );
				}
			}, 0 );
		} );
	} );

	if ( ! ( 'IntersectionObserver' in window ) ) {
		return;
	}

	// The form, and the footer: the bar steps aside for either, so it never
	// covers the last line of the page.
	var watched = [ target ];
	var footer  = document.querySelector( '.site-footer' );
	var visible = [];

	if ( footer ) {
		watched.push( footer );
	}

	var observer = new IntersectionObserver(
		function ( entries ) {
			entries.forEach( function ( entry ) {
				var at = visible.indexOf( entry.target );

				if ( entry.isIntersecting && -1 === at ) {
					visible.push( entry.target );
				} else if ( ! entry.isIntersecting && -1 !== at ) {
					visible.splice( at, 1 );
				}
			} );

			root.classList.toggle( 'tdh-inquire-in-view', visible.length > 0 );
		},
		{ threshold: 0.15 }
	);

	watched.forEach( function ( el ) {
		observer.observe( el );
	} );
} )();
