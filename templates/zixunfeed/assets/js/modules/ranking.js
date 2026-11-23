/**
 * zixunfeed 热榜排行榜页（ranking.html）
 * 路由：/ranking。对接 /posts/popular、/posts/timeline?sort=hot、/topics。
 */
import { PAGE_BASE, PAGE_PATH } from './config.js?v=20261117.02';
import { makeCardNavigable } from './utils.js?v=20261117.02';

const state = { board: '', label: '全站热榜', loading: false };

function titleOf(p) { return p.title || (p.content || '').replace(/<[^>]+>/g, '').slice(0, 40) || '动态'; }
function coverOf(p) {
    const imgs = p.images || [], vids = p.videos || [];
    return imgs[0] || (vids[0] && vids[0].cover) || '';
}
function catName(app, id) {
    if (!id) return '资讯';
    const cats = app.siteConfig?.categories || [];
    for (const c of cats) { if (c.id === id) return c.name; for (const ch of c.children || []) if (ch.id === id) return ch.name; }
    return '资讯';
}
function fmtNum(n) {
    n = Number(n) || 0;
    return n >= 10000 ? (n / 10000).toFixed(1).replace(/\.0$/, '') + '万' : String(n);
}

export async function renderRanking(app) {
    state.board = '';
    state.label = '全站热榜';
    document.title = '热榜 · ' + (app.opt.brandName || 'woquan');
    const time = document.getElementById('boardTime');
    if (time) time.textContent = '实时更新 · ' + new Date().toTimeString().slice(0, 5);
    await renderTabs(app);
    bindTabs(app);
    bindList(app);
    renderAside(app);
    loadBoard(app);
}

async function renderTabs(app) {
    const box = document.getElementById('boardTabs');
    if (!box) return;
    let cats = [];
    try { const d = await app.request('/app/categories'); cats = (d && d.list) || []; }
    catch (e) { cats = app.siteConfig?.categories || []; }
    box.innerHTML = '<button class="board-tab active" data-board="" data-label="全站热榜">全站热榜</button>'
        + cats.map(c => `<button class="board-tab" data-board="${c.id}" data-label="${app.escapeHtml(c.name)}热榜">${app.escapeHtml(c.name)}</button>`).join('')
        + '<button class="board-tab" data-board="video" data-label="视频热榜">视频</button>';
}
function bindTabs(app) {
    const box = document.getElementById('boardTabs');
    if (!box) return;
    box.addEventListener('click', e => {
        const t = e.target.closest('.board-tab'); if (!t) return;
        box.querySelectorAll('.board-tab').forEach(x => x.classList.remove('active'));
        t.classList.add('active');
        state.board = t.dataset.board || '';
        state.label = t.dataset.label || '热榜';
        loadBoard(app);
    });
}
function bindList(app) {
    const box = document.getElementById('boardList');
    if (!box) return;
    box.addEventListener('click', e => {
        const it = e.target.closest('.rank-item');
        if (it && it.dataset.id && it.tagName !== 'A') location.href = PAGE_PATH + '/p/' + it.dataset.id;
    });
}

async function loadBoard(app) {
    if (state.loading) return;
    state.loading = true;
    const box = document.getElementById('boardList');
    const title = document.getElementById('boardTitle');
    if (title) title.textContent = state.label;
    if (box) box.innerHTML = '<div class="empty-state">加载中…</div>';

    let url = '/posts/popular?type=likes&limit=20';
    if (state.board === 'video') url = '/posts/timeline?media=video&sort=hot&page=1&limit=20';
    else if (state.board) url = `/posts/timeline?category_id=${state.board}&sort=hot&page=1&limit=20`;

    try {
        const d = await app.request(url);
        let list = (d && d.list) || [];
        // 按热度（赞 + 评论 * 2）重排，保证榜单语义
        list = [...list].sort((a, b) => hot(b) - hot(a));
        if (!list.length) { if (box) box.innerHTML = '<div class="empty-state">该榜单暂无内容</div>'; state.loading = false; return; }
        const sub = document.getElementById('boardSub');
        if (sub) sub.textContent = `综合热度排序 · 共 ${list.length} 条`;
        if (box) box.innerHTML = list.map((p, i) => {
            const cover = coverOf(p);
            const up = (p.like_count || 0) >= (p.comment_count || 0);
            return `<a class="rank-item${i < 3 ? ` top top-${i + 1}` : ''}" data-id="${p.id}" href="${PAGE_PATH}/p/${p.id}" data-spa="1">
              <span class="rank-no">${i + 1}</span>
              <div class="rank-main">
                <div class="rank-title">${app.escapeHtml(titleOf(p))}</div>
                <div class="rank-sub">
                  <span>${app.escapeHtml(catName(app, p.category_id))} · ${p.comment_count || 0} 条讨论</span>
                  <span class="trend ${up ? '' : 'down'}">${up ? '▲ 上升' : '▼ 下降'}</span>
                  <span>热度 ${fmtNum(hot(p))}</span>
                </div>
              </div>
              <div class="rank-thumb">${cover ? `<img src="${app.escapeHtml(cover)}" alt="${app.escapeHtml(titleOf(p))}" loading="lazy">` : ''}</div>
            </a>`;
        }).join('');
    } catch (e) {
        if (box) box.innerHTML = `<div class="empty-state">${app.escapeHtml(e.message || '加载失败')}</div>`;
    } finally {
        state.loading = false;
    }
}
function hot(p) { return (p.like_count || 0) + (p.comment_count || 0) * 2 + (p.view_count || 0); }

function renderAside(app) {
    const rise = document.getElementById('riseList');
    if (rise) {
        app.request('/posts/popular?type=comments&limit=5').then(d => {
            const list = (d && d.list) || [];
            rise.innerHTML = list.length
                ? list.map((p, i) => `<a class="rise-item" data-id="${p.id}" href="${PAGE_PATH}/p/${p.id}" data-spa="1">
                     <span class="rise-no">${i + 1}</span>
                     <span class="rise-text">${app.escapeHtml(titleOf(p))}</span>
                     <span class="rise-num">+${p.comment_count || 0}</span></a>`).join('')
                : '<div class="rank-hot">暂无飙升内容</div>';
        }).catch(() => { rise.innerHTML = '<div class="rank-hot">暂无飙升内容</div>'; });
    }
    const chips = document.getElementById('topicChips');
    if (chips) {
        app.request('/topics?limit=8').then(d => {
            const list = (d && d.list) || [];
            chips.innerHTML = list.length
                ? list.map(t => `<a class="chip" data-name="${app.escapeHtml(t.name)}" href="${PAGE_PATH}/topic/${encodeURIComponent(t.name)}" data-spa="1">#${app.escapeHtml(t.name)}</a>`).join('')
                : ['#热门', '#推荐', '#最新'].map(t => `<a class="chip" data-name="${t.slice(1)}" href="${PAGE_PATH}/topic/${encodeURIComponent(t.slice(1))}" data-spa="1">${t}</a>`).join('');
        }).catch(() => { chips.innerHTML = '<span class="chip">#热门</span>'; });
    }
}
