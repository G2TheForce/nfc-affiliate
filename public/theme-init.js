// Applies the saved or system colour theme before first paint, so pages do
// not flash light in dark mode. Kept as a static file because the production
// CSP allows no inline scripts.
(function () {
    var saved = null;
    try {
        saved = localStorage.getItem('theme');
    } catch (e) {
        // Storage can be blocked; fall back to the system setting.
    }
    var dark = saved === 'dark' || (saved !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
})();
