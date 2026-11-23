// ============================================================
// app-versions.js — App 版本管理
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';



// App 版本管理（V76）
export let currentAppVersionId = null;

export async function loadAppVersions() {
    try {
        const rows = await request('/admin/app-versions');
        const tbody = document.getElementById('app-versions-table');
        const typeMap = {0:'建议更新',1:'强制更新'};
        const pfMap = {android:'Android',ios:'iOS',h5:'H5'};
        tbody.innerHTML = rows.map(v => `
            <tr>
                <td data-label="平台">${pfMap[v.platform]||v.platform}</td>
                <td data-label="版本名">${v.version_name}</td>
                <td data-label="版本号">${v.version_code}</td>
                <td data-label="更新类型"><span class="badge ${v.update_type==1?'badge-danger':'badge-success'}">${typeMap[v.update_type]||'建议更新'}</span></td>
                <td data-label="下载" data-full><a href="${v.download_url}" target="_blank" rel="noopener" style="color:var(--primary);text-decoration:none;word-break:break-all;">${v.download_url}</a></td>
                <td data-label="状态"><span class="badge ${v.status===1?'badge-success':'badge-danger'}">${v.status===1?'启用':'禁用'}</span></td>
                <td data-label="时间">${v.created_at||'-'}</td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-default" data-action="edit-app-version" data-id="${v.id}" data-platform="${escapeHtml(v.platform)}" data-code="${v.version_code}" data-name="${escapeHtml(v.version_name)}" data-type="${v.update_type}" data-changelog="${escapeHtml(v.changelog||'')}" data-url="${escapeHtml(v.download_url)}" data-status="${v.status}">编辑</button>
                    <button class="btn btn-danger" data-action="delete-app-version" data-id="${v.id}">删除</button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="8" style="text-align:center;padding:40px;">暂无版本记录</td></tr>';
    } catch(e) { showToast(e.message); }
}

export function openAppVersionForm() {
    currentAppVersionId = null;
    document.getElementById('app-version-id').value = '';
    document.getElementById('app-version-platform').value = 'android';
    document.getElementById('app-version-code').value = '';
    document.getElementById('app-version-name').value = '';
    document.getElementById('app-version-update-type').value = '0';
    document.getElementById('app-version-changelog').value = '';
    document.getElementById('app-version-download').value = '';
    document.getElementById('app-version-status').checked = true;
    document.getElementById('app-version-form-title').textContent = '发布新版本';
    document.getElementById('app-version-form').classList.remove('hidden');
}

export function editAppVersion(id, platform, code, name, updateType, changelog, downloadUrl, status) {
    currentAppVersionId = id;
    document.getElementById('app-version-id').value = id;
    document.getElementById('app-version-platform').value = platform;
    document.getElementById('app-version-code').value = code;
    document.getElementById('app-version-name').value = name;
    document.getElementById('app-version-update-type').value = String(updateType);
    document.getElementById('app-version-changelog').value = changelog;
    document.getElementById('app-version-download').value = downloadUrl;
    document.getElementById('app-version-status').checked = status === 1;
    document.getElementById('app-version-form-title').textContent = '编辑版本';
    document.getElementById('app-version-form').classList.remove('hidden');
}

export function closeAppVersionForm() {
    document.getElementById('app-version-form').classList.add('hidden');
}

export async function saveAppVersion() {
    const body = {
        id: currentAppVersionId,
        platform: document.getElementById('app-version-platform').value,
        version_code: parseInt(document.getElementById('app-version-code').value || '0', 10),
        version_name: document.getElementById('app-version-name').value.trim(),
        update_type: parseInt(document.getElementById('app-version-update-type').value || '0', 10),
        changelog: document.getElementById('app-version-changelog').value.trim(),
        download_url: document.getElementById('app-version-download').value.trim(),
        status: document.getElementById('app-version-status').checked ? 1 : 0
    };
    if (!body.version_code || body.version_code <= 0) { showToast('请填写正确的版本号（正整数）'); return; }
    if (!body.version_name) { showToast('版本名不能为空'); return; }
    if (!body.download_url) { showToast('下载地址不能为空'); return; }
    try {
        await request('/admin/app-versions', { method: currentAppVersionId ? 'PUT' : 'POST', body });
        showToast('保存成功');
        closeAppVersionForm();
        loadAppVersions();
    } catch(e) { showToast(e.message); }
}

export async function deleteAppVersion(id) {
    if (!confirm('确定删除该版本记录？')) return;
    try {
        await request('/admin/app-versions?id=' + id, { method: 'DELETE' });
        showToast('删除成功');
        loadAppVersions();
    } catch(e) { showToast(e.message); }
}

// B4a 委托化：版本编辑 / 删除
registerActions({
    'edit-app-version': (d) => editAppVersion(+d.id, d.platform, +d.code, d.name, +d.type, d.changelog, d.url, +d.status),
    'delete-app-version': (d) => deleteAppVersion(+d.id),
    // B4b 委托化：静态发布按钮 / 表单保存取消
    'open-app-version-form': () => openAppVersionForm(),
    'save-app-version': () => saveAppVersion(),
    'close-app-version-form': () => closeAppVersionForm(),
});