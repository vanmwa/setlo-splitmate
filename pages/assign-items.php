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

  <div class="app-hero px-5 pb-5 pt-8" :class="{ 'fun-hero': bill && bill.fun_mode }">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[19px] font-extrabold leading-tight tracking-tight">Assign Items</h1>
        <p class="truncate text-[12px] font-medium text-brand-50/90">{{ splitMode === 'game' ? 'The game decided who pays' : splitMode === 'percent' ? 'Set each member’s share of the bill' : 'Pick a member, then tap what they shared' }}</p>
      </div>
      <span v-if="saving" class="text-[11px] font-semibold text-brand-50/80">Saving…</span>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/steps.php'; ?>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 pb-4 pt-4">
    <!-- Fun Mode: a game decided the split (pages/game.php) -->
    <div v-if="splitMode === 'game'" class="tile mx-5 !border-fuchsia-200 !bg-fuchsia-50/60 p-4">
      <p class="text-[11px] font-extrabold tracking-[.08em] text-fuchsia-700">🎉 FUN MODE</p>
      <p class="mt-1 text-[15px] font-extrabold text-ink">{{ gameHeadline }}</p>
      <p class="mt-0.5 text-[12px] text-slate-500">Of the whole bill: {{ peso(billTotal / 100) }}, with tax, service charge and discount.</p>
      <div class="mt-3 space-y-1.5">
        <div v-for="m in members" :key="m.id" class="flex items-center gap-2.5">
          <avatar :user="m" :size="26"></avatar>
          <span class="min-w-0 flex-1 truncate text-[13px] font-semibold">{{ m.id === me ? 'You' : m.name }}</span>
          <span class="text-[13px] font-bold" :class="shares[m.id] < 0 ? 'text-emerald-600' : shares[m.id] === 0 ? 'text-slate-400' : 'text-ink'">{{ shares[m.id] < 0 ? 'gets ' + peso(-shares[m.id] / 100) : peso((shares[m.id] || 0) / 100) }}</span>
        </div>
      </div>
      <div class="mt-4 grid grid-cols-2 gap-2">
        <a :href="'game?bill=' + billId" class="btn btn-outline !border-fuchsia-400 !text-fuchsia-700 hover:!bg-fuchsia-50">Play again</a>
        <button @click="setMode('items')" class="btn btn-ghost" :disabled="busy">Split normally</button>
      </div>
    </div>

    <div v-else class="mx-5 mb-3 grid grid-cols-2 gap-1 rounded-2xl border border-slate-200 bg-white p-1 text-[12.5px] font-bold" role="radiogroup" aria-label="How to split">
      <button @click="setMode('items')" role="radio" :aria-checked="splitMode === 'items'" :disabled="busy" class="rounded-xl py-2" :class="splitMode === 'items' ? 'bg-brand-600 text-white' : 'text-slate-500'">By items</button>
      <button @click="setMode('percent')" role="radio" :aria-checked="splitMode === 'percent'" :disabled="busy" class="rounded-xl py-2" :class="splitMode === 'percent' ? 'bg-brand-600 text-white' : 'text-slate-500'">By percentage</button>
    </div>

    <!-- Percentage split: each member pays a set share of the whole bill -->
    <div v-if="splitMode === 'percent'" class="tile mx-5 p-4">
      <div class="flex items-center justify-between gap-3">
        <p class="text-[14px] font-extrabold text-ink">Who pays what share</p>
        <button @click="evenPercents" class="text-[12px] font-bold text-brand-700">Split evenly</button>
      </div>
      <p class="mt-0.5 text-[12px] text-slate-400">Of the whole bill: {{ peso(billTotal / 100) }}, with tax, service charge and discount.</p>
      <div class="mt-3 space-y-2">
        <label v-for="m in members" :key="m.id" class="flex items-center gap-2.5">
          <avatar :user="m" :size="28"></avatar>
          <span class="min-w-0 flex-1 truncate text-[13px] font-semibold">{{ m.id === me ? 'You' : m.name }}</span>
          <input :value="pctDraft[m.id]" @input="setPct(m, $event)" :data-field="'pct' + m.id" inputmode="decimal" placeholder="0" class="row-in !h-9 w-[72px] text-right" :aria-label="'Percent for ' + m.name" />
          <span class="text-[13px] text-slate-400">%</span>
          <span class="w-[76px] text-right text-[13px] font-bold text-ink">{{ peso((shares[m.id] || 0) / 100) }}</span>
        </label>
      </div>
      <p class="mt-3 text-[12px] font-semibold" :class="pctOk ? 'text-brand-600' : 'text-rose-500'">{{ pctMessage }}</p>
    </div>

    <template v-else-if="splitMode === 'items'">
    <div v-if="bill.fun_mode" class="mx-5 mb-3 flex items-center justify-between rounded-2xl bg-fuchsia-50 px-3.5 py-2.5 text-[12.5px]">
      <span class="font-semibold text-fuchsia-800">🎉 Fun Mode group</span>
      <a :href="'game?bill=' + billId" class="font-bold text-fuchsia-700 underline">Let a game decide</a>
    </div>
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
      <template v-for="g in itemGroups" :key="g.key">
      <p v-if="g.label" class="section-label !mb-0 pt-1">{{ g.label }}</p>
      <button v-for="it in g.items" :key="it.id" @click="toggle(it)" class="tile flex w-full items-center gap-3 p-4 text-left" :class="{ '!border-brand-200': it.who.includes(active), '!border-rose-200 !bg-rose-50/60': !it.who.length }">
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
      </template>
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
    </template>
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
      <label v-if="plan.length" class="mt-4 flex items-start gap-2.5 rounded-2xl bg-slate-50 px-3.5 py-3 text-[12.5px] leading-snug text-slate-600">
        <input type="checkbox" v-model="interestOn" class="mt-0.5 h-4 w-4 shrink-0 accent-brand-600" />
        <span><b class="text-ink">Allow installments with interest</b><br />Anyone can pay in parts; each partial payment adds this % of what's still left.</span>
      </label>
      <div v-if="plan.length && interestOn" class="mt-2.5">
        <p class="mb-1.5 px-1 text-[12px] font-semibold text-slate-500">Interest after each partial payment</p>
        <rate-choice :rates="rates" v-model="interestRate"></rate-choice>
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
    bill: null, members: [], items: [], receipts: [], plan: [], paidBy: [], active: <?= (int) $user['id'] ?>, saveTimer: null,
    discountLabel: { none: '', senior: ' · Senior', pwd: ' · PWD' },
    // Percentage split: typed percents per member (strings), saved after a short pause.
    splitMode: 'items', pctDraft: {}, pctTimer: null,
    // Installment interest: one of the fixed rates (INSTALLMENT_RATES), the lowest picked by default
    interestOn: false, rates: <?= json_encode(INSTALLMENT_RATES) ?>, interestRate: <?= (int) INSTALLMENT_RATES[0]['rate'] ?>,
  }),
  computed: {
    /** Items under their receipt's title when the bill has several receipts (see Setlo.groupByReceipt). */
    itemGroups() { return Setlo.groupByReceipt(this.items, this.receipts); },
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
    /** Whole bill in cents: items + tax (unless already in the prices) + service charge − discount. */
    billTotal() {
      return this.items.reduce((s, it) => s + cents(it.line_total), 0) + cents(this.extras) - cents(this.discount);
    },
    pctTotal() {
      return Math.round(this.members.reduce((s, m) => s + (parseFloat(this.pctDraft[m.id]) || 0), 0) * 100) / 100;
    },
    pctOk() { return Math.round(this.pctTotal * 100) === 10000; },
    pctMessage() {
      if (this.pctOk) return 'Adds up to 100% ✓';
      const gap = Math.round(Math.abs(100 - this.pctTotal) * 100) / 100;
      return `Adds up to ${this.pctTotal}% — make it 100% (${this.pctTotal < 100 ? gap + '% left' : gap + '% too much'})`;
    },
    interestError() {
      return !this.interestOn || this.rates.some((r) => r.rate === this.interestRate) ? '' : 'Choose one of the interest rates.';
    },
    gameHeadline() {
      const g = this.bill.game;
      if (this.members.some((m) => m.game_share !== null && m.game_share !== undefined)) return (g && g.title ? g.title + ': ' : '') + 'the cards set everyone’s share';
      const payers = this.members.filter((m) => m.game_weight !== 0);
      const who = payers.length === 1 ? (payers[0].id === this.me ? 'You pay' : payers[0].first + ' pays') + ' the whole bill'
        : payers.map((m) => (m.id === this.me ? 'You' : m.first)).join(', ') + ' split the bill';
      return (g && g.title ? g.title + ': ' : '') + who;
    },
    /** Mirrors the server's compute_shares(), compute_percent_shares() and compute_game_shares(): cents per member, plus each member's discount. */
    calc() {
      const ids = this.members.map((m) => m.id);
      if (this.splitMode === 'game') {
        const fixed = this.members.some((m) => m.game_share !== null && m.game_share !== undefined);
        const weights = Object.fromEntries(this.members.map((m) => [m.id, m.game_weight === null || m.game_weight === undefined ? 1 : m.game_weight]));
        const shares = fixed ? Object.fromEntries(this.members.map((m) => [m.id, m.game_share || 0])) : splitProportional(this.billTotal, weights);
        return { shares, discountBy: Object.fromEntries(ids.map((id) => [id, 0])) };
      }
      if (this.splitMode === 'percent') {
        const weights = Object.fromEntries(this.members.map((m) => [m.id, Math.round((parseFloat(this.pctDraft[m.id]) || 0) * 100)]));
        const shares = this.pctOk ? splitProportional(this.billTotal, weights) : Object.fromEntries(ids.map((id) => [id, 0]));
        return { shares, discountBy: Object.fromEntries(ids.map((id) => [id, 0])) };
      }
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
      if (fresh && r.bill.game_pending) { location.replace('game?bill=' + this.billId); return; }
      if (fresh && shown !== null && shown !== state()) return; // already tapping: keep their work
      this.bill = r.bill;
      this.members = r.members;
      this.splitMode = r.bill.split_mode || 'items';
      this.fillPercents();
      this.paidBy = Object.keys(r.payments.paid).map(Number);
      this.items = r.items;
      this.receipts = r.receipts || [];
      if (!fresh) shown = state();
    });
    window.addEventListener('beforeunload', this.flush);
  },
  methods: {
    fillPercents() {
      this.pctDraft = Object.fromEntries(this.members.map((m) => [m.id, m.percent === null || m.percent === undefined ? '' : String(m.percent)]));
    },
    async setMode(mode) {
      if (mode === this.splitMode) return;
      if (this.saveTimer) await this.persist();
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'set_split_mode', bill_id: this.billId, mode }));
      if (!r) return;
      this.members = r.members;
      this.splitMode = mode;
      this.fillPercents();
    },
    setPct(m, e) {
      let v = Setlo.filterMoney(e.target.value);
      if (parseFloat(v) > 100) v = '100';
      e.target.value = v;
      this.pctDraft[m.id] = v;
      clearTimeout(this.pctTimer);
      this.pctTimer = setTimeout(this.savePercents, 500);
    },
    evenPercents() {
      const ids = this.members.map((m) => m.id);
      const parts = splitEvenly(10000, ids); // hundredths of a percent, leftovers to the first members
      this.pctDraft = Object.fromEntries(ids.map((id) => [id, String(parts[id] / 100)]));
      this.savePercents();
    },
    async savePercents() {
      clearTimeout(this.pctTimer);
      this.pctTimer = null;
      const percents = Object.fromEntries(this.members.map((m) => [m.id, this.pctDraft[m.id] === '' ? null : parseFloat(this.pctDraft[m.id])]));
      this.saving = true;
      try {
        await api.post('bills.php', { action: 'set_percents', bill_id: this.billId, percents });
      } catch (e) {
        Setlo.toast(e.message);
        throw e;
      } finally {
        this.saving = false;
      }
    },
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
    flush() {
      if (this.saveTimer) this.persist();
      if (this.pctTimer) this.savePercents();
    },
    async splitEvenly() {
      if (this.saveTimer) await this.persist();
      const r = await Setlo.run(this, () => api.post('assignments.php', { bill_id: this.billId, action: 'split_evenly' }));
      if (r) this.items = r.items;
    },
    async finish() {
      if (this.splitMode === 'percent') {
        if (!this.pctOk) { Setlo.toast('The percentages must add up to 100%.'); return; }
      } else if (this.splitMode === 'items' && this.unassignedCount) {
        Setlo.toast('Assign ' + this.unassigned.map((it) => it.name).join(', ') + ' first');
        return;
      }
      // The plan comes from the server so the preview is exactly what will be created.
      const r = await Setlo.run(this, async () => {
        await this.persist();
        if (this.splitMode === 'percent') await this.savePercents();
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
      if (this.plan.length && this.interestError) { Setlo.toast(this.interestError); return; }
      const interest_rate = this.plan.length && this.interestOn ? this.interestRate : null;
      const r = await Setlo.run(this, async () => {
        await this.persist();
        return api.post('bills.php', { action: 'start_settling', bill_id: this.billId, interest_rate });
      });
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
