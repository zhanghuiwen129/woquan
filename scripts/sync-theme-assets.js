#!/usr/bin/env node
/**
 * sync-theme-assets.js —— 跨主题「共享静态资源」一致性校验（只读）
 *
 * 【为什么改成现在这样】
 * 本脚本最初是 moments / shaiboblog 两套「字节镜像」主题的单一真源同步器；
 * shaiboblog 已随 7d71a2d 删除，仓库现存 moments + zixunfeed 两套主题，
 * 且二者**不是镜像**：zixunfeed 是独立代码库（模块划分不同，约 9766 行），
 * 白名单里的 14 个旧模块只有 6 个在 zixunfeed 存在，其中仅 emoji.js
 * 是同一份内容（moments 291 行 CRLF vs zixunfeed 纯 LF，归一化后逐字节相同），
 * 其余存在的文件要么差版本戳、要么已漂移上百行，属于独立演进。
 *
 * 因此「以 moments 为真源覆盖另一主题」的设计前提已不复存在：
 *   - 旧的 SYNC_PAIR=['moments','shaiboblog'] 让 --check 对已删主题误报 14 处缺失
 *     （这正是 check-theme-sync 那 14 处假阳性的来源）；
 *   - 若简单改成 ['moments','zixunfeed']，--write 会用 moments 覆盖 zixunfeed 的
 *     独立模块，造成严重破坏。
 *
 * 【现在的职责】
 *   - 动态发现 templates/ 下实际存在的主题，不再硬编码任何主题名；
 *   - 只校验一份极小的显式清单（SHARED_ASSETS）：仅在多个主题间确实共享的资源；
 *   - 只校验「所有现存主题都拥有」的资源；某主题没有该文件视为其独立模块，跳过而非报错；
 *   - 比较前统一 CRLF→LF：行尾风格差异不算内容差异（emoji.js 即此情形）；
 *   - **不再提供写入能力**：--write 已被移除并显式拒绝执行，杜绝任何覆盖 zixunfeed
 *     独立代码的可能性。需要同步的模块请人工确认后单独提交。
 *
 * 用法：
 *   node scripts/sync-theme-assets.js --check              # 校验共享资源一致性（只读）
 *   node scripts/sync-theme-assets.js                      # 同上（默认只读）
 *   node scripts/sync-theme-assets.js --write              # 已退役：拒绝执行，退出码 1
 *
 * 退出码：0 = 通过（或无主题/无共享资源可校验）/ 1 = 发现真实不一致，或使用了已退役的 --write。
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

/** 模块目录（相对主题根） */
const MODULE_SUBDIR = path.join('assets', 'js', 'modules');

/**
 * 跨主题共享资源白名单（显式、极小、数据驱动）。
 *
 * 判定标准（三条，缺一不可）：
 *   1) 在全部现存主题中都存在；
 *   2) 归一化行尾后内容逐字节一致（实测 emoji.js 是唯一满足者）；
 *   3) 语义上属于同一份实现，而非各主题独立演进的模块。
 *
 * 旧清单里的 api.js/config.js/events-bus.js/search.js 差版本戳，utils.js 漂移 142 行，
 * 其余 8 个在 zixunfeed 根本不存在 —— 全部不属于共享资源，故不再登记。
 * 新增条目前必须先用本脚本实测确认上述三条，禁止凭文件名相同就加入。
 */
const SHARED_ASSETS = [
    'emoji.js'
];

/** 参考主题：仅用于报告里标注真源一侧，不参与任何写入 */
const DEFAULT_SOURCE = 'moments';

/** 模块文件相对主题根的路径 */
function moduleRelPath(name) {
    return path.join(MODULE_SUBDIR, name);
}

/** 主题内某文件的绝对路径 */
function themeFile(theme, rel) {
    return path.join(ROOT, 'templates', theme, rel);
}

/** 仓库相对路径（统一正斜杠，便于打印） */
function repoRelative(abs) {
    return path.relative(ROOT, abs).split(path.sep).join('/');
}

/** 读取文件 Buffer；不存在返回 null */
function readBuffer(abs) {
    try {
        return fs.readFileSync(abs);
    } catch (e) {
        return null;
    }
}

/**
 * 动态发现 templates/ 下实际存在的主题目录。
 * 只纳入拥有 assets/ 的目录，避免把文档目录等误当主题。
 * @returns {string[]} 按名称排序的主题列表
 */
function discoverThemes() {
    const templatesDir = path.join(ROOT, 'templates');
    let entries = [];
    try {
        entries = fs.readdirSync(templatesDir, { withFileTypes: true });
    } catch (e) {
        return [];
    }
    return entries
        .filter((entry) => entry.isDirectory())
        .map((entry) => entry.name)
        .filter((name) => fs.existsSync(path.join(templatesDir, name, 'assets')))
        .sort();
}

/** 统一行尾：CRLF / CR → LF。仅此一种归一化，避免掩盖真实内容差异。 */
function normalize(buf) {
    return buf.toString('utf8').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

/** 归一化后是否一致 */
function assetsEqual(a, b) {
    return a !== null && b !== null && normalize(a) === normalize(b);
}

/**
 * 计算归一化内容首个差异行号与各自行数（用于生成差异摘要）。
 */
function diffSummary(bufA, bufB) {
    const linesA = normalize(bufA).split('\n');
    const linesB = normalize(bufB).split('\n');
    let firstDiff = 0;
    const max = Math.max(linesA.length, linesB.length);
    for (let i = 0; i < max; i++) {
        if ((linesA[i] ?? '') !== (linesB[i] ?? '')) {
            firstDiff = i + 1;
            break;
        }
    }
    return { firstDiff, linesA: linesA.length, linesB: linesB.length };
}

/**
 * 列出出现在多个主题、但不在共享清单里的模块（真分叉，禁止同步）。
 * @param {string[]} themes
 */
function listForkedModules(themes) {
    const byName = new Map();
    for (const theme of themes) {
        const dir = themeFile(theme, MODULE_SUBDIR);
        let names = [];
        try {
            names = fs.readdirSync(dir).filter((name) => name.endsWith('.js'));
        } catch (e) {
            continue;
        }
        for (const name of new Set(names)) {
            if (!byName.has(name)) byName.set(name, new Set());
            byName.get(name).add(theme);
        }
    }
    return [...byName.entries()]
        .filter(([name, owners]) => owners.size >= 2 && !SHARED_ASSETS.includes(name))
        .map(([name]) => name)
        .sort();
}

/**
 * 校验共享资源在全部现存主题间是否一致（只读，绝不写文件）。
 *
 * 语义：
 *   - 只有「所有现存主题都拥有」的资源才参与比较；
 *   - 某主题缺少该资源 → 记入 skipped（提示其独立演进），不算失败；
 *   - 参考主题自身缺少该资源 → 同样 skipped；
 *   - 比较统一行尾归一化后的内容，真实不一致才计入 results 并使 ok=false。
 *
 * @returns {{ok:boolean, results:Array, missing:Array, skipped:Array, checked:number,
 *            compared:number, source:string, target:string, themes:string[], forked:string[]}}
 */
function checkSharedModules(options = {}) {
    const themes = discoverThemes();
    const requestedSource = options.source || DEFAULT_SOURCE;
    const source = themes.includes(requestedSource) ? requestedSource : (themes[0] || requestedSource);
    const results = [];
    const missing = [];   // 保留字段以兼容调用方；正常运行时恒为空（不再有「已删主题缺失」误报）
    const skipped = [];

    for (const name of SHARED_ASSETS) {
        const rel = moduleRelPath(name);
        const sourceAbs = themeFile(source, rel);
        const sourceBuf = readBuffer(sourceAbs);
        if (sourceBuf === null) {
            skipped.push({
                name,
                reason: `参考主题 ${source} 无此文件，视为独立模块`,
                path: repoRelative(sourceAbs)
            });
            continue;
        }

        let someThemeMissing = false;
        let firstDifferentPath = null;
        let firstDiff = 0;
        let linesA = 0;
        let linesB = 0;
        let allEqual = true;

        for (const theme of themes) {
            if (theme === source) continue;
            const targetAbs = themeFile(theme, rel);
            const targetBuf = readBuffer(targetAbs);
            if (targetBuf === null) {
                someThemeMissing = true;
                skipped.push({
                    name,
                    reason: `主题 ${theme} 无此文件，视为独立模块`,
                    path: repoRelative(targetAbs)
                });
                continue;
            }
            if (!assetsEqual(sourceBuf, targetBuf)) {
                allEqual = false;
                if (firstDifferentPath === null) {
                    firstDifferentPath = repoRelative(targetAbs);
                    const summary = diffSummary(sourceBuf, targetBuf);
                    firstDiff = summary.firstDiff;
                    linesA = summary.linesA;
                    linesB = summary.linesB;
                }
            }
        }

        if (someThemeMissing) {
            // 只要有主题没有该文件，就不把它当作「必须一致」的共享资源来判定。
            continue;
        }

        const entry = {
            name,
            rel,
            equal: allEqual,
            sourcePath: repoRelative(sourceAbs),
            targetPath: firstDifferentPath || repoRelative(themeFile(themes[themes.length - 1] || source, rel)),
            bytes: sourceBuf.length
        };
        if (!allEqual) Object.assign(entry, { firstDiff, linesA, linesB });
        results.push(entry);
    }

    return {
        ok: missing.length === 0 && results.every((r) => r.equal),
        results,
        missing,
        skipped,
        checked: SHARED_ASSETS.length,
        compared: results.length,
        source,
        target: 'all existing themes',
        themes,
        forked: listForkedModules(themes)
    };
}

/**
 * 已退役的写入能力：显式拒绝，杜绝覆盖任何主题（尤其 zixunfeed）的独立模块。
 * 保留导出是为了让历史调用方拿到明确报错，而不是 "undefined is not a function"。
 * @returns {never}
 */
function syncSharedModules() {
    throw new Error(
        'syncSharedModules 已退役：shaiboblog 删除后 moments 与 zixunfeed 不再是镜像主题，'
        + '自动同步会覆盖 zixunfeed 的独立模块。请人工确认后单独提交变更。'
    );
}

/** 打印校验报告 */
function printCheckReport(report) {
    console.log('=== 跨主题共享资源一致性检查（只读） ===');
    console.log(`现存主题：${report.themes.length ? report.themes.join(' / ') : '(未发现)'}`);
    console.log(`参考主题：${report.source}   校验范围：${report.target}`);
    console.log(`共享清单：${report.checked}   实际参与比较：${report.compared}`);
    console.log('');

    for (const m of report.missing) {
        console.log(`[缺失] ${m.name}`);
        if (m.sourceMissing) console.log(`       参考主题缺失：${m.sourcePath}`);
        if (m.targetMissing) console.log(`       其他主题缺失：${m.targetPath}`);
    }

    for (const s of report.skipped) {
        console.log(`[跳过] ${s.name} —— ${s.reason}（${s.path}）`);
    }

    const bad = report.results.filter((r) => !r.equal);
    for (const r of bad) {
        console.log(`[不一致] ${r.name}`);
        console.log(`       参考：${r.sourcePath}`);
        console.log(`       其他：${r.targetPath}`);
        console.log(`       首个差异行号：第 ${r.firstDiff} 行（参考 ${r.linesA} 行 / 其他 ${r.linesB} 行）`);
    }

    if (report.ok) {
        console.log(`结果：通过 —— ${report.compared} 个共享资源在现存主题间一致（行尾归一化后比较）。`);
    } else {
        console.log(`结果：失败 —— ${bad.length} 个共享资源不一致。`);
    }

    if (report.forked.length) {
        console.log('');
        console.log(`独立模块已跳过（${report.forked.length} 个，各主题独立演进，禁止同步）：`);
        console.log('  ' + report.forked.join(', '));
    }
}

/** 入口 */
function main(argv) {
    const args = argv.slice(2);

    if (args.includes('--write')) {
        console.error('[已退役] --write 不再可用：moments 与 zixunfeed 不是镜像主题，自动同步会破坏 zixunfeed 的独立模块。');
        console.error('         本脚本现为只读校验器；请人工确认后单独提交需要同步的资源。');
        process.exit(1);
    }

    const check = args.includes('--check');
    if (!check && args.length) {
        console.error(`[错误] 未知参数：${args.join(' ')}`);
        console.error('用法：node scripts/sync-theme-assets.js [--check]');
        process.exit(1);
    }
    if (!check) {
        console.log('用法：node scripts/sync-theme-assets.js --check （默认按只读校验处理）');
        console.log('');
    }

    const report = checkSharedModules({});
    printCheckReport(report);
    process.exit(report.ok ? 0 : 1);
}

if (require.main === module) {
    main(process.argv);
}

module.exports = {
    SHARED_ASSETS,
    /** @deprecated 旧白名单别名，语义已收窄为跨主题共享资源 */
    SHARED_MODULE_WHITELIST: SHARED_ASSETS,
    SYNC_PAIR: discoverThemes(),
    DEFAULT_SOURCE,
    discoverThemes,
    checkSharedModules,
    syncSharedModules,
    listForkedModules,
    moduleRelPath
};
