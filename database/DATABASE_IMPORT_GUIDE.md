# 📖 数据库优化 - 分步导入指南

## 🎯 问题说明
如果 `optimize_database.sql` 文件太大无法导入，请使用本指南中的**分步导入方法**。

---

## 📋 分步导入步骤

### 第1步：导入moments表索引
1. 打开 phpMyAdmin
2. 选择你的数据库
3. 点击"导入"按钮
4. 选择文件：`optimize_part1_indexes.sql`
5. 点击"执行"
6. 等待完成（约10-20秒）

### 第2步：导入comments表索引
1. 重复上述步骤
2. 选择文件：`optimize_part2_comments.sql`
3. 点击"执行"
4. 等待完成

### 第3步：导入likes和follows表索引
1. 重复上述步骤
2. 选择文件：`optimize_part3_likes_follows.sql`
3. 点击"执行"
4. 等待完成

### 第4步：导入user、messages、notifications表索引
1. 重复上述步骤
2. 选择文件：`optimize_part4_user_messages.sql`
3. 点击"执行"
4. 等待完成

### 第5步：导入其他表索引
1. 重复上述步骤
2. 选择文件：`optimize_part5_other_tables.sql`
3. 点击"执行"
4. 等待完成

---

## ✅ 验证导入成功

导入完成后，检查以下内容：

### 方法一：查看表结构
1. 在phpMyAdmin中，点击任意一个表（如：moments）
2. 点击"结构"标签
3. 查看是否有新增的索引（如：idx_user_status_create）

### 方法二：执行SQL查询
在phpMyAdmin的"SQL"标签中，执行：

```sql
-- 检查moments表的索引
SHOW INDEX FROM moments;

-- 检查comments表的索引
SHOW INDEX FROM comments;

-- 检查likes表的索引
SHOW INDEX FROM likes;
```

如果看到新增的索引，说明导入成功！

---

## ⚠️ 常见错误及解决方法

### 错误1：Duplicate key name
**错误信息：** `Duplicate key name 'idx_user_status_create'`

**原因：** 索引已存在

**解决方法：**
- 这个错误可以忽略，说明索引已经存在
- 继续导入其他文件

### 错误2：Table doesn't exist
**错误信息：** `Table 'xxx' doesn't exist`

**原因：** 表不存在

**解决方法：**
- 检查数据库名称是否正确
- 确认表是否真的存在

### 错误3：Access denied
**错误信息：** `Access denied for user 'xxx'@'xxx'`

**原因：** 数据库用户权限不足

**解决方法：**
- 联系服务器管理员
- 或使用有足够权限的数据库用户

---

## 🚀 导入完成后的下一步

数据库优化完成后，继续以下步骤：

1. **启动Redis服务**（参考主部署指南）
2. **清理缓存**：删除 `runtime/cache` 和 `runtime/temp` 目录
3. **测试网站**：访问网站，检查是否正常运行

---

## 📞 需要帮助？

如果遇到其他问题：

1. **记录错误信息**：复制完整的错误提示
2. **查看日志**：`runtime/log/sql.log`
3. **联系技术支持**：提供错误信息和日志内容

---

**分步导入比一次性导入更安全，即使出错也容易定位问题！** 🎉
