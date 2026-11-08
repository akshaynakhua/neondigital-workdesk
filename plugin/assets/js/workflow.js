/* NeonDigital WorkDesk — Work Overview (organisation-wide, read-only) and My Profile. */
(function () {
	'use strict';

	var WD = window.WD, S = WD.S, I = WD.I, api = WD.api, V = WD.views;
	var esc = WD.u.esc, on = WD.u.on, can = WD.u.can;
	var ui = WD.ui, fmt = WD.fmt;

	function th(st, key, label, cls) {
		var cur = st.sort === key, asc = cur && st.dir === 'asc';
		return '<th class="sortable' + (cls ? ' ' + cls : '') + (cur ? ' sorted' : '') + '" data-sort="' + key + '" tabindex="0" aria-sort="' + (cur ? (asc ? 'ascending' : 'descending') : 'none') + '">' + esc(label) + '<i class="sort-ind">' + (cur ? (asc ? '▲' : '▼') : '↕') + '</i></th>';
	}

	/**
	 * Task table shared by Work Overview and Team Dashboard. Sorting and paging ask the server
	 * again (st holds sort, dir, page), so filters and search stay as they are.
	 */
	function workTable(box, res, st, reload) {
		if (!res.items.length) {
			box.innerHTML = ui.emptyState('No tasks match these filters', 'Change or reset the filters.', I.list);
			return;
		}
		var byId = {};
		res.items.forEach(function (t) { byId[t.id] = t; });
		var pages = Math.ceil(res.total / res.per_page);
		box.innerHTML = '<div class="table-wrap"><table class="tbl stack"><thead><tr>' + th(st, 'title', 'Task') + '<th>Assigned to</th><th>Team</th><th>Client / project</th>' + th(st, 'status', 'Status') + th(st, 'priority', 'Priority') + th(st, 'date', 'Scheduled') + th(st, 'due', 'Due') + '<th></th></tr></thead><tbody>' + res.items.map(function (t) {
			return '<tr class="click" data-wt="' + t.id + '"><td data-th="Task"><span class="muted mono small">#' + t.id + '</span> <b>' + esc(t.title) + '</b>' + (t.is_mine ? ' <span class="badge">Mine</span>' : '') + (t.is_overdue ? ' <span class="badge overdue">Overdue</span>' : '') + '</td>' +
				'<td data-th="Assigned to">' + (t.assignees.length ? esc(t.assignees.join(', ')) : '<span class="muted">Team pool</span>') + '</td>' +
				'<td data-th="Team">' + (t.teams.length ? esc(t.teams.join(', ')) : '<span class="muted">—</span>') + '</td>' +
				'<td data-th="Client / project">' + (t.client ? esc(t.client) : '<span class="muted">—</span>') + (t.project ? '<div class="muted small">' + esc(t.project) + '</div>' : '') + '</td>' +
				'<td data-th="Status">' + ui.statusBadge(t.status) + '</td><td data-th="Priority">' + ui.prioBadge(t.priority) + '</td>' +
				'<td data-th="Scheduled">' + esc(fmt.date(t.scheduled_date, true)) + '</td><td data-th="Due">' + (t.due_date ? esc(fmt.date(t.due_date, true)) : '<span class="muted">—</span>') + '</td>' +
				'<td class="actions">' + (t.can_pickup ? '<button type="button" class="btn btn-sm btn-primary" data-pick="' + t.id + '">Pick up</button>' : '') + '</td></tr>';
		}).join('') + '</tbody></table></div>' +
			(pages > 1 ? '<div class="toolbar wo-pager"><span class="muted small">' + res.total + ' tasks</span><span class="spacer"></span><button type="button" class="btn btn-sm btn-icon" data-pg="-1" aria-label="Previous page"' + (res.page <= 1 ? ' disabled' : '') + '>' + I.left + '</button><span class="small muted">' + res.page + ' / ' + pages + '</span><button type="button" class="btn btn-sm btn-icon" data-pg="1" aria-label="Next page"' + (res.page >= pages ? ' disabled' : '') + '>' + I.right + '</button></div>' : '');
		box.onclick = function (e) {
			var b = e.target.closest('[data-pick]');
			if (b) { e.stopPropagation(); WD.pickUp(+b.getAttribute('data-pick'), reload); return; }
			b = e.target.closest('[data-pg]');
			if (b) { st.page = res.page + +b.getAttribute('data-pg'); reload(); return; }
			var h = e.target.closest('th[data-sort]');
			if (h) {
				var k = h.getAttribute('data-sort');
				if (st.sort === k) st.dir = st.dir === 'asc' ? 'desc' : 'asc';
				else { st.sort = k; st.dir = k === 'title' || k === 'priority' ? 'asc' : 'desc'; }
				st.page = 1;
				reload();
				return;
			}
			var r = e.target.closest('[data-wt]');
			if (r) {
				var t = byId[r.getAttribute('data-wt')];
				if (t.can_open) WD.go('/tasks/' + t.id); else factsModal(t);
			}
		};
	}
	WD.workTable = workTable;

	/** What anyone may see of another team's task: facts only, no remarks and no actions. */
	function factsModal(t) {
		WD.modal({ title: 'Task #' + t.id, body: '<h3 style="margin-top:0">' + esc(t.title) + '</h3><dl class="kv"><dt>Status</dt><dd>' + ui.statusBadge(t.status) + '</dd><dt>Priority</dt><dd>' + ui.prioBadge(t.priority) + '</dd><dt>Assigned to</dt><dd>' + esc(t.assignees.join(', ') || 'Team pool') + '</dd><dt>Team</dt><dd>' + esc(t.teams.join(', ') || '—') + '</dd><dt>Client</dt><dd>' + esc(t.client || '—') + '</dd><dt>Project</dt><dd>' + esc(t.project || '—') + '</dd><dt>Scheduled</dt><dd>' + esc(fmt.date(t.scheduled_date, true)) + '</dd><dt>Due</dt><dd>' + esc(t.due_date ? fmt.date(t.due_date, true) : '—') + '</dd></dl><p class="muted small">Read-only overview. This task belongs to work you are not part of, so its remarks and actions stay with its team.</p>', foot: '<button class="btn btn-primary" data-close>Close</button>' });
	}

	/* Claims a pool task; the server decides who wins. A lost race just refreshes the list. */
	WD.pickUp = function (id, done) {
		return api('tasks/' + id + '/pickup', { method: 'POST' }).then(function () {
			WD.toast('Task picked up — it is now yours');
			if (done) done();
		}, function (e) {
			WD.toastError(e);
			if (done) done();
		});
	};

	/* ------------------------------------------------------------------ *
	 * Work Overview
	 * ------------------------------------------------------------------ */
	S.wo = S.wo || { sort: 'date', dir: 'desc', page: 1 };
	V.workOverview = function (c) {
		WD.setTitle('Work Overview', 'Organisation-wide visibility. Read-only: seeing a task here does not let you change it.');
		var f = S.wo, opts = null;
		c.innerHTML = '<div data-wo-f></div><div data-wo>' + ui.loading(8) + '</div>';
		var bar = c.querySelector('[data-wo-f]'), body = c.querySelector('[data-wo]');
		function sel(k, label, list, empty) {
			return '<label class="field"><span>' + esc(label) + '</span><select data-wf="' + k + '">' + WD.selectOptions(list, f[k] || '', empty) + '</select></label>';
		}
		function paintBar(o) {
			bar.innerHTML = '<section class="card filter-card"><div class="card-b"><div class="filter-grid">' +
				'<label class="field filter-q"><span>Search</span><input type="search" data-wf="q" placeholder="Title or #id" value="' + esc(f.q || '') + '"></label>' +
				'<label class="field"><span>From</span><input type="date" data-wf="from" value="' + esc(f.from || '') + '"></label><label class="field"><span>To</span><input type="date" data-wf="to" value="' + esc(f.to || '') + '"></label>' +
				sel('team_id', 'Team', o.teams, 'All teams') + sel('member_id', 'Member', o.members, 'All members') + sel('client_id', 'Client', o.clients, 'All clients') + sel('project_id', 'Project', o.projects, 'All projects') +
				'<label class="field"><span>Priority</span><select data-wf="priority"><option value="">All priorities</option>' + WD.mapOptions(S.priorities, f.priority || '') + '</select></label>' +
				'<label class="field"><span>Status</span><select data-wf="status"><option value="">All statuses</option>' + WD.mapOptions(S.statuses, f.status || '') + '</select></label>' +
				'</div><div class="filter-foot"><div class="seg" data-wo-who><button type="button" data-v="all">All tasks</button><button type="button" data-v="me">My tasks</button></div><span class="muted small" data-wo-range></span><span class="spacer"></span><button type="button" class="btn btn-sm btn-ghost" data-wo-reset>Clear filters</button></div></div></section>';
			var deb = WD.u.debounce(function () { f.page = 1; load(); }, 300);
			bar.querySelectorAll('[data-wf]').forEach(function (i) {
				i.addEventListener(i.type === 'search' ? 'input' : 'change', function () {
					f[i.getAttribute('data-wf')] = i.value;
					if (i.type === 'search') deb(); else { f.page = 1; load(); paintWho(); }
				});
			});
			bar.querySelector('[data-wo-who]').onclick = function (e) {
				var b = e.target.closest('button');
				if (!b) return;
				f.member_id = b.getAttribute('data-v') === 'me' ? String(S.user.id) : '';
				f.page = 1;
				paintBar(opts);
				load();
			};
			bar.querySelector('[data-wo-reset]').onclick = function () { S.wo = f = { sort: 'date', dir: 'desc', page: 1 }; paintBar(opts); load(); };
			paintWho();
		}
		function paintWho() {
			bar.querySelectorAll('[data-wo-who] button').forEach(function (b) { b.classList.toggle('on', (b.getAttribute('data-v') === 'me') === (String(f.member_id || '') === String(S.user.id))); });
		}
		function kpi(key, label, value, tone) {
			var on2 = f.view === key || f.status === key;
			return '<button type="button" class="stat' + (on2 ? ' is-on' : '') + '" data-wk="' + key + '" style="--tone:' + tone + '"><div class="k">' + esc(label) + '</div><div class="v">' + value + '</div></button>';
		}
		function load() {
			var p = {};
			Object.keys(f).forEach(function (k) { if (f[k]) p[k] = f[k]; });
			return api('work-overview', { params: p }).then(function (d) {
				if (!c.isConnected) return;
				if (!opts) { opts = d.options; paintBar(opts); }
				bar.querySelector('[data-wo-range]').textContent = fmt.date(d.from, true) + ' – ' + fmt.date(d.to, true) + ' · ' + d.counts.total + ' task' + (d.counts.total === 1 ? '' : 's') + ' · ' + d.pct + '% completed';
				var k = d.counts;
				body.innerHTML = '<div class="stats wo-stats">' +
					kpi('pending', 'Pending', k.pending, 'var(--st-pending)') + kpi('in_process', 'In Process', k.in_process, 'var(--st-process)') + kpi('rejected', 'Rejected', k.rejected, 'var(--st-rejected)') + kpi('completed', 'Completed', k.completed, 'var(--st-done)') +
					kpi('urgent', 'Urgent', k.urgent, 'var(--pr-urgent)') + kpi('overdue', 'Overdue', k.overdue, 'var(--danger)') + kpi('due_today', 'Due today', k.due_today, 'var(--brand-1)') + kpi('upcoming', 'Upcoming', k.upcoming, 'var(--brand-3)') +
					'</div>' +
					'<section class="card section-gap"><div class="card-h"><h2>Tasks</h2><span class="spacer"></span><span class="muted small">' + d.tasks.total + ' shown</span></div><div data-wo-tasks></div></section>' +
					'<div class="grid grid-2 section-gap"><section class="card"><div class="card-h"><h2>Member workload</h2></div>' + perfTable(d.members, 'Member', d.max_open, true) + '</section>' +
					'<section class="card"><div class="card-h"><h2>Team performance</h2></div>' + perfTable(d.teams, 'Team', 0, false) + '</section></div>';
				workTable(body.querySelector('[data-wo-tasks]'), d.tasks, f, load);
				on(body, 'click', '[data-wk]', function (e, b) {
					var key = b.getAttribute('data-wk'), isSt = !!S.statuses[key];
					var same = isSt ? f.status === key : f.view === key;
					f.status = ''; f.view = '';
					if (!same) { if (isSt) f.status = key; else f.view = key; }
					f.page = 1;
					var st = bar.querySelector('[data-wf="status"]');
					if (st) { st.value = f.status; if (WD.refreshDropdown) WD.refreshDropdown(st); }
					load();
				});
				on(body, 'click', '[data-wm]', function (e, r) { f.member_id = r.getAttribute('data-wm'); f.page = 1; paintBar(opts); load(); });
			}, function (e) { body.innerHTML = ''; body.appendChild(ui.errorBox(e, load)); });
		}
		function perfTable(rows, label, maxOpen, isMember) {
			if (!rows.length) return ui.emptyState('Nothing in this selection');
			return '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>' + esc(label) + '</th><th class="num">Open</th>' + (isMember ? '<th style="width:16%"></th>' : '') + '<th class="num">Pending</th><th class="num">In Process</th><th class="num">Rejected</th><th class="num">Done</th><th class="num">Overdue</th><th class="num">Completion</th></tr></thead><tbody>' + rows.map(function (r) {
				return '<tr' + (isMember ? ' class="click" data-wm="' + r.user_id + '"' : '') + '><td data-th="' + esc(label) + '">' + (isMember ? '<span style="display:inline-flex;gap:8px;align-items:center">' + ui.avatar(r.user_id, 'sm') + esc(r.name) + '</span>' : esc(r.name)) + '</td><td data-th="Open" class="num">' + r.open + '</td>' + (isMember ? '<td><div class="bar"><i style="width:' + (r.open / maxOpen * 100) + '%"></i></div></td>' : '') + '<td data-th="Pending" class="num">' + r.pending + '</td><td data-th="In Process" class="num">' + r.in_process + '</td><td data-th="Rejected" class="num">' + r.rejected + '</td><td data-th="Done" class="num">' + r.completed + '</td><td data-th="Overdue" class="num"' + (r.overdue ? ' style="color:var(--danger);font-weight:600"' : '') + '>' + r.overdue + '</td><td data-th="Completion" class="num">' + r.completion_pct + '%</td></tr>';
			}).join('') + '</tbody></table></div>';
		}
		return load();
	};

	/* ------------------------------------------------------------------ *
	 * My Profile
	 * ------------------------------------------------------------------ */
	V.profile = function (c) {
		WD.setTitle('My Profile', 'Your picture, display handle, notifications and account details');
		c.innerHTML = ui.loading(4);
		return api('me/profile').then(function (p) {
			c.innerHTML = '<div class="grid grid-2"><section class="card"><div class="card-h"><h2>Picture and colour</h2></div><div class="card-b"><div class="pf-head">' + ui.avatar(S.user.id, 'xl') + '<div><b>' + esc(p.name) + '</b><div class="muted small">Shown on dashboards, task lists, teams and member views.</div></div></div><button type="button" class="btn" data-pic style="margin-top:12px">' + I.edit + ' Change picture or colour</button></div></section>' +
				'<section class="card"><div class="card-h"><h2>Display handle</h2></div><div class="card-b"><form data-h class="form-grid"><label class="field span-2"><span>Handle <span class="hint">(optional — letters, numbers, dots, dashes, underscores)</span></span><input name="handle" maxlength="31" value="' + esc(p.handle) + '" placeholder="e.g. akshay.n"></label><div class="span-2"><button class="btn btn-primary">Save handle</button></div></form></div></section>' +
				'<section class="card" style="grid-column:1/-1"><div class="card-h"><h2>Account details</h2></div><div class="card-b"><dl class="kv"><dt>Official name</dt><dd>' + esc(p.name) + '</dd><dt>Registered email</dt><dd>' + esc(p.email) + '</dd><dt>Role</dt><dd>' + esc(WD.roleLabel(p.role)) + '</dd><dt>Teams</dt><dd>' + (p.teams.length ? p.teams.map(function (t) { return esc(t.name) + (t.manager ? ' <span class="badge">Manager</span>' : ''); }).join(', ') : '<span class="muted">No team</span>') + '</dd></dl>' +
				(p.can_admin ? '<p class="small muted" style="margin-bottom:0">You can change names and emails under <a href="#/admin/members">Members &amp; Roles</a>.</p>' : '<div class="alert alert-info small" style="margin:12px 0 0"><b>Contact Administration</b> to change your official name or registered email.</div>') + '</div></section><div data-push style="grid-column:1/-1"></div></div>';
			WD.pushSettings(c.querySelector('[data-push]'));
			c.querySelector('[data-pic]').onclick = function () { WD.openProfile(S.user.id); };
			c.querySelector('[data-h]').onsubmit = function (e) {
				e.preventDefault();
				var b = e.target.querySelector('button');
				WD.busy(b, true);
				api('me/profile', { method: 'POST', body: { handle: e.target.handle.value } }).then(function (n) {
					WD.busy(b, false);
					e.target.handle.value = n.handle;
					S.user.handle = n.handle;
					WD.toast('Handle saved');
				}, function (er) { WD.busy(b, false); WD.toastError(er); });
			};
		}, function (e) { c.innerHTML = ''; c.appendChild(ui.errorBox(e, function () { V.profile(c); })); });
	};
})();
