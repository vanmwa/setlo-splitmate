<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'My Bills';
$nav = 'bills';
$back = 'dashboard.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <h1 class="text-[21px] font-extrabold tracking-tight">My Bills</h1>
    </div>
    <div class="seg mt-5">
      <button v-for="f in filters" :key="f" :class="{ on: filter === f }" @click="filter = f">{{ f[0].toUpperCase() + f.slice(1) }}</button>
    </div>
  </div>

  <div class="flex-1 px-5 pb-6 pt-4">
    <spinner v-if="loading"></spinner>
    <template v-else>
      <p class="mb-3 text-[12px] font-medium text-slate-500">{{ shown.length }} {{ shown.length === 1 ? 'bill' : 'bills' }} · {{ peso(shownTotal) }} total</p>
      <div v-if="!shown.length" class="tile p-6 text-center">
        <p class="text-sm font-bold text-ink">No bills here yet</p>
        <p class="mt-1 text-[12px] text-slate-400">Tap + to create a bill and scan the receipt.</p>
      </div>
      <div class="space-y-3 lg:grid lg:grid-cols-2 lg:gap-3 lg:space-y-0">
        <a v-for="b in shown" :key="b.id" :href="b.link" class="tile flex items-center gap-3.5 p-4" :class="{ 'tile-muted': b.status === 'closed' }">
          <div class="initials" :class="{ 'initials-muted': b.status === 'closed' }">{{ b.initials }}</div>
          <div class="min-w-0 flex-1">
            <p class="text-[14px] font-bold leading-snug" :class="b.status === 'closed' ? 'text-slate-500' : 'text-ink'">{{ b.name }}</p>
            <p class="mt-0.5 text-[12px] text-slate-400">{{ b.members }} members · {{ fmtDate(b.created_at) }}</p>
            <div class="progress mt-2" :class="{ 'progress-muted': b.status === 'closed' }"><span :style="{ width: b.progress + '%' }"></span></div>
          </div>
          <div class="flex shrink-0 flex-col items-end gap-1.5">
            <span class="text-[14px] font-extrabold" :class="b.status === 'closed' ? 'text-slate-500' : 'text-ink'">{{ pesoShort(b.total) }}</span>
            <status-pill :status="b.status"></status-pill>
          </div>
        </a>
      </div>
    </template>
  </div>

  <div class="pointer-events-none sticky bottom-[78px] z-30 lg:bottom-10 flex h-0 justify-end px-5">
    <button @click="openCreate" class="fab2 pointer-events-auto -translate-y-full" aria-label="Create bill">
      <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m7-7H5"/></svg>
    </button>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <!-- Create Bill bottom sheet -->
  <div v-if="creating" class="sheet-backdrop" @click.self="creating = false">
    <form class="sheet" @submit.prevent="create">
      <div class="sheet-grip"></div>
      <div class="mb-5 flex items-center justify-between">
        <h2 class="text-[18px] font-extrabold text-ink">Create Bill</h2>
        <button type="button" @click="creating = false" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-500" aria-label="Close">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
      </div>
      <div class="space-y-4">
        <div>
          <label class="field-label" for="bill-name">Bill Name</label>
          <input id="bill-name" ref="name" data-field="name" v-model="form.name" @input="touch('name')" @blur="touch('name')" :class="{ 'is-invalid': err('name') }" class="input-soft" placeholder="e.g. Mang Inasal Friday" maxlength="120" />
          <p v-if="err('name')" class="field-error">{{ err('name') }}</p>
        </div>
        <div v-if="groups.length">
          <p class="field-label">Start from a group</p>
          <div class="flex flex-wrap gap-2">
            <button v-for="g in groups" :key="g.id" type="button" @click="useGroup(g)" class="pill !px-3 !py-1.5 !text-[12px]"
              :class="form.group_id === g.id ? 'bg-brand-600 text-white' : 'border border-slate-200 bg-white text-slate-600'">{{ g.name }} · {{ g.members.length }}</button>
          </div>
        </div>
        <div>
          <label class="field-label" for="member-search">Add Members</label>
          <div class="input-wrap">
            <svg class="input-lead h-[18px] w-[18px] text-slate-400" fill="none" stroke="currentColor" stroke-width="1.9" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M20 20l-3.5-3.5"/></svg>
            <input id="member-search" v-model="search" @input="lookup" class="input-soft has-lead" placeholder="Search by name or email" autocomplete="off" />
          </div>
          <div v-if="results.length" class="mt-2 divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200">
            <button v-for="u in results" :key="u.id" type="button" @click="addMember(u)" class="flex w-full items-center gap-3 px-3.5 py-2.5 text-left hover:bg-slate-50">
              <avatar :user="u" :size="30"></avatar>
              <div class="min-w-0 flex-1"><p class="text-[13px] font-bold text-ink">{{ u.name }}</p><p class="truncate text-[11px] text-slate-400">{{ u.email }}</p></div>
              <span class="text-[12px] font-bold text-brand-700">Add</span>
            </button>
          </div>
          <p v-if="!form.members.length && !form.guests.length" class="mt-2 text-[12px] text-slate-400">Friends not on Setlo yet? Share an invite link from the bill page, or add them as a guest below.</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <span class="pill pill-active !px-3 !py-1.5 !text-[12px]">You (Creator)</span>
            <button v-for="m in form.members" :key="m.id" type="button" @click="removeMember(m)" class="pill bg-slate-100 !px-3 !py-1.5 !text-[12px] text-slate-600">{{ m.first }} ✕</button>
            <button v-for="(g, i) in form.guests" :key="'g' + i" type="button" @click="form.guests.splice(i, 1)" class="pill border border-dashed border-slate-300 bg-white !px-3 !py-1.5 !text-[12px] text-slate-600">{{ g }} · guest ✕</button>
          </div>
          <div class="mt-3 flex gap-2">
            <input data-field="guest" v-model="guestName" @input="touch('guest')" @keydown.enter.prevent="addGuest" :class="{ 'is-invalid': guestName && err('guest') }" class="input-soft !h-10 flex-1" maxlength="100" placeholder="Add a guest (no account needed)" aria-label="Guest name" />
            <button type="button" @click="addGuest" class="btn btn-outline btn-sm shrink-0">Add guest</button>
          </div>
          <p v-if="guestName && err('guest')" class="field-error">{{ err('guest') }}</p>
          <p v-if="form.guests.length" class="mt-1.5 text-[11.5px] text-slate-400">You'll mark guests' payments for them.</p>
        </div>
        <div>
          <label class="field-label" for="payer">Who paid the restaurant?</label>
          <select id="payer" v-model.number="form.payer_id" class="input-soft cursor-pointer">
            <option :value="me">You</option>
            <option v-for="m in form.members" :key="m.id" :value="m.id">{{ m.name }}</option>
          </select>
          <p class="mt-1.5 text-[12px] text-slate-400">Everyone else settles with this person.</p>
        </div>
        <button class="btn-pill btn-pill-primary" :disabled="busy">{{ busy ? 'Creating…' : 'Create & Scan Receipt' }}</button>
      </div>
    </form>
  </div>
</div>

<script>
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    me: <?= (int) $user['id'] ?>,
    loading: true, busy: false, bills: [],
    filters: ['all', 'active', 'settling', 'closed'], filter: 'all',
    // ?new=1 opens the Create Bill sheet in the very first paint (no pop-in on refresh)
    creating: new URLSearchParams(location.search).has('new'), search: '', results: [], timer: null,
    form: { name: '', members: [], guests: [], payer_id: <?= (int) $user['id'] ?>, group_id: null },
    groups: [],
    guestName: '',
  }),
  computed: {
    shown() {
      if (this.filter === 'all') return this.bills;
      if (this.filter === 'active') return this.bills.filter((b) => b.status === 'active' || b.status === 'draft');
      return this.bills.filter((b) => b.status === this.filter);
    },
    shownTotal() { return this.shown.reduce((s, b) => s + b.total, 0); },
  },
  async mounted() {
    if (this.creating) this.openCreate();
    await Setlo.load(this, 'bills.php', null, (r) => { this.bills = r.bills; });
  },
  methods: {
    async openCreate() {
      this.creating = true;
      this.$nextTick(() => this.$refs.name && this.$refs.name.focus());
      try { this.groups = (await api.get('groups.php')).groups; } catch (e) { this.groups = []; }
      // From a group page: ?new=1&group=ID
      const g = this.groups.find((x) => x.id === Number(new URLSearchParams(location.search).get('group')));
      if (g && this.form.group_id !== g.id) this.useGroup(g);
    },
    /** Fill the members from a saved group (tap again to undo); the bill shows in the group's trail. */
    useGroup(g) {
      if (this.form.group_id === g.id) {
        this.form.group_id = null;
        this.form.members = [];
        this.form.payer_id = this.me;
        return;
      }
      this.form.group_id = g.id;
      this.form.members = g.members.filter((m) => m.id !== this.me);
      if (!this.form.members.some((m) => m.id === this.form.payer_id)) this.form.payer_id = this.me;
    },
    lookup() {
      clearTimeout(this.timer);
      const q = this.search.trim();
      if (q.length < 2) { this.results = []; return; }
      this.timer = setTimeout(async () => {
        try {
          const taken = this.form.members.map((m) => m.id);
          this.results = (await api.get('users.php', { q })).users.filter((u) => !taken.includes(u.id));
        } catch (e) { Setlo.toast(e.message); }
      }, 250);
    },
    addMember(u) {
      this.form.members.push(u);
      this.search = '';
      this.results = [];
    },
    validators() {
      return {
        name: V.billName(this.form.name),
        guest: V.name(this.guestName, 'Guest name') || (this.form.guests.some((g) => g.toLowerCase() === this.guestName.trim().toLowerCase()) ? 'You already added this guest.' : ''),
      };
    },
    addGuest() {
      this.touch('guest');
      if (this.vErrors.guest) { Setlo.toast(this.vErrors.guest, 'warn'); return; }
      this.form.guests.push(this.guestName.trim());
      this.guestName = '';
      this.touched.guest = false;
    },
    removeMember(m) {
      this.form.members = this.form.members.filter((x) => x.id !== m.id);
      if (this.form.payer_id === m.id) this.form.payer_id = this.me;
    },
    async create() {
      if (!this.validateAll(['name'])) return;
      const r = await Setlo.run(this, () => api.post('bills.php', {
        action: 'create', name: this.form.name.trim(), payer_id: this.form.payer_id, member_ids: this.form.members.map((m) => m.id), guest_names: this.form.guests, group_id: this.form.group_id,
      }));
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
