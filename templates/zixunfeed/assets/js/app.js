/**
 * zixunfeed 前端入口（ES Module）
 * 按当前路径分发：首页信息流 / 文章详情 / 结构壳模块
 */
import { API_BASE, PAGE_BASE, HOME_URL, PAGE_PATH } from './modules/config.js?v=20261117.02';
import * as api from './modules/api.js?v=20261117.02';
import * as utils from './modules/utils.js?v=20261117.02';
import * as nav from './modules/nav.js?v=20261117.02';
import * as auth from './modules/auth.js?v=20261117.02';
import * as feed from './modules/feed.js?v=20261117.02';
import * as article from './modules/article.js?v=20261117.02';
import * as category from './modules/category.js?v=20261117.02';
import * as search from './modules/search.js?v=20261117.02';
import * as user from './modules/user.js?v=20261117.02';
import * as messages from './modules/messages.js?v=20261117.02';
import * as topic from './modules/topic.js?v=20261117.02';
import * as video from './modules/video.js?v=20261117.02';
import * as ranking from './modules/ranking.js?v=20261117.02';
import * as write from './modules/write.js?v=20261121.01';
import * as login from './modules/login.js?v=20261117.02';
import * as account from './modules/account.js?v=20261117.02';
import * as profileLists from './modules/profileLists.js?v=20261117.02';
import { expandIncludes } from './modules/includes.js?v=20261117.02';

const app = {
    token: localStorage.getItem('hublog_token'),
    user: null, authed: null, csrfToken: '', siteConfig: null,
    page: 1, loading: false, hasMore: true, currentChannel: 'all',
    // 主题外观选项（后台「主题外观」设置），由 applyThemeOptions 填充，供 feed 构建时判断显隐
    opt: { showHero: true, showFlash: true, showSidebar: true, brandName: 'woquan' },

    request(url, opts = {}) { return api.request(this, url, opts); },
    fetchCsrf() { return api.fetchCsrf(this); },
    showToast(msg) { nav.toast(msg); },
    escapeHtml: utils.escapeHtml,
    escapeJs: utils.escapeJs,
    renderMarkdown: utils.renderMarkdown,
    safeUrl: utils.safeUrl,
    resolveAvatar(u) { return auth.resolveAvatar(this, u); },
    checkAuth() { return auth.checkAuth(this); },
    openAuthModal() { auth.openAuthModal(this); },
    openRegister() { auth.goLogin(this, 'register'); },
    updateAuthUI() { auth.updateAuthUI(this); },
    fetchUser() { return auth.fetchUser(this); },
    logout() { return auth.logout(this); },
    openAccountPanel(tab) { return account.openAccountPanel(this, tab); },
    goUser(u) { location.href = PAGE_PATH + '/u/' + encodeURIComponent(u); },
};

// 暴露给内联回调与调试（登录/注册统一跳 /login 独立页）
window.__zixun_app = app;
window.__zixun_auth = auth;

function currentPath() {
    let p = location.pathname;
    if (window.HUBLOG_PAGE_BASE) {
        const base = window.HUBLOG_PAGE_BASE.replace(/\/$/, '');
        if (base && p.startsWith(base)) p = p.slice(base.length) || '/';
    }
    return p || '/';
}

async function loadSiteConfig() {
    try {
        const cfg = await app.request('/site/config');
        app.siteConfig = cfg || {};
    } catch (e) { app.siteConfig = {}; }
}

const SHELL_NAMES = {
    user: '个人主页', followers: '粉丝', following: '关注', pinned: '置顶动态',
    favorites: '我的收藏', go: '外链跳转', category: '分类', topic: '话题',
    circles: '圈子', circle: '圈子详情', search: '搜索', chat: '私信',
    messages: '消息中心', 'reset-password': '重置密码', wallet: '钱包', points: '积分',
};

/**
 * 应用后台「主题外观」选项：配色 / 暗黑 / 品牌名 / 显隐开关。
 * 选项来自 index.php 首屏内联的 window.__THEME_OPTIONS__（优先，无闪变），
 * 兜底 app.siteConfig.theme_options（异步 /site/config 返回）。
 */
function applyThemeOptions(app) {
    const inline = (typeof window !== 'undefined' && window.__THEME_OPTIONS__) || {};
    const asyncOpts = (app.siteConfig && app.siteConfig.theme_options) || {};
    // 首屏内联仅作首屏占位避免闪变；异步 /site/config 是最终权威配置（后台保存后必以它为准），
    // 故 asyncOpts 在后覆盖 inline，确保后台改完立即生效，不被首屏旧值覆盖（含页面/CDN 缓存场景）。
    const opts = { ...inline, ...asyncOpts };
    const root = document.documentElement;
    const setVar = (k, v) => { if (v) root.style.setProperty(k, v); };

    if (opts.brand_color) setVar('--brand', opts.brand_color);
    if (opts.accent_color) setVar('--accent', opts.accent_color);
    if (opts.bg_color) setVar('--bg', opts.bg_color);
    if (opts.card_color) setVar('--card', opts.card_color);
    if (opts.text_color) { setVar('--ink', opts.text_color); setVar('--body', opts.text_color); }
    if (opts.card_radius) setVar('--radius', opts.card_radius + 'px');
    if (opts.content_width) setVar('--maxw', opts.content_width + 'px');

    // 深色模式优先级：用户手动选择 > 后台默认。
    // 手动选择必须跨页面保持，否则除个人主页外刷新后会回落到浅色（历史缺陷）。
    let manualDark = null;
    try { manualDark = localStorage.getItem('zixun_dark'); } catch (e) {}
    const useDark = manualDark === null ? !!opts.dark_mode : manualDark === '1';
    if (useDark) root.setAttribute('data-theme', 'dark');
    else root.removeAttribute('data-theme');

    app.opt = {
        showHero: opts.show_hero !== false,
        showFlash: opts.show_flash !== false,
        showSidebar: opts.show_sidebar !== false,
        brandName: utils.resolveBrandName(opts, app.siteConfig),
    };

    utils.applyBrandNameToDOM(app.opt.brandName);

    // 详情页右栏（热门阅读 / 相关推荐）随「侧边栏」开关隐藏
    if (!app.opt.showSidebar) {
        const ad = document.querySelector('.aside-detail');
        if (ad) ad.style.display = 'none';
    }
}

async function boot() {
    // 公共顶栏 / 底栏 / 挂载点必须先展开，再做任何 DOM 查询与事件绑定。
    // expandIncludes 内部已对单个 partial 做超时 + 内容校验 + 内联兜底，正常不会 reject；
    // 这里再兜一层，确保任何意外都不会让后续初始化整段中断（否则整页只剩空壳）。
    try {
        await expandIncludes();
    } catch (e) {
        console.error('[zixunfeed] 展开公共区块异常，继续以降级模式启动', e);
    }
    try {
        utils.initSpaLinks(); // 站内真实链接的无刷新跳转（无 JS 时链接依然可用）
        nav.mountTopbar(app);
        applyThemeOptions(app); // 首屏即应用外观选项（配色/暗黑/品牌名/显隐）
        nav.initTabbar(app);
        nav.initHomeLinks();
    } catch (e) {
        console.error('[zixunfeed] 导航初始化异常，页面将以最小可用状态运行', e);
    }

    // CSRF、登录态、站点配置互不依赖，并行拉取，避免首屏多等两个 RTT。
    // 每一项都单独 catch：任一接口异常都不能中断 boot，否则整页内容都不渲染（历史白屏成因）。
    await Promise.all([
        app.fetchCsrf().catch(() => {}),
        app.fetchUser().catch(() => {}),
        loadSiteConfig().catch(() => {}),
    ]);
    app.updateAuthUI();
    // 站点配置到达后再应用一次：品牌名/显隐以最终配置为准，且不再被二次覆盖
    applyThemeOptions(app);
    nav.mountTopbar(app);
    nav.mountUserMenu(app);

    const onChannel = (ch, tabEl) => {
        if (document.getElementById('feed')) feed.reloadFeed(app);
        else if (tabEl && tabEl.getAttribute('href')) location.href = tabEl.getAttribute('href');
        else location.href = HOME_URL;
    };
    if (document.getElementById('channelBar')) nav.renderChannelTabs(app, onChannel);

    const path = currentPath();
    const pd = path.match(/\/p\/(\d+)/);
    if (pd && document.getElementById('articleHost')) {
        article.renderArticle(app, pd[1]);
        return;
    }
    if (document.getElementById('feed')) {
        const cm = path.match(/\/c\/(\d+)/);
        if (cm) { category.renderCategory(app, cm[1]); return; }
        feed.loadFeed(app, false);
        feed.setupInfiniteScroll(app);
        return;
    }
    if (document.getElementById('searchFeed')) {
        search.renderSearch(app);
        return;
    }
    if (document.getElementById('userGrid')) { user.renderUser(app); return; }
    if (document.getElementById('noticeTabs')) { messages.renderMessages(app); return; }
    if (document.getElementById('topicFeed')) { topic.renderTopic(app); return; }
    if (document.getElementById('vGrid')) { video.renderVideo(app); return; }
    if (document.getElementById('boardTabs')) { ranking.renderRanking(app); return; }
    if (document.getElementById('bodyInput')) { write.renderWrite(app); return; }
    if (document.getElementById('loginRoot')) { login.renderLogin(app); return; }
    if (document.getElementById('page-module')) {
        // 个人主页关联页接真实数据，不再显示占位结构壳
        const listType = ['followers', 'following', 'favorites', 'pinned']
            .find(k => new RegExp('/(?:u/[^/]+/)?' + k + '/?$').test(path));
        if (listType) { profileLists.renderProfileList(app, listType); return; }
        let name = SHELL_NAMES['home'] || '模块';
        for (const k in SHELL_NAMES) { if (path.includes('/' + k)) { name = SHELL_NAMES[k]; break; } }
        nav.renderShell(app, name);
        return;
    }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
else boot();
