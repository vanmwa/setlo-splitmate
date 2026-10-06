<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
// Spending: an overview of your share of every bill, and (toggle) the closed bills it came from.
// ?tab=history opens the past-bills list (the old Bill History page redirects here).
$title = 'Spending';
$nav = 'stats';
$back = 'dashboard.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0">
        <h1 class="text-[21px] font-extrabold tracking-tight">Spending</h1>
        <p class="text-[12px] font-medium text-brand-50/90">
          <template v-if="tab === 'overview'">Your share of every bill — not what the table paid</template>
          <template v-else-if="history">{{ history.length }} closed {{ history.length === 1 ? 'bill' : 'bills' }} · {{ peso(historyTotal) }} split in total</template>
          <template v-else>Bills that are fully settled</template>
        </p>
      </div>
    </div>
    <div class="seg mt-5" role="tablist" aria-label="Spending view">
      <button role="tab" :aria-selected="tab === 'overview'" :class="{ on: tab === 'overview' }" @click="show('overview')">Overview</button>
      <button role="tab" :aria-selected="tab === 'history'" :class="{ on: tab === 'history' }" @click="show('history')">Past bills</button>
    </div>
  </div>

  <!-- Past bills: closed bills by month (open one for its breakdown) -->
  <div v-if="tab === 'history'" class="flex-1 space-y-3 px-5 pb-6 pt-4">
    <spinner v-if="!history"></spinner>
    <div v-else-if="!history.length" class="tile p-6 text-center">
      <p class="text-sm font-bold text-ink">No closed bills yet</p>
      <p class="mt-1 text-[12px] text-slate-400">Bills move here once every payment is confirmed.</p>
    </div>
    <template v-for="g in historyGroups" :key="g.month">
      <p class="pt-2 text-xs font-semibold text-slate-400">{{ g.month }}</p>
      <a v-for="b in g.bills" :key="b.id" :href="b.link" class="tile flex items-center gap-3 p-3.5">
        <div class="initials initials-muted">{{ b.initials }}</div>
        <div class="min-w-0 flex-1">
          <p class="truncate text-sm font-semibold text-slate-800">{{ b.name }}</p>
          <p class="text-xs text-slate-400">{{ b.members }} members · Closed {{ fmtDate(b.closed_at) }}</p>
        </div>
        <p class="text-sm font-bold text-slate-700">{{ peso(b.total) }}</p>
      </a>
    </template>
  </div>

  <spinner v-else-if="loading"></spinner>
  <div v-else class="flex-1 space-y-5 px-5 pb-6 pt-5 lg:grid lg:grid-cols-2 lg:items-start lg:gap-5 lg:space-y-0">

    <!-- Headline numbers -->
    <div class="grid grid-cols-2 gap-3 lg:col-span-2 lg:grid-cols-4">
      <div class="tile col-span-2 p-4">
        <p class="text-[12px] font-medium text-slate-500">This month</p>
        <p class="mt-0.5 text-[30px] font-extrabold leading-tight tracking-tight text-ink">{{ peso(s.this_month) }}</p>
        <p class="mt-1 text-[12px] font-semibold" :class="change === null ? 'text-slate-400' : change > 0 ? 'text-rose-500' : 'text-brand-600'">
          <template v-if="change === null">No spending last month to compare</template>
          <template v-else>{{ change > 0 ? '▲' : change < 0 ? '▼' : '' }} {{ Math.abs(change) }}% vs last month ({{ peso(s.last_month) }})</template>
        </p>
      </div>
      <div class="tile p-4">
        <p class="text-[12px] font-medium text-slate-500">Average per bill</p>
        <p class="mt-0.5 text-[19px] font-extrabold text-ink">{{ peso(s.average) }}</p>
        <p class="text-[11px] text-slate-400">{{ s.bills }} bills all-time</p>
      </div>
      <div class="tile p-4">
        <p class="text-[12px] font-medium text-slate-500">Saved with discounts</p>
        <p class="mt-0.5 text-[19px] font-extrabold text-ink">{{ peso(s.saved) }}</p>
        <p class="text-[11px] text-slate-400">Senior/PWD &amp; promos</p>
      </div>
    </div>

    <!-- Monthly bars -->
    <section class="tile p-4 lg:col-span-2">
      <div class="flex items-center justify-between">
        <h2 class="text-[14px] font-extrabold text-ink">Last 6 months</h2>
        <button @click="asTable = !asTable" class="text-[12px] font-bold text-brand-700">{{ asTable ? 'Show chart' : 'Show table' }}</button>
      </div>

      <table v-if="asTable" class="mt-3 w-full text-[13px]">
        <thead><tr class="text-left text-[11px] text-slate-400"><th class="pb-1 font-medium">Month</th><th class="pb-1 text-right font-medium">Your share</th></tr></thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="m in s.months" :key="m.month"><td class="py-1.5 text-slate-600">{{ monthLong(m.month) }}</td><td class="py-1.5 text-right font-semibold text-ink">{{ peso(m.amount) }}</td></tr>
        </tbody>
      </table>

      <div v-else class="relative mt-4" @mouseleave="hover = null">
        <!-- Tooltip -->
        <div v-if="hover !== null" class="pointer-events-none absolute -top-2 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-lg bg-ink px-2.5 py-1.5 text-[11px] font-semibold text-white shadow-lg"
             :style="{ left: ((hover + 0.5) / s.months.length * 100) + '%' }">
          {{ monthLong(s.months[hover].month) }} · {{ peso(s.months[hover].amount) }}
        </div>
        <div class="flex h-40 items-end gap-2 border-b border-slate-200" role="img" :aria-label="'Monthly spending: ' + s.months.map((m) => monthShort(m.month) + ' ' + peso(m.amount)).join(', ')">
          <button v-for="(m, i) in s.months" :key="m.month" class="group relative flex h-full flex-1 items-end focus:outline-none"
                  @mouseenter="hover = i" @focus="hover = i" @blur="hover = null" :aria-label="monthLong(m.month) + ' ' + peso(m.amount)">
            <span class="w-full rounded-t-[4px] bg-[#0d9488] transition-opacity group-hover:opacity-80 group-focus-visible:ring-2 group-focus-visible:ring-brand-300"
                  :style="{ height: m.amount > 0 ? Math.max(2, m.amount / maxMonth * 100) + '%' : '0' }"></span>
          </button>
        </div>
        <div class="mt-1.5 flex gap-2">
          <span v-for="(m, i) in s.months" :key="m.month" class="flex-1 text-center text-[11px]" :class="i === s.months.length - 1 ? 'font-bold text-ink' : 'text-slate-400'">{{ monthShort(m.month) }}</span>
        </div>
      </div>
    </section>

    <!-- Ranked lists -->
    <section v-for="list in lists" :key="list.key" class="tile p-4">
      <h2 class="text-[14px] font-extrabold text-ink">{{ list.title }}</h2>
      <p v-if="!s[list.key].length" class="mt-2 text-[12px] text-slate-400">Nothing yet.</p>
      <div class="mt-3 space-y-3">
        <div v-for="row in s[list.key]" :key="row.name">
          <div class="flex items-baseline justify-between gap-3 text-[13px]">
            <span class="truncate font-semibold text-slate-700">{{ row.name }}</span>
            <span class="shrink-0 font-bold text-ink">{{ peso(row.amount) }}</span>
          </div>
          <div class="mt-1 h-2 rounded-full bg-slate-100">
            <div class="h-2 rounded-full bg-[#0d9488]" :style="{ width: Math.max(2, row.amount / s[list.key][0].amount * 100) + '%' }"></div>
          </div>
        </div>
      </div>
    </section>

    <div class="tile grid grid-cols-2 divide-x divide-slate-100 py-3.5 text-center lg:col-span-2">
      <div><p class="text-[11px] text-slate-500">You've paid friends</p><p class="text-[15px] font-extrabold text-ink">{{ peso(s.paid_out) }}</p></div>
      <div><p class="text-[11px] text-slate-500">Friends paid you back</p><p class="text-[15px] font-extrabold text-ink">{{ peso(s.received) }}</p></div>
    </div>
    <p class="text-center text-[11px] text-slate-400 lg:col-span-2">Confirmed settlements only. Draft bills aren't counted.
      <button @click="show('history')" class="font-bold text-brand-700">See past bills →</button></p>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  data: () => ({
    loading: true, s: null, hover: null, asTable: false,
    tab: new URLSearchParams(location.search).get('tab') === 'history' ? 'history' : 'overview',
    history: null, historyLoading: false, // closed bills, loaded the first time Past bills is opened
    lists: [{ key: 'places', title: 'Where your money goes' }, { key: 'items', title: 'What you order most' }],
  }),
  computed: {
    maxMonth() { return Math.max(1, ...this.s.months.map((m) => m.amount)); },
    change() {
      if (!this.s.last_month) return null;
      return Math.round(((this.s.this_month - this.s.last_month) / this.s.last_month) * 100);
    },
    historyTotal() { return (this.history || []).reduce((sum, b) => sum + b.total, 0); },
    historyGroups() {
      const out = [];
      for (const b of this.history || []) {
        const month = this.fmtMonth(b.closed_at || b.created_at);
        let g = out.find((x) => x.month === month);
        if (!g) out.push((g = { month, bills: [] }));
        g.bills.push(b);
      }
      return out;
    },
  },
  async mounted() {
    if (this.tab === 'history') this.loadHistory();
    await Setlo.load(this, 'stats.php', null, (r) => { this.s = r; });
  },
  methods: {
    /** Switch views; the URL keeps the choice, so Back from a past bill returns to the list. */
    show(tab) {
      this.tab = tab;
      window.history.replaceState(null, '', tab === 'history' ? '?tab=history' : location.pathname);
      if (tab === 'history' && !this.history) this.loadHistory();
      window.scrollTo(0, 0);
    },
    loadHistory() { return Setlo.load(this, 'bills.php', { scope: 'history' }, (r) => { this.history = r.bills; }, 'historyLoading'); },
    monthShort(k) { return new Date(k + '-01T00:00').toLocaleDateString('en-PH', { month: 'short' }); },
    monthLong(k) { return new Date(k + '-01T00:00').toLocaleDateString('en-PH', { month: 'long', year: 'numeric' }); },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
