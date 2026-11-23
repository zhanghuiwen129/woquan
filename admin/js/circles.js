// ============================================================
// circles.js — 圈子管理：成员、移交、入圈申请
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { BASE_URL, escapeHtml, escapeJs, registerActions, request, showToast } from './core.js';

// B3 委托化：圈子管理动作注册（模板串 onclick → data-action）
registerActions({
    'edit-circle': (d) => editCircle(+d.id),
    'open-circle-members': (d) => openCircleMembersPanel(+d.id, d.name),
    'moderate-circle': (d) => moderateCircle(+d.id, d.value),
    'delete-circle': (d) => deleteCircle(+d.id),
    'load-circles': (d) => loadCircles(+d.page),
    'set-circle-member-role': (d) => setCircleMemberRole(+d.id, d.value),
    'transfer-circle-owner': (d) => transferCircleOwner(+d.id),
    'remove-circle-member': (d) => removeCircleMember(+d.id),
    'approve-circle-request': (d) => approveCircleRequest(+d.id),
    'reject-circle-request': (d) => rejectCircleRequest(+d.id),
    'load-circle-requests': (d) => loadCirclePendingRequests(currentRequestsCircleId, +d.page),
    'load-circle-members': (d) => loadCircleMembers(+d.page),
    // B4b 委托化：静态添加 / 表单保存取消 / 成员面板按钮（原 currentMembersCircleId 是不存在的全局，点击即报错，此处置修复）
    'open-circle-form': () => openCircleForm(),
    'save-circle': () => saveCircle(),
    'close-circle-form': () => closeCircleForm(),
    'open-circle-pending-requests': () => loadCirclePendingRequests(membersCurrentCircleId, 1),
    'close-circle-members-panel': () => closeCircleMembersPanel(),
    'close-circle-requests-panel': () => closeCircleRequestsPanel(),
    // B6 委托化：付费方式联动 / 圈子搜索回车（原 onchange/onkeydown 直调）
    'circle-pay-type-change': () => onCirclePayTypeChange(),
    'enter-circles': (d, el, e) => { if (e.key === 'Enter') loadCircles(); },
});



// ============ V49 圈子管理 ============
export let circlesCurrentPage = 1;
export let circlesCurrentLimit = 20;
export let membersCurrentCircleId = null;
export let membersCurrentPage = 1;
export let membersCurrentLimit = 50;

export async function loadCircles(page) {
    if (page) circlesCurrentPage = page;
    else circlesCurrentPage = 1;
    const keyword = (document.getElementById('circles-search-input')?.value || '').trim();
    try {
        const params = new URLSearchParams({ page: circlesCurrentPage, limit: circlesCurrentLimit });
        if (keyword) params.set('keyword', keyword);
        const data = await request('/admin/circles?' + params.toString());
        const list = data.list || [];
        const visText = { public: '公开', protected: '受保护', private: '私密' };
        const visBadge = { public: 'badge-success', protected: 'badge-warning', private: 'badge-danger' };
        const joinText = { free: '自由', approval: '需审核', invite: '仅邀请' };
        const payText = { 0: '免费', 1: 'Lv门槛', 2: '付费' };
        const payBadge = { 0: 'badge-default', 1: 'badge-warning', 2: 'badge-primary' };
        document.getElementById('circles-table').innerHTML = list.map(c => {
            const vis = c.visibility || 'public';
            const jm = c.join_mode || 'free';
            const payType = Number(c.pay_type || 0);
            return `
            <tr data-circle-id="${c.id}" data-circle='${escapeHtml(JSON.stringify({id:c.id,name:c.name,description:c.description||'',cover:c.cover||'',owner_id:c.owner_id,member_count:c.member_count,post_count:c.post_count,status:c.status,visibility:vis,join_mode:jm,post_permission:c.post_permission||'all',pay_type:payType,pay_vip_level:c.pay_vip_level||1,pay_amount:c.pay_amount||0,pay_days:c.pay_days||0}))}'>
                <td data-label="ID">${c.id}</td>
                <td data-label="封面">${c.cover ? `<img src="${escapeHtml(c.cover)}" alt="" style="width:48px;height:32px;object-fit:cover;border-radius:4px;">` : '<span style="color:var(--text-muted);">无</span>'}</td>
                <td data-label="名称">${escapeHtml(c.name || '')}</td>
                <td data-label="圈主">${escapeHtml(c.owner_nickname || c.owner_username || ('ID:' + c.owner_id))}</td>
                <td data-label="简介" data-full>${escapeHtml(c.description || '-')}</td>
                <td data-label="可见性"><span class="badge ${visBadge[vis] || 'badge-default'}">${visText[vis] || vis}</span></td>
                <td data-label="加入方式">${joinText[jm] || jm}</td>
                <td data-label="付费"><span class="badge ${payBadge[payType] || 'badge-default'}">${payText[payType] || '免费'}${payType === 2 ? ' ¥' + (c.pay_amount || 0) : (payType === 1 ? ' Lv' + (c.pay_vip_level || 1) : '')}</span></td>
                <td data-label="成员">${c.member_count || 0}</td>
                <td data-label="动态">${c.post_count || 0}</td>
                <td data-label="状态"><span class="badge ${c.status===1?'badge-success':'badge-danger'}">${c.status===1?'启用':'禁用'}</span>${Number(c.limited)===1?' <span class="badge badge-warning">限流</span>':''}${c.removed_at?' <span class="badge badge-danger">已下架</span>':''}</td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-default" data-action="edit-circle" data-id="${c.id}">编辑</button>
                    <button class="btn btn-default" data-action="open-circle-members" data-id="${c.id}" data-name="${escapeHtml(c.name || '')}">成员</button>
                    ${Number(c.limited)===1
                        ? `<button class="btn btn-default" data-action="moderate-circle" data-id="${c.id}" data-value="unlimit">解除限流</button>`
                        : `<button class="btn btn-default" data-action="moderate-circle" data-id="${c.id}" data-value="limit">限流</button>`}
                    ${c.removed_at
                        ? `<button class="btn btn-default" data-action="moderate-circle" data-id="${c.id}" data-value="restore">恢复上架</button>`
                        : `<button class="btn btn-danger" data-action="moderate-circle" data-id="${c.id}" data-value="remove">下架</button>`}
                    <button class="btn btn-danger" data-action="delete-circle" data-id="${c.id}">删除</button>
                </td>
            </tr>`;
        }).join('') || '<tr><td colspan="11" style="text-align:center;padding:40px;">暂无圈子</td></tr>';
        renderCirclesPagination(data);
    } catch(e) { showToast(e.message); }
}

export function renderCirclesPagination(data) {
    const total = data.total || 0;
    const page = data.page || 1;
    const limit = data.limit || circlesCurrentLimit;
    const totalPages = Math.max(1, Math.ceil(total / limit));
    const el = document.getElementById('circles-pagination');
    if (!el) return;
    el.innerHTML = `
        <span style="font-size:13px;color:var(--text-secondary);">共 ${total} 个圈子 · 第 ${page}/${totalPages} 页</span>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-default" ${page<=1?'disabled':''} data-action="load-circles" data-page="${page-1}">上一页</button>
            <button class="btn btn-default" ${page>=totalPages?'disabled':''} data-action="load-circles" data-page="${page+1}">下一页</button>
        </div>`;
}

export function openCircleForm() {
    document.getElementById('circle-id').value = '';
    document.getElementById('circle-name').value = '';
    document.getElementById('circle-description').value = '';
    document.getElementById('circle-cover').value = '';
    document.getElementById('circle-owner-id').value = '';
    document.getElementById('circle-status').checked = true;
    document.getElementById('circle-join-mode').value = 'free';
    document.getElementById('circle-post-permission').value = 'all';
    document.getElementById('circle-visibility').value = 'public';
    document.getElementById('circle-pay-type').value = '0';
    document.getElementById('circle-pay-vip-level').value = '1';
    document.getElementById('circle-pay-amount').value = '0';
    onCirclePayTypeChange();
    document.getElementById('circle-form-title').textContent = '添加圈子';
    document.getElementById('circle-form').classList.remove('hidden');
    document.getElementById('circle-members-panel').classList.add('hidden');
    document.getElementById('circle-requests-panel')?.classList.add('hidden');
}

export function closeCircleForm() {
    document.getElementById('circle-form').classList.add('hidden');
}

/** V67：付费方式一变，只显示对应的那组输入，别让管理员填了个用不上的价格 */
export function onCirclePayTypeChange() {
    const t = document.getElementById('circle-pay-type').value;
    document.getElementById('circle-pay-vip-box').style.display = (t === '1') ? '' : 'none';
    document.getElementById('circle-pay-amount-box').style.display = (t === '2') ? '' : 'none';
}

export function editCircle(id) {
    let circle = null;
    const row = document.querySelector('#circles-table tr[data-circle-id="' + id + '"]');
    if (row) {
        try { circle = JSON.parse(row.getAttribute('data-circle') || '{}'); } catch(e) {}
    }
    if (!circle) return;
    document.getElementById('circle-id').value = id;
    document.getElementById('circle-name').value = circle.name || '';
    document.getElementById('circle-description').value = circle.description || '';
    document.getElementById('circle-cover').value = circle.cover || '';
    document.getElementById('circle-owner-id').value = '';
    document.getElementById('circle-status').checked = (circle.status === 1);
    document.getElementById('circle-join-mode').value = circle.visibility ? (circle.join_mode || 'free') : 'free';
    document.getElementById('circle-join-mode').value = circle.join_mode || 'free';
    document.getElementById('circle-post-permission').value = circle.post_permission || 'all';
    document.getElementById('circle-visibility').value = circle.visibility || 'public';
    document.getElementById('circle-pay-type').value = String(circle.pay_type ?? 0);
    document.getElementById('circle-pay-vip-level').value = String(circle.pay_vip_level ?? 1);
    document.getElementById('circle-pay-amount').value = circle.pay_amount ?? 0;
    document.getElementById('circle-pay-days').value = circle.pay_days ?? 0;
    onCirclePayTypeChange();
    document.getElementById('circle-form-title').textContent = '编辑圈子';
    document.getElementById('circle-form').classList.remove('hidden');
    document.getElementById('circle-members-panel').classList.add('hidden');
    document.getElementById('circle-requests-panel')?.classList.add('hidden');
    // 拉详情补全 owner_id（用公开的 /circles/detail 接口，无需 admin 权限）
    request('/circles/detail?id=' + id).then(c => {
        if (c) {
            document.getElementById('circle-cover').value = c.cover || '';
            document.getElementById('circle-owner-id').value = c.owner_id || '';
            document.getElementById('circle-owner-id').placeholder = '原圈主ID：' + (c.owner_id || '');
            // 详情接口的字段更权威，覆盖一次
            document.getElementById('circle-join-mode').value = c.join_mode || 'free';
            document.getElementById('circle-post-permission').value = c.post_permission || 'all';
            document.getElementById('circle-visibility').value = c.visibility || 'public';
        }
    }).catch(() => {});
}

export async function saveCircle() {
    const name = document.getElementById('circle-name').value.trim();
    if (!name) { showToast('圈子名称不能为空'); return; }
    const id = document.getElementById('circle-id').value;
    const ownerId = parseInt(document.getElementById('circle-owner-id').value, 10);
    if (!ownerId || ownerId <= 0) { showToast('圈主用户ID必填'); return; }
    const body = {
        name: name,
        description: document.getElementById('circle-description').value.trim(),
        cover: document.getElementById('circle-cover').value.trim(),
        owner_id: ownerId,
        status: document.getElementById('circle-status').checked ? 1 : 0,
        join_mode: document.getElementById('circle-join-mode').value,
        post_permission: document.getElementById('circle-post-permission').value,
        visibility: document.getElementById('circle-visibility').value,
        pay_type: parseInt(document.getElementById('circle-pay-type').value, 10) || 0,
        pay_vip_level: parseInt(document.getElementById('circle-pay-vip-level').value, 10) || 1,
        pay_amount: parseFloat(document.getElementById('circle-pay-amount').value) || 0,
        pay_days: parseInt(document.getElementById('circle-pay-days').value, 10) || 0
    };
    try {
        if (id) {
            body.id = parseInt(id, 10);
            await request('/admin/circles', {method:'PUT', body});
        } else {
            await request('/admin/circles', {method:'POST', body});
        }
        showToast('保存成功');
        closeCircleForm();
        loadCircles();
    } catch(e) { showToast(e.message); }
}

export async function deleteCircle(id) {
    if (!confirm('删除圈子将清除所有成员关系，圈内动态会回归全站信息流（保留不删除）。确定删除？')) return;
    try {
        await request('/admin/circles?id=' + id, {method:'DELETE'});
        showToast('删除成功');
        loadCircles();
    } catch(e) { showToast(e.message); }
}

export function openCircleMembersPanel(circleId, circleName) {
    membersCurrentCircleId = circleId;
    membersCurrentPage = 1;
    document.getElementById('members-circle-name').textContent = circleName || ('#' + circleId);
    document.getElementById('circle-members-panel').classList.remove('hidden');
    document.getElementById('circle-form').classList.add('hidden');
    document.getElementById('circle-requests-panel')?.classList.add('hidden');
    loadCircleMembers();
}

export function closeCircleMembersPanel() {
    document.getElementById('circle-members-panel').classList.add('hidden');
    membersCurrentCircleId = null;
}

export async function loadCircleMembers(page) {
    if (!membersCurrentCircleId) return;
    if (page) membersCurrentPage = page;
    try {
        const params = new URLSearchParams({
            id: membersCurrentCircleId,
            page: membersCurrentPage,
            limit: membersCurrentLimit
        });
        const data = await request('/admin/circles/members?' + params.toString());
        const list = data.list || [];
        const roleText = { owner: '圈主', admin: '管理员', member: '成员' };
        const roleBadge = { owner: 'badge-warning', admin: 'badge-success', member: 'badge-default' };
        document.getElementById('circle-members-table').innerHTML = list.map(m => `
            <tr>
                <td data-label="用户ID">${m.user_id}</td>
                <td data-label="头像"><img src="${escapeHtml(m.avatar || (BASE_URL + '/templates/moments/assets/images/default-avatar.svg'))}" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;"></td>
                <td data-label="昵称">${escapeHtml(m.nickname || '-')}</td>
                <td data-label="用户名">@${escapeHtml(m.username || '')}</td>
                <td data-label="角色"><span class="badge ${roleBadge[m.role] || 'badge-default'}">${roleText[m.role] || m.role}</span></td>
                <td data-label="加入时间">${escapeHtml((m.joined_at || '').split(' ')[0])}</td>
                <td data-label="操作" data-actions>
                    ${m.role === 'owner'
                        ? '<span style="color:var(--text-muted);font-size:12px;">圈主</span>'
                        : `
                        ${m.role === 'admin'
                            ? `<button class="btn btn-default" data-action="set-circle-member-role" data-id="${m.user_id}" data-value="member">取消管理员</button>`
                            : `<button class="btn btn-default" data-action="set-circle-member-role" data-id="${m.user_id}" data-value="admin">设为管理员</button>`
                        }
                        <button class="btn btn-default" data-action="transfer-circle-owner" data-id="${m.user_id}">转让圈主</button>
                        <button class="btn btn-danger" data-action="remove-circle-member" data-id="${m.user_id}">移除</button>
                    `}
                </td>
            </tr>
        `).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;">暂无成员</td></tr>';
        renderMembersPagination(data);
    } catch(e) { showToast(e.message); }
}

// V50：设置成员角色（任命/取消管理员）
export async function setCircleMemberRole(userId, role) {
    if (!membersCurrentCircleId) return;
    if (!confirm(role === 'admin' ? '确认将该成员设为管理员？' : '确认取消该成员的管理员身份？')) return;
    try {
        await request('/admin/circles/members/role', {method:'POST', body:{
            circle_id: membersCurrentCircleId,
            user_id: userId,
            role: role
        }});
        showToast(role === 'admin' ? '已设为管理员' : '已取消管理员');
        loadCircleMembers();
    } catch(e) { showToast(e.message); }
}

// V50：转让圈主
export async function transferCircleOwner(newOwnerId) {
    if (!membersCurrentCircleId) return;
    if (!confirm('确认将圈主转让给该成员？转让后您将不再是圈主。')) return;
    try {
        await request('/admin/circles/transfer', {method:'POST', body:{
            circle_id: membersCurrentCircleId,
            new_owner_id: newOwnerId
        }});
        showToast('圈主已转让');
        loadCircleMembers();
        loadCircles();
    } catch(e) { showToast(e.message); }
}

// V50：待审申请子页
export let currentRequestsCircleId = null;
export let currentRequestsPage = 1;
export let currentRequestsLimit = 20;

export function closeCircleRequestsPanel() {
    document.getElementById('circle-requests-panel').classList.add('hidden');
    currentRequestsCircleId = null;
}

export async function loadCirclePendingRequests(circleId, page) {
    if (!circleId) return;
    currentRequestsCircleId = circleId;
    if (page) currentRequestsPage = page; else currentRequestsPage = 1;
    try {
        const params = new URLSearchParams({
            id: circleId, page: currentRequestsPage, limit: currentRequestsLimit
        });
        const data = await request('/admin/circles/requests?' + params.toString());
        const list = data.list || [];
        const name = document.getElementById('members-circle-name')?.textContent || ('#' + circleId);
        document.getElementById('requests-circle-name').textContent = name;
        document.getElementById('circle-requests-panel').classList.remove('hidden');
        document.getElementById('circle-members-panel').classList.add('hidden');
        document.getElementById('circle-form').classList.add('hidden');
        document.getElementById('circle-requests-table').innerHTML = list.map(r => `
            <tr>
                <td data-label="用户ID">${r.user_id}</td>
                <td data-label="头像"><img src="${escapeHtml(r.avatar || (BASE_URL + '/templates/moments/assets/images/default-avatar.svg'))}" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;"></td>
                <td data-label="昵称">${escapeHtml(r.nickname || '-')}</td>
                <td data-label="用户名">@${escapeHtml(r.username || '')}</td>
                <td data-label="申请留言">${escapeHtml(r.message || '-')}</td>
                <td data-label="申请时间">${escapeHtml((r.created_at || '').split('.')[0])}</td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-primary" data-action="approve-circle-request" data-id="${r.user_id}">通过</button>
                    <button class="btn btn-danger" data-action="reject-circle-request" data-id="${r.user_id}">拒绝</button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;">暂无待审申请</td></tr>';
        renderRequestsPagination(data);
    } catch(e) { showToast(e.message); }
}

export function renderRequestsPagination(data) {
    const total = data.total || 0;
    const page = data.page || 1;
    const limit = data.limit || currentRequestsLimit;
    const totalPages = Math.max(1, Math.ceil(total / limit));
    const el = document.getElementById('requests-pagination');
    if (!el) return;
    el.innerHTML = `
        <span style="font-size:13px;color:var(--text-secondary);">共 ${total} 条申请 · 第 ${page}/${totalPages} 页</span>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-default" ${page<=1?'disabled':''} data-action="load-circle-requests" data-page="${page-1}">上一页</button>
            <button class="btn btn-default" ${page>=totalPages?'disabled':''} data-action="load-circle-requests" data-page="${page+1}">下一页</button>
        </div>`;
}

export async function approveCircleRequest(userId) {
    if (!currentRequestsCircleId) return;
    try {
        await request('/admin/circles/requests/approve', {method:'POST', body:{
            circle_id: currentRequestsCircleId, user_id: userId
        }});
        showToast('已通过');
        loadCirclePendingRequests(currentRequestsCircleId, currentRequestsPage);
    } catch(e) { showToast(e.message); }
}

export async function rejectCircleRequest(userId) {
    if (!currentRequestsCircleId) return;
    if (!confirm('确认拒绝该申请？')) return;
    try {
        await request('/admin/circles/requests/reject', {method:'POST', body:{
            circle_id: currentRequestsCircleId, user_id: userId
        }});
        showToast('已拒绝');
        loadCirclePendingRequests(currentRequestsCircleId, currentRequestsPage);
    } catch(e) { showToast(e.message); }
}

export function renderMembersPagination(data) {
    const total = data.total || 0;
    const page = data.page || 1;
    const limit = data.limit || membersCurrentLimit;
    const totalPages = Math.max(1, Math.ceil(total / limit));
    const el = document.getElementById('members-pagination');
    if (!el) return;
    el.innerHTML = `
        <span style="font-size:13px;color:var(--text-secondary);">共 ${total} 名成员 · 第 ${page}/${totalPages} 页</span>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-default" ${page<=1?'disabled':''} data-action="load-circle-members" data-page="${page-1}">上一页</button>
            <button class="btn btn-default" ${page>=totalPages?'disabled':''} data-action="load-circle-members" data-page="${page+1}">下一页</button>
        </div>`;
}

export async function removeCircleMember(userId) {
    if (!membersCurrentCircleId) return;
    if (!confirm('确定将该成员移出圈子？')) return;
    try {
        await request('/admin/circles/members?circle_id=' + membersCurrentCircleId + '&user_id=' + userId, {method:'DELETE'});
        showToast('移除成功');
        loadCircleMembers();
        loadCircles();
    } catch(e) { showToast(e.message); }
}

/** 圈子限流 / 解除限流 / 下架 / 恢复 */
export async function moderateCircle(circleId, action) {
    const tips = { limit: '限制该圈子发言？成员仍可浏览，但不能发帖/评论。', unlimit: '恢复该圈子的发言权限？', remove: '下架该圈子？下架后列表与详情页都不再展示。', restore: '恢复该圈子的展示？' };
    if (!confirm(tips[action] || '确定执行该操作？')) return;
    try {
        const data = await request('/admin/circles/moderate', { method: 'POST', body: { circle_id: circleId, action } });
        showToast(data && data.message ? data.message : '操作成功');
        await loadCircles();
    } catch(e) { showToast(e.message); }
}