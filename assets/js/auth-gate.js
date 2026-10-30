/**
 * Early protected-page authorization gate.
 * This file is intentionally loaded synchronously in <head> before any inline
 * page script can initialize protected API requests.
 */
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
  let initialLoadFailed = false;
  let revealTimer = null;

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

  function finishInitialLoad() {
    if (!protectedPage || !authSucceeded || !domReady || initialRequests !== 0) return;
    window.clearTimeout(revealTimer);
    revealTimer = window.setTimeout(function () {
      if (initialRequests !== 0) return;
      if (initialLoadFailed) {
        root.setAttribute('data-load-error', '');
      } else {
        root.setAttribute('data-auth-ready', '');
      }
    }, 0);
  }

  function hydrateIdentity(user) {
    if (!user) return;
    const initials = String(user.name || 'User').split(/\s+/).filter(Boolean)
      .map(word => word[0]).join('').slice(0, 2).toUpperCase();
    document.querySelectorAll('.user-avatar').forEach(element => { element.textContent = initials; });
    document.querySelectorAll('.user-name, [data-user-name], .sidebar-footer .user-info p')
      .forEach(element => { element.textContent = user.name || ''; });
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
        window.location.replace(window.appUrl('/login.html'));
      } else if (!response.ok && !root.hasAttribute('data-auth-ready')) {
        initialLoadFailed = true;
      }
      return response;
    } catch (error) {
      if (!root.hasAttribute('data-auth-ready')) initialLoadFailed = true;
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
  (async function authorize() {
    try {
      const response = await nativeFetch(window.appUrl('/api/auth.php'), { cache: 'no-store' });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.user) {
        window.location.replace(window.appUrl('/login.html'));
        return;
      }
      const requiredRole = root.getAttribute('data-required-role');
      if (requiredRole && payload.user.role !== requiredRole) {
        const homes = {
          student: '/student/dashboard.html',
          employer: '/employer/dashboard.html',
          admin: '/admin/dashboard.html'
        };
        window.location.replace(window.appUrl(homes[payload.user.role] || '/login.html'));
        return;
      }
      sessionStorage.setItem('user', JSON.stringify(payload.user));
      window.__completeInternPilihAuth(payload.user);
    } catch (_) {
      window.location.replace(window.appUrl('/login.html'));
    }
  })();
})();
