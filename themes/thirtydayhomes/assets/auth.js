/**
 * The account screens' small helpers (G3b, G4a).
 *
 * 1. Show / Hide on password fields. The plugin prints each toggle hidden;
 *    this reveals it and does the work. Without the script the field is a
 *    plain password field. The word changes and aria-pressed carries the
 *    state for a screen reader.
 * 2. "Send code" for text alerts waits for a number and the consent tick,
 *    and the line beside it says so. Without the script the button is
 *    always pressable and the server refuses what is missing, as before.
 * 3. On a phone the listing cards' "More" menus start closed; on a wider
 *    screen, or with no script, their actions sit in the row.
 */
( function () {
	'use strict';

	Array.prototype.forEach.call( document.querySelectorAll( '[data-tdh-pw]' ), function ( button ) {
		var input = document.getElementById( button.getAttribute( 'data-tdh-pw' ) );

		if ( ! input ) {
			return;
		}

		var showWord = button.textContent.trim();
		var hideWord = button.getAttribute( 'data-hide' ) || 'Hide';

		button.hidden = false;

		button.addEventListener( 'click', function () {
			var shown = 'text' === input.type;

			input.type = shown ? 'password' : 'text';
			button.textContent = shown ? showWord : hideWord;
			button.setAttribute( 'aria-pressed', shown ? 'false' : 'true' );
			button.setAttribute( 'aria-label', shown ? 'Show password' : 'Hide password' );
			input.focus( { preventScroll: true } );
		} );
	} );

	// Add member / Add facility (G5a): the header button opens the form
	// panel where it stands and puts the cursor in the first field. The
	// link itself (?add=1) does the same without a script.
	var calm = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	Array.prototype.forEach.call( document.querySelectorAll( '[data-tdh-add]' ), function ( button ) {
		var panel = document.getElementById( button.getAttribute( 'data-tdh-add' ) );

		if ( ! panel ) {
			return;
		}

		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			panel.hidden = false;
			button.setAttribute( 'aria-expanded', 'true' );
			panel.scrollIntoView( { block: 'start', behavior: calm ? 'auto' : 'smooth' } );

			var first = panel.querySelector( 'input:not([type="hidden"]), select, textarea' );
			if ( first ) {
				first.focus( { preventScroll: true } );
			}
		} );
	} );

	Array.prototype.forEach.call( document.querySelectorAll( '[data-tdh-add-close]' ), function ( link ) {
		var id     = link.getAttribute( 'data-tdh-add-close' );
		var panel  = document.getElementById( id );
		var button = document.querySelector( '[data-tdh-add="' + id + '"]' );

		if ( ! panel ) {
			return;
		}

		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			panel.hidden = true;
			if ( button ) {
				button.setAttribute( 'aria-expanded', 'false' );
				button.focus();
			}
		} );
	} );

	Array.prototype.forEach.call( document.querySelectorAll( '[data-tdh-sms-start]' ), function ( form ) {
		var phone  = form.querySelector( 'input[type="tel"]' );
		var tick   = form.querySelector( 'input[type="checkbox"]' );
		var button = form.querySelector( 'button[type="submit"]' );
		var wait   = form.querySelector( '.sms-wait' );

		if ( ! phone || ! tick || ! button ) {
			return;
		}

		var sync = function () {
			var ready = phone.value.replace( /\D/g, '' ).length >= 10 && tick.checked;

			button.disabled = ! ready;
			button.setAttribute( 'aria-disabled', ready ? 'false' : 'true' );

			if ( wait ) {
				wait.hidden = ready;
			}
		};

		phone.addEventListener( 'input', sync );
		tick.addEventListener( 'change', sync );
		sync();
	} );

	var narrow = window.matchMedia( '(max-width: 43.75rem)' );

	var setMenus = function () {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-tdh-overflow]' ), function ( menu ) {
			menu.open = ! narrow.matches;
		} );
	};

	setMenus();

	if ( narrow.addEventListener ) {
		narrow.addEventListener( 'change', setMenus );
	}
} )();
