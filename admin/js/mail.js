// ============================================================
// mail.js — 邮件测试
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { registerActions, request, showToast } from './core.js';

// B4b 委托化：静态发送测试邮件
registerActions({
    'send-test-mail': () => sendTestMail(),
});



// ============ 邮件测试 ============
export async function sendTestMail() {
    const to = document.getElementById('mail-test-to').value.trim();
    const result = document.getElementById('mail-test-result');
    if (!to) { showToast('请输入收件邮箱地址'); return; }
    result.innerHTML = '<span style="color:var(--info);">正在发送测试邮件...</span>';
    try {
        const data = await request('/admin/mail/test', {method:'POST', body:{to}});
        result.innerHTML = '<span style="color:var(--success);">测试邮件发送成功，请查收</span>';
        showToast('测试邮件发送成功');
    } catch(e) {
        result.innerHTML = '<span style="color:var(--danger);">' + e.message + '</span>';
        showToast(e.message);
    }
}