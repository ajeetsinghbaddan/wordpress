/**
 * Editor UI for ytep/embed.
 *
 * Plain ES5 with wp.element.createElement instead of JSX so the plugin runs
 * straight from the folder with no npm build step.
 *
 * Flow: a freshly inserted block shows a chooser ("What do you want to
 * embed?"). Picking a type reveals the matching input — a URL field, or a
 * dropdown of channels saved under Settings → YouTube Embed Pro. Once a
 * source exists, the block shows the live server-rendered preview.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;

	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;

	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var ToggleControl = wp.components.ToggleControl;
	var RangeControl = wp.components.RangeControl;
	var Placeholder = wp.components.Placeholder;
	var Disabled = wp.components.Disabled;
	var Button = wp.components.Button;

	var ServerSideRender = wp.serverSideRender;

	// Injected by wp_localize_script; guard so a missing global cannot crash the editor.
	var data = window.ytepData || {};
	var channels = Array.isArray( data.channels ) ? data.channels : [];
	var settingsUrl = typeof data.settingsUrl === 'string' ? data.settingsUrl : '';

	var KIND_CHOICES = [
		{ value: 'video', label: __( 'Video', 'ytep' ) },
		{ value: 'short', label: __( 'Short', 'ytep' ) },
		{ value: 'playlist', label: __( 'Playlist', 'ytep' ) },
		{ value: 'live', label: __( 'Live stream', 'ytep' ) },
		{ value: 'channel', label: __( 'Channel uploads', 'ytep' ) },
		{ value: 'auto', label: __( 'Any link (auto-detect)', 'ytep' ) }
	];

	var URL_PLACEHOLDERS = {
		video: 'https://www.youtube.com/watch?v=…',
		short: 'https://www.youtube.com/shorts/…',
		playlist: 'https://www.youtube.com/playlist?list=…',
		live: 'https://www.youtube.com/live/…',
		auto: __( 'Paste any YouTube link or ID', 'ytep' )
	};

	function set( props, key ) {
		return function ( value ) {
			var update = {};
			update[ key ] = value;
			props.setAttributes( update );
		};
	}

	/**
	 * Step 1: the type chooser buttons.
	 */
	function renderChooser( onPick ) {
		return el(
			'div',
			{ className: 'ytep-chooser', style: { display: 'flex', flexWrap: 'wrap', gap: '8px' } },
			KIND_CHOICES.map( function ( choice ) {
				return el(
					Button,
					{
						key: choice.value,
						variant: 'secondary',
						onClick: function () {
							onPick( choice.value );
						}
					},
					choice.label
				);
			} )
		);
	}

	/**
	 * Step 2 for channel: dropdown of saved channels.
	 */
	function renderChannelPicker( props ) {
		if ( ! channels.length ) {
			return el(
				'p',
				{},
				__( 'No channels saved yet. ', 'ytep' ),
				settingsUrl
					? el(
						'a',
						{ href: settingsUrl, target: '_blank', rel: 'noopener noreferrer' },
						__( 'Add one under Settings → YouTube Embed Pro.', 'ytep' )
					)
					: __( 'Add one under Settings → YouTube Embed Pro.', 'ytep' )
			);
		}

		var options = [ { label: __( 'Choose a channel…', 'ytep' ), value: '' } ].concat(
			channels.map( function ( channel ) {
				return { label: channel.label, value: channel.id };
			} )
		);

		return el( SelectControl, {
			label: __( 'Channel', 'ytep' ),
			value: props.attributes.channel,
			options: options,
			onChange: set( props, 'channel' )
		} );
	}

	/**
	 * Step 2 for everything else: a URL field.
	 */
	function renderUrlInput( props ) {
		var kind = props.attributes.kind;
		return el( TextControl, {
			label: __( 'YouTube URL or ID', 'ytep' ),
			value: props.attributes.url,
			placeholder: URL_PLACEHOLDERS[ kind ] || URL_PLACEHOLDERS.auto,
			autoFocus: true,
			onChange: set( props, 'url' )
		} );
	}

	wp.blocks.registerBlockType( 'ytep/embed', {
		edit: function ( props ) {
			var a = props.attributes;
			var blockProps = useBlockProps();

			// hasSource: the block can render something on the server.
			var hasSource = a.channel ? true : !! a.url;

			// picked is local UI state, not saved with the post: it only
			// remembers that a chooser button was pressed in this session,
			// so re-opening an existing block skips straight to the preview.
			var pickedState = useState( hasSource );
			var picked = pickedState[ 0 ];
			var setPicked = pickedState[ 1 ];

			var isChannel = 'channel' === a.kind;

			var sourcePanel = el(
				PanelBody,
				{ title: __( 'Source', 'ytep' ), initialOpen: true },
				el( SelectControl, {
					label: __( 'Embed type', 'ytep' ),
					value: a.kind,
					help: __( 'Auto-detect reads the type from the link.', 'ytep' ),
					options: [
						{ label: __( 'Auto-detect', 'ytep' ), value: 'auto' },
						{ label: __( 'Video', 'ytep' ), value: 'video' },
						{ label: __( 'Short', 'ytep' ), value: 'short' },
						{ label: __( 'Playlist', 'ytep' ), value: 'playlist' },
						{ label: __( 'Live stream', 'ytep' ), value: 'live' },
						{ label: __( 'Channel uploads', 'ytep' ), value: 'channel' }
					],
					onChange: set( props, 'kind' )
				} ),
				isChannel
					? renderChannelPicker( props )
					: el( TextControl, {
						label: __( 'YouTube URL or ID', 'ytep' ),
						value: a.url,
						placeholder: URL_PLACEHOLDERS[ a.kind ] || URL_PLACEHOLDERS.auto,
						onChange: set( props, 'url' )
					} ),
				el( TextControl, {
					label: __( 'Title for screen readers', 'ytep' ),
					value: a.title,
					onChange: set( props, 'title' )
				} )
			);

			var layoutPanel = el(
				PanelBody,
				{ title: __( 'Layout', 'ytep' ), initialOpen: false },
				el( SelectControl, {
					label: __( 'Aspect ratio', 'ytep' ),
					value: a.ratio,
					options: [
						{ label: __( 'Match the embed type', 'ytep' ), value: '' },
						{ label: '16:9', value: '16:9' },
						{ label: '9:16', value: '9:16' },
						{ label: '4:3', value: '4:3' },
						{ label: '3:2', value: '3:2' },
						{ label: '1:1', value: '1:1' },
						{ label: '21:9', value: '21:9' }
					],
					onChange: set( props, 'ratio' )
				} ),
				el( RangeControl, {
					label: __( 'Maximum width (px)', 'ytep' ),
					value: a.maxWidth,
					min: 0,
					max: 1600,
					step: 20,
					help: __( '0 fills the available width.', 'ytep' ),
					onChange: function ( value ) {
						props.setAttributes( { maxWidth: value ? parseInt( value, 10 ) : 0 } );
					}
				} )
			);

			var playbackPanel = el(
				PanelBody,
				{ title: __( 'Playback and privacy', 'ytep' ), initialOpen: false },
				el( RangeControl, {
					label: __( 'Start at (seconds)', 'ytep' ),
					value: a.start,
					min: 0,
					max: 3600,
					onChange: function ( value ) {
						props.setAttributes( { start: value ? parseInt( value, 10 ) : 0 } );
					}
				} ),
				el( ToggleControl, {
					label: __( 'Use youtube-nocookie.com', 'ytep' ),
					help: __( 'Stops YouTube setting tracking cookies until playback starts.', 'ytep' ),
					checked: !! a.privacy,
					onChange: set( props, 'privacy' )
				} ),
				el( ToggleControl, {
					label: __( 'Load on click', 'ytep' ),
					help: __( 'Shows the thumbnail first and loads the player when a visitor clicks.', 'ytep' ),
					checked: !! a.facade,
					onChange: set( props, 'facade' )
				} ),
				el( ToggleControl, {
					label: __( 'Autoplay (muted)', 'ytep' ),
					checked: !! a.autoplay,
					onChange: set( props, 'autoplay' )
				} ),
				el( ToggleControl, {
					label: __( 'Loop', 'ytep' ),
					checked: !! a.loop,
					onChange: set( props, 'loop' )
				} ),
				el( ToggleControl, {
					label: __( 'Show player controls', 'ytep' ),
					checked: !! a.controls,
					onChange: set( props, 'controls' )
				} ),
				el( ToggleControl, {
					label: __( 'Turn captions on', 'ytep' ),
					checked: !! a.captions,
					onChange: set( props, 'captions' )
				} )
			);

			var content;

			if ( hasSource ) {
				content = el(
					Disabled,
					{},
					el( ServerSideRender, {
						block: 'ytep/embed',
						attributes: a
					} )
				);
			} else if ( ! picked ) {
				// Step 1: ask what to embed.
				content = el(
					Placeholder,
					{
						icon: 'video-alt3',
						label: __( 'YouTube Embed Pro', 'ytep' ),
						instructions: __( 'What do you want to embed?', 'ytep' )
					},
					renderChooser( function ( kind ) {
						props.setAttributes( { kind: kind } );
						setPicked( true );
					} )
				);
			} else {
				// Step 2: collect the source for the chosen type.
				var choice = KIND_CHOICES.filter( function ( c ) {
					return c.value === a.kind;
				} )[ 0 ];

				content = el(
					Placeholder,
					{
						icon: 'video-alt3',
						label: choice ? choice.label : __( 'YouTube Embed Pro', 'ytep' ),
						instructions: isChannel
							? __( 'Pick one of your saved channels.', 'ytep' )
							: __( 'Paste the link below.', 'ytep' )
					},
					el(
						'div',
						{ style: { width: '100%' } },
						isChannel ? renderChannelPicker( props ) : renderUrlInput( props ),
						el(
							Button,
							{
								variant: 'link',
								onClick: function () {
									setPicked( false );
								}
							},
							__( '← Choose a different type', 'ytep' )
						)
					)
				);
			}

			return el(
				Fragment,
				{},
				el( InspectorControls, {}, sourcePanel, layoutPanel, playbackPanel ),
				el( 'div', blockProps, content )
			);
		},

		// Dynamic block: the front end is rendered by PHP, so nothing is saved.
		save: function () {
			return null;
		}
	} );
} )( window.wp );
