/**
 * Theme front-end behaviour.
 *
 * Two small things, deliberately dependency-free: the mobile navigation
 * toggle and the hero search date gate.
 */
( function () {
	'use strict';

	/* ---------------------------------------------------------------
	 * Mobile navigation
	 * ------------------------------------------------------------ */

	var toggle = document.querySelector( '.nav-toggle' );
	var nav    = document.getElementById( 'site-nav' );

	if ( toggle && nav ) {
		var labelOpen  = toggle.getAttribute( 'aria-label' ) || 'Open menu';
		var labelClose = 'Close menu';

		var setOpen = function ( open ) {
			nav.classList.toggle( 'open', open );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			toggle.setAttribute( 'aria-label', open ? labelClose : labelOpen );
		};

		toggle.addEventListener( 'click', function () {
			setOpen( 'true' !== toggle.getAttribute( 'aria-expanded' ) );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && nav.classList.contains( 'open' ) ) {
				setOpen( false );
				toggle.focus();
			}
		} );
	}

	/* ---------------------------------------------------------------
	 * Hero search date gate
	 *
	 * The approved design keeps "Search homes" dimmed until both dates
	 * are set. Two things make that safe rather than merely decorative:
	 *
	 *   - the end date's `min` tracks the start date, so a stay that
	 *     ends before it begins cannot be expressed in the first place;
	 *   - the hint carries role="status", so the button becoming usable
	 *     is announced rather than silently changing.
	 *
	 * The inputs are also marked `required`, so if this script never
	 * runs the browser's own validation still blocks an empty search.
	 * ------------------------------------------------------------ */

	var form = document.querySelector( '[data-tdh-hero-search]' );

	if ( ! form ) {
		return;
	}

	var start  = form.querySelector( '[data-tdh-start]' );
	var end    = form.querySelector( '[data-tdh-end]' );
	var submit = form.querySelector( '[data-tdh-submit]' );
	var hint   = document.getElementById( 'tdh-search-hint' );

	if ( ! start || ! end || ! submit ) {
		return;
	}

	var readyText = 'Ready to search.';
	var waitText  = hint ? hint.textContent.trim() : '';
	var wasReady  = false;

	function sync() {
		// An end date before the start date is not a stay.
		end.min = start.value || '';

		if ( start.value && end.value && end.value < start.value ) {
			end.value = '';
		}

		var ready = Boolean( start.value && end.value );

		submit.disabled = ! ready;

		if ( hint && ready !== wasReady ) {
			hint.textContent = ready ? readyText : waitText;
			wasReady = ready;
		}
	}

	start.addEventListener( 'change', sync );
	end.addEventListener( 'change', sync );
	start.addEventListener( 'input', sync );
	end.addEventListener( 'input', sync );

	sync();
} )();
