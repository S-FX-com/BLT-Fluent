/**
 * BLT Fluent — keep entered values across FluentCart's AJAX re-renders.
 *
 * FluentCart re-renders parts of the checkout when the address, coupon or total
 * changes. If our block is inside a replaced fragment, a half-filled field would
 * otherwise be wiped. Values are mirrored into sessionStorage as they are typed
 * and restored whenever the block reappears, then cleared on submit.
 */
( function () {
	'use strict';

	var STORAGE_PREFIX = 'bltFluent:';
	var SELECTOR = '.blt-fluent-fields';

	function storage() {
		try {
			return window.sessionStorage;
		} catch ( e ) {
			return null;
		}
	}

	function keyFor( input ) {
		return STORAGE_PREFIX + input.name;
	}

	function remember( input ) {
		var store = storage();

		if ( ! store || ! input.name ) {
			return;
		}

		try {
			if ( input.type === 'checkbox' || input.type === 'radio' ) {
				store.setItem( keyFor( input ) + '|' + input.value, input.checked ? '1' : '' );
			} else if ( input.multiple && input.selectedOptions ) {
				store.setItem(
					keyFor( input ),
					JSON.stringify(
						Array.prototype.map.call( input.selectedOptions, function ( option ) {
							return option.value;
						} )
					)
				);
			} else {
				store.setItem( keyFor( input ), input.value );
			}
		} catch ( e ) {
			// Storage full or blocked: nothing to do, the field simply won't persist.
		}
	}

	function restore( input ) {
		var store = storage();

		if ( ! store || ! input.name ) {
			return;
		}

		try {
			if ( input.type === 'checkbox' || input.type === 'radio' ) {
				var checked = store.getItem( keyFor( input ) + '|' + input.value );

				if ( checked !== null ) {
					input.checked = checked === '1';
				}

				return;
			}

			var stored = store.getItem( keyFor( input ) );

			if ( stored === null ) {
				return;
			}

			if ( input.multiple ) {
				var selected = JSON.parse( stored ) || [];

				Array.prototype.forEach.call( input.options, function ( option ) {
					option.selected = selected.indexOf( option.value ) !== -1;
				} );

				return;
			}

			if ( input.value === '' ) {
				input.value = stored;
			}
		} catch ( e ) {
			// Ignore malformed stored values.
		}
	}

	function eachField( callback ) {
		var blocks = document.querySelectorAll( SELECTOR );

		Array.prototype.forEach.call( blocks, function ( block ) {
			var inputs = block.querySelectorAll( 'input, select, textarea' );

			Array.prototype.forEach.call( inputs, callback );
		} );
	}

	function clearStored() {
		var store = storage();

		if ( ! store ) {
			return;
		}

		try {
			Object.keys( store )
				.filter( function ( key ) {
					return key.indexOf( STORAGE_PREFIX ) === 0;
				} )
				.forEach( function ( key ) {
					store.removeItem( key );
				} );
		} catch ( e ) {
			// Nothing we can do; stale values expire with the session anyway.
		}
	}

	document.addEventListener( 'input', function ( event ) {
		if ( event.target.closest && event.target.closest( SELECTOR ) ) {
			remember( event.target );
		}
	}, true );

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.closest && event.target.closest( SELECTOR ) ) {
			remember( event.target );
		}
	}, true );

	document.addEventListener( 'submit', clearStored, true );

	function init() {
		eachField( restore );

		if ( typeof window.MutationObserver !== 'function' || ! document.body ) {
			return;
		}

		var pending = null;

		new window.MutationObserver( function () {
			// Re-rendered fragments arrive in bursts; coalesce them.
			window.clearTimeout( pending );
			pending = window.setTimeout( function () {
				eachField( restore );
			}, 50 );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
