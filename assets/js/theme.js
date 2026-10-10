/* Appearance: accent color (<html data-theme>) + light / dark / system (<html class="dark">).
   Loaded as a blocking script in partials/head.php, right after the stylesheet, so the saved choice is applied
   before the first paint. The choice lives in this browser's localStorage. The admin area stays light.
   Accent = one of the presets in src/input.css, or "custom": any #rrggbb, turned into the 50..900 scale below. */
(function (global) {
  'use strict';
  var KEY = 'setlo-theme';
  var STEPS = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];
  var ACCENTS = {
    green:  { label: 'Green',  color: '#0d9488' },
    purple: { label: 'Purple', color: '#7c3aed' },
    blue:   { label: 'Blue',   color: '#2563eb' },
    rose:   { label: 'Rose',   color: '#e11d48' },
    orange: { label: 'Orange', color: '#ea580c' },
  };
  var MODES = ['light', 'dark', 'system'];
  var DEFAULT_CUSTOM = '#7c3aed';
  var root = document.documentElement;
  var dark = global.matchMedia ? global.matchMedia('(prefers-color-scheme: dark)') : null;
  var admin = !!document.querySelector('meta[name="area"][content="admin"]');

  var isHex = function (v) { return typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v); };

  // ---------- custom color -> 50..900 scale ----------
  function hexToHsl(hex) {
    var r = parseInt(hex.slice(1, 3), 16) / 255, g = parseInt(hex.slice(3, 5), 16) / 255, b = parseInt(hex.slice(5, 7), 16) / 255;
    var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2, h = 0, s = 0, d = max - min;
    if (d) {
      s = d / (1 - Math.abs(2 * l - 1));
      h = max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
      h = (h * 60 + 360) % 360;
    }
    return [h, s, l];
  }
  function hslToRgb(h, s, l) {
    var c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs(((h / 60) % 2) - 1)), m = l - c / 2, rgb;
    if (h < 60) rgb = [c, x, 0]; else if (h < 120) rgb = [x, c, 0]; else if (h < 180) rgb = [0, c, x];
    else if (h < 240) rgb = [0, x, c]; else if (h < 300) rgb = [x, 0, c]; else rgb = [c, 0, x];
    return rgb.map(function (v) { return Math.round((v + m) * 255); });
  }
  /** WCAG contrast of white text on this rgb color. */
  function whiteContrast(rgb) {
    var lin = rgb.map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 1.05 / (0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2] + 0.05);
  }
  /**
   * The user's color sits at step 600 (buttons, links), darkened if white text on it would be hard to read.
   * Returns { vars: { 50: "r g b", ... }, hex: the 600 color, adjusted: boolean }.
   */
  function scale(hex) {
    if (!isHex(hex)) hex = ACCENTS.green.color;
    var hsl = hexToHsl(hex), h = hsl[0], s = hsl[1], l = hsl[2], adjusted = false;
    // bold button text only needs the AA "large text" ratio (3:1); 3.5 keeps a margin and leaves the default teal alone
    while (l > 0.12 && whiteContrast(hslToRgb(h, s, l)) < 3.5) { l -= 0.01; adjusted = true; }
    // Steps are blended from the anchor toward near-white (50) and near-black (900), so any pick gets a usable scale
    var toward = { 50: [0.97, 1], 100: [0.94, 0.9], 200: [0.88, 0.75], 300: [0.78, 0.55], 400: [0.66, 0.33], 500: [0.56, 0.14],
                   600: [l, 0], 700: [0.1, 0.22], 800: [0.1, 0.45], 900: [0.08, 0.65] };
    var vars = {};
    STEPS.forEach(function (n) {
      var t = toward[n], ll = l + (t[0] - l) * t[1];
      // very light steps keep less saturation, so a vivid pick doesn't turn neon-pastel
      var ss = n <= 100 ? s * 0.8 : s;
      vars[n] = hslToRgb(h, ss, Math.min(0.97, Math.max(0.05, ll))).join(' ');
    });
    var rgb600 = vars[600].split(' ').map(Number);
    var hex600 = '#' + rgb600.map(function (v) { return ('0' + v.toString(16)).slice(-2); }).join('');
    return { vars: vars, hex: hex600, adjusted: adjusted };
  }

  // ---------- saved choice ----------
  function get() {
    var t = {};
    try { t = JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { /* storage blocked */ }
    return {
      accent: ACCENTS[t.accent] || t.accent === 'custom' ? t.accent : 'green',
      custom: isHex(t.custom) ? t.custom.toLowerCase() : DEFAULT_CUSTOM,
      mode: MODES.indexOf(t.mode) >= 0 ? t.mode : 'system',
    };
  }

  function apply() {
    var t = get();
    var accent = admin ? 'green' : t.accent;
    var isDark = !admin && (t.mode === 'dark' || (t.mode === 'system' && !!dark && dark.matches));
    var themeColor;
    STEPS.forEach(function (n) { root.style.removeProperty('--brand-' + n); });
    if (accent === 'custom') {
      var sc = scale(t.custom);
      STEPS.forEach(function (n) { root.style.setProperty('--brand-' + n, sc.vars[n]); });
      themeColor = sc.hex;
    } else {
      themeColor = ACCENTS[accent].color;
    }
    root.setAttribute('data-theme', accent);
    root.classList.toggle('dark', isDark);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', isDark ? '#0d1117' : themeColor);
  }

  /** patch: { accent, custom, mode }. Picking a custom color also switches the accent to "custom". */
  function set(patch) {
    var t = get();
    if (patch.accent && (ACCENTS[patch.accent] || patch.accent === 'custom')) t.accent = patch.accent;
    if (isHex(patch.custom)) { t.custom = patch.custom.toLowerCase(); t.accent = 'custom'; }
    if (patch.mode && MODES.indexOf(patch.mode) >= 0) t.mode = patch.mode;
    try { localStorage.setItem(KEY, JSON.stringify(t)); } catch (e) { /* storage blocked */ }
    apply();
    return t;
  }

  /** The accent as a CSS color, for canvas drawing and SweetAlert buttons: color(600) -> "rgb(13 148 136)". */
  function color(step) {
    var v = getComputedStyle(root).getPropertyValue('--brand-' + (step || 600)).trim();
    return v ? 'rgb(' + v + ')' : ACCENTS.green.color;
  }

  apply();
  if (dark && dark.addEventListener) dark.addEventListener('change', apply);
  global.addEventListener('storage', function (e) { if (e.key === KEY) apply(); });

  global.SetloTheme = { ACCENTS: ACCENTS, MODES: MODES, DEFAULT_CUSTOM: DEFAULT_CUSTOM, isHex: isHex, scale: scale, get: get, set: set, apply: apply, color: color };
})(window);
