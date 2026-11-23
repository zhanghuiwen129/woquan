// ============================================================
// content-pay.js — 内容付费管理（定价 / 专栏 / 订单），V97
// 依赖 core.js 的 registerActions / request / escapeHtml / showToast
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';

const PANELS = ['product', 'column', 'orders'];
let currentTab = 'product';
let orderStatus = '';
let orderPage = 1;
let productsCache = [];

export function contentPayTab(kind) {
    currentTab = kind;
    PANELS.forEach(p => {
        const el = document.getElementById('panel-content-pay-' + p);
        if (el) el.classList.toggle('hidden', p !== kind);
    });
    document.querySelectorAll('[data-action="content-pay-tab"]').forEach(el => {
        el.classList.toggle('active', el.dataset.value === kind);
    });
}

/** 页面加载入口（由 nav-ui 的 pageLoaders 调用） */
export function loadContentPay() {
    contentPayTab('product');
    return loadContentPayData();
}

export async function loadContentPayData(refresh = false) {
    if (refresh) orderPage = 1;
    const statusSel = document.getElementById('cp-order-status');
    if (statusSel) orderStatus = statusSel.value || '';
    try {
        await Promise.all([
            loadProductList(),
            loadColumns(),
            loadOrders(),
        ]);
    } catch (e) {
        showToast(e.message || '加载失败');
    }
}

async function loadProductList() {
    // 商品列表暂无独立接口，随订单页带出；这里从订单反推已购商品不够完整，
    // 故用 columns + 订单的 product/target 做展示。真正定价数据以保存回显为准。
    const tbody = document.getElementById('cp-products-table');
    if (!tbody) return;
    // 通过订单接口拿不到全部商品；提供只读提示，避免误以为空
    if (!productsCache.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="color:var(--text-muted,#888);">在上方表单给帖子/专栏定价后，权益即时生效。已定价商品会在订单中体现。</td></tr>';
        return;
    }
    tbody.innerHTML = productsCache.map(p =>
        '<tr><td>' + esc(p.product_type === 'column' ? '专栏' : '单篇') + '</td>'
        + '<td>' + esc(p.target_id) + '</td><td>' + esc(p.post_type) + '</td>'
        + '<td>¥' + esc(Number(p.price).toFixed(2)) + '</td><td>' + esc(p.preview_ratio) + '%</td>'
        + '<td>' + esc(p.valid_days || '-') + '</td><td>' + (Number(p.status) === 1 ? '上架' : '下架') + '</td>'
        + '<td>' + esc(p.sold_count || 0) + '</td></tr>'
    ).join('');
}

async function loadColumns() {
    const tbody = document.getElementById('cp-columns-table');
    if (!tbody) return;
    const data = await request('/admin/content-pay/columns');
    const list = (data && data.list) || [];
    tbody.innerHTML = list.length
        ? list.map(c =>
            '<tr><td>' + esc(c.id) + '</td><td>' + esc(c.title) + '</td><td>' + esc(c.owner_id) + '</td>'
            + '<td>' + esc(c.valid_days) + '天</td><td>' + esc(c.item_count) + '</td>'
            + '<td>' + (Number(c.status) === 1 ? '上架' : '下架') + '</td></tr>'
        ).join('')
        : '<tr><td colspan="6" style="color:var(--text-muted,#888);">暂无专栏</td></tr>';
}

async function loadOrders() {
    const tbody = document.getElementById('cp-orders-table');
    if (!tbody) return;
    const qs = 'status=' + encodeURIComponent(orderStatus) + '&page=' + orderPage + '&limit=20';
    const data = await request('/admin/content-pay/orders?' + qs);
    const list = (data && data.list) || [];
    tbody.innerHTML = list.length
        ? list.map(o =>
            '<tr><td>' + esc(o.id) + '</td><td>' + esc(o.user_id) + '</td>'
            + '<td>' + esc(o.product_type === 'column' ? '专栏' : '单篇') + '</td><td>' + esc(o.target_id) + '</td>'
            + '<td>¥' + esc(Number(o.amount).toFixed(2)) + '</td><td>' + esc(o.points_used) + '</td>'
            + '<td>¥' + esc(Number(o.pay_amount).toFixed(2)) + '</td><td>' + esc(o.status) + '</td>'
            + '<td>' + esc(o.order_no) + '</td><td>' + esc(o.created_at) + '</td></tr>'
        ).join('')
        : '<tr><td colspan="10" style="color:var(--text-muted,#888);">暂无订单</td></tr>';
    renderCpPagination(data);
}

function renderCpPagination(data) {
    const host = document.getElementById('cp-orders-pagination');
    if (!host) return;
    const total = (data && data.total) || 0;
    const pages = Math.max(1, Math.ceil(total / 20));
    host.innerHTML = '<span>共 ' + total + ' 条 · 第 ' + orderPage + '/' + pages + ' 页</span>'
        + '<span><button class="btn" data-action="cp-order-page" data-page="' + Math.max(1, orderPage - 1) + '"' + (orderPage <= 1 ? ' disabled' : '') + '>上一页</button> '
        + '<button class="btn" data-action="cp-order-page" data-page="' + Math.min(pages, orderPage + 1) + '"' + (orderPage >= pages ? ' disabled' : '') + '>下一页</button></span>';
}

function esc(v) { return escapeHtml(v == null ? '' : String(v)); }

registerActions({
    'content-pay-tab': (el) => contentPayTab(el.dataset.value),
    'load-content-pay': (el) => loadContentPayData(el && el.dataset.refresh === '1'),
    'cp-order-page': (el) => { orderPage = parseInt(el.dataset.page, 10) || 1; loadOrders(); },
    'cp-save-product': async () => {
        const body = {
            product_type: (document.getElementById('cp-product-type') || {}).value || 'post',
            target_id: parseInt((document.getElementById('cp-target-id') || {}).value, 10) || 0,
            post_type: (document.getElementById('cp-post-type') || {}).value || 'normal',
            price: parseFloat((document.getElementById('cp-price') || {}).value) || 0,
            preview_ratio: parseInt((document.getElementById('cp-ratio') || {}).value, 10) || 0,
            valid_days: parseInt((document.getElementById('cp-valid-days') || {}).value, 10) || 0,
            status: 1,
        };
        if (!body.target_id) { showToast('请填写目标 ID'); return; }
        try {
            await request('/admin/content-pay/product', { method: 'POST', body });
            showToast('定价已保存');
        } catch (e) { showToast(e.message || '保存失败'); }
    },
    'cp-save-column': async () => {
        const body = {
            title: (document.getElementById('cp-col-title') || {}).value || '',
            owner_id: parseInt((document.getElementById('cp-col-owner') || {}).value, 10) || 0,
            valid_days: parseInt((document.getElementById('cp-col-days') || {}).value, 10) || 365,
        };
        if (!body.title) { showToast('请填写专栏标题'); return; }
        try {
            await request('/admin/content-pay/column', { method: 'POST', body });
            showToast('专栏已创建');
            loadColumns();
        } catch (e) { showToast(e.message || '创建失败'); }
    },
    'cp-save-item': async () => {
        const body = {
            column_id: parseInt((document.getElementById('cp-item-column') || {}).value, 10) || 0,
            post_id: parseInt((document.getElementById('cp-item-post') || {}).value, 10) || 0,
            sort_order: parseInt((document.getElementById('cp-item-sort') || {}).value, 10) || 0,
        };
        if (!body.column_id || !body.post_id) { showToast('请填写专栏 ID 和帖子 ID'); return; }
        try {
            await request('/admin/content-pay/item', { method: 'POST', body });
            showToast('条目已加入');
            loadColumns();
        } catch (e) { showToast(e.message || '加入失败'); }
    },
});
