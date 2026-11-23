// ============================================================
// export.js — 数据导出
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { API_BASE, registerActions, showToast, token } from './core.js';

// B4b 委托化：静态导出数据
registerActions({
    'do-export': () => doExport(),
});



// ============ 数据导出 ============
export function initExport() {
    // 默认设置日期为空时不需要额外初始化
}

export function doExport() {
    const type = document.getElementById('export-type').value;
    const format = document.getElementById('export-format').value;
    const exportToken = localStorage.getItem('hublog_token') || token;
    const url = API_BASE + '/admin/export?type=' + encodeURIComponent(type) + '&format=' + encodeURIComponent(format) + '&token=' + encodeURIComponent(exportToken);
    window.open(url, '_blank');
    showToast('正在导出数据...');
}