/**
 * shaiboblog 前端入口（ES Module）
 * 按当前路径分发：首页信息流 / 文章详情 / 分类 / 话题 / 搜索 / 个人页 / 写作 / 消息
 */
import { PAGE_BASE, HOME_URL } from './modules/config.js?v=20261116.12';
import * as api from './modules/api.js?v=20261116.12';
import * as utils from './modules/utils.js?v=20261116.12';
import * as nav from './modules/nav.js?v=20261116.12';
import * as auth from './modules/auth.js?v=20261116.12';
import * as feed from './modules/feed.js?v=20261116.12';
import * as article from './modules/article.js?v=20261116.12';
import * as category from './modules/category.js?v=20261116.12';
import * as search from './modules/search.js?v=20261116.12';
import * as user from './modules/user.js?v=20261116.12';
import * as messages from './modules/messages.js?v=20261116.12';
import * as topic from './modules/topic.js?v=20261116.12';
import * as write from './modules/write.js?v=20261121.01';
import * as login from './modules/login.js?v=20261116.12';
import * as account from './modules/account.js?v=20261116.12';
import * as profileLists from './modules/profileLists.js?v=20261116.12';
import * as sidebar from './modules/sidebar.js?v=20261116.12';
import { expandIncludes } from './modules/includes.js?v=20261116.12';
import { initCyberFx } from './modules/cyberFx.js?v=20261116.12';
import { initBgFx } from './modules/bgFx.js?v=20261116.12';
import { initCursorFx } from './modules/cursorFx.js?v=20261116.12';
import { initWarpFx } from './modules/warpFx.js?v=20261116.12';

const app = {
    token: localStorage.getItem('hublog_token'),
    user: null, authed: null, csrfToken: '', siteConfig: null,
    page: 1, loading: false, hasMore: true, currentChannel: 'all',
    opt: {
        showHero: true, showSidebar: true, brandName: '赛博博客',
        brandSub: 'SHAIBOBLOG',
        heroTitle: '在代码与文字之间，记录每一次思考',
        heroDesc: '记录路亚、夜钓与装备心得，偶尔写点技术笔记。文章按分类与归档沉淀下来，方便日后检索与回看。',
        aboutText: '', footerText: '',
    },

    request(url, opts = {}) { return api.request(this, url, opts); },
    fetchCsrf() { return api.fetchCsrf(this); },
    showToast(msg) { nav.toast(msg); },
    escapeHtml: utils.escapeHtml,
    escapeJs: utils.escapeJs,
    renderMarkdown: utils.renderMarkdown,
    safeUrl: utils.safeUrl,
    resolveAvatar(u) { return auth.resolveAvatar(this, u); },
    checkAuth() { return auth.checkAuth(this); },
    goLogin() { auth.goLogin(this); },
    updateAuthUI() { auth.updateAuthUI(this); },
    fetchUser() { return auth.fetchUser(this); },
    logout() { return auth.logout(this); },
    openAccountPanel(tab) { return account.openAccountPanel(this, tab); },
    goUser(u) { location.href = PAGE_BASE + '/u/' + encodeURIComponent(u); },
    refreshSidebars(posts) { return sidebar.fillSidebars(this, posts); },
};

window.__shaibo_app = app;

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
    user: '个人主页', followers: '粉丝', following: '关注', pinned: '置顶文章',
    favorites: '我的收藏', go: '外链跳转', category: '分类', topic: '话题',
    circles: '圈子', circle: '圈子详情', search: '搜索', chat: '私信',
    messages: '消息中心', 'reset-password': '重置密码', wallet: '钱包', points: '积分',
};

/**
 * 应用后台外观选项。首屏内联 window.__THEME_OPTIONS__ 优先避免闪变，
 * 异步 /site/config 返回的 theme_options 最终覆盖，保证后台保存即时生效。
 */
function applyThemeOptions(app) {
    const inline = (typeof window !== 'undefined' && window.__THEME_OPTIONS__) || {};
    const asyncOpts = (app.siteConfig && app.siteConfig.theme_options) || {};
    const opts = { ...inline, ...asyncOpts };
    const root = document.documentElement;
    const setVar = (k, v) => { if (v) root.style.setProperty(k, v); };

    setVar('--brand', opts.primary_color);
    setVar('--accent', opts.accent_color);
    setVar('--bg', opts.bg_color);
    setVar('--card', opts.card_color);
    if (opts.text_color) { setVar('--ink', opts.text_color); setVar('--body', opts.text_color); }
    if (opts.content_width) setVar('--maxw', opts.content_width + 'px');

    // 赛博博客为深色主题，始终锁定 dark，避免跟随系统回落到浅色组件。
    root.setAttribute('data-theme', 'dark');

    // 可选氛围特效：把后台外观设置写到 body，引擎据此切换（含运行时热切换）
    const bgFx = opts.bg_effect || 'aurora';
    const cursorFx = opts.cursor_effect || 'glow';
    document.body.dataset.bgFx = bgFx;
    document.body.dataset.cursorFx = cursorFx;
    if (typeof window.__setBgFx === 'function') window.__setBgFx(bgFx);
    if (typeof window.__setCursorFx === 'function') window.__setCursorFx(cursorFx);

    app.opt = {
        showHero: opts.show_hero !== false,
        showHeroStats: opts.show_hero_stats !== false,
        heroStyle: opts.hero_style || 'terminal',
        bgEffect: bgFx,
        cursorEffect: cursorFx,
        showSidebar: opts.show_sidebar !== false,
        sideAbout: opts.side_about !== false,
        sideCategories: opts.side_categories !== false,
        sideArchive: opts.side_archive !== false,
        sideLatest: opts.side_latest !== false,
        sideTags: opts.side_tags !== false,
        brandName: utils.resolveBrandName(opts, app.siteConfig),
        brandSub: (typeof opts.brand_sub === 'string' && opts.brand_sub.trim()) || 'SHAIBOBLOG',
        heroKicker: opts.hero_kicker || '// PERSONAL BLOG · EST.2026',
        heroTitle: opts.hero_title || '在代码与文字之间，记录每一次思考',
        heroDesc: opts.hero_desc || '记录路亚、夜钓与装备心得，偶尔写点技术笔记。',
        heroTermTitle: opts.hero_term_title || '~/blog',
        heroTermCmdUser: opts.hero_term_cmd_user || '$ whoami',
        heroTermUser: opts.hero_term_user || 'shaibo —— writer & angler',
        heroTermCmdFile: opts.hero_term_cmd_file || '$ cat manifesto.md',
        heroTermManifesto: (typeof opts.hero_term_manifesto === 'string' && opts.hero_term_manifesto.trim())
            ? opts.hero_term_manifesto
            : '把每一次抛投、每一段代码，\n都写进可检索的档案。',
        heroTermPrompt: opts.hero_term_prompt || '$ _',
        aboutText: opts.about_text || '',
        aboutAvatar: opts.about_avatar || '',
        footerText: opts.footer_text || '',
        icpText: opts.icp_text || '',
    };

    utils.applyBrandNameToDOM(app.opt.brandName);

    const brandSub = document.getElementById('brandSub');
    if (brandSub) brandSub.textContent = app.opt.brandSub;

    const heroKicker = document.getElementById('heroKicker');
    if (heroKicker) heroKicker.textContent = app.opt.heroKicker;
    const heroTitle = document.getElementById('heroTitle');
    if (heroTitle) heroTitle.textContent = app.opt.heroTitle;
    const heroDesc = document.getElementById('heroDesc');
    if (heroDesc) heroDesc.textContent = app.opt.heroDesc;
    const heroTermTitle = document.getElementById('heroTermTitle');
    if (heroTermTitle) heroTermTitle.textContent = app.opt.heroTermTitle;
    const heroTerm = document.getElementById('heroTermCode');
    if (heroTerm) {
        const manifesto = app.opt.heroTermManifesto
            .split(/\r?\n/).map(s => s.trim()).filter(Boolean)
            .map(s => '> ' + s).join('\n');
        heroTerm.textContent = app.opt.heroTermCmdUser + '\n> ' + app.opt.heroTermUser
            + '\n' + app.opt.heroTermCmdFile + '\n' + manifesto + '\n' + app.opt.heroTermPrompt;
    }
    const hero = document.getElementById('hero');
    if (hero) hero.style.display = app.opt.showHero ? '' : 'none';
    const heroStats = document.querySelector('.hero-stats');
    if (heroStats) heroStats.style.display = app.opt.showHeroStats ? '' : 'none';
    document.body.classList.toggle('hero-minimal', app.opt.heroStyle === 'minimal');
    document.body.classList.toggle('hero-spotlight', app.opt.heroStyle === 'spotlight');

    const aboutText = document.getElementById('aboutText');
    if (aboutText && app.opt.aboutText) aboutText.textContent = app.opt.aboutText;
    const aboutAvatar = document.getElementById('aboutAvatar');
    if (aboutAvatar) {
        aboutAvatar.onerror = () => { aboutAvatar.onerror = null; aboutAvatar.src = 'assets/images/default-avatar.svg'; };
        aboutAvatar.src = app.opt.aboutAvatar || 'assets/images/default-avatar.svg';
    }

    const footerText = document.getElementById('footerText');
    if (footerText && app.opt.footerText) footerText.textContent = app.opt.footerText;
    const icpText = document.getElementById('icpText');
    if (icpText) icpText.textContent = app.opt.icpText ? ' · ' + app.opt.icpText : '';

    document.body.classList.toggle('no-sidebar', !app.opt.showSidebar);
    // 侧栏各模块可独立开关；整列模块全关时收起该列，版面不留空档
    [
        ['aboutPanel', app.opt.sideAbout],
        ['catPanel', app.opt.sideCategories],
        ['archivePanel', app.opt.sideArchive],
        ['latestPanel', app.opt.sideLatest],
        ['tagPanel', app.opt.sideTags],
    ].forEach(([id, on]) => {
        const el = document.getElementById(id);
        if (el) el.style.display = on ? '' : 'none';
    });
    ['.side-left', '.side-right'].forEach(sel => {
        const side = document.querySelector(sel);
        if (!side) return;
        const anyVisible = Array.from(side.children).some(el => el.style.display !== 'none');
        side.style.display = (app.opt.showSidebar && anyVisible) ? '' : 'none';
        document.body.classList.toggle(sel === '.side-left' ? 'hide-side-left' : 'hide-side-right',
            !(app.opt.showSidebar && anyVisible));
    });
}

function bindHomeAnchors() {
    document.querySelectorAll('[data-home-anchor]').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const id = link.getAttribute('data-home-anchor');
            const el = document.getElementById(id);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }
            location.href = HOME_URL + '#' + id;
        });
    });
}

async function boot() {
    try {
        await expandIncludes();
    } catch (e) {
        console.error('[shaiboblog] 展开公共区块异常，继续以降级模式启动', e);
    }
    try {
        utils.initSpaLinks();
        nav.mountTopbar(app);
        applyThemeOptions(app);
        nav.initTabbar(app);
        nav.initHomeLinks();
        bindHomeAnchors();
    } catch (e) {
        console.error('[shaiboblog] 导航初始化异常，页面将以最小可用状态运行', e);
    }

    await Promise.all([
        app.fetchCsrf().catch(() => {}),
        app.fetchUser().catch(() => {}),
        loadSiteConfig().catch(() => {}),
    ]);
    app.updateAuthUI();
    applyThemeOptions(app);
    nav.mountTopbar(app);
    nav.mountUserMenu(app);
    // 赛博特效：必须在第二次 applyThemeOptions（文本/选项为最终值）之后初始化
    initCyberFx();
    // 可选氛围特效：背景 + 鼠标，由后台外观设置驱动；保存切换函数供 applyThemeOptions 热切换
    window.__setBgFx = initBgFx(app.opt.bgEffect);
    window.__setCursorFx = initCursorFx(app.opt.cursorEffect);
    // 详情页时光穿梭转场（对 /p/<id> 链接生效，减动偏好自动跳过）
    initWarpFx();

    const year = document.getElementById('footerYear');
    if (year) year.textContent = String(new Date().getFullYear());

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
    if (document.getElementById('bodyInput')) { write.renderWrite(app); return; }
    if (document.getElementById('loginRoot')) { login.renderLogin(app); return; }

    if (document.getElementById('plList')) {
        const listType = ['followers', 'following', 'favorites', 'pinned']
            .find(k => new RegExp('/(?:u/[^/]+/)?' + k + '/?$').test(path));
        if (listType) { profileLists.renderProfileList(app, listType); return; }
    }

    if (document.getElementById('page-module')) {
        const listType = ['followers', 'following', 'favorites', 'pinned']
            .find(k => new RegExp('/(?:u/[^/]+/)?' + k + '/?$').test(path));
        if (listType) { profileLists.renderProfileList(app, listType); return; }
        let name = '模块';
        for (const k in SHELL_NAMES) { if (path.includes('/' + k)) { name = SHELL_NAMES[k]; break; } }
        nav.renderShell(app, name);
        return;
    }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
else boot();
