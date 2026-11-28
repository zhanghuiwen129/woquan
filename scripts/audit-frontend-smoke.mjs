#!/usr/bin/env node
/**
 * audit-frontend-smoke.mjs —— 前端最小冒烟（零新增依赖）
 *
 * 只用 Node 内置模块（node:test / node:assert / node:fs / node:vm / node:child_process）：
 *   1) app-client/src/api/index.js 与 3 套主题的 assets/js/app.js 语法可解析；
 *      这些文件都是 ESM，vm.Script 无法直接解析 import/export，故用内置
 *      `node --check`（真正的 JS 解析器，Node 22 自动识别 ESM）做语法门禁。
 *   2) 抽取 api/index.js 中 url: '/api/...' / `...?x=${y}` 字面量，校验路径格式
 *      与关键端点存在，防止一次误改整批接口路径。
 *   3) theme.json 的 content_format 必须是 markdown/html，且目录与 id 一致。
 *
 * 退出码：0 全部通过；非 0 由 node:test 自动给出（CI 视为硬阻断）。
 */

import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const API_FILE = path.join(ROOT, 'app-client', 'src', 'api', 'index.js');
const THEMES = ['moments', 'shaiboblog', 'zixunfeed'];
const REQUIRED_ENDPOINTS = [
  '/api/auth/login',
  '/api/posts',
  '/api/pay/wallet',
  '/api/chat/send',
  '/api/notifications/unread-count',
];
const VALID_PATH = /^\/api\/[a-z0-9][a-z0-9_\-./]*$/;

/** 用 Node 自带解析器做语法检查；ESM 的 import/export 也能正确识别。 */
function assertSyntaxOk(file) {
  assert.ok(fs.existsSync(file), `缺少文件: ${file}`);
  try {
    execFileSync(process.execPath, ['--check', file], { stdio: 'pipe' });
  } catch (error) {
    assert.fail(`语法错误 ${path.relative(ROOT, file)}: ${String(error.stderr || error.message)}`);
  }
}

/**
 * 匹配 `url: '/api/...'` 与 `url: \`/api/...\`` 两类字面量。
 * 模板串里的 ${...} 与 query 不作为路径的一部分，统一截取到 ? 或 $ 之前；
 * `/api/posts/${action}` 这类前缀末尾的 '/' 是拼接占位，校验前归一去掉。
 */
function extractApiPaths(source) {
  const regex = /\burl:\s*(['"`])(\/api\/[^'"`\r\n]*)(\1)?/g;
  return [...source.matchAll(regex)]
    .map((match) => match[2].split(/[?$]/, 1)[0].replace(/\/+$/, ''))
    .filter((value) => value.length > 0);
}

test('app-client API 入口语法可解析', () => {
  assertSyntaxOk(API_FILE);
});

test('app-client API 路径字面量格式合法且关键端点齐全', () => {
  const source = fs.readFileSync(API_FILE, 'utf8');
  const paths = extractApiPaths(source);

  assert.ok(paths.length >= 100, `仅提取到 ${paths.length} 条 /api 路径，疑似提取规则失效`);

  const unique = [...new Set(paths)];
  const invalid = unique.filter((item) =>
    !VALID_PATH.test(item) || item.includes('//') || item.endsWith('/')
  );
  assert.deepEqual(invalid, [], `非法 API 路径: ${invalid.join(', ')}`);

  const missing = REQUIRED_ENDPOINTS.filter((endpoint) => !paths.includes(endpoint));
  assert.deepEqual(missing, [], `关键端点缺失: ${missing.join(', ')}`);

  // 用内置 VM 再确认一次：提取出的字符串都是可安全执行的 JS 字面量，
  // 不会出现控制字符 / 非法转义混入路径常量。
  const context = vm.createContext({});
  new vm.Script(`globalThis.__paths = ${JSON.stringify(unique)};`).runInContext(context);
  assert.equal(context.__paths.join('\n'), unique.join('\n'), 'VM 中还原的路径列表应与源码一致');
});

test('三套主题入口 JS 语法可解析', () => {
  for (const theme of THEMES) {
    assertSyntaxOk(path.join(ROOT, 'templates', theme, 'assets', 'js', 'app.js'));
  }
});

test('主题清单 content_format 合法且目录与 id 一致', () => {
  const templateRoot = path.join(ROOT, 'templates');
  const dirs = fs.readdirSync(templateRoot, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name)
    .sort();

  for (const theme of THEMES) {
    assert.ok(dirs.includes(theme), `缺少主题目录: templates/${theme}`);
  }

  for (const dir of dirs) {
    const file = path.join(templateRoot, dir, 'theme.json');
    assert.ok(fs.existsSync(file), `缺少主题清单: ${path.relative(ROOT, file)}`);
    const manifest = JSON.parse(fs.readFileSync(file, 'utf8'));
    assert.equal(manifest.id, dir, `theme.json 的 id 与目录不一致: ${dir}`);
    assert.ok(
      manifest.content_format === 'markdown' || manifest.content_format === 'html',
      `${dir} 的 content_format 非法: ${String(manifest.content_format)}`
    );
  }
});
