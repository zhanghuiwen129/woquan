/**
 * woquan 个人主页关联列表页
 *
 * 把原先只显示占位文案的 followers / following / favorites / pinned 四个页面
 * 接成真实数据：粉丝与关注列表走 /follow/*，收藏走 /favorites/list，
 * 置顶走 /posts/pinned。所有列表共用 #page-module 容器，无需改动 HTML 骨架。
 */
import { PAGE_BASE, HOME_URL, PAGE_PATH } from './config.js?v=20261117.02';
import { collectPostImages } from './utils.js?v=20261117.02';

const TITLES = {
    followers: '粉丝', following: '关注', favorites: '我的收藏', pinned: '置顶内容',
};

function colorFrom(str) {
    let h = 0;
    for (let i = 0; i < (str || '?').length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
    return `hsl(${h} 38% 42%)`;
}

function shell(app, type, name) {
    const host = document.getElementById('page-module');
    if (!host) return null;
    host.innerHTML = `
      <div class="module-head">
        <button class="back-btn" type="button" id="plBack" aria-label="返回">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <h1>${app.escapeHtml(name)}</h1>
      </div>
      <div class="module-list" id="plList"><div class="empty">加载中…</div></div>
      <div class="load-more" id="plMore" style="display:none">加载更多</div>`;
    const back = document.getElementById('plBack');
    if (back) back.onclick = () => {
        const m = location.pathname.match(/\/u\/([^/?#]+)/);
        if (m) location.href = PAGE_PATH + '/u/' + m[1];
        else if (window.history.length > 1) window.history.back();
        else location.href = HOME_URL;
    };
    return host;
}

function userRow(app, u) {
    const el = document.createElement('div');
    el.className = 'module-row';
    const av = app.resolveAvatar(u.avatar);
    const initial = (u.nickname || u.username || '?')[0];
    const bg = u.avatar ? 'transparent' : colorFrom(u.username);
    el.innerHTML = `
      <span class="avatar" style="background:${bg}">${u.avatar ? `<img src="${app.escapeHtml(av)}" alt="">` : app.escapeHtml(initial)}</span>
      <div class="mr-info">
        <div class="mr-name"><a href="${PAGE_PATH}/u/${encodeURIComponent(u.username || '')}" data-spa="1">${app.escapeHtml(u.nickname || u.username || '用户')}</a></div>
        <div class="mr-sub">${app.escapeHtml(u.bio || ('@' + (u.username || '')))}</div>
      </div>
      <button class="btn-ghost" type="button" data-pl-follow>关注</button>`;
    el.addEventListener('click', e => {
        if (e.target.closest('[data-pl-follow]')) return;
        if (u.username) location.href = PAGE_PATH + '/u/' + encodeURIComponent(u.username);
    });
    const btn = el.querySelector('[data-pl-follow]');
    if (btn) {
        if (String(u.id) === String(app.user?.id)) btn.style.display = 'none';
        btn.onclick = async e => {
            e.stopPropagation();
            if (!app.checkAuth()) return;
            const on = btn.classList.toggle('followed');
            btn.textContent = on ? '已关注' : '关注';
            try { await app.request('/follow', { method: 'POST', body: { user_id: u.id } }); }
            catch (err) { btn.classList.toggle('followed', !on); btn.textContent = on ? '关注' : '已关注'; app.showToast(err.message); }
        };
    }
    return el;
}

function postRow(app, p) {
    const el = document.createElement('div');
    el.className = 'module-row pl-post';
    const cover = collectPostImages(p, 1)[0];
    const title = p.title || String(p.content || '').replace(/!\[[^\]]*\]\([^)]*\)/g, '').replace(/<[^>]+>/g, '').trim().slice(0, 40) || '动态';
    const postHref = PAGE_PATH + '/p/' + p.id;
    el.innerHTML = `
      ${cover ? `<span class="pl-thumb"><a class="card-cover-link" href="${postHref}" data-spa="1"><img src="${app.escapeHtml(cover)}" alt="${app.escapeHtml(title)}" loading="lazy"></a></span>` : ''}
      <div class="mr-info">
        <div class="mr-name"><a href="${postHref}" data-spa="1">${app.escapeHtml(title)}</a></div>
        <div class="mr-sub">${app.escapeHtml(p.user?.nickname || p.user?.username || '')} · ${p.like_count || 0} 赞 · ${p.comment_count || 0} 评论</div>
      </div>`;
    if (p.id) el.addEventListener('click', e => { if (e.target.closest('a[href]')) return; location.href = postHref; });
    return el;
}

/**
 * @param {object} app
 * @param {'followers'|'following'|'favorites'|'pinned'} type
 */
export async function renderProfileList(app, type) {
    const host = shell(app, type, TITLES[type] || '列表');
    if (!host) return;
    const listEl = document.getElementById('plList');
    const moreEl = document.getElementById('plMore');

    // 路径 /u/{username}/followers 或 /followers（我的）
    const m = location.pathname.match(/\/u\/([^/?#]+)/);
    const username = m ? decodeURIComponent(m[1]) : '';
    const isUserList = type === 'followers' || type === 'following';
    let page = 1;
    let hasMore = false;
    let loading = false;

    const load = async (append) => {
        if (loading) return;
        loading = true;
        try {
            let url;
            if (type === 'followers') url = `/follow/followers?page=${page}&limit=20` + (username ? `&username=${encodeURIComponent(username)}` : '');
            else if (type === 'following') url = `/follow/following?page=${page}&limit=20` + (username ? `&username=${encodeURIComponent(username)}` : '');
            else if (type === 'favorites') url = `/favorites/list?page=${page}&limit=20`;
            else url = `/posts/pinned`;

            const data = await app.request(url);
            const items = Array.isArray(data) ? data : (data.list || []);
            if (!append) listEl.innerHTML = '';
            if (!items.length && !append) {
                listEl.innerHTML = `<div class="empty">${type === 'favorites' ? '还没有收藏内容' : (TITLES[type] || '列表') + '暂无内容'}</div>`;
            } else {
                items.forEach(it => listEl.appendChild(isUserList ? userRow(app, it) : postRow(app, it)));
            }
            if (type === 'pinned') hasMore = false;
            else hasMore = !!(data && data.pagination && data.pagination.page < data.pagination.pages);
            if (hasMore) page++;
            if (moreEl) {
                moreEl.style.display = hasMore ? '' : 'none';
                moreEl.textContent = '加载更多';
            }
        } catch (e) {
            if (!append) listEl.innerHTML = `<div class="empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
            else app.showToast(e.message || '加载失败');
        } finally {
            loading = false;
        }
    };

    if (moreEl) moreEl.onclick = () => { if (hasMore) load(true); };
    await load(false);
}
