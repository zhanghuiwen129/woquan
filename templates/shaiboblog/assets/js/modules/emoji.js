/**
 * woquan 表情选择器
 * 使用微软 Fluent UI Emoji 3D 表情图标（立体风格），失败回退到 Twemoji
 */

const EMOJI_DATA = [
    { emoji: '😊', name: '微笑' }, { emoji: '😒', name: '撇嘴' }, { emoji: '😍', name: '色' },
    { emoji: '😳', name: '发呆' }, { emoji: '😏', name: '得意' }, { emoji: '😭', name: '流泪' },
    { emoji: '😌', name: '害羞' }, { emoji: '🤐', name: '闭嘴' }, { emoji: '😴', name: '睡' },
    { emoji: '😢', name: '大哭' }, { emoji: '😅', name: '尴尬' }, { emoji: '😡', name: '发怒' },
    { emoji: '😜', name: '调皮' }, { emoji: '😁', name: '呲牙' }, { emoji: '😲', name: '惊讶' },
    { emoji: '😞', name: '难过' }, { emoji: '😎', name: '酷' }, { emoji: '😓', name: '冷汗' },
    { emoji: '😫', name: '抓狂' }, { emoji: '🤮', name: '吐' }, { emoji: '😆', name: '偷笑' },
    { emoji: '😄', name: '愉快' }, { emoji: '🙄', name: '白眼' }, { emoji: '😤', name: '傲慢' },
    { emoji: '😋', name: '饥饿' }, { emoji: '😪', name: '困' }, { emoji: '😱', name: '惊恐' },
    { emoji: '😥', name: '流汗' }, { emoji: '😀', name: '憨笑' }, { emoji: '😶', name: '悠闲' },
    { emoji: '💪', name: '奋斗' }, { emoji: '🤬', name: '咒骂' }, { emoji: '❓', name: '疑问' },
    { emoji: '🤫', name: '嘘' }, { emoji: '😵', name: '晕' }, { emoji: '🤪', name: '疯了' },
    { emoji: '😩', name: '衰' }, { emoji: '💀', name: '骷髅' }, { emoji: '👊', name: '敲打' },
    { emoji: '👋', name: '再见' }, { emoji: '🤥', name: '抠鼻' }, { emoji: '👏', name: '鼓掌' },
    { emoji: '😈', name: '坏笑' }, { emoji: '😕', name: '疑惑' }, { emoji: '🥱', name: '哈欠' },
    { emoji: '🥺', name: '委屈' }, { emoji: '😘', name: '亲亲' }, { emoji: '🔪', name: '菜刀' },
    { emoji: '🍉', name: '西瓜' }, { emoji: '🍺', name: '啤酒' }, { emoji: '🏀', name: '篮球' },
    { emoji: '☕', name: '咖啡' }, { emoji: '🍚', name: '饭' }, { emoji: '🐷', name: '猪头' },
    { emoji: '🌹', name: '玫瑰' }, { emoji: '🥀', name: '凋谢' }, { emoji: '💋', name: '嘴唇' },
    { emoji: '❤️', name: '爱心' }, { emoji: '💔', name: '心碎' }, { emoji: '🎂', name: '蛋糕' },
    { emoji: '⚡', name: '闪电' }, { emoji: '💣', name: '炸弹' }, { emoji: '⚽', name: '足球' },
    { emoji: '🐞', name: '瓢虫' }, { emoji: '💩', name: '便便' }, { emoji: '🌙', name: '月亮' },
    { emoji: '☀️', name: '太阳' }, { emoji: '🎁', name: '礼物' }, { emoji: '🤗', name: '拥抱' },
    { emoji: '👍', name: '强' }, { emoji: '👎', name: '弱' }, { emoji: '🤝', name: '握手' },
    { emoji: '✌️', name: '胜利' }, { emoji: '🙏', name: '抱拳' }, { emoji: '👉', name: '勾引' },
    { emoji: '✊', name: '拳头' }, { emoji: '🤟', name: '爱你' }, { emoji: '🙅', name: 'NO' },
    { emoji: '👌', name: 'OK' }, { emoji: '🕺', name: '跳跳' }, { emoji: '🥶', name: '发抖' },
    { emoji: '💃', name: '转圈' }
];

export { EMOJI_DATA };

/**
 * Fluent UI Emoji 映射表
 * 键: emoji 字符（与 EMOJI_DATA 中的完全一致）
 * 值: Fluent UI Emoji 英文目录名
 *   - 带 * 前缀: 有肤色变体，路径为 {Name}/Default/3D/{name}_3d_default.png
 *   - 不带 *: 无肤色变体，路径为 {Name}/3D/{name}_3d.png
 */
const FLUENT_EMOJI_MAP = {
    '😊': 'Smiling face with smiling eyes',
    '😒': 'Unamused face',
    '😍': 'Smiling face with heart-eyes',
    '😳': 'Flushed face',
    '😏': 'Smirking face',
    '😭': 'Loudly crying face',
    '😌': 'Relieved face',
    '🤐': 'Zipper-mouth face',
    '😴': 'Sleeping face',
    '😢': 'Crying face',
    '😅': 'Grinning face with sweat',
    '😡': 'Pouting face',
    '😜': 'Winking face with tongue',
    '😁': 'Beaming face with smiling eyes',
    '😲': 'Astonished face',
    '😞': 'Disappointed face',
    '😎': 'Smiling face with sunglasses',
    '😓': 'Downcast face with sweat',
    '😫': 'Tired face',
    '🤮': 'Face vomiting',
    '😆': 'Grinning squinting face',
    '😄': 'Grinning face with smiling eyes',
    '🙄': 'Face with rolling eyes',
    '😤': 'Face with steam from nose',
    '😋': 'Face savoring food',
    '😪': 'Sleepy face',
    '😱': 'Face screaming in fear',
    '😥': 'Sad but relieved face',
    '😀': 'Grinning face',
    '😶': 'Face without mouth',
    '🤬': 'Face with symbols on mouth',
    '❓': 'Red question mark',
    '🤫': 'Shushing face',
    '😵': 'Knocked-out face',
    '🤪': 'Zany face',
    '😩': 'Weary face',
    '💀': 'Skull',
    '🤥': 'Lying face',
    '😈': 'Angry face with horns',
    '😕': 'Confused face',
    '🥱': 'Yawning face',
    '🥺': 'Pleading face',
    '😘': 'Face blowing a kiss',
    '🔪': 'Kitchen knife',
    '🍺': 'Beer mug',
    '🍉': 'Watermelon',
    '🏀': 'Basketball',
    '☕': 'Hot beverage',
    '🍚': 'Cooked rice',
    '🐷': 'Pig face',
    '🌹': 'Rose',
    '🥀': 'Wilted flower',
    '💋': 'Kiss mark',
    '❤️': 'Red heart',
    '💔': 'Broken heart',
    '🎂': 'Birthday cake',
    '⚡': 'High voltage',
    '💣': 'Bomb',
    '⚽': 'Soccer ball',
    '🐞': 'Lady beetle',
    '💩': 'Pile of poo',
    '🌙': 'Crescent moon',
    '☀️': 'Sun',
    '🎁': 'Wrapped gift',
    '🤗': 'Hugging face',
    // 有肤色变体的手势/人物姿势（需要 Default 路径）
    '💪': '*Flexed biceps',
    '👊': '*Oncoming fist',
    '👋': '*Waving hand',
    '👏': '*Clapping hands',
    '🤝': '*Handshake',
    '✌️': '*Victory hand',
    '🙏': '*Folded hands',
    '👉': '*Backhand index pointing right',
    '✊': '*Raised fist',
    '🤟': '*Love-you gesture',
    '🙅': '*Person gesturing no',
    '👌': '*Ok hand',
    '🕺': '*Man dancing',
    '💃': '*Woman dancing',
    '👍': '*Thumbs up',
    '👎': '*Thumbs down'
};

const FLUENT_CDN_BASE = 'https://cdn.jsdelivr.net/gh/microsoft/fluentui-emoji@main/assets/';

/**
 * 使用 Fluent UI Emoji 3D 渲染表情图片，失败回退到 Twemoji
 */
export function renderFluentEmoji(emoji) {
    const entry = FLUENT_EMOJI_MAP[emoji];
    if (!entry) {
        // 映射表中没有，直接返回原生 emoji
        return emoji;
    }

    const isDefault = entry.startsWith('*');
    const name = isDefault ? entry.slice(1) : entry;
    const fileName = name.toLowerCase().replace(/\s+/g, '_');
    const encodedName = encodeURIComponent(name);

    let primaryUrl, fallbackUrl;
    if (isDefault) {
        primaryUrl = `${FLUENT_CDN_BASE}${encodedName}/Default/3D/${fileName}_3d_default.png`;
        fallbackUrl = `${FLUENT_CDN_BASE}${encodedName}/3D/${fileName}_3d.png`;
    } else {
        primaryUrl = `${FLUENT_CDN_BASE}${encodedName}/3D/${fileName}_3d.png`;
        fallbackUrl = `${FLUENT_CDN_BASE}${encodedName}/Default/3D/${fileName}_3d_default.png`;
    }

    // 最终回退：Twemoji
    const codePoint = [...emoji].map(c => c.codePointAt(0).toString(16)).join('-');
    const twemojiUrl = `https://cdn.jsdelivr.net/gh/twitter/twemoji@latest/assets/72x72/${codePoint}.png`;

    // 链式 onerror：主URL → 备用URL（内联处理） → Twemoji → 原生emoji（全局监听处理）
    return `<img class="fluent-emoji" src="${primaryUrl}" alt="${emoji}" title="${emoji}" loading="lazy" ` +
        `data-fallback="${fallbackUrl}" data-twemoji="${twemojiUrl}" ` +
        `onerror="if(this.dataset.retry==='0'){this.dataset.retry='1';this.src=this.dataset.fallback}" ` +
        `data-retry="0">`;
}

// 全局监听：备用URL失败回退到 Twemoji，Twemoji 也失败则显示原生 emoji
document.addEventListener('error', function(e) {
    const img = e.target;
    if (!img || !img.dataset) return;

    if (img.classList.contains('fluent-emoji') && img.dataset.retry === '1') {
        // 备用URL失败 → Twemoji
        img.dataset.retry = '2';
        img.src = img.dataset.twemoji;
        img.classList.remove('fluent-emoji');
        img.classList.add('twemoji');
    } else if (img.classList.contains('twemoji') && img.dataset.retry === '2') {
        // Twemoji 也失败 → 显示原生 emoji 文本
        const text = document.createTextNode(img.alt || '');
        if (img.parentNode) {
            img.parentNode.replaceChild(text, img);
        }
    }
}, true);

export function initEmojiPicker(app) {
    let panel = document.getElementById('emoji-panel');
    if (!panel) {
        panel = document.createElement('div');
        panel.id = 'emoji-panel';
        panel.className = 'emoji-panel';
        document.body.appendChild(panel);
    }

    // 使用 Fluent UI Emoji 3D 渲染表情
    panel.innerHTML = `<div class="emoji-grid">${EMOJI_DATA.map(item =>
        `<div class="emoji-item" title="${app.escapeHtml(item.name)}" data-emoji="${app.escapeHtml(item.emoji)}">${renderFluentEmoji(item.emoji)}</div>`
    ).join('')}</div>`;

    panel.addEventListener('mousedown', (e) => e.preventDefault());
    panel.querySelectorAll('.emoji-item').forEach(el => {
        el.addEventListener('click', () => {
            const emoji = el.dataset.emoji;
            if (emoji) insertEmoji(app, emoji);
        });
    });
    app.emojiPanel = panel;
}

function insertEmoji(app, emoji) {
    // Vditor 模式下插入到编辑器（textarea 已隐藏，直接写入其 value 不会反映到可见编辑器）
    if (app._publishVditor && typeof app._publishVditor.insertValue === 'function') {
        app._publishVditor.insertValue(emoji);
        hideEmojiPanel(app);
        return;
    }
    const input = document.getElementById(app.emojiTarget);
    if (!input) return;
    const start = input.selectionStart || 0;
    const end = input.selectionEnd || 0;
    const value = input.value;
    input.value = value.substring(0, start) + emoji + value.substring(end);
    const pos = start + emoji.length;
    input.setSelectionRange(pos, pos);
    input.focus();
    hideEmojiPanel(app);
}

export function toggleEmojiPicker(app, targetId, anchor) {
    if (!app.emojiPanel) return;
    const same = app.emojiPanel.classList.contains('active') && app.emojiTarget === targetId;
    if (same) {
        hideEmojiPanel(app);
        return;
    }
    app.emojiTarget = targetId;
    app.emojiAnchor = anchor;
    app.emojiPanel.classList.add('active');
    positionEmojiPanel(app, anchor);
    const input = document.getElementById(targetId);
    if (input && document.activeElement !== input) {
        input.focus();
    }
}

export function hideEmojiPanel(app) {
    if (app.emojiPanel) app.emojiPanel.classList.remove('active');
}

function positionEmojiPanel(app, anchor) {
    if (!anchor || !app.emojiPanel) return;
    const rect = anchor.getBoundingClientRect();
    const panelHeight = app.emojiPanel.offsetHeight || 240;
    const gap = 8;
    app.emojiPanel.style.bottom = 'auto';
    app.emojiPanel.style.top = 'auto';
    if (rect.top > panelHeight + gap) {
        app.emojiPanel.style.top = (rect.top - panelHeight - gap) + 'px';
    } else {
        app.emojiPanel.style.top = (rect.bottom + gap) + 'px';
    }
}

/**
 * 懒加载构建 emoji 匹配正则（基于 FLUENT_EMOJI_MAP 的键）
 * 按长度降序排列，确保多字符 emoji（如 ❤️）优先匹配
 */
let _emojiTextRegex = null;
function getEmojiTextRegex() {
    if (_emojiTextRegex === null) {
        const keys = Object.keys(FLUENT_EMOJI_MAP).sort((a, b) => b.length - a.length);
        _emojiTextRegex = new RegExp(keys.map(e => e.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|'), 'g');
    }
    return _emojiTextRegex;
}

/**
 * 将 HTML 文本中的 emoji 字符替换为 Fluent UI Emoji 3D 图片
 * 用于帖子/评论内容中渲染 3D 立体表情，替代系统原生扁平字体
 *
 * @param {string} html 已经过 HTML 转义的文本（含 <br>、<a> 等标签）
 * @returns {string} 替换后的 HTML
 */
export function renderTextEmoji(html) {
    if (!html) return html;
    const regex = getEmojiTextRegex();
    regex.lastIndex = 0;
    return html.replace(regex, (match) => renderFluentEmoji(match));
}
