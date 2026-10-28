/* NodeThemes Analytics AI – AI Insights page */
(function (w, d) {
	'use strict';
	var N = w.NTAA;
	if (!N) return;
	var A = N.A, __ = N.__, esc = N.esc, $ = N.$, $$ = N.$$, icon = N.icon;

	function hasAI() { return Object.keys(A.providers || {}).some(function (k) { return A.providers[k].configured; }); }
	function provider() {
		var p = N.store('ai_provider') || A.provider;
		if (!A.providers[p] || !A.providers[p].configured) p = Object.keys(A.providers).filter(function (k) { return A.providers[k].configured; })[0] || A.provider;
		return p;
	}
	function thinking(el) {
		el.innerHTML = '<div class="ntaa-thinking"><span></span><span></span><span></span> ' + esc(__('Your AI is analyzing the data…')) + ' <b class="ntaa-elapsed">0</b>' + esc(__('s')) + '</div>';
		var t0 = Date.now(), tm = setInterval(function () { var s = $('.ntaa-elapsed', el); if (s) s.textContent = N.num(Math.round((Date.now() - t0) / 1000)); }, 1000);
		return function () { clearInterval(tm); return Math.round((Date.now() - t0) / 1000); };
	}
	function lockedLink(label) {
		var href = A.proInstalled ? A.licenseUrl : A.upgradeUrl;
		return '<a class="button button-primary" href="' + esc(href) + '"' + (A.proInstalled ? '' : ' target="_blank" rel="noopener"') + '>' + esc(label) + '</a>';
	}
	function body(extra) {
		var b = { from: N.state.from, to: N.state.to, provider: provider(), site: N.state.site };
		if (N.state.segment && N.state.segment.conds && N.state.segment.conds.length) b.segment = N.state.segment.conds;
		return Object.assign(b, extra);
	}

	var root, chatLog = [], lastText = '';

	function chatCard() {
		var head = '<div class="ntaa-card-head"><h3>' + icon('format-chat') + esc(__('Ask your data')) + (A.chat ? '' : ' <span class="ntaa-protag">PRO</span>') + '</h3>' + (A.chat ? '<button type="button" class="ntaa-icon-btn" id="ntaa-chat-clear" title="' + esc(__('New conversation')) + '" aria-label="' + esc(__('New conversation')) + '">' + icon('trash') + '</button>' : '') + '</div>';
		if (!A.chat) return '<div class="ntaa-card ntaa-chat">' + head + '<p class="ntaa-muted">' + esc(__('Ask questions like “Why is my bounce rate high?” or “Which page should I improve first?” and get answers based on your own numbers.')) + '</p>' + lockedLink(A.proInstalled ? __('Activate license') : __('Unlock with Pro')) + '</div>';
		var ideas = [__('Why is my bounce rate high?'), __('Which page should I improve first?'), __('Which channel brings the most sales?'), __('How is my mobile traffic doing?')];
		return '<div class="ntaa-card ntaa-chat">' + head + '<div class="ntaa-chat-suggest">' + ideas.map(function (q) { return '<button type="button" data-q="' + esc(q) + '">' + esc(q) + '</button>'; }).join('') + '</div><div id="ntaa-chat-log" class="ntaa-chat-log" aria-live="polite"></div>' +
			'<form id="ntaa-chat-form" class="ntaa-chat-form"><label class="screen-reader-text" for="ntaa-chat-input">' + esc(__('Your question')) + '</label><textarea id="ntaa-chat-input" rows="2" placeholder="' + esc(__('Ask anything about your analytics…')) + '"></textarea><button class="button button-primary" type="submit">' + esc(__('Send')) + '</button></form></div>';
	}

	function renderAI() {
		var types = A.types || {};
		var provSel = '<div class="ntaa-provider-select" role="radiogroup" aria-label="' + esc(__('AI provider')) + '">' + Object.keys(A.providers).map(function (k) {
			var p = A.providers[k], on = provider() === k;
			return '<button type="button" role="radio" aria-checked="' + on + '" data-prov="' + k + '" class="' + (on ? 'active ' : '') + (p.configured ? '' : 'off') + '" ' + (p.configured ? '' : 'disabled title="' + esc(__('No API key saved')) + '"') + '><b>' + esc(p.label) + '</b><small dir="ltr">' + esc(p.model) + '</small></button>';
		}).join('') + '</div>';
		var proNote = A.pro ? '' : '<div class="ntaa-card ntaa-pro-box">' + icon('lock') + '<div><b>' + esc(__('Deep AI reports are part of Pro')) + '</b><p>' + esc(A.proInstalled ? __('The Pro add-on is installed. Activate your license key to unlock every report, the AI chat and scheduled email reports.') : __('The free version includes the AI summary. Pro adds in-depth reports, chat with your data, a report archive and scheduled email reports.')) + '</p></div>' + lockedLink(A.proInstalled ? __('Activate license') : __('Get Pro')) + '</div>';

		root.innerHTML = N.header(__('AI Insights'), __('Let Claude, Gemini or ChatGPT read your analytics and tell you what to do next')) +
			(hasAI() ? '' : '<div class="ntaa-card ntaa-warn">' + icon('warning') + ' ' + esc(__('No AI provider is connected yet.')) + ' ' + (A.canManage ? '<a class="button button-primary" href="' + esc(A.settingsUrl) + '#ai">' + esc(__('Add an API key')) + '</a>' : esc(__('Ask the site administrator to add an API key.'))) + '</div>') +
			proNote + '<div id="ntaa-toolbar"></div>' +
			'<div class="ntaa-ai-layout"><div class="ntaa-ai-main">' +
			N.card(__('AI provider'), provSel, { icon: 'admin-generic' }) +
			N.card(__('Choose a report'), '<div class="ntaa-ai-types">' + Object.keys(types).map(function (k) { var t = types[k]; return '<button type="button" class="ntaa-ai-type' + (t.locked ? ' locked' : '') + '" data-type="' + esc(k) + '">' + icon(t.locked ? 'lock' : t.icon) + '<b>' + esc(t.label) + (t.locked ? ' <span class="ntaa-protag">PRO</span>' : '') + '</b><small>' + esc(t.desc || '') + '</small></button>'; }).join('') + '</div><label class="screen-reader-text" for="ntaa-extra">' + esc(__('Extra instructions')) + '</label><textarea id="ntaa-extra" rows="2" placeholder="' + esc(__('Optional: extra instructions, e.g. “focus on product pages”')) + '"></textarea><p class="description">' + esc(__('The selected date range, website and segment are used.')) + '</p>', { icon: 'analytics' }) +
			'<div class="ntaa-card ntaa-result" id="ntaa-result" hidden><div class="ntaa-card-head"><h3 id="ntaa-result-title"></h3><div class="ntaa-result-tools">' +
			'<button type="button" class="ntaa-icon-btn" id="ntaa-copy" title="' + esc(__('Copy')) + '" aria-label="' + esc(__('Copy')) + '">' + icon('admin-page') + '</button>' +
			'<button type="button" class="ntaa-icon-btn" id="ntaa-dl" title="' + esc(__('Download Markdown')) + '" aria-label="' + esc(__('Download Markdown')) + '">' + icon('download') + '</button>' +
			'<button type="button" class="ntaa-icon-btn" id="ntaa-print" title="' + esc(__('Print / PDF')) + '" aria-label="' + esc(__('Print / PDF')) + '">' + icon('printer') + '</button></div></div><div id="ntaa-result-body" class="ntaa-md"></div></div>' +
			chatCard() +
			'</div><aside class="ntaa-ai-side">' + N.card(__('Saved reports'), '<div id="ntaa-history" class="ntaa-history"><div class="ntaa-muted">…</div></div>', { icon: 'archive' }) +
			'<div class="ntaa-card ntaa-note">' + icon('shield') + ' ' + esc(__('Only aggregated numbers are sent to the AI provider. Visitor IP addresses and personal data are never sent.')) + '</div></aside></div>' + N.support();

		N.mountToolbar($('#ntaa-toolbar', root));
		$$('[data-prov]', root).forEach(function (b) {
			b.addEventListener('click', function () {
				N.store('ai_provider', b.getAttribute('data-prov'));
				$$('[data-prov]', root).forEach(function (x) { x.classList.toggle('active', x === b); x.setAttribute('aria-checked', x === b); });
			});
		});
		$$('.ntaa-ai-type', root).forEach(function (b) {
			b.addEventListener('click', function () {
				var t = types[b.getAttribute('data-type')];
				if (t.locked) { w.open(A.proInstalled ? A.licenseUrl : A.upgradeUrl, A.proInstalled ? '_self' : '_blank'); return; }
				run(b.getAttribute('data-type'), b);
			});
		});
		$('#ntaa-copy').addEventListener('click', function () { N.copy(lastText, __('Report copied')); });
		$('#ntaa-dl').addEventListener('click', function () {
			var a = d.createElement('a');
			a.href = URL.createObjectURL(new Blob([lastText], { type: 'text/markdown;charset=utf-8' }));
			a.download = 'nodethemes-analytics-ai-report-' + N.state.from + '_' + N.state.to + '.md';
			a.click();
		});
		$('#ntaa-print').addEventListener('click', function () {
			var pw = w.open('', '_blank');
			if (!pw) return;
			pw.document.write('<!doctype html><html dir="' + (N.RTL ? 'rtl' : 'ltr') + '"><head><meta charset="utf-8"><title>' + esc(__('AI report')) + '</title><style>body{font-family:-apple-system,"Segoe UI",Roboto,Vazirmatn,Tahoma,sans-serif;line-height:1.8;max-width:800px;margin:30px auto;padding:0 16px;color:#0f172a}table{border-collapse:collapse;width:100%}td,th{border:1px solid #cbd5e1;padding:6px 8px}h2,h3{color:#4338ca}</style></head><body><h1>' + esc($('#ntaa-result-title').textContent) + '</h1>' + $('#ntaa-result-body').innerHTML + '</body></html>');
			pw.document.close(); pw.focus(); setTimeout(function () { pw.print(); }, 300);
		});
		if (A.chat) {
			$$('[data-q]', root).forEach(function (b) { b.addEventListener('click', function () { $('#ntaa-chat-input').value = b.getAttribute('data-q'); chat(); }); });
			$('#ntaa-chat-form').addEventListener('submit', function (e) { e.preventDefault(); chat(); });
			$('#ntaa-chat-input').addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chat(); } });
			$('#ntaa-chat-clear').addEventListener('click', function () { chatLog = []; $('#ntaa-chat-log').innerHTML = ''; });
		}
		history();
	}

	function run(type, btn) {
		if (!hasAI()) { N.toast(__('Connect an AI provider in Settings first.'), 'err'); return; }
		var box = $('#ntaa-result'), out = $('#ntaa-result-body'), t = A.types[type];
		box.hidden = false;
		$('#ntaa-result-title').innerHTML = icon(t.icon) + esc(t.label) + ' <small class="ntaa-muted">' + esc(N.longDate(N.state.from)) + ' – ' + esc(N.longDate(N.state.to)) + '</small>';
		var stop = thinking(out);
		$$('.ntaa-ai-type', root).forEach(function (b) { b.disabled = true; b.classList.toggle('running', b === btn); });
		box.scrollIntoView({ behavior: 'smooth', block: 'start' });
		N.api('ai/analyze', { method: 'POST', body: body({ type: type, question: $('#ntaa-extra').value }) }).then(function (r) {
			lastText = r.text;
			var secs = stop();
			out.innerHTML = N.md(r.text) + '<div class="ntaa-ai-meta">' + icon('admin-generic') + esc((A.providers[r.provider] || {}).label || r.provider) + ' · <span dir="ltr">' + esc(r.model) + '</span> · ' + N.num(secs) + esc(__('s')) + '</div>';
			history();
		}).catch(function (e) { stop(); out.innerHTML = '<div class="ntaa-error">' + icon('warning') + esc(e.message) + '</div>'; })
			.finally(function () { $$('.ntaa-ai-type', root).forEach(function (b) { b.disabled = false; b.classList.remove('running'); }); });
	}

	function chat() {
		var input = $('#ntaa-chat-input'), q = input.value.trim();
		if (!q) return;
		if (!hasAI()) { N.toast(__('Connect an AI provider in Settings first.'), 'err'); return; }
		var log = $('#ntaa-chat-log');
		input.value = '';
		log.insertAdjacentHTML('beforeend', '<div class="ntaa-msg user">' + esc(q).replace(/\n/g, '<br>') + '</div>');
		var holder = d.createElement('div');
		holder.className = 'ntaa-msg ai';
		log.appendChild(holder);
		var stop = thinking(holder);
		log.scrollTop = log.scrollHeight;
		N.api('ai/analyze', { method: 'POST', body: body({ type: 'chat', question: q, history: chatLog.slice() }) }).then(function (r) {
			chatLog.push({ role: 'user', content: q }, { role: 'assistant', content: r.text });
			holder.innerHTML = '<div class="ntaa-md">' + N.md(r.text) + '</div>';
			history();
		}).catch(function (e) { holder.innerHTML = '<div class="ntaa-error">' + esc(e.message) + '</div>'; })
			.finally(function () { stop(); log.scrollTop = log.scrollHeight; });
	}

	function typeLabel(type) { return type === 'chat' ? __('Chat') : ((A.types[type] || {}).label || type); }

	function history() {
		var el = $('#ntaa-history');
		if (!el) return;
		N.api('ai/reports', { cache: false }).then(function (rows) {
			if (!rows.length) { el.innerHTML = '<div class="ntaa-muted">' + esc(__('No reports yet.')) + '</div>'; return; }
			el.innerHTML = rows.map(function (r) {
				return '<div class="ntaa-hist" data-id="' + Number(r.id) + '" tabindex="0" role="button"><div><b>' + esc(typeLabel(r.type)) + '</b><small>' + esc(N.dateTime(r.created_at)) + ' · ' + esc(r.provider) + '</small>' + (r.type === 'chat' ? '<small class="ntaa-hist-q">' + esc(r.prompt) + '</small>' : '') + '</div>' +
					(A.canManage ? '<button type="button" class="ntaa-icon-btn" data-del="' + Number(r.id) + '" title="' + esc(__('Delete')) + '" aria-label="' + esc(__('Delete')) + '">' + icon('no-alt') + '</button>' : '') + '</div>';
			}).join('');
			$$('.ntaa-hist', el).forEach(function (h) {
				function open(e) {
					if (e.target.closest('[data-del]')) return;
					N.api('ai/reports/' + h.getAttribute('data-id')).then(function (r) {
						var box = $('#ntaa-result');
						box.hidden = false;
						lastText = r.response;
						$('#ntaa-result-title').innerHTML = icon('archive') + esc(typeLabel(r.type)) + ' <small class="ntaa-muted">' + esc(N.longDate(r.date_from)) + ' – ' + esc(N.longDate(r.date_to)) + '</small>';
						$('#ntaa-result-body').innerHTML = (r.type === 'chat' ? '<blockquote>' + esc(r.prompt) + '</blockquote>' : '') + N.md(r.response) + '<div class="ntaa-ai-meta">' + esc(r.provider) + ' · <span dir="ltr">' + esc(r.model) + '</span></div>';
						box.scrollIntoView({ behavior: 'smooth', block: 'start' });
					});
				}
				h.addEventListener('click', open);
				h.addEventListener('keydown', function (e) { if (e.key === 'Enter') open(e); });
			});
			$$('[data-del]', el).forEach(function (b) {
				b.addEventListener('click', function () {
					if (!w.confirm(__('Delete this report?'))) return;
					N.api('ai/reports/' + b.getAttribute('data-del'), { method: 'DELETE' }).then(history);
				});
			});
		}).catch(function () { el.innerHTML = ''; });
	}

	N.ready(function () {
		root = d.getElementById('ntaa-app');
		if (root && root.getAttribute('data-view') === 'ai') renderAI();
	});
})(window, document);
