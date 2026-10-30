(function () {
  if (window.__internPilihAccessibilityInstalled) return;
  window.__internPilihAccessibilityInstalled = true;

  const storageKey = 'internpilih:accessibility';
  const defaults = { theme: 'light', textSize: 'normal', vision: 'standard' };
  const allowed = {
    theme: ['light', 'dark'],
    textSize: ['normal', 'large', 'larger'],
    vision: ['standard', 'red-green', 'blue-yellow', 'contrast']
  };

  function readSettings() {
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
      return Object.fromEntries(Object.entries(defaults).map(([key, fallback]) => [
        key,
        allowed[key].includes(saved[key]) ? saved[key] : fallback
      ]));
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
    try { localStorage.setItem(storageKey, JSON.stringify(settings)); } catch (_) {}
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
      <label class="accessibility-field" for="accessibilityTextSize">
        <span>Interface size</span>
        <select id="accessibilityTextSize">
          <option value="normal">Normal</option>
          <option value="large">Large (115%)</option>
          <option value="larger">Extra large (130%)</option>
        </select>
      </label>
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

    document.body.append(launcher, panel);
    const dark = panel.querySelector('#accessibilityDark');
    const textSize = panel.querySelector('#accessibilityTextSize');
    const vision = panel.querySelector('#accessibilityVision');
    dark.checked = settings.theme === 'dark';
    textSize.value = settings.textSize;
    vision.value = settings.vision;

    function setOpen(open) {
      panel.hidden = !open;
      launcher.setAttribute('aria-expanded', String(open));
      if (open) panel.querySelector('button, input, select')?.focus();
    }
    launcher.addEventListener('click', () => setOpen(panel.hidden));
    panel.querySelector('#accessibilityClose').addEventListener('click', () => setOpen(false));
    dark.addEventListener('change', () => updateSetting('theme', dark.checked ? 'dark' : 'light'));
    textSize.addEventListener('change', () => updateSetting('textSize', textSize.value));
    vision.addEventListener('change', () => updateSetting('vision', vision.value));
    panel.querySelector('#accessibilityReset').addEventListener('click', () => {
      settings = { ...defaults };
      dark.checked = false;
      textSize.value = defaults.textSize;
      vision.value = defaults.vision;
      applySettings();
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape' && !panel.hidden) setOpen(false);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', installControls, { once: true });
  } else {
    installControls();
  }
})();
