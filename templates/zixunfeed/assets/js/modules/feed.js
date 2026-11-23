/**
 * zixunfeed 信息流模块
 * 对接 /posts/timeline、/posts/like、/follow、/favorites/toggle、/follow/suggest、/site/config
 */
import { PAGE_BASE, PAGE_PATH } from './config.js?v=20261117.02';
import { collectPostImages, makeCardNavigable, linkTag } from './utils.js?v=20261117.02';

function categoryName(app, id) {
    if (!id) return '';
    const cats = app.siteConfig?.categories || [];
    for (const c of cats) { if (c.id === id) return c.name; for (const ch of c.children || []) if (ch.id === id) return ch.name; }
    return '';
}
function colorFrom(str) {
    let h = 0; for (let i = 0; i < (str || '?').length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
    return `hsl(${h} 38% 42%)`;
}
function hueOf(str) {
    let h = 0; for (let i = 0; i < (str || '?').length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
    return h;
}
/**
 * 卡片封面：优先用帖子真实图片；无图帖生成渐变 SVG 封面（含分类/标签字样），
 * 保证「大图 / 小图」交替的视觉节奏不被纯文字卡打断。
 * 返回可直接用于 <img src> 的地址（真实 URL 或 data URI）。
 */
function escXml(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
}
function coverFor(post, app) {
    const imgs = collectPostImages(post);
    if (imgs.length) return imgs[0];
    const label = escXml(tagOf(app, post) || '资讯');
    const h = hueOf(String(post.title || post.id || ''));
    const h2 = (h + 28) % 360;
    const svg =
        `<svg xmlns='http://www.w3.org/2000/svg' width='800' height='450'>` +
        `<defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'>` +
        `<stop offset='0' stop-color='hsl(${h},45%,46%)'/>` +
        `<stop offset='1' stop-color='hsl(${h2},45%,32%)'/>` +
        `</linearGradient></defs>` +
        `<rect width='800' height='450' fill='url(#g)'/>` +
        `<text x='400' y='228' fill='rgba(255,255,255,.92)' font-size='46' font-family='PingFang SC,Microsoft YaHei,sans-serif' font-weight='700' text-anchor='middle' dominant-baseline='middle'>${label}</text>` +
        `</svg>`;
    return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
}
// 正文转纯文本：剥离 HTML 标签与 Markdown 图片/链接语法，避免摘要里出现 ![图片](url)
function plainText(content) {
    return String(content || '')
        .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
        .replace(/\[\[media:(image|video|attachment):\d+\]\]/g, '')
        .replace(/<[^>]+>/g, '')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/[*_`>#]/g, '')
        .replace(/\s+/g, ' ').trim();
}
function titleOf(post) { return post.title || plainText(post.content).slice(0, 40) || '动态'; }
function descOf(post) {
    const c = plainText(post.content);
    if (!c) return '';
    return c.length > 60 ? c.slice(0, 60) + '…' : c;
}
function tagOf(app, post) {
    const cn = categoryName(app, post.category_id);
    if (cn) return cn;
    return post.is_pinned ? '置顶' : '资讯';
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
    if (loadMore) loadMore.innerHTML = '<span class="load-more">加载中…</span>';
    const feed = document.getElementById('feed');
    // 首屏未渲染时先展示骨架，避免弱网下大片空白
    if (!append && feed && !feed.children.length) feed.innerHTML = skeletonCards();
    try {
        let url = `/posts/timeline?page=${app.page}&limit=10`;
        if (app.currentChannel && app.currentChannel !== 'all') {
            if (app.currentChannel === 'hot') url += '&sort=hot';
            else if (app.currentChannel.startsWith('cat:')) url += `&category_id=${app.currentChannel.slice(4)}`;
        }
        const data = await app.request(url);
        const list = data.list || [];
        if (!feed) { app.loading = false; return; }
        if (!append) {
            feed.innerHTML = '';
            app._heroIds = new Set(); // 首屏重建时重置轮播帖去重集合
            // 首屏：用带图动态填充轮播；用最新动态填充快讯；依后台「主题外观」开关显隐
            if (app.opt.showHero) buildHero(app, list);
            else { const h = document.getElementById('hero'); if (h) h.style.display = 'none'; }
            if (app.opt.showFlash) buildFlash(app, list);
            else { const f = document.getElementById('flash'); if (f) f.style.display = 'none'; }
            if (app.page === 1) {
                if (app.opt.showSidebar) buildSidebar(app, list);
                else { const a = document.querySelector('.aside'); if (a) a.style.display = 'none'; }
            }
        }
        const heroIds = app._heroIds || new Set();
        const renderList = append ? list : list.filter(p => !heroIds.has(p.id));
        if (!renderList.length && !append) {
            feed.innerHTML = '<div class="empty-state">该频道暂无内容，先看看其他频道吧</div>';
        } else {
            renderList.forEach((p, i) => feed.appendChild(renderCard(app, p, i)));
        }
        app.hasMore = data.pagination ? (data.pagination.page < data.pagination.pages) : false;
        if (app.hasMore) app.page++;
        if (loadMore) {
            loadMore.innerHTML = app.hasMore ? '上滑加载更多' : '没有更多内容了';
            loadMore.classList.toggle('no-more', !app.hasMore);
        }
    } catch (e) {
        app.showToast(e.message || '加载失败');
        if (!append && feed) feed.innerHTML = '<div class="empty-state">加载失败，请稍后重试</div>';
        if (loadMore) loadMore.innerHTML = '<span class="load-more">加载失败，点击重试</span>';
    } finally {
        app.loading = false;
    }
}

function skeletonCards(n = 3) {
    return Array.from({ length: n }).map(() => `
      <div class="card" aria-hidden="true">
        <div class="card-head">
          <span class="skeleton" style="width:36px;height:36px;border-radius:50%"></span>
          <div style="flex:1">
            <div class="skeleton" style="height:12px;width:32%"></div>
            <div class="skeleton" style="height:10px;width:20%;margin-top:8px"></div>
          </div>
        </div>
        <div class="skeleton" style="height:180px;margin:12px 0"></div>
        <div class="skeleton" style="height:14px;width:82%"></div>
        <div class="skeleton" style="height:12px;width:56%;margin-top:8px"></div>
      </div>`).join('');
}

export function renderCard(app, post, index = 0) {
    const el = document.createElement('article');
    el.className = 'card';
    el.dataset.id = post.id;
    const av = app.resolveAvatar(post.user?.avatar);
    const initial = (post.user?.nickname || post.user?.username || '?')[0];
    const color = post.user?.avatar ? 'transparent' : colorFrom(post.user?.username);
    const head = `
      <div class="card-head">
        <span class="avatar" style="background:${color}">${post.user?.avatar ? `<img src="${app.escapeHtml(av)}" alt="">` : app.escapeHtml(initial)}</span>
        <div><div class="author">${app.escapeHtml(post.user?.nickname || post.user?.username || '匿名')}</div>
        <div class="meta">${app.escapeHtml(post.time_ago || '')}</div></div>
        <button class="follow ${post.user?._following ? 'followed' : ''}" data-follow="${post.user?.id}">${post.user?._following ? '已关注' : '关注'}</button>
      </div>`;
    // 标题与封面都输出真实 href：爬虫能读到文章 URL 与锚文本，JS 环境下仍走 SPA 跳转
    const target = PAGE_PATH + '/p/' + post.id;
    const titleText = app.escapeHtml(titleOf(post));
    const linkText = titleText || '查看详情';
    const title = `<div class="card-title"><span class="tag">${app.escapeHtml(tagOf(app, post))}</span>${linkTag(target, linkText, 'card-title-link')}</div>`;
    const desc = descOf(post) ? `<div class="card-desc">${app.escapeHtml(descOf(post))}</div>` : '';
    const foot = cardFoot(app, post);

    let body = '';
    const isVideo = (post.videos || []).length >= 1;
    if (isVideo) {
        const v = post.videos[0];
        const dur = v.duration ? fmtDur(v.duration) : '';
        body = `<div class="video-thumb">${linkTag(target, `<img src="${app.escapeHtml(coverFor(post, app))}" alt="${linkText}" loading="lazy">`, 'card-cover-link')}
          <span class="play"><svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg></span>
          ${dur ? `<span class="duration">${dur}</span>` : ''}</div>${title}${desc}${foot}`;
    } else {
        // 统一「大图 / 小图」交替节奏（偶数位大图、奇数位侧图），无图帖用生成的渐变封面兜底，
        // 避免出现纯文字卡破坏「一个大图一个小图」的视觉节奏。
        const cover = coverFor(post, app);
        if (index % 2 === 0) {
            body = `<div class="big-thumb">${linkTag(target, `<img src="${app.escapeHtml(cover)}" alt="${linkText}" loading="lazy">`, 'card-cover-link')}</div>${title}${desc}${foot}`;
        } else {
            body = `<div class="side-body"><div class="side-thumb">${linkTag(target, `<img src="${app.escapeHtml(cover)}" alt="${linkText}" loading="lazy">`, 'card-cover-link')}</div>
              <div class="side-content">${title}${desc}${foot}</div></div>`;
        }
    }
    el.innerHTML = head + body;
    el.addEventListener('click', e => {
        if (e.target.closest('[data-like],[data-follow],[data-collect]')) return;
        // 点在真实链接上时交给链接自身处理（全局委托负责 SPA 跳转），避免重复跳转
        if (e.target.closest('a[href]')) return;
        location.href = target;
    });
    makeCardNavigable(el, target);
    // 交互
    const likeBtn = el.querySelector('[data-like]');
    if (likeBtn) likeBtn.addEventListener('click', e => { e.stopPropagation(); toggleLike(app, post, likeBtn); });
    const folBtn = el.querySelector('[data-follow]');
    if (folBtn) folBtn.addEventListener('click', e => { e.stopPropagation(); toggleFollow(app, post.user, folBtn); });
    const colBtn = el.querySelector('[data-collect]');
    if (colBtn) colBtn.addEventListener('click', e => { e.stopPropagation(); toggleCollect(app, post, colBtn); });
    return el;
}

function cardFoot(app, post) {
    const liked = post.liked ? 'liked' : '';
    const collected = post.favorited ? 'collected' : '';
    let timeText = post.time_ago || '';
    if (!timeText && post.created_at) timeText = String(post.created_at).replace('T', ' ').slice(0, 16);
    return `<div class="card-foot">
      <span class="act read" title="阅读量">${iconEye()}<span>${post.view_count || 0}</span></span>
      ${timeText ? `<span class="act time" title="发布时间">${iconClock()}<span>${app.escapeHtml(timeText)}</span></span>` : ''}
      <span class="act ${liked}" data-like>${iconLike()}<span>${post.like_count || 0}</span></span>
      <span class="act" data-comment>${iconComment()}${post.comment_count || 0}</span>
      <span class="act ${collected}" data-collect>${iconStar()}收藏</span>
    </div>`;
}
function iconEye() { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>`; }
function iconClock() { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>`; }
function iconLike() { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v10H4V10h3zm0 0l4.2-6a2.4 2.4 0 0 1 3.4 1.6L15 8h3a2 2 0 0 1 2 2.4l-1.7 8A2 2 0 0 1 16.3 20H7"/></svg>`; }
function iconComment() { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5z"/></svg>`; }
function iconStar() { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>`; }
function fmtDur(s) { const n = parseInt(s, 10) || 0; return `${String(Math.floor(n / 60)).padStart(2, '0')}:${String(n % 60).padStart(2, '0')}`; }

async function toggleLike(app, post, btn) {
    if (!app.checkAuth()) return;
    const on = btn.classList.toggle('liked');
    const span = btn.querySelector('span');
    span.textContent = (+span.textContent) + (on ? 1 : -1);
    try { await app.request('/posts/like', { method: 'POST', body: { post_id: post.id } }); }
    catch (e) { btn.classList.toggle('liked', !on); span.textContent = (+span.textContent) + (on ? -1 : 1); app.showToast(e.message); }
}
async function toggleFollow(app, user, btn) {
    if (!user) return;
    if (!app.checkAuth()) return;
    const on = btn.classList.toggle('followed');
    btn.textContent = on ? '已关注' : '关注';
    try { await app.request('/follow', { method: 'POST', body: { user_id: user.id } }); if (on) app.showToast('已关注'); }
    catch (e) { btn.classList.toggle('followed', !on); btn.textContent = on ? '关注' : '已关注'; app.showToast(e.message); }
}
async function toggleCollect(app, post, btn) {
    if (!app.checkAuth()) return;
    const on = btn.classList.toggle('collected');
    try { const d = await app.request('/favorites/toggle', { method: 'POST', body: { post_id: post.id } }); app.showToast(d?.favorited ? '已收藏' : '已取消收藏'); }
    catch (e) { btn.classList.toggle('collected', !on); app.showToast(e.message); }
}

/* ===== 首屏轮播 ===== */
function buildHero(app, list) {
    const box = document.getElementById('hero');
    if (!box) return;
    const slides = list.map(p => ({ p, cover: collectPostImages(p, 1)[0] }))
        .filter(x => !!x.cover).slice(0, 3);
    if (!slides.length) { box.style.display = 'none'; return; }
    app._heroIds = new Set(slides.map(s => s.p.id)); // 轮播展示过的帖，从信息流去重
    let idx = 0;
    box.innerHTML = slides.map((x, i) => `
      <a class="hero-slide ${i === 0 ? 'active' : ''}" data-id="${x.p.id}" href="${PAGE_PATH}/p/${x.p.id}" data-spa="1">
        <img src="${app.escapeHtml(x.cover)}" alt="${app.escapeHtml(titleOf(x.p) || '轮播推荐')}" loading="eager">
        <div class="hero-mask"></div>
        <div class="hero-text"><span class="hero-tag">${app.escapeHtml(tagOf(app, x.p))}</span>
        <div class="hero-title">${app.escapeHtml(titleOf(x.p))}</div></div>
      </a>`).join('') +
      `<div class="hero-dots">${slides.map((_, i) => `<span class="hero-dot ${i === 0 ? 'active' : ''}" data-dot="${i}"></span>`).join('')}</div>`;
    box.querySelectorAll('.hero-slide').forEach(s => {
        // a 标签自带可聚焦与回车激活，无需额外键盘绑定
        s.setAttribute('role', 'link');
    });
    box.querySelectorAll('.hero-dot').forEach(d => d.addEventListener('click', e => { e.stopPropagation(); goHero(+d.dataset.dot); }));
    function goHero(i) {
        idx = i;
        box.querySelectorAll('.hero-slide').forEach((s, k) => s.classList.toggle('active', k === i));
        box.querySelectorAll('.hero-dot').forEach((d, k) => d.classList.toggle('active', k === i));
    }
    const reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    const stop = () => {
        if (window.__heroTimer) { clearInterval(window.__heroTimer); window.__heroTimer = null; }
    };
    const start = () => {
        stop();
        if (reduce || slides.length < 2) return;
        window.__heroTimer = setInterval(() => goHero((idx + 1) % slides.length), 5000);
    };
    start();
    // 悬停 / 键盘聚焦时暂停自动轮播，避免用户阅读中被切走
    box.addEventListener('mouseenter', stop);
    box.addEventListener('mouseleave', start);
    box.addEventListener('focusin', stop);
    box.addEventListener('focusout', start);
}

/* ===== 快讯条 ===== */
function buildFlash(app, list) {
    const track = document.getElementById('flashTrack');
    if (!track) return;
    const titles = list.map(p => titleOf(p)).filter(Boolean).slice(0, 6);
    if (!titles.length) { const f = document.getElementById('flash'); if (f) f.style.display = 'none'; return; }
    track.textContent = titles.join('　·　');
}

/* ===== 侧栏：热榜 / 话题 / 作者 ===== */
export function buildSidebar(app, list) {
    // 热榜「更多」→ 完整榜单页
    const more = document.getElementById('rankMore');
    if (more) more.onclick = (e) => { e.preventDefault(); location.href = PAGE_PATH + '/ranking'; };
    // 热榜
    const rank = document.getElementById('rankList');
    if (rank) {
        const paint = (items) => {
            const hot = items.slice(0, 8);
            if (!hot.length) return;
            rank.innerHTML = hot.map((p, i) => `
              <a class="rank-item" data-id="${p.id}" href="${PAGE_PATH}/p/${p.id}" data-spa="1">
                <span class="rank-no">${i + 1}</span>
                <div><div class="rank-text">${app.escapeHtml(titleOf(p))}</div><div class="rank-hot">${p.like_count || 0} 赞</div></div>
              </a>`).join('');
        };
        // 热榜走真实榜单接口；接口失败或为空时退回当前页热文，保证侧栏不空白
        const fallback = () => paint([...list].sort((a, b) => (b.like_count || 0) - (a.like_count || 0)));
        app.request('/posts/popular?type=likes&limit=8')
            .then(d => { const items = (d && d.list) || []; items.length ? paint(items) : fallback(); })
            .catch(fallback);
    }
    // 话题 chips
    const chips = document.getElementById('topicChips');
    if (chips) {
        const cats = (app.siteConfig?.categories || []).slice(0, 10);
        chips.innerHTML = cats.length
            ? cats.map(c => `<a class="chip" data-cat="${c.id}" href="${PAGE_PATH}/c/${c.id}" data-spa="1">#${app.escapeHtml(c.name)}</a>`).join('')
            : `<a class="chip" href="${PAGE_PATH}/search" data-spa="1">#热门</a><a class="chip" href="${PAGE_PATH}/search" data-spa="1">#推荐</a><a class="chip" href="${PAGE_PATH}/search" data-spa="1">#最新</a>`;
    }
    // 关注作者
    const authors = document.getElementById('asideAuthors');
    if (authors) {
        app.request('/follow/suggest?limit=6').then(data => {
            const users = (data && data.list) || data || [];
            if (!users.length) { authors.innerHTML = '<div class="rank-hot">暂无推荐</div>'; return; }
            authors.innerHTML = users.map(u => {
                const av = app.resolveAvatar(u.avatar);
                const ini = (u.nickname || u.username || '?')[0];
                const bg = u.avatar ? 'transparent' : colorFrom(u.username);
                return `<div class="aside-author">
                  <span class="avatar" style="background:${bg}">${u.avatar ? `<img src="${app.escapeHtml(av)}" alt="">` : app.escapeHtml(ini)}</span>
                  <div class="info"><div class="author"><a href="${PAGE_PATH}/u/${encodeURIComponent(u.username || '')}" data-spa="1">${app.escapeHtml(u.nickname || u.username)}</a></div>
                  <div class="desc">${app.escapeHtml(u.bio || (u.fans ? u.fans + ' 粉丝' : '推荐作者'))}</div></div>
                  <button class="follow" data-uid="${u.id}">关注</button>
                </div>`;
            }).join('');
            authors.querySelectorAll('[data-uid]').forEach(b => b.addEventListener('click', async () => {
                if (!app.checkAuth()) return;
                const on = b.classList.toggle('followed'); b.textContent = on ? '已关注' : '关注';
                try { await app.request('/follow', { method: 'POST', body: { user_id: b.dataset.uid } }); } catch (e) { app.showToast(e.message); }
            }));
        }).catch(() => { authors.innerHTML = '<div class="rank-hot">暂无推荐</div>'; });
    }
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
