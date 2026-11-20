(function () {
  const defaults = { theme: 'light', uiSize: 'default', vision: 'standard' };
  try {
    const saved = JSON.parse(localStorage.getItem('internpilih:accessibility') || '{}');
    document.documentElement.dataset.theme = ['light', 'dark'].includes(saved.theme) ? saved.theme : defaults.theme;
    document.documentElement.dataset.uiSize = ['compact', 'default', 'large'].includes(saved.uiSize)
      ? saved.uiSize
      : ({ small: 'compact', normal: 'default', large: 'large' }[saved.textSize] || defaults.uiSize);
    document.documentElement.dataset.colorVision = ['standard', 'red-green', 'blue-yellow', 'contrast'].includes(saved.vision)
      ? saved.vision : defaults.vision;
  } catch (_) {
    document.documentElement.dataset.theme = defaults.theme;
    document.documentElement.dataset.uiSize = defaults.uiSize;
    document.documentElement.dataset.colorVision = defaults.vision;
  }
})();
