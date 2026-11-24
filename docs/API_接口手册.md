# 我圈 API 接口手册

> 路由真源：`api/routes.php`（当前 416 条）。本文档与 `openapi.yaml` 均按该文件逐条对齐，新增接口后需同步更新两份文档。

## 1 接入

| 项 | 值 |
| --- | --- |
| 入口 | `{站点根}/api/index.php`（PATH_INFO，无需重写）；配置重写后可用 `{站点根}/api` |
| 路径 | 业务接口以 `/api` 开头；`/api/index.php/xxx` 与 `/api/xxx` 等价 |
| 版本前缀 | `/api/v1/xxx` 等价于 `/api/xxx`（`api/core/Router.php` 透明剥离 v1 前缀，路由表无 v1 定义）；不支持 v2 |
| 编码 | UTF-8；响应 `Content-Type: application/json; charset=utf-8` |
| 请求体 | `application/json`、`application/x-www-form-urlencoded`、`multipart/form-data` |
| 方法 | GET / POST / PUT / DELETE / PATCH / OPTIONS |
| CORS | 允许 `Content-Type, Authorization, X-CSRF-Token, X-Client-Type`；`OPTIONS` → 204 |

| 请求头 | 值 | 用途 |
| --- | --- | --- |
| `Authorization` | `Bearer <token>` | 用户鉴权 |
| `X-CSRF-Token` | `GET /api/csrf` 返回的 token | 写操作校验 |
| `X-Client-Type` | `app` / `miniapp` | 免 CSRF（须同时无会话 Cookie） |
| `X-Cron-Token` | 服务端密钥 | 定时任务 |

## 2 响应与错误

```json
{"code":200,"message":"success","data":{"list":[],"pagination":{}}}
```

```json
{"code":401,"message":"请先登录","data":null}
```

| 规则 | 值 |
| --- | --- |
| 成功判定 | `code === 200` |
| HTTP 状态码 | 与 `code` 一致（`code=404` → HTTP 404） |
| 失败载荷 | 多数错误 `data` 为 `null`；部分错误携带结构化详情（如 `{field:...}`、`{logs:[...]}`） |
| 500 响应 | 额外含 `error_id`（8 位 hex），排障时提供该值 |
| 时间字段 | `created_at` / `updated_at` 为 `Y-m-d H:i:s`（服务器时区）；`server_time` 为 Unix 秒 |

| code | HTTP | 触发 |
| --- | --- | --- |
| 200 | 200 | 成功 |
| 400 | 400 | 参数缺失 / 格式错误 |
| 401 | 401 | 未登录、token 失效、登录态被踢 |
| 402 | 402 | 配额上限、余额不足、模块未授权 |
| 403 | 403 | 无权限、账号禁用、功能关闭、CSRF 校验失败 |
| 404 | 404 | 资源不存在 |
| 409 | 409 | 冲突（用户名 / 邮箱 / 昵称已占用） |
| 422 | 422 | 字段校验失败 |
| 423 | 423 | 登录尝试过多，临时锁定 |
| 429 | 429 | 触发限流 |
| 500 | 500 | 服务端异常 |
| 501 | 501 | 功能未实现 |
| 502 | 502 | 上游网关错误（应用中心注册未返回有效凭据、升级时远程源码下载失败） |
| 503 | 503 | 服务暂不可用（站点未安装 / 依赖未就绪） |
| 4230 | **200** | 站点功能模块未开启；只按状态码判定会误判为成功，必须判 `body.code` |

### 限流

| 请求类别 | 上限 / 60 秒 |
| --- | --- |
| 普通 GET | 1500 |
| 轮询类 GET（`/api/posts/check-new`、`/api/notifications/unread-count`、`/api/follow/stats`、`/api/sse`、`/api/chat/*`、`/api/notifications/*`） | 2400 |
| POST / PUT / DELETE / PATCH | 600 |

| 项 | 值 |
| --- | --- |
| 计数维度 | 已登录按用户 ID，未登录按 IP；再按请求类别分桶 |
| 超限响应 | `429 {"code":429,"message":"请求过于频繁，请稍后再试","data":null}` |
| `Retry-After` | 不下发 |
| 豁免 | `admin` 角色 |

## 3 鉴权与 CSRF

| 标记 | 含义 |
| --- | --- |
| 公开 | 无需 token |
| 登录 | 需 `Authorization: Bearer <token>` 或 `hublog_token` Cookie |
| 管理员 | 需登录且 `role=admin`；例外：`/api/admin/activities`、`/api/admin/my-circles` 对圈主放行（仅限自己圈子） |
| 系统 | 由服务端 cron 或第三方支付服务器调用 |

| 项 | 值 |
| --- | --- |
| token 来源 | `Authorization: Bearer <token>` → Cookie `hublog_token`；两者均无则 401 |
| `?token=` 查询参数 | `GET /api/sse` 与 `GET /api/metrics` 两处支持；其余接口一律不读（该参数在 access 日志中会被脱敏） |
| access token 有效期 | 7 天 |
| refresh token 有效期 | 30 天，单次轮换后立即作废旧值 |
| 双令牌下发条件 | 登录请求带 `X-Client-Type: app\|miniapp\|android\|ios\|h5` 或 `device_id` 非空 |
| Cookie 属性 | `HttpOnly`、`SameSite=Lax`、HTTPS 下 `Secure` |
| 登出 | `POST /api/auth/logout`，客户端同时清除本地 token |

| CSRF 规则 | 值 |
| --- | --- |
| 校验范围 | POST / PUT / DELETE / PATCH；GET / HEAD 不校验 |
| token 传递 | `X-CSRF-Token` 请求头，或请求参数 `csrf_token` |
| 获取 | `GET /api/csrf` → `data.token`（64 位 hex，有效期 3600 秒） |
| 放行：纯 Bearer | 带 `Authorization: Bearer ...` 且无 `PHPSESSID` / `user_token` / `hublog_uid` / `remember_web` Cookie |
| 放行：客户端类型 | `X-Client-Type: app` 或 `miniapp`（精确小写），且上述 Cookie 均不存在 |
| 校验失败 | `403` |

免 CSRF 白名单（写操作可直接调用）：

```text
/api/auth/login          /api/auth/register       /api/auth/forgot-password
/api/auth/wx-mini-login  /api/auth/send-code      /api/auth/mobile-login
/api/auth/refresh        /api/auth/token-refresh  /api/app/auth/wx-app-login
/api/app/version         /api/app/config          /api/csrf
/api/cron/scheduled      /api/cron/badges         /api/cron/jobs
/api/cron/archive        /api/cron/circle-subscribes  /api/cron/license-heartbeat
```

## 4 通用约定

### 4.1 参数

| 项 | 值 |
| --- | --- |
| GET | 只取 query string，忽略请求体 |
| 非 GET | `$_GET` 与 body 合并；同名时 body 覆盖 query |
| JSON body | `Content-Type: application/json` 时解析为关联数组 |
| 表单 | `application/x-www-form-urlencoded` / `multipart/form-data` |
| 文件 | `multipart/form-data`，字段名见第 7 章 |
| 路径参数 | `{id}` / `{provider}` 由路由注入，按普通参数读取 |
| 布尔值 | `1` / `true` / `on` / `yes` / 数值 1 为真 |
| 数组 | `getArray` 语义：非数组入参按空数组处理 |

接口索引表中 `*` 标记必填参数。

### 4.2 分页

| 项 | 值 |
| --- | --- |
| 参数 | `page`（默认 1）、`limit`（默认 20，上限 50；各接口可覆盖） |
| 标准响应 | `{"list":[],"pagination":{"page":1,"limit":20,"total":0,"pages":0}}` |
| 部分接口 | `pagination` 缺 `pages` 或 `limit`；`GET /api/site/ranking`、`GET /api/user/search`、`POST /api/follow/batch-check` 直接返回数组 / 映射对象 |
| 游标参数 | `cursor`（时间线、话题）、`before_id`（通知、私信、搜索） |
| 搜索游标 | `pagination` 额外含 `next_before_id`、`has_more` |

`GET /api/posts/timeline` 传 `cursor` 后改为游标模式：

```json
{"pinned":[],"items":[],"next_cursor":"...","has_more":true}
```

游标模式不含 `list` 与 `pagination`；首屏（`cursor=0`）才返回 `pinned`。

### 4.3 媒体字段

| 项 | 值 |
| --- | --- |
| 返回形态 | `images` / `videos` / `attachments` / `avatar` / `cover` 均为绝对 `http(s)://` URL |
| JSON 数组字段 | `images` / `videos` / `attachments` 自动反序列化为数组 |
| 空头像 | `avatar` 为 null 或空串时自动补默认头像绝对 URL |
| 已为绝对 URL | 原样返回，不二次加工 |
| 上传入库 | 服务端存站点根相对路径，转换发生在响应层 |

### 4.4 枚举

| 字段 | 合法值 |
| --- | --- |
| `post_type` | `normal`、`poll`、`question`、`team`、`checkin` |
| `visibility` | `public`、`circle`、`friend`、`private` |
| `/api/posts/timeline` 的媒体过滤参数 | `media` = `video` / `image` / `text` |
| `/api/search` 的媒体过滤参数 | `media_type` = `all` / `image` / `video` / `text` |
| `/api/search` 的 `date_range` | `7d`、`30d`、`all` |
| `/api/posts/popular` 的 `type` | `comments`、`likes`、`favorites` |
| `/api/notifications` 的 `type` | `comment`、`like`、`comment_like`、`follow`、`mention`、`system`、`message`、`category`（逗号分隔可多选） |
| `/api/chat/send` 的 `type` | `text`、`image`、`video`、`file`、`voice` |
| `/api/site/ranking` 的 `type` / `period` | `user\|post\|circle\|topic` / `day\|week\|month\|all` |
| `/api/app/version` 的 `platform` | `android`、`ios`、`h5` |
| OAuth `provider` | `github`、`google`、`wechat` |
| `role` | `user`、`admin`、`reviewer` |

## 5 数据字典

### 5.1 帖子对象

```text
id, category_id, title, content, images, videos, attachments,
location, location_lat, location_lng, is_anonymous, is_original,
link{url,title,desc,site}|null, visibility, is_featured, origin_post,
is_pinned, pinned_at, created_at, time_ago,
user{}, likes[], comments[], like_count, comment_count, view_count,
tags[], circle{id,name}|null, post_type, liked, favorited, favorited_at
```

按帖型追加：`poll` / `question` / `team` / `checkin`。详情页另追加 `origin_post`、`mentions`、`pending_review`。

### 5.2 用户对象

| 出现位置 | 字段 |
| --- | --- |
| 帖子内嵌 `user` | `id, username, nickname, avatar, role, bio, realname_verified, phone_verified, vip_level, avatar_frame, is_following, badges` |
| `/api/user/profile` 的 `user` | `id, username, nickname, avatar, cover, bio, user_tags, created_at, realname_verified, vip_level, vip_expire_at, avatar_frame, deleted, deleted_at, online, last_active_at, badges` |
| 登录 / 注册返回的 `user` | `id, username, nickname, avatar, cover, role, email, token_version, created_at` |
| `/api/auth/me` | 上表全部 + `status, vip_level, vip_expire_at, balance, points, avatar_frame, muted_until, is_circle_owner` |

匿名动态内嵌 `user` 固定为 `{id:0, username:"anonymous", nickname:"匿名用户", avatar:null, role:"anonymous"}`。

### 5.3 评论对象

```text
id, parent_id, content, image, images[], is_ai, created_at, time_ago,
liked, like_count, is_god, can_mark,
user{id, username, nickname, avatar, vip_level, avatar_frame, badges}
```

### 5.4 消息对象

```text
id, conversation_id, sender_id, content, type, extra, is_self,
sender{id, username, nickname, avatar, vip_level, avatar_frame}, created_at
```

## 6 接口索引

### 6.1 认证与账号

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| POST | `/api/auth/register` | 公开 | `username*`(3-20 字母数字)、`password*`、`password_confirm*`、`email*` | `{user,token}` |
| POST | `/api/auth/login` | 公开 | `username*`、`password*`、`device_id` | `{user,token}` + 可选 `refresh_token,expires_in,refresh_expires_in` |
| POST | `/api/auth/phone-code` | 公开 | `phone*` | `{sent,expires_in,dev_code}` |
| POST | `/api/auth/login-phone` | 公开 | `phone*`(11 位)、`code*`(6 位)、`device_id` | `{user,token,is_new}` + 可选双令牌字段 |
| GET | `/api/auth/check-username` | 公开 | `username*` | `{available,message}` |
| GET | `/api/auth/check-email` | 公开 | `email*` | `{available,message}` |
| POST | `/api/auth/send-verification` | 公开 | `email*` | `null` |
| POST | `/api/auth/verify-email` | 公开 | `email*`、`code*` | `null` |
| POST | `/api/auth/forgot-password` | 公开 | `email*` | `null` |
| POST | `/api/auth/reset-password` | 公开 | `email*`、`token*`、`password*`、`password_confirm*` | `null` |
| GET | `/api/auth/oauth/{provider}` | 公开 | `provider*` | 302 跳转，非 JSON |
| GET | `/api/auth/oauth/{provider}/callback` | 公开 | 回调参数由平台下发 | 302 跳转，非 JSON |
| POST | `/api/auth/oauth/exchange` | 公开 | `provider*`、`code*` | `{user,token}` |
| POST | `/api/auth/wx-mini-login` | 公开 | `code*`、`nickname`、`avatar` | `{user,token}` |
| POST | `/api/app/auth/wx-app-login` | 公开 | `code*` 或 `access_token*`+`openid*`、`device_id`、`nickname`、`avatar` | `{user,token}` + 可选双令牌字段 |
| POST | `/api/auth/token-refresh` | 公开 | `refresh_token*` | `{user,token,refresh_token,expires_in,refresh_expires_in}` |
| GET | `/api/auth/me` | 登录 | — | 用户对象 + `is_circle_owner` |
| POST | `/api/auth/refresh` | 登录 | — | `{user,token}` |
| POST | `/api/auth/logout` | 登录 | `refresh_token` | `null` |
| PUT | `/api/auth/profile` | 登录 | `nickname`(1-50)、`bio`(≤20)、`email`、`avatar`、`cover`、`gender`、`birthday`、`region`、`user_tags`、`privacy_home`、`allow_search`、`allow_mention`、`allow_stranger_msg`、`hide_online`、`font_scale`、`msg_sound` | 用户对象 |
| GET | `/api/auth/profile` | 登录 | — | 用户对象 |
| PUT | `/api/auth/password` | 登录 | `old_password*`、`new_password*`、`new_password_confirm*` | `null` |
| POST | `/api/auth/set-password` | 登录 | `password*` | `null` |
| POST | `/api/auth/avatar` | 登录 | 文件字段 `avatar` | `{avatar}` |
| POST | `/api/auth/cover` | 登录 | 文件字段 `cover` | `{cover}` |
| GET | `/api/realname/status` | 登录 | — | 实名状态对象 |
| POST | `/api/realname/verify` | 登录 | `real_name*`、`id_card*` | 核验结果 |

### 6.2 站点与应用配置

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/csrf` | 公开 | — | `{token}` |
| GET | `/api/app/version` | 公开 | `platform`、`code` | `{has_update,force,version_name,version_code,changelog,download_url}` |
| GET | `/api/app/config` | 公开 | — | `site_name,site_logo,site_desc,icp,register_open,limits{},agreements{},post_types{},post_tools{},enabled_modules,module_pages,site_mode,server_time` 等 |
| GET | `/api/app/agreements` | 公开 | `type`(`user`/`privacy`/`realname`) | `{type,title,content,updated_at}` |
| GET | `/api/app/categories` | 公开 | — | `{list:[{id,parent_id,name}]}` |
| GET | `/api/app/wx-jsapi-config` | 公开 | `url` | `{ready,...}` |
| POST | `/api/app/track` | 公开 | `events*`(≤20 条) 或 `event*`+`props`、`platform` | `{recorded}` |
| GET | `/api/site/config` | 公开 | — | 站点设置、`categories` 树、`theme_options`、`license` |
| GET | `/api/site/friend-links` | 公开 | — | `{list}` |
| GET | `/api/site/stats` | 公开 | — | 站点统计 |
| GET | `/api/site/user-ranking` | 公开 | `limit` | 排行数组 |
| GET | `/api/site/ranking` | 公开 | `type`、`period`、`limit`(≤50) | **数组**，字段随 `type` 变化 |
| GET | `/api/site/featured` | 公开 | `limit` | `{list}` |
| GET | `/api/site/lang` | 公开 | `lang`(`zh-CN` / `en`，默认 `zh-CN`) | `{lang,strings}` |
| GET | `/api/location/ip` | 公开 | — | IP 定位结果 |
| GET | `/api/location/reverse` | 公开 | `lat*`、`lng*` | 逆地理编码结果 |

### 6.3 动态与信息流

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/posts/timeline` | 公开 | `page`、`limit`(默认10，上限20)、`username`、`sort`(仅 `recommend`)、`category_id`、`media`、`following`、`cursor` | `{list,pagination}` 或游标 `{pinned,items,next_cursor,has_more}` |
| GET | `/api/posts/detail` | 公开 | `id*` | 帖子对象 + 详情扩展字段 |
| GET | `/api/posts/pinned` | 公开 | — | `{list}` |
| GET | `/api/posts/category` | 公开 | `id*`、`page`、`limit` | `{list,pagination}` |
| GET | `/api/posts/topic` | 公开 | `name*`、`page`、`limit`、`cursor` | `{list,pagination,next_cursor,cursor_mode,query_mode}` |
| GET | `/api/topics` | 公开 | `limit`(默认6，上限30)、`keyword` | `{list:[{name,post_count}]}` |
| GET | `/api/posts/popular` | 公开 | `type`、`limit`(≤50) | `{list,pagination,type}`（`pagination` 无 `limit`） |
| GET | `/api/posts/latest-ids` | 公开 | — | `{post_id,comment_id,like_id}` |
| GET | `/api/posts/check-new` | 公开 | `since_id`、`since_comment_id`、`since_like_id`、`username` | `{count,post_count,comment_count,like_count,latest_id,latest_comment_id,latest_like_id}` |
| GET | `/api/posts/likes` | 公开 | `post_id*`、`page`、`limit`(5-50) | `{list,pagination}` |
| GET | `/api/posts/scheduled` | 登录 | `page`、`limit` | `{list,pagination,allowed,server_time}` |
| GET | `/api/search` | 公开 | `q`、`page`、`limit`(≤20)、`before_id`、`category_id`、`date_range`、`media_type` | `{list,pagination}`（游标时含 `next_before_id,has_more`） |
| POST | `/api/posts` | 登录 | 见下方「发布动态参数」 | 帖子对象 + `pending_review` |
| PUT | `/api/posts` | 登录 | `id*` + 待更新字段（同发布参数子集） | `{pending_review}` |
| DELETE | `/api/posts` | 登录 | `id*` | `null` |
| POST | `/api/posts/repost` | 登录 | `post_id*`、`content*`、`circle_id` | 帖子对象 + `origin_post` |
| POST | `/api/posts/feedback` | 登录 | `target_value*`、`action`(`dislike`/`tag`/`author`)、`target_type` | `{action,target_type}` |
| POST | `/api/posts/like` | 登录 | `post_id*` | `{liked,like_count,likes}` |
| POST | `/api/posts/comment` | 登录 | `post_id*`、`content*`(≤1000)、`image`、`images`(≤9)、`parent_id`、`mentions` | `{comment,post_user_id}` |
| DELETE | `/api/posts/comment` | 登录 | `id*` | `null` |
| POST | `/api/posts/comment-like` | 登录 | `comment_id*` | `{liked,like_count}` |
| POST | `/api/posts/comment/god` | 登录 | `comment_id*` | 标记结果 |
| POST | `/api/posts/comment/god/unmark` | 登录 | `comment_id*` | 标记结果 |
| POST | `/api/posts/comment/pin` | 登录 | `comment_id*` | 置顶结果 |
| POST | `/api/ai/assist` | 登录 | `content*`、`action*` | 润色 / 扩写结果 |
| POST | `/api/reports` | 登录 | `target_type*`、`target_id*`、`reason*` | 举报结果 |

#### 发布动态参数（`POST /api/posts`）

| 分组 | 字段 |
| --- | --- |
| 基础 | `content`(有媒体或帖型时可空)、`title`(≤255)、`category_id`(`category_required=1` 时必填)、`circle_id`、`post_type`、`visibility`、`scheduled_at` |
| 媒体 | `images[]`、`videos[]`、`attachments[]`（上限由 `/api/app/config` 的 `limits` 下发） |
| 位置 | `location`、`location_lat`、`location_lng` |
| 链接 | `link_url`、`link_title`、`link_desc`、`link_site` |
| 其他 | `is_anonymous`、`is_original`、`declaration`、`mentions[]` |
| 问答帖 | `question` |
| 投票帖 | `poll_question`、`poll_options[]` 或 `poll{options}`、`multi`、`duration_days` |
| 组队帖 | `headline`、`need_count`、`team_deadline`、`city`、`contact` |
| 打卡帖 | `goal`、`target_days` |

话题标签从 `content` / `title` 中的 `#话题#` 自动提取，不接收 `tags` 入参。

### 6.4 帖型扩展

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| POST | `/api/posts/poll/vote` | 登录 | `post_id*`、`option_id*` 或 `options*` | 投票对象 `{id,question,multi,total_votes,options[]}`；重复提交同选项即撤回 |
| POST | `/api/posts/question/answer` | 登录 | `post_id*`、`content*`(≤500) | 问答对象 `{question,status,answer_count,answers[],accepted_answer_id}` |
| POST | `/api/posts/question/accept` | 登录 | `post_id*`、`answer_id*` | 问答对象 |
| POST | `/api/posts/team/join` | 登录 | `post_id*` | 组队对象 `{need_count,joined_count,joined,is_full,members[]}` |
| POST | `/api/posts/team/leave` | 登录 | `post_id*` | 组队对象 |
| POST | `/api/posts/checkin/do` | 登录 | `post_id*`、`content`(≤100) | `{goal,target_days,my_total,done_today,percent}` |

### 6.5 用户与关系

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/user/profile` | 公开 | `username*` | `{user,stats}`；`stats` 含 `posts,likes,comments,likes_received,comments_received,following_count,followers_count` |
| GET | `/api/user/search` | 公开 | `keyword*`、`limit`(5-20) | **数组** `[{id,username,nickname,avatar,vip_level,badges}]` |
| GET | `/api/user/check-nickname` | 公开 | `nickname*` | `{available,message}` |
| GET | `/api/user/comments` | 公开 | `username*`、`page`、`limit` | `{list:[{comment,post}],pagination}`（无 `pages`） |
| GET | `/api/user/likes` | 公开 | `username*`、`page`、`limit` | `{list,pagination}`（无 `pages`） |
| GET | `/api/user/privacy` | 登录 | — | 隐私设置对象 |
| PUT | `/api/user/privacy` | 登录 | 隐私字段键值 | 隐私设置对象 |
| GET | `/api/user/drafts` | 登录 | `type`(`post`/`comment`) | `{list,pagination}` |
| POST | `/api/user/draft` | 登录 | `type`、`title`、`content`、`images`、`id`(编辑时)、`circle_id`、`post_id`、`visibility`、`scheduled_at`、`poll_options`、`poll_question`、`poll_duration`、`link_url`、`link_title`、`link_desc`、`link_site` | 草稿对象 |
| DELETE | `/api/user/draft` | 登录 | `id*` | `null` |
| GET | `/api/user/notify-prefs` | 登录 | — | 通知偏好对象 |
| PUT | `/api/user/notify-prefs` | 登录 | 偏好字段键值 | 通知偏好对象 |
| POST | `/api/user/appeal` | 登录 | `category*`、`content*`、`target_type`、`target_id` | 申诉对象 |
| GET | `/api/user/appeals` | 登录 | `page`、`limit` | `{list,pagination}` |
| POST | `/api/user/phone-code` | 登录 | `phone*` | `{sent,expires_in}` |
| POST | `/api/user/phone/verify` | 登录 | `phone*`、`code*` | 绑定结果 |
| GET | `/api/user/online` | 登录 | `user_id` | 在线状态 |
| PUT | `/api/user/alive` | 登录 | — | 心跳结果 |
| GET | `/api/user/export-data` | 登录 | `format`(`json`，仅支持 json；csv 返回 400) | 个人数据导出（结构化 JSON 包，不含密码/令牌；csv/zip 暂未实现） |
| POST | `/api/user/frame` | 登录 | `frame*` | 设置结果 |
| GET | `/api/follow/stats` | 公开 | `username` | `{following_count,followers_count,following,followers}` |
| GET | `/api/follow/followers` | 公开 | `username`、`page`、`limit`(≤50) | `{list,pagination}` |
| GET | `/api/follow/following` | 公开 | `username`、`page`、`limit`(≤50) | `{list,pagination}` |
| GET | `/api/follow/suggest` | 公开 | `limit`(3-20) | `{list}` |
| POST | `/api/follow` | 登录 | `user_id*` | `{following,followers_count}` |
| GET | `/api/follow/check` | 登录 | `user_id*` | `{following}` |
| POST | `/api/follow/batch-check` | 登录 | `user_ids*`(≤100) | **映射对象** `{"<user_id>":true/false}` |
| POST | `/api/follow/block` | 登录 | `user_id*` | 拉黑结果 |
| POST | `/api/follow/unblock` | 登录 | `user_id*` | 拉黑结果 |
| GET | `/api/follow/blocks` | 登录 | `page`、`limit` | `{list,pagination}` |
| GET | `/api/follow/blocked-check` | 登录 | `user_id*` | `{blocked}` |
| POST | `/api/favorites/toggle` | 登录 | `post_id*` | `{favorited}` |
| GET | `/api/favorites/list` | 登录 | `page`、`limit`(≤20) | `{list,pagination}`，元素含 `favorited_at` |
| GET | `/api/favorites/check` | 登录 | `post_id*` | `{favorited}` |

### 6.6 通知与实时

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/notifications` | 登录 | `page`、`limit`(默认15，≤50)、`before_id`、`type` | `{list,unread,unread_by_type,pagination}`；元素含 `sender{}`、`post{}|null` |
| GET | `/api/notifications/unread-count` | 登录 | — | `{count}` |
| PUT | `/api/notifications/{id}/read` | 登录 | 路径 `id*` | `null` |
| PUT | `/api/notifications/read-all` | 登录 | — | `null` |
| DELETE | `/api/notifications/{id}` | 登录 | 路径 `id*` | `null` |
| DELETE | `/api/notifications/clear` | 登录 | — | `null` |
| GET | `/api/sse` | 登录 | `token`(query 可用)、`last_event_id` | `text/event-stream`，见第 8 章 |
| GET | `/api/app/events` | 登录 | `last_event_id` | `{events,last_event_id,unread_notifications,unread_messages,server_time}` |
| POST | `/api/app/push-token` | 登录 | `token*`、`provider*`、`device_id` | 登记结果 |
| GET | `/api/badges/my` | 登录 | — | 已解锁徽章 + 进度 |
| DELETE | `/api/app/account` | 登录 | 确认参数 | 账号注销结果 |
| GET | `/api/app/devices` | 登录 | — | `{list}` 会话设备 |
| POST | `/api/app/devices/kick` | 登录 | `device_id*` | 踢下线结果 |

### 6.7 私信 IM

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/chat/conversations` | 登录 | `page`、`limit`(默认20) | `{list,pagination}`；元素含 `last_message`、`peer`、`unread_count` |
| GET | `/api/chat/messages` | 登录 | `conversation_id*`、`page`、`limit`(默认30)、`before_id` | `{list,pagination}`，含 `next_before_id,has_more` |
| GET | `/api/chat/new-messages` | 登录 | `conversation_id*`、`last_message_id` | `{list}` |
| POST | `/api/chat/send` | 登录 | `conversation_id*`、`content`(text 必填)、`type`、文件字段、`duration` | 消息对象 |
| PUT | `/api/chat/read` | 登录 | `conversation_id*` | 已读结果 |
| DELETE | `/api/chat/message` | 登录 | `id*` | 撤回结果（2 分钟窗口） |
| DELETE | `/api/chat/conversation` | 登录 | `conversation_id*` | `null` |
| GET | `/api/chat/deleted` | 登录 | `page`、`limit` | 回收站会话 |
| POST | `/api/chat/restore` | 登录 | `conversation_id*` | 恢复结果 |
| POST | `/api/chat/typing` | 登录 | `conversation_id*` | 仅实时广播，不落库 |
| GET | `/api/chat/unread-count` | 登录 | — | `{count}` |
| POST | `/api/chat/private` | 登录 | `user_id*` | 会话对象 |
| PUT | `/api/chat/settings` | 登录 | `conversation_id*`、设置字段 | 设置结果 |
| GET | `/api/chat/group/info` | 登录 | `conversation_id*` | 群信息 |
| GET | `/api/chat/group/members` | 登录 | `conversation_id*` | `{list}` |
| PUT | `/api/chat/group/announce` | 登录 | `conversation_id*`、`content*` | 群公告 |
| POST | `/api/chat/group/mute` | 登录 | `conversation_id*`、`user_id*`、`minutes` | 禁言结果 |
| POST | `/api/chat/group/kick` | 登录 | `conversation_id*`、`user_id*` | 踢人结果 |
| POST | `/api/chat/group/dissolve` | 登录 | `conversation_id*` | 解散结果 |

### 6.8 圈子

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/circles` | 公开 | `page`、`limit`、`keyword` | `{list,pagination}` |
| GET | `/api/circles/detail` | 公开 | `id*` | 圈子对象 |
| GET | `/api/circles/members` | 公开 | `id*`、`page`、`limit` | `{list,pagination}` |
| GET | `/api/circles/posts` | 公开 | `id*`、`page`、`limit` | `{list,pagination}` |
| GET | `/api/circles/blacklist` | 登录 | `id*` | 黑名单列表 |
| GET | `/api/circles/my` | 登录 | — | `{list}` |
| POST | `/api/circles` | 登录 | `name*`、`description`、`cover`、`join_mode`、`visibility`、`post_permission` | 圈子对象 |
| PUT | `/api/circles` | 登录 | `id*` + 待更新字段 | 圈子对象 |
| DELETE | `/api/circles` | 登录 | `id*` | `null` |
| POST | `/api/circles/join` | 登录 | `id*` | 加入结果 |
| POST | `/api/circles/leave` | 登录 | `id*` | `null` |
| GET | `/api/circles/my-requests` | 登录 | — | 我的申请列表 |
| GET | `/api/circles/pending-posts` | 登录（圈主/管理员） | `id*`、`page`、`limit` | 待审动态列表（`post_review` 开启时） |
| GET | `/api/circles/pending-requests` | 登录 | `id*` | 待审申请列表 |
| POST | `/api/circles/approve` | 登录 | `id*`、`user_id` | 审批结果 |
| POST | `/api/circles/reject` | 登录 | `id*`、`user_id`、`content` | 审批结果 |
| POST | `/api/circles/announce` | 登录 | `id*`、`content*`、`action` | 公告结果 |
| POST | `/api/circles/manage-member` | 登录 | `id*`、`user_id*`、`action*` | 成员结果 |
| POST | `/api/circles/mute-member` | 登录 | `id*`、`user_id*`、`minutes` | 禁言结果 |
| POST | `/api/circles/ban-member` | 登录 | `id*`、`user_id*` | 拉黑结果 |
| POST | `/api/circles/unban-member` | 登录 | `id*`、`user_id*` | 解黑结果 |
| POST | `/api/circles/manage-post` | 登录（圈主/管理员） | `id*`、`post_id*`、`action*`（含 `approve`/`reject`） | 内容治理 / 圈内审核结果 |
| POST | `/api/circles/group` | 登录 | `id*` | 群会话对象 |

### 6.9 活动

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/activities` | 公开 | `page`、`limit`、`status` | `{list,pagination}` |
| GET | `/api/activity/detail` | 公开 | `id*` | 活动对象 |
| GET | `/api/activity/mine` | 登录 | — | 我报名的活动 |
| POST | `/api/activity/join` | 登录 | `activity_id*` | 报名结果 |
| POST | `/api/activity/leave` | 登录 | `activity_id*` | 取消报名结果 |

### 6.10 钱包 / 会员 / 支付 / 积分

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/pay/wallet` | 登录 | `direction`、`type`、`page`、`limit` | `{list,summary,pagination}` |
| GET | `/api/pay/vip-info` | 登录 | — | 会员状态 |
| GET | `/api/pay/vip-orders` | 登录 | `page`、`limit` | `{list,pagination}` |
| POST | `/api/pay/vip-buy` | 登录 | `level*`、`coupon_id` | 订单对象 |
| GET | `/api/pay/circle-pay` | 登录 | `circle_id*` | 圈付费信息 |
| POST | `/api/pay/circle-subscribe` | 登录 | `circle_id*`、`use_points` | 订阅结果 |
| GET | `/api/pay/recharge-config` | 登录 | — | 充值渠道配置 |
| POST | `/api/pay/recharge-create` | 登录 | `amount*`、`method*` | 支付参数 |
| GET | `/api/pay/recharge-orders` | 登录 | `page`、`limit` | `{list,pagination}` |
| GET | `/api/points/home` | 登录 | — | 积分中心首页 |
| GET | `/api/points/tasks` | 登录 | — | 任务列表 |
| POST | `/api/points/signin` | 登录 | — | 签到结果 |
| GET | `/api/points/logs` | 登录 | `direction`、`type`、`page`、`limit` | `{list,summary,pagination}` |
| POST | `/api/points/claim` | 登录 | `task_id*` | 领取结果 |
| GET | `/api/points/wheel-prizes` | 公开 | — | 奖品配置 |
| POST | `/api/points/wheel` | 登录 | — | 抽奖结果 |
| GET | `/api/points/wheel-records` | 登录 | `page`、`limit` | `{list,pagination}` |
| POST | `/api/pay/notify/wxpay` | 系统 | 支付平台回调报文 | 平台要求的应答格式 |
| POST | `/api/pay/notify/alipay` | 系统 | 支付平台回调报文 | 平台要求的应答格式 |

### 6.11 商城

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/shop/config` | 公开 | — | 商城配置 |
| GET | `/api/shop/products` | 公开 | `page`、`limit`、`keyword`、`type` | `{list,pagination}` |
| GET | `/api/shop/product` | 公开 | `id*` | 商品对象 |
| POST | `/api/shop/buy` | 登录 | `product_id*`、`qty`、`pay_currency`(`balance` / `points`) | 订单对象 |
| GET | `/api/shop/orders` | 登录 | `page`、`limit` | `{list,pagination}` |
| POST | `/api/shop/post` | 登录 | `title*`、`price_balance*`、`content`、`cover`、`images` | 闲置商品对象 |
| GET | `/api/shop/my-posts` | 登录 | `page`、`limit` | `{list,pagination}` |
| POST | `/api/shop/post-delete` | 登录 | `id*` | `null` |

### 6.12 音视频通话

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| POST | `/api/call/token` | 登录 | — | TRTC 签名 |
| POST | `/api/call/invite` | 登录 | `callee_id*`、`type*` | 通话对象 |
| POST | `/api/call/accept` | 登录 | `room_id*` | 通话对象 |
| POST | `/api/call/reject` | 登录 | `room_id*` | `null` |
| POST | `/api/call/cancel` | 登录 | `room_id*`、`duration` | `null` |
| POST | `/api/call/hangup` | 登录 | `room_id*`、`duration` | 通话记录 |
| GET | `/api/call/pending` | 登录 | — | 待接听通话 |
| GET | `/api/call/records` | 登录 | `page`、`limit` | `{list,pagination}` |

### 6.13 运维端点

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/health` | 公开 | — | 健康检查（无限流、无鉴权） |
| GET | `/sitemap.xml` | 公开 | — | XML，非 JSON |
| GET | `/robots.txt` | 公开 | — | 文本，非 JSON |
| POST | `/api/cron/scheduled` | 系统 | `secret` 或 `X-Cron-Token` | 定时发布结果 |
| POST | `/api/cron/badges` | 系统 | `secret` 或 `X-Cron-Token` | 徽章扫描结果 |
| POST | `/api/cron/jobs` | 系统 | `secret` 或 `X-Cron-Token` | 异步任务结果 |
| POST | `/api/cron/license-heartbeat` | 系统 | `secret` 或 `X-Cron-Token` | `{reported:bool}`，主动触发一次授权统计心跳上报 |
| POST | `/api/cron/vip-expire` | 系统 | `secret` 或 `X-Cron-Token`、`limit` | `{scanned,downgraded,failed}`，VIP 到期批量降级（读时兜底保留；无 HTTP cron 可用 `php bin/expire-vip.php`） |
| GET | `/api/cron/jobs/status` | 系统 | — | 队列状态 |

### 6.14 补充接口

以下接口在前述小节中未单列，此处补齐，与 `api/routes.php` 一一对应。

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/category/following` | 登录 | — | 已关注的分类频道列表 |
| GET | `/api/category/status` | 登录 | — | 分类频道关注状态 |
| POST | `/api/category/follow` | 登录 | — | 关注结果 |
| GET | `/api/metrics` | 系统 | `?token=` / `X-Metrics-Token` / Bearer | Prometheus 指标（密钥也可经 `?token=` 传入，access 日志会脱敏该参数） |
| POST | `/api/cron/archive` | 系统 | `secret` 或 `X-Cron-Token` | 归档结果 |

### 6.15 内容付费（单篇买断 / 专栏订阅）

需开启 finance 授权模块；内容付费开关由站点模式的 contentpay 模块控制返回。价格只从服务端商品读取，接口不接受前端传价。回复中的 `paywall.locked=true` 表示正文为试读。

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/content-pay/product` | 登录 | `post_id` 或 `column_id` | `{product_type,target_id,post_type,price,preview_ratio,valid_days,unlocked,expire_at,reason,point_rate}` |
| GET | `/api/content-pay/column` | 登录 | `id*` | 专栏信息 + `items[]` + `unlocked` |
| POST | `/api/content-pay/buy` | 登录 | `product_type*`、`target_id*`、`use_points` | `{order_no,pay_amount,discount,expire_at,balance,points}`；已解锁含 `already_unlocked` |
| GET | `/api/content-pay/orders` | 登录 | `page`、`limit` | `{total,list}` |
| GET | `/api/content-pay/entitlements` | 登录 | — | `{list}` 已解锁权益 |

### 6.16 通用单页与插件扩展闭环（2026-11-21 新增）

通用单页与插件管理均为**免费基础能力**，不进 `KNOWN_MODULES`、无 `data-module`。后台路由属 `/api/admin` 组（`AdminMiddleware + requireAdmin()`，写操作需 CSRF）；公开路由走 `/api` 公开组。

#### 6.16.1 公开读取

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/pages/{slug}` | 公开 | 路径参数 `slug`；仅 `status=1` 可见 | `{slug,title,content,seo_title,seo_description,seo_keywords,updated_at}`；未命中或草稿 → 404（不回退） |
| GET | `/api/app/agreements` | 公开 | `type` = `user/privacy/realname` | **四字段结构不变** `{type,title,content,updated_at}`；正文优先取 `pages(slug=agreement_{type},status=1)`，回退 `settings.agreement_{type}_text`，再回退控制器缺省正文 |

#### 6.16.2 后台单页管理

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/admin/pages` | 管理员 | — | `{list:[...],total}` |
| POST | `/api/admin/pages` | 管理员 | `slug*`、`title*`、`content`、`seo_*`、`sort_order` | `{id}`；slug 重复 → 409，非法 → 400 |
| PUT | `/api/admin/pages` | 管理员 | `id*`（其余同上） | `{id}` |
| DELETE | `/api/admin/pages` | 管理员 | `id*` | `{deleted:true}` |
| POST | `/api/admin/pages/publish` | 管理员 | `slug*`、`status*` | `{slug,status}` |

`slug` 匹配 `^[a-z0-9]+(-[a-z0-9]+)*$`；`status` 0=草稿 1=发布；`template` 为预留模板标识。协议种子页 `source='seed'` 且默认草稿，站长确认后发布。

#### 6.16.3 后台插件生命周期、声明与配置

| 方法 | 路径 | 权限 | 主要参数 | data |
| --- | --- | --- | --- | --- |
| GET | `/api/admin/plugins` | 管理员 | — | 原 `{list:[...]}` 八字段 + 清单插件可选 `core_version/install_status/installed_version/admin_menu`；入参出参向后兼容 |
| PUT | `/api/admin/plugins` | 管理员 | `name*`、`enabled*` | `{enabled:bool}`；启停写 `plugin_states`，入参出参逐字不变 |
| POST | `/api/admin/plugins/install` | 管理员 | `name*`、`confirm` | `{name,install_status,installed_version}`；清单非法或核心版本不满足 422 / 其他生命周期失败 500（无 403 分支） |
| POST | `/api/admin/plugins/upgrade` | 管理员 | `name*`、`from*`、`to*` | `{name,from,to,install_status}` |
| POST | `/api/admin/plugins/uninstall` | 管理员 | `name*`；清单声明删表时另需 `confirm_token*` | `{name,enabled:false,data_kept,dropped}`；令牌缺失/无效 403；**cron_secret 未配置时 fail-closed，删表一律 403** |
| GET | `/api/admin/plugin-admin-config` | 管理员 | — | `{menus:[...],settings:[...]}`；仅已启用且有后台声明的插件，声明删表者附带 `drop_confirm_token` |
| GET | `/api/admin/plugin-config` | 管理员 | `plugin_name*`、`key*` | `{value}`；`key=_schema_tables` 为保留键，拒绝读写 |
| POST | `/api/admin/plugin-config` | 管理员 | `plugin_name*`、`key*`、`value*` | `{ok:true}`；`uk_plugin_config` 幂等 upsert |
| GET | `/api/admin/plugin-settings-page` | 管理员 | `plugin_name*` | `text/html`；插件目录内 `settings.html`，realpath 越界校验 |

示例插件 `plugins/DemoExtension/` 演示完整链路：清单 → 安装建表 `plugin_demo_notes` → 设置页开关 → `post_created` 钩子 → 升级 → 卸载（默认保留数据）。机制说明见 `docs/插件开发说明书.md` §18。

### 6.17 授权统计上报（心跳通道）

站点安装完成及日常请求（后台每次、前台每 5 分钟门控）会检查 `settings.license_heartbeat_last_at`，超过 `settings.license_report_interval`（默认 21600 秒）或首次安装 pending 时，经 `Cache::withLock('license_heartbeat_report')` 双重检查后补发一次。上报在响应发出后执行（PHP-FPM 先 `fastcgi_finish_request()`），失败静默、不阻断任何功能。

**客户端触发端点**：`POST {上报端点}`（与远程验签同入口、同 SSRF 防护）。端点优先级 `settings.license_report_endpoint` > `settings.license_remote_url` > `License::DEFAULT_REPORT_ENDPOINT`（内置默认值为发布前占位，替换域名只需改这一处常量）。

**请求头**：

| 头 | 值 | 说明 |
| --- | --- | --- |
| `X-Site-Report` | `heartbeat` | 分支选择器；无此头走原 license 验签路径 |
| `X-Hublog-Fingerprint` | `<fingerprint>` | 保留原授权链路头 |
| `X-Site-Installation` | `<installation_id>` | 与 body 不一致直接拒绝 |
| `X-Site-Timestamp` | `<timestamp>` | 与 body 不一致直接拒绝 |
| `X-Site-Nonce` | `<nonce>` | 与 body 不一致直接拒绝 |
| `X-Site-Signature` | `<signature>` | 优先验此值，缺失回落 body.signature |

**请求体 schema**（9 个签名字段 + 签名；`server_ip` 绝不发送）：

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `installation_id` | string(uuid v4) | 是 | 安装实例主键，与域名/host 无关 |
| `fingerprint` | string(sha256) | 是 | 硬件指纹，辅助识别 |
| `license_code` | string | 否 | 授权码，空串表示未提供 |
| `site_host` | string | 是 | 展示字段，可伪造会变化，不参与身份判定 |
| `version` | string | 是 | 源码版本 |
| `users` / `posts` | int | 否 | 聚合计数，非个人信息 |
| `timestamp` | int | 是 | Unix 秒，服务端时间窗 ±1800s |
| `nonce` | string(32 hex) | 是 | 一次性随机串，防重放 |
| `signature` | string(64 hex) | 是 | HMAC-SHA256 小写 hex，签名串为 `POST\n/remote_verify.php\n{timestamp}\n{nonce}\n{canonical_hash}`；密钥为 `HMAC(license_code|fingerprint, SEED)`；canonical = 标量字符串化 + `ksort(SORT_STRING)` + `json_encode(UNESCAPED_UNICODE|UNESCAPED_SLASHES)` |

**响应**：沿用 `{code,message,data,signature}` 结构；成功 `data={ok:true,installation_id,auth_status,server_time}`，客户端 fire-and-forget 不验响应 RSA 签名。拒绝时 `code=400/429/500`，`data.reason` 枚举：`HB_MISSING_INSTALLATION_ID`、`HB_HEADER_MISMATCH`、`HB_SIGNATURE_INVALID`、`HB_TIMESTAMP_OUT_OF_WINDOW`、`HB_NONCE_REPLAY`、`HB_RATE_LIMITED`。授权中心按 installation_id 去重 upsert，出口 IP 由服务端 `REMOTE_ADDR` 记录。

## 7 文件上传

全部需登录；`multipart/form-data`。

| 方法 | 路径 | 文件字段 | 其他参数 | 默认大小上限 | data |
| --- | --- | --- | --- | --- | --- |
| POST | `/api/posts/upload` | `image*` | `file_hash` | 10 MB | `{url}` |
| POST | `/api/posts/upload-video` | `video*` | `cover`、`duration`、`client_compressed`、`file_hash` | 50 MB | `{url,cover,duration}` |
| POST | `/api/posts/upload-attachment` | `attachment*` | `file_hash` | 50 MB | `{url,filename,size,file_hash}` |
| POST | `/api/auth/avatar` | `avatar*` | — | 由站点设置 | `{avatar}` |
| POST | `/api/auth/cover` | `cover*` | — | 由站点设置 | `{cover}` |
| POST | `/api/upload/sign` | — | `type*`、`filename*`、`content_length*`、`content_type` | 见下表 | 直传签名 |
| POST | `/api/upload/register` | — | `type*`、`object_key*`、`final_url*`、`file_size`、`orig_filename`、`mime_type` | — | 按 `type` 返回媒体对象 |
| POST | `/api/posts/delete-upload` | — | `url*` 或 `id*` | — | `null` |
| POST | `/api/uploads/cleanup` | — | — | — | 清理结果 |

| `type` | 允许图片格式 | `upload/sign` 上限 |
| --- | --- | --- |
| `avatar` | JPEG / PNG / GIF / WebP | 20 MB |
| `cover` | JPEG / PNG / GIF / WebP | 50 MB |
| `image` | JPEG / PNG / GIF / WebP | 50 MB |
| `video` | mp4 / webm / mov / avi / mkv / flv / wmv / m4v / 3gp / mpg / mpeg / ogv / ts 等 | 500 MB |
| `attachment` | zip / rar / 7z / pdf / doc / docx / xls / xlsx / ppt / pptx / txt | 500 MB |

| 规则 | 值 |
| --- | --- |
| 图片校验 | MIME 探测 + 文件头 + `getimagesize` 三重校验 |
| 视频校验 | 文件头 magic number + 扩展名白名单 |
| 压缩 | 由站点 `upload_compress` / `upload_max_width`（默认 1920）决定 |
| 直传流程 | `POST /api/upload/sign` → 客户端直传对象存储 → `POST /api/upload/register` 登记入库 |
| 实际限制 | 以 `/api/app/config` 的 `limits` 与 `/api/site/config` 的上传设置为准 |

## 8 实时通道

### 8.1 SSE（`GET /api/sse`）

| 项 | 值 |
| --- | --- |
| 响应类型 | `text/event-stream`（非 JSON） |
| 鉴权 | `Authorization` / `hublog_token` Cookie / `?token=`（与 `GET /api/metrics` 同为两处支持 query token 的接口之一） |
| 帧格式 | `id: <id>\nevent: <event>\ndata: <json>\n\n` |
| 固定事件 | `connected{user_id,time}`、`heartbeat{unread_notifications,unread_messages,time}`、`close{reason}` |
| 业务事件 | `event:{id,event,data,timestamp}` |
| 断点续传 | `Last-Event-ID` 请求头或 `last_event_id` 参数 |

### 8.2 长轮询（`GET /api/app/events`）

```json
{
  "events": [{"id": 12, "event": "comment", "data": {"post_id": 3}, "timestamp": 1731657600}],
  "last_event_id": 12,
  "unread_notifications": 2,
  "unread_messages": 1,
  "server_time": 1731657600
}
```

App / 小程序端用长轮询；H5 用 SSE。两者共用同一事件队列。

## 9 管理后台接口

全部位于 `/api/admin/*`，由后台 SPA 调用。

> 鉴权：`AuthMiddleware` + `AdminMiddleware`，非管理员 403；例外：`/api/admin/activities` 与 `/api/admin/my-circles` 对圈主放行（仅限自己圈子，判定依据 `circles.owner_id`）。
> 写操作（POST/PUT/DELETE）需 `X-CSRF-Token`。
> 后台 SPA 实际请求路径为 `/api/index.php/admin/xxx`。

当前表格共 169 条，逐条如下（扩展闭环新增的 plugin / pages 路由另见 §6.16 的参数与返回说明）。源码 `/api/admin/*` 实际共 185 条，其余 16 条 `/api/admin/appcenter/*` 见 `docs/应用中心-站点端接入说明.md`；它们未进入 `openapi.yaml`，在路由对账基线中列为允许缺失。

| 方法 | 路径 | 说明 | 控制器 |
| --- | --- | --- | --- |
| GET | `/api/admin/dashboard` | 后台概览 | `AdminController.dashboard` |
| GET | `/api/admin/users` | 用户列表 | `UserController.list` |
| PUT | `/api/admin/users/status` | 更新用户状态 | `UserController.updateStatus` |
| PUT | `/api/admin/users/update` | 更新用户资料 | `UserController.adminUpdate` |
| DELETE | `/api/admin/users` | 删除用户 | `UserController.delete` |
| GET | `/api/admin/posts` | 帖子列表 | `AdminController.posts` |
| PUT | `/api/admin/posts/status` | 更新帖子状态 | `AdminController.updatePostStatus` |
| PUT | `/api/admin/posts/pin` | 置顶帖子 | `AdminController.pinPost` |
| GET | `/api/admin/featured` | 精选列表 | `AdminController.featuredList` |
| POST | `/api/admin/featured/toggle` | 切换精选 | `AdminController.featuredToggle` |
| GET | `/api/admin/comments` | 评论列表 | `AdminController.comments` |
| DELETE | `/api/admin/comments` | 删除评论 | `AdminController.deleteComment` |
| GET | `/api/admin/attachments` | 附件列表 | `AdminAttachmentController.attachments` |
| PUT | `/api/admin/attachments/status` | 更新附件状态 | `AdminAttachmentController.updateAttachmentStatus` |
| DELETE | `/api/admin/attachments` | 删除附件 | `AdminAttachmentController.deleteAttachment` |
| GET | `/api/admin/settings` | 获取站点设置 | `AdminSettingsController.settings` |
| PUT | `/api/admin/settings` | 保存站点设置 | `AdminSettingsController.settings` |
| GET | `/api/admin/themes` | 主题列表 | `AdminSettingsController.themes` |
| GET | `/api/admin/theme-options` | 获取外观选项 | `AdminSettingsController.themeOptions` |
| PUT | `/api/admin/theme-options` | 保存外观选项 | `AdminSettingsController.saveThemeOptions` |
| POST | `/api/admin/theme-options/upload` | 上传主题图片 | `AdminSettingsController.uploadThemeImage` |
| GET | `/api/admin/storage/test-connection` | 测试存储连接 | `AdminSettingsController.storageTestConnection` |
| POST | `/api/admin/upload-homepage-cover` | 上传首页封面 | `AdminSettingsController.uploadHomepageCover` |
| POST | `/api/admin/upload-watermark` | 上传水印 | `AdminSettingsController.uploadWatermark` |
| POST | `/api/admin/watermark-preview` | 水印预览 | `AdminSettingsController.watermarkPreview` |
| GET | `/api/admin/friend-links` | 友链列表 | `AdminFriendLinkController.friendLinks` |
| POST | `/api/admin/friend-links` | 新建友链 | `AdminFriendLinkController.saveFriendLink` |
| PUT | `/api/admin/friend-links` | 更新友链 | `AdminFriendLinkController.saveFriendLink` |
| DELETE | `/api/admin/friend-links` | 删除友链 | `AdminFriendLinkController.deleteFriendLink` |
| GET | `/api/admin/app-versions` | App 版本列表 | `AdminAppVersionController.appVersions` |
| POST | `/api/admin/app-versions` | 新建 App 版本 | `AdminAppVersionController.saveAppVersion` |
| PUT | `/api/admin/app-versions` | 更新 App 版本 | `AdminAppVersionController.saveAppVersion` |
| DELETE | `/api/admin/app-versions` | 删除 App 版本 | `AdminAppVersionController.deleteAppVersion` |
| GET | `/api/admin/activities` | 活动列表 | `AdminActivitiesController.activities` |
| POST | `/api/admin/activities` | 新建活动 | `AdminActivitiesController.saveActivity` |
| PUT | `/api/admin/activities` | 更新活动 | `AdminActivitiesController.saveActivity` |
| DELETE | `/api/admin/activities` | 删除活动 | `AdminActivitiesController.deleteActivity` |
| GET | `/api/admin/my-circles` | 我的圈子 | `AdminActivitiesController.myCircles` |
| GET | `/api/admin/categories` | 分类列表 | `AdminCategoryController.categories` |
| POST | `/api/admin/categories` | 新建分类 | `AdminCategoryController.saveCategory` |
| PUT | `/api/admin/categories` | 更新分类 | `AdminCategoryController.saveCategory` |
| DELETE | `/api/admin/categories` | 删除分类 | `AdminCategoryController.deleteCategory` |
| POST | `/api/admin/backup` | 创建备份 | `AdminUpgradeController.backup` |
| POST | `/api/admin/backup/cleanup` | 清理备份 | `AdminUpgradeController.cleanupBackups` |
| GET | `/api/admin/version-check` | 版本检查 | `AdminUpgradeController.versionCheck` |
| POST | `/api/admin/upgrade` | 执行升级 | `AdminUpgradeController.upgrade` |
| POST | `/api/admin/upgrade-compare` | 版本比对 | `AdminUpgradeController.upgradeCompare` |
| GET | `/api/admin/upgrade/online-preflight` | 在线升级预检 | `AdminUpgradeController.onlinePreflight` |
| GET | `/api/admin/upgrade/online-compare` | 在线版本比对 | `AdminUpgradeController.onlineCompare` |
| POST | `/api/admin/upgrade/online-run` | 执行在线升级 | `AdminUpgradeController.onlineRun` |
| POST | `/api/admin/rollback` | 回滚版本 | `AdminUpgradeController.rollback` |
| GET | `/api/admin/reports` | 举报列表 | `ReportController.list` |
| PUT | `/api/admin/reports/resolve` | 处理举报 | `ReportController.resolve` |
| POST | `/api/admin/ai/test` | 测试 AI 服务 | `AdminAiController.testAi` |
| GET | `/api/admin/ai/ollama-status` | 检测 Ollama 状态 | `AdminAiController.ollamaStatus` |
| POST | `/api/admin/ai/ollama-install` | 安装 Ollama | `AdminAiController.ollamaInstall` |
| POST | `/api/admin/posts/batch` | 批量操作帖子 | `AdminBatchController.batchPosts` |
| POST | `/api/admin/comments/batch` | 批量操作评论 | `AdminBatchController.batchComments` |
| POST | `/api/admin/users/batch` | 批量操作用户 | `AdminBatchController.batchUsers` |
| GET | `/api/admin/export` | 导出数据 | `AdminToolsController.exportData` |
| GET | `/api/admin/stats` | 运行统计 | `AdminToolsController.stats` |
| GET | `/api/admin/topics` | 话题列表 | `AdminToolsController.topics` |
| POST | `/api/admin/topics` | 新建话题 | `AdminToolsController.saveTopic` |
| PUT | `/api/admin/topics` | 更新话题 | `AdminToolsController.saveTopic` |
| DELETE | `/api/admin/topics` | 删除话题 | `AdminToolsController.deleteTopic` |
| POST | `/api/admin/topics/cover` | 设置话题封面 | `AdminToolsController.setTopicCover` |
| GET | `/api/admin/circles` | 圈子列表 | `AdminCircleController.circles` |
| POST | `/api/admin/circles` | 新建圈子 | `AdminCircleController.saveCircle` |
| PUT | `/api/admin/circles` | 更新圈子 | `AdminCircleController.saveCircle` |
| DELETE | `/api/admin/circles` | 删除圈子 | `AdminCircleController.deleteCircle` |
| GET | `/api/admin/circles/members` | 圈子成员列表 | `AdminCircleController.circleMembers` |
| DELETE | `/api/admin/circles/members` | 移除圈子成员 | `AdminCircleController.removeCircleMember` |
| POST | `/api/admin/circles/members/role` | 设置圈子成员角色 | `AdminCircleController.setCircleMemberRole` |
| POST | `/api/admin/circles/transfer` | 转让圈主 | `AdminCircleController.transferCircleOwner` |
| GET | `/api/admin/realname` | 实名认证列表 | `RealNameController.adminList` |
| POST | `/api/admin/realname/review` | 审核实名认证 | `RealNameController.adminReview` |
| GET | `/api/admin/circles/requests` | 待审申请列表 | `AdminCircleController.circlePendingRequests` |
| POST | `/api/admin/circles/requests/approve` | 通过圈子申请 | `AdminCircleController.approveCircleRequest` |
| POST | `/api/admin/circles/requests/reject` | 驳回圈子申请 | `AdminCircleController.rejectCircleRequest` |
| GET | `/api/admin/plugins` | 插件列表 | `AdminToolsController.plugins` |
| PUT | `/api/admin/plugins` | 启用/停用插件 | `AdminToolsController.togglePlugin` |
| POST | `/api/admin/plugins/install` | 安装插件（清单建表 + install 钩子） | `AdminPluginController.install` |
| POST | `/api/admin/plugins/upgrade` | 升级插件（upgrade 钩子） | `AdminPluginController.upgrade` |
| POST | `/api/admin/plugins/uninstall` | 卸载插件（默认保留数据，删表需令牌） | `AdminPluginController.uninstall` |
| GET | `/api/admin/plugin-admin-config` | 插件后台菜单/设置声明汇总 | `AdminPluginController.adminConfig` |
| GET | `/api/admin/plugin-config` | 读插件配置 | `AdminPluginController.config` |
| POST | `/api/admin/plugin-config` | 写插件配置（幂等 upsert） | `AdminPluginController.saveConfig` |
| GET | `/api/admin/plugin-settings-page` | 读取插件 settings.html | `AdminPluginController.settingsPage` |
| GET | `/api/admin/pages` | 单页列表 | `AdminPageController.index` |
| POST | `/api/admin/pages` | 新建单页 | `AdminPageController.save` |
| PUT | `/api/admin/pages` | 更新单页 | `AdminPageController.save` |
| DELETE | `/api/admin/pages` | 删除单页 | `AdminPageController.delete` |
| POST | `/api/admin/pages/publish` | 发布/下线单页 | `AdminPageController.publish` |
| POST | `/api/admin/backup/auto` | 执行自动备份 | `AdminToolsController.autoBackup` |
| POST | `/api/admin/mail/test` | 测试邮件发送 | `AdminToolsController.testMail` |
| GET | `/api/admin/license` | 授权状态 | `AdminLicenseController.licenseStatus` |
| POST | `/api/admin/license/activate` | 激活授权 | `AdminLicenseController.licenseActivate` |
| POST | `/api/admin/license/deactivate` | 停用授权 | `AdminLicenseController.licenseDeactivate` |
| POST | `/api/admin/license/refresh-hashes` | 刷新授权哈希 | `AdminLicenseController.licenseRefreshHashes` |
| POST | `/api/admin/license/save-settings` | 保存授权设置 | `AdminLicenseController.licenseSaveSettings` |
| POST | `/api/admin/license/test-remote` | 远程测试授权 | `AdminLicenseController.licenseTestRemote` |
| POST | `/api/admin/license/sync-revoked` | 同步吊销列表 | `AdminLicenseController.licenseSyncRevoked` |
| GET | `/api/admin/chat/conversations` | 会话列表 | `AdminChatController.chatConversations` |
| GET | `/api/admin/chat/messages` | 消息列表 | `AdminChatController.chatMessages` |
| GET | `/api/admin/chat/deletions` | 删除记录 | `AdminChatController.chatDeletions` |
| POST | `/api/admin/chat/restore` | 恢复消息 | `AdminChatController.chatRestoreMessage` |
| GET | `/api/admin/chat/export` | 导出会话记录 | `AdminChatController.chatExport` |
| DELETE | `/api/admin/chat/message` | 删除消息 | `AdminChatController.chatDeleteMessage` |
| POST | `/api/admin/chat/batch-delete` | 批量删除私信消息 | `AdminChatController.chatBatchDelete` |
| POST | `/api/admin/chat/cleanup-expired` | 清理过期私信 | `AdminChatController.chatCleanupExpired` |
| DELETE | `/api/admin/chat/conversation` | 删除会话 | `AdminChatController.chatDeleteConversation` |
| GET | `/api/admin/wallet-logs` | 钱包流水 | `AdminFinanceController.walletLogs` |
| POST | `/api/admin/wallet-adjust` | 调整钱包 | `AdminFinanceController.walletAdjust` |
| GET | `/api/admin/vip-orders` | 会员订单 | `AdminFinanceController.vipOrders` |
| POST | `/api/admin/grant-vip` | 发放会员 | `AdminFinanceController.grantVip` |
| GET | `/api/admin/points-logs` | 积分流水 | `AdminPointsController.pointsLogs` |
| POST | `/api/admin/points-adjust` | 调整积分 | `AdminPointsController.pointsAdjust` |
| GET | `/api/admin/point-tasks` | 积分任务列表 | `AdminPointsController.pointTasks` |
| POST | `/api/admin/point-task-save` | 保存积分任务 | `AdminPointsController.pointTaskSave` |
| POST | `/api/admin/point-task-delete` | 删除积分任务 | `AdminPointsController.pointTaskDelete` |
| GET | `/api/admin/shop-products` | 商城商品列表 | `ShopController.adminProducts` |
| POST | `/api/admin/shop-product-save` | 新建商城商品 | `ShopController.adminProductSave` |
| PUT | `/api/admin/shop-product-save` | 更新商城商品 | `ShopController.adminProductSave` |
| POST | `/api/admin/shop-product-delete` | 删除商城商品 | `ShopController.adminProductDelete` |
| GET | `/api/admin/shop-orders` | 商城订单 | `ShopController.adminOrders` |
| POST | `/api/admin/shop-ship` | 商城发货 | `ShopController.adminShip` |
| GET | `/api/admin/wheel-prizes` | 转盘奖品列表 | `ShopController.adminWheelPrizes` |
| POST | `/api/admin/wheel-prize-save` | 新建转盘奖品 | `ShopController.adminWheelPrizeSave` |
| PUT | `/api/admin/wheel-prize-save` | 更新转盘奖品 | `ShopController.adminWheelPrizeSave` |
| POST | `/api/admin/wheel-prize-delete` | 删除转盘奖品 | `ShopController.adminWheelPrizeDelete` |
| GET | `/api/admin/recharge-orders` | 充值订单 | `PaymentController.adminRechargeOrders` |
| GET | `/api/admin/chat/storage-stats` | 私信存储统计 | `AdminChatController.chatStorageStats` |
| POST | `/api/admin/chat/cleanup-orphan` | 清理孤儿私信 | `AdminChatController.chatCleanupOrphan` |
| GET | `/api/admin/chat/settings` | 获取私信设置 | `AdminChatController.chatGetSettings` |
| PUT | `/api/admin/chat/settings` | 保存私信设置 | `AdminChatController.chatSaveSettings` |
| GET | `/api/admin/content-pay/orders` | 内容付费全站订单 | `AdminContentPayController.orders` |
| GET | `/api/admin/content-pay/columns` | 专栏列表 | `AdminContentPayController.columns` |
| POST | `/api/admin/content-pay/product` | 内容定价 upsert | `AdminContentPayController.saveProduct` |
| POST | `/api/admin/content-pay/column` | 专栏 upsert | `AdminContentPayController.saveColumn` |
| POST | `/api/admin/content-pay/item` | 专栏条目 upsert | `AdminContentPayController.saveItem` |
| DELETE | `/api/admin/content-pay/item` | 删除专栏条目 | `AdminContentPayController.deleteItem` |
| POST | `/api/admin/content-pay/grant` | 免费赠送权益 | `AdminContentPayController.grant` |
| POST | `/api/admin/posts/cleanup-orphan-uploads` | 清理孤儿上传 | `PostController.cleanupOrphanUploads` |
| GET | `/api/admin/sensitive-words` | 敏感词列表 | `AdminModerationController.sensitiveWords` |
| POST | `/api/admin/sensitive-words` | 新建敏感词 | `AdminModerationController.saveSensitiveWord` |
| PUT | `/api/admin/sensitive-words` | 更新敏感词 | `AdminModerationController.saveSensitiveWord` |
| DELETE | `/api/admin/sensitive-words` | 删除敏感词 | `AdminModerationController.deleteSensitiveWord` |
| POST | `/api/admin/sensitive-words/import` | 导入敏感词 | `AdminModerationController.importSensitiveWords` |
| GET | `/api/admin/filter-logs` | 过滤日志 | `AdminModerationController.filterLogs` |
| POST | `/api/admin/users/mute` | 禁言用户 | `AdminModerationController.muteUser` |
| POST | `/api/admin/users/unmute` | 解除用户禁言 | `AdminModerationController.unmuteUser` |
| POST | `/api/admin/circles/moderate` | 管理圈子内容 | `AdminCircleController.circleModerate` |
| GET | `/api/admin/badges` | 徽章列表 | `BadgeController.adminList` |
| POST | `/api/admin/badge-save` | 新建徽章 | `BadgeController.adminSave` |
| PUT | `/api/admin/badge-save` | 更新徽章 | `BadgeController.adminSave` |
| POST | `/api/admin/badge-toggle` | 徽章开关 | `BadgeController.adminToggle` |
| GET | `/api/admin/user-badges` | 用户徽章列表 | `BadgeController.adminUserBadges` |
| POST | `/api/admin/user-badge-grant` | 发放用户徽章 | `BadgeController.adminGrant` |
| POST | `/api/admin/user-badge-freeze` | 冻结用户徽章 | `BadgeController.adminFreeze` |
| POST | `/api/admin/user-badge-revoke` | 吊销用户徽章 | `BadgeController.adminRevoke` |
| GET | `/api/admin/badge-logs` | 徽章日志 | `BadgeController.adminLogs` |
| POST | `/api/admin/badge-scan` | 扫描徽章 | `BadgeController.adminScan` |
| GET | `/api/admin/badge-god-marks` | 大神标记列表 | `BadgeController.adminGodMarks` |
| DELETE | `/api/admin/badge-god-marks` | 删除大神标记 | `BadgeController.adminDeleteGodMark` |
| GET | `/api/admin/crash-logs` | 崩溃日志列表 | `AdminCrashController.crashLogs` |
| PUT | `/api/admin/crash-alert-threshold` | 崩溃告警阈值 | `AdminCrashController.crashAlertThreshold` |
| GET | `/api/admin/appeals` | 申诉列表 | `UserController.adminAppeals` |
| POST | `/api/admin/appeal-handle` | 处理申诉 | `UserController.adminHandleAppeal` |
| GET | `/api/admin/shop-coupons` | 商城优惠券列表 | `ShopController.adminCoupons` |

## 10 调用示例

### 10.1 登录并读取信息流

```bash
BASE='https://example.com/api'

# 1. 登录（写操作先取 CSRF；app 端带 X-Client-Type 可跳过）
CSRF=$(curl -s "$BASE/csrf" | node -pe 'JSON.parse(require("fs").readFileSync(0,"utf8")).data.token')

TOKEN=$(curl -s -X POST "$BASE/auth/login" \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -H 'X-Client-Type: app' \
  -d '{"username":"demo","password":"secret"}' \
  | node -pe 'JSON.parse(require("fs").readFileSync(0,"utf8")).data.token')

# 2. 首页信息流
curl -s "$BASE/posts/timeline?page=1&limit=10" \
  -H "Authorization: Bearer $TOKEN"
```

### 10.2 App 端免 CSRF 写操作

```bash
curl -s -X POST 'https://example.com/api/posts/like' \
  -H 'Content-Type: application/json' \
  -H 'X-Client-Type: app' \
  -H 'Authorization: Bearer <token>' \
  -d '{"post_id": 12}'
```

### 10.3 H5 端写操作（Cookie 登录态）

```bash
CSRF=$(curl -s 'https://example.com/api/csrf' \
  | node -pe 'JSON.parse(require("fs").readFileSync(0,"utf8")).data.token')

curl -s -X POST 'https://example.com/api/posts/comment' \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -b 'hublog_token=<token>' \
  -d '{"post_id":12,"content":"第一条评论"}'
```

### 10.4 发布带图动态

```bash
# 1. 上传图片
URL=$(curl -s -X POST 'https://example.com/api/posts/upload' \
  -H 'Authorization: Bearer <token>' \
  -F 'image=@/path/to/photo.jpg' \
  | node -pe 'JSON.parse(require("fs").readFileSync(0,"utf8")).data.url')

# 2. 发布（images 用上一步返回的绝对 URL 数组）
curl -s -X POST 'https://example.com/api/posts' \
  -H 'Content-Type: application/json' \
  -H 'X-Client-Type: app' \
  -H 'Authorization: Bearer <token>' \
  -d "{\"content\":\"今天的好天气 #日常#\",\"images\":[\"$URL\"]}"
```

### 10.5 令牌轮换

```bash
curl -s -X POST 'https://example.com/api/auth/token-refresh' \
  -H 'Content-Type: application/json' \
  -H 'X-Client-Type: app' \
  -d '{"refresh_token":"<refresh_token>"}'
```

## 11 自检清单

| # | 检查项 |
| --- | --- |
| 1 | 所有请求路径以 `/api` 开头；重定向与 SPA 回落不当作接口成功 |
| 2 | 成功判定用 `body.code === 200`，并兼容 `code=4230` 但 HTTP 200 的情况 |
| 3 | 失败响应以 `message` 为主；`data` 多数为 `null`，部分错误带结构化详情（如 `{field:...}`、`{logs:[...]}`），按需读取 |
| 4 | 写操作带 `X-CSRF-Token`，或 `X-Client-Type: app` 且无会话 Cookie |
| 5 | token 放在 `Authorization: Bearer`，不用 `?token=`（仅 `GET /api/sse`、`GET /api/metrics` 两处支持） |
| 6 | 登录时按 `X-Client-Type` / `device_id` 决定是否拿到 refresh token；过期走 `/api/auth/token-refresh` |
| 7 | 分页循环终止条件用 `pagination.pages` 或 `has_more`；游标模式读 `next_cursor` |
| 8 | 媒体字段按绝对 URL 使用，不自行拼接站点域名 |
| 9 | `post_type` / `visibility` 只使用第 4.4 节枚举值 |
| 10 | 时间线媒体过滤用 `media`，搜索媒体过滤用 `media_type` |
| 11 | `/api/site/ranking`、`/api/user/search`、`/api/follow/batch-check` 不按 `{list}` 解析 |
| 12 | 上传大小与数量上限从 `/api/app/config` 的 `limits` 读取，不硬编码 |
| 13 | 限流按 60 秒窗口退避；收到 429 后不要立即重试 |
| 14 | 500 响应记录 `error_id`，用于排障 |
| 15 | 生产把 `CORS_ALLOWED_ORIGINS` 收敛为具体域名白名单 |
