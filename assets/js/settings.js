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

	document.addEventListener( 'DOMContentLoaded', function () {
		bindConnectionTest();
		bindModelFetch();
	} );
}() );
