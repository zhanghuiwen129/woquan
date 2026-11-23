#!/usr/bin/env node
/**
 * check-theme-sync.js —— 前端一致性 CI 门禁（一条命令跑完三类检查）
 *
 * 三类检查：
 *   1) 共享资源一致性
 *      调用 sync-theme-assets.js 的只读校验：动态发现现存主题（shaiboblog 已删除），
 *      仅对「所有现存主题都拥有」的显式共享清单（当前仅 emoji.js）做行尾归一化后的一致性比较；
 *      某主题缺少该资源视为其独立模块而跳过，不再误报「目标主题缺失」。
 *
 *   2) 版本戳一致性
 *      同一资源文件在 HTML / JS 里的所有引用，?v= 戳必须相同。
 *      现状里 utils.js 在 moments 内被引 4 种戳（20261115.1 / 20261112.2 /
 *      20261024.1 / ...），config.js 混用两戳 —— 这是真实错位。
 *
 *   3) 版本戳与内容匹配
 *      对每个被引用的静态资源，计算内容 sha1 前 8 位，?v= 必须等于该 hash
 *      （与 gen-asset-version.js 的产出格式完全一致）。
 *      这一项同时覆盖跨主题：两主题引用同一份内容 → hash 相同 → 戳自动相同。
 *
 * 输出格式统一为：文件:行号 | 当前戳 | 应为 | 原因
 *
 * 用法：
 *   node scripts/check-theme-sync.js            # 只检查，不写任何文件
 *   node scripts/check-theme-sync.js --fix      # 自动修正版本戳（调用 gen-asset-version 的写入逻辑）
 *
 * 退出码：0 = 全部通过 / 1 = 发现问题。
 */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');

const sync = require('./sync-theme-assets.js');
const gen = require('./gen-asset-version.js');

/* ------------------------------------------------------------------ *
 * 工具
 * ------------------------------------------------------------------ */

/** 仓库相对路径（统一正斜杠） */
function repoRelative(abs) {
    return path.relative(ROOT, abs).split(path.sep).join('/');
}

/** 统一的问题行：文件:行号 | 当前戳 | 应为 | 原因 */
function formatIssue(rel, line, current, expected, reason) {
    return `  ${rel}:${line} | ${current || '(无)'} | ${expected || '(无)'} | ${reason}`;
}

/* ------------------------------------------------------------------ *
 * 检查 1：共享模块一致性
 * ------------------------------------------------------------------ */
function checkSharedModules() {
    const report = sync.checkSharedModules({});
    const issues = [];

    for (const m of report.missing || []) {
        const where = m.sourceMissing ? m.sourcePath : m.targetPath;
        issues.push({
            kind: 'shared',
            rel: where,
            line: 0,
            current: '(缺失)',
            expected: '(存在)',
            reason: `共享资源 ${m.name} 在 ${m.sourceMissing ? '参考' : '其他'}主题中缺失`
        });
    }

    for (const r of report.results) {
        if (r.equal) continue;
        issues.push({
            kind: 'shared',
            rel: r.targetPath,
            line: r.firstDiff || 0,
            current: `${r.linesB} 行`,
            expected: `${r.linesA} 行`,
            reason: `共享资源 ${r.name} 与参考 ${r.sourcePath} 不一致（首个差异在第 ${r.firstDiff} 行）`
        });
    }

    return {
        name: '共享资源一致性',
        ok: report.ok,
        checked: report.compared != null ? report.compared : report.checked,
        issues,
        detail: {
            source: report.source,
            target: report.target,
            themes: report.themes,
            skipped: report.skipped,
            forked: report.forked
        }
    };
}

/* ------------------------------------------------------------------ *
 * 检查 2 & 3：版本戳一致性 / 版本戳与内容匹配
 * ------------------------------------------------------------------ */

/**
 * 收集全部「可解析 + 带字面量戳」的引用。
 * 扫描直接复用 gen-asset-version 的词法与路径解析逻辑，保证两者口径完全一致。
 */
function collectStampRefs() {
    const texts = new Map();
    for (const scope of gen.SCOPES) {
        for (const file of gen.scopeSourceFiles(scope)) {
            texts.set(file, fs.readFileSync(file, 'utf8'));
        }
    }
    const readForHash = (abs) => {
        const t = texts.get(abs);
        if (t !== undefined) return Buffer.from(t, 'utf8');
        return fs.readFileSync(abs);
    };
    const scan = gen.scanRefs(texts, readForHash, true);
    return { refs: scan.refs, skipped: scan.skipped, noStamp: scan.noStamp };
}

/**
 * 检查 2：同一资源文件的所有引用必须使用同一个 ?v= 戳。
 * 资源身份 = 解析出的目标文件绝对路径。
 */
function checkStampConsistency(refs) {
    const issues = [];

    // 目标绝对路径 -> 该目标下每一条引用
    const byTarget = new Map();
    for (const ref of refs) {
        if (ref.reason !== null) continue;
        if (!ref.hasStamp || !ref.target) continue;
        if (!byTarget.has(ref.target)) byTarget.set(ref.target, []);
        byTarget.get(ref.target).push(ref);
    }

    for (const [target, list] of byTarget) {
        // 该目标下出现过的不同戳
        const stamps = [...new Set(list.map((r) => r.stamp))];
        if (stamps.length <= 1) continue;

        // 多数戳作为「应为」值（内容 hash 已在检查 3 中单独核对）
        const counts = new Map();
        for (const r of list) counts.set(r.stamp, (counts.get(r.stamp) || 0) + 1);
        let majority = stamps[0];
        let best = -1;
        for (const s of stamps) {
            if (counts.get(s) > best) {
                best = counts.get(s);
                majority = s;
            }
        }

        for (const r of list) {
            if (r.stamp === majority) continue;
            issues.push({
                kind: 'consistency',
                rel: r.rel,
                line: r.line,
                current: r.stamp,
                expected: majority,
                reason: `资源 ${repoRelative(target)} 在此被引用为 ${r.stamp}，同文件其它引用用的是 ${majority}`
            });
        }
    }

    return {
        name: '版本戳一致性',
        ok: issues.length === 0,
        checked: byTarget.size,
        issues,
        detail: { targets: byTarget.size }
    };
}

/**
 * 检查 3：?v= 戳必须等于目标文件内容的 sha1 前 8 位。
 */
function checkStampMatchesContent(refs) {
    const issues = [];
    let checked = 0;

    for (const ref of refs) {
        if (ref.reason !== null) continue;
        if (!ref.hasStamp || !ref.target) continue;

        checked++;
        const expected = gen.hash8(fs.readFileSync(ref.target));
        if (ref.stamp === expected) continue;

        issues.push({
            kind: 'content',
            rel: ref.rel,
            line: ref.line,
            current: ref.stamp,
            expected,
            reason: `戳与内容不符：${repoRelative(ref.target)} 的内容 sha1 前 8 位为 ${expected}`
        });
    }

    return {
        name: '版本戳与内容匹配',
        ok: issues.length === 0,
        checked,
        issues,
        detail: {}
    };
}

/* ------------------------------------------------------------------ *
 * 输出
 * ------------------------------------------------------------------ */

function printCheck(check) {
    console.log(`--- 检查 ${check.name} ---`);
    if (check.issues.length === 0) {
        console.log(`  通过（检查 ${check.checked} 项，无问题）。`);
        console.log('');
        return;
    }
    console.log(`  发现 ${check.issues.length} 个问题：`);
    for (const i of check.issues) {
        console.log(formatIssue(i.rel, i.line, i.current, i.expected, i.reason));
    }
    console.log('');
}

/** 打印三类检查的总报告 */
function printReport(checks, extra) {
    for (const c of checks) printCheck(c);

    if (extra) {
        console.log('=== 扫描范围说明 ===');
        console.log(`  参与检查的静态资源引用总数：${extra.totalRefs}`);
        console.log(`  跳过（无法静态解析出目标戳）：${extra.skippedTotal} 处`);
        console.log(`    动态拼接（模板字符串 / 变量拼接）：${extra.skipped.dynamic}`);
        console.log(`    外链或 data: URI：${extra.skipped.external}`);
        console.log(`    目标文件不存在：${extra.skipped.notFound}`);
        console.log(`    ?v= 值非字面量（如 ?v=' + PAGE_VER）：${extra.skipped.dynamicStamp}`);
        console.log(`  可解析但无 ?v= 戳（不参与戳校验）：${extra.noStamp.count} 处`);
        console.log('');
    }

    const failed = checks.filter((c) => !c.ok);
    if (failed.length === 0) {
        console.log('结果：通过 —— 三类检查全部通过。');
    } else {
        console.log(`结果：失败 —— ${failed.length} 类检查未通过：`);
        for (const c of failed) {
            console.log(`  - ${c.name}（${c.issues.length} 个问题）`);
        }
    }
}

/* ------------------------------------------------------------------ *
 * 入口
 * ------------------------------------------------------------------ */

function main(argv) {
    const args = argv.slice(2);
    const fix = args.includes('--fix');

    console.log('=== 前端一致性检查（check-theme-sync） ===');
    if (fix) console.log('[--fix] 将自动改写版本戳，请确认工作区状态可回滚。');
    console.log('');

    // 检查 1
    const shared = checkSharedModules();

    // 检查 2 & 3
    const { refs, skipped, noStamp } = collectStampRefs();
    const consistency = checkStampConsistency(refs);
    const content = checkStampMatchesContent(refs);

    const checks = [shared, consistency, content];
    const skippedTotal =
        skipped.dynamic + skipped.external + skipped.notFound + skipped.dynamicStamp;

    printReport(checks, {
        totalRefs: refs.length,
        skipped,
        skippedTotal,
        noStamp
    });

    const failed = checks.some((c) => !c.ok);

    if (fix) {
        console.log('');
        console.log('=== 自动修正（--fix） ===');
        const plan = gen.planVersions();
        if (!plan.converged) {
            console.error(`  定点迭代未收敛：${plan.reason}`);
            process.exit(1);
        }
        if (!plan.changes.length) {
            console.log('  没有需要修正的版本戳。');
        } else {
            console.log(`  待修正 ${plan.changes.length} 处，涉及 ${plan.files.size} 个文件。`);
            const res = gen.applyPlan(plan);
            console.log(`  已写入 ${res.written.length} 个文件。`);
            for (const f of res.failed) console.error(`  写入失败：${f.file} —— ${f.reason}`);
        }
    }

    process.exit(failed ? 1 : 0);
}

if (require.main === module) {
    main(process.argv);
}

module.exports = {
    checkSharedModules,
    collectStampRefs,
    checkStampConsistency,
    checkStampMatchesContent,
    printReport,
    formatIssue
};
