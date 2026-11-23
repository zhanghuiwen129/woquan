// ============================================================
// theme-boot.js — 首屏同步引导（经典脚本，放 <head> 里同步执行）
// 用途：在 CSS 生效前把主题写到 <html data-theme>，避免深色/浅色切换时白屏闪烁。
// admin/.htaccess 的 CSP 是 script-src 'self'，禁止内联脚本，所以必须独立成文件。
// ============================================================
(function () {
    var t = 'light';
    try { t = localStorage.getItem('admin-theme') === 'dark' ? 'dark' : 'light'; } catch (e) {}
    document.documentElement.setAttribute('data-theme', t);
})();
