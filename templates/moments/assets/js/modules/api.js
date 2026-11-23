/**
 * woquan API 请求层
 * CSRF 自动重试 & Token 自动刷新
 */
import { API_BASE } from './config.js?v=20260803.5';

export async function request(app, url, options = {}) {
    // Token 即将过期时自动续期（滑动窗口）
    if (app.token && !options._skipRefresh) {
        await tryRefreshToken(app);
    }

    const isFormData = options.body instanceof FormData;
    const headers = {
        'X-CSRF-Token': app.csrfToken,
    };
    // FormData 由浏览器自动设置 Content-Type（含 boundary），不能手动覆盖
    if (!isFormData) {
        headers['Content-Type'] = 'application/json';
    }
    if (app.token) {
        headers['Authorization'] = `Bearer ${app.token}`;
    }

    const opts = { headers, ...options };
    if (opts.body && typeof opts.body === 'object' && !isFormData) {
        opts.body = JSON.stringify(opts.body);
    }

    const res = await fetch(API_BASE + url, opts);

    // 先读取文本，避免空响应导致 json() 报错
    const text = await res.text();
    if (!text || !text.trim()) {
        throw new Error('服务器未返回数据');
    }
    let data;
    try {
        data = JSON.parse(text);
    } catch (e) {
        throw new Error('服务器返回了非 JSON 数据');
    }

    // CSRF token 过期或失效时，自动刷新并重试一次
    if (data.code === 403 && /csrf/i.test(data.message || '') && !options._csrfRetried) {
        await fetchCsrf(app);
        return request(app, url, { ...options, _csrfRetried: true });
    }

    // 401 认证失效：token 过期/用户已删除/数据库重装 → 清除残留登录态
    // 跳过 /auth/refresh 自身的 401（已在 tryRefreshToken 中处理）
    if (data.code === 401 && !options._skipAuthClear) {
        clearStaleAuthFromApi(app);
    }

    if (data.code !== 200) {
        const err = new Error(data.message);
        // 带上业务码/状态码，调用方据此区分「登录失效(401)」「需授权(402)」
        // 「CSRF 失败(403)」等，避免一律当普通错误吞掉。
        err.code = data.code;
        err.statusCode = res.status;
        err.apiMessage = data.message || '';
        throw err;
    }
    return data.data;
}

export async function fetchCsrf(app) {
    try {
        const data = await request(app, '/csrf');
        app.csrfToken = data.token;
    } catch (e) {
        // 公开接口可能不需要 csrf
    }
}

export async function tryRefreshToken(app) {
    // P0-3：Cookie 模式下 app.token 恒为 null，无法从 JWT 读 exp。
    // 此时令牌由后端以 httpOnly Cookie 维护，前端无从得知过期时间 ——
    // 交给 /auth/me 与业务接口的 401 兜底（会话真失效时服务端会返回 401）。
    if (!app.token) return;
    try {
        const payload = JSON.parse(atob(app.token.split('.')[1]));
        const exp = payload.exp * 1000;
        const now = Date.now();
        const oneDay = 24 * 60 * 60 * 1000;
        if (exp - now < oneDay) {
            // _skipRefresh 避免递归调用 tryRefreshToken
            const data = await request(app, '/auth/refresh', { method: 'POST', _skipRefresh: true });
            if (data && data.token) {
                app.token = data.token;
                // P0-3：令牌持久化已改 httpOnly Cookie（后端 refresh 时同步下发），
                // 不再写 localStorage
            }
        }
    } catch (e) {
        // 只有服务端明确判定令牌无效（401）才清登录态。
        // 网络抖动 / 解析异常不等于登录失效，误清会让用户莫名其妙被登出。
        if (e && (e.statusCode === 401 || e.apiMessage)) {
            clearStaleAuthFromApi(app);
        }
    }
}

/**
 * 从 api.js 清除过期认证状态
 * 不能直接 import auth.js 的 clearStaleAuth（循环依赖），所以内联实现
 */
function clearStaleAuthFromApi(app) {
    app.token = null;
    app.user = null;
    // 服务端返回 401 = 权威地告诉前端「这个会话不可用」，
    // 必须同步置为未登录，否则界面仍显示已登录，用户点了却莫名失败。
    app.authed = false;
    localStorage.removeItem('hublog_token');
    localStorage.removeItem('hublog_user');
    if (app.notificationPollingInterval) {
        clearInterval(app.notificationPollingInterval);
        app.notificationPollingInterval = null;
    }
    if (app.unreadPollingInterval) {
        clearInterval(app.unreadPollingInterval);
        app.unreadPollingInterval = null;
    }
    app.notificationUnread = 0;
    if (typeof app.updateAuthUI === 'function') {
        app.updateAuthUI();
    }
}
