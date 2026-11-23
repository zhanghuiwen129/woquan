// ============================================================
// cron.js — 定时任务 / 外部 cron
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { registerActions, request, showToast } from './core.js';

// B4b 委托化：静态复制定时命令 / 立即执行调度
registerActions({
    'copy-cron-curl': () => copyCronCurl(),
    'run-cron-now': () => runCronNow(),
});



/* ===== V65 定时发布调度（外部 cron 打 /api/cron/scheduled） ===== */
export function currentSiteUrl() {
    return location.protocol + '//' + location.host + '/';
}
export function refreshCronTip() {
    const tip = document.getElementById('cron-curl-tip');
    const secret = (document.getElementById('setting-cron_secret').value || '').trim();
    if (!tip) return;
    if (!secret) { tip.textContent = '先填写上面的 Token 并保存，这里会显示调度命令。'; return; }
    const url = currentSiteUrl() + 'api/cron/scheduled';
    tip.textContent = '*/5 * * * * curl -s -k -X POST ' + url + ' -H "Content-Type:application/json" -d \'{"secret":"' + secret + '"}\'';
}
export async function copyCronCurl() {
    refreshCronTip();
    const text = document.getElementById('cron-curl-tip').textContent;
    try {
        await navigator.clipboard.writeText(text);
        showToast('已复制，粘到服务器 crontab 里即可');
    } catch (e) { showToast('复制失败，请手动选中'); }
}
export async function runCronNow() {
    const secret = (document.getElementById('setting-cron_secret').value || '').trim();
    if (!secret) { showToast('请先填写 Token 并保存'); return; }
    try {
        // 这里不走 request()：它会把 url 当成 /admin/... 自动补接口基址，
        // 而 cron 是公开组的 /api/cron/scheduled，必须自己拼完整地址。
        const res = await fetch(currentSiteUrl() + 'api/cron/scheduled', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ secret: secret })
        });
        const json = await res.json().catch(() => null);
        if (!json || json.code !== 200) throw new Error((json && json.message) || ('HTTP ' + res.status));
        showToast('本次发布到期动态 ' + (json.data && json.data.published !== undefined ? json.data.published : 0) + ' 条');
    } catch(e) { showToast(e.message); }
}