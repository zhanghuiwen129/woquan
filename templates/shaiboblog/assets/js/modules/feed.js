/**
 * shaiboblog 信息流模块
 * 对接 /posts/timeline、/posts/like、/follow、/favorites/toggle
 */
import { PAGE_PATH } from './config.js?v=20261116.12';
import { collectPostImages, makeCardNavigable, linkTag } from './utils.js?v=20261116.12';
import { fillSidebars } from './sidebar.js?v=20261116.12';

export function categoryName(app, id) {
    if (!id) return '';
    const cats = app.siteConfig?.categories || [];
    for (const c of cats) {
        if (String(c.id) === String(id)) return c.name;
        for (const ch of c.children || []) if (String(ch.id) === String(id)) return ch.name;
    }
    return '';
}

function plainText(content) {
    return String(content || '')
        .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
        .replace(/\[\[media:(image|video|attachment):\d+\]\]/g, '')
        .replace(/<[^>]+>/g, '')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/[*_`>#]/g, '')
        .replace(/\s+/g, ' ').trim();
}
function titleOf(post) { return post.title || plainText(post.content).slice(0, 40) || '无题'; }
function descOf(post) {
    const c = plainText(post.content);
    if (!c) return '';
    return c.length > 96 ? c.slice(0, 96) + '…' : c;
}
function tagsOf(post) {
    const raw = Array.isArray(post.tags) ? post.tags : [];
    return raw.map(t => (typeof t === 'string' ? t : (t && (t.name || t.title)) || '')).filter(Boolean).slice(0, 3);
}
function dateOf(post) {
    const raw = post.time_ago || post.created_at || '';
    return String(raw).replace('T', ' ').slice(0, 16);
}

export function reloadFeed(app) {
    app.page = 1; app.hasMore = true; app.loading = false;
    const feed = document.getElementById('feed');
    if (feed) feed.innerHTML = '';
    loadFeed(app, false);
}

export async function loadFeed(app, append = false) {
    if (app.loading || !app.hasMore) return;
    app.loading = true;
    const loadMore = document.getElementById('load-more');
    if (loadMore) loadMore.innerHTML = '加载中…';
    const feed = document.getElementById('feed');
    if (!append && feed && !feed.children.length) feed.innerHTML = skeletonCards();
    try {
        const url = `/posts/timeline?page=${app.page}&limit=10`;
        const data = await app.request(url);
        const list = data.list || [];
        if (data.pagination && data.pagination.total) app._postTotal = data.pagination.total;
        if (!feed) { app.loading = false; return; }
        if (!append) {
            feed.innerHTML = '';
            if (!list.length) feed.innerHTML = '<div class="empty-state">还没有文章，去写第一篇吧</div>';
        }
        list.forEach(p => feed.appendChild(renderCard(app, p)));
        if (!append) fillSidebars(app, list);
        app.hasMore = data.pagination ? (data.pagination.page < data.pagination.pages) : false;
        if (app.hasMore) app.page++;
        if (loadMore) {
            loadMore.innerHTML = app.hasMore ? '上滑加载更多' : '没有更多内容了';
            loadMore.classList.toggle('no-more', !app.hasMore);
        }
    } catch (e) {
        app.showToast(e.message || '加载失败');
        if (!append && feed) feed.innerHTML = '<div class="empty-state">加载失败，请稍后重试</div>';
        if (loadMore) loadMore.innerHTML = '加载失败，点击重试';
    } finally {
        app.loading = false;
    }
}

function skeletonCards(n = 3) {
    return Array.from({ length: n }).map(() => `
      <article class="post skeleton-post" aria-hidden="true">
        <div class="post-main">
          <div class="skeleton" style="height:12px;width:18%;margin-bottom:14px"></div>
          <div class="skeleton" style="height:20px;width:78%"></div>
          <div class="skeleton" style="height:12px;width:92%;margin-top:12px"></div>
          <div class="skeleton" style="height:12px;width:64%;margin-top:8px"></div>
        </div>
        <div class="skeleton post-thumb-skeleton"></div>
      </article>`).join('');
}

export function renderCard(app, post, index = 0) {
    const el = document.createElement('article');
    el.className = 'post';
    el.dataset.id = post.id;
    const target = PAGE_PATH + '/p/' + post.id;
    const titleText = app.escapeHtml(titleOf(post));
    const catName = categoryName(app, post.category_id);
    const cat = catName
        ? `<a class="post-cat" href="${PAGE_PATH}/c/${post.category_id}" data-spa="1">${app.escapeHtml(catName)}</a>`
        : '';
    const pins = [
        post.is_pinned ? '<span class="post-flag pin">置顶</span>' : '',
        post.is_original ? '<span class="post-flag orig">原创</span>' : '',
    ].join('');
    const tags = tagsOf(post).map(t =>
        `<a class="post-tag" href="${PAGE_PATH}/search?q=${encodeURIComponent(t)}" data-spa="1">#${app.escapeHtml(t)}</a>`
    ).join('');
    const desc = descOf(post);
    const cover = collectPostImages(post)[0];
    const author = post.user?.nickname || post.user?.username || '匿名';
    const authorLink = post.user?.username
        ? `<a class="post-author" href="${PAGE_PATH}/u/${encodeURIComponent(post.user.username)}" data-spa="1">${app.escapeHtml(author)}</a>`
        : `<span class="post-author">${app.escapeHtml(author)}</span>`;
    const liked = post.liked ? ' liked' : '';
    const collected = post.favorited ? ' collected' : '';

    el.innerHTML = `
      <div class="post-main">
        <div class="post-top">${cat}${pins}</div>
        <h2 class="post-title">${linkTag(target, titleText, 'post-title-link')}</h2>
        ${desc ? `<p class="post-excerpt">${app.escapeHtml(desc)}</p>` : ''}
        ${tags ? `<div class="post-tags">${tags}</div>` : ''}
        <div class="post-meta">
          ${authorLink}
          <span class="post-dot">·</span>
          <time>${app.escapeHtml(dateOf(post))}</time>
          <span class="post-stat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>${post.view_count || 0}</span>
          <a class="post-stat" href="${target}#comments" data-spa="1"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5z"/></svg>${post.comment_count || 0}</a>
          <button class="post-act${liked}" type="button" data-like aria-label="点赞">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v10H4V10h3zm0 0l4.2-6a2.4 2.4 0 0 1 3.4 1.6L15 8h3a2 2 0 0 1 2 2.4l-1.7 8A2 2 0 0 1 16.3 20H7"/></svg>
            <span>${post.like_count || 0}</span>
          </button>
          <button class="post-act${collected}" type="button" data-collect aria-label="收藏">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
          </button>
        </div>
      </div>
      ${cover ? `<a class="post-thumb" href="${target}" data-spa="1"><img src="${app.escapeHtml(cover)}" alt="${titleText}" loading="lazy"></a>` : ''}
    `;

    el.addEventListener('click', e => {
        if (e.target.closest('[data-like],[data-collect]')) return;
        if (e.target.closest('a[href]')) return;
        location.href = target;
    });
    makeCardNavigable(el, target);

    const likeBtn = el.querySelector('[data-like]');
    if (likeBtn) likeBtn.addEventListener('click', e => { e.stopPropagation(); toggleLike(app, post, likeBtn); });
    const colBtn = el.querySelector('[data-collect]');
    if (colBtn) colBtn.addEventListener('click', e => { e.stopPropagation(); toggleCollect(app, post, colBtn); });
    return el;
}

async function toggleLike(app, post, btn) {
    if (!app.checkAuth()) return;
    const on = btn.classList.toggle('liked');
    const span = btn.querySelector('span');
    if (span) span.textContent = (+span.textContent) + (on ? 1 : -1);
    try {
        await app.request('/posts/like', { method: 'POST', body: { post_id: post.id } });
    } catch (e) {
        btn.classList.toggle('liked', !on);
        if (span) span.textContent = (+span.textContent) + (on ? -1 : 1);
        app.showToast(e.message);
    }
}

async function toggleCollect(app, post, btn) {
    if (!app.checkAuth()) return;
    const on = btn.classList.toggle('collected');
    try {
        const d = await app.request('/favorites/toggle', { method: 'POST', body: { post_id: post.id } });
        app.showToast(d?.favorited ? '已收藏' : '已取消收藏');
    } catch (e) {
        btn.classList.toggle('collected', !on);
        app.showToast(e.message);
    }
}

export function buildSidebar(app, list) {
    fillSidebars(app, list || []);
}

export function setupInfiniteScroll(app) {
    const sentinel = document.getElementById('load-more');
    if (!sentinel) return;
    sentinel.addEventListener('click', () => { if (!sentinel.classList.contains('no-more')) loadFeed(app, true); });
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting) loadFeed(app, true);
        }, { rootMargin: '160px' });
        io.observe(sentinel);
    }
}

/**
 * 封面加载失败兜底：渐变 SVG（对齐预览页 coverSvg）。
 * error 事件不冒泡，用 capture 全局监听，同时覆盖 SSR 首屏与动态渲染的卡片。
 */
function coverSvg(label, hue) {
    const h2 = (hue + 28) % 360;
    const safe = String(label ?? '').replace(/[&<>"']/g, ch => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
    ));
    const svg = "<svg xmlns='http://www.w3.org/2000/svg' width='800' height='450'>"
        + "<defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'>"
        + "<stop offset='0' stop-color='hsl(" + hue + ",45%,38%)'/>"
        + "<stop offset='1' stop-color='hsl(" + h2 + ",45%,24%)'/>"
        + "</linearGradient></defs>"
        + "<rect width='800' height='450' fill='url(#g)'/>"
        + "<text x='400' y='228' fill='rgba(255,255,255,.92)' font-size='46' "
        + "font-family='PingFang SC,Microsoft YaHei,sans-serif' font-weight='700' "
        + "text-anchor='middle' dominant-baseline='middle'>" + safe + "</text></svg>";
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}

document.addEventListener('error', (e) => {
    const img = e.target;
    if (!img || img.tagName !== 'IMG' || img.dataset.cyCoverFallback) return;
    if (typeof img.closest !== 'function' || !img.closest('.post-thumb')) return;
    const card = img.closest('.post');
    const label = card?.querySelector('.post-cat')?.textContent?.trim() || 'WOQUAN';
    const id = Number(card?.dataset?.id) || 1;
    img.dataset.cyCoverFallback = '1';
    img.src = coverSvg(label, (id * 47 + 168) % 360);
}, true);
