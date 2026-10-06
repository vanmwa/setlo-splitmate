<?php
require __DIR__ . '/../includes/bootstrap.php';
$user = require_login();
$title = 'Profile';
$nav = 'profile';
$back = 'dashboard.php';
require __DIR__ . '/../partials/head.php';
?>
<div id="app" class="device device-narrow" v-cloak>

  <div class="app-hero px-5 pb-6 pt-8">
    <div class="flex items-center gap-3">
      <?php require __DIR__ . '/../partials/back.php'; ?>
      <h1 class="flex-1 text-[21px] font-extrabold tracking-tight">Profile</h1>
    </div>
    <div v-if="p" class="mt-5 flex items-center gap-3.5">
      <button @click="picking = true" class="relative shrink-0 rounded-full" aria-label="Change profile picture">
        <avatar :user="p" :size="60" ring></avatar>
        <span class="absolute -bottom-0.5 -right-0.5 flex h-6 w-6 items-center justify-center rounded-full bg-white text-brand-700 shadow ring-2 ring-brand-500/30">
          <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8a2 2 0 012-2h1.5l1.2-1.8A1 1 0 019.5 4h5a1 1 0 01.8.4L16.5 6H18a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2V8z"/><circle cx="12" cy="12.5" r="3.2"/></svg>
        </span>
      </button>
      <div class="min-w-0">
        <p class="truncate text-[17px] font-extrabold">{{ p.name }}</p>
        <p class="truncate text-[12px] text-brand-50/90">{{ p.email }}</p>
        <button @click="picking = true" class="mt-0.5 text-[12px] font-bold text-white underline decoration-white/40 underline-offset-2">Change picture</button>
        <status-badge v-if="featuredBadge" :badge="featuredBadge" class="mt-1.5 !bg-white/95"></status-badge>
      </div>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-5 px-5 pb-6 pt-5">

    <!-- Money owed to me, and unfinished installments -->
    <div v-if="money" class="tile p-4">
      <div class="grid grid-cols-2 gap-3">
        <div class="rounded-2xl bg-emerald-50 px-3.5 py-3">
          <p class="text-[11px] font-bold uppercase tracking-wide text-emerald-700">Owed to you</p>
          <p class="mt-0.5 text-[20px] font-extrabold tabular-nums text-emerald-800">{{ peso(money.owed_to_me) }}</p>
          <p class="text-[11.5px] text-emerald-700/80">{{ money.people.length ? 'from ' + money.people.length + (money.people.length > 1 ? ' people' : ' person') : 'nobody owes you' }}</p>
        </div>
        <div class="rounded-2xl bg-slate-50 px-3.5 py-3">
          <p class="text-[11px] font-bold uppercase tracking-wide text-slate-500">You owe</p>
          <p class="mt-0.5 text-[20px] font-extrabold tabular-nums text-ink">{{ peso(money.i_owe) }}</p>
          <a href="my-settlements" class="text-[11.5px] font-semibold text-brand-700">Settle up →</a>
        </div>
      </div>
      <p v-if="money.installments" class="mt-3 rounded-xl bg-amber-50 px-3.5 py-2.5 text-[12.5px] text-amber-800">
        <b>{{ peso(money.installment_owed) }}</b> still unpaid on {{ money.installments }} installment{{ money.installments > 1 ? 's' : '' }} owed to you.
      </p>
      <div v-if="money.people.length" class="mt-3 divide-y divide-slate-100">
        <button v-for="u in money.people" :key="u.id" @click="showPerson(u.id)" class="flex w-full items-center gap-3 py-2.5 text-left">
          <avatar :user="u" :size="32"></avatar>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-[13.5px] font-semibold text-ink">{{ u.name }}</span>
            <span v-if="u.installments" class="text-[11.5px] font-semibold text-amber-700">{{ u.installments }} unfinished installment{{ u.installments > 1 ? 's' : '' }} · {{ peso(u.installment_owed) }}</span>
          </span>
          <span class="text-[14px] font-extrabold tabular-nums text-ink">{{ peso(u.owed) }}</span>
          <svg class="h-4 w-4 text-slate-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>
    </div>

    <!-- Achievements: unlocked badges, each shown or hidden, one featured as the title next to my name -->
    <div v-if="ach" class="tile p-4">
      <div class="flex items-baseline justify-between gap-2">
        <p class="text-[15px] font-extrabold text-ink">Achievements</p>
        <p class="text-[12px] font-semibold text-slate-400">{{ ach.badges.length }} of {{ ach.total }} unlocked</p>
      </div>
      <p v-if="!ach.badges.length" class="mt-2 text-[12.5px] text-slate-400">Settle a bill to start earning badges.</p>
      <template v-else>
        <p class="mt-0.5 text-[12px] text-slate-400">Tap a badge to see it again. ★ makes it the title next to your name; hidden badges only you can see.</p>
        <div class="mt-3 grid grid-cols-2 gap-2">
          <div v-for="a in ach.badges" :key="a.code" class="flex items-center gap-1 rounded-2xl border px-2.5 py-2"
               :class="a.hidden ? 'border-dashed border-slate-200 bg-slate-50 opacity-60' : a.code === ach.featured ? 'border-violet-300 bg-violet-50' : 'border-slate-200 bg-white'">
            <button @click="openBadge(a)" class="flex min-w-0 flex-1 items-center gap-1.5 text-left" :aria-label="'Open ' + a.title">
              <span class="text-[20px] leading-none">{{ a.emoji }}</span>
              <span class="min-w-0 truncate text-[12.5px] font-bold text-ink">{{ a.title }}</span>
            </button>
            <button v-if="!a.hidden" @click="feature(a)" :disabled="busyAch" class="shrink-0 text-[15px] leading-none"
                    :class="a.code === ach.featured ? 'text-violet-600' : 'text-slate-300 hover:text-violet-500'"
                    :aria-label="a.code === ach.featured ? 'Stop using as title' : 'Use as title'" :title="a.code === ach.featured ? 'Your title' : 'Use as title'">★</button>
            <button @click="toggleHidden(a)" :disabled="busyAch" class="shrink-0 p-0.5 text-slate-400 hover:text-ink" :aria-label="a.hidden ? 'Show ' + a.title : 'Hide ' + a.title" :title="a.hidden ? 'Hidden — tap to show' : 'Shown — tap to hide'">
              <svg v-if="a.hidden" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3l18 18M10.6 10.6a2 2 0 002.8 2.8M9.9 5.1A9.8 9.8 0 0112 5c4.5 0 8.3 2.9 9.5 7a10 10 0 01-2.8 4.2M6.6 6.6A10 10 0 002.5 12c1.2 4.1 5 7 9.5 7a9.8 9.8 0 005.4-1.6"/></svg>
              <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.5 12C3.7 7.9 7.5 5 12 5s8.3 2.9 9.5 7c-1.2 4.1-5 7-9.5 7s-8.3-2.9-9.5-7z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
      </template>
    </div>

    <!-- Payment details -->
    <form class="tile space-y-4 p-4" @submit.prevent="saveProfile" novalidate>
      <div>
        <p class="text-[15px] font-extrabold text-ink">How friends pay you</p>
        <p class="mt-0.5 text-[12px] text-slate-400">Shown on your Pay-me page and on the “You owe” card of anyone who owes you.</p>
      </div>
      <div>
        <label class="field-label" for="name">Full Name</label>
        <input id="name" data-field="full_name" v-model="f.full_name" @input="touch('full_name')" @blur="touch('full_name')"
               class="input-soft" :class="{ 'is-invalid': err('full_name') }" maxlength="100" autocomplete="name" />
        <p v-if="err('full_name')" class="field-error">{{ err('full_name') }}</p>
      </div>
      <div>
        <label class="field-label" for="method">Preferred Payment Method</label>
        <select id="method" v-model="f.payment_method" class="input-soft cursor-pointer">
          <option>GCash</option><option>Maya</option><option>Bank Transfer</option><option>Cash</option>
        </select>
      </div>
      <div v-if="f.payment_method !== 'Cash'">
        <label class="field-label" for="account">{{ accountLabel }}</label>
        <input v-if="isGcash" id="account" data-field="payment_account" v-model="f.payment_account" @input="onGcashInput" @blur="touch('payment_account')"
               class="input-soft" :class="{ 'is-invalid': err('payment_account') }" inputmode="numeric" autocomplete="tel-national" placeholder="e.g. 09171234567" />
        <input v-else id="account" data-field="payment_account" v-model="f.payment_account" @input="touch('payment_account')" @blur="touch('payment_account')"
               class="input-soft" :class="{ 'is-invalid': err('payment_account') }" maxlength="60" :placeholder="accountPlaceholder" />
        <p v-if="err('payment_account')" class="field-error">{{ err('payment_account') }}</p>
        <p v-else-if="!f.payment_account.trim()" class="mt-1.5 text-[12px] text-amber-600">Add your number so friends know where to send money.</p>
      </div>
      <button class="btn-pill btn-pill-primary" :disabled="busy">Save details</button>
    </form>

    <!-- Personal Pay-me QR (generated per account) -->
    <div class="tile p-4">
      <p class="text-[15px] font-extrabold text-ink">Your Pay-me QR</p>
      <p class="mt-0.5 text-[12px] text-slate-400">Made for your account automatically. Friends scan it to open your pay page with your {{ p.payment_method }} details and what they owe you.</p>
      <div class="mt-4 flex flex-col items-center gap-4 sm:flex-row">
        <div class="h-40 w-40 shrink-0 rounded-2xl border border-slate-200 bg-white p-2" v-html="qr" role="img" aria-label="Your Pay-me QR code"></div>
        <div class="w-full flex-1 space-y-2">
          <button @click="downloadQr" class="btn btn-primary w-full">Download QR</button>
          <button @click="shareLink" class="btn btn-outline w-full">{{ canShare ? 'Share link' : 'Copy link' }}</button>
          <a :href="payUrl" class="btn btn-ghost w-full">Preview my pay page</a>
          <button @click="rotate" class="w-full text-center text-[12px] font-semibold text-slate-400 hover:text-rose-600" :disabled="busy">Get a new QR (old one stops working)</button>
        </div>
      </div>
    </div>

    <!-- Password -->
    <form class="tile space-y-3 p-4" @submit.prevent="changePassword" novalidate>
      <p class="text-[15px] font-extrabold text-ink">Change password</p>
      <div>
        <input data-field="pw_current" v-model="pw.current" @input="touch('pw_current')" type="password" autocomplete="current-password"
               class="input-soft" :class="{ 'is-invalid': err('pw_current') }" placeholder="Current password" aria-label="Current password" />
        <p v-if="err('pw_current')" class="field-error">{{ err('pw_current') }}</p>
      </div>
      <div>
        <input data-field="pw_new" v-model="pw.new" @input="touch('pw_new')" type="password" autocomplete="new-password"
               class="input-soft" :class="{ 'is-invalid': err('pw_new') }" placeholder="New password" aria-label="New password" />
        <p v-if="err('pw_new')" class="field-error">{{ err('pw_new') }}</p>
        <p v-else-if="pw.new" class="field-ok">✓ Strong enough</p>
        <p v-else class="mt-1.5 text-[12px] text-slate-400">At least 8 characters, with a letter and a number.</p>
      </div>
      <div>
        <input data-field="pw_confirm" v-model="pw.confirm" @input="touch('pw_confirm')" type="password" autocomplete="new-password"
               class="input-soft" :class="{ 'is-invalid': err('pw_confirm') }" placeholder="Confirm new password" aria-label="Confirm new password" />
        <p v-if="err('pw_confirm')" class="field-error">{{ err('pw_confirm') }}</p>
      </div>
      <button class="btn-pill btn-pill-soft" :disabled="busy">Update password</button>
    </form>

  </div>

  <?php require __DIR__ . '/../partials/nav.php'; ?>

  <!-- Profile picture: a preset, a gallery photo, or initials (same picker as the first-run page) -->
  <div v-if="picking && p" class="sheet-backdrop" @click.self="picking = false">
    <div class="sheet" role="dialog" aria-labelledby="pic-title">
      <div class="sheet-grip"></div>
      <div class="mb-4 flex items-center justify-between">
        <h2 id="pic-title" class="text-[18px] font-extrabold text-ink">Profile picture</h2>
        <button @click="picking = false" class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-slate-500" aria-label="Close">✕</button>
      </div>
      <div class="mb-4 flex justify-center"><avatar :user="p" :size="88"></avatar></div>
      <avatar-picker :profile="p" :presets="presets" @saved="onPicture"></avatar-picker>
      <button @click="picking = false" class="btn-pill btn-pill-primary mt-5">Done</button>
    </div>
  </div>
</div>

<script>
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    loading: true, busy: false, busyAch: false, p: null, money: null, ach: null, canShare: !!navigator.share,
    presets: [], picking: false,
    f: { full_name: '', payment_method: 'GCash', payment_account: '' },
    pw: { current: '', new: '', confirm: '' }, serverErrors: {},
  }),
  computed: {
    isGcash() { return this.f.payment_method === 'GCash'; },
    accountLabel() {
      if (this.isGcash) return 'GCash number';
      return this.f.payment_method === 'Bank Transfer' ? 'Bank & account number' : this.f.payment_method + ' number / name';
    },
    accountPlaceholder() { return this.f.payment_method === 'Bank Transfer' ? 'e.g. BPI 1234-5678-90 · Juan Dela Cruz' : 'e.g. 0917 123 4567 · Juan D.'; },
    payUrl() { return this.p ? Setlo.payLink(this.p.pay_code) : ''; },
    qr() { return this.p ? Setlo.qrSvg(this.payUrl) : ''; },
    featuredBadge() {
      if (!this.ach) return this.p?.badge || null;
      const a = this.ach.badges.find((b) => b.code === this.ach.featured);
      return a ? { emoji: a.emoji, title: a.title } : null;
    },
  },
  async mounted() {
    await Setlo.load(this, 'profile.php', null, (r) => { this.set(r.profile); this.money = r.money || null; this.presets = r.presets || []; });
    Setlo.load(this, 'achievements.php', null, (r) => { this.ach = r; }, 'busyAch');
  },
  methods: {
    openBadge(a) { Setlo.showAchievement(a); },
    async toggleHidden(a) {
      const r = await Setlo.run(this, () => api.post('achievements.php', { action: 'hide', code: a.code, hidden: !a.hidden }), 'busyAch');
      if (r) this.ach = r;
    },
    async feature(a) {
      const r = await Setlo.run(this, () => api.post('achievements.php', { action: 'feature', code: a.code === this.ach.featured ? '' : a.code }), 'busyAch');
      if (r) { this.ach = r; Setlo.toast(r.featured ? 'Title set' : 'Title removed', 'ok'); }
    },
    showPerson(id) { Setlo.showPerson(id); },
    /** A new picture was saved: show it, but keep any unsaved edits in the details form. */
    onPicture(profile) {
      this.p = profile;
      Setlo.toast('Picture updated', 'ok');
    },
    validators() {
      return {
        full_name: V.name(this.f.full_name, 'Full name'),
        payment_account: this.f.payment_method === 'Cash' ? '' : (this.isGcash ? V.gcash(this.f.payment_account) : V.account(this.f.payment_account)),
        pw_current: this.serverErrors.current || V.required(this.pw.current, 'Current password'),
        pw_new: this.serverErrors.new || V.password(this.pw.new) || (this.pw.new && this.pw.new === this.pw.current ? 'Choose a password different from your current one.' : ''),
        pw_confirm: V.match(this.pw.confirm, this.pw.new),
      };
    },
    set(p) {
      this.p = p;
      this.f = { full_name: p.name, payment_method: p.payment_method, payment_account: p.payment_account || '' };
    },
    // Digits only, so "0917 123 4567" or a pasted "0917-123-4567" becomes 09171234567.
    onGcashInput() {
      this.f.payment_account = this.f.payment_account.replace(/\D/g, '').slice(0, 11);
      this.touch('payment_account');
    },
    async saveProfile() {
      if (!this.validateAll(['full_name', 'payment_account'])) return;
      const r = await Setlo.run(this, () => api.post('profile.php', { action: 'update', ...this.f, full_name: this.f.full_name.trim() }));
      if (r) { this.set(r.profile); Setlo.toast('Details saved', 'ok'); }
    },
    downloadQr() {
      const a = document.createElement('a');
      a.href = Setlo.qrPng(this.payUrl, 'Pay ' + this.p.first + ' on Setlo');
      a.download = 'setlo-pay-' + this.p.first.toLowerCase() + '.png';
      a.click();
    },
    async shareLink() {
      if (navigator.share) {
        navigator.share({ title: 'Pay ' + this.p.name + ' on Setlo', url: this.payUrl }).catch(() => {});
        return;
      }
      try { await navigator.clipboard.writeText(this.payUrl); Setlo.toast('Link copied', 'ok'); }
      catch (e) { Setlo.showCopy('Your pay link', this.payUrl); }
    },
    async rotate() {
      const ok = await Setlo.confirm({
        title: 'Get a new QR?', danger: true, confirmText: 'Yes, new QR',
        text: 'Your current QR and pay link will stop working. Anyone who saved the old one will need the new one.',
      });
      if (!ok) return;
      const r = await Setlo.run(this, () => api.post('profile.php', { action: 'rotate_pay_code' }));
      if (r) { this.set(r.profile); Setlo.toast('New QR created', 'ok'); }
    },
    async changePassword() {
      this.serverErrors = {};
      if (!this.validateAll(['pw_current', 'pw_new', 'pw_confirm'])) return;
      try {
        this.busy = true;
        await api.post('profile.php', { action: 'password', ...this.pw });
        this.pw = { current: '', new: '', confirm: '' };
        ['pw_current', 'pw_new', 'pw_confirm'].forEach((k) => { this.touched[k] = false; });
        Setlo.alert('Password updated', 'Use your new password next time you sign in.', 'success');
      } catch (e) {
        this.serverErrors = e.data.fields || {};
        Setlo.toast(e.message);
      } finally {
        this.busy = false;
      }
    },
  },
  watch: {
    'pw.current'() { this.serverErrors.current = ''; },
    'pw.new'() { this.serverErrors.new = ''; },
  },
});
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
