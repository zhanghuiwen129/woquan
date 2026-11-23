// ============================================================
// shop.js — 积分商城：商品、订单、发货、折扣卡
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';



/* ============================================================
 * 商城管理（V143）
 *   商品上架（含会员折扣卡）/ 商城订单发货 / 折扣卡发放记录。
 *   之前后台没有商品入口，上架只能手写 SQL —— 折扣卡这种要频繁调价的活动品
 *   完全没法运营，所以这里补齐。
 * ============================================================ */
export let shopCurrentTab = 'products';
export let shopCurrentProductId = null;
export let shopProductCache = {};   // id -> 商品对象，编辑时直接用，避免再发一次请求

export const SHOP_TYPE_LABEL = { vip: '折扣会员', coupon: '会员折扣卡', physical: '圈子周边', info: '圈友好物' };
export const VIP_LEVEL_LABEL = { 1: '青铜', 2: '白银', 3: '黄金' };

export function loadShop() {
    shopTab(shopCurrentTab);
}

export function shopTab(tab) {
    shopCurrentTab = tab;
    ['products', 'orders', 'coupons'].forEach(t => {
        const pane = document.getElementById('shop-pane-' + t);
        const btn = document.getElementById('shop-tab-' + t);
        if (pane) pane.classList.toggle('hidden', t !== tab);
        if (btn) btn.style.fontWeight = (t === tab) ? '600' : '';
    });
    if (tab === 'products') loadShopProducts();
    else if (tab === 'orders') loadShopOrders();
    else if (tab === 'coupons') loadShopCoupons();
}

// B2 委托化：商城 Tab 切换（data-action="shop-tab" data-value="products|orders|coupons"）
registerActions({
    'shop-tab': (d) => shopTab(d.value),
});

/** 折扣卡配置转成一句人话，例：8折（限白银/黄金） */
export function shopCouponDesc(mode, value, levels) {
    const v = Number(value) || 0;
    let txt;
    if (mode === 'amount') {
        txt = '立减 ¥' + v;
    } else {
        const zhe = (100 - v) / 10;
        txt = Math.abs(zhe - Math.round(zhe)) < 0.0001 ? (Math.round(zhe) + '折') : ('减 ' + v + '%');
    }
    const lv = (String(levels || '').split(',').map(x => parseInt(x, 10)).filter(x => x >= 1 && x <= 3));
    if (lv.length && lv.length < 3) txt += '（限' + lv.map(x => VIP_LEVEL_LABEL[x]).join('/') + '）';
    return txt;
}

export async function loadShopProducts() {
    try {
        const type = document.getElementById('shop-filter-type').value;
        const status = document.getElementById('shop-filter-status').value;
        const keyword = document.getElementById('shop-keyword').value.trim();
        const qs = ['limit=50'];
        if (type) qs.push('type=' + encodeURIComponent(type));
        if (status) qs.push('status=' + encodeURIComponent(status));
        if (keyword) qs.push('keyword=' + encodeURIComponent(keyword));
        const data = await request('/admin/shop-products?' + qs.join('&'));
        const list = data.list || [];
        shopProductCache = {};
        document.getElementById('shop-products-table').innerHTML = list.map(p => {
            shopProductCache[p.id] = p;
            let cfg = '-';
            if (p.type === 'vip') cfg = (VIP_LEVEL_LABEL[p.vip_level] || '会员') + ' · ' + p.vip_days + ' 天';
            else if (p.type === 'coupon') cfg = shopCouponDesc(p.discount_mode, p.discount_value, p.vip_levels) + (Number(p.valid_days) > 0 ? ' · ' + p.valid_days + ' 天有效' : ' · 长期');
            else if (p.type === 'physical') cfg = '需发货';
            const price = [];
            if (Number(p.price_balance) > 0) price.push('¥' + p.price_balance);
            if (Number(p.price_points) > 0) price.push(p.price_points + ' 积分');
            // 圈友自发发布的好物（seller_id>0）不支持后台改，编辑接口也会拒绝
            const editable = Number(p.seller_id) === 0;
            return `<tr>
                <td data-label="类型"><span class="badge ${p.type === 'coupon' ? 'badge-warning' : 'badge-default'}">${SHOP_TYPE_LABEL[p.type] || p.type}</span></td>
                <td data-label="标题">${escapeHtml(p.title || '')}</td>
                <td data-label="售价">${price.join(' / ') || '-'}</td>
                <td data-label="配置">${escapeHtml(cfg)}</td>
                <td data-label="库存/销量">${p.stock}/${p.sales || 0}</td>
                <td data-label="状态"><span class="badge ${p.status === 1 ? 'badge-success' : 'badge-danger'}">${p.status === 1 ? '已上架' : '已下架'}</span></td>
                <td data-label="操作" data-actions>
                    ${editable ? `<button class="btn btn-default" data-action="edit-shop-product" data-id="${p.id}">编辑</button>` : '<span style="color:var(--text-muted);">圈友发布</span>'}
                    <button class="btn btn-danger" data-action="delete-shop-product" data-id="${p.id}">下架</button>
                </td>
            </tr>`;
        }).join('') || '<tr><td colspan="7" style="text-align:center;padding:40px;">还没有商品，点右上角上架</td></tr>';
    } catch (e) { showToast(e.message); }
}

/** 按类型显示/隐藏专属表单块，并更新折扣预览文案 */
export function syncShopProductType() {
    const type = document.getElementById('shop-p-type').value;
    document.getElementById('shop-block-vip').classList.toggle('hidden', type !== 'vip');
    document.getElementById('shop-block-coupon').classList.toggle('hidden', type !== 'coupon');
    const mode = document.getElementById('shop-p-discount-mode').value;
    document.getElementById('shop-p-discount-label').textContent =
        mode === 'amount' ? '立减金额（元）：填 10 = 直接减 ¥10' : '减免百分比：填 20 = 减 20%（即 8 折）';
    const v = Number(document.getElementById('shop-p-discount-value').value) || 0;
    const levels = [1, 2, 3].filter(x => document.getElementById('shop-p-level-' + x).checked).join(',');
    document.getElementById('shop-p-discount-preview').textContent =
        v > 0 ? ('效果预览：' + shopCouponDesc(mode, v, levels)) : '';
    return true;
}

export function openShopProductForm(type) {
    shopCurrentProductId = null;
    document.getElementById('shop-p-id').value = '';
    document.getElementById('shop-p-type').value = type || 'coupon';
    document.getElementById('shop-p-title').value = '';
    document.getElementById('shop-p-cover').value = '';
    document.getElementById('shop-p-content').value = '';
    document.getElementById('shop-p-price-balance').value = '';
    document.getElementById('shop-p-price-points').value = '';
    document.getElementById('shop-p-vip-level').value = '1';
    document.getElementById('shop-p-vip-days').value = '30';
    document.getElementById('shop-p-discount-mode').value = 'percent';
    document.getElementById('shop-p-discount-value').value = '20';
    [1, 2, 3].forEach(x => { document.getElementById('shop-p-level-' + x).checked = false; });
    document.getElementById('shop-p-valid-days').value = '0';
    document.getElementById('shop-p-stock').value = '0';
    document.getElementById('shop-p-sort').value = '0';
    document.getElementById('shop-p-status').checked = true;
    document.getElementById('shop-product-form-title').textContent =
        '上架' + (SHOP_TYPE_LABEL[type || 'coupon'] || '商品');
    document.getElementById('shop-product-form').classList.remove('hidden');
    syncShopProductType();
    document.getElementById('shop-p-title').focus();
}

export function editShopProduct(id) {
    const p = shopProductCache[id];
    if (!p) return;
    shopCurrentProductId = id;
    document.getElementById('shop-p-id').value = id;
    document.getElementById('shop-p-type').value = p.type;
    document.getElementById('shop-p-title').value = p.title || '';
    document.getElementById('shop-p-cover').value = p.cover || '';
    document.getElementById('shop-p-content').value = p.content || '';
    document.getElementById('shop-p-price-balance').value = p.price_balance || '';
    document.getElementById('shop-p-price-points').value = p.price_points || '';
    document.getElementById('shop-p-vip-level').value = String(p.vip_level || 1);
    document.getElementById('shop-p-vip-days').value = p.vip_days || 30;
    document.getElementById('shop-p-discount-mode').value = p.discount_mode || 'percent';
    document.getElementById('shop-p-discount-value').value = p.discount_value || '';
    const lv = String(p.vip_levels || '').split(',').map(x => parseInt(x, 10));
    [1, 2, 3].forEach(x => { document.getElementById('shop-p-level-' + x).checked = lv.indexOf(x) >= 0; });
    document.getElementById('shop-p-valid-days').value = p.valid_days || 0;
    document.getElementById('shop-p-stock').value = p.stock || 0;
    document.getElementById('shop-p-sort').value = p.sort || 0;
    document.getElementById('shop-p-status').checked = Number(p.status) === 1;
    document.getElementById('shop-product-form-title').textContent = '编辑商品（ID ' + id + '）';
    document.getElementById('shop-product-form').classList.remove('hidden');
    syncShopProductType();
}

export function closeShopProductForm() {
    document.getElementById('shop-product-form').classList.add('hidden');
}

export async function saveShopProduct() {
    const type = document.getElementById('shop-p-type').value;
    const levels = [1, 2, 3].filter(x => document.getElementById('shop-p-level-' + x).checked).join(',');
    const body = {
        id: shopCurrentProductId,
        type: type,
        title: document.getElementById('shop-p-title').value.trim(),
        cover: document.getElementById('shop-p-cover').value.trim(),
        content: document.getElementById('shop-p-content').value.trim(),
        price_balance: parseFloat(document.getElementById('shop-p-price-balance').value) || 0,
        price_points: parseInt(document.getElementById('shop-p-price-points').value, 10) || 0,
        vip_level: type === 'vip' ? parseInt(document.getElementById('shop-p-vip-level').value, 10) : 0,
        vip_days: type === 'vip' ? parseInt(document.getElementById('shop-p-vip-days').value, 10) : 0,
        discount_mode: document.getElementById('shop-p-discount-mode').value,
        discount_value: parseFloat(document.getElementById('shop-p-discount-value').value) || 0,
        vip_levels: type === 'coupon' ? levels : '',
        valid_days: type === 'coupon' ? (parseInt(document.getElementById('shop-p-valid-days').value, 10) || 0) : 0,
        stock: parseInt(document.getElementById('shop-p-stock').value, 10) || 0,
        sort: parseInt(document.getElementById('shop-p-sort').value, 10) || 0,
        status: document.getElementById('shop-p-status').checked ? 1 : 0
    };
    if (!body.title) { showToast('请填写商品标题'); return; }
    if (type === 'coupon' && body.discount_value <= 0) { showToast('请填写折扣力度'); return; }
    try {
        await request('/admin/shop-product-save', { method: 'POST', body });
        showToast('已保存');
        closeShopProductForm();
        loadShopProducts();
    } catch (e) { showToast(e.message); }
}

export async function deleteShopProduct(id) {
    if (!confirm('确认下架该商品？\n已购买用户的订单与已发出的折扣卡不受影响。')) return;
    try {
        await request('/admin/shop-product-delete', { method: 'POST', body: { id } });
        showToast('已下架');
        loadShopProducts();
    } catch (e) { showToast(e.message); }
}

export async function loadShopOrders() {
    try {
        const data = await request('/admin/shop-orders?limit=50');
        const list = data.list || [];
        const shipText = { none: '无需发货', pending: '待发货', shipped: '已发货', completed: '已完成' };
        document.getElementById('shop-orders-table').innerHTML = list.map(o => {
            const amount = Number(o.pay_currency === 'balance' ? o.amount : o.points) || 0;
            const money = o.pay_currency === 'balance' ? ('¥' + amount) : (amount + ' 积分');
            let act = '-';
            if (o.product_type === 'physical' && o.ship_status !== 'completed') {
                const next = o.ship_status === 'pending' ? 'shipped' : 'shipped';
                act = `<button class="btn btn-default" data-action="shop-ship" data-id="${o.id}" data-value="${next}">标记发货</button>
                       <button class="btn btn-default" data-action="shop-ship" data-id="${o.id}" data-value="completed">完成</button>`;
            }
            return `<tr>
                <td data-label="订单号">${escapeHtml(o.order_no || '')}</td>
                <td data-label="买家">${escapeHtml(o.buyer_name || o.buyer_username || '未知')}</td>
                <td data-label="商品">${escapeHtml(o.product_title || '商品已删除')}</td>
                <td data-label="金额">${money}</td>
                <td data-label="状态"><span class="badge badge-success">${o.status === 'paid' ? '已付款' : o.status}</span>
                    ${o.product_type === 'physical' ? `<span class="badge badge-default">${shipText[o.ship_status] || o.ship_status}</span>` : ''}</td>
                <td data-label="操作" data-actions>${act}</td>
            </tr>`;
        }).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;">还没有商城订单</td></tr>';
    } catch (e) { showToast(e.message); }
}

export async function shopShip(id, status) {
    try {
        await request('/admin/shop-ship', { method: 'POST', body: { id, ship_status: status } });
        showToast('已更新');
        loadShopOrders();
    } catch (e) { showToast(e.message); }
}

// B4a 委托化：商城商品编辑/下架 / 订单发货状态
registerActions({
    'edit-shop-product': (d) => editShopProduct(+d.id),
    'delete-shop-product': (d) => deleteShopProduct(+d.id),
    'shop-ship': (d) => shopShip(+d.id, d.value),
    // B4b 委托化：静态上架按钮（coupon/vip/physical）/ 表单保存取消
    'open-shop-product-form': (d) => openShopProductForm(d.value),
    'save-shop-product': () => saveShopProduct(),
    'close-shop-product-form': () => closeShopProductForm(),
    // B6 委托化：商品/券筛选与类型联动（原 onchange/oninput 直调）
    'load-shop-products': () => loadShopProducts(),
    'sync-shop-product-type': () => syncShopProductType(),
    'load-shop-coupons': () => loadShopCoupons(),
});

export async function loadShopCoupons() {
    try {
        const status = document.getElementById('shop-coupon-status').value;
        const keyword = document.getElementById('shop-coupon-keyword').value.trim();
        const qs = ['limit=50'];
        if (status) qs.push('status=' + encodeURIComponent(status));
        if (keyword) qs.push('keyword=' + encodeURIComponent(keyword));
        const data = await request('/admin/shop-coupons?' + qs.join('&'));
        const list = data.list || [];
        const stText = { unused: '未使用', used: '已使用', expired: '已过期' };
        document.getElementById('shop-coupons-table').innerHTML = list.map(c => `<tr>
            <td data-label="用户">${escapeHtml(c.user_name || c.user_account || '#' + c.user_id)}</td>
            <td data-label="折扣卡">${escapeHtml(c.title || '')}</td>
            <td data-label="折扣">${escapeHtml(c.coupon_desc || '')}</td>
            <td data-label="状态"><span class="badge ${c.status === 'unused' ? 'badge-success' : 'badge-default'}">${stText[c.status] || c.status}</span></td>
            <td data-label="有效期">${c.expire_at ? escapeHtml(String(c.expire_at).slice(0, 10)) : '长期'}</td>
            <td data-label="核销订单">${escapeHtml(c.used_order_no || '-')}</td>
        </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;">还没有发出折扣卡</td></tr>';
    } catch (e) { showToast(e.message); }
}