// Setlo service worker: makes the app installable and keeps the shell usable on a flaky connection.
// Money data (api/) is never cached — balances must always come from the server.
const VERSION = 'setlo-v9';
const SHELL = [
  'offline.html',
  'assets/css/app.css',
  'assets/js/api.js',
  'assets/js/ui.js',
  'assets/js/summary.js',
  'assets/js/validate.js',
  'assets/js/docscan-core.js',
  'assets/js/docscan.js',
  'assets/js/docscan-worker.js',
  'assets/js/receipt.js',
  'assets/js/people.js',
  'assets/icons/icon-192.png',
  'assets/setlo_logo.png',
  'assets/fonts/jakarta-latin.woff2',
  'assets/fonts/jakarta-latin-ext.woff2',
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // Live data and uploads: network only.
  if (url.pathname.includes('/api/') || url.pathname.includes('/uploads/')) return;

  // Pages: always try the network (they carry the session + CSRF token); offline page as a fallback.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match('offline.html')));
    return;
  }

  // Our static files: network first so a new deploy shows on a normal refresh; the saved copy is only for offline.
  if (url.origin === self.location.origin && url.pathname.includes('/assets/')) {
    // Key by path only, so each ?v= deploy replaces the old copy instead of piling up.
    const key = url.origin + url.pathname;
    event.respondWith(
      caches.open(VERSION).then((cache) =>
        fetch(req)
          .then((res) => {
            if (res.ok) { cache.put(key, res.clone()); }
            return res;
          })
          .catch(async () => (await cache.match(key)) || Response.error())
      )
    );
    return;
  }

  // Vue, SweetAlert and the QR library from the CDN: cache after first use.
  if (/cdn\.jsdelivr\.net/.test(url.host)) {
    event.respondWith(
      caches.open(VERSION).then(async (cache) => {
        const cached = await cache.match(req);
        if (cached) return cached;
        const res = await fetch(req);
        if (res.ok) cache.put(req, res.clone()); // CORS responses only, so integrity checks keep working
        return res;
      })
    );
  }
});
