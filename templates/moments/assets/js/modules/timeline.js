/**
 * woquan 时间线 & 帖子渲染
 */
import { PAGE_BASE } from './config.js?v=20260803.5';
import { getTextLength, truncateText, renderMarkdown } from './utils.js?v=20261112.2';

export async function loadTimeline(app, append = false) {
    if (app.loading || !app.hasMore) return;
    app.loading = true;
    const loadMore = document.getElementById('load-more');
    if (loadMore) loadMore.innerHTML = '<span class="loading-spinner"></span>';

    try {
        const urlParams = new URLSearchParams(window.location.search);
        const username = urlParams.get('username') || '';
        let url = `/posts/timeline?page=${app.page}&limit=10&username=${encodeURIComponent(username)}`;
        const filter = app.timelineFilter || {};
        if (filter.category_id) url += `&category_id=${encodeURIComponent(filter.category_id)}`;
        if (filter.date_range) url += `&date_range=${encodeURIComponent(filter.date_range)}`;
        const data = await app.request(url);
        const list = document.getElementById('moments-list');
        if (!list) return;

        if (!append) {
            list.innerHTML = '';
            if (data.list && data.list.length > 0) {
                app.latestPostId = Math.max(...data.list.map(p => p.id), app.latestPostId);
            }
            const pinned = (data.list || []).filter(p => p.is_pinned);
            const showPinnedGrid = !username && pinned.length >= 2;
            if (showPinnedGrid) {
                list.appendChild(renderPinnedSection(app, pinned));
                pinned.forEach(p => p.__hidePinned = true);
                data.list.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            }
        }

        data.list.forEach(post => {
            list.appendChild(createPostElement(app, post));
        });

        // 为新渲染的视频缩略图加载首帧
        initVideoThumbnails(list);
        // 图片懒加载兜底（旧浏览器不支持 native loading="lazy" 时）
        app.initLazyLoading(list);

        app.hasMore = data.pagination.page < data.pagination.pages;
        if (app.hasMore) app.page++;

        if (loadMore) {
            if (app.hasMore) {
                loadMore.innerHTML = '加载更多';
                loadMore.style.display = '';
                loadMore.classList.remove('no-more');
            } else {
                loadMore.innerHTML = '没有更多动态了';
                loadMore.classList.add('no-more');
            }
        }
    } catch (e) {
        app.showToast(e.message);
        if (loadMore) loadMore.innerHTML = '加载失败，点击重试';
    } finally {
        app.loading = false;
    }
}

/* ==================== 类型帖（问答 / 投票 / 组队 / 打卡） ====================
 * 类型帖的正文按设计存在独立表（questions / teams / checkin_posts / polls），
 * posts.content 留空。接口从 V59 起已随列表一并返回 question/team/checkin/poll
 * （见 PostController::hydratePosts），但 web 端此前只渲染 post.content，
 * 导致信息流里这类动态整块空白 —— 这里补上结构化区块的渲染。
 * 交互（投票 / 回答 / 加入 / 打卡）走 app.xxx，由 applyMixin 自动挂到原型。
 */

const TYPE_LABEL = { question: '问答', poll: '投票', team: '组队', checkin: '打卡' };

export function buildTypeBlockHtml(app, post, isDetail = false) {
    const type = post.post_type || 'normal';
    let html = '';
    if (type === 'question') html = buildQuestionBlock(app, post, isDetail);
    else if (type === 'poll') html = buildPollBlock(app, post, isDetail);
    else if (type === 'team') html = buildTeamBlock(app, post, isDetail);
    else if (type === 'checkin') html = buildCheckinBlock(app, post, isDetail);
    if (!html) return '';
    // V140：类型帖的正文 posts.content 恒空、且通常没有标题，
    // 而卡片进详情只有「标题 / 置顶 / 查看全文」三处入口 ——
    // 三者全缺时整张卡片点不进详情页，这里补一个明确入口。
    if (!isDetail) {
        html += `<a class="tp-more" href="${PAGE_BASE}/p/${post.id}">查看详情 ›</a>`;
    }
    return html;
}

function buildQuestionBlock(app, post, isDetail) {
    const q = post.question;
    if (!q) return '';
    const e = (s) => app.escapeHtml(s || '');
    const solved = (q.status === 1) || !!q.answer;

    let answerHtml = '';
    if (q.answer) {
        answerHtml = `<div class="tp-answer"><span class="tp-answer-label">回答</span><div class="tp-answer-body">${e(q.answer)}</div></div>`;
    } else {
        answerHtml = `<div class="tp-pending">还没有人回答</div>`;
    }

    let formHtml = '';
    if (isDetail && q.can_answer && app.user) {
        formHtml = `<div class="tp-form">
            <textarea class="tp-input" id="qa-input-${post.id}" rows="2" placeholder="写下你的回答…"></textarea>
            <button class="tp-btn" onclick="app.submitQuestionAnswer(${post.id})">提交回答</button>
        </div>`;
    }

    return `<div class="tp-block tp-question">
        <div class="tp-head"><span class="tp-tag">${TYPE_LABEL.question}</span>
            <span class="tp-state${solved ? ' tp-state-done' : ''}">${solved ? '已解决' : '待解答'}</span></div>
        <div class="tp-q">${e(q.question)}</div>
        ${answerHtml}${formHtml}
    </div>`;
}

function buildPollBlock(app, post, isDetail) {
    const p = post.poll;
    if (!p) return '';
    const e = (s) => app.escapeHtml(s || '');
    const total = p.total_votes || 0;
    const opts = (p.options || []).map(o => `
        <div class="tp-option${o.my_vote ? ' voted' : ''}" onclick="app.votePoll(${post.id}, ${o.id})">
            <div class="tp-opt-bar" style="width:${o.percent || 0}%"></div>
            <span class="tp-opt-text">${e(o.content)}</span>
            <span class="tp-opt-count">${o.votes || 0} 票 · ${o.percent || 0}%</span>
        </div>`).join('');

    return `<div class="tp-block tp-poll">
        <div class="tp-head"><span class="tp-tag">${TYPE_LABEL.poll}</span>
            ${p.multi ? `<span class="tp-state">可多选${p.max_choice ? ' ≤' + p.max_choice : ''}</span>` : ''}
            ${p.closed ? '<span class="tp-state tp-state-done">已结束</span>' : ''}</div>
        <div class="tp-q">${e(p.question)}</div>
        <div class="tp-options">${opts}</div>
        <div class="tp-foot">共 ${total} 人参与${p.closed ? '' : ' · 点击选项投票'}</div>
    </div>`;
}

function buildTeamBlock(app, post, isDetail) {
    const t = post.team;
    if (!t) return '';
    const e = (s) => app.escapeHtml(s || '');
    const need = t.need_count || 0;
    const joined = t.joined_count || 0;
    const lack = need > 0 ? Math.max(0, need - joined) : 0;
    const meta = [];
    if (t.city) meta.push('📍 ' + e(t.city));
    if (t.deadline) meta.push('截止 ' + e(t.deadline));
    if (need > 0) meta.push(`${joined}/${need} 人`);

    const btn = t.is_full
        ? '<span class="tp-state tp-state-done">已满员</span>'
        : `<button class="tp-btn${t.joined ? ' tp-btn-plain' : ''}" onclick="app.joinTeam(${post.id})">${t.joined ? '退出' : '加入'}</button>`;

    return `<div class="tp-block tp-team">
        <div class="tp-head"><span class="tp-tag">${TYPE_LABEL.team}</span>
            <span class="tp-state">${need > 0 ? ('还差 ' + lack + ' 人') : '人数不限'}</span></div>
        <div class="tp-q">${e(t.headline || '找人组队')}</div>
        ${meta.length ? `<div class="tp-meta">${meta.join(' · ')}</div>` : ''}
        <div class="tp-foot">${btn}</div>
    </div>`;
}

function buildCheckinBlock(app, post, isDetail) {
    const c = post.checkin;
    if (!c) return '';
    const e = (s) => app.escapeHtml(s || '');
    const pct = c.percent || 0;
    const btn = c.done_today
        ? '<span class="tp-state tp-state-done">今日已打卡</span>'
        : `<button class="tp-btn" onclick="app.checkinToday(${post.id})">打卡</button>`;

    return `<div class="tp-block tp-checkin">
        <div class="tp-head"><span class="tp-tag">${TYPE_LABEL.checkin}</span>
            <span class="tp-state">${c.target_days ? ('目标 ' + c.target_days + ' 天') : '每日打卡'}</span></div>
        <div class="tp-q">${e(c.goal || '每日打卡')}</div>
        <div class="tp-progress"><div class="tp-progress-bar" style="width:${pct}%"></div></div>
        <div class="tp-foot">已坚持 ${c.my_total || 0} 天 · ${pct}% ${btn}</div>
    </div>`;
}

/* ---------------- 类型帖交互 ---------------- */

// 列表页用增量刷新；详情页（post.html）没有 #moments-list，直接重载
function afterTypeAction(app) {
    if (document.getElementById('moments-list')) return app.refreshPage();
    location.reload();
}

export async function votePoll(app, postId, optionId) {
    if (!app.checkAuth()) return app.openAuthModal();
    try {
        await app.request('/posts/poll/vote', { method: 'POST', body: { post_id: postId, option_id: optionId } });
        afterTypeAction(app);
    } catch (err) {
        app.showToast(err.message || '投票失败');
    }
}

export async function submitQuestionAnswer(app, postId) {
    if (!app.checkAuth()) return app.openAuthModal();
    const input = document.getElementById('qa-input-' + postId);
    const content = input ? input.value.trim() : '';
    if (!content) return app.showToast('请先填写回答');
    try {
        await app.request('/posts/question/answer', { method: 'POST', body: { post_id: postId, answer: content } });
        afterTypeAction(app);
    } catch (err) {
        app.showToast(err.message || '提交失败');
    }
}

export async function joinTeam(app, postId) {
    if (!app.checkAuth()) return app.openAuthModal();
    try {
        await app.request('/posts/team/join', { method: 'POST', body: { post_id: postId } });
        afterTypeAction(app);
    } catch (err) {
        app.showToast(err.message || '操作失败');
    }
}

export async function checkinToday(app, postId) {
    if (!app.checkAuth()) return app.openAuthModal();
    try {
        await app.request('/posts/checkin/do', { method: 'POST', body: { post_id: postId } });
        afterTypeAction(app);
    } catch (err) {
        app.showToast(err.message || '打卡失败');
    }
}

export function createPostElement(app, post, isDetail = false) {
    const div = document.createElement('div');
    const showPinned = post.is_pinned && !post.__hidePinned;
    const hasImages = (post.images?.length || 0) > 0;
    const hasVideos = (post.videos?.length || 0) > 0;
    const hasAttachments = (post.attachments?.length || 0) > 0;
    let typeClass = '';
    if ((hasImages || hasVideos) && hasAttachments) typeClass = ' mixed';
    else if (hasImages) typeClass = ' has-images';
    else if (hasVideos) typeClass = ' has-videos';
    else if (hasAttachments) typeClass = ' has-attachments';
    else typeClass = ' text-only';
    div.className = 'moment-item' + (showPinned ? ' moment-pinned' : '') + typeClass;
    div.dataset.id = post.id;
    if (post.user?.username) div.dataset.username = post.user.username;

    const imgCount = post.images?.length || 0;
    const imgClass = imgCount > 0 ? `img-${Math.min(imgCount, 9)}` : '';
    const videoCount = post.videos?.length || 0;
    const videoClass = videoCount > 0 ? `video-${Math.min(videoCount, 3)}` : '';

    const isAdmin = post.user?.role === 'admin';
    const adminBadge = isAdmin ? '<span class="admin-badge">管理员</span>' : '';
    const anonymousBadge = post.is_anonymous ? '<span class="anonymous-badge">匿名</span>' : '';

    let locationHtml = '';
    if (post.location) {
        locationHtml = `<div class="moment-location">📍 <span>${app.escapeHtml(post.location)}</span></div>`;
    }

    let imagesHtml = '';
    if (imgCount > 0) {
        const imagesJson = JSON.stringify(post.images).replace(/'/g, "&#39;").replace(/"/g, '&quot;');
        // V163：网格图默认请求 _s 缩略图变体（P2-9 后端已生成 small 变体），
        //       变体不存在时 onerror 自动回退原图——既吃到列表带宽收益，又兼容 P2-9 之前
        //       上传的无变体老图，全程零后端/存储改动，属纯前端无感优化。
        imagesHtml = `<div class="moment-images ${imgClass}">` +
            post.images.map((img, i) => {
                const thumb = img.replace(/(\.[^.]+)$/, '_s$1');
                return `<img src="${app.escapeHtml(thumb)}" alt="${app.escapeHtml(post.title || '动态图片')}" loading="lazy" onclick="app.viewImage('${app.escapeJs(img)}', ${i}, ${imagesJson})" onerror="this.onerror=null;this.src='${app.escapeJs(img)}'">`;
            }).join('') +
            `</div>`;
    }

    let videosHtml = '';
    if (videoCount > 0) {
        videosHtml = `<div class="moment-videos ${videoClass}">` +
            post.videos.map((v, i) => {
                if (v.blocked) {
                    return `<div class="moment-video-item blocked" onclick="app.showToast('视频已失效，无法播放')">
                        <span class="video-blocked-tip">视频已失效</span>
                    </div>`;
                }
                const src = app.escapeHtml(v.url);
                const cover = v.cover ? app.escapeHtml(v.cover) : '';
                const durSeconds = parseInt(v.duration || '0', 10) || 0;
                const mm = String(Math.floor(durSeconds / 60)).padStart(2, '0');
                const ss = String(durSeconds % 60).padStart(2, '0');
                const durationBadge = durSeconds > 0 ? `<span class="moment-video-duration">${mm}:${ss}</span>` : '';
                // 始终使用 <video> 元素作缩略图，preload=metadata 只加载首帧
                // 通过 #t=0.001 提示浏览器加载第 0.001 秒的关键帧作为封面
                const posterAttr = cover ? `poster="${cover}"` : '';
                return `<div class="moment-video-item" data-video-src="${src}" data-video-index="${i}" data-post-id="${post.id}">
                    <video
                        src="${src}"
                        ${posterAttr}
                        preload="metadata"
                        muted
                        playsinline
                        webkit-playsinline
                        x5-playsinline
                        disableremote
                        data-thumb="1"
                    ></video>
                    <span class="moment-video-play" onclick="app.playVideo(${post.id}, ${i})">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                    </span>
                    ${durationBadge}
                </div>`;
            }).join('') +
            `</div>`;
    }

    let attachmentsHtml = '';
    if (post.attachments?.length > 0) {
        const canDownload = app.user || (app.siteConfig?.allow_guest_download_attachment !== false);
        attachmentsHtml = `<div class="moment-attachments">` +
            post.attachments.map(att => {
                const blocked = att.blocked;
                const icon = '📎';
                const filename = app.escapeHtml(att.filename || '附件');
                if (blocked) {
                    return `<div class="attachment-item blocked" onclick="app.showToast('附件已失效，无法下载')"><span class="attachment-icon">${icon}</span><span class="attachment-name">${filename}</span><span class="attachment-status">（已失效）</span></div>`;
                }
                if (!canDownload) {
                    return `<div class="attachment-item" onclick="app.showToast('请登录后下载附件')"><span class="attachment-icon">${icon}</span><span class="attachment-name">${filename}</span><span class="attachment-size">${app.formatFileSize(att.size || 0)}</span></div>`;
                }
                return `<a class="attachment-item" href="${app.escapeHtml(att.url)}" target="_blank" download="${filename}"><span class="attachment-icon">${icon}</span><span class="attachment-name">${filename}</span><span class="attachment-size">${app.formatFileSize(att.size || 0)}</span></a>`;
            }).join('') +
            `</div>`;
    }

    const hasTitle = !!post.title;
    const socialHtml = app.buildSocialHtml(post);
    const contentHtml = isDetail
        ? renderMarkdown(post.content, PAGE_BASE)
        : (hasTitle
            ? buildTitlePreviewHtml(app, post.content, post.id).html
            : buildCollapseHtml(app, post.content).html);
    const titleHtml = hasTitle
        ? `<a class="moment-title" href="${PAGE_BASE}/p/${post.id}">${app.escapeHtml(post.title)}</a>`
        : '';
    const categoryName = getCategoryName(app, post.category_id);
    const categoryHtml = categoryName
        ? `<a class="moment-category" href="${PAGE_BASE}/c/${post.category_id}">${app.escapeHtml(categoryName)}</a>`
        : '';
    // V139：类型帖（问答/投票/组队/打卡）正文不在 content 里，改渲染结构化区块
    const typeBlockHtml = buildTypeBlockHtml(app, post, isDetail);
    // 类型帖的 content 恒为空，留着空的 moment-text 会多撑出一段空白
    const showText = !typeBlockHtml || !!(post.content || '').trim();

    div.__comments = post.comments || [];

    // 删除按钮：仅在「自己主页 (/u/{自己用户名}) + 这条动态是自己发的」时才显示。
    // 公共主页 / 别人主页一律不显示，避免与微信朋友圈布局过于雷同（防侵权考量）。
    const deleteBtn = (isOwnProfilePage(app) && canDeletePost(app, post))
        ? `<button class="moment-delete-btn" onclick="app.deletePost(${post.id})" title="删除">
               <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
           </button>`
        : '';

    const isAnonymousPost = post.is_anonymous;
    const avatarClick = isAnonymousPost ? '' : `onclick="app.goUser('${app.escapeJs(post.user.username)}')"`;
    const authorClick = isAnonymousPost ? '' : `onclick="app.goUser('${app.escapeJs(post.user.username)}')"`;
    const authorCursor = isAnonymousPost ? ' style="cursor:default"' : '';
    const isSelfPost = app.user && post.user && app.user.id === post.user.id;
    const privateChatBtn = (!isSelfPost && !isAnonymousPost) ? `
        <button class="moment-author-pm-btn" onclick="event.stopPropagation(); event.preventDefault(); if(app.checkAuth()){app.startPrivateChat('${app.escapeJs(post.user.username)}')}else{app.openAuthModal()}" title="发私信" style="display:inline-flex;align-items:center;justify-content:center;margin-left:8px;width:22px;height:22px;border:none;background:none;cursor:pointer;opacity:0.7;padding:0;border-radius:4px;vertical-align:middle;" onmouseover="this.style.opacity='1';this.style.background='rgba(7,193,96,0.08)';" onmouseout="this.style.opacity='0.7';this.style.background='none';">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" style="color:var(--primary);"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
        </button>
    ` : '';

    // ⋯ 更多按钮：非匿名动态一律显示（登录作者本人也能看到）；匿名动态仅作者/管理员可见。
    // 菜单内容按权限区分 —— 有删除权（作者/管理员）→ 显示"删除"；无删除权 → 显示"举报"。
    const canDelete = canDeletePost(app, post);
    const moreBtn = (canDelete || !isAnonymousPost) ? `
        <button class="moment-action-btn moment-act-more" onclick="app.toggleMoreMenu(this, ${post.id}, ${canDelete ? 'true' : 'false'})" aria-label="更多" title="更多">
            <svg viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
        </button>
    ` : '';

    div.innerHTML = `
        <img src="${app.escapeHtml(app.resolveAvatar(post.user.avatar))}" class="moment-avatar" loading="lazy" ${avatarClick} alt="${app.escapeHtml(post.user.nickname || post.user.username)}的头像">
        <div class="moment-content">
            <div class="moment-author" ${authorClick}${authorCursor} style="display:flex;align-items:center;flex-wrap:wrap;">
                <span style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    ${app.escapeHtml(post.user.nickname)}${isAnonymousPost ? '' : app.vipBadge(post.user.vip_level, 14)}${adminBadge}${anonymousBadge}${showPinned ? '<span class="pinned-badge">置顶</span>' : ''}
                    <span class="moment-time-inline">${post.time_ago}</span>
                    ${post.view_count > 0 ? `<span class="moment-views-inline">👁 ${post.view_count}</span>` : ''}
                </span>
                ${privateChatBtn}
            </div>
            ${titleHtml}
            ${categoryHtml}
            ${typeBlockHtml}
            ${showText ? `<div class="moment-text" data-full="${app.escapeHtml(post.content)}">${contentHtml}</div>` : ''}
            ${imagesHtml}
            ${videosHtml}
            ${attachmentsHtml}
            ${locationHtml}
            <div class="moment-actions">
                <button class="moment-action-btn moment-act-like ${post.liked ? 'liked' : ''}" onclick="app.toggleLike(${post.id})" aria-label="${post.liked ? '取消赞' : '赞'}">
                    <span class="ma-label">点赞</span>
                    ${(post.like_count ?? (post.likes?.length || 0)) > 0 ? `<span class="ma-count">${(post.like_count ?? (post.likes?.length || 0)) > 99 ? '99+' : (post.like_count ?? (post.likes?.length || 0))}</span>` : ''}
                </button>
                <button class="moment-action-btn moment-act-comment" onclick="app.openComment(${post.id})" aria-label="评论">
                    <span class="ma-label">评论</span>
                    ${(post.comment_count ?? (post.comments?.length || 0)) > 0 ? `<span class="ma-count">${(post.comment_count ?? (post.comments?.length || 0)) > 99 ? '99+' : (post.comment_count ?? (post.comments?.length || 0))}</span>` : ''}
                </button>
                <button class="moment-action-btn moment-act-fav ${post.favorited ? 'favorited' : ''}" onclick="app.toggleFavorite(${post.id})" aria-label="${post.favorited ? '已收藏' : '收藏'}">
                    <span class="ma-label">收藏</span>
                </button>
                <button class="moment-action-btn moment-act-share" onclick="app.sharePost(${post.id})" aria-label="分享">
                    <span class="ma-label">分享</span>
                </button>
                ${moreBtn}
                ${deleteBtn}
            </div>
            ${socialHtml}
        </div>
    `;
    return div;
}

export function renderPinnedSection(app, pinnedPosts) {
    const section = document.createElement('div');
    section.className = 'pinned-section';

    const perPage = 6;
    const pages = [];
    for (let i = 0; i < pinnedPosts.length; i += perPage) {
        pages.push(pinnedPosts.slice(i, i + perPage));
    }

    const trackId = 'pinned-track-' + Date.now();
    const previewLength = app.siteConfig?.post_title_preview_length || 150;
    const pageHtml = pages.map((page, idx) => {
        const itemsHtml = page.map(post => {
            const hasTitle = post.title && post.title.trim();
            const titleHtml = hasTitle
                ? `<div class="pinned-card-title">${app.escapeHtml(post.title)}</div>`
                : '';
            const previewText = hasTitle
                ? app.escapeHtml(truncateText(post.content || '', 40))
                : app.escapeHtml(post.content || '');
            return `
                <a class="pinned-item${hasTitle ? ' has-title' : ''}" href="${PAGE_BASE}/p/${post.id}">
                    <div class="pinned-card-body">
                        ${titleHtml}
                        <div class="pinned-text">${previewText}</div>
                    </div>
                    <div class="pinned-label">置顶</div>
                </a>
            `;
        }).join('');
        return `<div class="pinned-page"><div class="pinned-grid">${itemsHtml}</div></div>`;
    }).join('');

    const showNav = pages.length > 1;
    const dotsHtml = showNav ? pages.map((_, idx) => `<span class="pinned-dot ${idx === 0 ? 'active' : ''}" data-idx="${idx}"></span>`).join('') : '';

    section.innerHTML = `
        <div class="pinned-header">
            <div class="pinned-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
                <span>置顶动态</span>
            </div>
            <a class="pinned-view-all" href="${PAGE_BASE}/pinned">查看全部</a>
        </div>
        <div class="pinned-carousel">
            <div class="pinned-track" id="${trackId}" style="width: ${pages.length * 100}%">${pageHtml}</div>
            ${showNav ? `<button class="pinned-nav pinned-prev" data-track="${trackId}">&#8249;</button>` : ''}
            ${showNav ? `<button class="pinned-nav pinned-next" data-track="${trackId}">&#8250;</button>` : ''}
            ${showNav ? `<div class="pinned-dots" data-track="${trackId}">${dotsHtml}</div>` : ''}
        </div>
    `;

    if (showNav) {
        setTimeout(() => bindPinnedCarousel(app, trackId), 0);
    }
    return section;
}

export function bindPinnedCarousel(app, trackId) {
    const track = document.getElementById(trackId);
    if (!track) return;
    const carousel = track.closest('.pinned-carousel');
    const prev = carousel.querySelector('.pinned-prev');
    const next = carousel.querySelector('.pinned-next');
    const dots = carousel.querySelectorAll('.pinned-dot');
    const total = dots.length;
    let current = 0;

    const go = (idx) => {
        current = Math.max(0, Math.min(idx, total - 1));
        track.style.transform = `translateX(-${current * (100 / total)}%)`;
        dots.forEach((d, i) => d.classList.toggle('active', i === current));
    };

    if (prev) prev.addEventListener('click', (e) => { e.preventDefault(); go(current - 1); });
    if (next) next.addEventListener('click', (e) => { e.preventDefault(); go(current + 1); });
    dots.forEach((dot, idx) => dot.addEventListener('click', () => go(idx)));
}

function isMobileViewport() {
    return window.innerWidth < 768 || /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
}

export function buildCollapseHtml(app, content) {
    const cfg = app.siteConfig || {};
    const baseLimit = cfg.post_collapse_length || 300;
    const limit = isMobileViewport() ? Math.min(baseLimit, 80) : baseLimit;
    if (!content || getTextLength(content) <= limit) {
        return { html: renderMarkdown(content, PAGE_BASE), collapsed: false };
    }
    const short = truncateText(content, limit);
    return {
        html: `<span class="text-short">${renderMarkdown(short, PAGE_BASE)}...</span><span class="text-full" style="display:none;">${renderMarkdown(content, PAGE_BASE)}</span><button class="btn-expand" onclick="app.toggleExpand(this)">展开</button>`,
        collapsed: true
    };
}

export function buildTitlePreviewHtml(app, content, postId) {
    const cfg = app.siteConfig || {};
    const baseLimit = cfg.post_title_preview_length || 150;
    const limit = isMobileViewport() ? Math.min(baseLimit, 60) : baseLimit;
    if (!content || getTextLength(content) <= limit) {
        return { html: renderMarkdown(content, PAGE_BASE), collapsed: false };
    }
    const short = truncateText(content, limit);
    return {
        html: `<span class="text-short">${renderMarkdown(short, PAGE_BASE)}...<a class="preview-more" href="${PAGE_BASE}/p/${postId}">查看全文</a></span>`,
        collapsed: true
    };
}

export async function loadPostDetail(app, postId) {
    const list = document.getElementById('moments-list');
    if (!list) return;
    list.innerHTML = '<div class="load-more">加载中...</div>';
    try {
        const data = await app.request(`/posts/detail?id=${postId}`);
        list.innerHTML = '';
        if (data) {
            list.appendChild(createPostElement(app, data, true));
            // V97 内容付费墙：正文已由服务端裁剪为试读，追加购买卡（仅未解锁时）
            if (data.paywall && data.paywall.locked) {
                const card = document.createElement('div');
                card.className = 'paywall-card';
                const price = Number(data.paywall.price || 0).toFixed(2);
                const label = data.paywall.product_type === 'column' ? '专栏' : '单篇';
                card.innerHTML = '<p class="paywall-title">剩余内容需付费解锁</p>'
                    + '<p class="paywall-price">¥' + price + '</p>'
                    + '<button type="button" class="paywall-unlock" data-action="content-unlock">解锁' + label + '全文</button>';
                card.dataset.productType = data.paywall.product_type || 'post';
                card.dataset.targetId = data.paywall.target_id || postId;
                list.appendChild(card);
                const btn = card.querySelector('.paywall-unlock');
                if (btn) btn.addEventListener('click', async () => {
                    if (!app.checkAuth()) return;
                    if (btn.disabled) return;
                    btn.disabled = true; const old = btn.textContent; btn.textContent = '处理中…';
                    try {
                        await app.request('/content-pay/buy', {
                            method: 'POST',
                            body: { product_type: card.dataset.productType, target_id: Number(card.dataset.targetId), use_points: 0 },
                        });
                        app.showToast('解锁成功');
                        await loadPostDetail(app, postId); // 重渲染全文
                    } catch (e) {
                        app.showToast(e.message || '解锁失败，请重试');
                        btn.disabled = false; btn.textContent = old;
                    }
                });
            }
            initVideoThumbnails(list);
            app.initLazyLoading(list);
        } else {
            list.innerHTML = '<div class="empty-state">动态不存在或已被删除</div>';
        }
    } catch (e) {
        list.innerHTML = '<div class="empty-state">加载失败</div>';
        app.showToast(e.message);
    }
}

export function refreshPage(app) {
    app.page = 1;
    app.hasMore = true;
    app.newPostCount = 0;
    app.updateRefreshBadge();
    const list = document.getElementById('moments-list');
    if (list) list.innerHTML = '';
    app.loadTimeline();
}

export function setupInfiniteScroll(app) {
    const loadMore = document.getElementById('load-more');
    if (!loadMore) return;
    const mainContent = document.querySelector('.main-content');
    const scrollRoot = mainContent || null;
    const observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting) {
            app.loadTimeline(true);
        }
    }, { root: scrollRoot, rootMargin: '100px', threshold: 0 });
    observer.observe(loadMore);

    // 点击「加载更多」/「加载失败，点击重试」/「没有更多动态了」都能触发对应行为。
    // IntersectionObserver 在容器布局变化、PC 端 main-content 自滚动、初始加载过快等场景
    // 不一定能稳定触发；点击兜底才能保证用户能继续翻页。
    // loadTimeline() 内部已有 app.loading 锁，并发点击会被自然丢弃，不会重复请求。
    loadMore.addEventListener('click', () => {
        // 「没有更多动态了」状态下点击不重复请求（避免无效请求 + 抖动）
        if (loadMore.classList.contains('no-more')) return;
        app.loadTimeline(true);
    });

    // 滚动到底部时显示"没有更多动态了"
    const container = scrollRoot || document;
    const target = scrollRoot || window;
    target.addEventListener('scroll', () => {
        if (!loadMore.classList.contains('no-more')) return;
        const el = scrollRoot || document.documentElement;
        const scrollBottom = el.scrollTop + el.clientHeight;
        const fullHeight = el.scrollHeight;
        loadMore.classList.toggle('visible', (fullHeight - scrollBottom) < 100);
    });
}

export function startRefreshPolling(app) {
    if (app.refreshPollingInterval) return;
    syncLatestIds(app).then(() => checkNewPosts(app));
    app.refreshPollingInterval = setInterval(() => {
        // 页面不可见时跳过轮询，节省电量和流量
        if (document.hidden) return;
        checkNewPosts(app);
    }, 30000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            // 页面恢复可见时立即检查一次新动态
            checkNewPosts(app);
        }
    });
}

export async function syncLatestIds(app) {
    try {
        const data = await app.request('/posts/latest-ids');
        if (data) {
            app.latestPostId = Math.max(app.latestPostId, data.post_id);
            app.latestCommentId = Math.max(app.latestCommentId, data.comment_id);
            app.latestLikeId = Math.max(app.latestLikeId, data.like_id);
        }
    } catch (e) {
        // 静默失败
    }
}

export async function checkNewPosts(app) {
    if (!app.latestPostId && !app.latestCommentId && !app.latestLikeId) return;
    const urlParams = new URLSearchParams(window.location.search);
    const username = urlParams.get('username') || '';
    try {
        const data = await app.request(`/posts/check-new?since_id=${app.latestPostId}&since_comment_id=${app.latestCommentId}&since_like_id=${app.latestLikeId}&username=${encodeURIComponent(username)}`);
        if (data) {
            if (data.count > 0) {
                app.newPostCount = data.count;
                app.latestPostId = Math.max(app.latestPostId, data.latest_id);
                app.latestCommentId = Math.max(app.latestCommentId, data.latest_comment_id);
                app.latestLikeId = Math.max(app.latestLikeId, data.latest_like_id);
            }
            app.updateRefreshBadge();
        }
    } catch (e) {
        // 静默失败，不影响用户体验
    }
}

export function updateRefreshBadge(app) {
    const badge = document.getElementById('refresh-badge');
    if (!badge) return;
    if (app.newPostCount > 0) {
        badge.textContent = app.newPostCount > 99 ? '99+' : app.newPostCount;
        badge.classList.add('active');
    } else {
        badge.classList.remove('active');
    }
}

export function getCategoryName(app, categoryId) {
    if (!categoryId) return '';
    const categories = app.siteConfig?.categories || [];
    for (const cat of categories) {
        if (cat.id === categoryId) return cat.name;
        for (const child of cat.children || []) {
            if (child.id === categoryId) return child.name;
        }
    }
    return '';
}

export function canDeletePost(app, post) {
    if (!app.user || !post?.user) return false;
    return app.user.id === post.user.id || app.user.role === 'admin';
}

/**
 * 判断当前页面是不是「当前用户自己的个人主页」
 * 个人主页路径：/u/{username} 或 /u/{userId}
 * 用于控制删除按钮等敏感操作只在「自己的主页」显示（公共主页 / 别人主页均隐藏）
 */
export function isOwnProfilePage(app) {
    if (!app?.user || !app.user.username) return false;
    const m = window.location.pathname.match(/^\/u\/([^/]+)/);
    if (!m) return false;
    const slug = decodeURIComponent(m[1]);
    return slug === app.user.username || slug === String(app.user.id);
}

export async function deletePost(app, postId) {
    if (!app.checkAuth()) return;
    if (!confirm('确定要删除这条动态吗？')) return;
    try {
        await app.request('/posts', {
            method: 'DELETE',
            body: { id: postId }
        });
        app.showToast('删除成功');
        const el = document.querySelector(`.moment-item[data-id="${postId}"]`);
        if (el) {
            el.style.opacity = '0';
            el.style.transform = 'scale(0.95)';
            setTimeout(() => el.remove(), 200);
        }
    } catch (e) {
        app.showToast(e.message);
    }
}

/**
 * 动态操作栏 ⋯ 按钮：点击弹出次级菜单。
 * 菜单内容按权限区分：有删除权（作者/管理员）→ "删除"；否则 → "举报"。
 *
 * 行为约定：
 *  - 同一篇动态的 ⋯ 二次点击 = 关闭（toggle）
 *  - 不同动态的 ⋯ 之间切换 = 关闭旧的、开新的
 *  - 点击菜单项 = 关闭菜单 + 执行动作
 *  - 点击菜单外 / ⋯ 按钮外 = 关闭
 *  - ESC = 关闭（由 document.keydown 接管）
 */
export function toggleMoreMenu(app, btn, postId, isSelf = false) {
    let existing = document.getElementById('moment-more-menu');

    // 二次点击同 ⋯：关闭
    if (existing && existing._postId === postId) {
        teardownMoreMenu(existing);
        return;
    }
    // 切到不同 ⋯：先拆掉旧菜单
    if (existing) teardownMoreMenu(existing);

    const menu = document.createElement('div');
    menu.id = 'moment-more-menu';
    menu.className = 'moment-more-menu';
    menu._postId = postId;
    menu.innerHTML = isSelf ? `
        <button class="moment-more-item moment-more-item-danger" data-action="delete">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
            <span>删除</span>
        </button>
    ` : `
        <button class="moment-more-item moment-more-item-report" data-action="report">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
            <span>举报</span>
        </button>
    `;
    document.body.appendChild(menu);
    // 先 display:block（.active）再测量尺寸：display:none 时 getBoundingClientRect 拿到的是 0，
    // 会导致定位计算错误、菜单飞出屏幕右侧（移动端尤为明显）。
    menu.classList.add('active');

    // 定位：右上对齐 ⋯ 按钮，优先放按钮下方，空间不够时往上翻
    const rect = btn.getBoundingClientRect();
    menu.style.visibility = 'hidden';
    requestAnimationFrame(() => {
        const menuRect = menu.getBoundingClientRect();
        let left = rect.right - menuRect.width;
        let top = rect.bottom + 6;
        if (left < 8) left = 8;
        if (left + menuRect.width > window.innerWidth - 8) {
            left = window.innerWidth - menuRect.width - 8;
        }
        if (top + menuRect.height > window.innerHeight - 8) {
            top = rect.top - menuRect.height - 6;
        }
        if (top < 8) top = 8;
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
        menu.style.visibility = '';
    });

    // 菜单项点击
    menu.addEventListener('click', (e) => {
        const item = e.target.closest('.moment-more-item');
        if (!item || !menu.contains(item)) return;
        const action = item.dataset.action;
        teardownMoreMenu(menu);
        if (action === 'report') {
            if (app.checkAuth()) {
                app.openReportModal('post', postId);
            } else {
                app.openAuthModal();
            }
        } else if (action === 'delete') {
            app.deletePost(postId);
        }
    });

    // 点击外部关闭（setTimeout 0 避开本次触发 ⋯ 的那个 click）
    setTimeout(() => {
        const onClickOutside = (e) => {
            if (!menu.contains(e.target) && !btn.contains(e.target)) {
                teardownMoreMenu(menu);
            }
        };
        document.addEventListener('click', onClickOutside);
        menu._clickOutside = onClickOutside;
    }, 0);

    // ESC 关闭（一次性绑定，关闭后自动解绑）
    const onKey = (e) => {
        if (e.key === 'Escape' && document.getElementById('moment-more-menu') === menu) {
            teardownMoreMenu(menu);
        }
    };
    document.addEventListener('keydown', onKey);
    menu._keyHandler = onKey;
}

function teardownMoreMenu(menu) {
    if (!menu) return;
    if (menu._clickOutside) {
        document.removeEventListener('click', menu._clickOutside);
        menu._clickOutside = null;
    }
    if (menu._keyHandler) {
        document.removeEventListener('keydown', menu._keyHandler);
        menu._keyHandler = null;
    }
    menu.remove();
}

export function updatePostWordCount(app) {
    const textarea = document.getElementById('publish-content');
    const counter = document.getElementById('publish-word-count');
    if (!counter) return;
    const cfg = app.siteConfig || {};
    const maxLength = cfg.post_max_length || 2000;
    // Vditor 模式下从编辑器读取 Markdown 源文本；否则从 textarea 读取
    let text = '';
    if (app._publishVditor && typeof app._publishVditor.getValue === 'function') {
        text = app._publishVditor.getValue() || '';
    } else if (textarea) {
        text = textarea.value;
    }
    const length = getTextLength(text);
    counter.textContent = `${length} / ${maxLength}`;
    counter.classList.toggle('over-limit', length > maxLength);

    // textarea 自适应高度
    if (textarea) {
        textarea.style.height = 'auto';
        const scrollH = textarea.scrollHeight;
        const computedMinH = parseInt(getComputedStyle(textarea).minHeight, 10) || 36;
        const maxH = 200;
        textarea.style.height = Math.max(computedMinH, Math.min(scrollH, maxH)) + 'px';
    }
}

export function toggleExpand(app, btn) {
    const textEl = btn.parentElement;
    const short = textEl.querySelector('.text-short');
    const full = textEl.querySelector('.text-full');
    if (!short || !full) return;
    if (full.style.display === 'none') {
        short.style.display = 'none';
        full.style.display = '';
        btn.textContent = '收起';
    } else {
        short.style.display = '';
        full.style.display = 'none';
        btn.textContent = '展开';
    }
}

/**
 * 初始化视频缩略图：强制 seek 到 0.1s 显示首帧
 * 在列表渲染完成后调用
 */
export function initVideoThumbnails(rootEl) {
    const scope = rootEl || document;
    scope.querySelectorAll('video[data-thumb="1"]').forEach(video => {
        if (video.dataset.thumbReady) return;
        const trySeek = () => {
            try {
                if (video.readyState >= 1 && video.duration > 0.2) {
                    video.currentTime = Math.min(0.1, video.duration - 0.05);
                    video.dataset.thumbReady = '1';
                }
            } catch (_) { /* 某些浏览器 seek 到 0 会报错 */ }
        };
        video.addEventListener('loadedmetadata', trySeek, { once: true });
        video.addEventListener('loadeddata', trySeek, { once: true });
        // 兜底：300ms 后强制尝试
        setTimeout(trySeek, 300);
    });
}

/**
 * 点击视频播放：移动端全屏弹窗，PC 端嵌入播放
 */
export function playVideo(app, postId, videoIndex) {
    const item = document.querySelector(`.moment-item[data-id="${postId}"]`);
    if (!item) return;
    const videoItems = item.querySelectorAll('.moment-video-item');
    const target = videoItems[videoIndex];
    if (!target) return;
    const src = target.dataset.videoSrc;
    if (!src) return;

    const isMobile = window.innerWidth < 768 || /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);

    if (isMobile) {
        openFullscreenVideoModal(src);
    } else {
        // PC 端：在原位置替换为可播放 video
        const thumbVideo = target.querySelector('video[data-thumb="1"]');
        const playBtn = target.querySelector('.moment-video-play');
        const durationBadge = target.querySelector('.moment-video-duration');
        if (playBtn) playBtn.remove();
        if (durationBadge) durationBadge.style.display = 'none';

        if (thumbVideo) {
            thumbVideo.remove();
        }
        const video = document.createElement('video');
        video.src = src;
        video.controls = true;
        video.playsInline = true;
        video.setAttribute('webkit-playsinline', '');
        video.setAttribute('x5-playsinline', '');
        video.setAttribute('controlslist', 'nodownload');
        video.disablePictureInPicture = true;
        video.preload = 'auto';
        video.style.width = '100%';
        video.style.height = '100%';
        video.style.objectFit = 'contain';
        video.style.display = 'block';
        video.style.background = '#000';
        video.style.borderRadius = '8px';
        target.appendChild(video);
        video.play().catch(() => {});
    }
}

/**
 * 全屏视频弹窗（移动端体验）
 * 不调用浏览器原生 requestFullscreen，因为那会隐藏 modal 外壳和关闭按钮。
 * 改用 CSS fixed 全屏遮罩，关闭按钮始终可见。
 */
export function openFullscreenVideo(app, src) {
    openFullscreenVideoModal(src);
}

function openFullscreenVideoModal(src) {
    let modal = document.getElementById('video-fullscreen-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'video-fullscreen-modal';
        modal.className = 'video-fullscreen-modal';
        // 结构：inner(容器) > topbar(顶部条) + video(播放器) + bottom-hint(底部提示)
        // 关闭按钮使用 absolute 定位在 topbar 中，z-index 极高确保夸克/QQ浏览器可见
        modal.innerHTML = `
            <div class="video-fullscreen-inner">
                <div class="video-fs-topbar">
                    <button class="video-fs-close" aria-label="关闭" type="button">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                    </button>
                </div>
                <video class="video-fs-player" playsinline webkit-playsinline x5-playsinline controls plays></video>
                <div class="video-fs-bottom-hint">点击空白区域关闭</div>
            </div>
        `;
        document.body.appendChild(modal);
        const closeBtn = modal.querySelector('.video-fs-close');
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            closeFullscreenVideoModal();
        });
        // 点击视频区域以外（即 inner 或 topbar）也可关闭
        modal.querySelector('.video-fullscreen-inner').addEventListener('click', (e) => {
            if (e.target.classList.contains('video-fullscreen-inner') ||
                e.target.classList.contains('video-fs-topbar') ||
                e.target.classList.contains('video-fs-bottom-hint')) {
                closeFullscreenVideoModal();
            }
        });
        // ESC 键关闭（桌面端）
        modal.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeFullscreenVideoModal();
        });
    }
    const player = modal.querySelector('.video-fs-player');
    player.src = src;
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
    player.play().catch(() => {});
}

export function closeFullscreenVideo(app) {
    closeFullscreenVideoModal();
}

function closeFullscreenVideoModal() {
    const modal = document.getElementById('video-fullscreen-modal');
    if (!modal) return;
    const player = modal.querySelector('.video-fs-player');
    if (player) {
        player.pause();
        player.removeAttribute('src');
        player.load();
    }
    modal.classList.remove('active');
    document.body.style.overflow = '';
}

/**
 * 初始化用户主页时间线筛选器
 * 提供分类下拉和日期范围筛选，变更后重新加载时间线
 */
export function initTimelineFilter(app) {
    const container = document.getElementById('timeline-filter');
    if (!container) return;
    if (container.dataset.initialized === '1') return;
    container.dataset.initialized = '1';

    const cfg = app.siteConfig || {};
    const categories = cfg.categories || [];

    const categoryOptions = categories.map(cat =>
        `<option value="${cat.id}">${app.escapeHtml(cat.name)}</option>`
    ).join('');

    container.innerHTML = `
        <div class="timeline-filter-bar" style="display:flex;gap:8px;padding:8px 0;flex-wrap:wrap;align-items:center;">
            <select id="timeline-filter-category" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border,#e5e5e5);background:var(--card-bg,#fff);color:var(--text,#333);font-size:13px;">
                <option value="">全部分类</option>
                ${categoryOptions}
            </select>
            <select id="timeline-filter-date" style="padding:4px 8px;border-radius:4px;border:1px solid var(--border,#e5e5e5);background:var(--card-bg,#fff);color:var(--text,#333);font-size:13px;">
                <option value="">全部时间</option>
                <option value="7d">7天内</option>
                <option value="30d">30天内</option>
            </select>
        </div>
    `;

    const catSelect = document.getElementById('timeline-filter-category');
    const dateSelect = document.getElementById('timeline-filter-date');

    const applyFilter = () => {
        app.timelineFilter = {
            category_id: catSelect ? catSelect.value : '',
            date_range: dateSelect ? dateSelect.value : ''
        };
        app.page = 1;
        app.hasMore = true;
        const list = document.getElementById('moments-list');
        if (list) list.innerHTML = '';
        app.loadTimeline(false);
    };

    if (catSelect) catSelect.addEventListener('change', applyFilter);
    if (dateSelect) dateSelect.addEventListener('change', applyFilter);
}
