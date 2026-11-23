<?php
/**
 * Hublog 路由系统
 * 支持子目录自适应、中间件链、动态参数
 */

namespace Hublog\Core;

use Hublog\Core\Exception\HublogException;

class Router
{
    private array $routes = [];
    private array $groupStack = [];
    private Request $request;

    public function __construct()
    {
        $this->request = new Request();
    }

    public function get(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /** S4：CORS 头与 CsrfMiddleware 都已按 PATCH 设计，路由层此前却没有 patch()。 */
    public function patch(string $path, callable|array $handler, array $middleware = []): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, callable|array $handler, array $middleware): void
    {
        $fullPath = '';
        $fullMiddleware = [];
        foreach ($this->groupStack as $group) {
            $fullPath .= rtrim($group['prefix'], '/');
            $fullMiddleware = array_merge($fullMiddleware, $group['middleware']);
        }
        $fullPath .= $path;
        $fullMiddleware = array_merge($fullMiddleware, $middleware);

        $this->routes[$method][$fullPath] = [
            'handler' => $handler,
            'middleware' => $fullMiddleware
        ];
    }

    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $this->groupStack[] = ['prefix' => $prefix, 'middleware' => $middleware];
        $callback($this);
        array_pop($this->groupStack);
    }

    public function dispatch(): Response
    {
        $method = $this->request->getMethod();
        $path = $this->resolvePath();
        // P2 修复：把解析后的规范路径回写给 Request，保证中间件（Csrf/Admin/License）
        // 与路由看到同一条路径。PATH_INFO 模式下原始路径形如 /api/index.php/auth/login，
        // CsrfMiddleware 的公开白名单按 /api/auth/login 匹配必然落空 → 登录 403「CSRF验证失败」。
        $this->request->setPath($path);

        // 处理OPTIONS预检请求
        if ($method === 'OPTIONS') {
            return Response::success(null, 'OK');
        }

        $route = $this->findRoute($method, $path);

        if (!$route) {
            throw new HublogException('路由未找到', 404);
        }

        // 执行中间件
        // S4：原实现只处理 is_string，传了闭包或中间件对象会被静默跳过 ——
        // 这是安全降级隐患（限流/鉴权看起来挂了、实际没跑）。现在显式支持
        // 类名字符串 / Closure / 带 handle() 的对象，其余一律报错。
        foreach ($route['middleware'] as $middleware) {
            if (is_string($middleware)) {
                $middlewareClass = "Hublog\\Middleware\\{$middleware}";
                if (!class_exists($middlewareClass)) {
                    throw new HublogException("中间件 {$middleware} 不存在", 500);
                }
                $instance = new $middlewareClass();
                $shortCircuit = $instance->handle($this->request);
            } elseif ($middleware instanceof \Closure) {
                $shortCircuit = $middleware($this->request);
            } elseif (is_object($middleware) && method_exists($middleware, 'handle')) {
                $shortCircuit = $middleware->handle($this->request);
            } else {
                throw new HublogException('中间件配置非法：仅支持类名字符串 / Closure / 带 handle() 的对象', 500);
            }
            // 站点模式守卫等「拒绝型中间件」可直接返回 Response 短路，
            // 走统一出口输出；现有 void 中间件返回 null，行为完全不变。
            if ($shortCircuit instanceof Response) {
                return $shortCircuit;
            }
        }

        // 执行处理器
        $handler = $route['handler'];
        if (is_array($handler)) {
            [$class, $methodName] = $handler;
            $controller = new $class();
            $result = $controller->$methodName($this->request);
        } else {
            $result = $handler($this->request);
        }

        if ($result instanceof Response) {
            return $result;
        }

        return Response::success($result);
    }

    /**
     * 解析请求路径，自动适配子目录部署
     *
     * 支持两种访问模式（与伪静态开关无关，API 路由始终可用）：
     * 1. PATH_INFO 模式（默认，不依赖 Web 服务器重写）：
     *    请求 /api/index.php/csrf → PATH_INFO=/csrf → 补 /api 前缀 → /api/csrf
     * 2. 重写模式（兼容已配置伪静态的环境）：
     *    请求 /api/csrf 被 .htaccess/nginx 重写到 api/index.php
     *    REQUEST_URI=/api/csrf, SCRIPT_NAME=/api/index.php
     */
    private function resolvePath(): string
    {
        // 统一解析 API 路径，与部署方式解耦：
        // - PATH_INFO 模式（前端有意采用，不依赖伪静态）：请求 /api/index.php/csrf
        // - 重写模式（nginx 伪静态已重写到 api/index.php）：请求 /api/csrf
        // - router-script 模式（php -S host api/index.php）：请求 /api/index.php/csrf（SCRIPT_NAME 可能是 /index.php）
        // 旧实现只依赖 $_SERVER['PATH_INFO']，而该变量在 php -S router 模式 / 部分 nginx、Apache 下
        // 不一定被设置，导致 /api/index.php/csrf 被当成完整路由路径 → 404。
        // 新实现直接从 REQUEST_URI 中剥离入口脚本段，不再依赖 PATH_INFO。
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $rawPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
        $path = $rawPath;

        // 优先按 SCRIPT_NAME 剥离入口脚本（兼容子目录部署 /sub/api/index.php）
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($scriptName !== '' && str_starts_with($path, $scriptName)) {
            $path = substr($path, strlen($scriptName));
        }

        // PATH_INFO 模式：请求形如 /api/index.php/csrf。
        // 无论 SCRIPT_NAME 是否匹配（router script 模式下 SCRIPT_NAME 可能是 /index.php），
        // 一律剥掉路径中的 /index.php 段，再补 /api 前缀。路由表无 index.php 开头项，剥除安全。
        if (str_contains($rawPath, '/index.php')) {
            $path = preg_replace('#/index\\.php#i', '', $path, 1) ?? $path;
            if ($path === '' || !str_starts_with($path, '/api')) {
                $path = '/api' . $path;
            }
        }

        // P2-3 API 版本化外壳：把 /api/v1/... 透明重写为 /api/... 后走现有匹配，
        // 使当前 API 同时以「无前缀（冻结 legacy）」与「/api/v1（受版本约束）」两种形式对外。
        // 旧客户端不感知；新客户端逐步切到 /api/v1，后续破坏性变更走 /api/v2。
        // 仅重写 v1 前缀（现有路由表无任何 /api/v1 开头项，剥离安全），v2+ 留给真正的版本分叉。
        $path = $this->applyApiVersionPrefix($path);

        return rtrim($path, '/') ?: '/';
    }

    /**
     * P2-3：把 /api/v1/foo 透明重写为 /api/foo。
     * 仅作用于 v1 前缀；/api/v2（及以后）不被触碰，届时由显式注册的 v2 路由承载分叉。
     */
    private function applyApiVersionPrefix(string $path): string
    {
        if (preg_match('#^/api/v1(/|$)#', $path)) {
            $path = '/api' . substr($path, 7); // strlen('/api/v1') === 7
        }
        return $path;
    }

    private function findRoute(string $method, string $path): ?array
    {
        if (!isset($this->routes[$method])) {
            return null;
        }

        // 精确匹配
        if (isset($this->routes[$method][$path])) {
            return $this->routes[$method][$path];
        }

        // 动态路由匹配
        // S4：原实现直接把整条路由路径当正则，未对字面量 preg_quote，
        // 导致 /sitemap.xml 的 '.' 成了通配符（/sitemapXxml 也能命中）。
        // 现在只把 {param} 占位符替换成 ([^/]+)，其余字符一律转义。
        foreach ($this->routes[$method] as $routePath => $route) {
            $segments = preg_split('/(\{[a-zA-Z0-9_]+\})/', $routePath, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            $pattern = '';
            foreach ($segments as $segment) {
                $pattern .= preg_match('/^\{[a-zA-Z0-9_]+\}$/', $segment)
                    ? '([^/]+)'
                    : preg_quote($segment, '#');
            }
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $path, $matches)) {
                array_shift($matches);
                preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $routePath, $keys);
                if (!empty($keys[1])) {
                    foreach ($keys[1] as $index => $key) {
                        $this->request->setParam($key, $matches[$index] ?? null);
                    }
                }
                return $route;
            }
        }

        return null;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }
}
