/**
 * shaiboblog 消息通知页（messages.html）
 * 路由：/messages。对接 /notifications（分类 + 未读角标）、
 * /notifications/read-all、/notifications/{id}/read、/follow（回关）。
 */
import { PAGE_BASE, HOME_URL, PAGE_PATH } from './config.js?v=20261116.12';

const TAB_TYPE = {
    comment: 'comment,comment_like,mention',
    like: 'like',
    follow: 'follow',
    system: 'system,message',
    channel: 'category',
};
const ACTION = {
    comment: '评论了你的文章', comment_like: '赞了你的评论', like: '赞了你的文章',
    follow: '关注了你', mention: '在动态中提到了你', system: '系统通知', message: '新消息',
    category: '在你的关注频道发布了新文章',
};
const state = { tab: 'comment', page: 1, hasMore: false, loading: false };

function colorFrom(str) {
    let h = 0; for (let i = 0; i < (str || '?').length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
    return `hsl(${h} 38% 42%)`;
}
function timeAgo(s) {
    if (!s) return '';
    const t = new Date(String(s).replace(' ', 'T')).getTime();
    if (!t) return String(s).slice(0, 16);
    const d = (Date.now() - t) / 1000;
    if (d < 60) return '刚刚';
    if (d < 3600) return Math.floor(d / 60) + '分钟前';
    if (d < 86400) return Math.floor(d / 3600) + '小时前';
    if (d < 604800) return Math.floor(d / 86400) + '天前';
    return String(s).slice(0, 10);
}

export async function renderMessages(app) {
    state.tab = 'comment';
    document.title = '消息通知 · ' + (app.opt.brandName || 'woquan');
    bindChrome(app);
    bindTabs(app);
    bindList(app);
    if (!app.user) {
        const box = document.getElementById('noticeList');
        if (box) box.innerHTML = '<div class="empty">登录后即可查看消息通知<br><br><button class="btn-primary" id="goLogin" style="padding:9px 22px;border-radius:18px">去登录</button></div>';
        const gl = document.getElementById('goLogin');
        if (gl) gl.onclick = () => app.goLogin();
        return;
    }
    await loadUnread(app);
    await loadList(app, true);
}

function bindChrome(app) {
    const back = document.getElementById('backBtn');
    if (back) back.onclick = () => {
        if (window.history.length > 1) window.history.back();
        else location.href = HOME_URL;
    };
    const all = document.getElementById('readAllBtn');
    if (all) all.onclick = async () => {
        if (!app.user) return app.goLogin();
        try {
            await app.request('/notifications/read-all', { method: 'PUT' });
            document.querySelectorAll('.notice.unread').forEach(n => n.classList.remove('unread'));
            document.querySelectorAll('.notice .dot').forEach(d => d.remove());
            document.querySelectorAll('.badge').forEach(b => { b.style.display = 'none'; });
            app.showToast('已全部标为已读');
        } catch (e) { app.showToast(e.message || '操作失败'); }
    };
    const lm = document.getElementById('load-more');
    if (lm) lm.onclick = () => { if (state.hasMore) loadList(app, false); };
}
function bindTabs(app) {
    const tabs = document.getElementById('noticeTabs');
    if (!tabs) return;
    tabs.addEventListener('click', e => {
        const t = e.target.closest('.tab'); if (!t) return;
        tabs.querySelectorAll('.tab').forEach(x => x.classList.remove('active'));
        t.classList.add('active');
        state.tab = t.dataset.tab;
        if (app.user) loadList(app, true);
    });
}
function bindList(app) {
    const box = document.getElementById('noticeList');
    if (!box) return;
    box.addEventListener('click', async e => {
        const act = e.target.closest('[data-act]');
        if (act) {
            e.stopPropagation();
            const id = act.dataset.uid;
            if (act.dataset.act === 'followBack') {
                if (!app.checkAuth()) return;
                try {
                    await app.request('/follow', { method: 'POST', body: { user_id: id } });
                    act.textContent = '已关注';
                    act.classList.remove('brand');
                    app.showToast('已回关');
                } catch (err) { app.showToast(err.message || '操作失败'); }
            } else if (act.dataset.act === 'home') {
                location.href = PAGE_PATH + '/u/' + encodeURIComponent(act.dataset.name || '');
            }
            return;
        }
        const item = e.target.closest('.notice');
        if (!item) return;
        const nid = item.dataset.id;
        if (item.classList.contains('unread') && nid) {
            try { await app.request('/notifications/' + nid + '/read', { method: 'PUT' }); } catch (err) { /* 忽略 */ }
            item.classList.remove('unread');
            const dot = item.querySelector('.dot'); if (dot) dot.remove();
        }
        const pid = item.dataset.postId;
        if (pid) location.href = PAGE_PATH + '/p/' + pid;
        else if (item.dataset.type === 'follow') location.href = PAGE_PATH + '/u/' + encodeURIComponent(item.dataset.name || '');
    });
}

async function loadUnread(app) {
    try {
        const d = await app.request('/notifications/unread-count');
        const n = d && d.count ? d.count : 0;
        const b = document.getElementById('badge-' + state.tab);
        // 分类角标由列表接口的 unread_by_type 提供更精确，这里先给总数兜底
        if (b && n > 0) { b.textContent = n > 99 ? '99+' : n; b.style.display = ''; }
    } catch (e) { /* 游客忽略 */ }
}

async function loadList(app, reset) {
    if (state.loading) return;
    if (!app.user) return;
    if (reset) { state.page = 1; state.hasMore = false; }
    state.loading = true;
    const box = document.getElementById('noticeList');
    const lm = document.getElementById('load-more');
    if (reset && box) box.innerHTML = '<div class="empty">加载中…</div>';
    if (lm) lm.style.display = 'none';

    const url = `/notifications?page=${state.page}&limit=20&type=${encodeURIComponent(TAB_TYPE[state.tab])}`;
    try {
        const d = await app.request(url);
        const list = (d && d.list) || [];
        // 未读角标
        const byType = (d && d.unread_by_type) || {};
        const unreadFor = k => (TAB_TYPE[k] || '').split(',').reduce((s, t) => s + (Number(byType[t]) || 0), 0);
        ['comment', 'like', 'follow', 'channel', 'system'].forEach(k => {
            const b = document.getElementById('badge-' + k);
            if (!b) return;
            const n = unreadFor(k);
            if (n > 0) { b.textContent = n > 99 ? '99+' : n; b.style.display = ''; }
            else b.style.display = 'none';
        });

        if (reset && box) box.innerHTML = '';
        if (!list.length && reset) {
            if (box) box.innerHTML = '<div class="empty">暂无通知</div>';
            state.loading = false;
            return;
        }
        if (box) {
            if (reset) box.appendChild(groupLabel());
            let host = box.querySelector('.list');
            if (!host) { host = document.createElement('div'); host.className = 'list'; box.appendChild(host); }
            list.forEach(n => host.appendChild(buildNotice(app, n)));
            // 点击「去登录」等占位不残留
        }
        state.hasMore = d && d.pagination ? (d.pagination.page < d.pagination.pages) : false;
        if (state.hasMore) state.page++;
        if (lm && state.hasMore) { lm.style.display = ''; lm.textContent = '加载更多'; }
    } catch (e) {
        if (box && reset) box.innerHTML = `<div class="empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
        else app.showToast(e.message || '加载失败');
    } finally {
        state.loading = false;
    }
}

function groupLabel() {
    const d = document.createElement('div');
    d.className = 'group-label';
    d.textContent = '最近通知';
    return d;
}

function buildNotice(app, n) {
    const el = document.createElement('div');
    const unread = !n.is_read;
    el.className = 'notice' + (unread ? ' unread' : '');
    el.dataset.id = n.id;
    el.dataset.type = n.type;
    if (n.post_id) el.dataset.postId = n.post_id;
    const sender = n.sender || {};
    const name = sender.nickname || sender.username || '系统';
    el.dataset.name = sender.username || '';

    const text = (n.content || '').replace(/<[^>]+>/g, '');
    const time = timeAgo(n.created_at);
    const post = n.post || {};
    const thumb = post.image || (post.images && post.images[0]) || '';
    const isSystem = n.type === 'system' || n.type === 'message';
    const action = ACTION[n.type] || '给你发来一条通知';
    const dot = unread ? '<span class="dot" style="margin-left:6px"></span>' : '';

    if (isSystem) {
        el.innerHTML = `
          <span class="sys-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/></svg></span>
          <div class="notice-body">
            <div class="notice-line"><b>${app.escapeHtml(action)}</b>${dot}</div>
            <div class="notice-text">${app.escapeHtml(text)}</div>
            <div class="notice-meta"><span>${app.escapeHtml(time)}</span></div>
          </div>`;
        return el;
    }

    const av = sender.avatar
        ? `<img src="${app.escapeHtml(app.resolveAvatar(sender.avatar))}" alt="">`
        : app.escapeHtml(name[0] || '?');
    const bg = sender.avatar ? 'transparent' : colorFrom(sender.username || name);
    const isFollow = n.type === 'follow';
    const acts = isFollow
        ? `<div class="notice-actions">
             <button class="n-btn brand" data-act="followBack" data-uid="${n.sender_id || ''}">回关</button>
             <button class="n-btn" data-act="home" data-name="${app.escapeHtml(sender.username || '')}">主页</button>
           </div>`
        : '';

    el.innerHTML = `
      <span class="avatar" style="background:${bg}">${av}</span>
      <div class="notice-body">
        <div class="notice-line"><b>${app.escapeHtml(name)}</b> ${app.escapeHtml(action)}${dot}</div>
        <div class="notice-text">${app.escapeHtml(text)}</div>
        ${acts}
        <div class="notice-meta"><span>${app.escapeHtml(time)}</span>${n.post_id ? '<span>点击查看原文</span>' : ''}</div>
      </div>
      ${thumb ? `<div class="notice-thumb"><img src="${app.escapeHtml(thumb)}" alt="配图" loading="lazy"></div>` : ''}`;
    return el;
}
