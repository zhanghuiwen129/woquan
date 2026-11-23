/**
 * shaiboblog 背景特效引擎
 * 读取 body.dataset.bgFx（由 app.js 从主题选项 bg_effect 写入），在全屏 canvas 上绘制氛围层。
 *
 * 特效：grid 网格脉冲 / aurora 极光流动 / stars 星尘漂浮 / stream 数据流 / none 关闭
 *
 * 设计约束：
 *  - canvas 动态注入（className cy-bg-fx），不写静态 HTML，契约零改动；
 *  - z-index:-1 + pointer-events:none，永远在内容之下、不挡点击与三栏滚动；
 *  - 尊重 prefers-reduced-motion：减动偏好只画一帧静态图，不起 RAF；
 *  - 移动端窄屏（<=720px）降粒子数、降帧，极光/数据流简化；
 *  - 页面隐藏（visibilitychange）暂停 RAF，回来恢复，省电；
 *  - 选项变化可热切换：setBgFx(mode) 会清理旧状态再起新状态。
 */
const REDUCED = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
const NARROW = window.innerWidth <= 720;

let canvas = null;
let ctx = null;
let raf = 0;
let mode = 'none';
let particles = [];
let cols = [];
let w = 0, h = 0, dpr = 1;
let lastDraw = 0;

function ensureCanvas() {
    if (canvas && canvas.isConnected) return;
    canvas = document.createElement('canvas');
    canvas.className = 'cy-bg-fx';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.insertBefore(canvas, document.body.firstChild);
    ctx = canvas.getContext('2d');
    resize();
}

function resize() {
    if (!canvas || !ctx) return;
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    w = window.innerWidth;
    h = window.innerHeight;
    canvas.width = Math.floor(w * dpr);
    canvas.height = Math.floor(h * dpr);
    canvas.style.width = w + 'px';
    canvas.style.height = h + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    seed();
}

function rand(a, b) { return a + Math.random() * (b - a); }

/** 按当前 mode 初始化粒子/列数据 */
function seed() {
    particles = [];
    cols = [];
    if (mode === 'stars') {
        const n = NARROW ? 45 : 110;
        for (let i = 0; i < n; i++) {
            particles.push({
                x: rand(0, w), y: rand(0, h),
                r: rand(0.5, 1.9),
                vy: rand(0.06, 0.32),
                vx: rand(-0.06, 0.06),
                a: rand(0.15, 0.65),
                tw: rand(0, Math.PI * 2),
            });
        }
    } else if (mode === 'stream') {
        const step = NARROW ? 26 : 18;
        for (let x = 0; x < w; x += step) {
            cols.push({
                x: x,
                y: rand(-h, h),
                speed: rand(0.8, 2.4),
                len: rand(8, 26),
                chars: '01アイウエオカキクケコ<>/{}',
                alpha: rand(0.05, 0.18),
            });
        }
    } else if (mode === 'grid') {
        const step = 40;
        for (let x = 0; x <= w; x += step) cols.push({ x: x });
        for (let y = 0; y <= h; y += step) particles.push({ y: y });
    }
    // aurora 不需要粒子
}

const brand = () => {
    const v = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim();
    return v || '#2dd4bf';
};
const accent = () => {
    const v = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
    return v || '#f472b6';
};

function hexToRgb(hex) {
    const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
    return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [45, 212, 191];
}

function clear() {
    if (ctx) ctx.clearRect(0, 0, w, h);
}

function drawGrid(t) {
    clear();
    if (!ctx) return;
    const [r, g, b] = hexToRgb(brand());
    const pulse = REDUCED ? 0.5 : 0.5 + 0.5 * Math.sin(t / 1600);
    ctx.lineWidth = 1;
    ctx.strokeStyle = `rgba(${r},${g},${b},${(0.03 + pulse * 0.035).toFixed(3)})`;
    ctx.beginPath();
    cols.forEach(c => { ctx.moveTo(c.x + 0.5, 0); ctx.lineTo(c.x + 0.5, h); });
    particles.forEach(p => { ctx.moveTo(0, p.y + 0.5); ctx.lineTo(w, p.y + 0.5); });
    ctx.stroke();
    if (!REDUCED) {
        // 一道沿网格移动的高光脉冲
        const gy = (t / 26) % h;
        const grad = ctx.createLinearGradient(0, gy - 60, 0, gy + 60);
        grad.addColorStop(0, `rgba(${r},${g},${b},0)`);
        grad.addColorStop(0.5, `rgba(${r},${g},${b},0.06)`);
        grad.addColorStop(1, `rgba(${r},${g},${b},0)`);
        ctx.fillStyle = grad;
        ctx.fillRect(0, gy - 60, w, 120);
    }
}

function drawAurora(t) {
    clear();
    if (!ctx) return;
    const c1 = hexToRgb(brand());
    const c2 = hexToRgb(accent());
    const blobs = NARROW ? 2 : 3;
    for (let i = 0; i < blobs; i++) {
        const cx = w * (0.25 + 0.28 * i) + (REDUCED ? 0 : Math.sin(t / (3000 + i * 700) + i) * w * 0.16);
        const cy = h * (0.22 + 0.12 * i) + (REDUCED ? 0 : Math.cos(t / (3600 + i * 600) + i) * h * 0.1);
        const rad = Math.max(w, h) * (NARROW ? 0.55 : 0.42);
        const col = i % 2 === 0 ? c1 : c2;
        const grad = ctx.createRadialGradient(cx, cy, 0, cx, cy, rad);
        grad.addColorStop(0, `rgba(${col[0]},${col[1]},${col[2]},${(0.14 + i * 0.02).toFixed(3)})`);
        grad.addColorStop(0.55, `rgba(${col[0]},${col[1]},${col[2]},0.05)`);
        grad.addColorStop(1, 'rgba(0,0,0,0)');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, w, h);
    }
}

function drawStars(t) {
    clear();
    if (!ctx) return;
    const [r, g, b] = hexToRgb(brand());
    for (const p of particles) {
        if (!REDUCED) {
            p.y += p.vy;
            p.x += p.vx;
            if (p.y > h + 4) { p.y = -4; p.x = rand(0, w); }
            if (p.x < -4) p.x = w + 4;
            if (p.x > w + 4) p.x = -4;
        }
        const twinkle = REDUCED ? 1 : 0.7 + 0.3 * Math.sin(t / 700 + p.tw);
        ctx.beginPath();
        ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(${r},${g},${b},${(p.a * twinkle).toFixed(3)})`;
        ctx.fill();
    }
}

function drawStream(t) {
    clear();
    if (!ctx) return;
    const [r, g, b] = hexToRgb(brand());
    ctx.font = '13px monospace';
    ctx.textBaseline = 'top';
    for (const c of cols) {
        if (!REDUCED) {
            c.y += c.speed;
            if (c.y - c.len * 16 > h) { c.y = rand(-h * 0.5, 0); }
        }
        for (let k = 0; k < c.len; k++) {
            const y = c.y - k * 16;
            if (y < -16 || y > h) continue;
            const ch = c.chars.charAt((Math.abs((c.x + k + (t / 90) | 0)) % c.chars.length));
            const a = c.alpha * (1 - k / c.len);
            ctx.fillStyle = k === 0
                ? `rgba(255,255,255,${Math.min(0.5, a * 3).toFixed(3)})`
                : `rgba(${r},${g},${b},${a.toFixed(3)})`;
            ctx.fillText(ch, c.x, y);
        }
    }
}

function frame(t) {
    if (!ctx) return;
    // 数据流限制到 ~30fps，其它 60fps
    if (mode === 'stream' && t - lastDraw < 32) { raf = requestAnimationFrame(frame); return; }
    lastDraw = t;
    if (mode === 'grid') drawGrid(t);
    else if (mode === 'aurora') drawAurora(t);
    else if (mode === 'stars') drawStars(t);
    else if (mode === 'stream') drawStream(t);
    if (!REDUCED) raf = requestAnimationFrame(frame);
}

function stop() {
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
}

function start() {
    stop();
    if (!ctx) return;
    if (REDUCED) { frame(0); return; } // 静态一帧
    raf = requestAnimationFrame(frame);
}

/** 切换特效：清理旧画布状态，按新 mode 重建 */
function setBgFx(next) {
    const allowed = ['none', 'grid', 'aurora', 'stars', 'stream'];
    mode = allowed.indexOf(next) >= 0 ? next : 'aurora';
    if (mode === 'none') {
        stop();
        if (canvas && canvas.isConnected) canvas.style.display = 'none';
        return;
    }
    ensureCanvas();
    canvas.style.display = '';
    seed();
    start();
}

/** 唯一入口：由 app.js 在 applyThemeOptions 后调用 */
export function initBgFx(value) {
    const v = (typeof value === 'string' && value) ? value : 'aurora';
    setBgFx(v);
    if (!canvas || !canvas.dataset.bgBound) {
        if (canvas) canvas.dataset.bgBound = '1';
        window.addEventListener('resize', () => { resize(); if (REDUCED) frame(0); }, { passive: true });
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stop();
            else if (mode !== 'none') start();
        });
    }
    return setBgFx; // 暴露给运行时切换
}
