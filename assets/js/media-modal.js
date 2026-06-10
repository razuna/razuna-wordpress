/**
 * Classic-editor integration: an "Add from Razuna" button that opens a modal
 * with the shared picker and inserts the chosen asset as an <img> using the
 * durable direct-link URL. (The block editor is covered by the Razuna Asset
 * block; this targets the classic editor / media buttons area.)
 */
( function ( window, document ) {
	'use strict';

	var Config = window.RazunaConfig || {};
	var i18n = Config.i18n || {};
	var picker = null;

	function buildModal() {
		var overlay = document.createElement( 'div' );
		overlay.className = 'razuna-overlay';
		overlay.style.display = 'none';

		var dialog = document.createElement( 'div' );
		dialog.className = 'razuna-overlay__dialog';

		var header = document.createElement( 'div' );
		header.className = 'razuna-overlay__header';
		header.innerHTML = '<h2>' + ( i18n.tabLabel || 'Razuna' ) + '</h2>';

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

		overlay._body = body;
		return overlay;
	}

	var modalEl = null;

	function open() {
		if ( ! modalEl ) {
			modalEl = buildModal();
		}
		modalEl.style.display = 'flex';

		if ( window.RazunaPicker ) {
			picker = window.RazunaPicker.mount( modalEl._body, {
				onSelect: function ( payload ) {
					insertIntoEditor( payload );
					close();
				},
			} );
		} else {
			modalEl._body.textContent = 'Picker unavailable.';
		}
	}

	function close() {
		if ( modalEl ) {
			modalEl.style.display = 'none';
			modalEl._body.innerHTML = '';
		}
	}

	function insertIntoEditor( payload ) {
		var url = payload.url;
		if ( ! url ) {
			return;
		}
		var alt = ( payload.alt || payload.name || '' ).replace( /"/g, '&quot;' );
		var width = parseInt( payload.width, 10 ) || 0;
		var height = parseInt( payload.height, 10 ) || 0;
		var dims = '';
		var html;
		if ( payload.is_image ) {
			if ( width > 0 ) {
				dims += ' width="' + width + '"';
			}
			if ( height > 0 ) {
				dims += ' height="' + height + '"';
			}
			html = '<img src="' + url + '" alt="' + alt + '" class="razuna-image"' + dims + ' />';
		} else {
			html = '<a href="' + url + '">' + ( payload.name || url ) + '</a>';
		}

		if ( typeof window.send_to_editor === 'function' ) {
			window.send_to_editor( html );
		} else if ( window.wp && window.wp.media && window.wp.media.editor ) {
			window.wp.media.editor.insert( html );
		}
	}

	function addButton() {
		var containers = document.querySelectorAll( '.wp-media-buttons' );
		containers.forEach( function ( container ) {
			if ( container.querySelector( '.razuna-add-button' ) ) {
				return;
			}
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'button razuna-add-button';
			btn.innerHTML = '<span class="dashicons dashicons-images-alt2" style="vertical-align:text-top"></span> ' + ( i18n.tabLabel || 'Razuna' );
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				open();
			} );
			container.appendChild( btn );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', addButton );
	// Expose for manual triggering / future block-editor toolbar use.
	window.RazunaMediaModal = { open: open, close: close };
} )( window, document );
