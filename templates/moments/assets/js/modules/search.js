/**
 * woquan 内容搜索
 */

export async function searchPosts(app, query, page = 1, filters = {}) {
    if (!query || !query.trim()) return { list: [], pagination: { pages: 0 } };
    try {
        const catId = filters.category_id || '';
        const range = filters.date_range || '';
        const mediaType = filters.media_type || '';
        let url = `/search?q=${encodeURIComponent(query.trim())}&page=${page}&limit=10`;
        if (catId) url += `&category_id=${encodeURIComponent(catId)}`;
        if (range) url += `&date_range=${encodeURIComponent(range)}`;
        if (mediaType) url += `&media_type=${encodeURIComponent(mediaType)}`;
        const data = await app.request(url);
        return data || { list: [], pagination: { pages: 0 } };
    } catch (e) {
        app.showToast(e.message);
        return { list: [], pagination: { pages: 0 } };
    }
}

export function switchSearchTab(app, tabName) {
    const tabs = document.querySelectorAll('.search-tab');
    tabs.forEach(t => {
        t.classList.toggle('active', t.dataset.searchTab === tabName);
    });
    const panelPosts = document.getElementById('search-panel-posts');
    const panelUsers = document.getElementById('search-panel-users');
    if (panelPosts) panelPosts.classList.toggle('active', tabName === 'posts');
    if (panelUsers) panelUsers.classList.toggle('active', tabName === 'users');
    app.currentSearchTab = tabName;

    const searchInput = document.getElementById('search-input');
    const query = searchInput ? searchInput.value.trim() : '';
    if (tabName === 'users' && query) {
        searchUsers(app, query);
    }
}

export async function searchUsers(app, keyword) {
    const container = document.getElementById('user-search-results');
    if (!container) return;

    if (!keyword || !keyword.trim()) {
        container.innerHTML = '<div class="empty-state">输入关键词搜索用户</div>';
        return;
    }

    container.innerHTML = '<div class="empty-state"><span class="loading-spinner"></span> 搜索中...</div>';

    try {
        const data = await app.request(`/user/search?q=${encodeURIComponent(keyword)}`);
        const list = data?.list || [];

        if (list.length === 0) {
            container.innerHTML = `<div class="empty-state">未找到相关用户</div>`;
            return;
        }

        const currentUserId = app.user?.id;
        // 使用 DocumentFragment 批量插入，减少重排
        const frag = document.createDocumentFragment();
        const tempDiv = document.createElement('div');
        list.map(user => {
            const isSelf = currentUserId && user.id === currentUserId;
            const avatar = app.resolveAvatar(user.avatar);
            const nickname = app.escapeHtml(user.nickname || user.username);
            const username = app.escapeHtml(user.username);
            const bio = user.bio ? app.escapeHtml(user.bio) : '这个人很懒，什么都没留下~';
            const stats = [];
            if (user.follower_count != null) stats.push(`粉丝 ${user.follower_count}`);
            if (user.post_count != null) stats.push(`动态 ${user.post_count}`);
            const statsText = stats.length > 0 ? `<div class="user-search-stats">${stats.join(' · ')}</div>` : '';

            let followBtn = '';
            if (!isSelf) {
                followBtn = `<button class="btn btn-primary btn-sm user-search-follow-btn" data-user-id="${user.id}" data-username="${username}" onclick="event.stopPropagation(); app.checkAuth() && app.toggleFollow(${user.id});">关注</button>`;
            }

            return `
                <div class="user-search-item" onclick="app.goUser('${app.escapeJs(user.username)}')">
                    <img class="user-search-avatar" src="${app.escapeHtml(avatar)}" onerror="this.src='assets/images/default-avatar.svg'" alt="" loading="lazy">
                    <div class="user-search-info">
                        <div class="user-search-name">${nickname}${app.vipBadge(user.vip_level, 14)}${isSelf ? '<span class="self-badge">自己</span>' : ''}</div>
                        <div class="user-search-username">@${username}</div>
                        <div class="user-search-bio">${bio}</div>
                        ${statsText}
                    </div>
                    <div class="user-search-actions">
                        ${followBtn}
                    </div>
                </div>
            `;
        }).forEach(html => {
            tempDiv.innerHTML = html;
            while (tempDiv.firstChild) frag.appendChild(tempDiv.firstChild);
        });
        container.innerHTML = '';
        container.appendChild(frag);

        const followBtns = container.querySelectorAll('.user-search-follow-btn');
        const userIds = [];
        followBtns.forEach(btn => {
            const userId = parseInt(btn.dataset.userId, 10);
            if (userId) userIds.push(userId);
        });

        if (userIds.length > 0 && app.authed !== false) {
            // 批量查询关注状态，消除 N+1
            try {
                const statusMap = await app.request('/follow/batch-check', {
                    method: 'POST',
                    body: { user_ids: userIds }
                });
                followBtns.forEach(btn => {
                    const userId = parseInt(btn.dataset.userId, 10);
                    const isFollowing = !!statusMap?.[userId];
                    if (isFollowing) {
                        btn.classList.remove('btn-primary');
                        btn.classList.add('btn-default');
                        btn.textContent = '已关注';
                    }
                });
            } catch (e) {}
        }
    } catch (e) {
        container.innerHTML = `<div class="empty-state">搜索失败：${app.escapeHtml(e.message)}</div>`;
    }
}

export async function loadPopularPosts(app, type = 'comments') {
    const list = document.getElementById('moments-list');
    if (!list) return;

    const typeLabels = {
        comments: '评论最多',
        likes: '点赞最多',
        favorites: '收藏最多'
    };

    list.innerHTML = `<div class="loading-state" style="text-align:center;padding:20px;color:var(--text-secondary);">正在加载热门动态...</div>`;

    try {
        const data = await app.request(`/posts/popular?type=${type}&limit=10`);
        if (!data.list || data.list.length === 0) {
            list.innerHTML = `<div class="empty-state">暂无热门动态</div>`;
            return;
        }

        list.innerHTML = '';
        // 顶部标题和切换标签
        const header = document.createElement('div');
        header.className = 'popular-header';
        header.innerHTML = `
            <div class="popular-header-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13.5.67s.74 2.65.74 4.8c0 2.06-1.35 3.73-3.41 3.73-2.07 0-3.63-1.67-3.63-3.73l.03-.36C5.21 7.51 4 10.62 4 14c0 4.42 3.58 8 8 8s8-3.58 8-8C20 8.61 17.41 3.8 13.5.67zM11.71 19c-1.78 0-3.22-1.4-3.22-3.14 0-1.62 1.05-2.76 2.81-3.12 1.77-.36 3.6-1.21 4.62-2.58.39 1.29.59 2.65.59 4.04 0 2.65-2.15 4.8-4.8 4.8z"/></svg>
                <span>${typeLabels[type] || '热门动态'}</span>
            </div>
            <div class="popular-tabs">
                <button class="popular-tab ${type === 'comments' ? 'active' : ''}" data-type="comments">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span>评论</span>
                </button>
                <button class="popular-tab ${type === 'likes' ? 'active' : ''}" data-type="likes">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    <span>点赞</span>
                </button>
                <button class="popular-tab ${type === 'favorites' ? 'active' : ''}" data-type="favorites">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    <span>收藏</span>
                </button>
            </div>
        `;
        list.appendChild(header);

        // 绑定标签切换
        header.querySelectorAll('.popular-tabs button').forEach(btn => {
            btn.addEventListener('click', () => {
                const newType = btn.dataset.type;
                loadPopularPosts(app, newType);
            });
        });

        // 渲染动态列表
        data.list.forEach(post => {
            list.appendChild(app.createPostElement(post));
        });

        const loadMore = document.getElementById('load-more');
        if (loadMore) {
            loadMore.innerHTML = '';
        }
    } catch (e) {
        list.innerHTML = `<div class="empty-state">加载失败：${app.escapeHtml(e.message)}</div>`;
    }
}

/**
 * 渲染搜索筛选栏 HTML
 */
export function renderFilterBar(app) {
    const cfg = app.siteConfig || {};
    const categories = cfg.categories || [];

    const categoryOptions = categories.map(cat =>
        `<option value="${cat.id}">${app.escapeHtml(cat.name)}</option>`
    ).join('');

    const currentFilters = app.searchFilters || {};

    return `
        <div class="search-filter-bar" id="search-filter-bar" style="display:flex;gap:8px;padding:8px 12px;flex-wrap:wrap;align-items:center;border-bottom:1px solid var(--border,#e5e5e5);background:var(--card-bg,#fff);">
            <select id="filter-category" class="filter-select" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border,#e5e5e5);background:var(--card-bg,#fff);color:var(--text,#333);font-size:13px;">
                <option value="">全部分类</option>
                ${categoryOptions}
            </select>
            <select id="filter-date-range" class="filter-select" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border,#e5e5e5);background:var(--card-bg,#fff);color:var(--text,#333);font-size:13px;">
                <option value="">全部时间</option>
                <option value="7d">7天内</option>
                <option value="30d">30天内</option>
            </select>
            <select id="filter-media-type" class="filter-select" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border,#e5e5e5);background:var(--card-bg,#fff);color:var(--text,#333);font-size:13px;">
                <option value="">全部</option>
                <option value="image">含图片</option>
                <option value="video">含视频</option>
                <option value="text">纯文字</option>
            </select>
        </div>
    `;
}

export function renderSearchResults(app, data, query) {
    const list = document.getElementById('moments-list');
    if (!list) return;

    if (!data.list || data.list.length === 0) {
        list.innerHTML = renderFilterBar(app) + `<div class="empty-state">未找到与「${app.escapeHtml(query)}」相关的动态</div>`;
        bindFilterEvents(app);
        return;
    }

    list.innerHTML = renderFilterBar(app);
    const resultsContainer = document.createElement('div');
    resultsContainer.className = 'search-results-list';
    data.list.forEach(post => {
        resultsContainer.appendChild(app.createPostElement(post));
    });
    list.appendChild(resultsContainer);

    bindFilterEvents(app);

    const loadMore = document.getElementById('load-more');
    if (loadMore) {
        loadMore.innerHTML = data.pagination.page < data.pagination.pages ? '加载更多' : '没有更多结果了';
    }
}

/**
 * 绑定筛选栏事件
 */
function bindFilterEvents(app) {
    const catSelect = document.getElementById('filter-category');
    const dateSelect = document.getElementById('filter-date-range');
    const mediaSelect = document.getElementById('filter-media-type');

    const onFilterChange = () => {
        app.searchFilters = {
            category_id: catSelect ? catSelect.value : '',
            date_range: dateSelect ? dateSelect.value : '',
            media_type: mediaSelect ? mediaSelect.value : ''
        };
        app.searchPage = 1;
        const query = app.searchQuery || '';
        if (query) {
            searchPosts(app, query, 1, app.searchFilters).then(data => {
                renderSearchResults(app, data, query);
            });
        }
    };

    if (catSelect) catSelect.addEventListener('change', onFilterChange);
    if (dateSelect) dateSelect.addEventListener('change', onFilterChange);
    if (mediaSelect) mediaSelect.addEventListener('change', onFilterChange);
}

export function initSearchPage(app) {
    const searchInput = document.getElementById('search-input');
    const searchBtn = document.getElementById('search-btn');
    if (!searchInput || !searchBtn) return;

    app.currentSearchTab = 'posts';
    app.searchFilters = app.searchFilters || {};

    // 页面加载时，如果没有搜索关键词，显示热门动态
    const urlQuery = new URLSearchParams(window.location.search).get('q') || '';
    if (!urlQuery.trim()) {
        loadPopularPosts(app, 'comments');
    } else {
        searchInput.value = urlQuery;
    }

    const doSearch = async () => {
        const query = searchInput.value.trim();
        if (!query) {
            if (app.currentSearchTab === 'posts') {
                loadPopularPosts(app, 'comments');
            } else {
                searchUsers(app, '');
            }
            return;
        }
        app.searchQuery = query;
        if (app.currentSearchTab === 'posts') {
            app.searchPage = 1;
            const data = await searchPosts(app, query, 1, app.searchFilters || {});
            renderSearchResults(app, data, query);
        } else {
            searchUsers(app, query);
        }
    };

    searchBtn.addEventListener('click', doSearch);
    searchInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') doSearch();
    });
}
