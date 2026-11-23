// ============================================================
// core.js — 基础设施：API 基址、鉴权、HTTP 请求、提示、转义、通用分页、事件委托分发器
// 由 V166 后台拆分脚本从 admin/index.html 切出；B1 起追加事件委托分发器（与源文件不再逐字节一致）
// ============================================================

export const API_BASE = (() => {
    const path = window.location.pathname;
    const segments = path.split('/').filter(Boolean);
    const last = segments.length - 1;
    if (last >= 0 && segments[last] === 'admin') {
        // URL: /hublog/admin/ 或 /admin/
        return segments.length > 1
            ? window.location.origin + '/' + segments.slice(0, last).join('/') + '/api/index.php'
            : window.location.origin + '/api/index.php';
    }
    return window.location.origin + '/api/index.php';
})();
export const BASE_URL = API_BASE.replace(/\/api\/index\.php$/, '');
export let token = localStorage.getItem('hublog_token');
// P0-3：cookie 鉴权模式（token 为空时依赖 httpOnly Cookie）下，写请求需带 CSRF 头。
// csrfToken 由 fetchCsrf() 从 GET /csrf 取得（绑定当前用户身份，含 Cookie 回退识别）。
export let csrfToken = '';

export async function fetchCsrf() {
    try {
        const data = await request('/csrf', { _skipCsrf: true });
        csrfToken = data.token || '';
    } catch (e) { /* 公开接口失败不阻塞 */ }
}

export let currentPage = { users:1, posts:1, comments:1, attachments:1 };

export async function request(url, opts={}) {
    const headers = {};
    // P0-3：token 仅内存态；为空时不带 Authorization 头（发 "Bearer null" 会阻断
    // 后端的 httpOnly Cookie 回退识别）
    // V179：Bearer + PHPSESSID Cookie 并存时，CsrfMiddleware 不视为「纯 Bearer」，
    // 写请求同样要求 CSRF 校验 → Bearer 模式下也带 X-CSRF-Token（fetchCsrf 已在入口预取）。
    if (token) {
        headers['Authorization'] = 'Bearer ' + token;
        if (!opts._skipCsrf && csrfToken && opts.method && opts.method.toUpperCase() !== 'GET') {
            headers['X-CSRF-Token'] = csrfToken;
        }
    } else if (!opts._skipCsrf && opts.method && opts.method.toUpperCase() !== 'GET') {
        // Cookie 鉴权模式下 CsrfMiddleware 对写请求启用 CSRF 校验
        headers['X-CSRF-Token'] = csrfToken;
    }
    let body = undefined;
    if (opts.isFormData) {
        body = opts.body; // FormData 直接传递，不设置 Content-Type（浏览器自动处理 multipart boundary）
    } else if (opts.body) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(opts.body);
    }
    // P1-1：默认 30s 超时，防止慢请求（压缩/导出/批量操作）把页面永久挂起。
    // 慢接口（导出、批量处理）可传 opts.timeout（毫秒）单独放宽；传 0 表示不限时。
    const timeout = opts.timeout !== undefined ? opts.timeout : 30000;
    const controller = new AbortController();
    const timer = timeout > 0 ? setTimeout(() => controller.abort(), timeout) : null;
    let res;
    try {
        res = await fetch(API_BASE+url, { headers, ...opts, body, signal: controller.signal });
    } catch(e) {
        if (e.name === 'AbortError') {
            throw new Error('请求超时（' + Math.round((timeout||0)/1000) + ' 秒未响应），请稍后重试');
        }
        throw e;
    } finally {
        if (timer) clearTimeout(timer);
    }
    const text = await res.text();
    if (!text || !text.trim()) {
        throw new Error('服务器未返回数据（可能执行超时）');
    }
    let data;
    try { data = JSON.parse(text); } catch(e) {
        throw new Error('服务器返回了非 JSON 数据');
    }
    // CSRF 失效自动重取并重试一次（对齐前台 api.js 的既有模式）。
    // 注意：本分支必须先于下方「非 200 抛错」——原实现 throw 在前，重试逻辑永远不可达（死代码），
    // Cookie 鉴权模式下 CSRF 过期后所有写请求直接失败且不自愈。
    if (data.code === 403 && /csrf/i.test(String(data.message || '')) && !opts._csrfRetried) {
        await fetchCsrf();
        return request(url, { ...opts, _csrfRetried: true });
    }
    if(data.code!==200) throw new Error(data.message);
    return data.data;
}

export function showToast(msg, ms) { const t=document.getElementById('toast'); t.textContent=msg; t.style.opacity=1; setTimeout(()=>t.style.opacity=0, ms||2000); }

export function escapeJs(str) {
    return str.replace(/\\/g,'\\\\').replace(/'/g,"\\'").replace(/\n/g,'\\n').replace(/\r/g,'\\r');
}

/** 把后端返回的 'YYYY-MM-DD HH:MM:SS' 转成 <input type="datetime-local"> 需要的 'YYYY-MM-DDTHH:MM' */
export function toLocalInputValue(v) {
    if (!v) return '';
    const s = String(v).replace(' ', 'T');
    return s.length >= 16 ? s.slice(0, 16) : s;
}

export function renderPagination(id, pagination, callback) {
    const container = document.getElementById(id);
    let html = '';
    const pages = pagination.pages || 0;
    const current = pagination.page || 1;
    // 移动端最多显示5个页码按钮，桌面端最多10个
    const maxButtons = window.innerWidth <= 768 ? 5 : 10;
    let startPage = Math.max(1, current - Math.floor(maxButtons / 2));
    let endPage = Math.min(pages, startPage + maxButtons - 1);
    if (endPage - startPage + 1 < maxButtons) startPage = Math.max(1, endPage - maxButtons + 1);

    if (startPage > 1) html += `<button class="page-btn" data-page="1">1</button>`;
    if (startPage > 2) html += `<span style="padding:4px;color:var(--text-muted);">...</span>`;
    for(let i = startPage; i <= endPage; i++) {
        html += `<button class="page-btn ${i===current?'active':''}" data-page="${i}">${i}</button>`;
    }
    if (endPage < pages - 1) html += `<span style="padding:4px;color:var(--text-muted);">...</span>`;
    if (endPage < pages) html += `<button class="page-btn" data-page="${pages}">${pages}</button>`;
    container.innerHTML = html;
    // 使用事件委托，避免 onclick 作用域问题；callback 一律为函数引用
    container.querySelectorAll('.page-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const page = parseInt(btn.dataset.page);
            callback(page);
        });
    });
}

// ============================================================
// 事件委托分发器（B1 改造）
// 全站唯一 click 监听，按 data-action 分发到 registerActions 注册的 handler，
// handler 签名统一为 (dataset, el, event)。B6 已拆除 window 全局挂载，
// 各模块动作经 registerActions 以模块作用域函数引用注册。
// ============================================================
const __actions = {};
export function registerActions(map) { Object.assign(__actions, map); }

document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-action]');
    if (!el) return;
    const fn = __actions[el.dataset.action];
    if (!fn) { console.error('未注册的 action: ' + el.dataset.action); return; }
    fn(el.dataset, el, e);
});

// B6 委托化：change/input/keydown 与 data-action（click）同表分发，但用独立属性
// （data-change / data-input / data-keydown）声明，避免按钮聚焦时 Enter 经 keydown
// 与原生 click 双触发同一 handler。handler 签名与 click 一致：(dataset, el, event)。
const bindDelegated = (type, attr) => {
    document.addEventListener(type, (e) => {
        const el = e.target.closest('[' + attr + ']');
        if (!el) return;
        const name = el.getAttribute(attr);
        const fn = __actions[name];
        if (!fn) { console.error('未注册的 action: ' + name); return; }
        fn(el.dataset, el, e);
    });
};
bindDelegated('change', 'data-change');
bindDelegated('input', 'data-input');
bindDelegated('keydown', 'data-keydown');

// B6 委托化：图片加载失败统一隐藏（原模板串内联 error 处理已删除）。
// error 事件不冒泡，必须用捕获阶段监听。
document.addEventListener('error', (e) => {
    if (e.target && e.target.tagName === 'IMG') e.target.style.display = 'none';
}, true);

export function escapeHtml(str) {
    if (str == null) return '';
    return String(str).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch]);
}