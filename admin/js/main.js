// ============================================================
// main.js — 后台入口
// P1-2/P2-1 懒加载：仅静态 import 首屏必需模块（core 分发器/nav-ui 外壳/
// dashboard 首屏数据），其余 30 个模块由 nav-ui.js 的 pageLoaders 在首次
// 进入对应页面时动态 import()，page 容器片段由 showPage 按需 fetch 挂载。
// B6 委托化完成：内联事件全部经 core.js 分发器注册表分发，
// window 全局挂载已拆除，业务函数仅模块作用域可见。
// ============================================================
import * as core_js from './core.js';
// 版本参数：nav-ui.js 修复了「点菜单 → 整页空白」（隐藏逻辑误伤 page-host），
// 必须带版本号强刷，避免浏览器复用旧模块缓存导致修复不生效
import * as nav_ui_js from './nav-ui.js?v=202611211';
import * as dashboard_js from './dashboard.js';

// 图标库 / 主题开关属于外壳基础设施，走动态 import 保持首屏静态依赖只有 3 个模块。
// startIconObserver 会在片段懒加载、JS 模板 innerHTML 更新后自动水合 <i data-icon>。
import('./icons.js?v=202611211').then(m => {
    m.startIconObserver();
    const label = document.getElementById('theme-label');
    if (label) label.textContent = m.currentTheme() === 'dark' ? '浅色' : '深色';
}).catch(e => console.error('[icons]', e));

// 检查权限
document.addEventListener('DOMContentLoaded', () => {
    // P0-3：令牌可能只存在于 httpOnly Cookie（localStorage 不再持久化令牌字符串），
    // 入口不再以 localStorage 令牌为准，统一交给下方 /auth/me 判定（Cookie/Bearer 均可）。
    // V179：无论是否带 Bearer，都预取 CSRF token——浏览器同时存在 Bearer 头与 PHPSESSID
    // Cookie 时，CsrfMiddleware 不视为「纯 Bearer」，写请求仍要求 CSRF 校验。
    core_js.fetchCsrf();

    // 侧边栏每次进入后台都回到默认折叠态：仅「概览与数据」展开，其余分组折叠。
    // 不再从 localStorage 恢复上次的手动折叠，保证每次进后台的侧边栏形态一致。
    nav_ui_js.resetNavGroups();
    // 站点模式：异步拉一次模块可见性配置；读取失败时按全显示（fail-open）
    nav_ui_js.initModuleVisibility();

    core_js.request('/auth/me').then(u=>{
      document.getElementById('admin-user').textContent = u.nickname || u.username || '管理员';
      window.__isAdmin = (u.role === 'admin');
      window.__isCircleOwner = !!u.is_circle_owner;
      if (window.__isAdmin) {
        // 首屏固定为「数据概览」：同步导航高亮 + 页头标题/副标题，再加载数据
        nav_ui_js.syncActivePage('dashboard');
        dashboard_js.loadDashboard();
        // E2-3 / Q8：仅在管理员身份确认后拉取插件后台声明并注入菜单；
        // 该请求走 /api/admin 组，未登录/非管理员由后端 403，前端不提前触碰插件文件。
        nav_ui_js.loadPluginAdminConfig();
      } else if (window.__isCircleOwner) {
        // 圈主：仅开放「活动管理」，其余后台功能不可见
        nav_ui_js.restrictNavForCircleOwner();
        // P1-2：showPage 内部动态加载模块，失败时自行 toast，这里不阻塞
        nav_ui_js.showPage('activities');
      } else {
        location.href = core_js.BASE_URL + '/';
      }
    }).catch(()=>location.href = core_js.BASE_URL + '/');

    // 移动端菜单按钮同时监听 touchstart，避免某些浏览器 click 不触发
    const menuBtn = document.querySelector('.mobile-menu-btn');
    if(menuBtn) {
        menuBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            nav_ui_js.toggleMobileMenu();
        }, { passive: false });
    }
});
