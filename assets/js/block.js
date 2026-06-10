/**
 * Razuna block-editor integrations.
 *
 * Registers direct-link Razuna blocks and adds opt-in Razuna import controls to
 * core media blocks. No JSX/build step; uses wp.element.createElement directly.
 */
( function ( wp, window ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment || 'div';
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n ? wp.i18n.__ : function ( s ) { return s; };
	var blockEditor = wp.blockEditor || wp.editor;
	var components = wp.components || {};
	var hooks = wp.hooks || {};
	var compose = wp.compose || {};
	var data = wp.data || {};

	var Button = components.Button;
	var Placeholder = components.Placeholder;
	var Modal = components.Modal;
	var Spinner = components.Spinner;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var RangeControl = components.RangeControl;
	var PanelBody = components.PanelBody;
	var ToolbarGroup = components.ToolbarGroup;
	var ToolbarButton = components.ToolbarButton;
	var InspectorControls = blockEditor ? blockEditor.InspectorControls : function () { return null; };
	var BlockControls = blockEditor ? blockEditor.BlockControls : function () { return null; };
	var useBlockProps = blockEditor && blockEditor.useBlockProps ? blockEditor.useBlockProps : function () { return {}; };

	function merge( target, source ) {
		target = target || {};
		Object.keys( source || {} ).forEach( function ( key ) {
			target[ key ] = source[ key ];
		} );
		return target;
	}

	function PickerModal( props ) {
		var ref = useRef( null );

		useEffect( function () {
			if ( ref.current && window.RazunaPicker ) {
				window.RazunaPicker.mount(
					ref.current,
					merge(
						merge( {}, props.pickerOptions || {} ),
						{ onSelect: props.onSelect }
					)
				);
			}
		}, [] );

		return el(
			Modal,
			{ title: __( 'Insert from Razuna', 'razuna-dam' ), onRequestClose: props.onClose, className: 'razuna-modal' },
			el( 'div', { ref: ref, className: 'razuna-modal__body' }, ! window.RazunaPicker ? el( Spinner, null ) : null )
		);
	}

	function importedName( item ) {
		if ( ! item ) {
			return '';
		}
		if ( item.filename ) {
			return item.filename;
		}
		if ( item.title && 'string' === typeof item.title ) {
			return item.title;
		}
		if ( item.name ) {
			return item.name;
		}
		return item.url || '';
	}

	function importedAlt( item ) {
		return ( item && ( item.alt || importedName( item ) ) ) || '';
	}

	function importedMimeType( item ) {
		return String( ( item && ( item.mime || item.content_type || item.type ) ) || '' ).toLowerCase();
	}

	function importedMediaType( item ) {
		var mime = importedMimeType( item );

		if ( 0 === mime.indexOf( 'image/' ) || 'image' === ( item && item.type ) ) {
			return 'image';
		}
		if ( 0 === mime.indexOf( 'video/' ) || 'video' === ( item && item.type ) ) {
			return 'video';
		}
		if ( 0 === mime.indexOf( 'audio/' ) || 'audio' === ( item && item.type ) ) {
			return 'audio';
		}
		return 'file';
	}

	function razunaGalleryItems( payload ) {
		var items = Array.isArray( payload ) ? payload : [ payload ];

		return items.filter( function ( item ) {
			return item && item.url;
		} ).map( function ( item ) {
			return {
				fileId: item.id || item.file_id || '',
				url: item.url || '',
				fullUrl: item.full_url || '',
				alt: item.alt || item.name || '',
				name: item.name || '',
				width: item.width || 0,
				height: item.height || 0,
				caption: '',
			};
		} );
	}

	function AssetEdit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var open = useState( false );
		var isOpen = open[ 0 ];
		var setOpen = open[ 1 ];
		var blockProps = useBlockProps();

		function onSelect( payload ) {
			setAttributes( {
				fileId: payload.id,
				url: payload.url || '',
				fullUrl: payload.full_url || '',
				alt: attributes.alt || payload.alt || payload.name || '',
				name: payload.name || '',
				width: payload.width || 0,
				height: payload.height || 0,
				isImage: !! payload.is_image,
			} );
			setOpen( false );
		}

		var modal = isOpen
			? el( PickerModal, {
				onSelect: onSelect,
				onClose: function () { setOpen( false ); },
				pickerOptions: { multiple: false, mode: 'direct', context: 'asset' },
			} )
			: null;

		var inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Asset settings', 'razuna-dam' ), initialOpen: true },
				el( TextControl, {
					label: __( 'Alt text', 'razuna-dam' ),
					value: attributes.alt || '',
					onChange: function ( v ) { setAttributes( { alt: v } ); },
				} ),
				el( ToggleControl, {
					label: __( 'Link to full-size original', 'razuna-dam' ),
					checked: !! attributes.linkToOriginal,
					onChange: function ( v ) { setAttributes( { linkToOriginal: v } ); },
				} )
			)
		);

		if ( ! attributes.url ) {
			return el(
				'div',
				blockProps,
				inspector,
				el(
					Placeholder,
					{
						icon: 'format-image',
						label: __( 'Razuna Asset', 'razuna-dam' ),
						instructions: __( 'Browse and insert an asset from your Razuna library.', 'razuna-dam' ),
					},
					el( Button, { variant: 'primary', onClick: function () { setOpen( true ); } }, __( 'Browse Razuna', 'razuna-dam' ) )
				),
				modal
			);
		}

		var preview = attributes.isImage
			? el( 'img', { key: 'img', src: attributes.url, alt: attributes.alt || '', width: attributes.width || undefined } )
			: el( 'a', { key: 'lnk', href: attributes.url, className: 'razuna-asset__file' }, attributes.name || attributes.url );

		return el(
			'div',
			blockProps,
			inspector,
			el( 'figure', { className: 'razuna-asset' }, [
				preview,
				el(
					'div',
					{ key: 'tb', className: 'razuna-asset__toolbar' },
					el( Button, { variant: 'secondary', onClick: function () { setOpen( true ); } }, __( 'Replace', 'razuna-dam' ) )
				),
			] ),
			modal
		);
	}

	function GalleryEdit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var open = useState( false );
		var isOpen = open[ 0 ];
		var setOpen = open[ 1 ];
		var blockProps = useBlockProps( { className: 'razuna-gallery-editor' } );
		var items = Array.isArray( attributes.items ) ? attributes.items : [];

		function onSelect( payload ) {
			setAttributes( { items: razunaGalleryItems( payload ) } );
			setOpen( false );
		}

		var inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Gallery settings', 'razuna-dam' ), initialOpen: true },
				RangeControl
					? el( RangeControl, {
						label: __( 'Columns', 'razuna-dam' ),
						value: attributes.columns || 3,
						min: 1,
						max: 8,
						onChange: function ( value ) { setAttributes( { columns: value || 3 } ); },
					} )
					: null,
				el( ToggleControl, {
					label: __( 'Crop images', 'razuna-dam' ),
					checked: !! attributes.imageCrop,
					onChange: function ( value ) { setAttributes( { imageCrop: value } ); },
				} ),
				el( ToggleControl, {
					label: __( 'Link to full-size originals', 'razuna-dam' ),
					checked: !! attributes.linkToOriginal,
					onChange: function ( value ) { setAttributes( { linkToOriginal: value } ); },
				} ),
				el( TextControl, {
					label: __( 'Caption', 'razuna-dam' ),
					value: attributes.caption || '',
					onChange: function ( value ) { setAttributes( { caption: value } ); },
				} )
			)
		);

		var modal = isOpen
			? el( PickerModal, {
				onSelect: onSelect,
				onClose: function () { setOpen( false ); },
				pickerOptions: { multiple: true, allowedTypes: [ 'image' ], mode: 'direct', context: 'gallery' },
			} )
			: null;

		if ( ! items.length ) {
			return el(
				'div',
				blockProps,
				inspector,
				el(
					Placeholder,
					{
						icon: 'format-gallery',
						label: __( 'Razuna Gallery', 'razuna-dam' ),
						instructions: __( 'Select multiple images from your Razuna library.', 'razuna-dam' ),
					},
					el( Button, { variant: 'primary', onClick: function () { setOpen( true ); } }, __( 'Browse Razuna', 'razuna-dam' ) )
				),
				modal
			);
		}

		return el(
			'div',
			blockProps,
			inspector,
			el(
				'div',
				{ className: 'razuna-gallery-preview columns-' + ( attributes.columns || 3 ) },
				items.map( function ( item, index ) {
					return el( 'img', { key: item.fileId || index, src: item.url, alt: item.alt || '' } );
				} )
			),
			el(
				'div',
				{ className: 'razuna-asset__toolbar' },
				el( Button, { variant: 'secondary', onClick: function () { setOpen( true ); } }, __( 'Replace', 'razuna-dam' ) )
			),
			modal
		);
	}

	wp.blocks.registerBlockType( 'razuna/asset', {
		apiVersion: 2,
		title: __( 'Razuna Asset', 'razuna-dam' ),
		description: __( 'Embed an asset from your Razuna digital asset library.', 'razuna-dam' ),
		category: 'media',
		icon: 'format-image',
		supports: { align: true, html: false },
		attributes: {
			fileId: { type: 'string', default: '' },
			url: { type: 'string', default: '' },
			fullUrl: { type: 'string', default: '' },
			alt: { type: 'string', default: '' },
			name: { type: 'string', default: '' },
			width: { type: 'number', default: 0 },
			height: { type: 'number', default: 0 },
			isImage: { type: 'boolean', default: true },
			linkToOriginal: { type: 'boolean', default: false },
		},
		edit: AssetEdit,
		save: function () { return null; },
	} );

	wp.blocks.registerBlockType( 'razuna/gallery', {
		apiVersion: 2,
		title: __( 'Razuna Gallery', 'razuna-dam' ),
		description: __( 'Display multiple images from Razuna without copying them into WordPress.', 'razuna-dam' ),
		category: 'media',
		icon: 'format-gallery',
		supports: { align: true, html: false },
		attributes: {
			items: { type: 'array', default: [] },
			columns: { type: 'number', default: 3 },
			imageCrop: { type: 'boolean', default: true },
			linkToOriginal: { type: 'boolean', default: false },
			caption: { type: 'string', default: '' },
		},
		edit: GalleryEdit,
		save: function () { return null; },
	} );

	function coreConfig( blockName ) {
		var map = {
			'core/image': { multiple: false, allowedTypes: [ 'image' ], context: 'core-block', label: __( 'Replace from Razuna', 'razuna-dam' ) },
			'core/gallery': { multiple: true, allowedTypes: [ 'image' ], context: 'gallery', label: __( 'Add Razuna gallery', 'razuna-dam' ) },
			'core/video': { multiple: false, allowedTypes: [ 'video' ], context: 'core-block', label: __( 'Replace from Razuna', 'razuna-dam' ) },
			'core/audio': { multiple: false, allowedTypes: [ 'audio' ], context: 'core-block', label: __( 'Replace from Razuna', 'razuna-dam' ) },
			'core/file': { multiple: false, allowedTypes: [ 'file' ], context: 'core-block', label: __( 'Replace from Razuna', 'razuna-dam' ) },
			'core/cover': { multiple: false, allowedTypes: [ 'image', 'video' ], context: 'core-block', label: __( 'Replace from Razuna', 'razuna-dam' ) },
			'core/media-text': { multiple: false, allowedTypes: [ 'image', 'video' ], context: 'core-block', label: __( 'Replace from Razuna', 'razuna-dam' ) },
		};

		return map[ blockName ] || null;
	}

	function applyCoreImport( props, payload ) {
		var items = Array.isArray( payload ) ? payload : [ payload ];
		var item = items[ 0 ];
		var type = importedMediaType( item );
		var blockName = props.name;
		var blockDispatch = data.dispatch ? data.dispatch( 'core/block-editor' ) : null;
		var createBlock = wp.blocks.createBlock;

		if ( ! item ) {
			return;
		}

		if ( 'core/gallery' === blockName && blockDispatch && createBlock ) {
			blockDispatch.replaceBlock(
				props.clientId,
				createBlock(
					'core/gallery',
					{ columns: Math.min( 3, Math.max( 1, items.length ) ) },
					items.map( function ( image ) {
						return createBlock( 'core/image', {
							id: image.id,
							url: image.url,
							alt: importedAlt( image ),
						} );
					} )
				)
			);
			return;
		}

		if ( 'core/image' === blockName ) {
			props.setAttributes( {
				id: item.id,
				url: item.url,
				alt: importedAlt( item ),
				width: item.width ? String( item.width ) : undefined,
				height: item.height ? String( item.height ) : undefined,
			} );
		} else if ( 'core/video' === blockName ) {
			props.setAttributes( { id: item.id, src: item.url } );
		} else if ( 'core/audio' === blockName ) {
			props.setAttributes( { id: item.id, src: item.url } );
		} else if ( 'core/file' === blockName ) {
			props.setAttributes( {
				id: item.id,
				href: item.url,
				fileId: 'wp-block-file--media-' + item.id,
				fileName: importedName( item ),
				textLinkHref: item.url,
			} );
		} else if ( 'core/cover' === blockName ) {
			props.setAttributes( {
				id: item.id,
				url: item.url,
				alt: importedAlt( item ),
				backgroundType: 'video' === type ? 'video' : 'image',
			} );
		} else if ( 'core/media-text' === blockName ) {
			props.setAttributes( {
				mediaId: item.id,
				mediaUrl: item.url,
				mediaType: 'video' === type ? 'video' : 'image',
				mediaAlt: importedAlt( item ),
			} );
		}
	}

	function registerCoreControls() {
		if ( ! hooks.addFilter || ! compose.createHigherOrderComponent || ! BlockControls || ! ToolbarButton ) {
			return;
		}

		hooks.addFilter(
			'editor.BlockEdit',
			'razuna/media-block-controls',
			compose.createHigherOrderComponent( function ( BlockEdit ) {
				return function ( props ) {
					var config = coreConfig( props.name );
					var open = useState( false );
					var isOpen = open[ 0 ];
					var setOpen = open[ 1 ];

					function onSelect( payload ) {
						applyCoreImport( props, payload );
						setOpen( false );
					}

					return el(
						Fragment,
						null,
						el( BlockEdit, props ),
						config && props.isSelected
							? el(
								BlockControls,
								null,
								el(
									ToolbarGroup || 'div',
									null,
									el( ToolbarButton, {
										icon: 'images-alt2',
										label: config.label,
										onClick: function () { setOpen( true ); },
									} )
								)
							)
							: null,
						isOpen
							? el( PickerModal, {
								onSelect: onSelect,
								onClose: function () { setOpen( false ); },
								pickerOptions: {
									multiple: config.multiple,
									allowedTypes: config.allowedTypes,
									mode: 'import',
									context: config.context,
								},
							} )
							: null
					);
				};
			}, 'withRazunaMediaControls' )
		);
	}

	function registerFeaturedImagePanel() {
		var plugins = wp.plugins || {};
		var editPost = wp.editPost || {};
		var PluginDocumentSettingPanel = editPost.PluginDocumentSettingPanel;

		if ( ! plugins.registerPlugin || ! PluginDocumentSettingPanel || ! Button ) {
			return;
		}

		plugins.registerPlugin( 'razuna-featured-image', {
			render: function () {
				var open = useState( false );
				var isOpen = open[ 0 ];
				var setOpen = open[ 1 ];

				function onSelect( payload ) {
					if ( data.dispatch && payload && payload.id ) {
						data.dispatch( 'core/editor' ).editPost( { featured_media: payload.id } );
					}
					setOpen( false );
				}

				return el(
					Fragment,
					null,
					el(
						PluginDocumentSettingPanel,
						{ name: 'razuna-featured-image', title: __( 'Razuna', 'razuna-dam' ), className: 'razuna-featured-panel' },
						el( Button, { variant: 'secondary', onClick: function () { setOpen( true ); } }, __( 'Set featured image from Razuna', 'razuna-dam' ) )
					),
					isOpen
						? el( PickerModal, {
							onSelect: onSelect,
							onClose: function () { setOpen( false ); },
							pickerOptions: { multiple: false, allowedTypes: [ 'image' ], mode: 'import', context: 'featured' },
						} )
						: null
				);
			},
		} );
	}

	registerCoreControls();
	registerFeaturedImagePanel();
} )( window.wp, window );
