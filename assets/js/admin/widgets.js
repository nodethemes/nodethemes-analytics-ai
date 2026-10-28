/* NodeThemes Analytics AI – "My dashboard" (customizable widgets) and "Compare segments". Pro adds widgets with NTAA.addWidget(). */
(function (w, d) {
	'use strict';
	var N = w.NTAA;
	if (!N || !N.dash) return;
	var A = N.A, __ = N.__, esc = N.esc, $ = N.$, $$ = N.$$, num = N.num, pct = N.pct, dur = N.dur, icon = N.icon, money = N.money, table = N.table, card = N.card, D = N.dash;

	/* =============================================================
	 * Widget registry
	 * =========================================================== */
	var widgets = [];
	function addWidget(wg) {
		var i = widgets.findIndex(function (x) { return x.id === wg.id; });
		if (i >= 0) widgets[i] = wg; else widgets.push(wg);
	}
	N.addWidget = addWidget;
	N.widgets = widgets;

	function chartBox(id) { return '<div class="ntaa-chart-box"><canvas id="' + id + '"></canvas></div>'; }
	function link(hash, label) { return '<a class="button-link" href="#/' + hash + '">' + esc(label) + '</a>'; }

	addWidget({ id: 'kpis', title: __('Key metrics'), icon: 'dashboard', part: 'summary', size: 'full', render: function (el, dd) {
		var s = dd.summary, p = dd.previous;
		el.innerHTML = '<div class="ntaa-kpis">' + D.METRICS.map(function (m) { return N.kpi(m.label, m.fmt(s[m.id]), m.icon, N.delta(s[m.id], p[m.id], m.invert)); }).join('') + '</div>';
	} });
	addWidget({ id: 'chart', title: __('Visits over time'), icon: 'chart-area', part: 'summary', size: 'full', render: function (el, dd, wid) {
		var pts = dd.timeseries.points || [], hourly = dd.timeseries.granularity === 'hour';
		var metric = N.store('w_chart_metric') || 'visits';
		var m = D.METRICS.filter(function (x) { return x.id === metric; })[0] || D.METRICS[1];
		el.innerHTML = card(m.label, chartBox(wid + '-c'), { icon: 'chart-area', tools: '<select data-wm>' + D.METRICS.map(function (x) { return '<option value="' + x.id + '"' + (x.id === m.id ? ' selected' : '') + '>' + esc(x.label) + '</option>'; }).join('') + '</select>' });
		N.line(wid + '-c', pts.map(function (p) { return hourly ? N.clock(p.date) : N.fmtDate(p.date); }), [{ label: m.label, data: pts.map(function (p) { return D.metricValue(m, p); }) }], m.fmt, { annotations: hourly ? [] : (dd.annotations || []), keys: pts.map(function (p) { return String(p.date).slice(0, 10); }) });
		$('[data-wm]', el).addEventListener('change', function () { N.store('w_chart_metric', this.value); wg('chart').render(el, dd, wid); });
	} });
	addWidget({ id: 'channels', title: __('Channels'), icon: 'share-alt2', part: 'overview', render: function (el, dd, wid) {
		var ch = dd.referrer_types || [];
		el.innerHTML = card(__('Channels'), ch.length ? chartBox(wid + '-c') : N.empty(), { icon: 'share-alt2', tools: link('acquisition/channels', __('More')) });
		if (ch.length) N.doughnut(wid + '-c', ch.map(function (c) { return N.CHANNEL[c.type] || c.type; }), ch.map(function (c) { return c.visits; }));
	} });
	addWidget({ id: 'devices', title: __('Devices'), icon: 'smartphone', part: 'overview', render: function (el, dd, wid) {
		var dv = dd.devices || [];
		el.innerHTML = card(__('Devices'), dv.length ? chartBox(wid + '-c') : N.empty(), { icon: 'smartphone', tools: link('visitors/devices', __('More')) });
		if (dv.length) N.doughnut(wid + '-c', dv.map(function (c) { return N.DEVICE[c.name] || c.name; }), dv.map(function (c) { return c.visits; }));
	} });
	addWidget({ id: 'pages', title: __('Top pages'), icon: 'media-document', part: 'overview', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Top pages'), [{ key: 'page', label: __('Page'), fmt: N.pageCell }, { key: 'views', label: __('Views'), num: true, fmt: num }, { key: 'avg_time', label: __('Time'), num: true, fmt: dur }], dd.pages, { bar: 'views', limit: 8, icon: 'media-document', evo: D.evoPage('page'), tools: link('behaviour/pages', __('More')) });
	} });
	addWidget({ id: 'map', title: __('Visitor map'), icon: 'admin-site-alt3', part: 'overview', render: function (el, dd) {
		el.innerHTML = card(__('Visitor map'), D.mapSvg(dd.countries, 'visits'), { icon: 'admin-site-alt3', tools: link('visitors/locations', __('More')) });
		D.bindMap(el);
	} });
	addWidget({ id: 'countries', title: __('Countries'), icon: 'flag', part: 'overview', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Countries'), [{ key: 'name', label: __('Country'), fmt: function (v) { return N.other(v) || N.country(v); }, csv: function (v) { return v; } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: function (v) { return v ? pct(v) : '—'; } }], dd.countries, { bar: 'visits', limit: 8, icon: 'flag', evo: D.evo('country') });
	} });
	addWidget({ id: 'busiest', title: __('Busiest times'), icon: 'grid-view', part: 'overview', size: 'full', render: function (el, dd) { el.innerHTML = D.heatmapCard(dd.heatmap); } });
	addWidget({ id: 'referrers', title: __('Referrers'), icon: 'admin-links', part: 'sources', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Referrers'), [{ key: 'host', label: __('Source'), fmt: function (v, r) { return N.other(v) || '<span dir="ltr">' + esc(v) + '</span> <small class="ntaa-tag">' + esc(N.CHANNEL[r.type] || r.type) + '</small>'; } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }], dd.referrers, { bar: 'visits', limit: 8, icon: 'admin-links', evo: D.evo('referrer', function (r) { return r.host + N.SEP + r.type; }), tools: link('acquisition/websites', __('More')) });
	} });
	addWidget({ id: 'campaigns', title: __('Campaigns'), icon: 'megaphone', part: 'sources', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Campaigns'), [{ key: 'campaign', label: __('Campaign'), fmt: function (v, r) { return N.other(v) || '<b>' + esc(v || __('(not set)')) + '</b> <small class="ntaa-muted" dir="ltr">' + esc([r.source, r.medium].filter(Boolean).join(' / ')) + '</small>'; } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: function (v) { return v ? pct(v) : '—'; } }], dd.campaigns, { bar: 'visits', limit: 8, icon: 'megaphone', evo: D.evo('campaign', function (r) { return r.campaign + N.SEP + r.source + N.SEP + r.medium; }), tools: link('acquisition/campaigns', __('More')) });
	} });
	addWidget({ id: 'ai', title: __('AI assistants'), icon: 'superhero', part: 'sources', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('AI assistants'), [{ key: 'name', label: __('Assistant'), fmt: function (v) { return N.other(v) || esc(v); } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: function (v) { return v ? pct(v) : '—'; } }], dd.ai_assistants, { bar: 'visits', limit: 8, icon: 'superhero', evo: D.evo('ai'), empty: __('No visits from AI assistants yet.') });
	} });
	addWidget({ id: 'entry', title: __('Entry pages'), icon: 'migrate', part: 'content', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Entry pages'), [{ key: 'page', label: __('Page'), fmt: N.pageCell }, { key: 'entrances', label: __('Entrances'), num: true, fmt: num }, { key: 'bounce_rate', label: __('Bounce'), num: true, fmt: N.bounceFmt }], dd.entry_pages, { bar: 'entrances', limit: 8, icon: 'migrate', evo: D.evoPage('entry') });
	} });
	addWidget({ id: 'search', title: __('Site search'), icon: 'search', part: 'content', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Site search'), [{ key: 'term', label: __('Search term'), fmt: function (v) { return N.other(v) || '<b>' + esc(v) + '</b>'; } }, { key: 'searches', label: __('Searches'), num: true, fmt: num }, { key: 'no_results', label: __('No results'), num: true, fmt: function (v) { return v ? '<span class="ntaa-bad">' + num(v) + '</span>' : '—'; } }], dd.site_search, { bar: 'searches', limit: 8, icon: 'search', evo: D.evo('search', function (r) { return r.term; }) });
	} });
	addWidget({ id: 'vitals', title: __('Page speed (Web Vitals)'), icon: 'performance', part: 'content', render: function (el, dd) {
		var p = dd.performance || {};
		function v(label, val, good, poor, fmt) { var c = !val ? '' : (val <= good ? 'good' : (val <= poor ? 'mid' : 'bad')); return '<div class="ntaa-kpi ntaa-vital ' + c + '"><span class="ntaa-kpi-label">' + esc(label) + '</span><b>' + (val ? (fmt ? fmt(val) : N.ms(val)) : '—') + '</b></div>'; }
		el.innerHTML = card(__('Page speed (Web Vitals)'), '<div class="ntaa-kpis small">' + v('LCP', p.lcp, 2500, 4000) + v('INP', p.inp, 200, 500) + v('CLS', p.cls, 0.1, 0.25, function (x) { return N.dec(x, 3); }) + v('TTFB', p.ttfb, 800, 1800) + '</div>', { icon: 'performance', tools: link('behaviour/performance', __('More')) });
	} });
	addWidget({ id: 'goals', title: __('Goals'), icon: 'flag', part: 'goals', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Goals'), [{ key: 'name', label: __('Goal'), fmt: function (v) { return '<b>' + esc(v) + '</b>'; } }, { key: 'conversions', label: __('Conversions'), num: true, fmt: num }, { key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: pct }], dd.goals, { bar: 'conversions', icon: 'flag', evo: Object.assign(D.evo('goal', function (r) { return String(r.id); }), { title: function (r) { return r.name; } }), empty: __('No goals yet.'), tools: link('goals/overview', __('More')) });
	} });
	addWidget({ id: 'ecommerce', title: __('Ecommerce'), icon: 'cart', part: 'ecommerce', woo: true, render: function (el, dd) {
		var e = dd.ecommerce || {}, p = dd.ecommerce_previous || {};
		el.innerHTML = card(__('Ecommerce'), '<div class="ntaa-kpis small">' + N.kpi(__('Revenue'), money(e.revenue, e.currency), 'money-alt', N.delta(e.revenue, p.revenue)) + N.kpi(__('Orders'), num(e.orders), 'cart', N.delta(e.orders, p.orders)) + N.kpi(__('Average order value'), money(e.aov, e.currency), 'chart-bar') + N.kpi(__('Conversion rate'), pct(e.conversion_rate), 'chart-line') + '</div>', { icon: 'cart', tools: link('ecommerce/overview', __('More')) });
	} });
	addWidget({ id: 'products', title: __('Top products'), icon: 'products', part: 'ecommerce', woo: true, render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Top products'), [{ key: 'name', label: __('Product'), fmt: function (v) { return N.other(v) || '<b>' + esc(v) + '</b>'; } }, { key: 'sold', label: __('Sold'), num: true, fmt: num }, { key: 'revenue', label: __('Revenue'), num: true, fmt: function (v) { return money(v); } }], dd.products, { bar: 'revenue', limit: 8, icon: 'products', evo: Object.assign(D.evo('product', function (r) { return String(r.id); }), { title: function (r) { return r.name; } }) });
	} });
	addWidget({ id: 'browsers', title: __('Browsers'), icon: 'admin-site', part: 'audience', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Browsers'), [D.nameCol(__('Browser')), { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'bounce_rate', label: __('Bounce'), num: true, fmt: N.bounceFmt }], dd.browsers, { bar: 'visits', limit: 8, icon: 'admin-site', evo: D.evo('browser') });
	} });
	addWidget({ id: 'returning', title: __('New vs returning'), icon: 'groups', part: 'engagement', render: function (el, dd, wid) {
		var r = dd.returning || [], lab = { 'new': __('New visits'), returning: __('Returning visits') };
		el.innerHTML = card(__('New vs returning'), r.length ? chartBox(wid + '-c') : N.empty(), { icon: 'groups' });
		if (r.length) N.doughnut(wid + '-c', r.map(function (x) { return lab[x.name] || x.name; }), r.map(function (x) { return x.visits; }));
	} });
	addWidget({ id: 'events', title: __('Events'), icon: 'marker', part: 'events', render: function (el, dd, wid) {
		el.innerHTML = table(wid, __('Events'), [{ key: 'category', label: __('Event'), fmt: function (v, r) { return N.other(v) || '<b>' + esc(v) + '</b> › ' + esc(r.action); } }, { key: 'total', label: __('Total'), num: true, fmt: num }], dd.events, { bar: 'total', limit: 8, icon: 'marker', evo: D.evo('event', function (r) { return r.category + N.SEP + r.action; }) });
	} });
	addWidget({ id: 'realtime', title: __('Real-time'), icon: 'clock', live: true, render: function (el) {
		N.api('realtime', { params: { site: N.state.site }, cache: false }).then(function (r) {
			el.innerHTML = card(__('Real-time'), '<div class="ntaa-w-rt"><span class="ntaa-dot big"></span><b>' + num(r.active_30) + '</b><span>' + esc(__('visitors in the last 30 minutes')) + '</span></div>' + table('w-rt-pages', '', [{ key: 'page', label: __('Page'), fmt: N.pageCell }, { key: 'views', label: __('Views'), num: true, fmt: num }], (r.pages || []).slice(0, 5), { bare: true, bar: 'views' }), { icon: 'clock', tools: link('dashboard/realtime', __('More')) });
		}).catch(function (e) { el.innerHTML = D.err(e); });
	} });
	addWidget({ id: 'annotations', title: __('Annotations'), icon: 'sticky', part: 'summary', render: function (el, dd) {
		var list = dd.annotations || [];
		el.innerHTML = card(__('Annotations'), list.length ? '<ul class="ntaa-ann-list">' + list.slice(-8).reverse().map(function (a) { return '<li><span class="ntaa-tag">' + esc(N.longDate(a.day)) + '</span> ' + esc(a.note) + '</li>'; }).join('') + '</ul>' : N.empty(__('No annotations in this period.')), { icon: 'sticky', tools: link('dashboard/overview', __('Add')) });
	} });

	function wg(id) { return widgets.filter(function (x) { return x.id === id; })[0]; }
	function available(x) { return x && (!x.woo || A.woo) && (!x.pro || A.pro); }

	var DEFAULT = [{ id: 'kpis', size: 'full' }, { id: 'chart', size: 'full' }, { id: 'channels', size: 'half' }, { id: 'pages', size: 'half' }, { id: 'map', size: 'half' }, { id: 'referrers', size: 'half' }, { id: 'devices', size: 'half' }, { id: 'goals', size: 'half' }];
	if (A.woo) DEFAULT.splice(2, 0, { id: 'ecommerce', size: 'full' });

	/* =============================================================
	 * My dashboard
	 * =========================================================== */
	var layout = null, editing = false;
	function saveLayout() {
		return N.api('dashboard-layout', { method: 'POST', body: { widgets: layout } }).catch(function (e) { N.toast(e.message, 'err'); });
	}
	function renderMine(view) {
		view.innerHTML = D.skeleton();
		var token = view._token;
		var load = layout ? Promise.resolve() : N.api('dashboard-layout', { cache: false }).then(function (r) { layout = r.widgets && r.widgets.length ? r.widgets : DEFAULT.slice(); });
		load.then(function () {
			if (view._token !== token) return;
			draw(view);
		}).catch(function (e) { view.innerHTML = D.err(e); });
	}
	function draw(view) {
		N.destroyCharts();
		var list = layout.filter(function (x) { return available(wg(x.id)); });
		view.innerHTML = '<div class="ntaa-dash-bar"><span class="ntaa-muted">' + esc(editing ? __('Drag widgets to reorder them. Changes are saved automatically.') : __('Your own dashboard: choose the widgets you want and arrange them.')) + '</span><span>' +
			(editing ? '<button type="button" class="button" data-w-add>' + icon('plus-alt2') + esc(__('Add widget')) + '</button> <button type="button" class="button-link" data-w-reset>' + esc(__('Reset to default')) + '</button> <button type="button" class="button button-primary" data-w-edit>' + esc(__('Done')) + '</button>' : '<button type="button" class="button" data-w-edit>' + icon('edit') + esc(__('Customize')) + '</button>') + '</span></div>' +
			'<div class="ntaa-wgrid' + (editing ? ' editing' : '') + '">' + list.map(function (x) {
				var def = wg(x.id), size = x.size || def.size || 'half';
				return '<div class="ntaa-widget size-' + size + '" data-w="' + esc(x.id) + '"' + (editing ? ' draggable="true"' : '') + '>' +
					(editing ? '<div class="ntaa-w-ctl"><span class="ntaa-w-drag" title="' + esc(__('Drag to move')) + '">' + icon('move') + esc(def.title) + '</span><span><button type="button" class="ntaa-icon-btn" data-w-size title="' + esc(size === 'full' ? __('Half width') : __('Full width')) + '">' + icon(size === 'full' ? 'image-flip-horizontal' : 'editor-expand') + '</button><button type="button" class="ntaa-icon-btn" data-w-rm title="' + esc(__('Remove')) + '">' + icon('no-alt') + '</button></span></div>' : '') +
					'<div class="ntaa-w-body">' + D.skeleton() + '</div></div>';
			}).join('') + '</div>' + (list.length ? '' : N.empty(__('No widgets. Click “Customize” and add some.')));
		bindBar(view);
		// Load each data part once, then render its widgets.
		var parts = {};
		list.forEach(function (x) { var def = wg(x.id); if (def.live) { def.render($('[data-w="' + x.id + '"] .ntaa-w-body', view), null, 'w-' + x.id); return; } (parts[def.part] = parts[def.part] || []).push(x.id); });
		var token = view._token;
		Object.keys(parts).forEach(function (part) {
			N.api('stats', { params: N.params({ part: part }) }).then(function (dd) {
				if (view._token !== token) return;
				parts[part].forEach(function (id) {
					var el = $('[data-w="' + id + '"] .ntaa-w-body', view);
					if (!el) return;
					try { wg(id).render(el, dd, 'w-' + id); } catch (e) { el.innerHTML = D.err(e); if (w.console) console.error(e); }
				});
			}).catch(function (e) { parts[part].forEach(function (id) { var el = $('[data-w="' + id + '"] .ntaa-w-body', view); if (el) el.innerHTML = D.err(e); }); });
		});
		if (editing) bindDrag(view);
	}
	function bindBar(view) {
		var eb = $('[data-w-edit]', view);
		if (eb) eb.addEventListener('click', function () { editing = !editing; draw(view); });
		var ab = $('[data-w-add]', view);
		if (ab) ab.addEventListener('click', function () {
			var have = {}; layout.forEach(function (x) { have[x.id] = 1; });
			var free = widgets.filter(function (x) { return available(x) && !have[x.id]; });
			var m = N.modal(__('Add widget'), free.length ? '<div class="ntaa-w-pick">' + free.map(function (x) { return '<button type="button" class="ntaa-w-opt" data-pick="' + esc(x.id) + '">' + icon(x.icon || 'chart-bar') + '<span>' + esc(x.title) + '</span></button>'; }).join('') + '</div>' : N.empty(__('All widgets are already on your dashboard.')));
			$$('[data-pick]', m.body).forEach(function (b) { b.addEventListener('click', function () { var def = wg(b.getAttribute('data-pick')); layout.push({ id: def.id, size: def.size || 'half' }); saveLayout(); m.close(); draw(view); }); });
		});
		var rb = $('[data-w-reset]', view);
		if (rb) rb.addEventListener('click', function () { if (!w.confirm(__('Reset your dashboard to the default widgets?'))) return; layout = DEFAULT.slice(); N.api('dashboard-layout', { method: 'POST', body: { mode: 'reset', widgets: null } }); draw(view); });
		$$('[data-w-rm]', view).forEach(function (b) { b.addEventListener('click', function () { var id = b.closest('[data-w]').getAttribute('data-w'); layout = layout.filter(function (x) { return x.id !== id; }); saveLayout(); draw(view); }); });
		$$('[data-w-size]', view).forEach(function (b) { b.addEventListener('click', function () { var id = b.closest('[data-w]').getAttribute('data-w'); layout.forEach(function (x) { if (x.id === id) x.size = x.size === 'full' ? 'half' : 'full'; }); saveLayout(); draw(view); }); });
	}
	function bindDrag(view) {
		var dragging = null;
		$$('.ntaa-widget', view).forEach(function (el) {
			el.addEventListener('dragstart', function (e) { dragging = el; el.classList.add('dragging'); try { e.dataTransfer.setData('text/plain', el.getAttribute('data-w')); e.dataTransfer.effectAllowed = 'move'; } catch (er) {} });
			el.addEventListener('dragend', function () { el.classList.remove('dragging'); dragging = null; $$('.ntaa-widget', view).forEach(function (x) { x.classList.remove('drop-before', 'drop-after'); }); });
			el.addEventListener('dragover', function (e) {
				if (!dragging || dragging === el) return;
				e.preventDefault();
				var r = el.getBoundingClientRect(), after = (e.clientY - r.top) > r.height / 2 || (el.classList.contains('size-half') && (N.RTL ? e.clientX < r.left + r.width / 2 : e.clientX > r.left + r.width / 2) && (e.clientY - r.top) > r.height / 4);
				el.classList.toggle('drop-after', after); el.classList.toggle('drop-before', !after);
			});
			el.addEventListener('dragleave', function () { el.classList.remove('drop-before', 'drop-after'); });
			el.addEventListener('drop', function (e) {
				e.preventDefault();
				if (!dragging || dragging === el) return;
				var from = dragging.getAttribute('data-w'), to = el.getAttribute('data-w'), after = el.classList.contains('drop-after');
				var item = layout.filter(function (x) { return x.id === from; })[0];
				layout = layout.filter(function (x) { return x.id !== from; });
				var idx = layout.findIndex(function (x) { return x.id === to; });
				layout.splice(after ? idx + 1 : idx, 0, item);
				saveLayout(); draw(view);
			});
		});
		// Keyboard-friendly alternative: move with arrow buttons.
		$$('.ntaa-w-drag', view).forEach(function (h) {
			h.setAttribute('tabindex', '0');
			h.addEventListener('keydown', function (e) {
				if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown' && e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
				e.preventDefault();
				var id = h.closest('[data-w]').getAttribute('data-w'), i = layout.findIndex(function (x) { return x.id === id; });
				var j = (e.key === 'ArrowUp' || e.key === 'ArrowLeft') ? i - 1 : i + 1;
				if (j < 0 || j >= layout.length) return;
				var t = layout[i]; layout[i] = layout[j]; layout[j] = t;
				saveLayout(); draw(view);
				var nh = $('[data-w="' + id + '"] .ntaa-w-drag', view); if (nh) nh.focus();
			});
		});
	}

	/* =============================================================
	 * Compare segments
	 * =========================================================== */
	var DIMS = [
		{ id: 'channel', label: __('Channel'), part: 'sources', rows: function (dd) { return dd.referrer_types; }, key: function (r) { return r.name; }, fmt: function (v) { return D.chName(v); }, metric: 'visits' },
		{ id: 'device', label: __('Device'), part: 'audience', rows: function (dd) { return dd.devices; }, key: function (r) { return r.name; }, fmt: function (v) { return D.devName(v); }, metric: 'visits' },
		{ id: 'country', label: __('Country'), part: 'audience', rows: function (dd) { return dd.countries; }, key: function (r) { return r.name; }, fmt: function (v) { return N.other(v) || N.country(v); }, metric: 'visits' },
		{ id: 'browser', label: __('Browser'), part: 'audience', rows: function (dd) { return dd.browsers; }, key: function (r) { return r.name; }, fmt: function (v) { return N.other(v) || esc(v); }, metric: 'visits' },
		{ id: 'campaign', label: __('Campaign'), part: 'sources', rows: function (dd) { return dd.campaigns; }, key: function (r) { return r.campaign; }, fmt: function (v) { return N.other(v) || esc(v || __('(not set)')); }, metric: 'visits' },
		{ id: 'entry', label: __('Entry page'), part: 'content', rows: function (dd) { return dd.entry_pages; }, key: function (r) { return r.page; }, fmt: function (v) { return N.pageCell(v); }, metric: 'entrances' },
		{ id: 'page', label: __('Page'), part: 'content', rows: function (dd) { return dd.pages; }, key: function (r) { return r.page; }, fmt: function (v) { return N.pageCell(v); }, metric: 'views' }
	];
	var CMP_METRICS = [
		['visitors', __('Visitors'), num], ['visits', __('Visits'), num], ['pageviews', __('Pageviews'), num], ['pages_per_visit', __('Pages per visit'), function (v) { return N.dec(v, 2); }],
		['bounce_rate', __('Bounce rate'), pct, true], ['avg_duration', __('Avg. visit duration'), dur], ['conversions', __('Goal conversions'), num], ['conversion_rate', __('Conversion rate'), pct]
	];
	if (A.woo) CMP_METRICS.push(['orders', __('Orders'), num], ['revenue', __('Revenue'), function (v) { return money(v); }]);
	var COLORS = ['#6366f1', '#f59e0b', '#10b981', '#ef4444'];

	function renderCompare(view) {
		view.innerHTML = D.skeleton();
		var token = view._token;
		N.api('segments', { cache: false }).then(function (meta) {
			if (view._token !== token) return;
			var opts = [{ key: 'all', name: __('All visits'), conds: null }];
			(meta.saved || []).forEach(function (s) { opts.push({ key: 's' + s.id, name: (s.pre ? '⚡ ' : '') + s.name, conds: s.conds }); });
			if (N.state.segment && N.state.segment.conds && N.state.segment.conds.length && !N.state.segment.id) opts.push({ key: 'current', name: __('Current segment') + (N.state.segment.name ? ': ' + N.state.segment.name : ''), conds: N.state.segment.conds });
			var sel = N.store('cmp_sel') || ['all'].concat(opts[1] ? [opts[1].key] : []);
			sel = sel.filter(function (k) { return opts.some(function (o) { return o.key === k; }); });
			if (!sel.length) sel = ['all'];
			var dim = N.store('cmp_dim') || 'channel', metric = N.store('cmp_metric') || 'visits';
			view.innerHTML = '<div class="ntaa-card"><div class="ntaa-card-head"><h3>' + icon('image-flip-horizontal') + esc(__('Compare segments')) + '</h3></div><p class="ntaa-muted">' + esc(__('Pick up to 4 segments to compare side by side for the selected period. Save segments in the segment editor (the filter button on any report) to see them here.')) + '</p>' +
				'<div class="ntaa-chips ntaa-cmp-pick">' + opts.map(function (o) { return '<label class="ntaa-chip"><input type="checkbox" value="' + esc(o.key) + '"' + (sel.indexOf(o.key) >= 0 ? ' checked' : '') + '> ' + esc(o.name) + '</label>'; }).join('') + '</div>' +
				(opts.length < 2 ? '<p>' + esc(__('You have no saved segments yet.')) + ' <button type="button" class="button" data-newseg>' + icon('filter') + esc(__('Create a segment')) + '</button></p>' : '') + '</div><div class="ntaa-cmp-out"></div>';
			var nb = $('[data-newseg]', view);
			if (nb) nb.addEventListener('click', function () { $('#ntaa-segbtn').click(); });
			$$('.ntaa-cmp-pick input', view).forEach(function (cb) {
				cb.addEventListener('change', function () {
					var on = $$('.ntaa-cmp-pick input:checked', view).map(function (x) { return x.value; });
					if (on.length > 4) { cb.checked = false; N.toast(__('You can compare up to 4 segments.'), 'err'); return; }
					sel = on; N.store('cmp_sel', sel); load();
				});
			});
			function load() {
				var out = $('.ntaa-cmp-out', view);
				var chosen = opts.filter(function (o) { return sel.indexOf(o.key) >= 0; });
				if (!chosen.length) { out.innerHTML = N.empty(__('Select at least one segment.')); return; }
				out.innerHTML = D.skeleton();
				var dm = DIMS.filter(function (x) { return x.id === dim; })[0] || DIMS[0];
				Promise.all(chosen.map(function (o) {
					var p = { from: N.state.from, to: N.state.to, site: N.state.site, part: dm.part };
					if (o.conds) p.segment = JSON.stringify(o.conds);
					return N.api('stats', { params: p });
				})).then(function (res) {
					if (view._token !== token) return;
					N.destroyCharts();
					var base = res[0].summary;
					var head = '<tr><th>' + esc(__('Metric')) + '</th>' + chosen.map(function (o, i) { return '<th class="num"><span class="ntaa-swatch" style="background:' + COLORS[i] + '"></span>' + esc(o.name) + '</th>'; }).join('') + '</tr>';
					var body = CMP_METRICS.map(function (m) {
						return '<tr' + (m[0] === metric ? ' class="on"' : '') + ' data-cm="' + m[0] + '"><td class="main">' + esc(m[1]) + '</td>' + res.map(function (r, i) {
							var v = r.summary[m[0]];
							return '<td class="num"><b>' + m[2](v) + '</b>' + (i ? ' ' + N.delta(v, base[m[0]], m[3]) : '') + '</td>';
						}).join('') + '</tr>';
					}).join('');
					var tsKey = { visitors: 'visitors', visits: 'visits', pageviews: 'pageviews', bounce_rate: 'bounce_rate', avg_duration: 'avg_duration', conversions: 'conversions', revenue: 'revenue', orders: 'orders' };
					var cm = CMP_METRICS.filter(function (m) { return m[0] === metric; })[0] || CMP_METRICS[1];
					var chartMetric = tsKey[cm[0]] ? cm : CMP_METRICS[1];
					var slow = res.some(function (r) { return r.segmented && !r.seg_fast; });
					out.innerHTML = card(__('Key metrics'), '<div class="ntaa-table-wrap"><table class="ntaa-table ntaa-cmp">' + '<thead>' + head + '</thead><tbody>' + body + '</tbody></table></div><p class="description">' + esc(__('Arrows compare each segment with the first column. Click a metric to chart it.')) + '</p>', { icon: 'editor-table' }) +
						card(chartMetric[1] + ' · ' + (res[0].timeseries.granularity === 'hour' ? __('hourly') : __('daily')), '<div class="ntaa-chart-box tall"><canvas id="ch-cmp"></canvas></div>', { icon: 'chart-line' }) +
						'<div class="ntaa-card"><div class="ntaa-card-head"><h3>' + icon('list-view') + esc(__('Breakdown')) + '</h3><select data-cdim>' + DIMS.map(function (x) { return '<option value="' + x.id + '"' + (x.id === dm.id ? ' selected' : '') + '>' + esc(x.label) + '</option>'; }).join('') + '</select></div><div class="ntaa-cmp-dim"></div></div>' +
						(slow ? D.note(esc(__('Some of these segments are calculated live. On busy sites save them with “Pre-process (fast)” for instant comparisons.')), 'info-outline') : '');
					// Chart.
					var hourly = res[0].timeseries.granularity === 'hour';
					var pts0 = res[0].timeseries.points || [];
					N.line('ch-cmp', pts0.map(function (p) { return hourly ? N.clock(p.date) : N.fmtDate(p.date); }), res.map(function (r, i) { return { label: chosen[i].name, data: (r.timeseries.points || []).map(function (p) { return p[tsKey[chartMetric[0]]]; }), color: COLORS[i], fill: false }; }), chartMetric[2]);
					$$('[data-cm]', out).forEach(function (tr) { tr.addEventListener('click', function () { metric = tr.getAttribute('data-cm'); N.store('cmp_metric', metric); load(); }); });
					$('[data-cdim]', out).addEventListener('change', function () { dim = this.value; N.store('cmp_dim', dim); load(); });
					// Breakdown table: rows of the first segment (top 15), values per segment.
					var maps = res.map(function (r) { var mp = {}, tot = 0; (dm.rows(r) || []).forEach(function (x) { mp[dm.key(x)] = x; tot += Number(x[dm.metric]) || 0; }); return { m: mp, tot: tot || 1 }; });
					var keys = [], seen = {};
					res.forEach(function (r) { (dm.rows(r) || []).forEach(function (x) { var k = dm.key(x); if (k !== '__other__' && !seen[k]) { seen[k] = 1; keys.push(k); } }); });
					keys.sort(function (a, b) { var va = maps[0].m[a] ? maps[0].m[a][dm.metric] : 0, vb = maps[0].m[b] ? maps[0].m[b][dm.metric] : 0; return vb - va; });
					keys = keys.slice(0, 20);
					var hasCr = dm.metric === 'visits' || dm.metric === 'entrances';
					var rows = keys.map(function (k) {
						return '<tr><td class="main">' + dm.fmt(k) + '</td>' + maps.map(function (mp) {
							var x = mp.m[k], v = x ? Number(x[dm.metric]) || 0 : 0;
							return '<td class="num">' + num(v) + ' <small class="ntaa-muted">' + pct(v / mp.tot * 100) + '</small>' + (hasCr && x && x.conversion_rate ? '<br><small class="ntaa-good">' + esc(N.sprintf(__('%s conv.'), pct(x.conversion_rate))) + '</small>' : '') + '</td>';
						}).join('') + '</tr>';
					}).join('');
					$('.ntaa-cmp-dim', out).innerHTML = keys.length ? '<div class="ntaa-table-wrap"><table class="ntaa-table ntaa-cmp"><thead><tr><th>' + esc(dm.label) + '</th>' + chosen.map(function (o, i) { return '<th class="num"><span class="ntaa-swatch" style="background:' + COLORS[i] + '"></span>' + esc(o.name) + '</th>'; }).join('') + '</tr></thead><tbody>' + rows + '</tbody></table></div><p class="description">' + esc(__('Value and share of the segment’s total.')) + '</p>' : N.empty();
				}).catch(function (e) { out.innerHTML = D.err(e); });
			}
			load();
		}).catch(function (e) { view.innerHTML = D.err(e); });
	}

	/* ---------- navigation ---------- */
	var sec = N.section('dashboard');
	if (!sec.items.some(function (x) { return x.id === 'mine'; })) sec.items.splice(1, 0, { id: 'mine', label: __('My dashboard'), icon: 'layout', render: renderMine });
	if (!sec.items.some(function (x) { return x.id === 'compare'; })) sec.items.push({ id: 'compare', label: __('Compare segments'), icon: 'image-flip-horizontal', noSegment: true, render: renderCompare });
})(window, document);
