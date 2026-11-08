/* NeonDigital WorkDesk: Projects dashboard and the project page (1.18).
 * Workspace, Workspace package, add-ons and service packages are assigned here, on the project.
 * Sub-projects are listed inside their parent and never as separate rows on the dashboard. */
(function () {
	'use strict';
	var WD = window.WD, S = WD.S, api = WD.api, V = WD.views, H = WD.wsa;
	var esc = WD.u.esc, can = WD.u.can, ui = WD.ui, I = WD.I, el = WD.u.el;
	var toast = WD.toast, err = WD.toastError || function (e) { toast(e.message || String(e), 'error'); };
	var badge = H.badge, day = H.day;

	var ST = { active: ['Active', 'ok'], inactive: ['Inactive', 'muted'], archived: ['Archived', 'muted'] };
	var ACCESS = { active: ['Open', 'ok'], renewal_due: ['Renewal due', 'warn'], grace: ['In grace period', 'warn'], subscribed: ['Own subscription', 'ok'], scheduled: ['Starts later', 'info'], no_package: ['No package', 'bad'], expired: ['Package ended', 'bad'], migration_expired: ['Grace ended', 'bad'], subscription_lapsed: ['Subscription ended', 'bad'], feature_locked: ['Connect off', 'bad'], no_workspace: ['No Workspace', 'muted'] };
	function stBadge(s) { var x = ST[s] || [s, 'muted']; return badge(x[0], x[1]); }
	function accBadge(s) { var x = ACCESS[s] || [String(s || '').replace(/_/g, ' '), 'muted']; return badge(x[0], x[1]); }
	var inr = function (n) { return n === null || n === undefined ? '—' : '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 2 }); };

	/* ---------------- Project form: one form for the dashboard, the project page and the client page ---------------- */
	WD.projectForm = function (p, done, preset) {
		preset = preset || {};
		var isParent = p && S.projects.some(function (x) { return +x.parent_id === +p.id; });
		var state = p ? (+p.is_archived ? 'archived' : +p.is_active ? 'active' : 'inactive') : 'active';
		var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
			'<label class="field"><span>Project name <span class="req">*</span></span><input name="name" required maxlength="160"></label>' +
			'<label class="field"><span>Client</span><select name="client_id">' + WD.selectOptions((S.clients || []).filter(function (c) { return +c.is_active !== 0 || (p && +p.client_id === +c.id); }), p ? p.client_id : (preset.client_id || ''), 'No client yet') + '</select></label>' +
			'<label class="field"><span>Parent project <span class="hint">(optional, makes this a sub-project)</span></span><select name="parent_id"' + (isParent ? ' disabled' : '') + '></select></label>' +
			'<label class="field"><span>Status</span><select name="status">' + WD.mapOptions({ active: 'Active', inactive: 'Inactive', archived: 'Archived' }, state) + '</select></label>' +
			'<label class="field span-2"><span>Description</span><textarea name="description" rows="2"></textarea></label></div>' +
			(isParent ? '<p class="muted small">This project has sub-projects, so it stays a main project.</p>' : '') +
			'<p class="muted small">Workspace and packages are set on the project page after saving.</p></form>');
		f.name.value = p ? p.name : '';
		f.description.value = p && p.description ? p.description : '';
		function paintParents() {
			var cid = +f.client_id.value || 0, keep = f.parent_id.value || (p && p.parent_id) || preset.parent_id || '';
			f.parent_id.innerHTML = WD.selectOptions(S.projects.filter(function (x) { return !x.parent_id && (!p || x.id !== p.id) && +x.client_id === cid && !+x.is_archived; }), keep, 'None, main project');
		}
		paintParents();
		f.client_id.onchange = function () { f.parent_id.value = ''; paintParents(); };
		if (preset.parent_id) { f.client_id.disabled = true; }
		var md = WD.modal({ title: p ? 'Edit project' : (preset.parent_id ? 'Add sub-project' : 'Add project'), body: f, foot: (p && can('ndwd_delete_records') ? '<button class="btn btn-danger left" data-del>Delete</button>' : '') + '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-save>Save</button>' });
		var delP = md.el.querySelector('[data-del]');
		if (delP) delP.onclick = function () { WD.safeDelete('projects/' + p.id, 'project').then(function (ok) { if (ok) { md.close(); WD.reloadBoot().then(function () { WD.go('/projects'); }); } }); };
		md.el.querySelector('[data-save]').onclick = function (e) {
			var box = f.querySelector('[data-err]');
			if (!f.name.value.trim()) { f.name.focus(); return; }
			var body = { name: f.name.value, description: f.description.value, status: f.status.value };
			if (!preset.parent_id) body.client_id = f.client_id.value || 0;
			if (!isParent) body.parent_id = f.parent_id.value || 0;
			WD.busy(e.target, true);
			api(p ? 'projects/' + p.id : 'projects', { method: p ? 'PUT' : 'POST', body: body }).then(function (list) {
				md.close();
				toast('Project saved');
				var saved = p ? p.id : (Array.isArray(list) ? list.filter(function (x) { return x.name === body.name; }).map(function (x) { return +x.id; }).sort(function (a, b) { return b - a; })[0] : null);
				WD.reloadBoot().then(function () { if (done) done(saved); });
			}, function (er) { WD.busy(e.target, false); box.textContent = er.message; box.classList.remove('hidden'); });
		};
	};

	/* ---------------- Projects dashboard ---------------- */
	var hf = { q: '', client_id: '', connection_id: '', status: 'active' };
	V.projectHub = function (c) {
		WD.setTitle('Projects', 'Every main project with its client, Workspace and package. Open a project to manage it.');
		c.innerHTML = '<section class="card ph-card"><div class="ph-bar">' +
			'<input type="search" data-q placeholder="Search projects or clients" aria-label="Search projects" value="' + esc(hf.q) + '">' +
			'<select data-cl aria-label="Client"><option value="">All clients</option>' + (S.clients || []).map(function (x) { return '<option value="' + x.id + '"' + (String(x.id) === String(hf.client_id) ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select>' +
			'<select data-ws aria-label="Workspace" hidden><option value="">All Workspaces</option></select>' +
			'<select data-st aria-label="Status">' + WD.mapOptions({ active: 'Active', inactive: 'Inactive', archived: 'Archived', all: 'All statuses' }, hf.status) + '</select>' +
			'<span class="spacer"></span>' + (can('ndwd_manage_projects') ? '<button class="btn btn-primary" data-add>' + I.plus + ' New project</button>' : '') + '</div><div data-list>' + ui.loading(5) + '</div></section>';
		var list = c.querySelector('[data-list]'), wsSel = c.querySelector('[data-ws]');
		function load() {
			return api('project-hub', { params: hf }).then(function (r) {
				if (!list.isConnected) return;
				if (r.can_manage_workspace && wsSel.hidden) {
					wsSel.hidden = false;
					wsSel.insertAdjacentHTML('beforeend', r.workspaces.map(function (w) { return '<option value="' + w.connection_id + '"' + (String(w.connection_id) === String(hf.connection_id) ? ' selected' : '') + '>' + esc(w.name) + '</option>'; }).join(''));
				}
				list.innerHTML = r.items.length ? '<div class="ph-grid">' + r.items.map(function (p) {
					return '<a class="ph-item" href="#/projects/' + p.id + '"><div class="ph-top"><b class="ph-name">' + esc(p.name) + '</b>' + stBadge(p.status) + '</div>' +
						'<div class="ph-meta">' + (p.client ? esc(p.client.name) : '<span class="muted">No client</span>') + '</div>' +
						(p.workspace !== null && p.workspace !== undefined || r.can_manage_workspace ? '<dl class="ph-kv"><div><dt>Workspace</dt><dd>' + (p.workspace ? esc(p.workspace.name) : '<span class="muted">None</span>') + '</dd></div><div><dt>Package</dt><dd>' + (p.package && p.package.name ? esc(p.package.name) + (p.package.addons ? ' <span class="muted small">+' + p.package.addons + ' add-on' + (p.package.addons === 1 ? '' : 's') + '</span>' : '') : '<span class="muted">None</span>') + '</dd></div></dl>' : '') +
						'<div class="ph-foot">' + (p.package ? accBadge(p.package.state) : '') + '<span class="muted small">' + p.sub_count + ' sub-project' + (p.sub_count === 1 ? '' : 's') + ' · ' + p.open_tasks + ' open task' + (p.open_tasks === 1 ? '' : 's') + '</span></div></a>';
				}).join('') + '</div>' : ui.emptyState('No projects match', 'Change the search or filters, or add a project.', I.folder);
			}).catch(function (e) { list.innerHTML = ''; list.appendChild(ui.errorBox(e, load)); });
		}
		var deb = WD.u.debounce(load, 250);
		c.querySelector('[data-q]').oninput = function (e) { hf.q = e.target.value; deb(); };
		c.querySelector('[data-cl]').onchange = function (e) { hf.client_id = e.target.value; load(); };
		wsSel.onchange = function (e) { hf.connection_id = e.target.value; load(); };
		c.querySelector('[data-st]').onchange = function (e) { hf.status = e.target.value; load(); };
		var add = c.querySelector('[data-add]');
		if (add) add.onclick = function () { WD.projectForm(null, function (id) { if (id) WD.go('/projects/' + id); else load(); }); };
		return load();
	};

	/* ---------------- Project page ---------------- */
	V.projectPage = function (c) {
		var id = +S.routeParams[1];
		WD.setTitle('Project', '');
		c.innerHTML = ui.loading(6);
		var reload = function () { return V.projectPage(c); };
		return api('project-hub/' + id).then(function (d) {
			var p = d.project, cl = d.client, sec = function (title, act, body, cls) { return '<section class="card pp-sec' + (cls ? ' ' + cls : '') + '"><div class="card-h"><h2>' + title + '</h2><span class="spacer"></span>' + (act || '') + '</div><div class="card-b">' + body + '</div></section>'; };
			WD.setTitle(p.name, p.parent ? 'Sub-project of ' + p.parent.name : (cl ? cl.name : 'No client yet'));
			var crumbs = '<div class="toolbar"><a class="btn btn-sm btn-ghost" href="#/projects">' + I.left + ' Projects</a>' + (p.parent ? '<a class="btn btn-sm btn-ghost" href="#/projects/' + p.parent.id + '">' + esc(p.parent.name) + '</a>' : '') + '<span class="spacer"></span>' + (d.can.manage_projects ? '<button class="btn btn-sm" data-edit>' + I.edit + ' Edit project</button>' : '') + '<a class="btn btn-sm" href="#/tasks" data-tasks>' + I.list + ' Tasks</a>' + (S.user ? '<button class="btn btn-sm btn-primary" data-newtask>' + I.plus + ' New task' + (p.parent ? ' in this sub-project' : '') + '</button>' : '') + '</div>';

			var tasks = d.tasks.by_status || {};
			var overview = '<dl class="pp-kv"><div><dt>Status</dt><dd>' + stBadge(p.status) + '</dd></div>' +
				'<div><dt>Client</dt><dd>' + (cl ? '<a href="#/clients/' + cl.id + '">' + esc(cl.name) + '</a>' + (cl.company ? ' <span class="muted small">' + esc(cl.company) + '</span>' : '') : '<span class="muted">No client yet</span>') + (d.can.manage_projects && !p.parent ? ' <button class="btn btn-sm btn-ghost" data-client>' + (cl ? 'Change' : 'Assign client') + '</button>' : '') + '</dd></div>' +
				'<div><dt>Tasks</dt><dd>' + d.tasks.total + ' total' + (tasks.pending ? ' · ' + tasks.pending + ' pending' : '') + (tasks.in_process ? ' · ' + tasks.in_process + ' in process' : '') + (tasks.completed ? ' · ' + tasks.completed + ' completed' : '') + '</dd></div>' +
				(p.description ? '<div class="span-all"><dt>Description</dt><dd>' + esc(p.description) + '</dd></div>' : '') + '</dl>';

			var html = crumbs + '<div class="pp-grid"><div class="pp-main">' + sec('Overview', '', overview);

			if (p.parent) html = html.replace('<div class="pp-grid">', '<div class="alert pp-parent">Sub-project of <a href="#/projects/' + p.parent.id + '"><b>' + esc(p.parent.name) + '</b></a>. Its requests, tasks, deliverables, files and conversations are its own; ' + (d.workspace_source === 'project' ? 'it has its own Workspace.' : 'it uses the main project\'s Workspace.') + '</div><div class="pp-grid">');
			if (d.can.manage_workspace) {
				var w = d.workspace, src = { parent: 'Inherited from the main project, ' + (p.parent ? p.parent.name : ''), project: 'Assigned to this ' + (p.parent ? 'sub-project' : 'project'), none: '' }[d.workspace_source] || '';
				var other = d.available_workspaces.filter(function (x) { return !w || x.connection_id !== w.connection_id; });
				var wsBody = !cl ? '<p class="muted">Assign a client first. A project\'s Workspace belongs to its client.</p>' :
					(w ? '<div class="pp-ws"><div><b>' + esc(w.name) + '</b> ' + badge(w.status_label, H.toneOf ? H.toneOf(w.status) : '') + '<div class="muted small">' + esc(src) + '</div>' + (w.workspace_uid ? '<div class="muted small mono">' + esc(w.workspace_uid) + '</div>' : '') + '<div class="small">Business type: <b>' + esc(w.business_type_label || 'Not chosen') + '</b>' + (w.linked ? ' <span class="muted">· change it with Change Business Type in Workspace Management</span>' : '') + '</div></div></div>' : '<p class="muted">' + (p.parent ? 'No Workspace yet: the main project has none.' : 'No Workspace assigned to this project yet. Each main project has its own; other projects\' Workspaces are not shown here.') + '</p>') +
					(d.account_email ? '<p class="muted small">Client account: ' + esc(d.account_email) + '. A new Workspace is added to this account straight away, with a notification. No new invitation.</p>' : '<p class="muted small">The client has no Neon Workspace account yet. The first Workspace sends one invitation that creates it.</p>') +
					'<div class="pp-acts">' + (other.length ? '<button class="btn btn-sm" data-move>' + (d.workspace_source === 'project' ? 'Change Workspace' : 'Assign existing Workspace') + '</button>' : '') + (d.workspace_source !== 'project' || (w && !w.linked) ? '<button class="btn btn-sm' + (w ? '' : ' btn-primary') + '" data-newws>' + I.plus + ' ' + (p.parent ? 'Separate Workspace for this sub-project' : 'New Workspace for this project') + '</button>' : '') + ((d.assignment_history || []).length ? '<button class="btn btn-sm btn-ghost" data-ah>Assignment history</button>' : '') + '</div>' + (p.parent && d.workspace_source === 'parent' ? '<p class="muted small">Follows the main project\'s Workspace by default. Give it its own only if it really needs a separate one.</p>' : '');
				html += sec('Workspace', '', wsBody);

				var pk = d.package, mig = d.migration && d.migration.status !== 'reversed';
				var acts = [];
				if (cl && w && !mig) {
					acts.push('<button class="btn btn-sm btn-primary" data-pa="plan">' + (pk ? 'Change package' : 'Assign package') + '</button>');
					if (pk) { if (pk.kind !== 'legacy') acts.push('<button class="btn btn-sm" data-pe>Edit terms</button>'); acts.push('<button class="btn btn-sm" data-pa="addon">Add add-on</button>'); acts.push('<button class="btn btn-sm" data-prm="' + pk.id + '">Remove</button>'); }
					acts.push('<button class="btn btn-sm" data-pm>Move to own subscription</button>');
				}
				if (w) acts.push('<button class="btn btn-sm btn-ghost" data-ph>History</button>');
				var pkBody = !w ? '<p class="muted">Packages apply once the project is in a Workspace.</p>' :
					'<div class="pp-access">' + (d.access ? accBadge(d.access.state) + (d.access.message ? ' <span class="muted small">' + esc(d.access.message) + '</span>' : '') : '') + '</div>' +
					(mig ? '<p><b>' + esc(d.migration.package_name) + '</b> <span class="muted small">own subscription, grace to ' + day(d.migration.grace_ends_on) + '</span></p>' :
						pk ? '<dl class="pp-kv"><div><dt>Package</dt><dd><b>' + esc(pk.name) + '</b> <span class="muted small">' + esc(pk.billing_label) + '</span></dd></div><div><dt>Price</dt><dd>' + esc(inr(pk.price)) + (pk.custom_price !== null && pk.original_price !== null && pk.custom_price !== pk.original_price ? ' <span class="muted small">catalogue ' + esc(inr(pk.original_price)) + '</span>' : '') + '</dd></div><div><dt>Dates</dt><dd>' + day(pk.start_date) + ' → ' + (pk.expires_on ? day(pk.expires_on) : 'no end') + '</dd></div><div><dt>Grace</dt><dd>' + (+pk.grace_days) + ' days</dd></div></dl>' : d.access && d.access.inherited ? '<p class="muted">Uses the main project&rsquo;s package' + (d.access.inherited_package ? ' (<b>' + esc(d.access.inherited_package) + '</b>)' : '') + (d.access.open ? '' : ', which is not active') + '. Assign one here only if this sub-project needs its own.</p>' : '<p class="muted">No Workspace package yet. The client sees this project as locked until one is assigned.</p>') +
					(d.addons.length ? '<h3 class="pp-h3">Add-ons</h3>' + d.addons.map(function (a) { return '<div class="pp-row"><span>' + esc(a.name) + ' <span class="muted small">' + esc(inr(a.price)) + ' · ' + day(a.start_date) + ' → ' + (a.expires_on ? day(a.expires_on) : 'no end') + '</span></span><button class="btn btn-sm btn-ghost" data-prm="' + a.id + '">Remove</button></div>'; }).join('') : '') +
					(acts.length ? '<div class="pp-acts">' + acts.join('') + '</div>' : '');
				html += sec('Workspace package and add-ons', '', pkBody);
			}

			var reqs = d.requests || [], dels = d.deliverables || [], files = d.attachments || [];
			html += sec('Work requests', '', reqs.length ? reqs.map(function (q) { return '<a class="pp-row" href="#/tasks/requests" ' + (q.task_id ? 'data-rq-task="' + q.task_id + '"' : '') + '><span><b>' + esc(q.title) + '</b> <span class="muted small">#' + q.id + (q.requested_by ? ' · ' + esc(q.requested_by) : '') + ' · ' + esc(H.dt ? H.dt(q.created_at) : q.created_at) + '</span></span>' + badge(q.status_label, q.status === 'rejected' ? 'bad' : q.status === 'submitted' ? 'info' : 'ok') + '</a>'; }).join('') : '<p class="muted">No work requests on this ' + (p.parent ? 'sub-project' : 'project') + '.</p>');
			html += sec('Deliverables', '', dels.length ? dels.map(function (x) { return '<div class="pp-row"><span><b>' + esc(x.title) + '</b> <span class="muted small">v' + x.revision + (x.client_visible ? '' : ' · not shared') + '</span>' + WD.att.list(x.attachments) + '</span>' + badge(x.status_label, x.status === 'approved' ? 'ok' : x.status === 'rejected' ? 'bad' : 'info') + '</div>'; }).join('') : '<p class="muted">No deliverables yet. Add them from a task\'s Client panel.</p>');
			html += sec('Files and updates', '', WD.att.list(files, { remove: true }) + (files.length ? '' : '<p class="muted">No files on this ' + (p.parent ? 'sub-project' : 'project') + ' yet.</p>') + (cl && (d.can.manage_projects || d.can.manage_workspace) ? '<div class="pp-up"><div data-ppf></div><label class="field"><span>Note <span class="hint">(optional)</span></span><input data-ppn maxlength="200" placeholder="e.g. Sitemap v1"></label><label class="check small"><input type="checkbox" data-ppi> Internal only (never shown to the client)</label><button class="btn btn-sm" data-ppgo>Add file' + (w ? '' : ' (internal until a Workspace is assigned)') + '</button></div>' : ''));
			var sp = d.service_packages;
			html += sec('Service packages', d.can.manage_accounts && cl ? '<button class="btn btn-sm" data-sp>' + I.plus + ' Assign service package</button>' : '',
				sp.length ? sp.map(function (a) { return '<div class="pp-row"><span><b>' + esc(a.name) + '</b> <span class="muted small">' + esc(a.status_label) + ' · ' + esc(a.type_label || '') + '</span></span>' + (d.can.manage_accounts ? '<button class="btn btn-sm btn-ghost" data-spe="' + a.id + '">Adjust</button>' : '') + '</div>'; }).join('') : '<p class="muted">No service package on this project.' + (cl ? '' : ' Assign a client first.') + '</p>');
			html += '</div><div class="pp-side">';
			if (!p.parent) {
				html += sec('Sub-projects', d.can.manage_projects ? '<button class="btn btn-sm" data-addsub>' + I.plus + ' Add</button>' : '',
					d.subprojects.length ? d.subprojects.map(function (s) { return '<a class="pp-sub" href="#/projects/' + s.id + '"><span><b>' + esc(s.name) + '</b>' + (s.description ? '<span class="muted small">' + esc(s.description) + '</span>' : '') + '<span class="muted small">' + (s.own_workspace ? 'Own Workspace: ' + esc(s.own_workspace) : 'Uses this project\'s Workspace') + (s.open_requests ? ' · ' + s.open_requests + ' open request' + (s.open_requests === 1 ? '' : 's') : '') + '</span></span><span>' + stBadge(s.status) + (s.package ? ' ' + accBadge(s.package.state) : '') + '</span></a>'; }).join('') : '<p class="muted">No sub-projects. Sub-projects share this project\'s client and use its Workspace unless given their own.</p>');
			}
			html += '</div></div>';
			c.innerHTML = html;

			var q = function (s) { return c.querySelector(s); };
			var shape = { id: p.id, name: p.name, package: d.package, addons: d.addons, migration: d.migration };
			if (q('[data-edit]')) q('[data-edit]').onclick = function () { WD.projectForm(WD.u.project(p.id) || { id: p.id, name: p.name, client_id: cl ? cl.id : 0, parent_id: p.parent ? p.parent.id : 0, description: p.description, is_active: p.is_active ? 1 : 0, is_archived: p.is_archived ? 1 : 0 }, reload); };
			q('[data-tasks]').onclick = function (e) { e.preventDefault(); S.taskPreset = { project_id: p.id, view: 'all', scope: '' }; WD.go('/tasks'); };
			if (q('[data-newtask]')) q('[data-newtask]').onclick = function () { WD.openTaskForm('create', null, null, { project_id: p.id, done: reload }); };
			if (q('[data-client]')) q('[data-client]').onclick = function () {
				var m = WD.modal({ title: 'Client of ' + p.name, size: 'sm', body: '<label class="field"><span>Client</span><select data-c>' + WD.selectOptions(S.clients || [], cl ? cl.id : '', 'No client') + '</select></label><p class="muted small">Sub-projects move with this project. A project with active packages keeps its client until they are ended.</p>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-ok>Save</button>' });
				m.el.querySelector('[data-ok]').onclick = function () { api('project-hub/' + p.id + '/client', { method: 'POST', body: { client_id: +m.el.querySelector('[data-c]').value || 0 } }).then(function () { m.close(); toast('Client updated'); WD.reloadBoot().then(reload); }).catch(err); };
			};
			c.querySelectorAll('[data-rq-task]').forEach(function (a) { a.onclick = function (e) { e.preventDefault(); if (WD.openTask) WD.openTask(+a.getAttribute('data-rq-task')); else WD.go('/tasks'); }; });
			if (q('[data-ppf]')) {
				var ppk = WD.att.picker(q('[data-ppf]'), d.attachment_rules);
				q('[data-ppgo]').onclick = function (e) {
					if (!ppk.count()) { toast('Choose a file first'); return; }
					WD.busy(e.target, true);
					ppk.files().then(function (list) { ppk.status('uploading'); return api('workspace/projects/' + p.id + '/attachments', { method: 'POST', body: { files: list, note: q('[data-ppn]').value, is_internal: q('[data-ppi]').checked || !w } }); })
						.then(function () { toast('File added'); reload(); }, function (x) { WD.busy(e.target, false); ppk.status('failed', x.message); err(x); });
				};
			}
			c.querySelectorAll('[data-att-rm]').forEach(function (b) { b.onclick = function () { WD.confirm('Remove this file?', 'It is no longer shown to anyone. The removal is recorded.', 'Remove', true).then(function (ok) { if (ok) api('workspace/attachments/' + b.getAttribute('data-att-rm'), { method: 'DELETE' }).then(function () { toast('Removed'); reload(); }).catch(err); }); }; });
			if (q('[data-ah]')) q('[data-ah]').onclick = function () {
				WD.modal({ title: 'Assignment history: ' + p.name, body: '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>When</th><th>What</th><th>Workspace</th><th>By</th></tr></thead><tbody>' + d.assignment_history.map(function (h) { return '<tr><td data-th="When">' + esc(H.dt ? H.dt(h.at) : h.at) + '</td><td data-th="What">' + esc(h.label) + '</td><td data-th="Workspace">' + esc(h.workspace || '—') + '</td><td data-th="By">' + esc(h.by) + '</td></tr>'; }).join('') + '</tbody></table></div>', foot: '<button class="btn" data-close>Close</button>' });
			};
			if (q('[data-move]')) q('[data-move]').onclick = function () {
				var m = WD.modal({ title: 'Workspace for ' + p.name, size: 'sm', body: '<label class="field"><span>Workspace</span><select data-to>' + d.available_workspaces.map(function (x) { return '<option value="' + x.connection_id + '"' + (d.workspace && x.connection_id === d.workspace.connection_id ? ' selected' : '') + '>' + esc(x.name) + ' (' + esc(x.status_label) + ')</option>'; }).join('') + '</select></label><p class="muted small">Only Workspaces that hold no other main project are listed. The project and its package move. Sub-projects that follow it move too; a sub-project with its own Workspace keeps it. The client is notified.</p>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-ok>Save</button>' });
				m.el.querySelector('[data-ok]').onclick = function () { api('workspace/projects/' + p.id + '/workspace', { method: 'POST', body: { connection_id: +m.el.querySelector('[data-to]').value } }).then(function () { m.close(); toast('Workspace updated. The client was notified.'); reload(); }).catch(err); };
			};
			if (q('[data-newws]')) q('[data-newws]').onclick = function () {
				var acct = d.account_email;
				var m = WD.modal({ title: (p.parent ? 'Separate Workspace for ' : 'New Workspace for ') + p.name, body: (acct ? '<p class="muted small"><b>' + esc(cl.name) + '</b> already has a Neon Workspace account (' + esc(acct) + '). The new Workspace is added to that account now and the client gets an email and an in-app notification that opens this ' + (p.parent ? 'sub-project' : 'project') + '. No invitation, no new password, and the Terms are not asked again.</p>' : '<p class="muted small">The client gets one invitation. Accepting it creates their account (password, Terms and Privacy) and this Workspace. Later projects are added to the same account without another invitation.</p>') +
					'<div class="form-grid"><label class="field"><span>Workspace name</span><input data-n value="' + esc(p.name) + '" maxlength="190"></label><label class="field"><span>Business type <span class="req">*</span></span><select data-bt><option value="">Choose the business type</option>' + (d.business_types || []).map(function (t) { return '<option value="' + esc(t.key) + '">' + esc(t.label) + '</option>'; }).join('') + '</select><span class="hint">Sets the new Workspace&rsquo;s dashboard, navigation, modules and forms.</span></label>' + (acct ? '<input type="hidden" data-e value="">' : '<label class="field"><span>Invitation email</span><input type="email" data-e placeholder="The client\'s usual email" value="' + esc(cl && cl.email ? cl.email : '') + '"></label><label class="field"><span>Valid for (days)</span><input type="number" data-v min="1" max="60" value="14"></label>') + '<label class="check" style="align-self:end"><input type="checkbox" data-m checked> ' + (acct ? 'Email the client about it' : 'Email the invitation now') + '</label></div><div data-out></div>',
					foot: '<button class="btn" data-close>Close</button><button class="btn btn-primary" data-ok>' + (acct ? 'Add to the client\'s account' : 'Create invitation') + '</button>' });
				m.el.querySelector('[data-ok]').onclick = function (e) {
					var bt = m.el.querySelector('[data-bt]').value;
					if (!bt) { toast('Choose the business type first.'); m.el.querySelector('[data-bt]').focus(); return; }
					WD.busy(e.target, true);
					var vd = m.el.querySelector('[data-v]');
					api('project-hub/' + p.id + '/new-workspace', { method: 'POST', body: { workspace_name: m.el.querySelector('[data-n]').value, business_category: bt, email: m.el.querySelector('[data-e]').value, valid_days: vd ? vd.value : 14, send_email: m.el.querySelector('[data-m]').checked } }).then(function (r) {
						WD.busy(e.target, false);
						if (r.provisioned) { m.close(); toast(r.workspace_name + ' added to the client\'s account. The client was notified.'); reload(); return; }
						m.el.querySelector('[data-out]').innerHTML = '<div class="alert">' + (r.emailed ? 'Invitation emailed to ' + esc(r.email || '') + '.' : 'Not emailed, so send this link yourself.') + ' Single use, expires ' + esc(H.dt(r.expires_at)) + '.<br><input readonly value="' + esc(r.url) + '" onclick="this.select()"></div>';
						toast('Invitation created');
					}).catch(function (x) { WD.busy(e.target, false); err(x); });
				};
			};
			var wp = WD.wsProject || {};
			c.querySelectorAll('[data-pa]').forEach(function (b) { b.onclick = function () { wp.assign(shape, b.getAttribute('data-pa'), null, reload); }; });
			if (q('[data-pe]')) q('[data-pe]').onclick = function () { wp.assign(shape, d.package.kind, d.package, reload); };
			if (q('[data-pm]')) q('[data-pm]').onclick = function () { wp.migrate(shape, reload); };
			if (q('[data-ph]')) q('[data-ph]').onclick = function () { wp.history(shape); };
			c.querySelectorAll('[data-prm]').forEach(function (b) { b.onclick = function () { WD.confirm('Remove this package?', 'If it is the project\'s package, the project locks for the client. Its data and history stay.', 'Remove', true).then(function (ok) { if (ok) api('workspace/assignments/' + b.getAttribute('data-prm'), { method: 'DELETE' }).then(function () { toast('Removed'); reload(); }).catch(err); }); }; });
			if (q('[data-sp]')) q('[data-sp]').onclick = function () { WD.allocationForm({ id: cl.id, name: cl.name }, null, reload, { project_id: p.id }); };
			c.querySelectorAll('[data-spe]').forEach(function (b) { b.onclick = function () { var a = sp.filter(function (x) { return +x.id === +b.getAttribute('data-spe'); })[0]; WD.allocationForm({ id: cl.id, name: cl.name }, a, reload, { project_id: p.id }); }; });
			if (q('[data-addsub]')) q('[data-addsub]').onclick = function () { WD.projectForm(null, reload, { client_id: cl ? cl.id : '', parent_id: p.id }); };
		}).catch(function (e) { c.innerHTML = ''; c.appendChild(ui.errorBox(e, reload)); });
	};

	/* ---------------- Client page: projects of this client, assignable from here too ---------------- */
	WD.clientProjects = function (box, client) {
		var mine = S.projects.filter(function (p) { return +p.client_id === +client.id && !p.parent_id; });
		var subs = function (p) { return S.projects.filter(function (x) { return +x.parent_id === +p.id; }).length; };
		var manage = can('ndwd_manage_projects');
		box.innerHTML = '<div class="card-h"><h2>Projects</h2><span class="spacer"></span>' + (manage ? '<button class="btn btn-sm" data-asg>Assign existing project</button><button class="btn btn-sm btn-primary" data-new>' + I.plus + ' New project</button>' : '') + '</div><div class="card-b">' +
			(mine.length ? mine.map(function (p) { var s = subs(p); return '<a class="pp-sub" href="#/projects/' + p.id + '"><span><b>' + esc(p.name) + '</b>' + (s ? '<span class="muted small">' + s + ' sub-project' + (s === 1 ? '' : 's') + '</span>' : '') + '</span><span class="muted small">Open project →</span></a>'; }).join('') : '<p class="muted">No projects for this client yet.</p>') +
			'<p class="muted small">Workspace and packages are assigned on each project.</p></div>';
		var re = function () { WD.reloadBoot().then(function () { WD.clientProjects(box, client); }); };
		var n = box.querySelector('[data-new]');
		if (n) n.onclick = function () { WD.projectForm(null, function (id) { if (id) WD.go('/projects/' + id); else re(); }, { client_id: client.id }); };
		var a = box.querySelector('[data-asg]');
		if (a) a.onclick = function () {
			var pool = S.projects.filter(function (p) { return !p.parent_id && +p.client_id !== +client.id && !+p.is_archived; });
			if (!pool.length) { toast('Every main project already belongs to a client. Create a new one instead.'); return; }
			var m = WD.modal({ title: 'Assign a project to ' + client.name, size: 'sm', body: '<label class="field"><span>Project</span><select data-p>' + pool.map(function (p) { var o = WD.u.client(+p.client_id); return '<option value="' + p.id + '">' + esc(p.name) + (o ? ' (now ' + esc(o.name) + ')' : '') + '</option>'; }).join('') + '</select></label><p class="muted small">Its sub-projects come with it. A project with active packages for another client cannot move until those end.</p>', foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-ok>Assign</button>' });
			m.el.querySelector('[data-ok]').onclick = function () { api('clients/' + client.id + '/assign-project', { method: 'POST', body: { project_id: +m.el.querySelector('[data-p]').value } }).then(function () { m.close(); toast('Project assigned'); re(); }).catch(err); };
		};
	};

	var st = document.createElement('style');
	st.textContent = '.ph-card{padding:0}.ph-bar{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:16px;border-bottom:1px solid var(--border)}.ph-bar input[type=search]{flex:1 1 240px;width:auto;min-width:0}.ph-bar select{flex:0 1 190px;width:auto;min-width:140px}.ph-bar .spacer{flex:1}.ph-bar .btn{flex:0 0 auto}' +
		'[data-list]{padding:16px}.ph-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px}.ph-item{display:flex;flex-direction:column;gap:8px;padding:16px;border:1px solid var(--border);border-radius:12px;background:var(--surface);color:inherit;text-decoration:none;transition:border-color .15s,box-shadow .15s}.ph-item:hover{border-color:var(--primary);box-shadow:0 4px 18px rgba(0,0,0,.12)}' +
		'.ph-top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}.ph-name{font-size:16px}.ph-meta{color:var(--muted);font-size:13px}.ph-kv{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:0}.ph-kv dt,.pp-kv dt{font-size:12px;color:var(--muted);margin:0}.ph-kv dd,.pp-kv dd{margin:2px 0 0;font-size:14px}.ph-foot{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;margin-top:auto;padding-top:8px;border-top:1px solid var(--border)}' +
		'.pp-grid{display:grid;grid-template-columns:minmax(0,2fr) minmax(260px,1fr);gap:16px;align-items:start}.pp-main,.pp-side{display:flex;flex-direction:column;gap:16px}.pp-kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:0}.pp-kv .span-all{grid-column:1/-1}.pp-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}.pp-h3{font-size:14px;margin:16px 0 6px}.pp-row{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:8px 0;border-top:1px solid var(--border)}.pp-row:first-child{border-top:0}' +
		'.pp-sub{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;color:inherit;text-decoration:none}.pp-sub:hover{border-color:var(--primary)}.pp-sub > span:first-child{display:flex;flex-direction:column;gap:2px;min-width:0}.pp-access{margin-bottom:10px}.pp-ws{display:flex;gap:12px;align-items:flex-start}' +
		'@media (max-width:900px){.pp-grid{grid-template-columns:1fr}.ph-bar select{flex:1 1 140px}}' +
		/* Conversation on a task (1.18). */
		'.wsa-thread{max-height:420px;min-height:120px;overflow-y:auto;display:flex;flex-direction:column;gap:4px;padding:12px;margin-bottom:10px;border:1px solid var(--border);border-radius:12px;background:var(--surface-2,var(--surface))}' +
		'.chat-day{display:flex;align-items:center;gap:10px;margin:10px 0 6px;color:var(--muted);font-size:12px}.chat-day:before,.chat-day:after{content:"";flex:1;border-top:1px solid var(--border)}' +
		'.chat-row{display:flex;justify-content:flex-start}.chat-row.me{justify-content:flex-end}.chat-bubble{max-width:min(78%,560px);padding:8px 12px 6px;border-radius:14px 14px 14px 4px;background:var(--surface-3);box-shadow:0 1px 1px rgba(0,0,0,.06)}' +
		'.chat-row.me .chat-bubble{border-radius:14px 14px 4px 14px;background:rgba(91,108,245,.16)}.chat-bubble.int{background:var(--st-pending-bg);border:1px dashed var(--border)}' +
		'.chat-who{font-size:12px;font-weight:600;margin-bottom:2px;color:var(--muted)}.chat-flag{color:var(--warn,#b45309)}.chat-text{white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.45}.chat-meta{font-size:11px;color:var(--muted);text-align:right;margin-top:2px}' +
		'.chat-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:96px;color:var(--muted);font-size:13px}.chat-empty b{color:var(--text)}.chat-compose textarea{width:100%;min-height:64px;resize:vertical;font-size:14px}';
	/* Attachments, sub-project banner and project files (Phase 4). */
	st.textContent += '.att-list{display:flex;flex-wrap:wrap;gap:8px;margin:8px 0}.att{display:inline-flex;align-items:center;gap:8px;max-width:100%;border:1px solid var(--border);border-radius:10px;background:var(--surface-2);padding:4px 8px 4px 4px}.att-int{border-style:dashed}.att-bad{border-color:var(--danger,#dc2626)}' +
		'.att-open{display:inline-flex;align-items:center;gap:8px;min-height:40px;background:none;border:0;color:inherit;font:inherit;text-align:left;cursor:pointer;padding:2px 4px}.att-open:hover b{color:var(--link)}.att-ic{flex:none;display:inline-grid;place-items:center;width:32px;height:32px;border-radius:7px;background:var(--accent-soft);color:var(--accent);font:700 10px/1 inherit;letter-spacing:.03em}' +
		'.att-txt{display:flex;flex-direction:column;min-width:0}.att-txt b{font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:240px}.att-txt small{font-size:12px}.att-st{font-size:12px;font-weight:600;color:var(--muted);white-space:nowrap}.att-st.ok{color:var(--accent)}.att-st.bad{color:var(--danger,#dc2626)}' +
		'.att-pending{flex-direction:column}.att-pending .att{width:100%}.att-pending .att-txt{flex:1}.att-add{cursor:pointer}.chat-bubble .att{background:var(--surface)}.pp-parent{margin-bottom:16px}.pp-up{display:flex;flex-direction:column;gap:8px;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)}' +
		'@media (max-width:600px){.att-txt b{max-width:160px}}';
	document.head.appendChild(st);
})();
