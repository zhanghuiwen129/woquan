/**
 * woquan 账户设置中心
 *
 * 覆盖：个人资料（昵称/签名/邮箱/性别/生日/地区/标签）、头像与封面上传、
 *       修改密码、隐私与通知开关、退出登录。
 * 端点：GET /auth/profile、PUT /auth/profile、PUT /auth/password、
 *       POST /auth/avatar、POST /auth/cover、POST /auth/logout。
 *
 * 面板 DOM 运行时注入，22 个页面无需各自加骨架；所有用户数据均用 .value / textContent
 * 写入，不拼进 innerHTML，避免资料字段成为 XSS 入口。
 */
import { PAGE_BASE } from './config.js?v=20261117.02';

let modal = null;
let activeTab = 'profile';
let profileCache = null;
let initialProfile = null;

const GENDER_OPTIONS = [['0', '未设置'], ['1', '男'], ['2', '女']];
const PRIVACY_OPTIONS = [['public', '所有人可见'], ['followers', '仅关注者可见'], ['private', '仅自己可见']];
const SWITCHES = [
    ['allow_search', '允许被搜索', '关闭后别人搜不到你的主页'],
    ['allow_mention', '允许被 @ 提及', '关闭后他人无法在内容中 @ 你'],
    ['allow_stranger_msg', '允许陌生人私信', '关闭后仅相互关注的人可以发消息'],
    ['hide_online', '隐藏在线状态', '不在主页与列表里显示最近活跃时间'],
];

const SHELL = `
<div class="acct-modal" id="acctModal" role="dialog" aria-modal="true" aria-label="账户设置">
  <div class="acct-box">
    <div class="acct-head">
      <h2>账户设置</h2>
      <button class="acct-close" id="acctClose" aria-label="关闭">✕</button>
    </div>
    <div class="acct-tabs">
      <button class="acct-tab active" data-acct-tab="profile">个人资料</button>
      <button class="acct-tab" data-acct-tab="security">账号安全</button>
      <button class="acct-tab" data-acct-tab="privacy">隐私设置</button>
    </div>

    <div class="acct-body">
      <!-- ===== 个人资料 ===== -->
      <section class="acct-pane active" data-acct-pane="profile">
        <div class="acct-media-row">
          <span class="acct-avatar" id="acctAvatar">?</span>
          <div class="acct-media-ops">
            <div class="acct-media-btns">
              <button class="btn-ghost" type="button" id="acctAvatarBtn">更换头像</button>
              <button class="btn-ghost" type="button" id="acctCoverBtn">更换封面</button>
            </div>
            <div class="acct-hint">支持 JPG / PNG / GIF / WebP，建议头像为正方形</div>
            <input type="file" id="acctAvatarFile" accept="image/*" hidden>
            <input type="file" id="acctCoverFile" accept="image/*" hidden>
          </div>
        </div>
        <div class="field"><label for="acctNickname">昵称</label><input id="acctNickname" type="text" maxlength="50" autocomplete="nickname"></div>
        <div class="field"><label for="acctBio">个性签名（最多 20 字）</label><textarea id="acctBio" maxlength="20" rows="2"></textarea></div>
        <div class="field"><label for="acctEmail">邮箱</label><input id="acctEmail" type="email" autocomplete="email"></div>
        <div class="acct-grid">
          <div class="field"><label for="acctGender">性别</label><select id="acctGender"></select></div>
          <div class="field"><label for="acctBirthday">生日</label><input id="acctBirthday" type="date"></div>
          <div class="field"><label for="acctRegion">地区</label><input id="acctRegion" type="text" maxlength="50"></div>
          <div class="field"><label for="acctTags">个人标签</label><input id="acctTags" type="text" placeholder="用逗号分隔，最多 5 个"></div>
        </div>
      </section>

      <!-- ===== 账号安全 ===== -->
      <section class="acct-pane" data-acct-pane="security">
        <div class="acct-note">修改密码后当前登录态会立即失效，需要用新密码重新登录。</div>
        <div class="field"><label for="acctOldPwd">原密码</label><input id="acctOldPwd" type="password" autocomplete="current-password"></div>
        <div class="field"><label for="acctNewPwd">新密码</label><input id="acctNewPwd" type="password" autocomplete="new-password"></div>
        <div class="field"><label for="acctNewPwd2">确认新密码</label><input id="acctNewPwd2" type="password" autocomplete="new-password"></div>
        <div class="acct-hint">密码需包含大小写字母与数字，长度不少于 8 位</div>
      </section>

      <!-- ===== 隐私设置 ===== -->
      <section class="acct-pane" data-acct-pane="privacy">
        <div class="field">
          <label for="acctPrivacyHome">主页可见范围</label>
          <select id="acctPrivacyHome"></select>
        </div>
        <div class="acct-switches" id="acctSwitches"></div>
      </section>
    </div>

    <div class="acct-foot">
      <div class="acct-foot-right">
        <button class="btn-ghost" type="button" id="acctCancel">取消</button>
        <button class="btn-primary" type="button" id="acctSave">保存</button>
      </div>
    </div>
  </div>
</div>`;

function $(id) { return document.getElementById(id); }

/** 读取「个人资料」页当前表单值（fill 与 save 共用同一套口径，避免脏检查误判） */
function readProfile() {
    return {
        nickname: $('acctNickname').value.trim(),
        bio: $('acctBio').value.trim(),
        email: $('acctEmail').value.trim(),
        gender: Number($('acctGender').value),
        birthday: $('acctBirthday').value,
        region: $('acctRegion').value.trim(),
        user_tags: $('acctTags').value.trim(),
    };
}

function build(app) {
    if ($('acctModal')) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = SHELL;
    modal = wrap.firstElementChild;
    document.body.appendChild(modal);

    // 下拉选项（静态，无用户数据）
    $('acctGender').innerHTML = GENDER_OPTIONS.map(([v, t]) => `<option value="${v}">${t}</option>`).join('');
    $('acctPrivacyHome').innerHTML = PRIVACY_OPTIONS.map(([v, t]) => `<option value="${v}">${t}</option>`).join('');
    $('acctSwitches').innerHTML = SWITCHES.map(([key, title, desc]) => `
      <label class="acct-switch">
        <span class="acct-switch-txt"><b>${title}</b><small>${desc}</small></span>
        <input type="checkbox" data-switch="${key}"><span class="acct-switch-ui"></span>
      </label>`).join('');

    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    $('acctClose').onclick = close;
    $('acctCancel').onclick = close;

    modal.querySelectorAll('.acct-tab').forEach(btn => {
        btn.onclick = () => switchTab(btn.dataset.acctTab);
    });

    $('acctAvatarBtn').onclick = () => $('acctAvatarFile').click();
    $('acctCoverBtn').onclick = () => $('acctCoverFile').click();
    $('acctAvatarFile').onchange = e => upload(app, 'avatar', e.target.files[0]);
    $('acctCoverFile').onchange = e => upload(app, 'cover', e.target.files[0]);

    $('acctSave').onclick = () => save(app);
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal.classList.contains('active')) close();
    });
}

function close() {
    if (modal) modal.classList.remove('active');
}

function switchTab(tab) {
    activeTab = tab;
    if (!modal) return;
    modal.querySelectorAll('.acct-tab').forEach(b => b.classList.toggle('active', b.dataset.acctTab === tab));
    modal.querySelectorAll('.acct-pane').forEach(p => p.classList.toggle('active', p.dataset.acctPane === tab));
    const saveBtn = $('acctSave');
    if (saveBtn) saveBtn.textContent = tab === 'security' ? '修改密码' : '保存';
}

/** 头像渲染：无头像时显示昵称首字 */
function paintAvatar(app) {
    const box = $('acctAvatar');
    if (!box) return;
    const u = app.user || {};
    const url = app.resolveAvatar(u.avatar);
    box.innerHTML = '';
    if (u.avatar) {
        const img = document.createElement('img');
        img.src = url; img.alt = '';
        img.onerror = () => { box.textContent = (u.nickname || u.username || '?')[0]; };
        box.appendChild(img);
    } else {
        box.textContent = (u.nickname || u.username || '?')[0];
    }
}

function fill(app, data) {
    profileCache = data || {};
    const set = (id, v) => { const el = $(id); if (el) el.value = v ?? ''; };
    set('acctNickname', data.nickname || data.username);
    set('acctBio', data.bio);
    set('acctEmail', data.email);
    set('acctGender', String(data.gender ?? 0));
    set('acctBirthday', data.birthday);
    set('acctRegion', data.region);
    set('acctTags', data.user_tags);
    set('acctPrivacyHome', data.privacy_home || 'public');
    SWITCHES.forEach(([key]) => {
        const el = modal.querySelector(`[data-switch="${key}"]`);
        if (el) el.checked = Number(data[key] ?? 1) === 1;
    });
    initialProfile = readProfile();
    paintAvatar(app);
}

/** 打开账户设置面板 */
export async function openAccountPanel(app, tab = 'profile') {
    if (!app.user) { app.openAuthModal(); return; }
    build(app);
    switchTab(tab);
    modal.classList.add('active');
    try {
        const data = await app.request('/auth/profile');
        fill(app, data || {});
    } catch (e) {
        app.showToast(e.message || '资料加载失败');
        fill(app, app.user || {});
    }
}

/** 上传头像 / 封面（multipart，api.request 会自动跳过 Content-Type） */
async function upload(app, kind, file) {
    if (!file) return;
    const input = $(kind === 'avatar' ? 'acctAvatarFile' : 'acctCoverFile');
    const maxMB = Number(app.siteConfig?.upload_max_avatar_size || 5);
    if (file.size > maxMB * 1024 * 1024) {
        app.showToast(`图片不能超过 ${maxMB}MB`);
        if (input) input.value = '';
        return;
    }
    const btn = $(kind === 'avatar' ? 'acctAvatarBtn' : 'acctCoverBtn');
    const oldText = btn ? btn.textContent : '';
    if (btn) { btn.textContent = '上传中…'; btn.disabled = true; }
    try {
        const fd = new FormData();
        fd.append(kind, file);
        const res = await app.request(kind === 'avatar' ? '/auth/avatar' : '/auth/cover', { method: 'POST', body: fd });
        const url = res && (res.avatar || res.cover);
        if (url) {
            app.user = { ...app.user, [kind]: url };
            try { localStorage.setItem('hublog_user', JSON.stringify(app.user)); } catch (e) {}
        }
        app.updateAuthUI();
        if (kind === 'avatar') paintAvatar(app);
        app.showToast(kind === 'avatar' ? '头像已更新' : '封面已更新');
    } catch (e) {
        app.showToast(e.message || '上传失败');
    } finally {
        if (btn) { btn.textContent = oldText; btn.disabled = false; }
        if (input) input.value = '';
    }
}

async function save(app) {
    const btn = $('acctSave');
    if (!btn || btn.disabled) return;

    if (activeTab === 'security') return savePassword(app, btn);

    let body;
    if (activeTab === 'privacy') {
        body = { privacy_home: $('acctPrivacyHome').value };
        SWITCHES.forEach(([key]) => {
            const el = modal.querySelector(`[data-switch="${key}"]`);
            body[key] = el && el.checked ? 1 : 0;
        });
    } else {
        const cur = readProfile();
        if (!cur.nickname) { app.showToast('昵称不能为空'); return; }

        // 只提交发生变化的字段。既有资料可能含不符合当前校验规则的历史值
        // （如本地种子账号 admin@localhost 不是合法邮箱、旧签名超过 20 字），
        // 全量回传会让后端把这些未修改的值重新校验一遍并直接 422 拒绝。
        const base = initialProfile || {};
        body = {};
        Object.keys(cur).forEach(k => {
            if (base[k] === undefined || cur[k] !== base[k]) body[k] = cur[k];
        });

        // 仅对本次真正修改的字段做前置校验，避免静默提交后拿到不明所以的 422
        if (body.bio !== undefined && Array.from(body.bio).length > 20) {
            app.showToast('个性签名最多 20 个字');
            return;
        }
        if (body.email !== undefined && body.email !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(body.email)) {
            app.showToast('邮箱格式不正确');
            return;
        }
        if (Object.keys(body).length === 0) {
            app.showToast('没有需要保存的修改');
            return;
        }
    }

    btn.disabled = true;
    const oldText = btn.textContent;
    btn.textContent = '保存中…';
    try {
        const updated = await app.request('/auth/profile', { method: 'PUT', body });
        app.user = { ...app.user, ...(updated || body) };
        try { localStorage.setItem('hublog_user', JSON.stringify(app.user)); } catch (e) {}
        app.updateAuthUI();
        // 同步个人主页上的展示（若当前就在 /u/ 页面）
        const nameEl = document.getElementById('profileName');
        if (nameEl && app.user.nickname) nameEl.textContent = app.user.nickname;
        const bioEl = document.getElementById('profileBio');
        if (bioEl && activeTab === 'profile') bioEl.textContent = app.user.bio || '这个人很懒，还没有填写简介';
        const avatarEl = document.getElementById('profileAvatar');
        if (avatarEl && app.user.avatar) {
            avatarEl.innerHTML = `<img src="${app.escapeHtml(app.resolveAvatar(app.user.avatar))}" alt="">`;
            avatarEl.style.background = 'transparent';
        }
        app.showToast('已保存');
        close();
    } catch (e) {
        app.showToast(e.message || '保存失败');
    } finally {
        btn.disabled = false;
        btn.textContent = oldText;
    }
}

async function savePassword(app, btn) {
    const oldPwd = $('acctOldPwd').value;
    const newPwd = $('acctNewPwd').value;
    const newPwd2 = $('acctNewPwd2').value;
    if (!oldPwd || !newPwd || !newPwd2) { app.showToast('请填写完整'); return; }
    if (newPwd !== newPwd2) { app.showToast('两次输入的新密码不一致'); return; }

    btn.disabled = true;
    btn.textContent = '提交中…';
    try {
        await app.request('/auth/password', {
            method: 'PUT',
            body: { old_password: oldPwd, new_password: newPwd, new_password_confirm: newPwd2 }
        });
        close();
        app.showToast('密码修改成功，请重新登录');
        // 后端递增 token_version，旧令牌立即失效，必须清态后回登录页
        setTimeout(() => app.logout(), 1200);
    } catch (e) {
        app.showToast(e.message || '修改失败');
    } finally {
        btn.disabled = false;
        btn.textContent = '修改密码';
    }
}

/** 供顶栏用户菜单调用的入口（避免 nav.js 直接依赖内部实现） */
export function openPanel(app, tab) { return openAccountPanel(app, tab); }
