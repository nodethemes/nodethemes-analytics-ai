/* NodeThemes Analytics AI – visitor opt-out box ([ntaa_optout]). */
(function () {
	function out() { return /(?:^|;\s*)ntaa_optout=1/.test(document.cookie); }
	function setup(box) {
		var cb = box.querySelector('input'), msg = box.querySelector('.ntaa-optout-msg');
		if (!cb || !msg) { return; }
		var t = box.getAttribute('data-tracked') || '', n = box.getAttribute('data-not-tracked') || '';
		function show() { cb.checked = !out(); msg.textContent = out() ? n : t; }
		cb.addEventListener('change', function () {
			var secure = location.protocol === 'https:' ? '; Secure' : '';
			if (cb.checked) { document.cookie = 'ntaa_optout=; path=/; max-age=0; SameSite=Lax' + secure; }
			else { document.cookie = 'ntaa_optout=1; path=/; max-age=' + (60 * 60 * 24 * 400) + '; SameSite=Lax' + secure; }
			try { if (!cb.checked) { localStorage.removeItem('ntaa_vid'); } } catch (e) {}
			show();
		});
		show();
	}
	function init() {
		var boxes = document.querySelectorAll('.ntaa-optout');
		for (var i = 0; i < boxes.length; i++) { setup(boxes[i]); }
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
