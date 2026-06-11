(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var root   = document.getElementById('wcw-root');
		var box    = document.getElementById('wcw-box');
		var toggle = document.getElementById('wcw-toggle');
		var close  = document.getElementById('wcw-close');
		var input  = document.getElementById('wcw-input');
		var send   = document.getElementById('wcw-send');

		if (!root || !box || !toggle || typeof wcwData === 'undefined') {
			return;
		}

		var phone = String(wcwData.phone || '').replace(/\D/g, '');
		if (!phone) {
			return;
		}

		function setOpen(open) {
			root.classList.toggle('wcw-open', open);
			toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			box.setAttribute('aria-hidden', open ? 'false' : 'true');

			if (open) {
				box.hidden = false;
				requestAnimationFrame(function () {
					root.classList.add('wcw-open');
					input.focus();
				});
			} else {
				root.classList.remove('wcw-open');
				window.setTimeout(function () {
					box.hidden = true;
				}, 200);
			}
		}

		function isOpen() {
			return root.classList.contains('wcw-open');
		}

		function sendMessage() {
			var text = input.value.trim() || String(wcwData.defaultMessage || '');
			var url  = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(text);

			window.open(url, '_blank', 'noopener,noreferrer');
			input.value = '';
		}

		toggle.addEventListener('click', function () {
			setOpen(!isOpen());
		});

		close.addEventListener('click', function () {
			setOpen(false);
			toggle.focus();
		});

		send.addEventListener('click', sendMessage);

		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				sendMessage();
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && isOpen()) {
				setOpen(false);
				toggle.focus();
			}
		});
	});
})();
