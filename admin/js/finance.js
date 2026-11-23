// ============================================================
// finance.js — 财务与会员：VIP 订单、钱包流水
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { loadPointTasks, loadPointsLogs } from './points.js';
import { escapeHtml, registerActions, request, showToast } from './core.js';



// ==================== V67 财务 / 会员 ====================
export let financeCurrentTab = 'vip';
export let vipOrdersCurrentPage = 1;
export let walletLogsCurrentPage = 1;
export let pointsLogsCurrentPage = 1;

// V176：横向标签页。5 个主 Tab——vip/wallet/points 是数据面板（loadFinance 拉数据），
// pay/ops 是纯表单面板（无需拉数据）。面板 id 统一为 panel-finance-<value>。
const FINANCE_PANELS = ['vip', 'wallet', 'points', 'pay', 'ops'];

/** 切主标签：显隐面板 + 点亮 Tab；「刷新」按钮只对数据 Tab 有意义 */
export function financeTab(kind) {
    financeCurrentTab = kind;
    FINANCE_PANELS.forEach(p => {
        const el = document.getElementById('panel-finance-' + p);
        if (el) el.classList.toggle('hidden', p !== kind);
    });
    document.querySelectorAll('[data-action="finance-tab"]').forEach(el => {
        el.classList.toggle('active', el.dataset.value === kind);
    });
    const refreshBtn = document.getElementById('finance-refresh-btn');
    if (refreshBtn) refreshBtn.classList.toggle('hidden', kind === 'pay' || kind === 'ops');
}

// B2 委托化：财务 Tab 切换（data-action="finance-tab" data-value="vip|wallet|points|pay|ops"）
registerActions({
    'finance-tab': (d) => financeTab(d.value),
});

// ==================== 支付配置面板（V176 自系统设置页迁入）====================
// 背景：V176 把会员定价 / 在线充值 / 支付参数面板从「系统设置」页迁到本页 pay Tab，
// 但加载与保存逻辑没有跟着迁——旧 saveSettings 读取本页不存在的系统设置元素直接
// TypeError，导致两侧保存按钮全部失效。这里补上独立的回填与提交。
// GET /admin/settings 对未提交字段不做覆盖（按提交键遍历白名单），部分提交安全。

const PAY_TEXT_KEYS = [
    'vip_price_1', 'vip_price_2', 'vip_price_3', 'point_rate',
    'circle_subscribe_days', 'circle_expire_remind_days',
    'wxpay_appid', 'wxpay_mchid', 'wxpay_serial_no', 'wxpay_api_v3_key', 'wxpay_private_key',
    'alipay_appid', 'alipay_private_key', 'alipay_public_key',
];
const PAY_BOOL_KEYS = ['pay_enabled', 'pay_wxpay_enabled', 'pay_alipay_enabled', 'circle_sub_auto_cleanup'];

export async function loadPayConfig() {
    try {
        const data = await request('/admin/settings');
        PAY_TEXT_KEYS.forEach(k => {
            const el = document.getElementById('setting-' + k);
            const v = data[k] || '';
            if (el) el.value = String(v).startsWith('******HUBLOG_SENSITIVE_MASKED:') ? '' : v;
        });
        PAY_BOOL_KEYS.forEach(k => {
            const el = document.getElementById('setting-' + k);
            if (el) el.checked = (data[k] == '1');
        });
    } catch(e) { showToast(e.message); }
}

export async function savePayConfig() {
    try {
        const body = {};
        PAY_TEXT_KEYS.forEach(k => {
            const el = document.getElementById('setting-' + k);
            if (!el) return;
            const v = String(el.value).trim();
            if (v.startsWith('******HUBLOG_SENSITIVE_MASKED:')) return;
            body[k] = v;
        });
        PAY_BOOL_KEYS.forEach(k => {
            const el = document.getElementById('setting-' + k);
            if (el) body[k] = el.checked ? '1' : '0';
        });
        await request('/admin/settings', { method: 'PUT', body });
        showToast('保存成功');
    } catch(e) { showToast(e.message); }
}

export async function loadFinance(resetPage) {
    // V176：pay/ops 是纯表单面板，不拉数据（showPage 进页时 loadFinance(true) 对它们直接跳过）
    if (financeCurrentTab === 'pay' || financeCurrentTab === 'ops') return;
    if (financeCurrentTab === 'wallet') {
        if (resetPage) walletLogsCurrentPage = 1;
        await loadWalletLogs();
        return;
    }
    if (financeCurrentTab === 'points') {
        if (resetPage) pointsLogsCurrentPage = 1;
        await loadPointTasks();
        await loadPointsLogs();
        return;
    }
    if (resetPage) vipOrdersCurrentPage = 1;
    await loadVipOrders();
}

export async function loadVipOrders() {
    try {
        const data = await request('/admin/vip-orders?page=' + vipOrdersCurrentPage + '&limit=20');
        const list = data.list || [];
        const lvText = { 1: '月度', 2: '季度', 3: '年度' };
        document.getElementById('vip-orders-table').innerHTML = list.map(o => `
            <tr>
                <td data-label="ID">${o.id}</td>
                <td data-label="用户">${escapeHtml(o.nickname || o.username || ('ID:' + o.user_id))}</td>
                <td data-label="等级">Lv${o.level} ${lvText[o.level] || ''}</td>
                <td data-label="天数">${o.days}</td>
                <td data-label="金额">¥${o.amount}</td>
                <td data-label="订单号">${escapeHtml(o.order_no || '')}</td>
                <td data-label="生效">${o.started_at || '-'}</td>
                <td data-label="到期">${o.expire_at || '-'}</td>
                <td data-label="下单时间">${o.created_at || ''}</td>
            </tr>`).join('') || '<tr><td colspan="9" style="text-align:center;color:var(--text-muted);">暂无会员订单</td></tr>';
        renderFinancePagination(data.pagination, 'vip');
    } catch(e) { showToast(e.message); }
}

export async function loadWalletLogs() {
    const type = document.getElementById('wallet-log-type')?.value || '';
    const keyword = (document.getElementById('wallet-keyword')?.value || '').trim();
    const params = new URLSearchParams({ page: walletLogsCurrentPage, limit: 20 });
    if (type) params.set('type', type);
    if (keyword) params.set('keyword', keyword);
    try {
        const data = await request('/admin/wallet-logs?' + params.toString());
        const list = data.list || [];
        const typeText = { recharge: '充值', consume: '消费', adjust: '后台调整', refund: '退款', income: '收入' };
        document.getElementById('wallet-logs-table').innerHTML = list.map(w => `
            <tr>
                <td data-label="ID">${w.id}</td>
                <td data-label="用户">${escapeHtml(w.nickname || w.username || ('ID:' + w.user_id))}</td>
                <td data-label="变动" style="color:${w.amount >= 0 ? '#2e9e5b' : '#d93025'};font-weight:600;">${w.amount >= 0 ? '+' : ''}${w.amount}</td>
                <td data-label="类型">${typeText[w.type] || w.type}</td>
                <td data-label="业务">${w.biz_type}${w.biz_id ? '#' + w.biz_id : ''}</td>
                <td data-label="变动后余额">${w.balance_after}</td>
                <td data-label="备注" data-full>${escapeHtml(w.remark || '-')}</td>
                <td data-label="时间">${w.created_at || ''}</td>
            </tr>`).join('') || '<tr><td colspan="8" style="text-align:center;color:var(--text-muted);">暂无流水</td></tr>';
        renderFinancePagination(data.pagination, 'wallet');
    } catch(e) { showToast(e.message); }
}

export function renderFinancePagination(pager, kind) {
    const box = document.getElementById(
        kind === 'vip' ? 'finance-pagination' : (kind === 'wallet' ? 'wallet-logs-pagination' : 'points-logs-pagination')
    );
    if (!box || !pager) { return; }
    box.innerHTML = `第 ${pager.total} 条 · 第 ${pager.page}/${pager.pages} 页
        <span>
            <button class="btn btn-default" ${pager.page <= 1 ? 'disabled' : ''} data-action="finance-page" data-kind="${kind}" data-page="${pager.page - 1}">上一页</button>
            <button class="btn btn-default" ${pager.page >= pager.pages ? 'disabled' : ''} data-action="finance-page" data-kind="${kind}" data-page="${pager.page + 1}">下一页</button>
        </span>`;
}

export function financeGoPage(kind, page) {
    if (page < 1) return;
    if (kind === 'vip') vipOrdersCurrentPage = page;
    else if (kind === 'wallet') walletLogsCurrentPage = page;
    else pointsLogsCurrentPage = page;
    loadFinance(false);
}

// B4a 委托化：财务分页（VIP 订单 / 钱包流水 / 积分流水共用）
registerActions({
    'finance-page': (d) => financeGoPage(d.kind, +d.page),
    // B4b 委托化：静态刷新 / 搜索（data-refresh="1" 时重置页码）
    'load-finance': (d) => loadFinance(d.refresh === '1'),
    // B6 委托化：钱包/积分搜索回车（原 onkeydown 直调，等价 loadFinance(true)）
    'enter-finance': (d, el, e) => { if (e.key === 'Enter') loadFinance(true); },
    // 支付配置面板（pay Tab）：保存 + 手动刷新回填
    'save-pay-settings': () => savePayConfig(),
    'load-pay-config': () => loadPayConfig(),
});