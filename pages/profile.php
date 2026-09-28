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
      <avatar :user="p" :size="52" ring></avatar>
      <div class="min-w-0">
        <p class="truncate text-[17px] font-extrabold">{{ p.name }}</p>
        <p class="truncate text-[12px] text-brand-50/90">{{ p.email }}</p>
      </div>
    </div>
  </div>

  <spinner v-if="loading"></spinner>
  <div v-else class="flex-1 space-y-5 px-5 pb-6 pt-5">

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
</div>

<script>
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    loading: true, busy: false, p: null, canShare: !!navigator.share,
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
  },
  async mounted() {
    await Setlo.load(this, 'profile.php', null, (r) => { this.set(r.profile); });
  },
  methods: {
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
