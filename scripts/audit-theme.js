#!/usr/bin/env node
/**
 * audit-theme.js —— 前台主题 DOM 契约审计（P2-3）
 *
 * 目的：换主题时，新主题的 HTML 骨架必须守住 moments 模板的 DOM 契约，
 *      否则 22 个共用 JS 模块会因为 getElementById / querySelector 拿到 null 而静默失效。
 *
 * 契约基线取自 moments 自身的 18 个 HTML（不是 JS 引用的全集——JS 里有大量运行时动态
 * 生成的元素，那些 id 本就不在静态 HTML 里，纳入会误报）。做法：
 *   1) --gen：扫描 moments 的全部 HTML，提取出现的 id 与 class 作为「基线」；
 *      同时扫描 JS，提取字面量 getElementById('x') / querySelector('#x'/'.x')，
 *      与基线取交集，标注为「JS 强依赖的关键 id/class」。产出 templates/moments/contract.json。
 *   2) 审计（默认）：对每个待检主题，检查其 HTML 是否覆盖 contract.json 的基线 id/class
 *      全集（缺一个即报错）；moments 自身作为基线应零缺失。
 *
 * 用法：
 *   node scripts/audit-theme.js --gen                       # 重新生成基准主题 contract.json
 *   node scripts/audit-theme.js --gen --theme=zixunfeed     # 生成指定主题自己的 contract.json
 *   node scripts/audit-theme.js                             # 审计所有含 theme.json 的主题
 *   node scripts/audit-theme.js moments minimal             # 只审计指定主题
 *
 * 独立 JS 主题：theme.json 的 inherits_js 指向非基准主题（通常填自身 id）时，
 *   不共用 moments 的 JS 模块，套 moments 基线只会产生无意义误报；改走 standalone 分支，
 *   校验 18 骨架页 + 资源 + 入口引用齐全，并报告自身 JS 静态锚点命中数。
 *
 * 内容契约（见 docs/主题开发说明书.md 10.1）：与 DOM 契约同次审计，
 *   校验 theme.json 的 content_format / inline_media 声明，以及主题源码里
 *   正文内媒体占位符 [[media:<type>:<index>]] 的语法（防止各主题自造方言）。
 *   inline_media=true 时还要求存在解析实现与 videos / attachments 提交字段。
 *
 * 退出码：0 全部通过；1 存在契约缺失或错误。
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const TEMPLATES = path.join(ROOT, 'templates');
const BASE_THEME = 'moments';
const CONTRACT_PATH = path.join(TEMPLATES, BASE_THEME, 'contract.json');
const HTML_EXTS = /\.(html|htm)$/i;
const JS_EXTS = /\.js$/i;

// ===== 内容契约：正文格式 + 正文内媒体占位符（规范见 docs/主题开发说明书.md 10.1）=====
const CONTENT_FORMATS = ['markdown', 'html'];
// 唯一合法语法：媒体类型 + 对应数组的 0-based 下标，占位符内不含 URL
const MEDIA_PLACEHOLDER = /^\[\[media:(image|video|attachment):(\d+)\]\]$/;
// 模板字符串里允许用 ${...} 代替类型或下标（如 [[media:video:${i}]]）
const MEDIA_TEMPLATE = /^\[\[media:(?:image|video|attachment|\$\{[^}]*\}):(?:\d+|\$\{[^}]*\})\]\]$/;
// 宽扫描：任何双方括号媒体形态都要过语法校验，另造方言（如 [[video:0]]）同样报错
const MEDIA_TOKEN_SCAN = /\[\[(?:media|image|video|attachment)[^\]\n]*\]\]/g;
// 只有出现契约前缀才算「实现」，别的前缀属于方言而非实现
const MEDIA_ANY_SCAN = /\[\[media:/g;

/** 递归列出目录下匹配扩展名的文件 */
function walk(dir, exts, out = []) {
    if (!fs.existsSync(dir)) return out;
    for (const name of fs.readdirSync(dir)) {
        if (name === 'node_modules' || name === '.git') continue;
        const p = path.join(dir, name);
        const st = fs.statSync(p);
        if (st.isDirectory()) walk(p, exts, out);
        else if (exts.test(name)) out.push(p);
    }
    return out;
}

// 合法 HTML id/class 形态：字母/下划线开头，后接字母数字下划线连字符。
// 注意：不能用宽松的 [^"']+ —— 会把 JS 拼接串、data-page-node-id 后缀值等垃圾一起收进来。
const VALID_TOKEN = /^[A-Za-z_][\w-]*$/;

/** 从 HTML 文本提取所有 id 与 class 字面量 */
function extractDom(htmlText) {
    const ids = new Set();
    const classes = new Set();
    // id="xxx"：属性名前必须是标签起/空白，避免匹配 data-page-node-id= 这类后缀；值须是合法 token
    for (const m of htmlText.matchAll(/(?:^|[\s<])id\s*=\s*["']([^"']*)["']/gi)) {
        const v = m[1].trim();
        if (VALID_TOKEN.test(v)) ids.add(v);
    }
    // class="a b  c"（可含空格分隔多个），逐个过滤非法 token
    for (const m of htmlText.matchAll(/(?:^|[\s<])class\s*=\s*["']([^"']*)["']/gi)) {
        for (const c of m[1].split(/\s+/)) {
            const t = c.trim();
            if (VALID_TOKEN.test(t)) classes.add(t);
        }
    }
    return { ids, classes };
}

/** 合并多个文件的 DOM 提取结果 */
function collectDom(files) {
    const ids = new Set();
    const classes = new Set();
    for (const f of files) {
        let txt;
        try { txt = fs.readFileSync(f, 'utf8'); } catch { continue; }
        const { ids: i, classes: c } = extractDom(txt);
        for (const x of i) ids.add(x);
        for (const x of c) classes.add(x);
    }
    return { ids: [...ids].sort(), classes: [...classes].sort() };
}

/** 从 JS 文本提取字面量的 getElementById / querySelector 目标 */
function extractJsRefs(jsText) {
    const ids = new Set();
    const sels = new Set();
    // getElementById('xxx') / getElementById("xxx") —— 仅字面量（反引号/变量跳过）
    for (const m of jsText.matchAll(/getElementById\(\s*['"]([^'"]+)['"]\s*\)/g)) {
        if (m[1]) ids.add(m[1]);
    }
    // querySelector(All)?('...') —— 仅字面量
    for (const m of jsText.matchAll(/querySelectorAll?\(\s*['"]([^'"]+)['"]\s*\)/g)) {
        if (m[1]) sels.add(m[1]);
    }
    return { ids, sels };
}

function collectJsRefs(files) {
    const ids = new Set();
    const sels = new Set();
    for (const f of files) {
        let txt;
        try { txt = fs.readFileSync(f, 'utf8'); } catch { continue; }
        const { ids: i, sels: s } = extractJsRefs(txt);
        for (const x of i) ids.add(x);
        for (const x of s) sels.add(x);
    }
    return { ids: [...ids].sort(), sels: [...sels].sort() };
}

/** 从复合选择器里拆出首个 #id 或 .class 作为可审计锚点 */
function selectorAnchors(sel) {
    const anchors = [];
    for (const part of sel.split(/[\s>+~,]/)) {
        const idm = part.match(/^#([A-Za-z_][\w-]*)/);
        if (idm) { anchors.push({ type: 'id', value: idm[1] }); continue; }
        const cm = part.match(/^\.([A-Za-z_][\w-]*)/);
        if (cm) { anchors.push({ type: 'class', value: cm[1] }); continue; }
        break; // 首个 token 不是 id/class（如标签选择器、属性选择器）则不再深入
    }
    return anchors;
}

/** 占位符是否合规：写死的字面量严格匹配，模板字符串按形状匹配 */
function isLegalMediaToken(token) {
    return MEDIA_PLACEHOLDER.test(token)
        || (token.includes('${') && MEDIA_TEMPLATE.test(token));
}

/**
 * 内容契约校验：theme.json 声明 + 主题源码里占位符字面量的语法。
 * 注释里的语法示例不参与校验；不重排、不改写任何文件。
 * @returns {{errors: string[], warnings: string[]}}
 */
function auditContentContract(dir, meta, htmlFiles, jsFiles) {
    const errors = [];
    const warnings = [];

    const fmt = meta.content_format;
    if (fmt === undefined || fmt === null || fmt === '') {
        errors.push('theme.json 未声明 content_format（仅允许 markdown / html）');
    } else if (!CONTENT_FORMATS.includes(fmt)) {
        errors.push(`theme.json content_format 取值非法: ${fmt}（仅允许 markdown / html）`);
    }
    if (meta.inline_media !== undefined && typeof meta.inline_media !== 'boolean') {
        errors.push('theme.json inline_media 必须为布尔值');
    }

    let hasParser = false;
    const jsChunks = [];
    for (const [f, isJs] of [
        ...htmlFiles.map(f => [f, false]),
        ...jsFiles.map(f => [f, true]),
    ]) {
        let txt;
        try { txt = fs.readFileSync(f, 'utf8'); } catch { continue; }
        const code = isJs
            ? txt.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1')
            : txt.replace(/<!--[\s\S]*?-->/g, '');
        for (const token of code.match(MEDIA_TOKEN_SCAN) || []) {
            if (!isLegalMediaToken(token)) {
                errors.push(`非法媒体占位符 ${token}（${path.relative(dir, f)}）；语法为 [[media:<type>:<index>]]`);
            }
        }
        if (isJs) {
            jsChunks.push(txt);
            if ((code.match(MEDIA_ANY_SCAN) || []).length) hasParser = true;
        }
    }

    const jsText = jsChunks.join('\n');
    if (meta.inline_media === true) {
        if (!hasParser) {
            errors.push('声明 inline_media=true，但 assets/js 中未找到 [[media: 解析实现');
        }
        if (!/\b(videos|attachments)\b/.test(jsText)) {
            errors.push('声明 inline_media=true，但 assets/js 中未出现 videos / attachments 提交字段');
        }
        if (/upload-video/.test(jsText) && !/\bvideos\b/.test(jsText)) {
            errors.push('调用 /posts/upload-video 但未提交 videos 数组');
        }
        if (/upload-attachment/.test(jsText) && !/\battachments\b/.test(jsText)) {
            errors.push('调用 /posts/upload-attachment 但未提交 attachments 数组');
        }
    } else if (hasParser) {
        warnings.push('assets/js 中存在 [[media: 实现，但 theme.json 未声明 inline_media=true');
    }
    return { errors, warnings };
}

function generate(baseTheme = BASE_THEME) {
    const baseDir = path.join(TEMPLATES, baseTheme);
    const contractPath = path.join(baseDir, 'contract.json');
    const htmlFiles = walk(baseDir, HTML_EXTS);
    const jsFiles = walk(path.join(baseDir, 'assets', 'js'), JS_EXTS);
    if (!htmlFiles.length) {
        console.error(`[audit-theme] 未找到 ${baseTheme} 的 HTML 文件`);
        process.exit(1);
    }
    const dom = collectDom(htmlFiles);
    const js = collectJsRefs(jsFiles);

    const domIds = new Set(dom.ids);
    const domClasses = new Set(dom.classes);

    // 关键契约 = JS 字面量引用 ∩ HTML 基线（只在静态 HTML 里真实存在的才算）
    const criticalIds = js.ids.filter(id => domIds.has(id));
    const criticalClasses = new Set();
    for (const sel of js.sels) {
        for (const a of selectorAnchors(sel)) {
            if (a.type === 'id' && domIds.has(a.value)) criticalIds.push(a.value);
            if (a.type === 'class' && domClasses.has(a.value)) criticalClasses.add(a.value);
        }
    }
    const criticalIdSet = [...new Set(criticalIds)].sort();
    const criticalClassArr = [...criticalClasses].sort();

    // JS 引用但 HTML 基线里没有的 id = 运行时动态生成，记录但不纳入契约
    const dynamicIds = js.ids.filter(id => !domIds.has(id));

    const contract = {
        schema: 'hublog-theme-contract/1.0',
        base_theme: baseTheme,
        generated_by: 'scripts/audit-theme.js --gen',
        generated_at: new Date().toISOString(),
        stats: {
            html_files: htmlFiles.length,
            js_files: jsFiles.length,
            baseline_ids: dom.ids.length,
            baseline_classes: dom.classes.length,
            critical_ids: criticalIdSet.length,
            critical_classes: criticalClassArr.length,
            dynamic_ids_excluded: dynamicIds.length,
        },
        // 新主题必须覆盖的 DOM 基线全集（守住它 = 22 个 JS 模块功能不丢）
        baseline_ids: dom.ids,
        baseline_classes: dom.classes,
        // 其中被 JS 强依赖的关键子集（单独列出，便于人工重点核对）
        critical_ids: criticalIdSet,
        critical_classes: criticalClassArr,
        dynamic_ids_excluded: dynamicIds,
    };
    fs.writeFileSync(contractPath, JSON.stringify(contract, null, 2) + '\n', 'utf8');
    console.log(`[audit-theme] 已生成 ${path.relative(ROOT, contractPath)}`);
    console.log(`  基线: ${contract.stats.baseline_ids} id / ${contract.stats.baseline_classes} class`);
    console.log(`  JS 强依赖关键项: ${contract.stats.critical_ids} id / ${contract.stats.critical_classes} class`);
    console.log(`  JS 动态生成(已排除): ${contract.stats.dynamic_ids_excluded} id`);
}

function audit(themeIds) {
    if (!fs.existsSync(CONTRACT_PATH)) {
        console.error('[audit-theme] 缺少 contract.json，请先运行: node scripts/audit-theme.js --gen');
        process.exit(1);
    }
    const contract = JSON.parse(fs.readFileSync(CONTRACT_PATH, 'utf8'));
    let targets = themeIds;
    if (!targets || !targets.length) {
        // 自动发现：templates 下含 theme.json 的目录
        targets = fs.readdirSync(TEMPLATES, { withFileTypes: true })
            .filter(d => d.isDirectory() && fs.existsSync(path.join(TEMPLATES, d.name, 'theme.json')))
            .map(d => d.name);
    }
    if (!targets.length) {
        console.error('[audit-theme] 未发现任何含 theme.json 的主题');
        process.exit(1);
    }

    let hasError = false;
    let totalMissing = 0;
    let totalContentErrors = 0;
    for (const id of targets) {
        const dir = path.join(TEMPLATES, id);
        if (!fs.existsSync(path.join(dir, 'theme.json'))) {
            console.error(`[audit-theme] ✗ ${id}: 缺少 theme.json`);
            hasError = true;
            continue;
        }
        const htmlFiles = walk(dir, HTML_EXTS);
        if (!htmlFiles.length) {
            console.error(`[audit-theme] ✗ ${id}: 无 HTML 页面`);
            hasError = true;
            continue;
        }
        const jsFiles = walk(path.join(dir, 'assets', 'js'), JS_EXTS);
        const dom = collectDom(htmlFiles);
        const haveIds = new Set(dom.ids);
        const haveClasses = new Set(dom.classes);

        // 独立 JS 主题：theme.json 声明 inherits_js 指向自身（或任意非基线主题）时，
        // 它不共用 moments 的 22 个 JS 模块，套用 moments DOM 基线只会产生无意义误报。
        // 改为校验主题自身骨架完整性 + JS 静态锚点覆盖情况。
        let themeMeta = {};
        try { themeMeta = JSON.parse(fs.readFileSync(path.join(dir, 'theme.json'), 'utf8')); } catch (e) {}
        const standalone = !!themeMeta.inherits_js && themeMeta.inherits_js !== BASE_THEME;

        // 内容契约对两类主题一视同仁：声明合法性 + 占位符语法 + 实现完整性
        const content = auditContentContract(dir, themeMeta, htmlFiles, jsFiles);
        for (const w of content.warnings) console.log(`   ⚠ 内容契约 ${id}: ${w}`);
        if (content.errors.length) {
            hasError = true;
            totalContentErrors += content.errors.length;
            console.error(`[audit-theme] ✗ ${id}: 内容契约 ${content.errors.length} 项错误`);
            for (const e of content.errors) console.error(`   ${e}`);
        } else {
            console.log(`   · 内容契约 ${id}: ${themeMeta.content_format} / inline_media=${themeMeta.inline_media === true}`);
        }

        if (standalone) {
            const requiredPages = [
                'index', 'post', 'user', 'followers', 'following', 'pinned', 'favorites',
                'go', 'category', 'topic', 'circles', 'circle', 'search', 'chat', 'messages',
                'reset-password', 'wallet', 'points',
            ];
            const requiredAssets = [
                'assets/css/style.css', 'assets/js/app.js', 'assets/js/modules',
                'assets/images/default-avatar.svg', 'contract.json',
            ];
            const missingPages = requiredPages.filter(p => !fs.existsSync(path.join(dir, p + '.html')));
            const missingAssets = requiredAssets.filter(a => !fs.existsSync(path.join(dir, a)));
            const noEntry = htmlFiles.filter(f => {
                // 参考预览页（文件名含「预览」/preview）不参与主题审计
                if (/预览|preview/i.test(path.basename(f))) return false;
                // partials/ 是被各页面内联的片段，本身不引入 app.js
                if (/(^|[\\/])partials[\\/]/.test(f)) return false;
                try { return !/app\.js/.test(fs.readFileSync(f, 'utf8')); } catch (e) { return true; }
            });
            if (missingPages.length || missingAssets.length || noEntry.length) {
                hasError = true;
                console.error(`[audit-theme] ✗ ${id}: 独立主题骨架不完整`);
                if (missingPages.length) console.error(`   缺少页面: ${missingPages.map(p => p + '.html').join(', ')}`);
                if (missingAssets.length) console.error(`   缺少资源: ${missingAssets.join(', ')}`);
                if (noEntry.length) console.error(`   未引入 app.js: ${noEntry.slice(0, 8).map(f => path.basename(f)).join(', ')}`);
            } else {
                const js = collectJsRefs(jsFiles);
                const hit = js.ids.filter(x => haveIds.has(x)).length;
                const dynamic = js.ids.length - hit;
                console.log(`[audit-theme] ✓ ${id}: 独立 JS 主题，${requiredPages.length}/${requiredPages.length} 骨架页齐全；JS 静态锚点 ${hit} 个命中，${dynamic} 个运行时生成（不计入静态契约）`);
            }
            continue;
        }

        // 强契约：JS 强依赖的关键 id/class 必须全部存在
        const missingCriticalIds = contract.critical_ids.filter(x => !haveIds.has(x));
        const missingCriticalClasses = contract.critical_classes.filter(x => !haveClasses.has(x));
        // 完整基线覆盖（warning 级：不阻断，但提示可能丢视觉/结构）
        const missingBaselineIds = contract.baseline_ids.filter(x => !haveIds.has(x));
        const missingBaselineClasses = contract.baseline_classes.filter(x => !haveClasses.has(x));

        const criticalMissing = missingCriticalIds.length + missingCriticalClasses.length;
        if (criticalMissing) {
            hasError = true;
            totalMissing += criticalMissing;
            console.error(`[audit-theme] ✗ ${id}: 关键契约缺失 ${criticalMissing} 项（会破坏 JS 功能）`);
            if (missingCriticalIds.length) console.error(`   缺失 id: ${missingCriticalIds.join(', ')}`);
            if (missingCriticalClasses.length) console.error(`   缺失 class: ${missingCriticalClasses.join(', ')}`);
        } else {
            console.log(`[audit-theme] ✓ ${id}: 关键契约完整（${contract.critical_ids.length} id / ${contract.critical_classes.length} class）`);
        }
        const baselineMissing = missingBaselineIds.length + missingBaselineClasses.length;
        if (baselineMissing) {
            console.log(`   ⚠ 基线覆盖 ${id}: 缺 ${missingBaselineIds.length} id / ${missingBaselineClasses.length} class（可能丢视觉/结构，非阻断）`);
            if (missingBaselineIds.length) console.log(`     id: ${missingBaselineIds.slice(0, 20).join(', ')}${missingBaselineIds.length > 20 ? ' …' : ''}`);
        }
    }
    if (hasError) {
        console.error(`\n[audit-theme] 审计失败：${totalMissing} 项关键契约缺失，${totalContentErrors} 项内容契约错误`);
        process.exit(1);
    }
    console.log('\n[audit-theme] 全部通过');
}

const args = process.argv.slice(2);
const themeArg = args.find(a => a.startsWith('--theme='));
if (args.includes('--gen')) generate(themeArg ? themeArg.slice('--theme='.length) : BASE_THEME);
else audit(args.filter(a => !a.startsWith('--')));
