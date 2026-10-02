<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Bill Breakdown';
$nav = 'bills';
$billId = (int) ($_GET['bill'] ?? 0);
if (!$billId) {
    redirect('pages/bill-history');
}
$back = 'bill-history.php';
$headExtra = ['assets/js/summary.js'];
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="truncate text-[19px] font-extrabold leading-tight tracking-tight">{{ bill ? bill.name : '' }}</h1>
        <p v-if="bill" class="text-[12px] font-medium text-brand-50/90">{{ bill.closed_at ? 'Closed · ' + fmtDate(bill.closed_at, true) : 'Created · ' + fmtDate(bill.created_at, true) }}</p>
      </div>
      <status-pill v-if="bill" :status="bill.status"></status-pill>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-5 px-5 pb-6 pt-5">
    <section>
      <p class="section-label">ITEMIZED RECEIPT</p>
      <div class="tile divide-y divide-slate-100">
        <template v-for="g in itemGroups" :key="g.key">
        <p v-if="g.label" class="bg-slate-50 px-3.5 py-2 text-[11.5px] font-extrabold tracking-[.04em] text-slate-500">{{ g.label }}</p>
        <div v-for="it in g.items" :key="it.id" class="flex items-center justify-between gap-3 p-3.5">
          <div class="min-w-0">
            <p class="text-sm font-medium">{{ it.name }} ×{{ it.qty }}</p>
            <p class="text-xs text-slate-400">{{ assignedLabel(it) }}</p>
          </div>
          <p class="shrink-0 text-sm font-semibold">{{ peso(it.line_total) }}</p>
        </div>
        </template>
        <div v-if="d.extras > 0" class="flex items-center justify-between p-3.5">
          <div><p class="text-sm font-medium">Tax + service charge</p><p class="text-xs text-slate-400">Split equally</p></div>
          <p class="text-sm font-semibold">{{ peso(d.extras) }}</p>
        </div>
        <div v-if="bill.discount > 0" class="flex items-center justify-between p-3.5">
          <div><p class="text-sm font-medium">Receipt discount</p><p class="text-xs text-slate-400">{{ d.members.some((m) => m.discount_type !== 'none') ? 'Senior/PWD members first' : 'Shared in proportion to orders' }}</p></div>
          <p class="text-sm font-semibold text-emerald-600">−{{ peso(bill.discount) }}</p>
        </div>
      </div>
    </section>

    <section>
      <p class="section-label">PER-MEMBER SHARE</p>
      <div class="tile divide-y divide-slate-100">
        <div v-for="m in d.members" :key="m.id" class="flex items-center gap-3 p-3.5">
          <avatar :user="m" :size="32"></avatar>
          <p class="flex-1 text-sm font-medium">{{ m.name }}{{ d.payments && d.payments.paid[m.id] ? ' (paid ' + peso(d.payments.paid[m.id]) + ')' : '' }}{{ m.id === me ? ' · You' : '' }}
            <span v-if="m.discount_type !== 'none'" class="pill pill-settled ml-1">{{ m.discount_type === 'pwd' ? 'PWD' : 'Senior' }}</span>
            <span v-if="d.discount_by[m.id]" class="block text-xs text-emerald-600">−{{ peso(d.discount_by[m.id]) }} discount</span>
          </p>
          <p class="text-sm font-bold">{{ peso(d.shares[m.id] || 0) }}</p>
        </div>
      </div>
    </section>

    <section v-if="d.settlements.length">
      <p class="section-label">SETTLEMENT HISTORY</p>
      <div class="tile divide-y divide-slate-100">
        <a v-for="s in d.settlements" :key="s.id" :href="'settlement-audit.php?id=' + s.id" class="flex items-center justify-between p-3.5">
          <div>
            <p class="text-sm font-medium">{{ s.from.first }} → {{ s.to.first }} · {{ peso(s.amount) }}</p>
            <p class="text-xs text-slate-400">{{ s.confirmed_at ? 'Confirmed ' + fmtDateTime(s.confirmed_at) : s.paid_at ? 'Marked paid ' + fmtDateTime(s.paid_at) : 'Not yet paid' }}</p>
          </div>
          <status-pill :status="s.status"></status-pill>
        </a>
      </div>
    </section>

    <share-summary :d="d"></share-summary>

    <div class="tile bg-slate-50 p-3.5">
      <div class="flex justify-between text-sm font-bold"><span>Bill total</span><span class="text-brand-700">{{ peso(d.total) }}</span></div>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  data: () => ({ billId: <?= $billId ?>, me: <?= (int) $user['id'] ?>, loading: true, bill: null, d: { members: [], items: [], settlements: [], shares: {}, discount_by: {} } }),
  computed: {
    /** Items under their receipt's title when the bill has several receipts (see Setlo.groupByReceipt). */
    itemGroups() { return Setlo.groupByReceipt(this.d.items, this.d.receipts); },
    memberMap() { return Object.fromEntries(this.d.members.map((m) => [m.id, m])); } },
  async mounted() {
    await Setlo.load(this, 'bills.php', { id: this.billId }, (r) => { this.d = r; this.bill = r.bill; });
  },
  methods: {
    assignedLabel(it) {
      if (!it.who.length) return this.bill.split_mode === 'percent' ? 'Split by percentage' : 'Unassigned';
      if (it.who.length === this.d.members.length && this.d.members.length > 2) return 'Split equally';
      return 'Assigned to: ' + it.who.map((id) => this.memberMap[id].first).join(', ');
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
