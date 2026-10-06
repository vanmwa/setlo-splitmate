// Setlo API client: JSON fetch wrapper with CSRF header and session-expiry handling.
(function (global) {
  const meta = (name) => document.querySelector('meta[name="' + name + '"]')?.content || '';
  const apiBase = meta('api-base');

  class ApiError extends Error {
    constructor(message, status, data) {
      super(message);
      this.status = status;
      this.data = data || {};
    }
  }

  // Last reply of each GET, kept for this tab only, so a refreshed page can show it at once
  // while fresh data loads (see Setlo.load). Keyed by user; cleared on any change and when signed out.
  const PREFIX = 'setlo:' + (meta('user-id') || '0') + ':';
  const store = {
    get(key) { try { return JSON.parse(sessionStorage.getItem(PREFIX + key)); } catch (e) { return null; } },
    set(key, value) {
      try { sessionStorage.setItem(PREFIX + key, JSON.stringify(value)); }
      catch (e) { store.clear(); } // full or blocked: just skip saving
    },
    clear() {
      try {
        Object.keys(sessionStorage).filter((k) => k.startsWith('setlo:')).forEach((k) => sessionStorage.removeItem(k));
      } catch (e) { /* storage blocked */ }
    },
  };
  if (PREFIX === 'setlo:0:') store.clear(); // signed out (login pages): drop everything

  const withQuery = (path, params) => path + (params ? '?' + new URLSearchParams(params).toString() : '');

  async function request(method, path, data, cache = true) {
    if (method !== 'GET') store.clear(); // something may change: never show old numbers again
    const opts = { method, headers: { Accept: 'application/json' }, credentials: 'same-origin' };
    if (method !== 'GET') {
      opts.headers['X-CSRF-Token'] = meta('csrf-token');
      if (data instanceof FormData) {
        opts.body = data;
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(data || {});
      }
    }

    let res, json;
    try {
      res = await fetch(apiBase + path, opts);
      json = await res.json();
    } catch (e) {
      throw new ApiError('Could not reach the server. Check your connection.', 0);
    }

    if (res.status === 401) {
      location.href = meta('login-page') || 'login';
      throw new ApiError(json.error, 401, json);
    }
    if (!res.ok || !json.ok) {
      throw new ApiError(json.error || 'Request failed.', res.status, json);
    }
    if (method === 'GET' && cache) store.set(path, json);
    // Badges this request just earned (includes/achievements.php): pop up on whatever page made it. The reply is
    // handed back only once they're closed, so a page that redirects or reloads next doesn't cut the pop-up short.
    if (json.achievements?.length && global.Setlo) await global.Setlo.showAchievements(json.achievements);
    return json;
  }

  global.api = {
    ApiError,
    get: (path, params) => request('GET', withQuery(path, params)),
    /** A GET that's repeated every second or two (live game screens): never saved for Setlo.load. */
    poll: (path, params) => request('GET', withQuery(path, params), null, false),
    post: (path, data) => request('POST', path, data),
    /** The last saved reply for this GET (this tab, this user), or null. */
    peek: (path, params) => store.get(withQuery(path, params)),
    /** Update the CSRF token after login/register rotates the session. */
    setCsrf: (token) => {
      const el = document.querySelector('meta[name="csrf-token"]');
      if (el && token) el.content = token;
    },
  };
})(window);
