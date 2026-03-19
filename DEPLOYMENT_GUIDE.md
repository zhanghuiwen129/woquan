# 🚀 新手部署指南 - 我圈社交平台优化版

## 📋 目录
1. [准备工作](#准备工作)
2. [数据库优化](#数据库优化)
3. [Redis配置](#redis配置)
4. [代码部署](#代码部署)
5. [测试验证](#测试验证)
6. [常见问题](#常见问题)

---

## 准备工作

### 需要的环境
- ✅ PHP 7.4 或更高版本
- ✅ MySQL 8.0 或更高版本
- ✅ Redis 服务器
- ✅ Composer（PHP包管理器）
- ✅ Web服务器（Apache/Nginx）

### 检查环境
在浏览器访问：`http://你的域名/install/env_check.php`
确保所有环境检查都通过。

---

## 数据库优化

### 方法一：使用phpMyAdmin（推荐新手）

1. **登录phpMyAdmin**
   - 访问：`http://你的域名/phpmyadmin`
   - 输入数据库用户名和密码登录

2. **选择数据库**
   - 在左侧列表中找到你的数据库名（如：quansns）
   - 点击选中

3. **导入优化脚本**
   - 点击顶部菜单的"导入"
   - 点击"选择文件"按钮
   - 选择文件：`d:\wwwroot\database\optimize_database.sql`
   - 点击"执行"按钮
   - 等待导入完成（可能需要1-2分钟）

4. **验证结果**
   - 如果看到"导入成功"提示，说明完成
   - 如果有错误，复制错误信息联系技术支持

### 方法二：使用命令行（适合有经验的用户）

```bash
# 进入项目目录
cd d:\wwwroot

# 导入数据库优化脚本
mysql -u你的用户名 -p你的密码 数据库名 < database/optimize_database.sql
```

---

## Redis配置

### Windows系统安装Redis

1. **下载Redis**
   - 访问：https://github.com/microsoftarchive/redis/releases
   - 下载：Redis-x64-3.2.100.zip
   - 解压到：`C:\redis`

2. **启动Redis**
   - 打开命令提示符（CMD）
   - 输入：
   ```bash
   cd C:\redis
   redis-server.exe
   ```
   - 看到"Ready to accept connections"表示启动成功

3. **测试Redis**
   - 新开一个CMD窗口
   - 输入：
   ```bash
   cd C:\redis
   redis-cli.exe ping
   ```
   - 返回"PONG"表示正常

### Linux/Mac系统安装Redis

```bash
# Ubuntu/Debian
sudo apt-get install redis-server
sudo systemctl start redis

# CentOS/RHEL
sudo yum install redis
sudo systemctl start redis

# Mac
brew install redis
brew services start redis
```

### 验证Redis配置

打开文件：`d:\wwwroot\.env`
确认以下配置正确：

```ini
# 缓存配置
CACHE_DRIVER = redis
CACHE_PREFIX = woquan_

# Redis配置
REDIS_HOST = 127.0.0.1
REDIS_PORT = 6379
REDIS_PASSWORD = 
REDIS_SELECT = 0
```

如果你的Redis有密码，填写在`REDIS_PASSWORD`处。

---

## 代码部署

### 步骤1：备份原有代码（重要！）

```bash
# 复制整个项目文件夹作为备份
# Windows：右键 d:\wwwroot 文件夹 -> 复制
# 粘贴到 d:\wwwroot_backup
```

### 步骤2：确认新文件已创建

检查以下文件是否存在：

```
d:\wwwroot\
├── app\
│   ├── helper\
│   │   ├── InputValidator.php      ✅ 新增
│   │   └── FileValidator.php        ✅ 新增
│   ├── middleware\
│   │   ├── Cors.php                ✅ 新增
│   │   ├── Csrf.php                ✅ 新增
│   │   └── ExceptionHandler.php     ✅ 新增
│   ├── exception\
│   │   ├── BusinessException.php    ✅ 新增
│   │   ├── ValidationException.php   ✅ 新增
│   │   └── ExceptionHandler.php    ✅ 新增
│   └── service\
│       └── RedisCache.php          ✅ 新增
├── database\
│   └── optimize_database.sql       ✅ 新增
├── route\
│   ├── modules\                    ✅ 新增目录
│   │   ├── user.php
│   │   ├── moments.php
│   │   ├── message.php
│   │   ├── notification.php
│   │   └── frontend.php
│   └── app_modular.php            ✅ 新增
├── public\
│   ├── static\
│   │   ├── index.html             ✅ 新增
│   │   └── js\
│   │       └── app.js            ✅ 新增
│   └── websocket\
│       └── chat-server-enhanced.php ✅ 新增
└── config\
    └── exception.php              ✅ 新增
```

### 步骤3：清理缓存（重要！）

```bash
# 删除所有缓存文件
# Windows：手动删除以下文件夹
d:\wwwroot\runtime\cache\
d:\wwwroot\runtime\log\
d:\wwwroot\runtime\temp\
```

### 步骤4：设置文件权限（Linux/Mac）

```bash
# 设置runtime目录可写
chmod -R 777 d:\wwwroot\runtime

# 设置uploads目录可写
chmod -R 777 d:\wwwroot\public\uploads
```

Windows系统不需要设置权限。

---

## 测试验证

### 1. 测试网站访问

在浏览器访问：`http://你的域名/`

**检查项：**
- ✅ 网站能正常打开
- ✅ 页面显示正常
- ✅ 没有报错信息

### 2. 测试用户登录

**检查项：**
- ✅ 能正常登录
- ✅ 登录后页面跳转正确
- ✅ 用户信息显示正常

### 3. 测试发布动态

**检查项：**
- ✅ 能正常发布动态
- ✅ 图片上传正常
- ✅ 动态列表显示正常

### 4. 测试聊天功能（可选）

如果需要使用聊天功能：

**启动WebSocket服务器：**
```bash
# 打开新的CMD窗口
cd d:\wwwroot
php public/websocket/chat-server-enhanced.php
```

**检查项：**
- ✅ WebSocket服务器启动成功
- ✅ 聊天功能正常

### 5. 查看日志文件

如果遇到问题，查看日志：

```
d:\wwwroot\runtime\log\
├── error.log        # 错误日志
├── sql.log         # SQL日志
└── websocket.log   # WebSocket日志
```

---

## 常见问题

### Q1: 数据库导入失败

**问题：** phpMyAdmin导入时报错

**解决方法：**
1. 检查MySQL版本是否为8.0+
2. 确认数据库用户有足够权限
3. 尝试分批导入（删除部分SQL语句后分次导入）

### Q2: Redis连接失败

**问题：** 网站报错"Redis连接失败"

**解决方法：**
1. 确认Redis服务已启动
2. 检查`.env`文件中的Redis配置
3. 确认Redis端口6379未被占用

### Q3: 网站访问报错500

**问题：** 访问网站显示500错误

**解决方法：**
1. 查看错误日志：`runtime/log/error.log`
2. 确认PHP版本是否满足要求
3. 清理缓存：删除`runtime/cache`目录
4. 检查文件权限

### Q4: 文件上传失败

**问题：** 上传图片时报错

**解决方法：**
1. 检查`uploads`目录是否有写入权限
2. 确认PHP配置中的`upload_max_filesize`
3. 检查磁盘空间是否充足

### Q5: WebSocket无法启动

**问题：** 启动WebSocket时报错

**解决方法：**
1. 确认已安装Swoole扩展：`php -m | grep swoole`
2. 检查端口9501是否被占用
3. 确认防火墙允许该端口

---

## 回滚方案

如果遇到严重问题，可以快速回滚：

### 方法一：恢复备份

```bash
# 1. 停止Web服务器
# 2. 删除 d:\wwwroot 目录
# 3. 将 d:\wwwroot_backup 重命名为 d:\wwwroot
# 4. 重新启动Web服务器
```

### 方法二：仅回滚数据库

```bash
# 使用之前的数据库备份恢复
mysql -u用户名 -p密码 数据库名 < backup.sql
```

---

## 技术支持

如果遇到无法解决的问题：

1. **收集信息：**
   - 错误截图
   - 错误日志内容
   - 系统环境信息

2. **联系方式：**
   - 官方文档：查看项目README
   - 技术论坛：搜索相关问题
   - 开发者支持：联系技术支持团队

---

## 🎉 完成检查清单

部署完成后，请逐项检查：

- [ ] 数据库优化脚本已导入
- [ ] Redis服务已启动并配置
- [ ] 所有新文件已创建
- [ ] 缓存已清理
- [ ] 网站能正常访问
- [ ] 用户登录功能正常
- [ ] 发布动态功能正常
- [ ] 文件上传功能正常
- [ ] 没有明显的错误日志

全部勾选后，恭喜您部署成功！🎊

---

## 📞 快速命令参考

```bash
# 清理缓存
rm -rf runtime/cache/* runtime/temp/*

# 查看错误日志
tail -f runtime/log/error.log

# 重启Redis
redis-cli shutdown
redis-server

# 测试Redis连接
redis-cli ping

# 查看PHP版本
php -v

# 查看已安装的PHP扩展
php -m

# 检查文件权限
ls -la runtime/
```

---

**祝您部署顺利！如有问题，请参考上面的常见问题部分。** 🚀
