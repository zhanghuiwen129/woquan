/**
 * shaiboblog 写作页：Markdown 编辑器
 * 支持工具栏、图片上传、标签、原创/置顶/评论/定时发布与本地草稿。
 */
import { PAGE_PATH, HOME_URL } from './config.js?v=20261116.12';
import { safeUrl, escapeAttr } from './utils.js?v=20261116.12';

const DRAFT_KEY = 'shaibo_write_draft';
const state = { tags: [], submitting: false, catId: '', media: { video: [], attachment: [] } };

const $ = id => document.getElementById(id);

function escapeText(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}

function wordCount(md) {
    return String(md || '')
        .replace(/```[\s\S]*?```/g, '')
        .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
        .replace(/\[\[media:(image|video|attachment):\d+\]\]/g, '')
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
        .replace(/[#>*_`~\-]/g, '')
        .replace(/\s/g, '').length;
}

function firstImage(md) {
    const m = String(md || '').match(/!\[[^\]]*\]\(([^)\s]+)[^)]*\)/);
    return m ? m[1] : '';
}

function insertAtCursor(el, text) {
    const start = el.selectionStart ?? el.value.length;
    const end = el.selectionEnd ?? el.value.length;
    el.value = el.value.slice(0, start) + text + el.value.slice(end);
    el.selectionStart = el.selectionEnd = start + text.length;
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

function wrapSelection(el, before, after, placeholder) {
    const start = el.selectionStart ?? 0;
    const end = el.selectionEnd ?? 0;
    const sel = el.value.slice(start, end) || placeholder || '';
    el.value = el.value.slice(0, start) + before + sel + after + el.value.slice(end);
    el.selectionStart = start + before.length;
    el.selectionEnd = start + before.length + sel.length;
    el.focus();
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

function bindToolbar(app, input) {
    const bar = $('toolbar');
    if (!bar) return;
    const emojiPop = document.createElement('div');
    emojiPop.className = 'emoji-pop';
    emojiPop.hidden = true;
    emojiPop.innerHTML = ['🙂', '😂', '👍', '🎣', '🌙', '💡', '🔥', '✨', '🐟', '📝', '⚙️', '🌊']
        .map(e => `<button type="button" data-emoji="${e}">${e}</button>`).join('');
    bar.appendChild(emojiPop);
    emojiPop.addEventListener('click', e => {
        const b = e.target.closest('[data-emoji]');
        if (!b) return;
        insertAtCursor(input, b.dataset.emoji);
        emojiPop.hidden = true;
    });

    bar.addEventListener('click', e => {
        const btn = e.target.closest('[data-md]');
        if (!btn) return;
        e.preventDefault();
        const cmd = btn.dataset.md;
        input.focus();
        if (cmd === 'h2') wrapSelection(input, '\n## ', '\n', '小标题');
        else if (cmd === 'bold') wrapSelection(input, '**', '**', '加粗文字');
        else if (cmd === 'italic') wrapSelection(input, '*', '*', '斜体文字');
        else if (cmd === 'quote') wrapSelection(input, '\n> ', '\n', '引用内容');
        else if (cmd === 'code') {
            const sel = input.value.slice(input.selectionStart ?? 0, input.selectionEnd ?? 0);
            insertAtCursor(input, sel.includes('\n') ? '\n```\n' + sel + '\n```\n' : '`' + (sel || 'code') + '`');
        } else if (cmd === 'ul') wrapSelection(input, '\n- ', '\n', '列表项');
        else if (cmd === 'link') {
            const url = window.prompt('输入链接地址：', 'https://');
            if (!url) return;
            const sel = input.value.slice(input.selectionStart ?? 0, input.selectionEnd ?? 0) || '链接文字';
            insertAtCursor(input, `[${sel}](${url})`);
        } else if (cmd === 'image') {
            pickImage(app, input);
        } else if (cmd === 'extimage') {
            // 外部图片：只接受 http/https，插入标准 Markdown 图片语法
            const raw = window.prompt('输入外部图片地址（http/https）：', 'https://');
            if (!raw) return;
            const url = safeUrl(raw.trim());
            if (!/^https?:\/\//i.test(url)) {
                app.showToast('图片地址需以 http:// 或 https:// 开头');
                return;
            }
            let alt = url.split('?')[0].split('/').pop() || '外部图片';
            try { alt = decodeURIComponent(alt); } catch (err) {}
            alt = alt.replace(/[\[\]()]/g, '').replace(/\.[^.]+$/, '').slice(0, 60) || '外部图片';
            const mdUrl = url.replace(/\)/g, '%29').replace(/\s/g, '%20');
            insertAtCursor(input, `\n![${alt}](${mdUrl})\n`);
            updateImageStrip(input);
            app.showToast('外部图片已插入正文');
        } else if (cmd === 'video' || cmd === 'attachment') {
            pickMedia(app, input, cmd);
        } else if (cmd === 'emoji') {
            emojiPop.hidden = !emojiPop.hidden;
        }
    });
}

async function uploadImageFile(app, input, file) {
    const maxMB = Number(app.siteConfig?.upload_max_size || 10);
    if (file.size > maxMB * 1024 * 1024) { app.showToast(`图片不能超过 ${maxMB}MB`); return; }
    const fd = new FormData();
    fd.append('image', file);
    app.showToast('图片上传中…');
    try {
        const res = await app.request('/posts/upload', { method: 'POST', body: fd });
        const url = res?.url || res?.data?.url || '';
        if (!url) throw new Error('上传未返回地址');
        insertAtCursor(input, `\n![${file.name.replace(/\.[^.]+$/, '')}](${url})\n`);
        updateImageStrip(input);
        app.showToast('图片已插入正文，下方预览可见');
    } catch (e) {
        // 登录失效时 api.js 已清空前端登录态，这里给出可操作的提示而不是静默失败
        app.showToast(e.code === 401 ? '登录已失效，请重新登录后再上传' : (e.message || '图片上传失败'));
    }
}

function pickImage(app, input) {
    if (!app.checkAuth()) return;
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.onchange = () => {
        const file = fileInput.files && fileInput.files[0];
        if (file) uploadImageFile(app, input, file);
    };
    fileInput.click();
}

/**
 * 视频 / 附件：上传成功后把 [[media:<type>:<index>]] 写进 textarea 光标处。
 * URL 同时暂存在 state.media，发布时随 videos / attachments 一起提交，
 * 否则 recordUploads 不会绑定 post_id，文件会被当成孤儿上传清理。
 */
function pickMedia(app, input, type) {
    if (!app.checkAuth()) return;
    // /site/config 返回扁平键；limits.* 嵌套是 App 端 /api/app/config 口径，Web 端无此结构
    const limits = app.siteConfig || {};
    const label = type === 'video' ? '视频' : '附件';
    const maxCount = Number(type === 'video' ? (limits.upload_max_videos ?? 3) : (limits.upload_max_attachments ?? 5));
    if (state.media[type].length >= maxCount) {
        app.showToast(`最多插入 ${maxCount} 个${label}`);
        return;
    }
    const picker = document.createElement('input');
    picker.type = 'file';
    picker.accept = type === 'video'
        ? 'video/*'
        : '.zip,.rar,.7z,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt';
    picker.onchange = async () => {
        const file = picker.files && picker.files[0];
        if (!file) return;
        const maxMB = Number(limits[type === 'video' ? 'upload_max_video_size' : 'upload_max_attachment_size'] || 50);
        if (file.size > maxMB * 1024 * 1024) { app.showToast(`${label}不能超过 ${maxMB}MB`); return; }
        const fd = new FormData();
        fd.append(type, file);
        app.showToast(`${label}上传中…`);
        try {
            const res = await app.request(type === 'video' ? '/posts/upload-video' : '/posts/upload-attachment',
                { method: 'POST', body: fd });
            const d = (res && res.data) || res || {};
            const url = d.url || '';
            if (!url) throw new Error('上传未返回地址');
            const item = type === 'video'
                ? { url, cover: d.cover || '', duration: Number(d.duration || 0) }
                : { url, filename: d.filename || file.name, size: Number(d.size || file.size) };
            state.media[type].push(item);
            insertAtCursor(input, `\n[[media:${type}:${state.media[type].length - 1}]]\n`);
            app.showToast(`${label}已插入正文光标处`);
        } catch (e) {
            app.showToast(e.message || `${label}上传失败`);
        }
    };
    picker.click();
}

/**
 * 发布前按占位符在正文里的出现顺序重排媒体数组并重编号。
 * 用户删掉正文里的某个占位符时，对应媒体不会进最终数组，索引自动收缩。
 */
function normalizeMedia(md) {
    const ordered = { video: [], attachment: [] };
    const text = String(md || '').replace(/\[\[media:(video|attachment):(\d+)\]\]/g, (m, type, idx) => {
        const item = state.media[type][Number(idx)];
        if (!item) return m;
        let at = ordered[type].indexOf(item);
        if (at < 0) { ordered[type].push(item); at = ordered[type].length - 1; }
        return `[[media:${type}:${at}]]`;
    });
    return { text, videos: ordered.video, attachments: ordered.attachment };
}

async function loadCategories(app) {
    const box = $('catSelect');
    if (!box) return;
    let cats = [];
    try { const d = await app.request('/app/categories'); cats = (d && d.list) || []; }
    catch (e) { cats = app.siteConfig?.categories || []; }
    box.innerHTML = '<option value="">不分类</option>' + cats.map(c =>
        `<option value="${c.id}">${escapeText(c.name)}</option>`).join('');
}

function renderTags() {
    const box = $('tagList');
    if (!box) return;
    box.innerHTML = state.tags.map(t =>
        `<span class="tag-chip">${escapeText(t)}<button type="button" data-tag-del="${escapeAttr(t)}" aria-label="移除标签">×</button></span>`).join('');
    box.querySelectorAll('[data-tag-del]').forEach(btn => {
        btn.onclick = () => {
            state.tags = state.tags.filter(x => x !== btn.dataset.tagDel);
            renderTags();
        };
    });
}

function addTag(app, value) {
    const tag = String(value || '').trim().replace(/^#/, '').slice(0, 20);
    if (!tag) return;
    if (state.tags.includes(tag)) { app.showToast('标签已存在'); return; }
    if (state.tags.length >= 5) { app.showToast('最多添加 5 个标签'); return; }
    state.tags.push(tag);
    renderTags();
}

function bindTags(app) {
    const input = $('tagInput');
    if (!input) return;
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ',' || e.key === '，') {
            e.preventDefault();
            addTag(app, input.value);
            input.value = '';
        }
    });
}

function updateCount(input) {
    const n = wordCount(input.value);
    const el = $('wordCount');
    if (el) el.textContent = String(n);
    const stateEl = $('editorState');
    if (stateEl && stateEl.dataset.saved) stateEl.textContent = stateEl.dataset.saved;
    updateCover(input);
    updateImageStrip(input);
}

function updateCover(input) {
    const box = $('coverPreview');
    if (!box) return;
    const raw = firstImage(input.value);
    const url = raw ? safeUrl(raw) : '';
    if (url) {
        box.innerHTML = `<img src="${escapeAttr(url)}" alt="封面预览" loading="lazy">`;
    } else {
        box.innerHTML = '<span>正文首图将作为封面</span>';
    }
}

/**
 * 正文图片预览条：编辑器是纯 Markdown textarea，上传后源码里只有 ![...](url)，
 * 用户看不到图片会以为「没反应」。这里从源码实时解析出缩略图，支持点击定位与移除。
 * 容器动态创建，不写进 HTML 骨架，避免破坏主题契约。
 */
function updateImageStrip(input) {
    let strip = $('imageStrip');
    if (!strip) {
        strip = document.createElement('div');
        strip.id = 'imageStrip';
        strip.className = 'md-image-strip';
        strip.hidden = true;
        input.insertAdjacentElement('afterend', strip);
    }
    const matches = [...String(input.value || '').matchAll(/!\[([^\]]*)\]\(([^)\s]+)[^)]*\)/g)];
    if (!matches.length) { strip.hidden = true; strip.innerHTML = ''; return; }
    strip.hidden = false;
    const items = matches.map(m => ({ full: m[0], start: m.index, end: m.index + m[0].length, url: m[2] }));
    strip.innerHTML = '<div class="strip-label">正文图片 · ' + items.length + ' 张</div><div class="strip-list">'
        + items.map((it, i) => {
            const safe = safeUrl(it.url);
            const alt = escapeAttr(it.url.split('/').pop() || ('图片 ' + (i + 1)));
            return '<div class="strip-item" data-idx="' + i + '" title="点击定位到正文源码">'
                + (safe ? `<img src="${escapeAttr(safe)}" alt="${alt}" loading="lazy">` : '<span class="strip-broken">?</span>')
                + '<button type="button" class="strip-del" data-idx="' + i + '" title="移除这张图片" aria-label="移除图片">×</button>'
                + '</div>';
        }).join('') + '</div>';
    strip.querySelectorAll('.strip-item').forEach((el, i) => {
        el.addEventListener('click', () => {
            const it = items[i];
            input.focus();
            input.setSelectionRange(it.start, it.end);
        });
    });
    strip.querySelectorAll('.strip-del').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const it = items[Number(btn.dataset.idx)];
            if (!it) return;
            input.value = input.value.slice(0, it.start) + input.value.slice(it.end);
            input.focus();
            input.setSelectionRange(it.start, it.start);
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
}

function saveDraft(app, silent) {
    const body = {
        title: $('titleInput')?.value || '',
        content: $('bodyInput')?.value || '',
        tags: state.tags.slice(),
        catId: $('catSelect')?.value || '',
        original: !!$('originalSwitch')?.checked,
        pinned: !!$('pinSwitch')?.checked,
        allowComment: $('commentSwitch') ? !!$('commentSwitch').checked : true,
        media: { video: state.media.video.slice(), attachment: state.media.attachment.slice() },
    };
    try { localStorage.setItem(DRAFT_KEY, JSON.stringify(body)); } catch (e) {}
    const stateEl = $('editorState');
    if (stateEl && !silent) {
        const t = new Date().toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' });
        stateEl.dataset.saved = `草稿已保存 · ${t}`;
        stateEl.textContent = stateEl.dataset.saved;
    } else if (stateEl) {
        stateEl.textContent = '自动暂存草稿';
    }
    if (!silent) app.showToast('草稿已保存到本地');
}

function restoreDraft() {
    let raw = '';
    try { raw = localStorage.getItem(DRAFT_KEY) || ''; } catch (e) {}
    if (!raw) return;
    try {
        const d = JSON.parse(raw);
        if ($('titleInput')) $('titleInput').value = d.title || '';
        if ($('bodyInput')) $('bodyInput').value = d.content || '';
        if (Array.isArray(d.tags)) { state.tags = d.tags.slice(0, 5); renderTags(); }
        if (d.media) {
            state.media.video = Array.isArray(d.media.video) ? d.media.video.slice() : [];
            state.media.attachment = Array.isArray(d.media.attachment) ? d.media.attachment.slice() : [];
        }
        if ($('catSelect') && d.catId) $('catSelect').value = d.catId;
        if ($('originalSwitch')) $('originalSwitch').checked = !!d.original;
        if ($('pinSwitch')) $('pinSwitch').checked = !!d.pinned;
        if ($('commentSwitch')) $('commentSwitch').checked = d.allowComment !== false;
    } catch (e) {}
}

function bindDraft(app) {
    document.querySelectorAll('[data-draft]').forEach(btn => {
        btn.onclick = () => saveDraft(app, false);
    });
    let timer;
    const auto = () => {
        clearTimeout(timer);
        timer = setTimeout(() => saveDraft(app, true), 1500);
    };
    ['titleInput', 'bodyInput'].forEach(id => {
        const el = $(id);
        if (el) el.addEventListener('input', auto);
    });
}

function bindPublish(app) {
    document.querySelectorAll('[data-publish]').forEach(btn => {
        btn.onclick = async () => {
            if (state.submitting) return;
            const title = ($('titleInput')?.value || '').trim();
            const md = $('bodyInput')?.value || '';
            const norm = normalizeMedia(md);
            const text = norm.text.replace(/\s/g, '');
            if (!title) { app.showToast('请先填写文章标题'); $('titleInput')?.focus(); return; }
            if (text.length < 10) { app.showToast('正文内容过少，建议不少于 10 字'); $('bodyInput')?.focus(); return; }
            if (wordCount(norm.text) > 2000) { app.showToast('正文超过 2000 字，请精简后再发布'); return; }
            if (!app.checkAuth()) return;

            const content = app.renderMarkdown(norm.text, PAGE_PATH);
            // 后端从正文 #话题# 中抽取标签，这里把编辑器里的标签写进正文末尾
            const tagLine = state.tags.length
                ? `<p>${state.tags.map(t => '#' + app.escapeHtml(t) + '#').join(' ')}</p>`
                : '';
            const images = [...norm.text.matchAll(/!\[[^\]]*\]\(([^)\s]+)[^)]*\)/g)]
                .map(m => m[1]).filter(u => /\/uploads\//.test(u));
            const body = { title, content: content + tagLine, category_id: $('catSelect')?.value || 0 };
            if (images.length) body.images = images;
            if (norm.videos.length) body.videos = norm.videos;
            if (norm.attachments.length) body.attachments = norm.attachments;
            if (state.tags.length) body.tags = state.tags;
            if ($('originalSwitch')?.checked) body.is_original = 1;
            if ($('commentSwitch') && !$('commentSwitch').checked) body.allow_comment = 0;
            if ($('pinSwitch')?.checked) body.is_pinned = 1;
            const sched = $('schedSwitch'), schedTime = $('schedTime');
            if (sched?.checked && schedTime?.value) body.scheduled_at = schedTime.value.replace('T', ' ');

            state.submitting = true;
            btn.disabled = true;
            const old = btn.textContent;
            btn.textContent = '发布中…';
            try {
                const res = await app.request('/posts', { method: 'POST', body });
                try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
                app.showToast(res && res.pending_review ? '已提交，等待审核' : '发布成功');
                const id = res && (res.id || res.post_id);
                setTimeout(() => { location.href = id ? (PAGE_PATH + '/p/' + id) : HOME_URL; }, 600);
            } catch (e) {
                app.showToast(e.message || '发布失败，请稍后重试');
                btn.textContent = old;
                btn.disabled = false;
            } finally {
                state.submitting = false;
            }
        };
    });
}

function bindSchedule() {
    const sched = $('schedSwitch'), time = $('schedTime');
    if (!sched || !time) return;
    sched.addEventListener('change', () => {
        time.hidden = !sched.checked;
        if (!sched.checked) time.value = '';
    });
}

export async function renderWrite(app) {
    const input = $('bodyInput');
    if (!input) return;

    await loadCategories(app);
    restoreDraft();
    bindToolbar(app, input);
    bindTags(app);
    bindDraft(app);
    bindPublish(app);
    bindSchedule();

    const tagParam = new URLSearchParams(location.search).get('tag');
    if (tagParam) addTag(app, tagParam);

    input.addEventListener('input', () => updateCount(input));
    // 直接粘贴剪贴板图片也走同一上传链路；普通文本粘贴仍照常更新字数与预览
    input.addEventListener('paste', e => {
        const file = Array.from(e.clipboardData?.items || [])
            .filter(it => it && it.type && it.type.startsWith('image/'))
            .map(it => it.getAsFile())
            .find(Boolean);
        if (file && app.checkAuth()) {
            e.preventDefault();
            uploadImageFile(app, input, file);
        } else {
            setTimeout(() => updateCount(input), 0);
        }
    });
    updateCount(input);

    if (!app.user) {
        const stateEl = $('editorState');
        if (stateEl) stateEl.textContent = '登录后即可发布';
    }
}
