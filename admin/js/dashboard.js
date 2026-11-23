// ============================================================
// dashboard.js — 数据概览 + 系统升级
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { BASE_URL, escapeHtml, registerActions, request, showToast } from './core.js';

// B4b 委托化：静态重新检测版本 / 立即升级 / 执行回滚
registerActions({
    'check-version': () => checkVersion(),
    'do-upgrade': () => doUpgrade(),
    'do-rollback': () => doRollback(),
});



export async function loadDashboard() {
    try {
        const data = await request('/admin/dashboard');
        const stats = [
            { label: '总用户', icon: 'users',      value: data.users.total,    today: data.users.today },
            { label: '总动态', icon: 'file-text',  value: data.posts.total,    today: data.posts.today },
            { label: '总评论', icon: 'comment',    value: data.comments.total, today: data.comments.today },
            { label: '总点赞', icon: 'heart',      value: data.likes.total,    today: data.likes.today },
        ];
        document.getElementById('stats-grid').innerHTML = stats.map(s => `
            <div class="stat-card">
                <div class="stat-card-top">
                    <span class="stat-icon"><i data-icon="${escapeHtml(s.icon)}"></i></span>
                    <span class="stat-trend">今日 +${escapeHtml(String(s.today))}</span>
                </div>
                <div class="stat-value">${escapeHtml(String(s.value))}</div>
                <div class="stat-label">${escapeHtml(s.label)}</div>
            </div>`).join('');
        if (data.version) {
            document.getElementById('version-current').textContent = data.version.current;
            document.getElementById('version-latest').textContent = data.version.latest;
            const tip = document.getElementById('version-tip');
            const link = document.getElementById('version-link');
            if (data.version.has_new) {
                tip.innerHTML = '<span class="badge badge-danger">发现新版本</span>';
                link.style.display = 'inline-block';
            } else if (data.version.fetched) {
                tip.textContent = '当前已是最新版本';
                link.style.display = 'none';
            } else {
                tip.textContent = '无法获取远程版本';
                link.style.display = 'none';
            }
        }
        if (data.server) {
            document.getElementById('server-php').textContent = data.server.php_version;
            document.getElementById('server-os').textContent = data.server.os;
            document.getElementById('server-software').textContent = data.server.server_software;
            document.getElementById('server-mysql').textContent = data.server.mysql_version;
            document.getElementById('server-upload').textContent = data.server.upload_max_filesize;
            document.getElementById('server-post').textContent = data.server.post_max_size;
            document.getElementById('server-execution').textContent = data.server.max_execution_time + 's';
            document.getElementById('server-memory').textContent = data.server.memory_limit;
            document.getElementById('server-disk').textContent = data.server.disk_free;
        }
        if (Array.isArray(data.capabilities)) {
            const grid = document.getElementById('integration-status-grid');
            grid.replaceChildren();
            for (const c of data.capabilities) {
                const item = document.createElement('div');
                const label = document.createElement('strong');
                label.textContent = c.label;
                const state = document.createElement('div');
                const dot = document.createElement('span');
                dot.style.cssText = 'display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background:'
                    + (c.ok ? 'var(--success, #2e7d32)' : 'var(--danger, #c62828)');
                state.appendChild(dot);
                state.appendChild(document.createTextNode(c.ok ? '已就绪' : '未就绪'));
                const reason = document.createElement('div');
                reason.style.cssText = 'margin-top:4px;color:var(--text-muted);font-size:12px;line-height:1.5;';
                reason.textContent = c.reason;
                item.append(label, state, reason);
                grid.appendChild(item);
            }
        }
    } catch(e) { showToast(e.message); if(e.message.includes('权限')) location.href=BASE_URL+'/'; }
}

export async function checkVersion() {
    document.getElementById('current-version').textContent = '检测中...';
    document.getElementById('latest-version').textContent = '-';
    document.getElementById('version-message').textContent = '';
    document.getElementById('download-link').style.display = 'none';
    try {
        const data = await request('/admin/version-check');
        document.getElementById('current-version').textContent = data.current;
        document.getElementById('latest-version').textContent = data.latest;
        if (data.has_new) {
            document.getElementById('version-message').innerHTML = '<span style="color:var(--danger);font-weight:600;">发现新版本，请前往 Gitee 下载升级包</span>';
            const link = document.getElementById('download-link');
            link.href = data.download_url || data.release_page;
            link.style.display = 'inline-block';
        } else {
            document.getElementById('version-message').textContent = data.message || '当前已是最新版本';
        }
    } catch(e) {
        document.getElementById('version-message').textContent = '检测失败：' + e.message;
    }
}

export async function doUpgrade() {
    const input = document.getElementById('upgrade-package');
    if (!input.files || !input.files[0]) {
        showToast('请选择升级包');
        return;
    }
    if (!confirm('升级前系统会自动备份数据库与代码文件，确定继续吗？')) return;

    const btn = document.getElementById('btn-upgrade');
    const result = document.getElementById('upgrade-result');
    btn.disabled = true;
    btn.textContent = '升级中，请勿关闭页面...';
    result.innerHTML = '<span style="color:var(--text-secondary);">正在备份并升级，请稍候...</span>';

    try {
        const formData = new FormData();
        formData.append('package', input.files[0]);
        // 统一走 core.request：Cookie 模式（无 Bearer）自动带 X-CSRF-Token，
        // 避免「Bearer null」阻断后端 Cookie 回退识别；升级耗时，超时放宽到 10 分钟
        const data = await request('/admin/upgrade', { method: 'POST', body: formData, isFormData: true, timeout: 600000 });
        result.innerHTML = `<span style="color:var(--primary);">升级成功！${escapeHtml(data.message)}</span><br><small>数据库备份：${escapeHtml(data.db_backup)}<br>代码备份：${escapeHtml(data.code_backup)}</small>`;
        showToast('升级成功');
    } catch(e) {
        result.innerHTML = `<span style="color:var(--danger);">升级失败：${escapeHtml(e.message)}</span>`;
        showToast('升级失败');
    } finally {
        btn.disabled = false;
        btn.textContent = '立即升级';
    }
}

export async function doRollback() {
    if (!confirm('确定要回滚到最近一次升级前的备份吗？当前数据将被覆盖。')) return;

    const btn = document.getElementById('btn-rollback');
    const result = document.getElementById('rollback-result');
    btn.disabled = true;
    btn.textContent = '回滚中...';
    result.innerHTML = '<span style="color:var(--text-secondary);">正在恢复备份...</span>';

    try {
        // 统一走 core.request（与 doUpgrade 同因：修 Cookie 模式鉴权 + CSRF 头）；回滚耗时放宽到 10 分钟
        const data = await request('/admin/rollback', { method: 'POST', timeout: 600000 });
        result.innerHTML = `<span style="color:var(--primary);">回滚成功！${escapeHtml(data.message)}</span>`;
        showToast('回滚成功');
    } catch(e) {
        result.innerHTML = `<span style="color:var(--danger);">回滚失败：${escapeHtml(e.message)}</span>`;
        showToast('回滚失败');
    } finally {
        btn.disabled = false;
        btn.textContent = '执行回滚';
    }
}