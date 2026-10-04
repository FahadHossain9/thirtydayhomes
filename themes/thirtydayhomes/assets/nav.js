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

		/*
		 * The dimmed layer behind the panel, created here rather than in the
		 * template. It exists only to be clicked, so a page with no
		 * JavaScript has no reason to carry it — and with no JavaScript the
		 * drawer never opens, so there would be nothing for it to sit behind.
		 */
		var backdrop = document.createElement( 'div' );
		backdrop.className = 'nav-backdrop';
		backdrop.hidden = false;
		document.body.appendChild( backdrop );

		var isOpen = function () {
			return nav.classList.contains( 'open' );
		};

		var setOpen = function ( open ) {
			nav.classList.toggle( 'open', open );
			backdrop.classList.toggle( 'open', open );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			toggle.setAttribute( 'aria-label', open ? labelClose : labelOpen );

			// The panel is fixed; without this the page scrolls underneath it
			// and the menu appears to drift away as you swipe.
			document.documentElement.classList.toggle( 'tdh-nav-open', open );

			if ( open ) {
				// Move into the panel, so the next Tab is a menu item rather
				// than something behind the overlay.
				var first = nav.querySelector( 'a' );
				if ( first ) {
					first.focus();
				}
			}
		};

		toggle.addEventListener( 'click', function () {
			setOpen( ! isOpen() );
		} );

		backdrop.addEventListener( 'click', function () {
			setOpen( false );
			toggle.focus();
		} );

		/*
		 * Close when a link is followed. Most navigations replace the page
		 * and the panel goes with it, but an in-page anchor does not — and a
		 * menu left covering the thing it just scrolled to is worse than one
		 * that never opened.
		 */
		nav.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( 'a' ) ) {
				setOpen( false );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {

			if ( ! isOpen() ) {
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

			/*
			 * Keep Tab inside the panel while it is open. Without this the
			 * focus ring walks off into the page behind the overlay, where
			 * nothing is visible and a keyboard user has no idea where they
			 * are. The toggle counts as part of the panel, since it is how
			 * you close it.
			 */
			var focusable = [ toggle ].concat(
				Array.prototype.slice.call( nav.querySelectorAll( 'a[href], button:not([disabled])' ) )
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

		/*
		 * Above the breakpoint the navigation is an ordinary row again, and a
		 * panel left "open" from a narrower window would keep the scroll lock
		 * on a desktop page that has no menu to close.
		 */
		var wide = window.matchMedia( '(min-width: 62.0625rem)' );

		var onWide = function ( event ) {
			if ( event.matches && isOpen() ) {
				setOpen( false );
			}
		};

		if ( wide.addEventListener ) {
			wide.addEventListener( 'change', onWide );
		} else if ( wide.addListener ) {
			wide.addListener( onWide );
		}
	}

	/* ---------------------------------------------------------------
	 * Marketplace portal sidebar
	 * ------------------------------------------------------------ */

	var portal       = document.querySelector( '.portal' );
	var portalSide   = document.getElementById( 'portal-sidebar' );
	var portalToggle = document.querySelector( '.portal-menu-toggle' );

	if ( portal && portalSide && portalToggle ) {
		var portalKey = 'tdh_portal_sidebar_collapsed';
		var compact   = window.matchMedia( '(max-width: 62rem)' );
		var stored    = null;

		try {
			stored = window.localStorage.getItem( portalKey );
		} catch ( e ) {
			stored = null;
		}

		var setPortalCollapsed = function ( collapsed, remember ) {
			portal.classList.toggle( 'is-sidebar-collapsed', collapsed );
			portalToggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
			portalToggle.setAttribute( 'aria-label', collapsed ? 'Expand dashboard sidebar' : 'Collapse dashboard sidebar' );

			/* On desktop, collapsed means an accessible icon rail. On compact
			 * screens it becomes a true hidden drawer to preserve content width. */
			portalSide.setAttribute( 'aria-hidden', collapsed && compact.matches ? 'true' : 'false' );
			portalSide.inert = collapsed && compact.matches;

			if ( remember ) {
				try {
					window.localStorage.setItem( portalKey, collapsed ? '1' : '0' );
				} catch ( e ) {
					// The control still works when storage is unavailable.
				}
			}
		};

		setPortalCollapsed( null === stored ? compact.matches : '1' === stored, false );

		portalToggle.addEventListener( 'click', function () {
			setPortalCollapsed( ! portal.classList.contains( 'is-sidebar-collapsed' ), true );
		} );

		portalSide.addEventListener( 'click', function ( event ) {
			if ( compact.matches && event.target.closest( 'a' ) ) {
				setPortalCollapsed( true, false );
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! portal.classList.contains( 'is-sidebar-collapsed' ) && compact.matches ) {
				setPortalCollapsed( true, false );
				portalToggle.focus();
			}
		} );
	}

	/* ---------------------------------------------------------------
	 * Hero search dates
	 *
	 * The end date's `min` tracks the start date, so a stay that ends
	 * before it begins cannot be expressed in the first place. The
	 * button itself is always usable (G2 design review): dates narrow
	 * the search, they are not a condition of it, and the results page
	 * handles a search with no dates.
	 * ------------------------------------------------------------ */

	var form = document.querySelector( '[data-tdh-hero-search]' );

	if ( ! form ) {
		return;
	}

	var start = form.querySelector( '[data-tdh-start]' );
	var end   = form.querySelector( '[data-tdh-end]' );

	if ( ! start || ! end ) {
		return;
	}

	function sync() {
		// An end date before the start date is not a stay.
		end.min = start.value || '';

		if ( start.value && end.value && end.value < start.value ) {
			end.value = '';
		}
	}

	start.addEventListener( 'change', sync );
	end.addEventListener( 'change', sync );
	start.addEventListener( 'input', sync );
	end.addEventListener( 'input', sync );

	sync();
} )();
