<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Groups';
$nav = 'groups';
$back = 'dashboard.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[21px] font-extrabold tracking-tight">Groups</h1>
        <p class="text-[12px] font-medium text-brand-50/90">People you split with often</p>
      </div>
      <button @click="openNew" class="shrink-0 rounded-full bg-white/15 px-3.5 py-2 text-[13px] font-bold text-white hover:bg-white/25">+ New</button>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-3 px-5 pb-6 pt-4">
    <div v-if="!groups.length" class="tile p-6 text-center">
      <p class="text-sm font-bold text-ink">No groups yet</p>
      <p class="mt-1 text-[12px] text-slate-400">Save your barkada, dorm or team once — then start a bill with everyone in one tap, and see all your group's payments and receipts in one place.</p>
      <button @click="openNew" class="btn btn-primary btn-sm mt-3">+ New group</button>
    </div>
    <a v-for="g in groups" :key="g.id" :href="'group?id=' + g.id" class="tile flex items-center gap-3.5 p-4">
      <div class="flex -space-x-2">
        <avatar v-for="m in g.members.slice(0, 4)" :key="m.id" :user="m" :size="30" ring></avatar>
      </div>
      <div class="min-w-0 flex-1">
        <p class="flex items-center gap-1.5 text-[14px] font-bold text-ink"><span class="truncate">{{ g.name }}</span><span v-if="g.fun_mode" class="fun-badge">🎉 Fun</span></p>
        <p class="mt-0.5 text-[12px] text-slate-400">{{ g.members.length }} people · {{ g.bills }} bill{{ g.bills === 1 ? '' : 's' }}</p>
      </div>
      <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
    </a>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <div v-if="drafting" class="sheet-backdrop" @click.self="drafting = false">
    <form class="sheet" @submit.prevent="create" novalidate>
      <div class="sheet-grip"></div>
      <h2 class="text-[17px] font-extrabold text-ink">New group</h2>
      <label class="field-label mt-4" for="group-name">Name</label>
      <input id="group-name" data-field="name" v-model="f.name" @input="touch('name')" :class="{ 'is-invalid': err('name') }" class="input-soft" maxlength="60" placeholder="e.g. Barkada, Dorm 3B, Office lunch" />
      <p v-if="err('name')" class="field-error">{{ err('name') }}</p>
      <p class="field-label mt-4">People</p>
      <input data-field="members" v-model="search" @input="lookup" class="input-soft" :class="{ 'is-invalid': err('members') }" placeholder="Search by name or email" aria-label="Add people" />
      <p v-if="err('members')" class="field-error">{{ err('members') }}</p>
      <div v-if="results.length" class="tile mt-2 divide-y divide-slate-100 overflow-hidden">
        <button v-for="u in results" :key="u.id" type="button" @click="add(u)" class="flex w-full items-center gap-3 px-3.5 py-2.5 text-left hover:bg-slate-50">
          <avatar :user="u" :size="28"></avatar><span class="flex-1 text-[13px] font-bold">{{ u.name }}</span><span class="text-[12px] font-bold text-brand-700">Add</span>
        </button>
      </div>
      <div class="mt-3 flex flex-wrap gap-2">
        <span class="pill pill-active !px-3 !py-1.5 !text-[12px]">You</span>
        <button v-for="m in f.members" :key="m.id" type="button" @click="f.members = f.members.filter((x) => x.id !== m.id)" class="pill bg-slate-100 !px-3 !py-1.5 !text-[12px] text-slate-600">{{ m.first }} ✕</button>
      </div>
      <div class="mt-5 grid grid-cols-2 gap-2.5">
        <button type="button" @click="drafting = false" class="btn-pill btn-pill-soft">Cancel</button>
        <button class="btn-pill btn-pill-primary" :disabled="busy">Create group</button>
      </div>
    </form>
  </div>
</div>

<script>
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({ loading: true, busy: false, groups: [], drafting: false, search: '', results: [], timer: null, f: { name: '', members: [] } }),
  async mounted() {
    await Setlo.load(this, 'groups.php', null, (r) => { this.groups = r.groups; });
    if (new URLSearchParams(location.search).has('new')) this.openNew();
  },
  methods: {
    openNew() {
      this.f = { name: '', members: [] };
      this.search = '';
      this.results = [];
      Object.keys(this.touched).forEach((k) => { this.touched[k] = false; });
      this.drafting = true;
    },
    lookup() {
      clearTimeout(this.timer);
      const q = this.search.trim();
      if (q.length < 2) { this.results = []; return; }
      this.timer = setTimeout(async () => {
        try {
          const taken = this.f.members.map((m) => m.id);
          this.results = (await api.get('users.php', { q })).users.filter((u) => !taken.includes(u.id));
        } catch (e) { Setlo.toast(e.message); }
      }, 250);
    },
    add(u) { this.f.members.push(u); this.results = []; this.search = ''; this.touch('members'); },
    validators() {
      const name = this.f.name.trim();
      return {
        name: name.length < 2 ? 'Give the group a name.' : /[<>]/.test(name) ? 'No < or > please.' : '',
        members: this.f.members.length ? '' : 'Add at least one other person.',
      };
    },
    async create() {
      if (!this.validateAll(['name', 'members'])) return;
      const r = await Setlo.run(this, () => api.post('groups.php', { action: 'create', name: this.f.name.trim(), member_ids: this.f.members.map((m) => m.id) }));
      if (r) location.href = r.redirect;
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
