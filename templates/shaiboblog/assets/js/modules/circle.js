/**
 * 圈子详情页：对接 /circles/detail、/circles/posts、/circles/join。
 */
import { PAGE_PATH } from './config.js?v=20261128.01';

export async function renderCircle(app, id) {
    const mod = document.getElementById('page-module');
    if (!mod || !id) return;

    mod.innerHTML = `
      <div class="module-head">
        <button class="back-btn" style="display:flex" data-back-circle aria-label="返回圈子列表">←</button>
        <h1>圈子详情</h1>
      </div>
      <div class="mod-card" id="circleHead"><div class="mod-empty">加载中…</div></div>
      <div class="mod-card">
        <div class="side-head"><h3>圈子动态</h3><span class="side-mark">//</span></div>
        <div class="mod-list" id="circlePosts"><div class="mod-empty">加载中…</div></div>
      </div>`;

    document.querySelector('[data-back-circle]')?.addEventListener('click', () => {
        location.href = PAGE_PATH + '/circles';
    });

    try {
        const d = await app.request('/circles/detail?id=' + encodeURIComponent(id));
        const head = document.getElementById('circleHead');
        if (head) {
            const joined = !!d.is_member;
            head.innerHTML = `
              <div class="wallet-hero">
                <span class="wallet-label">${app.escapeHtml(String(d.name || '圈子'))}</span>
                <p style="margin:10px 0;color:var(--sub);font-size:13px;line-height:1.7">${app.escapeHtml(String(d.description || ''))}</p>
                <div class="wallet-stats">
                  <span>成员 ${app.escapeHtml(String(d.member_count ?? 0))}</span>
                  <span>动态 ${app.escapeHtml(String(d.post_count ?? 0))}</span>
                </div>
                ${app.user ? `<button class="btn-primary" id="circleJoin" style="margin-top:16px" ${joined || d.is_owner ? 'disabled' : ''}>
                  ${joined || d.is_owner ? '已加入' : '加入圈子'}
                </button>` : ''}
              </div>`;
            const btn = document.getElementById('circleJoin');
            if (btn && !joined && !d.is_owner) {
                btn.addEventListener('click', async () => {
                    btn.disabled = true;
                    try {
                        await app.request('/circles/join', { method: 'POST', body: { id: Number(id) } });
                        app.showToast('已加入圈子');
                        btn.textContent = '已加入';
                    } catch (e) {
                        btn.disabled = false;
                        app.showToast(e.message || '加入失败');
                    }
                });
            }
        }
    } catch (e) {
        const head = document.getElementById('circleHead');
        if (head) head.innerHTML = `<div class="mod-empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
    }

    try {
        const d = await app.request('/circles/posts?id=' + encodeURIComponent(id) + '&page=1&limit=20');
        const list = (d && d.list) || [];
        const box = document.getElementById('circlePosts');
        if (box) {
            box.innerHTML = list.length
                ? list.map(p => `<a class="mod-row mod-link" href="${PAGE_PATH}/p/${p.id || 0}" data-spa="1">
                    <div class="mod-row-main"><b>${app.escapeHtml(String(p.title || (p.content || '').slice(0, 40) || '动态'))}</b>
                    <span>${app.escapeHtml(String(p.created_at || ''))}</span></div>
                  </a>`).join('')
                : '<div class="mod-empty">暂无动态</div>';
        }
    } catch (e) {
        const box = document.getElementById('circlePosts');
        if (box) box.innerHTML = `<div class="mod-empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
    }
}
