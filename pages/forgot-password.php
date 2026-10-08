<?php
// "Forgot password?" in three steps: email → 6-digit code from the email → new password. Server side: api/auth.php (forgot, verify_code, reset).
require __DIR__ . '/../includes/bootstrap.php';
if (current_user()) {
    redirect('');
}
$prefill = substr(trim((string) ($_GET['email'] ?? '')), 0, 190);
$title = 'Forgot Password';
$bodyClass = 'landing-body';
$headExtra = ['assets/fonts/caveat-latin.woff2'];
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/auth-landing-top.php';
?>
<div id="app" class="landing-card" v-cloak>
  <div class="flex flex-col items-center text-center">
    <span class="logo-tile h-14 w-14 rounded-[18px] text-[30px]">₱</span>
    <h2 class="mt-3 text-[22px] font-extrabold text-slate-800">{{ heading }}</h2>
    <p class="mt-1 max-w-[290px] text-[14px] leading-snug text-slate-500">{{ intro }}</p>
  </div>

  <!-- 1. Find your account -->
  <form v-if="step === 'email'" class="mt-6 space-y-3" @submit.prevent="sendCode" novalidate>
    <div class="relative">
      <label for="email" class="sr-only">Email</label>
      <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6"/></svg>
      <input id="email" data-field="email" v-model.trim="email" @input="touch('email')" @blur="touch('email')" type="email" autocomplete="email" maxlength="190"
             class="landing-input" :class="{ 'is-invalid': err('email') }" placeholder="you@email.com" required autofocus />
    </div>
    <p v-if="err('email')" class="field-error !mt-1">{{ err('email') }}</p>
    <p v-if="error" class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-[13px] font-semibold text-rose-600" role="alert">{{ error }}</p>
    <button type="submit" class="btn-pill btn-pill-primary !mt-5 !h-[54px]" :disabled="busy">{{ busy ? 'Sending…' : 'Send code' }}</button>
    <p class="pt-2 text-center text-[13px] text-slate-500">Signed up with Google? Just use <b>Continue with Google</b> on the log in page.</p>
  </form>

  <!-- 2. Enter the code -->
  <form v-else-if="step === 'code'" class="mt-6 space-y-3" @submit.prevent="checkCode" novalidate>
    <label for="code" class="sr-only">6-digit code</label>
    <input id="code" ref="code" v-model="code" @input="onCode" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6"
           class="landing-input !text-center !text-[26px] font-extrabold tracking-[.4em]" :class="{ 'is-invalid': error }" placeholder="••••••" />
    <p v-if="error" class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-[13px] font-semibold text-rose-600" role="alert">{{ error }}</p>
    <button type="submit" class="btn-pill btn-pill-primary !mt-5 !h-[54px]" :disabled="busy || code.length !== 6">{{ busy ? 'Checking…' : 'Continue' }}</button>
    <div class="flex items-center justify-between pt-1 text-[13px]">
      <button type="button" class="font-semibold text-slate-500 hover:text-slate-700" @click="back">Wrong email?</button>
      <button type="button" class="font-semibold text-brand-700 hover:underline disabled:cursor-not-allowed disabled:text-slate-400 disabled:no-underline" :disabled="wait > 0 || busy" @click="sendCode">
        {{ wait > 0 ? 'Resend code in ' + wait + 's' : 'Resend code' }}
      </button>
    </div>
    <p class="pt-1 text-center text-[12px] text-slate-400">Can’t find it? Check your spam folder.</p>
  </form>

  <!-- 3. New password -->
  <form v-else class="mt-6 space-y-3" @submit.prevent="savePassword" novalidate>
    <div>
      <div class="relative">
        <label for="pw" class="sr-only">New password</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 018 0v3"/></svg>
        <input id="pw" data-field="password" v-model="f.password" @input="touch('password')" @blur="touch('password')" :class="{ 'is-invalid': err('password') }" maxlength="72" :type="showPw ? 'text' : 'password'" autocomplete="new-password" class="landing-input !pr-12" placeholder="New password (8+ with a letter and a number)" autofocus />
        <button type="button" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-brand-600" :class="{ '!text-brand-600': showPw }" @click="showPw = !showPw" :aria-label="showPw ? 'Hide passwords' : 'Show passwords'">
          <svg width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 010-.64C3.42 7.51 7.36 4.5 12 4.5s8.57 3.01 9.96 7.18a1 1 0 010 .64C20.58 16.49 16.64 19.5 12 19.5s-8.57-3.01-9.96-7.18z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <p v-if="err('password')" class="field-error">{{ err('password') }}</p>
      <p v-else-if="f.password" class="field-ok">✓ Strong enough</p>
    </div>
    <div>
      <div class="relative">
        <label for="pw2" class="sr-only">Confirm new password</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 018 0v3"/></svg>
        <input id="pw2" data-field="password_confirm" v-model="f.password_confirm" @input="touch('password_confirm')" @blur="touch('password_confirm')" :class="{ 'is-invalid': err('password_confirm') }" maxlength="72" :type="showPw ? 'text' : 'password'" autocomplete="new-password" class="landing-input" placeholder="Confirm new password" />
      </div>
      <p v-if="err('password_confirm')" class="field-error">{{ err('password_confirm') }}</p>
    </div>
    <p v-if="error" class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-[13px] font-semibold text-rose-600" role="alert">{{ error }}</p>
    <button type="submit" class="btn-pill btn-pill-primary !mt-5 !h-[54px]" :disabled="busy">{{ busy ? 'Saving…' : 'Save new password' }}</button>
  </form>

  <p class="mt-6 text-center text-[14px] text-slate-600">
    Remembered it? <a href="login" class="font-bold text-brand-700 hover:underline">Log in</a>
  </p>
</div>

<?php require __DIR__ . '/../partials/auth-landing-bottom.php'; ?>
<script>
Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    step: 'email', email: <?= json_encode($prefill) ?>, masked: '', code: '', wait: 0, timer: null,
    f: { password: '', password_confirm: '' }, showPw: false, error: '', busy: false,
  }),
  computed: {
    heading() { return { email: 'Find your account', code: 'Enter the code', password: 'Choose a new password' }[this.step]; },
    intro() {
      return {
        email: 'Enter the email you signed up with and we’ll send you a 6-digit code.',
        code: 'We emailed a 6-digit code to ' + this.masked + '. It works for 10 minutes.',
        password: 'Pick something you haven’t used before. You’ll be signed in right after.',
      }[this.step];
    },
  },
  beforeUnmount() { clearInterval(this.timer); },
  methods: {
    validators() {
      if (this.step === 'password') {
        return { password: V.password(this.f.password), password_confirm: V.match(this.f.password_confirm, this.f.password) };
      }
      return { email: V.email(this.email) };
    },
    countdown(seconds) {
      clearInterval(this.timer);
      this.wait = seconds;
      this.timer = setInterval(() => { if (--this.wait <= 0) clearInterval(this.timer); }, 1000);
    },
    async sendCode() {
      this.error = '';
      if (!this.validateAll()) return;
      this.busy = true;
      try {
        const r = await api.post('auth.php', { action: 'forgot', email: this.email });
        this.masked = r.masked;
        this.code = '';
        this.step = 'code';
        this.countdown(r.resend_after || 60);
        this.$nextTick(() => this.$refs.code && this.$refs.code.focus());
      } catch (e) {
        this.error = e.message;
        if (e.data && e.data.retry_after) this.countdown(e.data.retry_after);
      }
      this.busy = false;
    },
    onCode() {
      this.code = this.code.replace(/\D/g, '').slice(0, 6);
      this.error = '';
      if (this.code.length === 6) this.checkCode();
    },
    async checkCode() {
      if (this.busy || this.code.length !== 6) return;
      this.error = '';
      this.busy = true;
      try {
        await api.post('auth.php', { action: 'verify_code', email: this.email, code: this.code });
        this.step = 'password';
      } catch (e) {
        this.error = e.message;
        if (e.status === 410) { this.code = ''; }
      }
      this.busy = false;
    },
    back() { clearInterval(this.timer); this.wait = 0; this.error = ''; this.code = ''; this.step = 'email'; },
    async savePassword() {
      this.error = '';
      if (!this.validateAll()) return;
      this.busy = true;
      try {
        const r = await api.post('auth.php', { action: 'reset', password: this.f.password, password_confirm: this.f.password_confirm });
        location.href = r.redirect;
      } catch (e) {
        this.error = e.message;
        this.busy = false;
      }
    },
  },
}, '#app');
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
