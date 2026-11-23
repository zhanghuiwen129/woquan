// ============================================================
// reports.js — 举报管理
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, renderPagination, request, showToast } from './core.js';



// ============ 举报管理 ============
export let reportState = { status: -1, page: 1 };

export function switchReportTab(status) {
    reportState.status = status;
    reportState.page = 1;
    document.querySelectorAll('.report-tab').forEach(el => {
        el.classList.toggle('active', parseInt(el.dataset.status) === status);
    });
    loadReports(1);
}

// B2 委托化：举报 Tab 切换（data-action="switch-report-tab"，data-status 为数字串需转 number）
registerActions({
    'switch-report-tab': (d) => switchReportTab(+d.status),
});

export async function loadReports(page = 1) {
    reportState.page = page;
    try {
        const data = await request(`/admin/reports?status=${reportState.status}&page=${page}&limit=15`);
        const list = data.list || [];
        const pagination = data.pagination || { page, pages: 0, total: 0 };

        const reasonMap = { spam: '垃圾广告', porn: '色情低俗', abuse: '辱骂攻击', other: '其他' };
        const typeMap = { post: '动态', comment: '评论', user: '用户' };
        const statusMap = { 0: '<span style="color:var(--warn);">待处理</span>', 1: '<span style="color:var(--success);">已处理</span>', 2: '<span style="color:var(--text-muted);">已驳回</span>' };

        document.getElementById('reports-table').innerHTML = list.length ? list.map(r => `
            <tr>
                <td data-label="ID">${r.id}</td>
                <td data-label="举报人">${escapeHtml(r.reporter_nickname || r.reporter_username)}<br><span style="font-size:12px;color:var(--text-muted);">@${escapeHtml(r.reporter_username)}</span></td>
                <td data-label="对象类型">${typeMap[r.target_type] || r.target_type}</td>
                <td data-label="对象ID">${r.target_id}</td>
                <td data-label="原因">${escapeHtml(reasonMap[r.reason] || r.reason)}</td>
                <td data-label="补充说明" data-full style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${escapeHtml(r.description || '')}">${escapeHtml(r.description || '-')}</td>
                <td data-label="状态">${statusMap[r.status] ?? r.status}</td>
                <td data-label="时间" style="font-size:12px;">${r.created_at || '-'}</td>
                <td data-label="操作" data-actions>
                    ${r.status === 0 ? `
                    <button class="btn btn-primary btn-sm" data-action="resolve-report" data-id="${r.id}" data-value="1">通过</button>
                    <button class="btn btn-default btn-sm" data-action="resolve-report" data-id="${r.id}" data-value="2">驳回</button>
                    ` : '<span style="color:var(--text-muted);font-size:12px;">已处理</span>'}
                </td>
            </tr>
        `).join('') : '<tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">暂无举报记录</td></tr>';

        renderPagination('reports-pagination', pagination, loadReports);
    } catch (e) {
        showToast(e.message);
    }
}

export async function resolveReport(id, status) {
    if (!confirm(status === 1 ? '确认将该举报标记为「已处理」？' : '确认将该举报标记为「已驳回」？')) return;
    try {
        await request('/admin/reports/resolve', { method: 'PUT', body: { id, status } });
        showToast(status === 1 ? '已标记为已处理' : '已驳回');
        loadReports(reportState.page);
    } catch (e) {
        showToast(e.message);
    }
}

// B4a 委托化：举报处理
registerActions({
    'resolve-report': (d) => resolveReport(+d.id, +d.value),
});