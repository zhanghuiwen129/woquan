/**
 * woquan Moments 前端应用
 * ES Module 主入口
 *
 * 注意：所有模块导入需带 ?v= 版本号，避免浏览器缓存旧版模块导致功能异常。
 * 更新任意模块后，请同步递增所有导入路径中的版本号（建议使用日期戳 YYYYMMDD + 序号）。
 */
import * as api from './modules/api.js?v=20261117.1';
import * as auth from './modules/auth.js?v=20261115.2';
import * as timeline from './modules/timeline.js?v=20261117.1';
import * as interact from './modules/interact.js?v=20261117.1';
import * as publish from './modules/publish.js?v=20261117.1';
import * as ui from './modules/ui.js?v=20261117.1';
import * as events from './modules/events.js?v=20261117.1';
import * as emoji from './modules/emoji.js?v=20261117.1';
import * as utils from './modules/utils.js?v=20261117.1';
import * as follow from './modules/follow.js?v=20261117.1';
import * as search from './modules/search.js?v=20261117.1';
import * as favorite from './modules/favorite.js?v=20261117.1';
import * as report from './modules/report.js?v=20261117.1';
import * as notification from './modules/notification.js?v=20261116.2';
import * as chat from './modules/chat.js?v=20261117.3';
import { renderBottomNav, updateMessagesBadge } from './modules/nav.js?v=20261117.1';
import './modules/theme.js?v=20261117.1';
import { BASE_URL } from './modules/config.js?v=20261117.1';
// P3-19：引入 EventBus，用于模块间解耦通信
import { bus } from './modules/events-bus.js?v=20261117.1';

class HublogApp {
    constructor() {
        // P0-3：令牌持久化已改为 httpOnly Cookie，JS 侧读不到、也不再往 localStorage 写。
        // 因此「是否已登录」不能再靠 app.token 判断（刷新后必然是 null），
        // 而要以服务端会话为准 —— 见 authed 字段。
        this.token = localStorage.getItem('hublog_token');
        this.user = null;
        try {
            this.user = JSON.parse(localStorage.getItem('hublog_user') || 'null');
        } catch (e) {
            this.user = null;
        }
        /**
         * 服务端确认的登录态（权威判据）。
         *
         * null = 尚未向服务端确认（页面刚加载，/auth/me 还没回来）
         * true = 服务端认得这个会话（Cookie 有效）
         * false = 确实未登录
         *
         * 所有「要不要弹登录框」的判断都必须看它，而不是看 app.token ——
         * 后者在 Cookie 模式下刷新后恒为 null，会把已登录用户误判成游客。
         */
        this.authed = null;
        this.csrfToken = '';
        this.siteConfig = null;
        this.page = 1;
        this.loading = false;
        this.hasMore = true;
        this.currentCommentPostId = null;
        this.currentReplyCommentId = null;
        this.commentBlurTimeout = null;
        this.commentImageUrl = '';
        this.isSelectingCommentImage = false;
        this.viewerImages = [];
        this.viewerIndex = 0;
        this.notifications = [];
        this.notificationPage = 1;
        this.notificationHasMore = true;
        this.notificationUnread = 0;
        this.notificationLoading = false;
        this.notificationPollingInterval = null;
        this.emojiPanel = null;
        this.emojiTarget = null;
        this.emojiAnchor = null;
        this.uploadCache = new Map();
        this.latestPostId = 0;
        this.latestCommentId = 0;
        this.latestLikeId = 0;
        this.newPostCount = 0;
        this.refreshPollingInterval = null;
        this.chatUnread = 0;
    }

    _detectNavActive() {
        const path = window.location.pathname;
        if (path.includes('/search')) return 'search';
        if (path.includes('/messages') || path.includes('/chat')) return 'messages';
        if (path.includes('/favorites')) return 'favorites';
        if (path.match(/\/u\/[^\/]+/) || path.includes('/profile')) return 'profile';
        return 'home';
    }

    /** 更新底部导航消息角标（通知 + 私信合计），同时刷新标签页角标 */
    updateMessagesBadge() {
        updateMessagesBadge(this);
        // 消息标签页角标（仅在消息中心页存在）
        const notifyBadge = document.getElementById('msg-tab-notify-badge');
        if (notifyBadge) {
            const n = this.notificationUnread || 0;
            if (n > 0) {
                notifyBadge.textContent = n > 99 ? '99+' : String(n);
                notifyBadge.style.display = 'inline-flex';
            } else {
                notifyBadge.style.display = 'none';
            }
        }
        const chatBadge = document.getElementById('msg-tab-chat-badge');
        if (chatBadge) {
            const c = this.chatUnread || 0;
            if (c > 0) {
                chatBadge.textContent = c > 99 ? '99+' : String(c);
                chatBadge.style.display = 'inline-flex';
            } else {
                chatBadge.style.display = 'none';
            }
        }
    }

    /** 切换消息标签页（通知 / 私信） */
    switchMessagesTab(tabName) {
        const tabs = document.querySelectorAll('#messages-tabs .msg-tab');
        tabs.forEach(t => {
            t.classList.toggle('active', t.dataset.tab === tabName);
        });
        const panels = document.querySelectorAll('.messages-tab-panel');
        panels.forEach(p => {
            p.classList.toggle('active', p.id === 'panel-' + tabName);
        });
        // 切换到私信面板，懒加载一次会话列表
        if (tabName === 'chat' && !this._messagesChatLoaded) {
            this._messagesChatLoaded = true;
            if (typeof this.loadMessagesPageConversations === 'function') {
                this.loadMessagesPageConversations(20);
            }
        }
    }

    /** 绑定消息标签页点击 */
    bindMessagesTabs() {
        const tabsEl = document.getElementById('messages-tabs');
        if (!tabsEl || tabsEl._bound) return;
        tabsEl._bound = true;
        tabsEl.querySelectorAll('.msg-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const name = tab.dataset.tab;
                if (name) this.switchMessagesTab(name);
            });
        });
    }

    /**
     * V67 / V68：给左栏「功能菜单」补两个 V 级入口（会员中心 / 积分中心）。
     * 菜单在 14 个页面里是硬编码的同一份，逐页改 HTML 既重复又会漏；
     * 这里统一在运行时往 .nav-list 里插，并排安置在「管理后台」之前。
     * 未登录不插（点了也没法下单），避免给游客一个 404 入口。
     */
    renderSidebarNavExtras() {
        const list = document.querySelector('.nav-list');
        // 先摘干净：登录态切换（登录 / 退出）后本函数是唯一入口，重复插会越点越多
        if (list) list.querySelectorAll('[data-nav-extra]').forEach((el) => el.remove());
        if (!list || !(this.user && this.user.id)) return;

        const icon = (inner) =>
            `<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${inner}</svg>`;
        const awardIcon = icon('<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>');
        const giftIcon = icon('<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>');

        const adminNode = document.getElementById('nav-admin');
        const items = [
            { id: 'nav-vip', label: '会员中心', icon: awardIcon, path: '/wallet' },
            { id: 'nav-points', label: '积分中心', icon: giftIcon, path: '/points' }
        ];
        items.forEach((it) => {
            const li = document.createElement('li');
            li.id = it.id;
            li.setAttribute('data-nav-extra', '1');
            li.innerHTML = `<a href="#" class="nav-link" onclick="event.preventDefault(); if(app.checkAuth()) { location.assign(window.HUBLOG_PAGE_BASE + '${it.path}'); } else { app.openAuthModal(); }">${it.icon}<span>${it.label}</span></a>`;
            if (adminNode) list.insertBefore(li, adminNode);
            else list.appendChild(li);
        });
    }

    async init() {
        await this.fetchCsrf();
        await this.fetchUser();
        await this.loadSiteConfig();

        const navActive = this._detectNavActive();
        renderBottomNav(this, navActive);
        this.renderSidebarNavExtras();

        this.initEmojiPicker();
        this.bindEvents();
        this.updateAuthUI();
        this.loadSidebarData();

        const body = document.body;
        const isDetail = body?.dataset.page === 'post-detail';
        const detailPostId = body?.dataset.postId;

        if (isDetail && detailPostId) {
            this.loadPostDetail(parseInt(detailPostId, 10));
        } else if (body?.dataset.page === 'search') {
            this.initSearchPage();
            // 搜索逻辑已在 initSearchPage 内部处理（包括热门动态和搜索结果）
        } else if (body?.dataset.page === 'favorites') {
            // 收藏页面：等待 favorites 内联脚本加载收藏列表
            // 此处仅确保基础初始化完成
        } else if (body?.dataset.page === 'chat') {
            // 聊天页面
            // P0-3：登录态看 app.authed（服务端确认的会话状态），不看 app.token
            // —— Cookie 模式下 app.token 刷新后恒为 null，用它会把已登录用户挡在门外。
            if (this.authed !== false) {
                this.initChatPage();
                if (typeof this.initChatPageNewConversationBtn === 'function') this.initChatPageNewConversationBtn();
            } else {
                this.openAuthModal();
            }
        } else if (body?.dataset.page === 'messages') {
            // 消息中心页面
            if (this.authed !== false) {
                this.initMessagesPage();
            } else {
                this.openAuthModal();
            }
        } else if (document.getElementById('moments-list')) {
            await this.loadTimeline();
            this.setupInfiniteScroll();
            this.startRefreshPolling();
        }
        if (this.authed !== false) {
            this.loadUnreadCount();
            this.startNotificationPolling();
            this.startUnreadPolling();
        }

        // 首页右栏推荐关注（有容器才加载，避免无关页面报错）
        if (document.getElementById('suggest-card')) {
            if (typeof this.loadSuggestUsers === 'function') {
                this.loadSuggestUsers();
            }
        }

        // P3-19：注册 EventBus 订阅，将事件转为对应模块调用
        // 这里的订阅是"附加响应"，原有直接调用保持不变，保证向后兼容
        this._registerEventSubscriptions();
    }

    /**
     * 注册 EventBus 订阅（P3-19 解耦试点）
     * - 登录后自动连接 SSE 实时推送
     * - 登出后关闭 SSE 连接、重置未读状态
     * 未来新模块只需 bus.on(...) 即可响应，无需修改 auth.js
     */
    _registerEventSubscriptions() {
        // 登录成功：建立 SSE 连接（与现有 startNotificationPolling 互为补充）
        bus.on('auth:login', () => {
            if (typeof this.initSSE === 'function') {
                this.initSSE();
            }
        });

        // 登出：关闭 SSE、清理重试定时器
        bus.on('auth:logout', () => {
            if (this.sse) {
                try { this.sse.close(); } catch (_) {}
                this.sse = null;
            }
            if (this.sseRetryTimer) {
                clearTimeout(this.sseRetryTimer);
                this.sseRetryTimer = null;
            }
            this.sseConnected = false;
            this.chatUnread = 0;
        });
    }
}

/**
 * 将模块方法混入原型
 * 模块函数签名: fn(app, ...args)，需要绑定 this 为首参数
 */
function applyMixin(proto, module) {
    for (const [name, fn] of Object.entries(module)) {
        if (typeof fn === 'function') {
            proto[name] = function (...args) { return fn(this, ...args); };
        }
    }
}

// 混入各模块方法
applyMixin(HublogApp.prototype, api);
applyMixin(HublogApp.prototype, auth);
applyMixin(HublogApp.prototype, timeline);
applyMixin(HublogApp.prototype, interact);
applyMixin(HublogApp.prototype, publish);
applyMixin(HublogApp.prototype, notification);
applyMixin(HublogApp.prototype, ui);
applyMixin(HublogApp.prototype, events);
applyMixin(HublogApp.prototype, emoji);
applyMixin(HublogApp.prototype, follow);
applyMixin(HublogApp.prototype, search);
applyMixin(HublogApp.prototype, favorite);
applyMixin(HublogApp.prototype, report);
applyMixin(HublogApp.prototype, chat);

// utils 模块的函数不接收 app 参数，直接挂载到原型
Object.assign(HublogApp.prototype, utils);

// 覆盖 formatText 使其匹配原始类方法签名 formatText(text)
HublogApp.prototype.formatText = function (text) {
    return utils.formatText(text, BASE_URL, this.escapeHtml);
};

window.app = new HublogApp();
document.addEventListener('DOMContentLoaded', () => window.app.init());
