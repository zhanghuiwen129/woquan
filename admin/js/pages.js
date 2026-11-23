// ============================================================
// pages.js — 通用单页管理（B2-1 / B2-3 / B2-4）
//
// 依赖 core.js 的委托化事件注册：本模块被 nav-ui.js 按需 import 时，
// registerActions 把动作挂到全站唯一监听表；重复 import 会 Object.assign 覆盖同名键，
// 幂等无副作用。Vditor 未加载时降级为原生 textarea（与 posts.js 同口径）。
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';

let pageRows = [];
let pageVditor = null;

/** 取正文：Vditor 优先，未加载时回落原生 textarea */
function currentContent() {
    return pageVditor ? pageVditor.getValue() : (document.getElementById('pages-content')?.value || '');
}

/** 初始化 / 复位 Vditor；Vditor 缺失时显示原生 textarea */
function initPageVditor(initial) {
    const Vditor = window.Vditor;
    const fallback = document.getElementById('pages-content');
    if (!Vditor) {
        if (pageVditor) { try { pageVditor.destroy(); } catch (e) { /* 已销毁 */ } pageVditor = null; }
        if (fallback) {
            fallback.classList.remove('hidden');
            fallback.value = initial;
        }
        return;
    }
    if (fallback) fallback.classList.add('hidden');
    if (pageVditor) {
        pageVditor.setValue(initial || '');
        return;
    }
    pageVditor = new Vditor('pages-vditor', {
        mode: 'wysiwyg',
        cdn: './vendor/vditor',
        cache: { enable: false },
        placeholder: '单页正文，支持 Markdown 语法',
        height: 320,
        after: () => { pageVditor.setValue(initial || ''); }
    });
}

/** 读取列表并渲染表格 */
export async function loadPages() {
    try {
        const data = await request('/admin/pages');
        pageRows = Array.isArray(data?.list) ? data.list : [];
        const tbody = document.getElementById('pages-table');
        if (!tbody) return;
        tbody.innerHTML = pageRows.map(p => {
            const published = Number(p.status) === 1;
            const slug = escapeHtml(String(p.slug || ''));
            const title = escapeHtml(String(p.title || ''));
            const source = escapeHtml(String(p.source || 'manual'));
            const updated = escapeHtml(String(p.updated_at || ''));
            return `<tr>
                <td data-label="slug"><code>${slug}</code></td>
                <td data-label="标题">${title}</td>
                <td data-label="状态"><span class="badge ${published ? 'badge-success' : 'badge-danger'}">${published ? '已发布' : '草稿'}</span></td>
                <td data-label="来源">${source}</td>
                <td data-label="更新时间">${updated}</td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-default" data-action="edit-page" data-id="${Number(p.id)}">编辑</button>
                    <button class="btn btn-default" data-action="preview-page-row" data-id="${Number(p.id)}">预览</button>
                    <button class="btn btn-default" data-action="toggle-page-publish" data-id="${Number(p.id)}">${published ? '撤回' : '发布'}</button>
                    <button class="btn btn-danger" data-action="delete-page" data-id="${Number(p.id)}">删除</button>
                </td>
            </tr>`;
        }).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;">暂无单页</td></tr>';
    } catch (e) {
        showToast(e.message);
    }
}

function findPage(id) {
    const numericId = Number(id);
    return pageRows.find(p => Number(p.id) === numericId) || null;
}

export function openPageForm() {
    document.getElementById('pages-id').value = '';
    document.getElementById('pages-slug').value = '';
    document.getElementById('pages-title').value = '';
    document.getElementById('pages-seo-title').value = '';
    document.getElementById('pages-seo-description').value = '';
    document.getElementById('pages-seo-keywords').value = '';
    document.getElementById('pages-sort-order').value = '0';
    document.getElementById('pages-status').checked = false;
    document.getElementById('pages-form-title').textContent = '新建单页';
    document.getElementById('pages-form').classList.remove('hidden');
    document.getElementById('pages-preview').classList.add('hidden');
    initPageVditor('');
}

export function closePageForm() {
    document.getElementById('pages-form').classList.add('hidden');
}

export function editPage(id) {
    const p = findPage(id);
    if (!p) { showToast('数据已过期，请刷新列表后重试'); return; }
    document.getElementById('pages-id').value = String(p.id);
    document.getElementById('pages-slug').value = String(p.slug || '');
    document.getElementById('pages-title').value = String(p.title || '');
    document.getElementById('pages-seo-title').value = String(p.seo_title || '');
    document.getElementById('pages-seo-description').value = String(p.seo_description || '');
    document.getElementById('pages-seo-keywords').value = String(p.seo_keywords || '');
    document.getElementById('pages-sort-order').value = String(p.sort_order || 0);
    document.getElementById('pages-status').checked = Number(p.status) === 1;
    document.getElementById('pages-form-title').textContent = '编辑单页 #' + p.id;
    document.getElementById('pages-form').classList.remove('hidden');
    document.getElementById('pages-preview').classList.add('hidden');
    initPageVditor(String(p.content || ''));
}

export async function savePage() {
    const id = Number(document.getElementById('pages-id').value || 0);
    const slug = document.getElementById('pages-slug').value.trim().toLowerCase();
    const title = document.getElementById('pages-title').value.trim();
    const payload = {
        slug,
        title,
        content: currentContent(),
        status: document.getElementById('pages-status').checked ? 1 : 0,
        seo_title: document.getElementById('pages-seo-title').value.trim(),
        seo_description: document.getElementById('pages-seo-description').value.trim(),
        seo_keywords: document.getElementById('pages-seo-keywords').value.trim(),
        sort_order: Number(document.getElementById('pages-sort-order').value || 0)
    };
    if (slug === '' || title === '') { showToast('请填写 slug 与标题'); return; }
    try {
        if (id > 0) {
            payload.id = id;
            await request('/admin/pages', { method: 'PUT', body: payload });
        } else {
            await request('/admin/pages', { method: 'POST', body: payload });
        }
        showToast('保存成功');
        closePageForm();
        await loadPages();
    } catch (e) {
        showToast(e.message);
    }
}

export async function deletePage(id) {
    const numericId = Number(id);
    if (!numericId || !window.confirm('确认删除该单页？删除后公开接口立即返回 404。')) return;
    try {
        await request('/admin/pages', { method: 'DELETE', body: { id: numericId } });
        showToast('删除成功');
        await loadPages();
    } catch (e) {
        showToast(e.message);
    }
}

export async function togglePagePublish(id) {
    const p = findPage(id);
    if (!p) { showToast('数据已过期，请刷新列表后重试'); return; }
    const status = Number(p.status) === 1 ? 0 : 1;
    try {
        await request('/admin/pages/publish', { method: 'POST', body: { id: Number(p.id), status } });
        showToast(status === 1 ? '已发布' : '已撤回');
        await loadPages();
    } catch (e) {
        showToast(e.message);
    }
}

/** 预览：Vditor 可用时按 Markdown 渲染，否则纯文本展示 */
export function renderPagePreview(title, content) {
    const box = document.getElementById('pages-preview-body');
    const head = document.getElementById('pages-preview-title');
    if (!box) return;
    head.textContent = '正文预览 · ' + (title || '未命名');
    box.innerHTML = '';
    const Vditor = window.Vditor;
    if (Vditor && typeof Vditor.preview === 'function') {
        Vditor.preview(box, content || '', { cdn: './vendor/vditor', mode: 'light' });
    } else {
        box.textContent = content || '（空正文）';
    }
    document.getElementById('pages-preview').classList.remove('hidden');
}

export function previewPage() {
    renderPagePreview(
        document.getElementById('pages-title').value.trim(),
        currentContent()
    );
}

export function previewPageRow(id) {
    const p = findPage(id);
    if (!p) { showToast('数据已过期，请刷新列表后重试'); return; }
    renderPagePreview(String(p.title || ''), String(p.content || ''));
}

export function closePagePreview() {
    document.getElementById('pages-preview').classList.add('hidden');
}

registerActions({
    'open-page-form': () => openPageForm(),
    'close-page-form': () => closePageForm(),
    'save-page': () => savePage(),
    'delete-page': (ds) => deletePage(ds.id),
    'toggle-page-publish': (ds) => togglePagePublish(ds.id),
    'preview-page': () => previewPage(),
    'preview-page-row': (ds) => previewPageRow(ds.id),
    'close-page-preview': () => closePagePreview(),
    'reload-pages': () => loadPages()
});
