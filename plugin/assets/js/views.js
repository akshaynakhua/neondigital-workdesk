/* NeonDigital WorkDesk — views. Depends on core.js (window.WD). */
(function () {
	'use strict';

	var WD = window.WD, S = WD.S, I = WD.I, api = WD.api, V = WD.views;
	var esc = WD.u.esc, el = WD.u.el, $ = WD.u.$, $$ = WD.u.$$, on = WD.u.on, can = WD.u.can;
	var ui = WD.ui, fmt = WD.fmt;

	function stat(key, label, value, tone, warn) {
		return '<button class="stat' + (warn && value ? ' warn' : '') + '" data-goto="' + esc(key) + '" style="--tone:' + (tone || 'transparent') + '"><div class="k">' + esc(label) + '</div><div class="v">' + esc(value) + '</div></button>';
	}
	function card(title, body, extra) {
		return '<section class="card"><div class="card-h"><h2>' + esc(title) + '</h2><span class="spacer"></span>' + (extra || '') + '</div><div class="card-b flush" ' + body + '></div></section>';
	}
	function ring(pct, label, sub) {
		return '<div class="ring-wrap"><div class="ring" style="--p:' + Math.max(0, Math.min(100, pct)) + '"><b>' + Math.round(pct) + '%</b></div><div><div style="font-weight:600">' + esc(label) + '</div><div class="muted small">' + sub + '</div></div></div>';
	}
	function openTasks(preset) {
		S.taskPreset = preset;
		WD.go('/tasks');
	}
	function bindGoto(c, scope) {
		on(c, 'click', '[data-goto]', function (e, b) {
			var k = b.getAttribute('data-goto');
			var p = { scope: scope, view: k };
			if (k === 'active') p.view = 'today';
			if (k === 'completed_today' || k === 'completed_month' || k === 'completed') p.view = 'completed';
			if (k === 'carried') p.view = 'carried';
			openTasks(p);
		});
	}
	function activityList(items) {
		if (!items.length) return ui.emptyState('No recent activity');
		return '<ul class="timeline" style="padding:0 16px">' + items.map(function (a) {
			return '<li>' + ui.avatar(a.user_id, 'sm') + '<div class="body"><b>' + esc(a.user) + '</b> ' + esc(a.label) +
				(a.task_id ? ' <a href="#/tasks/' + a.task_id + '">' + esc(a.task_title || '#' + a.task_id) + '</a>' : '') +
				WD.activityDetails(a) + '</div><span class="when">' + esc(fmt.dt(a.created_at)) + '</span></li>';
		}).join('') + '</ul>';
	}
	function definitions(defs) {
		return '<details class="defs" style="margin-top:12px"><summary style="cursor:pointer">How are these numbers calculated?</summary><ul style="padding-left:18px;margin:8px 0 0">' +
			Object.keys(defs || {}).map(function (k) { return '<li>' + esc(defs[k]) + '</li>'; }).join('') + '</ul></details>';
	}
	function greeting() {
		var h = parseInt(new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Kolkata', hour: 'numeric', hour12: false }).format(new Date()), 10);
		return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
	}
	function longDate(d) {
		var p = d.split('-');
		var dt = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2]));
		return dt.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
	}

	/** Worksheet grouping: overdue → carried forward → scheduled → completed. */
	function groupWorksheet(items, date) {
		var g = { overdue: [], carried: [], today: [], done: [] };
		items.forEach(function (t) {
			if (t.status === 'completed') g.done.push(t);
			else if (t.is_overdue) g.overdue.push(t);
			else if (t.scheduled_date < date) g.carried.push(t);
			else g.today.push(t);
		});
		return g;
	}
	function renderGroups(container, items, date) {
		var g = groupWorksheet(items, date);
		var sections = [
			['overdue', 'Overdue', g.overdue, 'danger'],
			['carried', 'Carried forward', g.carried, ''],
			['today', date === S.today ? 'Scheduled today' : 'Scheduled ' + fmt.date(date), g.today, ''],
			['done', 'Completed ' + (date === S.today ? 'today' : 'on ' + fmt.date(date)), g.done, '']
		];
		if (!items.length) {
			container.innerHTML = ui.emptyState('Nothing on this worksheet', 'Unfinished tasks carry forward automatically. Use “New Task” to add work.', I.sheet);
			return;
		}
		container.innerHTML = sections.filter(function (s) { return s[2].length; }).map(function (s) {
			return '<div class="group-h ' + s[3] + '">' + esc(s[1]) + ' <span class="n">' + s[2].length + '</span></div><ul class="tasks">' + s[2].map(function (t) { return WD.taskRow(t); }).join('') + '</ul>';
		}).join('');
		bindList(container);
	}
	function bindList(container) {
		// Reuse core list behaviour on pre-rendered markup.
		if (container._wired) return;
		container._wired = true;
		container.addEventListener('click', function (e) {
			var tg = e.target.closest('[data-toggle]');
			if (tg) { e.stopPropagation(); WD.changeStatus(tg.getAttribute('data-toggle'), tg.classList.contains('on') ? 'pending' : 'completed'); return; }
			if (e.target.closest('select, a, button')) return;
			var row = e.target.closest('[data-task]');
			if (row) WD.go('/tasks/' + row.getAttribute('data-task'));
		});
		container.addEventListener('change', function (e) {
			var s = e.target.closest('[data-status]');
			if (s) WD.changeStatus(s.getAttribute('data-status'), s.value);
		});
		container.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && e.target.matches('[data-task]')) WD.go('/tasks/' + e.target.getAttribute('data-task'));
		});
	}

	/* ------------------------------------------------------------------ *
	 * My Dashboard
	 * ------------------------------------------------------------------ */
	V.dashboard = function (c, o) {
		WD.setTitle('My Dashboard', function () { return greeting() + ', ' + S.user.name.split(' ')[0] + ' · ' + WD.nowLabel(); });
		if (!o || !o.silent) c.innerHTML = '<div class="stats">' + new Array(9).join('<div class="stat"><div class="skel" style="width:60%"></div><div class="skel" style="height:26px;margin-top:8px;width:40%"></div></div>') + '</div>';
		return Promise.all([api('dashboard/me'), api('tasks', { params: { scope: 'mine', view: 'today', per_page: 100 } }), api('leaves').catch(function () { return null; })]).then(function (r) {
			var d = r[0], ws = r[1], k = d.counts, lv = r[2];
			c.innerHTML =
				'<div class="stats">' +
				stat('active', 'Active today', k.active, 'var(--brand-2)') +
				stat('due_today', 'Due today', k.due_today, 'var(--brand-1)') +
				stat('pending', 'Pending', k.pending || 0, 'var(--st-pending)') +
				stat('in_process', 'In Process', k.in_process || 0, 'var(--st-process)') +
				stat('rejected', 'Rejected today', k.rejected || 0, 'var(--st-rejected)') +
				stat('completed_today', 'Completed today', k.completed_today, 'var(--st-done)') +
				stat('urgent', 'Urgent', k.urgent, 'var(--pr-urgent)', true) +
				stat('overdue', 'Overdue', k.overdue, 'var(--danger)', true) +
				stat('upcoming', 'Upcoming', k.upcoming, 'var(--brand-3)') +
				'</div>' +
				'<div class="grid grid-main">' +
				'<div class="grid">' +
				card('Today’s worksheet', 'data-ws', '<span class="muted small">' + ws.total + ' item' + (ws.total === 1 ? '' : 's') + '</span><a class="btn btn-sm" href="#/worksheet">Open worksheet</a>') +
				card('Upcoming', 'data-up', '<a class="btn btn-sm btn-ghost" href="#/calendar">Calendar</a>') +
				'</div>' +
				'<div class="grid" style="align-content:start">' +
				'<section class="card"><div class="card-h"><h2>My completion</h2></div><div class="card-b">' +
				ring(d.completion.pct, 'This month', esc(d.completion.completed) + ' of ' + esc(d.completion.in_scope) + ' tasks completed<br>' + esc(k.completed_month) + ' completed this month') + definitions({ c: d.definitions.completion, s: d.definitions.in_scope }) + '</div></section>' +
				card('My leave', 'data-leave', '<button class="btn btn-sm btn-primary" data-apply-leave>' + I.plus + ' Apply for leave</button>') +
				card('Reminders', 'data-rem') +
				card('Overdue', 'data-od') +
				card('My recent activity', 'data-act') +
				'</div></div>';
			bindGoto(c, 'mine');
			renderGroups(c.querySelector('[data-ws]'), ws.items, S.today);
			WD.taskList(c.querySelector('[data-up]'), d.upcoming, { emptyTitle: 'Nothing scheduled ahead', showDate: true });
			WD.taskList(c.querySelector('[data-od]'), d.overdue, { emptyTitle: 'No overdue tasks', emptyText: 'Nice work.' });
			c.querySelector('[data-rem]').innerHTML = d.reminders.length ? '<ul class="timeline" style="padding:0 16px">' + d.reminders.map(function (r) {
				return '<li>' + I.bell.replace('<svg', '<svg width="16" height="16"') + '<div class="body"><a href="#/tasks/' + r.task_id + '">' + esc(r.title) + '</a></div><span class="when">' + esc(fmt.dt(r.reminder_at)) + '</span></li>';
			}).join('') + '</ul>' : ui.emptyState('No reminders set', 'Add a reminder when creating or editing a task.', I.bell);
			c.querySelector('[data-act]').innerHTML = activityList(d.activity);
			var lvBox = c.querySelector('[data-leave]');
			var upcoming = lv ? lv.mine.filter(function (l) { return (l.status === 'pending' || l.status === 'approved') && l.end_date >= S.today; }) : [];
			lvBox.innerHTML = (lv && lv.can_review && S.leave_pending ? '<a class="alert alert-warn" style="display:block;margin:12px 16px 0" href="#/leave">' + S.leave_pending + ' leave request' + (S.leave_pending === 1 ? '' : 's') + ' waiting for your review</a>' : '') +
				(upcoming.length ? '<ul class="timeline" style="padding:0 16px">' + upcoming.slice(0, 4).map(function (l) {
					return '<li>' + I.plane.replace('<svg', '<svg width="16" height="16"') + '<div class="body"><b>' + esc(leaveDates(l)) + '</b> · ' + esc(l.type_label) + '</div>' + leaveBadge(l.status) + '</li>';
				}).join('') + '</ul><div style="padding:0 16px 12px"><a href="#/leave" class="small">All my leave</a></div>' : ui.emptyState('No upcoming leave', 'Apply here; your manager will be notified.', I.plane));
			lvBox.parentNode.querySelector('[data-apply-leave]').onclick = function () { openLeaveForm(function () { V.dashboard(c, { silent: true }); }); };
		});
	};

	/* ------------------------------------------------------------------ *
	 * Team Dashboard
	 * ------------------------------------------------------------------ */
	S.teamFilters = S.teamFilters || {};
	function filterBar(f, opts) {
		opts = opts || {};
		var teams = S.teams.filter(function (t) { return can('ndwd_view_all') || S.user.teams.indexOf(t.id) >= 0; });
		var members = S.members.filter(function (m) { return opts.teamRequired && f.team_id ? m.teams.indexOf(+f.team_id) >= 0 : can('ndwd_view_all') || m.teams.some(function (t) { return S.user.teams.indexOf(t) >= 0; }) || m.id === S.user.id; });
		var h = '<div class="toolbar">';
		if (opts.search) h += '<input type="search" data-f="q" placeholder="Search title or #id" value="' + esc(f.q || '') + '" aria-label="Search">';
			if (opts.team !== false) h += '<select data-f="team_id" aria-label="Team">' + WD.selectOptions(teams, f.team_id, opts.teamRequired ? false : 'All teams') + '</select>';
		if (opts.member !== false && (can('ndwd_view_reports') || can('ndwd_view_all'))) h += '<select data-f="member_id" aria-label="Member">' + WD.selectOptions(members, f.member_id, 'All members') + '</select>';
		if (opts.client) h += '<select data-f="client_id" aria-label="Client">' + WD.selectOptions(S.clients || [], f.client_id, 'All clients') + '</select>';
		if (opts.project !== false) h += '<select data-f="project_id" aria-label="Project">' + WD.selectOptions(S.projects, f.project_id, 'All projects') + '</select>';
		if (opts.priority) h += '<select data-f="priority" aria-label="Priority"><option value="">All priorities</option>' + WD.mapOptions(S.priorities, f.priority) + '</select>';
		if (opts.status) h += '<select data-f="status" aria-label="Status"><option value="">All statuses</option>' + WD.mapOptions(S.statuses, f.status) + '</select>';
		if (opts.tag) h += '<select data-f="tag_id" aria-label="Tag">' + WD.selectOptions(S.tags, f.tag_id, 'All tags') + '</select>';
		if (opts.work) h += '<select data-f="work_type" aria-label="Work type"><option value="">All work types</option>' + WD.mapOptions(S.work_types, f.work_type) + '</select>';
		if (opts.range) h += '<input type="date" data-f="from" value="' + esc(f.from || '') + '" aria-label="From date" title="From"><input type="date" data-f="to" value="' + esc(f.to || '') + '" aria-label="To date" title="To">';
		h += '<span class="spacer"></span><button class="btn btn-sm btn-ghost" data-reset>Reset</button></div>';
		return h;
	}
	function wireFilters(c, f, onChange) {
		var deb = WD.u.debounce(onChange, 300);
		$$('[data-f]', c).forEach(function (inp) {
			inp.addEventListener(inp.type === 'search' ? 'input' : 'change', function () {
				f[inp.getAttribute('data-f')] = inp.value;
				(inp.type === 'search' ? deb : onChange)();
			});
		});
		var r = c.querySelector('[data-reset]');
		if (r) r.onclick = function () { Object.keys(f).forEach(function (k) { delete f[k]; }); onChange(true); };
	}

	V.team = function (c, o) {
		WD.setTitle('Team Dashboard', 'Only the selected team\'s tasks, members and activity');
		var f = S.teamFilters;
		// One team at a time: its tasks and figures only. Default: the user's first team.
		var myTeams = S.teams.filter(function (t) { return can('ndwd_view_all') || S.user.teams.indexOf(t.id) >= 0; });
		if (!f.team_id && myTeams.length) f.team_id = String(myTeams[0].id);
		if (!f.sort) { f.sort = 'date'; f.dir = 'desc'; f.page = 1; }
		if (!o || !o.silent || !c.querySelector('[data-team-body]')) {
			var shownTeam = f.team_id;
			c.innerHTML = filterBar(f, { search: true, client: true, priority: true, status: true, range: true, teamRequired: true }) + '<p class="page-note">Counts reflect the selected team\'s current open work; completion uses the selected date range (default: this month).</p><div data-team-body>' + ui.loading(8) + '</div>';
			wireFilters(c, f, function (reset) {
				f.page = 1;
				if (reset || f.team_id !== shownTeam) { if (!reset) f.member_id = ''; V.team(c); } else load();
			});
		}
		var body = c.querySelector('[data-team-body]');
		function load() {
			return api('dashboard/team', { params: f }).then(function (d) {
				var k = d.counts;
				var wl = d.workload.slice().sort(function (a, b) { return b.open - a.open; });
				var maxOpen = Math.max.apply(null, [1].concat(wl.map(function (w) { return w.open; })));
				body.innerHTML =
					'<div class="stats">' +
					stat('today', 'Active tasks', k.active, 'var(--brand-2)') +
					stat('due_today', 'Due today', k.due_today, 'var(--brand-1)') +
					stat('completed', 'Completed', k.completed, 'var(--st-done)') +
					stat('pending', 'Pending', k.pending, 'var(--st-pending)') +
					stat('in_process', 'In Process', k.in_process, 'var(--st-process)') +
					stat('rejected', 'Rejected today', k.rejected || 0, 'var(--st-rejected)') +
					stat('urgent', 'Urgent', k.urgent, 'var(--pr-urgent)', true) +
					stat('overdue', 'Overdue', k.overdue, 'var(--danger)', true) +
					stat('upcoming', 'Upcoming', k.upcoming, 'var(--brand-3)') +
					'</div>' +
					'<div class="grid">' +
					'<section class="card"><div class="card-h"><h2>Member workload</h2><span class="spacer"></span><span class="muted small">' + esc(fmt.date(d.period[0])) + ' – ' + esc(fmt.date(d.period[1])) + '</span></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Member</th><th class="num">Open</th><th></th><th class="num">In Process</th><th class="num">Urgent</th><th class="num">Overdue</th><th class="num">Upcoming</th><th class="num">Done</th><th class="num">Completion</th></tr></thead><tbody>' +
					(wl.length ? wl.map(function (w) {
						return '<tr class="click" data-member="' + w.user_id + '"><td><span style="display:inline-flex;gap:8px;align-items:center">' + ui.avatar(w.user_id, 'sm') + esc(w.name) + '</span></td><td class="num">' + w.open + '</td><td style="width:18%"><div class="bar"><i style="width:' + (w.open / maxOpen * 100) + '%"></i></div></td><td class="num">' + w.in_process + '</td><td class="num">' + w.urgent + '</td><td class="num"' + (w.overdue ? ' style="color:var(--danger);font-weight:600"' : '') + '>' + w.overdue + '</td><td class="num">' + w.upcoming + '</td><td class="num">' + w.completed + '</td><td class="num">' + w.completion_pct + '%</td></tr>';
					}).join('') : '<tr><td colspan="9">' + ui.emptyState('No members in this view', 'Add members to teams under Admin → Teams.') + '</td></tr>') +
					'</tbody></table></div></section>' +
					'<div class="grid grid-2">' +
					'<section class="card"><div class="card-h"><h2>Team completion</h2></div><div class="card-b">' + ring(d.completion.pct, 'Selected period', esc(d.completion.completed) + ' of ' + esc(d.completion.in_scope) + ' distinct tasks completed') + definitions(d.definitions) + '</div></section>' +
					'<section class="card"><div class="card-h"><h2>Attendance today</h2><span class="spacer"></span><span class="muted small">' + d.attendance.present + ' / ' + d.attendance.total + ' signed in · ' + d.attendance.active + ' active</span></div><div class="card-b flush"><div class="table-wrap"><table class="tbl"><tbody>' +
					d.attendance.rows.map(function (a) {
						return '<tr><td>' + esc(a.name) + '</td><td>' + (a.login_count ? '<span class="badge s-completed">Present</span>' : (a.activity_count ? '<span class="badge s-in_process">Active</span>' : '<span class="badge">Not signed in</span>')) + '</td><td class="muted small">' + (a.first_login ? 'In ' + esc(fmt.time(a.first_login)) : '') + '</td><td class="num small">' + a.activity_count + ' act.</td></tr>';
					}).join('') + '</tbody></table></div></div></section>' +
					'</div></div>' +
					'<div class="section-gap">' + card('Shared task pool — unassigned', 'data-pool') + '</div>' +
					'<section class="card section-gap"><div class="card-h"><h2>Team tasks</h2><span class="spacer"></span><span class="muted small">' + d.tasks.total + ' task' + (d.tasks.total === 1 ? '' : 's') + '</span></div><div data-team-tasks></div></section>' +
					'<div class="grid grid-2 section-gap">' + card('Upcoming work', 'data-up') + card('Recent team activity', 'data-act') + '</div>';
				WD.workTable(body.querySelector('[data-team-tasks]'), d.tasks, f, load);
				var poolBox = body.querySelector('[data-pool]');
				poolBox.innerHTML = d.pool.length ? '<ul class="tasks">' + d.pool.map(function (t) { return '<li class="task" data-task="' + t.id + '" tabindex="0"><div style="min-width:0;grid-column:1/3"><div class="t-title">' + esc(t.title) + '</div><div class="t-meta">' + (t.due_date ? '<span>' + I.clock + 'Due ' + esc(fmt.date(t.due_date, true)) + '</span>' : '') + '<span>Scheduled ' + esc(fmt.date(t.scheduled_date, true)) + '</span></div></div><div class="t-right">' + ui.prioBadge(t.priority) + (t.can_pickup ? '<button type="button" class="btn btn-sm btn-primary" data-pool-pick="' + t.id + '">Pick up</button>' : '') + '</div></li>'; }).join('') + '</ul>' : ui.emptyState('Nothing waiting', 'Tasks given to the team without a member appear here for anyone to pick up.', I.check);
				poolBox.onclick = function (e) {
					var pb = e.target.closest('[data-pool-pick]');
					if (pb) { e.stopPropagation(); WD.pickUp(+pb.getAttribute('data-pool-pick'), load); return; }
					var pr = e.target.closest('[data-task]');
					if (pr) WD.go('/tasks/' + pr.getAttribute('data-task'));
				};
				bindGoto(body, can('ndwd_view_all') ? '' : 'team');
				on(body, 'click', '[data-member]', function (e, r) { openTasks({ member_id: r.getAttribute('data-member'), team_id: f.team_id, view: 'open' }); });
				WD.taskList(body.querySelector('[data-up]'), d.upcoming, { emptyTitle: 'Nothing scheduled ahead', showDate: true });
				body.querySelector('[data-act]').innerHTML = activityList(d.activity);
			}, function (e) { body.innerHTML = ''; body.appendChild(ui.errorBox(e, load)); });
		}
		return load();
	};

	/* ------------------------------------------------------------------ *
	 * Daily Worksheet
	 * ------------------------------------------------------------------ */
	S.ws = S.ws || { scope: 'mine' };
	V.worksheet = function (c, o) {
		var w = S.ws;
		var date = w.date || S.today;
		WD.setTitle('Daily Worksheet', longDate(date) + ' · generated automatically from your tasks');
		if (!o || !o.silent || !c.querySelector('[data-wsb]')) {
			var scopes = '<div class="seg" data-scope><button data-v="mine">Mine</button>' + (can('ndwd_view_team') ? '<button data-v="team">Team</button>' : '') + '</div>';
			c.innerHTML = '<div class="toolbar"><div class="datebar"><button class="btn btn-sm btn-icon" data-prev aria-label="Previous day">' + I.left + '</button><input type="date" data-date aria-label="Worksheet date"><button class="btn btn-sm btn-icon" data-next aria-label="Next day">' + I.right + '</button><button class="btn btn-sm" data-today>Today</button></div>' + scopes + '<span class="spacer"></span><span class="muted small" data-count></span></div>' +
				'<div class="alert alert-info small" data-note style="display:none"></div>' +
				'<section class="card"><div class="card-b flush" data-wsb>' + ui.loading(6) + '</div></section>' +
				'<p class="page-note" style="margin-top:12px">Pending and In Process tasks carry forward until they are completed or rejected. Completed and Rejected tasks appear only on the day they were closed and never carry forward. Future tasks appear on their scheduled date.</p>';
			var di = c.querySelector('[data-date]');
			di.value = date;
			di.onchange = function () { w.date = di.value || S.today; V.worksheet(c); };
			c.querySelector('[data-prev]').onclick = function () { w.date = fmt.addDays(w.date || S.today, -1); V.worksheet(c); };
			c.querySelector('[data-next]').onclick = function () { w.date = fmt.addDays(w.date || S.today, 1); V.worksheet(c); };
			c.querySelector('[data-today]').onclick = function () { w.date = null; V.worksheet(c); };
			var seg = c.querySelector('[data-scope]');
			$$('button', seg).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === w.scope); });
			seg.onclick = function (e) { var b = e.target.closest('button'); if (b) { w.scope = b.getAttribute('data-v'); V.worksheet(c); } };
		}
		var note = c.querySelector('[data-note]');
		note.style.display = date !== S.today ? '' : 'none';
		note.textContent = date < S.today ? 'Viewing a past date: shows what was on the worksheet that day (tasks completed later appear as open).' : date > S.today ? 'Viewing a future date: a preview of what will be on the worksheet if nothing is completed before then.' : '';
		var params = { view: 'worksheet', date: date, per_page: 500 };
		if (w.scope === 'mine') params.scope = 'mine';
		return api('tasks', { params: params }).then(function (r) {
			c.querySelector('[data-count]').textContent = r.total + ' task' + (r.total === 1 ? '' : 's');
			renderGroups(c.querySelector('[data-wsb]'), r.items, date);
		});
	};

	/* ------------------------------------------------------------------ *
	 * Tasks (filters + search)
	 * ------------------------------------------------------------------ */
	var QUICK = [['all', 'All'], ['today', 'Today'], ['upcoming', 'Upcoming'], ['overdue', 'Overdue'], ['completed', 'Completed'], ['pending', 'Pending'], ['in_process', 'In Process'], ['rejected', 'Rejected'], ['urgent', 'Urgent'], ['high', 'High priority'], ['followups', 'Follow-ups']];
	S.tf = S.tf || { view: 'today', scope: 'mine', page: 1 };
	V.tasks = function (c) {
		var f = S.tf;
		if (S.taskPreset) {
			S.tf = f = Object.assign({ page: 1 }, S.taskPreset);
			if (!f.scope && !can('ndwd_view_all')) f.scope = '';
			S.taskPreset = null;
		}
		if (S.pendingSearch !== undefined && S.pendingSearch !== null) {
			f.q = S.pendingSearch; f.view = 'all'; f.scope = ''; f.page = 1; S.pendingSearch = null;
		}
		WD.setTitle('Task Management', 'Combine filters to find any task');
		c.innerHTML =
			'<div class="toolbar"><div class="seg" data-quick>' + QUICK.map(function (q) { return '<button data-v="' + q[0] + '">' + esc(q[1]) + '</button>'; }).join('') + '</div></div>' +
			'<div class="toolbar"><div class="seg" data-scope><button data-v="mine">Assigned to me</button><button data-v="">' + (can('ndwd_view_all') ? 'Everyone' : 'All I can see') + '</button></div></div>' +
			filterBar(f, { search: true, tag: true, work: true, priority: false, range: true, member: true, client: true }) +
			'<section class="card"><div class="card-h"><h2 data-sum>Tasks</h2><span class="spacer"></span><div data-pager></div></div><div class="card-b flush" data-list>' + ui.loading(6) + '</div></section>';
		function paint() {
			$$('[data-quick] button', c).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === (f.view || 'all')); });
			$$('[data-scope] button', c).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === (f.scope || '')); });
		}
		c.querySelector('[data-quick]').onclick = function (e) { var b = e.target.closest('button'); if (b) { f.view = b.getAttribute('data-v'); f.page = 1; paint(); load(); } };
		c.querySelector('[data-scope]').onclick = function (e) { var b = e.target.closest('button'); if (b) { f.scope = b.getAttribute('data-v'); f.page = 1; paint(); load(); } };
		wireFilters(c, f, function (reset) { f.page = 1; if (reset) { f.view = 'all'; f.scope = 'mine'; V.tasks(c); } else load(); });
		paint();
		var list = c.querySelector('[data-list]');
		function load() {
			var p = Object.assign({}, f);
			if (p.view === 'all') delete p.view;
			p.per_page = 50;
			return api('tasks', { params: p }).then(function (r) {
				var label = (QUICK.filter(function (q) { return q[0] === (f.view || 'all'); })[0] || ['', 'Tasks'])[1];
				c.querySelector('[data-sum]').textContent = label + ' · ' + r.total;
				WD.taskList(list, r.items, { emptyTitle: 'No tasks match these filters', emptyText: 'Try a different view or reset the filters.', showDate: true });
				var pages = Math.ceil(r.total / 50);
				var pg = c.querySelector('[data-pager]');
				pg.innerHTML = pages > 1 ? '<button class="btn btn-sm btn-icon" data-pp ' + (f.page <= 1 ? 'disabled' : '') + ' aria-label="Previous page">' + I.left + '</button> <span class="small muted">' + f.page + ' / ' + pages + '</span> <button class="btn btn-sm btn-icon" data-np ' + (f.page >= pages ? 'disabled' : '') + ' aria-label="Next page">' + I.right + '</button>' : '';
				if (pages > 1) {
					pg.querySelector('[data-pp]').onclick = function () { f.page--; load(); };
					pg.querySelector('[data-np]').onclick = function () { f.page++; load(); };
				}
			}, function (e) { list.innerHTML = ''; list.appendChild(ui.errorBox(e, load)); });
		}
		V.tasks.refresh = function () { load(); };
		return load();
	};

	/* ------------------------------------------------------------------ *
	 * Calendar
	 * ------------------------------------------------------------------ */
	S.cal = S.cal || { scope: 'mine' };
	V.calendar = function (c) {
		var st = S.cal;
		st.month = st.month || S.today.slice(0, 7);
		var p = st.month.split('-'), y = +p[0], m = +p[1];
		var title = new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('en-IN', { month: 'long', year: 'numeric', timeZone: 'UTC' });
		WD.setTitle('Calendar', 'Tasks and follow-ups on their scheduled dates');
		c.innerHTML = WD.calendarTabs(st.scope === 'mine' ? 'mine' : 'team') + '<div class="toolbar"><div class="datebar"><button class="btn btn-sm btn-icon" data-pm aria-label="Previous month">' + I.left + '</button><b style="min-width:150px;text-align:center">' + esc(title) + '</b><button class="btn btn-sm btn-icon" data-nm aria-label="Next month">' + I.right + '</button><button class="btn btn-sm" data-tm>This month</button></div>' +
			'<select data-f="team_id" style="width:auto">' + WD.selectOptions(S.teams, st.team_id, 'All teams') + '</select><span class="spacer"></span></div>' +
			'<section class="card"><div data-cal>' + ui.loading(6) + '</div></section><section class="card section-gap"><div class="card-h"><h2 data-dtitle></h2><span class="spacer"></span><button class="btn btn-sm" data-add>' + I.plus + ' Add task on this day</button></div><div class="card-b flush" data-dlist></div></section>';
		c.querySelector('[data-f]').onchange = function (e) { st.team_id = e.target.value; V.calendar(c); };
		function shift(n) { var d = new Date(Date.UTC(y, m - 1 + n, 1)); st.month = d.toISOString().slice(0, 7); st.sel = null; V.calendar(c); }
		c.querySelector('[data-pm]').onclick = function () { shift(-1); };
		c.querySelector('[data-nm]').onclick = function () { shift(1); };
		c.querySelector('[data-tm]').onclick = function () { st.month = S.today.slice(0, 7); st.sel = S.today; V.calendar(c); };
		return api('calendar', { params: { month: st.month, scope: st.scope, team_id: st.team_id } }).then(function (r) {
			var days = r.days || {};
			var first = new Date(Date.UTC(y, m - 1, 1));
			var offset = (first.getUTCDay() + 6) % 7;
			var start = fmt.addDays(r.from, -offset);
			var h = '<div class="cal">' + ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(function (d) { return '<div class="dow">' + d + '</div>'; }).join('');
			var sel = st.sel || (S.today.slice(0, 7) === st.month ? S.today : r.from);
			for (var i = 0; i < 42; i++) {
				var d = fmt.addDays(start, i);
				if (i >= 35 && d.slice(0, 7) !== st.month) break;
				var items = days[d] || [];
				h += '<div class="day' + (d.slice(0, 7) !== st.month ? ' out' : '') + (d === S.today ? ' today' : '') + (d === sel ? ' sel' : '') + '" data-day="' + d + '" tabindex="0"><div class="n">' + (+d.slice(8)) + '</div>' +
					items.slice(0, 3).map(function (t) { return '<div class="ev p-' + t.priority + (t.status === 'completed' ? ' done' : '') + '" title="' + esc(t.title) + '">' + (t.parent_id ? '↪ ' : '') + esc(t.title) + '</div>'; }).join('') +
					(items.length > 3 ? '<div class="more">+' + (items.length - 3) + ' more</div>' : '') +
					(items.length ? '<div class="dots">' + items.slice(0, 6).map(function (t) { return '<i class="p-' + t.priority + '"></i>'; }).join('') + '</div>' : '') + '</div>';
			}
			c.querySelector('[data-cal]').innerHTML = h + '</div>';
			function showDay(d) {
				st.sel = d;
				$$('.day', c).forEach(function (x) { x.classList.toggle('sel', x.getAttribute('data-day') === d); });
				c.querySelector('[data-dtitle]').textContent = longDate(d);
				WD.taskList(c.querySelector('[data-dlist]'), days[d] || [], { emptyTitle: 'Nothing scheduled', emptyText: 'Unfinished work from earlier days also appears on the Daily Worksheet.' });
				c.querySelector('[data-add]').onclick = function () {
					WD.openTaskForm('create');
					setTimeout(function () { var i = $('.modal [name=scheduled_date]'); if (i) i.value = d; }, 10);
				};
			}
			on(c.querySelector('[data-cal]'), 'click', '[data-day]', function (e, x) { showDay(x.getAttribute('data-day')); });
			showDay(sel);
		});
	};

	/* ------------------------------------------------------------------ *
	 * Notifications
	 * ------------------------------------------------------------------ */
	V.notifications = function (c) {
		WD.setTitle('Notifications', 'Assignments, reminders, due dates and updates');
		var unreadOnly = S.notifFilter === 'unread';
		c.innerHTML = '<div class="toolbar"><div class="seg" role="tablist" aria-label="Show"><button data-f="all"' + (unreadOnly ? '' : ' class="on"') + '>All notifications</button><button data-f="unread"' + (unreadOnly ? ' class="on"' : '') + '>Unread</button></div><span class="spacer"></span>' +
			'<button class="btn btn-sm" data-all>Mark all as read</button><button class="btn btn-sm" data-clear>Clear all read</button></div><section class="card"><div data-list' + (unreadOnly ? ' data-unread-only' : '') + '>' + ui.loading(5) + '</div></section>';
		$$('[data-f]', c).forEach(function (b) { b.onclick = function () { S.notifFilter = b.getAttribute('data-f'); V.notifications(c); }; });
		c.querySelector('[data-all]').onclick = function () {
			api('notifications/read', { method: 'POST', body: { all: true } }).then(function (r) { S.unread = r.unread; WD.updateBadge(); V.notifications(c); }, WD.toastError);
		};
		c.querySelector('[data-clear]').onclick = function () {
			WD.confirm('Clear read notifications?', 'All notifications you have already read will be removed from your history. Unread notifications are kept.', 'Clear read', true).then(function (ok) {
				if (!ok) return;
				api('notifications/clear-read', { method: 'POST' }).then(function (r) {
					S.unread = r.unread; WD.updateBadge();
					WD.toast(r.removed ? r.removed + ' read notification' + (r.removed === 1 ? '' : 's') + ' cleared' : 'No read notifications to clear');
					V.notifications(c);
				}, WD.toastError);
			});
		};
		return api('notifications', { params: { limit: 100, unread: unreadOnly ? 1 : 0 } }).then(function (r) {
			S.unread = r.unread; WD.updateBadge();
			var l = c.querySelector('[data-list]');
			l.innerHTML = r.items.length ? r.items.map(WD.notifItem).join('') : ui.emptyState(unreadOnly ? 'You are all caught up' : 'No notifications yet', '', I.bell);
			WD.bindNotifList(l);
		});
	};

	/* ------------------------------------------------------------------ *
	 * Attendance
	 * ------------------------------------------------------------------ */
	S.att = S.att || { period: 'daily' };
	function periodBar(st) {
		return '<div class="seg" data-period><button data-v="daily">Daily</button><button data-v="weekly">Weekly</button><button data-v="monthly">Monthly</button></div><input type="date" data-pdate aria-label="Date" style="width:auto">';
	}
	function wirePeriod(c, st, reload) {
		$$('[data-period] button', c).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === st.period); });
		c.querySelector('[data-period]').onclick = function (e) { var b = e.target.closest('button'); if (b) { st.period = b.getAttribute('data-v'); reload(); } };
		var d = c.querySelector('[data-pdate]');
		d.value = st.date || S.today;
		d.onchange = function () { st.date = d.value; reload(); };
	}
	function reportFilters(st) {
		var h = '';
		if (can('ndwd_view_reports') || can('ndwd_view_all')) {
			h += '<select data-rf="team_id" style="width:auto" aria-label="Team">' + WD.selectOptions(reportTeams(), st.team_id, 'All teams') + '</select>';
			h += '<select data-rf="member_id" style="width:auto" aria-label="Member">' + WD.selectOptions(reportMembers(st.team_id), st.member_id, 'All members') + '</select>';
		}
		return h;
	}
	function wireReportFilters(c, st, reload) {
		$$('[data-rf]', c).forEach(function (s) { s.onchange = function () { st[s.getAttribute('data-rf')] = s.value; reload(); }; });
	}
	V.attendance = function (c) {
		var st = S.att;
		WD.setTitle('Attendance', 'Based on successful sign-ins and meaningful work activity');
		c.innerHTML = '<div class="toolbar">' + periodBar(st) + reportFilters(st) + '<span class="spacer"></span></div><div data-body>' + ui.loading(6) + '</div>';
		wirePeriod(c, st, function () { V.attendance(c); });
		wireReportFilters(c, st, function () { V.attendance(c); });
		return api('attendance', { params: { period: st.period, date: st.date, team_id: st.team_id, member_id: st.member_id } }).then(function (r) {
			var body = c.querySelector('[data-body]');
			body.innerHTML = '<p class="page-note">' + esc(fmt.date(r.from, true)) + (r.from !== r.to ? ' – ' + esc(fmt.date(r.to, true)) : '') + ' · ' + esc(r.definitions.attendance) + '</p>' +
				'<section class="card"><div class="card-h"><h2>Summary</h2></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Member</th><th class="num">Attendance days</th><th class="num">Active days</th><th class="num">Logins</th><th class="num">Meaningful activities</th><th class="num">Leave days</th></tr></thead><tbody>' +
				(r.summary.length ? r.summary.map(function (s) { return '<tr><td>' + esc(s.name) + '</td><td class="num">' + s.attendance_days + '</td><td class="num">' + s.active_days + '</td><td class="num">' + s.login_count + '</td><td class="num">' + s.activity_count + '</td><td class="num">' + (s.leave_days || 0) + '</td></tr>'; }).join('') : '<tr><td colspan="6">' + ui.emptyState('No members') + '</td></tr>') +
				'</tbody></table></div></section>' +
				'<section class="card section-gap"><div class="card-h"><h2>Daily records</h2><span class="spacer"></span><span class="muted small">One record per member per day (IST)</span></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Member</th><th>First login</th><th>Last login</th><th class="num">Logins</th><th>First activity</th><th>Last activity</th><th class="num">Activities</th></tr></thead><tbody>' +
				(r.rows.length ? r.rows.map(function (a) {
					return '<tr><td>' + esc(fmt.date(a.date, true)) + '</td><td>' + esc(a.name) + '</td><td>' + esc(fmt.time(a.first_login)) + '</td><td>' + esc(fmt.time(a.last_login)) + '</td><td class="num">' + a.login_count + '</td><td>' + esc(fmt.time(a.first_activity)) + '</td><td>' + esc(fmt.time(a.last_activity)) + '</td><td class="num">' + a.activity_count + '</td></tr>';
				}).join('') : '<tr><td colspan="8">' + ui.emptyState('No attendance records in this period', 'A record is created when a member signs in or does meaningful work.', I.clock) + '</td></tr>') +
				'</tbody></table></div></section>';
		});
	};

	/* ------------------------------------------------------------------ *
	 * Reports
	 * ------------------------------------------------------------------ */
	/**
	 * Generate report: choose type, format (PDF / Excel / CSV), range and — when allowed —
	 * team and member. The file is built on request and downloaded; nothing is stored.
	 */
	function reportTeams() {
		var perms = S.user.team_perms || {};
		return S.teams.filter(function (t) { return can('ndwd_view_all') || (perms[t.id] && perms[t.id].view_reports); });
	}
	function reportMembers(teamId) {
		if (can('ndwd_view_all')) return S.members.filter(function (m) { return !teamId || m.teams.indexOf(+teamId) >= 0; });
		var ok = reportTeams().map(function (t) { return t.id; });
		return S.members.filter(function (m) { return m.id === S.user.id || m.teams.some(function (t) { return ok.indexOf(t) >= 0 && (!teamId || t === +teamId); }); });
	}
	function openReportForm(pre) {
		pre = pre || {};
		var month = S.today.slice(0, 7);
		var types = { tasks: 'Work & tasks', productivity: 'Team performance & member-wise summary', attendance: 'Attendance', leave: 'Leave', activity: 'Activity log' };
		var scoped = can('ndwd_view_reports') || can('ndwd_view_all');
		var form = el('<form novalidate><div class="alert alert-error hidden" data-err role="alert"></div><div class="form-grid">' +
			'<label class="field span-2"><span>Report</span><select name="type">' + WD.mapOptions(types, pre.type || 'productivity') + '</select></label>' +
			'<div class="field span-2"><span>Format</span><div class="seg fmt-seg" data-fmt><button type="button" data-v="pdf">' + I.file + ' PDF</button><button type="button" data-v="xlsx">' + I.sheet + ' Excel (.xlsx)</button><button type="button" data-v="csv">' + I.list + ' CSV (.csv)</button></div></div>' +
			'<div class="field span-2"><span>Period</span><div class="seg" data-quick><button type="button" data-q="this">This month</button><button type="button" data-q="last">Last month</button><button type="button" data-q="fy">This financial year</button></div></div>' +
			'<label class="field"><span>From</span><input type="date" name="from" required></label><label class="field"><span>To</span><input type="date" name="to" required></label>' +
			(scoped ? '<label class="field"><span>Team</span><select name="team_id">' + WD.selectOptions(reportTeams(), pre.team_id || '', can('ndwd_view_all') ? 'All teams' : 'All my teams') + '</select></label><label class="field"><span>Member</span><select name="member_id"></select></label>' : '<p class="span-2 muted small">The report covers your own work and attendance.</p>') +
			'</div></form>');
		var fmtVal = pre.format || 'pdf';
		function paintFmt() { $$('[data-fmt] button', form).forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === fmtVal); }); }
		form.querySelector('[data-fmt]').onclick = function (e) { var b = e.target.closest('button'); if (b) { fmtVal = b.getAttribute('data-v'); paintFmt(); } };
		paintFmt();
		function setRange(q) {
			var y = +S.today.slice(0, 4), m = +S.today.slice(5, 7);
			if (q === 'this') { form.from.value = month + '-01'; form.to.value = S.today; }
			if (q === 'last') { var d = new Date(Date.UTC(y, m - 2, 1)), e = new Date(Date.UTC(y, m - 1, 0)); form.from.value = d.toISOString().slice(0, 10); form.to.value = e.toISOString().slice(0, 10); }
			if (q === 'fy') { var fy = m >= 4 ? y : y - 1; form.from.value = fy + '-04-01'; form.to.value = S.today; }
		}
		form.querySelector('[data-quick]').onclick = function (e) { var b = e.target.closest('button'); if (b) setRange(b.getAttribute('data-q')); };
		if (pre.from && pre.to) { form.from.value = pre.from; form.to.value = pre.to; } else setRange('this');
		function paintMembers() {
			if (!form.member_id) return;
			form.member_id.innerHTML = WD.selectOptions(reportMembers(form.team_id.value), pre.member_id || '', 'All members');
		}
		if (form.team_id) { form.team_id.onchange = function () { pre.member_id = ''; paintMembers(); }; paintMembers(); }
		var md = WD.modal({ title: 'Generate report', body: form, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-go>' + I.download + ' Generate & download</button>' });
		var go = md.el.querySelector('[data-go]'), err = form.querySelector('[data-err]');
		go.onclick = function () {
			err.classList.add('hidden');
			var body = { type: form.type.value, format: fmtVal, from: form.from.value, to: form.to.value, team_id: form.team_id ? form.team_id.value : '', member_id: form.member_id ? form.member_id.value : '' };
			if (!body.from || !body.to || body.from > body.to) { err.textContent = 'Choose a valid date range.'; err.classList.remove('hidden'); return; }
			WD.download('reports/export', { body: body, button: go, busyLabel: 'Generating…' }).then(function () { md.close(); }, function (e) { err.textContent = e.message + ' — press Generate again to retry.'; err.classList.remove('hidden'); });
		};
	}
	WD.openReportForm = openReportForm;

	S.rep = S.rep || { period: 'monthly' };
	V.reports = function (c) {
		var st = S.rep;
		WD.setTitle('Productivity Reports', 'Individual and team performance by period');
		c.innerHTML = '<div class="toolbar">' + periodBar(st) + reportFilters(st) + '<select data-rf="project_id" style="width:auto">' + WD.selectOptions(S.projects, st.project_id, 'All projects') + '</select><span class="spacer"></span><button class="btn btn-primary" data-genrep>' + I.download + ' Generate report</button></div><div data-body>' + ui.loading(8) + '</div>';
		c.querySelector('[data-genrep]').onclick = function () { openReportForm({ team_id: st.team_id, member_id: st.member_id }); };
		wirePeriod(c, st, function () { V.reports(c); });
		wireReportFilters(c, st, function () { V.reports(c); });
		return api('reports/productivity', { params: { period: st.period, date: st.date, team_id: st.team_id, member_id: st.member_id, project_id: st.project_id } }).then(function (r) {
			var t = r.team, body = c.querySelector('[data-body]');
			var max = Math.max.apply(null, [1].concat(r.daily.map(function (d) { return d.completed; })));
			body.innerHTML = '<p class="page-note">' + esc(fmt.date(r.from, true)) + (r.from !== r.to ? ' – ' + esc(fmt.date(r.to, true)) : '') + (st.member_id ? ' · ' + esc(WD.u.memberName(+st.member_id)) : '') + '</p>' +
				'<div class="stats">' +
				stat('', 'Tasks assigned', t.in_scope) + stat('', 'Completed', t.completed, 'var(--st-done)') + stat('', 'Pending (open)', t.open_at_end, 'var(--st-pending)') +
				stat('', 'Overdue', t.overdue, 'var(--danger)', true) + stat('', 'Carry-forward', t.carry_forward, 'var(--brand-3)') + stat('', 'Completion', t.completion_pct + '%', 'var(--brand-1)') +
				'</div>' +
				'<div class="grid grid-main"><section class="card"><div class="card-h"><h2>Individual productivity</h2></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Member</th><th class="num">Assigned</th><th class="num">Completed</th><th class="num">Open</th><th class="num">Overdue</th><th class="num">Carried</th><th class="num">Completion</th><th class="num">Att. days</th><th class="num">Logins</th><th class="num">Activities</th><th class="num">Leave</th></tr></thead><tbody>' +
				(r.members.length ? r.members.map(function (m) {
					return '<tr><td>' + esc(m.name) + '</td><td class="num">' + m.in_scope + '</td><td class="num">' + m.completed + '</td><td class="num">' + m.open_at_end + '</td><td class="num">' + m.overdue + '</td><td class="num">' + m.carry_forward + '</td><td class="num"><div style="display:flex;gap:6px;align-items:center;justify-content:flex-end"><div class="bar" style="width:50px"><i style="width:' + m.completion_pct + '%"></i></div>' + m.completion_pct + '%</div></td><td class="num">' + m.attendance_days + '</td><td class="num">' + m.login_count + '</td><td class="num">' + m.activity_count + '</td><td class="num">' + (m.leave_days || 0) + '</td></tr>';
				}).join('') : '<tr><td colspan="11">' + ui.emptyState('No members') + '</td></tr>') +
				'</tbody></table></div></section>' +
				'<section class="card"><div class="card-h"><h2>Work completed over time</h2></div><div class="card-b"><div class="chart">' +
				r.daily.map(function (d) { return '<div class="col' + (d.completed ? '' : ' zero') + '" data-tip="' + esc(fmt.date(d.date)) + ': ' + d.completed + '"><i style="height:' + (d.completed / max * 100) + '%"></i></div>'; }).join('') +
				'</div><div class="chart-axis"><span>' + esc(fmt.date(r.from)) + '</span><span>' + esc(fmt.date(r.to)) + '</span></div>' + definitions(r.definitions) + '</div></section></div>' +
				'<div class="grid grid-2 section-gap">' +
				breakdown('Workload by team', r.by_team) + breakdown('Workload by project / client', r.by_project) + '</div>';
		});
	};
	function breakdown(title, rows) {
		return '<section class="card"><div class="card-h"><h2>' + esc(title) + '</h2></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Name</th><th class="num">In scope</th><th class="num">Completed</th><th class="num">Open</th></tr></thead><tbody>' +
			(rows.length ? rows.map(function (b) { return '<tr><td>' + esc(b.name) + '</td><td class="num">' + b.in_scope + '</td><td class="num">' + b.completed + '</td><td class="num">' + b.open + '</td></tr>'; }).join('') : '<tr><td colspan="4">' + ui.emptyState('No data') + '</td></tr>') +
			'</tbody></table></div></section>';
	}

	/* ------------------------------------------------------------------ *
	 * Monthly archives
	 * ------------------------------------------------------------------ */
	V.archives = function (c) {
		WD.setTitle('Monthly Archives', 'Monthly report packs (Excel, CSV, PDF) and on-demand report downloads');
		c.innerHTML = ui.loading(5);
		return api('archives').then(function (r) {
			var gen = '<section class="card" style="margin-bottom:16px"><div class="card-b"><div class="toolbar" style="margin:0">' +
				'<button class="btn btn-primary" data-custom>' + I.download + ' Generate report</button><span class="muted small" style="max-width:560px">Pick PDF, Excel or CSV, a date range and filters. The file downloads straight away and is not stored on the server.</span></div>' +
				(r.can_generate ? '<div class="toolbar" style="margin:12px 0 0;border-top:1px solid var(--border);padding-top:12px"><label class="field" style="margin:0"><span>Month</span><input type="month" data-month value="' + esc(r.previous) + '" max="' + esc(S.today.slice(0, 7)) + '" style="width:auto"></label><button class="btn" data-gen style="align-self:flex-end">' + I.archive + ' Rebuild monthly pack</button><span class="muted small" style="align-self:flex-end;max-width:520px">The previous month’s pack is generated and emailed to the Admin automatically after 01:00 IST on the 1st. Packs are kept on the server for the retention period, then removed once they have been delivered.</span></div>' : '') +
				'</div></section>';
			c.innerHTML = gen + '<section class="card"><div class="card-h"><h2>Monthly packs</h2></div><div data-list></div></section>';
			c.querySelector('[data-custom]').onclick = function () { openReportForm(); };
			var list = c.querySelector('[data-list]');
			list.innerHTML = r.reports.length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>Month</th><th>Status</th><th>Delivery</th><th>Files</th></tr></thead><tbody>' + r.reports.map(function (rep) {
				var badge = { success: 's-completed', partial: 's-pending', failed: 's-blocked', running: 's-in_process' }[rep.status] || '';
				var mail = rep.email_status === 'sent' ? '<span class="badge s-completed">Emailed</span> <span class="muted small">' + esc(fmt.dt(rep.emailed_at)) + '</span>' : rep.email_status ? '<span class="badge s-blocked">Email ' + esc(rep.email_status) + '</span>' + (rep.email_error ? '<div class="small" style="color:var(--danger);max-width:260px">' + esc(rep.email_error) + '</div>' : '') : '<span class="muted small">Not emailed</span>';
				var acts = r.can_generate && rep.status !== 'failed' && !rep.purged_at ? '<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap"><button class="btn btn-sm" data-mail="' + rep.id + '">' + I.mail + ' Email now</button>' + (rep.confirmed_at ? '<span class="badge">Saved ✓ ' + esc(fmt.date(rep.confirmed_at)) + '</span>' : '<button class="btn btn-sm btn-ghost" data-confirm="' + rep.id + '" title="Confirm you have saved these files">Mark as saved</button>') + '</div>' : '';
				return '<tr><td data-th="Month"><b>' + esc(rep.period) + '</b> <span class="muted">v' + rep.version + '</span><div class="muted small">' + esc(fmt.dt(rep.finished_at || rep.started_at)) + ' · ' + esc(rep.generated_by) + '</div></td><td data-th="Status"><span class="badge ' + badge + '">' + esc(rep.status) + '</span>' + (rep.error ? '<div class="small" style="color:var(--danger);max-width:260px">' + esc(rep.error) + '</div>' : '') + '</td><td data-th="Delivery">' + (r.can_generate ? mail + acts : '') + '</td><td data-th="Files">' +
					(rep.purged_at ? '<span class="muted small">Removed from the server on ' + esc(fmt.date(rep.purged_at, true)) + ' (retention policy)</span>' : rep.files.length ? rep.files.map(function (f) {
						return f.download ? '<div><button class="linkbtn" data-dl="' + esc(f.download) + '" data-name="' + esc(f.name) + '">' + I.download.replace('<svg', '<svg width="13" height="13" style="vertical-align:-2px"') + ' ' + esc(f.label) + '</button> <span class="muted small">' + Math.max(1, Math.round(f.size / 1024)) + ' KB</span></div>' : '<div class="small" style="color:var(--danger)">✕ ' + esc(f.label) + ': ' + esc(f.error) + '</div>';
					}).join('') : '<span class="muted">No files</span>') + '</td></tr>';
			}).join('') + '</tbody></table></div>' : ui.emptyState('No monthly packs yet', r.can_generate ? 'The first pack is created on the 1st of next month, or rebuild one now.' : 'Your individual monthly reports will appear here.', I.archive);
			on(list, 'click', '[data-dl]', function (e, b) { WD.download(b.getAttribute('data-dl'), { button: b, busyLabel: 'Downloading…', filename: b.getAttribute('data-name') }).catch(function () {}); });
			on(list, 'click', '[data-mail]', function (e, b) {
				WD.busy(b, true, 'Sending…');
				api('archives/' + b.getAttribute('data-mail') + '/email', { method: 'POST' }).then(function (rep) {
					WD.toast(rep.email_status === 'sent' ? 'Reports emailed' : 'Email ' + rep.email_status + ': ' + (rep.email_error || ''), rep.email_status === 'sent' ? 'success' : 'error');
					V.archives(c);
				}, function (er) { WD.busy(b, false); WD.toastError(er); });
			});
			on(list, 'click', '[data-confirm]', function (e, b) {
				WD.confirm('Mark these reports as saved?', 'Confirm that you have downloaded and kept this month’s files. Once a month is delivered or saved, its operational records can be removed by the retention cleanup after the retention period.', 'Mark as saved').then(function (ok) {
					if (ok) api('archives/' + b.getAttribute('data-confirm') + '/confirm', { method: 'POST' }).then(function () { V.archives(c); }, WD.toastError);
				});
			});
			var g = c.querySelector('[data-gen]');
			if (g) g.onclick = function () {
				var month = c.querySelector('[data-month]').value;
				if (!month) return;
				var exists = r.reports.some(function (x) { return x.period === month && x.status !== 'failed'; });
				var go = exists ? WD.confirm('Generate a new version?', 'An archive for ' + month + ' already exists. A new version will be created and the existing files kept.', 'Generate new version') : Promise.resolve(true);
				go.then(function (ok) {
					if (!ok) return;
					WD.busy(g, true, 'Generating…');
					api('archives/generate', { method: 'POST', body: { month: month } }).then(function (rep) {
						WD.toast(rep.status === 'success' ? 'Report generated: ' + rep.files.length + ' files' : 'Report finished with status: ' + rep.status, rep.status === 'success' ? 'success' : 'error');
						V.archives(c);
					}, function (e) { WD.busy(g, false); WD.toastError(e); });
				});
			};
		});
	};

	/* ------------------------------------------------------------------ *
	 * Admin: members & roles
	 * ------------------------------------------------------------------ */
	V.members = function (c) {
		WD.setTitle('Members & Roles', 'Add members, set roles and permissions, activate or deactivate accounts');
		c.innerHTML = ui.loading(6);
		return api('members').then(function (r) {
			c.innerHTML = '<div class="toolbar"><input type="search" data-q placeholder="Search members" style="max-width:260px"><span class="spacer"></span><button class="btn btn-primary" data-add>' + I.plus + ' Add member</button></div>' +
				'<section class="card"><div class="table-wrap"><table class="tbl stack"><thead><tr><th>Member</th><th>Role</th><th>Teams</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody data-rows></tbody></table></div></section>' +
					'<p class="page-note" style="margin-top:12px">There are two roles: <b>Admin</b> has every permission across WorkDesk; <b>Member</b> has basic access to their own work. Team managers are chosen per team under <a href="#/admin/teams">Teams</a>, where each team’s permissions for managers and members are set — someone can manage one team and be an ordinary member of another. Extra company-wide permissions (for example “Archive any task” or “Accounts”) can be granted individually. Only people with “Grant administrative rights” can give admin-level access, and nobody can change their own role.</p>';
			function paint() {
				var q = (c.querySelector('[data-q]').value || '').toLowerCase();
				var rows = r.members.filter(function (m) { return !q || (m.name + ' ' + m.email + ' ' + m.login).toLowerCase().indexOf(q) >= 0; });
				c.querySelector('[data-rows]').innerHTML = rows.length ? rows.map(function (m) {
					return '<tr><td><div style="display:flex;gap:10px;align-items:center">' + ui.avatar(m.id) + '<div><b>' + esc(m.name) + '</b><div class="muted small">' + esc(m.email) + '</div></div></div></td>' +
						'<td data-th="Role">' + esc(WD.roleLabel(m.role)) + (m.extra_caps.length ? ' <span class="badge" title="Extra permissions">+' + m.extra_caps.length + '</span>' : '') + '</td>' +
						'<td class="small" data-th="Teams">' + (m.teams.map(function (t) { var tt = WD.u.team(t.team_id); return tt ? esc(tt.name) + (t.is_manager ? ' <span class="badge">Manager</span>' : '') : ''; }).join(', ') || '<span class="muted">—</span>') + '</td>' +
						'<td data-th="Status">' + (m.active ? '<span class="badge s-completed">Active</span>' : '<span class="badge s-blocked">Inactive</span>') + '</td>' +
						'<td class="small muted" data-th="Last login">' + esc(m.last_login ? fmt.dt(m.last_login) : 'Never') + '</td>' +
						'<td class="actions"><button class="btn btn-sm" data-edit="' + m.id + '">Edit</button>' + (WD.u.member(m.id) ? ' <button class="btn btn-sm btn-ghost" data-pic="' + m.id + '">Picture</button>' : '') + '</td></tr>';
				}).join('') : '<tr><td colspan="6">' + ui.emptyState('No members yet', 'Add your first member to get started.', I.users) + '</td></tr>';
			}
			paint();
			c.querySelector('[data-q]').oninput = paint;
			c.querySelector('[data-add]').onclick = function () { memberForm(null, r, function () { V.members(c); }); };
			on(c, 'click', '[data-pic]', function (e, b) { WD.openProfile(+b.getAttribute('data-pic')); });
			on(c, 'click', '[data-edit]', function (e, b) {
				var m = r.members.filter(function (x) { return x.id === +b.getAttribute('data-edit'); })[0];
				memberForm(m, r, function () { V.members(c); });
			});
		});
	};
	function teamPicker(selected) {
		var root = el('<div class="grid" style="gap:6px"></div>');
		if (!S.teams.length) { root.innerHTML = '<div class="muted small">No teams yet. Create teams under Admin → Teams.</div>'; }
		S.teams.forEach(function (t) {
			var cur = (selected || []).filter(function (x) { return x.team_id === t.id; })[0];
			root.appendChild(el('<div style="display:flex;gap:12px;align-items:center"><label class="check"><input type="checkbox" data-team="' + t.id + '"' + (cur ? ' checked' : '') + '> ' + esc(t.name) + '</label>' + (cur && cur.is_manager ? '<span class="badge">Team manager</span>' : '') + '</div>'));
		});
		if (S.teams.length) root.appendChild(el('<div class="muted small">Team managers and what they may do are set in Teams → Manage.</div>'));
		// Only membership is sent; the server keeps each existing manager position.
		root.getValue = function () {
			return $$('[data-team]', root).filter(function (i) { return i.checked; }).map(function (i) { return { team_id: +i.getAttribute('data-team') }; });
		};
		return root;
	}
	function memberForm(m, r, done) {
		var isNew = !m, self = m && m.id === S.user.id;
		var canGrant = can('ndwd_grant_admin');
		var form = el('<form><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
			'<label class="field"><span>Full name <span class="req">*</span></span><input type="text" name="name" required></label>' +
			'<label class="field"><span>Email <span class="req">*</span></span><input type="email" name="email" required></label>' +
			(isNew ? '<label class="field"><span>Username <span class="hint">(optional)</span></span><input type="text" name="login" autocomplete="off"></label><label class="field"><span>Password <span class="hint">(optional — at least 6 characters with an uppercase letter, a lowercase letter and a number; leave blank to email a set-password link)</span></span><input type="password" name="password" autocomplete="new-password" minlength="6" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{6,}"></label>' : '') +
			'<label class="field"><span>Role</span><select name="role"' + (self || (m && m.is_wp_admin) ? ' disabled' : '') + '>' + Object.keys(r.roles).filter(function (k) { return k !== 'ndwd_admin' || canGrant || (m && m.role === k); }).map(function (k) { return '<option value="' + k + '"' + ((m ? m.role : 'ndwd_member') === k ? ' selected' : '') + '>' + esc(r.roles[k]) + '</option>'; }).join('') + (m && m.is_wp_admin ? '<option selected>Site administrator</option>' : '') + '</select></label>' +
			(!isNew ? '<label class="field"><span>Status</span><select name="active"' + (self ? ' disabled' : '') + '><option value="1"' + (m.active ? ' selected' : '') + '>Active</option><option value="0"' + (!m.active ? ' selected' : '') + '>Inactive (cannot sign in)</option></select></label>' : '') +
			'<div class="field span-2"><span>Teams</span><div data-teams></div></div>' +
			(!isNew && canGrant && !self && !m.is_wp_admin ? '<div class="field span-2"><span>Extra permissions <span class="hint">(on top of the role)</span></span><div class="grid grid-2" style="gap:6px" data-caps>' + Object.keys(r.caps).map(function (k) {
				var inRole = m.effective.indexOf(k) >= 0 && m.extra_caps.indexOf(k) < 0;
				return '<label class="check small"><input type="checkbox" value="' + k + '"' + (m.extra_caps.indexOf(k) >= 0 || inRole ? ' checked' : '') + (inRole ? ' disabled title="Included in role"' : '') + '> ' + esc(r.caps[k]) + '</label>';
			}).join('') + '</div></div>' : '') +
			(self ? '<p class="span-2 muted small">You cannot change your own role, permissions or status.</p>' : '') +
			'</div></form>');
		form.name.value = m ? m.name : '';
		form.email.value = m ? m.email : '';
		var teams = teamPicker(m ? m.teams : []);
		form.querySelector('[data-teams]').appendChild(teams);
		var md = WD.modal({ title: isNew ? 'Add member' : 'Edit ' + m.name, body: form, foot: '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-save>' + (isNew ? 'Add member' : 'Save') + '</button>' });
		var save = md.el.querySelector('[data-save]'), err = form.querySelector('[data-err]');
		save.onclick = function () {
			var body = { name: form.name.value.trim(), email: form.email.value.trim(), teams: can('ndwd_manage_teams') ? teams.getValue() : undefined };
			if (!form.role.disabled) body.role = form.role.value;
			if (isNew) { body.login = form.login.value.trim(); body.password = form.password.value; }
			else if (!form.active.disabled) body.active = form.active.value === '1';
			var caps = form.querySelector('[data-caps]');
			if (caps) body.extra_caps = $$('input:checked:not([disabled])', caps).map(function (i) { return i.value; });
			WD.busy(save, true);
			api(isNew ? 'members' : 'members/' + m.id, { method: isNew ? 'POST' : 'PUT', body: body }).then(function (res) {
				md.close(); WD.toast(res.message || (isNew ? 'Member added' : 'Member updated')); WD.reloadBoot().then(done);
			}, function (e) { WD.busy(save, false); err.textContent = e.message; err.classList.remove('hidden'); });
		};
	}

	/* ------------------------------------------------------------------ *
	 * Admin: teams
	 * ------------------------------------------------------------------ */
	V.teams = function (c) {
		WD.setTitle('Teams', 'Create teams, choose team managers and set what each team can do');
		c.innerHTML = ui.loading(5);
		return api('teams').then(function (teams) {
			c.innerHTML = '<div class="toolbar"><span class="spacer"></span><button class="btn btn-primary" data-add>' + I.plus + ' New team</button></div><div class="grid grid-3" data-list></div>';
			var l = c.querySelector('[data-list]');
			l.innerHTML = teams.length ? teams.map(function (t) {
				return '<section class="card"><div class="card-h"><span style="width:10px;height:10px;border-radius:3px;background:' + esc(t.color) + '"></span><h2>' + esc(t.name) + '</h2><span class="spacer"></span>' + (t.is_active ? '' : '<span class="badge s-blocked">Inactive</span>') + '<button class="btn btn-sm" data-edit="' + t.id + '">Manage</button></div><div class="card-b">' +
					(t.description ? '<p class="muted small">' + esc(t.description) + '</p>' : '') +
					(t.members.length ? t.members.slice().sort(function (a, b) { return b.is_manager - a.is_manager; }).map(function (m) { return '<div style="display:flex;gap:8px;align-items:center;margin:6px 0">' + ui.avatar(m.user_id, 'sm') + esc(WD.u.memberName(m.user_id)) + (m.is_manager ? ' <span class="badge">Manager</span>' : '') + '</div>'; }).join('') : '<div class="muted small">No members yet</div>') +
					'</div></section>';
			}).join('') : '<div class="card" style="grid-column:1/-1">' + ui.emptyState('No teams yet', 'Create your first team — nothing is predefined.', I.team) + '</div>';
			c.querySelector('[data-add]').onclick = function () { teamForm(null, function () { V.teams(c); }); };
			on(l, 'click', '[data-edit]', function (e, b) { teamForm(teams.filter(function (t) { return t.id === +b.getAttribute('data-edit'); })[0], function () { V.teams(c); }); });
		});
	};
	function teamForm(t, done) {
		var members = (t ? t.members : []).slice();
		var form = el('<form><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
			'<label class="field"><span>Team name <span class="req">*</span></span><input type="text" name="name" required maxlength="120"></label>' +
			'<label class="field"><span>Colour</span><input type="color" name="color" style="height:38px;padding:4px"></label>' +
			'<label class="field span-2"><span>Description</span><textarea name="description" rows="2"></textarea></label>' +
			(t ? '<label class="check span-2"><input type="checkbox" name="is_active"> Team is active</label>' : '') +
			'<div class="field span-2"><span>Members <span class="hint">— tick “Team manager” for one or more people</span></span><div data-ms></div><div data-mlist style="margin-top:10px"></div></div>' +
			'<div class="field span-2"><span>Team permissions <span class="hint">— what managers and members can do in this team only</span></span><div class="table-wrap"><table class="tbl perm-tbl"><thead><tr><th>Permission</th><th class="num">Managers</th><th class="num">Members</th></tr></thead><tbody data-perms></tbody></table></div><div class="muted small" style="margin-top:6px">Admins always have every permission. Team managers do not get Admin access.</div></div></div></form>');
		form.name.value = t ? t.name : '';
		form.color.value = t ? t.color : '#2dd4a8';
		form.description.value = t ? t.description : '';
		if (t) form.is_active.checked = t.is_active;
		var ms = WD.multiSelect({ options: WD.memberOptions(false), value: members.map(function (m) { return m.user_id; }), placeholder: 'Add members…', onChange: function (v) {
			members = v.map(function (id) { var e = members.filter(function (m) { return m.user_id === id; })[0]; return e || { user_id: id, is_manager: false }; });
			paint();
		} });
		form.querySelector('[data-ms]').appendChild(ms);
		function paint() {
			form.querySelector('[data-mlist]').innerHTML = members.map(function (m) {
				return '<div style="display:flex;gap:10px;align-items:center;margin:4px 0">' + ui.avatar(m.user_id, 'sm') + '<span style="flex:1">' + esc(WD.u.memberName(m.user_id)) + '</span><label class="check small"><input type="checkbox" data-mgr="' + m.user_id + '"' + (m.is_manager ? ' checked' : '') + '> Team manager</label></div>';
			}).join('');
		}
		paint();
		on(form, 'change', '[data-mgr]', function (e, i) { members.forEach(function (m) { if (m.user_id === +i.getAttribute('data-mgr')) m.is_manager = i.checked; }); });
		var perms = JSON.parse(JSON.stringify(t ? t.permissions : { manager: { view_team_tasks: true, assign_tasks: true, edit_team_tasks: true, reassign_tasks: true, archive_tasks: false, view_reports: true, approve_leave: true, view_clients: true, view_client_contacts: true }, member: { view_team_tasks: true, assign_tasks: true, edit_team_tasks: false, reassign_tasks: false, archive_tasks: false, view_reports: false, approve_leave: false, view_clients: true, view_client_contacts: false } }));
		var labels = S.team_perm_labels || {};
		form.querySelector('[data-perms]').innerHTML = Object.keys(labels).map(function (k) {
			return '<tr><td data-th="Permission">' + esc(labels[k]) + '</td>' + ['manager', 'member'].map(function (lv) { return '<td class="num"><input type="checkbox" data-perm="' + lv + ':' + k + '"' + (perms[lv][k] ? ' checked' : '') + ' aria-label="' + esc(labels[k]) + ' — ' + (lv === 'manager' ? 'managers' : 'members') + '"></td>'; }).join('') + '</tr>';
		}).join('');
		on(form, 'change', '[data-perm]', function (e, i) { var p = i.getAttribute('data-perm').split(':'); perms[p[0]][p[1]] = i.checked; });
		var md = WD.modal({ title: t ? 'Manage ' + t.name : 'New team', body: form, foot: (t && can('ndwd_delete_records') ? '<button class="btn btn-danger left" data-del>Delete team</button>' : '') + '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-save>Save team</button>' });
		var save = md.el.querySelector('[data-save]');
		var delT = md.el.querySelector('[data-del]');
		if (delT) delT.onclick = function () { WD.safeDelete('teams/' + t.id, 'team').then(function (ok) { if (ok) { md.close(); WD.reloadBoot().then(done); } }); };
		save.onclick = function () {
			var body = { name: form.name.value.trim(), color: form.color.value, description: form.description.value, members: members, permissions: perms };
			if (t) body.is_active = form.is_active.checked;
			if (!body.name) { form.name.focus(); return; }
			WD.busy(save, true);
			api(t ? 'teams/' + t.id : 'teams', { method: t ? 'PUT' : 'POST', body: body }).then(function () {
				md.close(); WD.toast('Team saved'); WD.reloadBoot().then(done);
			}, function (e) { WD.busy(save, false); var x = form.querySelector('[data-err]'); x.textContent = e.message; x.classList.remove('hidden'); });
		};
	}

	/* ------------------------------------------------------------------ *
	 * Admin: projects & tags
	 * ------------------------------------------------------------------ */
	/* Tags. Projects are managed on the Projects page (1.18). */
	V.projects = function (c) {
		WD.setTitle('Tags', 'Labels used when creating tasks. Projects are managed under Projects.');
		var pf = S.pjf = S.pjf || { tq: '' };
		c.innerHTML = '<section class="card"><div class="card-h"><h2>Tags</h2><span class="spacer"></span><a class="btn btn-sm" href="#/projects">' + I.folder + ' Projects</a><button class="btn btn-sm btn-primary" data-addt>' + I.plus + ' Add tag</button></div><div class="toolbar pj-bar"><input type="search" data-tgq placeholder="Search tags" aria-label="Search tags" value="' + esc(pf.tq) + '"></div><div class="card-b" data-t></div></section>';
		function paint() {
			var tq = pf.tq.trim().toLowerCase(), tags = S.tags.filter(function (g) { return !tq || g.name.toLowerCase().indexOf(tq) >= 0; });
			c.querySelector('[data-t]').innerHTML = tags.length ? tags.map(function (g) {
				return '<span class="tag" style="margin:0 6px 8px 0;padding:4px 10px"><i style="background:' + esc(g.color) + '"></i>' + esc(g.name) + ' <button class="btn btn-ghost btn-sm" style="min-height:20px;padding:0 4px" data-et="' + g.id + '" aria-label="Edit tag">' + I.edit.replace('<svg', '<svg width="12" height="12"') + '</button></span>';
			}).join('') : ui.emptyState(S.tags.length ? 'No tags match' : 'No tags yet', '', I.tag);
		}
		paint();
		c.querySelector('[data-tgq]').oninput = WD.u.debounce(function (e) { pf.tq = c.querySelector('[data-tgq]').value; paint(); }, 200);
		function tagForm(g) {
			var f = el('<form><div class="form-grid"><label class="field"><span>Tag name <span class="req">*</span></span><input name="name" required maxlength="80"></label><label class="field"><span>Colour</span><input type="color" name="color" style="height:38px;padding:4px"></label></div></form>');
			f.name.value = g ? g.name : ''; f.color.value = g ? g.color : '#60a5fa';
			var md = WD.modal({ title: g ? 'Edit tag' : 'Add tag', size: 'sm', body: f, foot: (g ? '<button class="btn btn-danger left" data-del>Delete</button>' : '') + '<button class="btn" data-close>Cancel</button><button class="btn btn-primary" data-save>Save</button>' });
			md.el.querySelector('[data-save]').onclick = function () {
				if (!f.name.value.trim()) { f.name.focus(); return; }
				api(g ? 'tags/' + g.id : 'tags', { method: g ? 'PUT' : 'POST', body: { name: f.name.value, color: f.color.value } }).then(function () { md.close(); WD.toast('Tag saved'); WD.reloadBoot().then(paint); }, WD.toastError);
			};
			var del = md.el.querySelector('[data-del]');
			if (del) del.onclick = function () {
				WD.confirm('Delete tag?', 'The tag will be removed from all tasks.', 'Delete', true).then(function (ok) {
					if (ok) api('tags/' + g.id, { method: 'DELETE' }).then(function () { md.close(); WD.reloadBoot().then(paint); }, WD.toastError);
				});
			};
		}
		c.querySelector('[data-addt]').onclick = function () { tagForm(null); };
		on(c, 'click', '[data-et]', function (e, b) { tagForm(WD.u.tag(+b.getAttribute('data-et'))); });
		return Promise.resolve();
	};

	/* ------------------------------------------------------------------ *
	 * Admin: settings
	 * ------------------------------------------------------------------ */
	V.settings = function (c) {
		WD.setTitle('Settings', 'Email, option lists, sessions and scheduler');
		c.innerHTML = ui.loading(8);
		return api('settings').then(function (d) {
			paintSettings(c, d);
			if (WD.u.can('ndwd_manage_workspace')) {
				var i = document.createElement('section');
				i.className = 'card section-gap';
				i.innerHTML = '<div class="card-h"><h2>Integrations</h2></div><div class="card-b"><a class="btn btn-sm" href="#/admin/integrations">Neon Workspace</a></div>';
				c.insertBefore(i, c.firstChild);
			}
		});
	};
	V.settings.refresh = function (c) {
		return api('settings').then(function (d) { paintSettings(c, d); });
	};
	/** Server side of push: no key is ever sent here, only whether one is in place. */
	function pushCard(p) {
		if (!p) { return ''; }
		var warn = !p.configured ? 'VAPID keys are missing or invalid, or PHP OpenSSL is unavailable. Push cannot be sent.' : !p.https ? 'This site is not on HTTPS, so browsers will refuse push outside localhost.' : '';
		return '<section class="card"><div class="card-h"><h2>Push notifications</h2><span class="spacer"></span><span class="badge ' + (p.configured ? 's-completed' : 's-blocked') + '">' + (p.configured ? 'Ready' : 'Not ready') + '</span></div><div class="card-b">' + (warn ? '<div class="alert alert-info small">' + esc(warn) + '</div>' : '') + '<dl class="kv">' +
			'<dt>VAPID keys</dt><dd>' + (p.source === 'wp-config' ? 'From wp-config.php' : p.source === 'database' ? 'Generated and stored on the server' : '—') + '</dd>' +
			'<dt>Contact sent to push services</dt><dd>' + esc(p.subject) + '</dd>' +
			'<dt>Devices</dt><dd>' + esc(p.devices) + ' from ' + esc(p.members) + ' member(s)</dd>' +
			'<dt>Waiting to send</dt><dd>' + esc(p.queued) + '</dd>' +
			'<dt>Last 24 hours</dt><dd>' + esc(p.sent_24h) + ' sent, ' + esc(p.failed_24h) + ' failed</dd>' +
			(p.last_error ? '<dt>Latest problem</dt><dd class="small">' + esc(p.last_error) + '</dd>' : '') +
			'</dl><p class="small muted" style="margin-bottom:0">The scheduler sends push on each run. Members turn it on in My Profile.</p></div></section>';
	}

	function paintSettings(c, d) {
		var s = d.settings, et = s.email_types || {};
		var types = [['assigned', 'Task assigned'], ['reassigned', 'Task reassigned'], ['reminder', 'Reminders, due today & overdue'], ['updated', 'Important updates (status, priority, dates, remarks)'], ['leave', 'Leave requests and decisions'], ['daily_summary', 'Daily worksheet summary']];
		// The key is never printed on screen: only the copy button uses it.
		var cronCmd = 'curl -fsS --max-time 280 "' + d.cron.endpoint + '" >/dev/null 2>&1';
		var cronShown = 'curl -fsS --max-time 280 "' + d.cron.endpoint.replace(/key=.*$/, 'key=••••••••') + '" >/dev/null 2>&1';
		var ch = d.cron.health || {}, hc = ch.counts || {};
		var hTone = { server_cron: 's-completed', http_cron: 's-completed', wp_cron_only: 's-pending', not_detected: 's-rejected', error: 's-rejected' }[ch.status] || '';
		var cli = d.cron.php_cli || {}, srcs = ch.sources || {};
		var srcRow = function (k) {
			var r = srcs[k] || {};
			return '<dt>' + esc(r.label || k) + '</dt><dd>' + (r.last_run ? esc(fmt.dt(r.last_run)) + (r.failed ? ' <span style="color:var(--danger)">(failed)</span>' : '') : '<span class="muted">Never</span>') + '</dd>';
		};
		var cliBinaryNote = cli.binary_source === 'confirmed'
			? 'PHP path confirmed by this site’s own PHP CLI run' + (cli.confirmed_at ? ' (' + esc(fmt.dt(cli.confirmed_at)) + ')' : '') + '.'
			: 'PHP path found on this server but not yet proven by a run. Check it against what your hosting panel shows.';
		var cronRule = '<hr style="border:0;border-top:1px solid var(--border);margin:16px 0">';
		var mailFail = WD.unseenMailFailure(d.email_status);
		c.innerHTML =
			(d.email_status.warnings.length ? '<div class="alert alert-warn"><b>Email warnings</b><ul>' + d.email_status.warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') + '</ul></div>' : (mailFail ? '' : '<div class="alert alert-info">Email is configured. Use “Send test email” to confirm delivery.</div>')) +
			(mailFail ? '<div class="alert alert-warn" data-mail-fail style="display:flex;align-items:center;gap:8px"><div style="flex:1">' + esc(mailFail.message) + '</div><button type="button" class="btn btn-ghost btn-sm btn-icon" aria-label="Dismiss" title="Dismiss">' + I.x + '</button></div>' : '') +
			'<div class="grid grid-2">' +
			'<section class="card"><div class="card-h"><h2>Email notifications</h2></div><div class="card-b"><form data-mail class="form-grid">' +
			'<label class="check span-2"><input type="checkbox" name="email_enabled"' + (s.email_enabled ? ' checked' : '') + '> Send email notifications</label>' +
			'<label class="field"><span>From name</span><input name="email_from_name" value="' + esc(s.email_from_name || '') + '"></label>' +
			'<label class="field"><span>From email</span><input type="email" name="email_from" value="' + esc(s.email_from || '') + '" placeholder="workdesk@yourdomain.com"></label>' +
			'<label class="field"><span>SMTP host</span><input name="smtp_host" value="' + esc(s.smtp_host || '') + '" placeholder="smtp.yourhost.com"></label>' +
			'<label class="field"><span>Port</span><input type="number" name="smtp_port" value="' + esc(s.smtp_port || 587) + '"></label>' +
			'<label class="field"><span>Encryption</span><select name="smtp_secure"><option value="">None</option><option value="tls"' + (s.smtp_secure === 'tls' ? ' selected' : '') + '>TLS (587)</option><option value="ssl"' + (s.smtp_secure === 'ssl' ? ' selected' : '') + '>SSL (465)</option></select></label>' +
			'<label class="field"><span>SMTP username</span><input name="smtp_user" value="' + esc(s.smtp_user || '') + '" autocomplete="off"></label>' +
			'<label class="field span-2"><span>SMTP password <span class="hint">' + (s.smtp_password_constant ? '(set in wp-config.php as NDWD_SMTP_PASSWORD)' : s.smtp_password_set ? '(saved — leave blank to keep)' : '(not set)') + '</span></span><input type="password" name="smtp_password" autocomplete="new-password"' + (s.smtp_password_constant ? ' disabled' : '') + ' placeholder="' + (s.smtp_password_set ? '••••••••' : '') + '"></label>' +
			'<div class="field span-2"><span>Send email for</span>' + types.map(function (t) { return '<label class="check" style="display:flex;margin:4px 0"><input type="checkbox" data-et="' + t[0] + '"' + (et[t[0]] ? ' checked' : '') + '> ' + esc(t[1]) + '</label>'; }).join('') + '</div>' +
			'<label class="field"><span>Daily summary hour (IST)</span><input type="number" min="0" max="23" name="daily_summary_hour" value="' + esc(s.daily_summary_hour) + '"></label>' +
			'<label class="field span-2"><span>Monthly report email <span class="hint">— the Admin address(es) that receive each month’s reports; separate several with commas</span></span><input name="report_email" value="' + esc(s.report_email || '') + '" placeholder="' + esc(d.admin_email || 'admin@yourdomain.com') + '"></label>' +
			'<div class="span-2" style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-primary" data-save-mail>Save email settings</button><button type="button" class="btn" data-test>Send test email to me</button></div>' +
			'</form><p class="muted small" style="margin-top:12px">Tip: for better security put the SMTP password in <code>wp-config.php</code>: <code>define( \'NDWD_SMTP_PASSWORD\', \'…\' );</code>. Passwords are never sent to the browser.</p></div></section>' +

			'<div class="grid" style="align-content:start">' +
			pushCard(d.push) +
			'<section class="card"><div class="card-h"><h2>Scheduler health</h2><span class="spacer"></span><span class="badge ' + hTone + '">' + esc(ch.status_label || '') + '</span></div><div class="card-b"><p class="small" style="margin:0 0 12px">' + esc(ch.status_detail || '') + '</p><dl class="kv">' +
			'<dt>Last run</dt><dd>' + (ch.last_run ? esc(fmt.dt(ch.last_run)) + (ch.last_source ? ' <span class="muted small">(' + esc(ch.last_source) + ')</span>' : '') : '<span style="color:var(--danger)">Never</span>') + '</dd>' +
			'<dt>Last successful run</dt><dd>' + (ch.last_success ? esc(fmt.dt(ch.last_success)) : '—') + '</dd>' +
			'<dt>Next expected run</dt><dd>' + esc(ch.next_run ? fmt.dt(ch.next_run) : '—') + (ch.next_run_note ? ' <span class="muted small">(' + esc(ch.next_run_note) + ')</span>' : '') + '</dd>' +
			srcRow('php_cli') + srcRow('http') + srcRow('wp_cron') + srcRow('manual') +
			'<dt>Next reminder</dt><dd>' + esc(ch.next_reminder ? fmt.dt(ch.next_reminder) : 'None set') + (ch.upcoming ? ' <span class="muted small">(' + ch.upcoming + ' scheduled)</span>' : '') + '</dd>' +
			'<dt>Pending jobs</dt><dd>' + (ch.pending || 0) + ((hc.retry || 0) ? ' <span class="muted small">(' + hc.retry + ' waiting to retry)</span>' : '') + '</dd>' +
			'<dt>Failed jobs</dt><dd>' + (ch.failed ? '<span style="color:var(--danger);font-weight:600">' + ch.failed + '</span>' : '0') + '</dd>' +
			'<dt>Delivered</dt><dd>' + (hc.delivered || 0) + ' <span class="muted small">· ' + (ch.inapp_created || 0) + ' in-app created · ' + (ch.email_sent || 0) + ' emails sent · ' + (ch.retried || 0) + ' retried · ' + (ch.delayed || 0) + ' delayed</span></dd>' +
			'<dt>WP-Cron setting</dt><dd style="word-break:normal">' + (d.cron.wp_cron_disabled ? 'Disabled (server cron expected)' : 'Enabled as a fallback (needs site visits; stands down while a server cron runs)') + '</dd></dl>' +
			((ch.recent_failures || []).length ? '<details class="small" style="margin-top:8px"><summary>Recent failures</summary><ul style="padding-left:18px;margin:6px 0">' + ch.recent_failures.map(function (x) { return '<li>' + esc(x.kind) + ' · task #' + x.task_id + ' · ' + x.attempts + ' attempts · ' + esc(x.error) + '</li>'; }).join('') + '</ul></details>' : '') +
			'<form data-cron-window style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:12px"><label class="field" style="margin:0"><span>Health window (minutes)</span><input type="number" min="2" max="180" name="scheduler_health_minutes" value="' + esc(ch.health_minutes || 5) + '" style="max-width:120px"></label><button class="btn btn-sm">Save</button><button type="button" class="btn btn-sm" data-run>Run jobs now</button></form>' +
			'<p class="muted small" style="margin:8px 0 0">A cron job silent for longer than the health window stops counting as running. Run jobs now is recorded separately and never counts as a cron job.</p>' +
			cronRule +
			'<div class="label">Recommended: PHP CLI Cron (Hostinger)</div>' +
			'<p class="small" style="margin:0 0 8px">Your host runs WorkDesk directly every minute, even with everyone signed out and no one on the site. This method does not need the URL secret key.</p>' +
			(cli.command ?
				'<pre class="code" data-cli>' + esc(cli.command) + '</pre><p class="muted small" style="margin:6px 0 8px">' + cliBinaryNote + '</p>' +
				'<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-sm btn-primary" data-copy-cli>Copy PHP Command</button><button class="btn btn-sm" data-copy-path>Copy script path</button></div>'
				:
				'<div class="alert alert-warn small" style="margin:0 0 8px">' + (cli.script_ok ? 'WorkDesk could not confirm the PHP program’s path on this server, so it does not show a full command.' : 'WorkDesk could not read its script at this path, so it does not show a command. Check the plugin is installed in this folder.') + '</div>' +
				'<div class="small" style="margin-bottom:4px">PHP script path:</div><pre class="code">' + esc(cli.script || '') + '</pre>' +
				'<p class="small" style="margin:6px 0 8px">To confirm the PHP path: if your hosting panel has a PHP option for cron jobs, it supplies the path. Otherwise run <code>which php</code> over SSH or ask your host, then use that path, a space, and the script path above.</p>' +
				'<div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-sm" data-copy-path>Copy script path</button></div>') +
			(cli.script_from_home ? '<p class="muted small" style="margin:8px 0 0">If the panel already shows your home folder, the script path from there is <code>' + esc(cli.script_from_home) + '</code></p>' : '') +
			'<ol class="small" style="padding-left:18px;margin:10px 0 0"><li>In Hostinger, open Advanced → Cron Jobs.</li><li>Select PHP.</li><li>Paste or select the PHP script path.</li><li>Set it to run every minute.</li><li>Save. This page shows Server cron running after the first run.</li></ol>' +
			cronRule +
			'<div class="label">Alternative: HTTP / URL Cron</div>' +
			'<p class="small" style="margin:0 0 8px">For hosts that can only call a web address. The secret key is used only by this method and is hidden here; Copy URL Command includes it.</p><pre class="code" data-cron>' + esc(cronShown) + '</pre>' +
			'<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px"><button class="btn btn-sm" data-copy>Copy URL Command</button><button class="btn btn-sm btn-ghost" data-rekey>Regenerate Secret Key</button></div>' +
			'<p class="muted small" style="margin:8px 0 0">Anyone with this address can start the scheduled jobs, and web addresses can appear in hosting access logs. Keep it private and regenerate the key if it leaks. PHP CLI cron is not affected by the key.</p>' +
			(d.cron.last_run ? '<details style="margin-top:10px" class="small"><summary>Last run log</summary><pre class="code">' + esc(JSON.stringify(d.cron.last_run.log, null, 2)) + '</pre></details>' : '') + '</div></section>' +
			'<section class="card"><div class="card-h"><h2>Data retention</h2></div><div class="card-b"><form data-ret>' +
			'<p class="small">Operational history (attendance, activity log, completed and archived tasks, notifications, email log and stored monthly packs) is kept for the number of calendar months below, judged by each record’s own date. A month is removed only after its reports were emailed to the Admin or marked as saved. Invoices, payments, expenses, clients, teams, members and leave are never removed.</p>' +
			'<label class="field"><span>Keep operational history for (months, minimum 6)</span><input type="number" min="6" max="36" name="retention_months" value="' + esc(s.retention_months || 6) + '" style="max-width:120px"></label>' +
			'<dl class="kv" style="margin-top:10px"><dt>Kept from</dt><dd>' + esc(fmt.date(d.retention.cutoff, true)) + ' onwards</dd><dt>Next cleanup would remove</dt><dd>' + d.retention.total + ' record(s) from ' + Object.keys(d.retention.cleaned).length + ' month(s)</dd>' +
			(Object.keys(d.retention.blocked).length ? '<dt>Kept until delivered</dt><dd>' + esc(Object.keys(d.retention.blocked).join(', ')) + '</dd>' : '') + '</dl>' +
			'<button class="btn btn-primary btn-sm" style="margin-top:10px">Save</button></form></div></section>' +

			'<section class="card"><div class="card-h"><h2>Option lists</h2></div><div class="card-b"><form data-opts>' +
			'<div class="label">Priority labels</div><div class="form-grid" style="margin-bottom:14px">' + Object.keys(d.priorities).map(function (k) { return '<label class="field"><span class="hint">' + esc(k) + '</span><input data-pr="' + k + '" value="' + esc(d.priorities[k]) + '"></label>'; }).join('') + '</div>' +
			'<div class="label">Work types</div><div data-wt></div><button type="button" class="btn btn-sm" data-addwt>' + I.plus + ' Add work type</button>' +
			'<div style="margin-top:14px"><button class="btn btn-primary btn-sm">Save option lists</button></div></form></div></section>' +

			'<section class="card"><div class="card-h"><h2>Sessions & data</h2></div><div class="card-b"><form data-misc>' +
			'<p class="small muted" style="margin:0 0 10px">Sign-in sessions carry on across midnight IST. Attendance for each IST date is recorded when a member signs in, or opens and uses WorkDesk while still signed in.</p>' +
			'<label class="check" style="display:flex;margin-bottom:10px"><input type="checkbox" name="delete_on_uninstall"' + (s.delete_on_uninstall ? ' checked' : '') + '> <span>Delete all WorkDesk data when the plugin is <b>deleted</b> (never on deactivation)</span></label>' +
			'<button class="btn btn-primary btn-sm">Save</button></form>' +
			'<dl class="kv" style="margin-top:14px"><dt>PHP</dt><dd>' + esc(d.environment.php) + '</dd><dt>ZipArchive (.xlsx)</dt><dd>' + (d.environment.zip ? 'Available' : '<span style="color:var(--danger)">Missing — Excel export will fail</span>') + '</dd><dt>iconv (PDF text)</dt><dd>' + (d.environment.iconv ? 'Available' : 'Missing — non-ASCII text shown as ?') + '</dd><dt>Report storage</dt><dd>' + (d.environment.writable ? 'Writable' : '<span style="color:var(--danger)">Not writable</span>') + '</dd></dl></div></section>' +
			'</div></div>' +
			'<section class="card section-gap"><div class="card-h"><h2>Scheduled jobs</h2><span class="spacer"></span><span class="muted small">Monthly reports, retention cleanup, invoice archive reminders, temp files</span></div><div class="table-wrap"><table class="tbl stack"><thead><tr><th>Job</th><th>Period</th><th>Status</th><th>Attempts</th><th>Last run</th><th>Message</th></tr></thead><tbody>' +
			(d.jobs.length ? d.jobs.map(function (j) {
				var cls = { done: 's-completed', running: 's-in_process', waiting: 's-pending', failed: 's-blocked', gave_up: 's-blocked' }[j.status] || '';
				return '<tr><td data-th="Job">' + esc(j.label) + '</td><td data-th="Period">' + esc(j.period) + '</td><td data-th="Status"><span class="badge ' + cls + '">' + esc(j.status.replace('_', ' ')) + '</span></td><td data-th="Attempts" class="num">' + j.attempts + '</td><td data-th="Last run" class="small">' + esc(fmt.dt(j.finished_at || j.started_at)) + (j.next_attempt_at ? '<div class="muted">Next try ' + esc(fmt.dt(j.next_attempt_at)) + '</div>' : '') + '</td><td data-th="Message" class="small wrap">' + esc(j.message) + '</td></tr>';
			}).join('') : '<tr><td colspan="6">' + ui.emptyState('No scheduled jobs have run yet') + '</td></tr>') + '</tbody></table></div></section>' +
			'<section class="card section-gap"><div class="card-h"><h2>Email log</h2><span class="spacer"></span><span class="muted small">Last 50 attempts</span></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Time</th><th>To</th><th>Subject</th><th>Type</th><th>Status</th></tr></thead><tbody>' +
			(d.email_log.length ? d.email_log.map(function (l) { return '<tr><td class="small">' + esc(fmt.dt(l.created_at)) + '</td><td>' + esc(l.recipient) + '</td><td>' + esc(l.subject) + '</td><td>' + esc(l.type) + '</td><td>' + (l.status === 'sent' ? '<span class="badge s-completed">sent</span>' : '<span class="badge s-blocked" title="' + esc(l.error || '') + '">' + esc(l.status) + '</span>' + (l.error ? '<div class="small" style="color:var(--danger)">' + esc(l.error) + '</div>' : '')) + '</td></tr>'; }).join('') : '<tr><td colspan="5">' + ui.emptyState('No emails sent yet') + '</td></tr>') +
			'</tbody></table></div></section>';

		function save(body, btn) {
			WD.busy(btn, true);
			return api('settings', { method: 'PUT', body: body }).then(function (nd) { WD.toast('Settings saved'); WD.reloadBoot(); paintSettings(c, nd); }, function (e) { WD.busy(btn, false); WD.toastError(e); });
		}
		var failBox = c.querySelector('[data-mail-fail]');
		if (failBox) failBox.querySelector('button').onclick = function () { WD.dismissMailFailure(d.email_status); failBox.remove(); };
		var mail = c.querySelector('[data-mail]');
		mail.onsubmit = function (e) {
			e.preventDefault();
			var b = { email_enabled: mail.email_enabled.checked, email_from_name: mail.email_from_name.value, email_from: mail.email_from.value, smtp_host: mail.smtp_host.value, smtp_port: mail.smtp_port.value, smtp_secure: mail.smtp_secure.value, smtp_user: mail.smtp_user.value, daily_summary_hour: mail.daily_summary_hour.value, report_email: mail.report_email.value, email_types: {} };
			if (mail.smtp_password.value) b.smtp_password = mail.smtp_password.value;
			$$('[data-et]', mail).forEach(function (i) { b.email_types[i.getAttribute('data-et')] = i.checked; });
			save(b, mail.querySelector('[data-save-mail]'));
		};
		c.querySelector('[data-test]').onclick = function (e) {
			var btn = e.target; WD.busy(btn, true, 'Sending…');
			api('settings/test-email', { method: 'POST' }).then(function (r) { WD.busy(btn, false); WD.toast(r.message, r.ok ? 'success' : 'error'); V.settings(c); }, function (er) { WD.busy(btn, false); WD.toastError(er); });
		};
		function copyText(txt, okMsg) {
			(navigator.clipboard ? navigator.clipboard.writeText(txt) : Promise.reject()).catch(function () {
				var ta = document.createElement('textarea');
				ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
				document.body.appendChild(ta); ta.select();
				var done = false;
				try { done = document.execCommand('copy'); } catch (er) { done = false; }
				ta.remove();
				if (!done) throw new Error('copy');
			}).then(function () { WD.toast(okMsg); }, function () { WD.toast('Your browser blocked copying. Allow clipboard access and try again.', 'error'); });
		}
		// The on-screen URL hides the key, so this copies the real command, not the text shown.
		c.querySelector('[data-copy]').onclick = function () { copyText(cronCmd, 'URL command copied'); };
		var copyCli = c.querySelector('[data-copy-cli]');
		if (copyCli) copyCli.onclick = function () { copyText(cli.command, 'PHP command copied'); };
		c.querySelector('[data-copy-path]').onclick = function () { copyText(cli.script, 'Script path copied'); };
		var cronWin = c.querySelector('[data-cron-window]');
		cronWin.onsubmit = function (e) {
			e.preventDefault();
			save({ scheduler_health_minutes: cronWin.scheduler_health_minutes.value }, cronWin.querySelector('button'));
		};
		c.querySelector('[data-run]').onclick = function (e) {
			var btn = e.target; WD.busy(btn, true, 'Running…');
			api('settings/run-cron', { method: 'POST' }).then(function (r) { WD.toast(r.log && r.log.skipped ? 'Another run is in progress. Nothing was started.' : 'Scheduled jobs ran (recorded as a manual run)'); V.settings(c); }, function (er) { WD.busy(btn, false); WD.toastError(er); });
		};
		c.querySelector('[data-rekey]').onclick = function (e) {
			WD.confirm('Regenerate key?', 'The old cron URL will stop working, so update any URL cron job afterwards. PHP CLI cron is not affected.', 'Regenerate').then(function (ok) { if (ok) save({ regenerate_cron_key: true }, e.target); });
		};
		var wt = c.querySelector('[data-wt]');
		function wtRow(k, v) {
			var r = el('<div class="link-row"><input placeholder="key (e.g. field)" data-k><input placeholder="Label" data-l><button type="button" class="btn btn-ghost btn-icon btn-sm" aria-label="Remove">' + I.trash + '</button></div>');
			r.querySelector('[data-k]').value = k || ''; r.querySelector('[data-l]').value = v || '';
			if (k) r.querySelector('[data-k]').readOnly = true;
			r.querySelector('button').onclick = function () { r.remove(); };
			wt.appendChild(r);
		}
		Object.keys(d.work_types).forEach(function (k) { wtRow(k, d.work_types[k]); });
		c.querySelector('[data-addwt]').onclick = function () { wtRow('', ''); };
		var opts = c.querySelector('[data-opts]');
		opts.onsubmit = function (e) {
			e.preventDefault();
			var b = { priorities: {}, work_types: [] };
			$$('[data-pr]', opts).forEach(function (i) { b.priorities[i.getAttribute('data-pr')] = i.value; });
			$$('.link-row', wt).forEach(function (r) {
				var k = r.querySelector('[data-k]').value.trim().toLowerCase().replace(/[^a-z0-9_-]/g, '_'), l = r.querySelector('[data-l]').value.trim();
				if (k && l) b.work_types.push({ key: k, label: l });
			});
			if (!b.work_types.length) { WD.toast('Keep at least one work type', 'error'); return; }
			save(b, opts.querySelector('button:last-child'));
		};
		var ret = c.querySelector('[data-ret]');
		ret.onsubmit = function (e) { e.preventDefault(); save({ retention_months: ret.retention_months.value }, ret.querySelector('button')); };
		var misc = c.querySelector('[data-misc]');
		misc.onsubmit = function (e) {
			e.preventDefault();
			save({ delete_on_uninstall: misc.delete_on_uninstall.checked }, misc.querySelector('button'));
		};
	}

	/* ------------------------------------------------------------------ *
	 * Admin: CSV import
	 * ------------------------------------------------------------------ */
	V.import = function (c) {
		WD.setTitle('Import worksheet CSV', 'Bring in your existing worksheet: preview, map columns and members, then confirm');
		var st = { csv: '', mapping: null, members: {}, options: { scheduled_date: S.today, preserve_status: true, notify: false, header_row: '' } };
		c.innerHTML = '<section class="card"><div class="card-h"><h2>1 · Choose file</h2></div><div class="card-b"><div class="form-grid cols-3">' +
			'<label class="field"><span>CSV file</span><input type="file" accept=".csv,text/csv" data-file></label>' +
			'<label class="field"><span>Default scheduled date <span class="hint">(rows without a date)</span></span><input type="date" data-o="scheduled_date" value="' + esc(S.today) + '"></label>' +
			'<label class="field"><span>Assign imported tasks to team</span><select data-o="team_id">' + WD.selectOptions(S.teams, '', 'No team') + '</select></label>' +
			'<label class="field"><span>Header row number <span class="hint">(auto-detected)</span></span><input type="number" min="1" data-o="header_row" placeholder="Auto"></label>' +
			'<label class="check" style="align-self:end"><input type="checkbox" data-o="preserve_status" checked> Keep status from the file</label>' +
			'<label class="check" style="align-self:end"><input type="checkbox" data-o="notify"> Notify assignees</label>' +
			'</div><p class="muted small" style="margin-top:10px">Expected columns include No., Task / Activity, Assigned To, Priority, Due Time, Status, Report, Remarks. Title rows and blank rows above the header are skipped automatically. The file is read in your browser and is not stored on the server.</p></div></section><div data-step2></div>';
		$$('[data-o]', c).forEach(function (i) {
			i.onchange = function () { st.options[i.getAttribute('data-o')] = i.type === 'checkbox' ? i.checked : i.value; if (st.csv) preview(); };
		});
		c.querySelector('[data-file]').onchange = function (e) {
			var f = e.target.files[0];
			if (!f) return;
			if (f.size > 2 * 1024 * 1024) { WD.toast('File is larger than 2 MB', 'error'); return; }
			var rd = new FileReader();
			rd.onload = function () { st.csv = rd.result; st.mapping = null; st.members = {}; preview(); };
			rd.onerror = function () { WD.toast('Could not read the file', 'error'); };
			rd.readAsText(f);
		};
		var s2 = c.querySelector('[data-step2]');
		function preview() {
			s2.innerHTML = '<section class="card section-gap">' + ui.loading(6) + '</section>';
			return api('import/preview', { method: 'POST', body: { csv: st.csv, mapping: st.mapping, members: st.members, options: st.options } }).then(function (p) {
				st.mapping = p.mapping;
				p.members.forEach(function (m) { st.members[m.key] = m.user_id; });
				var cols = p.header.map(function (h, i) { return { id: i, name: (h || 'Column ' + (i + 1)) }; });
				var stateBadge = { ok: 's-completed', duplicate: 's-pending', invalid: 's-blocked', blank: '' };
				s2.innerHTML = '<section class="card section-gap"><div class="card-h"><h2>2 · Map columns</h2><span class="spacer"></span><span class="muted small">Header found on row ' + p.header_row + (p.skipped_top_rows ? ' (' + p.skipped_top_rows + ' title/blank rows above it skipped)' : '') + '</span></div><div class="card-b"><div class="form-grid cols-3">' +
					Object.keys(p.fields).map(function (f) { return '<label class="field"><span>' + esc(p.fields[f]) + '</span><select data-map="' + f + '"><option value="">— not mapped —</option>' + cols.map(function (cl) { return '<option value="' + cl.id + '"' + (String(p.mapping[f]) === String(cl.id) ? ' selected' : '') + '>' + esc(cl.name) + '</option>'; }).join('') + '</select></label>'; }).join('') +
					'</div></div></section>' +
					'<section class="card section-gap"><div class="card-h"><h2>3 · Map people</h2><span class="spacer"></span><span class="muted small">' + p.members.length + ' names found in “Assigned To”</span></div><div class="card-b">' +
					(p.members.length ? '<div class="form-grid cols-3">' + p.members.map(function (m) { return '<label class="field"><span>' + esc(m.name) + '</span><select data-mm="' + esc(m.key) + '"><option value="0">— not mapped —</option>' + S.members.map(function (x) { return '<option value="' + x.id + '"' + (x.id === m.user_id ? ' selected' : '') + '>' + esc(x.name) + '</option>'; }).join('') + '</select></label>'; }).join('') + '</div>' : '<p class="muted">No assignee names detected. Unassigned rows will be assigned to you.</p>') +
					'</div></section>' +
					'<section class="card section-gap"><div class="card-h"><h2>4 · Review</h2><span class="spacer"></span><span class="badge s-completed">' + p.counts.ok + ' ready</span> <span class="badge s-pending">' + p.counts.duplicate + ' duplicates</span> <span class="badge s-blocked">' + p.counts.invalid + ' invalid</span> <span class="badge">' + p.counts.blank + ' blank</span></div>' +
					'<div class="table-wrap" style="max-height:420px;overflow:auto"><table class="tbl"><thead><tr><th>Row</th><th>Result</th><th>Title</th><th>Assignees</th><th>Priority</th><th>Date</th><th>Due</th><th>Status</th><th>Notes</th></tr></thead><tbody>' +
					p.items.map(function (it) {
						var d = it.data || {};
						return '<tr><td class="num">' + it.row + '</td><td><span class="badge ' + (stateBadge[it.state] || '') + '">' + esc(it.state) + '</span></td><td>' + esc(d.title || it.raw.join(' | ').slice(0, 80)) + '</td><td class="small">' + esc(WD.u.names(d.assignee_ids || [])) + '</td><td>' + (d.priority ? ui.prioBadge(d.priority) : '') + '</td><td class="small">' + esc(d.scheduled_date || '') + '</td><td class="small">' + esc((d.due_date || '') + ' ' + (d.due_time || '')) + '</td><td>' + (it.status ? ui.statusBadge(it.status) : '') + '</td><td class="small muted">' + esc(it.messages.join('; ')) + '</td></tr>';
					}).join('') + '</tbody></table></div>' +
					(p.total > p.items.length ? '<div class="card-b small muted">Showing first ' + p.items.length + ' of ' + p.total + ' rows. All rows are processed on import.</div>' : '') +
					'<div class="modal-f" style="border-radius:0 0 12px 12px"><label class="check left"><input type="checkbox" data-dups> Import duplicates anyway</label><button class="btn btn-primary" data-commit' + (p.counts.ok + p.counts.duplicate ? '' : ' disabled') + '>Import ' + p.counts.ok + ' task' + (p.counts.ok === 1 ? '' : 's') + '</button></div></section>';
				$$('[data-map]', s2).forEach(function (sel) { sel.onchange = function () { st.mapping[sel.getAttribute('data-map')] = sel.value; preview(); }; });
				$$('[data-mm]', s2).forEach(function (sel) { sel.onchange = function () { st.members[sel.getAttribute('data-mm')] = +sel.value; preview(); }; });
				var dups = s2.querySelector('[data-dups]'), commit = s2.querySelector('[data-commit]');
				dups.onchange = function () { commit.textContent = 'Import ' + (p.counts.ok + (dups.checked ? p.counts.duplicate : 0)) + ' tasks'; };
				commit.onclick = function () {
					WD.confirm('Import tasks?', 'Tasks will be created from the reviewed rows. Invalid and blank rows are reported, never silently dropped.', 'Import').then(function (ok) {
						if (!ok) return;
						WD.busy(commit, true, 'Importing…');
						var opts = Object.assign({}, st.options, { import_duplicates: dups.checked });
						api('import/commit', { method: 'POST', body: { csv: st.csv, mapping: st.mapping, members: st.members, options: opts } }).then(function (r) {
							s2.innerHTML = '<section class="card section-gap"><div class="card-h"><h2>Import summary</h2></div><div class="card-b"><div class="stats">' +
								stat('', 'Imported', r.imported, 'var(--st-done)') + stat('', 'Duplicates skipped', r.skipped_duplicates, 'var(--st-pending)') + stat('', 'Invalid', r.invalid, 'var(--danger)') + stat('', 'Blank rows', r.blank) + stat('', 'Failed', r.failed, 'var(--danger)') +
								'</div>' + (r.details.length ? '<div class="table-wrap"><table class="tbl"><thead><tr><th>Row</th><th>Result</th><th>Details</th></tr></thead><tbody>' + r.details.map(function (x) { return '<tr><td class="num">' + x.row + '</td><td>' + esc(x.result) + '</td><td class="small">' + esc(x.message) + '</td></tr>'; }).join('') + '</tbody></table></div>' : '') +
								'<div style="margin-top:12px;display:flex;gap:8px"><a class="btn btn-primary" href="#/worksheet">Open worksheet</a><button class="btn" data-again>Import another file</button></div></div></section>';
							s2.querySelector('[data-again]').onclick = function () { V.import(c); };
							WD.toast(r.imported + ' tasks imported');
						}, function (e) { WD.busy(commit, false); WD.toastError(e); });
					});
				};
			}, function (e) { s2.innerHTML = ''; s2.appendChild(ui.errorBox(e, preview)); });
		}
		return Promise.resolve();
	};
	/* ------------------------------------------------------------------ *
	 * Clients
	 * ------------------------------------------------------------------ */
	S.cl = S.cl || { q: '', inactive: false };
	V.clients = function (c, o) {
		var st = S.cl, manage = can('ndwd_manage_clients');
		WD.setTitle('Clients', manage ? 'Who the work is for — contacts, billing, projects and open tasks' : 'Clients assigned to you and your teams');
		if (!o || !o.silent || !c.querySelector('[data-cards]')) {
			c.innerHTML = '<div class="toolbar"><input type="search" data-q placeholder="Search name, company, contact, phone or email" aria-label="Search clients" style="max-width:340px">' +
				'<label class="check"><input type="checkbox" data-inactive' + (st.inactive ? ' checked' : '') + '> Show inactive</label><span class="spacer"></span>' +
				(manage ? '<button class="btn btn-primary" data-add>' + I.plus + ' Add client</button>' : '') + '</div>' +
				'<div class="grid grid-3 client-grid" data-cards>' + '<div class="card">' + ui.loading(4) + '</div>' + '</div>';
			var q = c.querySelector('[data-q]');
			q.value = st.q;
			q.oninput = WD.u.debounce(function () { st.q = q.value; paint(); }, 150);
			c.querySelector('[data-inactive]').onchange = function (e) { st.inactive = e.target.checked; paint(); };
			if (manage) c.querySelector('[data-add]').onclick = function () { clientForm(null, function () { V.clients(c, { silent: true }); }); };
		}
		var list = [];
		function paint() {
			var needle = st.q.trim().toLowerCase();
			var rows = list.filter(function (x) {
				if (!st.inactive && !x.is_active) return false;
				return !needle || [x.name, x.company, x.contact_person || '', x.phone || '', x.email || ''].join(' ').toLowerCase().indexOf(needle) >= 0;
			});
			var box = c.querySelector('[data-cards]');
			if (!rows.length) {
				box.innerHTML = '<div class="card" style="grid-column:1/-1">' + ui.emptyState(list.length ? 'No clients match your search' : 'No clients yet', list.length ? 'Try another name, or tick “Show inactive”.' : (manage ? 'Add your first client to link projects and tasks to them.' : 'Clients appear here when they are assigned to you or your team.'), I.briefcase) + '</div>';
				return;
			}
			box.innerHTML = rows.map(function (x) {
				var tel = x.phone ? x.phone.replace(/[^\d+]/g, '') : '';
				return '<section class="card client-card' + (x.is_active ? '' : ' inactive') + '"><div class="card-h"><span class="avatar" style="background:hsl(' + (x.id * 67 % 360) + ' 50% 42%)">' + esc(x.name.slice(0, 2).toUpperCase()) + '</span><div style="min-width:0;flex:1"><h2 class="ellipsis"><a href="#/clients/' + x.id + '">' + esc(x.name) + '</a></h2>' + (x.company ? '<div class="muted small ellipsis">' + esc(x.company) + '</div>' : '') + '</div>' + (x.is_active ? '' : '<span class="badge">Inactive</span>') + '</div>' +
					'<div class="card-b"><dl class="kv">' +
					(x.contact_person ? '<dt>Contact</dt><dd>' + esc(x.contact_person) + '</dd>' : '') +
					(x.phone ? '<dt>Phone</dt><dd><a href="tel:' + esc(tel) + '">' + esc(x.phone) + '</a></dd>' : '') +
					(x.email ? '<dt>Email</dt><dd><a href="mailto:' + esc(x.email) + '">' + esc(x.email) + '</a></dd>' : '') +
					'<dt>Work</dt><dd>' + x.projects + ' project' + (x.projects === 1 ? '' : 's') + ' · ' + x.open_tasks + ' open of ' + x.tasks + ' task' + (x.tasks === 1 ? '' : 's') + '</dd></dl>' +
					(x.notes ? '<p class="muted small client-notes">' + esc(x.notes) + '</p>' : '') +
					(x.level === 'basic' ? '<p class="muted small">Contact details are visible to the client’s team managers and Admins.</p>' : '') +
					'<div class="drawer-actions"><a class="btn btn-sm" href="#/clients/' + x.id + '">' + I.briefcase + ' Profile</a><button class="btn btn-sm" data-tasks="' + x.id + '">' + I.list + ' View tasks</button>' + (manage ? '<button class="btn btn-sm" data-edit-client="' + x.id + '">' + I.edit + ' Edit</button>' : '') + '</div></div></section>';
			}).join('');
		}
		on(c, 'click', '[data-tasks]', function (e, b) { openTasks({ client_id: b.getAttribute('data-tasks'), view: 'all', scope: '' }); });
		on(c, 'click', '[data-edit-client]', function (e, b) {
			var x = list.filter(function (y) { return y.id === +b.getAttribute('data-edit-client'); })[0];
			if (x) clientForm(x, function () { V.clients(c, { silent: true }); });
		});
		return api('clients').then(function (r) {
			list = r;
			S.clients = r;
			S.clientMap = {};
			r.forEach(function (x) { S.clientMap[x.id] = x; });
			paint();
		});
	};

	/** Client profile (#/clients/ID). The server returns 404 when the user may not see it. */
	V.client = function (c) {
		var id = +S.routeParams[1];
		WD.setTitle('Client', '');
		c.innerHTML = ui.loading(6);
		return api('clients/' + id).then(function (x) {
			WD.setTitle(x.name, x.company || 'Client profile');
			var tel = x.phone ? x.phone.replace(/[^\d+]/g, '') : '';
			var kv = function (k, v) { return v ? '<dt>' + esc(k) + '</dt><dd>' + v + '</dd>' : ''; };
			c.innerHTML = '<div class="toolbar"><a class="btn btn-sm btn-ghost" href="#/clients">' + I.left + ' All clients</a><span class="spacer"></span>' + (can('ndwd_manage_clients') ? '<button class="btn btn-sm" data-edit>' + I.edit + ' Edit</button>' : '') + (can('ndwd_manage_accounts') ? '<a class="btn btn-sm btn-primary" href="#/accounts/invoices/new?client=' + x.id + '">' + I.rupee + ' Create invoice</a>' : '') + '</div>' +
				'<div class="grid grid-2"><section class="card"><div class="card-h"><h2>Details</h2>' + (x.is_active ? '' : '<span class="badge">Inactive</span>') + '</div><div class="card-b"><dl class="kv">' +
				kv('Company', esc(x.company)) + kv('Contact', esc(x.contact_person || '')) + kv('Phone', x.phone ? '<a href="tel:' + esc(tel) + '">' + esc(x.phone) + '</a>' : '') + kv('Email', x.email ? '<a href="mailto:' + esc(x.email) + '">' + esc(x.email) + '</a>' : '') +
				kv('Work', x.projects + ' project(s) · ' + x.open_tasks + ' open of ' + x.tasks + ' task(s) you can see') +
				(x.team_ids ? kv('Teams', esc(x.team_ids.map(function (t) { return WD.u.team(t) ? WD.u.team(t).name : ''; }).filter(Boolean).join(', ') || '—')) + kv('Members', esc(WD.u.names(x.member_ids) || '—')) : '') +
				'</dl>' + (x.notes ? '<p class="muted small client-notes">' + esc(x.notes) + '</p>' : '') + (x.level === 'basic' ? '<p class="muted small">Contact details are shown only to people allowed to see them.</p>' : '') + '</div></section>' +
				(x.billing_name !== undefined ? '<section class="card"><div class="card-h"><h2>Billing</h2></div><div class="card-b"><dl class="kv">' + kv('Bill to', esc(x.billing_name || x.name)) + kv('Billing contact', esc(x.billing_contact)) + kv('WhatsApp', esc(x.billing_whatsapp)) +
					kv('WhatsApp group', x.billing_wa_group ? '<a href="' + esc(x.billing_wa_group) + '" target="_blank" rel="noopener noreferrer">' + I.wa + ' Open billing group</a>' : '') +
					kv('Email (To)', esc((x.billing_emails || []).filter(function (e) { return e.type === 'to'; }).map(function (e) { return e.email; }).join(', '))) +
					kv('Email (CC)', esc((x.billing_emails || []).filter(function (e) { return e.type === 'cc'; }).map(function (e) { return e.email; }).join(', '))) +
					kv('Address', esc(x.billing_address).replace(/\n/g, '<br>')) + kv('GSTIN', esc(x.gstin)) + '</dl>' + (!x.billing_whatsapp && !x.billing_email ? '<p class="muted small">No billing WhatsApp or email yet — add them to send invoices.</p>' : '') + '</div></section>' : '') +
				'</div><section class="card section-gap" data-cprojects></section>' + (can('ndwd_manage_workspace') ? '<section class="card section-gap" data-ws-card>' + ui.loading(3) + '</section>' : '') + '<section class="card section-gap"><div class="card-h"><h2>Packages &amp; service usage</h2><span class="spacer"></span><span data-pk-act></span></div><div class="card-b" data-pk>' + ui.loading(3) + '</div></section>' +
				'<section class="card section-gap"><div class="card-h"><h2>Tasks</h2><span class="spacer"></span><button class="btn btn-sm" data-all>' + I.list + ' Open in Tasks</button></div><div class="card-b flush" data-tl>' + ui.loading(4) + '</div></section>';
			c.querySelector('[data-all]').onclick = function () { openTasks({ client_id: x.id, view: 'all', scope: '' }); };
			var ed = c.querySelector('[data-edit]');
			if (ed) ed.onclick = function () { clientForm(x, function () { V.client(c); }); };
			clientPackages(c.querySelector('[data-pk]'), c.querySelector('[data-pk-act]'), x);
			if (WD.clientProjects) WD.clientProjects(c.querySelector('[data-cprojects]'), x);
			if (c.querySelector('[data-ws-card]')) WD.clientWorkspaceCard(c.querySelector('[data-ws-card]'), x.id);
			var tl = c.querySelector('[data-tl]');
			function loadTasks() {
				return api('tasks', { params: { client_id: x.id, per_page: 30 } }).then(function (r) {
					WD.taskList(tl, r.items, { emptyTitle: 'No tasks you can see for this client', showDate: true });
				}, function (e) { tl.innerHTML = ''; tl.appendChild(ui.errorBox(e, loadTasks)); });
			}
			loadTasks();
		});
	};

	function clientForm(x, done) {
		var billing = can('ndwd_manage_accounts');
		var form = el('<form novalidate><div class="alert alert-error hidden" data-err role="alert"></div><div class="form-grid">' +
			'<label class="field"><span>Client name <span class="req">*</span></span><input name="name" maxlength="160" required></label>' +
			'<label class="field"><span>Company / business</span><input name="company" maxlength="160"></label>' +
			'<label class="field"><span>Contact person</span><input name="contact_person" maxlength="160"></label>' +
			'<label class="field"><span>Phone</span><input type="tel" name="phone" maxlength="20" placeholder="+91 98765 43210"></label>' +
			'<label class="field span-2"><span>Email</span><input type="email" name="email" maxlength="190"></label>' +
			'<label class="field span-2"><span>Notes</span><textarea name="notes" rows="2"></textarea></label>' +
			'<div class="field span-2"><span>Assigned teams <span class="hint">— their managers / members see this client as each team allows</span></span><div data-cteams></div></div>' +
			'<div class="field span-2"><span>Assigned members <span class="hint">— see this client and its contact details</span></span><div data-cmembers></div></div>' +
			(billing ? '<div class="span-2 form-section"><b>Billing details</b> <span class="muted small">— used on invoices. Visible only to people with Accounts access.</span></div>' +
				'<label class="field"><span>Client / business name on invoice</span><input name="billing_name" maxlength="160" placeholder="Defaults to the client name"></label>' +
				'<label class="field"><span>Billing contact person</span><input name="billing_contact" maxlength="160"></label>' +
				'<label class="field"><span>Billing WhatsApp number</span><input type="tel" name="billing_whatsapp" maxlength="20" placeholder="+91 98765 43210"></label>' +
				'<label class="field"><span>Billing WhatsApp group <span class="hint">(invite link)</span></span><input type="url" name="billing_wa_group" maxlength="255" placeholder="https://chat.whatsapp.com/…"></label>' +
				'<div class="field span-2"><span>Billing emails <span class="hint">— mark each address To or CC; invoices go to every To address with the CC addresses copied</span></span><div data-bemails></div></div>' +
				'<label class="field span-2"><span>Billing address</span><textarea name="billing_address" rows="2"></textarea></label>' +
				'<label class="field"><span>GSTIN <span class="hint">(if applicable)</span></span><input name="gstin" maxlength="15" style="text-transform:uppercase" placeholder="15 characters"></label>' : '') +
			(x ? '<label class="check span-2"><input type="checkbox" name="is_active"> Active (offered when creating tasks and projects)</label>' : '') +
			'</div></form>');
		['name', 'company', 'contact_person', 'phone', 'email', 'notes'].concat(billing ? ['billing_name', 'billing_contact', 'billing_whatsapp', 'billing_wa_group', 'billing_address', 'gstin'] : []).forEach(function (k) { form[k].value = x ? x[k] || '' : ''; });
		var bemails = null;
		if (billing) {
			bemails = WD.emailListEditor(x ? x.billing_emails || [] : [], { allowEmpty: false });
			form.querySelector('[data-bemails]').appendChild(bemails);
		}
		if (x) form.is_active.checked = x.is_active;
		var cteams = WD.multiSelect({ options: S.teams.map(function (t) { return { id: t.id, label: t.name }; }), value: x ? x.team_ids || [] : [], placeholder: 'Add teams…', label: 'Assigned teams' });
		var cmembers = WD.multiSelect({ options: WD.memberOptions(false), value: x ? x.member_ids || [] : [], placeholder: 'Add members…', label: 'Assigned members' });
		form.querySelector('[data-cteams]').appendChild(cteams);
		form.querySelector('[data-cmembers]').appendChild(cmembers);
		var md = WD.modal({ title: x ? 'Edit ' + x.name : 'Add client', size: 'lg', body: form, sticky: true, foot: (x && can('ndwd_delete_records') ? '<button type="button" class="btn btn-danger left" data-del>Delete client</button>' : '') + '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-save>' + (x ? 'Save changes' : 'Add client') + '</button>' });
		var save = md.el.querySelector('[data-save]'), err = form.querySelector('[data-err]');
		var delC = md.el.querySelector('[data-del]');
		if (delC) delC.onclick = function () {
			WD.safeDelete('clients/' + x.id, 'client').then(function (ok) {
				if (!ok) return;
				md.close();
				WD.reloadBoot().then(function () { if (location.hash.indexOf('#/clients/') === 0) WD.go('/clients'); else done(); });
			});
		};
		function fail(m) { err.textContent = m; err.classList.remove('hidden'); md.body.scrollTop = 0; }
		form.onsubmit = function (e) { e.preventDefault(); save.click(); };
		save.onclick = function () {
			if (save.disabled) return;
			err.classList.add('hidden');
			var body = { name: form.name.value.trim(), company: form.company.value.trim(), contact_person: form.contact_person.value.trim(), phone: form.phone.value.trim(), email: form.email.value.trim(), notes: form.notes.value, team_ids: cteams.getValue(), member_ids: cmembers.getValue() };
			if (x) body.is_active = form.is_active.checked;
			if (billing) {
				['billing_name', 'billing_contact', 'billing_whatsapp', 'billing_wa_group', 'billing_address', 'gstin'].forEach(function (k) { body[k] = form[k].value.trim(); });
				if (body.billing_whatsapp && !/^\+?[0-9\s\-().]{6,20}$/.test(body.billing_whatsapp)) { form.billing_whatsapp.focus(); return fail('Billing WhatsApp number should contain only digits, spaces, +, - or brackets.'); }
				if (body.billing_wa_group && !/^https:\/\/([a-z0-9-]+\.)*whatsapp\.com\//i.test(body.billing_wa_group)) { form.billing_wa_group.focus(); return fail('Billing WhatsApp group: paste the group invite link, e.g. https://chat.whatsapp.com/AbC123…'); }
				try { body.billing_emails = bemails.getValue(); } catch (er) { return fail('Billing emails: ' + er.message); }
				if (body.gstin && !/^[0-9]{2}[A-Za-z0-9]{13}$/.test(body.gstin.replace(/\s+/g, ''))) { form.gstin.focus(); return fail('GSTIN should be 15 characters: 2-digit state code followed by 13 letters/digits.'); }
			}
			if (!body.name) { form.name.focus(); return fail('Client name is required.'); }
			if (body.phone && !/^\+?[0-9\s\-().]{6,20}$/.test(body.phone)) { form.phone.focus(); return fail('Phone number should contain only digits, spaces, +, - or brackets.'); }
			if (body.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(body.email)) { form.email.focus(); return fail('Please enter a valid email address.'); }
			WD.busy(save, true);
			api(x ? 'clients/' + x.id : 'clients', { method: x ? 'PUT' : 'POST', body: body }).then(function () {
				md.close();
				WD.toast(x ? 'Client updated' : 'Client added');
				WD.reloadBoot().then(done, done);
			}, function (e) { WD.busy(save, false); fail(e.message); });
		};
	}

	/* ------------------------------------------------------------------ *
	 * Client packages and service usage (client profile; forms shared with Accounts)
	 * ------------------------------------------------------------------ */
	var PKG_BADGE = { active: 's-completed', upcoming: 's-pending', paused: 's-in_process', expired: '', inactive: 's-pending', cancelled: 's-rejected', archived: '' };
	var nfp = (function () { try { return new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } catch (e) { return null; } })();
	function rs(n) { n = Number(n || 0); return '₹' + (nfp ? nfp.format(n) : n.toFixed(2)); }
	function meter(used, total) {
		var pct = total > 0 ? Math.min(100, Math.round(used / total * 100)) : (used > 0 ? 100 : 0);
		return '<div class="meter' + (used > total ? ' over' : pct >= 80 ? ' warn' : '') + '" role="img" aria-label="' + used + ' of ' + total + ' used"><i style="width:' + pct + '%"></i></div>';
	}
	function usageTable(items) {
		if (!items.length) return '<p class="muted small" style="margin:0">No counted services — retainer / scope-of-work package.</p>';
		return '<div class="table-wrap"><table class="tbl stack pkg-usage"><thead><tr><th>Service</th><th class="num">Included</th><th class="num">Reserved</th><th class="num">Used</th><th class="num">Remaining</th><th style="width:26%"></th></tr></thead><tbody>' + items.map(function (it) {
			var total = it.allocated + it.adjusted;
			return '<tr' + (it.is_active === false ? ' class="dim"' : '') + '><td data-th="Service"><b>' + esc(it.service_name) + '</b>' + (it.notes ? '<div class="muted small">' + esc(it.notes) + '</div>' : '') + (it.adjusted ? '<div class="muted small">' + (it.adjusted > 0 ? '+' : '') + it.adjusted + ' adjusted</div>' : '') + '<div class="pkg-mob small">' + it.consumed + ' used · ' + it.reserved + ' reserved · <b>' + it.remaining + '</b> of ' + total + ' ' + esc(it.unit_plural) + ' left</div></td>' +
				'<td data-th="Included" class="num">' + total + ' <span class="muted small">' + esc(it.unit_plural) + '</span></td><td data-th="Reserved" class="num">' + it.reserved + '</td><td data-th="Used" class="num">' + it.consumed + '</td>' +
				'<td data-th="Remaining" class="num"><b' + (it.remaining < 0 ? ' style="color:var(--danger)"' : '') + '>' + it.remaining + '</b></td><td>' + meter(it.consumed + it.reserved, total) + '</td></tr>';
		}).join('') + '</tbody></table></div>';
	}
	function allocationCard(a, manage) {
		var dates = fmt.date(a.start_date, true) + (a.end_date ? ' – ' + fmt.date(a.end_date, true) : ' onwards');
		var cnt = a.counts || {};
		var extra = [cnt.retainer ? cnt.retainer + ' retainer-scope task' + (cnt.retainer === 1 ? '' : 's') : '', cnt.additional ? cnt.additional + ' additional-scope task' + (cnt.additional === 1 ? '' : 's') + ' (may be charged)' : '', cnt.over_limit ? cnt.over_limit + ' beyond the limit' : ''].filter(Boolean);
		var hist = a.history || [];
		return '<div class="pkg-alloc" data-alloc="' + a.id + '"><div class="pkg-alloc-h"><div><b>' + esc(a.name) + '</b> <span class="badge ' + (PKG_BADGE[a.effective_status] || '') + '">' + esc(a.status_label) + '</span>' +
				'<div class="small">' + (a.project_name ? 'Project: <b>' + esc(a.project_name) + '</b>' : '<span class="muted">Client-wide (older package; choose a project under Adjust package)</span>') + (a.parent_inactive ? ' <span class="badge overdue">Client or project inactive</span>' : '') + '</div>' +
				'<div class="muted small">' + esc(a.type_label) + (a.price !== undefined ? ' · ' + rs(a.price) + (a.period_label ? ' / ' + esc(a.period_label) : '') : '') + ' · ' + esc(dates) + '</div>' +
			(a.cycle ? '<div class="small">' + (a.resets ? 'Current cycle: ' : 'Package period: ') + '<b>' + esc(a.cycle.label) + '</b>' + (a.resets ? ' <span class="muted">· resets each cycle</span>' : '') + '</div>' : '') + '</div>' +
			(manage ? '<div class="inv-actions"><button type="button" class="btn btn-sm" data-pk-edit="' + a.id + '">' + I.edit + ' Adjust package</button><button type="button" class="btn btn-sm" data-pk-units="' + a.id + '">± Units</button><button type="button" class="btn btn-sm btn-ghost" data-pk-ledger="' + a.id + '">Usage log</button>' + (a.carry ? '<button type="button" class="btn btn-sm' + (a.carry.pending ? ' btn-primary' : ' btn-ghost') + '" data-pk-carry="' + a.id + '">Carry forward' + (a.carry.pending ? ' (' + a.carry.pending + ')' : '') + '</button>' : '') + '</div>' : '') + '</div>' +
			usageTable(a.items) +
			(extra.length ? '<p class="small" style="margin:8px 0 0">' + esc(extra.join(' · ')) + '</p>' : '') +
			(a.scope || a.terms || a.remarks ? '<details class="pkg-more"><summary>Scope of work &amp; terms</summary>' + (a.scope ? '<div class="label">Scope</div><div class="desc">' + esc(a.scope) + '</div>' : '') + (a.terms ? '<div class="label">Terms</div><div class="desc">' + esc(a.terms) + '</div>' : '') + (a.remarks ? '<div class="label">Remarks</div><div class="desc">' + esc(a.remarks) + '</div>' : '') + '</details>' : '') +
			(hist.length ? '<details class="pkg-more"><summary>Previous cycles (' + hist.length + ')</summary><div class="table-wrap"><table class="tbl stack"><thead><tr><th>Cycle</th><th>Usage</th><th class="num">Retainer / additional</th></tr></thead><tbody>' + hist.map(function (h) {
				return '<tr><td data-th="Cycle">' + esc(h.label) + '</td><td data-th="Usage">' + (h.items.length ? h.items.map(function (it) { return esc(it.service_name) + ': <b>' + it.consumed + '</b>/' + (it.allocated + it.adjusted) + (it.reserved ? ' (+' + it.reserved + ' reserved)' : ''); }).join('<br>') : '<span class="muted">—</span>') + '</td><td data-th="Retainer / additional" class="num">' + h.retainer + ' / ' + h.additional + '</td></tr>';
			}).join('') + '</tbody></table></div></details>' : '') + '</div>';
	}
	/** Packages / service usage block of a client profile. */
	function clientPackages(box, act, client) {
		function load() {
			return api('clients/' + client.id + '/packages', { params: { history: 1 } }).then(function (r) {
				var list = r.packages, manage = r.can_manage;
				act.innerHTML = '<span class="muted small">Assigned on each project</span>';
				var active = list.filter(function (a) { return a.effective_status === 'active' || a.effective_status === 'upcoming' || a.effective_status === 'paused'; });
				var past = list.filter(function (a) { return active.indexOf(a) < 0; });
				box.innerHTML = (list.length ? active.map(function (a) { return allocationCard(a, manage); }).join('') + (past.length ? '<details class="pkg-more"><summary>Expired and cancelled packages (' + past.length + ')</summary>' + past.map(function (a) { return allocationCard(a, manage); }).join('') + '</details>' : '') : ui.emptyState('No packages', manage ? 'Assign a package to track the services included for this client.' : 'No service package is set up for this client.', I.briefcase)) +
					(r.additional.length ? '<details class="pkg-more"' + (list.length ? '' : ' open') + '><summary>Out-of-package work without a package (' + r.additional.length + ')</summary><ul class="timeline" style="padding:0 4px">' + r.additional.map(function (t) {
						return '<li><div class="body"><a href="#/tasks/' + t.id + '">#' + t.id + ' ' + esc(t.title) + '</a> ' + ui.statusBadge(t.status) + '<div class="small muted">' + esc(fmt.date(t.scheduled_date, true)) + (t.note ? ' · ' + esc(t.note) : '') + '</div></div></li>';
					}).join('') + '</ul></details>' : '');
				var byId = {};
				list.forEach(function (a) { byId[a.id] = a; });
				var asg = act.querySelector('[data-pk-assign]');
				if (asg) asg.onclick = function () { allocationForm(client, null, load); };
				box.onclick = function (e) {
					var b = e.target.closest('[data-pk-edit],[data-pk-units],[data-pk-ledger],[data-pk-carry]');
					if (!b) return;
					var a = byId[b.getAttribute('data-pk-edit') || b.getAttribute('data-pk-units') || b.getAttribute('data-pk-ledger') || b.getAttribute('data-pk-carry')];
					if (b.hasAttribute('data-pk-carry')) carryForm(a, load);
					else if (b.hasAttribute('data-pk-edit')) allocationForm(client, a, load);
					else if (b.hasAttribute('data-pk-units')) unitsForm(a, load);
					else usageLog(a);
				};
			}, function (e) { box.innerHTML = ''; box.appendChild(ui.errorBox(e, load)); });
		}
		return load();
	}
	var PKM = null;
	function pkgMeta() {
		if (PKM) return Promise.resolve(PKM);
		return Promise.all([api('package-services'), can('ndwd_manage_accounts') ? api('accounts/settings') : null]).then(function (r) {
			PKM = r[0];
			PKM.periods = r[1] ? r[1].periods : { '': 'One-time', monthly: 'Monthly' };
			return PKM;
		});
	}
	WD.pkgMeta = pkgMeta;
	WD.pkgMetaReset = function () { PKM = null; };
	/** Editable list of service lines [{id?, service_id, quantity, notes}]. */
	function pkgItemsEditor(services, items) {
		var root = el('<div class="pkg-items"><div data-rows></div><button type="button" class="btn btn-sm" data-add-item>' + I.plus + ' Add service</button></div>');
		var rows = root.querySelector('[data-rows]');
		function row(it) {
			var opts = services.filter(function (x) { return x.is_active || (it && +it.service_id === x.id); });
			var r = el('<div class="pkg-item-row"><select data-svc aria-label="Service"><option value="">Service…</option>' + opts.map(function (x) { return '<option value="' + x.id + '">' + esc(x.name) + ' (' + esc(x.unit_plural) + ')</option>'; }).join('') + '</select><input data-qty type="number" min="0" max="10000" step="1" placeholder="Qty" aria-label="Quantity"><input data-notes maxlength="255" placeholder="Notes, e.g. per month, up to 60 s" aria-label="Notes"><button type="button" class="btn btn-ghost btn-icon btn-sm" data-rm-item aria-label="Remove service">' + I.trash + '</button></div>');
			if (it) { r.dataset.id = it.id || ''; r.querySelector('[data-svc]').value = it.service_id; r.querySelector('[data-qty]').value = it.quantity; r.querySelector('[data-notes]').value = it.notes || ''; }
			rows.appendChild(r);
			return r;
		}
		(items || []).forEach(row);
		root.querySelector('[data-add-item]').onclick = function () { row(null).querySelector('[data-svc]').focus(); };
		on(rows, 'click', '[data-rm-item]', function (e, b) { b.closest('.pkg-item-row').remove(); });
		root.setValue = function (list) { rows.innerHTML = ''; (list || []).forEach(row); };
		root.getValue = function () {
			var out = [];
			$$('.pkg-item-row', rows).forEach(function (r) {
				var sid = r.querySelector('[data-svc]').value, q = r.querySelector('[data-qty]').value;
				if (!sid && !q) return;
				if (!sid) throw new Error('Choose the service for every line.');
				if (q === '' || +q < 0 || Math.floor(+q) !== +q) throw new Error('Enter a whole-number quantity (0 or more) for every service.');
				out.push({ id: r.dataset.id ? +r.dataset.id : 0, service_id: +sid, quantity: +q, notes: r.querySelector('[data-notes]').value.trim() });
			});
			return out;
		};
		return root;
	}
	WD.pkgItemsEditor = pkgItemsEditor;
	/** Assign a package to a client (a = null) or adjust an existing allocation. */
	function allocationForm(client, a, done, opts) {
		Promise.all([pkgMeta(), a ? null : api('accounts/packages')]).then(function (r) {
			var m = r[0], tpls = (r[1] || []).filter(function (p) { return p.is_active; });
			var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
				(a ? '' : '<label class="field span-2"><span>Package template</span><select name="package_id"><option value="">Client-specific package (no template)</option>' + tpls.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + ' — ' + rs(p.amount) + '</option>'; }).join('') + '</select></label>') +
				'<label class="field span-2"><span>Project <span class="hint">— its package counters are independent of other projects and sub-projects</span></span><select name="project_id">' + WD.selectOptions(S.projects.filter(function (p) { return +p.client_id === +client.id && (+p.is_active || (a && +p.id === +a.project_id)); }), a ? a.project_id : '', a ? 'Client-wide (older package)' : 'Choose a project…', function (p) { var par = p.parent_id ? WD.u.project(+p.parent_id) : null; return (par ? par.name + ' › ' : '') + p.name; }) + '</select></label>' +
				'<label class="field span-2"><span>Package name <span class="req">*</span></span><input name="name" maxlength="160"></label>' +
				'<label class="field"><span>Package type</span><select name="package_type"' + (a ? ' disabled' : '') + '>' + WD.mapOptions(m.types, a ? a.package_type : 'monthly') + '</select></label>' +
				'<label class="field"><span>Price (₹)</span><input name="price" inputmode="decimal" placeholder="0.00"></label>' +
				'<label class="field"><span>Billing frequency</span><select name="billing_period">' + WD.mapOptions(m.periods, a ? a.billing_period : 'monthly') + '</select></label>' +
				(a ? '<label class="field"><span>Status</span><select name="status">' + WD.mapOptions({ active: 'Active', paused: 'Paused', inactive: 'Deactivated', cancelled: 'Cancelled', archived: 'Archived' }, a.status) + '</select></label>' : '<div></div>') +
				'<label class="field"><span>Start date</span><input type="date" name="start_date"></label>' +
				'<label class="field"><span>End date <span class="hint">(required for fixed duration)</span></span><input type="date" name="end_date"></label>' +
				'<label class="check span-2" data-reset-row><input type="checkbox" name="resets"> Reset service limits at the start of every monthly cycle (cycles start on the start date’s day)</label>' +
				'<label class="check span-2"><input type="checkbox" name="reset_when_paused"> Keep resetting while the package is paused</label>' +
				'<div class="field span-2"><span>Included services and quantities <span class="hint">— per cycle for monthly packages, for the whole period otherwise</span></span><div data-items></div></div>' +
				'<label class="field span-2"><span>Scope of work</span><textarea name="scope" rows="3" placeholder="What the retainer covers, deliverables, exclusions"></textarea></label>' +
				'<label class="field span-2"><span>Additional terms</span><textarea name="terms" rows="2"></textarea></label>' +
				'<label class="field span-2"><span>Remarks</span><textarea name="remarks" rows="2"></textarea></label>' +
				'</div>' + (a ? '<p class="muted small">Changes apply to this client only and are recorded in the audit trail. New quantities apply to the current cycle; earlier cycles keep their limits. Removing a service keeps its usage history.</p>' : '<p class="muted small">The template’s terms are copied to this client. Later template changes do not affect this client.</p>') + '</form>');
			var items = pkgItemsEditor(m.services, a ? a.items.filter(function (it) { return it.is_active; }) : []);
			f.querySelector('[data-items]').appendChild(items);
			function fill(src, isTpl) {
				f.name.value = src.name || '';
				f.price.value = (isTpl ? src.amount : src.price) !== undefined ? Number(isTpl ? src.amount : src.price).toFixed(2) : '';
				f.billing_period.value = src.billing_period || '';
				if (!a) f.package_type.value = src.package_type || 'monthly';
				f.start_date.value = isTpl ? (src.start_date || S.today) : src.start_date;
				f.end_date.value = src.end_date || '';
				f.resets.checked = (src.reset_rule || 'monthly') === 'monthly';
				f.scope.value = src.scope || ''; f.terms.value = src.terms || ''; f.remarks.value = src.remarks || '';
				if (isTpl) items.setValue(src.items.map(function (it) { return { service_id: it.service_id, quantity: it.quantity, notes: it.notes }; }));
				syncType();
			}
			function syncType() { var mo = (a ? a.package_type : f.package_type.value) === 'monthly'; f.querySelector('[data-reset-row]').hidden = !mo; f.reset_when_paused.closest('label').hidden = !mo; }
			if (a) { fill(a, false); f.reset_when_paused.checked = a.reset_when_paused; } else { f.start_date.value = S.today; f.resets.checked = true; syncType(); f.package_type.onchange = syncType; }
			if (opts && opts.project_id) { f.project_id.value = String(opts.project_id); f.project_id.disabled = true; }
			if (!a) f.package_id.onchange = function () { var t = tpls.filter(function (p) { return String(p.id) === f.package_id.value; })[0]; if (t) fill(t, true); };
			var md = WD.modal({ title: a ? 'Adjust ' + a.name : 'Assign package to ' + client.name, size: 'lg', body: f, sticky: true, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>' + (a ? 'Save changes' : 'Assign package') + '</button>' });
			var ok = md.el.querySelector('[data-ok]'), err = f.querySelector('[data-err]');
			function fail(msg) { err.textContent = msg; err.classList.remove('hidden'); md.body.scrollTop = 0; }
			ok.onclick = function () {
				var body = { project_id: f.project_id.value || 0, name: f.name.value.trim(), price: f.price.value || 0, billing_period: f.billing_period.value, start_date: f.start_date.value, end_date: f.end_date.value, reset_rule: f.resets.checked ? 'monthly' : 'none', reset_when_paused: f.reset_when_paused.checked, scope: f.scope.value, terms: f.terms.value, remarks: f.remarks.value };
				try { body.items = items.getValue(); } catch (er) { return fail(er.message); }
				if (!body.name) return fail('Enter the package name.');
				if (a) { body.status = f.status.value; if (a.start_date === body.start_date) delete body.start_date; } else { body.package_type = f.package_type.value; if (f.package_id.value) body.package_id = f.package_id.value; }
				WD.busy(ok, true);
				api(a ? 'client-packages/' + a.id : 'clients/' + client.id + '/packages', { method: a ? 'PUT' : 'POST', body: body }).then(function () { md.close(); WD.toast(a ? 'Package updated' : 'Package assigned'); done(); }, function (e) { WD.busy(ok, false); fail(e.message); });
			};
		}, WD.toastError);
	}
	WD.allocationForm = allocationForm;
	/** Manual carry-forward: review the finished cycle's unused units, accept or reject per service. */
	function carryForm(a, done) {
		api('client-packages/' + a.id + '/carry-forward').then(function (r) {
			var rv = r.review, open = rv ? rv.items.filter(function (i) { return !i.decided; }) : [];
			var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div>' +
				(rv ? '<p class="small" style="margin-top:0">Unused units of <b>' + esc(rv.from) + '</b> can move to <b>' + esc(rv.to) + '</b>. What you do not accept is archived. Each cycle can be decided once, and at most one cycle\'s quantity can be carried.</p>' : '<p class="muted small" style="margin-top:0">There is no finished cycle to review right now.</p>') +
				(open.length ? open.map(function (i) { return '<div class="form-grid carry-row" data-c="' + i.item_id + '"><div><b>' + esc(i.service_name) + '</b><div class="muted small">' + i.unused + ' unused ' + esc(i.unit_plural) + ' · up to ' + i.max + ' can carry</div></div><label class="field"><span>Accept</span><input type="number" min="0" max="' + i.max + '" step="1" value="0" data-acc></label></div>'; }).join('') + '<label class="field"><span>Remarks <span class="hint">(optional)</span></span><textarea name="note" rows="2"></textarea></label>' : (rv ? '<p class="muted small">Nothing left to decide for this cycle.</p>' : '')) +
				(r.history.length ? '<details class="pkg-more"><summary>History (' + r.history.length + ')</summary><div class="table-wrap"><table class="tbl stack"><thead><tr><th>Cycle</th><th>Service</th><th class="num">Unused</th><th class="num">Carried</th><th class="num">Archived</th><th>By</th></tr></thead><tbody>' + r.history.map(function (h) { return '<tr><td data-th="Cycle">' + esc(fmt.date(h.from_cycle, true)) + '</td><td data-th="Service">' + esc(h.service_name) + '</td><td data-th="Unused" class="num">' + h.unused + '</td><td data-th="Carried" class="num">' + h.accepted + '</td><td data-th="Archived" class="num">' + h.rejected + '</td><td data-th="By">' + esc(h.by) + '</td></tr>'; }).join('') + '</tbody></table></div></details>' : '') + '</form>');
			var md = WD.modal({ title: 'Carry forward — ' + a.name, body: f, sticky: true, foot: '<button type="button" class="btn" data-close>' + (open.length ? 'Cancel' : 'Close') + '</button>' + (open.length ? '<button type="button" class="btn btn-primary" data-ok>Save decision</button>' : '') });
			var ok = md.el.querySelector('[data-ok]');
			if (ok) ok.onclick = function () {
				var dec = $$('[data-c]', f).map(function (x) { return { item_id: +x.getAttribute('data-c'), accept: +x.querySelector('[data-acc]').value || 0 }; });
				WD.busy(ok, true);
				api('client-packages/' + a.id + '/carry-forward', { method: 'POST', body: { from_cycle: rv.from_cycle, decisions: dec, note: f.note.value } }).then(function () { md.close(); WD.toast('Carry-forward saved'); done(); }, function (e) { WD.busy(ok, false); var er = f.querySelector('[data-err]'); er.textContent = e.message; er.classList.remove('hidden'); });
			};
		}, WD.toastError);
	}
	WD.carryForm = carryForm;
	function unitsForm(a, done) {
		var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
			'<label class="field"><span>Service</span><select name="item_id">' + a.items.filter(function (it) { return it.is_active; }).map(function (it) { return '<option value="' + it.id + '">' + esc(it.service_name) + ' (' + it.remaining + ' left)</option>'; }).join('') + '</select></label>' +
			'<label class="field"><span>Units to add <span class="hint">(negative to remove)</span></span><input name="quantity" type="number" step="1" value="1"></label>' +
			'<label class="field span-2"><span>Reason <span class="req">*</span></span><textarea name="note" rows="2" placeholder="e.g. Approved two extra videos for the launch"></textarea></label></div>' +
			'<p class="muted small">Applies to the current cycle' + (a.cycle ? ' (' + esc(a.cycle.label) + ')' : '') + ' only and is kept in the usage log.</p></form>');
		var md = WD.modal({ title: 'Adjust units — ' + a.name, body: f, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save adjustment</button>' });
		var ok = md.el.querySelector('[data-ok]'), err = f.querySelector('[data-err]');
		ok.onclick = function () {
			if (!f.note.value.trim()) { err.textContent = 'Give the reason for the adjustment.'; err.classList.remove('hidden'); return; }
			WD.busy(ok, true);
			api('client-packages/' + a.id + '/adjust', { method: 'POST', body: { item_id: f.item_id.value, quantity: f.quantity.value, note: f.note.value } }).then(function () { md.close(); WD.toast('Adjustment saved'); done(); }, function (e) { WD.busy(ok, false); err.textContent = e.message; err.classList.remove('hidden'); });
		};
	}
	var STATE_LBL = { reserved: 'Reserved', consumed: 'Used', released: 'Released', adjustment: 'Adjustment' };
	function usageLog(a) {
		var body = el('<div>' + ui.loading(4) + '</div>');
		WD.modal({ title: 'Usage log — ' + a.name, size: 'lg', body: body, foot: '<button type="button" class="btn" data-close>Close</button>' });
		api('client-packages/' + a.id).then(function (d) {
			body.innerHTML = (d.entries || []).length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>When</th><th>Task</th><th>Service</th><th>Cycle</th><th class="num">Qty</th><th>State</th><th>Note</th></tr></thead><tbody>' + d.entries.map(function (u) {
				return '<tr><td data-th="When">' + esc(fmt.dt(u.created_at)) + '<div class="muted small">' + esc(u.created_by) + '</div></td><td data-th="Task">' + (u.task_id ? '<a href="#/tasks/' + u.task_id + '">#' + u.task_id + ' ' + esc(u.task_title) + '</a>' : '<span class="muted">—</span>') + '</td><td data-th="Service">' + esc(u.service_name) + '</td><td data-th="Cycle">' + esc(fmt.date(u.cycle_start, true)) + '</td><td data-th="Qty" class="num">' + (u.quantity > 0 && u.state === 'adjustment' ? '+' : '') + u.quantity + '</td>' +
					'<td data-th="State"><span class="badge ' + ({ reserved: 's-in_process', consumed: 's-completed', released: '', adjustment: 's-pending' }[u.state] || '') + '">' + esc(STATE_LBL[u.state] || u.state) + '</span>' + (u.over_limit ? ' <span class="badge overdue">Beyond limit</span>' : '') + '</td><td data-th="Note" class="wrap small">' + esc([u.note, u.approved_by && u.over_limit ? 'approved by ' + u.approved_by : '', u.release_reason ? 'released: ' + u.release_reason : ''].filter(Boolean).join(' · ')) + '</td></tr>';
			}).join('') + '</tbody></table></div>' : ui.emptyState('No usage yet', 'Tasks linked to an included service appear here.', I.list);
		}, function (e) { body.innerHTML = ''; body.appendChild(ui.errorBox(e)); });
	}
	WD.allocationCard = allocationCard;
	WD.u.meter = meter;

	/* ------------------------------------------------------------------ *
	 * Leave
	 * ------------------------------------------------------------------ */
	var LEAVE_BADGE = { pending: 's-pending', approved: 's-completed', rejected: 's-blocked', cancelled: '' };
	function leaveBadge(s) { return '<span class="badge ' + (LEAVE_BADGE[s] || '') + '">' + esc({ pending: 'Pending', approved: 'Approved', rejected: 'Rejected', cancelled: 'Cancelled' }[s] || s) + '</span>'; }
	function leaveDates(l) { return l.start_date === l.end_date ? fmt.date(l.start_date, true) : fmt.date(l.start_date) + ' – ' + fmt.date(l.end_date, true); }

	function openLeaveForm(done) {
		var types = S.leave_types || {};
		var form = el('<form novalidate><div class="alert alert-error hidden" data-err role="alert"></div><div class="form-grid">' +
			'<label class="field span-2"><span>Leave type <span class="req">*</span></span><select name="type">' + WD.mapOptions(types, 'casual') + '</select></label>' +
			'<label class="check span-2"><input type="checkbox" name="single" checked> Single day</label>' +
			'<label class="field"><span>Start date <span class="req">*</span></span><input type="date" name="start_date" required></label>' +
			'<label class="field" data-end><span>End date <span class="req">*</span></span><input type="date" name="end_date"></label>' +
			'<label class="field span-2"><span>Reason <span class="req">*</span></span><textarea name="reason" rows="3" maxlength="1000" placeholder="e.g. Family function in Pune"></textarea></label>' +
			'</div><p class="muted small" data-days style="margin-top:10px"></p><p class="muted small">Your manager is notified and can approve or reject it. You can cancel until the day before the leave starts.</p></form>');
		var tomorrow = fmt.addDays(S.today, 1);
		form.start_date.value = tomorrow;
		form.start_date.min = S.today;
		form.end_date.min = S.today;
		function sync() {
			var single = form.single.checked;
			form.querySelector('[data-end]').classList.toggle('hidden', single);
			if (single || !form.end_date.value || form.end_date.value < form.start_date.value) form.end_date.value = form.start_date.value;
			form.end_date.min = form.start_date.value || S.today;
			var s = form.start_date.value, e = form.end_date.value;
			var n = s && e && e >= s ? Math.round((Date.parse(e) - Date.parse(s)) / 864e5) + 1 : 0;
			form.querySelector('[data-days]').textContent = n ? n + ' day' + (n === 1 ? '' : 's') + ' of leave.' : '';
		}
		form.single.onchange = sync; form.start_date.onchange = sync; form.end_date.onchange = sync;
		sync();
		var md = WD.modal({ title: 'Apply for leave', size: 'sm', body: form, sticky: true, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-save>Submit request</button>' });
		var save = md.el.querySelector('[data-save]'), err = form.querySelector('[data-err]');
		function fail(m) { err.textContent = m; err.classList.remove('hidden'); }
		form.onsubmit = function (e) { e.preventDefault(); save.click(); };
		save.onclick = function () {
			if (save.disabled) return;
			err.classList.add('hidden');
			sync();
			var body = { type: form.type.value, start_date: form.start_date.value, end_date: form.single.checked ? form.start_date.value : form.end_date.value, reason: form.reason.value.trim() };
			if (!body.start_date) return fail('Please choose the start date.');
			if (body.start_date < S.today) return fail('Leave cannot start in the past.');
			if (body.end_date < body.start_date) return fail('End date cannot be before the start date.');
			if (!body.reason) { form.reason.focus(); return fail('Please give a reason for the leave.'); }
			WD.busy(save, true, 'Submitting…');
			api('leaves', { method: 'POST', body: body }).then(function (l) {
				md.close();
				WD.toast(l.reviewers ? 'Leave request sent for approval' : 'Leave request saved — no reviewer is set up for your team yet, so tell your manager.', l.reviewers ? 'success' : 'error');
				if (done) done();
			}, function (e) { WD.busy(save, false); fail(e.message); });
		};
	}
	WD.openLeaveForm = openLeaveForm;

	S.lv = S.lv || { status: 'pending' };
	V.leave = function (c) {
		WD.setTitle('Leave', 'Apply for leave, track decisions, and review your team’s requests');
		c.innerHTML = '<div class="toolbar"><span class="spacer"></span><button class="btn btn-primary" data-apply>' + I.plus + ' Apply for leave</button></div><div data-body>' + ui.loading(6) + '</div>';
		c.querySelector('[data-apply]').onclick = function () { openLeaveForm(function () { V.leave(c); }); };
		return api('leaves', { params: { status: S.lv.status === 'all' ? '' : S.lv.status } }).then(function (r) {
			var body = c.querySelector('[data-body]');
			var mine = r.mine.length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>Dates</th><th>Type</th><th>Reason</th><th>Status</th><th>Decision</th><th></th></tr></thead><tbody>' + r.mine.map(function (l) {
				return '<tr><td data-th="Dates"><b>' + esc(leaveDates(l)) + '</b><div class="muted small">' + l.days + ' day' + (l.days === 1 ? '' : 's') + '</div></td><td data-th="Type">' + esc(l.type_label) + '</td><td data-th="Reason" class="wrap">' + esc(l.reason) + '</td><td data-th="Status">' + leaveBadge(l.status) + '</td>' +
					'<td data-th="Decision" class="small">' + (l.reviewed_by ? esc(l.reviewed_by) + ' · ' + esc(fmt.dt(l.reviewed_at)) + (l.review_note ? '<div class="muted">' + esc(l.review_note) + '</div>' : '') : (l.cancelled_at ? 'Cancelled ' + esc(fmt.dt(l.cancelled_at)) : '<span class="muted">Awaiting review</span>')) + '</td>' +
					'<td class="actions">' + (l.can_cancel ? '<button class="btn btn-sm btn-danger" data-cancel="' + l.id + '">Cancel</button>' : (l.started && (l.status === 'approved' || l.status === 'pending') ? '<span class="muted small" title="Leave that has started cannot be cancelled">Started</span>' : '')) + '</td></tr>';
			}).join('') + '</tbody></table></div>' : ui.emptyState('No leave requests yet', 'Use “Apply for leave” to send one to your manager.', I.plane);
			var review = '';
			if (r.can_review) {
				review = '<section class="card section-gap"><div class="card-h"><h2>Team requests</h2><span class="spacer"></span><div class="seg" data-lstatus>' + [['pending', 'Pending'], ['approved', 'Approved'], ['all', 'All']].map(function (s) { return '<button data-v="' + s[0] + '"' + (S.lv.status === s[0] ? ' class="on"' : '') + '>' + s[1] + '</button>'; }).join('') + '</div></div>' +
					(r.review.length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>Member</th><th>Dates</th><th>Type</th><th>Reason</th><th>Status</th><th></th></tr></thead><tbody>' + r.review.map(function (l) {
						return '<tr><td data-th="Member"><span style="display:inline-flex;gap:8px;align-items:center">' + ui.avatar(l.user_id, 'sm') + esc(l.user) + '</span></td><td data-th="Dates"><b>' + esc(leaveDates(l)) + '</b><div class="muted small">' + l.days + ' day' + (l.days === 1 ? '' : 's') + '</div></td><td data-th="Type">' + esc(l.type_label) + '</td><td data-th="Reason" class="wrap">' + esc(l.reason) + '</td>' +
							'<td data-th="Status">' + leaveBadge(l.status) + (l.reviewed_by ? '<div class="muted small">by ' + esc(l.reviewed_by) + '</div>' : '') + '</td><td class="actions">' +
							(l.can_review ? '<button class="btn btn-sm btn-primary" data-approve="' + l.id + '">Approve</button> <button class="btn btn-sm btn-danger" data-reject="' + l.id + '">Reject</button>' : '') +
							(l.can_reassign ? '<button class="btn btn-sm" data-reassign="' + l.id + '">' + I.users + ' Reassign work</button>' : '') + '</td></tr>';
					}).join('') + '</tbody></table></div>' : ui.emptyState(S.lv.status === 'pending' ? 'No requests waiting for you' : 'No requests', '', I.plane)) + '</section>';
			}
			body.innerHTML = (r.can_review ? review : '') + '<section class="card' + (r.can_review ? ' section-gap' : '') + '"><div class="card-h"><h2>My leave</h2></div>' + mine + '</section>';
			S.leave_pending = r.can_review ? r.review.filter(function (l) { return l.can_review; }).length : 0;
			if (S.lv.status !== 'pending') S.leave_pending = null;
			if (S.leave_pending !== null) WD.updateBadge();
			var seg = body.querySelector('[data-lstatus]');
			if (seg) seg.onclick = function (e) { var b = e.target.closest('button'); if (b) { S.lv.status = b.getAttribute('data-v'); V.leave(c); } };
			on(body, 'click', '[data-cancel]', function (e, b) {
				WD.confirm('Cancel this leave?', 'Your manager and team will be told the leave is cancelled.', 'Cancel leave', true).then(function (ok) {
					if (!ok) return;
					WD.busy(b, true, '…');
					api('leaves/' + b.getAttribute('data-cancel') + '/cancel', { method: 'POST' }).then(function () { WD.toast('Leave cancelled'); V.leave(c); }, function (er) { WD.busy(b, false); WD.toastError(er); });
				});
			});
			on(body, 'click', '[data-approve]', function (e, b) { decide(b.getAttribute('data-approve'), 'approved', b); });
			on(body, 'click', '[data-reject]', function (e, b) { decide(b.getAttribute('data-reject'), 'rejected', b); });
			on(body, 'click', '[data-reassign]', function (e, b) { reassignForm(+b.getAttribute('data-reassign'), function () { V.leave(c); }); });
			function decide(id, decision, btn) {
				var form = el('<form><div class="alert alert-error hidden" data-err></div><label class="field"><span>Note to the member' + (decision === 'rejected' ? ' <span class="req">*</span>' : ' <span class="hint">(optional)</span>') + '</span><textarea name="note" rows="3"></textarea></label></form>');
				var md = WD.modal({ title: decision === 'approved' ? 'Approve leave' : 'Reject leave', size: 'sm', body: form, foot: '<button type="button" class="btn" data-close>Back</button><button type="button" class="btn ' + (decision === 'approved' ? 'btn-primary' : 'btn-danger') + '" data-save>' + (decision === 'approved' ? 'Approve' : 'Reject') + '</button>' });
				var save = md.el.querySelector('[data-save]');
				save.onclick = function () {
					if (save.disabled) return;
					var note = form.note.value.trim(), err = form.querySelector('[data-err]');
					if (decision === 'rejected' && !note) { err.textContent = 'Please add a note explaining the rejection.'; err.classList.remove('hidden'); return; }
					WD.busy(save, true);
					api('leaves/' + id + '/decision', { method: 'POST', body: { decision: decision, note: note } }).then(function () {
						md.close();
						WD.toast(decision === 'approved' ? 'Leave approved — the member and team have been notified' : 'Leave rejected');
						if (decision === 'approved') reassignForm(+id, function () { V.leave(c); }, true); else V.leave(c);
					}, function (er) { WD.busy(save, false); err.textContent = er.message; err.classList.remove('hidden'); });
				};
			}
		});
	};

	/** After approval: move the absent member's open tasks to a colleague. */
	function reassignForm(id, done, afterApprove) {
		api('leaves/' + id + '/tasks').then(function (d) {
			var l = d.leave;
			if (!d.tasks.length) {
				if (afterApprove) { done(); return; }
				WD.toast(l.user + ' has no open tasks to reassign');
				done();
				return;
			}
			var form = el('<form><div class="alert alert-error hidden" data-err></div><p class="muted">' + esc(l.user) + ' is on leave ' + esc(leaveDates(l)) + '. Choose the tasks to hand over. Task history is kept and the change is recorded.</p>' +
				'<label class="check" style="margin:6px 0 4px"><input type="checkbox" data-all checked> Select all</label>' +
				'<div class="reassign-list">' + d.tasks.map(function (t) {
					return '<label class="check reassign-item"><input type="checkbox" value="' + t.id + '" checked><span><b>#' + t.id + ' ' + esc(t.title) + '</b><span class="muted small"> · ' + esc(S.statuses[t.status]) + ' · ' + esc(fmt.day(t.scheduled_date)) + (t.due_date ? ' · due ' + esc(fmt.date(t.due_date)) : '') + '</span></span></label>';
				}).join('') + '</div>' +
				'<label class="field" style="margin-top:12px"><span>Hand over to <span class="req">*</span></span><select name="to"><option value="">Choose a member…</option>' + d.candidates.map(function (m) {
					return '<option value="' + m.id + '"' + (m.on_leave ? ' disabled' : '') + '>' + esc(m.name) + (m.on_leave ? ' (also on leave)' : m.same_team ? '' : ' (other team)') + '</option>';
				}).join('') + '</select></label></form>');
			var all = form.querySelector('[data-all]');
			all.onchange = function () { $$('.reassign-item input', form).forEach(function (i) { i.checked = all.checked; }); };
			var md = WD.modal({ title: 'Reassign work for ' + l.user, body: form, sticky: true, foot: '<button type="button" class="btn" data-close>' + (afterApprove ? 'Not now' : 'Cancel') + '</button><button type="button" class="btn btn-primary" data-save>Reassign selected</button>', onClose: function () { done(); } });
			var save = md.el.querySelector('[data-save]'), err = form.querySelector('[data-err]');
			save.onclick = function () {
				if (save.disabled) return;
				var ids = $$('.reassign-item input:checked', form).map(function (i) { return +i.value; });
				var to = +form.to.value;
				if (!ids.length) { err.textContent = 'Select at least one task.'; err.classList.remove('hidden'); return; }
				if (!to) { err.textContent = 'Choose who takes over the work.'; err.classList.remove('hidden'); return; }
				WD.busy(save, true, 'Reassigning…');
				api('leaves/' + id + '/reassign', { method: 'POST', body: { task_ids: ids, to_user: to } }).then(function (res) {
					md.close();
					WD.toast(res.moved.length + ' task' + (res.moved.length === 1 ? '' : 's') + ' reassigned to ' + WD.u.memberName(to) + (res.errors.length ? ' (' + res.errors.length + ' skipped)' : ''), res.errors.length ? 'error' : 'success');
				}, function (er) { WD.busy(save, false); err.textContent = er.message; err.classList.remove('hidden'); });
			};
		}, WD.toastError);
	}
})();
