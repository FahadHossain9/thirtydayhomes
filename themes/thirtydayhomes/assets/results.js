/**
 * The results area refreshes in place (team review, 4 Oct 2026).
 *
 * Every filter, the sort, the page numbers, the filter labels and the
 * "clear" links used to reload the whole page and drop the renter back at
 * the top, above the hero. Now only the results area is fetched and
 * swapped, the address bar still changes (so Back, Refresh and sharing a
 * link behave exactly as before), and the page settles on the results.
 *
 * How it works: the same URL the browser would have opened is fetched, the
 * [data-tdh-results] block is lifted out of the answer and put in place of
 * the old one. The server renders it exactly as a full load would, so
 * there is one source of truth and nothing to keep in step.
 *
 * Falls back to an ordinary page load whenever it cannot help: the map
 * view (Google's map needs a real load), an answer without a results
 * block, a network error, or a browser without fetch. The compact phone
 * homes archive is the exception: an offline refresh keeps its current
 * homes and typed fields and offers one inline retry instead of leaving
 * the branded page. Without this file, every control is the plain link or
 * GET form it always was.
 */
( function () {
	'use strict';

	var root = document.querySelector( '[data-tdh-results]' );

	if ( ! root || ! window.fetch || ! window.DOMParser || ! window.history || ! history.pushState ) {
		return;
	}

	var inFlight = null;
	var requestId = 0;

	// Screen readers hear the new count; sighted users see it.
	var live = document.createElement( 'p' );
	live.className = 'screen-reader-text';
	live.setAttribute( 'role', 'status' );
	live.setAttribute( 'aria-live', 'polite' );
	document.body.appendChild( live );

	var isMap = function ( url ) {
		return 'map' === url.searchParams.get( 'view' );
	};

	var current = function () {
		return document.querySelector( '[data-tdh-results]' );
	};

	// H1's compact recovery belongs only to the phone /homes/ archive. The
	// desktop, taxonomy and widget fallbacks remain ordinary page loads.
	var keepsInlineFailure = function () {
		return document.body
			&& document.body.classList.contains( 'post-type-archive-tdh_listing' )
			&& document.body.classList.contains( 'tdh-results' )
			&& window.matchMedia
			&& window.matchMedia( '(max-width: 43.75rem)' ).matches;
	};

	var clearLoading = function ( block ) {
		if ( ! block ) {
			return;
		}

		block.classList.remove( 'is-refreshing' );
		block.removeAttribute( 'aria-busy' );
	};

	var closeFilterDrawer = function ( block ) {
		var drawer   = block && block.querySelector( '[data-tdh-filter-drawer]' );
		var backdrop = block && block.querySelector( '.filter-backdrop' );
		var toggle   = block && block.querySelector( '[data-tdh-filter-toggle]' );

		if ( drawer ) {
			drawer.classList.remove( 'open' );
		}
		if ( backdrop ) {
			backdrop.classList.remove( 'open' );
		}
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'false' );
		}

		document.documentElement.classList.remove( 'tdh-filters-open' );
	};

	var showFailure = function ( url, push ) {
		var block = current();

		clearLoading( block );
		closeFilterDrawer( block );
		live.textContent = '';

		if ( ! block ) {
			return;
		}

		// Reuse the one alert if two failures arrive close together. Its data
		// is replaced, so Retry always repeats the latest intended address.
		var notice = block.querySelector( '.results-error' );

		if ( ! notice ) {
			notice = document.createElement( 'div' );
			notice.className = 'results-error notice error';
			notice.setAttribute( 'role', 'alert' );

			var message = document.createElement( 'p' );
			message.textContent = "We couldn't update the homes. Check your connection and try again; your current results are still here.";
			notice.appendChild( message );

			var retry = document.createElement( 'button' );
			retry.className = 'secondary results-retry';
			retry.type = 'button';
			retry.textContent = 'Try again';
			retry.addEventListener( 'click', function () {
				var retryUrl  = notice.getAttribute( 'data-retry-url' );
				var retryPush = '1' === notice.getAttribute( 'data-retry-push' );

				if ( retryUrl ) {
					go( retryUrl, retryPush );
				}
			} );
			notice.appendChild( retry );

			var filters = block.querySelector( '.filters' );
			if ( filters ) {
				filters.insertAdjacentElement( 'afterend', notice );
			} else {
				block.insertBefore( notice, block.firstChild );
			}
		}

		notice.setAttribute( 'data-retry-url', url.toString() );
		notice.setAttribute( 'data-retry-push', push ? '1' : '0' );

		var action = notice.querySelector( '.results-retry' );
		if ( action ) {
			action.focus();
		}
	};

	// The fixed header covers the top of the page; land just below it.
	var headerHeight = function () {
		// The site header when it is pinned, and WordPress's black bar for
		// signed-in staff, which is pinned above everything.
		var total = 0;

		Array.prototype.forEach.call( document.querySelectorAll( '.site-header, #wpadminbar' ), function ( h ) {
			var position = getComputedStyle( h ).position;
			if ( 'fixed' === position || 'sticky' === position ) {
				total += h.offsetHeight;
			}
		} );

		return total;
	};

	var settle = function () {
		var block  = current();
		var target = block && ( block.querySelector( '.result-top' ) || block.querySelector( '.empty' ) || block.querySelector( '.filter-chips' ) );

		if ( ! target ) {
			return;
		}

		var top = target.getBoundingClientRect().top + window.pageYOffset - headerHeight() - 16;
		var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		window.scrollTo( { top: Math.max( 0, top ), behavior: reduce ? 'auto' : 'smooth' } );
	};

	var go = function ( href, push ) {

		var url;

		try {
			url = new URL( href, window.location.href );
		} catch ( e ) {
			return false;
		}

		if ( url.origin !== window.location.origin || isMap( url ) || isMap( new URL( window.location.href ) ) ) {
			return false;
		}

		var inlineRecovery = keepsInlineFailure();

		if ( inFlight ) {
			inFlight.abort();
		}

		var controller = window.AbortController ? new AbortController() : null;
		var request    = ++requestId;
		var committed  = false;
		inFlight = controller;

		var block      = current();
		var oldFailure = block.querySelector( '.results-error' );
		if ( oldFailure ) {
			oldFailure.remove();
		}
		block.classList.add( 'is-refreshing' );
		block.setAttribute( 'aria-busy', 'true' );
		if ( inlineRecovery ) {
			live.textContent = 'Updating homes.';
		}

		// A phone's filter drawer closes; the results are what it asked for.
		document.documentElement.classList.remove( 'tdh-filters-open' );

		fetch( url.toString(), {
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'tdh-results' },
			signal: controller ? controller.signal : undefined
		} ).then( function ( response ) {
			if ( ! response.ok && 404 !== response.status ) {
				throw new Error( 'status ' + response.status );
			}
			return response.text();
		} ).then( function ( html ) {
			// AbortController is an optimisation, not the guard. Browsers
			// without it, and an older response already being parsed, must not
			// replace or report over the renter's newer request.
			if ( request !== requestId ) {
				return;
			}

			var doc   = new DOMParser().parseFromString( html, 'text/html' );
			var fresh = doc.querySelector( '[data-tdh-results]' );

			if ( ! fresh ) {
				if ( inlineRecovery && keepsInlineFailure() ) {
					throw new Error( 'results block missing' );
				}
				window.location.href = url.toString();
				return;
			}

			var old = current();
			old.replaceWith( document.importNode( fresh, true ) );
			committed = true;

			if ( doc.title ) {
				document.title = doc.title;
			}

			if ( push ) {
				history.pushState( { tdhResults: true }, '', url.toString() );
			}

			// The fresh controls get their behaviour back.
			if ( window.tdhFiltersInit ) {
				window.tdhFiltersInit();
			}
			if ( window.tdhSavedInit ) {
				window.tdhSavedInit( current() );
			}

			var count = current().querySelector( '.result-top > b' ) || current().querySelector( '.empty > h3' );
			live.textContent = count ? count.textContent.trim() : '';

			settle();
		} ).catch( function ( error ) {
			if ( request !== requestId ) {
				return;
			}
			if ( error && 'AbortError' === error.name ) {
				return;
			}
			// The fresh results are already on screen. A later enhancement error
			// must not misreport a successful refresh as a connection failure.
			if ( committed ) {
				return;
			}
			if ( inlineRecovery && keepsInlineFailure() ) {
				showFailure( url, push );
				return;
			}
			// Slow, offline or refused: the ordinary page load still works.
			window.location.href = url.toString();
		} ).then( function () {
			if ( request === requestId && inFlight === controller ) {
				inFlight = null;
			}
		} );

		return true;
	};

	// The filter form and the sort form, delegated so fresh copies count too.
	document.addEventListener( 'submit', function ( event ) {

		var form = event.target;

		if ( ! form.closest || ! form.closest( '[data-tdh-results]' ) || 'get' !== ( form.getAttribute( 'method' ) || 'get' ).toLowerCase() ) {
			return;
		}

		var url  = new URL( form.getAttribute( 'action' ) || window.location.href, window.location.href );
		var data = new FormData( form );

		url.search = '';
		// Empty fields are left out: the server reads a missing field as
		// "any", and a shared link stays short and readable.
		data.forEach( function ( value, key ) {
			if ( '' !== String( value ).trim() ) {
				url.searchParams.append( key, value );
			}
		} );

		if ( event.submitter && event.submitter.name ) {
			url.searchParams.set( event.submitter.name, event.submitter.value );
		}

		if ( go( url.toString(), true ) ) {
			event.preventDefault();
		}
	} );

	// Page numbers, filter labels, "Clear all", "Show all homes".
	var LINKS = '.listing-pagination a, .filter-chips a, .filter-clear, .search-clear, .empty a';

	document.addEventListener( 'click', function ( event ) {

		if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
			return;
		}

		var link = event.target.closest ? event.target.closest( LINKS ) : null;

		if ( ! link || ! link.closest( '[data-tdh-results]' ) || link.target ) {
			return;
		}

		if ( go( link.href, true ) ) {
			event.preventDefault();
		}
	} );

	// Back and Forward show the results that URL had.
	history.replaceState( { tdhResults: true }, '', window.location.href );

	window.addEventListener( 'popstate', function ( event ) {
		if ( event.state && event.state.tdhResults && ! go( window.location.href, false ) ) {
			window.location.reload();
		}
	} );
} )();
