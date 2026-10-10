<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Dashboard';
$nav = 'dashboard';
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning,' : ($hour < 18 ? 'Good afternoon,' : 'Good evening,');
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-6 pb-16 pt-8">
    <div class="flex items-center justify-between">
      <div>
        <p class="text-[13px] font-medium text-brand-50/90"><?= h($greeting) ?></p>
        <h1 class="text-[22px] font-extrabold leading-tight tracking-tight"><?= h($user['full_name']) ?></h1>
      </div>
      <div class="flex items-center gap-2.5">
        <notif-bell></notif-bell>
        <div class="relative">
          <?php $photo = avatar_url($user['avatar'], $user['id']); ?>
          <button class="glass-btn<?= $photo ? ' overflow-hidden !bg-white/90 p-0.5' : '' ?>" @click="menu = !menu" aria-label="Account menu"><?php if ($photo): ?><img src="<?= h($photo) ?>" alt="" class="h-full w-full rounded-full object-cover" /><?php else: ?><?= h(initials($user['full_name'])) ?><?php endif; ?></button>
          <div v-if="menu" class="hero-pop tile absolute right-0 top-12 z-40 w-56 overflow-hidden text-slate-800 shadow-xl">
            <div class="border-b border-slate-100 px-4 py-3">
              <p class="text-sm font-bold text-ink"><?= h($user['full_name']) ?></p>
              <p class="text-xs text-slate-400"><?= h($user['email']) ?> · <?= h($user['payment_method']) ?></p>
            </div>
            <a href="profile" class="block px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Profile &amp; payment QR</a>
            <a href="stats" class="block px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Spending stats</a>
            <form method="post" action="logout" class="border-t border-slate-100">
              <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>" />
              <button class="w-full px-4 py-3 text-left text-sm font-semibold text-rose-600 hover:bg-rose-50">Sign out</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="relative z-10 -mt-10 flex-1 space-y-6 px-5 pb-6">
    <!-- Owe / owed summary -->
    <div class="tile grid grid-cols-2 divide-x divide-slate-100 py-4 shadow-lg shadow-slate-900/5">
      <a href="my-settlements" class="px-4">
        <p class="text-[12px] font-medium text-slate-500">You owe</p>
        <p class="mt-0.5 text-[22px] font-extrabold tracking-tight text-rose-500">{{ peso(d.owe.total) }}</p>
        <p class="mt-1 text-[11px] text-slate-400">{{ d.owe.count }} open</p>
      </a>
      <a href="my-settlements?tab=owed" class="px-4">
        <p class="text-[12px] font-medium text-slate-500">You're owed</p>
        <p class="mt-0.5 text-[22px] font-extrabold tracking-tight text-brand-600">{{ peso(d.owed.total) }}</p>
        <p class="mt-1 text-[11px] text-slate-400">{{ d.owed.count }} open</p>
      </a>
    </div>

    <!-- Quick actions -->
    <div class="grid grid-cols-4 gap-2">
      <a href="my-bills?new=1" class="flex flex-col items-center gap-1.5">
        <div class="qa-icon bg-brand-100/70 text-brand-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V6a2 2 0 012-2h2M16 4h2a2 2 0 012 2v2M20 16v2a2 2 0 01-2 2h-2M8 20H6a2 2 0 01-2-2v-2M8 12h8"/></svg>
        </div>
        <span class="text-[12px] font-semibold text-slate-600">New Bill</span>
      </a>
      <a href="my-bills" class="flex flex-col items-center gap-1.5">
        <div class="qa-icon bg-indigo-100 text-indigo-500">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3h12v18l-3-2-3 2-3-2-3 2V3zM9 8h6M9 12h6"/></svg>
        </div>
        <span class="text-[12px] font-semibold text-slate-600">My Bills</span>
      </a>
      <a href="my-settlements" class="flex flex-col items-center gap-1.5">
        <div class="qa-icon bg-amber-100 text-amber-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M14.5 9.5c-.4-.9-1.4-1.5-2.5-1.5-1.4 0-2.5.8-2.5 2s1.1 1.7 2.5 2 2.5.8 2.5 2-1.1 2-2.5 2c-1.1 0-2.1-.6-2.5-1.5M12 6.5V8m0 8v1.5"/></svg>
        </div>
        <span class="text-[12px] font-semibold text-slate-600">Settle Up</span>
      </a>
      <a href="stats?tab=history" class="flex flex-col items-center gap-1.5">
        <div class="qa-icon bg-slate-200/80 text-slate-600">
          <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg>
        </div>
        <span class="text-[12px] font-semibold text-slate-600">History</span>
      </a>
    </div>

    <a href="stats" class="tile flex items-center gap-3 px-4 py-3">
      <span class="qa-icon !h-10 !w-10 bg-brand-100/70 text-brand-700">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
      </span>
      <span class="flex-1">
        <span class="block text-[13px] font-bold text-ink">Your food spending</span>
        <span class="block text-[12px] text-slate-400">Monthly totals, favorite spots, what you order most</span>
      </span>
      <span class="text-slate-300">›</span>
    </a>

    <div class="space-y-6 lg:grid lg:grid-cols-2 lg:items-start lg:gap-6 lg:space-y-0">
    <!-- Active bills -->
    <section>
      <div class="mb-3 flex items-center justify-between">
        <h2 class="text-[15px] font-extrabold text-ink">Active Bills</h2>
        <a href="my-bills" class="text-[13px] font-bold text-brand-700">See all</a>
      </div>
      <spinner v-if="loading"></spinner>
      <div v-else-if="!d.bills.length" class="tile p-5 text-center">
        <p class="text-sm font-bold text-ink">No open bills</p>
        <p class="mt-1 text-[12px] text-slate-400">Create one after your next meal out.</p>
        <a href="my-bills?new=1" class="btn btn-primary btn-sm mt-3">Create a bill</a>
      </div>
      <div v-else class="space-y-3">
        <a v-for="b in d.bills" :key="b.id" :href="b.link" class="tile flex items-center gap-3.5 p-4">
          <div class="initials">{{ b.initials }}</div>
          <div class="min-w-0 flex-1">
            <p class="truncate text-[14px] font-bold text-ink">{{ b.name }}</p>
            <p class="mt-0.5 text-[12px] text-slate-400">{{ b.members }} members · {{ peso(b.total) }}</p>
            <div class="progress mt-2 w-full max-w-[150px]"><span :style="{ width: b.progress + '%' }"></span></div>
          </div>
          <status-pill :status="b.status"></status-pill>
        </a>
      </div>
    </section>

    <!-- Recent activity -->
    <section v-if="d.activity.length">
      <h2 class="mb-3 text-[15px] font-extrabold text-ink">Recent Activity</h2>
      <div class="tile divide-y divide-slate-100">
        <div v-for="(a, i) in d.activity" :key="i" class="flex items-center gap-3 px-4 py-3.5">
          <span class="h-2 w-2 shrink-0 rounded-full" :class="dot[a.event] || 'bg-slate-300'"></span>
          <p class="flex-1 text-[13px] text-slate-600"><b class="text-ink">{{ a.actor }}</b> {{ describe(a) }}</p>
          <span class="shrink-0 text-[11px] text-slate-400">{{ timeAgo(a.created_at) }}</span>
        </div>
      </div>
    </section>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  data: () => ({
    loading: true, menu: false,
    d: { owe: { total: 0, count: 0 }, owed: { total: 0, count: 0 }, bills: [], activity: [] },
    dot: { marked_paid: 'bg-amber-400', confirmed: 'bg-brand-500', disputed: 'bg-rose-400', resent: 'bg-sky-400', nudged: 'bg-slate-400' },
  }),
  async mounted() {
    document.addEventListener('click', (e) => { if (!e.target.closest('[aria-label="Account menu"]') && !e.target.closest('form')) this.menu = false; });
    await Setlo.load(this, 'dashboard.php', null, (r) => { this.d = r; });
    this.showNewAchievements();
  },
  methods: {
    /** Badges earned while away (someone else's action earned them): their pop-ups, once. */
    async showNewAchievements() {
      try {
        const r = await api.get('achievements.php');
        if (!r.unseen.length) return;
        await Setlo.showAchievements(r.unseen);
        await api.post('achievements.php', { action: 'seen', codes: r.unseen.map((a) => a.code) });
      } catch (e) { /* badges are non-critical */ }
    },
    describe(a) {
      const amt = this.peso(a.amount);
      switch (a.event) {
        case 'marked_paid': return `marked ${amt} as paid to ${a.to}`;
        case 'confirmed': return `confirmed receiving ${amt}`;
        case 'disputed': return `disputed a ${amt} payment`;
        case 'resent': return `reopened a ${amt} payment`;
        case 'nudged': return a.by_admin ? `followed up on a dispute in ${a.bill}` : `sent a reminder about ${amt}`;
        default: return `updated ${a.bill}`;
      }
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
