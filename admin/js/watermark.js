// ============================================================
// watermark.js — 图片水印
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { registerActions, request, showToast } from './core.js';

// B4b 委托化：静态水印上传 / 移除 / 预览
registerActions({
    'upload-watermark-image': () => uploadWatermarkImage(),
    'clear-watermark-image': () => clearWatermarkImage(),
    'preview-watermark': () => previewWatermark(),
    // B6 委托化：水印开关/类型切换（原 onchange 直调）
    'toggle-watermark-fields': () => toggleWatermarkFields(),
});



/* ============ V43：图片水印 ============ */

export function toggleWatermarkFields() {
    const enabled = document.getElementById('setting-watermark_enabled').checked;
    const type = document.getElementById('setting-watermark_type').value;
    const textBox = document.getElementById('watermark-text-fields');
    const imgBox = document.getElementById('watermark-image-fields');
    if (textBox) textBox.style.display = (enabled && type === 'text') ? '' : 'none';
    if (imgBox) imgBox.style.display = (enabled && type === 'image') ? '' : 'none';
}

export function renderWatermarkImage(url) {
    const box = document.getElementById('watermark-image-box');
    const img = document.getElementById('watermark-image-thumb');
    if (!box || !img) return;
    if (url) {
        img.src = url;
        box.style.display = '';
    } else {
        img.src = '';
        box.style.display = 'none';
    }
}

export async function uploadWatermarkImage() {
    const input = document.getElementById('watermark-file-input');
    if (!input || !input.files || !input.files[0]) {
        showToast('请先选择水印图片');
        return;
    }
    const fd = new FormData();
    fd.append('watermark', input.files[0]);
    try {
        const res = await request('/admin/upload-watermark', { method: 'POST', isFormData: true, body: fd });
        document.getElementById('setting-watermark_image').value = res.url;
        renderWatermarkImage(res.url);
        showToast('水印图片上传成功');
    } catch(e) { showToast(e.message); }
}

export function clearWatermarkImage() {
    document.getElementById('setting-watermark_image').value = '';
    const input = document.getElementById('watermark-file-input');
    if (input) input.value = '';
    renderWatermarkImage('');
}

export async function previewWatermark() {
    const val = id => (document.getElementById(id) || {}).value || '';
    const chk = id => (document.getElementById(id) || {}).checked ? '1' : '0';
    const payload = {
        watermark_type: val('setting-watermark_type'),
        watermark_text: val('setting-watermark_text'),
        watermark_font_size: val('setting-watermark_font_size'),
        watermark_color: val('setting-watermark_color'),
        watermark_shadow: chk('setting-watermark_shadow'),
        watermark_font_path: val('setting-watermark_font_path'),
        watermark_image: val('setting-watermark_image'),
        watermark_image_scale: val('setting-watermark_image_scale'),
        watermark_position: val('setting-watermark_position'),
        watermark_opacity: val('setting-watermark_opacity'),
        watermark_margin: val('setting-watermark_margin'),
        watermark_rotate: val('setting-watermark_rotate'),
        watermark_min_size: val('setting-watermark_min_size'),
        watermark_apply_avatar: chk('setting-watermark_apply_avatar'),
    };
    try {
        const res = await request('/admin/watermark-preview', { method: 'POST', body: payload });
        document.getElementById('watermark-canvas-img').src = res.image;
        document.getElementById('watermark-canvas-box').style.display = '';
        showToast(res.applied ? '预览已生成' : '水印未生效，请检查配置（如缺少字体、未上传水印图）');
    } catch(e) { showToast(e.message); }
}