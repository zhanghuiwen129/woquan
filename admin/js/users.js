// ============================================================
// users.js — 用户管理
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { currentPage, escapeHtml, registerActions, renderPagination, request, showToast } from './core.js';
import { muteUserPrompt, unmuteUserAction } from './moderation.js';



export async function loadUsers(page=1) {
    currentPage.users = page;
    try {
        const keyword = document.getElementById('user-search')?.value || '';
        const data = await request(`/admin/users?page=${page}&limit=15&keyword=${encodeURIComponent(keyword)}`);
        document.getElementById('users-table').innerHTML = data.list.map(u=>{
            // V71：禁言到期时间已过就不再显示「禁言中」（后台不主动清库，到期即自动失效）
            const muted = u.muted_until && new Date(String(u.muted_until).replace(' ','T')).getTime() > Date.now();
            userCache[u.id] = u;
            return `
            <tr><td data-label="ID">${u.id}</td><td data-label="用户名">${escapeHtml(u.username)}</td><td data-label="昵称">${escapeHtml(u.nickname)}</td><td data-label="角色">${u.role==='admin'?'管理员':'用户'}</td>
            <td data-label="状态"><span class="badge ${u.status===1?'badge-success':'badge-danger'}">${u.status===1?'正常':'禁用'}</span>${muted?' <span class="badge badge-warning">禁言中</span>':''}</td>
            <td data-label="注册时间">${u.created_at}</td>
            <td data-label="操作" data-actions>
                <button class="btn btn-default" data-action="edit-user" data-id="${u.id}">编辑</button>
                <button class="btn btn-default" data-action="update-user-status" data-id="${u.id}" data-status="${u.status===1?0:1}">${u.status===1?'禁用':'启用'}</button>
                ${muted
                    ? `<button class="btn btn-default" data-action="unmute-user" data-id="${u.id}">解禁</button>`
                    : `<button class="btn btn-default" data-action="mute-user" data-id="${u.id}">禁言</button>`}
                <button class="btn btn-danger" data-action="delete-user" data-id="${u.id}">删除</button>
            </td></tr>`;
        }).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;">暂无数据</td></tr>';
        renderPagination('users-pagination', data.pagination, loadUsers);
    } catch(e) { showToast(e.message); }
}

// 编辑弹窗行数据缓存（V179）
const userCache = {};

export function editUser(id) {
    const u = userCache[id];
    if (!u) { showToast('数据已过期，请刷新列表后重试'); return; }
    document.getElementById('user-edit-id').textContent = '#' + u.id;
    document.getElementById('user-edit-username').value = u.username || '';
    document.getElementById('user-edit-nickname').value = u.nickname || '';
    document.getElementById('user-edit-role').value = u.role === 'admin' ? 'admin' : 'user';
    document.getElementById('user-edit-status').value = u.status === 1 ? '1' : '0';
    document.getElementById('user-edit-password').value = '';
    document.getElementById('user-edit-mask').classList.remove('hidden');
}

export function closeUserEdit() {
    document.getElementById('user-edit-mask').classList.add('hidden');
}

export async function saveUserEdit() {
    const id = parseInt(String(document.getElementById('user-edit-id').textContent).replace('#', ''), 10);
    if (!id) { showToast('参数错误'); return; }
    const nickname = document.getElementById('user-edit-nickname').value.trim();
    const role = document.getElementById('user-edit-role').value;
    const status = parseInt(document.getElementById('user-edit-status').value, 10);
    const password = document.getElementById('user-edit-password').value;
    if (!nickname) { showToast('昵称不能为空'); return; }
    if (password && password.length < 6) { showToast('新密码至少 6 位'); return; }
    try {
        await request('/admin/users/update', { method:'PUT', body:{ id, nickname, role, status, password } });
        showToast('保存成功');
        closeUserEdit();
        loadUsers(currentPage.users);
    } catch(e) { showToast(e.message); }
}

export async function updateUserStatus(id, status) {
    if(!confirm('确定操作？')) return;
    try { await request('/admin/users/status', {method:'PUT', body:{id,status}}); loadUsers(currentPage.users); showToast('操作成功'); } catch(e){ showToast(e.message); }
}
export async function deleteUser(id) {
    if(!confirm('确定删除？此操作不可恢复！')) return;
    try { await request('/admin/users?id='+id, {method:'DELETE'}); loadUsers(currentPage.users); showToast('删除成功'); } catch(e){ showToast(e.message); }
}

// B4a 委托化：编辑 / 用户状态 / 禁言 / 删除
registerActions({
    'edit-user': (d) => editUser(+d.id),
    'save-user-edit': () => saveUserEdit(),
    'close-user-edit': () => closeUserEdit(),
    'close-user-edit-mask': (d, el, e) => { if (e.target === el) closeUserEdit(); },
    'update-user-status': (d) => updateUserStatus(+d.id, +d.status),
    'mute-user': (d) => muteUserPrompt(+d.id),
    'unmute-user': (d) => unmuteUserAction(+d.id),
    'delete-user': (d) => deleteUser(+d.id),
    // B4b 委托化：静态搜索按钮
    'load-users': () => loadUsers(),
    // B6 委托化：搜索框回车（原 onkeypress 直调）
    'enter-users': (d, el, e) => { if (e.key === 'Enter') loadUsers(); },
});