(function () {
  const defaults = { theme: 'light', textSize: 'normal', vision: 'standard' };
  try {
    const saved = JSON.parse(localStorage.getItem('internpilih:accessibility') || '{}');
    document.documentElement.dataset.theme = saved.version === 2 && saved.theme === 'dark' ? 'dark' : defaults.theme;
    document.documentElement.dataset.textSize = ['small', 'normal', 'large'].includes(saved.textSize)
      ? saved.textSize : defaults.textSize;
    document.documentElement.dataset.colorVision = ['standard', 'red-green', 'blue-yellow', 'contrast'].includes(saved.vision)
      ? saved.vision : defaults.vision;
  } catch (_) {
    document.documentElement.dataset.theme = defaults.theme;
    document.documentElement.dataset.textSize = defaults.textSize;
    document.documentElement.dataset.colorVision = defaults.vision;
  }
})();
