// ============================================================
// storage.js — 云存储驱动
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { registerActions, request, showToast } from './core.js';

// B4b 委托化：静态连通性测试（data-value 为空串=当前驱动）
registerActions({
    'test-cloud-connection': (d) => testCloudConnection(d.value || ''),
    // B6 委托化：存储驱动切换（原 onchange 直调）
    'cloud-driver-change': () => cloudDriverChange(),
});



/* ---------- 云存储 Tab 交互 ---------- */

/** 切换 driver 时高亮对应厂商的配置区块（视觉提示） */
export function cloudDriverChange() {
    const val = (document.getElementById('setting-upload_driver') || {}).value || 'local';
    const badges = document.querySelectorAll('[data-cloud]');
    badges.forEach(b => {
        const match = b.getAttribute('data-cloud') === val;
        if (match) {
            b.classList.remove('badge-success');
            b.classList.add('badge-danger');
            b.textContent = b.getAttribute('data-cloud') + ' ← 当前';
        } else {
            b.classList.remove('badge-danger');
            b.classList.add('badge-success');
            b.textContent = b.getAttribute('data-cloud');
        }
    });
}

/** 连通性测试 */
export async function testCloudConnection(driver) {
    const box = document.getElementById('cloud-test-result');
    const nameMap = { local:'本地存储', aliyun:'阿里云 OSS', qcloud:'腾讯云 COS', qiniu:'七牛云 Kodo' };
    const showName = nameMap[driver] || '当前驱动';
    if (box) {
        box.style.background = '#fff7e6';
        box.style.borderColor = '#ffd591';
        box.textContent = '正在测试 ' + showName + ' 连通性...';
    }
    try {
        const qs = (driver && driver !== '') ? '?driver=' + encodeURIComponent(driver) : '';
        const data = await request('/admin/storage/test-connection' + qs);
        const ok = data && data.ok === true;
        if (box) {
            box.style.background = ok ? '#f6ffed' : '#fff1f0';
            box.style.borderColor = ok ? '#b7eb8f' : '#ffa39e';
            const lines = [];
            lines.push((ok ? '测试通过：' : '测试失败：') + showName + '（driver=' + (data.driver || '-') + ', code=' + (data.code || '-') + '）');
            lines.push('原因：' + (data.reason || '-'));
            if (data.diagnosis) lines.push('诊断：' + data.diagnosis);
            box.textContent = lines.join('\n');
        }
        showToast(ok ? showName + ' 连通性 OK' : showName + ' 连通性失败', ok ? 'success' : 'error');
    } catch(e) {
        if (box) {
            box.style.background = '#fff1f0';
            box.style.borderColor = '#ffa39e';
            box.textContent = '异常：' + e.message;
        }
        showToast(e.message, 'error');
    }
}