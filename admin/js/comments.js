// ============================================================
// comments.js — 评论管理
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { currentPage, escapeHtml, registerActions, renderPagination, request, showToast } from './core.js';



export async function loadComments(page=1) {
    currentPage.comments = page;
    try {
        const data = await request(`/admin/comments?page=${page}&limit=15`);
        document.getElementById('comments-table').innerHTML = data.list.map(c=>`
            <tr><td data-label="ID">${c.id}</td><td data-label="用户">${escapeHtml(c.nickname)}</td><td data-label="内容" data-full>${escapeHtml(c.content)}</td><td data-label="所属动态" data-full>${escapeHtml(c.post_content?.substring(0,30))}...</td><td data-label="时间">${c.created_at}</td>
            <td data-label="操作" data-actions><button class="btn btn-danger" data-action="delete-comment" data-id="${c.id}">删除</button></td></tr>
        `).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;">暂无数据</td></tr>';
        renderPagination('comments-pagination', data.pagination, loadComments);
    } catch(e) { showToast(e.message); }
}
export async function deleteComment(id) {
    if(!confirm('确定删除？')) return;
    try { await request('/admin/comments?id='+id, {method:'DELETE'}); loadComments(currentPage.comments); showToast('删除成功'); } catch(e){ showToast(e.message); }
}

// B4a 委托化：评论删除
registerActions({
    'delete-comment': (d) => deleteComment(+d.id),
});