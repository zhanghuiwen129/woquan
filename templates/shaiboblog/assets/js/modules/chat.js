/**
 * 私信页：对接 /chat/conversations、/chat/messages、/chat/send。
 * 基础版：会话列表 + 消息记录 + 发送文本，不做轮询与群管理。
 */
import { PAGE_PATH } from './config.js?v=20261128.01';

export async function renderChat(app) {
    const mod = document.getElementById('page-module');
    if (!mod) return;

    if (!app.user) {
        mod.innerHTML = `
          <div class="module-head"><h1>私信</h1></div>
          <div class="module-card">
            <p style="color:var(--sub);font-size:14px;line-height:1.7">登录后即可查看和发送私信。</p>
            <button class="btn-primary" id="chatLogin" style="margin-top:16px">去登录</button>
          </div>`;
        const login = document.getElementById('chatLogin');
        if (login) login.addEventListener('click', () => app.goLogin());
        return;
    }

    mod.innerHTML = `
      <div class="module-head"><h1>私信</h1></div>
      <div class="chat-layout">
        <div class="chat-list" id="chatList"><div class="mod-empty">加载中…</div></div>
        <div class="chat-panel" id="chatPanel"><div class="mod-empty">选择一个会话开始聊天</div></div>
      </div>`;

    let currentId = 0;

    async function loadConversations() {
        const box = document.getElementById('chatList');
        if (!box) return;
        try {
            const d = await app.request('/chat/conversations?page=1&limit=20');
            const list = (d && d.list) || [];
            box.innerHTML = list.length
                ? list.map(c => {
                    const peer = c.peer || {};
                    const who = peer.nickname || peer.username || c.title || '会话';
                    const last = c.last_message?.content || '';
                    const unread = Number(c.unread_count || 0);
                    return `<button type="button" class="chat-conv" data-id="${c.id}">
                      <b>${app.escapeHtml(String(who))}${unread ? `<em>${unread}</em>` : ''}</b>
                      <span>${app.escapeHtml(String(last).slice(0, 40) || '暂无消息')}</span>
                    </button>`;
                }).join('')
                : '<div class="mod-empty">暂无会话</div>';
            box.querySelectorAll('.chat-conv').forEach(btn => {
                btn.addEventListener('click', () => {
                    currentId = Number(btn.dataset.id) || 0;
                    box.querySelectorAll('.chat-conv').forEach(b => b.classList.toggle('active', b === btn));
                    loadMessages(currentId);
                });
            });
        } catch (e) {
            box.innerHTML = `<div class="mod-empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
        }
    }

    async function loadMessages(id) {
        const panel = document.getElementById('chatPanel');
        if (!panel || !id) return;
        panel.innerHTML = '<div class="mod-empty">加载中…</div>';
        try {
            const d = await app.request('/chat/messages?conversation_id=' + id + '&page=1&limit=50');
            const list = (d && d.list) || [];
            panel.innerHTML = `
              <div class="chat-msgs" id="chatMsgs">${list.length
                ? list.map(m => `<div class="chat-msg ${m.is_self ? 'mine' : ''}">
                    <span>${app.escapeHtml(String(m.sender?.nickname || m.sender?.username || '对方'))}</span>
                    <p>${app.escapeHtml(String(m.content || ''))}</p>
                  </div>`).join('')
                : '<div class="mod-empty">暂无消息</div>'}</div>
              <div class="chat-compose">
                <input id="chatInput" type="text" placeholder="输入消息…" autocomplete="off">
                <button class="btn-primary" id="chatSend" type="button">发送</button>
              </div>`;
            const msgs = document.getElementById('chatMsgs');
            if (msgs) msgs.scrollTop = msgs.scrollHeight;
            const input = document.getElementById('chatInput');
            const send = document.getElementById('chatSend');
            const doSend = async () => {
                const content = (input?.value || '').trim();
                if (!content) return;
                if (send) send.disabled = true;
                try {
                    await app.request('/chat/send', {
                        method: 'POST',
                        body: { conversation_id: id, content, type: 'text' },
                    });
                    await loadMessages(id);
                } catch (e) {
                    app.showToast(e.message || '发送失败');
                    if (send) send.disabled = false;
                }
            };
            if (send) send.addEventListener('click', doSend);
            if (input) {
                input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); doSend(); } });
            }
        } catch (e) {
            panel.innerHTML = `<div class="mod-empty">${app.escapeHtml(e.message || '加载失败')}</div>`;
        }
    }

    await loadConversations();
    // 从个人主页「私信」入口带过来的会话 id，自动打开
    const preset = Number(new URLSearchParams(location.search).get('c') || 0);
    if (preset) {
        const target = document.querySelector('.chat-conv[data-id="' + preset + '"]');
        if (target) target.click();
        else await loadMessages(preset);
    }
}
