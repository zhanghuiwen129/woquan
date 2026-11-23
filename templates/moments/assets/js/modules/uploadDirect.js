/**
 * woquan 统一直传助手（local driver 回退为原后端代理上传）
 *
 * 三步走：
 *   1. POST /api/upload/sign → 拿 {driver, is_local, sign:{upload_url,form_data,object_key,final_url,…}}
 *   2. 按 sign.method（POST）把 sign.form_data + 文件本身，multipart/form-data POST 到 sign.upload_url
 *      - is_local=true（driver=local）：sign.upload_url 指向 /api/auth/avatar、/posts/upload 等原接口，
 *        form_data 为空（保持原 multipart 格式不变，字段名 avatar/cover/image/video/attachment）
 *   3. 直传云存储成功后，POST /api/upload/register 回传 URL 入库；本地 driver 跳过此步（原接口直接写库）
 *
 * 返回结构与原 Upload::xxx 一致：
 *   avatar     -> {avatar: url}
 *   cover      -> {cover: url}
 *   image      -> {url, id, width, height, mime, size}
 *   video      -> {url, id, cover, duration, size}
 *   attachment -> {url, id, filename, size}
 *
 * 经验 1277439/703935：
 *   - form_data 的 **顺序必须是先放签名字段，最后才放 file**（OSS/COS PostObject 的要求）
 *   - 直传域名必须在 OSS/COS 控制台配置了本站域名的 CORS，否则浏览器 307/CORS 报错
 *   - Policy/MimeLimit/ContentLength 已在后端签入，前端**不要**改签名时声明的 contentType 和 size
 */

import { API_BASE } from './config.js?v=20260803.5';

/**
 * 通用直传入口
 * @param {*} app        hublog 全局 app 对象（提供 token/csrfToken/uploadCache/showToast 等）
 * @param {Object} opts
 * @param {'avatar'|'cover'|'image'|'video'|'attachment'} opts.type    上传类型
 * @param {File|Blob}      opts.file          最终要上传的文件（已压缩/抽帧后）
 * @param {string}         opts.fieldName     表单字段名（avatar/cover/image/video/attachment），用于 local 模式
 * @param {Object<string,string>|null} [opts.extraFields] local 模式下需要附带的字段（视频：cover/duration 等）
 * @param {string}         [opts.localEndpoint] local 模式下的上传 URL（默认按 type 推断）
 * @param {(percent:number)=>void} [opts.onProgress]  上传进度回调 0-100
 * @param {boolean}        [opts.skipRegister=false]  本地 driver 模式是否跳过 register（本地接口自己写库，必须 true）
 * @param {string}         [opts.origFilename]        登记时的用户友好文件名（缺省使用 file.name）
 */
export async function universalDirectUpload(app, opts) {
    const {
        type, file, fieldName,
        extraFields = null,
        localEndpoint = '',
        onProgress = null,
        skipRegister = false,
        origFilename = ''
    } = opts;

    // 1. 拿签名 / 元信息（含 local driver 回退信息）
    const signResp = await app.request('/upload/sign', {
        method: 'POST',
        body: {
            type,
            filename:       (file && file.name) ? file.name : (origFilename || ('file_' + Date.now())),
            content_length: file ? file.size : 0,
            content_type:   file ? (file.type || '') : ''
        }
    });
    const { driver, is_local, sign } = signResp;
    const signData = sign || {};

    const uploadUrl   = signData.upload_url || (is_local ? (localEndpoint || defaultLocalEndpoint(type)) : '');
    const method      = (signData.method || 'POST').toUpperCase();
    const fileField   = signData.file_field_name || (is_local ? fieldName : 'file');
    const objectKey   = signData.object_key || '';
    const expectedUrl = signData.final_url    || '';

    // 2. 构造 multipart form
    //    OSS/COS：先签名字段，再 key，再 file（严格顺序）
    //    本地：   仅放 file + extraFields（avatar/cover/image/video/attachment 原有结构）
    const fd = new FormData();
    if (!is_local && signData.form_data && typeof signData.form_data === 'object') {
        for (const [k, v] of Object.entries(signData.form_data)) {
            fd.append(k, v == null ? '' : String(v));
        }
    }
    // extraFields：本地 driver 附带 cover/duration/file_hash 等
    if (is_local && extraFields) {
        for (const [k, v] of Object.entries(extraFields)) {
            if (v === undefined || v === null || v === '') continue;
            fd.append(k, typeof v === 'string' || v instanceof Blob ? v : String(v));
        }
    }
    fd.append(fileField, file, (file && file.name) ? file.name : (origFilename || 'file'));

    // 3. 真正上传
    const uploadHeaders = {};
    // 本地模式：需要我们的 token + CSRF（这两个 Header 不会触发 preflight，multipart/form-data 可直接发）
    if (is_local) {
        uploadHeaders['Authorization'] = 'Bearer ' + (app.token || '');
        if (app.csrfToken) uploadHeaders['X-CSRF-Token'] = app.csrfToken;
    }
    // 云存储：**不要**加额外的自定义 Header，否则会触发 CORS preflight 且对象存储要求把这些 Header 也签进 policy

    const raw = await uploadMultipart(method, uploadUrl, uploadHeaders, fd, onProgress);
    let uploadResult = null;

    if (is_local) {
        // 本地：统一的 {code, message, data}
        try {
            uploadResult = await tryParseJson(raw);
        } catch (e) {
            throw new Error('本地上传响应解析失败: ' + (raw || '').slice(0, 200));
        }
        if (!uploadResult || uploadResult.code !== 200) {
            throw new Error((uploadResult && uploadResult.message) || '上传失败');
        }
        return uploadResult.data;
    }

    // 云存储直传：返回结果格式多样（OSS/COS 204 空、七牛 200 JSON），统一视为成功（2xx 已由 uploadMultipart 校验）
    // 登记用 URL：优先用 sign.final_url（跟后端驱动计算的一致，避免前端拼错）
    const finalUrl = expectedUrl || '';

    // 4. 回后端登记（入库）
    if (!skipRegister) {
        const registerBody = {
            type,
            object_key:    objectKey,
            final_url:     finalUrl,
            file_size:     file ? file.size : 0,
            orig_filename: origFilename || (file && file.name) || '',
            mime_type:     file ? (file.type || '') : ''
        };
        // 如果有本地抽取的视频封面/时长，也塞进去（方便后续 attachments 表补全）
        if (extraFields) {
            if (extraFields.cover)    registerBody.video_cover = extraFields.cover;
            if (extraFields.duration) registerBody.duration    = String(extraFields.duration);
        }
        const reg = await app.request('/upload/register', { method: 'POST', body: registerBody });
        return reg;
    }

    // 理论不会走到（skipRegister 只有本地模式用，前面已 return）
    return { url: finalUrl, final_url: finalUrl, object_key: objectKey };
}

/* ---------- 内部工具 ---------- */

/** 本地上传接口默认按 type 推断 */
function defaultLocalEndpoint(type) {
    switch (type) {
        case 'avatar':     return '/auth/avatar';
        case 'cover':      return '/auth/cover';
        case 'image':      return '/posts/upload';
        case 'video':      return '/posts/upload-video';
        case 'attachment': return '/posts/upload-attachment';
        default:           return '/posts/upload';
    }
}

/**
 * fetch + 上传进度（fetch 默认没有 progress，这里用 XHR 兜底）
 * HTTP 非 2xx 抛错；返回响应文本（JSON 或空）
 */
function uploadMultipart(method, url, headers, formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url, true);
        if (headers) {
            for (const [k, v] of Object.entries(headers)) {
                if (v === undefined || v === null || v === '') continue;
                // 注意：不要手动设置 Content-Type，multipart/form-data 必须让浏览器带 boundary
                if (/^content-type$/i.test(k)) continue;
                xhr.setRequestHeader(k, String(v));
            }
        }
        xhr.withCredentials = false; // 跨域 OSS/COS 不带 cookie，避免预检复杂化

        if (xhr.upload && typeof onProgress === 'function') {
            xhr.upload.onprogress = (e) => {
                if (!e.lengthComputable) return;
                const p = e.total > 0 ? Math.max(0, Math.min(100, Math.round(e.loaded / e.total * 100))) : 0;
                try { onProgress(p); } catch (_) {}
            };
        }
        xhr.onerror = () => reject(new Error('上传失败：网络错误或跨域（CORS）被拦截。请检查对象存储控制台 CORS 配置是否允许本站域名'));
        xhr.ontimeout = () => reject(new Error('上传超时'));
        xhr.onabort   = () => reject(new Error('上传已取消'));
        xhr.onload    = () => {
            // 204（OSS/COS 空响应）或 200/201 都视为成功
            const ok = xhr.status >= 200 && xhr.status < 300;
            const body = xhr.responseText || '';
            if (!ok) {
                let msg = '上传失败：HTTP ' + xhr.status;
                if (xhr.status === 403) msg += '（签名错误 / AK 权限不足 / Policy MimeLimit 拒绝，请检查直传配置）';
                if (xhr.status === 400) msg += '（参数错误，可能：扩展名不在 policy 白名单）';
                if (xhr.status === 0)   msg += '（网络未连通或 CORS 预检失败）';
                if (body) msg += ' 响应：' + body.slice(0, 500);
                reject(new Error(msg));
                return;
            }
            resolve(body);
        };
        try {
            xhr.send(formData);
        } catch (e) {
            reject(e);
        }
    });
}

async function tryParseJson(text) {
    if (text == null || text === '') return null;
    try {
        return JSON.parse(text);
    } catch (e) {
        // 可能只是响应头部不是 JSON，但 body 是 JSON 的非严格格式？这里直接包一层详细信息
        const err = new Error('解析失败，body=' + (typeof text === 'string' ? text.slice(0, 200) : String(text).slice(0, 200)));
        err.name = 'JsonParseError';
        throw err;
    }
}
