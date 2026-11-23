// ============================================================
// points.js — 积分中心：任务、流水、调整、会员授予
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';
import { loadFinance, pointsLogsCurrentPage, renderFinancePagination } from './finance.js';


export let pointTaskEditingId = 0;

// ==================== V68 积分中心管理 ====================

export async function loadPointTasks() {
    try {
        const data = await request('/admin/point-tasks');
        const list = data || [];
        window.__pointTasks = list;
        const typeText = {
            profile: '完善资料', post: '发动态', comment: '发评论', like: '点赞',
            circle_join: '加圈子', follow: '关注好友', signin_days: '签到天数'
        };
        document.getElementById('point-tasks-table').innerHTML = list.map(t => `
            <tr>
                <td data-label="ID">${t.id}</td>
                <td data-label="任务" data-full>${escapeHtml(t.title || '')}</td>
                <td data-label="类型">${typeText[t.task_type] || t.task_type}</td>
                <td data-label="达标">${t.target} 次</td>
                <td data-label="周期">${t.period === 'once' ? '仅一次' : '每日'}</td>
                <td data-label="奖励" style="color:#d47a1a;font-weight:600;">+${t.points}</td>
                <td data-label="排序">${t.sort}</td>
                <td data-label="状态">${t.status == 1 ? '<span style="color:#2e9e5b;">已开启</span>' : '<span style="color:var(--text-muted);">已关闭</span>'}</td>
                <td data-label="操作">
                    <button class="btn btn-default" data-action="open-point-task-form" data-id="${t.id}">编辑</button>
                    <button class="btn btn-default" data-action="do-point-task-delete" data-id="${t.id}" data-title="${escapeHtml(t.title || '')}">删除</button>
                </td>
            </tr>`).join('') || '<tr><td colspan="9" style="text-align:center;color:var(--text-muted);">暂无任务</td></tr>';
    } catch(e) { showToast(e.message); }
}

export async function loadPointsLogs() {
    const type = document.getElementById('points-log-type')?.value || '';
    const keyword = (document.getElementById('points-keyword')?.value || '').trim();
    const params = new URLSearchParams({ page: pointsLogsCurrentPage, limit: 20 });
    if (type) params.set('type', type);
    if (keyword) params.set('keyword', keyword);
    try {
        const data = await request('/admin/points-logs?' + params.toString());
        const list = data.list || [];
        const typeText = { earn: '获得', spend: '消耗', signin: '签到', task: '任务', consume: '兑换', adjust: '后台调整' };
        document.getElementById('points-logs-table').innerHTML = list.map(w => `
            <tr>
                <td data-label="ID">${w.id}</td>
                <td data-label="用户">${escapeHtml(w.nickname || w.username || ('ID:' + w.user_id))}</td>
                <td data-label="积分" style="color:${w.points >= 0 ? '#2e9e5b' : '#d93025'};font-weight:600;">${w.points >= 0 ? '+' : ''}${w.points}</td>
                <td data-label="类型">${typeText[w.type] || w.type}</td>
                <td data-label="业务">${w.biz_type}${w.biz_id ? '#' + w.biz_id : ''}</td>
                <td data-label="变动后">${w.balance_after}</td>
                <td data-label="备注" data-full>${escapeHtml(w.remark || '-')}</td>
                <td data-label="时间">${w.created_at || ''}</td>
            </tr>`).join('') || '<tr><td colspan="8" style="text-align:center;color:var(--text-muted);">暂无积分流水</td></tr>';
        renderFinancePagination(data.pagination, 'points');
    } catch(e) { showToast(e.message); }
}

export function openPointTaskForm(id) {
    pointTaskEditingId = id || 0;
    document.getElementById('pt-title').value = '';
    document.getElementById('pt-desc').value = '';
    document.getElementById('pt-points').value = '100';
    document.getElementById('pt-type').value = 'post';
    document.getElementById('pt-target').value = '1';
    document.getElementById('pt-period').value = 'daily';
    document.getElementById('pt-sort').value = '90';
    document.getElementById('pt-status').value = '1';
    if (id) {
        const t = (window.__pointTasks || []).find(x => x.id === id);
        if (t) {
            document.getElementById('pt-title').value = t.title || '';
            document.getElementById('pt-desc').value = t.desc || '';
            document.getElementById('pt-points').value = t.points;
            document.getElementById('pt-type').value = t.task_type;
            document.getElementById('pt-target').value = t.target;
            document.getElementById('pt-period').value = t.period;
            document.getElementById('pt-sort').value = t.sort;
            document.getElementById('pt-status').value = t.status == 1 ? '1' : '0';
        }
    }
    document.getElementById('point-task-form').classList.remove('hidden');
}

export function closePointTaskForm() {
    document.getElementById('point-task-form').classList.add('hidden');
    pointTaskEditingId = 0;
}

export async function doPointTaskSave() {
    const body = {
        id: pointTaskEditingId,
        title: document.getElementById('pt-title').value.trim(),
        desc: document.getElementById('pt-desc').value.trim(),
        points: parseInt(document.getElementById('pt-points').value, 10) || 0,
        task_type: document.getElementById('pt-type').value,
        target: parseInt(document.getElementById('pt-target').value, 10) || 1,
        period: document.getElementById('pt-period').value,
        sort: parseInt(document.getElementById('pt-sort').value, 10) || 0,
        status: parseInt(document.getElementById('pt-status').value, 10) || 0
    };
    if (!body.title) { showToast('任务标题必填'); return; }
    if (body.points <= 0) { showToast('奖励积分要大于 0'); return; }
    try {
        await request('/admin/point-task-save', { method: 'POST', body: body });
        showToast('已保存');
        closePointTaskForm();
        loadFinance(true);
    } catch(e) { showToast(e.message); }
}

export async function doPointTaskDelete(id, title) {
    if (!confirm('确认删除任务「' + title + '」？用户进度会一并清掉，已发出的积分不会收回。')) return;
    try {
        await request('/admin/point-task-delete', { method: 'POST', body: { id: id } });
        showToast('已删除');
        loadFinance(true);
    } catch(e) { showToast(e.message); }
}

export async function doPointsAdjust() {
    const userId = parseInt(document.getElementById('points-user-id').value, 10);
    const delta = parseInt(document.getElementById('points-delta').value, 10);
    const remark = document.getElementById('points-remark').value.trim();
    if (!userId || userId <= 0) { showToast('用户ID必填'); return; }
    if (!delta || isNaN(delta)) { showToast('积分数量不合法'); return; }
    if (!confirm('确认对用户 #' + userId + (delta >= 0 ? ' 增加 ' : ' 扣除 ') + Math.abs(delta) + ' 积分？')) return;
    try {
        await request('/admin/points-adjust', { method: 'POST', body: { user_id: userId, points: delta, remark: remark } });
        showToast('已调整');
        document.getElementById('points-delta').value = '0';
        document.getElementById('points-remark').value = '';
        loadFinance(true);
    } catch(e) { showToast(e.message); }
}

export async function doWalletAdjust() {
    const userId = parseInt(document.getElementById('wallet-user-id').value, 10);
    const amount = parseFloat(document.getElementById('wallet-amount').value);
    const remark = document.getElementById('wallet-remark').value.trim();
    if (!userId || userId <= 0) { showToast('用户ID必填'); return; }
    if (!amount || isNaN(amount)) { showToast('金额不合法'); return; }
    if (!confirm('确认对用户 #' + userId + (amount >= 0 ? ' 充值 ' : ' 扣款 ') + Math.abs(amount) + ' 元？（会写进钱包流水）')) return;
    try {
        await request('/admin/wallet-adjust', { method: 'POST', body: { user_id: userId, amount: amount, remark: remark } });
        showToast('已调整');
        document.getElementById('wallet-amount').value = '0';
        document.getElementById('wallet-remark').value = '';
        loadFinance(true);
    } catch(e) { showToast(e.message); }
}

export async function doGrantVip() {
    const userId = parseInt(document.getElementById('grant-user-id').value, 10);
    const level = parseInt(document.getElementById('grant-level').value, 10);
    const days = parseInt(document.getElementById('grant-days').value, 10);
    if (!userId || userId <= 0) { showToast('用户ID必填'); return; }
    if (!confirm('确认给用户 #' + userId + ' 开通 Lv' + level + ' 会员 ' + days + ' 天？')) return;
    try {
        await request('/admin/grant-vip', { method: 'POST', body: { user_id: userId, level: level, days: days } });
        showToast('已开通');
        loadFinance(true);
    } catch(e) { showToast(e.message); }
}

// B4a 委托化：积分任务编辑 / 删除
registerActions({
    'open-point-task-form': (d) => openPointTaskForm(+d.id),
    'do-point-task-delete': (d) => doPointTaskDelete(+d.id, d.title),
    // B4b 委托化：静态新增任务 / 表单保存取消 / 积分钱包调整 / VIP 开通
    'do-points-adjust': () => doPointsAdjust(),
    'close-point-task-form': () => closePointTaskForm(),
    'do-point-task-save': () => doPointTaskSave(),
    'do-wallet-adjust': () => doWalletAdjust(),
    'do-grant-vip': () => doGrantVip(),
});