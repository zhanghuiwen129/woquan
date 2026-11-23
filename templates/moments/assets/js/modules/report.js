/**
 * woquan 用户举报
 */

const REPORT_REASONS = [
    { value: 'spam', label: '垃圾广告' },
    { value: 'porn', label: '色情低俗' },
    { value: 'abuse', label: '辱骂攻击' },
    { value: 'other', label: '其他违规' }
];

export function openReportModal(app, targetType, targetId) {
    if (!app.checkAuth()) return;

    let modal = document.getElementById('report-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'report-modal';
        modal.className = 'modal-overlay';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('aria-labelledby', 'report-modal-title');
        modal.innerHTML = `
            <div class="modal-content report-modal">
                <div class="modal-header">
                    <h3 id="report-modal-title">举报</h3>
                    <button class="modal-close" aria-label="关闭" onclick="document.getElementById('report-modal').classList.remove('active')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="report-reasons" id="report-reasons">
                        ${REPORT_REASONS.map(r => `<label class="report-reason"><input type="radio" name="report-reason" value="${r.value}"> ${r.label}</label>`).join('')}
                    </div>
                    <textarea id="report-description" class="report-description" placeholder="补充说明（选填，最多500字）" maxlength="500"></textarea>
                </div>
                <div class="modal-footer">
                    <button class="btn-cancel" onclick="document.getElementById('report-modal').classList.remove('active')">取消</button>
                    <button class="btn-submit" id="report-submit">提交举报</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        modal.addEventListener('click', (e) => {
            if (e.target === modal || e.target.closest('.modal-close')) {
                modal.classList.remove('active');
                modal.setAttribute('aria-hidden', 'true');
            }
        });
        // ESC 键关闭
        modal.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                e.preventDefault();
                modal.classList.remove('active');
                modal.setAttribute('aria-hidden', 'true');
            }
        });
    }

    // 重置表单
    modal.querySelectorAll('input[name="report-reason"]').forEach(r => r.checked = false);
    const desc = document.getElementById('report-description');
    if (desc) desc.value = '';

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');

    const submitBtn = document.getElementById('report-submit');
    if (submitBtn) {
        submitBtn.onclick = async () => {
            const checked = modal.querySelector('input[name="report-reason"]:checked');
            if (!checked) {
                app.showToast('请选择举报原因');
                return;
            }
            const description = desc?.value.trim() || '';
            submitBtn.disabled = true;
            submitBtn.textContent = '提交中...';
            try {
                await app.request('/reports', {
                    method: 'POST',
                    body: {
                        target_type: targetType,
                        target_id: targetId,
                        reason: checked.value,
                        description: description || undefined
                    }
                });
                app.showToast('举报已提交');
                modal.classList.remove('active');
            } catch (e) {
                app.showToast(e.message);
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = '提交举报';
            }
        };
    }
}

export function buildReportButton(app, targetType, targetId) {
    return `<button class="action-report" onclick="app.openReportModal('${targetType}', ${targetId}); app.hideActionMenu(${targetId})">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><line x1="4" y1="22" x2="4" y2="15"/></svg>
        <span>举报</span>
    </button>`;
}
