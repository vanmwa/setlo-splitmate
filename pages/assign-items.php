<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Assign Items';
$nav = 'scan';
$billId = (int) ($_GET['bill'] ?? 0);
if (!$billId) {
    redirect('pages/my-bills');
}
$back = 'review-items.php?bill=' . $billId;
$step = 3;
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[19px] font-extrabold leading-tight tracking-tight">Assign Items</h1>
        <p class="truncate text-[12px] font-medium text-brand-50/90">Pick a member, then tap what they shared</p>
      </div>
      <span v-if="saving" class="text-[11px] font-semibold text-brand-50/80">Saving…</span>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/steps.php'; ?>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 pb-4 pt-4">
    <div class="no-scrollbar flex gap-2 overflow-x-auto px-5 pb-1">
      <button v-for="m in members" :key="m.id" @click="active = m.id" class="mchip" :class="{ on: m.id === active }">
        <avatar :user="m" :size="28"></avatar>{{ m.id === me ? 'You' : m.first }}
      </button>
    </div>

    <div class="mt-3 flex items-center justify-between px-5 text-[12px]">
      <a :href="'bill-detail.php?bill=' + billId" class="text-slate-500">Paid by <b class="text-ink">{{ paidByLabel }}</b></a>
      <button v-if="unassignedCount" @click="splitEvenly" class="font-bold text-brand-700">Split unassigned evenly</button>
    </div>

    <div class="mt-3 space-y-3 px-5">
      <button v-for="it in items" :key="it.id" @click="toggle(it)" class="tile flex w-full items-center gap-3 p-4 text-left" :class="{ '!border-brand-200': it.who.includes(active), '!border-rose-200 !bg-rose-50/60': !it.who.length }">
        <div class="min-w-0 flex-1">
          <p class="text-[14px] font-bold text-ink">{{ it.name }}{{ it.qty > 1 ? ' ×' + it.qty : '' }}</p>
          <p v-if="it.details" class="mt-0.5 text-[11.5px] text-slate-500">Includes {{ it.details }}</p>
          <p class="mt-0.5 text-[12px] text-slate-400">
            {{ peso(it.line_total) }} ·
            <span v-if="!it.who.length" class="font-semibold text-rose-500">not assigned</span>
            <span v-else-if="it.who.length === 1">{{ nameOf(it.who[0]) }} only</span>
            <span v-else>shared by {{ it.who.length }}</span>
          </p>
        </div>
        <div class="flex -space-x-1.5">
          <avatar v-for="uid in it.who" :key="uid" :user="memberMap[uid]" :size="22" ring></avatar>
        </div>
        <span class="cbox" :class="{ on: it.who.includes(active) }">
          <svg v-if="it.who.includes(active)" class="h-4 w-4 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </span>
      </button>
    </div>

    <div v-if="extras > 0" class="mx-5 mt-3 flex items-center justify-between rounded-[18px] border border-brand-100 bg-brand-50/70 px-4 py-3">
      <div>
        <p class="text-[13px] font-bold text-brand-900">{{ bill.tax_included ? 'Service charge' : 'Tax + service charge' }}</p>
        <p class="text-[12px] text-brand-700/80">Split equally · {{ peso(extras / members.length) }} each</p>
      </div>
      <span class="text-[14px] font-extrabold text-brand-900">{{ peso(extras) }}</span>
    </div>

    <div v-if="discount > 0" class="mx-5 mt-3 rounded-[18px] border border-emerald-100 bg-emerald-50/70 px-4 py-3">
      <div class="flex items-center justify-between">
        <div>
          <p class="text-[13px] font-bold text-emerald-900">Receipt discount</p>
          <p class="text-[12px] text-emerald-700/80">{{ flaggedCount ? 'Goes to Senior/PWD members first' : 'Shared by everyone, in proportion to what they ordered' }}</p>
        </div>
        <span class="text-[14px] font-extrabold text-emerald-900">−{{ peso(discount) }}</span>
      </div>
      <p class="mt-3 text-[11px] font-extrabold tracking-[.06em] text-emerald-900/60">WHO HAS A SENIOR / PWD ID? <span class="font-medium normal-case tracking-normal">tap to change</span></p>
      <div class="mt-1.5 flex flex-wrap gap-1.5">
        <button v-for="m in members" :key="m.id" @click="cycleDiscount(m)" :disabled="busy"
          class="pill !px-2.5 !py-1 !text-[12px]" :class="m.discount_type !== 'none' ? 'bg-emerald-600 text-white' : 'border border-slate-200 bg-white text-slate-600'">
          {{ m.id === me ? 'You' : m.first }}{{ discountLabel[m.discount_type] }}
        </button>
      </div>
    </div>
    <p v-else class="mx-5 mt-3 text-[11.5px] text-slate-400">Senior/PWD or promo discount on the receipt? Add it on the Review step.</p>

    <p v-if="unassignedCount" class="mx-5 mt-3 text-[12px] font-semibold text-rose-500">
      {{ unassignedCount }} item{{ unassignedCount > 1 ? 's' : '' }} still unassigned ({{ peso(unassignedTotal) }}) — assign {{ unassignedCount > 1 ? 'them' : 'it' }} before sending.
    </p>

    <div class="mx-5 mt-4 tile divide-y divide-slate-100">
      <div v-for="m in members" :key="m.id" class="flex items-center gap-3 px-4 py-2.5">
        <avatar :user="m" :size="26"></avatar>
        <span class="flex-1 text-[13px] font-semibold text-slate-700">{{ m.id === me ? 'You' : m.name }}</span>
        <span v-if="calc.discountBy[m.id]" class="text-[11px] font-semibold text-emerald-600">−{{ peso(calc.discountBy[m.id] / 100) }}</span>
        <span class="text-[13px] font-bold text-ink">{{ peso(shares[m.id] / 100) }}</span>
      </div>
    </div>
  </div>

  <div class="dock">
    <div class="dock-inner">
      <div>
        <p class="text-[11px] font-medium text-slate-400">{{ active === me ? 'Your' : nameOf(active) + "'s" }} share</p>
        <p class="text-[21px] font-extrabold leading-tight tracking-tight text-white">{{ peso((shares[active] || 0) / 100) }}</p>
      </div>
      <button @click="finish" class="dock-btn" :disabled="busy">{{ busy ? 'Sending…' : 'Finish & send' }}</button>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <div v-if="confirming" class="sheet-backdrop" @click.self="confirming = false">
    <div class="sheet">
      <div class="sheet-grip"></div>
      <h2 class="text-[18px] font-extrabold text-ink">Send the settlement plan?</h2>
      <p class="mt-1 text-[13px] text-slate-500">These payments settle everyone up. Item assignments lock once settling starts.</p>
      <div class="mt-4 space-y-2">
        <div v-for="(t, i) in plan" :key="i" class="flex items-center gap-3 rounded-2xl bg-slate-50 px-3.5 py-2.5">
          <avatar :user="memberMap[t.from]" :size="28"></avatar>
          <span class="flex-1 text-[13px] font-semibold">{{ nameOf(t.from) }} → {{ nameOf(t.to) }}</span>
          <span class="text-[13px] font-extrabold">{{ peso(t.amount) }}</span>
        </div>
        <p v-if="!plan.length" class="text-[13px] text-slate-400">Nobody owes anyone — the bill will close right away.</p>
      </div>
      <div class="mt-5 grid grid-cols-2 gap-3">
        <button @click="confirming = false" class="btn-pill btn-pill-soft">Back</button>
        <button @click="send" class="btn-pill btn-pill-primary" :disabled="busy">Start settling</button>
      </div>
    </div>
  </div>
</div>

<script>
const cents = (n) => Math.round(Number(n) * 100);
function splitEvenly(total, ids) {
  const sorted = [...ids].sort((a, b) => a - b);
  const base = Math.floor(total / sorted.length);
  const rem = total - base * sorted.length;
  return Object.fromEntries(sorted.map((id, i) => [id, base + (i < rem ? 1 : 0)]));
}
/** Mirrors split_proportional() in includes/domain.php. weights: { id: cents } */
function splitProportional(total, weights) {
  const ids = Object.keys(weights).map(Number).sort((a, b) => a - b);
  const sum = ids.reduce((s, id) => s + weights[id], 0);
  if (total <= 0 || sum <= 0) return total > 0 ? splitEvenly(total, ids) : Object.fromEntries(ids.map((id) => [id, 0]));
  const out = {};
  let given = 0;
  for (const id of ids) { out[id] = Math.floor((total * weights[id]) / sum); given += out[id]; }
  for (const id of ids) { if (given >= total) break; if (weights[id] > 0) { out[id]++; given++; } }
  return out;
}
Setlo.mount({
  data: () => ({
    billId: <?= $billId ?>, me: <?= (int) $user['id'] ?>,
    loading: true, busy: false, saving: false, confirming: false,
    bill: null, members: [], items: [], plan: [], paidBy: [], active: <?= (int) $user['id'] ?>, saveTimer: null,
    discountLabel: { none: '', senior: ' · Senior', pwd: ' · PWD' },
  }),
  computed: {
    memberMap() { return Object.fromEntries(this.members.map((m) => [m.id, m])); },
    payer() { return this.bill && this.memberMap[this.bill.payer_id]; },
    extras() { return this.bill ? Math.round(((this.bill.tax_included ? 0 : this.bill.tax) + this.bill.service_charge) * 100) / 100 : 0; },
    unassigned() { return this.items.filter((it) => !it.who.length); },
    unassignedCount() { return this.unassigned.length; },
    unassignedTotal() { return this.unassigned.reduce((s, it) => s + it.line_total, 0); },
    paidByLabel() {
      const names = this.paidBy.map((id) => (id === this.me ? 'you' : (this.memberMap[id] || {}).first)).filter(Boolean);
      return names.length > 1 ? names.slice(0, -1).join(', ') + ' & ' + names[names.length - 1] : names[0] || '';
    },
    discount() { return this.bill ? this.bill.discount : 0; },
    flaggedCount() { return this.members.filter((m) => m.discount_type !== 'none').length; },
    /** Mirrors the server's compute_shares(): cents per member, plus each member's discount. */
    calc() {
      const ids = this.members.map((m) => m.id);
      const items = Object.fromEntries(ids.map((id) => [id, 0]));
      for (const it of this.items) {
        if (!it.who.length) continue;
        for (const [id, c] of Object.entries(splitEvenly(cents(it.line_total), it.who))) items[id] += c;
      }
      const out = { ...items };
      if (ids.length) for (const [id, c] of Object.entries(splitEvenly(cents(this.extras), ids))) out[id] += c;

      const discount = cents(this.discount);
      const flaggedWeights = Object.fromEntries(this.members.filter((m) => m.discount_type !== 'none').map((m) => [m.id, items[m.id]]));
      const toFlagged = Math.min(discount, Object.values(flaggedWeights).reduce((a, b) => a + b, 0));
      const discountBy = Object.fromEntries(ids.map((id) => [id, 0]));
      for (const [id, c] of Object.entries(splitProportional(toFlagged, flaggedWeights))) discountBy[id] += c;
      const remaining = Object.fromEntries(ids.map((id) => [id, items[id] - discountBy[id]]));
      for (const [id, c] of Object.entries(splitProportional(discount - toFlagged, remaining))) discountBy[id] += c;
      for (const id of ids) out[id] -= discountBy[id];
      return { shares: out, discountBy };
    },
    shares() { return this.calc.shares; },
  },
  async mounted() {
    let shown = null; // assignments as filled from this tab's saved reply
    const state = () => JSON.stringify([this.items, this.members, this.paidBy]);
    await Setlo.load(this, 'bills.php', { id: this.billId }, (r, fresh) => {
      if (!r.me.is_creator || r.bill.locked) { location.replace('bill-items?bill=' + this.billId); return; }
      if (!r.items.length) { location.replace('review-items?bill=' + this.billId); return; }
      if (fresh && shown !== null && shown !== state()) return; // already tapping: keep their work
      this.bill = r.bill;
      this.members = r.members;
      this.paidBy = Object.keys(r.payments.paid).map(Number);
      this.items = r.items;
      if (!fresh) shown = state();
    });
    window.addEventListener('beforeunload', this.flush);
  },
  methods: {
    async cycleDiscount(m) {
      const next = { none: 'senior', senior: 'pwd', pwd: 'none' }[m.discount_type];
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'set_discount_type', bill_id: this.billId, user_id: m.id, type: next }));
      if (r) this.members = r.members;
    },
    nameOf(id) { return id === this.me ? 'You' : (this.memberMap[id] || {}).first; },
    toggle(it) {
      it.who = it.who.includes(this.active) ? it.who.filter((x) => x !== this.active) : [...it.who, this.active];
      this.queueSave();
    },
    queueSave() {
      clearTimeout(this.saveTimer);
      this.saveTimer = setTimeout(this.persist, 500);
    },
    async persist() {
      clearTimeout(this.saveTimer);
      this.saveTimer = null;
      const assignments = Object.fromEntries(this.items.map((it) => [it.id, it.who]));
      this.saving = true;
      try {
        await api.post('assignments.php', { bill_id: this.billId, assignments });
      } catch (e) {
        Setlo.toast(e.message);
        throw e;
      } finally {
        this.saving = false;
      }
    },
    flush() { if (this.saveTimer) this.persist(); },
    async splitEvenly() {
      if (this.saveTimer) await this.persist();
      const r = await Setlo.run(this, () => api.post('assignments.php', { bill_id: this.billId, action: 'split_evenly' }));
      if (r) this.items = r.items;
    },
    async finish() {
      if (this.unassignedCount) {
        Setlo.toast('Assign ' + this.unassigned.map((it) => it.name).join(', ') + ' first');
        return;
      }
      // The plan comes from the server so the preview is exactly what will be created.
      const r = await Setlo.run(this, async () => {
        await this.persist();
        return api.get('bills.php', { id: this.billId });
      });
      if (!r) return;
      if (r.payments.mismatch) {
        Setlo.toast('Payments don’t add up to the bill total — fix “Who paid” on Bill Detail.');
        return;
      }
      this.plan = r.plan;
      this.confirming = true;
    },
    async send() {
      const r = await Setlo.run(this, async () => {
        await this.persist();
        return api.post('bills.php', { action: 'start_settling', bill_id: this.billId });
      });
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
