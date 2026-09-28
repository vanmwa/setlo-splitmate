<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Settlement Audit Trail';
$nav = 'settle';
$sid = (int) ($_GET['id'] ?? 0);
if (!$sid) {
    redirect('pages/my-settlements');
}
$back = 'my-settlements.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0">
        <h1 class="text-[19px] font-extrabold leading-tight tracking-tight">Settlement Audit Trail</h1>
        <p v-if="s" class="truncate text-[12px] font-medium text-brand-50/90">{{ who(s.from) }} → {{ who(s.to) }} · {{ peso(s.amount) }}</p>
      </div>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 px-5 py-5">
    <div class="tile mb-5 flex items-center justify-between p-3.5">
      <div class="flex items-center gap-2.5">
        <avatar :user="s.from" :size="32"></avatar>
        <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        <avatar :user="s.to" :size="32"></avatar>
        <a :href="'bill-detail.php?bill=' + s.bill_id" class="ml-1 text-[13px] font-semibold text-slate-600">{{ s.bill_name }}</a>
      </div>
      <status-pill :status="s.status"></status-pill>
    </div>

    <div v-if="s.payment_ref || s.has_proof || s.settle_method" class="tile -mt-2 mb-5 flex items-center justify-between gap-3 p-3.5 text-[13px]">
      <span class="min-w-0 text-slate-600">
        <b v-if="s.settle_method" class="block text-slate-800">{{ { online: 'Paid online via PayMongo', transfer: 'Paid by transfer', cash: 'Paid in cash, in person' }[s.settle_method] }}</b>
        <span class="break-all">{{ s.payment_ref ? 'Ref no. ' + s.payment_ref : (s.has_proof ? 'Proof of payment attached' : '') }}</span>
      </span>
      <button v-if="s.has_proof && (s.from.id === me || s.to.id === me)" @click="proof = true" class="font-bold text-brand-700">View proof</button>
    </div>
    <div v-if="proof" class="fixed inset-0 z-50 flex items-center justify-center bg-ink/80 p-6" @click="proof = false">
      <img :src="'<?= h(url('api/settlements.php')) ?>?proof=' + s.id" alt="Proof of payment" class="max-h-[80vh] max-w-full rounded-2xl bg-white shadow-2xl" />
    </div>

    <ol class="relative pl-6">
      <div class="absolute bottom-2 left-[7px] top-2 w-0.5 bg-slate-200"></div>
      <li v-for="(e, i) in events" :key="i" class="relative pb-6 last:pb-2">
        <div class="absolute -left-6 top-0.5 h-4 w-4 rounded-full ring-4" :class="styles[e.event] || styles.created"></div>
        <p class="text-sm font-semibold text-slate-800">{{ labels[e.event] || e.event }}</p>
        <p v-if="e.note" class="mt-0.5 text-xs text-slate-500">{{ e.event === 'disputed' ? '“' + e.note + '”' : e.note }}</p>
        <p class="mt-1 text-[11px] text-slate-400">{{ fmtDateTime(e.created_at) }}<span v-if="e.actor"> · {{ e.actor }}</span></p>
      </li>
    </ol>

    <div class="tile mt-5 bg-slate-50 p-3.5">
      <p class="text-xs text-slate-500">Every status change is timestamped and append-only — this is the record Setlo relies on instead of taking a sender's “I paid” claim as final.</p>
    </div>

    <template v-if="others.length">
      <p class="section-label mt-6">OTHER SETTLEMENTS IN THIS BILL</p>
      <div class="space-y-2">
        <a v-for="o in others" :key="o.id" :href="'settlement-audit.php?id=' + o.id" class="tile flex items-center justify-between p-3.5">
          <div class="flex items-center gap-2.5">
            <avatar :user="o.from" :size="28"></avatar>
            <svg class="h-3.5 w-3.5 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            <avatar :user="o.to" :size="28"></avatar>
            <span class="ml-1 text-sm font-semibold">{{ peso(o.amount) }}</span>
          </div>
          <status-pill :status="o.status"></status-pill>
        </a>
      </div>
    </template>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  data: () => ({
    id: <?= $sid ?>, me: <?= (int) $user['id'] ?>, loading: true, s: null, events: [], others: [], proof: false,
    labels: { created: 'Settlement Created', marked_paid: 'Marked as Paid', confirmed: 'Confirmed Received', disputed: 'Rejected / Disputed', resent: 'Reopened by Sender', nudged: 'Reminder Sent' },
    styles: {
      created: 'bg-brand-600 ring-brand-100', marked_paid: 'bg-amber-500 ring-amber-100', confirmed: 'bg-emerald-500 ring-emerald-100',
      disputed: 'bg-red-500 ring-red-100', resent: 'bg-sky-500 ring-sky-100', nudged: 'bg-slate-500 ring-slate-100',
    },
  }),
  async mounted() {
    await Setlo.load(this, 'settlements.php', { id: this.id }, (r) => {
      this.s = r.settlement;
      this.events = r.events;
      this.others = r.others;
    });
  },
  methods: { who(u) { return u.id === this.me ? 'You' : u.first; } },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
