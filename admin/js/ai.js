// ============================================================
// ai.js — AI 助手 / Ollama
// 由 V166 后台拆分脚本从 admin/index.html 切出，内容与源文件逐字节一致
// ============================================================
import { API_BASE, registerActions, request, showToast, token } from './core.js';

// B4b 委托化：静态 Ollama 检测 / 一键安装 / AI 调用测试
registerActions({
    'check-ollama-status': () => checkOllamaStatus(),
    'install-ollama': () => installOllama(),
    'test-ai-comment': () => testAiComment(),
    // B6 委托化：AI 服务商预置切换（原 onchange 直调）
    'preset-ai-provider': (d, el) => presetAiProvider(el.value),
});



// Ollama 状态检测
export async function checkOllamaStatus() {
    const box = document.getElementById('ollama-status-box');
    box.innerHTML = '<span style="color:var(--info);">正在检测...</span>';
    try {
        const data = await request('/admin/ai/ollama-status');
        if (data?.running) {
            const models = data.models || [];
            box.innerHTML = `<span style="color:var(--success);">Ollama 运行中</span>（${data.host}:${data.port}）<br>` +
                `<span style="color:var(--text-secondary);">已安装模型（${data.models_count}）：</span>` +
                (models.length > 0 ? models.map(m => `<code style="background:var(--surface-2);padding:2px 6px;border-radius:3px;margin:2px;">${m}</code>`).join(' ') : '<span style="color:var(--warn);">无模型，请点击下方「一键安装」或执行 ollama pull llama3.2</span>');
        } else {
            const osLabel = data.os === 'Windows' ? 'Windows' : (data.os === 'Darwin' ? 'macOS' : 'Linux');
            const hint = data.install_hint || '请访问 https://ollama.com/download 下载安装';
            box.innerHTML = `<span style="color:var(--danger);">Ollama 未运行</span><br>` +
                `<span style="color:var(--text-secondary);font-size:12px;">系统：${osLabel} | 请点击下方「一键安装」或手动安装：<code>${hint}</code></span>`;
        }
    } catch(e) {
        box.innerHTML = '<span style="color:var(--danger);">' + e.message + '</span>';
    }
}

// 一键安装 Ollama
export async function installOllama() {
    const model = document.getElementById('ollama-install-model').value;
    const btn = document.getElementById('btn-ollama-install');
    const logBox = document.getElementById('ollama-install-log');

    if (!confirm(model ? `确认一键安装 Ollama 并拉取模型「${model}」？\n\n安装过程可能需要 3-10 分钟，请勿关闭页面。` : '确认一键安装 Ollama？\n\n安装过程可能需要几分钟，请勿关闭页面。')) {
        return;
    }

    logBox.style.display = 'block';
    logBox.textContent = '开始安装，请耐心等待...\n';
    btn.disabled = true;
    btn.textContent = '安装中...';

    try {
        // 安装耗时长，用独立 fetch 请求，设置 10 分钟超时
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 600000);

        const res = await fetch(API_BASE + '/admin/ai/ollama-install', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + token
            },
            body: JSON.stringify({ model: model || '' }),
            signal: controller.signal
        });
        clearTimeout(timeoutId);

        // 先读取文本，避免空响应导致 json() 报错
        const text = await res.text();
        if (!text || !text.trim()) {
            throw new Error('服务器未返回数据（可能是执行超时或 Web 服务器超时）。请稍后点击「检测」按钮查看 Ollama 是否已安装成功，或参考下方手动安装方法。');
        }

        let data;
        try {
            data = JSON.parse(text);
        } catch(parseErr) {
            throw new Error('服务器返回了非 JSON 数据，可能是 Web 服务器错误页面。请稍后点击「检测」按钮查看状态，或参考手动安装方法。');
        }

        if (data.code !== 200) {
            throw new Error(data.message || '安装失败');
        }

        const result = data.data;
        if (result?.logs) {
            logBox.textContent = result.logs.join('\n');
        }

        logBox.textContent += '\n\n安装完成';
        logBox.scrollTop = logBox.scrollHeight;
        showToast('Ollama 安装成功');
        setTimeout(() => checkOllamaStatus(), 1000);
        if (model) {
            document.getElementById('setting-ai_comment_provider').value = 'ollama';
            document.getElementById('setting-ai_comment_api_url').value = 'http://localhost:11434/v1/chat/completions';
            document.getElementById('setting-ai_comment_model').value = model;
            showToast('已自动填充 Ollama 配置，请点击「保存」');
        }
    } catch(e) {
        if (e.name === 'AbortError') {
            logBox.textContent += '\n\n请求超时（超过 10 分钟）。安装可能仍在后台进行，请稍后点击「检测」按钮确认状态。';
            showToast('请求超时，请稍后检测状态');
        } else {
            logBox.textContent += '\n\n' + e.message;
            showToast(e.message);
        }
    } finally {
        btn.disabled = false;
        btn.textContent = '一键安装';
        logBox.scrollTop = logBox.scrollHeight;
    }
}

// 服务商预设
export function presetAiProvider(provider) {
    const presets = {
        ollama: { url: 'http://localhost:11434/v1/chat/completions', model: 'llama3.2', key: '' },
        deepseek: { url: 'https://api.deepseek.com/v1/chat/completions', model: 'deepseek-chat', key: '' },
        openai: { url: 'https://api.openai.com/v1/chat/completions', model: 'gpt-4o-mini', key: '' },
        zhipu: { url: 'https://open.bigmodel.cn/api/paas/v4/chat/completions', model: 'glm-4-flash', key: '' },
        moonshot: { url: 'https://api.moonshot.cn/v1/chat/completions', model: 'moonshot-v1-8k', key: '' },
        custom: { url: '', model: '', key: '' }
    };
    const p = presets[provider] || presets.custom;
    document.getElementById('setting-ai_comment_api_url').value = p.url;
    document.getElementById('setting-ai_comment_model').value = p.model;
    document.getElementById('setting-ai_comment_api_key').value = p.key;
}

// AI 测试调用
export async function testAiComment() {
    const result = document.getElementById('ai-test-result');
    const apiUrl = document.getElementById('setting-ai_comment_api_url').value;
    const apiKey = document.getElementById('setting-ai_comment_api_key').value;
    const model = document.getElementById('setting-ai_comment_model').value;

    if (!apiUrl) { showToast('请先填写 API 接口地址'); return; }

    result.innerHTML = '<span style="color:var(--info);">测试中...</span>';
    try {
        const data = await request('/admin/ai/test', {
            method: 'POST',
            body: { api_url: apiUrl, api_key: apiKey, model: model },
            timeout: 120000 // AI 生成可能较慢，放宽到 2 分钟
        });
        result.innerHTML = '<span style="color:var(--success);">' + (data.reply || '成功') + '</span>';
    } catch(e) {
        result.innerHTML = '<span style="color:var(--danger);">' + e.message + '</span>';
    }
}