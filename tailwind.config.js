/** @type {import('tailwindcss').Config} */
module.exports = {
  darkMode: ['class', 'html.dark'],
  content: [
    './*.php',
    './pages/**/*.php',
    './partials/**/*.php',
    './assets/js/**/*.js',
  ],
  // Status classes are built from API values at runtime (e.g. 'pill-' + status), so keep them.
  safelist: [
    { pattern: /^pill-(active|draft|settling|closed|pending|awaiting|settled|disputed|unassigned|suspended)$/ },
  ],
  theme: {
    extend: {
      colors: {
        brand: Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900].map((n) => [n, `rgb(var(--brand-${n}) / <alpha-value>)`])),
        accent: { 500: '#22c55e', 600: '#16a34a' },
        ink: 'rgb(var(--ink) / <alpha-value>)',
        night: '#0f172a',
        surface: 'rgb(var(--surface) / <alpha-value>)',
        slate: Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900].map((n) => [n, `rgb(var(--slate-${n}) / <alpha-value>)`])),
        canvas: 'rgb(var(--canvas) / <alpha-value>)',
      },
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
      },
      keyframes: {
        vfscan: { '0%, 100%': { top: '40px' }, '50%': { top: 'calc(100% - 60px)' } },
        nudge: { '0%, 100%': { transform: 'translateX(0)' }, '25%': { transform: 'translateX(-5px)' }, '75%': { transform: 'translateX(5px)' } },
      },
      animation: {
        vfscan: 'vfscan 1.8s ease-in-out infinite',
        nudge: 'nudge .3s ease-in-out 2',
      },
    },
  },
  plugins: [],
};
