/**
 * woquan 交互功能（点赞、评论、社交）
 */
import { BASE_URL } from './config.js?v=20260803.5';
import { formatText, renderMarkdown } from './utils.js?v=20261112.2';
import { cleanupOrphanUploads } from './publish.js?v=20260908.1';

/* ============================================================
   文章详情页内嵌评论框（与社交动态底部固定输入栏互斥）
   —— 判断条件刻意收窄到「post-detail 页且存在 #article-composer」，
      因此 moments 等其它主题 / 页面完全走原来的逻辑，行为不变。
   ============================================================ */
function isArticleComposerPage() {
    return document.body?.dataset.page === 'post-detail'
        && !!document.getElementById('article-composer');
}

/** 把就地回复的评论框移回评论区顶部的默认容器 */
function moveArticleComposerHome() {
    const composer = document.getElementById('article-composer');
    const home = document.getElementById('article-composer-home');
    if (composer && home && composer.parentElement !== home) home.appendChild(composer);
}

/** 复位评论框：取消回复态、清空草稿与图片、字数归零（不隐藏，内嵌常驻） */
function resetArticleComposer(app) {
    app.currentReplyCommentId = 0;
    moveArticleComposerHome();
    const input = document.getElementById('comment-input');
    if (input) {
        input.value = '';
        input.placeholder = '写下你的想法，与作者和其他读者交流…（Ctrl + Enter 发送）';
    }
    const tip = document.getElementById('article-reply-tip');
    if (tip) tip.hidden = true;
    const count = document.getElementById('article-comment-count');
    if (count) count.textContent = '0 / 500';
    if (typeof app.hideEmojiPanel === 'function') app.hideEmojiPanel();
    if (typeof app.clearCommentImage === 'function') app.clearCommentImage();
}

/** 打开（或就地展开）文章页评论框 */
function openArticleComposer(app, parentId) {
    const composer = document.getElementById('article-composer');
    const input = document.getElementById('comment-input');
    const home = document.getElementById('article-composer-home');
    if (!composer || !input) return;

    const item = parentId
        ? document.querySelector(`.comment-item[data-comment-id="${parentId}"]`)
        : null;
    const name = item?.querySelector('.comment-author')?.textContent?.trim() || '该评论';
    const tip = document.getElementById('article-reply-tip');
    const tipText = document.getElementById('article-reply-tip-text');

    if (parentId && item) {
        // 就地回复：输入框出现在被回复评论下方（知乎 / 掘金的常见交互）
        item.after(composer);
        if (tipText) tipText.textContent = '正在回复 @' + name;
        if (tip) tip.hidden = false;
        input.placeholder = '回复 @' + name + '…（Ctrl + Enter 发送）';
    } else {
        if (home) home.appendChild(composer);
        if (tip) tip.hidden = true;
        input.placeholder = '写下你的想法，与作者和其他读者交流…（Ctrl + Enter 发送）';
    }

    const avatar = document.getElementById('article-composer-avatar');
    if (avatar && app.user) {
        try { avatar.src = app.resolveAvatar(app.user.avatar); } catch (_) { /* 保留默认头像 */ }
    }
    setTimeout(() => {
        input.focus();
        try { composer.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (_) {}
    }, 30);
    initCommentMention(app);
}

export async function toggleLike(app, postId) {
    if (!app.checkAuth()) return;
    if (!app.canInteract('like')) {
        app.showToast('当前禁止点赞');
        return;
    }
    try {
        const data = await app.request('/posts/like', { method: 'POST', body: { post_id: postId } });
        const postEl = document.querySelector(`.moment-item[data-id="${postId}"]`);
        if (postEl) {
            const actionLike = postEl.querySelector('.moment-act-like');
            if (actionLike) {
                actionLike.classList.toggle('liked', !!data.liked);
                // 刷新文字按钮后的点赞数量
                const likeCount = typeof data.like_count === 'number' ? data.like_count : ((data.likes?.length) ?? 0);
                const countEl = actionLike.querySelector('.ma-count');
                if (likeCount > 0) {
                    const show = likeCount > 99 ? '99+' : String(likeCount);
                    if (countEl) countEl.textContent = show;
                    else actionLike.insertAdjacentHTML('beforeend', `<span class="ma-count">${show}</span>`);
                } else if (countEl) {
                    countEl.remove();
                }
            }
            app.updatePostSocial(postId, {
                likes: data.likes,
                like_count: data.like_count,
                comments: postEl.__comments || []
            });
        }
        app.showToast(data.liked ? '点赞成功' : '已取消点赞');
    } catch (e) {
        app.showToast(e.message);
    }
}

/**
 * 找到 el 的最近可滚动祖先（overflow:auto/scroll 且实际能滚动）。
 * 用于 PC 三栏布局下，window.scrollTo 失效时手动滚动内部容器。
 */
function findScrollableAncestor(el) {
    let node = el && el.parentElement;
    while (node && node !== document.body) {
        const style = getComputedStyle(node);
        const overflowY = style.overflowY;
        if ((overflowY === 'auto' || overflowY === 'scroll') &&
            node.scrollHeight > node.clientHeight) {
            return node;
        }
        node = node.parentElement;
    }
    return null;
}

export function openComment(app, postId, parentId = 0) {
    if (!app.checkAuth()) return;
    if (!app.canInteract('comment')) {
        app.showToast('当前禁止评论');
        return;
    }
    if (app.commentBlurTimeout) {
        clearTimeout(app.commentBlurTimeout);
        app.commentBlurTimeout = null;
    }

    app.currentCommentPostId = postId;
    app.currentReplyCommentId = parentId;

    // 文章详情页：使用评论区顶部内嵌的多行评论框，不再弹出社交动态那条固定底栏
    if (isArticleComposerPage()) {
        openArticleComposer(app, parentId);
        return;
    }

    const bar = document.getElementById('comment-input-bar');
    const input = document.getElementById('comment-input');
    const appContainer = document.querySelector('.app-container');

    if (bar) bar.classList.add('active');
    if (appContainer) appContainer.classList.add('comment-active');

    const postEl = document.querySelector(`.moment-item[data-id="${postId}"]`);
    if (postEl) {
        setTimeout(() => {
            // scrollIntoView 同时兼容：
            //  - 移动端（window 自身滚动）
            //  - PC 端（.main-content 是 overflow:auto 的内部滚动容器，
            //    window.scrollTo 在这里失效）
            // block:'center' 让动态居中，下方评论输入栏是 position:fixed
            // 不会真正遮挡，仍能完整看到动态内容。
            try {
                postEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (_) {
                // 老浏览器兜底：手动找到最近的可滚动祖先并计算位置
                const scrollRoot = findScrollableAncestor(postEl);
                if (scrollRoot) {
                    const rootRect = scrollRoot.getBoundingClientRect();
                    const postRect = postEl.getBoundingClientRect();
                    const targetTop = scrollRoot.scrollTop + (postRect.top - rootRect.top) - 60;
                    scrollRoot.scrollTo({ top: Math.max(0, targetTop), behavior: 'smooth' });
                }
            }
        }, 50);
    }

    if (input) {
        input.placeholder = parentId ? '回复评论...' : '写评论...';
        input.focus();
    }

    // 初始化评论 @mention 支持
    initCommentMention(app);
}

export function closeCommentBar(app) {
    // 文章页常驻评论框：「关闭」= 复位，而不是隐藏
    if (isArticleComposerPage()) {
        resetArticleComposer(app);
        hideCommentMentionPanel();
        return;
    }
    const bar = document.getElementById('comment-input-bar');
    const appContainer = document.querySelector('.app-container');
    if (bar) bar.classList.remove('active');
    if (appContainer) appContainer.classList.remove('comment-active');
    app.hideEmojiPanel();
    app.clearCommentImage();
    hideCommentMentionPanel();
}

/** 取消「回复某人」：保留已输入内容，仅回到一级评论状态 */
export function cancelArticleReply(app) {
    if (!isArticleComposerPage()) return;
    app.currentReplyCommentId = 0;
    moveArticleComposerHome();
    const tip = document.getElementById('article-reply-tip');
    if (tip) tip.hidden = true;
    const input = document.getElementById('comment-input');
    if (input) input.placeholder = '写下你的想法，与作者和其他读者交流…（Ctrl + Enter 发送）';
    input?.focus();
}
export async function submitComment(app) {
    // 并发守卫：防止快速连点发送按钮 / 连按回车时，两次 submitComment
    // 在 await 完成前都读到相同的 content，导致服务端收到重复请求并存两条相同的评论。
    if (app.submitting) return;
    if (!app.checkAuth()) return;
    if (!app.canInteract('comment')) {
        app.showToast('当前禁止评论');
        return;
    }
    if (app.commentBlurTimeout) {
        clearTimeout(app.commentBlurTimeout);
        app.commentBlurTimeout = null;
    }
    const input = document.getElementById('comment-input');
    if (!input) return;
    const content = input.value.trim();
    if (!content && !app.commentImageUrl) return;

    // 兜底：常驻评论框允许「打开页面 → 直接输入 → 点发布」，不经过 openComment()。
    // 正常情况下 init() 已写入 currentCommentPostId；这里再从页面数据推导一次，
    // 覆盖脚本延迟执行 / 页面复用 / 状态被复位等边界，避免发出空 post_id。
    let postId = app.currentCommentPostId;
    if (!postId && isArticleComposerPage()) {
        const cardId = document.querySelector('.moment-item[data-id]')?.dataset.id;
        postId = parseInt(document.body?.dataset.postId || '', 10)
            || parseInt(cardId || '', 10) || 0;
        if (postId) app.currentCommentPostId = postId;
    }
    if (!postId) {
        app.showToast('文章 ID 缺失，请刷新页面后重试');
        return;
    }

    app.submitting = true;
    const btnSend = document.getElementById('btn-send-comment');
    const originalBtnText = btnSend ? btnSend.textContent : '';
    if (btnSend) {
        btnSend.disabled = true;
        btnSend.textContent = '发送中...';
        btnSend.style.opacity = '0.6';
    }
    try {
        // 提取 @提及的用户名
        const mentions = [];
        const mentionRegex = /@([a-zA-Z0-9_]{3,20})/g;
        let mMatch;
        while ((mMatch = mentionRegex.exec(content)) !== null) {
            if (!mentions.includes(mMatch[1])) {
                mentions.push(mMatch[1]);
            }
        }

        const body = {
            post_id: postId,
            content: content,
            parent_id: app.currentReplyCommentId || undefined,
            mentions: mentions
        };
        if (app.commentImageUrl) {
            body.image = app.commentImageUrl;
        }
        const data = await app.request('/posts/comment', {
            method: 'POST',
            body
        });
        // 评论已成功：先把 commentImageUrl 置空再 closeCommentBar，
        // 避免 clearCommentImage 误把"已被评论绑定"的图片当作孤儿清理掉
        // （后端 comment 接口会把图片对应的 uploads.post_id 回写为所属动态，已非孤儿）
        app.commentImageUrl = '';
        input.value = '';
        const wasArticlePage = isArticleComposerPage();
        app.closeCommentBar();

        const postEl = document.querySelector(`.moment-item[data-id="${app.currentCommentPostId}"]`);
        if (postEl) {
            const comment = data.comment;
            if (comment) {
                postEl.__comments = postEl.__comments || [];
                postEl.__comments.push(comment);
                app.updatePostSocial(app.currentCommentPostId, { comments: postEl.__comments });
            }
        }
        app.showToast('评论成功');
        // 发布反馈：轻提示 + 滚回评论区让用户看到刚发出的评论
        if (wasArticlePage) {
            try {
                document.getElementById('article-comment-list')
                    ?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } catch (_) {}
        }
    } catch (e) {
        app.showToast(e.message);
    } finally {
        app.submitting = false;
        if (btnSend) {
            btnSend.disabled = false;
            btnSend.textContent = originalBtnText || '发送';
            btnSend.style.opacity = '';
        }
    }
}

export function buildSocialHtml(app, post) {
    const likeCount = post.like_count ?? (post.likes?.length || 0);
    const likesClickable = likeCount > 0 ? `onclick="event.stopPropagation(); app.openPostLikesModal(${post.id})"` : '';
    const likesHtml = likeCount > 0
        ? `<div class="likes-list" style="cursor:pointer;${likeCount > 0 ? '' : 'display:none;'}" ${likesClickable} title="查看全部点赞用户">${post.likes?.length > 0 ? post.likes.map((l, i) => `<span class="like-name" onclick="event.stopPropagation(); app.goUser('${app.escapeHtml(l.username)}')">${app.escapeHtml(l.nickname)}${i < post.likes.length - 1 ? '，' : ''}</span>`).join('') : `<span class="like-name-total" style="color:var(--text-secondary);">${likeCount} 人觉得很赞</span>`}</div>`
        : '';

    const commentsHtml = buildCommentTreeHtml(app, post);

    return (likesHtml || commentsHtml) ? `<div class="moment-social">${likesHtml}${commentsHtml}</div>` : '';
}

/**
 * 渲染评论为"楼中楼"（线程）结构：
 *  - 每条评论向上找到它的根评论；
 *  - 根评论平铺展示（level 0）；
 *  - 对根评论的所有回复（含"回复的回复"，统一缩进一次）挂在根评论下方（level 1 缩进）。
 * 视觉上有嵌套层次，而不是全部平铺 + "回复xxx"文字前缀。
 */
function buildCommentTreeHtml(app, post) {
    const comments = post.comments || [];
    if (!comments.length) return '';

    const byId = new Map(comments.map(c => [c.id, c]));
    const findRoot = (c) => {
        let cur = c, guard = 0;
        while (cur && cur.parent_id && cur.parent_id !== cur.id && byId.has(cur.parent_id) && guard < 10) {
            cur = byId.get(cur.parent_id);
            guard++;
        }
        return cur || c;
    };

    const roots = [];
    const repliesByRoot = {};
    comments.forEach(c => {
        const root = findRoot(c);
        if (c.id === root.id) {
            roots.push(c);
        } else {
            (repliesByRoot[root.id] = repliesByRoot[root.id] || []).push(c);
        }
    });

    return roots.map(root =>
        renderCommentItem(app, post, root, 0) +
        (repliesByRoot[root.id]?.length
            ? `<div class="comment-replies">${repliesByRoot[root.id].map(r => renderCommentItem(app, post, r, 1)).join('')}</div>`
            : '')
    ).join('');
}

// 渲染单条评论（isReply: 是否作为楼中楼回复缩进展示）
function renderCommentItem(app, post, c, isReply) {
    const parent = c.parent_id ? (post.comments.find(p => p.id === c.parent_id)?.user || null) : null;
    const replyTo = parent ? `<span class="comment-reply">回复${app.escapeHtml(parent.nickname || parent.username)}</span>: ` : '';
    const aiBadge = c.is_ai ? '<span class="ai-badge" title="AI 自动评论">AI</span>' : '';
    const authorName = c.is_ai ? 'AI评论家' : (c.user.nickname || c.user.username);
    // V71：评论作者会员徽章（AI 评论不发 badge；字段缺失时 helper 自动返回空串）
    const authorVip = c.is_ai ? '' : app.vipBadge(c.user && c.user.vip_level, 14);
    const authorClick = c.is_ai ? '' : `onclick="event.stopPropagation(); app.goUser('${app.escapeHtml(c.user.username)}')"`;
    const imageHtml = c.image ? `<span class="comment-image" onclick="event.stopPropagation(); app.viewImage('${app.escapeHtml(c.image)}', 0, ['${app.escapeHtml(c.image)}'])"><img src="${app.escapeHtml(c.image)}" alt="评论图片" loading="lazy"></span>` : '';
    const isSelfComment = app.user && c.user && app.user.id === c.user.id;
    const cLikeCount = typeof c.like_count === 'number' ? c.like_count : 0;
    const cLiked = !!c.liked;
    const commentLikeBtn = (!c.is_ai) ? `<span class="comment-action-btn comment-like-btn ${cLiked ? 'liked' : ''}" data-comment-id="${c.id}" data-liked="${cLiked ? '1' : '0'}" data-count="${cLikeCount}" onclick="event.stopPropagation(); app.toggleCommentLike(${c.id}, this);" title="点赞">
        <svg viewBox="0 0 24 24" width="12" height="12" fill="${cLiked ? 'currentColor' : 'none'}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
        <em>${cLikeCount > 0 ? cLikeCount : ''}</em>
    </span>` : '';
    const moreBtn = (!c.is_ai) ? `<button class="comment-more-btn" data-comment-id="${c.id}" data-is-self="${isSelfComment ? '1' : '0'}" onclick="event.stopPropagation(); toggleCommentMore(${c.id}, this, ${isSelfComment ? 'true' : 'false'})" aria-label="更多操作" title="更多">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
    </button>` : '';
    const levelClass = isReply ? ' comment-reply' : ' comment-top';
    return `<div class="comment-item${levelClass} ${c.is_ai ? 'comment-ai' : ''}" data-comment-id="${c.id}" onclick="app.openComment(${post.id}, ${c.id})" style="flex-wrap:wrap;">
        <span style="display:inline-flex;align-items:center;flex-wrap:wrap;width:100%;">
            <span style="flex:1;min-width:0;word-break:break-word;">${aiBadge}<span class="comment-author ${c.is_ai ? 'comment-author-ai' : ''}" ${authorClick}>${app.escapeHtml(authorName)}</span>${authorVip}: ${replyTo}${renderMarkdown(c.content, BASE_URL)}${imageHtml}</span>
            <span style="flex-shrink:0;display:inline-flex;align-items:center;margin-top:2px;margin-left:10px;gap:4px;">${commentLikeBtn}${moreBtn}</span>
        </span>
    </div>`;
}

/**
 * 打开动态点赞用户列表弹窗
 */
export async function openPostLikesModal(app, postId) {
    let modal = document.getElementById('post-likes-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'post-likes-modal';
        modal.className = 'modal active';
        modal.innerHTML = `
            <div class="modal-content" style="max-width:480px;">
                <div class="modal-header">
                    <h3 id="pl-title">点赞用户</h3>
                    <button class="modal-close" onclick="document.getElementById('post-likes-modal').classList.remove('active')">&times;</button>
                </div>
                <div class="modal-body" id="pl-body" style="max-height:65vh;overflow-y:auto;padding:8px 16px;"></div>
                <div class="modal-footer" style="display:none;padding:8px 16px 16px;">
                    <button class="btn btn-default btn-block" id="pl-more">加载更多</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }
    modal.classList.add('active');
    const body = document.getElementById('pl-body');
    const moreBtn = document.getElementById('pl-more');
    body.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-secondary);">加载中...</div>';
    app._plPage = 1;
    app._plHasMore = true;

    const loadMore = async () => {
        if (!app._plHasMore) return;
        try {
            const data = await app.request(`/posts/likes?post_id=${postId}&page=${app._plPage}&limit=20`);
            const list = data?.list || [];
            const pages = data?.pagination?.pages || 0;
            app._plHasMore = app._plPage < pages;
            if (app._plPage === 1) body.innerHTML = '';
            if (list.length === 0 && app._plPage === 1) {
                body.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-secondary);">暂无点赞用户</div>';
                if (moreBtn) moreBtn.parentElement.style.display = 'none';
                return;
            }
            const base = (typeof window.HUBLOG_PAGE_BASE === 'string') ? window.HUBLOG_PAGE_BASE : '';
            const selfId = app.user?.id || 0;
            const frag = document.createDocumentFragment();
            const ids = [];
            list.forEach(u => {
                const uId = u.id || 0;
                const isSelf = selfId && uId === selfId;
                const nick = app.escapeHtml(u.nickname || u.username || '用户');
                const ava = u.avatar || 'assets/images/default-avatar.svg';
                const bio = app.escapeHtml(u.bio || '');
                const fc = u.follower_count ?? 0;
                const pc = u.post_count ?? 0;
                const row = document.createElement('div');
                row.className = 'user-search-item';
                row.style.cursor = 'pointer';
                row.style.padding = '10px 0';
                row.innerHTML = `
                    <img class="user-search-avatar" src="${app.escapeHtml(ava)}" onerror="this.src='assets/images/default-avatar.svg'" loading="lazy">
                    <div class="user-search-info">
                        <div class="user-search-name-row">
                            <span class="user-search-name">${nick}</span>
                            ${u.username ? `<span class="user-search-username">@${app.escapeHtml(u.username)}</span>` : ''}
                            ${isSelf ? '<span class="self-badge">自己</span>' : ''}
                        </div>
                        ${bio ? `<div class="user-search-bio">${bio}</div>` : ''}
                        <div class="user-search-stats">粉丝 ${fc} · 动态 ${pc}</div>
                    </div>
                    <div class="user-search-actions" style="flex-shrink:0;">
                        ${isSelf ? '' : `<button class="btn-sm pl-follow-check" data-uid="${uId}" style="display:none;">关注</button>`}
                    </div>
                `;
                row.onclick = () => { if (u.username) app.goUser(u.username); };
                if (!isSelf) ids.push(uId);
                frag.appendChild(row);
            });
            body.appendChild(frag);
            // 批量查询关注状态，消除 N+1
            if (ids.length) {
                (async () => {
                    try {
                        const statusMap = await app.request('/follow/batch-check', {
                            method: 'POST',
                            body: { user_ids: ids }
                        });
                        ids.forEach(uid => {
                            const btn = body.querySelector(`.pl-follow-check[data-uid="${uid}"]`);
                            if (btn) {
                                const following = !!statusMap?.[uid];
                                btn.style.display = 'inline-flex';
                                btn.textContent = following ? '已关注' : '关注';
                                btn.classList.toggle('following', following);
                                btn.onclick = async (e) => {
                                    e.stopPropagation();
                                    await app.toggleFollow(uid);
                                    try {
                                        const r2 = await app.request(`/follow/check?user_id=${uid}`);
                                        const f = !!r2?.following;
                                        btn.textContent = f ? '已关注' : '关注';
                                        btn.classList.toggle('following', f);
                                    } catch (_) {}
                                };
                            }
                        });
                    } catch (_) {}
                })();
            }
            if (moreBtn) moreBtn.parentElement.style.display = app._plHasMore ? '' : 'none';
            app._plPage++;
        } catch (e) {
            app.showToast(e.message || '加载失败');
            if (app._plPage === 1) body.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-secondary);">加载失败</div>';
        }
    };
    if (moreBtn) moreBtn.onclick = () => loadMore();
    await loadMore();
}

/**
 * 评论点赞 / 取消点赞
 */
export async function toggleCommentLike(app, commentId, btnEl) {
    if (!app.checkAuth()) { app.openAuthModal(); return; }
    if (!app.canInteract('like')) { app.showToast('当前禁止点赞'); return; }
    try {
        const data = await app.request('/posts/comment-like', {
            method: 'POST',
            body: { comment_id: commentId }
        });
        if (data?.unsupported) {
            app.showToast('系统版本过旧，暂不支持评论点赞');
            return;
        }
        const liked = !!data?.liked;
        const count = data?.like_count || 0;
        if (btnEl) {
            btnEl.dataset.liked = liked ? '1' : '0';
            btnEl.classList.toggle('liked', liked);
            const svg = btnEl.querySelector('svg');
            if (svg) svg.setAttribute('fill', liked ? 'currentColor' : 'none');
            const em = btnEl.querySelector('em');
            if (em) em.textContent = count > 0 ? String(count) : '';
        }
    } catch (e) {
        app.showToast(e.message);
    }
}

export function updatePostSocial(app, postId, postData) {
    const postEl = document.querySelector(`.moment-item[data-id="${postId}"]`);
    if (!postEl) return;

    const comments = postData.comments || postEl.__comments || [];
    if (Array.isArray(postData.comments)) {
        postEl.__comments = postData.comments;
    }

    // 1. 更新动态下方文字按钮的评论数量
    const commentBtn = postEl.querySelector('.moment-act-comment');
    if (commentBtn) {
        const commentCount = typeof postData.comment_count === 'number' ? postData.comment_count : comments.length;
        const countEl = commentBtn.querySelector('.ma-count');
        if (commentCount > 0) {
            const show = commentCount > 99 ? '99+' : String(commentCount);
            if (countEl) countEl.textContent = show;
            else commentBtn.insertAdjacentHTML('beforeend', `<span class="ma-count">${show}</span>`);
        } else if (countEl) {
            countEl.remove();
        }
    }

    // 2. 同步刷新动态下方文字按钮的点赞数量（外部调用 updatePostSocial 时 like_count 可能发生变化）
    if (typeof postData.like_count === 'number' || postData.likes) {
        const likeBtn = postEl.querySelector('.moment-act-like');
        if (likeBtn) {
            const likeCount = typeof postData.like_count === 'number' ? postData.like_count : (postData.likes?.length ?? 0);
            const countEl = likeBtn.querySelector('.ma-count');
            if (likeCount > 0) {
                const show = likeCount > 99 ? '99+' : String(likeCount);
                if (countEl) countEl.textContent = show;
                else likeBtn.insertAdjacentHTML('beforeend', `<span class="ma-count">${show}</span>`);
            } else if (countEl) {
                countEl.remove();
            }
        }
    }

    // 3. 更新卡片下方点赞/评论社交区
    // 文章详情页把 .moment-social 整体搬进了 #article-comment-list，
    // 若仍用 postEl.querySelector 会找不到，进而在文章卡片里另起一块评论盒，
    // 导致「评论发出去了但评论区没变」。
    const article = isArticleComposerPage();
    if (article) moveArticleComposerHome();
    const host = article
        ? (document.getElementById('article-comment-list') || postEl.querySelector('.moment-content'))
        : postEl.querySelector('.moment-content');
    const social = article
        ? (document.querySelector('#article-comment-list .moment-social') || postEl.querySelector('.moment-social'))
        : postEl.querySelector('.moment-social');
    const socialHtml = app.buildSocialHtml({
        id: postId,
        likes: postData.likes || [],
        comments: comments,
        like_count: postData.like_count ?? 0,
        comment_count: comments.length
    });

    if (article && socialHtml) {
        document.querySelectorAll('#article-comment-list .comments-empty')
            .forEach(el => el.remove());
    }
    if (social) {
        social.outerHTML = socialHtml;
    } else if (socialHtml && host) {
        host.insertAdjacentHTML('beforeend', socialHtml);
    }
    if (article) {
        const title = document.getElementById('article-comments-title');
        if (title) title.textContent = '评论 · ' + comments.length;
    }
}

/**
 * 分享当前动态：弹出完整分享面板，支持多平台分享
 */
export async function sharePost(app, postId) {
    try {
        const postEl = document.querySelector(`.moment-item[data-id="${postId}"]`);
        const shareUrl = `${BASE_URL}/p/${postId}`;
        let shareTitle = document.title || '';
        let shareDesc = '';
        if (postEl) {
            const titleEl = postEl.querySelector('.moment-title');
            const textEl = postEl.querySelector('.moment-text');
            if (titleEl && titleEl.textContent.trim()) shareTitle = titleEl.textContent.trim();
            if (textEl) shareDesc = textEl.textContent.trim().slice(0, 80);
        }
        if (!shareTitle) shareTitle = 'woquan 动态';

        const panel = getOrCreateSharePanel(app);
        panel.dataset.url = shareUrl;
        panel.dataset.title = shareTitle;
        panel.dataset.desc = shareDesc;

        updateSharePanelContent(panel, app);
        panel.classList.add('active');
        panel.setAttribute('aria-hidden', 'false');
    } catch (e) {
        app.showToast('打开分享面板失败：' + e.message);
    }
}

function getOrCreateSharePanel(app) {
    let panel = document.getElementById('share-modal');
    if (panel) return panel;

    panel = document.createElement('div');
    panel.id = 'share-modal';
    panel.className = 'modal-overlay';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-hidden', 'true');
    panel.setAttribute('aria-labelledby', 'share-modal-title');
    panel.innerHTML = `
        <div class="modal-content share-modal" style="max-width:420px;">
            <div class="modal-header">
                <h3 id="share-modal-title">分享到</h3>
                <button class="modal-close" aria-label="关闭" onclick="document.getElementById('share-modal').classList.remove('active')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="share-preview">
                    <div class="share-preview-title" id="share-preview-title"></div>
                    <div class="share-preview-desc" id="share-preview-desc"></div>
                    <div class="share-preview-url" id="share-preview-url"></div>
                </div>
                <div class="share-grid">
                    <button class="share-item" data-platform="wechat" title="微信">
                        <div class="share-icon" style="background:#07C160;">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="#fff"><path d="M8.691 2.188C3.891 2.188 0 5.476 0 9.53c0 2.212 1.17 4.203 3.002 5.55a.59.59 0 0 1 .213.665l-.39 1.48c-.019.07-.048.141-.048.213 0 .163.13.295.29.295a.326.326 0 0 0 .167-.054l1.903-1.114a.864.864 0 0 1 .717-.098 10.16 10.16 0 0 0 2.837.403c.276 0 .543-.027.811-.05-.857-2.578.157-4.972 1.932-6.446 1.703-1.415 3.882-1.98 5.853-1.838-.576-3.583-4.196-6.348-8.596-6.348zM5.785 5.991c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178A1.17 1.17 0 0 1 4.623 7.17c0-.651.52-1.18 1.162-1.18zm5.813 0c.642 0 1.162.529 1.162 1.18a1.17 1.17 0 0 1-1.162 1.178 1.17 1.17 0 0 1-1.162-1.178c0-.651.52-1.18 1.162-1.18zm5.34 2.867c-1.797-.052-3.746.512-5.28 1.786-1.72 1.428-2.687 3.72-1.78 6.22.942 2.453 3.666 4.229 6.884 4.229.826 0 1.622-.12 2.361-.336a.722.722 0 0 1 .598.082l1.584.926a.272.272 0 0 0 .14.047c.134 0 .24-.111.24-.247 0-.06-.023-.12-.038-.177l-.327-1.233a.582.582 0 0 1-.023-.156.49.49 0 0 1 .201-.398C23.024 18.48 24 16.82 24 14.98c0-3.21-2.931-5.837-6.656-6.088V8.89c-.135-.01-.27-.027-.407-.033zm-2.53 3.274c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.969-.982zm4.844 0c.535 0 .969.44.969.982a.976.976 0 0 1-.969.983.976.976 0 0 1-.969-.983c0-.542.434-.982.969-.982z"/></svg>
                        </div>
                        <span>微信</span>
                    </button>
                    <button class="share-item" data-platform="weibo" title="微博">
                        <div class="share-icon" style="background:#E6162D;">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="#fff"><path d="M10.5 20.5c-4.61 0-8.5-2.79-8.5-6.5S5.89 7.5 10.5 7.5s8.5 2.79 8.5 6.5-3.89 6.5-8.5 6.5zm0-12c-3.58 0-6.5 2.18-6.5 5s2.92 5 6.5 5 6.5-2.18 6.5-5-2.92-5-6.5-5zm-1 8.5c-1.93 0-3.5-1.12-3.5-2.5S7.57 12 9.5 12s3.5 1.12 3.5 2.5-1.57 2.5-3.5 2.5zm0-5c-.83 0-1.5.45-1.5 1s.67 1 1.5 1 1.5-.45 1.5-1-.67-1-1.5-1zM19.5 8.5c-.28 0-.5-.22-.5-.5 0-2.49-2.01-4.5-4.5-4.5-.28 0-.5-.22-.5-.5s.22-.5.5-.5c3.04 0 5.5 2.46 5.5 5.5 0 .28-.22.5-.5.5zm3 2.5c-.55 0-1-.45-1-1 0-3.58-2.92-6.5-6.5-6.5-.55 0-1-.45-1-1s.45-1 1-1c4.69 0 8.5 3.81 8.5 8.5 0 .55-.45 1-1 1z"/></svg>
                        </div>
                        <span>微博</span>
                    </button>
                    <button class="share-item" data-platform="qq" title="QQ">
                        <div class="share-icon" style="background:#12B7F5;">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="#fff"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.5 12.5c-.55 0-1-.45-1-1v-2c0-.55.45-1 1-1s1 .45 1 1v2c0 .55-.45 1-1 1zm-9 0c-.55 0-1-.45-1-1v-2c0-.55.45-1 1-1s1 .45 1 1v2c0 .55-.45 1-1 1zm2.5 1c-.83 0-1.5-.67-1.5-1.5S9.17 12 10 12s1.5.67 1.5 1.5S10.83 15.5 10 15.5zm4 0c-.83 0-1.5-.67-1.5-1.5S13.17 12 14 12s1.5.67 1.5 1.5S14.83 15.5 14 15.5zm-2-8c-2.76 0-5 2.24-5 5 0 .55.45 1 1 1h8c.55 0 1-.45 1-1 0-2.76-2.24-5-5-5z"/></svg>
                        </div>
                        <span>QQ</span>
                    </button>
                    <button class="share-item" data-platform="twitter" title="Twitter/X">
                        <div class="share-icon" style="background:#1a1a1a;">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="#fff"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                        </div>
                        <span>X / Twitter</span>
                    </button>
                    <button class="share-item" data-platform="facebook" title="Facebook">
                        <div class="share-icon" style="background:#1877F2;">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="#fff"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        </div>
                        <span>Facebook</span>
                    </button>
                    <button class="share-item" data-platform="reddit" title="Reddit">
                        <div class="share-icon" style="background:#FF4500;">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="#fff"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm5.5 11c0 .83-.67 1.5-1.5 1.5-.4 0-.77-.16-1.04-.42-1.52 1.01-3.56 1.56-5.74 1.56-.91 0-1.78-.11-2.57-.3-.2.42-.63.72-1.13.72-.69 0-1.25-.56-1.25-1.25 0-.69.56-1.25 1.25-1.25.26 0 .5.08.7.22.77-.6 1.86-1 3.04-1 .69 0 1.35.07 1.98.2.17-.13.38-.2.6-.2.83 0 1.5.67 1.5 1.5zM8.5 9c-.83 0-1.5-.67-1.5-1.5S7.67 6 8.5 6s1.5.67 1.5 1.5S9.33 9 8.5 9zm7 0c-.83 0-1.5-.67-1.5-1.5S14.67 6 15.5 6s1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>
                        </div>
                        <span>Reddit</span>
                    </button>
                    <button class="share-item" data-platform="linkedin" title="LinkedIn">
                        <div class="share-icon" style="background:#0A66C2;">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="#fff"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                        </div>
                        <span>LinkedIn</span>
                    </button>
                    <button class="share-item" data-platform="email" title="邮件">
                        <div class="share-icon" style="background:#6366F1;">
                            <svg viewBox="0 0 24 24" width="24" height="24" fill="#fff"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>
                        </div>
                        <span>邮件</span>
                    </button>
                </div>
                <div class="share-actions">
                    <button class="share-action-btn" data-action="copy-link">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                        <span>复制链接</span>
                    </button>
                    <button class="share-action-btn" data-action="copy-text">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        <span>复制文本</span>
                    </button>
                    <button class="share-action-btn" data-action="native" id="share-native-btn" style="display:none;">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>
                        <span>系统分享</span>
                    </button>
                </div>
                <div class="share-tip" id="share-tip"></div>
            </div>
        </div>
    `;
    document.body.appendChild(panel);

    panel.addEventListener('click', (e) => {
        if (e.target === panel) {
            panel.classList.remove('active');
            panel.setAttribute('aria-hidden', 'true');
        }
        // 关闭按钮也同步 aria-hidden
        if (e.target.closest('.modal-close')) {
            panel.setAttribute('aria-hidden', 'true');
        }
        const item = e.target.closest('.share-item');
        if (item) {
            const platform = item.dataset.platform;
            handleSharePlatform(panel, platform, app);
        }
        const actionBtn = e.target.closest('.share-action-btn');
        if (actionBtn) {
            const action = actionBtn.dataset.action;
            handleShareAction(panel, action, app);
        }
    });

    return panel;
}

function updateSharePanelContent(panel, app) {
    const url = panel.dataset.url || '';
    const title = panel.dataset.title || '';
    const desc = panel.dataset.desc || '';

    document.getElementById('share-preview-title').textContent = title;
    document.getElementById('share-preview-desc').textContent = desc;
    document.getElementById('share-preview-url').textContent = url;

    const nativeBtn = document.getElementById('share-native-btn');
    if (nativeBtn && navigator.share) {
        nativeBtn.style.display = 'inline-flex';
    }
}

function encodeURI(val) {
    return encodeURIComponent(val);
}

function handleSharePlatform(panel, platform, app) {
    const url = panel.dataset.url || '';
    const title = panel.dataset.title || '';
    const desc = panel.dataset.desc || '';
    const fullText = `${title}\n${desc}\n${url}`;

    const openShareWindow = (shareUrl) => {
        const w = 600, h = 500;
        const left = (screen.width / 2) - (w / 2);
        const top = (screen.height / 2) - (h / 2);
        window.open(shareUrl, 'share_window', `width=${w},height=${h},left=${left},top=${top}`);
    };

    switch (platform) {
        case 'wechat':
            app.showToast('请使用微信扫一扫或手动复制链接分享');
            copyToClipboard(url, app).then(() => {
                document.getElementById('share-tip').textContent = '链接已复制，快去微信粘贴分享吧！';
                setTimeout(() => { document.getElementById('share-tip').textContent = ''; }, 3000);
            });
            break;
        case 'weibo':
            openShareWindow(`https://service.weibo.com/share/share.php?url=${encodeURI(url)}&title=${encodeURI(title)}`);
            break;
        case 'qq':
            openShareWindow(`https://connect.qq.com/widget/shareqq/index.html?url=${encodeURI(url)}&title=${encodeURI(title)}&desc=${encodeURI(desc)}`);
            break;
        case 'twitter':
            openShareWindow(`https://twitter.com/intent/tweet?url=${encodeURI(url)}&text=${encodeURI(title)}`);
            break;
        case 'facebook':
            openShareWindow(`https://www.facebook.com/sharer/sharer.php?u=${encodeURI(url)}&quote=${encodeURI(title)}`);
            break;
        case 'reddit':
            openShareWindow(`https://www.reddit.com/submit?url=${encodeURI(url)}&title=${encodeURI(title)}`);
            break;
        case 'linkedin':
            openShareWindow(`https://www.linkedin.com/sharing/share-offsite/?url=${encodeURI(url)}`);
            break;
        case 'email':
            window.location.href = `mailto:?subject=${encodeURI(title)}&body=${encodeURI(fullText)}`;
            break;
    }
}

function handleShareAction(panel, action, app) {
    const url = panel.dataset.url || '';
    const title = panel.dataset.title || '';
    const desc = panel.dataset.desc || '';
    const fullText = `${title}\n${desc}\n${url}`;

    switch (action) {
        case 'copy-link':
            copyToClipboard(url, app).then(() => app.showToast('链接已复制到剪贴板'));
            break;
        case 'copy-text':
            copyToClipboard(fullText, app).then(() => app.showToast('文本已复制到剪贴板'));
            break;
        case 'native':
            if (navigator.share) {
                navigator.share({ title, text: desc, url }).catch(() => {});
            }
            break;
    }
}

async function copyToClipboard(text, app) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        return true;
    } catch (e) {
        app.showToast('复制失败，请手动复制');
        return false;
    }
}

export function toggleActionMenu(app, postId, btn) {
    const menu = document.getElementById(`action-menu-${postId}`);
    if (!menu) return;
    const isActive = menu.classList.contains('active');
    app.hideAllActionMenus();
    if (!isActive) {
        menu.classList.add('active');
        const rect = btn.getBoundingClientRect();
        const menuRect = menu.getBoundingClientRect();
        menu.style.top = (rect.top - menuRect.height / 2 + rect.height / 2) + 'px';
        const minEdge = 8;
        // 默认让菜单出现在按钮左侧，小三角指向按钮
        let right = window.innerWidth - rect.left + 8;
        let left = window.innerWidth - right - menuRect.width;
        menu.classList.remove('menu-right');
        if (left < minEdge) {
            // 左侧空间不够，切换到按钮右侧并反转小三角
            menu.classList.add('menu-right');
            right = window.innerWidth - rect.right - 8 - menuRect.width;
            if (right < minEdge) right = minEdge;
        }
        menu.style.right = right + 'px';
    }
}

export function hideActionMenu(app, postId) {
    const menu = document.getElementById(`action-menu-${postId}`);
    if (menu) menu.classList.remove('active');
}

export function hideAllActionMenus(app) {
    document.querySelectorAll('.action-menu.active').forEach(m => m.classList.remove('active'));
}

export function toggleCommentMore(commentId, btn, isSelf) {
    const existing = document.getElementById(`comment-more-${commentId}`);
    const allMenus = document.querySelectorAll('.comment-more-menu.active');
    const isSame = existing && existing.classList.contains('active') && (existing.dataset.self === String(!!isSelf));

    allMenus.forEach(m => m.classList.remove('active'));

    if (isSame) return;

    let menu = existing;
    if (!menu || menu.dataset.self !== String(!!isSelf)) {
        if (menu) menu.remove();
        menu = document.createElement('div');
        menu.id = `comment-more-${commentId}`;
        menu.className = 'comment-more-menu';
        menu.dataset.self = String(!!isSelf);

        if (isSelf) {
            menu.innerHTML = `
                <button class="comment-more-item danger" data-action="delete">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-2 14a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/></svg>
                    <span>删除评论</span>
                </button>
            `;
        } else {
            menu.innerHTML = `
                <button class="comment-more-item" data-action="report">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
                    <span>举报</span>
                </button>
            `;
        }
        document.body.appendChild(menu);

        menu.addEventListener('click', (e) => {
            const item = e.target.closest('.comment-more-item');
            if (!item) return;
            const action = item.dataset.action;
            menu.classList.remove('active');
            if (action === 'report') {
                const app = window.app;
                if (app && app.checkAuth()) {
                    app.openReportModal('comment', commentId);
                } else if (app) {
                    app.openAuthModal();
                }
            } else if (action === 'delete') {
                deleteCommentFromDom(commentId, menu);
            }
        });
    }

    menu.classList.add('active');
    const rect = btn.getBoundingClientRect();
    const menuRect = menu.getBoundingClientRect();
    let left = rect.right - menuRect.width + 12;
    let top = rect.bottom + 6;
    if (left < 8) left = 8;
    if (left + menuRect.width > window.innerWidth - 8) {
        left = window.innerWidth - menuRect.width - 8;
    }
    if (top + menuRect.height > window.innerHeight - 8) {
        top = rect.top - menuRect.height - 6;
    }
    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
}

async function deleteCommentFromDom(commentId, menu) {
    const app = window.app;
    if (!app) return;

    if (!confirm('确定要删除这条评论吗？删除后不可恢复。')) return;

    try {
        app.showToast('删除中...');
        const res = await app.request(`/posts/comment?id=${commentId}`, {
            method: 'DELETE'
        });
        const commentEl = document.querySelector(`.comment-item[data-comment-id="${commentId}"]`);
        if (commentEl) {
            const socialEl = commentEl.closest('.moment-social');
            const postEl = commentEl.closest('.moment-item');
            const commentCountEl = postEl ? postEl.querySelector('.comment-count') : null;

            commentEl.style.transition = 'opacity 0.2s, height 0.2s';
            commentEl.style.opacity = '0';
            commentEl.style.overflow = 'hidden';
            commentEl.style.height = commentEl.offsetHeight + 'px';
            setTimeout(() => {
                commentEl.style.height = '0';
                commentEl.style.paddingTop = '0';
                commentEl.style.paddingBottom = '0';
                setTimeout(() => {
                    commentEl.remove();
                    if (socialEl && !socialEl.children.length) {
                        socialEl.remove();
                    }
                    if (commentCountEl) {
                        const current = parseInt(commentCountEl.textContent, 10);
                        if (!isNaN(current) && current > 0) {
                            commentCountEl.textContent = current - 1;
                        }
                    }
                }, 200);
            }, 10);
        }
        app.showToast(res?.message || '删除成功');
    } catch (e) {
        app.showToast(e.message || '删除失败');
    }
}

document.addEventListener('click', (e) => {
    if (e.target.closest('.comment-more-menu') || e.target.closest('.comment-more-btn')) return;
    document.querySelectorAll('.comment-more-menu.active').forEach(m => m.classList.remove('active'));
});

export function clearCommentImage(app) {
    const url = app.commentImageUrl;
    // 先清空本地状态（避免 UI 与变量不一致）
    app.commentImageUrl = '';
    const preview = document.getElementById('comment-image-preview');
    if (preview) preview.innerHTML = '';
    const input = document.getElementById('comment-image-upload');
    if (input) input.value = '';
    // 若图片未提交就清除（取消发送、点 × 按钮、关闭评论栏），通知后端删除孤儿文件
    if (url) {
        // fire-and-forget：与 publish.js 取消发布时的清理保持一致，静默失败
        cleanupOrphanUploads(app, [url]);
    }
}

export async function handleCommentImage(app, file) {
    if (!file) {
        app.isSelectingCommentImage = false;
        return;
    }
    const preview = document.getElementById('comment-image-preview');
    const input = document.getElementById('comment-input');
    if (!preview) {
        app.isSelectingCommentImage = false;
        return;
    }
    try {
        const url = await app.uploadImage(file);
        app.commentImageUrl = url;
        preview.innerHTML = `
            <div class="comment-image-preview-item">
                <img src="${app.escapeHtml(url)}" alt="评论图片">
                <span class="comment-image-remove" onclick="app.clearCommentImage()">×</span>
            </div>
        `;
        if (input) input.focus();
    } catch (e) {
        app.showToast(e.message);
    } finally {
        app.isSelectingCommentImage = false;
    }
}

// ========== 评论 @mention 支持 ==========

let commentMentionState = {
    searchTimer: null,
    lastKeyword: '',
    atIndex: -1,
    bound: false
};

/**
 * 初始化评论输入框的 @mention 支持（仅绑定一次）
 */
export function initCommentMention(app) {
    const input = document.getElementById('comment-input');
    if (!input || commentMentionState.bound) return;
    commentMentionState.bound = true;

    input.addEventListener('input', () => handleCommentMentionInput(app));
    input.addEventListener('keydown', (e) => handleCommentMentionKeydown(app, e));
    input.addEventListener('blur', () => {
        // 延迟关闭以允许点击 mention 项
        setTimeout(() => hideCommentMentionPanel(), 200);
    });
}

/**
 * 处理评论输入框中的 @ 符号，触发用户搜索
 */
function handleCommentMentionInput(app) {
    const input = document.getElementById('comment-input');
    if (!input) return;

    const cursorPos = input.selectionStart;
    const text = input.value.substring(0, cursorPos);

    const atIndex = text.lastIndexOf('@');
    if (atIndex === -1 || (atIndex > 0 && !/\s/.test(text[atIndex - 1]) && text[atIndex - 1] !== '\n')) {
        hideCommentMentionPanel();
        return;
    }

    const keyword = text.substring(atIndex + 1);
    if (keyword.includes(' ') || keyword.includes('\n')) {
        hideCommentMentionPanel();
        return;
    }

    const cfg = app.siteConfig || {};
    if (cfg.allow_mention === false) return;

    if (commentMentionState.searchTimer) {
        clearTimeout(commentMentionState.searchTimer);
    }
    if (keyword === commentMentionState.lastKeyword) return;

    commentMentionState.searchTimer = setTimeout(() => {
        searchCommentMentionUsers(app, keyword, atIndex);
    }, 200);
}

/**
 * 搜索用户用于评论 @提及
 */
async function searchCommentMentionUsers(app, keyword, atIndex) {
    if (!keyword || keyword.length < 1) {
        hideCommentMentionPanel();
        return;
    }
    commentMentionState.lastKeyword = keyword;
    commentMentionState.atIndex = atIndex;

    try {
        const res = await app.request('/user/search?keyword=' + encodeURIComponent(keyword) + '&limit=10');
        const users = Array.isArray(res) ? res : (res?.list || []);
        if (!users || users.length === 0) {
            hideCommentMentionPanel();
            return;
        }
        showCommentMentionPanel(app, users, atIndex);
    } catch (e) {
        hideCommentMentionPanel();
    }
}

/**
 * 显示评论 @提及用户选择面板
 */
function showCommentMentionPanel(app, users, atIndex) {
    let panel = document.getElementById('comment-mention-panel');
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'comment-mention-panel';
        panel.className = 'mention-panel';
        panel.style.cssText = 'position:fixed;bottom:60px;left:0;right:0;max-height:240px;overflow-y:auto;background:var(--card-bg,#fff);border-top:1px solid var(--border,#e5e5e5);box-shadow:0 -2px 8px rgba(0,0,0,0.1);z-index:1000;display:none;';
        panel.innerHTML = '<div class="mention-list" id="comment-mention-list"></div>';
        document.body.appendChild(panel);
    }

    const list = panel.querySelector('#comment-mention-list');
    if (!list) return;

    list.innerHTML = users.map(user => `
        <div class="mention-item" data-username="${app.escapeHtml(user.username)}" data-nickname="${app.escapeHtml(user.nickname || user.username)}" style="display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border,#f0f0f0);">
            <img src="${app.escapeAttr(app.safeUrl(user.avatar) || 'assets/images/default-avatar.svg')}" style="width:28px;height:28px;border-radius:50%;" onerror="this.src='assets/images/default-avatar.svg'">
            <div style="flex:1;min-width:0;">
                <div style="font-size:14px;color:var(--text,#333);">${app.escapeHtml(user.nickname || user.username)}</div>
                <div style="font-size:12px;color:var(--text-secondary,#999);">@${app.escapeHtml(user.username)}</div>
            </div>
        </div>
    `).join('');

    list.querySelectorAll('.mention-item').forEach(item => {
        item.addEventListener('click', () => {
            const username = item.dataset.username;
            insertCommentMention(app, username, atIndex);
        });
    });

    panel.style.display = 'block';
}

/**
 * 隐藏评论 @提及面板
 */
function hideCommentMentionPanel() {
    const panel = document.getElementById('comment-mention-panel');
    if (panel) panel.style.display = 'none';
    commentMentionState.lastKeyword = '';
}

/**
 * 在评论输入框光标处插入 @提及
 */
function insertCommentMention(app, username, atIndex) {
    const input = document.getElementById('comment-input');
    if (!input) return;

    const cursorPos = input.selectionStart;
    const text = input.value;
    const beforeAt = text.substring(0, atIndex);
    const afterCursor = text.substring(cursorPos);

    const mentionText = `@${username} `;
    input.value = beforeAt + mentionText + afterCursor;

    const newPos = atIndex + mentionText.length;
    input.setSelectionRange(newPos, newPos);
    input.focus();

    hideCommentMentionPanel();
}

/**
 * 处理评论 @提及面板的键盘导航
 */
export function handleCommentMentionKeydown(app, e) {
    const panel = document.getElementById('comment-mention-panel');
    if (!panel || panel.style.display === 'none') return;

    const items = panel.querySelectorAll('.mention-item');
    if (items.length === 0) return;

    let selectedIndex = -1;
    items.forEach((item, idx) => {
        if (item.classList.contains('selected')) selectedIndex = idx;
    });

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = selectedIndex < items.length - 1 ? selectedIndex + 1 : 0;
        items.forEach((item, idx) => item.classList.toggle('selected', idx === selectedIndex));
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = selectedIndex > 0 ? selectedIndex - 1 : items.length - 1;
        items.forEach((item, idx) => item.classList.toggle('selected', idx === selectedIndex));
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        if (selectedIndex >= 0) {
            items[selectedIndex].click();
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        hideCommentMentionPanel();
    }
}

window.toggleCommentMore = toggleCommentMore;
