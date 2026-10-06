<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/google.php';
$next = safe_next((string) ($_GET['next'] ?? ''));
if (current_user()) {
    redirect($next ? 'pages/' . $next : '');
}
$title = 'Create Account';
$bodyClass = 'landing-body';
$headExtra = ['assets/fonts/caveat-latin.woff2', 'assets/js/google-auth.js'];
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/auth-landing-top.php';
?>
<div id="app" class="landing-card" v-cloak>
  <div class="flex flex-col items-center text-center">
    <span class="logo-tile h-14 w-14 rounded-[18px] text-[30px]">₱</span>
    <h2 class="mt-3 text-[22px] font-extrabold text-slate-800">Create your account</h2>
    <p class="mt-1 max-w-[280px] text-[14px] leading-snug text-slate-500">Start splitting bills with your barkada in seconds.</p>
  </div>

  <div ref="google" class="mt-6 flex min-h-[52px] justify-center"></div>
  <div class="my-5 flex items-center gap-3 text-[12px] text-slate-400"><span class="h-px flex-1 bg-slate-200"></span>or sign up with email<span class="h-px flex-1 bg-slate-200"></span></div>

  <form class="space-y-3" @submit.prevent="submit" novalidate>
    <div>
      <div class="relative">
        <label for="name" class="sr-only">Full name</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/></svg>
        <input id="name" data-field="full_name" v-model="f.full_name" @input="touch('full_name')" @blur="touch('full_name')" :class="{ 'is-invalid': err('full_name') }" autocomplete="name" class="landing-input" placeholder="Full name (e.g. Juan Dela Cruz)" maxlength="100" />
      </div>
      <p v-if="err('full_name')" class="field-error">{{ err('full_name') }}</p>
    </div>

    <div>
      <div class="relative">
        <label for="email" class="sr-only">Email</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6"/></svg>
        <input id="email" data-field="email" v-model.trim="f.email" @input="touch('email')" @blur="touch('email')" :class="{ 'is-invalid': err('email') }" maxlength="190" type="email" autocomplete="email" class="landing-input" placeholder="you@email.com" />
      </div>
      <p v-if="err('email')" class="field-error">{{ err('email') }}</p>
    </div>

    <div>
      <div class="relative">
        <label for="pw" class="sr-only">Password</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 018 0v3"/></svg>
        <input id="pw" data-field="password" v-model="f.password" @input="touch('password')" @blur="touch('password')" :class="{ 'is-invalid': err('password') }" maxlength="72" :type="showPw ? 'text' : 'password'" autocomplete="new-password" class="landing-input !pr-12" placeholder="Password (8+ with a letter and a number)" />
        <button type="button" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-brand-600" :class="{ '!text-brand-600': showPw }" @click="showPw = !showPw" :aria-label="showPw ? 'Hide passwords' : 'Show passwords'">
          <svg width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 010-.64C3.42 7.51 7.36 4.5 12 4.5s8.57 3.01 9.96 7.18a1 1 0 010 .64C20.58 16.49 16.64 19.5 12 19.5s-8.57-3.01-9.96-7.18z"/><circle cx="12" cy="12" r="3"/></svg>
        </button>
      </div>
      <p v-if="err('password')" class="field-error">{{ err('password') }}</p>
      <p v-else-if="f.password" class="field-ok">✓ Strong enough</p>
    </div>

    <div>
      <div class="relative">
        <label for="pw2" class="sr-only">Confirm password</label>
        <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 018 0v3"/></svg>
        <input id="pw2" data-field="password_confirm" v-model="f.password_confirm" @input="touch('password_confirm')" @blur="touch('password_confirm')" :class="{ 'is-invalid': err('password_confirm') }" maxlength="72" :type="showPw ? 'text' : 'password'" autocomplete="new-password" class="landing-input" placeholder="Confirm password" />
      </div>
      <p v-if="err('password_confirm')" class="field-error">{{ err('password_confirm') }}</p>
    </div>

    <label class="flex cursor-pointer items-start gap-2.5 pt-1 text-[13px] text-slate-500">
      <input data-field="agree" v-model="f.agree" @change="touch('agree')" type="checkbox" class="mt-0.5 h-4 w-4 accent-brand-600" />
      <span>I agree to the <b class="text-brand-700">Terms &amp; Conditions</b> and <b class="text-brand-700">Privacy Policy</b></span>
    </label>
    <p v-if="err('agree')" class="field-error !mt-1">{{ err('agree') }}</p>
    <p v-if="error" class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-[13px] font-semibold text-rose-600" role="alert">{{ error }}</p>

    <button type="submit" class="btn-pill btn-pill-primary !mt-5 !h-[54px]" :disabled="busy">
      {{ busy ? 'Creating account…' : 'Create Account' }}
      <svg v-if="!busy" width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6l6 6-6 6"/></svg>
    </button>
  </form>

  <p class="mt-6 text-center text-[14px] text-slate-600">
    Already have an account? <a href="login<?= $next ? '?next=' . h(urlencode($next)) : '' ?>" class="font-bold text-brand-700 hover:underline">Log in</a>
  </p>
</div>

<?php require __DIR__ . '/../partials/auth-landing-bottom.php'; ?>
<script>
const NEXT = <?= json_encode($next) ?>;
const GOOGLE_CLIENT_ID = <?= json_encode(google_client_id()) ?>;

Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({
    f: { full_name: '', email: '', password: '', password_confirm: '', agree: false },
    serverErrors: {}, error: '', showPw: false, busy: false,
  }),
  mounted() {
    SetloGoogle.mount(this.$refs.google, {
      clientId: GOOGLE_CLIENT_ID,
      label: 'Sign up with Google',
      text: 'signup_with',
      onSuccess: (r) => { location.href = Setlo.afterSignUp(r, NEXT); },
      onError: (message) => { this.error = message; },
    });
  },
  watch: { 'f.email'() { this.serverErrors.email = ''; } },
  methods: {
    validators() {
      return {
        full_name: V.name(this.f.full_name, 'Full name'),
        email: this.serverErrors.email || V.email(this.f.email),
        password: V.password(this.f.password),
        password_confirm: V.match(this.f.password_confirm, this.f.password),
        agree: this.f.agree ? '' : 'Please accept the terms to continue.',
      };
    },
    async submit() {
      this.error = '';
      if (!this.validateAll()) return;

      this.busy = true;
      try {
        const r = await api.post('auth.php', { action: 'register', ...this.f, full_name: this.f.full_name.trim() });
        location.href = Setlo.afterSignUp(r, NEXT);
      } catch (e) {
        this.serverErrors = e.data.fields || {};
        if (!e.data.fields) this.error = e.message;
        else Setlo.toast(Object.values(e.data.fields)[0], 'warn');
        this.busy = false;
      }
    },
  },
}, '#app');
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
