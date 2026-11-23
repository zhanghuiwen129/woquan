// ============================================================
// categories.js — 分类管理
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';



// 分类管理
export let currentCategoryId = null;
export let currentCategories = [];

export function flattenCategories(categories, level = 0) {
    const result = [];
    categories.forEach(cat => {
        result.push({ ...cat, level });
        if (cat.children && cat.children.length > 0) {
            result.push(...flattenCategories(cat.children, level + 1));
        }
    });
    return result;
}

export async function loadCategories() {
    try {
        currentCategories = await request('/admin/categories');
        const flat = flattenCategories(currentCategories);
        const tbody = document.getElementById('categories-table');
        tbody.innerHTML = flat.map(cat => `
            <tr>
                <td data-label="排序">${cat.sort_order}</td>
                <td data-label="名称" style="padding-left:${12 + cat.level * 24}px">${'　'.repeat(cat.level)}${cat.level > 0 ? '├ ' : ''}${escapeHtml(cat.name)}</td>
                <td data-label="层级">${cat.level === 0 ? '一级' : '二级'}</td>
                <td data-label="状态"><span class="badge ${cat.status===1?'badge-success':'badge-danger'}">${cat.status===1?'启用':'禁用'}</span></td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-default" data-action="edit-category" data-id="${cat.id}">编辑</button>
                    <button class="btn btn-danger" data-action="delete-category" data-id="${cat.id}">删除</button>
                </td>
            </tr>
        `).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;">暂无分类</td></tr>';
    } catch(e) { showToast(e.message); }
}

export function openCategoryForm() {
    currentCategoryId = null;
    document.getElementById('category-id').value = '';
    document.getElementById('category-name').value = '';
    document.getElementById('category-parent_id').value = '';
    document.getElementById('category-sort_order').value = '0';
    document.getElementById('category-status').checked = true;
    document.getElementById('category-form-title').textContent = '添加分类';
    renderCategoryParentOptions();
    document.getElementById('category-form').classList.remove('hidden');
}

export function closeCategoryForm() {
    document.getElementById('category-form').classList.add('hidden');
}

export function renderCategoryParentOptions(excludeId = null) {
    const select = document.getElementById('category-parent_id');
    select.innerHTML = '<option value="">无（一级分类）</option>';
    const flat = flattenCategories(currentCategories).filter(c => c.status === 1 && c.level === 0 && c.id !== excludeId);
    flat.forEach(c => {
        select.appendChild(new Option(c.name, c.id));
    });
}

export async function editCategory(id) {
    currentCategoryId = id;
    try {
        const flat = flattenCategories(currentCategories);
        const cat = flat.find(c => c.id === id);
        if (!cat) return;
        document.getElementById('category-id').value = cat.id;
        document.getElementById('category-name').value = cat.name;
        document.getElementById('category-sort_order').value = cat.sort_order;
        document.getElementById('category-status').checked = cat.status === 1;
        document.getElementById('category-form-title').textContent = '编辑分类';
        renderCategoryParentOptions(cat.id);
        document.getElementById('category-parent_id').value = cat.parent_id || '';
        document.getElementById('category-form').classList.remove('hidden');
    } catch(e) { showToast(e.message); }
}

export async function saveCategory() {
    const id = currentCategoryId;
    const body = {
        id: id,
        name: document.getElementById('category-name').value.trim(),
        parent_id: document.getElementById('category-parent_id').value || 0,
        sort_order: parseInt(document.getElementById('category-sort_order').value || '0', 10),
        status: document.getElementById('category-status').checked ? 1 : 0
    };
    if (!body.name) {
        showToast('分类名称不能为空');
        return;
    }
    try {
        await request('/admin/categories', { method: id ? 'PUT' : 'POST', body });
        showToast('保存成功');
        closeCategoryForm();
        loadCategories();
    } catch(e) { showToast(e.message); }
}

export async function deleteCategory(id) {
    if (!confirm('确定删除该分类？')) return;
    try {
        await request('/admin/categories?id=' + id, { method: 'DELETE' });
        showToast('删除成功');
        loadCategories();
    } catch(e) { showToast(e.message); }
}

// B4a 委托化：分类编辑 / 删除
registerActions({
    'edit-category': (d) => editCategory(+d.id),
    'delete-category': (d) => deleteCategory(+d.id),
    // B4b 委托化：静态添加 / 表单保存取消
    'open-category-form': () => openCategoryForm(),
    'save-category': () => saveCategory(),
    'close-category-form': () => closeCategoryForm(),
});