/* NodeThemes Analytics AI – admin core (shared by the dashboard, AI page, settings and the Pro add-on) */
(function (w, d) {
	'use strict';

	var A = w.NTAAAdmin || {};
	var D = 'nodethemes-analytics-ai';
	var i18n = (w.wp && w.wp.i18n) || {};
	function __(s) { return i18n.__ ? i18n.__(s, D) : s; }
	function sprintf(f) { var a = Array.prototype.slice.call(arguments, 1), i = 0; return String(f).replace(/%(\d+\$)?[sd]/g, function (m, pos) { var v = pos ? a[parseInt(pos, 10) - 1] : a[i++]; return v == null ? '' : v; }); }

	var RTL = !!A.rtl;
	var LOCALE = (A.locale || 'en-US').replace('_', '-');

	/* ---------- basics ---------- */
	var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; }); }
	function $(sel, el) { return (el || d).querySelector(sel); }
	function $$(sel, el) { return Array.prototype.slice.call((el || d).querySelectorAll(sel)); }
	var nf; try { nf = new Intl.NumberFormat(LOCALE); } catch (e) { nf = new Intl.NumberFormat('en-US'); LOCALE = 'en-US'; }
	function num(n) { return nf.format(Number(n) || 0); }
	function dec(n, p) { var f = Math.pow(10, p == null ? 2 : p); return nf.format(Math.round((Number(n) || 0) * f) / f); }
	function pct(n) { return nf.format(Math.round((Number(n) || 0) * 10) / 10) + '%'; }
	function dur(s) {
		s = Math.round(Number(s) || 0);
		if (s < 60) return num(s) + __('s');
		var m = Math.floor(s / 60), r = s % 60;
		if (m < 60) return num(m) + __('m') + (r ? ' ' + num(r) + __('s') : '');
		return num(Math.floor(m / 60)) + __('h') + ' ' + num(m % 60) + __('m');
	}
	function ms(v) { v = Number(v) || 0; return v ? (v < 1000 ? num(Math.round(v)) + ' ms' : nf.format(Math.round(v / 100) / 10) + __('s')) : '—'; }
	function money(v, cur) {
		cur = cur || A.currency || '';
		try { if (cur) return new Intl.NumberFormat(LOCALE, { style: 'currency', currency: cur, maximumFractionDigits: 2 }).format(Number(v) || 0); } catch (e) {}
		return dec(v, 2) + (cur ? ' ' + cur : '');
	}
	function parseYmd(s) { var p = String(s).split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2], 12)); }
	function ymd(dt) { return dt.toISOString().slice(0, 10); }
	function addDays(s, n) { var dt = parseYmd(s); dt.setUTCDate(dt.getUTCDate() + n); return ymd(dt); }
	function fmtDate(s, opts) { try { return new Intl.DateTimeFormat(LOCALE, Object.assign({ timeZone: 'UTC' }, opts || { month: 'short', day: 'numeric' })).format(parseYmd(String(s).slice(0, 10))); } catch (e) { return s; } }
	function longDate(s) { return fmtDate(s, { year: 'numeric', month: 'short', day: 'numeric' }); }
	function dateTime(s) { try { return new Intl.DateTimeFormat(LOCALE, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' }).format(new Date(String(s).replace(' ', 'T') + 'Z')); } catch (e) { return s; } }
	function clock(s) { try { return new Intl.DateTimeFormat(LOCALE, { hour: '2-digit', minute: '2-digit', timeZone: 'UTC' }).format(new Date(String(s).replace(' ', 'T').slice(0, 16) + ':00Z')); } catch (e) { return String(s).slice(11, 16); } }
	function icon(name) { return '<span class="dashicons dashicons-' + esc(name) + '" aria-hidden="true"></span>'; }

	var regionNames = null, langNames = null;
	try { regionNames = new Intl.DisplayNames([LOCALE], { type: 'region' }); langNames = new Intl.DisplayNames([LOCALE], { type: 'language' }); } catch (e) {}
	function flag(cc) { if (!cc || cc.length !== 2) return '🌐'; return cc.toUpperCase().replace(/./g, function (c) { return String.fromCodePoint(127397 + c.charCodeAt(0)); }); }
	function countryName(cc) { if (!cc) return __('Unknown'); try { return (regionNames && regionNames.of(cc)) || cc; } catch (e) { return cc; } }
	function country(cc) { return flag(cc) + ' ' + esc(countryName(cc)); }
	function language(l) { if (!l) return esc(__('Unknown')); try { return esc(langNames ? langNames.of(l) + ' (' + l + ')' : l); } catch (e) { return esc(l); } }
	var DEVICE = { desktop: __('Desktop'), mobile: __('Mobile'), tablet: __('Tablet'), '': __('Unknown') };
	var DEVICE_ICON = { desktop: 'desktop', mobile: 'smartphone', tablet: 'tablet' };
	var CHANNEL = { direct: __('Direct'), search: __('Search engines'), ai: __('AI assistants'), social: __('Social networks'), website: __('Websites'), campaign: __('Campaigns') };
	var PALETTE = ['#6366f1', '#06b6d4', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#ec4899', '#84cc16', '#64748b', '#0ea5e9'];
	function other(v) { return v === '__other__' ? '<i class="ntaa-muted">' + esc(__('Others')) + '</i>' : null; }

	/* ---------- storage ---------- */
	function store(key, val) {
		try {
			if (val === undefined) return JSON.parse(w.localStorage.getItem('ntaa_' + key) || 'null');
			w.localStorage.setItem('ntaa_' + key, JSON.stringify(val));
		} catch (e) { return null; }
	}

	/* ---------- API ---------- */
	var cache = {};
	function url(path, params) {
		var u = A.rest + path;
		if (params) {
			var q = Object.keys(params).filter(function (k) { return params[k] !== undefined && params[k] !== null && params[k] !== ''; }).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
			if (q) u += (u.indexOf('?') === -1 ? '?' : '&') + q;
		}
		return u;
	}
	function api(path, opts) {
		opts = opts || {};
		var method = opts.method || 'GET';
		var u = url(path, opts.params);
		if (method === 'GET' && opts.cache !== false && cache[u] && Date.now() - cache[u].t < 60000) return Promise.resolve(cache[u].v);
		var init = { method: method, headers: { 'X-WP-Nonce': A.nonce, 'Content-Type': 'application/json' }, credentials: 'same-origin' };
		if (opts.body) init.body = JSON.stringify(opts.body);
		return fetch(u, init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (j) {
				if (!r.ok) throw new Error((j && j.message) || ('HTTP ' + r.status));
				if (method === 'GET') cache[u] = { t: Date.now(), v: j }; else cache = {};
				return j;
			});
		});
	}

	/* ---------- UI bits ---------- */
	function toast(msg, type) {
		var t = d.createElement('div');
		t.className = 'ntaa-toast ' + (type || '');
		t.setAttribute('role', 'status');
		t.textContent = msg;
		d.body.appendChild(t);
		setTimeout(function () { t.classList.add('show'); }, 10);
		setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 3500);
	}
	function modal(title, html, opts) {
		opts = opts || {};
		var wrap = d.createElement('div');
		wrap.className = 'ntaa-modal-wrap ntaa-wrap';
		wrap.innerHTML = '<div class="ntaa-modal' + (opts.wide ? ' wide' : '') + '" role="dialog" aria-modal="true" aria-label="' + esc(title) + '"><div class="ntaa-modal-head"><h2>' + esc(title) + '</h2><button type="button" class="ntaa-icon-btn" data-close aria-label="' + esc(__('Close')) + '">' + icon('no-alt') + '</button></div><div class="ntaa-modal-body">' + html + '</div></div>';
		d.body.appendChild(wrap);
		function close() { wrap.remove(); d.removeEventListener('keydown', key); if (opts.onClose) opts.onClose(); }
		function key(e) { if (e.key === 'Escape') close(); }
		wrap.addEventListener('click', function (e) { if (e.target === wrap || (e.target.closest && e.target.closest('[data-close]'))) close(); });
		d.addEventListener('keydown', key);
		var first = $('input,select,textarea,button:not([data-close])', wrap);
		if (first) setTimeout(function () { first.focus(); }, 30);
		return { el: wrap, body: $('.ntaa-modal-body', wrap), close: close };
	}
	function copy(text, msg) {
		if (navigator.clipboard && w.isSecureContext) { navigator.clipboard.writeText(text).then(function () { toast(msg || __('Copied'), 'ok'); }).catch(function () { legacy(); }); return; }
		legacy();
		function legacy() {
		var ta = d.createElement('textarea'); ta.value = text; d.body.appendChild(ta); ta.select();
		try { d.execCommand('copy'); toast(msg || __('Copied'), 'ok'); } catch (e) {}
		ta.remove();
		}
	}
	function delta(cur, prev, invert) {
		cur = Number(cur) || 0; prev = Number(prev) || 0;
		if (!prev) return '<span class="ntaa-delta neutral">' + (cur ? esc(__('new')) : '—') + '</span>';
		var dd = (cur - prev) / prev * 100, good = invert ? dd < 0 : dd > 0;
		var cls = Math.abs(dd) < 0.5 ? 'neutral' : (good ? 'up' : 'down');
		return '<span class="ntaa-delta ' + cls + '">' + (dd > 0 ? '▲' : (dd < 0 ? '▼' : '')) + ' ' + pct(Math.abs(dd)) + '</span>';
	}
	function empty(text) { return '<div class="ntaa-empty">' + icon('info-outline') + ' ' + esc(text || __('No data recorded for this period yet.')) + '</div>'; }
	function card(title, body, opts) {
		opts = opts || {};
		return '<div class="ntaa-card' + (opts.cls ? ' ' + opts.cls : '') + '"' + (opts.id ? ' id="' + esc(opts.id) + '"' : '') + '><div class="ntaa-card-head"><h3>' + (opts.icon ? icon(opts.icon) : '') + esc(title) + (opts.badge || '') + '</h3>' + (opts.tools || '') + '</div>' + body + '</div>';
	}
	function kpi(label, value, ic, extra) {
		return '<div class="ntaa-kpi"><span class="ntaa-kpi-label">' + (ic ? icon(ic) : '') + esc(label) + '</span><b>' + value + '</b>' + (extra || '') + '</div>';
	}
	function pageCell(v, r) {
		var o = other(v); if (o) return o;
		var title = r && r.title && r.title !== v ? '<span class="ntaa-title">' + esc(r.title) + '</span>' : '';
		var site = A.sites && A.site && A.sites[A.site] && A.sites[A.site].url ? A.sites[A.site].url : A.home;
		return title + '<a class="ntaa-path" href="' + esc(String(site).replace(/\/$/, '') + v) + '" target="_blank" rel="noopener" dir="ltr">' + esc(v || '/') + '</a>';
	}
	function bounceFmt(v) { var c = v < 40 ? 'good' : (v < 70 ? 'mid' : 'bad'); return '<span class="ntaa-speed ' + c + '">' + pct(v) + '</span>'; }
	function speed(v, good, poor, fmt) { if (!v) return '—'; var c = v <= good ? 'good' : (v <= poor ? 'mid' : 'bad'); return '<span class="ntaa-speed ' + c + '">' + (fmt ? fmt(v) : ms(v)) + '</span>'; }

	/* ---------- markdown (escapes first, so AI output can never inject HTML) ---------- */
	function inlineMd(s) {
		s = esc(s);
		s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
		s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
		s = s.replace(/(^|[\s(])\*([^*\s][^*]*)\*/g, '$1<em>$2</em>');
		s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s"]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
		return s;
	}
	var LIST_RE = /^[-*•+]\s+/, OL_RE = /^\d+[.)]\s+/, SEP_RE = /^\|[\s:|-]+\|$/;
	function md(text) {
		var codes = [];
		text = String(text || '').replace(/```[\w-]*\n?([\s\S]*?)```/g, function (_, code) { codes.push(code); return '\u0000CODE' + (codes.length - 1) + '\u0000'; });
		var lines = text.split(/\r?\n/), out = [], i = 0;
		while (i < lines.length) {
			var t = lines[i].trim();
			if (!t) { i++; continue; }
			var cm = t.match(/^\u0000CODE(\d+)\u0000$/);
			if (cm) { out.push('<pre dir="ltr"><code>' + esc(codes[+cm[1]]) + '</code></pre>'); i++; continue; }
			var hm = t.match(/^(#{1,6})\s+(.*)$/);
			if (hm) { var lv = Math.min(6, hm[1].length + 1); out.push('<h' + lv + '>' + inlineMd(hm[2].replace(/#+$/, '')) + '</h' + lv + '>'); i++; continue; }
			if (/^(-{3,}|\*{3,}|_{3,})$/.test(t)) { out.push('<hr>'); i++; continue; }
			if (/^\|.*\|\s*$/.test(t)) {
				var rows = [];
				while (i < lines.length && /^\|.*\|\s*$/.test(lines[i].trim())) { rows.push(lines[i].trim()); i++; }
				var html = '<div class="ntaa-md-table"><table>';
				rows.forEach(function (r, idx) {
					if (SEP_RE.test(r)) return;
					var tag = (idx === 0 && rows[1] && SEP_RE.test(rows[1])) ? 'th' : 'td';
					html += '<tr>' + r.replace(/^\||\|$/g, '').split('|').map(function (c) { return '<' + tag + '>' + inlineMd(c.trim()) + '</' + tag + '>'; }).join('') + '</tr>';
				});
				out.push(html + '</table></div>');
				continue;
			}
			if (LIST_RE.test(t) || OL_RE.test(t)) {
				var ordered = OL_RE.test(t), re = ordered ? OL_RE : LIST_RE, items = [];
				while (i < lines.length) {
					var lt = lines[i].trim();
					if (re.test(lt)) { items.push([lt.replace(re, '')]); i++; }
					else if (lt && /^\s{2,}/.test(lines[i]) && items.length) { items[items.length - 1].push(lt.replace(LIST_RE, '• ')); i++; }
					else break;
				}
				var tg = ordered ? 'ol' : 'ul';
				out.push('<' + tg + '>' + items.map(function (it) { return '<li>' + it.map(inlineMd).join('<br>') + '</li>'; }).join('') + '</' + tg + '>');
				continue;
			}
			if (/^>\s?/.test(t)) {
				var q = [];
				while (i < lines.length && /^>\s?/.test(lines[i].trim())) { q.push(lines[i].trim().replace(/^>\s?/, '')); i++; }
				out.push('<blockquote>' + q.map(inlineMd).join('<br>') + '</blockquote>');
				continue;
			}
			var para = [];
			while (i < lines.length && lines[i].trim() && !/^(#{1,6}\s|[-*•+]\s|\d+[.)]\s|\||>|\u0000CODE)/.test(lines[i].trim())) { para.push(lines[i].trim()); i++; }
			if (!para.length) { para.push(t); i++; }
			out.push('<p>' + para.map(inlineMd).join('<br>') + '</p>');
		}
		return out.join('\n');
	}

	/* ---------- charts ---------- */
	var charts = {};
	var Y_POS = RTL ? 'right' : 'left';
	if (w.Chart) {
		Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Vazirmatn, Tahoma, sans-serif';
		Chart.defaults.color = '#64748b';
		Chart.defaults.plugins.tooltip.rtl = RTL;
		Chart.defaults.plugins.legend.rtl = RTL;
		Chart.defaults.plugins.tooltip.padding = 10;
		Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
	}
	function destroyCharts() { Object.keys(charts).forEach(function (k) { try { charts[k].destroy(); } catch (e) {} delete charts[k]; }); }
	function chart(id, cfg) {
		var el = d.getElementById(id);
		if (!el || !w.Chart) return null;
		if (charts[id]) charts[id].destroy();
		charts[id] = new Chart(el, cfg);
		return charts[id];
	}
	function doughnut(id, labels, values) {
		return chart(id, {
			type: 'doughnut',
			data: { labels: labels, datasets: [{ data: values, backgroundColor: PALETTE, borderWidth: 2, borderColor: '#fff' }] },
			options: { cutout: '64%', maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, padding: 14 } }, tooltip: { callbacks: { label: function (c) { var tot = c.dataset.data.reduce(function (a, b) { return a + b; }, 0) || 1; return ' ' + c.label + ': ' + num(c.raw) + ' (' + pct(c.raw / tot * 100) + ')'; } } } } }
		});
	}
	function hbar(id, labels, values, color, fmt) {
		return chart(id, {
			type: 'bar',
			data: { labels: labels, datasets: [{ data: values, backgroundColor: color || '#6366f1', borderRadius: 6, barThickness: 16 }] },
			options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + (fmt || num)(c.raw); } } } }, scales: { x: { reverse: RTL, grid: { color: '#f1f5f9' }, ticks: { callback: function (v) { return (fmt || num)(v); } } }, y: { position: Y_POS, grid: { display: false } } } }
		});
	}
	function vbar(id, labels, datasets, fmt) {
		return chart(id, {
			type: 'bar',
			data: { labels: labels, datasets: datasets.map(function (ds, i) { return Object.assign({ backgroundColor: PALETTE[i % PALETTE.length], borderRadius: 4 }, ds); }) },
			options: { maintainAspectRatio: false, plugins: { legend: { display: datasets.length > 1, position: 'top', align: 'end' }, tooltip: { callbacks: { label: function (c) { return ' ' + (c.dataset.label ? c.dataset.label + ': ' : '') + (fmt || num)(c.raw); } } } }, scales: { x: { reverse: RTL, grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true } }, y: { position: Y_POS, beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0, callback: function (v) { return (fmt || num)(v); } } } } }
		});
	}
	function line(id, labels, datasets, fmt, opts) {
		opts = opts || {};
		var ann = opts.annotations || [];
		var annPlugin = {
			id: 'ntaaAnnotations',
			afterDatasetsDraw: function (c) {
				if (!ann.length) return;
				var x = c.scales.x, area = c.chartArea, ctx = c.ctx;
				ann.forEach(function (a) {
					var idx = opts.keys ? opts.keys.indexOf(a.day) : -1;
					if (idx < 0) return;
					var px = x.getPixelForValue(idx);
					ctx.save(); ctx.strokeStyle = '#f59e0b'; ctx.setLineDash([4, 4]); ctx.beginPath(); ctx.moveTo(px, area.top); ctx.lineTo(px, area.bottom); ctx.stroke();
					ctx.setLineDash([]); ctx.fillStyle = '#f59e0b'; ctx.beginPath(); ctx.arc(px, area.top + 4, 4, 0, Math.PI * 2); ctx.fill(); ctx.restore();
				});
			}
		};
		return chart(id, {
			type: 'line',
			data: {
				labels: labels,
				datasets: datasets.map(function (ds, i) {
					var color = ds.color || PALETTE[i % PALETTE.length];
					return Object.assign({
						borderColor: color, borderWidth: 2.5, tension: 0.35, pointRadius: labels.length > 45 ? 0 : 3, pointHoverRadius: 5, fill: i === 0,
						backgroundColor: function (ctx) {
							var c = ctx.chart, area = c.chartArea;
							if (!area) return 'rgba(99,102,241,.12)';
							var g = c.ctx.createLinearGradient(0, area.top, 0, area.bottom);
							g.addColorStop(0, 'rgba(99,102,241,.25)'); g.addColorStop(1, 'rgba(99,102,241,0)');
							return g;
						}
					}, ds);
				})
			},
			options: {
				maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
				plugins: {
					legend: { display: datasets.length > 1, position: 'top', align: 'end' },
					tooltip: { callbacks: {
						label: function (c) { return ' ' + c.dataset.label + ': ' + (fmt || num)(c.raw); },
						afterBody: function (items) { if (!ann.length || !opts.keys) return ''; var k = opts.keys[items[0].dataIndex]; return ann.filter(function (a) { return a.day === k; }).map(function (a) { return '📌 ' + a.note; }); }
					} }
				},
				scales: { x: { reverse: RTL, grid: { display: false }, ticks: { maxTicksLimit: 10, autoSkip: true, maxRotation: 0 } }, y: { position: Y_POS, beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { maxTicksLimit: 6, callback: function (v) { return (fmt || num)(v); } } } }
			},
			plugins: [annPlugin]
		});
	}

	/* ---------- tables ---------- */
	var tables = {};
	function table(id, title, cols, rows, opts) {
		opts = opts || {};
		rows = rows || [];
		tables[id] = { cols: cols, rows: rows, evo: opts.evo, opts: opts };
		var barKey = opts.bar, max = 0;
		if (barKey) rows.forEach(function (r) { if (r[cols[0].key] !== '__other__') max = Math.max(max, Number(r[barKey]) || 0); });
		var lim = opts.limit || rows.length;
		var body = rows.slice(0, lim).map(function (r, ri) {
			var wd = barKey && max ? Math.round((Number(r[barKey]) || 0) / max * 100) : 0;
			return '<tr' + (opts.onRow ? ' class="ntaa-click" tabindex="0" data-row="' + ri + '" data-table="' + esc(id) + '"' : '') + '>' + cols.map(function (c, ci) {
				var v = c.fmt ? c.fmt(r[c.key], r) : (other(r[c.key]) || esc(r[c.key]));
				var bar = ci === 0 && barKey;
				if (ci === 0 && opts.evo && r[cols[0].key] !== '__other__') v += '<button type="button" class="ntaa-evo" data-evo="' + esc(id) + '" data-r="' + ri + '" title="' + esc(__('Row evolution')) + '" aria-label="' + esc(__('Row evolution')) + '">' + icon('chart-line') + '</button>';
				return '<td class="' + (c.num ? 'num' : 'main') + (bar ? ' bar' : '') + '"' + (bar ? ' style="--w:' + Math.min(100, wd) + '%"' : '') + ' data-label="' + esc(c.label) + '">' + v + '</td>';
			}).join('') + '</tr>';
		}).join('');
		if (opts.onRow) tables[id].onRow = opts.onRow;
		var tools = (opts.tools || '') + (rows.length ? '<button type="button" class="ntaa-icon-btn" data-csv="' + esc(id) + '" title="' + esc(A.pro ? __('Export') : __('Download CSV')) + '" aria-label="' + esc(A.pro ? __('Export') : __('Download CSV')) + '">' + icon('download') + '</button>' : '');
		var more = rows.length > lim ? '<button type="button" class="button-link ntaa-more" data-more="' + esc(id) + '">' + esc(sprintf(__('Show all %s rows'), num(rows.length))) + '</button>' : '';
		var inner = rows.length ? '<div class="ntaa-table-wrap"><table class="ntaa-table"><thead><tr>' + cols.map(function (c) { return '<th class="' + (c.num ? 'num' : '') + '"' + (c.tip ? ' title="' + esc(c.tip) + '"' : '') + '>' + esc(c.label) + '</th>'; }).join('') + '</tr></thead><tbody>' + body + '</tbody></table></div>' + more : empty(opts.empty);
		if (opts.bare) return inner;
		return '<div class="ntaa-card ntaa-table-card" data-tablecard="' + esc(id) + '"><div class="ntaa-card-head"><h3>' + (opts.icon ? icon(opts.icon) : '') + esc(title) + (opts.badge || '') + '</h3><div class="ntaa-tools">' + tools + '</div></div>' + (opts.intro ? '<p class="ntaa-muted ntaa-intro">' + opts.intro + '</p>' : '') + inner + '</div>';
	}
	d.addEventListener('click', function (e) {
		var fb = e.target.closest && e.target.closest('[data-fmt]');
		if (fb) { var menu = fb.closest('.ntaa-dl-menu'); download(menu.getAttribute('data-for'), fb.getAttribute('data-fmt')); menu.remove(); return; }
		var b = e.target.closest && e.target.closest('[data-csv]');
		$$('.ntaa-dl-menu').forEach(function (x) { if (!b || x.getAttribute('data-for') !== b.getAttribute('data-csv')) x.remove(); });
		if (b) {
			var id0 = b.getAttribute('data-csv');
			if (!A.pro) { download(id0, 'csv'); return; }
			if ($('.ntaa-dl-menu[data-for="' + id0 + '"]')) { $('.ntaa-dl-menu[data-for="' + id0 + '"]').remove(); return; }
			var mn = d.createElement('div');
			mn.className = 'ntaa-dl-menu';
			mn.setAttribute('data-for', id0);
			mn.innerHTML = [['csv', 'CSV (Excel)'], ['tsv', 'TSV'], ['xml', 'XML'], ['json', 'JSON']].map(function (f) { return '<button type="button" data-fmt="' + f[0] + '">' + esc(f[1]) + '</button>'; }).join('');
			b.parentNode.style.position = 'relative';
			b.parentNode.appendChild(mn);
			return;
		}
		var m = e.target.closest && e.target.closest('[data-more]');
		if (m) {
			var id = m.getAttribute('data-more'), tt = tables[id], cardEl = m.closest('.ntaa-card');
			if (tt && cardEl) {
				var holder = d.createElement('div');
				holder.innerHTML = table(id, $('h3', cardEl).textContent, tt.cols, tt.rows, Object.assign({}, tt.opts, { limit: tt.rows.length }));
				cardEl.replaceWith(holder.firstChild);
			}
			return;
		}
		var ev = e.target.closest && e.target.closest('[data-evo]');
		if (ev) {
			var te = tables[ev.getAttribute('data-evo')];
			if (te && te.evo) { var rr = te.rows[+ev.getAttribute('data-r')]; rowEvolution(te.evo.report, te.evo.label(rr), te.evo.title ? te.evo.title(rr) : te.evo.label(rr)); }
			return;
		}
		var row = e.target.closest && e.target.closest('tr[data-row]');
		if (row && !e.target.closest('a,button')) {
			var tb = tables[row.getAttribute('data-table')];
			if (tb && tb.onRow) tb.onRow(tb.rows[+row.getAttribute('data-row')]);
		}
	});
	d.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.matches && e.target.matches('tr[data-row]')) e.target.click(); });

	function download(id, fmt) {
		var t = tables[id];
		if (!t) return;
		var cols = t.cols.filter(function (c) { return c.label; });
		function v(c, r) { var x = c.csv ? c.csv(r[c.key], r) : r[c.key]; return x == null ? '' : String(x).replace(/\x1f/g, ' / '); }
		var body, type;
		if (fmt === 'json') {
			body = JSON.stringify(t.rows.map(function (r) { var o = {}; cols.forEach(function (c) { o[c.label] = c.csv ? c.csv(r[c.key], r) : r[c.key]; }); return o; }), null, 2); type = 'application/json';
		} else if (fmt === 'xml') {
			var x = function (s2) { return String(s2).replace(/[<>&"']/g, function (c) { return { '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&apos;' }[c]; }).replace(/[\x00-\x08\x0b\x0c\x0e-\x1f]/g, ''); };
			body = '<?xml version="1.0" encoding="UTF-8"?>\n<report name="' + x(id) + '" from="' + state.from + '" to="' + state.to + '">\n' + t.rows.map(function (r) { return '  <row>' + cols.map(function (c) { return '<col name="' + x(c.label) + '">' + x(v(c, r)) + '</col>'; }).join('') + '</row>'; }).join('\n') + '\n</report>\n'; type = 'application/xml';
		} else {
			var sep = fmt === 'tsv' ? '\t' : ',';
			var q = fmt === 'tsv' ? function (s2) { return String(s2).replace(/[\t\r\n]+/g, ' '); } : function (s2) { return '"' + String(s2).replace(/"/g, '""') + '"'; };
			body = (fmt === 'csv' ? '\ufeff' : '') + cols.map(function (c) { return q(c.label); }).join(sep) + '\n' + t.rows.map(function (r) { return cols.map(function (c) { return q(v(c, r)); }).join(sep); }).join('\n');
			type = fmt === 'tsv' ? 'text/tab-separated-values' : 'text/csv';
		}
		var a = d.createElement('a');
		a.href = URL.createObjectURL(new Blob([body], { type: type + ';charset=utf-8' }));
		a.download = 'nodethemes-analytics-' + id + '-' + state.from + '_' + state.to + '.' + fmt;
		d.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
	}

	/* ---------- row evolution ---------- */
	var SEP = '\x1f';
	function evoMetrics(family) {
		function r(a, b, m) { return function (p) { return p[b] ? p[a] / p[b] * (m || 1) : 0; }; }
		function c(k, div) { return function (p) { return div ? p[k] / div : p[k]; }; }
		var M = {
			visit: [['visits', __('Visits'), c('c1'), num], ['pageviews', __('Pageviews'), c('c2'), num], ['bounce', __('Bounce rate'), r('c3', 'c1', 100), pct], ['dur', __('Avg. visit duration'), r('c4', 'c1'), dur], ['conv', __('Goal conversions'), c('c5'), num], ['cr', __('Conversion rate'), r('c11', 'c1', 100), pct], ['rev', __('Revenue'), c('c8', 100), money], ['new', __('New visits'), c('c9'), num]],
			page: [['views', __('Views'), c('c1'), num], ['unique', __('Unique views'), c('c2'), num], ['time', __('Avg. time on page'), r('c3', 'c4'), dur], ['scroll', __('Scroll depth'), r('c5', 'c6'), pct], ['load', __('Load time'), r('c7', 'c8'), ms]],
			event: [['total', __('Total'), c('c1'), num], ['visits', __('Visits'), c('c2'), num], ['value', __('Value'), c('c3', 100), num]],
			search: [['searches', __('Searches'), c('c1'), num], ['none', __('No results'), c('c2'), num], ['visits', __('Visits'), c('c3'), num]],
			goal: [['conv', __('Conversions'), c('c1'), num], ['value', __('Value'), c('c2', 100), money]],
			product: [['views', __('Views'), c('c1'), num], ['adds', __('Added to cart'), c('c2'), num], ['sold', __('Sold'), c('c3'), num], ['rev', __('Revenue'), c('c4', 100), money], ['orders', __('Orders'), c('c5'), num]],
			category: [['sold', __('Sold'), c('c1'), num], ['rev', __('Revenue'), c('c2', 100), money], ['orders', __('Orders'), c('c3'), num]]
		};
		M.evlabel = M.event;
		return M[family] || M.visit;
	}
	function rowEvolution(report, label, title) {
		var from = state.from, to = state.to;
		if (Math.round((parseYmd(to) - parseYmd(from)) / 864e5) < 13) from = addDays(to, -29);
		var m = modal(__('Row evolution'), '<p class="ntaa-evo-title"><b>' + esc(String(title).split(SEP).filter(Boolean).join(' › ') || __('(not set)')) + '</b> <span class="ntaa-muted">' + esc(longDate(from) + ' – ' + longDate(to)) + '</span></p><div class="ntaa-evo-body"><div class="ntaa-skeleton"><div></div><div></div></div></div>', { wide: true });
		api('evolution', { params: Object.assign(params({ report: report, label: label }), { from: from, to: to }) }).then(function (r) {
			var mets = evoMetrics(r.family), sel = store('evo_' + r.family) || mets[0][0];
			if (!mets.some(function (x) { return x[0] === sel; })) sel = mets[0][0];
			var box = $('.ntaa-evo-body', m.body);
			box.innerHTML = '<div class="ntaa-evo-tools"><label>' + esc(__('Metric')) + ' <select data-evo-m>' + mets.map(function (x) { return '<option value="' + x[0] + '"' + (x[0] === sel ? ' selected' : '') + '>' + esc(x[1]) + '</option>'; }).join('') + '</select></label><span class="ntaa-evo-stats"></span></div><div class="ntaa-chart-box"><canvas id="ch-evo"></canvas></div><p class="description">' + esc(__('Daily values of this row. Rows that were too small on a day to be stored individually are counted under “Others” for that day.')) + '</p>';
			function draw() {
				var mt = mets.filter(function (x) { return x[0] === sel; })[0];
				var vals = r.points.map(mt[2]);
				var tot = vals.reduce(function (a, b) { return a + b; }, 0), max = Math.max.apply(null, vals.concat([0])), min = Math.min.apply(null, vals.length ? vals : [0]);
				var isSum = mt[3] === num || mt[3] === money;
				$('.ntaa-evo-stats', box).innerHTML = (isSum ? esc(__('Total')) + ' <b>' + mt[3](tot) + '</b> · ' : '') + esc(__('Daily average')) + ' <b>' + (mt[3] === num ? dec(vals.length ? tot / vals.length : 0, 1) : mt[3](vals.length ? tot / vals.length : 0)) + '</b> · ' + esc(__('Min')) + ' <b>' + mt[3](min) + '</b> · ' + esc(__('Max')) + ' <b>' + mt[3](max) + '</b>';
				line('ch-evo', r.points.map(function (p) { return fmtDate(p.date); }), [{ label: mt[1], data: vals }], mt[3]);
			}
			$('[data-evo-m]', box).addEventListener('change', function () { sel = this.value; store('evo_' + r.family, sel); draw(); });
			draw();
		}).catch(function (e) { $('.ntaa-evo-body', m.body).innerHTML = '<div class="ntaa-error">' + esc(e.message) + '</div>'; });
	}

	/* ---------- state: range, site, segment ---------- */
	var PRESETS = [
		{ id: 'today', label: __('Today') }, { id: 'yesterday', label: __('Yesterday') }, { id: '7', label: __('7 days') },
		{ id: '30', label: __('30 days') }, { id: '90', label: __('90 days') }, { id: '365', label: __('12 months') }
	];
	function presetRange(id) {
		var t = A.today || ymd(new Date());
		if (id === 'today') return [t, t];
		if (id === 'yesterday') { var y = addDays(t, -1); return [y, y]; }
		var n = parseInt(id, 10) || 30;
		return [addDays(t, -(n - 1)), t];
	}
	var saved = store('range') || {};
	var state = { preset: saved.preset || '30', from: '', to: '', site: parseInt(store('site'), 10) || 1, segment: store('segment') || null, listeners: [] };
	if (!A.sites || !A.sites[state.site]) state.site = 1;
	if (state.preset === 'custom' && saved.from && saved.to) { state.from = saved.from; state.to = saved.to; } else { var pr = presetRange(state.preset); state.from = pr[0]; state.to = pr[1]; }
	A.site = state.site;
	function params(extra) {
		var p = { from: state.from, to: state.to, site: state.site };
		if (state.segment && state.segment.conds && state.segment.conds.length) p.segment = JSON.stringify(state.segment.conds);
		return Object.assign(p, extra || {});
	}
	function changed() { state.listeners.forEach(function (fn) { try { fn(); } catch (e) {} }); }
	function onChange(fn) { state.listeners.push(fn); }

	function rangeBar() {
		return '<div class="ntaa-range">' +
			'<div class="ntaa-presets" role="group" aria-label="' + esc(__('Date range')) + '">' + PRESETS.map(function (p) { return '<button type="button" data-preset="' + p.id + '" class="' + (state.preset === p.id ? 'active' : '') + '">' + esc(p.label) + '</button>'; }).join('') + '</div>' +
			'<div class="ntaa-custom"><input type="date" id="ntaa-from" value="' + state.from + '" max="' + A.today + '" aria-label="' + esc(__('From')) + '"><span>' + esc(__('to')) + '</span><input type="date" id="ntaa-to" value="' + state.to + '" max="' + A.today + '" aria-label="' + esc(__('To')) + '"><button type="button" class="button" id="ntaa-apply">' + esc(__('Apply')) + '</button></div>' +
			'<div class="ntaa-range-label">' + icon('calendar-alt') + ' ' + esc(longDate(state.from)) + (state.from !== state.to ? ' – ' + esc(longDate(state.to)) : '') + '</div>' +
			'</div>';
	}
	function segmentLabel() {
		if (!state.segment || !state.segment.conds || !state.segment.conds.length) return __('All visits');
		return state.segment.name || __('Custom segment');
	}
	function siteSwitcher() {
		var ids = A.sites ? Object.keys(A.sites) : [];
		if (ids.length < 2) return '';
		return '<label class="ntaa-site"><span class="screen-reader-text">' + esc(__('Website')) + '</span>' + icon('admin-site-alt3') + '<select id="ntaa-site">' + ids.map(function (id) { return '<option value="' + esc(id) + '"' + (+id === state.site ? ' selected' : '') + '>' + esc(A.sites[id].name) + '</option>'; }).join('') + '</select></label>';
	}
	function toolbar(opts) {
		opts = opts || {};
		return '<div class="ntaa-toolbar">' + siteSwitcher() + (opts.segments === false ? '' : '<button type="button" class="ntaa-segbtn' + (state.segment && state.segment.conds && state.segment.conds.length ? ' on' : '') + '" id="ntaa-segbtn">' + icon('filter') + '<span>' + esc(segmentLabel()) + '</span></button>') + '</div>' + rangeBar();
	}
	function bindToolbar(root) {
		$$('[data-preset]', root).forEach(function (b) {
			b.addEventListener('click', function () {
				state.preset = b.getAttribute('data-preset');
				var r = presetRange(state.preset); state.from = r[0]; state.to = r[1];
				store('range', { preset: state.preset });
				refreshToolbar(root); changed();
			});
		});
		var apply = $('#ntaa-apply', root);
		if (apply) apply.addEventListener('click', function () {
			var f = $('#ntaa-from', root).value, t = $('#ntaa-to', root).value;
			if (!f || !t) return;
			if (f > t) { var x = f; f = t; t = x; }
			state.preset = 'custom'; state.from = f; state.to = t;
			store('range', { preset: 'custom', from: f, to: t });
			refreshToolbar(root); changed();
		});
		var site = $('#ntaa-site', root);
		if (site) site.addEventListener('change', function () { state.site = parseInt(site.value, 10) || 1; A.site = state.site; store('site', state.site); changed(); });
		var seg = $('#ntaa-segbtn', root);
		if (seg) seg.addEventListener('click', function () { segmentEditor(function () { refreshToolbar(root); changed(); }); });
	}
	function refreshToolbar(root) {
		var holder = root.classList && root.classList.contains('ntaa-toolbar-wrap') ? root : $('.ntaa-toolbar-wrap', root);
		if (!holder) return;
		holder.innerHTML = toolbar(holder._opts);
		bindToolbar(holder);
	}
	function mountToolbar(el, opts) {
		el.classList.add('ntaa-toolbar-wrap');
		el._opts = opts || {};
		el.innerHTML = toolbar(opts);
		bindToolbar(el);
	}

	/* ---------- segment editor ---------- */
	var segMeta = null;
	function segmentEditor(done) {
		var load = segMeta ? Promise.resolve(segMeta) : api('segments', { cache: false }).then(function (m) { segMeta = m; return m; });
		load.then(function (meta) {
			var conds = state.segment && state.segment.conds ? JSON.parse(JSON.stringify(state.segment.conds)) : [];
			if (!conds.length) conds.push({ f: 'device', o: 'eq', v: [''] });
			var m = modal(__('Segment: filter all reports'), '<div class="ntaa-seg-saved"></div><div class="ntaa-seg-conds"></div><p><button type="button" class="button" data-add>' + icon('plus-alt2') + esc(__('Add condition')) + '</button></p><p class="description">' + esc(__('Conditions are combined with AND. Separate several values with | to match any of them. Unsaved segments are calculated live from the visit log, which can be slow on busy sites over long periods — save them with “Pre-process” to make them as fast as normal reports.')) + '</p><div class="ntaa-seg-actions"><button type="button" class="button button-primary" data-apply>' + esc(__('Apply segment')) + '</button> <button type="button" class="button" data-clear>' + esc(__('Show all visits')) + '</button><span class="ntaa-seg-save"><input type="text" data-name placeholder="' + esc(__('Segment name')) + '" value="' + esc(state.segment && state.segment.name || '') + '"> <label><input type="checkbox" data-shared> ' + esc(__('Share with other users')) + '</label>' + (A.canManage ? ' <label title="' + esc(__('Archived every night like normal reports, so it loads instantly. Past days are processed in the background.')) + '"><input type="checkbox" data-pre> ' + esc(__('Pre-process (fast)')) + '</label>' : '') + ' <button type="button" class="button" data-save>' + esc(__('Save')) + '</button></span></div>', { wide: true });
			function render() {
				$('.ntaa-seg-conds', m.body).innerHTML = conds.map(function (c, i) {
					return '<div class="ntaa-seg-row" data-i="' + i + '"><select data-k="f">' + meta.fields.map(function (f) { return '<option value="' + esc(f.id) + '"' + (f.id === c.f ? ' selected' : '') + '>' + esc(f.label) + '</option>'; }).join('') + '</select>' +
						'<select data-k="o">' + Object.keys(meta.operators).map(function (o) { return '<option value="' + o + '"' + (o === c.o ? ' selected' : '') + '>' + esc(meta.operators[o]) + '</option>'; }).join('') + '</select>' +
						'<input type="text" data-k="v" value="' + esc((c.v || []).join('|')) + '" placeholder="' + esc(segHint(c.f)) + '">' +
						'<button type="button" class="ntaa-icon-btn" data-del="' + i + '" aria-label="' + esc(__('Remove')) + '">' + icon('trash') + '</button></div>';
				}).join('');
				$('.ntaa-seg-saved', m.body).innerHTML = meta.saved.length ? '<div class="ntaa-chips">' + meta.saved.map(function (s) { return '<span class="ntaa-chip"><button type="button" data-use="' + s.id + '">' + (s.pre ? '⚡ ' : '') + esc(s.name) + '</button>' + (s.mine || A.canManage ? '<button type="button" class="x" data-rm="' + s.id + '" aria-label="' + esc(__('Delete')) + '">×</button>' : '') + '</span>'; }).join('') + '</div>' : '';
			}
			function segHint(f) { return { channel: 'search|ai|social', device: 'mobile|desktop|tablet', country: 'US|GB', referrer: 'google.com' }[f] || ''; }
			function collect() {
				$$('.ntaa-seg-row', m.body).forEach(function (row) {
					var i = +row.getAttribute('data-i');
					conds[i] = { f: $('[data-k=f]', row).value, o: $('[data-k=o]', row).value, v: $('[data-k=v]', row).value.split('|').map(function (s) { return s.trim(); }).filter(Boolean) };
				});
				return conds.filter(function (c) { return c.v.length; });
			}
			render();
			m.body.addEventListener('change', function () { collect(); render(); });
			m.body.addEventListener('click', function (e) {
				var t = e.target.closest('button'); if (!t) return;
				if (t.hasAttribute('data-add')) { collect(); conds.push({ f: 'channel', o: 'eq', v: [] }); render(); }
				else if (t.hasAttribute('data-del')) { collect(); conds.splice(+t.getAttribute('data-del'), 1); render(); }
				else if (t.hasAttribute('data-apply')) { state.segment = { name: $('[data-name]', m.body).value, conds: collect() }; store('segment', state.segment); m.close(); done(); }
				else if (t.hasAttribute('data-clear')) { state.segment = null; store('segment', null); m.close(); done(); }
				else if (t.hasAttribute('data-use')) { var s = meta.saved.filter(function (x) { return x.id === +t.getAttribute('data-use'); })[0]; if (s) { state.segment = { name: s.name, conds: s.conds, id: s.id }; store('segment', state.segment); m.close(); done(); } }
				else if (t.hasAttribute('data-rm')) { if (!w.confirm(__('Delete this segment?'))) return; api('segments/' + t.getAttribute('data-rm'), { method: 'DELETE' }).then(function () { segMeta = null; m.close(); segmentEditor(done); }).catch(function (er) { toast(er.message, 'err'); }); }
				else if (t.hasAttribute('data-save')) {
					var name = $('[data-name]', m.body).value.trim();
					if (!name) { toast(__('Please give the segment a name.'), 'err'); return; }
					api('segments', { method: 'POST', body: { name: name, conds: collect(), shared: $('[data-shared]', m.body).checked ? 1 : 0, pre: $('[data-pre]', m.body) && $('[data-pre]', m.body).checked ? 1 : 0, id: state.segment && state.segment.id } }).then(function (s) {
						segMeta = null; state.segment = { name: s.name, conds: s.conds, id: s.id }; store('segment', state.segment); toast(__('Segment saved'), 'ok'); m.close(); done();
					}).catch(function (er) { toast(er.message, 'err'); });
				}
			});
		}).catch(function (e) { toast(e.message, 'err'); });
	}

	/* ---------- header & support ---------- */
	function header(title, sub, extra) {
		return '<header class="ntaa-header"><div class="ntaa-brand"><span class="ntaa-logo">' + icon('chart-area') + '</span><div><h1>' + esc(title) + (A.pro ? ' <span class="ntaa-protag on">PRO</span>' : '') + '</h1><p>' + esc(sub) + '</p></div></div>' + (extra || '') + '</header>';
	}
	function support() {
		var s = A.support || {};
		return '<div class="ntaa-support"><strong>' + esc(__('Need help?')) + '</strong> ' + esc(__('Email')) + ' <a href="mailto:' + esc(s.email) + '">' + esc(s.email) + '</a> · WhatsApp <a href="' + esc(s.whatsapp_url) + '" target="_blank" rel="noopener">' + esc(s.whatsapp) + '</a> · Telegram <a href="' + esc(s.telegram) + '" target="_blank" rel="noopener">@nodethemes</a></div>';
	}
	function proLock(title, text, features) {
		var href = A.proInstalled ? A.licenseUrl : A.upgradeUrl;
		return '<div class="ntaa-card ntaa-pro-box ntaa-pro-box--list"><div><h2>' + icon('lock') + esc(title) + ' <span class="ntaa-protag">PRO</span></h2><p>' + esc(text) + '</p>' +
			(features && features.length ? '<ul class="ntaa-checks">' + features.map(function (f) { return '<li>' + esc(f) + '</li>'; }).join('') + '</ul>' : '') +
			'<p><a class="button button-primary" href="' + esc(href) + '"' + (A.proInstalled ? '' : ' target="_blank" rel="noopener"') + '>' + esc(A.proInstalled ? __('Activate license') : __('Get Pro')) + '</a></p></div></div>';
	}

	/* ---------- navigation registry (the Pro add-on adds items) ---------- */
	var sections = [];
	function section(id, label, ic) { var s = sections.filter(function (x) { return x.id === id; })[0]; if (!s) { s = { id: id, label: label, icon: ic, items: [] }; sections.push(s); } return s; }
	function addItem(sectionId, item) {
		var s = section(sectionId);
		var i = s.items.findIndex(function (x) { return x.id === item.id; });
		if (i >= 0) s.items[i] = item; else s.items.push(item);
	}

	var readyFns = [];
	function ready(fn) { readyFns.push(fn); }
	w.addEventListener('load', function () { readyFns.forEach(function (fn) { try { fn(); } catch (e) { if (w.console) console.error(e); } }); });

	w.NTAA = {
		A: A, __: __, sprintf: sprintf, esc: esc, $: $, $$: $$, num: num, dec: dec, pct: pct, dur: dur, ms: ms, money: money,
		ymd: ymd, addDays: addDays, fmtDate: fmtDate, longDate: longDate, dateTime: dateTime, clock: clock, icon: icon,
		flag: flag, country: country, countryName: countryName, language: language, DEVICE: DEVICE, DEVICE_ICON: DEVICE_ICON, CHANNEL: CHANNEL, PALETTE: PALETTE, other: other,
		store: store, api: api, toast: toast, modal: modal, copy: copy, delta: delta, empty: empty, card: card, kpi: kpi, pageCell: pageCell, bounceFmt: bounceFmt, speed: speed, md: md,
		chart: chart, charts: charts, destroyCharts: destroyCharts, doughnut: doughnut, hbar: hbar, vbar: vbar, line: line, table: table,
		state: state, params: params, onChange: onChange, mountToolbar: mountToolbar, header: header, support: support, proLock: proLock,
		sections: sections, section: section, addItem: addItem, ready: ready, RTL: RTL, LOCALE: LOCALE, rowEvolution: rowEvolution, SEP: SEP, evoMetrics: evoMetrics
	};
})(window, document);
