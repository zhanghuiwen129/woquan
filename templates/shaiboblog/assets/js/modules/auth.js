/**
 * shaiboblog 认证模块
 * 端点与 moments 完全一致：/auth/me /auth/login /auth/register /auth/logout /csrf
 * 登录 / 注册统一使用独立页面 login.html（路由 /login），不再使用弹窗。
 */
import { refreshUserMenu } from './nav.js?v=20261116.12';
import { HOME_URL, PAGE_BASE } from './config.js?v=20261116.12';

/** 独立登录页地址：next 记录当前页面，登录 / 注册成功后原路返回 */
export function loginUrl() {
    const next = location.pathname + location.search + location.hash;
    return PAGE_BASE + '/login?next=' + encodeURIComponent(next);
}

/** 跳转独立登录页；当前已在登录页时不再跳转，避免死循环 */
export function goLogin(app) {
    if (/\/login\/?$/.test(location.pathname)) return;
    location.href = loginUrl();
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
