/**
 * woquan 认证模块（资讯流顶栏定制）
 * 端点与 moments 完全一致：/auth/me /auth/login /auth/register /auth/logout /csrf
 *
 * 登录 / 注册统一走独立页面 login.html（路由 /login），不再使用弹窗。
 * openAuthModal() 这个名字保留为全主题的「需要登录」统一入口，
 * 内部改为跳转，避免逐个改 feed/article/write 等调用点。
 */
import { refreshUserMenu } from './nav.js?v=20261117.02';
import { PAGE_BASE, HOME_URL } from './config.js?v=20261117.02';

// 登录页自身及重置密码页不作为返回目标，避免登录后跳回登录页形成循环
const AUTH_SELF = /(?:^|\/)(?:login|reset-password)\/?$/;

/** 只接受本站相对地址作为登录后返回目标，阻断 //evil.com、javascript: 等开放重定向 */
export function safeTarget(raw) {
    if (!raw) return HOME_URL;
    try {
        const u = new URL(raw, location.origin);
        if (u.origin !== location.origin) return HOME_URL;
        return u.pathname + u.search + u.hash;
    } catch (e) { return HOME_URL; }
}

/** 跳转独立登录页；mode=register 直接落到注册态，redirect 带回当前页 */
export function goLogin(app, mode) {
    const back = AUTH_SELF.test(location.pathname) ? '' : (location.pathname + location.search + location.hash);
    const qs = [];
    if (mode === 'register') qs.push('mode=register');
    if (back) qs.push('redirect=' + encodeURIComponent(back));
    location.href = PAGE_BASE + '/login' + (qs.length ? '?' + qs.join('&') : '');
}

export async function logout(app) {
    try { await app.request('/auth/logout', { method: 'POST' }); } catch (e) {}
    app.token = null; app.user = null; app.authed = false;
    localStorage.removeItem('hublog_token');
    localStorage.removeItem('hublog_user');
    app.updateAuthUI();
    app.showToast('已退出');
    location.href = HOME_URL;
}

export async function fetchUser(app) {
    try {
        const user = await app.request('/auth/me');
        if (user) { app.user = user; app.authed = true; localStorage.setItem('hublog_user', JSON.stringify(user)); }
        else { app.authed = false; }
    } catch (e) {
        app.authed = false;
        app.token = null; app.user = null;
        localStorage.removeItem('hublog_token'); localStorage.removeItem('hublog_user');
    }
}

export function checkAuth(app) {
    if (app.authed === false) { app.showToast('请先登录'); goLogin(app); return false; }
    // authed 为 null 表示 /auth/me 尚未返回：此时放行会先发请求再失败，
    // 提示语会变成「操作失败」，用户无法判断是先登录还是重试。
    if (app.authed === null) { app.showToast('正在确认登录状态，请稍后再试'); return false; }
    return true;
}

/** 兼容旧调用名：一律跳独立登录页，不再显示弹窗 */
export function openAuthModal(app) {
    goLogin(app);
}

export function updateAuthUI(app) {
    const loginBtn = document.getElementById('loginBtn');
    const avatar = document.getElementById('topbarAvatar');
    if (app.user) {
        const url = resolveAvatar(app, app.user.avatar);
        if (avatar) {
            avatar.style.display = 'flex';
            const img = avatar.querySelector('img');
            if (img) img.src = url; else avatar.textContent = (app.user.nickname || app.user.username || '?')[0];
        }
        if (loginBtn) loginBtn.style.display = 'none';
    } else {
        if (avatar) avatar.style.display = 'none';
        if (loginBtn) loginBtn.style.display = '';
    }
    refreshUserMenu(app);
}

export function resolveAvatar(app, avatar) {
    if (!avatar || avatar === 'null' || avatar === 'undefined') return 'assets/images/default-avatar.svg';
    if (avatar.endsWith('/uploads/avatars/default.svg') || avatar === 'templates/moments/assets/images/default-avatar.svg')
        return 'assets/images/default-avatar.svg';
    return avatar;
}

export function clearStaleAuth(app) {
    app.token = null; app.user = null; app.authed = false;
    localStorage.removeItem('hublog_token'); localStorage.removeItem('hublog_user');
    if (typeof app.updateAuthUI === 'function') app.updateAuthUI();
}
