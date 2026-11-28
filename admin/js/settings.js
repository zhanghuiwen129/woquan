// ============================================================
// settings.js — 系统设置：开关、导航排序、站点封面
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, request, showToast } from './core.js';
import { refreshCronTip } from './cron.js';
import { switchSmsProvider } from './realname.js';
import { renderWatermarkImage, toggleWatermarkFields } from './watermark.js';
import { cloudDriverChange } from './storage.js';
// P1-2 懒加载：ai/mail 是孤立模块（动作元素全在本页片段内、无其他消费者），
// 原 main.js 死 import 的副作用承接点迁到这里，否则设置页 AI/邮件交互静默失效
import './ai.js';
import './mail.js';

// P2-1 片段懒加载：settings 页片段首次挂载后的一次性初始化
// （原 main.js DOMContentLoaded 里的 switchSettingTab('site') 与封面拖拽绑定整体迁入）
let settingsPageInitDone = false;
export function initSettingsPage() {
    if (settingsPageInitDone) return;
    settingsPageInitDone = true;

    // V69：标签合并后，静态 HTML 里只有 site 面板是不带 hidden 的，
    // 同组的 cover 仍带 hidden，首屏会漏显示。这里统一按映射初始化一次。
    switchSettingTab('site');

    // 首页封面拖拽上传：选择文件即上传，无中间"保存"按钮
    // （与全局"保存设置"按钮的语义不冲突——"保存设置"仅提交表单字段，与封面图片无关）
    const dropZone = document.getElementById('cover-drop-zone');
    if (!dropZone) return;
    ['dragover', 'dragenter'].forEach(evt => {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.style.background = '#e6f7ff';
            this.style.borderColor = 'var(--info)';
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        dropZone.addEventListener(evt, function (e) {
            e.preventDefault();
            e.stopPropagation();
            this.style.background = '#fafafa';
            this.style.borderColor = '#d9d9d9';
        });
    });
    dropZone.addEventListener('drop', function (e) {
        const files = e.dataTransfer.files;
        if (files && files.length > 0) {
            const fileInput = document.getElementById('cover-file-input');
            fileInput.files = files;
            handleCoverUpload({ target: { files } });
        }
    });
}



/**
 * V69：一个标签可以对应多个面板（原来 11 个标签太多，标签条要横向滚动）。
 * 这样合并不需要搬动任何 HTML 结构，也就不会碰坏字段 id 和保存逻辑。
 */
export const SETTING_TAB_PANELS = {
    site:     ['site', 'cover'],          // 站点信息 + 首页封面
    content:  ['permission', 'post'],     // 权限控制 + 动态设置
    storage:  ['upload', 'cloud'],        // 上传设置 + 云存储直传
    security: ['security'],               // 安全限流
    service:  ['ai', 'mail', 'sms'],       // AI 智能 + 邮件设置 + 短信服务
    feature:  ['feature']                 // 功能开关（V69-B：底部导航栏并入此面板，和帖子类型/工具栏开关放一起）
};

// V174（自 yigengan 移植）：双层标签——主标签 → 子标签（一子页一面板）。
// 映射按本站（www2）实际面板结构适配：只有多面板的主标签才出子标签条。
const SETTING_SUBTABS = {
    site:     [{ sub: 'site', label: '站点信息' }, { sub: 'cover', label: '首页封面' }],
    content:  [{ sub: 'permission', label: '基础权限' }, { sub: 'post', label: '动态设置' }],
    storage:  [{ sub: 'upload', label: '上传设置' }, { sub: 'cloud', label: '云存储' }],
    security: [{ sub: 'security', label: '接口限流' }],
    service:  [{ sub: 'ai', label: 'AI 服务' }, { sub: 'mail', label: '邮件设置' }, { sub: 'sms', label: '短信服务' }],
    feature:  [{ sub: 'feature', label: '功能开关' }]
};

/** V174：动态生成本站各主标签的子标签条（插在主标签条后面，免改 index.html 结构） */
function ensureSettingSubtabBars() {
    const tabBar = document.querySelector('.tab-bar');
    if (!tabBar || document.getElementById('setting-subtabs-site')) return;
    Object.entries(SETTING_SUBTABS).forEach(([tab, subs]) => {
        if (subs.length <= 1) return; // 单子页不出条
        const bar = document.createElement('div');
        bar.className = 'setting-subtabs hidden';
        bar.id = 'setting-subtabs-' + tab;
        bar.innerHTML = subs.map(s =>
            `<button class="setting-subtab" data-tab="${tab}" data-sub="${s.sub}" data-action="switch-setting-sub-tab">${escapeHtml(s.label)}</button>`
        ).join('');
        tabBar.after(bar);
    });
}

/** V174：切主标签——点亮主标签按钮，只显示该主标签的子标签条，并激活它的第一个子页 */
export function switchSettingTab(tab) {
    ensureSettingSubtabBars();
    const panels = SETTING_TAB_PANELS[tab];
    if (!panels) return;
    document.querySelectorAll('.setting-tab').forEach(el => el.classList.remove('active'));
    const btn = document.querySelector('.setting-tab[data-tab="' + tab + '"]');
    if (btn) btn.classList.add('active');
    // 先全部隐藏，再按映射逐个显示（同一次点击只显示这一组）
    document.querySelectorAll('[id^="setting-tab-"]').forEach(el => el.classList.add('hidden'));
    panels.forEach(p => {
        const el = document.getElementById('setting-tab-' + p);
        if (el) el.classList.remove('hidden');
    });
    // V174：主标签条显隐 + 激活第一个子页
    document.querySelectorAll('.setting-subtabs').forEach(el => el.classList.add('hidden'));
    const bar = document.getElementById('setting-subtabs-' + tab);
    if (bar) bar.classList.remove('hidden');
    const subs = SETTING_SUBTABS[tab];
    if (subs && subs.length > 1) switchSettingSubTab(tab, subs[0].sub);
}

// B2 委托化：设置 Tab 切换（data-action="switch-setting-tab"，复用 data-tab 属性）
registerActions({
    'switch-setting-tab': (d) => switchSettingTab(d.tab),
    'switch-setting-sub-tab': (d) => switchSettingSubTab(d.tab, d.sub),
    // B4b 委托化：静态保存设置（系统设置 + 支付配置两处共用）/ 移除首页封面
    'save-settings': () => saveSettings(),
    'remove-homepage-cover': () => removeHomepageCover(),
    // B5 委托化：封面拖拽区/选择图片按钮 → 触发隐藏 file input（onchange 上传逻辑不变）
    'pick-cover-file': () => document.getElementById('cover-file-input').click(),
    // B6 委托化：file input 选中文件后触发上传（原 onchange 直调）
    'cover-file-changed': (d, el, e) => handleCoverUpload(e),
    // 站点模式（独立保存，不并入 saveSettings 的大表单）
    'select-site-mode': (d) => applySitePreset(d.mode),
    'toggle-site-module': (d, el) => onSiteModuleToggle(d.module, el.checked),
    'save-site-mode': () => saveSiteMode(true),
    'reset-site-mode': () => resetSiteMode(),
});

// ============================================================
// 站点模式：一键切换（预设填充 → 确认 → 落库）+ 依赖联动 + 独立微调
// 模块清单 / 预设矩阵 / 依赖关系全部来自后端 module_catalog（单一真值源），
// 这里不硬编码任何模块 key，后端加模块只需改 api/core/ModuleCatalog.php。
// ============================================================
let siteCatalog = null;
let siteFlags = {};
// 当前"选中"的模式（点击意图），区别于 currentPresetMatch() 的派生结果。
// 修复：之前只有复选框匹配预设时才高亮，点「自定义」永远不高亮。
let selectedMode = null;

function parseSiteFlags(raw) {
    let obj = {};
    try {
        if (typeof raw === 'string' && raw) obj = JSON.parse(raw) || {};
        else if (raw && typeof raw === 'object') obj = raw;
    } catch (e) { obj = {}; }
    const out = {};
    siteCatalog.modules.forEach(m => {
        const v = obj[m.key];
        // 缺省视为开，与后端 ModuleCatalog::normalize() 完全一致
        out[m.key] = (v === undefined || v === null || v === true || v === 1 || v === '1') ? 1 : 0;
    });
    return out;
}

/** 由当前勾选反查匹配的预设名，与后端 detectMode() 同规则 */
function currentPresetMatch() {
    const presets = siteCatalog.presets || {};
    for (const [name, keys] of Object.entries(presets)) {
        const matched = siteCatalog.modules.every(m =>
            (keys.includes(m.key) ? 1 : 0) === siteFlags[m.key]);
        if (matched) return name;
    }
    return 'custom';
}

function syncSitePresetActive() {
    const active = selectedMode || currentPresetMatch();
    document.querySelectorAll('#site-mode-presets [data-mode]').forEach(btn => {
        const on = btn.dataset.mode === active;
        // 始终保留 .btn（圆角 + 内边距），只叠加 .btn-primary 切换高亮态
        btn.classList.add('btn');
        btn.classList.toggle('btn-primary', on);
    });
    const info = (siteCatalog.modes || []).find(m => m.key === active);
    const label = document.getElementById('site-mode-current');
    if (label) label.textContent = '当前：' + (info ? info.label : '自定义');
}

function syncSiteCheckboxes() {
    document.querySelectorAll('#site-mode-switches input[data-module]').forEach(inp => {
        inp.checked = siteFlags[inp.dataset.module] === 1;
    });
}

/** 回填：老版本后端没下发 catalog 时整块不渲染，保持 fail-open */
export function renderSiteMode(data) {
    siteCatalog = (data && data.module_catalog && Array.isArray(data.module_catalog.modules))
        ? data.module_catalog : null;
    if (!siteCatalog) return;
    siteFlags = parseSiteFlags(data.module_flags);
    selectedMode = currentPresetMatch();

    const presetHost = document.getElementById('site-mode-presets');
    const switchHost = document.getElementById('site-mode-switches');
    if (!presetHost || !switchHost) return;

    presetHost.innerHTML = (siteCatalog.modes || []).map(m =>
        `<button type="button" class="btn" data-action="select-site-mode" data-mode="${escapeHtml(m.key)}" title="${escapeHtml(m.desc)}"><i data-icon="layers"></i> ${escapeHtml(m.label)}</button>`
    ).join('');

    switchHost.innerHTML = siteCatalog.modules.map(m =>
        `<div class="switch-card">
            <label>
                <input type="checkbox" id="site-mod-${escapeHtml(m.key)}" data-module="${escapeHtml(m.key)}" data-change="toggle-site-module">
                <span class="sc-title">${escapeHtml(m.label)}</span>
            </label>
            <div class="sc-desc">${escapeHtml(m.desc)}</div>
        </div>`
    ).join('');
    syncSiteCheckboxes();

    const guard = document.getElementById('site-mode-guard');
    if (guard) guard.value = data.feature_guard_mode || 'off';
    syncSitePresetActive();
}

/**
 * 点预设即一键切换：填充开关 + 套用前台档案 → 明确确认 → 复用 doSaveSiteMode 落库。
 * custom 没有固定预设，是"脱离预设的手动微调态"，只高亮、不清空任何开关。
 */
async function applySitePreset(mode) {
    if (!siteCatalog) return;
    const presets = siteCatalog.presets || {};
    // 自定义不是一套预设，而是"脱离预设"的状态：选中它但不动任何开关
    if (mode === 'custom' || !presets[mode]) {
        selectedMode = 'custom';
        syncSitePresetActive();
        showToast('自定义模式请在下方开关中微调后保存');
        return;
    }

    // 确认前快照：用户取消时完整回滚内存态与表单态，避免"看着切了其实没落库"
    const prevFlags = { ...siteFlags };
    const prevMode = selectedMode;
    const frontIds = Array.from(document.querySelectorAll('input[id^="switch-type-"], input[id^="switch-tool-"]'))
        .map(el => el.id)
        .concat(['setting-nav_publish_enabled', 'setting-nav_search_enabled', 'setting-nav_messages_enabled',
                 'setting-nav_favorites_enabled', 'setting-nav_profile_enabled']);
    const frontSnap = {};
    frontIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) frontSnap[id] = el.checked;
    });

    const keys = presets[mode];
    siteCatalog.modules.forEach(m => { siteFlags[m.key] = keys.includes(m.key) ? 1 : 0; });
    selectedMode = mode;
    syncSiteCheckboxes();
    // 同步套用该模式的前台功能档案（帖子类型 / 发布页工具栏 / 底部导航）
    applyFrontendProfile(mode);
    syncSitePresetActive();

    const info = (siteCatalog.modes || []).find(m => m.key === mode);
    const label = info ? info.label : mode;
    const onCount = siteCatalog.modules.filter(m => siteFlags[m.key] === 1).length;
    const ok = confirm(
        '切换到「' + label + '」将立即生效：开启 ' + onCount + ' 个模块、关闭其余模块，' +
        '并调整前台发布类型与导航；已发布内容不会被删除。确定继续吗？');
    if (!ok) {
        siteFlags = prevFlags;
        selectedMode = prevMode;
        syncSiteCheckboxes();
        Object.keys(frontSnap).forEach(id => {
            const el = document.getElementById(id);
            if (el) el.checked = frontSnap[id];
        });
        syncSitePresetActive();
        return;
    }
    try {
        await doSaveSiteMode();
    } catch (e) { showToast(e.message); }
}

/**
 * 把某模式的前台功能档案套用到「功能开关」面板复选框。
 * 与后台模块预设互补：选社交 / 博客 / 资讯时，前台形态也一并切换，
 * 解决"切到博客模式发布页工具栏和底部导航还在"的问题。
 */
function applyFrontendProfile(mode) {
    if (!siteCatalog) return;
    const fp = siteCatalog.frontend && siteCatalog.frontend[mode];
    if (!fp) return;
    document.querySelectorAll('input[id^="switch-type-"]').forEach(el => {
        const t = el.id.replace('switch-type-', '');
        if (t === 'normal') return;
        el.checked = (fp.post_types || []).includes(t);
    });
    document.querySelectorAll('input[id^="switch-tool-"]').forEach(el => {
        const t = el.id.replace('switch-tool-', '');
        el.checked = (fp.post_tools || []).includes(t);
    });
    const navMap = {
        publish: 'setting-nav_publish_enabled', search: 'setting-nav_search_enabled',
        messages: 'setting-nav_messages_enabled', favorites: 'setting-nav_favorites_enabled',
        profile: 'setting-nav_profile_enabled',
    };
    Object.keys(navMap).forEach(k => {
        const el = document.getElementById(navMap[k]);
        if (el) el.checked = !!(fp.nav && fp.nav[k]);
    });
}

function onSiteModuleToggle(key, checked) {
    if (!siteCatalog || siteFlags[key] === undefined) return;
    const def = siteCatalog.modules.find(m => m.key === key) || {};

    if (checked) {
        // 开 A 自动勾上它的依赖
        (def.depends || []).forEach(dep => {
            if (siteFlags[dep] === 0) {
                siteFlags[dep] = 1;
                const el = document.getElementById('site-mod-' + dep);
                if (el) el.checked = true;
            }
        });
        siteFlags[key] = 1;
    } else {
        // 关依赖时若下游仍开 → 阻止（与后端 validate 一致，不静默连带关闭）
        const blockers = siteCatalog.modules.filter(m =>
            m.key !== key && siteFlags[m.key] === 1 && (m.depends || []).includes(key));
        if (blockers.length) {
            const el = document.getElementById('site-mod-' + key);
            if (el) el.checked = true;
            showToast('「' + blockers.map(m => m.label).join('、') + '」还在用此项，请先关闭它们');
            return;
        }
        siteFlags[key] = 0;
    }
    // 手动改动后若已偏离当前选中预设，自动落入"自定义"
    if (selectedMode && selectedMode !== 'custom') {
        const presetKeys = siteCatalog.presets[selectedMode] || [];
        const diverged = siteCatalog.modules.some(m =>
            (presetKeys.includes(m.key) ? 1 : 0) !== siteFlags[m.key]);
        if (diverged) selectedMode = 'custom';
    }
    syncSitePresetActive();
}

async function doSaveSiteMode() {
    if (!siteCatalog) { showToast('模块配置未加载，请刷新页面'); return; }
    const blockers = siteCatalog.modules.filter(m =>
        siteFlags[m.key] === 1 && (m.depends || []).some(d => siteFlags[d] !== 1));
    if (blockers.length) {
        showToast('依赖关系未满足：' + blockers.map(m => m.label).join('、'));
        return;
    }
    const guardEl = document.getElementById('site-mode-guard');
    // 从「功能开关」面板读取当前前台形态（手动微调也一并生效）
    const typeKeys = ['poll', 'question', 'team', 'checkin'].filter(t => {
        const el = document.getElementById('switch-type-' + t);
        return el && el.checked;
    });
    const toolKeys = [];
    document.querySelectorAll('input[id^="switch-tool-"]').forEach(el => {
        if (el.checked) toolKeys.push(el.id.replace('switch-tool-', ''));
    });
    const navMap = {
        publish: 'setting-nav_publish_enabled', search: 'setting-nav_search_enabled',
        messages: 'setting-nav_messages_enabled', favorites: 'setting-nav_favorites_enabled',
        profile: 'setting-nav_profile_enabled',
    };
    const navVals = {};
    Object.keys(navMap).forEach(k => {
        const el = document.getElementById(navMap[k]);
        navVals[k] = (el && el.checked) ? '1' : '0';
    });
    await request('/admin/settings', {
        method: 'PUT',
        body: {
            site_mode: selectedMode || currentPresetMatch(),
            module_flags: JSON.stringify(siteFlags),
            feature_guard_mode: guardEl ? guardEl.value : 'off',
            post_types: ['normal'].concat(typeKeys).join(','),
            post_tools: toolKeys.join(','),
            nav_publish_enabled: navVals.publish,
            nav_search_enabled: navVals.search,
            nav_messages_enabled: navVals.messages,
            nav_favorites_enabled: navVals.favorites,
            nav_profile_enabled: navVals.profile,
        }
    });
    showToast('站点模式已保存');
    // nav-ui.js 监听此事件重新拉配置，菜单即时刷新，无需整页 reload
    document.dispatchEvent(new CustomEvent('hublog:module-flags-changed'));
}

async function saveSiteMode(needConfirm) {
    if (needConfirm && !confirm(
        '保存后将立即改变后台菜单的可见模块' +
        (document.getElementById('site-mode-guard')?.value === 'on' ? '，并启用线上 API 拦截' : '') +
        '。\n切换只改开关、不会删除任何数据，确定继续吗？')) return;
    try {
        await doSaveSiteMode();
    } catch (e) { showToast(e.message); }
}

/** 一键回滚：填入全开并直接提交（写入全 1 即回到升级前状态） */
async function resetSiteMode() {
    if (!siteCatalog) return;
    if (!confirm('恢复全部模块？15 个开关将全部开启，运行层拦截档位保持不变。')) return;
    siteCatalog.modules.forEach(m => { siteFlags[m.key] = 1; });
    syncSiteCheckboxes();
    syncSitePresetActive();
    try {
        await doSaveSiteMode();
    } catch (e) { showToast(e.message); }
}

/** V174：切子标签——只在本主标签的子面板范围内显隐，不影响其它主标签的面板状态 */
export function switchSettingSubTab(tab, sub) {
    const subs = SETTING_SUBTABS[tab];
    if (!subs) return;
    subs.forEach(s => {
        const el = document.getElementById('setting-tab-' + s.sub);
        if (el) el.classList.toggle('hidden', s.sub !== sub);
    });
    const bar = document.getElementById('setting-subtabs-' + tab);
    if (bar) {
        bar.querySelectorAll('.setting-subtab').forEach(b => {
            b.classList.toggle('active', b.getAttribute('data-sub') === sub);
        });
    }
}

/**
 * V64：回填「功能开关」页
 *
 * post_types / post_tools 在 settings 表里存的是逗号分隔串；
 * 这里拆成复选框状态。库里还没有这两个键（老站点升级前）→ 默认全勾，
 * 和后端 AppController 的「缺失即全开」保持一致，否则界面会显示「全部关闭」却实际全开。
 */
export function loadFeatureSwitches(data) {
    const listTo = (raw, fallback) => {
        const str = String(raw || '').trim();
        if (str === '') return fallback;
        return str.split(',').map(s => s.trim()).filter(Boolean);
    };
    const toolKeys = ['image','video','emoji','location','mention','anonymous','link','attachment','topic','draft','schedule','category'];
    const typeKeys = ['poll','question','team','checkin'];
    const onTools = listTo(data.post_tools, toolKeys);
    const onTypes = listTo(data.post_types, ['normal'].concat(typeKeys));

    // 旧闸门关着 → 对应复选框禁用并标红，避免管理员勾了却“看起来没生效”找不到原因
    const gateMap = { location:'allow_location', mention:'allow_mention', anonymous:'allow_anonymous_post',
                      attachment:'allow_upload_attachment', schedule:'allow_scheduled_post' };
    const gateOff = (key) => {
        const g = gateMap[key];
        if (!g) return false;
        const v = data[g];
        return !(v === undefined || v === null || v === '1' || v === true);
    };
    Object.keys(gateMap).forEach(k => {
        try {
            if (gateOff(k)) document.getElementById('gate-hint-' + k).textContent = '（受「' + gateMap[k] + '」约束）';
        } catch (e) { /* 面板不在当前 tab，忽略 */ }
    });

    toolKeys.forEach(k => {
        const el = document.getElementById('switch-tool-' + k);
        if (el) el.checked = onTools.indexOf(k) >= 0;
    });
    typeKeys.forEach(k => {
        const el = document.getElementById('switch-type-' + k);
        if (el) el.checked = onTypes.indexOf(k) >= 0;
    });
}

// ===== 前台主题卡片（P1 主题系统）—— V175 已迁至 plugins.js「扩展管理」页 =====

export async function loadSettings() {
    try {
        const data = await request('/admin/settings');
        loadFeatureSwitches(data);
        renderSiteMode(data);
        document.getElementById('setting-site_name').value = data.site_name || '';
        document.getElementById('setting-site_url').value = data.site_url || '';
        document.getElementById('setting-site_desc').value = data.site_desc || '';
        document.getElementById('setting-site_keywords').value = data.site_keywords || '';
        document.getElementById('setting-site_description').value = data.site_description || '';
        document.getElementById('setting-site_stats_code').value = data.site_stats_code || '';
        if (document.getElementById('setting-seo_robots_txt')) {
            document.getElementById('setting-seo_robots_txt').value = data.seo_robots_txt || '';
        }
        document.getElementById('setting-site_icp').value = data.site_icp || '';
        // V176：会员定价 / 在线充值 / 支付参数已迁至「财务与会员」页（finance.js loadPayConfig 回填），
        // 本页不再读写这些字段。
        document.getElementById('setting-cron_secret').value = data.cron_secret || '';
        refreshCronTip();

        // 短信服务
        const smsProvider = data.sms_provider || 'aliyun';
        const spEl = document.getElementById('setting-sms_provider');
        if (spEl) spEl.value = smsProvider;
        switchSmsProvider(smsProvider);
        const smsFill = (k, v) => { const el = document.getElementById('setting-' + k); if (el && v && !String(v).startsWith('******')) el.value = v; };
        smsFill('sms_aliyun_access_key_id', data.sms_aliyun_access_key_id);
        smsFill('sms_aliyun_access_key_secret', data.sms_aliyun_access_key_secret);
        smsFill('sms_aliyun_sign_name', data.sms_aliyun_sign_name);
        smsFill('sms_aliyun_template_code', data.sms_aliyun_template_code);
        smsFill('sms_tencent_secret_id', data.sms_tencent_secret_id);
        smsFill('sms_tencent_secret_key', data.sms_tencent_secret_key);
        smsFill('sms_tencent_app_id', data.sms_tencent_app_id);
        smsFill('sms_tencent_sign_name', data.sms_tencent_sign_name);
        smsFill('sms_tencent_template_id', data.sms_tencent_template_id);
        smsFill('sms_tencent_region', data.sms_tencent_region);
        smsFill('sms_baidu_ak', data.sms_baidu_ak);
        smsFill('sms_baidu_sk', data.sms_baidu_sk);
        smsFill('sms_baidu_endpoint', data.sms_baidu_endpoint);
        smsFill('sms_baidu_path', data.sms_baidu_path);
        smsFill('sms_baidu_sign_id', data.sms_baidu_sign_id);
        smsFill('sms_baidu_template_id', data.sms_baidu_template_id);
        document.getElementById('setting-allow_register').checked = (data.allow_register == '1');
        document.getElementById('setting-allow_post').checked = (data.allow_post == '1');
        document.getElementById('setting-allow_like').checked = (data.allow_like == '1');
        document.getElementById('setting-allow_comment').checked = (data.allow_comment == '1');
        document.getElementById('setting-allow_guest_download_attachment').checked = (data.allow_guest_download_attachment == '1');
        document.getElementById('setting-allow_upload_image').checked = (data.allow_upload_image == '1' || data.allow_upload_image === true || data.allow_upload_image === undefined);
        document.getElementById('setting-allow_upload_video').checked = (data.allow_upload_video == '1' || data.allow_upload_video === true || data.allow_upload_video === undefined);
        document.getElementById('setting-allow_upload_attachment').checked = (data.allow_upload_attachment == '1' || data.allow_upload_attachment === true || data.allow_upload_attachment === undefined);
        document.getElementById('setting-post_max_length').value = data.post_max_length || '2000';
        document.getElementById('setting-post_collapse_length').value = data.post_collapse_length || '300';
        document.getElementById('setting-post_title_preview_length').value = data.post_title_preview_length || '150';
        document.getElementById('setting-category_required').checked = (data.category_required == '1');
        document.getElementById('setting-title_required').checked = (data.title_required == '1');
        document.getElementById('setting-allow_mention').checked = (data.allow_mention == '1' || data.allow_mention === true || data.allow_mention === undefined);
        document.getElementById('setting-mention_max_count').value = data.mention_max_count || '10';
        document.getElementById('setting-allow_location').checked = (data.allow_location == '1' || data.allow_location === true || data.allow_location === undefined);
        document.getElementById('setting-allow_circle').checked = (data.allow_circle == '1' || data.allow_circle === true || data.allow_circle === undefined);
        const amapKeyInput = document.getElementById('setting-amap_web_key');
        if (amapKeyInput) {
            const amapKey = data.amap_web_key || '';
            amapKeyInput.value = String(amapKey).startsWith('******HUBLOG_SENSITIVE_MASKED:') ? '' : amapKey;
        }
        document.getElementById('setting-allow_anonymous_post').checked = (data.allow_anonymous_post == '1' || data.allow_anonymous_post === true || data.allow_anonymous_post === undefined);
        document.getElementById('setting-url_rewrite').checked = (data.url_rewrite == '1');
        document.getElementById('setting-allow_upload_image').checked = (data.allow_upload_image == '1' || data.allow_upload_image === true || data.allow_upload_image === undefined);
        document.getElementById('setting-upload_max_images').value = data.upload_max_images || '9';
        document.getElementById('setting-upload_max_size').value = data.upload_max_size || '10';
        document.getElementById('setting-upload_compress').checked = (data.upload_compress == '1');
        document.getElementById('setting-upload_max_width').value = data.upload_max_width || '1920';
        // V43：图片水印
        document.getElementById('setting-watermark_enabled').checked = (data.watermark_enabled == '1');
        document.getElementById('setting-watermark_type').value = data.watermark_type || 'text';
        document.getElementById('setting-watermark_text').value = data.watermark_text || '';
        document.getElementById('setting-watermark_font_size').value = data.watermark_font_size || '16';
        document.getElementById('setting-watermark_color').value = data.watermark_color || '#FFFFFF';
        document.getElementById('setting-watermark_shadow').checked = (data.watermark_shadow == '1' || data.watermark_shadow === undefined);
        document.getElementById('setting-watermark_font_path').value = data.watermark_font_path || '';
        document.getElementById('setting-watermark_image').value = data.watermark_image || '';
        document.getElementById('setting-watermark_image_scale').value = data.watermark_image_scale || '20';
        document.getElementById('setting-watermark_position').value = data.watermark_position || 'bottom-right';
        document.getElementById('setting-watermark_opacity').value = data.watermark_opacity || '60';
        document.getElementById('setting-watermark_margin').value = data.watermark_margin || '16';
        document.getElementById('setting-watermark_rotate').value = data.watermark_rotate || '0';
        document.getElementById('setting-watermark_min_size').value = data.watermark_min_size || '200';
        document.getElementById('setting-watermark_apply_avatar').checked = (data.watermark_apply_avatar == '1');
        renderWatermarkImage(data.watermark_image || '');
        toggleWatermarkFields();
        document.getElementById('setting-allow_upload_video').checked = (data.allow_upload_video == '1' || data.allow_upload_video === true || data.allow_upload_video === undefined);
        document.getElementById('setting-upload_max_videos').value = data.upload_max_videos || '3';
        document.getElementById('setting-upload_max_video_size').value = data.upload_max_video_size || '50';
        document.getElementById('setting-allow_upload_attachment').checked = (data.allow_upload_attachment == '1' || data.allow_upload_attachment === true || data.allow_upload_attachment === undefined);
        document.getElementById('setting-upload_max_attachments').value = data.upload_max_attachments || '5';
        document.getElementById('setting-upload_max_attachment_size').value = data.upload_max_attachment_size || '50';
        document.getElementById('setting-upload_attachment_exts').value = data.upload_attachment_exts || 'zip,rar,7z,pdf,doc,docx,xls,xlsx,ppt,pptx,txt';
        document.getElementById('setting-upload_max_avatar_size').value = data.upload_max_avatar_size || '5';
        document.getElementById('setting-rate_limit_requests').value = data.rate_limit_requests || '300';
        document.getElementById('setting-rate_limit_window').value = data.rate_limit_window || '60';
        document.getElementById('setting-forbidden_nicknames').value = data.forbidden_nicknames || '';
        document.getElementById('setting-allowed_email_domains').value = data.allowed_email_domains || '';
        document.getElementById('setting-nav_publish_enabled').checked = (data.nav_publish_enabled == '1' || data.nav_publish_enabled === true || typeof data.nav_publish_enabled === 'undefined');
        document.getElementById('setting-nav_search_enabled').checked = (data.nav_search_enabled == '1' || data.nav_search_enabled === true);
        document.getElementById('setting-nav_messages_enabled').checked = (data.nav_messages_enabled == '1' || data.nav_messages_enabled === true || typeof data.nav_messages_enabled === 'undefined');
        document.getElementById('setting-nav_favorites_enabled').checked = (data.nav_favorites_enabled == '1' || data.nav_favorites_enabled === true);
        document.getElementById('setting-nav_profile_enabled').checked = (data.nav_profile_enabled == '1' || data.nav_profile_enabled === true);
        // 底部导航排序
        renderNavSortList(data.nav_items_order || 'home,search,messages,favorites,profile');
        // AI 配置
        document.getElementById('setting-ai_auto_install').checked = (data.ai_auto_install == '1' || data.ai_auto_install === true);
        document.getElementById('setting-ai_comment_enabled').checked = (data.ai_comment_enabled == '1' || data.ai_comment_enabled === true);
        if (data.ai_comment_provider) document.getElementById('setting-ai_comment_provider').value = data.ai_comment_provider;
        document.getElementById('setting-ai_comment_api_url').value = data.ai_comment_api_url || '';
        document.getElementById('setting-ai_comment_api_key').value = data.ai_comment_api_key || '';
        document.getElementById('setting-ai_comment_model').value = data.ai_comment_model || '';
        document.getElementById('setting-ai_comment_prompt').value = data.ai_comment_prompt || '';
        document.getElementById('setting-ai_comment_max_length').value = data.ai_comment_max_length || '200';
        document.getElementById('setting-ai_comment_timeout').value = data.ai_comment_timeout || '30';
        // 邮件设置
        document.getElementById('setting-mail_enabled').checked = (data.mail_enabled == '1' || data.mail_enabled === true);
        document.getElementById('setting-mail_host').value = data.mail_host || '';
        document.getElementById('setting-mail_port').value = data.mail_port || '465';
        document.getElementById('setting-mail_username').value = data.mail_username || '';
        document.getElementById('setting-mail_password').value = data.mail_password || '';
        document.getElementById('setting-mail_from_name').value = data.mail_from_name || '';
        document.getElementById('setting-mail_from_email').value = data.mail_from_email || '';
        if (data.mail_encryption) document.getElementById('setting-mail_encryption').value = data.mail_encryption;
        // 密码策略
        document.getElementById('setting-password_min_length').value = data.password_min_length || '6';
        document.getElementById('setting-password_require_upper').checked = (data.password_require_upper == '1' || data.password_require_upper === true);
        document.getElementById('setting-password_require_lower').checked = (data.password_require_lower == '1' || data.password_require_lower === true);
        document.getElementById('setting-password_require_digit').checked = (data.password_require_digit == '1' || data.password_require_digit === true);
        document.getElementById('setting-password_require_special').checked = (data.password_require_special == '1' || data.password_require_special === true);
        // 国际化设置
        if (data.site_language) document.getElementById('setting-site_language').value = data.site_language;
        document.getElementById('setting-allow_language_switch').checked = (data.allow_language_switch == '1' || data.allow_language_switch === true);
        // 首页封面
        if (data.homepage_cover) {
            document.getElementById('cover-current-img').src = data.homepage_cover;
            document.getElementById('cover-current-link').href = data.homepage_cover;
            document.getElementById('cover-current').style.display = 'block';
            document.getElementById('cover-remove-btn').style.display = '';
        } else {
            document.getElementById('cover-current').style.display = 'none';
            document.getElementById('cover-remove-btn').style.display = 'none';
        }
        // 云存储直传
        if (data.upload_driver) document.getElementById('setting-upload_driver').value = data.upload_driver;
        document.getElementById('setting-upload_dir_prefix').value = data.upload_dir_prefix || '';
        document.getElementById('setting-upload_sign_ttl').value = data.upload_sign_ttl || '600';
        document.getElementById('setting-upload_cdn_common_domain').value = data.upload_cdn_common_domain || '';
        document.getElementById('setting-upload_oss_access_key_id').value = data.upload_oss_access_key_id || '';
        document.getElementById('setting-upload_oss_access_key_secret').value = data.upload_oss_access_key_secret || '';
        document.getElementById('setting-upload_oss_bucket').value = data.upload_oss_bucket || '';
        document.getElementById('setting-upload_oss_endpoint').value = data.upload_oss_endpoint || '';
        document.getElementById('setting-upload_oss_cdn_domain').value = data.upload_oss_cdn_domain || '';
        document.getElementById('setting-upload_oss_ssl').checked = (data.upload_oss_ssl == '1' || data.upload_oss_ssl === true || typeof data.upload_oss_ssl === 'undefined');
        document.getElementById('setting-upload_cos_secret_id').value = data.upload_cos_secret_id || '';
        document.getElementById('setting-upload_cos_secret_key').value = data.upload_cos_secret_key || '';
        document.getElementById('setting-upload_cos_bucket').value = data.upload_cos_bucket || '';
        document.getElementById('setting-upload_cos_region').value = data.upload_cos_region || '';
        document.getElementById('setting-upload_cos_cdn_domain').value = data.upload_cos_cdn_domain || '';
        document.getElementById('setting-upload_cos_ssl').checked = (data.upload_cos_ssl == '1' || data.upload_cos_ssl === true || typeof data.upload_cos_ssl === 'undefined');
        document.getElementById('setting-upload_qiniu_access_key').value = data.upload_qiniu_access_key || '';
        document.getElementById('setting-upload_qiniu_secret_key').value = data.upload_qiniu_secret_key || '';
        document.getElementById('setting-upload_qiniu_bucket').value = data.upload_qiniu_bucket || '';
        document.getElementById('setting-upload_qiniu_cdn_domain').value = data.upload_qiniu_cdn_domain || '';
        document.getElementById('setting-upload_qiniu_region').value = data.upload_qiniu_region || '';
        document.getElementById('setting-upload_qiniu_ssl').checked = (data.upload_qiniu_ssl == '1' || data.upload_qiniu_ssl === true || typeof data.upload_qiniu_ssl === 'undefined');
        try { cloudDriverChange(); } catch(e) {}
    } catch(e) { showToast(e.message); }
}

// 底部导航拖拽排序
export const NAV_META = {
    home:      { label: '首页', icon: 'home',   path: '/' },
    search:    { label: '搜索', icon: 'search', path: '/search' },
    messages:  { label: '消息', icon: 'bell',   path: '/messages' },
    favorites: { label: '收藏', icon: 'heart',  path: '/favorites' },
    profile:   { label: '我的', icon: 'user',   path: '/u/profile' }
};
export function renderNavSortList(orderStr) {
    const order = (orderStr || 'home,search,messages,favorites,profile').split(',').map(s => s.trim()).filter(Boolean);
    const list = document.getElementById('nav-sort-list');
    if (!list) return;
    list.innerHTML = order.map(key => {
        const m = NAV_META[key] || { label: key, icon: 'dot' };
        return `<div class="nav-sort-item" draggable="true" data-key="${key}" style="padding:8px 14px;background:var(--surface-2);border:1px solid var(--border);border-radius:9px;font-size:13px;cursor:grab;user-select:none;display:flex;align-items:center;gap:9px;transition:all .15s;">
            <span style="color:var(--text-muted);font-size:14px;">⋮⋮</span>
            <span><i data-icon="${escapeHtml(String(m.icon))}"></i></span>
            <span>${escapeHtml(String(m.label))}</span>
        </div>`;
    }).join('');
    list.querySelectorAll('.nav-sort-item').forEach(item => {
        item.addEventListener('dragstart', e => {
            e.dataTransfer.setData('text/plain', item.dataset.key);
            item.style.opacity = '0.4';
        });
        item.addEventListener('dragend', () => { item.style.opacity = '1'; });
        item.addEventListener('dragover', e => { e.preventDefault(); item.style.borderColor = '#2196f3'; });
        item.addEventListener('dragleave', () => { item.style.borderColor = '#e0e4ea'; });
        item.addEventListener('drop', e => {
            e.preventDefault();
            item.style.borderColor = '#e0e4ea';
            const dragKey = e.dataTransfer.getData('text/plain');
            const dropKey = item.dataset.key;
            if (dragKey === dropKey) return;
            const arr = Array.from(list.querySelectorAll('.nav-sort-item')).map(el => el.dataset.key);
            const dragIdx = arr.indexOf(dragKey);
            const dropIdx = arr.indexOf(dropKey);
            arr.splice(dragIdx, 1);
            arr.splice(dropIdx, 0, dragKey);
            document.getElementById('setting-nav_items_order').value = arr.join(',');
            renderNavSortList(arr.join(','));
        });
    });
    document.getElementById('setting-nav_items_order').value = order.join(',');
}

/**
 * V64：收集「功能开关」页 → 逗号分隔串
 *
 * 后端对空串会回退成默认值（全开），所以这里先在本地拦一道，
 * 提示管理员「至少留一个」，免得点保存后面板全亮起来、以为开关没保存成功。
 */
export function collectFeatureSwitches() {
    const toolKeys = ['image','video','emoji','location','mention','anonymous','link','attachment','topic','draft','schedule','category'];
    const typeKeys = ['normal','poll','question','team','checkin'];
    const tools = [], types = [];
    toolKeys.forEach(k => {
        const el = document.getElementById('switch-tool-' + k);
        if (el && el.checked) tools.push(k);
    });
    typeKeys.forEach(k => {
        const el = document.getElementById('switch-type-' + k);
        if (el && el.checked) types.push(k);
    });
    if (tools.length === 0 || types.length === 0) {
        alert('工具栏和帖子类型至少要保留一个，否则用户没有任何方式发布内容。');
        return null;
    }
    return { post_tools: tools.join(','), post_types: types.join(',') };
}

export async function saveSettings() {
    try {
        const switches = collectFeatureSwitches();
        if (!switches) return;
        const body = {
            ...switches,
            site_name: document.getElementById('setting-site_name').value,
            site_url: (document.getElementById('setting-site_url')?.value || '').trim(),
            site_desc: document.getElementById('setting-site_desc').value,
            site_keywords: document.getElementById('setting-site_keywords').value,
            site_description: document.getElementById('setting-site_description').value,
            site_stats_code: document.getElementById('setting-site_stats_code').value,
            seo_robots_txt: (document.getElementById('setting-seo_robots_txt')?.value || '').trim(),
            site_icp: document.getElementById('setting-site_icp').value,
            // V176：会员定价 / 在线充值 / 支付参数已迁至「财务与会员」页，
            // 由 finance.js 的 savePayConfig 单独提交（本页元素不存在，读取会 TypeError
            // 导致整个保存失效——修复前的回归点）。
            cron_secret: (document.getElementById('setting-cron_secret')?.value || '').trim(),
            // 短信服务
            sms_provider: (document.getElementById('setting-sms_provider')?.value || 'aliyun').trim(),
            sms_aliyun_access_key_id: (document.getElementById('setting-sms_aliyun_access_key_id')?.value || '').trim(),
            sms_aliyun_access_key_secret: (document.getElementById('setting-sms_aliyun_access_key_secret')?.value || '').trim(),
            sms_aliyun_sign_name: (document.getElementById('setting-sms_aliyun_sign_name')?.value || '').trim(),
            sms_aliyun_template_code: (document.getElementById('setting-sms_aliyun_template_code')?.value || '').trim(),
            sms_tencent_secret_id: (document.getElementById('setting-sms_tencent_secret_id')?.value || '').trim(),
            sms_tencent_secret_key: (document.getElementById('setting-sms_tencent_secret_key')?.value || '').trim(),
            sms_tencent_app_id: (document.getElementById('setting-sms_tencent_app_id')?.value || '').trim(),
            sms_tencent_sign_name: (document.getElementById('setting-sms_tencent_sign_name')?.value || '').trim(),
            sms_tencent_template_id: (document.getElementById('setting-sms_tencent_template_id')?.value || '').trim(),
            sms_tencent_region: (document.getElementById('setting-sms_tencent_region')?.value || '').trim(),
            sms_baidu_ak: (document.getElementById('setting-sms_baidu_ak')?.value || '').trim(),
            sms_baidu_sk: (document.getElementById('setting-sms_baidu_sk')?.value || '').trim(),
            sms_baidu_endpoint: (document.getElementById('setting-sms_baidu_endpoint')?.value || '').trim(),
            sms_baidu_path: (document.getElementById('setting-sms_baidu_path')?.value || '').trim(),
            sms_baidu_sign_id: (document.getElementById('setting-sms_baidu_sign_id')?.value || '').trim(),
            sms_baidu_template_id: (document.getElementById('setting-sms_baidu_template_id')?.value || '').trim(),
            allow_register: document.getElementById('setting-allow_register').checked ? '1' : '0',
            allow_post: document.getElementById('setting-allow_post').checked ? '1' : '0',
            allow_like: document.getElementById('setting-allow_like').checked ? '1' : '0',
            allow_comment: document.getElementById('setting-allow_comment').checked ? '1' : '0',
            allow_guest_download_attachment: document.getElementById('setting-allow_guest_download_attachment').checked ? '1' : '0',
            post_max_length: document.getElementById('setting-post_max_length').value,
            post_collapse_length: document.getElementById('setting-post_collapse_length').value,
            post_title_preview_length: document.getElementById('setting-post_title_preview_length').value,
            category_required: document.getElementById('setting-category_required').checked ? '1' : '0',
            title_required: document.getElementById('setting-title_required').checked ? '1' : '0',
            allow_mention: document.getElementById('setting-allow_mention').checked ? '1' : '0',
            mention_max_count: document.getElementById('setting-mention_max_count').value,
            allow_location: document.getElementById('setting-allow_location').checked ? '1' : '0',
            allow_circle: document.getElementById('setting-allow_circle').checked ? '1' : '0',
            amap_web_key: (document.getElementById('setting-amap_web_key')?.value || '').trim(),
            allow_anonymous_post: document.getElementById('setting-allow_anonymous_post').checked ? '1' : '0',
            url_rewrite: document.getElementById('setting-url_rewrite').checked ? '1' : '0',
            allow_upload_image: document.getElementById('setting-allow_upload_image').checked ? '1' : '0',
            upload_max_images: document.getElementById('setting-upload_max_images').value,
            upload_max_size: document.getElementById('setting-upload_max_size').value,
            upload_compress: document.getElementById('setting-upload_compress').checked ? '1' : '0',
            upload_max_width: document.getElementById('setting-upload_max_width').value,
            // V43：图片水印
            watermark_enabled: document.getElementById('setting-watermark_enabled').checked ? '1' : '0',
            watermark_type: document.getElementById('setting-watermark_type').value,
            watermark_text: document.getElementById('setting-watermark_text').value,
            watermark_font_size: document.getElementById('setting-watermark_font_size').value,
            watermark_color: document.getElementById('setting-watermark_color').value,
            watermark_shadow: document.getElementById('setting-watermark_shadow').checked ? '1' : '0',
            watermark_font_path: document.getElementById('setting-watermark_font_path').value,
            watermark_image: document.getElementById('setting-watermark_image').value,
            watermark_image_scale: document.getElementById('setting-watermark_image_scale').value,
            watermark_position: document.getElementById('setting-watermark_position').value,
            watermark_opacity: document.getElementById('setting-watermark_opacity').value,
            watermark_margin: document.getElementById('setting-watermark_margin').value,
            watermark_rotate: document.getElementById('setting-watermark_rotate').value,
            watermark_min_size: document.getElementById('setting-watermark_min_size').value,
            watermark_apply_avatar: document.getElementById('setting-watermark_apply_avatar').checked ? '1' : '0',
            allow_upload_video: document.getElementById('setting-allow_upload_video').checked ? '1' : '0',
            upload_max_videos: document.getElementById('setting-upload_max_videos').value,
            upload_max_video_size: document.getElementById('setting-upload_max_video_size').value,
            allow_upload_attachment: document.getElementById('setting-allow_upload_attachment').checked ? '1' : '0',
            upload_max_attachments: document.getElementById('setting-upload_max_attachments').value,
            upload_max_attachment_size: document.getElementById('setting-upload_max_attachment_size').value,
            upload_attachment_exts: document.getElementById('setting-upload_attachment_exts').value,
            upload_max_avatar_size: document.getElementById('setting-upload_max_avatar_size').value,
            rate_limit_requests: document.getElementById('setting-rate_limit_requests').value,
            rate_limit_window: document.getElementById('setting-rate_limit_window').value,
            forbidden_nicknames: document.getElementById('setting-forbidden_nicknames').value,
            allowed_email_domains: document.getElementById('setting-allowed_email_domains').value,
            nav_publish_enabled: document.getElementById('setting-nav_publish_enabled').checked ? '1' : '0',
            nav_search_enabled: document.getElementById('setting-nav_search_enabled').checked ? '1' : '0',
            nav_messages_enabled: document.getElementById('setting-nav_messages_enabled').checked ? '1' : '0',
            nav_favorites_enabled: document.getElementById('setting-nav_favorites_enabled').checked ? '1' : '0',
            nav_profile_enabled: document.getElementById('setting-nav_profile_enabled').checked ? '1' : '0',
            // AI 配置
            ai_auto_install: document.getElementById('setting-ai_auto_install').checked ? '1' : '0',
            ai_comment_enabled: document.getElementById('setting-ai_comment_enabled').checked ? '1' : '0',
            ai_comment_provider: document.getElementById('setting-ai_comment_provider').value,
            ai_comment_api_url: document.getElementById('setting-ai_comment_api_url').value,
            ai_comment_api_key: document.getElementById('setting-ai_comment_api_key').value,
            ai_comment_model: document.getElementById('setting-ai_comment_model').value,
            ai_comment_prompt: document.getElementById('setting-ai_comment_prompt').value,
            ai_comment_max_length: document.getElementById('setting-ai_comment_max_length').value,
            ai_comment_timeout: document.getElementById('setting-ai_comment_timeout').value,
            // 邮件设置
            mail_enabled: document.getElementById('setting-mail_enabled').checked ? '1' : '0',
            mail_host: document.getElementById('setting-mail_host').value,
            mail_port: document.getElementById('setting-mail_port').value,
            mail_username: document.getElementById('setting-mail_username').value,
            mail_password: document.getElementById('setting-mail_password').value,
            mail_from_name: document.getElementById('setting-mail_from_name').value,
            mail_from_email: document.getElementById('setting-mail_from_email').value,
            mail_encryption: document.getElementById('setting-mail_encryption').value,
            // 密码策略
            password_min_length: document.getElementById('setting-password_min_length').value,
            password_require_upper: document.getElementById('setting-password_require_upper').checked ? '1' : '0',
            password_require_lower: document.getElementById('setting-password_require_lower').checked ? '1' : '0',
            password_require_digit: document.getElementById('setting-password_require_digit').checked ? '1' : '0',
            password_require_special: document.getElementById('setting-password_require_special').checked ? '1' : '0',
            // 国际化设置
            site_language: document.getElementById('setting-site_language').value,
            allow_language_switch: document.getElementById('setting-allow_language_switch').checked ? '1' : '0',
            // 云存储直传
            upload_driver: document.getElementById('setting-upload_driver').value,
            upload_dir_prefix: document.getElementById('setting-upload_dir_prefix').value,
            upload_sign_ttl: document.getElementById('setting-upload_sign_ttl').value,
            upload_cdn_common_domain: document.getElementById('setting-upload_cdn_common_domain').value,
            upload_oss_access_key_id: document.getElementById('setting-upload_oss_access_key_id').value,
            upload_oss_access_key_secret: document.getElementById('setting-upload_oss_access_key_secret').value,
            upload_oss_bucket: document.getElementById('setting-upload_oss_bucket').value,
            upload_oss_endpoint: document.getElementById('setting-upload_oss_endpoint').value,
            upload_oss_cdn_domain: document.getElementById('setting-upload_oss_cdn_domain').value,
            upload_oss_ssl: document.getElementById('setting-upload_oss_ssl').checked ? '1' : '0',
            upload_cos_secret_id: document.getElementById('setting-upload_cos_secret_id').value,
            upload_cos_secret_key: document.getElementById('setting-upload_cos_secret_key').value,
            upload_cos_bucket: document.getElementById('setting-upload_cos_bucket').value,
            upload_cos_region: document.getElementById('setting-upload_cos_region').value,
            upload_cos_cdn_domain: document.getElementById('setting-upload_cos_cdn_domain').value,
            upload_cos_ssl: document.getElementById('setting-upload_cos_ssl').checked ? '1' : '0',
            upload_qiniu_access_key: document.getElementById('setting-upload_qiniu_access_key').value,
            upload_qiniu_secret_key: document.getElementById('setting-upload_qiniu_secret_key').value,
            upload_qiniu_bucket: document.getElementById('setting-upload_qiniu_bucket').value,
            upload_qiniu_cdn_domain: document.getElementById('setting-upload_qiniu_cdn_domain').value,
            upload_qiniu_region: document.getElementById('setting-upload_qiniu_region').value,
            upload_qiniu_ssl: document.getElementById('setting-upload_qiniu_ssl').checked ? '1' : '0'
        };
        // 掩码占位串绝不回写：后端也做同样跳过，双保险避免清空真实密钥
        if (String(body.amap_web_key || '').startsWith('******HUBLOG_SENSITIVE_MASKED:')) delete body.amap_web_key;
        await request('/admin/settings', { method:'PUT', body });
        showToast('保存成功');
    } catch(e) { showToast(e.message); }
}

export function handleCoverUpload(event) {
    const file = event.target.files[0];
    // 拖拽事件传入的是合成对象 { target:{ files } }，没有 .value；用可选链兜底
    const input = (event.target && 'value' in event.target) ? event.target : null;
    if (!file) return;
    if (!file.type.startsWith('image/')) {
        showToast('请选择图片文件');
        if (input) input.value = '';
        return;
    }
    if (file.size > 10 * 1024 * 1024) {
        showToast('图片大小不能超过 10MB');
        if (input) input.value = '';
        return;
    }
    // 选择文件即上传，避免再出"保存封面"按钮与全局"保存设置"按钮撞名。
    uploadCoverNow(file, input);
}

export async function uploadCoverNow(file, input) {
    const status = document.getElementById('cover-upload-status');
    if (status) {
        status.style.display = 'block';
        status.textContent = '上传中...';
    }
    try {
        const formData = new FormData();
        formData.append('cover', file);
        const data = await request('/admin/upload-homepage-cover', { method: 'POST', body: formData, isFormData: true });
        showToast('封面上传成功');
        document.getElementById('cover-current-img').src = data.url;
        document.getElementById('cover-current-link').href = data.url;
        document.getElementById('cover-current').style.display = 'block';
        document.getElementById('cover-remove-btn').style.display = '';
        if (status) status.textContent = '已更新于 ' + new Date().toLocaleTimeString();
    } catch (e) {
        showToast(e.message || '封面上传失败');
        if (status) status.textContent = '' + (e.message || '上传失败');
    } finally {
        if (input) input.value = '';
    }
}

export async function removeHomepageCover() {
    if (!confirm('确定要移除首页封面吗？')) return;
    try {
        await request('/admin/settings', { method: 'PUT', body: { homepage_cover: '' } });
        showToast('封面已移除');
        document.getElementById('cover-current').style.display = 'none';
        document.getElementById('cover-remove-btn').style.display = 'none';
        const status = document.getElementById('cover-upload-status');
        if (status) { status.style.display = 'none'; status.textContent = ''; }
    } catch(e) { showToast(e.message); }
}