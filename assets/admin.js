/**
 * BLT Fluent — admin field ordering.
 *
 * Drag-and-drop uses jQuery UI Sortable, which ships with WP admin. The row
 * order is written back into a single hidden input as a comma separated list of
 * slugs, so no input names have to be renumbered on drop.
 */
( function ( $ ) {
	'use strict';

	function syncOrder( $tbody, $orderInput ) {
		var slugs = $tbody
			.children( 'tr' )
			.map( function () {
				return $( this ).data( 'slug' );
			} )
			.get()
			.filter( function ( slug ) {
				return !! slug;
			} );

		$orderInput.val( slugs.join( ',' ) );
	}

	$( function () {
		var $tbody = $( '#blt-fluent-sortable' );
		var $orderInput = $( '#blt-field-order' );

		if ( ! $tbody.length || ! $orderInput.length ) {
			return;
		}

		if ( typeof $.fn.sortable === 'function' ) {
			$tbody.sortable( {
				handle: '.blt-fluent-handle',
				axis: 'y',
				cursor: 'move',
				placeholder: 'blt-fluent-row-placeholder',
				helper: function ( event, ui ) {
					// Lock cell widths so the dragged row keeps its shape.
					ui.children().each( function () {
						$( this ).width( $( this ).width() );
					} );

					return ui;
				},
				update: function () {
					syncOrder( $tbody, $orderInput );
				}
			} );
		}

		// Keep the hidden value correct even if nothing is ever dragged.
		syncOrder( $tbody, $orderInput );

		// Dim rows that are not being collected, so the active set reads clearly.
		$tbody.on( 'change', 'input[type="checkbox"][name^="include"]', function () {
			$( this ).closest( 'tr' ).toggleClass( 'blt-fluent-row--off', ! this.checked );
		} );

		$tbody.find( 'input[type="checkbox"][name^="include"]' ).each( function () {
			$( this ).closest( 'tr' ).toggleClass( 'blt-fluent-row--off', ! this.checked );
		} );
	} );
}( jQuery ) );
