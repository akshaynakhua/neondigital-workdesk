/* NeonDigital WorkDesk: website enquiries, onboarding settings, client visibility on tasks, Neon Workspace integration settings. */
(function () {
	'use strict';
	var WD = window.WD, S = WD.S, api = WD.api, V = WD.views;
	var esc = WD.u.esc, can = WD.u.can, ui = WD.ui, fmt = WD.fmt;
	var toast = WD.toast || function () {};
	var fail = function (e) { toast(e && e.message ? e.message : String(e), 'err'); };
	var dt = function (v) { return v ? (fmt && fmt.dt ? fmt.dt(v) : String(v)) : '—'; };
	var card = function (title, body) { return '<section class="card section-gap"><div class="card-h"><h2>' + esc(title) + '</h2></div><div class="card-b">' + body + '</div></section>'; };

	/* ---- Enquiries ---- */
	var enqS = { status: 'open', owner: '', q: '', page: 1 };
	V.enquiries = function (c) {
		WD.setTitle('Enquiries', 'Enquiries from the NeonDigital website');
		c.innerHTML = ui.loading(6);
		return api('enquiries', { params: enqS }).then(function (r) {
			var st = Object.keys(r.statuses).map(function (k) { return '<option value="' + k + '"' + (enqS.status === k ? ' selected' : '') + '>' + esc(r.statuses[k]) + '</option>'; }).join('');
			c.innerHTML = '<div class="toolbar"><select data-f="status" style="width:auto" data-native><option value="open"' + (enqS.status === 'open' ? ' selected' : '') + '>Open</option><option value=""' + (!enqS.status ? ' selected' : '') + '>All</option>' + st + '</select><select data-f="owner" style="width:auto" data-native><option value="">Everyone</option><option value="unassigned"' + (enqS.owner === 'unassigned' ? ' selected' : '') + '>Unassigned</option><option value="mine"' + (enqS.owner === 'mine' ? ' selected' : '') + '>Mine</option></select><input data-f="q" placeholder="Search" value="' + esc(enqS.q) + '" style="width:auto"><span class="spacer"></span>' + (r.is_manager ? '<button class="btn btn-sm" data-set>Form settings</button>' : '') + '</div>' +
				'<section class="card"><div class="card-b">' + (r.items.length ? r.items.map(function (e) {
					return '<div class="row-item" data-o="' + e.id + '" style="display:flex;gap:10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--border,#e5e7eb);cursor:pointer"><div style="flex:1;min-width:0"><b>' + esc(e.name) + '</b>' + (e.company ? ' <span class="muted small">' + esc(e.company) + '</span>' : '') + '<div class="muted small">' + esc(e.service || e.message.slice(0, 80)) + '</div></div><span class="badge">' + esc(e.status_label) + '</span><span class="muted small">' + esc(e.owner_name || 'Unassigned') + '</span></div>';
				}).join('') : '<p class="muted">No enquiries here.</p>') +
				(r.pages > 1 ? '<div class="toolbar"><button class="btn btn-sm" data-pg="-1"' + (r.page <= 1 ? ' disabled' : '') + '>Previous</button><span class="muted small">Page ' + r.page + ' of ' + r.pages + '</span><button class="btn btn-sm" data-pg="1"' + (r.page >= r.pages ? ' disabled' : '') + '>Next</button></div>' : '') + '</div></section>';
			c.querySelectorAll('[data-f]').forEach(function (i) { i.onchange = function () { enqS[i.getAttribute('data-f')] = i.value; enqS.page = 1; V.enquiries(c); }; });
			c.querySelectorAll('[data-pg]').forEach(function (b) { b.onclick = function () { enqS.page += +b.getAttribute('data-pg'); V.enquiries(c); }; });
			c.querySelectorAll('[data-o]').forEach(function (d) { d.onclick = function () { enquiry(+d.getAttribute('data-o'), c); }; });
			var s = c.querySelector('[data-set]');
			if (s) s.onclick = function () { enquirySettings(c); };
		});
	};
	function people() {
		return (S.members || S.users || []).map(function (u) { return '<option value="' + u.id + '">' + esc(u.name) + '</option>'; }).join('');
	}
	function enquiry(id, list) {
		api('enquiries/' + id).then(function (e) {
			var m = WD.modal({ title: e.name, wide: true });
			var box = m.body || m.el.querySelector('.modal-b') || m.el;
			var draw = function (x) {
				e = x;
				var op = Object.keys({ picked_up: 1, contacted: 1, follow_up: 1, qualified: 1, won: 1, lost: 1 });
				box.innerHTML = '<p>' + esc(e.email) + (e.email && e.phone ? ' · ' : '') + esc(e.phone) + '</p>' + (e.company ? '<p class="muted small">' + esc(e.company) + '</p>' : '') + (e.service ? '<p><b>Service:</b> ' + esc(e.service) + '</p>' : '') + (e.message ? '<p>' + esc(e.message) + '</p>' : '') +
					'<p class="muted small">Status: <b>' + esc(e.status_label) + '</b> · Owner: <b>' + esc(e.owner_name || 'Nobody') + '</b>' + (e.client_id ? ' · <a href="#/clients">Client #' + e.client_id + '</a>' : '') + '</p>' +
					'<div class="toolbar">' + (!e.owner_id ? '<button class="btn btn-primary btn-sm" data-pick>Pick up</button>' : '') + (e.can_act ? '<select data-st data-native style="width:auto"><option value="">Change status</option>' + op.map(function (s) { return '<option value="' + s + '">' + s.replace('_', ' ') + '</option>'; }).join('') + '</select><input type="date" data-fu value="' + esc(e.follow_up_on || '') + '" aria-label="Follow-up date" style="width:auto"><button class="btn btn-sm" data-con>Log contact</button>' : '') + (e.can_act && e.status === 'won' ? '<button class="btn btn-sm btn-primary" data-onb>Send Client Onboarding Form</button>' : '') + (e.can_act ? '<select data-own data-native style="width:auto" aria-label="Assign to"><option value="">Assign to…</option><option value="0">Nobody (back to the queue)</option>' + people() + '</select>' : '') + (e.client_id && can('ndwd_manage_workspace') ? '<a class="btn btn-sm" href="#/clients/' + e.client_id + '">Open client to send a Workspace invitation</a>' : '') + '</div>' +
					(e.onboarding ? '<p class="muted small">Onboarding: ' + (e.onboarding.submitted_at ? 'completed ' + esc(dt(e.onboarding.submitted_at)) : 'sent to ' + esc(e.onboarding.email) + ', expires ' + esc(dt(e.onboarding.expires_at))) + '</p>' : '') +
					'<label class="field"><span>Internal remark</span><textarea data-rem rows="2"></textarea></label><button class="btn btn-sm" data-rs>Add remark</button>' +
					'<h3 style="margin-top:14px">History</h3>' + e.history.map(function (h) { return '<div style="padding:6px 0;border-bottom:1px solid var(--border,#e5e7eb)"><b>' + esc(h.who) + '</b> <span class="muted small">' + esc(h.type.replace('_', ' ')) + (h.to ? ': ' + (h.from ? esc(h.from) + ' → ' : '') + esc(h.to) : '') + ' · ' + esc(dt(h.at)) + '</span>' + (h.note ? '<div class="small">' + esc(h.note) + '</div>' : '') + '</div>'; }).join('');
				var q = function (s) { return box.querySelector(s); };
				var after = function (r) { draw(r); V.enquiries(list); };
				if (q('[data-pick]')) q('[data-pick]').onclick = function () { api('enquiries/' + id + '/pickup', { method: 'POST', body: {} }).then(after).catch(fail); };
				if (q('[data-st]')) q('[data-st]').onchange = function (ev) { if (ev.target.value) api('enquiries/' + id, { method: 'PUT', body: { status: ev.target.value } }).then(after).catch(fail); };
				if (q('[data-own]')) q('[data-own]').onchange = function (ev) { if (ev.target.value !== '') api('enquiries/' + id, { method: 'PUT', body: { owner_id: +ev.target.value } }).then(after).catch(fail); };
				if (q('[data-fu]')) q('[data-fu]').onchange = function (ev) { api('enquiries/' + id, { method: 'PUT', body: { follow_up_on: ev.target.value } }).then(after).catch(fail); };
				if (q('[data-con]')) q('[data-con]').onclick = function () { var n = window.prompt('What happened on this contact attempt?'); if (n !== null) api('enquiries/' + id + '/contact', { method: 'POST', body: { channel: 'call', note: n } }).then(after).catch(fail); };
				if (q('[data-onb]')) q('[data-onb]').onclick = function () { api('enquiries/' + id + '/onboarding', { method: 'POST', body: {} }).then(function (r) { window.prompt(r.emailed ? 'Sent by email. Link:' : 'Email was not sent. Share this link:', r.url); return api('enquiries/' + id); }).then(after).catch(fail); };
				q('[data-rs]').onclick = function () { var t = q('[data-rem]').value; api('enquiries/' + id + '/remarks', { method: 'POST', body: { note: t } }).then(after).catch(fail); };
			};
			draw(e);
		}).catch(fail);
	}
	function enquirySettings(list) {
		api('enquiries/settings').then(function (s) {
			var m = WD.modal({ title: 'Enquiry and onboarding settings', wide: true });
			var box = m.body || m.el;
			box.innerHTML = '<h3>Website connection</h3><p class="small">Post enquiries to <code>' + esc(s.endpoint) + '</code>, signed as described in the integration guide. Secret: ' + (s.has_key ? 'stored encrypted' : 'not created') + '.</p><button class="btn btn-sm" data-rot>' + (s.has_key ? 'Rotate secret' : 'Create secret') + '</button><div data-key></div><h3 style="margin-top:14px">Onboarding form fields</h3>' +
				s.fields.map(function (f, i) { var core = ['business_name', 'contact_person', 'email', 'phone'].indexOf(f.key) > -1; return '<div class="toolbar" data-row="' + i + '"><input data-l value="' + esc(f.label) + '" aria-label="Label" style="flex:1"><label class="small"><input type="checkbox" data-on' + (f.on ? ' checked' : '') + (core ? ' disabled' : '') + '> Show</label><label class="small"><input type="checkbox" data-rq' + (f.required ? ' checked' : '') + (core ? ' disabled' : '') + '> Required</label></div>'; }).join('') + '<button class="btn btn-sm btn-primary" data-sv>Save fields</button>';
			box.querySelector('[data-rot]').onclick = function () {
				if (s.has_key && !window.confirm('The old secret stops working at once. Continue?')) return;
				api('enquiries/settings/rotate', { method: 'POST', body: {} }).then(function (r) { box.querySelector('[data-key]').innerHTML = '<div class="alert">Copy now. It is shown once.<br><code>' + esc(r.secret) + '</code></div>'; }).catch(fail);
			};
			box.querySelector('[data-sv]').onclick = function () {
				var out = s.fields.map(function (f, i) { var r = box.querySelector('[data-row="' + i + '"]'); return { key: f.key, type: f.type, label: r.querySelector('[data-l]').value, on: r.querySelector('[data-on]').checked, required: r.querySelector('[data-rq]').checked }; });
				api('enquiries/settings', { method: 'POST', body: { fields: out } }).then(function () { toast('Saved'); }).catch(fail);
			};
		}).catch(fail);
	}

	/* ---- Client visibility and comments in the task drawer ---- */
	var LABELS = [['visible', 'Visible to Client / Send to Client'], ['show_description', 'Client-safe description'], ['show_assignee', 'Assignee'], ['show_deadline', 'Deadline'], ['show_status', 'Status'], ['show_comments', 'Client-visible comments'], ['show_calendar', 'Calendar entry'], ['show_handoff', 'Handoff updates']];
	var rid = function () { return 'wd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12); };

	/* The same switches on the real Create Task and Edit Task forms. Off by default. */
	WD.taskVisibilityFields = function () {
		return '<div class="field span-2" data-tcv hidden><label class="check"><input type="checkbox" name="cv_visible"> <b>Visible to Client / Send to Client</b></label>' +
			'<div data-tcv-more hidden><p class="muted small">Choose what the client sees. Internal remarks and internal comments are never sent.</p><div class="toolbar" style="flex-wrap:wrap">' + LABELS.slice(1).map(function (l) { return '<label class="small"><input type="checkbox" name="cv_' + l[0] + '"> ' + l[1] + '</label>'; }).join('') + '</div>' +
			'<label class="field" data-tcv-desc hidden><span>Client-safe description <span class="hint">(this wording goes to the client, not the description above)</span></span><textarea name="cv_description" rows="2"></textarea></label></div></div>';
	};
	WD.bindTaskVisibility = function (form, taskId) {
		var box = form.querySelector('[data-tcv]'), more = box.querySelector('[data-tcv-more]'), desc = box.querySelector('[data-tcv-desc]');
		var ready = !taskId;
		var paint = function () {
			box.hidden = !form.client_id.value;
			more.hidden = !form.cv_visible.checked;
			desc.hidden = !form.cv_show_description.checked;
		};
		form.client_id.addEventListener('change', paint);
		form.cv_visible.addEventListener('change', function () {
			// Turning it on shows the client the task's progress. They can be narrowed one by one.
			if (form.cv_visible.checked && !LABELS.slice(1).some(function (l) { return form['cv_' + l[0]].checked; })) {
				['show_status', 'show_assignee', 'show_deadline', 'show_comments', 'show_calendar'].forEach(function (k) { form['cv_' + k].checked = true; });
			}
			paint();
		});
		form.cv_show_description.addEventListener('change', paint);
		paint();
		if (taskId) {
			api('workspace/tasks/' + taskId + '/client').then(function (r) {
				form.cv_visible.checked = !!r.flags.visible;
				LABELS.slice(1).forEach(function (l) { form['cv_' + l[0]].checked = !!r.flags[l[0]]; });
				form.cv_description.value = r.flags.client_description || '';
				if (!r.can_edit) box.querySelectorAll('input,textarea').forEach(function (i) { i.disabled = true; });
				ready = !!r.can_edit;
				paint();
			}).catch(function () { ready = false; box.hidden = true; });
		}
		return {
			loaded: function () { return ready; },
			value: function () {
				var o = { visible: form.cv_visible.checked, client_description: form.cv_description.value };
				LABELS.slice(1).forEach(function (l) { o[l[0]] = form['cv_' + l[0]].checked; });
				return o;
			}
		};
	};
	/* ---- Neon Workspace section on a client ---- */
	var WC_TONE = { not_connected: '', invitation_pending: 'badge-info', connected: 'badge-ok', active: 'badge-ok', renewal_due: 'badge-warn', grace: 'badge-warn', locked: 'badge-bad', suspended: 'badge-bad', disconnected: '' };
	WD.clientWorkspaceCard = function (box, clientId) {
		var reload = function () { return WD.clientWorkspaceCard(box, clientId); };
		return api('workspace/clients/' + clientId + '/status').then(function (x) {
			var kv = function (k, v) { return '<div><span>' + esc(k) + '</span>' + v + '</div>'; };
			var dash = '<span class="muted">Not set</span>';
			var day = function (v) { return v ? esc(fmt.date(v, true)) : dash; };
			var acts = WD.wsActions(x);
			var main = acts.filter(function (a) { return a[0] === 'manage'; })[0];
			var rest = acts.filter(function (a) { return ['suspend', 'reactivate', 'disconnect', 'reconnect', 'btype', 'invite', 'revoke'].indexOf(a[0]) > -1; });
			// Simplified (1.19): who, what type, which status and whether sync is healthy. Packages are per project.
				box.innerHTML = '<div class="card-h"><h2>Neon Workspace</h2> <span class="badge ' + (WC_TONE[x.status] || '') + '">' + esc(x.status_label) + '</span><span class="spacer"></span>' + (main ? '<button class="btn btn-sm" data-wsa="manage">Workspace details</button>' : '') + '</div><div class="card-b"><div class="wsa-facts">' +
				kv(x.workspaces && x.workspaces.length > 1 ? 'Workspaces (' + x.workspaces.length + ')' : 'Workspace', x.workspaces && x.workspaces.length > 1 ? x.workspaces.map(function (w) { return '<a href="#/workspace/clients/' + w.id + '">' + esc(w.name || 'Not linked yet') + '</a> <span class="muted small">' + esc(w.status) + '</span>'; }).join('<br>') : esc(x.workspace_name || 'Not linked')) +
				kv('Business type', '<span data-btype class="muted">…</span>') +
				(x.status === 'invitation_pending' ? kv('Invitation', esc(x.invited_email || '') + (x.invitation_expiry ? ' <span class="muted small">until ' + esc(dt(x.invitation_expiry)) + '</span>' : '')) : '') +
				kv('Last successful sync', x.last_sync ? esc(dt(x.last_sync)) : dash) + kv('Connection health', esc(x.health.label) + (x.health.failed_24h ? ' <span class="muted small">' + (+x.health.failed_24h) + ' failed in 24 hours</span>' : '')) + '</div>' +
				(x.status === 'not_connected' ? '<p class="muted small" style="margin-top:8px">Workspaces and packages are assigned per project. Open a project above and choose <b>New Workspace for this project</b>.</p>' : '') +
				'<div class="toolbar" style="margin-top:10px;flex-wrap:wrap">' + rest.map(function (a) { return '<button class="btn btn-sm" data-wsa="' + a[0] + '">' + esc(a[1]) + '</button>'; }).join('') + (x.has_link && x.workspace_url ? '<a class="btn btn-sm" href="' + esc(x.workspace_url) + '/app/" target="_blank" rel="noopener noreferrer">Open Workspace</a>' : '') + '</div></div>';
			box.querySelectorAll('[data-wsa]').forEach(function (b) { b.onclick = function () { WD.wsDo(b.getAttribute('data-wsa'), x, reload); }; });
				var bt = box.querySelector('[data-btype]');
				if (bt && x.connection_id) api('workspace/connections/' + x.connection_id + '/business-type').then(function (i) { bt.className = ''; bt.textContent = i.current_label; }).catch(function () { bt.textContent = '—'; });
				else if (bt) bt.textContent = '—';
		}).catch(function (e) { box.innerHTML = '<div class="card-b muted small">' + esc(e.message) + '</div>'; });
	};

	/* ---- Change Business Type: current type, new type, reason, client consent, preview, confirm ---- */
	WD.businessTypeFlow = function (connId, done) {
		api('workspace/connections/' + connId + '/business-type').then(function (info) {
			var opts = Object.keys(info.types).filter(function (k) { return k !== info.current; }).map(function (k) { return '<option value="' + k + '">' + esc(info.types[k]) + '</option>'; }).join('');
			var m = WD.modal({ title: 'Change business type', size: 'lg', body: '<p>Current business type: <b>' + esc(info.current_label) + '</b></p>' +
				'<div class="form-grid"><label class="field"><span>New business type</span><select data-to><option value="">Choose</option>' + opts + '</select></label><label class="field"><span>Client consent given by</span><input data-by placeholder="Name of the person at the client"></label>' +
				'<label class="field"><span>Consent date and time</span><input type="datetime-local" data-at></label><label class="field"><span>How they agreed</span><input data-note placeholder="Email, call, meeting…"></label>' +
				'<label class="field span-2"><span>Reason for the change</span><textarea data-reason rows="2"></textarea></label></div><label class="check"><input type="checkbox" data-cons> The client has agreed to this change and understands what is removed.</label>' +
				'<div data-pv></div><div class="alert alert-error hidden" data-e></div>' +
				(info.history.length ? '<details style="margin-top:10px"><summary class="small">History (' + info.history.length + ')</summary>' + info.history.map(function (h) { return '<div class="small" style="padding:4px 0;border-bottom:1px solid var(--line,#ddd)"><b>' + esc(h.from_label) + ' → ' + esc(h.to_label) + '</b> · ' + esc(h.status) + ' · ' + esc(dt(h.created_at)) + ' by ' + esc(h.staff) + '<br><span class="muted">Reason: ' + esc(h.reason) + ' · consent: ' + esc(h.consent_by) + (h.error ? ' · ' + esc(h.error) : '') + '</span>' + (h.status === 'completed' ? ' <button class="btn btn-sm" data-undo="' + h.id + '">Restore previous type</button>' : '') + '</div>'; }).join('') + '</details>' : ''),
				foot: '<button class="btn" data-close>Cancel</button><button class="btn" data-prev>Preview changes</button><button class="btn btn-danger" data-go disabled>Change business type</button>' });
			var r = m.el, q = function (s) { return r.querySelector(s); }, showErr = function (x) { q('[data-e]').textContent = x.message; q('[data-e]').classList.remove('hidden'); };
			q('[data-prev]').onclick = function () {
				q('[data-e]').classList.add('hidden');
				if (!q('[data-to]').value) { showErr(new Error('Choose the new business type.')); return; }
				api('workspace/connections/' + connId + '/business-type/preview', { method: 'POST', body: { to: q('[data-to]').value } }).then(function (p) {
					var li = function (x) { return '<li>' + esc(x.label) + ' <b>(' + x.count + ')</b></li>'; };
					q('[data-pv]').innerHTML = '<div class="grid grid-2" style="margin-top:10px"><section><h3>Removed from the Workspace</h3><ul>' + (p.remove.map(li).join('') || '<li>Nothing</li>') + '</ul></section><section><h3>Kept</h3><ul>' + p.keep.map(li).join('') + '</ul></section></div><p class="small"><b>New modules:</b> ' + esc(p.to.modules.join(', ')) + '</p><ul class="muted small">' + p.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') + '</ul><label class="field"><span>Type CHANGE to confirm</span><input data-cf></label>';
					q('[data-go]').disabled = false;
				}).catch(showErr);
			};
			q('[data-go]').onclick = function (ev) {
				q('[data-e]').classList.add('hidden');
				var at = q('[data-at]').value;
				WD.busy(ev.target, true);
				api('workspace/connections/' + connId + '/business-type/convert', { method: 'POST', body: { to: q('[data-to]').value, reason: q('[data-reason]').value, client_consent: q('[data-cons]').checked, consent_by: q('[data-by]').value, consent_at: at ? at.replace('T', ' ') + ':00' : '', consent_note: q('[data-note]').value, confirm: (q('[data-cf]') || {}).value } }).then(function (x) {
					m.close(); toast('Business type changed to ' + info.types[x.to] + (x.backup_verified ? '. Backup verified.' : '')); if (done) done();
				}).catch(function (x) { WD.busy(ev.target, false); showErr(x); });
			};
			r.querySelectorAll('[data-undo]').forEach(function (b) { b.onclick = function () {
				WD.confirm('Restore the previous business type?', 'Neon Workspace puts back what this change removed, from its verified backup. Records created since stay but are hidden.', 'Restore', true).then(function (ok) {
					if (ok) api('workspace/connections/' + connId + '/business-type/restore', { method: 'POST', body: { log_id: +b.getAttribute('data-undo') } }).then(function (x) { m.close(); toast('Restored to ' + (info.types[x.category] || x.category)); if (done) done(); }).catch(showErr);
				});
			}; });
		}).catch(fail);
	};

	/* ---- Send to Neon Workspace, on an invoice ---- */
	WD.invoiceWorkspaceAction = function (el, v) {
		var paint = function (st) {
			var sent = st.sent;
			el.innerHTML = '<button class="btn" data-send>' + (sent ? 'Resend to Neon Workspace' : 'Send to Neon Workspace') + '</button>' + (sent ? ' <span class="small muted">' + (sent.delivery_status === 'delivered' ? 'Sent to Neon Workspace' : 'Sending to Neon Workspace, will retry') + ' · ' + esc(dt(sent.sent_at)) + ' by ' + esc(sent.sent_by) + (sent.send_count > 1 ? ' · sent ' + (+sent.send_count) + ' times' : '') + '</span>' : '');
			el.querySelector('[data-send]').onclick = function () {
				if (!st.can_send) { WD.toast(st.reason || 'This invoice cannot be sent.', 'err'); return; }
				WD.confirm((sent ? 'Resend ' : 'Send ') + v.invoice_no + ' to Neon Workspace?', 'This goes to ' + (st.workspace_name || 'the connected Workspace') + ' (' + st.workspace_uid + '). The client sees a read-only copy, and WorkDesk stays the record of it. ' + (sent ? 'The existing copy is updated, not duplicated.' : ''), sent ? 'Resend' : 'Send').then(function (ok) {
					if (!ok) return;
					api('workspace/invoices/' + v.id + '/send', { method: 'POST', body: { confirm_workspace_uid: st.workspace_uid } }).then(function (r) { WD.toast(r.delivered ? 'Sent to Neon Workspace' : 'Queued. It will be retried automatically.'); paint(r); }).catch(fail);
				});
			};
		};
		return api('workspace/invoices/' + v.id + '/send').then(paint).catch(function () { el.innerHTML = ''; });
	};

	/* ---- Settings → Integrations → Neon Workspace ---- */
	V.integrations = function (c) {
		WD.setTitle('Integrations', 'Neon Workspace connection');
		c.innerHTML = ui.loading(5);
		return Promise.all([api('workspace/settings'), api('workspace/settings/health')]).then(function (r) {
			var s = r[0], h = r[1];
			c.innerHTML = '<div class="toolbar"><a class="btn btn-sm" href="#/admin/settings">Back to Settings</a></div>' + card('Neon Workspace',
				'<dl class="kv"><dt>State</dt><dd>' + (s.has_secret && s.workspace_url ? 'Configured' : 'Not configured') + '</dd><dt>API version</dt><dd>' + esc(h.api_version) + '</dd><dt>Last successful sync</dt><dd>' + esc(dt(h.last_sync)) + '</dd><dt>Last webhook delivered</dt><dd>' + esc(dt(h.last_webhook)) + '</dd><dt>Failed requests, 24 hours</dt><dd>' + (+h.failed_requests) + '</dd><dt>Webhooks waiting</dt><dd>' + (+h.pending_webhooks) + '</dd><dt>Connected clients</dt><dd>' + (+h.connections) + '</dd></dl>' +
				'<div class="toolbar"><button class="btn btn-sm" data-test>Test Connection</button><button class="btn btn-sm" data-retry>Retry waiting webhooks</button></div><div data-tr class="small"></div>' +
				'<label class="field"><span>Workspace address</span><input data-url type="url" value="' + esc(s.workspace_url) + '"' + (s.url_from_constant ? ' disabled' : '') + '></label><label class="field"><span>Consolidated data retention (days)</span><input data-ret type="number" min="30" max="3650" value="' + esc(s.retention_days) + '"></label>' +
				'<p class="muted small">Site API key: <code>' + esc(s.site_key || 'not created') + '</code> · secret ' + (s.has_secret ? 'stored encrypted, never shown again' : 'not created') + '. See <code>docs/workspace-integration</code> for the integration guide.</p><div data-cred></div><div class="toolbar"><button class="btn btn-primary btn-sm" data-save>Save</button>' + (s.keys_from_constant ? '' : '<button class="btn btn-sm" data-rot>' + (s.has_secret ? 'Rotate credentials' : 'Create credentials') + '</button>') + '</div>');
			var q = function (x) { return c.querySelector(x); };
			q('[data-test]').onclick = function () { api('workspace/settings/test', { method: 'POST', body: {} }).then(function (x) { q('[data-tr]').textContent = x.message; }).catch(fail); };
			q('[data-retry]').onclick = function () { api('workspace/webhooks/run', { method: 'POST', body: {} }).then(function () { toast('Done'); V.integrations(c); }).catch(fail); };
			q('[data-save]').onclick = function () { api('workspace/settings', { method: 'POST', body: { workspace_url: q('[data-url]').value, retention_days: q('[data-ret]').value } }).then(function () { toast('Saved'); }).catch(fail); };
			var rot = q('[data-rot]');
			if (rot) rot.onclick = function () {
				if (s.has_secret && !window.confirm('The old key stops working at once. Continue?')) return;
				api('workspace/settings/rotate', { method: 'POST', body: {} }).then(function (k) { q('[data-cred]').innerHTML = '<div class="alert">Copy now. It is shown once.<br>Key: <code>' + esc(k.site_key) + '</code><br>Secret: <code>' + esc(k.site_secret) + '</code></div>'; }).catch(fail);
			};
		});
	};
})();
