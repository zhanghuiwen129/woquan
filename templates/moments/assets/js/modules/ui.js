/**
 * woquan UI 工具方法
 */
import { BASE_URL, PAGE_BASE } from './config.js?v=20260803.5';
import * as api from './api.js?v=20260803.5';
import { resolveAvatar } from './auth.js?v=20260908.4';

export function openImageViewer(app, images, index) {
    viewImage(app, null, index || 0, images);
}

export function viewImage(app, src, index, images) {
    app.viewerImages = Array.isArray(images) ? images : [src];
    app.viewerIndex = index || 0;
    const img = document.getElementById('viewer-img');
    if (img) img.src = app.viewerImages[app.viewerIndex];
    document.getElementById('image-viewer')?.classList.add('active');
    app.updateViewerNav();
}

export function updateViewerNav(app) {
    const prev = document.getElementById('viewer-prev');
    const next = document.getElementById('viewer-next');
    const show = app.viewerImages.length > 1;
    if (prev) prev.style.display = show ? 'flex' : 'none';
    if (next) next.style.display = show ? 'flex' : 'none';
}

export function prevImage(app, e) {
    e && e.stopPropagation();
    if (app.viewerImages.length <= 1) return;
    app.viewerIndex = (app.viewerIndex - 1 + app.viewerImages.length) % app.viewerImages.length;
    const img = document.getElementById('viewer-img');
    if (img) img.src = app.viewerImages[app.viewerIndex];
}

export function nextImage(app, e) {
    e && e.stopPropagation();
    if (app.viewerImages.length <= 1) return;
    app.viewerIndex = (app.viewerIndex + 1) % app.viewerImages.length;
    const img = document.getElementById('viewer-img');
    if (img) img.src = app.viewerImages[app.viewerIndex];
}

export function closeViewer(app) {
    document.getElementById('image-viewer')?.classList.remove('active');
}

export function goUser(app, username) {
    if (!username) return;
    window.location.href = PAGE_BASE + '/u/' + encodeURIComponent(username);
}

export function goToPost(app, postId) {
    document.getElementById('notification-modal')?.classList.remove('active');
    const postEl = document.querySelector(`.moment-item[data-id="${postId}"]`);
    if (postEl) {
        postEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        postEl.style.transition = 'background 0.3s';
        postEl.style.background = 'rgba(7, 193, 96, 0.08)';
        setTimeout(() => { postEl.style.background = ''; }, 1500);
    } else {
        app.showToast('该动态不在当前列表中');
    }
}

export function goAdmin(app) {
    // admin 是独立目录（admin/index.html），直接通过 BASE_URL 访问，
    // 不使用 PAGE_BASE（伪静态关闭时为 /index.php，会导致 /index.php/admin 路由错误）
    const url = BASE_URL + '/admin/';
    window.open(url, '_blank');
}

export function showToast(app, msg) {
    const toast = document.getElementById('toast');
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 2000);
}

export function showPrompt(app, message, placeholder, callback) {
    const result = prompt(message, placeholder || '');
    if (callback) {
        callback(result);
    }
}

export async function loadSiteConfig(app) {
    try {
        const config = await app.request('/site/config');
        app.siteConfig = config || {};
        if (config && config.site_name) {
            const headerTitle = document.getElementById('header-title');
            if (headerTitle) headerTitle.textContent = config.site_name;
        }
        app.renderPublishCategories();
        app.renderPublishTitle();
        app.updatePermissionUI();
        if (typeof app.updatePublishFeatureButtons === 'function') {
            app.updatePublishFeatureButtons();
        }
    } catch (e) {
        app.siteConfig = {};
    }
}

export function renderPublishCategories(app) {
    const select = document.getElementById('publish-category');
    if (!select) return;
    const categories = app.siteConfig?.categories || [];
    if (categories.length === 0) {
        select.style.display = 'none';
        return;
    }
    select.style.display = '';
    const required = app.siteConfig?.category_required === true;
    select.innerHTML = `<option value="">${required ? '* 请选择分类' : '选择分类（可选）'}</option>`;
    categories.forEach(cat => {
        select.appendChild(new Option(cat.name, cat.id));
        (cat.children || []).forEach(child => {
            select.appendChild(new Option('　├ ' + child.name, child.id));
        });
    });
    select.classList.toggle('required', required);
}

export function renderPublishTitle(app) {
    const input = document.getElementById('publish-title');
    if (!input) return;
    const required = app.siteConfig?.title_required === true;
    input.placeholder = required ? '标题（必填）' : '标题（选填）';
    input.classList.toggle('required', required);
}

export function updatePermissionUI(app) {
    const cfg = app.siteConfig || {};
    const isAdmin = app.user?.role === 'admin';
    const canPost = (isAdmin || cfg.allow_post !== false) && !!app.user;
    const btnPublish = document.getElementById('btn-publish');
    if (btnPublish) {
        btnPublish.style.display = (isAdmin || cfg.allow_post !== false) ? 'block' : 'none';
    }

    // PC端内联发布框：未登录或禁止发帖时隐藏
    const publishComposer = document.getElementById('publish-modal');
    if (publishComposer) {
        publishComposer.classList.toggle('pc-hidden', !canPost);
    }

    // 管理员后台入口：仅管理员可见
    const adminLink = document.getElementById('nav-admin');
    if (adminLink) {
        adminLink.style.display = isAdmin ? '' : 'none';
    }

    const uploadActions = document.getElementById('upload-actions');
    const btnMedia = document.getElementById('btn-media-upload');
    const btnVideo = document.getElementById('btn-video-upload');
    // 图片按钮：图片上传开启时显示
    if (btnMedia) btnMedia.style.display = canUploadImages(app) ? 'inline-flex' : 'none';
    // 视频按钮：视频上传开启时显示
    if (btnVideo) btnVideo.style.display = canUploadVideos(app) ? 'inline-flex' : 'none';
    if (uploadActions) {
        const anyVisible = canUploadImages(app) || canUploadVideos(app);
        uploadActions.style.display = anyVisible ? 'flex' : 'none';
    }

    updateBottomNav(app);
}

function updateBottomNav(app) {
    const cfg = app.siteConfig || {};
    const bottomNav = document.getElementById('bottom-nav');
    if (!bottomNav) return;

    const searchNav = bottomNav.querySelector('[data-nav="search"]');
    if (searchNav) {
        searchNav.style.display = (cfg.nav_search_enabled !== false) ? '' : 'none';
    }

    const favoritesNav = bottomNav.querySelector('[data-nav="favorites"]');
    if (favoritesNav) {
        favoritesNav.style.display = (cfg.nav_favorites_enabled !== false) ? '' : 'none';
    }

    const profileNav = bottomNav.querySelector('[data-nav="profile"]');
    if (profileNav) {
        profileNav.style.display = (cfg.nav_profile_enabled !== false) ? '' : 'none';
    }
}

export function canInteract(app, action) {
    if (app.user?.role === 'admin') return true;
    const cfg = app.siteConfig || {};
    if (action === 'post') return cfg.allow_post !== false;
    if (action === 'like') return cfg.allow_like !== false;
    if (action === 'comment') return cfg.allow_comment !== false;
    return true;
}

export function getMaxImages(app) {
    return app.siteConfig?.upload_max_images || 9;
}

export function getMaxVideos(app) {
    return app.siteConfig?.upload_max_videos || 3;
}

export function getMaxAttachments(app) {
    return app.siteConfig?.upload_max_attachments || 5;
}

export function canUploadMedia(app) {
    const cfg = app.siteConfig || {};
    return (cfg.allow_upload_image !== false) || (cfg.allow_upload_video !== false);
}

export function canUploadImages(app) {
    return (app.siteConfig?.allow_upload_image ?? true) !== false && (app.siteConfig?.upload_max_images ?? 9) > 0;
}

export function canUploadVideos(app) {
    return (app.siteConfig?.allow_upload_video ?? true) !== false && (app.siteConfig?.upload_max_videos ?? 3) > 0;
}

export function canUploadAttachments(app) {
    return (app.siteConfig?.allow_upload_attachment ?? true) !== false && (app.siteConfig?.upload_max_attachments ?? 5) > 0;
}

export async function loadSidebarData(app) {
    loadUserCard(app);
    loadSiteStats(app);
    loadUserRanking(app);
    loadFriendLinks(app);
    loadCategories(app);
}

function loadUserCard(app) {
    const avatar = document.getElementById('sidebar-avatar');
    const nickname = document.getElementById('sidebar-nickname');
    const bio = document.getElementById('sidebar-bio');
    const postsCount = document.getElementById('sidebar-stat-posts');
    const followingCount = document.getElementById('sidebar-stat-following');
    const followersCount = document.getElementById('sidebar-stat-followers');

    if (app.user) {
        if (avatar) avatar.src = resolveAvatar(app, app.user.avatar);
        // V71：侧栏展示自己的会员徽章（textContent 会吞 HTML，故改 innerHTML + 转义）
        if (nickname) nickname.innerHTML = app.escapeHtml(app.user.nickname || app.user.username || 'woquan 用户') + app.vipBadge(app.user.vip_level, 16);
        if (bio) bio.textContent = app.user.bio || '这个人很懒，什么都没留下~';
        if (postsCount) postsCount.textContent = '—';
        if (followingCount) followingCount.textContent = '—';
        if (followersCount) followersCount.textContent = '—';
        loadUserStats(app);
    } else {
        if (avatar) avatar.src = 'assets/images/default-avatar.svg';
        if (nickname) nickname.textContent = 'woquan 用户';
        if (bio) bio.textContent = '登录后查看更多精彩内容';
        if (postsCount) postsCount.textContent = '0';
        if (followingCount) followingCount.textContent = '0';
        if (followersCount) followersCount.textContent = '0';
    }
}

async function loadUserStats(app) {
    if (!app.user) return;
    try {
        const profileData = await api.request(app, '/user/profile?username=' + encodeURIComponent(app.user.username));
        const postsCount = document.getElementById('sidebar-stat-posts');
        if (postsCount && profileData?.stats?.posts !== undefined) {
            postsCount.textContent = profileData.stats.posts;
        }
    } catch (e) {}

    try {
        const followData = await api.request(app, '/follow/stats');
        const followingCount = document.getElementById('sidebar-stat-following');
        const followersCount = document.getElementById('sidebar-stat-followers');
        if (followingCount && followData?.following_count !== undefined) {
            followingCount.textContent = followData.following_count;
        }
        if (followersCount && followData?.followers_count !== undefined) {
            followersCount.textContent = followData.followers_count;
        }
    } catch (e) {}
}

async function loadSiteStats(app) {
    try {
        const data = await api.request(app, '/site/stats');
        const elPosts = document.getElementById('stats-total-posts');
        const elComments = document.getElementById('stats-total-comments');
        const elLikes = document.getElementById('stats-total-likes');
        const elUsers = document.getElementById('stats-total-users');
        if (elPosts) elPosts.textContent = data.total_posts ?? 0;
        if (elComments) elComments.textContent = data.total_comments ?? 0;
        if (elLikes) elLikes.textContent = data.total_likes ?? 0;
        if (elUsers) elUsers.textContent = data.total_users ?? 0;
    } catch (e) {
        console.error('[loadSiteStats] 获取站点统计失败:', e.message);
    }
}

async function loadUserRanking(app) {
    try {
        const list = await api.request(app, '/site/user-ranking?limit=10');
        const card = document.getElementById('user-ranking-card');
        const container = document.getElementById('user-ranking-list');
        if (!card || !container) return;
        if (!list || list.length === 0) {
            card.style.display = 'none';
            return;
        }
        card.style.display = '';
        const medals = ['🥇', '🥈', '🥉'];
        container.innerHTML = list.map((u, i) => {
            const rank = i < 3 ? medals[i] : `<span class="rank-num">${i + 1}</span>`;
            const name = app.escapeHtml(u.nickname || u.username);
            // P1-12 修复：头像 URL 协议白名单 + 属性转义
            const avatar = app.escapeAttr(app.safeUrl(u.avatar) || 'assets/images/default-avatar.svg');
            // V71：VIP 徽章（铜/银/金，与 App 端同一套 vipBadge helper，等级 <=0 自动返回空串）
            const vipBadge = app.vipBadge(u.vip_level, 14);
            return `<div class="ranking-item" onclick="app.goUser('${app.escapeJs(u.username)}')" style="display:flex;align-items:center;gap:8px;padding:8px 4px;cursor:pointer;border-bottom:1px solid var(--border);">
                <span class="ranking-rank" style="width:24px;text-align:center;font-size:14px;flex-shrink:0;">${rank}</span>
                <img src="${avatar}" class="ranking-avatar" style="width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt="">
                <span class="ranking-name" style="flex:1;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${name}${vipBadge}</span>
                <span class="ranking-score" style="font-size:12px;color:var(--primary);font-weight:600;flex-shrink:0;">${u.score}</span>
            </div>`;
        }).join('');
    } catch (e) {
        console.error('[loadUserRanking] 获取用户排行失败:', e.message);
    }
}

async function loadFriendLinks(app) {
    const el = document.getElementById('sidebar-friends');
    if (!el) return;
    try {
        const links = await api.request(app, '/site/friend-links');
        if (links && links.length > 0) {
            // P1-12 修复：友情链接 URL 协议白名单，防止 javascript: 注入
            el.innerHTML = links.map(l => {
                const safeHref = app.safeUrl(l.url);
                if (!safeHref) return ''; // 不安全的 URL 直接过滤
                const name = app.escapeHtml(l.name);
                return `<li><a href="${app.escapeAttr(safeHref)}" target="_blank" rel="noopener noreferrer nofollow">${name}</a></li>`;
            }).join('');
        } else {
            el.innerHTML = '<li style="color:#999;">暂无友情链接</li>';
        }
    } catch (e) {
        el.innerHTML = '<li style="color:#999;">加载失败</li>';
    }
}

function loadCategories(app) {
    const container = document.getElementById('sidebar-categories');
    const list = document.getElementById('category-list');
    if (!container || !list || !app.siteConfig) return;
    
    const categories = app.siteConfig.categories || [];
    if (categories.length > 0) {
        container.style.display = '';
        list.innerHTML = categories.map(c => {
            const count = c.count || 0;
            return `<li><a href="#" onclick="event.preventDefault(); location.assign(window.HUBLOG_PAGE_BASE + '/c/${c.id}');" style="color:inherit;text-decoration:none;">${c.name} <span style="color:#999;float:right;">${count}</span></a></li>`;
        }).join('');
    }
}
