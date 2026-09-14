/**
 * TransparAI Quick Edit: prefill the AI level from the row's data marker.
 *
 * Core builds the #edit-{id} row inside inlineEditPost.edit(), so the
 * original runs first and the select is filled afterwards. Without this
 * prefill a Quick Edit save would reset every post to the first option.
 */
( function () {
	'use strict';

	if ( ! window.inlineEditPost || 'function' !== typeof window.inlineEditPost.edit ) {
		return;
	}

	var original = window.inlineEditPost.edit;

	window.inlineEditPost.edit = function ( post ) {
		original.apply( this, arguments );

		var id = ( post && 'object' === typeof post ) ? parseInt( this.getId( post ), 10 ) : parseInt( post, 10 );
		if ( ! id ) {
			return;
		}
		var row = document.getElementById( 'post-' + id );
		var editRow = document.getElementById( 'edit-' + id );
		if ( ! row || ! editRow ) {
			return;
		}
		var marker = row.querySelector( '.trai-qe' );
		var select = editRow.querySelector( 'select[name="transparai_content_level"]' );
		if ( select ) {
			select.value = ( marker && marker.getAttribute( 'data-trai-level' ) ) || '';
		}
	};
} )();
