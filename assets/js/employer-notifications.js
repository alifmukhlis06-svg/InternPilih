function ensureNotifEmptyState(list) {
  let empty = document.getElementById('notifEmptyState');
  if (empty) return empty;
  empty = document.createElement('div');
  empty.id = 'notifEmptyState';
  empty.className = 'empty-state';
  empty.innerHTML = '<i class="fas fa-inbox" aria-hidden="true"></i><p>No notifications match this filter.</p>';
  list.appendChild(empty);
  return empty;
}

function filterNotif(type, button) {
  document.querySelectorAll('.tab-btn').forEach(tab => tab.classList.toggle('active', tab === button));
  const list = document.getElementById('notifList');
  if (!list || list.dataset.loadError === 'true') return;
  const empty = ensureNotifEmptyState(list);
  let visible = 0;
  list.querySelectorAll('.notif-item').forEach(item => {
    const matches = type === 'all' || (item.dataset.type || '').includes(type);
    item.style.display = matches ? 'flex' : 'none';
    if (matches) visible++;
  });
  const messages = {
    all: 'No notifications yet.',
    unread: 'No unread notifications.',
    system: 'No system notifications.',
    admin: 'No coordinator notifications.'
  };
  empty.querySelector('p').textContent = messages[type] || 'No notifications match this filter.';
  empty.hidden = visible > 0;
}

function updateNotifCounts() {
  const items = Array.from(document.querySelectorAll('#notifList .notif-item'));
  const counts = {
    all: items.length,
    unread: items.filter(item => (item.dataset.type || '').includes('unread')).length,
    system: items.filter(item => (item.dataset.type || '').includes('system')).length,
    admin: items.filter(item => (item.dataset.type || '').includes('admin')).length
  };
  const labels = { all: 'All', unread: 'Unread', system: 'System', admin: 'From Coordinator' };
  document.querySelectorAll('.tab-btn').forEach(tab => {
    const type = tab.dataset.filter || 'all';
    tab.textContent = `${labels[type]} (${counts[type] || 0})`;
  });
}

async function markAllRead(event) {
  const button = event.currentTarget;
  button.disabled = true;
  try {
    const response = await fetch('../api/notifications.php', { method: 'PUT' });
    if (!response.ok) throw new Error('Unable to update notifications.');
    if (window.refreshRecipientNotifications) await window.refreshRecipientNotifications();
    button.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i><span>All Read</span>';
  } catch (error) {
    window.alert(error.message || 'Unable to update notifications.');
  } finally {
    window.setTimeout(() => {
      button.innerHTML = '<i class="fas fa-check-double" aria-hidden="true"></i><span>Mark All Read</span>';
      button.disabled = false;
    }, 2000);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const list = document.getElementById('notifList');
  if (!list) return;
  const sync = () => {
    updateNotifCounts();
    const active = document.querySelector('.tab-btn.active') || document.querySelector('[data-filter="all"]');
    if (active) filterNotif(active.dataset.filter || 'all', active);
  };
  sync();
  new MutationObserver(sync).observe(list, { childList: true });
});
