/**
 * Regenerate button for the AI Media Search description panel.
 *
 * The panel is printed in the Edit Media meta box and inside the media modal,
 * and the modal tears its details view down and rebuilds it whenever the
 * selection changes. A delegated listener on the document survives that, so
 * there is nothing to re-bind and no view to extend.
 */
( function () {
	'use strict';

	var settings = window.aiMediaSearchAdmin || {};

	/**
	 * Show a message next to the button.
	 *
	 * @param {HTMLElement} panel   The panel element.
	 * @param {string}      message Text to show.
	 */
	function setFeedback( panel, message ) {
		var feedback = panel.querySelector( '.ai-media-search-feedback' );

		if ( feedback ) {
			feedback.textContent = message;
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var button = target.closest( '.ai-media-search-regenerate' );

		if ( ! button ) {
			return;
		}

		var panel = button.closest( '.ai-media-search-panel' );
		var id = button.getAttribute( 'data-attachment-id' );
		var nonce = button.getAttribute( 'data-nonce' );

		if ( ! panel || ! id || ! nonce || ! settings.root ) {
			return;
		}

		event.preventDefault();

		button.disabled = true;
		setFeedback( panel, settings.working || '' );

		window
			.fetch( settings.root + 'attachments/' + encodeURIComponent( id ) + '/regenerate', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': settings.nonce || '',
				},
				body: JSON.stringify( { nonce: nonce } ),
			} )
			.then( function ( response ) {
				return response.json().then( function ( body ) {
					if ( ! response.ok ) {
						throw new Error( ( body && body.message ) || '' );
					}

					return body;
				} );
			} )
			.then( function ( body ) {
				// The response carries the freshly rendered panel, including a
				// new nonce, so the markup never drifts from what PHP would print.
				if ( body && body.html ) {
					panel.outerHTML = body.html;
				} else {
					button.disabled = false;
					setFeedback( panel, '' );
				}
			} )
			.catch( function ( error ) {
				button.disabled = false;
				setFeedback( panel, error.message || settings.failed || '' );
			} );
	} );
} )();
