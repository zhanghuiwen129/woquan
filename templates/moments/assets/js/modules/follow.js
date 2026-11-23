/**
 * woquan 关注/粉丝系统
 */

export async function toggleFollow(app, userId) {
    if (!app.checkAuth()) return;
    try {
        const data = await app.request('/follow', {
            method: 'POST',
            body: { user_id: userId }
        });
        app.showToast(data.following ? '关注成功' : '已取消关注');
        // 更新用户主页的关注按钮状态
        const btn = document.getElementById('btn-follow');
        if (btn) {
            btn.classList.toggle('following', data.following);
            btn.textContent = data.following ? '已关注' : '关注';
        }
        // 更新粉丝数
        const followersEl = document.getElementById('stat-followers');
        if (followersEl) {
            const cur = parseInt(followersEl.textContent, 10) || 0;
            followersEl.textContent = data.following ? cur + 1 : Math.max(0, cur - 1);
        }
        return data;
    } catch (e) {
        app.showToast(e.message);
    }
}

export async function checkFollowing(app, userId) {
    // P0-3：登录态看 app.authed，不看 app.token
    if (app.authed === false || !userId) return false;
    try {
        const data = await app.request(`/follow/check?user_id=${userId}`);
        return data?.following ?? false;
    } catch (e) {
        return false;
    }
}

/**
 * 批量查询关注状态（消除 N+1）
 * @param {Object} app - HublogApp 实例
 * @param {number[]} userIds - 要查询的用户 ID 列表
 * @returns {Promise<Object>} - { userId: boolean, ... }
 */
export async function batchCheckFollowing(app, userIds) {
    // P0-3：登录态看 app.authed，不看 app.token
    if (app.authed === false || !userIds || userIds.length === 0) return {};
    try {
        const data = await app.request('/follow/batch-check', {
            method: 'POST',
            body: { user_ids: userIds }
        });
        return data || {};
    } catch (e) {
        return {};
    }
}

export async function loadFollowStats(app, username) {
    try {
        const params = username ? `?username=${encodeURIComponent(username)}` : '';
        const data = await app.request(`/follow/stats${params}`);
        return data || { following_count: 0, followers_count: 0 };
    } catch (e) {
        return { following_count: 0, followers_count: 0 };
    }
}

export async function loadFollowers(app, username, page = 1) {
    try {
        const params = `?page=${page}&limit=20` + (username ? `&username=${encodeURIComponent(username)}` : '');
        return await app.request(`/follow/followers${params}`);
    } catch (e) {
        app.showToast(e.message);
        return { list: [], pagination: { pages: 0 } };
    }
}

export async function loadFollowing(app, page = 1, username) {
    try {
        const params = `?page=${page}&limit=20` + (username ? `&username=${encodeURIComponent(username)}` : '');
        return await app.request(`/follow/following${params}`);
    } catch (e) {
        return { list: [], pagination: { pages: 0 } };
    }
}

/**
 * 打开关注/粉丝列表弹窗
 * @param {string} type - 'following' 或 'followers'
 */
export async function openFollowList(app, type) {
    const title = type === 'following' ? '关注列表' : '粉丝列表';
    const username = app.viewingUsername || '';

    // 构建弹窗
    let modal = document.getElementById('follow-list-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'follow-list-modal';
        modal.className = 'modal active';
        modal.innerHTML = `
            <div class="modal-content" style="max-width:480px;">
                <div class="modal-header">
                    <h3 id="follow-list-title">${title}</h3>
                    <button class="modal-close" onclick="document.getElementById('follow-list-modal').classList.remove('active')">&times;</button>
                </div>
                <div class="modal-body" id="follow-list-body" style="max-height:60vh;overflow-y:auto;padding:8px 16px;"></div>
                <div class="modal-footer" style="display:none;">
                    <button class="btn btn-default btn-block" id="follow-list-more" onclick="app.loadMoreFollowList()">加载更多</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    document.getElementById('follow-list-title').textContent = title;
    document.getElementById('follow-list-body').innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-secondary);">加载中...</div>';
    modal.classList.add('active');

    app._followListType = type;
    app._followListPage = 1;

    await app.loadMoreFollowList();
}

export async function loadMoreFollowList(app) {
    const type = app._followListType;
    const page = app._followListPage || 1;
    const username = app.viewingUsername || '';

    try {
        let data;
        if (type === 'following') {
            data = await loadFollowing(app, page, username);
        } else {
            data = await loadFollowers(app, username, page);
        }

        const body = document.getElementById('follow-list-body');
        if (!body) return;

        if (page === 1) body.innerHTML = '';

        if (!data.list || data.list.length === 0) {
            if (page === 1) {
                body.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text-secondary);">暂无数据</div>';
            }
            const footer = document.querySelector('#follow-list-modal .modal-footer');
            if (footer) footer.style.display = 'none';
            return;
        }

        data.list.forEach(u => {
            const name = app.escapeHtml(u.nickname || u.username);
            // P1-12 修复：头像 URL 协议白名单 + 属性转义
            const avatar = app.escapeAttr(app.safeUrl(u.avatar) || 'assets/images/default-avatar.svg');
            const bio = app.escapeHtml(u.bio || '');
            const item = document.createElement('div');
            item.className = 'follow-list-item';
            item.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);cursor:pointer;';
            item.onclick = () => {
                app.goUser(u.username);
                document.getElementById('follow-list-modal')?.classList.remove('active');
            };
            item.innerHTML = `
                <img src="${avatar}" style="width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt="" loading="lazy">
                <div style="flex:1;min-width:0;">
                    <div style="font-size:14px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${name}${app.vipBadge(u.vip_level, 14)}</div>
                    ${bio ? `<div style="font-size:12px;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${bio}</div>` : ''}
                </div>
            `;
            body.appendChild(item);
        });

        app._followListPage = page + 1;

        const footer = document.querySelector('#follow-list-modal .modal-footer');
        if (footer) {
            footer.style.display = (data.pagination && page < data.pagination.pages) ? '' : 'none';
        }
    } catch (e) {
        const body = document.getElementById('follow-list-body');
        if (body) body.innerHTML = `<div style="text-align:center;padding:20px;color:var(--text-secondary);">加载失败</div>`;
    }
}

export function renderFollowButton(app, isFollowing) {
    return `<button class="btn-follow ${isFollowing ? 'following' : ''}" id="btn-follow" onclick="app.toggleFollow(${app.viewingUserId || 0})">${isFollowing ? '已关注' : '关注'}</button>`;
}

/**
 * 独立粉丝/关注列表页初始化
 */
export async function initFollowListPage(app) {
    if (!app.checkAuth()) {
        app.openAuthModal();
        return;
    }
    const type = app._followListType; // 'followers' | 'following'
    const username = app._followListUsername || '';
    const listEl = document.getElementById('follow-list-container');
    const moreBtn = document.getElementById('follow-list-more');
    if (!listEl) return;

    // 1. 如果是他人的粉丝/关注，先拉 profile 显示 subject 条
    if (username && (!app.user || username !== app.user.username)) {
        try {
            const prof = await app.request(`/user/profile?username=${encodeURIComponent(username)}`);
            const u = prof?.user;
            if (u) {
                app.viewingUser = u;
                const subjectEl = document.getElementById('follow-list-subject');
                if (subjectEl) {
                    document.getElementById('fl-subject-avatar').src = u.avatar || 'assets/images/default-avatar.svg';
                    document.getElementById('fl-subject-name').textContent = u.nickname || u.username;
                    document.getElementById('fl-subject-type').textContent = type === 'following' ? '的关注' : '的粉丝';
                    subjectEl.style.display = 'flex';
                }
                const titleEl = document.getElementById('follow-list-title');
                if (titleEl) titleEl.textContent = type === 'following' ? '关注' : '粉丝';
            }
        } catch (e) {
            // 忽略，继续加载列表
        }
    }

    // 2. 绑定加载更多
    if (moreBtn) {
        moreBtn.onclick = () => {
            if (!app._followListHasMore) return;
            app._followListPage = (app._followListPage || 1) + 1;
            loadMoreFollowListPage(app);
        };
        // 页面滚动到底部自动加载更多
        const onPageScroll = () => {
            if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 200) {
                if (app._followListHasMore && !app._followListLoading) {
                    app._followListPage = (app._followListPage || 1) + 1;
                    loadMoreFollowListPage(app);
                }
            }
        };
        window.addEventListener('scroll', onPageScroll, { passive: true });
    }

    await loadMoreFollowListPage(app, true);
}

/**
 * 独立粉丝/关注列表页：加载（append=first时清空）
 */
async function loadMoreFollowListPage(app, first = false) {
    const listEl = document.getElementById('follow-list-container');
    const moreBtn = document.getElementById('follow-list-more');
    if (!listEl) return;
    if (app._followListLoading) return;
    app._followListLoading = true;

    try {
        const page = app._followListPage || 1;
        const username = app._followListUsername || '';
        let data;
        if (app._followListType === 'following') {
            data = await loadFollowing(app, page, username);
        } else {
            data = await loadFollowers(app, username, page);
        }
        const list = data?.list || [];
        const hasMore = !!(data?.pagination && page < data.pagination.pages);
        app._followListHasMore = hasMore;

        if (first) listEl.innerHTML = '';

        if (list.length === 0 && first) {
            listEl.innerHTML = `<div class="empty-state">${app._followListType === 'following' ? '暂无关注' : '暂无粉丝'}</div>`;
            app._followListLoading = false;
            if (moreBtn) moreBtn.style.display = 'none';
            return;
        }

        // 渲染列表项（复用 user-search-item 样式）
        const base = (typeof window.HUBLOG_PAGE_BASE === 'string') ? window.HUBLOG_PAGE_BASE : '';
        const selfId = app.user?.id || 0;
        const idsToCheck = [];
        const fragment = document.createDocumentFragment();
        list.forEach(u => {
            const uId = u.id || 0;
            const isSelf = selfId && uId === selfId;
            const nick = app.escapeHtml(u.nickname || u.username);
            const ava = u.avatar || 'assets/images/default-avatar.svg';
            const bio = app.escapeHtml(u.bio || '');
            const fc = u.follower_count ?? u.followers_count ?? 0;
            const pc = u.post_count ?? u.posts_count ?? 0;

            const item = document.createElement('div');
            item.className = 'user-search-item';
            item.style.cursor = 'pointer';
            item.innerHTML = `
                <img class="user-search-avatar" src="${app.escapeHtml(ava)}" onerror="this.src='assets/images/default-avatar.svg'" loading="lazy">
                <div class="user-search-info">
                    <div class="user-search-name-row">
                        <span class="user-search-name">${nick}</span>
                        ${app.vipBadge(u.vip_level, 14)}
                        ${u.username ? `<span class="user-search-username">@${app.escapeHtml(u.username)}</span>` : ''}
                        ${isSelf ? '<span class="self-badge">自己</span>' : ''}
                    </div>
                    ${bio ? `<div class="user-search-bio">${bio}</div>` : ''}
                    <div class="user-search-stats">粉丝 ${fc} · 动态 ${pc}</div>
                </div>
                <div class="user-search-actions" style="flex-shrink:0;display:flex;gap:6px;align-items:center;">
                    ${isSelf ? '' : `<button class="btn-sm follow-check-btn" data-uid="${uId}" data-username="${app.escapeHtml(u.username || '')}" style="display:none;">关注</button>`}
                    ${isSelf ? '' : `<button class="btn-sm" style="background:transparent;color:var(--primary);border:1px solid var(--border);" onclick="event.stopPropagation(); if(app.checkAuth()){ app.startPrivateChat(${uId}); } else { app.openAuthModal(); }">私信</button>`}
                </div>
            `;
            item.onclick = () => {
                if (u.username) app.goUser(u.username);
                else if (uId) location.assign(base + `/u/${uId}`);
            };
            if (!isSelf) idsToCheck.push(uId);
            fragment.appendChild(item);
        });
        listEl.appendChild(fragment);

        // 批量查询关注状态，消除 N+1
        if (idsToCheck.length) {
            batchCheckFollowing(app, idsToCheck).then(statusMap => {
                idsToCheck.forEach(uid => {
                    const btn = listEl.querySelector(`.follow-check-btn[data-uid="${uid}"]`);
                    if (btn) {
                        const following = !!statusMap[uid];
                        btn.style.display = 'inline-flex';
                        btn.textContent = following ? '已关注' : '关注';
                        btn.classList.toggle('following', following);
                        btn.onclick = async (e) => {
                            e.stopPropagation();
                            await app.toggleFollow(uid);
                            // 刷新按钮状态
                            try {
                                const r2 = await app.request(`/follow/check?user_id=${uid}`);
                                const f = !!r2?.following;
                                btn.textContent = f ? '已关注' : '关注';
                                btn.classList.toggle('following', f);
                            } catch (_) {}
                        };
                    }
                });
            }).catch(() => {});
        }

        if (moreBtn) {
            moreBtn.style.display = hasMore ? 'block' : 'none';
        }
    } catch (e) {
        if (first) {
            listEl.innerHTML = '<div class="empty-state">加载失败</div>';
        } else {
            app.showToast(e.message || '加载失败');
        }
    } finally {
        app._followListLoading = false;
    }
}

/**
 * 跳转独立粉丝/关注列表页
 * @param {string} type - 'following' | 'followers'
 */
export function goFollowListPage(app, type) {
    const username = app.viewingUsername || '';
    const base = (typeof window.HUBLOG_PAGE_BASE === 'string') ? window.HUBLOG_PAGE_BASE : '';
    let url;
    if (username) {
        url = `${base}/u/${encodeURIComponent(username)}/${type}`;
    } else {
        url = `${base}/${type}`;
    }
    location.assign(url);
}

/**
 * 拉黑 / 解除拉黑用户（切换）
 */
export async function toggleBlockUser(app, userId) {
    if (!app.checkAuth()) { app.openAuthModal(); return; }
    if (!userId) { app.showToast('用户信息缺失'); return; }
    try {
        const check = await app.request(`/follow/blocked-check?user_id=${userId}`);
        const blocked = !!check?.blocked;
        if (blocked) {
            await app.request('/follow/unblock', { method: 'POST', body: { user_id: userId } });
            app.showToast('已解除拉黑');
        } else {
            if (!window.confirm('确认将该用户加入黑名单？将自动解除双方关注关系。')) return;
            await app.request('/follow/block', { method: 'POST', body: { user_id: userId } });
            app.showToast('已加入黑名单');
            // 拉黑后刷新关注按钮为"关注"
            const btn = document.getElementById('btn-follow');
            if (btn) {
                btn.classList.remove('following');
                btn.textContent = '关注';
            }
            // 刷新粉丝数显示
            const followersEl = document.getElementById('stat-followers');
            if (followersEl) {
                const cur = parseInt(followersEl.textContent, 10) || 0;
                followersEl.textContent = Math.max(0, cur - 1);
            }
        }
        // 更新拉黑按钮文案
        const blockBtn = document.getElementById('btn-block-user');
        if (blockBtn) {
            const nowBlocked = !blocked;
            blockBtn.textContent = nowBlocked ? '已拉黑' : '拉黑';
            blockBtn.style.color = nowBlocked ? '#ff4d4f' : '#666';
        }
    } catch (e) {
        app.showToast(e.message || '操作失败');
    }
}

/**
 * 查看他人主页时回填拉黑状态
 */
export async function checkBlockStatus(app, userId) {
    const btn = document.getElementById('btn-block-user');
    if (!btn || !userId) return;
    try {
        const check = await app.request(`/follow/blocked-check?user_id=${userId}`);
        const blocked = !!check?.blocked;
        btn.textContent = blocked ? '已拉黑' : '拉黑';
        btn.style.color = blocked ? '#ff4d4f' : '#666';
    } catch (_) {
        // 忽略
    }
}

/**
 * 加载推荐关注用户（首页右栏卡片）
 */
export async function loadSuggestUsers(app) {
    const card = document.getElementById('suggest-card');
    const listEl = document.getElementById('suggest-list');
    if (!card || !listEl) return;
    try {
        const data = await app.request('/follow/suggest?limit=6');
        const list = data?.list || [];
        if (list.length === 0) {
            card.style.display = 'none';
            return;
        }
        card.style.display = '';
        listEl.innerHTML = list.map(u => {
            const nick = app.escapeHtml(u.nickname || u.username || '用户');
            const ava = u.avatar || 'assets/images/default-avatar.svg';
            const fc = u.follower_count ?? 0;
            const bio = app.escapeHtml(u.bio || '');
            return `
                <div class="suggest-item" data-uid="${u.id}" data-username="${app.escapeHtml(u.username || '')}" style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);">
                    <img src="${app.escapeHtml(ava)}" onerror="this.src='assets/images/default-avatar.svg'" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;" loading="lazy">
                    <div style="flex:1;min-width:0;cursor:pointer;" class="suggest-info">
                        <div style="font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${nick}${app.vipBadge(u.vip_level, 14)}</div>
                        <div style="font-size:11px;color:var(--text-secondary);">粉丝 ${fc}${bio ? ' · ' + bio : ''}</div>
                    </div>
                    <button class="btn-sm suggest-follow-btn" data-uid="${u.id}" style="flex-shrink:0;">关注</button>
                </div>
            `;
        }).join('');

        listEl.querySelectorAll('.suggest-item').forEach(item => {
            const infoEl = item.querySelector('.suggest-info');
            const username = item.dataset.username;
            if (infoEl && username) {
                infoEl.addEventListener('click', () => app.goUser(username));
            }
        });
        // 批量查询关注状态，消除 N+1
        const suggestBtns = listEl.querySelectorAll('.suggest-follow-btn');
        const suggestIds = [];
        suggestBtns.forEach(btn => {
            const uid = parseInt(btn.dataset.uid, 10);
            if (uid) suggestIds.push(uid);
        });

        if (app.authed === false) {
            // 游客：点击关注按钮弹出登录框
            // P0-3：登录态看 app.authed，不看 app.token
            suggestBtns.forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    app.openAuthModal();
                });
            });
        } else if (suggestIds.length > 0) {
            batchCheckFollowing(app, suggestIds).then(statusMap => {
                suggestBtns.forEach(btn => {
                    const uid = parseInt(btn.dataset.uid, 10);
                    const following = !!statusMap[uid];
                    btn.textContent = following ? '已关注' : '关注';
                    btn.classList.toggle('following', following);
                    btn.addEventListener('click', async (e) => {
                        e.stopPropagation();
                        await app.toggleFollow(uid);
                        try {
                            const r2 = await app.request(`/follow/check?user_id=${uid}`);
                            const f = !!r2?.following;
                            btn.textContent = f ? '已关注' : '关注';
                            btn.classList.toggle('following', f);
                        } catch (_) {}
                    });
                });
            }).catch(() => {});
        }
    } catch (_) {
        card.style.display = 'none';
    }
}
