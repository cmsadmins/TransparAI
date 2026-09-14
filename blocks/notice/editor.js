/**
 * TransparAI notice block, editor side. Plain ES5, no build step: the block
 * is rendered by PHP, the editor only shows a live server preview and two
 * controls. Strings arrive through wp_localize_script (transparaiNotice).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.serverSideRender ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = ( wp.blockEditor || wp.editor ).InspectorControls;
	var useBlockProps = ( wp.blockEditor || wp.editor ).useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ServerSideRender = wp.serverSideRender;
	var labels = window.transparaiNotice || {};

	wp.blocks.registerBlockType( 'transparai/notice', {
		title: labels.title || 'AI notice',
		edit: function ( props ) {
			var attrs = props.attributes;
			var blockProps = useBlockProps ? useBlockProps() : {};

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: labels.title || 'AI notice', initialOpen: true },
						el( SelectControl, {
							label: labels.variant || 'Style',
							value: attrs.variant || 'block',
							options: [
								{ value: 'block', label: labels.block || 'Block (own line)' },
								{ value: 'inline', label: labels.inline || 'Inline (inside text)' }
							],
							onChange: function ( value ) {
								props.setAttributes( { variant: value } );
							}
						} ),
						el( TextControl, {
							label: labels.text || 'Custom text',
							help: labels.textHelp || '',
							value: attrs.text || '',
							onChange: function ( value ) {
								props.setAttributes( { text: value } );
							}
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( ServerSideRender, {
						block: 'transparai/notice',
						attributes: attrs
					} )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
