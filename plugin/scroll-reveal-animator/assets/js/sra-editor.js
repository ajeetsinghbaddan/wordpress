(function (wp) {
	'use strict';

	var addFilter = wp.hooks.addFilter;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var Fragment = wp.element.Fragment;
	var el = wp.element.createElement;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var __ = wp.i18n.__;

	var animations = Array.isArray(window.SRA_ANIMATIONS) ? window.SRA_ANIMATIONS : [];

	var animationOptions = [{ label: __('None', 'scroll-reveal-animator'), value: '' }].concat(
		animations.map(function (name) {
			return { label: name, value: name };
		})
	);

	function isValidAnimation(value) {
		return animations.indexOf(value) !== -1;
	}

	addFilter('blocks.registerBlockType', 'sra/attributes', function (settings) {
		if (!settings.attributes) {
			settings.attributes = {};
		}
		settings.attributes.sraAnimation = { type: 'string', default: '' };
		settings.attributes.sraDelay = { type: 'number', default: 0 };
		return settings;
	});

	var withSraControls = createHigherOrderComponent(function (BlockEdit) {
		return function (props) {
			return el(
				Fragment,
				null,
				el(BlockEdit, props),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Scroll Reveal', 'scroll-reveal-animator'), initialOpen: false },
						el(SelectControl, {
							label: __('Animation', 'scroll-reveal-animator'),
							value: props.attributes.sraAnimation || '',
							options: animationOptions,
							onChange: function (value) {
								props.setAttributes({
									sraAnimation: isValidAnimation(value) ? value : ''
								});
							}
						}),
						props.attributes.sraAnimation
							? el(RangeControl, {
									label: __('Delay (ms)', 'scroll-reveal-animator'),
									value: props.attributes.sraDelay || 0,
									min: 0,
									max: 1000,
									step: 100,
									onChange: function (value) {
										props.setAttributes({ sraDelay: value || 0 });
									}
								})
							: null
					)
				)
			);
		};
	}, 'withSraControls');

	addFilter('editor.BlockEdit', 'sra/controls', withSraControls);

	addFilter('blocks.getSaveContent.extraProps', 'sra/save-props', function (extraProps, blockType, attributes) {
		if (attributes.sraAnimation && isValidAnimation(attributes.sraAnimation)) {
			var classes = extraProps.className ? extraProps.className.split(' ') : [];
			classes.push('sra-' + attributes.sraAnimation);
			extraProps.className = classes.join(' ').trim();

			var delay = parseInt(attributes.sraDelay, 10) || 0;
			if (delay > 0 && delay <= 3000) {
				extraProps['data-sra-delay'] = String(delay);
			}
		}
		return extraProps;
	});
})(window.wp);
