/**
 * woquan 通用工具函数
 */
import { renderTextEmoji } from './emoji.js?v=20261117.02';

/**
 * 从帖子中提取可用于列表封面的图片。
 *
 * 文章编辑器输出 Markdown，图片以 `![图片](url)` 内嵌在 content 里，
 * posts.images 字段为空；社交动态的图则存在 images 数组中。
 * 两类来源都要覆盖，否则资讯流卡片会全部退化成纯文字卡。
 *
 * @param {object} post 帖子对象
 * @param {number} limit 最多返回几张
 * @returns {string[]} 已做协议白名单校验的图片 URL
 */
export function collectPostImages(post, limit = 9) {
    const out = [];
    const seen = new Set();
    const push = (raw) => {
        const url = safeUrl(String(raw ?? '').trim());
        if (url && !seen.has(url)) { seen.add(url); out.push(url); }
    };

    // 1) 结构化 images（动态 / 编辑器显式上传的图）
    if (Array.isArray(post?.images)) {
        for (const im of post.images) {
            if (typeof im === 'string') push(im);
            else if (im && typeof im === 'object') push(im.url || im.src || '');
        }
    }

    // 2) 正文内嵌图：Markdown ![alt](url) 与 HTML <img src="url">
    const content = String(post?.content || '');
    const md = /!\[[^\]]*\]\(\s*<?([^)\s>]+)>?/g;
    let m;
    while ((m = md.exec(content)) !== null) push(m[1].replace(/&amp;/g, '&'));
    const html = /<img\b[^>]*?\bsrc\s*=\s*["']([^"']+)["']/gi;
    while ((m = html.exec(content)) !== null) push(m[1].replace(/&amp;/g, '&'));

    // 3) 视频封面兜底
    if (!out.length && Array.isArray(post?.videos)) {
        for (const v of post.videos) {
            if (v && typeof v === 'object' && v.cover) push(v.cover);
        }
    }
    return limit > 0 ? out.slice(0, limit) : out;
}

export function escapeHtml(text) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text ?? '').replace(/[&<>"']/g, c => map[c]);
}

/**
 * 让整卡点击跳转的容器具备链接语义与键盘可达性（Enter / 空格触发）。
 * 信息流、搜索结果、榜单等列表项都是 div + click，读屏与键盘用户原本无法激活。
 */
/**
 * 输出站内链接：真实 href（爬虫可见）+ data-spa 标记（JS 环境下走无刷新跳转）
 *
 * 用法：linkTag(PAGE_PATH + '/p/' + id, '<img ...>', 'card-cover-link')
 * 不依赖 JS 时链接依然可用，JS 环境下由 initSpaLinks() 统一拦截。
 */
export function linkTag(url, inner, cls = '') {
    const href = String(url || '').replace(/"/g, '&quot;');
    const clsAttr = cls ? ` class="${cls}"` : '';
    return `<a${clsAttr} href="${href}" data-spa="1">${inner}</a>`;
}

/**
 * 全站链接委托：带 data-spa 的链接拦截为无刷新跳转，
 * 其余 a 标签保持浏览器默认行为（外链、锚点、下载等不受影响）。
 */
export function initSpaLinks() {
    if (document.documentElement.dataset.spaLinks === '1') return;
    document.documentElement.dataset.spaLinks = '1';
    document.addEventListener('click', e => {
        const a = e.target.closest && e.target.closest('a[data-spa]');
        if (!a) return;
        if (a.target === '_blank' || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        const href = a.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        e.preventDefault();
        location.href = href;
    });
}

export function makeCardNavigable(el, href) {
    el.setAttribute('role', 'link');
    el.tabIndex = 0;
    el.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            location.href = href;
        }
    });
    return el;
}

/* ===================== 品牌名 ===================== */
/**
 * 解析对外展示的品牌名。
 * 优先级：后台「主题外观」设置的 brand_name > 站点 site_name > woquan。
 * 历史遗留标识与主题通用占位名「资讯圈」不再作为对外品牌。
 *
 * @param {object} themeOpts 主题外观选项（首屏 window.__THEME_OPTIONS__ 或异步下发的 theme_options）
 * @param {object} siteConfig /site/config 返回的站点配置
 * @returns {string}
 */
export function resolveBrandName(themeOpts, siteConfig) {
    // 只过滤真正的代码标识符泄露（Hublog 是命名空间 / 变量前缀等强依赖标识，绝不该作为对外品牌名）。
    // 不再把「资讯圈」「woquan」等合法品牌名当遗留值丢弃——后台设置的任何名字都应被尊重。
    const legacy = /^hublog$/i;
    const pick = (v) => (typeof v === 'string' ? v.trim() : '');
    const configured = pick(themeOpts && themeOpts.brand_name);
    if (configured && !legacy.test(configured)) return configured;
    const site = pick(siteConfig && siteConfig.site_name);
    if (site && !legacy.test(site)) return site;
    const fallback = pick(siteConfig && siteConfig.theme_options && siteConfig.theme_options.brand_name);
    if (fallback && !legacy.test(fallback)) return fallback;
    return 'woquan';
}

/**
 * 把品牌名写入页面上所有展示位，保证切页/异步配置到达后仍一致。
 * @param {string} brand
 */
export function applyBrandNameToDOM(brand) {
    const text = brand || 'woquan';
    ['brandName', 'brandNameLeft', 'brandNameRight'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    });
    document.querySelectorAll('[data-brand-name]').forEach(el => { el.textContent = text; });
    // 页面标题里残留的旧品牌/占位字样一并替换
    if (document.title) {
        document.title = document.title
            .replace(/资讯圈|Hublog/gi, text)
            .replace(/\s*·\s*woquan(\s*·\s*woquan)+/gi, ' · woquan');
    }
}

/**
 * URL 安全化：仅允许 http/https 与相对路径 / ...，禁止 javascript:/data: 等危险协议
 * 用于 <img src>、<a href>、CSS url()、video src 等所有 URL 字段
 * 返回空串表示 URL 不安全，调用方应回退到默认值
 */
export function safeUrl(url) {
    const u = String(url ?? '').trim();
    if (u === '') return '';
    // 相对路径 / ./ ../ 与协议相对 // 直接放行（但 // 仍可能跨域，需调用方自行判断）
    if (/^(\/|\.\/|\.\.\/)/.test(u)) return u;
    // 仅允许 http/https
    if (/^https?:\/\//i.test(u)) return u;
    // 协议相对 //（不阻断但调用方注意跨域）
    if (u.startsWith('//')) return u;
    // 其余（javascript:、data:、file: 等）一律拒绝
    return '';
}

/**
 * 转义 URL 用于 HTML 属性上下文（src="..." / href="..." / url('...')）
 * 配合 safeUrl 使用：先 safeUrl 校验协议，再 escapeAttr 转义引号
 */
export function escapeAttr(url) {
    return String(url ?? '')
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * JS 上下文字符串转义：用于内联 onclick="app.fn('...')" 等场景
 * 防止 escapeHtml 转义后的 &#039; 在 HTML 属性解析时被还原为 ' 关闭 JS 字符串
 * 输出的是 \\xHH 形式，浏览器先解析 HTML 再解析 JS 时均不会触发危险字符
 */
export function escapeJs(text) {
    return String(text ?? '')
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'")
        .replace(/"/g, '\\"')
        .replace(/</g, '\\x3c')
        .replace(/>/g, '\\x3e')
        .replace(/&/g, '\\x26')
        .replace(/=/g, '\\x3d')
        .replace(/;/g, '\\x3b')
        .replace(/`/g, '\\x60');
}

export function getTextLength(text) {
    return Array.from(String(text || '')).length;
}

export function truncateText(text, maxLength) {
    if (getTextLength(text) <= maxLength) return text;
    let result = '';
    let count = 0;
    for (const char of String(text || '')) {
        if (count >= maxLength) break;
        result += char;
        count++;
    }
    return result;
}

/**
 * V71：Web 端会员徽章（与 App 端 src/utils/vip.js 同一套三档命名/配色）
 * 等级：0=普通用户（不显示）、1=青铜、2=白银、3=黄金
 * 返回一段可直接 innerHTML 的 HTML；level<=0 时返回空串，调用方无需判断。
 * 用法：${vipBadge(post.user.vip_level)}
 */
export function vipLevelName(level) {
    const map = { 1: '青铜会员', 2: '白银会员', 3: '黄金会员' };
    return map[level] || '';
}

// V71：VIP 字母图标（与 App 端 src/static/icons/VIP.svg 同源，1024 viewBox）
const VIP_SVG =
    `<svg viewBox="0 0 1024 1024" width="100%" height="100%" fill="currentColor" aria-hidden="true">` +
    `<path d="M605.693 867.837h-63.715v-304.621h63.715v304.621M881.428 719.652c-16.991 25.486-43.449 38.108-79.493 38.108h-73.060v110.077h-63.715v-304.621h139.325c29.491 0 52.671 8.738 69.42 26.093 16.869 17.355 25.244 41.142 25.244 71.483 0.122 21.36-5.825 41.021-17.718 58.861M186.383 867.837v-648.685c0-2.67-2.185-4.854-4.854-4.854h-49.637c-2.67 0-4.854-2.185-4.854-4.854v-49.759c0-2.67 2.185-4.854 4.854-4.854h227.92c2.67 0 4.854 2.185 4.854 4.854v292.242l297.703-295.641c0.85-0.85 2.185-1.456 3.398-1.456h221.972c4.369 0 6.432 5.219 3.398 8.253l-704.754 704.754M822.446 625.596c-8.738-6.432-23.059-9.709-42.962-9.709h-50.608v89.202h59.104c20.389 0 34.224-6.19 41.628-18.569 4.005-6.796 5.947-16.384 5.947-28.763 0-14.928-4.369-25.607-13.107-32.161M822.446 625.596z"/>` +
    `</svg>`;

export const VIP_STYLE_MAP = {
    1: { bg: 'linear-gradient(135deg,#c9884a,#8a4f1f)', name: '青铜会员' },
    2: { bg: 'linear-gradient(135deg,#a8b2bd,#6f7a87)', name: '白银会员' },
    3: { bg: 'linear-gradient(135deg,#ffd53d,#f0a800)', name: '黄金会员' }
    // V86：会员只有 1/2/3 三档（购买获得），4/5 已收回，与 App 端 utils/vip.js 保持一致
};

/**
 * size 单位 px（正方形徽章边长）。加字号*1.15 的矩形版本用 vipBadgeText()。
 */
export function vipBadge(level, size = 16) {
    const lv = Number(level) || 0;
    const vip = VIP_STYLE_MAP[lv];
    if (!vip) return '';
    // 徽章内的 VIP 字母图标与 App 端同源（1024 viewBox 的 VIP 字形），白色填充。
    // 全部用内联样式：各页面 style.css 带的 ?v= 版本号不必逐个升，改一处全站生效。
    return `<span class="wb-vip" data-level="${lv}" title="${vip.name}" aria-label="${vip.name}" style="display:inline-flex;align-items:center;justify-content:center;width:${size}px;height:${size}px;padding:2px;border-radius:999px;background:${vip.bg};color:#fff;flex-shrink:0;vertical-align:middle;">` +
        VIP_SVG +
        `</span>`;
}

/** 带等级文字的横向徽章（用于用户主页头部） */
export function vipBadgeText(level, fontSize = 12) {
    const lv = Number(level) || 0;
    const vip = VIP_STYLE_MAP[lv];
    if (!vip) return '';
    return `<span class="wb-vip" data-level="${lv}" title="${vip.name}" style="display:inline-flex;align-items:center;gap:3px;height:${Math.round(fontSize * 1.6)}px;padding:0 ${Math.round(fontSize * 0.5)}px;border-radius:999px;background:${vip.bg};color:#fff;font-size:${fontSize}px;line-height:1;flex-shrink:0;vertical-align:middle;">` +
        `<span style="display:inline-flex;width:${Math.round(fontSize * 0.9)}px;height:${Math.round(fontSize * 0.9)}px;">${VIP_SVG}</span>` +
        `${vip.name}</span>`;
}

export function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

export function formatTimeAgo(time) {
    if (!time) return '';
    const date = new Date(time.replace(' ', 'T'));
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    if (diff < 60) return '刚刚';
    if (diff < 3600) return `${Math.floor(diff / 60)}分钟前`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}小时前`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}天前`;
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function decodeHtmlEntities(text) {
    const textarea = document.createElement('textarea');
    textarea.innerHTML = text;
    return textarea.value;
}

/**
 * 初始化图片懒加载兜底
 * 浏览器原生支持 loading="lazy" 时此函数几乎无开销；
 * 不支持时（如旧版 Safari），使用 IntersectionObserver 懒加载 data-src 图片。
 * 应在 DOM 渲染完成后调用。
 */
let _lazyObserver = null;
export function initLazyLoading(rootEl) {
    const scope = rootEl || document;
    // 检查浏览器是否原生支持 loading="lazy"
    const supportsNativeLazy = 'loading' in HTMLImageElement.prototype;
    if (supportsNativeLazy) return; // 原生支持，无需兜底

    if (!_lazyObserver) {
        _lazyObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        delete img.dataset.src;
                    }
                    _lazyObserver.unobserve(img);
                }
            });
        }, { rootMargin: '200px' });
    }
    // 查找所有带 loading="lazy" 但尚未处理的图片
    scope.querySelectorAll('img[loading="lazy"]:not([data-lazy-bounded])').forEach(img => {
        img.setAttribute('data-lazy-bounded', '1');
        if (!img.dataset.src && img.src) {
            img.dataset.src = img.src;
            img.removeAttribute('src');
        }
        _lazyObserver.observe(img);
    });
}

export function formatText(text, BASE_URL, escapeHtml) {
    if (!text) return '';
    let html = escapeHtml(text).replace(/\r\n|\r|\n/g, '<br>');

    const topicPlaceholders = [];
    const urlPlaceholders = [];
    const mentionPlaceholders = [];

    // 1. @提及 → 占位符（在话题和URL之前处理，避免和HTML标签混淆）
    html = html.replace(/(^|[\s]|&lt;|&gt;)@([a-zA-Z0-9_\u4e00-\u9fa5]+)/g, (match, prefix, username) => {
        const idx = mentionPlaceholders.length;
        const safeUsername = escapeHtml(username);
        mentionPlaceholders.push(`<a href="${BASE_URL}/u/${encodeURIComponent(username)}" class="mention-link" onclick="event.stopPropagation()">@${safeUsername}</a>`);
        return prefix + `<!--HUBLOG_MENTION_${idx}-->`;
    });

    // 2. 话题 → 占位符
    const addTopicPlaceholder = (match, topic) => {
        const t = topic.trim();
        if (!t) return match;
        const idx = topicPlaceholders.length;
        const decoded = decodeHtmlEntities(t);
        topicPlaceholders.push(`<a href="${BASE_URL}/topic/${encodeURIComponent(decoded)}" class="topic-link" onclick="event.stopPropagation()">#${t}#</a>`);
        return `<!--HUBLOG_TOPIC_${idx}-->`;
    };
    html = html.replace(/##([^#]+)##/g, addTopicPlaceholder);
    html = html.replace(/#([^#]{1,30})#/g, (match, topic) => {
        const t = topic.trim();
        if (!t || !(/[\u4e00-\u9fa5]/.test(t) || /[^0-9a-fA-F]/.test(t))) return match;
        return addTopicPlaceholder(match, topic);
    });

    // 3. URL → 占位符
    // V133：字符集收紧——老正则 [^\s<]+ 会把中文和标点一起吞进 URL。
    // 用户实测：正文「测试发布地址www.baidu.com，这个地址。」整串被当成一个链接，
    // 点进去跳到 http://www.baidu.com，这个地址。（坏地址）。
    // 现在只匹配 URL 合法字符（ASCII 集合），遇到中文/全角标点自然断开，
    // 后面若还有别的 URL 也能各自成链；回调里再剃掉尾部标点（剃掉的部分原样拼回正文，不丢字）。
    const urlRegex = /(https?:\/\/[A-Za-z0-9\-._~:/?#[\]@!$&'()*+,;=%]+|www\.[A-Za-z0-9\-._~:/?#[\]@!$&'()*+,;=%]+)/g;
    html = html.replace(urlRegex, (match) => {
        const decoded = decodeHtmlEntities(match);
        // 剃掉尾部标点（URL 后紧跟句读/括号的场景，如「www.baidu.com.」）
        const trimmed = decoded.replace(/[.,;:!?'")\]}]+$/, '');
        const tail = decoded.slice(trimmed.length);
        if (tail) {
            // 剃完后 host 部分啥都不剩（如「www..」「https://.」）→ 不成链，原样返回
            const hostPart = trimmed.replace(/^(https?:\/\/|www\.)/, '').split(/[/?#]/)[0];
            if (!/[A-Za-z0-9]/.test(hostPart)) return match;
        }
        let url = trimmed;
        if (url.startsWith('www.')) url = 'https://' + url;
        try {
            const urlObj = new URL(url);
            const idx = urlPlaceholders.length;
            const display = escapeHtml(trimmed);
            if (urlObj.host === window.location.host) {
                urlPlaceholders.push(`<a href="${display}" target="_blank" rel="noopener" class="text-link" onclick="event.stopPropagation()">${display}</a>`);
            } else {
                urlPlaceholders.push(`<a href="${BASE_URL}/go?url=${encodeURIComponent(url)}" target="_blank" rel="noopener" class="text-link external-link" onclick="event.stopPropagation()">${display}</a>`);
            }
            return `<!--HUBLOG_URL_${idx}-->` + tail;
        } catch (e) {
            return match;
        }
    });

    // 4. 统一替换占位符
    html = html.replace(/<!--HUBLOG_MENTION_(\d+)-->/g, (_, idx) => mentionPlaceholders[parseInt(idx, 10)] ?? _);
    html = html.replace(/<!--HUBLOG_URL_(\d+)-->/g, (_, idx) => urlPlaceholders[parseInt(idx, 10)] ?? _);
    html = html.replace(/<!--HUBLOG_TOPIC_(\d+)-->/g, (_, idx) => topicPlaceholders[parseInt(idx, 10)] ?? _);

    // 5. 将 emoji 字符替换为 Fluent UI Emoji 3D 图片（最后执行，避免干扰 URL/话题/提及匹配）
    html = renderTextEmoji(html);

    return html;
}

/**
 * 可访问性：为模态框添加 ARIA 属性并管理焦点
 * - 设置 role="dialog" aria-modal="true" aria-hidden
 * - 打开时聚焦第一个可交互元素，关闭时恢复触发元素焦点
 * - 支持 ESC 键关闭（需绑定到 modal 本身）
 *
 * @param {HTMLElement} modal 模态框根元素（.modal-overlay）
 * @param {HTMLElement|null} trigger 触发该模态框的元素（关闭后焦点回到这里）
 * @returns {Function} 关闭函数（调用以编程方式关闭并恢复焦点）
 */
export function makeAccessibleModal(modal, trigger = null) {
    if (!modal) return () => {};
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-hidden', 'true');

    // 收集可聚焦元素
    const focusSelector = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

    const open = () => {
        modal.setAttribute('aria-hidden', 'false');
        // 聚焦第一个可交互元素，便于键盘用户立即操作
        const focusable = modal.querySelector(focusSelector);
        if (focusable) {
            // 延迟一帧等待 .active 动画完成
            requestAnimationFrame(() => focusable.focus());
        }
    };

    const close = () => {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        // 恢复触发元素的焦点
        if (trigger && typeof trigger.focus === 'function') {
            trigger.focus();
        }
    };

    // ESC 键关闭（仅当模态框可见时）
    const onKeydown = (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) {
            e.preventDefault();
            close();
        }
    };
    modal.addEventListener('keydown', onKeydown);

    return close;
}

/**
 * 渲染 Markdown 为安全 HTML（展示端统一出口）
 * - marked.parse 生成 HTML
 * - DOMPurify 净化（防 XSS，与 App 端 app-client/src/utils/markdown.js 同源策略）
 * - @提及 / #话题# 在 marked 之前预链接化为 markdown 链接（避免被转义）
 * - 外链统一加 target=_blank rel=noopener
 * - emoji 字符替换为 Fluent UI 图片（净化前执行，由 DOMPurify 校验 img）
 */
export function renderMarkdown(content, base = '') {
    if (!content) return '';
    const BASE = base || '';
    let src = String(content);

    // @提及 → markdown 链接（仅匹配字母数字下划线用户名，与后端/提取逻辑一致）
    src = src.replace(/(^|[\s(>])@([a-zA-Z0-9_]{3,20})/g, (m, pre, u) =>
        `${pre}[@${u}](${BASE}/u/${encodeURIComponent(u)})`);
    // #话题# → markdown 链接
    src = src.replace(/#([^#\s]{1,30})#/g, (m, t) =>
        `[#${t}#](${BASE}/topic/${encodeURIComponent(t)})`);

    let html;
    // marked 与净化器必须同时存在才走 Markdown 渲染。
    // 缺任一依赖时退化为纯文本（escapeHtml），避免「有 marked 无净化」时
    // 把用户正文里的原始 HTML 原样注入页面（存储型 XSS）。
    try {
        if (window.marked && typeof window.marked.parse === 'function' && window.DOMPurify) {
            html = window.marked.parse(src);
        } else {
            html = escapeHtml(src).replace(/\r\n|\r|\n/g, '<br>');
        }
    } catch (e) {
        html = escapeHtml(src).replace(/\r\n|\r|\n/g, '<br>');
    }

    if (window.DOMPurify) {
        html = window.DOMPurify.sanitize(html, {
            FORBID_TAGS: ['style', 'iframe', 'form', 'script', 'object', 'embed', 'link', 'meta', 'base'],
            FORBID_ATTR: ['style', 'on*'],
            ADD_ATTR: ['target', 'rel'],
            ALLOW_DATA_ATTR: false
        });
    }

    // 外链加安全属性（DOMPurify 之后处理，确保不丢）
    html = html.replace(/<a\s+href="(https?:\/\/([^"/]+)[^"]*)"/g, (m, url, host) => {
        if (host === window.location.host) return m;
        return m + ' target="_blank" rel="noopener noreferrer"';
    });

    // emoji 替换（净化前已执行，DOMPurify 已校验 img；此处再次执行无害）
    html = renderTextEmoji(html);
    return html;
}

