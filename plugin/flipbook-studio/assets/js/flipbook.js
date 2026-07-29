/**
 * Flipbook Studio reader.
 *
 * Three libraries meet here:
 *   PDF.js      turns PDF pages into <canvas> bitmaps
 *   StPageFlip  animates those canvases as a book
 *   this file   owns the state, the UI and the security handshake between them
 *
 * Nothing is loaded until the reader is on screen, and nothing is rendered
 * until a page is about to be seen.
 */
(function () {
	'use strict';

	var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/**
	 * A per-tab identifier used to group page reads into one reading session.
	 * sessionStorage is deliberate: it dies with the tab, and unlike a cookie
	 * it is never attached to unrelated requests.
	 */
	function sessionId() {
		try {
			var id = window.sessionStorage.getItem('fbsSession');
			if (!id) {
				id = Math.random().toString(36).slice(2) + Date.now().toString(36);
				window.sessionStorage.setItem('fbsSession', id);
			}
			return id;
		} catch (e) {
			return 'nostore';
		}
	}

	function clamp(value, min, max) {
		return Math.min(max, Math.max(min, value));
	}

	function Reader(root) {
		this.root = root;
		this.cfg = JSON.parse(root.getAttribute('data-fbs'));
		this.t = this.cfg.i18n;

		this.pdf = null;
		this.task = null;
		this.flip = null;
		this.pages = [];
		this.rendered = {};
		this.total = 0;
		this.shown = 0;
		this.current = 1;
		this.zoom = 1;
		this.pan = { x: 0, y: 0 };
		this.quality = 1;
		this.soundOn = !!this.cfg.sound;
		this.textIndex = null;
		this.unlock = '';
		this.hovered = false;

		this.el = {
			stage: root.querySelector('.fbs-stage'),
			book: root.querySelector('[data-fbs-book]'),
			status: root.querySelector('[data-fbs-status]'),
			statusText: root.querySelector('[data-fbs-status-text]'),
			bar: root.querySelector('[data-fbs-bar]'),
			pageInput: root.querySelector('[data-fbs-page]'),
			total: root.querySelector('[data-fbs-total]'),
			hint: root.querySelector('[data-fbs-hint]'),
			thumbs: root.querySelector('[data-fbs-thumbs]'),
			outline: root.querySelector('[data-fbs-outline]'),
			results: root.querySelector('[data-fbs-results]'),
			query: root.querySelector('[data-fbs-query]'),
			gate: root.querySelector('[data-fbs-gate]'),
			gateError: root.querySelector('[data-fbs-gate-error]'),
			previewGate: root.querySelector('[data-fbs-preview-gate]')
		};

		this.bindControls();

		if (this.cfg.locked) {
			this.bindGate();
		} else {
			this.open(this.cfg.file);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Loading                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Opens a PDF from a signed URL and builds the book.
	 *
	 * The PDF.js options are the security-relevant part:
	 *   isEvalSupported: false   never eval() font programs
	 *   enableScripting: false   ignore JavaScript embedded in the PDF
	 *   enableXfa: false         ignore XFA forms, a large extra attack surface
	 *   disableAutoFetch: true   fetch byte ranges on demand instead of the
	 *                            whole file, which is what makes a 200 MB
	 *                            catalogue open in a second
	 */
	Reader.prototype.open = function (url) {
		var self = this;

		if (typeof window.pdfjsLib === 'undefined' || typeof window.St === 'undefined') {
			this.fail(this.t.failed);
			return;
		}

		window.pdfjsLib.GlobalWorkerOptions.workerSrc = this.cfg.worker;

		this.task = window.pdfjsLib.getDocument({
			url: url,
			withCredentials: true,
			// cMapUrl points at the bundled character maps. Without them a PDF
			// using standard (non-embedded) CJK fonts renders as blank glyphs.
			cMapUrl: this.cfg.cmaps,
			cMapPacked: true,
			isEvalSupported: false,
			enableScripting: false,
			enableXfa: false,
			disableAutoFetch: true,
			disableStream: false
		});

		this.task.onProgress = function (data) {
			if (data.total) {
				self.progress(data.loaded / data.total);
			}
		};

		this.task.promise.then(function (pdf) {
			self.pdf = pdf;
			self.total = pdf.numPages;
			self.shown = self.cfg.previewPages > 0
				? Math.min(self.total, self.cfg.previewPages)
				: self.total;

			self.setStatus(self.t.rendering);
			return self.build();
		}).then(function () {
			self.hideStatus();
			self.scheduleTokenRefresh();
			self.loadOutline();
			self.buildThumbs();
			self.announce(self.t.shortcuts);
		}).catch(function (err) {
			self.fail(self.t.failed, err);
		});
	};

	/**
	 * Creates one placeholder element per page and hands them to StPageFlip.
	 *
	 * Placeholders go in up front because the flip engine needs to know how
	 * many leaves the book has before it can animate. The pixels arrive later.
	 */
	Reader.prototype.build = function () {
		var self = this;

		return this.pdf.getPage(1).then(function (page) {
			var view = page.getViewport({ scale: 1 });
			self.ratio = view.height / view.width;

			var size = self.fitSize();
			self.el.book.style.width = size.width + 'px';
			self.el.book.style.height = size.height + 'px';
			self.spread = size.spread;

			for (var i = 1; i <= self.shown; i++) {
				var el = document.createElement('div');
				el.className = 'fbs-page';
				el.setAttribute('data-page', i);
				// A "hard" leaf is a stiff cover: it swings as one board rather
				// than curling, which is what a real cover does.
				if (i === 1 || i === self.shown) {
					el.setAttribute('data-density', 'hard');
				}
				el.innerHTML = '<div class="fbs-page-skeleton"></div>';
				self.el.book.appendChild(el);
				self.pages.push(el);
			}

			// In stretch mode the library derives page size from the container
			// and switches to one-page view when the container is narrower than
			// minWidth * 2. That threshold is therefore the single-page
			// breakpoint, and a huge value is how "always one page" is expressed.
			self.flip = new window.St.PageFlip(self.el.book, {
				width: size.pageWidth,
				height: size.height,
				size: 'stretch',
				minWidth: self.cfg.singlePage ? 99999 : 340,
				maxWidth: 2000,
				minHeight: 120,
				maxHeight: 2400,
				showCover: true,
				usePortrait: true,
				// autoSize would overwrite the width we just measured.
				autoSize: false,
				maxShadowOpacity: 0.45,
				drawShadow: true,
				flippingTime: REDUCED ? 1 : 720,
				mobileScrollSupport: true,
				swipeDistance: 24,
				useMouseEvents: true
			});

			self.flip.loadFromHTML(self.el.book.querySelectorAll('.fbs-page'));

			// The library writes its own min-width onto the container during
			// setup. Clearing it in the same tick — before the browser paints —
			// keeps a narrow phone from getting a horizontally scrolling book.
			self.el.book.style.minWidth = '0px';
			self.el.book.style.minHeight = '0px';
			self.flip.update();

			self.flip.on('flip', function (e) {
				self.onFlip(e.data + 1);
			});

			self.el.total.textContent = self.cfg.previewPages > 0 && self.shown < self.total
				? self.shown + '*'
				: self.total;

			var start = self.hashPage() || self.cfg.startPage || 1;
			self.goTo(start, true);

			return self.renderWindow(start);
		});
	};

	/**
	 * Works out how large the book can be inside the stage.
	 *
	 * Two constraints fight each other: the stage height and the stage width
	 * once the spread is doubled. Whichever runs out first decides the size.
	 */
	Reader.prototype.fitSize = function () {
		var stage = this.el.stage.getBoundingClientRect();

		var maxWidth = Math.max(160, stage.width - 48);
		var maxHeight = Math.max(160, stage.height - 32);

		// 680 = the 340 minWidth handed to the flip library, doubled. Both
		// sides of the calculation must agree or the pages end up cropped.
		var spread = (this.cfg.singlePage || maxWidth < 680) ? 1 : 2;

		var pageWidth = Math.min(maxWidth / spread, maxHeight / this.ratio);
		var height = pageWidth * this.ratio;

		return {
			spread: spread,
			pageWidth: Math.floor(pageWidth),
			width: Math.floor(pageWidth * spread),
			height: Math.floor(height)
		};
	};

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Renders a page onto a canvas sized for this device.
	 *
	 * Multiplying by devicePixelRatio is what stops text looking soft on a
	 * retina screen; capping that multiplier at 2 is what stops a phone from
	 * allocating a 40-megapixel canvas and running out of memory.
	 */
	Reader.prototype.renderPage = function (number) {
		var self = this;

		if (number < 1 || number > this.shown) {
			return Promise.resolve();
		}

		var wanted = this.quality;
		if (this.rendered[number] >= wanted) {
			return Promise.resolve();
		}
		this.rendered[number] = wanted;

		return this.pdf.getPage(number).then(function (page) {
			var el = self.pages[number - 1];
			var css = el.clientWidth || self.fitSize().pageWidth;
			var dpr = clamp(window.devicePixelRatio || 1, 1, 2);
			var base = page.getViewport({ scale: 1 });
			var scale = (css * dpr * wanted) / base.width;
			var viewport = page.getViewport({ scale: scale });

			var canvas = document.createElement('canvas');
			canvas.width = Math.floor(viewport.width);
			canvas.height = Math.floor(viewport.height);

			return page.render({
				canvasContext: canvas.getContext('2d', { alpha: false }),
				viewport: viewport
			}).promise.then(function () {
				el.innerHTML = '';
				el.appendChild(canvas);
				el.classList.add('fbs-page--' + (number % 2 === 0 ? 'left' : 'right'));
			});
		}).catch(function (err) {
			delete self.rendered[number];
			self.recover(err);
		});
	};

	/**
	 * Renders the pages around a position.
	 *
	 * A window of two spreads either side keeps a fast flipper from ever
	 * seeing a blank sheet, without rendering a 300-page document up front.
	 */
	Reader.prototype.renderWindow = function (centre) {
		var jobs = [];
		for (var i = centre - 3; i <= centre + 4; i++) {
			jobs.push(this.renderPage(i));
		}
		return Promise.all(jobs);
	};

	/* ------------------------------------------------------------------ */
	/* Navigation                                                         */
	/* ------------------------------------------------------------------ */

	Reader.prototype.onFlip = function (number) {
		this.current = number;
		this.el.pageInput.value = number;
		this.renderWindow(number);
		this.markThumb(number);
		this.updateHash(number);
		this.playSound();
		this.track(number);

		if (this.cfg.previewPages > 0 && this.total > this.shown && number >= this.shown) {
			this.showPreviewGate();
		}
	};

	Reader.prototype.goTo = function (number, immediate) {
		if (!this.flip) {
			return;
		}
		number = clamp(parseInt(number, 10) || 1, 1, this.shown);
		this.renderWindow(number);

		if (immediate || REDUCED) {
			this.flip.turnToPage(number - 1);
		} else {
			this.flip.flip(number - 1);
		}
		this.current = number;
		this.el.pageInput.value = number;
	};

	Reader.prototype.next = function () {
		if (this.flip) {
			this.flip.flipNext();
		}
	};

	Reader.prototype.prev = function () {
		if (this.flip) {
			this.flip.flipPrev();
		}
	};

	/* ------------------------------------------------------------------ */
	/* Zoom and pan                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Applies zoom as a CSS transform and re-renders at a matching resolution.
	 *
	 * Scaling a canvas up with CSS alone gives a blurry page, so past 1.4x the
	 * page is re-rasterised at higher quality. Below that the visual gain is
	 * not worth the render cost.
	 */
	Reader.prototype.setZoom = function (value) {
		this.zoom = clamp(value, 1, 3);

		if (this.zoom === 1) {
			this.pan = { x: 0, y: 0 };
		}

		this.root.classList.toggle('is-zoomed', this.zoom > 1);
		this.el.book.style.transform =
			'scale(' + this.zoom + ') translate(' + this.pan.x + 'px,' + this.pan.y + 'px)';

		var wanted = this.zoom > 1.4 ? 2 : 1;
		if (wanted !== this.quality) {
			this.quality = wanted;
			this.renderWindow(this.current);
		}
	};

	Reader.prototype.bindPan = function () {
		var self = this;
		var dragging = false;
		var origin = null;

		this.el.stage.addEventListener('pointerdown', function (e) {
			if (self.zoom <= 1) {
				return;
			}
			dragging = true;
			origin = { x: e.clientX, y: e.clientY, px: self.pan.x, py: self.pan.y };
			self.root.classList.add('is-panning');
		});

		window.addEventListener('pointermove', function (e) {
			if (!dragging) {
				return;
			}
			self.pan.x = origin.px + (e.clientX - origin.x) / self.zoom;
			self.pan.y = origin.py + (e.clientY - origin.y) / self.zoom;
			self.setZoom(self.zoom);
		});

		window.addEventListener('pointerup', function () {
			dragging = false;
			self.root.classList.remove('is-panning');
		});
	};

	/* ------------------------------------------------------------------ */
	/* Panels: thumbnails, outline, search                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Builds the thumbnail strip.
	 *
	 * The thumbnails are drawn only when they scroll into view. On a 400-page
	 * book that is the difference between a responsive panel and a frozen tab.
	 */
	Reader.prototype.buildThumbs = function () {
		var self = this;

		if (!this.el.thumbs) {
			return;
		}

		var observer = 'IntersectionObserver' in window
			? new IntersectionObserver(function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						self.drawThumb(entry.target);
						observer.unobserve(entry.target);
					}
				});
			}, { root: this.el.thumbs, rootMargin: '150px' })
			: null;

		for (var i = 1; i <= this.shown; i++) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'fbs-thumb';
			button.setAttribute('data-thumb', i);
			button.setAttribute('aria-label', this.t.page + ' ' + i);
			button.innerHTML = '<span>' + i + '</span>';
			this.el.thumbs.appendChild(button);

			if (observer) {
				observer.observe(button);
			} else {
				this.drawThumb(button);
			}
		}

		this.el.thumbs.addEventListener('click', function (e) {
			var button = e.target.closest('[data-thumb]');
			if (button) {
				self.goTo(button.getAttribute('data-thumb'));
				self.closePanels();
			}
		});
	};

	Reader.prototype.drawThumb = function (button) {
		var self = this;
		var number = parseInt(button.getAttribute('data-thumb'), 10);

		this.pdf.getPage(number).then(function (page) {
			var base = page.getViewport({ scale: 1 });
			var viewport = page.getViewport({ scale: 180 / base.width });
			var canvas = document.createElement('canvas');
			canvas.width = viewport.width;
			canvas.height = viewport.height;

			return page.render({
				canvasContext: canvas.getContext('2d', { alpha: false }),
				viewport: viewport
			}).promise.then(function () {
				button.insertBefore(canvas, button.firstChild);
			});
		}).catch(function () { /* a missing thumbnail is not worth an error */ });
	};

	Reader.prototype.markThumb = function (number) {
		if (!this.el.thumbs) {
			return;
		}
		var previous = this.el.thumbs.querySelector('.is-current');
		if (previous) {
			previous.classList.remove('is-current');
		}
		var current = this.el.thumbs.querySelector('[data-thumb="' + number + '"]');
		if (current) {
			current.classList.add('is-current');
			current.scrollIntoView({ block: 'nearest' });
		}
	};

	/**
	 * Reads the PDF's own bookmark tree and turns it into a contents list.
	 *
	 * Bookmarks point at internal destinations, not page numbers, so each one
	 * has to be resolved through the document before it can be jumped to.
	 */
	Reader.prototype.loadOutline = function () {
		var self = this;

		if (!this.el.outline) {
			return;
		}

		this.pdf.getOutline().then(function (items) {
			if (!items || !items.length) {
				self.el.outline.innerHTML = '<p class="fbs-empty">' + self.t.noResults + '</p>';
				return;
			}

			var walk = function (nodes, depth) {
				nodes.forEach(function (node) {
					var link = document.createElement('a');
					link.href = '#';
					link.className = 'fbs-depth-' + depth;
					link.textContent = node.title;
					link.addEventListener('click', function (e) {
						e.preventDefault();
						self.jumpToDest(node.dest);
					});
					self.el.outline.appendChild(link);

					if (node.items && node.items.length) {
						walk(node.items, depth + 1);
					}
				});
			};

			walk(items, 0);
		}).catch(function () { /* outlines are optional */ });
	};

	Reader.prototype.jumpToDest = function (dest) {
		var self = this;
		var resolve = typeof dest === 'string' ? this.pdf.getDestination(dest) : Promise.resolve(dest);

		resolve.then(function (array) {
			return array ? self.pdf.getPageIndex(array[0]) : null;
		}).then(function (index) {
			if (index !== null) {
				self.goTo(index + 1);
				self.closePanels();
			}
		}).catch(function () { /* broken bookmark, ignore */ });
	};

	/**
	 * Extracts the text layer of every page once, then searches it in memory.
	 *
	 * Building the index is the slow part, so it happens on the first search
	 * and is kept for the rest of the session.
	 */
	Reader.prototype.buildTextIndex = function () {
		var self = this;

		if (this.textIndex) {
			return Promise.resolve(this.textIndex);
		}

		this.announce(this.t.searching + '…');
		var jobs = [];

		for (var i = 1; i <= this.shown; i++) {
			jobs.push((function (number) {
				return self.pdf.getPage(number).then(function (page) {
					return page.getTextContent();
				}).then(function (content) {
					return {
						page: number,
						text: content.items.map(function (item) { return item.str; }).join(' ')
					};
				});
			})(i));
		}

		return Promise.all(jobs).then(function (pages) {
			self.textIndex = pages;
			self.announce('');
			return pages;
		});
	};

	Reader.prototype.search = function (term) {
		var self = this;
		term = term.trim();

		if (term.length < 2) {
			this.el.results.innerHTML = '';
			return;
		}

		this.buildTextIndex().then(function (pages) {
			var needle = term.toLowerCase();
			var hits = [];

			pages.forEach(function (entry) {
				var at = entry.text.toLowerCase().indexOf(needle);
				if (at === -1) {
					return;
				}
				var from = Math.max(0, at - 40);
				hits.push({
					page: entry.page,
					before: entry.text.slice(from, at),
					match: entry.text.slice(at, at + term.length),
					after: entry.text.slice(at + term.length, at + term.length + 50)
				});
			});

			if (!hits.length) {
				self.el.results.innerHTML = '<p class="fbs-empty">' + self.t.noResults + '</p>';
				return;
			}

			self.el.results.innerHTML = '';
			hits.forEach(function (hit) {
				var button = document.createElement('button');
				button.type = 'button';
				button.className = 'fbs-result';
				// textContent everywhere: PDF text is untrusted content and must
				// never be written into the page as markup.
				var label = document.createElement('em');
				label.textContent = self.t.page + ' ' + hit.page;
				var line = document.createElement('span');
				line.appendChild(document.createTextNode('…' + hit.before));
				var strong = document.createElement('b');
				strong.textContent = hit.match;
				line.appendChild(strong);
				line.appendChild(document.createTextNode(hit.after + '…'));
				button.appendChild(label);
				button.appendChild(line);
				button.addEventListener('click', function () {
					self.goTo(hit.page);
					self.closePanels();
				});
				self.el.results.appendChild(button);
			});
		});
	};

	Reader.prototype.closePanels = function () {
		this.root.querySelectorAll('[data-fbs-panel]').forEach(function (panel) {
			panel.classList.remove('is-open');
			panel.hidden = true;
		});
		this.root.querySelectorAll('[data-fbs-toggle]').forEach(function (button) {
			button.setAttribute('aria-pressed', 'false');
		});
	};

	Reader.prototype.togglePanel = function (name) {
		var panel = this.root.querySelector('[data-fbs-panel="' + name + '"]');
		var button = this.root.querySelector('[data-fbs-toggle="' + name + '"]');

		if (!panel) {
			return;
		}

		var open = panel.classList.contains('is-open');
		this.closePanels();

		if (!open) {
			panel.hidden = false;
			// The class is applied a frame later so the slide-in transition has
			// a starting position to animate from.
			window.requestAnimationFrame(function () {
				panel.classList.add('is-open');
			});
			if (button) {
				button.setAttribute('aria-pressed', 'true');
			}
			if (name === 'search' && this.el.query) {
				this.el.query.focus();
			}
		}
	};

	/* ------------------------------------------------------------------ */
	/* Sound                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Synthesises a page-turn instead of shipping an audio file.
	 *
	 * A short burst of noise pushed through a sweeping band-pass filter reads
	 * as paper. It costs no download and no extra HTTP request. The audio
	 * context is created on first use because browsers block it before a gesture.
	 */
	Reader.prototype.playSound = function () {
		if (!this.soundOn || REDUCED) {
			return;
		}

		try {
			if (!this.audio) {
				var Ctx = window.AudioContext || window.webkitAudioContext;
				if (!Ctx) {
					return;
				}
				this.audio = new Ctx();
			}

			var ctx = this.audio;
			var duration = 0.22;
			var buffer = ctx.createBuffer(1, ctx.sampleRate * duration, ctx.sampleRate);
			var data = buffer.getChannelData(0);

			for (var i = 0; i < data.length; i++) {
				var fade = 1 - i / data.length;
				data[i] = (Math.random() * 2 - 1) * fade * fade * 0.5;
			}

			var source = ctx.createBufferSource();
			source.buffer = buffer;

			var filter = ctx.createBiquadFilter();
			filter.type = 'bandpass';
			filter.frequency.setValueAtTime(1600, ctx.currentTime);
			filter.frequency.exponentialRampToValueAtTime(600, ctx.currentTime + duration);
			filter.Q.value = 0.9;

			var gain = ctx.createGain();
			gain.gain.setValueAtTime(0.18, ctx.currentTime);
			gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration);

			source.connect(filter).connect(gain).connect(ctx.destination);
			source.start();
		} catch (e) { /* audio is a nicety, never an error */ }
	};

	/* ------------------------------------------------------------------ */
	/* Security handshake                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Swaps in a fresh signed URL before the current one expires.
	 *
	 * Signed links are short-lived on purpose, but a reader may sit with the
	 * book open for an hour. Re-opening the document behind the scenes at 85%
	 * of the lifetime keeps long sessions working without ever issuing a
	 * long-lived link.
	 */
	Reader.prototype.scheduleTokenRefresh = function () {
		var self = this;
		var ttl = this.cfg.tokenTtl || 900;

		window.clearTimeout(this.refreshTimer);
		this.refreshTimer = window.setTimeout(function () {
			self.refreshDocument();
		}, Math.max(60, ttl * 0.85) * 1000);
	};

	Reader.prototype.refreshDocument = function () {
		var self = this;

		return this.post(this.cfg.restToken, { id: this.cfg.id, unlock: this.unlock })
			.then(function (data) {
				if (!data || !data.ok) {
					throw new Error('token');
				}

				var task = window.pdfjsLib.getDocument({
					url: data.file,
					withCredentials: true,
					cMapUrl: self.cfg.cmaps,
					cMapPacked: true,
					isEvalSupported: false,
					enableScripting: false,
					enableXfa: false,
					disableAutoFetch: true
				});

				return task.promise.then(function (pdf) {
					var old = self.task;
					self.pdf = pdf;
					self.task = task;
					if (old) {
						old.destroy();
					}
					self.scheduleTokenRefresh();
				});
			})
			.catch(function () {
				self.announce(self.t.expired);
			});
	};

	/**
	 * Recovers from a rejected byte-range request.
	 *
	 * The usual cause is an expired link, so one silent refresh is attempted
	 * before giving up and telling the reader to reload.
	 */
	Reader.prototype.recover = function (err) {
		var self = this;
		var status = err && (err.status || err.code);

		if (this.recovering || (status !== 403 && status !== 401)) {
			return;
		}

		this.recovering = true;
		this.refreshDocument().then(function () {
			self.recovering = false;
			self.rendered = {};
			self.renderWindow(self.current);
		});
	};

	Reader.prototype.bindGate = function () {
		var self = this;
		var input = this.root.querySelector('[data-fbs-password]');
		var button = this.root.querySelector('[data-fbs-unlock]');

		if (!button) {
			return;
		}

		var submit = function () {
			var value = input.value;
			if (!value) {
				return;
			}
			button.disabled = true;
			self.el.gateError.textContent = '';

			self.post(self.cfg.restUnlock, { id: self.cfg.id, password: value })
				.then(function (data) {
					button.disabled = false;
					if (!data || !data.ok) {
						self.el.gateError.textContent = (data && data.message) || self.t.wrongPass;
						input.select();
						return;
					}
					self.unlock = data.unlock;
					try {
						window.sessionStorage.setItem('fbsUnlock' + self.cfg.id, data.unlock);
					} catch (e) { /* private mode */ }
					self.el.gate.hidden = true;
					self.open(data.file);
				})
				.catch(function () {
					button.disabled = false;
					self.el.gateError.textContent = self.t.wrongPass;
				});
		};

		button.addEventListener('click', submit);
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				submit();
			}
		});

		// A ticket from earlier in this tab skips the form.
		try {
			var saved = window.sessionStorage.getItem('fbsUnlock' + this.cfg.id);
			if (saved) {
				this.unlock = saved;
				this.post(this.cfg.restToken, { id: this.cfg.id, unlock: saved }).then(function (data) {
					if (data && data.ok) {
						self.el.gate.hidden = true;
						self.open(data.file);
					}
				});
			}
		} catch (e) { /* private mode */ }
	};

	/**
	 * POSTs JSON to a REST route.
	 *
	 * X-WP-Nonce is what lets WordPress accept a cookie-authenticated request
	 * without treating it as cross-site forgery.
	 */
	Reader.prototype.post = function (url, body) {
		return fetch(url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.cfg.nonce
			},
			body: JSON.stringify(body)
		}).then(function (response) {
			return response.json().catch(function () { return null; });
		});
	};

	Reader.prototype.track = function (page) {
		if (!this.cfg.analytics) {
			return;
		}

		// keepalive lets the request survive the page being closed, which is
		// exactly when the last page read is most worth recording.
		fetch(this.cfg.restView, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': this.cfg.nonce
			},
			body: JSON.stringify({ id: this.cfg.id, page: page, session: sessionId() })
		}).catch(function () { /* tracking must never break reading */ });
	};

	/* ------------------------------------------------------------------ */
	/* Controls                                                           */
	/* ------------------------------------------------------------------ */

	Reader.prototype.bindControls = function () {
		var self = this;
		var root = this.root;

		root.addEventListener('mouseenter', function () { self.hovered = true; });
		root.addEventListener('mouseleave', function () { self.hovered = false; });

		root.querySelectorAll('[data-fbs-next]').forEach(function (el) {
			el.addEventListener('click', function () { self.next(); });
		});

		root.querySelectorAll('[data-fbs-prev]').forEach(function (el) {
			el.addEventListener('click', function () { self.prev(); });
		});

		root.querySelectorAll('[data-fbs-toggle]').forEach(function (el) {
			el.addEventListener('click', function () {
				self.togglePanel(el.getAttribute('data-fbs-toggle'));
			});
		});

		root.querySelectorAll('[data-fbs-panel-close]').forEach(function (el) {
			el.addEventListener('click', function () { self.closePanels(); });
		});

		root.querySelectorAll('[data-fbs-zoom]').forEach(function (el) {
			el.addEventListener('click', function () {
				var step = el.getAttribute('data-fbs-zoom') === 'in' ? 0.4 : -0.4;
				self.setZoom(self.zoom + step);
			});
		});

		if (this.el.pageInput) {
			this.el.pageInput.addEventListener('change', function () {
				self.goTo(self.el.pageInput.value);
			});
		}

		if (this.el.query) {
			var timer = null;
			this.el.query.addEventListener('input', function () {
				window.clearTimeout(timer);
				var value = self.el.query.value;
				timer = window.setTimeout(function () { self.search(value); }, 280);
			});
		}

		var sound = root.querySelector('[data-fbs-sound]');
		if (sound) {
			sound.addEventListener('click', function () {
				self.soundOn = !self.soundOn;
				sound.setAttribute('aria-pressed', self.soundOn ? 'true' : 'false');
			});
		}

		var fullscreen = root.querySelector('[data-fbs-fullscreen]');
		if (fullscreen) {
			fullscreen.addEventListener('click', function () {
				if (document.fullscreenElement) {
					document.exitFullscreen();
				} else if (root.requestFullscreen) {
					root.requestFullscreen();
				}
			});
		}

		var share = root.querySelector('[data-fbs-share]');
		if (share) {
			share.addEventListener('click', function () {
				var url = self.cfg.shareUrl + '#' + self.hashKey() + self.current;
				if (navigator.clipboard) {
					navigator.clipboard.writeText(url);
				}
				self.announce(self.t.copied);
			});
		}

		var download = root.querySelector('[data-fbs-download]');
		if (download) {
			download.addEventListener('click', function () {
				self.post(self.cfg.restToken, { id: self.cfg.id, unlock: self.unlock }).then(function (data) {
					if (data && data.ok) {
						var link = document.createElement('a');
						link.href = data.file + '&dl=1';
						link.download = '';
						link.click();
					}
				});
			});
		}

		var print = root.querySelector('[data-fbs-print]');
		if (print) {
			print.addEventListener('click', function () {
				self.post(self.cfg.restToken, { id: self.cfg.id, unlock: self.unlock }).then(function (data) {
					if (!data || !data.ok) {
						return;
					}
					var frame = document.createElement('iframe');
					frame.style.cssText = 'position:fixed;width:0;height:0;border:0;opacity:0';
					frame.src = data.file;
					frame.onload = function () {
						try {
							frame.contentWindow.focus();
							frame.contentWindow.print();
						} catch (e) {
							window.open(data.file, '_blank', 'noopener');
						}
					};
					document.body.appendChild(frame);
				});
			});
		}

		var back = root.querySelector('[data-fbs-preview-back]');
		if (back) {
			back.addEventListener('click', function () {
				self.el.previewGate.hidden = true;
				self.goTo(Math.max(1, self.shown - 1));
			});
		}

		// Deterrents, not protection: they raise the effort of a casual grab.
		// The rules that actually decide access all run on the server.
		if (!this.cfg.allowDownload) {
			this.el.stage.addEventListener('contextmenu', function (e) { e.preventDefault(); });
			this.el.stage.addEventListener('dragstart', function (e) { e.preventDefault(); });
		}

		document.addEventListener('keydown', function (e) {
			if (!self.hovered && !root.contains(document.activeElement)) {
				return;
			}
			if (e.target.tagName === 'INPUT' && e.key !== 'Escape') {
				return;
			}

			switch (e.key) {
				case 'ArrowRight': self.next(); break;
				case 'ArrowLeft': self.prev(); break;
				case 'Home': self.goTo(1); break;
				case 'End': self.goTo(self.shown); break;
				case '+': case '=': self.setZoom(self.zoom + 0.4); break;
				case '-': self.setZoom(self.zoom - 0.4); break;
				case 'f': case 'F':
					if (root.requestFullscreen && !document.fullscreenElement) {
						root.requestFullscreen();
					}
					break;
				case 'Escape': self.closePanels(); break;
				default: return;
			}
			e.preventDefault();
		});

		window.addEventListener('resize', function () {
			window.clearTimeout(self.resizeTimer);
			self.resizeTimer = window.setTimeout(function () { self.onResize(); }, 200);
		});

		this.bindPan();
	};

	/**
	 * Re-fits the book after the window changes shape.
	 *
	 * Only the container is resized; StPageFlip in stretch mode recalculates
	 * its own geometry, and the canvases scale with CSS until the next render.
	 */
	Reader.prototype.onResize = function () {
		if (!this.flip || !this.ratio) {
			return;
		}
		var size = this.fitSize();
		this.el.book.style.width = size.width + 'px';
		this.el.book.style.height = size.height + 'px';

		try {
			this.flip.update();
		} catch (e) { /* older builds resize themselves */ }
	};

	/* ------------------------------------------------------------------ */
	/* Small helpers                                                      */
	/* ------------------------------------------------------------------ */

	Reader.prototype.hashKey = function () {
		return 'flipbook-' + this.cfg.id + '-p';
	};

	Reader.prototype.hashPage = function () {
		var match = window.location.hash.match(new RegExp(this.hashKey() + '(\\d+)'));
		return match ? parseInt(match[1], 10) : 0;
	};

	Reader.prototype.updateHash = function (page) {
		if (window.history && window.history.replaceState) {
			window.history.replaceState(null, '', '#' + this.hashKey() + page);
		}
	};

	Reader.prototype.setStatus = function (text) {
		if (this.el.statusText) {
			this.el.statusText.textContent = text;
		}
	};

	Reader.prototype.progress = function (fraction) {
		if (this.el.bar) {
			this.el.bar.style.width = Math.round(clamp(fraction, 0, 1) * 100) + '%';
		}
	};

	Reader.prototype.hideStatus = function () {
		if (this.el.status) {
			this.el.status.hidden = true;
		}
	};

	Reader.prototype.showPreviewGate = function () {
		if (this.el.previewGate) {
			this.el.previewGate.hidden = false;
		}
	};

	Reader.prototype.announce = function (text) {
		if (this.el.hint) {
			this.el.hint.textContent = text;
		}
	};

	Reader.prototype.fail = function (message, err) {
		this.setStatus(message);
		this.progress(0);
		if (err && window.console) {
			window.console.warn('[Flipbook Studio]', err);
		}
	};

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Starts a reader only once it is close to the viewport.
	 *
	 * A flipbook far down a long page should not cost the visitor a PDF
	 * download they may never scroll to.
	 */
	function boot() {
		var nodes = document.querySelectorAll('[data-fbs]');

		if (!('IntersectionObserver' in window)) {
			nodes.forEach(function (node) { new Reader(node); });
			return;
		}

		var observer = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					observer.unobserve(entry.target);
					new Reader(entry.target);
				}
			});
		}, { rootMargin: '400px' });

		nodes.forEach(function (node) { observer.observe(node); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
