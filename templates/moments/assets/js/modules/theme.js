/**
 * woquan 夜间模式
 * 通过 CSS 变量切换主题，localStorage 持久化
 */

const THEME_KEY = 'hublog_theme';
const CUSTOM_COLOR_KEY = 'hublog_custom_color';
const DARK_THEME = 'dark';
const LIGHT_THEME = 'light';

const DARK_VARS = {
    '--primary': '#3cb672',
    '--primary-dark': '#2da061',
    '--bg': '#1a1a1a',
    '--card-bg': '#2d2d2d',
    '--text': '#e0e0e0',
    '--text-secondary': '#888',
    '--border': '#3a3a3a',
    '--link': '#7a9bc7',
    '--danger': '#e54545',
    '--shadow': '0 1px 3px rgba(0,0,0,0.3)'
};

const LIGHT_VARS = {
    '--primary': '#07c160',
    '--primary-dark': '#06ad56',
    '--bg': '#ededed',
    '--card-bg': '#fff',
    '--text': '#333',
    '--text-secondary': '#999',
    '--border': '#e5e5e5',
    '--link': '#576b95',
    '--danger': '#fa5151',
    '--shadow': '0 1px 3px rgba(0,0,0,0.1)'
};

/**
 * 将十六进制颜色变亮（按百分比混合白色）
 */
function lightenHex(hex, percent) {
    const num = parseInt(hex.replace('#', ''), 16);
    const amt = Math.round(255 * percent / 100);
    const r = Math.min(255, ((num >> 16) & 0xff) + amt);
    const g = Math.min(255, ((num >> 8) & 0xff) + amt);
    const b = Math.min(255, (num & 0xff) + amt);
    return '#' + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
}

/**
 * 将十六进制颜色变暗（按百分比混合黑色）
 */
function darkenHex(hex, percent) {
    const num = parseInt(hex.replace('#', ''), 16);
    const amt = Math.round(255 * percent / 100);
    const r = Math.max(0, ((num >> 16) & 0xff) - amt);
    const g = Math.max(0, ((num >> 8) & 0xff) - amt);
    const b = Math.max(0, (num & 0xff) - amt);
    return '#' + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
}

/**
 * 应用自定义主题色
 * 设置 --primary 及衍生变量（--primary-light / --primary-dark 等）
 */
export function applyCustomThemeColor(color) {
    if (!color || !/^#[0-9a-fA-F]{6}$/.test(color)) return;
    const root = document.documentElement;
    root.style.setProperty('--primary', color);
    root.style.setProperty('--primary-light', lightenHex(color, 20));
    root.style.setProperty('--primary-dark', darkenHex(color, 20));
}

/**
 * 保存并应用自定义主题色
 */
export function setCustomThemeColor(color) {
    if (!color || !/^#[0-9a-fA-F]{6}$/.test(color)) return;
    localStorage.setItem(CUSTOM_COLOR_KEY, color);
    applyCustomThemeColor(color);
}

/**
 * 重置主题色为默认值
 */
export function resetThemeColor() {
    localStorage.removeItem(CUSTOM_COLOR_KEY);
    const root = document.documentElement;
    root.style.removeProperty('--primary');
    root.style.removeProperty('--primary-light');
    root.style.removeProperty('--primary-dark');
    // 重新应用当前主题的默认颜色
    const saved = localStorage.getItem(THEME_KEY) || LIGHT_THEME;
    applyTheme(saved);
}

export function initTheme() {
    const saved = localStorage.getItem(THEME_KEY) || LIGHT_THEME;
    applyTheme(saved);

    // 应用自定义主题色（如果已保存）
    const customColor = localStorage.getItem(CUSTOM_COLOR_KEY);
    if (customColor) {
        applyCustomThemeColor(customColor);
    }

    // 页面加载后插入切换按钮
    document.addEventListener('DOMContentLoaded', () => {
        const btn = document.getElementById('btn-theme');
        if (btn) {
            updateThemeButton(btn, saved);
            btn.addEventListener('click', toggleTheme);
        } else {
            // 若模板无预设按钮，在顶部导航栏追加
            const actions = document.querySelector('.header-navbar .actions');
            if (actions) {
                const themeBtn = document.createElement('button');
                themeBtn.className = 'btn-icon';
                themeBtn.id = 'btn-theme';
                themeBtn.title = '切换主题';
                themeBtn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>`;
                actions.appendChild(themeBtn);
                updateThemeButton(themeBtn, saved);
                themeBtn.addEventListener('click', toggleTheme);
            }
        }
    });
}

export function toggleTheme() {
    const current = localStorage.getItem(THEME_KEY) || LIGHT_THEME;
    const next = current === LIGHT_THEME ? DARK_THEME : LIGHT_THEME;
    applyTheme(next);
    localStorage.setItem(THEME_KEY, next);
    const btn = document.getElementById('btn-theme');
    if (btn) updateThemeButton(btn, next);
}

function applyTheme(theme) {
    const root = document.documentElement;
    const vars = theme === DARK_THEME ? DARK_VARS : LIGHT_VARS;
    for (const [key, value] of Object.entries(vars)) {
        root.style.setProperty(key, value);
    }
    root.setAttribute('data-theme', theme);
}

function updateThemeButton(btn, theme) {
    if (theme === DARK_THEME) {
        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>`;
        btn.title = '切换到日间模式';
    } else {
        btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>`;
        btn.title = '切换到夜间模式';
    }
}

// 自动跟随系统偏好（首次访问时）
export function followSystemPreference() {
    if (localStorage.getItem(THEME_KEY)) return;
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        applyTheme(DARK_THEME);
        localStorage.setItem(THEME_KEY, DARK_THEME);
    }
}

// 初始化
initTheme();
followSystemPreference();
