// ============================================================
// stats.js — 数据统计（含趋势图）
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';

// B4b 委托化：静态统计查询
registerActions({
    'load-stats': () => loadStats(),
});



// ============ 数据统计 ============
export async function loadStats() {
    try {
        const startDate = document.getElementById('stats-start-date')?.value || '';
        const endDate = document.getElementById('stats-end-date')?.value || '';
        const params = new URLSearchParams();
        if (startDate) params.set('start_date', startDate);
        if (endDate) params.set('end_date', endDate);
        const qs = params.toString() ? '?' + params.toString() : '';
        const data = await request('/admin/stats' + qs);

        // 概览卡片
        const summary = data.summary || {};
        document.getElementById('stats-summary-grid').innerHTML = `
            <div class="stat-card"><div class="stat-value">${summary.total_users || 0}</div><div class="stat-label">总用户</div></div>
            <div class="stat-card"><div class="stat-value">${summary.total_posts || 0}</div><div class="stat-label">总动态</div></div>
            <div class="stat-card"><div class="stat-value">${summary.total_comments || 0}</div><div class="stat-label">总评论</div></div>
            <div class="stat-card"><div class="stat-value">${summary.total_views || 0}</div><div class="stat-label">总浏览量</div></div>
            <div class="stat-card"><div class="stat-value">${summary.active_users_30d || 0}</div><div class="stat-label">30天活跃用户</div></div>
            <div class="stat-card"><div class="stat-value">${summary.new_users_30d || 0}</div><div class="stat-label">30天新增用户</div></div>
        `;

        // 趋势图表
        renderTrendChart(data.trend || []);

        // 分类分布
        const categories = data.categories || [];
        const totalCat = categories.reduce((s, c) => s + (c.count || 0), 0) || 1;
        document.getElementById('stats-category-table').innerHTML = categories.map(c => `
            <tr><td data-label="分类名称">${escapeHtml(c.name)}</td><td data-label="动态数">${c.count || 0}</td><td data-label="占比">${((c.count || 0) / totalCat * 100).toFixed(1)}%</td></tr>
        `).join('') || '<tr><td colspan="3" style="text-align:center;padding:40px;">暂无数据</td></tr>';

        // 热门话题
        const topics = data.top_topics || [];
        document.getElementById('stats-topics-table').innerHTML = topics.map((t, i) => `
            <tr><td data-label="排名">${i + 1}</td><td data-label="话题">${escapeHtml(t.name)}</td><td data-label="动态数">${t.post_count || 0}</td></tr>
        `).join('') || '<tr><td colspan="3" style="text-align:center;padding:40px;">暂无数据</td></tr>';
    } catch(e) { showToast(e.message); }
}

export function renderTrendChart(trend) {
    const svg = document.getElementById('stats-trend-chart');
    if (!trend || trend.length === 0) {
        svg.innerHTML = '<text x="340" y="150" text-anchor="middle" fill="#999" font-size="14">暂无趋势数据</text>';
        return;
    }
    const W = 680, H = 300, padL = 50, padR = 20, padT = 20, padB = 40;
    const chartW = W - padL - padR, chartH = H - padT - padB;
    const maxVal = Math.max(...trend.map(d => d.count || 0), 1);
    const stepX = chartW / Math.max(trend.length - 1, 1);

    let pathD = '';
    let bars = '';
    trend.forEach((d, i) => {
        const x = padL + i * stepX;
        const y = padT + chartH - ((d.count || 0) / maxVal) * chartH;
        if (i === 0) pathD += `M ${x} ${y}`;
        else pathD += ` L ${x} ${y}`;
    });

    let gridLines = '';
    for (let i = 0; i <= 4; i++) {
        const y = padT + (chartH / 4) * i;
        const val = Math.round(maxVal - (maxVal / 4) * i);
        gridLines += `<line x1="${padL}" y1="${y}" x2="${W - padR}" y2="${y}" stroke="#eee" stroke-width="1"/>`;
        gridLines += `<text x="${padL - 8}" y="${y + 4}" text-anchor="end" fill="#999" font-size="10">${val}</text>`;
    }

    let xLabels = '';
    const labelStep = Math.ceil(trend.length / 10);
    trend.forEach((d, i) => {
        if (i % labelStep === 0) {
            const x = padL + i * stepX;
            xLabels += `<text x="${x}" y="${H - padB + 18}" text-anchor="middle" fill="#999" font-size="10">${(d.date || '').substring(5)}</text>`;
        }
    });

    const areaD = pathD + ` L ${padL + (trend.length - 1) * stepX} ${padT + chartH} L ${padL} ${padT + chartH} Z`;

    svg.innerHTML = gridLines + xLabels +
        `<path d="${areaD}" fill="rgba(7,193,96,0.1)"/>` +
        `<path d="${pathD}" fill="none" stroke="var(--primary)" stroke-width="2"/>` +
        trend.map((d, i) => {
            const x = padL + i * stepX;
            const y = padT + chartH - ((d.count || 0) / maxVal) * chartH;
            return `<circle cx="${x}" cy="${y}" r="3" fill="var(--primary)"><title>${d.date}: ${d.count}</title></circle>`;
        }).join('');
}