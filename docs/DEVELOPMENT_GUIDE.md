# 开发规范文档

## 文档概述

本文档详细描述了社交应用系统的开发规范，包括代码规范、命名规范、注释规范、版本控制规范、测试规范等内容。所有开发人员必须遵守本规范，以保证代码质量和可维护性。

## 目录

- [代码规范](#代码规范)
- [命名规范](#命名规范)
- [注释规范](#注释规范)
- [目录结构规范](#目录结构规范)
- [MVC开发规范](#mvc开发规范)
- [数据库操作规范](#数据库操作规范)
- [API开发规范](#api开发规范)
- [前端开发规范](#前端开发规范)
- [安全开发规范](#安全开发规范)
- [性能优化规范](#性能优化规范)
- [版本控制规范](#版本控制规范)
- [测试规范](#测试规范)
- [文档规范](#文档规范)

## 代码规范

### PHP代码规范

#### 1. 基本格式

- 使用4个空格缩进，不使用Tab
- 每行代码长度不超过120字符
- 文件末尾保留一个空行
- 文件编码使用UTF-8

```php
<?php
declare (strict_types = 1);

namespace app\controller;

use think\facade\Db;

class UserController extends BaseController
{
    public function index()
    {
        $users = Db::name('user')->select();
        return json($users);
    }
}
```

#### 2. 命名空间和use声明

- 命名空间与文件路径一致
- use声明按字母顺序排列
- 每个use声明单独一行

```php
<?php
namespace app\controller;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;
```

#### 3. 类定义

- 类名使用大驼峰命名法（PascalCase）
- 类名与文件名一致
- 类属性和方法使用小驼峰命名法（camelCase）
- 访问修饰符必须显式声明

```php
class UserService
{
    private $cache;
    protected $db;
    public $config;

    public function __construct()
    {
        $this->cache = Cache::store('redis');
        $this->db = Db::connect();
    }

    public function getUser($id)
    {
        return $this->db->name('user')->where('id', $id)->find();
    }
}
```

#### 4. 方法定义

- 方法名使用小驼峰命名法
- 参数之间用逗号和空格分隔
- 默认参数放在最后

```php
public function getUserList($page = 1, $pageSize = 20, $status = 1)
{
    return Db::name('user')
        ->where('status', $status)
        ->page($page, $pageSize)
        ->select();
}
```

#### 5. 控制结构

- if、for、while等关键字后加空格
- 左大括号不换行
- 右大括号独占一行

```php
if ($condition) {
    $result = true;
} elseif ($otherCondition) {
    $result = false;
} else {
    $result = null;
}

foreach ($items as $item) {
    echo $item;
}

for ($i = 0; $i < 10; $i++) {
    echo $i;
}

while ($condition) {
    $condition = false;
}
```

#### 6. 数组定义

- 短数组语法 `[]`
- 多行数组时，每个元素独占一行

```php
$users = [
    'id' => 1,
    'username' => 'admin',
    'nickname' => '管理员',
    'status' => 1,
];

$items = [
    ['id' => 1, 'name' => 'Item 1'],
    ['id' => 2, 'name' => 'Item 2'],
    ['id' => 3, 'name' => 'Item 3'],
];
```

#### 7. 字符串

- 优先使用单引号
- 包含变量或转义字符时使用双引号
- 长字符串使用HEREDOC或NOWDOC

```php
$name = 'John';
$message = "Hello, {$name}!";

$sql = <<<SQL
SELECT * FROM user WHERE status = 1
SQL;
```

#### 8. 常量定义

- 使用 `const` 定义类常量
- 使用 `define()` 定义全局常量
- 常量名使用全大写和下划线

```php
class UserService
{
    const CACHE_KEY_PREFIX = 'user_';
    const CACHE_EXPIRE = 3600;
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 0;
}

define('APP_NAME', 'Quanzi');
define('APP_VERSION', '1.0.0');
```

### JavaScript代码规范

#### 1. 基本格式

- 使用2个空格缩进
- 使用单引号
- 语句末尾加分号

```javascript
function getUser(id) {
    return fetch(`/api/user/${id}`)
        .then(response => response.json())
        .then(data => {
            return data;
        });
}
```

#### 2. 变量声明

- 优先使用 `const`，其次使用 `let`
- 不使用 `var`

```javascript
const API_URL = 'https://api.example.com';
let currentPage = 1;
const users = [];
```

#### 3. 函数定义

- 使用箭头函数
- 函数名使用小驼峰命名法

```javascript
const getUserList = (page, pageSize) => {
    return fetch(`/api/users?page=${page}&pageSize=${pageSize}`)
        .then(response => response.json());
};

const formatDate = (timestamp) => {
    const date = new Date(timestamp);
    return date.toLocaleDateString();
};
```

#### 4. 对象和数组

- 使用对象字面量
- 使用展开运算符

```javascript
const user = {
    id: 1,
    username: 'admin',
    nickname: '管理员'
};

const newUser = { ...user, status: 1 };
const allUsers = [...users, newUser];
```

### CSS代码规范

#### 1. 基本格式

- 使用2个空格缩进
- 选择器独占一行
- 属性独占一行

```css
.user-card {
    padding: 20px;
    margin-bottom: 10px;
    border-radius: 8px;
    background-color: #fff;
}

.user-card .avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
}
```

#### 2. 命名规范

- 使用小写字母和连字符（kebab-case）
- 使用有意义的类名

```css
.user-profile {
    /* ... */
}

.user-avatar {
    /* ... */
}

.user-name {
    /* ... */
}
```

#### 3. 属性顺序

- 定位属性
- 盒模型属性
- 字体属性
- 背景属性
- 其他属性

```css
.element {
    /* 定位 */
    position: relative;
    top: 0;
    left: 0;
    z-index: 1;

    /* 盒模型 */
    display: flex;
    width: 100%;
    height: 100px;
    padding: 10px;
    margin: 0;
    border: 1px solid #ccc;
    border-radius: 4px;

    /* 字体 */
    font-size: 14px;
    font-weight: bold;
    color: #333;
    text-align: center;

    /* 背景 */
    background-color: #fff;
    background-image: url('bg.png');

    /* 其他 */
    opacity: 0.9;
    transition: all 0.3s;
}
```

## 命名规范

### 1. 文件命名

| 类型 | 命名规范 | 示例 |
|-----|---------|------|
| 控制器 | 大驼峰 + Controller.php | UserController.php |
| 模型 | 大驼峰 + .php | User.php |
| 视图 | 小写 + 下划线 | user_list.html |
| 配置 | 小写 + 下划线 + .php | database.php |
| 中间件 | 大驼峰 + .php | Auth.php |
| JavaScript | 小写 + 下划线 + .js | user_list.js |
| CSS | 小写 + 下划线 + .css | user_list.css |

### 2. 变量命名

| 类型 | 命名规范 | 示例 |
|-----|---------|------|
| 普通变量 | 小驼峰 | $userName, $userId |
| 常量 | 全大写 + 下划线 | USER_STATUS_ACTIVE |
| 私有属性 | 小驼峰 + 下划线前缀 | $_cache |
| 静态属性 | 小驼峰 | $instance |

### 3. 函数和方法命名

| 类型 | 命名规范 | 示例 |
|-----|---------|------|
| 普通方法 | 小驼峰 | getUser(), saveUser() |
| 私有方法 | 小驼峰 + 下划线前缀 | _validateUser() |
| 静态方法 | 小驼峰 | getInstance() |
| 魔术方法 | 双下划线前缀 | __construct(), __call() |

### 4. 数据库命名

| 类型 | 命名规范 | 示例 |
|-----|---------|------|
| 表名 | 小写 + 下划线 + 复数 | users, user_profiles |
| 字段名 | 小写 + 下划线 | user_id, user_name |
| 主键 | id | id |
| 外键 | 表名_id | user_id, moment_id |
| 时间字段 | _time | create_time, update_time |
| 状态字段 | status, is_ | status, is_active |

### 5. API命名

| 类型 | 命名规范 | 示例 |
|-----|---------|------|
| URL路径 | 小写 + 下划线 | /api/user/list |
| 查询参数 | 小写 + 下划线 | page, page_size, user_id |
| 响应字段 | 小写 + 下划线 | user_id, user_name |

## 注释规范

### 1. 文件注释

每个PHP文件必须包含文件注释：

```php
<?php
/**
 * 用户控制器
 *
 * @author System
 * @version 1.0.0
 * @date 2026-02-11
 */
namespace app\controller;
```

### 2. 类注释

每个类必须包含类注释：

```php
/**
 * 用户服务类
 *
 * 提供用户相关的业务逻辑处理
 *
 * @author System
 * @version 1.0.0
 */
class UserService
{
    // ...
}
```

### 3. 方法注释

每个公共方法必须包含方法注释：

```php
/**
 * 获取用户列表
 *
 * @param int $page 页码
 * @param int $pageSize 每页数量
 * @param int $status 状态
 * @return array 用户列表
 */
public function getUserList($page = 1, $pageSize = 20, $status = 1)
{
    // ...
}
```

### 4. 属性注释

每个类属性必须包含属性注释：

```php
/**
 * 缓存实例
 * @var Cache
 */
private $cache;

/**
 * 用户状态：活跃
 */
const STATUS_ACTIVE = 1;
```

### 5. 行内注释

复杂的逻辑必须添加行内注释：

```php
// 获取用户关注的好友列表
$followingIds = Db::name('follows')
    ->where('follower_id', $currentUserId)
    ->where('status', 1)
    ->column('following_id');

// 过滤掉隐藏的动态
$hiddenMomentIds = Db::name('hidden_moments')
    ->where('user_id', $currentUserId)
    ->column('moment_id');
```

### 6. JavaScript注释

```javascript
/**
 * 获取用户列表
 * @param {number} page - 页码
 * @param {number} pageSize - 每页数量
 * @returns {Promise<Array>} 用户列表
 */
const getUserList = (page, pageSize) => {
    return fetch(`/api/users?page=${page}&pageSize=${pageSize}`)
        .then(response => response.json());
};
```

### 7. CSS注释

```css
/* 用户卡片样式 */
.user-card {
    padding: 20px;
}

/* 头像样式 */
.user-avatar {
    width: 50px;
    height: 50px;
}
```

## 目录结构规范

### 标准目录结构

```
quanzi/
├── app/                    # 应用目录
│   ├── controller/         # 控制器目录
│   │   ├── admin/          # 后台控制器
│   │   ├── api/            # API控制器
│   │   └── BaseController.php
│   ├── model/              # 模型目录
│   ├── middleware/         # 中间件目录
│   ├── validate/           # 验证器目录
│   ├── service/            # 服务层目录
│   └── common/             # 公共类目录
├── config/                 # 配置文件目录
│   ├── app.php
│   ├── database.php
│   ├── cache.php
│   └── ...
├── database/               # 数据库目录
│   ├── migrations/         # 数据库迁移文件
│   └── install.sql         # 安装SQL文件
├── public/                 # Web根目录
│   ├── index.php           # 入口文件
│   ├── static/             # 静态资源
│   │   ├── css/
│   │   ├── js/
│   │   └── images/
│   ├── uploads/            # 上传文件
│   └── storage/            # 存储文件
├── route/                  # 路由目录
│   └── app.php
├── runtime/                # 运行时目录
│   ├── cache/              # 缓存目录
│   ├── log/                # 日志目录
│   └── temp/               # 临时目录
├── vendor/                 # Composer依赖
├── docs/                   # 文档目录
├── tests/                  # 测试目录
├── composer.json           # Composer配置
└── README.md               # 项目说明
```

## MVC开发规范

### 1. 控制器规范

#### 控制器职责

- 接收请求参数
- 调用服务层处理业务逻辑
- 返回响应结果

#### 控制器示例

```php
<?php
namespace app\controller;

use think\facade\Request;
use app\service\UserService;

/**
 * 用户控制器
 */
class UserController extends BaseController
{
    private $userService;

    public function __construct()
    {
        parent::__construct();
        $this->userService = new UserService();
    }

    /**
     * 获取用户列表
     */
    public function list()
    {
        $page = Request::param('page', 1);
        $pageSize = Request::param('page_size', 20);
        
        $result = $this->userService->getUserList($page, $pageSize);
        
        return json($result);
    }

    /**
     * 获取用户详情
     */
    public function detail()
    {
        $id = Request::param('id');
        
        $result = $this->userService->getUserDetail($id);
        
        return json($result);
    }
}
```

### 2. 模型规范

#### 模型职责

- 定义数据表结构
- 提供数据访问方法
- 处理数据验证

#### 模型示例

```php
<?php
namespace app\model;

use think\Model;

/**
 * 用户模型
 */
class User extends Model
{
    protected $name = 'user';
    protected $autoWriteTimestamp = false;

    /**
     * 获取用户信息
     */
    public function getUserById($id)
    {
        return $this->where('id', $id)->find();
    }

    /**
     * 根据用户名获取用户
     */
    public function getUserByUsername($username)
    {
        return $this->where('username', $username)->find();
    }

    /**
     * 创建用户
     */
    public function createUser($data)
    {
        return $this->insertGetId($data);
    }

    /**
     * 更新用户
     */
    public function updateUser($id, $data)
    {
        return $this->where('id', $id)->update($data);
    }

    /**
     * 删除用户
     */
    public function deleteUser($id)
    {
        return $this->where('id', $id)->delete();
    }
}
```

### 3. 视图规范

#### 视图职责

- 展示数据
- 处理用户交互
- 不包含业务逻辑

#### 视图示例

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>用户列表</title>
</head>
<body>
    <div class="container">
        <h1>用户列表</h1>
        <table class="user-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>用户名</th>
                    <th>昵称</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                {volist name="users" id="user"}
                <tr>
                    <td>{$user.id}</td>
                    <td>{$user.username}</td>
                    <td>{$user.nickname}</td>
                    <td>{$user.status == 1 ? '正常' : '禁用'}</td>
                    <td>
                        <a href="/user/edit/{$user.id}">编辑</a>
                        <a href="/user/delete/{$user.id}">删除</a>
                    </td>
                </tr>
                {/volist}
            </tbody>
        </table>
    </div>
</body>
</html>
```

## 数据库操作规范

### 1. 查询规范

#### 使用参数化查询

```php
// 正确
$user = Db::name('user')->where('id', $id)->find();

// 错误
$user = Db::name('user')->where("id = {$id}")->find();
```

#### 使用字段别名

```php
$users = Db::name('user')
    ->alias('u')
    ->field('u.id, u.username, u.nickname')
    ->select();
```

#### 使用索引

```php
// 确保查询条件使用索引
$users = Db::name('user')
    ->where('status', 1)
    ->where('create_time', '>', time() - 86400)
    ->select();
```

### 2. 插入规范

#### 使用批量插入

```php
$data = [
    ['username' => 'user1', 'nickname' => '用户1'],
    ['username' => 'user2', 'nickname' => '用户2'],
];

Db::name('user')->insertAll($data);
```

#### 使用事务

```php
Db::startTrans();
try {
    Db::name('user')->insert($userData);
    Db::name('user_profile')->insert($profileData);
    Db::commit();
} catch (\Exception $e) {
    Db::rollback();
    throw $e;
}
```

### 3. 更新规范

#### 使用条件更新

```php
Db::name('user')
    ->where('id', $id)
    ->update(['status' => 1]);
```

#### 使用字段自增

```php
Db::name('user')
    ->where('id', $id)
    ->inc('login_count')
    ->update();
```

### 4. 删除规范

#### 使用软删除

```php
Db::name('user')
    ->where('id', $id)
    ->update(['deleted_at' => time()]);
```

#### 使用条件删除

```php
Db::name('user')
    ->where('status', 0)
    ->where('deleted_at', '<', time() - 86400 * 30)
    ->delete();
```

## API开发规范

### 1. URL规范

#### RESTful风格

| 方法 | URL | 说明 |
|-----|-----|------|
| GET | /api/users | 获取用户列表 |
| GET | /api/users/{id} | 获取用户详情 |
| POST | /api/users | 创建用户 |
| PUT | /api/users/{id} | 更新用户 |
| DELETE | /api/users/{id} | 删除用户 |

#### 查询参数

```http
GET /api/users?page=1&page_size=20&status=1&keyword=admin
```

### 2. 请求规范

#### 请求头

```http
Content-Type: application/json
Authorization: Bearer {token}
```

#### 请求体

```json
{
    "username": "admin",
    "password": "123456",
    "nickname": "管理员"
}
```

### 3. 响应规范

#### 成功响应

```json
{
    "code": 200,
    "message": "success",
    "data": {
        "id": 1,
        "username": "admin",
        "nickname": "管理员"
    }
}
```

#### 分页响应

```json
{
    "code": 200,
    "message": "success",
    "data": {
        "list": [
            {
                "id": 1,
                "username": "admin"
            }
        ],
        "total": 100,
        "page": 1,
        "page_size": 20,
        "total_page": 5
    }
}
```

#### 错误响应

```json
{
    "code": 400,
    "message": "参数错误",
    "data": null
}
```

### 4. 状态码规范

| 状态码 | 说明 |
|-------|------|
| 200 | 成功 |
| 201 | 创建成功 |
| 400 | 请求参数错误 |
| 401 | 未授权 |
| 403 | 禁止访问 |
| 404 | 资源不存在 |
| 500 | 服务器错误 |

## 前端开发规范

### 1. HTML规范

#### 语义化标签

```html
<header>
    <nav>
        <ul>
            <li><a href="/">首页</a></li>
            <li><a href="/moments">动态</a></li>
        </ul>
    </nav>
</header>

<main>
    <article class="moment">
        <header>
            <h1>动态标题</h1>
        </header>
        <p>动态内容</p>
    </article>
</main>

<footer>
    <p>&copy; 2026 社交应用</p>
</footer>
```

#### 表单规范

```html
<form action="/api/user/login" method="POST">
    <div class="form-group">
        <label for="username">用户名</label>
        <input type="text" id="username" name="username" required>
    </div>
    <div class="form-group">
        <label for="password">密码</label>
        <input type="password" id="password" name="password" required>
    </div>
    <button type="submit">登录</button>
</form>
```

### 2. JavaScript规范

#### 模块化开发

```javascript
// user.js
export const getUser = (id) => {
    return fetch(`/api/users/${id}`)
        .then(response => response.json());
};

export const getUserList = (page, pageSize) => {
    return fetch(`/api/users?page=${page}&pageSize=${pageSize}`)
        .then(response => response.json());
};
```

#### 异步处理

```javascript
const loadUserData = async () => {
    try {
        const response = await fetch('/api/user/profile');
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('加载用户数据失败:', error);
        throw error;
    }
};
```

### 3. CSS规范

#### 响应式设计

```css
.container {
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

@media (max-width: 768px) {
    .container {
        padding: 0 10px;
    }
}
```

#### Flexbox布局

```css
.flex-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.flex-item {
    flex: 1;
}
```

## 安全开发规范

### 1. 输入验证

```php
// 验证用户输入
$username = Request::param('username');
if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) {
    return json(['code' => 400, 'message' => '用户名格式错误']);
}
```

### 2. SQL注入防护

```php
// 使用参数化查询
$user = Db::name('user')->where('id', $id)->find();

// 不使用字符串拼接
$user = Db::name('user')->where("id = {$id}")->find();
```

### 3. XSS防护

```php
// 输出时转义HTML
$content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

// 使用ThinkPHP模板引擎自动转义
{$content}
```

### 4. CSRF防护

```php
// 在表单中添加CSRF令牌
<input type="hidden" name="__token__" value="{:token()}">

// 验证CSRF令牌
if (!$request->checkToken('__token__', $request->post())) {
    return json(['code' => 400, 'message' => 'CSRF token验证失败']);
}
```

### 5. 密码加密

```php
// 使用bcrypt加密密码
$password = password_hash($rawPassword, PASSWORD_BCRYPT);

// 验证密码
if (password_verify($inputPassword, $hashedPassword)) {
    // 密码正确
}
```

## 性能优化规范

### 1. 缓存使用

```php
// 使用Redis缓存
$key = 'user_' . $userId;
$user = Cache::store('redis')->get($key);

if (!$user) {
    $user = Db::name('user')->where('id', $userId)->find();
    Cache::store('redis')->set($key, $user, 3600);
}
```

### 2. 数据库查询优化

```php
// 使用索引
$users = Db::name('user')
    ->where('status', 1)
    ->where('create_time', '>', time() - 86400)
    ->select();

// 使用分页
$users = Db::name('user')
    ->where('status', 1)
    ->paginate(20);

// 只查询需要的字段
$users = Db::name('user')
    ->field('id, username, nickname')
    ->select();
```

### 3. 静态资源优化

```html
<!-- 使用CDN -->
<link rel="stylesheet" href="https://cdn.example.com/css/style.css">
<script src="https://cdn.example.com/js/app.js"></script>

<!-- 图片懒加载 -->
<img src="placeholder.jpg" data-src="image.jpg" class="lazyload">
```

## 版本控制规范

### 1. 分支管理

```
main          # 主分支，用于生产环境
develop       # 开发分支
feature/*     # 功能分支
hotfix/*      # 紧急修复分支
release/*     # 发布分支
```

### 2. 提交信息规范

```
feat: 添加用户注册功能
fix: 修复登录验证bug
docs: 更新API文档
style: 代码格式调整
refactor: 重构用户服务
test: 添加单元测试
chore: 更新依赖包
```

### 3. 版本号规范

```
v1.0.0  # 主版本号.次版本号.修订号
```

## 测试规范

### 1. 单元测试

```php
class UserServiceTest extends TestCase
{
    public function testGetUserById()
    {
        $userService = new UserService();
        $user = $userService->getUserById(1);
        
        $this->assertIsArray($user);
        $this->assertEquals(1, $user['id']);
    }
}
```

### 2. 集成测试

```php
class UserControllerTest extends TestCase
{
    public function testGetUserList()
    {
        $response = $this->get('/api/users');
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertIsArray($response->json()['data']);
    }
}
```

## 文档规范

### 1. 代码文档

- 每个类必须包含类注释
- 每个公共方法必须包含方法注释
- 复杂逻辑必须添加行内注释

### 2. API文档

- 每个API接口必须包含接口文档
- 文档包含：URL、方法、参数、响应、示例

### 3. 数据库文档

- 每个表必须包含表结构文档
- 文档包含：字段说明、索引说明、关联关系

## 版本历史

| 版本 | 日期 | 更新内容 |
|-----|------|---------|
| v1.0.0 | 2026-02-11 | 初始版本 |

---

**© 2026 社交应用系统 - 开发规范文档**