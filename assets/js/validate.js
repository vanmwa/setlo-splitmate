// Live form validation. The same rules are enforced on the server (includes/validate.php).
//
// Usage in a page:
//   Setlo.mount({ mixins: [Setlo.validation], ...,
//     methods: { validators() { return { email: V.email(this.f.email), ... }; } } })
//   Template:  <input data-field="email" @input="touch('email')" :class="{ 'is-invalid': err('email') }">
//              <p v-if="err('email')" class="field-error">{{ err('email') }}</p>
//   Submit:    if (!this.validateAll()) return;
(function (global) {
  const s = (v) => (v === null || v === undefined ? '' : String(v));

  const V = {
    required: (v, label = 'This field') => (s(v).trim() ? '' : `${label} is required.`),
    minLen: (v, n, label = 'This field') => (s(v).trim().length >= n ? '' : `${label} must be at least ${n} characters.`),
    maxLen: (v, n, label = 'This field') => (s(v).length <= n ? '' : `${label} must be ${n} characters or fewer.`),
    email: (v) => {
      if (!s(v).trim()) return 'Email is required.';
      const t = s(v).trim();
      // Length first, then a pattern without overlapping groups (no catastrophic backtracking).
      return t.length <= 190 && /^[^\s@]+@[^\s@.]+(\.[^\s@.]+)*\.[a-z]{2,}$/i.test(t) ? '' : 'Enter a valid email address (e.g. you@email.com).';
    },
    name: (v, label = 'Name') => {
      const t = s(v).trim();
      if (!t) return `${label} is required.`;
      if (t.length < 2) return `${label} is too short.`;
      if (t.length > 100) return `${label} must be 100 characters or fewer.`;
      return /^[\p{L}][\p{L} .'\-]*$/u.test(t) ? '' : `${label} can only have letters, spaces, periods, apostrophes and hyphens.`;
    },
    billName: (v) => {
      const t = s(v).trim();
      if (!t) return 'Give the bill a name.';
      if (t.length > 120) return 'Bill name must be 120 characters or fewer.';
      return /[<>]/.test(t) ? 'Bill name can’t contain < or >.' : '';
    },
    password: (v) => {
      const p = s(v);
      if (!p) return 'Password is required.';
      if (p.length < 8) return 'Use at least 8 characters.';
      if (p.length > 72) return 'Use 72 characters or fewer.';
      if (!/[A-Za-z]/.test(p) || !/\d/.test(p)) return 'Include at least one letter and one number.';
      return '';
    },
    match: (v, other, msg = "Passwords don't match.") => (s(v) && s(v) === s(other) ? '' : (s(v) ? msg : 'Please re-enter the password.')),
    money: (v, { required = false, max = 1000000, label = 'Amount' } = {}) => {
      const t = s(v).trim();
      if (!t) return required ? `${label} is required.` : '';
      if (!/^\d+(\.\d{1,2})?$/.test(t)) return `${label} must be a number with up to 2 decimals.`;
      if (parseFloat(t) > max) return `${label} can’t be more than ₱${max.toLocaleString('en-PH')}.`;
      return '';
    },
    int: (v, min, max, label = 'Quantity') => {
      const t = s(v).trim();
      if (!/^\d+$/.test(t)) return `${label} must be a whole number.`;
      const n = parseInt(t, 10);
      return n >= min && n <= max ? '' : `${label} must be between ${min} and ${max}.`;
    },
    account: (v) => {
      const t = s(v).trim();
      if (!t) return '';
      if (t.length > 60) return 'Keep it to 60 characters or fewer.';
      return /^[\p{L}\d .·+\-()]+$/u.test(t) ? '' : 'Use letters, numbers, spaces and · . - + ( ) only.';
    },
    gcash: (v) => {
      const t = s(v).trim();
      if (!t) return '';
      if (!/^\d+$/.test(t)) return 'Use numbers only.';
      if (!t.startsWith('09')) return 'GCash number must start with 09.';
      return t.length === 11 ? '' : 'GCash number must be exactly 11 digits.';
    },
    refNo: (v) => {
      const t = s(v).trim();
      if (!t) return '';
      if (t.length > 60) return 'Reference number must be 60 characters or fewer.';
      return /^[A-Za-z0-9 \-]+$/.test(t) ? '' : 'Use letters, numbers, spaces and hyphens only.';
    },
    reason: (v, min = 5, max = 500) => {
      const t = s(v).trim();
      if (t.length < min) return `Please explain in at least ${min} characters.`;
      return t.length <= max ? '' : `Keep it to ${max} characters or fewer.`;
    },
  };

  /** Keep only digits and one decimal point with at most 2 decimals (for peso amounts). */
  function filterMoney(value) {
    let t = s(value).replace(/[^\d.]/g, '');
    const dot = t.indexOf('.');
    if (dot !== -1) t = t.slice(0, dot + 1) + t.slice(dot + 1).replace(/\./g, '').slice(0, 2);
    return t.slice(0, 10);
  }
  const filterInt = (value, maxLen = 3) => s(value).replace(/\D/g, '').slice(0, maxLen);

  /** Vue mixin: touched-state + error lookup + validate-before-submit. Pages implement validators(). */
  const validation = {
    data: () => ({ touched: {}, submitted: false }),
    computed: {
      vErrors() { return typeof this.validators === 'function' ? this.validators() : {}; },
    },
    methods: {
      touch(field) { this.touched[field] = true; },
      err(field) { return this.touched[field] || this.submitted ? this.vErrors[field] || '' : ''; },
      resetValidation() { this.touched = {}; this.submitted = false; },
      /**
       * Returns true when valid; otherwise warns and focuses the first bad field.
       * only: optional list of field names to check (for pages with more than one form).
       */
      validateAll(only = null, message = 'Please fix the highlighted fields.') {
        const keys = Object.keys(this.vErrors).filter((k) => !only || only.includes(k));
        keys.forEach((k) => { this.touched[k] = true; });
        if (!only) this.submitted = true;
        const bad = keys.find((k) => this.vErrors[k]);
        if (!bad) return true;
        this.$nextTick(() => {
          const el = document.querySelector(`[data-field="${bad}"]`);
          if (el) { el.focus(); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
        });
        global.Setlo.toast(this.vErrors[bad] || message, 'warn');
        return false;
      },
      filterMoney, filterInt,
    },
  };

  global.V = V;
  Object.assign(global.Setlo, { validation, filterMoney, filterInt, V });
})(window);
