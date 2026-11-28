/**
 * 重置密码页：对接 /auth/reset-password（公开组 + CSRF）。
 * 邮件链接携带 email 与 token 两个 query 参数。
 */
import { PAGE_PATH } from './config.js?v=20261128.01';

export function renderResetPassword(app) {
    const mod = document.getElementById('page-module');
    if (!mod) return;

    const params = new URLSearchParams(location.search);
    const email = params.get('email') || '';
    const token = params.get('token') || '';
    const valid = !!email && !!token;

    mod.innerHTML = `
      <div class="module-head"><h1>重置密码</h1></div>
      <div class="mod-card mod-form">
        ${valid ? '' : '<p class="mod-empty">重置链接无效或缺少参数，请重新申请。</p>'}
        <label class="field"><span>邮箱</span><input id="resetEmail" type="email" value="${app.escapeHtml(email)}" ${valid ? 'readonly' : 'disabled'}></label>
        <label class="field"><span>新密码</span><input id="resetPwd" type="password" autocomplete="new-password" ${valid ? '' : 'disabled'}></label>
        <label class="field"><span>确认密码</span><input id="resetPwd2" type="password" autocomplete="new-password" ${valid ? '' : 'disabled'}></label>
        <button class="btn-primary" id="resetSubmit" type="button" style="margin-top:16px" ${valid ? '' : 'disabled'}>确认重置</button>
        <p id="resetMsg" style="margin-top:10px;font-size:12px;color:var(--sub)"></p>
      </div>`;

    const submit = document.getElementById('resetSubmit');
    if (!submit || !valid) return;
    submit.addEventListener('click', async () => {
        const password = document.getElementById('resetPwd').value;
        const password_confirm = document.getElementById('resetPwd2').value;
        const msg = document.getElementById('resetMsg');
        if (password !== password_confirm) { msg.textContent = '两次输入的密码不一致'; return; }
        submit.disabled = true;
        try {
            await app.request('/auth/reset-password', {
                method: 'POST',
                body: { email, token, password, password_confirm },
            });
            msg.textContent = '密码重置成功，即将前往登录页…';
            setTimeout(() => { location.href = PAGE_PATH + '/login'; }, 1200);
        } catch (e) {
            msg.textContent = e.message || '重置失败，链接可能已过期';
            submit.disabled = false;
        }
    });
}
