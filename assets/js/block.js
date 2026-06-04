/**
 * "Razuna Asset" block — dynamic (server-rendered). No JSX / build step:
 * uses wp.element.createElement directly so the file loads as-is.
 *
 * Stores the picked file's id + durable direct-link URL + dimensions. The URL
 * is a 365-day signed Razuna direct link, so published content renders for
 * anonymous visitors independently of the editor's OAuth session.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useRef = wp.element.useRef;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n ? wp.i18n.__ : function ( s ) { return s; };
	var blockEditor = wp.blockEditor || wp.editor;
	var components = wp.components || {};

	var Button = components.Button;
	var Placeholder = components.Placeholder;
	var Modal = components.Modal;
	var Spinner = components.Spinner;
	var TextControl = components.TextControl;
	var ToggleControl = components.ToggleControl;
	var PanelBody = components.PanelBody;
	var InspectorControls = blockEditor ? blockEditor.InspectorControls : function () { return null; };
	var useBlockProps = blockEditor && blockEditor.useBlockProps ? blockEditor.useBlockProps : function () { return {}; };

	function PickerModal( props ) {
		var ref = useRef( null );

		useEffect( function () {
			if ( ref.current && window.RazunaPicker ) {
				window.RazunaPicker.mount( ref.current, { onSelect: props.onSelect } );
			}
		}, [] );

		return el(
			Modal,
			{ title: __( 'Insert from Razuna', 'razuna' ), onRequestClose: props.onClose, className: 'razuna-modal' },
			el( 'div', { ref: ref, className: 'razuna-modal__body' }, ! window.RazunaPicker ? el( Spinner, null ) : null )
		);
	}

	function Edit( props ) {
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
			? el( PickerModal, { onSelect: onSelect, onClose: function () { setOpen( false ); } } )
			: null;

		var inspector = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Asset settings', 'razuna' ), initialOpen: true },
				el( TextControl, {
					label: __( 'Alt text', 'razuna' ),
					value: attributes.alt || '',
					onChange: function ( v ) { setAttributes( { alt: v } ); },
				} ),
				el( ToggleControl, {
					label: __( 'Link to full-size original', 'razuna' ),
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
						label: __( 'Razuna Asset', 'razuna' ),
						instructions: __( 'Browse and insert an asset from your Razuna library.', 'razuna' ),
					},
					el( Button, { variant: 'primary', onClick: function () { setOpen( true ); } }, __( 'Browse Razuna', 'razuna' ) )
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
					el( Button, { variant: 'secondary', onClick: function () { setOpen( true ); } }, __( 'Replace', 'razuna' ) )
				),
			] ),
			modal
		);
	}

	wp.blocks.registerBlockType( 'razuna/asset', {
		apiVersion: 2,
		title: __( 'Razuna Asset', 'razuna' ),
		description: __( 'Embed an image from your Razuna digital asset library.', 'razuna' ),
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
		edit: Edit,
		// Dynamic block: PHP render_callback emits the final markup.
		save: function () { return null; },
	} );
} )( window.wp );
