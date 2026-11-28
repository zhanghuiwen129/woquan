/**
 * 钱包页：对接 /pay/wallet，展示余额 / 积分 / VIP 与收支流水。
 */
import { PAGE_PATH } from './config.js?v=20261128.01';

export async function renderWallet(app) {
    const mod = document.getElementById('page-module');
    if (!mod) return;

    if (!app.user) {
        mod.innerHTML = `
          <div class="module-head"><h1>我的钱包</h1></div>
          <div class="module-card">
            <p style="color:var(--sub);font-size:14px;line-height:1.7">登录后即可查看余额与收支明细。</p>
            <button class="btn-primary" id="walletLogin" style="margin-top:16px">去登录</button>
          </div>`;
        const login = document.getElementById('walletLogin');
        if (login) login.addEventListener('click', () => app.goLogin());
        return;
    }

    let direction = 'all';
    mod.innerHTML = `
      <div class="module-head"><h1>我的钱包</h1></div>
      <div class="mod-card" id="walletSummary"><div class="mod-empty">加载中…</div></div>
      <div class="mod-card">
        <div class="side-head"><h3>收支明细</h3><span class="side-mark">//</span></div>
        <div class="mod-tabs" id="walletTabs">
          <button type="button" class="mod-tab active" data-dir="all">全部</button>
          <button type="button" class="mod-tab" data-dir="income">收入</button>
          <button type="button" class="mod-tab" data-dir="expense">支出</button>
        </div>
        <div class="mod-list" id="walletLogs"><div class="mod-empty">加载中…</div></div>
      </div>`;

    mod.querySelectorAll('.mod-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            direction = btn.dataset.dir || 'all';
            mod.querySelectorAll('.mod-tab').forEach(b => b.classList.toggle('active', b === btn));
            load();
        });
    });

    async function load() {
        const summary = document.getElementById('walletSummary');
        const logs = document.getElementById('walletLogs');
        try {
            const data = await app.request('/pay/wallet?limit=20&direction=' + encodeURIComponent(direction));
            const bal = app.escapeHtml(String(data.balance ?? '0.00'));
            const sum = data.summary || {};
            if (summary) {
                summary.innerHTML = `
                  <div class="wallet-hero">
                    <span class="wallet-label">可用余额</span>
                    <b class="wallet-balance">¥${bal}</b>
                    <div class="wallet-stats">
                      <span>积分 ${app.escapeHtml(String(data.points ?? 0))}</span>
                      <span>VIP ${app.escapeHtml(String(data.vip_level ?? 0))}</span>
                      <span>收入 ¥${app.escapeHtml(String(sum.income ?? '0.00'))}</span>
                      <span>支出 ¥${app.escapeHtml(String(sum.expense ?? '0.00'))}</span>
                    </div>
                  </div>`;
            }
            const rows = Array.isArray(data.logs) ? data.logs : [];
            if (logs) {
                logs.innerHTML = rows.length
                    ? rows.map(r => {
                        const amount = String(r.amount ?? r.change ?? 0);
                        const cls = amount.startsWith('-') ? 'mod-amount minus' : 'mod-amount plus';
                        const label = r.title || r.description || r.type || '交易';
                        return `<div class="mod-row">
                          <div class="mod-row-main"><b>${app.escapeHtml(String(label))}</b><span>${app.escapeHtml(String(r.created_at || ''))}</span></div>
                          <b class="${cls}">${app.escapeHtml(amount)}</b>
                        </div>`;
                    }).join('')
                    : '<div class="mod-empty">暂无流水记录</div>';
            }
        } catch (e) {
            if (summary) summary.innerHTML = `<div class="mod-empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
            if (logs) logs.innerHTML = '';
        }
    }
    await load();
}
