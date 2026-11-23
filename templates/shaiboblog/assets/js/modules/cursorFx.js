/**
 * shaiboblog 鼠标特效引擎
 * 读取主题选项 cursor_effect：glow 霓虹光晕 / trail 霓虹拖尾 / laser 激光准星 / none 关闭
 *
 * 设计约束：
 *  - 全部动态注入（单个元素或 canvas），不写静态 HTML，契约零改动；
 *  - pointer-events:none + 高 z-index，只做视觉、不挡点击；
 *  - 触屏（pointer:coarse）与减动偏好用户直接跳过；
 *  - 热切换：setCursorFx(mode) 清理旧元素/监听再起新形态；
 *  - 元素用 dataset 标记，重复 init 不会叠加。
 */
const COARSE = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
const REDUCED = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

let mode = 'none';
let el = null;          // 当前挂载的元素（glow div / laser div / trail canvas）
let raf = 0;
let bound = false;
let tx = window.innerWidth / 2, ty = window.innerHeight / 2;
let cx = tx, cy = ty;
let trail = [];         // 拖尾点

function brand() {
    const v = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim();
    return v || '#2dd4bf';
}
function accent() {
    const v = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim();
    return v || '#f472b6';
}
function hexToRgb(hex) {
    const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
    return m ? [parseInt(m[1], 16), parseInt(m[2], 16), parseInt(m[3], 16)] : [45, 212, 191];
}

function clean() {
    stop();
    if (el && el.isConnected) el.remove();
    el = null;
    trail = [];
}

function stop() {
    if (raf) { cancelAnimationFrame(raf); raf = 0; }
}

function onMove(e) {
    tx = e.clientX; ty = e.clientY;
}

function ensureBound() {
    if (bound) return;
    bound = true;
    document.addEventListener('pointermove', onMove, { passive: true });
}

/** 霓虹光晕：一个径向渐变球，缓动跟随 */
function mountGlow() {
    el = document.createElement('div');
    el.className = 'cy-glow';
    document.body.appendChild(el);
    (function follow() {
        if (!el || !el.isConnected) return;
        cx += (tx - cx) * 0.12;
        cy += (ty - cy) * 0.12;
        el.style.transform = `translate(${cx.toFixed(1)}px,${cy.toFixed(1)}px)`;
        raf = requestAnimationFrame(follow);
    })();
}

/** 霓虹拖尾：canvas 记录最近 N 个鼠标点，画渐细渐淡的发光折线 + 端点 */
function mountTrail() {
    const cv = document.createElement('canvas');
    cv.className = 'cy-cursor-fx';
    document.body.appendChild(cv);
    el = cv;
    const c = cv.getContext('2d');
    let w = 0, h = 0, dpr = 1;
    const resize = () => {
        dpr = Math.min(window.devicePixelRatio || 1, 2);
        w = window.innerWidth; h = window.innerHeight;
        cv.width = Math.floor(w * dpr); cv.height = Math.floor(h * dpr);
        cv.style.width = w + 'px'; cv.style.height = h + 'px';
        c.setTransform(dpr, 0, 0, dpr, 0, 0);
    };
    resize();
    cv._resize = resize;
    trail = [];
    (function draw() {
        if (!el || !el.isConnected) return;
        c.clearRect(0, 0, w, h);
        trail.push({ x: tx, y: ty });
        if (trail.length > 18) trail.shift();
        const a = hexToRgb(brand());
        const b = hexToRgb(accent());
        for (let i = 1; i < trail.length; i++) {
            const p = trail[i - 1], q = trail[i];
            const ratio = i / trail.length;
            const col = i % 2 ? a : b;
            c.beginPath();
            c.moveTo(p.x, p.y);
            c.lineTo(q.x, q.y);
            c.lineWidth = 1 + ratio * 3;
            c.strokeStyle = `rgba(${col[0]},${col[1]},${col[2]},${(ratio * 0.5).toFixed(3)})`;
            c.shadowColor = `rgba(${col[0]},${col[1]},${col[2]},0.8)`;
            c.shadowBlur = 8 * ratio;
            c.stroke();
        }
        c.shadowBlur = 0;
        if (trail.length) {
            const head = trail[trail.length - 1];
            c.beginPath();
            c.arc(head.x, head.y, 2.5, 0, Math.PI * 2);
            c.fillStyle = `rgba(${a[0]},${a[1]},${a[2]},0.9)`;
            c.fill();
        }
        raf = requestAnimationFrame(draw);
    })();
}

/** 激光准星：横竖两条细线交叉于鼠标，极简 HUD 感 */
function mountLaser() {
    el = document.createElement('div');
    el.className = 'cy-laser';
    el.innerHTML = '<i class="cy-laser-h"></i><i class="cy-laser-v"></i>';
    document.body.appendChild(el);
    (function follow() {
        if (!el || !el.isConnected) return;
        cx += (tx - cx) * 0.35;
        cy += (ty - cy) * 0.35;
        el.style.transform = `translate(${cx.toFixed(1)}px,${cy.toFixed(1)}px)`;
        raf = requestAnimationFrame(follow);
    })();
}

function setCursorFx(next) {
    const allowed = ['none', 'glow', 'trail', 'laser'];
    mode = (COARSE || REDUCED) ? 'none' : (allowed.indexOf(next) >= 0 ? next : 'glow');
    clean();
    if (mode === 'none') return;
    ensureBound();
    if (mode === 'glow') mountGlow();
    else if (mode === 'trail') mountTrail();
    else if (mode === 'laser') mountLaser();
}

/** 唯一入口：由 app.js 传入 cursor_effect 选项 */
export function initCursorFx(value) {
    const v = (typeof value === 'string' && value) ? value : 'glow';
    setCursorFx(v);
    if (!bound) {
        window.addEventListener('resize', () => {
            if (el && el._resize) el._resize();
        }, { passive: true });
    }
    return setCursorFx;
}
