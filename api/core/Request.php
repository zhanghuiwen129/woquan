<?php
/**
 * Hublog HTTP 请求处理类
 * 支持 JSON/FormData 解析、安全 IP 获取
 */

namespace Hublog\Core;

class Request
{
    private array $params = [];
    private array $headers = [];
    private array $files = [];
    /** 框架内部属性（如 AI 配额预扣 ID），与用户输入 params 严格隔离 */
    private array $attributes = [];
    private string $method;
    private string $path;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $this->headers = $this->getAllHeaders();
        $this->files = $_FILES;
        $this->parseInput();
    }

    private function getAllHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($name)] = $value;
            }
        }
        // Apache/PHP 将 Content-Type / Content-Length 放在无 HTTP_ 前缀的键中
        if (!empty($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (!empty($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }
        return $headers;
    }

    private function parseInput(): void
    {
        if ($this->method === 'GET') {
            $this->params = $_GET;
        } else {
            $contentType = $this->headers['content-type'] ?? '';
            if (str_contains($contentType, 'application/json')) {
                $input = file_get_contents('php://input');
                $this->params = !empty($input) ? (json_decode($input, true) ?? []) : [];
            } else {
                $this->params = $_POST;
            }
            // 合并 URL query 参数（兼容 DELETE/PUT 通过 ?id=xxx 传参）
            $this->params = array_merge($_GET, $this->params);
        }
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Router 解析出规范路径（剥离 /index.php、补 /api 前缀）后回写，
     * 使中间件与路由看到同一条路径。否则 CsrfMiddleware / AdminMiddleware
     * 的白名单按原始 PATH_INFO 路径匹配必然落空（登录 403 CSRF验证失败）。
     */
    public function setPath(string $path): void
    {
        $this->path = $path;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * S9：读取 URL query 参数。AdminUpgradeController 的 onlinePreflight /
     * onlineCompare / onlineRun 三处调用 $request->query('ref')，但本类从来没有
     * 这个方法 → 三个在线升级接口必然 500（且间接掩盖了 S3）。
     * 与 get() 的区别：只认 $_GET，不被 body 覆盖；未传返回 null。
     */
    public function query(string $key, ?string $default = null): ?string
    {
        if (!isset($_GET[$key]) || is_array($_GET[$key])) {
            return $default;
        }
        return (string)$_GET[$key];
    }

    public function getInt(string $key, int $default = 0): int
    {
        return isset($this->params[$key]) ? (int)$this->params[$key] : $default;
    }

    /**
     * 取字符串参数
     * $default 允许传 null（历史写法 getString($k, null) 会直接 TypeError 导致 500），
     * 这里放宽为 ?string，null 时按空字符串返回，避免一个约定写法炸掉整个接口。
     */
    public function getString(string $key, ?string $default = ''): string
    {
        return isset($this->params[$key]) ? (string)$this->params[$key] : ($default ?? '');
    }

    public function getArray(string $key): array
    {
        return isset($this->params[$key]) && is_array($this->params[$key]) ? $this->params[$key] : [];
    }

    /**
     * 获取浮点参数
     *
     * PHPStan 提级后揪出：ShopController 有 4 处写成 getFloat('price_balance', 0)，
     * 而旧签名只有 1 个参数 —— 默认值被 PHP 静默丢弃（缺失时返回 null 而不是 0）。
     * 补上可选默认值，既有单参调用行为完全不变。
     */
    public function getFloat(string $key, ?float $default = null): ?float
    {
        if (!isset($this->params[$key]) || $this->params[$key] === '' || $this->params[$key] === null) {
            return $default;
        }
        return (float)$this->params[$key];
    }

    /**
     * 获取布尔参数（支持字符串 '1','true','on' 等）
     */
    public function getBool(string $key): bool
    {
        if (!isset($this->params[$key])) {
            return false;
        }
        $val = $this->params[$key];
        if (is_bool($val)) {
            return $val;
        }
        if (is_numeric($val)) {
            return (int)$val === 1;
        }
        return in_array(strtolower((string)$val), ['1', 'true', 'on', 'yes'], true);
    }

    public function all(): array
    {
        return $this->params;
    }

    public function setParam(string $key, mixed $value): void
    {
        $this->params[$key] = $value;
        $_GET[$key] = $value;
    }

    /** 设置框架内部属性（不写入 $_GET / 用户输入） */
    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /** 读取框架内部属性；不存在返回默认值 */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->params[$key]) && $this->params[$key] !== '';
    }

    public function getHeader(string $key): ?string
    {
        return $this->headers[strtolower($key)] ?? null;
    }

    public function getBearerToken(): ?string
    {
        $auth = $this->getHeader('authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }
        return null;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function getFile(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * 获取真实客户端 IP（安全版，防伪造）
     *
     * 【安全原则】绝不默认信任客户端可控的转发头：
     * - X-Forwarded-For / X-Real-IP / Client-Ip 任何用户都能自己塞，默认会被攻击者伪造 IP
     * - 只有在 REMOTE_ADDR 命中管理员显式配置的 TRUSTED_PROXIES 可信代理列表时，才解析转发头
     * - 可信代理下优先取 X-Forwarded-For 最左（离客户端最近）的一段；没有才尝试 X-Real-IP
     * - 解析结果做 FILTER_VALIDATE_IP 校验；不可信直接 fallback 到 REMOTE_ADDR
     */
    public function getIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }

        $trusted = defined('TRUSTED_PROXIES') ? array_map('trim', explode(',', (string)TRUSTED_PROXIES)) : [];
        $isTrusted = false;
        foreach ($trusted as $cidr) {
            if ($cidr === '' || !$this->ipInCidr($remote, $cidr)) {
                continue;
            }
            $isTrusted = true;
            break;
        }

        if ($isTrusted) {
            // 1) X-Forwarded-For 优先级最高（CDN / 多层 LB 都会设置）
            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($xff !== '') {
                $segments = explode(',', $xff);
                // 取最左边第一个（离真实用户最近、最初被加上的那段）
                $ip = trim($segments[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }

            // 2) X-Real-IP（部分 Nginx 直接转发的配置）
            $xReal = $_SERVER['HTTP_X_REAL_IP'] ?? '';
            if ($xReal !== '') {
                $ip = trim($xReal);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        // 不可信代理 OR 无法解析时，直接使用 REMOTE_ADDR（虽然会是 CDN 地址，但至少不会被伪造）
        return $remote;
    }

    /**
     * 判断 IP 是否在 CIDR 网段内
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        $maskNum = (int)$mask;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        if ($maskNum === 0) {
            return true;
        }
        $maskLong = (-1 << (32 - $maskNum));
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * 获取分页参数
     */
    public function pagination(int $defaultLimit = 20, int $maxLimit = 50): array
    {
        return Helper::pagination($this, $defaultLimit, $maxLimit);
    }
}
