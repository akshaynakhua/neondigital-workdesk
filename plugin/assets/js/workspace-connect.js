/* NeonDigital WorkDesk: Neon Connect screens. Packages & Services, a client's Workspaces and projects, migrations, subscriptions, entitlements, the invitation form, and the client panel on a task. */
(function () {
	'use strict';
	var WD = window.WD, S = WD.S, api = WD.api, V = WD.views, H = WD.wsa;
	var esc = WD.u.esc, can = WD.u.can, ui = WD.ui, fmt = WD.fmt, toast = WD.toast || function () {};
	var modal = WD.modal, card = H.card, table = H.table, badge = H.badge, field = H.field, day = H.day, dt = H.dt, err = H.err;
	var P = WD.wsaPages;

	var inr = function (n) { return n === null || n === undefined ? 'Price on request' : '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 }); };
	var FREQ = { month: 'Monthly', year: 'Yearly', one_time: 'One-time', agency: 'Agency arrangement' };
	var ACCESS = { active: ['Active', 'ok'], renewal_due: ['Renewal due', 'warn'], grace: ['In grace period', 'warn'], subscribed: ['Own subscription', 'ok'], scheduled: ['Starts later', 'info'], no_package: ['Locked: no package', 'bad'], expired: ['Locked: package ended', 'bad'], migration_expired: ['Locked: grace ended', 'bad'], subscription_lapsed: ['Locked: subscription ended', 'bad'], feature_locked: ['Locked: Connect off', 'bad'] };
	function accessBadge(s) { var a = ACCESS[s] || [s, 'muted']; return badge(a[0], a[1]); }

	/* ------------------------- Attachments (Phase 4) -------------------------
	 * Files on client messages, work requests, project updates and deliverables. They are kept in
	 * WorkDesk's protected storage and fetched through a permission-checked route, never a file URL. */
	var RULES = { types: ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'docx', 'xlsx', 'pptx', 'zip', 'txt', 'csv'], max_total: 5242880, max_files: 5 };
	var extOf = function (n) { var m = /\.([a-z0-9]+)$/i.exec(String(n || '')); return m ? m[1].toLowerCase() : ''; };
	var size = function (n) { n = +n || 0; return n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(n < 10240 ? 1 : 0) + ' KB' : (n / 1048576).toFixed(1) + ' MB'; };
	var icon = function (e) { return { pdf: 'PDF', png: 'IMG', jpg: 'IMG', jpeg: 'IMG', gif: 'IMG', webp: 'IMG', docx: 'DOC', xlsx: 'XLS', pptx: 'PPT', zip: 'ZIP', csv: 'CSV', txt: 'TXT' }[e] || 'FILE'; };
	WD.att = {
		rules: RULES,
		size: size,
		list: function (list, opts) {
			opts = opts || {};
			if (!list || !list.length) return '';
			return '<div class="att-list">' + list.map(function (f) {
				return '<span class="att' + (f.is_internal ? ' att-int' : '') + '"><button type="button" class="att-open" data-att="' + (+f.id) + '" data-att-prev="' + (f.preview ? 1 : 0) + '" title="' + (f.preview ? 'Preview ' : 'Download ') + esc(f.name) + '"><span class="att-ic">' + icon(f.ext || extOf(f.name)) + '</span><span class="att-txt"><b>' + esc(f.name) + '</b><small class="muted">' + esc((f.ext || extOf(f.name)).toUpperCase()) + ' · ' + size(f.size) + (f.uploaded_by_type === 'client' ? ' · from the client' : '') + (f.note ? ' · ' + esc(f.note) : '') + '</small></span></button>' + (f.is_internal ? '<span class="badge muted">Internal only</span>' : '') + (opts.remove ? '<button type="button" class="btn btn-sm btn-ghost" data-att-rm="' + (+f.id) + '" aria-label="Remove ' + esc(f.name) + '">Remove</button>' : '') + '</span>';
			}).join('') + '</div>';
		},
		open: function (id, preview) {
			if (!preview) return WD.download('workspace/attachments/' + (+id), { quiet: true });
			var w = window.open('', '_blank');
			return fetch(NDWD.root + 'workspace/attachments/' + (+id) + '?inline=1', { credentials: 'same-origin', headers: { 'X-WP-Nonce': NDWD.nonce } }).then(function (r) { if (!r.ok) throw new Error('This file is not available.'); return r.blob(); })
				.then(function (b) { var u = URL.createObjectURL(b); if (w) w.location = u; setTimeout(function () { URL.revokeObjectURL(u); }, 60000); })
				.catch(function (e) { if (w) w.close(); toast(e.message, 'error'); });
		},
		/** A picker listing each file with type, size and status. files() → Promise of [{name, data}]. */
		picker: function (host, rules) {
			rules = rules || RULES;
			var chosen = [], state = '', note = '';
			host.innerHTML = '<div class="att-pick"><label class="btn btn-sm att-add"><input type="file" multiple hidden data-af accept="' + rules.types.map(function (t) { return '.' + t; }).join(',') + '">Attach files</label><div data-al></div></div>';
			var input = host.querySelector('[data-af]'), out = host.querySelector('[data-al]');
			var total = function () { return chosen.reduce(function (s, f) { return s + f.size; }, 0); };
			var paint = function () {
				out.innerHTML = chosen.length ? '<div class="att-list att-pending">' + chosen.map(function (f, i) {
					var st = f.error ? '<span class="att-st bad">' + esc(f.error) + '</span>' : state === 'uploading' ? '<span class="att-st">Uploading…</span>' : state === 'sent' ? '<span class="att-st ok">Sent</span>' : state === 'failed' ? '<span class="att-st bad">Not sent</span>' : '<span class="att-st">Ready</span>';
					return '<span class="att' + (f.error ? ' att-bad' : '') + '"><span class="att-ic">' + icon(extOf(f.name)) + '</span><span class="att-txt"><b>' + esc(f.name) + '</b><small class="muted">' + esc(extOf(f.name).toUpperCase()) + ' · ' + size(f.size) + '</small></span>' + st + (state === 'uploading' ? '' : '<button type="button" class="btn btn-sm btn-ghost" data-arm="' + i + '">Remove</button>') + '</span>';
				}).join('') + '</div><p class="muted small">' + size(total()) + ' of ' + size(rules.max_total) + (note ? ' · ' + esc(note) : '') + '</p>' : '';
				out.querySelectorAll('[data-arm]').forEach(function (b) { b.onclick = function () { chosen.splice(+b.getAttribute('data-arm'), 1); state = ''; paint(); }; });
			};
			input.onchange = function () {
				Array.prototype.forEach.call(input.files, function (f) {
					var e = rules.types.indexOf(extOf(f.name)) < 0 ? 'Type not allowed' : (chosen.length >= rules.max_files ? 'At most ' + rules.max_files + ' files' : '');
					chosen.push({ file: f, name: f.name, size: f.size, error: e });
				});
				input.value = ''; state = ''; note = total() > rules.max_total ? 'Over the 5 MB limit: remove a file' : '';
				paint();
			};
			return {
				count: function () { return chosen.length; },
				files: function () {
					if (chosen.some(function (f) { return f.error; })) return Promise.reject(new Error('Remove the files that cannot be sent first.'));
					if (total() > rules.max_total) return Promise.reject(new Error('Attachments can be at most 5 MB in total.'));
					return Promise.all(chosen.map(function (f) { return new Promise(function (ok, no) { var rd = new FileReader(); rd.onload = function () { ok({ name: f.name, data: String(rd.result).replace(/^data:[^,]*,/, '') }); }; rd.onerror = function () { no(new Error(f.name + ' could not be read.')); }; rd.readAsDataURL(f.file); }); }));
				},
				status: function (st, m) { state = st; note = m || ''; paint(); },
				clear: function () { chosen = []; state = ''; note = ''; paint(); }
			};
		}
	};
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('[data-att]');
		if (b) { e.preventDefault(); WD.att.open(b.getAttribute('data-att'), b.getAttribute('data-att-prev') === '1'); }
	});

	var catalogue = null;
	function getCatalogue(force) { return catalogue && !force ? Promise.resolve(catalogue) : api('workspace/catalogue').then(function (r) { catalogue = r; return r; }); }

	/* ------------------------- Packages & Services ------------------------- */
	P.packages = function (box, sub) {
		sub = sub || 'catalogue';
		var tabs = [['catalogue', 'Master catalogue'], ['assignments', 'Project assignments'], ['migrations', 'Migrations']];
		box.innerHTML = '<div class="wsa-subtabs" role="tablist">' + tabs.map(function (t) { return '<a role="tab" href="#/workspace/packages/' + t[0] + '" class="' + (t[0] === sub ? 'on' : '') + '">' + esc(t[1]) + '</a>'; }).join('') + '</div><div data-pk-body>' + ui.loading(5) + '</div>';
		var b = box.querySelector('[data-pk-body]');
		return ({ catalogue: catalogueList, assignments: assignmentList, migrations: migrationList }[sub] || catalogueList)(b);
	};

	function catalogueList(b) {
		return getCatalogue(true).then(function (r) {
			var row = function (x) { return x; };
			var cols = [
				['Package', function (x) { return '<b>' + esc(x.name) + '</b> <span class="muted small">v' + x.version + '</span><div class="muted small">' + esc(x.description) + '</div>'; }],
				['Original price', function (x) { return x.is_trial ? '<span class="muted">Free trial</span>' : esc(inr(x.price)) + (x.annual_price !== null ? '<div class="muted small">' + esc(inr(x.annual_price)) + ' a year</div>' : ''); }],
				['Includes', function (x) { return x.kind === 'addon' ? esc((x.feature_code || '').replace(/_/g, ' ')) : '<span class="small">' + esc(x.feature_labels.join(', ')) + '</span>'; }],
				['State', function (x) { return x.is_active ? badge('Active', 'ok') : badge('Withdrawn', 'muted'); }],
				['', function (x) { return '<button class="btn btn-sm" data-hist="' + x.id + '">History</button>'; }]
			];
			b.innerHTML = '<p class="muted small">This list comes from the Neon Workspace master and cannot be edited here, so there is only one catalogue. Change plans and add-ons in Workspace wp-admin → Plans &amp; Add-ons. Existing client agreements keep the price and contents they were given.</p>' +
				card('Workspace packages', table(cols, r.items.filter(function (x) { return x.kind === 'plan'; }), 'Nothing has synced from Workspace yet'), '<button class="btn btn-sm" data-sync>Sync now</button>') + '<div class="wsa-gap"></div>' +
				card('Add-on services', table(cols, r.items.filter(function (x) { return x.kind === 'addon'; }), 'No add-ons in the catalogue'));
			b.querySelector('[data-sync]').onclick = function () { api('workspace/catalogue/sync', { method: 'POST', body: {} }).then(function (x) { toast('Synced: ' + x.created + ' new, ' + x.updated + ' changed, ' + x.withdrawn + ' withdrawn'); catalogueList(b); }).catch(err); };
			b.querySelectorAll('[data-hist]').forEach(function (h) {
				h.onclick = function () {
					api('workspace/catalogue/' + h.getAttribute('data-hist') + '/history').then(function (y) {
						modal({ title: 'Version history', foot: false, body: table([['Version', function (v) { return 'v' + v.version; }], ['Synced', function (v) { return dt(v.at); }], ['Name', function (v) { return esc(v.data.name || ''); }], ['Price', function (v) { return v.data.withdrawn ? 'Withdrawn' : esc(inr(v.data.price)); }]], y.items, 'No history') });
					}).catch(err);
				};
			});
		});
	}

	function assignmentList(b) {
		return api('workspace/assignments').then(function (r) {
			b.innerHTML = card('Live project assignments', table([
				['Project', function (a) { return '<b>' + esc(a.project_name) + '</b><div class="muted small">' + esc(a.client_name) + (a.workspace_name ? ' · ' + esc(a.workspace_name) : '') + '</div>'; }],
				['Package', function (a) { return esc(a.name) + (a.kind === 'addon' ? ' ' + badge('Add-on', 'info') : '') + (a.kind === 'legacy' ? ' ' + badge('Existing arrangement', 'muted') : ''); }],
				['Price', priceCell],
				['Billing', function (a) { return esc(a.billing_label); }],
				['Dates', function (a) { return day(a.start_date) + ' → ' + (a.expires_on ? day(a.expires_on) : '<span class="muted">no end</span>'); }],
				['Status', function (a) { return accessBadge(a.state); }],
				['', function (a) { return '<a class="btn btn-sm" href="#/workspace/clients/' + a.connection_id + '">Manage</a>'; }]
			], r.items, 'No project has a package yet. Open a client under Workspace Clients to assign one.'));
		});
	}

	function priceCell(a) {
		if (a.kind === 'legacy') return '<span class="muted">Agency</span>';
		if (a.custom_price === null) return esc(inr(a.original_price));
		return '<b>' + esc(inr(a.custom_price)) + '</b>' + (a.original_price !== null ? '<div class="muted small"><s>' + esc(inr(a.original_price)) + '</s> catalogue</div>' : '');
	}

	var MIG = { pending: ['Waiting for activation', 'warn'], active: ['Subscribed', 'ok'], expired: ['Locked: grace ended', 'bad'], reversed: ['Reversed', 'muted'] };
	function migrationList(b) {
		return api('workspace/migrations').then(function (r) {
			b.innerHTML = card('Projects moved to their own subscription', table([
				['Project', function (m) { return '<a href="#/workspace/clients/' + m.connection_id + '"><b>' + esc(m.project_name) + '</b></a><div class="muted small">' + esc(m.client_name) + ' · ' + esc(m.workspace_name) + '</div>'; }],
				['Was on', function (m) { return esc((m.previous && m.previous.name) || 'No package'); }],
				['Moving to', function (m) { return esc(m.package_name) + '<div class="muted small">' + esc(inr(m.custom_price !== null ? m.custom_price : m.original_price)) + ' · ' + esc(FREQ[m.billing_cycle] || m.billing_cycle) + '</div>'; }],
				['Grace', function (m) { return (+m.grace_days) + ' days<div class="muted small">to ' + day(m.grace_ends_on) + (m.status === 'pending' ? ', ' + (+m.days_left) + ' left' : '') + '</div>'; }],
				['Status', function (m) { var x = MIG[m.status] || [m.status, 'muted']; return badge(x[0], x[1]); }],
				['Started', function (m) { return dt(m.created_at) + '<div class="muted small">' + esc(m.created_by) + '</div>'; }],
				['', function (m) {
					return '<button class="btn btn-sm" data-mh="' + m.id + '">History</button>' +
						(m.status === 'pending' || m.status === 'expired' ? ' <button class="btn btn-sm" data-mc="' + m.id + '">Correct</button>' : '') +
						(m.status !== 'reversed' ? ' <button class="btn btn-sm" data-mr="' + m.id + '">Reverse</button>' : '');
				}]
			], r.items, 'No project has been migrated'));
			var pick = function (el, at) { return r.items.filter(function (m) { return m.id === +el.getAttribute(at); })[0]; };
			b.querySelectorAll('[data-mh]').forEach(function (e) { e.onclick = function () { historyModal(pick(e, 'data-mh')); }; });
			b.querySelectorAll('[data-mc]').forEach(function (e) { e.onclick = function () { correctModal(pick(e, 'data-mc'), function () { migrationList(b); }); }; });
			b.querySelectorAll('[data-mr]').forEach(function (e) { e.onclick = function () { reverseModal(pick(e, 'data-mr'), function () { migrationList(b); }); }; });
		});
	}
	function historyModal(m) {
		modal({ title: 'Migration of ' + m.project_name, foot: false, size: 'lg', body:
			'<dl class="kv"><dt>Previous arrangement</dt><dd>' + esc((m.previous && m.previous.name) || 'No package') + (m.previous && m.previous.price !== undefined && m.previous.price !== null ? ' · ' + esc(inr(m.previous.price)) : '') + '</dd><dt>Selected package</dt><dd>' + esc(m.package_name) + '</dd><dt>Original price</dt><dd>' + esc(inr(m.original_price)) + '</dd><dt>Agreed price</dt><dd>' + (m.custom_price === null ? '<span class="muted">Same as original</span>' : esc(inr(m.custom_price))) + '</dd><dt>Grace period</dt><dd>' + (+m.grace_days) + ' days, to ' + day(m.grace_ends_on) + '</dd><dt>Activated</dt><dd>' + dt(m.activated_at) + '</dd>' + (m.period_end ? '<dt>Subscription ends</dt><dd>' + day(m.period_end) + '</dd>' : '') + (m.reverse_reason ? '<dt>Reversed because</dt><dd>' + esc(m.reverse_reason) + '</dd>' : '') + '</dl>' +
			'<h3 class="wsa-h3">Audit trail</h3>' + table([['When', function (h) { return dt(h.created_at); }], ['What', function (h) { return esc(String(h.action).replace(/_/g, ' ')); }], ['Who', function (h) { return esc(h.actor || 'System'); }]], m.history, 'Nothing yet') });
	}
	function correctModal(m, done) {
		var x = modal({ title: 'Correct migration', body: '<p class="muted small">This changes ' + esc(m.project_name) + ' only, and is recorded in its audit trail.</p>' + field('Grace period (days from the start of the move)', '<input type="number" min="0" max="365" data-g value="' + (+m.grace_days) + '">') + (can('ndwd_manage_accounts') ? field('Agreed price', '<input type="number" min="0" step="0.01" data-p value="' + (m.custom_price === null ? '' : esc(m.custom_price)) + '" placeholder="Same as original, ' + esc(inr(m.original_price)) + '">') : ''), foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-ok>Save correction</button>' });
		x.el.querySelector('[data-ok]').onclick = function () {
			var body = { grace_days: x.el.querySelector('[data-g]').value }, p = x.el.querySelector('[data-p]');
			if (p) body.custom_price = p.value;
			api('workspace/migrations/' + m.id, { method: 'PUT', body: body }).then(function () { toast('Corrected'); x.close(); done(); }).catch(err);
		};
	}
	function reverseModal(m, done) {
		var x = modal({ title: 'Reverse this migration?', body: '<p>' + esc(m.project_name) + ' goes back to ' + esc((m.previous && m.previous.name) || 'having no package') + '. Other projects are not affected. Any subscription already paid is not refunded by this, so do that separately.</p>' + field('Reason', '<textarea data-w rows="2"></textarea>'), foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-danger" data-ok>Reverse</button>' });
		x.el.querySelector('[data-ok]').onclick = function () {
			api('workspace/migrations/' + m.id + '/reverse', { method: 'POST', body: { reason: x.el.querySelector('[data-w]').value } }).then(function () { toast('Migration reversed'); x.close(); done(); }).catch(err);
		};
	}

	/* ------------------------- A client's Workspaces and projects ------------------------- */
	WD.wsaClientTree = function (box, clientId, reload, onlyConn) {
		return api('workspace/clients/' + clientId + '/tree').then(function (t) {
			if (onlyConn) { t.workspaces = t.workspaces.filter(function (w) { return +w.connection_id === +onlyConn; }); t.independent = []; }
			var multi = t.workspaces.length > 1;
			box.innerHTML = t.workspaces.map(function (w) {
				return '<section class="card section-gap"><div class="card-h"><h2>' + esc(w.workspace_name || 'Workspace not linked yet') + '</h2> ' + H.wcBadge(w) + (w.plan_code ? ' <span class="muted small">' + esc(w.plan_code) + '</span>' : '') + '<span class="spacer"></span></div><div class="card-b flush">' +
					(w.projects.length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>Project</th><th>Access</th><th>Package</th><th>Price</th><th>Dates</th><th></th></tr></thead><tbody>' + w.projects.map(function (p) { return projectRow(p, multi); }).join('') + '</tbody></table></div>' : '<div class="card-b muted">No projects yet.</div>') + '</div></section>';
			}).join('') + (t.independent.length ? '<section class="card section-gap"><div class="card-h"><h2>Other Workspaces this client bought</h2></div><div class="card-b">' + t.independent.map(function (x) { return '<div class="wsa-row"><span><b>' + esc(x.name) + '</b> <span class="mono small muted">' + esc(x.workspace_uid) + '</span></span><span>' + esc(x.plan_code || '') + ' ' + H.statusBadge(x.status || 'none') + '</span></div>'; }).join('') + '</div></section>' : '') +
				(t.workspaces.length ? '' : ui.emptyState('No Workspace yet', 'Invite this client to create their first Workspace.', WD.I.list));
			var all = [];
			t.workspaces.forEach(function (w) { w.projects.forEach(function (p) { p._conn = w.connection_id; all.push(p); }); });
			var find = function (e, at) { return all.filter(function (p) { return p.id === +e.getAttribute(at); })[0]; };
			var redo = reload || function () { WD.wsaClientTree(box, clientId, reload); };
			box.querySelectorAll('[data-pa]').forEach(function (e) { e.onclick = function () { assignModal(find(e, 'data-pa'), e.getAttribute('data-kind'), null, redo); }; });
			box.querySelectorAll('[data-pe]').forEach(function (e) { var p = find(e, 'data-pe'); e.onclick = function () { assignModal(p, p.package.kind, p.package, redo); }; });
			box.querySelectorAll('[data-pm]').forEach(function (e) { e.onclick = function () { migrateModal(find(e, 'data-pm'), redo); }; });
			box.querySelectorAll('[data-ph]').forEach(function (e) { e.onclick = function () { projectHistory(find(e, 'data-ph')); }; });
			box.querySelectorAll('[data-prm]').forEach(function (e) {
				e.onclick = function () {
					WD.confirm('Remove this package?', 'If it is the project\'s package, the project locks for the client. Its data and history stay.', 'Remove', true).then(function (ok) {
						if (ok) api('workspace/assignments/' + e.getAttribute('data-prm'), { method: 'DELETE' }).then(function () { toast('Removed'); redo(); }).catch(err);
					});
				};
			});
			box.querySelectorAll('[data-pw]').forEach(function (e) {
				e.onclick = function () {
					var p = find(e, 'data-pw');
					var m = modal({ title: 'Move ' + p.name, body: field('Workspace', '<select data-to>' + t.workspaces.map(function (w) { return '<option value="' + w.connection_id + '"' + (w.connection_id === p._conn ? ' selected' : '') + '>' + esc(w.workspace_name || 'Not linked yet') + '</option>'; }).join('') + '</select>'), foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-ok>Move</button>' });
					m.el.querySelector('[data-ok]').onclick = function () { api('workspace/projects/' + p.id + '/workspace', { method: 'POST', body: { connection_id: +m.el.querySelector('[data-to]').value } }).then(function () { toast('Moved'); m.close(); redo(); }).catch(err); };
				};
			});
		}).catch(err);
	};

	function projectRow(p, multi) {
		var a = p.package;
		var mig = p.migration && p.migration.status !== 'reversed';
		var acts = ['<a class="btn btn-sm" href="#/projects/' + p.id + '">Open project</a>'];
		return '<tr><td data-th="Project"><b>' + esc(p.name) + '</b>' + (p.addons.length ? '<div class="muted small">Add-ons: ' + p.addons.map(function (x) { return esc(x.name) + ' (' + esc(inr(x.price)) + ')'; }).join(', ') + '</div>' : '') + '</td>' +
			'<td data-th="Access">' + accessBadge(p.state) + '</td>' +
			'<td data-th="Package">' + (mig ? esc(p.migration.package_name) + ' <span class="muted small">own subscription</span>' : a ? esc(a.name) + '<div class="muted small">' + esc(a.billing_label) + '</div>' : '<span class="muted">None</span>') + '</td>' +
			'<td data-th="Price">' + (a && !mig ? priceCell(a) : '<span class="muted">—</span>') + '</td>' +
			'<td data-th="Dates">' + (mig ? 'Grace to ' + day(p.migration.grace_ends_on) : a ? day(a.start_date) + ' → ' + (a.expires_on ? day(a.expires_on) : '<span class="muted">no end</span>') : '<span class="muted">—</span>') + '</td>' +
			'<td data-th="Actions" class="wsa-acts">' + acts.join(' ') + '</td></tr>';
	}

	function includes(x) {
		if (!x) return '';
		return '<div class="wsa-inc"><b>Includes</b> ' + (x.kind === 'addon' ? esc((x.feature_code || '').replace(/_/g, ' ')) + (x.duration_days ? ', ' + (+x.duration_days) + ' days' : '') : esc(x.feature_labels.join(', ') || 'No features listed')) + '</div>';
	}

	function assignModal(p, kind, cur, done) {
		getCatalogue().then(function (cat) {
			var list = cat.items.filter(function (x) { return x.kind === (kind === 'addon' ? 'addon' : 'plan') && x.is_active && (!x.is_trial) && !(kind === 'addon' && false); });
			if (!cur && !list.length) { toast('The catalogue has nothing to assign yet. Sync it from Workspace.', 'error'); return; }
			var m = modal({ title: (cur ? 'Edit terms: ' : kind === 'addon' ? 'Add add-on to ' : 'Assign package to ') + p.name, body:
				'<div class="form-grid">' +
				(cur ? '<p class="span-2"><b>' + esc(cur.name) + '</b> <span class="muted small">agreed at catalogue v' + (+cur.catalogue_version) + '</span></p>' : field('Package', '<select data-a="catalogue_id">' + list.map(function (x) { return '<option value="' + x.id + '">' + esc(x.name) + '</option>'; }).join('') + '</select>', '', 'span-2')) +
				'<div class="span-2" data-inc></div>' +
				'<div class="field"><span>Original price</span><div data-orig class="wsa-static"></div></div>' +
				field('Price for this project', '<input type="number" min="0" step="0.01" data-a="custom_price" placeholder="Same as original"' + (cat.can_price ? '' : ' disabled') + ' value="' + (cur && cur.custom_price !== null ? esc(cur.custom_price) : '') + '">', cat.can_price ? '0 is allowed' : 'needs Accounts access') +
				field('Billing', '<select data-a="billing_frequency"><option value="month">Monthly</option><option value="year">Yearly</option><option value="one_time">One-time</option></select>') +
				field('Grace period (days)', '<input type="number" min="0" max="365" data-a="grace_days" value="' + esc(cur ? cur.grace_days : 7) + '">') +
				field('Starts', '<input type="date" data-a="start_date" value="' + esc((cur && cur.start_date) || S.today) + '">') +
				field('Expires', '<input type="date" data-a="expires_on" value="' + esc((cur && cur.expires_on) || '') + '">', 'blank for no end') +
				field('Quantity', '<input type="number" min="1" data-a="quantity" value="' + esc(cur ? cur.quantity : 1) + '">') +
				field('Usage limit', '<input type="number" min="0" data-a="usage_limit" placeholder="None" value="' + (cur && cur.usage_limit !== null ? esc(cur.usage_limit) : '') + '">') +
				field('Notes', '<input data-a="notes" value="' + esc((cur && cur.notes) || '') + '">', '', 'span-2') + '</div>' +
				'<p class="muted small">This changes the price and dates for this project only. The master catalogue and the client\'s other projects stay as they are.</p>',
				foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-ok>Save</button>' });
			var q = function (k) { return m.el.querySelector('[data-a="' + k + '"]'); };
			if (cur && cur.billing_frequency !== 'agency') q('billing_frequency').value = cur.billing_frequency;
			var paint = function () {
				var x = cur ? cat.items.filter(function (i) { return i.id === cur.catalogue_id; })[0] : list.filter(function (i) { return i.id === +q('catalogue_id').value; })[0];
				m.el.querySelector('[data-inc]').innerHTML = includes(x);
				var yr = q('billing_frequency').value === 'year';
				var o = cur ? cur.original_price : x ? (yr && x.annual_price !== null ? x.annual_price : x.price) : null;
				m.el.querySelector('[data-orig]').textContent = inr(o);
				if (!cur && x) q('grace_days').value = x.grace_days;
			};
			if (q('catalogue_id')) q('catalogue_id').onchange = paint;
			q('billing_frequency').onchange = paint;
			paint();
			m.el.querySelector('[data-ok]').onclick = function () {
				var d = {};
				m.el.querySelectorAll('[data-a]').forEach(function (i) { if (!i.disabled) d[i.getAttribute('data-a')] = i.value; });
				var req = cur ? api('workspace/assignments/' + cur.id, { method: 'PUT', body: d }) : api('workspace/projects/' + p.id + '/package', { method: 'POST', body: d });
				req.then(function () { toast('Saved'); m.close(); done(); }).catch(err);
			};
		}).catch(err);
	}

	function migrateModal(p, done) {
		getCatalogue().then(function (cat) {
			var list = cat.items.filter(function (x) { return x.kind === 'plan' && x.is_active && !x.is_trial; });
			if (!list.length) { toast('The catalogue has no paid package yet.', 'error'); return; }
			var m = modal({ title: 'Migrate to Independent Subscription', size: 'lg', body:
				'<div class="alert">Only <b>' + esc(p.name) + '</b> moves. Its data, tasks, files and conversations stay as they are, and every other project of this client is unaffected. The agency agreement on this project ends, so the two are never billed together.</div>' +
				'<div class="form-grid">' +
				field('Independent package', '<select data-m="catalogue_id">' + list.map(function (x) { return '<option value="' + x.id + '">' + esc(x.name) + '</option>'; }).join('') + '</select>', '', 'span-2') + '<div class="span-2" data-inc></div>' +
				field('Billing', '<select data-m="billing_cycle"><option value="month">Monthly</option><option value="year">Yearly</option></select>') +
				'<div class="field"><span>Original price</span><div data-orig class="wsa-static"></div></div>' +
				field('Price for this project', '<input type="number" min="0" step="0.01" data-m="custom_price" placeholder="Same as original"' + (cat.can_price ? '' : ' disabled') + '>', cat.can_price ? '0 is allowed' : 'needs Accounts access') +
				field('Grace period for this project (days)', '<input type="number" min="0" max="365" data-m="grace_days" value="14">') +
				field('Notes', '<textarea data-m="notes" rows="2"></textarea>', '', 'span-2') + '</div>' +
				'<label class="wsa-check"><input type="checkbox" data-yes> I understand the client will be notified, and if they do not activate within the grace period only this project locks.</label>',
				foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-ok>Confirm migration</button>' });
			var q = function (k) { return m.el.querySelector('[data-m="' + k + '"]'); };
			var paint = function () {
				var x = list.filter(function (i) { return i.id === +q('catalogue_id').value; })[0];
				m.el.querySelector('[data-inc]').innerHTML = includes(x);
				m.el.querySelector('[data-orig]').textContent = inr(q('billing_cycle').value === 'year' && x.annual_price !== null ? x.annual_price : x.price);
			};
			q('catalogue_id').onchange = paint; q('billing_cycle').onchange = paint; paint();
			m.el.querySelector('[data-ok]').onclick = function () {
				if (!m.el.querySelector('[data-yes]').checked) { toast('Confirm you understand what this does.', 'error'); return; }
				var d = {};
				m.el.querySelectorAll('[data-m]').forEach(function (i) { if (!i.disabled) d[i.getAttribute('data-m')] = i.value; });
				api('workspace/projects/' + p.id + '/migrate', { method: 'POST', body: d }).then(function () { toast('Migration started. The client has been told.'); m.close(); done(); }).catch(err);
			};
		}).catch(err);
	}

	/* The project page uses the same dialogs, so the rules stay in one place. */
	WD.wsProject = { assign: function (p, kind, cur, done) { assignModal(p, kind, cur, done); }, migrate: function (p, done) { migrateModal(p, done); }, history: function (p) { projectHistory(p); } };

	function projectHistory(p) {
		api('workspace/projects/' + p.id + '/history').then(function (h) {
			modal({ title: 'History of ' + p.name, size: 'lg', foot: false, body:
				'<h3 class="wsa-h3">Packages</h3>' + table([['Package', function (a) { return esc(a.name); }], ['Price', priceCell], ['Billing', function (a) { return esc(a.billing_label); }], ['Dates', function (a) { return day(a.start_date) + ' → ' + (a.expires_on ? day(a.expires_on) : 'no end'); }], ['Status', function (a) { return H.statusBadge(a.status); }], ['Added', function (a) { return dt(a.created_at) + '<div class="muted small">' + esc(a.created_by) + '</div>'; }], ['Ended', function (a) { return dt(a.ended_at); }]], h.assignments, 'No package yet') +
				'<h3 class="wsa-h3">Migrations</h3>' + table([['To', function (m) { return esc(m.package_name); }], ['Status', function (m) { var x = MIG[m.status] || [m.status, 'muted']; return badge(x[0], x[1]); }], ['Grace to', function (m) { return day(m.grace_ends_on); }], ['Started', function (m) { return dt(m.created_at); }]], h.migrations, 'Not migrated') });
		}).catch(err);
	}

	/* ------------------------- All Workspaces ------------------------- */
	var allS = { inactive: false };
	P.workspaces = function (box) {
		return api('workspace/all', { params: allS.inactive ? { show_inactive: 1 } : {} }).then(function (r) {
			box.innerHTML = card('All Workspaces', '<div class="toolbar"><label class="wsa-check"><input type="checkbox" data-in' + (allS.inactive ? ' checked' : '') + '> Show Inactive Clients / Workspaces</label><span class="spacer"></span><span class="muted small">' + r.items.length + ' shown' + (r.hidden ? ', ' + r.hidden + ' inactive hidden' : '') + '</span></div>' + table([
				['Workspace', function (x) { return '<b>' + esc(x.name) + '</b><div class="muted small mono">' + esc(x.workspace_uid) + '</div>'; }],
				['Client', function (x) { return x.client_id ? '<a href="#/clients/' + x.client_id + '">' + esc(x.client_name) + '</a>' : '<span class="muted">Not a NeonDigital client</span>'; }],
				['Type', function (x) { return x.account_type === 'agency' ? badge('Agency client', 'info') : badge('Independent', 'muted'); }],
				['Connection', function (x) { return badge(x.status_label, H.toneOf(x.status)); }],
				['Projects', function (x) { return String(x.projects); }],
				['Plan', function (x) { return esc(x.plan_code || '—'); }],
				['Leads and bookings', function (x) { return x.account_type === 'agency' && x.connection_id ? 'Received for agency work' : '<span class="muted">Not received (the owner&rsquo;s own Workspace)</span>'; }],
				['Last synced', function (x) { return dt(x.synced_at); }],
				['', function (x) { return x.connection_id ? '<a class="btn btn-sm" href="#/workspace/clients/' + x.connection_id + '">Manage</a>' : ''; }]
			], r.items, 'Workspace has not synced any workspaces yet'));
			box.querySelector('[data-in]').onchange = function (e) { allS.inactive = e.target.checked; P.workspaces(box); };
		});
	};

	/* ------------------------- Subscriptions ------------------------- */
	P.subscriptions = function (box) {
		return api('workspace/subscriptions').then(function (r) {
			var items = r.items;
			box.innerHTML = card('Workspace Subscriptions', '<p class="muted small">Workspace holds each subscription. A client can have several, and each has its own plan and dates. Agency Workspaces are billed by NeonDigital in Accounts.</p>' + table([
				['Client / business', function (x) { return '<b>' + esc(x.business_name) + '</b>' + (x.client_id ? '<div class="muted small"><a href="#/clients/' + x.client_id + '">' + esc(x.client_name) + '</a></div>' : ''); }],
				['Workspace', function (x) { return esc(x.workspace_name); }],
				['Plan', function (x) { return x.account_type === 'agency' ? badge('Agency', 'info') : esc(x.plan_name || '—'); }],
				['Status', function (x) { return x.account_type === 'agency' ? '<span class="muted">—</span>' : H.statusBadge(x.status || 'none'); }],
				['Billing', function (x) { return esc(FREQ[x.billing_cycle] || '—'); }],
				['Renews', function (x) { return day(x.renews_on); }],
				['', function (x, i) { return '<button class="btn btn-sm" data-det="' + esc(x.workspace_uid) + '">Details</button>'; }]
			], items, 'No subscriptions synced yet'));
			box.querySelectorAll('[data-det]').forEach(function (b) { b.onclick = function () { subDetail(items.filter(function (x) { return x.workspace_uid === b.getAttribute('data-det'); })[0], function () { P.subscriptions(box); }); }; });
		});
	};
	function subDetail(x, done) {
		var kv = function (k, v) { return '<div><span>' + esc(k) + '</span>' + v + '</div>'; }, dash = '<span class="muted">—</span>';
		getCatalogue().then(function (cat) {
			var plans = cat.items.filter(function (i) { return i.kind === 'plan'; });
			var m = modal({ title: x.workspace_name, size: 'lg', body:
				'<div class="wsa-facts">' + kv('Client / business', esc(x.business_name)) + kv('Workspace', esc(x.workspace_name)) + kv('Email', x.email ? esc(x.email) : dash) + kv('Phone', x.phone ? esc(x.phone) : dash) + kv('Plan', esc(x.plan_name || '—')) + kv('Status', x.account_type === 'agency' ? 'Billed by NeonDigital' : H.statusBadge(x.status || 'none')) + kv('Billing', esc(FREQ[x.billing_cycle] || '—')) + kv('Started', day(x.period_start)) + kv('Expires', day(x.period_end)) + kv('Extended to', day(x.extended_until)) + kv('Grace period', x.grace_days === null ? dash : (+x.grace_days) + ' days') + kv('Renewal', x.renews_on ? day(x.renews_on) + ' ' + (x.state ? H.stateBadge(x.state) : '') : dash) + '</div>' +
				'<h3 class="wsa-h3">Related projects</h3>' + (x.projects.length ? x.projects.map(function (p) { return '<div class="wsa-row"><span>' + esc(p.name) + (p.package ? ' <span class="muted small">' + esc(p.package) + '</span>' : '') + '</span>' + accessBadge(p.state) + '</div>'; }).join('') : '<p class="muted small">None linked to this Workspace.</p>') +
				(x.manageable ? '<h3 class="wsa-h3">Manage</h3><div class="form-grid">' + field('Plan', '<select data-s="plan_code">' + plans.map(function (p) { return '<option value="' + esc(p.code) + '"' + (p.code === x.plan_code ? ' selected' : '') + '>' + esc(p.name) + '</option>'; }).join('') + '</select>') + field('Billing', '<select data-s="billing_cycle"><option value="month"' + (x.billing_cycle !== 'year' ? ' selected' : '') + '>Monthly</option><option value="year"' + (x.billing_cycle === 'year' ? ' selected' : '') + '>Yearly</option></select>') + field('Period ends', '<input type="date" data-s="period_end" value="' + esc(x.period_end || '') + '">') + field('Extended until', '<input type="date" data-s="extended_until" value="' + esc(x.extended_until || '') + '">') + field('Grace period (days)', '<input type="number" min="0" max="365" data-s="grace_days" value="' + esc(x.grace_days === null ? '' : x.grace_days) + '">') + '</div><p class="muted small">Past invoices and payments do not change. The change is recorded on both sides.</p>' : ''),
				foot: '<button class="btn" data-close>Close</button>' + (x.manageable ? '<button class="btn btn-primary" data-ok>Save</button>' : '') });
			var ok = m.el.querySelector('[data-ok]');
			if (ok) ok.onclick = function () {
				var d = {};
				m.el.querySelectorAll('[data-s]').forEach(function (i) { d[i.getAttribute('data-s')] = i.value; });
				api('workspace/subscriptions/' + x.workspace_uid, { method: 'POST', body: d }).then(function () { toast('Subscription updated'); m.close(); done(); }).catch(err);
			};
		}).catch(err);
	}

	/* ------------------------- Feature Entitlements ------------------------- */
	P.entitlements = function (box) {
		return api('workspace/entitlements/overview').then(function (r) {
			box.innerHTML = '<p class="muted small">Features follow each Workspace\'s project packages and add-ons. Open a client to change what they can use. That screen is the only place feature access is edited.</p>' +
				(r.items.length ? r.items.map(function (c) {
					var count = c.workspaces.reduce(function (n, w) { return n + w.features.length; }, 0);
					return '<details class="card section-gap wsa-ent"><summary class="card-h"><h2>' + esc(c.client_name) + '</h2>' + (c.company ? ' <span class="muted small">' + esc(c.company) + '</span>' : '') + '<span class="spacer"></span><span class="muted small">' + c.workspaces.length + ' Workspace' + (c.workspaces.length === 1 ? '' : 's') + ', ' + count + ' feature' + (count === 1 ? '' : 's') + ' on</span></summary><div class="card-b">' +
						c.workspaces.map(function (w) {
							return '<div class="wsa-ent-w"><div class="toolbar"><b>' + esc(w.workspace_name) + '</b> <span class="muted small">grace ' + (+w.grace_days) + ' days</span><span class="spacer"></span><a class="btn btn-sm" href="#/workspace/clients/' + w.connection_id + '">Manage</a></div>' +
								table([['Feature', function (f) { return esc(f.label); }], ['State', function (f) { return H.stateBadge(f.state); }], ['Starts', function (f) { return day(f.starts_on); }], ['Expires', function (f) { return f.expires_on ? day(f.expires_on) : '<span class="muted">no end</span>'; }], ['Grace', function (f) { return (+f.grace_days) + ' days'; }], ['Usage limit', function (f) { return f.usage_limit === null ? '<span class="muted">none</span>' : esc(f.usage_limit); }], ['From', function (f) { return f.notes === 'package' ? badge('Package', 'info') : badge('Set by staff', 'muted'); }]], w.features, 'No features on') +
								(w.projects.length ? '<h3 class="wsa-h3">Projects</h3>' + w.projects.map(function (p) { return '<div class="wsa-row"><span>' + esc(p.name) + (p.package ? ' <span class="muted small">' + esc(p.package.name) + (p.addons.length ? ' + ' + p.addons.map(function (a) { return esc(a.name); }).join(', ') : '') + '</span>' : '') + '</span>' + accessBadge(p.state) + '</div>'; }).join('') : '') + '</div>';
						}).join('') + '</div></details>';
				}).join('') : ui.emptyState('No connected clients', 'Invite a client to get started.', WD.I.list));
		});
	};

	/* ------------------------- Invite a Client ------------------------- */
	P.connect = function (box) {
		return Promise.all([H.getSettings(), getCatalogue().catch(function () { return { items: [], can_price: false }; })]).then(function (r) {
			var s = r[0], cat = r[1];
			var feats = Object.keys(s.features).map(function (k) { return { feature_code: k, label: s.features[k], enabled: false, grace_override: false, usage_limit: null }; });
			var plans = cat.items.filter(function (x) { return x.kind === 'plan' && x.is_active && !x.is_trial; });
			var sec = function (n, title, hint, body) { return '<section class="wsa-sec"><h3><span class="wsa-num">' + n + '</span>' + esc(title) + '</h3>' + (hint ? '<p class="muted small">' + esc(hint) + '</p>' : '') + body + '</section>'; };
			box.innerHTML = '<p class="muted small">Connection settings are in <a href="#/admin/integrations">Settings → Integrations → Neon Workspace</a>.</p><form data-invite class="card card-b">' +
				sec(1, 'Client', 'Their details come from the client record, so they only need to set a password.',
					'<div class="form-grid">' + field('Client', '<select name="client_id" required>' + H.options(H.clients(), '', 'Choose a client') + '</select>') + field('Invitation email', '<input name="email" type="email" required>') + '</div><div data-c-info></div>') +
				sec(2, 'What this client may see and do', '', '<div class="wsa-perms">' + H.permBoxes({ calendar: true, work_requests: true, approvals: true, usage: true }) + '</div>') +
				sec(3, 'Workspace and projects', 'Clients see every project in their Workspace. A project with no package stays locked.', '<div data-w-pick><p class="muted small">Choose a client first.</p></div>') +
				sec(4, 'Package and billing', 'Applies to the projects ticked above that you mark for it. Leave it empty to invite now and assign packages later.',
					'<div class="form-grid">' + field('Package', '<select name="catalogue_id"><option value="">No package yet</option>' + plans.map(function (x) { return '<option value="' + x.id + '">' + esc(x.name) + '</option>'; }).join('') + '</select>', '', 'span-2') + '<div class="span-2" data-pkg-inc></div>' +
					field('Billing', '<select name="billing_frequency"><option value="month">Monthly</option><option value="year">Yearly</option><option value="one_time">One-time</option></select>') + '<div class="field"><span>Original price</span><div data-pkg-orig class="wsa-static muted">—</div></div>' +
					field('Price for these projects', '<input type="number" name="custom_price" min="0" step="0.01" placeholder="Same as original"' + (cat.can_price ? '' : ' disabled') + '>', cat.can_price ? '0 is allowed' : 'needs Accounts access') + field('Grace period (days)', '<input name="grace_days" type="number" min="0" max="365" value="7">') +
					field('Starts', '<input name="package_start" type="date" value="' + esc(S.today) + '">') + field('Expires', '<input name="package_end" type="date">', 'blank for no end') + '</div>' +
					'<details class="wsa-more"><summary>Agency service allocation (optional)</summary><div class="form-grid"><label class="field"><span>Agency package</span><select name="package_id"><option value="">Do not allocate a package now</option></select></label></div><div data-pkg-items></div></details>') +
				sec(5, 'Feature dates', 'Features come from the package. Open this only when one feature needs its own dates.', '<details class="wsa-more"><summary>Set feature-specific dates</summary>' + H.featureRows(feats) + '</details>') +
				sec(6, 'Team and member', 'New client requests go here. A team request goes to the team queue, and to the team leader if nobody picks it up within an hour.', '<div class="form-grid cols-3">' + H.routingFields({}) + '</div>') +
				sec(7, 'Send', '', '<div class="form-grid">' + field('Valid for (days)', '<input name="valid_days" type="number" min="1" max="60" value="14">') + '</div><label class="wsa-check"><input type="checkbox" name="send_email" checked> Email the invitation now</label><div class="toolbar"><button class="btn btn-primary" type="submit">Create invitation</button></div><div data-inv-out></div>') +
				'</form>';
			var f = box.querySelector('[data-invite]');
			var templates = [], tree = null;
			if (can('ndwd_manage_accounts')) {
				api('accounts/packages').then(function (x) {
					templates = (x.items || x.packages || x || []).filter(function (t) { return (t.is_active === undefined || t.is_active) && !/^Workspace( add-on)?: /.test(t.name); });
					f.package_id.innerHTML += templates.map(function (t) { return '<option value="' + t.id + '">' + esc(t.name) + '</option>'; }).join('');
				}).catch(function () {});
			}
			f.package_id.onchange = function () {
				var t = templates.filter(function (x) { return +x.id === +f.package_id.value; })[0], el = f.querySelector('[data-pkg-items]');
				el.innerHTML = t && (t.items || []).length ? '<div class="form-grid">' + t.items.map(function (i) { return '<label class="field"><span>' + esc(i.service_name || i.name) + ' units</span><input type="number" min="0" data-svc="' + i.service_id + '" value="' + esc(i.quantity) + '"></label>'; }).join('') + '</div>' : '';
			};
			var paintPkg = function () {
				var x = plans.filter(function (i) { return i.id === +f.catalogue_id.value; })[0];
				f.querySelector('[data-pkg-inc]').innerHTML = includes(x);
				f.querySelector('[data-pkg-orig]').textContent = x ? inr(f.billing_frequency.value === 'year' && x.annual_price !== null ? x.annual_price : x.price) : '—';
				if (x) f.grace_days.value = x.grace_days;
			};
			f.catalogue_id.onchange = paintPkg; f.billing_frequency.onchange = paintPkg;
			f.client_id.addEventListener('change', function () {
				var wp = f.querySelector('[data-w-pick]'), info = f.querySelector('[data-c-info]');
				tree = null; info.innerHTML = '';
				if (!f.client_id.value) { wp.innerHTML = '<p class="muted small">Choose a client first.</p>'; return; }
				wp.innerHTML = ui.loading(2);
				api('workspace/clients/' + f.client_id.value + '/tree').then(function (t) {
					tree = t;
					if (t.client.email && !f.email.value) f.email.value = t.client.email;
					info.innerHTML = '<div class="wsa-facts wsa-c-info"><div><span>Business</span>' + esc(t.client.company || t.client.name) + '</div><div><span>Contact</span>' + esc(t.client.contact_person || t.client.name) + '</div><div><span>Email</span>' + esc(t.client.email || '—') + '</div><div><span>Phone</span>' + esc(t.client.phone || '—') + '</div></div>';
					var linked = t.workspaces.filter(function (w) { return w.linked_at; });
					var projects = (S.projects || []).filter(function (p) { return +p.client_id === +t.client.id && !+p.is_archived; });
					wp.innerHTML = (linked.length ? '<div class="alert">This client already has ' + linked.map(function (w) { return '<b>' + esc(w.workspace_name) + '</b>'; }).join(', ') + '. This invitation creates an additional Workspace with its own settings and data.</div>' : '') +
						(projects.length ? '<div class="table-wrap"><table class="tbl"><thead><tr><th>In this Workspace</th><th>Apply the package below</th></tr></thead><tbody>' + projects.map(function (p) { return '<tr><td><label class="wsa-check"><input type="checkbox" data-proj="' + p.id + '" checked> ' + esc(p.name) + '</label></td><td><input type="checkbox" data-ppkg="' + p.id + '" aria-label="Apply the package to ' + esc(p.name) + '"></td></tr>'; }).join('') + '</tbody></table></div>' : '<p class="muted small">This client has no projects yet. They can be added later.</p>');
				}).catch(err);
			});
			f.addEventListener('submit', function (e) {
				e.preventDefault();
				if (!f.client_id.value) { toast('Choose the client this invitation is for.', 'error'); return; }
				var ids = Array.prototype.map.call(f.querySelectorAll('[data-proj]:checked'), function (i) { return +i.getAttribute('data-proj'); });
				var pkg = ids.filter(function (id) { var b = f.querySelector('[data-ppkg="' + id + '"]'); return b && b.checked; });
				if (pkg.length && !f.catalogue_id.value) { toast('Choose the package to apply to the marked projects.', 'error'); return; }
				var d = { client_id: f.client_id.value, send_email: f.send_email.checked, email: f.email.value, valid_days: f.valid_days.value, package_start: f.package_start.value, package_end: f.package_end.value, grace_days: f.grace_days.value, entitlements: H.readFeatures(f).filter(function (x) { return x.enabled; }), perms: H.readPerms(f), project_ids: ids, additional: !!(tree && tree.workspaces.filter(function (w) { return w.linked_at && w.active; }).length) };
				f.querySelectorAll('[data-r]').forEach(function (i) { d[i.getAttribute('data-r')] = i.value; });
				if (f.catalogue_id.value) d.project_packages = pkg.map(function (id) { return { project_id: id, catalogue_id: +f.catalogue_id.value, billing_frequency: f.billing_frequency.value, custom_price: f.custom_price.disabled ? '' : f.custom_price.value, start_date: f.package_start.value, expires_on: f.package_end.value, grace_days: f.grace_days.value }; });
				if (f.package_id.value) d.package = { package_id: f.package_id.value, start_date: f.package_start.value || S.today, items: Array.prototype.map.call(f.querySelectorAll('[data-svc]'), function (i) { return { service_id: +i.getAttribute('data-svc'), quantity: +i.value }; }) };
				api('workspace/invitations', { method: 'POST', body: d }).then(function (x) {
					f.querySelector('[data-inv-out]').innerHTML = '<div class="alert">' + (x.emailed ? 'Invitation emailed.' : 'The invitation was not emailed, so send this link to the client yourself.') + ' Single use, expires ' + esc(x.expires_at) + '. The link is shown only here:<br><input readonly value="' + esc(x.url) + '" onclick="this.select()"></div>';
					toast('Invitation created');
				}).catch(err);
			});
		});
	};

	/* ------------------------- Client work inside Task Management ------------------------- */
	V.taskClientWork = function (c) {
		var which = (S.routeParams && S.routeParams[1]) || 'requests';
		WD.setTitle('Task Management', which === 'requests' ? 'Requests clients sent from Neon Workspace. Each one is already a task.' : 'Deliverables shared with clients, connected to their tasks.');
		c.innerHTML = WD.taskTabs(which) + '<div data-b>' + ui.loading(5) + '</div>';
		return H[which === 'requests' ? 'requests' : 'deliverables'](c.querySelector('[data-b]'));
	};
	WD.taskTabs = function (on) {
		if (!can('ndwd_manage_workspace')) return '';
		return '<div class="wsa-subtabs" role="tablist"><a role="tab" href="#/tasks" class="' + (on === 'tasks' ? 'on' : '') + '">Tasks</a><a role="tab" href="#/tasks/requests" class="' + (on === 'requests' ? 'on' : '') + '">Client requests</a><a role="tab" href="#/tasks/deliverables" class="' + (on === 'deliverables' ? 'on' : '') + '">Deliverables</a></div>';
	};
	var baseTasks = V.tasks;
	V.tasks = function (c, o) {
		var res = baseTasks(c, o);
		var tabs = WD.taskTabs('tasks');
		if (tabs && !c.querySelector('.wsa-subtabs')) c.insertAdjacentHTML('afterbegin', tabs);
		return res;
	};

	/* ------------------------- The client panel on a task ------------------------- */
	var rid = function () { return 'wd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12); };
	var FLAGS = [['visible', 'Visible to Client / Send to Client'], ['show_description', 'Client-safe description'], ['show_assignee', 'Assigned member and team'], ['show_deadline', 'Deadline'], ['show_status', 'Status'], ['show_comments', 'Remarks and chat'], ['show_calendar', 'Calendar entry'], ['show_handoff', 'Handoff updates']];
	var livePoll = null;

	WD.onTaskDrawer = function (d, t) {
		if (!t.client_id) return;
		var old = d.querySelector('.task-client');
		if (old) old.remove();
		var box = document.createElement('details');
		box.className = 'task-client wsa-panel';
		box.innerHTML = '<summary>Client</summary><div data-c class="small">Loading…</div>';
		var body = d.querySelector('.drawer-b');
		if (body) body.parentNode.insertBefore(box, body);
		var c = box.querySelector('[data-c]'), stamp = 0;

		/* Messages as bubbles: ours on the right, the client's on the left, a divider for each day.
		 * Times are WorkDesk's own (Asia/Kolkata); Workspace receives the same moment in UTC. */
		function thread(r) {
			if (!r.comments.length) return '<div class="chat-empty"><b>No messages yet</b><span>Messages with the client appear here.</span></div>';
			var last = '', today = S.today, y = new Date(today + 'T12:00:00Z'); y.setUTCDate(y.getUTCDate() - 1);
			var yday = y.toISOString().slice(0, 10);
			return r.comments.map(function (x) {
				var mine = x.author_type !== 'client', d = String(x.created_at || '').slice(0, 10), out = '';
				if (d && d !== last) { last = d; out += '<div class="chat-day"><span>' + (d === today ? 'Today' : d === yday ? 'Yesterday' : esc(fmt.date(d, true))) + '</span></div>'; }
				var time = String(x.created_at || '').slice(11, 16);
				return out + '<div class="chat-row' + (mine ? ' me' : '') + '"><div class="chat-bubble' + (x.is_internal ? ' int' : '') + '">' +
					'<div class="chat-who">' + esc(x.author_name || (mine ? 'NeonDigital' : 'Client')) + (x.is_internal ? ' · <span class="chat-flag">Internal only</span>' : '') + (mine ? '' : ' · Client') + '</div>' +
					'<div class="chat-text">' + esc(x.body) + '</div>' + WD.att.list(x.attachments) + '<div class="chat-meta"><time datetime="' + esc(x.created_at) + '" title="' + esc(dt(x.created_at)) + '">' + esc(time) + '</time>' + (mine && !x.is_internal && x.read ? ' · Seen' : '') + '</div></div></div>';
			}).join('');
		}

		function requestCard(q, canEdit) {
			if (!q) return '';
			var open = q.status === 'submitted' || q.status === 'in_review';
			var links = q.reference_links.map(function (l) { return '<a target="_blank" rel="noopener noreferrer" href="' + esc(l.url) + '">' + esc(l.label || l.url) + '</a>'; }).join(' · ');
			return '<div class="wsa-req"><div class="toolbar"><b>Client request #' + q.id + '</b> ' + H.statusBadge(q.status, q.status_label) + (q.requested_by ? ' <span class="muted">from ' + esc(q.requested_by) + '</span>' : '') + '<span class="spacer"></span>' + (q.service_name ? esc(q.service_name) + (q.unit_held ? ' ' + badge('Unit reserved', 'info') : '') + (q.over_limit ? ' ' + badge('No units left', 'warn') : '') : '') + '</div>' +
				(links ? '<p>Reference links: ' + links + '</p>' : '') + (q.attachments && q.attachments.length ? '<p class="muted small" style="margin-bottom:0">Files sent with the request</p>' + WD.att.list(q.attachments) : '') +
				(q.queue_entered_at && !q.escalated_at && !t.assignee_ids.length ? '<p class="muted">In the team queue since ' + dt(q.queue_entered_at) + '. It goes to the team leader after one hour.</p>' : '') +
				(q.escalated_at ? '<p>' + badge('Escalated', 'warn') + ' to ' + esc(q.escalated_to) + ', ' + dt(q.escalated_at) + '</p>' : '') +
				'<div class="wsa-steps">' + q.progress.map(function (s) { return '<span class="' + (s.done ? 'on' : '') + '">' + esc(s.label) + '</span>'; }).join('') + '</div>' +
				(q.review_note ? '<p><b>Note to client:</b> ' + esc(q.review_note) + '</p>' : '') +
				(open && canEdit ? '<div data-rv><textarea data-rn rows="2" placeholder="Note to the client (required to reject)"></textarea>' + (q.over_limit ? '<input data-ro placeholder="Reason for going beyond the package">' : '') + '<div class="toolbar"><button class="btn btn-sm" data-go="in_review">Mark reviewed</button><button class="btn btn-sm" data-go="approve">Accept</button><button class="btn btn-sm" data-go="reject">Reject</button></div></div>' : '') + '</div>';
		}

		function delivCard(r) {
			var list = r.deliverables;
			return '<details class="wsa-more"' + (list.length ? ' open' : '') + '><summary>Deliverables (' + list.length + ')</summary>' +
				list.map(function (x) { var a = x.approvals[x.approvals.length - 1]; return '<div class="wsa-row"><span>' + (x.file_url ? '<a target="_blank" rel="noopener noreferrer" href="' + esc(x.file_url) + '">' + esc(x.title) + '</a>' : esc(x.title)) + ' <span class="muted">v' + x.revision + '</span>' + (a ? '<div class="muted">' + esc(a.actor_name) + ': ' + esc(a.decision) + (a.comment ? ', ' + esc(a.comment) : '') + '</div>' : '') + WD.att.list(x.attachments) + '</span><span>' + H.statusBadge(x.status, x.status_label) + (x.client_visible ? '' : ' ' + badge('Not shared', 'muted')) + (x.status !== 'pending_approval' && x.status !== 'approved' && r.can_edit ? ' <button class="btn btn-sm" data-drev="' + x.id + '">New revision</button>' : '') + '</span></div>'; }).join('') +
				(r.can_edit && r.connected ? '<div class="form-grid wsa-dform"><label class="field"><span>Title</span><input data-dt></label><label class="field"><span>File link <span class="hint">(optional, e.g. Google Drive)</span></span><input data-du type="url" placeholder="https://"></label><div class="field"><span>Or attach a file</span><div data-dfile></div></div><label class="field"><span>Due date</span><input data-dd type="date"></label><label class="wsa-check"><input type="checkbox" data-dv checked> Show to the client</label></div><button class="btn btn-sm" data-dadd>Add deliverable</button>' : '') + '</details>';
		}

		function load() {
			var draft = c.querySelector('[data-cb]');
			var kept = draft ? draft.value : '';
			var dTitle = c.querySelector('[data-dt]') ? c.querySelector('[data-dt]').value : '';
			var dUrl = c.querySelector('[data-du]') ? c.querySelector('[data-du]').value : '';
			var note = c.querySelector('[data-rn]') ? c.querySelector('[data-rn]').value : '';
			var moreOpen = c.querySelector('[data-vis]') ? c.querySelector('[data-vis]').open : false;
			return api('workspace/tasks/' + t.id + '/client').then(function (r) {
				stamp = r.stamp;
				if (r.request || r.chat_open) box.open = true;
				var sendable = r.flags.visible && r.flags.show_comments && (!r.chat_required || r.flags.chat_active_at);
				c.innerHTML = (r.connected ? (r.workspace_name ? '<p class="muted">Workspace: <b>' + esc(r.workspace_name) + '</b>' + (r.project ? ' · Project: <a href="#/projects/' + r.project.id + '">' + esc((r.project.parent ? r.project.parent.name + ' › ' : '') + r.project.name) + '</a>' : '') + '</p>' : '') : '<p class="muted">' + (r.project ? 'This project has no Workspace yet. Assign one from its project page.' : 'This client is not connected to Workspace yet.') + '</p>') +
					requestCard(r.request, r.can_edit) +
					(r.connected ? '<div class="wsa-chat"><div class="toolbar"><b>Conversation</b><span class="spacer"></span>' + (r.chat_required && !r.flags.chat_active_at ? '<button class="btn btn-sm btn-primary" data-chat>Chat Now</button>' : r.flags.chat_active_at ? badge('Chat active', 'ok') : '') + '</div>' + (r.chat_required && !r.flags.chat_active_at ? '<p class="muted">The client can\'t message yet. Chat Now opens it for both sides.</p>' : '') +
						'<div class="wsa-thread" data-th>' + thread(r) + '</div>' +
						'<div class="chat-compose"><textarea data-cb rows="2" aria-label="Message or remark" placeholder="Write a message or remark"></textarea></div><div data-catt></div><div class="toolbar"><span class="muted small">Ctrl + Enter sends to the client · Internal Only keeps the message and its files inside WorkDesk</span><span class="spacer"></span><button class="btn btn-sm" data-send="internal">Internal Only</button><button class="btn btn-sm btn-primary" data-send="client"' + (sendable ? '' : ' disabled title="Turn on Remarks and chat, and open the chat first"') + '>Send to Client</button></div></div>' : '') +
					delivCard(r) +
					'<details class="wsa-more" data-vis' + (moreOpen ? ' open' : '') + '><summary>What the client sees</summary><div class="wsa-perms">' + FLAGS.map(function (l, i) { return '<label class="wsa-check"><input type="checkbox" data-fl="' + l[0] + '"' + (r.flags[l[0]] ? ' checked' : '') + (r.can_edit && (i === 0 || r.flags.visible) ? '' : ' disabled') + '> ' + l[1] + '</label>'; }).join('') + '</div><p class="muted">Off by default. Internal remarks and internal tasks never reach Workspace.</p>' + (r.flags.visible && r.flags.show_description ? '<label class="field"><span>Client-safe description</span><textarea data-cd rows="2"' + (r.can_edit ? '' : ' disabled') + '>' + esc(r.flags.client_description || '') + '</textarea></label>' : '') + '</details>';
				var q = function (s) { return c.querySelector(s); };
				if (q('[data-cb]')) {
					q('[data-cb]').value = kept;
					q('[data-cb]').addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); var s = c.querySelector('[data-send="client"]'); if (s && !s.disabled) s.click(); } });
				}
				if (q('[data-dt]')) { q('[data-dt]').value = dTitle; q('[data-du]').value = dUrl; }
				if (q('[data-rn]')) q('[data-rn]').value = note;
				if (q('[data-th]')) q('[data-th]').scrollTop = q('[data-th]').scrollHeight;
				var cpick = q('[data-catt]') ? WD.att.picker(q('[data-catt]'), r.attachment_rules) : null;
				var dpick = q('[data-dfile]') ? WD.att.picker(q('[data-dfile]'), r.attachment_rules) : null;
				if (q('[data-chat]')) q('[data-chat]').onclick = function () { api('workspace/tasks/' + t.id + '/chat', { method: 'POST', body: {} }).then(function () { toast('Chat is open for both sides'); load(); }).catch(err); };
				c.querySelectorAll('[data-send]').forEach(function (b) {
					b.onclick = function () {
						var i = q('[data-cb]'), n = cpick ? cpick.count() : 0;
						if (!i.value.trim() && !n) { i.focus(); return; }
						var internal = b.getAttribute('data-send') === 'internal';
						var v = i.value;
						b.disabled = true;
						(n ? cpick.files() : Promise.resolve([])).then(function (files) {
							if (files.length) cpick.status('uploading');
							var body = { external_id: rid(), body: v.trim() ? v : 'Shared ' + files.length + ' file' + (files.length === 1 ? '' : 's'), is_internal: internal, attachments: files };
							i.value = '';
							return api('workspace/tasks/' + t.id + '/client/comments', { method: 'POST', body: body });
						}).then(load).catch(function (e) { i.value = v; b.disabled = false; if (n) cpick.status('failed', e.message); err(e); });
					};
				});
				c.querySelectorAll('[data-go]').forEach(function (b) {
					b.onclick = function () {
						var bd = { decision: b.getAttribute('data-go'), note: q('[data-rn]').value };
						if (q('[data-ro]') && q('[data-ro]').value) { bd.package_override = true; bd.package_override_reason = q('[data-ro]').value; }
						api('workspace/tasks/' + t.id + '/request/review', { method: 'POST', body: bd }).then(function () { q('[data-rn]').value = ''; toast('Saved. The client has been told.'); load(); }).catch(err);
					};
				});
				c.querySelectorAll('[data-fl]').forEach(function (i) { i.onchange = function () { var b = {}; b[i.getAttribute('data-fl')] = i.checked; if (i.getAttribute('data-fl') === 'visible' && i.checked) { b.show_status = true; b.show_assignee = true; b.show_deadline = true; b.show_comments = true; b.show_calendar = true; } api('workspace/tasks/' + t.id + '/client', { method: 'PUT', body: b }).then(load).catch(err); }; });
				if (q('[data-cd]')) q('[data-cd]').onchange = function () { api('workspace/tasks/' + t.id + '/client', { method: 'PUT', body: { client_description: q('[data-cd]').value } }).then(function () { toast('Saved'); }).catch(err); };
				if (q('[data-dadd]')) q('[data-dadd]').onclick = function () {
					var n = dpick ? dpick.count() : 0;
					(n ? dpick.files() : Promise.resolve([])).then(function (files) {
						if (files.length) dpick.status('uploading');
						return api('workspace/tasks/' + t.id + '/deliverables', { method: 'POST', body: { title: q('[data-dt]').value, file_url: q('[data-du]').value, due_date: q('[data-dd]').value, client_visible: q('[data-dv]').checked, attachments: files } });
					}).then(function () { q('[data-dt]').value = ''; q('[data-du]').value = ''; toast('Deliverable added'); load(); }).catch(function (e) { if (n) dpick.status('failed', e.message); err(e); });
				};
				c.querySelectorAll('[data-drev]').forEach(function (b) {
					b.onclick = function () {
						var m = modal({ title: 'New revision', body: field('Link to the new revision', '<input data-nu type="url">'), foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-ok>Send for approval</button>' });
						m.el.querySelector('[data-ok]').onclick = function () { api('workspace/tasks/' + t.id + '/deliverables/' + b.getAttribute('data-drev'), { method: 'PUT', body: { file_url: m.el.querySelector('[data-nu]').value, new_revision: true } }).then(function () { m.close(); load(); }).catch(err); };
					};
				});
			}).catch(function (e) { c.textContent = e.message; });
		}
		load();
		if (livePoll) clearInterval(livePoll);
		// Short polling, only while this panel is open and the tab is in view. What is being typed is kept.
		livePoll = setInterval(function () {
			if (!box.isConnected) { clearInterval(livePoll); return; }
			if (!box.open || document.hidden) return;
			if (c.querySelector('.att-pending')) return; // Files chosen and not sent yet: keep them.
			api('workspace/tasks/' + t.id + '/client/pulse').then(function (p) { if (p.stamp !== stamp) load(); }).catch(function () {});
		}, 5000);
	};
})();
