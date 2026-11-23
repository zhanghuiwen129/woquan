# 常见问题 FAQ

> 按源码实际行为整理。更完整的处置流程见[运维排障手册](./运维排障手册.md)与[后台管理使用手册](./后台管理使用手册.md)。

## 安装与升级

**Q：安装向导提示「已安装」，无法继续？**
根目录存在 `.installed.lock`（旧部署可能是 `cache/install.lock`）。这是防重放保护：目标库已有管理员时安装请求会被拒绝。确认不需要重装则直接进入后台；确实要重装先备份数据库与 `uploads/`，再移除锁并清空目标库。

**Q：需要手工导入 `sql/hublog.sql` 吗？**
使用 `install.php` 向导时不需要。向导会写 `config.php`、执行 Migration 建表并写入默认配置；`sql/hublog.sql` 只是初始化引导脚本，也不是备份文件。

**Q：前台跳 302、API 返回 503？**
安装锁不存在，系统判定为未安装。检查根目录 `.installed.lock` 与旧版 `cache/install.lock`。

**Q：升级后代码已更新但功能没变化？**
先确认 Migration 是否成功（`/health` 的 `data.checks.migration` 与 `cache/.migration_failed`），再清除 `site_settings` 缓存：主题与站点设置经该缓存读取，前台 TTL 3600 秒。

## 配置与运行

**Q：改了 `config.php` 但没生效？**
配置只从 `config.php` 的 `define()` 读取，`.env` / `EnvLoader.php` 未接入；改完需重启 PHP-FPM。生产环境 `JWT_SECRET` 少于 32 字节或命中公开示例值会直接 500 并终止启动，可用 `php -r 'echo bin2hex(random_bytes(32));'` 重新生成。

**Q：在后台切换了主题，前台还是旧主题？**
`active_theme` 随 `site_settings` 缓存，前台 TTL 3600 秒。清除该缓存后即时生效。

**Q：关闭了某个模块，接口还能访问？**
模块开关由 `module_flags` 控制，后台菜单、运行层守卫与日志归因共用 `ModuleCatalog`。确认保存成功、守卫模式不是 `off`，并注意长接口前缀优先匹配。

**Q：反向代理后所有用户共用同一个限流计数？**
把代理 IP（支持 CIDR）写入 `config.php` 的 `TRUSTED_PROXIES`，`Request::getIp()` 才会解析 `X-Forwarded-For`。未配置不会绕过限流，只会让请求落在同一计数桶。

**Q：所有接口都能用 `?token=` 鉴权吗？**
不能。只有 SSE 与 `GET /api/metrics` 读取 query token，其余接口用 httpOnly Cookie 或 `Authorization: Bearer`。相关日志已脱敏，但仍不建议在 URL 中传递令牌。

**Q：写请求返回 403 CSRF验证失败？**
token 从 `X-CSRF-Token` 头或 `csrf_token` 参数读取，有效期 3600 秒。登录后需要重新获取；App / 小程序用 `X-Client-Type: app|miniapp` 且不发认证 Cookie 时走放行分支。

## 内容与媒体

**Q：发布时正文为空被拒绝？**
有图片、视频、附件或选择了帖型时，`content` 可以为空；纯文字帖必须有正文。帖型只有 `normal / poll / question / team / checkin` 五种。

**Q：游客看不到圈子内的帖子？**
可见性是 `public / circle / friend / private` 四态，`circle` 要求有效成员身份（付费圈还要求订阅有效），`friend` 是当前用户单向关注作者；缺失或非法值默认拒绝。这不是缓存问题。

**Q：上传提示超过限制？**
应用层 `UPLOAD_MAX_SIZE` 默认 10 MB，Web 服务器配置另有 100 MB 阈值，两者独立。图片仅 `jpeg / png / gif / webp`，附件按扩展白名单。

**Q：视频上传后没有转码？**
转码依赖 FFmpeg，需在服务器安装并在后台配置；上传目录的 PHP 执行权限必须关闭。

## 付费与授权

**Q：付费功能返回 402？**
授权模块共 8 项：`ai`、`scheduled`、`dataops`、`circles`、`shop`、`finance`、`activities`、`realname`。`badges`、`points`、`moderation` 免费，发布 / 私信 / 会话属基础能力。`contentpay` 依赖 `finance`，`shop` 依赖 `finance`，必须同时开启。

**Q：未购买内容付费文章能看到多少？**
未购买用户只拿到试读内容；购买权益以独立权益表为准，不走 `posts` 表标记。

## 数据、备份与定时任务

**Q：帖子点赞数、评论数不准？**
运行 `php bin/reconcile-counters.php` 对账。对账来源为 `likes`、`comments`、`circle_members` 与 `posts.circle_id`。

**Q：备份会包含上传文件和配置吗？**
代码备份排除 `uploads`、`cache`、`logs`、`backups`、`.git`、`.DS_Store`、`license_system/data`，并排除 `config.php`、`.env`、`.htpasswd`。升级覆盖时这些路径同样受保护。

**Q：后台导出 URL 还需要拼 `token=` 吗？**
不需要。`GET /api/admin/export` 走 Cookie / Bearer，前端拼接 `token=` 只为兼容旧脚本，不参与鉴权。

**Q：AI 自动评论或语义搜索没有反应？**
两者都把任务入队，由 `POST /api/cron/jobs` 在独立请求中消费。未配置该 cron 时静默失效，不会报错。

**Q：cron 请求返回 403？**
核对后台「站点设置」的 `cron_secret` 与请求头 `X-Cron-Token`（或请求体 `secret`）是否一致。留空即关闭外部调度入口。五个 cron 路径已在 CSRF 白名单内。

## 移动端

**Q：公开仓库里没有客户端源码？**
公开仓库只发布 PHP 后端。移动端是 uni-app 工程，H5 用 `npm run build:h5` 构建后放到站点 `h5/` 目录，Android / iOS 用 HBuilderX 调试与云打包。客户端获取入口以官网为准。

**Q：App 连不上接口？**
检查客户端配置的 API 域名、HTTPS 证书与 `MEDIA_BASE_URL`（App 需要完整媒体 URL），并确认接口路径带 `/api` 前缀。

## 相关链接

- [全新安装指南](./我圈_全新安装指南.md) · [功能总览](./我圈_功能总览.md) · [演示与下载](./我圈_演示与下载.md)
- [主题开发说明书](./主题开发说明书.md) · [插件开发说明书](./插件开发说明书.md) · [API 接口手册](./API_接口手册.md)
