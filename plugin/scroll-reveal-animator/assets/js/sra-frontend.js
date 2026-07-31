(function () {
	'use strict';

	var config = window.SRA_CONFIG || {};
	var once = config.once !== false;
	var threshold = typeof config.threshold === 'number' ? config.threshold : 0.15;
	var autoSelectors = Array.isArray(config.autoSelectors) ? config.autoSelectors : [];

	var SELECTOR = '[class*="sra-fade"], [class*="sra-zoom"]';

	function applyAutoSelectors(root) {
		autoSelectors.forEach(function (selector) {
			var nodes;
			try {
				nodes = root.querySelectorAll(selector);
			} catch (e) {
				return;
			}
			Array.prototype.forEach.call(nodes, function (node) {
				if (!/(^|\s)sra-(fade|zoom)/.test(node.className)) {
					node.classList.add('sra-fade-up');
				}
			});
		});
	}

	function applyDelay(node) {
		var delay = parseInt(node.getAttribute('data-sra-delay'), 10);
		if (delay > 0 && delay <= 3000) {
			node.style.transitionDelay = delay + 'ms';
		}
	}

	function revealAll(root) {
		var nodes = root.querySelectorAll(SELECTOR);
		Array.prototype.forEach.call(nodes, function (node) {
			node.classList.add('sra-visible');
		});
	}

	function start() {
		document.body.classList.add('sra-ready');
		applyAutoSelectors(document);

		if (!('IntersectionObserver' in window)) {
			revealAll(document);
			return;
		}

		var observer = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						entry.target.classList.add('sra-visible');
						if (once) {
							observer.unobserve(entry.target);
						}
					} else if (!once) {
						entry.target.classList.remove('sra-visible');
					}
				});
			},
			{ threshold: threshold, rootMargin: '0px 0px -5% 0px' }
		);

		function observe(root) {
			var nodes = root.querySelectorAll(SELECTOR);
			Array.prototype.forEach.call(nodes, function (node) {
				if (node.getAttribute('data-sra-observed')) {
					return;
				}
				node.setAttribute('data-sra-observed', '1');
				applyDelay(node);
				observer.observe(node);
			});
		}

		observe(document);

		if ('MutationObserver' in window) {
			var mo = new MutationObserver(function (mutations) {
				mutations.forEach(function (mutation) {
					Array.prototype.forEach.call(mutation.addedNodes, function (node) {
						if (node.nodeType !== 1) {
							return;
						}
						applyAutoSelectors(node);
						observe(node);
						if (node.matches && node.matches(SELECTOR)) {
							if (!node.getAttribute('data-sra-observed')) {
								node.setAttribute('data-sra-observed', '1');
								applyDelay(node);
								observer.observe(node);
							}
						}
					});
				});
			});
			mo.observe(document.body, { childList: true, subtree: true });
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
