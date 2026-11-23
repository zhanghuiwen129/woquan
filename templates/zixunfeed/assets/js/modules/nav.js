/**
 * zixunfeed 导航与外壳模块
 * 负责：Toast、顶栏登录入口（跳独立登录页）、登录态、频道 Tab、底部 Tab、结构壳渲染
 */
import { BASE_URL, PAGE_BASE, HOME_URL, PAGE_PATH } from './config.js?v=20261117.02';
import { openPanel } from './account.js?v=20261117.02';

let toastTimer;
export function toast(msg) {
    let t = document.getElementById('toast');
    if (!t) { t = document.createElement('div'); t.id = 'toast'; t.className = 'toast'; document.body.appendChild(t); }
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 1600);
}

let homeLinksBound = false;

/**
 * 站内首页跳转统一入口：Logo、面包屑「首页」等使用 [data-home] 标记。
 * 统一走 config.js 的 HOME_URL（由 HUBLOG_BASE_URL 推导），
 * 不能用 HUBLOG_PAGE_BASE 拼接：伪静态开启且根目录部署时该值为空，
 * `(pageBase ? ... : '/') + '/'` 会拼出 "//"，浏览器视为无效协议相对地址，点击毫无反应。
 */
export function initHomeLinks() {
    document.querySelectorAll('[data-home]').forEach(el => {
        if (el.tagName === 'A') el.setAttribute('href', HOME_URL);
    });
    if (homeLinksBound) return;
    homeLinksBound = true;
    // 事件委托：兼容运行时动态插入的返回首页按钮
    document.addEventListener('click', e => {
        const target = e.target.closest('[data-home]');
        if (!target) return;
        e.preventDefault();
        location.href = HOME_URL;
    });
}

/** 顶栏：绑定登录按钮、搜索、Logo */
export function mountTopbar(app) {
    // boot() 会在首屏与站点配置到达后各调用一次，必须防重复绑定
    const bindOnce = (el, fn) => {
        if (!el || el.dataset.topbarBound === '1') return;
        el.dataset.topbarBound = '1';
        el.addEventListener('click', fn);
    };
    const loginBtn = document.getElementById('loginBtn');
    bindOnce(loginBtn, () => {
        if (app.user) { location.href = PAGE_PATH + '/u/' + encodeURIComponent(app.user.username); }
        else app.openAuthModal();
    });
    const avatar = document.getElementById('topbarAvatar');
    bindOnce(avatar, () => {
        if (app.user) location.href = PAGE_PATH + '/u/' + encodeURIComponent(app.user.username);
    });
    const searchInput = document.getElementById('searchInput');
    if (searchInput && searchInput.dataset.topbarBound !== '1') {
        searchInput.dataset.topbarBound = '1';
        searchInput.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                const q = searchInput.value.trim();
                if (q) location.href = PAGE_PATH + '/search?q=' + encodeURIComponent(q);
            }
        });
    }
    // 品牌名统一由 app.js::applyThemeOptions → utils.applyBrandNameToDOM 写入，
    // 此处不再用 site_name 覆盖（会冲掉后台「主题外观」里设置的站点品牌名）。
}

/**
 * 顶栏用户菜单：登录后把右上角头像变成可展开菜单，
 * 提供个人主页 / 我的置顶 / 消息中心 / 我的收藏 / 修改密码 / 夜间模式 / 管理后台（仅管理员）/ 退出登录。
 * 每个页面各自的 .topbar-right 结构不同，这里统一在末尾追加，不改动 HTML 骨架。
 */
export function mountUserMenu(app) {
    if (document.getElementById('userMenu')) return;
    // 各页顶栏结构不同：优先 .topbar-right，其次 .topbar-actions（写文章页），
    // 都没有时在 .topbar-inner 末尾补一个，保证 22 个页面都有账户菜单与退出登录入口。
    let right = document.querySelector('.topbar-right') || document.querySelector('.topbar-actions');
    if (!right) {
        const inner = document.querySelector('.topbar-inner');
        if (!inner) return;
        right = document.createElement('div');
        right.className = 'topbar-right';
        inner.appendChild(right);
    }

    const box = document.createElement('div');
    box.className = 'user-menu-wrap';
    box.id = 'userMenu';
    box.innerHTML = `
      <button class="user-menu-trigger" type="button" id="userMenuTrigger" aria-label="账户菜单" aria-expanded="false">
        <span class="user-menu-avatar" id="userMenuAvatar"></span>
      </button>
      <div class="user-menu-panel" id="userMenuPanel">
        <div class="user-menu-head"><b id="userMenuName"></b><small id="userMenuHandle"></small></div>
        <a class="primary" data-um="write" href="${PAGE_PATH}/write" data-spa="1">写文章</a>
        <a data-um="profile" id="userMenuProfile" href="${PAGE_PATH}/u/" data-spa="1">个人主页</a>
        <a data-um="pinned" href="${PAGE_PATH}/pinned" data-spa="1">我的置顶</a>
        <a data-um="messages" href="${PAGE_PATH}/messages" data-spa="1">消息中心</a>
        <a data-um="favorites" href="${PAGE_PATH}/favorites" data-spa="1">我的收藏</a>
        <button type="button" data-um="password">修改密码</button>
        <button type="button" data-um="dark">夜间模式</button>
        <a data-um="admin" href="${BASE_URL}/admin/" target="_blank" rel="noopener noreferrer" style="display:none">管理后台</a>
        <button type="button" class="danger" data-um="logout">退出登录</button>
      </div>`;
    right.appendChild(box);

    const trigger = box.querySelector('#userMenuTrigger');
    const panel = box.querySelector('#userMenuPanel');
    trigger.addEventListener('click', e => {
        e.stopPropagation();
        const open = panel.classList.toggle('open');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('click', e => {
        if (!box.contains(e.target)) {
            panel.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
        }
    });
    panel.addEventListener('click', async e => {
        const item = e.target.closest('[data-um]');
        if (!item) return;
        const act = item.dataset.um;
        // 真实链接项：由全局链接委托做无刷新跳转，这里只收起面板
        if (item.tagName === 'A') {
            panel.classList.remove('open');
            trigger.setAttribute('aria-expanded', 'false');
            return;
        }
        panel.classList.remove('open');
        if (act === 'account') openPanel(app, 'profile');
        else if (act === 'password') openPanel(app, 'security');
        else if (act === 'dark') {
            const root = document.documentElement;
            const dark = root.getAttribute('data-theme') === 'dark';
            if (dark) root.removeAttribute('data-theme'); else root.setAttribute('data-theme', 'dark');
            try { localStorage.setItem('zixun_dark', dark ? '0' : '1'); } catch (err) {}
            app.showToast(dark ? '已切换为浅色模式' : '已切换为夜间模式');
        } else if (act === 'logout') {
            await app.logout();
        }
    });
    refreshUserMenu(app);
}

/** 登录态变化时同步菜单显隐与昵称；由 updateAuthUI 调用 */
export function refreshUserMenu(app) {
    const wrap = document.getElementById('userMenu');
    if (!wrap) return;
    // 本人主页时隐藏「个人主页」菜单项，避免与当前所在页冗余
    const profItem = wrap.querySelector('[data-um="profile"]');
    if (profItem) {
        const m = location.pathname.match(/^\/u\/([^/?#]+)/);
        const onOwn = !!(m && app.user && decodeURIComponent(m[1]) === app.user.username);
        profItem.style.display = onOwn ? 'none' : '';
    }
    // 菜单自带头像触发器，隐藏旧的 #topbarAvatar，避免顶栏出现两个头像
    const legacyAvatar = document.getElementById('topbarAvatar');
    if (legacyAvatar) legacyAvatar.style.display = 'none';
    const u = app.user;
    wrap.classList.toggle('authed', !!u);
    // 管理后台入口：仅 role=admin 可见。后台是独立目录（admin/index.html），
    // 用 BASE_URL 拼接且新窗口打开，不走 PAGE_BASE / SPA 链接委托，
    // 否则伪静态关闭时会拼出 /index.php/admin 或被无刷新跳转到前台路由。
    const adminItem = wrap.querySelector('[data-um="admin"]');
    if (adminItem) adminItem.style.display = (u && u.role === 'admin') ? '' : 'none';
    if (!u) return;
    const name = document.getElementById('userMenuName');
    const handle = document.getElementById('userMenuHandle');
    const avatar = document.getElementById('userMenuAvatar');
    // 个人主页链接带上真实用户名（菜单里的 a 标签 href 需保持有效）
    const profileLink = document.getElementById('userMenuProfile');
    if (profileLink) profileLink.setAttribute('href', PAGE_PATH + '/u/' + encodeURIComponent(u.username || ''));
    if (name) name.textContent = u.nickname || u.username || '';
    if (handle) handle.textContent = '@' + (u.username || '');
    if (avatar) {
        avatar.innerHTML = '';
        if (u.avatar) {
            const img = document.createElement('img');
            img.src = app.resolveAvatar(u.avatar);
            img.alt = '';
            img.onerror = () => { avatar.textContent = (u.nickname || u.username || '?')[0]; };
            avatar.appendChild(img);
        } else {
            avatar.textContent = (u.nickname || u.username || '?')[0];
        }
    }
}

/** 频道 Tab：从站点分类动态生成；onChange 回调通知信息流刷新 */
export function renderChannelTabs(app, onChange) {
    const bar = document.getElementById('channelBar');
    if (!bar) return;
    const cats = (app.siteConfig?.categories || []).slice();
    const tabs = [{ id: 'all', name: '推荐' }, { id: 'hot', name: '热点' }]
        .concat(cats.map(c => ({ id: 'cat:' + c.id, name: c.name })));
    // 频道 Tab 仍是「同页切换」（点击不跳页），但输出真实 href，
    // 让爬虫能沿频道发现分类页 /c/{id}
    bar.innerHTML = tabs.map((t, i) => {
        // 「推荐 / 热点」指向站点首页：伪静态关闭时 PAGE_PATH + '/' 会拼出 /index.php/
        // （部分服务器直接判 404），统一用 HOME_URL（站点根）
        const href = String(t.id).startsWith('cat:') ? PAGE_PATH + '/c/' + String(t.id).slice(4) : HOME_URL;
        return `<a class="channel-tab ${i === 0 ? 'active' : ''}" data-channel="${t.id}" href="${href}">${app.escapeHtml(t.name)}</a>`;
    }).join('');
    bar.addEventListener('click', e => {
        const tab = e.target.closest('.channel-tab');
        if (!tab) return;
        e.preventDefault(); // 同页切换信息流，不整页跳转
        bar.querySelectorAll('.channel-tab').forEach(x => x.classList.remove('active'));
        tab.classList.add('active');
        app.currentChannel = tab.dataset.channel;
        if (typeof onChange === 'function') onChange(tab.dataset.channel, tab);
    });
}

export function setActiveChannel(app, channel) {
    const bar = document.getElementById('channelBar');
    if (!bar) return;
    bar.querySelectorAll('.channel-tab').forEach(x =>
        x.classList.toggle('active', x.dataset.channel === channel));
    app.currentChannel = channel;
}

/** 底部 Tab：高亮当前页 + 点击导航 */
export function initTabbar(app) {
    const bar = document.getElementById('tabbar');
    if (!bar) return;
    const path = location.pathname;
    let active = 'home';
    if (/\/video|\/ranking/.test(path)) active = 'video';
    else if (/\/search|\/topic/.test(path)) active = 'search';
    else if (/\/messages|\/chat/.test(path)) active = 'messages';
    else if (/\/u\//.test(path)) active = 'me';
    bar.querySelectorAll('.tabbar-item').forEach(item => {
        const key = item.dataset.tab;
        item.classList.toggle('active', key === active);
        item.addEventListener('click', () => {
            // 真实链接项交给链接自身（全局委托）处理，避免重复跳转
            if (item.tagName === 'A') return;
            const map = {
                home: '/', video: '/video', search: '/search', messages: '/messages',
                me: (app.user ? '/u/' + app.user.username : '/login'),
            };
            location.href = PAGE_PATH + (map[key] || '/');
        });
    });
    const pub = bar.querySelector('.tabbar-pub');
    if (pub) pub.addEventListener('click', () => { if (pub.tagName !== 'A') location.href = PAGE_PATH + '/write'; });
}

/** 结构壳：在 #page-module 渲染模块占位卡片 */
export function renderShell(app, name) {
    const mod = document.getElementById('page-module');
    if (!mod) return;
    const siteName = app.opt?.brandName || 'woquan';
    mod.innerHTML = `
      <div class="module-head">
        <button class="back-btn" style="display:flex" data-home aria-label="返回首页">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <h1>${app.escapeHtml(name)}</h1>
      </div>
      <div class="module-card">
        <p style="color:var(--sub);font-size:14px;line-height:1.7">
          「${app.escapeHtml(name)}」页面尚未开放，当前站点为 <b>${app.escapeHtml(siteName)}</b>。
        </p>
        <button class="btn-primary" style="margin-top:16px" data-home>返回首页</button>
      </div>`;
}
