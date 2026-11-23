/**
 * 公共底部导航
 * 统一所有移动端页面的底部导航结构与样式
 *
 * 用法:
 *   import { renderBottomNav } from './modules/nav.js';
 *   renderBottomNav(app, 'home');   // active: home | search | messages | favorites | profile
 */

const ICONS = {
    home: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
    messages: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
    favorites: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.29 1.51 4.04 3 5.5l7 7Z"/></svg>',
    profile: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    publish: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>'
};

function renderBottomNav(app, active) {
    const container = document.getElementById('bottom-nav');
    if (!container) return;

    // 使用 HUBLOG_PAGE_BASE（伪静态开关控制前缀，根目录为空字符串）
    const base = (typeof window.HUBLOG_PAGE_BASE === 'string')
        ? window.HUBLOG_PAGE_BASE
        : '';

    const cfg = app.siteConfig || {};
    const showSearch = cfg.nav_search_enabled !== false;
    const showMessages = cfg.nav_messages_enabled !== false;
    const showFavorites = cfg.nav_favorites_enabled !== false;
    const showProfile = cfg.nav_profile_enabled !== false;
    const showPublish = cfg.nav_publish_enabled !== false;

    // 发布按钮只在已登录且允许发帖时显示
    const canPublish = showPublish && app.checkAuth && app.checkAuth() &&
        (cfg.allow_post !== false) && (app.user && app.user.id);

    const go = (path) => {
        const full = base + path;
        if (path === '/u/profile') {
            if (app.checkAuth() && app.user) {
                window.location.assign(base + '/u/' + app.user.username);
            } else {
                app.openAuthModal();
            }
        } else if (path === '/favorites') {
            if (app.checkAuth()) {
                window.location.assign(base + '/favorites');
            } else {
                app.openAuthModal();
            }
        } else if (path === '/messages' || path === '/chat') {
            if (app.checkAuth()) {
                window.location.assign(base + path);
            } else {
                app.openAuthModal();
            }
        } else {
            window.location.assign(full);
        }
    };

    // 1. 先收集所有可用导航项（key → HTML）
    const itemMap = {};
    if (true) itemMap.home = `
        <a href="#" class="nav-item ${active === 'home' ? 'active' : ''}" data-nav="home" data-path="/">
            <span class="icon">${ICONS.home}</span>
            <span>首页</span>
        </a>`;
    if (showSearch) itemMap.search = `
        <a href="#" class="nav-item ${active === 'search' ? 'active' : ''}" data-nav="search" data-path="/search">
            <span class="icon">${ICONS.search}</span>
            <span>搜索</span>
        </a>`;
    if (showMessages) itemMap.messages = `
        <a href="#" class="nav-item ${active === 'messages' ? 'active' : ''}" data-nav="messages" data-path="/messages">
            <span class="icon">
                ${ICONS.messages}
                <em class="nav-bottom-badge" id="nav-bottom-messages-badge" style="display:none;">0</em>
            </span>
            <span>消息</span>
        </a>`;
    if (showFavorites) itemMap.favorites = `
        <a href="#" class="nav-item ${active === 'favorites' ? 'active' : ''}" data-nav="favorites" data-path="/favorites">
            <span class="icon">${ICONS.favorites}</span>
            <span>收藏</span>
        </a>`;
    if (showProfile) itemMap.profile = `
        <button class="nav-item ${active === 'profile' ? 'active' : ''}" data-nav="profile" data-path="/u/profile">
            <span class="icon">${ICONS.profile}</span>
            <span>我的</span>
        </button>`;

    // 2. 按后台配置顺序排列（nav_items_order），不在配置里的追加到末尾
    const defaultOrder = ['home', 'search', 'messages', 'favorites', 'profile'];
    const customOrder = (cfg.nav_items_order || '').split(',').map(s => s.trim()).filter(Boolean);
    const orderList = customOrder.length ? customOrder : defaultOrder;
    const allItems = [];
    const seen = new Set();
    for (const key of orderList) {
        if (itemMap[key] && !seen.has(key)) { allItems.push(itemMap[key]); seen.add(key); }
    }
    // 兜底：itemMap 里还有但没出现在 orderList 里的
    for (const key of defaultOrder) {
        if (itemMap[key] && !seen.has(key)) { allItems.push(itemMap[key]); seen.add(key); }
    }

    // 3. 均衡分配：左边 ceil(n/2) 个，右边 floor(n/2) 个
    //    保证 FAB 两边图标数量最多差 1
    const leftCount = Math.ceil(allItems.length / 2);
    const leftItems = allItems.slice(0, leftCount);
    const rightItems = allItems.slice(leftCount);

    // 3. 中间悬浮发布按钮
    const publishBtn = canPublish ? `
        <button class="nav-publish-fab" id="nav-publish-btn" data-nav="publish" aria-label="发布">
            <span class="nav-publish-icon">${ICONS.publish}</span>
        </button>` : '';

    // 4. 渲染：左右两半 flex:1 等宽 → FAB 始终居中；
    //    半区内部 nav-item flex:1 自动均分
    container.innerHTML = `
        <div class="nav-half nav-left">${leftItems.join('')}</div>
        ${publishBtn}
        <div class="nav-half nav-right">${rightItems.join('')}</div>
    `;

    // 初始化角标（根据 app 中的未读计数）
    updateMessagesBadge(app);

    // 发布按钮点击
    const publishEl = document.getElementById('nav-publish-btn');
    if (publishEl) {
        publishEl.addEventListener('click', function (e) {
            e.preventDefault();
            if (typeof app.openPublishModal === 'function') {
                app.openPublishModal();
            }
        });
    }

    // 常规导航点击
    container.querySelectorAll('.nav-item').forEach(item => {
        item.addEventListener('click', function (e) {
            e.preventDefault();
            const path = this.dataset.path;
            if (path) go(path);
        });
    });
}

/**
 * 更新底部导航"消息"tab 的合并角标（通知未读 + 私信未读）
 * 当 notification / chat 模块的未读数变化时，外部调用此方法刷新
 */
function updateMessagesBadge(app) {
    const el = document.getElementById('nav-bottom-messages-badge');
    if (!el) return;
    const notify = app.notificationUnread || 0;
    const chat = app.chatUnread || 0;
    const total = notify + chat;
    if (total > 0) {
        el.textContent = total > 99 ? '99+' : String(total);
        el.style.display = 'inline-flex';
    } else {
        el.style.display = 'none';
    }
}

export { renderBottomNav, updateMessagesBadge };
