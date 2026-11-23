// ============================================================
// nav-ui.js — 外壳交互：页面切换、侧边栏分组、移动端菜单
// 由 V166 后台拆分脚本从 admin/index.html 切出；B1 起导航类动作改为 data-action 委托（与源文件不再逐字节一致）
// ============================================================
import { registerActions, BASE_URL, showToast, request } from './core.js';
import { checkVersion, loadDashboard } from './dashboard.js';



// P1-2 懒加载：页 → 模块动态加载映射。nav-ui 仅保留首屏必需的静态依赖
// （core 分发器 / dashboard 首屏数据），其余 30 个模块在首次进入对应页面时
// 才 import()——浏览器按模块文件天然分 chunk，依赖链（如 settings → ai/mail）
// 由 ESM 递归加载自动处理。
const pageLoaders = {
    dashboard: () => loadDashboard(),
    users: () => import('./users.js?v=' + PAGE_VER).then(m => m.loadUsers()),
    posts: () => import('./posts.js?v=' + PAGE_VER).then(m => { m.loadPostCategoryOptions(); m.loadPosts(); }),
    comments: () => import('./comments.js?v=' + PAGE_VER).then(m => m.loadComments()),
    reports: () => import('./reports.js?v=' + PAGE_VER).then(m => m.loadReports()),
    appeals: () => import('./appeals.js?v=' + PAGE_VER).then(m => m.loadAppeals()),
    attachments: () => import('./attachments.js?v=' + PAGE_VER).then(m => m.loadAttachments()),
    'friend-links': () => import('./links.js?v=' + PAGE_VER).then(m => m.loadFriendLinks()),
    categories: () => import('./categories.js?v=' + PAGE_VER).then(m => m.loadCategories()),
    settings: () => import('./settings.js?v=' + PAGE_VER).then(m => m.loadSettings()),
    stats: () => import('./stats.js?v=' + PAGE_VER).then(m => m.loadStats()),
    topics: () => import('./topics.js?v=' + PAGE_VER).then(m => m.loadTopics()),
    featured: () => import('./topics.js?v=' + PAGE_VER).then(m => m.loadFeatured()),
    finance: () => import('./finance.js?v=' + PAGE_VER).then(m => { m.financeTab(m.financeCurrentTab); m.loadFinance(true); m.loadPayConfig(); }),
    circles: () => import('./circles.js?v=' + PAGE_VER).then(m => m.loadCircles()),
    plugins: () => import('./plugins.js?v=' + PAGE_VER).then(m => { m.loadPlugins(); m.loadThemes(); }),
    export: () => import('./export.js?v=' + PAGE_VER).then(m => m.initExport()),
    // B2 通用单页管理：免费基础能力，无 data-module，不进授权门禁
    pages: () => import('./pages.js?v=' + PAGE_VER).then(m => m.loadPages()),
    // E2-3 插件设置页：由 plugin-admin-config 声明动态注入菜单，无 data-module
    'plugin-settings': () => import('./plugin-settings.js?v=' + PAGE_VER).then(m => m.loadPluginSettings()),
    // upgrade 页需要禁用表单原生提交（upgrade-form 守卫，见 license.js）
    upgrade: () => { checkVersion(); return import('./license.js?v=' + PAGE_VER).then(m => m.bindUpgradeFormGuard()); },
    license: () => import('./license.js?v=' + PAGE_VER).then(m => m.loadLicensePage()),
    chat: () => import('./chat.js?v=' + PAGE_VER).then(m => m.chatInit()),
    realname: () => import('./realname.js?v=' + PAGE_VER).then(m => m.loadRealname()),
    badges: () => import('./badges.js?v=' + PAGE_VER).then(m => { m.initBadgeIconNames(); m.loadBadges(); }),
    'sensitive-words': () => import('./moderation.js?v=' + PAGE_VER).then(m => m.loadSensitiveWords()),
    'filter-logs': () => import('./moderation.js?v=' + PAGE_VER).then(m => m.loadFilterLogs()),
    'app-versions': () => import('./app-versions.js?v=' + PAGE_VER).then(m => m.loadAppVersions()),
    'crash-logs': () => import('./crash-logs.js?v=' + PAGE_VER).then(m => m.loadCrashLogs(1)),
    activities: () => import('./activities.js?v=' + PAGE_VER).then(m => m.loadActivities()),
    shop: () => import('./shop.js?v=' + PAGE_VER).then(m => m.loadShop()),
    'content-pay': () => import('./content-pay.js?v=' + PAGE_VER).then(m => m.loadContentPay()),
    'app-center': () => import('./app-center.js?v=' + PAGE_VER).then(m => m.loadAppCenter()),
};

// 导航竞态令牌：快速连点 A→B→A 时，旧导航的加载失败不再打扰当前页
let navToken = 0;

// P2-1 片段版本参数：片段内容更新时同步 bump，防部分环境（如未启用
// no-cache 头的 nginx/Apache vhost）下的启发式缓存拿到旧片段。
// 同时也用于动态 import 的页面模块版本号——否则浏览器会复用无 query 的旧模块缓存，
// 改了 badges.js / settings.js 等也不生效（之前点菜单空白的同类隐患）。
const PAGE_VER = '202611211';

// 页面容器清单（29 页）：标题文案 + 容器 id 一一对应。
// 隐藏页面必须用这份显式清单，不能用 id 前缀匹配——'page-title'（标题 h1）
// 和 'page-host'（片段挂载宿主）同样以 'page-' 开头，前缀匹配会把它们一起
// 隐藏：host 一旦隐形，所有按需 fetch 的页面片段都装进看不见的容器里，
// 表现为「点左侧菜单 → 整页空白」（P2-1 拆分时引入的回归）。
const PAGE_TITLES = {dashboard:'数据概览',users:'用户管理',posts:'内容管理',comments:'评论管理',reports:'举报管理',appeals:'申诉管理',attachments:'附件管理','friend-links':'友情链接',categories:'分类管理',settings:'系统设置',stats:'数据统计',topics:'话题管理',circles:'圈子管理',plugins:'扩展管理',export:'数据导出',pages:'单页管理','plugin-settings':'插件设置',upgrade:'系统升级',license:'授权管理',chat:'私信管理',realname:'实名认证',featured:'精华管理',finance:'财务与会员','sensitive-words':'敏感词库','filter-logs':'命中记录','app-versions':'版本管理',activities:'活动管理',badges:'徽章管理','crash-logs':'客户端异常',shop:'商城管理','content-pay':'内容付费','app-center':'应用中心'};

// ============================================================
// 站点模式 · 菜单可见性（单一入口）
// 两套限制（角色白名单 / 模块开关）必须在这里一次性重算全量。
// 不能各自写 el.style.display：圈主逻辑只隐藏不恢复，模块过滤再来一遍会互相踩。
// ============================================================
let modulePages = null;       // null = 配置未加载 / 读取失败 → fail-open 全显示
let roleAllowedPages = null;  // 非 null = 角色白名单生效（圈主）
let appCenterUrl = '';        // 应用中心后台地址（来自 /site/config，可由 config.php 的 APP_CENTER_URL 配置）

/** 应用中心入口是否可见：配置了地址才显示。 */
function applyAppCenterVisibility() {
    const btn = document.querySelector('.nav-item[data-action="open-app-center"]');
    if (btn) btn.style.display = appCenterUrl ? '' : 'none';
}

/** 页面是否被模块开关允许；配置未知时一律放行 */
export function modulePageVisible(page) {
    return modulePages === null || modulePages[page] !== false;
}

function applyNavVisibility() {
    let visibleCount = 0;
    document.querySelectorAll('.nav-item[data-page]').forEach(el => {
        const page = el.getAttribute('data-page');
        // 角色限制生效时忽略模块开关：否则关掉 activities 会把圈主锁死在空后台
        const roleOk = roleAllowedPages === null || roleAllowedPages.includes(page);
        const moduleOk = roleAllowedPages !== null || modulePageVisible(page);
        const visible = roleOk && moduleOk;
        el.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
    });
    document.querySelectorAll('.nav-group').forEach(g => {
        const hasVisible = Array.from(g.querySelectorAll('.nav-item[data-page]'))
            .some(el => el.style.display !== 'none');
        g.style.display = hasVisible ? '' : 'none';
    });
    const foot = document.getElementById('sidebar-module-count');
    if (foot) foot.textContent = visibleCount + ' 个功能模块已就绪';
}

/** 启动时读一次公开配置（圈主也能读）；任何失败都保持 fail-open */
export async function initModuleVisibility() {
    try {
        const cfg = await request('/site/config');
        if (cfg && cfg.module_pages && typeof cfg.module_pages === 'object') {
            modulePages = cfg.module_pages;
        }
        if (cfg && typeof cfg.app_center_url === 'string') {
            appCenterUrl = cfg.app_center_url;
        }
    } catch (e) {
        console.warn('[nav] 模块配置读取失败，按全显示处理', e);
        modulePages = null;
    }
    applyNavVisibility();
    applyAppCenterVisibility();
}

// E2-3 / Q8：管理员鉴权通过后才拉取插件后台声明并合并菜单。
// 菜单归属完全由清单 spec.group 决定，复用现有 nav-* 分组；不写 data-module，
// 因此既不会被模块开关误锁，也不进入任何授权门禁。
export async function loadPluginAdminConfig() {
    try {
        const cfg = await request('/admin/plugin-admin-config');
        document.querySelectorAll('.nav-item[data-plugin-menu="1"]').forEach(el => el.remove());
        const menus = Array.isArray(cfg && cfg.menus) ? cfg.menus : [];
        menus.forEach(spec => {
            const page = String(spec && spec.page || '');
            const group = String(spec && spec.group || '');
            if (!page || !group) return;
            const body = document.querySelector('#nav-' + group + ' .nav-group-body');
            if (!body || document.querySelector('.nav-item[data-page="' + page + '"]')) return;
            const btn = document.createElement('button');
            btn.className = 'nav-item';
            btn.dataset.page = page;
            btn.dataset.action = 'show-page';
            btn.dataset.pluginMenu = '1';
            const icon = document.createElement('i');
            icon.dataset.icon = String(spec.icon || 'puzzle');
            btn.appendChild(icon);
            btn.appendChild(document.createTextNode(String(spec.title || page)));
            body.appendChild(btn);
            PAGE_TITLES[page] = String(spec.title || page);
        });
        applyNavVisibility();
    } catch (e) {
        // 插件菜单是增强项：读取失败时保持原生菜单，不影响后台其它功能
        console.warn('[nav] 插件后台声明加载失败，按无插件菜单处理', e);
    }
}

// 设置页独立保存成功后发事件刷新菜单，避免两个模块互相静态依赖
document.addEventListener('hublog:module-flags-changed', () => { initModuleVisibility(); });

// P2-1 片段懒加载：各页片段首次挂载后的一次性初始化钩子
// （数据加载 pageLoaders 每次进页都执行，两类钩子严格二分）
const pageInitHooks = {
    settings: () => import('./settings.js?v=' + PAGE_VER).then(m => m.initSettingsPage()),
};

// 侧边栏默认态：仅「概览与数据」展开，其余分组折叠。
// 每次进入后台都回到这个状态（不再从 localStorage 恢复上次的手动折叠），
// 保证「一进后台就是数据概览 + 干净侧边栏」的一致首屏。
export function resetNavGroups() {
    document.querySelectorAll('.nav-group').forEach(g => {
        g.classList.toggle('collapsed', g.id !== 'nav-overview');
    });
}

// 导航高亮与页头文案（进入后台首屏 / 切页共用同一套逻辑）
export function syncActivePage(page) {
    document.querySelectorAll('.nav-item').forEach(el=>el.classList.remove('active'));
    const activeNav = document.querySelector('.nav-item[data-page="' + page + '"]');
    if (activeNav) {
        activeNav.classList.add('active');
        const group = activeNav.closest('.nav-group');
        if (group && group.classList.contains('collapsed')) {
            group.classList.remove('collapsed');
        }
    }
    const titles = PAGE_TITLES;
    document.getElementById('page-title').textContent = titles[page] || '数据概览';
    document.getElementById('mobile-title').textContent = '我圈 · ' + (titles[page] || '后台');
    // 页头副标题：自动取当前导航分组名，统一所有页面的层级信息
    const subtitle = document.getElementById('header-subtitle');
    if (subtitle) {
        const groupName = activeNav?.closest('.nav-group')
            ?.querySelector('.nav-group-header')?.firstChild?.textContent?.trim();
        subtitle.textContent = groupName ? groupName + ' / ' + (titles[page] || '') : (titles[page] || '控制台');
    }
}

export function showPage(page) {
    // 站点模式：被关模块拦在最前，不挂载片段、不改导航高亮。
    // 圈主角色限制生效时走角色白名单，不受模块开关影响。
    if (roleAllowedPages === null && !modulePageVisible(page)) {
        showToast('该功能未开启');
        return;
    }
    const t = ++navToken;
    // 同步先行：导航高亮与标题反馈不依赖片段/数据加载
    syncActivePage(page);
    closeMobileMenu();
    // 只隐藏真正的页面容器（显式清单），绝不碰 page-title / page-host
    for (const p of Object.keys(PAGE_TITLES)) {
        document.getElementById('page-' + p)?.classList.add('hidden');
    }

    // P2-1 片段懒加载：page 容器不在 DOM 时 fetch 对应片段挂载到宿主。
    // dashboard 常驻骨架（不在 host 内），挂载判定必须全文档查；其余 27 页按需
    // fetch（admin/.htaccess 已 no-cache，fetch 走协商缓存）。
    const alreadyMounted = document.getElementById('page-' + page) !== null;
    const host = document.getElementById('page-host');
    const mountP = alreadyMounted
        ? Promise.resolve('cached')
        : fetch('./pages/' + page + '.html?v=' + PAGE_VER).then(r => {
            if (!r.ok) throw new Error('片段加载失败（HTTP ' + r.status + '）');
            return r.text();
        }).then(html => {
            if (t !== navToken) return 'stale'; // 过期导航：内容直接丢弃
            const wrap = document.createElement('div');
            wrap.innerHTML = html;
            // 防御：服务器对缺失片段的 fallback（如 SPA 回退整页）不含目标容器，
            // 一律拒绝挂载，避免骨架/无关内容被复制进宿主
            if (!wrap.querySelector('#page-' + page)) {
                throw new Error('片段内容异常（缺少 #page-' + page + ' 容器）');
            }
            Array.from(wrap.children).forEach(ch => host.appendChild(ch));
            return 'fresh';
        });

    mountP.then(status => {
        if (status === 'stale') return;
        // 显示目标页（片段根节点自带 hidden，挂载/切换后移除）
        document.getElementById('page-' + page)?.classList.remove('hidden');
        if (status === 'fresh') {
            enhanceNumberInputs();           // 幂等（dataset.numStep 防重入）
            pageInitHooks[page]?.();         // 页面一次性初始化
        }
        // 数据加载：每次进页都刷新（与原行为一致）
        return pageLoaders[page]?.();
    }).catch(e => {
        console.error('[showPage]', page, e);
        if (t === navToken) showToast('页面加载失败：' + (e?.message || e));
    });
}

export function toggleNavGroup(name) {
    const group = document.getElementById('nav-' + name);
    if (!group) return;
    // 仅本次会话内切换，不再持久化：每次进入后台都回到默认折叠态
    group.classList.toggle('collapsed');
}
export function toggleMobileMenu() {
    document.getElementById('sidebar').classList.toggle('active');
    document.getElementById('sidebar-overlay').classList.toggle('active');
}
export function closeMobileMenu() {
    document.getElementById('sidebar').classList.remove('active');
    document.getElementById('sidebar-overlay').classList.remove('active');
}

/**
 * V69：给所有数字输入框补一组常显的 − / + 步进按钮。
 * 原生 number 的上下键又窄又淡，很多人压根没发现，只能手动敲数字。
 * 这里在输入框右侧加两个按钮，并严格沿用原本的 min / max / step 约束。
 */
export function enhanceNumberInputs() {
    document.querySelectorAll('input[type="number"].form-input').forEach(function (inp) {
        if (inp.dataset.numStep === '1') return;
        inp.dataset.numStep = '1';

        var wrap = document.createElement('div');
        wrap.className = 'num-step';
        inp.parentNode.insertBefore(wrap, inp);
        wrap.appendChild(inp);

        var up = document.createElement('button');
        up.type = 'button'; up.className = 'num-step-btn'; up.textContent = '+'; up.title = '增加';
        var dn = document.createElement('button');
        dn.type = 'button'; dn.className = 'num-step-btn'; dn.textContent = '\u2212'; dn.title = '减少';
        var box = document.createElement('div');
        box.className = 'num-step-btns';
        box.appendChild(up);
        box.appendChild(dn);
        wrap.appendChild(box);

        var step = parseFloat(inp.getAttribute('step')) || 1;
        var min = inp.hasAttribute('min') ? parseFloat(inp.getAttribute('min')) : null;
        var max = inp.hasAttribute('max') ? parseFloat(inp.getAttribute('max')) : null;

        function bump(dir) {
            var v = parseFloat(inp.value);
            if (isNaN(v)) v = (min !== null ? min : 0);
            v = v + dir * step;
            if (min !== null && v < min) v = min;
            if (max !== null && v > max) v = max;
            // 消掉浮点尾巴，避免出现 0.30000000000000004
            inp.value = String(Math.round(v * 1000) / 1000);
            inp.dispatchEvent(new Event('input', { bubbles: true }));
            inp.dispatchEvent(new Event('change', { bubbles: true }));
        }
        up.addEventListener('click', function () { bump(1); });
        dn.addEventListener('click', function () { bump(-1); });
    });
}

// B1 委托化：外壳交互动作注册（onclick → data-action，见改造方案文档）
registerActions({
    'show-page': (d) => showPage(d.page),
    'toggle-nav-group': (d) => toggleNavGroup(d.group),
    'toggle-mobile-menu': () => toggleMobileMenu(),
    'close-mobile-menu': () => closeMobileMenu(),
    // B5 委托化：返回前台（原 location.href 直调，含 BASE_URL 拼接）
    'goto-home': () => { location.href = BASE_URL + '/'; },
    // 应用中心：新标签打开独立部署的应用中心后台（Dz 式在线安装入口）
    'open-app-center': () => {
        if (!appCenterUrl) { showToast('应用中心地址未配置', 'error'); return; }
        window.open(appCenterUrl, '_blank', 'noopener');
    },
});

// 圈主：仅保留「活动管理」入口，其余后台功能不可见。
// 统一走 applyNavVisibility()，与站点模式开关叠加时不会互相覆盖。
export function restrictNavForCircleOwner() {
    roleAllowedPages = ['activities'];
    applyNavVisibility();
}