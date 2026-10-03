<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Group';
$nav = 'groups';
$groupId = (int) ($_GET['id'] ?? 0);
if (!$groupId) {
    redirect('pages/groups');
}
$back = 'groups.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="truncate text-[21px] font-extrabold tracking-tight">{{ g ? g.name : 'Group' }}</h1>
        <p class="text-[12px] font-medium text-brand-50/90">{{ members.length }} people · {{ bills.length }} bill{{ bills.length === 1 ? '' : 's' }}<span v-if="open > 0"> · {{ peso(open) }} still unpaid</span></p>
      </div>
    </div>
    <div class="seg mt-5">
      <button v-for="t in tabs" :key="t.key" :class="{ on: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-4 px-5 pb-6 pt-4">
    <a :href="'my-bills?new=1&group=' + groupId" class="btn-pill btn-pill-primary">+ New bill with this group</a>

    <!-- Trail: every payment on the group's bills, with receipts -->
    <template v-if="tab === 'trail'">
      <div v-if="!trail.length" class="tile p-5 text-center text-[13px] text-slate-400">No payments yet. They appear here as the group's bills get paid.</div>
      <section v-for="day in trailByDay" :key="day.date">
        <p class="section-label">{{ day.label }}</p>
        <div class="tile divide-y divide-slate-100">
          <div v-for="p in day.items" :key="p.id" class="flex items-center gap-3 px-3.5 py-3">
            <button @click="showPerson(p.from.id)" class="shrink-0 rounded-full" :aria-label="'About ' + p.from.name"><avatar :user="p.from" :size="30"></avatar></button>
            <div class="min-w-0 flex-1">
              <p class="truncate text-[13px] font-semibold text-ink">{{ who(p.from) }} → {{ who(p.to) }}</p>
              <p class="truncate text-[11.5px] text-slate-400">
                <a :href="'settlement-audit?id=' + p.settlement_id" class="hover:underline">{{ p.bill_name }}</a> · {{ methodName[p.method] }}<span v-if="p.paid_by.id !== p.from.id"> · paid by {{ who(p.paid_by) }}</span> ·
                <span :class="statusTone[p.status]" class="font-semibold">{{ statusName[p.status] }}</span>
              </p>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-[13.5px] font-extrabold tabular-nums">{{ peso(p.amount) }}</p>
              <button v-if="p.receipt_no" @click="showReceipt(p.id)" class="text-[11px] font-bold text-brand-700 underline">{{ p.receipt_no }}</button>
            </div>
          </div>
        </div>
      </section>
    </template>

    <!-- Bills started from this group -->
    <template v-if="tab === 'bills'">
      <div v-if="!bills.length" class="tile p-5 text-center text-[13px] text-slate-400">No bills yet — start one with the button above.</div>
      <a v-for="b in bills" :key="b.id" :href="b.link" class="tile flex items-center gap-3.5 p-4">
        <div class="initials">{{ b.initials }}</div>
        <div class="min-w-0 flex-1">
          <p class="truncate text-[14px] font-bold text-ink">{{ b.name }}</p>
          <p class="mt-0.5 text-[12px] text-slate-400">{{ peso(b.total) }} · {{ fmtDate(b.created_at) }}</p>
        </div>
        <status-pill :status="b.status"></status-pill>
      </a>
    </template>

    <!-- People -->
    <template v-if="tab === 'people'">
      <div class="tile divide-y divide-slate-100">
        <div v-for="m in members" :key="m.id" class="flex items-center gap-3 p-3.5">
          <button @click="showPerson(m.id)" class="shrink-0 rounded-full" :aria-label="'About ' + m.name"><avatar :user="m" :size="34"></avatar></button>
          <button @click="showPerson(m.id)" class="min-w-0 flex-1 truncate text-left text-[13.5px] font-semibold text-ink hover:underline">
            {{ m.name }} <span v-if="m.id === me" class="text-[11px] font-medium text-brand-600">(You)</span><span v-if="m.id === g.owner_id" class="text-[11px] font-medium text-slate-400"> · made the group</span>
          </button>
          <button v-if="g.is_owner && m.id !== g.owner_id" @click="removeMember(m)" class="text-slate-300 hover:text-rose-500" :aria-label="'Remove ' + m.name" :disabled="busy">✕</button>
        </div>
      </div>
      <template v-if="g.is_owner">
        <input v-model="search" @input="lookup" class="input-soft !bg-white" placeholder="Add someone by name or email" aria-label="Add someone" />
        <div v-if="results.length" class="tile divide-y divide-slate-100 overflow-hidden">
          <button v-for="u in results" :key="u.id" @click="addMember(u)" class="flex w-full items-center gap-3 px-3.5 py-2.5 text-left hover:bg-slate-50">
            <avatar :user="u" :size="28"></avatar><span class="flex-1 text-[13px] font-bold">{{ u.name }}</span><span class="text-[12px] font-bold text-brand-700">Add</span>
          </button>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <button @click="rename" class="btn btn-outline" :disabled="busy">Rename</button>
          <button @click="remove" class="btn btn-ghost !text-rose-600" :disabled="busy">Delete group</button>
        </div>
      </template>
      <button v-else @click="leave" class="btn btn-ghost w-full !text-rose-600" :disabled="busy">Leave group</button>
    </template>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>
</div>

<script>
Setlo.mount({
  data: () => ({
    groupId: <?= $groupId ?>, me: <?= (int) $user['id'] ?>, loading: true, busy: false,
    g: null, members: [], bills: [], trail: [], open: 0, search: '', results: [], timer: null,
    tab: 'trail', tabs: [{ key: 'trail', label: 'Payments' }, { key: 'bills', label: 'Bills' }, { key: 'people', label: 'People' }],
    methodName: { online: 'Online', transfer: 'Transfer', cash: 'Cash', credit: 'Credit' },
    statusName: { confirmed: 'Received', awaiting: 'Waiting', rejected: 'Rejected' },
    statusTone: { confirmed: 'text-emerald-600', awaiting: 'text-blue-600', rejected: 'text-red-600' },
  }),
  computed: {
    /** Payments grouped by day, newest first. */
    trailByDay() {
      const days = [];
      for (const p of this.trail) {
        const date = p.created_at.slice(0, 10);
        let d = days[days.length - 1];
        if (!d || d.date !== date) days.push((d = { date, label: this.fmtDate(p.created_at), items: [] }));
        d.items.push(p);
      }
      return days;
    },
  },
  async mounted() {
    await Setlo.load(this, 'groups.php', { id: this.groupId }, (r) => this.fill(r));
  },
  methods: {
    fill(r) {
      this.g = r.group;
      this.members = r.members;
      this.bills = r.bills;
      this.trail = r.trail;
      this.open = r.open;
    },
    async load() { this.fill(await api.get('groups.php', { id: this.groupId })); },
    who(u) { return u.id === this.me ? 'You' : u.first; },
    showPerson(id) { Setlo.showPerson(id); },
    showReceipt(id) { Setlo.showReceipt(id); },
    lookup() {
      clearTimeout(this.timer);
      const q = this.search.trim();
      if (q.length < 2) { this.results = []; return; }
      this.timer = setTimeout(async () => {
        const ids = this.members.map((m) => m.id);
        try { this.results = (await api.get('users.php', { q })).users.filter((u) => !ids.includes(u.id)); } catch (e) { Setlo.toast(e.message); }
      }, 250);
    },
    async addMember(u) {
      this.search = ''; this.results = [];
      const r = await Setlo.run(this, () => api.post('groups.php', { action: 'add_member', id: this.groupId, user_id: u.id }));
      if (r) this.members = r.members;
    },
    async removeMember(m) {
      if (!(await Setlo.confirm({ title: 'Remove ' + m.name + '?', text: 'Their bills and payments stay as they are.', confirmText: 'Remove', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('groups.php', { action: 'remove_member', id: this.groupId, user_id: m.id }));
      if (r) this.members = r.members;
    },
    async rename() {
      const name = await Setlo.promptText({ title: 'Rename group', value: this.g.name, input: 'text', confirmText: 'Save',
        validate: (v) => (v.trim().length < 2 ? 'Give the group a name.' : /[<>]/.test(v) ? 'No < or > please.' : '') });
      if (name === null) return;
      const r = await Setlo.run(this, () => api.post('groups.php', { action: 'rename', id: this.groupId, name: name.trim() }));
      if (r) await this.load();
    },
    async leave() {
      if (!(await Setlo.confirm({ title: 'Leave ' + this.g.name + '?', text: 'Your bills and payments stay as they are.', confirmText: 'Leave', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('groups.php', { action: 'leave', id: this.groupId }));
      if (r) location.href = r.redirect;
    },
    async remove() {
      if (!(await Setlo.confirm({ title: 'Delete ' + this.g.name + '?', text: 'The group goes for everyone. Its bills, payments and receipts stay.', confirmText: 'Delete', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('groups.php', { action: 'delete', id: this.groupId }));
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
