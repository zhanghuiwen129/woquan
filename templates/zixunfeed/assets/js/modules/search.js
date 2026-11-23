/**
 * zixunfeed 搜索结果页（search.html）
 * 路由：/search?q=。资讯走 /search?q=&media_type=&page=&limit=，
 * 用户走 /user/search?keyword=&limit=。
 * 页面重心是顶部大搜索框；结果区按 综合/最新/最热/视频/用户 切换。
 */
import { PAGE_BASE, PAGE_PATH } from './config.js?v=20261117.02';
import { collectPostImages, makeCardNavigable, linkTag } from './utils.js?v=20261117.02';

const state = { q: '', tab: '综合', page: 1, hasMore: true, loading: false, items: [] };

// 递增序号：切换关键词/标签时丢弃过期响应，避免旧结果覆盖新结果
let searchSeq = 0;

const FALLBACK_WORDS = ['钓鱼技巧', '装备评测', '野钓', '路亚'];

function titleOf(post) {
    return post.title || plainText(post.content).slice(0, 40) || '动态';
}
function plainText(content) {
    return String(content || '')
        .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
        .replace(/<[^>]+>/g, '')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/[*_`>#]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}
function descOf(post) {
    const c = plainText(post.content);
    return c.length > 60 ? c.slice(0, 60) + '…' : c;
}
function categoryName(app, id) {
    if (!id) return '';
    const cats = app.siteConfig?.categories || [];
    for (const c of cats) {
        if (c.id === id) return c.name;
        for (const ch of c.children || []) if (ch.id === id) return ch.name;
    }
    return '';
}
function fmtDur(s) {
    const n = parseInt(s, 10) || 0;
    return `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`;
}
function colorFrom(str) {
    let h = 0;
    for (let i = 0; i < (str || '?').length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
    return `hsl(${h} 38% 42%)`;
}
/** 转义后做关键词高亮（与 feed.js 的 .hl 风格一致） */
function hl(app, text, q) {
    const safe = app.escapeHtml(text || '');
    if (!q) return safe;
    const eq = app.escapeHtml(q);
    let re;
    try { re = new RegExp(eq.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'); }
    catch (e) { return safe; }
    return safe.replace(re, m => `<span class="hl">${m}</span>`);
}

/** 热门搜索词：优先站点分类名，无分类时用通用词兜底 */
function hotWordsOf(app) {
    const cats = (app.siteConfig?.categories || [])
        .map(c => String(c.name || '').trim())
        .filter(Boolean);
    return [...new Set(cats.length ? cats : FALLBACK_WORDS)].slice(0, 8);
}

export async function renderSearch(app) {
    const params = new URLSearchParams(location.search);
    state.q = (params.get('q') || '').trim();
    state.tab = '综合';
    state.page = 1;
    state.hasMore = true;
    state.items = [];

    const heroInput = document.getElementById('heroSearchInput');
    const topbarInput = document.getElementById('searchInput');
    if (heroInput) heroInput.value = state.q;
    if (topbarInput) topbarInput.value = state.q;
    syncClear();

    bindHeroSearch(app);
    bindTabs(app);
    bindLoadMore(app);
    bindEmpty(app);
    renderHotWords(app);
    renderRelated(app);
    renderRank(app);

    if (state.q) doSearch(app, true);
    else showEmpty(app, true);
}

function syncClear() {
    const input = document.getElementById('heroSearchInput');
    const clear = document.getElementById('heroSearchClear');
    if (clear) clear.hidden = !(input && input.value.trim());
}

function bindHeroSearch(app) {
    const form = document.getElementById('heroSearchForm');
    const input = document.getElementById('heroSearchInput');
    const clear = document.getElementById('heroSearchClear');

    if (form) {
        form.addEventListener('submit', e => {
            e.preventDefault();
            applyQuery(app, input ? input.value : '');
        });
    }
    if (input) {
        input.addEventListener('input', syncClear);
        input.addEventListener('keydown', e => {
            if (e.key === 'Escape' && input.value) {
                e.preventDefault();
                input.value = '';
                syncClear();
            }
        });
    }
    if (clear) {
        clear.addEventListener('click', () => {
            if (!input) return;
            input.value = '';
            syncClear();
            input.focus();
        });
    }
}

function setTab(tab) {
    state.tab = tab;
    const tabs = document.getElementById('tabs');
    if (!tabs) return;
    tabs.querySelectorAll('.tab').forEach(x => x.classList.toggle('active', x.dataset.tab === tab));
}

function bindTabs(app) {
    const tabs = document.getElementById('tabs');
    if (!tabs) return;
    tabs.addEventListener('click', e => {
        const t = e.target.closest('.tab');
        if (!t || t.dataset.tab === state.tab) return;
        setTab(t.dataset.tab);
        if (state.tab === '用户') loadUsers(app);
        else doSearch(app, true);
    });
}

function bindLoadMore(app) {
    const lm = document.getElementById('load-more');
    if (!lm) return;
    lm.addEventListener('click', () => { if (state.hasMore) doSearch(app, false); });
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting && state.hasMore && !state.loading) doSearch(app, false);
        }, { rootMargin: '200px' });
        io.observe(lm);
    }
}

function bindEmpty(app) {
    const all = document.getElementById('emptyAll');
    if (all) all.onclick = () => {
        setTab('综合');
        applyQuery(app, state.q);
    };
}

/** 新关键词：重置到综合页、同步两个输入框、更新地址栏后重新搜索 */
function applyQuery(app, raw) {
    const q = String(raw || '').trim();
    state.q = q;

    const heroInput = document.getElementById('heroSearchInput');
    const topbarInput = document.getElementById('searchInput');
    if (heroInput) heroInput.value = q;
    if (topbarInput) topbarInput.value = q;
    syncClear();

    setTab('综合');
    updateUrl(q);
    renderRelated(app);

    if (q) doSearch(app, true);
    else showEmpty(app, true);
}

/** 地址栏同步（replaceState：避免产生无法回退渲染的历史记录） */
function updateUrl(q) {
    const url = PAGE_PATH + '/search' + (q ? '?q=' + encodeURIComponent(q) : '');
    try { history.replaceState(null, '', url); } catch (e) { /* 忽略跨域等异常 */ }
}

async function doSearch(app, reset = false) {
    if (!reset && (state.loading || !state.hasMore)) return;
    if (reset) {
        state.page = 1;
        state.hasMore = true;
        state.items = [];
    }
    const token = ++searchSeq;
    state.loading = true;

    const feed = document.getElementById('searchFeed');
    const empty = document.getElementById('emptyState');
    const loadMore = document.getElementById('load-more');
    if (empty) empty.style.display = 'none';
    if (loadMore) { loadMore.style.display = ''; loadMore.innerHTML = reset ? '' : '加载中…'; }
    if (reset && feed) feed.innerHTML = '<div class="search-loading">正在搜索…</div>';

    let url = `/search?q=${encodeURIComponent(state.q)}&page=${state.page}&limit=10`;
    if (state.tab === '视频') url += '&media_type=video';

    try {
        const data = await app.request(url);
        if (token !== searchSeq) return;

        let list = (data && data.list) || [];
        if (state.tab === '最热') list = [...list].sort((a, b) => (b.like_count || 0) - (a.like_count || 0));

        if (reset && feed) feed.innerHTML = '';
        if (!list.length && reset) {
            showEmpty(app, false);
            return;
        }
        if (feed) list.forEach(p => feed.appendChild(buildCard(app, p)));
        state.items.push(...list);

        const total = data && data.pagination ? data.pagination.total : state.items.length;
        setMeta(`共找到 <b>${total}</b> 篇与「<b>${app.escapeHtml(state.q)}</b>」相关的内容`);
        state.hasMore = data && data.pagination ? (data.pagination.page < data.pagination.pages) : false;
        if (state.hasMore) state.page++;
        if (loadMore) {
            loadMore.innerHTML = state.hasMore ? '上滑加载更多' : '没有更多内容了';
            loadMore.classList.toggle('no-more', !state.hasMore);
        }
    } catch (e) {
        if (token !== searchSeq) return;
        app.showToast(e.message || '搜索失败');
        if (loadMore) loadMore.innerHTML = '加载失败，点击重试';
    } finally {
        if (token === searchSeq) state.loading = false;
    }
}

async function loadUsers(app) {
    if (!state.q) { showEmpty(app, true); return; }
    const token = ++searchSeq;
    state.loading = true;

    const feed = document.getElementById('searchFeed');
    const empty = document.getElementById('emptyState');
    const loadMore = document.getElementById('load-more');
    if (empty) empty.style.display = 'none';
    if (loadMore) loadMore.style.display = 'none';
    if (feed) feed.innerHTML = '<div class="search-loading">正在搜索…</div>';

    try {
        const data = await app.request(`/user/search?keyword=${encodeURIComponent(state.q)}&limit=20`);
        if (token !== searchSeq) return;

        const list = Array.isArray(data) ? data : ((data && data.list) || []);
        if (feed) feed.innerHTML = '';
        if (!list.length) {
            showEmpty(app, false, '没有找到相关用户，换个关键词试试');
            return;
        }
        if (feed) list.forEach(u => feed.appendChild(buildUserCard(app, u)));
        setMeta(`找到 <b>${list.length}</b> 位与「<b>${app.escapeHtml(state.q)}</b>」相关的用户`);
    } catch (e) {
        if (token !== searchSeq) return;
        app.showToast(e.message || '搜索失败');
        showEmpty(app, false, '搜索用户失败，请稍后重试');
    } finally {
        if (token === searchSeq) state.loading = false;
    }
}

function setMeta(html) {
    const meta = document.getElementById('resultMeta');
    if (meta) meta.innerHTML = html;
}

function buildCard(app, post) {
    const el = document.createElement('article');
    el.className = 'card';
    const vids = post.videos || [];
    const isVideo = vids.length >= 1;
    const thumb = (isVideo && vids[0] && vids[0].cover) || collectPostImages(post, 1)[0] || '';
    const dur = isVideo && vids[0] && vids[0].duration ? fmtDur(vids[0].duration) : '';
    const catName = categoryName(app, post.category_id);
    const tag = catName || (isVideo ? '视频' : '资讯');
    const author = post.user?.nickname || post.user?.username || '匿名';
    const time = post.time_ago || '';
    const title = hl(app, titleOf(post), state.q);
    const desc = hl(app, descOf(post), state.q);

    const foot = `
      <div class="card-foot">
        <span>${app.escapeHtml(author)}</span>
        <span>${app.escapeHtml(time)}</span>
        ${isVideo
            ? `<span class="type-video"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>${dur || '视频'}</span>`
            : `<span>评论 ${post.comment_count || 0}</span>`}
        <span>赞 ${post.like_count || 0}</span>
      </div>`;

    const postHref = PAGE_PATH + '/p/' + post.id;
    const thumbHtml = thumb
        ? `<div class="thumb"><a class="card-cover-link" href="${postHref}" data-spa="1"><img src="${app.escapeHtml(thumb)}" alt="${app.escapeHtml(post.title || '内容')}" loading="lazy"></a></div>`
        : '';
    el.innerHTML = `
      <div class="side-body${thumb ? '' : ' no-thumb'}">
        ${thumbHtml}
        <div class="side-content">
          <div class="card-title"><span class="tag">${app.escapeHtml(tag)}</span>${linkTag(postHref, title, 'card-title-link')}</div>
          <div class="card-desc">${desc}</div>
          ${foot}
        </div>
      </div>`;

    el.addEventListener('click', e => { if (e.target.closest('a[href]')) return; location.href = postHref; });
    makeCardNavigable(el, postHref);
    return el;
}

function buildUserCard(app, u) {
    const el = document.createElement('article');
    el.className = 'card user-result';
    const name = u.nickname || u.username || '用户';
    const handle = '@' + (u.username || '');
    const avatar = app.resolveAvatar(u.avatar);
    const initial = String(name)[0] || '?';
    const bg = u.avatar ? 'transparent' : colorFrom(u.username || name);
    const href = PAGE_PATH + '/u/' + encodeURIComponent(u.username || '');
    el.innerHTML = `
      <a class="user-result-avatar" href="${href}" data-spa="1" style="background:${bg}">
        ${u.avatar ? `<img src="${app.escapeHtml(avatar)}" alt="">` : app.escapeHtml(initial)}
      </a>
      <div class="user-result-body">
        <div class="user-result-name"><a href="${href}" data-spa="1">${hl(app, name, state.q)}</a></div>
        <div class="user-result-handle">${hl(app, handle, state.q)}</div>
      </div>
      <a class="user-result-go" href="${href}" data-spa="1">查看主页</a>`;

    el.addEventListener('click', e => {
        if (e.target.closest('a[href]')) return;
        location.href = href;
    });
    makeCardNavigable(el, href);
    return el;
}

function showEmpty(app, noQuery, message) {
    const empty = document.getElementById('emptyState');
    const feed = document.getElementById('searchFeed');
    const loadMore = document.getElementById('load-more');
    if (feed) feed.innerHTML = '';
    if (loadMore) loadMore.style.display = 'none';
    if (noQuery) setMeta('');
    else setMeta(`未找到与「<b>${app.escapeHtml(state.q)}</b>」相关的内容`);

    const txt = document.getElementById('emptyText');
    if (txt) txt.textContent = message || (noQuery ? '输入关键词，搜索全站资讯' : '没有找到相关内容，换个关键词试试');
    const all = document.getElementById('emptyAll');
    if (all) all.style.display = noQuery ? 'none' : '';
    renderEmptyWords(app, !noQuery);
    if (empty) empty.style.display = 'block';
}

/** 空态里的推荐词，点击即可直接再搜一次 */
function renderEmptyWords(app, show) {
    const box = document.getElementById('emptyWords');
    if (!box) return;
    if (!show) { box.innerHTML = ''; return; }
    const words = hotWordsOf(app).slice(0, 6);
    box.innerHTML = words.map(w => `<button type="button" class="search-hot-word">${app.escapeHtml(w)}</button>`).join('');
    box.querySelectorAll('.search-hot-word').forEach(btn => {
        btn.addEventListener('click', () => applyQuery(app, btn.textContent));
    });
}

/** 顶部热门搜索词 */
function renderHotWords(app) {
    const box = document.getElementById('searchHotWords');
    if (!box) return;
    const words = hotWordsOf(app);
    box.innerHTML = words.map(w => `<button type="button" class="search-hot-word">${app.escapeHtml(w)}</button>`).join('');
    box.querySelectorAll('.search-hot-word').forEach(btn => {
        btn.addEventListener('click', () => applyQuery(app, btn.textContent));
    });
}

/** 相关搜索：基于当前关键词派生 + 点击可重搜 */
function renderRelated(app) {
    const box = document.getElementById('relatedWords');
    if (!box) return;
    let words = [];
    const suffixes = ['攻略', '教程', '推荐', '排行'];
    if (state.q) words = [state.q].concat(suffixes.map(s => state.q + ' ' + s));
    else words = hotWordsOf(app);
    box.innerHTML = words.map(w => `<span class="hot-word" data-q="${app.escapeHtml(w)}">${app.escapeHtml(w)}</span>`).join('');
    box.querySelectorAll('.hot-word').forEach(el => {
        el.addEventListener('click', () => applyQuery(app, el.dataset.q));
    });
}

/** 大家都在搜：复用热帖接口，展示热门标题 + 点赞数 */
function renderRank(app) {
    const box = document.getElementById('rankList');
    if (!box) return;
    app.request('/posts/timeline?page=1&limit=10&sort=hot').then(d => {
        const list = (d && d.list) || [];
        if (!list.length) { box.innerHTML = '<div class="rank-hot">暂无热门</div>'; return; }
        box.innerHTML = list.slice(0, 8).map((p, i) => `
          <a class="rank-item" data-id="${p.id}" href="${PAGE_PATH}/p/${p.id}" data-spa="1">
            <span class="rank-no">${i + 1}</span>
            <div><div class="rank-text">${app.escapeHtml(titleOf(p))}</div><div class="rank-hot">${p.like_count || 0} 赞</div></div>
          </a>`).join('');
    }).catch(() => { box.innerHTML = '<div class="rank-hot">暂无热门</div>'; });
}
