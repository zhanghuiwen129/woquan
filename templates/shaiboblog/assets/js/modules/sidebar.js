/**
 * shaiboblog 公共侧栏：分类 / 归档 / 最新文章 / 标签云 / 首页统计
 * 所有列表页共用；posts 为空时回退拉取一页时间线，避免侧栏空白。
 */
import { PAGE_PATH } from './config.js?v=20261116.12';

let cachePosts = null;
let cachePromise = null;
let cacheCounts = null;
let cacheCountsPromise = null;

const CAT_COUNT_KEY = 'shaibo_cat_counts';
const CAT_COUNT_TTL = 5 * 60 * 1000;

function monthKey(value) {
    const s = String(value || '');
    const m = s.match(/^(\d{4})[-/](\d{1,2})/);
    if (m) return m[1] + '-' + String(m[2]).padStart(2, '0');
    const d = new Date(value);
    if (!isNaN(d.getTime())) return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    return '';
}

function monthLabel(key) {
    const [y, m] = key.split('-');
    return `${y} 年 ${Number(m)} 月`;
}

function titleOf(post) {
    return post.title || String(post.content || '').replace(/<[^>]+>/g, '').slice(0, 30) || '无题';
}

function tagsOf(post) {
    const raw = Array.isArray(post.tags) ? post.tags : [];
    return raw.map(t => (typeof t === 'string' ? t : (t && (t.name || t.title)) || '')).filter(Boolean);
}

function dateLabel(post) {
    const raw = post.created_at || post.time_ago || '';
    const s = String(raw).replace('T', ' ');
    return s.length >= 10 ? s.slice(5, 10) : s;
}

export async function fetchSidebarPosts(app) {
    if (cachePosts) return cachePosts;
    if (cachePromise) return cachePromise;
    cachePromise = app.request('/posts/timeline?page=1&limit=18')
        .then(d => { cachePosts = (d && d.list) || []; return cachePosts; })
        .catch(() => { cachePosts = []; return cachePosts; })
        .finally(() => { cachePromise = null; });
    return cachePromise;
}

function flattenCats(list, out = []) {
    for (const c of list || []) {
        if (!c || !c.name) continue;
        out.push(c);
        if (Array.isArray(c.children) && c.children.length) flattenCats(c.children, out);
    }
    return out;
}

async function loadCategories(app) {
    const local = flattenCats((app.siteConfig && app.siteConfig.categories) || []);
    if (local.length) return local;
    try {
        const d = await app.request('/app/categories');
        return flattenCats((d && d.list) || []);
    } catch (e) { return []; }
}

/**
 * 分类文章数：公开接口不返回 post_count，逐分类取分页 total 补齐，
 * 结果缓存在 sessionStorage，避免翻页时重复请求。
 */
async function loadCategoryCounts(app, cats) {
    if (cacheCounts) return cacheCounts;
    if (cacheCountsPromise) return cacheCountsPromise;
    cacheCountsPromise = (async () => {
        try {
            const raw = sessionStorage.getItem(CAT_COUNT_KEY);
            if (raw) {
                const saved = JSON.parse(raw);
                if (saved && Date.now() - saved.t < CAT_COUNT_TTL) {
                    cacheCounts = saved.v || {};
                    return cacheCounts;
                }
            }
        } catch (e) { /* 隐私模式下 sessionStorage 可能不可用 */ }

        const out = {};
        await Promise.all((cats || []).slice(0, 8).map(async c => {
            if (typeof c.post_count === 'number') { out[c.id] = c.post_count; return; }
            try {
                const d = await app.request(`/posts/timeline?page=1&limit=1&category_id=${c.id}`);
                const total = d && d.pagination ? d.pagination.total : undefined;
                if (typeof total === 'number') out[c.id] = total;
            } catch (e) { /* 单个分类失败不影响其他分类 */ }
        }));
        cacheCounts = out;
        try { sessionStorage.setItem(CAT_COUNT_KEY, JSON.stringify({ t: Date.now(), v: out })); } catch (e) {}
        return out;
    })().finally(() => { cacheCountsPromise = null; });
    return cacheCountsPromise;
}

export async function fillSidebars(app, posts) {
    const hasPosts = Array.isArray(posts) && posts.length > 0;
    const [cats, list] = await Promise.all([
        loadCategories(app),
        hasPosts ? Promise.resolve(posts) : fetchSidebarPosts(app),
    ]);
    renderCategories(app, cats, cacheCounts || {});
    // 分类计数需要额外请求，先渲染占位再异步补齐，避免侧栏整体等待
    loadCategoryCounts(app, cats).then(counts => renderCategories(app, cats, counts)).catch(() => {});
    renderArchives(app, list);
    renderRecent(app, list);
    renderTags(app, list, cats);
    renderHeroStats(app, cats, list);
}

function renderCategories(app, cats, counts = {}) {
    const box = document.getElementById('catList');
    if (!box) return;
    const countOf = c => (typeof counts[c.id] === 'number' ? counts[c.id] : '—');
    const items = (cats || []).filter(c => c && c.name).slice(0, 8);
    if (!items.length) {
        box.innerHTML = '<div class="side-empty">暂无分类</div>';
        return;
    }
    box.innerHTML = items.map(c => `
      <a class="cat-item" href="${PAGE_PATH}/c/${c.id}" data-spa="1">
        <span class="cat-name"><i></i>${app.escapeHtml(c.name)}</span>
        <span class="cat-count">${countOf(c)}</span>
      </a>`).join('');
}

function renderArchives(app, posts) {
    const box = document.getElementById('archiveList');
    if (!box) return;
    const map = new Map();
    (posts || []).forEach(p => {
        const key = monthKey(p.created_at || p.time_ago);
        if (!key) return;
        map.set(key, (map.get(key) || 0) + 1);
    });
    const items = [...map.entries()].sort((a, b) => (a[0] < b[0] ? 1 : -1)).slice(0, 6);
    if (!items.length) {
        box.innerHTML = '<div class="side-empty">暂无归档</div>';
        return;
    }
    box.innerHTML = items.map(([key, count]) => `
      <div class="archive-item">
        <span>${monthLabel(key)}</span>
        <b>${count}</b>
      </div>`).join('');
}

function renderRecent(app, posts) {
    const box = document.getElementById('recentList');
    if (!box) return;
    const items = [...(posts || [])]
        .sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')))
        .slice(0, 5);
    if (!items.length) {
        box.innerHTML = '<div class="side-empty">暂无文章</div>';
        return;
    }
    box.innerHTML = items.map((p, i) => `
      <a class="latest-item" href="${PAGE_PATH}/p/${p.id}" data-spa="1">
        <span class="latest-no">${String(i + 1).padStart(2, '0')}</span>
        <span class="latest-title">${app.escapeHtml(titleOf(p))}</span>
        <time>${app.escapeHtml(dateLabel(p))}</time>
      </a>`).join('');
}

function renderTags(app, posts, cats) {
    const box = document.getElementById('tagCloud');
    if (!box) return;
    const counter = new Map();
    (posts || []).forEach(p => tagsOf(p).forEach(t => counter.set(t, (counter.get(t) || 0) + 1)));
    if (!counter.size) {
        (cats || []).slice(0, 10).forEach(c => counter.set(c.name, c.post_count || 1));
    }
    const items = [...counter.entries()].sort((a, b) => b[1] - a[1]).slice(0, 18);
    if (!items.length) {
        box.innerHTML = '<div class="side-empty">暂无标签</div>';
        return;
    }
    box.innerHTML = items.map(([tag, count]) => {
        const weight = count >= 3 ? ' hot' : count === 2 ? ' warm' : '';
        return `<a class="cloud-tag${weight}" href="${PAGE_PATH}/search?q=${encodeURIComponent(tag)}" data-spa="1">#${app.escapeHtml(tag)}</a>`;
    }).join('');
}

function renderHeroStats(app, cats, posts) {
    const postEl = document.getElementById('heroPostCount');
    const catEl = document.getElementById('heroCatCount');
    const tagEl = document.getElementById('heroTagCount');
    if (postEl) postEl.textContent = String(app._postTotal || (posts || []).length || '—');
    if (catEl) catEl.textContent = String((cats || []).length || '—');
    if (tagEl) {
        const set = new Set();
        (posts || []).forEach(p => tagsOf(p).forEach(t => set.add(t)));
        tagEl.textContent = String(set.size || (cats || []).length || '—');
    }
}
