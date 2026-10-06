<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Notifications';
$nav = 'notifications';
$back = 'dashboard.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <h1 class="flex-1 text-[21px] font-extrabold tracking-tight">Notifications</h1>
      <button v-if="unread" @click="readAll" class="text-xs font-semibold text-brand-50">Mark all read</button>
    </div>
  </div>

  <div class="flex-1 px-5 py-3">
    <spinner v-if="loading"></spinner>
    <p v-else-if="!items.length" class="py-10 text-center text-[13px] text-slate-400">No notifications yet.</p>
    <div v-else class="tile divide-y divide-slate-100">
      <a v-for="n in items" :key="n.id" :href="n.link || '#'" @click="markRead(n)" class="flex gap-3 px-4 py-3.5" :class="{ 'bg-brand-50/40': !n.is_read }">
        <notif-icon :type="n.type" :size="36"></notif-icon>
        <div class="min-w-0 flex-1">
          <p class="text-sm text-slate-800">{{ n.message }}</p>
          <p class="mt-0.5 text-xs text-slate-400">{{ timeAgo(n.created_at) }}</p>
        </div>
        <span v-if="!n.is_read" class="mt-2 h-2 w-2 shrink-0 rounded-full bg-brand-500"></span>
      </a>
    </div>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  data: () => ({ loading: true, items: [], unread: 0 }),
  async mounted() {
    await Setlo.load(this, 'notifications.php', { limit: 50 }, (r) => {
      this.items = r.notifications;
      this.unread = r.unread;
    });
  },
  methods: {
    markRead(n) {
      if (!n.is_read) api.post('notifications.php', { action: 'read', id: n.id }).catch(() => {});
    },
    async readAll() {
      await Setlo.run(this, () => api.post('notifications.php', { action: 'read_all' }));
      this.items.forEach((n) => { n.is_read = true; });
      this.unread = 0;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
