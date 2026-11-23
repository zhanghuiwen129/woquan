/**
 * woquan 前端媒体压缩模块
 *
 * 功能：
 *   1. 图片压缩：基于 Canvas 降采样 + 质量压缩，优先输出 WebP（浏览器不支持时回退 JPEG）
 *      - 手机照片通常 3-8MB，压缩后 200-800KB，节省 80%-95% 带宽和服务器空间
 *   2. 视频封面抽取：通过 <video> 元素截取第一帧作为封面图
 *   3. 视频时长读取
 *
 * 使用：
 *   import { compressImage, extractVideoCover } from './modules/compress.js';
 *   const blob = await compressImage(file, { maxWidth: 1920, quality: 0.82 });
 *   const { coverBlob, duration } = await extractVideoCover(file);
 */

/**
 * 检测浏览器是否支持 WebP 编码
 */
let _webpSupported = null;
async function supportsWebP() {
    if (_webpSupported !== null) return _webpSupported;
    try {
        const canvas = document.createElement('canvas');
        canvas.width = 1;
        canvas.height = 1;
        _webpSupported = canvas.toDataURL('image/webp').startsWith('data:image/webp');
    } catch (e) {
        _webpSupported = false;
    }
    return _webpSupported;
}

/**
 * 读取图片文件为 HTMLImageElement
 */
function loadImage(imgFile) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(imgFile);
        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = (e) => {
            URL.revokeObjectURL(url);
            reject(new Error('图片加载失败'));
        };
        img.src = url;
    });
}

/**
 * 压缩图片
 *
 * @param {File|Blob} file 原始图片文件
 * @param {object} options
 * @param {number} options.maxWidth  最大宽度，默认 1920
 * @param {number} options.maxHeight 最大高度，默认 1920
 * @param {number} options.quality   压缩质量 0-1，默认 0.82
 * @param {boolean} options.preferWebP 是否优先 WebP，默认 true
 * @returns {Promise<Blob>} 压缩后的 Blob
 */
export async function compressImage(file, options = {}) {
    const maxWidth = options.maxWidth || 1920;
    const maxHeight = options.maxHeight || 1920;
    const quality = options.quality || 0.82;
    const preferWebP = options.preferWebP !== false;

    // GIF 动图不压缩（Canvas 只能取第一帧，会丢失动画）
    if (file.type === 'image/gif') {
        return file;
    }

    const img = await loadImage(file);
    const origW = img.naturalWidth;
    const origH = img.naturalHeight;

    // 尺寸未超限且文件较小时，跳过压缩直接返回原文件
    if (origW <= maxWidth && origH <= maxHeight && file.size < 300 * 1024) {
        return file;
    }

    // 计算缩放比例
    let newW = origW;
    let newH = origH;
    if (origW > maxWidth || origH > maxHeight) {
        const ratio = Math.min(maxWidth / origW, maxHeight / origH);
        newW = Math.round(origW * ratio);
        newH = Math.round(origH * ratio);
    }

    const canvas = document.createElement('canvas');
    canvas.width = newW;
    canvas.height = newH;
    const ctx = canvas.getContext('2d');

    // 白底填充（JPEG 不支持透明通道，PNG 透明区域在 JPEG 会变黑）
    const useWebP = preferWebP && await supportsWebP();
    const outputType = useWebP ? 'image/webp' : 'image/jpeg';

    if (outputType === 'image/jpeg') {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, newW, newH);
    }

    ctx.drawImage(img, 0, 0, newW, newH);

    // PNG 如果原图有透明通道且支持 WebP，用 WebP 保持透明；否则 PNG 原样返回
    if (file.type === 'image/png' && !useWebP) {
        // PNG 不做有损压缩，仅在尺寸超标时缩放
        if (origW <= maxWidth && origH <= maxHeight) {
            return file;
        }
        return new Promise((resolve, reject) => {
            canvas.toBlob((blob) => {
                blob ? resolve(blob) : reject(new Error('PNG 压缩失败'));
            }, 'image/png');
        });
    }

    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error('图片压缩失败'));
                }
            },
            outputType,
            quality
        );
    });
}

/**
 * 压缩图片并封装为 File 对象（保留原始文件名扩展名）
 */
export async function compressImageFile(file, options = {}) {
    const blob = await compressImage(file, options);
    // 如果返回的是原文件（未压缩），直接返回
    if (blob === file) return file;

    const useWebP = options.preferWebP !== false && await supportsWebP();
    const ext = useWebP ? 'webp' : (file.name.split('.').pop() || 'jpg');
    const baseName = file.name.replace(/\.[^.]+$/, '');
    return new File([blob], `${baseName}.${ext}`, {
        type: blob.type,
        lastModified: Date.now()
    });
}

/**
 * 抽取视频封面帧和时长
 *
 * @param {File} videoFile 视频文件
 * @param {number} seekTime 截取的时间点（秒），默认 1
 * @returns {Promise<{coverBlob: Blob|null, duration: number}>}
 */
export function extractVideoCover(videoFile, seekTime = 1) {
    return new Promise((resolve) => {
        const video = document.createElement('video');
        video.preload = 'metadata';
        video.muted = true;
        const url = URL.createObjectURL(videoFile);
        video.src = url;

        let settled = false;
        const cleanup = () => {
            URL.revokeObjectURL(url);
        };
        const done = (result) => {
            if (settled) return;
            settled = true;
            cleanup();
            resolve(result);
        };

        // 超时兜底（5秒内未完成则返回空封面）
        const timeout = setTimeout(() => {
            done({ coverBlob: null, duration: 0 });
        }, 5000);

        video.onloadedmetadata = () => {
            const duration = video.duration || 0;
            // 跳转到指定时间点截取封面
            const targetTime = Math.min(seekTime, Math.max(0, duration - 0.1));
            try {
                video.currentTime = targetTime;
            } catch (e) {
                clearTimeout(timeout);
                done({ coverBlob: null, duration: Math.round(duration) });
            }
        };

        video.onseeked = () => {
            clearTimeout(timeout);
            try {
                const canvas = document.createElement('canvas');
                const vw = video.videoWidth || 640;
                const vh = video.videoHeight || 360;
                // 封面图限制最大宽度 640px，进一步节省空间
                const maxCoverW = 640;
                const ratio = vw > maxCoverW ? maxCoverW / vw : 1;
                canvas.width = Math.round(vw * ratio);
                canvas.height = Math.round(vh * ratio);
                const ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

                canvas.toBlob(
                    (blob) => {
                        done({ coverBlob: blob, duration: Math.round(video.duration || 0) });
                    },
                    'image/jpeg',
                    0.8
                );
            } catch (e) {
                done({ coverBlob: null, duration: Math.round(video.duration || 0) });
            }
        };

        video.onerror = () => {
            clearTimeout(timeout);
            done({ coverBlob: null, duration: 0 });
        };
    });
}

/**
 * 将 Blob 上传为封面图（调用图片上传接口）
 *
 * @param {object} app HublogApp 实例
 * @param {Blob} coverBlob 封面图 Blob
 * @returns {Promise<string|null>} 封面图 URL
 */
export async function uploadCoverBlob(app, coverBlob) {
    if (!coverBlob) return null;
    const formData = new FormData();
    formData.append('image', coverBlob, 'video_cover.jpg');
    const { API_BASE } = await import('./config.js?v=20260803.5');
    const res = await fetch(API_BASE + '/posts/upload', {
        method: 'POST',
        headers: {
            // P0-3：token 仅内存态，为 null 时不带 Bearer 头走 httpOnly Cookie 鉴权
            ...(app.token ? { 'Authorization': `Bearer ${app.token}` } : {}),
            'X-CSRF-Token': app.csrfToken
        },
        body: formData
    });
    const data = await res.json();
    if (data.code !== 200) throw new Error(data.message);
    return data.data.url;
}
