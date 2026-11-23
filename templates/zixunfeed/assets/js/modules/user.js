/**
 * zixunfeed 个人主页（user.html）
 * 路由：/u/{username}。对接 /user/profile、/posts/timeline?username=、
 * /user/likes、/user/comments、/favorites/list、/follow/check、/follow。
 */
import { PAGE_BASE, HOME_URL, PAGE_PATH } from './config.js?v=20261117.02';
import { collectPostImages } from './utils.js?v=20261117.02';

const state = { username: '', userId: null, tab: 'works', page: 1, hasMore: false, loading: false, profile: null };

function titleOf(post) {
    const plain = String(post.content || '')
        .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
        .replace(/<[^>]+>/g, '')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/\s+/g, ' ').trim();
    return post.title || plain.slice(0, 40) || '动态';
}
function colorFrom(str) {
    let h = 0; for (let i = 0; i < (str || '?').length; i++) h = (h * 31 + str.charCodeAt(i)) % 360;
    return `hsl(${h} 38% 42%)`;
}
function coverOf(post) {
    return collectPostImages(post, 1)[0] || '';
}
function fmtNum(n) {
    n = Number(n) || 0;
    return n >= 10000 ? (n / 10000).toFixed(1).replace(/\.0$/, '') + '万' : String(n);
}

export async function renderUser(app) {
    const m = location.pathname.match(/\/u\/([^/?#]+)/);
    state.username = m ? decodeURIComponent(m[1]) : '';
    state.tab = 'works';
    state.page = 1;
    document.title = (state.username || '个人主页') + ' · ' + (app.opt.brandName || 'woquan');

    bindChrome(app);
    bindTabs(app);
    bindStats(app);
    bindLoadMore(app);

    if (!state.username) {
        const n = document.getElementById('profileName');
        if (n) n.textContent = '用户不存在';
        return;
    }

    try {
        const d = await app.request('/user/profile?username=' + encodeURIComponent(state.username));
        state.profile = d || {};
        fillProfile(app, state.profile);
    } catch (e) {
        const n = document.getElementById('profileName');
        if (n) n.textContent = '用户不存在';
        app.showToast(e.message || '资料加载失败');
    }
    loadTab(app, true);
}

function fillProfile(app, d) {
    const u = d.user || {};
    const s = d.stats || {};
    state.userId = u.id || null;
    document.title = (u.nickname || u.username || state.username) + ' · ' + (app.opt.brandName || 'woquan');

    const av = document.getElementById('profileAvatar');
    if (av) {
        if (u.avatar) av.innerHTML = `<img src="${app.escapeHtml(app.resolveAvatar(u.avatar))}" alt="">`;
        else { av.textContent = (u.nickname || u.username || '?')[0]; av.style.background = colorFrom(u.username); }
    }
    const name = document.getElementById('profileName');
    if (name) name.textContent = u.nickname || u.username || state.username;
    const bio = document.getElementById('profileBio');
    if (bio) bio.textContent = u.bio || '这个人很懒，还没有填写简介';
    const meta = document.getElementById('profileMeta');
    if (meta) {
        const joined = (u.created_at || '').slice(0, 7);
        meta.innerHTML = `<span>${app.escapeHtml(u.location || (app.opt.brandName || 'woquan') + '用户')}</span>`
            + (joined ? `<span>加入于 ${app.escapeHtml(joined)}</span>` : '')
            + (u.realname_verified ? '<span>已实名认证</span>' : '');
    }

    const stats = document.getElementById('stats');
    if (stats) {
        const vals = [s.posts, s.likes_received, s.favorites ?? s.likes, s.followers_count, s.following_count];
        stats.querySelectorAll('.stat b').forEach((b, i) => { b.textContent = fmtNum(vals[i]); });
    }

    const about = document.getElementById('aboutBox');
    if (about) {
        about.innerHTML =
            `<div class="about-line"><b>用户名：</b>${app.escapeHtml(u.username || state.username)}</div>`
            + `<div class="about-line"><b>简介：</b>${app.escapeHtml(u.bio || '暂无简介')}</div>`
            + `<div class="about-line"><b>加入时间：</b>${app.escapeHtml((u.created_at || '—').slice(0, 10))}</div>`
            + `<div class="about-line"><b>认证：</b>${u.realname_verified ? '已实名认证' : '普通用户'}</div>`;
    }
    const isMe = !!(app.user && (String(app.user.id) === String(u.id) || app.user.username === u.username));
    const followBtn = document.getElementById('followBtn');
    const editBtn = document.getElementById('editBtn');
    const msgBtn = document.getElementById('msgBtn');
    if (isMe) {
        if (editBtn) {
            editBtn.style.display = '';
            editBtn.textContent = '编辑资料';
            // 打开完整账户设置（资料 / 头像 / 封面 / 密码 / 隐私），替代原来的 prompt 改简介
            editBtn.onclick = () => app.openAccountPanel('profile');
        }
        if (msgBtn) msgBtn.style.display = 'none';
        // 退出登录统一收进顶栏用户菜单（nav.js::mountUserMenu 的「退出登录」项），
        // 个人主页不再单独放一个退出按钮，避免顶栏出现两个退出入口。
    } else if (followBtn) {
        followBtn.style.display = '';
        checkFollow(app, followBtn);
    }
}

async function checkFollow(app, btn) {
    if (!state.userId || !app.user) return;
    try {
        const d = await app.request('/follow/check?user_id=' + state.userId);
        setFollow(btn, !!d.following);
    } catch (e) { /* 游客态忽略 */ }
}
function setFollow(btn, on) {
    btn.classList.toggle('followed', on);
    btn.textContent = on ? '已关注' : '关注';
    btn.dataset.on = on ? '1' : '0';
}

function bindChrome(app) {
    const back = document.getElementById('backBtn');
    if (back) back.onclick = () => {
        if (window.history.length > 1) window.history.back();
        else location.href = HOME_URL;
    };
    const msg = document.getElementById('msgBtn');
    if (msg) msg.onclick = () => {
        if (!app.checkAuth()) return;
        // 私信页当前仍为结构壳，直接跳转会让用户进入空白页；先给出明确提示
        app.showToast('私信功能尚未开放');
    };
    // 夜间模式统一收进顶栏用户菜单（nav.js::mountUserMenu 的「夜间模式」项），本页不再自带设置按钮
    const followBtn = document.getElementById('followBtn');
    if (followBtn) followBtn.onclick = async () => {
        if (!app.checkAuth()) return;
        if (!state.userId) return;
        const on = followBtn.dataset.on !== '1';
        setFollow(followBtn, on);
        try {
            await app.request('/follow', { method: 'POST', body: { user_id: state.userId } });
            app.showToast(on ? '已关注' : '已取消关注');
        } catch (e) {
            setFollow(followBtn, !on);
            app.showToast(e.message || '操作失败');
        }
    };
    // 夜间模式的持久化读取统一在 app.js::applyThemeOptions 处理，本页不再单独读 localStorage，
    // 否则会出现「只有个人主页记住手选模式」的不一致行为。
}

function bindTabs(app) {
    const tabs = document.getElementById('userTabs');
    if (!tabs) return;
    tabs.addEventListener('click', e => {
        const t = e.target.closest('.tab'); if (!t) return;
        activateTab(t.dataset.tab);
        loadTab(app, true);
    });
}
function activateTab(tab) {
    state.tab = tab;
    const tabs = document.getElementById('userTabs');
    if (tabs) tabs.querySelectorAll('.tab').forEach(x => x.classList.toggle('active', x.dataset.tab === tab));
}
function bindStats(app) {
    const stats = document.getElementById('stats');
    if (!stats) return;
    stats.addEventListener('click', e => {
        const s = e.target.closest('.stat'); if (!s) return;
        const k = s.dataset.nav;
        if (k === 'followers' || k === 'following') {
            location.href = PAGE_PATH + '/u/' + encodeURIComponent(state.username) + '/' + k;
            return;
        }
        activateTab(k === 'likes' ? 'likes' : (k === 'favs' ? 'favs' : 'works'));
        loadTab(app, true);
    });
}
function bindLoadMore(app) {
    const lm = document.getElementById('load-more');
    if (lm) lm.onclick = () => { if (state.hasMore) loadTab(app, false); };
}

async function loadTab(app, reset) {
    if (state.loading) return;
    if (reset) { state.page = 1; state.hasMore = false; }
    state.loading = true;
    const grid = document.getElementById('userGrid');
    const lm = document.getElementById('load-more');
    if (lm) lm.style.display = 'none';
    if (reset && grid) grid.innerHTML = '<div class="empty">加载中…</div>';

    const un = encodeURIComponent(state.username);
    let url;
    if (state.tab === 'works') url = `/posts/timeline?username=${un}&page=${state.page}&limit=12`;
    else if (state.tab === 'likes') url = `/user/likes?username=${un}&page=${state.page}&limit=12`;
    else if (state.tab === 'comments') url = `/user/comments?username=${un}&page=${state.page}&limit=12`;
    else {
        // 收藏仅本人可见
        const isMe = !!(app.user && (app.user.username === state.username || String(app.user.id) === String(state.userId)));
        if (!isMe) {
            if (grid) grid.innerHTML = '<div class="empty">该用户的收藏仅本人可见</div>';
            state.loading = false;
            return;
        }
        url = `/favorites/list?page=${state.page}&limit=12`;
    }

    try {
        const d = await app.request(url);
        let list = (d && d.list) || [];
        if (reset && grid) grid.innerHTML = '';
        if (!list.length && reset) {
            if (grid) grid.innerHTML = '<div class="empty">暂无内容</div>';
            state.loading = false;
            return;
        }
        list.forEach(item => {
            let post, note = '';
            if (state.tab === 'comments') {
                post = item.post || {};
                note = (item.comment && item.comment.content || '').replace(/<[^>]+>/g, '').slice(0, 30);
            } else post = item;
            if (grid) grid.appendChild(buildWork(app, post, note));
        });
        state.hasMore = d && d.pagination ? (d.pagination.page < d.pagination.pages) : false;
        if (state.hasMore) state.page++;
        if (lm && state.hasMore) {
            lm.style.display = '';
            lm.textContent = '加载更多';
            lm.classList.remove('no-more');
        }
    } catch (e) {
        if (grid && reset) grid.innerHTML = `<div class="empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
        else app.showToast(e.message || '加载失败');
    } finally {
        state.loading = false;
    }
}

function buildWork(app, post, note) {
    const el = document.createElement('article');
    el.className = 'work';
    const cover = coverOf(post);
    const title = titleOf(post);
    const workHref = PAGE_PATH + '/p/' + post.id;
    el.innerHTML = `
      <div class="work-thumb"><a class="card-cover-link" href="${workHref}" data-spa="1">${cover ? `<img src="${app.escapeHtml(cover)}" alt="${app.escapeHtml(title)}" loading="lazy">` : ''}</a></div>
      <div class="work-info">
        <div class="work-title"><a href="${workHref}" data-spa="1">${app.escapeHtml(title)}</a></div>
        <div class="work-meta">
          <span>赞 ${post.like_count || 0}</span><span>评论 ${post.comment_count || 0}</span>
          ${note ? `<span>${app.escapeHtml(note)}</span>` : ''}
        </div>
      </div>`;
    if (post.id) el.addEventListener('click', e => { if (e.target.closest('a[href]')) return; location.href = workHref; });
    return el;
}
