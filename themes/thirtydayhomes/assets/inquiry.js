/**
 * The inquiry form's sending state.
 *
 * Two jobs, both small, and the form works without either of them:
 *
 * 1. Say that something is happening. A form that sits still after Send
 *    reads as broken, and the renter presses again.
 * 2. Make the second press impossible. The server already treats an
 *    identical inquiry within ten minutes as the one it has, so a double
 *    press is harmless — but it should not look like it needs one.
 *
 * On a validation error the browser blocks the submit and fires nothing,
 * so the button must not be disabled until the form is genuinely on its
 * way: `submit` fires after the browser's own checks have passed.
 */
( function () {
	'use strict';

	var form = document.querySelector( '.inquiry-form' );

	if ( ! form ) {
		return;
	}

	var button = form.querySelector( '[data-tdh-send]' );

	if ( ! button ) {
		return;
	}

	form.addEventListener( 'submit', function () {

		var sending = button.getAttribute( 'data-sending' );

		/*
		 * A disabled button is not submitted with the form, but this one
		 * carries no value the handler reads, so disabling it is safe.
		 * aria-disabled as well, because a screen reader should hear the
		 * state change and not only see it.
		 */
		button.disabled = true;
		button.setAttribute( 'aria-disabled', 'true' );

		if ( sending ) {
			button.textContent = sending;
		}
	} );

	/*
	 * Back-button restore. Browsers return a cached page with the button
	 * still disabled from the send that took the visitor away, which
	 * leaves a dead form behind. Put it back.
	 */
	window.addEventListener( 'pageshow', function ( event ) {

		if ( ! event.persisted ) {
			return;
		}

		button.disabled = false;
		button.removeAttribute( 'aria-disabled' );
	} );

	/*
	 * Send the renter straight to the first field that was refused, so a
	 * long form does not have to be hunted through. Only on load, and only
	 * when the server marked something: the browser handles its own
	 * required-field focus.
	 */
	var refused = form.querySelector( '[aria-invalid="true"]' );

	if ( refused ) {
		refused.focus( { preventScroll: true } );
		refused.scrollIntoView( { block: 'center', behavior: 'auto' } );
	}
}() );
