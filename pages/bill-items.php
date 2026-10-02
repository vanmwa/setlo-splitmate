<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Bill Items';
$nav = 'bills';
$billId = (int) ($_GET['bill'] ?? 0);
if (!$billId) {
    redirect('pages/my-bills');
}
$back = 'my-bills.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-6 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <h1 class="min-w-0 flex-1 truncate text-[20px] font-extrabold tracking-tight">{{ bill ? bill.name : '' }}</h1>
      <status-pill v-if="bill" :status="bill.status"></status-pill>
    </div>
    <div v-if="bill" class="mt-5 flex items-end justify-between">
      <div>
        <p class="text-[12px] font-medium text-brand-50/90">Total · {{ fmtDate(bill.created_at) }}</p>
        <p class="text-[30px] font-extrabold leading-tight tracking-tight">{{ peso(d.total) }}</p>
      </div>
      <div class="mb-1.5 flex -space-x-2">
        <avatar v-for="m in d.members" :key="m.id" :user="m" :size="30" ring></avatar>
      </div>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 px-5 pb-4 pt-5">
    <div class="mb-3 flex items-center justify-between">
      <h2 class="text-[15px] font-extrabold text-ink">Items</h2>
      <div class="flex items-center gap-3">
        <a :href="'bill-detail.php?bill=' + billId" class="text-[13px] font-bold text-slate-500">Details</a>
        <button v-if="canEdit && unassignedCount" @click="splitEvenly" class="text-[13px] font-bold text-brand-700">Split evenly</button>
      </div>
    </div>

    <p v-if="!canEdit && !bill.locked" class="mb-3 rounded-xl border border-brand-100 bg-brand-50 px-3.5 py-2.5 text-[12px] font-medium text-brand-800">
      You're a Bill Member. Only the Bill Creator ({{ creatorName }}) can change assignments.
    </p>
    <p v-if="byPercent" class="mb-3 rounded-xl border border-brand-100 bg-brand-50 px-3.5 py-2.5 text-[12px] font-medium text-brand-800">
      This bill is split by percentage of the whole total, so items don't need assigning. <a v-if="canEdit" :href="'assign-items.php?bill=' + billId" class="font-bold underline">Change the split</a>
    </p>
    <p v-if="bill.locked" class="mb-3 rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-[12px] text-slate-500">
      <b class="text-slate-700">Editing locked.</b> This bill is {{ bill.status === 'closed' ? 'closed' : 'settling' }}, so item assignments can't change.
    </p>

    <div v-if="!d.items.length" class="tile p-5 text-center text-[13px] text-slate-400">No items yet — the creator hasn't scanned the receipt.</div>
    <div class="space-y-3">
      <component :is="canEdit ? 'button' : 'div'" v-for="it in d.items" :key="it.id" @click="canEdit && openSheet(it)"
        class="flex w-full items-center gap-3 p-4 text-left"
        :class="it.who.length ? 'tile' : 'rounded-[18px] border-[1.5px] border-dashed border-rose-300 bg-rose-50/70'">
        <div class="min-w-0 flex-1">
          <p class="text-[14px] font-bold text-ink">{{ it.name }}{{ it.qty > 1 ? ' ×' + it.qty : '' }}</p>
          <p v-if="it.details" class="mt-0.5 text-[11.5px] text-slate-500">Includes {{ it.details }}</p>
          <p class="mt-0.5 text-[12px]" :class="it.who.length ? 'text-slate-400' : 'text-slate-500'">
            {{ !it.who.length ? (canEdit ? 'Tap to assign' : 'Not yet assigned') : it.qty > 1 ? pesoShort(it.unit_price) + ' each' : '1 item' }}
          </p>
        </div>
        <span v-if="!it.who.length" class="pill pill-unassigned">Unassigned</span>
        <div v-else class="flex -space-x-1.5"><avatar v-for="uid in it.who" :key="uid" :user="memberMap[uid]" :size="22" ring></avatar></div>
        <span class="w-16 text-right text-[15px] font-extrabold text-ink">{{ pesoShort(it.line_total) }}</span>
      </component>
    </div>
    <p v-if="d.extras > 0" class="mt-3 text-center text-[12px] text-slate-400">+ {{ peso(d.extras) }} {{ bill && bill.tax_included ? 'service charge' : 'tax & service charge' }}, split equally</p>
    <p v-if="bill.discount > 0" class="mt-1 text-center text-[12px] text-emerald-600">− {{ peso(bill.discount) }} receipt discount<span v-if="d.discount_by[me]"> · yours: {{ peso(d.discount_by[me]) }}</span></p>
  </div>

  <div class="sticky bottom-[65px] z-20 px-4 pb-3 lg:bottom-4 lg:px-0 lg:pb-0">
    <div class="flex items-center justify-between rounded-[20px] bg-ink py-2.5 pl-5 pr-2.5 shadow-xl shadow-slate-900/20">
      <div>
        <p class="text-[11px] font-medium text-slate-400">Your share</p>
        <p class="text-[21px] font-extrabold leading-tight tracking-tight text-white">{{ peso(d.shares[me] || 0) }}</p>
      </div>
      <button v-if="canEdit" @click="confirmSplit" class="dock-btn" :disabled="busy">Confirm split</button>
      <a v-else-if="bill && bill.locked" :href="'bill-detail.php?bill=' + billId" class="dock-btn flex items-center">Settlement plan</a>
      <span v-else class="dock-btn flex items-center opacity-60">Waiting for creator</span>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <div v-if="editing" class="sheet-backdrop" @click.self="editing = null">
    <div class="sheet">
      <div class="sheet-grip"></div>
      <p class="text-[12px] font-semibold text-slate-400">Who had this?</p>
      <div class="mb-4 mt-0.5 flex items-center justify-between">
        <h2 class="text-[18px] font-extrabold text-ink">{{ editing.name }}{{ editing.qty > 1 ? ' ×' + editing.qty : '' }}</h2>
        <span class="text-[16px] font-extrabold text-ink">{{ peso(editing.line_total) }}</span>
      </div>
      <div class="space-y-2">
        <button v-for="m in d.members" :key="m.id" @click="toggleDraft(m.id)" class="flex h-14 w-full items-center gap-3 rounded-2xl border-[1.5px] px-3.5" :class="draft.includes(m.id) ? 'border-brand-500 bg-brand-50/60' : 'border-slate-200'">
          <avatar :user="m" :size="32"></avatar>
          <span class="flex-1 text-left text-[14px] font-bold text-ink">{{ m.id === me ? m.name + ' (You)' : m.name }}</span>
          <span class="flex h-5 w-5 items-center justify-center rounded-md" :class="draft.includes(m.id) ? 'bg-brand-600' : 'border-2 border-slate-300'">
            <svg v-if="draft.includes(m.id)" class="h-3.5 w-3.5 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          </span>
        </button>
      </div>
      <p class="mt-4 h-4 text-center text-[12px] text-slate-500">{{ draft.length ? peso(editing.line_total / draft.length) + ' each · split ' + draft.length + ' way' + (draft.length > 1 ? 's' : '') : '' }}</p>
      <button @click="saveSheet" class="btn-pill btn-pill-primary mt-3" :disabled="busy">Done</button>
    </div>
  </div>
</div>

<script>
Setlo.mount({
  data: () => ({
    billId: <?= $billId ?>, me: <?= (int) $user['id'] ?>,
    loading: true, busy: false, bill: null,
    d: { members: [], items: [], shares: {}, discount_by: {}, total: 0, extras: 0 },
    editing: null, draft: [],
  }),
  computed: {
    memberMap() { return Object.fromEntries(this.d.members.map((m) => [m.id, m])); },
    canEdit() { return this.bill && this.d.me.is_creator && !this.bill.locked; },
    creatorName() { return (this.memberMap[this.bill.creator_id] || {}).name; },
    byPercent() { return !!this.bill && this.bill.split_mode === 'percent'; },
    // Split by percentage: items don't need assigning.
    unassignedCount() { return this.byPercent ? 0 : this.d.items.filter((it) => !it.who.length).length; },
  },
  async mounted() { await Setlo.load(this, 'bills.php', { id: this.billId }, (r) => { this.d = r; this.bill = r.bill; }); },
  methods: {
    async load() {
      this.d = await api.get('bills.php', { id: this.billId });
      this.bill = this.d.bill;
    },
    openSheet(it) { this.editing = it; this.draft = [...it.who]; },
    toggleDraft(id) { this.draft = this.draft.includes(id) ? this.draft.filter((x) => x !== id) : [...this.draft, id]; },
    async saveSheet() {
      const ok = await Setlo.run(this, async () => {
        await api.post('assignments.php', { bill_id: this.billId, assignments: { [this.editing.id]: this.draft } });
        await this.load();
        return true;
      });
      if (ok) this.editing = null;
    },
    async splitEvenly() {
      await Setlo.run(this, async () => {
        await api.post('assignments.php', { bill_id: this.billId, action: 'split_evenly' });
        await this.load();
      });
    },
    async confirmSplit() {
      if (this.unassignedCount) {
        Setlo.toast('Assign ' + this.unassignedCount + ' item' + (this.unassignedCount > 1 ? 's' : '') + ' before confirming');
        return;
      }
      if (!(await Setlo.confirm({ title: 'Start settling?', text: 'Everyone will be asked to pay. Item assignments will be locked.', confirmText: 'Start settling' }))) return;
      const r = await Setlo.run(this, () => api.post('bills.php', { action: 'start_settling', bill_id: this.billId }));
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
