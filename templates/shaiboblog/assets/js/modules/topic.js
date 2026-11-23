/**
 * shaiboblog 话题聚合页（topic.html）
 * 路由：/topic/{name}。对接 /posts/topic?name=、/topics、/follow/suggest。
 */
import { PAGE_BASE, HOME_URL, PAGE_PATH } from './config.js?v=20261116.12';
import { linkTag } from './utils.js?v=20261116.12';

const state = { name: '', sort: 'latest', page: 1, hasMore: true, loading: false, total: 0, topicMeta: null };

function titleOf(p) { return p.title || (p.content || '').replace(/<[^>]+>/g, '').slice(0, 40) || '动态'; }
function descOf(p) {
    const c = (p.content || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
    return c.length > 70 ? c.slice(0, 70) + '…' : c;
}
function coverOf(p) {
    const imgs = p.images || [], vids = p.videos || [];
    return imgs[0] || (vids[0] && vids[0].cover) || '';
}
function catName(app, id) {
    if (!id) return '';
    const cats = app.siteConfig?.categories || [];
    for (const c of cats) { if (String(c.id) === String(id)) return c.name; for (const ch of c.children || []) if (String(ch.id) === String(id)) return ch.name; }
    return '';
}
function colorFrom(s) {
    let h = 0; for (let i = 0; i < (s || '?').length; i++) h = (h * 31 + s.charCodeAt(i)) % 360;
    return `hsl(${h} 38% 42%)`;
}

export async function renderTopic(app) {
    const m = location.pathname.match(/\/topic\/([^/?#]+)/);
    state.name = m ? decodeURIComponent(m[1]) : '';
    state.sort = 'latest';
    state.page = 1;
    state.hasMore = true;
    document.title = '#' + state.name + ' · ' + (app.opt.brandName || '赛博博客');

    const nm = document.getElementById('topicName');
    if (nm) nm.textContent = '# ' + (state.name || '话题');
    const brief = document.getElementById('topicBrief');
    if (brief) brief.textContent = `汇集「${state.name || '话题'}」话题下的全部资讯与讨论，一起交流分享。`;
    const composeText = document.getElementById('topicComposeText');
    if (composeText) composeText.textContent = `参与「${state.name || '话题'}」讨论，分享你的看法…`;

    bindChrome(app);
    renderAside(app);
    app.refreshSidebars();
    updateFavBtn(app);
    loadFeed(app, true);
    // V95：管理员可见「设置封面」入口
    if (app.user && app.user.role === 'admin') maybeShowAdminCoverBtn(app);
}

function bindChrome(app) {
    const back = document.getElementById('backBtn');
    if (back) back.onclick = () => {
        if (window.history.length > 1) window.history.back();
        else location.href = HOME_URL;
    };
    const join = document.getElementById('joinBtn');
    if (join) join.onclick = (e) => {
        e.preventDefault();
        if (!app.checkAuth()) return;
        location.href = PAGE_PATH + '/write?tag=' + encodeURIComponent(state.name);
    };
    const compose = document.getElementById('topicCompose');
    if (compose) compose.onclick = () => {
        if (!app.checkAuth()) return;
        location.href = PAGE_PATH + '/write?tag=' + encodeURIComponent(state.name);
    };
    const fav = document.getElementById('favTopicBtn');
    if (fav) fav.onclick = () => {
        const key = 'shaibo_fav_topics';
        let list = [];
        try { list = JSON.parse(localStorage.getItem(key) || '[]'); } catch (e) { list = []; }
        const i = list.indexOf(state.name);
        if (i >= 0) { list.splice(i, 1); app.showToast('已取消收藏话题'); }
        else { list.push(state.name); app.showToast('已收藏话题'); }
        try { localStorage.setItem(key, JSON.stringify(list)); } catch (e) {}
        updateFavBtn(app);
    };
    const sortBar = document.getElementById('sortBar');
    if (sortBar) sortBar.addEventListener('click', e => {
        const s = e.target.closest('.sort'); if (!s) return;
        sortBar.querySelectorAll('.sort').forEach(x => x.classList.remove('active'));
        s.classList.add('active');
        state.sort = s.dataset.sort;
        loadFeed(app, true);
    });
    const lm = document.getElementById('load-more');
    if (lm) lm.addEventListener('click', () => { if (state.hasMore) loadFeed(app, false); });
}
function updateFavBtn(app) {
    const fav = document.getElementById('favTopicBtn');
    if (!fav) return;
    let list = [];
    try { list = JSON.parse(localStorage.getItem('shaibo_fav_topics') || '[]'); } catch (e) {}
    fav.textContent = list.indexOf(state.name) >= 0 ? '已收藏' : '收藏话题';
}

async function loadFeed(app, reset) {
    if (state.loading || !state.hasMore) return;
    if (reset) { state.page = 1; state.hasMore = true; }
    state.loading = true;
    const box = document.getElementById('topicFeed');
    const lm = document.getElementById('load-more');
    if (lm) { lm.style.display = ''; lm.innerHTML = '加载中…'; }
    if (reset && box) box.innerHTML = '';

    const url = `/posts/topic?name=${encodeURIComponent(state.name)}&page=${state.page}&limit=10`;
    try {
        const d = await app.request(url);
        let list = (d && d.list) || [];
        if (state.sort === 'hot') list = [...list].sort((a, b) => (b.like_count || 0) - (a.like_count || 0));
        state.total = d && d.pagination ? (d.pagination.total || list.length) : list.length;
        // V95：话题元信息（含后台设置的头图 / 讨论数），首屏记录后供 updateStats 渲染
        if (reset) state.topicMeta = (d && d.topic) || null;

        if (!list.length && reset) {
            if (box) box.innerHTML = '<div class="empty-state">该话题下暂无内容，来发布第一篇吧</div>';
            if (lm) lm.style.display = 'none';
            updateStats(app, list);
            state.loading = false;
            return;
        }
        if (box) list.forEach(p => box.appendChild(buildCard(app, p)));
        updateStats(app, list);
        state.hasMore = d && d.pagination ? (d.pagination.page < d.pagination.pages) : false;
        if (state.hasMore) state.page++;
        if (lm) {
            lm.innerHTML = state.hasMore ? '上滑加载更多' : '没有更多内容了';
            lm.classList.toggle('no-more', !state.hasMore);
        }
    } catch (e) {
        app.showToast(e.message || '加载失败');
        if (box && reset) box.innerHTML = '<div class="empty-state">内容加载失败，请稍后重试</div>';
        if (lm) lm.style.display = 'none';
    } finally {
        state.loading = false;
    }
}

function updateStats(app, list) {
    const stats = document.getElementById('topicStats');
    if (!stats) return;
    const reads = list.reduce((s, p) => s + (p.view_count || 0), 0);
    const authors = new Set(list.map(p => p.user && p.user.id).filter(Boolean)).size;
    // V95：讨论数优先用后台 topics 表的权威 post_count，没有再退回当前列表分页总数
    const total = (state.topicMeta && typeof state.topicMeta.post_count === 'number') ? state.topicMeta.post_count : state.total;
    stats.innerHTML = `<span><b>${total}</b> 篇讨论</span><span><b>${reads}</b> 阅读</span><span><b>${authors}</b> 位作者参与</span>`;

    // V95：优先使用后台设置的话题头图；未设置时回退到首帖首图，再退化为渐变底色
    const cover = document.getElementById('topicCover');
    const hero = document.getElementById('topicHero');
    if (cover) {
        const metaCover = state.topicMeta && state.topicMeta.cover;
        if (metaCover) {
            cover.src = metaCover;
            if (hero) hero.style.background = '';
        } else if (!cover.getAttribute('src')) {
            const c = list.map(coverOf).find(Boolean);
            if (c) cover.src = c;
            else { cover.removeAttribute('src'); if (hero) hero.style.background = 'linear-gradient(135deg,var(--brand),#0a3b33)'; }
        }
    }

    const brief = document.getElementById('topicBrief');
    const t = app.siteConfig?.topic_map?.[state.name];
    if (brief && t && t.description) brief.textContent = t.description;
}

/** V95：管理员在话题详情页设置头图（上传到 /posts/upload 后写回 /admin/topics/cover） */
function maybeShowAdminCoverBtn(app) {
    const actions = document.querySelector('.topic-actions');
    if (!actions || document.getElementById('setTopicCoverBtn')) return;
    const b = document.createElement('button');
    b.className = 'btn-ghost-light';
    b.id = 'setTopicCoverBtn';
    b.textContent = '设置封面';
    b.onclick = () => pickAndUploadCover(app);
    actions.appendChild(b);
}

async function pickAndUploadCover(app) {
    const id = state.topicMeta && state.topicMeta.id;
    if (!id) { app.showToast('话题信息加载中，请稍候'); return; }
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.onchange = async () => {
        const file = input.files && input.files[0];
        if (!file) return;
        app.showToast('图片上传中…');
        try {
            const fd = new FormData();
            fd.append('image', file);
            const res = await app.request('/posts/upload', { method: 'POST', body: fd });
            const url = res && res.url;
            if (!url) throw new Error('上传未返回图片地址');
            await app.request('/admin/topics/cover', { method: 'POST', body: { id, cover: url } });
            state.topicMeta = state.topicMeta || {};
            state.topicMeta.cover = url;
            const cover = document.getElementById('topicCover');
            if (cover) cover.src = url;
            const hero = document.getElementById('topicHero');
            if (hero) hero.style.background = '';
            app.showToast('话题头图已更新');
        } catch (e) {
            app.showToast(e.message || '设置失败');
        }
    };
    input.click();
}

function buildCard(app, post) {
    const el = document.createElement('article');
    el.className = 'post';
    const cover = coverOf(post);
    const tag = catName(app, post.category_id) || '文章';
    const author = post.user?.nickname || post.user?.username || '匿名';
    const postHref = PAGE_PATH + '/p/' + post.id;
    const tags = (Array.isArray(post.tags) ? post.tags : [])
        .map(t => (typeof t === 'string' ? t : (t && (t.name || t.title)) || ''))
        .filter(Boolean).slice(0, 3)
        .map(t => `<a class="post-tag" href="${PAGE_PATH}/search?q=${encodeURIComponent(t)}" data-spa="1">#${app.escapeHtml(t)}</a>`).join('');
    el.innerHTML = `
      <div class="post-main">
        <div class="post-top">
          ${post.category_id ? `<a class="post-cat" href="${PAGE_PATH}/c/${post.category_id}" data-spa="1">${app.escapeHtml(tag)}</a>` : `<span class="post-cat">${app.escapeHtml(tag)}</span>`}
          ${post.is_pinned ? '<span class="post-flag pin">置顶</span>' : ''}
        </div>
        <h2 class="post-title">${linkTag(postHref, app.escapeHtml(titleOf(post)), 'post-title-link')}</h2>
        <p class="post-excerpt">${app.escapeHtml(descOf(post))}</p>
        ${tags ? `<div class="post-tags">${tags}</div>` : ''}
        <div class="post-meta">
          <span class="post-author">${app.escapeHtml(author)}</span>
          <span class="post-dot">·</span>
          <time>${app.escapeHtml(String(post.time_ago || post.created_at || '').replace('T', ' ').slice(0, 16))}</time>
          <span class="post-stat">${post.comment_count || 0} 评论</span>
          <span class="post-stat">${post.like_count || 0} 赞</span>
        </div>
      </div>
      ${cover ? `<a class="post-thumb" href="${postHref}" data-spa="1"><img src="${app.escapeHtml(cover)}" alt="${app.escapeHtml(titleOf(post))}" loading="lazy"></a>` : ''}`;
    el.addEventListener('click', e => { if (e.target.closest('a[href]')) return; location.href = postHref; });
    return el;
}

function renderAside(app) {
    const box = document.getElementById('topicList');
    if (box) {
        app.request('/topics?limit=8').then(d => {
            const list = (d && d.list) || [];
            const others = list.filter(t => t.name !== state.name).slice(0, 6);
            box.innerHTML = others.length
                ? others.map(t => `<a class="topic-row" data-name="${app.escapeHtml(t.name)}" href="${PAGE_PATH}/topic/${encodeURIComponent(t.name)}" data-spa="1">
                     <div class="t-name"><b>#${app.escapeHtml(t.name)}</b><span>${t.post_count || 0} 篇讨论</span></div>
                     <span class="join">进入</span></a>`).join('')
                : '<div class="rank-hot">暂无相关话题</div>';
        }).catch(() => { box.innerHTML = '<div class="rank-hot">暂无相关话题</div>'; });
    }
    const authors = document.getElementById('asideAuthors');
    if (authors) {
        app.request('/follow/suggest?limit=4').then(d => {
            const users = (d && d.list) || d || [];
            if (!users.length) { authors.innerHTML = '<div class="rank-hot">暂无活跃作者</div>'; return; }
            authors.innerHTML = users.slice(0, 4).map(u => {
                const av = u.avatar;
                const ini = (u.nickname || u.username || '?')[0];
                const bg = av ? 'transparent' : colorFrom(u.username);
                return `<div class="aside-author" data-uid="${u.id}" data-un="${app.escapeHtml(u.username || '')}">
                  <span class="avatar" style="background:${bg}">${av ? `<img src="${app.escapeHtml(app.resolveAvatar(av))}" alt="">` : app.escapeHtml(ini)}</span>
                  <div class="info"><div class="author"><a href="${PAGE_PATH}/u/${encodeURIComponent(u.username || '')}" data-spa="1">${app.escapeHtml(u.nickname || u.username)}</a></div>
                  <div class="desc">${app.escapeHtml(u.bio || (u.follower_count ? u.follower_count + ' 粉丝' : '活跃作者'))}</div></div>
                  <button class="follow" data-follow="${u.id}">关注</button>
                </div>`;
            }).join('');
            authors.querySelectorAll('[data-follow]').forEach(b => b.addEventListener('click', async e => {
                e.stopPropagation();
                if (!app.checkAuth()) return;
                const on = b.classList.toggle('followed'); b.textContent = on ? '已关注' : '关注';
                try { await app.request('/follow', { method: 'POST', body: { user_id: b.dataset.follow } }); }
                catch (err) { b.classList.toggle('followed', !on); b.textContent = on ? '关注' : '已关注'; app.showToast(err.message); }
            }));
            authors.querySelectorAll('.aside-author').forEach(a => a.addEventListener('click', e => {
                if (e.target.closest('[data-follow]')) return;
                if (a.dataset.un) location.href = PAGE_PATH + '/u/' + encodeURIComponent(a.dataset.un);
            }));
        }).catch(() => { authors.innerHTML = '<div class="rank-hot">暂无活跃作者</div>'; });
    }
}
