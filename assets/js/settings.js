/**
 * Settings screen behaviour.
 *
 * Vanilla, no build step, no framework. Everything translatable is handed over by
 * wp_localize_script as wpcaiSettings, so there is no JSON catalogue to ship.
 */
( function () {
	'use strict';

	/**
	 * Write a status line into one of the aria-live paragraphs.
	 *
	 * @param {HTMLElement} element Target paragraph.
	 * @param {string}      message Text to show.
	 * @param {boolean}     isError Whether to style it as a failure.
	 * @return {void}
	 */
	function report( element, message, isError ) {
		if ( ! element ) {
			return;
		}

		element.textContent = message;
		element.classList.toggle( 'wpcai-error', !! isError );
		element.classList.toggle( 'wpcai-ok', ! isError && !! message );
	}

	/**
	 * Read the credentials currently in the form.
	 *
	 * Both buttons act on what has been typed rather than on what is stored, so a
	 * new key can be tested before it is saved. The field is left empty when a key
	 * is already stored, and sending nothing is what tells the server to fall back
	 * to it.
	 *
	 * @return {FormData} The provider and, if one was typed, the key.
	 */
	function credentials() {
		var body = new FormData();
		var provider = document.getElementById( 'wpcai-provider' );
		var key = document.getElementById( 'wpcai-api-key' );

		if ( provider ) {
			body.append( 'provider', provider.value );
		}

		if ( key && key.value ) {
			body.append( 'api_key', key.value );
		}

		return body;
	}

	/**
	 * POST to admin-ajax and hand back the decoded reply.
	 *
	 * @param {string}   action   The wp_ajax action to call.
	 * @param {string}   nonce    Nonce for that action.
	 * @param {FormData} body     Fields to send.
	 * @return {Promise} Resolves with the parsed JSON response.
	 */
	function send( action, nonce, body ) {
		body.append( 'action', action );
		body.append( 'nonce', nonce );

		return fetch( wpcaiSettings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Put a button into or out of its working state.
	 *
	 * @param {HTMLElement} button  The button.
	 * @param {boolean}     working Whether it is mid-request.
	 * @return {void}
	 */
	function busy( button, working ) {
		button.disabled = working;
		button.classList.toggle( 'wpcai-busy', working );
	}

	/**
	 * Wire the connection test.
	 *
	 * @return {void}
	 */
	function bindConnectionTest() {
		var button = document.getElementById( 'wpcai-test-connection' );
		var result = document.getElementById( 'wpcai-connection-result' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			busy( button, true );
			report( result, wpcaiSettings.testing, false );

			send( 'wpcai_test_connection', wpcaiSettings.testNonce, credentials() )
				.then( function ( response ) {
					if ( response && response.success ) {
						report( result, response.data.message || wpcaiSettings.connected, false );
						return;
					}

					report( result, ( response && response.data && response.data.message ) || wpcaiSettings.testFailed, true );
				} )
				.catch( function () {
					report( result, wpcaiSettings.testFailed, true );
				} )
				.finally( function () {
					busy( button, false );
				} );
		} );
	}

	/**
	 * Rebuild the model dropdown from a fetched list.
	 *
	 * Whatever was selected is kept selected, and re-added if the account does not
	 * list it: a model that still works but no longer appears must not be silently
	 * swapped for another one just because someone pressed Fetch.
	 *
	 * @param {Object} data Recommended labels and other model ids.
	 * @return {void}
	 */
	function fillModels( data ) {
		var select = document.getElementById( 'wpcai-model' );
		var previous;
		var seen = false;
		var group;

		if ( ! select ) {
			return;
		}

		previous = select.value;
		select.innerHTML = '';

		select.appendChild( new Option( wpcaiSettings.providerDefault, '' ) );

		group = document.createElement( 'optgroup' );
		group.label = wpcaiSettings.recommendedName;

		Object.keys( data.recommended || {} ).forEach( function ( id ) {
			group.appendChild( new Option( data.recommended[ id ], id ) );
			seen = seen || id === previous;
		} );

		if ( group.children.length ) {
			select.appendChild( group );
		}

		group = document.createElement( 'optgroup' );
		group.label = wpcaiSettings.otherName;

		( data.other || [] ).forEach( function ( id ) {
			group.appendChild( new Option( id, id ) );
			seen = seen || id === previous;
		} );

		if ( group.children.length ) {
			select.appendChild( group );
		}

		if ( previous && ! seen ) {
			select.appendChild( new Option( previous, previous ) );
		}

		select.value = previous;
	}

	/**
	 * Wire the model fetch.
	 *
	 * @return {void}
	 */
	function bindModelFetch() {
		var button = document.getElementById( 'wpcai-fetch-models' );
		var result = document.getElementById( 'wpcai-models-result' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			busy( button, true );
			report( result, wpcaiSettings.fetchingModels, false );

			send( 'wpcai_fetch_models', wpcaiSettings.modelsNonce, credentials() )
				.then( function ( response ) {
					if ( response && response.success ) {
						fillModels( response.data );
						report( result, wpcaiSettings.modelsLoaded, false );
						return;
					}

					report( result, ( response && response.data && response.data.message ) || wpcaiSettings.modelsFailed, true );
				} )
				.catch( function () {
					report( result, wpcaiSettings.modelsFailed, true );
				} )
				.finally( function () {
					busy( button, false );
				} );
		} );
	}

	/**
	 * Apply one job's reported state to its row.
	 *
	 * @param {HTMLElement} row   The job's table row.
	 * @param {Object}      state What the server reported.
	 * @return {void}
	 */
	function paintJob( row, state ) {
		var summary = row.querySelector( '.wpcai-job-summary' );
		var position = row.querySelector( '.wpcai-job-position' );
		var bar = row.querySelector( '.wpcai-job-progress' );
		var button = row.querySelector( 'button[type="submit"]' );

		if ( summary ) {
			summary.textContent = state.summary;
		}

		if ( position ) {
			position.textContent = state.position;
		}

		if ( bar ) {
			bar.hidden = ! state.running;

			// An indeterminate bar is what "running, but I cannot say how far" looks
			// like. Removing the attribute is the only way to get one.
			if ( null === state.percentage ) {
				bar.removeAttribute( 'value' );
			} else {
				bar.value = state.percentage;
			}
		}

		if ( button ) {
			button.disabled = state.running;
		}
	}

	/**
	 * Poll for job progress while anything is running, and stop when nothing is.
	 *
	 * @return {void}
	 */
	function bindProgressPoll() {
		var rows = document.querySelectorAll( '[data-wpcai-job]' );
		var timer = null;

		if ( ! rows.length ) {
			return;
		}

		/**
		 * Whether any row is currently showing a running job.
		 *
		 * @return {boolean} True when something is in flight.
		 */
		function anythingRunning() {
			return Array.prototype.some.call( rows, function ( row ) {
				var button = row.querySelector( 'button[type="submit"]' );
				return button && button.disabled;
			} );
		}

		/**
		 * Ask the server where everything has got to.
		 *
		 * @return {void}
		 */
		function poll() {
			var body = new FormData();

			send( 'wpcai_job_progress', wpcaiSettings.progressNonce, body )
				.then( function ( response ) {
					var wasRunning = anythingRunning();

					if ( ! response || ! response.success ) {
						return;
					}

					Array.prototype.forEach.call( rows, function ( row ) {
						var state = response.data.jobs[ row.getAttribute( 'data-wpcai-job' ) ];

						if ( state ) {
							paintJob( row, state );
						}
					} );

					// A job that has just finished may have produced a draft to review,
					// and that is rendered by PHP. Reload rather than trying to build it
					// here — one implementation of that markup, not two.
					if ( wasRunning && ! anythingRunning() ) {
						window.location.reload();
						return;
					}

					if ( anythingRunning() ) {
						timer = window.setTimeout( poll, wpcaiSettings.progressInterval );
					}
				} )
				.catch( function () {
					// Stop rather than hammer a server that is not answering.
					window.clearTimeout( timer );
				} );
		}

		if ( anythingRunning() ) {
			timer = window.setTimeout( poll, wpcaiSettings.progressInterval );
		}

		// A job queued from this page starts a moment later, so begin polling on
		// submit rather than waiting for the next page load to notice.
		Array.prototype.forEach.call( rows, function ( row ) {
			var form = row.querySelector( 'form' );

			if ( form ) {
				form.addEventListener( 'submit', function () {
					window.clearTimeout( timer );
					timer = window.setTimeout( poll, wpcaiSettings.progressInterval );
				} );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindConnectionTest();
		bindModelFetch();
		bindProgressPoll();
	} );
}() );
