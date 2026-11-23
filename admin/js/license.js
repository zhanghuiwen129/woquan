// ============================================================
// license.js — 授权管理
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';



// ============ 授权管理 ============
export function licenseSwitchTab(tab) {
    // 切换 Tab：隐藏所有面板 → 显示目标面板 → 更新按钮 active 状态
    document.querySelectorAll('#page-license [id^="license-tab-"]').forEach(el => el.classList.add('hidden'));
    document.getElementById('license-tab-' + tab).classList.remove('hidden');
    document.querySelectorAll('#page-license .setting-tab').forEach(el => el.classList.remove('active'));
    const activeBtn = document.querySelector('#page-license .setting-tab[data-tab="license-' + tab + '"]');
    if (activeBtn) activeBtn.classList.add('active');
}

// B2 委托化：授权 Tab 切换（data-action="license-switch-tab"，data-tab 值带 license- 前缀需剥离）
registerActions({
    'license-switch-tab': (d) => licenseSwitchTab(String(d.tab).replace(/^license-/, '')),
    // B4b 委托化：静态激活 / 刷新 / 重算哈希 / 停用 / 远程测试 / 同步吊销清单 / 保存验签配置
    'license-activate': () => licenseActivate(),
    'load-license-status': () => loadLicenseStatus(),
    'license-refresh-hashes': () => licenseRefreshHashes(),
    'license-deactivate': () => licenseDeactivate(),
    'license-test-remote': () => licenseTestRemote(),
    'do-license-sync-revoked': () => doLicenseSyncRevoked(),
    'license-save-settings': () => licenseSaveSettings(),
    // B6 委托化：验签模式切换（原 onchange 直调）
    'toggle-license-verify-mode': () => toggleLicenseVerifyMode(),
});

// P1-2 懒加载：upgrade-form 元素在 page-upgrade 片段内（不在本页），顶层绑定会因
// 片段未挂载而静默跳过（且行为依赖访问顺序）。改为导出函数，由 upgrade 页入口调用。
export function bindUpgradeFormGuard() {
    document.getElementById('upgrade-form')?.addEventListener('submit', (e) => e.preventDefault());
}
export async function loadLicensePage() {
    await loadLicenseStatus();
    await loadLicenseSettings();
}

export function renderLicenseMatrix(data) {
    const tbody = document.getElementById('license-matrix-table');
    const box0 = document.getElementById('license-usage-box');
    // 授权系统已关闭时，所有限制都不生效，直接说明，避免对照表误导
    if (data.status && data.status.enabled === false) {
        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-muted);">授权系统已关闭，当前站点无任何功能限制</td></tr>';
        box0.innerHTML = '';
        return;
    }
    const list = data.feature_matrix || [];
    if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;color:var(--text-muted);">暂无数据</td></tr>';
        return;
    }
    // 说明：额度与开放范围均由授权策略决定，后台不可自行调整，
    // 因此对照表只保留「功能 / 已授权 / 未授权（试用）」三列，不做任何可改列的展示。
    tbody.innerHTML = list.map(f => {
        const trialTxt = f.trial || '';
        const closed = /^不可用|^不开放/.test(trialTxt);
        const color = closed ? 'var(--danger)' : 'var(--warn)';
        return `<tr>
            <td data-label="功能">${escapeHtml(f.name)}</td>
            <td data-label="已授权" style="color:var(--primary);">${f.authorized}</td>
            <td data-label="未授权（试用）" style="color:${color};">${trialTxt}${f.daily ? ' <span style="color:var(--text-muted);">（每日重置）</span>' : ''}</td>
        </tr>`;
    }).join('');

    const u = data.usage || {};
    const box = document.getElementById('license-usage-box');
    box.innerHTML = '当前站点用量：用户 <b>' + (u.users || 0) + '</b> 个 · 动态 <b>' + (u.posts || 0) +
        '</b> 条（今日新增 ' + (u.today_posts || 0) + '）· 私信 <b>' + (u.today_messages || 0) +
        '</b> 条（今日）· 会话 <b>' + (u.conversations || 0) + '</b> 个' +
        (u.today_ai !== undefined ? ' · AI 辅助 <b>' + (u.today_ai || 0) + '</b> 次（今日）' : '');
}

export function renderLicenseStatus(status) {
    const box = document.getElementById('license-status-content');
    if (!status.enabled) {
        box.innerHTML = `
            <div style="text-align:center;padding:20px;">
                <div style="margin-bottom:12px;color:var(--warn);"><i data-icon="alert" data-ico-class="ico-xl"></i></div>
                <div style="font-size:18px;font-weight:600;color:var(--warn);margin-bottom:8px;">授权系统未启用</div>
                <div style="color:var(--text-secondary);font-size:14px;line-height:1.7;">当前站点不受任何授权限制，所有功能无限制使用。<br>是否受限只由授权码决定：在下方填入授权码并点「立即激活」，即可按授权范围生效。</div>
            </div>
        `;
        document.getElementById('btn-license-deactivate').style.display = 'none';
        return;
    }

    // 授权类型显示
    const typeLabels = {
        disabled: '已关闭',
        unauthorized: '未授权',
        trial: '试用版',
        personal: '个人版',
        business: '商业版',
        enterprise: '企业版',
        expired: '已过期',
        revoked: '授权已吊销',
        inactive: '授权未激活',
        domain_mismatch: '域名不匹配',
        integrity_failed: '文件篡改检测'
    };
    const typeColors = {
        disabled: '#8c8c8c',
        unauthorized: 'var(--danger)',
        trial: 'var(--warn)',
        personal: 'var(--info)',
        business: '#722ed1',
        enterprise: 'var(--primary)',
        expired: 'var(--danger)',
        revoked: '#a8071a',
        inactive: '#873800',
        domain_mismatch: 'var(--danger)',
        integrity_failed: 'var(--danger)'
    };
    const typeLabel = typeLabels[status.type] || status.type;
    const typeColor = typeColors[status.type] || 'var(--text)';
    const authorizedBadge = status.authorized
        ? '<span class="badge badge-success" style="padding:4px 12px;font-size:13px;">已授权</span>'
        : '<span class="badge badge-danger" style="padding:4px 12px;font-size:13px;">未授权</span>';

    // 状态细节：改为「一行式」展示——每条细节本身只占一行（标签 + 内容同行），
    // 多条细节在同一行里平铺并用竖线分隔，避免之前每条「标题一行 + 内容一行」把状态卡撑成高高两行。
    const detailItems = [];
    const detailRow = (label, html) => detailItems.push(
        `<div style="white-space:nowrap;font-size:14px;"><span style="color:var(--text-muted);font-size:13px;margin-right:6px;">${label}</span>${html}</div>`
    );

    // 客户信息
    if (status.customer_name || status.customer_email) {
        const parts = [];
        if (status.customer_name) parts.push('名称：' + escapeHtml(status.customer_name));
        if (status.customer_email) parts.push('邮箱：' + escapeHtml(status.customer_email));
        detailRow('客户信息', parts.join(' &nbsp;|&nbsp; '));
    }

    // 过期时间
    if (status.expires_at) {
        const expireColor = status.expired ? 'var(--danger)' : 'var(--success)';
        detailRow('有效期', `<span style="color:${expireColor};">${status.expired ? '已过期：' : '到期时间：'}${status.expires_at}</span>`);
    } else if (status.authorized) {
        detailRow('有效期', '<span style="color:var(--success);">永久授权</span>');
    }

    // 域名绑定
    if (status.domain) {
        const domainStatus = status.domain_ok
            ? '<span style="color:var(--success);">域名匹配</span>'
            : '<span style="color:var(--danger);">域名不匹配（当前：' + escapeHtml(status.current_domain || '') + '）</span>';
        detailRow('域名绑定', '授权域名 <code style="background:var(--surface-2);padding:2px 6px;border-radius:3px;">' + escapeHtml(status.domain) + '</code> ' + domainStatus);
    }

    // 资源限制
    if (status.authorized && (status.max_users !== null || status.max_posts !== null)) {
        const lim = [];
        lim.push(status.max_users !== null
            ? '最大用户数：<strong>' + status.max_users + '</strong>'
            : '最大用户数：<span style="color:var(--success);">不限</span>');
        lim.push(status.max_posts !== null
            ? '最大动态数：<strong>' + status.max_posts + '</strong>'
            : '最大动态数：<span style="color:var(--success);">不限</span>');
        detailRow('资源限制', lim.join(' &nbsp;|&nbsp; '));
    }

    // 文件完整性
    if (!status.integrity_ok) {
        // 后端 integrity_issues 每项形如 {file:'xxx', issue:'文件哈希不匹配（可能被篡改）'}
        // 直接 escapeHtml(对象) 会渲染成 [object Object]，这里统一取字段
        const issuesList = (status.integrity_issues || []).map(f => {
            const txt = (f && typeof f === 'object') ? (f.file + '：' + (f.issue || '文件校验异常')) : String(f || '');
            return `<code style="background:var(--surface-2);padding:2px 6px;border-radius:3px;margin:2px;display:inline-block;">${escapeHtml(txt)}</code>`;
        }).join(' ');
        detailRow('文件完整性校验失败', `<span style="color:var(--danger);">${issuesList}</span>`);
    }

    // 功能列表
    if (status.authorized && status.features && status.features.length > 0) {
        const featureLabels = {
            '*': '全部模块',
            ai: 'AI 智能辅助',
            scheduled: '定时发布',
            dataops: '数据运维（导出·统计·插件·升级）',
            circles: '圈子',
            shop: '商城',
            points: '积分',
            finance: '财务会员',
            activities: '活动',
            realname: '实名认证',
            badges: '徽章',
            moderation: '内容治理'
        };
        const featuresText = status.features.map(f => featureLabels[f] || f).join('、');
        detailRow('已授权功能', `<span style="color:var(--success);">${featuresText}</span>`);
    }

    // 不再单独输出「未授权版本限制」：额度上限与当前用量都已在下方「授权 / 未授权 功能对照」及用量行完整展示，
    // 状态卡重复列一遍只会让同一组数字出现两次，这里只保留授权状态本身。

    box.innerHTML = `
        <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:6px 12px;margin-bottom:10px;">
            <div style="display:flex;align-items:baseline;flex-wrap:wrap;gap:8px;">
                <span style="font-size:20px;font-weight:600;color:${typeColor};">${typeLabel}</span>
                ${authorizedBadge}
            </div>
            <div style="font-size:12px;color:var(--text-muted);">
                ${status.license_key ? '授权码：<code style="background:var(--surface-2);padding:2px 6px;border-radius:3px;">' + escapeHtml(status.license_key.substring(0, 16) + '...') + '</code>' : '尚未激活授权码'}
            </div>
        </div>
        ${detailItems.length
            ? '<div class="license-detail">' + detailItems.map(t => '<div>' + t + '</div>').join('<span class="license-detail-sep">|</span>') + '</div>'
            : ''}
    `;

    document.getElementById('btn-license-deactivate').style.display = status.license_key ? '' : 'none';
}

export async function loadLicenseStatus() {
    try {
        const data = await request('/admin/license');
        // 合并后端返回的各部分数据，供渲染使用
        const status = Object.assign({}, data.status || {});
        status.current_domain = data.current_domain || '';
        // 未授权限制信息：来自 resource_limit
        if (data.resource_limit) {
            if (!status.authorized) {
                // 额度上限与当前用量不在这里展示（下方对照表 + 用量行已覆盖），只取字段备用
            }
            status.current_users = data.resource_limit.users;
            status.current_posts = data.resource_limit.posts;
        }
        renderLicenseStatus(status);
        renderLicenseMatrix(data);
    } catch(e) { showToast(e.message); }
}

export async function loadLicenseSettings() {
    try {
        const data = await request('/admin/license');
        const s = data.settings || {};
        // 说明：授权「总开关 / 完整性校验开关 / 绑定域名 / 未授权横幅」已从后台移除——
        // 买授权 = 全功能可用，没买 = 限制版，能不能用只由授权码决定，站点不该有开关能改它。
        // 这里只回填「验签配置」（公钥、远程验签地址），那部分是技术项，见进阶 tab。
        // 公钥后端原样返回完整 PEM，可直接回填，保存不会破坏已存储的值。
        const verifyMode = s.license_verify_mode || 'local';
        document.getElementById('license-setting-verify_mode').value = verifyMode;
        document.getElementById('license-setting-public_key').value = s.license_public_key || '';
        document.getElementById('license-setting-remote_url').value = s.license_remote_url || '';
        document.getElementById('license-setting-remote_pubkey').value = s.license_remote_pubkey || '';
        document.getElementById('license-setting-remote_timeout').value = s.license_remote_timeout ?? '8';
        document.getElementById('license-setting-remote_ssl_verify').checked = s.license_remote_ssl_verify !== '0';
        toggleLicenseVerifyMode(verifyMode);

        // 展示最近一次远程错误（如果有）
        if (data.status && typeof data.status.remote_last_error === 'string' && data.status.remote_last_error !== '') {
            document.getElementById('license-remote-last-error').classList.remove('hidden');
            document.getElementById('license-remote-last-error-body').textContent = data.status.remote_last_error;
        } else {
            document.getElementById('license-remote-last-error').classList.add('hidden');
        }
    } catch(e) { showToast(e.message); }
}

export async function doLicenseSyncRevoked() {
    const box = document.getElementById('license-revoked-sync-result');
    // 元素缺失时不再中断（旧版在 try 之前裸访问 .classList，按钮点了毫无反应）
    if (!box) { showToast('页面片段缺少结果显示区，请刷新后台重试'); return; }
    const hint = document.getElementById('license-revoked-hint');
    box.classList.remove('hidden');
    box.textContent = '正在同步…';
    if (hint) hint.textContent = '';
    try {
        const r = await request('/admin/license/sync-revoked', {method:'POST'});
        const lines = [];
        lines.push('同步完成，清单共 ' + (r.count || 0) + ' 条');
        if (r.synced_at) lines.push('同步时间：' + new Date(r.synced_at * 1000).toLocaleString());
        lines.push('当前授权：' + (r.authorized ? '有效（' + (r.type || '') + '）' : '无效（' + (r.type || '') + (r.revoked_reason ? '，原因：' + r.revoked_reason : '') + '）'));
        box.textContent = lines.join('\n');
        if (hint) hint.textContent = '已同步 ' + (r.count || 0) + ' 条';
    } catch(e) {
        box.textContent = '同步失败：' + e.message;
        if (hint) hint.textContent = '';
    }
}

export function toggleLicenseVerifyMode(mode) {
    mode = mode || document.getElementById('license-setting-verify_mode').value;
    const remoteBox = document.getElementById('license-remote-settings');
    if (mode === 'remote') {
        remoteBox.classList.remove('hidden');
    } else {
        remoteBox.classList.add('hidden');
    }
}

export async function licenseActivate() {
    // 去除所有空白字符（含换行、Tab、零宽空格），不止 trim 首尾。
    // 授权码是纯 base64 + 一个 '.'，里面不应有任何空白；
    // 用户从 IM/邮件/IDE 复制时常被自动换行，trim 保留中间空白会让后端 base64_decode 严格模式失败。
    const raw = document.getElementById('license-key-input').value;
    const key = raw.replace(/\s+/g, '');
    if (!key) { showToast('请输入授权码'); return; }
    try {
        const status = await request('/admin/license/activate', {method:'POST', body:{license_key: key}});
        showToast('激活操作已完成');
        renderLicenseStatus(status);
        document.getElementById('license-key-input').value = '';
    } catch(e) { showToast(e.message); }
}

export async function licenseDeactivate() {
    if (!confirm('确定要取消当前授权吗？取消后站点将按未授权策略运行。')) return;
    try {
        await request('/admin/license/deactivate', {method:'POST'});
        showToast('授权已取消');
        await loadLicensePage();
    } catch(e) { showToast(e.message); }
}

export async function licenseRefreshHashes() {
    try {
        const data = await request('/admin/license/refresh-hashes', {method:'POST'});
        const count = (data && data.count) ? data.count : 0;
        showToast(`已重新计算 ${count} 个核心文件的哈希值`);
        await loadLicenseStatus();
    } catch(e) { showToast(e.message); }
}

export async function licenseSaveSettings() {
    try {
        // 只提交「验签配置」类字段。
        // 授权总开关 / 完整性校验 / 绑定域名 / 未授权横幅 / 功能白名单 / 试用额度 一律不再提交：
        // 这些属于授权策略与防盗设置，界面已移除，后端也不再接收写入。
        const body = {
            license_verify_mode: document.getElementById('license-setting-verify_mode').value,
            license_remote_url: document.getElementById('license-setting-remote_url').value.trim(),
            license_remote_pubkey: document.getElementById('license-setting-remote_pubkey').value.trim(),
            license_remote_timeout: parseInt(document.getElementById('license-setting-remote_timeout').value) || 8,
            license_remote_ssl_verify: document.getElementById('license-setting-remote_ssl_verify').checked ? '1' : '0',
            license_public_key: document.getElementById('license-setting-public_key').value.trim()
        };
        const r = await request('/admin/license/save-settings', {method:'POST', body: body});
        // 未授权状态下部分受保护项会被后端拒绝，这里提示出来，避免管理员以为保存成功实际没生效
        if (r && Array.isArray(r.blocked) && r.blocked.length) {
            showToast('已保存；以下项未授权不可修改：' + r.blocked.join('、'), 4000);
        } else {
            showToast('验签配置已保存');
        }
        await loadLicensePage();
    } catch(e) { showToast(e.message); }
}

export async function licenseTestRemote() {
    const box = document.getElementById('license-remote-test-result');
    box.classList.remove('hidden');
    box.textContent = '正在请求...';
    box.style.background = '#e6f7ff';
    box.style.color = '#096dd9';
    box.style.borderLeft = '3px solid #096dd9';
    box.style.border = '1px solid #91d5ff';
    try {
        const body = {
            url: document.getElementById('license-setting-remote_url').value.trim(),
            pubkey: document.getElementById('license-setting-remote_pubkey').value.trim(),
            timeout: parseInt(document.getElementById('license-setting-remote_timeout').value) || 8,
            ssl_verify: document.getElementById('license-setting-remote_ssl_verify').checked ? '1' : '0',
            test_license_key: document.getElementById('license-key-input').value.trim()
        };
        const res = await request('/admin/license/test-remote', {method:'POST', body});
        const latency = (res.latency_ms ?? 0).toFixed(0);
        if (res.ok) {
            box.style.background = '#f6ffed';
            box.style.color = '#389e0d';
            box.style.borderLeft = '3px solid #389e0d';
            box.style.border = '1px solid #b7eb8f';
            let html = `<strong>远程服务器连接成功</strong>（延迟 ${latency} ms）\n`;
            if (res.licensed) {
                html += `• 测试授权码验证：<span style="color:#389e0d;">合法授权</span>\n`;
                if (res.license_info) html += `• 授权信息：${res.license_info}`; // audit-skip: 拼接结果仅由 textContent 渲染，不解析 HTML
            } else {
                html += `• 测试授权码验证：<span style="color:var(--warn);">无效/未填写</span>（原因：${res.reason || '未提供测试授权码'}）\n`; // audit-skip: 同上，textContent 渲染
                html += `  小提示：在上方「激活授权」输入框粘贴授权码，再点测试就能验证真实有效性`;
            }
            box.textContent = html;
        } else {
            box.style.background = '#fff1f0';
            box.style.color = 'var(--danger)';
            box.style.borderLeft = '3px solid var(--danger)';
            box.style.border = '1px solid #ffa39e';
            box.textContent = `远程服务器连接失败（${latency} ms）\n原因：${res.reason || '未知错误'}\n\n诊断：${res.diagnosis || ''}`;
        }
    } catch(e) {
        box.style.background = '#fff1f0';
        box.style.color = 'var(--danger)';
        box.style.borderLeft = '3px solid var(--danger)';
        box.style.border = '1px solid #ffa39e';
        box.textContent = `接口错误：${e.message}`;
    }
}