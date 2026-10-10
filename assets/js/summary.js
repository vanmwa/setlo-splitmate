// Shareable bill summary: draws a PNG card on a canvas (no server work, no library) and shares/downloads it.
(function (global) {
  const W = 720;
  const PAD = 44;
  const FONT = '"Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif';
  const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const STATUS = { pending: ['Pending', '#a16207', '#fef9c3'], awaiting: ['Awaiting', '#1d4ed8', '#dbeafe'], settled: ['Settled', '#15803d', '#dcfce7'], disputed: ['Disputed', '#b91c1c', '#fee2e2'] };

  /**
   * data: { name, date, total, subtotal, extras, discount, members: [{id, name, share, paid, color, initials, payment_method, payment_account, is_guest}],
   *         transfers: [{from, to, amount, status?}] (ids), note }
   */
  function layout(data) {
    const rows = [];
    let y = 250;
    rows.push({ type: 'label', text: 'EACH PERSON’S SHARE', y }); y += 30;
    for (const m of data.members) { rows.push({ type: 'member', m, y }); y += 58; }
    if (data.transfers.length) {
      y += 18; rows.push({ type: 'label', text: 'WHO PAYS WHOM', y }); y += 30;
      for (const t of data.transfers) { rows.push({ type: 'transfer', t, y }); y += 50; }
    }
    const receivers = [...new Set(data.transfers.map((t) => t.to))]
      .map((id) => data.members.find((m) => m.id === id))
      .filter((m) => m && m.payment_method && m.payment_method !== 'Cash');
    if (receivers.length) {
      y += 18; rows.push({ type: 'label', text: 'SEND PAYMENTS TO', y }); y += 30;
      for (const m of receivers) { rows.push({ type: 'payto', m, y }); y += 40; }
    }
    return { rows, height: y + 90 };
  }

  function roundRect(ctx, x, y, w, h, r, fill) {
    ctx.beginPath();
    ctx.roundRect(x, y, w, h, r);
    ctx.fillStyle = fill;
    ctx.fill();
  }

  function text(ctx, str, x, y, { size = 22, weight = 600, color = '#0f172a', align = 'left', max } = {}) {
    ctx.font = `${weight} ${size}px ${FONT}`;
    ctx.fillStyle = color;
    ctx.textAlign = align;
    ctx.textBaseline = 'middle';
    let s = String(str);
    if (max) { while (ctx.measureText(s).width > max && s.length > 1) s = s.slice(0, -2) + '…'; }
    ctx.fillText(s, x, y);
  }

  function avatar(ctx, m, cx, cy, r) {
    ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.fillStyle = m.color || (window.SetloTheme ? SetloTheme.color(600) : '#0d9488'); ctx.fill();
    text(ctx, m.initials, cx, cy + 1, { size: r * 0.72, weight: 800, color: '#fff', align: 'center' });
  }

  async function render(data) {
    try { await document.fonts.load(`800 24px ${FONT}`); await document.fonts.load(`600 24px ${FONT}`); } catch (e) { /* fall back to system font */ }
    const { rows, height } = layout(data);
    const scale = 2;
    const canvas = document.createElement('canvas');
    canvas.width = W * scale;
    canvas.height = height * scale;
    const ctx = canvas.getContext('2d');
    ctx.scale(scale, scale);

    // Background + header band
    ctx.fillStyle = '#f1f5f5'; ctx.fillRect(0, 0, W, height);
    const g = ctx.createLinearGradient(0, 0, W, 200);
    g.addColorStop(0, window.SetloTheme ? SetloTheme.color(500) : '#1fbfae'); g.addColorStop(1, window.SetloTheme ? SetloTheme.color(700) : '#0f8a7e');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, 200);
    text(ctx, 'setlo', PAD, 46, { size: 26, weight: 800, color: '#fff' });
    text(ctx, data.date, W - PAD, 46, { size: 18, weight: 600, color: 'rgba(255,255,255,.85)', align: 'right' });
    text(ctx, data.name, PAD, 100, { size: 34, weight: 800, color: '#fff', max: W - PAD * 2 });
    text(ctx, peso(data.total), PAD, 150, { size: 40, weight: 800, color: '#fff' });
    const bits = [data.members.length + ' people'];
    if (data.extras > 0) bits.push('incl. ' + peso(data.extras) + (data.tax_included ? ' service' : ' tax & service'));
    if (data.discount > 0) bits.push(peso(data.discount) + ' discount');
    text(ctx, bits.join(' · '), W - PAD, 150, { size: 17, weight: 600, color: 'rgba(255,255,255,.9)', align: 'right', max: 330 });

    // Card
    roundRect(ctx, PAD / 2, 220, W - PAD, height - 290, 24, '#ffffff');

    const byId = Object.fromEntries(data.members.map((m) => [m.id, m]));
    for (const row of rows) {
      if (row.type === 'label') {
        text(ctx, row.text, PAD + 6, row.y, { size: 15, weight: 800, color: '#64748b' });
      } else if (row.type === 'member') {
        const m = row.m;
        avatar(ctx, m, PAD + 26, row.y + 16, 20);
        text(ctx, m.name + (m.is_guest ? ' (guest)' : ''), PAD + 60, row.y + (m.paid > 0 ? 8 : 16), { size: 21, weight: 700, max: 380 });
        if (m.paid > 0) text(ctx, 'paid ' + peso(m.paid), PAD + 60, row.y + 32, { size: 15, weight: 600, color: window.SetloTheme ? SetloTheme.color(700) : '#0f766e' });
        text(ctx, peso(m.share), W - PAD - 6, row.y + 16, { size: 22, weight: 800, align: 'right' });
      } else if (row.type === 'transfer') {
        const t = row.t;
        const from = byId[t.from] || { name: '?' };
        const to = byId[t.to] || { name: '?' };
        roundRect(ctx, PAD, row.y - 4, W - PAD * 2, 42, 12, '#f8fafa');
        text(ctx, from.name.split(' ')[0] + '  →  ' + to.name.split(' ')[0], PAD + 16, row.y + 17, { size: 19, weight: 700, max: 330 });
        let right = W - PAD - 14;
        if (t.status && STATUS[t.status]) {
          const [label, fg, bg] = STATUS[t.status];
          ctx.font = `800 13px ${FONT}`;
          const w = ctx.measureText(label).width + 20;
          roundRect(ctx, right - w, row.y + 5, w, 24, 12, bg);
          text(ctx, label, right - w / 2, row.y + 17, { size: 13, weight: 800, color: fg, align: 'center' });
          right -= w + 14;
        }
        text(ctx, peso(t.amount), right, row.y + 17, { size: 20, weight: 800, align: 'right' });
      } else if (row.type === 'payto') {
        const m = row.m;
        text(ctx, m.name.split(' ')[0] + ':', PAD + 6, row.y + 10, { size: 18, weight: 700 });
        text(ctx, m.payment_method + (m.payment_account ? ' · ' + m.payment_account : ''), PAD + 130, row.y + 10, { size: 18, weight: 600, color: '#334155', max: W - PAD * 2 - 140 });
      }
    }
    text(ctx, data.note || 'Split fairly with Setlo — scan, split, confirm.', W / 2, height - 36, { size: 15, weight: 600, color: '#94a3b8', align: 'center' });
    return canvas;
  }

  function asText(data) {
    const byId = Object.fromEntries(data.members.map((m) => [m.id, m]));
    const lines = [`🧾 ${data.name} — ${peso(data.total)} (${data.date})`, ''];
    for (const m of data.members) lines.push(`• ${m.name}: ${peso(m.share)}${m.paid > 0 ? ` (paid ${peso(m.paid)})` : ''}`);
    if (data.transfers.length) {
      lines.push('', 'Who pays whom:');
      for (const t of data.transfers) {
        const st = t.status && STATUS[t.status] ? ` [${STATUS[t.status][0]}]` : '';
        lines.push(`• ${byId[t.from].name.split(' ')[0]} → ${byId[t.to].name.split(' ')[0]}: ${peso(t.amount)}${st}`);
      }
      const receivers = [...new Set(data.transfers.map((t) => t.to))].map((id) => byId[id]).filter((m) => m.payment_account);
      if (receivers.length) {
        lines.push('', 'Send to:');
        for (const m of receivers) lines.push(`• ${m.name.split(' ')[0]}: ${m.payment_method} ${m.payment_account}`);
      }
    }
    lines.push('', 'via Setlo');
    return lines.join('\n');
  }

  /** Build summary data from a bills.php?id= response. */
  function fromBill(d) {
    const transfers = d.settlements.length
      ? d.settlements.map((s) => ({ from: s.from.id, to: s.to.id, amount: s.amount, status: s.status }))
      : d.plan.map((t) => ({ from: t.from, to: t.to, amount: t.amount }));
    return {
      name: d.bill.name,
      date: new Date(d.bill.created_at.replace(' ', 'T')).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }),
      total: d.total, extras: d.extras, tax_included: d.bill.tax_included, discount: d.bill.discount,
      members: d.members.map((m) => ({ ...m, share: d.shares[m.id] || 0, paid: (d.payments && d.payments.paid[m.id]) || 0 })),
      transfers,
      note: d.settlements.length ? null : 'Proposed split — final once the bill starts settling.',
    };
  }

  global.SetloSummary = { render, asText, fromBill };
})(window);
