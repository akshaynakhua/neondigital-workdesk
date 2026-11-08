/* NeonDigital WorkDesk — Workspace Management, the agency view of the Calendar and the operations panel on My Dashboard. */
(function () {
	'use strict';

	var WD = window.WD, S = WD.S, api = WD.api, V = WD.views;
	var esc = WD.u.esc, can = WD.u.can, ui = WD.ui, fmt = WD.fmt, toast = WD.toast || function () {};
	var modal = WD.modal;
	var P = WD.wsaPages = {};

	/* The left menu. Work Requests and Deliverables now live in Task Management. */
	var NAV = [
		['workspaces', 'All Workspaces', 'ndwd_manage_workspace'],
		['clients', 'Workspace Clients', 'ndwd_manage_workspace'],
		['connect', 'Invite a Client', 'ndwd_manage_workspace'],
		['packages', 'Packages & Services', 'ndwd_manage_workspace'],
		['entitlements', 'Feature Entitlements', 'ndwd_manage_workspace'],
		['subscriptions', 'Workspace Subscriptions', 'ndwd_manage_workspace'],
		['leads', 'Consolidated Leads', 'ndwd_view_consolidated'],
		['bookings', 'Consolidated Bookings', 'ndwd_view_consolidated'],
		['logs', 'API & Sync Logs', 'ndwd_manage_workspace']
	];
	var LOG_TABS = [['api', 'API & Sync'], ['webhooks', 'Webhooks'], ['audit', 'Data Access & Audit'], ['backups', 'Backup Logs']];
	/* Old addresses still land in the right place. */
	var MOVED = { 'api-logs': ['logs', 'api'], webhooks: ['logs', 'webhooks'], audit: ['logs', 'audit'], backups: ['logs', 'backups'] };

	var STATE = { active: 'Active', renewal_due: 'Renewal due', grace: 'Grace period', locked: 'Locked', scheduled: 'Scheduled', disabled: 'Off' };

	function badge(text, tone) {
		return '<span class="wsa-badge wsa-' + esc(tone || 'muted') + '">' + esc(text) + '</span>';
	}
	function stateBadge(s) {
		var t = { active: 'ok', renewal_due: 'warn', grace: 'warn', locked: 'bad', scheduled: 'info', disabled: 'muted' }[s];
		return badge(STATE[s] || s, t);
	}
	function statusBadge(s, label) {
		var t = { submitted: 'info', in_review: 'info', approved: 'ok', in_progress: 'info', completed: 'ok', rejected: 'bad', cancelled: 'muted', pending_approval: 'warn', revision_requested: 'warn', active: 'ok', invited: 'info', suspended: 'bad', pending: 'warn', delivered: 'ok', dead: 'bad' }[s];
		return badge(label || String(s).replace(/_/g, ' '), t);
	}
	function dt(v) { return v ? esc(fmt.dt ? fmt.dt(v) : v) : '<span class="muted">—</span>'; }
	function day(v) { return v ? esc(fmt.date(v, true)) : '<span class="muted">—</span>'; }
	function err(e) { toast(e && e.message ? e.message : String(e), 'error'); }
	function table(cols, rows, empty) {
		if (!rows.length) return ui.emptyState(empty || 'Nothing here yet', '', WD.I.list);
		return '<div class="table-wrap"><table class="tbl stack"><thead><tr>' + cols.map(function (c) { return '<th>' + esc(c[0]) + '</th>'; }).join('') + '</tr></thead><tbody>' +
			rows.map(function (r) {
				return '<tr>' + cols.map(function (c) { return '<td data-th="' + esc(c[0]) + '">' + c[1](r) + '</td>'; }).join('') + '</tr>';
			}).join('') + '</tbody></table></div>';
	}
	function card(title, body, tools) {
		return '<section class="card"><div class="card-h"><h2>' + esc(title) + '</h2><span class="spacer"></span>' + (tools || '') + '</div><div class="card-b">' + body + '</div></section>';
	}
	function field(label, html, hint, cls) {
		return '<label class="field' + (cls ? ' ' + cls : '') + '"><span>' + esc(label) + (hint ? ' <span class="hint">' + esc(hint) + '</span>' : '') + '</span>' + html + '</label>';
	}
	function options(list, cur, empty) {
		return (empty !== undefined ? '<option value="">' + esc(empty) + '</option>' : '') + list.map(function (o) {
			return '<option value="' + esc(o.id) + '"' + (String(o.id) === String(cur || '') ? ' selected' : '') + '>' + esc(o.name) + '</option>';
		}).join('');
	}
	function people() { return (S.members || S.users || []).map(function (m) { return { id: m.id, name: m.name }; }); }
	function teams() { return (S.teams || []).map(function (t) { return { id: t.id, name: t.name }; }); }
	function clients() { return (S.clients || []).map(function (t) { return { id: t.id, name: t.name }; }); }

	var settings = null;
	function getSettings() { return settings ? Promise.resolve(settings) : api('workspace/settings').then(function (s) { settings = s; return s; }); }

	/** One Calendar menu. These views switch between task and agency sources; permissions stay as they were. */
	var CAL_VIEWS = [['mine', 'My Calendar', '/calendar', 'mine'], ['team', 'Team Calendar', '/calendar', ''], ['project', 'Project Calendar', '/agency-calendar', 'project'], ['client', 'Client-visible Agency Events', '/agency-calendar', 'client'], ['consolidated', 'Consolidated Calendar', '/agency-calendar', 'consolidated']];
	WD.calendarTabs = function (on) {
		return '<div class="toolbar wsa-calviews" role="tablist">' + CAL_VIEWS.map(function (x) { return '<button type="button" role="tab" class="btn btn-sm' + (x[0] === on ? ' btn-primary' : '') + '" data-calview="' + x[0] + '">' + esc(x[1]) + '</button>'; }).join('') + '</div>';
	};
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-calview]');
		if (!b) return;
		var v = CAL_VIEWS.filter(function (x) { return x[0] === b.getAttribute('data-calview'); })[0];
		if (v[2] === '/calendar') { S.cal = S.cal || {}; S.cal.scope = v[3]; } else { calS.view = v[3]; }
		if (location.hash === '#' + v[2]) { WD.refresh(); } else { WD.go(v[2]); }
	});
	/* This section's dialogs open outside its layout, so the brand colour follows the address. */
	function markSection() { document.body.toggleAttribute('data-wsa', /^#\/workspace/.test(location.hash)); }
	window.addEventListener('hashchange', markSection);
	markSection();

	V.workspaceAdmin = function (c) {
		var p = S.routeParams || [];
		if (p[1] === 'requests' || p[1] === 'deliverables') { WD.go('/tasks/' + p[1]); return; }
		var nav = NAV.filter(function (t) { return can(t[2]); });
		var tab = p[1], sub = p[2];
		if (MOVED[tab]) { sub = MOVED[tab][1]; tab = MOVED[tab][0]; }
		tab = tab || (nav[0] && nav[0][0]);
		WD.setTitle('Workspace Management', 'Clients, their Workspaces and projects, and everything exchanged with Neon Workspace.');
		c.innerHTML = '<div class="wsa-shell"><nav class="wsa-menu" aria-label="Workspace Management">' + nav.map(function (t) {
			return '<a href="#/workspace/' + t[0] + '" class="' + (t[0] === tab ? 'on' : '') + '"' + (t[0] === tab ? ' aria-current="page"' : '') + '>' + esc(t[1]) + '</a>';
		}).join('') + '</nav><div class="wsa-main" data-ws-body>' + ui.loading(6) + '</div></div>';
		var box = c.querySelector('[data-ws-body]');
		var fn = { connect: P.connect, clients: wsClients, entitlements: P.entitlements, workspaces: P.workspaces, subscriptions: P.subscriptions, packages: P.packages, leads: leads, bookings: bookings, logs: logs }[tab];
		if (!fn) { box.innerHTML = ui.emptyState('Choose a section', '', WD.I.list); return; }
		return fn(box, /^\d+$/.test(sub || '') ? +sub : sub);
	};

	function logs(box, sub) {
		sub = sub || 'api';
		box.innerHTML = '<div class="wsa-subtabs" role="tablist">' + LOG_TABS.map(function (t) { return '<a role="tab" href="#/workspace/logs/' + t[0] + '" class="' + (t[0] === sub ? 'on' : '') + '">' + esc(t[1]) + '</a>'; }).join('') + '</div><div data-log>' + ui.loading(5) + '</div>';
		return ({ api: apiLogs, webhooks: webhookLogs, audit: audit, backups: backups }[sub] || apiLogs)(box.querySelector('[data-log]'));
	}

	/* ------------------------- Agency Connect: settings + invitations ------------------------- */
	function featureRows(list) {
		return '<div class="table-wrap"><table class="tbl"><thead><tr><th>Feature</th><th>On</th><th>Starts</th><th>Expires</th><th>Grace days</th><th>Usage limit</th></tr></thead><tbody>' + list.map(function (e) {
			return '<tr data-feature="' + esc(e.feature_code) + '"><td>' + esc(e.label) + (e.state ? ' ' + stateBadge(e.state) : '') + '</td>' +
				'<td><input type="checkbox" data-k="enabled"' + (e.enabled ? ' checked' : '') + ' aria-label="Enable ' + esc(e.label) + '"></td>' +
				'<td><input type="date" data-k="starts_on" value="' + esc(e.starts_on || '') + '"></td>' +
				'<td><input type="date" data-k="expires_on" value="' + esc(e.expires_on || '') + '"></td>' +
				'<td><input type="number" min="0" max="365" data-k="grace_days" placeholder="default" value="' + (e.grace_override ? esc(e.grace_days) : '') + '"></td>' +
				'<td><input type="number" min="0" data-k="usage_limit" placeholder="none" value="' + (e.usage_limit === null || e.usage_limit === undefined ? '' : esc(e.usage_limit)) + '"></td></tr>';
		}).join('') + '</tbody></table></div>';
	}
	function readFeatures(root) {
		return Array.prototype.map.call(root.querySelectorAll('[data-feature]'), function (tr) {
			var g = function (k) { return tr.querySelector('[data-k="' + k + '"]'); };
			return { feature_code: tr.getAttribute('data-feature'), enabled: g('enabled').checked, starts_on: g('starts_on').value, expires_on: g('expires_on').value, grace_days: g('grace_days').value, usage_limit: g('usage_limit').value };
		});
	}
	function permBoxes(p) {
		var L = { calendar: 'Calendar', work_requests: 'Work requests', approvals: 'Approvals', usage: 'Package usage' };
		return Object.keys(L).map(function (k) {
			return '<label class="wsa-check"><input type="checkbox" data-perm="' + k + '"' + (p[k] ? ' checked' : '') + '> ' + L[k] + '</label>';
		}).join('');
	}
	function readPerms(root) {
		var out = {};
		root.querySelectorAll('[data-perm]').forEach(function (i) { out[i.getAttribute('data-perm')] = i.checked; });
		return out;
	}
	function routingFields(o) {
		return field('Default team', '<select data-r="default_team_id">' + options(teams(), o.default_team_id, 'None') + '</select>') +
			field('Team routing', '<select data-r="default_team_mode"><option value="pool"' + (o.default_team_mode !== 'leader' ? ' selected' : '') + '>Team unassigned pool</option><option value="leader"' + (o.default_team_mode === 'leader' ? ' selected' : '') + '>Team leader</option></select>') +
			field('Or assign directly to', '<select data-r="default_member_id">' + options(people(), o.default_member_id, 'Nobody') + '</select>');
	}

	/* ------------------------- Workspace Clients ------------------------- */
	var wcS = { q: '', filter: '', sort: 'client', dir: 'asc', page: 1, inactive: false };
	var WC_FILTERS = [['', 'All'], ['connected', 'Connected'], ['invitation_pending', 'Invitation Pending'], ['not_connected', 'Not Connected'], ['suspended', 'Deactivated'], ['disconnected', 'Disconnected'], ['active_package', 'Active Package'], ['expired_package', 'Expired Package'], ['renewal_due', 'Renewal Due'], ['missing_entitlements', 'Missing Entitlements'], ['archived', 'Archived']];
	var WC_TONE = { not_connected: 'muted', invitation_pending: 'info', connected: 'ok', active: 'ok', renewal_due: 'warn', grace: 'warn', locked: 'bad', suspended: 'bad', disconnected: 'muted' };
	function wcBadge(x) { return badge(x.status_label, WC_TONE[x.status]); }

	/** The actions a relationship allows, in one place, for the table and the client page. */
	WD.wsActions = function (x) {
		var a = [];
		var cid = x.connection_id;
		if (cid) a.push(['manage', 'Manage Workspace']);
		a.push(['view', 'View Client']);
		if (x.status === 'invitation_pending') { a.push(['invite', 'Resend Invitation']); a.push(['revoke', 'Revoke Invitation']); }
		else if (x.status === 'not_connected') a.push(['projects', 'Connect from a project']);
		if (cid && x.status === 'disconnected' && x.workspace_uid) a.push(['reconnect', 'Reconnect']);
		if (cid && x.workspace_uid && ['connected', 'active', 'renewal_due', 'grace', 'locked', 'suspended'].indexOf(x.status) > -1) a.push(['btype', 'Change Business Type']);
		if (cid && x.status === 'suspended') a.push(['reactivate', 'Activate']);
		if (cid && ['connected', 'active', 'renewal_due', 'grace', 'locked'].indexOf(x.status) > -1) a.push(['suspend', 'Deactivate']);
		if (cid && ['connected', 'active', 'renewal_due', 'grace', 'locked', 'suspended'].indexOf(x.status) > -1) a.push(['disconnect', 'Disconnect Workspace']);
		if (cid && !x.archived && ['not_connected', 'disconnected', 'suspended'].indexOf(x.status) > -1) a.push(['archive', 'Archive Link']);
		if (cid && x.archived) a.push(['unarchive', 'Restore Link']);
		if (cid) a.push(['logs', 'View Sync Logs']);
		if (cid && x.status !== 'deleted') a.push(['deletion', 'Delete Workspace']);
		return a;
	};
	WD.wsDo = function (action, x, done) {
		var cid = x.connection_id, post = function (path, body) { return api('workspace/connections/' + cid + '/' + path, { method: 'POST', body: body || {} }); };
		var confirmThen = function (title, text, label, path) {
			WD.confirm(title, text, label, label !== 'Reactivate').then(function (ok) {
				if (ok) post(path).then(function () { toast('Done'); done(); }).catch(err);
			});
		};
		switch (action) {
			case 'manage': WD.go('/workspace/clients/' + cid); break;
			case 'view': WD.go('/clients/' + x.client_id); break;
			case 'invite': inviteModal(x, done); break;
			case 'revoke':
				WD.confirm('Revoke this invitation?', 'The unused link stops working at once. Its history stays in the audit log. No account or record is changed.', 'Revoke', true).then(function (ok) {
					if (ok) api('workspace/invitations/' + x.invitation_id + '/revoke', { method: 'POST', body: {} }).then(function () { toast('Invitation revoked'); done(); }).catch(err);
				});
				break;
			case 'disconnect': confirmThen('Disconnect this Workspace?', 'This Workspace stops syncing and leaves the active list. Nothing is deleted: the client keeps their account and data, WorkDesk keeps its records and sync history, and their other Workspaces are not affected. You can reconnect it later.', 'Disconnect', 'disconnect'); break;
			case 'reconnect': WD.confirm('Reconnect this Workspace?', 'It starts syncing again with fresh credentials, using the same Workspace and its existing data. No new invitation is needed.', 'Reconnect').then(function (ok) { if (ok) post('reconnect').then(function () { toast('Reconnected'); done(); }).catch(err); }); break;
			case 'suspend': confirmThen('Deactivate this Workspace?', 'Agency projects and requests in this Workspace pause until you activate it again. Nothing is deleted, and the client\'s other Workspaces are not affected.', 'Deactivate', 'suspend'); break;
			case 'reactivate': confirmThen('Activate this Workspace?', 'The client can use NeonDigital Connect in this Workspace again.', 'Activate', 'reactivate'); break;
			case 'archive': confirmThen('Archive this link?', 'It leaves the default list. Nothing is deleted, and you can restore it from the Archived filter.', 'Archive', 'archive'); break;
			case 'unarchive': post('unarchive').then(function () { toast('Link restored'); done(); }).catch(err); break;
			case 'logs': logsModal(x); break;
			case 'btype': WD.businessTypeFlow(cid, done); break;
			case 'projects': WD.go('/clients/' + x.client_id); toast('Open one of this client\'s projects and choose New Workspace for this project.'); break;
			case 'deletion': deletionModal(x, done); break;
		}
	};
	function inviteModal(x, done) {
		var m = modal({ title: 'Invite ' + (x.company || x.name || x.client_name), body: '<p class="muted small">The invitation uses this client\'s details. A new invitation replaces any open one, so nothing is duplicated.</p>' + field('Invitation email', '<input type="email" data-ie value="' + esc(x.invited_email || x.email || x.contact_email || '') + '">') + field('Valid for (days)', '<input type="number" min="1" max="60" data-iv value="14">') + '<label class="wsa-check"><input type="checkbox" data-im checked> Email the invitation now</label><div data-iout></div>', foot: '<button class="btn" data-close>Close</button><button class="btn btn-primary" data-ig>Create invitation</button>' });
		var root = m.el;
		root.querySelector('[data-ig]').onclick = function () {
			api('workspace/clients/' + x.client_id + '/invite', { method: 'POST', body: { email: root.querySelector('[data-ie]').value, valid_days: root.querySelector('[data-iv]').value, send_email: root.querySelector('[data-im]').checked } }).then(function (r) {
				root.querySelector('[data-iout]').innerHTML = '<div class="alert">' + (r.emailed ? 'Invitation emailed.' : 'The invitation was not emailed, so send this link yourself.') + ' Single use, expires ' + esc(r.expires_at) + '. The link is shown only here:<br><input readonly value="' + esc(r.url) + '" onclick="this.select()"></div>';
				toast('Invitation created');
				done();
			}).catch(err);
		};
	}
	function logsModal(x) {
		var m = modal({ title: 'Sync logs', size: 'lg', body: ui.loading(4) });
		api('workspace/connections/' + x.connection_id + '/logs').then(function (r) {
			m.body.innerHTML = '<h3 class="wsa-h3">API calls</h3>' + table([['Time', function (l) { return dt(l.created_at); }], ['Call', function (l) { return '<span class="mono small">' + esc(l.method) + ' ' + esc(l.route) + '</span>'; }], ['Status', function (l) { return badge(l.status, +l.status < 400 ? 'ok' : 'bad'); }], ['Error', s('error')]], r.api, 'No calls logged') +
				'<h3 class="wsa-h3">Webhooks</h3>' + table([['Time', function (l) { return dt(l.created_at); }], ['Event', function (l) { return '<span class="mono small">' + esc(l.event) + '</span>'; }], ['Status', function (l) { return statusBadge(l.status); }], ['Attempts', s('attempts')], ['Last error', s('last_error')]], r.webhooks, 'No webhooks sent');
		}).catch(err);
	}
	/**
	 * Phase 5: Delete Workspace for real. Shows exactly what goes and what stays (counted live on
	 * Neon Workspace and here), needs the Workspace name typed, and reports the true outcome. If
	 * Neon Workspace refuses or cannot be reached, nothing is deleted and that is what is said.
	 */
	function deletionModal(x, done) {
		var m = modal({ title: 'Delete Workspace', size: 'lg', body: ui.loading(4) });
		api('workspace/connections/' + x.connection_id + '/delete-preview').then(function (r) {
			var w = r.workspace, lab = { projects: 'Projects', project_members: 'Project team rows', tasks: 'Tasks', milestones: 'Milestones', comments: 'Comments', files: 'File records', events: 'Calendar events', event_participants: 'Event guests', calendar_shares: 'Calendar shares', customers: 'Customers', leads: 'Leads and enquiries', activities: 'CRM activity', business: 'Business profile', domains: 'Domains', closures: 'Closed days', services: 'Services', booking_pages: 'Booking pages', bookings: 'Bookings', notifications: 'Notifications', notes: 'Notes', goals: 'Goals', invites: 'Team invitations', forms: 'Forms', form_submissions: 'Form entries', planner_days: 'Planner days', meetings: 'Meetings', portal_tokens: 'Customer portal links', payment_accounts: 'Payment accounts', drive_files: 'Drive file links', drive_folders: 'Drive folders', drive_links: 'Drive connection', biz_gateways: 'Payment gateway keys', availability: 'Availability rules', retention_runs: 'Clean-up runs', sync_outbox: 'Pending sync', feature_grants: 'Feature grants', workspace_addons: 'Add-ons', spaces: 'Spaces and rooms', booking_credits: 'Makeup credits', biz_records: 'Business records', biz_lists: 'Saved lists', pos_orders: 'POS orders', pos_items: 'POS items', pos_kots: 'Kitchen tickets', stays: 'Hotel stays', checkins: 'Check-ins', attendance: 'Attendance marks', conversions: 'Business-type changes', agency_invoices: 'Copies of NeonDigital invoices', number_series: 'Number series', subscriptions: 'Subscription', biz_invoices: 'Invoices', biz_quotes: 'Quotations', biz_items: 'Invoice and quotation lines', biz_payments: 'Payments', finance_entries: 'Income and expense entries', sub_invoices: 'Subscription invoices', transactions: 'Payment transactions', project_subscriptions: 'Project subscriptions' };
			var rows = function (o) { var k = Object.keys(o || {}); return k.length ? k.map(function (t) { return '<div class="wsa-row"><span>' + esc(lab[t] || t) + '</span><b>' + (+o[t]) + '</b></div>'; }).join('') : '<p class="muted small">Nothing.</p>'; };
			var wd = r.workdesk || {};
			var body = '<div class="alert alert-error"><b>This permanently deletes the Workspace ' + esc(r.workspace_name || '(not named)') + '</b> of ' + esc(r.client_name) + '. Its members lose access at once and it cannot be undone. The platform\'s routine backups expire on their own schedule (see the Privacy Policy).</div>';
			if (r.workspace_error) body += '<div class="alert">' + esc(r.workspace_error) + (r.linked ? ' The numbers below could not be loaded, and deleting is refused until Neon Workspace answers.' : '') + '</div>';
			if (w) body += '<div class="wsa-cols"><div><h3 class="wsa-h3">Deleted on Neon Workspace</h3>' + rows(w.deleted) + '<div class="wsa-row"><span>Stored files (uploads, attachments, backups of business-type changes, images)</span><b>' + (+w.files) + '</b></div><div class="wsa-row"><span>Members whose access ends</span><b>' + (+w.members) + '</b></div></div>' +
				'<div><h3 class="wsa-h3">Moved to the restricted archive</h3><p class="muted small">Tax law requires these to be kept. They are encrypted, removed from the Workspace, visible only to platform administrators on a legal request, and destroyed after the retention period.</p>' + rows(w.archived) + '</div></div>';
			else if (!r.linked) body += '<p class="muted">The invitation for this Workspace was never accepted, so nothing exists on Neon Workspace. Deleting closes the link and revokes any open invitation.</p>';
			body += '<h3 class="wsa-h3">Removed from WorkDesk</h3><div class="wsa-row"><span>Synced lead copies</span><b>' + (+wd.leads || 0) + '</b></div><div class="wsa-row"><span>Synced booking copies</span><b>' + (+wd.bookings || 0) + '</b></div><div class="wsa-row"><span>Projects that stop using this Workspace</span><b>' + esc((wd.projects_unassigned || []).join(', ') || 'None') + '</b></div>' +
				'<h3 class="wsa-h3">Kept in WorkDesk</h3><ul class="small">' + (r.workdesk_kept || []).map(function (k) { return '<li>' + esc(k) + '</li>'; }).join('') + '</ul>';
			var blocked = !r.can_delete || r.status === 'deleted' || (r.linked && !w);
			if (!r.can_delete) body += '<div class="alert">You need permission to delete records. Ask an administrator.</div>';
			if (!blocked) body += field('Reason (kept in the audit record)', '<textarea data-dr rows="2" placeholder="e.g. Client closed this business"></textarea>') + field('Type the Workspace name to confirm: ' + r.workspace_name, '<input data-dn autocomplete="off" spellcheck="false">') + '<div class="toolbar"><button class="btn btn-danger" data-ds disabled>Delete Workspace permanently</button></div><div data-dres></div>';
			m.body.innerHTML = body;
			var root = m.el, ds = root.querySelector('[data-ds]'), dn = root.querySelector('[data-dn]');
			if (dn) dn.oninput = function () { ds.disabled = dn.value.trim() !== r.workspace_name; };
			if (ds) ds.onclick = function () {
				ds.disabled = true;
				ds.textContent = 'Deleting…';
				api('workspace/connections/' + x.connection_id + '/delete', { method: 'POST', body: { confirm_name: dn.value.trim(), reason: root.querySelector('[data-dr]').value } }).then(function (res) {
					var n = res.workspace && res.workspace.deleted ? Object.keys(res.workspace.deleted).reduce(function (a, k) { return a + (+res.workspace.deleted[k]); }, 0) : 0;
					m.body.innerHTML = '<div class="alert alert-ok"><b>' + esc(res.workspace_name) + ' was deleted.</b><br>' + (res.workspace ? n + ' records and ' + (+res.workspace.files_removed) + ' files removed on Neon Workspace; ' + (+res.workspace.members_removed) + ' members lost access' + (res.workspace.archive_until ? '; financial records archived until ' + esc(res.workspace.archive_until) : '') + '.' : esc(res.note)) + '<br>WorkDesk removed ' + (+res.workdesk_removed.leads) + ' lead copies and ' + (+res.workdesk_removed.bookings) + ' booking copies and closed the link.</div>';
					toast('Workspace deleted');
					done();
				}).catch(function (e) {
					ds.disabled = false;
					ds.textContent = 'Delete Workspace permanently';
					root.querySelector('[data-dres]').innerHTML = '<div class="alert alert-error">' + esc(e.message) + '</div>';
				});
			};
		}).catch(function (e) { m.body.innerHTML = '<div class="alert alert-error">' + esc(e.message) + '</div>'; });
	}
	function wsClients(box, id) {
		if (id) return connectionDetail(box, id);
		box.innerHTML = card('Workspace Clients', '<div class="toolbar"><input type="search" data-q placeholder="Name, business, email, phone, client ID, Workspace ID or invited email" value="' + esc(wcS.q) + '" aria-label="Search Workspace clients" style="flex:1 1 280px"><select data-fl data-native aria-label="Filter" style="width:auto">' + WC_FILTERS.map(function (f) { return '<option value="' + f[0] + '"' + (f[0] === wcS.filter ? ' selected' : '') + '>' + f[1] + '</option>'; }).join('') + '</select><label class="wsa-check"><input type="checkbox" data-inactive' + (wcS.inactive ? ' checked' : '') + '> Show Inactive Clients / Workspaces</label></div><div data-list>' + ui.loading(6) + '</div>');
		var list = box.querySelector('[data-list]');
		function th(key, label) { return '<th>' + (key ? '<button type="button" class="wsa-sort" data-sort="' + key + '">' + esc(label) + (wcS.sort === key ? (wcS.dir === 'asc' ? ' ▲' : ' ▼') : '') + '</button>' : esc(label)) + '</th>'; }
		function load() {
			return api('workspace/clients', { params: { q: wcS.q, filter: wcS.filter, sort: wcS.sort, dir: wcS.dir, page: wcS.page, show_inactive: wcS.inactive ? 1 : 0 } }).then(function (r) {
				if (!list.isConnected) return;
				var rows = r.items.map(function (x, i) {
					var acts = WD.wsActions(x);
					return '<tr><td data-th="Client / Workspace"><a href="' + (x.connection_id ? '#/workspace/clients/' + x.connection_id : '#/clients/' + x.client_id) + '"><b>' + esc(x.name) + '</b></a><div class="muted small">' + esc(x.company || '') + (x.workspace_name ? ' · ' + esc(x.workspace_name) : '') + '</div></td>' +
						'<td data-th="Email">' + esc(x.invited_email || x.email || '—') + '</td><td data-th="Connection">' + wcBadge(x) + (x.connection_id && !x.connect_on ? ' ' + badge('Connect off', 'muted') : '') + (x.workspace_uid ? '<div class="muted small mono" title="Workspace ID">' + esc(x.workspace_uid) + '</div>' : '') + '</td>' +
						'<td data-th="Projects">' + (+x.project_count) + (x.locked_count ? ' <span class="muted small">' + (+x.locked_count) + ' locked</span>' : '') + '</td><td data-th="Features">' + (+x.feature_count) + '</td>' +
						'<td data-th="Validity">' + (x.package_end ? day(x.package_end) + ' ' + stateBadge(x.package_state) : '<span class="muted">—</span>') + '</td><td data-th="Last sync">' + dt(x.last_sync) + '</td>' +
						'<td data-th="Actions"><select class="wsa-act" data-native data-act="' + i + '" aria-label="Actions for ' + esc(x.name) + '"><option value="">Actions</option>' + acts.map(function (a) { return '<option value="' + a[0] + '">' + esc(a[1]) + '</option>'; }).join('') + '</select></td></tr>';
				}).join('');
				list.innerHTML = r.items.length ? '<p class="muted small">' + r.total + ' client' + (r.total === 1 ? '' : 's') + '</p><div class="table-wrap"><table class="tbl stack"><thead><tr>' + th('client', 'Client / Workspace') + th('', 'Email') + th('status', 'Connection') + th('', 'Projects') + th('', 'Features') + th('validity', 'Validity') + th('sync', 'Last sync') + th('', 'Actions') + '</tr></thead><tbody>' + rows + '</tbody></table></div>' +
					(r.pages > 1 ? '<div class="toolbar"><button class="btn btn-sm" data-pg="-1"' + (r.page <= 1 ? ' disabled' : '') + '>Previous</button><span class="muted small">Page ' + r.page + ' of ' + r.pages + '</span><button class="btn btn-sm" data-pg="1"' + (r.page >= r.pages ? ' disabled' : '') + '>Next</button></div>' : '') : ui.emptyState('No clients match', 'Change the search or filter.', WD.I.list);
				list.querySelectorAll('[data-sort]').forEach(function (b) { b.onclick = function () { var k = b.getAttribute('data-sort'); if (wcS.sort === k) wcS.dir = wcS.dir === 'asc' ? 'desc' : 'asc'; else { wcS.sort = k; wcS.dir = 'asc'; } wcS.page = 1; load(); }; });
				list.querySelectorAll('[data-pg]').forEach(function (b) { b.onclick = function () { wcS.page += +b.getAttribute('data-pg'); load(); }; });
				list.querySelectorAll('[data-act]').forEach(function (sel) { sel.onchange = function () { var v = sel.value; sel.value = ''; if (v) WD.wsDo(v, r.items[+sel.getAttribute('data-act')], load); }; });
			}).catch(err);
		}
		box.querySelector('[data-q]').addEventListener('input', WD.u.debounce(function () { wcS.q = box.querySelector('[data-q]').value; wcS.page = 1; load(); }, 300));
		box.querySelector('[data-fl]').onchange = function (e) { wcS.filter = e.target.value; wcS.page = 1; load(); };
		box.querySelector('[data-inactive]').onchange = function (e) { wcS.inactive = e.target.checked; wcS.page = 1; load(); };
		return load();
	}

	/*
	 * Workspace details (1.18): name and ID, connection information, and one Active / Deactivated
	 * control. Projects and packages are managed on each project page. Agency access dates and
	 * features stay available below, folded, because they decide what the client can use.
	 */
	function connectionDetail(box, id) {
		return Promise.all([api('workspace/connections/' + id), null]).then(function (res) {
			var c = res[0];
			var kv = function (k, v) { return '<div><span>' + esc(k) + '</span>' + v + '</div>'; }, dash = '<span class="muted">—</span>';
			var stateLine = { active: ['Active', 'ok', 'NeonDigital Connect is on for this Workspace.'], suspended: ['Deactivated', 'bad', 'Agency projects and requests are paused for this Workspace only. Nothing is deleted.'], invited: ['Invitation pending', 'info', 'The client has not accepted the invitation yet.'], disconnected: ['Disconnected', 'muted', 'This Workspace no longer syncs. Its data and history are kept.'] }[c.status] || [c.status, 'muted', ''];
			box.innerHTML = '<nav class="wsa-crumb"><a href="#/workspace/clients">← Workspace Clients</a><span class="muted">/</span><span>' + esc(c.client_name) + '</span></nav>' +
				'<section class="card"><div class="card-h"><h2>' + esc(c.workspace_name || 'Workspace not linked yet') + '</h2> ' + badge(stateLine[0], stateLine[1]) + '<span class="spacer"></span><span data-status-acts></span></div><div class="card-b">' +
					'<p class="muted small" style="margin-top:0">' + esc(stateLine[2]) + '</p><div class="wsa-facts">' +
					kv('Workspace name', c.workspace_name ? esc(c.workspace_name) : dash) + kv('Workspace ID', c.workspace_uid ? '<span class="mono small">' + esc(c.workspace_uid) + '</span>' : dash) +
					kv('Client', '<a href="#/clients/' + c.client_id + '">' + esc(c.client_name) + '</a>') + kv('Linked', c.linked_at ? dt(c.linked_at) : dash) + kv('Last contact', c.last_seen_at ? dt(c.last_seen_at) : dash) +
					kv('Invited email', (c.invitations && c.invitations.length) ? esc(c.invitations[0].email) : dash) + '</div></div></section>' +
				'<section class="section-gap"><div class="card"><div class="card-h"><h2>Projects in this Workspace</h2><span class="spacer"></span><a class="btn btn-sm" href="#/projects">All projects</a></div><div class="card-b flush" data-tree>' + ui.loading(3) + '</div></div></section>' +
				'<details class="card section-gap wsa-ent"><summary class="card-h"><h2>Agency access and features</h2><span class="spacer"></span><span class="muted small">Dates, features, request routing</span></summary><div class="card-b">' +
					'<h3 class="wsa-h3">What this client may see and do</h3><div class="wsa-perms">' + permBoxes(c.perms) + '</div>' +
					'<h3 class="wsa-h3">Agency relationship</h3><div class="form-grid">' +
					field('NeonDigital Connect', '<select data-f="agency_connect"><option value="1"' + (c.agency_connect ? ' selected' : '') + '>Available</option><option value="0"' + (c.agency_connect ? '' : ' selected') + '>Not available</option></select>') +
					field('Starts', '<input type="date" data-f="package_start" value="' + esc(c.package_start || '') + '">') +
					field('Ends', '<input type="date" data-f="package_end" value="' + esc(c.package_end || '') + '">') +
					field('Manual extension until', '<input type="date" data-f="extended_until" value="' + esc(c.extended_until || '') + '">') +
					field('Grace period (days)', '<input type="number" data-f="grace_days" value="' + esc(c.grace_days) + '">') + '</div>' +
					'<h3 class="wsa-h3">Team and member for new client requests</h3><div class="form-grid cols-3">' + routingFields(c) + '</div>' +
					'<h3 class="wsa-h3">Features and their own dates</h3>' + featureRows(c.entitlements) +
					'<div class="toolbar"><button class="btn btn-primary" data-save>Save access and features</button></div></div></details>' +
				'<details class="card section-gap"><summary class="card-h"><h2>Connection and invitations</h2><span class="spacer"></span><span class="muted small">Sync logs, invitations, disconnect</span></summary><div class="card-b">' +
					'<div class="toolbar" data-more-acts></div>' +
					table([
						['Email', function (x) { return esc(x.email); }], ['Created', function (x) { return dt(x.created_at); }], ['Expires', function (x) { return dt(x.expires_at); }],
						['State', function (x) { return x.accepted_at ? badge('Accepted', 'ok') : x.revoked_at ? badge('Revoked', 'muted') : badge('Open', 'info'); }],
						['', function (x) { return !x.accepted_at && !x.revoked_at ? '<button class="btn btn-sm" data-revoke="' + x.id + '">Revoke</button>' : ''; }]
					], c.invitations, 'No invitations') + '</div></details>';
			WD.wsaClientTree(box.querySelector('[data-tree]'), c.client_id, function () { connectionDetail(box, id); }, id);
			var again = function () { connectionDetail(box, id); };
			api('workspace/clients/' + c.client_id + '/status', { params: { connection_id: id } }).then(function (x) {
				var top = box.querySelector('[data-status-acts]'), more = box.querySelector('[data-more-acts]');
				if (!top) return;
				var all = WD.wsActions(x), has = function (k) { return all.some(function (a) { return a[0] === k; }); };
				var main = [];
				if (has('suspend')) main.push(['suspend', 'Deactivate', 'btn-danger']);
				if (has('reactivate')) main.push(['reactivate', 'Activate', 'btn-primary']);
				if (has('reconnect')) main.push(['reconnect', 'Reconnect', 'btn-primary']);
				if (has('btype')) main.push(['btype', 'Change Business Type', '']);
				if (c.status === 'invited' && has('invite')) main.push(['invite', 'Resend invitation', '']);
				top.innerHTML = main.map(function (a) { return '<button class="btn btn-sm ' + a[2] + '" data-wsa="' + a[0] + '">' + esc(a[1]) + '</button>'; }).join(' ');
				var rest = all.filter(function (a) { return ['logs', 'disconnect', 'deletion', 'revoke'].indexOf(a[0]) > -1; });
				more.innerHTML = rest.map(function (a) { return '<button class="btn btn-sm' + (a[0] === 'disconnect' ? ' btn-danger' : '') + '" data-wsa="' + a[0] + '">' + esc(a[1]) + '</button>'; }).join(' ');
				box.querySelectorAll('[data-wsa]').forEach(function (b) { b.onclick = function () { WD.wsDo(b.getAttribute('data-wsa'), x, again); }; });
			}).catch(function () {});
			box.querySelector('[data-save]').onclick = function () {
				var d = { entitlements: readFeatures(box), perms: readPerms(box) };
				box.querySelectorAll('[data-f]').forEach(function (i) { d[i.getAttribute('data-f')] = i.value; });
				d.agency_connect = d.agency_connect === '1';
				box.querySelectorAll('[data-r]').forEach(function (i) { d[i.getAttribute('data-r')] = i.value; });
				api('workspace/connections/' + id, { method: 'PUT', body: d }).then(function () { toast('Saved. Workspace is told at the next sync.'); again(); }).catch(err);
			};
			box.querySelectorAll('[data-revoke]').forEach(function (b) {
				b.onclick = function () { api('workspace/invitations/' + b.getAttribute('data-revoke') + '/revoke', { method: 'POST', body: {} }).then(again).catch(err); };
			});
		});
	}

	/* ------------------------- Work requests ------------------------- */
	function requests(box) {
		return api('workspace/requests').then(function (r) {
			var open = r.items.filter(function (q) { return q.status === 'submitted' || q.status === 'in_review'; });
			var rest = r.items.filter(function (q) { return open.indexOf(q) === -1; });
			var cols = [
				['Request', function (q) { return '<b>#' + q.id + ' ' + esc(q.title) + '</b><div class="muted small">' + esc(q.client_name) + (q.project_name ? ' · ' + esc(q.project_name) : '') + '</div>'; }],
				['Service', function (q) { return esc(q.service_name || '—') + (q.unit_held ? ' ' + badge('Unit reserved', 'info') : '') + (q.over_limit ? ' ' + badge('No units left', 'warn') : ''); }],
				['Preferred', function (q) { return day(q.preferred_date); }],
				['Status', function (q) { return statusBadge(q.status, q.status_label); }],
				['', function (q) { return '<button class="btn btn-sm" data-open="' + q.id + '">Open</button>' + (q.task_id ? ' <a class="btn btn-sm btn-ghost" href="#/tasks/' + q.task_id + '">Task #' + q.task_id + '</a>' : ''); }]
			];
			box.innerHTML = card('Waiting for review (' + open.length + ')', table(cols, open, 'Nothing is waiting for review')) + '<div class="wsa-gap"></div>' + card('Reviewed', table(cols, rest, 'No reviewed requests yet'));
			box.onclick = function (e) {
				var b = e.target.closest('[data-open]');
				if (b) reviewModal(r.items.filter(function (q) { return q.id === +b.getAttribute('data-open'); })[0], function () { requests(box); });
			};
		});
	}

	function discussion(q) {
		return q.comments.map(function (c) { return '<p><b>' + esc(c.author_name || c.author_type) + '</b>' + (c.is_internal ? ' ' + badge('Internal', 'warn') : '') + ' <span class="muted small">' + dt(c.created_at) + '</span><br>' + esc(c.body) + '</p>'; }).join('') || '<p class="muted">No comments yet.</p>';
	}

	function reviewModal(q, done) {
		var reviewable = q.status === 'submitted' || q.status === 'in_review';
		var body = '<p><b>' + esc(q.client_name) + '</b>' + (q.requested_by ? ' · ' + esc(q.requested_by) : '') + '</p>' +
			'<p class="pre">' + esc(q.description) + '</p>' + (q.instructions ? '<p class="muted small">Instructions: ' + esc(q.instructions) + '</p>' : '') +
			(q.reference_links.length ? '<ul>' + q.reference_links.map(function (l) { return '<li><a target="_blank" rel="noopener noreferrer" href="' + esc(l.url) + '">' + esc(l.label || l.url) + '</a></li>'; }).join('') + '</ul>' : '') +
			(q.review_note ? '<p><b>Review note:</b> ' + esc(q.review_note) + '</p>' : '') +
			(reviewable ? '<div class="form-grid">' +
				field('Team', '<select data-a="team_id">' + options(teams(), q.default_team_id, 'None') + '</select>') +
				field('Routing', '<select data-a="team_mode"><option value="pool"' + (q.default_team_mode !== 'leader' ? ' selected' : '') + '>Team unassigned pool</option><option value="leader"' + (q.default_team_mode === 'leader' ? ' selected' : '') + '>Team leader</option></select>') +
				field('Or assign to', '<select data-a="assignee_id">' + options(people(), q.default_member_id, 'Nobody') + '</select>') +
				field('Due date', '<input type="date" data-a="due_date" value="' + esc(q.preferred_date || '') + '">') +
				(q.over_limit ? field('Reason for going beyond the package', '<input data-a="package_override_reason">', 'needed to approve', 'span-2') : '') +
				field('Note to the client', '<textarea data-a="note" rows="2"></textarea>', 'required to reject', 'span-2') + '</div>' : '') +
			(q.task_id ? '<p><a class="btn btn-sm" href="#/tasks/' + q.task_id + '">Open task #' + q.task_id + ' to chat with the client</a></p>' : '') +
				'<h3 class="wsa-h3">Discussion</h3><div data-disc>' + discussion(q) + '</div>' +
			'<textarea data-c rows="2" placeholder="Add a comment"></textarea><label class="wsa-check"><input type="checkbox" data-int checked> Internal only (never sent to the client)</label><button class="btn btn-sm" data-cmt type="button">Add comment</button>';
		var m = modal({ title: 'Request #' + q.id + ': ' + q.title, body: body, foot: reviewable ? '<button class="btn" data-go="in_review">Mark in review</button><button class="btn" data-go="reject">Reject</button><button class="btn btn-primary" data-go="approve">Approve and route</button>' : false });
		var root = m.el;
		root.querySelector('[data-cmt]').onclick = function () {
			var bx = root.querySelector('[data-c]');
			api('workspace/requests/' + q.id + '/comments', { method: 'POST', body: { body: bx.value, is_internal: root.querySelector('[data-int]').checked } }).then(function (fresh) {
				bx.value = '';
				q = fresh;
				root.querySelector('[data-disc]').innerHTML = discussion(fresh);
				toast('Comment added');
				done();
			}).catch(err);
		};
		root.querySelectorAll('[data-go]').forEach(function (b) {
			b.onclick = function () {
				var d = { decision: b.getAttribute('data-go') };
				root.querySelectorAll('[data-a]').forEach(function (i) { d[i.getAttribute('data-a')] = i.value; });
				if (d.package_override_reason) d.package_override = true;
				api('workspace/requests/' + q.id + '/review', { method: 'POST', body: d }).then(function () { toast('Saved'); if (m.close) m.close(); done(); }).catch(err);
			};
		});
	}

	/* ------------------------- Deliverables ------------------------- */
	function deliverables(box) {
		return Promise.all([api('workspace/deliverables'), api('workspace/connections')]).then(function (r) {
			var conns = r[1].items.map(function (c) { return { id: c.id, name: c.client_name }; });
			box.innerHTML = card('Deliverables', table([
				['Deliverable', function (d) { return '<b>' + esc(d.title) + '</b> <span class="muted small">v' + d.revision + '</span><div class="muted small">' + esc(d.client_name) + (d.project_name ? ' · ' + esc(d.project_name) : '') + '</div>'; }],
				['Status', function (d) { return statusBadge(d.status, d.status_label); }],
				['Due', function (d) { return day(d.due_date); }],
				['Latest response', function (d) { var a = d.approvals[d.approvals.length - 1]; return a ? esc(a.decision) + (a.comment ? ': ' + esc(a.comment) : '') : '<span class="muted">—</span>'; }],
				['Task', function (d) { return d.task_id ? '<a class="btn btn-sm btn-ghost" href="#/tasks/' + d.task_id + '">Task #' + d.task_id + '</a>' : '<span class="muted small">Not on a task</span>'; }],
				['', function (d) { return d.status !== 'pending_approval' && d.status !== 'approved' ? '<button class="btn btn-sm" data-rev="' + d.id + '">New revision</button>' : ''; }]
			], r[0].items, 'No deliverables yet'), '<button class="btn btn-sm btn-primary" data-new>Add deliverable</button>');
			box.querySelector('[data-new]').onclick = function () {
				var m = modal({ title: 'Add deliverable', body: '<div class="form-grid">' + field('Client', '<select data-d="connection_id">' + options(conns, '', 'Choose') + '</select>', '', 'span-2') + field('Title', '<input data-d="title">', '', 'span-2') + field('File link', '<input data-d="file_url" type="url">', '', 'span-2') + field('Due date', '<input data-d="due_date" type="date">') + field('Description', '<textarea data-d="description" rows="3"></textarea>', '', 'span-2') + '</div>', foot: '<button class="btn btn-primary" data-sv>Save and ask for approval</button>' });
				var root = m.el;
				root.querySelector('[data-sv]').onclick = function () {
					var d = {};
					root.querySelectorAll('[data-d]').forEach(function (i) { d[i.getAttribute('data-d')] = i.value; });
					api('workspace/deliverables', { method: 'POST', body: d }).then(function () { if (m.close) m.close(); deliverables(box); }).catch(err);
				};
			};
			box.querySelectorAll('[data-rev]').forEach(function (b) {
				b.onclick = function () {
					var url = window.prompt('Link to the new revision');
					if (url === null) return;
					api('workspace/deliverables/' + b.getAttribute('data-rev'), { method: 'PUT', body: { file_url: url, new_revision: true } }).then(function () { deliverables(box); }).catch(err);
				};
			});
		});
	}

	/* ------------------------- Consolidated ------------------------- */
	function consolidated(box, path, title, cols) {
		var q = '';
		function load() {
			return api('workspace/' + path, { params: { q: q } }).then(function (r) {
				box.querySelector('[data-list]').innerHTML = '<p class="muted small">Every view and export is logged. Retention policy: ' + r.retention_days + ' days.</p>' + table(cols, r.items, 'No records yet');
			}).catch(err);
		}
		box.innerHTML = card(title, '<div class="toolbar"><input type="search" data-q placeholder="Search name, email or phone"><span class="spacer"></span><button class="btn btn-sm" data-exp>Export CSV</button><button class="btn btn-sm" data-purge>Purge past retention</button></div><div data-list>' + ui.loading(4) + '</div>');
		box.querySelector('[data-q]').addEventListener('input', WD.u.debounce(function (e) { q = box.querySelector('[data-q]').value; load(); }, 300));
		box.querySelector('[data-exp]').onclick = function () {
			api('workspace/' + path, { params: { q: q, export: 1 } }).then(function (r) {
				var a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([r.csv], { type: 'text/csv' }));
				a.download = r.filename;
				a.click();
			}).catch(err);
		};
		box.querySelector('[data-purge]').onclick = function () {
			if (!window.confirm('Delete consolidated leads and bookings older than the retention period? The businesses keep their own records in Workspace.')) return;
			api('workspace/retention/purge', { method: 'POST', body: { confirm: true } }).then(function (r) { toast('Removed ' + r.leads + ' leads and ' + r.bookings + ' bookings'); load(); }).catch(err);
		};
		return load();
	}
	function s(k) { return function (x) { return x[k] ? esc(x[k]) : '<span class="muted">—</span>'; }; }
	function leads(box) {
		return consolidated(box, 'leads', 'Consolidated Leads', [['Business', s('business_name')], ['Customer', s('customer_name')], ['Contact', function (x) { return esc(x.contact_number) + '<div class="muted small">' + esc(x.email) + '</div>'; }], ['Enquiry', s('enquiry')], ['Type', s('lead_type')], ['Source', s('lead_source')], ['Status', s('status')], ['Date', function (x) { return dt(x.lead_date); }], ['Assigned', s('assigned_to')], ['Marketing consent', function (x) { return +x.marketing_consent ? 'Yes' : 'No'; }], ['Source ID', function (x) { return '<span class="mono small">' + esc(x.workspace_uid) + ' / ' + esc(x.source_id) + '</span>'; }]]);
	}
	function bookings(box) {
		return consolidated(box, 'bookings', 'Consolidated Bookings', [['Business', s('business_name')], ['Customer', s('customer_name')], ['Contact', function (x) { return esc(x.contact_number) + '<div class="muted small">' + esc(x.email) + '</div>'; }], ['Service / class', s('service')], ['Type', s('booking_type')], ['When', function (x) { return dt(x.booking_at); }], ['Status', s('status')], ['Source ID', function (x) { return '<span class="mono small">' + esc(x.workspace_uid) + ' / ' + esc(x.source_id) + '</span>'; }]]);
	}

	/* ------------------------- Logs ------------------------- */
	function apiLogs(box) {
		return api('workspace/logs/api').then(function (r) {
			box.innerHTML = card('API & Sync Logs', table([['Time', function (x) { return dt(x.created_at); }], ['Request', function (x) { return '<span class="mono small">' + esc(x.method) + ' ' + esc(x.route) + '</span>'; }], ['Status', function (x) { return badge(x.status, +x.status < 400 ? 'ok' : 'bad'); }], ['Error', s('error')], ['Connection', s('connection_id')], ['IP', s('ip')]], r.items, 'No API calls yet'));
		});
	}
	function webhookLogs(box) {
		return api('workspace/logs/webhooks').then(function (r) {
			box.innerHTML = card('Webhook Logs', table([['Created', function (x) { return dt(x.created_at); }], ['Event', function (x) { return '<span class="mono small">' + esc(x.event) + '</span>'; }], ['Status', function (x) { return statusBadge(x.status); }], ['Attempts', s('attempts')], ['Next try', function (x) { return dt(x.next_attempt_at); }], ['Last error', s('last_error')], ['', function (x) { return x.status !== 'delivered' ? '<button class="btn btn-sm" data-retry="' + x.id + '">Retry</button>' : ''; }]], r.items, 'No webhooks yet'), '<button class="btn btn-sm" data-run>Send due now</button>');
			box.querySelector('[data-run]').onclick = function () { api('workspace/webhooks/run', { method: 'POST', body: {} }).then(function (x) { toast('Sent ' + x.sent + ', failed ' + x.failed); webhookLogs(box); }).catch(err); };
			box.querySelectorAll('[data-retry]').forEach(function (b) { b.onclick = function () { api('workspace/webhooks/' + b.getAttribute('data-retry') + '/retry', { method: 'POST', body: {} }).then(function () { webhookLogs(box); }).catch(err); }; });
		});
	}
	function audit(box) {
		var page = audit.page || 1;
		return api('workspace/logs/audit', { params: { page: page } }).then(function (r) {
			setTimeout(function () {
				if (!box.isConnected || r.pages < 2) return;
				var d = document.createElement('div');
				d.className = 'toolbar';
				d.innerHTML = '<button class="btn btn-sm" data-pv' + (page <= 1 ? ' disabled' : '') + '>Previous</button><span class="muted small">Page ' + page + ' of ' + r.pages + '</span><button class="btn btn-sm" data-nx' + (page >= r.pages ? ' disabled' : '') + '>Next</button>';
				d.querySelector('[data-pv]').onclick = function () { audit.page = page - 1; audit(box); };
				d.querySelector('[data-nx]').onclick = function () { audit.page = page + 1; audit(box); };
				box.appendChild(d);
			}, 0);
			box.innerHTML = card('Data Access & Audit Logs', table([['Time', function (x) { return dt(x.created_at); }], ['Kind', function (x) { return badge(x.kind, x.kind === 'admin' ? 'muted' : 'info'); }], ['Action', function (x) { return esc(String(x.action).replace(/_/g, ' ')); }], ['By', s('actor')], ['Details', function (x) { return x.details ? '<code class="small">' + esc(String(x.details).slice(0, 180)) + '</code>' : ''; }]], r.items, 'No entries yet'));
		});
	}
	function backups(box) {
		return api('workspace/logs/backups').then(function (r) {
			box.innerHTML = card('Backup Logs', '<p class="muted small">A record of backups taken with your host\'s tools. WorkDesk does not take the backup itself. Never store a dump where the web server can serve it.</p>' +
				'<div class="form-grid cols-3">' + field('Type', '<select data-b="backup_type"><option value="database">Database</option><option value="files">Uploaded files</option><option value="full">Full site</option></select>') + field('Date', '<input type="date" data-b="taken_on" value="' + esc(S.today) + '">') + field('Stored at', '<input data-b="location_note" placeholder="Where it is kept (no credentials)">') + '</div>' +
				'<label class="wsa-check"><input type="checkbox" data-b-v> Restore was tested</label><div class="toolbar"><button class="btn btn-primary" data-add>Add record</button></div>' +
				table([['Date', function (x) { return day(x.taken_on); }], ['Type', s('backup_type')], ['Stored at', s('location_note')], ['Restore tested', function (x) { return +x.restore_verified ? badge('Yes', 'ok') : badge('No', 'warn'); }]], r.items, 'No backups recorded yet'));
			box.querySelector('[data-add]').onclick = function () {
				var d = { restore_verified: box.querySelector('[data-b-v]').checked };
				box.querySelectorAll('[data-b]').forEach(function (i) { d[i.getAttribute('data-b')] = i.value; });
				api('workspace/backups', { method: 'POST', body: d }).then(function () { backups(box); }).catch(err);
			};
		});
	}

	/* ------------------------- Agency calendar ------------------------- */
	var calS = { month: null, team_id: '', client_id: '', member_id: '', project_id: '', view: 'consolidated' };
	V.agencyCalendar = function (c) {
		WD.setTitle('Calendar', 'Project, agency and client-visible events. Only events marked client-visible reach Workspace.');
		calS.month = calS.month || S.today.slice(0, 7);
		var y = +calS.month.slice(0, 4), m = +calS.month.slice(5, 7);
		var last = new Date(Date.UTC(y, m, 0)).getUTCDate();
		var from = calS.month + '-01', to = calS.month + '-' + String(last).padStart(2, '0');
		var projects = (S.projects || []).map(function (p) { return { id: p.id, name: p.name }; });
		c.innerHTML = WD.calendarTabs(calS.view) + '<div class="toolbar"><button class="btn btn-sm" data-m="-1" aria-label="Previous month">‹</button><b>' + esc(new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('en-IN', { month: 'long', year: 'numeric', timeZone: 'UTC' })) + '</b><button class="btn btn-sm" data-m="1" aria-label="Next month">›</button><span class="spacer"></span>' +
			'<select data-cf="team_id" aria-label="Team">' + options(teams(), calS.team_id, 'All teams') + '</select><select data-cf="project_id" aria-label="Project">' + options(projects, calS.project_id, 'All projects') + '</select><select data-cf="member_id" aria-label="Assignee">' + options(people(), calS.member_id, 'All assignees') + '</select><select data-cf="client_id" aria-label="Client">' + options(clients(), calS.client_id, 'All clients') + '</select><button class="btn btn-sm btn-primary" data-new-ev>Add event</button></div><div data-cal>' + ui.loading(6) + '</div>';
		c.querySelectorAll('[data-m]').forEach(function (b) {
			b.onclick = function () { var d = new Date(Date.UTC(y, m - 1 + +b.getAttribute('data-m'), 1)); calS.month = d.toISOString().slice(0, 7); V.agencyCalendar(c); };
		});
		c.querySelectorAll('[data-cf]').forEach(function (i) { i.onchange = function () { calS[i.getAttribute('data-cf')] = i.value; V.agencyCalendar(c); }; });
		c.querySelector('[data-new-ev]').onclick = function () { eventModal(null, function () { V.agencyCalendar(c); }); };
		var p = { from: from, to: to, view: calS.view };
		['team_id', 'project_id', 'member_id', 'client_id'].forEach(function (k) { if (calS[k]) p[k] = calS[k]; });
		return api('workspace/calendar', { params: p }).then(function (r) {
			var byDay = {};
			r.items.forEach(function (e) { (byDay[e.date] = byDay[e.date] || []).push(e); });
			var days = Object.keys(byDay).sort();
			var tone = { task: 'info', event: 'ok', request: 'warn', deliverable: 'muted' };
			var box = c.querySelector('[data-cal]');
			if (!box) return;
			box.innerHTML = days.length ? days.map(function (d) {
				return '<section class="card wsa-day"><div class="card-h"><h2>' + esc(fmt.date(d, true)) + '</h2></div><div class="card-b">' + byDay[d].map(function (e) {
					var link = e.task_id ? '#/tasks/' + e.task_id : e.request_id ? '#/tasks/requests' : '';
					var title = e.kind === 'event' ? '<a href="#" data-ev="' + e.event_id + '">' + esc(e.title) + '</a>' : link ? '<a href="' + link + '">' + esc(e.title) + '</a>' : esc(e.title);
					return '<div class="wsa-ev">' + badge(e.kind, tone[e.kind]) + ' ' + title + (e.starts_at ? ' <span class="muted small">' + esc(e.starts_at.slice(11, 16)) + '</span>' : '') + (e.visibility === 'client' ? ' ' + badge('Client-visible', 'warn') : '') + '</div>';
				}).join('') + '</div></section>';
			}).join('') : ui.emptyState('Nothing this month', 'Change the month or filters, or add an event.', WD.I.cal);
			box.querySelectorAll('[data-ev]').forEach(function (a) {
				a.onclick = function (e) { e.preventDefault(); eventModal(r.items.filter(function (x) { return x.event_id === +a.getAttribute('data-ev'); })[0], function () { V.agencyCalendar(c); }); };
			});
		});
	};

	function eventModal(ev, done) {
		ev = ev || {};
		var projects = (S.projects || []).map(function (p) { return { id: p.id, name: p.name }; });
		var mayShare = can('ndwd_manage_workspace');
		var m = modal({
			title: ev.event_id ? 'Edit event' : 'Add event',
			body: '<div class="form-grid">' + field('Title', '<input data-e="title" value="' + esc(ev.title || '') + '">', '', 'span-2') +
				field('Type', '<select data-e="event_type">' + [['meeting', 'Team meeting'], ['client_meeting', 'Client meeting'], ['milestone', 'Milestone'], ['deliverable', 'Deliverable date'], ['internal', 'Internal']].map(function (o) { return '<option value="' + o[0] + '"' + (ev.event_type === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select>') +
				field('Visibility', '<select data-e="visibility"><option value="internal">Internal only</option>' + (mayShare ? '<option value="client"' + (ev.visibility === 'client' ? ' selected' : '') + '>Client-visible (sent to Workspace)</option>' : '') + '</select>') +
				field('Starts', '<input type="datetime-local" data-e="starts_at" value="' + esc((ev.starts_at || '').replace(' ', 'T').slice(0, 16)) + '">') +
				field('Ends', '<input type="datetime-local" data-e="ends_at" value="' + esc((ev.ends_at || '').replace(' ', 'T').slice(0, 16)) + '">') +
				field('Client', '<select data-e="client_id">' + options(clients(), ev.client_id, 'None') + '</select>') + field('Project', '<select data-e="project_id">' + options(projects, ev.project_id, 'None') + '</select>') +
				field('Team', '<select data-e="team_id">' + options(teams(), ev.team_id, 'None') + '</select>') + field('Member', '<select data-e="member_id">' + options(people(), ev.member_id, 'None') + '</select>') +
				field('Description', '<textarea data-e="description" rows="2">' + esc(ev.description || '') + '</textarea>', '', 'span-2') + '</div>',
			foot: (ev.event_id ? '<button class="btn" data-del>Delete</button>' : '') + '<button class="btn btn-primary" data-sv>Save</button>'
		});
		var root = m.el;
		root.querySelector('[data-sv]').onclick = function () {
			var d = {};
			root.querySelectorAll('[data-e]').forEach(function (i) { d[i.getAttribute('data-e')] = i.value; });
			api('workspace/events' + (ev.event_id ? '/' + ev.event_id : ''), { method: ev.event_id ? 'PUT' : 'POST', body: d }).then(function () { if (m.close) m.close(); done(); }).catch(err);
		};
		var del = root.querySelector('[data-del]');
		if (del) del.onclick = function () { api('workspace/events/' + ev.event_id, { method: 'DELETE' }).then(function () { if (m.close) m.close(); done(); }).catch(err); };
	}

	/* ------------------------- Operations panel on My Dashboard ------------------------- */
	var baseDashboard = V.dashboard;
	var inr = function (n) { return '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 }); };
	function tile(label, value, href) {
		return '<a class="wsa-tile" href="' + esc(href || '#') + '"><span class="wsa-tile-v">' + esc(value) + '</span><span class="muted small">' + esc(label) + '</span></a>';
	}
	V.dashboard = function (c, o) {
		var res = baseDashboard(c, o);
		return Promise.resolve(res).then(function () {
			return api('workspace/ops').then(function (d) {
				if (!c.isConnected || d.scope === 'none') return;
				var tiles = '';
				if (d.scope === 'all') {
					tiles += tile('Total clients', d.total_clients, '#/clients') + tile('Active agency clients', d.active_agency_clients, can('ndwd_manage_workspace') ? '#/workspace/clients' : '#') + tile('Active projects', d.active_projects, '#/admin/projects') + tile('Pending work requests', d.pending_requests, can('ndwd_manage_workspace') ? '#/tasks/requests' : '#') + tile('Completed projects', d.completed_projects, '#/admin/projects');
				}
				tiles += tile('Active today', d.active_today, '#/tasks') + tile('Due today', d.due_today, '#/tasks') + tile('Overdue work', d.overdue, '#/tasks') + tile('Urgent', d.urgent, '#/tasks') + tile('Upcoming, 7 days', d.upcoming, '#/tasks') + tile('Completion', d.completion + '%', '#/work-overview');
				if (d.pending_enquiries !== null && d.pending_enquiries !== undefined) tiles += tile('Pending enquiries', d.pending_enquiries, '#/enquiries');
				d.tasks_by_status.forEach(function (s2) { tiles += tile(s2.label, s2.count, '#/work-overview'); });
				if (d.finance) {
					tiles += tile('Invoiced this month', inr(d.finance.invoiced_this_month), '#/accounts/invoices') + tile('Received this month', inr(d.finance.received_this_month), '#/accounts/income') + tile('Pending payments', inr(d.finance.pending_payments), '#/accounts/invoices');
				}
				var lists = '<div class="wsa-ops-grid">' +
					card('Team workload', d.workload.length ? d.workload.map(function (w) { return '<div class="wsa-row"><span>' + esc(w.name) + '</span><b>' + w.open_tasks + '</b></div>'; }).join('') : '<p class="muted">No open assigned tasks.</p>') +
					card('Upcoming deadlines', d.deadlines.length ? d.deadlines.map(function (t) { return '<div class="wsa-row"><a href="#/tasks/' + t.id + '">' + esc(t.title) + '</a><span class="muted small">' + esc(fmt.date(t.due_date, true)) + '</span></div>'; }).join('') : '<p class="muted">No upcoming deadlines.</p>') +
					(d.package_usage ? card('Package usage', d.package_usage.length ? d.package_usage.map(function (a) { return '<div class="wsa-row"><span>' + esc(a.client_name) + ' · ' + esc(a.name) + '</span><span class="muted small">' + a.items.map(function (i) { return esc(i.service_name) + ' ' + i.remaining + ' left'; }).join(', ') + '</span></div>'; }).join('') : '<p class="muted">No running packages.</p>') : '') +
					(d.recent_activity ? card('Recent activity', d.recent_activity.map(function (a) { return '<div class="wsa-row"><span>' + esc(a.who) + ' ' + esc(String(a.action).replace(/_/g, ' ')) + (a.task_id ? ' <a href="#/tasks/' + a.task_id + '">#' + a.task_id + '</a>' : '') + '</span><span class="muted small">' + dt(a.at) + '</span></div>'; }).join('') || '<p class="muted">No activity yet.</p>') : '') +
					'</div>';
				var el = document.createElement('div');
				el.className = 'wsa-ops';
				el.innerHTML = '<h2 class="wsa-ops-h">Agency overview' + (d.scope === 'teams' ? ' (your teams)' : '') + '</h2><div class="wsa-tiles">' + tiles + '</div>' + lists;
				c.appendChild(el);
			}).catch(function () { /* The personal dashboard above stays usable if this panel fails. */ });
		});
	};

	WD.wsa = { card: card, table: table, badge: badge, field: field, day: day, dt: dt, err: err, statusBadge: statusBadge, stateBadge: stateBadge, wcBadge: wcBadge, toneOf: function (s) { return WC_TONE[s] || 'muted'; }, options: options, clients: clients, teams: teams, people: people, permBoxes: permBoxes, readPerms: readPerms, featureRows: featureRows, readFeatures: readFeatures, routingFields: routingFields, getSettings: getSettings, requests: requests, deliverables: deliverables };

	/* Styles for this module only; reuses the app's tokens so light and dark both work. */
	var st = document.createElement('style');
	st.textContent = '.wsa-shell{--wsa-brand:#3f56e0;--wsa-soft:rgba(91,108,245,.12);display:grid;grid-template-columns:220px minmax(0,1fr);gap:20px;align-items:start}.wsa-menu{position:sticky;top:12px;display:flex;flex-direction:column;gap:2px;padding:8px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm)}.wsa-menu a{padding:9px 12px;border-radius:8px;color:var(--text-2);font-size:13px;text-decoration:none}.wsa-menu a:hover{background:var(--surface-3)}.wsa-menu a.on{background:var(--wsa-soft);color:var(--wsa-brand);font-weight:600}.wsa-main{min-width:0}.wsa-shell .btn-primary,.wsa-panel .btn-primary,body[data-wsa] .overlay .btn-primary{background:linear-gradient(135deg,#3b9fd1,#5b6cf5);box-shadow:none}.wsa-subtabs{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:14px;border-bottom:1px solid var(--border)}.wsa-subtabs a{padding:8px 12px;font-size:13px;color:var(--text-2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px}.wsa-subtabs a.on{color:#3f56e0;border-color:#5b6cf5;font-weight:600}.wsa-crumb{display:flex;gap:8px;align-items:center;margin-bottom:14px;font-size:13px}.wsa-sec{padding:18px 0;border-bottom:1px solid var(--border)}.wsa-sec:first-child{padding-top:0}.wsa-sec:last-child{border-bottom:0;padding-bottom:0}.wsa-sec h3{display:flex;gap:10px;align-items:center;font-size:14px;margin:0 0 8px}.wsa-num{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;background:rgba(91,108,245,.14);color:#3f56e0;font-size:12px;font-weight:700}.wsa-more{margin-top:10px}.wsa-more>summary{cursor:pointer;font-weight:600;font-size:13px;padding:6px 0}.wsa-inc{padding:8px 10px;background:var(--surface-3);border-radius:8px;font-size:12px}.wsa-static{padding:9px 0;font-weight:600}.wsa-acts{white-space:normal;min-width:180px}.wsa-acts .btn{margin:2px 0}.btn-link{background:none;border:0;padding:0;color:var(--link);cursor:pointer}.wsa-ent>summary{cursor:pointer;list-style-position:inside}.wsa-ent-w{padding:0 0 16px}.wsa-c-info{margin-top:10px}.wsa-facts{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px 20px}.wsa-facts>div{min-width:0;overflow-wrap:anywhere}.wsa-facts span:first-child{display:block;font-size:11px;font-weight:600;color:var(--muted);margin-bottom:2px}.wsa-panel{padding:10px 16px;border-bottom:1px solid var(--border)}.wsa-panel>summary{cursor:pointer;font-weight:600;padding-bottom:6px}.wsa-req{padding:10px;background:var(--surface-3);border-radius:8px;margin-bottom:10px}.wsa-req textarea,.wsa-req input{margin-top:6px}.wsa-steps{display:flex;flex-wrap:wrap;gap:4px;margin:6px 0}.wsa-steps span{padding:2px 8px;border-radius:999px;background:var(--surface);color:var(--muted);font-size:11px}.wsa-steps span.on{background:rgba(91,108,245,.14);color:#3f56e0;font-weight:600}.wsa-chat{margin:10px 0}.wsa-thread{max-height:280px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;margin-bottom:8px}.wsa-msg{padding:8px 10px;border-radius:10px;background:var(--surface-3);align-self:flex-start;max-width:88%;white-space:pre-wrap}.wsa-msg.me{align-self:flex-end;background:rgba(91,108,245,.12)}.wsa-msg.int{background:var(--st-pending-bg)}.wsa-msg-h{font-size:11px;margin-bottom:2px}.wsa-dform{margin:8px 0}@media (max-width:900px){.wsa-shell{grid-template-columns:minmax(0,1fr)}.wsa-menu{position:static;flex-direction:row;flex-wrap:wrap}.wsa-menu a{padding:7px 10px}.form-grid.cols-3{grid-template-columns:minmax(0,1fr)}}.wsa-sort{background:none;border:0;padding:0;font:inherit;font-weight:600;color:inherit;cursor:pointer;white-space:nowrap}.wsa-act{min-width:110px;width:auto}.wsa-calviews{flex-wrap:wrap}.ws-card-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:4px 24px}' +
		'.wsa-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:var(--surface-3);color:var(--text-2);text-transform:capitalize}.wsa-ok{background:rgba(91,108,245,.14);color:#3f56e0}.wsa-warn{background:var(--st-pending-bg);color:var(--st-pending)}.wsa-bad{background:var(--st-rejected-bg);color:var(--st-rejected)}.wsa-info{background:var(--st-process-bg);color:var(--st-process)}' +
		'.wsa-gap{height:16px}.wsa-h3{font-size:13px;margin:18px 0 8px}.wsa-perms{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center}.wsa-check{display:inline-flex;gap:6px;align-items:center;font-size:13px;margin:4px 0}.wsa-check input{width:auto;min-height:0}.wsa-day{margin-bottom:10px}.wsa-ev{padding:4px 0}' +
		'.wsa-ops{margin-top:20px}.wsa-ops-h{font-size:15px;margin:0 0 10px}.wsa-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:14px}.wsa-tile{display:flex;flex-direction:column;gap:2px;padding:12px;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);text-decoration:none;color:var(--text)}.wsa-tile-v{font-size:20px;font-weight:700}.wsa-ops-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.wsa-row{display:flex;justify-content:space-between;gap:10px;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px}.wsa-row:last-child{border-bottom:0}';
	document.head.appendChild(st);
})();
