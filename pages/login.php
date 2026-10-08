<?php
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/google.php';
$next = safe_next((string) ($_GET['next'] ?? ''));
if (current_user()) {
    redirect($next ? 'pages/' . $next : '');
}
$title = 'Log In';
$bodyClass = 'landing-body';
$headExtra = ['assets/fonts/caveat-latin.woff2', 'assets/js/google-auth.js'];
require __DIR__ . '/../partials/head.php';
require __DIR__ . '/../partials/auth-landing-top.php';
?>
<div id="app" class="landing-card" v-cloak>
  <div class="flex flex-col items-center text-center">
    <span class="logo-tile h-16 w-16 rounded-[20px] text-[34px]">₱</span>
    <p class="mt-2 text-[34px] font-extrabold leading-none tracking-tight text-brand-600">setlo</p>
    <h2 class="mt-4 text-[22px] font-extrabold text-slate-800">Welcome back!</h2>
    <p class="mt-1 max-w-[260px] text-[14px] leading-snug text-slate-500">Log in to your account and manage your shared expenses.</p>
  </div>

  <form class="mt-7 space-y-3.5" @submit.prevent="submit" novalidate>
    <div class="relative">
      <label for="email" class="sr-only">Email</label>
      <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6"/></svg>
      <input id="email" data-field="email" v-model.trim="email" @input="touch('email')" @blur="touch('email')" type="email" autocomplete="email" maxlength="190"
             class="landing-input" :class="{ 'is-invalid': err('email') }" placeholder="you@email.com" required autofocus />
    </div>
    <p v-if="err('email')" class="field-error !mt-1">{{ err('email') }}</p>
    <div class="relative">
      <label for="pw" class="sr-only">Password</label>
      <svg width="20" height="20" class="landing-input-icon" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path stroke-linecap="round" d="M8 11V8a4 4 0 018 0v3"/></svg>
      <input id="pw" data-field="password" v-model="password" @input="touch('password')" @blur="touch('password')" :type="showPw ? 'text' : 'password'" autocomplete="current-password" maxlength="72"
             class="landing-input !pr-12" :class="{ 'is-invalid': err('password') }" placeholder="Enter your password" required />
      <button type="button" class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-500 hover:text-brand-600" :class="{ '!text-brand-600': showPw }" @click="showPw = !showPw" :aria-label="showPw ? 'Hide password' : 'Show password'">
        <svg width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2.04 12.32a1 1 0 010-.64C3.42 7.51 7.36 4.5 12 4.5s8.57 3.01 9.96 7.18a1 1 0 010 .64C20.58 16.49 16.64 19.5 12 19.5s-8.57-3.01-9.96-7.18z"/><circle cx="12" cy="12" r="3"/></svg>
      </button>
    </div>

    <p v-if="err('password')" class="field-error !mt-1">{{ err('password') }}</p>
    <p v-if="error" class="rounded-xl bg-rose-50 px-3.5 py-2.5 text-[13px] font-semibold text-rose-600" role="alert">{{ error }}</p>

    <button type="submit" class="btn-pill btn-pill-primary !mt-5 !h-[54px]" :disabled="busy">
      {{ busy ? 'Logging in…' : 'Log In' }}
      <svg v-if="!busy" width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14m-6-6l6 6-6 6"/></svg>
    </button>

    <div class="flex items-center justify-between pt-1 text-[13px]">
      <label class="flex cursor-pointer items-center gap-2 text-slate-600">
        <input v-model="remember" type="checkbox" class="h-4 w-4 rounded accent-brand-600" /> Remember me
      </label>
      <a :href="'forgot-password' + (email ? '?email=' + encodeURIComponent(email) : '')" class="font-semibold text-brand-700 hover:underline">Forgot password?</a>
    </div>
  </form>

  <div class="my-6 flex items-center gap-3 text-[12px] text-slate-400"><span class="h-px flex-1 bg-slate-200"></span>or<span class="h-px flex-1 bg-slate-200"></span></div>

  <div ref="google" class="flex min-h-[52px] justify-center"></div>

  <p class="mt-6 text-center text-[14px] text-slate-600">
    Don't have an account? <a href="register<?= $next ? '?next=' . h(urlencode($next)) : '' ?>" class="font-bold text-brand-700 hover:underline">Sign up</a>
  </p>
</div>

<?php require __DIR__ . '/../partials/auth-landing-bottom.php'; ?>
<script>
const NEXT = <?= json_encode($next) ?>;
const GOOGLE_CLIENT_ID = <?= json_encode(google_client_id()) ?>;
const afterLogin = (r) => { location.href = NEXT && r.role !== 'admin' ? NEXT : r.redirect; };

Setlo.mount({
  mixins: [Setlo.validation],
  data: () => ({ email: '', password: '', showPw: false, remember: true, busy: false, error: '' }),
  mounted() {
    SetloGoogle.mount(this.$refs.google, {
      clientId: GOOGLE_CLIENT_ID,
      label: 'Continue with Google',
      text: 'continue_with',
      remember: () => this.remember,
      onSuccess: (r) => { location.href = Setlo.afterSignUp(r, NEXT); },
      onError: (message) => { this.error = message; },
    });
  },
  methods: {
    validators() {
      return { email: V.email(this.email), password: V.required(this.password, 'Password') };
    },
    async submit() {
      this.error = '';
      if (!this.validateAll()) return;
      this.busy = true;
      try {
        afterLogin(await api.post('auth.php', { action: 'login', email: this.email, password: this.password, remember: this.remember }));
      } catch (e) {
        this.error = e.message;
        if (e.status === 429) Setlo.alert('Too many attempts', e.message, 'warning');
        this.busy = false;
      }
    },
  },
}, '#app');
</script>
<?php require __DIR__ . '/../partials/foot.php'; ?>
