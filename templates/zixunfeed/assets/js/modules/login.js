/**
 * zixunfeed 登录 / 注册页（login.html）
 * 路由：/login。对接 /auth/login、/auth/register、/auth/phone-code、
 * /auth/login-phone、/site/stats。验证码 60s 倒计时、手机号校验、协议勾选、注册互切。
 */
import { HOME_URL } from './config.js?v=20261117.02';
import { safeTarget } from './auth.js?v=20261117.02';

const $ = id => document.getElementById(id);
const state = { method: 'code', isReg: false, counting: false, submitting: false, returnTo: HOME_URL };

/** 读 URL 参数：mode=register 落到注册态；redirect 为登录成功后返回的本站点内地址 */
function readQuery() {
    let q;
    try { q = new URLSearchParams(location.search); } catch (e) { return; }
    if ((q.get('mode') || '').toLowerCase() === 'register') state.isReg = true;
    state.returnTo = safeTarget(q.get('redirect'));
}

/** 初始态为注册时，互切文案与字段要同步成注册态（与 bindToggle 的切换结果一致） */
function syncRegShell() {
    if (!state.isReg) return;
    const sub = $('formSub'), btn = $('submitBtn'), foot = $('formFoot');
    if (sub) sub.textContent = '注册账号，记录你的每一次分享';
    if (btn) btn.textContent = '注册';
    if (foot) foot.innerHTML = '已有账号？<a href="javascript:;" id="toggleReg">返回登录</a>';
}

export async function renderLogin(app) {
    document.title = (state.isReg ? '注册' : '登录') + ' · ' + (app.opt.brandName || 'woquan');
    const brand = app.opt.brandName || 'woquan';
    ['brandNameLeft', 'brandNameRight'].forEach(id => { const el = $(id); if (el) el.textContent = brand; });

    readQuery();
    syncRegShell();
    bindMethod(app);
    bindToggle(app);
    bindCodeBtn(app);
    bindForm(app);
    bindMisc(app);
    loadStats(app);
    syncFields();

    if (app.user) {
        const hint = $('formHint');
        if (hint) hint.textContent = '已登录为 ' + (app.user.nickname || app.user.username) + '，可直接返回';
        const btn = $('submitBtn');
        if (btn) { btn.textContent = '返回原页面'; btn.dataset.goHome = '1'; }
    }
}

function syncFields() {
    const isCode = state.method === 'code';
    const show = (id, on) => { const el = $(id); if (el) el.style.display = on ? 'block' : 'none'; };
    show('codeField', isCode);
    show('pwdField', !isCode);
    show('confirmField', !isCode && state.isReg);
    show('emailField', !isCode && state.isReg);
    const label = $('accountLabel'), input = $('account');
    if (label) label.textContent = isCode ? '手机号' : '用户名 / 手机号';
    if (input) {
        input.placeholder = isCode ? '请输入手机号' : '请输入用户名或手机号';
        input.type = isCode ? 'tel' : 'text';
        if (isCode) input.maxLength = 11;
        else input.removeAttribute('maxlength');
    }
    document.querySelectorAll('.method-btn').forEach(b => b.classList.toggle('active', b.dataset.method === state.method));
}

function bindMethod(app) {
    const box = $('method');
    if (!box) return;
    box.addEventListener('click', e => {
        const b = e.target.closest('.method-btn'); if (!b) return;
        state.method = b.dataset.method;
        syncFields();
        const h = $('formHint'); if (h) h.textContent = '';
    });
}

function bindToggle(app) {
    const foot = $('formFoot');
    if (!foot) return;
    const toggle = () => {
        state.isReg = !state.isReg;
        const sub = $('formSub'), btn = $('submitBtn');
        if (sub) sub.textContent = state.isReg ? '注册账号，记录你的每一次分享' : '登录后查看收藏、消息与你的创作';
        if (btn && !btn.dataset.goHome) btn.textContent = state.isReg ? '注册' : '登录';
        foot.innerHTML = state.isReg
            ? '已有账号？<a href="javascript:;" id="toggleReg">返回登录</a>'
            : '还没有账号？<a href="javascript:;" id="toggleReg">立即注册</a>';
        syncFields();
    };
    foot.addEventListener('click', e => { if (e.target.closest('#toggleReg')) { e.preventDefault(); toggle(); } });
}

function bindCodeBtn(app) {
    const btn = $('codeBtn');
    if (!btn) return;
    btn.addEventListener('click', async () => {
        if (state.counting) return;
        const phone = ($('account')?.value || '').trim();
        if (!/^1\d{10}$/.test(phone)) { app.showToast('请输入正确的手机号'); return; }
        try {
            const d = await app.request('/auth/phone-code', { method: 'POST', body: { phone } });
            // 本地调试环境会回传 dev_code，自动填入方便联调
            const dev = d && (d.dev_code || d.code);
            if (dev && $('code')) $('code').value = dev;
            app.showToast(dev ? '验证码已发送（已自动填入）' : '验证码已发送');
        } catch (e) {
            app.showToast(e.message || '验证码发送失败');
            return;
        }
        let n = 60;
        state.counting = true;
        btn.disabled = true;
        btn.textContent = n + 's 后重发';
        const timer = setInterval(() => {
            n--;
            btn.textContent = n + 's 后重发';
            if (n <= 0) {
                clearInterval(timer);
                state.counting = false;
                btn.disabled = false;
                btn.textContent = '获取验证码';
            }
        }, 1000);
    });
}

function bindForm(app) {
    const form = $('loginForm');
    if (!form) return;
    form.addEventListener('submit', e => { e.preventDefault(); submit(app); });
    const btn = $('submitBtn');
    if (btn) btn.addEventListener('click', e => { e.preventDefault(); submit(app); });
}

async function submit(app) {
    const btn = $('submitBtn');
    if (btn && btn.dataset.goHome) { location.href = state.returnTo; return; }
    if (state.submitting) return;
    const account = ($('account')?.value || '').trim();
    const agree = $('agree');
    if (!account) { app.showToast('请填写账号'); return; }
    if (agree && !agree.checked) { app.showToast('请先勾选同意用户协议'); return; }

    let url, body, okMsg;
    if (state.method === 'code') {
        const code = ($('code')?.value || '').trim();
        if (!/^1\d{10}$/.test(account)) { app.showToast('请输入正确的手机号'); return; }
        if (!code) { app.showToast('请填写验证码'); return; }
        url = '/auth/login-phone'; body = { phone: account, code };
        okMsg = state.isReg ? '注册成功，欢迎加入' : '登录成功';
    } else if (state.isReg) {
        const pwd = $('pwd')?.value || '';
        const pwd2 = $('pwdConfirm')?.value || '';
        const email = ($('email')?.value || '').trim();
        if (!pwd || pwd.length < 6) { app.showToast('密码至少 6 位'); return; }
        if (pwd !== pwd2) { app.showToast('两次密码不一致'); return; }
        if (!/^\S+@\S+\.\S+$/.test(email)) { app.showToast('请填写正确的邮箱'); return; }
        url = '/auth/register';
        body = { username: account, email, password: pwd, password_confirm: pwd2 };
        okMsg = '注册成功，欢迎加入';
    } else {
        const pwd = $('pwd')?.value || '';
        if (!pwd) { app.showToast('请填写密码'); return; }
        url = '/auth/login'; body = { username: account, password: pwd };
        okMsg = '登录成功';
    }

    state.submitting = true;
    if (btn) { btn.disabled = true; btn.dataset.old = btn.textContent; btn.textContent = '请稍候…'; }
    try {
        const d = await app.request(url, { method: 'POST', body });
        applyLogin(app, d);
        app.showToast(okMsg);
        const hint = $('formHint');
        if (hint) hint.textContent = okMsg + '，正在跳转…';
        setTimeout(() => { location.href = state.returnTo; }, 700);
    } catch (e) {
        app.showToast(e.message || '操作失败，请重试');
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.old || '登录'; }
    } finally {
        state.submitting = false;
    }
}

function applyLogin(app, d) {
    app.user = (d && d.user) || null;
    app.authed = true;
    if (d && d.token) {
        app.token = d.token;
        try { localStorage.setItem('hublog_token', d.token); } catch (e) {}
    }
    try { localStorage.setItem('hublog_user', JSON.stringify(app.user)); } catch (e) {}
    if (app.fetchCsrf) app.fetchCsrf().catch(() => {});
    if (app.updateAuthUI) app.updateAuthUI();
}

function bindMisc(app) {
    document.querySelectorAll('[data-agree]').forEach(a => a.addEventListener('click', e => {
        e.preventDefault(); app.showToast(a.dataset.agree + '（由站点管理员配置）');
    }));
    document.querySelectorAll('[data-third]').forEach(b => b.addEventListener('click', async () => {
        const name = b.dataset.third;
        try {
            const d = await app.request('/auth/oauth/providers');
            const list = (d && (d.list || d.providers)) || [];
            if (Array.isArray(list) && list.length) {
                const p = list[0];
                const provider = typeof p === 'string' ? p : (p.id || p.name);
                location.href = (window.HUBLOG_BASE_URL || '/') + 'api/index.php/auth/oauth/' + encodeURIComponent(provider);
            } else app.showToast('站点未开启' + name + '，请用手机号或密码登录');
        } catch (e) {
            app.showToast('站点未开启' + name + '，请用手机号或密码登录');
        }
    }));
}

async function loadStats(app) {
    const box = $('brandPoints');
    if (!box) return;
    try {
        const d = await app.request('/site/stats');
        const s = d || {};
        box.innerHTML = `<span><b>${fmt(s.total_posts)}</b>篇内容</span>`
            + `<span><b>${fmt(s.total_users)}</b>位作者</span>`
            + `<span><b>${fmt(s.total_comments)}</b>条讨论</span>`;
    } catch (e) { /* 保留占位 */ }
}
function fmt(n) {
    n = Number(n) || 0;
    return n >= 10000 ? (n / 10000).toFixed(1).replace(/\.0$/, '') + '万' : String(n);
}
