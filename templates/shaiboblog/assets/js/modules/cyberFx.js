/**
 * shaiboblog 赛博特效模块
 * 负责首屏四个动态特效：终端打字机 / 数字滚动计数 / 标题字符解密 / 文章滚动显现
 *
 * 设计约束：
 *  - 只在 boot 第二次 applyThemeOptions（文本为最终值）之后初始化一次
 *  - 全部尊重 prefers-reduced-motion，减动偏好下保留静态文本不做动画
 *  - 数字由 renderHeroStats 异步填充，用 MutationObserver 监听，不侵入 sidebar.js
 *  - 不新增/改动任何静态 id/class，契约门禁零风险
 *  - 每个动画用 dataset 标记防重复；元素被 SPA 移除后主动停止
 */
const REDUCED = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

/** 终端打字机：把已有文本逐字打出，末尾保留原始终端提示符 */
function typeTerminal(el) {
    if (!el || REDUCED || el.dataset.cyTyped === '1') return;
    const target = el.textContent || '';
    if (target.length < 8) return;
    el.dataset.cyTyped = '1';
    el.classList.add('cy-typing');
    el.textContent = '';
    let i = 0;
    const step = () => {
        if (!el.isConnected) return;
        if (i >= target.length) {
            el.textContent = target;
            el.classList.remove('cy-typing');
            return;
        }
        const ch = target.charAt(i++);
        el.textContent += ch;
        setTimeout(step, ch === '\n' ? 130 : 17);
    };
    setTimeout(step, 240);
}

/** 单个数字滚动：0 → 目标值，easeOutCubic，900ms */
function animateCount(el) {
    if (!el || REDUCED || el.dataset.cyCounted === '1') return;
    const raw = (el.textContent || '').trim();
    if (!/^\d+$/.test(raw)) return;
    const target = parseInt(raw, 10);
    el.dataset.cyCounted = '1';
    if (!isFinite(target) || target <= 0) return;
    const start = performance.now();
    const dur = 900;
    const tick = (now) => {
        if (!el.isConnected) return;
        const p = Math.min(1, (now - start) / dur);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = String(Math.round(target * eased));
        if (p < 1) requestAnimationFrame(tick);
        else el.textContent = String(target);
    };
    requestAnimationFrame(tick);
}

/** 监听三个统计数字从「—」变成数字后触发滚动；不侵入 sidebar.js */
function watchStats() {
    ['heroPostCount', 'heroCatCount', 'heroTagCount'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        animateCount(el);
        new MutationObserver(() => animateCount(el)).observe(el, {
            childList: true, characterData: true, subtree: true,
        });
    });
}

/** 标题字符解密：乱码逐字符收敛到目标文字，一次性播放 */
function decryptTitle(el) {
    if (!el || REDUCED || el.dataset.cyDecrypted === '1') return;
    const target = el.textContent || '';
    if (target.length < 4) return;
    el.dataset.cyDecrypted = '1';
    const pool = '!<>-_\\/[]{}=+*^?#01アイウエオカキクケコ';
    const n = target.length;
    const revealAt = [];
    for (let i = 0; i < n; i++) revealAt.push(i * 38 + Math.random() * 160);
    const total = revealAt[n - 1] + 200;
    const start = performance.now();
    el.classList.add('cy-decrypting');
    const tick = (now) => {
        if (!el.isConnected) return;
        const t = now - start;
        let out = '';
        for (let i = 0; i < n; i++) {
            const c = target.charAt(i);
            if (c === ' ' || c === '\n') { out += c; continue; }
            out += t >= revealAt[i] ? c : pool.charAt((Math.random() * pool.length) | 0);
        }
        el.textContent = out;
        if (t < total) requestAnimationFrame(tick);
        else { el.textContent = target; el.classList.remove('cy-decrypting'); }
    };
    requestAnimationFrame(tick);
}

/** 文章卡片滚动显现：默认由 CSS 隐藏，进入视口加 cy-in 恢复 */
function setupReveal() {
    if (REDUCED) return;
    const feed = document.getElementById('feed');
    if (!feed) return;
    if (!('IntersectionObserver' in window)) return;
    document.body.classList.add('cy-reveal-ready');
    const io = new IntersectionObserver((entries) => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                e.target.classList.add('cy-in');
                io.unobserve(e.target);
            }
        });
    }, { threshold: 0, rootMargin: '0px 0px -30px 0px' });
    const mark = (node) => {
        if (!node || node.nodeType !== 1) return;
        const cards = [];
        if (node.matches && node.matches('.post')) cards.push(node);
        if (node.querySelectorAll) node.querySelectorAll('.post').forEach(p => cards.push(p));
        cards.forEach(card => {
            if (card.dataset.cyReveal === '1') return;
            card.dataset.cyReveal = '1';
            io.observe(card);
        });
    };
    mark(feed);
    new MutationObserver(muts => {
        muts.forEach(m => m.addedNodes.forEach(mark));
    }).observe(feed, { childList: true, subtree: true });
}

/** 唯一入口：由 app.js 在 boot 第二次 applyThemeOptions 之后调用一次 */
export function initCyberFx() {
    if (document.body.dataset.cyFxInit === '1') return;
    document.body.dataset.cyFxInit = '1';
    typeTerminal(document.getElementById('heroTermCode'));
    decryptTitle(document.getElementById('heroTitle'));
    watchStats();
    setupReveal();
}
