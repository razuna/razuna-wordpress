/**
 * WordPress admin media integrations for Razuna.
 *
 * Handles the classic editor media button, Media Library imports, featured
 * image imports and a fallback button inside wp.media frames.
 */
( function ( window, document ) {
	'use strict';

	var Config = window.RazunaConfig || {};
	var i18n = Config.i18n || {};
	var modalEl = null;
	var activePicker = null;

	function closest( node, selector ) {
		while ( node && node !== document ) {
			if ( node.matches && node.matches( selector ) ) {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	function buildModal() {
		var overlay = document.createElement( 'div' );
		overlay.className = 'razuna-overlay';
		overlay.style.display = 'none';

		var dialog = document.createElement( 'div' );
		dialog.className = 'razuna-overlay__dialog';

		var header = document.createElement( 'div' );
		header.className = 'razuna-overlay__header';
		header.innerHTML = '<h2></h2>';

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'razuna-overlay__close';
		closeBtn.setAttribute( 'aria-label', 'Close' );
		closeBtn.innerHTML = '&times;';
		closeBtn.addEventListener( 'click', close );
		header.appendChild( closeBtn );

		var body = document.createElement( 'div' );
		body.className = 'razuna-overlay__body';

		dialog.appendChild( header );
		dialog.appendChild( body );
		overlay.appendChild( dialog );
		overlay.addEventListener( 'click', function ( e ) {
			if ( e.target === overlay ) {
				close();
			}
		} );
		document.body.appendChild( overlay );

		overlay._title = header.querySelector( 'h2' );
		overlay._body = body;
		return overlay;
	}

	function open( options ) {
		options = options || {};
		if ( ! modalEl ) {
			modalEl = buildModal();
		}
		modalEl.style.display = 'flex';
		if ( modalEl._title ) {
			modalEl._title.textContent = options.title || (
				'import' === options.mode
					? ( i18n.importFromRazuna || 'Import from Razuna' )
					: ( i18n.insertFromRazuna || 'Insert from Razuna' )
			);
		}
		modalEl._body.innerHTML = '';

		if ( window.RazunaPicker ) {
			activePicker = window.RazunaPicker.mount( modalEl._body, {
				multiple: !! options.multiple,
				allowedTypes: options.allowedTypes || [],
				mode: options.mode || 'direct',
				context: options.context || 'asset',
				onSelect: function ( payload ) {
					( options.onSelect || insertIntoEditor )( payload );
					close();
				},
			} );
		} else {
			modalEl._body.textContent = 'Picker unavailable.';
		}
	}

	function close() {
		activePicker = null;
		if ( modalEl ) {
			modalEl.style.display = 'none';
			modalEl._body.innerHTML = '';
		}
	}

	function htmlForPayload( payload ) {
		var url = payload.url;
		var alt;
		var width;
		var height;
		var dims = '';

		if ( ! url ) {
			return '';
		}

		alt = String( payload.alt || payload.name || '' ).replace( /"/g, '&quot;' );
		width = parseInt( payload.width, 10 ) || 0;
		height = parseInt( payload.height, 10 ) || 0;
		if ( payload.is_image ) {
			if ( width > 0 ) {
				dims += ' width="' + width + '"';
			}
			if ( height > 0 ) {
				dims += ' height="' + height + '"';
			}
			return '<img src="' + url + '" alt="' + alt + '" class="razuna-image"' + dims + ' />';
		}
		return '<a href="' + url + '">' + ( payload.name || url ) + '</a>';
	}

	function galleryHtml( payloads ) {
		var items = payloads.map( function ( payload ) {
			var inner = htmlForPayload( payload );

			return inner ? '<figure class="wp-block-image size-large">' + inner + '</figure>' : '';
		} ).filter( Boolean ).join( '' );

		if ( ! items ) {
			return '';
		}
		return '<figure class="wp-block-gallery has-nested-images columns-3 is-cropped razuna-gallery">' + items + '</figure>';
	}

	function insertIntoEditor( payload ) {
		var html;

		if ( Array.isArray( payload ) ) {
			html = galleryHtml( payload );
		} else {
			html = htmlForPayload( payload );
		}
		if ( ! html ) {
			return;
		}

		if ( typeof window.send_to_editor === 'function' ) {
			window.send_to_editor( html );
		} else if ( window.wp && window.wp.media && window.wp.media.editor ) {
			window.wp.media.editor.insert( html );
		}
	}

	function attachmentFromPayload( payload ) {
		if ( ! window.wp || ! window.wp.media || ! payload || ! payload.id ) {
			return null;
		}
		return window.wp.media.attachment( payload.id );
	}

	function addImportedToCurrentFrame( payloads ) {
		var frame = window.wp && window.wp.media ? window.wp.media.frame : null;
		var state = frame && frame.state ? frame.state() : null;
		var selection = state && state.get ? ( state.get( 'selection' ) || state.get( 'library' ) ) : null;

		payloads = Array.isArray( payloads ) ? payloads : [ payloads ];
		if ( ! selection ) {
			return;
		}

		payloads.forEach( function ( payload ) {
			var attachment = attachmentFromPayload( payload );

			if ( attachment ) {
				attachment.fetch();
				selection.add( attachment );
			}
		} );
	}

	function handleMediaFrameImport() {
		var frame = window.wp && window.wp.media ? window.wp.media.frame : null;
		var state = frame && frame.state ? frame.state() : null;
		var stateId = state && state.id ? state.id : '';
		var isFeatured = 'featured-image' === stateId;
		var isGallery = stateId.indexOf( 'gallery' ) !== -1;

		open( {
			multiple: ! isFeatured,
			allowedTypes: isFeatured || isGallery ? [ 'image' ] : [ 'image', 'video', 'audio', 'file' ],
			mode: 'import',
			context: isFeatured ? 'featured' : ( isGallery ? 'gallery' : 'media-library' ),
			onSelect: function ( payload ) {
				if ( isFeatured && window.wp.media.featuredImage && payload && payload.id ) {
					window.wp.media.featuredImage.set( payload.id );
					if ( frame && frame.close ) {
						frame.close();
					}
					return;
				}
				addImportedToCurrentFrame( payload );
			},
		} );
	}

	function bindClicks() {
		document.addEventListener( 'click', function ( event ) {
			var button = closest( event.target, '.razuna-media-button' );
			var libraryButton;
			var featuredButton;
			var frameButton;
			var routerButton;

			if ( button ) {
				event.preventDefault();
				window.wpActiveEditor = button.getAttribute( 'data-editor' ) || window.wpActiveEditor || 'content';
				open( {
					multiple: false,
					allowedTypes: [ 'image', 'video', 'audio', 'file' ],
					mode: 'direct',
					context: 'asset',
				} );
				return;
			}

			libraryButton = closest( event.target, '.razuna-media-library-button' );
			if ( libraryButton ) {
				event.preventDefault();
				open( {
					multiple: true,
					allowedTypes: [ 'image', 'video', 'audio', 'file' ],
					mode: 'import',
					context: 'media-library',
					onSelect: function () {
						window.location.reload();
					},
				} );
				return;
			}

			featuredButton = closest( event.target, '.razuna-featured-button' );
			if ( featuredButton ) {
				event.preventDefault();
				open( {
					multiple: false,
					allowedTypes: [ 'image' ],
					mode: 'import',
					context: 'featured',
					onSelect: function ( payload ) {
						if ( window.wp && window.wp.media && window.wp.media.featuredImage && payload.id ) {
							window.wp.media.featuredImage.set( payload.id );
						}
					},
				} );
				return;
			}

			frameButton = closest( event.target, '.razuna-media-frame-button' );
			if ( frameButton ) {
				event.preventDefault();
				handleMediaFrameImport();
				return;
			}

			routerButton = closest( event.target, '.razuna-media-router-button' );
			if ( routerButton ) {
				event.preventDefault();
				handleMediaFrameImport();
			}
		} );
	}

	function addMediaLibraryButton() {
		var heading = document.querySelector( '.wrap h1.wp-heading-inline' );
		var existing = document.querySelector( '.razuna-media-library-button' );
		var button;

		if ( existing || ! document.body.classList.contains( 'upload-php' ) || ! heading ) {
			return;
		}

		button = document.createElement( 'a' );
		button.href = '#';
		button.className = 'page-title-action razuna-media-library-button';
		button.textContent = i18n.importFromRazuna || 'Import from Razuna';
		heading.parentNode.insertBefore( button, heading.nextSibling );
	}

	function addFeaturedImageButton() {
		var box = document.querySelector( '#postimagediv .inside' );
		var existing = document.querySelector( '.razuna-featured-button' );
		var paragraph;
		var button;

		if ( ! box || existing ) {
			return;
		}

		paragraph = document.createElement( 'p' );
		paragraph.className = 'hide-if-no-js';
		button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button razuna-featured-button';
		button.textContent = i18n.import || 'Set from Razuna';
		paragraph.appendChild( button );
		box.appendChild( paragraph );
	}

	function addFrameButton() {
		var toolbar = document.querySelector( '.media-modal .media-frame-toolbar .media-toolbar-primary' );
		var existing = document.querySelector( '.media-modal .razuna-media-frame-button' );
		var button;

		if ( ! toolbar || existing ) {
			return;
		}

		button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button media-button razuna-media-frame-button';
		button.textContent = i18n.importFromRazuna || 'Import from Razuna';
		toolbar.insertBefore( button, toolbar.firstChild );
	}

	function addRouterButton() {
		var routers = document.querySelectorAll( '.media-modal .media-router' );

		Array.prototype.forEach.call( routers, function ( router ) {
			var button;

			if ( router.querySelector( '.razuna-media-router-button' ) ) {
				return;
			}

			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'media-menu-item razuna-media-router-button';
			button.textContent = i18n.importFromRazuna || 'Import from Razuna';
			router.appendChild( button );
		} );
	}

	function observeAdmin() {
		var observer = new window.MutationObserver( function () {
			addMediaLibraryButton();
			addFeaturedImageButton();
			addFrameButton();
			addRouterButton();
		} );

		observer.observe( document.body, { childList: true, subtree: true } );
		addMediaLibraryButton();
		addFeaturedImageButton();
		addFrameButton();
		addRouterButton();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindClicks();
		if ( window.MutationObserver ) {
			observeAdmin();
		} else {
			addMediaLibraryButton();
			addFeaturedImageButton();
			addRouterButton();
		}
	} );

	window.RazunaMediaModal = { open: open, close: close };
} )( window, document );
