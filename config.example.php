<?php
/**
 * Hublog 配置文件示例
 * 安装程序会自动生成正式的 config.php
 * 请勿直接提交包含真实密码的 config.php 到代码仓库
 */

declare(strict_types=1);

defined('APP_DEBUG') || define('APP_DEBUG', false);

// S5：反向代理 / CDN 回源时，把代理的连接 IP（支持 CIDR）填在这里，
// Request::getIp() 才会据此解析 X-Forwarded-For 还原真实客户端 IP。
// 不填不会绕过限流（RateLimitMiddleware 已不再豁免私网来源），
// 但所有经代理的请求会共用一个限流计数桶，高峰期可能误伤。
defined('TRUSTED_PROXIES') || define('TRUSTED_PROXIES', '127.0.0.1,::1');

// S3：在线升级默认强制校验升级包清单 + RSA 签名 + 后台公钥（fail-closed）。
// 仅自托管且确实拿不到签名包时，显式改为 true 表示知情降级（高风险）。
defined('UPGRADE_ALLOW_UNSIGNED') || define('UPGRADE_ALLOW_UNSIGNED', false);

// S10：后台「一键安装 Ollama」会执行第三方远程脚本（curl ... | sh），默认禁用。
// 确需开启时改为 true；更推荐在服务器上手动安装。
defined('ALLOW_REMOTE_INSTALLER') || define('ALLOW_REMOTE_INSTALLER', false);

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'hublog');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

defined('APP_NAME') || define('APP_NAME', '我圈');
define('APP_VERSION', 'v2.17.00');
// 运行环境：production（默认）/ development / testing。
// 用于区分「错误是否外显」「日志级别」「缓存/调试行为」等环境相关差异，
// 替代散落的 APP_DEBUG 布尔。development 下 APP_DEBUG 自动视为 true。
// 注意：本项目配置以 config.php 的 define() 为唯一真实来源（.env / EnvLoader 未接入）。
defined('APP_ENV') || define('APP_ENV', 'production');
defined('APP_DEBUG') || define('APP_DEBUG', (defined('APP_ENV') && APP_ENV === 'development'));
define('APP_URL', '');
define('TIMEZONE', 'Asia/Shanghai');

// 必填：至少 32 字节的随机串（如 bin2hex(random_bytes(32))）。
// 安装向导会自动生成；留空或填任何公开示例值时，生产环境会拒绝启动
// （见 bootstrap.php::assertJwtSecret），避免攻击者离线伪造管理员 JWT。
defined('JWT_SECRET') || define('JWT_SECRET', '');
defined('JWT_EXPIRE') || define('JWT_EXPIRE', 86400 * 7);
define('CSRF_TOKEN_EXPIRE', 3600);
define('PASSWORD_COST', 12);
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 60);

define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024);
define('UPLOAD_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('UPLOAD_AVATAR_DIR', __DIR__ . '/uploads/avatars/');
define('UPLOAD_IMAGE_DIR', __DIR__ . '/uploads/images/');
define('UPLOAD_ATTACHMENT_DIR', __DIR__ . '/uploads/attachments/');
define('UPLOAD_ATTACHMENT_EXTS', ['zip', 'rar', '7z', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt']);
// 敏感图替换占位图：站点根绝对路径（带前导斜杠），真实文件在 templates/moments/assets/images/ 下
define('UPLOAD_BLOCKED_IMAGE_URL', '/templates/moments/assets/images/blocked.svg');
define('UPLOAD_URL_PATH', '/uploads/');

// 缓存驱动：file（默认，无需扩展）或 redis（生产强烈推荐）
// P0-10：file 驱动已去除全局索引锁（每次 set 不再读改写 .index，前缀删除改为
// 扫描缓存文件内嵌 key），小规模（数千 key）可用；用户量上来后请切 redis：
// 限流计数、登录锁、验证码锁、未读计数等高频计数场景在 file 下依赖 flock 串行。
define('CACHE_DRIVER', 'file');
define('CACHE_PREFIX', 'hublog_');
defined('CACHE_TTL') || define('CACHE_TTL', 3600);

// 实时事件派发驱动：file（默认，本机文件队列，适合单机部署）。
// 多机部署时改为其它实现（如 centrifugo），需先部署对应 Dispatcher 类。
// 存量安装未定义此常量时按 file 处理，无需改配置即可升级；
// 显式填了未实现的驱动会快速失败，不会静默降级导致多机漏事件。
defined('REALTIME_DRIVER') || define('REALTIME_DRIVER', 'file');

// 高德地图 Web 服务 Key（可选）：用于把经纬度反查成中文地名
defined('AMAP_WEB_KEY') || define('AMAP_WEB_KEY', '');

// CORS 跨域配置
// 逗号分隔的允许域名列表，* 表示允许所有（生产环境建议白名单）
define('CORS_ALLOWED_ORIGINS', '*');

// 媒体 URL 基础地址（多端适配）
// App / 小程序需要完整 URL，留空则从当前请求自动推导
// 示例：define('MEDIA_BASE_URL', 'https://your-domain.com');
define('MEDIA_BASE_URL', '');

// Redis 缓存配置（可选，需安装 redis 扩展）
// 将 CACHE_DRIVER 改为 'redis' 启用
// define('REDIS_HOST', '127.0.0.1');
// define('REDIS_PORT', 6379);
// define('REDIS_PASSWORD', '');
// define('REDIS_DB', 0);

define('DEFAULT_TEMPLATE', 'moments');
define('TEMPLATE_DIR', __DIR__ . '/templates/');

if (!APP_DEBUG) {
    error_reporting(0);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// 应用中心后台地址（Dz 式在线安装入口）。按需修改；置为空字符串可隐藏入口。
if (!defined('APP_CENTER_URL')) {
    define('APP_CENTER_URL', 'http://127.0.0.1:8080/admin.php');
}

date_default_timezone_set(TIMEZONE);
