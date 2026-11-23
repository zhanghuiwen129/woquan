// ============================================================
// links.js — 友情链接
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';



// 友情链接管理
export let currentFriendLinkId = null;

export async function loadFriendLinks() {
    try {
        const links = await request('/admin/friend-links');
        const tbody = document.getElementById('friend-links-table');
        tbody.innerHTML = links.map(link => `
            <tr>
                <td data-label="排序">${link.sort_order}</td>
                <td data-label="名称">${escapeHtml(link.name)}</td>
                <td data-label="网址" data-full><a href="${escapeHtml(link.url)}" target="_blank" rel="noopener" style="color:var(--primary);text-decoration:none;">${escapeHtml(link.url)}</a></td>
                <td data-label="描述">${escapeHtml(link.description || '-')}</td>
                <td data-label="状态"><span class="badge ${link.status===1?'badge-success':'badge-danger'}">${link.status===1?'启用':'禁用'}</span></td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-default" data-action="edit-friend-link" data-id="${link.id}" data-name="${escapeHtml(link.name)}" data-url="${escapeHtml(link.url)}" data-desc="${escapeHtml(link.description || '')}" data-sort="${link.sort_order}" data-status="${link.status}">编辑</button>
                    <button class="btn btn-danger" data-action="delete-friend-link" data-id="${link.id}">删除</button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;">暂无友情链接</td></tr>';
    } catch(e) { showToast(e.message); }
}

export function openFriendLinkForm() {
    currentFriendLinkId = null;
    document.getElementById('friend-link-id').value = '';
    document.getElementById('friend-link-name').value = '';
    document.getElementById('friend-link-url').value = '';
    document.getElementById('friend-link-description').value = '';
    document.getElementById('friend-link-sort-order').value = '0';
    document.getElementById('friend-link-status').checked = true;
    document.getElementById('friend-link-form-title').textContent = '添加友情链接';
    document.getElementById('friend-link-form').classList.remove('hidden');
}

export function editFriendLink(id, name, url, description, sortOrder, status) {
    currentFriendLinkId = id;
    document.getElementById('friend-link-id').value = id;
    document.getElementById('friend-link-name').value = name;
    document.getElementById('friend-link-url').value = url;
    document.getElementById('friend-link-description').value = description;
    document.getElementById('friend-link-sort-order').value = sortOrder;
    document.getElementById('friend-link-status').checked = status === 1;
    document.getElementById('friend-link-form-title').textContent = '编辑友情链接';
    document.getElementById('friend-link-form').classList.remove('hidden');
}

export function closeFriendLinkForm() {
    document.getElementById('friend-link-form').classList.add('hidden');
}

export async function saveFriendLink() {
    const body = {
        id: currentFriendLinkId,
        name: document.getElementById('friend-link-name').value.trim(),
        url: document.getElementById('friend-link-url').value.trim(),
        description: document.getElementById('friend-link-description').value.trim(),
        sort_order: parseInt(document.getElementById('friend-link-sort-order').value || '0', 10),
        status: document.getElementById('friend-link-status').checked ? 1 : 0
    };
    if (!body.name || !body.url) {
        showToast('网站名称和网址不能为空');
        return;
    }
    try {
        await request('/admin/friend-links', { method: currentFriendLinkId ? 'PUT' : 'POST', body });
        showToast('保存成功');
        closeFriendLinkForm();
        loadFriendLinks();
    } catch(e) { showToast(e.message); }
}

export async function deleteFriendLink(id) {
    if (!confirm('确定删除该友情链接？')) return;
    try {
        await request('/admin/friend-links?id=' + id, { method: 'DELETE' });
        showToast('删除成功');
        loadFriendLinks();
    } catch(e) { showToast(e.message); }
}

// B4a 委托化：友链编辑 / 删除
registerActions({
    'edit-friend-link': (d) => editFriendLink(+d.id, d.name, d.url, d.desc, +d.sort, +d.status),
    'delete-friend-link': (d) => deleteFriendLink(+d.id),
    // B4b 委托化：静态表单按钮
    'open-friend-link-form': () => openFriendLinkForm(),
    'save-friend-link': () => saveFriendLink(),
    'close-friend-link-form': () => closeFriendLinkForm(),
});