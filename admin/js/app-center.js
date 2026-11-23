// ============================================================
// app-center.js — 后台「应用中心」页（站点端 agent）
// 连接唯一的应用中心：市场浏览 / 购买码激活 / 在线安装 / 完整性校验。
// 遵循全站 data-action 委托模式，API 统一走 core.js 的 request()。
// ============================================================
import { registerActions, request, showToast, escapeHtml } from './core.js';

const API = '/api/admin/appcenter';

let appSearch = '';
let currentApps = [];

/** 页面加载入口（由 nav-ui pageLoaders 调用）。 */
export function loadAppCenter() {
    loadConfig();
    loadApps();
    loadInstalled();
    loadLicenses();
    loadBaselines();
}

// ---------- Tab 切换 ----------
function switchTab(tab) {
    ['ac-market', 'ac-installed', 'ac-license', 'ac-settings'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.toggle('hidden', id !== tab);
    });
    const tabs = document.querySelectorAll('#page-app-center .ext-tab');
    tabs.forEach(b => b.classList.toggle('active', b.getAttribute('data-ext-tab') === tab));
}

// ---------- 配置 ----------
async function loadConfig() {
    try {
        const cfg = await request(API + '/config');
        const apiInput = document.getElementById('ac-api');
        if (apiInput) apiInput.value = cfg.api || '';
        const info = document.getElementById('ac-config-info');
        if (!info) return;
        if (cfg.registered) {
            info.innerHTML = '<span style="color:var(--success,#2e7d32);">● 已注册</span>　'
                + '站点名称：<b>' + escapeHtml(cfg.site_name || '-') + '</b><br>'
                + 'site_key：<code>' + escapeHtml(cfg.site_key || '') + '</code><br>'
                + 'site_secret：' + escapeHtml(cfg.site_secret_masked || '') + '（仅本机保存）';
        } else {
            info.innerHTML = '<span style="color:var(--warning,#b8860b);">○ 尚未注册</span>　请先填写 API 地址并注册新站点。';
        }
    } catch (e) {
        showToast('读取配置失败：' + e.message, 3000);
    }
}

// ---------- 应用市场 ----------
async function loadApps() {
    const status = document.getElementById('ac-market-status');
    const box = document.getElementById('ac-apps');
    if (!box) return;
    try {
        const data = await request(API + '/apps');
        currentApps = Array.isArray(data.apps) ? data.apps : [];
        const kw = appSearch.trim().toLowerCase();
        const apps = kw
            ? currentApps.filter(a => ((a.title || '') + (a.slug || '') + (a.description || '')).toLowerCase().includes(kw))
            : currentApps;
        if (status) status.textContent = '共 ' + apps.length + ' 个应用';
        renderApps(apps, box);
    } catch (e) {
        if (status) status.textContent = '';
        box.innerHTML = '<div style="font-size:13px;color:#c62828;">加载失败：' + escapeHtml(e.message)
            + '。请先在「设置与校验」里配置中心地址并注册。</div>';
    }
}

function renderApps(apps, box) {
    if (!apps.length) {
        box.innerHTML = '<div style="font-size:13px;color:var(--text-muted,#888);">暂无应用</div>';
        return;
    }
    box.innerHTML = apps.map(a => {
        const isPlugin = a.type === 'plugin';
        const price = a.price_type === 'free' || !a.price_type
            ? '<span style="color:var(--success,#2e7d32);">免费</span>'
            : '<span style="color:#c62828;">¥' + escapeHtml(String(a.price ?? '')) + '</span>';
        const licensed = a.licensed
            ? '<span style="color:var(--success,#2e7d32);">已授权</span>'
            : (a.price_type === 'free' || !a.price_type ? '' : '<span style="color:var(--warning,#b8860b);">未授权</span>');
        return '<div style="flex:1 1 260px;max-width:340px;border:1px solid var(--border);border-radius:8px;padding:14px;display:flex;flex-direction:column;gap:8px;">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;">'
            + '<strong style="font-size:15px;">' + escapeHtml(a.title || a.slug || '') + '</strong>'
            + '<span style="font-size:11px;padding:2px 8px;border-radius:10px;background:var(--notice-bg);">' + (isPlugin ? '插件' : '主题') + '</span>'
            + '</div>'
            + '<div style="font-size:12px;color:var(--text-secondary);min-height:36px;line-height:1.6;">' + escapeHtml(a.description || '暂无描述') + '</div>'
            + '<div style="font-size:12px;color:var(--text-muted,#888);">版本 ' + escapeHtml(a.latest_version || '-') + '　' + price + '　' + licensed + '</div>'
            + '<div style="margin-top:auto;">'
            + '<button class="btn btn-primary" data-action="ac-install" data-app-id="' + escapeHtml(String(a.id)) + '">安装 / 更新</button>'
            + '</div></div>';
    }).join('');
}

async function checkUpdates() {
    try {
        const data = await request(API + '/check-updates', { method: 'POST', body: {} });
        const box = document.getElementById('ac-updates');
        if (!box) return;
        const updates = Array.isArray(data.updates) ? data.updates : [];
        box.classList.remove('hidden');
        if (!updates.length) {
            box.innerHTML = '<span style="color:var(--success,#2e7d32);">✓ 已是最新版本</span>';
        } else {
            box.innerHTML = '<b>有 ' + updates.length + ' 个可更新：</b><br>' + updates.map(u =>
                escapeHtml(u.slug || String(u.app_id)) + '：' + escapeHtml(u.installed_version || '-') + ' → ' + escapeHtml(u.latest_version || '-')
            ).join('<br>');
        }
    } catch (e) {
        showToast('检查更新失败：' + e.message, 3000);
    }
}

// ---------- 安装 ----------
async function installApp(appId, btn) {
    if (!appId) return;
    const old = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '安装中…'; }
    try {
        const res = await request(API + '/install', { method: 'POST', body: { app_id: Number(appId) }, timeout: 120000 });
        const warns = Array.isArray(res.warnings) && res.warnings.length
            ? '\n提示：' + res.warnings.join('；') : '';
        showToast('安装成功：' + (res.slug || '') + warns, 4000);
        loadInstalled();
        loadApps();
    } catch (e) {
        showToast('安装失败：' + e.message, 4000);
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = old; }
    }
}

// ---------- 已安装 ----------
async function loadInstalled() {
    const tbody = document.getElementById('ac-installed-table');
    if (!tbody) return;
    try {
        const data = await request(API + '/installed');
        const apps = Array.isArray(data.apps) ? data.apps : [];
        if (!apps.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="color:var(--text-muted,#888);">暂无已安装扩展</td></tr>';
            return;
        }
        tbody.innerHTML = apps.map(a => {
            const managed = a.managed;
            const missing = a.missing ? ' <span style="color:#c62828;">(目录缺失)</span>' : '';
            const src = managed ? '<span style="color:var(--success,#2e7d32);">应用中心</span>' : '内置/其他';
            const op = managed
                ? '<button class="btn" data-action="ac-uninstall" data-type="' + escapeHtml(a.type) + '" data-slug="' + escapeHtml(a.slug) + '">卸载</button> '
                : '';
            const baseOp = '<button class="btn" data-action="ac-baseline" data-type="' + escapeHtml(a.type) + '" data-slug="' + escapeHtml(a.slug) + '">建基线</button> '
                + '<button class="btn" data-action="ac-verify" data-type="' + escapeHtml(a.type) + '" data-slug="' + escapeHtml(a.slug) + '">校验</button>';
            return '<tr>'
                + '<td>' + escapeHtml(a.title || a.slug) + missing + '</td>'
                + '<td>' + (a.type === 'plugin' ? '插件' : '主题') + '</td>'
                + '<td>' + escapeHtml(a.installed_version || a.version || '-') + '</td>'
                + '<td>' + src + '</td>'
                + '<td>' + (a.enabled ? '<span style="color:var(--success,#2e7d32);">已启用</span>' : '<span style="color:var(--text-muted,#888);">—</span>') + '</td>'
                + '<td>' + op + baseOp + '</td>'
                + '</tr>';
        }).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="6" style="color:#c62828;">加载失败：' + escapeHtml(e.message) + '</td></tr>';
    }
}

async function uninstallApp(type, slug) {
    if (!type || !slug) return;
    if (!confirm('确定卸载 ' + slug + ' 吗？卸载前会自动备份到 cache 目录。')) return;
    try {
        const res = await request(API + '/uninstall', { method: 'POST', body: { type, slug } });
        showToast('已卸载：' + slug + '（备份：' + (res.backup || '-') + '）', 3500);
        loadInstalled();
    } catch (e) {
        showToast('卸载失败：' + e.message, 3500);
    }
}

// ---------- 购买码 ----------
async function activateLicense() {
    const input = document.getElementById('ac-license-key');
    const key = input ? input.value.trim() : '';
    if (!key) { showToast('请输入购买码'); return; }
    try {
        await request(API + '/activate', { method: 'POST', body: { license_key: key } });
        showToast('购买码激活成功', 3000);
        if (input) input.value = '';
        loadLicenses();
        loadApps();
    } catch (e) {
        showToast('激活失败：' + e.message, 3500);
    }
}

async function loadLicenses() {
    const tbody = document.getElementById('ac-licenses-table');
    if (!tbody) return;
    try {
        const data = await request(API + '/licenses');
        const list = Array.isArray(data.licenses) ? data.licenses : [];
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="4" style="color:var(--text-muted,#888);">暂无已激活授权</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(l => {
            const status = (l.status === 'active')
                ? '<span style="color:var(--success,#2e7d32);">正常</span>'
                : '<span style="color:#c62828;">' + escapeHtml(l.status || '未知') + '</span>';
            return '<tr>'
                + '<td>' + escapeHtml(l.app_title || l.slug || String(l.app_id ?? '')) + '</td>'
                + '<td><code>' + escapeHtml(l.license_key || '') + '</code></td>'
                + '<td>' + escapeHtml(l.expires_at || '永久') + '</td>'
                + '<td>' + status + '</td>'
                + '</tr>';
        }).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="4" style="color:#c62828;">加载失败：' + escapeHtml(e.message) + '</td></tr>';
    }
}

// ---------- 设置与校验 ----------
async function saveApi() {
    const input = document.getElementById('ac-api');
    const api = input ? input.value.trim() : '';
    try {
        await request(API + '/config', { method: 'POST', body: { api } });
        showToast('中心地址已保存', 2500);
        loadConfig();
    } catch (e) {
        showToast('保存失败：' + e.message, 3000);
    }
}

async function ping() {
    try {
        const data = await request(API + '/ping');
        showToast('连接成功：' + (data.site_name || data.site_key || '应用中心'), 3000);
    } catch (e) {
        showToast('连接失败：' + e.message, 3500);
    }
}

async function registerSite() {
    const api = (document.getElementById('ac-api')?.value || '').trim();
    const name = (document.getElementById('ac-site-name')?.value || '').trim();
    if (!name) { showToast('请填写站点名称'); return; }
    try {
        await request(API + '/register', { method: 'POST', body: { api, name } });
        showToast('注册成功，凭据已保存到本机', 3500);
        loadConfig();
        loadApps();
    } catch (e) {
        showToast('注册失败：' + e.message, 3500);
    }
}

async function loadBaselines() {
    const tbody = document.getElementById('ac-baselines-table');
    if (!tbody) return;
    try {
        const data = await request(API + '/baselines');
        const list = Array.isArray(data.baselines) ? data.baselines : [];
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="color:var(--text-muted,#888);">暂无基线，请先对已安装扩展「建基线」</td></tr>';
            return;
        }
        tbody.innerHTML = list.map(b =>
            '<tr>'
            + '<td>' + (b.type === 'plugin' ? '插件' : '主题') + '</td>'
            + '<td>' + escapeHtml(b.slug) + '</td>'
            + '<td>' + escapeHtml(String(b.files)) + '</td>'
            + '<td>' + escapeHtml(b.created_at) + '</td>'
            + '<td>'
            + '<button class="btn" data-action="ac-verify" data-type="' + escapeHtml(b.type) + '" data-slug="' + escapeHtml(b.slug) + '">校验</button> '
            + '<button class="btn" data-action="ac-delete-baseline" data-type="' + escapeHtml(b.type) + '" data-slug="' + escapeHtml(b.slug) + '">删除</button>'
            + '</td></tr>'
        ).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="5" style="color:#c62828;">加载失败：' + escapeHtml(e.message) + '</td></tr>';
    }
}

async function buildBaseline(type, slug) {
    if (!type || !slug) return;
    try {
        const res = await request(API + '/baseline', { method: 'POST', body: { type, slug } });
        showToast('基线已建立：' + slug + '（' + res.files + ' 个文件）', 3000);
        loadBaselines();
    } catch (e) {
        showToast('建立基线失败：' + e.message, 3500);
    }
}

async function verifyBaseline(type, slug) {
    if (!type || !slug) return;
    try {
        const data = await request(API + '/verify?type=' + encodeURIComponent(type) + '&slug=' + encodeURIComponent(slug));
        showVerifyResult(slug, data);
    } catch (e) {
        showToast('校验失败：' + e.message, 3500);
    }
}

function showVerifyResult(slug, res) {
    const box = document.getElementById('ac-verify-result');
    if (!box) return;
    box.classList.remove('hidden');
    const statusMap = {
        ok: '<b style="color:var(--success,#2e7d32);">✓ 未被篡改</b>',
        tampered: '<b style="color:#c62828;">✗ 检测到改动</b>',
        no_baseline: '<b style="color:var(--warning,#b8860b);">尚无基线</b>',
        missing_dir: '<b style="color:#c62828;">扩展目录缺失</b>',
    };
    let html = '【' + escapeHtml(slug) + '】' + (statusMap[res.status] || escapeHtml(res.status)) + '<br>';
    if (res.status === 'tampered') {
        if (res.modified?.length) html += '修改：' + res.modified.map(escapeHtml).join('、') + '<br>';
        if (res.added?.length) html += '新增：' + res.added.map(escapeHtml).join('、') + '<br>';
        if (res.deleted?.length) html += '删除：' + res.deleted.map(escapeHtml).join('、') + '<br>';
    }
    if (res.baseline_at) html += '<span style="color:var(--text-muted,#888);">基线时间：' + escapeHtml(res.baseline_at) + '</span>';
    box.innerHTML = html;
}

async function deleteBaseline(type, slug) {
    if (!type || !slug) return;
    try {
        await request(API + '/baseline/delete', { method: 'POST', body: { type, slug } });
        showToast('基线已删除', 2500);
        loadBaselines();
    } catch (e) {
        showToast('删除失败：' + e.message, 3000);
    }
}

// ==================== 动作注册 ====================
registerActions({
    'switch-appcenter-tab': (d) => switchTab(d.extTab),
    'ac-refresh-apps': () => loadApps(),
    'ac-check-updates': () => checkUpdates(),
    'ac-install': (d, el) => installApp(d.appId, el),
    'ac-refresh-installed': () => loadInstalled(),
    'ac-uninstall': (d) => uninstallApp(d.type, d.slug),
    'ac-activate': () => activateLicense(),
    'ac-save-api': () => saveApi(),
    'ac-ping': () => ping(),
    'ac-register': () => registerSite(),
    'ac-baseline': (d) => buildBaseline(d.type, d.slug),
    'ac-verify': (d) => verifyBaseline(d.type, d.slug),
    'ac-delete-baseline': (d) => deleteBaseline(d.type, d.slug),
});

// 搜索框
document.addEventListener('input', (e) => {
    if (e.target && e.target.id === 'ac-search') {
        appSearch = e.target.value || '';
        loadApps();
    }
});
