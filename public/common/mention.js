/**
 * @提及功能
 * 支持在评论中@提及用户
 */
class MentionHelper {
    constructor(inputElement, options = {}) {
        this.input = inputElement;
        this.options = {
            triggerChar: '@',
            minSearchLength: 1,
            maxSuggestions: 10,
            debounceDelay: 300,
            searchUrl: '/moments/getUser',
            ...options
        };

        this.mentionList = null;
        this.currentMentionStart = -1;
        this.currentMentionText = '';
        this.currentSuggestionIndex = -1;
        this.suggestions = [];
        this.debounceTimer = null;

        this.init();
    }

    init() {
        // 创建提及列表容器
        this.createMentionList();

        // 绑定事件
        this.bindEvents();
    }

    createMentionList() {
        const list = document.createElement('div');
        list.className = 'mention-list';
        list.style.display = 'none';
        this.input.parentNode.appendChild(list);
        this.mentionList = list;
    }

    bindEvents() {
        // 输入事件
        this.input.addEventListener('input', (e) => this.handleInput(e));
        this.input.addEventListener('keydown', (e) => this.handleKeyDown(e));

        // 点击外部关闭列表
        document.addEventListener('click', (e) => {
            if (!this.input.contains(e.target) && !this.mentionList.contains(e.target)) {
                this.hideMentionList();
            }
        });

        // 失去焦点关闭列表
        this.input.addEventListener('blur', () => {
            setTimeout(() => this.hideMentionList(), 200);
        });
    }

    handleInput(e) {
        const value = this.input.value;
        const cursorPos = this.input.selectionStart;
        const textBeforeCursor = value.substring(0, cursorPos);

        // 查找最近的@符号
        const lastAtIndex = textBeforeCursor.lastIndexOf(this.options.triggerChar);

        if (lastAtIndex === -1) {
            this.hideMentionList();
            return;
        }

        // 获取@后的文本
        const mentionText = textBeforeCursor.substring(lastAtIndex + 1);
        const textAfterAt = textBeforeCursor.substring(lastAtIndex);

        // 检查是否在空格或其他特殊字符之后
        const spaceAfterAt = textAfterAt.includes(' ');
        if (spaceAfterAt) {
            this.hideMentionList();
            return;
        }

        this.currentMentionStart = lastAtIndex;
        this.currentMentionText = mentionText;

        // 如果输入的文本长度达到最小长度，触发搜索
        if (mentionText.length >= this.options.minSearchLength) {
            this.debouncedSearch(mentionText);
        } else if (mentionText.length === 0) {
            // 显示推荐用户（关注的用户）
            this.getRecommendedUsers();
        }
    }

    handleKeyDown(e) {
        if (this.mentionList.style.display === 'none') return;

        const suggestions = this.mentionList.querySelectorAll('.mention-item');
        const visibleSuggestions = Array.from(suggestions).filter(item => item.style.display !== 'none');

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                this.currentSuggestionIndex = Math.min(this.currentSuggestionIndex + 1, visibleSuggestions.length - 1);
                this.highlightSuggestion(visibleSuggestions);
                break;

            case 'ArrowUp':
                e.preventDefault();
                this.currentSuggestionIndex = Math.max(this.currentSuggestionIndex - 1, 0);
                this.highlightSuggestion(visibleSuggestions);
                break;

            case 'Enter':
                e.preventDefault();
                if (this.currentSuggestionIndex >= 0 && visibleSuggestions[this.currentSuggestionIndex]) {
                    const selectedUser = visibleSuggestions[this.currentSuggestionIndex].dataset.user;
                    this.selectUser(selectedUser);
                }
                break;

            case 'Escape':
                e.preventDefault();
                this.hideMentionList();
                break;
        }
    }

    highlightSuggestion(suggestions) {
        suggestions.forEach((item, index) => {
            item.classList.toggle('active', index === this.currentSuggestionIndex);
        });

        // 滚动到可见区域
        const activeItem = suggestions[this.currentSuggestionIndex];
        if (activeItem) {
            activeItem.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
    }

    debouncedSearch(keyword) {
        if (this.debounceTimer) {
            clearTimeout(this.debounceTimer);
        }

        this.debounceTimer = setTimeout(() => {
            this.searchUsers(keyword);
        }, this.options.debounceDelay);
    }

    async searchUsers(keyword) {
        try {
            this.showLoading();

            const response = await fetch(this.options.searchUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ keyword: keyword, limit: this.options.maxSuggestions })
            });

            const result = await response.json();

            if (result.code === 200 && result.data && result.data.list) {
                this.suggestions = result.data.list;
                this.displaySuggestions(result.data.list, keyword);
            } else {
                this.displayEmpty();
            }
        } catch (error) {
            console.error('搜索用户失败:', error);
            this.displayError();
        }
    }

    async getRecommendedUsers() {
        try {
            const response = await fetch('/moments/recommended', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });

            const result = await response.json();

            if (result.code === 200 && result.data && result.data.list) {
                this.suggestions = result.data.list;
                this.displaySuggestions(result.data.list, '');
            }
        } catch (error) {
            console.error('获取推荐用户失败:', error);
        }
    }

    displaySuggestions(users, highlightKeyword) {
        if (!users || users.length === 0) {
            this.displayEmpty();
            return;
        }

        const html = users.map(user => {
            const displayName = user.nickname || user.username;
            const highlightedName = highlightKeyword
                ? this.highlightText(displayName, highlightKeyword)
                : displayName;

            return `
                <div class="mention-item" data-user='${JSON.stringify(user).replace(/'/g, "&#39;")}'
                     data-user-id="${user.id}"
                     data-username="${user.username}"
                     data-nickname="${user.nickname || ''}">
                    <img src="${user.avatar || '/static/images/default-avatar.png'}"
                         class="mention-item-avatar"
                         alt="头像">
                    <div class="mention-item-info">
                        <div class="mention-item-name">${highlightedName}</div>
                        <div class="mention-item-nickname">@${user.username}</div>
                    </div>
                </div>
            `;
        }).join('');

        this.mentionList.innerHTML = html;
        this.mentionList.style.display = 'block';
        this.currentSuggestionIndex = 0;
        this.highlightSuggestion(this.mentionList.querySelectorAll('.mention-item'));
    }

    highlightText(text, keyword) {
        if (!keyword) return text;
        const regex = new RegExp(`(${this.escapeRegex(keyword)})`, 'gi');
        return text.replace(regex, '<span class="mention-highlight">$1</span>');
    }

    escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    displayLoading() {
        this.mentionList.innerHTML = `
            <div class="mention-loading">
                <span class="spinner"></span> 搜索中...
            </div>
        `;
        this.mentionList.style.display = 'block';
    }

    displayEmpty() {
        this.mentionList.innerHTML = `
            <div class="mention-empty">
                <i class="fa fa-user-times"></i>
                <div>未找到相关用户</div>
            </div>
        `;
        this.mentionList.style.display = 'block';
    }

    displayError() {
        this.mentionList.innerHTML = `
            <div class="mention-empty">
                <i class="fa fa-exclamation-triangle"></i>
                <div>加载失败，请重试</div>
            </div>
        `;
        this.mentionList.style.display = 'block';
    }

    selectUser(userData) {
        const value = this.input.value;
        const cursorPos = this.input.selectionStart;

        // 获取@符号前的文本和@符号后的文本
        const beforeMention = value.substring(0, this.currentMentionStart);
        const afterMention = value.substring(cursorPos);

        // 构建新的值：@用户名 + 空格 + 后续文本
        const mentionText = this.options.triggerChar + (userData.nickname || userData.username);
        const newValue = beforeMention + mentionText + ' ' + afterMention;

        // 设置新值并设置光标位置
        this.input.value = newValue;
        const newCursorPos = this.currentMentionStart + mentionText.length + 1;
        this.input.setSelectionRange(newCursorPos, newCursorPos);
        this.input.focus();

        // 触发输入事件（用于自动保存等）
        this.input.dispatchEvent(new Event('input', { bubbles: true }));

        // 隐藏提及列表
        this.hideMentionList();

        // 触发回调
        if (this.options.onSelect) {
            this.options.onSelect(userData);
        }
    }

    hideMentionList() {
        this.mentionList.style.display = 'none';
        this.currentMentionStart = -1;
        this.currentMentionText = '';
        this.currentSuggestionIndex = -1;
        this.suggestions = [];
    }

    destroy() {
        // 移除事件监听
        this.input.removeEventListener('input', this.handleInput);
        this.input.removeEventListener('keydown', this.handleKeyDown);

        // 移除提及列表
        if (this.mentionList && this.mentionList.parentNode) {
            this.mentionList.parentNode.removeChild(this.mentionList);
        }
    }
}

// 导出为全局变量，方便使用
window.MentionHelper = MentionHelper;
