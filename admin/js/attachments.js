// ============================================================
// attachments.js — 附件管理（含孤儿清理）
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { BASE_URL, currentPage, escapeHtml, registerActions, renderPagination, request, showToast } from './core.js';


export let currentAttachmentType = 'all';

export function switchAttachmentTab(type) {
    currentAttachmentType = type;
    document.querySelectorAll('.attachment-tab').forEach(el=>el.classList.remove('active'));
    document.querySelector('.attachment-tab[data-type="'+type+'"]').classList.add('active');
    currentPage.attachments = 1;
    loadAttachments();
}

// B2 委托化：附件 Tab 切换（data-action="switch-attachment-tab"，复用 data-type 属性）
registerActions({
    'switch-attachment-tab': (d) => switchAttachmentTab(d.type),
    // B4a 委托化：附件上架/下架 / 删除
    'update-attachment-status': (d) => updateAttachmentStatus(+d.id, +d.status),
    'delete-attachment': (d) => deleteAttachment(+d.id),
    // B4b 委托化：静态搜索 / 孤儿清理
    'load-attachments': () => loadAttachments(),
    'cleanup-post-orphan-uploads': () => cleanupPostOrphanUploads(),
    // B6 委托化：搜索框回车（原 onkeypress 直调）
    'enter-attachments': (d, el, e) => { if (e.key === 'Enter') loadAttachments(); },
});

export async function loadAttachments(page=1) {
    currentPage.attachments = page;
    try {
        const keyword = document.getElementById('attachment-search')?.value || '';
        const typeParam = currentAttachmentType === 'all' ? '' : '&type='+encodeURIComponent(currentAttachmentType);
        const data = await request(`/admin/attachments?page=${page}&limit=15${typeParam}&keyword=${encodeURIComponent(keyword)}`);
        document.getElementById('attachments-table').innerHTML = data.list.map(a=>{
            const postCell = a.post_id
                ? `<a href="${BASE_URL}/post.html?id=${a.post_id}" target="_blank" rel="noopener" style="color:var(--info);text-decoration:none;font-weight:600;">#${a.post_id} ↗</a>${a.post_status != 1 ? ' <span class="badge badge-danger">动态已下架</span>' : ''}${a.post_content ? `<div style="font-size:12px;color:var(--text-muted);margin-top:3px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(a.post_content.slice(0, 30))}</div>` : ''}`
                : '<span style="color:var(--text-muted);">未绑定</span>';
            return `
            <tr><td data-label="ID">${a.id}</td><td data-label="类型">${a.type==='image'?'图片':'附件'}</td><td data-label="文件名" data-full title="${escapeHtml(a.filename)}">${escapeHtml(a.filename)}</td><td data-label="用户">${escapeHtml(a.nickname)}</td><td data-label="所属动态" data-full>${postCell}</td><td data-label="大小">${a.size_text}</td>
            <td data-label="状态"><span class="badge ${a.status===1?'badge-success':'badge-danger'}">${a.status===1?'正常':'下架'}</span></td>
            <td data-label="时间">${a.created_at}</td>
            <td data-label="操作" data-actions>
                <button class="btn btn-default" data-action="update-attachment-status" data-id="${a.id}" data-status="${a.status===1?0:1}">${a.status===1?'下架':'上架'}</button>
                <button class="btn btn-danger" data-action="delete-attachment" data-id="${a.id}">删除</button>
            </td></tr>
        `;
        }).join('') || '<tr><td colspan="9" style="text-align:center;padding:40px;">暂无数据</td></tr>';
        renderPagination('attachments-pagination', data.pagination, loadAttachments);
    } catch(e) { showToast(e.message); }
}

export async function updateAttachmentStatus(id, status) {
    try { await request('/admin/attachments/status', {method:'PUT', body:{id,status}}); loadAttachments(currentPage.attachments); showToast('操作成功'); } catch(e){ showToast(e.message); }
}

export async function deleteAttachment(id) {
    if(!confirm('确定删除？此操作不可恢复！')) return;
    try { await request('/admin/attachments?id='+id, {method:'DELETE'}); loadAttachments(currentPage.attachments); showToast('删除成功'); } catch(e){ showToast(e.message); }
}

/**
 * 清理动态孤儿文件：uploads 表里 post_id IS NULL 且 created_at 早于 N 小时前的记录
 * （用户上传了图片/视频/附件但未发布就放弃了，文件常驻在服务器上）
 */
export async function cleanupPostOrphanUploads() {
    const hoursInput = document.getElementById('post-orphan-hours');
    const hours = Math.max(1, parseInt(hoursInput?.value) || 24);
    if (!confirm(`确定要清理 ${hours} 小时前仍未发布绑定的孤儿文件吗？\n此操作不可恢复！`)) return;
    const resultEl = document.getElementById('post-orphan-result');
    resultEl.textContent = '扫描中...';
    try {
        const r = await request('/admin/posts/cleanup-orphan-uploads', { method: 'POST', body: { hours }, timeout: 300000 }); // 物理文件清理较慢，放宽到 5 分钟
        resultEl.innerHTML = `<span style="color:var(--success);">已清理 ${r.deleted_records} 条记录，删除 ${r.deleted_files} 个物理文件，释放 ${r.freed_mb} MB 空间</span>`;
        loadAttachments(currentPage.attachments);
    } catch (e) {
        resultEl.innerHTML = `<span style="color:var(--danger);">${escapeHtml(e.message)}</span>`;
    }
}