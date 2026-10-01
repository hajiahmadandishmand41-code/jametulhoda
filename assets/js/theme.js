(function () {
  let theme;
  try { theme = localStorage.getItem('jhd-theme'); } catch (_) {}
  if (theme !== 'light' && theme !== 'dark') theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.dataset.theme = theme;
  document.documentElement.setAttribute('data-bs-theme', theme);
})();
