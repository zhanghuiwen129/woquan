#!/usr/bin/env node
/**
 * gen-asset-version.js —— 把 ?v= 手工日期戳改成「目标文件内容 hash」
 *
 * 现状：仓库里全部前端静态资源引用都带 ?v=20261112.2 这样的**日期+序号**戳，
 *      共 300+ 处（moments / shaiboblog / zixunfeed / admin）。
 *      同一个 utils.js 在不同地方有 4 种不同戳；publish.js 在 moments 引
 *      utils.js?v=20261112.2、在 shaiboblog 引 ?v=20261112.1 —— 真实错位。
 *      日期戳靠人手维护，改了文件忘了同步戳，浏览器就继续吃旧缓存。
 *
 * 本脚本把版本戳改为**内容派生**：戳 = 目标文件内容的 sha1 前 8 位十六进制。
 *      内容变了 → 戳必变 → 缓存必失效；内容没变 → 戳不变 → 不产生无谓失效。
 *      两主题引用同一份内容 → 戳自动相同，顺带消灭跨主题错位。
 *
 * 关键实现约束（自引用问题）：
 *   目标文件本身可能含有 ?v= 戳（例如 app.js 里 import 了 modules/*.js?v=xxx）。
 *   戳 = 文件内容 hash，而文件内容又包含戳 → 一次扫描直接写会来回震荡。
 *   做法是**定点迭代**：每轮按「当前内容」重扫引用、算出期望戳、回写，
 *   直到某一轮无变化为止。迭代终点满足不变量：
 *      任意引用处的戳 == 目标文件**最终内容**的 sha1 前 8 位。
 *   因此第二次运行必然 0 变更（幂等）。若状态重复（循环依赖），
 *   脚本检测出来并报错退出，而不是死循环。
 *
 * 写入语义（只替换，不新增）：
 *   写入**只替换已有 ?v= 后面的那段值**，绝不改动 URL 的其余部分。
 *   本身没有 ?v= 的引用**不新增**戳 —— 那等于新增 query 段，
 *   会改变 URL 语义；且 admin/js 存在 import 环
 *   （points.js <-> finance.js、users.js <-> moderation.js），
 *   给环边补戳会让 hash 方程无解、迭代不收敛。
 *   实测：带戳依赖图无环、无自引用，收敛点唯一。
 *
 * 用法：
 *   node scripts/gen-asset-version.js              # dry-run：只打印清单，不写任何文件
 *   node scripts/gen-asset-version.js --write      # 原地回写
 *
 * 退出码：0 = 成功；1 = 定点迭代不收敛 / 写文件失败。
 */
'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..');

/** 扫描范围：主题整目录 + admin 的 index.html / js/ / css/ */
const SCOPES = [
    {
        name: 'moments',
        root: path.join(ROOT, 'templates', 'moments'),
        siteRoot: path.join(ROOT, 'templates', 'moments'),
        include: null // 整个主题目录
    },
    {
        name: 'shaiboblog',
        root: path.join(ROOT, 'templates', 'shaiboblog'),
        siteRoot: path.join(ROOT, 'templates', 'shaiboblog'),
        include: null
    },
    {
        name: 'zixunfeed',
        root: path.join(ROOT, 'templates', 'zixunfeed'),
        siteRoot: path.join(ROOT, 'templates', 'zixunfeed'),
        include: null
    },
    {
        name: 'admin',
        root: path.join(ROOT, 'admin'),
        siteRoot: path.join(ROOT, 'admin'),
        include: ['index.html', 'js', 'css'] // admin/pages 与 admin/vendor 不纳入
    }
];

/** 视为静态资源的扩展名 */
const ASSET_EXT = /\.(js|mjs|css|svg|png|jpe?g|gif|webp|ico|bmp|woff2?|ttf|otf|eot|json|mp3|mp4|webm)$/i;

/** 定点迭代最大轮数（超过即判定为循环依赖） */
const MAX_ROUNDS = 25;

/** 仓库相对路径（统一正斜杠） */
function repoRelative(abs) {
    return path.relative(ROOT, abs).split(path.sep).join('/');
}

/** 递归收集匹配扩展名的文件 */
function walk(dir, extRe, out = []) {
    if (!fs.existsSync(dir)) return out;
    for (const name of fs.readdirSync(dir)) {
        const p = path.join(dir, name);
        const st = fs.statSync(p);
        if (st.isDirectory()) {
            if (name === 'node_modules' || name === '.git') continue;
            walk(p, extRe, out);
        } else if (extRe.test(name)) {
            out.push(p);
        }
    }
    return out;
}

/** 某扫描范围下的源文件（.html / .js） */
function scopeSourceFiles(scope) {
    const out = [];
    if (scope.include) {
        for (const item of scope.include) {
            const p = path.join(scope.root, item);
            if (!fs.existsSync(p)) continue;
            const st = fs.statSync(p);
            if (st.isDirectory()) walk(p, /\.(html|js)$/i, out);
            else if (/\.(html|js)$/i.test(item)) out.push(p);
        }
    } else {
        walk(scope.root, /\.(html|js)$/i, out);
    }
    return out.sort();
}

/** 内容 sha1 前 8 位 */
function hash8(buf) {
    return crypto.createHash('sha1').update(buf).digest('hex').slice(0, 8);
}

/* ------------------------------------------------------------------ *
 * 词法：抽出「字符串字面量」
 * ------------------------------------------------------------------ */

/** 判定 / 是正则还是除号：看前一个有效字符 */
const REGEX_PREV = new Set(['(', ',', '=', ':', '[', '!', '&', '|', '?', '{', '}', ';']);
/** 后面跟着 / 属于正则的关键字 */
const REGEX_KEYWORDS = new Set([
    'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'case',
    'do', 'else', 'yield', 'await', 'throw'
]);

/**
 * 扫描 JS 源码中的字符串字面量（跳过注释与正则）。
 * 返回 [{start, end, quote, content}]，start/end 为内容区在原文中的下标。
 */
function scanJsLiterals(text) {
    const out = [];
    const n = text.length;
    let i = 0;
    let prevChar = '';
    let prevWord = '';

    const flushWord = (ch) => {
        if (/[A-Za-z0-9_$]/.test(ch)) prevWord += ch;
        else prevWord = '';
    };

    while (i < n) {
        const c = text[i];

        // 行注释
        if (c === '/' && text[i + 1] === '/') {
            while (i < n && text[i] !== '\n') i++;
            continue;
        }
        // 块注释
        if (c === '/' && text[i + 1] === '*') {
            i += 2;
            while (i < n && !(text[i] === '*' && text[i + 1] === '/')) i++;
            i += 2;
            continue;
        }
        // 单双引号字符串
        if (c === '"' || c === "'") {
            const start = i;
            i++;
            let content = '';
            while (i < n) {
                if (text[i] === '\\') {
                    content += text[i] + (text[i + 1] ?? '');
                    i += 2;
                    continue;
                }
                if (text[i] === c) {
                    i++;
                    break;
                }
                content += text[i];
                i++;
            }
            out.push({ start: start + 1, end: i - 1, quote: c, content });
            prevChar = 'x';
            prevWord = '';
            continue;
        }
        // 模板字符串
        if (c === '`') {
            const start = i;
            i++;
            let content = '';
            let depth = 0;
            while (i < n) {
                if (text[i] === '\\') {
                    content += text[i] + (text[i + 1] ?? '');
                    i += 2;
                    continue;
                }
                if (depth === 0 && text[i] === '`') {
                    i++;
                    break;
                }
                if (text[i] === '$' && text[i + 1] === '{') {
                    depth = 1;
                    content += '${';
                    i += 2;
                    continue;
                }
                if (depth > 0) {
                    if (text[i] === '{') depth++;
                    else if (text[i] === '}') depth--;
                    content += text[i];
                    i++;
                    continue;
                }
                content += text[i];
                i++;
            }
            out.push({ start: start + 1, end: i - 1, quote: '`', content });
            prevChar = 'x';
            prevWord = '';
            continue;
        }
        // 正则字面量
        if (c === '/' && (REGEX_PREV.has(prevChar) || REGEX_KEYWORDS.has(prevWord))) {
            i++;
            let inClass = false;
            while (i < n) {
                if (text[i] === '\\') {
                    i += 2;
                    continue;
                }
                if (text[i] === '[') inClass = true;
                else if (text[i] === ']') inClass = false;
                else if (text[i] === '/' && !inClass) {
                    i++;
                    break;
                } else if (text[i] === '\n') break;
                i++;
            }
            while (i < n && /[a-z]/i.test(text[i])) i++;
            prevChar = 'x';
            prevWord = '';
            continue;
        }

        flushWord(c);
        if (!/\s/.test(c)) prevChar = c;
        i++;
    }
    return out;
}

/**
 * 扫描 HTML 中的资源引用：
 *   - 标签属性 src / href / poster / data-src / data-original
 *   - 内联 <script> 块里的 import 语句（按 JS 词法扫描）
 * 返回 [{start, end, content}]，content 为引号内的原始文本。
 */
function scanHtmlRefs(text) {
    const out = [];
    // HTML 注释整体抹白（保留换行，保证下标不漂移）
    const masked = text.replace(/<!--[\s\S]*?-->/g, (m) => m.replace(/[^\n]/g, ' '));

    const TAG_RE = /<[a-zA-Z][^>]*>/g;
    let tm;
    while ((tm = TAG_RE.exec(masked))) {
        const tag = tm[0];
        const tagStart = tm.index;
        const ATTR_RE = /\b(?:src|href|poster|data-src|data-original|data-image)\s*=\s*("([^"]*)"|'([^']*)')/gi;
        let am;
        while ((am = ATTR_RE.exec(tag))) {
            const value = am[2] !== undefined ? am[2] : am[3];
            const valueOffset = am[0].lastIndexOf(value);
            const start = tagStart + am.index + valueOffset;
            out.push({ start, end: start + value.length, content: value });
        }
    }

    // 内联 script 块
    const SCRIPT_RE = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
    let sm;
    while ((sm = SCRIPT_RE.exec(masked))) {
        if (/\bsrc\s*=/i.test(sm[1])) continue; // 外链 script 已在属性里处理
        const body = sm[2];
        const bodyStart = sm.index + sm[0].indexOf(body);
        for (const lit of scanJsLiterals(body)) {
            out.push({
                start: bodyStart + lit.start,
                end: bodyStart + lit.end,
                content: lit.content
            });
        }
    }

    return out;
}

/* ------------------------------------------------------------------ *
 * 引用解析
 * ------------------------------------------------------------------ */

/** 计算某个下标所在的行号（1 起） */
function buildLineIndex(text) {
    const starts = [0];
    for (let i = 0; i < text.length; i++) {
        if (text[i] === '\n') starts.push(i + 1);
    }
    return (idx) => {
        let lo = 0;
        let hi = starts.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (starts[mid] <= idx) lo = mid;
            else hi = mid - 1;
        }
        return lo + 1;
    };
}

/** 去掉查询串与片段，得到纯路径 */
function splitUrl(url) {
    let rest = url;
    let fragment = '';
    const hashIdx = rest.indexOf('#');
    if (hashIdx >= 0) {
        fragment = rest.slice(hashIdx);
        rest = rest.slice(0, hashIdx);
    }
    const qIdx = rest.indexOf('?');
    const pathPart = qIdx >= 0 ? rest.slice(0, qIdx) : rest;
    const query = qIdx >= 0 ? rest.slice(qIdx + 1) : '';
    return { path: pathPart, query, fragment };
}

/**
 * 解析一个 URL 字面量，定位 v 参数的值区间。
 * stamp === null 表示「有 v 参数但值不是字面量」（动态拼接）。
 */
function parseStamp(query, queryOffsetInUrl) {
    const res = { stamp: null, stampStart: -1, stampEnd: -1, hasVParam: false };
    if (!query) return res;
    let off = 0;
    for (const param of query.split('&')) {
        if (param.startsWith('v=')) {
            res.hasVParam = true;
            const vs = off + 2;
            const m = /^[A-Za-z0-9_.\-]+/.exec(query.slice(vs));
            if (m && m[0].length) {
                res.stamp = m[0];
                res.stampStart = queryOffsetInUrl + vs;
                res.stampEnd = queryOffsetInUrl + vs + m[0].length;
            }
            break;
        }
        off += param.length + 1;
    }
    return res;
}

/** 文件名主干（去掉扩展名），用于剔除 '.mp4' 这类裸扩展名 */
function assetStem(pathStr) {
    const seg = (pathStr.split(/[\\/]/).pop() || '').trim();
    const m = seg.match(/^(.*?)(\.[A-Za-z0-9]+)$/);
    const stem = m ? m[1] : seg;
    return stem;
}

/** 是否外链 / data: URI */
function isExternal(pathStr) {
    if (/^https?:\/\//i.test(pathStr)) return true;
    if (/^\/\/[^\/]/.test(pathStr)) return true; // 协议相对外链
    if (/^data:/i.test(pathStr)) return true;
    return false;
}

/** 是否动态拼接（模板字符串 / 变量连接） */
function isDynamic(pathStr) {
    return pathStr.includes('${') || pathStr.includes('+');
}

/**
 * 判断一个 URL 是否为「静态资源引用候选」：
 *   - 主干非空且以资源扩展名结尾（xxx.js / xxx.css / xxx.svg ...）
 *   - 或带 v= 版本参数（说明它就是一个被打戳的资源引用）
 */
function isAssetCandidate(pathStr, hasVParam) {
    if (!pathStr) return false;
    if (hasVParam) return true;
    if (!ASSET_EXT.test(pathStr)) return false;
    return assetStem(pathStr) !== '';
}

/**
 * 解析引用目标：
 *   - 相对路径：HTML 按 <base href> 语义解析到主题根，JS 按自身所在目录解析
 *   - 以 / 开头：先按范围站点根（templates/<theme>/ 或 admin/），再回退仓库根（共享 vendor 资源）
 * 返回绝对路径；解析不到返回 null。
 */
function resolveTarget(pathStr, sourceAbs, scope) {
    if (!pathStr) return null;
    if (isExternal(pathStr)) return null;
    if (isDynamic(pathStr)) return null;

    const candidates = [];
    if (pathStr.startsWith('/')) {
        candidates.push(path.join(scope.siteRoot, pathStr));
        candidates.push(path.join(ROOT, pathStr));
    } else {
        const isHtml = /\.html?$/i.test(sourceAbs);
        // HTML 通过 <base href="/templates/<theme>/"> 解析到主题根；JS 用相对自身目录
        const base = isHtml ? scope.siteRoot : path.dirname(sourceAbs);
        candidates.push(path.resolve(base, pathStr));
        candidates.push(path.join(scope.siteRoot, pathStr));
        candidates.push(path.join(ROOT, pathStr));
    }

    for (const c of candidates) {
        const norm = path.resolve(c);
        if (!norm.startsWith(ROOT)) continue; // 不允许越出仓库
        try {
            if (fs.existsSync(norm) && fs.statSync(norm).isFile()) return norm;
        } catch (e) {
            /* ignore */
        }
    }
    return null;
}

/* ------------------------------------------------------------------ *
 * 扫描
 * ------------------------------------------------------------------ */

/**
 * 扫描一遍引用。
 * @param {Map<string,string>} texts   文件 -> 当前文本（定点迭代中的状态）
 * @param {(abs:string)=>Buffer} readForHash 读取目标内容（迭代中取状态，其余取磁盘）
 * @param {boolean} withHash 是否计算目标内容 hash
 */
function scanRefs(texts, readForHash, withHash = true) {
    const refs = [];
    const skipped = { dynamic: 0, external: 0, notFound: 0, dynamicStamp: 0, otherQuery: 0 };
    const noStamp = { count: 0, withOtherQuery: 0, samples: [] };

    for (const scope of SCOPES) {
        for (const file of scopeSourceFiles(scope)) {
            const text = texts.get(file);
            if (text === undefined) continue;
            const isHtml = /\.html?$/i.test(file);
            const lineOf = buildLineIndex(text);
            const literals = isHtml ? scanHtmlRefs(text) : scanJsLiterals(text);

            for (const lit of literals) {
                const url = lit.content;
                const { path: urlPath, query, fragment } = splitUrl(url);
                const stampInfo = parseStamp(query, urlPath.length + 1);
                if (!isAssetCandidate(urlPath, stampInfo.hasVParam)) continue;

                const ref = {
                    scope: scope.name,
                    file,
                    rel: repoRelative(file),
                    line: lineOf(lit.start),
                    url,
                    path: urlPath,
                    query,
                    stamp: stampInfo.stamp,
                    stampStart: stampInfo.stampStart >= 0 ? lit.start + stampInfo.stampStart : -1,
                    stampEnd: stampInfo.stampEnd >= 0 ? lit.start + stampInfo.stampEnd : -1,
                    // 是否带可替换的字面量戳（无戳引用不写入，见 noStamp 语义）
                    hasStamp: stampInfo.hasVParam && stampInfo.stamp !== null,
                    target: null,
                    hash: null,
                    reason: null
                };

                // 1) 动态拼接
                if (isDynamic(urlPath)) {
                    ref.reason = 'dynamic';
                    skipped.dynamic++;
                    refs.push(ref);
                    continue;
                }
                // 2) 外链 / data: URI
                if (isExternal(urlPath)) {
                    ref.reason = 'external';
                    skipped.external++;
                    refs.push(ref);
                    continue;
                }
                // 3) 目标文件解析不到
                const target = resolveTarget(urlPath, file, scope);
                if (!target) {
                    ref.reason = 'notFound';
                    skipped.notFound++;
                    refs.push(ref);
                    continue;
                }
                ref.target = target;

                // 4) 有 v 参数但值不是字面量（?v=' + PAGE_VER）
                if (stampInfo.hasVParam && stampInfo.stamp === null) {
                    ref.reason = 'dynamicStamp';
                    skipped.dynamicStamp++;
                    refs.push(ref);
                    continue;
                }
                // 5) 可解析但本身没有 ?v= 版本戳 —— 只统计，不写入
                //    （写入只允许替换已有 ?v= 的值，不得新增 query 段）
                if (!ref.hasStamp) {
                    ref.reason = null;
                    ref.noStamp = true;
                    if (query) noStamp.withOtherQuery++;
                    noStamp.count++;
                    if (noStamp.samples.length < 12) {
                        noStamp.samples.push(`${ref.rel}:${ref.line} | ${url}`);
                    }
                    if (withHash) ref.hash = hash8(readForHash(target));
                    refs.push(ref);
                    continue;
                }

                if (withHash) ref.hash = hash8(readForHash(target));
                refs.push(ref);
            }
        }
    }

    return { refs, skipped, noStamp };
}

/* ------------------------------------------------------------------ *
 * 定点迭代
 * ------------------------------------------------------------------ */

/**
 * 定点迭代求出最终戳。
 *
 * @returns {{
 *   converged: boolean, rounds: number, reason?: string,
 *   refs: Array, changes: Array, files: Set, skipped: Object, noStamp: Object, texts: Map
 * }}
 */
function planVersions() {
    // 状态：源文件绝对路径 -> 当前文本
    const texts = new Map();
    for (const scope of SCOPES) {
        for (const file of scopeSourceFiles(scope)) {
            texts.set(file, fs.readFileSync(file, 'utf8'));
        }
    }

    const readForHash = (abs) => {
        const t = texts.get(abs);
        if (t !== undefined) return Buffer.from(t, 'utf8');
        return fs.readFileSync(abs);
    };

    // 原始扫描（产出「旧戳 -> 新戳」清单）
    const original = scanRefs(texts, readForHash, true);
    const skipped = original.skipped;
    const noStamp = original.noStamp;

    // 引用按「文件 + 文件内出现序号」编号：迭代中戳长度变化会让字符下标漂移，
    // 用稳定 id 追踪同一个引用点。
    const counters = new Map();
    for (const ref of original.refs) {
        const n = counters.get(ref.file) || 0;
        counters.set(ref.file, n + 1);
        ref.id = `${ref.file}#${n}`;
    }

    const seenStates = new Map();
    let converged = false;
    let rounds = 0;
    let lastRefs = original.refs;

    for (let round = 1; round <= MAX_ROUNDS; round++) {
        rounds = round;

        // 状态指纹，用于检测震荡 / 循环依赖
        const fingerprint = hash8(
            Buffer.from(
                [...texts.entries()]
                    .sort((a, b) => (a[0] < b[0] ? -1 : 1))
                    .map(([k, v]) => `${k}\n${v}`)
                    .join('\n'),
                'utf8'
            )
        );
        if (seenStates.has(fingerprint)) {
            return {
                converged: false,
                rounds,
                reason: `内容状态在第 ${round} 轮重复（第 ${seenStates.get(fingerprint)} 轮已出现过），疑似循环依赖`,
                skipped,
                noStamp,
                refs: original.refs
            };
        }
        seenStates.set(fingerprint, round);

        // 按当前状态重扫（戳长度变化会移动字符下标，必须每轮重扫）
        const scan = scanRefs(texts, readForHash, true);
        lastRefs = scan.refs;

        const roundCounters = new Map();
        for (const ref of scan.refs) {
            const n = roundCounters.get(ref.file) || 0;
            roundCounters.set(ref.file, n + 1);
            ref.id = `${ref.file}#${n}`;
        }

        // 回写：把期望戳写进引用所在文件
        // 关键：同一文件内的多个编辑必须**从后往前**应用。
        // 戳长度会变（20261115.1 -> 49691716），若从前往后改，
        // 后面的编辑用的是改之前的下标，必然错位（曾产出 49691716tegories.js）。
        const byFile = new Map();
        let changed = 0;
        for (const ref of scan.refs) {
            if (ref.reason !== null) continue;
            if (!ref.hasStamp) continue; // 无戳引用不写入
            if (ref.stamp === ref.hash) continue;
            if (!byFile.has(ref.file)) byFile.set(ref.file, []);
            byFile.get(ref.file).push(ref);
        }
        for (const [file, items] of byFile) {
            let text = texts.get(file);
            // 下标降序：先改后面的，前面引用的下标不受影响
            items.sort((a, b) => b.stampStart - a.stampStart);
            for (const ref of items) {
                // 只替换 ?v= 后面的那一段值，URL 其余部分原样
                text = text.slice(0, ref.stampStart) + ref.hash + text.slice(ref.stampEnd);
                changed++;
            }
            texts.set(file, text);
        }

        if (changed === 0) {
            converged = true;
            break;
        }
    }

    if (!converged) {
        return {
            converged: false,
            rounds,
            reason: `定点迭代 ${MAX_ROUNDS} 轮仍未收敛`,
            skipped,
            noStamp,
            refs: original.refs
        };
    }

    // 合并：原始戳 + 收敛后的期望戳
    const finalById = new Map();
    for (const ref of lastRefs) finalById.set(ref.id, ref.stamp);

    const merged = [];
    const changes = [];
    for (const ref of original.refs) {
        const expected = finalById.get(ref.id);
        const row = {
            id: ref.id,
            scope: ref.scope,
            rel: ref.rel,
            file: ref.file,
            line: ref.line,
            path: ref.path,
            url: ref.url,
            current: ref.stamp,
            expected: expected === undefined ? null : expected,
            target: ref.target ? repoRelative(ref.target) : null,
            hasStamp: !!ref.hasStamp,
            noStamp: !!ref.noStamp,
            reason: ref.reason
        };
        merged.push(row);
        // 只有「带戳且戳值不同」的引用才会被写入
        if (row.reason === null && row.hasStamp && row.current !== row.expected) {
            changes.push(row);
        }
    }

    const files = new Set(changes.map((c) => c.file));
    return { converged: true, rounds, refs: merged, changes, files, skipped, noStamp, texts };
}

/**
 * 写入最终结果：只替换 ?v= 的值，其余字节原样保留（保持 CRLF / BOM / 缩进不变）。
 */
function applyPlan(plan) {
    if (!plan.converged) return { written: [], failed: [] };
    const written = [];
    const failed = [];

    // 状态文本本身就是「只替换戳值」的结果，其余字节原样
    for (const [file, text] of plan.texts) {
        try {
            const original = fs.readFileSync(file);
            const next = Buffer.from(text, 'utf8');
            if (original.equals(next)) continue;
            fs.writeFileSync(file, next);
            written.push(file);
        } catch (err) {
            failed.push({ file, reason: err.message });
        }
    }

    return { written, failed };
}

/** 打印扫描/跳过统计 */
function printStats(skipped, noStamp) {
    const total = skipped.dynamic + skipped.external + skipped.notFound + skipped.dynamicStamp;
    console.log('=== 跳过统计（无法静态解析出目标戳的引用） ===');
    console.log(`已跳过 ${total} 处：`);
    console.log(`  动态拼接（模板字符串 / 变量拼接）：${skipped.dynamic}`);
    console.log(`  外链或 data: URI：${skipped.external}`);
    console.log(`  目标文件不存在：${skipped.notFound}`);
    console.log(`  ?v= 值非字面量（如 ?v=' + PAGE_VER）：${skipped.dynamicStamp}`);
    console.log('');
    console.log(`另有 ${noStamp.count} 处引用可解析、但本身没有 ?v= 版本戳 —— 不改动（写入只允许替换已有 ?v= 的值）。`);
    if (noStamp.samples.length) {
        console.log('  样例：');
        for (const s of noStamp.samples) console.log(`    ${s}`);
    }
}

/** 入口 */
function main(argv) {
    const write = argv.includes('--write');
    const plan = planVersions();

    if (!plan.converged) {
        console.error('=== 版本戳生成失败 ===');
        console.error(`定点迭代未收敛：${plan.reason}`);
        console.error('可能存在互相引用、且内容互为戳来源的循环依赖，需要人工介入。');
        process.exit(1);
    }

    console.log(`=== 版本戳生成（${write ? '写入' : 'dry-run，不写文件'}） ===`);
    console.log(`定点迭代 ${plan.rounds} 轮收敛（戳 = 目标文件内容 sha1 前 8 位）。`);
    console.log('');

    if (!plan.changes.length) {
        console.log('没有需要变更的版本戳（已是内容 hash 且一致）。');
    } else {
        console.log('变更清单（文件:行号 | 旧戳 | 新戳）：');
        for (const c of plan.changes) {
            console.log(`  ${c.rel}:${c.line} | ${c.current || '(无)'} | ${c.expected}`);
        }
        console.log('');
        console.log(`汇总：改动 ${plan.changes.length} 处，涉及 ${plan.files.size} 个文件。`);
    }

    console.log('');
    printStats(plan.skipped, plan.noStamp);

    if (write) {
        const res = applyPlan(plan);
        console.log('');
        console.log(`已写入 ${res.written.length} 个文件。`);
        for (const f of res.failed) console.error(`  写入失败：${f.file} —— ${f.reason}`);
        process.exit(res.failed.length ? 1 : 0);
    }
    process.exit(0);
}

if (require.main === module) {
    main(process.argv.slice(2));
}

module.exports = {
    SCOPES,
    ASSET_EXT,
    scanRefs,
    planVersions,
    applyPlan,
    hash8,
    resolveTarget,
    repoRelative,
    scopeSourceFiles,
    scanJsLiterals,
    scanHtmlRefs,
    splitUrl,
    parseStamp,
    isAssetCandidate,
    isExternal,
    isDynamic,
    buildLineIndex
};
