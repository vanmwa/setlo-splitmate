// A person's card: tap someone in a settlement, bill or your profile to see how to pay them, what you owe each
// other (unfinished installments first) and the payments between you, each with its receipt.
// Open from anywhere with Setlo.showPerson(userId).
(function (global) {
  const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const esc = (s) => global.Setlo.escapeHtml(String(s ?? ''));
  const day = (s) => new Date(String(s).replace(' ', 'T')).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
  const METHOD = { online: 'Online', transfer: 'Transfer', cash: 'Cash', credit: 'Kept change' };
  const PART = { confirmed: ['Received', 'text-emerald-700'], awaiting: ['Waiting', 'text-blue-700'], rejected: ['Rejected', 'text-red-600'] };

  const avatar = (u, size) => u.photo
    ? `<img src="${esc(u.photo)}" alt="" class="shrink-0 rounded-full object-cover" style="width:${size}px;height:${size}px" />`
    : `<span class="inline-flex shrink-0 items-center justify-center rounded-full font-extrabold text-white"
    style="width:${size}px;height:${size}px;background:${esc(u.color)};font-size:${Math.round(size * 0.38)}px">${esc(u.initials)}</span>`;

  /** One open debt between us: "Lunch · ₱272.00 left of ₱385.60 · 5% per part", with a paid bar for installments. */
  function debtRow(s, d) {
    const who = s.from_id === d.person.id ? `${esc(d.person.first)} owes you` : `You owe ${esc(d.person.first)}`;
    const pct = s.amount > 0 ? Math.min(100, (100 * s.paid_amount) / s.amount) : 0;
    return `<a href="settlement-audit?id=${s.id}" class="block rounded-xl border border-slate-100 px-3 py-2.5 text-left hover:bg-slate-50">
      <span class="flex items-baseline justify-between gap-2">
        <span class="min-w-0 truncate text-[13px] font-bold text-ink">${esc(s.bill_name)}</span>
        <span class="shrink-0 text-[13px] font-extrabold tabular-nums ${s.from_id === d.person.id ? 'text-emerald-700' : 'text-ink'}">${peso(s.remaining)}</span>
      </span>
      <span class="mt-0.5 block text-[11.5px] text-slate-500">${who}${s.paid_amount > 0 ? ` · ${peso(s.paid_amount)} paid of ${peso(s.amount)}` : ''}${s.interest_rate ? ` · ${esc(s.interest_rate)}% per part` : ''}</span>
      ${s.installment ? `<span class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-amber-400" style="width:${pct}%"></span></span>` : ''}
    </a>`;
  }

  function historyRow(p, d) {
    const [label, tone] = PART[p.status] || [p.status, 'text-slate-500'];
    const dir = p.from.id === d.person.id ? `${esc(d.person.first)} → you` : `You → ${esc(d.person.first)}`;
    const payer = p.paid_by.id !== p.from.id ? ` · paid by ${esc(p.paid_by.first)}` : '';
    return `<div class="flex items-center gap-2 py-2 text-left">
      <span class="min-w-0 flex-1">
        <span class="block truncate text-[12.5px] font-semibold text-ink">${dir} · ${esc(p.bill_name)}</span>
        <span class="block text-[11px] text-slate-400">${day(p.created_at)} · ${esc(Setlo.methodWithVia(p, METHOD))}${payer} · <span class="${tone} font-semibold">${label}</span></span>
      </span>
      <span class="shrink-0 text-[13px] font-bold tabular-nums">${peso(p.amount)}</span>
      ${p.status === 'confirmed' ? `<button type="button" data-receipt="${p.id}" class="shrink-0 text-[11.5px] font-bold text-brand-700 underline">Receipt</button>` : ''}
    </div>`;
  }

  function html(d) {
    const p = d.person;
    const net = Math.round((d.they_owe - d.i_owe) * 100) / 100;
    const section = (title, body) => `<p class="mt-4 text-left text-[11px] font-extrabold tracking-[.06em] text-slate-400">${title}</p>${body}`;
    return `<div class="text-left">
      <div class="flex items-center gap-3 pr-9">
        ${avatar(p, 48)}
        <div class="min-w-0 flex-1">
          <p class="truncate text-[17px] font-extrabold text-ink">${esc(p.name)}${p.is_guest ? ' <span class="text-[12px] font-semibold text-slate-400">(guest)</span>' : ''}</p>
          ${p.badge ? `<span class="status-badge mt-0.5">${esc(p.badge.emoji)} ${esc(p.badge.title)}</span>` : ''}
          <p class="truncate text-[12.5px] text-slate-500">${p.is_guest ? 'No account — the bill creator pays for them' : esc(p.payment_method) + (p.payment_account ? ' · ' + esc(p.payment_account) : '')}</p>
        </div>
        ${p.pay_code && !p.is_me ? '<button type="button" data-qr class="shrink-0 text-[12px] font-bold text-brand-700">Show QR</button>' : ''}
      </div>
      <div class="mt-4 grid grid-cols-2 gap-2">
        <div class="rounded-xl bg-emerald-50 px-3 py-2"><p class="text-[10.5px] font-bold uppercase tracking-wide text-emerald-700">Owes you</p><p class="text-[17px] font-extrabold tabular-nums text-emerald-800">${peso(d.they_owe)}</p></div>
        <div class="rounded-xl bg-slate-50 px-3 py-2"><p class="text-[10.5px] font-bold uppercase tracking-wide text-slate-500">You owe</p><p class="text-[17px] font-extrabold tabular-nums text-ink">${peso(d.i_owe)}</p></div>
      </div>
      ${p.badges?.length ? `<div class="mt-3 flex flex-wrap gap-1.5">${p.badges.map((a) => `<button type="button" data-badge="${esc(a.code)}" class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11.5px] font-semibold text-slate-600 hover:bg-slate-50" title="${esc(a.description)}">${esc(a.emoji)} ${esc(a.title)}</button>`).join('')}</div>` : ''}
      ${d.their_credit > 0 ? `<p class="mt-2 rounded-xl bg-violet-50 px-3 py-2 text-[12px] text-violet-800">💵 You kept <b>${peso(d.their_credit)}</b> of ${esc(p.first)}'s change — it pays their next debt to you, or pay it back (Utang).</p>` : ''}
      ${d.my_credit > 0 ? `<p class="mt-2 rounded-xl bg-violet-50 px-3 py-2 text-[12px] text-violet-800">💵 ${esc(p.first)} kept <b>${peso(d.my_credit)}</b> of your change — it pays your next debt to them, or they pay it back.</p>` : ''}
      ${net !== 0 && d.they_owe > 0 && d.i_owe > 0 ? `<p class="mt-2 text-[12px] text-slate-500">Overall, ${net > 0 ? esc(p.first) + ' owes you' : 'you owe ' + esc(p.first)} ${peso(Math.abs(net))}.</p>` : ''}
      ${d.installments.length ? section('UNFINISHED INSTALLMENTS', `<div class="mt-1.5 space-y-1.5">${d.installments.map((s) => debtRow(s, d)).join('')}</div>`) : ''}
      ${d.open.length ? section('OTHER OPEN DEBTS', `<div class="mt-1.5 space-y-1.5">${d.open.map((s) => debtRow(s, d)).join('')}</div>`) : ''}
      ${section('PAYMENT HISTORY', d.history.length
        ? `<div class="mt-1 divide-y divide-slate-100">${d.history.map((h) => historyRow(h, d)).join('')}</div>`
        : `<p class="mt-1.5 text-[12.5px] text-slate-400">No payments between you yet.</p>`)}
    </div>`;
  }

  async function show(userId) {
    let d;
    try {
      d = await api.get('people.php', { id: userId });
    } catch (e) {
      global.Setlo.toast(e.message);
      return;
    }
    global.Swal.fire({
      html: html(d), showConfirmButton: false, showCloseButton: true, padding: '1.25rem',
      customClass: { popup: 'setlo-swal' },
      didOpen: (el) => {
        el.addEventListener('click', (e) => {
          const r = e.target.closest('[data-receipt]');
          if (r) global.Setlo.showReceipt(Number(r.dataset.receipt));
          if (e.target.closest('[data-qr]')) global.Setlo.showPayQr(d.person);
          const b = e.target.closest('[data-badge]');
          if (b) global.Setlo.showAchievement(d.person.badges.find((a) => a.code === b.dataset.badge));
        });
      },
    });
  }

  global.Setlo.showPerson = show;
})(window);
