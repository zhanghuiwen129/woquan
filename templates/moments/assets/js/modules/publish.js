/**
 * woquan 发布动态 & 文件上传
 */
import { API_BASE, BASE_URL } from './config.js?v=20260803.5';
import { getTextLength } from './utils.js?v=20261112.2';
import { compressImageFile, extractVideoCover, uploadCoverBlob } from './compress.js?v=20260803.5';
import { universalDirectUpload } from './uploadDirect.js?v=20260803.5';

// 发布状态
let publishState = {
    location: null,
    locationLat: null,
    locationLng: null,
    mentionSearchTimer: null,
    lastMentionKeyword: '',
    richTextEnabled: false,
    richTextInitialized: false,
    vditorInitialized: false,
    scheduledInitialized: false
};

// Markdown 编辑器实例（Vditor 模式；null 表示降级为原生 textarea）
let publishVditor = null;

export async function publishPost(app) {
    const title = document.getElementById('publish-title')?.value.trim() || '';
    const cfg = app.siteConfig || {};

    // 读取内容：Vditor 模式从编辑器实例读取 Markdown，否则从 textarea 读取
    // 内容可空（支持纯图片/纯视频/纯附件发布），联合校验在收集完 media 后做。
    let content;
    let plainText = '';
    if (publishVditor) {
        content = (publishVditor.getValue() || '').trim();
        plainText = content;
    } else {
        content = document.getElementById('publish-content')?.value.trim() || '';
        plainText = content;
    }

    if (cfg.title_required === true && title === '') {
        app.showToast('请填写标题');
        return;
    }
    const maxLength = cfg.post_max_length || 2000;
    if (getTextLength(content) > maxLength) {
        app.showToast(`内容不能超过 ${maxLength} 字`);
        return;
    }

    const images = [];
    const videos = [];
    document.querySelectorAll('#media-preview .preview-media').forEach(el => {
        if (el.dataset.type === 'image') {
            images.push(el.dataset.url);
        } else if (el.dataset.type === 'video') {
            videos.push({
                url: el.dataset.url,
                cover: el.dataset.cover || '',
                duration: el.dataset.duration || 0
            });
        }
    });
    
    const maxImages = app.getMaxImages();
    if (images.length > maxImages) {
        app.showToast(`最多只能上传 ${maxImages} 张图片`);
        return;
    }
    
    const maxVideos = app.getMaxVideos();
    if (videos.length > maxVideos) {
        app.showToast(`最多只能上传 ${maxVideos} 个视频`);
        return;
    }

    const attachments = [];
    document.querySelectorAll('#attachment-preview .attachment-item').forEach(el => {
        attachments.push({
            url: el.dataset.url,
            filename: el.dataset.filename,
            size: parseInt(el.dataset.size || '0')
        });
    });
    const maxAttachments = app.getMaxAttachments();
    if (attachments.length > maxAttachments) {
        app.showToast(`最多只能上传 ${maxAttachments} 个附件`);
        return;
    }

    // 联合校验：内容与媒体至少有一项（支持纯图片/纯视频/纯附件发布）。
    // 与后端 PostController::create() 镜像，避免被绕过。
    if (!plainText && images.length === 0 && videos.length === 0 && attachments.length === 0) {
        app.showToast('请输入内容或上传图片/视频/附件');
        return;
    }

    const categorySelect = document.getElementById('publish-category');
    const categoryId = categorySelect ? parseInt(categorySelect.value || '0', 10) : 0;
    if (cfg.category_required === true && !categoryId) {
        app.showToast('请选择分类');
        return;
    }

    const isAnonymous = document.getElementById('publish-anonymous')?.checked || false;
    const isOriginal = document.getElementById('publish-original')?.checked || false;

    try {
        const body = { content, images };
        if (title) body.title = title;
        if (categoryId) body.category_id = categoryId;
        if (attachments.length > 0) body.attachments = attachments;
        if (videos.length > 0) body.videos = videos;
        if (publishState.location) {
            body.location = publishState.location;
            if (publishState.locationLat) body.location_lat = publishState.locationLat;
            if (publishState.locationLng) body.location_lng = publishState.locationLng;
        }
        if (isAnonymous) body.is_anonymous = true;
        if (isOriginal) body.is_original = true;
        
        const mentions = extractMentions(content);
        if (mentions.length > 0) {
            body.mentions = mentions;
        }

        // 定时发布
        const scheduleToggle = document.getElementById('publish-schedule-toggle');
        if (scheduleToggle && scheduleToggle.checked) {
            const scheduleTime = document.getElementById('publish-schedule-time')?.value;
            if (scheduleTime) {
                body.scheduled_at = scheduleTime;
            }
        }

        await app.request('/posts', {
            method: 'POST',
            body
        });
        // 发布成功：不清理已上传文件（已关联到动态），仅关闭弹窗
        closePublishModal(app, false);
        app.page = 1;
        app.hasMore = true;
        app.loadTimeline(false);
        app.showToast('发布成功');
    } catch (e) {
        app.showToast(e.message);
    }
}

/**
 * 关闭发布弹窗
 * @param {boolean} cleanupUploads 是否清理已上传但未使用的媒体文件（用户取消时为 true）
 */
export function closePublishModal(app, cleanupUploads = true) {
    // 用户取消发布时，收集已上传的媒体 URL，通知后端删除孤儿文件
    if (cleanupUploads) {
        const urls = [];
        document.querySelectorAll('#media-preview .preview-media').forEach(el => {
            if (el.dataset.url) urls.push(el.dataset.url);
            // 视频封面图也需清理
            if (el.dataset.type === 'video' && el.dataset.cover) urls.push(el.dataset.cover);
        });
        document.querySelectorAll('#attachment-preview .attachment-item').forEach(el => {
            if (el.dataset.url) urls.push(el.dataset.url);
        });
        if (urls.length > 0) {
            cleanupOrphanUploads(app, urls);
        }
    }

    document.getElementById('publish-modal')?.classList.remove('active');
    document.getElementById('publish-title').value = '';
    const contentEl = document.getElementById('publish-content');
    contentEl.value = '';
    contentEl.style.height = 'auto';
    if (typeof app.updatePostWordCount === 'function') app.updatePostWordCount();
    const categorySelect = document.getElementById('publish-category');
    if (categorySelect) categorySelect.value = '';
    document.getElementById('media-preview').innerHTML = '';
    document.getElementById('attachment-preview').innerHTML = '';
    const anonymousCheckbox = document.getElementById('publish-anonymous');
    if (anonymousCheckbox) anonymousCheckbox.checked = false;
    const anonBtn = document.getElementById('btn-anonymous');
    if (anonBtn) anonBtn.classList.remove('active');
    const originalCheckbox = document.getElementById('publish-original');
    if (originalCheckbox) originalCheckbox.checked = false;
    const origBtn = document.getElementById('btn-original');
    if (origBtn) origBtn.classList.remove('active');
    publishState.location = null;
    publishState.locationLat = null;
    publishState.locationLng = null;
    updateLocationDisplay();
    app.hideEmojiPanel();
    hideMentionPanel();

    // 重置 Vditor 编辑器（若有）
    if (publishVditor) {
        try { publishVditor.setValue(''); } catch (_) {}
    }
    // 重置降级 textarea（若存在）；Vditor 模式下保持隐藏，避免重复显示
    const contentTextarea = document.getElementById('publish-content');
    if (contentTextarea) {
        contentTextarea.value = '';
        contentTextarea.style.height = 'auto';
        if (!publishVditor) contentTextarea.style.display = '';
    }
    // 兼容旧 contenteditable 残留（极端情况下未加载 Vditor 时）
    const rtEditor = document.getElementById('publish-richtext-editor');
    if (rtEditor) { rtEditor.innerHTML = ''; rtEditor.style.display = 'none'; }
    publishState.richTextEnabled = false;

    // 重置定时发布
    const scheduleToggle = document.getElementById('publish-schedule-toggle');
    const scheduleTime = document.getElementById('publish-schedule-time');
    const scheduleBtn = document.getElementById('btn-schedule');
    const scheduleTimeWrapper = document.getElementById('schedule-time-wrapper');
    const scheduleTimeVisible = document.getElementById('publish-schedule-time-visible');
    if (scheduleToggle) scheduleToggle.checked = false;
    if (scheduleTime) scheduleTime.value = '';
    if (scheduleBtn) scheduleBtn.classList.remove('active');
    if (scheduleTimeWrapper) scheduleTimeWrapper.style.display = 'none';
    if (scheduleTimeVisible) scheduleTimeVisible.value = '';
}

/**
 * 通知后端删除未关联动态的上传文件（用户取消发布时调用）
 * 静默执行，不提示用户，失败不影响用户体验
 *
 * 供 interact.js 在评论图片取消时复用（评论场景的图片也通过 /posts/delete-upload 清理）
 */
export async function cleanupOrphanUploads(app, urls) {
    if (!urls || urls.length === 0) return;
    try {
        // V42：批量调用一次接口清理（原来 N 条 N 次，改成 1 次）
        await app.request('/uploads/cleanup', {
            method: 'POST',
            body: { urls }
        });
    } catch (e) {
        // 静默失败，不影响用户体验（还有惰性自动清理兜底）
    }
}

/**
 * 从内容中提取 @提及的用户名
 */
function extractMentions(content) {
    const mentions = [];
    const regex = /@([a-zA-Z0-9_]{3,20})/g;
    let match;
    while ((match = regex.exec(content)) !== null) {
        if (!mentions.includes(match[1])) {
            mentions.push(match[1]);
        }
    }
    return mentions;
}

/**
 * 处理@提及面板的键盘导航
 */
export function handleMentionKeydown(app, e) {
    const panel = document.getElementById('mention-panel');
    if (!panel || panel.style.display === 'none') return;
    
    const items = panel.querySelectorAll('.mention-item');
    if (items.length === 0) return;
    
    let selectedIndex = -1;
    items.forEach((item, idx) => {
        if (item.classList.contains('selected')) selectedIndex = idx;
    });
    
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        selectedIndex = selectedIndex < items.length - 1 ? selectedIndex + 1 : 0;
        items.forEach((item, idx) => item.classList.toggle('selected', idx === selectedIndex));
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        selectedIndex = selectedIndex > 0 ? selectedIndex - 1 : items.length - 1;
        items.forEach((item, idx) => item.classList.toggle('selected', idx === selectedIndex));
        items[selectedIndex].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter' || e.key === 'Tab') {
        e.preventDefault();
        if (selectedIndex >= 0) {
            items[selectedIndex].click();
        }
    } else if (e.key === 'Escape') {
        e.preventDefault();
        hideMentionPanel();
    }
}

/**
 * 处理输入框中的@符号，触发用户搜索
 */
export function handleMentionInput(app) {
    if (publishVditor) return handleMentionInputVditor(app);
    const input = document.getElementById('publish-content');
    if (!input) return;
    
    const cursorPos = input.selectionStart;
    const text = input.value.substring(0, cursorPos);
    
    const atIndex = text.lastIndexOf('@');
    if (atIndex === -1 || (atIndex > 0 && !/\s/.test(text[atIndex - 1]) && text[atIndex - 1] !== '\n')) {
        hideMentionPanel();
        return;
    }
    
    const keyword = text.substring(atIndex + 1);
    if (keyword.includes(' ') || keyword.includes('\n')) {
        hideMentionPanel();
        return;
    }
    
    const cfg = app.siteConfig || {};
    if (cfg.allow_mention === false) {
        return;
    }
    
    if (publishState.mentionSearchTimer) {
        clearTimeout(publishState.mentionSearchTimer);
    }
    
    if (keyword === publishState.lastMentionKeyword) {
        return;
    }
    
    publishState.mentionSearchTimer = setTimeout(() => {
        searchUsersForMention(app, keyword, atIndex);
    }, 200);
}

/**
 * 搜索用户用于@提及
 */
async function searchUsersForMention(app, keyword, atIndex) {
    if (!keyword || keyword.length < 1) {
        hideMentionPanel();
        return;
    }
    
    publishState.lastMentionKeyword = keyword;
    
    try {
        const users = await app.request(`/user/search?keyword=${encodeURIComponent(keyword)}&limit=10`);
        if (!users || users.length === 0) {
            hideMentionPanel();
            return;
        }
        
        showMentionPanel(app, users, atIndex);
    } catch (e) {
        hideMentionPanel();
    }
}

/**
 * 显示@提及用户选择面板
 */
function showMentionPanel(app, users, atIndex) {
    const panel = document.getElementById('mention-panel');
    const list = document.getElementById('mention-list');
    if (!panel || !list) return;
    
    list.innerHTML = users.map(user => `
        <div class="mention-item" data-username="${app.escapeHtml(user.username)}" data-nickname="${app.escapeHtml(user.nickname || user.username)}">
            <img src="${user.avatar || 'assets/images/default-avatar.svg'}" onerror="this.src='assets/images/default-avatar.svg'">
            <div class="mention-item-info">
                <div class="mention-item-name">${app.escapeHtml(user.nickname || user.username)}</div>
                <div class="mention-item-username">@${app.escapeHtml(user.username)}</div>
            </div>
        </div>
    `).join('');
    
    list.querySelectorAll('.mention-item').forEach(item => {
        item.addEventListener('click', () => {
            const username = item.dataset.username;
            insertMentionAtCursor(app, username, atIndex);
        });
    });
    
    panel.style.display = 'block';
}

/**
 * 隐藏@提及面板
 */
function hideMentionPanel() {
    const panel = document.getElementById('mention-panel');
    if (panel) panel.style.display = 'none';
    publishState.lastMentionKeyword = '';
}

/**
 * 在光标处插入@提及
 */
function insertMentionAtCursor(app, username, atIndex) {
    if (publishVditor) {
        const val = publishVditor.getValue() || '';
        const newVal = val.replace(/(^|[\s(>])@([a-zA-Z0-9_]*)$/, (mm, pre, kw) => `${pre}@${username} `);
        publishVditor.setValue(newVal);
        publishVditor.focus();
        hideMentionPanel();
        return;
    }
    const input = document.getElementById('publish-content');
    if (!input) return;
    
    const cursorPos = input.selectionStart;
    const text = input.value;
    const beforeAt = text.substring(0, atIndex);
    const afterCursor = text.substring(cursorPos);
    
    const mentionText = `@${username} `;
    input.value = beforeAt + mentionText + afterCursor;
    
    const newPos = atIndex + mentionText.length;
    input.setSelectionRange(newPos, newPos);
    input.focus();
    
    hideMentionPanel();
}

/**
 * 点击@按钮插入@符号
 */
export function insertMentionTrigger(app) {
    if (publishVditor) {
        publishVditor.focus();
        publishVditor.insertValue('@');
        handleMentionInputVditor(app);
        return;
    }
    const cfg = app.siteConfig || {};
    if (cfg.allow_mention === false) {
        app.showToast('@提及功能已关闭');
        return;
    }
    
    const input = document.getElementById('publish-content');
    if (!input) return;
    
    const cursorPos = input.selectionStart;
    const text = input.value;
    
    let insertText = '@';
    if (cursorPos > 0 && !/\s/.test(text[cursorPos - 1]) && text[cursorPos - 1] !== '\n') {
        insertText = ' @';
    }
    
    input.value = text.substring(0, cursorPos) + insertText + text.substring(cursorPos);
    const newPos = cursorPos + insertText.length;
    input.setSelectionRange(newPos, newPos);
    input.focus();
    
    handleMentionInput(app);
}

/**
 * 切换定位
 * 优先级：IP 自动定位 > 浏览器 GPS 定位 > 手动输入
 */
export async function toggleLocation(app) {
    const cfg = app.siteConfig || {};
    if (cfg.allow_location === false) {
        app.showToast('定位功能已关闭');
        return;
    }

    if (publishState.location) {
        clearLocation(app);
        return;
    }

    app.showToast('正在获取位置...');

    // 第一步：尝试 IP 自动定位（无需用户授权，适用于所有环境）
    try {
        const ipResult = await app.request('/location/ip');
        if (ipResult && ipResult.location) {
            publishState.location = ipResult.location;
            publishState.locationLat = ipResult.lat;
            publishState.locationLng = ipResult.lng;
            updateLocationDisplay();
            app.showToast('位置获取成功（IP定位）');
            return;
        }
    } catch (e) {
        // IP 定位失败，继续尝试浏览器定位
        console.warn('IP定位失败：', e.message);
    }

    // 第二步：尝试浏览器 GPS 定位（需要 HTTPS + 用户授权）
    const isSecure = window.isSecureContext || location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';

    if (isSecure && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            async (position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                publishState.locationLat = lat;
                publishState.locationLng = lng;

                // 调用后端逆地理编码接口
                const locationName = await reverseGeocode(app, lat, lng);
                publishState.location = locationName || `${lat.toFixed(4)}, ${lng.toFixed(4)}`;

                updateLocationDisplay();
                app.showToast('位置获取成功（GPS定位）');
            },
            (error) => {
                let msg = '定位失败';
                if (error.code === error.PERMISSION_DENIED) {
                    msg = '您拒绝了定位权限';
                } else if (error.code === error.POSITION_UNAVAILABLE) {
                    msg = '位置信息不可用';
                } else if (error.code === error.TIMEOUT) {
                    msg = '定位请求超时';
                }
                promptManualInput(app, msg + '。请手动输入位置名称：');
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0
            }
        );
        return;
    }

    // 第三步：降级到手动输入
    const reason = !isSecure ? '当前非 HTTPS 环境，浏览器定位不可用。' : '浏览器不支持自动定位。';
    promptManualInput(app, reason + '请手动输入位置名称：');
}

/**
 * 提示用户手动输入位置
 */
function promptManualInput(app, message) {
    app.showPrompt(message, '例如：北京市朝阳区', (name) => {
        if (name && name.trim()) {
            publishState.location = name.trim();
            publishState.locationLat = null;
            publishState.locationLng = null;
            updateLocationDisplay();
            app.showToast('位置已设置：' + publishState.location);
        }
    });
}

/**
 * 逆地理编码（获取位置名称）
 * 调用后端 /api/location/reverse 接口，将经纬度转换为可读地址
 */
async function reverseGeocode(app, lat, lng) {
    try {
        const data = await app.request(`/location/reverse?lat=${lat}&lng=${lng}`);
        return data?.location || `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
    } catch (e) {
        return `${lat.toFixed(4)}, ${lng.toFixed(4)}`;
    }
}

/**
 * 清除定位
 */
export function clearLocation(app) {
    publishState.location = null;
    publishState.locationLat = null;
    publishState.locationLng = null;
    updateLocationDisplay();
}

export function toggleAnonymous(app) {
    const btn = document.getElementById('btn-anonymous');
    const checkbox = document.getElementById('publish-anonymous');
    if (!btn) return;
    const isActive = btn.classList.toggle('active');
    if (checkbox) checkbox.checked = isActive;
}

/** V83：原创声明开关（原创作者徽章按原创帖数统计） */
export function toggleOriginal(app) {
    const btn = document.getElementById('btn-original');
    const checkbox = document.getElementById('publish-original');
    if (!btn) return;
    const isActive = btn.classList.toggle('active');
    if (checkbox) checkbox.checked = isActive;
}

/**
 * 更新定位显示
 */
function updateLocationDisplay() {
    const infoEl = document.getElementById('location-info');
    const nameEl = document.getElementById('location-name');
    const btnLocation = document.getElementById('btn-location');
    
    if (publishState.location) {
        if (infoEl) infoEl.style.display = 'flex';
        if (nameEl) nameEl.textContent = publishState.location;
        if (btnLocation) btnLocation.classList.add('active');
    } else {
        if (infoEl) infoEl.style.display = 'none';
        if (btnLocation) btnLocation.classList.remove('active');
    }
}

/**
 * 更新发布功能按钮显示状态
 */
export function updatePublishFeatureButtons(app) {
    const cfg = app.siteConfig || {};
    
    const btnMention = document.getElementById('btn-mention');
    if (btnMention) {
        btnMention.style.display = cfg.allow_mention !== false ? 'inline-flex' : 'none';
    }
    
    const btnLocation = document.getElementById('btn-location');
    if (btnLocation) {
        btnLocation.style.display = cfg.allow_location !== false ? 'inline-flex' : 'none';
    }
    
    const optionAnonymous = document.getElementById('option-anonymous');
    if (optionAnonymous) {
        optionAnonymous.style.display = 'none';
    }

    const btnAnonymous = document.getElementById('btn-anonymous');
    if (btnAnonymous) {
        btnAnonymous.style.display = cfg.allow_anonymous_post !== false ? 'inline-flex' : 'none';
    }

    const btnSchedule = document.getElementById('btn-schedule');
    if (btnSchedule) {
        btnSchedule.style.display = cfg.allow_scheduled_post !== false ? 'inline-flex' : 'none';
    }
}

export async function openPublishModal(app) {
    if (!app.checkAuth()) return;
    try {
        await app.request('/auth/me');
    } catch (e) {
        app.showToast(e.message);
        return;
    }
    if (!app.canInteract('post')) {
        app.showToast('当前禁止发布动态');
        return;
    }
    publishState.location = null;
    publishState.locationLat = null;
    publishState.locationLng = null;
    updateLocationDisplay();
    const anonymousCheckbox = document.getElementById('publish-anonymous');
    if (anonymousCheckbox) anonymousCheckbox.checked = false;
    const anonBtn = document.getElementById('btn-anonymous');
    if (anonBtn) anonBtn.classList.remove('active');
    const originalCheckbox = document.getElementById('publish-original');
    if (originalCheckbox) originalCheckbox.checked = false;
    const origBtn = document.getElementById('btn-original');
    if (origBtn) origBtn.classList.remove('active');
    // 重置定时发布状态
    const scheduleToggle = document.getElementById('publish-schedule-toggle');
    const scheduleBtn = document.getElementById('btn-schedule');
    const scheduleTimeWrapper = document.getElementById('schedule-time-wrapper');
    const scheduleTimeVisible = document.getElementById('publish-schedule-time-visible');
    if (scheduleToggle) scheduleToggle.checked = false;
    if (scheduleBtn) scheduleBtn.classList.remove('active');
    if (scheduleTimeWrapper) scheduleTimeWrapper.style.display = 'none';
    if (scheduleTimeVisible) scheduleTimeVisible.value = '';
    app.updatePublishFeatureButtons();
    initVditorEditor(app);
    initScheduledPost(app);
    document.getElementById('publish-modal')?.classList.add('active');
    app.updatePostWordCount();
}

export async function uploadImage(app, file) {
    const cfg = app.siteConfig || {};
    const maxSize = (cfg.upload_max_size || 10) * 1024 * 1024;
    if (file.size > maxSize) {
        throw new Error(`单张图片不能超过 ${cfg.upload_max_size || 10}MB`);
    }

    // 前端 Canvas 压缩（WebP 优先，降采样到 1920px），大幅减少上传体积
    const compressEnabled = (cfg.upload_compress ?? 1) === 1 || (cfg.upload_frontend_compress ?? 1) === 1;
    let uploadFile = file;
    if (compressEnabled && file.type.startsWith('image/')) {
        try {
            const maxWidth = parseInt(cfg.upload_max_width, 10) || 1920;
            uploadFile = await compressImageFile(file, { maxWidth, maxHeight: maxWidth, quality: 0.82 });
        } catch (e) {
            // 压缩失败时回退到原文件上传
            uploadFile = file;
        }
    }

    let fileHash = '';
    try {
        fileHash = await hashFile(app, uploadFile);
    } catch (e) {
        // 哈希计算失败时回退到普通上传
    }

    const cacheKey = fileHash ? 'img:' + fileHash : '';
    if (cacheKey && app.uploadCache.has(cacheKey)) {
        return app.uploadCache.get(cacheKey);
    }

    // 本地 driver 需要保留字段名 image + file_hash（原接口结构）；云存储走 form_data 自动签好
    const extra = fileHash ? { file_hash: fileHash } : null;
    const result = await universalDirectUpload(app, {
        type: 'image',
        file: uploadFile,
        fieldName: 'image',
        extraFields: extra,
        localEndpoint: '/posts/upload',
        skipRegister: false, // 两种模式都让 helper 统一处理（本地接口自动 return）
        origFilename: file.name
    });
    const url = (result && typeof result === 'object') ? (result.url || result.avatar || result.cover || '') : (typeof result === 'string' ? result : '');
    if (!url) throw new Error('上传后未得到 URL');
    if (cacheKey && url) {
        app.uploadCache.set(cacheKey, url);
    }
    return url;
}

export async function uploadAttachment(app, file) {
    const cfg = app.siteConfig || {};
    const maxSize = (cfg.upload_max_attachment_size || 50) * 1024 * 1024;
    if (file.size > maxSize) {
        throw new Error(`单个附件不能超过 ${cfg.upload_max_attachment_size || 50}MB`);
    }

    let fileHash = '';
    try {
        fileHash = await hashFile(app, file);
    } catch (e) {
        // 哈希计算失败时回退到普通上传
    }

    const cacheKey = fileHash ? 'att:' + fileHash : '';
    if (cacheKey && app.uploadCache.has(cacheKey)) {
        return app.uploadCache.get(cacheKey);
    }

    const extra = fileHash ? { file_hash: fileHash } : null;
    const result = await universalDirectUpload(app, {
        type: 'attachment',
        file,
        fieldName: 'attachment',
        extraFields: extra,
        localEndpoint: '/posts/upload-attachment',
        skipRegister: false,
        origFilename: file.name
    });
    if (cacheKey && result?.url) {
        app.uploadCache.set(cacheKey, result);
    }
    return result;
}

export async function hashFile(app, file) {
    const buffer = await file.arrayBuffer();
    const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
    const hashArray = Array.from(new Uint8Array(hashBuffer));
    return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
}

export function handlePublishPaste(app, e) {
    const items = e.clipboardData?.items;
    if (!items) return;
    let hasFile = false;
    for (const item of items) {
        if (item.kind === 'file') {
            hasFile = true;
            const file = item.getAsFile();
            if (!file) continue;
            if (detectMediaType(file)) {
                handleMediaFile(app, file);
            } else {
                handleAttachmentFile(app, file);
            }
        }
    }
    if (hasFile) {
        e.preventDefault();
    }
}

// 视频扩展名集合（用于 MIME type 缺失时的回退判断，如 Windows 下 .mkv/.flv 无 MIME）
const VIDEO_EXTENSIONS = ['.mp4', '.webm', '.mov', '.avi', '.mkv', '.flv', '.wmv', '.m4v', '.3gp', '.mpg', '.mpeg', '.ogv', '.rm', '.rmvb', '.asf', '.ts', '.mts', '.m2ts'];
const IMAGE_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.bmp', '.webp', '.svg', '.ico', '.tif', '.tiff'];

function detectMediaType(file) {
    if (file.type.startsWith('image/')) return 'image';
    if (file.type.startsWith('video/')) return 'video';
    // 回退：通过扩展名判断（Windows 下部分视频格式无 MIME type）
    const name = (file.name || '').toLowerCase();
    if (IMAGE_EXTENSIONS.some(ext => name.endsWith(ext))) return 'image';
    if (VIDEO_EXTENSIONS.some(ext => name.endsWith(ext))) return 'video';
    return null;
}

/**
 * 初始化媒体上传 input 的 accept 属性
 *
 * 图片和视频使用独立的 input + 独立的图标按钮：
 *   - media-upload：accept="image/*"，仅读取相册图片
 *   - video-upload：accept 明确列出视频 MIME 类型 + 扩展名
 *     兼容性说明：QQ浏览器、UC浏览器对 "video/*" 通配支持较弱，
 *     必须显式列出 MIME 和扩展名才能正确过滤出视频文件
 */
export function initMediaUpload(app) {
    const mediaInput = document.getElementById('media-upload');
    if (mediaInput) mediaInput.setAttribute('accept', 'image/*');
    const videoInput = document.getElementById('video-upload');
    if (videoInput) {
        // 显式列出常见视频 MIME + 扩展名，覆盖 QQ/UC/夸克 等浏览器
        videoInput.setAttribute('accept',
            'video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo,' +
            'video/x-matroska,video/x-flv,video/x-ms-wmv,video/3gpp,video/3gpp2,' +
            'video/mpeg,video/x-m4v,.mp4,.webm,.mov,.avi,.mkv,.flv,.wmv,.m4v,' +
            '.3gp,.3g2,.mpg,.mpeg,.ogv,.ts,.mts,.m2ts'
        );
    }
}

export async function handleMediaFile(app, file) {
    const preview = document.getElementById('media-preview');
    if (!preview) return;

    const mediaType = detectMediaType(file);
    const isImage = mediaType === 'image';
    const isVideo = mediaType === 'video';

    if (!isImage && !isVideo) {
        app.showToast('仅支持图片或视频文件');
        return;
    }
    
    const currentCount = preview.querySelectorAll('.preview-media').length;
    const currentImageCount = preview.querySelectorAll('.preview-media[data-type="image"]').length;
    const currentVideoCount = preview.querySelectorAll('.preview-media[data-type="video"]').length;
    
    if (isImage) {
        if (!app.canUploadImages()) {
            app.showToast('图片上传已关闭');
            return;
        }
        if (currentImageCount >= app.getMaxImages()) {
            app.showToast('已达到最大图片数量');
            return;
        }
    }
    
    if (isVideo) {
        if (!app.canUploadVideos()) {
            app.showToast('视频上传已关闭');
            return;
        }
        if (currentVideoCount >= app.getMaxVideos()) {
            app.showToast('已达到最大视频数量');
            return;
        }
    }
    
    try {
        app.showToast('正在上传 ' + (isVideo ? '视频' : '图片') + '...');
        const data = await uploadMedia(app, file);

        const wrap = document.createElement('div');
        wrap.className = 'preview-media';
        wrap.dataset.type = isImage ? 'image' : 'video';
        wrap.dataset.url = data.url;

        if (isImage) {
            const img = document.createElement('img');
            img.src = data.url;
            wrap.appendChild(img);
        } else if (isVideo) {
            wrap.dataset.cover = data.cover || '';
            wrap.dataset.duration = data.duration || 0;
            // 使用本地 object URL 做预览（即使服务端文件未就绪也能显示首帧）
            const video = document.createElement('video');
            const localPreviewUrl = URL.createObjectURL(file);
            video.src = localPreviewUrl;
            video.muted = true;
            video.playsInline = true;
            video.setAttribute('webkit-playsinline', '');
            video.setAttribute('x5-playsinline', '');
            video.setAttribute('disableremote', '');
            video.preload = 'metadata';
            // 强制 seek 到 0.1s 显示首帧（关键）
            const seekFirstFrame = () => {
                try {
                    if (video.readyState >= 1 && video.duration > 0.2) {
                        video.currentTime = Math.min(0.1, video.duration - 0.05);
                    }
                } catch (_) { /* ignore */ }
            };
            video.addEventListener('loadedmetadata', seekFirstFrame, { once: true });
            video.addEventListener('loadeddata', seekFirstFrame, { once: true });
            setTimeout(seekFirstFrame, 300);
            // 移除预览时释放 object URL
            wrap._cleanupUrl = localPreviewUrl;
            wrap.appendChild(video);
            // 叠加播放图标
            const playIcon = document.createElement('span');
            playIcon.className = 'preview-video-icon';
            playIcon.innerHTML = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>';
            wrap.appendChild(playIcon);
        }

        const del = document.createElement('span');
        del.className = 'preview-remove';
        del.textContent = '×';
        del.onclick = () => removePreviewItem(app, wrap);
        wrap.appendChild(del);

        preview.appendChild(wrap);
        updateUploadButtons(app);
        app.showToast('上传成功');
    } catch (e) {
        app.showToast(e.message);
    }
}

export async function uploadMedia(app, file) {
    const cfg = app.siteConfig || {};
    const mediaType = detectMediaType(file);
    const isImage = mediaType === 'image';
    const isVideo = mediaType === 'video';

    let maxSize, uploadEndpoint, typeName, fieldName;
    if (isImage) {
        maxSize = (cfg.upload_max_size || 10) * 1024 * 1024;
        uploadEndpoint = '/posts/upload';
        typeName = 'image';
        fieldName = 'image';
    } else if (isVideo) {
        maxSize = (cfg.upload_max_video_size || 50) * 1024 * 1024;
        uploadEndpoint = '/posts/upload-video';
        typeName = 'video';
        fieldName = 'video';
    } else {
        throw new Error('不支持的文件类型');
    }

    if (file.size > maxSize) {
        throw new Error(`文件大小不能超过 ${maxSize / 1024 / 1024}MB`);
    }

    // 图片：前端 Canvas 压缩；视频：抽取封面帧 + 时长
    let uploadFile = file;
    let videoCover = '';
    let videoDuration = 0;
    if (isImage) {
        const compressEnabled = (cfg.upload_compress ?? 1) === 1 || (cfg.upload_frontend_compress ?? 1) === 1;
        if (compressEnabled) {
            try {
                const maxWidth = parseInt(cfg.upload_max_width, 10) || 1920;
                uploadFile = await compressImageFile(file, { maxWidth, maxHeight: maxWidth, quality: 0.82 });
            } catch (e) {
                uploadFile = file;
            }
        }
    } else if (isVideo) {
        try {
            const { coverBlob, duration } = await extractVideoCover(file);
            videoDuration = duration;
            if (coverBlob) {
                videoCover = await uploadCoverBlob(app, coverBlob);
            }
        } catch (e) {
            // 封面抽取失败不阻断视频上传
        }
    }

    let fileHash = '';
    try {
        fileHash = await hashFile(app, uploadFile);
    } catch (e) {
        // 哈希计算失败时回退到普通上传
    }

    const cacheKey = fileHash ? 'media:' + fileHash : '';
    if (cacheKey && app.uploadCache.has(cacheKey)) {
        return app.uploadCache.get(cacheKey);
    }

    const extraFields = {};
    if (fileHash) extraFields.file_hash = fileHash;
    if (isVideo) {
        if (videoCover)   extraFields.cover    = videoCover;
        if (videoDuration) extraFields.duration = String(videoDuration);
    }

    const result = await universalDirectUpload(app, {
        type: typeName,
        file: uploadFile,
        fieldName,
        extraFields,
        localEndpoint: uploadEndpoint,
        skipRegister: false,
        origFilename: file.name
    });

    // 视频：如果登记时没回 cover/duration，用前端抽出来的补上（保持 handleMediaFile 兼容）
    if (isVideo && result) {
        if (!result.cover && videoCover) result.cover = videoCover;
        if ((!result.duration || result.duration === 0) && videoDuration) {
            result.duration = videoDuration;
        }
    }

    if (cacheKey && result) {
        app.uploadCache.set(cacheKey, result);
    }
    return result;
}

export async function handleAttachmentFile(app, file) {
    const preview = document.getElementById('attachment-preview');
    if (!preview) return;
    if (preview.querySelectorAll('.attachment-item').length >= app.getMaxAttachments()) {
        app.showToast('已达到最大附件数量');
        return;
    }
    try {
        const data = await uploadAttachment(app, file);
        const el = document.createElement('div');
        el.className = 'attachment-item';
        el.dataset.url = data.url;
        el.dataset.filename = data.filename;
        el.dataset.size = data.size || 0;
        el.innerHTML = `<span class='attachment-icon'>📎</span><span class='attachment-name'>${app.escapeHtml(data.filename)}</span><span class='attachment-size'>${app.formatFileSize(data.size || 0)}</span><span class='attachment-remove' title='移除'>×</span>`;
        el.querySelector('.attachment-remove').onclick = () => removePreviewItem(app, el);
        preview.appendChild(el);
        updateUploadButtons(app);
    } catch (e) {
        app.showToast(e.message);
    }
}

export function removePreviewItem(app, el) {
    // 释放视频预览的 object URL，避免内存泄漏
    if (el._cleanupUrl) {
        URL.revokeObjectURL(el._cleanupUrl);
    }
    el.remove();
    updateUploadButtons(app);
}

export function updateUploadButtons(app) {
    const preview = document.getElementById('media-preview');
    const imageCount = preview ? preview.querySelectorAll('.preview-media[data-type="image"]').length : 0;
    const videoCount = preview ? preview.querySelectorAll('.preview-media[data-type="video"]').length : 0;

    const btnMedia = document.getElementById('btn-media-upload');
    const btnVideo = document.getElementById('btn-video-upload');

    const canUploadMoreImages = imageCount < app.getMaxImages() && app.canUploadImages();
    const canUploadMoreVideos = videoCount < app.getMaxVideos() && app.canUploadVideos();

    // 图片按钮：仅当还能上传图片时显示
    if (btnMedia) btnMedia.style.display = canUploadMoreImages ? 'inline-flex' : 'none';
    // 视频按钮：仅当还能上传视频时显示
    if (btnVideo) btnVideo.style.display = canUploadMoreVideos ? 'inline-flex' : 'none';
}

/**
 * 初始化富文本编辑器
 * 添加"富文本"开关，切换 textarea 和 contenteditable div
 * 工具栏：粗体(B)、斜体(I)、链接(🔗)、引用(❝)
 */
export function initVditorEditor(app) {
    if (publishState.vditorInitialized) return;
    publishState.vditorInitialized = true;

    const textarea = document.getElementById('publish-content');
    if (!window.Vditor) {
        // 降级：保留原生 textarea（旧浏览器 / Vditor 资源缺失时仍可发布）
        return;
    }

    publishVditor = new window.Vditor('publish-vditor', {
        mode: 'wysiwyg',
        cdn: '/assets/vendor/vditor',
        cache: { enable: false },
        height: 220,
        placeholder: '分享新鲜事… 支持 Markdown 语法',
        toolbar: [
            'emoji', 'headings', 'bold', 'italic', 'strike', '|',
            'link', 'list', 'ordered-list', 'check', 'quote', 'code', 'code-block', '|',
            'upload', 'undo', 'redo', '|', 'fullscreen'
        ],
        upload: {
            accept: 'image/*',
            max: 9,
            handler: async (files) => {
                try {
                    const succMap = {};
                    for (const file of files) {
                        const url = await uploadImage(app, file);
                        succMap[file.name] = url;
                    }
                    return { msg: '', code: 0, data: { succMap } };
                } catch (e) {
                    return { msg: (e && e.message) || '上传失败', code: 1 };
                }
            }
        },
        input: () => {
            if (typeof app.updatePostWordCount === 'function') app.updatePostWordCount();
            handleMentionInputVditor(app);
        }
    });
    app._publishVditor = publishVditor;
    if (textarea) textarea.style.display = 'none';
}

function handleMentionInputVditor(app) {
    if (!publishVditor) return;
    const val = publishVditor.getValue() || '';
    const m = val.match(/(^|[\s(>])@([a-zA-Z0-9_]{0,20})$/);
    if (!m) {
        hideMentionPanel();
        return;
    }
    const keyword = m[2];
    const cfg = app.siteConfig || {};
    if (cfg.allow_mention === false) return;
    if (publishState.mentionSearchTimer) clearTimeout(publishState.mentionSearchTimer);
    publishState.mentionSearchTimer = setTimeout(() => {
        searchUsersForMention(app, keyword, -1);
    }, 200);
}


/**
 * 初始化定时发布功能
 * 元素已在 HTML 中，此处仅做初始化标记
 */
export function initScheduledPost(app) {
    if (publishState.scheduledInitialized) return;
    publishState.scheduledInitialized = true;
}

/**
 * 切换定时发布开关
 */
export function toggleSchedule(app) {
    const btn = document.getElementById('btn-schedule');
    const toggle = document.getElementById('publish-schedule-toggle');
    const timeWrapper = document.getElementById('schedule-time-wrapper');
    const timeInput = document.getElementById('publish-schedule-time-visible');
    const hiddenTime = document.getElementById('publish-schedule-time');
    if (!btn) return;

    const isActive = btn.classList.toggle('active');
    if (toggle) toggle.checked = isActive;

    if (isActive) {
        if (timeWrapper) timeWrapper.style.display = 'flex';
        // 默认设为 1 小时后
        if (timeInput && !timeInput.value) {
            const now = new Date(Date.now() + 3600000);
            const pad = (n) => String(n).padStart(2, '0');
            const val = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
            timeInput.value = val;
            if (hiddenTime) hiddenTime.value = val;
        }
    } else {
        if (timeWrapper) timeWrapper.style.display = 'none';
        if (timeInput) timeInput.value = '';
        if (hiddenTime) hiddenTime.value = '';
    }
}

/**
 * 定时发布时间变更
 */
export function onScheduleTimeChange(app, value) {
    const hiddenTime = document.getElementById('publish-schedule-time');
    if (hiddenTime) hiddenTime.value = value;
}
