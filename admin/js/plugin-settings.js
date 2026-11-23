// ============================================================
// plugin-settings.js — 插件设置页通用容器（E2-3 / E2-4 / Q8）
// 插件只声明 settings.html 与配置键；设置页 HTML 由核心接口读取并做越界校验，
// 配置项落 plugin_configs（plugin_name + key 隔离），不碰核心 settings 白名单。
// ============================================================
import { API_BASE, token, request, showToast, escapeHtml, registerActions } from './core.js';

let settingsList = [];
let currentPlugin = '';

/** 进入页面时拉取后台声明，填充插件下拉并载入第一个设置页 */
export async function loadPluginSettings() {
    const picker = document.getElementById('plugin-settings-picker');
    const notice = document.getElementById('plugin-settings-notice');
    if (!picker) return;
    try {
        const cfg = await request('/admin/plugin-admin-config');
        settingsList = Array.isArray(cfg && cfg.settings) ? cfg.settings : [];
        picker.innerHTML = settingsList.map(s =>
            `<option value="${escapeHtml(s.plugin_name)}">${escapeHtml(s.title || s.plugin_name)}</option>`
        ).join('') || '<option value="">暂无插件设置页</option>';
        if (notice) {
            notice.textContent = settingsList.length
                ? '选择插件后载入其 settings.html；带 data-config-key 的控件可一键保存到插件独立配置。'
                : '当前没有已启用且声明了 admin_settings 的插件。';
        }
        currentPlugin = settingsList.length ? String(settingsList[0].plugin_name || '') : '';
        if (currentPlugin) {
            await loadSettingsHtml(currentPlugin);
        }
    } catch (e) {
        if (notice) notice.textContent = '插件设置声明加载失败：' + (e.message || '未知错误');
    }
}

/** 读取插件设置页 HTML 文本，并回填已有配置值 */
async function loadSettingsHtml(pluginName) {
    const host = document.getElementById('plugin-settings-host');
    const status = document.getElementById('plugin-settings-status');
    if (!host) return;
    host.textContent = '正在载入设置页…';
    if (status) status.textContent = '';
    try {
        const headers = {};
        if (token) headers['Authorization'] = 'Bearer ' + token;
        const url = API_BASE + '/admin/plugin-settings-page?plugin_name=' + encodeURIComponent(pluginName);
        const res = await fetch(url, { headers, credentials: 'include' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        host.innerHTML = await res.text();
        // 约定：插件在 settings.html 中给需要持久化的控件加 data-config-key
        host.querySelectorAll('[data-config-key]').forEach(async (el) => {
            const key = el.dataset.configKey;
            if (!key) return;
            try {
                const cfg = await request('/admin/plugin-config?plugin_name=' + encodeURIComponent(pluginName)
                    + '&key=' + encodeURIComponent(key));
                el.value = cfg && cfg.value != null ? String(cfg.value) : '';
            } catch (e) {
                // 单个键读取失败不阻塞整页：未配置时控件保持插件自带默认值
            }
        });
    } catch (e) {
        host.textContent = '设置页加载失败：' + (e.message || '未知错误');
    }
}

/** 保存当前插件所有 data-config-key 控件的值（逐键幂等 upsert） */
async function savePluginConfig() {
    if (!currentPlugin) {
        showToast('请先选择插件', 'error');
        return;
    }
    const host = document.getElementById('plugin-settings-host');
    const status = document.getElementById('plugin-settings-status');
    const fields = host ? Array.from(host.querySelectorAll('[data-config-key]')) : [];
    if (!fields.length) {
        showToast('该设置页没有可保存的配置项');
        return;
    }
    try {
        for (const el of fields) {
            await request('/admin/plugin-config', {
                method: 'POST',
                body: { plugin_name: currentPlugin, key: el.dataset.configKey, value: String(el.value || '') },
            });
        }
        if (status) status.textContent = '已保存 ' + fields.length + ' 项配置';
        showToast('插件配置已保存');
    } catch (e) {
        if (status) status.textContent = '保存失败：' + (e.message || '未知错误');
        showToast(e.message || '插件配置保存失败', 'error');
    }
}

registerActions({
    'plugin-settings-select': (d, el) => {
        currentPlugin = String(el.value || '');
        if (currentPlugin) loadSettingsHtml(currentPlugin);
    },
    'plugin-settings-save': () => savePluginConfig(),
});
