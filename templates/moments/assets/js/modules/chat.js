/**
 * woquan 即时通讯前端模块
 *
 * 功能：
 *   - 会话列表加载与渲染
 *   - 消息历史加载（游标分页向上滚动加载更早消息）
 *   - 发送文本/图片消息
 *   - 轮询拉取新消息（5秒间隔）
 *   - 未读数徽标轮询（30秒间隔，全站生效）
 */

import { API_BASE } from './config.js?v=20260803.5';
import { compressImageFile } from './compress.js?v=20260803.5';
import { renderTextEmoji } from './emoji.js?v=20260906.1';

// 聊天状态
let chatState = {
    currentConversationId: null,
    lastMessageId: 0,
    pollingTimer: null,
    unreadPollingTimer: null,
    loadingMessages: false,
    nextBeforeId: 0,
    hasMoreMessages: true,
    // V98：文本发送失败后恢复输入时复用同一个幂等键，避免重试产生重复消息
    pendingClientMsgId: '',
    pendingContent: ''
};

/**
 * 生成客户端消息幂等键（V98）。
 * 现代浏览器用 crypto.randomUUID()，老浏览器回退到时间戳 + 随机数。
 * @returns {string}
 */
function generateClientMsgId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return Date.now() + '-' + Math.random().toString(36).slice(2);
}

/**
 * 初始化聊天页面
 */
export async function initChatPage(app) {
    // P0-3：登录态看 app.authed，不看 app.token
    // （Cookie 模式下 token 刷新后为 null，用它会让已登录用户被弹登录框）
    if (app.authed === false) {
        app.openAuthModal();
        return;
    }

    // 启动未读数轮询（全站）
    startUnreadPolling(app);

    // 初始进入页面时标记为「未选中会话」：
    // PC 端据此隐藏输入栏并展示空状态；移动端本就只显示列表，无副作用
    showConversationList();

    // 加载会话列表
    await loadChatConversations(app);

    const msgContainer = document.getElementById('chat-messages');

    // 绑定发送按钮
    const sendBtn = document.getElementById('chat-send-btn');
    if (sendBtn) {
        sendBtn.addEventListener('click', () => sendChatMessage(app));
    }
    const input = document.getElementById('chat-input');
    if (input) {
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendChatMessage(app);
            }
        });
    }

    // 图片选择 input
    const imgInput = document.getElementById('chat-image-input');
    if (imgInput) {
        imgInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) sendImageMessage(app, file);
            e.target.value = '';
        });
    }

    // 视频选择 input
    const videoInput = document.getElementById('chat-video-input');
    if (videoInput) {
        videoInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) sendVideoMessage(app, file);
            e.target.value = '';
        });
    }

    // 表情按钮
    const emojiBtn = document.getElementById('chat-emoji-btn');
    if (emojiBtn) {
        emojiBtn.addEventListener('click', (e) => {
            e.preventDefault();
            hideMediaOptionsPanel();
            const inputEl = document.getElementById('chat-input');
            if (inputEl) {
                app.toggleEmojiPicker('chat-input', emojiBtn);
            }
        });
    }

    // + 按钮（更多功能面板）
    const addBtn = document.getElementById('chat-add-btn');
    if (addBtn) {
        addBtn.addEventListener('click', (e) => {
            e.preventDefault();
            app.hideEmojiPanel?.();
            toggleMediaOptionsPanel();
        });
    }

    // 媒体面板选项点击（图片 / 视频）
    const mediaPanel = document.getElementById('chat-media-options');
    if (mediaPanel) {
        mediaPanel.querySelectorAll('.chat-media-item').forEach(item => {
            item.addEventListener('click', () => {
                const action = item.dataset.action;
                if (action === 'image') {
                    imgInput?.click();
                } else if (action === 'video') {
                    videoInput?.click();
                }
                hideMediaOptionsPanel();
            });
        });
    }

    // 点击消息区域 / 输入框时关闭媒体面板
    msgContainer?.addEventListener('click', hideMediaOptionsPanel);
    const chatInput = document.getElementById('chat-input');
    chatInput?.addEventListener('focus', hideMediaOptionsPanel);

    // 返回按钮（移动端）
    const backBtn = document.getElementById('chat-back-btn');
    if (backBtn) {
        backBtn.addEventListener('click', () => {
            stopChatPolling();
            showConversationList();
            // 清除 URL hash，避免返回列表后再次自动进入
            if (window.location.hash) {
                history.replaceState(null, '', window.location.pathname + window.location.search);
            }
        });
    }

    // 滚动加载历史消息
    if (msgContainer) {
        msgContainer.addEventListener('scroll', () => {
            if (msgContainer.scrollTop < 50 && !chatState.loadingMessages && chatState.hasMoreMessages) {
                loadMoreMessages(app);
            }
        });

        // 事件委托：图片点击预览、视频点击全屏
        msgContainer.addEventListener('click', (e) => {
            const imgEl = e.target.closest('.chat-msg-image');
            if (imgEl) {
                e.preventDefault();
                e.stopPropagation();
                const url = imgEl.src;
                if (url && app.openImageViewer) {
                    app.openImageViewer([url], 0);
                }
                return;
            }
            const videoEl = e.target.closest('.chat-msg-video');
            if (videoEl && e.target.closest('.chat-msg-video-play') || videoEl) {
                // 视频区域点击
                const vEl = e.target.closest('.chat-msg-video');
                if (vEl) {
                    e.preventDefault();
                    e.stopPropagation();
                    const videoSrc = vEl.querySelector('video')?.src;
                    if (videoSrc && app.openFullscreenVideo) {
                        app.openFullscreenVideo(videoSrc);
                    }
                }
            }
        });
    }

    // 解析 URL hash，自动打开指定会话（如 /chat#conv=123）
    const hash = window.location.hash;
    if (hash) {
        const match = hash.match(/conv=(\d+)/);
        if (match) {
            const convId = parseInt(match[1], 10);
            if (convId > 0) {
                // 延迟一点确保 DOM 渲染完成
                setTimeout(() => openChatConversation(app, convId), 100);
                return;
            }
        }
    }

    // 发起新会话按钮
    initChatPageNewConversationBtn(app);
}

/**
 * 加载会话列表
 */
export async function loadChatConversations(app) {
    const listEl = document.getElementById('chat-conversation-list');
    if (!listEl) return;

    try {
        const data = await app.request('/chat/conversations');
        if (!data || !data.list) return;

        if (data.list.length === 0) {
            listEl.innerHTML = '<div class="chat-empty">暂无会话，去用户主页发起私聊吧</div>';
            return;
        }

        listEl.innerHTML = data.list.map(conv => {
            const avatar = conv.avatar || (conv.peer?.avatar) || 'assets/images/default-avatar.svg';
            const title = conv.title || (conv.peer?.nickname) || '未知会话';
            // V71：私聊会话标题后跟会员徽章（群聊没有 peer 会员概念，helper 自动返回空串）
            const convVip = conv.peer ? app.vipBadge(conv.peer.vip_level, 14) : '';
            const lastMsg = conv.last_message;
            const preview = lastMsg ? lastMsg.content : '暂无消息';
            const time = formatTime(conv.last_message_at);
            const unreadBadge = conv.unread_count > 0
                ? `<span class="chat-unread-badge">${conv.unread_count > 99 ? '99+' : conv.unread_count}</span>`
                : '';
            const mutedIcon = conv.muted ? '<span class="chat-conv-muted" title="已开启免打扰">🔕</span>' : '';
            const pinnedIcon = conv.pinned ? '<span class="chat-conv-pinned" title="已置顶">📌</span>' : '';
            const pinnedCls = conv.pinned ? ' is-pinned' : '';

            return `
                <div class="chat-conv-item${pinnedCls}" data-id="${conv.id}" data-muted="${conv.muted ? 1 : 0}" data-pinned="${conv.pinned ? 1 : 0}">
                    <div class="chat-conv-avatar">
                        <img src="${app.escapeHtml(avatar)}" onerror="this.src='assets/images/default-avatar.svg'">
                        ${unreadBadge}
                    </div>
                    <div class="chat-conv-info">
                        <div class="chat-conv-header">
                            <span class="chat-conv-name">${app.escapeHtml(title)}${convVip}${pinnedIcon}${mutedIcon}</span>
                            <span class="chat-conv-time">${time}</span>
                        </div>
                        <div class="chat-conv-preview">${app.escapeHtml(preview)}</div>
                    </div>
                </div>
            `;
        }).join('');

        listEl.querySelectorAll('.chat-conv-item').forEach(item => {
            item.addEventListener('click', () => {
                openChatConversation(app, parseInt(item.dataset.id, 10));
            });
            // 长按 / 右键弹出会话设置菜单
            let pressTimer = null;
            const openMenu = (e) => {
                e.preventDefault();
                e.stopPropagation();
                showConvContextMenu(app, item);
            };
            item.addEventListener('contextmenu', openMenu);
            item.addEventListener('touchstart', (e) => {
                pressTimer = setTimeout(() => { if (e.cancelable) e.preventDefault(); openMenu(e); }, 500);
            });
            item.addEventListener('touchend', () => { if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } });
            item.addEventListener('touchmove', () => { if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; } });
        });
    } catch (e) {
        listEl.innerHTML = '<div class="chat-empty">加载失败，请稍后重试</div>';
    }
}

/**
 * 打开会话
 */
export async function openChatConversation(app, conversationId) {
    chatState.currentConversationId = conversationId;
    chatState.lastMessageId = 0;
    chatState.nextBeforeId = 0;
    chatState.hasMoreMessages = true;

    // 显示聊天详情视图
    showChatDetail();

    const titleEl = document.getElementById('chat-detail-title');
    const msgContainer = document.getElementById('chat-messages');
    if (msgContainer) msgContainer.innerHTML = '<div class="chat-loading">加载中...</div>';

    try {
        const data = await app.request(`/chat/messages?conversation_id=${conversationId}&limit=30`);
        if (data && data.list) {
            chatState.lastMessageId = data.list.length > 0 ? data.list[data.list.length - 1].id : 0;
            chatState.hasMoreMessages = data.pagination ? !!data.pagination.has_more : false;
            chatState.nextBeforeId = data.pagination?.next_before_id || 0;
            renderMessages(app, data.list, false);

            // 设置标题
            const conv = await findConversationFromList(app, conversationId);
            if (titleEl && conv) {
                const titleText = conv.title || conv.peer?.nickname || '聊天';
                // V71：聊天标题栏也显示对方会员徽章（textContent 会吞 HTML，故改 innerHTML + 转义）
                titleEl.innerHTML = app.escapeHtml(titleText) + (conv.peer ? app.vipBadge(conv.peer.vip_level, 16) : '');
            }

            // 滚动到底部
            scrollToBottom();

            // 标记已读
            if (chatState.lastMessageId > 0) {
                await app.request('/chat/read', {
                    method: 'PUT',
                    body: { conversation_id: conversationId, message_id: chatState.lastMessageId }
                });
            }

            // 刷新会话列表的未读数
            loadChatConversations(app);

            // 开始轮询新消息
            startChatPolling(app);
        }
    } catch (e) {
        if (msgContainer) msgContainer.innerHTML = '<div class="chat-empty">加载失败</div>';
        app.showToast(e.message);
    }
}

/**
 * 加载更多历史消息（向上翻）
 */
async function loadMoreMessages(app) {
    if (!chatState.currentConversationId || !chatState.hasMoreMessages || chatState.nextBeforeId <= 0) return;

    chatState.loadingMessages = true;

    const msgContainer = document.getElementById('chat-messages');
    const prevScrollHeight = msgContainer ? msgContainer.scrollHeight : 0;

    try {
        const data = await app.request(
            `/chat/messages?conversation_id=${chatState.currentConversationId}&before_id=${chatState.nextBeforeId}&limit=30`
        );
        if (data && data.list && data.list.length > 0) {
            chatState.nextBeforeId = data.pagination?.next_before_id || 0;
            // 最后一页恰好满页时后端可能多给一次 has_more；游标归零即视为没有更早消息
            chatState.hasMoreMessages = !!data.pagination?.has_more && chatState.nextBeforeId > 0;
            renderMessages(app, data.list, true); // prepend = true
            // 保持滚动位置
            if (msgContainer) {
                msgContainer.scrollTop = msgContainer.scrollHeight - prevScrollHeight;
            }
        } else {
            chatState.hasMoreMessages = false;
        }
    } catch (e) {
        // 游标保持不变：下次滚动重试会请求同一页，不跳过任何历史消息
    } finally {
        chatState.loadingMessages = false;
    }
}

/**
 * 发送文本消息
 */
export async function sendChatMessage(app) {
    const input = document.getElementById('chat-input');
    if (!input) return;
    const content = input.value.trim();
    if (!content || !chatState.currentConversationId) return;

    // 失败重试且内容未变时复用上一次的幂等键；内容变了则换一个新键
    if (!chatState.pendingClientMsgId || chatState.pendingContent !== content) {
        chatState.pendingClientMsgId = generateClientMsgId();
    }
    chatState.pendingContent = content;
    const clientMsgId = chatState.pendingClientMsgId;

    input.value = '';

    try {
        const msg = await app.request('/chat/send', {
            method: 'POST',
            body: {
                conversation_id: chatState.currentConversationId,
                content: content,
                type: 'text',
                client_msg_id: clientMsgId
            }
        });

        // 追加到消息列表
        renderMessages(app, [msg], false);
        scrollToBottom();
        chatState.lastMessageId = msg.id;

        // 刷新会话列表
        loadChatConversations(app);

        // 发送成功：幂等键完成使命，下次发送生成新的
        chatState.pendingClientMsgId = '';
        chatState.pendingContent = '';
    } catch (e) {
        app.showToast(e.message);
        input.value = content; // 恢复输入内容，幂等键保留供下次重试复用
    }
}

/**
 * 发送图片消息
 */
async function sendImageMessage(app, file) {
    if (!chatState.currentConversationId) {
        app.showToast('请先选择一个会话');
        return;
    }

    app.showToast('发送中...');

    try {
        // 前端压缩图片
        let uploadFile = file;
        try {
            uploadFile = await compressImageFile(file, { maxWidth: 1280, maxHeight: 1280, quality: 0.8 });
        } catch (e) {
            uploadFile = file;
        }

        const formData = new FormData();
        formData.append('conversation_id', chatState.currentConversationId);
        formData.append('type', 'image');
        formData.append('image', uploadFile);
        formData.append('client_msg_id', generateClientMsgId());

        const res = await fetch(API_BASE + '/chat/send', {
            method: 'POST',
            headers: {
                // P0-3：token 仅内存态，为 null 时不带 Bearer 头走 httpOnly Cookie 鉴权
                ...(app.token ? { 'Authorization': `Bearer ${app.token}` } : {}),
                'X-CSRF-Token': app.csrfToken
            },
            body: formData
        });
        const data = await res.json();
        if (data.code !== 200) throw new Error(data.message);

        renderMessages(app, [data.data], false);
        scrollToBottom();
        chatState.lastMessageId = data.data.id;
        loadChatConversations(app);
        app.showToast('发送成功');
    } catch (e) {
        app.showToast(e.message);
    }
}

/**
 * 发送视频消息
 */
async function sendVideoMessage(app, file) {
    if (!chatState.currentConversationId) {
        app.showToast('请先选择一个会话');
        return;
    }

    if (file.size > 100 * 1024 * 1024) {
        app.showToast('视频不能超过100MB');
        return;
    }

    app.showToast('视频上传中...');

    try {
        const formData = new FormData();
        formData.append('conversation_id', chatState.currentConversationId);
        formData.append('type', 'video');
        formData.append('video', file);
        formData.append('client_msg_id', generateClientMsgId());

        const res = await fetch(API_BASE + '/chat/send', {
            method: 'POST',
            headers: {
                // P0-3：token 仅内存态，为 null 时不带 Bearer 头走 httpOnly Cookie 鉴权
                ...(app.token ? { 'Authorization': `Bearer ${app.token}` } : {}),
                'X-CSRF-Token': app.csrfToken
            },
            body: formData
        });
        const data = await res.json();
        if (data.code !== 200) throw new Error(data.message);

        renderMessages(app, [data.data], false);
        scrollToBottom();
        chatState.lastMessageId = data.data.id;
        loadChatConversations(app);
        app.showToast('发送成功');
    } catch (e) {
        app.showToast(e.message);
    }
}

/**
 * 渲染消息
 * @param {Array} messages 消息列表
 * @param {boolean} prepend 是否插入到顶部（加载历史消息时为 true）
 */
function renderMessages(app, messages, prepend) {
    const container = document.getElementById('chat-messages');
    if (!container) return;

    const html = messages.map(msg => renderMessageHTML(msg, app)).join('');

    if (prepend) {
        container.insertAdjacentHTML('afterbegin', html);
    } else {
        // 移除加载提示
        const loading = container.querySelector('.chat-loading, .chat-empty');
        if (loading) loading.remove();
        container.insertAdjacentHTML('beforeend', html);
    }
}

function renderMessageHTML(msg, app) {
    const isSelf = msg.is_self;
    const currentUserId = app?.user?.id || app?.currentUser?.id;
    const isSentByCurrentUser = isSelf || (msg.sender_id && currentUserId && msg.sender_id === currentUserId);
    const avatar = msg.sender?.avatar || 'assets/images/default-avatar.svg';
    const time = formatTime(msg.created_at);

    let contentHTML = '';
    if (msg.type === 'image' && msg.extra?.url) {
        const imgUrl = app.escapeHtml(msg.extra.url);
        const imgUrlJs = app.escapeJs(msg.extra.url);
        contentHTML = `<img class="chat-msg-image" src="${imgUrl}" onclick="window.app?.openImageViewer?.(['${imgUrlJs}'], 0)">`;
    } else if (msg.type === 'video' && msg.extra?.url) {
        const videoUrl = app.escapeHtml(msg.extra.url);
        const videoUrlJs = app.escapeJs(msg.extra.url);
        const poster = msg.extra?.poster ? app.escapeHtml(msg.extra.poster) : '';
        contentHTML = `
            <div class="chat-msg-video" onclick="window.app?.openFullscreenVideo?.('${videoUrlJs}')">
                <video src="${videoUrl}" preload="metadata" muted playsinline webkit-playsinline x5-playsinline${poster ? ` poster="${poster}"` : ''}></video>
                <div class="chat-msg-video-play">
                    <svg viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg>
                </div>
            </div>
        `;
    } else if (msg.type === 'system') {
        return `<div class="chat-msg-system">${app.escapeHtml(msg.content)}</div>`;
    } else {
        const renderedContent = renderTextEmoji(app.escapeHtml(msg.content));
        contentHTML = `<div class="chat-msg-bubble">${renderedContent}</div>`;
    }

    // 已读 / 未读回执（仅对自己发送的消息显示）
    let readReceiptHtml = '';
    if (isSentByCurrentUser && msg.type !== 'system') {
        if (msg.read_at) {
            readReceiptHtml = '<span class="chat-msg-read chat-msg-read-seen" style="color:var(--text-tertiary,#999);font-size:11px;margin-top:2px;display:block;text-align:right;">已读</span>';
        } else {
            readReceiptHtml = '<span class="chat-msg-read chat-msg-read-unseen" style="color:var(--text-secondary,#ccc);font-size:11px;margin-top:2px;display:block;text-align:right;opacity:0.6;">未读</span>';
        }
    }

    return `
        <div class="chat-msg ${isSelf ? 'chat-msg-self' : 'chat-msg-other'}">
            <img class="chat-msg-avatar" src="${app.escapeHtml(avatar)}" onerror="this.src='assets/images/default-avatar.svg'">
            <div class="chat-msg-body">
                ${contentHTML}
                <span class="chat-msg-time">${time}</span>
                ${readReceiptHtml}
            </div>
        </div>
    `;
}

/**
 * 启动新消息轮询
 */
function startChatPolling(app) {
    stopChatPolling();
    chatState.pollingTimer = setInterval(async () => {
        if (!chatState.currentConversationId) return;
        // 页面不可见时跳过轮询，节省电量和流量
        if (document.hidden) return;
        try {
            const data = await app.request(
                `/chat/new-messages?conversation_id=${chatState.currentConversationId}&last_message_id=${chatState.lastMessageId}`
            );
            if (data && data.list && data.list.length > 0) {
                renderMessages(app, data.list, false);
                scrollToBottom();
                chatState.lastMessageId = data.list[data.list.length - 1].id;
                loadChatConversations(app);
            }
        } catch (e) {
            // 静默失败，下次轮询重试
        }
    }, 5000);

    // 页面恢复可见时立即拉取一次新消息
    if (!chatState.visibilityBound) {
        chatState.visibilityBound = true;
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && chatState.currentConversationId) {
                // 立即检查一次新消息
                app.request(
                    `/chat/new-messages?conversation_id=${chatState.currentConversationId}&last_message_id=${chatState.lastMessageId}`
                ).then(data => {
                    if (data && data.list && data.list.length > 0) {
                        renderMessages(app, data.list, false);
                        scrollToBottom();
                        chatState.lastMessageId = data.list[data.list.length - 1].id;
                        loadChatConversations(app);
                    }
                }).catch(() => {});
            }
        });
    }
}

function stopChatPolling() {
    if (chatState.pollingTimer) {
        clearInterval(chatState.pollingTimer);
        chatState.pollingTimer = null;
    }
}

/**
 * 启动未读数轮询（全站，用于显示徽标）
 */
export function startUnreadPolling(app) {
    if (chatState.unreadPollingTimer) return;
    if (app.authed === false) return;

    const fetchUnread = async () => {
        // 页面不可见时跳过轮询
        if (document.hidden) return;
        try {
            const data = await app.request('/chat/unread-count');
            if (data) {
                const count = data.count || 0;
                app.chatUnread = count;
                updateUnreadBadge(count);
                // 同步刷新底部导航"消息"tab 合并角标
                if (typeof app.updateMessagesBadge === 'function') {
                    app.updateMessagesBadge();
                }
            }
        } catch (e) {
            // 静默
        }
    };

    fetchUnread();
    chatState.unreadPollingTimer = setInterval(fetchUnread, 30000);

    // 页面恢复可见时立即拉取一次未读数
    if (!chatState.unreadVisibilityBound) {
        chatState.unreadVisibilityBound = true;
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) fetchUnread();
        });
    }
}

function updateUnreadBadge(count) {
    const badge = document.getElementById('chat-unread-badge');
    if (badge) {
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'inline-flex';
        } else {
            badge.style.display = 'none';
        }
    }
}

/**
 * 从会话列表中查找指定会话
 */
async function findConversationFromList(app, convId) {
    // 从已渲染的 DOM 中获取
    const item = document.querySelector(`.chat-conv-item[data-id="${convId}"]`);
    if (item) {
        const name = item.querySelector('.chat-conv-name')?.textContent || '';
        return { title: name };
    }
    return null;
}

/**
 * 视图切换
 */
function showConversationList() {
    const listView = document.getElementById('chat-list-view');
    const detailView = document.getElementById('chat-detail-view');
    if (listView) listView.style.display = '';
    // 只切 data-active 属性，display 交给 CSS 按断点控制（避免内联样式覆盖 @media）
    if (detailView) detailView.dataset.active = '0';
}

function showChatDetail() {
    const listView = document.getElementById('chat-list-view');
    const detailView = document.getElementById('chat-detail-view');
    // 移动端（<1024px）：隐藏列表，只保留详情；PC 端：列表 + 详情双栏保留
    const isMobile = window.innerWidth < 1024;
    if (listView && isMobile) listView.style.display = 'none';
    // 详情区显示由 CSS 通过 [data-active="1"] 决定，这里只切状态
    if (detailView) detailView.dataset.active = '1';
}

function scrollToBottom() {
    const container = document.getElementById('chat-messages');
    if (container) {
        container.scrollTop = container.scrollHeight;
    }
}

function formatTime(timeStr) {
    if (!timeStr) return '';
    const date = new Date(timeStr.replace(' ', 'T'));
    const now = new Date();
    const diff = now - date;
    if (diff < 60000) return '刚刚';
    if (diff < 3600000) return Math.floor(diff / 60000) + '分钟前';
    if (date.toDateString() === now.toDateString()) {
        return date.getHours().toString().padStart(2, '0') + ':' + date.getMinutes().toString().padStart(2, '0');
    }
    return (date.getMonth() + 1) + '/' + date.getDate();
}

/**
 * 初始化消息中心页（通知 + 最近会话快捷入口）
 */
export async function initMessagesPage(app) {
    // 启动未读数轮询（私信未读）
    startUnreadPolling(app);

    // 绑定消息标签页切换
    if (typeof app.bindMessagesTabs === 'function') {
        app.bindMessagesTabs();
    }

    // 通知列表（默认 tab=notify，直接加载）
    app.notificationPage = 1;
    app.notificationHasMore = true;
    await app.loadNotifications(false);

    // 私信会话：默认当前是通知面板，若用户主动切到 chat 再懒加载；这里标记为未加载
    app._messagesChatLoaded = false;

    // 绑定全部已读按钮
    const markAll = document.getElementById('btn-mark-all-read');
    if (markAll && !markAll._bound) {
        markAll._bound = true;
        markAll.addEventListener('click', () => app.markAllNotificationsRead());
    }
}

/**
 * 加载消息中心页中显示的最近会话列表（点击跳转到 /chat 对应会话或直接进入私信页）
 */
export async function loadMessagesPageConversations(app, limit = 5) {
    const listEl = document.getElementById('messages-chat-list');
    if (!listEl) return;

    try {
        const data = await app.request('/chat/conversations');
        const all = (data && data.list) ? data.list : [];
        const list = all.slice(0, limit);

        if (list.length === 0) {
            listEl.innerHTML = '<div class="empty-state">暂无会话，去用户主页发起私聊吧</div>';
            return;
        }

        const base = (typeof window.HUBLOG_PAGE_BASE === 'string') ? window.HUBLOG_PAGE_BASE : '';
        listEl.innerHTML = list.map(conv => {
            const avatar = conv.avatar || (conv.peer?.avatar) || 'assets/images/default-avatar.svg';
            const title = conv.title || (conv.peer?.nickname) || (conv.peer?.username) || '未知会话';
            const convVip = conv.peer ? app.vipBadge(conv.peer.vip_level, 14) : '';
            const username = conv.peer?.username || '';
            const lastMsg = conv.last_message;
            const preview = lastMsg ? lastMsg.content : '暂无消息';
            const time = formatTime(conv.last_message_at);
            const unreadBadge = conv.unread_count > 0
                ? `<span class="chat-unread-badge">${conv.unread_count > 99 ? '99+' : conv.unread_count}</span>`
                : '';

            return `
                <div class="chat-conv-item" data-id="${conv.id}" data-username="${app.escapeHtml(username)}">
                    <div class="chat-conv-avatar">
                        <img src="${app.escapeHtml(avatar)}" onerror="this.src='assets/images/default-avatar.svg'">
                        ${unreadBadge}
                    </div>
                    <div class="chat-conv-info">
                        <div class="chat-conv-header">
                            <span class="chat-conv-name">${app.escapeHtml(title)}${convVip}</span>
                            <span class="chat-conv-time">${time}</span>
                        </div>
                        <div class="chat-conv-preview">${app.escapeHtml(preview)}</div>
                    </div>
                    <div class="chat-conv-arrow">›</div>
                </div>
            `;
        }).join('');

        listEl.querySelectorAll('.chat-conv-item').forEach(item => {
            item.addEventListener('click', () => {
                const convId = item.dataset.id;
                const url = base + '/chat#conv=' + encodeURIComponent(convId);
                window.location.assign(url);
            });
        });
    } catch (e) {
        listEl.innerHTML = '<div class="empty-state">加载失败</div>';
    }
}

export async function startPrivateChat(app, userIdOrUsername) {
    try {
        let userId;
        if (typeof userIdOrUsername === 'number') {
            userId = userIdOrUsername;
        } else if (typeof userIdOrUsername === 'string' && /^\d+$/.test(userIdOrUsername)) {
            userId = parseInt(userIdOrUsername, 10);
        } else {
            const profile = await app.request('/user/profile?username=' + encodeURIComponent(userIdOrUsername));
            if (!profile || !profile.user || !profile.user.id) {
                throw new Error('未找到该用户');
            }
            userId = profile.user.id;
        }

        const data = await app.request('/chat/private', {
            method: 'POST',
            body: { user_id: userId }
        });

        if (!data || !data.conversation_id) {
            throw new Error('创建会话失败');
        }

        const base = (typeof window.HUBLOG_PAGE_BASE === 'string') ? window.HUBLOG_PAGE_BASE : '';
        location.assign(base + '/chat#conv=' + encodeURIComponent(data.conversation_id));
    } catch (e) {
        app.showToast(e.message || '发起私信失败');
    }
}

export function initChatPageNewConversationBtn(app) {
    const btn = document.getElementById('btn-new-chat');
    if (!btn) return;
    btn.addEventListener('click', () => {
        const username = window.prompt('请输入要私信的用户名：');
        if (!username || !username.trim()) return;
        app.startPrivateChat(username.trim());
    });
}

/**
 * 显示会话右键/长按菜单（置顶、免打扰）
 */
function showConvContextMenu(app, itemEl) {
    const convId = parseInt(itemEl.dataset.id, 10);
    const isMuted = itemEl.dataset.muted === '1';
    const isPinned = itemEl.dataset.pinned === '1';
    // 移除已有菜单
    document.querySelectorAll('.conv-context-menu').forEach(m => m.remove());

    const menu = document.createElement('div');
    menu.className = 'conv-context-menu';
    menu.innerHTML = `
        <button class="conv-menu-item" data-action="pin">${isPinned ? '取消置顶' : '置顶会话'}</button>
        <button class="conv-menu-item" data-action="mute">${isMuted ? '关闭免打扰' : '开启免打扰'}</button>
    `;
    document.body.appendChild(menu);

    // 定位
    const rect = itemEl.getBoundingClientRect();
    const menuW = 160, menuH = 96;
    let top = rect.top;
    let left = rect.right - menuW - 8;
    if (top + menuH > window.innerHeight) top = window.innerHeight - menuH - 8;
    if (left < 8) left = 8;
    menu.style.top = top + 'px';
    menu.style.left = left + 'px';
    menu.classList.add('show');

    const close = () => { menu.remove(); document.removeEventListener('click', onDocClick); document.removeEventListener('touchstart', onDocClick); };
    const onDocClick = (e) => { if (!menu.contains(e.target)) close(); };
    setTimeout(() => {
        document.addEventListener('click', onDocClick);
        document.addEventListener('touchstart', onDocClick);
    }, 0);

    menu.querySelectorAll('.conv-menu-item').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            const action = btn.dataset.action;
            close();
            try {
                let body = { conversation_id: convId };
                if (action === 'pin') body.pinned = isPinned ? 0 : 1;
                if (action === 'mute') body.muted = isMuted ? 0 : 1;
                const r = await app.request('/chat/settings', { method: 'PUT', body });
                app.showToast(action === 'pin' ? (isPinned ? '已取消置顶' : '已置顶') : (isMuted ? '已关闭免打扰' : '已开启免打扰'));
                // 刷新列表
                await loadChatConversations(app);
            } catch (err) {
                app.showToast(err.message || '设置失败');
            }
        });
    });
}

/**
 * 切换媒体功能面板（图片 / 视频）
 */
function toggleMediaOptionsPanel() {
    const panel = document.getElementById('chat-media-options');
    if (!panel) return;
    if (panel.style.display === 'none') {
        panel.style.display = 'block';
        // 平滑展开
        requestAnimationFrame(() => {
            panel.classList.add('chat-media-options--open');
        });
    } else {
        hideMediaOptionsPanel();
    }
}

/**
 * 隐藏媒体功能面板
 */
function hideMediaOptionsPanel() {
    const panel = document.getElementById('chat-media-options');
    if (!panel) return;
    panel.classList.remove('chat-media-options--open');
    // 等待过渡结束后隐藏
    const onEnd = () => {
        panel.style.display = 'none';
        panel.removeEventListener('transitionend', onEnd);
    };
    panel.addEventListener('transitionend', onEnd);
    // 兜底：200ms 后强制隐藏
    setTimeout(() => { panel.style.display = 'none'; }, 220);
}

/**
 * 文档点击时关闭媒体面板
 */
document.addEventListener('click', (e) => {
    const panel = document.getElementById('chat-media-options');
    const addBtn = document.getElementById('chat-add-btn');
    if (!panel) return;
    if (panel.style.display !== 'none' && !panel.contains(e.target) && !addBtn?.contains(e.target)) {
        hideMediaOptionsPanel();
    }
}, true);
