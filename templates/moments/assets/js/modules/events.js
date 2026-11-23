/**
 * woquan 事件绑定
 */

export function bindEvents(app) {
    /**
     * 全站「写文章」入口：统一事件委托。
     * 之前 18 个 HTML 各自硬编码 inline onclick，其中 17 个写成
     * document.getElementById('btn-publish')?.click() —— 那些页面根本没有
     * #btn-publish，点击静默失效（详情页最典型）。改成 data-action 后，
     * 新增页面只要挂上属性即可，逻辑只此一份。
     * moments 主题未引入 articleEditor 模块时安全降级到社交发布框。
     */
    document.addEventListener('click', (e) => {
        const btn = e.target.closest?.('[data-action="write-article"]');
        if (!btn) return;
        e.preventDefault();
        if (!app.checkAuth()) return;
        if (typeof app.openArticleEditor === 'function') {
            app.openArticleEditor();
        } else if (typeof app.openPublishModal === 'function') {
            app.openPublishModal();
        }
    });

    const btnPublish = document.getElementById('btn-publish');
    if (btnPublish) btnPublish.addEventListener('click', () => app.openPublishModal());

    // 根据设备类型调整媒体上传 input 的 accept 属性
    // 桌面端移除 accept 确保视频文件可见，移动端保留 accept 确保相册/相机正常调出
    if (typeof app.initMediaUpload === 'function') {
        app.initMediaUpload();
    }

    const publishContent = document.getElementById('publish-content');
    if (publishContent) {
        publishContent.addEventListener('paste', (e) => app.handlePublishPaste(e));
        publishContent.addEventListener('input', () => app.handleMentionInput());
        publishContent.addEventListener('keydown', (e) => app.handleMentionKeydown(e));
    }

    const btnRefresh = document.getElementById('btn-refresh');
    if (btnRefresh) btnRefresh.addEventListener('click', () => app.refreshPage());

    const btnNotify = document.getElementById('btn-notify');
    if (btnNotify) btnNotify.addEventListener('click', () => app.openNotificationModal());

    const btnMarkAllRead = document.getElementById('btn-mark-all-read');
    if (btnMarkAllRead) btnMarkAllRead.addEventListener('click', () => app.markAllNotificationsRead());

    document.querySelectorAll('.modal-overlay').forEach(el => {
        el.addEventListener('click', (e) => {
            if (e.target !== el) return;
            // 发布弹窗：通过 closePublishModal 关闭，以便清理已上传但未使用的文件
            if (el.id === 'publish-modal') {
                app.closePublishModal();
            } else {
                el.classList.remove('active');
            }
        });
    });

    const viewer = document.getElementById('image-viewer');
    if (viewer) {
        viewer.addEventListener('click', (e) => {
            // 点击背景或关闭按钮 → 关闭
            if (e.target === viewer || e.target.closest('#close-viewer')) {
                app.closeViewer();
            }
            // 上一张
            if (e.target.closest('#viewer-prev')) {
                e.stopPropagation();
                app.prevImage();
            }
            // 下一张
            if (e.target.closest('#viewer-next')) {
                e.stopPropagation();
                app.nextImage();
            }
        });
    }

    // 文章详情页把 #comment-input 换成多行 textarea 内嵌评论框，
    // 其余页面仍是原来的单行 input 固定底栏。两者快捷键不同：
    //   - 单行 input：Enter 发送
    //   - 多行 textarea：Enter 换行、Ctrl/Cmd + Enter 发送（主流文章站惯例）
    const isArticleComposerPage = () =>
        document.body?.dataset.page === 'post-detail' && !!document.getElementById('article-composer');

    const commentInput = document.getElementById('comment-input');
    if (commentInput) {
        // 用 keydown 而非 keypress：textarea 上 Ctrl/Cmd+Enter 在部分浏览器
        // 不触发 keypress，改 keydown 后单行 / 多行两条路径都可靠。
        commentInput.addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            if (isArticleComposerPage()) {
                if (e.ctrlKey || e.metaKey) {
                    e.preventDefault();
                    app.submitComment();
                }
                return;
            }
            // Enter 发送评论 —— 必须 preventDefault 阻止潜在的 form submit 行为
            if (!e.shiftKey) {
                e.preventDefault();
                app.submitComment();
            }
        });

        const loginPassword = document.getElementById('login-password');
        const loginUsername = document.getElementById('login-username');
        if (loginPassword) {
            loginPassword.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') app.login();
            });
        }
        if (loginUsername) {
            loginUsername.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') app.login();
            });
        }
        commentInput.addEventListener('blur', (e) => {
            // 文章详情页内嵌评论框常驻，失焦不能关掉/清空用户已输入的草稿
            if (isArticleComposerPage()) return;
            if (app.isSelectingCommentImage) {
                return;
            }
            const related = e.relatedTarget;
            if (related && related.closest && related.closest('#comment-input-bar')) {
                return;
            }
            app.commentBlurTimeout = setTimeout(() => {
                app.closeCommentBar();
                app.commentBlurTimeout = null;
            }, 200);
        });
    }
    document.getElementById('btn-send-comment')?.addEventListener('click', () => app.submitComment());

    // 文章详情页内嵌评论框的附加交互：字数统计 / 取消回复 / 当前用户头像
    if (isArticleComposerPage()) {
        const counter = document.getElementById('article-comment-count');
        const updateCount = () => {
            if (counter && commentInput) counter.textContent = commentInput.value.length + ' / 500';
        };
        if (commentInput) {
            commentInput.addEventListener('input', updateCount);
            updateCount();
        }
        document.getElementById('article-reply-cancel')
            ?.addEventListener('click', () => app.cancelArticleReply());
        const avatar = document.getElementById('article-composer-avatar');
        if (avatar && app.user) {
            try { avatar.src = app.resolveAvatar(app.user.avatar); } catch (_) { /* 保留默认头像 */ }
        }
    }

    const btnCommentEmoji = document.getElementById('btn-comment-emoji');
    const btnCommentImage = document.getElementById('btn-comment-image');
    const clearCommentBlurTimeout = () => {
        if (app.commentBlurTimeout) {
            clearTimeout(app.commentBlurTimeout);
            app.commentBlurTimeout = null;
        }
    };
    if (btnCommentEmoji) {
        btnCommentEmoji.addEventListener('mousedown', clearCommentBlurTimeout);
    }
    if (btnCommentImage) {
        btnCommentImage.addEventListener('mousedown', () => {
            clearCommentBlurTimeout();
            app.isSelectingCommentImage = true;
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        // 文章页 Escape 只取消「回复某人」状态，不关掉常驻评论框
        if (isArticleComposerPage()) {
            if (app.currentReplyCommentId && typeof app.cancelArticleReply === 'function') {
                app.cancelArticleReply();
            }
            return;
        }
        app.closeCommentBar();
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.moment-actions')) {
            app.hideAllActionMenus();
        }
    });

    document.addEventListener('click', (e) => {
        if (app.emojiPanel && app.emojiPanel.classList.contains('active')) {
            if (!e.target.closest('.emoji-panel') && !e.target.closest('.btn-emoji') && !e.target.closest('.btn-emoji-toggle') && !e.target.closest('.chat-emoji-btn')) {
                app.hideEmojiPanel();
            }
        }
    });
}
