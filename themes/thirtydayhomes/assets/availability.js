/**
 * The property page's availability calendar: Previous and Next buttons for
 * the row of months.
 *
 * Enhancement only. The months are all in the page and the row scrolls
 * sideways by itself (swipe, trackpad, or the keyboard once it has focus);
 * the buttons stay hidden without this script rather than doing nothing.
 * One month per press. Smooth scrolling only when the visitor has not asked
 * for reduced motion.
 */
( function () {
	'use strict';

	var cals = document.querySelectorAll( '[data-avail-cal]' );

	Array.prototype.forEach.call( cals, function ( cal ) {
		var track = cal.querySelector( '[data-avail-cal-track]' );
		var nav   = cal.querySelector( '[data-avail-cal-nav]' );
		var prev  = cal.querySelector( '[data-avail-cal-prev]' );
		var next  = cal.querySelector( '[data-avail-cal-next]' );

		if ( ! track || ! nav || ! prev || ! next ) {
			return;
		}

		var still = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		// The width of one month plus the gap after it.
		function step() {
			var month = track.querySelector( '.avail-cal-month' );
			if ( ! month ) {
				return track.clientWidth;
			}
			var gap = parseFloat( window.getComputedStyle( track ).columnGap ) || 0;
			return month.getBoundingClientRect().width + gap;
		}

		function update() {
			var max = track.scrollWidth - track.clientWidth - 1;
			prev.disabled = track.scrollLeft <= 0;
			next.disabled = track.scrollLeft >= max;
		}

		function move( direction ) {
			track.scrollBy( { left: direction * step(), behavior: still ? 'auto' : 'smooth' } );
		}

		nav.hidden = false;

		prev.addEventListener( 'click', function () { move( -1 ); } );
		next.addEventListener( 'click', function () { move( 1 ); } );

		var queued = false;
		track.addEventListener( 'scroll', function () {
			if ( queued ) {
				return;
			}
			queued = true;
			window.requestAnimationFrame( function () {
				queued = false;
				update();
			} );
		}, { passive: true } );

		window.addEventListener( 'resize', update );
		update();
	} );
} )();
