<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Utang';
$nav = 'utang';
$back = 'dashboard.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-5 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <div class="min-w-0 flex-1">
        <h1 class="text-[21px] font-extrabold tracking-tight">Utang</h1>
        <p class="text-[12px] font-medium text-brand-50/90">Money lent or borrowed outside a bill</p>
      </div>
      <button @click="openNew" class="shrink-0 rounded-full bg-white/15 px-3.5 py-2 text-[13px] font-bold text-white hover:bg-white/25">+ Record</button>
    </div>
    <div class="mt-5 grid grid-cols-2 gap-3">
      <div class="rounded-2xl bg-white/10 px-3.5 py-2.5">
        <p class="text-[10.5px] font-bold uppercase tracking-wide text-brand-50/80">Lent · still out</p>
        <p class="text-[19px] font-extrabold tabular-nums">{{ peso(totals.lent) }}</p>
      </div>
      <div class="rounded-2xl bg-white/10 px-3.5 py-2.5">
        <p class="text-[10.5px] font-bold uppercase tracking-wide text-brand-50/80">Borrowed · to repay</p>
        <p class="text-[19px] font-extrabold tabular-nums">{{ peso(totals.borrowed) }}</p>
      </div>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-5 px-5 pb-6 pt-4">
    <div v-if="!loans.length" class="tile p-6 text-center">
      <p class="text-sm font-bold text-ink">No utang yet</p>
      <p class="mt-1 text-[12px] text-slate-400">Lent a friend money, or borrowed some? Record it so you both have the same record.</p>
      <button @click="openNew" class="btn btn-primary btn-sm mt-3">+ Record utang</button>
    </div>

    <section v-for="sec in sections" :key="sec.key">
      <p class="section-label">{{ sec.title }}</p>
      <div class="space-y-3">
        <div v-for="l in sec.items" :key="l.id" class="tile p-3.5" :class="{ 'tile-muted': l.status === 'closed' }">
          <div class="flex items-start gap-3">
            <button v-if="l.other" @click="showPerson(l.other.id)" class="shrink-0 rounded-full" :aria-label="'About ' + l.other.name"><avatar :user="l.other" :size="36"></avatar></button>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-semibold">{{ l.i_lent ? 'You lent ' + l.other.first : l.other.first + ' lent you' }}</p>
              <p class="truncate text-xs text-slate-400">{{ l.purpose }} · {{ fmtDate(l.created_at) }}</p>
              <span v-if="l.interest_rate" class="pill pill-draft mt-1">{{ l.interest_rate }}% per partial payment</span>
            </div>
            <div class="shrink-0 text-right">
              <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{{ l.status === 'closed' ? 'Paid back' : l.settlement ? 'Left' : 'Amount' }}</p>
              <p class="text-sm font-bold tabular-nums">{{ peso(l.settlement && l.status !== 'closed' ? l.settlement.remaining : l.amount) }}</p>
              <p v-if="l.settlement && l.settlement.paid_amount > 0 && l.status !== 'closed'" class="text-[10.5px] text-slate-400">of {{ peso(l.settlement.amount) }}</p>
            </div>
          </div>
          <div v-if="l.settlement && l.settlement.paid_amount > 0 && l.status !== 'closed'" class="mt-2.5">
            <div class="progress !h-1.5"><span :style="{ width: Math.min(100, (100 * l.settlement.paid_amount) / l.settlement.amount) + '%' }"></span></div>
          </div>

          <div class="mt-3 flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-3">
            <template v-if="l.status === 'draft'">
              <template v-if="l.recorded_by_me">
                <span class="mr-auto text-[11.5px] text-slate-400">Waiting for {{ l.other.first }} to confirm</span>
                <button @click="act(l, 'cancel')" class="btn btn-ghost btn-sm !text-rose-600" :disabled="busy">Cancel</button>
              </template>
              <template v-else>
                <button @click="decline(l)" class="btn btn-danger btn-sm" :disabled="busy">That's not right</button>
                <button @click="act(l, 'confirm')" class="btn btn-accent btn-sm" :disabled="busy">Confirm {{ peso(l.amount) }}</button>
              </template>
            </template>
            <template v-else-if="l.status === 'settling'">
              <a :href="'settlement-audit?id=' + l.settlement.id" class="mr-auto text-[11px] font-semibold text-brand-600">History →</a>
              <a v-if="l.i_lent" href="my-settlements?tab=owed" class="btn btn-accent btn-sm">Record cash</a>
              <a v-else href="my-settlements" class="btn btn-primary btn-sm">Pay back</a>
            </template>
            <a v-else :href="'settlement-audit?id=' + l.settlement.id" class="text-[11px] font-semibold text-brand-600">Paid back ✓ · View history →</a>
          </div>
        </div>
      </div>
    </section>
  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <!-- Record a new utang -->
  <div v-if="drafting" class="sheet-backdrop" @click.self="drafting = false">
    <form class="sheet" @submit.prevent="create" novalidate>
      <div class="sheet-grip"></div>
      <h2 class="text-[17px] font-extrabold text-ink">Record utang</h2>
      <p class="mt-0.5 text-[12px] text-slate-500">It counts once the other person confirms it.</p>

      <div class="mt-4 grid grid-cols-2 gap-1 rounded-2xl border border-slate-200 bg-white p-1 text-[13px] font-bold" role="radiogroup" aria-label="Lent or borrowed">
        <button type="button" role="radio" :aria-checked="f.direction === 'lent'" @click="f.direction = 'lent'" class="rounded-xl py-2" :class="f.direction === 'lent' ? 'bg-brand-600 text-white' : 'text-slate-500'">I lent money</button>
        <button type="button" role="radio" :aria-checked="f.direction === 'borrowed'" @click="f.direction = 'borrowed'" class="rounded-xl py-2" :class="f.direction === 'borrowed' ? 'bg-brand-600 text-white' : 'text-slate-500'">I borrowed money</button>
      </div>

      <p class="field-label mt-4">{{ f.direction === 'lent' ? 'Lent to' : 'Borrowed from' }}</p>
      <div v-if="f.person" class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-3 py-2">
        <avatar :user="f.person" :size="30"></avatar>
        <span class="min-w-0 flex-1 truncate text-[13.5px] font-semibold">{{ f.person.name }}</span>
        <button type="button" @click="f.person = null" class="text-[12px] font-bold text-slate-400">Change</button>
      </div>
      <template v-else>
        <input data-field="person" v-model="search" @input="lookup" class="input-soft" :class="{ 'is-invalid': err('person') }" placeholder="Search by name or email" aria-label="Person" />
        <p v-if="err('person')" class="field-error">{{ err('person') }}</p>
        <div v-if="results.length" class="tile mt-2 divide-y divide-slate-100 overflow-hidden">
          <button v-for="u in results" :key="u.id" type="button" @click="pick(u)" class="flex w-full items-center gap-3 px-3.5 py-2.5 text-left hover:bg-slate-50">
            <avatar :user="u" :size="28"></avatar><span class="flex-1 text-[13px] font-bold">{{ u.name }}</span>
          </button>
        </div>
      </template>

      <label class="field-label mt-4" for="loan-amount">Amount</label>
      <div class="flex items-center gap-2">
        <span class="text-slate-400">₱</span>
        <input id="loan-amount" data-field="amount" :value="f.amount" @input="f.amount = filterMoney($event.target.value); $event.target.value = f.amount; touch('amount')" :class="{ 'is-invalid': err('amount') }" class="input-soft flex-1" inputmode="decimal" placeholder="0.00" />
      </div>
      <p v-if="err('amount')" class="field-error">{{ err('amount') }}</p>

      <label class="field-label mt-4" for="loan-purpose">What it was for</label>
      <input id="loan-purpose" data-field="purpose" v-model="f.purpose" @input="touch('purpose')" :class="{ 'is-invalid': err('purpose') }" class="input-soft" maxlength="120" placeholder="e.g. Pamasahe, tuition, lunch money" />
      <p v-if="err('purpose')" class="field-error">{{ err('purpose') }}</p>

      <template v-if="f.direction === 'lent'">
        <label class="mt-4 flex items-start gap-2.5 rounded-2xl bg-slate-50 px-3.5 py-3 text-[12.5px] leading-snug text-slate-600">
          <input type="checkbox" v-model="f.interestOn" class="mt-0.5 h-4 w-4 shrink-0 accent-brand-600" />
          <span><b class="text-ink">Installments with interest</b><br />Each partial payment adds this % of what's still left.</span>
        </label>
        <div v-if="f.interestOn" class="mt-2 flex items-center gap-2 px-1 text-[13px]">
          <span class="flex-1 text-slate-500">Interest after each partial payment</span>
          <input v-model="f.rate" data-field="interest" @input="touch('interest')" inputmode="decimal" class="row-in !h-9 w-20 text-right" :class="{ 'is-invalid': err('interest') }" aria-label="Interest rate in percent" />
          <span class="text-slate-400">%</span>
        </div>
        <p v-if="f.interestOn && err('interest')" class="field-error px-1">{{ err('interest') }}</p>
      </template>

      <div class="mt-5 grid grid-cols-2 gap-2.5">
        <button type="button" @click="drafting = false" class="btn-pill btn-pill-soft">Cancel</button>
        <button class="btn-pill btn-pill-primary" :disabled="busy">Send to {{ f.person ? f.person.first : 'them' }}</button>
      </div>
    </form>
  </div>
</div>

<script>
const money = (v) => Math.round((parseFloat(String(v).replace(/,/g, '')) || 0) * 100) / 100;
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    loading: true, busy: false, loans: [],
    drafting: false, search: '', results: [], timer: null,
    f: { direction: 'lent', person: null, amount: '', purpose: '', interestOn: false, rate: '5' },
  }),
  computed: {
    sections() {
      const waiting = this.loans.filter((l) => l.status === 'draft' && !l.recorded_by_me);
      const rest = this.loans.filter((l) => !waiting.includes(l));
      return [
        { key: 'waiting', title: 'WAITING FOR YOU TO CONFIRM', items: waiting },
        { key: 'lent', title: 'YOU LENT', items: rest.filter((l) => l.i_lent) },
        { key: 'borrowed', title: 'YOU BORROWED', items: rest.filter((l) => !l.i_lent) },
      ].filter((s) => s.items.length);
    },
    totals() {
      const left = (l) => (l.status === 'settling' && l.settlement ? l.settlement.remaining : 0);
      return {
        lent: this.loans.filter((l) => l.i_lent).reduce((s, l) => s + left(l), 0),
        borrowed: this.loans.filter((l) => !l.i_lent).reduce((s, l) => s + left(l), 0),
      };
    },
  },
  async mounted() {
    await Setlo.load(this, 'loans.php', null, (r) => { this.loans = r.loans; });
  },
  methods: {
    showPerson(id) { Setlo.showPerson(id); },
    async load() { this.loans = (await api.get('loans.php')).loans; },
    openNew() {
      this.f = { direction: 'lent', person: null, amount: '', purpose: '', interestOn: false, rate: '5' };
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
        try { this.results = (await api.get('users.php', { q })).users; } catch (e) { Setlo.toast(e.message); }
      }, 250);
    },
    pick(u) { this.f.person = u; this.results = []; this.search = ''; },
    validators() {
      const r = parseFloat(this.f.rate);
      return {
        person: this.f.person ? '' : 'Choose who it was with.',
        amount: V.money(this.f.amount, { required: true, label: 'Amount' }) || (money(this.f.amount) < 1 ? 'Enter at least ₱1.00.' : ''),
        purpose: V.required(this.f.purpose, 'What it was for') || V.maxLen(this.f.purpose, 120, 'What it was for'),
        interest: this.f.direction === 'lent' && this.f.interestOn && !(r > 0 && r <= 20) ? 'Enter a rate above 0% and up to 20%.' : '',
      };
    },
    async create() {
      if (!this.validateAll(['person', 'amount', 'purpose', 'interest'])) return;
      const f = this.f;
      const r = await Setlo.run(this, () => api.post('loans.php', {
        action: 'create', person_id: f.person.id, direction: f.direction, amount: money(f.amount), purpose: f.purpose.trim(),
        interest_rate: f.direction === 'lent' && f.interestOn ? parseFloat(f.rate) : null,
      }));
      if (!r) return;
      this.drafting = false;
      await this.load();
      Setlo.toast('Sent to ' + f.person.first + ' to confirm', 'ok');
    },
    async act(l, action) {
      if (action === 'cancel' && !(await Setlo.confirm({ title: 'Cancel this utang?', text: 'It hasn’t been confirmed yet, so it’s simply removed.', confirmText: 'Cancel it', danger: true }))) return;
      const r = await Setlo.run(this, () => api.post('loans.php', { action, bill_id: l.id }));
      await this.load();
      if (r) Setlo.toast(action === 'confirm' ? 'Confirmed ✓' : 'Cancelled', 'ok');
    },
    async decline(l) {
      const reason = await Setlo.promptText({
        title: 'Decline this utang?', text: `${l.other.first} will be told it isn't right. Say why (optional).`,
        placeholder: 'e.g. It was ₱300, not ₱500.', confirmText: 'Decline', input: 'text',
      });
      if (reason === undefined || reason === null || reason === false) return;
      const r = await Setlo.run(this, () => api.post('loans.php', { action: 'decline', bill_id: l.id, reason: String(reason).slice(0, 200) }));
      await this.load();
      if (r) Setlo.toast('Declined', 'ok');
    },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
