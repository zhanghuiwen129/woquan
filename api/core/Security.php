<?php
/**
 * Hublog 安全过滤类
 * XSS 输出转义（白名单策略）、输入净化、数据脱敏
 */

namespace Hublog\Core;

class Security
{
    /**
     * HTML 实体编码，用于前端输出时防 XSS（推荐策略）
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * 净化 HTML 内容：仅保留白名单标签，并移除所有事件处理器和 style
     * 注意：此函数用于输入净化，但输出时仍建议配合 escape() 双重保险
     *
     * 分层防御策略：
     * 1. DOMDocument 解析清理危险属性（处理畸形 HTML 绕过问题）
     * 2. 正则二次清理（双重保险）
     * 3. strip_tags 白名单标签过滤
     */
    public static function purify(string $html): string
    {
        if ($html === '' || trim($html) === '') {
            return '';
        }

        // 第一层：DOMDocument 解析清理危险属性（比正则更稳健，能正确处理畸形 HTML）
        $html = self::cleanHtmlWithDom($html);

        // 第二层：正则二次清理（双重保险，防止 DOM 解析遗漏）
        $html = self::purifyRegex($html);

        return $html;
    }

    /**
     * 正则净化（原始逻辑，作为 DOM 解析的回退和二次清理）
     */
    private static function purifyRegex(string $html): string
    {
        // 先移除所有事件属性 onxxx= 和 style=（处理带引号、无引号、单引号的情况）
        $html = preg_replace('/\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html);
        $html = preg_replace('/\s+style\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html);

        // 先 URL decode 一次，拦截 java%61script: / data%3Atext%2Fhtml;base64 等百分号绕过
        // （解码是临时副本用于判断；结果写回时用原始字符串替换，保留正确的转义）
        $decoded = @urldecode($html);
        if (is_string($decoded) && $decoded !== '' && $decoded !== $html) {
            // 如果解码后存在危险协议，用同规则的严格正则清除原始 html（避免编码字符绕过）
            if (preg_match('/(java|vb)\s*s\s*c\s*r\s*i\s*p\s*t\s*:/i', $decoded)
                || preg_match('/d\s*a\s*t\s*a\s*:\s*(text|application)\/(html|xhtml|xml|x-shockwave-flash)/i', $decoded)
            ) {
                // 先把 %XX 的可疑字符打散再清除
                $html = preg_replace('#%(?:4A|6A|4A|56|76|44|64|42|62|43|63|52|72|53|73|49|69|50|70|54|74|58|78|4D|6D|4C|6C)[0-9A-Fa-f]{0,2}(?:[89ABab][0-9A-Fa-f])?#u', '_', (string)$html);
            }
        }

        // 移除 javascript: / vbscript: 伪协议（去除空白字符后仍匹配，对抗 "java\u0000script:" 等编码绕过）
        $html = preg_replace_callback('/<\s*[a-z0-9]+[^>]*\s+(href|src|action|formaction|data|poster)=([^>]*)?>/iu', function ($m) {
            $full = $m[0];
            $tag = preg_replace('/(<\s*[a-z0-9]+\s+)/iu', '', $full, 1);
            if (!is_string($tag)) return $full;
            // 先 urldecode 一段看是否命中危险协议前缀
            $lower = strtolower(preg_replace('/[\x00-\x20\s]+/u', '', @urldecode($full) ?: $full));
            if (str_contains($lower, 'javascript:') || str_contains($lower, 'vbscript:')
                || preg_match('#data\s*:\s*(text|application)/(html|xhtml)#u', $lower)) {
                // 把整个属性值干掉
                return (string)preg_replace('/\s+(href|src|action|formaction|data|poster)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', ' ', $full);
            }
            return $full;
        }, $html);

        $html = preg_replace('/(j\s*a\s*v\s*a|v\s*b)\s*s\s*c\s*r\s*i\s*p\s*t\s*:/iu', '', $html);
        $html = preg_replace('/d\s*a\s*t\s*a\s*:\s*(text|application)\/(html|xhtml|xml|x-shockwave-flash)/iu', '', $html);
        $html = preg_replace('/livescript\s*:/iu', '', $html);
        $html = preg_replace('/mocha\s*:/iu', '', $html);

        // 保留白名单标签
        $allowedTags = '<p><br><b><i><u><strong><em><span><a><img><ul><ol><li><blockquote><code><pre><h1><h2><h3><h4><h5><h6><div><hr>';
        return strip_tags($html, $allowedTags);
    }

    /**
     * 使用 DOMDocument 解析 HTML 并在 DOM 层面移除危险属性
     * 比纯正则更稳健，能正确处理畸形 HTML（如未闭合标签、嵌套引号等）
     * DOMDocument 不可用或解析失败时回退到原始 HTML（由后续正则处理）
     */
    private static function cleanHtmlWithDom(string $html): string
    {
        if (!class_exists('DOMDocument')) {
            return $html;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        // 包装在 HTML 文档中并指定编码，避免 DOMDocument 的编码问题
        $wrapped = '<?xml encoding="UTF-8"?><html><body><div id="__hublog_purify_root__">'
            . mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')
            . '</div></body></html>';

        $previous = libxml_use_internal_errors(true);
        $loaded = @$dom->loadHTML($wrapped, LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return $html;
        }

        // 查找根节点（getElementById 在无 DTD 时可能失败，用 XPath 兜底）
        $root = $dom->getElementById('__hublog_purify_root__');
        if (!$root) {
            $xpath = new \DOMXPath($dom);
            $nodes = $xpath->query("//div[@id='__hublog_purify_root__']");
            $root = $nodes->length > 0 ? $nodes->item(0) : null;
        }
        if (!$root) {
            return $html;
        }

        // 递归清理所有元素节点的危险属性
        self::removeDangerousAttributes($root);

        // 提取清理后的 HTML 片段
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }
        return trim($result);
    }

    /**
     * 递归移除 DOM 节点树中的危险属性 + 非白名单标签整体剥离
     * - 移除所有 on* 事件属性（含带冒号/命名空间变体的 onX 写法）
     * - 移除 style 属性
     * - 移除 href/src/action/formaction/xlink:href/srcdoc 等危险协议（javascript/vbscript/data:html）
     * - 对 <a target="_blank"> 强制补 rel="noopener noreferrer"，防 window.opener 钓鱼
     * - 非白名单标签：剥掉标签外壳，保留其文本内容（让 `script/svg/iframe/math/template/object/embed` 不再是可执行上下文）
     */
    private static function removeDangerousAttributes(\DOMNode $node): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        // 白名单标签（与 purifyRegex 的 strip_tags 白名单对齐；大小写不敏感）
        static $allowedTags = [
            'p','br','b','i','u','strong','em','span','a','img',
            'ul','ol','li','blockquote','code','pre',
            'h1','h2','h3','h4','h5','h6','div','hr'
        ];

        // URL/资源型属性名：统一按危险协议校验（xlink:href 是 SVG <a> 常用的跳转属性，srcdoc 是 iframe 内联 HTML）
        static $urlAttrs = [
            'href','src','action','formaction','data','poster','xlink:href','srcdoc','background','ping'
        ];

        // 必须后向遍历（删除节点时不会跳过索引）
        $children = [];
        foreach ($node->childNodes as $c) $children[] = $c;
        foreach ($children as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE || !($child instanceof \DOMElement)) {
                // 非元素节点（文本/注释）若为 HTML 注释节点，整体干掉（<!-- --> 可被利用绕过）
                if ($child instanceof \DOMComment) {
                    $node->removeChild($child);
                }
                continue;
            }

            $tagName = strtolower($child->tagName);

            // P2-15：非白名单标签，用其文本+子节点内容替换自身（保留内容，剥掉危险外壳：script/svg/iframe/form/template/object/embed/math 等）
            if (!in_array($tagName, $allowedTags, true)) {
                // 把子节点从危险标签里搬出来，再干掉危险标签
                $frag = $node->ownerDocument->createDocumentFragment();
                while ($child->firstChild) {
                    $frag->appendChild($child->firstChild);
                }
                $node->replaceChild($frag, $child);
                continue; // 被替换节点已不在树里，不再处理它的属性
            }

            // a 标签：target=_blank 时强制加 noopener noreferrer（前端 window.opener 接管钓鱼防护）
            if ($tagName === 'a') {
                $target = strtolower(trim((string)$child->getAttribute('target')));
                if ($target === '_blank' || $target === '') {
                    // 默认 a 打开新窗口也统一加保护（target 为空时浏览器仍可打开新窗口，视前端设定）
                    $rel = trim((string)$child->getAttribute('rel'));
                    $need = [];
                    if (!str_contains($rel, 'noopener')) $need[] = 'noopener';
                    if (!str_contains($rel, 'noreferrer')) $need[] = 'noreferrer';
                    if (!empty($need)) {
                        $child->setAttribute('rel', trim(($rel !== '' ? $rel . ' ' : '') . implode(' ', $need)));
                    }
                }
                // 兜底：href 协议强制限制为 http/https/mailto/tel/ 相对路径，其他干掉（data/javascript vbscript 等）
                if ($child->hasAttribute('href')) {
                    $href = (string)$child->getAttribute('href');
                    $norm = strtolower((string)preg_replace('/[\x00-\x20\s\+]+/u', '', (string)@urldecode(@html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                    if ($norm !== '' && !str_starts_with($norm, 'http://') && !str_starts_with($norm, 'https://')
                        && !str_starts_with($norm, 'mailto:') && !str_starts_with($norm, 'tel:')
                        && !str_starts_with($norm, '#') && !str_starts_with($norm, '/')
                        && !str_starts_with($norm, './') && !str_starts_with($norm, '../')
                        && !str_starts_with($norm, '?')
                    ) {
                        $child->removeAttribute('href');
                    }
                }
            }

            // img 标签：只允许 http/https/data:image/*（允许内联小图），否则剥 src
            if ($tagName === 'img') {
                if ($child->hasAttribute('src')) {
                    $src = (string)$child->getAttribute('src');
                    $norm = strtolower((string)preg_replace('/[\x00-\x20\s\+]+/u', '', (string)@urldecode(@html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
                    $ok = ($norm === '')
                        || str_starts_with($norm, 'http://')
                        || str_starts_with($norm, 'https://')
                        || str_starts_with($norm, '/')
                        || str_starts_with($norm, './')
                        || str_starts_with($norm, '../')
                        || str_starts_with($norm, 'data:image/');
                    if (!$ok) {
                        $child->removeAttribute('src');
                    }
                }
            }

            // 属性清理
            if ($child->hasAttributes()) {
                $attrsToRemove = [];
                foreach ($child->attributes as $attr) {
                    $name = strtolower($attr->name);
                    $value = $attr->value;

                    // on* 事件属性（含带前缀的 XML 命名空间写法 onXXX、OnXxx、on-load 这种非标准但仍可能被解析的形式）
                    if (str_starts_with($name, 'on')) {
                        $attrsToRemove[] = $attr->nodeName;
                        continue;
                    }
                    // style
                    if ($name === 'style') {
                        $attrsToRemove[] = $attr->nodeName;
                        continue;
                    }
                    // URL/资源类属性：危险协议一律剥
                    if (in_array($name, $urlAttrs, true)) {
                        $v = (string)$value;
                        $valueDecoded = (string)@html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $valueDecoded = (string)@urldecode($valueDecoded);
                        $valueCleaned = strtolower((string)preg_replace('/[\x00-\x20\s\+]+/u', '', $valueDecoded));
                        if (str_starts_with($valueCleaned, 'javascript:')
                            || str_starts_with($valueCleaned, 'vbscript:')
                            || str_starts_with($valueCleaned, 'livescript:')
                            || str_starts_with($valueCleaned, 'mocha:')
                            || str_starts_with($valueCleaned, 'data:text/html')
                            || str_starts_with($valueCleaned, 'data:application/xhtml')
                            || str_starts_with($valueCleaned, 'data:application/xml')
                            || str_starts_with($valueCleaned, 'data:text/xml')
                            || str_starts_with($valueCleaned, 'data:application/x-shockwave-flash')
                        ) {
                            $attrsToRemove[] = $attr->nodeName;
                        }
                    }
                }
                foreach ($attrsToRemove as $attrName) {
                    $child->removeAttribute($attrName);
                }
            }

            // 递归
            self::removeDangerousAttributes($child);
        }
    }

    public static function maskPhone(?string $phone): string
    {
        if (empty($phone) || strlen($phone) !== 11) {
            return $phone ?? '';
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    public static function maskEmail(?string $email): string
    {
        if (empty($email) || !str_contains($email, '@')) {
            return $email ?? '';
        }
        [$local, $domain] = explode('@', $email);
        $localLen = strlen($local);
        if ($localLen <= 2) {
            return str_repeat('*', $localLen) . '@' . $domain;
        }
        return substr($local, 0, 2) . str_repeat('*', $localLen - 2) . '@' . $domain;
    }

    /**
     * 净化用户输入：转义 HTML 危险字符，保留代码片段、JSON、数学表达式的可读性
     *
     * 旧策略直接剥离 < > " ' % ; & { } 等字符，会破坏：
     * - 代码片段（含 < > 比较、& 运算符）
     * - JSON 示例（含 { } " :）
     * - 数学表达式（含 < > =）
     *
     * 新策略：仅移除控制字符（\x00-\x1F 除 \t \n \r），HTML 危险字符交给
     * escapeHtml() 在输出层处理。这样输入层保留原意，输出层负责防 XSS。
     */
    public static function sanitize(string $input): string
    {
        // 移除控制字符，但保留制表符、换行符、回车符
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $input);
    }

    public static function randomString(int $length = 32): string
    {
        return bin2hex(random_bytes((int)ceil($length / 2)));
    }

    /**
     * 接口限流检查
     * 优先使用后台设置；已登录用户按用户 ID 限流，游客按 IP 限流
     */
    public static function rateLimit(string $ip, string $action = 'api', ?int $userId = null, ?int $maxRequests = null, ?int $window = null): bool
    {
        $settings = Cache::remember('site_settings', function () {
            $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 300);

        if ($maxRequests === null) {
            $maxRequests = (int)($settings['rate_limit_requests'] ?? RATE_LIMIT_REQUESTS);
        }
        if ($window === null) {
            $window = (int)($settings['rate_limit_window'] ?? RATE_LIMIT_WINDOW);
        }
        if ($maxRequests <= 0) {
            $maxRequests = 300;
        }
        if ($window <= 0) {
            $window = 60;
        }

        $identity = $userId ? "user_{$userId}" : $ip;
        $key = "rate_limit_{$action}_{$identity}";

        // 使用文件锁保证读-检查-写的原子性，防止高并发突破限流阈值
        // 锁获取失败时降级为原逻辑（功能仍可用，仅并发精度下降）
        return Cache::withLock($key, function () use ($key, $maxRequests, $window) {
            $current = Cache::get($key) ?? 0;
            if ($current >= $maxRequests) {
                return false;
            }
            Cache::set($key, $current + 1, $window);
            return true;
        });
    }

    /**
     * P1-1 登录防爆破：基于「用户名 + IP」的阶梯式锁定
     *  - 失败 5 次以内：继续允许
     *  - 5~9 次：锁定 15 分钟
     *  - 10 次以上：锁定 24 小时
     *  - 成功登录：清除计数
     * 返回 ['locked' => bool, 'retry_after' => int 秒, 'attempts' => int]
     */
    public static function checkLoginLock(string $username, string $ip): array
    {
        $key = 'login_fail_' . md5(mb_strtolower($username) . '|' . $ip);
        $lockKey = 'login_lock_' . md5(mb_strtolower($username) . '|' . $ip);
        $lockUntil = Cache::get($lockKey);
        if ($lockUntil && $lockUntil > time()) {
            return ['locked' => true, 'retry_after' => (int)($lockUntil - time()), 'attempts' => (int)(Cache::get($key) ?? 0)];
        }
        if ($lockUntil && $lockUntil <= time()) {
            // 锁过期，清掉过期锁
            Cache::delete($lockKey);
        }
        return ['locked' => false, 'retry_after' => 0, 'attempts' => (int)(Cache::get($key) ?? 0)];
    }

    /**
     * P1-1 登录失败计数 + 锁定触发
     */
    public static function recordLoginFailure(string $username, string $ip): void
    {
        $key = 'login_fail_' . md5(mb_strtolower($username) . '|' . $ip);
        $lockKey = 'login_lock_' . md5(mb_strtolower($username) . '|' . $ip);
        $attempts = Cache::withLock($key, function () use ($key) {
            $cur = (int)(Cache::get($key) ?? 0) + 1;
            Cache::set($key, $cur, 86400); // 计数保留 24h，超过则自然过期
            return $cur;
        });
        if ($attempts >= 10) {
            Cache::set($lockKey, time() + 86400, 86400); // 锁 24 小时
        } elseif ($attempts >= 5) {
            Cache::set($lockKey, time() + 900, 900); // 锁 15 分钟
        }
        // 写独立审计日志
        try {
            Logger::warning('[Auth] 登录失败 user=' . $username . ' ip=' . $ip . ' attempts=' . $attempts);
        } catch (\Throwable $e) {
            // 日志失败不影响主流程
        }
    }

    /**
     * P1-1 登录成功后清除失败计数
     */
    public static function clearLoginFailures(string $username, string $ip): void
    {
        $key = 'login_fail_' . md5(mb_strtolower($username) . '|' . $ip);
        $lockKey = 'login_lock_' . md5(mb_strtolower($username) . '|' . $ip);
        Cache::delete($key);
        Cache::delete($lockKey);
    }

    /**
     * 会员系统治理 P0-1：注册独立限流（按 IP，默认 1 小时 10 次）。
     *
     * 全局 rateLimit 是 600 次/分钟，拦不住慢速批量注册；这里给注册单独设闸。
     * 与 rateLimit 共用 Cache::withLock 的原子读-检查-写，避免并发绕过。
     * 阈值可由后台 settings.register_max_per_hour 覆盖，便于运营调节。
     *
     * @return bool true=允许注册，false=触发限流
     */
    public static function checkRegisterLock(string $ip): bool
    {
        $max = 10;
        try {
            $settings = Cache::remember('site_settings', function () {
                $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
                $result = [];
                foreach ($rows as $row) {
                    $result[$row['key']] = $row['value'];
                }
                return $result;
            }, 300);
            if (isset($settings['register_max_per_hour'])) {
                $configured = (int)$settings['register_max_per_hour'];
                if ($configured > 0) {
                    $max = $configured;
                }
            }
        } catch (\Throwable $e) {
            // 配置读取失败时用保守默认值，不阻断注册
        }

        $key = 'register_ip_' . md5($ip);
        return Cache::withLock($key, function () use ($key, $max) {
            $current = (int)(Cache::get($key) ?? 0);
            if ($current >= $max) {
                return false;
            }
            Cache::set($key, $current + 1, 3600);
            return true;
        });
    }

    /**
     * V147 验证码防爆破（P0-7）：查询「手机号 + 场景」是否处于锁定状态
     *  - 失败 5 次以内：继续允许
     *  - 满 5 次：锁定 1 小时（期间即使验证码正确也拒绝）
     *  - 验证成功：由 clearCodeFailures 清除计数
     * 与登录锁定（checkLoginLock）同一模式，键空间独立。
     *
     * @return array{locked:bool, retry_after:int}
     */
    public static function checkCodeLock(string $phone, string $scene = 'login'): array
    {
        $key = 'code_fail_' . md5($phone . '|' . $scene);
        $lockKey = 'code_lock_' . md5($phone . '|' . $scene);
        $lockUntil = Cache::get($lockKey);
        if ($lockUntil && $lockUntil > time()) {
            return ['locked' => true, 'retry_after' => (int)($lockUntil - time())];
        }
        if ($lockUntil && $lockUntil <= time()) {
            // 锁过期，清掉过期锁
            Cache::delete($lockKey);
        }
        return ['locked' => false, 'retry_after' => 0];
    }

    /**
     * V147：验证码验证失败计数 + 锁定触发（5 次锁 1 小时）
     *
     * @return int 累计失败次数
     */
    public static function recordCodeFailure(string $phone, string $scene = 'login'): int
    {
        $key = 'code_fail_' . md5($phone . '|' . $scene);
        $lockKey = 'code_lock_' . md5($phone . '|' . $scene);
        $attempts = Cache::withLock($key, function () use ($key) {
            $cur = (int)(Cache::get($key) ?? 0) + 1;
            Cache::set($key, $cur, 3600); // 计数保留 1 小时，超过则自然过期
            return $cur;
        });
        if ($attempts >= 5) {
            Cache::set($lockKey, time() + 3600, 3600); // 锁 1 小时
        }
        return $attempts;
    }

    /**
     * V147：验证码验证成功后清除失败计数与锁定
     */
    public static function clearCodeFailures(string $phone, string $scene = 'login'): void
    {
        $key = 'code_fail_' . md5($phone . '|' . $scene);
        $lockKey = 'code_lock_' . md5($phone . '|' . $scene);
        Cache::delete($key);
        Cache::delete($lockKey);
    }

    /**
     * 验证密码强度
     * 默认策略：至少 8 位，必须包含大写字母、小写字母和数字
     * 可通过后台设置自定义要求（password_min_length / password_require_upper / password_require_lower / password_require_digit / password_require_special）
     */
    public static function checkPasswordStrength(string $password): array
    {
        $settings = Cache::remember('site_settings', function () {
            $rows = Database::fetchAll("SELECT `key`, `value` FROM settings");
            $result = [];
            foreach ($rows as $row) {
                $result[$row['key']] = $row['value'];
            }
            return $result;
        }, 300);

        $minLength = (int)($settings['password_min_length'] ?? 8);
        if ($minLength < 6) {
            $minLength = 6;
        }
        $requireUpper = ($settings['password_require_upper'] ?? '1') === '1';
        $requireLower = ($settings['password_require_lower'] ?? '1') === '1';
        $requireDigit  = ($settings['password_require_digit']  ?? '1') === '1';
        $requireSpecial = ($settings['password_require_special'] ?? '0') === '1';

        $errors = [];

        if (mb_strlen($password) < $minLength) {
            $errors[] = "密码至少{$minLength}位";
        }
        if ($requireUpper && !preg_match('/[A-Z]/', $password)) {
            $errors[] = '密码必须包含大写字母';
        }
        if ($requireLower && !preg_match('/[a-z]/', $password)) {
            $errors[] = '密码必须包含小写字母';
        }
        if ($requireDigit && !preg_match('/[0-9]/', $password)) {
            $errors[] = '密码必须包含数字';
        }
        if ($requireSpecial && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            $errors[] = '密码必须包含特殊字符';
        }

        // 计算强度等级（0-4）
        $strength = 0;
        if (mb_strlen($password) >= $minLength) $strength++;
        if (preg_match('/[A-Z]/', $password) && preg_match('/[a-z]/', $password)) $strength++;
        if (preg_match('/[0-9]/', $password)) $strength++;
        if (preg_match('/[^a-zA-Z0-9]/', $password)) $strength++;
        if (mb_strlen($password) >= 12) $strength++;

        return [
            'valid' => empty($errors),
            'strength' => min($strength, 4),
            'errors' => $errors
        ];
    }

    /**
     * SSRF 安全校验：验证远程 HTTP 请求的 URL 是否允许访问
     *
     * 防止攻击者（或被盗的管理员账号）让服务器发起内网/云元数据/本地文件请求：
     *  - 协议只允许 https / http（http 仅当 allowHttp=true 时可用）
     *  - 禁止 file:/// gopher:// dict:// telnet:// ftp:// 等危险协议
     *  - 禁止解析到内网 / 回环 / IPv6 本地 / 链路本地 / 云元数据（169.254.169.254）的主机
     *  - 禁止默认端口号之外的异常端口（如 22/3306/6379/11211）
     *  - 白名单：如果 $allowedHosts 非空，则主机必须在这个数组里（字符串精确匹配）
     *  - 允许本地 AI 地址：$allowLocalAi=true 时 127.0.0.1/::1 + 端口 ∈ [11434(Ollama),8080,8000,3000] 放行
     *
     * @param string $url           待验证 URL
     * @param array  $options       allowHttp(bool), allowLocalAi(bool), allowedHosts(string[])
     * @return array{ok:bool, reason:?string, host:?string, scheme:?string, port:?int}
     */
    public static function validateOutboundUrl(string $url, array $options = []): array
    {
        $allowHttp    = (bool)($options['allowHttp']    ?? false);
        $allowLocalAi = (bool)($options['allowLocalAi'] ?? false);
        $allowedHosts = array_values(array_filter(
            (array)($options['allowedHosts'] ?? []),
            'is_string'
        ));

        $result = [
            'ok'     => false,
            'reason' => null,
            'host'   => null,
            'scheme' => null,
            'port'   => null,
        ];

        if ($url === '' || strlen($url) > 2000) {
            $result['reason'] = 'URL 为空或过长';
            return $result;
        }

        // parse_url 对畸形协议名非常宽松，先预处理：只允许字母开头的 scheme（排除 "javascript:" 等非 http(s)）
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            $result['reason'] = 'URL 格式非法，缺少 scheme 或主机名';
            return $result;
        }
        $scheme = strtolower($parts['scheme']);
        $host   = (string)$parts['host'];
        $port   = isset($parts['port']) ? (int)$parts['port'] : null;

        $result['scheme'] = $scheme;
        $result['host']   = $host;
        $result['port']   = $port;

        // 1) 协议白名单
        if (!in_array($scheme, ['https', 'http'], true)) {
            $result['reason'] = "不允许的协议：{$scheme}（仅允许 https" . ($allowHttp ? "/http" : "") . "）";
            return $result;
        }
        if ($scheme === 'http' && !$allowHttp) {
            $result['reason'] = '不允许明文 HTTP，只允许 HTTPS（若为本地 Ollama 测试需单独开启）';
            return $result;
        }

        // 2) 主机名若在管理员显式白名单里，直接通过（但仍要求 https）
        if ($allowedHosts !== [] && in_array($host, $allowedHosts, true)) {
            $result['ok'] = true;
            return $result;
        }

        // 3) 禁止危险端口（即使主机合法，打到 3306=MySQL 也是盲打 SSRF）
        $defaultPort = ($scheme === 'https') ? 443 : 80;
        $actualPort  = $port ?? $defaultPort;
        $dangerousPorts = [
            21, 22, 23, 25, 53, 110, 111, 135, 137, 139, 143, 389, 445, 465, 512, 513, 514,
            587, 636, 873, 993, 995, 1080, 1433, 1521, 1723, 2049, 2082, 2083, 2375, 2376,
            3306, 3389, 4560, 5432, 5900, 5984, 6379, 7001, 8000, 8001, 8008, 8009, 8080,
            8161, 8443, 8888, 9000, 9009, 9092, 9200, 9300, 10000, 11211, 27017, 27018, 50000, 50070
        ];

        // 本地 AI 的放行端口（allowLocalAi=true 时启用）
        $localAiPorts = [11434, 3000, 8000, 8080];

        // 4) 解析 IPv4/IPv6，拿到所有解析结果（A/AAAA 都考虑）
        $records = [];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $records[] = ['type' => 'A',     'ip' => $host];
        } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $records[] = ['type' => 'AAAA',  'ip' => $host];
        } else {
            // 是域名 → 解析 A/AAAA
            $a = @dns_get_record($host, DNS_A);
            if (is_array($a)) {
                foreach ($a as $r) { if (!empty($r['ip'])) $records[] = ['type' => 'A', 'ip' => $r['ip']]; }
            }
            $aaaa = @dns_get_record($host, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $r) { if (!empty($r['ipv6'])) $records[] = ['type' => 'AAAA', 'ip' => $r['ipv6']]; }
            }
        }

        if ($records === []) {
            $result['reason'] = "无法解析主机：{$host}";
            return $result;
        }

        foreach ($records as $r) {
            $ip = $r['ip'];

            // 注意：FILTER_FLAG_LOOPBACK 是 PHP 8.0+ 才有的常量，实测线上 PHP 8.1.32
            // 却 defined() = false（编译环境差异），直接用会抛
            // "Undefined constant FILTER_FLAG_LOOPBACK" 导致整条外发请求失败。
            // 这里改用字符串判断回环地址（127.0.0.0/8 + ::1），不依赖该常量。
            $isLoopback  = (strpos($ip, '127.') === 0 || $ip === '::1' || $ip === '0:0:0:0:0:0:0:1');
            $isPrivate   = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
            $isReserved  = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)  === false;
            $isMetadata  = ($ip === '169.254.169.254' || $ip === 'fd00:ec2::254');
            $isLocalhost = ($isLoopback || strcasecmp($host, 'localhost') === 0);

            // 本地 AI 特殊放行：回环地址 + 端口在本地 AI 白名单里
            if ($allowLocalAi && $isLocalhost && in_array($actualPort, $localAiPorts, true)) {
                // 即使是本地 AI，也不允许打到其余危险端口（SSH/MySQL 等）
                $blockedForLocal = array_diff($dangerousPorts, $localAiPorts);
                if (!in_array($actualPort, $blockedForLocal, true)) {
                    continue;
                }
            }

            if ($isLoopback) { $result['reason'] = "禁止请求回环地址 {$ip}（SSRF 防护）"; return $result; }
            if ($isPrivate)  { $result['reason'] = "禁止请求内网私网地址 {$ip}（SSRF 防护）"; return $result; }
            if ($isReserved && !$isLocalhost) { $result['reason'] = "禁止请求保留网段地址 {$ip}（SSRF 防护）"; return $result; }
            if ($isMetadata) { $result['reason'] = "禁止请求云服务元数据地址 {$ip}（SSRF 防护）"; return $result; }

            // 对非本地地址：禁止危险端口
            if (in_array($actualPort, $dangerousPorts, true)) {
                $result['reason'] = "禁止请求端口 {$actualPort}（高危服务端口，SSRF 防护）";
                return $result;
            }
        }

        $result['ok'] = true;
        return $result;
    }

    /**
     * 配置 cURL 句柄的 SSRF 安全选项（强制协议+302 跳转限制）
     */
    public static function applyCurlSsrfOptions($ch, bool $allowHttp = false): void
    {
        $protocols = CURLPROTO_HTTPS;
        if ($allowHttp) {
            $protocols = CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }
        curl_setopt($ch, CURLOPT_PROTOCOLS, $protocols);
        // 跳转只允许 HTTPS，不允许跳转到本地 file/gopher（PHP 新版本默认只支持 http(s)，再加一层保险）
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    }
}
