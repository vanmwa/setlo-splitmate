// Setlo UI helpers: formatters, toast, shared Vue components and the mount() entry point.
(function (global) {
  const { createApp } = global.Vue;

  const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const pesoShort = (n) => '₱' + Math.round(Number(n || 0)).toLocaleString('en-PH');

  const parseDate = (s) => (s instanceof Date ? s : new Date(String(s).replace(' ', 'T')));

  function timeAgo(s) {
    const d = parseDate(s);
    const sec = Math.max(0, (Date.now() - d.getTime()) / 1000);
    if (sec < 60) return 'just now';
    if (sec < 3600) return Math.floor(sec / 60) + 'm ago';
    if (sec < 86400) return Math.floor(sec / 3600) + 'h ago';
    if (sec < 172800) return 'Yesterday';
    if (sec < 604800) return Math.floor(sec / 86400) + 'd ago';
    return fmtDate(d);
  }

  const fmtDate = (s, withYear) =>
    parseDate(s).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', ...(withYear ? { year: 'numeric' } : {}) });

  const fmtDateTime = (s) =>
    parseDate(s).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });

  const fmtMonth = (s) => parseDate(s).toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }).toUpperCase();

  const STATUS_LABELS = {
    draft: 'Draft', active: 'Active', settling: 'Settling', closed: 'Closed',
    pending: 'Pending', awaiting: 'Awaiting Confirmation', settled: 'Settled', disputed: 'Disputed',
    suspended: 'Suspended',
  };

  // ---------- SweetAlert2 dialogs & toasts (loaded in partials/head.php) ----------
  const BRAND = '#0d9488';
  const swal = (opts) => global.Swal.fire({
    confirmButtonColor: BRAND, cancelButtonColor: '#94a3b8', reverseButtons: true, buttonsStyling: true,
    customClass: { popup: 'setlo-swal' }, ...opts,
  });
  const Toast = global.Swal ? global.Swal.mixin({
    toast: true, position: 'top-end', showConfirmButton: false, timer: 2800, timerProgressBar: true,
    customClass: { popup: 'setlo-swal-toast' },
    didOpen: (el) => { el.addEventListener('mouseenter', global.Swal.stopTimer); el.addEventListener('mouseleave', global.Swal.resumeTimer); },
  }) : null;

  /** Small corner notification. tone: 'error' (default) | 'warn' | 'ok' | 'info'. */
  function toast(message, tone) {
    const icon = { error: 'error', warn: 'warning', ok: 'success', info: 'info' }[tone || 'error'] || 'error';
    if (Toast) Toast.fire({ icon, title: message });
    else console.warn(message);
  }

  /** Yes/No dialog. Resolves true when confirmed. */
  async function confirmDialog({ title, text = '', confirmText = 'Yes', cancelText = 'Cancel', danger = false, icon }) {
    const r = await swal({
      title, text, icon: icon || (danger ? 'warning' : 'question'), showCancelButton: true,
      confirmButtonText: confirmText, cancelButtonText: cancelText, confirmButtonColor: danger ? '#e11d48' : BRAND, focusCancel: danger,
    });
    return r.isConfirmed;
  }

  /** Message dialog. */
  function alertDialog(title, text = '', icon = 'info') {
    return swal({ title, text, icon, confirmButtonText: 'OK' });
  }

  /** Text input dialog; validate(value) returns an error message or ''. Resolves the text, or null if cancelled. */
  async function promptText({ title, text = '', placeholder = '', value = '', confirmText = 'Send', input = 'textarea', validate }) {
    const r = await swal({
      title, text, input, inputValue: value, inputPlaceholder: placeholder, showCancelButton: true, confirmButtonText: confirmText,
      inputValidator: (v) => (validate ? validate(v || '') || null : null),
    });
    return r.isConfirmed ? (r.value || '') : null;
  }

  /** Show a value the user should copy (e.g. a temporary password), with a Copy button. */
  async function showCopy(title, value, text = '') {
    const r = await swal({
      title, html: (text ? `<p class="mb-3 text-sm text-slate-500">${escapeHtml(text)}</p>` : '') +
        `<code class="block select-all break-all rounded-xl bg-slate-100 px-4 py-3 text-lg font-bold text-ink">${escapeHtml(value)}</code>`,
      icon: 'success', showCancelButton: true, confirmButtonText: 'Copy', cancelButtonText: 'Close',
    });
    if (r.isConfirmed) {
      try { await navigator.clipboard.writeText(value); toast('Copied to clipboard', 'ok'); } catch (e) { toast('Select the text and copy it manually.', 'warn'); }
    }
  }

  // ---------- Personal "Pay me" QR ----------
  /** Absolute link a Pay-me QR points to (works from any page in /pages/). */
  const payLink = (code) => new URL('pay.php?u=' + encodeURIComponent(code), location.href).href;

  /** SVG markup of a QR code for `text` (needs qrcode-generator, loaded in partials/head.php). */
  function qrSvg(text) {
    if (!global.qrcode) return '';
    const q = global.qrcode(0, 'M');
    q.addData(text);
    q.make();
    return q.createSvgTag({ cellSize: 6, margin: 2, scalable: true });
  }

  /** PNG data URL of a QR code with a caption underneath, for downloading. */
  function qrPng(text, caption = '') {
    const q = global.qrcode(0, 'M');
    q.addData(text);
    q.make();
    const n = q.getModuleCount();
    const cell = 12, pad = 36, size = n * cell + pad * 2, capH = caption ? 64 : 0;
    const c = document.createElement('canvas');
    c.width = size;
    c.height = size + capH;
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.fillStyle = '#0f172a';
    for (let r = 0; r < n; r++) for (let col = 0; col < n; col++) if (q.isDark(r, col)) ctx.fillRect(pad + col * cell, pad + r * cell, cell, cell);
    if (caption) {
      ctx.fillStyle = '#0f766e';
      ctx.font = '700 26px "Plus Jakarta Sans", system-ui, sans-serif';
      ctx.textAlign = 'center';
      ctx.fillText(caption, size / 2, size + 34);
    }
    return c.toDataURL('image/png');
  }

  /** Swal showing someone's Pay-me QR plus their payment details. person: { name, payment_method, payment_account, pay_code } */
  function showPayQr(person) {
    const acct = person.payment_account ? `${person.payment_method} · ${person.payment_account}` : person.payment_method;
    return swal({
      title: `Pay ${escapeHtml(person.name.split(' ')[0])}`,
      html: `<div class="mx-auto h-56 w-56 rounded-2xl border border-slate-200 bg-white p-2">${qrSvg(payLink(person.pay_code))}</div>
             <p class="mt-3 text-[14px] font-bold text-slate-800">${escapeHtml(acct || '')}</p>
             <p class="mt-1 text-[12px] text-slate-500">Scan to open their Setlo pay page, then send the money in ${escapeHtml(person.payment_method)}.</p>`,
      showCancelButton: !!person.payment_account, confirmButtonText: 'Close', cancelButtonText: 'Copy number',
    }).then(async (r) => {
      if (r.dismiss === global.Swal.DismissReason.cancel && person.payment_account) {
        try { await navigator.clipboard.writeText(person.payment_account); toast('Copied', 'ok'); } catch (e) { showCopy('Copy the number', person.payment_account); }
      }
    });
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  // ---------- Shared components ----------
  const Avatar = {
    props: { user: { type: Object, required: true }, size: { type: Number, default: 36 }, ring: { type: Boolean, default: false } },
    template: `<span class="av" :style="{ background: user.color, width: size + 'px', height: size + 'px', fontSize: size * 0.36 + 'px', boxShadow: ring ? undefined : 'none' }" :title="user.name">{{ user.initials }}</span>`,
  };

  const StatusPill = {
    props: { status: String, label: String },
    template: `<span class="pill" :class="'pill-' + status">{{ label || labels[status] || status }}</span>`,
    data: () => ({ labels: STATUS_LABELS }),
  };

  const Spinner = {
    // Fades in after 300 ms, so a quick load never flashes a spinner
    template: `<div class="spinner-delay flex justify-center py-10"><span class="h-7 w-7 animate-spin rounded-full border-[3px] border-brand-200 border-t-brand-600"></span></div>`,
  };

  const NOTIF_ICONS = {
    paid: ['bg-sky-100 text-sky-600', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
    confirmed: ['bg-emerald-100 text-emerald-700', 'M5 13l4 4L19 7'],
    disputed: ['bg-rose-100 text-rose-600', 'M12 9v3.75m0 3.75h.008M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    resent: ['bg-amber-100 text-amber-600', 'M4 4v5h5M20 20v-5h-5M5.1 15a7 7 0 0011.8 2.5M18.9 9A7 7 0 007.1 6.5'],
    added: ['bg-brand-100 text-brand-700', 'M12 4v16m8-8H4'],
    settling: ['bg-yellow-100 text-yellow-700', 'M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z'],
    closed: ['bg-slate-100 text-slate-600', 'M5 13l4 4L19 7'],
    admin: ['bg-slate-800 text-white', 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    nudge: ['bg-amber-100 text-amber-600', 'M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5'],
  };

  const NotifIcon = {
    props: { type: String, size: { type: Number, default: 32 } },
    computed: { icon() { return NOTIF_ICONS[this.type] || NOTIF_ICONS.admin; } },
    template: `<div class="flex shrink-0 items-center justify-center rounded-full" :class="icon[0]" :style="{ width: size + 'px', height: size + 'px' }">
      <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" :d="icon[1]"/></svg>
    </div>`,
  };

  /** Bell button + dropdown, polls every 30 s. */
  const NotifBell = {
    components: { NotifIcon },
    data: () => ({ open: false, items: [], unread: 0, timer: null }),
    mounted() {
      this.load();
      this.timer = setInterval(this.load, 30000);
      document.addEventListener('click', this.outside);
    },
    unmounted() {
      clearInterval(this.timer);
      document.removeEventListener('click', this.outside);
    },
    methods: {
      async load() {
        try {
          const r = await api.get('notifications.php', { limit: 6 });
          this.items = r.notifications;
          this.unread = r.unread;
        } catch (e) { /* bell is non-critical */ }
      },
      outside(e) { if (!this.$el.contains(e.target)) this.open = false; },
      async go(n) {
        if (!n.is_read) api.post('notifications.php', { action: 'read', id: n.id }).catch(() => {});
        location.href = n.link || 'notifications';
      },
      timeAgo,
    },
    template: `<div class="relative">
      <button @click="open = !open" class="glass-btn" aria-label="Notifications" :aria-expanded="open">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
        <span v-if="unread" class="absolute -right-0.5 -top-0.5 flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-amber-400 px-1 text-[10px] font-extrabold text-white ring-2 ring-brand-500">{{ unread > 9 ? '9+' : unread }}</span>
      </button>
      <div v-if="open" class="hero-pop tile absolute right-0 top-12 z-40 w-80 max-w-[88vw] overflow-hidden text-slate-800 shadow-xl">
        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
          <span class="text-sm font-bold text-ink">Notifications</span>
          <a href="notifications" class="text-xs font-bold text-brand-700">See all</a>
        </div>
        <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto">
          <p v-if="!items.length" class="px-4 py-6 text-center text-[13px] text-slate-400">You're all caught up.</p>
          <button v-for="n in items" :key="n.id" @click="go(n)" class="flex w-full gap-3 px-4 py-3 text-left hover:bg-slate-50" :class="{ 'bg-brand-50/50': !n.is_read }">
            <notif-icon :type="n.type"></notif-icon>
            <div class="min-w-0">
              <p class="text-[13px] text-slate-700">{{ n.message }}</p>
              <p class="mt-0.5 text-[11px] text-slate-400">{{ timeAgo(n.created_at) }}</p>
            </div>
          </button>
        </div>
      </div>
    </div>`,
  };

  /** "Share summary" button + preview sheet. Needs assets/js/summary.js on the page. */
  const ShareSummary = {
    props: { d: { type: Object, required: true } },
    data: () => ({ open: false, url: null, blob: null, busy: false, canShareFiles: false, copied: false }),
    methods: {
      async show() {
        this.open = true;
        this.busy = true;
        try {
          const data = SetloSummary.fromBill(this.d);
          const canvas = await SetloSummary.render(data);
          this.blob = await new Promise((r) => canvas.toBlob(r, 'image/png'));
          if (this.url) URL.revokeObjectURL(this.url);
          this.url = URL.createObjectURL(this.blob);
          const file = new File([this.blob], this.fileName(), { type: 'image/png' });
          this.canShareFiles = !!(navigator.canShare && navigator.canShare({ files: [file] }));
        } catch (e) {
          toast('Could not create the summary image.');
          this.open = false;
        } finally {
          this.busy = false;
        }
      },
      fileName() { return 'setlo-' + this.d.bill.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') + '.png'; },
      async share() {
        const file = new File([this.blob], this.fileName(), { type: 'image/png' });
        try { await navigator.share({ files: [file], title: this.d.bill.name, text: SetloSummary.asText(SetloSummary.fromBill(this.d)) }); } catch (e) { /* cancelled */ }
      },
      download() {
        const a = document.createElement('a');
        a.href = this.url;
        a.download = this.fileName();
        a.click();
      },
      async copyText() {
        const text = SetloSummary.asText(SetloSummary.fromBill(this.d));
        try { await navigator.clipboard.writeText(text); } catch (e) { showCopy('Copy the summary', text); }
        this.copied = true;
        setTimeout(() => { this.copied = false; }, 1800);
      },
    },
    template: `<div>
      <button @click="show" class="btn btn-outline w-full">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12v7a1 1 0 001 1h14a1 1 0 001-1v-7M16 6l-4-4-4 4M12 2v14"/></svg>
        Share summary
      </button>
      <div v-if="open" class="sheet-backdrop" @click.self="open = false">
        <div class="sheet">
          <div class="sheet-grip"></div>
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-[17px] font-extrabold text-ink">Bill summary</h2>
            <button @click="open = false" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-500" aria-label="Close">✕</button>
          </div>
          <div class="max-h-[50vh] overflow-y-auto rounded-2xl border border-slate-200 bg-slate-50">
            <div v-if="busy" class="flex justify-center py-16"><span class="h-7 w-7 animate-spin rounded-full border-[3px] border-brand-200 border-t-brand-600"></span></div>
            <img v-else-if="url" :src="url" alt="Bill summary image" class="w-full" />
          </div>
          <div class="mt-4 grid gap-2.5" :class="canShareFiles ? 'grid-cols-3' : 'grid-cols-2'">
            <button v-if="canShareFiles" @click="share" class="btn btn-primary" :disabled="busy">Share</button>
            <button @click="download" class="btn btn-outline" :disabled="busy">Download</button>
            <button @click="copyText" class="btn btn-ghost" :disabled="busy">{{ copied ? 'Copied ✓' : 'Copy text' }}</button>
          </div>
        </div>
      </div>
    </div>`,
  };

  /** What to call a receipt: its title, else the store, else "Receipt 2". */
  const receiptLabel = (rc) => rc.title || rc.store || 'Receipt ' + rc.number;

  /**
   * Items under their receipt, for bills with two or more receipts: [{ key, label, items }]. Typed-in items
   * come last as "Added manually". With one receipt or none, a single unlabelled group.
   */
  function groupByReceipt(items, receipts) {
    receipts = receipts || [];
    if (receipts.length < 2) return [{ key: 'all', label: null, items }];
    const ids = receipts.map((rc) => rc.id);
    const groups = receipts.map((rc) => ({ key: 'r' + rc.id, label: rc.number + '. ' + receiptLabel(rc), items: items.filter((it) => it.receipt_id === rc.id) }));
    const loose = items.filter((it) => !ids.includes(it.receipt_id));
    if (loose.length) groups.push({ key: 'manual', label: 'Added manually', items: loose });
    return groups.filter((g) => g.items.length);
  }

  const helpers = { peso, pesoShort, timeAgo, fmtDate, fmtDateTime, fmtMonth, toast, receiptLabel, statusLabel: (s) => STATUS_LABELS[s] || s };

  /** Create and mount a page's Vue app with the shared components and helpers. */
  function mount(options, selector) {
    const app = createApp(options);
    app.component('avatar', Avatar);
    app.component('status-pill', StatusPill);
    app.component('spinner', Spinner);
    app.component('notif-bell', NotifBell);
    app.component('notif-icon', NotifIcon);
    app.component('share-summary', ShareSummary);
    Object.assign(app.config.globalProperties, helpers);
    app.config.errorHandler = (err) => { console.error(err); toast(err.message || 'Something went wrong.'); };
    return app.mount(selector || '#app');
  }

  /** Run an API call with a busy flag and error toast. Returns the result or undefined on error. */
  async function run(vm, fn, busyKey) {
    const key = busyKey || 'busy';
    vm[key] = true;
    try {
      return await fn();
    } catch (e) {
      toast(e.message);
      return undefined;
    } finally {
      vm[key] = false;
    }
  }

  /**
   * Load a page's data without a blink: show the last saved reply at once (if this tab has one),
   * then always fetch fresh data and apply it again. `apply(reply, fresh)` copies the reply into the page;
   * `fresh` is false for the saved reply, true for the server's.
   */
  async function load(vm, path, params, apply, busyKey) {
    const key = busyKey || 'loading';
    const saved = api.peek(path, params);
    if (saved) apply(saved, false);
    vm[key] = !saved;
    try {
      apply(await api.get(path, params), true);
    } catch (e) {
      toast(e.message);
    } finally {
      vm[key] = false;
    }
  }

  // Sidebar burger ([data-nav-toggle="lg|md"]): on screens at least that wide it shrinks the sidebar
  // to icons (remembered), on smaller ones it opens the slide-in menu. Delegated, so it works in and out of Vue.
  const root = document.documentElement;
  const NAV_BREAK = { lg: 1024, md: 768 };
  const syncBurgers = () => {
    document.querySelectorAll('[data-nav-toggle]').forEach((b) => {
      const wide = window.innerWidth >= (NAV_BREAK[b.dataset.navToggle] || 1024);
      b.setAttribute('aria-expanded', String(wide ? !root.classList.contains('sb-collapsed') : root.classList.contains('nav-open')));
    });
  };
  const closeDrawer = () => { root.classList.remove('nav-open'); syncBurgers(); };
  document.addEventListener('click', (e) => {
    const toggle = e.target.closest('[data-nav-toggle]');
    if (toggle) {
      const brk = NAV_BREAK[toggle.dataset.navToggle] || 1024;
      if (window.innerWidth >= brk) {
        const collapsed = root.classList.toggle('sb-collapsed');
        try { localStorage.setItem('setlo-sidebar', collapsed ? 'collapsed' : 'open'); } catch (err) { /* storage blocked: this page only */ }
      } else {
        root.dataset.navBreak = brk;
        root.classList.toggle('nav-open');
      }
      syncBurgers();
      return;
    }
    if (root.classList.contains('nav-open') && e.target.closest('[data-nav-close], .nav-drawer a, .admin-aside a')) closeDrawer();
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && root.classList.contains('nav-open')) closeDrawer(); });
  window.addEventListener('resize', () => {
    if (root.classList.contains('nav-open') && window.innerWidth >= Number(root.dataset.navBreak || 1024)) closeDrawer();
  });
  window.addEventListener('load', syncBurgers);

  // Confirm before signing out, everywhere a sign-out form appears (sidebar, phone drawer, admin, account menu).
  // Capture phase: runs before the click reaches the <button> and submits the form.
  document.addEventListener('click', (e) => {
    const form = e.target.closest('form[action="logout"]');
    if (!form || !e.target.closest('button')) return;
    e.preventDefault();
    confirmDialog({ title: 'Sign out?', text: 'You can log back in anytime.', confirmText: 'Sign out' })
      .then((ok) => { if (ok) form.submit(); });
  }, true);

  global.Setlo = { mount, run, load, confirm: confirmDialog, alert: alertDialog, promptText, showCopy, escapeHtml, payLink, qrSvg, qrPng, showPayQr, groupByReceipt, ...helpers };
})(window);
