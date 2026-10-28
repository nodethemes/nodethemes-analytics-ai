/* NodeThemes Analytics AI – dashboard app (left navigation, reports). Pro adds items through NTAA.addItem(). */
(function (w, d) {
	'use strict';
	var N = w.NTAA;
	if (!N) return;
	var A = N.A, __ = N.__, esc = N.esc, $ = N.$, $$ = N.$$, num = N.num, pct = N.pct, dur = N.dur, icon = N.icon, table = N.table, card = N.card, money = N.money;
	var CHANNEL = N.CHANNEL, DEVICE = N.DEVICE;

	/* ---------- helpers ---------- */
	function soft(sectionId, item) { var s = N.section(sectionId); if (!s.items.some(function (x) { return x.id === item.id; })) s.items.push(item); }
	function locked(sectionId, id, label, ic, text, features) {
		soft(sectionId, { id: id, label: label, icon: ic, pro: true, render: function (view) { view.innerHTML = N.proLock(label, text, features); } });
	}
	function err(e) { return '<div class="ntaa-card ntaa-error">' + icon('warning') + ' ' + esc(__('Could not load statistics:')) + ' ' + esc(e && e.message ? e.message : e) + '</div>'; }
	function skeleton() { return '<div class="ntaa-skeleton"><div></div><div></div><div></div><div></div></div>'; }
	function visitCols(first) {
		return [first,
			{ key: 'visits', label: __('Visits'), num: true, fmt: num },
			{ key: 'pageviews', label: __('Pageviews'), num: true, fmt: num },
			{ key: 'bounce_rate', label: __('Bounce'), num: true, fmt: N.bounceFmt },
			{ key: 'avg_duration', label: __('Avg. duration'), num: true, fmt: dur },
			{ key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: function (v) { return v ? pct(v) : '—'; }, tip: __('Visits that converted at least one goal') }
		].concat(A.woo ? [{ key: 'revenue', label: __('Revenue'), num: true, fmt: function (v) { return v ? money(v) : '—'; } }] : []);
	}
	function nameCol(label, fmt) { return { key: 'name', label: label, fmt: fmt || function (v) { return N.other(v) || esc(v === '' ? __('(not set)') : v); } }; }
	function chName(v) { return N.other(v) || esc(CHANNEL[v] || v || __('Unknown')); }
	function devName(v) { return N.other(v) || (icon(N.DEVICE_ICON[v] || 'desktop') + ' ' + esc(DEVICE[v] || v || __('Unknown'))); }
	function bucketTable(id, title, rows, ic, labelFmt) {
		return table(id, title, [nameCol(title, labelFmt), { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'share', label: __('Share'), num: true, fmt: pct }], share(rows), { bar: 'visits', icon: ic });
	}
	function share(rows) { var t = (rows || []).reduce(function (a, r) { return a + (Number(r.visits) || 0); }, 0) || 1; return (rows || []).map(function (r) { return Object.assign({ share: r.visits / t * 100 }, r); }); }
	var SEP = N.SEP;
	function evo(report, fn) { return { report: report, label: fn || function (r) { return String(r.name); } }; }
	function evoPage(report) { return evo(report, function (r) { return r.page; }); }
	function grid(html, n) { return '<div class="ntaa-grid-' + (n || 2) + '">' + html + '</div>'; }
	function note(text, ic) { return '<div class="ntaa-card ntaa-note">' + icon(ic || 'info-outline') + ' ' + text + '</div>'; }

	/* ---------- AI helpers (quick summary) ---------- */
	function hasAI() { return Object.keys(A.providers || {}).some(function (k) { return A.providers[k].configured; }); }
	function provider() {
		var p = N.store('ai_provider') || A.provider;
		if (!A.providers || !A.providers[p] || !A.providers[p].configured) p = Object.keys(A.providers || {}).filter(function (k) { return A.providers[k].configured; })[0] || A.provider;
		return p;
	}
	function thinking(el) {
		el.innerHTML = '<div class="ntaa-thinking"><span></span><span></span><span></span> ' + esc(__('Your AI is analyzing the data…')) + ' <b class="ntaa-elapsed">0</b>' + esc(__('s')) + '</div>';
		var t0 = Date.now(), tm = setInterval(function () { var s = $('.ntaa-elapsed', el); if (s) s.textContent = num(Math.round((Date.now() - t0) / 1000)); }, 1000);
		return function () { clearInterval(tm); return Math.round((Date.now() - t0) / 1000); };
	}
	N.hasAI = hasAI; N.provider = provider; N.thinking = thinking;

	/* =============================================================
	 * Dashboard › Overview
	 * =========================================================== */
	var METRICS = [
		{ id: 'visitors', label: __('Visitors'), fmt: num, icon: 'admin-users' },
		{ id: 'visits', label: __('Visits'), fmt: num, icon: 'randomize' },
		{ id: 'pageviews', label: __('Pageviews'), fmt: num, icon: 'visibility' },
		{ id: 'bounce_rate', label: __('Bounce rate'), fmt: pct, invert: true, icon: 'undo' },
		{ id: 'avg_duration', label: __('Avg. visit duration'), fmt: dur, icon: 'clock' },
		{ id: 'conversions', label: __('Goal conversions'), fmt: num, icon: 'flag' }
	];
	if (A.woo) METRICS.push({ id: 'revenue', label: __('Revenue'), fmt: function (v) { return money(v); }, icon: 'cart' });
	else METRICS.push({ id: 'pages_per_visit', label: __('Pages per visit'), fmt: function (v) { return N.dec(v, 1); }, icon: 'admin-page' });

	function metricValue(m, p) { return m.id === 'pages_per_visit' ? (p.visits ? Math.round(p.pageviews / p.visits * 100) / 100 : 0) : p[m.id]; }

	function weekdays() {
		var start = parseInt(A.startOfWeek || 0, 10), out = [];
		for (var i = 0; i < 7; i++) {
			var js = (start + i) % 7;
			var name = new Intl.DateTimeFormat(N.LOCALE, { weekday: 'short', timeZone: 'UTC' }).format(new Date(Date.UTC(2024, 0, 7 + js, 12)));
			out.push([js + 1, name]);
		}
		return out;
	}
	function heatmapCard(hm, title) {
		if (!hm) return '';
		var max = 0;
		Object.keys(hm).forEach(function (k) { (hm[k] || []).forEach(function (v) { max = Math.max(max, v); }); });
		var head = '<div class="ntaa-hm-row ntaa-hm-head"><span></span>' + Array.apply(null, Array(24)).map(function (_, h) { return '<i>' + (h % 3 === 0 ? num(h) : '') + '</i>'; }).join('') + '</div>';
		var rows = weekdays().map(function (wd) {
			return '<div class="ntaa-hm-row"><span>' + esc(wd[1]) + '</span>' + (hm[wd[0]] || []).map(function (v, h) {
				var a = max ? v / max : 0;
				return '<i style="--a:' + (a ? (0.12 + a * 0.88).toFixed(2) : 0) + '" title="' + esc(wd[1] + ' ' + num(h) + ':00 · ' + num(v) + ' ' + __('visits')) + '"></i>';
			}).join('') + '</div>';
		}).join('');
		return card(title || __('Busiest times (weekday × hour)'), '<div class="ntaa-heatmap">' + head + rows + '</div>', { icon: 'grid-view' });
	}

	function mainChart(dd, metric, canvas, titleEl) {
		var m = METRICS.filter(function (x) { return x.id === metric; })[0] || METRICS[0];
		var pts = dd.timeseries.points || [], hourly = dd.timeseries.granularity === 'hour';
		if (titleEl) titleEl.textContent = m.label + ' · ' + (hourly ? __('hourly') : __('daily'));
		var labels = pts.map(function (p) { return hourly ? N.fmtDate(p.date, { weekday: 'short' }) + ' ' + N.clock(p.date) : N.fmtDate(p.date); });
		var keys = pts.map(function (p) { return String(p.date).slice(0, 10); });
		var ds = [{ label: m.label, data: pts.map(function (p) { return metricValue(m, p); }) }];
		if (N.store('compare') && !hourly) {
			if (!dd._prev) {
				N.api('stats', { params: N.params({ from: dd.previous_range.from, to: dd.previous_range.to, part: 'summary' }) }).then(function (pd) { dd._prev = pd.timeseries.points; if ($('#' + canvas)) mainChart(dd, metric, canvas, titleEl); }).catch(function () {});
			} else {
				ds.push({ label: __('Previous period'), data: dd._prev.map(function (p) { return metricValue(m, p); }).slice(0, pts.length), color: '#94a3b8', borderDash: [6, 5], borderWidth: 2, fill: false, pointRadius: 0 });
			}
		}
		N.line(canvas, labels, ds, m.fmt, { annotations: hourly ? [] : (dd.annotations || []), keys: keys });
	}

	function annotationsCard(dd) {
		var list = dd.annotations || [];
		return card(__('Annotations'), (list.length ? '<ul class="ntaa-ann-list">' + list.map(function (a) {
			return '<li><span class="ntaa-tag">' + esc(N.longDate(a.day)) + '</span> ' + esc(a.note) + (A.canManage || +a.user_id === +A.userId ? ' <button type="button" class="ntaa-icon-btn" data-ann-del="' + Number(a.id) + '" aria-label="' + esc(__('Delete')) + '">' + icon('trash') + '</button>' : '') + '</li>';
		}).join('') + '</ul>' : '<p class="ntaa-muted">' + esc(__('Mark events such as a campaign launch, a site redesign or a price change. They appear as dotted lines on the chart.')) + '</p>'), {
			icon: 'sticky', tools: '<button type="button" class="button" id="ntaa-ann-add">' + icon('plus-alt2') + esc(__('Add annotation')) + '</button>'
		});
	}
	function bindAnnotations(view, refresh) {
		var add = $('#ntaa-ann-add', view);
		if (add) add.addEventListener('click', function () {
			var m = N.modal(__('Add annotation'), '<p><label>' + esc(__('Date')) + '<br><input type="date" data-day value="' + esc(A.today) + '" max="' + esc(A.today) + '"></label></p><p><label>' + esc(__('Note')) + '<br><input type="text" class="regular-text" data-note maxlength="255" placeholder="' + esc(__('e.g. Instagram campaign started')) + '"></label></p><p><button type="button" class="button button-primary" data-save>' + esc(__('Save')) + '</button></p>');
			$('[data-save]', m.body).addEventListener('click', function () {
				N.api('annotations', { method: 'POST', params: { site: N.state.site }, body: { day: $('[data-day]', m.body).value, note: $('[data-note]', m.body).value } }).then(function () { m.close(); N.toast(__('Annotation saved'), 'ok'); refresh(); }).catch(function (e) { N.toast(e.message, 'err'); });
			});
		});
		$$('[data-ann-del]', view).forEach(function (b) {
			b.addEventListener('click', function () {
				if (!w.confirm(__('Delete this annotation?'))) return;
				N.api('annotations/' + b.getAttribute('data-ann-del'), { method: 'DELETE' }).then(refresh).catch(function (e) { N.toast(e.message, 'err'); });
			});
		});
	}

	function renderOverview(view, dd, refresh) {
		var s = dd.summary, p = dd.previous, metric = N.store('metric') || 'visitors';
		if (!METRICS.some(function (m) { return m.id === metric; })) metric = 'visitors';
		var kpis = METRICS.map(function (m) {
			return '<button type="button" class="ntaa-kpi' + (metric === m.id ? ' active' : '') + '" data-metric="' + m.id + '"><span class="ntaa-kpi-label">' + icon(m.icon) + esc(m.label) + '</span><b>' + m.fmt(s[m.id]) + '</b>' + N.delta(s[m.id], p[m.id], m.invert) + '<small>' + esc(__('Previous period:')) + ' ' + m.fmt(p[m.id]) + '</small></button>';
		}).join('');
		var aiBtn = hasAI() ? '<button type="button" class="button button-primary" id="ntaa-quick">' + icon('lightbulb') + esc(N.sprintf(__('Analyze with %s'), (A.providers[provider()] || {}).label || 'AI')) + '</button>' : (A.canManage ? '<a class="button" href="' + esc(A.settingsUrl) + '#ai">' + esc(__('Connect an AI provider')) + '</a>' : '');
		var aiCard = A.canAI ? card(__('AI summary'), '<div id="ntaa-quick-out" class="ntaa-md ntaa-muted">' + esc(__('Your AI reviews the numbers for this period and gives you the five most important insights and one action to take this week.')) + ' <a href="' + esc(A.aiUrl) + '">' + esc(__('Deeper AI reports')) + ' →</a></div>', { icon: 'superhero', cls: 'ntaa-ai-quick', tools: aiBtn }) : '';
		var chans = dd.referrer_types || [], devices = dd.devices || [];
		view.innerHTML = '<div class="ntaa-kpis">' + kpis + '</div>' +
			card('', '<div class="ntaa-chart-box tall"><canvas id="ch-main" role="img" aria-label="' + esc(__('Traffic chart')) + '"></canvas></div>', { icon: 'chart-area', tools: '<label class="ntaa-compare"><input type="checkbox" id="ntaa-compare" ' + (N.store('compare') ? 'checked' : '') + '> ' + esc(__('Compare with previous period')) + '</label>' }) +
			aiCard +
			grid(
				table('ov-pages', __('Top pages'), [{ key: 'page', label: __('Page'), fmt: N.pageCell }, { key: 'views', label: __('Views'), num: true, fmt: num }, { key: 'avg_time', label: __('Time'), num: true, fmt: dur }], dd.pages, { bar: 'views', limit: 10, icon: 'media-document', evo: evoPage('page'), tools: '<a class="button-link" href="#/behaviour/pages">' + esc(__('All pages')) + '</a>' }) +
				card(__('Channels'), chans.length ? '<div class="ntaa-chart-box"><canvas id="ch-channels"></canvas></div>' : N.empty(), { icon: 'share-alt2' }) +
				card(__('Devices'), devices.length ? '<div class="ntaa-chart-box"><canvas id="ch-devices"></canvas></div>' : N.empty(), { icon: 'smartphone' }) +
				card(__('Visitor map'), mapSvg(dd.countries, 'visits'), { icon: 'admin-site-alt3', tools: '<a class="button-link" href="#/visitors/locations">' + esc(__('Locations')) + '</a>' })
			) + heatmapCard(dd.heatmap) + annotationsCard(dd);
		var cardHead = $('.ntaa-card h3', view);
		var titleEl = d.createElement('span'); cardHead.appendChild(titleEl);
		$$('[data-metric]', view).forEach(function (b) {
			b.addEventListener('click', function () {
				metric = b.getAttribute('data-metric'); N.store('metric', metric);
				$$('[data-metric]', view).forEach(function (x) { x.classList.toggle('active', x === b); });
				mainChart(dd, metric, 'ch-main', titleEl);
			});
		});
		$('#ntaa-compare', view).addEventListener('change', function () { N.store('compare', this.checked); mainChart(dd, metric, 'ch-main', titleEl); });
		mainChart(dd, metric, 'ch-main', titleEl);
		if (chans.length) N.doughnut('ch-channels', chans.map(function (c) { return CHANNEL[c.type] || c.type; }), chans.map(function (c) { return c.visits; }));
		if (devices.length) N.doughnut('ch-devices', devices.map(function (c) { return DEVICE[c.name] || c.name; }), devices.map(function (c) { return c.visits; }));
		bindMap(view);
		bindAnnotations(view, refresh);
		var qb = $('#ntaa-quick', view);
		if (qb) qb.addEventListener('click', function () {
			var out = $('#ntaa-quick-out', view);
			qb.disabled = true; out.classList.remove('ntaa-muted');
			var stop = thinking(out);
			var body = { type: 'quick', from: N.state.from, to: N.state.to, provider: provider(), site: N.state.site };
			if (N.state.segment && N.state.segment.conds) body.segment = N.state.segment.conds;
			N.api('ai/analyze', { method: 'POST', body: body }).then(function (r) {
				out.innerHTML = N.md(r.text) + '<div class="ntaa-ai-meta"><span dir="ltr">' + esc(r.model) + '</span> · <a href="' + esc(A.aiUrl) + '">' + esc(__('Deeper AI reports')) + ' →</a></div>';
			}).catch(function (e) { out.innerHTML = '<div class="ntaa-error">' + esc(e.message) + '</div>'; }).finally(function () { stop(); qb.disabled = false; });
		});
	}

	/* ---------- world map ---------- */
	function mapSvg(rows, key) {
		var M = w.NTAAWorldMap;
		if (!M) return N.empty();
		var vals = {}, max = 0, total = 0;
		(rows || []).forEach(function (r) { if (r.name && r.name.length === 2) { vals[r.name] = Number(r[key]) || 0; max = Math.max(max, vals[r.name]); total += vals[r.name]; } });
		if (!total) return N.empty();
		var paths = Object.keys(M.p).map(function (cc) {
			var v = vals[cc] || 0, a = v && max ? 0.18 + 0.82 * Math.sqrt(v / max) : 0;
			return '<path d="' + M.p[cc] + '" data-cc="' + cc + '" style="--a:' + a.toFixed(3) + '" class="' + (v ? 'on' : '') + '"><title>' + esc(N.countryName(cc) + ': ' + num(v) + ' (' + pct(v / total * 100) + ')') + '</title></path>';
		}).join('');
		return '<div class="ntaa-map" dir="ltr"><svg viewBox="0 0 ' + M.w + ' ' + M.h + '" role="img" aria-label="' + esc(__('Visitor map')) + '">' + paths + '</svg></div>' + (A.geo && A.geo.attribution ? '<p class="ntaa-attrib">' + A.geo.attribution + '</p>' : '');
	}
	function bindMap(view) {
		$$('.ntaa-map path.on', view).forEach(function (p) {
			p.addEventListener('click', function () {
				N.state.segment = { name: N.countryName(p.getAttribute('data-cc')), conds: [{ f: 'country', o: 'eq', v: [p.getAttribute('data-cc')] }] };
				N.store('segment', N.state.segment);
				N.toast(N.sprintf(__('Segment applied: %s'), N.state.segment.name), 'ok');
				refreshToolbar(); render();
			});
		});
	}

	/* =============================================================
	 * Real-time
	 * =========================================================== */
	var rtTimer = null;
	function renderRealtime(view) {
		view.innerHTML = skeleton();
		var token = view._token;
		function load() {
			if (d.hidden || view._token !== token) return;
			N.api('realtime', { params: { site: N.state.site }, cache: false }).then(function (r) {
				if (view._token !== token) return;
				if (!$('#rt-wrap', view)) {
					view.innerHTML = '<div id="rt-wrap"><div class="ntaa-grid-rt"><div class="ntaa-card ntaa-rt-big"><span class="ntaa-dot big"></span><b id="rt-30"></b><p>' + esc(__('visitors in the last 30 minutes')) + '</p><small id="rt-5"></small><small id="rt-q" class="ntaa-muted"></small></div>' +
						card(__('Pageviews per minute'), '<div class="ntaa-chart-box"><canvas id="ch-rt"></canvas></div>', { icon: 'chart-bar', tools: '<span class="ntaa-muted">' + esc(__('Refreshes every 15 seconds')) + '</span>' }) + '</div>' +
						grid('<div id="rt-recent"></div><div id="rt-side"></div>') + '</div>';
				}
				$('#rt-30', view).textContent = num(r.active_30);
				$('#rt-5', view).textContent = N.sprintf(__('%s in the last 5 minutes'), num(r.active_5));
				$('#rt-q', view).textContent = r.backlog > 0 ? N.sprintf(__('%s hits waiting in the processing queue'), num(r.backlog)) : '';
				var labels = r.minutes.map(function (m) { return N.clock(m.minute); }), vals = r.minutes.map(function (m) { return m.views; });
				var c = N.charts['ch-rt'];
				if (c) { c.data.labels = labels; c.data.datasets[0].data = vals; c.update('none'); } else N.vbar('ch-rt', labels, [{ data: vals, backgroundColor: '#06b6d4' }]);
				$('#rt-recent', view).innerHTML = table('rt-recent', __('Latest pageviews'), [
					{ key: 'created_at', label: __('Time'), fmt: function (v) { return '<span class="ntaa-muted">' + esc(N.clock(v)) + '</span>'; } },
					{ key: 'page', label: __('Page'), fmt: N.pageCell },
					{ key: 'country', label: __('Visitor'), fmt: function (v, row) { return '<span title="' + esc(row.browser + ' / ' + row.os + (row.city ? ' / ' + row.city : '')) + '">' + N.flag(v) + ' ' + icon(N.DEVICE_ICON[row.device] || 'desktop') + '</span> <small class="ntaa-muted">' + esc(row.referrer_host || CHANNEL[row.referrer_type] || '') + '</small>'; } }
				], r.recent, { icon: 'update', onRow: function (row) { openVisit(row.visit_id); } });
				$('#rt-side', view).innerHTML = table('rt-pages', __('Active pages right now'), [{ key: 'page', label: __('Page'), fmt: N.pageCell }, { key: 'views', label: __('Views'), num: true, fmt: num }], r.pages, { bar: 'views', icon: 'media-document' }) +
					table('rt-geo', __('Countries right now'), [{ key: 'country', label: __('Country'), fmt: N.country }, { key: 'visitors', label: __('Visitors'), num: true, fmt: num }], (r.countries || []).sort(function (a, b) { return b.visitors - a.visitors; }), { bar: 'visitors', icon: 'admin-site-alt3' });
			}).catch(function (e) { view.innerHTML = err(e); });
		}
		load();
		rtTimer = setInterval(load, 15000);
	}

	/* =============================================================
	 * Visitors
	 * =========================================================== */
	function actionLine(a) {
		var t = '<span class="ntaa-muted">' + esc(N.clock(a.created_at)) + '</span> ';
		if (a.type === 'page') return t + icon('media-document') + N.pageCell(a.page, a) + (a.time_on_page ? ' <small class="ntaa-muted">' + dur(a.time_on_page) + (a.scroll_depth ? ' · ' + pct(a.scroll_depth) + ' ' + esc(__('scrolled')) : '') + '</small>' : '');
		if (a.type === 'search') return t + icon('search') + esc(__('Site search:')) + ' <b>' + esc(a.search_term) + '</b> <small class="ntaa-muted">' + (a.search_count === 0 ? esc(__('no results')) : (a.search_count > 0 ? N.sprintf(esc(__('%s results')), num(a.search_count)) : '')) + '</small>';
		if (a.type === 'event') return t + icon('marker') + '<b>' + esc(a.category) + '</b> › ' + esc(a.action) + (a.label ? ' <small>' + esc(a.label) + '</small>' : '') + (a.value ? ' <span class="ntaa-tag">' + num(a.value) + '</span>' : '');
		if (a.type === 'goal') return t + icon('flag') + '<b class="ntaa-good">' + esc(__('Goal:')) + ' ' + esc(a.name) + '</b>' + (Number(a.value) ? ' <span class="ntaa-tag">' + money(a.value) + '</span>' : '');
		if (a.type === 'order') return t + icon('cart') + '<b class="ntaa-good">' + esc(N.sprintf(__('Order #%s'), a.order_id)) + '</b> ' + money(a.revenue) + ' <small class="ntaa-muted">' + esc(N.sprintf(__('%s items'), num(a.items))) + ' · ' + esc(a.status) + '</small>';
		var custom = N.actionRenderers && N.actionRenderers[a.type];
		return t + (custom ? custom(a) : esc(a.type));
	}
	function visitSummary(v) {
		var src = v.utm_campaign ? icon('megaphone') + esc(v.utm_campaign) + ' <small>(' + esc(v.utm_source || '') + ')</small>' : (v.referrer_host ? '<span dir="ltr">' + esc(v.referrer_host) + '</span>' : esc(CHANNEL[v.referrer_type] || __('Direct')));
		var where = N.country(v.country) + (v.city ? ' · ' + esc(v.city) : (v.region ? ' · ' + esc(v.region) : ''));
		return '<div class="ntaa-visit-meta">' +
			'<span>' + where + '</span><span>' + icon(N.DEVICE_ICON[v.device] || 'desktop') + esc((v.browser || '') + ' / ' + (v.os || '')) + '</span><span>' + src + '</span>' +
			'<span>' + icon('visibility') + num(v.pageviews) + ' · ' + icon('clock') + dur(v.duration) + '</span>' +
			(v.visit_number > 1 ? '<span class="ntaa-tag">' + esc(N.sprintf(__('Visit #%s'), num(v.visit_number))) + '</span>' : '') +
			(v.goal_conversions ? '<span class="ntaa-tag good">' + icon('flag') + num(v.goal_conversions) + '</span>' : '') +
			(v.orders ? '<span class="ntaa-tag good">' + icon('cart') + money(v.revenue) + '</span>' : '') + '</div>';
	}
	function openVisit(id) {
		if (!id) return;
		var m = N.modal(__('Visit details'), skeleton(), { wide: true });
		N.api('visits/' + id).then(function (v) {
			m.body.innerHTML = '<p class="ntaa-muted">' + esc(N.dateTime(v.started_at)) + '</p>' + visitSummary(v) +
				'<ol class="ntaa-timeline">' + (v.actions || []).map(function (a) { return '<li class="t-' + esc(a.type) + '">' + actionLine(a) + '</li>'; }).join('') + '</ol>' +
				'<p class="ntaa-visit-tools">' + (v.visitor_id ? '<button type="button" class="button" data-profile="' + esc(v.visitor_id) + '">' + icon('admin-users') + esc(__('Visitor profile')) + '</button> ' : '') +
				(N.visitTools ? N.visitTools(v) : '') +
				(A.canManage ? '<button type="button" class="button button-link-delete" data-gdpr="' + Number(v.id) + '">' + esc(__('Delete this visit (GDPR)')) + '</button>' : '') + '</p>';
			var pb = $('[data-profile]', m.body);
			if (pb) pb.addEventListener('click', function () { m.close(); openProfile(pb.getAttribute('data-profile')); });
			var g = $('[data-gdpr]', m.body);
			if (g) g.addEventListener('click', function () {
				if (!w.confirm(__('Delete this visit and everything recorded in it? This cannot be undone.'))) return;
				N.api('gdpr/delete-visitor', { method: 'POST', body: { visit_id: v.id } }).then(function () { m.close(); N.toast(__('Visit deleted'), 'ok'); render(); });
			});
		}).catch(function (e) { m.body.innerHTML = err(e); });
	}
	function openProfile(vid) {
		var m = N.modal(__('Visitor profile'), skeleton(), { wide: true });
		N.api('visitor/' + vid).then(function (p) {
			var s = p.summary || {};
			m.body.innerHTML = '<div class="ntaa-kpis small">' + N.kpi(__('Visits'), num(s.visits)) + N.kpi(__('Pageviews'), num(s.pageviews)) + N.kpi(__('Total time'), dur(s.duration)) + N.kpi(__('Conversions'), num(s.conversions)) + (A.woo ? N.kpi(__('Revenue'), money(s.revenue)) : '') + '</div>' +
				'<p class="ntaa-muted">' + esc(N.sprintf(__('First visit %1$s · last visit %2$s'), N.dateTime(s.first), N.dateTime(s.last))) + ' · ' + esc(__('Visitor ID')) + ' <code dir="ltr">' + esc(vid) + '</code></p>' +
				table('profile-visits', __('All visits'), [
					{ key: 'started_at', label: __('Date'), fmt: function (v) { return esc(N.dateTime(v)); } },
					{ key: 'entry_page', label: __('Entry page'), fmt: N.pageCell },
					{ key: 'referrer_type', label: __('Source'), fmt: function (v, r) { return esc(r.referrer_host || CHANNEL[v] || v); } },
					{ key: 'pageviews', label: __('Pages'), num: true, fmt: num },
					{ key: 'duration', label: __('Duration'), num: true, fmt: dur },
					{ key: 'goal_conversions', label: __('Goals'), num: true, fmt: num }
				], p.visits, { icon: 'backup', onRow: function (r) { m.close(); openVisit(r.id); } }) +
				(A.canManage ? '<p><button type="button" class="button button-link-delete" data-gdpr>' + esc(__('Delete all data of this visitor (GDPR)')) + '</button></p>' : '');
			var g = $('[data-gdpr]', m.body);
			if (g) g.addEventListener('click', function () {
				if (!w.confirm(__('Delete every visit of this visitor? This cannot be undone.'))) return;
				N.api('gdpr/delete-visitor', { method: 'POST', body: { visitor_id: vid } }).then(function (r) { m.close(); N.toast(N.sprintf(__('%s visits deleted'), num(r.deleted)), 'ok'); render(); });
			});
		}).catch(function (e) { m.body.innerHTML = err(e); });
	}
	N.openVisit = openVisit;

	function renderLog(view) {
		var page = 1;
		function load() {
			view.innerHTML = skeleton();
			N.api('visits', { params: N.params({ page: page, per_page: 25 }) }).then(function (r) {
				var rows = r.rows || [];
				view.innerHTML = '<div class="ntaa-card"><div class="ntaa-card-head"><h3>' + icon('list-view') + esc(__('Visits log')) + ' <span class="ntaa-muted">' + esc(N.sprintf(__('%s visits'), num(r.total))) + '</span></h3></div>' +
					(rows.length ? '<ul class="ntaa-visits">' + rows.map(function (v) {
						return '<li tabindex="0" data-visit="' + Number(v.id) + '"><div class="ntaa-visit-head"><b>' + esc(N.dateTime(v.started_at)) + '</b>' + visitSummary(v) + '</div><div class="ntaa-visit-path">' + icon('migrate') + N.pageCell(v.entry_page) + (v.exit_page && v.exit_page !== v.entry_page ? ' → ' + N.pageCell(v.exit_page) : '') + '</div></li>';
					}).join('') + '</ul>' : N.empty()) +
					(r.pages > 1 ? '<div class="ntaa-pager"><button type="button" class="button" data-p="-1" ' + (page <= 1 ? 'disabled' : '') + '>' + esc(__('Newer')) + '</button><span>' + esc(N.sprintf(__('Page %1$s of %2$s'), num(page), num(r.pages))) + '</span><button type="button" class="button" data-p="1" ' + (page >= r.pages ? 'disabled' : '') + '>' + esc(__('Older')) + '</button></div>' : '') + '</div>' +
					(A.cookieless ? note(esc(__('Cookieless mode is on, so each visitor gets a new anonymous ID every day and visitor profiles are not available. You can switch to cookie mode in Settings › Privacy.')), 'shield') : '');
				$$('[data-visit]', view).forEach(function (li) {
					li.addEventListener('click', function (e) { if (!e.target.closest('a')) openVisit(li.getAttribute('data-visit')); });
					li.addEventListener('keydown', function (e) { if (e.key === 'Enter') openVisit(li.getAttribute('data-visit')); });
				});
				$$('[data-p]', view).forEach(function (b) { b.addEventListener('click', function () { page += +b.getAttribute('data-p'); load(); }); });
			}).catch(function (e) { view.innerHTML = err(e); });
		}
		load();
	}

	function renderLocations(view, dd) {
		var geoInfo = A.geo || {};
		var geoNote = !geoInfo.active ? note(esc(__('No GeoIP database is installed yet, so countries are estimated from the browser time zone and cities are not available.')) + (A.canManage ? ' <a href="' + esc(A.settingsUrl) + '#privacy">' + esc(__('Install a GeoIP database')) + '</a>' : ''), 'warning') : '';
		view.innerHTML = geoNote + card(__('Visitor map'), mapSvg(dd.countries, 'visits'), { icon: 'admin-site-alt3', cls: 'ntaa-map-card' }) +
			grid(
				table('countries', __('Countries'), visitCols({ key: 'name', label: __('Country'), fmt: function (v) { return N.other(v) || N.country(v); }, csv: function (v) { return v; } }), dd.countries, { bar: 'visits', limit: 15, icon: 'flag', evo: evo('country') }) +
				table('regions', __('Regions'), [{ key: 'region', label: __('Region'), fmt: function (v, r) { return N.other(v) || (N.flag(r.country) + ' ' + esc(v || __('Unknown'))); } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'bounce_rate', label: __('Bounce'), num: true, fmt: N.bounceFmt }, { key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: function (v) { return v ? pct(v) : '—'; } }], dd.regions, { bar: 'visits', limit: 15, icon: 'location-alt', evo: evo('region', function (r) { return r.country + SEP + r.region; }) }) +
				table('cities', __('Cities'), [{ key: 'city', label: __('City'), fmt: function (v, r) { return N.other(v) || (N.flag(r.country) + ' ' + esc(v || __('Unknown')) + (r.region ? ' <small class="ntaa-muted">' + esc(r.region) + '</small>' : '')); } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'avg_duration', label: __('Avg. duration'), num: true, fmt: dur }], dd.cities, { bar: 'visits', limit: 15, icon: 'location', evo: evo('city', function (r) { return r.country + SEP + r.region + SEP + r.city; }), empty: geoInfo.city ? '' : __('City data needs a city-level GeoIP database (Settings › Privacy).') }) +
				table('languages', __('Browser languages'), [{ key: 'name', label: __('Language'), fmt: function (v) { return N.other(v) || N.language(v); } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }], dd.languages, { bar: 'visits', limit: 15, icon: 'translation', evo: evo('language') })
			);
		bindMap(view);
	}

	function renderDevices(view, dd) {
		view.innerHTML = grid(
			card(__('Device type'), (dd.devices || []).length ? '<div class="ntaa-chart-box"><canvas id="ch-dev"></canvas></div>' : N.empty(), { icon: 'smartphone' }) +
			card(__('Browsers'), (dd.browsers || []).length ? '<div class="ntaa-chart-box"><canvas id="ch-br"></canvas></div>' : N.empty(), { icon: 'admin-site' }) +
			card(__('Operating systems'), (dd.os || []).length ? '<div class="ntaa-chart-box"><canvas id="ch-os"></canvas></div>' : N.empty(), { icon: 'desktop' }) +
			table('screens', __('Screen size'), [{ key: 'name', label: __('Screen'), fmt: function (v) { return N.other(v) || '<span dir="ltr">' + esc(v || '—') + '</span>'; } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }], dd.screens, { bar: 'visits', limit: 10, icon: 'image-crop', evo: evo('screen') })
		) + grid(
			table('devices', __('Device type'), visitCols(nameCol(__('Device'), devName)), dd.devices, { bar: 'visits', icon: 'smartphone', evo: evo('device') }) +
			table('browsers', __('Browsers'), visitCols(nameCol(__('Browser'))), dd.browsers, { bar: 'visits', limit: 10, icon: 'admin-site', evo: evo('browser') }) +
			table('oss', __('Operating systems'), visitCols(nameCol(__('System'))), dd.os, { bar: 'visits', limit: 10, icon: 'desktop', evo: evo('os') })
		);
		if ((dd.devices || []).length) N.doughnut('ch-dev', dd.devices.map(function (x) { return DEVICE[x.name] || x.name; }), dd.devices.map(function (x) { return x.visits; }));
		if ((dd.browsers || []).length) N.hbar('ch-br', dd.browsers.slice(0, 7).map(function (x) { return x.name; }), dd.browsers.slice(0, 7).map(function (x) { return x.visits; }), '#6366f1');
		if ((dd.os || []).length) N.hbar('ch-os', dd.os.slice(0, 7).map(function (x) { return x.name; }), dd.os.slice(0, 7).map(function (x) { return x.visits; }), '#06b6d4');
	}

	function renderDims(view, dd) {
		var dims = dd.dims || [];
		if (!dims.length) {
			view.innerHTML = card(__('Custom dimensions'), '<p>' + esc(__('Custom dimensions let you record your own information per visit, e.g. membership level, A/B group or author.')) + '</p><pre class="ntaa-code" dir="ltr">ntaa.setCustomDimension(1, \'gold-member\');</pre>' + (A.canManage ? '<p><a class="button" href="' + esc(A.settingsUrl) + '#tracking">' + esc(__('Name your dimensions in Settings')) + '</a></p>' : ''), { icon: 'tag' });
			return;
		}
		view.innerHTML = grid(dims.map(function (dm) { return table('dim' + dm.index, dm.name, visitCols(nameCol(dm.name)), dm.rows, { bar: 'visits', limit: 15, icon: 'tag', evo: evo('dim' + dm.index) }); }).join(''));
	}

	function renderEngagement(view, dd) {
		var ret = dd.returning || [], newV = 0, retV = 0;
		ret.forEach(function (r) { if (r.name === 'new') newV = r.visits; else if (r.name === 'returning') retV = r.visits; });
		var label = { 'new': __('New visits'), returning: __('Returning visits') };
		view.innerHTML = (A.cookieless ? note(esc(__('Cookieless mode is on: visitors cannot be recognised on another day, so “returning”, “visit number” and “days since last visit” only count repeat visits on the same day.')), 'shield') : '') +
			grid(
				card(__('New vs returning'), (newV + retV) ? '<div class="ntaa-chart-box"><canvas id="ch-ret"></canvas></div>' : N.empty(), { icon: 'groups' }) +
				table('returning', __('New vs returning'), visitCols(nameCol(__('Type'), function (v) { return esc(label[v] || v); })), ret, { icon: 'groups' }) +
				bucketTable('duration_b', __('Visit duration'), dd.duration_b, 'clock') +
				bucketTable('pages_b', __('Pages per visit'), dd.pages_b, 'admin-page') +
				bucketTable('visitnum_b', __('Visit number'), dd.visitnum_b, 'backup') +
				bucketTable('dayssince_b', __('Days since last visit'), dd.dayssince_b, 'calendar')
			) +
			card(__('Visits per hour (site time)'), '<div class="ntaa-chart-box"><canvas id="ch-hours"></canvas></div>', { icon: 'clock' }) +
			card(__('Visits per hour (visitor’s local time)'), '<div class="ntaa-chart-box"><canvas id="ch-lhours"></canvas></div>', { icon: 'admin-site-alt3' }) +
			heatmapCard(dd.heatmap);
		if (newV + retV) N.doughnut('ch-ret', [label['new'], label.returning], [newV, retV]);
		function hours(rows) { var a = []; for (var i = 0; i < 24; i++) a.push(0); (rows || []).forEach(function (r) { var h = parseInt(r.name, 10); if (h >= 0 && h < 24) a[h] = r.visits; }); return a; }
		var hl = []; for (var i = 0; i < 24; i++) hl.push(num(i) + ':00');
		N.vbar('ch-hours', hl, [{ label: __('Visits'), data: hours(dd.hours) }]);
		N.vbar('ch-lhours', hl, [{ label: __('Visits'), data: hours(dd.localhours), backgroundColor: '#06b6d4' }]);
	}

	/* =============================================================
	 * Behaviour
	 * =========================================================== */
	function transitions(page) {
		var m = N.modal(__('Transitions'), skeleton(), { wide: true });
		N.api('transitions', { params: N.params({ page: page }) }).then(function (t) {
			var ent = (t.entries || []).reduce(function (a, r) { return a + r.n; }, 0);
			function list(rows, kind) {
				if (!rows.length) return '<p class="ntaa-muted">—</p>';
				return '<ul class="ntaa-flow ' + kind + '">' + rows.map(function (r) { return '<li><span>' + (r.page !== undefined ? N.pageCell(r.page) : esc(CHANNEL[r.channel] || r.channel)) + '</span><b>' + num(r.n) + '</b><small>' + pct(t.views ? r.n / t.views * 100 : 0) + '</small></li>'; }).join('') + '</ul>';
			}
			m.body.innerHTML = (t.sampled ? '<p class="ntaa-muted">' + esc(N.sprintf(__('Busy page: based on the latest %s views and scaled to all views.'), num(t.sampled))) + '</p>' : '') + '<div class="ntaa-transitions"><div><h4>' + esc(__('Came from')) + '</h4><h5>' + esc(__('Entrances')) + ' · ' + num(ent) + '</h5>' + list(t.entries || [], 'in') + '<h5>' + esc(__('Internal pages')) + '</h5>' + list(t.previous || [], 'in') + '</div>' +
				'<div class="ntaa-tr-center"><div>' + N.pageCell(t.page) + '</div><b>' + num(t.views) + '</b><span>' + esc(__('pageviews')) + '</span>' + ((t.events || []).length ? '<h5>' + esc(__('Events on this page')) + '</h5><ul class="ntaa-flow">' + t.events.map(function (e) { return '<li><span>' + esc(e.category) + ' › ' + esc(e.action) + '</span><b>' + num(e.n) + '</b></li>'; }).join('') + '</ul>' : '') + '</div>' +
				'<div><h4>' + esc(__('Went to')) + '</h4><h5>' + esc(__('Exits')) + ' · ' + num(t.exits) + ' (' + pct(t.views ? t.exits / t.views * 100 : 0) + ')</h5><h5>' + esc(__('Internal pages')) + '</h5>' + list(t.next || [], 'out') + '</div></div>';
		}).catch(function (e) { m.body.innerHTML = err(e); });
	}
	N.transitions = transitions;

	function renderPages(view, dd) {
		var tools = N.pageTools || null;
		view.innerHTML = table('pages', __('All pages'), [
			{ key: 'page', label: __('Page'), fmt: N.pageCell },
			{ key: 'views', label: __('Views'), num: true, fmt: num },
			{ key: 'unique_views', label: __('Unique'), num: true, fmt: num },
			{ key: 'avg_time', label: __('Avg. time'), num: true, fmt: dur },
			{ key: 'avg_scroll', label: __('Scroll depth'), num: true, fmt: function (v) { return v ? '<span class="ntaa-meter" style="--v:' + Number(v) + '%"></span>' + pct(v) : '—'; } },
			{ key: 'avg_load', label: __('Load time'), num: true, fmt: function (v) { return N.speed(v, 2500, 4500); } },
			{ key: 'page', label: '', fmt: function (v) { return v === '__other__' ? '' : '<button type="button" class="button-link" data-tr="' + esc(v) + '">' + esc(__('Transitions')) + '</button>' + (tools ? tools(v) : ''); }, csv: function () { return ''; } }
		], dd.pages, { bar: 'views', icon: 'media-document', limit: 50, evo: evoPage('page'), intro: esc(__('Click “Transitions” to see where visitors came from and where they went next. The chart icon next to a row shows its daily evolution.')) });
		view.addEventListener('click', function (e) { var b = e.target.closest('[data-tr]'); if (b) transitions(b.getAttribute('data-tr')); });
	}
	function renderTitles(view, dd) {
		view.innerHTML = (dd.titles_pending ? note(esc(__('Page titles were added in version 2.1. Older days are being prepared in the background, so totals for long periods may still be incomplete.')), 'update') : '') +
			table('titles', __('Page titles'), [
				{ key: 'title', label: __('Page title'), fmt: function (v, r) { return N.other(v) || '<b>' + esc(v || __('(no title)')) + '</b>' + (r.page ? ' <small class="ntaa-muted ntaa-path" dir="ltr">' + esc(r.page) + '</small>' : ''); } },
				{ key: 'views', label: __('Views'), num: true, fmt: num },
				{ key: 'unique_views', label: __('Unique'), num: true, fmt: num },
				{ key: 'avg_time', label: __('Avg. time'), num: true, fmt: dur },
				{ key: 'avg_scroll', label: __('Scroll depth'), num: true, fmt: function (v) { return v ? '<span class="ntaa-meter" style="--v:' + Number(v) + '%"></span>' + pct(v) : '—'; } },
				{ key: 'avg_load', label: __('Load time'), num: true, fmt: function (v) { return N.speed(v, 2500, 4500); } }
			], dd.page_titles, { bar: 'views', icon: 'editor-textcolor', limit: 50, evo: evo('ptitle', function (r) { return r.title; }), intro: esc(__('Pages grouped by their title, which is useful when the same content is reachable under several addresses (filters, tracking parameters, translations). The address shown is one example.')) });
	}
	function renderEntryExit(view, dd) {
		view.innerHTML = grid(
			table('entry', __('Entry pages'), [{ key: 'page', label: __('Page'), fmt: N.pageCell }, { key: 'entrances', label: __('Entrances'), num: true, fmt: num }, { key: 'bounce_rate', label: __('Bounce'), num: true, fmt: N.bounceFmt }, { key: 'avg_duration', label: __('Avg. duration'), num: true, fmt: dur }, { key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: function (v) { return v ? pct(v) : '—'; } }], dd.entry_pages, { bar: 'entrances', icon: 'migrate', limit: 25, evo: evoPage('entry') }) +
			table('exit', __('Exit pages'), [{ key: 'page', label: __('Page'), fmt: N.pageCell }, { key: 'exits', label: __('Exits'), num: true, fmt: num }], dd.exit_pages, { bar: 'exits', icon: 'external', limit: 25, evo: evoPage('exit') })
		);
	}
	function renderSearch(view, dd) {
		var total = (dd.site_search || []).reduce(function (a, r) { return a + (r.searches || 0); }, 0);
		var none = (dd.site_search || []).reduce(function (a, r) { return a + (r.no_results || 0); }, 0);
		view.innerHTML = '<div class="ntaa-kpis small">' + N.kpi(__('Searches'), num(total), 'search') + N.kpi(__('Without results'), num(none) + ' <small>(' + pct(total ? none / total * 100 : 0) + ')</small>', 'dismiss') + N.kpi(__('Unique terms'), num((dd.site_search || []).length), 'editor-ol') + '</div>' +
			grid(
				table('search', __('Search terms'), [{ key: 'term', label: __('Search term'), fmt: function (v) { return N.other(v) || '<b>' + esc(v) + '</b>'; } }, { key: 'searches', label: __('Searches'), num: true, fmt: num }, { key: 'no_results', label: __('No results'), num: true, fmt: function (v) { return v ? '<span class="ntaa-bad">' + num(v) + '</span>' : '—'; } }], dd.site_search, { bar: 'searches', icon: 'search', limit: 25, evo: evo('search', function (r) { return r.term; }) }) +
				table('search-none', __('Searches without results'), [{ key: 'term', label: __('Search term'), fmt: function (v) { return '<b>' + esc(v) + '</b>'; } }, { key: 'searches', label: __('Searches'), num: true, fmt: num }], dd.search_no_results, { bar: 'searches', icon: 'dismiss', intro: esc(__('Visitors looked for these but found nothing — ideas for new content or products.')), empty: __('Great — every search returned results.') })
			) + note(esc(__('WordPress and WooCommerce searches are recorded automatically. For another search engine, set its URL parameter in Settings › Tracking.')));
	}
	function renderEvents(view, dd) {
		view.innerHTML = table('events', __('Events'), [
			{ key: 'category', label: __('Category'), fmt: function (v) { return N.other(v) || '<b>' + esc(v) + '</b>'; } },
			{ key: 'action', label: __('Action'), fmt: esc },
			{ key: 'total', label: __('Total'), num: true, fmt: num },
			{ key: 'visits', label: __('Visits'), num: true, fmt: num },
			{ key: 'value_sum', label: __('Value'), num: true, fmt: function (v) { return v ? num(v) : '—'; } }
		], dd.events, { bar: 'total', icon: 'marker', limit: 30, evo: evo('event', function (r) { return r.category + SEP + r.action; }) }) +
			table('event-labels', __('Event labels'), [
				{ key: 'category', label: __('Event'), fmt: function (v, r) { return N.other(v) || esc(v) + ' › ' + esc(r.action); } },
				{ key: 'label', label: __('Label'), fmt: function (v) { return /^https?:\/\//.test(v) ? '<a href="' + esc(v) + '" target="_blank" rel="noopener" dir="ltr" class="ntaa-path">' + esc(v) + '</a>' : esc(v); } },
				{ key: 'total', label: __('Total'), num: true, fmt: num }
			], dd.event_labels, { bar: 'total', icon: 'tag', limit: 30, evo: evo('evlabel', function (r) { return r.category + SEP + r.action + SEP + r.label; }) }) +
			card(__('Track your own events'), '<pre class="ntaa-code" dir="ltr">ntaa.track(\'Category\', \'Action\', \'Label\', 49.99);\n&lt;button data-ntaa-event="CTA|signup|header"&gt;Sign up&lt;/button&gt;\n&lt;?php ntaa_track_event( \'Newsletter\', \'signup\', \'footer\' ); ?&gt;</pre>', { icon: 'editor-code' });
	}
	function renderPerformance(view, dd) {
		var p = dd.performance || {};
		function vital(label, v, good, poor, fmt, goodPct, help) {
			var c = !v ? '' : (v <= good ? 'good' : (v <= poor ? 'mid' : 'bad'));
			return '<div class="ntaa-kpi ntaa-vital ' + c + '"><span class="ntaa-kpi-label">' + esc(label) + '</span><b>' + (v ? (fmt ? fmt(v) : N.ms(v)) : '—') + '</b>' + (goodPct !== undefined && v ? '<span class="ntaa-meter" style="--v:' + Number(goodPct) + '%"></span><small>' + esc(N.sprintf(__('%s good'), pct(goodPct))) + '</small>' : '') + '<small class="ntaa-muted">' + esc(help) + '</small></div>';
		}
		view.innerHTML = (p.samples ? '' : note(esc(__('Core Web Vitals are measured in real visitors’ browsers (Chrome, Edge and other Chromium browsers). Data appears after the first visits.')))) +
			'<div class="ntaa-kpis">' +
			vital('LCP', p.lcp, 2500, 4000, null, p.lcp_good, __('Largest Contentful Paint — good ≤ 2.5 s')) +
			vital('INP', p.inp, 200, 500, null, p.inp_good, __('Interaction to Next Paint — good ≤ 200 ms')) +
			vital('CLS', p.cls, 0.1, 0.25, function (v) { return N.dec(v, 3); }, p.cls_good, __('Cumulative Layout Shift — good ≤ 0.1')) +
			vital('FCP', p.fcp, 1800, 3000, null, undefined, __('First Contentful Paint')) +
			vital('TTFB', p.ttfb, 800, 1800, null, p.ttfb_good, __('Time to First Byte — server speed')) +
			vital(__('Page load'), p.load, 2500, 4500, null, undefined, __('Full page load time')) +
			'</div>' +
			table('vitals-pages', __('Speed per page'), [
				{ key: 'page', label: __('Page'), fmt: N.pageCell },
				{ key: 'views', label: __('Views'), num: true, fmt: num },
				{ key: 'lcp', label: 'LCP', num: true, fmt: function (v) { return N.speed(v, 2500, 4000); } },
				{ key: 'inp', label: 'INP', num: true, fmt: function (v) { return N.speed(v, 200, 500); } },
				{ key: 'cls', label: 'CLS', num: true, fmt: function (v, r) { return r.vitals_n ? N.speed(v || 0.0001, 0.1, 0.25, function (x) { return N.dec(x < 0.001 ? 0 : x, 3); }) : '—'; } },
				{ key: 'avg_load', label: __('Load'), num: true, fmt: function (v) { return N.speed(v, 2500, 4500); } }
			], (dd.pages || []).filter(function (r) { return r.vitals_n || r.avg_load; }), { icon: 'performance', limit: 30 });
	}

	/* =============================================================
	 * Acquisition
	 * =========================================================== */
	function renderChannels(view, dd) {
		var ch = dd.referrer_types || [];
		view.innerHTML = grid(card(__('Channels'), ch.length ? '<div class="ntaa-chart-box"><canvas id="ch-ch"></canvas></div>' : N.empty(), { icon: 'share-alt2' }) +
			table('channels', __('Channels'), visitCols(nameCol(__('Channel'), chName)), ch, { bar: 'visits', icon: 'share-alt2', evo: evo('channel') })) +
			table('referrers', __('All referrers'), visitCols({ key: 'host', label: __('Source'), fmt: function (v, r) { return N.other(v) || '<span dir="ltr">' + esc(v) + '</span> <small class="ntaa-tag">' + esc(CHANNEL[r.type] || r.type) + '</small>'; } }), dd.referrers, { bar: 'visits', icon: 'admin-links', limit: 30, evo: evo('referrer', function (r) { return r.host + SEP + r.type; }) });
		if (ch.length) N.doughnut('ch-ch', ch.map(function (c) { return CHANNEL[c.type] || c.type; }), ch.map(function (c) { return c.visits; }));
	}
	function renderAIChannel(view, dd) {
		var ai = dd.ai_assistants || [];
		var total = ai.reduce(function (a, r) { return a + r.visits; }, 0);
		view.innerHTML = '<div class="ntaa-kpis small">' + N.kpi(__('Visits from AI assistants'), num(total), 'superhero') + N.kpi(__('Share of all visits'), pct(dd.summary.visits ? total / dd.summary.visits * 100 : 0), 'chart-pie') + '</div>' +
			grid(card(__('AI assistants'), ai.length ? '<div class="ntaa-chart-box"><canvas id="ch-ai"></canvas></div>' : N.empty(__('No visits from ChatGPT, Perplexity, Gemini, Claude or Copilot yet.')), { icon: 'superhero' }) +
				table('ai', __('AI assistants'), visitCols(nameCol(__('Assistant'))), ai, { bar: 'visits', icon: 'superhero', evo: evo('ai') })) +
			note(esc(__('Visits are counted here when people click a link inside an AI assistant (ChatGPT, Perplexity, Gemini, Claude, Copilot, DeepSeek and more). AI crawlers that read your pages are listed under “Crawlers & AI agents”.')), 'info-outline');
		if (ai.length) N.hbar('ch-ai', ai.slice(0, 8).map(function (x) { return x.name; }), ai.slice(0, 8).map(function (x) { return x.visits; }), '#8b5cf6');
	}
	function renderWebsites(view, dd) {
		var refs = dd.referrers || [];
		function bytype(t) { return refs.filter(function (r) { return r.type === t || r.host === '__other__'; }); }
		view.innerHTML = grid(
			table('ref-search', __('Search engines'), visitCols({ key: 'host', label: __('Search engine'), fmt: function (v) { return N.other(v) || '<span dir="ltr">' + esc(v) + '</span>'; } }), bytype('search'), { bar: 'visits', icon: 'search', evo: evo('referrer', function (r) { return r.host + SEP + r.type; }) }) +
			table('ref-social', __('Social networks'), visitCols({ key: 'host', label: __('Network'), fmt: function (v) { return N.other(v) || '<span dir="ltr">' + esc(v) + '</span>'; } }), bytype('social'), { bar: 'visits', icon: 'share', evo: evo('referrer', function (r) { return r.host + SEP + r.type; }) })
		) + table('ref-web', __('Websites'), visitCols({ key: 'host', label: __('Website'), fmt: function (v) { return N.other(v) || '<a href="https://' + esc(v) + '" target="_blank" rel="noopener noreferrer" dir="ltr">' + esc(v) + '</a>'; } }), bytype('website'), { bar: 'visits', icon: 'admin-links', limit: 30, evo: evo('referrer', function (r) { return r.host + SEP + r.type; }) }) +
			(A.pro ? '' : note(esc(__('Search engines no longer pass the keywords people searched for. Pro can import them from Google Search Console.')) + ' <a href="#/acquisition/keywords">' + esc(__('Search keywords')) + ' →</a>', 'lock'));
	}
	function renderCampaigns(view, dd) {
		view.innerHTML = table('campaigns', __('Campaigns'), visitCols({ key: 'campaign', label: __('Campaign'), fmt: function (v, r) { return N.other(v) || '<b>' + esc(v || __('(not set)')) + '</b> <small class="ntaa-muted" dir="ltr">' + esc([r.source, r.medium].filter(Boolean).join(' / ')) + '</small>'; } }), dd.campaigns, { bar: 'visits', icon: 'megaphone', limit: 30, evo: evo('campaign', function (r) { return r.campaign + SEP + r.source + SEP + r.medium; }) }) +
			grid(
				table('campaign-terms', __('Campaign keywords (utm_term)'), [{ key: 'term', label: __('Keyword'), fmt: function (v, r) { return N.other(v) || esc(v) + ' <small class="ntaa-muted">' + esc(r.campaign) + '</small>'; } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: function (v) { return v ? pct(v) : '—'; } }], dd.campaign_terms, { bar: 'visits', icon: 'tag', evo: evo('campaign_term', function (r) { return r.term + SEP + r.campaign; }) }) +
				table('campaign-content', __('Campaign content (utm_content)'), [{ key: 'content', label: __('Content'), fmt: function (v, r) { return N.other(v) || esc(v) + ' <small class="ntaa-muted">' + esc(r.campaign) + '</small>'; } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: function (v) { return v ? pct(v) : '—'; } }], dd.campaign_content, { bar: 'visits', icon: 'format-image', evo: evo('campaign_content', function (r) { return r.content + SEP + r.campaign; }) })
			) +
			card(__('Campaign URL builder'), '<div class="ntaa-utm"><label>' + esc(__('Page address')) + '<input type="url" id="utm-url" value="' + esc(A.home) + '" dir="ltr"></label><label>utm_source<input type="text" id="utm-source" placeholder="instagram" dir="ltr"></label><label>utm_medium<input type="text" id="utm-medium" placeholder="social" dir="ltr"></label><label>utm_campaign<input type="text" id="utm-campaign" placeholder="summer-sale" dir="ltr"></label><label>utm_term<input type="text" id="utm-term" dir="ltr"></label><label>utm_content<input type="text" id="utm-content" dir="ltr"></label></div><div class="ntaa-utm-out"><code id="utm-out" dir="ltr"></code><button type="button" class="button" id="utm-copy">' + esc(__('Copy link')) + '</button></div><p class="description">' + esc(__('Google Ads (gclid), Microsoft Ads (msclkid) and TikTok (ttclid) clicks are detected automatically.')) + '</p>', { icon: 'admin-links' });
		function build() {
			var u = $('#utm-url', view).value.trim() || A.home, p = [];
			['source', 'medium', 'campaign', 'term', 'content'].forEach(function (k) { var v = $('#utm-' + k, view).value.trim(); if (v) p.push('utm_' + k + '=' + encodeURIComponent(v)); });
			$('#utm-out', view).textContent = p.length ? u + (u.indexOf('?') === -1 ? '?' : '&') + p.join('&') : u;
		}
		$$('.ntaa-utm input', view).forEach(function (i) { i.addEventListener('input', build); });
		$('#utm-copy', view).addEventListener('click', function () { N.copy($('#utm-out', view).textContent, __('Link copied')); });
		build();
	}
	function renderCrawlers(view) {
		view.innerHTML = skeleton();
		N.api('crawlers', { params: N.params() }).then(function (c) {
			var KIND = { ai: __('AI crawler'), search: __('Search engine'), seo: __('SEO tool'), social: __('Social preview'), monitor: __('Monitoring'), other: __('Other bot') };
			var byKind = {};
			(c.bots || []).forEach(function (b) { byKind[b.kind] = (byKind[b.kind] || 0) + b.hits; });
			var days = [], k = N.state.from; while (k <= N.state.to && days.length < 400) { days.push(k); k = N.addDays(k, 1); }
			var series = {};
			(c.daily || []).forEach(function (r) { (series[r.kind] = series[r.kind] || {})[r.day] = r.hits; });
			view.innerHTML = (A.trackBots ? '' : note(esc(__('Crawler logging is off. Turn on “Log crawlers & AI agents” in Settings › Tracking.')) + (A.canManage ? ' <a href="' + esc(A.settingsUrl) + '#tracking">' + esc(__('Settings')) + '</a>' : ''), 'warning')) +
				'<div class="ntaa-kpis small">' + Object.keys(KIND).filter(function (x) { return byKind[x]; }).map(function (x) { return N.kpi(KIND[x], num(byKind[x]), x === 'ai' ? 'superhero' : 'admin-site'); }).join('') + '</div>' +
				card(__('Crawler hits per day'), '<div class="ntaa-chart-box"><canvas id="ch-bots"></canvas></div>', { icon: 'chart-bar' }) +
				table('bots', __('Crawlers & AI agents'), [
					{ key: 'bot', label: __('Bot'), fmt: function (v) { return '<b>' + esc(v) + '</b>'; } },
					{ key: 'kind', label: __('Type'), fmt: function (v) { return '<span class="ntaa-tag' + (v === 'ai' ? ' ai' : '') + '">' + esc(KIND[v] || v) + '</span>'; } },
					{ key: 'hits', label: __('Hits'), num: true, fmt: num },
					{ key: 'last_seen', label: __('Last seen'), num: true, fmt: function (v) { return esc(N.dateTime(v)); } },
					{ key: 'last_url', label: __('Last page'), fmt: function (v) { return '<span class="ntaa-path" dir="ltr">' + esc(v) + '</span>'; } }
				], c.bots, { bar: 'hits', icon: 'admin-site', intro: esc(__('Bots are never counted as visitors. This log shows which search engines and AI companies (GPTBot, ClaudeBot, PerplexityBot…) read your site.')) });
			var kinds = Object.keys(series);
			if (kinds.length) N.vbar('ch-bots', days.map(function (x) { return N.fmtDate(x); }), kinds.map(function (kk) { return { label: KIND[kk] || kk, data: days.map(function (x) { return series[kk][x] || 0; }), stack: 's' }; }));
			if (N.charts['ch-bots']) { N.charts['ch-bots'].options.scales.x.stacked = true; N.charts['ch-bots'].options.scales.y.stacked = true; N.charts['ch-bots'].update(); }
		}).catch(function (e) { view.innerHTML = err(e); });
	}

	/* =============================================================
	 * Goals
	 * =========================================================== */
	var GOAL_TYPES = { url: __('Visits a page'), event: __('Triggers an event'), duration: __('Stays longer than'), pages: __('Views more pages than'), purchase: __('Places an order (WooCommerce)'), manual: __('Manual (from code)') };
	function goalDesc(g) {
		if (g.type === 'url') return esc(__('Page address')) + ' ' + esc({ contains: __('contains'), exact: __('is exactly'), starts: __('starts with'), regex: __('matches regex') }[g.match] || g.match) + ' <code dir="ltr">' + esc(g.pattern) + '</code>';
		if (g.type === 'event') return esc(__('Event')) + ' <code dir="ltr">' + esc([g.ev_cat || '*', g.ev_act || '*', g.ev_label || '*'].join(' › ')) + '</code>';
		if (g.type === 'duration') return esc(N.sprintf(__('Visit longer than %s'), dur(g.min)));
		if (g.type === 'pages') return esc(N.sprintf(__('More than %s pages'), num(g.min)));
		if (g.type === 'manual') return '<code dir="ltr">ntaa.trackGoal(' + Number(g.id) + ')</code>';
		return esc(GOAL_TYPES[g.type] || g.type);
	}
	function renderGoals(view, dd, refresh) {
		var goals = dd.goals || [];
		var s = dd.summary;
		if (!goals.length) {
			view.innerHTML = card(__('Goals'), '<p>' + esc(__('Goals measure what matters: a contact form sent, a sign-up, a download, a purchase or simply an engaged visit. Create your first goal to see conversions, conversion rates and which channels convert best.')) + '</p>' + (A.canManage ? '<p><a class="button button-primary" href="#/goals/manage">' + icon('plus-alt2') + esc(__('Create a goal')) + '</a></p>' : ''), { icon: 'flag' });
			return;
		}
		var series = dd.goal_series || [];
		view.innerHTML = '<div class="ntaa-kpis small">' + N.kpi(__('Conversions'), num(s.conversions), 'flag', N.delta(s.conversions, dd.previous.conversions)) + N.kpi(__('Conversion rate'), pct(s.conversion_rate), 'chart-line', N.delta(s.conversion_rate, dd.previous.conversion_rate)) + N.kpi(__('Goal value'), money(s.goal_value), 'money-alt', N.delta(s.goal_value, dd.previous.goal_value)) + '</div>' +
			card(__('Conversions per day'), '<div class="ntaa-chart-box"><canvas id="ch-goals"></canvas></div>', { icon: 'chart-area' }) +
			table('goals', __('Goals'), [
				{ key: 'name', label: __('Goal'), fmt: function (v, r) { return '<b>' + esc(v) + '</b>' + (r.active ? '' : ' <span class="ntaa-tag">' + esc(__('paused')) + '</span>'); } },
				{ key: 'conversions', label: __('Conversions'), num: true, fmt: num },
				{ key: 'conversion_rate', label: __('Conv. rate'), num: true, fmt: pct },
				{ key: 'value', label: __('Value'), num: true, fmt: function (v) { return v ? money(v) : '—'; } }
			], goals, { bar: 'conversions', icon: 'flag', onRow: function (g) { goalReport(g); }, intro: esc(__('Click a goal for its own report.')), tools: A.canManage ? '<a class="button-link" href="#/goals/manage">' + esc(__('Manage goals')) + '</a>' : '' }) +
			grid(
				table('goal-ch', __('Conversions by channel'), [nameCol(__('Channel'), chName), { key: 'conversions', label: __('Conversions'), num: true, fmt: num }, { key: 'value', label: __('Value'), num: true, fmt: function (v) { return v ? money(v) : '—'; } }], dd.goal_channel, { bar: 'conversions', icon: 'share-alt2' }) +
				table('goal-dev', __('Conversions by device'), [nameCol(__('Device'), devName), { key: 'conversions', label: __('Conversions'), num: true, fmt: num }], dd.goal_device, { bar: 'conversions', icon: 'smartphone' }) +
				table('goal-cc', __('Conversions by country'), [nameCol(__('Country'), function (v) { return N.other(v) || N.country(v); }), { key: 'conversions', label: __('Conversions'), num: true, fmt: num }], dd.goal_country, { bar: 'conversions', icon: 'admin-site-alt3' }) +
				table('goal-camp', __('Conversions by campaign'), [nameCol(__('Campaign')), { key: 'conversions', label: __('Conversions'), num: true, fmt: num }, { key: 'value', label: __('Value'), num: true, fmt: function (v) { return v ? money(v) : '—'; } }], dd.goal_campaign, { bar: 'conversions', icon: 'megaphone' }) +
				table('goal-entry', __('Conversions by entry page'), [nameCol(__('Entry page'), function (v) { return N.pageCell(v); }), { key: 'conversions', label: __('Conversions'), num: true, fmt: num }], dd.goal_entry, { bar: 'conversions', icon: 'migrate' })
			);
		N.line('ch-goals', series.map(function (p) { return N.fmtDate(p.date); }), [{ label: __('Conversions'), data: series.map(function (p) { return p.conversions; }), color: '#10b981' }]);
	}
	function goalReport(g) {
		var m = N.modal(g.name, skeleton(), { wide: true });
		N.api('goals/' + g.id + '/report', { params: N.params() }).then(function (r) {
			var tot = (r.series || []).reduce(function (a, p) { return a + p.conversions; }, 0), val = (r.series || []).reduce(function (a, p) { return a + p.value; }, 0);
			m.body.innerHTML = '<p class="ntaa-muted">' + goalDesc(r.goal || g) + '</p><div class="ntaa-kpis small">' + N.kpi(__('Conversions'), num(tot), 'flag') + N.kpi(__('Conversion rate'), pct(g.conversion_rate), 'chart-line') + N.kpi(__('Value'), money(val), 'money-alt') + '</div>' +
				'<div class="ntaa-chart-box"><canvas id="ch-goal1"></canvas></div>' +
				grid(
					table('g1-ch', __('By channel'), [nameCol(__('Channel'), chName), { key: 'conversions', label: __('Conversions'), num: true, fmt: num }], r.channel, { bar: 'conversions' }) +
					table('g1-dev', __('By device'), [nameCol(__('Device'), devName), { key: 'conversions', label: __('Conversions'), num: true, fmt: num }], r.device, { bar: 'conversions' }) +
					table('g1-cc', __('By country'), [nameCol(__('Country'), function (v) { return N.other(v) || N.country(v); }), { key: 'conversions', label: __('Conversions'), num: true, fmt: num }], r.country, { bar: 'conversions' }) +
					table('g1-entry', __('By entry page'), [nameCol(__('Entry page'), function (v) { return N.pageCell(v); }), { key: 'conversions', label: __('Conversions'), num: true, fmt: num }], r.entry, { bar: 'conversions' })
				);
			N.line('ch-goal1', (r.series || []).map(function (p) { return N.fmtDate(p.date); }), [{ label: __('Conversions'), data: (r.series || []).map(function (p) { return p.conversions; }), color: '#10b981' }]);
		}).catch(function (e) { m.body.innerHTML = err(e); });
	}

	function goalForm(g, done) {
		g = g || { type: 'url', match: 'contains', active: 1 };
		var m = N.modal(g.id ? __('Edit goal') : __('New goal'), '<div class="ntaa-form">' +
			'<label>' + esc(__('Goal name')) + '<input type="text" data-k="name" value="' + esc(g.name || '') + '" placeholder="' + esc(__('e.g. Contact form sent')) + '"></label>' +
			'<label>' + esc(__('A conversion happens when the visitor…')) + '<select data-k="type">' + Object.keys(GOAL_TYPES).map(function (t) { return '<option value="' + t + '"' + (g.type === t ? ' selected' : '') + (t === 'purchase' && !A.woo ? ' disabled' : '') + '>' + esc(GOAL_TYPES[t]) + '</option>'; }).join('') + '</select></label>' +
			'<div data-show="url" class="ntaa-row"><label>' + esc(__('Page address')) + '<select data-k="match"><option value="contains">' + esc(__('contains')) + '</option><option value="exact">' + esc(__('is exactly')) + '</option><option value="starts">' + esc(__('starts with')) + '</option><option value="regex">' + esc(__('matches regex')) + '</option></select></label><label>' + esc(__('Pattern')) + '<input type="text" data-k="pattern" dir="ltr" value="' + esc(g.pattern || '') + '" placeholder="/thank-you"></label></div>' +
			'<div data-show="event" class="ntaa-row"><label>' + esc(__('Category')) + '<input type="text" data-k="ev_cat" value="' + esc(g.ev_cat || '') + '" placeholder="Form"></label><label>' + esc(__('Action')) + '<input type="text" data-k="ev_act" value="' + esc(g.ev_act || '') + '" placeholder="submit"></label><label>' + esc(__('Label')) + '<input type="text" data-k="ev_label" value="' + esc(g.ev_label || '') + '"></label></div>' +
			'<div data-show="event"><label class="ntaa-check"><input type="checkbox" data-k="ev_value"' + (g.ev_value ? ' checked' : '') + '> <span>' + esc(__('Use the event value as the goal value')) + '</span></label></div>' +
			'<div data-show="duration"><label>' + esc(__('Seconds')) + '<input type="number" min="1" data-k="min" value="' + esc(g.type === 'duration' ? g.min : 120) + '"></label></div>' +
			'<div data-show="pages"><label>' + esc(__('Pages')) + '<input type="number" min="1" data-k="min2" value="' + esc(g.type === 'pages' ? g.min : 3) + '"></label></div>' +
			'<div data-show="manual"><p class="description">' + esc(__('Trigger it from your theme or plugin:')) + '</p><pre class="ntaa-code" dir="ltr">ntaa.trackGoal(GOAL_ID, 25);\n&lt;?php ntaa_track_goal( GOAL_ID, 25 ); ?&gt;\n&lt;a data-ntaa-goal="GOAL_ID" href="…"&gt;</pre></div>' +
			'<div data-show="purchase"><p class="description">' + esc(__('Counts every paid WooCommerce order. The order total is used as the goal value.')) + '</p></div>' +
			'<div data-hide="purchase"><label>' + esc(__('Goal value (optional)')) + '<input type="number" step="0.01" min="0" data-k="value" value="' + esc(g.value || '') + '"></label></div>' +
			'<label class="ntaa-check"><input type="checkbox" data-k="multiple"' + (g.multiple ? ' checked' : '') + '> <span>' + esc(__('Allow several conversions per visit')) + '</span></label>' +
			'<label class="ntaa-check" data-show="url"><input type="checkbox" data-k="case"' + (g.case ? ' checked' : '') + '> <span>' + esc(__('Case sensitive')) + '</span></label>' +
			'<label class="ntaa-check"><input type="checkbox" data-k="active"' + (g.active ? ' checked' : '') + '> <span>' + esc(__('Active')) + '</span></label>' +
			'<p><button type="button" class="button button-primary" data-save>' + esc(__('Save goal')) + '</button></p></div>');
		var f = m.body;
		$('[data-k=match]', f).value = g.match || 'contains';
		function sync() { var t = $('[data-k=type]', f).value; $$('[data-show]', f).forEach(function (el) { el.hidden = el.getAttribute('data-show') !== t; }); $$('[data-hide]', f).forEach(function (el) { el.hidden = el.getAttribute('data-hide') === t; }); }
		$('[data-k=type]', f).addEventListener('change', sync); sync();
		$('[data-save]', f).addEventListener('click', function () {
			var t = $('[data-k=type]', f).value;
			var body = { id: g.id || 0, name: $('[data-k=name]', f).value, type: t, match: $('[data-k=match]', f).value, pattern: $('[data-k=pattern]', f).value, ev_cat: $('[data-k=ev_cat]', f).value, ev_act: $('[data-k=ev_act]', f).value, ev_label: $('[data-k=ev_label]', f).value, ev_value: $('[data-k=ev_value]', f).checked ? 1 : 0, min: t === 'pages' ? $('[data-k=min2]', f).value : $('[data-k=min]', f).value, value: $('[data-k=value]', f).value, multiple: $('[data-k=multiple]', f).checked ? 1 : 0, 'case': $('[data-k=case]', f).checked ? 1 : 0, active: $('[data-k=active]', f).checked ? 1 : 0 };
			N.api('goals', { method: 'POST', body: body }).then(function (saved) { m.close(); N.toast(__('Goal saved'), 'ok'); done(saved, !g.id); }).catch(function (e) { N.toast(e.message, 'err'); });
		});
	}
	function recalc(g, from, to, box) {
		var after = 0, total = 0, conv = 0, seen = 0;
		box.innerHTML = '<div class="ntaa-progress"><span style="width:0"></span></div><small></small>';
		function step() {
			N.api('goals/' + g.id + '/recalc', { method: 'POST', body: { from: from, to: to, after: after } }).then(function (r) {
				after = r.after; total = r.total || total; conv += r.conversions || 0; seen += r.processed || 0;
				var done = r.done ? 100 : Math.min(99, total ? Math.round(seen / total * 100) : 50);
				$('span', box).style.width = done + '%';
				$('small', box).textContent = r.done ? N.sprintf(__('Done: %s past conversions found.'), num(conv)) : __('Processing past visits…');
				if (!r.done) step(); else N.toast(__('Goal recalculated'), 'ok');
			}).catch(function (e) { box.innerHTML = '<span class="ntaa-bad">' + esc(e.message) + '</span>'; });
		}
		step();
	}
	function renderGoalsManage(view) {
		view.innerHTML = skeleton();
		N.api('goals', { cache: false }).then(function (goals) {
			view.innerHTML = '<div class="ntaa-card"><div class="ntaa-card-head"><h3>' + icon('flag') + esc(__('Manage goals')) + '</h3>' + (A.canManage ? '<button type="button" class="button button-primary" data-new>' + icon('plus-alt2') + esc(__('New goal')) + '</button>' : '') + '</div>' +
				(goals.length ? '<div class="ntaa-table-wrap"><table class="ntaa-table"><thead><tr><th>ID</th><th>' + esc(__('Goal')) + '</th><th>' + esc(__('Rule')) + '</th><th class="num">' + esc(__('Value')) + '</th><th></th></tr></thead><tbody>' + goals.map(function (g) {
					return '<tr><td data-label="ID">' + Number(g.id) + '</td><td class="main" data-label="' + esc(__('Goal')) + '"><b>' + esc(g.name) + '</b>' + (g.active ? '' : ' <span class="ntaa-tag">' + esc(__('paused')) + '</span>') + '</td><td data-label="' + esc(__('Rule')) + '">' + goalDesc(g) + '</td><td class="num" data-label="' + esc(__('Value')) + '">' + (g.value ? money(g.value) : '—') + '</td><td class="ntaa-actions">' +
						(A.canManage ? '<button type="button" class="button-link" data-edit="' + g.id + '">' + esc(__('Edit')) + '</button> · ' + (g.type !== 'manual' ? '<button type="button" class="button-link" data-recalc="' + g.id + '">' + esc(__('Apply to past data')) + '</button> · ' : '') + '<button type="button" class="button-link ntaa-bad" data-del="' + g.id + '">' + esc(__('Delete')) + '</button>' : '') + '<div class="ntaa-recalc" data-box="' + g.id + '"></div></td></tr>';
				}).join('') + '</tbody></table></div>' : N.empty(__('No goals yet.'))) + '</div>' +
				note(esc(__('New and edited goals count conversions from now on. Use “Apply to past data” to scan visits already recorded in the selected date range (raw data within your retention period).')));
			var byId = {}; goals.forEach(function (g) { byId[g.id] = g; });
			function reload() { N.api('goals', { cache: false }); renderGoalsManage(view); }
			var nb = $('[data-new]', view);
			if (nb) nb.addEventListener('click', function () { goalForm(null, function (saved) { reload(); if (saved && saved.type !== 'manual' && w.confirm(__('Also look for conversions of this goal in the visits of the selected date range?'))) setTimeout(function () { var b = $('[data-box="' + saved.id + '"]', view); if (b) recalc(saved, N.state.from, N.state.to, b); }, 400); }); });
			$$('[data-edit]', view).forEach(function (b) { b.addEventListener('click', function () { goalForm(byId[b.getAttribute('data-edit')], reload); }); });
			$$('[data-del]', view).forEach(function (b) { b.addEventListener('click', function () { if (!w.confirm(__('Delete this goal? Its recorded conversions stay in old reports but the goal stops counting.'))) return; N.api('goals/' + b.getAttribute('data-del'), { method: 'DELETE' }).then(reload); }); });
			$$('[data-recalc]', view).forEach(function (b) { b.addEventListener('click', function () { var g = byId[b.getAttribute('data-recalc')]; if (!w.confirm(N.sprintf(__('Recalculate “%1$s” for %2$s – %3$s? Existing conversions of this goal in that range are replaced.'), g.name, N.longDate(N.state.from), N.longDate(N.state.to)))) return; recalc(g, N.state.from, N.state.to, $('[data-box="' + g.id + '"]', view)); }); });
		}).catch(function (e) { view.innerHTML = err(e); });
	}

	/* =============================================================
	 * Ecommerce
	 * =========================================================== */
	function noWoo(view) { view.innerHTML = card(__('Ecommerce'), '<p>' + esc(__('Install and activate WooCommerce to see revenue, orders, products, abandoned carts and the shopping path. Other shops can send orders with ntaa.trackOrder().')) + '</p><pre class="ntaa-code" dir="ltr">ntaa.trackOrder({ id: \'1024\', revenue: 125, items: [{ id: \'SKU1\', name: \'Shoe\', price: 125, qty: 1 }] });</pre>', { icon: 'cart' }); }
	function stepsCard(st) {
		var steps = [['visits', __('Visits')], ['product_view', __('Viewed a product')], ['add_to_cart', __('Added to cart')], ['checkout', __('Started checkout')], ['purchase', __('Purchased')]];
		var first = st.visits || 0;
		return card(__('Shopping path'), '<div class="ntaa-funnel">' + steps.map(function (s, i) {
			var v = st[s[0]] || 0, prev = i ? (st[steps[i - 1][0]] || 0) : v;
			var drop = i && prev ? (1 - v / prev) * 100 : 0;
			return '<div class="ntaa-funnel-step"><div class="ntaa-funnel-bar" style="--w:' + (first ? Math.max(2, v / first * 100) : 0) + '%"><b>' + num(v) + '</b></div><span>' + esc(s[1]) + '</span>' + (i ? '<small class="' + (drop > 70 ? 'ntaa-bad' : 'ntaa-muted') + '">' + (prev ? esc(N.sprintf(__('%1$s continued · %2$s dropped off'), pct(100 - drop), pct(drop))) : '—') + '</small>' : '') + '</div>';
		}).join('') + '</div>', { icon: 'filter', intro: '' });
	}
	function renderEcom(view, dd) {
		if (!A.woo && !(dd.ecommerce && dd.ecommerce.orders)) return noWoo(view);
		var e = dd.ecommerce || {}, p = dd.ecommerce_previous || {}, cur = e.currency || A.currency;
		function mo(v) { return money(v, cur); }
		var pts = dd.timeseries.points || [];
		view.innerHTML = '<div class="ntaa-kpis">' + N.kpi(__('Revenue'), mo(e.revenue), 'money-alt', N.delta(e.revenue, p.revenue)) + N.kpi(__('Orders'), num(e.orders), 'cart', N.delta(e.orders, p.orders)) + N.kpi(__('Average order value'), mo(e.aov), 'chart-bar', N.delta(e.aov, p.aov)) + N.kpi(__('Ecommerce conversion rate'), pct(e.conversion_rate), 'chart-line', N.delta(e.conversion_rate, p.conversion_rate)) + N.kpi(__('Products sold'), num(e.items), 'products') + N.kpi(__('Abandoned carts'), num(e.abandoned) + ' <small>' + mo(e.abandoned_value) + '</small>', 'dismiss') + '</div>' +
			card(__('Revenue per day'), '<div class="ntaa-chart-box"><canvas id="ch-rev"></canvas></div>', { icon: 'chart-area' }) +
			grid(stepsCard(e.steps || {}) + card(__('Order details'), '<table class="ntaa-table ntaa-kv"><tbody>' + [[__('Subtotal'), mo(e.subtotal)], [__('Tax'), mo(e.tax)], [__('Shipping'), mo(e.shipping)], [__('Discounts'), mo(e.discount)], [__('New customers'), num(e.new_customers)], [__('Returning customers'), num(Math.max(0, e.orders - e.new_customers))]].map(function (r) { return '<tr><td>' + esc(r[0]) + '</td><td class="num">' + r[1] + '</td></tr>'; }).join('') + '</tbody></table>', { icon: 'list-view' })) +
			note(esc(__('Orders are recorded when they are paid (or marked processing/completed) and removed again when refunded or cancelled. Each order is linked to the visit that placed it, so you can see which channel and campaign earned the money.')));
		N.line('ch-rev', pts.map(function (x) { return dd.timeseries.granularity === 'hour' ? N.clock(x.date) : N.fmtDate(x.date); }), [{ label: __('Revenue'), data: pts.map(function (x) { return x.revenue; }), color: '#10b981' }, { label: __('Orders'), data: pts.map(function (x) { return x.orders; }), color: '#6366f1', fill: false, yAxisID: 'y1' }], mo);
		var c = N.charts['ch-rev'];
		if (c) { c.options.scales.y1 = { position: N.RTL ? 'left' : 'right', beginAtZero: true, grid: { display: false }, ticks: { precision: 0 } }; c.options.plugins.tooltip.callbacks.label = function (ctx) { return ' ' + ctx.dataset.label + ': ' + (ctx.datasetIndex ? num(ctx.raw) : mo(ctx.raw)); }; c.update(); }
	}
	function renderProducts(view, dd) {
		if (!A.woo && !(dd.products || []).length) return noWoo(view);
		var cur = (dd.ecommerce || {}).currency || A.currency;
		view.innerHTML = table('products', __('Products'), [
			{ key: 'name', label: __('Product'), fmt: function (v) { return N.other(v) || '<b>' + esc(v) + '</b>'; } },
			{ key: 'views', label: __('Views'), num: true, fmt: num },
			{ key: 'adds', label: __('Added to cart'), num: true, fmt: num },
			{ key: 'sold', label: __('Sold'), num: true, fmt: num },
			{ key: 'orders', label: __('Orders'), num: true, fmt: num },
			{ key: 'conversion', label: __('View → order'), num: true, fmt: function (v) { return v ? pct(v) : '—'; } },
			{ key: 'revenue', label: __('Revenue'), num: true, fmt: function (v) { return money(v, cur); } }
		], dd.products, { bar: 'revenue', icon: 'products', limit: 30, evo: Object.assign(evo('product', function (r) { return String(r.id); }), { title: function (r) { return r.name; } }) }) +
			table('categories', __('Product categories'), [{ key: 'name', label: __('Category'), fmt: function (v) { return N.other(v) || esc(v || __('(none)')); } }, { key: 'sold', label: __('Sold'), num: true, fmt: num }, { key: 'orders', label: __('Orders'), num: true, fmt: num }, { key: 'revenue', label: __('Revenue'), num: true, fmt: function (v) { return money(v, cur); } }], dd.categories, { bar: 'revenue', icon: 'category', evo: evo('category') });
	}
	function renderSales(view, dd) {
		if (!A.woo && !(dd.ecommerce && dd.ecommerce.orders)) return noWoo(view);
		var cur = (dd.ecommerce || {}).currency || A.currency;
		function cols(first) { return [first, { key: 'orders', label: __('Orders'), num: true, fmt: num }, { key: 'revenue', label: __('Revenue'), num: true, fmt: function (v) { return money(v, cur); } }, { key: 'visits', label: __('Visits'), num: true, fmt: num }, { key: 'rpv', label: __('Revenue / visit'), num: true, fmt: function (v) { return money(v, cur); } }]; }
		function rpv(rows) { return (rows || []).filter(function (r) { return r.revenue > 0 || r.orders > 0; }).map(function (r) { return Object.assign({ rpv: r.visits ? r.revenue / r.visits : 0 }, r); }); }
		view.innerHTML = grid(
			table('rev-ch', __('Sales by channel'), cols(nameCol(__('Channel'), chName)), rpv(dd.revenue_channel), { bar: 'revenue', icon: 'share-alt2', evo: evo('channel') }) +
			table('rev-camp', __('Sales by campaign'), cols({ key: 'campaign', label: __('Campaign'), fmt: function (v, r) { return N.other(v) || '<b>' + esc(v) + '</b> <small class="ntaa-muted">' + esc([r.source, r.medium].filter(Boolean).join(' / ')) + '</small>'; } }), rpv(dd.revenue_campaign), { bar: 'revenue', icon: 'megaphone', evo: evo('campaign', function (r) { return r.campaign + SEP + r.source + SEP + r.medium; }) }) +
			table('rev-cc', __('Sales by country'), cols(nameCol(__('Country'), function (v) { return N.other(v) || N.country(v); })), rpv(dd.revenue_country), { bar: 'revenue', icon: 'admin-site-alt3', evo: evo('country') }) +
			table('rev-dev', __('Sales by device'), cols(nameCol(__('Device'), devName)), rpv(dd.revenue_device), { bar: 'revenue', icon: 'smartphone', evo: evo('device') })
		) + (A.pro ? '' : note(esc(__('These reports credit each sale to the visit that placed the order (last visit). Pro adds multi-touch attribution (first click, linear, time decay) across all visits of a customer.')) + ' <a href="#/acquisition/attribution">' + esc(__('Attribution')) + ' →</a>', 'lock'));
	}

	/* =============================================================
	 * Navigation
	 * =========================================================== */
	N.section('dashboard', __('Dashboard'), 'dashboard');
	N.section('visitors', __('Visitors'), 'groups');
	N.section('behaviour', __('Behaviour'), 'media-document');
	N.section('acquisition', __('Acquisition'), 'share-alt2');
	N.section('goals', __('Goals'), 'flag');
	N.section('ecommerce', __('Ecommerce'), 'cart');
	N.section('tools', __('Pro tools'), 'admin-tools');

	soft('dashboard', { id: 'overview', label: __('Overview'), icon: 'dashboard', part: 'overview', render: renderOverview });
	soft('dashboard', { id: 'realtime', label: __('Real-time'), icon: 'clock', noRange: true, render: renderRealtime });
	soft('visitors', { id: 'log', label: __('Visits log'), icon: 'list-view', render: renderLog });
	soft('visitors', { id: 'locations', label: __('Locations'), icon: 'admin-site-alt3', part: 'audience', render: renderLocations });
	soft('visitors', { id: 'devices', label: __('Devices & software'), icon: 'smartphone', part: 'audience', render: renderDevices });
	soft('visitors', { id: 'engagement', label: __('Engagement'), icon: 'heart', part: 'engagement', render: renderEngagement });
	soft('visitors', { id: 'dimensions', label: __('Custom dimensions'), icon: 'tag', part: 'audience', render: renderDims });
	locked('visitors', 'cohorts', __('Cohorts'), 'image-filter', __('See how many visitors come back week after week, grouped by their first visit.'), [__('Weekly and monthly retention tables'), __('Returning revenue per cohort'), __('Works in cookie mode')]);
	locked('visitors', 'flow', __('Users flow'), 'randomize', __('Follow the most common paths visitors take through your site, step by step.'), [__('Top paths from any start page'), __('Drop-off at each step')]);
	soft('behaviour', { id: 'pages', label: __('Pages'), icon: 'media-document', part: 'content', render: renderPages });
	soft('behaviour', { id: 'titles', label: __('Page titles'), icon: 'editor-textcolor', part: 'content', render: renderTitles });
	soft('behaviour', { id: 'entryexit', label: __('Entry & exit pages'), icon: 'migrate', part: 'content', render: renderEntryExit });
	soft('behaviour', { id: 'search', label: __('Site search'), icon: 'search', part: 'content', render: renderSearch });
	soft('behaviour', { id: 'events', label: __('Events'), icon: 'marker', part: 'events', render: renderEvents });
	soft('behaviour', { id: 'performance', label: __('Page speed (Web Vitals)'), icon: 'performance', part: 'content', render: renderPerformance });
	locked('behaviour', 'overlay', __('Page overlay'), 'welcome-view-site', __('Open any page of your site and see on the page itself what share of visitors clicked each link.'), [__('Click share on every link'), __('Walk through your site in overlay mode'), __('7, 30 or 90 days')]);
	locked('behaviour', 'heatmaps', __('Heatmaps'), 'admin-customizer', __('Click, move and scroll heatmaps show what visitors look at and click on every page.'), [__('Click, move and scroll maps'), __('Desktop, tablet and mobile'), __('Sampling and privacy masking')]);
	locked('behaviour', 'recordings', __('Session recordings'), 'video-alt3', __('Watch anonymised replays of real visits to find what confuses visitors.'), [__('Inputs and personal data masked'), __('Sampling, consent and size limits'), __('Linked to the visits log')]);
	locked('behaviour', 'forms', __('Form analytics'), 'feedback', __('See which forms and fields cause visitors to give up.'), [__('Starts, submissions and abandonment'), __('Time and drop-off per field')]);
	locked('behaviour', 'media', __('Media analytics'), 'format-video', __('Plays, completion and watch time of your HTML5 videos and audio.'), [__('Play rate and finish rate'), __('Watch time per video')]);
	locked('behaviour', 'errors', __('JavaScript errors'), 'warning', __('Catch broken scripts that hurt sales before your visitors tell you.'), [__('Error message, file and line'), __('Pages and browsers affected')]);
	locked('behaviour', 'content', __('Content tracking'), 'images-alt2', __('Measure impressions and clicks of banners, CTAs and blocks.'), [__('Impressions, clicks and click rate'), __('Mark blocks with data-ntaa-content')]);
	soft('acquisition', { id: 'channels', label: __('All channels'), icon: 'share-alt2', part: 'sources', render: renderChannels });
	soft('acquisition', { id: 'ai', label: __('AI assistants'), icon: 'superhero', part: 'sources', render: renderAIChannel });
	soft('acquisition', { id: 'websites', label: __('Search, social & websites'), icon: 'admin-links', part: 'sources', render: renderWebsites });
	soft('acquisition', { id: 'campaigns', label: __('Campaigns'), icon: 'megaphone', part: 'sources', render: renderCampaigns });
	soft('acquisition', { id: 'crawlers', label: __('Crawlers & AI agents'), icon: 'admin-site', render: renderCrawlers });
	locked('acquisition', 'keywords', __('Search keywords'), 'search', __('Import the Google searches that brought visitors, with clicks, impressions and position, from Google Search Console.'), [__('Keywords per page'), __('Clicks, impressions, CTR and position')]);
	locked('acquisition', 'bing', __('Bing keywords'), 'search', __('Import keywords and pages from Bing Webmaster Tools with clicks, impressions and position.'), [__('Keywords and pages'), __('Daily automatic import')]);
	locked('acquisition', 'attribution', __('Attribution'), 'chart-pie', __('Give credit to every channel that helped a sale, not just the last one.'), [__('First click, last click, linear, position-based and time-decay models'), __('Revenue and conversions per channel')]);
	locked('acquisition', 'ga4', __('Google Analytics import'), 'download', __('Bring your history from Google Analytics 4 into your reports.'), [__('Daily visits, sources, pages, countries and devices'), __('Fills the days before you installed this plugin')]);
	soft('goals', { id: 'overview', label: __('Goals overview'), icon: 'flag', part: 'goals', render: renderGoals });
	soft('goals', { id: 'manage', label: __('Manage goals'), icon: 'admin-generic', render: renderGoalsManage });
	locked('goals', 'funnels', __('Funnels'), 'filter', __('Define the steps to a goal and see exactly where visitors drop off.'), [__('Pages, events and goals as steps'), __('Drop-off and time between steps'), __('WooCommerce checkout funnel')]);
	soft('ecommerce', { id: 'overview', label: __('Ecommerce overview'), icon: 'cart', part: 'ecommerce', render: renderEcom });
	soft('ecommerce', { id: 'products', label: __('Products'), icon: 'products', part: 'ecommerce', render: renderProducts });
	soft('ecommerce', { id: 'sales', label: __('Sales by source'), icon: 'money-alt', part: 'ecommerce', render: renderSales });
	locked('tools', 'abtests', __('A/B tests'), 'image-flip-horizontal', __('Test two versions of a page, headline or button and let the numbers pick the winner.'), [__('Split URL and in-page variants'), __('Statistical significance'), __('Goals and revenue per variant')]);
	locked('tools', 'tags', __('Tag Manager'), 'editor-code', __('Add Facebook Pixel, Google Ads, Hotjar or your own scripts without touching the theme — with consent and triggers.'), [__('Page, click, form and timer triggers'), __('Consent-aware loading')]);
	locked('tools', 'reports', __('Custom reports'), 'chart-bar', __('Combine any dimension with any metrics and save it as your own report.'), [__('Pick dimensions, metrics, filters and sorting'), __('Export to CSV')]);
	locked('tools', 'alerts', __('Alerts'), 'bell', __('Get an email when traffic, conversions or revenue suddenly rise or drop.'), [__('Thresholds or % change'), __('Daily or hourly checks')]);
	locked('tools', 'emailreports', __('Scheduled email reports'), 'email-alt', __('Daily, weekly or monthly KPI emails with an optional AI summary.'), [__('Several recipients and sites'), __('AI-written highlights')]);
	locked('tools', 'activity', __('Activity log'), 'backup', __('See who changed settings, goals, segments, websites and tokens — and when.'), [__('Kept for one year'), __('Filter by user and action')]);
	locked('tools', 'sites', __('Websites & API'), 'admin-site-alt3', __('Track other websites (also non-WordPress) in the same dashboard and read your data with a secure API.'), [__('Multiple sites and roll-ups'), __('Reporting & tracking API (JSON, CSV, TSV, XML)'), __('Embeddable widgets')]);

	/* ---------- shell ---------- */
	var root, viewEl, current = { section: 'dashboard', item: 'overview' }, renderSeq = 0;
	function parseHash() {
		var h = (location.hash || '').replace(/^#\/?/, '').split('/');
		var sec = N.sections.filter(function (s) { return s.id === h[0]; })[0];
		if (!sec) return { section: 'dashboard', item: 'overview' };
		var it = sec.items.filter(function (x) { return x.id === h[1]; })[0] || sec.items[0];
		return { section: sec.id, item: it ? it.id : '' };
	}
	function navHtml() {
		return '<nav class="ntaa-nav" aria-label="' + esc(__('Reports')) + '">' + N.sections.filter(function (s) { return s.items.length; }).map(function (s) {
			var open = s.id === current.section;
			return '<div class="ntaa-nav-sec' + (open ? ' open' : '') + '"><button type="button" class="ntaa-nav-head" data-sec="' + esc(s.id) + '" aria-expanded="' + open + '">' + icon(s.icon) + '<span>' + esc(s.label) + '</span></button><ul>' + s.items.map(function (it) {
				var active = open && it.id === current.item;
				return '<li><a href="#/' + esc(s.id) + '/' + esc(it.id) + '" class="' + (active ? 'active' : '') + (it.pro && !A.pro ? ' locked' : '') + '"' + (active ? ' aria-current="page"' : '') + '>' + icon(it.icon || 'marker') + '<span>' + esc(it.label) + '</span>' + (it.pro && !A.pro ? '<span class="ntaa-protag">PRO</span>' : '') + '</a></li>';
			}).join('') + '</ul></div>';
		}).join('') + '</nav>';
	}
	function findItem() {
		var s = N.sections.filter(function (x) { return x.id === current.section; })[0];
		return s ? s.items.filter(function (x) { return x.id === current.item; })[0] : null;
	}
	function refreshToolbar() { var tb = $('#ntaa-toolbar', root); if (tb) N.mountToolbar(tb, tb._opts); }
	function render() {
		var it = findItem();
		if (!it) return;
		if (rtTimer) { clearInterval(rtTimer); rtTimer = null; }
		N.destroyCharts();
		var seq = ++renderSeq;
		var fresh = viewEl.cloneNode(false);
		viewEl.parentNode.replaceChild(fresh, viewEl);
		viewEl = fresh;
		viewEl._token = seq;
		var s = N.sections.filter(function (x) { return x.id === current.section; })[0];
		$('#ntaa-crumb', root).innerHTML = esc(s.label) + ' › <b>' + esc(it.label) + '</b>';
		$('#ntaa-toolbar', root).hidden = !!it.noRange || (it.pro && !A.pro);
		root.classList.toggle('ntaa-hide-seg', !!it.noSegment);
		var refresh = function () { N.api('stats', { params: N.params({ part: it.part }), cache: false }).then(function () { render(); }); };
		if (it.part && !(it.pro && !A.pro)) {
			viewEl.innerHTML = skeleton();
			N.api('stats', { params: N.params({ part: it.part }) }).then(function (data) {
				if (seq !== renderSeq) return;
				try { it.render(viewEl, data, refresh); } catch (e) { viewEl.innerHTML = err(e); if (w.console) console.error(e); }
				if (data.segmented) viewEl.insertAdjacentHTML('afterbegin', data.seg_fast ? (data.seg_pending ? note(esc(N.sprintf(__('This segment is still being processed: %s days of this period are calculated live for now.'), num(data.seg_pending))), 'update') : '') : '<div class="ntaa-seg-hint">' + icon('filter') + ' ' + esc(__('Live segment. On busy sites, save it with “Pre-process (fast)” for instant reports.')) + '</div>');
			}).catch(function (e) { if (seq === renderSeq) viewEl.innerHTML = err(e); });
		} else {
			try { it.render(viewEl, null, refresh); } catch (e) { viewEl.innerHTML = err(e); if (w.console) console.error(e); }
		}
	}
	function route() {
		current = parseHash();
		$('#ntaa-nav-wrap', root).innerHTML = navHtml();
		root.classList.remove('nav-open');
		render();
	}
	function pollLive() {
		if (d.hidden) return;
		N.api('realtime', { params: { site: N.state.site }, cache: false }).then(function (r) { var el = $('#ntaa-live-count'); if (el) el.textContent = num(r.active_30); }).catch(function () {});
	}
	N.render = function () { render(); };
	N.dash = { mapSvg: mapSvg, bindMap: bindMap, heatmapCard: heatmapCard, err: err, skeleton: skeleton, visitCols: visitCols, nameCol: nameCol, chName: chName, devName: devName, evo: evo, evoPage: evoPage, note: note, grid: grid, METRICS: METRICS, metricValue: metricValue, openVisit: openVisit };
	N.route = function () { route(); };

	N.ready(function () {
		root = d.getElementById('ntaa-app');
		if (!root || root.getAttribute('data-view') !== 'dashboard') return;
		var live = '<a class="ntaa-live" href="#/dashboard/realtime" title="' + esc(__('Visitors in the last 30 minutes')) + '"><span class="ntaa-dot"></span><b id="ntaa-live-count">…</b> ' + esc(__('online')) + '</a>';
		root.innerHTML = N.header('NodeThemes Analytics AI', __('Privacy-first website analytics with AI insights'), live) +
			(A.notices || []).map(function (n) { return '<div class="ntaa-card ntaa-warn">' + icon('warning') + ' ' + n + '</div>'; }).join('') +
			'<div class="ntaa-app"><aside class="ntaa-side"><button type="button" class="ntaa-nav-toggle button" aria-label="' + esc(__('Menu')) + '">' + icon('menu') + esc(__('Reports')) + '</button><div id="ntaa-nav-wrap"></div></aside>' +
			'<main class="ntaa-main"><div class="ntaa-crumb" id="ntaa-crumb"></div><div id="ntaa-toolbar"></div><div id="ntaa-view"></div></main></div>' + N.support();
		viewEl = $('#ntaa-view', root);
		N.mountToolbar($('#ntaa-toolbar', root));
		N.onChange(function () { render(); pollLive(); });
		$('.ntaa-nav-toggle', root).addEventListener('click', function () { root.classList.toggle('nav-open'); });
		root.addEventListener('click', function (e) {
			var h = e.target.closest('[data-sec]');
			if (h) { var sec = h.parentNode; sec.classList.toggle('open'); h.setAttribute('aria-expanded', sec.classList.contains('open')); }
		});
		w.addEventListener('hashchange', route);
		route();
		pollLive();
		setInterval(pollLive, 30000);
	});
})(window, document);
