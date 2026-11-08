/* NeonDigital WorkDesk — core: API, state, shell, components, task modal & drawer. */
(function () {
	'use strict';

	var CFG = window.NDWD || {};
	var WD = (window.WD = { views: {}, S: {} });
	var S = WD.S;

	/* ------------------------------------------------------------------ *
	 * Utilities
	 * ------------------------------------------------------------------ */

	function esc(v) {
		return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	function el(html) {
		var t = document.createElement('template');
		t.innerHTML = html.trim();
		return t.content.firstElementChild;
	}
	/**
	 * Delegated event handler. Idempotent per (root, event, selector): views re-render
	 * into the same long-lived container, so binding again replaces the handler instead
	 * of stacking another listener (stacked listeners opened one modal per past visit).
	 */
	function on(root, evt, sel, fn) {
		var reg = root._wdOn || (root._wdOn = {});
		var key = evt + '|' + sel;
		var bound = key in reg;
		reg[key] = fn;
		if (bound) return;
		root.addEventListener(evt, function (e) {
			var t = e.target.closest(sel);
			if (t && root.contains(t)) reg[key](e, t);
		});
	}
	var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	function fmtDate(d, withYear) {
		if (!d) return '';
		var p = String(d).slice(0, 10).split('-');
		if (p.length < 3) return d;
		var s = parseInt(p[2], 10) + ' ' + MONTHS[parseInt(p[1], 10) - 1];
		return withYear || p[0] !== S.today.slice(0, 4) ? s + ' ' + p[0] : s;
	}
	function fmtDay(d) {
		if (!d) return '';
		if (d === S.today) return 'Today';
		if (d === addDays(S.today, 1)) return 'Tomorrow';
		if (d === addDays(S.today, -1)) return 'Yesterday';
		return fmtDate(d);
	}
	function fmtTime(t) {
		if (!t) return '';
		var p = String(t).split(':'), h = parseInt(p[0], 10), m = p[1];
		return (h % 12 || 12) + ':' + m + ' ' + (h < 12 ? 'AM' : 'PM');
	}
	function fmtDT(dt) {
		if (!dt) return '';
		var s = String(dt).replace('T', ' ');
		return fmtDay(s.slice(0, 10)) + ', ' + fmtTime(s.slice(11, 16));
	}
	function addDays(ymd, n) {
		var d = new Date(ymd + 'T00:00:00Z');
		d.setUTCDate(d.getUTCDate() + n);
		return d.toISOString().slice(0, 10);
	}
	function istToday() {
		try {
			return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
		} catch (e) {
			var d = new Date(Date.now() + 330 * 60000);
			return d.toISOString().slice(0, 10);
		}
	}
	function initials(name) {
		return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w[0]; }).join('').toUpperCase();
	}
	function hueOf(id) { return (parseInt(id, 10) * 47) % 360; }
	/** Member avatar: their uploaded picture, else initials on their chosen (or automatic) colour. */
	function avatar(id, size) {
		var m = member(id);
		var name = m ? m.name : 'Unknown';
		var bg = m && m.color && S.avatar_colors && S.avatar_colors[m.color] ? S.avatar_colors[m.color] : 'hsl(' + hueOf(id) + ' 55% 45%)';
		return '<span class="avatar' + (size ? ' ' + size : '') + '" title="' + esc(name) + '" style="background:' + bg + '">' + (m && m.avatar ? '<img src="' + esc(m.avatar) + '" alt="" loading="lazy">' : esc(initials(name))) + '</span>';
	}
	/** Profile picture + initials colour; own profile, or any member's for "Manage members". */
	function openProfile(id) {
		id = +(id || S.user.id);
		var m = member(id);
		if (!m) return;
		var changed = false;
		var md = modal({ title: id === S.user.id ? 'My profile picture' : 'Profile picture · ' + m.name, size: 'sm', body: '<div class="pf"></div>', foot: '<button class="btn btn-primary" data-close>Done</button>', onClose: function () { if (changed) { paintSideFoot(); refresh(); } } });
		var box = md.body.querySelector('.pf');
		function paint() {
			var cols = S.avatar_colors || {};
			box.innerHTML = '<div class="pf-head">' + avatar(id, 'xl') + '<div><b>' + esc(m.name) + '</b><div class="muted small">PNG, JPG or WEBP, up to 2 MB. Cropped to a square.</div></div></div>' +
				'<div class="inv-actions"><label class="btn btn-sm">' + I.upload + (m.avatar ? ' Change picture' : ' Upload picture') + '<input type="file" data-pf-file accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" hidden></label>' +
				(m.avatar ? '<button type="button" class="btn btn-sm btn-ghost btn-danger" data-pf-rm>Remove picture</button>' : '') + '</div>' +
				'<div class="field" style="margin:14px 0 0"><span>Initials colour <span class="hint">(shown when there is no picture)</span></span><div class="swatches">' +
				'<button type="button" class="swatch' + (m.color ? '' : ' on') + '" data-color="" title="Automatic" aria-label="Automatic colour" style="background:hsl(' + hueOf(id) + ' 55% 45%)">' + esc(initials(m.name)) + '</button>' +
				Object.keys(cols).map(function (k) { return '<button type="button" class="swatch' + (m.color === k ? ' on' : '') + '" data-color="' + esc(k) + '" title="' + esc(k) + '" aria-label="' + esc(k) + '" style="background:' + esc(cols[k]) + '">' + esc(initials(m.name)) + '</button>'; }).join('') + '</div></div>';
		}
		function apply(p) { m.avatar = p.avatar; m.color = p.color; changed = true; paint(); }
		paint();
		on(box, 'change', '[data-pf-file]', function (e, inp) {
			var file = inp.files[0];
			if (!file) return;
			if (!/[.](png|jpe?g|webp)$/i.test(file.name)) { inp.value = ''; return toast('Choose a PNG, JPG or WEBP image.', 'error'); }
			if (file.size > 2 * 1024 * 1024) { inp.value = ''; return toast('The picture is larger than 2 MB.', 'error'); }
			var rd = new FileReader();
			rd.onload = function () {
				api('members/' + id + '/avatar', { method: 'POST', body: { name: file.name, data: rd.result } }).then(function (p) { apply(p); toast('Profile picture saved'); }, WD.toastError);
			};
			rd.readAsDataURL(file);
		});
		on(box, 'click', '[data-pf-rm]', function () {
			api('members/' + id + '/avatar', { method: 'DELETE' }).then(function (p) { apply(p); toast('Profile picture removed'); }, WD.toastError);
		});
		on(box, 'click', '[data-color]', function (e, b) {
			api('members/' + id + '/avatar-color', { method: 'POST', body: { color: b.getAttribute('data-color') } }).then(apply, WD.toastError);
		});
	}
	WD.openProfile = function (id) { openProfile(id); };
	function paintSideFoot() {
		var a = $('.side-foot [data-profile]');
		if (a) a.innerHTML = avatar(S.user.id);
	}
	function avatars(ids, max) {
		ids = ids || [];
		max = max || 3;
		var h = ids.slice(0, max).map(function (i) { return avatar(i, 'sm'); }).join('');
		if (ids.length > max) h += '<span class="avatar sm" style="background:var(--surface-3);color:var(--text-2)">+' + (ids.length - max) + '</span>';
		return '<span class="avatars">' + h + '</span>';
	}
	function member(id) { return S.memberMap[id]; }
	function memberName(id) { var m = member(id); return m ? m.name : 'Unknown'; }
	function names(ids) { return (ids || []).map(memberName).join(', '); }
	/** Server-supplied names win, so members added after page load still show correctly. */
	function assigneeNames(t) { return (t.assignee_names && t.assignee_names.length ? t.assignee_names : t.assignee_ids.map(memberName)).join(', '); }
	function team(id) { return S.teamMap[id]; }
	function project(id) { return S.projectMap[id]; }
	function client(id) { return S.clientMap[id]; }
	/** Client of a task: its own client, else its project's client. */
	/** Teams currently on a task, lead team first (older task data may only have team_id). */
	function taskTeamIds(t) {
		var ids = (t && t.team_ids ? t.team_ids : []).map(Number);
		if (t && t.team_id && ids.indexOf(+t.team_id) < 0) ids.unshift(+t.team_id);
		return ids;
	}
	function teamNames(ids) { return ids.map(function (id) { return team(id) ? team(id).name : ''; }).filter(Boolean).join(', '); }
	function taskClient(t) { return client(t.client_id) || (project(t.project_id) && client(project(t.project_id).client_id)) || null; }
	function tag(id) { return S.tagMap[id]; }
	function can(cap) { return !!(S.caps && S.caps[cap]); }
	function debounce(fn, ms) {
		var t;
		return function () {
			var a = arguments, c = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(c, a); }, ms);
		};
	}
	function qs(params) {
		var parts = [];
		Object.keys(params || {}).forEach(function (k) {
			var v = params[k];
			if (v === undefined || v === null || v === '' || (Array.isArray(v) && !v.length)) return;
			parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(Array.isArray(v) ? v.join(',') : v));
		});
		return parts.length ? '?' + parts.join('&') : '';
	}
	function isUrl(u) { return /^https?:\/\/[^\s/$.?#].[^\s]*$/i.test(String(u).trim()) || /^[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i.test(String(u).trim()); }

	/* ------------------------------------------------------------------ *
	 * Icons (inline SVG, stroke = currentColor)
	 * ------------------------------------------------------------------ */
	function ic(paths) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>'; }
	var I = {
		home: ic('<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>'),
		team: ic('<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.5 3.3-5.5 6.5-5.5s5.9 2 6.5 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.6c2.8-.3 4.9 1.4 5.5 4.4"/>'),
		sheet: ic('<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>'),
		list: ic('<path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1"/><circle cx="4.5" cy="12" r="1"/><circle cx="4.5" cy="18" r="1"/>'),
		cal: ic('<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>'),
		clock: ic('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
		chart: ic('<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>'),
		archive: ic('<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9M10 13h4"/>'),
		bell: ic('<path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10 20a2 2 0 0 0 4 0"/>'),
		users: ic('<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>'),
		folder: ic('<path d="M3 6a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/>'),
		cog: ic('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'),
		upload: ic('<path d="M12 16V4M7 9l5-5 5 5M4 20h16"/>'),
		plus: ic('<path d="M12 5v14M5 12h14"/>'),
		search: ic('<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'),
		sun: ic('<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>'),
		moon: ic('<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>'),
		menu: ic('<path d="M4 6h16M4 12h16M4 18h16"/>'),
		x: ic('<path d="M18 6 6 18M6 6l12 12"/>'),
		check: ic('<path d="m5 12 5 5 9-10"/>'),
		link: ic('<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>'),
		msg: ic('<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>'),
		fwd: ic('<path d="M15 4l5 5-5 5"/><path d="M20 9H10a6 6 0 0 0-6 6v5"/>'),
		edit: ic('<path d="M4 20h4L19 9l-4-4L4 16z"/>'),
		trash: ic('<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>'),
		pin: ic('<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>'),
		logout: ic('<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l5-5-5-5M15 12H3"/>'),
		left: ic('<path d="m15 18-6-6 6-6"/>'),
		right: ic('<path d="m9 18 6-6-6-6"/>'),
		inbox: ic('<path d="M3 13h5l2 3h4l2-3h5"/><path d="M5 5h14l2 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6z"/>'),
		tag: ic('<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="8" cy="8" r="1.5"/>'),
		download: ic('<path d="M12 4v12M7 11l5 5 5-5M4 20h16"/>'),
		briefcase: ic('<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M3 13h18"/>'),
		plane: ic('<path d="M3 13.5 21 5l-4.5 15-4-6-6 3z"/><path d="m12.5 14 4-4.5"/>'),
		phone: ic('<path d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A17 17 0 0 1 3 5a2 2 0 0 1 2-2"/>'),
		mail: ic('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>'),
		wa: '<svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2m0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2m4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.8-1.4a.5.5 0 0 0 0-.5l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .1-1.3c0-.1-.2-.2-.4-.3"/></svg>',
		alert: ic('<path d="M12 3 2 20h20z"/><path d="M12 10v4M12 17h.01"/>'),
		rupee: ic('<path d="M6 4h12M6 9h12M14 4c3 0 3 10-4 10H6l8 7"/>'),
		file: ic('<path d="M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>')
	};
	WD.I = I;

	/* ------------------------------------------------------------------ *
	 * API
	 * ------------------------------------------------------------------ */
	function apiUrl(path, params) {
		var url = CFG.root + path.replace(/^\//, '');
		var query = qs(params);
		// With plain permalinks the REST root is "…/?rest_route=/ndwd/v1/", so extra params join with "&".
		if (query) url += (url.indexOf('?') >= 0 ? '&' : '?') + query.slice(1);
		return url;
	}
	/**
	 * A page left open for many hours outlives its REST nonce. Instead of failing, ask the
	 * server for a fresh nonce (works while the login cookie is still valid) and retry once.
	 */
	var nonceRefresh = null;
	function refreshNonce() {
		if (!nonceRefresh) {
			var body = new FormData();
			body.append('action', 'ndwd_nonce');
			nonceRefresh = fetch(CFG.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (j) {
				nonceRefresh = null;
				if (!j || !j.success) throw new Error('signed out');
				CFG.nonce = j.data.nonce;
				return true;
			}, function (e) { nonceRefresh = null; throw e; });
		}
		return nonceRefresh;
	}
	function apiError(res, data) {
		var err = new Error((data && data.message) || ('Request failed (' + res.status + ')'));
		err.status = res.status;
		err.code = data && data.code;
		err.data = data && data.data;
		return err;
	}
	/** fetch() with the REST nonce; on an expired nonce, refreshes it and retries once. */
	function send(url, init, retried) {
		init.headers['X-WP-Nonce'] = CFG.nonce;
		return fetch(url, init).then(function (res) {
			if (res.status === 403 && !retried && CFG.ajax) {
				return res.clone().json().catch(function () { return null; }).then(function (d) {
					if (d && d.code === 'rest_cookie_invalid_nonce') {
						return refreshNonce().then(function () { return send(url, init, true); }, function () { return res; });
					}
					return res;
				});
			}
			return res;
		}, function () {
			throw new Error('Network error — check your connection and try again.');
		});
	}
	function api(path, opts) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' } };
		if (opts.body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		return send(apiUrl(path, opts.params), init).then(function (res) {
			return res.text().then(function (txt) {
				var data = null;
				try { data = txt ? JSON.parse(txt) : null; } catch (e) { data = null; }
				if (!res.ok) {
					var err = apiError(res, data);
					if (err.code === 'rest_cookie_invalid_nonce' || res.status === 401) {
						err.message = 'Your session has expired. Please reload the page and sign in again.';
						sessionExpired();
					}
					throw err;
				}
				if (data === null && txt) throw new Error('The server returned an unexpected response. Check the PHP error log.');
				return data;
			});
		});
	}
	WD.api = api;

	/**
	 * Downloads a file from a REST route (GET, or POST with a JSON body) through fetch, so the
	 * request carries the nonce and a failure shows a clear message with a working Retry
	 * instead of a WordPress error page. Resolves when the browser has been handed the file.
	 */
	function download(path, opts) {
		opts = opts || {};
		var btn = opts.button;
		var init = { method: opts.body !== undefined ? 'POST' : 'GET', credentials: 'same-origin', headers: { Accept: '*/*' } };
		if (opts.body !== undefined) {
			init.headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.body);
		}
		if (btn) busy(btn, true, opts.busyLabel || 'Preparing…');
		var run = function () {
			return send(apiUrl(path, opts.params), init).then(function (res) {
				var type = res.headers.get('Content-Type') || '';
				if (!res.ok || type.indexOf('application/json') === 0) {
					return res.text().then(function (txt) {
						var data = null;
						try { data = JSON.parse(txt); } catch (e) { /* not JSON */ }
						if (res.ok && data && !data.code) throw new Error('The server did not return a file.');
						throw apiError(res, data);
					});
				}
				var name = opts.filename || 'download';
				var cd = res.headers.get('Content-Disposition') || '';
				var m = cd.match(/filename\*=UTF-8''([^;]+)/i) || cd.match(/filename="?([^";]+)"?/i);
				if (m) { try { name = decodeURIComponent(m[1]); } catch (e) { name = m[1]; } }
				return res.blob().then(function (blob) {
					if (!blob.size) throw new Error('The downloaded file was empty.');
					var a = document.createElement('a');
					a.href = URL.createObjectURL(blob);
					a.download = name;
					document.body.appendChild(a);
					a.click();
					setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
					return { name: name, size: blob.size };
				});
			});
		};
		return run().then(function (r) {
			if (btn) busy(btn, false);
			if (!opts.quiet) toast('Downloaded ' + r.name);
			return r;
		}, function (e) {
			if (btn) busy(btn, false);
			if (e.status === 401 || e.code === 'rest_cookie_invalid_nonce') { sessionExpired(); throw e; }
			toast('Download failed: ' + (e.message || e), 'error', { label: 'Retry', fn: function () { download(path, opts); } });
			throw e;
		});
	}
	WD.download = download;

	/** Object URL for an image served by a protected REST route, or null when there is none. */
	WD.imageUrl = function (path) {
		return send(apiUrl(path), { method: 'GET', credentials: 'same-origin', headers: { Accept: 'image/*' } }).then(function (res) {
			var type = res.headers.get('Content-Type') || '';
			return res.ok && type.indexOf('image/') === 0 ? res.blob().then(function (b) { return URL.createObjectURL(b); }) : null;
		}, function () { return null; });
	};

	var expiredShown = false;
	function sessionExpired() {
		if (expiredShown) return;
		expiredShown = true;
		var m = modal({ title: 'Session ended', size: 'sm', body: '<p>Your sign-in session has ended — you signed out in another tab, your password was changed, or your login expired. Sign in again to continue.</p>', foot: '<button class="btn btn-primary" data-reload>Sign in again</button>' });
		m.el.querySelector('[data-reload]').onclick = function () { location.reload(); };
	}

	/* ------------------------------------------------------------------ *
	 * Toasts, modals, confirm
	 * ------------------------------------------------------------------ */
	function toast(msg, type, action) {
		var box = $('.toasts') || document.body.appendChild(el('<div class="toasts" role="status" aria-live="polite"></div>'));
		var t = el('<div class="toast ' + (type || 'success') + '"><span>' + esc(msg) + '</span></div>');
		if (action) {
			var b = el('<button type="button" class="toast-btn">' + esc(action.label) + '</button>');
			b.onclick = function () { t.remove(); action.fn(); };
			t.appendChild(b);
		}
		box.appendChild(t);
		setTimeout(function () { t.remove(); }, action ? 12000 : type === 'error' ? 6000 : 3200);
	}
	WD.toast = toast;
	function toastError(e) { toast(e && e.message ? e.message : String(e), 'error'); }
	WD.toastError = toastError;

	var modalStack = [];
	function modal(o) {
		var ov = el('<div class="overlay" role="dialog" aria-modal="true"><div class="modal ' + (o.size || '') + '">' +
			'<div class="modal-h"><h2>' + esc(o.title) + '</h2><button class="btn btn-ghost btn-icon btn-sm" data-close aria-label="Close">' + I.x + '</button></div>' +
			'<div class="modal-b"></div>' + (o.foot !== false ? '<div class="modal-f">' + (o.foot || '') + '</div>' : '') + '</div></div>');
		var body = ov.querySelector('.modal-b');
		if (typeof o.body === 'string') body.innerHTML = o.body; else if (o.body) body.appendChild(o.body);
		document.body.appendChild(ov);
		var prevFocus = document.activeElement;
		var m = {
			el: ov, body: body,
			close: function () {
				ov.remove();
				modalStack = modalStack.filter(function (x) { return x !== m; });
				if (prevFocus && prevFocus.focus) prevFocus.focus();
				if (o.onClose) o.onClose();
			}
		};
		modalStack.push(m);
		ov.addEventListener('mousedown', function (e) { if (e.target === ov && !o.sticky) m.close(); });
		on(ov, 'click', '[data-close]', function () { m.close(); });
		setTimeout(function () { var f = ov.querySelector('[autofocus], input:not([type=hidden]), select, textarea'); if (f) f.focus(); }, 30);
		return m;
	}
	WD.modal = modal;

	function confirmBox(title, text, okLabel, danger) {
		return new Promise(function (resolve) {
			var done = false;
			var m = modal({
				title: title, size: 'sm', body: '<p>' + esc(text) + '</p>',
				foot: '<button class="btn" data-close>Cancel</button><button class="btn ' + (danger ? 'btn-danger' : 'btn-primary') + '" data-ok>' + esc(okLabel || 'Confirm') + '</button>',
				onClose: function () { if (!done) resolve(false); }
			});
			m.el.querySelector('[data-ok]').onclick = function () { done = true; m.close(); resolve(true); };
		});
	}
	WD.confirm = confirmBox;

	/**
	 * Delete with a server-side check first: the route is called with ?dry=1 to learn what
	 * the record is linked to. Linked records block the delete (deactivate instead); otherwise
	 * the user confirms, naming exactly what is deleted. Resolves true when deleted.
	 */
	function safeDelete(path, what) {
		return api(path, { method: 'DELETE', params: { dry: 1 } }).then(function (d) {
			var links = Object.keys(d.links || {}).map(function (k) { return d.links[k] + ' ' + k; });
			if (d.blocked) {
				modal({ title: 'Cannot delete ' + what, size: 'sm', body: '<p><b>' + esc(d.name) + '</b> is linked to ' + esc(links.join(', ')) + '.</p><p class="muted">It is kept so history, invoices and reports stay intact. Mark it inactive instead.</p>', foot: '<button class="btn btn-primary" data-close>OK</button>' });
				return false;
			}
			return confirmBox('Delete ' + what + '?', 'Permanently delete "' + d.name + '"? ' + (d.notes || []).join(' ') + ' This cannot be undone.', 'Delete', true).then(function (ok) {
				if (!ok) return false;
				return api(path, { method: 'DELETE' }).then(function () { toast(what.charAt(0).toUpperCase() + what.slice(1) + ' deleted'); return true; });
			});
		}).catch(function (e) { toastError(e); return false; });
	}
	WD.safeDelete = safeDelete;

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') {
			if (modalStack.length) { modalStack[modalStack.length - 1].close(); return; }
			if ($('.drawer')) { closeDrawer(); return; }
			var p = $('.pop'); if (p) p.remove();
		}
		if (e.key === 'n' && !e.ctrlKey && !e.metaKey && !/input|textarea|select/i.test(document.activeElement.tagName) && !modalStack.length) {
			e.preventDefault();
			openTaskForm('create');
		}
	});

	function busy(btn, isBusy, label) {
		if (!btn) return;
		if (isBusy) { btn.dataset.label = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> ' + esc(label || 'Saving…'); }
		else { btn.disabled = false; if (btn.dataset.label) btn.innerHTML = btn.dataset.label; }
	}
	WD.busy = busy;

	/* ------------------------------------------------------------------ *
	 * Shared renderers
	 * ------------------------------------------------------------------ */
	function statusBadge(s) { return '<span class="badge s-' + esc(s) + '">' + esc(S.statuses[s] || s) + '</span>'; }
	function prioBadge(p) { return '<span class="prio p-' + esc(p) + '">' + esc(S.priorities[p] || p) + '</span>'; }
	function workType(w) { return esc(S.work_types[w] || w); }
	function emptyState(title, text, icon) {
		return '<div class="empty">' + (icon || I.inbox) + '<b>' + esc(title) + '</b>' + (text ? '<div>' + esc(text) + '</div>' : '') + '</div>';
	}
	function loading(n) {
		var h = '<div class="loading-block">';
		for (var i = 0; i < (n || 4); i++) h += '<div class="skel" style="width:' + (60 + (i * 13) % 35) + '%"></div>';
		return h + '</div>';
	}
	/** Error message with a Retry button that repeats the failed operation. */
	function errorBox(e, retry) {
		var b = el('<div class="alert alert-error load-error" role="alert"><div style="flex:1"><b>Could not load.</b> <span data-msg></span></div>' + (retry ? '<button class="btn btn-sm" data-retry>Retry</button>' : '') + '</div>');
		b.querySelector('[data-msg]').textContent = (e && e.message) || String(e);
		if (retry) {
			var btn = b.querySelector('[data-retry]');
			btn.onclick = function () {
				busy(btn, true, 'Retrying…');
				var r;
				try { r = retry(); } catch (err) { busy(btn, false); b.querySelector('[data-msg]').textContent = err.message || String(err); return; }
				if (r && r.then) r.then(function () { if (b.isConnected) b.remove(); }, function (err) { busy(btn, false); b.querySelector('[data-msg]').textContent = (err && err.message) || String(err); });
			};
		}
		return b;
	}
	WD.ui = { statusBadge: statusBadge, prioBadge: prioBadge, workType: workType, emptyState: emptyState, loading: loading, errorBox: errorBox, avatar: avatar, avatars: avatars };
	WD.fmt = { date: fmtDate, day: fmtDay, time: fmtTime, dt: fmtDT, addDays: addDays };
	WD.u = { apiUrl: apiUrl, client: client, taskClient: taskClient, esc: esc, el: el, $: $, $$: $$, on: on, debounce: debounce, qs: qs, member: member, memberName: memberName, names: names, team: team, project: project, tag: tag, can: can };

	function taskRow(t, opts) {
		opts = opts || {};
		var meta = [];
		if (t.is_overdue) meta.push('<span class="badge overdue">Overdue</span>');
		else if (t.is_carried) meta.push('<span class="badge carried" title="Carried forward from ' + esc(fmtDate(t.scheduled_date)) + '">Carried · ' + esc(fmtDate(t.scheduled_date)) + '</span>');
		if (t.parent_id) meta.push('<span class="badge followup">Follow-up</span>');
		if (opts.showDate || (!t.is_carried && t.scheduled_date !== S.today)) meta.push('<span>' + I.cal + esc(fmtDay(t.scheduled_date)) + '</span>');
		if (t.due_date || t.due_time) meta.push('<span>' + I.clock + 'Due ' + esc((t.due_date && t.due_date !== S.today ? fmtDate(t.due_date) + ' ' : '') + (t.due_time ? fmtTime(t.due_time) : (t.due_date === S.today ? 'today' : ''))) + '</span>');
		if (taskClient(t)) meta.push('<span>' + I.briefcase + esc(taskClient(t).name) + '</span>');
		if (t.project_id && project(t.project_id)) meta.push('<span>' + I.folder + esc(project(t.project_id).name) + '</span>');
		if (t.team_id && team(t.team_id)) meta.push('<span>' + I.team + esc(team(t.team_id).name) + (taskTeamIds(t).length > 1 ? ' +' + (taskTeamIds(t).length - 1) : '') + '</span>');
		if (t.work_type && t.work_type !== 'office') meta.push('<span>' + I.pin + workType(t.work_type) + '</span>');
		if (t.counts && t.counts.links) meta.push('<span>' + I.link + t.counts.links + '</span>');
		if (t.counts && t.counts.remarks) meta.push('<span>' + I.msg + t.counts.remarks + '</span>');
		(t.tag_ids || []).forEach(function (id) { var g = tag(id); if (g) meta.push('<span class="tag"><i style="background:' + esc(g.color) + '"></i>' + esc(g.name) + '</span>'); });
		var done = t.status === 'completed';
		var chk = t.can_work ? '<button class="chk' + (done ? ' on' : '') + '" data-toggle="' + t.id + '" title="' + (done ? 'Mark as pending' : 'Mark completed') + '" aria-label="' + (done ? 'Mark as pending' : 'Mark completed') + '">' + I.check + '</button>' : '<span class="chk' + (done ? ' on' : '') + '" style="cursor:default">' + I.check + '</span>';
		var status = t.can_work
			? '<select class="status-select badge s-' + t.status + '" data-status="' + t.id + '" aria-label="Status">' + Object.keys(S.statuses).map(function (k) { return '<option value="' + k + '"' + (k === t.status ? ' selected' : '') + '>' + esc(S.statuses[k]) + '</option>'; }).join('') + '</select>'
			: statusBadge(t.status);
		return '<li class="task' + (done ? ' done' : '') + '" data-task="' + t.id + '" tabindex="0">' + chk +
			'<div style="min-width:0"><div class="t-title">' + esc(t.title) + '</div><div class="t-meta">' + meta.join('') + '</div></div>' +
			'<div class="t-right">' + prioBadge(t.priority) + avatars(t.assignee_ids) + status + '</div></li>';
	}

	/** Renders a task list into container and wires up open/status actions. */
	function taskList(container, tasks, opts) {
		opts = opts || {};
		if (!tasks.length) {
			container.innerHTML = emptyState(opts.emptyTitle || 'No tasks here', opts.emptyText || '');
			return;
		}
		container.innerHTML = '<ul class="tasks">' + tasks.map(function (t) { return taskRow(t, opts); }).join('') + '</ul>';
		bindTaskList(container);
	}
	function bindTaskList(container) {
		if (container._bound) return;
		container._bound = true;
		container.addEventListener('click', function (e) {
			if (e.target.closest('select, .chk, a, button')) {
				var tg = e.target.closest('[data-toggle]');
				if (tg) {
					e.stopPropagation();
					var done = tg.classList.contains('on');
					changeStatus(tg.getAttribute('data-toggle'), done ? 'pending' : 'completed');
				}
				return;
			}
			var row = e.target.closest('[data-task]');
			if (row) go('/tasks/' + row.getAttribute('data-task'));
		});
		container.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && e.target.matches('[data-task]')) go('/tasks/' + e.target.getAttribute('data-task'));
		});
		container.addEventListener('change', function (e) {
			var s = e.target.closest('[data-status]');
			if (s) changeStatus(s.getAttribute('data-status'), s.value);
		});
	}
	function changeStatus(id, status, note) {
		return api('tasks/' + id + '/status', { method: 'POST', body: { status: status, note: note || '' } }).then(function (t) {
			toast('Status set to ' + S.statuses[status]);
			refresh();
			return t;
		}, function (e) { toastError(e); refresh(); });
	}
	WD.taskRow = taskRow;
	WD.taskList = taskList;
	WD.changeStatus = changeStatus;

	/* ------------------------------------------------------------------ *
	 * Dropdowns: every single <select> in the app is shown as a styled menu.
	 * The native <select> stays in the DOM (visually hidden) and remains the
	 * source of truth: its value, options, name, change/input events and form
	 * submission work exactly as before, so no view code changes. The menu is
	 * rendered at <body> level with fixed positioning, so cards, tables, modals
	 * and drawers never clip it. Opt out with data-native on a <select>.
	 * ------------------------------------------------------------------ */
	var DD_PROTO_VALUE = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value');
	var DD_PROTO_INDEX = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'selectedIndex');
	var ddSeq = 0;
	var ddOpen = null;
	var DD_CHEVRON = '<svg class="dd-chev" viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><path d="M5.5 7.5 10 12l4.5-4.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
	var DD_TICK = '<svg class="dd-tick" viewBox="0 0 20 20" width="14" height="14" aria-hidden="true"><path d="m5 10.5 3.2 3L15 6.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

	/** Task-status selects get the status colours (Pending amber, In Process blue, Rejected red, Completed green). */
	function ddIsStatus(sel) {
		return sel.classList.contains('status-select') || sel.getAttribute('data-f') === 'status' || sel.getAttribute('data-tone') === 'status';
	}
	function ddStatusKey(v) { return v && S.statuses && S.statuses[v] ? v : ''; }
	function ddLabelOf(sel) {
		var name = sel.getAttribute('aria-label') || '';
		if (!name && sel.labels && sel.labels[0]) {
			var lab = sel.labels[0].cloneNode(true);
			$$('select, button, .dd, .hint', lab).forEach(function (n) { n.remove(); });
			name = lab.textContent.replace(/\s+/g, ' ').trim();
		}
		return name;
	}

	function ddSync(sel) {
		var dd = sel._dd;
		if (!dd) return;
		var opt = sel.options[DD_PROTO_INDEX.get.call(sel)];
		var text = opt ? opt.text : '';
		var cls = 'dd ' + sel.className.replace(/\bdd-native\b/g, '').replace(/\bs-[a-z_]+\b/g, '').trim();
		var st = dd.status ? ddStatusKey(opt ? opt.value : '') : '';
		if (st) cls += ' dd-status s-' + st;
		else if (dd.status) cls += ' dd-status';
		if (opt && opt.value === '') cls += ' dd-placeholder';
		dd.btn.className = cls;
		dd.btn.disabled = sel.disabled;
		var style = sel.getAttribute('style');
		if (style !== dd.style) { dd.style = style; if (style) dd.btn.setAttribute('style', style); else dd.btn.removeAttribute('style'); }
		dd.text.textContent = text || ' ';
		var name = ddLabelOf(sel);
		dd.btn.setAttribute('aria-label', (name ? name + ': ' : '') + (text || 'none'));
		if (sel.required) dd.btn.setAttribute('aria-required', 'true'); else dd.btn.removeAttribute('aria-required');
		if (ddOpen && ddOpen.sel === sel) ddRenderOptions(ddOpen);
	}

	function ddEnhance(sel) {
		if (sel._dd || sel.multiple || sel.hasAttribute('data-native') || (sel.size && sel.size > 1)) return;
		var id = 'dd' + (++ddSeq);
		var btn = el('<button type="button" class="dd" aria-haspopup="listbox" aria-expanded="false" aria-controls="' + id + '-menu"><span class="dd-dot" aria-hidden="true"></span><span class="dd-text"></span>' + DD_CHEVRON + '</button>');
		var dd = sel._dd = { btn: btn, text: btn.querySelector('.dd-text'), id: id, status: ddIsStatus(sel), style: null };
		// Keep programmatic `select.value = x` / `selectedIndex = n` in sync with the trigger.
		Object.defineProperty(sel, 'value', { configurable: true, get: function () { return DD_PROTO_VALUE.get.call(this); }, set: function (v) { DD_PROTO_VALUE.set.call(this, v); ddSync(this); } });
		Object.defineProperty(sel, 'selectedIndex', { configurable: true, get: function () { return DD_PROTO_INDEX.get.call(this); }, set: function (v) { DD_PROTO_INDEX.set.call(this, v); ddSync(this); } });
		sel.classList.add('dd-native');
		sel.setAttribute('tabindex', '-1');
		sel.setAttribute('aria-hidden', 'true');
		sel.insertAdjacentElement('afterend', btn);
		btn._sel = sel;
		sel.addEventListener('change', function () { ddSync(sel); });
		sel.addEventListener('focus', function () { if (!btn.disabled) btn.focus(); });
		sel.addEventListener('invalid', function () { btn.classList.add('dd-invalid'); });
		if (sel.form && !sel.form._ddReset) {
			sel.form._ddReset = true;
			sel.form.addEventListener('reset', function (e) { var f = e.target; setTimeout(function () { $$('select', f).forEach(ddSync); }, 0); });
		}
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			if (ddOpen && ddOpen.sel === sel) ddClose(true); else ddOpenMenu(sel);
		});
		btn.addEventListener('keydown', function (e) {
			if (['ArrowDown', 'ArrowUp', 'Enter', ' ', 'Spacebar'].indexOf(e.key) >= 0 || (e.altKey && e.key === 'ArrowDown')) {
				e.preventDefault();
				e.stopPropagation();
				ddOpenMenu(sel);
			}
		});
		ddSync(sel);
	}

	function ddItems(sel) {
		var out = [];
		Array.prototype.forEach.call(sel.children, function (n) {
			if (n.tagName === 'OPTGROUP') {
				out.push({ group: n.label });
				Array.prototype.forEach.call(n.children, function (o) { out.push({ opt: o, disabled: o.disabled || n.disabled }); });
			} else if (n.tagName === 'OPTION') {
				out.push({ opt: n, disabled: n.disabled });
			}
		});
		return out;
	}

	function ddRenderOptions(m) {
		var sel = m.sel, q = m.search ? m.search.value.trim().toLowerCase() : '';
		var cur = DD_PROTO_INDEX.get.call(sel);
		var html = '', idx = [];
		ddItems(sel).forEach(function (it) {
			if (it.group !== undefined) { html += '<div class="dd-group" role="presentation">' + esc(it.group) + '</div>'; return; }
			var o = it.opt;
			if (q && o.text.toLowerCase().indexOf(q) < 0) return;
			var st = sel._dd.status ? ddStatusKey(o.value) : '';
			var i = o.index;
			idx.push(i);
			html += '<div class="dd-opt' + (st ? ' dd-status s-' + st : '') + (o.value === '' ? ' dd-placeholder' : '') + (it.disabled ? ' is-disabled' : '') + '" role="option" id="' + m.id + '-o' + i + '" data-i="' + i + '" aria-selected="' + (i === cur ? 'true' : 'false') + '"' + (it.disabled ? ' aria-disabled="true"' : '') + '>' +
				'<span class="dd-dot" aria-hidden="true"></span><span class="dd-opt-text">' + esc(o.text || ' ') + '</span>' + DD_TICK + '</div>';
		});
		m.list.innerHTML = html || '<div class="dd-empty">No matches</div>';
		m.idx = idx;
		if (m.active == null || idx.indexOf(m.active) < 0) m.active = idx.indexOf(cur) >= 0 ? cur : ddNextEnabled(m, -1, 1);
		ddSetActive(m, m.active, false);
	}

	function ddEnabled(m, i) { var o = m.sel.options[i]; return o && !o.disabled && !(o.parentNode && o.parentNode.tagName === 'OPTGROUP' && o.parentNode.disabled); }
	function ddNextEnabled(m, from, dir) {
		var pos = m.idx.indexOf(from);
		for (var p = pos + dir; p >= 0 && p < m.idx.length; p += dir) if (ddEnabled(m, m.idx[p])) return m.idx[p];
		return from >= 0 ? from : (m.idx.length ? m.idx[0] : null);
	}
	function ddSetActive(m, i, scroll) {
		m.active = i;
		$$('.dd-opt.is-active', m.list).forEach(function (n) { n.classList.remove('is-active'); });
		var n = i != null ? m.list.querySelector('[data-i="' + i + '"]') : null;
		if (n) {
			n.classList.add('is-active');
			m.focusEl.setAttribute('aria-activedescendant', n.id);
			if (scroll !== false) n.scrollIntoView({ block: 'nearest' });
			else { var top = n.offsetTop - m.list.offsetTop; if (top < m.list.scrollTop || top + n.offsetHeight > m.list.scrollTop + m.list.clientHeight) m.list.scrollTop = Math.max(0, top - m.list.clientHeight / 2); }
		} else {
			m.focusEl.removeAttribute('aria-activedescendant');
		}
	}

	function ddPlace(m) {
		var r = m.sel._dd.btn.getBoundingClientRect();
		var vw = document.documentElement.clientWidth, vh = window.innerHeight, pad = 8;
		var rootCss = getComputedStyle(document.documentElement);
		var safe = function (side) { return parseFloat(rootCss.getPropertyValue('--safe-' + side)) || 0; };
		var menu = m.menu;
		if (vw <= 560) { menu.classList.add('dd-sheet'); menu.style.cssText = ''; return; }
		menu.classList.remove('dd-sheet');
		var padL = pad + safe('left'), padR = pad + safe('right');
		var width = Math.min(Math.max(r.width, 200), vw - padL - padR);
		var left = Math.min(Math.max(padL, r.left), vw - width - padR);
		var below = vh - r.bottom - pad - safe('bottom'), above = r.top - pad - safe('top');
		menu.style.left = left + 'px';
		menu.style.minWidth = width + 'px';
		menu.style.maxWidth = (vw - padL - padR) + 'px';
		menu.style.maxHeight = '';
		var h = Math.min(menu.scrollHeight, 340);
		if (below >= h || below >= above) {
			menu.style.top = (r.bottom + 4) + 'px'; menu.style.bottom = '';
			menu.style.maxHeight = Math.max(120, Math.min(340, below - 4)) + 'px';
			menu.classList.remove('dd-up');
		} else {
			menu.style.top = ''; menu.style.bottom = (vh - r.top + 4) + 'px';
			menu.style.maxHeight = Math.max(120, Math.min(340, above - 4)) + 'px';
			menu.classList.add('dd-up');
		}
	}

	function ddOpenMenu(sel) {
		if (sel.disabled) return;
		ddClose(false);
		var dd = sel._dd;
		var many = sel.options.length > 10;
		var menu = el('<div class="dd-menu' + (dd.status ? ' dd-menu-status' : '') + '" id="' + dd.id + '-menu">' +
			(many ? '<div class="dd-search"><input type="search" placeholder="Search…" aria-label="Search options" autocomplete="off"></div>' : '') +
			'<div class="dd-list" role="listbox" tabindex="-1"></div></div>');
		var m = ddOpen = { sel: sel, id: dd.id, menu: menu, list: menu.querySelector('.dd-list'), search: menu.querySelector('.dd-search input'), active: null, typed: '', typedAt: 0 };
		m.focusEl = m.search || m.list;
		var name = ddLabelOf(sel);
		if (name) m.list.setAttribute('aria-label', name);
		if (m.search) { m.search.setAttribute('role', 'combobox'); m.search.setAttribute('aria-controls', dd.id + '-list'); m.search.setAttribute('aria-expanded', 'true'); m.list.id = dd.id + '-list'; }
		document.body.appendChild(menu);
		ddRenderOptions(m);
		ddPlace(m);
		dd.btn.setAttribute('aria-expanded', 'true');
		dd.btn.classList.add('is-open');
		m.focusEl.focus({ preventScroll: true });
		ddSetActive(m, m.active, true);

		menu.addEventListener('mousedown', function (e) { if (e.target !== m.search) e.preventDefault(); });
		menu.addEventListener('click', function (e) {
			var o = e.target.closest('.dd-opt');
			if (o && !o.classList.contains('is-disabled')) ddChoose(m, +o.getAttribute('data-i'));
		});
		menu.addEventListener('mousemove', function (e) {
			var o = e.target.closest('.dd-opt');
			if (o && !o.classList.contains('is-disabled') && +o.getAttribute('data-i') !== m.active) ddSetActive(m, +o.getAttribute('data-i'), false);
		});
		if (m.search) m.search.addEventListener('input', function () { m.active = null; ddRenderOptions(m); });
		menu.addEventListener('keydown', function (e) {
			var k = e.key;
			if (k === 'Escape') { e.preventDefault(); e.stopPropagation(); ddClose(true); return; }
			if (k === 'Tab') { ddClose(false); return; }
			if (k === 'ArrowDown') { e.preventDefault(); ddSetActive(m, ddNextEnabled(m, m.active, 1)); return; }
			if (k === 'ArrowUp') { e.preventDefault(); if (e.altKey) { ddChoose(m, m.active); return; } ddSetActive(m, ddNextEnabled(m, m.active, -1)); return; }
			if (k === 'Home' || k === 'PageUp') { if (m.search && k === 'Home') return; e.preventDefault(); ddSetActive(m, ddNextEnabled(m, -1, 1)); return; }
			if (k === 'End' || k === 'PageDown') { if (m.search && k === 'End') return; e.preventDefault(); ddSetActive(m, m.idx.length ? ddNextEnabled(m, m.idx[m.idx.length - 1], 0) : null); return; }
			if (k === 'Enter' || (k === ' ' && !m.search)) { e.preventDefault(); e.stopPropagation(); if (m.active != null && ddEnabled(m, m.active)) ddChoose(m, m.active); return; }
			if (!m.search && k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
				// Type-ahead: jump to the first option starting with the typed letters.
				var now = Date.now();
				m.typed = (now - m.typedAt > 700 ? '' : m.typed) + k.toLowerCase();
				m.typedAt = now;
				for (var p = 0; p < m.idx.length; p++) {
					var o = sel.options[m.idx[p]];
					if (ddEnabled(m, m.idx[p]) && o.text.toLowerCase().indexOf(m.typed) === 0) { ddSetActive(m, m.idx[p]); break; }
				}
			}
		});
	}

	function ddChoose(m, i) {
		var sel = m.sel;
		var changed = DD_PROTO_INDEX.get.call(sel) !== i;
		DD_PROTO_INDEX.set.call(sel, i);
		ddClose(true);
		ddSync(sel);
		if (changed) {
			sel._dd.btn.classList.remove('dd-invalid');
			sel.dispatchEvent(new Event('input', { bubbles: true }));
			sel.dispatchEvent(new Event('change', { bubbles: true }));
		}
	}

	function ddClose(refocus) {
		var m = ddOpen;
		if (!m) return;
		ddOpen = null;
		m.menu.remove();
		var btn = m.sel._dd && m.sel._dd.btn;
		if (btn) {
			btn.setAttribute('aria-expanded', 'false');
			btn.classList.remove('is-open');
			if (refocus && btn.isConnected) btn.focus({ preventScroll: true });
		}
	}

	function ddScan(root) {
		if (root.tagName === 'SELECT') ddEnhance(root);
		else if (root.querySelectorAll) $$('select', root).forEach(ddEnhance);
	}

	function initDropdowns() {
		if (initDropdowns.done || !window.MutationObserver) return;
		initDropdowns.done = true;
		ddScan(document.body);
		new MutationObserver(function (records) {
			records.forEach(function (r) {
				if (r.type === 'childList') {
					var t = r.target;
					if (t.tagName === 'SELECT' || t.tagName === 'OPTGROUP') { ddSync(t.tagName === 'SELECT' ? t : t.parentNode); return; }
					Array.prototype.forEach.call(r.addedNodes, function (n) { if (n.nodeType === 1) ddScan(n); });
					if (ddOpen && !ddOpen.sel.isConnected) ddClose(false);
				} else if (r.type === 'attributes' && r.target.tagName === 'SELECT' && r.target._dd) {
					if (r.attributeName === 'class' && r.target.className.indexOf('dd-native') < 0) r.target.classList.add('dd-native');
					ddSync(r.target);
				} else if (r.type === 'attributes' && r.target.tagName === 'OPTION' && r.target.parentNode) {
					var s = r.target.closest('select');
					if (s) ddSync(s);
				}
			});
		}).observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'class', 'style', 'required', 'selected', 'label'] });
		document.addEventListener('mousedown', function (e) {
			if (ddOpen && !ddOpen.menu.contains(e.target) && e.target !== ddOpen.sel._dd.btn && !ddOpen.sel._dd.btn.contains(e.target)) ddClose(false);
		}, true);
		window.addEventListener('resize', function () { if (ddOpen) ddPlace(ddOpen); });
		document.addEventListener('scroll', function (e) {
			if (!ddOpen || ddOpen.menu.contains(e.target)) return;
			if (!ddOpen.sel._dd.btn.isConnected) { ddClose(false); return; }
			ddPlace(ddOpen);
		}, true);
	}
	WD.initDropdowns = initDropdowns;
	WD.refreshDropdown = ddSync;

	/* ------------------------------------------------------------------ *
	 * Multi-select
	 * ------------------------------------------------------------------ */
	function multiSelect(o) {
		var value = (o.value || []).map(Number);
		var root = el('<div class="ms"><div class="ms-box"><input type="text" placeholder="' + esc(o.placeholder || 'Select…') + '" aria-label="' + esc(o.label || o.placeholder || 'Select') + '"></div></div>');
		var box = root.firstChild, input = box.querySelector('input'), list = null, hl = 0;
		function label(id) { var x = o.options.filter(function (a) { return a.id === id; })[0]; return x ? x.label : '#' + id; }
		function draw() {
			$$('.chip', box).forEach(function (c) { c.remove(); });
			value.forEach(function (id) {
				var c = el('<span class="chip">' + esc(label(id)) + '<button type="button" aria-label="Remove">×</button></span>');
				c.querySelector('button').onclick = function (e) { e.stopPropagation(); value = value.filter(function (v) { return v !== id; }); draw(); changed(); };
				box.insertBefore(c, input);
			});
			input.placeholder = value.length ? '' : (o.placeholder || 'Select…');
		}
		function changed() { if (o.onChange) o.onChange(value.slice()); }
		function options() {
			var q = input.value.trim().toLowerCase();
			return o.options.filter(function (a) { return value.indexOf(a.id) < 0 && !a.disabled && (!q || a.label.toLowerCase().indexOf(q) >= 0); });
		}
		function openList() {
			closeList();
			var opts = options();
			list = el('<div class="ms-list" role="listbox"></div>');
			if (!opts.length) list.innerHTML = '<div class="none">' + (o.options.length ? 'No matches' : esc(o.emptyText || 'Nothing to choose from')) + '</div>';
			opts.slice(0, 60).forEach(function (a, i) {
				var b = el('<button type="button" role="option"' + (i === hl ? ' class="hl"' : '') + '>' + (a.html || esc(a.label)) + (a.sub ? ' <span class="muted small">' + esc(a.sub) + '</span>' : '') + '</button>');
				b.onmousedown = function (e) { e.preventDefault(); pick(a.id); };
				list.appendChild(b);
			});
			root.appendChild(list);
		}
		function closeList() { if (list) { list.remove(); list = null; } }
		function pick(id) {
			if (o.single) value = [id]; else value.push(id);
			input.value = ''; hl = 0; draw(); changed();
			if (o.single) { closeList(); input.blur(); } else openList();
		}
		box.onclick = function () { input.focus(); };
		input.onfocus = openList;
		input.oninput = function () { hl = 0; openList(); };
		input.onblur = function () { setTimeout(closeList, 120); };
		input.onkeydown = function (e) {
			var opts = options();
			if (e.key === 'ArrowDown') { hl = Math.min(hl + 1, opts.length - 1); openList(); e.preventDefault(); }
			else if (e.key === 'ArrowUp') { hl = Math.max(hl - 1, 0); openList(); e.preventDefault(); }
			else if (e.key === 'Enter') { if (opts[hl]) { pick(opts[hl].id); } e.preventDefault(); }
			else if (e.key === 'Backspace' && !input.value && value.length) { value.pop(); draw(); changed(); }
		};
		draw();
		root.getValue = function () { return value.slice(); };
		root.setValue = function (v) { value = (v || []).map(Number); draw(); };
		return root;
	}
	WD.multiSelect = multiSelect;

	function memberOptions(onlyAssignable) {
		return S.members.filter(function (m) { return m.active && (!onlyAssignable || m.assignable); }).map(function (m) {
			return { id: m.id, label: m.name, sub: m.teams.map(function (t) { return team(t) ? team(t).name : ''; }).filter(Boolean).join(', ') };
		});
	}
	WD.memberOptions = memberOptions;

	function selectOptions(items, selected, emptyLabel, labelFn) {
		var h = emptyLabel !== false ? '<option value="">' + esc(emptyLabel || '—') + '</option>' : '';
		items.forEach(function (it) {
			h += '<option value="' + esc(it.id) + '"' + (String(it.id) === String(selected) ? ' selected' : '') + '>' + esc(labelFn ? labelFn(it) : it.name) + '</option>';
		});
		return h;
	}
	function mapOptions(obj, selected) {
		return Object.keys(obj).map(function (k) { return '<option value="' + esc(k) + '"' + (k === selected ? ' selected' : '') + '>' + esc(obj[k]) + '</option>'; }).join('');
	}
	WD.selectOptions = selectOptions;
	WD.mapOptions = mapOptions;

	/* ------------------------------------------------------------------ *
	 * Links editor (URL + optional label; no file uploads)
	 * ------------------------------------------------------------------ */
	function linksEditor(links) {
		var root = el('<div><div class="link-rows"></div><button type="button" class="btn btn-sm">' + I.plus + ' Add link</button><div class="muted small" style="margin-top:6px">Reference links only (http/https). File uploads are not supported.</div></div>');
		var rows = root.querySelector('.link-rows');
		function add(l) {
			l = l || {};
			var r = el('<div class="link-row"><input type="url" placeholder="https://…" aria-label="Link URL"><input type="text" placeholder="Label (optional)" aria-label="Link label" maxlength="200"><button type="button" class="btn btn-ghost btn-icon btn-sm" aria-label="Remove link">' + I.trash + '</button></div>');
			r.dataset.id = l.id || '';
			r.children[0].value = l.url || '';
			r.children[1].value = l.label || '';
			r.children[2].onclick = function () { r.remove(); };
			rows.appendChild(r);
			return r;
		}
		(links || []).forEach(add);
		root.querySelector('.btn').onclick = function () { add().children[0].focus(); };
		root.getValue = function () {
			var out = [], bad = null;
			$$('.link-row', rows).forEach(function (r) {
				var url = r.children[0].value.trim();
				if (!url) return;
				if (!isUrl(url)) bad = url;
				out.push({ id: r.dataset.id ? parseInt(r.dataset.id, 10) : 0, url: url, label: r.children[1].value.trim() });
			});
			if (bad) throw new Error('"' + bad + '" is not a valid web link. Use http:// or https:// links.');
			return out;
		};
		return root;
	}

	/* ------------------------------------------------------------------ *
	 * Billing recipients editor: email rows marked To or CC (client form, invoices, email)
	 * ------------------------------------------------------------------ */
	function emailListEditor(list, opts) {
		opts = opts || {};
		var root = el('<div class="email-list"><div data-rows></div><button type="button" class="btn btn-sm" data-add-email>' + I.plus + ' Add email</button></div>');
		var rows = root.querySelector('[data-rows]');
		function row(e) {
			var r = el('<div class="email-row"><input type="email" maxlength="190" placeholder="name@company.com" aria-label="Email address"><div class="seg seg-sm" role="group" aria-label="Recipient type"><button type="button" data-et="to">To</button><button type="button" data-et="cc">CC</button><button type="button" data-et="bcc">BCC</button></div><button type="button" class="btn btn-ghost btn-icon btn-sm" data-rm-email aria-label="Remove email">' + I.trash + '</button></div>');
			r.querySelector('input').value = e && e.email || '';
			r.dataset.type = e && (e.type === 'cc' || e.type === 'bcc') ? e.type : 'to';
			paintRow(r);
			rows.appendChild(r);
			return r;
		}
		function paintRow(r) { $$('[data-et]', r).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-et') === r.dataset.type); }); }
		(list || []).forEach(row);
		if (!rows.children.length && !opts.allowEmpty) row(null);
		root.querySelector('[data-add-email]').onclick = function () {
			var r = row({ email: '', type: rows.children.length ? 'cc' : 'to' });
			r.querySelector('input').focus();
		};
		on(rows, 'click', '[data-et]', function (e, b) { var r = b.closest('.email-row'); r.dataset.type = b.getAttribute('data-et'); paintRow(r); });
		on(rows, 'click', '[data-rm-email]', function (e, b) { b.closest('.email-row').remove(); });
		root.setValue = function (l) { rows.innerHTML = ''; (l || []).forEach(row); if (!rows.children.length && !opts.allowEmpty) row(null); };
		/** [{email, type}]; throws on an invalid address or when only CC is given. */
		root.getValue = function () {
			var out = [];
			$$('.email-row', rows).forEach(function (r) {
				var v = r.querySelector('input').value.trim();
				if (!v) return;
				if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) throw new Error('"' + v + '" is not a valid email address.');
				out.push({ email: v, type: r.dataset.type });
			});
			if (out.length && !out.some(function (x) { return x.type === 'to'; })) throw new Error('Mark at least one email address as "To".');
			return out;
		};
		return root;
	}
	WD.emailListEditor = emailListEditor;

	/* ------------------------------------------------------------------ *
	 * Task form modal: create / edit / follow-up
	 * ------------------------------------------------------------------ */
	/**
	 * ctx (Phase 5): { project_id } when opened from a project dashboard. From a main project the
	 * task goes to it or one of its sub-projects; from a sub-project it is locked to that sub-project
	 * and its main project. The server enforces the same rule (context_project_id).
	 */
	function openTaskForm(mode, task, parent, ctx) {
		task = task || {};
		var isEdit = mode === 'edit', isFollow = mode === 'followup';
		var cp = ctx && ctx.project_id ? project(+ctx.project_id) : null;
		if (cp && !isEdit && !isFollow) task = { project_id: cp.id, client_id: cp.client_id || (cp.parent_id && project(+cp.parent_id) ? project(+cp.parent_id).client_id : '') };
		var src = isFollow ? parent : task;
		var def = {
			title: isEdit ? task.title : '',
			description: isEdit ? task.description : '',
			project_id: src && src.project_id || '',
			client_id: src && src.client_id || (src && src.project_id && project(src.project_id) ? project(src.project_id).client_id : '') || '',
			// New tasks start with no team and no assignee; the creator picks them.
			team_ids: isEdit || isFollow ? taskTeamIds(isEdit ? task : parent) : [],
			assignee_ids: isEdit ? task.assignee_ids : (isFollow ? parent.assignee_ids : []),
			tag_ids: isEdit ? task.tag_ids : (isFollow ? parent.tag_ids : []),
			priority: isEdit ? task.priority : (isFollow ? parent.priority : 'medium'),
			work_type: isEdit ? task.work_type : (isFollow ? parent.work_type : 'office'),
			scheduled_date: isEdit ? task.scheduled_date : (isFollow ? addDays(S.today, 1) : S.today),
			due_date: isEdit ? task.due_date : '',
			due_time: isEdit ? task.due_time : '',
			reminder_at: isEdit ? task.reminder_at : '',
			links: isEdit ? task.links : [],
			// Package link (follow-ups start without one: their scope is decided separately).
			package: isEdit && task.package ? task.package : null
		};
		var title = isEdit ? 'Edit task #' + task.id : isFollow ? 'Create follow-up task' : 'New task';
		var projects = S.projects.filter(function (p) { return p.is_active == 1 || String(p.id) === String(def.project_id); });
		var cpRoot = cp ? (cp.parent_id ? project(+cp.parent_id) : cp) : null;
		if (cp) projects = cp.parent_id ? [cp] : [cp].concat(S.projects.filter(function (p) { return +p.parent_id === +cp.id && (p.is_active == 1); }));
		var teams = S.teams.filter(function (t) { return can('ndwd_view_all') || can('ndwd_edit_all_tasks') || S.user.teams.indexOf(t.id) >= 0 || def.team_ids.indexOf(t.id) >= 0; });
		// Projects offered for a client: only that client's projects (the task's current project is kept on first paint).
		function projectsFor(clientId, keepId) {
			return projects.filter(function (p) { return !clientId || +p.client_id === +clientId || (keepId && String(p.id) === String(keepId)); });
		}
		function projectLabel(p) { var par = p.parent_id ? project(+p.parent_id) : null; return (par ? par.name + ' › ' : '') + p.name + (p.client ? ' — ' + p.client : ''); }
		var form = el('<form novalidate>' +
			(isFollow ? '<div class="alert alert-info">Follow-up for <b>#' + parent.id + ' ' + esc(parent.title) + '</b>. It gets its own ID, schedule, status and history; the original task is not changed.</div>' : '') +
			'<div class="alert alert-error hidden" data-err role="alert"></div>' +
			'<div class="form-grid">' +
			'<label class="field span-2"><span>' + (isFollow ? 'Follow-up title' : 'Task / Activity') + ' <span class="req">*</span></span><input type="text" name="title" maxlength="255" required autofocus placeholder="' + (isFollow ? 'e.g. Call client regarding website changes' : 'What needs to be done?') + '"></label>' +
			'<label class="field span-2"><span>' + (isFollow ? 'Notes' : 'Description') + '</span><textarea name="description" rows="3" placeholder="Details, context or instructions"></textarea></label>' +
			(cp ? (cp.parent_id ? '<div class="field span-2"><span>Project</span><div class="alert alert-info ctx-lock"><span><b>' + esc(cpRoot ? cpRoot.name : '') + '</b> › <b>' + esc(cp.name) + '</b><br><small>Created in this sub-project, so it belongs here. The main project follows automatically and cannot be changed.</small></span></div><input type="hidden" name="project_id" value="' + cp.id + '"><input type="hidden" name="client_id" value="' + esc(def.client_id) + '"></div>'
				: '<label class="field"><span>Main project</span><input value="' + esc(cp.name) + '" disabled></label><label class="field"><span>Sub-project <span class="hint">(optional)</span></span><select name="project_id">' + '<option value="' + cp.id + '"' + (+def.project_id === +cp.id ? ' selected' : '') + '>None — the main project itself</option>' + projects.filter(function (p) { return +p.id !== +cp.id; }).map(function (p) { return '<option value="' + p.id + '"' + (+def.project_id === +p.id ? ' selected' : '') + '>' + esc(p.name) + '</option>'; }).join('') + '</select></label><input type="hidden" name="client_id" value="' + esc(def.client_id) + '">')
			: '<label class="field"><span>Project <span class="hint">(optional — fills in its client)</span></span><select name="project_id">' + selectOptions(projectsFor(def.client_id, def.project_id), def.project_id, 'No project', projectLabel) + '</select></label>' +
			'<label class="field"><span>Client</span><select name="client_id">' + selectOptions(S.clients.filter(function (c) { return c.is_active || c.id === +def.client_id; }), def.client_id, 'No client') + '</select></label>') +
			'<div class="field span-2 pkg-field" data-pkg-wrap hidden><span>Project package <span class="hint">(optional — which package this work belongs to)</span></span><div data-pkg></div></div>' +
				'<div class="field span-2"><span>Team</span><div data-teams></div></div>' +
				'<div class="field span-2"><span>' + (isEdit ? 'Assigned to' : 'Initial assignee / Start with') + ' <span class="hint">— optional. Lists the chosen team\'s members. Leave empty to put the task in the team\'s shared pool, where any member can pick it up.</span></span><div data-assignees></div></div>' +
			'<div class="field"><span>Priority</span><div class="seg" data-seg="priority">' + Object.keys(S.priorities).map(function (k) { return '<button type="button" data-v="' + k + '">' + esc(S.priorities[k]) + '</button>'; }).join('') + '</div></div>' +
			'<div class="field"><span>Work type</span><div class="seg" data-seg="work_type">' + Object.keys(S.work_types).map(function (k) { return '<button type="button" data-v="' + esc(k) + '">' + esc(S.work_types[k]) + '</button>'; }).join('') + '</div></div>' +
			'<label class="field"><span>Scheduled date <span class="req">*</span></span><input type="date" name="scheduled_date" required></label>' +
			'<label class="field"><span>Due date <span class="hint">(optional)</span></span><input type="date" name="due_date"></label>' +
			'<label class="field"><span>Due time <span class="hint">(optional)</span></span><input type="time" name="due_time"></label>' +
			'<label class="field"><span>Reminder <span class="hint">(optional)</span></span><input type="datetime-local" name="reminder_at"></label>' +
			(isFollow ? '' : '<div class="field span-2"><span>Tags</span><div data-tags></div></div>') +
			'<div class="field span-2"><span>Reference links</span><div data-links></div></div>' +
			(isEdit ? '' : '<label class="field span-2"><span>Remarks <span class="hint">(optional, saved as the first remark)</span></span><textarea name="remarks" rows="2"></textarea></label>') +
				(isFollow ? '' : WD.taskVisibilityFields()) +
			'</div>' +
			(isEdit || isFollow ? '' : '<p class="muted small" style="margin-top:12px">New tasks always start as <b>Pending</b>. You can change the status after the task is created.</p>') +
			'</form>');

		form.title.value = def.title || '';
		form.description.value = def.description || '';
		form.scheduled_date.value = def.scheduled_date || S.today;
		form.due_date.value = def.due_date || '';
		form.due_time.value = def.due_time || '';
		form.reminder_at.value = def.reminder_at || '';
		var segs = { priority: def.priority, work_type: def.work_type };
		$$('[data-seg]', form).forEach(function (seg) {
			var key = seg.getAttribute('data-seg');
			function paint() { $$('button', seg).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === segs[key]); }); }
			seg.onclick = function (e) { var b = e.target.closest('button'); if (b) { segs[key] = b.getAttribute('data-v'); paint(); } };
			paint();
		});
		var assignOpts = memberOptions(true);
		(def.assignee_ids || []).forEach(function (id) { if (!assignOpts.some(function (o) { return o.id === id; })) assignOpts.push({ id: id, label: memberName(id) }); });
		// Eligible members follow the chosen teams; with no team, everyone the user may assign.
		function eligibleFor(teamIds, keep) {
			if (!teamIds.length) return assignOpts;
			return assignOpts.filter(function (o) {
				var mm = member(o.id);
				return (keep || []).indexOf(o.id) >= 0 || (mm && mm.active && mm.teams.some(function (t) { return teamIds.indexOf(t) >= 0; }));
			});
		}
		var assignHolder = form.querySelector('[data-assignees]'), assignees = null;
		function paintAssignees(teamIds, value) {
			assignees = multiSelect({ options: eligibleFor(teamIds, isEdit ? def.assignee_ids : []), value: value, placeholder: teamIds.length ? 'Choose a team member (optional)…' : 'Search members…', label: 'Assignees', emptyText: 'No eligible members in the chosen team.' });
			assignHolder.innerHTML = '';
			assignHolder.appendChild(assignees);
		}
		paintAssignees(def.team_ids, def.assignee_ids);
		var teamsMs = multiSelect({
			options: teams.map(function (t) { return { id: t.id, label: t.name }; }), value: def.team_ids, placeholder: 'Add teams…', label: 'Teams',
			onChange: function (now) {
				var allowed = eligibleFor(now, isEdit ? def.assignee_ids : []).map(function (o) { return o.id; });
				paintAssignees(now, assignees.getValue().filter(function (id) { return allowed.indexOf(id) >= 0; }));
			}
		});
		form.querySelector('[data-teams]').appendChild(teamsMs);
		var tagsMs = null;
		if (!isFollow) {
			tagsMs = multiSelect({ options: S.tags.map(function (g) { return { id: +g.id, label: g.name, html: '<span class="tag"><i style="background:' + esc(g.color) + '"></i>' + esc(g.name) + '</span>' }; }), value: def.tag_ids, placeholder: 'Add tags…', label: 'Tags', emptyText: 'No tags yet — an admin can create them under Projects & Tags.' });
			form.querySelector('[data-tags]').appendChild(tagsMs);
		}
		function paintProjects(keep) {
			if (cp) return; // Opened from a project dashboard: the choice is fixed to that project's family.
			var cid = form.client_id.value, list = projectsFor(cid);
			var pid = list.some(function (p) { return String(p.id) === String(keep); }) ? keep : '';
			form.project_id.innerHTML = selectOptions(list, pid, cid ? 'No project (client only)' : 'No project', projectLabel);
		}
		form.project_id.onchange = function () {
			if (cp) return;
			var p = project(+form.project_id.value);
			if (p && p.client_id && client(+p.client_id)) {
				if (!form.client_id.querySelector('option[value="' + +p.client_id + '"]')) form.client_id.insertAdjacentHTML('beforeend', selectOptions([client(+p.client_id)], '', false));
				form.client_id.value = p.client_id;
				paintProjects(p.id);
			}
		};
		// Client package: only the chosen client's active packages are offered. "Included
		// service" takes one unit of the chosen service; retainer and additional scope take none.
		var pkgWrap = form.querySelector('[data-pkg-wrap]'), pkgBox = form.querySelector('[data-pkg]');
		var dp = def.package || {};
		var pk = { client: '', list: [], alloc: dp.client_package_id || '', scope: dp.scope || '', item: dp.item_id || '', note: dp.note || '', manual: !!dp.item_id, override: false, reason: '', loaded: false };
		function pkAlloc() { return pk.list.filter(function (a) { return String(a.id) === String(pk.alloc); })[0] || null; }
		function holdsUnit(it) { return isEdit && dp.item_id && +dp.item_id === +it.id && (dp.usage === 'reserved' || dp.usage === 'consumed'); }
		function detectItem(a) {
			var t = ' ' + form.title.value.toLowerCase().replace(/[^a-z0-9]+/g, ' ') + ' ', best = 0, hits = [];
			a.items.filter(function (it) { return it.is_active; }).forEach(function (it) {
				var sc = 0;
				String(it.keywords || '').split(',').forEach(function (k) { k = k.trim(); if (k && t.indexOf(' ' + k + ' ') >= 0) sc++; });
				if (sc > best) { best = sc; hits = [it]; } else if (sc && sc === best) hits.push(it);
			});
			return hits.length === 1 ? hits[0].id : '';
		}
		function paintPkg() {
			var cid = form.client_id.value;
			pkgWrap.hidden = !cid;
			if (!cid) return;
			if (!pk.loaded) { pkgBox.innerHTML = '<span class="muted small">Loading packages…</span>'; return; }
			var a = pkAlloc();
			if (a && !pk.scope) pk.scope = 'included';
			if (a && pk.scope === 'included' && !pk.manual) { var d = detectItem(a); if (d) pk.item = d; }
			// Each project has its own packages: a parent's or sibling's package is never offered.
			var pid = +form.project_id.value || 0;
			var usable = pk.list.filter(function (x) { return !x.project_id || +x.project_id === pid; });
			if (a && usable.indexOf(a) < 0) { pk.alloc = ''; pk.scope = ''; pk.item = ''; pk.manual = false; a = null; }
			var h = '<select data-pk-alloc aria-label="Package"><option value="">' + (usable.length ? 'No package' : 'No active package for this project') + '</option>' + usable.map(function (x) {
				return '<option value="' + x.id + '"' + (String(x.id) === String(pk.alloc) ? ' selected' : '') + '>' + esc(x.name + (x.project_name ? ' — ' + x.project_name : ' — client-wide') + (x.effective_status !== 'active' ? ' (' + x.status_label + ')' : '')) + '</option>';
			}).join('') + '</select>';
			if (a) {
				h += '<div class="pkg-sum small muted">' + esc(a.type_label + (a.cycle ? ' · ' + a.cycle.label : '')) + ' · ' + a.items.filter(function (it) { return it.is_active; }).map(function (it) { return esc(it.service_name) + ' <b>' + Math.max(0, it.remaining) + '</b>/' + (it.allocated + it.adjusted) + ' left'; }).join(' · ') + '</div>' +
					'<div class="seg" data-pk-scope role="group" aria-label="Package scope">' + Object.keys(S.package_scopes).map(function (k) { return '<button type="button" data-v="' + k + '"' + (pk.scope === k ? ' class="on"' : '') + '>' + esc(S.package_scopes[k]) + '</button>'; }).join('') + '</div>';
				if (pk.scope === 'included') {
					var items = a.items.filter(function (it) { return it.is_active || String(it.id) === String(pk.item); });
					h += '<select data-pk-item aria-label="Package service"><option value="">Choose the service…</option>' + items.map(function (it) {
						var left = it.remaining + (holdsUnit(it) ? 1 : 0);
						return '<option value="' + it.id + '"' + (String(it.id) === String(pk.item) ? ' selected' : '') + '>' + esc(it.service_name + ' — ' + Math.max(0, left) + ' of ' + (it.allocated + it.adjusted) + ' ' + it.unit_plural + ' left') + '</option>';
					}).join('') + '</select>';
					var cur = items.filter(function (it) { return String(it.id) === String(pk.item); })[0];
					if (cur && cur.remaining < 1 && !holdsUnit(cur)) {
						h += '<div class="alert alert-warn small">No ' + esc(cur.unit_plural) + ' left for ' + esc(cur.service_name) + ' in this cycle. Mark the task as <b>Additional scope</b>' + (can('ndwd_manage_accounts') ? ', or approve the extra work:' : ', or ask an Accounts admin to approve the extra work.') + '</div>' +
							(can('ndwd_manage_accounts') ? '<label class="check small"><input type="checkbox" data-pk-ovr' + (pk.override ? ' checked' : '') + '> Approve work beyond the package limit</label><input data-pk-reason maxlength="500" placeholder="Reason for approving (required)" value="' + esc(pk.reason) + '"' + (pk.override ? '' : ' hidden') + '>' : '');
					} else if (!cur) h += '<div class="small muted">Pick the service this task uses; it takes one ' + (a.items[0] ? esc(a.items[0].unit) : 'unit') + ' when saved and counts as used once completed.</div>';
				} else {
					h += '<div class="small muted">' + (pk.scope === 'retainer' ? 'Covered by the package’s general scope — no units are used.' : 'Outside the package — no units are used; it can be billed later as a custom amount.') + '</div>';
				}
			} else {
				h += '<label class="check small"><input type="checkbox" data-pk-add' + (pk.scope === 'additional' ? ' checked' : '') + '> Additional / out-of-package work <span class="muted">(can be billed later as a custom amount)</span></label>';
			}
			h += '<textarea data-pk-note rows="2" maxlength="1000" placeholder="Scope remarks / reason (optional — saved as a remark and shown in the invoice work log)">' + esc(pk.note) + '</textarea>';
			pkgBox.innerHTML = h;
		}
		function loadPkgs() {
			var cid = form.client_id.value;
			if (cid === pk.client) return paintPkg();
			pk.client = cid; pk.loaded = false;
			if (String(cid) !== String(def.client_id)) { pk.alloc = ''; pk.scope = ''; pk.item = ''; pk.manual = false; }
			paintPkg();
			if (!cid) return;
			api('clients/' + cid + '/packages').then(function (r) {
				if (pk.client !== cid) return;
				pk.list = r.packages.filter(function (x) { return x.can_link || String(x.id) === String(pk.alloc); });
				pk.loaded = true;
				paintPkg();
			}, function () { pk.list = []; pk.loaded = true; paintPkg(); });
		}
		pkgBox.addEventListener('change', function (e) {
			var t = e.target;
			if (t.matches('[data-pk-alloc]')) { pk.alloc = t.value; pk.scope = t.value ? 'included' : ''; pk.item = ''; pk.manual = false; pk.override = false; paintPkg(); }
			else if (t.matches('[data-pk-item]')) { pk.item = t.value; pk.manual = true; pk.override = false; paintPkg(); }
			else if (t.matches('[data-pk-add]')) { pk.scope = t.checked ? 'additional' : ''; }
			else if (t.matches('[data-pk-ovr]')) { pk.override = t.checked; paintPkg(); }
		});
		pkgBox.addEventListener('input', function (e) {
			if (e.target.matches('[data-pk-note]')) pk.note = e.target.value;
			if (e.target.matches('[data-pk-reason]')) pk.reason = e.target.value;
		});
		pkgBox.addEventListener('click', function (e) {
			var b = e.target.closest('[data-pk-scope] button');
			if (b) { pk.scope = b.getAttribute('data-v'); paintPkg(); }
		});
		form.title.addEventListener('input', debounce(function () { if (pk.scope === 'included' && !pk.manual && pkAlloc()) paintPkg(); }, 300));

		// Changing the client keeps the project only if it belongs to that client.
		form.client_id.onchange = function () { paintProjects(form.project_id.value); loadPkgs(); };
		var prevProjectChange = form.project_id.onchange;
		form.project_id.onchange = function () { prevProjectChange(); loadPkgs(); };
		if (def.client_id && !def.project_id) paintProjects('');
		if (!isFollow) loadPkgs();
		var links = linksEditor(def.links);
		form.querySelector('[data-links]').appendChild(links);

		var m = modal({
			title: title, size: 'lg', body: form, sticky: true,
			foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-save>' + (isEdit ? 'Save changes' : isFollow ? 'Create follow-up' : 'Create task') + '</button>'
		});
		var errBox = form.querySelector('[data-err]');
		function fail(msg) { errBox.textContent = msg; errBox.classList.remove('hidden'); m.body.scrollTop = 0; }
		var save = m.el.querySelector('[data-save]');
		form.onsubmit = function (e) { e.preventDefault(); save.click(); };
		var cvFields = isFollow ? null : WD.bindTaskVisibility(form, isEdit ? task.id : 0);
		save.onclick = function () {
			errBox.classList.add('hidden');
			var body;
			try {
				body = {
					title: form.title.value.trim(),
					description: form.description.value,
					project_id: form.project_id.value || 0,
					client_id: form.client_id.value || 0,
					team_ids: teamsMs.getValue(),
					assignee_ids: assignees.getValue(),
					priority: segs.priority,
					work_type: segs.work_type,
					scheduled_date: form.scheduled_date.value,
					due_date: form.due_date.value,
					due_time: form.due_time.value,
					reminder_at: form.reminder_at.value,
					links: links.getValue()
				};
			} catch (err) { return fail(err.message); }
				if (tagsMs) body.tag_ids = tagsMs.getValue();
			if (cvFields && cvFields.loaded() && body.client_id) body.client_visibility = cvFields.value();
			if (!isEdit && form.remarks) body.remarks = form.remarks.value;
			if (!isFollow && body.client_id) {
				body.client_package_id = pk.alloc || 0;
				body.package_scope = pk.scope || '';
				body.package_item_id = pk.scope === 'included' ? pk.item || 0 : 0;
				body.package_note = pk.note;
				if (pk.override) { body.package_override = true; body.package_override_reason = pk.reason; }
			}
			if (!body.title) { form.title.focus(); return fail('Please enter a title.'); }
			if (!body.scheduled_date) return fail('Please choose a scheduled date.');
			if (body.due_date && body.due_date < body.scheduled_date) return fail('Due date cannot be before the scheduled date.');
			if (!body.assignee_ids.length && !body.team_ids.length && isEdit) return fail('Choose at least one assignee or a team.');
			if (body.client_package_id && body.package_scope === 'included' && !body.package_item_id) return fail('Choose which package service this task uses, or pick Retainer or Additional scope.');
			if (pk.override && !pk.reason.trim()) return fail('Give the reason for approving work beyond the package limit.');
			if (cp && !isEdit && !isFollow) body.context_project_id = cp.id;
			var req = isEdit ? api('tasks/' + task.id, { method: 'PUT', body: body })
				: isFollow ? api('tasks/' + parent.id + '/followups', { method: 'POST', body: body })
				: api('tasks', { method: 'POST', body: body });
			busy(save, true);
			req.then(function (t) {
				m.close();
				toast(isEdit ? 'Task updated' : isFollow ? 'Follow-up #' + t.id + ' created for ' + fmtDay(t.scheduled_date) : 'Task #' + t.id + ' created');
				refresh();
				if (ctx && typeof ctx.done === 'function') ctx.done(t);
				if (isEdit || isFollow) { if ($('.drawer')) openTask(isFollow ? parent.id : t.id, true); }
			}, function (e) { busy(save, false); fail(e.message); });
		};
	}
	WD.openTaskForm = openTaskForm;

	/* ------------------------------------------------------------------ *
	 * Team handoff: a team manager (or Admin) passes the task to another team
	 * ------------------------------------------------------------------ */
	function openHandoff(t) {
		var fromIds = t.handoff_from || [];
		var form = el('<form novalidate>' +
			'<div class="alert alert-error hidden" data-err role="alert"></div>' +
			'<p class="muted small" style="margin-top:0">Use this when your team has finished its part. The task moves to the receiving team and starts again as <b>Pending</b>; your team\'s part, status and assignees are kept in the task history.</p>' +
			'<div class="form-grid">' +
			'<label class="field"><span>From team</span><select name="from_team_id"' + (fromIds.length > 1 ? '' : ' disabled') + '>' + selectOptions(fromIds.map(function (id) { return team(id) || { id: id, name: 'Team #' + id }; }), fromIds.indexOf(+t.team_id) >= 0 ? t.team_id : fromIds[0], false) + '</select></label>' +
			'<label class="field"><span>Receiving team <span class="req">*</span></span><select name="to_team_id"></select></label>' +
			'<div class="field span-2"><span>Receiving members <span class="hint">— all current members are selected; remove anyone who should not take it</span></span><div data-members></div></div>' +
			'<label class="field span-2"><span>Handoff remarks <span class="hint">(optional, saved as a labelled remark)</span></span><textarea name="note" rows="2" placeholder="What was done, what is next"></textarea></label>' +
			'<div class="field span-2"><span>Reference links <span class="hint">(optional, with descriptions)</span></span><div data-hlinks></div></div>' +
			'</div></form>');
		var hLinks = linksEditor([]);
		form.querySelector('[data-hlinks]').appendChild(hLinks);
		var members = null;
		function paintTeams() {
			var from = +form.from_team_id.value, keep = form.to_team_id.value;
			form.to_team_id.innerHTML = selectOptions(S.teams.filter(function (x) { return x.id !== from; }), keep, 'Choose a team…');
			paintMembers();
		}
		function paintMembers() {
			var tid = +form.to_team_id.value;
			var opts = tid ? S.members.filter(function (mm) { return mm.active && mm.teams.indexOf(tid) >= 0; }).map(function (mm) { return { id: mm.id, label: mm.name }; }) : [];
			var holder = form.querySelector('[data-members]');
			holder.innerHTML = '';
			members = multiSelect({ options: opts, value: opts.map(function (o) { return o.id; }), placeholder: tid ? 'Search members…' : 'Choose the receiving team first', label: 'Receiving members', emptyText: tid ? 'This team has no active members.' : 'Choose the receiving team first.' });
			holder.appendChild(members);
		}
		form.from_team_id.onchange = paintTeams;
		form.to_team_id.onchange = paintMembers;
		paintTeams();

		var m = modal({
			title: 'Hand off task #' + t.id, body: form, sticky: true,
			foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-save>' + I.fwd + ' Hand off</button>'
		});
		var errBox = form.querySelector('[data-err]');
		function fail(msg) { errBox.textContent = msg; errBox.classList.remove('hidden'); }
		var save = m.el.querySelector('[data-save]');
		form.onsubmit = function (e) { e.preventDefault(); save.click(); };
		save.onclick = function () {
			errBox.classList.add('hidden');
			var to = +form.to_team_id.value, lk;
			if (!to) { form.to_team_id.focus(); return fail('Choose the team that receives the task.'); }
			try { lk = hLinks.getValue(); } catch (er) { return fail(er.message); }
			busy(save, true);
			api('tasks/' + t.id + '/handoff', { method: 'POST', body: { from_team_id: +form.from_team_id.value, to_team_id: to, member_ids: members.getValue(), note: form.note.value, links: lk } }).then(function () {
				m.close();
				toast('Task handed off to ' + (team(to) ? team(to).name : 'the receiving team'));
				refresh();
				if ($('.drawer')) openTask(t.id, true);
			}, function (e) { busy(save, false); fail(e.message); });
		};
	}
	WD.openHandoff = openHandoff;

	/** Internal handoff (own task) or reassignment (with the permission) within the same team. */
	function openMemberHandoff(t) {
		var ids = taskTeamIds(t);
		var froms = t.assignee_ids.filter(function (id) { return t.can_reassign || id === S.user.id; });
		var fromDefault = froms.indexOf(S.user.id) >= 0 ? S.user.id : froms[0];
		var form = el('<form novalidate><div class="alert alert-error hidden" data-err role="alert"></div><p class="muted small" style="margin-top:0">Responsibility moves to the receiving member. The task keeps its ID, client, project, priority, due date and team.</p><div class="form-grid">' +
			'<label class="field"><span>From member</span><select name="from"' + (froms.length > 1 ? '' : ' disabled') + '>' + selectOptions(froms.map(function (id) { return { id: id, name: memberName(id) }; }), fromDefault, froms.length ? false : 'Team pool') + '</select></label>' +
			'<label class="field"><span>Receiving member <span class="req">*</span></span><select name="to"></select></label>' +
			'<label class="field span-2"><span>Handoff remarks <span class="hint">(optional, saved as a labelled remark)</span></span><textarea name="note" rows="2" placeholder="e.g. Initial editing completed. Please finish the final export."></textarea></label>' +
			'<div class="field span-2"><span>Reference links <span class="hint">(optional, with descriptions)</span></span><div data-hlinks></div></div></div></form>');
		function paintTo() {
			var from = +form.from.value || 0;
			form.to.innerHTML = selectOptions(S.members.filter(function (mm) { return mm.active && mm.id !== from && mm.teams.some(function (x) { return ids.indexOf(x) >= 0; }); }).map(function (mm) { return { id: mm.id, name: mm.name }; }), '', 'Choose a member…');
		}
		form.from.onchange = paintTo;
		paintTo();
		var lks = linksEditor([]);
		form.querySelector('[data-hlinks]').appendChild(lks);
		var m = modal({ title: 'Hand off to member · task #' + t.id, body: form, sticky: true, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-save>' + I.fwd + ' Hand off</button>' });
		var errBox = form.querySelector('[data-err]');
		function fail(msg) { errBox.textContent = msg; errBox.classList.remove('hidden'); }
		var save = m.el.querySelector('[data-save]');
		form.onsubmit = function (e) { e.preventDefault(); save.click(); };
		save.onclick = function () {
			errBox.classList.add('hidden');
			var to = +form.to.value, links;
			if (!to) return fail('Choose the member who receives the task.');
			try { links = lks.getValue(); } catch (er) { return fail(er.message); }
			busy(save, true);
			api('tasks/' + t.id + '/handoff-member', { method: 'POST', body: { from_user_id: +form.from.value || 0, to_user_id: to, note: form.note.value, links: links } }).then(function () {
				m.close();
				toast('Task handed to ' + memberName(to));
				refresh();
				if ($('.drawer')) openTask(t.id, true);
			}, function (e) { busy(save, false); fail(e.message); });
		};
	}
	WD.openMemberHandoff = openMemberHandoff;

	/* ------------------------------------------------------------------ *
	 * Task detail drawer
	 * ------------------------------------------------------------------ */
	var drawerState = { tab: 'remarks' };
	function closeDrawer(skipNav) {
		var d = $('.drawer'), o = $('.drawer-overlay');
		if (d) d.remove();
		if (o) o.remove();
		if (!skipNav && /^#\/tasks\/\d+/.test(location.hash)) {
			history.replaceState(null, '', '#' + (S.baseRoute || '/dashboard'));
		}
	}
	function openTask(id, keepTab) {
		if (!keepTab) drawerState.tab = 'remarks';
		var existing = $('.drawer');
		var d = existing || el('<aside class="drawer" role="dialog" aria-modal="true" aria-label="Task details"></aside>');
		if (!existing) {
			var o = el('<div class="drawer-overlay"></div>');
			o.onclick = function () { closeDrawer(); };
			document.body.appendChild(o);
			document.body.appendChild(d);
			d.innerHTML = '<div class="drawer-h"><div class="top"><span class="muted">Loading…</span><span style="flex:1"></span><button class="btn btn-ghost btn-icon btn-sm" data-x aria-label="Close">' + I.x + '</button></div></div><div class="drawer-b">' + loading(6) + '</div>';
			d.querySelector('[data-x]').onclick = function () { closeDrawer(); };
		}
		return api('tasks/' + id).then(function (t) { if (d.isConnected) { renderDrawer(d, t); if (WD.onTaskDrawer) WD.onTaskDrawer(d, t); } }, function (e) {
			var body = d.querySelector('.drawer-b');
			if (!body) return;
			body.innerHTML = '';
			body.appendChild(errorBox(e, function () { return openTask(id, true); }));
		});
	}
	WD.openTask = openTask;

	function renderDrawer(d, t) {
		var proj = project(t.project_id), tm = team(t.team_id), cl = taskClient(t);
		var flags = [];
		if (t.is_overdue) flags.push('<span class="badge overdue">Overdue</span>');
		if (t.is_carried) flags.push('<span class="badge carried">Carried forward from ' + esc(fmtDate(t.scheduled_date)) + '</span>');
		if (t.parent_id) flags.push('<span class="badge followup">Follow-up</span>');
		var statusBtns = Object.keys(S.statuses).map(function (k) {
			return '<button class="s-' + k + (k === t.status ? ' on' : '') + '" data-st="' + k + '"' + (t.can_work ? '' : ' disabled') + '>' + esc(S.statuses[k]) + '</button>';
		}).join('');
		var due = t.due_date || t.due_time ? esc((t.due_date ? fmtDate(t.due_date, true) : '') + (t.due_time ? ' ' + fmtTime(t.due_time) : '')) : '<span class="muted">—</span>';
		d.innerHTML =
			'<div class="drawer-h"><div class="top"><span class="muted mono">#' + t.id + '</span>' + prioBadge(t.priority) + flags.join('') + '<span style="flex:1"></span>' +
			'<button class="btn btn-ghost btn-icon btn-sm" data-x aria-label="Close">' + I.x + '</button></div>' +
			'<h2>' + esc(t.title) + '</h2>' +
			(t.parent ? '<div class="small muted" style="margin-top:4px">Follow-up of <a href="#/tasks/' + t.parent.id + '">#' + t.parent.id + ' ' + esc(t.parent.title) + '</a></div>' : '') +
			'<div class="status-bar" style="margin-top:12px" aria-label="Status">' + statusBtns + '</div>' +
			'<div class="drawer-actions">' +
			'<button class="btn btn-sm" data-act="remark">' + I.msg + ' Add remark</button>' +
			'<button class="btn btn-sm" data-act="followup">' + I.fwd + ' Create follow-up</button>' +
			'<a class="btn btn-sm btn-wa" href="' + esc(whatsappUrl(t)) + '" target="_blank" rel="noopener noreferrer" title="Share on WhatsApp — you choose the chat and press send">' + I.wa + ' WhatsApp</a>' +
			(t.can_edit ? '<button class="btn btn-sm" data-act="edit">' + I.edit + ' Edit</button>' : '') +
				(t.can_pickup ? '<button class="btn btn-sm btn-primary" data-act="pickup" title="Take responsibility for this team task">' + I.check + ' Pick up task</button>' : '') +
				(t.can_member_handoff ? '<button class="btn btn-sm" data-act="member" title="Pass this task to another member of the same team">' + I.fwd + ' Hand off to member</button>' : '') +
				(t.can_handoff ? '<button class="btn btn-sm" data-act="handoff" title="Your team has finished its part — pass the task to another team">' + I.fwd + ' Team handoff</button>' : '') +
				(t.can_release ? '<button class="btn btn-sm btn-ghost" data-act="release" title="Put the task back in the team\'s shared pool">Release to pool</button>' : '') +
			(t.can_archive ? '<button class="btn btn-sm btn-ghost btn-danger" data-act="archive">' + I.trash + ' Archive</button>' : '') +
			'</div></div>' +
			'<div class="drawer-b">' +
			'<div class="meta-grid">' +
				'<div><div class="k">Assigned to</div><div class="v">' + (t.assignee_ids.length ? esc(assigneeNames(t)) : (tm ? 'Team pool: ' + esc(teamNames(taskTeamIds(t))) : '—')) + (t.claimed_by && t.claimed_at ? '<div class="small muted">Picked up by ' + esc(t.claimed_by) + ' · ' + esc(fmtDT(t.claimed_at)) + '</div>' : '') + '</div></div>' +
			'<div><div class="k">' + (taskTeamIds(t).length > 1 ? 'Teams' : 'Team') + '</div><div class="v">' + (taskTeamIds(t).length ? esc(teamNames(taskTeamIds(t))) : '<span class="muted">—</span>') +
			((t.past_team_ids || []).length ? '<div class="small muted">Handed off by: ' + esc(teamNames(t.past_team_ids)) + '</div>' : '') + '</div></div>' +
			'<div><div class="k">Client</div><div class="v">' + (cl ? '<a href="#/clients/' + cl.id + '">' + esc(cl.name) + '</a>' + (cl.phone ? ' · <a href="tel:' + esc(cl.phone.replace(/[^\d+]/g, '')) + '">' + esc(cl.phone) + '</a>' : '') : '<span class="muted">—</span>') + '</div></div>' +
			'<div><div class="k">Project</div><div class="v">' + (proj ? esc(proj.name) : '<span class="muted">—</span>') + '</div></div>' +
			(t.package ? '<div class="span-all"><div class="k">Package</div><div class="v">' + (t.package.package_name ? esc(t.package.package_name) + ' · ' : '') + esc(t.package.scope_label) + (t.package.service_name ? ': ' + esc(t.package.service_name) : '') +
				(t.package.usage && t.package.usage !== 'released' ? ' <span class="badge ' + (t.package.usage === 'consumed' ? 's-completed' : 's-in_process') + '">' + (t.package.usage === 'consumed' ? '1 ' + esc(t.package.unit) + ' used' : '1 ' + esc(t.package.unit) + ' reserved') + '</span>' : '') +
				(t.package.over_limit ? ' <span class="badge overdue">Beyond limit</span>' : '') + (t.package.note ? '<div class="small muted">' + esc(t.package.note) + '</div>' : '') + '</div></div>' : '') +
			'<div><div class="k">Work type</div><div class="v">' + workType(t.work_type) + '</div></div>' +
			'<div><div class="k">Scheduled</div><div class="v">' + esc(fmtDate(t.scheduled_date, true)) + '</div></div>' +
			'<div><div class="k">Due</div><div class="v">' + due + '</div></div>' +
			'<div><div class="k">Reminder</div><div class="v">' + (t.reminder_at ? esc(fmtDT(t.reminder_at)) : '<span class="muted">—</span>') + '</div></div>' +
			'<div><div class="k">Created</div><div class="v">' + esc(t.created_by_name) + ' · ' + esc(fmtDT(t.created_at)) + '</div></div>' +
			(t.completed_at ? '<div><div class="k">Completed</div><div class="v">' + esc(fmtDT(t.completed_at)) + '</div></div>' : '') +
			(t.tag_ids.length ? '<div><div class="k">Tags</div><div class="v">' + t.tag_ids.map(function (id) { var g = tag(id); return g ? '<span class="tag"><i style="background:' + esc(g.color) + '"></i>' + esc(g.name) + '</span> ' : ''; }).join('') + '</div></div>' : '') +
			'</div>' +
			(t.description ? '<div class="label">Description</div><div class="desc">' + esc(t.description) + '</div>' : '') +
				((t.handoffs || []).length ? '<div class="label" style="margin-top:16px">Handoff &amp; assignment history</div><ul class="timeline">' + t.handoffs.map(function (h) {
					var route = h.type === 'team' ? '<b>' + esc(h.from_team) + '</b> → <b>' + esc(h.to_team) + '</b>' : h.type === 'pickup' ? 'Team pool (' + esc(h.to_team) + ') → <b>' + esc(h.to_user) + '</b>' : h.type === 'release' ? '<b>' + esc(h.from_assignees.join(', ')) + '</b> → team pool (' + esc(h.to_team) + ')' : '<b>' + esc(h.from_user || 'Team pool') + '</b> → <b>' + esc(h.to_user) + '</b> · ' + esc(h.to_team);
					return '<li>' + avatar(h.handed_by, 'sm') + '<div class="body"><span class="badge">' + esc(h.type_label) + '</span> ' + route + ' <span class="when">' + esc(fmtDT(h.created_at)) + '</span>' +
						'<div class="small muted">By ' + esc(h.handed_by_name) + (h.type === 'team' ? ' · ' + esc(h.from_team) + ' left it as ' + esc(S.statuses[h.from_status] || h.from_status) + (h.from_completed_at ? ' (completed ' + esc(fmtDT(h.from_completed_at)) + ')' : '') + (h.to_assignees.length ? ' · now with ' + esc(h.to_assignees.join(', ')) : '') : ' · status ' + esc(S.statuses[h.from_status] || h.from_status)) + '</div>' +
						(h.note ? '<div class="remark-text">' + esc(h.note) + '</div>' : '') +
						(h.links || []).map(function (l) { return '<div class="small"><a href="' + esc(l.url) + '" target="_blank" rel="noopener noreferrer nofollow">' + esc(l.label || l.url) + '</a></div>'; }).join('') + '</div></li>';
				}).join('') + '</ul>' : '') +
			(t.links.length ? '<div class="label" style="margin-top:16px">Links</div><ul class="links">' + t.links.map(function (l) {
				return '<li><a href="' + esc(l.url) + '" target="_blank" rel="noopener noreferrer nofollow">' + I.link + '<span>' + (l.label ? '<b>' + esc(l.label) + '</b><br><small>' + esc(l.url) + '</small>' : esc(l.url)) + '</span></a></li>';
			}).join('') + '</ul>' : '') +
			'<div class="tabs" role="tablist">' +
			['remarks', 'followups', 'activity'].map(function (k) {
				var lbl = { remarks: 'Remarks (' + t.remarks.length + ')', followups: 'Follow-ups (' + t.followups.length + ')', activity: 'History' }[k];
				return '<button role="tab" data-tab="' + k + '" class="' + (drawerState.tab === k ? 'on' : '') + '">' + lbl + '</button>';
			}).join('') + '</div><div data-tabbody></div></div>';

		d.querySelector('[data-x]').onclick = function () { closeDrawer(); };
		// One delegated handler, replaced on every render (the drawer element is reused).
		d.onclick = function (e) {
			var b = e.target.closest('[data-st]');
			if (b) {
				if (b.disabled || b.classList.contains('on')) return;
				changeStatus(t.id, b.getAttribute('data-st')).then(function () { openTask(t.id, true); });
				return;
			}
			b = e.target.closest('[data-tab]');
			if (b) { drawerState.tab = b.getAttribute('data-tab'); renderDrawer(d, t); return; }
			b = e.target.closest('[data-act]');
			if (b) drawerAction(b.getAttribute('data-act'));
		};
		function drawerAction(a) {
			if (a === 'edit') openTaskForm('edit', t);
			if (a === 'followup') openTaskForm('followup', null, t);
			if (a === 'handoff') openHandoff(t);
			if (a === 'member') openMemberHandoff(t);
			if (a === 'pickup') WD.pickUp(t.id, function () { refresh(); openTask(t.id, true); });
			if (a === 'release') confirmBox('Release to the team pool?', 'The current assignee is removed and any member of the team can pick the task up. The history is kept.', 'Release').then(function (ok) {
				if (ok) api('tasks/' + t.id + '/release', { method: 'POST', body: {} }).then(function () { toast('Task released to the team pool'); refresh(); openTask(t.id, true); }, toastError);
			});
			if (a === 'remark') { drawerState.tab = 'remarks'; renderDrawer(d, t); setTimeout(function () { var ta = d.querySelector('[data-remark]'); if (ta) ta.focus(); }, 20); }
			if (a === 'archive') confirmBox('Archive task?', 'The task will be hidden from worksheets and lists. Its history is kept.', 'Archive', true).then(function (ok) {
				if (!ok) return;
				api('tasks/' + t.id, { method: 'DELETE' }).then(function () { toast('Task archived'); closeDrawer(); refresh(); }, toastError);
			});
		}

		var tb = d.querySelector('[data-tabbody]');
		if (drawerState.tab === 'remarks') {
			tb.innerHTML = '<form data-rform style="margin-bottom:12px"><textarea data-remark rows="2" placeholder="Add a remark… earlier remarks are kept" aria-label="New remark"></textarea><div style="display:flex;justify-content:flex-end;margin-top:8px"><button class="btn btn-primary btn-sm">Add remark</button></div></form>' +
				(t.remarks.length ? '<ul class="timeline">' + t.remarks.map(function (r) {
					return '<li>' + avatar(r.user_id, 'sm') + '<div class="body"><b>' + esc(r.user) + '</b> <span class="when">' + esc(fmtDT(r.created_at)) + '</span><div class="remark-text">' + esc(r.body) + '</div></div></li>';
				}).join('') + '</ul>' : emptyState('No remarks yet', 'Remarks keep a running log of updates on this task.', I.msg));
			tb.querySelector('[data-rform]').onsubmit = function (e) {
				e.preventDefault();
				var ta = tb.querySelector('[data-remark]'), btn = e.target.querySelector('button');
				if (!ta.value.trim()) { ta.focus(); return; }
				busy(btn, true, 'Adding…');
				api('tasks/' + t.id + '/remarks', { method: 'POST', body: { body: ta.value } }).then(function (nt) {
					toast('Remark added'); renderDrawer(d, nt); refresh();
				}, function (err) { busy(btn, false); toastError(err); });
			};
		} else if (drawerState.tab === 'followups') {
			tb.innerHTML = '<div style="margin-bottom:10px"><button class="btn btn-sm" data-act="followup">' + I.plus + ' Create follow-up</button></div><div data-fl></div>';
			if (t.followups.length) taskList(tb.querySelector('[data-fl]'), t.followups, { showDate: true });
			else tb.querySelector('[data-fl]').innerHTML = emptyState('No follow-ups', 'Create a separate linked task for the next step — e.g. a call next Monday.', I.fwd);
		} else {
			tb.innerHTML = t.activity.length ? '<ul class="timeline">' + t.activity.map(function (a) {
				return '<li>' + avatar(a.user_id, 'sm') + '<div class="body"><b>' + esc(a.user) + '</b> ' + esc(a.label) + ' <span class="when">' + esc(fmtDT(a.created_at)) + '</span>' + activityDetails(a) + '</div></li>';
			}).join('') + '</ul>' : emptyState('No history yet');
		}
	}

	/**
	 * WhatsApp share link (wa.me, free, no API). Opens WhatsApp / WhatsApp Web with the text
	 * pre-filled; the user picks the chat and sends. The task link requires sign-in and
	 * opens only for people allowed to see the task.
	 */
	function taskUrl(id) { return String(CFG.appUrl || location.origin + location.pathname).split('#')[0] + '#/tasks/' + id; }
	function whatsappText(t) {
		var due = t.due_date || t.due_time ? (t.due_date ? fmtDate(t.due_date, true) : 'Today') + (t.due_time ? ' ' + fmtTime(t.due_time) : '') : 'Not set';
		var details = String(t.description || '').replace(/\s+/g, ' ').trim();
		if (details.length > 280) details = details.slice(0, 277) + '…';
		var c = taskClient(t);
		return [
			'*Task:* ' + t.title,
			'*Task ID:* #' + t.id,
			'*Assigned To:* ' + (t.assignee_ids.length ? assigneeNames(t) : (team(t.team_id) ? team(t.team_id).name + ' (team)' : '—')),
			c ? '*Client:* ' + c.name : null,
			'*Scheduled:* ' + fmtDate(t.scheduled_date, true),
			'*Due Date:* ' + due,
			'*Priority:* ' + (S.priorities[t.priority] || t.priority),
			'*Status:* ' + (S.statuses[t.status] || t.status),
			details ? '*Task Details:* ' + details : null,
			'*Open Task:* ' + taskUrl(t.id)
		].filter(Boolean).join('\n');
	}
	function whatsappUrl(t) { return 'https://wa.me/?text=' + encodeURIComponent(whatsappText(t)); }
	WD.whatsappText = whatsappText;

	function activityDetails(a) {
		var d = a.details || {}, parts = [];
		Object.keys(d).forEach(function (k) {
			var v = d[k];
			if (k === 'title' && a.action === 'task_created') return;
			if (v && typeof v === 'object' && 'from' in v) {
				var f = Array.isArray(v.from) ? v.from.join(', ') : v.from, to = Array.isArray(v.to) ? v.to.join(', ') : v.to;
				if (k === 'priority') { f = S.priorities[f] || f; to = S.priorities[to] || to; }
				if (k === 'work_type') { f = S.work_types[f] || f; to = S.work_types[to] || to; }
				if (k === 'team_id') { f = team(f) ? team(f).name : (f || 'none'); to = team(to) ? team(to).name : (to || 'none'); k = 'team'; }
				if (k === 'project_id') { f = project(f) ? project(f).name : (f || 'none'); to = project(to) ? project(to).name : (to || 'none'); k = 'project'; }
				parts.push(esc(k.replace(/_/g, ' ')) + ': <s class="muted">' + esc(f || '—') + '</s> → <b>' + esc(to || '—') + '</b>');
			} else if (k === 'followup_id') {
				parts.push('<a href="#/tasks/' + esc(v) + '">#' + esc(v) + '</a>');
			} else if (typeof v !== 'object') {
				parts.push(esc(k === 'text' ? v : k.replace(/_/g, ' ') + ': ' + v));
			}
		});
		return parts.length ? '<div class="small muted" style="margin-top:2px">' + parts.join(' · ') + '</div>' : '';
	}
	WD.activityDetails = activityDetails;

	/* ------------------------------------------------------------------ *
	 * Boot data, theme, shell, router
	 * ------------------------------------------------------------------ */
	function applyBoot(b) {
		S.package_scopes = b.package_scopes || { included: 'Included service', retainer: 'Retainer scope', additional: 'Additional scope' };
		Object.keys(b).forEach(function (k) { S[k] = b[k]; });
		S.memberMap = {}; S.teamMap = {}; S.projectMap = {}; S.tagMap = {}; S.clientMap = {};
		(S.clients || []).forEach(function (c) { S.clientMap[c.id] = c; });
		S.members.forEach(function (m) { S.memberMap[m.id] = m; });
		S.teams.forEach(function (t) { S.teamMap[t.id] = t; });
		S.projects.forEach(function (p) { p.id = +p.id; S.projectMap[p.id] = p; });
		S.tags.forEach(function (g) { g.id = +g.id; S.tagMap[g.id] = g; });
	}
	function reloadBoot() { return api('bootstrap').then(function (b) { applyBoot(b); updateBadge(); }); }
	WD.reloadBoot = reloadBoot;

	function applyTheme(pref) {
		var dark = pref === 'dark' || (pref !== 'light' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
		document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
		var b = $('[data-theme-btn]');
		if (b) { b.innerHTML = dark ? I.sun : I.moon; b.title = dark ? 'Switch to light mode' : 'Switch to dark mode'; }
	}
	function toggleTheme() {
		var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
		S.user.theme = next;
		try { localStorage.setItem('ndwd-theme', next); } catch (e) { /* storage unavailable */ }
		applyTheme(next);
		api('me/preferences', { method: 'POST', body: { theme: next } }).catch(function () {});
	}

	var NAV = [
		{ group: 'Work' },
		{ path: '/dashboard', label: 'My Dashboard', icon: 'home' },
		{ path: '/work-overview', label: 'Work Overview', icon: 'chart' },
		{ path: '/team', label: 'Team Dashboard', icon: 'team', cap: 'ndwd_view_team' },
		{ path: '/worksheet', label: 'Daily Worksheet', icon: 'sheet' },
		{ path: '/tasks', label: 'Task Management', icon: 'list' },
		{ path: '/calendar', label: 'Calendar', icon: 'cal' },
		{ path: '/clients', label: 'Clients', icon: 'briefcase' },
		{ path: '/projects', label: 'Projects', icon: 'folder', any: ['ndwd_manage_projects', 'ndwd_manage_workspace'], prefix: true },
		{ path: '/enquiries', label: 'Enquiries', icon: 'list', cap: 'ndwd_handle_enquiries' },
		{ path: '/leave', label: 'Leave', icon: 'plane', pill: 'leave' },
		{ path: '/notifications', label: 'Notifications', icon: 'bell', pill: 'unread' },
		{ group: 'Insights' },
		{ path: '/attendance', label: 'Attendance', icon: 'clock' },
		{ path: '/reports', label: 'Reports', icon: 'chart' },
		{ path: '/archives', label: 'Monthly Archives', icon: 'archive' },
		{ group: 'Accounts', any: ['ndwd_manage_accounts'] },
		{ path: '/accounts', label: 'Accounts', icon: 'rupee', cap: 'ndwd_manage_accounts', prefix: true },
		{ group: 'Workspace', any: ['ndwd_manage_workspace', 'ndwd_view_consolidated'] },
		{ path: '/workspace', label: 'Workspace Management', icon: 'briefcase', any: ['ndwd_manage_workspace', 'ndwd_view_consolidated'], prefix: true },
		{ group: 'Admin', any: ['ndwd_manage_members', 'ndwd_manage_teams', 'ndwd_manage_projects', 'ndwd_manage_settings', 'ndwd_import'] },
		{ path: '/admin/members', label: 'Members & Roles', icon: 'users', cap: 'ndwd_manage_members' },
		{ path: '/admin/teams', label: 'Teams', icon: 'team', cap: 'ndwd_manage_teams' },
		{ path: '/admin/projects', label: 'Tags', icon: 'tag', cap: 'ndwd_manage_projects' },
		{ path: '/admin/import', label: 'Import CSV', icon: 'upload', cap: 'ndwd_import' },
		{ path: '/admin/settings', label: 'Settings', icon: 'cog', cap: 'ndwd_manage_settings' }
	];

	function renderShell() {
		var logos = CFG.logos || {};
		var nav = NAV.filter(function (n) {
			if (n.cap && !can(n.cap)) return false;
			if (n.any && !n.any.some(can)) return false;
			return true;
		}).map(function (n) {
			if (n.group) return '<div class="nav-group">' + esc(n.group) + '</div>';
			return '<a href="#' + n.path + '" data-nav="' + n.path + '"' + (n.prefix ? ' data-prefix' : '') + '>' + I[n.icon] + '<span>' + esc(n.label) + '</span>' + (n.pill === 'unread' ? '<span class="pill" data-unread-pill></span>' : n.pill === 'leave' ? '<span class="pill" data-leave-pill' + (S.leave_pending ? '' : ' style="display:none"') + ' title="Leave requests awaiting your review">' + (S.leave_pending || '') + '</span>' : '') + '</a>';
		}).join('');
		var root = $('#ndwd-app');
		root.innerHTML =
			'<div class="shell">' +
			'<aside class="sidebar" aria-label="Main navigation">' +
			'<a class="brand" href="#/dashboard"><img class="mark" src="' + esc(logos.mark) + '" alt=""><span><img class="word" src="' + esc(logos.wordmark) + '" alt="NeonDigital"><span class="sub">WorkDesk</span></span></a>' +
			'<nav class="nav">' + nav + '</nav>' +
			'<div class="side-foot"><button type="button" class="avatar-btn" data-profile title="My profile" aria-label="My profile">' + avatar(S.user.id) + '</button><div class="who"><b>' + esc(S.user.name) + '</b><span>' + esc(roleLabel(S.user.role)) + '</span></div>' +
			'<a class="btn btn-ghost btn-icon btn-sm" href="' + esc(S.logout_url) + '" title="Sign out" aria-label="Sign out">' + I.logout + '</a></div>' +
			'</aside>' +
			'<div class="main">' +
			'<header class="topbar">' +
			'<button class="btn btn-ghost btn-icon menu-btn" data-menu aria-label="Open menu">' + I.menu + '</button>' +
			'<div class="title"><h1 data-title></h1><div class="muted" data-subtitle></div></div>' +
			'<div class="spacer"></div>' +
			'<div class="search">' + I.search + '<input type="search" placeholder="Search tasks…" data-search aria-label="Search tasks (shortcut: /)"></div>' +
			'<button class="btn btn-primary btn-new" data-new title="New task (N)">' + I.plus + '<span class="lbl">New Task</span></button>' +
			'<div class="rel"><button class="btn btn-ghost btn-icon" data-bell aria-label="Notifications">' + I.bell + '<span class="count hidden" data-unread style="position:absolute;top:2px;right:2px"></span></button></div>' +
			'<button class="btn btn-ghost btn-icon" data-theme-btn aria-label="Toggle dark mode"></button>' +
			'</header>' +
			'<div data-banner></div>' +
			'<main class="content" data-view tabindex="-1"></main>' +
			'</div></div>';
		applyTheme(document.documentElement.getAttribute('data-theme'));
		root.querySelector('[data-theme-btn]').onclick = toggleTheme;
		root.querySelector('[data-profile]').onclick = function () { go('/profile'); };
		root.querySelector('[data-new]').onclick = function () { openTaskForm('create'); };
		root.querySelector('[data-menu]').onclick = function () { $('.shell').classList.toggle('nav-open'); };
		root.querySelector('.shell').addEventListener('click', function (e) {
			if (e.target.classList.contains('shell')) $('.shell').classList.remove('nav-open');
			if (e.target.closest('.nav a')) $('.shell').classList.remove('nav-open');
		});
		root.querySelector('[data-bell]').onclick = function (e) { e.stopPropagation(); toggleNotifPop(); };
		var search = root.querySelector('[data-search]');
		search.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { S.pendingSearch = search.value.trim(); go('/tasks'); if (location.hash === '#/tasks') route(); }
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === '/' && !/input|textarea|select/i.test(document.activeElement.tagName)) { e.preventDefault(); search.focus(); }
		});
		document.addEventListener('click', function (e) { var p = $('.pop'); if (p && !e.target.closest('.pop') && !e.target.closest('[data-bell]')) p.remove(); });
		renderBanner();
		updateBadge();
	}
	function roleLabel(r) {
		return { administrator: 'Site administrator', ndwd_admin: 'WorkDesk Admin', ndwd_member: 'Member' }[r] || 'Member';
	}
	WD.roleLabel = roleLabel;

	// Email-failure warnings stay dismissed (per user, per browser) until a newer failed log entry appears.
	function mailFailKey() { return 'ndwd-mail-fail-seen-' + (S.user ? S.user.id : 0); }
	function unseenMailFailure(st) {
		var f = st && st.failures;
		if (!f || !f.last_id) return null;
		var seen = 0;
		try { seen = +localStorage.getItem(mailFailKey()) || 0; } catch (e) { /* ignore */ }
		return f.last_id > seen ? f : null;
	}
	function dismissMailFailure(st) {
		if (!st || !st.failures) return;
		try { localStorage.setItem(mailFailKey(), String(st.failures.last_id)); } catch (e) { /* ignore */ }
		$$('[data-mail-fail]').forEach(function (x) { x.remove(); });
		if (S.email_status && S.email_status.failures && S.email_status.failures.last_id <= st.failures.last_id) renderBanner();
	}
	WD.unseenMailFailure = unseenMailFailure;
	WD.dismissMailFailure = dismissMailFailure;

	function renderBanner() {
		var b = $('[data-banner]');
		if (!b) return;
		b.innerHTML = '';
		if (!S.email_status || !can('ndwd_manage_settings')) return;
		var st = S.email_status, cfg = st.warnings || [], fail = unseenMailFailure(st);
		var sig = cfg.join('\n'), dismissed = false;
		try { dismissed = sessionStorage.getItem('ndwd-mail-warn') === sig; } catch (e) { /* ignore */ }
		// Setup warnings are hidden for the session once dismissed; an unseen failure always shows.
		if (!fail && (!cfg.length || dismissed)) return;
		var items = (fail || !dismissed ? cfg : []).concat(fail ? [fail.message] : []);
		var w = el('<div class="alert alert-warn banner"><div style="flex:1"><b>Email setup needs attention.</b> In-app notifications still work.<ul>' +
			items.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></div><a class="btn btn-sm" href="#/admin/settings">Open settings</a><button class="btn btn-ghost btn-sm btn-icon" aria-label="Dismiss" title="Dismiss">' + I.x + '</button></div>');
		w.querySelector('button').onclick = function () {
			try { sessionStorage.setItem('ndwd-mail-warn', sig); } catch (e) { /* ignore */ }
			w.remove();
			dismissMailFailure(st);
		};
		b.appendChild(w);
	}
	WD.renderBanner = renderBanner;

	function updateBadge() {
		var n = S.unread || 0;
		$$('[data-unread]').forEach(function (b) { b.textContent = n > 99 ? '99+' : n; b.classList.toggle('hidden', !n); });
		$$('[data-unread-pill]').forEach(function (b) { b.textContent = n || ''; b.style.display = n ? '' : 'none'; });
		document.title = (n ? '(' + n + ') ' : '') + 'NeonDigital WorkDesk';
		$$('[data-leave-pill]').forEach(function (b) { var k = S.leave_pending || 0; b.textContent = k || ''; b.style.display = k ? '' : 'none'; });
	}
	WD.updateBadge = updateBadge;

	function pollUnread() {
		api('notifications', { params: { limit: 5, unread: 1 } }).then(function (r) {
			if (r.unread > (S.unread || 0) && S.unreadSeen !== undefined) toast(r.items[0] ? r.items[0].message : 'New notification');
			S.unread = r.unread; S.unreadSeen = true; updateBadge();
		}).catch(function () {});
	}

	function notifItem(n) {
		return '<div class="notif' + (n.is_read ? '' : ' unread') + '" data-n="' + n.id + '" data-link="' + esc(n.link) + '"><span class="dot"></span><div class="notif-b"><div>' + esc(n.message) + '</div><div class="when">' + esc(fmtDT(n.created_at)) + '</div></div>' +
			'<button type="button" class="btn btn-ghost btn-icon btn-sm notif-del" data-del title="Remove" aria-label="Remove notification">' + I.x + '</button></div>';
	}
	WD.notifItem = notifItem;
	// Shows the list's empty state once its last item has gone (read in an unread-only list, or removed).
	function notifEmpty(list) {
		if (!list.querySelector('[data-n]')) list.innerHTML = list.hasAttribute('data-unread-only') ? emptyState('You are all caught up', '', I.bell) : emptyState('No notifications yet', '', I.bell);
	}
	// Opening a notification marks it read at once (badge first, server second); an unread-only list drops it.
	function openNotif(node) {
		var id = node.getAttribute('data-n'), link = node.getAttribute('data-link'), list = node.parentNode;
		if (node.classList.contains('unread')) {
			node.classList.remove('unread');
			S.unread = Math.max(0, (S.unread || 0) - 1); updateBadge();
			api('notifications/read', { method: 'POST', body: { ids: [id] } }).then(function (r) { S.unread = r.unread; updateBadge(); }).catch(function () {});
			if (list && list.hasAttribute('data-unread-only')) { node.remove(); notifEmpty(list); }
		}
		var p = $('.pop'); if (p) p.remove();
		if (link) location.hash = link.replace(/^#/, '');
	}
	WD.openNotif = openNotif;
	function removeNotif(node) {
		var list = node.parentNode;
		node.style.opacity = '.5';
		api('notifications/delete', { method: 'POST', body: { ids: [node.getAttribute('data-n')] } }).then(function (r) {
			node.remove(); if (list) notifEmpty(list);
			S.unread = r.unread; updateBadge();
			toast('Notification removed');
		}, function (e) { node.style.opacity = ''; toastError(e); });
	}
	WD.removeNotif = removeNotif;
	function bindNotifList(list) {
		on(list, 'click', '[data-n]', function (e, n) { if (e.target.closest('[data-del]')) { e.stopPropagation(); removeNotif(n); return; } openNotif(n); });
	}
	WD.bindNotifList = bindNotifList;
	function toggleNotifPop() {
		var existing = $('.pop');
		if (existing) { existing.remove(); return; }
		var pop = el('<div class="pop" role="dialog" aria-label="Unread notifications"><div class="pop-h"><b>Unread notifications</b><button class="btn btn-ghost btn-sm" data-all>Mark all as read</button></div><div class="pop-list" data-unread-only>' + loading(3) + '</div><div style="padding:8px;text-align:center;border-top:1px solid var(--border)"><a href="#/notifications">View all notifications</a></div></div>');
		$('[data-bell]').parentNode.appendChild(pop);
		var list = pop.querySelector('.pop-list');
		function loadPop() {
			return api('notifications', { params: { limit: 12, unread: 1 } }).then(function (r) {
				S.unread = r.unread; updateBadge();
				list.innerHTML = r.items.map(notifItem).join(''); notifEmpty(list);
			}, function (e) { list.innerHTML = ''; list.appendChild(errorBox(e, loadPop)); });
		}
		loadPop();
		bindNotifList(list);
		pop.querySelector('[data-all]').onclick = function () {
			S.unread = 0; updateBadge(); list.innerHTML = ''; notifEmpty(list);
			api('notifications/read', { method: 'POST', body: { all: true } }).then(function (r) { S.unread = r.unread; updateBadge(); }, function (e) { toastError(e); loadPop(); });
		};
	}

	/* Router */
	function go(path) { location.hash = path; }
	WD.go = go;
	var liveSub = null;
	function setTitle(t, sub) {
		var h = $('[data-title]'), s = $('[data-subtitle]');
		liveSub = typeof sub === 'function' ? sub : null;
		if (h) h.textContent = t;
		if (s) s.textContent = (liveSub ? liveSub() : sub) || '';
	}
	WD.setTitle = setTitle;
	/** Current date and time in the app timezone (Asia/Kolkata), e.g. "Thursday, 29 October 2026 · 4:35 PM". */
	WD.nowLabel = function () {
		var d = new Date(), tz = CFG.timezone || 'Asia/Kolkata';
		try {
			return d.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: tz }) + ' · ' + d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: tz }).toUpperCase();
		} catch (e) { return d.toLocaleString(); }
	};
	function tickTitle() {
		var s = $('[data-subtitle]');
		if (liveSub && s) s.textContent = liveSub();
	}

	var routes = [
		[/^\/dashboard$/, 'dashboard'], [/^\/work-overview$/, 'workOverview'], [/^\/profile$/, 'profile'], [/^\/team$/, 'team'], [/^\/worksheet$/, 'worksheet'], [/^\/tasks$/, 'tasks'], [/^\/tasks\/(requests|deliverables)$/, 'taskClientWork'],
		[/^\/calendar$/, 'calendar'], [/^\/clients$/, 'clients'], [/^\/leave$/, 'leave'], [/^\/notifications$/, 'notifications'], [/^\/attendance$/, 'attendance'],
		[/^\/reports$/, 'reports'], [/^\/archives$/, 'archives'], [/^\/admin\/members$/, 'members'], [/^\/admin\/teams$/, 'teams'],
		[/^\/admin\/projects$/, 'projects'], [/^\/admin\/import$/, 'import'], [/^\/admin\/settings$/, 'settings'],
		[/^\/clients\/(\d+)$/, 'client'],
		[/^\/projects$/, 'projectHub'], [/^\/projects\/(\d+)$/, 'projectPage'],
		[/^\/agency-calendar$/, 'agencyCalendar'],
		[/^\/enquiries$/, 'enquiries'],
		[/^\/admin\/integrations$/, 'integrations'],
		[/^\/workspace(?:\/([a-z-]+))?(?:\/([a-z-]+|\d+))?$/, 'workspaceAdmin'],
		[/^\/accounts(?:\/(overview|invoices|income|expenses|passbook|reports|packages|settings))?$/, 'accounts'],
		[/^\/accounts\/invoices\/new$/, 'invoiceNew'], [/^\/accounts\/invoices\/(\d+)$/, 'invoice']
	];
	/**
	 * Every render gets its own root element inside [data-view]. A request started by a
	 * view that the user has already left resolves into its old, detached root, so it can
	 * neither fail with "Cannot set properties of null" (its elements still exist) nor
	 * overwrite the page now on screen.
	 */
	function renderView(path) {
		var view = null, params = null;
		routes.forEach(function (r) { var m = !view && path.match(r[0]); if (m) { view = r[1]; params = m; } });
		if (!view || !WD.views[view]) { path = '/dashboard'; view = 'dashboard'; params = null; }
		S.baseRoute = path;
		S.routeParams = params;
		$$('[data-nav]').forEach(function (a) {
			var p = a.getAttribute('data-nav');
			a.classList.toggle('on', p === path || (a.hasAttribute('data-prefix') && path.indexOf(p + '/') === 0));
		});
		var host = $('[data-view]');
		var c = document.createElement('div');
		c.className = 'view-root';
		host.innerHTML = '';
		host.appendChild(c);
		S.viewRoot = c;
		S.currentView = view;
		function fail(e) {
			if (!c.isConnected) return;
			c.innerHTML = '';
			c.appendChild(errorBox(e, function () { renderView(path); return Promise.resolve(); }));
		}
		try {
			var r = WD.views[view](c);
			if (r && r.catch) r.catch(fail);
		} catch (e) {
			fail(e);
		}
	}
	WD.isCurrent = function (c) { return c && c.isConnected; };
	function route() {
		var hash = location.hash.replace(/^#/, '') || '/dashboard';
		var m = hash.match(/^\/tasks\/(\d+)$/);
		if (m) {
			if (!S.currentView) renderView(S.baseRoute || '/dashboard');
			openTask(m[1]);
			return;
		}
		closeDrawer(true);
		renderView(hash.split('?')[0]);
		window.scrollTo(0, 0);
	}
	/** Re-render the current view in place (after a change). */
	function refresh() {
		if (!S.currentView || !S.viewRoot) return;
		var c = S.viewRoot;
		var y = window.scrollY;
		if (WD.views[S.currentView].refresh) { WD.views[S.currentView].refresh(c); return; }
		var r = WD.views[S.currentView](c, { silent: true });
		if (r && r.then) r.then(function () { window.scrollTo(0, y); }, function (e) {
			if (c.isConnected) toast('Could not refresh: ' + (e.message || e), 'error', { label: 'Retry', fn: refresh });
		});
	}
	WD.refresh = refresh;

	/*
	 * Midnight rollover in Asia/Kolkata: the worksheet date follows the clock. The sign-in
	 * session carries on; the new date's attendance is recorded on the member's next real
	 * click or keypress (a tab left open overnight does not mark anyone present).
	 */
	var presencePending = false;
	function markPresence() {
		if (!presencePending) return;
		presencePending = false;
		api('attendance/presence', { method: 'POST', body: {} }).catch(function () { presencePending = true; });
	}
	function checkDate() {
		var t = istToday();
		if (S.today && t !== S.today) {
			var prev = S.today;
			S.today = t;
			reloadBoot().then(function () {
				S.today = t;
				presencePending = true;
				toast('A new day has started — your worksheet now shows ' + fmtDate(t, true) + '.');
				refresh();
			}).catch(function (e) {
				if (e && e.status !== 401 && e.code !== 'rest_cookie_invalid_nonce') S.today = prev; // Retry on the next check.
			});
		}
	}

	function boot() {
		if (!CFG.root || WD.booted) return;
		WD.booted = true;
		api('bootstrap').then(function (b) {
			applyBoot(b);
			initDropdowns();
			if (S.user.theme === 'light' || S.user.theme === 'dark') { try { localStorage.setItem('ndwd-theme', S.user.theme); } catch (e) { /* ignore */ } }
			applyTheme(S.user.theme === 'system' ? (function () { try { return localStorage.getItem('ndwd-theme') || 'system'; } catch (e) { return 'system'; } })() : S.user.theme);
			renderShell();
			try {
				var after = sessionStorage.getItem('ndwd-after-login');
				sessionStorage.removeItem('ndwd-after-login');
				if (after && (!location.hash || location.hash === '#/dashboard')) history.replaceState(null, '', after);
			} catch (e) { /* storage unavailable */ }
			window.addEventListener('hashchange', route);
			route();
			setInterval(pollUnread, 60000);
			setInterval(checkDate, 30000);
			setInterval(tickTitle, 15000);
			document.addEventListener('visibilitychange', function () { if (!document.hidden) { checkDate(); pollUnread(); } });
			['pointerdown', 'keydown'].forEach(function (ev) { document.addEventListener(ev, markPresence, true); });
		}, function (e) {
			var root = $('#ndwd-app');
			var signedOut = e.status === 401 || e.code === 'rest_cookie_invalid_nonce';
			// A genuinely ended session goes back to the sign-in screen instead of a dead end.
			root.innerHTML = '<main class="auth"><div class="auth-card"><h1>' + (signedOut ? 'Please sign in again' : 'WorkDesk could not start') + '</h1><p class="muted" style="text-align:center">' + esc(signedOut ? 'Your sign-in session has ended.' : e.message) + '</p><button class="btn btn-primary" style="width:100%" onclick="location.reload()">' + (signedOut ? 'Sign in' : 'Reload') + '</button></div></main>';
		});
	}
	WD.boot = boot;
	WD.closeDrawer = closeDrawer;
	document.addEventListener('DOMContentLoaded', function () { setTimeout(boot, 0); });
	if (document.readyState !== 'loading') setTimeout(boot, 0);
})();
