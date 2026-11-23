// ============================================================
// realname.js — 实名认证
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';



// ============ V51 实名认证 ============
export let rnCurrentPage = 1;
export let rnCurrentLimit = 20;

export async function loadRealname(page) {
    if (page) rnCurrentPage = page;
    else rnCurrentPage = 1;
    const status = document.getElementById('rn-status')?.value || '';
    const keyword = (document.getElementById('rn-keyword')?.value || '').trim();
    await loadRealnameSettings();
    try {
        const params = new URLSearchParams({ page: rnCurrentPage, limit: rnCurrentLimit });
        if (status) params.set('status', status);
        if (keyword) params.set('keyword', keyword);
        const data = await request('/admin/realname?' + params.toString());
        const list = data.list || [];
        const stText = { pending: '待复核', verified: '已通过', rejected: '未通过', revoked: '已撤销' };
        const stBadge = { pending: 'badge-warning', verified: 'badge-success', rejected: 'badge-danger', revoked: 'badge-default' };
        document.getElementById('rn-table').innerHTML = list.map(r => {
            const st = r.status || 'pending';
            const verified = st === 'verified';
            return `
            <tr>
                <td data-label="ID">${r.id}</td>
                <td data-label="用户">${escapeHtml(r.nickname || r.username || ('ID:' + r.user_id))}<div style="font-size:12px;color:var(--text-muted);">@${escapeHtml(r.username || '')}</div></td>
                <td data-label="姓名">${escapeHtml(r.real_name_masked || '-')}</td>
                <td data-label="身份证">${escapeHtml(r.id_card_masked || '-')}</td>
                <td data-label="服务商">${escapeHtml(r.provider || '-')}</td>
                <td data-label="状态"><span class="badge ${stBadge[st] || 'badge-default'}">${stText[st] || st}</span></td>
                <td data-label="说明" data-full>${escapeHtml(r.fail_reason || (r.provider_code ? '返回码 ' + r.provider_code : '-'))}</td>
                <td data-label="提交时间">${escapeHtml(r.created_at || '-')}</td>
                <td data-label="操作" data-actions>
                    ${verified ? '' : '<button class="btn btn-primary" data-action="review-realname" data-id="' + r.id + '" data-value="approve">通过</button>'}
                    ${(st === 'pending' || verified) ? '<button class="btn btn-default" data-action="review-realname" data-id="' + r.id + '" data-value="reject">驳回</button>' : ''}
                    ${verified ? '<button class="btn btn-danger" data-action="review-realname" data-id="' + r.id + '" data-value="revoke">撤销</button>' : ''}
                </td>
            </tr>`;
        }).join('') || '<tr><td colspan="9" style="text-align:center;padding:40px;">暂无认证记录</td></tr>';
        renderRnPagination(data);
    } catch(e) { showToast(e.message); }
}

export function renderRnPagination(data) {
    const total = data.total || 0;
    const page = data.page || 1;
    const limit = data.limit || rnCurrentLimit;
    const totalPages = Math.max(1, Math.ceil(total / limit));
    const el = document.getElementById('rn-pagination');
    if (!el) return;
    el.innerHTML = `
        <span style="font-size:13px;color:var(--text-secondary);">共 ${total} 条记录 · 第 ${page}/${totalPages} 页</span>
        <div style="display:flex;gap:8px;">
            <button class="btn btn-default" ${page<=1?'disabled':''} data-action="realname-page" data-delta="-1">上一页</button>
            <button class="btn btn-default" ${page>=totalPages?'disabled':''} data-action="realname-page" data-delta="1">下一页</button>
        </div>`;
}

export async function reviewRealname(id, action) {
    let reason = '';
    if (action === 'reject') {
        reason = prompt('请输入驳回原因（用户可见）', '姓名与身份证号不一致') || '';
    } else if (action === 'revoke') {
        if (!confirm('确定撤销该用户的实名认证？')) return;
    } else if (action === 'approve') {
        if (!confirm('确认通过该用户的实名认证？请核对信息真实性。')) return;
    }
    try {
        await request('/admin/realname/review', { method: 'POST', body: { id, action, reason } });
        showToast('操作成功');
        loadRealname(rnCurrentPage);
    } catch(e) { showToast(e.message); }
}

export function switchRealnameProvider(provider) {
    const map = { aliyun_market: 'rn-creds-aliyun', tencent_cloud: 'rn-creds-tencent', baidu_cloud: 'rn-creds-baidu' };
    Object.values(map).forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });
    const active = map[provider];
    if (active) { const el = document.getElementById(active); if (el) el.style.display = 'block'; }
}

export function switchSmsProvider(provider) {
    const map = { aliyun: 'sms-creds-aliyun', tencent: 'sms-creds-tencent', baidu: 'sms-creds-baidu' };
    Object.values(map).forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });
    const active = map[provider];
    if (active) { const el = document.getElementById(active); if (el) el.style.display = 'block'; }
}

export async function loadRealnameSettings() {
    try {
        const s = await request('/admin/settings');
        const enabled = document.getElementById('rn-enabled');
        const provider = document.getElementById('rn-provider');
        if (enabled) enabled.value = (s.realname_enabled === '0' || s.realname_enabled === 0) ? '0' : '1';
        const pv = s.realname_provider || 'aliyun_market';
        if (provider) provider.value = pv;
        switchRealnameProvider(pv);
        const fill = (id, val) => {
            const el = document.getElementById(id);
            // 密钥是掩码占位串时不要回填到输入框，避免保存时被当成真实值写回
            if (el && val && !String(val).startsWith('******')) el.value = val;
        };
        // 阿里云
        fill('rn-appcode', s.realname_appcode);
        fill('rn-apiurl', s.realname_api_url);
        // 腾讯云
        fill('rn-tc-id', s.realname_tencent_secret_id);
        fill('rn-tc-key', s.realname_tencent_secret_key);
        fill('rn-tc-host', s.realname_tencent_endpoint);
        fill('rn-tc-action', s.realname_tencent_action);
        fill('rn-tc-version', s.realname_tencent_version);
        fill('rn-tc-region', s.realname_tencent_region);
        // 百度云
        fill('rn-bd-ak', s.realname_baidu_ak);
        fill('rn-bd-sk', s.realname_baidu_sk);
        fill('rn-bd-host', s.realname_baidu_endpoint);
        fill('rn-bd-path', s.realname_baidu_path);
    } catch(e) { /* 配置区加载失败不阻塞列表 */ }
}

export async function saveRealnameSettings() {
    const provider = document.getElementById('rn-provider')?.value || 'aliyun_market';
    const body = {
        realname_enabled: document.getElementById('rn-enabled')?.value || '1',
        realname_provider: provider
    };
    const trim = (id) => (document.getElementById(id)?.value || '').trim();
    // 阿里云
    const appcode = trim('rn-appcode'), apiurl = trim('rn-apiurl');
    if (appcode) body.realname_appcode = appcode;
    if (apiurl) body.realname_api_url = apiurl;
    // 腾讯云
    const tcId = trim('rn-tc-id'), tcKey = trim('rn-tc-key'), tcHost = trim('rn-tc-host'),
          tcAction = trim('rn-tc-action'), tcVer = trim('rn-tc-version'), tcRegion = trim('rn-tc-region');
    if (tcId) body.realname_tencent_secret_id = tcId;
    if (tcKey) body.realname_tencent_secret_key = tcKey;
    if (tcHost) body.realname_tencent_endpoint = tcHost;
    if (tcAction) body.realname_tencent_action = tcAction;
    if (tcVer) body.realname_tencent_version = tcVer;
    if (tcRegion) body.realname_tencent_region = tcRegion;
    // 百度云
    const bdAk = trim('rn-bd-ak'), bdSk = trim('rn-bd-sk'), bdHost = trim('rn-bd-host'), bdPath = trim('rn-bd-path');
    if (bdAk) body.realname_baidu_ak = bdAk;
    if (bdSk) body.realname_baidu_sk = bdSk;
    if (bdHost) body.realname_baidu_endpoint = bdHost;
    if (bdPath) body.realname_baidu_path = bdPath;
    try {
        await request('/admin/settings', { method: 'PUT', body });
        showToast('配置已保存');
        await loadRealname();
    } catch(e) { showToast(e.message); }
}

// B4a 委托化：实名审核 / 翻页
registerActions({
    'review-realname': (d) => reviewRealname(+d.id, d.value),
    'realname-page': (d) => loadRealname(rnCurrentPage + Number(d.delta)),
    // B4b 委托化：静态保存配置 / 搜索
    'save-realname-settings': () => saveRealnameSettings(),
    'load-realname': (d) => loadRealname(+(d.page || 1)),
    // B6 委托化：服务商切换 / 搜索回车（原 onchange/onkeydown 直调）
    'switch-realname-provider': (d, el) => switchRealnameProvider(el.value),
    'switch-sms-provider': (d, el) => switchSmsProvider(el.value),
    'enter-realname': (d, el, e) => { if (e.key === 'Enter') loadRealname(); },
});