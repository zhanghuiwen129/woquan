/**
 * shaiboblog · 时光穿梭转场
 * 点文章卡片进详情页时，先播放一段虫洞穿梭动画，再跳到目标页。
 *
 * 接入方式：utils.js::initSpaLinks 对 /p/<id> 链接调用 window.__warpNavigate(href, go)。
 * 本模块导出 initWarpFx() 完成钩子注册，由 app.js 在 boot 末尾调用一次。
 *
 * 设计约束：
 *  - 遮罩层 JS 动态注入，不写静态 HTML，契约零改动；
 *  - prefers-reduced-motion 下跳过动画直接跳转；
 *  - 动画期间锁住 body 滚动，结束/异常都保证恢复与跳转，绝不卡死导航；
 *  - 连接慢时上限 1.4s 强制跳转，避免用户干等。
 */
const REDUCED = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
let running = false;

/** 构建一次性的穿梭遮罩；纯 CSS 动画，不依赖外部资源 */
function buildOverlay() {
    const wrap = document.createElement('div');
    wrap.className = 'cy-warp';
    wrap.setAttribute('aria-hidden', 'true');
    wrap.innerHTML =
        '<div class="cy-warp-stars"></div>' +
        '<div class="cy-warp-tunnel"></div>' +
        '<div class="cy-warp-ring cy-warp-ring1"></div>' +
        '<div class="cy-warp-ring cy-warp-ring2"></div>' +
        '<div class="cy-warp-ring cy-warp-ring3"></div>' +
        '<div class="cy-warp-flash"></div>' +
        '<div class="cy-warp-hud"><span>TIME WARP</span><b>// TRAVELING TO NODE</b></div>';
    return wrap;
}

/** 播放转场；go 为真正的跳转函数 */
function play(href, go) {
    if (REDUCED || running) { go(); return; }
    running = true;
    const wrap = buildOverlay();
    document.body.appendChild(wrap);
    document.body.classList.add('cy-warping');

    let done = false;
    const finish = () => {
        if (done) return;
        done = true;
        go(); // 整页跳转，遮罩随旧页面一起销毁；若跳转被拦截，下面兜底清理
        setTimeout(() => {
            if (wrap.isConnected) wrap.remove();
            document.body.classList.remove('cy-warping');
            running = false;
        }, 60);
    };

    // 主动画 950ms 后跳转；另有 1.4s 硬上限兜底
    setTimeout(finish, 950);
    setTimeout(finish, 1400);
}

/** 唯一入口：注册全局钩子，供 utils.js 调用 */
export function initWarpFx() {
    if (typeof window.__warpNavigate === 'function') return;
    window.__warpNavigate = play;
}
