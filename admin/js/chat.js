// ============================================================
// chat.js — 私信管理：会话、消息、删除台账、清理
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { API_BASE, registerActions, request, showToast, token } from './core.js';



/* ================================================================
 * 私信管理 JS
 * ================================================================ */
export let chatCurrentTab = 'stats';
export let chatCurrentPage = { conversations: 1, messages: 1 };
export let chatSelectedMsgIds = new Set();

export function chatInit() {
    chatSwitchTab('stats');
    chatLoadSettings();
}

export function chatSwitchTab(tab, el) {
    chatCurrentTab = tab;
    document.querySelectorAll('#page-chat .setting-tab').forEach(el => el.classList.remove('active'));
    // B2 委托化：el 由分发器传入；chatInit 内部调用无 el，保持原 event 兜底（无 event 时静默跳过）
    (el || event?.currentTarget)?.classList?.add('active');
    ['stats', 'conversations', 'messages', 'deletions', 'cleanup', 'settings'].forEach(t => {
        const el = document.getElementById('chat-tab-' + t);
        if (el) el.classList.toggle('hidden', t !== tab);
    });
    if (tab === 'stats') chatLoadStats();
    if (tab === 'conversations') loadChatConversations();
    if (tab === 'messages') loadChatMessages();
    if (tab === 'deletions') loadChatDeletions();
}

// B2 委托化：私信 Tab 切换（data-action="chat-switch-tab" data-tab="stats|conversations|..."）
registerActions({
    'chat-switch-tab': (d, el) => chatSwitchTab(d.tab, el),
});

// B3 委托化：私信管理动作注册（模板串 onclick → data-action）
registerActions({
    'chat-view-conv': (d) => chatViewConvMessages(+d.id),
    'chat-delete-conv': (d) => chatDeleteConv(+d.id),
    'chat-restore-msg': (d) => chatRestoreMsg(+d.id),
    'chat-delete-msg': (d) => chatDeleteMsg(+d.id),
    // B4b 委托化：静态搜索 / 批量下架 / 导出 / 清理 / 设置保存重载
    'load-chat-conversations': () => loadChatConversations(),
    'load-chat-messages': () => loadChatMessages(),
    'chat-msg-batch-delete': () => chatMsgBatchDelete(),
    'chat-export-csv': () => chatExportCsv(),
    'load-chat-deletions': () => loadChatDeletions(),
    'chat-cleanup-expired': () => chatCleanupExpired(),
    'chat-cleanup-orphan': () => chatCleanupOrphan(),
    'chat-save-settings': () => chatSaveSettings(),
    'chat-load-settings': () => chatLoadSettings(),
    // B6 委托化：搜索框回车 / 消息勾选（原 onkeypress/onchange 直调）
    'enter-chat-conversations': (d, el, e) => { if (e.key === 'Enter') loadChatConversations(); },
    'enter-chat-messages': (d, el, e) => { if (e.key === 'Enter') loadChatMessages(); },
    'enter-chat-deletions': (d, el, e) => { if (e.key === 'Enter') loadChatDeletions(); },
    'chat-toggle-msg': (d, el) => chatToggleMsg(+d.id, el.checked),
    'chat-check-all-msgs': (d, el) => chatCheckAllMsgs(el),
});

export async function chatLoadStats() {
    try {
        const data = await request('/admin/chat/storage-stats');
        const m = data.messages || {};
        const s = data.storage || {};
        const types = m.by_type || {};
        const typeBadges = Object.entries(types).map(([t, c]) =>
            `<span class="badge badge-success" style="margin-right:6px;">${t}: ${c}</span>`
        ).join('') || '<span style="color:var(--text-muted);">暂无消息</span>';

        document.getElementById('chat-stats-grid').innerHTML = `
            <div class="stat-card"><div class="stat-value">${m.total || 0}</div><div class="stat-label">私信消息总数</div></div>
            <div class="stat-card"><div class="stat-value">${m.conversations || 0}</div><div class="stat-label">会话数</div></div>
            <div class="stat-card"><div class="stat-value">${s.total_mb || 0} MB</div><div class="stat-label">uploads 总占用</div></div>
            <div class="stat-card"><div class="stat-value" style="color:${s.orphan_mb > 10 ? 'var(--danger)' : 'var(--success)'}">${s.orphan_mb || 0} MB</div><div class="stat-label">孤儿文件占用（可清理）</div></div>
        `;

        document.getElementById('chat-dir-stats').innerHTML = `
            <table>
                <thead><tr><th>目录</th><th>大小</th><th>占比</th></tr></thead>
                <tbody>
                    ${Object.entries(s.dirs || {}).map(([d, info]) => {
                        const total = s.total_bytes || 1;
                        const pct = ((info.bytes / total) * 100).toFixed(1);
                        return `<tr><td data-label="目录">uploads/${d}/</td><td data-label="大小">${info.mb} MB</td><td data-label="占比">
                            <div style="background:var(--surface-2);border-radius:4px;height:8px;overflow:hidden;">
                                <div style="height:100%;background:var(--primary);width:${pct}%;"></div>
                            </div><span style="font-size:12px;color:var(--text-muted);">${pct}%</span></td></tr>`;
                    }).join('')}
                </tbody>
            </table>
        `;

        document.getElementById('chat-orphan-info').innerHTML = `
            <div style="margin-bottom:8px;">
                <strong>孤儿文件数：</strong> <span style="color:var(--danger);font-weight:600;">${s.orphan_files || 0}</span> 个，
                占用 <span style="color:var(--danger);font-weight:600;">${s.orphan_mb || 0} MB</span>
            </div>
            <div style="color:var(--text-muted);font-size:12px;">孤儿文件是指物理存在于服务器但未被任何消息、动态、头像引用的文件，可能来自已删除的消息、上传失败、程序 bug 等。建议定期清理。</div>
        `;
    } catch (e) { showToast(e.message); }
}

export async function loadChatConversations(page = 1) {
    chatCurrentPage.conversations = page;
    try {
        const keyword = document.getElementById('chat-conv-search').value;
        const includeDeleted = document.getElementById('chat-conv-include-deleted').checked ? 1 : 0;
        const data = await request('/admin/chat/conversations?page=' + page + '&limit=15&keyword=' + encodeURIComponent(keyword) + '&include_deleted=' + includeDeleted);
        const list = data.list || [];
        const tbody = document.getElementById('chat-convs-table');
        if (!list.length) {
            tbody.innerHTML = '<tr><td style="text-align:center;color:var(--text-muted);">暂无会话记录</td></tr>';
        } else {
            tbody.innerHTML = list.map(c => {
                const memberNames = (c.members || []).map(m => m.nickname || m.username).join(', ');
                const off = c.deleted_at;
                return `<tr${off ? ' style="opacity:.6;"' : ''}>
                    <td data-label="ID">${c.id}${off ? ' <span class="badge" style="background:var(--surface-2);color:var(--danger);">已下架</span>' : ''}</td>
                    <td data-label="成员">${memberNames || '-'}</td>
                    <td data-label="消息数">${c.msg_count}</td>
                    <td data-label="已删消息">${c.deleted_msg_count || 0}</td>
                    <td data-label="最后消息">${c.last_message_at || '-'}</td>
                    <td data-label="创建时间">${c.created_at || '-'}</td>
                    <td data-label="操作" data-actions>
                        <button class="btn btn-default btn-sm" data-action="chat-view-conv" data-id="${c.id}">看消息</button>
                        <button class="btn btn-danger btn-sm" data-action="chat-delete-conv" data-id="${c.id}">下架</button>
                    </td>
                </tr>`;
            }).join('');
        }
        renderChatPagination('chat-convs-pagination', data.pagination, loadChatConversations);
    } catch (e) { showToast(e.message); }
}

export async function chatDeleteConv(id) {
    if (!confirm('确定要下架整个会话吗？会话将从用户端隐藏。\n\n注意：消息不会物理删除，仍可在「删除审计」中查询、导出。')) return;
    try {
        const r = await request('/admin/chat/conversation', { method: 'DELETE', body: { id } });
        showToast(`已下架会话，${r.deleted_messages} 条消息转为不可见（记录保留）`);
        loadChatConversations(chatCurrentPage.conversations);
    } catch (e) { showToast(e.message); }
}

export function chatViewConvMessages(id) {
    chatCurrentTab = 'messages';
    ['stats', 'conversations', 'messages', 'deletions', 'cleanup', 'settings'].forEach(t => {
        const el = document.getElementById('chat-tab-' + t);
        if (el) el.classList.toggle('hidden', t !== 'messages');
    });
    document.getElementById('chat-msg-keyword').value = '';
    document.getElementById('chat-msg-include-deleted').checked = true;
    document.getElementById('chat-msg-deleted-only').checked = false;
    document.getElementById('chat-msg-conv').value = id;
    loadChatMessages();
}

export async function loadChatMessages(page = 1) {
    chatCurrentPage.messages = page;
    chatSelectedMsgIds.clear();
    try {
        const keyword = document.getElementById('chat-msg-keyword').value;
        const type = document.getElementById('chat-msg-type').value;
        const convId = document.getElementById('chat-msg-conv').value;
        const dateFrom = document.getElementById('chat-msg-from').value;
        const dateTo = document.getElementById('chat-msg-to').value;
        const includeDeleted = document.getElementById('chat-msg-include-deleted').checked;
        const deletedOnly = document.getElementById('chat-msg-deleted-only').checked;
        const params = new URLSearchParams({ page, limit: 20 });
        if (keyword) params.set('keyword', keyword);
        if (type) params.set('type', type);
        if (convId) params.set('conversation_id', convId);
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        // 「只看已删除」隐含「含已删除」
        if (includeDeleted || deletedOnly) params.set('include_deleted', '1');
        if (deletedOnly) params.set('deleted_only', '1');

        const data = await request('/admin/chat/messages?' + params.toString());
        const list = data.list || [];
        const tbody = document.getElementById('chat-msgs-table');
        if (!list.length) {
            tbody.innerHTML = '<tr><td style="text-align:center;color:var(--text-muted);" data-full>暂无消息记录</td></tr>';
        } else {
            const typeLabels = { text: '文本', image: '图片', video: '视频', file: '文件' };
            const scopeLabels = { self: '用户自己删', admin: '管理员下架', purge: '系统清理' };
            tbody.innerHTML = list.map(m => {
                let preview = '';
                if (m.type === 'text') preview = m.content ? m.content.substring(0, 60) : '';
                else preview = typeLabels[m.type] || m.type;
                const nickname = m.nickname || m.username || '-';
                const isDeleted = !!m.deleted;
                const deleter = m.deleted_by_nickname || m.deleted_by_username || (m.deleted_by ? ('#' + m.deleted_by) : '');
                const status = isDeleted
                    ? `<span class="badge" style="background:var(--surface-2);color:var(--danger);">${scopeLabels[m.delete_scope] || '已删'}</span>
                       <div style="font-size:12px;color:var(--text-muted);">${deleter} · ${m.deleted_at || ''}</div>`
                    : '<span class="badge badge-success">正常</span>';
                return `<tr${isDeleted ? ' style="opacity:.7;background:var(--surface-2);"' : ''}>
                    <td data-label=""><input type="checkbox" class="chat-msg-check" data-id="${m.id}" data-change="chat-toggle-msg"></td>
                    <td data-label="ID">${m.id}</td>
                    <td data-label="会话">${m.conversation_id}</td>
                    <td data-label="发送者">${nickname}</td>
                    <td data-label="类型">${typeLabels[m.type] || m.type}</td>
                    <td data-label="内容" data-full style="max-width:300px;overflow:hidden;text-overflow:ellipsis;">${preview}</td>
                    <td data-label="状态">${status}</td>
                    <td data-label="时间">${m.created_at || '-'}</td>
                    <td data-label="操作" data-actions>
                        ${isDeleted
                            ? `<button class="btn btn-default btn-sm" data-action="chat-restore-msg" data-id="${m.id}">恢复</button>`
                            : `<button class="btn btn-danger btn-sm" data-action="chat-delete-msg" data-id="${m.id}">下架</button>`}
                    </td>
                </tr>`;
            }).join('');
        }
        document.getElementById('chat-msg-check-all').checked = false;
        renderChatPagination('chat-msgs-pagination', data.pagination, loadChatMessages);
    } catch (e) { showToast(e.message); }
}

export function chatToggleMsg(id, checked) {
    if (checked) chatSelectedMsgIds.add(id);
    else chatSelectedMsgIds.delete(id);
}

export function chatCheckAllMsgs(el) {
    document.querySelectorAll('.chat-msg-check').forEach(cb => {
        cb.checked = el.checked;
        chatToggleMsg(parseInt(cb.dataset.id), el.checked);
    });
}

export async function chatDeleteMsg(id) {
    if (!confirm('确定要下架这条消息吗？\n\n消息不会物理删除，可在「删除审计」里查到原文。')) return;
    try {
        await request('/admin/chat/message', { method: 'DELETE', body: { id } });
        showToast('已下架（记录保留）');
        loadChatMessages(chatCurrentPage.messages);
    } catch (e) { showToast(e.message); }
}

export async function chatRestoreMsg(id) {
    if (!confirm('确定要恢复这条消息吗？恢复后所有会话成员都能看到它。')) return;
    try {
        await request('/admin/chat/restore', { method: 'POST', body: { id } });
        showToast('已恢复');
        loadChatMessages(chatCurrentPage.messages);
    } catch (e) { showToast(e.message); }
}

export async function chatMsgBatchDelete() {
    if (!chatSelectedMsgIds.size) { showToast('请先勾选要下架的消息'); return; }
    if (!confirm(`确定要下架选中的 ${chatSelectedMsgIds.size} 条消息吗？\n\n记录仍会保留，可随时恢复或导出。`)) return;
    try {
        const r = await request('/admin/chat/batch-delete', { method: 'POST', body: { ids: Array.from(chatSelectedMsgIds) }, timeout: 300000 }); // 批量删除放宽到 5 分钟
        showToast(`已下架 ${r.deleted_messages} 条消息（记录保留）`);
        loadChatMessages(chatCurrentPage.messages);
    } catch (e) { showToast(e.message); }
}

/**
 * 导出 CSV：带上 token 直接下载（后端会记一条 chat.export 审计）
 */
export function chatExportCsv() {
    const params = new URLSearchParams();
    const keyword = document.getElementById('chat-msg-keyword').value;
    const type = document.getElementById('chat-msg-type').value;
    const convId = document.getElementById('chat-msg-conv').value;
    const dateFrom = document.getElementById('chat-msg-from').value;
    const dateTo = document.getElementById('chat-msg-to').value;
    if (keyword) params.set('keyword', keyword);
    if (type) params.set('type', type);
    if (convId) params.set('conversation_id', convId);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);

    const url = API_BASE + '/admin/chat/export?' + params.toString();
    fetch(url, { headers: { 'Authorization': 'Bearer ' + token } })
        .then(res => res.blob())
        .then(blob => {
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'chat-export-' + Date.now() + '.csv';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            showToast('已导出（含已删除消息与内容指纹）');
        })
        .catch(() => showToast('导出失败'));
}

export async function loadChatDeletions(page = 1) {
    chatCurrentPage.deletions = page;
    try {
        const keyword = document.getElementById('chat-del-keyword').value;
        const convId = document.getElementById('chat-del-conv').value;
        const operatorId = document.getElementById('chat-del-operator').value;
        const scope = document.getElementById('chat-del-scope').value;
        const params = new URLSearchParams({ page, limit: 30 });
        if (keyword) params.set('keyword', keyword);
        if (convId) params.set('conversation_id', convId);
        if (operatorId) params.set('operator_id', operatorId);
        if (scope) params.set('scope', scope);

        const data = await request('/admin/chat/deletions?' + params.toString());
        const list = data.list || [];
        const tbody = document.getElementById('chat-dels-table');
        const scopeLabels = { self: '用户自己删', admin: '管理员下架', purge: '系统清理', restore: '恢复' };
        if (!list.length) {
            tbody.innerHTML = '<tr><td style="text-align:center;color:var(--text-muted);" data-full>暂无删除记录</td></tr>';
        } else {
            tbody.innerHTML = list.map(d => {
                const snapshot = (d.content_snapshot || '').replace(/</g, '&lt;').substring(0, 120);
                const operator = d.operator_nickname || d.operator_username || ('#' + d.operator_id);
                const roleTag = d.operator_role === 'admin'
                    ? '<span class="badge" style="background:var(--surface-2);color:#d46b08;">管理员</span>'
                    : '<span class="badge badge-success">用户</span>';
                return `<tr>
                    <td data-label="ID">${d.id}</td>
                    <td data-label="消息ID">${d.message_id}</td>
                    <td data-label="会话">${d.conversation_id}</td>
                    <td data-label="操作人">${operator} ${roleTag}</td>
                    <td data-label="类型">${scopeLabels[d.scope] || d.scope}</td>
                    <td data-label="原文快照" data-full style="max-width:320px;word-break:break-all;">${snapshot || '-'}</td>
                    <td data-label="操作时间">${d.created_at || '-'}</td>
                </tr>`;
            }).join('');
        }
        renderChatPagination('chat-dels-pagination', data.pagination, loadChatDeletions);
    } catch (e) { showToast(e.message); }
}

export async function chatCleanupExpired() {
    if (!confirm('确定要清理过期消息吗？\n\n消息会被标记为「已删」并从用户端隐藏，但记录与原文仍保留，可在「删除审计」中查询。')) return;
    try {
        const days = parseInt(document.getElementById('chat-cleanup-days').value) || 90;
        const types = [];
        document.querySelectorAll('.chat-cleanup-type:checked').forEach(cb => types.push(cb.value));
        const r = await request('/admin/chat/cleanup-expired', { method: 'POST', body: { days, types }, timeout: 300000 }); // 历史清理放宽到 5 分钟
        const el = document.getElementById('chat-cleanup-result');
        el.innerHTML = `<span style="color:var(--success);">清理完成：${r.deleted_messages} 条消息已标记为已删（记录保留，附件未删除）</span>`;
        chatLoadStats();
    } catch (e) { showToast(e.message); }
}

export async function chatCleanupOrphan() {
    if (!confirm('确定要扫描并清理孤儿文件吗？')) return;
    try {
        const r = await request('/admin/chat/cleanup-orphan', { method: 'POST', body: {}, timeout: 300000 }); // 孤儿文件扫描放宽到 5 分钟
        const el = document.getElementById('chat-orphan-result');
        el.innerHTML = `<span style="color:var(--success);">清理完成：删除 ${r.deleted_files} 个孤儿文件，释放 ${r.freed_mb} MB 空间</span>`;
        chatLoadStats();
    } catch (e) { showToast(e.message); }
}

export async function chatLoadSettings() {
    try {
        const s = await request('/admin/chat/settings');
        document.getElementById('chat-set-max-image').value = Math.round(s.chat_max_image_size / 1024 / 1024);
        document.getElementById('chat-set-image-width').value = s.chat_max_image_width;
        document.getElementById('chat-set-max-video').value = Math.round(s.chat_max_video_size / 1024 / 1024);
        document.getElementById('chat-set-video-compress').checked = s.chat_video_compress === 1;
        document.getElementById('chat-set-cleanup-days').value = s.chat_cleanup_days;
    } catch (e) { console.warn('chat load settings', e.message); }
}

export async function chatSaveSettings() {
    try {
        const body = {
            chat_max_image_size: parseInt(document.getElementById('chat-set-max-image').value) * 1024 * 1024,
            chat_max_image_width: parseInt(document.getElementById('chat-set-image-width').value),
            chat_max_video_size: parseInt(document.getElementById('chat-set-max-video').value) * 1024 * 1024,
            chat_video_compress: document.getElementById('chat-set-video-compress').checked ? 1 : 0,
            chat_cleanup_days: parseInt(document.getElementById('chat-set-cleanup-days').value),
        };
        await request('/admin/chat/settings', { method: 'PUT', body });
        showToast('设置已保存');
    } catch (e) { showToast(e.message); }
}

export function renderChatPagination(containerId, pagination, callback) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const { page, pages } = pagination;
    let html = '';
    const maxBtns = 7;
    let startPage = Math.max(1, page - Math.floor(maxBtns / 2));
    let endPage = Math.min(pages, startPage + maxBtns - 1);
    if (endPage - startPage + 1 < maxBtns) startPage = Math.max(1, endPage - maxBtns + 1);
    if (startPage > 1) { html += `<button class="page-btn" data-page="1">1</button>`; if (startPage > 2) html += `<span style="padding:0 4px;">...</span>`; }
    for (let i = startPage; i <= endPage; i++) html += `<button class="page-btn ${i === page ? 'active' : ''}" data-page="${i}">${i}</button>`;
    if (endPage < pages) { if (endPage < pages - 1) html += `<span style="padding:0 4px;">...</span>`; html += `<button class="page-btn" data-page="${pages}">${pages}</button>`; }
    container.innerHTML = html;
    container.querySelectorAll('.page-btn').forEach(btn => {
        btn.addEventListener('click', () => callback(parseInt(btn.dataset.page)));
    });
}