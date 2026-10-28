/*! NodeThemes Analytics AI tracker 2.0 – lightweight, privacy-first */
(function (w, d) {
	'use strict';
	var C = w.NTAAConfig;
	if (!C || !C.endpoint || w.__ntaaLoaded) return;
	w.__ntaaLoaded = true;

	var nav = w.navigator;
	var pending = w.ntaa && w.ntaa.q ? w.ntaa.q : [];
	var hooks = {};
	var dims = {};
	var api;

	function noopApi(reason) {
		w.ntaa = {
			track: function () {}, trackGoal: function () {}, trackOrder: function () {}, pageview: function () {},
			setCustomDimension: function () {}, setConsent: function () {}, optOut: function () {}, optIn: optIn,
			on: function () {}, send: function () {}, pvId: function () { return ''; }, config: C, active: false, reason: reason, q: pending
		};
	}
	function cookie(name) {
		var m = d.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}
	function setCookie(name, value, days) {
		var secure = location.protocol === 'https:' ? '; Secure' : '';
		d.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + Math.round(days * 86400) + '; SameSite=Lax' + secure;
	}
	function optIn() { setCookie('ntaa_optout', '', 0); }

	if (cookie('ntaa_optout') === '1') return noopApi('optout');
	if (C.dnt && (nav.doNotTrack === '1' || w.doNotTrack === '1' || nav.globalPrivacyControl === true)) return noopApi('dnt');
	if (nav.webdriver) return noopApi('automation');

	function rnd(bytes) {
		var a = new Uint8Array(bytes), s = '';
		try { (w.crypto || w.msCrypto).getRandomValues(a); } catch (e) { for (var j = 0; j < bytes; j++) a[j] = Math.floor(Math.random() * 256); }
		for (var i = 0; i < a.length; i++) s += ('0' + a[i].toString(16)).slice(-2);
		return s;
	}

	/* ---------- consent ---------- */
	function consentFromCmp() {
		try { if (typeof w.wp_has_consent === 'function') return !!w.wp_has_consent('statistics'); } catch (e) {}
		var c = cookie('ntaa_consent');
		return c === '1' ? true : (c === '0' ? false : null);
	}
	var consent = consentFromCmp();
	var mode = C.consent || 'off';
	function cookieAllowed() { return !C.cookieless && (mode === 'off' || consent === true); }
	function trackingAllowed() { return mode !== 'strict' || consent === true; }

	/* ---------- visitor id (cookie mode only) ---------- */
	var vid = '';
	function loadVid() {
		vid = '';
		if (!cookieAllowed()) return;
		try { vid = w.localStorage.getItem('ntaa_vid') || ''; } catch (e) {}
		if (!/^[a-f0-9]{16}$/.test(vid)) vid = cookie('ntaa_vid');
		if (!/^[a-f0-9]{16}$/.test(vid)) vid = rnd(8);
		try { w.localStorage.setItem('ntaa_vid', vid); } catch (e) {}
		setCookie('ntaa_vid', vid, 395); // Lets the server attribute WooCommerce orders to the visit.
	}
	function forgetVid() {
		vid = '';
		try { w.localStorage.removeItem('ntaa_vid'); } catch (e) {}
		setCookie('ntaa_vid', '', 0);
	}
	loadVid();

	/* ---------- sending (batched) ---------- */
	var queue = [], timer = null;
	function post(body, beacon) {
		if (beacon && nav.sendBeacon && body.length < 60000) {
			try { if (nav.sendBeacon(C.endpoint, new Blob([body], { type: 'text/plain' }))) return; } catch (e) {}
		}
		if (!w.fetch) return;
		try {
			w.fetch(C.endpoint, { method: 'POST', body: body, headers: { 'Content-Type': 'text/plain' }, credentials: 'same-origin', keepalive: body.length < 60000 }).catch(function () {});
		} catch (e) {}
	}
	function flush(beacon) {
		if (timer) { clearTimeout(timer); timer = null; }
		if (!queue.length) return;
		var batch = queue.splice(0, 25);
		post(JSON.stringify({ b: batch }), beacon);
		if (queue.length) flush(beacon);
	}
	function send(hit, now) {
		if (!trackingAllowed()) return;
		hit.s = C.s || 1;
		if (vid) hit.vid = vid;
		queue.push(hit);
		if (now) flush(true);
		else if (!timer) timer = setTimeout(function () { flush(false); }, 60);
	}

	/* ---------- page view state ---------- */
	var cur = null, engaged = 0, visibleSince = 0, maxScroll = 0, firstLoad = true, lastUrl = w.location.href, started = false;
	var vt = { lcp: 0, cls: 0, inp: 0, fcp: 0, ttfb: 0 };

	function scrollPct() {
		var el = d.documentElement, b = d.body;
		var h = Math.max(el.scrollHeight, b ? b.scrollHeight : 0) - w.innerHeight;
		if (h <= 0) return 100;
		return Math.min(100, Math.round(((w.pageYOffset || el.scrollTop) / h) * 100));
	}
	var ticking = false;
	w.addEventListener('scroll', function () {
		if (ticking) return;
		ticking = true;
		(w.requestAnimationFrame || setTimeout)(function () { var p = scrollPct(); if (p > maxScroll) maxScroll = p; ticking = false; });
	}, { passive: true });

	function navEntry() {
		try { return w.performance && w.performance.getEntriesByType && w.performance.getEntriesByType('navigation')[0]; } catch (e) { return null; }
	}
	function loadTime() {
		var n = navEntry();
		if (n && n.loadEventEnd > 0) return Math.round(n.loadEventEnd);
		try { var t = w.performance.timing; if (t && t.loadEventEnd > 0) return t.loadEventEnd - t.navigationStart; } catch (e) {}
		return 0;
	}

	/* ---------- Core Web Vitals (first page load only) ---------- */
	function observe(type, cb, opts) {
		try {
			if (!w.PerformanceObserver || (PerformanceObserver.supportedEntryTypes && PerformanceObserver.supportedEntryTypes.indexOf(type) < 0)) return;
			var o = new PerformanceObserver(function (list) { list.getEntries().forEach(cb); });
			var conf = { type: type, buffered: true };
			for (var k in opts) conf[k] = opts[k];
			o.observe(conf);
		} catch (e) {}
	}
	if (C.vitals) {
		var n0 = navEntry();
		if (n0 && n0.responseStart > 0) vt.ttfb = Math.round(n0.responseStart);
		observe('paint', function (e) { if (e.name === 'first-contentful-paint') vt.fcp = Math.round(e.startTime); });
		observe('largest-contentful-paint', function (e) { vt.lcp = Math.round(e.startTime); });
		var clsWin = 0, clsFirst = 0, clsLast = 0;
		observe('layout-shift', function (e) {
			if (e.hadRecentInput) return;
			if (clsWin && e.startTime - clsLast < 1000 && e.startTime - clsFirst < 5000) { clsWin += e.value; } else { clsWin = e.value; clsFirst = e.startTime; }
			clsLast = e.startTime;
			if (clsWin > vt.cls) vt.cls = Math.round(clsWin * 1000) / 1000;
		});
		var inter = {};
		observe('event', function (e) {
			if (!e.interactionId) return;
			var dur = Math.round(e.duration);
			if (!inter[e.interactionId] || inter[e.interactionId] < dur) inter[e.interactionId] = dur;
			var all = [], k;
			for (k in inter) all.push(inter[k]);
			all.sort(function (a, b) { return b - a; });
			vt.inp = all[Math.min(all.length - 1, Math.floor(all.length / 50))];
		}, { durationThreshold: 40 });
		observe('first-input', function (e) { var dl = Math.round(e.processingStart - e.startTime + e.duration); if (!vt.inp) vt.inp = Math.max(0, dl); });
	}

	function engagedSeconds() { return Math.round((engaged + (visibleSince ? Date.now() - visibleSince : 0)) / 1000); }

	function leave(final) {
		if (!cur) return;
		var hit = { t: 'leave', pv: cur.id, tm: engagedSeconds(), sc: Math.max(maxScroll, scrollPct()), lt: cur.first ? loadTime() : 0 };
		if (cur.first && C.vitals && (vt.lcp || vt.fcp || vt.ttfb)) hit.vt = vt;
		send(hit, final !== false);
		emit('leave', hit);
	}

	function pageview(referrer) {
		engaged = 0; maxScroll = 0;
		visibleSince = d.visibilityState === 'visible' ? Date.now() : 0;
		var tz = '';
		try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
		var isFirst = firstLoad;
		firstLoad = false;
		cur = { id: rnd(16), first: isFirst };
		var hit = {
			t: 'pv', pv: cur.id, u: w.location.pathname + w.location.search, ti: d.title, r: referrer || '',
			sw: w.screen ? w.screen.width : 0, sh: w.screen ? w.screen.height : 0, l: nav.language || '', tz: tz,
			lh: new Date().getHours(), pid: isFirst ? (C.pid || 0) : 0
		};
		if (isFirst && C.search) hit.sr = C.search;
		var cd = {}, has = false, k;
		if (C.cd) for (k in C.cd) { cd[k] = C.cd[k]; has = true; }
		for (k in dims) { cd[k] = dims[k]; has = true; }
		if (has) hit.cd = cd;
		send(hit);
		if (isFirst && C.wc) {
			if (C.wc.page === 'product' && C.wc.p) track('ecommerce', 'product_view', C.wc.p.n, C.wc.p.pr, C.wc.p.id);
			else if (C.wc.page === 'checkout') track('ecommerce', 'checkout_view', '');
			else if (C.wc.page === 'cart') track('ecommerce', 'cart_view', '');
		}
		emit('pv', hit);
	}

	d.addEventListener('visibilitychange', function () {
		if (d.visibilityState === 'hidden') {
			if (visibleSince) { engaged += Date.now() - visibleSince; visibleSince = 0; }
			leave(true);
		} else {
			visibleSince = Date.now();
		}
	});
	w.addEventListener('pagehide', function () { leave(true); flush(true); });

	/* ---------- events ---------- */
	function num(v) { return typeof v === 'number' ? v : (v != null && v !== '' && !isNaN(v) ? Number(v) : null); }
	function track(category, action, label, value, ref) {
		if (!category || !action) return;
		send({ t: 'ev', pv: cur ? cur.id : '', c: String(category).slice(0, 100), a: String(action).slice(0, 150), n: label != null ? String(label).slice(0, 255) : '', v: num(value), rid: ref ? parseInt(ref, 10) || 0 : 0, u: w.location.pathname + w.location.search });
	}
	var dlRe = /\.(pdf|zip|rar|7z|tar|gz|docx?|xlsx?|pptx?|csv|txt|mp3|wav|mp4|mkv|avi|mov|apk|ipa|exe|msi|dmg|iso|epub)(\?|#|$)/i;
	d.addEventListener('click', function (e) {
		var el = e.target;
		var tagged = el && el.closest ? el.closest('[data-ntaa-event]') : null;
		if (tagged) {
			var p = String(tagged.getAttribute('data-ntaa-event')).split('|');
			track(p[0], p[1] || 'click', p[2] || '', p[3]);
		}
		var goal = el && el.closest ? el.closest('[data-ntaa-goal]') : null;
		if (goal) trackGoal(goal.getAttribute('data-ntaa-goal'), goal.getAttribute('data-ntaa-value'));
		var a = el && el.closest ? el.closest('a[href]') : null;
		if (!a) return;
		var href = a.href || '';
		if (/^mailto:/i.test(href)) { track('Contact', 'email', href.replace(/^mailto:/i, '').split('?')[0]); return; }
		if (/^tel:/i.test(href)) { track('Contact', 'phone', href.replace(/^tel:/i, '')); return; }
		if (C.downloads && dlRe.test(href)) { track('Download', ((href.match(dlRe) || [])[1] || 'file').toLowerCase(), href); return; }
		if (C.outbound && /^https?:/i.test(href) && a.hostname && a.hostname.replace(/^www\./, '') !== w.location.hostname.replace(/^www\./, '')) {
			track('Outbound', a.hostname.replace(/^www\./, ''), href);
		}
	}, true);
	if (C.forms) {
		d.addEventListener('submit', function (e) {
			var f = e.target;
			if (!f || f.tagName !== 'FORM') return;
			track('Form', 'submit', f.getAttribute('id') || f.getAttribute('name') || f.getAttribute('aria-label') || (f.getAttribute('action') || '').split('?')[0] || 'form');
		}, true);
	}

	function trackGoal(id, value) {
		id = parseInt(id, 10);
		if (!id) return;
		send({ t: 'goal', pv: cur ? cur.id : '', g: id, v: num(value) });
	}
	function trackOrder(o) {
		if (!o || !o.id) return;
		var items = [];
		(o.items || []).forEach(function (it) { items.push({ id: it.id || 0, sku: it.sku || '', n: it.name || '', cat: it.category || '', p: num(it.price) || 0, q: parseInt(it.quantity || 1, 10) }); });
		send({ t: 'order', pv: cur ? cur.id : '', id: String(o.id), rev: num(o.revenue) || 0, sub: num(o.subtotal) || 0, tax: num(o.tax) || 0, ship: num(o.shipping) || 0, disc: num(o.discount) || 0, cur: o.currency || '', items: items.slice(0, 100) }, true);
	}

	/* ---------- hooks for add-ons ---------- */
	function on(name, fn) { (hooks[name] = hooks[name] || []).push(fn); }
	function emit(name, data) { (hooks[name] || []).forEach(function (fn) { try { fn(data); } catch (e) {} }); }

	/* ---------- single-page apps ---------- */
	function onRoute() {
		if (w.location.href === lastUrl) return;
		var prev = lastUrl;
		lastUrl = w.location.href;
		if (visibleSince) { engaged += Date.now() - visibleSince; visibleSince = Date.now(); }
		leave(false);
		setTimeout(function () { pageview(prev); }, 50);
	}
	if (C.spa && w.history && w.history.pushState) {
		var push = w.history.pushState;
		w.history.pushState = function () { var r = push.apply(this, arguments); onRoute(); return r; };
		w.addEventListener('popstate', onRoute);
	}

	function start() {
		if (started || !trackingAllowed()) return;
		started = true;
		pageview(d.referrer || '');
		emit('start', {});
	}

	function setConsent(v) {
		consent = !!v;
		setCookie('ntaa_consent', consent ? '1' : '0', 395);
		if (consent) { loadVid(); start(); } else { forgetVid(); }
	}
	d.addEventListener('wp_listen_for_consent_change', function (e) {
		var det = e && e.detail;
		if (det && det.statistics) setConsent(det.statistics === 'allow');
	});

	/* ---------- public API ---------- */
	api = {
		track: track,
		trackGoal: trackGoal,
		trackOrder: trackOrder,
		pageview: function () { leave(false); pageview(lastUrl); },
		setCustomDimension: function (i, v) { i = parseInt(i, 10); if (i >= 1 && i <= 5) dims[i] = String(v).slice(0, 190); },
		setConsent: setConsent,
		optOut: function () { setCookie('ntaa_optout', '1', 400); forgetVid(); },
		optIn: optIn,
		on: on,
		send: send,
		flush: flush,
		pvId: function () { return cur ? cur.id : ''; },
		visitorId: function () { return vid; },
		config: C,
		active: true
	};
	w.ntaa = api;
	var calls = [], early = { setCustomDimension: 1, setConsent: 1 }, named = { trackGoal: 1, trackOrder: 1, setCustomDimension: 1, setConsent: 1, pageview: 1 };
	for (var q = 0; q < pending.length; q++) { try { calls.push(Array.prototype.slice.call(pending[q])); } catch (e) {} }
	calls.forEach(function (args) { if (early[args[0]]) { try { api[args[0]].apply(null, args.slice(1)); } catch (e) {} } });
	start();
	calls.forEach(function (args) {
		try {
			if (early[args[0]]) return;
			if (named[args[0]]) api[args[0]].apply(null, args.slice(1));
			else api.track.apply(null, args);
		} catch (e) {}
	});
	if (typeof w.CustomEvent === 'function') { try { d.dispatchEvent(new CustomEvent('ntaa:ready')); } catch (e) {} }
})(window, document);
