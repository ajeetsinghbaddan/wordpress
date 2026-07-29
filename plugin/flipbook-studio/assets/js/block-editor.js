/**
 * Flipbook block - editor side.
 *
 * Written against wp.element directly (createElement instead of JSX) so the
 * plugin ships with no build step: what you read is what the browser runs.
 *
 * The editor never mounts the real reader. Loading PDF.js and a PDF inside
 * the editing canvas would make typing sluggish and burn a signed URL for
 * nothing, so the canvas shows a light preview card and the real book renders
 * on the front end, where it matters.
 */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var apiFetch = wp.apiFetch;

	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var NumberControl = wp.components.__experimentalNumberControl || wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var Placeholder = wp.components.Placeholder;
	var Spinner = wp.components.Spinner;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;

	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;

	/**
	 * The book icon shown in the inserter and the placeholder.
	 */
	var icon = el('svg', { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.6 },
		el('path', { d: 'M4 5a2 2 0 0 1 2-2h5v18H6a2 2 0 0 1-2-2V5z' }),
		el('path', { d: 'M20 5a2 2 0 0 0-2-2h-5v18h5a2 2 0 0 0 2-2V5z' }),
		el('path', { d: 'M11 3c-1.2 1.2-1.2 16.8 0 18' })
	);

	/**
	 * Fetches the flipbook list once per block instance.
	 *
	 * useEffect with an empty dependency array is the hook equivalent of
	 * "run this once after the first render". The empty-catch keeps a REST
	 * hiccup from crashing the whole editor; the UI just shows the error state.
	 */
	function useBooks() {
		var state = useState({ books: null, error: false });
		var value = state[0];
		var setValue = state[1];

		useEffect(function () {
			var alive = true;

			apiFetch({ path: '/flipbook/v1/list' })
				.then(function (books) {
					if (alive) {
						setValue({ books: books, error: false });
					}
				})
				.catch(function () {
					if (alive) {
						setValue({ books: [], error: true });
					}
				});

			// The cleanup function runs if the block is removed mid-request,
			// so a late response never writes state into a dead component.
			return function () {
				alive = false;
			};
		}, []);

		return value;
	}

	function findBook(books, id) {
		if (!books) {
			return null;
		}
		for (var i = 0; i < books.length; i++) {
			if (books[i].id === id) {
				return books[i];
			}
		}
		return null;
	}

	/**
	 * The preview card shown in the canvas once a book is chosen.
	 */
	function PreviewCard(props) {
		var book = props.book;
		var attrs = props.attributes;

		var themeLabel = {
			'': __('Book default', 'flipbook-studio'),
			ink: __('Ink', 'flipbook-studio'),
			paper: __('Paper', 'flipbook-studio'),
			slate: __('Slate', 'flipbook-studio')
		}[attrs.theme || ''];

		return el('div', { className: 'fbs-block-card fbs-block-card--' + (attrs.theme || 'ink') },
			el('div', { className: 'fbs-block-card__book', 'aria-hidden': 'true' },
				el('span', { className: 'fbs-block-card__spine' }),
				el('span', { className: 'fbs-block-card__page' }),
				el('span', { className: 'fbs-block-card__page fbs-block-card__page--turn' })
			),
			el('div', { className: 'fbs-block-card__meta' },
				el('strong', null, book ? book.title : __('Flipbook', 'flipbook-studio')),
				el('span', null,
					themeLabel
					+ ' · '
					+ (attrs.height ? attrs.height + 'px' : __('Auto height', 'flipbook-studio'))
					+ (attrs.toolbar ? '' : ' · ' + __('No toolbar', 'flipbook-studio'))
				),
				book && !book.hasFile
					? el('span', { className: 'fbs-block-card__warn' },
						__('No PDF uploaded yet — this will render nothing for visitors.', 'flipbook-studio'))
					: null,
				book && book.status !== 'publish'
					? el('span', { className: 'fbs-block-card__warn' },
						__('Not published yet — visible only to editors.', 'flipbook-studio'))
					: null
			),
			book && book.edit
				? el(Button, {
					variant: 'secondary',
					size: 'small',
					href: book.edit,
					target: '_blank'
				}, __('Edit flipbook', 'flipbook-studio'))
				: null
		);
	}

	registerBlockType('flipbook-studio/flipbook', {
		title: __('Flipbook', 'flipbook-studio'),
		icon: icon,
		category: 'media',

		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var data = useBooks();
			var blockProps = useBlockProps({ className: 'fbs-block-edit' });

			var options = [{ value: 0, label: __('Choose a flipbook…', 'flipbook-studio') }];

			if (data.books) {
				data.books.forEach(function (book) {
					options.push({
						value: book.id,
						label: book.title + (book.hasFile ? '' : ' — ' + __('no PDF', 'flipbook-studio'))
					});
				});
			}

			var inspector = el(InspectorControls, null,
				el(PanelBody, { title: __('Flipbook', 'flipbook-studio'), initialOpen: true },
					el(SelectControl, {
						label: __('Which flipbook', 'flipbook-studio'),
						value: attributes.bookId,
						options: options,
						onChange: function (value) {
							setAttributes({ bookId: parseInt(value, 10) || 0 });
						}
					})
				),
				el(PanelBody, { title: __('Display', 'flipbook-studio'), initialOpen: false },
					el(RangeControl, {
						label: __('Height (px)', 'flipbook-studio'),
						help: __('0 uses the height saved on the flipbook itself. On small screens the reader caps itself to the viewport regardless.', 'flipbook-studio'),
						value: attributes.height,
						min: 0,
						max: 1600,
						step: 10,
						allowReset: true,
						onChange: function (value) {
							setAttributes({ height: value || 0 });
						}
					}),
					el(SelectControl, {
						label: __('Theme', 'flipbook-studio'),
						value: attributes.theme,
						options: [
							{ value: '', label: __('Book default', 'flipbook-studio') },
							{ value: 'ink', label: __('Ink', 'flipbook-studio') },
							{ value: 'paper', label: __('Paper', 'flipbook-studio') },
							{ value: 'slate', label: __('Slate', 'flipbook-studio') }
						],
						onChange: function (value) {
							setAttributes({ theme: value });
						}
					}),
					el(NumberControl, {
						label: __('Open on page', 'flipbook-studio'),
						value: attributes.page || '',
						min: 0,
						onChange: function (value) {
							setAttributes({ page: parseInt(value, 10) || 0 });
						}
					}),
					el(ToggleControl, {
						label: __('Show the toolbar', 'flipbook-studio'),
						checked: attributes.toolbar,
						onChange: function (value) {
							setAttributes({ toolbar: value });
						}
					})
				)
			);

			// Three canvas states: loading, nothing chosen, chosen.
			var canvas;

			if (data.books === null) {
				canvas = el(Placeholder, { icon: icon, label: __('Flipbook', 'flipbook-studio') },
					el(Spinner, null));
			} else if (!attributes.bookId) {
				canvas = el(Placeholder, {
					icon: icon,
					label: __('Flipbook', 'flipbook-studio'),
					instructions: data.books.length
						? __('Pick which flipbook to show here.', 'flipbook-studio')
						: __('No flipbooks yet. Create one under Flipbooks in the admin menu, then come back and pick it.', 'flipbook-studio')
				},
					data.error
						? el(Notice, { status: 'error', isDismissible: false },
							__('The flipbook list could not be loaded.', 'flipbook-studio'))
						: el(SelectControl, {
							value: attributes.bookId,
							options: options,
							onChange: function (value) {
								setAttributes({ bookId: parseInt(value, 10) || 0 });
							}
						})
				);
			} else {
				canvas = el(PreviewCard, {
					book: findBook(data.books, attributes.bookId),
					attributes: attributes
				});
			}

			return el('div', blockProps, inspector, canvas);
		},

		// A dynamic block saves nothing: the server owns the markup. Returning
		// null here is what tells WordPress to call the PHP render_callback.
		save: function () {
			return null;
		}
	});
})(window.wp);
