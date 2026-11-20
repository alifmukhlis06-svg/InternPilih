/**
 * Early protected-page authorization gate.
 * This file is intentionally loaded synchronously in <head> before any inline
 * page script can initialize protected API requests.
 */
window.internPilihDisplayName = window.internPilihDisplayName || function (name) {
  const fullName = String(name || '').trim().replace(/\s+/g, ' ');
  const patronymic = fullName.match(/\s+(?:bin|binti)(?=\s|$)/i);
  return patronymic && patronymic.index > 0
    ? fullName.slice(0, patronymic.index).trim()
    : fullName;
};

(function () {
  if (window.__internPilihAuthGateInstalled) return;
  window.__internPilihAuthGateInstalled = true;
  window.__internPilihAuthGuardStarted = true;

  const script = document.currentScript;
  const scriptPath = script ? new URL(script.src, window.location.href).pathname : '';
  const assetMarker = '/assets/js/auth-gate.js';
  let appBase = scriptPath.endsWith(assetMarker) ? scriptPath.slice(0, -assetMarker.length) : '';
  const root = document.documentElement;
  const protectedPage = root.hasAttribute('data-protected');
  const nativeFetch = window.fetch.bind(window);
  let resolveAuth;
  let authSucceeded = !protectedPage;
  let domReady = document.readyState !== 'loading';
  let initialRequests = 0;
  let revealTimer = null;
  let authorizationInFlight = null;

  window.appUrl = function (path) {
    if (!path || !path.startsWith('/')) return path;
    return (appBase || '') + path;
  };

  function resolveApiUrl(input) {
    if (typeof input !== 'string') return input;
    if (input.startsWith('/api/')) return window.appUrl(input);
    if (input.startsWith('../api/')) return window.appUrl('/api/' + input.slice('../api/'.length));
    if (input.startsWith('./api/')) return window.appUrl('/api/' + input.slice('./api/'.length));
    if (input.startsWith('api/')) return window.appUrl('/api/' + input.slice('api/'.length));
    return input;
  }

  function requestPath(input) {
    try {
      const value = typeof input === 'string' ? input : input.url;
      return new URL(value, window.location.href).pathname;
    } catch (_) {
      return '';
    }
  }

  window.authReady = protectedPage
    ? new Promise(resolve => { resolveAuth = resolve; })
    : Promise.resolve(null);
  window.__internPilihNativeFetch = nativeFetch;

  function hideProtectedContent() {
    if (!protectedPage) return;
    window.clearTimeout(revealTimer);
    authSucceeded = false;
    root.removeAttribute('data-auth-ready');
    root.setAttribute('data-auth-checking', '');
  }

  function clearProtectedState(scrubRenderedContent) {
    hideProtectedContent();
    window.internPilihUser = null;
    try {
      sessionStorage.removeItem('user');
      sessionStorage.removeItem('internpilih:access-pending');
    } catch (_) {}
    if (scrubRenderedContent) {
      document.querySelectorAll('.main, .sidebar').forEach(element => element.replaceChildren());
    }
  }

  window.__internPilihClearProtectedState = clearProtectedState;

  function finishInitialLoad() {
    if (!protectedPage || !authSucceeded || !domReady || initialRequests !== 0) return;
    window.clearTimeout(revealTimer);
    revealTimer = window.setTimeout(function () {
      if (initialRequests !== 0) return;
      root.removeAttribute('data-load-error');
      root.removeAttribute('data-auth-checking');
      root.setAttribute('data-auth-ready', '');
    }, 0);
  }

  function hydrateIdentity(user) {
    if (!user) return;
    const displayName = window.internPilihDisplayName(user.name) || 'User';
    const initials = displayName.split(/\s+/).filter(Boolean)
      .map(word => word[0]).join('').slice(0, 2).toUpperCase();
    document.querySelectorAll('.user-avatar').forEach(element => { element.textContent = initials; });
    document.querySelectorAll('.user-name, [data-user-name], .sidebar-footer .user-info p')
      .forEach(element => { element.textContent = displayName; });
    document.querySelectorAll('.user-matric, [data-user-matric], .sidebar-footer .user-info span')
      .forEach(element => {
        element.textContent = user.role === 'admin'
          ? 'Coordinator'
          : (user.role === 'employer' ? 'Employer Account' : (user.matric_id || user.email || ''));
      });
  }

  window.__completeInternPilihAuth = function (user) {
    authSucceeded = true;
    window.internPilihUser = user;
    hydrateIdentity(user);
    resolveAuth?.(user);
    finishInitialLoad();
  };

  function redirectToLogin() {
    clearProtectedState(true);
    window.location.replace(window.appUrl('/login.html'));
  }

  function redirectForRole(role) {
    const homes = {
      student: '/student/dashboard.html',
      employer: '/employer/dashboard.html',
      admin: '/admin/dashboard.html'
    };
    clearProtectedState(true);
    window.location.replace(window.appUrl(homes[role] || '/login.html'));
  }

  function revalidateAuthentication() {
    if (!protectedPage) return Promise.resolve(null);
    hideProtectedContent();
    if (authorizationInFlight) return authorizationInFlight;

    authorizationInFlight = (async function () {
      try {
        const response = await nativeFetch(window.appUrl('/api/auth.php'), {
          cache: 'no-store',
          credentials: 'same-origin'
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload.user) {
          redirectToLogin();
          return null;
        }
        const requiredRole = root.getAttribute('data-required-role');
        if (requiredRole && payload.user.role !== requiredRole) {
          redirectForRole(payload.user.role);
          return null;
        }
        sessionStorage.setItem('user', JSON.stringify(payload.user));
        window.__completeInternPilihAuth(payload.user);
        return payload.user;
      } catch (_) {
        redirectToLogin();
        return null;
      } finally {
        authorizationInFlight = null;
      }
    })();
    return authorizationInFlight;
  }

  window.__internPilihRevalidateAuth = revalidateAuthentication;

  window.fetch = async function (input, init) {
    const resolved = resolveApiUrl(input);
    const path = requestPath(resolved);
    const shouldGate = protectedPage && path.includes('/api/') && !path.endsWith('/api/auth.php');
    if (!shouldGate) return nativeFetch(resolved, init);

    initialRequests++;
    try {
      await window.authReady;
      const response = await nativeFetch(resolved, init);
      if (response.status === 401) {
        redirectToLogin();
      }
      return response;
    } catch (error) {
      throw error;
    } finally {
      initialRequests--;
      finishInitialLoad();
    }
  };

  document.addEventListener('DOMContentLoaded', function () {
    domReady = true;
    hydrateIdentity(window.internPilihUser);
    finishInitialLoad();
  });

  if (!protectedPage) return;
  revalidateAuthentication();
  window.addEventListener('pagehide', function () {
    // Store only the hidden state in browser history/BFCache. A restored page
    // must pass the pageshow revalidation below before it can be revealed.
    hideProtectedContent();
  });
  window.addEventListener('pageshow', function () {
    // pageshow runs for normal navigation and BFCache restoration. Hide first,
    // then prove that the server-side session is still valid before revealing.
    revalidateAuthentication();
  });
})();
