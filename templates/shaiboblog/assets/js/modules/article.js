/**
 * shaiboblog 文章详情模块
 * 对接 /posts/detail、/posts/like、/favorites/toggle、/follow、/posts/comment、/posts/comment-like、/posts/timeline
 */
import { PAGE_BASE, PAGE_PATH } from './config.js?v=20261116.12';
import { collectPostImages, makeCardNavigable, safeUrl, escapeAttr } from './utils.js?v=20261116.12';

function colorFrom(str) {
    let h = 0; for (let i = 0; i < (str || '?').length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
    return `hsl(${h} 38% 42%)`;
}

/**
 * 图片去重用的归一化 key：去掉协议、域名、hash，只保留路径（含 query）。
 * 同一张图在正文里是相对路径 /uploads/x.jpg，在 images 数组里常被后端补成
 * http://host/uploads/x.jpg，直接比字符串去不掉，会导致封面 + 正文显示两张。
 */
function normalizeImgKey(url) {
    return String(url || '').trim()
        .replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]*/i, '')
        .replace(/^\/\/[^/]*/, '')
        .split('#')[0];
}
function categoryName(app, id) {
    if (!id) return '';
    const cats = app.siteConfig?.categories || [];
    for (const c of cats) {
        if (String(c.id) === String(id)) return c.name;
        for (const ch of c.children || []) if (String(ch.id) === String(id)) return ch.name;
    }
    return '';
}

/**
 * 把正文里的 [[media:<type>:<index>]] 替换成播放器 / 附件卡片。
 * 必须在 renderMarkdown 之后调用：占位符是纯文本，marked / DOMPurify 原样保留；
 * URL 只从接口下发的 videos / attachments 按 index 取，绝不解析正文里的地址。
 */
function renderMediaTokens(html, post, app) {
    const videos = Array.isArray(post.videos) ? post.videos : [];
    const attachments = Array.isArray(post.attachments) ? post.attachments : [];
    const humanSize = (n) => {
        const v = Number(n || 0);
        if (v < 1024) return v + ' B';
        if (v < 1048576) return (v / 1024).toFixed(1) + ' KB';
        return (v / 1048576).toFixed(1) + ' MB';
    };
    return String(html || '').replace(/\[\[media:(image|video|attachment):(\d+)\]\]/g, (m, type, idx) => {
        if (type === 'image') return m;
        const n = Number(idx);
        if (type === 'video') {
            const v = videos[n];
            const url = v && !v.blocked ? safeUrl(v.url) : '';
            if (!url) return '<span class="media-fallback">视频不可用</span>';
            const poster = v.cover && safeUrl(v.cover) ? ` poster="${escapeAttr(safeUrl(v.cover))}"` : '';
            return `<div class="media-video"><video controls preload="metadata" playsinline src="${escapeAttr(url)}"${poster}>您的浏览器不支持视频播放</video></div>`;
        }
        const a = attachments[n];
        const url = a && !a.blocked ? safeUrl(a.url) : '';
        if (!url) return '<span class="media-fallback">附件不可用</span>';
        const name = app.escapeHtml(a.filename || '下载附件');
        const meta = a.size ? ` <span class="att-size">${humanSize(a.size)}</span>` : '';
        return `<div class="media-attachment"><a href="${escapeAttr(url)}" download target="_blank" rel="noopener noreferrer">附件：${name}${meta}</a></div>`;
    });
}

export async function renderArticle(app, postId) {
    const host = document.getElementById('articleHost');
    if (!host) return;
    // 服务端已渲染首屏（标题/作者/正文）时先保留，避免「加载中」闪烁，
    // 接口返回后再整体替换为完整版（互动数据、评论、相关推荐）。
    const hasSSR = !!host.querySelector('.content');
    if (!hasSSR) host.innerHTML = '<div class="empty-state">加载中…</div>';
    let post;
    try {
        post = await app.request(`/posts/detail?id=${postId}`);
    } catch (e) {
        host.innerHTML = `<div class="empty-state">文章不存在或已被删除<br><br><button class="btn-ghost" data-home>返回首页</button></div>`;
        return;
    }
    if (!post) { host.innerHTML = '<div class="empty-state">文章不存在</div>'; return; }

    const av = app.resolveAvatar(post.user?.avatar);
    const ini = (post.user?.nickname || post.user?.username || '?')[0];
    const bg = post.user?.avatar ? 'transparent' : colorFrom(post.user?.username);
    const meta = [post.time_ago, post.view_count ? `阅读 ${post.view_count}` : '', post.location].filter(Boolean).join(' · ');
    // 图片有两种来源：编辑器文章把图内嵌在 Markdown 正文里，社交动态把图放在 images 数组里。
    // 先取正文内嵌图做去重基准，再把 images 里没出现在正文的图全部补齐，避免只显示第一张。
    const inlineImages = collectPostImages({ content: post.content || '' }, 0);
    const inlineKeys = new Set(inlineImages.map(normalizeImgKey));
    const allImages = collectPostImages({ images: Array.isArray(post.images) ? post.images : [] }, 0)
        .filter(u => !inlineKeys.has(normalizeImgKey(u)));
    const cover = allImages[0]
        ? `<figure class="cover"><img src="${app.escapeHtml(allImages[0])}" alt=""></figure>` : '';
    const gallery = allImages.length > 1
        ? `<div class="content-gallery">${allImages.slice(1).map(u =>
            `<figure><img src="${app.escapeHtml(u)}" alt=""></figure>`).join('')}</div>`
        : '';
    const body = app.renderMarkdown ? app.renderMarkdown(post.content || '', PAGE_PATH) : app.escapeHtml(post.content || '');
    const content = renderMediaTokens(body, post, app) + gallery;
    const catName = categoryName(app, post.category_id);
    const rawTags = (Array.isArray(post.tags) ? post.tags : [])
        .map(t => (typeof t === 'string' ? t : (t && (t.name || t.title)) || ''))
        .filter(Boolean);
    const postTags = rawTags.length ? rawTags : (catName ? [catName] : []);
    const plain = String(post.content || '').replace(/<[^>]+>/g, '').replace(/!\[[^\]]*\]\([^)]*\)/g, '');
    const readMin = Math.max(1, Math.ceil(plain.length / 400));
    const dateText = String(post.time_ago || post.created_at || '').replace('T', ' ').slice(0, 16);

    host.innerHTML = `
      <nav class="breadcrumb" aria-label="面包屑">
        <a href="${PAGE_PATH}/" data-home>首页</a><span>/</span>
        ${catName ? `<a href="${PAGE_PATH}/c/${post.category_id}" data-spa="1">${app.escapeHtml(catName)}</a><span>/</span>` : ''}
        <span>正文</span>
      </nav>
      <div class="post-top article-badges">
        ${catName ? `<a class="post-cat" href="${PAGE_PATH}/c/${post.category_id}" data-spa="1">${app.escapeHtml(catName)}</a>` : ''}
        ${post.is_pinned ? '<span class="post-flag pin">置顶</span>' : ''}
        ${post.is_original ? '<span class="post-flag orig">原创</span>' : ''}
      </div>
      <h1 class="title">${app.escapeHtml(post.title || plain.slice(0, 40) || '无题')}</h1>
      <div class="article-meta-row">
        <span class="avatar" style="background:${bg}">${post.user?.avatar ? `<img src="${app.escapeHtml(av)}" alt="">` : app.escapeHtml(ini)}</span>
        <div class="article-byline">
          <div class="author-name">${app.escapeHtml(post.user?.nickname || post.user?.username || '匿名')}</div>
          <div class="author-meta">${app.escapeHtml(dateText)} · 阅读 ${post.view_count || 0} · 约 ${readMin} 分钟</div>
        </div>
        <button class="follow ${post.user?.is_following ? 'followed' : ''}" data-follow="${post.user?.id}">${post.user?.is_following ? '已关注' : '关注'}</button>
      </div>
      ${cover}
      <div class="content article-body">${content}</div>
      ${postTags.length ? `<div class="post-tags article-tags">${postTags.map(t =>
        `<a class="post-tag" href="${PAGE_PATH}/search?q=${encodeURIComponent(t)}" data-spa="1">#${app.escapeHtml(t)}</a>`).join('')}</div>` : ''}
      <div class="action-bar">
        <button class="action-btn ${post.liked ? 'liked' : ''}" data-like>${iconLike()}<span>${post.like_count || 0}</span></button>
        <button class="action-btn" data-scroll="comments">${iconComment()}<span>${post.comment_count || 0}</span></button>
        <button class="action-btn ${post.favorited ? 'collected' : ''}" data-collect>${iconStar()}收藏</button>
        <button class="action-btn share" data-share>分享</button>
      </div>
      <div class="author-card">
        <span class="avatar" style="background:${bg}">${post.user?.avatar ? `<img src="${app.escapeHtml(av)}" alt="">` : app.escapeHtml(ini)}</span>
        <div class="info"><div class="author-name">${app.escapeHtml(post.user?.nickname || post.user?.username || '匿名')}</div>
        <div class="desc">${app.escapeHtml(post.user?.bio || '该作者还没有简介')}</div></div>
        <button class="follow ${post.user?.is_following ? 'followed' : ''}" data-follow="${post.user?.id}">${post.user?.is_following ? '已关注' : '关注'}</button>
      </div>
      <section class="comments" id="comments">
        <div class="comment-head"><h3>评论 ${post.comment_count || 0}</h3>
          <button class="comment-tab active" data-tab="hot">最热</button>
          <button class="comment-tab" data-tab="new">最新</button></div>
        <div class="comment-input">
          <span class="avatar" style="background:${bg}">${app.user?.avatar ? `<img src="${app.escapeHtml(app.resolveAvatar(app.user.avatar))}" alt="">` : app.escapeHtml((app.user?.nickname || '我')[0])}</span>
          <input type="text" id="commentInput" placeholder="写下你的看法…">
          <button class="comment-send" id="commentSend">发送</button>
        </div>
        <div class="comment-list" id="commentList"></div>
      </section>`;

    bindActions(app, post);
    // 评论排序：后端 /posts/detail 不支持 sort，前端按点赞数/时间本地排序
    let commentSort = 'hot';
    const sortedComments = () => {
        const arr = Array.isArray(post.comments) ? [...post.comments] : [];
        if (commentSort === 'new') {
            return arr.sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || '')));
        }
        return arr.sort((a, b) => (b.like_count || 0) - (a.like_count || 0));
    };
    renderComments(app, sortedComments());
    document.querySelectorAll('#articleHost .comment-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            commentSort = tab.dataset.tab === 'new' ? 'new' : 'hot';
            document.querySelectorAll('#articleHost .comment-tab').forEach(t => t.classList.toggle('active', t === tab));
            renderComments(app, sortedComments());
        });
    });
    bindCommentSend(app, post);
    loadAside(app, post);
    app.refreshSidebars().catch(() => {});
}

function bindActions(app, post) {
    const like = document.querySelector('#articleHost [data-like]');
    if (like) like.addEventListener('click', async () => {
        if (!app.checkAuth()) return;
        const on = like.classList.toggle('liked');
        const span = like.querySelector('span'); span.textContent = (+span.textContent) + (on ? 1 : -1);
        try { await app.request('/posts/like', { method: 'POST', body: { post_id: post.id } }); }
        catch (e) { like.classList.toggle('liked', !on); span.textContent = (+span.textContent) + (on ? -1 : 1); app.showToast(e.message); }
    });
    const col = document.querySelector('#articleHost [data-collect]');
    if (col) col.addEventListener('click', async () => {
        if (!app.checkAuth()) return;
        const on = col.classList.toggle('collected');
        try { const d = await app.request('/favorites/toggle', { method: 'POST', body: { post_id: post.id } }); app.showToast(d?.favorited ? '已收藏' : '已取消收藏'); }
        catch (e) { col.classList.toggle('collected', !on); app.showToast(e.message); }
    });
    const share = document.querySelector('#articleHost [data-share]');
    if (share) share.addEventListener('click', () => {
        const url = location.href;
        if (navigator.clipboard) navigator.clipboard.writeText(url).then(() => app.showToast('链接已复制')).catch(() => app.showToast('分享：' + url));
        else app.showToast('分享：' + url);
    });
    const scroll = document.querySelector('#articleHost [data-scroll="comments"]');
    if (scroll) scroll.addEventListener('click', () => document.getElementById('comments')?.scrollIntoView({ behavior: 'smooth' }));
    document.querySelectorAll('#articleHost [data-follow]').forEach(b => b.addEventListener('click', async () => {
        if (!app.checkAuth()) return;
        const on = b.classList.toggle('followed'); b.textContent = on ? '已关注' : '关注';
        try { await app.request('/follow', { method: 'POST', body: { user_id: b.dataset.follow } }); if (on) app.showToast('已关注'); }
        catch (e) { b.classList.toggle('followed', !on); b.textContent = on ? '关注' : '已关注'; app.showToast(e.message); }
    }));
}

function renderComments(app, list) {
    const box = document.getElementById('commentList');
    if (!box) return;
    if (!list.length) { box.innerHTML = '<div class="rank-hot">还没有评论，来抢沙发～</div>'; return; }
    box.innerHTML = list.map(c => {
        const av = app.resolveAvatar(c.user?.avatar);
        const ini = (c.user?.nickname || c.user?.username || '?')[0];
        const bg = c.user?.avatar ? 'transparent' : colorFrom(c.user?.username);
        return `<div class="comment" data-cid="${c.id}">
          <span class="avatar" style="background:${bg}">${c.user?.avatar ? `<img src="${app.escapeHtml(av)}" alt="">` : app.escapeHtml(ini)}</span>
          <div class="comment-body"><div class="comment-name">${app.escapeHtml(c.user?.nickname || c.user?.username || '匿名')}<span class="comment-time">${app.escapeHtml(c.time_ago || '')}</span></div>
          <div class="comment-text">${app.escapeHtml(c.content || '')}</div>
          <div class="comment-acts"><span data-clike class="${c.liked ? 'liked' : ''}">赞 ${c.like_count || 0}</span><span data-creply>回复</span></div></div>
        </div>`;
    }).join('');
    box.querySelectorAll('[data-clike]').forEach(el => el.addEventListener('click', async () => {
        if (!app.checkAuth()) return;
        const on = el.classList.toggle('liked');
        const n = parseInt(el.textContent.replace(/\D/g, ''), 10) || 0;
        el.textContent = on ? `赞 ${n + 1}` : `赞 ${n}`;
        try { await app.request('/posts/comment-like', { method: 'POST', body: { comment_id: el.closest('.comment').dataset.cid } }); }
        catch (e) { app.showToast(e.message); }
    }));
    // 回复：后端 /posts/comment 支持 parent_id（不是 reply_to）
    box.querySelectorAll('[data-creply]').forEach(el => el.addEventListener('click', () => {
        const input = document.getElementById('commentInput');
        if (!input) return;
        input.dataset.parentId = el.closest('.comment')?.dataset.cid || '';
        input.placeholder = '回复该评论…';
        input.focus();
    }));
}

function bindCommentSend(app, post) {
    const input = document.getElementById('commentInput');
    const send = document.getElementById('commentSend');
    if (!send) return;
    send.addEventListener('click', async () => {
        if (!app.checkAuth()) return;
        const val = input.value.trim();
        if (!val) { app.showToast('先写点内容再发送'); return; }
        try {
            await app.request('/posts/comment', {
                method: 'POST',
                body: { post_id: post.id, content: val, parent_id: Number(input.dataset.parentId || 0) },
            });
            input.value = '';
            delete input.dataset.parentId;
            input.placeholder = '写下你的看法…';
            app.showToast('评论已发布');
            // 重新拉取详情评论
            const d = await app.request(`/posts/detail?id=${post.id}`);
            renderComments(app, d.comments || []);
            const h = document.querySelector('#comments h3'); if (h) h.textContent = '评论 ' + (d.comment_count || 0);
        } catch (e) { app.showToast(e.message); }
    });
}

async function loadAside(app, post) {
    // 热门阅读（详情页右栏）
    const rank = document.getElementById('rankList');
    try {
        // 右栏「热门阅读」取真实榜单，避免只从首页时间线里挑几条
        let list = [];
        try {
            const pop = await app.request('/posts/popular?type=likes&limit=8');
            list = (pop && pop.list) || [];
        } catch (e) {
            const data = await app.request('/posts/timeline?page=1&limit=8');
            list = (data.list || []);
        }
        list = list.filter(p => p.id !== post.id).slice(0, 6);
        if (rank && list.length) {
            rank.innerHTML = list.map((p, i) => `
              <a class="rank-item" data-id="${p.id}" href="${PAGE_PATH}/p/${p.id}" data-spa="1">
                <span class="rank-no">${i + 1}</span>
                <div><div class="rank-text">${app.escapeHtml((p.title || (p.content || '').slice(0, 30)) || '动态')}</div>
                <div class="rank-hot">${p.like_count || 0} 赞</div></div>
              </a>`).join('');
        }
    } catch (e) {}
    // 相关推荐
    const rel = document.getElementById('relGrid');
    try {
        const data = await app.request('/posts/timeline?page=1&limit=4');
        const list = (data.list || []).filter(p => p.id !== post.id).slice(0, 3);
        const fallback = collectPostImages(post, 3);
        if (rel) {
            if (!list.length) { rel.innerHTML = ''; return; }
            rel.innerHTML = list.map((p, i) => {
                const thumb = collectPostImages(p, 1)[0] || fallback[i] || '';
                return `<a class="rel-card" data-id="${p.id}" href="${PAGE_PATH}/p/${p.id}" data-spa="1">
                  <div class="thumb">${thumb ? `<img src="${app.escapeHtml(thumb)}" alt="${app.escapeHtml((p.title || (p.content || '').slice(0, 30)) || '动态')}" loading="lazy">` : ''}</div>
                  <div class="info"><div class="rel-title">${app.escapeHtml((p.title || (p.content || '').slice(0, 30)) || '动态')}</div>
                  <div class="rel-meta">${app.escapeHtml((p.user?.nickname || p.user?.username || ''))} · ${p.comment_count || 0}评论</div></div>
                </a>`;
            }).join('');
        }
    } catch (e) {}
}

function iconLike() { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v10H4V10h3zm0 0l4.2-6a2.4 2.4 0 0 1 3.4 1.6L15 8h3a2 2 0 0 1 2 2.4l-1.7 8A2 2 0 0 1 16.3 20H7"/></svg>`; }
function iconComment() { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a8 8 0 0 1-8 8H4l2-3a8 8 0 1 1 15-5z"/></svg>`; }
function iconStar() { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>`; }
