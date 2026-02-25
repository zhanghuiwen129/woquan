# 社交应用系统

## 项目简介

这是一个基于 ThinkPHP 框架开发的现代化社交应用系统，提供丰富的社交功能，包括动态发布、评论互动、话题讨论、实时聊天等核心功能。系统采用前后端分离架构，前端使用现代 JavaScript 技术栈，后端使用 PHP + ThinkPHP 框架，支持多种存储方案和部署环境。

## 核心功能
这是AI编写的，只有基础功能能使用，还有很多的问题没有处理，希望有大佬能略微出手一下。

### 前端功能
- **动态发布**：支持文字、图片、视频等多种形式的内容发布
- **评论系统**：抖音式无限楼中楼评论，支持回复、点赞、热门评论置顶
- **话题系统**：支持话题创建、关注、热门话题推荐
- **用户互动**：关注/粉丝、点赞、收藏、分享
- **实时聊天**：支持一对一聊天、群聊、消息通知
- **内容发现**：热门推荐、附近的人、兴趣标签
- **个人中心**：用户资料编辑、动态管理、消息中心
- **多媒体支持**：图片上传、视频上传、表情系统
- **地理位置**：基于位置的服务，附近的动态

### 后端功能
- **用户管理**：注册、登录、认证、权限控制
- **内容管理**：动态审核、评论管理、话题管理
- **存储系统**：支持本地存储、云存储（OSS、COS、七牛云）
- **缓存系统**：Redis 缓存，提升系统性能
- **搜索系统**：全文检索，支持用户、动态、话题搜索
- **数据分析**：用户行为分析、内容热度分析
- **安全防护**：防SQL注入、XSS攻击、敏感词过滤

### 后台管理
- **用户管理**：用户列表、黑名单、等级管理
- **内容管理**：动态审核、评论管理、话题管理
- **系统设置**：网站配置、存储配置、消息模板
- **数据分析**：运营数据、用户增长、内容趋势
- **工具系统**：数据库维护、缓存清理、日志管理

## 技术栈

### 后端
- **框架**：ThinkPHP 6.x
- **数据库**：MySQL 5.7+
- **缓存**：Redis
- **认证**：JWT
- **存储**：本地存储 / OSS / COS / 七牛云
- **WebSocket**：Swoole（实时聊天）

### 前端
- **JavaScript**：ES6+
- **CSS**：Tailwind CSS
- **图标**：FontAwesome
- **动画**：CSS3 动画
- **网络请求**：Fetch API
- **WebSocket**：浏览器原生 WebSocket

## 系统要求

### 服务器环境
- **PHP**：7.4+
- **MySQL**：5.7+
- **Nginx/Apache**：支持 URL 重写
- **Redis**：推荐 5.0+
- **Swoole**：4.5+（实时聊天功能）
- **内存**：至少 2GB
- **磁盘**：至少 20GB 可用空间

### 浏览器支持
- Chrome 60+
- Firefox 55+
- Safari 12+
- Edge 79+

## 安装部署

### 1. 环境准备

确保服务器已安装以下软件：
- PHP 7.4+（开启所需扩展：pdo_mysql、curl、gd、fileinfo、exif、mbstring）
- MySQL 5.7+
- Nginx/Apache
- Redis（可选，用于缓存和WebSocket）
- Composer（PHP 依赖管理）

### 2. 代码部署

```bash
# 克隆代码库
git clone <repository-url> social-app

# 进入项目目录
cd social-app

# 安装依赖
composer install

# 配置环境变量
cp .env.example .env
# 编辑 .env 文件，配置数据库连接、缓存等信息
```

### 3. 数据库安装

- 创建数据库（默认数据库名：`social_app`）
- 导入数据库结构文件：`database/install.sql`
- 或访问安装向导：`http://your-domain/install`

### 4. 服务器配置

#### Nginx 配置示例

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/social-app/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # WebSocket 配置
    location /websocket/ {
        proxy_pass http://127.0.0.1:9501;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
    }
}
```

### 5. 启动服务

- **启动 WebSocket 服务**（聊天功能）：
  ```bash
  php public/websocket/chat-server.php start
  ```

- **配置定时任务**（可选）：
  ```bash
  # 每分钟执行一次
  * * * * * php /path/to/social-app/think cron:run
  ```

### 6. 访问系统

- **前台**：`http://your-domain.com`
- **后台**：`http://your-domain.com/admin`
  - 默认管理员账号：`admin`
  - 默认管理员密码：`admin123`

## 目录结构

```
social-app/
├── app/                 # 应用核心代码
│   ├── controller/      # 控制器
│   ├── model/           # 模型
│   ├── service/         # 服务
│   ├── middleware/      # 中间件
│   └── common.php       # 公共函数
├── config/              # 配置文件
├── public/              # 公共资源
│   ├── static/          # 静态资源
│   ├── uploads/         # 上传文件
│   └── websocket/       # WebSocket 服务
├── route/               # 路由配置
├── view/                # 视图文件
├── database/            # 数据库相关
├── docs/                # 文档
├── temp_backup/         # 临时备份
├── vendor/              # 第三方依赖
├── .env                 # 环境配置
└── index.php            # 入口文件
```

## 核心模块说明

### 1. 动态系统（Moments）

**功能特点**：
- 支持多种内容类型：文字、图片、视频
- 话题标签自动识别和关联
- 地理位置信息记录
- 内容审核机制
- 隐私设置（公开、私密）

**主要文件**：
- `app/controller/Moments.php` - 动态控制器
- `app/model/Moment.php` - 动态模型
- `public/static/js/pages/moments.js` - 前端动态模块

### 2. 评论系统（Comments）

**功能特点**：
- 抖音式无限楼中楼评论
- 支持评论回复、点赞
- 热门评论置顶
- 评论审核机制
- 评论通知

**主要文件**：
- `app/controller/Comments.php` - 评论控制器
- `app/model/Comment.php` - 评论模型
- `public/static/js/comment.js` - 前端评论模块

### 3. 用户系统（User）

**功能特点**：
- 用户注册、登录、找回密码
- 个人资料编辑
- 头像上传
- 关注/粉丝系统
- 用户等级和经验值

**主要文件**：
- `app/controller/User.php` - 用户控制器
- `app/model/User.php` - 用户模型
- `public/static/js/pages/user-card.js` - 前端用户模块

### 4. 聊天系统（Chat）

**功能特点**：
- 实时一对一聊天
- 群聊功能
- 消息历史记录
- 消息已读状态
- 多媒体消息支持

**主要文件**：
- `app/controller/Chat.php` - 聊天控制器
- `public/websocket/chat-server.php` - WebSocket 服务
- `public/static/js/chat-core.js` - 前端聊天模块

### 5. 话题系统（Topics）

**功能特点**：
- 话题创建和管理
- 话题关注
- 热门话题推荐
- 话题内容聚合

**主要文件**：
- `app/controller/Topics.php` - 话题控制器
- `app/model/Topic.php` - 话题模型
- `public/static/js/pages/hot-topics.js` - 前端话题模块

### 6. 存储系统（Storage）

**功能特点**：
- 支持多种存储方案
- 文件上传和管理
- 图片压缩和处理
- 文件访问控制
- 存储使用统计

**主要文件**：
- `app/controller/Storage.php` - 存储控制器
- `app/service/StorageService.php` - 存储服务
- `app/service/StorageFactory.php` - 存储工厂

## API 文档

### 认证相关

#### 登录
- **URL**: `/api/user/login`
- **方法**: POST
- **参数**: `username` (用户名), `password` (密码)
- **返回**: JWT token 和用户信息

#### 注册
- **URL**: `/api/user/register`
- **方法**: POST
- **参数**: `username` (用户名), `password` (密码), `nickname` (昵称)
- **返回**: 注册结果

### 动态相关

#### 获取动态列表
- **URL**: `/api/moments/list`
- **方法**: GET
- **参数**: `page` (页码), `limit` (每页数量), `type` (类型)
- **返回**: 动态列表

#### 发布动态
- **URL**: `/api/moments/add`
- **方法**: POST
- **参数**: `content` (内容), `images` (图片), `video` (视频), `location` (位置)
- **返回**: 发布结果

### 评论相关

#### 获取评论列表
- **URL**: `/api/comments/list`
- **方法**: GET
- **参数**: `moment_id` (动态ID), `page` (页码), `limit` (每页数量)
- **返回**: 评论列表

#### 发表评论
- **URL**: `/api/comments/add`
- **方法**: POST
- **参数**: `moment_id` (动态ID), `content` (内容), `parent_id` (父评论ID)
- **返回**: 评论结果

### 用户相关

#### 获取用户信息
- **URL**: `/api/user/info`
- **方法**: GET
- **参数**: `user_id` (用户ID)
- **返回**: 用户信息

#### 关注用户
- **URL**: `/api/user/follow`
- **方法**: POST
- **参数**: `user_id` (用户ID)
- **返回**: 关注结果

## 开发指南

### 代码规范

1. **PHP 代码规范**：
   - 遵循 PSR-12 代码规范
   - 使用 4 个空格缩进
   - 类名使用驼峰命名法
   - 方法名使用小驼峰命名法
   - 变量名使用下划线分隔

2. **JavaScript 代码规范**：
   - 遵循 ES6+ 语法规范
   - 使用 2 个空格缩进
   - 变量使用 const/let 声明
   - 函数使用箭头函数（适当场景）

3. **CSS 代码规范**：
   - 使用 Tailwind CSS 类名
   - 自定义样式放在 `public/static/css` 目录
   - 遵循 BEM 命名规范（自定义类名）

### 开发流程

1. **环境搭建**：
   - 克隆代码库
   - 安装依赖
   - 配置数据库
   - 启动开发服务器

2. **功能开发**：
   - 创建控制器和模型
   - 配置路由
   - 开发前端页面
   - 测试功能

3. **代码提交**：
   - 执行代码检查
   - 运行测试
   - 提交代码

### 测试指南

1. **单元测试**：
   ```bash
   php think unit
   ```

2. **API 测试**：
   - 使用 Postman 或类似工具测试 API
   - 测试数据准备
   - 测试用例编写

3. **性能测试**：
   - 使用 Apache Bench 或 JMeter 进行压力测试
   - 监控系统资源使用情况

## 部署指南

### 生产环境部署

1. **环境配置**：
   - 关闭调试模式：`APP_DEBUG=false`
   - 配置缓存：`CACHE_DRIVER=redis`
   - 配置日志：`LOG_CHANNEL=file`

2. **性能优化**：
   - 启用 OPcache
   - 配置 PHP 内存限制：`memory_limit=512M`
   - 优化 MySQL 配置
   - 配置 Redis 持久化

3. **安全配置**：
   - 配置 HTTPS
   - 限制文件上传大小
   - 配置 CORS 策略
   - 定期备份数据库

### 容器化部署

**Docker 部署示例**：

```bash
# 构建镜像
docker build -t social-app .

# 运行容器
docker run -d \
  --name social-app \
  -p 80:80 \
  -v ./data:/app/public/uploads \
  -e DB_HOST=mysql \
  -e DB_NAME=social_app \
  -e DB_USER=root \
  -e DB_PASS=password \
  social-app
```

## 维护指南

### 日常维护

1. **日志管理**：
   - 定期查看系统日志
   - 配置日志轮转

2. **数据库维护**：
   - 定期备份数据库
   - 优化数据库表结构
   - 清理冗余数据

3. **缓存管理**：
   - 定期清理缓存
   - 监控缓存使用情况

### 常见问题

1. **上传文件失败**：
   - 检查文件权限
   - 检查 upload_max_filesize 配置
   - 检查存储路径配置

2. **WebSocket 连接失败**：
   - 检查 WebSocket 服务是否运行
   - 检查防火墙配置
   - 检查 Nginx 代理配置

3. **性能问题**：
   - 启用缓存
   - 优化数据库查询
   - 检查服务器资源使用情况

## 许可证

本项目采用 MIT 许可证，详见 LICENSE 文件。

## 更新日志

### v1.0.0（2026-02-11）
- 项目初始化
- 核心功能实现
- 前端界面开发
- 后台管理系统

### v1.0.1（2026-02-12）
- 修复评论系统bug
- 优化文件上传功能
- 改进用户体验

## 贡献指南

欢迎各位开发者贡献代码和建议！

1. **Fork 本项目**
2. **创建功能分支**
3. **提交代码**
4. **发起 Pull Request**

## 联系方式

- **项目地址**：https://github.com/username/social-app
- **问题反馈**：https://github.com/username/social-app/issues
- **邮件联系**：contact@example.com

---

**© 2026 社交应用系统 - 保留所有权利**
