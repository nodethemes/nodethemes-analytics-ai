/* NodeThemes Analytics AI – settings page behaviour */
(function (w, d) {
	'use strict';
	var N = w.NTAA;
	if (!N) return;
	var __ = N.__, $ = N.$, $$ = N.$$;

	N.ready(function () {
		var root = d.getElementById('ntaa-app');
		if (!root || root.getAttribute('data-view') !== 'settings') return;

		var tabs = $$('[data-tabs] [data-tab]', root);
		function show(id) {
			tabs.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-tab') === id); t.setAttribute('aria-selected', t.getAttribute('data-tab') === id); });
			$$('[data-pane]', root).forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-pane') === id); });
			$$('[data-pane-extra]', root).forEach(function (p) { p.hidden = p.getAttribute('data-pane-extra') !== id; });
		}
		tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab')); history.replaceState(null, '', '#' + t.getAttribute('data-tab')); }); });
		var hash = (location.hash || '').replace('#', '');
		show(hash && $('[data-pane="' + hash + '"]', root) ? hash : (tabs[0] ? tabs[0].getAttribute('data-tab') : 'tracking'));

		var form = $('form[action="options.php"]', root);
		if (form) form.addEventListener('submit', function () {
			var ref = form.querySelector('input[name="_wp_http_referer"]'), active = $('[data-tabs] .active', root);
			if (ref && active) ref.value = ref.value.split('#')[0] + '#' + active.getAttribute('data-tab');
		});

		// Show/hide dependent rows.
		$$('[data-depends]', root).forEach(function (row) {
			var sel = row.getAttribute('data-depends').split('='), input = $('[name="' + sel[0] + '"]', root) || $('#' + sel[0], root);
			if (!input) return;
			function sync() {
				var v = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
				row.hidden = sel[1].split('|').indexOf(v) === -1;
			}
			input.addEventListener('change', sync); sync();
		});

		$$('.ntaa-test', root).forEach(function (b) {
			b.addEventListener('click', function () {
				var id = b.getAttribute('data-provider'), out = $('.ntaa-test-result[data-for="' + id + '"]', root);
				b.disabled = true;
				out.className = 'ntaa-test-result';
				out.textContent = __('Testing…');
				N.api('ai/test', { method: 'POST', body: { provider: id } })
					.then(function (r) { out.classList.add('ok'); out.textContent = '✓ ' + N.sprintf(__('Connected (%s)'), r.model); })
					.catch(function (e) { out.classList.add('err'); out.textContent = '✗ ' + e.message; })
					.finally(function () { b.disabled = false; });
			});
		});

		$$('[data-copy]', root).forEach(function (b) { b.addEventListener('click', function () { N.copy(b.getAttribute('data-copy')); }); });

		var gdpr = $('#ntaa-gdpr-go', root);
		if (gdpr) gdpr.addEventListener('click', function () {
			var v = $('#ntaa-gdpr-id', root).value.trim().toLowerCase(), out = $('#ntaa-gdpr-out', root);
			if (!/^[a-f0-9]{16}$/.test(v)) { out.textContent = __('Enter the 16-character visitor ID shown in the visitor profile (Visits log › visit › Visitor profile).'); return; }
			if (!w.confirm(__('Delete every visit of this visitor? This cannot be undone.'))) return;
			N.api('gdpr/delete-visitor', { method: 'POST', body: { visitor_id: v } }).then(function (r) { out.textContent = N.sprintf(__('%s visits deleted.'), N.num(r.deleted)); }).catch(function (e) { out.textContent = e.message; });
		});
	});
})(window, document);
