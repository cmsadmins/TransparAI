/**
 * TransparAI document sidebar panel (block editor): the AI level of the
 * post's text and the responsible person. Writes post meta through the
 * editor store, so the values save with the post and land in revisions.
 * Plain ES5, no build step; strings and keys come from transparaiContent.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element || ! wp.data || ! wp.components ) {
		return;
	}

	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel )
		|| ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var cfg = window.transparaiContent || {};

	function format( template, a, b ) {
		return String( template ).replace( '%1$s', a ).replace( '%2$s', b );
	}

	function Render() {
		var data = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				meta: editor.getEditedPostAttribute( 'meta' ) || {},
				status: editor.getEditedPostAttribute( 'status' )
			};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;
		var meta = data.meta;
		var level = meta[ cfg.keyLevel ] || '';

		function setMeta( key, value ) {
			var patch = {};
			patch[ key ] = value;
			editPost( { meta: patch } );
		}

		/* Site default for brand-new posts only; existing posts stay visibly unclassified. */
		useEffect( function () {
			if ( 'auto-draft' === data.status && ! level && cfg.defaultLevel ) {
				setMeta( cfg.keyLevel, cfg.defaultLevel );
			}
		}, [ data.status ] );

		var stamp = null;
		try {
			stamp = meta[ cfg.keyReview ] ? JSON.parse( meta[ cfg.keyReview ] ) : null;
		} catch ( e ) {
			stamp = null;
		}

		var children = [
			el( SelectControl, {
				key: 'level',
				label: cfg.level,
				value: level,
				options: cfg.levels || [],
				onChange: function ( value ) {
					setMeta( cfg.keyLevel, value );
				}
			} )
		];

		if ( level === cfg.reviewedLevel ) {
			children.push( el( TextControl, {
				key: 'responsible',
				label: cfg.responsible,
				value: meta[ cfg.keyResponsible ] || '',
				placeholder: cfg.placeholder || '',
				onChange: function ( value ) {
					setMeta( cfg.keyResponsible, value );
				}
			} ) );
		}

		if ( stamp && stamp.on ) {
			children.push( el( 'p', { key: 'stamp', className: 'description' },
				format( cfg.reviewed, stamp.by || stamp.responsible || '', stamp.on ) ) );
		}

		children.push( el( 'p', { key: 'help', className: 'description' }, cfg.help ) );

		return el( Panel, { name: 'transparai-content', title: cfg.title }, children );
	}

	wp.plugins.registerPlugin( 'transparai-content', {
		render: Render,
		icon: 'visibility'
	} );
} )( window.wp );
