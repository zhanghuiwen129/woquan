/**
 * zixunfeed 写文章页（write.html）
 * 路由：/write。富文本工具栏 / 封面自动提取 / 标签 / 摘要 /
 * 存草稿（localStorage）/ 预览，发布对接 POST /posts。
 */
import { PAGE_BASE, HOME_URL, PAGE_PATH } from './config.js?v=20261117.02';
import { safeUrl } from './utils.js?v=20261117.02';

const DRAFT_KEY = 'zixun_draft';
const EMOJIS = ['😀', '😄', '😍', '👍', '🎣', '🐟', '🌊', '☀️', '📷', '✨', '🔥', '💚'];
const state = { catId: '', tags: [], submitting: false, media: { video: [], attachment: [] } };
// 文件选择框会让 contenteditable 失焦并丢掉选区，插入媒体前必须缓存 Range
let savedRange = null;

const $ = id => document.getElementById(id);

/** 缓存当前光标位置；点工具栏弹文件框后选区会丢，插入前必须先存 */
function captureRange(editor) {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) return;
    const r = sel.getRangeAt(0);
    if (editor.contains(r.commonAncestorContainer)) savedRange = r.cloneRange();
}

/** 把节点插到缓存的光标处；不依赖 execCommand，保证占位符是纯文本节点 */
function insertNodeAtRange(editor, node) {
    editor.focus();
    const sel = window.getSelection();
    let r = savedRange;
    if (!r || !editor.contains(r.commonAncestorContainer)) {
        r = document.createRange();
        r.selectNodeContents(editor);
        r.collapse(false);
    }
    r.deleteContents();
    r.insertNode(node);
    r.setStartAfter(node);
    r.collapse(true);
    if (sel) { sel.removeAllRanges(); sel.addRange(r); }
    savedRange = r.cloneRange();
}

export async function renderWrite(app) {
    document.title = '写文章 · ' + (app.opt.brandName || 'woquan');
    const editor = $('bodyInput');
    if (!editor) return;

    bindChrome(app);
    bindToolbar(app, editor);
    bindTags(app);
    bindSummary(app);
    bindSwitches(app);
    bindPreview(app, editor);
    bindPublish(app, editor);
    bindDraft(app, editor);

    await loadCategories(app);
    restoreDraft(app, editor);
    const q = new URLSearchParams(location.search).get('tag');
    if (q) addTag(app, q);

    if (typeof MutationObserver !== 'undefined') {
        const ob = new MutationObserver(() => { updateCount(editor); updateCover(editor); });
        ob.observe(editor, { childList: true, subtree: true, characterData: true, attributes: true });
    }
    editor.addEventListener('input', () => { updateCount(editor); updateCover(editor); });
    updateCount(editor);
    updateCover(editor);
}

function collectData(editor) {
    const title = ($('titleInput')?.value || '').trim();
    const norm = normalizeMedia(editor.innerHTML.trim());
    const html = norm.html;
    const text = (editor.innerText || editor.textContent || '')
        .replace(/\[\[media:(image|video|attachment):\d+\]\]/g, '')
        .replace(/\s/g, '');
    const imgs = [...editor.querySelectorAll('img')].map(i => i.getAttribute('src') || '').filter(Boolean);
    // 仅把本站 uploads 下的图片提交给后端（外链图片会被 uploads 归属校验拒绝）
    const localImgs = imgs.filter(u => /\/uploads\//.test(u));
    return {
        title, content: html, text,
        category_id: state.catId || undefined,
        images: localImgs,
        videos: norm.videos,
        attachments: norm.attachments,
        tags: state.tags.slice(),
        summary: ($('summaryInput')?.value || '').trim(),
    };
}

/* ===== 通用交互 ===== */
function bindChrome(app) {
    const back = $('backBtn');
    if (back) back.onclick = () => {
        if (window.history.length > 1) window.history.back();
        else location.href = HOME_URL;
    };
    const brand = $('brandName');
    if (brand) brand.textContent = app.opt.brandName || 'woquan';
}

function exec(editor, cmd, arg) {
    try { editor.focus(); } catch (e) {}
    if (typeof document.execCommand === 'function') {
        try { document.execCommand(cmd, false, arg); } catch (e) {}
    }
    updateCount(editor); updateCover(editor);
}

function bindToolbar(app, editor) {
    const bar = $('toolbar');
    if (!bar) return;
    bar.addEventListener('click', e => {
        const btn = e.target.closest('.tool');
        if (!btn) return;
        e.preventDefault();
        if (btn.dataset.cmd) {
            const cmd = btn.dataset.cmd;
            if (cmd === 'createLink') {
                const url = window.prompt('输入链接地址：', 'https://');
                if (url) exec(editor, 'createLink', url);
                return;
            }
            exec(editor, cmd);
            return;
        }
        if (btn.dataset.block) {
            const tag = btn.dataset.block;
            exec(editor, 'formatBlock', tag === 'p' ? '<p>' : `<${tag}>`);
            return;
        }
        if (btn.dataset.insert) {
            const type = btn.dataset.insert;
            if (type === 'image') {
                // 只允许本地上传，禁用外链图片输入
                if (!app.checkAuth()) return;
                captureRange(editor);
                pickAndUploadImage(app, editor, btn);
            } else if (type === 'video' || type === 'attachment') {
                captureRange(editor);
                pickMedia(app, editor, btn, type);
            } else {
                const em = EMOJIS[Math.floor(Math.random() * EMOJIS.length)];
                exec(editor, 'insertText', em);
            }
            return;
        }
    });
    const picker = $('colorPicker');
    if (picker) picker.addEventListener('input', e => exec(editor, 'foreColor', e.target.value));
}

/* ===== 图片：仅允许本地上传，杜绝外链 ===== */
let _imgPicker = null;
function pickAndUploadImage(app, editor, btn) {
    if (!_imgPicker) {
        _imgPicker = document.createElement('input');
        _imgPicker.type = 'file';
        _imgPicker.accept = 'image/*';
        _imgPicker.style.display = 'none';
        document.body.appendChild(_imgPicker);
        _imgPicker.addEventListener('change', async () => {
            const file = _imgPicker.files && _imgPicker.files[0];
            if (file) await uploadInlineImage(app, editor, file, btn);
            _imgPicker.value = '';
        });
    }
    _imgPicker.click();
}
async function uploadInlineImage(app, editor, file, btn) {
    const maxMB = Number(app.siteConfig?.upload_max_size || 10);
    if (file.size > maxMB * 1024 * 1024) { app.showToast(`图片不能超过 ${maxMB}MB`); return; }
    if (btn) btn.disabled = true;
    app.showToast('图片上传中…');
    try {
        const fd = new FormData();
        fd.append('image', file);
        const res = await app.request('/posts/upload', { method: 'POST', body: fd });
        const url = res && res.url;
        if (!url) throw new Error('上传未返回图片地址');
        const img = document.createElement('img');
        img.src = safeUrl(url) || url;
        img.alt = '';
        insertNodeAtRange(editor, img);
        app.showToast('图片已插入光标处，并自动同步到封面');
    } catch (e) {
        app.showToast(e.message || '图片上传失败');
    } finally {
        if (btn) btn.disabled = false;
    }
}

/** 视频 / 附件：上传成功后把纯文本占位符插到缓存的光标处 */
function pickMedia(app, editor, btn, type) {
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
        if (btn) btn.disabled = true;
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
            insertNodeAtRange(editor, document.createTextNode(`[[media:${type}:${state.media[type].length - 1}]]`));
            app.showToast(`${label}已插入光标处`);
        } catch (e) {
            app.showToast(e.message || `${label}上传失败`);
        } finally {
            if (btn) btn.disabled = false;
        }
    };
    picker.click();
}

/** 按正文占位符出现顺序重排媒体数组并重编号；删掉占位符的媒体不进最终数组 */
function normalizeMedia(html) {
    const ordered = { video: [], attachment: [] };
    const text = String(html || '').replace(/\[\[media:(video|attachment):(\d+)\]\]/g, (m, type, idx) => {
        const item = state.media[type][Number(idx)];
        if (!item) return m;
        let at = ordered[type].indexOf(item);
        if (at < 0) { ordered[type].push(item); at = ordered[type].length - 1; }
        return `[[media:${type}:${at}]]`;
    });
    return { html: text, videos: ordered.video, attachments: ordered.attachment };
}

function updateCount(editor) {
    const el = $('wordCount');
    const n = (editor.innerText || editor.textContent || '').replace(/\s/g, '').length;
    if (el) el.textContent = n + ' 字';
}
function updateCover(editor) {
    const first = editor.querySelector('img');
    const cur = $('coverCurrent'), empty = $('coverEmpty'), img = $('coverImg');
    if (!cur || !empty) return;
    if (first && first.getAttribute('src')) {
        if (img) img.src = first.getAttribute('src');
        cur.hidden = false; empty.hidden = true;
    } else {
        cur.hidden = true; empty.hidden = false;
    }
}

/* ===== 栏目 ===== */
async function loadCategories(app) {
    const box = $('catList');
    if (!box) return;
    let cats = [];
    try { const d = await app.request('/app/categories'); cats = (d && d.list) || []; }
    catch (e) { cats = app.siteConfig?.categories || []; }
    box.innerHTML = '<button class="cat-item active" data-cat="">未分类</button>'
        + cats.map(c => `<button class="cat-item" data-cat="${c.id}">${app.escapeHtml(c.name)}</button>`).join('');
    box.addEventListener('click', e => {
        const it = e.target.closest('.cat-item'); if (!it) return;
        box.querySelectorAll('.cat-item').forEach(x => x.classList.remove('active'));
        it.classList.add('active');
        state.catId = it.dataset.cat || '';
        app.showToast('已选择栏目：' + it.textContent);
    });
}

/* ===== 标签 ===== */
function addTag(app, val) {
    val = (val || '').trim().replace(/^#/, '');
    if (!val || state.tags.includes(val) || state.tags.length >= 8) return;
    state.tags.push(val);
    renderTags();
}
function renderTags() {
    const box = $('tagBox'), input = $('tagInput');
    if (!box || !input) return;
    box.querySelectorAll('.tag-chip').forEach(x => x.remove());
    state.tags.forEach(t => {
        const chip = document.createElement('span');
        chip.className = 'tag-chip';
        chip.innerHTML = `${escapeText(t)}<button type="button" data-tag-del>×</button>`;
        chip.querySelector('[data-tag-del]').addEventListener('click', () => {
            state.tags = state.tags.filter(x => x !== t);
            renderTags();
        });
        box.insertBefore(chip, input);
    });
}
function escapeText(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function bindTags(app) {
    const input = $('tagInput');
    if (!input) return;
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addTag(app, input.value); input.value = ''; }
    });
}

function bindSummary(app) {
    const ta = $('summaryInput'), cnt = $('summaryCount');
    if (ta && cnt) ta.addEventListener('input', () => { cnt.textContent = ta.value.length; });
}
function bindSwitches(app) {
    const sw = $('schedSwitch'), time = $('schedTime');
    if (sw && time) sw.addEventListener('change', () => {
        time.style.display = sw.checked ? 'block' : 'none';
        app.showToast(sw.checked ? '已开启定时发布，请选择时间' : '已关闭定时发布');
    });
}

/* ===== 预览 ===== */
function bindPreview(app, editor) {
    const btn = $('previewBtn'), box = $('previewBox'), close = $('previewClose');
    const t = $('previewTitle'), b = $('previewBody');
    if (!btn || !box) return;
    btn.onclick = () => {
        const show = box.style.display === 'none';
        if (show) {
            if (t) t.textContent = ($('titleInput')?.value || '').trim() || '（未填写标题）';
            if (b) b.textContent = (editor.innerText || editor.textContent || '').trim() || '（正文为空）';
            box.style.display = '';
            btn.textContent = '返回编辑';
        } else {
            box.style.display = 'none';
            btn.textContent = '预览';
        }
    };
    if (close) close.onclick = () => { box.style.display = 'none'; btn.textContent = '预览'; };
}

/* ===== 草稿 ===== */
function saveDraft(app, editor, silent) {
    try {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({
            title: $('titleInput')?.value || '', content: editor.innerHTML,
            summary: $('summaryInput')?.value || '', tags: state.tags,
            catId: state.catId, at: Date.now(),
            media: { video: state.media.video.slice(), attachment: state.media.attachment.slice() },
        }));
        if (!silent) app.showToast('草稿已保存');
    } catch (e) { if (!silent) app.showToast('草稿保存失败'); }
}
function restoreDraft(app, editor) {
    let d = null;
    try { d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null'); } catch (e) {}
    if (!d) return;
    if ($('titleInput')) $('titleInput').value = d.title || '';
    if ($('summaryInput')) $('summaryInput').value = d.summary || '';
    editor.innerHTML = d.content || '';
    state.tags = Array.isArray(d.tags) ? d.tags : [];
    state.catId = d.catId || '';
    if (d.media) {
        state.media.video = Array.isArray(d.media.video) ? d.media.video.slice() : [];
        state.media.attachment = Array.isArray(d.media.attachment) ? d.media.attachment.slice() : [];
    }
    renderTags();
    if ($('summaryCount')) $('summaryCount').textContent = (d.summary || '').length;
    const box = $('catList');
    if (box && state.catId) {
        box.querySelectorAll('.cat-item').forEach(x => x.classList.toggle('active', x.dataset.cat === state.catId));
    }
    app.showToast('已恢复上次草稿');
}
function bindDraft(app, editor) {
    document.querySelectorAll('[data-draft]').forEach(b => b.onclick = () => saveDraft(app, editor, false));
    // 编辑过程中自动暂存
    let timer;
    const auto = () => { clearTimeout(timer); timer = setTimeout(() => saveDraft(app, editor, true), 1500); };
    [$('titleInput'), editor, $('summaryInput')].forEach(el => { if (el) el.addEventListener('input', auto); });
}

/* ===== 发布 ===== */
function bindPublish(app, editor) {
    document.querySelectorAll('[data-publish]').forEach(btn => btn.onclick = async () => {
        if (state.submitting) return;
        const d = collectData(editor);
        if (!d.title) { app.showToast('请先填写文章标题'); $('titleInput')?.focus(); return; }
        if (d.text.length < 10) { app.showToast('正文内容过少，建议不少于 10 字'); editor.focus(); return; }
        if (!app.checkAuth()) return;
        if (d.text.length > 1900) { app.showToast('正文过长，请精简后再发布'); return; }

        const sched = $('schedSwitch'), schedTime = $('schedTime');
        const body = {
            title: d.title,
            content: d.content,
            category_id: d.category_id || 0,
        };
        if (d.images.length) body.images = d.images;
        if (d.videos.length) body.videos = d.videos;
        if (d.attachments.length) body.attachments = d.attachments;
        if (d.tags.length) body.tags = d.tags;
        if ($('originalSwitch')?.checked) body.is_original = 1;
        if ($('commentSwitch') && !$('commentSwitch').checked) body.allow_comment = 0;
        if ($('pinSwitch')?.checked) body.is_pinned = 1;
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
            setTimeout(() => { location.href = id ? (PAGE_PATH + '/p/' + id) : HOME_URL; }, 700);
        } catch (e) {
            app.showToast(e.message || '发布失败，请稍后重试');
            btn.textContent = old;
            btn.disabled = false;
        } finally {
            state.submitting = false;
        }
    });
}
