/**
 * TransparAI blocks, editor side. Plain ES5, no build step: every block is
 * rendered by PHP, the editor shows a live server preview plus a style
 * select, a custom text field and (media label only) a file picker. Block
 * names, titles and strings arrive through wp_localize_script
 * (transparaiBlocks); title and description come from block.json.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.serverSideRender ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var editor = wp.blockEditor || wp.editor;
	var InspectorControls = editor.InspectorControls;
	var useBlockProps = editor.useBlockProps;
	var MediaUpload = editor.MediaUpload;
	var MediaUploadCheck = editor.MediaUploadCheck;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var Button = wp.components.Button;
	var ServerSideRender = wp.serverSideRender;
	var config = window.transparaiBlocks || {};
	var labels = config.labels || {};
	var blocks = config.blocks || {};

	var styleOptions = [
		{ value: 'block', label: labels.block || 'Block (own line)' },
		{ value: 'inline', label: labels.inline || 'Inline (inside text)' },
		{ value: 'banner', label: labels.banner || 'Banner (dismissible)' },
		{ value: 'badge', label: labels.badge || 'Badge (small chip)' },
		{ value: 'modal', label: labels.modal || 'Modal (button opens a dialog)' }
	];

	/**
	 * Inspector controls of one block: style, custom text, file picker.
	 */
	function controls( name, props ) {
		var attrs = props.attributes;
		var block = blocks[ name ] || {};
		var items = [];

		if ( block.hasStyle ) {
			items.push(
				el( SelectControl, {
					key: 'variant',
					label: labels.variant || 'Style',
					value: attrs.variant || block.defaultVariant || 'block',
					options: styleOptions,
					onChange: function ( value ) {
						props.setAttributes( { variant: value } );
					}
				} )
			);
		}
		if ( block.hasId && MediaUpload ) {
			items.push(
				el(
					MediaUploadCheck,
					{ key: 'media' },
					el( MediaUpload, {
						onSelect: function ( media ) {
							props.setAttributes( { id: media && media.id ? parseInt( media.id, 10 ) : 0 } );
						},
						value: attrs.id || 0,
						render: function ( open ) {
							return el(
								'p',
								null,
								el(
									Button,
									{ variant: 'secondary', onClick: open },
									attrs.id ? ( labels.replace || 'Replace file' ) : ( labels.select || 'Select file' )
								),
								attrs.id
									? el(
											Button,
											{ variant: 'link', isDestructive: true, onClick: function () {
												props.setAttributes( { id: 0 } );
											} },
											labels.remove || 'Remove'
										)
									: null
							);
						}
					} )
				)
			);
		}
		if ( block.hasText ) {
			items.push(
				el( TextControl, {
					key: 'text',
					label: labels.text || 'Custom text',
					help: labels.textHelp || '',
					value: attrs.text || '',
					onChange: function ( value ) {
						props.setAttributes( { text: value } );
					}
				} )
			);
		}
		if ( ! items.length ) {
			return null;
		}
		return el(
			InspectorControls,
			null,
			el( PanelBody, { title: block.title || labels.panel || 'TransparAI', initialOpen: true }, items )
		);
	}

	Object.keys( blocks ).forEach( function ( name ) {
		wp.blocks.registerBlockType( name, {
			title: blocks[ name ].title,
			edit: function ( props ) {
				var blockProps = useBlockProps ? useBlockProps() : {};
				return el(
					Fragment,
					null,
					controls( name, props ),
					el(
						'div',
						blockProps,
						el( ServerSideRender, {
							block: name,
							attributes: props.attributes
						} )
					)
				);
			},
			save: function () {
				return null;
			}
		} );
	} );
} )( window.wp );
