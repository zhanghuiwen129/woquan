/**
 * zixunfeed 视频专区页（video.html）
 * 路由：/video。对接 /posts/timeline?media=video 与 /app/categories。
 */
import { PAGE_BASE, PAGE_PATH } from './config.js?v=20261117.02';

const state = { cat: '', page: 1, hasMore: true, loading: false, first: true };

function titleOf(p) { return p.title || (p.content || '').replace(/<[^>]+>/g, '').slice(0, 40) || '视频动态'; }
function coverOf(p) {
    const vids = p.videos || [], imgs = p.images || [];
    return (vids[0] && vids[0].cover) || imgs[0] || '';
}
function durOf(p) {
    const v = (p.videos || [])[0];
    const n = parseInt(v && v.duration, 10) || 0;
    if (!n) return '视频';
    return `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;
}
function colorFrom(s) {
    let h = 0; for (let i = 0; i < (s || '?').length; i++) h = (h * 31 + s.charCodeAt(i)) % 360;
    return `hsl(${h} 38% 42%)`;
}
const ICON_LIKE = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v10H4V10h3zm0 0l4.2-6a2.4 2.4 0 0 1 3.4 1.6L15 8h3a2 2 0 0 1 2 2.4l-1.7 8A2 2 0 0 1 16.3 20H7"/></svg>`;
const ICON_COMMENT = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5z"/></svg>`;

export async function renderVideo(app) {
    state.cat = '';
    state.page = 1;
    state.hasMore = true;
    state.first = true;
    document.title = '视频 · ' + (app.opt.brandName || 'woquan');
    bindTabs(app);
    bindLoadMore(app);
    bindFeature(app);
    await renderTabs(app);
    await loadVideos(app, true);
}

async function renderTabs(app) {
    const box = document.getElementById('vTabs');
    if (!box) return;
    let cats = [];
    try {
        const d = await app.request('/app/categories');
        cats = (d && d.list) || [];
    } catch (e) { cats = (app.siteConfig?.categories || []); }
    box.innerHTML = '<button class="v-tab active" data-cat="">推荐</button>'
        + cats.map(c => `<button class="v-tab" data-cat="${c.id}">${app.escapeHtml(c.name)}</button>`).join('');
}

function bindTabs(app) {
    const box = document.getElementById('vTabs');
    if (!box) return;
    box.addEventListener('click', e => {
        const t = e.target.closest('.v-tab'); if (!t) return;
        box.querySelectorAll('.v-tab').forEach(x => x.classList.remove('active'));
        t.classList.add('active');
        state.cat = t.dataset.cat || '';
        state.first = true;
        loadVideos(app, true);
    });
}
function bindLoadMore(app) {
    const lm = document.getElementById('load-more');
    if (lm) lm.onclick = () => { if (state.hasMore) loadVideos(app, false); };
}
function bindFeature(app) {
    const f = document.getElementById('feature');
    if (f) f.onclick = () => {
        const id = f.dataset.id;
        if (id) location.href = PAGE_PATH + '/p/' + id;
    };
}

async function loadVideos(app, reset) {
    if (state.loading || !state.hasMore) return;
    if (reset) { state.page = 1; state.hasMore = true; }
    state.loading = true;
    const grid = document.getElementById('vGrid');
    const lm = document.getElementById('load-more');
    const feature = document.getElementById('feature');
    if (reset && grid) grid.innerHTML = '<div class="grid-empty">加载中…</div>';
    if (lm) lm.style.display = 'none';
    if (reset && feature) feature.style.display = 'none';

    let url = `/posts/timeline?media=video&page=${state.page}&limit=12`;
    if (state.cat) url += `&category_id=${state.cat}`;
    try {
        const d = await app.request(url);
        const list = (d && d.list) || [];
        if (reset && grid) grid.innerHTML = '';

        if (reset) {
            const vc = document.getElementById('videoCount');
            if (vc) vc.textContent = (d && d.pagination && d.pagination.total) ? `共 ${d.pagination.total} 个视频` : '持续更新';
        }

        if (state.first && list.length) {
            const p = list[0];
            const img = document.getElementById('featureImg');
            const title = document.getElementById('featureTitle');
            const dur = document.getElementById('featureDur');
            if (img) { const c = coverOf(p); if (c) img.src = c; }
            if (title) title.textContent = titleOf(p);
            if (dur) dur.textContent = durOf(p);
            if (feature) { feature.dataset.id = p.id; feature.style.display = ''; }
            state.first = false;
        }

        const rest = state.page === 1 && list.length ? list.slice(1) : list;
        if (!rest.length && state.page === 1) {
            if (grid && !grid.children.length) grid.innerHTML = '<div class="grid-empty">暂无视频内容，去首页看看图文资讯吧</div>';
        } else {
            rest.forEach(p => { if (grid) grid.appendChild(buildCard(app, p)); });
        }
        state.hasMore = d && d.pagination ? (d.pagination.page < d.pagination.pages) : false;
        if (state.hasMore) state.page++;
        if (lm && state.hasMore) { lm.style.display = ''; lm.textContent = '加载更多'; }
    } catch (e) {
        if (grid && reset) grid.innerHTML = `<div class="grid-empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
        else app.showToast(e.message || '加载失败');
    } finally {
        state.loading = false;
    }
}

function buildCard(app, p) {
    const el = document.createElement('article');
    el.className = 'v-card';
    const u = p.user || {};
    const name = u.nickname || u.username || '匿名';
    const av = u.avatar;
    const bg = av ? 'transparent' : colorFrom(u.username || name);
    el.innerHTML = `
      <div class="v-thumb">
        ${coverOf(p) ? `<img src="${app.escapeHtml(coverOf(p))}" alt="" loading="lazy">` : ''}
        <span class="v-play"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></span>
        <span class="duration">${app.escapeHtml(durOf(p))}</span>
      </div>
      <div class="v-info">
        <div class="v-title"><a href="${PAGE_PATH}/p/${p.id}" data-spa="1">${app.escapeHtml(titleOf(p))}</a></div>
        <div class="v-meta">
          <span class="v-avatar" style="background:${bg}">${av ? `<img src="${app.escapeHtml(app.resolveAvatar(av))}" alt="">` : app.escapeHtml(name[0] || '?')}</span>
          <span class="name">${app.escapeHtml(name)}</span>
          <span class="stat">${ICON_LIKE}${p.like_count || 0}</span>
          <span class="stat">${ICON_COMMENT}${p.comment_count || 0}</span>
        </div>
      </div>`;
    el.addEventListener('click', () => { location.href = PAGE_PATH + '/p/' + p.id; });
    return el;
}
