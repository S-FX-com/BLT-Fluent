/**
 * BLT Fluent — company selection.
 *
 * An accessible combobox over FluentCRM's companies: type to search, pick an
 * existing company or offer to create one, then save it to your own contact
 * record. No framework, no jQuery.
 *
 * Company names come from the database and from other members, so every value
 * is written with textContent — never innerHTML.
 */
( function () {
	'use strict';

	var CONFIG = window.bltFluentCompany || {};
	var I18N = CONFIG.i18n || {};
	var DEBOUNCE_MS = 250;

	function t( key, fallback ) {
		return I18N[ key ] || fallback;
	}

	/**
	 * One company selector instance.
	 *
	 * @param {HTMLElement} root Block element.
	 */
	function CompanySelector( root ) {
		this.root = root;

		try {
			this.settings = JSON.parse( root.getAttribute( 'data-blt-company' ) ) || {};
		} catch ( e ) {
			this.settings = {};
		}

		this.input = root.querySelector( '.blt-company__input' );
		this.list = root.querySelector( '.blt-company__list' );
		this.saveButton = root.querySelector( '.blt-company__save' );
		this.status = root.querySelector( '.blt-company__status' );
		this.currentName = root.querySelector( '[data-blt-company-current]' );

		if ( ! this.input || ! this.list || ! this.saveButton ) {
			return;
		}

		this.minChars = this.settings.minChars || 2;
		this.allowCreate = !! this.settings.allowCreate;
		this.currentId = this.settings.currentId || 0;

		this.options = [];
		this.activeIndex = -1;
		this.selection = null;
		this.cache = {};
		this.controller = null;
		this.timer = null;
		this.saving = false;

		this.bind();
	}

	CompanySelector.prototype.bind = function () {
		var self = this;

		this.input.addEventListener( 'input', function () {
			self.selection = null;
			self.refreshSaveState();
			self.scheduleSearch();
		} );

		this.input.addEventListener( 'keydown', function ( event ) {
			self.onKeyDown( event );
		} );

		this.input.addEventListener( 'focus', function () {
			if ( self.options.length ) {
				self.open();
			}
		} );

		// mousedown, not click: the input's blur must not close the list first.
		this.list.addEventListener( 'mousedown', function ( event ) {
			var item = event.target.closest( '[data-index]' );

			if ( ! item ) {
				return;
			}

			event.preventDefault();
			self.choose( parseInt( item.getAttribute( 'data-index' ), 10 ) );
		} );

		document.addEventListener( 'mousedown', function ( event ) {
			if ( ! self.root.contains( event.target ) ) {
				self.close();
			}
		} );

		this.saveButton.addEventListener( 'click', function () {
			self.save();
		} );
	};

	CompanySelector.prototype.scheduleSearch = function () {
		var self = this;
		var term = this.input.value.trim();

		window.clearTimeout( this.timer );

		if ( term.length < this.minChars ) {
			this.close();
			this.options = [];
			return;
		}

		this.timer = window.setTimeout( function () {
			self.search( term );
		}, DEBOUNCE_MS );
	};

	CompanySelector.prototype.search = function ( term ) {
		var self = this;

		if ( Object.prototype.hasOwnProperty.call( this.cache, term ) ) {
			this.show( term, this.cache[ term ] );
			return;
		}

		// Only the newest query matters.
		if ( this.controller ) {
			this.controller.abort();
		}

		this.controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;

		this.setStatus( t( 'searching', 'Searching…' ) );

		window.fetch( CONFIG.root + '/companies?q=' + encodeURIComponent( term ), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': CONFIG.nonce },
			signal: this.controller ? this.controller.signal : undefined
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'search failed' );
				}

				return response.json();
			} )
			.then( function ( data ) {
				var results = ( data && data.results ) || [];

				self.cache[ term ] = results;
				self.setStatus( '' );
				self.show( term, results );
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}

				self.setStatus( t( 'searchFail', 'Search is unavailable right now.' ) );
				self.show( term, [] );
			} );
	};

	/**
	 * Build the listbox for a set of results.
	 *
	 * @param {string} term    The term searched for.
	 * @param {Array}  results Company rows.
	 */
	CompanySelector.prototype.show = function ( term, results ) {
		var self = this;
		var typed = this.input.value.trim();

		// A late response for a term the member has already moved past.
		if ( typed !== term ) {
			return;
		}

		this.options = results.map( function ( company ) {
			return { type: 'company', id: company.id, name: company.name, meta: company.meta || '' };
		} );

		var exactMatch = this.options.some( function ( option ) {
			return option.name.toLowerCase() === typed.toLowerCase();
		} );

		if ( this.allowCreate && typed.length >= this.minChars && ! exactMatch ) {
			this.options.push( { type: 'create', id: 0, name: typed } );
		}

		this.list.textContent = '';
		this.activeIndex = -1;

		if ( ! this.options.length ) {
			var empty = document.createElement( 'li' );
			empty.className = 'blt-company__empty';
			empty.textContent = t( 'noResults', 'No matching companies found.' );
			this.list.appendChild( empty );
			this.open();
			return;
		}

		this.options.forEach( function ( option, index ) {
			self.list.appendChild( self.buildOption( option, index ) );
		} );

		this.open();
	};

	CompanySelector.prototype.buildOption = function ( option, index ) {
		var item = document.createElement( 'li' );

		item.className = 'blt-company__option' + ( 'create' === option.type ? ' blt-company__option--create' : '' );
		item.setAttribute( 'role', 'option' );
		item.setAttribute( 'id', this.input.id + '-option-' + index );
		item.setAttribute( 'data-index', String( index ) );
		item.setAttribute( 'aria-selected', 'false' );

		var name = document.createElement( 'span' );
		name.className = 'blt-company__option-name';

		if ( 'create' === option.type ) {
			name.textContent = t( 'addNew', '+ Add a new company: “%s”' ).replace( '%s', option.name );
		} else {
			name.textContent = option.name;
		}

		item.appendChild( name );

		if ( option.meta ) {
			var meta = document.createElement( 'span' );
			meta.className = 'blt-company__option-meta';
			meta.textContent = option.meta;
			item.appendChild( meta );
		}

		return item;
	};

	CompanySelector.prototype.open = function () {
		this.list.hidden = false;
		this.input.setAttribute( 'aria-expanded', 'true' );
	};

	CompanySelector.prototype.close = function () {
		this.list.hidden = true;
		this.input.setAttribute( 'aria-expanded', 'false' );
		this.input.removeAttribute( 'aria-activedescendant' );
		this.activeIndex = -1;
	};

	CompanySelector.prototype.setActive = function ( index ) {
		var items = this.list.querySelectorAll( '[data-index]' );

		if ( ! items.length ) {
			return;
		}

		if ( index < 0 ) {
			index = items.length - 1;
		} else if ( index >= items.length ) {
			index = 0;
		}

		Array.prototype.forEach.call( items, function ( item ) {
			item.classList.remove( 'is-active' );
			item.setAttribute( 'aria-selected', 'false' );
		} );

		var active = items[ index ];
		active.classList.add( 'is-active' );
		active.setAttribute( 'aria-selected', 'true' );

		this.activeIndex = index;
		this.input.setAttribute( 'aria-activedescendant', active.id );

		if ( active.scrollIntoView ) {
			active.scrollIntoView( { block: 'nearest' } );
		}
	};

	CompanySelector.prototype.onKeyDown = function ( event ) {
		switch ( event.key ) {
			case 'ArrowDown':
				event.preventDefault();

				if ( this.list.hidden && this.options.length ) {
					this.open();
				}

				this.setActive( this.activeIndex + 1 );
				break;

			case 'ArrowUp':
				event.preventDefault();
				this.setActive( this.activeIndex - 1 );
				break;

			case 'Enter':
				// Always swallow Enter: the block may sit inside somebody else's
				// form, and submitting it here would lose the member's work.
				event.preventDefault();

				if ( ! this.list.hidden && this.activeIndex > -1 ) {
					this.choose( this.activeIndex );
				} else if ( ! this.saveButton.disabled ) {
					this.save();
				}
				break;

			case 'Escape':
				this.close();
				break;

			case 'Tab':
				this.close();
				break;
		}
	};

	CompanySelector.prototype.choose = function ( index ) {
		var option = this.options[ index ];

		if ( ! option ) {
			return;
		}

		this.selection = option;
		this.input.value = option.name;
		this.close();
		this.setStatus( '' );
		this.refreshSaveState();
		this.saveButton.focus();
	};

	CompanySelector.prototype.refreshSaveState = function () {
		var selection = this.selection;
		var enabled = false;

		if ( selection && ! this.saving ) {
			enabled = 'create' === selection.type || selection.id !== this.currentId;
		}

		this.saveButton.disabled = ! enabled;
	};

	CompanySelector.prototype.setStatus = function ( message, isError ) {
		if ( ! this.status ) {
			return;
		}

		this.status.textContent = message;
		this.status.classList.toggle( 'is-error', !! isError );
	};

	CompanySelector.prototype.save = function () {
		var self = this;

		if ( ! this.selection || this.saving ) {
			return;
		}

		var body = 'create' === this.selection.type
			? { company_name: this.selection.name }
			: { company_id: this.selection.id };

		this.saving = true;
		this.saveButton.disabled = true;
		this.setStatus( t( 'saving', 'Saving…' ) );

		window.fetch( CONFIG.root + '/company', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CONFIG.nonce
			},
			body: JSON.stringify( body )
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || t( 'error', 'Sorry, that could not be saved.' ) );
					}

					return data;
				} );
			} )
			.then( function ( data ) {
				var company = data.company || {};

				self.currentId = company.id || 0;
				self.selection = null;
				self.cache = {};

				if ( self.currentName ) {
					self.currentName.textContent = company.name || t( 'notSet', 'Not set' );
				}

				if ( company.name ) {
					self.input.value = company.name;
				}

				self.setStatus( data.created ? t( 'savedNew', 'Company created and saved.' ) : t( 'saved', 'Your company has been updated.' ) );
			} )
			.catch( function ( error ) {
				self.setStatus( error.message || t( 'error', 'Sorry, that could not be saved.' ), true );
			} )
			.then( function () {
				self.saving = false;
				self.refreshSaveState();
			} );
	};

	function init() {
		var blocks = document.querySelectorAll( '.blt-company[data-blt-company]' );

		Array.prototype.forEach.call( blocks, function ( block ) {
			if ( ! block.bltCompanyReady ) {
				block.bltCompanyReady = true;
				new CompanySelector( block );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
