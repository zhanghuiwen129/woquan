# 🚀 本地环境性能优化指南

## 🔍 常见慢的原因

### 1. 调试模式开启（最常见！）
**影响：** 调试模式会记录大量日志，严重影响性能
**解决：** 已关闭 `APP_DEBUG = false`

### 2. 数据库连接慢
**影响：** MySQL连接配置不当
**解决：** 优化数据库配置

### 3. 缓存未生效
**影响：** 每次都重新计算
**解决：** 确保缓存正常工作

### 4. 文件缓存过多
**影响：** 读取缓存文件慢
**解决：** 定期清理缓存

---

## ✅ 已完成的优化

1. ✅ 关闭调试模式（`APP_DEBUG = false`）
2. ✅ 清理缓存目录
3. ✅ 使用文件缓存（不依赖Redis）

---

## 🔧 进一步优化建议

### 优化1：数据库连接优化

检查 `.env` 中的数据库配置：

```ini
# 数据库配置
DB_TYPE = mysql
DATABASE_HOSTNAME = localhost
DATABASE_HOSTPORT = 3306
DATABASE_DATABASE = quansns
DATABASE_USERNAME = quansns
DATABASE_PASSWORD = FLwjGhCWKmFXGtLy
DATABASE_PREFIX = sns_
```

**优化建议：**
- ✅ 使用 `localhost` 而不是 `127.0.0.1`（在某些系统更快）
- ✅ 确保MySQL服务正常运行
- ✅ 检查数据库连接数是否过多

### 优化2：启用OPcache（PHP缓存）

编辑 `php.ini` 文件，添加或修改：

```ini
; OPcache配置
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
opcache.validate_timestamps=1
```

**重启Web服务器后生效。**

### 优化3：清理过期缓存

定期清理缓存目录：

```bash
# Windows PowerShell
Remove-Item -Path "d:\wwwroot\runtime\cache" -Recurse -Force
Remove-Item -Path "d:\wwwroot\runtime\temp" -Recurse -Force

# Linux/Mac
rm -rf runtime/cache/*
rm -rf runtime/temp/*
```

### 优化4：检查数据库查询

查看慢查询日志：

```sql
-- 查看慢查询配置
SHOW VARIABLES LIKE 'slow_query%';

-- 启用慢查询日志（如果未启用）
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 2;
```

### 优化5：优化数据库连接池

如果使用连接池，确保配置合理：

```ini
# 数据库连接池配置
DB_CONNECTION_POOL = true
DB_MAX_CONNECTIONS = 10
DB_MIN_CONNECTIONS = 2
```

---

## 📊 性能测试

### 测试1：页面加载时间

在浏览器中：
1. 打开开发者工具（F12）
2. 切换到 "Network" 标签
3. 刷新页面
4. 查看 "DOMContentLoaded" 和 "Load" 时间

**正常范围：**
- 首页：1-3秒
- 内页：0.5-2秒

### 测试2：数据库查询时间

查看日志文件：
```
d:\wwwroot\runtime\log\20260314.log
```

如果查询时间过长，需要优化。

---

## 🎯 常见问题解决

### 问题1：首次加载慢，后续正常
**原因：** 缓存未预热
**解决：** 正常现象，第二次访问会快很多

### 问题2：每次都慢
**原因：** 缓存未生效
**解决：** 检查 `runtime/cache` 目录权限

### 问题3：特定页面慢
**原因：** 该页面查询复杂
**解决：** 查看该页面的控制器代码，优化查询

---

## 🚀 快速优化清单

- [x] 关闭调试模式
- [x] 清理缓存
- [ ] 启用OPcache
- [ ] 检查数据库连接
- [ ] 优化数据库查询
- [ ] 定期清理缓存

---

## 💡 额外建议

### 1. 使用本地开发服务器
如果使用Apache/Nginx，可以考虑使用PHP内置服务器：

```bash
# 启动PHP内置服务器
php -S localhost:8000 -t public
```

### 2. 使用浏览器缓存
在浏览器中禁用缓存进行开发，但生产环境启用缓存。

### 3. 使用CDN（生产环境）
生产环境建议使用CDN加速静态资源。

---

## 📞 如果还是很慢

如果以上优化后仍然很慢，请提供：

1. 浏览器开发者工具的Network截图
2. 日志文件内容：`runtime/log/20260314.log`
3. 数据库慢查询日志
4. 服务器配置信息

---

**关闭调试模式后，性能应该有显著提升！** 🚀
