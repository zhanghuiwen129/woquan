/**
 * 外链跳转页：安全校验 + 二次确认
 * 只放行 http/https 绝对 URL，拒绝 javascript:/data:/file: 与协议相对 //，
 * 避免开放重定向与 XSS（moments 主题的旧实现无协议白名单）。
 */
import { PAGE_PATH } from './config.js?v=20261128.01';

function resolveExternal(raw) {
    const s = String(raw || '').trim();
    if (!s) return null;
    let u;
    try {
        u = new URL(/^www\./i.test(s) ? 'https://' + s : s);
    } catch (e) {
        return null;
    }
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
    if (!u.hostname) return null;
    return u.href;
}

export function renderGo(app) {
    const mod = document.getElementById('page-module');
    if (!mod) return;
    const target = resolveExternal(new URLSearchParams(location.search).get('url'));
    const esc = app.escapeHtml;

    mod.innerHTML = `
      <div class="module-head">
        <button class="back-btn" style="display:flex" data-home aria-label="返回首页">←</button>
        <h1>即将离开本站</h1>
      </div>
      <div class="module-card">
        <p style="color:var(--sub);font-size:14px;line-height:1.7">你即将访问外部链接，请确认网址无误。</p>
        <p class="go-url" style="margin-top:12px;padding:12px 14px;border:1px solid var(--line);border-radius:10px;background:rgba(6,12,23,.6);color:var(--brand);font-family:var(--font-mono);font-size:12px;word-break:break-all">${target ? esc(target) : '链接无效或未提供'}</p>
        <div style="display:flex;gap:10px;margin-top:18px">
          <button class="btn-ghost" type="button" data-home>取消</button>
          <button class="btn-primary" id="goConfirm" type="button" ${target ? '' : 'disabled'}>继续访问</button>
        </div>
      </div>`;

    mod.querySelectorAll('[data-home]').forEach(btn => {
        btn.addEventListener('click', () => { location.href = PAGE_PATH + '/'; });
    });
    const confirm = document.getElementById('goConfirm');
    if (confirm && target) confirm.addEventListener('click', () => { window.location.assign(target); });
}
