/**
 * Puzzle Gate — block editor script
 *
 * WHY THERE IS NO JSX HERE
 *
 * JSX is not JavaScript; it has to be compiled. Shipping a block that needs
 * `npm run build` means anyone editing this plugin needs Node installed and a
 * toolchain configured. `wp.element.createElement` is what JSX compiles *to*,
 * so writing it directly gives an identical result with zero build step. The
 * cost is verbosity, which is why the alias `el` exists.
 *
 * The globals (wp.blocks, wp.element, …) are guaranteed to exist because
 * index.asset.php declares them as script dependencies.
 */
( function ( blocks, element, blockEditor, components, data, i18n ) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var useEffect = element.useEffect;
	var __ = i18n.__;

	var useBlockProps = blockEditor.useBlockProps;
	var InnerBlocks = blockEditor.InnerBlocks;
	var InspectorControls = blockEditor.InspectorControls;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;

	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var Button = components.Button;
	var Notice = components.Notice;

	var TYPES = [
		{ value: 'slide', label: __( 'Sliding tiles', 'puzzle-gate' ) },
		{ value: 'riddle', label: __( 'Riddle / question', 'puzzle-gate' ) },
		{ value: 'sequence', label: __( 'Number sequence', 'puzzle-gate' ) }
	];

	/**
	 * Generate a short, collision-resistant id.
	 *
	 * This is only an identifier, never a secret, so Math.random is fine here —
	 * unlike the server side, where tokens come from a CSPRNG because guessing
	 * one would matter.
	 */
	function newId() {
		return 'g' + Math.random().toString( 36 ).slice( 2, 10 );
	}

	/**
	 * Is this gateId already used by a different block in this post?
	 *
	 * Duplicating a block copies its attributes, including the id. Two gates
	 * sharing an id would share unlock state and the server would only ever find
	 * the first one — a confusing bug, so we detect it and re-roll on insert.
	 */
	function idTaken( gateId, ownClientId ) {
		var store = data.select( 'core/block-editor' );
		if ( ! store || ! gateId ) {
			return false;
		}

		var ids = store.getClientIdsWithDescendants();

		return ids.some( function ( clientId ) {
			if ( clientId === ownClientId ) {
				return false;
			}
			var block = store.getBlock( clientId );
			return block && block.name === 'puzzle-gate/gate' && block.attributes.gateId === gateId;
		} );
	}

	function Edit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;

		// Runs once per mounted block. Assigns an id on first insert, and
		// replaces it if this block turned out to be a duplicate.
		useEffect( function () {
			if ( ! a.gateId || idTaken( a.gateId, props.clientId ) ) {
				set( { gateId: newId() } );
			}
		}, [] );

		var blockProps = useBlockProps( { className: 'pgz-editor' } );

		var settings = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Puzzle', 'puzzle-gate' ), initialOpen: true },
				el( SelectControl, {
					label: __( 'Puzzle type', 'puzzle-gate' ),
					value: a.type,
					options: TYPES,
					onChange: function ( v ) { set( { type: v } ); }
				} ),

				a.type === 'slide' && el( RangeControl, {
					label: __( 'Grid size', 'puzzle-gate' ),
					value: a.size,
					min: 3,
					max: 5,
					help: __( '3 = 8 tiles, 4 = 15 tiles. Four and up gets hard fast.', 'puzzle-gate' ),
					onChange: function ( v ) { set( { size: v } ); }
				} ),

				a.type === 'slide' && el(
					MediaUploadCheck,
					null,
					el( MediaUpload, {
						allowedTypes: [ 'image' ],
						value: a.image,
						onSelect: function ( media ) { set( { image: media.url } ); },
						render: function ( open ) {
							return el(
								Fragment,
								null,
								el( Button, {
									variant: 'secondary',
									onClick: open.open
								}, a.image ? __( 'Replace tile image', 'puzzle-gate' ) : __( 'Use an image for the tiles', 'puzzle-gate' ) ),
								a.image && el( Button, {
									variant: 'link',
									isDestructive: true,
									onClick: function () { set( { image: '' } ); }
								}, __( 'Remove image', 'puzzle-gate' ) )
							);
						}
					} )
				),

				a.type === 'riddle' && el( TextareaControl, {
					label: __( 'Question', 'puzzle-gate' ),
					value: a.question,
					onChange: function ( v ) { set( { question: v } ); }
				} ),

				a.type === 'riddle' && el( TextControl, {
					label: __( 'Accepted answers', 'puzzle-gate' ),
					value: a.answer,
					help: __( 'Separate alternatives with |  — case, spacing and punctuation are ignored.', 'puzzle-gate' ),
					onChange: function ( v ) { set( { answer: v } ); }
				} ),

				a.type === 'riddle' && el( TextControl, {
					label: __( 'Hint', 'puzzle-gate' ),
					value: a.hint,
					help: __( 'Offered after three wrong answers.', 'puzzle-gate' ),
					onChange: function ( v ) { set( { hint: v } ); }
				} ),

				a.type === 'sequence' && el( SelectControl, {
					label: __( 'Difficulty', 'puzzle-gate' ),
					value: a.difficulty,
					options: [
						{ value: 'normal', label: __( 'Normal', 'puzzle-gate' ) },
						{ value: 'hard', label: __( 'Hard', 'puzzle-gate' ) }
					],
					onChange: function ( v ) { set( { difficulty: v } ); }
				} )
			),

			el(
				PanelBody,
				{ title: __( 'Lock plate', 'puzzle-gate' ), initialOpen: false },
				el( TextControl, {
					label: __( 'Title', 'puzzle-gate' ),
					value: a.title,
					onChange: function ( v ) { set( { title: v } ); }
				} ),
				el( TextareaControl, {
					label: __( 'Teaser', 'puzzle-gate' ),
					value: a.teaser,
					onChange: function ( v ) { set( { teaser: v } ); }
				} ),
				el( TextControl, {
					label: __( 'Button label', 'puzzle-gate' ),
					value: a.buttonText,
					onChange: function ( v ) { set( { buttonText: v } ); }
				} ),
				el( 'p', { className: 'pgz-editor__id' }, __( 'Gate id: ', 'puzzle-gate' ), el( 'code', null, a.gateId ) )
			)
		);

		var needsAnswer = a.type === 'riddle' && ! a.answer.trim();

		var canvas = el(
			'div',
			blockProps,
			el(
				'div',
				{ className: 'pgz-editor__head' },
				el( 'span', { className: 'pgz-editor__badge' }, __( 'Puzzle Gate', 'puzzle-gate' ) ),
				el( 'span', { className: 'pgz-editor__meta' }, typeLabel( a.type ) )
			),

			needsAnswer && el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( 'Add at least one accepted answer, or nobody can open this gate.', 'puzzle-gate' )
			),

			el( 'p', { className: 'pgz-editor__note' },
				__( 'Everything below is hidden until a visitor solves the puzzle. It is never sent to their browser before then.', 'puzzle-gate' )
			),

			el(
				'div',
				{ className: 'pgz-editor__body' },
				el( InnerBlocks, {
					renderAppender: InnerBlocks.ButtonBlockAppender
				} )
			)
		);

		return el( Fragment, null, settings, canvas );
	}

	function typeLabel( slug ) {
		for ( var i = 0; i < TYPES.length; i++ ) {
			if ( TYPES[ i ].value === slug ) {
				return TYPES[ i ].label;
			}
		}
		return slug;
	}

	/**
	 * save() writes the *inner* blocks into post_content and nothing else.
	 *
	 * This is the crux of the whole design. The hidden blocks must be saved to
	 * the database (they are the content), but the block has a render_callback
	 * in PHP, so what the visitor's browser actually receives is decided on the
	 * server at request time — not by this function.
	 */
	function Save() {
		return el( InnerBlocks.Content );
	}

	blocks.registerBlockType( 'puzzle-gate/gate', {
		edit: Edit,
		save: Save,

		// Copying a gate must not copy its identity.
		__experimentalLabel: function ( attributes ) {
			return attributes.title || __( 'Puzzle Gate', 'puzzle-gate' );
		}
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.data,
	window.wp.i18n
);
