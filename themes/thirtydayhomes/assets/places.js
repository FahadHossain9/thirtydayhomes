/**
 * Address suggestions from Google while typing (team review, 4 Oct 2026).
 *
 * A typed address was free text, so mistakes ("Street Address: 48 Market
 * Street", a Cincinnati city with a Pittsburgh ZIP) reached the map as
 * "not found". Now the landlord types a few letters and picks the real
 * address from a list; the street, ZIP, city, neighbourhood and state fill
 * themselves, and the server's own lookup then finds the house.
 *
 * Two kinds of field, both marked with data-tdh-places:
 *
 *   "address"  the listing form's street box. On a pick it fills the
 *              street and the sibling fields of its form.
 *   "point"    a search box on staff's "Set location" panel. On a pick it
 *              writes "lat, lng" into the field named by
 *              data-tdh-places-target, ready for Save location.
 *
 * Progressive: without this script, or while Google's Places service is
 * switched off for the key, every field is exactly the plain text box it
 * was, and the "point" search stays hidden. Nothing here is required to
 * save a home.
 *
 * Settings arrive in window.tdhPlaces (src, bounds, words), printed by the
 * theme only on pages that can hold one of these fields.
 */
( function () {
	'use strict';

	var settings = window.tdhPlaces || {};
	var fields   = document.querySelectorAll( '[data-tdh-places]' );

	if ( ! fields.length || ! settings.src ) {
		return;
	}

	var words = settings.words || {};
	var said  = function ( key, fallback ) { return words[ key ] || fallback; };

	/* ---------------------------------------------------------------------
	 * Load Google's library once, only because a field is on the page.
	 * ------------------------------------------------------------------ */

	var places = null;

	window.tdhPlacesReady = function () {
		google.maps.importLibrary( 'places' ).then( function ( lib ) {
			places = lib;
			Array.prototype.forEach.call( fields, wire );
		} ).catch( function () {} );
	};

	// A key Google refuses calls this; the fields simply stay plain.
	window.gm_authFailure = window.gm_authFailure || function () {};

	var loader   = document.createElement( 'script' );
	loader.src   = settings.src;
	loader.async = true;
	document.head.appendChild( loader );

	/* ---------------------------------------------------------------------
	 * One field: an accessible combobox over Google's suggestions.
	 * ------------------------------------------------------------------ */

	var count = 0;

	function wire( input ) {

		var mode   = input.getAttribute( 'data-tdh-places' );
		var form   = input.closest( 'form' );
		var listId = 'tdh-places-list-' + ( ++count );
		var token  = new places.AutocompleteSessionToken();
		var items  = [];
		var active = -1;
		var timer  = 0;
		var asked  = 0;
		var picked = '';

		// The "point" search only appears once it can work.
		var shell = input.closest( '[data-tdh-places-shell]' );
		if ( shell ) {
			shell.hidden = false;
		}

		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', listId );
		input.setAttribute( 'autocomplete', 'off' );

		var wrap = document.createElement( 'div' );
		wrap.className = 'places-pop';
		wrap.hidden = true;

		var list = document.createElement( 'ul' );
		list.id = listId;
		list.className = 'places-list';
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', said( 'list', 'Suggested addresses' ) );

		// Google's terms: suggestions shown without a Google map carry its name.
		var credit = document.createElement( 'p' );
		credit.className = 'places-credit';
		credit.textContent = said( 'credit', 'Suggestions by Google' );

		wrap.appendChild( list );
		wrap.appendChild( credit );
		input.insertAdjacentElement( 'afterend', wrap );

		// The line under the field: found, or how to get it found.
		var note = document.createElement( 'p' );
		note.className = 'places-note';
		note.setAttribute( 'role', 'status' );
		note.hidden = true;
		wrap.insertAdjacentElement( 'afterend', note );

		var say = function ( text, kind ) {
			note.textContent = text;
			note.className = 'places-note' + ( kind ? ' is-' + kind : '' );
			note.hidden = ! text;
		};

		var close = function () {
			wrap.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			active = -1;
		};

		var mark = function ( i ) {
			active = i;
			Array.prototype.forEach.call( list.children, function ( li, n ) {
				li.setAttribute( 'aria-selected', n === i ? 'true' : 'false' );
				li.classList.toggle( 'is-active', n === i );
			} );
			if ( i >= 0 && list.children[ i ] ) {
				input.setAttribute( 'aria-activedescendant', list.children[ i ].id );
				list.children[ i ].scrollIntoView( { block: 'nearest' } );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		};

		var draw = function ( suggestions ) {
			items = suggestions;
			list.textContent = '';

			if ( ! items.length ) {
				close();
				return;
			}

			items.forEach( function ( s, n ) {
				var p  = s.placePrediction;
				var li = document.createElement( 'li' );
				li.id = listId + '-' + n;
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', 'false' );

				var main = document.createElement( 'b' );
				main.textContent = p.mainText ? p.mainText.text : p.text.text;
				var rest = document.createElement( 'span' );
				rest.textContent = p.secondaryText ? p.secondaryText.text : '';

				li.appendChild( main );
				li.appendChild( rest );

				// mousedown, not click: click comes after blur has closed the list.
				li.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					choose( n );
				} );
				list.appendChild( li );
			} );

			// Directly under the box being typed in, never over it: the field's
			// label is a flex column, so an absolute child would otherwise sit
			// at the label's top and cover the text being typed.
			wrap.style.top = ( input.offsetTop + input.offsetHeight ) + 'px';

			wrap.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			mark( -1 );
		};

		var ask = function () {
			var text = input.value.trim();

			if ( text.length < 3 || text === picked ) {
				close();
				return;
			}

			var request = {
				input: text,
				sessionToken: token,
				includedRegionCodes: [ 'us' ]
			};

			// A home's address is an address: no shops, offices or schools in
			// the list. Streets stay in, so the list does not vanish before
			// the house number is typed; picking one asks for the number.
			if ( 'address' === mode ) {
				request.includedPrimaryTypes = [ 'street_address', 'premise', 'subpremise', 'route' ];
			}

			// Lean towards the area the site serves; anywhere in the US still answers.
			if ( settings.bounds ) {
				request.locationBias = settings.bounds;
			}

			var mine = ++asked;

			places.AutocompleteSuggestion.fetchAutocompleteSuggestions( request ).then( function ( r ) {
				if ( mine === asked ) {
					draw( ( r && r.suggestions ) ? r.suggestions.slice( 0, 5 ) : [] );
				}
			} ).catch( function () {
				close();
			} );
		};

		var choose = function ( n ) {
			var s = items[ n ];
			close();

			if ( ! s ) {
				return;
			}

			var place = s.placePrediction.toPlace();

			place.fetchFields( { fields: [ 'addressComponents', 'location', 'formattedAddress' ] } ).then( function () {

				// A new session for the next search, as Google bills per session.
				token = new places.AutocompleteSessionToken();

				if ( 'point' === mode ) {
					fillPoint( place );
				} else {
					fillAddress( place );
				}
			} ).catch( function () {
				say( said( 'failed', 'Google didn’t answer. Type the address in full instead.' ), 'warn' );
			} );
		};

		var part = function ( place, type, long ) {
			var c = ( place.addressComponents || [] ).filter( function ( x ) {
				return x.types.indexOf( type ) !== -1;
			} )[ 0 ];
			return c ? ( long ? c.longText : c.shortText ) : '';
		};

		var pickOption = function ( select, name ) {
			if ( ! select || ! name ) {
				return false;
			}
			var want = name.trim().toLowerCase();
			var hit  = Array.prototype.filter.call( select.options, function ( o ) {
				return o.value && o.text.trim().toLowerCase() === want;
			} )[ 0 ];
			if ( hit ) {
				select.value = hit.value;
				select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				return true;
			}
			return false;
		};

		function fillAddress( place ) {

			var number = part( place, 'street_number' );
			var route  = part( place, 'route' );
			var unit   = part( place, 'subpremise' );

			if ( ! number || ! route ) {
				say( said( 'noNumber', 'That suggestion has no house number. Pick the exact address, with its number.' ), 'warn' );
				return;
			}

			var street = number + ' ' + route + ( unit ? ' #' + unit : '' );
			input.value = street;
			picked = street;

			var zip   = form ? form.querySelector( '[name="tdh_zip"]' ) : null;
			var city  = form ? form.querySelector( '[name="tdh_city"]' ) : null;
			var hood  = form ? form.querySelector( '[name="tdh_neighborhood"]' ) : null;
			var state = form ? form.querySelector( '[name="tdh_state"]' ) : null;

			var town = part( place, 'locality', true ) || part( place, 'postal_town', true ) || part( place, 'sublocality', true );

			if ( zip ) {
				zip.value = part( place, 'postal_code' );
			}

			if ( state ) {
				state.value = part( place, 'administrative_area_level_1' );
			}

			// The neighbourhood is a nicety: chosen only when it matches one we list.
			if ( hood && ! hood.value ) {
				pickOption( hood, part( place, 'neighborhood', true ) );
			}

			if ( city && ! pickOption( city, town ) ) {
				// A city left over from an earlier pick would be wrong for this
				// address: back to "Choose…", so the choice is made on purpose.
				if ( city.options.length > 2 ) {
					city.value = '';
				}
				say(
					( said( 'noCity', '%s isn’t on our city list yet. Choose the nearest city — our team can add it.' ) ).replace( '%s', town || said( 'thisTown', 'This town' ) ),
					'warn'
				);
				city.focus();
				return;
			}

			say( said( 'found', 'Address found. It will be placed on the map when you save.' ), 'ok' );
		}

		function fillPoint( place ) {
			var target = document.getElementById( input.getAttribute( 'data-tdh-places-target' ) || '' );

			if ( ! target || ! place.location ) {
				return;
			}

			target.value = place.location.lat().toFixed( 6 ) + ', ' + place.location.lng().toFixed( 6 );
			target.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			picked = input.value = place.formattedAddress || input.value;
			say( said( 'pointSet', 'Coordinates filled in from Google. Press Save location.' ), 'ok' );
		}

		input.addEventListener( 'input', function () {
			// Typing again after a pick: the filled-in state no longer applies.
			if ( 'address' === mode && picked && input.value.trim() !== picked ) {
				picked = '';
				var state = form ? form.querySelector( '[name="tdh_state"]' ) : null;
				if ( state ) {
					state.value = '';
				}
				say( '', '' );
			}
			clearTimeout( timer );
			timer = setTimeout( ask, 250 );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			var open = ! wrap.hidden && items.length;

			if ( 'ArrowDown' === e.key && open ) {
				e.preventDefault();
				mark( ( active + 1 ) % items.length );
			} else if ( 'ArrowUp' === e.key && open ) {
				e.preventDefault();
				mark( active <= 0 ? items.length - 1 : active - 1 );
			} else if ( 'Enter' === e.key && open && active >= 0 ) {
				// Enter picks the highlighted address instead of sending the form.
				e.preventDefault();
				choose( active );
			} else if ( 'Escape' === e.key && open ) {
				e.preventDefault();
				close();
			}
		} );

		input.addEventListener( 'blur', function () {
			setTimeout( close, 150 );

			if ( 'address' === mode && ! picked && input.value.trim().length >= 3 && items.length ) {
				say( said( 'pickHint', 'Pick your address from the list so we can place it on the map.' ), 'hint' );
			}
		} );
	}
} )();
