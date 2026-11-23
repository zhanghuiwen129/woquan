// ============================================================
// posts.js — 内容管理：动态列表、批量操作、神评台账
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { currentPage, escapeHtml, registerActions, renderPagination, request, showToast } from './core.js';
import { flattenCategories } from './categories.js';



let postCache = {};
export let selectedPostIds = new Set();
// Vditor 实例（后台动态编辑富文本），未加载则降级为原生 textarea
let editVditor = null;
// 内容管理状态页签：-1 全部 / 1 已发布 / 0 已下架(受限)，自 yigengan 移植
let postStatusFilter = -1;

/** 切换状态快速筛选页签（全部/已发布/已下架受限） */
export function filterPostsByStatus(status) {
    postStatusFilter = status;
    document.querySelectorAll('#post-status-tabs .post-status-tab').forEach(btn => {
        btn.classList.toggle('active', parseInt(btn.dataset.status) === status);
    });
    loadPosts(1);
}

export async function loadPostCategoryOptions() {
    const sel = document.getElementById('post-category');
    if (!sel || sel.options.length > 1) return;
    try {
        const tree = await request('/admin/categories');
        const flat = flattenCategories(tree || []);
        sel.innerHTML = '<option value="">全部分类</option>' + flat.map(c =>
            `<option value="${c.id}">${c.level > 0 ? '├ ' : ''}${escapeHtml(c.name)}</option>`
        ).join('');
    } catch(e) {}
}

export async function loadPosts(page=1) {
    currentPage.posts = page;
    try {
        const keyword = document.getElementById('post-search')?.value || '';
        const categoryId = document.getElementById('post-category')?.value || '';
        const data = await request(`/admin/posts?page=${page}&limit=15&keyword=${encodeURIComponent(keyword)}&category_id=${encodeURIComponent(categoryId)}&status=${postStatusFilter}`);
        // 状态页签数量统计（后端 status_counts，统计失败时保持 0）
        const sc = data.status_counts || {};
        const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v ?? 0; };
        setText('post-count-all', sc.all);
        setText('post-count-published', sc.published);
        setText('post-count-restricted', sc.restricted);
        // 供「编辑」按钮取原始内容：列表接口已返回 content/title/category_id
        postCache = {};
        data.list.forEach(p => { postCache[p.id] = p; });
        document.getElementById('posts-table').innerHTML = data.list.map(p=>`
            <tr><td data-label="选择"><input type="checkbox" class="post-checkbox" value="${p.id}" data-change="update-post-selection" ${selectedPostIds.has(p.id)?'checked':''}></td><td data-label="ID">${p.id}</td><td data-label="作者">${escapeHtml(p.nickname)}</td><td data-label="分类">${p.category_name ? escapeHtml(p.category_name) : '<span style="color:var(--text-muted);">未分类</span>'}</td><td data-label="内容" data-full><a href="/p/${p.id}" target="_blank" rel="noopener" title="点击在前台查看文章详情">${escapeHtml(p.display)}</a></td>
            <td data-label="状态"><span class="badge ${p.status===1?'badge-success':'badge-danger'}">${p.status===1?'正常':'下架'}</span></td>
            <td data-label="置顶">${p.is_pinned ? '<span class="badge badge-danger">已置顶</span>' : '<span class="badge badge-success">未置顶</span>'}</td>
            <td data-label="发布时间">${(p.created_at || '').slice(0, 10)}<br><span style="color:var(--text-muted);">${(p.created_at || '').slice(11, 16)}</span></td>
            <td data-label="操作" data-actions>
                <button class="btn btn-primary" data-action="edit-post" data-id="${p.id}">编辑</button>
                <button class="btn btn-default" data-action="update-post-status" data-id="${p.id}" data-status="${p.status===1?0:1}">${p.status===1?'下架':'上架'}</button>
                <button class="btn ${p.is_pinned?'btn-danger':'btn-primary'}" data-action="pin-post" data-id="${p.id}" data-value="${p.is_pinned?0:1}">${p.is_pinned?'取消':'置顶'}</button>
            </td></tr>
        `).join('') || '<tr><td colspan="9" style="text-align:center;padding:40px;">暂无数据</td></tr>';
        renderPagination('posts-pagination', data.pagination, loadPosts);
        updateBatchBar();
    } catch(e) { showToast(e.message); }
}

// ===== 内容编辑（复用 PUT /posts，后端对 admin 放行任意状态动态） =====
// 编辑态媒体列表：图片为 URL 字符串；视频保持原始对象（url/cover/duration），新上传的追加对象
let editImages = [];
let editVideos = [];

export function closePostEdit() {
    document.getElementById('post-edit-mask').classList.add('hidden');
}

function videoUrlOf(v) { return typeof v === 'string' ? v : ((v && v.url) || ''); }

function renderEditMedia() {
    const del = (action, i) => `<span data-action="${action}" data-index="${i}" title="移除" style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;line-height:16px;text-align:center;border-radius:50%;background:#f56c6c;color:#fff;font-size:12px;cursor:pointer;">×</span>`;
    const imgs = editImages.map((u, i) =>
        `<span style="position:relative;display:inline-block;">
            <a href="${escapeHtml(u)}" target="_blank" rel="noopener"><img src="${escapeHtml(u)}" style="width:88px;height:88px;object-fit:cover;border-radius:4px;border:1px solid var(--border);display:block;"></a>${del('remove-edit-image', i)}
        </span>`).join('');
    const vids = editVideos.map((v, i) =>
        `<span style="position:relative;display:inline-block;">
            <video src="${escapeHtml(videoUrlOf(v))}" controls preload="metadata" style="width:120px;height:88px;object-fit:cover;border-radius:4px;border:1px solid var(--border);display:block;background:#000;"></video>${del('remove-edit-video', i)}
        </span>`).join('');
    document.getElementById('post-edit-media').innerHTML = imgs + vids;
}

export function removeEditImage(i) { editImages.splice(i, 1); renderEditMedia(); }
export function removeEditVideo(i) { editVideos.splice(i, 1); renderEditMedia(); }

export async function uploadEditImages(input) {
    const files = Array.from(input.files || []);
    if (!files.length) return;
    const tip = document.getElementById('post-edit-upload-tip');
    try {
        for (let i = 0; i < files.length; i++) {
            tip.textContent = `图片上传中 ${i + 1}/${files.length}...`;
            const fd = new FormData();
            fd.append('image', files[i]);
            const data = await request('/posts/upload', { method: 'POST', body: fd, isFormData: true });
            editImages.push(data.url);
            renderEditMedia();
        }
        tip.textContent = '图片上传完成';
        setTimeout(() => { if (tip.textContent === '图片上传完成') tip.textContent = ''; }, 2000);
    } catch (e) { tip.textContent = ''; showToast(e.message); }
    input.value = '';
}

export async function uploadEditVideos(input) {
    const files = Array.from(input.files || []);
    if (!files.length) return;
    const tip = document.getElementById('post-edit-upload-tip');
    try {
        for (let i = 0; i < files.length; i++) {
            tip.textContent = `视频上传中 ${i + 1}/${files.length}（大文件较慢，请勿关闭弹窗）...`;
            const fd = new FormData();
            fd.append('video', files[i]);
            const data = await request('/posts/upload-video', { method: 'POST', body: fd, isFormData: true });
            editVideos.push({ url: data.url, cover: data.cover || '', duration: data.duration || 0 });
            renderEditMedia();
        }
        tip.textContent = '视频上传完成';
        setTimeout(() => { if (tip.textContent === '视频上传完成') tip.textContent = ''; }, 2000);
    } catch (e) { tip.textContent = ''; showToast(e.message); }
    input.value = '';
}

function initEditVditor(initial) {
    const Vditor = window.Vditor;
    if (!Vditor) return; // 降级：原生 textarea
    if (editVditor) { editVditor.setValue(initial); return; }
    editVditor = new Vditor('post-edit-vditor', {
        mode: 'wysiwyg',
        cdn: './vendor/vditor',
        cache: { enable: false },
        placeholder: '动态内容，支持 Markdown 语法',
        upload: {
            handler: async (files) => {
                for (const file of files) {
                    try {
                        const fd = new FormData();
                        fd.append('image', file);
                        const data = await request('/posts/upload', { method: 'POST', body: fd, isFormData: true });
                        if (data && data.url) return { msg: '', code: 0, data: { url: data.url } };
                    } catch (e) {
                        return { msg: (e && e.message) || '上传失败', code: 1 };
                    }
                }
                return { msg: '未选择文件', code: 1 };
            }
        },
        after: () => { editVditor.setValue(initial); }
    });
}

export function editPost(id) {
    const p = postCache[id];
    if (!p) { showToast('数据已过期，请刷新列表后重试'); return; }
    document.getElementById('post-edit-id').textContent = '#' + p.id;
    document.getElementById('post-edit-title').value = p.title || '';
    document.getElementById('post-edit-content').value = p.content || '';
    const sel = document.getElementById('post-edit-category');
    const src = document.getElementById('post-category');
    sel.innerHTML = '<option value="">未分类</option>' + Array.from(src.options).filter(o => o.value !== '').map(o =>
        '<option value="' + o.value + '"' + (String(p.category_id ?? '') === o.value ? ' selected' : '') + '>' + escapeHtml(o.textContent) + '</option>'
    ).join('');
    // 媒体列表：图片/视频在内容编辑框内展示，支持移除与新上传
    editImages = Array.isArray(p.images) ? p.images.slice() : [];
    editVideos = Array.isArray(p.videos) ? p.videos.slice() : [];
    document.getElementById('post-edit-upload-tip').textContent = '';
    renderEditMedia();
    document.getElementById('post-edit-mask').classList.remove('hidden');
    initEditVditor(p.content || '');
}

export async function savePostEdit() {
    const id = parseInt(String(document.getElementById('post-edit-id').textContent).replace('#', ''), 10);
    const title = document.getElementById('post-edit-title').value.trim();
    const content = editVditor ? editVditor.getValue() : document.getElementById('post-edit-content').value;
    const category_id = document.getElementById('post-edit-category').value || '';
    if (!id) { showToast('参数错误'); return; }
    try {
        await request('/posts', {method:'PUT', body:{id, title, content, category_id, images: editImages, videos: editVideos}});
        showToast('保存成功');
        closePostEdit();
        loadPosts(currentPage.posts);
    } catch(e) { showToast(e.message); }
}

export function toggleSelectAllPosts(checked) {
    document.querySelectorAll('.post-checkbox').forEach(cb => {
        cb.checked = checked;
        const id = parseInt(cb.value);
        if (checked) selectedPostIds.add(id); else selectedPostIds.delete(id);
    });
    updateBatchBar();
}

export function updatePostSelection() {
    selectedPostIds.clear();
    document.querySelectorAll('.post-checkbox:checked').forEach(cb => selectedPostIds.add(parseInt(cb.value)));
    updateBatchBar();
}

export function updateBatchBar() {
    const bar = document.getElementById('posts-batch-bar');
    const countEl = document.getElementById('posts-selected-count');
    const selectAll = document.getElementById('post-select-all');
    const checkboxes = document.querySelectorAll('.post-checkbox');
    if (selectedPostIds.size > 0) {
        bar.classList.remove('hidden');
        bar.style.display = 'flex';
    } else {
        bar.classList.add('hidden');
        bar.style.display = 'none';
    }
    if (countEl) countEl.textContent = selectedPostIds.size;
    if (selectAll && checkboxes.length > 0) {
        selectAll.checked = Array.from(checkboxes).every(cb => cb.checked);
    }
}

export function clearPostSelection() {
    selectedPostIds.clear();
    document.querySelectorAll('.post-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('post-select-all');
    if (selectAll) selectAll.checked = false;
    updateBatchBar();
}

export async function batchPosts(action) {
    const ids = Array.from(selectedPostIds);
    if (ids.length === 0) { showToast('请先选择动态'); return; }
    const actionNames = {delete:'删除',hide:'隐藏',show:'显示',pin:'置顶',unpin:'取消置顶'};
    if (!confirm(`确认对选中的 ${ids.length} 条动态执行「批量${actionNames[action]}」操作？`)) return;
    try {
        await request('/admin/posts/batch', {method:'POST', body:{ids, action}, timeout: 120000}); // 批量操作放宽到 2 分钟
        showToast('批量操作成功');
        clearPostSelection();
        loadPosts(currentPage.posts);
    } catch(e) { showToast(e.message); }
}
export async function updatePostStatus(id, status) {
    try { await request('/admin/posts/status', {method:'PUT', body:{id,status}}); loadPosts(currentPage.posts); showToast('操作成功'); } catch(e){ showToast(e.message); }
}
export async function pinPost(id, pinned) {
    try { await request('/admin/posts/pin', {method:'PUT', body:{id,pinned}}); loadPosts(currentPage.posts); showToast(pinned ? '置顶成功' : '取消置顶成功'); } catch(e){ showToast(e.message); }
}

// ===== V83 神评标记台账 =====
export async function loadGodMarks(page = 1) {
    try {
        const keyword = document.getElementById('bd-god-keyword')?.value || '';
        const data = await request(
            `/admin/badge-god-marks?page=${page}&limit=20&keyword=${encodeURIComponent(keyword)}`
        );
        const rows = data.list || [];
        document.getElementById('bd-god-table').innerHTML = rows.map(m => `<tr>
            <td data-label="ID">${m.id}</td>
            <td data-label="评论作者">${escapeHtml(m.comment_author || '')}</td>
            <td data-label="评论摘要" data-full>${escapeHtml(m.comment_excerpt || '')}</td>
            <td data-label="所属动态" data-full>${escapeHtml(m.post_author || '')}：${escapeHtml(m.post_excerpt || '')}</td>
            <td data-label="标记人">${escapeHtml(m.operator_name || '')}</td>
            <td data-label="时间">${escapeHtml(m.time_ago || m.created_at || '')}</td>
            <td data-label="操作" data-actions>
                <button class="btn btn-danger" data-action="delete-god-mark" data-id="${m.id}">撤销</button>
            </td>
        </tr>`).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;">暂无神评标记</td></tr>';
        renderPagination('bd-god-pagination', data.pagination, loadGodMarks);
    } catch (e) { showToast(e.message); }
}

export async function deleteGodMark(id) {
    if (!confirm('确认撤销这条神评标记？\n撤销后会立即重算被评作者的神评类徽章（次数不足将自动冻结）。')) return;
    try {
        await request('/admin/badge-god-marks?id=' + id, { method: 'DELETE' });
        showToast('已撤销');
        loadGodMarks(1);
    } catch (e) { showToast(e.message); }
}

// B4a 委托化：动态操作 / 神评撤销 / 编辑媒体移除
registerActions({
    'edit-post': (d) => editPost(+d.id),
    'update-post-status': (d) => updatePostStatus(+d.id, +d.status),
    'pin-post': (d) => pinPost(+d.id, +d.value),
    'delete-god-mark': (d) => deleteGodMark(+d.id),
    'remove-edit-image': (d) => removeEditImage(+d.index),
    'remove-edit-video': (d) => removeEditVideo(+d.index),
    // B4b 委托化：静态状态页签 / 搜索 / 批量操作 / 编辑弹窗
    'filter-posts-by-status': (d) => filterPostsByStatus(+d.status),
    'load-posts': () => loadPosts(),
    'batch-posts': (d) => batchPosts(d.value),
    'clear-post-selection': () => clearPostSelection(),
    'save-post-edit': () => savePostEdit(),
    'close-post-edit': () => closePostEdit(),
    // B5 委托化：遮罩层点击关闭（原 if(event.target===this) 守卫，仅点遮罩空白处生效）
    'close-post-edit-mask': (d, el, e) => { if (e.target === el) closePostEdit(); },
    // B6 委托化：全选/勾选联动 / 编辑弹窗图片视频上传 / 搜索回车（原 onchange/onkeypress 直调）
    'toggle-select-all-posts': (d, el) => toggleSelectAllPosts(el.checked),
    'update-post-selection': () => updatePostSelection(),
    'upload-edit-images': (d, el) => uploadEditImages(el),
    'upload-edit-videos': (d, el) => uploadEditVideos(el),
    'enter-posts': (d, el, e) => { if (e.key === 'Enter') loadPosts(); },
    'load-god-marks': () => loadGodMarks(),
});