(function () {
  if (window.__internPilihAccessibilityInstalled) return;
  window.__internPilihAccessibilityInstalled = true;

  const storageKey = 'internpilih:accessibility';
  const settingsVersion = 2;
  const defaults = { theme: 'light', textSize: 'normal', vision: 'standard' };
  const allowed = {
    theme: ['light', 'dark'],
    textSize: ['small', 'normal', 'large'],
    vision: ['standard', 'red-green', 'blue-yellow', 'contrast']
  };

  function readSettings() {
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
      return {
        theme: saved.version === settingsVersion && allowed.theme.includes(saved.theme) ? saved.theme : defaults.theme,
        textSize: allowed.textSize.includes(saved.textSize) ? saved.textSize : defaults.textSize,
        vision: allowed.vision.includes(saved.vision) ? saved.vision : defaults.vision
      };
    } catch (_) {
      return { ...defaults };
    }
  }

  let settings = readSettings();

  function applySettings() {
    const root = document.documentElement;
    root.dataset.theme = settings.theme;
    root.dataset.textSize = settings.textSize;
    root.dataset.colorVision = settings.vision;
    try { localStorage.setItem(storageKey, JSON.stringify({ ...settings, version: settingsVersion })); } catch (_) {}
  }

  function updateSetting(key, value) {
    if (!allowed[key].includes(value)) return;
    settings[key] = value;
    applySettings();
  }

  applySettings();

  function installControls() {
    if (!document.body || document.getElementById('accessibilityLauncher')) return;
    const launcher = document.createElement('button');
    launcher.id = 'accessibilityLauncher';
    launcher.className = 'accessibility-launcher';
    launcher.type = 'button';
    launcher.setAttribute('aria-label', 'Open accessibility settings');
    launcher.setAttribute('aria-expanded', 'false');
    launcher.setAttribute('aria-controls', 'accessibilityPanel');
    launcher.innerHTML = '<span aria-hidden="true">Aa</span>';

    const themeToggle = document.createElement('button');
    themeToggle.id = 'themeToggle';
    themeToggle.className = 'theme-toggle';
    themeToggle.type = 'button';

    const panel = document.createElement('section');
    panel.id = 'accessibilityPanel';
    panel.className = 'accessibility-panel';
    panel.setAttribute('aria-label', 'Accessibility settings');
    panel.hidden = true;
    panel.innerHTML = `
      <div class="accessibility-panel-head">
        <strong>Accessibility</strong>
        <button type="button" id="accessibilityClose" aria-label="Close accessibility settings">&times;</button>
      </div>
      <label class="accessibility-switch">
        <span>Dark mode</span>
        <input type="checkbox" id="accessibilityDark">
      </label>
      <div class="accessibility-field">
        <span>Font size</span>
        <div class="font-size-controls" role="group" aria-label="Font size">
          <button type="button" data-text-size="small" aria-label="Decrease font size">A&minus;</button>
          <button type="button" data-text-size="normal" aria-label="Default font size">A</button>
          <button type="button" data-text-size="large" aria-label="Increase font size">A+</button>
        </div>
      </div>
      <label class="accessibility-field" for="accessibilityVision">
        <span>Colour assistance</span>
        <select id="accessibilityVision">
          <option value="standard">Standard colours</option>
          <option value="red-green">Red–green friendly</option>
          <option value="blue-yellow">Blue–yellow friendly</option>
          <option value="contrast">High contrast</option>
        </select>
      </label>
      <button type="button" class="accessibility-reset" id="accessibilityReset">Reset settings</button>`;

    const themeHost = document.querySelector('.topbar-right, .nav-links');
    if (themeHost) themeHost.appendChild(themeToggle);
    else {
      themeToggle.classList.add('floating');
      document.body.appendChild(themeToggle);
    }
    document.body.append(launcher, panel);
    const dark = panel.querySelector('#accessibilityDark');
    const textSizeButtons = Array.from(panel.querySelectorAll('[data-text-size]'));
    const vision = panel.querySelector('#accessibilityVision');
    dark.checked = settings.theme === 'dark';
    vision.value = settings.vision;

    function syncThemeControls() {
      const darkMode = settings.theme === 'dark';
      dark.checked = darkMode;
      themeToggle.textContent = darkMode ? '☀' : '☾';
      themeToggle.setAttribute('aria-label', darkMode ? 'Use light mode' : 'Use dark mode');
      themeToggle.setAttribute('title', darkMode ? 'Light mode' : 'Dark mode');
    }
    syncThemeControls();

    function syncTextSizeButtons() {
      textSizeButtons.forEach(button => {
        button.setAttribute('aria-pressed', String(button.dataset.textSize === settings.textSize));
      });
    }
    syncTextSizeButtons();

    function setOpen(open) {
      panel.hidden = !open;
      launcher.setAttribute('aria-expanded', String(open));
      if (open) panel.querySelector('button, input, select')?.focus();
    }
    launcher.addEventListener('click', () => setOpen(panel.hidden));
    panel.querySelector('#accessibilityClose').addEventListener('click', () => setOpen(false));
    themeToggle.addEventListener('click', () => {
      updateSetting('theme', settings.theme === 'dark' ? 'light' : 'dark');
      syncThemeControls();
    });
    dark.addEventListener('change', () => {
      updateSetting('theme', dark.checked ? 'dark' : 'light');
      syncThemeControls();
    });
    textSizeButtons.forEach(button => button.addEventListener('click', () => {
      updateSetting('textSize', button.dataset.textSize);
      syncTextSizeButtons();
    }));
    vision.addEventListener('change', () => updateSetting('vision', vision.value));
    panel.querySelector('#accessibilityReset').addEventListener('click', () => {
      settings = { ...defaults };
      dark.checked = false;
      vision.value = defaults.vision;
      applySettings();
      syncThemeControls();
      syncTextSizeButtons();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !panel.hidden) setOpen(false);
    });
  }

  function installBrandAssets() {
    const logoUrl = typeof window.appUrl === 'function'
      ? window.appUrl('/assets/images/internpilih-logo.png')
      : 'assets/images/internpilih-logo.png';
    document.querySelectorAll('.sidebar-logo .icon, .recovery-mark').forEach(container => {
      container.classList.add('brand-logo-frame');
      container.innerHTML = `<img class="brand-logo-image" src="${logoUrl}" alt="InternPilih">`;
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      installBrandAssets();
      installControls();
    }, { once: true });
  } else {
    installBrandAssets();
    installControls();
  }
})();
