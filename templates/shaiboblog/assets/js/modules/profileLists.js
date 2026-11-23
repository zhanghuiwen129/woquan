/**
 * shaiboblog 关联列表页：粉丝 / 关注 / 我的收藏 / 置顶文章
 * 页面骨架（页头 + 侧栏）写在 HTML 里，本模块只负责填充 #plList。
 */
import { PAGE_PATH } from './config.js?v=20261116.12';
import { renderCard } from './feed.js?v=20261116.12';
import { fillSidebars } from './sidebar.js?v=20261116.12';

function colorFrom(str) {
    let h = 0;
    for (let i = 0; i < (str || '?').length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
    return `hsl(${h} 38% 42%)`;
}

function userRow(app, u) {
    const el = document.createElement('div');
    el.className = 'user-card';
    const av = app.resolveAvatar(u.avatar);
    const initial = (u.nickname || u.username || '?')[0];
    const bg = u.avatar ? 'transparent' : colorFrom(u.username);
    const href = PAGE_PATH + '/u/' + encodeURIComponent(u.username || '');
    el.innerHTML = `
      <a class="user-card-avatar" href="${href}" data-spa="1" style="background:${bg}">${u.avatar ? `<img src="${app.escapeHtml(av)}" alt="">` : app.escapeHtml(initial)}</a>
      <div class="user-card-info">
        <a class="user-card-name" href="${href}" data-spa="1">${app.escapeHtml(u.nickname || u.username || '用户')}</a>
        <div class="user-card-sub">${app.escapeHtml(u.bio || ('@' + (u.username || '')))}</div>
      </div>
      <button class="btn-ghost" type="button" data-pl-follow>关注</button>`;
    el.addEventListener('click', e => {
        if (e.target.closest('[data-pl-follow]')) return;
        if (e.target.closest('a[href]')) return;
        if (u.username) location.href = href;
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

function postRow(app, p, type) {
    const card = renderCard(app, p);
    if (type === 'favorites') {
        card.classList.add('is-favorite');
        const meta = card.querySelector('.post-meta');
        if (meta) {
            const btn = document.createElement('button');
            btn.className = 'post-act pl-remove';
            btn.type = 'button';
            btn.title = '取消收藏';
            btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>取消收藏`;
            btn.addEventListener('click', async e => {
                e.stopPropagation(); e.preventDefault();
                btn.disabled = true;
                try {
                    await app.request('/favorites/toggle', { method: 'POST', body: { post_id: p.id } });
                    card.remove();
                    app.showToast('已取消收藏');
                } catch (err) {
                    btn.disabled = false;
                    app.showToast(err.message || '操作失败');
                }
            });
            meta.appendChild(btn);
        }
    }
    if (type === 'pinned') {
        const top = card.querySelector('.post-top');
        if (top && !top.querySelector('.post-flag.pin')) {
            const flag = document.createElement('span');
            flag.className = 'post-flag pin';
            flag.textContent = '置顶';
            top.appendChild(flag);
        }
    }
    return card;
}

/**
 * @param {object} app
 * @param {'followers'|'following'|'favorites'|'pinned'} type
 */
export async function renderProfileList(app, type) {
    const listEl = document.getElementById('plList');
    const moreEl = document.getElementById('plMore');
    if (!listEl) return;

    const m = location.pathname.match(/\/u\/([^/?#]+)/);
    const username = m ? decodeURIComponent(m[1]) : '';
    const isUserList = type === 'followers' || type === 'following';
    const listClass = isUserList ? 'user-list' : 'post-list';
    listEl.className = listClass;
    let page = 1;
    let hasMore = false;
    let loading = false;

    const load = async (append) => {
        if (loading) return;
        loading = true;
        if (moreEl) moreEl.textContent = '加载中…';
        try {
            let url;
            if (type === 'followers') url = `/follow/followers?page=${page}&limit=20` + (username ? `&username=${encodeURIComponent(username)}` : '');
            else if (type === 'following') url = `/follow/following?page=${page}&limit=20` + (username ? `&username=${encodeURIComponent(username)}` : '');
            else if (type === 'favorites') url = `/favorites/list?page=${page}&limit=20`;
            else url = `/posts/pinned`;

            const data = await app.request(url);
            const items = Array.isArray(data) ? data : (data.list || []);
            if (!append) listEl.innerHTML = '';
            if (!items.length) {
                const text = type === 'favorites' ? '还没有收藏内容'
                    : type === 'pinned' ? '还没有置顶文章'
                    : (isUserList ? (username ? '该用户暂无用户数据' : '暂无用户') : '暂无内容');
                listEl.innerHTML = `<div class="empty-state">${app.escapeHtml(text)}</div>`;
            } else {
                items.forEach(it => listEl.appendChild(isUserList ? userRow(app, it) : postRow(app, it, type)));
            }
            // 侧栏数据独立于列表类型，统一补齐一次
            fillSidebars(app, isUserList ? [] : items);
            if (type === 'pinned') hasMore = false;
            else hasMore = !!(data && data.pagination && data.pagination.page < data.pagination.pages);
            if (hasMore) page++;
            if (moreEl) {
                moreEl.style.display = hasMore ? '' : 'none';
                moreEl.textContent = '加载更多';
            }
        } catch (e) {
            if (!append) listEl.innerHTML = `<div class="empty-state">${app.escapeHtml(e.message || '加载失败')}</div>`;
            else app.showToast(e.message || '加载失败');
        } finally {
            loading = false;
        }
    };

    if (moreEl) moreEl.onclick = () => { if (hasMore) load(true); };
    await load(false);
}
