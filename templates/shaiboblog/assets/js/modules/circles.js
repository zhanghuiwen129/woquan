/**
 * 圈子列表页：对接 /circles，支持关键词搜索与分页。
 */
import { PAGE_PATH } from './config.js?v=20261128.01';

export async function renderCircles(app) {
    const mod = document.getElementById('page-module');
    if (!mod) return;

    mod.innerHTML = `
      <div class="module-head"><h1>圈子</h1></div>
      <div class="mod-card">
        <div class="search-bar" style="display:flex;gap:8px">
          <input id="circleKeyword" type="search" placeholder="搜索圈子…" autocomplete="off"
            style="flex:1;min-width:0;background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:9px 12px;color:var(--ink);outline:none">
          <button class="btn-primary" id="circleSearch" type="button">搜索</button>
        </div>
      </div>
      <div class="mod-card">
        <div class="side-head"><h3>全部圈子</h3><span class="side-mark">//</span></div>
        <div class="mod-list" id="circleList"><div class="mod-empty">加载中…</div></div>
        <div class="load-more" id="circleMore">加载更多</div>
      </div>`;

    let page = 1;
    let hasMore = false;
    let keyword = '';

    async function load(append) {
        const box = document.getElementById('circleList');
        const more = document.getElementById('circleMore');
        if (!box) return;
        try {
            const d = await app.request('/circles?page=' + page + '&limit=20&keyword=' + encodeURIComponent(keyword));
            const list = (d && d.list) || [];
            if (!append) box.innerHTML = '';
            if (!list.length && !append) {
                box.innerHTML = '<div class="mod-empty">暂无圈子</div>';
            } else {
                list.forEach(c => {
                    const el = document.createElement('a');
                    el.className = 'mod-row mod-link';
                    el.href = PAGE_PATH + '/circle/' + (c.id || 0);
                    el.setAttribute('data-spa', '1');
                    el.innerHTML = `<div class="mod-row-main">
                        <b>${app.escapeHtml(String(c.name || '未命名圈子'))}</b>
                        <span>${app.escapeHtml(String(c.description || ''))}</span>
                      </div>
                      <span class="mod-badge">${app.escapeHtml(String(c.member_count ?? c.post_count ?? 0))}</span>`;
                    box.appendChild(el);
                });
            }
            const pg = d?.pagination || {};
            hasMore = Number(pg.page || 1) < Number(pg.pages || 1);
            if (more) {
                more.textContent = hasMore ? '加载更多' : '没有更多了';
                more.classList.toggle('no-more', !hasMore);
            }
        } catch (e) {
            if (!append) box.innerHTML = `<div class="mod-empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
            else app.showToast(e.message || '加载失败');
        }
    }

    const searchBtn = document.getElementById('circleSearch');
    if (searchBtn) {
        searchBtn.addEventListener('click', () => {
            keyword = (document.getElementById('circleKeyword')?.value || '').trim();
            page = 1;
            load(false);
        });
    }
    const input = document.getElementById('circleKeyword');
    if (input) {
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); searchBtn?.click(); } });
    }
    const more = document.getElementById('circleMore');
    if (more) {
        more.addEventListener('click', () => { if (hasMore) { page++; load(true); } });
    }
    await load(false);
}
