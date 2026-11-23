// ============================================================
// moderation.js — 内容治理：敏感词、命中记录、禁言
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { currentPage, escapeHtml, registerActions, request, showToast } from './core.js';
import { loadUsers } from './users.js';



/* ==================== V71 内容治理 ==================== */

export const SW_LEVEL_TEXT = { replace: '替换', block: '拦截', review: '转审核' };
export const SW_LEVEL_BADGE = { replace: 'badge-default', block: 'badge-danger', review: 'badge-warning' };
export const FL_SCENE_TEXT = { post: '动态', comment: '评论', chat: '私信', profile: '资料', circle: '圈子' };
export let swCurrentPage = 1;
export let flCurrentPage = 1;

export async function loadSensitiveWords(page) {
    if (page) swCurrentPage = page; else swCurrentPage = 1;
    const keyword = (document.getElementById('sw-search')?.value || '').trim();
    try {
        const params = new URLSearchParams({ page: swCurrentPage, limit: 20 });
        if (keyword) params.set('keyword', keyword);
        const data = await request('/admin/sensitive-words?' + params.toString());
        const list = data.list || [];
        document.getElementById('sw-table').innerHTML = list.map(w => `
            <tr>
                <td data-label="ID">${w.id}</td>
                <td data-label="敏感词">${escapeHtml(w.word)}</td>
                <td data-label="处理级别"><span class="badge ${SW_LEVEL_BADGE[w.level] || 'badge-default'}">${SW_LEVEL_TEXT[w.level] || w.level}</span></td>
                <td data-label="替换为">${w.level === 'replace' ? escapeHtml(w.replacement || '***') : '-'}</td>
                <td data-label="生效场景">${w.scene ? escapeHtml(w.scene) : '全部'}</td>
                <td data-label="命中次数">${w.hit_count || 0}</td>
                <td data-label="状态"><span class="badge ${w.status==1?'badge-success':'badge-danger'}">${w.status==1?'启用':'停用'}</span></td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-default" data-action="edit-word" data-id="${w.id}">编辑</button>
                    <button class="btn btn-danger" data-action="delete-word" data-id="${w.id}">删除</button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="8" style="text-align:center;padding:40px;">暂无敏感词</td></tr>';
        renderModerationPagination('sw-pagination', data, loadSensitiveWords);
    } catch(e) { showToast(e.message); }
}

export function renderModerationPagination(elId, data, fn) {
    const el = document.getElementById(elId);
    if (!el) return;
    const total = data.total || 0;
    const page = data.page || 1;
    const limit = data.limit || 20;
    const totalPages = Math.max(1, Math.ceil(total / limit));
    el.innerHTML = `
        <span style="font-size:13px;color:var(--text-secondary);">共 ${total} 条 · 第 ${page}/${totalPages} 页</span>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-default" ${page<=1?'disabled':''} data-page="${page-1}">上一页</button>
            <button class="btn btn-default" ${page>=totalPages?'disabled':''} data-page="${page+1}">下一页</button>
        </div>`;
    // B6 委托化：容器级绑定函数引用（原 onclick 拼动态函数名走 window，已拆除）
    el.querySelectorAll('button[data-page]').forEach(btn => {
        btn.addEventListener('click', () => fn(+btn.dataset.page));
    });
}

export function openWordForm() {
    document.getElementById('sw-id').value = '';
    document.getElementById('sw-word').value = '';
    document.getElementById('sw-level').value = 'replace';
    document.getElementById('sw-replacement').value = '***';
    document.getElementById('sw-scene').value = '';
    document.getElementById('sw-status').checked = true;
    document.getElementById('sw-form-title').textContent = '添加敏感词';
    document.getElementById('sw-form').classList.remove('hidden');
}

export function closeWordForm() {
    document.getElementById('sw-form').classList.add('hidden');
}

export async function editWord(id) {
    try {
        const params = new URLSearchParams({ page: 1, limit: 200 });
        const data = await request('/admin/sensitive-words?' + params.toString());
        const w = (data.list || []).find(x => x.id === id);
        if (!w) { showToast('词库里找不到该条记录'); return; }
        document.getElementById('sw-id').value = w.id;
        document.getElementById('sw-word').value = w.word;
        document.getElementById('sw-level').value = w.level;
        document.getElementById('sw-replacement').value = w.replacement || '***';
        document.getElementById('sw-scene').value = w.scene || '';
        document.getElementById('sw-status').checked = Number(w.status) === 1;
        document.getElementById('sw-form-title').textContent = '编辑敏感词';
        document.getElementById('sw-form').classList.remove('hidden');
        document.getElementById('sw-form').scrollIntoView({behavior:'smooth', block:'nearest'});
    } catch(e) { showToast(e.message); }
}

export async function saveWord() {
    const word = (document.getElementById('sw-word').value || '').trim();
    if (!word) { showToast('敏感词不能为空'); return; }
    const body = {
        id: Number(document.getElementById('sw-id').value) || 0,
        word,
        level: document.getElementById('sw-level').value,
        replacement: document.getElementById('sw-replacement').value || '***',
        scene: (document.getElementById('sw-scene').value || '').trim(),
        status: document.getElementById('sw-status').checked ? 1 : 0
    };
    try {
        await request('/admin/sensitive-words', { method: 'POST', body });
        showToast('保存成功（词库缓存已刷新，立即生效）');
        closeWordForm();
        await loadSensitiveWords();
    } catch(e) { showToast(e.message); }
}

export async function deleteWord(id) {
    if (!confirm('确定删除这个敏感词？')) return;
    try {
        await request('/admin/sensitive-words?id=' + id, { method: 'DELETE' });
        showToast('已删除');
        await loadSensitiveWords();
    } catch(e) { showToast(e.message); }
}

export function toggleImportBox() {
    document.getElementById('sw-import').classList.toggle('hidden');
}

export async function importWords() {
    const text = (document.getElementById('sw-import-text').value || '');
    if (!text.trim()) { showToast('请先粘贴要导入的词'); return; }
    try {
        const data = await request('/admin/sensitive-words/import', {
            method: 'POST',
            body: {
                text,
                level: document.getElementById('sw-import-level').value,
                replacement: document.getElementById('sw-import-replacement').value || '***',
                scene: (document.getElementById('sw-import-scene').value || '').trim()
            }
        });
        showToast(`导入完成：新增 ${data.added} 个，跳过 ${data.skipped} 个`);
        document.getElementById('sw-import-text').value = '';
        document.getElementById('sw-import').classList.add('hidden');
        await loadSensitiveWords();
    } catch(e) { showToast(e.message); }
}

export async function loadFilterLogs(page) {
    if (page) flCurrentPage = page; else flCurrentPage = 1;
    const scene = (document.getElementById('fl-scene')?.value || '').trim();
    try {
        const params = new URLSearchParams({ page: flCurrentPage, limit: 20 });
        if (scene) params.set('scene', scene);
        const data = await request('/admin/filter-logs?' + params.toString());
        const list = data.list || [];
        document.getElementById('fl-table').innerHTML = list.map(l => `
            <tr>
                <td data-label="ID">${l.id}</td>
                <td data-label="用户">${escapeHtml(l.nickname || l.username || ('ID:' + l.user_id))}</td>
                <td data-label="场景">${FL_SCENE_TEXT[l.scene] || l.scene}</td>
                <td data-label="处理"><span class="badge ${SW_LEVEL_BADGE[l.action] || 'badge-default'}">${SW_LEVEL_TEXT[l.action] || l.action}</span></td>
                <td data-label="命中词">${escapeHtml(l.words || '-')}</td>
                <td data-label="原文片段" data-full>${escapeHtml(l.excerpt || '-')}</td>
                <td data-label="时间">${l.created_at}</td>
            </tr>
        `).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;">暂无命中记录</td></tr>';
        renderModerationPagination('fl-pagination', data, loadFilterLogs);
    } catch(e) { showToast(e.message); }
}

/** 全站禁言：弹窗问时长（小时），0/取消则不处理 */
export async function muteUserPrompt(userId) {
    const hours = prompt('禁言时长（小时，可填小数，例如 0.5 = 30 分钟）：', '24');
    if (hours === null) return;
    const h = parseFloat(hours);
    if (!h || h <= 0) { showToast('时长必须大于 0'); return; }
    try {
        const data = await request('/admin/users/mute', { method: 'POST', body: { user_id: userId, minutes: Math.round(h * 60) } });
        showToast(data.muted_until ? ('已禁言至 ' + data.muted_until) : '已禁言');
        await loadUsers(currentPage.users);
    } catch(e) { showToast(e.message); }
}

export async function unmuteUserAction(userId) {
    if (!confirm('确定解除该用户的禁言？')) return;
    try {
        await request('/admin/users/unmute', { method: 'POST', body: { user_id: userId } });
        showToast('已解除禁言');
        await loadUsers(currentPage.users);
    } catch(e) { showToast(e.message); }
}

// B4a 委托化：敏感词编辑 / 删除（renderModerationPagination 的动态函数名分页留 B6）
registerActions({
    'edit-word': (d) => editWord(+d.id),
    'delete-word': (d) => deleteWord(+d.id),
    // B4b 委托化：静态搜索 / 添加 / 批量导入 / 保存取消 / 命中记录刷新
    'load-sensitive-words': (d) => loadSensitiveWords(+(d.page || 1)),
    'open-word-form': () => openWordForm(),
    'toggle-import-box': () => toggleImportBox(),
    'import-words': () => importWords(),
    'save-word': () => saveWord(),
    'close-word-form': () => closeWordForm(),
    'load-filter-logs': (d) => loadFilterLogs(+(d.page || 1)),
    // B6 委托化：敏感词搜索回车（原 onkeydown 直调）
    'enter-sensitive': (d, el, e) => { if (e.key === 'Enter') loadSensitiveWords(); },
});