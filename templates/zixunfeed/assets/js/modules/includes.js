/**
 * 主题局部模板（include）展开器 —— 完全在主题目录内运行，不依赖服务端任何改动。
 *
 * 背景：主题必须可以「只上传 templates/<theme>/ 整个目录」独立工作。
 * 页面里的 <!-- @include partials/xxx.html --> 注释由本模块在浏览器端展开，
 * partial 文件与页面同目录部署，fetch 路径相对 index.php 注入的 <base>（主题目录）。
 *
 * 健壮性（2026-11-15 修复）：
 *  1) 每个 partial 拉取加超时，超时即走内联兜底，绝不因挂起请求卡死 boot() 导致整页白屏；
 *  2) fetch 回来做内容特征校验，防止被 nginx rewrite / CDN 缓存成首页 HTML 造成 DOM 混乱；
 *  3) 失败时注入内联兜底 DOM（顶栏 / 底栏 / 挂载点），保证导航、登录、信息流照常可用。
 */

const partialCache = new Map();
const FETCH_TIMEOUT_MS = 4000;

// 内容特征：确认 fetch 回来的确实是目标片段，而不是被 rewrite 的首页 / 错误页
const SIGNATURE = {
    'topbar.html': 'class="topbar"',
    'tabbar.html': 'id="tabbar"',
    'page-tail.html': 'id="toast"',
};

// 内联兜底：与 partials/ 下文件保持一致，任何拉取失败都不会让页面缺失导航 / 登录 / 挂载点
const FALLBACK = {
    'topbar.html': `<header class="topbar">
    <div class="topbar-inner">
        <a class="logo" href="javascript:;" data-home>
            <span class="logo-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round">
                    <path d="M4 20C8 14 12 10 20 5"/><circle cx="6" cy="18" r="1.6" fill="#fff" stroke="none"/>
                </svg>
            </span>
            <span id="brandName">woquan</span>
        </a>
        <label class="search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <input type="text" id="searchInput" placeholder="搜索资讯、话题、作者">
        </label>
        <div class="topbar-right">
            <button class="btn-login" id="loginBtn">登录</button>
            <span class="topbar-avatar" id="topbarAvatar" style="display:none"><img alt=""></span>
        </div>
    </div>
    <nav class="channel-bar">
        <div class="channel-inner" id="channelBar"></div>
    </nav>
</header>`,
    'tabbar.html': `<nav class="tabbar" id="tabbar">
    <button class="tabbar-item active" data-tab="home">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5L12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg>
        首页
    </button>
    <button class="tabbar-item" data-tab="video">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M10 9.5l5 2.5-5 2.5z" fill="currentColor" stroke="none"/></svg>
        视频
    </button>
    <button class="tabbar-pub" data-tab="publish">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    </button>
    <button class="tabbar-item" data-tab="messages">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/></svg>
        消息
    </button>
    <button class="tabbar-item" data-tab="me">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5"/></svg>
        我的
    </button>
</nav>`,
    'page-tail.html': `<div class="toast" id="toast"></div>
<div id="authRoot"></div>`,
};

function fetchPartial(name) {
    if (!partialCache.has(name)) {
        partialCache.set(name, (async () => {
            const ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            const timer = ctrl ? setTimeout(() => ctrl.abort(), FETCH_TIMEOUT_MS) : null;
            try {
                const r = await fetch('partials/' + name, {
                    credentials: 'same-origin',
                    ...(ctrl ? { signal: ctrl.signal } : {}),
                });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const html = await r.text();
                const sig = SIGNATURE[name];
                if (sig && html.indexOf(sig) === -1) {
                    throw new Error('响应内容不匹配（疑似被 rewrite / CDN 缓存成错误页面）');
                }
                return html;
            } finally {
                if (timer) clearTimeout(timer);
            }
        })().catch(e => {
            console.warn('[zixunfeed] 局部模板加载失败，已启用内联兜底：' + name, e);
            return FALLBACK[name] || '';
        }));
    }
    return partialCache.get(name);
}

export async function expandIncludes() {
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT);
    const jobs = [];
    let node;
    while ((node = walker.nextNode())) {
        const m = node.nodeValue.match(/@include\s+partials\/([A-Za-z0-9_-]+\.html)/);
        if (m) jobs.push({ node, name: m[1] });
    }
    if (!jobs.length) return;

    await Promise.all(jobs.map(async job => {
        const html = await fetchPartial(job.name);
        const parent = job.node.parentNode;
        if (!parent || !html) { if (parent) job.node.remove(); return; }
        const tpl = document.createElement('template');
        tpl.innerHTML = html;
        parent.replaceChild(tpl.content, job.node);
    }));
}
