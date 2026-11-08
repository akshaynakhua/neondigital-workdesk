/* NeonDigital WorkDesk — push notifications: service worker registration and the settings shown in My Profile.
 * The browser's permission prompt only ever appears from a click on Enable Notifications. */
(function () {
	'use strict';

	var WD = window.WD, I = WD.I, api = WD.api, ui = WD.ui, fmt = WD.fmt, esc = WD.u.esc;
	var cfg = window.NDWD.push || {};
	var installEvent = null;

	/** What this browser can do. Features are tested directly, never guessed from the device name. */
	function support() {
		if (!window.isSecureContext) { return 'insecure'; }
		if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { return 'unsupported'; }
		return 'ok';
	}
	function standalone() {
		return (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
	}
	function register() {
		return navigator.serviceWorker.register(cfg.swUrl, { scope: cfg.scope, updateViaCache: 'none' });
	}
	function registration() {
		return register().then(function () { return navigator.serviceWorker.ready; });
	}
	function currentSub() {
		return navigator.serviceWorker.getRegistration(cfg.scope).then(function (r) { return r ? r.pushManager.getSubscription() : null; });
	}
	function keyBytes(b64) {
		var s = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((b64.length + 3) % 4));
		var a = new Uint8Array(s.length);
		for (var i = 0; i < s.length; i++) { a[i] = s.charCodeAt(i); }
		return a;
	}
	function sameKey(sub, bytes) {
		var k = sub.options && sub.options.applicationServerKey;
		if (!k) { return true; }
		var a = new Uint8Array(k);
		return a.length === bytes.length && a.every(function (v, i) { return v === bytes[i]; });
	}
	/** A name for the device list only; nothing depends on it. */
	function deviceLabel() {
		var ua = navigator.userAgent;
		var os = /Android/.test(ua) ? 'Android' : /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) ? 'iPad' : /Windows/.test(ua) ? 'Windows' : /Mac OS/.test(ua) ? 'Mac' : /Linux/.test(ua) ? 'Linux' : 'Device';
		var br = /SamsungBrowser/.test(ua) ? 'Samsung Internet' : /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
		return os + ' · ' + br + (standalone() ? ' (installed)' : '');
	}

	/* Runs on every app start, without any prompt. */
	if (support() === 'ok' && cfg.swUrl) {
		register().then(function () {
			return currentSub();
		}).then(function (sub) {
			if (!sub) { return; }
			var j = sub.toJSON();
			return api('me/push/check', { method: 'POST', body: { endpoint: j.endpoint, auth: j.keys && j.keys.auth } }).then(function (r) {
				// Removed from another device, or this device is now someone else's: stop here too.
				if (r.status !== 'mine') { return sub.unsubscribe(); }
			});
		}).catch(function () { /* Push is optional; the app works without it. */ });
		navigator.serviceWorker.addEventListener('message', function (e) {
			if (e.data && e.data.type === 'ndwd-open' && typeof e.data.url === 'string') {
				var i = e.data.url.indexOf('#');
				if (i >= 0) { location.hash = e.data.url.slice(i); }
			}
		});
	}
	window.addEventListener('beforeinstallprompt', function (e) {
		e.preventDefault();
		installEvent = e;
		document.querySelectorAll('[data-push-install]').forEach(function (b) { b.hidden = false; });
	});

	function guide() {
		return '<details style="margin-top:12px"><summary style="cursor:pointer;font-weight:600">How to install WorkDesk on your phone</summary><div class="small" style="margin-top:8px">' +
			'<button type="button" class="btn btn-sm" data-push-install' + (installEvent ? '' : ' hidden') + ' style="margin-bottom:8px">Install WorkDesk</button>' +
			'<p style="margin:0 0 4px"><b>Android (Chrome, Edge, Samsung Internet, Firefox)</b></p><ol style="margin:0 0 12px;padding-left:20px"><li>Open WorkDesk in the browser and sign in.</li><li>Open the browser menu and choose <b>Install app</b> or <b>Add to Home screen</b>.</li><li>Open WorkDesk from the new icon, come to My Profile and tap <b>Enable Notifications</b>.</li></ol>' +
			'<p style="margin:0 0 4px"><b>iPhone and iPad (iOS / iPadOS 16.4 or newer)</b></p><ol style="margin:0;padding-left:20px"><li>Open WorkDesk in Safari.</li><li>Tap <b>Share</b>, then <b>Add to Home Screen</b>, then <b>Add</b>.</li><li>Open WorkDesk from the Home Screen icon and sign in there. Push does not work from a normal Safari tab.</li><li>Come to My Profile and tap <b>Enable Notifications</b>, then <b>Allow</b>.</li></ol>' +
			'</div></details>';
	}

	/** The block about this browser: what it can do, and the one action available now. */
	function deviceBlock(st, sub) {
		var sup = support();
		if (sup === 'insecure') { return '<div class="alert alert-info small"><b>This page is not on a secure connection.</b> Push notifications need HTTPS. Open WorkDesk through its https:// address.</div>'; }
		if (sup === 'unsupported') {
			return '<div class="alert alert-info small"><b>This browser does not offer push notifications here.</b> ' + (standalone() ? 'Update your device or browser to a newer version.' : 'On iPhone or iPad, add WorkDesk to your Home Screen and open it from there (iOS 16.4 or newer). On other devices, try a current version of Chrome, Edge, Firefox or Samsung Internet.') + ' In-app and email notifications keep working.</div>';
		}
		if (!st.configured) { return '<div class="alert alert-info small">Push is not configured on this server yet. Ask your administrator.</div>'; }
		if (sub) { return '<p class="small" style="margin:0 0 8px"><span class="badge s-completed">On</span> This device receives push notifications.</p><button type="button" class="btn btn-sm" data-push-off>Turn off on this device</button>'; }
		if (Notification.permission === 'denied') { return '<div class="alert alert-info small"><b>Notifications are blocked for WorkDesk on this device.</b> Allow them in the browser or phone settings for this site, then reload this page.</div>'; }
		return '<p class="small muted" style="margin:0 0 8px">' + (Notification.permission === 'granted' ? 'Notifications are allowed, but this device is not registered.' : 'Your browser will ask for permission after you click the button.') + '</p><button type="button" class="btn btn-primary" data-push-on>' + I.bell + ' Enable Notifications</button>';
	}

	function paint(box, st, sub, currentId) {
		box.innerHTML = '<section class="card"><div class="card-h"><h2>Push notifications</h2></div><div class="card-b">' +
			'<p class="small muted" style="margin-top:0">Get WorkDesk notifications on your phone, even when WorkDesk is closed. Each device is registered separately.</p>' +
			'<div data-push-device>' + deviceBlock(st, sub) + '</div>' + guide() +
			'<hr style="border:0;border-top:1px solid var(--border);margin:16px 0">' +
			'<label class="check" style="display:flex;margin-bottom:8px"><input type="checkbox" data-push-enabled' + (st.enabled ? ' checked' : '') + '> <b>Send push notifications to my devices</b></label>' +
			'<label class="check small" style="display:flex;margin-bottom:12px"><input type="checkbox" data-push-details' + (st.show_details ? ' checked' : '') + '> Show task titles in notifications. Leave this off to keep task and client names off your lock screen.</label>' +
			'<div class="small muted" style="margin-bottom:4px">Notify me about</div><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:4px 16px">' +
			st.categories.map(function (c) { return '<label class="check small"><input type="checkbox" data-push-cat="' + esc(c.key) + '"' + (st.prefs[c.key] ? ' checked' : '') + '> ' + esc(c.label) + '</label>'; }).join('') + '</div>' +
			'<hr style="border:0;border-top:1px solid var(--border);margin:16px 0">' +
			'<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px"><b class="small">Registered devices</b><span class="spacer" style="flex:1"></span>' + (st.devices.length && st.configured ? '<button type="button" class="btn btn-sm" data-push-test>Send test notification</button>' : '') + '</div>' +
			(st.devices.length ? st.devices.map(function (d) {
				return '<div style="display:flex;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--border);flex-wrap:wrap"><div style="flex:1;min-width:180px"><b class="small">' + esc(d.label) + '</b>' + (d.id === currentId ? ' <span class="badge">This device</span>' : '') + '<div class="small muted">Added ' + esc(fmt.dt(d.created_at)) + (d.last_success_at ? ' · last delivered ' + esc(fmt.dt(d.last_success_at)) : '') + '</div></div><button type="button" class="btn btn-sm" data-push-rm="' + d.id + '">Remove</button></div>';
			}).join('') : '<p class="small muted" style="margin:0">No devices yet.</p>') +
			'</div></section>';
		wire(box, st, sub, currentId);
	}

	function wire(box, st, sub, currentId) {
		var repaint = function (ns, s, id) { paint(box, ns, s, id); };
		var save = function (body) {
			api('me/push/settings', { method: 'POST', body: body }).then(function (ns) { st = ns; WD.toast('Saved'); }, WD.toastError);
		};
		var q = function (sel) { return box.querySelector(sel); };
		var inst = q('[data-push-install]');
		if (inst) { inst.onclick = function () { if (installEvent) { installEvent.prompt(); installEvent = null; inst.hidden = true; } }; }
		q('[data-push-enabled]').onchange = function (e) { save({ enabled: e.target.checked }); };
		q('[data-push-details]').onchange = function (e) { save({ show_details: e.target.checked }); };
		box.querySelectorAll('[data-push-cat]').forEach(function (c) {
			c.onchange = function () { var p = {}; p[c.getAttribute('data-push-cat')] = c.checked; save({ prefs: p }); };
		});
		var on = q('[data-push-on]');
		if (on) {
			on.onclick = function () {
				WD.busy(on, true);
				// Asked first and directly from the click, which iOS requires.
				Notification.requestPermission().then(function (perm) {
					if (perm !== 'granted') { repaint(st, null, null); return null; }
					var key = keyBytes(st.public_key);
					return registration().then(function (reg) {
						return reg.pushManager.getSubscription().then(function (old) {
							if (old && sameKey(old, key)) { return old; }
							return (old ? old.unsubscribe() : Promise.resolve()).then(function () {
								return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
							});
						});
					}).then(function (s) {
						var j = s.toJSON();
						return api('me/push/subscribe', { method: 'POST', body: { endpoint: j.endpoint, keys: j.keys, label: deviceLabel() } }).then(function (ns) {
							WD.toast('Notifications enabled on this device');
							repaint(ns, s, ns.device_id);
						});
					});
				}).catch(function (er) { WD.busy(on, false); WD.toastError(er); });
			};
		}
		var off = q('[data-push-off]');
		if (off) {
			off.onclick = function () {
				WD.busy(off, true);
				var rm = currentId ? api('me/push/devices/' + currentId, { method: 'DELETE' }) : api('me/push');
				rm.then(function (ns) {
					return sub.unsubscribe().then(function () { WD.toast('Turned off on this device'); repaint(ns, null, null); });
				}).catch(function (er) { WD.busy(off, false); WD.toastError(er); });
			};
		}
		box.querySelectorAll('[data-push-rm]').forEach(function (b) {
			b.onclick = function () {
				var id = +b.getAttribute('data-push-rm');
				WD.busy(b, true);
				api('me/push/devices/' + id, { method: 'DELETE' }).then(function (ns) {
					if (id === currentId && sub) { return sub.unsubscribe().then(function () { WD.toast('Device removed'); repaint(ns, null, null); }); }
					WD.toast('Device removed');
					repaint(ns, sub, currentId);
				}).catch(function (er) { WD.busy(b, false); WD.toastError(er); });
			};
		});
		var test = q('[data-push-test]');
		if (test) {
			test.onclick = function () {
				WD.busy(test, true);
				api('me/push/test', { method: 'POST', body: {} }).then(function (r) {
					WD.toast(r.sent + ' sent' + (r.failed ? ', ' + r.failed + ' failed' : '') + (r.removed ? ', ' + r.removed + ' expired device removed' : ''));
					repaint(r, sub, currentId);
				}, function (er) { WD.busy(test, false); WD.toastError(er); });
			};
		}
	}

	WD.pushSettings = function (box) {
		box.innerHTML = ui.loading(2);
		var find = support() === 'ok' && cfg.swUrl ? register().then(currentSub) : Promise.resolve(null);
		return Promise.all([api('me/push'), find.catch(function () { return null; })]).then(function (a) {
			var st = a[0], sub = a[1];
			if (!sub) { paint(box, st, null, null); return; }
			var j = sub.toJSON();
			return api('me/push/check', { method: 'POST', body: { endpoint: j.endpoint, auth: j.keys && j.keys.auth } }).then(function (r) {
				if (r.status === 'mine') { paint(box, st, sub, r.device_id); return; }
				return sub.unsubscribe().then(function () { paint(box, st, null, null); });
			});
		}).catch(function (e) { box.innerHTML = ''; box.appendChild(ui.errorBox(e, function () { WD.pushSettings(box); })); });
	};
})();
