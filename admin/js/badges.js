// ============================================================
// badges.js — 徽章管理：图标字体、授予、冻结、台账
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { escapeHtml, registerActions, renderPagination, request, showToast } from './core.js';
import { loadGodMarks } from './posts.js';
import { BADGE_ART, BADGE_ART_BY_NAME, badgeArtSrc } from './badge-art.js';



/* ==================================================================
   V83 徽章管理
   说明：统计类徽章（如「原创作者」「封神评论官」）日常由外部 cron 每小时
   跑 /api/cron/badges 自动解锁；「立即扫描解锁」是后台手动补救入口。
   ================================================================== */

/* ------------------------------------------------------------------
   徽章图标素材（四种接入方式，优先级从高到低）
   ⓪ badge-art.js        ：徽章专属成品素材（badge_key / 中文名匹配，与 App 端
                            app-client/src/utils/badge-art.js 逐字节同步），
                            多色自带配色、不参与 color 染色 —— 与 App 同逻辑
   ① BADGE_ICONFONT_*    ：阿里矢量库（iconfont）导出的 symbol 代码，
                            整段粘进 BADGE_ICONFONT_SYMBOL 即可，图标名用
                            BADGE_ICONFONT_PREFIX + badges.icon 匹配
                            （只有 symbol 里真有这个 id 才生效，否则继续往下找）
   ② BADGE_ICON_SVG      ：内置 SVG（key = badges.icon），与 App 端 Icon.vue 同源
   ③ 都没配时自动降级    ：彩色圆底 + 徽章名首字（占位符）
   ------------------------------------------------------------------ */
/**
 * 与 App 端 app-client/src/components/Icon.vue 同源（改图标请两边一起改）。
 * · 字符串值：24×24 线性图标的内部片段，渲染时统一 stroke 描边（颜色跟随徽章主色）
 * · 对象值  ：{ vb, d } 实心图标 —— 实名认证 / VIP 是用户下载的真实素材
 *              （原件 src/static/icons/realname.svg、VIP.svg），viewBox 0 0 1024 1024，按 fill 填充
 */
export const BADGE_ICON_SVG = {
    /* —— 认证 / 身份（实心素材，1024 网格）—— */
    realname: { vb: '0 0 1024 1024', d: 'M291.008 408C314.368 432.64 347.328 448 384 448c36.608 0 69.632-15.36 92.992-40A192 192 0 0 1 576 576H512a128 128 0 1 0-256 0H192a192 192 0 0 1 99.008-168 128 128 0 1 1 185.984 0A192 192 0 0 1 576 576H512a128 128 0 1 0-256 0H192a192 192 0 0 1 99.008-168zM448 672c0 10.816 0.64 21.504 1.728 32H128a64 64 0 0 1-64-64V128a64 64 0 0 1 64-64h768a64 64 0 0 1 64 64v426.944a289.6 289.6 0 0 0-64-58.432V128H128v512h321.728a291.2 291.2 0 0 0-1.728 32zM384 384a64 64 0 1 0 0-128 64 64 0 0 0 0 128z m288-128h128a32 32 0 1 1 0 64h-128a32 32 0 1 1 0-64z m0 128h128a32 32 0 1 1 0 64h-128a32 32 0 1 1 0-64z m64 576a224 224 0 1 1 0-448 224 224 0 0 1 0 448z m105.344-310.656l-137.472 137.536-73.344-72.576-45.056 45.504 118.656 117.312 182.528-182.464-45.312-45.312z' },
    vip:      { vb: '0 0 1024 1024', d: 'M605.693 867.837h-63.715v-304.621h63.715v304.621M881.428 719.652c-16.991 25.486-43.449 38.108-79.493 38.108h-73.060v110.077h-63.715v-304.621h139.325c29.491 0 52.671 8.738 69.42 26.093 16.869 17.355 25.244 41.142 25.244 71.483 0.122 21.36-5.825 41.021-17.718 58.861M186.383 867.837v-648.685c0-2.67-2.185-4.854-4.854-4.854h-49.637c-2.67 0-4.854-2.185-4.854-4.854v-49.759c0-2.67 2.185-4.854 4.854-4.854h227.92c2.67 0 4.854 2.185 4.854 4.854v292.242l297.703-295.641c0.85-0.85 2.185-1.456 3.398-1.456h221.972c4.369 0 6.432 5.219 3.398 8.253l-704.754 704.754M822.446 625.596c-8.738-6.432-23.059-9.709-42.962-9.709h-50.608v89.202h59.104c20.389 0 34.224-6.19 41.628-18.569 4.005-6.796 5.947-16.384 5.947-28.763 0-14.928-4.369-25.607-13.107-32.161M822.446 625.596z' },

    /* —— 认证 / 身份（线性）—— */
    phone:       '<rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/>',
    mail:        '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
    'user-check': '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/>',
    shield:      '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'check-circle': '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    check:       '<polyline points="20 6 9 17 4 12"/>',
    user:        '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    key:         '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',

    /* —— VIP —— */
    crown:       '<path d="M2 4l3 12h14l3-12-6 7-4-7-4 7-6-7z"/><path d="M5 20h14"/>',
    award:       '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',

    /* —— 圈子 / 社交 —— */
    users:       '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    home:        '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
    heart:       '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
    gift:        '<polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/>',
    hash:        '<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>',
    tag:         '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',

    /* —— 创作 —— */
    pen:         '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>',
    image:       '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
    video:       '<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
    pin:         '<path d="M12 17v5"/><path d="M9 10.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z"/>',
    bookmark:    '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',

    /* —— 评论 —— */
    comment:     '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
    message:     '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    reply:       '<polyline points="9 14 4 9 9 4"/><path d="M20 20v-7a4 4 0 0 0-4-4H4"/>',

    /* —— 活跃 / 活动 —— */
    fire:        '<path d="M12 2c0 0-5 5.5-5 10a5 5 0 0 0 10 0c0-1.5-.5-3-1.5-4.5 0 2-1 3-2 3 0-3.5 2.5-6-1.5-8.5z"/><path d="M12 22a6 6 0 0 0 6-6c0-2-1-3.5-2-5"/>',
    star:        '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    clock:       '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    calendar:    '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    trophy:      '<path d="M7 4h10v4a5 5 0 0 1-10 0V4z"/><path d="M7 5H5a2 2 0 0 0 2 4"/><path d="M17 5h2a2 2 0 0 1-2 4"/><line x1="12" y1="13" x2="12" y2="16"/><path d="M8 16h8l1.5 5h-11L8 16z"/>',
    flag:        '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/>',
    sofa:        '<path d="M4 12V10a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2"/><rect x="2" y="12" width="20" height="6" rx="2"/><line x1="6" y1="18" x2="6" y2="20"/><line x1="18" y1="18" x2="18" y2="20"/>',
    eye:         '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    search:      '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    wrench:      '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>'
};
export const BADGE_ICONFONT_SYMBOL = '';          // 例：'<svg style="display:none"><symbol id="icon-vip" viewBox="0 0 1024 1024">…</symbol></svg>'
export const BADGE_ICONFONT_PREFIX = 'icon-';

/** 把 iconfont 的 symbol 定义一次性注入 DOM（只注入一次，幂等） */
export function ensureBadgeIconFont() {
    if (!BADGE_ICONFONT_SYMBOL || document.getElementById('bd-iconfont-defs')) return;
    const box = document.createElement('div');
    box.id = 'bd-iconfont-defs';
    box.style.display = 'none';
    box.innerHTML = BADGE_ICONFONT_SYMBOL;
    document.body.appendChild(box);
}

/** 外部素材里是否真的有这个图标（有才用，避免 <use> 指向不存在的 id 渲染成空白） */
export function badgeIconFontHas(key) {
    if (!BADGE_ICONFONT_SYMBOL || !key) return false;
    ensureBadgeIconFont();
    const box = document.getElementById('bd-iconfont-defs');
    if (!box) return false;
    const id = BADGE_ICONFONT_PREFIX + key;
    const sel = '#' + (window.CSS && CSS.escape ? CSS.escape(id) : id);
    try { return !!box.querySelector(sel); } catch (e) { return false; }
}

/** 取占位符用的首字：中文取首字，英文/标识取首字母大写 */
export function badgeInitial(name, iconKey) {
    const n = String(name || '').trim();
    if (n) {
        const first = n[0];
        return /[\u4e00-\u9fa5]/.test(first) ? first : first.toUpperCase();
    }
    const k = String(iconKey || '').trim();
    return k ? k[0].toUpperCase() : '徽';
}

/**
 * 渲染徽章图标。
 * 优先级：专属素材（badge-art.js）> iconfont > 内置 SVG > 首字占位。
 * @param {string} icon      badges.icon（图标名）
 * @param {string} name      徽章名称（占位符取首字用；也用于中文名命中素材）
 * @param {string} color     徽章主色（#rrggbb）
 * @param {number} size      图标边长 px
 * @param {string} badgeKey  badges.badge_key（专属素材优先按它匹配，选填）
 */
export function badgeIconHtml(icon, name, color, size = 22, badgeKey = '') {
    const c = /^#[0-9a-fA-F]{6}$/.test(String(color || '')) ? String(color) : '#3b8cfa';
    const key = String(icon || '').trim();
    const def = BADGE_ICON_SVG[key];
    // 专属素材匹配（与 App 端 badgeArtSrc 同逻辑）：badge_key 优先，中文名兜底（后台改过 key 的库也能命中）
    const artKey = String(badgeKey || '').trim() || BADGE_ART_BY_NAME[String(name || '').trim()] || '';
    const art = BADGE_ART[artKey] ? badgeArtSrc({ badge_key: artKey }) : '';
    let inner;
    if (art) {
        // 专属成品素材：多色自带配色、不染色；方形画布放大 1.15 倍对齐线性图标视觉尺寸（与 App 一致）
        const as = Math.round(size * 1.15);
        inner = `<img src="${art}" alt="" title="专属素材：${escapeHtml(artKey)}" style="width:${as}px;height:${as}px;object-fit:contain;display:block;">`;
    } else if (badgeIconFontHas(key)) {
        // 外部导入的素材（阿里矢量库）优先：贴了 symbol 就用新的，不用改内置 SVG
        inner = `<svg width="${size}" height="${size}" fill="${c}" aria-hidden="true"><use xlink:href="#${escapeHtml(BADGE_ICONFONT_PREFIX)}${escapeHtml(key)}"></use></svg>`;
    } else if (typeof def === 'object' && def && def.d) {
        // 实心素材（实名认证 / VIP 等下载的图标）：viewBox 由素材自身决定，按 fill 填充
        inner = `<svg viewBox="${def.vb || '0 0 1024 1024'}" width="${size}" height="${size}" fill="${c}" aria-hidden="true"><path d="${def.d}"/></svg>`;
    } else if (typeof def === 'string' && def) {
        inner = `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="${c}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${def}</svg>`;
    } else {
        // 占位符：彩色圆底 + 首字
        inner = `<span style="font-size:${Math.max(11, Math.round(size * 0.56))}px;font-weight:600;color:${c};line-height:1;">${escapeHtml(badgeInitial(name, key))}</span>`;
    }
    const box = size + 12;
    return `<span title="${escapeHtml(key || '未设置图标（占位符）')}" style="display:inline-flex;align-items:center;justify-content:center;width:${box}px;height:${box}px;border-radius:50%;background:${c}1f;vertical-align:middle;">${inner}</span>`;
}

/** 把已定义好的图标名填进编辑弹窗的 datalist，选图标时不用手敲 */
export function initBadgeIconNames() {
    const dl = document.getElementById('bd-icon-names');
    if (!dl || dl.dataset.filled) return;
    dl.innerHTML = Object.keys(BADGE_ICON_SVG)
        .map(k => `<option value="${escapeHtml(k)}"></option>`).join('');
    dl.dataset.filled = '1';
}

/** 编辑弹窗里的图标实时预览 */
export function refreshBadgeIconPreview() {
    const el = document.getElementById('bd-e-icon-preview');
    if (!el) return;
    const icon  = document.getElementById('bd-e-icon')?.value || '';
    const color = document.getElementById('bd-e-color')?.value || '#3b8cfa';
    const name  = document.getElementById('bd-e-name')?.value || '';
    const bkey  = document.getElementById('bd-e-key')?.value || '';
    const artHit = !!badgeArtSrc({ badge_key: bkey, name });
    el.innerHTML = badgeIconHtml(icon, name, color, 28, bkey)
        + `<span style="margin-left:8px;font-size:12px;color:var(--text-muted);">${artHit ? '命中专属素材（badge-art.js）' : (escapeHtml(String(icon).trim()) || '未设置 → 显示名称首字占位')}</span>`;
}

export let badgeCurrentTab = 'config';

export function switchBadgeTab(tab) {
    badgeCurrentTab = tab;
    const map = { config: 'bd-tab-config', user: 'bd-tab-user', god: 'bd-tab-god', log: 'bd-tab-log' };
    Object.values(map).forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.className = 'btn btn-default'; }
    });
    const active = document.getElementById(map[tab]);
    if (active) active.className = 'btn btn-primary';
    document.getElementById('bd-panel-config').style.display = tab === 'config' ? '' : 'none';
    document.getElementById('bd-panel-user').style.display   = tab === 'user'   ? '' : 'none';
    document.getElementById('bd-panel-god').style.display    = tab === 'god'    ? '' : 'none';
    document.getElementById('bd-panel-log').style.display    = tab === 'log'    ? '' : 'none';
    if (tab === 'config') loadBadges();
    if (tab === 'user')   loadUserBadges();
    if (tab === 'god')    loadGodMarks();
    if (tab === 'log')    loadBadgeLogs();
}

// B2 委托化：徽章 Tab 切换（data-action="switch-badge-tab" data-value="config|user|god|log"）
registerActions({
    'switch-badge-tab': (d) => switchBadgeTab(d.value),
});

// B3 委托化：徽章管理动作注册（模板串 onclick → data-action）
registerActions({
    'open-badge-editor': (d) => openBadgeEditorById(+d.id),
    'toggle-badge': (d) => toggleBadge(+d.id, +d.value),
    'grant-badge': (d) => grantBadge(+d.id, +d.badgeId),
    'freeze-badge': (d) => freezeBadge(+d.id, +d.badgeId),
    'revoke-badge': (d) => revokeBadge(+d.id, +d.badgeId),
    // B4b 委托化：静态扫描 / 新增（data-id="0" 走 openBadgeEditorById → 新增分支）/ 搜索 / 保存
    'run-badge-scan': () => runBadgeScan(),
    'load-badges': () => loadBadges(),
    'load-user-badges': () => loadUserBadges(),
    'save-badge': () => saveBadge(),
    // B5 委托化：徽章编辑器取消按钮（原 style.display 直调）
    'hide-bd-editor': () => { document.getElementById('bd-editor').style.display = 'none'; },
    // B6 委托化：徽章名/图标/颜色实时预览（原 oninput 直调）
    'refresh-badge-icon-preview': () => refreshBadgeIconPreview(),
});

export async function loadBadges(page = 1) {
    try {
        const category = document.getElementById('bd-category')?.value || '';
        const status   = document.getElementById('bd-status')?.value ?? '-1';
        const keyword  = document.getElementById('bd-keyword')?.value || '';
        const data = await request(
            `/admin/badges?page=${page}&limit=100&category=${encodeURIComponent(category)}` +
            `&status=${encodeURIComponent(status)}&keyword=${encodeURIComponent(keyword)}`
        );
        const rows = data.list || [];
        // 缓存当前页数据：编辑弹窗按 id 回查，避免把整个对象塞进 HTML 属性（含引号会破坏结构）
        window.__badgeRows = rows;
        document.getElementById('bd-table').innerHTML = rows.map(b => {
            const ruleMap = { count: '统计类', realtime: '实时类', timed: '限时类', manual: '人工授予' };
            return `<tr>
                <td data-label="图标">${badgeIconHtml(b.icon, b.name, b.color, 22, b.badge_key)}</td>
                <td data-label="名称" data-full>${escapeHtml(b.name)}</td>
                <td data-label="标识" style="font-family:monospace;font-size:12px;">${escapeHtml(b.badge_key)}</td>
                <td data-label="分类">${escapeHtml(b.category || '')}</td>
                <td data-label="规则">${ruleMap[b.rule_type] || b.rule_type}</td>
                <td data-label="权重">${b.sort_weight ?? 0}</td>
                <td data-label="展示位">${Number(b.display) === 1
                    ? '<span class="badge badge-success">名字旁内联</span>'
                    : '<span class="badge badge-default">主页横排</span>'}</td>
                <td data-label="状态">${Number(b.status) === 1
                    ? '<span class="badge badge-success">启用</span>'
                    : '<span class="badge badge-danger">禁用</span>'}</td>
                <td data-label="解锁/冻结">${b.unlock_count ?? 0} / ${b.freeze_count ?? 0}</td>
                <td data-label="操作" data-actions>
                    <button class="btn btn-default" data-action="open-badge-editor" data-id="${b.id}">编辑</button>
                    <button class="btn btn-default" data-action="toggle-badge" data-id="${b.id}" data-value="${Number(b.status) === 1 ? 0 : 1}">${Number(b.status) === 1 ? '禁用' : '启用'}</button>
                </td></tr>`;
        }).join('') || '<tr><td colspan="10" style="text-align:center;padding:40px;">暂无徽章</td></tr>';
        renderPagination('bd-pagination', data.pagination, loadBadges);
    } catch (e) { showToast(e.message); }
}

export function openBadgeEditorById(id) {
    const badge = (window.__badgeRows || []).find(x => Number(x.id) === Number(id));
    openBadgeEditor(badge || null);
}

export function openBadgeEditor(badge) {
    const isEdit = badge && badge.id;
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
    document.getElementById('bd-editor-title').textContent = isEdit ? '编辑徽章' : '新增徽章';
    set('bd-e-id', isEdit ? badge.id : 0);
    set('bd-e-name', isEdit ? badge.name : '');
    set('bd-e-key', isEdit ? badge.badge_key : '');
    set('bd-e-icon', isEdit ? badge.icon : 'award');
    set('bd-e-color', isEdit ? badge.color : '#3b8cfa');
    set('bd-e-category', isEdit ? badge.category : 'creation');
    set('bd-e-rule', isEdit ? badge.rule_type : 'count');
    set('bd-e-threshold', isEdit && badge.threshold ? (typeof badge.threshold === 'string' ? badge.threshold : JSON.stringify(badge.threshold)) : '');
    set('bd-e-desc', isEdit ? badge.desc : '');
    set('bd-e-weight', isEdit ? (badge.sort_weight ?? 0) : 0);
    set('bd-e-display', String(isEdit ? (Number(badge.display) === 1 ? 1 : 0) : 0));
    document.getElementById('bd-e-hideable').checked = isEdit ? Number(badge.hideable) === 1 : true;
    document.getElementById('bd-e-status').checked   = isEdit ? Number(badge.status) === 1  : true;
    document.getElementById('bd-editor').style.display = '';
    refreshBadgeIconPreview();
}

export async function saveBadge() {
    const id = Number(document.getElementById('bd-e-id').value || 0);
    const payload = {
        id,
        name:        document.getElementById('bd-e-name').value.trim(),
        badge_key:   document.getElementById('bd-e-key').value.trim(),
        icon:        document.getElementById('bd-e-icon').value.trim() || 'award',
        color:       document.getElementById('bd-e-color').value || '#3b8cfa',
        category:    document.getElementById('bd-e-category').value,
        rule_type:   document.getElementById('bd-e-rule').value,
        threshold:   document.getElementById('bd-e-threshold').value.trim(),
        desc:        document.getElementById('bd-e-desc').value.trim(),
        sort_weight: Number(document.getElementById('bd-e-weight').value || 0),
        display:     Number(document.getElementById('bd-e-display').value || 0),
        hideable:    document.getElementById('bd-e-hideable').checked,
        status:      document.getElementById('bd-e-status').checked ? 1 : 0,
    };
    if (!payload.name) { showToast('请填写徽章名称'); return; }
    try {
        await request('/admin/badge-save', { method: id > 0 ? 'PUT' : 'POST', body: payload });
        showToast('保存成功');
        document.getElementById('bd-editor').style.display = 'none';
        await loadBadges();
    } catch (e) { showToast(e.message); }
}

export async function toggleBadge(id, status) {
    try {
        await request('/admin/badge-toggle', { method: 'POST', body: { id, status } });
        showToast(status ? '已启用' : '已禁用');
        await loadBadges();
    } catch (e) { showToast(e.message); }
}

/** 手动触发一次全站扫描：立刻按当前数据重算所有用户的统计/限时徽章 */
export async function runBadgeScan() {
    if (!confirm('将立即全站重算徽章解锁状态（用户较多时需要一点时间），确定执行？')) return;
    const btn = event && event.target;
    const oldText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '扫描中...'; }
    try {
        const d = await request('/admin/badge-scan', { method: 'POST', timeout: 300000 }); // 全量扫描较慢，放宽到 5 分钟
        const unlocked = d && d.unlocked != null ? d.unlocked : '';
        showToast('扫描完成' + (unlocked !== '' ? `，本次解锁 ${unlocked} 人次` : ''), 4000);
        await loadBadges();
    } catch (e) { showToast(e.message); }
    finally { if (btn) { btn.disabled = false; btn.textContent = oldText; } }
}

export async function loadUserBadges(page = 1) {
    try {
        const userId = document.getElementById('bd-user-id')?.value || '';
        const state  = document.getElementById('bd-user-state')?.value ?? '-1';
        const data = await request(`/admin/user-badges?page=${page}&limit=20&user_id=${encodeURIComponent(userId)}&state=${encodeURIComponent(state)}`);
        const rows = data.list || [];
        const stateMap = { 1: ['badge-success', '已解锁'], 2: ['badge-warning', '已冻结'], 3: ['badge-danger', '已回收'] };
        document.getElementById('bd-user-table').innerHTML = rows.map(r => {
            const s = stateMap[Number(r.state)] || ['badge-default', r.state];
            return `<tr>
                <td data-label="用户">${escapeHtml(r.nickname || r.username || ('#' + r.user_id))}</td>
                <td data-label="徽章">${badgeIconHtml(r.icon, r.name || r.badge_key, r.color, 20, r.badge_key)} ${escapeHtml(r.name || r.badge_key || '')}</td>
                <td data-label="状态"><span class="badge ${s[0]}">${s[1]}</span></td>
                <td data-label="来源">${r.grant_type === 'manual' ? '人工授予' : '自动解锁'}</td>
                <td data-label="解锁时间">${r.unlock_time || '-'}</td>
                <td data-label="操作" data-actions>
                    ${Number(r.state) !== 1 ? `<button class="btn btn-default" data-action="grant-badge" data-id="${r.user_id}" data-badge-id="${r.badge_id}">授予</button>` : ''}
                    ${Number(r.state) === 1 ? `<button class="btn btn-default" data-action="freeze-badge" data-id="${r.user_id}" data-badge-id="${r.badge_id}">冻结</button>` : ''}
                    <button class="btn btn-danger" data-action="revoke-badge" data-id="${r.user_id}" data-badge-id="${r.badge_id}">回收</button>
                </td></tr>`;
        }).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;">暂无数据（可填用户ID查询，留空查全部）</td></tr>';
        renderPagination('bd-user-pagination', data.pagination, loadUserBadges);
    } catch (e) { showToast(e.message); }
}

export async function grantBadge(userId, badgeId) {
    const remark = prompt('授予备注（可留空）') || '';
    try { await request('/admin/user-badge-grant', { method: 'POST', body: { user_id: userId, badge_id: badgeId, remark } }); showToast('已授予'); await loadUserBadges(); } catch (e) { showToast(e.message); }
}
export async function freezeBadge(userId, badgeId) {
    const reason = prompt('冻结原因（可留空）') || '';
    try { await request('/admin/user-badge-freeze', { method: 'POST', body: { user_id: userId, badge_id: badgeId, reason } }); showToast('已冻结'); await loadUserBadges(); } catch (e) { showToast(e.message); }
}
export async function revokeBadge(userId, badgeId) {
    if (!confirm('回收后该徽章将从用户身上移除，确定？')) return;
    const reason = prompt('回收原因（可留空）') || '';
    try { await request('/admin/user-badge-revoke', { method: 'POST', body: { user_id: userId, badge_id: badgeId, reason } }); showToast('已回收'); await loadUserBadges(); } catch (e) { showToast(e.message); }
}

export async function loadBadgeLogs(page = 1) {
    try {
        const data = await request(`/admin/badge-logs?page=${page}&limit=20`);
        const rows = data.list || [];
        const actionMap = {
            grant: '解锁/授予', freeze: '冻结', revoke: '回收', expire: '到期',
            config_update: '编辑配置', config_create: '新增配置', config_toggle: '启停配置',
        };
        document.getElementById('bd-log-table').innerHTML = rows.map(l => `<tr>
            <td data-label="时间">${l.created_at}</td>
            <td data-label="操作人">${escapeHtml(l.operator_name || (l.operator_id ? ('#' + l.operator_id) : '系统'))}</td>
            <td data-label="用户">${escapeHtml(l.user_name || (l.user_id ? ('#' + l.user_id) : '-'))}</td>
            <td data-label="徽章">${escapeHtml(l.badge_name || l.badge_key || '-')}</td>
            <td data-label="动作">${actionMap[l.action] || escapeHtml(l.action || '')}</td>
            <td data-label="备注" data-full>${escapeHtml(l.remark || '')}</td>
        </tr>`).join('') || '<tr><td colspan="6" style="text-align:center;padding:40px;">暂无日志</td></tr>';
        renderPagination('bd-log-pagination', data.pagination, loadBadgeLogs);
    } catch (e) { showToast(e.message); }
}