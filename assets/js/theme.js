(function () {
  let theme;
  try { theme = localStorage.getItem('jhd-theme'); } catch (_) {}
  // Light is the explicit product default; OS theme is not used for first visit.
  if (theme !== 'light' && theme !== 'dark') theme = 'light';
  document.documentElement.dataset.theme = theme;
  document.documentElement.setAttribute('data-bs-theme', theme);
})();
