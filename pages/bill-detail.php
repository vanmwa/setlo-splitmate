<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Bill Detail';
$nav = 'bills';
$billId = (int) ($_GET['bill'] ?? 0);
if (!$billId) {
    redirect('pages/my-bills');
}
$back = 'my-bills.php';
$headExtra = ['assets/js/summary.js'];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8" :class="{ 'fun-hero': bill && bill.fun_mode }">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="flex items-center gap-2 text-[19px] font-extrabold leading-tight tracking-tight"><span class="truncate">{{ bill ? bill.name : '' }}</span><span v-if="bill && bill.fun_mode" class="fun-chip">🎉 Fun</span></h1>
        <p v-if="bill" class="text-[12px] font-medium text-brand-50/90">Created by {{ d.me.is_creator ? 'You' : creator.first }} · {{ fmtDate(bill.created_at) }}</p>
      </div>
      <status-pill v-if="bill" :status="bill.status"></status-pill>
    </div>
    <div class="seg mt-5">
      <button v-for="t in tabs" :key="t.key" :class="{ on: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-4 px-5 pb-6 pt-4 lg:grid lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start lg:gap-5 lg:space-y-0">
    <div class="space-y-4">

    <!-- Members & balances -->
    <template v-if="tab === 'members'">
      <div class="tile divide-y divide-slate-100">
        <div v-for="m in d.members" :key="m.id" class="flex items-center gap-3 p-3.5">
          <button @click="showPerson(m.id)" class="shrink-0 rounded-full" :aria-label="'About ' + m.name"><avatar :user="m" :size="36"></avatar></button>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-ink"><button @click="showPerson(m.id)" class="text-left hover:underline">{{ m.name }}</button> <status-badge :badge="m.badge"></status-badge> <span v-if="m.is_guest" class="pill pill-draft">Guest</span>
              <span v-if="m.id === me || m.id === bill.creator_id" class="text-[10px] font-medium text-brand-600">({{ [m.id === me ? 'You' : '', m.id === bill.creator_id ? 'Creator' : ''].filter(Boolean).join(' · ') }})</span>
            </p>
            <p class="text-xs text-slate-400">
              <template v-if="d.payments.paid[m.id]">Paid {{ peso(d.payments.paid[m.id]) }} · </template>share {{ peso(d.shares[m.id] || 0) }}<template v-if="m.is_guest"> · guest, no account</template>
            </p>
          </div>
          <p v-if="(d.balances[m.id] || 0) > 0" class="text-right text-sm font-bold text-emerald-600">+{{ peso(d.balances[m.id]) }}<span class="block text-[10px] font-medium text-slate-400">gets back</span></p>
          <p v-else-if="(d.balances[m.id] || 0) < 0" class="text-right text-sm font-bold text-red-600">−{{ peso(-d.balances[m.id]) }}<span class="block text-[10px] font-medium text-slate-400">owes</span></p>
          <p v-else class="text-sm font-bold text-slate-400">₱0.00</p>
          <button v-if="canEdit && m.id !== bill.creator_id && !d.payments.paid[m.id]" @click="removeMember(m)" class="ml-1 text-slate-300 hover:text-rose-500" :aria-label="'Remove ' + m.name">✕</button>
        </div>
      </div>

      <div v-if="canEdit" class="space-y-3">
        <!-- Invite by link / QR -->
        <div class="tile p-4">
          <div class="flex items-center justify-between gap-3">
            <div>
              <p class="text-[14px] font-extrabold text-ink">Invite link</p>
              <p class="text-[12px] text-slate-400">{{ bill.invite_code ? 'Anyone with this link can join until settling starts.' : 'Let friends join by link or QR — no searching needed.' }}</p>
            </div>
            <button v-if="!bill.invite_code" @click="invite('enable')" class="btn btn-primary btn-sm shrink-0" :disabled="busy">Create link</button>
          </div>
          <template v-if="bill.invite_code">
            <div class="mt-3 flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2">
              <span class="min-w-0 flex-1 truncate text-[12px] font-medium text-slate-600">{{ inviteUrl }}</span>
              <button @click="copyInvite" class="shrink-0 text-[12px] font-bold text-brand-700">{{ copied ? 'Copied ✓' : 'Copy' }}</button>
            </div>
            <div class="mt-3 flex items-center gap-4">
              <div class="h-28 w-28 shrink-0 rounded-xl border border-slate-200 bg-white p-1.5" v-html="inviteQr" role="img" aria-label="Invite QR code"></div>
              <div class="flex-1 space-y-1.5 text-[12px]">
                <p class="text-slate-500">Show this QR at the table — friends scan it with their phone camera.</p>
                <button v-if="canShare" @click="shareInvite" class="block font-bold text-brand-700">Share…</button>
                <button @click="invite('rotate')" class="block font-bold text-slate-500" :disabled="busy">New link</button>
                <button @click="invite('disable')" class="block font-bold text-rose-600" :disabled="busy">Turn off</button>
              </div>
            </div>
          </template>
        </div>
        <div class="input-wrap">
          <svg class="input-lead h-[18px] w-[18px] text-slate-400" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
          <input v-model="search" @input="lookup" class="input-soft has-lead !bg-white" aria-label="Invite member" placeholder="Invite another member by name or email" />
        </div>
        <div v-if="results.length" class="tile divide-y divide-slate-100 overflow-hidden">
          <button v-for="u in results" :key="u.id" @click="addMember(u)" class="flex w-full items-center gap-3 px-3.5 py-2.5 text-left hover:bg-slate-50">
            <avatar :user="u" :size="30"></avatar>
            <span class="flex-1 text-[13px] font-bold">{{ u.name }}</span>
            <span class="text-[12px] font-bold text-brand-700">Add</span>
          </button>
        </div>
        <div class="flex gap-2">
          <input data-field="guest" v-model="guestName" @input="touch('guest')" @keydown.enter.prevent="addGuest" :class="{ 'is-invalid': guestName && err('guest') }" class="input-soft !bg-white flex-1" maxlength="100" placeholder="Or add a guest (no account)" aria-label="Guest name" />
          <button @click="addGuest" class="btn btn-outline shrink-0" :disabled="busy || !guestName">Add guest</button>
        </div>
        <p v-if="guestName && err('guest')" class="field-error !mt-1">{{ err('guest') }}</p>
        <div class="tile p-4">
          <div class="flex items-center justify-between gap-3">
            <p class="text-[14px] font-extrabold text-ink">Who paid the restaurant?</p>
            <div class="seg seg-light w-44 !p-0.5">
              <button :class="{ on: !multiPay }" @click="useSinglePayer">One person</button>
              <button :class="{ on: multiPay }" @click="startMultiPay">Several</button>
            </div>
          </div>
          <div v-if="!multiPay" class="mt-3">
            <payer-picker :people="realMembers" :model-value="[bill.payer_id]" :me="me" :multiple="false" @update:model-value="setPayer($event[0])"></payer-picker>
            <p class="mt-1.5 text-[12px] text-slate-400">{{ payer.id === me ? 'You' : payer.name }} paid {{ peso(d.total) }}. Everyone else settles with this person.</p>
          </div>
          <template v-else>
            <div class="mt-3 space-y-2">
              <label v-for="m in payRows" :key="m.id" class="flex items-center gap-3">
                <avatar :user="m" :size="28"></avatar>
                <span class="flex-1 text-[13px] font-semibold">{{ m.id === me ? 'You' : m.name }}</span>
                <span class="text-slate-400">₱</span>
                <input :data-field="'pay' + m.id" :value="payDraft[m.id]" @input="payDraft[m.id] = filterMoney($event.target.value); $event.target.value = payDraft[m.id]; touch('pay' + m.id)" inputmode="decimal" placeholder="0.00" class="row-in !h-9 w-28 text-right" :class="{ 'is-invalid': err('pay' + m.id) }" :aria-label="'Amount paid by ' + m.name" />
              </label>
            </div>
            <p class="mt-2 text-[12px] font-semibold" :class="payDraftOk ? 'text-brand-600' : 'text-rose-500'">
              {{ peso(payDraftTotal) }} of {{ peso(d.total) }} entered{{ payDraftOk ? ' ✓' : ' — ' + peso(Math.abs(d.total - payDraftTotal)) + (payDraftTotal < d.total ? ' left' : ' too much') }}
            </p>
            <button type="button" @click="splitEvenly" class="mt-2 text-[12px] font-bold text-brand-700 hover:underline">Split evenly between {{ evenIds.length }} {{ evenIds.length === 1 ? 'person' : 'people' }}</button>
            <button @click="savePayments" class="btn btn-primary btn-sm mt-3 w-full" :disabled="busy">Save payments</button>
          </template>
        </div>
      </div>
    </template>

    <!-- Settlement plan -->
    <template v-if="tab === 'plan'">
      <div v-if="!d.settlements.length" class="tile p-4">
        <p class="text-sm font-bold text-ink">Proposed transfers</p>
        <p class="mt-0.5 text-[12px] text-slate-400">Final once the creator confirms the split. Uses the fewest payments possible.</p>
        <p v-if="d.unassigned_count" class="mt-3 text-[12px] font-semibold text-rose-500">Assign every item to see the plan.</p>
        <p v-else-if="d.payments.mismatch" class="mt-3 text-[12px] font-semibold text-rose-500">Payments entered ({{ peso(d.payments.total) }}) don't match the bill total ({{ peso(d.total) }}).</p>
        <div v-else class="mt-3 space-y-2">
          <div v-for="(t, i) in d.plan" :key="i" class="flex items-center gap-2.5 rounded-xl bg-slate-50 px-3 py-2">
            <avatar :user="memberMap[t.from]" :size="28"></avatar>
            <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            <avatar :user="memberMap[t.to]" :size="28"></avatar>
            <span class="flex-1 text-[13px] text-slate-600">{{ memberMap[t.from].first }} pays {{ memberMap[t.to].first }}</span>
            <span class="text-[13px] font-bold">{{ peso(t.amount) }}</span>
          </div>
          <p v-if="!d.plan.length" class="text-[12px] text-slate-400">Nobody owes anyone.</p>
        </div>
      </div>
      <p v-if="d.settlements.length && bill.interest_rate" class="rounded-xl bg-amber-50 px-3.5 py-2.5 text-[12px] text-amber-800">
        <b>Installments on:</b> each partial payment adds {{ bill.interest_rate }}% of what's left.
      </p>
      <div v-for="s in d.settlements" :key="s.id" class="tile p-3.5">
        <a :href="'settlement-audit.php?id=' + s.id" class="flex items-center justify-between">
          <div class="flex items-center gap-2.5">
            <avatar :user="s.from" :size="32"></avatar>
            <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            <avatar :user="s.to" :size="32"></avatar>
            <p class="ml-1 text-sm font-semibold">{{ peso(s.amount) }}</p>
          </div>
          <status-pill :status="s.status"></status-pill>
        </a>
        <div v-if="s.paid_amount > 0 && s.status !== 'settled'" class="mt-2.5">
          <div class="progress !h-1.5"><span :style="{ width: Math.min(100, (100 * s.paid_amount) / s.amount) + '%' }"></span></div>
          <p class="mt-1 text-[11px] text-slate-500">{{ peso(s.paid_amount) }} paid · {{ peso(s.remaining) }} left<span v-if="s.interest_added > 0"> · incl. {{ peso(s.interest_added) }} interest</span></p>
        </div>
        <div v-if="canCover(s)" class="mt-2 text-right">
          <a :href="'my-settlements?cover=' + s.id" class="text-[12px] font-bold text-brand-700">Pay for {{ s.from.first }} →</a>
        </div>
      </div>
      <a v-if="d.settlements.length && bill.status !== 'closed'" href="my-settlements" class="block pt-1 text-center text-xs font-semibold text-brand-600">Manage in My Settlements →</a>
    </template>

    <!-- Items -->
    <template v-if="tab === 'items'">
      <div class="tile divide-y divide-slate-100">
        <template v-for="g in itemGroups" :key="g.key">
        <p v-if="g.label" class="bg-slate-50 px-3.5 py-2 text-[11.5px] font-extrabold tracking-[.04em] text-slate-500">{{ g.label }}</p>
        <div v-for="it in g.items" :key="it.id" class="flex items-center justify-between gap-3 p-3.5">
          <div class="min-w-0">
            <p class="text-sm font-medium">{{ it.name }}{{ it.qty > 1 ? ' ×' + it.qty : '' }}</p>
            <p class="text-xs text-slate-400">{{ it.who.length ? it.who.map((id) => memberMap[id].first).join(', ') : bill.split_mode === 'percent' ? 'Split by percentage' : bill.split_mode === 'game' ? 'Decided by a game' : 'Unassigned' }}</p>
          </div>
          <p class="shrink-0 text-sm font-semibold">{{ peso(it.line_total) }}</p>
        </div>
        </template>
        <p v-if="!d.items.length" class="p-4 text-center text-[13px] text-slate-400">No items yet.</p>
      </div>
      <a :href="(bill.status === 'closed' ? 'bill-breakdown.php' : 'bill-items.php') + '?bill=' + billId" class="btn btn-outline w-full">Open full itemalized view</a>
    </template>

    </div>

    <!-- Side column on desktop: totals, share, creator actions -->
    <div class="space-y-4 lg:sticky lg:top-6">
    <div class="tile space-y-1 bg-slate-50 p-3.5 text-sm">
      <div v-if="bill.discount > 0" class="flex justify-between"><span class="text-slate-500">Receipt discount</span><span class="font-semibold text-emerald-600">−{{ peso(bill.discount) }}</span></div>
      <div class="flex justify-between"><span class="text-slate-500">Bill total</span><span class="font-semibold">{{ peso(d.total) }}</span></div>
      <div v-if="bill.receipt_total !== null" class="flex justify-between"><span class="text-slate-500">Receipt total</span><span class="font-semibold">{{ peso(bill.receipt_total) }}</span></div>
      <div v-if="bill.split_mode === 'percent'" class="flex justify-between"><span class="text-slate-500">Split</span>
        <span class="font-semibold" :class="bill.percent_total === 100 ? 'text-emerald-600' : 'text-rose-500'">By percentage{{ bill.percent_total === 100 ? ' ✓' : ' · ' + bill.percent_total + '% set' }}</span>
      </div>
      <div v-else-if="bill.split_mode === 'game'" class="flex justify-between"><span class="text-slate-500">Split</span>
        <span class="font-semibold text-fuchsia-600">{{ bill.game ? bill.game.title : '🎉 Fun Mode game' }}</span>
      </div>
      <div v-else class="flex justify-between"><span class="text-slate-500">Fully assigned</span>
        <span class="font-semibold" :class="d.unassigned_count ? 'text-rose-500' : 'text-emerald-600'">{{ d.unassigned_count ? peso(d.unassigned) + ' left' : 'Yes ✓' }}</span>
      </div>
    </div>

    <share-summary v-if="d.items.length" :d="d"></share-summary>

    <div v-if="d.me.is_creator && !bill.locked" class="grid grid-cols-2 gap-3">
      <a :href="'review-items.php?bill=' + billId" class="btn btn-outline">Edit items</a>
      <a :href="'assign-items.php?bill=' + billId" class="btn btn-primary">Assign items</a>
      <button @click="remove" class="btn btn-ghost col-span-2 !text-rose-600">Delete this bill</button>
    </div>
    <!-- Settling but nobody has paid yet: the creator can still undo a mistake -->
    <div v-else-if="bill.locked && d.me.is_creator && !bill.money_moved" class="tile p-3.5">
      <p class="text-[13px] font-extrabold text-ink">Made a mistake?</p>
      <p class="mt-0.5 text-[12px] text-slate-500">Nobody has paid yet, so you can take the settlement plan back and fix the bill, or delete it. Everyone on it is told.</p>
      <div class="mt-3 grid grid-cols-2 gap-2">
        <button @click="reopen" class="btn btn-outline btn-sm" :disabled="busy">Fix the bill</button>
        <button @click="remove" class="btn btn-danger btn-sm" :disabled="busy">Delete bill</button>
      </div>
    </div>
    <div v-else-if="bill.locked && d.me.is_creator" class="rounded-xl border border-slate-200 bg-slate-50 p-3.5">
      <p class="text-xs text-slate-500"><b class="text-slate-700">Editing locked.</b> Payments have been made on this bill, so it stays as their record. {{ bill.status === 'closed' ? 'Archive it to clear it from your lists.' : 'Once everything is settled you can archive it.' }}</p>
    </div>
    <button v-if="bill.status === 'closed'" @click="toggleArchive" class="btn btn-ghost w-full" :disabled="busy">{{ bill.archived ? 'Unarchive this bill' : 'Archive this bill' }}</button>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    billId: <?= $billId ?>, me: <?= (int) $user['id'] ?>,
    loading: true, busy: false, bill: null, d: { me: {}, members: [], items: [], settlements: [], shares: {}, balances: {}, plan: [], payments: { paid: {}, multi: false } },
    tab: 'members', tabs: [{ key: 'members', label: 'Members' }, { key: 'plan', label: 'Settlement' }, { key: 'items', label: 'Items' }],
    search: '', results: [], timer: null, guestName: '', multiPay: false, payDraft: {}, copied: false, canShare: !!navigator.share,
  }),
  computed: {
    /** Items under their receipt's title when the bill has several receipts (see Setlo.groupByReceipt). */
    itemGroups() { return Setlo.groupByReceipt(this.d.items, this.d.receipts); },
    memberMap() { return Object.fromEntries(this.d.members.map((m) => [m.id, m])); },
    creator() { return this.memberMap[this.bill.creator_id] || {}; },
    payer() { return this.memberMap[this.bill.payer_id] || {}; },
    inviteUrl() {
      return this.bill && this.bill.invite_code ? new URL('join.php?code=' + this.bill.invite_code, location.href).href : '';
    },
    inviteQr() {
      if (!this.inviteUrl || !window.qrcode) return '';
      const qr = qrcode(0, 'M');
      qr.addData(this.inviteUrl);
      qr.make();
      return qr.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    },
    realMembers() { return this.d.members.filter((m) => !m.is_guest); },
    /** Who paid, as named when the bill was created (or everyone, if it was never several). */
    evenIds() { return this.d.payments.multi ? this.d.payments.payer_ids : this.realMembers.map((m) => m.id); },
    /** The people who paid first, then everyone else, for the amount rows. */
    payRows() {
      const first = this.d.payments.multi ? this.d.payments.payer_ids : [];
      return [...this.realMembers].sort((a, b) => (first.includes(b.id) - first.includes(a.id)));
    },
    payDraftTotal() { return Math.round(Object.values(this.payDraft).reduce((s, v) => s + (parseFloat(v) || 0), 0) * 100) / 100; },
    payDraftOk() { return Math.abs(this.payDraftTotal - this.d.total) < 0.005; },
    canEdit() { return this.d.me.is_creator && !this.bill.locked; },
    /** Another member's open debt that I could pay for them (guests' debts are the creator's own to pay). */
    canCover() {
      return (s) => s.from.id !== this.me && s.to.id !== this.me && ['pending', 'awaiting'].includes(s.status) && s.remaining > 0
        && !(s.from.is_guest && this.d.me.is_creator);
    },
    owedToPayer() {
      return this.d.members.filter((m) => m.id !== this.bill.payer_id).reduce((s, m) => s + (this.d.shares[m.id] || 0), 0);
    },
  },
  async mounted() {
    await Setlo.load(this, 'bills.php', { id: this.billId }, (r) => {
      if (r.bill.kind === 'loan') { location.replace('utang'); return; } // an utang has its own page
      this.d = r;
      this.bill = r.bill;
      this.multiPay = r.payments.multi;
      if (this.bill.locked) this.tab = 'plan';
    });
  },
  methods: {
    showPerson(id) { Setlo.showPerson(id); },
    async invite(mode) {
      if (mode === 'rotate' && !(await Setlo.confirm({ title: 'Create a new invite link?', text: 'The current link and QR will stop working.', confirmText: 'New link', danger: true }))) return;
      if (mode === 'disable' && !(await Setlo.confirm({ title: 'Turn off the invite link?', text: 'Nobody new can join with it until you create a link again.', confirmText: 'Turn off', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'invite', mode, bill_id: this.billId }));
      if (r) this.bill.invite_code = r.invite_code;
    },
    async copyInvite() {
      try {
        await navigator.clipboard.writeText(this.inviteUrl);
      } catch (e) {
        Setlo.showCopy('Invite link', this.inviteUrl);
      }
      this.copied = true;
      setTimeout(() => { this.copied = false; }, 1800);
    },
    shareInvite() {
      navigator.share({ title: 'Join ' + this.bill.name + ' on Setlo', url: this.inviteUrl }).catch(() => {});
    },
    async load() {
      this.d = await api.get('bills.php', { id: this.billId });
      this.bill = this.d.bill;
      this.multiPay = this.d.payments.multi;
    },
    lookup() {
      clearTimeout(this.timer);
      const q = this.search.trim();
      if (q.length < 2) { this.results = []; return; }
      this.timer = setTimeout(async () => {
        const ids = this.d.members.map((m) => m.id);
        try { this.results = (await api.get('users.php', { q })).users.filter((u) => !ids.includes(u.id)); } catch (e) { Setlo.toast(e.message); }
      }, 250);
    },
    useSinglePayer() {
      this.multiPay = false;
      if (this.d.payments.multi) this.setPayer(this.bill.payer_id);
    },
    startMultiPay() {
      this.multiPay = true;
      this.payDraft = Object.fromEntries(this.realMembers.map((m) => [m.id, this.d.payments.multi && this.d.payments.paid[m.id] ? this.d.payments.paid[m.id].toFixed(2) : '']));
    },
    /** Fill the amounts with an equal split of the total (any odd centavos go to the first people). */
    splitEvenly() {
      const ids = this.evenIds;
      const cents = Math.round(this.d.total * 100);
      const base = Math.floor(cents / ids.length);
      const extra = cents - base * ids.length;
      this.payDraft = Object.fromEntries(this.realMembers.map((m) => [m.id, ids.includes(m.id) ? ((base + (ids.indexOf(m.id) < extra ? 1 : 0)) / 100).toFixed(2) : '']));
    },
    async savePayments() {
      if (!this.validateAll(this.realMembers.map((m) => 'pay' + m.id))) return;
      if (!this.payDraftOk) { Setlo.toast('The amounts must add up to ' + this.peso(this.d.total) + '.'); return; }
      const payments = Object.fromEntries(Object.entries(this.payDraft).filter(([, v]) => parseFloat(v) > 0).map(([k, v]) => [k, parseFloat(v)]));
      await Setlo.run(this, async () => {
        await api.post('bills.php', { action: 'set_payments', bill_id: this.billId, payments });
        await this.load();
        Setlo.toast('Payments saved', 'ok');
      });
    },
    validators() {
      const v = { guest: V.name(this.guestName, 'Guest name') };
      if (this.multiPay) for (const m of this.realMembers) v['pay' + m.id] = V.money(this.payDraft[m.id], { label: 'Amount' });
      return v;
    },
    async addGuest() {
      this.touch('guest');
      if (this.vErrors.guest) { Setlo.toast(this.vErrors.guest, 'warn'); return; }
      const name = this.guestName.trim();
      this.guestName = '';
      this.touched.guest = false;
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'add_guest', bill_id: this.billId, name }); await this.load(); });
    },
    async addMember(u) {
      this.search = ''; this.results = [];
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'add_member', bill_id: this.billId, user_id: u.id }); await this.load(); });
    },
    async removeMember(m) {
      if (!(await Setlo.confirm({ title: 'Remove ' + m.name + '?', text: 'Their item assignments on this bill will be cleared.', confirmText: 'Remove', danger: true }))) return;
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'remove_member', bill_id: this.billId, user_id: m.id }); await this.load(); });
    },
    async setPayer(id) {
      await Setlo.run(this, async () => { await api.post('bills.php', { action: 'set_payer', bill_id: this.billId, user_id: Number(id) }); await this.load(); });
    },
    async remove() {
      const text = this.bill.locked ? 'The bill and its settlement plan are removed for everyone, and they’re told. This cannot be undone.' : 'It will be removed for everyone. This cannot be undone.';
      if (!(await Setlo.confirm({ title: 'Delete this bill?', text, confirmText: 'Delete bill', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'delete', bill_id: this.billId }));
      if (r) location.href = r.redirect;
    },
    async reopen() {
      if (!(await Setlo.confirm({ title: 'Take back the settlement plan?', text: 'The payments asked for are removed and the bill opens for editing again. Everyone on it is told.', confirmText: 'Fix the bill' }))) return;
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'reopen', bill_id: this.billId }));
      if (r) location.href = r.redirect;
    },
    toggleArchive() { return Setlo.toggleArchive(this.bill); },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
