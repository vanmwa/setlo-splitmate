<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Review Items';
$nav = 'scan';
$billId = (int) ($_GET['bill'] ?? 0);
if (!$billId) {
    redirect('pages/scan-receipt');
}
$back = 'scan-receipt.php?bill=' . $billId;
$step = 2;
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0">
        <h1 class="text-[19px] font-extrabold leading-tight tracking-tight">Review Extracted Items</h1>
        <p class="truncate text-[12px] font-medium text-brand-50/90">{{ bill ? bill.name : '' }}</p>
      </div>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/steps.php'; ?>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-4 px-5 pb-4 pt-4">
    <!-- OCR summary -->
    <div class="tile flex items-center gap-3.5 p-3.5">
      <div class="h-[60px] w-[84px] shrink-0 space-y-1.5 rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-2">
        <div class="paper-line"></div><div class="paper-line w-4/5"></div><div class="paper-line"></div><div class="paper-line w-3/5"></div><div class="paper-line"></div>
      </div>
      <div class="min-w-0 flex-1">
        <p class="text-[14px] font-extrabold leading-snug text-ink">{{ headline }}</p>
        <p class="mt-0.5 text-[12px] leading-snug text-slate-500">{{ rows.length }} items · {{ flaggedCount ? flaggedCount + ' needs review' : 'all confirmed' }}</p>
      </div>
      <button v-if="!receipts.length && bill.has_receipt" @click="showPhotos([])" class="h-10 shrink-0 rounded-xl border-[1.5px] border-brand-300 px-4 text-[13px] font-bold text-brand-700 hover:bg-brand-50">Photo</button>
    </div>

    <!-- Same purchase scanned twice -->
    <div v-for="rc in dupReceipts" :key="'dup' + rc.id" class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-[12.5px] leading-relaxed text-rose-800" role="alert">
      <b class="text-rose-900">{{ rc.number }}. {{ receiptLabel(rc) }}:</b> {{ rc.dup_note }}
      <div class="mt-2 flex gap-4">
        <button @click="removeReceipt(rc)" :disabled="busy" class="font-bold text-rose-900 underline underline-offset-2">Remove Receipt {{ rc.number }}</button>
        <button @click="keepReceipt(rc)" :disabled="busy" class="font-bold text-rose-700">Keep both</button>
      </div>
    </div>

    <div v-if="flaggedCount" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-[12.5px] leading-relaxed text-amber-800">
      <b class="text-amber-900">Check {{ flaggedCount === 1 ? 'one item' : flaggedCount + ' items' }}.</b> Receipt codes were turned into plain names, and unclear reads and possible repeats are marked — confirm or correct the highlighted {{ flaggedCount === 1 ? 'row' : 'rows' }}.
      <button v-if="flaggedCount > 1" @click="resolveAll" class="mt-2 block font-bold text-amber-900 underline underline-offset-2">All look right</button>
    </div>

    <section v-for="g in groups" :key="g.key">
      <!-- Several separate receipts: each gets a title ("Lunch", "Snacks - Cafe") that labels its items from here on -->
      <div v-if="g.receipt && receipts.length > 1" class="mb-2 px-1">
        <div class="flex items-center gap-2">
          <span class="shrink-0 text-[13px] font-extrabold text-slate-500">{{ g.receipt.number }}.</span>
          <input :value="g.receipt.title || ''" @change="saveTitle(g.receipt, $event)" @keydown.enter.prevent="$event.target.blur()" maxlength="60"
            :placeholder="'Title, e.g. ' + (g.receipt.number === 1 ? 'Lunch' : 'Snacks - Cafe')" class="row-in !h-9 min-w-0 flex-1 font-bold" :aria-label="'Title of receipt ' + g.receipt.number" />
          <button v-if="g.receipt.photos.length" @click="showPhotos(g.receipt.photos)" class="shrink-0 text-[12px] font-bold text-brand-700">Photo{{ g.receipt.photos.length > 1 ? 's' : '' }}</button>
          <button @click="removeReceipt(g.receipt)" :disabled="busy" class="shrink-0 text-[12px] font-bold text-slate-400 hover:text-rose-500">Remove</button>
        </div>
        <p v-if="g.receipt.store || g.receipt.total !== null" class="mt-1 pl-5 text-[11.5px] text-slate-400">
          {{ [g.receipt.store, g.receipt.total !== null ? peso(g.receipt.total) : null].filter(Boolean).join(' · ') }}
        </p>
      </div>
      <div v-else-if="g.receipt" class="mb-2 flex items-center gap-2 px-1">
        <p class="min-w-0 flex-1 truncate text-[12px] font-extrabold tracking-[.04em] text-slate-500">
          RECEIPT {{ g.receipt.number }}<span v-if="g.receipt.store" class="font-semibold normal-case tracking-normal"> · {{ g.receipt.store }}</span><span v-if="g.receipt.total !== null" class="font-semibold tracking-normal"> · {{ peso(g.receipt.total) }}</span>
        </p>
        <button v-if="g.receipt.photos.length" @click="showPhotos(g.receipt.photos)" class="text-[12px] font-bold text-brand-700">Photo{{ g.receipt.photos.length > 1 ? 's' : '' }}</button>
        <button @click="removeReceipt(g.receipt)" :disabled="busy" class="text-[12px] font-bold text-slate-400 hover:text-rose-500">Remove</button>
      </div>
      <p v-else class="section-label">{{ g.label }}</p>
      <p v-if="g.receipt && !g.rows.length" class="tile px-4 py-3 text-[12.5px] text-slate-500">
        {{ g.receipt.ocr_status === 'failed' ? 'Couldn’t read this receipt' : 'No items found on this receipt' }} — add its items below with “+ Add missed item”, or remove it.
      </p>
      <div class="space-y-3">
        <div v-for="r in g.rows" :key="r.key" :ref="'row' + r.key" class="tile p-3" :class="{ flagged: r.needs_review, 'animate-nudge': r.nudge }">
          <div class="flex gap-2">
            <input v-model="r.name" @input="touch('name' + r.key)" @blur="touch('name' + r.key)" :data-field="'name' + r.key" :class="{ 'is-invalid': err('name' + r.key) }" class="row-in flex-1" placeholder="Item name" maxlength="120" aria-label="Item name" />
            <input :value="r.qty" @input="r.qty = filterInt($event.target.value); $event.target.value = r.qty; touch('qty' + r.key)" :data-field="'qty' + r.key" :class="{ 'is-invalid': err('qty' + r.key) }" class="row-in row-qty" inputmode="numeric" maxlength="3" aria-label="Quantity" />
            <input :value="r.unit_price" @input="r.unit_price = filterMoney($event.target.value); $event.target.value = r.unit_price; touch('price' + r.key)" :data-field="'price' + r.key" :class="{ 'is-invalid': err('price' + r.key) }" class="row-in w-[92px] text-right" inputmode="decimal" aria-label="Unit price" placeholder="0.00" />
          </div>
          <p v-if="rowErr(r)" class="field-error !mt-1">{{ rowErr(r) }}</p>
          <p v-if="r.details" class="mt-1.5 px-0.5 text-[11.5px] text-slate-500">Meal set · includes {{ r.details }}</p>
          <p v-if="r.renamedFrom" class="mt-1 px-0.5 text-[11px] text-slate-400">Printed: “{{ r.renamedFrom }}”</p>
          <div class="mt-2 flex items-center justify-between gap-2 px-0.5 text-[11.5px]">
            <span v-if="r.needs_review" class="font-bold text-amber-600">⚠ {{ flagText(r) }}</span>
            <span v-else class="flex items-center gap-1 font-bold text-brand-600">
              <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              {{ r.fixed ? 'Confirmed' : r.id ? (r.source === 'ocr' ? 'High confidence' : 'Saved') : 'Added manually' }}
            </span>
            <span class="flex shrink-0 items-center gap-2 text-slate-400">
              Line total {{ peso(lineTotal(r)) }}
              <button @click="remove(r)" class="font-bold text-slate-300 hover:text-rose-500" aria-label="Remove item">✕</button>
            </span>
          </div>
          <div v-if="r.needs_review && r.suggestion" class="mt-2.5 grid grid-cols-2 gap-2">
            <button @click="resolve(r, true)" class="h-9 rounded-xl bg-brand-600 text-[12px] font-bold text-white">Use “{{ r.suggestion }}”</button>
            <button @click="resolve(r, false)" class="h-9 rounded-xl border border-amber-300 bg-white text-[12px] font-bold text-amber-700">Keep as typed</button>
          </div>
          <div v-else-if="r.needs_review && r.dup_note" class="mt-2.5 grid grid-cols-2 gap-2">
            <button @click="resolve(r, false)" class="h-9 rounded-xl bg-brand-600 text-[12px] font-bold text-white">Keep it</button>
            <button @click="remove(r)" class="h-9 rounded-xl border border-amber-300 bg-white text-[12px] font-bold text-amber-700">Remove the repeat</button>
          </div>
          <div v-else-if="r.needs_review" class="mt-2.5 grid gap-2" :class="r.renamedFrom ? 'grid-cols-2' : 'grid-cols-1'">
            <button @click="resolve(r, false)" class="h-9 rounded-xl bg-brand-600 text-[12px] font-bold text-white">Looks right</button>
            <button v-if="r.renamedFrom" @click="usePrinted(r)" class="h-9 rounded-xl border border-amber-300 bg-white text-[12px] font-bold text-amber-700">Use printed name</button>
          </div>
        </div>
      </div>
    </section>

    <div class="grid grid-cols-2 gap-3">
      <button @click="add" class="h-11 rounded-2xl border-[1.5px] border-dashed border-slate-300 text-[13px] font-bold text-slate-500 hover:border-brand-400 hover:text-brand-700">+ Add {{ rows.length ? 'missed' : 'an' }} item</button>
      <button @click="addReceipt" :disabled="busy" class="h-11 rounded-2xl border-[1.5px] border-dashed border-brand-300 text-[13px] font-bold text-brand-700 hover:bg-brand-50">+ Add another receipt</button>
    </div>

    <!-- Receipt totals -->
    <div class="tile space-y-2 p-4 text-[13px]">
      <p v-if="receipts.length > 1" class="text-[11.5px] text-slate-400">Tax, service charge, discount and total are the {{ receipts.length }} receipts added together.</p>
      <div class="flex items-center justify-between"><span class="text-slate-500">Subtotal</span><span class="font-semibold text-slate-800">{{ peso(subtotal) }}</span></div>
      <label class="flex items-center justify-between"><span class="text-slate-500">Tax <span v-if="taxIncluded" class="text-[11px] font-semibold text-brand-600">(already in prices — not added)</span></span><input :value="tax" @input="tax = filterMoney($event.target.value); $event.target.value = tax; touch('tax')" data-field="tax" :class="{ 'is-invalid': err('tax') }" inputmode="decimal" class="row-in !h-9 w-24 text-right" aria-label="Tax" /></label>
      <label class="flex items-center justify-between"><span class="text-slate-500">Service charge</span><input :value="svc" @input="svc = filterMoney($event.target.value); $event.target.value = svc; touch('svc')" data-field="svc" :class="{ 'is-invalid': err('svc') }" inputmode="decimal" class="row-in !h-9 w-24 text-right" aria-label="Service charge" /></label>
      <label class="flex items-center justify-between"><span class="text-slate-500">Discount <span class="text-[11px] text-slate-400">(Senior/PWD, promo)</span></span><span class="flex items-center gap-1 text-slate-400">−<input :value="discount" @input="discount = filterMoney($event.target.value); $event.target.value = discount; touch('discount')" data-field="discount" :class="{ 'is-invalid': err('discount') }" inputmode="decimal" class="row-in !h-9 w-24 text-right text-emerald-700" aria-label="Discount" /></span></label>
      <p v-if="money(discount) > 0" class="text-[11.5px] text-slate-400">You'll choose who gets the discount on the next step.</p>
      <label class="mt-1 flex items-center justify-between border-t border-slate-100 pt-2 text-[14px]">
        <span class="font-extrabold text-ink">Receipt total <span class="text-[11px] font-medium text-slate-400">(as printed)</span></span>
        <input :value="receiptTotal" @input="receiptTotal = filterMoney($event.target.value); $event.target.value = receiptTotal; touch('receipt')" data-field="receipt" :class="{ 'is-invalid': err('receipt') }" inputmode="decimal" placeholder="optional" aria-label="Receipt total" class="row-in !h-9 w-28 text-right font-extrabold text-brand-700" />
      </label>
      <p v-if="receiptTotal !== '' && receiptTotal !== null" class="text-[12px] font-semibold" :class="diff === 0 ? 'text-brand-600' : 'text-rose-500'">
        {{ diff === 0 ? '✓ Matches the receipt total' : 'Off by ' + peso(Math.abs(diff)) + ' — check quantities and prices' }}
      </p>
    </div>
  </div>

  <div class="dock">
    <div class="dock-inner">
      <div>
        <p class="text-[11px] font-medium text-slate-400">{{ rows.length }} items · total</p>
        <p class="text-[21px] font-extrabold leading-tight tracking-tight text-white">{{ peso(total) }}</p>
      </div>
      <button @click="save" class="dock-btn" :disabled="busy">{{ busy ? 'Saving…' : 'Continue' }}</button>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <div v-if="photos" class="fixed inset-0 z-50 flex flex-col items-center gap-3 overflow-y-auto bg-ink/70 p-6" @click.self="photos = null">
    <img v-for="src in photos" :key="src" :src="src" alt="Receipt photo" class="max-h-[85vh] max-w-full rounded-2xl bg-white shadow-2xl" @click="photos = null" />
  </div>
</div>

<script>
const money = (v) => Math.round((parseFloat(String(v).replace(/,/g, '')) || 0) * 100) / 100;
const cents = (v) => Math.round(money(v) * 100);
/** The printed text, when the scan gave the item a different plain name (mirrors the check in includes/ocr.php). */
const nameKey = (s) => String(s).toLowerCase().replace(/[^a-z0-9]/g, '');
const renamedFrom = (it) => (it.printed_name && nameKey(it.printed_name) !== nameKey(it.name) ? it.printed_name : null);
const PHOTO_URL = '<?= h(url('api/receipts.php')) ?>';
let keySeq = 0;

Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    billId: <?= $billId ?>, loading: true, busy: false, photos: null, shown: null,
    bill: null, receipts: [], rows: [], tax: '0.00', svc: '0.00', discount: '0.00', receiptTotal: '',
  }),
  computed: {
    flaggedCount() { return this.rows.filter((r) => r.needs_review).length; },
    subtotal() { return this.rows.reduce((s, r) => s + this.lineTotal(r), 0); },
    /** Mirrors tax_included() in includes/domain.php: tax already in the prices if leaving it out is what matches the printed total. */
    taxIncluded() {
      const tax = cents(this.tax);
      if (tax <= 0 || this.receiptTotal === '' || this.receiptTotal === null) return false;
      const printed = cents(this.receiptTotal);
      const withoutTax = cents(this.subtotal) + cents(this.svc) - cents(this.discount);
      const offWithout = Math.abs(withoutTax - printed);
      return offWithout <= 100 && offWithout < Math.abs(withoutTax + tax - printed);
    },
    total() { return Math.round((this.subtotal + (this.taxIncluded ? 0 : money(this.tax)) + money(this.svc) - money(this.discount)) * 100) / 100; },
    diff() { return Math.round((this.total - money(this.receiptTotal)) * 100) / 100; },
    headline() {
      if (this.receipts.length > 1) return this.receipts.length + ' receipts scanned';
      if (this.bill.ocr_status === 'ok') return 'OCR scan complete';
      if (this.bill.ocr_status === 'failed') return 'Photo saved — enter items';
      return 'Manual entry';
    },
    dupReceipts() { return this.receipts.filter((rc) => rc.dup_note); },
    /** Items under their receipt; typed-in rows (and everything, when nothing was scanned) in one plain list. */
    groups() {
      if (!this.receipts.length) return [{ key: 'all', receipt: null, label: this.bill.ocr_status === 'ok' ? 'EXTRACTED ITEMS' : 'ITEMS', rows: this.rows }];
      const ids = this.receipts.map((rc) => rc.id);
      const out = this.receipts.map((rc) => ({ key: 'r' + rc.id, receipt: rc, rows: this.rows.filter((r) => r.receipt_id === rc.id) }));
      const loose = this.rows.filter((r) => !ids.includes(r.receipt_id));
      if (loose.length) out.push({ key: 'manual', receipt: null, label: 'ADDED MANUALLY', rows: loose });
      return out;
    },
  },
  async mounted() {
    await Setlo.load(this, 'bills.php', { id: this.billId }, (r, fresh) => {
      if (!r.me.is_creator || r.bill.locked) {
        location.replace('bill-items?bill=' + this.billId);
        return;
      }
      if (fresh && this.shown !== null && this.shown !== this.formState()) return; // already typing: keep their work
      this.fill(r);
    });
  },
  methods: {
    money,
    fill(r) {
      this.bill = r.bill;
      this.receipts = r.receipts || []; // a cached reply from before receipts existed has none
      this.rows = r.items.map((it) => ({ ...it, key: ++keySeq, unit_price: it.unit_price.toFixed(2), fixed: false, nudge: false, renamedFrom: renamedFrom(it) }));
      this.tax = r.bill.tax.toFixed(2);
      this.svc = r.bill.service_charge.toFixed(2);
      this.discount = r.bill.discount.toFixed(2);
      this.receiptTotal = r.bill.receipt_total === null ? '' : r.bill.receipt_total.toFixed(2);
      if (!this.rows.length && !this.receipts.length) this.add();
      this.shown = this.formState();
    },
    async reload() { this.fill(await api.get('bills.php', { id: this.billId })); },
    formState() {
      return JSON.stringify([this.rows.map(({ key, ...row }) => row), this.tax, this.svc, this.discount, this.receiptTotal]);
    },
    flagText(r) {
      if (r.suggestion) return 'Looks like “' + r.suggestion + '”?';
      if (r.dup_note) return r.dup_note;
      return r.renamedFrom ? 'Renamed from the receipt code — is this right?' : 'Please double-check this item';
    },
    showPhotos(ids) {
      const base = PHOTO_URL + '?bill_id=' + this.billId;
      this.photos = ids.length ? ids.map((id) => base + '&photo_id=' + id) : [base];
    },
    lineTotal(r) { return Math.round((Number(r.qty) || 0) * money(r.unit_price) * 100) / 100; },
    resolve(r, useSuggestion) {
      if (useSuggestion) r.name = r.suggestion;
      r.needs_review = false;
      r.fixed = true;
    },
    usePrinted(r) {
      r.name = r.renamedFrom;
      r.renamedFrom = null;
      this.resolve(r, false);
    },
    resolveAll() {
      for (const r of this.rows) if (r.needs_review) this.resolve(r, false);
    },
    rowEl(r) {
      const el = this.$refs['row' + r.key];
      return Array.isArray(el) ? el[0] : el;
    },
    add() {
      const r = { id: null, receipt_id: null, key: ++keySeq, name: '', qty: 1, unit_price: '', needs_review: false, source: 'manual', fixed: false, nudge: false };
      this.rows.push(r);
      this.$nextTick(() => this.rowEl(r)?.querySelector('input')?.focus());
    },
    remove(r) { this.rows.splice(this.rows.indexOf(r), 1); },
    flash(r, msg) {
      this.rowEl(r)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      r.nudge = false;
      this.$nextTick(() => { r.nudge = true; setTimeout(() => { r.nudge = false; }, 700); });
      Setlo.toast(msg, 'warn');
    },
    validators() {
      const v = {};
      for (const r of this.rows) {
        v['name' + r.key] = V.required(r.name, 'Item name') || V.maxLen(r.name, 120, 'Item name') || (/[<>]/.test(r.name) ? 'Item name can’t contain < or >.' : '');
        v['qty' + r.key] = V.int(r.qty, 1, 999, 'Quantity');
        v['price' + r.key] = V.money(r.unit_price, { required: true, label: 'Price' });
      }
      v.tax = V.money(this.tax, { label: 'Tax' });
      v.svc = V.money(this.svc, { label: 'Service charge' });
      v.discount = V.money(this.discount, { label: 'Discount' }) || (money(this.discount) > this.subtotal ? 'The discount can’t be more than the items subtotal.' : '');
      v.receipt = V.money(this.receiptTotal, { label: 'Receipt total' });
      return v;
    },
    rowErr(r) { return this.err('name' + r.key) || this.err('qty' + r.key) || this.err('price' + r.key); },
    payload() {
      return {
        bill_id: this.billId,
        items: this.rows.map((x) => ({ id: x.id, name: x.name.trim(), qty: Number(x.qty), unit_price: money(x.unit_price), needs_review: !!x.needs_review })),
        tax: money(this.tax), service_charge: money(this.svc), discount: money(this.discount),
        receipt_total: this.receiptTotal === '' ? null : money(this.receiptTotal),
      };
    },
    /**
     * Save edits without leaving (open flags stay open), before an action that reloads or leaves the page.
     * Resolves false when the form has errors to fix first.
     */
    async saveDraft() {
      if (this.formState() === this.shown || !this.rows.length) return true;
      if (!this.validateAll()) return false;
      await api.post('items.php', this.payload());
      return true;
    },
    async addReceipt() {
      const ok = await Setlo.run(this, () => this.saveDraft());
      if (ok) location.href = 'scan-receipt?bill=' + this.billId + '&add=1';
    },
    async removeReceipt(rc) {
      const yes = await Setlo.confirm({
        title: `Remove ${this.receiptLabel(rc)}?`, danger: true, confirmText: 'Remove',
        text: `Its items, photo and amounts (tax, service charge, total) come off this bill.`,
      });
      if (!yes) return;
      const done = await Setlo.run(this, async () => {
        if (!(await this.saveDraft())) return false;
        await api.post('receipts.php', { action: 'remove_receipt', bill_id: this.billId, receipt_id: rc.id });
        await this.reload();
        return true;
      });
      if (done) Setlo.toast(`${this.receiptLabel(rc)} removed`, 'ok');
    },
    async saveTitle(rc, e) {
      const title = e.target.value.trim().replace(/\s+/g, ' ');
      if (title === (rc.title || '')) return;
      if (/[<>]/.test(title)) { Setlo.toast('The title can’t contain < or >.', 'warn'); e.target.value = rc.title || ''; return; }
      try {
        const r = await api.post('receipts.php', { action: 'set_title', bill_id: this.billId, receipt_id: rc.id, title });
        this.receipts = r.receipts;
      } catch (err) {
        Setlo.toast(err.message);
        e.target.value = rc.title || '';
      }
    },
    async keepReceipt(rc) {
      const r = await Setlo.run(this, () => api.post('receipts.php', { action: 'keep_receipt', bill_id: this.billId, receipt_id: rc.id }));
      if (r) this.receipts = r.receipts;
    },
    async save() {
      const flagged = this.rows.find((r) => r.needs_review);
      if (flagged) return this.flash(flagged, 'Confirm the highlighted item first');
      if (!this.rows.length) return Setlo.toast('Add at least one item.', 'warn');
      if (!this.validateAll()) return;

      const r = await Setlo.run(this, () => api.post('items.php', this.payload()));
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
