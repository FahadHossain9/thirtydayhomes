/**
 * The search filters on a phone: a drawer.
 *
 * The search is one plain GET form (Render::filter_bar) and works without
 * this file — on a desktop the keyword row sits above a panel of fields,
 * and without JavaScript a phone shows the same panel stacked. This script
 * only does four things:
 *
 *   1. on narrow screens, hides the filter panel behind a "Filters" button
 *      and opens it as a drawer with a backdrop, Escape to close, Tab kept
 *      inside, and focus returned to the button afterwards;
 *   2. submits the sort form when the sort changes, so "Sort by" — which
 *      lives beside the result count, not among the filters — acts at once;
 *   3. closes the drawer if the screen becomes wide while it is open;
 *   4. keeps the move-out date after the move-in one, so the calendar cannot
 *      offer a stay that ends before it starts. The server refuses one
 *      anyway and says why; this simply makes it harder to type.
 *
 * Dependency-free, like nav.js, which it borrows its shape from.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-tdh-filters]' );

	// The sort is its own small form beside the count. It is wired first,
	// because a page may show the sort without the filter panel.
	var sort = document.querySelector( '[data-tdh-sort]' );

	if ( sort ) {
		var sortForm = sort.closest( 'form' );

		if ( sortForm ) {
			// The Sort button is only for a page with no script.
			var apply = sortForm.querySelector( '.sort-apply' );
			if ( apply ) {
				apply.hidden = true;
			}

			sort.addEventListener( 'change', function () {
				if ( typeof sortForm.requestSubmit === 'function' ) {
					sortForm.requestSubmit();
				} else {
					sortForm.submit();
				}
			} );
		}
	}

	if ( ! root ) {
		return;
	}

	// "Within" is a distance from somewhere: a chosen hospital or a place
	// typed in the search box. It unlocks the moment either exists and
	// locks again when both are cleared, so it never looks broken while a
	// renter is still choosing. The server decides the same thing on Show
	// homes, so without this script nothing changes but the timing.
	var within = root.querySelector( '[data-tdh-within]' );

	if ( within ) {
		var withinForm  = within.closest( 'form' );
		var facility    = withinForm ? withinForm.querySelector( 'select[name="facility"]' ) : null;
		var place       = withinForm ? withinForm.querySelector( 'input[name="q"]:not([type="hidden"])' ) : null;
		var typedPlace  = withinForm ? withinForm.querySelector( 'input[name="q"][type="hidden"]' ) : null;
		var placeholder = within.querySelector( '[data-tdh-within-placeholder]' );
		var fallback    = within.querySelector( '[data-tdh-within-default]' );
		var hint        = root.querySelector( '[data-tdh-within-hint]' );

		var syncWithin = function () {
			var anchored = ( facility && '' !== facility.value ) ||
				( place && '' !== place.value.trim() ) ||
				( typedPlace && '' !== typedPlace.value.trim() );

			if ( anchored === ! within.disabled ) {
				return;
			}

			within.disabled = ! anchored;

			if ( placeholder ) {
				placeholder.hidden = anchored;
			}

			if ( hint ) {
				hint.hidden = anchored;
			}

			if ( anchored ) {
				// Start from the distance the team set, never "Pick a place".
				if ( placeholder && placeholder.selected && fallback ) {
					fallback.selected = true;
				}
			} else if ( placeholder ) {
				placeholder.selected = true;
			}
		};

		if ( facility ) {
			facility.addEventListener( 'change', syncWithin );
		}

		if ( place ) {
			place.addEventListener( 'input', syncWithin );
		}

		syncWithin();
	}

	var toggle = root.querySelector( '[data-tdh-filter-toggle]' );
	var drawer = root.querySelector( '[data-tdh-filter-drawer]' );
	var close  = root.querySelector( '[data-tdh-filter-close]' );

	if ( ! toggle || ! drawer ) {
		return;
	}

	// The presence of this class is what lets the stylesheet hide the panel
	// on a phone: with no script the class is never added and the panel
	// stays visible, so nothing is ever lost.
	root.classList.add( 'has-js' );

	var narrow = window.matchMedia( '(max-width: 43.75rem)' );

	var backdrop = document.createElement( 'div' );
	backdrop.className = 'filter-backdrop';
	root.appendChild( backdrop );

	var isOpen = function () {
		return drawer.classList.contains( 'open' );
	};

	var setOpen = function ( open ) {
		drawer.classList.toggle( 'open', open );
		backdrop.classList.toggle( 'open', open );
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		document.documentElement.classList.toggle( 'tdh-filters-open', open );

		if ( open ) {
			var first = drawer.querySelector( 'input:not([type="hidden"]), select, button' );
			if ( first ) {
				first.focus();
			}
		}
	};

	toggle.addEventListener( 'click', function () {
		setOpen( ! isOpen() );
	} );

	if ( close ) {
		close.addEventListener( 'click', function () {
			setOpen( false );
			toggle.focus();
		} );
	}

	backdrop.addEventListener( 'click', function () {
		setOpen( false );
		toggle.focus();
	} );

	document.addEventListener( 'keydown', function ( event ) {

		if ( ! isOpen() || ! narrow.matches ) {
			return;
		}

		if ( 'Escape' === event.key ) {
			setOpen( false );
			toggle.focus();
			return;
		}

		if ( 'Tab' !== event.key ) {
			return;
		}

		// Keep Tab inside the drawer while it is open, the close button
		// included: a focus ring that walks off behind the backdrop leaves a
		// keyboard user nowhere.
		var focusable = Array.prototype.slice.call(
			drawer.querySelectorAll( 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), button:not([disabled]), a[href]' )
		);

		if ( ! focusable.length ) {
			return;
		}

		var first = focusable[ 0 ];
		var last  = focusable[ focusable.length - 1 ];

		if ( event.shiftKey && document.activeElement === first ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && document.activeElement === last ) {
			event.preventDefault();
			first.focus();
		}
	} );

	// Wide again (a phone turned sideways, a window resized): the panel is a
	// row once more, and a drawer left "open" would keep the scroll lock.
	var onWidth = function ( event ) {
		if ( ! event.matches && isOpen() ) {
			setOpen( false );
		}
	};

	if ( narrow.addEventListener ) {
		narrow.addEventListener( 'change', onWidth );
	} else if ( narrow.addListener ) {
		narrow.addListener( onWidth );
	}

	// The stay. A move-out can never be earlier than the move-in, and the
	// earliest useful one is the minimum stay away, which the form carries
	// as data-tdh-min-stay so the number lives in PHP and not in here.
	var form  = drawer.closest( 'form' );
	var start = drawer.querySelector( '[data-tdh-start]' );
	var end   = drawer.querySelector( '[data-tdh-end]' );

	if ( form && start && end ) {

		var minStay = parseInt( form.getAttribute( 'data-tdh-min-stay' ), 10 );

		if ( isNaN( minStay ) || minStay < 1 ) {
			minStay = 1;
		}

		var syncEnd = function () {

			if ( ! start.value ) {
				end.min = end.getAttribute( 'data-tdh-floor' ) || '';
				return;
			}

			var earliest = new Date( start.value + 'T00:00:00' );

			if ( isNaN( earliest.getTime() ) ) {
				return;
			}

			earliest.setDate( earliest.getDate() + minStay );
			end.min = earliest.toISOString().slice( 0, 10 );

			// A move-out that is now too early is LEFT ALONE. Clearing it
			// would take away the date the renter typed at the same moment
			// the page is telling them it was refused, leaving the message
			// pointing at an empty box. The browser marks it out of range,
			// the notice above says why, and the date is still there to be
			// corrected.
		};

		end.setAttribute( 'data-tdh-floor', end.min || '' );
		start.addEventListener( 'change', syncEnd );
		syncEnd();
	}
} )();
