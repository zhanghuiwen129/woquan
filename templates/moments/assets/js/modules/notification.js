/**
 * woquan 通知系统
 *
 * P3-19 解耦：未读数变化时通过 EventBus 发布事件，
 * 便于其他模块（如消息角标、桌面通知、声音提醒）订阅而不修改本模块。
 */
import { formatTimeAgo } from './utils.js?v=20261024.1';
import { bus } from './events-bus.js?v=20260820.1';

// 已消费事件的 id 持久化 key。
// 【为什么需要它】后端 SsePush 的事件队列消费后不清空，只按 lastEventId 过滤
// （api/core/SsePush.php:167 poll 只返回 id > lastEventId 的事件）。
// 此前 H5 用 EventSource 连 /sse 时不保存 lastEventId，重连后归零，队列里的
// 历史 notification / chat_message 会被整批重推一遍——未读角标凭空 +N。
// 现改为每 5 秒轮询 GET /app/events，沿用同一份 localStorage 进度，只取增量。
const LAST_EVENT_ID_KEY = 'hublog_sse_last_event_id';

// 事件轮询参数：常规 5s；连续失败按 5s→10s→20s→30s 退避，成功后复位。
const EVENTS_POLL_INTERVAL = 5000;
const EVENTS_POLL_MAX_BACKOFF = 30000;

function readLastEventId() {
    try {
        const v = Number(localStorage.getItem(LAST_EVENT_ID_KEY));
        return Number.isFinite(v) && v > 0 ? v : 0;
    } catch (e) {
        return 0;
    }
}

function writeLastEventId(id) {
    try {
        if (id > 0) localStorage.setItem(LAST_EVENT_ID_KEY, String(id));
    } catch (e) {}
}

// 「实时通道」是否该继续运行。initSSE 置 sseConnected=true，登出/401 置 false；
// 同时校验 authed，确保登录态失效后立刻停轮询，不再空转。
function isPollingActive(app) {
    return app.sseConnected === true && app.authed !== false;
}

function scheduleNext(app, delay) {
    if (app.eventsPollTimer) clearTimeout(app.eventsPollTimer);
    app.eventsPollTimer = null;
    if (!isPollingActive(app)) return;
    app.eventsPollTimer = setTimeout(() => {
        app.eventsPollTimer = null;
        tick(app);
    }, delay);
}

function stopEventPolling(app) {
    if (app.eventsPollTimer) clearTimeout(app.eventsPollTimer);
    app.eventsPollTimer = null;
    app.eventsPollInFlight = false;
    app.eventsPollBackoff = EVENTS_POLL_INTERVAL;
    app.eventsPollFailures = 0;
    app.sseConnected = false;
}

// 单次心跳：页面不可见时跳过请求，只挂下一次检查；可见时立即拉一次。
function tick(app) {
    if (!isPollingActive(app)) return;
    if (document.hidden) {
        scheduleNext(app, EVENTS_POLL_INTERVAL);
        return;
    }
    pollEventsOnce(app);
}

// 用响应里的权威未读数校正角标，避免事件丢失导致漂移。
function applyUnreadCounts(app, data) {
    if (!data) return;
    if (typeof data.unread_notifications === 'number') {
        app.notificationUnread = data.unread_notifications;
        app.updateNotifyBadge();
    }
    if (typeof data.unread_messages === 'number') {
        app.chatUnread = data.unread_messages;
        if (typeof app.updateMessagesBadge === 'function') app.updateMessagesBadge();
    }
}

// 把 /app/events 返回的增量事件按名字分发，行为对齐原 SSE 监听器。
function dispatchEvent(app, event) {
    if (!event || !event.event) return;
    const data = event.data || {};
    if (event.event === 'notification') {
        if (typeof data.count === 'number') app.notificationUnread = data.count;
        app.updateNotifyBadge();
    } else if (event.event === 'chat_message' || event.event === 'message') {
        if (typeof data.count === 'number') app.chatUnread = data.count;
        if (typeof app.updateMessagesBadge === 'function') app.updateMessagesBadge();
        // P3-19：发布聊天未读数变化事件
        bus.emit('unread:chat-changed', app.chatUnread);
    }
    // typing / call_* 等事件由 app-client（uni-app）侧订阅，H5 主题此前未处理，保持一致。
}

async function pollEventsOnce(app) {
    if (!isPollingActive(app)) return;
    if (app.eventsPollInFlight) return; // 防并发：上次没回来不发下一次
    app.eventsPollInFlight = true;
    try {
        const lastId = readLastEventId();
        // app.request 内部拼上 API_BASE 并携带同源 httpOnly Cookie；
        // 不往 URL 塞 token（与原有实时通道的 Cookie 鉴权语义一致）。
        const data = await app.request(`/app/events?last_event_id=${lastId}`);

        if (!isPollingActive(app)) return; // 请求期间被登出 / 登录失效
        app.eventsPollFailures = 0;
        app.eventsPollBackoff = EVENTS_POLL_INTERVAL;

        const events = (data && data.events) || [];
        events.forEach((e) => dispatchEvent(app, e));

        // 权威未读数最后覆盖，避免事件里自带的旧 count 造成角标漂移。
        applyUnreadCounts(app, data);

        // 仅在拿到更大的 id 时推进进度，避免回退导致历史事件重推。
        const maxId = Number(data && data.last_event_id);
        if (Number.isFinite(maxId) && maxId > lastId) writeLastEventId(maxId);
    } catch (e) {
        if (!isPollingActive(app)) return;
        // 401 / 登录态失效：停止轮询，不再重试。
        if (e && (e.code === 401 || e.statusCode === 401)) {
            stopEventPolling(app);
            return;
        }
        app.eventsPollFailures = (app.eventsPollFailures || 0) + 1;
        app.eventsPollBackoff = Math.min(
            EVENTS_POLL_MAX_BACKOFF,
            EVENTS_POLL_INTERVAL * Math.pow(2, Math.min(app.eventsPollFailures, 3))
        );
    } finally {
        app.eventsPollInFlight = false;
        if (isPollingActive(app)) scheduleNext(app, app.eventsPollBackoff || EVENTS_POLL_INTERVAL);
    }
}

/**
 * 兼容原有入口：启动「实时通道」时复用同一条事件轮询，
 * 不再单独起 30 秒未读轮询，避免两条定时器并存。
 */
export function startNotificationPolling(app) {
    initSSE(app);
}

/**
 * 原 SSE 初始化入口，现改为启动 /app/events 事件轮询。
 * 函数名与 app.sseConnected 字段对外保持不变，避免改动 app.js / auth.js。
 * 服务端 /sse 端点保留给尚未更新 JS 的老客户端。
 */
export function initSSE(app) {
    // P0-3：登录态看 app.authed，不看 app.token（Cookie 模式下 token 刷新后恒为 null）
    if (app.authed === false) return;

    // 兼容历史字段：不再创建长连接，仅清理可能残留的旧连接 / 定时器。
    if (app.sse) {
        try { app.sse.close(); } catch (_) {}
        app.sse = null;
    }
    if (app.sseRetryTimer) {
        clearTimeout(app.sseRetryTimer);
        app.sseRetryTimer = null;
    }

    app.sseConnected = true;
    if (app.eventsPollBackoff == null) app.eventsPollBackoff = EVENTS_POLL_INTERVAL;

    // app.js 的登出处理器只清 sseRetryTimer / sse，不会清本模块的定时器；
    // 这里订阅 bus 在登出时立即停轮询（带 flag 防止重复绑定）。
    if (!app._eventsPollLogoutBound) {
        app._eventsPollLogoutBound = true;
        bus.on('auth:logout', () => stopEventPolling(app));
    }
    // 页面恢复可见时立即补拉一次（tick 内部会处理隐藏跳过）。
    if (!app._eventsPollVisibilityBound) {
        app._eventsPollVisibilityBound = true;
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && isPollingActive(app)) pollEventsOnce(app);
        });
    }

    // initSSE 与 startNotificationPolling 都会调到这里，已在轮询就不重复启动。
    if (app.eventsPollTimer || app.eventsPollInFlight) return;
    tick(app);
}

export async function loadUnreadCount(app) {
    if (app.authed === false) return;
    try {
        const data = await app.request('/notifications/unread-count');
        app.notificationUnread = data?.count || 0;
        updateNotifyBadge(app);
    } catch (e) {
        // 静默失败
    }
}

export function updateNotifyBadge(app) {
    const unread = app.notificationUnread || 0;
    const show = unread > 0;
    const text = unread > 99 ? '99+' : String(unread);

    // 顶部导航栏通知铃铛徽章（index/post 页）
    const badge = document.getElementById('notify-badge');
    if (badge) {
        if (show) {
            badge.textContent = text;
            badge.classList.add('active');
        } else {
            badge.classList.remove('active');
        }
    }

    // PC 侧边栏「通知」菜单项徽章（所有带左侧栏的页面）
    const sidebarBadge = document.getElementById('sidebar-notify-badge');
    if (sidebarBadge) {
        if (show) {
            sidebarBadge.textContent = text;
            sidebarBadge.style.display = 'inline-flex';
        } else {
            sidebarBadge.style.display = 'none';
        }
    }

    // 同步刷新底部导航角标与消息中心快捷入口角标
    if (typeof app.updateMessagesBadge === 'function') {
        app.updateMessagesBadge();
    }
    // P3-19：发布通知未读数变化事件，便于其他模块订阅
    bus.emit('unread:notification-changed', app.notificationUnread);
}

export async function openNotificationModal(app) {
    if (!app.checkAuth()) return;
    app.notificationPage = 1;
    app.notificationHasMore = true;
    await loadNotifications(app);
    document.getElementById('notification-modal')?.classList.add('active');
}

export async function loadNotifications(app, append = false) {
    if (app.authed === false) return;
    const listEl = document.getElementById('notification-list');
    if (!listEl) return;
    if (!append) listEl.innerHTML = '<div class="empty-state">加载中...</div>';

    try {
        const data = await app.request(`/notifications?page=${app.notificationPage}&limit=15`);
        const list = data?.list || [];
        app.notificationHasMore = list.length === 15;

        if (!append) app.notifications = list;
        else app.notifications = app.notifications.concat(list);

        app.notificationUnread = data?.unread || 0;
        app.updateNotifyBadge();

        if (app.notifications.length === 0) {
            listEl.innerHTML = '<div class="empty-state">暂无通知</div>';
            hideNotifyLoadMore(app);
            return;
        }

        // 使用 DocumentFragment 批量插入，减少重排
        const frag = document.createDocumentFragment();
        const tempDiv = document.createElement('div');
        app.notifications.forEach((n, idx) => {
            tempDiv.innerHTML = buildNotificationItem(app, n, idx);
            while (tempDiv.firstChild) frag.appendChild(tempDiv.firstChild);
        });
        listEl.innerHTML = '';
        listEl.appendChild(frag);
        showNotifyLoadMore(app, app.notificationHasMore);

        // 消息中心页面：滚动到底部加载更多
        const moreBtn = document.getElementById('msg-notify-more');
        const onScrollBottom = () => {
            const scrollBottom = Math.abs(listEl.scrollTop + listEl.clientHeight - listEl.scrollHeight) < 50;
            if (scrollBottom && typeof window === 'object') {
                // 仅处理容器自身；当 notification-list 内嵌在页面中时，也检测页面滚动
            }
        };
        // 若存在"加载更多"按钮（消息中心页），绑定按钮点击，避免和无限滚动冲突
        if (moreBtn) {
            moreBtn.onclick = () => {
                if (app.notificationHasMore && !app.notificationLoading) {
                    app.notificationPage++;
                    loadMoreNotifications(app);
                }
            };
            // 页面级滚动加载（更适合移动端长列表）
            bindPageScrollNotify(app, listEl);
        } else {
            // Modal 场景：用容器内滚动加载
            listEl.onscroll = () => {
                if (listEl.scrollTop + listEl.clientHeight >= listEl.scrollHeight - 20) {
                    if (app.notificationHasMore && !app.notificationLoading) {
                        app.notificationPage++;
                        loadMoreNotifications(app);
                    }
                }
            };
        }
    } catch (e) {
        if (!append) listEl.innerHTML = '<div class="empty-state">加载失败</div>';
        app.showToast(e.message);
    }
}

function bindPageScrollNotify(app, listEl) {
    if (app._notifyPageScrollBound) return;
    app._notifyPageScrollBound = true;
    window.addEventListener('scroll', () => {
        if (!document.getElementById('msg-notify-more')) return;
        const rect = listEl.getBoundingClientRect();
        if ((rect.bottom - window.innerHeight) < 120) {
            if (app.notificationHasMore && !app.notificationLoading) {
                app.notificationPage++;
                loadMoreNotifications(app);
            }
        }
    }, { passive: true });
}

function showNotifyLoadMore(app, visible) {
    const moreBtn = document.getElementById('msg-notify-more');
    if (!moreBtn) return;
    moreBtn.style.display = visible ? '' : 'none';
}
function hideNotifyLoadMore(app) {
    showNotifyLoadMore(app, false);
}

export async function loadMoreNotifications(app) {
    app.notificationLoading = true;
    try {
        const data = await app.request(`/notifications?page=${app.notificationPage}&limit=15`);
        const list = data?.list || [];
        app.notificationHasMore = list.length === 15;
        const startIdx = app.notifications.length;
        app.notifications = app.notifications.concat(list);

        const listEl = document.getElementById('notification-list');
        if (listEl) {
            // 使用 DocumentFragment 批量追加，减少重排
            const frag = document.createDocumentFragment();
            const tempDiv = document.createElement('div');
            list.map((n, i) => buildNotificationItem(app, n, startIdx + i)).forEach(html => {
                tempDiv.innerHTML = html;
                while (tempDiv.firstChild) frag.appendChild(tempDiv.firstChild);
            });
            listEl.appendChild(frag);
        }
        const moreBtn = document.getElementById('msg-notify-more');
        if (moreBtn) {
            moreBtn.style.display = app.notificationHasMore ? '' : 'none';
        }
    } catch (e) {
        app.showToast(e.message);
    } finally {
        app.notificationLoading = false;
    }
}

export function buildNotificationItem(app, n, idx) {
    const senderName = app.escapeHtml(n.sender?.nickname || n.sender?.username || '有人');
    // V71：通知里的操作人也显示会员徽章
    const senderVip = app.vipBadge(n.sender?.vip_level, 14);
    let text = '';
    let clickAction = '';
    if (n.type === 'like') {
        text = `<strong>${senderName}</strong>${senderVip} 赞了你的动态`;
    } else if (n.type === 'reply') {
        text = `<strong>${senderName}</strong>${senderVip} 回复了你的评论`;
    } else if (n.type === 'follow') {
        text = `<strong>${senderName}</strong>${senderVip} 关注了你`;
    } else if (n.type === 'mention') {
        text = `<strong>${senderName}</strong>${senderVip} 在动态中 @了你`;
    } else if (n.type === 'chat') {
        text = `<strong>${senderName}</strong>${senderVip} 给你发了一条私信`;
        // 私信通知点击跳转到对应会话
        const convId = n.conversation_id || '';
        const base = (typeof window.HUBLOG_PAGE_BASE === 'string') ? window.HUBLOG_PAGE_BASE : '';
        clickAction = convId ? `onclick="app.handleNotificationClick(${n.id}, ${idx}, '${base}/chat#conv=${convId}')"` : '';
    } else {
        text = `<strong>${senderName}</strong>${senderVip} 评论了你的动态`;
    }
    if (!clickAction) {
        clickAction = n.post_id ? `onclick="app.handleNotificationClick(${n.id}, ${idx}, '', ${n.post_id})"` : (n.sender?.username ? `onclick="app.handleNotificationClick(${n.id}, ${idx}, '', 0, '${app.escapeJs(n.sender.username)}')"` : '');
    }
    const content = n.content ? `<div class="notification-content">${app.escapeHtml(n.content)}</div>` : '';
    const time = formatTimeAgo(n.created_at);
    return `
        <div class="notification-item ${n.is_read ? '' : 'unread'}" data-idx="${idx}" data-id="${n.id}" data-post-id="${n.post_id || ''}" ${clickAction}>
            <img src="${app.escapeHtml(app.resolveAvatar(n.sender?.avatar))}" class="notification-avatar" alt="" loading="lazy">
            <div class="notification-body">
                <div class="notification-text">${text}</div>
                ${content}
                <div class="notification-meta">
                    <span class="notification-dot"></span>
                    <span>${time}</span>
                </div>
            </div>
            <div class="notification-actions">
                ${n.is_read ? '' : `<button onclick="event.stopPropagation(); app.markNotificationRead(${n.id}, ${idx})">标为已读</button>`}
                <button onclick="event.stopPropagation(); app.deleteNotification(${n.id})">删除</button>
            </div>
        </div>
    `;
}

/**
 * 点击通知项：自动标记已读 + 跳转
 */
export function handleNotificationClick(app, id, idx, url, postId = 0, username = '') {
    // 未读则标记为已读
    if (app.notifications[idx] && !app.notifications[idx].is_read) {
        app.markNotificationRead(id, idx);
    }
    // 跳转
    if (url) {
        window.location.assign(url);
    } else if (postId) {
        app.goToPost(postId);
    } else if (username) {
        app.goUser(username);
    }
}

export async function markNotificationRead(app, id, idx) {
    try {
        await app.request(`/notifications/${id}/read`, { method: 'PUT' });
        if (app.notifications[idx]) {
            app.notifications[idx].is_read = 1;
        }
        app.notificationUnread = Math.max(0, app.notificationUnread - 1);
        app.updateNotifyBadge();
        renderNotificationItem(app, idx);
    } catch (e) {
        app.showToast(e.message);
    }
}

export async function markAllNotificationsRead(app) {
    try {
        await app.request('/notifications/read-all', { method: 'PUT' });
        app.notifications.forEach(n => n.is_read = 1);
        app.notificationUnread = 0;
        app.updateNotifyBadge();
        loadNotifications(app, false);
        app.showToast('已全部标记为已读');
    } catch (e) {
        app.showToast(e.message);
    }
}

export async function deleteNotification(app, id) {
    try {
        await app.request(`/notifications/${id}`, { method: 'DELETE' });
        app.notifications = app.notifications.filter(n => n.id !== id);
        loadUnreadCount(app);
        loadNotifications(app, false);
    } catch (e) {
        app.showToast(e.message);
    }
}

export function renderNotificationItem(app, idx) {
    const item = document.querySelector(`.notification-item[data-idx="${idx}"]`);
    if (!item) return;
    const n = app.notifications[idx];
    if (!n) return;
    const actions = item.querySelector('.notification-actions');
    item.classList.toggle('unread', !n.is_read);
    if (actions && n.is_read) {
        actions.innerHTML = `<button onclick="event.stopPropagation(); app.deleteNotification(${n.id})">删除</button>`;
    }
}
