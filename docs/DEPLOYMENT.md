# 部署运维文档

## 文档概述

本文档详细描述了社交应用系统的部署和运维流程，包括环境要求、安装步骤、配置说明、监控告警、故障排查等内容。

## 目录

- [环境要求](#环境要求)
- [安装部署](#安装部署)
- [配置说明](#配置说明)
- [数据库配置](#数据库配置)
- [缓存配置](#缓存配置)
- [文件存储配置](#文件存储配置)
- [Nginx配置](#nginx配置)
- [Apache配置](#apache配置)
- [SSL证书配置](#ssl证书配置)
- [监控告警](#监控告警)
- [日志管理](#日志管理)
- [备份恢复](#备份恢复)
- [性能优化](#性能优化)
- [安全加固](#安全加固)
- [故障排查](#故障排查)
- [常见问题](#常见问题)

## 环境要求

### 服务器配置

| 配置项 | 最低要求 | 推荐配置 |
|-------|---------|---------|
| 操作系统 | Linux (CentOS 7+, Ubuntu 18.04+) | Linux (CentOS 8+, Ubuntu 20.04+) |
| CPU | 2核 | 4核+ |
| 内存 | 4GB | 8GB+ |
| 硬盘 | 50GB | 100GB+ SSD |
| 带宽 | 10Mbps | 100Mbps+ |

### 软件环境

| 软件 | 最低版本 | 推荐版本 |
|-----|---------|---------|
| PHP | 7.4 | 8.0+ |
| MySQL | 5.7 | 8.0+ |
| Nginx | 1.18 | 1.20+ |
| Redis | 5.0 | 6.0+ |
| Composer | 2.0 | 2.2+ |

### PHP扩展要求

必须安装的PHP扩展：

- bcmath
- ctype
- curl
- dom
- fileinfo
- gd
- json
- mbstring
- openssl
- pdo
- pdo_mysql
- redis
- session
- xml
- zip

检查PHP扩展：

```bash
php -m | grep -E "bcmath|ctype|curl|dom|fileinfo|gd|json|mbstring|openssl|pdo|pdo_mysql|redis|session|xml|zip"
```

## 安装部署

### 1. 下载源码

```bash
# 克隆代码仓库
git clone https://github.com/your-repo/quanzi.git
cd quanzi

# 或者下载压缩包
wget https://github.com/your-repo/quanzi/archive/master.zip
unzip master.zip
cd quanzi-master
```

### 2. 安装依赖

```bash
# 安装Composer依赖
composer install --no-dev --optimize-autoloader

# 安装Node.js依赖（如需要）
npm install
npm run build
```

### 3. 配置文件

```bash
# 复制配置文件模板
cp config/database.php.example config/database.php
cp config/cache.php.example config/cache.php

# 设置目录权限
chmod -R 755 runtime
chmod -R 755 public/uploads
chmod -R 755 public/storage
```

### 4. 数据库初始化

```bash
# 创建数据库
mysql -u root -p
CREATE DATABASE quanzi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'quanzi'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON quanzi.* TO 'quanzi'@'localhost';
FLUSH PRIVILEGES;
EXIT;

# 导入数据库结构
mysql -u quanzi -p quanzi < database/install.sql
```

### 5. 配置Web服务器

参考[Nginx配置](#nginx配置)或[Apache配置](#apache配置)章节。

### 6. 启动服务

```bash
# 启动Nginx
systemctl start nginx
systemctl enable nginx

# 启动PHP-FPM
systemctl start php-fpm
systemctl enable php-FPM

# 启动MySQL
systemctl start mysqld
systemctl enable mysqld

# 启动Redis
systemctl start redis
systemctl enable redis
```

### 7. 验证安装

访问 `http://your-domain.com`，如果看到首页则安装成功。

## 配置说明

### 应用配置

编辑 `config/app.php`：

```php
return [
    // 应用地址
    'app_host'         => 'https://your-domain.com',
    
    // 默认时区
    'default_timezone' => 'Asia/Shanghai',
    
    // 显示错误信息（生产环境请设为false）
    'show_error_msg'   => false,
];
```

### 路由配置

编辑 `config/route.php`：

```php
return [
    // 路由配置
    '__pattern__' => [
        'name' => '\w+',
    ],
];
```

### 会话配置

编辑 `config/session.php`：

```php
return [
    // session name
    'name'           => 'PHPSESSID',
    
    // session_id前缀
    'prefix'         => '',
    
    // 驱动方式 支持file memcache redis
    'type'           => 'file',
    
    // 存储连接标识 当type使用cache的时候有效
    'store'          => null,
    
    // 过期时间
    'expire'         => 1440,
    
    // 是否自动开启
    'auto_start'     => true,
];
```

## 数据库配置

编辑 `config/database.php`：

```php
return [
    // 默认使用的数据库连接配置
    'default'         => 'mysql',

    // 数据库连接配置信息
    'connections'     => [
        'mysql' => [
            // 数据库类型
            'type'            => 'mysql',
            // 服务器地址
            'hostname' => 'localhost',
            // 数据库名
            'database' => 'quanzi',
            // 用户名
            'username' => 'quanzi',
            // 密码
            'password' => 'your_password',
            // 端口
            'hostport' => '3306',
            // 数据库编码默认采用utf8
            'charset'         => 'utf8mb4',
            // 数据库排序规则
            'collation'       => 'utf8mb4_unicode_ci',
            // 数据库表前缀
            'prefix' => 'wq_',
            // 数据库连接参数
            'params'          => [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                PDO::ATTR_PERSISTENT => true,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
            // 数据库调试模式（生产环境请设为false）
            'debug'           => false,
            // 数据库部署方式:0 集中式(单一服务器),1 分布式(主从服务器)
            'deploy'          => 0,
            // 数据库读写是否分离 主从式有效
            'rw_separate'     => false,
            // 读写分离后 主服务器数量
            'master_num'      => 1,
            // 指定从服务器序号
            'slave_no'        => '',
            // 是否严格检查字段是否存在
            'fields_strict'   => true,
        ],
    ],
    // 数据库日志记录
    'log'             => [
        'level' => [],
        'type'  => 'test',
    ],
    // 数据库字段缓存
    'fields_cache'    => true,
    // 数据库表缓存
    'table_cache'     => true,
];
```

### 数据库优化配置

编辑 `my.cnf` 或 `my.ini`：

```ini
[mysqld]
# 基础配置
port = 3306
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# 连接配置
max_connections = 500
max_connect_errors = 1000

# 缓冲区配置
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_log_buffer_size = 16M

# 查询缓存
query_cache_size = 64M
query_cache_type = 1

# 慢查询日志
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 2

# 二进制日志
log_bin = /var/log/mysql/mysql-bin.log
expire_logs_days = 7
max_binlog_size = 100M
```

## 缓存配置

编辑 `config/cache.php`：

```php
return [
    // 默认缓存驱动
    'default' => 'redis',

    // 缓存连接方式配置
    'stores'  => [
        'file' => [
            // 驱动方式
            'type'       => 'File',
            // 缓存保存目录
            'path'       => '',
            // 缓存前缀
            'prefix'     => '',
            // 缓存有效期 0表示永久缓存
            'expire'     => 0,
            // 缓存标签前缀
            'tag_prefix' => 'tag:',
            // 序列化机制 例如 ['serialize', 'unserialize']
            'serialize'  => [],
        ],
        // Redis缓存
        'redis' => [
            // 驱动方式
            'type'       => 'Redis',
            // 服务器地址
            'host'       => '127.0.0.1',
            // 端口
            'port'       => 6379,
            // 密码
            'password'   => '',
            // 数据库
            'select'     => 0,
            // 缓存前缀
            'prefix'     => 'lan_',
            // 缓存有效期 0表示永久缓存
            'expire'     => 3600,
            // 序列化机制
            'serialize'  => [],
        ],
    ],
];
```

### Redis优化配置

编辑 `redis.conf`：

```conf
# 网络配置
bind 127.0.0.1
port 6379
protected-mode yes

# 内存配置
maxmemory 512mb
maxmemory-policy allkeys-lru

# 持久化配置
save 900 1
save 300 10
save 60 10000

# 日志配置
loglevel notice
logfile /var/log/redis/redis.log

# 安全配置
requirepass your_redis_password
```

## 文件存储配置

编辑 `config/filesystem.php`：

```php
return [
    // 默认磁盘
    'default' => 'public',
    
    // 磁盘配置
    'disks'   => [
        'local'  => [
            'type' => 'local',
            'root' => app()->getRuntimePath() . 'storage',
        ],
        'public' => [
            'type'       => 'local',
            'root'       => app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'storage',
            'url'        => '/storage',
            'visibility' => 'public',
        ],
        'uploads' => [
            'type'       => 'local',
            'root'       => app()->getRootPath() . 'public' . DIRECTORY_SEPARATOR . 'uploads',
            'url'        => '/uploads',
            'visibility' => 'public',
        ],
    ],
];
```

## Nginx配置

创建 `/etc/nginx/conf.d/quanzi.conf`：

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name your-domain.com www.your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name your-domain.com www.your-domain.com;
    root /var/www/quanzi/public;
    index index.php index.html;

    # SSL证书配置
    ssl_certificate /etc/ssl/certs/your-domain.com.crt;
    ssl_certificate_key /etc/ssl/private/your-domain.com.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;

    # 安全头
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "no-referrer-when-downgrade" always;

    # 日志配置
    access_log /var/log/nginx/quanzi_access.log;
    error_log /var/log/nginx/quanzi_error.log;

    # 主配置
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP处理
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php-fpm/php-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        
        # 超时设置
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout 300;
        fastcgi_read_timeout 300;
    }

    # 静态文件缓存
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }

    # 禁止访问隐藏文件
    location ~ /\. {
        deny all;
        access_log off;
        log_not_found off;
    }

    # 禁止访问敏感文件
    location ~ /(\.env|\.git|composer\.json|composer\.lock|package\.json|package-lock\.json)$ {
        deny all;
    }

    # 限制上传文件大小
    client_max_body_size 100M;
    client_body_timeout 300s;
}
```

重启Nginx：

```bash
nginx -t
systemctl restart nginx
```

## Apache配置

创建 `/etc/apache2/sites-available/quanzi.conf`：

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    ServerAlias www.your-domain.com
    Redirect permanent / https://your-domain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    ServerAlias www.your-domain.com
    DocumentRoot /var/www/quanzi/public

    # SSL证书配置
    SSLEngine on
    SSLCertificateFile /etc/ssl/certs/your-domain.com.crt
    SSLCertificateKeyFile /etc/ssl/private/your-domain.com.key
    SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite HIGH:!aNULL:!MD5

    # 日志配置
    ErrorLog ${APACHE_LOG_DIR}/quanzi_error.log
    CustomLog ${APACHE_LOG_DIR}/quanzi_access.log combined

    # 目录配置
    <Directory /var/www/quanzi/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # 重写规则
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule ^(.*)$ index.php [QSA,L]
    </Directory>

    # 安全头
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-XSS-Protection "1; mode=block"

    # 限制上传文件大小
    LimitRequestBody 104857600
</VirtualHost>
```

启用站点：

```bash
a2ensite quanzi.conf
a2enmod rewrite
a2enmod ssl
a2enmod headers
systemctl restart apache2
```

## SSL证书配置

### 使用Let's Encrypt免费证书

```bash
# 安装certbot
yum install certbot python3-certbot-nginx  # CentOS
apt install certbot python3-certbot-nginx  # Ubuntu

# 获取证书
certbot --nginx -d your-domain.com -d www.your-domain.com

# 自动续期
certbot renew --dry-run
```

### 使用自签名证书（仅用于测试）

```bash
# 生成私钥
openssl genrsa -out your-domain.com.key 2048

# 生成证书签名请求
openssl req -new -key your-domain.com.key -out your-domain.com.csr

# 生成自签名证书
openssl x509 -req -days 365 -in your-domain.com.csr -signkey your-domain.com.key -out your-domain.com.crt
```

## 监控告警

### 系统监控

使用Prometheus + Grafana进行系统监控：

```yaml
# prometheus.yml
global:
  scrape_interval: 15s

scrape_configs:
  - job_name: 'node_exporter'
    static_configs:
      - targets: ['localhost:9100']

  - job_name: 'mysql_exporter'
    static_configs:
      - targets: ['localhost:9104']

  - job_name: 'redis_exporter'
    static_configs:
      - targets: ['localhost:9121']
```

### 应用监控

使用ThinkPHP的日志和监控功能：

```php
// 开启SQL日志
'debug' => true,

// 记录慢查询
'slow_query_log' => true,
'slow_query_time' => 2,
```

### 告警配置

使用Alertmanager配置告警规则：

```yaml
groups:
  - name: alert_rules
    rules:
      - alert: HighCPUUsage
        expr: 100 - (avg by(instance) (irate(node_cpu_seconds_total{mode="idle"}[5m])) * 100) > 80
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High CPU usage detected"
          description: "CPU usage is above 80% for more than 5 minutes"

      - alert: HighMemoryUsage
        expr: (1 - (node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes)) * 100 > 80
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High memory usage detected"
          description: "Memory usage is above 80% for more than 5 minutes"
```

## 日志管理

### 日志位置

| 日志类型 | 位置 | 说明 |
|---------|------|------|
| 应用日志 | `runtime/log/` | 应用运行日志 |
| 错误日志 | `runtime/log/error.log` | 错误日志 |
| SQL日志 | `runtime/log/sql.log` | SQL查询日志 |
| Nginx日志 | `/var/log/nginx/` | Web服务器日志 |
| MySQL日志 | `/var/log/mysql/` | 数据库日志 |
| Redis日志 | `/var/log/redis/` | 缓存日志 |

### 日志轮转

创建 `/etc/logrotate.d/quanzi`：

```conf
/var/www/quanzi/runtime/log/*.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    create 0644 www-data www-data
    sharedscripts
    postrotate
        systemctl reload php-fpm
    endscript
}
```

### 日志分析

使用ELK Stack进行日志分析：

```bash
# 安装Elasticsearch
yum install elasticsearch

# 安装Logstash
yum install logstash

# 安装Kibana
yum install kibana
```

## 备份恢复

### 数据库备份

创建备份脚本 `/usr/local/bin/backup_db.sh`：

```bash
#!/bin/bash

BACKUP_DIR="/var/backups/mysql"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="quanzi"
DB_USER="quanzi"
DB_PASS="your_password"

mkdir -p $BACKUP_DIR

# 全量备份
mysqldump -u$DB_USER -p$DB_PASS $DB_NAME | gzip > $BACKUP_DIR/${DB_NAME}_${DATE}.sql.gz

# 保留最近30天的备份
find $BACKUP_DIR -name "${DB_NAME}_*.sql.gz" -mtime +30 -delete

echo "Database backup completed: ${DB_NAME}_${DATE}.sql.gz"
```

设置定时任务：

```bash
# 添加到crontab
crontab -e

# 每天凌晨2点执行备份
0 2 * * * /usr/local/bin/backup_db.sh
```

### 文件备份

创建备份脚本 `/usr/local/bin/backup_files.sh`：

```bash
#!/bin/bash

BACKUP_DIR="/var/backups/files"
SOURCE_DIR="/var/www/quanzi/public/uploads"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p $BACKUP_DIR

# 备份上传文件
tar -czf $BACKUP_DIR/uploads_${DATE}.tar.gz $SOURCE_DIR

# 保留最近30天的备份
find $BACKUP_DIR -name "uploads_*.tar.gz" -mtime +30 -delete

echo "Files backup completed: uploads_${DATE}.tar.gz"
```

### 数据库恢复

```bash
# 解压备份文件
gunzip /var/backups/mysql/quanzi_20260211_020000.sql.gz

# 恢复数据库
mysql -u quanzi -p quanzi < /var/backups/mysql/quanzi_20260211_020000.sql
```

### 文件恢复

```bash
# 解压备份文件
tar -xzf /var/backups/files/uploads_20260211_020000.tar.gz -C /var/www/quanzi/public/
```

## 性能优化

### PHP优化

编辑 `php.ini`：

```ini
# 内存限制
memory_limit = 256M

# 执行时间
max_execution_time = 300

# 上传文件大小
upload_max_filesize = 100M
post_max_size = 100M

# OPcache配置
opcache.enable = 1
opcache.memory_consumption = 128
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 10000
opcache.revalidate_freq = 60
opcache.fast_shutdown = 1
```

### Nginx优化

编辑 `nginx.conf`：

```nginx
# 工作进程数
worker_processes auto;

# 每个工作进程的最大连接数
events {
    worker_connections 2048;
    use epoll;
}

# 缓冲区配置
client_body_buffer_size 128k;
client_header_buffer_size 1k;
large_client_header_buffers 4 4k;
output_buffers 1 32k;
postpone_output 1460;

# 启用Gzip压缩
gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss application/json;
```

### MySQL优化

参考[数据库配置](#数据库配置)章节。

### Redis优化

参考[缓存配置](#缓存配置)章节。

### 应用层优化

1. **使用缓存**

```php
// 使用Redis缓存
Cache::store('redis')->set('key', 'value', 3600);
$value = Cache::store('redis')->get('key');
```

2. **数据库查询优化**

```php
// 使用索引
Db::table('moments')->where('user_id', $userId)->where('status', 1)->select();

// 使用分页
Db::table('moments')->where('status', 1)->paginate(20);
```

3. **静态资源CDN**

将静态资源上传到CDN，减少服务器压力。

## 安全加固

### 1. 文件权限

```bash
# 设置正确的文件权限
chown -R www-data:www-data /var/www/quanzi
chmod -R 755 /var/www/quanzi
chmod -R 777 /var/www/quanzi/runtime
chmod -R 777 /var/www/quanzi/public/uploads
chmod -R 777 /var/www/quanzi/public/storage
```

### 2. 禁止访问敏感文件

在Nginx配置中添加：

```nginx
location ~ /(\.env|\.git|composer\.json|composer\.lock)$ {
    deny all;
}
```

### 3. 防止SQL注入

使用参数化查询：

```php
Db::table('users')->where('id', $id)->find();
```

### 4. 防止XSS攻击

对用户输入进行过滤：

```php
$content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
```

### 5. 防止CSRF攻击

使用CSRF令牌：

```php
// 在表单中添加CSRF令牌
<input type="hidden" name="__token__" value="{:token()}">

// 验证CSRF令牌
if (!$request->checkToken('__token__', $request->post())) {
    return json(['code' => 0, 'msg' => 'CSRF token验证失败']);
}
```

### 6. 配置防火墙

```bash
# 安装firewalld
yum install firewalld

# 开放必要端口
firewall-cmd --permanent --add-service=http
firewall-cmd --permanent --add-service=https
firewall-cmd --permanent --add-port=3306/tcp
firewall-cmd --permanent --add-port=6379/tcp
firewall-cmd --reload
```

### 7. 配置fail2ban

```bash
# 安装fail2ban
yum install fail2ban

# 配置jail.local
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5

[nginx-http-auth]
enabled = true
```

## 故障排查

### 常见问题

#### 1. 页面显示500错误

**原因**：PHP代码错误或配置错误

**解决方法**：

```bash
# 查看PHP错误日志
tail -f /var/log/php-fpm/error.log

# 查看Nginx错误日志
tail -f /var/log/nginx/quanzi_error.log

# 开启错误显示
# 编辑 config/app.php
'show_error_msg' => true,
```

#### 2. 数据库连接失败

**原因**：数据库配置错误或数据库服务未启动

**解决方法**：

```bash
# 检查MySQL服务状态
systemctl status mysqld

# 检查数据库配置
cat config/database.php

# 测试数据库连接
mysql -u quanzi -p quanzi
```

#### 3. 文件上传失败

**原因**：文件权限不足或上传大小限制

**解决方法**：

```bash
# 检查文件权限
ls -la /var/www/quanzi/public/uploads

# 修改文件权限
chmod -R 777 /var/www/quanzi/public/uploads

# 检查PHP上传配置
php -i | grep upload
```

#### 4. 缓存不生效

**原因**：Redis服务未启动或配置错误

**解决方法**：

```bash
# 检查Redis服务状态
systemctl status redis

# 测试Redis连接
redis-cli ping

# 清空缓存
redis-cli FLUSHALL
```

#### 5. 页面加载缓慢

**原因**：数据库查询慢、缓存未启用、服务器资源不足

**解决方法**：

```bash
# 检查服务器资源
top
free -m
df -h

# 检查慢查询
tail -f /var/log/mysql/slow.log

# 启用缓存
# 编辑 config/cache.php
'default' => 'redis',
```

### 日志分析

```bash
# 查看应用日志
tail -f runtime/log/$(date +%Y%m%d).log

# 查看错误日志
tail -f runtime/log/error.log

# 查看SQL日志
tail -f runtime/log/sql.log

# 查看Nginx访问日志
tail -f /var/log/nginx/quanzi_access.log

# 查看Nginx错误日志
tail -f /var/log/nginx/quanzi_error.log
```

## 常见问题

### Q1: 如何升级系统？

A: 按照以下步骤升级系统：

```bash
# 备份数据
/usr/local/bin/backup_db.sh
/usr/local/bin/backup_files.sh

# 拉取最新代码
git pull origin master

# 更新依赖
composer update

# 运行数据库迁移
php think migrate:run

# 清空缓存
php think clear
```

### Q2: 如何重置管理员密码？

A: 使用以下命令重置管理员密码：

```bash
# 进入数据库
mysql -u quanzi -p quanzi

# 更新管理员密码（密码为：123456）
UPDATE wq_admin SET password = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' WHERE username = 'admin';
```

### Q3: 如何清理日志？

A: 使用以下命令清理日志：

```bash
# 清理应用日志
rm -rf runtime/log/*.log

# 清理Nginx日志
rm -rf /var/log/nginx/quanzi_*.log

# 清理MySQL日志
rm -rf /var/log/mysql/*.log
```

### Q4: 如何优化数据库？

A: 使用以下命令优化数据库：

```bash
# 分析表
mysql -u quanzi -p quanzi -e "ANALYZE TABLE wq_moments;"

# 优化表
mysql -u quanzi -p quanzi -e "OPTIMIZE TABLE wq_moments;"

# 修复表
mysql -u quanzi -p quanzi -e "REPAIR TABLE wq_moments;"
```

### Q5: 如何监控服务器性能？

A: 使用以下工具监控服务器性能：

```bash
# 安装htop
yum install htop

# 安装iotop
yum install iotop

# 安装nethogs
yum install nethogs

# 使用htop查看系统资源
htop

# 使用iotop查看IO使用情况
iotop

# 使用nethogs查看网络使用情况
nethogs
```

## 版本历史

| 版本 | 日期 | 更新内容 |
|-----|------|---------|
| v1.0.0 | 2026-02-11 | 初始版本 |

---

**© 2026 社交应用系统 - 部署运维文档**