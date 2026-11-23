// ============================================================
// plugins.js — 扩展管理：外观主题 + 功能插件
// V175：主题卡片自 settings.js 迁入（主题属「可插拔外观包」，与插件同级管理）
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';



// ============ 插件管理 ============
/** 状态列：启用/停用 + 安装状态合并展示（旧字段 enabled 保持原语义） */
function pluginStatusBadge(p) {
    const installed = String(p.install_status || '') === 'installed';
    const label = String(p.install_status || '') === 'failed'
        ? '安装失败'
        : (installed ? (p.enabled ? '已启用' : '已停用') : '未安装');
    const cls = String(p.install_status || '') === 'failed' || (installed && !p.enabled)
        ? 'badge-danger'
        : (installed ? 'badge-success' : 'badge-secondary');
    return `<span class="badge ${cls}">${escapeHtml(label)}</span>`;
}

export async function loadPlugins() {
    const tbody = document.getElementById('plugins-table');
    try {
        const data = await request('/admin/plugins');
        const list = Array.isArray(data) ? data : (data.list || []);
        tbody.innerHTML = list.map(p => {
            const installed = String(p.install_status || '') === 'installed';
            const safeName = escapeHtml(p.name);
            const extra = p.core_version
                ? ' <span style="font-size:11px;color:var(--text-muted);">核心 ' + escapeHtml(String(p.core_version)) + '</span>'
                : '';
            return `
            <tr>
                <td data-label="插件名称">${escapeHtml(p.title || p.name)}</td>
                <td data-label="描述" data-full>${escapeHtml(p.description || '-')}</td>
                <td data-label="状态">${pluginStatusBadge(p)}</td>
                <td data-label="版本">${escapeHtml(String(p.installed_version || p.version || '-'))}${extra}</td>
                <td data-label="操作" data-actions>
                    <button class="btn ${p.enabled?'btn-danger':'btn-primary'}" data-action="toggle-plugin" data-name="${safeName}" data-enabled="${p.enabled?0:1}">${p.enabled?'禁用':'启用'}</button>
                    ${installed
                        ? `<button class="btn" data-action="upgrade-plugin" data-name="${safeName}" data-from="${escapeHtml(String(p.installed_version || p.version || '0.0.0'))}" data-to="${escapeHtml(String(p.version || '1.0.0'))}">升级</button>`
                        : `<button class="btn btn-primary" data-action="install-plugin" data-name="${safeName}">安装</button>`}
                    ${installed ? `<button class="btn btn-danger" data-action="uninstall-plugin" data-name="${safeName}">卸载</button>` : ''}
                </td>
            </tr>`;
        }).join('') || '<tr><td colspan="5" style="text-align:center;padding:40px;">暂无插件（plugins/ 目录下没有 Plugin.php）</td></tr>';
    } catch(e) {
        // 失败要在表格里可见，不能只弹 toast 就留下空白表体
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--danger);">插件列表加载失败：' + escapeHtml(e.message || '未知错误') + '</td></tr>';
    }
}

export async function togglePlugin(name, enabled) {
    try {
        await request('/admin/plugins', {method:'PUT', body:{name, enabled}});
        showToast(enabled ? '插件已启用' : '插件已禁用');
        loadPlugins();
    } catch(e) { showToast(e.message); }
}

/** E2-5：安装（confirm 是管理员对按清单建表的确认） */
export async function installPlugin(name) {
    try {
        await request('/admin/plugins/install', {method:'POST', body:{name, confirm:true}});
        showToast('插件已安装');
        loadPlugins();
    } catch(e) { showToast(e.message || '安装失败', 'error'); }
}

/** E2-5：升级（把当前安装版本与目标版本交给生命周期钩子） */
export async function upgradePlugin(name, from, to) {
    try {
        await request('/admin/plugins/upgrade', {method:'POST', body:{name, from, to}});
        showToast('插件已升级');
        loadPlugins();
    } catch(e) { showToast(e.message || '升级失败', 'error'); }
}

/** E2-5 / Q3：卸载。仅当插件声明删表时后端返回 drop_confirm_token，此时弹二次确认 */
export async function uninstallPlugin(name) {
    let tokenFromServer = '';
    try {
        const cfg = await request('/admin/plugin-admin-config');
        const settings = Array.isArray(cfg && cfg.settings) ? cfg.settings : [];
        const menus = Array.isArray(cfg && cfg.menus) ? cfg.menus : [];
        const declared = settings.find(s => s.plugin_name === name) || menus.find(m => m.plugin === name);
        tokenFromServer = declared && declared.drop_confirm_token ? String(declared.drop_confirm_token) : '';
    } catch (e) {
        showToast(e.message || '无法获取卸载确认信息', 'error');
        return;
    }
    if (tokenFromServer && !window.confirm('该插件声明了卸载时删除自建表，删除后数据不可恢复。确认继续？')) {
        return;
    }
    try {
        const body = tokenFromServer ? {name, confirm_token: tokenFromServer} : {name};
        const data = await request('/admin/plugins/uninstall', {method:'POST', body});
        showToast(data && data.data_kept ? '插件已停用，数据已保留' : '插件已卸载');
        loadPlugins();
    } catch(e) { showToast(e.message || '卸载失败', 'error'); }
}

// B4a 委托化：插件启用 / 禁用 + 生命周期按钮
registerActions({
    'toggle-plugin': (d) => togglePlugin(d.name, d.enabled === '1'),
    'install-plugin': (d) => installPlugin(d.name),
    'upgrade-plugin': (d) => upgradePlugin(d.name, d.from, d.to),
    'uninstall-plugin': (d) => uninstallPlugin(d.name),
});

// ===== 前台主题卡片（P1 主题系统，V175 自 settings.js 迁入） =====
let _themeList = [];       // 最近一次 GET /admin/themes 的主题清单
let _activeTheme = '';      // 当前选中（由 loadThemes 从后端 active 初始化；保存成功后同步）

/** 扩展管理页双 Tab 切换：外观主题 / 功能插件 */
export function switchExtTab(tab) {
    document.querySelectorAll('.ext-tab').forEach(el => el.classList.remove('active'));
    const btn = document.querySelector('.ext-tab[data-ext-tab="' + tab + '"]');
    if (btn) btn.classList.add('active');
    ['themes', 'plugins'].forEach(t => {
        const el = document.getElementById('ext-tab-' + t);
        if (el) el.classList.toggle('hidden', t !== tab);
    });
}

/** 拉取可用主题并渲染卡片；无效契约主题置灰禁止选中 */
export async function loadThemes() {
    const box = document.getElementById('theme-cards');
    try {
        const data = await request('/admin/themes');
        _themeList = Array.isArray(data.themes) ? data.themes : [];
        // 【修复】活动主题以**后端返回的 active 为准**。
        // 此前优先读隐藏域 ext-active_theme，而该隐藏域在 plugins.html 里硬编码 value="moments"，
        // 于是它永远非空 → 直接短路掉后端的真实活动主题 → 后台卡片永远高亮 moments，
        // 且 _activeTheme 被初始化成 moments，用户点「保存主题」存回去的也是 moments，
        // 表现为「怎么切主题都不生效」。
        // 隐藏域只在后端没给 active 时兜底（且要用真实值，不能是写死的默认主题）。
        const saved = data.active || data.default || 'moments';
        _activeTheme = saved;
        const input = document.getElementById('ext-active_theme');
        if (input) input.value = saved;
        renderThemeCards(saved);
        loadThemeOptions();
        const note = document.getElementById('theme-cards-note');
        if (!_themeList.length && note) {
            note.textContent = '未发现任何主题（templates 下缺少 theme.json）。';
        }
    } catch (e) {
        if (box) box.innerHTML = '<div style="font-size:13px;color:var(--danger);">主题列表加载失败：' + (e.message || '未知错误') + '</div>';
    }
}

function renderThemeCards(selected) {
    const box = document.getElementById('theme-cards');
    if (!box) return;
    if (!_themeList.length) {
        box.innerHTML = '<div style="font-size:13px;color:var(--text-muted,#888);">暂无可用主题</div>';
        return;
    }
    box.innerHTML = _themeList.map(t => {
        const invalid = !t.valid;
        const isSel = !invalid && t.id === selected;
        // preview 兼容两种形态：Response 统一出口（normalizePayload）会把 templates/ 相对路径
        // 绝对化成完整 URL，此时原样使用；相对路径则补站点根前缀。否则会拼出 /http://... 404。
        const previewSrc = /^(https?:)?\/\//i.test(String(t.preview || ''))
            ? String(t.preview)
            : '/' + String(t.preview || '').replace(/^\/+/, '');
        const preview = t.preview
            ? `<img class="theme-card-preview" src="${escapeHtml(previewSrc)}" alt="${escapeHtml(t.name || t.id)}">`
            : `<div class="theme-card-preview" style="display:flex;align-items:center;justify-content:center;color:#bbb;font-size:12px;">无预览图</div>`;
        const badge = invalid
            ? '<span class="theme-card-badge invalid-badge">契约不完整</span>'
            : (t.id === 'moments' ? '<span class="theme-card-badge">默认</span>' : '');
        const meta = `版本 ${t.version || '—'}${t.author ? ' · ' + escapeHtml(t.author) : ''}`;
        const title = invalid
            ? '该主题未通过契约校验，禁止选中：\n' + (t.issues || []).join('\n')
            : '点击选中「' + escapeHtml(t.name || t.id) + '」';
        return `<div class="theme-card${isSel ? ' selected' : ''}${invalid ? ' invalid' : ''}"
                     data-theme-id="${escapeHtml(t.id)}" title="${escapeHtml(title)}"
                     data-action="select-theme">
                    <div class="theme-card-check"></div>
                    ${preview}
                    <div class="theme-card-body">
                        <div class="theme-card-name">${escapeHtml(t.name || t.id)} ${badge}</div>
                        <div class="theme-card-meta">${meta}</div>
                        ${t.description ? `<div class="theme-card-desc">${escapeHtml(t.description)}</div>` : ''}
                    </div>
                </div>`;
    }).join('');
    const note = document.getElementById('theme-cards-note');
    const invalidCount = _themeList.filter(t => !t.valid).length;
    if (note) {
        note.textContent = invalidCount
            ? `共 ${_themeList.length} 套主题，${invalidCount} 套因缺少页面/资源被禁用。` : '';
    }
}

/** 选中一张主题卡片（B4a 委托化：由 select-theme action 分发） */
function selectTheme(id) {
    const t = _themeList.find(x => x.id === id);
    if (!t) return;
    if (!t.valid) {
        showToast('该主题未通过契约校验，无法启用：' + (t.issues || []).join('；'));
        return;
    }
    _activeTheme = id;
    const input = document.getElementById('ext-active_theme');
    if (input) input.value = id;
    document.querySelectorAll('#theme-cards .theme-card').forEach(el => {
        el.classList.toggle('selected', el.dataset.themeId === id);
    });
}

/** 保存主题（独立于「保存设置」：只提交 active_theme，后端 PUT 支持部分更新） */
async function saveTheme() {
    try {
        await request('/admin/settings', { method: 'PUT', body: { active_theme: _activeTheme } });
        showToast('主题已保存，前台即时生效');
        loadThemeOptions();
    } catch (e) { showToast(e.message); }
}

// ===== 主题外观选项（通用框架：按主题分别持久化，切主题不清存档） =====
let _themeOptionFields = [];
let _themeOptionValues = {};

/** 拉取当前主题的选项 schema 与已存值并渲染表单；主题无 settings.json 时隐藏面板 */
async function loadThemeOptions() {
    const panel = document.getElementById('theme-options-panel');
    const body = document.getElementById('theme-options-body');
    if (!panel || !body) return;
    try {
        const data = await request('/admin/theme-options');
        _themeOptionFields = Array.isArray(data.fields) ? data.fields : [];
        _themeOptionValues = data.values || {};
        if (!_themeOptionFields.length) {
            panel.classList.add('hidden');
            body.innerHTML = '';
            return;
        }
        // 面板标注当前生效主题，避免管理员切主题后不知道这份表单作用于谁
        const themeName = document.getElementById('theme-options-theme');
        if (themeName) themeName.textContent = data.theme ? '（当前：' + data.theme + '）' : '';
        panel.classList.remove('hidden');
        renderThemeOptions();
    } catch (e) {
        // 加载失败（鉴权/授权/服务端异常）要给出可见反馈，不能静默隐藏让管理员以为主题没有外观设置
        panel.classList.remove('hidden');
        body.innerHTML = '<div style="font-size:13px;color:var(--danger);">外观设置加载失败：' + escapeHtml(e.message || '未知错误') + '</div>';
    }
}

/** 渲染单个外观选项控件 */
function renderThemeField(f) {
    const val = _themeOptionValues[f.key];
    const label = escapeHtml(f.label || f.key);
    const desc = f.desc
        ? `<div style="font-size:11.5px;color:var(--text-muted);margin-top:5px;line-height:1.6;">${escapeHtml(f.desc)}</div>`
        : '';

    if (f.type === 'switch') {
        const on = val === true || val === '1' || val === 1;
        return `<div class="switch-card" style="border:1px solid var(--border);border-radius:10px;padding:12px;margin-bottom:10px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" data-opt-key="${escapeHtml(f.key)}" ${on ? 'checked' : ''}>
                <span class="sc-title">${label}</span>
            </label>${desc}
        </div>`;
    }

    let control = '';
    switch (f.type) {
        case 'color':
            control = `<input type="color" class="form-input" data-opt-key="${escapeHtml(f.key)}" value="${escapeHtml(val || '#000000')}" style="max-width:120px;padding:3px;height:38px;">`;
            break;
        case 'select': {
            const opts = (f.options || []).map(o =>
                `<option value="${escapeHtml(o.value)}" ${escapeHtml(String(val) === String(o.value) ? 'selected' : '')}>${escapeHtml(o.label || o.value)}</option>`
            ).join('');
            control = `<select class="form-input" data-opt-key="${escapeHtml(f.key)}">${opts}</select>`;
            break;
        }
        case 'textarea':
            control = `<textarea class="form-input" data-opt-key="${escapeHtml(f.key)}" rows="3">${escapeHtml(val == null ? '' : String(val))}</textarea>`;
            break;
        case 'number':
            control = `<input type="number" class="form-input" data-opt-key="${escapeHtml(f.key)}" value="${escapeHtml(val == null ? '' : String(val))}">`;
            break;
        case 'image':
            control = `<div style="display:flex;gap:8px;align-items:center;">
                <input type="text" class="form-input" data-opt-key="${escapeHtml(f.key)}" value="${escapeHtml(val == null ? '' : String(val))}" placeholder="点「上传」或直接粘贴图片地址" style="flex:1;">
                <button class="btn" type="button" data-action="upload-theme-image" data-opt-target="${escapeHtml(f.key)}">上传</button>
            </div>`;
            break;
        default:
            control = `<input type="text" class="form-input" data-opt-key="${escapeHtml(f.key)}" value="${escapeHtml(val == null ? '' : String(val))}">`;
    }
    return `<div class="form-group" style="margin-bottom:14px;"><label>${label}</label>${control}${desc}</div>`;
}

/** 按字段 group 渲染为标签页；无 group 的老主题全部归入「外观」单页，行为不变 */
function renderThemeOptions() {
    const body = document.getElementById('theme-options-body');
    if (!body) return;
    const hasGroups = _themeOptionFields.some(f => f.group);
    if (!hasGroups) {
        // 兼容旧主题：不分组，直接平铺
        body.innerHTML = `<div data-opt-panel="__all">${_themeOptionFields.map(renderThemeField).join('')}</div>`;
        return;
    }
    // 按字段出现顺序聚合分组
    const groups = [];
    const byName = {};
    _themeOptionFields.forEach(f => {
        const g = f.group || '其他';
        if (!byName[g]) { byName[g] = []; groups.push(g); }
        byName[g].push(f);
    });
    let active = groups[0];
    const tabs = groups.map((g, i) =>
        `<button type="button" class="theme-opt-tab${i === 0 ? ' active' : ''}" data-opt-tab="${escapeHtml(g)}" style="border:1px solid var(--border);background:${i === 0 ? 'var(--brand-soft,rgba(45,212,191,.12))' : 'transparent'};color:${i === 0 ? 'var(--brand,#2dd4bf)' : 'var(--text-secondary)'};padding:7px 14px;border-radius:8px;cursor:pointer;font-size:13px;white-space:nowrap;">${escapeHtml(g)}</button>`
    ).join('');
    const panels = groups.map((g, i) =>
        `<div data-opt-panel="${escapeHtml(g)}" style="${i === 0 ? '' : 'display:none;'}padding-top:14px;">${byName[g].map(renderThemeField).join('')}</div>`
    ).join('');
    body.innerHTML = `<div class="theme-opt-tabs" style="display:flex;gap:8px;flex-wrap:wrap;border-bottom:1px solid var(--border);padding-bottom:12px;">${tabs}</div>${panels}`;
    body.querySelectorAll('[data-opt-tab]').forEach(btn => {
        btn.addEventListener('click', () => {
            const g = btn.dataset.optTab;
            active = g;
            body.querySelectorAll('[data-opt-tab]').forEach(b => {
                const on = b === btn;
                b.classList.toggle('active', on);
                b.style.background = on ? 'var(--brand-soft,rgba(45,212,191,.12))' : 'transparent';
                b.style.color = on ? 'var(--brand,#2dd4bf)' : 'var(--text-secondary)';
            });
            body.querySelectorAll('[data-opt-panel]').forEach(p => {
                p.style.display = p.dataset.optPanel === g ? '' : 'none';
            });
        });
    });
}

/** 收集表单值并保存到当前主题 */
async function saveThemeOptions() {
    const inputs = document.querySelectorAll('#theme-options-body [data-opt-key]');
    const options = {};
    inputs.forEach(el => {
        const key = el.dataset.optKey;
        options[key] = el.type === 'checkbox' ? el.checked : el.value;
    });
    try {
        await request('/admin/theme-options', { method: 'PUT', body: { options } });
        showToast('外观设置已保存');
        // 重新拉取后端规范化后的值回填表单（如非法色值被回退默认），保证所见即所存
        loadThemeOptions();
    } catch (e) { showToast(e.message); }
}

/** 一键恢复当前主题的全部外观选项为 schema 默认值 */
async function resetThemeOptions() {
    if (!confirm('确定将当前主题的所有外观设置恢复为默认值吗？')) return;
    const options = {};
    _themeOptionFields.forEach(f => { options[f.key] = f.default; });
    try {
        await request('/admin/theme-options', { method: 'PUT', body: { options } });
        showToast('已恢复默认外观设置');
        loadThemeOptions();
    } catch (e) { showToast(e.message); }
}

/** image 字段：选文件 → 传 /admin/theme-options/upload → 回填地址框（落库仍走「保存外观设置」） */
function pickAndUploadThemeImage(key) {
    const picker = document.createElement('input');
    picker.type = 'file';
    picker.accept = 'image/*';
    picker.addEventListener('change', async () => {
        if (!picker.files || !picker.files[0]) return;
        const formData = new FormData();
        formData.append('image', picker.files[0]);
        try {
            const data = await request('/admin/theme-options/upload', { method: 'POST', body: formData, isFormData: true });
            const target = document.querySelector('#theme-options-body [data-opt-key="' + key + '"]');
            if (target) target.value = data.url;
            showToast('图片已上传，请点「保存外观设置」生效');
        } catch (e) { showToast(e.message || '图片上传失败'); }
    });
    picker.click();
}

// 扩展管理页：Tab 切换 / 主题卡片选中 / 保存主题 / 保存外观设置
registerActions({
    'switch-ext-tab': (d) => switchExtTab(d.extTab),
    'select-theme': (d) => selectTheme(d.themeId),
    'save-theme': () => saveTheme(),
    'save-theme-options': () => saveThemeOptions(),
    'reset-theme-options': () => resetThemeOptions(),
    'upload-theme-image': (d) => pickAndUploadThemeImage(d.optTarget),
});
