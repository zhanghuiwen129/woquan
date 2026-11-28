/**
 * 积分页：对接 /points/home 与 /points/signin，展示积分 / 签到 / 任务。
 */
import { PAGE_PATH } from './config.js?v=20261128.01';

export async function renderPoints(app) {
    const mod = document.getElementById('page-module');
    if (!mod) return;

    if (!app.user) {
        mod.innerHTML = `
          <div class="module-head"><h1>积分中心</h1></div>
          <div class="module-card">
            <p style="color:var(--sub);font-size:14px;line-height:1.7">登录后即可签到、查看积分与任务。</p>
            <button class="btn-primary" id="pointsLogin" style="margin-top:16px">去登录</button>
          </div>`;
        const login = document.getElementById('pointsLogin');
        if (login) login.addEventListener('click', () => app.goLogin());
        return;
    }

    mod.innerHTML = `
      <div class="module-head"><h1>积分中心</h1></div>
      <div class="mod-card" id="pointsSummary"><div class="mod-empty">加载中…</div></div>
      <div class="mod-card">
        <div class="side-head"><h3>每日任务</h3><span class="side-mark">//</span></div>
        <div class="mod-list" id="pointsTasks"><div class="mod-empty">加载中…</div></div>
      </div>`;

    async function load() {
        const box = document.getElementById('pointsSummary');
        const tasks = document.getElementById('pointsTasks');
        try {
            const d = await app.request('/points/home');
            if (box) {
                box.innerHTML = `
                  <div class="wallet-hero">
                    <span class="wallet-label">当前积分</span>
                    <b class="wallet-balance">${app.escapeHtml(String(d.points ?? 0))}</b>
                    <div class="wallet-stats">
                      <span>连续签到 ${app.escapeHtml(String(d.streak ?? 0))} 天</span>
                      <span>本月签到 ${app.escapeHtml(String(d.month_signed ?? 0))} 天</span>
                      <span>本月积分 ${app.escapeHtml(String(d.month_points ?? 0))}</span>
                    </div>
                    <button class="btn-primary" id="signinBtn" style="margin-top:16px" ${d.can_signin ? '' : 'disabled'}>
                      ${d.can_signin ? '立即签到' : '今日已签到'}
                    </button>
                  </div>`;
                const btn = document.getElementById('signinBtn');
                if (btn && d.can_signin) {
                    btn.addEventListener('click', async () => {
                        btn.disabled = true;
                        try {
                            const r = await app.request('/points/signin', { method: 'POST' });
                            app.showToast(r?.message || '签到成功');
                            await load();
                        } catch (e) {
                            btn.disabled = false;
                            app.showToast(e.message || '签到失败');
                        }
                    });
                }
            }
            const list = Array.isArray(d.tasks) ? d.tasks : [];
            if (tasks) {
                tasks.innerHTML = list.length
                    ? list.map(t => `<div class="mod-row">
                        <div class="mod-row-main"><b>${app.escapeHtml(String(t.name || t.title || '任务'))}</b>
                        <span>${app.escapeHtml(String(t.description || t.desc || ''))}</span></div>
                        <b class="mod-amount plus">+${app.escapeHtml(String(t.points ?? t.reward ?? 0))}</b>
                      </div>`).join('')
                    : '<div class="mod-empty">暂无任务</div>';
            }
        } catch (e) {
            if (box) box.innerHTML = `<div class="mod-empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
            if (tasks) tasks.innerHTML = '';
        }
    }
    await load();
}
