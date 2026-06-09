/**
 * Quiz Certify - block editor script.
 *
 * Written with wp.element.createElement (aliased to "el") instead of JSX so it runs
 * directly in the browser with no build step. It registers a block whose only setting
 * is which quiz to show; the actual quiz HTML is produced server-side by the block's
 * render_callback, so here we just draw the editor controls and a placeholder.
 */
( function ( blocks, element, components, blockEditor, i18n ) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var SelectControl = components.SelectControl;
	var Placeholder = components.Placeholder;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;

	// The quiz list injected by wp_localize_script.
	var quizzes = ( typeof QuizCertifyBlock !== 'undefined' && QuizCertifyBlock.quizzes ) ? QuizCertifyBlock.quizzes : [];

	// SelectControl wants {label, value} options; prepend a "choose one" entry.
	var options = [ { label: __( '— Select a quiz —', 'quiz-certify' ), value: '' } ].concat( quizzes );

	blocks.registerBlockType( 'quiz-certify/quiz', {
		title: __( 'Quiz Certify', 'quiz-certify' ),
		description: __( 'Display a quiz with a printable certificate.', 'quiz-certify' ),
		icon: 'forms',
		category: 'widgets',
		attributes: {
			quizId: { type: 'string', default: '' }
		},

		edit: function ( props ) {
			var quizId = props.attributes.quizId;

			function onChange( value ) {
				props.setAttributes( { quizId: value } );
			}

			// Find the chosen quiz's label for the placeholder text.
			var selected = quizzes.filter( function ( q ) { return q.value === quizId; } )[ 0 ];
			var labelText = selected
				? __( 'Quiz: ', 'quiz-certify' ) + selected.label
				: __( 'No quiz selected yet.', 'quiz-certify' );

			var picker = el( SelectControl, {
				label: __( 'Quiz', 'quiz-certify' ),
				value: quizId,
				options: options,
				onChange: onChange
			} );

			return el(
				'div',
				blockEditor.useBlockProps ? blockEditor.useBlockProps() : {},
				el( InspectorControls, {},
					el( PanelBody, { title: __( 'Quiz settings', 'quiz-certify' ), initialOpen: true },
						picker
					)
				),
				el( Placeholder, {
						icon: 'forms',
						label: __( 'Quiz Certify', 'quiz-certify' ),
						instructions: labelText
					},
					// Repeat the picker in the body so it's reachable without the sidebar.
					picker
				)
			);
		},

		// Dynamic block: nothing is saved to post content; the server renders it.
		save: function () { return null; }
	} );

	// Listing block — no settings; shows all published quizzes as a selectable grid.
	blocks.registerBlockType( 'quiz-certify/list', {
		title: __( 'Quiz List', 'quiz-certify' ),
		description: __( 'Show all quizzes as a grid users can pick from.', 'quiz-certify' ),
		icon: 'list-view',
		category: 'widgets',
		edit: function () {
			return el(
				'div',
				blockEditor.useBlockProps ? blockEditor.useBlockProps() : {},
				el( Placeholder, {
					icon: 'list-view',
					label: __( 'Quiz List', 'quiz-certify' ),
					instructions: __( 'All published quizzes appear here. Visitors pick one to take it.', 'quiz-certify' )
				} )
			);
		},
		save: function () { return null; }
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.components,
	window.wp.blockEditor,
	window.wp.i18n
);
