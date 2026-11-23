/**
 * woquan 前端配置
 * 自动适配根目录/子目录部署
 */

export const BASE_URL = (() => {
    // 优先使用后端注入的部署路径（兼容性最好，适配 Apache/Nginx 及子目录部署）
    if (typeof window.HUBLOG_BASE_URL === 'string' && window.HUBLOG_BASE_URL) {
        const path = window.HUBLOG_BASE_URL;
        return path === '/' ? window.location.origin : window.location.origin + path;
    }
    // 兜底：从当前脚本路径推导
    const scripts = document.querySelectorAll('script[src*="app.js"]');
    for (const script of scripts) {
        const src = script.src;
        const idx = src.indexOf('/templates/');
        if (idx !== -1) {
            const origin = src.substring(0, src.indexOf('/', src.indexOf('//') + 2));
            const path = src.substring(origin.length, idx);
            return origin + path;
        }
    }
    return window.location.origin;
})();

// 将 BASE_URL 的相对路径部分同步到 window.HUBLOG_BASE_URL，
// 供 inline onclick 等全局上下文使用
if (typeof window !== 'undefined') {
    const origin = window.location.origin;
    window.HUBLOG_BASE_URL = BASE_URL.startsWith(origin) 
        ? BASE_URL.substring(origin.length) || '/' 
        : BASE_URL;
}

// 使用 api/index.php 直接访问模式（PATH_INFO），不依赖 Web 服务器伪静态重写规则。
// 伪静态（url_rewrite）开关只控制前端页面 URL 格式，与 API 路由无关。
// 请求示例：http://host/api/index.php/csrf → Router 通过 PATH_INFO 解析为 /api/csrf
export const API_BASE = BASE_URL + '/api/index.php';

// 站内链接用根相对路径（站点换域名或接入 CDN 时不失效，SEO 上与 sitemap/canonical 风格一致）
export const PAGE_PATH = (() => {
    if (typeof window.HUBLOG_PAGE_BASE === 'string') {
        return window.HUBLOG_PAGE_BASE.replace(/\/$/, '');
    }
    return '';
})();

// 前端页面路由基础 URL（含 origin）
// 伪静态开启：http://host 或 http://host/sub（URL 美化，需 Web 服务器重写）
// 伪静态关闭：http://host/index.php 或 http://host/sub/index.php（PATH_INFO 模式，开箱即用）
// 用法：PAGE_BASE + '/search'、PAGE_BASE + '/u/admin'
export const PAGE_BASE = (() => {
    const origin = window.location.origin;
    if (typeof window.HUBLOG_PAGE_BASE === 'string') {
        // HUBLOG_PAGE_BASE 不含末尾斜杠（根目录为空字符串）
        return origin + window.HUBLOG_PAGE_BASE;
    }
    // 兜底：回退到 BASE_URL（向后兼容）
    return BASE_URL;
})();

// 首页固定指向站点根。PAGE_BASE 在伪静态关闭时带 /index.php 前缀，
// 拼出的 /index.php/ 会被部分 Web 服务器直接判为 404。
export const HOME_URL = BASE_URL + '/';
