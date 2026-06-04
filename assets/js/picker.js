/**
 * Razuna asset picker (framework-agnostic, no build step).
 *
 * Browse workspaces/folders, search, pick a file, choose how to insert it
 * (size or a saved format, optionally as a download link), then confirm. All
 * data comes from the same-origin WP REST proxy (wp-json/razuna/v1/*), so the
 * Razuna OAuth token never reaches the browser.
 *
 * Usage:
 *   RazunaPicker.mount(containerEl, { onSelect: fn });
 *   // onSelect receives a resolved payload:
 *   //   { id, name, alt, url, full_url, download_url, is_image, width, height, label }
 */
( function ( window, document ) {
	'use strict';

	var Config = window.RazunaConfig || {};
	var i18n = Config.i18n || {};

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( 'class' === k ) {
				node.className = attrs[ k ];
			} else if ( 'text' === k ) {
				node.textContent = attrs[ k ];
			} else if ( 0 === k.indexOf( 'on' ) && typeof attrs[ k ] === 'function' ) {
				node.addEventListener( k.slice( 2 ).toLowerCase(), attrs[ k ] );
			} else if ( null !== attrs[ k ] && undefined !== attrs[ k ] ) {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( null === c || undefined === c ) {
				return;
			}
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function api( path, params ) {
		var url = Config.restBase + path;
		if ( params ) {
			var qs = Object.keys( params )
				.filter( function ( k ) {
					return params[ k ] !== '' && params[ k ] !== null && params[ k ] !== undefined;
				} )
				.map( function ( k ) {
					return encodeURIComponent( k ) + '=' + encodeURIComponent( params[ k ] );
				} )
				.join( '&' );
			if ( qs ) {
				url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + qs;
			}
		}
		return window
			.fetch( url, { headers: { 'X-WP-Nonce': Config.nonce }, credentials: 'same-origin' } )
			.then( function ( res ) {
				return res.json().then( function ( body ) {
					if ( ! res.ok ) {
						var err = new Error( body && body.message ? body.message : 'Request failed' );
						err.status = res.status;
						throw err;
					}
					return body;
				} );
			} );
	}

	function Picker( container, options ) {
		this.container = container;
		this.onSelect = options.onSelect || function () {};
		this.state = { workspaceId: '', folderId: '', term: '', items: [], selected: null, formats: [] };
		this.nodes = {};
		this.render();
		this.loadWorkspaces();
	}

	Picker.prototype.render = function () {
		this.container.innerHTML = '';
		this.container.classList.add( 'razuna-picker' );

		if ( ! Config.connected ) {
			this.container.appendChild(
				el( 'div', { class: 'razuna-picker__empty' }, [
					el( 'p', { text: i18n.notConnected || 'Connect your Razuna account first.' } ),
					Config.settingsUrl
						? el( 'a', { href: Config.settingsUrl, class: 'button button-primary', target: '_blank', rel: 'noopener', text: 'Open settings' } )
						: null,
				] )
			);
			return;
		}

		var self = this;

		this.nodes.workspace = el( 'select', { class: 'razuna-picker__workspace', onChange: function ( e ) {
			self.state.workspaceId = e.target.value;
			self.state.folderId = '';
			self.loadFolders();
		} } );

		this.nodes.folder = el( 'select', { class: 'razuna-picker__folder', onChange: function ( e ) {
			self.state.folderId = e.target.value;
			self.loadFiles();
		} } );

		this.nodes.search = el( 'input', {
			type: 'search',
			class: 'razuna-picker__search',
			placeholder: i18n.searchPlaceholder || 'Search…',
			onKeydown: function ( e ) {
				if ( 'Enter' === e.key ) {
					e.preventDefault();
					self.state.term = e.target.value.trim();
					self.state.term ? self.runSearch() : self.loadFiles();
				}
			},
		} );

		this.nodes.results = el( 'div', { class: 'razuna-picker__results' } );
		this.nodes.status = el( 'div', { class: 'razuna-picker__msg' } );
		this.nodes.details = el( 'div', { class: 'razuna-picker__details' } );

		// One aligned toolbar row: each control is a labelled column so the inputs
		// line up on the same baseline (including search).
		function field( label, control ) {
			return el( 'label', { class: 'razuna-picker__field' }, [
				el( 'span', { class: 'razuna-picker__label', text: label } ),
				control,
			] );
		}

		this.container.appendChild(
			el( 'div', { class: 'razuna-picker__toolbar' }, [
				field( 'Workspace', this.nodes.workspace ),
				field( 'Folder', this.nodes.folder ),
				field( 'Search', this.nodes.search ),
			] )
		);
		this.container.appendChild( this.nodes.status );
		this.container.appendChild( this.nodes.results );
		// Insert-options bar lives at the bottom, outside the grid, so it never
		// disrupts the thumbnail layout. Sticky so it stays in view.
		this.container.appendChild( this.nodes.details );
	};

	Picker.prototype.setStatus = function ( text ) {
		if ( this.nodes.status ) {
			this.nodes.status.textContent = text || '';
		}
	};

	Picker.prototype.loadWorkspaces = function () {
		if ( ! Config.connected ) {
			return;
		}
		var self = this;
		this.setStatus( i18n.loading || 'Loading…' );
		api( '/workspaces' )
			.then( function ( body ) {
				var items = body.items || [];
				self.nodes.workspace.innerHTML = '';
				items.forEach( function ( ws ) {
					self.nodes.workspace.appendChild( el( 'option', { value: ws.id, text: ws.name } ) );
				} );
				if ( items.length ) {
					self.state.workspaceId = items[ 0 ].id;
					self.loadFolders();
				} else {
					self.setStatus( 'No workspaces available.' );
				}
			} )
			.catch( function ( e ) { self.setStatus( e.message ); } );
	};

	Picker.prototype.loadFolders = function () {
		var self = this;
		this.setStatus( i18n.loading || 'Loading…' );
		api( '/folders', { workspace_id: this.state.workspaceId } )
			.then( function ( body ) {
				var items = body.items || [];
				self.nodes.folder.innerHTML = '';
				if ( ! items.length ) {
					self.nodes.results.innerHTML = '';
					self.setStatus( 'No folders in this workspace.' );
					return;
				}
				items.forEach( function ( f ) {
					// Indent by depth; the depth-0 folder is the workspace root.
					var prefix = new Array( ( f.depth || 0 ) + 1 ).join( '— ' );
					self.nodes.folder.appendChild( el( 'option', { value: f.id, text: prefix + f.name } ) );
				} );
				// Default to the first folder (the depth-0 root, e.g. "All files").
				self.state.folderId = items[ 0 ].id;
				self.nodes.folder.value = self.state.folderId;
				self.loadFiles();
			} )
			.catch( function ( e ) { self.setStatus( e.message ); } );
	};

	Picker.prototype.loadFiles = function () {
		var self = this;
		this.clearSelection();
		this.setStatus( i18n.loading || 'Loading…' );
		api( '/files', { workspace_id: this.state.workspaceId, folder_id: this.state.folderId } )
			.then( function ( body ) { self.renderResults( body.items || [] ); } )
			.catch( function ( e ) { self.setStatus( e.message ); } );
	};

	Picker.prototype.runSearch = function () {
		var self = this;
		this.clearSelection();
		this.setStatus( i18n.loading || 'Loading…' );
		api( '/search', { workspace_id: this.state.workspaceId, term: this.state.term, folder_id: this.state.folderId } )
			.then( function ( body ) { self.renderResults( body.items || [] ); } )
			.catch( function ( e ) { self.setStatus( e.message ); } );
	};

	Picker.prototype.renderResults = function ( items ) {
		var self = this;
		this.state.items = items;
		this.nodes.results.innerHTML = '';
		if ( ! items.length ) {
			this.setStatus( i18n.noResults || 'No assets found.' );
			return;
		}
		this.setStatus( '' );
		items.forEach( function ( file ) {
			var thumb = file.thumb_url || file.preview_url || file.full_url || '';
			var tile = el( 'button', {
				type: 'button',
				class: 'razuna-picker__tile',
				title: file.name,
				onClick: function () { self.selectFile( file, tile ); },
			}, [
				file.is_image && thumb
					? el( 'img', { src: thumb, alt: file.name, loading: 'lazy' } )
					: el( 'span', { class: 'razuna-picker__ext', text: ( file.extension || 'file' ).toUpperCase() } ),
				el( 'span', { class: 'razuna-picker__name', text: file.name } ),
			] );
			self.nodes.results.appendChild( tile );
		} );
	};

	Picker.prototype.clearSelection = function () {
		this.state.selected = null;
		this.state.formats = [];
		this.selectedTile = null;
		if ( this.nodes.details ) {
			this.nodes.details.innerHTML = '';
		}
		if ( this.nodes.results ) {
			Array.prototype.forEach.call( this.nodes.results.children, function ( c ) {
				if ( c.classList ) {
					c.classList.remove( 'is-selected' );
				}
			} );
		}
	};

	Picker.prototype.selectFile = function ( file, tileEl ) {
		this.clearSelection();
		this.state.selected = file;
		this.selectedTile = tileEl;
		if ( tileEl ) {
			tileEl.classList.add( 'is-selected' );
		}
		this.renderBar();

		// Fetch saved formats for images (best-effort; failure is non-fatal).
		if ( file.is_image && file.id ) {
			var self = this;
			api( '/formats', { file_id: file.id } )
				.then( function ( body ) {
					if ( self.state.selected === file ) {
						self.state.formats = body.items || [];
						self.renderBar();
					}
				} )
				.catch( function () {} );
		}
	};

	/**
	 * Render the insert-options bar inline, right after the selected tile.
	 */
	Picker.prototype.renderBar = function () {
		var self = this;
		var file = this.state.selected;
		// Rebuild from scratch (re-render on formats load / re-select).
		if ( this.nodes.details ) {
			this.nodes.details.innerHTML = '';
		}
		if ( ! file || ! this.selectedTile ) {
			return;
		}

		// Build the "Insert as" options.
		var variantSelect = el( 'select', { class: 'razuna-picker__variant' } );
		if ( file.is_image ) {
			variantSelect.appendChild( el( 'option', { value: 'full', text: 'Full size' } ) );
			if ( file.preview_url ) {
				variantSelect.appendChild( el( 'option', { value: 'large', text: 'Large preview' } ) );
			}
			if ( file.thumb_url ) {
				variantSelect.appendChild( el( 'option', { value: 'thumb', text: 'Thumbnail' } ) );
			}
			this.state.formats.forEach( function ( fmt ) {
				var label = fmt.name || ( fmt.width + '×' + fmt.height + ' ' + ( fmt.format || '' ).toUpperCase() );
				variantSelect.appendChild( el( 'option', { value: 'format:' + fmt.id, text: label } ) );
			} );
		} else {
			variantSelect.appendChild( el( 'option', { value: 'full', text: 'Original file' } ) );
			variantSelect.disabled = true;
		}

		var downloadToggle = el( 'input', { type: 'checkbox', class: 'razuna-picker__download' } );
		// Non-images can only be inserted as a (download) link.
		if ( ! file.is_image ) {
			downloadToggle.checked = true;
			downloadToggle.disabled = true;
		}

		var insertBtn = el( 'button', {
			type: 'button',
			class: 'button button-primary razuna-picker__insert',
			text: i18n.insert || 'Insert',
			onClick: function () { self.doInsert( variantSelect.value, downloadToggle.checked ); },
		} );

		var closeBtn = el( 'button', {
			type: 'button',
			class: 'razuna-picker__detailclose',
			'aria-label': 'Close',
			title: 'Close',
			text: '×',
			onClick: function () { self.clearSelection(); },
		} );

		var bar = el( 'div', { class: 'razuna-picker__detailbar' }, [
			el( 'label', { class: 'razuna-picker__field razuna-picker__field--inline' }, [
				el( 'span', { class: 'razuna-picker__label', text: 'Insert as' } ),
				variantSelect,
			] ),
			el( 'label', { class: 'razuna-picker__toggle' }, [ downloadToggle, ' Download link' ] ),
			insertBtn,
			closeBtn,
		] );

		this.nodes.details.appendChild( bar );
	};

	/**
	 * Resolve the chosen variant + download toggle into a payload and emit it.
	 */
	Picker.prototype.doInsert = function ( variant, asDownload ) {
		var file = this.state.selected;
		if ( ! file ) {
			return;
		}

		var url = file.full_url;
		var label = 'Full size';

		if ( 0 === variant.indexOf( 'format:' ) ) {
			var fmtId = variant.slice( 7 );
			var fmt = this.state.formats.filter( function ( f ) { return String( f.id ) === fmtId; } )[ 0 ];
			if ( fmt ) {
				// Saved formats carry durable signed view + download direct links.
				url = asDownload ? ( fmt.download_url || fmt.view_url ) : ( fmt.view_url || fmt.download_url );
				label = fmt.name || ( fmt.width + '×' + fmt.height + ' ' + ( fmt.format || '' ).toUpperCase() );
			}
		} else if ( 'large' === variant ) {
			url = file.preview_url || file.full_url;
			label = 'Large preview';
		} else if ( 'thumb' === variant ) {
			url = file.thumb_url || file.preview_url || file.full_url;
			label = 'Thumbnail';
		}

		// A download link uses the dedicated download URL when available and is
		// rendered as a link (is_image false) by the host.
		var isImage = !! file.is_image && ! asDownload;
		if ( asDownload && 0 !== variant.indexOf( 'format:' ) ) {
			url = file.download_url || url;
		}

		this.onSelect( {
			id: file.id,
			name: file.name,
			alt: file.name,
			url: url,
			full_url: file.full_url,
			download_url: file.download_url,
			is_image: isImage,
			width: file.width || 0,
			height: file.height || 0,
			label: label,
		} );
	};

	window.RazunaPicker = {
		mount: function ( container, options ) {
			return new Picker( container, options || {} );
		},
		api: api,
	};
} )( window, document );
