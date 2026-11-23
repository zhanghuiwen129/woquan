// ============================================================
// crash-logs.js — 客户端异常收集
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';



// ===== 客户端异常收集（V97）=====
export let crashCurrentPage = 1;

export function resetCrashFilters() {
    document.getElementById('crash-filter-event').value = '';
    document.getElementById('crash-filter-platform').value = '';
    document.getElementById('crash-filter-date').value = '';
    document.getElementById('crash-filter-keyword').value = '';
    loadCrashLogs(1);
}

export async function loadCrashLogs(page) {
    if (page) crashCurrentPage = page;
    const event = document.getElementById('crash-filter-event').value;
    const platform = document.getElementById('crash-filter-platform').value;
    const date = document.getElementById('crash-filter-date').value;
    const keyword = document.getElementById('crash-filter-keyword').value.trim();
    const params = new URLSearchParams();
    params.set('page', crashCurrentPage);
    if (event) params.set('event', event);
    if (platform) params.set('platform', platform);
    if (date) params.set('date', date);
    if (keyword) params.set('keyword', keyword);
    try {
        const data = await request('/admin/crash-logs?' + params.toString());
        const box = document.getElementById('crash-today-box');
        const th = data.threshold || 0;
        let alertHtml = '';
        if (th > 0 && data.today > th) {
            alertHtml = ` <span class="badge badge-danger" style="margin-left:6px;">已超阈值（${th}）</span>`;
        } else if (th > 0) {
            alertHtml = ` <span class="badge badge-success" style="margin-left:6px;">阈值 ${th}</span>`;
        }
        box.innerHTML = `今日新增异常 <b>${data.today}</b> 条${alertHtml}`;

        const topBody = document.getElementById('crash-top-table');
        topBody.innerHTML = (data.top && data.top.length)
            ? data.top.map(t => `
                <tr>
                    <td data-label="类型"><span class="badge ${t.event==='api_error'?'badge-warning':'badge-danger'}">${t.event==='api_error'?'接口异常':'崩溃'}</span></td>
                    <td data-label="摘要" style="word-break:break-all;">${escapeHtml(t.message||'(空)')}</td>
                    <td data-label="次数"><b>${t.cnt}</b></td>
                    <td data-label="最近">${t.last_at||'-'}</td>
                </tr>`).join('')
            : '<tr><td colspan="4" style="text-align:center;padding:24px;">暂无聚合数据</td></tr>';

        const tbody = document.getElementById('crash-logs-table');
        const rows = data.list || [];
        tbody.innerHTML = rows.length
            ? rows.map(r => {
                const routeOrApi = r.event==='api_error'
                    ? `${escapeHtml(r.api_url||'-')}${r.api_code?(' <span class="badge badge-warning">'+escapeHtml(r.api_code)+'</span>'):''}`
                    : escapeHtml(r.route||'-');
                return `
                <tr>
                    <td data-label="时间">${r.occurred_at||'-'}</td>
                    <td data-label="类型"><span class="badge ${r.event==='api_error'?'badge-warning':'badge-danger'}">${r.event==='api_error'?'接口异常':'崩溃'}</span></td>
                    <td data-label="平台">${escapeHtml(r.platform||'-')}</td>
                    <td data-label="用户">${r.user_id?('#'+r.user_id):'游客'}</td>
                    <td data-label="路由/接口" style="word-break:break-all;max-width:240px;">${routeOrApi}</td>
                    <td data-label="摘要" style="word-break:break-all;max-width:260px;">${escapeHtml((r.message||'(空)').slice(0,80))}</td>
                    <td data-label="操作"><button class="btn btn-default" data-action="show-crash-detail" data-id="${r.id}">详情</button></td>
                </tr>`;
              }).join('')
            : '<tr><td colspan="7" style="text-align:center;padding:40px;">暂无异常记录</td></tr>';

        const pager = document.getElementById('crash-pager');
        const total = data.total || 0;
        const limit = data.limit || 50;
        const pages = Math.max(1, Math.ceil(total / limit));
        pager.innerHTML = `共 ${total} 条 · 第 ${crashCurrentPage}/${pages} 页
            <button class="btn btn-default" ${crashCurrentPage<=1?'disabled':''} data-action="crash-page" data-delta="-1">上一页</button>
            <button class="btn btn-default" ${crashCurrentPage>=pages?'disabled':''} data-action="crash-page" data-delta="1">下一页</button>`;

        window.__crashRows = rows;
    } catch(e) { showToast(e.message); }
}

export async function showCrashDetail(id) {
    const row = (window.__crashRows||[]).find(r => r.id === id);
    if (!row) { showToast('记录不存在'); return; }
    document.getElementById('crash-detail-title').textContent = (row.event==='api_error'?'接口异常':'崩溃') + ' · #' + row.id;
    const rows = [
        ['时间', row.occurred_at||'-'],
        ['类型', row.event==='api_error'?'接口异常':'崩溃'],
        ['平台', row.platform||'-'],
        ['客户端', row.client||'-'],
        ['用户', row.user_id?('#'+row.user_id):'游客'],
        ['IP', row.ip||'-'],
        ['路由', row.route||'-']
    ];
    if (row.event==='api_error') {
        rows.push(['接口', row.api_url||'-']);
        rows.push(['错误码', row.api_code||'-']);
    } else {
        rows.push(['上下文', row.context||'-']);
    }
    rows.push(['错误摘要', row.message||'-']);
    if (row.event!=='api_error' && row.stack) {
        rows.push(['堆栈', row.stack]);
    }
    const body = document.getElementById('crash-detail-body');
    body.innerHTML = rows.map(([k,v]) => {
        const isStack = k==='堆栈';
        return `<div style="margin-bottom:10px;">
            <div style="color:var(--text-3);font-size:12px;">${k}</div>
            <div style="${isStack?'white-space:pre-wrap;word-break:break-all;background:var(--bg-2,#f6f6f6);padding:10px;border-radius:6px;font-family:monospace;font-size:12px;max-height:320px;overflow:auto;':''}word-break:break-all;">${escapeHtml(v)}</div>
        </div>`;
    }).join('');
    document.getElementById('crash-detail-mask').classList.remove('hidden');
}

export function closeCrashDetail() {
    document.getElementById('crash-detail-mask').classList.add('hidden');
}

// B4a 委托化：异常详情 / 翻页
registerActions({
    'show-crash-detail': (d) => showCrashDetail(+d.id),
    'crash-page': (d) => loadCrashLogs(crashCurrentPage + Number(d.delta)),
    // B4b 委托化：静态查询 / 重置 / 关闭
    'load-crash-logs': (d) => loadCrashLogs(+(d.page || 1)),
    'reset-crash-filters': () => resetCrashFilters(),
    'close-crash-detail': () => closeCrashDetail(),
    // B5 委托化：遮罩层点击关闭（原 if(event.target===this) 守卫，仅点遮罩空白处生效）
    'close-crash-detail-mask': (d, el, e) => { if (e.target === el) closeCrashDetail(); },
});