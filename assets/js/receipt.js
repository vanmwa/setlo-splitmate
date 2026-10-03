// Payment receipts: every received payment part has one. Drawn on a canvas (no server work, no library), shown in a
// dialog, and saved or shared as a PNG. Open one from anywhere with Setlo.showReceipt(paymentId).
(function (global) {
  const W = 600;
  const PAD = 40;
  const FONT = '"Plus Jakarta Sans", ui-sans-serif, system-ui, sans-serif';
  const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const METHOD = { online: 'Online via PayMongo', transfer: 'Transfer', cash: 'Cash, in person', credit: 'Credit from an earlier overpayment' };
  const when = (s) => new Date(String(s).replace(' ', 'T')).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });

  function text(ctx, str, x, y, { size = 20, weight = 600, color = '#0f172a', align = 'left', max } = {}) {
    ctx.font = `${weight} ${size}px ${FONT}`;
    ctx.fillStyle = color;
    ctx.textAlign = align;
    ctx.textBaseline = 'middle';
    let s = String(str);
    if (max) { while (ctx.measureText(s).width > max && s.length > 1) s = s.slice(0, -2) + '…'; }
    ctx.fillText(s, x, y);
  }

  /** The label/value lines of a receipt, in order. */
  function lines(r) {
    const out = [
      ['Received from', r.from.name + (r.from.is_guest ? ' (guest)' : '')],
    ];
    if (r.paid_by.id !== r.from.id) out.push(['Paid by', r.paid_by.name + (r.pay_back ? ' (to be paid back)' : '')]);
    out.push(['Paid to', r.to.name]);
    out.push([r.kind === 'loan' ? 'For utang' : 'For bill', r.for]);
    out.push(['Method', METHOD[r.method] || r.method]);
    if (r.payment_ref) out.push([r.method === 'online' ? 'PayMongo ref' : 'Reference no.', r.payment_ref]);
    if (r.tendered !== null && r.tendered > r.amount) {
      out.push(['Cash handed over', peso(r.tendered)]);
      if (r.change > 0) out.push(['Change given back', peso(r.change)]);
      if (r.credit > 0) out.push(['Kept as credit', peso(r.credit)]);
    }
    out.push(null); // divider
    out.push(['Paid so far', peso(r.paid_so_far)]);
    out.push(['Still to pay now', peso(r.left_now)]);
    return out;
  }

  async function render(r) {
    try { await document.fonts.load(`800 24px ${FONT}`); await document.fonts.load(`600 24px ${FONT}`); } catch (e) { /* system font */ }
    const rows = lines(r);
    const height = 300 + rows.length * 40 + 70;
    const scale = 2;
    const canvas = document.createElement('canvas');
    canvas.width = W * scale;
    canvas.height = height * scale;
    const ctx = canvas.getContext('2d');
    ctx.scale(scale, scale);

    ctx.fillStyle = '#f1f5f5'; ctx.fillRect(0, 0, W, height);
    const g = ctx.createLinearGradient(0, 0, W, 190);
    g.addColorStop(0, '#1fbfae'); g.addColorStop(1, '#0f8a7e');
    ctx.fillStyle = g; ctx.fillRect(0, 0, W, 190);
    text(ctx, 'setlo', PAD, 42, { size: 24, weight: 800, color: '#fff' });
    text(ctx, 'PAYMENT RECEIPT', W - PAD, 42, { size: 15, weight: 800, color: 'rgba(255,255,255,.85)', align: 'right' });
    text(ctx, peso(r.amount), PAD, 104, { size: 42, weight: 800, color: '#fff' });
    text(ctx, r.no + ' · ' + when(r.received_at), PAD, 152, { size: 16, weight: 600, color: 'rgba(255,255,255,.9)' });

    ctx.beginPath(); ctx.roundRect(PAD / 2, 210, W - PAD, height - 260, 22); ctx.fillStyle = '#fff'; ctx.fill();
    let y = 250;
    for (const row of rows) {
      if (!row) {
        ctx.fillStyle = '#e2e8f0'; ctx.fillRect(PAD, y - 12, W - PAD * 2, 1.5);
        y += 10;
        continue;
      }
      text(ctx, row[0], PAD + 6, y, { size: 17, weight: 600, color: '#64748b' });
      text(ctx, row[1], W - PAD - 6, y, { size: 18, weight: 700, align: 'right', max: W - PAD * 2 - 200 });
      y += 40;
    }
    const by = r.method === 'online' ? 'Verified by PayMongo' : 'Confirmed by ' + r.to.name.split(' ')[0];
    text(ctx, by + ' · recorded on Setlo', W / 2, height - 28, { size: 14, weight: 600, color: '#94a3b8', align: 'center' });
    return canvas;
  }

  const blobOf = (canvas) => new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));

  /** Fetch a payment's receipt and show it, with Save image (and Share, where the phone supports it). */
  async function show(paymentId) {
    let r;
    try {
      r = (await api.get('settlements.php', { receipt: paymentId })).receipt;
    } catch (e) {
      global.Setlo.toast(e.message);
      return;
    }
    const canvas = await render(r);
    const blob = await blobOf(canvas);
    const file = new File([blob], `setlo-receipt-${r.no}.png`, { type: 'image/png' });
    const url = URL.createObjectURL(blob);
    const canShare = !!(navigator.canShare && navigator.canShare({ files: [file] }));
    const res = await global.Swal.fire({
      html: `<img src="${url}" alt="Receipt ${r.no} for ${global.Setlo.escapeHtml(peso(r.amount))}" style="width:100%;border-radius:16px" />`,
      showConfirmButton: true, confirmButtonText: 'Save image', confirmButtonColor: '#0d9488',
      showDenyButton: canShare, denyButtonText: 'Share', denyButtonColor: '#64748b',
      showCloseButton: true, padding: '1rem',
    });
    if (res.isConfirmed) {
      const a = document.createElement('a');
      a.href = url;
      a.download = file.name;
      a.click();
    } else if (res.isDenied) {
      try { await navigator.share({ files: [file], title: 'Receipt ' + r.no }); } catch (e) { /* cancelled */ }
    }
    setTimeout(() => URL.revokeObjectURL(url), 60000);
  }

  global.Setlo.showReceipt = show;
  global.Setlo.receiptLines = lines; // for tests
})(window);
