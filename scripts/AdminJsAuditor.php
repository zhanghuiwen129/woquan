<?php
/**
 * 后台 JS 静态审计器（P1-3 转义审计 + P2-3 lint 等价，2026-11-12）
 *
 * 背景：admin/js 曾有 98 处 innerHTML / 模板串拼接，转义靠人自觉（escapeHtml 调用
 * 115 次 vs innerHTML 98 处），漏一处即 XSS；B6 拆除 window 挂载后，跨模块裸调用
 * 会从「静默走 window」变成 ReferenceError。本审计器把两件事锚定为可自动执行的检查：
 *
 *   1. bareRefs      —— 跨模块裸引用：使用了其他模块的导出名却未 import。
 *                       拆 window 后这类代码直接 ReferenceError（B6 时 cron.js 缺
 *                       request import 就是实例），CI 必须红灯。
 *   2. unsafeWindows —— window 全局调用白名单：仅允许 __ 前缀内存态缓存与
 *                       location/open/CSS 等浏览器 API，防止 window 挂载回流。
 *   3. escapeIssues  —— 模板串 ${} 插值疑似未转义：插值里出现已知文本字段
 *                       （name/content/description/title/...）且未包 escapeHtml()。
 *                       数字插值与已转义插值不算。这是启发式规则，抓「字段名特征」，
 *                       宁可漏报不可误报（误报会让人习惯性忽略红灯）。
 *
 * 入口：scripts/audit-admin-js.php（CLI，高危>0 时 exit 1）；
 *       AdminInlineEventGuardTest 调用本类断言三项为零，纳入 CI 门禁。
 *
 * 纯静态文本分析：不加载 JS 引擎、不执行任何业务代码。
 */

declare(strict_types=1);

final class AdminJsAuditor
{
    /** 浏览器 API / 合法用途白名单（window.X 的 X 部分） */
    private const WINDOW_ALLOWLIST = [
        'location', 'open', 'innerWidth', 'innerHeight', 'CSS', 'confirm', 'alert',
        'prompt', 'getComputedStyle', 'requestAnimationFrame', 'setTimeout', 'setInterval',
        'clearTimeout', 'clearInterval', 'addEventListener', 'encodeURIComponent',
        'decodeURIComponent', 'history', 'navigator', 'atob', 'btoa',
        // vendor 经典脚本全局库（admin/vendor/ 下的非模块化第三方库）
        'Vditor',
    ];

    /** 已知文本字段名（出现在未转义插值中即视为高危；XSS 主战场） */
    private const TEXT_FIELDS = [
        'name', 'content', 'description', 'title', 'nickname', 'username', 'word',
        'remark', 'excerpt', 'text', 'label', 'message', 'body', 'reply', 'reason',
        'summary', 'desc', 'display', 'value', 'url', 'link', 'avatar', 'cover',
        'icon', 'email', 'phone', 'keyword', 'changelog', 'note', 'comment',
        'category_name', 'filename', 'post_content', 'diagnosis', 'license_info',
    ];

    /** 内部已妥善转义 / 返回可信 HTML 的白名单函数（新增时须在实现处注明理由） */
    private const SAFE_HTML_FUNCTIONS = ['escapeHtml(', 'escAttr(', 'badgeIconHtml('];

    /** @var array<string, array<string, true>> file => exported names */
    private array $exports = [];
    /** @var array<string, array<string, true>> file => imported names */
    private array $imports = [];
    /** @var array<string, string> file => source */
    private array $sources = [];
    /** @var array<string, true> 全量导出名 */
    private array $allExports = [];

    public function __construct(private string $adminJsDir)
    {
        foreach (glob(rtrim($adminJsDir, '/\\') . '/*.js') ?: [] as $file) {
            $src = (string)file_get_contents($file);
            $name = basename($file);
            $this->sources[$name] = $src;

            $exp = [];
            if (preg_match_all('/export\s+(?:async\s+)?function\s+(\w+)/', $src, $m)) {
                $exp = array_merge($exp, $m[1]);
            }
            if (preg_match_all('/export\s+(?:const|let|var)\s+(\w+)/', $src, $m)) {
                $exp = array_merge($exp, $m[1]);
            }
            if (preg_match_all('/export\s*\{([^}]+)\}/', $src, $m)) {
                foreach ($m[1] as $group) {
                    foreach (explode(',', $group) as $part) {
                        $n = trim(explode(' as ', $part)[0]);
                        if ($n !== '' && preg_match('/^\w+$/', $n)) {
                            $exp[] = $n;
                        }
                    }
                }
            }
            $this->exports[$name] = array_fill_keys($exp, true);
            foreach ($exp as $n) {
                $this->allExports[$n] = true;
            }

            $imp = [];
            if (preg_match_all('/import\s*\{([\s\S]*?)\}\s*from/', $src, $m)) {
                foreach ($m[1] as $group) {
                    foreach (explode(',', $group) as $part) {
                        $n = trim(explode(' as ', $part)[0]);
                        if ($n !== '' && preg_match('/^\w+$/', $n)) {
                            $imp[] = $n;
                        }
                    }
                }
            }
            $this->imports[$name] = array_fill_keys($imp, true);
        }
    }

    /**
     * 剥离 JS 注释（// 与 逐行状态机），保留字符串/模板串内容。
     * 模板串内的 ${...} 插值视为代码（bareRefs 需要检测插值里的调用）。
     * 用途：裸引用/window 检查在剥离后文本上跑，避免注释里的英文单词误报
     * （finance.js 注释提到 showPage、main.js 注释提到 token 都曾触发过误报）。
     */
    private static function stripComments(string $src): string
    {
        $out = '';
        $len = strlen($src);
        $i = 0;
        $state = 'code'; // code|line|block|squote|dquote|template
        $tplDepth = 0;   // 模板串 ${ } 嵌套深度（>0 时处于插值代码态）
        while ($i < $len) {
            $ch = $src[$i];
            $next = $i + 1 < $len ? $src[$i + 1] : '';
            switch ($state) {
                case 'code':
                    if ($ch === '/' && $next === '/') { $state = 'line'; $i += 2; $out .= ' '; continue 2; }
                    if ($ch === '/' && $next === '*') { $state = 'block'; $i += 2; $out .= ' '; continue 2; }
                    if ($ch === "'" || $ch === '"' || $ch === '`') {
                        $state = $ch === "'" ? 'squote' : ($ch === '"' ? 'dquote' : 'template');
                    }
                    $out .= $ch;
                    $i++;
                    break;
                case 'line':
                    if ($ch === "\n" || $ch === "\r") { $state = 'code'; $out .= $ch; }
                    $i++;
                    break;
                case 'block':
                    if ($ch === '*' && $next === '/') { $state = 'code'; $i += 2; $out .= ' '; continue 2; }
                    if ($ch === "\n") { $out .= $ch; }
                    $i++;
                    break;
                case 'squote':
                case 'dquote':
                    if ($ch === '\\') { $i += 2; $out .= '..'; continue 2; }
                    if (($state === 'squote' && $ch === "'") || ($state === 'dquote' && $ch === '"')) {
                        $state = 'code';
                    }
                    $out .= $ch;
                    $i++;
                    break;
                case 'template':
                    if ($ch === '\\') { $i += 2; $out .= '..'; continue 2; }
                    if ($ch === '`' && $tplDepth === 0) { $state = 'code'; $out .= $ch; $i++; break; }
                    if ($ch === '$' && $next === '{') { $tplDepth++; $out .= '${'; $i += 2; break; }
                    if ($ch === '}' && $tplDepth > 0) {
                        $tplDepth--;
                        if ($tplDepth === 0) { $out .= '}'; $i++; break; }
                        $out .= $ch; $i++; break;
                    }
                    if ($tplDepth > 0) {
                        // 插值内是代码：注释在这里同样剥离
                        if ($ch === '/' && $next === '/') { $i += 2; $out .= ' '; continue 2; }
                        if ($ch === '/' && $next === '*') { $state = 'tplblock'; $i += 2; $out .= ' '; continue 2; }
                    }
                    $out .= $ch;
                    $i++;
                    break;
                case 'tplblock':
                    if ($ch === '*' && $next === '/') { $state = 'template'; $i += 2; $out .= ' '; continue 2; }
                    if ($ch === "\n") { $out .= $ch; }
                    $i++;
                    break;
            }
        }
        return $out;
    }

    /** @return array<string, list<string>> file => 未 import 的其他模块导出名 */
    public function bareCrossModuleRefs(): array
    {
        $out = [];
        foreach ($this->sources as $file => $src) {
            $code = self::stripComments($src);
            $hits = [];
            foreach (array_keys($this->allExports) as $export) {
                if (isset($this->imports[$file][$export]) || isset($this->exports[$file][$export])) {
                    continue;
                }
                // 排除属性访问（namespace 引用 / 对象成员）
                if (preg_match('/(?<![.\w$])' . preg_quote($export, '/') . '\b/', $code)) {
                    $hits[] = $export;
                }
            }
            if ($hits !== []) {
                sort($hits);
                $out[$file] = $hits;
            }
        }
        return $out;
    }

    /** @return array<string, list<string>> file => 白名单外的 window.X 调用 */
    public function unsafeWindowAccess(): array
    {
        $out = [];
        foreach ($this->sources as $file => $src) {
            $code = self::stripComments($src);
            $hits = [];
            if (preg_match_all('/window\.(\w+)/', $code, $m)) {
                foreach ($m[1] as $prop) {
                    // __ 前缀为模块内存态缓存（约定俗成的合法用途）
                    if (str_starts_with($prop, '__')) {
                        continue;
                    }
                    if (!in_array($prop, self::WINDOW_ALLOWLIST, true)) {
                        $hits[] = $prop;
                    }
                }
            }
            if ($hits !== []) {
                $out[$file] = array_values(array_unique($hits));
            }
        }
        return $out;
    }

    /** @return array<string, list<string>> file => 疑似未转义的文本插值（含行号前缀） */
    public function unescapedTextInterpolations(): array
    {
        $out = [];
        foreach ($this->sources as $file => $src) {
            $hits = [];
            $lines = explode("\n", $src);
            foreach ($lines as $i => $line) {
                $lineNo = $i + 1;
                // textContent 赋值不解析 HTML，插值无 XSS 面，整行跳过
                if (preg_match('/\.textContent\s*=/', $line)) {
                    continue;
                }
                // 行级显式豁免（如「拼接后仅供 textContent 渲染」），用法：行尾 // audit-skip: 理由
                if (str_contains($line, 'audit-skip')) {
                    continue;
                }
                // 支持一层嵌套的 ${...}
                if (!preg_match_all('/\$\{((?:[^{}]|\{[^{}]*\})*)\}/', $line, $m)) {
                    continue;
                }
                foreach ($m[1] as $expr) {
                    $trimmed = trim($expr);
                    if ($trimmed === '') {
                        continue;
                    }
                    foreach (self::SAFE_HTML_FUNCTIONS as $safeFn) {
                        if (str_starts_with($trimmed, $safeFn)) {
                            continue 2;
                        }
                    }
                    // 弱规则：表达式内部任一处出现安全函数（嵌套模板串三元分支等）
                    // 视为已有防护。局限：多分支仅部分转义时会漏报，属启发式取舍。
                    foreach (self::SAFE_HTML_FUNCTIONS as $safeFn) {
                        if (str_contains($trimmed, $safeFn)) {
                            continue 2;
                        }
                    }
                    // 纯数字 / 算术表达式
                    if (preg_match('/^[\d\s+\-*\/().]+$/', $trimmed)) {
                        continue;
                    }
                    // 数字语义函数与比较
                    if (preg_match('/^(Number|parseInt|parseFloat)\s*\(/', $trimmed)) {
                        continue;
                    }
                    // 已知文本字段特征（含链式取值 / 三元等）→ 高危
                    foreach (self::TEXT_FIELDS as $field) {
                        if (preg_match('/\.' . preg_quote($field, '/') . '\b/i', $trimmed)) {
                            $hits[] = sprintf('L%d: ${%s}', $lineNo, $trimmed);
                            break;
                        }
                    }
                }
            }
            if ($hits !== []) {
                $out[$file] = $hits;
            }
        }
        return $out;
    }

    /** @return bool 三类检查全部为零 */
    public function isClean(): bool
    {
        return $this->bareCrossModuleRefs() === []
            && $this->unsafeWindowAccess() === []
            && $this->unescapedTextInterpolations() === [];
    }

    /** 输出人类可读报告（CLI 用） */
    public function report(): string
    {
        $lines = [];
        $sections = [
            '跨模块裸引用（拆 window 后 ReferenceError）' => $this->bareCrossModuleRefs(),
            'window 白名单外调用' => $this->unsafeWindowAccess(),
            '疑似未转义的文本插值（XSS 盲区）' => $this->unescapedTextInterpolations(),
        ];
        foreach ($sections as $title => $result) {
            $lines[] = "=== {$title} ===";
            if ($result === []) {
                $lines[] = '  (clean)';
            } else {
                foreach ($result as $file => $items) {
                    $lines[] = "  {$file}:";
                    foreach ($items as $item) {
                        $lines[] = "    {$item}";
                    }
                }
            }
            $lines[] = '';
        }
        $lines[] = $this->isClean() ? 'RESULT: CLEAN' : 'RESULT: FAIL';
        return implode("\n", $lines);
    }
}
