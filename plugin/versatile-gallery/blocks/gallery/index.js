/**
 * Versatile Gallery — block editor script.
 *
 * Written without JSX so it runs as-is, no build step. wp.element.createElement
 * (aliased "el") is the function JSX would normally compile down to. The block
 * is "dynamic": save() returns null because the real HTML is produced by PHP
 * (render.php). The editor only needs to (a) collect settings and (b) show a
 * preview.
 */
( function ( blocks, element, blockEditor, components, data, i18n ) {
	'use strict';

	var el       = element.createElement;
	var __       = i18n.__;
	var useSelect = data.useSelect;

	var InspectorControls = blockEditor.InspectorControls;
	var MediaUpload       = blockEditor.MediaUpload;
	var MediaUploadCheck  = blockEditor.MediaUploadCheck;
	var useBlockProps     = blockEditor.useBlockProps;

	var PanelBody     = components.PanelBody;
	var RangeControl  = components.RangeControl;
	var SelectControl = components.SelectControl;
	var ToggleControl = components.ToggleControl;
	var Button        = components.Button;
	var Placeholder   = components.Placeholder;

	blocks.registerBlockType( 'versatile-gallery/gallery', {
		/**
		 * The editing UI. `props` carries the current attributes and a
		 * setAttributes() function to update them (which re-renders the block).
		 */
		edit: function ( props ) {
			var attributes   = props.attributes;
			var setAttributes = props.setAttributes;
			var ids          = attributes.ids || [];

			// useBlockProps wires the wrapper to the editor (alignment, etc.).
			var blockProps = useBlockProps();

			// Resolve each selected ID to its media record so we can show real
			// thumbnails. useSelect subscribes to the data store and re-runs
			// when `ids` changes.
			var images = useSelect(
				function ( select ) {
					if ( ! ids.length ) {
						return [];
					}
					var core = select( 'core' );
					return ids.map( function ( id ) {
						return core.getMedia( id );
					} );
				},
				[ ids ]
			);

			// Called when the user finishes picking images in the media modal.
			function onSelectImages( media ) {
				setAttributes( {
					ids: media.map( function ( item ) {
						return item.id;
					} ),
				} );
			}

			// ---- Sidebar (Inspector) controls ----
			var inspector = el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Gallery settings', 'versatile-gallery' ) },
					el( SelectControl, {
						label: __( 'Layout', 'versatile-gallery' ),
						value: attributes.layout,
						options: [
							{ label: __( 'Uniform grid', 'versatile-gallery' ), value: 'grid' },
							{ label: __( 'Masonry', 'versatile-gallery' ), value: 'masonry' },
							{ label: __( 'Justified rows', 'versatile-gallery' ), value: 'justified' },
							{ label: __( 'Mosaic (featured)', 'versatile-gallery' ), value: 'mosaic' },
							{ label: __( 'Carousel', 'versatile-gallery' ), value: 'carousel' },
							{ label: __( 'Grid with captions', 'versatile-gallery' ), value: 'captions' },
						],
						onChange: function ( v ) {
							setAttributes( { layout: v } );
						},
					} ),
					el( RangeControl, {
						label: __( 'Columns', 'versatile-gallery' ),
						value: attributes.columns,
						min: 1,
						max: 6,
						onChange: function ( v ) {
							setAttributes( { columns: v } );
						},
					} ),
					el( RangeControl, {
						label: __( 'Gap (px)', 'versatile-gallery' ),
						value: attributes.gap,
						min: 0,
						max: 80,
						onChange: function ( v ) {
							setAttributes( { gap: v } );
						},
					} ),
					el( SelectControl, {
						label: __( 'Image size', 'versatile-gallery' ),
						value: attributes.size,
						options: [
							{ label: __( 'Thumbnail', 'versatile-gallery' ), value: 'thumbnail' },
							{ label: __( 'Medium', 'versatile-gallery' ), value: 'medium' },
							{ label: __( 'Large', 'versatile-gallery' ), value: 'large' },
							{ label: __( 'Full', 'versatile-gallery' ), value: 'full' },
						],
						onChange: function ( v ) {
							setAttributes( { size: v } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Enable lightbox', 'versatile-gallery' ),
						checked: attributes.lightbox,
						onChange: function ( v ) {
							setAttributes( { lightbox: v } );
						},
					} )
				)
			);

			// ---- Main canvas: empty state vs. preview ----
			var picker = el(
				MediaUploadCheck,
				{},
				el( MediaUpload, {
					onSelect: onSelectImages,
					allowedTypes: [ 'image' ],
					multiple: true,
					gallery: true,
					value: ids,
					render: function ( open ) {
						return el(
							Button,
							{ variant: ids.length ? 'secondary' : 'primary', onClick: open.open },
							ids.length
								? __( 'Edit gallery', 'versatile-gallery' )
								: __( 'Select images', 'versatile-gallery' )
						);
					},
				} )
			);

			var canvas;
			if ( ! ids.length ) {
				canvas = el(
					Placeholder,
					{
						icon: 'format-gallery',
						label: __( 'Versatile Gallery', 'versatile-gallery' ),
						instructions: __( 'Choose images from the media library.', 'versatile-gallery' ),
					},
					picker
				);
			} else {
				var layout = attributes.layout || 'grid';

				// Build the SAME markup the frontend uses, so the editor preview
				// is true WYSIWYG. The frontend stylesheet is loaded into the
				// editor via block.json "editorStyle", so these classes style
				// the preview identically to the live site.
				var tiles = images.map( function ( img, index ) {
					if ( ! img ) {
						return null; // still loading from the store
					}
					var sizes = img.media_details && img.media_details.sizes;
					var url   = sizes && sizes.medium ? sizes.medium.source_url : img.source_url;

					var children = [
						el( 'img', {
							key: 'img',
							className: 'vgal-image',
							src: url,
							alt: img.alt_text || '',
						} ),
					];

					// Captions layout: surface the image caption (or title).
					if ( 'captions' === layout ) {
						var caption = '';
						if ( img.caption && img.caption.rendered ) {
							caption = img.caption.rendered.replace( /<[^>]+>/g, '' ).trim();
						}
						if ( ! caption && img.title && img.title.rendered ) {
							caption = img.title.rendered;
						}
						if ( caption ) {
							children.push( el( 'span', { key: 'cap', className: 'vgal-caption' }, caption ) );
						}
					}

					// Justified layout: per-image aspect ratio feeds the flex math.
					var itemStyle;
					if ( 'justified' === layout && img.media_details && img.media_details.height ) {
						itemStyle = {
							'--vgal-ratio': ( img.media_details.width / img.media_details.height ).toFixed( 3 ),
						};
					}

					return el(
						'figure',
						{ key: ids[ index ], className: 'vgal-item', style: itemStyle },
						children
					);
				} );

				// React passes "--custom" properties straight through to the DOM,
				// which is how the same CSS variables the renderer uses work here.
				var preview = el(
					'div',
					{
						className: 'vgal-gallery vgal-gallery--' + layout,
						style: {
							'--vgal-columns': attributes.columns,
							'--vgal-gap': attributes.gap + 'px',
							marginBottom: '12px',
						},
					},
					tiles
				);

				canvas = el( 'div', {}, preview, picker );
			}

			return el( 'div', blockProps, inspector, canvas );
		},

		// Dynamic block: HTML comes from PHP, so nothing is stored in the post.
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.data,
	window.wp.i18n
);
