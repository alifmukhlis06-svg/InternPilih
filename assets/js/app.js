/**
 * Shared browser helpers.
 */

// Resolve app-root URLs and hold protected API work until authorization has
// completed. Protected HTML is hidden by CSS until its initial live data has
// loaded, so static fallback markup can never flash another account's data.
(function () {
  if (window.__internPilihAuthGateInstalled) return;
  window.__internPilihAuthGateInstalled = true;
  const script = document.currentScript;
  const scriptPath = script ? new URL(script.src, window.location.href).pathname : '';
  const assetMarker = '/assets/js/app.js';
  let appBase = scriptPath.endsWith(assetMarker) ? scriptPath.slice(0, -assetMarker.length) : '';
  const fileMode = window.location.protocol === 'file:';
  const projectMarker = '/InternPilih';

  if (fileMode) {
    const markerIndex = appBase.replace(/\\/g, '/').lastIndexOf(projectMarker);
    appBase = markerIndex >= 0 ? projectMarker : '';
  }

  window.appUrl = function (path) {
    if (!path || !path.startsWith('/')) return path;
    const base = (appBase || '') + path;
    return fileMode ? 'http://localhost' + base : base;
  };

  function apiRequestUrl(input) {
    if (typeof input !== 'string') return input;
    if (input.startsWith('/api/')) return window.appUrl(input);
    if (input.startsWith('../api/')) return window.appUrl('/api/' + input.slice('../api/'.length));
    if (input.startsWith('./api/')) return window.appUrl('/api/' + input.slice('./api/'.length));
    if (input.startsWith('api/')) return window.appUrl('/api/' + input.slice('api/'.length));
    return input;
  }

  const root = document.documentElement;
  const protectedPage = root.hasAttribute('data-protected');
  const nativeFetch = window.fetch.bind(window);
  let resolveAuth;
  let authSucceeded = !protectedPage;
  let domReady = document.readyState !== 'loading';
  let initialRequests = 0;
  let initialLoadFailed = false;
  let revealTimer = null;

  window.authReady = protectedPage
    ? new Promise(resolve => { resolveAuth = resolve; })
    : Promise.resolve(null);
  window.__internPilihNativeFetch = nativeFetch;

  function isApiRequest(input) {
    try {
      const value = typeof input === 'string' ? input : input.url;
      return new URL(value, window.location.href).pathname.includes('/api/');
    } catch (_) {
      return false;
    }
  }

  function isAuthRequest(input) {
    try {
      const value = typeof input === 'string' ? input : input.url;
      return new URL(value, window.location.href).pathname.endsWith('/api/auth.php');
    } catch (_) {
      return false;
    }
  }

  function finishInitialLoad() {
    if (!protectedPage || !authSucceeded || !domReady || initialRequests !== 0) return;
    window.clearTimeout(revealTimer);
    revealTimer = window.setTimeout(function () {
      if (initialRequests !== 0) return;
      if (initialLoadFailed) {
        root.setAttribute('data-load-error', '');
        return;
      }
      root.setAttribute('data-auth-ready', '');
    }, 0);
  }

  window.__completeInternPilihAuth = function (user) {
    authSucceeded = true;
    resolveAuth?.(user);
    finishInitialLoad();
  };

  window.fetch = async function (input, init) {
    const resolved = apiRequestUrl(input);
    const shouldGate = protectedPage && isApiRequest(resolved) && !isAuthRequest(resolved);
    if (!shouldGate) return nativeFetch(resolved, init);

    initialRequests++;
    try {
      await window.authReady;
      const response = await nativeFetch(resolved, init);
      if (response.status === 401) {
        window.location.replace(window.appUrl('/login.html'));
        return response;
      }
      if (!response.ok && !root.hasAttribute('data-auth-ready')) {
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

  if (!domReady) {
    document.addEventListener('DOMContentLoaded', function () {
      domReady = true;
      finishInitialLoad();
    });
  }
})();

(async function authGuard() {
  if (window.__internPilihAuthGuardStarted) return;
  window.__internPilihAuthGuardStarted = true;
  const root = document.documentElement;
  if (!root.hasAttribute('data-protected')) return;

  try {
    const res = await window.__internPilihNativeFetch(window.appUrl('/api/auth.php'), { cache: 'no-store' });
    const data = await res.json().catch(() => ({}));

    if (!res.ok || !data.user) {
      window.location.replace(window.appUrl('/login.html'));
      return;
    }

    const u = data.user;
    const requiredRole = root.getAttribute('data-required-role');
    if (requiredRole && u.role !== requiredRole) {
      const roleHome = {
        student: '/student/dashboard.html',
        employer: '/employer/dashboard.html',
        admin: '/admin/dashboard.html'
      };
      window.location.replace(window.appUrl(roleHome[u.role] || '/login.html'));
      return;
    }
    const initials = u.name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();

    document.querySelectorAll('.user-avatar').forEach(el => { el.textContent = initials; });
    document.querySelectorAll('.user-name,  [data-user-name]').forEach(el  => { el.textContent = u.name; });
    document.querySelectorAll('.user-matric,[data-user-matric]').forEach(el => { el.textContent = u.matric_id || u.email; });

    sessionStorage.setItem('user', JSON.stringify(u));
    window.__completeInternPilihAuth(u);
  } catch (_) {
    window.location.replace(window.appUrl('/login.html'));
  }
})();

// Keep the student response navigation consistent on every student page,
// including pages a browser may have loaded from older cached HTML.
(function ensureStudentResponseNavigation() {
  function updateNavigation() {
    if (!window.location.pathname.replace(/\\/g, '/').includes('/student/')) return;

    const nav = document.querySelector('.sidebar nav');
    if (!nav) return;

    const rejectionLink = nav.querySelector('a[href="communication.html"]');
    if (!rejectionLink) return;

    let acceptanceLink = nav.querySelector('a[href="acceptance.html"]');
    if (!acceptanceLink) {
      acceptanceLink = document.createElement('a');
      acceptanceLink.className = 'nav-item';
      acceptanceLink.href = 'acceptance.html';
      acceptanceLink.innerHTML = '<span class="nav-icon"><i class="fas fa-circle-check"></i></span>Acceptance System';
      rejectionLink.parentNode.insertBefore(acceptanceLink, rejectionLink);
    }

    if (window.location.pathname.endsWith('/student/acceptance.html')) {
      nav.querySelectorAll('.nav-item').forEach(function (item) { item.classList.remove('active'); });
      acceptanceLink.classList.add('active');
      acceptanceLink.setAttribute('aria-current', 'page');
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateNavigation);
  } else {
    updateNavigation();
  }
})();

// Logout
async function doLogout() {
  await fetch('/api/auth.php', { method: 'DELETE' });
  sessionStorage.removeItem('user');
  window.location.href = window.appUrl('/login.html');
}

// Download an authenticated coordinator report and surface API errors instead
// of saving an error response as a CSV/PDF file.
window.downloadAdminReport = async function (format, report) {
  const safeFormat = format === 'pdf' ? 'pdf' : 'csv';
  const safeReport = report === 'offers' ? 'offers' : 'placement';
  const buttons = Array.from(document.querySelectorAll('[data-report-export]'));
  buttons.forEach(button => { button.disabled = true; });

  try {
    const response = await fetch(`/api/reports.php?format=${encodeURIComponent(safeFormat)}&report=${encodeURIComponent(safeReport)}`);
    if (!response.ok) {
      let message = 'Unable to export the report.';
      try {
        const error = await response.json();
        if (error.error) message = error.error;
      } catch (_) {
        // Keep the generic error when the server did not return JSON.
      }
      throw new Error(message);
    }

    const blob = await response.blob();
    const disposition = response.headers.get('Content-Disposition') || '';
    const filenameMatch = disposition.match(/filename="?([^";]+)"?/i);
    const fallbackName = `internpilih-${safeReport}-report.${safeFormat}`;
    const filename = filenameMatch ? filenameMatch[1] : fallbackName;
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);

    if (typeof window.showToast === 'function') {
      window.showToast(`${safeFormat.toUpperCase()} report downloaded.`);
    }
  } catch (error) {
    if (typeof window.showToast === 'function') {
      window.showToast(error.message);
    } else {
      window.alert(error.message);
    }
  } finally {
    buttons.forEach(button => { button.disabled = false; });
  }
};

// Wire up any logout button
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.logout-btn').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      doLogout();
    });
  });
});


// Mobile sidebar
(function () {
  const sidebar  = document.getElementById('sidebar');
  const hamburger = document.getElementById('hamburger');
  const overlay  = document.getElementById('sidebarOverlay');

  if (!sidebar || !hamburger) return;

  function openSidebar() {
    sidebar.classList.add('mobile-open');
    hamburger.classList.add('open');
    if (overlay) overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    sidebar.classList.remove('mobile-open');
    hamburger.classList.remove('open');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  hamburger.addEventListener('click', function () {
    sidebar.classList.contains('mobile-open') ? closeSidebar() : openSidebar();
  });

  if (overlay) overlay.addEventListener('click', closeSidebar);

  // Close sidebar when a nav-item is clicked on mobile
  document.querySelectorAll('.nav-item').forEach(function (item) {
    item.addEventListener('click', function () {
      if (window.innerWidth <= 768) closeSidebar();
    });
  });

  // Close on resize past breakpoint
  window.addEventListener('resize', function () {
    if (window.innerWidth > 768) closeSidebar();
  });
})();


// Toasts
function showToast(msg, type) {
  var existing = document.querySelector('.toast');
  if (existing) existing.remove();

  var colors = {
    success : 'var(--green)',
    error   : 'var(--red)',
    warning : 'var(--gold)',
    info    : 'var(--blue3)'
  };
  var labels = {
    success : 'Done', error : 'Error', warning : 'Note', info : 'Info'
  };
  type = type || 'success';

  var toast = document.createElement('div');
  toast.className = 'toast';
  toast.style.background = colors[type] || colors.success;
  toast.innerHTML = '<span>' + (labels[type] || 'Done') + '</span> ' + msg;
  document.body.appendChild(toast);

  requestAnimationFrame(function () {
    toast.classList.add('show');
  });

  setTimeout(function () {
    toast.classList.remove('show');
    setTimeout(function () { toast.remove(); }, 300);
  }, 3000);
}


// Modal helpers
function openModal(id) {
  var m = document.getElementById(id);
  if (m) m.classList.add('open');
}

function closeModal(id) {
  var m = document.getElementById(id);
  if (m) m.classList.remove('open');
}

// Close modal when clicking outside the modal box
document.addEventListener('click', function (e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});

// Close modal on Escape key
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(function (m) {
      m.classList.remove('open');
    });
  }
});


// Active nav state
(function () {
  var currentPath = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-item').forEach(function (item) {
    var href = (item.getAttribute('href') || '').split('/').pop();
    if (href === currentPath) {
      item.classList.add('active');
    }
  });
})();


// Table search helper
function tableSearch(inputId, tableBodyId) {
  var input = document.getElementById(inputId);
  var tbody = document.getElementById(tableBodyId);
  if (!input || !tbody) return;

  input.addEventListener('input', function () {
    var q = this.value.toLowerCase();
    tbody.querySelectorAll('tr').forEach(function (row) {
      row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
}


// Tab filter helper
function initTabs(tabsSelector, itemsSelector, dataAttr) {
  document.querySelectorAll(tabsSelector).forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll(tabsSelector).forEach(function (b) { b.classList.remove('active'); });
      this.classList.add('active');
      var filter = this.dataset.filter || 'all';
      document.querySelectorAll(itemsSelector).forEach(function (item) {
        var val = item.getAttribute(dataAttr) || '';
        item.style.display = (filter === 'all' || val === filter) ? '' : 'none';
      });
    });
  });
}

// --- DATABASE PAGE BINDINGS -------------------------------------------------
(function () {
  const path = window.location.pathname.replace(/\\/g, '/');
  const page = path.split('/').pop();
  const section = path.includes('/admin/') ? 'admin' : path.includes('/employer/') ? 'employer' : path.includes('/student/') ? 'student' : '';

  function apiUrl(url) {
    return url.indexOf('/') === 0 ? window.appUrl(url) : window.appUrl('/' + url);
  }

  async function apiGet(url) {
    const res = await fetch(apiUrl(url), { cache: 'no-store' });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || 'Request failed');
    return data;
  }

  async function apiSend(url, method, payload) {
    const res = await fetch(apiUrl(url), {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.error) throw new Error(data.error || 'Request failed');
    return data;
  }

  function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  function initials(name) {
    return String(name || 'User').split(/\s+/).filter(Boolean).map(w => w[0]).join('').slice(0, 2).toUpperCase();
  }

  function money(value) {
    const n = Number(value || 0);
    return 'RM ' + n.toLocaleString('en-MY', { maximumFractionDigits: 0 });
  }

  function fmtDate(value) {
    if (!value) return '-';
    const d = new Date(value + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return value;
    return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
  }

  function badge(status, label) {
    const cls = {
      pending: 'badge-pending',
      accepted: 'badge-accepted',
      approved: 'badge-accepted',
      rejected: 'badge-rejected',
      withdrawn: 'badge-withdrawn',
      risk: 'badge-risk',
      verified: 'badge-verified'
    }[status] || '';
    return `<span class="badge ${cls}">${esc(label || status)}</span>`;
  }

  function pct(part, total) {
    return total ? Math.round((Number(part || 0) / Number(total || 0)) * 100) : 0;
  }

  function findPanel(title) {
    return Array.from(document.querySelectorAll('.panel')).find(panel => {
      const h = panel.querySelector('.panel-header h3');
      return h && h.textContent.toLowerCase().includes(title.toLowerCase());
    });
  }

  function industryCountsFromOffers(offers) {
    return (offers || []).reduce((acc, offer) => {
      const industry = String(offer.industry || 'Other').split('/')[0].split('&')[0].trim() || 'Other';
      acc[industry] = (acc[industry] || 0) + 1;
      return acc;
    }, {});
  }

  function placementVerificationStatus(offer, documents = []) {
    const stored = String(offer?.placement_verification_status || '');
    if (['not_submitted', 'pending', 'verified', 'rejected'].includes(stored)) return stored;

    // Compatibility fallback for databases that have not yet run the migration.
    const required = ['offer_letter', 'student_id', 'agreement'];
    const related = (documents || []).filter(d => Number(d.offer_id) === Number(offer?.id));
    if (!related.length) return 'not_submitted';
    if (related.some(d => required.includes(d.doc_type) && d.status === 'rejected')) return 'rejected';
    return required.every(type => related.some(d => d.doc_type === type && d.status === 'approved')) ? 'verified' : 'pending';
  }

  function placementVerificationBadge(offer, documents = []) {
    const status = placementVerificationStatus(offer, documents);
    if (status === 'verified') return badge('verified', 'Verified');
    if (status === 'rejected') return badge('rejected', 'Rejected');
    if (status === 'pending') return badge('pending', 'Pending');
    return badge('pending', 'Not submitted');
  }

  function companyPositionList(company) {
    return String(company?.positions || '').split('||').map(position => position.trim()).filter(Boolean);
  }

  function companyPositions(company) {
    const positions = companyPositionList(company);
    return positions.length ? positions.join(', ') : 'Added with each offer';
  }

  function setText(selector, value) {
    const el = document.querySelector(selector);
    if (el) el.textContent = value;
  }

  function statusForStudent(s) {
    if (s.is_flagged || s.placement_status === 'risk') return 'risk';
    if (s.placement_status === 'confirmed') return 'confirmed';
    if (s.placement_status === 'pending') return 'pending';
    return 'none';
  }

  function updateIdentity(user) {
    if (!user) return;
    document.querySelectorAll('.user-avatar').forEach(el => { el.textContent = initials(user.name); });
    document.querySelectorAll('.user-name, [data-user-name]').forEach(el => { el.textContent = user.name; });
    document.querySelectorAll('.user-matric, [data-user-matric]').forEach(el => { el.textContent = user.matric_id || user.email; });
    document.querySelectorAll('.sidebar-footer .user-info p').forEach(el => { el.textContent = user.name; });
    document.querySelectorAll('.sidebar-footer .user-info span').forEach(el => {
      el.textContent = user.role === 'admin' ? 'Coordinator' : user.role === 'employer' ? 'Employer Account' : (user.matric_id || user.email);
    });
    if (user.role === 'admin' && user.coordinator_programme) {
      const subtitle = document.querySelector('.topbar-sub');
      if (subtitle) subtitle.textContent = user.coordinator_programme;
    }
  }

  function updatePortalBadges(summary) {
    const pendingOffers = (summary.offers || []).filter(o => o.status === 'pending').length;
    const pendingDocs = (summary.documents || []).filter(d => d.status === 'pending').length;
    const riskStudents = Number(summary.stats?.risk_students || 0);
    document.querySelectorAll('.nav-item').forEach(item => {
      const href = item.getAttribute('href') || '';
      let count = null;
      if (section === 'student' && href.includes('offers')) count = pendingOffers;
      if (section === 'student' && href.includes('notifications')) count = Number(summary.stats?.unread_notifications || 0);
      if (section === 'admin' && href.includes('monitoring')) count = riskStudents;
      if (section === 'admin' && href.includes('verification')) count = pendingDocs;
      if (section === 'employer' && href.includes('offers')) count = pendingOffers;
      let b = item.querySelector('.nav-badge');
      if (count !== null) {
        if (!b) {
          b = document.createElement('span');
          b.className = 'nav-badge';
          item.appendChild(b);
        }
        b.textContent = count;
        b.style.display = count > 0 ? '' : 'none';
      }
    });
  }

  async function getSummary() {
    const summary = await apiGet('/api/summary.php');
    updateIdentity(summary.user);
    updatePortalBadges(summary);
    return summary;
  }

  function renderAdminDashboard(data) {
    const vals = document.querySelectorAll('.stats-grid .stat-val');
    const total = Number(data.stats.total_students || 0);
    const confirmed = Number(data.stats.accepted_offers || 0);
    const pending = Number(data.stats.pending_offers || 0);
    const risk = Number(data.stats.risk_students || 0);
    const docs = Number(data.stats.pending_documents || 0);
    [total, confirmed, pending, risk, docs].forEach((v, i) => { if (vals[i]) vals[i].textContent = v; });
    const placedPct = pct(confirmed, total);
    const change = document.querySelectorAll('.stats-grid .stat-change');
    if (change[0]) change[0].textContent = total + ' registered';
    if (change[1]) change[1].textContent = placedPct + '%';
    if (change[2]) change[2].textContent = pending ? 'Needs action' : 'No pending offers';
    if (change[3]) change[3].textContent = risk ? 'High risk' : 'Clear';
    if (change[4]) change[4].textContent = docs ? 'Review needed' : 'All reviewed';

    const topbarSub = document.querySelector('.topbar p');
    if (topbarSub) {
      topbarSub.textContent = `Live database - ${total} students, ${data.companies.length} active companies`;
    }

    const tbody = document.querySelector('.data-table tbody');
    if (tbody) {
      tbody.innerHTML = data.offers.slice(0, 6).map(o => `
        <tr>
          <td><strong>${esc(o.student_name)}</strong><br><small style="color:var(--text2);">${esc(o.matric_id)}</small></td>
          <td>${badge(o.status, o.status)}</td>
          <td>${esc(o.company_name)}</td>
          <td>${o.status === 'accepted' ? badge('verified', 'Placement') : badge(o.status, o.status)}</td>
          <td style="color:var(--text2);">${fmtDate(o.response_date || o.offer_date)}</td>
        </tr>
      `).join('') || '<tr><td colspan="5" style="text-align:center;color:var(--text2);padding:20px;">No offer activity yet.</td></tr>';
    }

    const docsSubmitted = data.documents.length;
    const docsVerified = data.documents.filter(d => d.status === 'approved').length;
    const offersReceived = data.offers.length;
    const progressPanel = findPanel('Placement Progress');
    if (progressPanel) {
      const body = progressPanel.querySelector('.panel-body');
      if (body) {
        body.innerHTML = `
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
            <div>
              ${progressRow('Confirmed Placements', `${confirmed} / ${total}`, pct(confirmed, total), 'green')}
              ${progressRow('Offers Recorded', `${offersReceived}`, pct(offersReceived, Math.max(offersReceived, total)), 'blue')}
              ${progressRow('Docs Submitted', `${docsSubmitted}`, pct(docsSubmitted, Math.max(docsSubmitted, confirmed)), 'gold')}
              ${progressRow('Docs Verified', `${docsVerified} / ${docsSubmitted}`, pct(docsVerified, docsSubmitted), 'purple')}
            </div>
            <div>
              <div style="width:160px;height:160px;border-radius:50%;background:conic-gradient(var(--green) 0% ${placedPct}%,var(--gold) ${placedPct}% ${Math.max(placedPct, pct(confirmed + pending, total))}%,var(--red) ${Math.max(placedPct, pct(confirmed + pending, total))}% 100%);display:flex;align-items:center;justify-content:center;margin:0 auto;position:relative;">
                <div style="width:100px;height:100px;background:var(--card);border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                  <span style="font-size:22px;font-weight:800;">${placedPct}%</span>
                  <span style="font-size:10px;color:var(--text2);">Placed</span>
                </div>
              </div>
              <div style="margin-top:16px;display:grid;gap:8px;font-size:12px;">
                ${legendRow('var(--green)', `Confirmed (${confirmed})`)}
                ${legendRow('var(--gold)', `Pending (${pending})`)}
                ${legendRow('var(--red)', `Risk (${risk})`)}
              </div>
            </div>
          </div>
        `;
      }
    }

    const quickPanel = findPanel('Quick Actions');
    if (quickPanel) {
      const body = quickPanel.querySelector('.panel-body');
      if (body) {
        body.innerHTML = `
          <a href="verification.html" class="btn ${docs ? 'btn-primary' : 'btn-outline'}" style="justify-content:flex-start;"><i class="fas fa-clipboard-check"></i> Review Pending Docs (${docs})</a>
          <a href="monitoring.html" class="btn ${risk ? 'btn-danger' : 'btn-outline'}" style="justify-content:flex-start;"><i class="fas fa-exclamation-triangle"></i> View Offer Flags (${risk})</a>
          <a href="notifications.html" class="btn btn-outline" style="justify-content:flex-start;"><i class="fas fa-bullhorn" style="color:var(--gold);"></i> Send Reminder (${pending} pending)</a>
          <a href="companies.html" class="btn btn-outline" style="justify-content:flex-start;"><i class="fas fa-plus" style="color:var(--green);"></i> Add Company</a>
          <a href="reports.html" class="btn btn-outline" style="justify-content:flex-start;"><i class="fas fa-download" style="color:var(--blue3);"></i> Export Report</a>
        `;
      }
    }

    const riskPanel = findPanel('Hoarding Risk') || findPanel('Offer Flags');
    if (riskPanel) {
      const riskStudents = data.students.filter(s => statusForStudent(s) === 'risk');
      const body = riskPanel.querySelector('.panel-header')?.nextElementSibling;
      if (body) {
        body.innerHTML = riskStudents.length ? riskStudents.map(s => `
          <div class="notif-item">
            <div class="notif-dot-icon" style="background:rgba(239,68,68,.12);color:var(--red);font-size:13px;font-weight:800;">${s.pending_count}</div>
            <div><p style="font-size:13px;font-weight:600;">${esc(s.name)}</p><p style="font-size:11px;color:var(--text2);">${esc(flagReasonText(s))} - ${esc(s.matric_id || '-')}</p></div>
            <a href="monitoring.html" class="btn btn-danger btn-sm">Review</a>
          </div>
        `).join('') : '<div style="padding:16px 0;color:var(--text2);font-size:13px;">No students are currently flagged for pending-offer issues.</div>';
      }
    }

    const industryPanel = findPanel('Industry Breakdown');
    if (industryPanel) {
      const body = industryPanel.querySelector('.panel-body');
      const counts = industryCountsFromOffers(data.offers.filter(o => o.status === 'accepted'));
      const max = Math.max(...Object.values(counts), 1);
      if (body) {
        body.innerHTML = Object.entries(counts).map(([name, count], i) => {
          const colors = ['blue', 'gold', 'purple', 'green', 'red'];
          return `<div>${progressLabel(name, `${count} placement${count === 1 ? '' : 's'}`, pct(count, max), colors[i % colors.length])}</div>`;
        }).join('') || '<div style="color:var(--text2);font-size:13px;">No confirmed placements yet.</div>';
      }
    }
  }

  function progressRow(label, value, width, color) {
    return `
      <div style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;"><span>${esc(label)}</span><strong>${esc(value)}</strong></div>
        <div class="progress-bar"><div class="progress-fill ${color}" style="width:${Math.max(0, Math.min(100, width))}%"></div></div>
      </div>
    `;
  }

  function progressLabel(label, value, width, color) {
    return `
      <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px;"><span>${esc(label)}</span><span>${esc(value)}</span></div>
      <div class="progress-bar"><div class="progress-fill ${color}" style="width:${Math.max(6, Math.min(100, width))}%"></div></div>
    `;
  }

  function legendRow(color, label) {
    return `<div style="display:flex;align-items:center;gap:8px;"><div style="width:10px;height:10px;background:${color};border-radius:2px;"></div>${esc(label)}</div>`;
  }

  let monitoringData = null;
  let placementChangeRows = [];
  function flagReasonText(student) {
    const reasons = [];
    if (student.multiple_pending_flag) reasons.push(`${student.pending_count} active pending offers`);
    if (student.overdue_pending_flag) reasons.push(`${student.overdue_pending_count} beyond response period`);
    return reasons.join('; ') || 'Clear';
  }

  async function renderMonitoring(data) {
    monitoringData = data;
    const body = document.getElementById('monBody');
    if (!body) return;
    const risk = data.students.filter(s => statusForStudent(s) === 'risk').length;
    const responseDays = Number(data.settings?.offer_response_period_days || 14);
    const responseInput = document.getElementById('responsePeriodDays');
    if (responseInput) responseInput.value = responseDays;
    const alert = document.getElementById('riskAlertText');
    if (alert) alert.innerHTML = `<strong>${risk} ${risk === 1 ? 'student is' : 'students are'} flagged</strong> for having more than one active pending offer or exceeding the ${responseDays}-day response period.`;
    const heading = document.getElementById('studentCount');
    if (heading) heading.textContent = '- ' + data.students.length + ' students';
    const riskButton = document.getElementById('riskFilterButton');
    if (riskButton) riskButton.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Flagged (${risk})`;
    const facultyFilter = document.getElementById('facultyFilter');
    if (facultyFilter) {
      const previous = facultyFilter.value;
      const faculties = [...new Set(data.students.map(student => student.faculty).filter(Boolean))].sort();
      facultyFilter.innerHTML = '<option value="">All Faculties</option>' + faculties.map(faculty => `<option value="${esc(faculty)}">${esc(faculty)}</option>`).join('');
      facultyFilter.value = faculties.includes(previous) ? previous : '';
    }
    body.innerHTML = data.students.map(s => {
      const st = statusForStudent(s);
      const warning = st === 'risk' ? badge('risk', s.multiple_pending_flag && s.overdue_pending_flag ? 'Multiple + Overdue' : s.multiple_pending_flag ? 'Multiple Pending' : 'Overdue') : st === 'confirmed' ? badge('verified', 'Clear') : st === 'pending' ? badge('pending', 'Watch') : badge('rejected', 'No Offer');
      const decision = Number(s.accepted_count) > 0 ? badge('accepted', 'Accepted') : Number(s.pending_count) > 0 ? badge('pending', 'Pending') : badge('rejected', 'No Offer');
      return `
        <tr data-status="${st}" data-faculty="${esc(s.faculty || '')}">
          <td><strong>${esc(s.name)}</strong></td>
          <td style="color:var(--text2);">${esc(s.matric_id || '-')}</td>
          <td style="font-size:12px;min-width:160px;">${esc(s.faculty || '-')}</td>
          <td><span style="font-weight:700;">${s.offers_count}</span> <span style="color:var(--text2);font-size:11px;">(${s.pending_count} pending, ${s.accepted_count} accepted)</span></td>
          <td>${decision}</td>
          <td>${warning}</td>
          <td>${esc(s.placement_company || '-')}</td>
          <td>${Number(s.accepted_count) > 0 ? placementVerificationBadge({ placement_verification_status: s.placement_verification_status }) : '-'}</td>
          <td><button class="btn ${st === 'risk' ? 'btn-danger' : 'btn-outline'} btn-sm" onclick="viewStudentFromDb(${s.id})"><i class="fas fa-eye"></i> View</button></td>
        </tr>
      `;
    }).join('');
    if (typeof window.filterStudents === 'function') window.filterStudents();

    try {
      placementChangeRows = await apiGet('/api/placement-changes.php');
    } catch (err) {
      placementChangeRows = [];
    }
    renderPlacementChangeQueue();
  }

  window.saveResponsePeriod = async function () {
    const input = document.getElementById('responsePeriodDays');
    const days = Number(input?.value);
    if (!Number.isInteger(days) || days < 1 || days > 90) {
      alert('Response period must be a whole number between 1 and 90 days.');
      return;
    }
    const button = document.getElementById('saveResponsePeriod');
    if (button) button.disabled = true;
    try {
      await apiSend('/api/settings.php', 'PUT', { offer_response_period_days: days });
      await renderMonitoring(await getSummary());
      showToast('Response period updated. Student flags were recalculated.', 'success');
    } catch (err) {
      alert(err.message);
    } finally {
      if (button) button.disabled = false;
    }
  };

  function renderPlacementChangeQueue() {
    const list = document.getElementById('placementChangeList');
    const count = document.getElementById('placementChangeCount');
    if (!list) return;

    const pending = placementChangeRows.filter(request => request.status === 'pending');
    if (count) count.textContent = pending.length + ' pending';

    list.innerHTML = placementChangeRows.length ? placementChangeRows.map(request => {
      const isPending = request.status === 'pending';
      return `
        <div class="notif-item" style="align-items:flex-start;padding:16px 0;border-bottom:1px solid var(--border);">
          <div class="notif-dot-icon" style="background:rgba(59,130,246,.12);color:var(--blue3);"><i class="fas fa-right-left"></i></div>
          <div style="flex:1;min-width:220px;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <p style="font-size:14px;font-weight:700;">${esc(request.student_name)}</p>
              ${badge(request.status, request.status)}
            </div>
            <p style="font-size:12px;color:var(--text2);margin-top:3px;">${esc(request.matric_id || '-')} - requested ${fmtDate(String(request.created_at || '').slice(0, 10))}</p>
            <div style="margin-top:10px;display:grid;grid-template-columns:1fr auto 1fr;gap:10px;align-items:center;font-size:12px;">
              <div style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px;"><strong>${esc(request.current_company_name)}</strong><br><span style="color:var(--text2);">${esc(request.current_position)}</span></div>
              <i class="fas fa-arrow-right" style="color:var(--text2);"></i>
              <div style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;padding:10px;"><strong>${esc(request.requested_company_name)}</strong><br><span style="color:var(--text2);">${esc(request.requested_position)}</span></div>
            </div>
            <p style="font-size:13px;color:var(--text3);line-height:1.6;margin-top:10px;">${esc(request.reason)}</p>
            ${request.review_note ? `<p style="font-size:12px;color:var(--text2);margin-top:6px;"><strong>Review note:</strong> ${esc(request.review_note)}</p>` : ''}
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end;">
            ${isPending ? `
              <button class="btn btn-success btn-sm" onclick="reviewPlacementChange(${request.id}, 'approved')"><i class="fas fa-check"></i> Approve</button>
              <button class="btn btn-danger btn-sm" onclick="reviewPlacementChange(${request.id}, 'rejected')"><i class="fas fa-times"></i> Reject</button>
            ` : `<span style="font-size:12px;color:var(--text2);">Reviewed ${fmtDate(String(request.reviewed_at || '').slice(0, 10))}</span>`}
          </div>
        </div>
      `;
    }).join('') : '<div style="padding:20px 0;color:var(--text2);font-size:13px;text-align:center;">No placement change requests yet.</div>';
  }

  window.reviewPlacementChange = async function (id, status) {
    const request = placementChangeRows.find(item => Number(item.id) === Number(id));
    if (!request) return;
    const action = status === 'approved' ? 'approve' : 'reject';
    const note = window.prompt(`Add a short review note to ${action} ${request.student_name}'s change request:`, status === 'approved' ? 'Approved by coordinator.' : 'Rejected by coordinator.');
    if (note === null) return;
    try {
      await apiSend('/api/placement-changes.php?id=' + id, 'PUT', {
        status,
        review_note: note
      });
      showToast(status === 'approved' ? 'Placement changed successfully.' : 'Change request rejected.', 'success');
      await renderMonitoring(await getSummary());
    } catch (err) {
      alert(err.message);
    }
  };

  window.viewStudentFromDb = function (id) {
    const s = monitoringData?.students.find(item => Number(item.id) === Number(id));
    if (!s) return;
    const offers = monitoringData.offers.filter(o => Number(o.student_id) === Number(id));
    const st = statusForStudent(s);
    const aiButton = document.getElementById('aiRiskButton');
    if (aiButton) {
      aiButton.style.display = st === 'risk' ? '' : 'none';
      aiButton.dataset.studentId = String(id);
      aiButton.disabled = false;
      aiButton.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Generate AI Summary';
    }
    setText('#sModalName', s.name);
    setText('#sModalId', 'Matric: ' + (s.matric_id || '-'));
    const content = document.getElementById('sModalContent');
    if (content) {
      content.innerHTML = `
        ${st === 'risk' ? `<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i><div><strong>Automatically flagged:</strong> ${esc(flagReasonText(s))}. The configured response period is ${Number(monitoringData.settings?.offer_response_period_days || 14)} days.</div></div>` : ''}
        ${st === 'risk' ? '<div id="aiRiskAssessment" style="display:none;margin-bottom:16px;"></div>' : ''}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:13px;margin-bottom:16px;">
          <div><span style="color:var(--text2);">Faculty</span><br><strong>${esc(s.faculty || '-')}</strong></div>
          <div><span style="color:var(--text2);">Programme</span><br><strong>${esc(s.programme || '-')}</strong></div>
          <div><span style="color:var(--text2);">CGPA</span><br><strong>${esc(s.cgpa || '-')}</strong></div>
          <div><span style="color:var(--text2);">Total Offers</span><br><strong>${s.offers_count}</strong></div>
          <div><span style="color:var(--text2);">Pending Offers</span><br><strong>${s.pending_count}${s.overdue_pending_count ? ` (${s.overdue_pending_count} overdue)` : ''}</strong></div>
        </div>
        <table class="data-table">
          <thead><tr><th>Company</th><th>Position</th><th>Status</th><th>Placement verification</th><th>Received</th><th>Response Due</th></tr></thead>
          <tbody>${offers.map(o => `<tr><td>${esc(o.company_name)}</td><td>${esc(o.position)}</td><td>${badge(Number(o.response_period_exceeded) > 0 ? 'risk' : o.status, Number(o.response_period_exceeded) > 0 ? 'Overdue' : o.status)}</td><td>${o.status === 'accepted' ? placementVerificationBadge(o, monitoringData.documents) : '-'}</td><td>${fmtDate(o.offer_date)}</td><td>${fmtDate(o.configured_response_deadline)}</td></tr>`).join('') || '<tr><td colspan="6">No offers recorded.</td></tr>'}</tbody>
        </table>
      `;
    }
    document.getElementById('studentModal')?.classList.add('open');
  };

  window.analyzeStudentRisk = async function () {
    const button = document.getElementById('aiRiskButton');
    const studentId = Number(button?.dataset.studentId || 0);
    const output = document.getElementById('aiRiskAssessment');
    if (!studentId || !output) return;
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Summarizing...';
    output.style.display = 'block';
    output.innerHTML = '<div class="alert alert-info"><i class="fas fa-wand-magic-sparkles"></i><div>Gemini is summarizing the anonymized rule indicators...</div></div>';
    try {
      const result = await apiSend('/api/offer-risk-ai.php', 'POST', { student_id: studentId });
      const analysis = result.analysis || {};
      const urgency = String(analysis.urgency || 'medium').toLowerCase();
      const badgeStatus = urgency === 'critical' || urgency === 'high' ? 'risk' : urgency === 'medium' ? 'pending' : 'verified';
      const factors = Array.isArray(analysis.factors) ? analysis.factors : [];
      output.innerHTML = `
        <div class="panel" style="margin-bottom:0;border-color:var(--blue3);">
          <div class="panel-header" style="padding:12px 16px;">
            <h3 style="font-size:13px;"><i class="fas fa-wand-magic-sparkles" style="color:var(--blue3);margin-right:7px;"></i>Gemini Indicator Summary</h3>
            ${badge(badgeStatus, `${urgency.toUpperCase()} (ADVISORY)`)}
          </div>
          <div class="panel-body" style="padding:16px;font-size:13px;line-height:1.6;">
            <p>${esc(analysis.summary || 'No summary returned.')}</p>
            ${factors.length ? `<div style="margin-top:12px;"><strong>Contributing indicators</strong><ul style="margin:6px 0 0 18px;">${factors.map(factor => `<li>${esc(factor)}</li>`).join('')}</ul></div>` : ''}
            <p style="margin-top:12px;"><strong>Recommended coordinator action:</strong> ${esc(analysis.recommended_action || '-')}</p>
            <p><strong>Suggested follow-up:</strong> Within ${Number(analysis.suggested_follow_up_hours || 24)} hours</p>
            <div style="margin-top:12px;padding:12px;background:var(--bg3);border-radius:8px;"><strong>Draft reminder</strong><br><span style="white-space:pre-wrap;">${esc(analysis.reminder_message || '-')}</span></div>
            <p style="font-size:10px;color:var(--text2);margin-top:10px;">The summary and additional guidance are AI-generated and advisory. The system rules determine the official flag.</p>
          </div>
        </div>`;
    } catch (err) {
      output.innerHTML = `<div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i><div><strong>AI analysis unavailable:</strong> ${esc(err.message)}</div></div>`;
    } finally {
      button.disabled = false;
      button.innerHTML = '<i class="fas fa-rotate"></i> Regenerate AI Summary';
    }
  };

  function renderReports(data) {
    const vals = document.querySelectorAll('.stats-grid .stat-val');
    const total = Number(data.stats.total_students || 0);
    const confirmed = Number(data.stats.accepted_offers || 0);
    const pending = Number(data.stats.pending_offers || 0);
    const risk = Number(data.stats.risk_students || 0);
    const companies = Number(data.stats.active_companies || 0);
    [total, confirmed, pending, risk, companies].forEach((v, i) => { if (vals[i]) vals[i].textContent = v; });
    const confirmedShare = document.querySelector('.stats-grid .stat-change');
    if (confirmedShare) confirmedShare.textContent = `${pct(confirmed, total)}%`;
    const reportSubtitle = document.querySelector('.topbar p');
    if (reportSubtitle) reportSubtitle.textContent = `${total} students across ${new Set(data.students.map(s => s.faculty).filter(Boolean)).size} faculties · Live placement data`;
    const answered = data.offers.filter(o => ['accepted', 'rejected', 'withdrawn'].includes(o.status));
    const awaiting = data.offers.filter(o => o.status === 'pending').length;
    const onTime = answered.filter(o => o.response_date && o.response_date <= (o.deadline || o.configured_response_deadline)).length;
    const late = answered.length - onTime;
    const allOffers = data.offers.length;
    const portion = count => allOffers ? Math.round(count / allOffers * 100) : 0;
    setText('#responseRate', `${portion(answered.length)}%`);
    setText('#onTimeCount', `${onTime} / ${allOffers}`);
    setText('#lateCount', `${late} / ${allOffers}`);
    setText('#awaitingCount', `${awaiting} / ${allOffers}`);
    [['onTimeBar', onTime], ['lateBar', late], ['awaitingBar', awaiting]].forEach(([id, count]) => {
      const bar = document.getElementById(id);
      if (bar) bar.style.width = `${portion(count)}%`;
    });

    if (window.Chart) {
      const placementChart = Chart.getChart('placementChart');
      if (placementChart) {
        placementChart.data.datasets[0].data = ['confirmed', 'pending', 'none', 'risk'].map(status => data.students.filter(s => statusForStudent(s) === status).length);
        placementChart.update();
      }
      const industryChart = Chart.getChart('industryChart');
      if (industryChart) {
        const industries = Object.entries(data.offers.reduce((counts, offer) => {
          const name = offer.industry || 'Other';
          counts[name] = (counts[name] || 0) + 1;
          return counts;
        }, {})).sort((a, b) => b[1] - a[1]);
        industryChart.data.labels = industries.map(([name]) => name);
        industryChart.data.datasets[0].data = industries.map(([, count]) => count);
        industryChart.data.datasets[0].backgroundColor = industries.map((_, i) => ['#09a9a3', '#ff9b2f', '#563383', '#14785b', '#b23a34', '#64748b'][i % 6]);
        industryChart.update();
      }
      const trendChart = Chart.getChart('trendChart');
      if (trendChart) {
        const months = Array.from({ length: 6 }, (_, index) => {
          const date = new Date();
          date.setDate(1);
          date.setMonth(date.getMonth() - 5 + index);
          return date;
        });
        trendChart.data.labels = months.map(date => date.toLocaleDateString('en-MY', { month: 'short', year: '2-digit' }));
        trendChart.data.datasets[0].data = months.map(date => data.offers.filter(o => String(o.offer_date || '').startsWith(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`)).length);
        trendChart.data.datasets[1].data = months.map(date => answered.filter(o => String(o.response_date || '').startsWith(`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`)).length);
        trendChart.update();
      }
    }
    const body = document.querySelector('.panel:last-of-type .data-table tbody');
    if (body) {
      body.innerHTML = data.students.map((s, i) => `
        <tr>
          <td>${i + 1}</td>
          <td>${esc(s.name)}</td>
          <td>${esc(s.matric_id || '-')}</td>
          <td>${esc(s.faculty || '-')}</td>
          <td>${esc(s.placement_company || '-')}</td>
          <td>${esc(s.placement_industry || '-')}</td>
          <td>${s.placement_allowance ? money(s.placement_allowance) : '-'}</td>
          <td>${statusForStudent(s) === 'confirmed' ? badge('accepted', 'Confirmed') : statusForStudent(s) === 'risk' ? badge('risk', 'Risk') : statusForStudent(s) === 'pending' ? badge('pending', 'Pending') : badge('rejected', 'No Offer')}</td>
          <td>${Number(s.accepted_count) > 0 ? placementVerificationBadge({ placement_verification_status: s.placement_verification_status }) : '-'}</td>
        </tr>
      `).join('');
    }
  }

  let companyRows = [];
  let editingCompanyId = null;
  function renderAdminCompanies(data) {
    companyRows = data.companies || [];
    const body = document.getElementById('compBody');
    if (!body) return;
    const titleCount = document.querySelector('.panel-header h3 span');
    if (titleCount) titleCount.textContent = '- ' + companyRows.length + ' total';
    body.innerHTML = companyRows.map(c => `
      <tr data-id="${c.id}">
        <td><strong>${esc(c.name)}</strong></td>
        <td>${esc(c.industry)}</td>
        <td>${esc(c.location)}</td>
        <td style="font-size:12px;color:var(--text2);">${esc(c.hr_email || '-')}</td>
        <td>${money(c.allowance)}</td>
        <td style="max-width:260px;">${esc(companyPositions(c))}</td>
        <td><strong style="color:var(--green);">${Number(c.confirmed_placements || 0)}</strong></td>
        <td>${badge('verified', 'Active')}</td>
        <td style="display:flex;gap:4px;">
          <button class="btn btn-outline btn-sm" onclick="editCompanyFromDb(${c.id})"><i class="fas fa-edit"></i></button>
          <button class="btn btn-danger btn-sm" onclick="deleteCompanyFromDb(${c.id})"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    `).join('');
  }

  window.editCompanyFromDb = function (id) {
    const c = companyRows.find(item => Number(item.id) === Number(id));
    if (!c) return;
    editingCompanyId = id;
    setText('#compModalTitle', 'Edit - ' + c.name);
    document.getElementById('cName').value = c.name || '';
    document.getElementById('cIndustry').value = c.industry || 'IT / Software';
    document.getElementById('cLocation').value = c.location || '';
    document.getElementById('cAllowance').value = c.allowance || '';
    document.getElementById('cEmail').value = c.hr_email || '';
    const modeSelect = document.getElementById('cWorkMode');
    if (modeSelect) modeSelect.value = c.work_mode || 'On-site';
    document.getElementById('compModal')?.classList.add('open');
  };

  window.deleteCompanyFromDb = function (id) {
    editingCompanyId = id;
    document.getElementById('deleteModal')?.classList.add('open');
  };

  function hookAdminCompanyCrud() {
    window.openAddCompany = function () {
      editingCompanyId = null;
      setText('#compModalTitle', 'Add New Company');
      ['cName', 'cLocation', 'cAllowance', 'cEmail', 'cPhone'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
      });
      document.getElementById('compModal')?.classList.add('open');
    };
    window.saveCompany = async function () {
      const modeSelect = document.getElementById('cWorkMode');
      const payload = {
        name: document.getElementById('cName').value.trim(),
        industry: document.getElementById('cIndustry').value,
        location: document.getElementById('cLocation').value.trim(),
        allowance: Number(document.getElementById('cAllowance').value || 0),
        work_mode: modeSelect ? modeSelect.value : 'On-site',
        hr_email: document.getElementById('cEmail').value.trim()
      };
      if (!payload.name || !payload.industry || !payload.location) {
        alert('Company name, industry, and location are required.');
        return;
      }
      try {
        await apiSend('/api/companies.php' + (editingCompanyId ? '?id=' + editingCompanyId : ''), editingCompanyId ? 'PUT' : 'POST', payload);
        document.getElementById('compModal')?.classList.remove('open');
        renderAdminCompanies(await getSummary());
        showToast('Company saved successfully!', 'success');
      } catch (err) {
        alert(err.message);
      }
    };
    window.confirmDelete = async function () {
      try {
        await fetch('/api/companies.php?id=' + editingCompanyId, { method: 'DELETE' });
        document.getElementById('deleteModal')?.classList.remove('open');
        renderAdminCompanies(await getSummary());
        showToast('Company deleted.', 'success');
      } catch (err) {
        alert(err.message);
      }
    };
  }

  let docsForVerification = [];
  let activeDocId = null;
  function renderVerification(data) {
    docsForVerification = data.documents || [];
    const list = document.getElementById('verifList');
    if (!list) return;
    const counts = {
      all: docsForVerification.length,
      pending: docsForVerification.filter(d => d.status === 'pending').length,
      approved: docsForVerification.filter(d => d.status === 'approved').length,
      rejected: docsForVerification.filter(d => d.status === 'rejected').length
    };
    document.querySelectorAll('.tab-btn').forEach(btn => {
      const txt = btn.textContent.toLowerCase();
      if (txt.includes('all')) btn.textContent = 'All (' + counts.all + ')';
      if (txt.includes('pending')) btn.textContent = 'Pending (' + counts.pending + ')';
      if (txt.includes('approved')) btn.textContent = 'Approved (' + counts.approved + ')';
      if (txt.includes('rejected')) btn.textContent = 'Rejected (' + counts.rejected + ')';
    });
    list.innerHTML = docsForVerification.map(d => `
      <div class="panel verif-card" data-vstatus="${esc(d.status)}" style="margin-bottom:0;">
        <div class="panel-body">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;">
            <div style="display:flex;gap:14px;align-items:flex-start;">
              <div style="width:48px;height:48px;background:rgba(59,130,246,.15);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;"><i class="fas fa-file-alt"></i></div>
              <div>
                <p style="font-weight:700;font-size:14px;">${esc(String(d.doc_type || '').replace(/_/g, ' '))}</p>
                <p style="font-size:12px;color:var(--text2);">Uploaded by: <strong style="color:var(--text);">${esc(d.student_name)} (${esc(d.matric_id || '-')})</strong> - ${esc(d.company_name || 'No placement')}</p>
                <p style="font-size:12px;color:var(--text2);">Submitted: ${fmtDate(String(d.uploaded_at || '').slice(0, 10))} - ${esc(d.file_name)}</p>
                ${d.reject_reason ? `<p style="font-size:12px;color:var(--red);">${esc(d.reject_reason)}</p>` : ''}
              </div>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px;">
              ${badge(d.status, d.status)}
              ${d.offer_id ? `<div style="font-size:11px;color:var(--text2);">Placement ${placementVerificationBadge({ id: d.offer_id, placement_verification_status: d.placement_verification_status }, docsForVerification)}</div>` : ''}
              <div style="display:flex;gap:6px;">
                <button class="btn btn-outline btn-sm" onclick="previewDocFromDb(${Number(d.id)})"><i class="fas fa-eye"></i> Preview</button>
                ${d.status === 'pending' ? `<button class="btn btn-success btn-sm" onclick="approveDocFromDb(${d.id})"><i class="fas fa-check"></i> Approve</button><button class="btn btn-danger btn-sm" onclick="openRejectFromDb(${d.id})"><i class="fas fa-times"></i> Reject</button>` : ''}
              </div>
            </div>
          </div>
        </div>
      </div>
    `).join('') || '<div class="panel"><div class="panel-body" style="text-align:center;color:var(--text2);">No documents uploaded yet.</div></div>';
  }

  window.previewDocFromDb = function (id) {
    window.open('/api/document-file.php?id=' + encodeURIComponent(id), '_blank', 'noopener,noreferrer');
  };
  window.approveDocFromDb = async function (id) {
    const result = await apiSend('/api/documents.php?id=' + id, 'PUT', { status: 'approved' });
    renderVerification(await getSummary());
    showToast(result.placement_verification_status === 'verified' ? 'Document approved. Placement is now verified.' : 'Document approved. Placement verification updated.', 'success');
  };
  window.openRejectFromDb = function (id) {
    activeDocId = id;
    document.getElementById('rejectModal')?.classList.add('open');
  };
  function hookVerification() {
    window.confirmReject = async function () {
      const reason = [document.getElementById('rejectReason')?.value, document.getElementById('rejectNote')?.value].filter(Boolean).join(' - ');
      const result = await apiSend('/api/documents.php?id=' + activeDocId, 'PUT', { status: 'rejected', reject_reason: reason });
      document.getElementById('rejectModal')?.classList.remove('open');
      renderVerification(await getSummary());
      showToast(result.placement_verification_status === 'rejected' ? 'Document rejected. Placement verification was rejected.' : 'Document rejected. Student notified.', 'success');
    };
  }

  let pendingRejectOffers = [];
  let selectedRejectOffer = null;
  async function renderStudentCommunication() {
    const offers = await apiGet('/api/offers.php');
    pendingRejectOffers = offers.filter(o => o.status === 'pending');
    const list = document.querySelector('.offer-select-card')?.parentElement;
    if (list) {
      list.innerHTML = pendingRejectOffers.map(o => `
        <div class="offer-select-card" data-id="${o.id}" onclick="selectOfferForReject(${o.id})" style="padding:14px;border:1px solid var(--border);border-radius:12px;cursor:pointer;transition:.2s;">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
              <p style="font-weight:600;font-size:13px;">${esc(o.company_name)}</p>
              <p style="font-size:12px;color:var(--text2);">${esc(o.position)} - ${esc(o.hr_email || 'No HR email')}</p>
            </div>
            ${badge('pending', 'Pending')}
          </div>
        </div>
      `).join('') || '<div style="padding:20px;color:var(--text2);text-align:center;">No pending offers to reject.</div>';
    }
    const stats = document.querySelectorAll('.panel .stat-card, .panel [style*="text-align:center"] div[style*="font-size:24px"]');
    if (stats[0]) stats[0].textContent = offers.filter(o => o.status === 'rejected').length;
    if (stats[1]) stats[1].textContent = offers.filter(o => o.status === 'accepted').length;
  }

  window.selectOfferForReject = function (id) {
    selectedRejectOffer = pendingRejectOffers.find(o => Number(o.id) === Number(id));
    document.querySelectorAll('.offer-select-card').forEach(c => {
      c.style.borderColor = 'var(--border)';
      c.style.background = 'transparent';
    });
    const card = document.querySelector(`.offer-select-card[data-id="${id}"]`);
    if (card) {
      card.style.borderColor = 'var(--blue3)';
      card.style.background = 'rgba(59,130,246,.05)';
    }
    const email = document.getElementById('toEmail');
    if (email) email.value = selectedRejectOffer?.hr_email || '';
    if (typeof updateTemplate === 'function') updateTemplate();
  };

  function hookStudentCommunication() {
    window.sendRejection = async function () {
      if (!selectedRejectOffer) {
        alert('Please select a pending offer first.');
        return;
      }
      const reason = document.getElementById('emailBody')?.value || 'Professional rejection notice sent.';
      const subject = document.getElementById('emailSubject')?.value?.trim() || '';
      const recipientEmail = document.getElementById('toEmail')?.value?.trim() || '';
      if (!recipientEmail) {
        alert('Enter the receiver email address.');
        return;
      }
      if (!document.getElementById('toEmail')?.checkValidity()) {
        alert('Enter a valid receiver email address.');
        return;
      }
      const registeredEmail = String(selectedRejectOffer.hr_email || '').trim();
      let confirmedRecipient = '';
      if (recipientEmail.toLowerCase() !== registeredEmail.toLowerCase()) {
        const confirmed = window.confirm(`Send this rejection email to ${recipientEmail}?\n\nThis is different from the company’s registered HR email.`);
        if (!confirmed) return;
        confirmedRecipient = recipientEmail;
      }
      const sendButtons = Array.from(document.querySelectorAll('[onclick="sendRejection()"]'));
      sendButtons.forEach(button => { button.disabled = true; });

      try {
        const result = await apiSend('/api/offers.php?id=' + selectedRejectOffer.id, 'PUT', {
          status: 'rejected',
          reject_reason: reason,
          email_subject: subject,
          recipient_email: recipientEmail,
          confirmed_recipient_email: confirmedRecipient
        });
        const sentMessage = document.getElementById('sentMessage');
        if (sentMessage && result.email_to) {
          sentMessage.textContent = `Email delivered to ${result.email_to} and logged in your communication history.`;
        }
        selectedRejectOffer = null;
        document.getElementById('previewModal')?.classList.remove('open');
        document.getElementById('sentModal')?.classList.add('open');
        await renderStudentCommunication();
      } catch (error) {
        alert(error.message || 'The rejection email could not be sent.');
      } finally {
        sendButtons.forEach(button => { button.disabled = false; });
      }
    };
  }

  let studentProfileSnapshot = null;

  function applyStudentProfile(data) {
    const u = data.user;
    studentProfileSnapshot = { ...u };
    updateIdentity(u);
    const fieldValues = {
      fullName: u.name || '',
      studentMatric: u.matric_id || '',
      studentEmail: u.email || '',
      studentPhone: u.phone || '',
      studentCgpa: u.cgpa ?? '',
      studentProgramme: u.programme || '',
      studentFaculty: u.faculty || ''
    };
    Object.entries(fieldValues).forEach(([id, value]) => {
      const field = document.getElementById(id);
      if (field) field.value = value;
    });
    const coordinatorInput = document.getElementById('coordinatorName');
    if (coordinatorInput) coordinatorInput.value = u.coordinator_name || 'Not assigned';
    setText('#studentProfileName', u.name || 'Student');
    setText('#studentProfileMeta', `${u.matric_id || '-'} - ${u.faculty || '-'}`);
    setText('#studentProfileProgramme', u.programme || 'Programme not recorded');
    const avatar = document.querySelector('.profile-avatar');
    if (avatar) avatar.textContent = initials(u.name);
    const placementBadge = document.getElementById('placementBadge');
    if (placementBadge) {
      const placement = data.placement;
      const verified = placement?.placement_verification_status === 'verified';
      placementBadge.className = `badge ${verified ? 'badge-verified' : (placement ? 'badge-accepted' : 'badge-pending')}`;
      placementBadge.textContent = verified
        ? 'Placement Verified'
        : (placement ? 'Placement Accepted - Verification Pending' : 'No Accepted Placement');
    }
  }

  async function renderStudentProfile() {
    applyStudentProfile(await apiGet('/api/profile.php'));
  }

  function hookStudentProfile() {
    window.saveProfile = async function () {
      const candidates = {
        name: document.getElementById('fullName')?.value.trim() || '',
        phone: document.getElementById('studentPhone')?.value.trim() || '',
        cgpa: document.getElementById('studentCgpa')?.value.trim() || ''
      };
      const payload = {};
      Object.entries(candidates).forEach(([field, value]) => {
        if (String(studentProfileSnapshot?.[field] ?? '') !== String(value)) payload[field] = value;
      });
      if (!Object.keys(payload).length) {
        showToast('No profile changes to save.', 'success');
        return;
      }
      try {
        const result = await apiSend('/api/profile.php', 'PUT', payload);
        applyStudentProfile(result);
        showToast('Profile saved!', 'success');
      } catch (err) {
        alert(err.message);
      }
    };
  }

  async function renderStudentNotifications() {
    const list = document.getElementById('notifList');
    if (!list) return;
    let data;
    try {
      data = await apiGet('/api/notifications.php?fresh=' + Date.now());
    } catch (error) {
      list.dataset.loadError = 'true';
      list.innerHTML = `<div class="empty-state" id="notifEmptyState"><i class="fas fa-triangle-exclamation"></i><p>Notifications could not be loaded.</p><button class="btn btn-outline btn-sm" type="button" onclick="window.refreshStudentNotifications()"><i class="fas fa-rotate-right"></i> Try Again</button></div>`;
      const sub = document.querySelector('.topbar p');
      if (sub) sub.textContent = 'Unable to refresh notifications';
      return;
    }
    delete list.dataset.loadError;
    const notes = data.notifications || [];
    const iconForType = type => type === 'urgent' ? 'fa-exclamation-triangle' : type === 'reminder' ? 'fa-bell' : type === 'general' ? 'fa-bullhorn' : 'fa-circle-info';
    const colorForType = type => type === 'urgent' ? 'var(--red)' : type === 'reminder' ? 'var(--gold)' : type === 'general' ? 'var(--purple)' : 'var(--blue3)';
    const isAdminNote = note => Boolean(note.created_by || note.announcement_id || (note.sender_name && note.sender_name !== 'System'));
    list.innerHTML = notes.map(note => {
      const unread = Number(note.is_read) === 0;
      const type = `${unread ? 'unread ' : ''}${isAdminNote(note) ? 'admin' : 'system'} ${note.type}`;
      return `
      <div class="notif-item" style="padding:16px 20px;${unread ? '' : 'opacity:.72;'}" data-type="${esc(type)}">
        <div class="notif-dot-icon" style="background:rgba(59,130,246,.12);color:${colorForType(note.type)};"><i class="fas ${iconForType(note.type)}"></i></div>
        <div style="flex:1;">
          <p style="font-size:14px;font-weight:600;">${esc(note.title)}</p>
          <p style="font-size:13px;color:var(--text3);margin-top:3px;">${esc(note.body)}</p>
          <p class="notif-meta" style="margin-top:6px;"><i class="fas fa-clock"></i> ${fmtDate(String(note.created_at || '').slice(0, 10))} - ${esc(note.sender_name || 'System')}</p>
          ${note.link_url ? `<div style="margin-top:10px;display:flex;gap:8px;"><a href="${esc(note.link_url)}" class="btn btn-outline btn-sm"><i class="fas fa-arrow-right"></i> Open</a></div>` : ''}
        </div>
        ${unread ? '<div class="unread-marker"></div>' : ''}
      </div>
    `}).join('') || '<div class="empty-state" id="notifEmptyState"><i class="fas fa-inbox"></i><p>No notifications yet.</p></div>';
    const unread = notes.filter(note => Number(note.is_read) === 0).length;
    const system = notes.filter(note => !isAdminNote(note)).length;
    const admin = notes.filter(note => isAdminNote(note)).length;
    const sub = document.querySelector('.topbar p');
    if (sub) sub.textContent = unread + ' unread notifications';
    const tabs = document.querySelectorAll('.tab-btn');
    if (tabs[0]) tabs[0].textContent = `All (${notes.length})`;
    if (tabs[1]) tabs[1].textContent = `Unread (${unread})`;
    if (tabs[2]) tabs[2].textContent = `System (${system})`;
    if (tabs[3]) tabs[3].textContent = `From Admin (${admin})`;
    const activeTab = document.querySelector('.tab-btn.active');
    if (activeTab && typeof window.filterNotif === 'function') {
      window.filterNotif(activeTab.dataset.filter || 'all', activeTab);
    }
  }

  function renderEmployerPages(data) {
    const offers = data.offers || [];
    const company = data.companies?.[0] || {};
    const accepted = offers.filter(o => o.status === 'accepted');
    const pending = offers.filter(o => o.status === 'pending');
    const rejected = offers.filter(o => o.status === 'rejected');
    const confirmedPlacements = Number(company.confirmed_placements || accepted.length || 0);
    const verifiedDocs = accepted.filter(o => placementVerificationStatus(o, data.documents) === 'verified').length;
    const pendingDocs = accepted.filter(o => placementVerificationStatus(o, data.documents) !== 'verified').length;
    const companyName = company.name || data.user.name;
    document.querySelectorAll('.sidebar-footer .user-info p').forEach(el => { el.textContent = companyName; });
    document.querySelectorAll('.sidebar-footer .user-avatar').forEach(el => { el.textContent = initials(companyName); });
    const briefKicker = document.querySelector('.dashboard-brief-kicker');
    if (briefKicker) briefKicker.textContent = companyName;

    const topbarSub = document.querySelector('.topbar p');
    if (topbarSub && page === 'dashboard.html') {
      topbarSub.textContent = `${company.name || data.user.name} - ${offers.length} offer response${offers.length === 1 ? '' : 's'}`;
    }

    const infoAlert = document.querySelector('.content > .alert-info');
    if (infoAlert && page === 'dashboard.html') {
      infoAlert.innerHTML = `<i class="fas fa-info-circle"></i>Viewing live offer responses for <strong>${esc(company.name || data.user.name)}</strong>. ${pending.length} response${pending.length === 1 ? '' : 's'} awaiting student action.`;
    }

    const vals = document.querySelectorAll('.stat-val');
    if (page === 'dashboard.html' || page === 'offers.html') {
      [offers.length, page === 'offers.html' ? pending.length : accepted.length, page === 'offers.html' ? accepted.length : rejected.length, page === 'offers.html' ? rejected.length : pending.length].forEach((v, i) => {
        if (vals[i]) vals[i].textContent = v;
      });
      const changes = document.querySelectorAll('.stats-grid .stat-change');
      if (page === 'dashboard.html') {
        if (changes[0]) changes[0].textContent = pct(offers.length, offers.length) + '%';
        if (changes[1]) changes[1].textContent = pct(accepted.length, offers.length) + '%';
        if (changes[2]) changes[2].textContent = pct(rejected.length, offers.length) + '%';
        if (changes[3]) changes[3].textContent = pct(pending.length, offers.length) + '%';
      }
    }
    if (page === 'dashboard.html') {
      const body = document.querySelector('.data-table tbody');
      if (body) body.innerHTML = offers.slice(0, 6).map(o => `
        <tr>
          <td><strong>${esc(o.student_name)}</strong><br><small style="color:var(--text2);">${esc(o.matric_id)}</small></td>
          <td>${esc(o.position)}</td>
          <td>${badge(o.status, o.status)}</td>
          <td style="color:var(--text2);">${fmtDate(o.response_date || o.offer_date)}</td>
          <td><button class="btn btn-outline btn-sm" onclick="viewEmployerOffer(${o.id})"><i class="fas fa-eye"></i></button></td>
        </tr>
      `).join('');

      const acceptedPanel = findPanel('Accepted Placements');
      const acceptedBody = acceptedPanel?.querySelector('.panel-header')?.nextElementSibling;
      if (acceptedBody) {
        acceptedBody.innerHTML = accepted.length ? accepted.map(o => {
          return `
            <div class="notif-item">
              <div class="notif-dot-icon" style="background:rgba(16,185,129,.12);color:var(--green);">${initials(o.student_name)}</div>
              <div><p style="font-size:13px;font-weight:600;">${esc(o.student_name)}</p><p style="font-size:12px;color:var(--text2);">${esc(o.position)} - Accepted ${fmtDate(o.response_date || o.offer_date)}</p>${placementVerificationBadge(o, data.documents)}</div>
            </div>
          `;
        }).join('') : '<div style="padding:16px 0;color:var(--text2);font-size:13px;">No accepted placements yet.</div>';
      }

      const companyPanel = findPanel('Company Info');
      const companyBody = companyPanel?.querySelector('.panel-body');
      if (companyBody) {
        companyBody.innerHTML = `
          ${infoLine('Company', company.name || '-')}
          ${infoLine('Industry', company.industry || '-')}
          ${infoLine('Location', company.location || '-')}
          ${infoLine('Work Mode', company.work_mode || '-')}
          ${infoLine('Recorded Positions', companyPositions(company))}
          ${infoLine('Confirmed Interns', String(confirmedPlacements), 'var(--green)')}
        `;
      }
    }
    if (page === 'offers.html') {
      const tabs = document.querySelectorAll('.tab-nav .tab-btn');
      const counts = { all: offers.length, pending: pending.length, accepted: accepted.length, rejected: rejected.length };
      if (tabs[0]) tabs[0].textContent = `All (${counts.all})`;
      if (tabs[1]) tabs[1].textContent = `Awaiting (${counts.pending})`;
      if (tabs[2]) tabs[2].textContent = `Accepted (${counts.accepted})`;
      if (tabs[3]) tabs[3].textContent = `Declined (${counts.rejected})`;

      const body = document.querySelector('#offersTable tbody');
      if (body) body.innerHTML = offers.map(o => `
        <tr data-fstatus="${esc(o.status)}">
          <td><strong>${esc(o.student_name)}</strong></td>
          <td style="color:var(--text2);">${esc(o.matric_id)}</td>
          <td>${esc(o.position)}</td>
          <td>${fmtDate(o.offer_date)}</td>
          <td>${badge(o.status, o.status)}</td>
          <td>${fmtDate(o.response_date)}</td>
          <td><button class="btn btn-outline btn-sm" onclick="viewEmployerOffer(${o.id})"><i class="fas fa-eye"></i></button></td>
        </tr>
      `).join('') || '<tr><td colspan="7" style="text-align:center;color:var(--text2);padding:20px;">No offers recorded for this company.</td></tr>';
    }
    if (page === 'placements.html') {
      const summaryCards = document.querySelectorAll('.content > div[style*="grid-template-columns:repeat(3"] > div');
      if (summaryCards[0]) summaryCards[0].innerHTML = `<div style="font-size:36px;font-weight:800;color:var(--green);">${accepted.length}</div><div style="font-size:13px;color:var(--text2);margin-top:4px;">Confirmed Interns</div><div style="font-size:11px;color:var(--green);margin-top:4px;">Accepted placements</div>`;
      if (summaryCards[1]) summaryCards[1].innerHTML = `<div style="font-size:36px;font-weight:800;color:var(--blue3);">${verifiedDocs}</div><div style="font-size:13px;color:var(--text2);margin-top:4px;">Placements Verified</div><div style="font-size:11px;color:var(--blue3);margin-top:4px;">${pendingDocs} pending review</div>`;
      if (summaryCards[2]) summaryCards[2].innerHTML = `<div style="font-size:24px;font-weight:800;color:var(--purple);">${accepted.length ? fmtDate(accepted[0].offer_date) : '-'}</div><div style="font-size:13px;color:var(--text2);margin-top:4px;">Earliest Offer Date</div><div style="font-size:11px;color:var(--text2);margin-top:4px;">From accepted records</div>`;

      const body = document.querySelector('.data-table tbody');
      if (body) body.innerHTML = accepted.map(o => {
        return `
          <tr>
            <td><div style="display:flex;align-items:center;gap:10px;"><div class="avatar-initial">${initials(o.student_name)}</div><div><strong>${esc(o.student_name)}</strong></div></div></td>
            <td style="color:var(--text2);">${esc(o.matric_id)}</td>
            <td>${esc(o.position)}</td>
            <td>UiTM</td>
            <td>${fmtDate(o.offer_date)}</td>
            <td>6 Months</td>
            <td>${placementVerificationBadge(o, data.documents)}</td>
            <td>${badge('accepted', 'Active')}</td>
          </tr>
        `;
      }).join('') || '<tr><td colspan="8" style="text-align:center;color:var(--text2);padding:20px;">No accepted placements yet.</td></tr>';
      const topNums = document.querySelectorAll('.content [style*="text-align:center"] > div:first-child');
      if (topNums[0]) topNums[0].textContent = accepted.length;
      if (topNums[1]) topNums[1].textContent = verifiedDocs;

      const grids = Array.from(document.querySelectorAll('.content > div[style*="display:grid"]'));
      const profileGrid = grids[grids.length - 1];
      if (profileGrid && !profileGrid.querySelector('.data-table')) {
        profileGrid.innerHTML = accepted.map(o => {
          return `
            <div class="panel" style="margin-bottom:0;">
              <div style="padding:24px;text-align:center;">
                <div style="width:64px;height:64px;background:#dbeafe;color:#1e40af;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;margin:0 auto 12px;">${initials(o.student_name)}</div>
                <h3 style="font-size:15px;font-weight:700;">${esc(o.student_name)}</h3>
                <p style="font-size:12px;color:var(--text2);">${esc(o.matric_id || '-')} - UiTM</p>
                <p style="font-size:12px;color:var(--blue3);margin-top:4px;">${esc(o.position)}</p>
                <div style="display:flex;justify-content:center;gap:6px;margin:12px 0;">${placementVerificationBadge(o, data.documents)}${badge('accepted', 'Active')}</div>
                <div style="text-align:left;font-size:12px;color:var(--text2);background:var(--bg3);border-radius:10px;padding:12px;margin-top:8px;display:grid;gap:6px;">
                  <div><strong style="color:var(--text);">CGPA:</strong> ${esc(o.cgpa || '-')}</div>
                  <div><strong style="color:var(--text);">Faculty:</strong> ${esc(o.faculty || '-')}</div>
                  <div><strong style="color:var(--text);">Programme:</strong> ${esc(o.programme || '-')}</div>
                  <div><strong style="color:var(--text);">Email:</strong> ${esc(o.student_email || '-')}</div>
                  <div><strong style="color:var(--text);">Accepted:</strong> ${fmtDate(o.response_date || o.offer_date)}</div>
                </div>
              </div>
            </div>
          `;
        }).join('') || '<div class="panel"><div class="panel-body" style="text-align:center;color:var(--text2);">No intern profiles yet.</div></div>';
      }
    }
    if (page === 'profile.html') {
      const wrap = document.querySelector('.content > div[style*="max-width"]');
      if (wrap) {
        wrap.innerHTML = `
          <div class="panel" style="margin-bottom:24px;">
            <div class="panel-body">
              <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
                <div style="width:88px;height:88px;background:#e8f1ff;color:var(--blue);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:36px;"><i class="fas fa-building"></i></div>
                <div style="flex:1;">
                  <h2 style="font-size:22px;font-weight:800;">${esc(company.name || data.user.name)}</h2>
                  <p style="color:var(--text2);font-size:13px;">${esc(company.industry || '-')} - ${esc(company.location || '-')}</p>
                  <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">${badge('verified', 'Verified Partner')}${badge('accepted', 'Active')}<span class="badge" style="background:rgba(16,185,129,.1);color:var(--green);">${confirmedPlacements} Confirmed Intern${confirmedPlacements === 1 ? '' : 's'}</span></div>
                </div>
              </div>
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
            <div class="panel"><div class="panel-header"><h3><i class="fas fa-building" style="color:var(--blue3);margin-right:8px;"></i>Company Information</h3></div><div class="panel-body" style="font-size:13px;display:grid;gap:14px;">
              ${profileField('Company Name', company.name || '-')}
              ${profileField('Industry', company.industry || '-')}
              ${profileField('Work Mode', company.work_mode || '-')}
              ${profileField('Monthly Allowance', money(company.allowance))}
              ${profileField('Active Offers', String(offers.length))}
            </div></div>
            <div class="panel"><div class="panel-header"><h3><i class="fas fa-map-marker-alt" style="color:var(--gold);margin-right:8px;"></i>Contact & Location</h3></div><div class="panel-body" style="font-size:13px;display:grid;gap:14px;">
              ${profileField('Address', company.location || '-')}
              ${profileField('HR Contact Email', company.hr_email || data.user.email)}
              ${profileField('Recorded Positions', companyPositions(company))}
              ${profileField('Confirmed Interns', `${confirmedPlacements} placements`)}
            </div></div>
          </div>
          <div class="panel" style="margin-top:0;"><div class="panel-header"><h3><i class="fas fa-briefcase" style="color:var(--purple);margin-right:8px;"></i>Internship Details</h3></div><div class="panel-body">
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;text-align:center;">
              ${metricBox(companyPositionList(company).length, 'Recorded Positions', 'var(--blue3)')}
              ${metricBox(confirmedPlacements, 'Confirmed Interns', 'var(--green)')}
              ${metricBox(money(company.allowance), 'Monthly Allowance', 'var(--gold)', '18px')}
              ${metricBox(pending.length, 'Awaiting Responses', 'var(--purple)')}
            </div>
            <div style="margin-top:16px;padding:14px;background:var(--bg3);border-radius:10px;font-size:13px;color:var(--text2);line-height:1.7;">
              <strong style="color:var(--text);">About:</strong> Company data is loaded from the InternPilih database and managed by the internship coordinator.
            </div>
          </div></div>
          <div class="panel" style="margin-top:0;"><div class="panel-header"><h3><i class="fas fa-lock" style="color:var(--red);margin-right:8px;"></i>Account Settings</h3></div><div class="panel-body">
            <div class="form-row"><div class="form-group"><label class="form-label">Login Email</label><input type="email" class="form-control" value="${esc(data.user.email)}" readonly style="opacity:.7;"></div><div class="form-group"><label class="form-label">Account Role</label><input class="form-control" value="Employer" readonly style="opacity:.7;"></div></div>
          </div></div>
        `;
      }
    }
    window.viewEmployerOffer = function (id) {
      const o = offers.find(item => Number(item.id) === Number(id));
      if (!o) return;
      setText('#msgTitle, #respModalTitle', o.student_name + ' - Response Detail');
      const target = document.getElementById('msgBody') || document.getElementById('respModalBody');
      if (target) target.innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;font-size:13px;">
          <div><span style="color:var(--text2);">Student</span><br><strong>${esc(o.student_name)}</strong></div>
          <div><span style="color:var(--text2);">Status</span><br>${badge(o.status, o.status)}</div>
          <div><span style="color:var(--text2);">Position</span><br><strong>${esc(o.position)}</strong></div>
          <div><span style="color:var(--text2);">Offer Date</span><br><strong>${fmtDate(o.offer_date)}</strong></div>
        </div>
        ${o.reject_reason ? `<div style="margin-top:16px;background:var(--bg3);padding:16px;border-radius:10px;font-size:13px;">${esc(o.reject_reason)}</div>` : ''}
      `;
      (document.getElementById('msgModal') || document.getElementById('respModal'))?.classList.add('open');
    };
  }

  function infoLine(label, value, color) {
    return `<div style="display:flex;justify-content:space-between;gap:16px;"><span style="color:var(--text2);">${esc(label)}</span><strong style="${color ? `color:${color};` : ''}text-align:right;">${esc(value)}</strong></div>`;
  }

  function profileField(label, value) {
    return `<div><span style="color:var(--text2);font-size:11px;text-transform:uppercase;font-weight:600;letter-spacing:.5px;">${esc(label)}</span><p style="margin-top:3px;font-weight:600;">${esc(value)}</p></div>`;
  }

  function metricBox(value, label, color, size = '24px') {
    return `<div style="padding:16px;background:var(--bg3);border-radius:12px;"><div style="font-size:${size};font-weight:800;color:${color};">${esc(value)}</div><div style="font-size:11px;color:var(--text2);margin-top:4px;">${esc(label)}</div></div>`;
  }

  function renderCoordinatorProfile(data) {
    const u = data.user || {};
    const values = {
      coordinatorFullName: u.name || '',
      coordinatorAccountId: String(u.id || ''),
      coordinatorStaffId: u.staff_id || '',
      coordinatorEmail: u.email || '',
      coordinatorStudentCount: String((data.students || []).length),
      coordinatorFaculty: u.faculty || '',
      coordinatorProgramme: u.coordinator_programme || ''
    };
    Object.entries(values).forEach(([id, value]) => {
      const element = document.getElementById(id);
      if (element) element.value = value;
    });
    const title = document.getElementById('coordinatorProfileName');
    if (title) title.textContent = u.name || '';
    const summary = document.getElementById('coordinatorCourseSummary');
    if (summary) summary.textContent = `Course Coordinator · ${u.coordinator_programme || ''}`;
    const avatar = document.querySelector('.profile-avatar');
    if (avatar) avatar.textContent = initials(u.name);
    window.saveCoordinatorProfile = async function () {
      try {
        const result = await apiSend('/api/profile.php', 'PUT', {
          name: document.getElementById('coordinatorFullName').value.trim(),
          email: document.getElementById('coordinatorEmail').value.trim(),
          staff_id: document.getElementById('coordinatorStaffId').value.trim()
        });
        updateIdentity(result.user);
        title.textContent = result.user.name;
        if (avatar) avatar.textContent = initials(result.user.name);
        showToast('Profile saved!');
      } catch (error) {
        alert(error.message || 'Unable to save profile.');
      }
    };
  }

  async function initDatabaseBindings() {
    try {
      if (!section) return;
      if (section === 'student' && page === 'communication.html') {
        return;
      }
      if (section === 'student' && page === 'acceptance.html') {
        return;
      }
      if (section === 'student' && page === 'profile.html') {
        hookStudentProfile();
        await renderStudentProfile();
        window.addEventListener('storage', function (event) {
          if (event.key === 'internpilih:offers-updated') renderStudentProfile();
        });
        document.addEventListener('visibilitychange', function () {
          if (document.visibilityState === 'visible') renderStudentProfile();
        });
        return;
      }
      if (section === 'student' && page === 'notifications.html') {
        window.refreshStudentNotifications = renderStudentNotifications;
        await renderStudentNotifications();
        window.setInterval(renderStudentNotifications, 30000);
        document.addEventListener('visibilitychange', function () {
          if (document.visibilityState === 'visible') renderStudentNotifications();
        });
        return;
      }

      const data = await getSummary();
      if (section === 'admin' && page === 'dashboard.html') renderAdminDashboard(data);
      if (section === 'admin' && page === 'monitoring.html') await renderMonitoring(data);
      if (section === 'admin' && page === 'reports.html') renderReports(data);
      if (section === 'admin' && page === 'profile.html') renderCoordinatorProfile(data);
      if (section === 'admin' && page === 'companies.html') {
        hookAdminCompanyCrud();
        renderAdminCompanies(data);
      }
      if (section === 'admin' && page === 'verification.html') {
        hookVerification();
        renderVerification(data);
      }
      if (section === 'employer') renderEmployerPages(data);
    } catch (err) {
      console.warn('Database page binding skipped:', err.message);
    }
  }

  document.addEventListener('DOMContentLoaded', initDatabaseBindings);
})();

