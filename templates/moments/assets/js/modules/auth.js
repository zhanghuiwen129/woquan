/**
 * woquan 认证相关方法
 *
 * P3-19 解耦：登录/登出/注册成功后，除保留原有直接调用外，
 * 额外通过 EventBus 发布事件，便于新模块订阅而不修改本模块。
 */
import { BASE_URL, API_BASE, PAGE_BASE } from './config.js?v=20260803.5';
import { compressImageFile } from './compress.js?v=20260803.5';
import { universalDirectUpload } from './uploadDirect.js?v=20260803.5';
import { bus } from './events-bus.js?v=20260820.1';

export async function login(app) {
    const username = document.getElementById('login-username')?.value;
    const password = document.getElementById('login-password')?.value;
    if (!username || !password) {
        app.showToast('请填写完整');
        return;
    }
    try {
        const data = await app.request('/auth/login', {
            method: 'POST',
            body: { username, password }
        });
        app.token = data.token;
        app.user = data.user;
        // P0-3：令牌持久化改 httpOnly Cookie 持久化（后端 setAuthCookie 下发），
        // 不再写入 localStorage —— XSS 无法窃取持久化令牌；内存 app.token 仅本次会话有效
        localStorage.setItem('hublog_user', JSON.stringify(app.user));
        // 服务端已确认登录成功
        app.authed = true;
        // P0-3：登录前取的 CSRF token 绑定的是「游客身份」，登录后身份变了必须重取，
        // 否则后续写请求会 403「CSRF验证失败」（自动重试能救，但这里主动换掉更干净）。
        if (typeof app.fetchCsrf === 'function') {
            try { await app.fetchCsrf(); } catch (e) { /* 取不到不影响主流程 */ }
        }
        document.getElementById('auth-modal')?.classList.remove('active');
        app.updateAuthUI();
        app.loadUnreadCount();
        app.startNotificationPolling();
        app.showToast('登录成功');
        // P3-19：发布登录事件，便于其他模块订阅（如自动连接 SSE、刷新动态列表等）
        bus.emit('auth:login', app.user);
    } catch (e) {
        app.showToast(e.message);
    }
}

export async function register(app) {
    const username = document.getElementById('reg-username')?.value;
    const email = document.getElementById('reg-email')?.value;
    const password = document.getElementById('reg-password')?.value;
    const passwordConfirm = document.getElementById('reg-password-confirm')?.value;
    if (!username || !email || !password || !passwordConfirm) {
        app.showToast('请填写完整');
        return;
    }
    if (password !== passwordConfirm) {
        app.showToast('两次输入的密码不一致');
        return;
    }
    try {
        const data = await app.request('/auth/register', {
            method: 'POST',
            body: { username, email, password, password_confirm: passwordConfirm }
        });
        app.token = data.token;
        app.user = data.user;
        // P0-3：同登录 —— 令牌持久化交给 httpOnly Cookie
        localStorage.setItem('hublog_user', JSON.stringify(app.user));
        // 服务端已确认登录成功
        app.authed = true;
        // P0-3：登录前取的 CSRF token 绑定的是「游客身份」，登录后身份变了必须重取，
        // 否则后续写请求会 403「CSRF验证失败」（自动重试能救，但这里主动换掉更干净）。
        if (typeof app.fetchCsrf === 'function') {
            try { await app.fetchCsrf(); } catch (e) { /* 取不到不影响主流程 */ }
        }
        document.getElementById('auth-modal')?.classList.remove('active');
        app.clearAuthForm();
        app.updateAuthUI();
        app.loadUnreadCount();
        app.startNotificationPolling();
        app.showToast('注册成功');
        // P3-19：发布登录事件（注册即视为登录）
        bus.emit('auth:login', app.user);
    } catch (e) {
        app.showToast(e.message);
    }
}

export async function logout(app) {
    try { await app.request('/auth/logout', { method: 'POST' }); } catch (e) {}
    app.token = null;
    app.user = null;
    // 已登出 → 明确置为未登录，后续点任何入口都会正确弹登录框
    app.authed = false;
    localStorage.removeItem('hublog_token');
    localStorage.removeItem('hublog_user');
    if (app.notificationPollingInterval) {
        clearInterval(app.notificationPollingInterval);
        app.notificationPollingInterval = null;
    }
    app.notificationUnread = 0;
    app.updateNotifyBadge();
    app.updateAuthUI();
    app.showToast('已退出');
    // P3-19：发布登出事件，便于其他模块清理订阅、关闭 SSE 等
    bus.emit('auth:logout');
    window.location.href = BASE_URL + '/';
}

export async function checkRegisterField(app, field) {
    const input = document.getElementById('reg-' + field);
    const hint = document.getElementById('reg-' + field + '-hint');
    if (!input || !hint) return;
    const value = input.value.trim();
    if (!value) {
        hint.textContent = '';
        hint.className = 'field-hint';
        return;
    }
    try {
        const data = await app.request('/auth/check-' + field + '?' + field + '=' + encodeURIComponent(value));
        hint.textContent = data.message;
        hint.className = 'field-hint ' + (data.available ? 'ok' : 'error');
    } catch (e) {
        hint.textContent = '';
        hint.className = 'field-hint';
    }
}

export async function updateProfile(app) {
    return saveProfile(app);
}

export async function saveProfile(app) {
    const nickname = document.getElementById('profile-nickname')?.value.trim();
    const bio = document.getElementById('profile-bio-input')?.value.trim();
    if (!nickname) {
        app.showToast('昵称不能为空');
        return;
    }
    try {
        const user = await app.request('/auth/profile', {
            method: 'PUT',
            body: { nickname, bio }
        });
        app.user = { ...app.user, ...user };
        localStorage.setItem('hublog_user', JSON.stringify(app.user));
        document.getElementById('profile-modal')?.classList.remove('active');
        app.updateAuthUI();
        const profileName = document.getElementById('profile-name');
        const profileBio = document.getElementById('profile-bio');
        if (profileName) profileName.textContent = app.user.nickname || app.user.username;
        if (profileBio) profileBio.textContent = app.user.bio || '这个人很懒，什么都没写~';
        app.showToast('保存成功');
    } catch (e) {
        app.showToast(e.message);
    }
}

export async function changePassword(app) {
    const oldPassword = document.getElementById('password-old')?.value;
    const newPassword = document.getElementById('password-new')?.value;
    const confirmPassword = document.getElementById('password-confirm')?.value;
    if (!oldPassword || !newPassword || !confirmPassword) {
        app.showToast('请填写完整');
        return;
    }
    if (newPassword !== confirmPassword) {
        app.showToast('两次输入的新密码不一致');
        return;
    }
    try {
        await app.request('/auth/password', {
            method: 'PUT',
            body: { old_password: oldPassword, new_password: newPassword, new_password_confirm: confirmPassword }
        });
        document.getElementById('password-modal')?.classList.remove('active');
        document.getElementById('password-old').value = '';
        document.getElementById('password-new').value = '';
        document.getElementById('password-confirm').value = '';
        app.showToast('密码修改成功，请重新登录');
        setTimeout(() => app.logout(), 1500);
    } catch (e) {
        app.showToast(e.message);
    }
}

export async function uploadAvatar(app, file) {
    const cfg = app.siteConfig || {};
    const maxSize = (cfg.upload_max_avatar_size || 5) * 1024 * 1024;
    if (file.size > maxSize) {
        throw new Error(`头像文件不能超过 ${cfg.upload_max_avatar_size || 5}MB`);
    }

    const compressed = await compressImage(app, file, 300, 300, 0.85);
    const data = await universalDirectUpload(app, {
        type: 'avatar',
        file: compressed,
        fieldName: 'avatar',
        localEndpoint: '/auth/avatar',
        skipRegister: false,
        origFilename: file.name
    });

    if (!data || !data.avatar) throw new Error('上传后未得到头像 URL');

    app.user.avatar = data.avatar;
    localStorage.setItem('hublog_user', JSON.stringify(app.user));

    // 统一刷新所有头像 DOM：加 cache buster 防止浏览器显示旧头像
    const cacheBuster = '_t=' + Date.now();
    const finalAvatar = resolveAvatar(app, app.user.avatar);
    const avatarWithCache = finalAvatar + (finalAvatar.includes('?') ? '&' : '?') + cacheBuster;

    app.updateAuthUI();

    // 1. 固定位置头像（顶部导航、侧边栏、个人主页大头像、弹窗预览）
    ['profile-avatar', 'sidebar-avatar', 'profile-modal-avatar'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.src = avatarWithCache;
    });

    // 2. 动态列表里当前用户发布的所有动态头像（.moment-item 已标记 data-username）
    const myUsername = app.user?.username;
    if (myUsername) {
        document.querySelectorAll(`.moment-item[data-username="${myUsername}"] .moment-avatar`).forEach(img => {
            img.src = avatarWithCache;
        });
    }

    return data.avatar;
}

/**
 * 渲染封面：容器尺寸由 CSS 固定，图片按容器宽高直接拉扯铺满（object-fit: fill），
 * 不裁切、也不留白。代价是图片比例与容器不一致时会被拉伸/压缩变形。
 *
 * 设计要点：
 * - 容器高度由 CSS 固定（主页头图 .profile-header 320/280px、个人主页 .user-cover 220/180px），
 *   不会因为换图而变化，页面布局稳定。
 * - 图片用 fill 强制拉伸到容器尺寸，四周无留白、边缘无裁切；
 *   比例不匹配时图片会被横向/纵向拉伸（典型表现：横图进矮宽容器会被"压扁"）。
 * - 不再使用模糊兜底层（之前用户反馈"模糊层像空白渐变带"）。
 *
 * @param {*} app               应用实例（applyMixin 自动注入首参）
 * @param {HTMLElement} container 内层容器（#cover-bg / #header-cover）
 * @param {string} url            封面 URL（调用方负责 safeUrl 校验）
 * @param {boolean} isVideo       是否为视频封面
 */
export function setCover(app, container, url, isVideo) {
    if (!container || !url) return;
    container.innerHTML = '';

    // 视频：cover 铺满（视频无"完整 vs 裁切"感知差异；fill 拉伸会让视频人物变形，通常不可接受）
    if (isVideo) {
        const v = document.createElement('video');
        v.src = url;
        v.autoplay = true;
        v.muted = true;
        v.loop = true;
        v.setAttribute('playsinline', '');
        v.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:1;';
        container.appendChild(v);
        return;
    }

    // 图片：fill 直接拉扯到容器尺寸，铺满四周、不裁切、不留白（图片会被按容器比例变形）
    const img = new Image();
    img.alt = '';
    img.style.cssText = 'position:relative;display:block;width:100%;height:100%;object-fit:fill;z-index:1;';
    img.src = url;
    container.appendChild(img);
}

export async function uploadCover(app, input) {
    if (!checkAuth(app)) return;
    const file = input.files[0];
    if (!file) return;

    const isVideo = file.type.startsWith('video/');
    const maxSize = isVideo ? 30 * 1024 * 1024 : 5 * 1024 * 1024;
    if (file.size > maxSize) {
        app.showToast(isVideo ? '视频不能超过30MB' : '图片不能超过5MB');
        input.value = '';
        return;
    }

    app.showToast('上传中...');
    try {
        // 图片封面压缩（视频不压缩，保留原画质）
        let uploadFile = file;
        if (!isVideo) {
            try {
                uploadFile = await compressImageFile(file, { maxWidth: 1280, maxHeight: 720, quality: 0.82 });
            } catch (e) {
                uploadFile = file;
            }
        }

        // 统一走直传助手（本地 driver 自动回退到 /auth/cover 原接口）
        const data = await universalDirectUpload(app, {
            type: 'cover',
            file: uploadFile,
            fieldName: 'cover',
            localEndpoint: '/auth/cover',
            skipRegister: false,
            origFilename: file.name
        });
        if (!data || !data.cover) throw new Error('上传后未得到封面 URL');

        // 同步更新 app.user.cover 和 localStorage
        app.user = app.user || {};
        app.user.cover = data.cover;
        localStorage.setItem('hublog_user', JSON.stringify(app.user));

        // 更新个人主页封面显示（容器高度随图片比例自适应：完整显示 + 不留白）
        const coverBg = document.getElementById('cover-bg');
        if (coverBg) {
            setCover(app, coverBg, data.cover, isVideo);
        }

        // 更新首页顶部封面显示
        const headerCover = document.getElementById('header-cover');
        if (headerCover) {
            setCover(app, headerCover, data.cover, isVideo);
        }
        app.showToast('封面更新成功');
    } catch (e) {
        // 认证失效时引导用户重新登录
        if (/请先登录|登录状态已失效/.test(e.message)) {
            app.showToast('登录已失效，请重新登录');
            setTimeout(() => {
                app.token = null;
                app.user = null;
                // 服务端已判定认证失效 → 同步未登录态
                app.authed = false;
                localStorage.removeItem('hublog_token');
                localStorage.removeItem('hublog_user');
                app.openAuthModal();
            }, 1200);
        } else {
            app.showToast(e.message);
        }
    } finally {
        input.value = '';
    }
}

/**
 * 登录态守卫：是否已登录。
 *
 * 【为什么不能看 app.token】
 * P0-3 起令牌持久化交给 httpOnly Cookie，JS 既读不到也不该存，
 * 页面刷新后 app.token 恒为 null。若用它判登录，已登录用户会被误判成游客，
 * 一��任何需要登录的入口就弹登录框（积分中心 / 收藏 / 私信…）。
 *
 * 【判据】
 * app.authed 是服务端 /auth/me 确认过的会话状态：
 *   true  → 已登录，放行
 *   false → 确实未登录，弹登录框
 *   null  → 还没确认（如会话正在确认中）。此时不弹框打断用户，
 *           交给后续请求的 401 处理——服务端才是最终权威。
 */
export function checkAuth(app) {
    if (app.authed === false) {
        app.showToast('请先登录后再进行操作');
        openAuthModal(app);
        return false;
    }
    return true;
}

export function openAuthModal(app) {
    document.getElementById('auth-modal')?.classList.add('active');
}

export function switchAuthTab(app, tab) {
    document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
    document.getElementById(`tab-${tab}`)?.classList.add('active');
    const loginForm = document.getElementById('login-form');
    const registerForm = document.getElementById('register-form');
    if (loginForm) loginForm.style.display = tab === 'login' ? 'flex' : 'none';
    if (registerForm) registerForm.style.display = tab === 'register' ? 'flex' : 'none';
}

export function clearAuthForm(app) {
    document.getElementById('login-username').value = '';
    document.getElementById('login-password').value = '';
    document.getElementById('reg-username').value = '';
    document.getElementById('reg-email').value = '';
    document.getElementById('reg-password').value = '';
    document.getElementById('reg-password-confirm').value = '';
}

export async function fetchUser(app) {
    // P0-3：不能因为 app.token 为空就跳过 —— Cookie 模式下令牌在 httpOnly 里，
    // 前端读不到，但服务端认得这个会话。这里必须实际问一次服务端，
    // 才能把 app.authed 置成 true（已登录）还是 false（未登录/会话失效）。
    try {
        const user = await app.request('/auth/me');
        if (user) {
            app.user = user;
            app.authed = true;
            localStorage.setItem('hublog_user', JSON.stringify(user));
        } else {
            app.authed = false;
        }
    } catch (e) {
        // /auth/me 401 = 服务端确认未登录 / 会话已失效 → 清除残留登录态，
        // 避免"幽灵登录"（界面显示已登录，实际任何写操作都会被拒）。
        app.authed = false;
        clearStaleAuth(app);
    }
}

/**
 * 清除过期的认证状态（token 失效、用户不存在等场景）
 * 统一清理 app 内存状态 + localStorage + UI 刷新
 */
export function clearStaleAuth(app) {
    app.token = null;
    app.user = null;
    // 服务端已确认这个会话不可用 → 同步置为「未登录」，
    // 否则 checkAuth() 仍会放行，用户点了才被 401 拦下，体验割裂。
    app.authed = false;
    localStorage.removeItem('hublog_token');
    localStorage.removeItem('hublog_user');
    // 停止轮询
    if (app.notificationPollingInterval) {
        clearInterval(app.notificationPollingInterval);
        app.notificationPollingInterval = null;
    }
    if (app.unreadPollingInterval) {
        clearInterval(app.unreadPollingInterval);
        app.unreadPollingInterval = null;
    }
    app.notificationUnread = 0;
    // 刷新 UI（如果在非页面场景下 updateAuthUI 不存在则跳过）
    if (typeof app.updateAuthUI === 'function') {
        app.updateAuthUI();
    }
}

export function updateAuthUI(app) {
    const headerAvatar = document.getElementById('header-avatar');
    const headerNickname = document.getElementById('header-nickname');
    const profileInfo = document.querySelector('.profile-info');
    const bottomNav = document.getElementById('bottom-nav');
    const siteFooter = document.getElementById('site-footer');
    const headerCover = document.getElementById('header-cover');

    if (app.user) {
        const avatar = resolveAvatar(app, app.user.avatar);
        if (headerAvatar) headerAvatar.src = avatar;
        if (headerNickname) headerNickname.textContent = app.user.nickname || app.user.username;
        if (profileInfo) profileInfo.style.display = '';
        if (bottomNav) bottomNav.style.display = '';
        // 登录后底部导航常驻（fixed），标记 body 让 CSS 给文档底部预留等高空间，
        // 否则列表最后一屏内容被导航遮挡且无法滚动出来（个人主页等无页脚页面）
        document.body.classList.add('has-bottom-nav');
        if (siteFooter) siteFooter.style.display = 'none';

        // 首页顶部封面：用户封面优先，其次后台设置的首页封面
        const userCover = app.user.cover;
        const siteCover = app.siteConfig?.homepage_cover;
        if (headerCover && (userCover || siteCover)) {
            const coverSrc = userCover || siteCover;
            const isVideoCover = /\.(mp4|webm)(\?|$)/i.test(coverSrc);
            const cacheBuster = '_t=' + (userCover ? (app.user.cover_updated_at || Date.now()) : (app.siteConfig?._ts || Date.now()));
            const coverUrl = coverSrc + (coverSrc.includes('?') ? '&' : '?') + cacheBuster;
            setCover(app, headerCover, coverUrl, isVideoCover);
        }
    } else {
        // 未登录：使用后台设置的首页封面（如果有）
        const siteCover = app.siteConfig?.homepage_cover;
        if (headerCover && siteCover) {
            setCover(app, headerCover, siteCover, /\.(mp4|webm)(\?|$)/i.test(siteCover));
        }
        if (profileInfo) profileInfo.style.display = 'none';
        if (bottomNav) bottomNav.style.display = 'none';
        document.body.classList.remove('has-bottom-nav');
        renderFooter(app);
    }
    app.updatePermissionUI();
    if (typeof app.loadSidebarData === 'function') {
        app.loadSidebarData();
    }
}

export function resolveAvatar(app, avatar, prefix = '') {
    if (!avatar || avatar === 'null' || avatar === 'undefined') {
        return prefix + 'assets/images/default-avatar.svg';
    }
    const knownBroken = [
        'templates/moments/assets/images/default-avatar.svg',
        '/uploads/avatars/default.svg',
    ];
    if (knownBroken.includes(avatar) || avatar.endsWith('/uploads/avatars/default.svg')) {
        return prefix + 'assets/images/default-avatar.svg';
    }
    return avatar;
}

export function openProfileModal(app) {
    if (!checkAuth(app)) return;
    const nicknameInput = document.getElementById('profile-nickname');
    const bioInput = document.getElementById('profile-bio-input');
    const usernameInput = document.getElementById('profile-username');
    const avatarImg = document.getElementById('profile-modal-avatar');
    if (nicknameInput) nicknameInput.value = app.user?.nickname || '';
    if (bioInput) bioInput.value = app.user?.bio || '';
    if (usernameInput) usernameInput.value = app.user?.username || '';
    if (avatarImg) avatarImg.src = app.resolveAvatar(app.user?.avatar);
    document.getElementById('profile-modal')?.classList.add('active');
}

export function compressImage(app, file, maxWidth, maxHeight, quality = 0.85) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            URL.revokeObjectURL(url);
            let width = img.width;
            let height = img.height;
            if (width > maxWidth || height > maxHeight) {
                const ratio = Math.min(maxWidth / width, maxHeight / height);
                width = Math.round(width * ratio);
                height = Math.round(height * ratio);
            }
            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(img, 0, 0, width, height);
            canvas.toBlob((blob) => {
                if (!blob) {
                    reject(new Error('图片压缩失败'));
                    return;
                }
                resolve(new File([blob], file.name, { type: blob.type }));
            }, file.type, quality);
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('图片加载失败'));
        };
        img.src = url;
    });
}

export function renderFooter(app) {
    const siteFooter = document.getElementById('site-footer');
    if (!siteFooter) return;
    siteFooter.style.display = '';

    const data = window.HUBLOG_FOOTER_DATA || {};
    const links = Array.isArray(data.friend_links) ? data.friend_links : [];

    const linksEl = document.getElementById('footer-friend-links');
    if (linksEl) {
        if (links.length > 0) {
            linksEl.innerHTML = '<span class="footer-label">友情链接：</span>' +
                links.map(link => `<a href="${app.escapeHtml(link.url)}" target="_blank" rel="noopener" title="${app.escapeHtml(link.description || '')}">${app.escapeHtml(link.name)}</a>`).join('<span class="footer-dot"></span>');
        } else {
            linksEl.innerHTML = '';
        }
    }

    const copyrightEl = document.getElementById('footer-copyright');
    if (copyrightEl) {
        copyrightEl.textContent = `© ${data.year || new Date().getFullYear()} ${data.site_name || '我圈'}`;
    }

    const execEl = document.getElementById('footer-execution');
    if (execEl) {
        execEl.textContent = `执行时间 ${data.execution_time || 0}ms`;
    }

    const icpEl = document.getElementById('footer-icp');
    if (icpEl) {
        const icp = data.site_icp || '';
        icpEl.innerHTML = icp ? `<a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener">${app.escapeHtml(icp)}</a>` : '';
    }
}
