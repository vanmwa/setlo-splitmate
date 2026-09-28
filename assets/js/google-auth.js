// "Continue with Google" button for the login / sign-up pages (Google Identity Services).
// With a client ID: Google's official button; its ID token is verified by api/auth.php (action "google").
// Without one: a look-alike button that explains Google sign-in isn't set up yet.
(function (global) {
  const G_LOGO = '<svg class="h-5 w-5" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';

  /**
   * el: container element. opts: { clientId, label, text: 'continue_with' | 'signup_with', remember: () => bool,
   *                                 onSuccess: (response) => void, onError: (message) => void }
   */
  function mount(el, opts) {
    const fallback = (message) => {
      el.innerHTML = '<button type="button" class="google-btn">' + G_LOGO + '<span></span></button>';
      el.querySelector('span').textContent = opts.label;
      el.querySelector('button').addEventListener('click', () => opts.onError(message));
    };

    if (!opts.clientId) {
      fallback('Google sign-in isn’t set up yet — the app manager needs to add a Google Client ID (see DEPLOY.md). Use your email and password for now.');
      return;
    }

    const render = () => {
      global.google.accounts.id.initialize({
        client_id: opts.clientId,
        ux_mode: 'popup',
        // Chrome/Edge show their built-in account chooser (FedCM) instead of a popup window, which could
        // stay open and blank on /gsi/transform. Other browsers still fall back to the popup.
        use_fedcm_for_button: true,
        callback: async (resp) => {
          try {
            const r = await api.post('auth.php', { action: 'google', credential: resp.credential, remember: opts.remember ? opts.remember() : true });
            opts.onSuccess(r);
          } catch (e) {
            opts.onError(e.message);
          }
        },
      });
      el.innerHTML = '';
      global.google.accounts.id.renderButton(el, {
        type: 'standard', theme: 'outline', size: 'large', shape: 'pill', logo_alignment: 'center',
        text: opts.text || 'continue_with', width: Math.min(400, Math.max(240, el.clientWidth)),
      });
    };

    if (global.google && global.google.accounts) { render(); return; }
    fallback('Loading Google sign-in…');
    const s = document.createElement('script');
    s.src = 'https://accounts.google.com/gsi/client';
    s.async = true;
    s.onload = render;
    s.onerror = () => fallback('Couldn’t reach Google. Check your internet connection, or use your email and password.');
    document.head.appendChild(s);
  }

  global.SetloGoogle = { mount };
})(window);
