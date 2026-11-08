/* NeonDigital WorkDesk — Accounts: overview, invoices, income, expenses, passbook, reports, packages, settings. */
(function () {
	'use strict';

	var WD = window.WD, S = WD.S, I = WD.I, api = WD.api, V = WD.views;
	var esc = WD.u.esc, el = WD.u.el, $$ = WD.u.$$, on = WD.u.on, can = WD.u.can;
	var ui = WD.ui, fmt = WD.fmt;
	var A = { meta: null };

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */
	var nf = (function () { try { return new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); } catch (e) { return null; } })();
	function inr(n) {
		n = Number(n || 0);
		return '₹' + (nf ? nf.format(n) : n.toFixed(2));
	}
	function money(v) { var n = parseFloat(String(v || '').replace(/[,₹\s]/g, '')); return isNaN(n) ? NaN : Math.round(n * 100); }
	function hashParams() {
		var q = location.hash.split('?')[1] || '', out = {};
		q.split('&').forEach(function (p) { var kv = p.split('='); if (kv[0]) out[decodeURIComponent(kv[0])] = decodeURIComponent(kv[1] || ''); });
		return out;
	}
	function fyOf(d) { var y = +d.slice(0, 4), m = +d.slice(5, 7), s = m >= 4 ? y : y - 1; return s + '-' + String(s + 1).slice(2); }
	function meta() {
		if (A.meta) return Promise.resolve(A.meta);
		return api('accounts/settings').then(function (m) { A.meta = m; return m; });
	}
	var STATUS_CLASS = { unpaid: 's-pending', partial: 's-in_process', paid: 's-completed', consolidated: 's-completed', cancelled: '' };
	function invBadge(v) {
		return '<span class="badge ' + (STATUS_CLASS[v.status] || '') + '">' + esc(v.status_label) + '</span>' + (v.is_overdue ? ' <span class="badge overdue">Overdue</span>' : '');
	}
	function fail(form, msg) { var x = form.querySelector('[data-err]'); x.textContent = msg; x.classList.remove('hidden'); x.scrollIntoView({ block: 'nearest' }); }
	/*
	 * Payment account (Received into / Paid from): which saved account the money moved
	 * through, separate from the payment method (how it moved). The list follows the
	 * method (Card → cards, Bank transfer → bank accounts …); "Other" takes a typed
	 * account, optionally saved for next time. Blank stays allowed. A transaction's own
	 * account is always kept, even when that account was renamed or deactivated later.
	 */
	function accByName(n) { return (A.meta.payment_accounts || []).filter(function (a) { return a.name === n; })[0]; }
	function accOptions(sel, method) {
		var m = A.meta, types = m.account_types || {}, want = (m.method_account_types || {})[method] || '';
		var act = (m.payment_accounts || []).filter(function (a) { return a.active; });
		var list = want ? act.filter(function (a) { return a.type === want; }) : act;
		if (want && !list.length) list = act;
		var known = list.some(function (a) { return a.name === sel; });
		var h = '<option value="">—</option>' + (sel && !known ? '<option selected>' + esc(sel) + '</option>' : '');
		Object.keys(types).forEach(function (t) {
			var g = list.filter(function (a) { return a.type === t; });
			if (g.length) h += '<optgroup label="' + esc(types[t]) + '">' + g.map(function (a) { return '<option value="' + esc(a.name) + '"' + (a.name === sel ? ' selected' : '') + '>' + esc(a.name + (a.ref ? ' · ' + a.ref : '')) + '</option>'; }).join('') + '</optgroup>';
		});
		return h + '<option value="" data-other>Other (enter account)…</option>';
	}
	function destField(label, sel, method) {
		return '<div class="field" data-dest><span>' + esc(label) + ' <span class="hint">(optional)</span></span><select name="destination" aria-label="' + esc(label) + '">' + accOptions(sel || '', method || '') + '</select>' +
			'<input data-dest-other class="cat-custom" maxlength="160" placeholder="Account name / details, e.g. ICICI Credit Card XX1234" aria-label="Other account" hidden>' +
			'<label class="check small cat-custom" data-dest-save hidden><input type="checkbox" data-save-acc> Save this account for future use</label></div>';
	}
	function syncDest(d, focus) {
		var s = d.querySelector('select'), o = s.selectedOptions[0], other = !!(o && o.hasAttribute('data-other')), inp = d.querySelector('[data-dest-other]');
		inp.hidden = !other;
		d.querySelector('[data-dest-save]').hidden = !other;
		if (other) { o.value = inp.value.trim(); if (focus) inp.focus(); }
	}
	function refilterDest(d, method) {
		var s = d.querySelector('select'), o = s.selectedOptions[0], other = !!(o && o.hasAttribute('data-other')), cur = other ? '' : s.value;
		var want = (A.meta.method_account_types || {})[method] || '', acc = accByName(cur);
		if (cur && want && acc && acc.type !== want) cur = ''; // method changed: drop a saved account of another kind
		s.innerHTML = accOptions(cur, method);
		if (other) s.querySelector('option[data-other]').selected = true;
		else if (!cur && want) { var g = s.querySelectorAll('optgroup option'); if (g.length === 1) g[0].selected = true; }
		syncDest(d);
	}
	/** "Save for future use" flag for the request body (the server skips names that already exist). */
	function saveAcc(f) {
		var c = f.querySelector('[data-save-acc]');
		if (!c || !c.checked || c.closest('[hidden]') || !f.destination.value) return '';
		if (!accByName(f.destination.value)) (A.meta.payment_accounts = A.meta.payment_accounts || []).push({ id: 0, name: f.destination.value, type: (A.meta.method_account_types || {})[f.method.value] || 'other', ref: '', active: true, aliases: [] });
		return 1;
	}
	document.addEventListener('change', function (e) {
		var t = e.target;
		if (t.name === 'destination' && t.closest('[data-dest]')) syncDest(t.closest('[data-dest]'), true);
		else if (t.name === 'method' && t.form && t.form.querySelector('[data-dest]')) refilterDest(t.form.querySelector('[data-dest]'), t.value);
	});
	document.addEventListener('input', function (e) {
		if (e.target.hasAttribute && e.target.hasAttribute('data-dest-other')) syncDest(e.target.closest('[data-dest]'));
	});
	/* Sortable tables: click a header to sort (again to reverse). Dates compare chronologically,
	 * numbers numerically, text case-insensitively; ties keep the server order (stable). The
	 * sort state lives in the section's filter object, so it survives searches and filters. */
	function sortTh(st, key, label, cls) {
		var cur = st.sort === key, asc = cur && st.dir === 'asc';
		return '<th class="sortable' + (cls ? ' ' + cls : '') + (cur ? ' sorted' : '') + '" data-sort="' + key + '" tabindex="0" aria-sort="' + (cur ? (asc ? 'ascending' : 'descending') : 'none') + '" title="Sort by ' + esc(label.toLowerCase()) + '">' + esc(label) + '<i class="sort-ind" aria-hidden="true">' + (cur ? (asc ? '▲' : '▼') : '↕') + '</i></th>';
	}
	function sortRows(rows, st, cols) {
		var c = cols[st.sort];
		if (!c) return rows.slice();
		var get = c[0], type = c[1], m = st.dir === 'asc' ? 1 : -1;
		return rows.map(function (r, i) { return [get(r), i, r]; }).sort(function (a, b) {
			var x = a[0], y = b[0], d;
			if (type === 'num') d = (+x || 0) - (+y || 0);
			else if (type === 'date') { x = String(x || ''); y = String(y || ''); d = x < y ? -1 : x > y ? 1 : 0; }
			else d = String(x || '').localeCompare(String(y || ''), undefined, { numeric: true, sensitivity: 'base' });
			return d * m || a[1] - b[1];
		}).map(function (a) { return a[2]; });
	}
	function bindSort(box, st, cols, paint) {
		var go = function (e, th) {
			if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
			e.preventDefault();
			var k = th.getAttribute('data-sort');
			if (st.sort === k) st.dir = st.dir === 'asc' ? 'desc' : 'asc';
			else { st.sort = k; st.dir = cols[k][1] === 'text' ? 'asc' : 'desc'; }
			paint();
			var n = box.querySelector('th[data-sort="' + k + '"]'); if (n) n.focus();
		};
		on(box, 'click', 'th[data-sort]', go);
		on(box, 'keydown', 'th[data-sort]', go);
	}
	/** Search field with a clear button; onChange(value) runs after typing stops or on clear. */
	function searchBox(attr, value, placeholder, label) {
		return '<label class="field filter-q search-box"><span>' + esc(label || 'Search') + '</span><span class="search-wrap"><input type="search" ' + attr + ' placeholder="' + esc(placeholder) + '" value="' + esc(value || '') + '"><button type="button" class="search-clear" data-sclear title="Clear search" aria-label="Clear search"' + (value ? '' : ' hidden') + '>×</button></span></label>';
	}
	function bindSearch(root, sel, onChange) {
		var i = root.querySelector(sel), x = i.parentNode.querySelector('[data-sclear]');
		var fire = WD.u.debounce(function () { onChange(i.value.trim()); }, 300);
		i.addEventListener('input', function () { x.hidden = !i.value; fire(); });
		x.onclick = function (e) { e.preventDefault(); i.value = ''; x.hidden = true; onChange(''); i.focus(); };
	}
	function invLink(ref) { return ref ? '<a href="#/accounts/invoices/' + ref.id + '">' + esc(ref.invoice_no) + '</a>' : ''; }
	function fyOptions(list, sel) { return list.map(function (f) { return '<option value="' + esc(f) + '"' + (f === sel ? ' selected' : '') + '>FY ' + esc(f) + '</option>'; }).join(''); }

	/**
	 * Available income / advance payments of a client, to adjust against an invoice.
	 * Money already received is only re-applied (an allocation), never counted again.
	 * opts: { exclude: invoice id being edited/replaced, preset: {income_id: rupees}, payable(): paise, onChange() }
	 */
	function advancePicker(box, opts) {
		var st = { client: 0, rows: [], sel: {}, project: '' }, pk = {};
		Object.keys(opts.preset || {}).forEach(function (k) { pk[k] = (+opts.preset[k]).toFixed(2); });
		function picked() { return st.rows.filter(function (x) { return st.sel[x.id] !== undefined; }); }
		function sum() { return picked().reduce(function (t, x) { var a = money(st.sel[x.id]); return t + (isNaN(a) ? 0 : a); }, 0); }
		function avail(x) { return Math.round((x.available + x.allocated_here) * 100); }
		function paint() {
			if (!st.client) { box.innerHTML = '<div class="card-b muted small">Choose a client to fetch income already received from them.</div>'; return; }
			var projs = S.projects.filter(function (p) { return +p.client_id === st.client; });
			var payable = opts.payable(), s = sum(), err = '';
			picked().forEach(function (x) { var a = money(st.sel[x.id]); if (isNaN(a) || a <= 0) err = 'Enter the amount to adjust from “' + x.description + '”.'; else if (a > avail(x)) err = '“' + x.description + '” has only ' + inr(avail(x) / 100) + ' available.'; });
			if (!err && s > payable) err = 'Advances adjusted (' + inr(s / 100) + ') are more than the invoice total (' + inr(payable / 100) + ').';
			box.innerHTML = '<div class="card-b adv-head"><p class="muted small">Optional. Money this client already paid (e.g. an advance) can settle this invoice. It is adjusted, not received again: Income and the passbook keep showing the original receipt once.</p>' +
				(projs.length ? '<label class="field adv-proj"><span>Project</span><select data-adv-proj><option value="">All projects of this client</option>' + projs.map(function (p) { return '<option value="' + p.id + '"' + (String(p.id) === st.project ? ' selected' : '') + '>' + esc(p.name) + '</option>'; }).join('') + '</select></label>' : '') + '</div>' +
				(st.rows.length ? '<div class="table-wrap"><table class="tbl stack adv-tbl"><thead><tr><th style="width:36px"></th><th>Income received</th><th class="num">Received</th><th class="num">Allocated</th><th class="num">Available</th><th class="num">Adjust (₹)</th></tr></thead><tbody>' + st.rows.map(function (x) {
					var on = st.sel[x.id] !== undefined;
					return '<tr data-adv="' + x.id + '"' + (on ? '' : ' class="dim"') + '><td><input type="checkbox" data-adv-on' + (on ? ' checked' : '') + ' aria-label="Use this income"></td><td data-th="Income"><b>' + esc(x.description) + '</b><div class="small"><span class="badge">' + esc(x.source) + '</span>' + (x.project_name ? ' <span class="muted">' + esc(x.project_name) + '</span>' : '') + (x.reference ? ' <span class="muted">Ref ' + esc(x.reference) + '</span>' : '') + '</div><div class="small muted">Received ' + esc(fmt.date(x.received_on, true)) + '</div></td>' +
						'<td data-th="Received" class="num">' + inr(x.amount) + '</td><td data-th="Allocated elsewhere" class="num" title="Already adjusted against other invoices">' + inr(x.allocated) + '</td><td data-th="Available" class="num amt-in">' + inr(avail(x) / 100) + '</td>' +
						'<td data-th="Adjust now" class="num">' + (on ? '<input data-adv-amt inputmode="decimal" value="' + esc(st.sel[x.id]) + '" style="max-width:110px;text-align:right" aria-label="Amount to adjust">' : '<span class="muted small">—</span>') + '</td></tr>';
				}).join('') + '</tbody></table></div>' : ui.emptyState('No available income for this client', 'Record an advance under Income (with this client) to adjust it here. Invoices without advances work as usual.', I.rupee)) +
				'<div class="card-b adv-sum"><dl class="kv"><dt>Invoice total</dt><dd>' + inr(payable / 100) + '</dd><dt>Advance adjusted</dt><dd>' + (s ? '− ' : '') + inr(s / 100) + '</dd><dt><b>Remaining payable</b></dt><dd><b>' + inr(Math.max(0, payable - s) / 100) + '</b></dd></dl>' + (err ? '<p class="small" style="color:var(--danger)" data-adv-err>' + esc(err) + '</p>' : '') + '</div>';
		}
		function load() {
			if (!st.client) { st.rows = []; paint(); return Promise.resolve(); }
			box.innerHTML = '<div class="card-b">' + ui.loading(2) + '</div>';
			return api('accounts/clients/' + st.client + '/available-income', { params: { project_id: st.project, exclude_invoice: opts.exclude || '' } }).then(function (rows) {
				st.rows = rows;
				rows.forEach(function (x) { if (pk[x.id] !== undefined && st.sel[x.id] === undefined) st.sel[x.id] = pk[x.id]; });
				Object.keys(st.sel).forEach(function (k) { if (!rows.some(function (x) { return String(x.id) === k; })) delete st.sel[k]; });
				paint(); if (opts.onChange) opts.onChange();
			}, function (e) { box.innerHTML = ''; box.appendChild(ui.errorBox(e, load)); });
		}
		var rowOf = function (n) { var id = n.closest('[data-adv]').getAttribute('data-adv'); return st.rows.filter(function (x) { return String(x.id) === id; })[0]; };
		on(box, 'change', '[data-adv-on]', function (e, i) {
			var x = rowOf(i);
			if (i.checked) { var rest = Math.max(0, opts.payable() - sum()); st.sel[x.id] = (Math.min(avail(x), rest || avail(x)) / 100).toFixed(2); } else delete st.sel[x.id];
			paint(); if (opts.onChange) opts.onChange();
		});
		on(box, 'input', '[data-adv-amt]', function (e, i) { st.sel[rowOf(i).id] = i.value; if (opts.onChange) opts.onChange(); });
		on(box, 'change', '[data-adv-amt]', function () { paint(); });
		on(box, 'change', '[data-adv-proj]', function (e, s) { st.project = s.value; load(); });
		return {
			setClient: function (id) { st.client = +id || 0; st.sel = {}; st.project = ''; return load(); },
			refresh: function () { var a = document.activeElement; if (!a || !box.contains(a) || !a.hasAttribute('data-adv-amt')) paint(); },
			total: sum,
			error: function () { paint(); var x = box.querySelector('[data-adv-err]'); return x ? x.textContent : ''; },
			value: function () { return picked().map(function (x) { return { income_id: x.id, amount: st.sel[x.id] }; }); },
			// Unallocated money of the client still free after this invoice's adjustments.
			credit: function () { return Math.max(0, st.rows.reduce(function (t, x) { return t + avail(x); }, 0) - sum()); }
		};
	}

	/**
	 * Billable client expenses for an invoice. Each expense is billed once; billed ones show
	 * their invoice and cannot be picked. The expense record itself is never changed here
	 * (except "Mark billable", which is an explicit edit of the expense).
	 * opts: { exclude: invoice id being replaced, preset: {expense_id: {amount, reason}}, onChange() }
	 */
	function expensePicker(box, opts) {
		var st = { client: 0, rows: [], sel: {}, project: '', all: false }, pk = opts.preset || {};
		function prop(x) { return Math.round(x.billable_amount * 100); }
		function eligible(x) { return x.billable && !x.billed_in.length; }
		function picked() { return st.rows.filter(function (x) { return st.sel[x.id] && eligible(x); }); }
		function sum() { return picked().reduce(function (t, x) { var a = money(st.sel[x.id].amount); return t + (isNaN(a) ? 0 : a); }, 0); }
		function err() {
			var e = '';
			picked().forEach(function (x) { var s = st.sel[x.id], a = money(s.amount); if (!e && (isNaN(a) || a <= 0)) e = 'Enter the billable amount for “' + x.title + '”.'; else if (!e && a !== prop(x) && !s.reason.trim()) e = 'Give the reason for changing the billable amount of “' + x.title + '”.'; });
			return e;
		}
		function paint() {
			if (!st.client) { box.innerHTML = '<div class="card-b muted small">Choose a client to fetch their billable expenses.</div>'; return; }
			var projs = S.projects.filter(function (p) { return +p.client_id === st.client; });
			var rows = st.rows.filter(function (x) { return st.all || eligible(x); }), hidden = st.rows.length - rows.length, e = err(), s = sum();
			box.innerHTML = '<div class="card-b adv-head"><p class="muted small">Optional. Expenses paid for this client and marked “Billable to client” can be claimed as reimbursable lines. The expense stays in Expenses unchanged; only the client’s payment is income.</p>' +
				(projs.length ? '<label class="field adv-proj"><span>Project</span><select data-ex-proj><option value="">All projects</option>' + projs.map(function (p) { return '<option value="' + p.id + '"' + (String(p.id) === st.project ? ' selected' : '') + '>' + esc(p.name) + '</option>'; }).join('') + '</select></label>' : '') +
				'<label class="check small"><input type="checkbox" data-ex-all' + (st.all ? ' checked' : '') + '> Show not-billable and already billed' + (hidden ? ' (' + hidden + ')' : '') + '</label></div>' +
				(rows.length ? '<div class="table-wrap"><table class="tbl stack adv-tbl"><thead><tr><th style="width:36px"></th><th>Expense</th><th class="num">Expense amount</th><th class="num">Bill (₹)</th></tr></thead><tbody>' + rows.map(function (x) {
					var on = !!st.sel[x.id] && eligible(x), s = st.sel[x.id];
					var state = x.billed_in.length ? '<div class="small" style="color:var(--danger)">Already billed in ' + x.billed_in.map(invLink).join(', ') + '</div>' : (!x.billable ? '<div class="small"><span class="badge">Not marked billable</span> <button type="button" class="btn btn-sm" data-ex-mark>Review &amp; mark billable</button></div>' : '');
					return '<tr data-ex="' + x.id + '"' + (on ? '' : ' class="dim"') + '><td>' + (eligible(x) ? '<input type="checkbox" data-ex-on' + (on ? ' checked' : '') + ' aria-label="Bill this expense">' : '') + '</td>' +
						'<td data-th="Expense"><b>' + esc(x.title) + '</b><div class="small"><span class="badge">' + esc(x.category) + '</span> <span class="muted">' + esc(fmt.date(x.expense_date, true)) + (x.project ? ' · ' + esc(x.project) : '') + (x.vendor ? ' · ' + esc(x.vendor) : '') + '</span></div>' + (x.billing_remarks ? '<div class="small muted">' + esc(x.billing_remarks) + '</div>' : '') + state +
						(on && money(s.amount) !== prop(x) ? '<input data-ex-why maxlength="255" placeholder="Reason for changing the amount (required)" value="' + esc(s.reason) + '" style="margin-top:4px">' : '') + '</td>' +
						'<td data-th="Expense amount" class="num">' + inr(x.amount) + (x.billable && prop(x) !== Math.round(x.amount * 100) ? '<div class="small muted">proposed ' + inr(x.billable_amount) + '</div>' : '') + '</td>' +
						'<td data-th="Bill" class="num">' + (on ? '<input data-ex-amt inputmode="decimal" value="' + esc(s.amount) + '" style="max-width:110px;text-align:right" aria-label="Billable amount">' : '<span class="muted small">—</span>') + '</td></tr>';
				}).join('') + '</tbody></table></div>' : ui.emptyState(st.rows.length ? 'No expenses ready to bill' : 'No expenses linked to this client', st.rows.length ? 'Tick “Show not-billable and already billed” to review them.' : 'Add an expense with this client and “Billable to client” to claim it here.', I.rupee)) +
				'<div class="card-b adv-sum"><dl class="kv"><dt>Expense subtotal</dt><dd><b>' + inr(s / 100) + '</b></dd></dl>' + (e ? '<p class="small" style="color:var(--danger)" data-ex-err>' + esc(e) + '</p>' : '') + '</div>';
		}
		function load() {
			if (!st.client) { st.rows = []; paint(); return Promise.resolve(); }
			box.innerHTML = '<div class="card-b">' + ui.loading(2) + '</div>';
			return api('accounts/clients/' + st.client + '/billable-expenses', { params: { project_id: st.project, exclude_invoice: opts.exclude || '', all: 1 } }).then(function (rows) {
				st.rows = rows;
				rows.forEach(function (x) { if (pk[x.id] && !st.sel[x.id] && eligible(x)) st.sel[x.id] = { amount: (+pk[x.id].amount).toFixed(2), reason: pk[x.id].reason || '' }; });
				Object.keys(st.sel).forEach(function (k) { if (!rows.some(function (x) { return String(x.id) === k && eligible(x); })) delete st.sel[k]; });
				paint(); if (opts.onChange) opts.onChange();
			}, function (e) { box.innerHTML = ''; box.appendChild(ui.errorBox(e, load)); });
		}
		var rowOf = function (n) { var id = n.closest('[data-ex]').getAttribute('data-ex'); return st.rows.filter(function (x) { return String(x.id) === id; })[0]; };
		on(box, 'change', '[data-ex-on]', function (e, i) { var x = rowOf(i); if (i.checked) st.sel[x.id] = { amount: x.billable_amount.toFixed(2), reason: '' }; else delete st.sel[x.id]; paint(); if (opts.onChange) opts.onChange(); });
		on(box, 'input', '[data-ex-amt]', function (e, i) { st.sel[rowOf(i).id].amount = i.value; if (opts.onChange) opts.onChange(); });
		on(box, 'change', '[data-ex-amt]', function () { paint(); });
		on(box, 'input', '[data-ex-why]', function (e, i) { st.sel[rowOf(i).id].reason = i.value; });
		on(box, 'change', '[data-ex-proj]', function (e, s) { st.project = s.value; load(); });
		on(box, 'change', '[data-ex-all]', function (e, c) { st.all = c.checked; paint(); });
		on(box, 'click', '[data-ex-mark]', function (e, b) {
			var x = rowOf(b);
			WD.confirm('Mark “' + x.title + '” billable?', 'It becomes a reimbursable expense of this client with a proposed billable amount of ' + inr(x.amount) + ' (you can change the amount on this invoice with a reason). The expense amount and its place in Expenses do not change.', 'Mark billable').then(function (y) {
				if (y) api('accounts/expenses/' + x.id, { method: 'PUT', body: { billable: 1 } }).then(function () { WD.toast('Marked billable'); load(); }, WD.toastError);
			});
		});
		paint();
		return {
			setClient: function (id) { st.client = +id || 0; st.sel = {}; st.project = ''; return load(); },
			total: sum,
			error: function () { paint(); return err(); },
			value: function () { return picked().map(function (x) { var s = st.sel[x.id]; return { expense_id: x.id, amount: s.amount, adjust_reason: money(s.amount) !== prop(x) ? s.reason : '' }; }); }
		};
	}

	/**
	 * Previous outstanding invoices of a client: shown on the new invoice as a summary (no new
	 * revenue), or — separately and explicitly — consolidated: the balance moves to the new
	 * invoice and the old one becomes Consolidated, so it is never counted twice.
	 * opts: { exclude, preset: [invoice ids], onChange() }
	 */
	function prevPicker(box, opts) {
		var st = { client: 0, rows: [], show: {}, cons: {}, reason: '' };
		(opts.preset || []).forEach(function (id) { st.show[id] = true; });
		function bal(x) { return Math.round(x.balance * 100); }
		function shown() { return st.rows.filter(function (x) { return st.show[x.id] && !st.cons[x.id]; }); }
		function consd() { return st.rows.filter(function (x) { return st.cons[x.id]; }); }
		function paint() {
			if (!st.client) { box.innerHTML = '<div class="card-b muted small">Choose a client to fetch their unpaid, partially paid and overdue invoices.</div>'; return; }
			var p = shown().reduce(function (t, x) { return t + bal(x); }, 0), c = consd().reduce(function (t, x) { return t + bal(x); }, 0);
			box.innerHTML = '<div class="card-b adv-head"><p class="muted small">Optional. Ticked invoices are listed on this invoice and its PDF as the previous outstanding balance — for information only: they are not added to this invoice’s total and keep their own payments. Balances come from recorded payments and adjustments.</p></div>' +
				(st.rows.length ? '<div class="table-wrap"><table class="tbl stack adv-tbl"><thead><tr><th style="width:36px"></th><th>Invoice</th><th class="num">Amount</th><th class="num">Received</th><th class="num">Outstanding</th><th>Consolidate</th></tr></thead><tbody>' + st.rows.map(function (x) {
					var on = st.show[x.id] || st.cons[x.id];
					return '<tr data-pv="' + x.id + '"' + (on ? '' : ' class="dim"') + '><td><input type="checkbox" data-pv-on' + (st.show[x.id] && !st.cons[x.id] ? ' checked' : '') + (st.cons[x.id] ? ' disabled' : '') + ' aria-label="Show in previous balance"></td>' +
						'<td data-th="Invoice"><a href="#/accounts/invoices/' + x.id + '" target="_blank" rel="noopener"><b>' + esc(x.invoice_no) + '</b></a> ' + invBadge(x) + '<div class="small muted">Dated ' + esc(fmt.date(x.invoice_date, true)) + ' · due ' + esc(fmt.date(x.due_date, true)) + '</div></td>' +
						'<td data-th="Amount" class="num">' + inr(x.total) + '</td><td data-th="Received" class="num">' + inr(x.amount_received) + (x.amount_adjusted ? '<div class="small muted">+ ' + inr(x.amount_adjusted) + ' adjusted</div>' : '') + '</td><td data-th="Outstanding" class="num"><b>' + inr(x.balance) + '</b></td>' +
						'<td data-th="Consolidate"><label class="check small"><input type="checkbox" data-pv-cons' + (st.cons[x.id] ? ' checked' : '') + '> Move balance here</label></td></tr>';
				}).join('') + '</tbody></table></div>' : ui.emptyState('No outstanding invoices', 'This client has no unpaid or partially paid invoices.', I.check)) +
				(c ? '<div class="card-b"><div class="alert alert-warn small"><b>Consolidation (' + inr(c / 100) + ')</b>: when you generate this invoice, the remaining balance of ' + esc(consd().map(function (x) { return x.invoice_no; }).join(', ')) + ' is added here as “Balance brought forward” (outside tax) and ' + (consd().length > 1 ? 'those invoices become' : 'that invoice becomes') + ' <b>Consolidated</b> — settled by transfer, keeping their number, payments and history. Cancelling this invoice later restores them. This happens in one step; nothing is counted twice.</div>' +
					'<label class="field"><span>Reason for consolidating <span class="req">*</span></span><textarea data-pv-why rows="2" placeholder="e.g. Client asked for a single statement">' + esc(st.reason) + '</textarea></label></div>' : '') +
				'<div class="card-b adv-sum"><dl class="kv"><dt>Previous outstanding shown</dt><dd><b>' + inr(p / 100) + '</b></dd>' + (c ? '<dt>Brought forward (consolidated)</dt><dd>' + inr(c / 100) + '</dd>' : '') + '</dl></div>';
		}
		function load() {
			if (!st.client) { st.rows = []; paint(); return Promise.resolve(); }
			box.innerHTML = '<div class="card-b">' + ui.loading(2) + '</div>';
			return api('accounts/clients/' + st.client + '/outstanding', { params: { exclude_invoice: opts.exclude || '' } }).then(function (rows) {
				st.rows = rows; paint(); if (opts.onChange) opts.onChange();
			}, function (e) { box.innerHTML = ''; box.appendChild(ui.errorBox(e, load)); });
		}
		var idOf = function (n) { return n.closest('[data-pv]').getAttribute('data-pv'); };
		on(box, 'change', '[data-pv-on]', function (e, i) { if (i.checked) st.show[idOf(i)] = true; else delete st.show[idOf(i)]; paint(); if (opts.onChange) opts.onChange(); });
		on(box, 'change', '[data-pv-cons]', function (e, i) { if (i.checked) st.cons[idOf(i)] = true; else delete st.cons[idOf(i)]; paint(); if (opts.onChange) opts.onChange(); });
		on(box, 'input', '[data-pv-why]', function (e, t) { st.reason = t.value; });
		paint();
		return {
			setClient: function (id) { if (st.client && st.client !== +id) { st.show = {}; } st.client = +id || 0; st.cons = {}; st.reason = ''; return load(); },
			previous: function () { return shown().reduce(function (t, x) { return t + bal(x); }, 0); },
			carried: function () { return consd().reduce(function (t, x) { return t + bal(x); }, 0); },
			error: function () { return consd().length && !st.reason.trim() ? 'Give the reason for consolidating.' : ''; },
			value: function () { return { previous: shown().map(function (x) { return x.id; }), consolidate: consd().map(function (x) { return x.id; }), consolidate_reason: st.reason.trim() }; }
		};
	}

	var TABS = [['overview', 'Overview'], ['invoices', 'Invoices'], ['income', 'Income'], ['expenses', 'Expenses'], ['passbook', 'Passbook'], ['reports', 'Financial reports'], ['packages', 'Packages & services'], ['settings', 'Settings']];

	/* ------------------------------------------------------------------ *
	 * Shell with tabs
	 * ------------------------------------------------------------------ */
	V.accounts = function (c) {
		if (!can('ndwd_manage_accounts')) {
			c.innerHTML = ui.emptyState('Accounts is restricted', 'Only Admins and people given Accounts access can see financial records.', I.rupee);
			return Promise.resolve();
		}
		var tab = (S.routeParams && S.routeParams[1]) || 'overview';
		WD.setTitle('Accounts', 'Invoices, income, expenses, packages and the passbook — amounts in INR');
		c.innerHTML = '<nav class="tabs acc-tabs" aria-label="Accounts sections">' + TABS.map(function (t) {
			return '<a href="#/accounts/' + t[0] + '" class="' + (t[0] === tab ? 'on' : '') + '">' + esc(t[1]) + '</a>';
		}).join('') + '</nav><div data-tab>' + ui.loading(6) + '</div>';
		var body = c.querySelector('[data-tab]');
		return meta().then(function () { return TAB[tab](body); });
	};
	var TAB = {};

	/* ------------------------------------------------------------------ *
	 * Overview
	 * ------------------------------------------------------------------ */
	A.fy = null;
	TAB.overview = function (b) {
		return api('accounts/overview', { params: { fy: A.fy || '' } }).then(function (o) {
			A.fy = o.fy;
			var k = o.kpis;
			var max = Math.max.apply(null, [1].concat(o.months.map(function (m) { return Math.max(m.invoiced, m.income, m.expenses); })));
			var warn = o.retention.filter(function (r) { return !r.confirmed_at && (r.stage === 'extra_month' || r.stage === 'grace' || (r.stage === 'current' && r.days_left <= 30) || r.stage === 'eligible'); });
			b.innerHTML =
				'<div class="toolbar"><label class="field" style="margin:0"><span>Financial year</span><select data-fy style="width:auto">' + fyOptions(o.fy_list, o.fy) + '</select></label><span class="muted small" style="align-self:flex-end">' + esc(fmt.date(o.from, true)) + ' – ' + esc(fmt.date(o.to, true)) + '</span><span class="spacer"></span><a class="btn btn-primary" href="#/accounts/invoices/new" style="align-self:flex-end">' + I.plus + ' Create invoice</a></div>' +
				(o.missing_details.length ? '<div class="alert alert-warn">Invoices will not show: ' + esc(o.missing_details.join(', ')) + '. Add them in <a href="#/accounts/settings">Accounts → Settings</a> — nothing is filled in automatically.</div>' : '') +
				warn.map(function (r) { return '<div class="alert alert-warn">FY ' + esc(r.fy) + ': ' + r.invoices + ' invoice(s). Retention deadline ' + esc(fmt.date(r.deadline, true)) + ' (grace until ' + esc(fmt.date(r.grace_until, true)) + '). <a href="#/accounts/reports">Download the financial-year archive</a> and confirm it.</div>'; }).join('') +
				'<div class="stats acc-kpis">' +
				kpi('Total invoiced', inr(k.invoiced), k.invoices + ' invoice(s) issued' + (k.invoiced_expenses ? ' · ' + inr(k.invoiced_expenses) + ' reimbursable expenses' : ''), 'var(--chart-inv)') +
				kpi('Received from invoices', inr(k.received), k.received_allocated ? inr(k.received_payments) + ' paid on invoices · ' + inr(k.received_allocated) + ' advances adjusted' : 'Payments received against invoices', 'var(--st-done)') +
				kpi('Other income', inr(k.other_income), 'Manual income not adjusted on invoices (incl. available advances)', 'var(--brand-2)') +
				kpi('Total income', inr(k.total_income), 'Received from invoices + other income', 'var(--st-done)') +
				kpi('Outstanding', inr(k.outstanding), k.outstanding_count + ' unpaid / partially paid invoice' + (k.outstanding_count === 1 ? '' : 's'), 'var(--st-pending)') +
				kpi('Overdue', { html: '<b>' + k.overdue_count + '</b> <span class="v-unit">Overdue Invoice' + (k.overdue_count === 1 ? '' : 's') + '</span>' }, inr(k.overdue) + ' overdue (past due date, balance pending)', 'var(--danger)', 'count') +
				fundsKpi(o.funds) +
				kpi('Total expenses', inr(k.expenses), inr(k.expenses_paid) + ' paid · ' + inr(k.expenses_unpaid) + ' unpaid' + (k.billable_unbilled ? ' · ' + inr(k.billable_unbilled) + ' billable, not yet invoiced' : ''), 'var(--chart-exp)') +
				kpi('Net', inr(k.net), 'Total income − expenses paid', k.net < 0 ? 'var(--danger)' : 'var(--brand-3)') +
				'</div>' +
				'<p class="page-note">“Invoiced” is what you billed. “Received from invoices” counts payments recorded on invoices plus advances (income already received) adjusted against invoices — an invoice is never treated as money received just because it was generated. Allocating an advance only moves it from “Other income” to “Received from invoices”: total income does not change and nothing is counted twice. “Current Balance” is the passbook balance (money actually received − money actually paid), not profit.</p>' +
				'<div class="grid grid-main"><section class="card"><div class="card-h"><h2>Monthly income and expenses</h2><span class="spacer"></span><span class="legend"><i style="background:var(--chart-inv)"></i>Invoiced <i style="background:var(--chart-inc)"></i>Income <i style="background:var(--chart-exp)"></i>Expenses</span></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Month</th><th class="num">Invoiced</th><th class="num">Received</th><th class="num">Other income</th><th class="num">Expenses</th><th style="width:28%"></th></tr></thead><tbody>' +
				o.months.map(function (m) {
					return '<tr><td>' + esc(m.label) + '</td><td class="num">' + inr(m.invoiced) + '</td><td class="num">' + inr(m.received) + '</td><td class="num">' + inr(m.other_income) + '</td><td class="num">' + inr(m.expenses) + '</td><td><div class="mbars"><i title="Invoiced ' + esc(inr(m.invoiced)) + '" style="width:' + (m.invoiced / max * 100) + '%;background:var(--chart-inv)"></i><i title="Income ' + esc(inr(m.income)) + '" style="width:' + (m.income / max * 100) + '%;background:var(--chart-inc)"></i><i title="Expenses ' + esc(inr(m.expenses)) + '" style="width:' + (m.expenses / max * 100) + '%;background:var(--chart-exp)"></i></div></td></tr>';
				}).join('') + '</tbody></table></div></section>' +
				'<div class="grid" style="align-content:start"><section class="card"><div class="card-h"><h2>Recent invoices</h2><span class="spacer"></span><a class="btn btn-sm btn-ghost" href="#/accounts/invoices">All</a></div><div class="card-b flush">' + invoiceList(o.recent_invoices, true) + '</div></section>' +
				'<section class="card"><div class="card-h"><h2>Recent transactions</h2><span class="spacer"></span><a class="btn btn-sm btn-ghost" href="#/accounts/passbook">Passbook</a></div><div class="card-b flush">' +
				(o.recent_transactions.length ? '<ul class="timeline" style="padding:0 16px">' + o.recent_transactions.map(function (r) {
					return '<li><div class="body"><b>' + esc(r.type_label) + '</b> · ' + esc(r.party || '—') + '<div class="small muted">' + esc(fmt.date(r.date, true)) + (r.method_label ? ' · ' + esc(r.method_label) : '') + '</div></div><span class="' + (r.in ? 'amt-in' : 'amt-out') + '">' + (r.in ? '+' + inr(r.in) : '−' + inr(r.out)) + '</span></li>';
				}).join('') + '</ul>' : ui.emptyState('No money movements yet', 'Record payments on invoices and add expenses to see them here.', I.rupee)) + '</div></section></div></div>';
			b.querySelector('[data-fy]').onchange = function (e) { A.fy = e.target.value; TAB.overview(b); };
		});
	};
	function kpi(label, value, sub, tone, cls) {
		return '<div class="stat' + (cls ? ' kpi-' + cls : '') + '" style="--tone:' + tone + '"><div class="k">' + esc(label) + '</div><div class="v">' + (value && value.html ? value.html : esc(value)) + '</div><div class="muted small">' + esc(sub) + '</div></div>';
	}
	/** Cash / bank position from the passbook (not profit): opening balance + received − paid, as of today. */
	function fundsKpi(fd) {
		return '<div class="stat kpi-funds" style="--tone:' + (fd.balance < 0 ? 'var(--danger)' : 'var(--brand-3)') + '"><div class="k">Current Balance</div><div class="v">' + esc(inr(fd.balance)) + '</div></div>';
	}
	var INV_COLS = { no: [function (v) { return v.invoice_no; }, 'text'], client: [function (v) { return v.client_name; }, 'text'], date: [function (v) { return v.invoice_date; }, 'date'], due: [function (v) { return v.due_date; }, 'date'], total: [function (v) { return v.total; }, 'num'], paid: [function (v) { return v.amount_paid; }, 'num'], balance: [function (v) { return v.balance; }, 'num'], status: [function (v) { return v.is_overdue ? 'Overdue' : v.status_label; }, 'text'] };
	function invoiceList(list, compact, st) {
		if (!list.length) return st ? ui.emptyState('No invoices match', 'Change or clear the search and filters.', I.file) : ui.emptyState('No invoices yet', 'Create the first invoice from a client’s completed work.', I.file);
		var h = function (k, l, c) { return st ? sortTh(st, k, l, c) : '<th' + (c ? ' class="' + c + '"' : '') + '>' + esc(l) + '</th>'; };
		if (st) list = sortRows(list, st, INV_COLS);
		return '<div class="table-wrap"><table class="tbl stack"><thead><tr>' + h('no', 'Invoice') + h('client', 'Client') + (compact ? '' : h('date', 'Date') + h('due', 'Due')) + h('total', 'Total', 'num') + (compact ? '' : h('paid', 'Paid', 'num') + h('balance', 'Balance', 'num')) + h('status', 'Status') + '</tr></thead><tbody>' + list.map(function (v) {
			return '<tr class="click" data-inv="' + v.id + '"><td data-th="Invoice"><a href="#/accounts/invoices/' + v.id + '"><b>' + esc(v.invoice_no) + '</b></a>' + (v.replaced_by ? '<div class="muted small">Replaced by ' + esc(v.replaced_by.invoice_no) + '</div>' : v.replaces ? '<div class="muted small">Replaces ' + esc(v.replaces.invoice_no) + '</div>' : '') + '</td><td data-th="Client">' + esc(v.client_name) + '</td>' + (compact ? '' : '<td data-th="Date">' + esc(fmt.date(v.invoice_date, true)) + '</td><td data-th="Due">' + esc(fmt.date(v.due_date, true)) + '</td>') + '<td data-th="Total" class="num">' + inr(v.total) + '</td>' + (compact ? '' : '<td data-th="Paid" class="num">' + inr(v.amount_paid) + '</td><td data-th="Balance" class="num">' + inr(v.balance) + '</td>') + '<td data-th="Status">' + invBadge(v) + '</td></tr>';
		}).join('') + '</tbody></table></div>';
	}
	document.addEventListener('click', function (e) {
		var r = e.target.closest('tr[data-inv]');
		if (r && !e.target.closest('a,button')) WD.go('/accounts/invoices/' + r.getAttribute('data-inv'));
	});

	/* ------------------------------------------------------------------ *
	 * Invoices list
	 * ------------------------------------------------------------------ */
	A.invf = { status: '', fy: '', q: '', sort: 'date', dir: 'desc' };
	TAB.invoices = function (b) {
		var f = A.invf;
		b.innerHTML = '<div class="toolbar acc-search">' + searchBox('data-q', f.q, 'Invoice no., client, project or status') + '<select data-st style="width:auto"><option value="">All statuses</option>' + WD.mapOptions(A.meta.statuses, f.status) + '<option value="overdue"' + (f.status === 'overdue' ? ' selected' : '') + '>Overdue</option></select><select data-fy style="width:auto"><option value="">All years</option>' + fyOptions(A.meta.fy_list, f.fy) + '</select><select data-client style="width:auto">' + WD.selectOptions(S.clients, f.client_id, 'All clients') + '</select><span class="spacer"></span><button class="btn" data-recv>' + I.rupee + ' Record client payment</button><a class="btn btn-primary" href="#/accounts/invoices/new">' + I.plus + ' Create invoice</a></div><section class="card"><div data-list>' + ui.loading(5) + '</div></section>';
		b.querySelector('[data-recv]').onclick = function () { receivePayment({ client_id: f.client_id || '' }, load); };
		var list = b.querySelector('[data-list]'), rows = [];
		var paint = function () { list.innerHTML = invoiceList(rows, false, f); };
		function load() {
			return api('accounts/invoices', { params: { status: f.status, fy: f.fy, q: f.q, client_id: f.client_id } }).then(function (r) {
				rows = r; paint();
			}, function (e) { list.innerHTML = ''; list.appendChild(ui.errorBox(e, load)); });
		}
		bindSort(list, f, INV_COLS, paint);
		bindSearch(b, '[data-q]', function (v) { f.q = v; load(); });
		b.querySelector('[data-st]').onchange = function (e) { f.status = e.target.value; load(); };
		b.querySelector('[data-fy]').onchange = function (e) { f.fy = e.target.value; load(); };
		b.querySelector('[data-client]').onchange = function (e) { f.client_id = e.target.value; load(); };
		return load();
	};

	/* ------------------------------------------------------------------ *
	 * Create invoice
	 * ------------------------------------------------------------------ */
	V.invoiceNew = function (c) {
		if (!can('ndwd_manage_accounts')) { c.innerHTML = ui.emptyState('Accounts is restricted', '', I.rupee); return Promise.resolve(); }
		WD.setTitle('Create invoice', 'Client → package → work log → review → PDF');
		c.innerHTML = ui.loading(6);
		var pre = hashParams();
		var repId = +pre.replace || 0;
		return Promise.all([meta(), api('accounts/packages'), api('clients'), repId ? api('accounts/invoices/' + repId) : null]).then(function (r) {
			var m = r[0], orig = r[3];
			if (orig && orig.status === 'cancelled') { c.innerHTML = ui.emptyState('Invoice already cancelled', orig.invoice_no + ' is cancelled' + (orig.replaced_by ? ' and replaced by ' + orig.replaced_by.invoice_no : '') + '.', I.file); return; }
			var packages = r[1].filter(function (p) { return p.is_active || (orig && p.id === orig.package_id); }), clients = r[2].filter(function (x) { return x.is_active || (orig && x.id === orig.client_id); });
			var st = { client: null, work: [], items: {}, custom: [], allocs: [], recip: null, pkgs: [], groups: [] };
			var draft = orig && S.replaceDraft && S.replaceDraft.id === orig.id ? S.replaceDraft : { reason: '', ack: false };
			if (orig) { pre.client = orig.client_id; WD.setTitle('Replace invoice ' + orig.invoice_no, 'Corrected copy — the original is cancelled only when you generate this one'); }
			var bl = orig ? orig.client || {} : {};
			c.innerHTML = '<div class="toolbar"><a class="btn btn-sm btn-ghost" href="' + (orig ? '#/accounts/invoices/' + orig.id : '#/accounts/invoices') + '">' + I.left + ' ' + (orig ? esc(orig.invoice_no) : 'Invoices') + '</a></div>' +
				'<form novalidate data-form><div class="alert alert-error hidden" data-err role="alert"></div>' +
				(orig ? '<section class="card"><div class="card-h"><h2>Replacing ' + esc(orig.invoice_no) + '</h2><span class="spacer"></span>' + invBadge(orig) + '</div><div class="card-b"><p class="small">Everything below is copied from ' + esc(orig.invoice_no) + ' (' + inr(orig.total) + '). Correct what is wrong, then Preview and Generate. Only then is ' + esc(orig.invoice_no) + ' marked <b>Cancelled — replaced by</b> the new number, in one step. Leaving this page changes nothing.</p>' +
					'<label class="field"><span>Reason for cancelling ' + esc(orig.invoice_no) + ' <span class="req">*</span></span><textarea name="cancel_reason" rows="2" placeholder="e.g. Wrong package amount">' + esc(draft.reason) + '</textarea></label>' +
					(orig.amount_adjusted > 0 ? '<div class="alert alert-info small">Advances adjusted on ' + esc(orig.invoice_no) + ' (' + inr(orig.amount_adjusted) + ') are pre-selected in step 7. They move to the replacement only when you generate it.</div>' : '') +
					(orig.amount_received > 0 ? '<div class="alert alert-warn small">' + esc(orig.invoice_no) + ' has payments of ' + inr(orig.amount_received) + '. They stay recorded on it (not refunded, not copied). After generating, you can move them to the new invoice or record a refund.</div><label class="check"><input type="checkbox" name="ack"' + (draft.ack ? ' checked' : '') + '> I understand the payments stay on ' + esc(orig.invoice_no) + '</label>' : '') +
					'</div></section><section class="card section-gap"><div class="card-h"><h2>Billing details on the new invoice</h2></div><div class="card-b"><div class="form-grid">' +
					'<label class="field"><span>Billing name <span class="req">*</span></span><input name="b_name" maxlength="160" value="' + esc(bl.name || '') + '"></label><label class="field"><span>Attention / contact</span><input name="b_contact" maxlength="160" value="' + esc(bl.contact || '') + '"></label>' +
					'<label class="field"><span>Billing WhatsApp</span><input name="b_whatsapp" maxlength="40" value="' + esc(bl.whatsapp || '') + '"></label><label class="field"><span>Client GSTIN</span><input name="b_gstin" maxlength="15" value="' + esc(bl.gstin || '') + '"></label>' +
					'<label class="field span-2"><span>Billing address</span><textarea name="b_address" rows="2">' + esc(bl.address || '') + '</textarea></label></div></div></section><div class="section-gap"></div>' : '') +
				'<section class="card"><div class="card-h"><h2>1 · Client</h2></div><div class="card-b"><div class="form-grid">' +
				'<label class="field"><span>Client <span class="req">*</span></span><select name="client_id">' + WD.selectOptions(clients, pre.client || '', 'Choose a client…') + '</select></label>' +
				'<div class="field"><span>Billing details</span><div data-bill class="bill-box muted small">Choose a client to load the saved billing details.</div></div>' +
				'<label class="field"><span>Invoice date</span><input type="date" name="invoice_date" value="' + esc(S.today) + '"></label>' +
				'<label class="field"><span>Due date</span><input type="date" name="due_date"></label>' +
				'<div class="field span-2"><span>Send this invoice to <span class="hint">— loaded from the client’s billing emails; changes here apply to this invoice only</span></span><div data-recip><span class="muted small">Choose a client to load the billing emails.</span></div></div>' +
				'</div></div></section>' +
				'<section class="card section-gap"><div class="card-h"><h2>2 · Packages</h2><span class="hint muted small">optional — one or more</span><span class="spacer"></span><select data-pkg-add style="width:auto;max-width:280px" aria-label="Add a package"></select><button type="button" class="btn btn-sm" data-pkg-custom>' + I.plus + ' Custom package</button></div><div class="card-b" data-pkgs></div></section>' +
				'<section class="card section-gap"><div class="card-h"><h2>3 · Work logs</h2><label class="check small hidden"><input type="checkbox" data-all> <span data-all-lbl>Select all</span></label><span class="muted small" data-work-count></span><span class="spacer"></span><label class="check small"><input type="checkbox" data-show-billed> Show already billed</label></div><div class="card-b wl-groups" data-groups></div><div class="card-b flush" data-work><div class="card-b muted small">Choose a client to fetch their completed work.</div></div></section>' +
				'<section class="card section-gap"><div class="card-h"><h2>4 · Other line items</h2><span class="spacer"></span><button type="button" class="btn btn-sm" data-add-line>' + I.plus + ' Add line</button></div><div class="card-b" data-lines><p class="muted small">Items not linked to a task, e.g. “Additional website banner”.</p></div></section>' +
				'<section class="card section-gap"><div class="card-h"><h2>5 · Billable expenses</h2><span class="hint muted small">optional</span></div><div class="card-b flush" data-exp-box></div></section>' +
				'<section class="card section-gap"><div class="card-h"><h2>6 · Previous outstanding invoices</h2><span class="hint muted small">optional</span></div><div class="card-b flush" data-prev-box></div></section>' +
				'<section class="card section-gap"><div class="card-h"><h2>7 · Available income / advance payments</h2><span class="hint muted small">optional</span></div><div class="card-b flush" data-adv-box></div></section>' +
				'<section class="card section-gap"><div class="card-h"><h2>8 · Totals and notes</h2></div><div class="card-b"><div class="form-grid">' +
				'<label class="field"><span>Discount (₹)</span><input name="discount" inputmode="decimal" placeholder="0.00"></label>' +
				'<label class="field"><span>Discount name / description <span class="hint">(optional, printed on the invoice)</span></span><input name="discount_label" maxlength="160" placeholder="e.g. Festive offer"></label>' +
				'<div class="form-grid" style="gap:8px"><label class="field"><span>Tax label</span><input name="tax_label" value="' + esc(m.settings.tax_label) + '" maxlength="40"></label><label class="field"><span>Tax rate (%)</span><input name="tax_rate" inputmode="decimal" value="' + esc(m.settings.tax_rate || '') + '" placeholder="0"></label></div>' +
				'<label class="field span-2"><span>Work log remarks <span class="hint">(printed on the invoice after the work items)</span></span><textarea name="work_remarks" rows="2" placeholder="e.g. October deliverables: 5 videos, 6 carousels; website banner billed separately"></textarea></label>' +
				'<label class="field span-2"><span>Payment instructions</span><textarea name="payment_instructions" rows="2"></textarea></label>' +
				'<label class="field span-2"><span>Notes</span><textarea name="notes" rows="2"></textarea></label>' +
				'</div><div class="totals" data-totals></div></div></section>' +
				'<div class="toolbar section-gap"><span class="spacer"></span><button type="button" class="btn btn-primary" data-preview>' + I.file + (orig ? ' Preview replacement' : ' Preview invoice') + '</button></div></form>';
			var form = c.querySelector('[data-form]');
			form.payment_instructions.value = m.settings.payment_instructions || '';
			if (orig) {
				form.client_id.disabled = true;
				orig.items.filter(function (i) { return i.treatment === 'package'; }).forEach(function (i) {
					st.pkgs.push({ name: i.description, amount: i.amount.toFixed(2), terms: i.terms || '', client_package_id: i.client_package_id || orig.client_package_id || '', package_id: i.package_id || orig.package_id || '', package_type: i.package_type || (i.billing_month ? 'monthly' : ''), billing_month: i.billing_month || '', period_start: i.period_start || '', period_end: i.period_end || '' });
				});
				if ((orig.groups || []).length) st.groups = orig.groups.map(function (g) { return { heading: g.heading, remarks: g.remarks || '' }; });
				form.discount_label.value = orig.discount_label || '';
				form.discount.value = orig.discount ? orig.discount.toFixed(2) : '';
				form.tax_label.value = orig.tax_label || m.settings.tax_label || '';
				form.tax_rate.value = orig.tax_rate || '';
				form.payment_instructions.value = orig.payment_instructions || '';
				form.notes.value = orig.notes || '';
				form.work_remarks.value = orig.work_remarks || '';
				form.due_date.value = '';
				orig.items.forEach(function (i) { if (!i.task_id && (i.treatment === 'custom' || i.treatment === 'included')) st.custom.push({ description: i.description, treatment: i.treatment, amount: i.treatment === 'custom' ? i.amount.toFixed(2) : '' }); });
				form.cancel_reason.oninput = function () { S.replaceDraft = { id: orig.id, reason: form.cancel_reason.value, ack: form.ack ? form.ack.checked : false }; };
			}
			var work = c.querySelector('[data-work]'), lines = c.querySelector('[data-lines]');
			var hasPkg = function () { return st.pkgs.length > 0; };
			var grand = 0, preset = {};
			if (orig) (orig.allocations || []).forEach(function (a) { if (!a.released_at) preset[a.income_id] = (preset[a.income_id] || 0) + a.amount; });
			var adv = advancePicker(c.querySelector('[data-adv-box]'), { exclude: orig ? orig.id : 0, preset: preset, payable: function () { return grand; }, onChange: function () { totals(true); } });
			var expPreset = {};
			if (orig) orig.items.forEach(function (i) { if (i.expense_id) expPreset[i.expense_id] = { amount: i.amount, reason: i.override_reason || '' }; });
			var exp = expensePicker(c.querySelector('[data-exp-box]'), { exclude: orig ? orig.id : 0, preset: expPreset, onChange: function () { totals(); } });
			var prv = prevPicker(c.querySelector('[data-prev-box]'), { exclude: orig ? orig.id : 0, preset: orig && orig.account_summary ? orig.account_summary.invoices.map(function (x) { return x.id; }) : [], onChange: function () { totals(); } });

			function totals(fromAdv) {
				var sub = 0, bad = false, needPkg = false;
				st.pkgs.forEach(function (p) { sub += money(p.amount) || 0; });
				Object.keys(st.items).forEach(function (id) { var it = st.items[id]; if (!it.on) return; if (it.treatment === 'custom') { var a = money(it.amount); if (isNaN(a) || a <= 0) bad = true; else sub += a; } else needPkg = true; });
				st.custom.forEach(function (l) { if (l.treatment === 'included') { if (l.description.trim()) needPkg = true; return; } var a = money(l.amount); if (!isNaN(a) && a > 0) sub += a; else if (l.description.trim()) bad = true; });
				var ex = exp.total(), carried = prv.carried(), prev = prv.previous();
				sub += ex;
				var disc = money(form.discount.value) || 0, rate = parseFloat(form.tax_rate.value) || 0;
				var tax = Math.round((sub - disc) * rate / 100), total = sub - disc + tax + carried;
				// Count and master checkbox follow the rows on screen; "Select all" covers only tasks not billed yet.
				var rows = shown(), isOn = function (t) { return st.items[t.id].on; }, elig = rows.filter(function (t) { return !t.billed_in.length; });
				var n = rows.filter(isOn).length, full = elig.length > 0 && elig.every(isOn), all = c.querySelector('[data-all]');
				c.querySelector('[data-work-count]').textContent = rows.length ? n + ' of ' + rows.length + ' selected' : '';
				all.closest('label').classList.toggle('hidden', !rows.length);
				all.checked = full;
				all.indeterminate = !full && n > 0;
				all.disabled = !elig.length && !n;
				c.querySelector('[data-all-lbl]').textContent = full ? 'Unselect all' : 'Select all';
				grand = total;
				if (fromAdv !== true) adv.refresh();
				var ad = adv.total();
				var cb = Math.max(0, total - ad), credit = adv.credit();
				c.querySelector('[data-totals]').innerHTML = '<dl class="kv">' + (ex ? '<dt>Services &amp; package</dt><dd>' + inr((sub - ex) / 100) + '</dd><dt>Reimbursable expenses</dt><dd>' + inr(ex / 100) + '</dd>' : '') + '<dt>Subtotal</dt><dd>' + inr(sub / 100) + '</dd>' + (disc ? '<dt>Discount</dt><dd>− ' + inr(disc / 100) + '</dd>' : '') + (rate ? '<dt>' + esc(form.tax_label.value || 'Tax') + ' @ ' + rate + '%</dt><dd>' + inr(tax / 100) + '</dd>' : '') +
					(carried ? '<dt>Current invoice total</dt><dd>' + inr((total - carried) / 100) + '</dd><dt>Balance brought forward</dt><dd>' + inr(carried / 100) + '</dd>' : '') +
					'<dt><b>Grand total</b></dt><dd><b>' + inr(total / 100) + '</b></dd>' + (ad ? '<dt>Advance adjusted</dt><dd>− ' + inr(ad / 100) + '</dd><dt><b>Current invoice balance</b></dt><dd><b>' + inr(cb / 100) + '</b></dd>' : '') + '</dl>' +
					(prev ? '<dl class="kv acc-summary"><dt class="label">Account summary (not part of this invoice)</dt><dt>Current invoice balance</dt><dd>' + inr(cb / 100) + '</dd><dt>Previous outstanding balance</dt><dd>' + inr(prev / 100) + '</dd>' + (credit ? '<dt>Available advance credit</dt><dd>− ' + inr(credit / 100) + '</dd>' : '') + '<dt><b>Total account balance payable</b></dt><dd><b>' + inr(Math.max(0, cb + prev - credit) / 100) + '</b></dd></dl>' : '') +
					(bad ? '<p class="small" style="color:var(--danger)">Enter an amount for every “Custom amount” item.</p>' : '') + (needPkg && !hasPkg() ? '<p class="small" style="color:var(--danger)">Items are marked “Included in package”, but no package is selected in step 2.</p>' : '');
			}
			['discount', 'tax_rate', 'tax_label'].forEach(function (n) { form[n].addEventListener('input', totals); });
			/* Packages: each with its own name, price, terms and service period. Copied into the invoice. */
			var pkgBox = c.querySelector('[data-pkgs]'), pkgAdd = c.querySelector('[data-pkg-add]');
			var monthNow = function () { return (form.invoice_date.value || S.today).slice(0, 7); };
			function paintPkgAdd() {
				var al = st.allocs.filter(function (a) { return ['cancelled', 'archived', 'inactive'].indexOf(a.effective_status) < 0; });
				pkgAdd.innerHTML = '<option value="">Add a package…</option>' +
					(al.length ? '<optgroup label="Assigned to this client’s projects">' + al.map(function (a) { return '<option value="a:' + a.id + '">' + esc((a.project_name ? a.project_name + ' · ' : '') + a.name + ' — ' + inr(a.price || 0)) + '</option>'; }).join('') + '</optgroup>' : '') +
					(packages.length ? '<optgroup label="Reusable templates (unassigned)">' + packages.map(function (p) { return '<option value="t:' + p.id + '">' + esc(p.name + ' — ' + inr(p.amount) + (p.billing_period ? ' / ' + m.periods[p.billing_period] : '')) + '</option>'; }).join('') + '</optgroup>' : '');
				if (WD.refreshDropdown) WD.refreshDropdown(pkgAdd);
			}
			function paintPkgs() {
				pkgBox.innerHTML = st.pkgs.length ? st.pkgs.map(function (p, i) {
					var a = p.client_package_id ? st.allocs.filter(function (x) { return x.id === +p.client_package_id; })[0] : null, mo = p.package_type === 'monthly';
					var adv = mo && p.billing_month && p.billing_month > monthNow();
					return '<div class="inv-pkg" data-p="' + i + '"><div class="form-grid">' +
						'<label class="field"><span>Package name on invoice <span class="req">*</span></span><input data-pf="name" maxlength="160" value="' + esc(p.name) + '"></label>' +
						'<label class="field"><span>Price (₹)</span><input data-pf="amount" inputmode="decimal" placeholder="0.00" value="' + esc(p.amount) + '"></label>' +
						(!p.client_package_id && !p.package_id ? '<label class="field"><span>Type</span><select data-pf="package_type">' + WD.mapOptions({ monthly: 'Monthly', fixed: 'Fixed duration', one_time: 'One-time' }, p.package_type || 'one_time') + '</select></label>' : '') +
						(mo ? '<label class="field"><span>Billing month ' + (adv ? '<span class="badge s-pending">Advance</span>' : '') + '</span><input type="month" data-pf="billing_month" value="' + esc(p.billing_month || monthNow()) + '"></label>' :
							'<label class="field"><span>Service period from <span class="hint">(optional)</span></span><input type="date" data-pf="period_start" value="' + esc(p.period_start || '') + '"></label><label class="field"><span>to</span><input type="date" data-pf="period_end" value="' + esc(p.period_end || '') + '"></label>') +
						'<label class="field span-2"><span>Terms <span class="hint">(optional, printed under this package)</span></span><textarea data-pf="terms" rows="1" maxlength="2000">' + esc(p.terms || '') + '</textarea></label></div>' +
						'<div class="inv-pkg-foot small muted"><span>' + (a ? esc(a.type_label + (a.project_name ? ' · ' + a.project_name : '') + (a.cycle ? ' · current cycle ' + a.cycle.label : '')) + (a.items.length ? ' · ' + a.items.map(function (it) { return esc(it.service_name) + ' ' + (it.consumed + it.reserved) + '/' + (it.allocated + it.adjusted) + ' used'; }).join(' · ') : '') : (p.package_id ? 'From template' : 'Custom package')) + '</span><button type="button" class="btn btn-ghost btn-sm" data-p-rm>' + I.trash + ' Remove</button></div></div>';
				}).join('') : '<p class="muted small" style="margin:0">No package on this invoice. Add one assigned to the client’s project, a reusable template, or a custom package. Each keeps its own price, terms and service period; later template changes never alter issued invoices.</p>';
				totals();
			}
			function addPkg(p) { st.pkgs.push(p); paintPkgs(); }
			pkgAdd.onchange = function () {
				var v = pkgAdd.value, id = +v.slice(2);
				if (v.charAt(0) === 'a') {
					var a = st.allocs.filter(function (x) { return x.id === id; })[0];
					if (a) addPkg({ name: a.name, amount: (a.price || 0).toFixed(2), terms: a.terms || '', client_package_id: a.id, package_id: a.package_id || '', package_type: a.package_type, billing_month: a.package_type === 'monthly' ? monthNow() : '', period_start: a.package_type === 'monthly' ? '' : a.start_date, period_end: a.package_type === 'monthly' ? '' : (a.end_date || '') });
				} else if (v.charAt(0) === 't') {
					var t = packages.filter(function (x) { return x.id === id; })[0];
					if (t) addPkg({ name: t.name, amount: t.amount.toFixed(2), terms: t.terms || '', client_package_id: '', package_id: t.id, package_type: t.package_type, billing_month: t.package_type === 'monthly' ? monthNow() : '', period_start: t.package_type === 'monthly' ? '' : (t.start_date || ''), period_end: t.package_type === 'monthly' ? '' : (t.end_date || '') });
				}
				pkgAdd.value = '';
				if (WD.refreshDropdown) WD.refreshDropdown(pkgAdd);
			};
			c.querySelector('[data-pkg-custom]').onclick = function () { addPkg({ name: '', amount: '', terms: '', client_package_id: '', package_id: '', package_type: 'one_time', billing_month: '', period_start: '', period_end: '' }); var ns = pkgBox.querySelectorAll('[data-pf="name"]'); ns[ns.length - 1].focus(); };
			on(pkgBox, 'input', '[data-pf]', function (e, i) { st.pkgs[+i.closest('[data-p]').getAttribute('data-p')][i.getAttribute('data-pf')] = i.value; if (i.getAttribute('data-pf') === 'amount') totals(); });
			on(pkgBox, 'change', '[data-pf="package_type"],[data-pf="billing_month"]', function (e, i) {
				var p = st.pkgs[+i.closest('[data-p]').getAttribute('data-p')];
				p[i.getAttribute('data-pf')] = i.value;
				if (p.package_type === 'monthly' && !p.billing_month) p.billing_month = monthNow();
				paintPkgs();
			});
			on(pkgBox, 'click', '[data-p-rm]', function (e, b) { st.pkgs.splice(+b.closest('[data-p]').getAttribute('data-p'), 1); paintPkgs(); });
			function loadAllocs() {
				return api('clients/' + st.client.id + '/packages').then(function (rr) {
					st.allocs = rr.packages;
					paintPkgAdd();
					paintPkgs();
				}).catch(function () {});
			}
			/* Work Log groups: client-facing headings the selected tasks are organised under. */
			var groupBox = c.querySelector('[data-groups]');
			function defaultHeading() {
				var d = new Date((form.invoice_date.value || S.today) + 'T00:00:00Z');
				return 'Working Logs for ' + d.toLocaleDateString('en-IN', { month: 'long', year: 'numeric', timeZone: 'UTC' });
			}
			if (!st.groups.length) st.groups.push({ heading: defaultHeading(), remarks: '' });
			function paintGroups() {
				groupBox.innerHTML = st.groups.map(function (g, i) {
					return '<div class="wl-group" data-g="' + i + '"><input data-gh maxlength="160" placeholder="Group heading, e.g. Working Logs for October 2026" aria-label="Work Log group heading" value="' + esc(g.heading) + '"><input data-gr maxlength="1000" placeholder="Group remarks (optional)" aria-label="Work Log group remarks" value="' + esc(g.remarks) + '">' + (st.groups.length > 1 ? '<button type="button" class="btn btn-ghost btn-icon btn-sm" data-g-rm aria-label="Remove group">' + I.trash + '</button>' : '') + '</div>';
				}).join('') + '<button type="button" class="btn btn-sm" data-g-add>' + I.plus + ' Add Work Log group</button>';
			}
			on(groupBox, 'input', '[data-gh]', function (e, i) { st.groups[+i.closest('[data-g]').getAttribute('data-g')].heading = i.value; });
			on(groupBox, 'input', '[data-gr]', function (e, i) { st.groups[+i.closest('[data-g]').getAttribute('data-g')].remarks = i.value; });
			// Headings are also used as the group options on each task: refresh them once typing ends.
			on(groupBox, 'change', '[data-gh]', function () { paintWork(); });
			on(groupBox, 'click', '[data-g-add]', function () { st.groups.push({ heading: '', remarks: '' }); paintGroups(); paintWork(); var hs = groupBox.querySelectorAll('[data-gh]'); hs[hs.length - 1].focus(); });
			on(groupBox, 'click', '[data-g-rm]', function (e, b) {
				var no = +b.closest('[data-g]').getAttribute('data-g') + 1;
				st.groups.splice(no - 1, 1);
				Object.keys(st.items).forEach(function (id) { var it = st.items[id]; it.group = it.group === no ? 1 : (it.group > no ? it.group - 1 : it.group); });
				paintGroups();
				paintWork();
			});
			function paintBill() {
				var x = st.client, box = c.querySelector('[data-bill]'), rb = c.querySelector('[data-recip]');
				if (!x) { box.innerHTML = 'Choose a client to load the saved billing details.'; rb.innerHTML = '<span class="muted small">Choose a client to load the billing emails.</span>'; st.recip = null; return; }
				var miss = !x.billing_whatsapp && !x.billing_email;
				box.classList.remove('muted');
				box.innerHTML = '<b>' + esc(x.billing_name || x.name) + '</b>' + (x.billing_contact ? '<div>Attn: ' + esc(x.billing_contact) + '</div>' : '') + (x.billing_address ? '<div>' + esc(x.billing_address).replace(/\n/g, '<br>') + '</div>' : '') +
					'<div class="small">' + [x.billing_whatsapp ? 'WhatsApp ' + esc(x.billing_whatsapp) : '', x.billing_wa_group ? 'WhatsApp group set' : '', x.gstin ? 'GSTIN ' + esc(x.gstin) : ''].filter(Boolean).join(' · ') + '</div>' +
					(miss ? '<div class="small" style="color:var(--danger)">No billing WhatsApp or email saved. <a href="#/clients/' + x.id + '">Add them to the client</a> to send the invoice.</div>' : '');
				var list = orig ? (bl.emails && bl.emails.length ? bl.emails : (bl.email ? [{ email: bl.email, type: 'to' }] : [])) : (x.billing_emails || []);
				st.recip = WD.emailListEditor(list, { allowEmpty: true });
				rb.innerHTML = '';
				rb.appendChild(st.recip);
			}
			function loadWork() {
				work.innerHTML = '<div class="card-b">' + ui.loading(3) + '</div>';
				return api('accounts/clients/' + st.client.id + '/billable', { params: orig ? { exclude_invoice: orig.id } : {} }).then(function (rows) {
					st.work = rows;
					st.items = {};
					rows.forEach(function (t) { st.items[t.id] = { on: !orig && !t.billed_in.length, treatment: t.suggested || 'included', amount: '', override: '', remarks: '', name: '', group: 1 }; });
					if (orig) {
						orig.items.forEach(function (i) {
							if (!i.task_id) return;
							if (st.items[i.task_id]) st.items[i.task_id] = { on: true, treatment: i.treatment === 'included' ? 'included' : 'custom', amount: i.treatment === 'custom' ? i.amount.toFixed(2) : '', override: i.override_reason || '', remarks: i.remarks || '', name: i.description || '', group: i.group_no || 1 };
							else if (!orig._moved) st.custom.push({ description: i.description, treatment: i.treatment === 'included' ? 'included' : 'custom', amount: i.treatment === 'custom' ? i.amount.toFixed(2) : '' });
						});
						orig._moved = true;
						paintLines();
					}
					paintWork();
				}, function (e) { work.innerHTML = ''; work.appendChild(ui.errorBox(e, loadWork)); });
			}
			var SCOPE_BADGE = { included: 's-completed', retainer: 's-in_process', additional: 's-pending' };
			function shown() {
				var showBilled = c.querySelector('[data-show-billed]').checked;
				return st.work.filter(function (t) { return showBilled || !t.billed_in.length; });
			}
			function paintWork() {
				var rows = shown();
				if (!rows.length) {
					work.innerHTML = ui.emptyState(st.work.length ? 'All completed work is already billed' : 'No completed work for this client', st.work.length ? 'Tick “Show already billed” to bill something again with a reason.' : 'Completed tasks linked to this client (or its projects) appear here.', I.check);
					totals();
					return;
				}
				work.innerHTML = '<div class="table-wrap"><table class="tbl stack work-tbl"><thead><tr><th style="width:36px"></th><th>Completed work</th><th>Billing</th><th class="num">Amount (₹)</th></tr></thead><tbody>' + rows.map(function (t) {
					var it = st.items[t.id], p = t.package;
						return '<tr data-w="' + t.id + '"' + (it.on ? '' : ' class="dim"') + '><td><label class="wl-pick"><input type="checkbox" data-on' + (it.on ? ' checked' : '') + ' aria-label="Include on invoice"></label></td><td data-th="Work">' + (it.on ? '<input data-nm maxlength="255" aria-label="Name on this invoice only" title="Shown on this invoice only. The task keeps its internal title." placeholder="' + esc(t.title) + '" value="' + esc(it.name) + '" class="wl-name"><div class="muted small">Internal: ' + esc(t.title) + '</div>' : '<b>' + esc(t.title) + '</b>') + '<div class="muted small">#' + t.id + ' · completed ' + esc(fmt.date(t.completed_at, true)) + (t.project ? ' · ' + esc(t.project) : '') + '</div>' +
						(p && p.scope ? '<div style="margin-top:3px"><span class="badge ' + (SCOPE_BADGE[p.scope] || '') + '">' + esc(p.scope_label + (p.service_name ? ': ' + p.service_name : '')) + '</span>' + (p.package_name ? ' <span class="muted small">' + esc(p.package_name) + '</span>' : '') + (p.over_limit ? ' <span class="badge overdue">Beyond limit</span>' : '') + '</div>' : '') +
						(p && p.note ? '<div class="small muted">' + esc(p.note) + '</div>' : '') +
						(t.remarks.length ? '<details class="small wl-rmk"><summary>Task remarks (' + t.remarks.length + ')</summary>' + t.remarks.map(function (x) { return '<div class="remark-text">' + esc(x) + '</div>'; }).join('') + '</details>' : '') +
						(t.billed_in.length ? '<div class="small" style="color:var(--danger)">Already billed in ' + esc(t.billed_in.map(function (b) { return b.invoice_no; }).join(', ')) + '</div><input data-ovr placeholder="Reason for billing again (required)" value="' + esc(it.override) + '" style="margin-top:4px">' : '') +
							(it.on ? (st.groups.length > 1 ? '<select data-grp aria-label="Work Log group" style="margin-top:6px">' + st.groups.map(function (g, gi) { return '<option value="' + (gi + 1) + '"' + (it.group === gi + 1 ? ' selected' : '') + '>' + esc(g.heading || 'Group ' + (gi + 1)) + '</option>'; }).join('') + '</select>' : '') + '<input data-rmk maxlength="1000" placeholder="Remark on the invoice (optional)" value="' + esc(it.remarks) + '" style="margin-top:6px">' : '') + '</td>' +
						'<td data-th="Billing"><div class="seg seg-sm"><button type="button" data-t="included"' + (it.treatment === 'included' ? ' class="on"' : '') + '>Included in package</button><button type="button" data-t="custom"' + (it.treatment === 'custom' ? ' class="on"' : '') + '>Custom amount</button></div></td>' +
						'<td data-th="Amount" class="num">' + (it.treatment === 'custom' ? '<input data-amt inputmode="decimal" value="' + esc(it.amount) + '" placeholder="0.00" style="max-width:120px;text-align:right">' : '<span class="muted small">No separate charge</span>') + '</td></tr>';
				}).join('') + '</tbody></table></div>';
				totals();
			}
			var itemOf = function (el) { return st.items[el.closest('[data-w]').getAttribute('data-w')]; };
			on(work, 'change', '[data-on]', function (e, i) {
				var id = i.closest('[data-w]').getAttribute('data-w');
				st.items[id].on = i.checked;
				paintWork();
				var n = work.querySelector('[data-w="' + id + '"] [data-on]');
				if (n) n.focus(); // the table is redrawn; keep keyboard position
			});
			// Select all: every not-yet-billed task on screen. Once they are all selected it clears every task on screen.
			// Already billed tasks are never ticked in bulk: each needs its own tick and a reason.
			c.querySelector('[data-all]').onchange = function () {
				var rows = shown(), elig = rows.filter(function (t) { return !t.billed_in.length; });
				var full = elig.length > 0 && elig.every(function (t) { return st.items[t.id].on; });
				(full || !elig.length ? rows : elig).forEach(function (t) { st.items[t.id].on = !full && elig.length > 0; });
				paintWork();
			};
			on(work, 'click', '[data-t]', function (e, b) { var it = itemOf(b); it.treatment = b.getAttribute('data-t'); it.on = true; paintWork(); });
			on(work, 'input', '[data-amt]', function (e, i) { itemOf(i).amount = i.value; totals(); });
			on(work, 'input', '[data-ovr]', function (e, i) { itemOf(i).override = i.value; });
			on(work, 'input', '[data-rmk]', function (e, i) { itemOf(i).remarks = i.value; });
			on(work, 'input', '[data-nm]', function (e, i) { itemOf(i).name = i.value; });
			on(work, 'change', '[data-grp]', function (e, i) { itemOf(i).group = +i.value; });
			// Hidden rows must not stay on the invoice unseen; their billing choice, amount and reason are kept.
			c.querySelector('[data-show-billed]').onchange = function () {
				if (!this.checked) st.work.forEach(function (t) { if (t.billed_in.length) st.items[t.id].on = false; });
				paintWork();
			};

			function paintLines() {
				lines.innerHTML = (st.custom.length ? '' : '<p class="muted small">Items not linked to a task, e.g. “Additional website banner”. Mark a line “Included in package” to list it without a charge.</p>') + st.custom.map(function (l, i) {
					var inc = l.treatment === 'included';
					return '<div class="line-row" data-l="' + i + '"><input data-d placeholder="Description" value="' + esc(l.description) + '" maxlength="255" aria-label="Description"><div class="seg seg-sm"><button type="button" data-lt="included"' + (inc ? ' class="on"' : '') + '>Included in package</button><button type="button" data-lt="custom"' + (inc ? '' : ' class="on"') + '>Custom amount</button></div>' +
						(inc ? '<span class="muted small line-amt">No separate charge</span>' : '<input data-a inputmode="decimal" placeholder="Amount (₹)" value="' + esc(l.amount) + '" class="line-amt" style="text-align:right" aria-label="Amount">') +
						'<button type="button" class="btn btn-ghost btn-icon btn-sm" data-rm aria-label="Remove line">' + I.trash + '</button></div>';
				}).join('');
				totals();
			}
			c.querySelector('[data-add-line]').onclick = function () { st.custom.push({ description: '', treatment: 'custom', amount: '' }); paintLines(); var rr = lines.querySelectorAll('[data-d]'); if (rr.length) rr[rr.length - 1].focus(); };
			on(lines, 'input', '[data-d]', function (e, i) { st.custom[+i.closest('[data-l]').getAttribute('data-l')].description = i.value; totals(); });
			on(lines, 'input', '[data-a]', function (e, i) { st.custom[+i.closest('[data-l]').getAttribute('data-l')].amount = i.value; totals(); });
			on(lines, 'click', '[data-lt]', function (e, b) { st.custom[+b.closest('[data-l]').getAttribute('data-l')].treatment = b.getAttribute('data-lt'); paintLines(); });
			on(lines, 'click', '[data-rm]', function (e, b) { st.custom.splice(+b.closest('[data-l]').getAttribute('data-l'), 1); paintLines(); });

			form.client_id.onchange = function () {
				var id = +form.client_id.value;
				st.client = clients.filter(function (x) { return x.id === id; })[0] || null;
				paintBill();
				adv.setClient(st.client ? st.client.id : 0);
				exp.setClient(st.client ? st.client.id : 0);
				prv.setClient(st.client ? st.client.id : 0);
				if (!orig) st.pkgs = [];
				if (st.client) { loadWork(); loadAllocs(); } else { work.innerHTML = '<div class="card-b muted small">Choose a client to fetch their completed work.</div>'; st.allocs = []; paintPkgAdd(); paintPkgs(); }
			};
			if (pre.client) form.client_id.onchange();
			if (orig) paintLines();
			paintPkgAdd();
			paintPkgs();
			paintGroups();
			form.invoice_date.addEventListener('change', function () {
				// A month picked for "this month" follows the invoice date until it is changed by hand.
				if (st.groups.length === 1 && /^Working Logs for /.test(st.groups[0].heading)) { st.groups[0].heading = defaultHeading(); paintGroups(); }
				paintPkgs();
			});
			totals();

			function body() {
				var items = [];
				st.work.forEach(function (t) {
					var it = st.items[t.id];
					if (!it || !it.on) return;
					items.push({ task_id: t.id, description: it.name.trim() || t.title, group: it.group || 1, treatment: it.treatment, amount: it.treatment === 'custom' ? it.amount : 0, override_reason: t.billed_in.length ? it.override : '', remarks: it.remarks });
				});
				st.custom.forEach(function (l) { if (l.description.trim() || l.amount) items.push({ description: l.description.trim(), treatment: l.treatment, amount: l.treatment === 'custom' ? l.amount : 0 }); });
				var hasTasks = items.some(function (i) { return i.task_id; });
				var out = { client_id: form.client_id.value, invoice_date: form.invoice_date.value, due_date: form.due_date.value, packages: st.pkgs.map(function (p) { return { name: p.name.trim(), amount: p.amount, terms: p.terms, client_package_id: p.client_package_id, package_id: p.package_id, package_type: p.package_type, billing_month: p.package_type === 'monthly' ? (p.billing_month || monthNow()) : '', period_start: p.period_start, period_end: p.period_end }; }), groups: hasTasks ? st.groups.map(function (g, i) { return { heading: g.heading.trim() || 'Work Log ' + (i + 1), remarks: g.remarks }; }) : [], items: items, discount: form.discount.value, discount_label: form.discount_label.value, tax_label: form.tax_label.value, tax_rate: form.tax_rate.value, work_remarks: form.work_remarks.value, notes: form.notes.value, payment_instructions: form.payment_instructions.value };
				if (st.recip) out.recipients = st.recip.getValue();
				out.allocations = adv.value();
				out.expenses = exp.value();
				var pv = prv.value();
				out.previous = pv.previous; out.consolidate = pv.consolidate; out.consolidate_reason = pv.consolidate_reason;
				if (orig) {
					out.billing = { name: form.b_name.value.trim(), contact: form.b_contact.value.trim(), whatsapp: form.b_whatsapp.value.trim(), address: form.b_address.value, gstin: form.b_gstin.value.trim().toUpperCase() };
					out.cancel_reason = form.cancel_reason.value.trim();
					out.acknowledge_payments = form.ack ? form.ack.checked : false;
				}
				return out;
			}
			var pv = c.querySelector('[data-preview]');
			pv.onclick = function () {
				form.querySelector('[data-err]').classList.add('hidden');
				if (!form.client_id.value) return fail(form, 'Choose a client.');
				var b;
				try { b = body(); } catch (er) { return fail(form, 'Recipients: ' + er.message); }
				if (orig && !b.cancel_reason) { form.cancel_reason.focus(); return fail(form, 'Give the reason for cancelling ' + orig.invoice_no + '.'); }
				if (orig && form.ack && !form.ack.checked) return fail(form, 'Confirm that the payments stay recorded on ' + orig.invoice_no + '.');
				if (orig && !b.billing.name) return fail(form, 'Enter the billing name.');
				var missing = st.work.filter(function (t) { var it = st.items[t.id]; return it && it.on && t.billed_in.length && !it.override.trim(); });
				if (missing.length) return fail(form, '“' + missing[0].title + '” is already billed. Untick it or give a reason for billing it again.');
				if (b.items.some(function (i) { return i.treatment === 'included'; }) && !hasPkg()) return fail(form, 'Some items are “Included in package”, but no package is selected. Add a package (step 2) or mark them “Custom amount”.');
				var ee = exp.error();
				if (ee) return fail(form, 'Billable expenses (step 5): ' + ee);
				var pe = prv.error();
				if (pe) return fail(form, 'Previous outstanding invoices (step 6): ' + pe);
				var ae = adv.error();
				if (ae) return fail(form, 'Advance payments (step 7): ' + ae);
				if (st.pkgs.some(function (p) { return !p.name.trim(); })) return fail(form, 'Give every package a name, or remove it (step 2).');
				if (!b.items.length && !b.expenses.length && !b.consolidate.length && !b.packages.some(function (p) { return money(p.amount) > 0; })) return fail(form, 'Add a package amount, work, a line item or an expense.');
				WD.busy(pv, true, 'Preparing…');
				api('accounts/invoices/preview', { method: 'POST', body: b }).then(function (p) {
					WD.busy(pv, false);
					if (orig) p.replaces = { id: orig.id, invoice_no: orig.invoice_no };
					var md = WD.modal({ title: orig ? 'Review replacement for ' + orig.invoice_no : 'Review invoice', size: 'lg', body: (orig ? '<div class="alert alert-warn small">Generating this invoice <b>cancels ' + esc(orig.invoice_no) + '</b> (reason: ' + esc(b.cancel_reason) + ') and links the two. This cannot be undone; ' + esc(orig.invoice_no) + ' keeps its number, PDF and history.</div>' : '<div class="alert alert-info small">Check everything below. The invoice number is reserved only when you press Generate.</div>') + (b.consolidate.length ? '<div class="alert alert-warn small">Generating also <b>consolidates</b> ' + b.consolidate.length + ' earlier invoice(s): their remaining balance moves to this invoice and they become Consolidated. Reason: ' + esc(b.consolidate_reason) + '</div>' : '') + (p.settings_missing.length ? '<div class="alert alert-warn small">Not configured, so not shown: ' + esc(p.settings_missing.join(', ')) + '. <a href="#/accounts/settings">Settings</a></div>' : '') + invoiceHtml(p, true), foot: '<button type="button" class="btn" data-close>Back to edit</button><button type="button" class="btn btn-primary" data-gen>' + I.check + (orig ? ' Cancel ' + esc(orig.invoice_no) + ' & generate replacement' : ' Generate invoice & PDF') + '</button>' });
					var g = md.el.querySelector('[data-gen]');
					g.onclick = function () {
						WD.busy(g, true, 'Generating…');
						api(orig ? 'accounts/invoices/' + orig.id + '/replace' : 'accounts/invoices', { method: 'POST', body: b }).then(function (inv) {
							md.close();
							S.replaceDraft = null;
							WD.toast(orig ? inv.invoice_no + ' created — ' + orig.invoice_no + ' cancelled and linked' : 'Invoice ' + inv.invoice_no + ' created');
							S.justCreated = inv.id;
							WD.go('/accounts/invoices/' + inv.id);
						}, function (e) { WD.busy(g, false); md.close(); fail(form, e.message); });
					};
				}, function (e) { WD.busy(pv, false); fail(form, e.message); });
			};
		});
	};

	/** "To: a, b · CC: c" from an invoice snapshot's recipient list. */
	function recipText(cl) {
		var l = cl.emails && cl.emails.length ? cl.emails : (cl.email ? [{ email: cl.email, type: 'to' }] : []);
		var to = l.filter(function (e) { return e.type === 'to'; }).map(function (e) { return e.email; }), cc = l.filter(function (e) { return e.type === 'cc'; }).map(function (e) { return e.email; });
		return to.length ? (cc.length ? 'To ' : '') + to.join(', ') + (cc.length ? ' · CC ' + cc.join(', ') : '') : '';
	}

	/** HTML rendering of an invoice (preview and detail page), from the same data as the PDF. */
	function invoiceHtml(v, preview) {
		var b = v.business || {}, cl = v.client || {};
		var biz = [b.address ? esc(b.address).replace(/\n/g, '<br>') : '', [b.phone, b.email, b.website].filter(Boolean).map(esc).join(' · '), b.gstin ? 'GSTIN: ' + esc(b.gstin) : '', b.business_logo ? '<span class="muted">Logo is printed on the PDF</span>' : ''].filter(Boolean).join('<br>');
		var items = (v.items || []).slice();
		if (preview) items = (v.packages || []).concat(items);
		var groups = v.groups || [], rowNo = 0;
		// Same order as the PDF: packages, each Work Log group under its heading, then the rest.
		var sections = [{ title: '', items: items }];
		if (groups.length) {
			var pk = items.filter(function (i) { return i.treatment === 'package'; });
			sections = pk.length ? [{ title: 'Services', items: pk }] : [];
			groups.forEach(function (g, gi) { var l = items.filter(function (i) { return i.treatment !== 'package' && i.group_no === gi + 1; }); if (l.length) sections.push({ title: g.heading, remarks: g.remarks, items: l }); });
			var rest = items.filter(function (i) { return i.treatment !== 'package' && !(i.group_no && groups[i.group_no - 1]); });
			if (rest.length) sections.push({ title: 'Other items', items: rest });
		}
		var pay = [b.account_name || b.bank_name ? esc([b.account_name, b.bank_name].filter(Boolean).join(' · ')) : '', b.account_number ? 'A/c no. ' + esc(b.account_number) + (b.ifsc ? ' · IFSC ' + esc(b.ifsc) : '') : '', b.upi_id ? 'UPI: ' + esc(b.upi_id) : '', b.upi_qr ? '<span class="muted">UPI QR code is printed on the PDF</span>' : '', v.payment_instructions ? esc(v.payment_instructions).replace(/\n/g, '<br>') : ''].filter(Boolean).join('<br>');
		return '<div class="invoice-doc">' +
			'<div class="inv-head"><div><div class="inv-brand">' + esc(b.business_name || 'NeonDigital') + '</div><div class="small muted">' + biz + '</div></div>' +
			'<div class="inv-meta"><div class="inv-title">' + (b.gstin ? 'TAX INVOICE' : 'INVOICE') + '</div><dl class="kv"><dt>Invoice no.</dt><dd>' + esc(v.invoice_no) + (preview ? ' <span class="muted small">(provisional)</span>' : '') + '</dd><dt>Invoice date</dt><dd>' + esc(fmt.date(v.invoice_date, true)) + '</dd><dt>Due date</dt><dd>' + esc(fmt.date(v.due_date, true)) + '</dd><dt>Financial year</dt><dd>FY ' + esc(v.fy) + '</dd>' + (v.replaces ? '<dt>Replaces</dt><dd>' + (preview ? esc(v.replaces.invoice_no) : invLink(v.replaces)) + '</dd>' : '') + '</dl></div></div>' +
			'<div class="inv-bill"><div class="label">Bill to</div><b>' + esc(cl.name || '') + '</b>' + (cl.contact ? '<div>Attn: ' + esc(cl.contact) + '</div>' : '') + (cl.address ? '<div>' + esc(cl.address).replace(/\n/g, '<br>') + '</div>' : '') + '<div class="small muted">' + [cl.whatsapp ? 'WhatsApp ' + esc(cl.whatsapp) : '', esc(recipText(cl)), cl.gstin ? 'GSTIN ' + esc(cl.gstin) : ''].filter(Boolean).join(' · ') + '</div></div>' +
			'<div class="table-wrap"><table class="tbl inv-lines"><thead><tr><th>#</th><th>Description</th><th>Billing</th><th class="num">Amount</th></tr></thead><tbody>' + sections.map(function (sec) { return (sec.title ? '<tr class="inv-sec"><td colspan="4"><b>' + esc(sec.title) + '</b></td></tr>' : '') + sec.items.map(function (it) {
				var i = rowNo++;
				var isExp = it.treatment === 'expense';
				var lbl = it.treatment === 'package' ? 'Package' : it.treatment === 'included' ? '<span class="badge s-completed">Included in package</span>' : isExp ? '<span class="badge">Reimbursable expense</span>' : it.treatment === 'carried' ? '<span class="badge s-pending">Previous balance</span>' : (it.task_id ? 'Additional work' : 'Custom item');
				var subl = [it.period_start ? 'Service period: ' + fmt.date(it.period_start, true) + (it.period_end && it.period_end !== '9999-12-31' ? ' – ' + fmt.date(it.period_end, true) : ' onwards') : '', it.terms ? 'Terms: ' + it.terms : '', it.completed_on ? (isExp ? 'Expense dated ' : 'Completed ') + fmt.date(it.completed_on, true) + (isExp && it.original_amount !== null && it.original_amount !== undefined && Math.abs(it.original_amount - it.amount) > 0.001 ? ' · original amount ' + inr(it.original_amount) : '') : '', it.remarks || '', it.override_reason ? (isExp ? 'Amount adjusted: ' : 'Billed again: ') + it.override_reason : ''].filter(Boolean);
				return '<tr><td>' + (i + 1) + '</td><td>' + (it.treatment === 'package' ? '<b>' + esc(it.description) + '</b>' : esc(it.description)) + subl.map(function (x) { return '<div class="small muted">' + esc(x).replace(/\n/g, '<br>') + '</div>'; }).join('') + '</td><td>' + lbl + '</td><td class="num">' + (it.treatment === 'included' ? '—' : inr(it.amount)) + '</td></tr>';
			}).join('') + (sec.remarks ? '<tr><td colspan="4" class="small muted">' + esc(sec.remarks).replace(/\n/g, '<br>') + '</td></tr>' : ''); }).join('') + '</tbody></table></div>' +
			(v.work_remarks ? '<div class="small" style="margin-top:10px"><div class="label">Work log remarks</div>' + esc(v.work_remarks).replace(/\n/g, '<br>') + '</div>' : '') +
			'<div class="inv-foot"><div class="small">' + (pay ? '<div class="label">Payment instructions</div>' + pay : '') + (v.notes ? '<div class="label" style="margin-top:10px">Notes</div>' + esc(v.notes).replace(/\n/g, '<br>') : '') + '</div>' +
			'<dl class="kv inv-totals">' + (v.expense_subtotal ? '<dt>Services &amp; package</dt><dd>' + inr(v.subtotal - v.expense_subtotal) + '</dd><dt>Reimbursable expenses</dt><dd>' + inr(v.expense_subtotal) + '</dd>' : '') + '<dt>Subtotal</dt><dd>' + inr(v.subtotal) + '</dd>' + (v.discount ? '<dt>Discount' + (v.discount_label ? ': ' + esc(v.discount_label) : '') + '</dt><dd>− ' + inr(v.discount) + '</dd>' : '') + (v.tax_rate ? '<dt>' + esc(v.tax_label || 'Tax') + ' @ ' + v.tax_rate + '%</dt><dd>' + inr(v.tax_amount) + '</dd>' : '') + (v.carried_forward ? '<dt>Current invoice total</dt><dd>' + inr(v.total - v.carried_forward) + '</dd><dt>Previous balance brought forward</dt><dd>' + inr(v.carried_forward) + '</dd>' : '') + '<dt><b>Grand total</b></dt><dd><b>' + inr(v.total) + '</b></dd>' + (v.amount_adjusted ? '<dt>Advance adjusted</dt><dd>− ' + inr(v.amount_adjusted) + '</dd><dt>Amount paid</dt><dd>' + inr(v.amount_received) + '</dd>' : '<dt>Amount paid</dt><dd>' + inr(v.amount_received !== undefined ? v.amount_received : v.amount_paid) + '</dd>') + (v.amount_transferred && v.consolidated_into ? '<dt>Transferred to ' + esc(v.consolidated_into.invoice_no) + '</dt><dd>− ' + inr(v.amount_transferred) + '</dd>' : '') + '<dt><b>Balance due</b></dt><dd><b>' + inr(v.balance) + '</b></dd></dl></div>' + accountSummaryHtml(v.status === 'cancelled' ? null : v.account_summary) + '</div>';
	}
	/** Previous outstanding balance statement (snapshot at issue); never part of the invoice total. */
	function accountSummaryHtml(as) {
		if (!as || !as.invoices || !as.invoices.length) return '';
		return '<div class="inv-prev"><div class="label">Previous outstanding balance (as on ' + esc(fmt.date(as.as_of, true)) + ')</div><div class="table-wrap"><table class="tbl"><thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th class="num">Amount</th><th class="num">Received</th><th class="num">Outstanding</th></tr></thead><tbody>' + as.invoices.map(function (o) {
			return '<tr><td>' + esc(o.invoice_no) + (o.overdue ? ' <span class="badge overdue">Overdue</span>' : '') + '</td><td>' + esc(fmt.date(o.invoice_date, true)) + '</td><td>' + esc(fmt.date(o.due_date, true)) + '</td><td class="num">' + inr(o.total) + '</td><td class="num">' + inr(o.received + o.adjusted) + '</td><td class="num">' + inr(o.balance) + '</td></tr>';
		}).join('') + '</tbody></table></div><dl class="kv inv-totals"><dt>Current invoice balance</dt><dd>' + inr(as.current_balance) + '</dd><dt>Previous outstanding balance</dt><dd>' + inr(as.previous_balance) + '</dd>' + (as.advance_credit ? '<dt>Available advance credit</dt><dd>− ' + inr(as.advance_credit) + '</dd>' : '') + '<dt><b>Total account balance payable</b></dt><dd><b>' + inr(as.total_payable) + '</b></dd></dl><p class="small muted">Earlier invoices are listed for information; they keep their own numbers and payments and are not included in this invoice total.</p></div>';
	}

	/* ------------------------------------------------------------------ *
	 * Invoice detail: PDF, WhatsApp, email, payments, edits, audit
	 * ------------------------------------------------------------------ */
	V.invoice = function (c) {
		if (!can('ndwd_manage_accounts')) { c.innerHTML = ui.emptyState('Accounts is restricted', '', I.rupee); return Promise.resolve(); }
		var id = +S.routeParams[1];
		WD.setTitle('Invoice', '');
		c.innerHTML = ui.loading(8);
		return Promise.all([meta(), api('accounts/invoices/' + id)]).then(function (r) { paintInvoice(c, r[1], r[0]); });
	};
	function paintInvoice(c, v, m) {
		WD.setTitle('Invoice ' + v.invoice_no, v.client_name + ' · ' + v.status_label);
		var open = v.status !== 'cancelled' && v.status !== 'consolidated';
		var fresh = S.justCreated === v.id;
		S.justCreated = null;
		c.innerHTML = '<div class="toolbar"><a class="btn btn-sm btn-ghost" href="#/accounts/invoices">' + I.left + ' Invoices</a><span class="spacer"></span>' + invBadge(v) + '</div>' +
			(fresh ? '<div class="alert alert-info">Invoice saved with its PDF. Send it to the client with WhatsApp or email below — nothing is sent until you do.</div>' : '') +
			'<div class="inv-actions"><button class="btn" data-pdf>' + I.download + ' Download PDF</button><button class="btn btn-wa" data-wa>' + I.wa + ' WhatsApp Client</button><button class="btn" data-mail>' + I.mail + ' Email Client</button>' + (can('ndwd_manage_workspace') ? '<span data-ws-send></span>' : '') +
			(open && v.balance > 0 ? '<button class="btn btn-primary" data-pay>' + I.rupee + ' Record payment</button><button class="btn" data-split>' + I.rupee + ' Payment for several invoices</button>' : '') + (open ? '<button class="btn btn-ghost" data-edit>' + I.edit + ' Edit dates / notes</button>' + (v.carried_forward ? '' : '<button class="btn" data-replace>' + I.file + ' Cancel &amp; create replacement</button>') + '<button class="btn btn-ghost btn-danger" data-cancel>Cancel invoice</button>' : '') + '</div>' +
			(v.consolidated_into ? '<div class="alert alert-info section-gap"><b>Consolidated into ' + invLink(v.consolidated_into) + '</b><div class="small">The remaining balance of ' + inr(v.amount_transferred) + ' was transferred to ' + esc(v.consolidated_into.invoice_no) + '; collect it there. Payments already received stay recorded here. Cancelling ' + esc(v.consolidated_into.invoice_no) + ' restores this balance.</div></div>' : '') +
			(v.consolidated_from && v.consolidated_from.length ? '<div class="alert alert-info section-gap">Includes balances brought forward from ' + v.consolidated_from.map(function (o) { return invLink(o) + ' (' + inr(o.amount) + ')'; }).join(', ') + '. Cancelling this invoice restores them.</div>' : '') +
			(v.replaces ? '<div class="alert alert-info section-gap">Replacement invoice — replaces ' + invLink(v.replaces) + ' (cancelled).</div>' : '') +
			(v.status === 'cancelled' ? '<div class="alert alert-error section-gap"><b>Cancelled' + (v.replaced_by ? ' — replaced by ' + invLink(v.replaced_by) : '') + '</b><div class="small">' + esc(fmt.dt(v.cancelled_at)) + (v.cancelled_by ? ' by ' + esc(v.cancelled_by) : '') + ' · Reason: ' + esc(v.cancel_reason) + '</div></div>' : '') +
			(v.status === 'cancelled' && v.amount_received > 0 ? '<div class="alert alert-warn">' + inr(v.amount_paid) + ' received on this cancelled invoice is still recorded as money received. ' + (v.replaced_by ? 'Move it to ' + esc(v.replaced_by.invoice_no) + ' below, or record a ' : 'Record a ') + '<a href="#/accounts/passbook">Refund Paid</a> linked to this invoice if it was returned.</div>' : '') +
			'<div class="grid grid-main section-gap"><section class="card"><div class="card-b">' + invoiceHtml(v, false) + '</div></section>' +
			'<div class="grid" style="align-content:start"><section class="card"><div class="card-h"><h2>Payments</h2><span class="spacer"></span><span class="muted small">Balance ' + inr(v.balance) + '</span></div><div class="card-b flush">' +
			(v.payments.length ? '<ul class="timeline" style="padding:0 16px">' + v.payments.map(function (p) {
				return '<li><div class="body' + (p.voided_at ? ' voided' : '') + '"><b>' + inr(p.amount) + '</b> · ' + esc(p.method_label) + (p.destination ? ' → ' + esc(p.destination) : '') + (p.reference ? ' · Ref ' + esc(p.reference) : '') + '<div class="small muted">' + esc(fmt.date(p.paid_on, true)) + ' · recorded by ' + esc(p.created_by) + (p.notes ? ' · ' + esc(p.notes) : '') + '</div>' + (p.voided_at ? '<div class="small" style="color:var(--danger)">Voided: ' + esc(p.void_reason) + '</div>' : '') + '</div>' + (!p.voided_at && open ? '<button class="btn btn-sm btn-ghost" data-void="' + p.id + '">Void</button>' : '') + (!p.voided_at && !open && v.replaced_by ? '<button class="btn btn-sm" data-move="' + p.id + '">Move to ' + esc(v.replaced_by.invoice_no) + '</button>' : '') + '</li>';
			}).join('') + '</ul>' : ui.emptyState('No payments yet', 'Record each payment as it arrives — part payments are fine.', I.rupee)) + '</div></section>' +
			'<section class="card"><div class="card-h"><h2>Advances adjusted</h2><span class="spacer"></span>' + (open ? '<button class="btn btn-sm" data-adv-edit>' + I.edit + ' Adjust advances</button>' : '') + '</div><div class="card-b flush">' +
			(v.allocations.length ? '<ul class="timeline" style="padding:0 16px">' + v.allocations.map(function (a) {
				return '<li><div class="body' + (a.released_at ? ' voided' : '') + '"><b>' + inr(a.amount) + '</b> from <a href="#/accounts/income">' + esc(a.description) + '</a><div class="small muted">Received ' + esc(fmt.date(a.received_on, true)) + ' (' + inr(a.income_amount) + ') · adjusted ' + esc(fmt.dt(a.created_at)) + ' by ' + esc(a.created_by) + '</div>' + (a.released_at ? '<div class="small" style="color:var(--danger)">Released: ' + esc(a.release_reason) + '</div>' : '') + '</div></li>';
			}).join('') + '</ul>' : '<div class="card-b muted small">No advance or earlier income is adjusted against this invoice.</div>') + '</div></section>' +
			'<section class="card"><div class="card-h"><h2>Audit trail</h2></div><div class="card-b flush"><ul class="timeline" style="padding:0 16px">' + v.audit.map(function (a) {
				return '<li><div class="body"><b>' + esc(a.user) + '</b> ' + esc(a.action.replace(/_/g, ' ')) + '<div class="small muted">' + esc(fmt.dt(a.created_at)) + auditText(a.details) + '</div></div></li>';
			}).join('') + '</ul></div></section></div></div>';
		var reload = function () { return api('accounts/invoices/' + v.id).then(function (nv) { paintInvoice(c, nv, m); }); };
		if (c.querySelector('[data-ws-send]')) WD.invoiceWorkspaceAction(c.querySelector('[data-ws-send]'), v);
		c.querySelector('[data-pdf]').onclick = function (e) { WD.download('accounts/invoices/' + v.id + '/pdf', { button: e.currentTarget, busyLabel: 'Downloading…' }).catch(function () {}); };
		c.querySelector('[data-wa]').onclick = function () { shareModal(v, 'whatsapp', m); };
		c.querySelector('[data-mail]').onclick = function () { shareModal(v, 'email', m); };
		var pay = c.querySelector('[data-pay]');
		if (pay) pay.onclick = function () { paymentForm(v, m, reload); };
		var spl = c.querySelector('[data-split]');
		if (spl) spl.onclick = function () { receivePayment({ client_id: v.client_id, focus: v.id }, reload); };
		var ed = c.querySelector('[data-edit]');
		if (ed) ed.onclick = function () { editInvoice(v, reload); };
		var ae = c.querySelector('[data-adv-edit]');
		if (ae) ae.onclick = function () { advanceForm(v, reload); };
		var cn = c.querySelector('[data-cancel]');
		var paidNote = v.amount_received > 0 ? inr(v.amount_received) + ' has been received on this invoice. Cancelling does not refund or erase it: the payment stays recorded and still counts as money received. Record a refund separately if money goes back.' : '';
		var advNote = (v.amount_adjusted > 0 ? ' Advances adjusted on it (' + inr(v.amount_adjusted) + ') are released and become available again for other invoices.' : '') + (v.expense_subtotal > 0 ? ' Its billable expenses become available for billing again.' : '') + (v.consolidated_from && v.consolidated_from.length ? ' Balances brought forward go back to ' + v.consolidated_from.map(function (o) { return o.invoice_no; }).join(', ') + '.' : '');
		if (cn) cn.onclick = function () { reasonBox('Cancel invoice ' + v.invoice_no, 'The invoice keeps its number, PDF and history, marked Cancelled. No further payments or edits are possible, and its completed work can be billed again. This is not a cash movement.' + advNote, 'Cancel invoice', function (reason, ack) { return api('accounts/invoices/' + v.id + '/cancel', { method: 'POST', body: { reason: reason, acknowledge_payments: ack } }).then(reload); }, paidNote); };
		var rp = c.querySelector('[data-replace]');
		if (rp) rp.onclick = function () {
			reasonBox('Cancel ' + v.invoice_no + ' & create replacement', 'Next you get an editable copy of this invoice. ' + v.invoice_no + ' is cancelled and linked to the new invoice only when you generate the replacement; if you leave the form, nothing changes.', 'Continue to replacement', function (reason, ack) {
				S.replaceDraft = { id: v.id, reason: reason, ack: ack };
				WD.go('/accounts/invoices/new?replace=' + v.id);
				return Promise.resolve();
			}, paidNote, true);
		};
		on(c, 'click', '[data-move]', function (e, b) {
			WD.confirm('Move payment to ' + v.replaced_by.invoice_no + '?', 'The same payment record is re-linked to the replacement invoice (it is not duplicated, and the passbook is unchanged). Both invoices and their PDFs are updated, and the move is written to both audit trails.', 'Move payment').then(function (y) {
				if (y) api('accounts/payments/' + b.getAttribute('data-move') + '/transfer', { method: 'POST' }).then(function () { WD.toast('Payment moved to ' + v.replaced_by.invoice_no); reload(); }, WD.toastError);
			});
		});
		on(c, 'click', '[data-void]', function (e, b) { reasonBox('Void payment', 'The payment stays in the history marked as voided, and the balance is recalculated.', 'Void payment', function (reason) { return api('accounts/payments/' + b.getAttribute('data-void') + '/void', { method: 'POST', body: { reason: reason } }).then(reload); }); });
	}
	function auditText(d) {
		if (!d || typeof d !== 'object') return '';
		var parts = [];
		Object.keys(d).forEach(function (k) {
			var x = d[k];
			if (x && typeof x === 'object' && 'from' in x) parts.push(k.replace(/_/g, ' ') + ': ' + (x.from || '—') + ' → ' + (x.to || '—'));
			else if (x !== null && typeof x !== 'object' && x !== '') parts.push(k.replace(/_/g, ' ') + ': ' + x);
		});
		return parts.length ? ' · ' + esc(parts.join(' · ')) : '';
	}
	/** Reason prompt; with `warn` (payments recorded) the user must also tick an acknowledgement. */
	function reasonBox(title, text, label, go, warn, quiet) {
		var f = el('<form><div class="alert alert-error hidden" data-err></div><p class="muted">' + esc(text) + '</p>' + (warn ? '<div class="alert alert-warn small">' + esc(warn) + '</div><label class="check" style="margin-bottom:10px"><input type="checkbox" name="ack"> I understand the payment stays recorded</label>' : '') + '<label class="field"><span>Reason <span class="req">*</span></span><textarea name="reason" rows="3"></textarea></label></form>');
		var md = WD.modal({ title: title, size: 'sm', body: f, foot: '<button type="button" class="btn" data-close>Back</button><button type="button" class="btn btn-danger" data-ok>' + esc(label) + '</button>' });
		var ok = md.el.querySelector('[data-ok]');
		ok.onclick = function () {
			if (!f.reason.value.trim()) return fail(f, 'Please give a reason.');
			if (warn && !f.ack.checked) return fail(f, 'Tick the confirmation to continue.');
			WD.busy(ok, true);
			go(f.reason.value.trim(), warn ? f.ack.checked : false).then(function () { md.close(); if (!quiet) WD.toast('Saved'); }, function (e) { WD.busy(ok, false); fail(f, e.message); });
		};
	}
	function editInvoice(v, reload) {
		var f = el('<form><div class="alert alert-error hidden" data-err></div><p class="muted small">Amounts and lines of an issued invoice cannot be changed. To correct them, cancel this invoice and create a new one.</p><div class="form-grid"><label class="field"><span>Due date</span><input type="date" name="due_date"></label><div></div><label class="field span-2"><span>Payment instructions</span><textarea name="payment_instructions" rows="2"></textarea></label><label class="field span-2"><span>Notes</span><textarea name="notes" rows="2"></textarea></label></div></form>');
		f.due_date.value = v.due_date; f.due_date.min = v.invoice_date; f.payment_instructions.value = v.payment_instructions; f.notes.value = v.notes;
		var md = WD.modal({ title: 'Edit ' + v.invoice_no, body: f, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save & update PDF</button>' });
		var ok = md.el.querySelector('[data-ok]');
		ok.onclick = function () {
			WD.busy(ok, true);
			api('accounts/invoices/' + v.id, { method: 'PUT', body: { due_date: f.due_date.value, payment_instructions: f.payment_instructions.value, notes: f.notes.value } }).then(function () { md.close(); WD.toast('Invoice updated'); reload(); }, function (e) { WD.busy(ok, false); fail(f, e.message); });
		};
	}
	/** Changes the advances adjusted against an issued invoice; the old set is released and the new one applied together. */
	function advanceForm(v, reload) {
		var preset = {};
		v.allocations.forEach(function (a) { if (!a.released_at) preset[a.income_id] = (preset[a.income_id] || 0) + a.amount; });
		var payable = Math.round((v.total - v.amount_received) * 100);
		var f = el('<form><div class="alert alert-error hidden" data-err></div><div data-adv-box class="adv-modal"></div></form>');
		var adv = advancePicker(f.querySelector('[data-adv-box]'), { exclude: v.id, preset: preset, payable: function () { return payable; } });
		adv.setClient(v.client_id);
		var md = WD.modal({ title: 'Advances adjusted on ' + v.invoice_no, size: 'lg', body: f, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save & update PDF</button>' });
		var ok = md.el.querySelector('[data-ok]');
		ok.onclick = function () {
			var e = adv.error();
			if (e) return fail(f, e);
			WD.busy(ok, true);
			api('accounts/invoices/' + v.id, { method: 'PUT', body: { allocations: adv.value() } }).then(function (nv) { md.close(); WD.toast('Advances updated — ' + nv.status_label); reload(); }, function (er) { WD.busy(ok, false); fail(f, er.message); });
		};
	}
	/**
	 * One payment received from a client, spread over several open invoices (or, with
	 * opts.income, the unallocated rest of an earlier receipt). Recorded once as income;
	 * allocations settle invoices; anything left stays the client's advance.
	 * opts: { client_id, focus: invoice id to pre-fill, income: income entry to allocate }
	 */
	function receivePayment(opts, done) {
		var m = A.meta, inc = opts.income || null, rows = [], sel = {};
		var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
			'<label class="field"><span>Client <span class="req">*</span></span><select name="client_id">' + WD.selectOptions(S.clients, opts.client_id || '', 'Choose a client…') + '</select></label>' +
			'<label class="field"><span>Amount received (₹) <span class="req">*</span></span><input name="amount" inputmode="decimal"></label>' +
			(inc ? '' : '<label class="field"><span>Date received <span class="req">*</span></span><input type="date" name="received_on" value="' + esc(S.today) + '" max="' + esc(S.today) + '"></label>' +
				'<label class="field"><span>Method <span class="req">*</span></span><select name="method">' + WD.mapOptions(m.methods, 'upi') + '</select></label>' +
				'<label class="field"><span>Transaction / reference no.</span><input name="reference" maxlength="120"></label>' + destField('Received into (account)', '', 'upi') +
				'<label class="field span-2"><span>Remarks</span><input name="remarks" maxlength="500"></label>') +
			'</div><div class="toolbar"><b>Allocate to invoices</b><span class="spacer"></span><button type="button" class="btn btn-sm" data-auto>Fill oldest first</button></div><div data-rows class="card-b flush"></div><div data-sum class="card-b adv-sum"></div></form>');
		if (inc) { f.client_id.value = inc.client_id; f.client_id.disabled = true; f.amount.value = inc.available.toFixed(2); f.amount.disabled = true; }
		function amt() { return money(f.amount.value); }
		function sum() { return Object.keys(sel).reduce(function (t, k) { var a = money(sel[k]); return t + (isNaN(a) ? 0 : a); }, 0); }
		function err() {
			var e = '', a = amt();
			if (isNaN(a) || a <= 0) return 'Enter the amount received.';
			rows.forEach(function (v) { if (sel[v.id] === undefined || e) return; var x = money(sel[v.id]); if (isNaN(x) || x <= 0) e = 'Enter the amount for ' + v.invoice_no + '.'; else if (x > Math.round(v.balance * 100)) e = v.invoice_no + ' has only ' + inr(v.balance) + ' outstanding.'; });
			if (!e && sum() > a) e = 'Allocations (' + inr(sum() / 100) + ') are more than the amount received (' + inr(a / 100) + ').';
			return e;
		}
		function paintSum() {
			var a = amt(), s = sum(), e = err();
			f.querySelector('[data-sum]').innerHTML = '<dl class="kv"><dt>Received</dt><dd>' + inr((isNaN(a) ? 0 : a) / 100) + '</dd><dt>Allocated to ' + Object.keys(sel).length + ' invoice(s)</dt><dd>' + inr(s / 100) + '</dd><dt><b>Kept as client advance</b></dt><dd><b>' + inr(Math.max(0, (isNaN(a) ? 0 : a) - s) / 100) + '</b></dd></dl>' + (e && Object.keys(sel).length ? '<p class="small" style="color:var(--danger)">' + esc(e) + '</p>' : '') + '<p class="muted small">' + (inc ? 'The original receipt stays as recorded in Income and the passbook; only the allocation changes.' : 'Recorded once in Income (Client Payment) and the passbook. Allocations settle the invoices; any rest stays available as advance for later invoices.') + '</p>';
		}
		function paintRows() {
			var box = f.querySelector('[data-rows]');
			if (!f.client_id.value) { box.innerHTML = '<p class="muted small">Choose a client to list their open invoices.</p>'; paintSum(); return; }
			box.innerHTML = rows.length ? '<div class="table-wrap"><table class="tbl stack adv-tbl"><thead><tr><th style="width:36px"></th><th>Invoice</th><th class="num">Outstanding</th><th class="num">Allocate (₹)</th></tr></thead><tbody>' + rows.map(function (v) {
				var on = sel[v.id] !== undefined;
				return '<tr data-r="' + v.id + '"' + (on ? '' : ' class="dim"') + '><td><input type="checkbox" data-r-on' + (on ? ' checked' : '') + ' aria-label="Allocate"></td><td data-th="Invoice"><b>' + esc(v.invoice_no) + '</b> ' + invBadge(v) + '<div class="small muted">Dated ' + esc(fmt.date(v.invoice_date, true)) + ' · due ' + esc(fmt.date(v.due_date, true)) + ' · total ' + inr(v.total) + '</div></td><td data-th="Outstanding" class="num">' + inr(v.balance) + '</td><td data-th="Allocate" class="num">' + (on ? '<input data-r-amt inputmode="decimal" value="' + esc(sel[v.id]) + '" style="max-width:110px;text-align:right" aria-label="Amount to allocate">' : '—') + '</td></tr>';
			}).join('') + '</tbody></table></div>' : ui.emptyState('No open invoices', 'The whole amount will be kept as the client’s advance.', I.check);
			paintSum();
		}
		function load() {
			sel = {};
			if (!f.client_id.value) { rows = []; paintRows(); return; }
			f.querySelector('[data-rows]').innerHTML = ui.loading(2);
			api('accounts/clients/' + f.client_id.value + '/outstanding').then(function (r) {
				rows = r;
				var fv = rows.filter(function (v) { return v.id === opts.focus; })[0];
				if (fv) { sel[fv.id] = fv.balance.toFixed(2); if (!f.amount.value) f.amount.value = fv.balance.toFixed(2); }
				paintRows();
			}, function (e) { var b = f.querySelector('[data-rows]'); b.innerHTML = ''; b.appendChild(ui.errorBox(e, load)); });
		}
		f.client_id.onchange = load;
		f.amount.addEventListener('input', paintSum);
		on(f, 'change', '[data-r-on]', function (e, i) {
			var v = rows.filter(function (x) { return String(x.id) === i.closest('[data-r]').getAttribute('data-r'); })[0];
			if (i.checked) { var left = Math.max(0, (amt() || 0) - sum()); sel[v.id] = (Math.min(Math.round(v.balance * 100), left || Math.round(v.balance * 100)) / 100).toFixed(2); } else delete sel[v.id];
			paintRows();
		});
		on(f, 'input', '[data-r-amt]', function (e, i) { sel[i.closest('[data-r]').getAttribute('data-r')] = i.value; paintSum(); });
		f.querySelector('[data-auto]').onclick = function () {
			var left = amt() || 0; sel = {};
			rows.slice().sort(function (a, b) { return a.due_date < b.due_date ? -1 : a.due_date > b.due_date ? 1 : a.id - b.id; }).forEach(function (v) { if (left <= 0) return; var x = Math.min(left, Math.round(v.balance * 100)); sel[v.id] = (x / 100).toFixed(2); left -= x; });
			paintRows();
		};
		var md = WD.modal({ title: inc ? 'Allocate “' + inc.description + '” to invoices' : 'Record client payment', size: 'lg', body: f, sticky: true, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>' + (inc ? 'Allocate' : 'Save payment') + '</button>' });
		var ok = md.el.querySelector('[data-ok]');
		ok.onclick = function () {
			if (!f.client_id.value) return fail(f, 'Choose the client.');
			var e = err();
			if (e) return fail(f, e);
			var allocs = Object.keys(sel).map(function (k) { return { invoice_id: +k, amount: sel[k] }; });
			if (inc && !allocs.length) return fail(f, 'Tick at least one invoice.');
			WD.busy(ok, true);
			var req = inc ? function () { return api('accounts/income/' + inc.id + '/allocate', { method: 'POST', body: { allocations: allocs } }); } : function () {
				return withDuplicateCheck(function (confirm) { return api('accounts/clients/' + f.client_id.value + '/receive-payment', { method: 'POST', body: { amount: f.amount.value, received_on: f.received_on.value, method: f.method.value, reference: f.reference.value, destination: f.destination.value, save_account: saveAcc(f), remarks: f.remarks.value, allocations: allocs, confirm_duplicate: confirm } }); }, 'Possible duplicate payment');
			};
			req().then(function (r) { md.close(); WD.toast('Allocated ' + inr(r.allocated) + (r.advance_left > 0 ? ' · ' + inr(r.advance_left) + ' kept as advance' : '')); A.meta = null; meta().then(done); }, function (er) { WD.busy(ok, false); fail(f, er.message); });
		};
		if (f.client_id.value) load(); else paintRows();
	}
	/** Sends a request; on a possible duplicate (409) asks once and resends with confirm_duplicate. */
	function withDuplicateCheck(send, title) {
		return send(false).catch(function (e) {
			if (e.code !== 'ndwd_duplicate') throw e;
			return WD.confirm(title || 'Possible duplicate', e.message, 'Record anyway').then(function (y) {
				if (!y) { var x = new Error('Not saved — it looks like a duplicate.'); x.quiet = true; throw x; }
				return send(true);
			});
		});
	}
	function paymentForm(v, m, reload) {
		var f = el('<form><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
			'<label class="field"><span>Amount (₹) <span class="req">*</span></span><input name="amount" inputmode="decimal" value="' + v.balance.toFixed(2) + '"></label>' +
			'<label class="field"><span>Payment date <span class="req">*</span></span><input type="date" name="paid_on" value="' + esc(S.today) + '" max="' + esc(S.today) + '"></label>' +
			'<label class="field"><span>Method <span class="req">*</span></span><select name="method">' + WD.mapOptions(m.methods, 'upi') + '</select></label>' +
			'<label class="field"><span>Transaction / reference no.</span><input name="reference" maxlength="120"></label>' +
			destField('Received into (account)', '', 'upi') +
			'<label class="field"><span>Income Category</span><select name="source">' + m.income_sources.map(function (x) { return '<option' + (x === 'Client Payment' ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select></label>' +
			'<label class="field span-2"><span>Notes</span><textarea name="notes" rows="2"></textarea></label></div><p class="muted small">Balance due ' + inr(v.balance) + '. Part payments are fine; the status updates automatically. The payment is listed under Income and in the passbook once.</p></form>');
		var md = WD.modal({ title: 'Record payment — ' + v.invoice_no, body: f, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save payment</button>' });
		var ok = md.el.querySelector('[data-ok]');
		ok.onclick = function () {
			var a = money(f.amount.value);
			if (isNaN(a) || a <= 0) return fail(f, 'Enter an amount greater than 0.');
			if (a > Math.round(v.balance * 100)) return fail(f, 'The amount is more than the balance due.');
			WD.busy(ok, true);
			var b = { amount: f.amount.value, paid_on: f.paid_on.value, method: f.method.value, destination: f.destination.value, save_account: saveAcc(f), reference: f.reference.value, notes: f.notes.value, source: f.source.value };
			withDuplicateCheck(function (confirm) { return api('accounts/invoices/' + v.id + '/payments', { method: 'POST', body: Object.assign({ confirm_duplicate: confirm }, b) }); }, 'Possible duplicate payment').then(function (nv) { md.close(); WD.toast('Payment recorded — ' + nv.status_label); reload(); }, function (e) { WD.busy(ok, false); fail(f, e.message); });
		};
	}

	/**
	 * WhatsApp / email review. WhatsApp click-to-chat cannot attach files, so the PDF goes
	 * as a secure link (optional) or the user attaches the downloaded PDF themselves.
	 * Opening a draft is never reported as "sent". Email recipients can be changed for one
	 * send without changing the invoice or the client.
	 */
	function shareModal(v, channel, m) {
		var withLink = !!+m.settings.include_pdf_link, recip = null, recipList = null;
		var body = el('<div><div data-sbody>' + ui.loading(3) + '</div></div>');
		var md = WD.modal({ title: channel === 'whatsapp' ? 'WhatsApp ' + v.client_name : 'Email ' + v.client_name, size: 'lg', body: body, foot: '<button type="button" class="btn" data-close>Close</button>' });
		function load() {
			if (recip) { try { recipList = recip.getValue(); } catch (e) { recipList = null; } }
			return api('accounts/invoices/' + v.id + '/share', { params: { link: withLink ? 1 : 0 } }).then(paint, function (e) { var b = body.querySelector('[data-sbody]'); b.innerHTML = ''; b.appendChild(ui.errorBox(e, load)); });
		}
		function mailto(list, x) {
			var to = list.filter(function (e) { return e.type === 'to'; }).map(function (e) { return encodeURIComponent(e.email); }), cc = list.filter(function (e) { return e.type === 'cc'; }).map(function (e) { return encodeURIComponent(e.email); });
			return to.length ? 'mailto:' + to.join(',') + '?' + (cc.length ? 'cc=' + cc.join(',') + '&' : '') + 'subject=' + encodeURIComponent(x.subject) + '&body=' + encodeURIComponent(x.body) : '';
		}
		function paint(sh) {
			var b = body.querySelector('[data-sbody]');
			var x = sh[channel];
			var linkBox = '<label class="check" style="margin:8px 0"><input type="checkbox" data-link' + (withLink ? ' checked' : '') + '> Add a secure download link to the PDF (works for 30 days, no login needed)</label>';
			var dl = '<button type="button" class="btn" data-dl>' + I.download + ' Download PDF</button>';
			if (channel === 'whatsapp') {
				b.innerHTML = (x.missing ? '<div class="alert alert-warn">' + esc(x.missing) + '</div>' : '<dl class="kv"><dt>To (billing WhatsApp)</dt><dd>+' + esc(x.number) + '</dd></dl>') +
					'<label class="field"><span>Message (review before sending)</span><textarea rows="11" readonly data-msg>' + esc(x.text) + '</textarea></label>' + linkBox +
					'<div class="alert alert-info small">WhatsApp opens with this message filled in; you press Send there. WhatsApp links cannot attach files, so the PDF is not attached automatically — ' + (withLink ? 'the message includes the secure PDF link' : 'download the PDF and attach it in the chat before sending') + '.' + (x.group_url ? ' Group invite links cannot fill in a message: use <b>Copy message</b>, open the billing group and paste it. Nothing is sent and nobody is invited automatically.' : '') + '</div>' +
					'<div class="inv-actions">' + dl + (x.url ? '<a class="btn btn-wa" data-open href="' + esc(x.url) + '" target="_blank" rel="noopener noreferrer">' + I.wa + ' Open WhatsApp</a>' : '') + (x.group_url ? '<button type="button" class="btn" data-copy>Copy message</button><a class="btn btn-wa" data-open-group href="' + esc(x.group_url) + '" target="_blank" rel="noopener noreferrer">' + I.wa + ' Open billing group</a>' : '') + '</div>';
			} else {
				var list = recipList || x.recipients || [];
				b.innerHTML = (x.missing && !list.length ? '<div class="alert alert-warn">' + esc(x.missing) + '</div>' : '') +
					'<div class="field"><span>Recipients <span class="hint">— To and CC for this email only; the invoice and the client’s saved emails do not change</span></span><div data-recip></div>' + (x.client_recipients && JSON.stringify(x.client_recipients) !== JSON.stringify(list) ? '<button type="button" class="btn btn-sm btn-ghost" data-reset-recip>Use the client’s current billing emails</button>' : '') + '</div>' +
					'<dl class="kv"><dt>Subject</dt><dd>' + esc(x.subject) + '</dd></dl>' +
					'<label class="field"><span>Message (review before sending)</span><textarea rows="12" readonly>' + esc(x.body) + '</textarea></label>' + linkBox +
					'<div class="alert alert-info small"><b>Open email draft</b> opens your mail app with this message and the To / CC addresses; attach the downloaded PDF yourself, then send. ' + (x.can_send || sh.email.to_list ? (m.email_enabled ? '<b>Send with PDF attached</b> sends it now through WorkDesk’s email setup with the PDF attached.' : 'Sending from WorkDesk is unavailable until email is enabled in Settings.') : '') + '</div>' +
					'<div class="inv-actions">' + dl + '<a class="btn" data-open data-mailto href="#">' + I.mail + ' Open email draft</a>' + (m.email_enabled ? '<button type="button" class="btn btn-primary" data-send>' + I.mail + ' Send with PDF attached</button>' : '') + '</div><div data-result></div>';
				recip = WD.emailListEditor(list, { allowEmpty: true });
				b.querySelector('[data-recip]').appendChild(recip);
				var rs = b.querySelector('[data-reset-recip]');
				if (rs) rs.onclick = function () { recip.setValue(x.client_recipients); rs.remove(); };
				b.querySelector('[data-mailto]').addEventListener('click', function (e) {
					var l;
					try { l = recip.getValue(); } catch (er) { e.preventDefault(); return WD.toast(er.message, 'error'); }
					var href = mailto(l, x);
					if (!href) { e.preventDefault(); return WD.toast('Add at least one “To” address.', 'error'); }
					this.href = href;
				});
			}
			b.querySelector('[data-link]').onchange = function (e) { withLink = e.target.checked; load(); };
			b.querySelector('[data-dl]').onclick = function (e) { WD.download('accounts/invoices/' + v.id + '/pdf', { button: e.currentTarget, busyLabel: 'Downloading…' }).catch(function () {}); };
			var cp = b.querySelector('[data-copy]');
			if (cp) cp.onclick = function () {
				var ta = b.querySelector('[data-msg]');
				(navigator.clipboard && navigator.clipboard.writeText ? navigator.clipboard.writeText(ta.value) : Promise.reject()).then(function () { WD.toast('Message copied — paste it in the WhatsApp group'); }, function () { ta.removeAttribute('readonly'); ta.select(); WD.toast('Press Ctrl+C to copy the selected message'); });
			};
			$$('[data-open],[data-open-group]', b).forEach(function (op) {
				op.addEventListener('click', function () { api('accounts/invoices/' + v.id + '/share-log', { method: 'POST', body: { channel: channel } }).catch(function () {}); WD.toast(channel === 'whatsapp' ? 'WhatsApp opened — press Send there to deliver the message' : 'Email draft opened — attach the PDF and send it from your mail app'); });
			});
			var sd = b.querySelector('[data-send]');
			if (sd) sd.onclick = function () {
				var l;
				try { l = recip.getValue(); } catch (er) { return WD.toast(er.message, 'error'); }
				var to = l.filter(function (e) { return e.type === 'to'; }).map(function (e) { return e.email; }), cc = l.filter(function (e) { return e.type === 'cc'; }).map(function (e) { return e.email; });
				if (!to.length) return WD.toast('Add at least one “To” address.', 'error');
				WD.confirm('Send invoice email?', 'Send ' + v.invoice_no + ' to ' + to.join(', ') + (cc.length ? ' (CC ' + cc.join(', ') + ')' : '') + ' now, with the PDF attached?', 'Send email').then(function (ok) {
					if (!ok) return;
					WD.busy(sd, true, 'Sending…');
					api('accounts/invoices/' + v.id + '/email', { method: 'POST', body: { link: withLink, recipients: l } }).then(function (r) {
						WD.busy(sd, false);
						b.querySelector('[data-result]').innerHTML = '<div class="alert alert-info">' + esc(r.message) + '</div>';
						WD.toast(r.message);
					}, function (e) {
						WD.busy(sd, false);
						b.querySelector('[data-result]').innerHTML = '<div class="alert alert-error">' + esc(e.message) + ' The email was not sent.</div>';
					});
				});
			};
		}
		load();
		return md;
	}

	/* ------------------------------------------------------------------ *
	 * Income
	 * ------------------------------------------------------------------ */
	A.incf = {};
	/** Income filters sent to the server (the visible list, totals and exports all use exactly these). */
	function incParams(f) {
		var out = {};
		['fy', 'from', 'to', 'q', 'client_id', 'project_id', 'source', 'method', 'kind', 'state'].forEach(function (k) { if (f[k]) out[k] = f[k]; });
		// A single date bound still filters: open end = end of time, open start = start of records.
		if (out.from && !out.to) out.to = '2099-12-31';
		if (out.to && !out.from) out.from = '2000-01-01';
		return out;
	}
	function monthBounds(d) {
		var y = +d.slice(0, 4), m = +d.slice(5, 7), last = new Date(Date.UTC(y, m, 0)).getUTCDate();
		return [d.slice(0, 7) + '-01', d.slice(0, 7) + '-' + String(last).padStart(2, '0')];
	}
	TAB.income = function (b) {
		var f = A.incf, m = A.meta;
		if (!('fy' in f)) { f.fy = m.current_fy; f.state = 'active'; }
		var opt = function (v, l, sel) { return '<option value="' + esc(v) + '"' + (String(v) === String(sel || '') ? ' selected' : '') + '>' + esc(l) + '</option>'; };
		var projOpts = function () { return S.projects.filter(function (p) { return !f.client_id || +p.client_id === +f.client_id; }); };
		b.innerHTML = '<section class="card filter-card"><div class="card-b"><div class="filter-grid">' +
				searchBox('data-iq', f.q, 'Description, reference / transaction ID, client, project, category, invoice') +
			'<label class="field"><span>Financial year</span><select data-if="fy"><option value="">All years</option>' + fyOptions(m.fy_list, f.fy) + '</select></label>' +
			'<label class="field"><span>From</span><input type="date" data-if="from" value="' + esc(f.from || '') + '"></label>' +
			'<label class="field"><span>To</span><input type="date" data-if="to" value="' + esc(f.to || '') + '"></label>' +
			'<label class="field"><span>Client</span><select data-if="client_id">' + WD.selectOptions(S.clients, f.client_id || '', 'All clients') + '</select></label>' +
			'<label class="field"><span>Project</span><select data-if="project_id">' + WD.selectOptions(projOpts(), f.project_id || '', 'All projects') + '</select></label>' +
			'<label class="field"><span>Income Category</span><select data-if="source"><option value="">All categories</option>' + m.income_sources.map(function (x) { return opt(x, x, f.source); }).join('') + '</select></label>' +
			'<label class="field"><span>Payment method</span><select data-if="method"><option value="">All methods</option>' + WD.mapOptions(m.methods, f.method || '') + '</select></label>' +
			'<label class="field"><span>Type</span><select data-if="kind">' + opt('', 'Invoice-linked & manual', f.kind) + opt('invoice', 'Invoice-linked (payments)', f.kind) + opt('manual', 'Manual income', f.kind) + opt('advance', 'Advances adjusted on invoices', f.kind) + '</select></label>' +
			'<label class="field"><span>Status</span><select data-if="state">' + opt('active', 'Active', f.state) + opt('voided', 'Voided', f.state) + opt('all', 'Active & voided', f.state) + '</select></label>' +
			'</div><div class="filter-foot"><span class="muted small" data-range></span><span class="spacer"></span><button type="button" class="btn btn-sm" data-month>This month</button><button type="button" class="btn btn-sm" data-cleardates' + (f.from || f.to ? '' : ' disabled') + '>Clear dates</button><button type="button" class="btn btn-sm btn-ghost" data-reset>Reset filters</button></div></div></section>' +
			'<div class="toolbar"><span class="spacer"></span><button class="btn btn-sm" data-inc-x="csv">CSV</button><button class="btn btn-sm" data-inc-x="xlsx">Excel</button><button class="btn btn-sm" data-inc-x="pdf">PDF</button><button class="btn btn-primary" data-add>' + I.plus + ' Record income</button></div><div data-body>' + ui.loading(5) + '</div>';
		var redraw = function () { TAB.income(b); };
		bindSearch(b, '[data-iq]', function (v) { f.q = v; load(); });
		$$('[data-if]', b).forEach(function (s) {
			s.addEventListener(s.type === 'search' ? 'input' : 'change', WD.u.debounce(function () {
				var k = s.getAttribute('data-if');
				f[k] = s.value;
				// The FY and a date range are never combined silently: dates replace the FY
				// (remembered for "Clear dates"); choosing an FY clears the dates.
				if ((k === 'from' || k === 'to') && s.value) { if (f.fy) f._fy = f.fy; f.fy = ''; }
				if (k === 'fy') { f.from = ''; f.to = ''; f._fy = ''; }
				if (k === 'client_id' && f.project_id && !projOpts().some(function (p) { return String(p.id) === String(f.project_id); })) f.project_id = '';
				if (s.type === 'search') load(); else redraw();
			}, s.type === 'search' ? 300 : 0));
		});
		b.querySelector('[data-month]').onclick = function () { var r = monthBounds(S.today); if (f.fy) f._fy = f.fy; f.fy = ''; f.from = r[0]; f.to = r[1]; redraw(); };
		b.querySelector('[data-cleardates]').onclick = function () { f.from = ''; f.to = ''; f.fy = f._fy || m.current_fy; f._fy = ''; redraw(); };
		b.querySelector('[data-reset]').onclick = function () { A.incf = { sort: f.sort, dir: f.dir }; TAB.income(b); };
		var body = b.querySelector('[data-body]'), items = [], last = null;
		if (!f.sort) { f.sort = 'date'; f.dir = 'desc'; }
		var INC_COLS = { date: [function (x) { return x.received_on; }, 'date'], desc: [function (x) { return x.description; }, 'text'], client: [function (x) { return x.client_name + ' ' + x.project_name; }, 'text'], invoice: [function (x) { return x.invoice ? x.invoice.invoice_no : ''; }, 'text'], method: [function (x) { return x.method_label; }, 'text'], amount: [function (x) { return x.amount; }, 'num'] };
		bindSort(body, f, INC_COLS, function () { paint(last); });
		b.querySelector('[data-add]').onclick = function () { incomeForm(null, load); };
		on(b, 'click', '[data-inc-x]', function (e, btn) { WD.download('accounts/export', { body: Object.assign({ report: 'income', format: btn.getAttribute('data-inc-x'), state: f.state || 'active' }, incParams(f)), button: btn }).catch(function () {}); });
		function rangeText(r) {
			if (f.fy) return 'Showing FY ' + f.fy + ' (' + fmt.date(r.from, true) + ' – ' + fmt.date(r.to, true) + ')';
			if (f.from || f.to) return 'Showing ' + (f.from ? fmt.date(f.from, true) : 'start') + ' – ' + (f.to ? fmt.date(f.to, true) : 'latest') + ' (custom dates)';
			return 'Showing all dates';
		}
		function load() {
			return api('accounts/income', { params: incParams(f) }).then(function (r) {
				items = r.items; last = r;
				b.querySelector('[data-range]').textContent = rangeText(r) + ' · ' + r.count + ' entr' + (r.count === 1 ? 'y' : 'ies') + (f.q ? ' matching “' + f.q + '”' : '');
				paint(r);
			}, function (e) { body.innerHTML = ''; body.appendChild(ui.errorBox(e, load)); });
		}
		function paint(r) {
				var rows = sortRows(items, f, INC_COLS);
				body.innerHTML = '<p class="page-note">Income is money actually received. Payments recorded on invoices appear here automatically, once; use <b>Record income</b> for money not tied to an invoice (e.g. an advance), or pick a related invoice to record it as that invoice’s payment. Advances adjusted against invoices stay here as the original receipt — they are never counted again.</p>' +
					'<div class="stats acc-kpis">' + kpi('Total received', inr(r.totals.all), items.filter(function (x) { return !x.voided_at; }).length + ' active entr' + (items.length === 1 ? 'y' : 'ies'), 'var(--st-done)') + kpi('Received for invoices', inr(r.totals.received_invoices), inr(r.totals.invoice_payments) + ' paid on invoices · ' + inr(r.totals.allocated) + ' advances adjusted', 'var(--brand-3)') + kpi('Other / unallocated income', inr(r.totals.other), 'Manual income not adjusted on invoices (' + inr(r.totals.manual) + ' manual in total)', 'var(--brand-1)') + kpi('Advance available', inr(r.totals.unallocated), inr(r.totals.allocated) + ' adjusted on invoices (not new income)', 'var(--brand-2)') + '</div>' +
					'<div class="grid grid-main"><section class="card"><div class="card-h"><h2>Income</h2></div>' +
					(items.length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr>' + sortTh(f, 'date', 'Date') + sortTh(f, 'desc', 'Description') + sortTh(f, 'client', 'Client / project') + sortTh(f, 'invoice', 'Invoice') + sortTh(f, 'method', 'Method') + sortTh(f, 'amount', 'Amount', 'num') + '<th></th></tr></thead><tbody>' + rows.map(function (x) {
						var al = x.allocations && x.allocations.length ? '<div class="small adv-note">Adjusted: ' + x.allocations.map(function (a) { return invLink(a.invoice) + ' ' + inr(a.amount); }).join(', ') + (x.available !== undefined ? ' · available ' + inr(x.available) : '') + '</div>' : (x.kind === 'manual' && x.client_id && !x.voided_at ? '<div class="small muted">Available to adjust on invoices</div>' : '');
						return '<tr' + (x.voided_at ? ' class="voided"' : '') + '><td data-th="Date">' + esc(fmt.date(x.received_on, true)) + '</td><td data-th="Description"><b>' + esc(x.description) + '</b><div class="small"><span class="badge">' + esc(x.source) + '</span>' + (x.kind === 'invoice_payment' ? ' <span class="badge s-completed">Invoice payment</span>' : '') + '</div>' + al + (x.remarks ? '<div class="muted small">' + esc(x.remarks) + '</div>' : '') + (x.voided_at ? '<div class="small" style="color:var(--danger)">Voided: ' + esc(x.void_reason) + '</div>' : '') + '</td>' +
							'<td data-th="Client / project">' + (x.client_name ? esc(x.client_name) : '<span class="muted">No client</span>') + (x.project_name ? '<div class="muted small">' + esc(x.project_name) + '</div>' : '') + '</td><td data-th="Invoice">' + (x.invoice ? invLink(x.invoice) : '<span class="muted">—</span>') + '</td>' +
							'<td data-th="Method">' + esc(x.method_label || '—') + (x.reference ? '<div class="muted small">Ref ' + esc(x.reference) + '</div>' : '') + (x.destination ? '<div class="muted small">' + esc(x.destination) + '</div>' : '') + '</td><td data-th="Amount" class="num amt-in">' + inr(x.amount) + '</td>' +
							'<td class="actions">' + (x.voided_at ? '' : (x.kind === 'manual' && x.client_id && x.available > 0 ? '<button class="btn btn-sm" data-alloc="' + x.id + '" title="Allocate the available amount to invoices">Allocate</button>' : '') + '<button class="btn btn-sm" data-edit="' + x.id + '">Edit</button><button class="btn btn-sm btn-ghost" data-void="' + x.id + '">Void</button>') + '</td></tr>';
					}).join('') + '</tbody></table></div>' : ui.emptyState('No income matches these filters', 'Change or reset the filters, or record income received.', I.rupee)) + '</section>' +
					'<div class="grid" style="align-content:start"><section class="card"><div class="card-h"><h2>By category</h2></div><div class="table-wrap"><table class="tbl"><tbody>' + (r.by_source.length ? r.by_source.map(function (x) { return '<tr><td>' + esc(x.source) + '</td><td class="num">' + inr(x.total) + '</td></tr>'; }).join('') : '<tr><td class="muted">—</td></tr>') + '</tbody></table></div></section>' +
					'<section class="card"><div class="card-h"><h2>By month</h2></div><div class="table-wrap"><table class="tbl"><tbody>' + (r.by_month.length ? r.by_month.map(function (x) { return '<tr><td>' + esc(x.month) + '</td><td class="num">' + inr(x.total) + '</td></tr>'; }).join('') : '<tr><td class="muted">—</td></tr>') + '</tbody></table></div></section></div></div>';
		}
		on(b, 'click', '[data-edit]', function (e, btn) { incomeForm(items.filter(function (x) { return x.id === +btn.getAttribute('data-edit'); })[0], load); });
		on(b, 'click', '[data-alloc]', function (e, btn) { var x = items.filter(function (y) { return y.id === +btn.getAttribute('data-alloc'); })[0]; receivePayment({ client_id: x.client_id, income: x }, load); });
		on(b, 'click', '[data-void]', function (e, btn) {
			var x = items.filter(function (y) { return y.id === +btn.getAttribute('data-void'); })[0];
			var adj = x && x.allocations && x.allocations.length ? ' It is adjusted against ' + x.allocations.map(function (a) { return a.invoice ? a.invoice.invoice_no : ''; }).join(', ') + '; those adjustments are released and the invoice balances go back up.' : '';
			reasonBox('Void income', x && x.kind === 'invoice_payment' ? 'This is a payment on ' + x.invoice.invoice_no + '. Voiding it voids the payment too, so the invoice balance goes back up. It stays in the history marked voided.' : 'The entry stays in the history marked voided and leaves all totals and the passbook.' + adj, 'Void', function (reason) { return api('accounts/income/' + x.id + '/void', { method: 'POST', body: { reason: reason } }).then(load); });
		});
		return load();
	};
	function incomeForm(x, done) {
		var m = A.meta, linked = x && x.kind === 'invoice_payment';
		var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div>' + (linked ? '<div class="alert alert-info small">This income is a payment on ' + invLink(x.invoice) + '. Its amount, date, method and reference belong to the payment — void it and record it again to correct them.</div>' : '') + '<div class="form-grid">' +
			'<label class="field"><span>Amount received (₹) <span class="req">*</span></span><input name="amount" inputmode="decimal"></label>' +
			'<label class="field"><span>Date received <span class="req">*</span></span><input type="date" name="received_on" max="' + esc(S.today) + '"></label>' +
			'<label class="field span-2"><span>Description / reason <span class="req">*</span></span><input name="description" maxlength="255" placeholder="e.g. Advance for November social media"></label>' +
			'<div class="field"><label for="ndwd-inc-cat" class="label">Income Category</label><select id="ndwd-inc-cat" name="cat">' + m.income_sources.map(function (s) { return '<option>' + esc(s) + '</option>'; }).join('') + '<option value="__custom">Custom Category…</option></select><input name="source" maxlength="80" placeholder="New category name" class="cat-custom" hidden aria-label="Custom category name"></div>' +
			'<label class="field"><span>Payment method <span class="req">*</span></span><select name="method"><option value="">—</option>' + WD.mapOptions(m.methods, '') + '</select></label>' +
			'<label class="field"><span>Reference / transaction no. <span class="hint">(optional)</span></span><input name="reference" maxlength="120"></label>' +
			destField('Received into (account)', x ? x.destination : '', x ? x.method : '') +
			'<label class="field"><span>Client <span class="hint">(optional)</span></span><select name="client_id">' + WD.selectOptions(S.clients, '', 'No client') + '</select></label>' +
			'<label class="field"><span>Project <span class="hint">(optional)</span></span><select name="project_id"></select></label>' +
			'<label class="field span-2"><span>Related invoice <span class="hint">(optional — records this as a payment on the invoice)</span></span><select name="invoice_id"><option value="">None — manual income</option></select></label>' +
			'<label class="field span-2"><span>Remarks <span class="hint">(optional)</span></span><textarea name="remarks" rows="2"></textarea></label>' +
			'</div><p class="muted small" data-inv-note>Client and project are independent: choose neither, either or both.</p></form>');
		['description', 'source', 'reference', 'remarks', 'received_on'].forEach(function (k) { f[k].value = x ? (x[k] || '') : ''; });
		f.amount.value = x ? x.amount.toFixed(2) : '';
		if (!x) { f.received_on.value = S.today; f.source.value = 'Other Income'; }
		// Income Category: a built-in or saved custom one, or "Custom Category…" to name a new one (saved for next time).
		var syncCat = function () { var cu = f.cat.value === '__custom'; f.source.hidden = !cu; if (!cu) f.source.value = f.cat.value; else { if (m.income_sources.indexOf(f.source.value) >= 0) f.source.value = ''; f.source.focus(); } };
		if (m.income_sources.indexOf(f.source.value) < 0) { f.cat.value = '__custom'; f.source.hidden = false; } else f.cat.value = f.source.value;
		f.cat.onchange = syncCat;
		f.method.value = x ? x.method || '' : '';
		f.client_id.value = x && x.client_id ? x.client_id : '';
		var invoices = [];
		function paintProjects(keep) {
			var cid = f.client_id.value;
			var list = S.projects.filter(function (p) { return (p.is_active == 1 || String(p.id) === String(keep)) && (!cid || !p.client_id || +p.client_id === +cid); });
			f.project_id.innerHTML = WD.selectOptions(list, list.some(function (p) { return String(p.id) === String(keep); }) ? keep : '', 'No project', function (p) { return p.client ? p.name + ' — ' + p.client : p.name; });
		}
		function paintInvoices() {
			var cid = f.client_id.value, keep = f.invoice_id.value;
			var list = invoices.filter(function (v) { return (v.status === 'unpaid' || v.status === 'partial') && (!cid || +v.client_id === +cid); });
			f.invoice_id.innerHTML = '<option value="">None — manual income</option>' + list.map(function (v) { return '<option value="' + v.id + '"' + (String(v.id) === keep ? ' selected' : '') + '>' + esc(v.invoice_no + ' · ' + v.client_name + ' · balance ' + inr(v.balance)) + '</option>'; }).join('');
			syncInvoice();
		}
		function syncInvoice() {
			var v = invoices.filter(function (y) { return String(y.id) === f.invoice_id.value; })[0];
			f.querySelector('[data-inv-note]').innerHTML = v ? 'Recorded as a payment on <b>' + esc(v.invoice_no) + '</b> (balance ' + inr(v.balance) + '). The invoice status and balance update, and the money is counted once.' : 'Client and project are independent: choose neither, either or both.';
			if (v && !f.client_id.value) { f.client_id.value = v.client_id; paintProjects(f.project_id.value); }
		}
		paintProjects(x && x.project_id ? x.project_id : '');
		f.client_id.onchange = function () { paintProjects(f.project_id.value); paintInvoices(); };
		f.invoice_id.onchange = syncInvoice;
		if (linked) {
			['amount', 'received_on', 'method', 'reference', 'destination', 'client_id', 'invoice_id'].forEach(function (k) { f[k].disabled = true; });
			f.invoice_id.innerHTML = '<option>' + esc(x.invoice.invoice_no) + '</option>';
		} else if (x) {
			f.invoice_id.closest('label').hidden = true;
		} else {
			api('accounts/invoices').then(function (list) { invoices = list; paintInvoices(); }).catch(function () {});
		}
		var md = WD.modal({ title: x ? 'Edit income' : 'Record income', size: 'lg', body: f, sticky: true, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save</button>' });
		var ok = md.el.querySelector('[data-ok]');
		ok.onclick = function () {
			var b = {};
			['description', 'source', 'remarks', 'project_id'].forEach(function (k) { b[k] = f[k].value; });
			if (!linked) ['amount', 'received_on', 'method', 'reference', 'destination', 'client_id', 'invoice_id'].forEach(function (k) { b[k] = f[k].value; });
			if (!linked) b.save_account = saveAcc(f);
			if (!b.description.trim()) return fail(f, 'Enter the income description / reason.');
			if (!b.source.trim()) return fail(f, 'Enter the name of the custom income category.');
			if (!linked) {
				var a = money(b.amount);
				if (isNaN(a) || a <= 0) return fail(f, 'Enter an amount greater than 0.');
				if (!b.received_on) return fail(f, 'Enter the date the money was received.');
				if (!b.method) return fail(f, 'Choose the payment method.');
				var v = invoices.filter(function (y) { return String(y.id) === String(b.invoice_id); })[0];
				if (v && a > Math.round(v.balance * 100)) return fail(f, 'The amount is more than the balance due on ' + v.invoice_no + ' (' + inr(v.balance) + ').');
			}
			WD.busy(ok, true);
			withDuplicateCheck(function (confirm) { return api(x ? 'accounts/income/' + x.id : 'accounts/income', { method: x ? 'PUT' : 'POST', body: Object.assign({ confirm_duplicate: confirm }, b) }); }, 'Possible duplicate income').then(function (r) {
				md.close(); WD.toast(r.kind === 'invoice_payment' && !x ? 'Payment recorded on ' + r.invoice.invoice_no : 'Income saved'); A.meta = null; meta().then(done);
			}, function (e) { WD.busy(ok, false); fail(f, e.message); });
		};
	}

	/* ------------------------------------------------------------------ *
	 * Expenses
	 * ------------------------------------------------------------------ */
	A.exf = {};
	TAB.expenses = function (b) {
		var f = A.exf, m = A.meta;
		if (!('fy' in f)) { f.fy = m.current_fy; f.sort = 'date'; f.dir = 'desc'; }
		b.innerHTML = '<div class="toolbar acc-search">' + searchBox('data-eq', f.q, 'Description, vendor / payee, reference, client, project, category') + '<select data-ef="fy" style="width:auto"><option value="">All years</option>' + fyOptions(m.fy_list, f.fy) + '</select><input type="month" data-ef="month" value="' + esc(f.month || '') + '" style="width:auto" aria-label="Month"><select data-ef="category" style="width:auto"><option value="">All categories</option>' + m.categories.map(function (x) { return '<option' + (x === f.category ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select><select data-ef="status" style="width:auto"><option value="">Paid & unpaid</option><option value="paid"' + (f.status === 'paid' ? ' selected' : '') + '>Paid</option><option value="unpaid"' + (f.status === 'unpaid' ? ' selected' : '') + '>Unpaid</option></select><span class="spacer"></span><button class="btn btn-sm" data-exp-x="csv">CSV</button><button class="btn btn-sm" data-exp-x="xlsx">Excel</button><button class="btn btn-sm" data-exp-x="pdf">PDF</button><button class="btn btn-primary" data-add>' + I.plus + ' Add expense</button></div><div data-body>' + ui.loading(5) + '</div>';
		$$('[data-ef]', b).forEach(function (s) { s.onchange = function () { f[s.getAttribute('data-ef')] = s.value; if (s.getAttribute('data-ef') === 'month' && s.value) f.fy = ''; load(); }; });
		var body = b.querySelector('[data-body]'), last = null;
		bindSearch(b, '[data-eq]', function (v) { f.q = v; load(); });
		var EXP_COLS = { date: [function (x) { return x.expense_date; }, 'date'], title: [function (x) { return x.title; }, 'text'], category: [function (x) { return x.category; }, 'text'], vendor: [function (x) { return x.vendor; }, 'text'], amount: [function (x) { return x.amount; }, 'num'], status: [function (x) { return x.status; }, 'text'] };
		bindSort(body, f, EXP_COLS, function () { paint(last); });
		b.querySelector('[data-add]').onclick = function () { expenseForm(null, load); };
		on(b, 'click', '[data-exp-x]', function (e, btn) { WD.download('accounts/export', { body: Object.assign({ report: 'expenses', format: btn.getAttribute('data-exp-x') }, expParams()), button: btn }).catch(function () {}); });
		var items = [];
		function load() {
			return api('accounts/expenses', { params: expParams() }).then(function (r) {
				items = r.items; last = r; paint(r);
			}, function (e) { body.innerHTML = ''; body.appendChild(ui.errorBox(e, load)); });
		}
		function expParams() { var o = {}; ['fy', 'month', 'category', 'status', 'q'].forEach(function (k) { if (f[k]) o[k] = f[k]; }); return o; }
		function paint(r) {
				body.innerHTML = '<div class="grid grid-main"><section class="card"><div class="card-h"><h2>Expenses</h2>' + (f.q ? '<span class="muted small">' + r.items.length + ' matching “' + esc(f.q) + '”</span>' : '') + '<span class="spacer"></span><b>' + inr(r.total) + '</b></div>' +
					(r.items.length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr>' + sortTh(f, 'date', 'Date') + sortTh(f, 'title', 'Expense') + sortTh(f, 'category', 'Category') + sortTh(f, 'vendor', 'Vendor') + sortTh(f, 'amount', 'Amount', 'num') + sortTh(f, 'status', 'Status') + '<th></th></tr></thead><tbody>' + sortRows(r.items, f, EXP_COLS).map(function (x) {
						return '<tr><td data-th="Date">' + esc(fmt.date(x.expense_date, true)) + '</td><td data-th="Expense"><b>' + esc(x.title) + '</b>' + (x.description ? '<div class="muted small">' + esc(x.description) + '</div>' : '') + (x.reference ? '<div class="muted small">Ref ' + esc(x.reference) + '</div>' : '') + (x.billable ? '<div class="small"><span class="badge s-in_process">Billable ' + inr(x.billable_amount) + '</span> ' + (x.billed_in.length ? '<span class="muted">billed in ' + x.billed_in.map(invLink).join(', ') + '</span>' : '<span class="muted">not invoiced yet</span>') + '</div>' : '') + '</td><td data-th="Category">' + esc(x.category) + '</td><td data-th="Vendor">' + esc(x.vendor || '—') + '</td><td data-th="Amount" class="num">' + inr(x.amount) + '</td><td data-th="Status">' + (x.status === 'paid' ? '<span class="badge s-completed">Paid</span><div class="muted small">' + esc(fmt.date(x.paid_on)) + ' · ' + esc(x.method_label) + '</div>' : '<span class="badge s-pending">Unpaid</span>') + '</td><td class="actions">' + (x.has_receipt ? '<button class="btn btn-sm btn-ghost" data-rcpt="' + x.id + '" title="Download receipt">' + I.file + '</button>' : '') + '<button class="btn btn-sm" data-edit="' + x.id + '">Edit</button></td></tr>';
					}).join('') + '</tbody></table></div>' : ui.emptyState('No expenses in this view', 'Add expenses as they happen. They stay unpaid until you mark them paid.', I.rupee)) + '</section>' +
					'<div class="grid" style="align-content:start"><section class="card"><div class="card-h"><h2>By category</h2></div><div class="table-wrap"><table class="tbl"><tbody>' + (r.by_category.length ? r.by_category.map(function (x) { return '<tr><td>' + esc(x.category) + '</td><td class="num">' + inr(x.total) + '</td></tr>'; }).join('') : '<tr><td class="muted">—</td></tr>') + '</tbody></table></div></section>' +
					'<section class="card"><div class="card-h"><h2>By month</h2></div><div class="table-wrap"><table class="tbl"><tbody>' + (r.by_month.length ? r.by_month.map(function (x) { return '<tr><td>' + esc(x.month) + '</td><td class="num">' + inr(x.total) + '</td></tr>'; }).join('') : '<tr><td class="muted">—</td></tr>') + '</tbody></table></div></section></div></div>';
		}
		on(b, 'click', '[data-edit]', function (e, btn) { expenseForm(items.filter(function (x) { return x.id === +btn.getAttribute('data-edit'); })[0], load); });
		on(b, 'click', '[data-rcpt]', function (e, btn) { WD.download('accounts/expenses/' + btn.getAttribute('data-rcpt') + '/receipt', { button: btn, busyLabel: '…' }).catch(function () {}); });
		return load();
	};
	function expenseForm(x, done) {
		var m = A.meta;
		var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
			'<label class="field span-2"><span>Expense title <span class="req">*</span></span><input name="title" maxlength="200"></label>' +
			'<label class="field"><span>Amount (₹) <span class="req">*</span></span><input name="amount" inputmode="decimal"></label>' +
			'<label class="field"><span>Expense date <span class="req">*</span></span><input type="date" name="expense_date"></label>' +
			'<label class="field"><span>Category</span><input name="category" list="ndwd-cats" maxlength="80" placeholder="Choose or type a new category"><datalist id="ndwd-cats">' + m.categories.map(function (c) { return '<option value="' + esc(c) + '">'; }).join('') + '</datalist></label>' +
			'<label class="field"><span>Vendor / payee</span><input name="vendor" maxlength="160"></label>' +
			'<label class="field"><span>Payment status</span><select name="status"><option value="unpaid">Unpaid</option><option value="paid">Paid</option></select></label>' +
			'<label class="field"><span>Paid on</span><input type="date" name="paid_on"></label>' +
			'<label class="field"><span>Payment method</span><select name="method"><option value="">—</option>' + WD.mapOptions(m.methods, '') + '</select></label>' +
			'<label class="field"><span>Transaction reference</span><input name="reference" maxlength="120"></label>' +
			destField('Paid from (account)', x ? x.destination : '', x ? x.method : '') +
			'<label class="field"><span>Client (optional)</span><select name="client_id">' + WD.selectOptions(S.clients, '', 'No client') + '</select></label>' +
			'<label class="field"><span>Project (optional)</span><select name="project_id">' + WD.selectOptions(S.projects, '', 'No project') + '</select></label>' +
			'<div class="field span-2 bill-toggle"><label class="check"><input type="checkbox" name="billable"> <b>Billable to client</b> <span class="hint">— can be claimed on one invoice as a reimbursable expense; it stays in Expenses either way</span></label></div>' +
			'<label class="field" data-bill-f><span>Billable amount (₹)</span><input name="billable_amount" inputmode="decimal" placeholder="Same as expense amount"></label>' +
			'<label class="field" data-bill-f><span>Billing remarks <span class="hint">(printed on the invoice)</span></span><input name="billing_remarks" maxlength="255"></label>' +
			(x && x.billed_in && x.billed_in.length ? '<div class="span-2 alert alert-info small">Billed in ' + x.billed_in.map(invLink).join(', ') + '. Its client and billable status are locked until that invoice is cancelled.</div>' : '') +
			'<label class="field span-2"><span>Description</span><textarea name="description" rows="2"></textarea></label>' +
			'<label class="field span-2"><span>Receipt <span class="hint">(PDF, JPG, PNG or WEBP, up to 5 MB)</span></span><input type="file" name="receipt" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>' +
			'<label class="field span-2"><span>Notes</span><textarea name="notes" rows="2"></textarea></label>' +
			'</div></form>');
		['title', 'description', 'category', 'vendor', 'reference', 'notes', 'expense_date', 'paid_on'].forEach(function (k) { f[k].value = x ? (x[k] || '') : ''; });
		f.amount.value = x ? x.amount.toFixed(2) : '';
		if (!x) f.expense_date.value = S.today;
		f.status.value = x ? x.status : 'unpaid';
		f.method.value = x ? x.method || '' : '';
		f.client_id.value = x && x.client_id ? x.client_id : '';
		f.project_id.value = x && x.project_id ? x.project_id : '';
		f.billable.checked = !!(x && x.billable);
		f.billable_amount.value = x && x.billable && Math.abs(x.billable_amount - x.amount) > 0.001 ? x.billable_amount.toFixed(2) : '';
		f.billing_remarks.value = x ? x.billing_remarks || '' : '';
		function sync() { var paid = f.status.value === 'paid'; f.paid_on.disabled = !paid; if (paid && !f.paid_on.value) f.paid_on.value = f.expense_date.value || S.today; }
		f.status.onchange = sync; sync();
		function syncBill() { $$('[data-bill-f]', f).forEach(function (n) { n.hidden = !f.billable.checked; }); }
		f.billable.onchange = syncBill; syncBill();
		// Picking a project fills in its client (an expense billable to a client needs one).
		f.project_id.addEventListener('change', function () { var p = S.projects.filter(function (y) { return String(y.id) === f.project_id.value; })[0]; if (p && p.client_id && !f.client_id.value) f.client_id.value = p.client_id; });
		var md = WD.modal({ title: x ? 'Edit expense' : 'Add expense', size: 'lg', body: f, sticky: true, foot: (x ? '<button type="button" class="btn btn-danger left" data-del>Delete</button>' : '') + (x && x.has_receipt ? '<span class="muted small left" style="margin-left:8px">Receipt: ' + esc(x.receipt_name) + '</span>' : '') + '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save</button>' });
		var ok = md.el.querySelector('[data-ok]');
		ok.onclick = function () {
			var body = {};
			['title', 'description', 'category', 'vendor', 'reference', 'notes', 'expense_date', 'paid_on', 'status', 'method', 'destination', 'client_id', 'project_id', 'amount', 'billable_amount', 'billing_remarks'].forEach(function (k) { body[k] = f[k].value; });
			body.save_account = saveAcc(f);
			body.billable = f.billable.checked ? 1 : 0;
			if (!body.title.trim()) return fail(f, 'Enter the expense title.');
			if (body.billable && !body.client_id && !body.project_id) return fail(f, 'Choose the client (or a client project) this expense is billable to.');
			var a = money(body.amount);
			if (isNaN(a) || a <= 0) return fail(f, 'Enter an amount greater than 0.');
			if (body.status === 'paid' && (!body.paid_on || !body.method)) return fail(f, 'A paid expense needs the date paid and the payment method.');
			var file = f.receipt.files[0];
			var send = function () {
				WD.busy(ok, true);
				api(x ? 'accounts/expenses/' + x.id : 'accounts/expenses', { method: x ? 'PUT' : 'POST', body: body }).then(function () { md.close(); WD.toast('Expense saved'); A.meta = null; meta().then(done); }, function (e) { WD.busy(ok, false); fail(f, e.message); });
			};
			if (!file) return send();
			if (file.size > 5 * 1024 * 1024) return fail(f, 'The receipt is larger than 5 MB.');
			var rd = new FileReader();
			rd.onload = function () { body.receipt = { name: file.name, data: rd.result }; send(); };
			rd.onerror = function () { fail(f, 'Could not read the receipt file.'); };
			rd.readAsDataURL(file);
		};
		var del = md.el.querySelector('[data-del]');
		if (del) del.onclick = function () {
			WD.confirm('Delete expense?', 'It is removed from all totals. The audit trail keeps a record of the deletion.', 'Delete', true).then(function (y) {
				if (y) api('accounts/expenses/' + x.id, { method: 'DELETE' }).then(function () { md.close(); WD.toast('Expense deleted'); done(); }, WD.toastError);
			});
		};
	}

	/* ------------------------------------------------------------------ *
	 * Passbook
	 * ------------------------------------------------------------------ */
	A.pbf = {};
	TAB.passbook = function (b) {
		var f = A.pbf, m = A.meta;
		if (!('fy' in f)) f.fy = m.current_fy;
		var types = { payment: 'Payment Received', income: 'Income Received', expense: 'Expense Paid' };
		Object.keys(m.entry_types).forEach(function (k) { types[k] = m.entry_types[k]; });
		b.innerHTML = '<div class="toolbar"><select data-pf="fy" style="width:auto"><option value="">All years</option>' + fyOptions(m.fy_list, f.fy) + '</select><input type="month" data-pf="month" value="' + esc(f.month || '') + '" style="width:auto" aria-label="Month"><input type="date" data-pf="from" value="' + esc(f.from || '') + '" aria-label="From"><input type="date" data-pf="to" value="' + esc(f.to || '') + '" aria-label="To"><select data-pf="type" style="width:auto"><option value="">All types</option>' + WD.mapOptions(types, f.type) + '</select><select data-pf="method" style="width:auto"><option value="">All methods</option>' + WD.mapOptions(m.methods, f.method) + '</select><input type="search" data-pf="party" placeholder="Client / payee" value="' + esc(f.party || '') + '" style="max-width:170px"></div>' +
			'<div class="toolbar"><span class="spacer"></span><button class="btn btn-sm" data-pb-x="csv">CSV</button><button class="btn btn-sm" data-pb-x="xlsx">Excel</button><button class="btn btn-sm" data-pb-x="pdf">PDF</button><button class="btn btn-sm btn-primary" data-entry>' + I.plus + ' Add transaction</button></div><div data-body>' + ui.loading(6) + '</div>';
		var body = b.querySelector('[data-body]');
		$$('[data-pf]', b).forEach(function (s) {
			s.addEventListener(s.type === 'search' ? 'input' : 'change', WD.u.debounce(function () {
				var k = s.getAttribute('data-pf'); f[k] = s.value;
				if (k === 'month' && s.value) { f.fy = ''; f.from = ''; f.to = ''; }
				if ((k === 'from' || k === 'to') && s.value) { f.fy = ''; f.month = ''; }
				TAB.passbook(b);
			}, s.type === 'search' ? 300 : 0));
		});
		on(b, 'click', '[data-pb-x]', function (e, btn) { WD.download('accounts/export', { body: Object.assign({ report: 'ledger', format: btn.getAttribute('data-pb-x') }, f), button: btn }).catch(function () {}); });
		b.querySelector('[data-entry]').onclick = function () { entryForm(function () { TAB.passbook(b); }); };
		function load() {
			return api('accounts/ledger', { params: f }).then(function (l) {
				var ob = l.opening_balance, d = function (x) { return esc(fmt.date(x, true)); };
				body.innerHTML = '<p class="page-note">' + (ob ? 'Opening balance ' + inr(ob.amount) + ' from ' + d(ob.date) + '. ' : 'No opening balance is configured, so the balance starts from ₹0.00 — set one in <a href="#/accounts/settings">Settings</a> if the account held money before the first entry. ') + 'Only money actually received or paid is listed; issuing or cancelling an invoice is not a transaction.' + (l.filtered ? ' <b>Filtered view:</b> totals cover the listed rows; Balance is the account balance after each row.' : '') + '</p>' +
					'<div class="stats acc-kpis">' + kpi('Opening balance', inr(l.period_opening), l.from ? 'Brought forward to ' + fmt.date(l.from, true) : 'Start of records', 'var(--brand-3)') + kpi('Money in (credit)', inr(l.totals.in), 'Payments, income, refunds received', 'var(--st-done)') + kpi('Money out (debit)', inr(l.totals.out), 'Expenses, refunds paid, other payments', 'var(--danger)') + kpi('Closing balance', inr(l.period_closing), l.to ? 'As of ' + fmt.date(l.to, true) : 'Current balance', 'var(--brand-2)') + '</div>' +
					'<section class="card"><div class="table-wrap"><table class="tbl stack ledger passbook"><thead><tr><th>Date</th><th>Type</th><th>Reference</th><th>Client / Payee</th><th>Description / Remarks</th><th class="num">Money In</th><th class="num">Money Out</th><th>Method</th><th>Account</th><th>Invoice</th><th class="num">Balance</th></tr></thead><tbody>' +
					'<tr class="pb-open"><td data-th="Date">' + (l.from ? d(l.from) : '') + '</td><td colspan="9">Opening balance' + (l.from ? ' brought forward' : '') + '</td><td data-th="Balance" class="num">' + inr(l.period_opening) + '</td></tr>' +
					(l.rows.length ? l.rows.map(function (r) {
						var isIn = r.in > 0;
						return '<tr><td data-th="Date">' + d(r.date) + '</td><td data-th="Type"><span class="pb-type' + (isIn ? '' : ' out') + '">' + esc(r.type_label) + '</span>' + (r.entry_id ? ' <button class="btn btn-sm btn-ghost" data-void-entry="' + r.entry_id + '" title="Void this transaction">Void</button>' : '') + (r.income_id && r.type === 'income' ? ' <a class="btn btn-sm btn-ghost" href="#/accounts/income">Income</a>' : '') + '</td><td data-th="Reference" class="ref" title="' + esc(r.reference) + '">' + esc(r.reference) + '</td><td data-th="Client / Payee">' + esc(r.party || '—') + '</td><td data-th="Description" class="wrap">' + esc(r.description || '—') + '</td><td data-th="Money In" class="num amt-in">' + (r.in ? inr(r.in) : '') + '</td><td data-th="Money Out" class="num amt-out">' + (r.out ? inr(r.out) : '') + '</td><td data-th="Method">' + esc(r.method_label || '—') + '</td><td data-th="Account"' + (r.destination ? '' : ' class="dim"') + '>' + esc(r.destination || '—') + '</td><td data-th="Invoice">' + ((r.invoices || []).length ? r.invoices.map(function (x) { return invLink(x) + (x.amount ? ' <span class="muted small">' + inr(x.amount) + '</span>' : ''); }).join('<br>') : '<span class="muted">—</span>') + '</td><td data-th="Balance" class="num">' + (r.balance === null ? '<span class="muted small">before opening</span>' : inr(r.balance)) + '</td></tr>';
					}).join('') : '<tr><td colspan="11">' + ui.emptyState('No transactions in this view', 'Record payments on invoices, mark expenses paid, or add a transaction.', I.rupee) + '</td></tr>') +
					'</tbody><tfoot><tr><td colspan="5">Totals</td><td data-th="Total in" class="num amt-in">' + inr(l.totals.in) + '</td><td data-th="Total out" class="num amt-out">' + inr(l.totals.out) + '</td><td colspan="3">Closing balance</td><td data-th="Closing" class="num">' + inr(l.period_closing) + '</td></tr></tfoot></table></div></section>';
			}, function (e) { body.innerHTML = ''; body.appendChild(ui.errorBox(e, load)); });
		}
		on(b, 'click', '[data-void-entry]', function (e, btn) {
			WD.confirm('Void this transaction?', 'It stays in the audit trail but leaves the passbook and totals.', 'Void', true).then(function (y) { if (y) api('accounts/entries/' + btn.getAttribute('data-void-entry') + '/void', { method: 'POST' }).then(load, WD.toastError); });
		});
		return load();
	};
	function entryForm(done) {
		var m = A.meta;
		var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
			'<label class="field"><span>Type <span class="req">*</span></span><select name="entry_type">' + WD.mapOptions(m.entry_types_new || m.entry_types, 'refund') + '</select></label>' +
			'<label class="field"><span>Amount (₹) <span class="req">*</span></span><input name="amount" inputmode="decimal"></label>' +
			'<label class="field"><span>Date <span class="req">*</span></span><input type="date" name="entry_date" value="' + esc(S.today) + '"></label>' +
			'<label class="field"><span>Method</span><select name="method"><option value="">—</option>' + WD.mapOptions(m.methods, '') + '</select></label>' +
			destField('Account', '') +
			'<label class="field"><span>Linked invoice (optional)</span><select name="invoice_id"><option value="">None</option></select></label>' +
			'<label class="field"><span>Client / payee</span><input name="party" maxlength="160"></label>' +
			'<label class="field"><span>Reference</span><input name="reference" maxlength="120"></label>' +
			'<label class="field span-2"><span>Description / remarks</span><textarea name="description" rows="2"></textarea></label></div><p class="muted small">Refunds and money paid out that is not an expense. Money received goes under <a href="#/accounts/income">Income</a>; invoice payments on the invoice; expenses under Expenses.</p></form>');
		api('accounts/invoices').then(function (list) {
			f.invoice_id.innerHTML = '<option value="">None</option>' + list.map(function (v) { return '<option value="' + v.id + '">' + esc(v.invoice_no + ' · ' + v.client_name + ' · ' + v.status_label) + '</option>'; }).join('');
		}).catch(function () {});
		var md = WD.modal({ title: 'Add transaction', body: f, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save</button>' });
		var ok = md.el.querySelector('[data-ok]');
		ok.onclick = function () {
			var a = money(f.amount.value);
			if (isNaN(a) || a <= 0) return fail(f, 'Enter an amount greater than 0.');
			WD.busy(ok, true);
			var body = {};
			['entry_type', 'amount', 'entry_date', 'method', 'destination', 'invoice_id', 'party', 'reference', 'description'].forEach(function (k) { body[k] = f[k].value; });
			body.save_account = saveAcc(f);
			api('accounts/entries', { method: 'POST', body: body }).then(function () { md.close(); WD.toast('Transaction added'); done(); }, function (e) { WD.busy(ok, false); fail(f, e.message); });
		};
	}

	/* ------------------------------------------------------------------ *
	 * Financial reports & financial-year archive
	 * ------------------------------------------------------------------ */
	TAB.reports = function (b) {
		var m = A.meta;
		b.innerHTML = '<div class="grid grid-2"><section class="card"><div class="card-h"><h2>Financial reports</h2></div><div class="card-b"><form data-rep class="form-grid"><div class="alert alert-error hidden span-2" data-err></div>' +
			'<label class="field span-2"><span>Report</span><select name="report">' + WD.mapOptions(m.reports, 'summary') + '</select></label>' +
			'<label class="field"><span>Financial year</span><select name="fy"><option value="">— use dates —</option>' + fyOptions(m.fy_list, m.current_fy) + '</select></label><div></div>' +
			'<label class="field"><span>From</span><input type="date" name="from"></label><label class="field"><span>To</span><input type="date" name="to"></label>' +
			'<div class="field span-2"><span>Format</span><div class="inv-actions"><button type="button" class="btn" data-f="pdf">' + I.file + ' PDF</button><button type="button" class="btn" data-f="xlsx">' + I.sheet + ' Excel (.xlsx)</button><button type="button" class="btn" data-f="csv">' + I.list + ' CSV (.csv)</button></div></div>' +
			'</form><p class="muted small">Dates override the financial year. Files are generated on request and not stored.</p></div></section>' +
			'<section class="card"><div class="card-h"><h2>Financial year archive</h2></div><div class="card-b"><p class="small">Download every invoice PDF of a financial year with the invoice index, passbook, payment history, expenses and a README in one ZIP. Generated on demand; the server copy is deleted automatically after ' + esc(m.settings.archive_expiry_hours) + ' hour(s).</p>' +
			(m.zip ? '' : '<div class="alert alert-error">The PHP ZipArchive extension is missing on this server, so ZIP archives cannot be created.</div>') +
			'<div class="toolbar" style="margin:0"><select data-afy style="width:auto">' + fyOptions(m.fy_list, m.fy_list[0]) + '</select><button class="btn btn-primary" data-build' + (m.zip ? '' : ' disabled') + '>' + I.archive + ' Download Financial Year Archive (ZIP)</button></div><div data-arch style="margin-top:14px">' + ui.loading(3) + '</div></div></section></div>' +
			'<section class="card section-gap"><div class="card-h"><h2>Invoice retention</h2><span class="spacer"></span><span class="muted small">FY runs 1 April – 31 March</span></div><div data-ret>' + ui.loading(3) + '</div></section>';
		var form = b.querySelector('[data-rep]');
		on(form, 'click', '[data-f]', function (e, btn) {
			form.querySelector('[data-err]').classList.add('hidden');
			var body = { report: form.report.value, format: btn.getAttribute('data-f'), fy: form.fy.value, from: form.from.value, to: form.to.value };
			if ((body.from || body.to) && !(body.from && body.to && body.from <= body.to)) return fail(form, 'Choose both dates, From on or before To.');
			if (body.from) body.fy = body.report === 'summary' ? form.fy.value : '';
			WD.download('accounts/export', { body: body, button: btn, busyLabel: 'Generating…' }).catch(function () {});
		});
		var arch = b.querySelector('[data-arch]'), ret = b.querySelector('[data-ret]');
		function load() {
			return api('accounts/archives').then(function (r) {
				arch.innerHTML = r.archives.length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>Archive</th><th>Status</th><th></th></tr></thead><tbody>' + r.archives.slice(0, 8).map(function (a) {
					var st = a.status === 'ready' ? '<span class="badge s-completed">Ready</span> <span class="muted small">' + Math.max(1, Math.round(a.size / 1024)) + ' KB · expires ' + esc(fmt.dt(a.expires_at)) + '</span>' : a.status === 'expired' ? '<span class="badge">Expired (file removed)</span>' : a.status === 'failed' ? '<span class="badge s-blocked">Failed</span><div class="small" style="color:var(--danger)">' + esc(a.error) + '</div>' : esc(a.status);
					return '<tr><td data-th="Archive"><b>FY ' + esc(a.fy) + '</b><div class="muted small">' + esc(fmt.dt(a.created_at)) + ' · ' + esc(a.created_by) + '</div></td><td data-th="Status">' + st + (a.downloaded_at ? '<div class="muted small">Downloaded ' + esc(fmt.dt(a.downloaded_at)) + '</div>' : '') + (a.confirmed_at ? '<div class="small"><span class="badge s-completed">Confirmed</span> ' + esc(a.confirmed_by) + '</div>' : '') + '</td><td class="actions">' +
						(a.status === 'ready' ? '<button class="btn btn-sm" data-dl="' + a.id + '">' + I.download + ' Download</button>' : '') + (a.status === 'failed' ? '<button class="btn btn-sm" data-retry-fy="' + esc(a.fy) + '">Retry</button>' : '') + (a.downloaded_at && !a.confirmed_at ? '<button class="btn btn-sm btn-primary" data-confirm="' + a.id + '">Confirm reviewed</button>' : '') + '</td></tr>';
				}).join('') + '</tbody></table></div>' : '<p class="muted small">No archives generated yet.</p>';
				ret.innerHTML = r.retention.length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>Financial year</th><th class="num">Invoices</th><th>Retention deadline</th><th>Grace until</th><th>Stage</th><th>Archive confirmed</th></tr></thead><tbody>' + r.retention.map(function (x) {
					var stage = { current: 'Current year', extra_month: 'Extra month after year end', grace: 'Grace period', eligible: 'Past grace period — kept (no automatic deletion)' }[x.stage];
					return '<tr><td data-th="FY"><b>FY ' + esc(x.fy) + '</b></td><td data-th="Invoices" class="num">' + x.invoices + '</td><td data-th="Deadline">' + esc(fmt.date(x.deadline, true)) + '</td><td data-th="Grace">' + esc(fmt.date(x.grace_until, true)) + '</td><td data-th="Stage">' + esc(stage) + '</td><td data-th="Confirmed">' + (x.confirmed_at ? '<span class="badge s-completed">Yes</span> ' + esc(fmt.date(x.confirmed_at)) : '<span class="badge s-pending">Not yet</span>') + '</td></tr>';
				}).join('') + '</tbody></table></div><p class="card-b muted small">Invoices and their PDFs are kept for the financial year plus one month and the grace period, and you are reminded (dashboard and email) to download and confirm the archive before the deadline. They are never deleted automatically — GST rules require keeping records for years.</p>' : ui.emptyState('No invoices yet');
			}, function (e) { arch.innerHTML = ''; arch.appendChild(ui.errorBox(e, load)); });
		}
		function build(fy, btn) {
			WD.busy(btn, true, 'Building ZIP…');
			return api('accounts/archives', { method: 'POST', body: { fy: fy } }).then(function (a) {
				WD.busy(btn, false);
				load();
				return WD.download('accounts/archives/' + a.id + '/download', { quiet: false }).then(load);
			}, function (e) {
				WD.busy(btn, false);
				WD.toast('Archive failed: ' + e.message, 'error', { label: 'Retry', fn: function () { build(fy, btn); } });
				load();
			});
		}
		b.querySelector('[data-build]').onclick = function (e) { build(b.querySelector('[data-afy]').value, e.currentTarget); };
		on(arch, 'click', '[data-dl]', function (e, btn) { WD.download('accounts/archives/' + btn.getAttribute('data-dl') + '/download', { button: btn }).then(load, function () {}); });
		on(arch, 'click', '[data-retry-fy]', function (e, btn) { build(btn.getAttribute('data-retry-fy'), btn); });
		on(arch, 'click', '[data-confirm]', function (e, btn) {
			WD.confirm('Confirm archive reviewed?', 'Confirm that you opened the ZIP, checked the invoices and saved it somewhere safe. Reminders for this financial year stop after confirmation.', 'Confirm').then(function (y) {
				if (y) api('accounts/archives/' + btn.getAttribute('data-confirm') + '/confirm', { method: 'POST' }).then(function () { WD.toast('Archive confirmed'); load(); }, WD.toastError);
			});
		});
		return load();
	};

	/* ------------------------------------------------------------------ *
	 * Packages
	 * ------------------------------------------------------------------ */
	TAB.packages = function (b) {
		var sub = A.pkgSub || 'usage';
		var SUBS = [['usage', 'Utilisation by client / project'], ['templates', 'Package templates'], ['services', 'Services']];
		b.innerHTML = '<div class="toolbar"><div class="seg" data-psub role="tablist">' + SUBS.map(function (s) { return '<button type="button" data-v="' + s[0] + '"' + (s[0] === sub ? ' class="on"' : '') + '>' + esc(s[1]) + '</button>'; }).join('') + '</div><span class="spacer"></span><span data-pact></span></div><div data-pbody>' + ui.loading(4) + '</div>';
		b.querySelector('[data-psub]').onclick = function (e) { var t = e.target.closest('button'); if (t) { A.pkgSub = t.getAttribute('data-v'); TAB.packages(b); } };
		var body = b.querySelector('[data-pbody]'), act = b.querySelector('[data-pact]');
		return WD.pkgMeta().then(function (pm) { return { usage: pkgUsage, templates: pkgTemplates, services: pkgServices }[sub](body, act, pm); });
	};
	A.puf = { status: '' };
	A.pus = { q: '', sort: 'client', dir: 'asc' };
	var PKG_COLS = {
		client: [function (a) { return a.client_name; }, 'text'],
		project: [function (a) { return a.project_name; }, 'text'],
		package: [function (a) { return a.name; }, 'text'],
		cycle: [function (a) { return a.cycle ? a.cycle.start || a.cycle.label : ''; }, 'date'],
		usage: [function (a) { var t = 0, u = 0; a.items.forEach(function (it) { t += it.allocated + it.adjusted; u += it.consumed + it.reserved; }); return t ? u / t : (u ? 1e6 + u : -1); }, 'num'],
		extra: [function (a) { return a.counts.additional; }, 'num'],
		status: [function (a) { return a.status_label; }, 'text']
	};
	function pkgMatch(a, q) {
		q = q.toLowerCase();
			return [a.client_name, a.project_name, a.name, a.type_label, a.status_label, a.effective_status].concat(a.items.map(function (it) { return it.service_name; })).some(function (v) { return String(v || '').toLowerCase().indexOf(q) >= 0; });
	}
	function pkgUsage(body, act, pm) {
		var st = A.pus;
		act.innerHTML = searchBox('data-pq', st.q, 'Client, project, package, service or status', 'Search packages') + '<select data-pu-status style="width:auto" aria-label="Status"><option value="">Active, paused &amp; expired</option>' + WD.mapOptions({ active: 'Active', paused: 'Paused', expired: 'Expired', upcoming: 'Starts later', inactive: 'Deactivated', cancelled: 'Cancelled', archived: 'Archived' }, A.puf.status) + '</select>';
		act.querySelector('select').onchange = function (e) { A.puf.status = e.target.value; load(); };
		var all = [];
		bindSearch(act, '[data-pq]', function (v) { st.q = v; paint(); });
		bindSort(body, st, PKG_COLS, function () { paint(); });
		function load() {
			return api('packages/utilization', { params: A.puf }).then(function (list) {
				all = list; paint();
			}, function (e) { body.innerHTML = ''; body.appendChild(ui.errorBox(e, load)); });
		}
		function paint() {
				var list = sortRows(st.q ? all.filter(function (a) { return pkgMatch(a, st.q); }) : all, st, PKG_COLS);
				var units = 0, used = 0, over = 0, add = 0;
				list.forEach(function (a) { a.items.forEach(function (it) { units += it.allocated + it.adjusted; used += it.consumed + it.reserved; }); over += a.counts.over_limit; add += a.counts.additional; });
				body.innerHTML = '<div class="stats acc-kpis">' + kpi('Client packages', String(list.length), list.filter(function (a) { return a.effective_status === 'active'; }).length + ' active', 'var(--brand-3)') + kpi('Units used this cycle', used + ' / ' + units, units ? Math.round(used / units * 100) + '% of included units (used + reserved)' : 'No counted services', 'var(--st-done)') + kpi('Additional-scope work', String(add), 'Tasks outside the package this cycle', 'var(--st-pending)') + kpi('Beyond the limit', String(over), 'Approved or reopened extra units', 'var(--danger)') + '</div>' +
					(st.q ? '<p class="muted small">' + list.length + ' of ' + all.length + ' client packages match “' + esc(st.q) + '”</p>' : '') +
					'<section class="card"><div class="table-wrap"><table class="tbl stack pkg-util"><thead><tr>' + sortTh(st, 'client', 'Client') + sortTh(st, 'project', 'Project') + sortTh(st, 'package', 'Package') + sortTh(st, 'cycle', 'Current cycle') + sortTh(st, 'usage', 'Service usage (used + reserved / included)') + sortTh(st, 'extra', 'Retainer / additional', 'num') + sortTh(st, 'status', 'Status') + '<th></th></tr></thead><tbody>' +
					(list.length ? list.map(function (a) {
						return '<tr class="click" data-goto-client="' + a.client_id + '"><td data-th="Client"><a href="#/clients/' + a.client_id + '"><b>' + esc(a.client_name) + '</b></a></td><td data-th="Project">' + (a.project_name ? esc(a.project_name) : '<span class="muted small">Client-wide</span>') + (a.parent_inactive ? '<div><span class="badge overdue">Client or project inactive</span></div>' : '') + '</td><td data-th="Package">' + esc(a.name) + '<div class="muted small">' + esc(a.type_label) + ' · ' + inr(a.price) + (a.period_label ? ' / ' + esc(a.period_label) : '') + '</div></td><td data-th="Cycle">' + (a.cycle ? esc(a.cycle.label) : '—') + '</td>' +
							'<td data-th="Usage">' + (a.items.length ? a.items.map(function (it) { var t = it.allocated + it.adjusted, u = it.consumed + it.reserved; return '<div class="pkg-util-row"><span>' + esc(it.service_name) + '</span><b' + (it.remaining < 0 ? ' style="color:var(--danger)"' : '') + '>' + u + '/' + t + '</b>' + WD.u.meter(u, t) + '</div>'; }).join('') : '<span class="muted small">Scope of work only</span>') + '</td>' +
							'<td data-th="Retainer / additional" class="num">' + a.counts.retainer + ' / ' + a.counts.additional + (a.counts.over_limit ? '<div class="small" style="color:var(--danger)">' + a.counts.over_limit + ' beyond limit</div>' : '') + '</td><td data-th="Status"><span class="badge ' + ({ active: 's-completed', paused: 's-in_process', upcoming: 's-pending', cancelled: 's-rejected' }[a.effective_status] || '') + '">' + esc(a.status_label) + '</span></td><td class="actions"><select data-life="' + a.id + '" aria-label="Package actions" style="width:auto"><option value="">Actions…</option>' + (a.status === 'active' ? '<option value="paused">Pause</option><option value="inactive">Deactivate</option>' : '') + (a.status !== 'active' ? '<option value="active">Reactivate</option>' : '') + (a.status !== 'cancelled' ? '<option value="cancelled">Cancel</option>' : '') + (a.status !== 'archived' ? '<option value="archived">Archive</option>' : '') + (a.carry ? '<option value="carry">Carry forward' + (a.carry.pending ? ' (' + a.carry.pending + ')' : '') + '</option>' : '') + '<option value="delete">Delete unused draft</option></select></td></tr>';
					}).join('') : '<tr><td colspan="8">' + (st.q && all.length ? ui.emptyState('No client packages match', 'Change or clear the search.', I.briefcase) : ui.emptyState('No client packages yet', 'Assign service packages from a project (Projects → project → Service packages).', I.briefcase)) + '</td></tr>') + '</tbody></table></div></section>';
		}
		on(body, 'click', 'tr[data-goto-client]', function (e, r) { if (!e.target.closest('a,select,.dd,.actions')) WD.go('/clients/' + r.getAttribute('data-goto-client')); });
		on(body, 'change', '[data-life]', function (e, s) {
			var v = s.value, id = +s.getAttribute('data-life'), a = all.filter(function (x) { return x.id === id; })[0];
			s.value = '';
			if (!v || !a) return;
			if (v === 'carry') return WD.carryForm(a, load);
			if (v === 'delete') return WD.safeDelete('client-packages/' + id, 'package').then(function (ok) { if (ok) load(); });
			var names = { paused: 'Pause', inactive: 'Deactivate', active: 'Reactivate', cancelled: 'Cancel', archived: 'Archive' };
			var go = function () { api('client-packages/' + id, { method: 'PUT', body: { status: v } }).then(function () { WD.toast('“' + a.name + '” updated — its history and invoices are kept'); load(); }, WD.toastError); };
			if (v === 'paused' || v === 'active') return go();
			WD.confirm(names[v] + ' “' + a.name + '”?', 'New tasks can no longer use this package. Existing tasks, usage history and invoices stay as they are.', names[v]).then(function (y) { if (y) go(); });
		});
		return load();
	}
	function pkgTemplates(body, act, pm) {
		act.innerHTML = '<button class="btn btn-primary" data-add>' + I.plus + ' New package</button>';
		var rows = [];
		function load() {
			return api('accounts/packages').then(function (r) {
				rows = r;
				body.innerHTML = '<p class="page-note">Reusable package templates. Assigning one to a client copies its terms, so each client has separate quantities and counters; editing a template affects new assignments and new invoices only.</p><div class="grid grid-3">' + (r.length ? r.map(function (p) {
					return '<section class="card' + (p.is_active ? '' : ' inactive') + '"><div class="card-h"><h2>' + esc(p.name) + '</h2><span class="spacer"></span>' + (p.is_active ? '<span class="badge">' + esc(p.type_label) + '</span>' : '<span class="badge">Inactive</span>') + '</div><div class="card-b"><div class="pkg-price">' + inr(p.amount) + (p.billing_period ? ' <span class="muted small">/ ' + esc(A.meta.periods[p.billing_period]) + '</span>' : '') + '</div>' +
						(p.items.length ? '<ul class="small pkg-tpl-items">' + p.items.map(function (it) { return '<li><b>' + it.quantity + '</b> ' + esc(it.quantity === 1 ? it.unit : it.unit_plural) + ' · ' + esc(it.service_name) + (p.package_type === 'monthly' && p.reset_rule === 'monthly' ? ' <span class="muted">/ cycle</span>' : '') + (it.notes ? ' <span class="muted">(' + esc(it.notes) + ')</span>' : '') + '</li>'; }).join('') + '</ul>' : '') +
						(p.description ? '<p class="muted small">' + esc(p.description) + '</p>' : '') + (p.scope ? '<p class="small"><b>Scope:</b> ' + esc(p.scope) + '</p>' : '') + (p.services.length ? '<ul class="small">' + p.services.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ul>' : '') +
						'<div class="muted small" style="margin-bottom:8px">Assigned to ' + p.assigned + ' client' + (p.assigned === 1 ? '' : 's') + (p.package_type === 'fixed' && p.duration_months ? ' · ' + p.duration_months + ' months' : '') + '</div><button class="btn btn-sm" data-edit="' + p.id + '">' + I.edit + ' Edit</button></div></section>';
				}).join('') : '<div class="card" style="grid-column:1/-1">' + ui.emptyState('No packages yet', 'Create packages such as “Social Media Management — 5 videos + 6 carousels per month”. Custom packages can still be typed on each invoice.', I.rupee) + '</div>') + '</div>';
			}, function (e) { body.innerHTML = ''; body.appendChild(ui.errorBox(e, load)); });
		}
		function form(p) {
			var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
				'<label class="field span-2"><span>Package name <span class="req">*</span></span><input name="name" maxlength="160"></label>' +
				'<label class="field"><span>Package type</span><select name="package_type">' + WD.mapOptions(pm.types, p ? p.package_type : 'monthly') + '</select></label>' +
				'<label class="field"><span>Price (₹) <span class="req">*</span></span><input name="amount" inputmode="decimal"></label>' +
				'<label class="field"><span>Billing frequency</span><select name="billing_period">' + WD.mapOptions(A.meta.periods, p ? p.billing_period : 'monthly') + '</select></label>' +
				'<label class="field" data-dur><span>Duration (months)</span><input name="duration_months" type="number" min="0" max="120" step="1"></label>' +
				'<label class="field"><span>Start date <span class="hint">(optional default)</span></span><input type="date" name="start_date"></label>' +
				'<label class="field"><span>End date <span class="hint">(optional)</span></span><input type="date" name="end_date"></label>' +
				'<label class="check span-2" data-reset><input type="checkbox" name="resets"> Reset service limits at the start of every monthly billing cycle</label>' +
				'<div class="field span-2"><span>Included services and quantities <span class="hint">— add custom services under Packages &amp; services → Services</span></span><div data-items></div></div>' +
				'<label class="field span-2"><span>Scope of work</span><textarea name="scope" rows="3" placeholder="e.g. Monthly retainer: content calendar, posting and community replies"></textarea></label>' +
				'<label class="field span-2"><span>Additional terms</span><textarea name="terms" rows="2" placeholder="e.g. Work beyond the included quantities is quoted separately"></textarea></label>' +
				'<label class="field span-2"><span>Description</span><textarea name="description" rows="2"></textarea></label>' +
				'<label class="field span-2"><span>Deliverables list <span class="hint">(optional, one per line — shown on the template card)</span></span><textarea name="services" rows="2"></textarea></label>' +
				'<label class="field span-2"><span>Remarks</span><textarea name="remarks" rows="2"></textarea></label>' +
				(p ? '<label class="check span-2"><input type="checkbox" name="is_active"> Active (offered for new clients and invoices)</label>' : '') + '</div></form>');
			var items = WD.pkgItemsEditor(pm.services, p ? p.items : []);
			f.querySelector('[data-items]').appendChild(items);
			f.name.value = p ? p.name : ''; f.amount.value = p ? p.amount.toFixed(2) : ''; f.description.value = p ? p.description : ''; f.services.value = p ? p.services.join('\n') : '';
			f.scope.value = p ? p.scope : ''; f.terms.value = p ? p.terms : ''; f.remarks.value = p ? p.remarks : '';
			f.start_date.value = p && p.start_date ? p.start_date : ''; f.end_date.value = p && p.end_date ? p.end_date : ''; f.duration_months.value = p && p.duration_months ? p.duration_months : '';
			f.resets.checked = !p || p.reset_rule === 'monthly';
			if (p) f.is_active.checked = p.is_active;
			function sync() { var t = f.package_type.value; f.querySelector('[data-dur]').hidden = t !== 'fixed'; f.querySelector('[data-reset]').hidden = t !== 'monthly'; }
			f.package_type.onchange = sync; sync();
			var md = WD.modal({ title: p ? 'Edit package' : 'New package', size: 'lg', body: f, sticky: true, foot: (p && A.meta.can_delete ? '<button type="button" class="btn btn-danger left" data-del>Delete</button>' : '') + '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save</button>' });
			var ok = md.el.querySelector('[data-ok]');
			var del = md.el.querySelector('[data-del]');
			if (del) del.onclick = function () { WD.safeDelete('accounts/packages/' + p.id, 'package').then(function (y) { if (y) { md.close(); load(); } }); };
			ok.onclick = function () {
				var a = money(f.amount.value);
				if (!f.name.value.trim()) return fail(f, 'Enter the package name.');
				if (isNaN(a) || a < 0) return fail(f, 'Enter an amount of 0 or more.');
				var body2 = { name: f.name.value, amount: f.amount.value, billing_period: f.billing_period.value, package_type: f.package_type.value, duration_months: f.duration_months.value || 0, start_date: f.start_date.value, end_date: f.end_date.value, reset_rule: f.resets.checked ? 'monthly' : 'none', description: f.description.value, services: f.services.value, scope: f.scope.value, terms: f.terms.value, remarks: f.remarks.value };
				try { body2.items = items.getValue(); } catch (er) { return fail(f, er.message); }
				if (p) body2.is_active = f.is_active.checked;
				WD.busy(ok, true);
				api(p ? 'accounts/packages/' + p.id : 'accounts/packages', { method: p ? 'PUT' : 'POST', body: body2 }).then(function () { md.close(); WD.toast('Package saved'); load(); }, function (e) { WD.busy(ok, false); fail(f, e.message); });
			};
		}
		act.querySelector('[data-add]').onclick = function () { form(null); };
		on(body, 'click', '[data-edit]', function (e, btn) { form(rows.filter(function (x) { return x.id === +btn.getAttribute('data-edit'); })[0]); });
		return load();
	}
	function pkgServices(body, act, pm) {
		act.innerHTML = '<button class="btn btn-primary" data-add>' + I.plus + ' New service</button>';
		function paint() {
			body.innerHTML = '<p class="page-note">Services packages can include, with the unit they are counted in. Keywords help WorkDesk suggest the service from a task title (you can always correct it on the task).</p><section class="card"><div class="table-wrap"><table class="tbl stack"><thead><tr><th>Service</th><th>Unit</th><th>Keywords</th><th>Status</th><th></th></tr></thead><tbody>' + pm.services.map(function (s) {
				return '<tr' + (s.is_active ? '' : ' class="dim"') + '><td data-th="Service"><b>' + esc(s.name) + '</b></td><td data-th="Unit">' + esc(s.unit) + ' / ' + esc(s.unit_plural) + '</td><td data-th="Keywords" class="small">' + esc(s.keywords || '—') + '</td><td data-th="Status">' + (s.is_active ? '<span class="badge s-completed">Active</span>' : '<span class="badge">Inactive</span>') + '</td><td class="actions"><button class="btn btn-sm" data-edit="' + s.id + '">' + I.edit + ' Edit</button></td></tr>';
			}).join('') + '</tbody></table></div></section>';
		}
		function form(sv) {
			var f = el('<form novalidate><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
				'<label class="field span-2"><span>Service name <span class="req">*</span></span><input name="name" maxlength="120" placeholder="e.g. Reel scripting"></label>' +
				'<label class="field"><span>Unit (one)</span><input name="unit" maxlength="40" placeholder="e.g. script"></label>' +
				'<label class="field"><span>Unit (many)</span><input name="unit_plural" maxlength="40" placeholder="e.g. scripts"></label>' +
				'<label class="field span-2"><span>Keywords <span class="hint">(comma separated, matched against task titles)</span></span><input name="keywords" maxlength="255" placeholder="script, scripting"></label>' +
				(sv ? '<label class="check span-2"><input type="checkbox" name="is_active"> Active (offered in new packages)</label>' : '') + '</div></form>');
			['name', 'unit', 'unit_plural', 'keywords'].forEach(function (k) { f[k].value = sv ? sv[k] : ''; });
			if (sv) f.is_active.checked = sv.is_active;
			var md = WD.modal({ title: sv ? 'Edit service' : 'New service', body: f, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save</button>' });
			var ok = md.el.querySelector('[data-ok]');
			ok.onclick = function () {
				if (!f.name.value.trim()) return fail(f, 'Enter the service name.');
				var bd = { name: f.name.value, unit: f.unit.value, unit_plural: f.unit_plural.value, keywords: f.keywords.value };
				if (sv) bd.is_active = f.is_active.checked;
				WD.busy(ok, true);
				api(sv ? 'package-services/' + sv.id : 'package-services', { method: sv ? 'PUT' : 'POST', body: bd }).then(function () { md.close(); WD.toast('Service saved'); WD.pkgMetaReset(); WD.pkgMeta().then(function (n) { pm = n; paint(); }); }, function (e) { WD.busy(ok, false); fail(f, e.message); });
			};
		}
		act.querySelector('[data-add]').onclick = function () { form(null); };
		on(body, 'click', '[data-edit]', function (e, btn) { form(pm.services.filter(function (x) { return x.id === +btn.getAttribute('data-edit'); })[0]); });
		paint();
		return Promise.resolve();
	}

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */
	TAB.settings = function (b) {
		var s = A.meta.settings;
		var t = function (name, label, extra) { return '<label class="field' + (extra && extra.span ? ' span-2' : '') + '"><span>' + label + '</span><input name="' + name + '" value="' + esc(s[name] === null || s[name] === undefined ? '' : s[name]) + '"' + (extra && extra.attrs ? ' ' + extra.attrs : '') + '></label>'; };
		var ta = function (name, label, rows) { return '<label class="field span-2"><span>' + label + '</span><textarea name="' + name + '" rows="' + (rows || 3) + '">' + esc(s[name] || '') + '</textarea></label>'; };
		b.innerHTML = '<form data-set novalidate><div class="alert alert-error hidden" data-err></div><div class="grid grid-2">' +
			'<section class="card"><div class="card-h"><h2>Business details (on invoices)</h2></div><div class="card-b"><div class="form-grid">' +
				'<div class="field span-2"><span>Business logo <span class="hint">(optional — PNG, JPG or WEBP, up to 2 MB, printed on invoice PDFs)</span></span><div class="qr-box" data-logo></div></div>' +
				t('business_name', 'Business name', { span: 1 }) + ta('address', 'Address <span class="hint">(optional)</span>', 3) + t('phone', 'Phone') + t('email', 'Email', { attrs: 'type="email"' }) + t('website', 'Website') + t('gstin', 'GSTIN (if registered)', { attrs: 'maxlength="15" style="text-transform:uppercase"' }) +
			'</div><p class="muted small">Only what you enter here appears on invoices. Nothing is filled in for you.</p></div></section>' +
			'<section class="card"><div class="card-h"><h2>Payment details</h2></div><div class="card-b"><div class="form-grid">' +
			t('account_name', 'Account name') + t('bank_name', 'Bank') + t('account_number', 'Account number') + t('ifsc', 'IFSC') + t('upi_id', 'UPI ID', { span: 1 }) + ta('payment_instructions', 'Default payment instructions', 2) +
			'<div class="field span-2"><span>UPI payment QR code <span class="hint">(PNG, JPG or WEBP, up to 2 MB — printed on invoice PDFs)</span></span><div class="qr-box" data-qr></div></div>' +
			'</div></div></section>' +
			'<section class="card"><div class="card-h"><h2>Invoice defaults</h2></div><div class="card-b"><div class="form-grid">' +
			t('invoice_prefix', 'Invoice number prefix', { attrs: 'maxlength="10"' }) + t('due_days', 'Days until due', { attrs: 'type="number" min="0" max="365"' }) + t('tax_label', 'Tax label') + t('tax_rate', 'Default tax rate (%)', { attrs: 'inputmode="decimal"' }) +
			'<p class="span-2 muted small">Numbers look like ' + esc((s.invoice_prefix || 'ND') + '/' + A.meta.current_fy + '/0001') + ' and restart each financial year.</p>' +
			t('opening_balance', 'Opening balance (₹, optional)', { attrs: 'inputmode="decimal" placeholder="Leave blank for none"' }) + t('opening_date', 'Opening balance date', { attrs: 'type="date"' }) +
			'</div></div></section>' +
			'<section class="card"><div class="card-h"><h2>Archives and retention</h2></div><div class="card-b"><div class="form-grid">' +
			t('fy_grace_days', 'Grace period after the deadline (days)', { attrs: 'type="number" min="0" max="365"' }) + t('archive_expiry_hours', 'Delete generated ZIPs after (hours)', { attrs: 'type="number" min="1" max="168"' }) +
			'<label class="field span-2"><span>Custom expense categories <span class="hint">(one per line, added to the standard list)</span></span><textarea name="expense_categories" rows="3">' + esc((s.expense_categories || []).join('\n')) + '</textarea></label>' +
			'<label class="field span-2"><span>Custom income categories <span class="hint">(one per line, added to ' + esc(A.meta.income_builtin.join(', ')) + '. Payment method is chosen separately on each entry.)</span></span><textarea name="income_sources" rows="3">' + esc((s.income_sources || []).join('\n')) + '</textarea></label>' +
			'</div></div></section></div>' +
			'<section class="card section-gap"><div class="card-h"><h2>Payment accounts</h2><span class="spacer"></span><button type="button" class="btn btn-sm" data-acc-add>' + I.plus + ' Add account</button></div><div class="card-b flush" data-acc-list></div><p class="muted small" style="margin:0;padding:10px 16px 14px">Offered as “Received into” / “Paid from” on Income, Expenses, payments and passbook entries, filtered by payment method. These are labels that record where money was received or paid; they do not hold separate balances — all transactions count towards the one NeonDigital balance. Renaming an account keeps its past transactions as they were recorded; deactivating only hides it from new entries — its past transactions stay unchanged.</p></section>' +
			'<section class="card section-gap"><div class="card-h"><h2>Message templates</h2><span class="spacer"></span><span class="muted small">Placeholders: {client_name} {invoice_number} {invoice_total} {due_date} {balance_due}</span></div><div class="card-b"><div class="form-grid">' +
			ta('wa_template', 'WhatsApp message', 8) + t('email_subject', 'Email subject', { span: 1 }) + ta('email_body', 'Email body', 10) +
			'<label class="check span-2"><input type="checkbox" name="include_pdf_link"' + (+s.include_pdf_link ? ' checked' : '') + '> Add a secure PDF download link to messages by default</label>' +
			'</div></div></section>' +
			'<div class="toolbar section-gap"><span class="spacer"></span><button class="btn btn-primary" data-save>Save settings</button></div></form>';
		var f = b.querySelector('[data-set]');
		var qr = f.querySelector('[data-qr]');
		function paintQr() {
			var has = !!A.meta.settings.upi_qr;
			qr.innerHTML = (has ? '<img data-qr-img alt="Saved UPI QR code">' : '<p class="muted small" style="margin:0">No QR code uploaded. Invoices show the UPI ID and bank details only.</p>') +
				'<div class="inv-actions"><label class="btn btn-sm">' + I.plus + (has ? ' Replace QR' : ' Upload QR') + '<input type="file" data-qr-file accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" hidden></label>' + (has ? '<button type="button" class="btn btn-sm btn-ghost btn-danger" data-qr-rm>Remove</button>' : '') + '</div>';
			if (has) WD.imageUrl('accounts/settings/qr').then(function (u) { var i = qr.querySelector('[data-qr-img]'); if (i && u) i.src = u; });
		}
			paintQr();
			var logo = f.querySelector('[data-logo]');
			function paintLogo() {
				var has = !!A.meta.settings.business_logo;
				logo.innerHTML = (has ? '<img data-logo-img alt="Saved business logo">' : '<p class="muted small" style="margin:0">No logo uploaded. Invoices show the business name only.</p>') +
					'<div class="inv-actions"><label class="btn btn-sm">' + I.plus + (has ? ' Replace logo' : ' Upload logo') + '<input type="file" data-logo-file accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" hidden></label>' + (has ? '<button type="button" class="btn btn-sm btn-ghost btn-danger" data-logo-rm>Remove</button>' : '') + '</div>';
				if (has) WD.imageUrl('accounts/settings/logo').then(function (u) { var i = logo.querySelector('[data-logo-img]'); if (i && u) i.src = u; });
			}
			paintLogo();
			on(logo, 'change', '[data-logo-file]', function (e, inp) {
				var file = inp.files[0];
				if (!file) return;
				if (!/[.](png|jpe?g|webp)$/i.test(file.name)) { inp.value = ''; return WD.toast('Choose a PNG, JPG or WEBP image.', 'error'); }
				if (file.size > 2 * 1024 * 1024) { inp.value = ''; return WD.toast('The logo is larger than 2 MB.', 'error'); }
				var rd = new FileReader();
				rd.onload = function () { api('accounts/settings/logo', { method: 'POST', body: { name: file.name, data: rd.result } }).then(function (mm) { A.meta = mm; WD.toast('Logo saved'); paintLogo(); }, WD.toastError); };
				rd.readAsDataURL(file);
			});
			on(logo, 'click', '[data-logo-rm]', function () {
				WD.confirm('Remove business logo?', 'New invoices will not show a logo. Invoices already issued keep the logo they were made with.', 'Remove', true).then(function (y) {
					if (y) api('accounts/settings/logo', { method: 'DELETE' }).then(function (mm) { A.meta = mm; WD.toast('Logo removed'); paintLogo(); }, WD.toastError);
				});
			});
			var accBox = f.querySelector('[data-acc-list]');
		function paintAccounts() {
			var list = A.meta.payment_accounts || [], types = A.meta.account_types || {};
			accBox.innerHTML = list.length ? '<div class="table-wrap"><table class="tbl stack"><thead><tr><th>Account</th><th>Type</th><th>Identifier / reference</th><th>Status</th><th></th></tr></thead><tbody>' + list.map(function (a) {
				return '<tr><td data-th="Account"><b>' + esc(a.name) + '</b>' + (a.aliases.length ? '<div class="muted small">Earlier: ' + esc(a.aliases.join(', ')) + '</div>' : '') + '</td><td data-th="Type">' + esc(types[a.type] || a.type) + '</td><td data-th="Reference">' + esc(a.ref || '—') + '</td>' +
					'<td data-th="Status">' + (a.active ? '<span class="badge s-completed">Active</span>' : '<span class="badge">Inactive</span>') + '</td>' +
					'<td class="actions"><button type="button" class="btn btn-sm" data-acc-edit="' + a.id + '">Edit</button> <button type="button" class="btn btn-sm btn-ghost" data-acc-toggle="' + a.id + '">' + (a.active ? 'Deactivate' : 'Activate') + '</button></td></tr>';
			}).join('') + '</tbody></table></div>' : ui.emptyState('No payment accounts yet', 'Add the bank accounts, UPI IDs, cards and cash you receive or pay money through.', I.rupee);
		}
		function accSaved(list) { A.meta.payment_accounts = list; A.meta.destinations = list.filter(function (a) { return a.active; }).map(function (a) { return a.name; }); paintAccounts(); }
		function accForm(a) {
			var types = A.meta.account_types || {};
			var af = el('<form novalidate><div class="alert alert-error hidden" data-err></div><div class="form-grid">' +
				'<label class="field span-2"><span>Display name <span class="req">*</span></span><input name="name" maxlength="160" placeholder="e.g. HDFC Current A/c XX4321" value="' + esc(a ? a.name : '') + '"></label>' +
				'<label class="field"><span>Account type <span class="req">*</span></span><select name="type">' + WD.mapOptions(types, a ? a.type : 'bank') + '</select></label>' +
				'<label class="field"><span>Identifier / reference <span class="hint">(optional)</span></span><input name="ref" maxlength="120" placeholder="Last digits, UPI ID, IFSC…" value="' + esc(a ? a.ref : '') + '"></label>' +
				'<label class="check span-2"><input type="checkbox" name="active"' + (!a || a.active ? ' checked' : '') + '> Active (offered on new entries)</label>' +
				'</div>' + (a ? '<p class="muted small">Transactions already recorded keep the account name they were saved with; they still count under this account after a rename.</p>' : '') + '</form>');
			var md = WD.modal({ title: a ? 'Edit payment account' : 'Add payment account', body: af, foot: '<button type="button" class="btn" data-close>Cancel</button><button type="button" class="btn btn-primary" data-ok>Save</button>' });
			var ok = md.el.querySelector('[data-ok]');
			ok.onclick = function () {
				if (!af.name.value.trim()) return fail(af, 'Enter the account display name.');
				WD.busy(ok, true);
				api(a ? 'accounts/payment-accounts/' + a.id : 'accounts/payment-accounts', { method: a ? 'PUT' : 'POST', body: { name: af.name.value, type: af.type.value, ref: af.ref.value, active: af.active.checked } }).then(function (list) { md.close(); accSaved(list); WD.toast('Payment account saved'); }, function (er) { WD.busy(ok, false); fail(af, er.message); });
			};
		}
		paintAccounts();
		f.querySelector('[data-acc-add]').onclick = function () { accForm(null); };
		on(accBox, 'click', '[data-acc-edit]', function (e, btn) { accForm(A.meta.payment_accounts.filter(function (a) { return a.id === +btn.getAttribute('data-acc-edit'); })[0]); });
		on(accBox, 'click', '[data-acc-toggle]', function (e, btn) {
			var a = A.meta.payment_accounts.filter(function (x) { return x.id === +btn.getAttribute('data-acc-toggle'); })[0];
			if (a) api('accounts/payment-accounts/' + a.id, { method: 'PUT', body: { active: !a.active } }).then(function (list) { accSaved(list); WD.toast(a.active ? 'Account deactivated — its history is kept' : 'Account activated'); }, WD.toastError);
		});
		on(qr, 'change', '[data-qr-file]', function (e, inp) {
			var file = inp.files[0];
			if (!file) return;
			if (!/[.](png|jpe?g|webp)$/i.test(file.name)) { inp.value = ''; return WD.toast('Choose a PNG, JPG or WEBP image.', 'error'); }
			if (file.size > 2 * 1024 * 1024) { inp.value = ''; return WD.toast('The QR image is larger than 2 MB.', 'error'); }
			var rd = new FileReader();
			rd.onload = function () {
				api('accounts/settings/qr', { method: 'POST', body: { name: file.name, data: rd.result } }).then(function (mm) { A.meta = mm; WD.toast('UPI QR code saved'); paintQr(); }, WD.toastError);
			};
			rd.readAsDataURL(file);
		});
		on(qr, 'click', '[data-qr-rm]', function () {
			WD.confirm('Remove UPI QR code?', 'New and regenerated invoice PDFs will no longer show a QR code. Invoices already issued keep the QR they were made with.', 'Remove', true).then(function (y) {
				if (y) api('accounts/settings/qr', { method: 'DELETE' }).then(function (mm) { A.meta = mm; WD.toast('UPI QR code removed'); paintQr(); }, WD.toastError);
			});
		});
		f.onsubmit = function (e) {
			e.preventDefault();
			var body = {};
			['business_name', 'address', 'phone', 'email', 'website', 'gstin', 'account_name', 'bank_name', 'account_number', 'ifsc', 'upi_id', 'payment_instructions', 'invoice_prefix', 'due_days', 'tax_label', 'tax_rate', 'opening_balance', 'opening_date', 'fy_grace_days', 'archive_expiry_hours', 'wa_template', 'email_subject', 'email_body'].forEach(function (k) { body[k] = f[k].value; });
			body.include_pdf_link = f.include_pdf_link.checked;
			body.expense_categories = f.expense_categories.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
			body.income_sources = f.income_sources.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean);
			var btn = f.querySelector('[data-save]');
			WD.busy(btn, true);
			api('accounts/settings', { method: 'PUT', body: body }).then(function (m) { A.meta = m; WD.busy(btn, false); WD.toast('Accounts settings saved'); }, function (er) { WD.busy(btn, false); fail(f, er.message); });
		};
		return Promise.resolve();
	};
})();
