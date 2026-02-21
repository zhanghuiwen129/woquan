# 授权系统使用说明

## 概述

本系统采用增强版授权验证机制，支持以下功能：
- 授权码加密签名
- 域名/IP绑定
- 在线验证能力
- 功能权限控制
- 验证次数追踪
- 缓存优化性能

## 快速开始

### 1. 配置授权

复制 `.env.example` 为 `.env`，并配置以下参数：

```env
# 授权码（必填，从后台获取）
LICENSE_KEY = your-license-key-here

# 授权密钥（用于生成签名，请勿泄露）
LICENSE_SECRET_KEY = WOQUAN_LICENSE_SECRET_KEY_2026

# 是否启用在线验证（true=启用，false=禁用）
LICENSE_ONLINE_VERIFY = false

# 在线验证服务器地址（启用在线验证时填写）
LICENSE_VERIFY_URL = https://api.yourdomain.com/verify
```

### 2. 生成授权码

登录后台管理，进入「授权管理」页面，可以：

- **单个添加**：手动添加授权，指定域名、IP、有效期等
- **批量生成**：批量生成授权码，提高效率

### 3. 应用授权

将生成的授权码配置到 `.env` 文件的 `LICENSE_KEY` 参数中。

## 授权码格式

授权码采用以下格式：
```
XXXXXXXX-XXXXXXXX
```

- 前16位：基础授权码（随机生成）
- 后8位：签名（基于密钥生成）

## 授权验证机制

### 本地验证

系统默认使用本地验证，检查以下内容：

1. **授权码存在性**：验证授权码是否在数据库中存在
2. **签名验证**：验证授权码签名是否正确
3. **有效期验证**：检查授权是否过期
4. **状态验证**：检查授权是否被禁用
5. **域名绑定**：验证当前域名是否在授权域名列表中
6. **IP绑定**：验证当前服务器IP是否在授权IP列表中

### 在线验证

启用在线验证后，系统会向授权服务器发送验证请求：

```php
POST https://api.yourdomain.com/verify
Headers:
  X-Secret-Key: YOUR_SECRET_KEY
Body:
  {
    "license_code": "XXXXXXXX-XXXXXXXX",
    "domain": "example.com",
    "ip": "127.0.0.1",
    "timestamp": 1234567890
  }
```

### 缓存机制

授权验证结果会被缓存1小时，减少数据库查询：
```php
Cache::set('license_info_' . md5($licenseCode), $result, 3600);
```

## 功能权限控制

授权可以控制特定功能的访问：

### 1. 设置功能权限

在生成授权时，可以指定允许的功能：

```json
{
  "features": ["articles", "comments", "chat", "vip"]
}
```

### 2. 检查功能权限

在代码中检查功能权限：

```php
if (LicenseService::checkFeature('vip')) {
    // 允许访问VIP功能
} else {
    // 功能未授权
}
```

## 中间件使用

### 应用授权验证中间件

在 `config/middleware.php` 中已配置：

```php
'alias' => [
    'LicenseAuth' => app\middleware\LicenseAuth::class,
],
```

### 豁免验证的路由

登录相关路由默认不验证授权：

```php
protected $config = [
    'except' => [
        'admin/login/index',
        'admin/login/login',
        'admin/login/logout',
        'admin/login/captcha',
    ]
];
```

## 授权管理后台

### 授权列表

查看所有授权信息，包括：
- 授权编号
- 关联软件
- 域名/IP
- 有效期
- 状态
- 验证次数

### 添加授权

填写以下信息：
- 授权编号（自动生成）
- 软件选择
- 授权域名（可选，多个用逗号分隔）
- 服务器IP（可选，多个用逗号分隔）
- 开始时间
- 结束时间（留空表示永久）
- 功能权限（可选，JSON格式）

### 批量生成

批量生成授权码，支持：
- 指定生成数量（1-1000）
- 统一设置域名/IP
- 统一设置有效期
- 统一设置功能权限

### 授权统计

查看授权统计信息：
- 总授权数
- 有效授权数
- 无效授权数
- 永久授权数
- 已过期授权数
- 即将过期授权数
- 按软件分类统计
- 最近7/30天新增授权

### 导出授权

导出授权信息为CSV文件，包含：
- 授权编号
- 软件名称
- 开始时间
- 结束时间
- 状态
- 创建时间

## 安全建议

### 1. 保护授权密钥

- 不要将 `LICENSE_SECRET_KEY` 提交到版本控制系统
- 定期更换密钥
- 使用强密钥（至少32位）

### 2. 使用在线验证

- 启用在线验证可以实时监控授权使用情况
- 发现异常可以立即禁用授权

### 3. 限制授权范围

- 尽量使用域名绑定，限制授权只能在指定域名使用
- 对于内网部署，使用IP绑定

### 4. 定期审查授权

- 查看授权统计，发现异常及时处理
- 定期清理过期授权

## 常见问题

### Q1: 授权码无效怎么办？

A: 检查以下几点：
1. 授权码是否正确复制
2. 授权是否已过期
3. 域名/IP是否匹配
4. 授权是否被禁用

### Q2: 如何更换授权密钥？

A: 
1. 修改 `app/service/LicenseService.php` 中的 `$secretKey`
2. 重新生成所有授权码
3. 更新 `.env.example` 文件

### Q3: 如何禁用在线验证？

A: 在 `.env` 文件中设置：
```env
LICENSE_ONLINE_VERIFY = false
```

### Q4: 授权验证失败会怎样？

A: 
- AJAX请求：返回401/403状态码和错误信息
- 普通请求：显示错误页面

### Q5: 如何添加新的功能权限？

A: 
1. 在 `app/service/LicenseService.php` 的 `checkFeature()` 方法中添加
2. 在生成授权时，在 `features` 字段中指定

## 技术支持

如有问题，请联系技术支持：
- 邮箱：support@example.com
- 官网：https://example.com
- 文档：https://docs.example.com
