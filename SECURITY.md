# 安全策略

## 支持版本

| 版本 | 状态 |
| --- | --- |
| `v2.17.00`（数据库迁移 101） | 当前维护版本，安全修复在此版本线上进行 |
| 更早版本 | 不再提供安全修复，请先升级到当前版本 |

## 报告漏洞

请通过官网 <https://woquan.com.cn> 公布的联系方式提交安全问题，并尽量提供：

- 受影响版本与部署方式（Web 服务器、PHP / MySQL 版本）
- 可复现的请求路径、参数与步骤
- 实际影响与你认为的修复方向

**请勿**在公开 Issue、Pull Request 或社区讨论中披露可利用细节。收到报告后先确认影响范围，再协调修复与披露。

## 代码中的安全机制

| 领域 | 实现口径（以源码为准） |
| --- | --- |
| 登录令牌 | JWT 通过 httpOnly Cookie 或 `Authorization: Bearer` 传递；`token_version` 支持后台强制失效。前端登录态以 `GET /api/auth/me` 为准。 |
| 密钥守卫 | 生产环境 `JWT_SECRET` 少于 32 字节或命中公开示例值时直接 500 并终止启动；可用 `php -r 'echo bin2hex(random_bytes(32));'` 生成。 |
| 密码存储 | `password_hash()` bcrypt，`PASSWORD_COST = 12`。 |
| CSRF | 写请求校验 `X-CSRF-Token` 或 `csrf_token`，有效期 3600 秒。放行：GET / HEAD、纯 Bearer、`X-Client-Type: app|miniapp`、公开路径（登录、注册、忘记密码、刷新令牌、`/app/version`、`/app/config`、`/csrf` 及 5 个 cron 路径）。 |
| 访问控制 | `AdminMiddleware` 校验角色；`/api/admin/activities` 与 `/api/admin/my-circles` 对圈主放行。动态可见性为 `public / circle / friend / private` 四态，缺失或非法值默认拒绝。 |
| 限流 | 读轮询 2400 次/60 秒、其余读 1500 次/60 秒、写 600 次/60 秒；用户名与邮箱检查、邮件、短信验证码另有独立闸门。反向代理后须配置 `TRUSTED_PROXIES`。 |
| 上传 | 图片仅 `jpeg / png / gif / webp`，附件按扩展白名单，单文件 10 MB，MIME 与文件头校验；`uploads/`、`plugins/` 下的 PHP 执行由 Web 服务器配置拒绝。 |
| 在线升级 | 默认校验升级包清单与 RSA 签名（`UPGRADE_ALLOW_UNSIGNED = false`），缺失签名即失败。 |
| 远程脚本 | 后台一键安装 Ollama 默认禁用（`ALLOW_REMOTE_INSTALLER = false`）。 |
| 插件删表 | 需带 `hash_hmac('sha256', 'plugin:<name>:drop', cron_secret)` 确认令牌；密钥为空时拒绝，不生成可预测令牌。 |
| 安装 | 一次性安装 Token + Host / Referer 同源校验；目标库已有管理员时拒绝重放；成功后写根目录 `.installed.lock` 并自删 `install.php`；`config.php` 权限 0600。 |
| Web 层保护 | 参考 `nginx.conf` / `Caddyfile` / `web.config`，拒绝访问 `config.php`、`install.php`、`.env`、`composer.*`、`backups/`、`cache/`、`logs/`、`sql/`、`docs/`、`license_system/data/` 与点文件。 |

## 部署者检查清单

- `config.php` 不入版本库，权限设为 `0600`；`JWT_SECRET` 为随机生成值。
- 生产环境保持 `APP_ENV=production`、`APP_DEBUG=false`。
- `CORS_ALLOWED_ORIGINS` 由默认的 `*` 改为实际域名白名单。
- 反向代理或 CDN 回源时填写 `TRUSTED_PROXIES`，否则限流会按同一 IP 计数。
- 缓存规模较大时把 `CACHE_DRIVER` 切到 `redis`，避免文件缓存下的高频计数串行。
- 安装或修改配置后确认 `GET /health` 的 `data.status` 为 `ok`，并确认 `install.php` 已删除。
- 不要公开 `?token=` 用法：除 SSE 与指标接口外接口不读取该参数，且相关日志已脱敏。

## 相关文档

- 安装与安全加固：[docs/我圈_全新安装指南.md](docs/我圈_全新安装指南.md)
- 故障诊断：[docs/运维排障手册.md](docs/运维排障手册.md)
- 贡献流程：[CONTRIBUTING.md](CONTRIBUTING.md)
