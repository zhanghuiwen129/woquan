/**
 * shaiboblog 登录 / 注册页（login.html）
 * 路由：/login。对接 /auth/login、/auth/register、/auth/phone-code、
 * /auth/login-phone、/site/stats。验证码 60s 倒计时、手机号校验、协议勾选、注册互切。
 */
import { HOME_URL } from './config.js?v=20261116.12';

const $ = id => document.getElementById(id);
const state = { method: 'code', isReg: false, counting: false, submitting: false };

export async function renderLogin(app) {
    const brand = app.opt.brandName || '赛博博客';
    ['brandNameLeft', 'brandNameRight'].forEach(id => { const el = $(id); if (el) el.textContent = brand; });

    // 从「注册」入口进入时（/login?mode=register）直接展示注册表单
    const params = new URLSearchParams(location.search);
    state.isReg = !app.user && (params.get('mode') === 'register' || params.get('reg') === '1');
    // 注册默认走密码方式，才能填写用户名 / 邮箱 / 密码
    state.method = state.isReg ? 'pwd' : 'code';

    bindMethod(app);
    bindToggle(app);
    bindCodeBtn(app);
    bindForm(app);
    bindMisc(app);
    paintMode(app);
    loadStats(app);

    if (app.user) {
        const hint = $('formHint');
        if (hint) hint.textContent = '已登录为 ' + (app.user.nickname || app.user.username) + '，可直接进入社区';
        const btn = $('submitBtn');
        if (btn) { btn.textContent = '进入社区'; btn.dataset.goHome = '1'; }
    }
}

/** 登录 / 注册成功后的回跳地址：仅接受站内根相对路径，避免开放重定向 */
function nextUrl() {
    const next = new URLSearchParams(location.search).get('next') || '';
    if (next.startsWith('/') && !next.startsWith('//') && !next.startsWith('/\\')) return next;
    return HOME_URL;
}

/** 按 state 刷新标题、表单文案与页脚切换链接 */
function paintMode(app) {
    const brand = (app && app.opt && app.opt.brandName) || '赛博博客';
    document.title = (state.isReg ? '注册' : '登录') + ' · ' + brand;
    const sub = $('formSub'), btn = $('submitBtn');
    if (sub) sub.textContent = state.isReg ? '注册账号，记录你的每一次分享' : '登录后查看收藏、消息与你的创作';
    if (btn && !btn.dataset.goHome) btn.textContent = state.isReg ? '注册' : '登录';
    const foot = $('formFoot');
    if (foot) foot.innerHTML = state.isReg
        ? '已有账号？<a href="javascript:;" id="toggleReg">返回登录</a>'
        : '还没有账号？<a href="javascript:;" id="toggleReg">立即注册</a>';
    // 登录 / 注册两种状态下方法按钮文案保持语义一致
    document.querySelectorAll('.method-btn').forEach(b => {
        const code = b.dataset.method === 'code';
        b.textContent = code ? (state.isReg ? '验证码注册' : '验证码登录') : (state.isReg ? '密码注册' : '密码登录');
    });
    syncFields();
}

function syncFields() {
    const isCode = state.method === 'code';
    const show = (id, on) => { const el = $(id); if (el) el.style.display = on ? 'block' : 'none'; };
    show('codeField', isCode);
    show('pwdField', !isCode);
    show('confirmField', !isCode && state.isReg);
    show('emailField', !isCode && state.isReg);
    const label = $('accountLabel'), input = $('account');
    if (label) label.textContent = isCode ? '手机号' : (state.isReg ? '用户名' : '用户名 / 手机号');
    if (input) {
        input.placeholder = isCode ? '请输入手机号' : (state.isReg ? '设置用户名（登录用）' : '请输入用户名或手机号');
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
    foot.addEventListener('click', e => {
        if (!e.target.closest('#toggleReg')) return;
        e.preventDefault();
        state.isReg = !state.isReg;
        // 注册默认切到密码方式，登录则回到验证码方式
        state.method = state.isReg ? 'pwd' : 'code';
        const hint = $('formHint');
        if (hint) hint.textContent = '';
        paintMode(app);
    });
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
    // #submitBtn 本身是 submit 按钮，只监听表单 submit，避免一次点击提交两次
    form.addEventListener('submit', e => { e.preventDefault(); submit(app); });
}

async function submit(app) {
    const btn = $('submitBtn');
    if (btn && btn.dataset.goHome) { location.href = nextUrl(); return; }
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
        setTimeout(() => { location.href = nextUrl(); }, 700);
    } catch (e) {
        app.showToast(e.message || '操作失败，请重试');
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.old || (state.isReg ? '注册' : '登录'); }
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
        e.preventDefault(); app.showToast('站点未配置「' + a.dataset.agree + '」页面，请联系管理员');
    }));
    document.querySelectorAll('[data-third]').forEach(b => b.addEventListener('click', async () => {
        const name = b.dataset.third;
        try {
            // /auth/oauth/providers 会被通配路由当成 provider 名，后端返回 400；
            // 改用 /app/config 里真实存在的 wx_app_login / wx_mini_login 开关
            const cfg = await app.request('/app/config');
            if (!cfg.wx_app_login && !cfg.wx_mini_login) throw new Error('未配置');
            location.href = (window.HUBLOG_BASE_URL || '/') + 'api/index.php/auth/oauth/wechat';
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
