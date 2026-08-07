/**
 * The category draft editor.
 *
 * Does three things and no more: confirms the destructive actions, keeps the path
 * label under each name field honest as it is typed, and greys out the descendants
 * of a row marked for removal so the consequence is visible before it is saved.
 *
 * It never builds a row. Every bit of that markup is rendered by PHP, so there is
 * one implementation of it rather than two that drift apart.
 */
( function () {
	'use strict';

	var SEPARATOR = ' › ';

	/**
	 * Every node row, in the document order PHP emitted them: pre-order, so a
	 * parent is always seen before its children.
	 *
	 * @return {Array} The rows.
	 */
	function rows() {
		return Array.prototype.slice.call( document.querySelectorAll( '.wpcai-node' ) );
	}

	/**
	 * Index the rows by their node key.
	 *
	 * @param {Array} all The rows.
	 * @return {Object} Key to row.
	 */
	function index( all ) {
		var byKey = {};

		all.forEach( function ( row ) {
			byKey[ row.getAttribute( 'data-key' ) ] = row;
		} );

		return byKey;
	}

	/**
	 * Whether a row, or anything it descends from, is marked for removal.
	 *
	 * @param {HTMLElement} row   The row.
	 * @param {Object}      byKey Key to row.
	 * @return {boolean} True when this row will go.
	 */
	function doomed( row, byKey ) {
		var guard = 0;
		var current = row;

		while ( current && guard < 10 ) {
			if ( current.querySelector( 'input[type="checkbox"]' ).checked ) {
				return true;
			}

			current = byKey[ current.getAttribute( 'data-parent' ) ];
			guard++;
		}

		return false;
	}

	/**
	 * Rebuild every path label and removal state.
	 *
	 * Recomputed for the whole table rather than for the row that changed, because
	 * renaming a parent changes the label of everything beneath it. The tables here
	 * are tens of rows, not thousands.
	 *
	 * @return {void}
	 */
	function refresh() {
		var all = rows();
		var byKey = index( all );
		var paths = {};

		all.forEach( function ( row ) {
			var key = row.getAttribute( 'data-key' );
			var parent = row.getAttribute( 'data-parent' );
			var name = row.querySelector( '.wpcai-node-name' ).value.trim();
			var label = row.querySelector( '.wpcai-path' );
			var gone = doomed( row, byKey );

			paths[ key ] = ( parent && paths[ parent ] ? paths[ parent ] + SEPARATOR : '' ) + name;

			if ( label ) {
				label.textContent = paths[ key ];
			}

			row.classList.toggle( 'wpcai-removing', gone );

			// A descendant of a removed row is going too, and saying so before the
			// save is the whole point: removing a top-level category takes its entire
			// branch, which is not obvious from a flat table.
			row.querySelector( '.wpcai-node-name' ).disabled = false;
		} );
	}

	/**
	 * Ask before anything destructive.
	 *
	 * @return {void}
	 */
	function bindConfirms() {
		var buttons = document.querySelectorAll( '[data-wpcai-confirm]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( button.getAttribute( 'data-wpcai-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var table = document.querySelector( '.wpcai-tree' );

		bindConfirms();

		if ( ! table ) {
			return;
		}

		table.addEventListener( 'input', refresh );
		table.addEventListener( 'change', refresh );

		refresh();
	} );
}() );
