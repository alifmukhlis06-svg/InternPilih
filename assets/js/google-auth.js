(function () {
  if (window.InternPilihGoogleAuth) return;

  const script = document.currentScript;
  const scriptPath = script ? new URL(script.src, window.location.href).pathname : '';
  const marker = '/assets/js/google-auth.js';
  const appBase = scriptPath.endsWith(marker) ? scriptPath.slice(0, -marker.length) : '';
  const sdkUrl = 'https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2.57.4/dist/umd/supabase.min.js';
  let configurationPromise;
  let clientPromise;

  function appPath(path) {
    if (typeof window.appUrl === 'function') return window.appUrl(path);
    return (appBase || '') + path;
  }

  function loadSdk() {
    if (window.supabase?.createClient) return Promise.resolve();
    return new Promise((resolve, reject) => {
      const existing = document.querySelector('script[data-internpilih-supabase]');
      if (existing) {
        existing.addEventListener('load', resolve, { once: true });
        existing.addEventListener('error', reject, { once: true });
        return;
      }
      const element = document.createElement('script');
      element.src = sdkUrl;
      element.async = true;
      element.crossOrigin = 'anonymous';
      element.dataset.internpilihSupabase = 'true';
      element.addEventListener('load', resolve, { once: true });
      element.addEventListener('error', reject, { once: true });
      document.head.appendChild(element);
    });
  }

  function getConfiguration() {
    configurationPromise ||= fetch(appPath('/api/auth.php?action=oauth-config'), {
      headers: { Accept: 'application/json' },
      cache: 'no-store'
    }).then(async response => {
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.enabled || !payload.project_url || !payload.publishable_key) {
        throw new Error('Google sign-in is temporarily unavailable.');
      }
      return payload;
    });
    return configurationPromise;
  }

  function getClient() {
    clientPromise ||= Promise.all([loadSdk(), getConfiguration()]).then(([, configuration]) =>
      window.supabase.createClient(configuration.project_url, configuration.publishable_key, {
        auth: {
          flowType: 'pkce',
          persistSession: true,
          autoRefreshToken: true,
          detectSessionInUrl: false
        }
      })
    );
    return clientPromise;
  }

  async function signIn() {
    const client = await getClient();
    const redirectTo = new URL(appPath('/oauth-callback.html'), window.location.origin).href;
    const { error } = await client.auth.signInWithOAuth({
      provider: 'google',
      options: {
        redirectTo,
        queryParams: { prompt: 'select_account' }
      }
    });
    if (error) throw new Error('Google sign-in could not be started. Please try again.');
  }

  async function exchangeCallback() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('error') || params.get('error_code')) {
      throw new Error('Google sign-in was cancelled or could not be completed.');
    }
    const code = params.get('code');
    if (!code) throw new Error('This Google sign-in response is invalid or has expired.');
    const client = await getClient();
    const { data, error } = await client.auth.exchangeCodeForSession(code);
    if (error || !data?.session?.access_token) {
      throw new Error('This Google sign-in response is invalid or has expired.');
    }
    return data.session;
  }

  async function signOut() {
    try {
      const client = await getClient();
      await client.auth.signOut({ scope: 'global' });
      await client.auth.signOut({ scope: 'local' });
    } catch (_) {
      try {
        const client = await getClient();
        await client.auth.signOut({ scope: 'local' });
      } catch (_) {
        // The InternPilih session is still cleared by the caller.
      }
    }
  }

  window.InternPilihGoogleAuth = { appPath, signIn, exchangeCallback, signOut };
})();
