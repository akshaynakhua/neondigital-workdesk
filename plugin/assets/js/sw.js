/* NeonDigital WorkDesk service worker: receives push messages and opens the right page on click.
 * It caches nothing, so no task or client data is ever stored on the device by this worker. */
'use strict';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (e) {
	var d = {};
	try { d = e.data ? e.data.json() : {}; } catch (err) { d = {}; }
	// A notification is shown for every message: iOS withdraws push from sites that stay silent.
	e.waitUntil(self.registration.showNotification(d.title || 'WorkDesk', {
		body: d.body || 'There is an update for you in WorkDesk.',
		icon: d.icon,
		badge: d.badge,
		tag: d.tag,
		data: { url: d.url }
	}));
});

self.addEventListener('notificationclick', function (e) {
	e.notification.close();
	var origin = self.location.origin, url = self.registration.scope;
	try {
		var u = new URL(e.notification.data.url, origin);
		if (e.notification.data.url && u.origin === origin) { url = u.href; } // Only ever opens a page of this site.
	} catch (err) { /* Keeps the site's start page. */ }
	var base = url.split('#')[0];
	e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
		for (var i = 0; i < list.length; i++) {
			if (list[i].url.split('#')[0].split('?')[0] === base && 'focus' in list[i]) {
				return list[i].focus().then(function (w) {
					// A signed-out tab lands on sign-in, which keeps the destination for after signing in.
					if (w.navigate) { return w.navigate(url).catch(function () { w.postMessage({ type: 'ndwd-open', url: url }); }); }
					w.postMessage({ type: 'ndwd-open', url: url });
				});
			}
		}
		return self.clients.openWindow(url);
	}));
});

/* The browser replaced the subscription with no page open: hand the server the new one, proving
 * ownership with the old endpoint and auth secret. */
self.addEventListener('pushsubscriptionchange', function (e) {
	var old = e.oldSubscription, json = old && old.toJSON();
	if (!json || !json.keys || !json.keys.auth) { return; }
	e.waitUntil((e.newSubscription ? Promise.resolve(e.newSubscription) : self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: old.options.applicationServerKey })).then(function (sub) {
		var n = sub.toJSON();
		return fetch(new URL('?rest_route=/ndwd/v1/push/rotate', self.registration.scope).href, {
			method: 'POST',
			credentials: 'omit',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ old_endpoint: json.endpoint, old_auth: json.keys.auth, endpoint: n.endpoint, keys: n.keys })
		});
	}));
});
