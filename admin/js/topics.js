// ============================================================
// topics.js — 话题管理 + 精华 / 推荐位
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';



// ============ 话题管理 ============
export let currentTopicId = null;

export async function loadTopics() {
    try {
        const data = await request('/admin/topics');
        const list = Array.isArray(data) ? data : (data.list || []);
        document.getElementById('topics-table').innerHTML = list.map(t => `
            <tr>
                <td data-label="ID">${t.id}</td>
                <td data-label="名称">${escapeHtml(t.name)}</td>
                <td data-label="描述" data-full>${escapeHtml(t.description || '-')}</td>
                <td data-label="动态数">${t.post_count || 0}</td>
                <td data-label="状态"><span class="badge ${t.status===1?'badge-success':'badge-danger'}">${t.status===1?'启用':'禁用'}</span></td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-default" data-action="edit-topic" data-id="${t.id}">编辑</button>
                    <button class="btn btn-danger" data-action="delete-topic" data-id="${t.id}">删除</button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;">暂无话题</td></tr>';
    } catch(e) { showToast(e.message); }
}

/* ===== V65 全站精华 / 运营推荐位 ===== */
export async function loadFeatured() {
    const slot = document.getElementById('featured-slot').value || 'home';
    try {
        const list = await request('/admin/featured?slot=' + encodeURIComponent(slot));
        document.getElementById('featured-table').innerHTML = (list || []).map(f => `
            <tr>
                <td data-label="动态ID">${f.post_id}</td>
                <td data-label="作者">${escapeHtml(f.nickname || f.username || '-')}</td>
                <td data-label="内容" data-full>${escapeHtml(String(f.content || '').slice(0, 80))}</td>
                <td data-label="排序">${f.sort_order || 0}</td>
                <td data-label="推荐理由" data-full>${escapeHtml(f.remark || '-')}</td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-danger" data-action="toggle-featured" data-id="${f.post_id}">取消推荐</button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;">还没有精选内容，在下方加一条试试</td></tr>';
    } catch(e) { showToast(e.message); }
}

export async function toggleFeatured(postId) {
    const slot = document.getElementById('featured-slot').value || 'home';
    const payload = { slot: slot, post_id: Number(postId) || 0 };
    if (!payload.post_id) {
        payload.post_id = Number(document.getElementById('featured-post-id').value) || 0;
        payload.sort_order = Number(document.getElementById('featured-sort').value) || 0;
        payload.remark = document.getElementById('featured-remark').value || '';
    }
    if (!payload.post_id) { showToast('请填写动态ID'); return; }
    try {
        await request('/admin/featured/toggle', { method: 'POST', body: payload });
        showToast('已更新');
        loadFeatured();
    } catch(e) { showToast(e.message); }
}

export function openTopicForm() {
    currentTopicId = null;
    document.getElementById('topic-id').value = '';
    document.getElementById('topic-name').value = '';
    document.getElementById('topic-description').value = '';
    document.getElementById('topic-status').checked = true;
    document.getElementById('topic-form-title').textContent = '添加话题';
    document.getElementById('topic-form').classList.remove('hidden');
}

export function closeTopicForm() {
    document.getElementById('topic-form').classList.add('hidden');
}

export function editTopic(id) {
    const rows = document.querySelectorAll('#topics-table tr');
    let topic = null;
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 0 && parseInt(cells[0].textContent) === id) {
            topic = {
                id: id,
                name: cells[1].textContent,
                description: cells[2].textContent === '-' ? '' : cells[2].textContent,
                status: cells[4].textContent.includes('启用') ? 1 : 0
            };
        }
    });
    if (!topic) return;
    currentTopicId = id;
    document.getElementById('topic-id').value = id;
    document.getElementById('topic-name').value = topic.name;
    document.getElementById('topic-description').value = topic.description;
    document.getElementById('topic-status').checked = topic.status === 1;
    document.getElementById('topic-form-title').textContent = '编辑话题';
    document.getElementById('topic-form').classList.remove('hidden');
}

export async function saveTopic() {
    const name = document.getElementById('topic-name').value.trim();
    if (!name) { showToast('话题名称不能为空'); return; }
    const body = {
        name: name,
        description: document.getElementById('topic-description').value.trim(),
        status: document.getElementById('topic-status').checked ? 1 : 0
    };
    try {
        if (currentTopicId) {
            body.id = currentTopicId;
            await request('/admin/topics', {method:'PUT', body});
        } else {
            await request('/admin/topics', {method:'POST', body});
        }
        showToast('保存成功');
        closeTopicForm();
        loadTopics();
    } catch(e) { showToast(e.message); }
}

export async function deleteTopic(id) {
    if (!confirm('确定删除该话题？')) return;
    try {
        await request('/admin/topics?id=' + id, {method:'DELETE'});
        showToast('删除成功');
        loadTopics();
    } catch(e) { showToast(e.message); }
}

// B4a 委托化：话题编辑/删除 / 精华取消推荐
registerActions({
    'edit-topic': (d) => editTopic(+d.id),
    'delete-topic': (d) => deleteTopic(+d.id),
    'toggle-featured': (d) => toggleFeatured(+d.id),
    // B4b 委托化：静态刷新 / 加入精选（toggle-featured 复用上面注册，无 data-id 时 +undefined=NaN 走表单取值分支）
    'load-featured': () => loadFeatured(),
    // B4b 委托化：话题表单开/关/保存（原内联事件直调 openTopicForm/closeTopicForm/saveTopic）
    'open-topic-form': () => openTopicForm(),
    'close-topic-form': () => closeTopicForm(),
    'save-topic': () => saveTopic(),
});