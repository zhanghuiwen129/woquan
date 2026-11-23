// ============================================================
// appeals.js — 申诉管理（App 我的-设置-我的申诉记录 提交的申诉）
// 后端：GET /api/admin/appeals、POST /api/admin/appeal-handle
// ============================================================
import { escapeHtml, registerActions, renderPagination, request, showToast, BASE_URL } from './core.js';

// ============ 申诉管理 ============
export let appealState = { status: '', category: '', keyword: '', page: 1 };

const APPEAL_CATEGORY_MAP = {
    falseblock: '账号误封',
    forbidden: '内容误下架',
    misreview: '审核有误',
    other: '其他'
};
const APPEAL_TYPE_MAP = { post: '动态', comment: '评论', user: '用户', circle: '圈子' };
const APPEAL_STATUS_MAP = {
    pending: '<span style="color:var(--warn);">待处理</span>',
    reviewing: '<span style="color:var(--info);">处理中</span>',
    resolved: '<span style="color:var(--success);">已解决</span>',
    rejected: '<span style="color:var(--text-muted);">已驳回</span>'
};

/** 申诉对象跳转链接（跳到前台对应页面） */
export function appealTargetLink(t) {
    if (!t) return '-';
    const label = `${APPEAL_TYPE_MAP[t.type] || t.type} #${t.id}：${escapeHtml(t.label || '')}`;
    let url = '';
    if (t.type === 'post') url = `${BASE_URL}/post.html?id=${t.id}`;
    else if (t.type === 'comment' && t.post_id) url = `${BASE_URL}/post.html?id=${t.post_id}`;
    else if (t.type === 'user') url = `${BASE_URL}/user.html?id=${t.id}`;
    else if (t.type === 'circle') url = `${BASE_URL}/circle.html?id=${t.id}`;
    if (!url) return `<span title="${t.type} #${t.id}">${label}${t.status !== undefined ? ` <span style="font-size:12px;color:var(--text-muted);">（现状：${escapeHtml(String(t.status))}）</span>` : ''}</span>`;
    return `<a href="${url}" target="_blank" style="color:var(--info);">${label} ↗</a>${t.status !== undefined ? ` <span style="font-size:12px;color:var(--text-muted);">（现状：${escapeHtml(String(t.status))}）</span>` : ''}`;
}

export function switchAppealTab(status) {
    appealState.status = String(status);
    appealState.page = 1;
    document.querySelectorAll('.appeal-tab').forEach(el => {
        el.classList.toggle('active', el.dataset.status === String(status));
    });
    loadAppeals(1);
}

// B2 委托化：申诉 Tab 切换（data-action="switch-appeal-tab"，函数内部统一 String() 处理）
registerActions({
    'switch-appeal-tab': (d) => switchAppealTab(d.status),
});

// B3 委托化：申诉处理动作注册（模板串 onclick → data-action）
registerActions({
    'handle-appeal': (d) => handleAppeal(+d.id, d.value),
    // B4b 委托化：静态搜索按钮
    'search-appeals': () => searchAppeals(),
});

export function searchAppeals() {
    appealState.category = document.getElementById('appeal-category-filter').value;
    appealState.keyword = document.getElementById('appeal-keyword').value.trim();
    loadAppeals(1);
}

export async function loadAppeals(page = 1) {
    appealState.page = page;
    try {
        const qs = new URLSearchParams();
        if (appealState.status) qs.set('status', appealState.status);
        if (appealState.category) qs.set('category', appealState.category);
        if (appealState.keyword) qs.set('keyword', appealState.keyword);
        qs.set('page', page);
        qs.set('limit', 15);
        const data = await request(`/admin/appeals?${qs.toString()}`);
        const list = data.list || [];
        const pagination = data.pagination || { page, pages: 0, total: 0 };

        document.getElementById('appeals-table').innerHTML = list.length ? list.map(r => `
            <tr>
                <td data-label="ID">${r.id}</td>
                <td data-label="申诉人">
                    ${r.avatar ? `<img src="${escapeHtml(r.avatar)}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;vertical-align:middle;margin-right:6px;">` : ''}
                    ${escapeHtml(r.nickname || r.username || `用户#${r.user_id}`)}
                    <br><span style="font-size:12px;color:var(--text-muted);">@${escapeHtml(r.username || r.user_id)}</span>
                </td>
                <td data-label="类型">${APPEAL_CATEGORY_MAP[r.category] || r.category}</td>
                <td data-label="申诉对象" style="max-width:220px;">${appealTargetLink(r.target)}</td>
                <td data-label="申诉说明" style="max-width:260px;">
                    <div style="white-space:pre-wrap;word-break:break-word;font-size:13px;">${escapeHtml(r.content || '')}</div>
                    ${(r.images || []).length ? `<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">${r.images.map(img => `<a href="${escapeHtml(img)}" target="_blank"><img src="${escapeHtml(img)}" style="width:44px;height:44px;object-fit:cover;border-radius:4px;"></a>`).join('')}</div>` : ''}
                </td>
                <td data-label="状态">${APPEAL_STATUS_MAP[r.status] ?? escapeHtml(r.status)}</td>
                <td data-label="时间" style="font-size:12px;">${r.created_at || '-'}${r.handled_at ? `<br><span style="color:var(--text-muted);">处理：${r.handled_at}</span>` : ''}</td>
                <td data-label="操作" data-actions style="max-width:200px;">
                    ${r.status === 'pending' ? `<button class="btn btn-default btn-sm" data-action="handle-appeal" data-id="${r.id}" data-value="reviewing">开始处理</button>` : ''}
                    ${r.status === 'pending' || r.status === 'reviewing' ? `
                    <button class="btn btn-primary btn-sm" data-action="handle-appeal" data-id="${r.id}" data-value="resolved">解决</button>
                    <button class="btn btn-default btn-sm" data-action="handle-appeal" data-id="${r.id}" data-value="rejected">驳回</button>` : ''}
                    ${r.status === 'resolved' || r.status === 'rejected' ? `<span style="color:var(--text-muted);font-size:12px;">${r.admin_note ? '备注：' + escapeHtml(r.admin_note) : '已处理'}</span>` : ''}
                </td>
            </tr>
        `).join('') : '<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">暂无申诉记录</td></tr>';

        renderPagination('appeals-pagination', pagination, loadAppeals);
    } catch (e) {
        showToast(e.message);
    }
}

/**
 * 处理申诉：reviewing 不需要备注；resolved/rejected 用 prompt 填写处理备注（会随系统通知发给申诉人）
 */
export async function handleAppeal(id, status) {
    let note = '';
    if (status === 'resolved' || status === 'rejected') {
        const tip = status === 'resolved'
            ? '处理备注（将随系统通知发给申诉人，必填）：'
            : '驳回理由（将随系统通知发给申诉人，必填）：';
        const def = status === 'resolved' ? '经核实，已恢复相关内容/账号，感谢反馈。' : '经核实，原处理无误，感谢理解。';
        const input = prompt(tip, def);
        if (input === null) return; // 用户取消
        if (input.trim() === '') { showToast(status === 'resolved' ? '解决时必须填写处理备注' : '驳回时必须填写驳回理由'); return; }
        note = input.trim();
    }
    try {
        await request('/admin/appeal-handle', { method: 'POST', body: { id, status, admin_note: note.trim() } });
        showToast(status === 'reviewing' ? '已标记为处理中' : status === 'resolved' ? '已解决并通知申诉人' : '已驳回并通知申诉人');
        loadAppeals(appealState.page);
    } catch (e) {
        showToast(e.message);
    }
}
