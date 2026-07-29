/* Flipbook Studio - admin helpers. */
(function () {
	'use strict';

	document.addEventListener('click', function (e) {
		var field = e.target.closest('.fbs-copy');

		if (!field) {
			return;
		}

		field.select();

		var done = function () {
			field.classList.add('is-copied');
			window.setTimeout(function () {
				field.classList.remove('is-copied');
			}, 1200);
		};

		// The clipboard API needs a secure context; execCommand is the fallback
		// for sites still served over plain HTTP.
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(field.value).then(done);
		} else {
			try {
				document.execCommand('copy');
				done();
			} catch (err) { /* the field is selected, the user can copy manually */ }
		}
	});
})();
