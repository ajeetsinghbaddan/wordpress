/**
 * Editor script for the "Testimonials Showcase" block.
 *
 * This file is written in plain JavaScript using wp.element.createElement
 * (aliased to `el`) instead of JSX, so it runs directly in the browser with NO
 * build step / compilation. That keeps the plugin a simple installable .zip.
 *
 * What it does:
 *  - Registers the block on the JS side (type, attributes mirror block.json).
 *  - Renders InspectorControls (the right-hand sidebar) with three controls:
 *      design (dropdown), category (dropdown), count (number).
 *  - Shows a live preview using ServerSideRender, which calls our PHP
 *    render_callback so the editor preview matches the real front end exactly.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var ServerSideRender = wp.serverSideRender;
	var useSelect = wp.data.useSelect;

	// The six design options. Keys MUST match ASB_TS_Renderer::get_designs().
	var DESIGNS = [
		{ label: __( 'Classic card grid', 'asb-testimonials-showcase' ), value: 'grid' },
		{ label: __( 'Horizontal slider / carousel', 'asb-testimonials-showcase' ), value: 'slider' },
		{ label: __( 'Single-quote spotlight', 'asb-testimonials-showcase' ), value: 'spotlight' },
		{ label: __( 'Masonry grid', 'asb-testimonials-showcase' ), value: 'masonry' },
		{ label: __( 'Minimal list with avatars', 'asb-testimonials-showcase' ), value: 'list' },
		{ label: __( 'Bubble / chat-style', 'asb-testimonials-showcase' ), value: 'bubble' }
	];

	registerBlockType( 'asb/testimonials', {
		/**
		 * edit() renders the block UI inside the editor.
		 *
		 * @param {Object} props Block props: attributes + setAttributes.
		 */
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps();

			// Pull the testimonial categories from the REST API for the dropdown.
			// useSelect re-runs when the data store updates, so the list stays current.
			var categories = useSelect( function ( select ) {
				return select( 'core' ).getEntityRecords( 'taxonomy', 'testimonial_category', {
					per_page: -1,
					hide_empty: false
				} );
			}, [] );

			// Build the category <option> list, starting with "All categories" (0).
			var categoryOptions = [ { label: __( 'All categories', 'asb-testimonials-showcase' ), value: 0 } ];
			if ( categories ) {
				categories.forEach( function ( term ) {
					categoryOptions.push( { label: term.name, value: term.id } );
				} );
			}

			// The sidebar controls.
			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Testimonials settings', 'asb-testimonials-showcase' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Design', 'asb-testimonials-showcase' ),
						value: attributes.design,
						options: DESIGNS,
						onChange: function ( value ) {
							setAttributes( { design: value } );
						}
					} ),
					el( SelectControl, {
						label: __( 'Category', 'asb-testimonials-showcase' ),
						value: attributes.category,
						options: categoryOptions,
						onChange: function ( value ) {
							// SelectControl returns strings; store as a number.
							setAttributes( { category: parseInt( value, 10 ) || 0 } );
						}
					} ),
					el( RangeControl, {
						label: __( 'Number to show', 'asb-testimonials-showcase' ),
						value: attributes.count,
						min: 1,
						max: 50,
						onChange: function ( value ) {
							setAttributes( { count: value } );
						}
					} )
				)
			);

			// The live preview, rendered by our PHP callback on the server.
			var preview = el( ServerSideRender, {
				block: 'asb/testimonials',
				attributes: attributes
			} );

			// useBlockProps() must wrap the block's visible markup.
			return el( 'div', blockProps, inspector, preview );
		},

		/**
		 * save() returns null because this is a dynamic (server-rendered) block:
		 * nothing is saved into post content; the PHP render_callback produces the
		 * HTML fresh on every page load.
		 */
		save: function () {
			return null;
		}
	} );
} )( window.wp );
