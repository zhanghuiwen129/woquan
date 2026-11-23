// ============================================================
// activities.js — 活动管理
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast, toLocalInputValue } from './core.js';



// ===== 活动管理（V79）=====
export let currentActivityId = null;
export let activityCircleLoaded = false;

export async function loadActivities() {
    const typeEl = document.getElementById('activity-filter-type');
    const statusEl = document.getElementById('activity-filter-status');
    const kwEl = document.getElementById('activity-filter-keyword');
    try {
        const params = new URLSearchParams({ limit: 100 });
        if (typeEl && typeEl.value) params.set('type', typeEl.value);
        if (statusEl && statusEl.value) params.set('status', statusEl.value);
        const kw = kwEl ? kwEl.value.trim() : '';
        if (kw) params.set('keyword', kw);

        const data = await request('/admin/activities?' + params.toString());
        const list = data.list || [];
        const statusMap = { open: '报名中', closed: '已截止', ended: '已结束' };
        document.getElementById('activities-table').innerHTML = list.map(a => `
            <tr data-activity-id="${a.id}">
                <td data-label="标题" data-full>${escapeHtml(a.title || '')}</td>
                <td data-label="类型"><span class="badge" style="background:var(--surface-2);color:var(--info);">报名</span></td>
                <td data-label="圈子">${a.circle_name ? escapeHtml(a.circle_name) : '全站'}</td>
                <td data-label="参与">${a.joined_count || 0}</td>
                <td data-label="起止">${(a.start_at || '不限')} ~ ${(a.end_at || '不限')}</td>
                <td data-label="状态"><span class="badge ${a.status === 'open' ? 'badge-success' : 'badge-danger'}">${statusMap[a.status] || a.status}</span></td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-default" data-activity='${escapeHtml(JSON.stringify(a))}' data-action="edit-activity">编辑</button>
                    <button class="btn btn-danger" data-action="delete-activity" data-id="${a.id}">删除</button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;">暂无活动</td></tr>';
    } catch (e) { showToast(e.message); }
}

/** 从按钮上挂着的 JSON 里取出活动数据再进编辑（避免超长参数串） */
export function editActivityFromBtn(btn) {
    let a = null;
    try { a = JSON.parse(btn.getAttribute('data-activity')); } catch (e) {}
    if (!a) { showToast('读取活动数据失败'); return; }
    editActivity(a);
}

/** 拉一次圈子列表填进下拉（0 = 全站活动，仅站点管理员可见） */
export async function ensureActivityCircles(selectedId) {
    const sel = document.getElementById('activity-circle');
    if (!sel) return;
    if (!activityCircleLoaded) {
        try {
            const data = await request('/admin/my-circles');
            const isAdmin = !!(data && data.is_admin);
            const list = (data && data.circles) || [];
            let opts = '';
            if (isAdmin) opts += '<option value="0">全站活动（不限定圈子）</option>';
            opts += list.map(c => `<option value="${c.id}">${escapeHtml(c.name || ('圈子 ID:' + c.id))}</option>`).join('');
            sel.innerHTML = opts || '<option value="0">全站活动（不限定圈子）</option>';
            activityCircleLoaded = true;
        } catch (e) {
            // 拉圈子失败不阻塞创建活动，保留「全站活动」一项
            sel.innerHTML = '<option value="0">全站活动（不限定圈子）</option>';
        }
    }
    sel.value = String(selectedId || 0);
}

export function onActivityTypeChange() {
    const type = document.getElementById('activity-type').value;
    // 活动仅剩报名类型：始终展示人数限制（旧「打卡目标」分组已随功能下线移除）
    document.getElementById('activity-mode-group').classList.remove('hidden');
    if (type !== 'signup') {
        document.getElementById('activity-mode').value = 'free';
        document.getElementById('activity-capacity-group').classList.add('hidden');
    }
}

export function onActivityModeChange() {
    const mode = document.getElementById('activity-mode').value;
    document.getElementById('activity-capacity-group').classList.toggle('hidden', mode !== 'count');
}

export async function openActivityForm() {
    currentActivityId = null;
    document.getElementById('activity-id').value = '';
    document.getElementById('activity-title').value = '';
    document.getElementById('activity-type').value = 'signup';
    document.getElementById('activity-description').value = '';
    document.getElementById('activity-cover').value = '';
    document.getElementById('activity-mode').value = 'free';
    document.getElementById('activity-capacity').value = '';
    document.getElementById('activity-start-at').value = '';
    document.getElementById('activity-end-at').value = '';
    document.getElementById('activity-status').value = 'open';
    document.getElementById('activity-form-title').textContent = '创建活动';
    await ensureActivityCircles(0);
    onActivityTypeChange();
    document.getElementById('activity-form').classList.remove('hidden');
}

export async function editActivity(a) {
    currentActivityId = a.id;
    document.getElementById('activity-id').value = a.id;
    document.getElementById('activity-title').value = a.title || '';
    document.getElementById('activity-type').value = a.type || 'signup';
    document.getElementById('activity-description').value = a.description || '';
    document.getElementById('activity-cover').value = a.cover || '';
    document.getElementById('activity-mode').value = a.mode || 'free';
    document.getElementById('activity-capacity').value = a.capacity || '';
    document.getElementById('activity-start-at').value = toLocalInputValue(a.start_at);
    document.getElementById('activity-end-at').value = toLocalInputValue(a.end_at);
    document.getElementById('activity-status').value = a.status || 'open';
    document.getElementById('activity-form-title').textContent = '编辑活动';
    await ensureActivityCircles(a.circle_id);
    onActivityTypeChange();
    onActivityModeChange();
    document.getElementById('activity-form').classList.remove('hidden');
}

export function closeActivityForm() {
    document.getElementById('activity-form').classList.add('hidden');
}

export async function saveActivity() {
    const body = {
        id: currentActivityId,
        title: document.getElementById('activity-title').value.trim(),
        type: document.getElementById('activity-type').value,
        description: document.getElementById('activity-description').value.trim(),
        cover: document.getElementById('activity-cover').value.trim(),
        circle_id: parseInt(document.getElementById('activity-circle').value || '0', 10),
        mode: document.getElementById('activity-mode').value,
        capacity: parseInt(document.getElementById('activity-capacity').value || '0', 10),
        checkin_goal: 0,
        start_at: document.getElementById('activity-start-at').value,
        end_at: document.getElementById('activity-end-at').value,
        status: document.getElementById('activity-status').value
    };
    if (!body.title) { showToast('请填写活动标题'); return; }
    if (body.mode === 'count' && (!body.capacity || body.capacity <= 0)) { showToast('限人数活动请填写人数上限'); return; }
    try {
        await request('/admin/activities', { method: currentActivityId ? 'PUT' : 'POST', body });
        showToast('保存成功');
        closeActivityForm();
        loadActivities();
    } catch (e) { showToast(e.message); }
}

export async function deleteActivity(id) {
    if (!confirm('确定删除该活动？\n\n该活动的报名记录会一并删除，且无法恢复。')) return;
    try {
        await request('/admin/activities?id=' + id, { method: 'DELETE' });
        showToast('删除成功');
        loadActivities();
    } catch (e) { showToast(e.message); }
}

// B4a 委托化：活动编辑 / 删除
registerActions({
    'edit-activity': (d, el) => editActivityFromBtn(el),
    'delete-activity': (d) => deleteActivity(+d.id),
    // B4b 委托化：静态创建 / 查询 / 表单保存取消
    'open-activity-form': () => openActivityForm(),
    'load-activities': () => loadActivities(),
    'save-activity': () => saveActivity(),
    'close-activity-form': () => closeActivityForm(),
    // B6 委托化：类型/人数联动 / 搜索回车（原 onchange/onkeydown 直调）
    'activity-type-change': () => onActivityTypeChange(),
    'activity-mode-change': () => onActivityModeChange(),
    'enter-activities': (d, el, e) => { if (e.key === 'Enter') loadActivities(); },
});