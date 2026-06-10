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

	function postApi( path, body ) {
		return window
			.fetch( Config.restBase + path, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': Config.nonce,
				},
				credentials: 'same-origin',
				body: JSON.stringify( body || {} ),
			} )
			.then( function ( res ) {
				return res.json().then( function ( responseBody ) {
					if ( ! res.ok ) {
						var err = new Error( responseBody && responseBody.message ? responseBody.message : 'Request failed' );
						err.status = res.status;
						throw err;
					}
					return responseBody;
				} );
			} );
	}

	function fileType( file ) {
		var contentType = String( file.content_type || '' ).toLowerCase();

		if ( file.is_image || 0 === contentType.indexOf( 'image/' ) ) {
			return 'image';
		}
		if ( 0 === contentType.indexOf( 'video/' ) ) {
			return 'video';
		}
		if ( 0 === contentType.indexOf( 'audio/' ) ) {
			return 'audio';
		}
		return 'file';
	}

	function typeLabel( type ) {
		if ( 'image' === type ) {
			return 'Images';
		}
		if ( 'video' === type ) {
			return 'Videos';
		}
		if ( 'audio' === type ) {
			return 'Audio';
		}
		return 'Files';
	}

	function formatFileSize( bytes ) {
		var size = parseInt( bytes, 10 ) || 0;
		var mb;

		if ( size <= 0 ) {
			return 'size unknown';
		}
		mb = size / ( 1024 * 1024 );
		if ( mb < 10 ) {
			return mb.toFixed( 1 ) + ' MB';
		}
		return Math.round( mb ) + ' MB';
	}

	function formatDimensions( width, height ) {
		var w = parseInt( width, 10 ) || 0;
		var h = parseInt( height, 10 ) || 0;

		if ( w > 0 && h > 0 ) {
			return w + '×' + h + 'px';
		}
		return '';
	}

	function formatOriginalLabel( file ) {
		var details = [];
		var dimensions = formatDimensions( file.width, file.height );

		if ( dimensions ) {
			details.push( dimensions );
		}
		details.push( formatFileSize( file.size ) );

		return 'Original file (' + details.join( ', ' ) + ')';
	}

	function formatSavedFormatLabel( fmt ) {
		var name = fmt.name || '';
		var dims = formatDimensionsForFormat( fmt, {} );
		var dimensions = formatDimensions( dims.width, dims.height );
		var format = fmt.format ? String( fmt.format ).toUpperCase() : '';
		var details = [ dimensions, format ].filter( function ( value ) { return !! value; } ).join( ' ' );

		if ( name && details && name.toLowerCase() !== details.toLowerCase() ) {
			return name + ' (' + details + ')';
		}
		return name || details || 'Additional format';
	}

	function dimensionsFromText( text ) {
		var match;

		if ( ! text ) {
			return { width: 0, height: 0 };
		}

		match = String( text ).match( /(\d{1,5})\s*[x×]\s*(\d{1,5})/i );
		if ( ! match ) {
			return { width: 0, height: 0 };
		}

		return dimensionPair( match[ 1 ], match[ 2 ] );
	}

	function dimensionPair( width, height ) {
		return {
			width: parseInt( width, 10 ) || 0,
			height: parseInt( height, 10 ) || 0,
		};
	}

	function scaledDimensions( width, height, maxSize ) {
		var dims = dimensionPair( width, height );
		var longest = Math.max( dims.width, dims.height );
		var scale;

		if ( dims.width <= 0 || dims.height <= 0 || maxSize <= 0 || longest <= maxSize ) {
			return dims;
		}

		scale = maxSize / longest;
		return {
			width: Math.max( 1, Math.round( dims.width * scale ) ),
			height: Math.max( 1, Math.round( dims.height * scale ) ),
		};
	}

	function completeDimensions( width, height, originalWidth, originalHeight ) {
		var dims = dimensionPair( width, height );
		var original = dimensionPair( originalWidth, originalHeight );

		if ( dims.width > 0 && dims.height > 0 ) {
			return dims;
		}

		if ( original.width <= 0 || original.height <= 0 ) {
			return dims;
		}

		if ( dims.width > 0 ) {
			dims.height = Math.max( 1, Math.round( dims.width * original.height / original.width ) );
		} else if ( dims.height > 0 ) {
			dims.width = Math.max( 1, Math.round( dims.height * original.width / original.height ) );
		}
		return dims;
	}

	function formatDimensionsForFormat( format, file ) {
		var dims = completeDimensions( format.width, format.height, file.width, file.height );
		var parsed;

		if ( dims.width > 0 && dims.height > 0 ) {
			return dims;
		}

		parsed = dimensionsFromText( format.label || format.name || '' );
		return completeDimensions( parsed.width, parsed.height, file.width, file.height );
	}

	function selectedDimensions( file, variant, format ) {
		if ( format ) {
			return formatDimensionsForFormat( format, file );
		}

		if ( 'large' === variant ) {
			return scaledDimensions( file.width, file.height, 1200 );
		}

		if ( 'thumb' === variant ) {
			return scaledDimensions( file.width, file.height, 400 );
		}

		return dimensionPair( file.width, file.height );
	}

	function Picker( container, options ) {
		this.container = container;
		this.options = options || {};
		this.onSelect = this.options.onSelect || function () {};
		this.multiple = !! this.options.multiple;
		this.mode = this.options.mode || 'direct';
		this.context = this.options.context || 'asset';
		this.allowedTypes = Array.isArray( this.options.allowedTypes ) ? this.options.allowedTypes : [];
		this.pageSize = 25;
		this.requestSeq = 0;
		this.observer = null;
		this.selectedMap = {};
		this.state = {
			workspaceId: '',
			folderId: '',
			term: '',
			mode: 'browse',
			typeFilter: '',
			items: [],
			selected: null,
			selectedItems: [],
			formats: [],
			page: 0,
			perPage: this.pageSize,
			total: 0,
			hasMore: false,
			loading: false,
		};
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
			self.state.term = '';
			self.nodes.search.value = '';
			self.loadFolders();
		} } );

		this.nodes.folder = el( 'select', { class: 'razuna-picker__folder', onChange: function ( e ) {
			self.state.folderId = e.target.value;
			self.loadCurrent();
		} } );

		this.nodes.typeFilter = el( 'select', { class: 'razuna-picker__type', onChange: function ( e ) {
			self.state.typeFilter = e.target.value;
			self.renderResults( self.filteredItems( self.state.items ), false, true );
			self.updateResultStatus();
			self.renderPager();
		} } );
		this.nodes.typeFilter.appendChild( el( 'option', { value: '', text: 'All files' } ) );
		( this.allowedTypes.length ? this.allowedTypes : [ 'image', 'video', 'audio', 'file' ] ).forEach( function ( type ) {
			self.nodes.typeFilter.appendChild( el( 'option', { value: type, text: typeLabel( type ) } ) );
		} );

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
				field( 'Type', this.nodes.typeFilter ),
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
		this.state.mode = 'browse';
		this.loadPage( true );
	};

	Picker.prototype.runSearch = function () {
		this.state.mode = 'search';
		this.loadPage( true );
	};

	Picker.prototype.loadCurrent = function () {
		if ( this.state.term ) {
			this.runSearch();
		} else {
			this.loadFiles();
		}
	};

	Picker.prototype.loadNextPage = function () {
		if ( this.state.loading || ! this.state.hasMore ) {
			return;
		}
		this.loadPage( false );
	};

	Picker.prototype.loadPage = function ( reset ) {
		var self = this;
		var nextPage = reset ? 1 : this.state.page + 1;
		var params = {
			workspace_id: this.state.workspaceId,
			folder_id: this.state.folderId,
			page: nextPage,
			per_page: this.pageSize,
		};
		var path = '/files';
		var requestId = ++this.requestSeq;

		if ( 'search' === this.state.mode ) {
			path = '/search';
			params.term = this.state.term;
		}

		if ( reset ) {
			this.clearSelection();
			this.state.items = [];
			this.state.page = 0;
			this.state.total = 0;
			this.state.hasMore = false;
			this.nodes.results.innerHTML = '';
			this.setStatus( i18n.loading || 'Loading…' );
		}

		this.state.loading = true;
		this.renderPager();
		api( path, params )
			.then( function ( body ) {
				var items;

				if ( requestId !== self.requestSeq ) {
					return;
				}

				items = self.allowedItems( body.items || [] );
				if ( reset ) {
					self.state.items = items;
				} else {
					self.state.items = self.state.items.concat( items );
				}
				self.state.page = parseInt( body.page, 10 ) || nextPage;
				self.state.perPage = parseInt( body.per_page, 10 ) || self.pageSize;
				self.state.total = parseInt( body.total, 10 ) || self.state.items.length;
				self.state.hasMore = ( undefined !== body.has_more )
					? !! body.has_more
					: ( self.state.total > self.state.page * self.state.perPage || items.length === self.state.perPage );
				self.state.loading = false;
				self.renderResults( self.filteredItems( self.state.items ), false, true );
				self.updateResultStatus();
				self.renderPager();
			} )
			.catch( function ( e ) {
				if ( requestId !== self.requestSeq ) {
					return;
				}
				self.state.loading = false;
				self.renderPager();
				self.setStatus( e.message );
			} );
	};

	Picker.prototype.updateResultStatus = function () {
		var visibleCount = this.filteredItems( this.state.items ).length;

		if ( ! visibleCount ) {
			this.setStatus( i18n.noResults || 'No assets found.' );
			return;
		}
		if ( this.state.total > this.state.items.length ) {
			this.setStatus( 'Showing ' + this.state.items.length + ' of ' + this.state.total + ' assets.' );
			return;
		}
		this.setStatus( '' );
	};

	Picker.prototype.allowedItems = function ( items ) {
		var allowed = this.allowedTypes;

		if ( ! allowed.length ) {
			return items;
		}
		return items.filter( function ( file ) {
			return allowed.indexOf( fileType( file ) ) !== -1;
		} );
	};

	Picker.prototype.filteredItems = function ( items ) {
		var selectedType = this.state.typeFilter;

		if ( ! selectedType ) {
			return items;
		}
		return items.filter( function ( file ) {
			return fileType( file ) === selectedType;
		} );
	};

	Picker.prototype.renderResults = function ( items, append, preserveState ) {
		var self = this;
		if ( append ) {
			this.state.items = this.state.items.concat( items );
		} else if ( ! preserveState ) {
			this.state.items = items;
			this.nodes.results.innerHTML = '';
		} else {
			this.nodes.results.innerHTML = '';
		}
		if ( ! this.state.items.length ) {
			return;
		}
		items.forEach( function ( file ) {
			var thumb = file.thumb_url || file.preview_url || file.full_url || '';
			var selected = !! self.selectedMap[ file.id ];
			var tile = el( 'button', {
				type: 'button',
				class: 'razuna-picker__tile' + ( selected ? ' is-selected' : '' ),
				title: file.name,
				'aria-pressed': selected ? 'true' : 'false',
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

	Picker.prototype.renderPager = function () {
		var self = this;
		var button;

		if ( ! this.nodes.results ) {
			return;
		}
		if ( this.nodes.pager && this.nodes.pager.parentNode ) {
			this.nodes.pager.parentNode.removeChild( this.nodes.pager );
		}
		this.nodes.pager = el( 'div', { class: 'razuna-picker__pager' } );

		if ( this.state.loading && this.state.items.length ) {
			this.nodes.pager.appendChild( el( 'span', { class: 'razuna-picker__loading', text: i18n.loading || 'Loading…' } ) );
		} else if ( this.state.hasMore ) {
			button = el( 'button', {
				type: 'button',
				class: 'button razuna-picker__load-more',
				text: 'Load more',
				onClick: function () { self.loadNextPage(); },
			} );
			this.nodes.pager.appendChild( button );
		} else if ( this.state.items.length ) {
			this.nodes.pager.appendChild( el( 'span', { class: 'razuna-picker__end', text: 'End of results' } ) );
		}

		if ( this.nodes.pager.childNodes.length ) {
			this.nodes.results.appendChild( this.nodes.pager );
			this.observePager();
		}
	};

	Picker.prototype.observePager = function () {
		var self = this;

		if ( this.observer ) {
			this.observer.disconnect();
			this.observer = null;
		}
		if ( ! window.IntersectionObserver || ! this.nodes.pager || ! this.nodes.results || ! this.state.hasMore ) {
			return;
		}

		this.observer = new window.IntersectionObserver(
			function ( entries ) {
				if ( entries[ 0 ] && entries[ 0 ].isIntersecting ) {
					self.loadNextPage();
				}
			},
			{ root: this.nodes.results, rootMargin: '120px' }
		);
		this.observer.observe( this.nodes.pager );
	};

	Picker.prototype.clearSelection = function () {
		this.state.selected = null;
		this.state.selectedItems = [];
		this.state.formats = [];
		this.selectedMap = {};
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
		var selectedId = file.id;
		var self = this;
		var selectedIndex;

		if ( this.multiple ) {
			selectedIndex = this.state.selectedItems.map( function ( item ) { return item.id; } ).indexOf( selectedId );
			if ( selectedIndex === -1 ) {
				this.state.selectedItems.push( file );
				this.selectedMap[ selectedId ] = file;
				if ( tileEl ) {
					tileEl.classList.add( 'is-selected' );
					tileEl.setAttribute( 'aria-pressed', 'true' );
				}
			} else {
				this.state.selectedItems.splice( selectedIndex, 1 );
				delete this.selectedMap[ selectedId ];
				if ( tileEl ) {
					tileEl.classList.remove( 'is-selected' );
					tileEl.setAttribute( 'aria-pressed', 'false' );
				}
			}
			this.state.selected = this.state.selectedItems.length ? this.state.selectedItems[ this.state.selectedItems.length - 1 ] : null;
			this.selectedTile = tileEl;
			this.state.formats = [];
			this.renderBar();
			return;
		}

		this.clearSelection();
		this.state.selected = file;
		this.state.selectedItems = [ file ];
		this.selectedMap[ selectedId ] = file;
		this.selectedTile = tileEl;
		if ( tileEl ) {
			tileEl.classList.add( 'is-selected' );
			tileEl.setAttribute( 'aria-pressed', 'true' );
		}
		this.renderBar();

		// Fetch saved formats for images (best-effort; failure is non-fatal).
		if ( file.is_image && file.id ) {
			api( '/formats', { file_id: file.id } )
				.then( function ( body ) {
					if ( self.state.selected && self.state.selected.id === selectedId ) {
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
		var selectedCount = this.multiple ? this.state.selectedItems.length : ( file ? 1 : 0 );
		// Rebuild from scratch (re-render on formats load / re-select).
		if ( this.nodes.details ) {
			this.nodes.details.innerHTML = '';
		}
		if ( ! selectedCount ) {
			return;
		}

		// Build the "Insert as" options.
		var variantSelect = el( 'select', { class: 'razuna-picker__variant' } );
		if ( file.is_image ) {
			if ( file.preview_url ) {
				variantSelect.appendChild( el( 'option', { value: 'large', text: 'Large thumbnail (1200px)' } ) );
			}
			if ( file.thumb_url ) {
				variantSelect.appendChild( el( 'option', { value: 'thumb', text: 'Small thumbnail (400px)' } ) );
			}
			if ( ! this.multiple ) {
				this.state.formats.forEach( function ( fmt ) {
					variantSelect.appendChild( el( 'option', { value: 'format:' + fmt.id, text: formatSavedFormatLabel( fmt ) } ) );
				} );
			}
			variantSelect.appendChild( el( 'option', { value: 'full', text: formatOriginalLabel( file ) } ) );
		} else {
			variantSelect.appendChild( el( 'option', { value: 'full', text: formatOriginalLabel( file ) } ) );
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
			text: this.actionLabel( selectedCount ),
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
			this.multiple
				? el( 'span', { class: 'razuna-picker__count', text: selectedCount + ' ' + ( i18n.selected || 'selected' ) } )
				: null,
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

	Picker.prototype.actionLabel = function ( selectedCount ) {
		if ( 'import' === this.mode ) {
			return this.multiple
				? ( i18n.importSelected || 'Import selected' )
				: ( i18n.import || 'Import' );
		}
		if ( 'gallery' === this.context || selectedCount > 1 ) {
			return i18n.insertGallery || 'Insert gallery';
		}
		return i18n.insert || 'Insert';
	};

	/**
	 * Resolve the chosen variant + download toggle into a payload and emit it.
	 */
	Picker.prototype.doInsert = function ( variant, asDownload ) {
		var self = this;
		var files = this.multiple ? this.state.selectedItems.slice() : ( this.state.selected ? [ this.state.selected ] : [] );
		var payloads;

		if ( ! files.length ) {
			return;
		}

		payloads = files.map( function ( file ) {
			return self.payloadForFile( file, variant, asDownload );
		} );

		if ( 'import' === this.mode ) {
			this.importPayloads( payloads );
			return;
		}

		this.onSelect( this.multiple ? payloads : payloads[ 0 ] );
	};

	Picker.prototype.payloadForFile = function ( file, variant, asDownload ) {
		var filename = file.filename || file.name;
		var url = file.full_url;
		var label = 'Original file';
		var fmt = null;
		var dims;
		var formatId = '';

		if ( 0 === variant.indexOf( 'format:' ) ) {
			var fmtId = variant.slice( 7 );
			formatId = fmtId;
			fmt = this.state.formats.filter( function ( f ) { return String( f.id ) === fmtId; } )[ 0 ];
			if ( fmt ) {
				// Saved formats carry durable signed view + download direct links.
				url = asDownload ? ( fmt.download_url || fmt.view_url ) : ( fmt.view_url || fmt.download_url );
				label = formatSavedFormatLabel( fmt );
			}
		} else if ( 'large' === variant ) {
			url = file.preview_url || file.full_url;
			label = 'Large thumbnail (1200px)';
		} else if ( 'thumb' === variant ) {
			url = file.thumb_url || file.preview_url || file.full_url;
			label = 'Small thumbnail (400px)';
		}

		// A download link uses the dedicated download URL when available and is
		// rendered as a link (is_image false) by the host.
		var isImage = !! file.is_image && ! asDownload;
		if ( asDownload && 0 !== variant.indexOf( 'format:' ) ) {
			url = file.download_url || url;
		}
		dims = isImage ? selectedDimensions( file, variant, fmt ) : { width: 0, height: 0 };

		return {
			id: file.id,
			file_id: file.id,
			name: filename,
			alt: filename,
			url: url,
			full_url: file.full_url,
			download_url: file.download_url,
			is_image: isImage,
			content_type: file.content_type || '',
			type: fileType( file ),
			width: dims.width,
			height: dims.height,
			label: label,
			variant: 0 === variant.indexOf( 'format:' ) ? 'format' : variant,
			format_id: formatId,
			as_download: !! asDownload,
		};
	};

	Picker.prototype.importPayloads = function ( payloads ) {
		var self = this;
		var body;

		this.setStatus( i18n.loading || 'Loading…' );
		body = {
			reuse: true,
			items: payloads.map( function ( payload ) {
				return {
					file_id: payload.file_id || payload.id,
					variant: payload.variant || 'full',
					format_id: payload.format_id || '',
					as_download: !! payload.as_download,
				};
			} ),
		};

		postApi( payloads.length > 1 ? '/imports' : '/import', payloads.length > 1 ? body : body.items[ 0 ] )
			.then( function ( response ) {
				var imported = payloads.length > 1 ? ( response.items || [] ) : [ response ];

				if ( response.errors && response.errors.length ) {
					self.setStatus( response.errors.length + ' import failed.' );
				}
				self.onSelect( self.multiple ? imported : imported[ 0 ] );
			} )
			.catch( function ( error ) {
				self.setStatus( error.message );
			} );
	};

	window.RazunaPicker = {
		mount: function ( container, options ) {
			return new Picker( container, options || {} );
		},
		api: api,
		postApi: postApi,
	};
} )( window, document );
