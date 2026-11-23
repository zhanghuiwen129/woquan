/**
 * zixunfeed 频道聚合页（category.html）
 * 路由：/c/{id}。对接 /posts/timeline?category_id=&sort= + /app/categories
 * 卡片复用 feed.renderCard；侧栏复用 feed.buildSidebar。
 */
import * as feed from './feed.js?v=20261117.02';
import { PAGE_BASE } from './config.js?v=20261117.02';

let catCache = null;
async function loadCategories(app) {
    if (catCache) return catCache;
    try {
        const d = await app.request('/app/categories');
        catCache = (d && d.list) || [];
    } catch (e) { catCache = []; }
    return catCache;
}

export async function renderCategory(app, catId) {
    if (!catId) {
        const m = location.pathname.match(/\/c\/(\d+)/);
        catId = m ? m[1] : null;
    }
    app._catId = catId;
    app._catSort = 'latest';
    app._catSub = '';
    app._catPage = 1;
    app._catLoading = false;
    app._catHasMore = true;

    const cats = await loadCategories(app);
    const cat = cats.find(c => String(c.id) === String(catId)) || null;
    fillHead(app, cat);
    renderSubChips(app, cats.filter(c => Number(c.parent_id) === Number(catId)));
    bindFilters(app);

    const feed = document.getElementById('feed');
    if (feed) { feed.innerHTML = ''; loadFeed(app, false); feed.buildSidebarOnce = false; }
}

function fillHead(app, cat) {
    const name = document.getElementById('chanName');
    const desc = document.getElementById('chanDesc');
    const stats = document.getElementById('chanStats');
    const cover = document.getElementById('chanCover');
    if (name) name.textContent = cat ? cat.name : '全部资讯';
    if (desc) desc.textContent = cat && cat.description ? cat.description : '聚合该频道下的全部内容';
    const baseStats = cat ? `<b>${cat.post_count || '—'}</b> 篇文章` : '实时聚合全站资讯';
    if (stats) stats.innerHTML = baseStats;
    if (cover) cover.innerHTML = cat && cat.cover
        ? `<img src="${app.escapeHtml(cat.cover)}" alt="">`
        : '';
    // 标题写入文档（SEO 已由后端 {{PAGE_TITLE}} 处理）
    const btn = document.getElementById('chanFollow');
    const catId = cat && cat.id;
    if (btn) {
        // V95：关注态与关注人数都以来自后端的 /category/status 为准，点击走 /category/follow 切换
        const applyState = (following, count) => {
            btn.classList.toggle('followed', !!following);
            btn.textContent = following ? '已关注' : '关注';
            if (count !== null && count !== undefined && stats) {
                stats.innerHTML = baseStats + ` · <b>${count}</b> 人关注`;
            }
        };
        if (catId) {
            app.request(`/category/status?category_id=${catId}`)
                .then(d => applyState(d && d.following, d && d.follower_count))
                .catch(() => {});
        }
        btn.onclick = async (e) => {
            e.stopPropagation();
            if (!app.checkAuth()) return;
            if (!catId) { app.showToast('频道信息缺失'); return; }
            btn.disabled = true;
            try {
                const d = await app.request('/category/follow', { method: 'POST', body: { category_id: catId } });
                applyState(d && d.following, d && d.follower_count);
                app.showToast((d && d.following) ? '已关注频道，新文章会通知你' : '已取消关注');
            } catch (err) {
                app.showToast(err.message || '操作失败');
            } finally {
                btn.disabled = false;
            }
        };
    }
}

function renderSubChips(app, children) {
    const box = document.getElementById('subChips');
    if (!box) return;
    let html = '<button class="chip active" data-sub="">全部</button>';
    if (children.length) {
        html += children.map(c => `<button class="chip" data-sub="${c.id}">${app.escapeHtml(c.name)}</button>`).join('');
    }
    box.innerHTML = html;
}

function bindFilters(app) {
    const sub = document.getElementById('subChips');
    if (sub) sub.addEventListener('click', e => {
        const c = e.target.closest('.chip'); if (!c) return;
        sub.querySelectorAll('.chip').forEach(x => x.classList.remove('active'));
        c.classList.add('active');
        app._catSub = c.dataset.sub || '';
        app._catPage = 1; app._catHasMore = true;
        const f = document.getElementById('feed'); if (f) f.innerHTML = '';
        loadFeed(app, false);
    });
    const sort = document.getElementById('sortGroup');
    if (sort) sort.addEventListener('click', e => {
        const s = e.target.closest('.sort'); if (!s) return;
        sort.querySelectorAll('.sort').forEach(x => x.classList.remove('active'));
        s.classList.add('active');
        app._catSort = s.dataset.sort || 'latest';
        app._catPage = 1; app._catHasMore = true;
        const f = document.getElementById('feed'); if (f) f.innerHTML = '';
        loadFeed(app, false);
    });
}

export async function loadFeed(app, append = false) {
    if (app._catLoading || !app._catHasMore) return;
    app._catLoading = true;
    const loadMore = document.getElementById('load-more');
    if (loadMore) loadMore.innerHTML = '<span class="load-more">加载中…</span>';
    try {
        let url = `/posts/timeline?page=${app._catPage}&limit=10`;
        if (app._catId) url += `&category_id=${app._catId}`;
        if (app._catSub) url += `&category_id=${app._catSub}`;
        if (app._catSort === 'hot') url += '&sort=hot';
        const data = await app.request(url);
        let list = (data && data.list) || [];
        if (app._catSort === 'comment') {
            list = [...list].sort((a, b) => (b.comment_count || 0) - (a.comment_count || 0));
        }
        const feedEl = document.getElementById('feed');
        if (!append && feedEl) {
            feedEl.innerHTML = '';
            if (!list.length) feedEl.innerHTML = '<div class="empty-state">该频道暂无内容，先看看其他频道吧</div>';
        }
        if (feedEl) list.forEach(p => feedEl.appendChild(feed.renderCard(app, p)));
        if (!append) feed.buildSidebar(app, list);
        app._catHasMore = data && data.pagination ? (data.pagination.page < data.pagination.pages) : false;
        if (app._catHasMore) app._catPage++;
        if (loadMore) {
            loadMore.innerHTML = app._catHasMore ? '上滑加载更多' : '没有更多内容了';
            loadMore.classList.toggle('no-more', !app._catHasMore);
        }
    } catch (e) {
        app.showToast(e.message || '加载失败');
        if (loadMore) loadMore.innerHTML = '<span class="load-more">加载失败，点击重试</span>';
    } finally {
        app._catLoading = false;
    }
}

/** 侧栏（热文 + 作者），随首屏调用一次 */
export function buildSidebar(app, list) {
    feed.buildSidebar(app, list || []);
}
