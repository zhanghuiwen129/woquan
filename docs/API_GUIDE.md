# API接口使用文档

## 文档概述

本文档详细描述了社交应用系统的所有API接口，包括接口地址、请求方法、请求参数、返回格式、错误码等信息。开发者可以根据本文档快速集成和使用系统的各项功能。

## 基础信息

### 接口域名

```
开发环境: http://localhost
生产环境: https://your-domain.com
```

### 接口协议

- **协议**: HTTP/HTTPS
- **数据格式**: JSON
- **字符编码**: UTF-8

### 请求头

所有API请求需要包含以下请求头：

```
Content-Type: application/json
Accept: application/json
```

### 认证方式

系统采用Session/Cookie认证方式：

1. **登录认证**: 用户登录后，服务器返回Session ID，客户端需要在后续请求中携带Cookie
2. **Token认证**: 部分接口支持Token认证，需要在请求头中携带Authorization字段

### 响应格式

所有API接口返回统一的JSON格式：

```json
{
  "code": 200,
  "msg": "操作成功",
  "data": {}
}
```

### 通用响应码

| 状态码 | 说明 |
|-------|------|
| 200 | 操作成功 |
| 400 | 请求参数错误 |
| 401 | 未登录或登录已过期 |
| 403 | 无权限访问 |
| 404 | 资源不存在 |
| 500 | 服务器内部错误 |

## API接口列表

### 1. 用户相关API

#### 1.1 用户登录

**接口地址**: `/user/login`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| username | string | 是 | 用户名 |
| password | string | 是 | 密码 |
| captcha | string | 否 | 验证码 |

**请求示例**:

```json
{
  "username": "testuser",
  "password": "123456"
}
```

**返回示例**:

```json
{
  "code": 200,
  "msg": "登录成功",
  "data": {
    "user_id": 1,
    "username": "testuser",
    "nickname": "测试用户",
    "avatar": "/uploads/avatar/xxx.jpg",
    "token": "xxxxxxxxxxxxx"
  }
}
```

#### 1.2 用户注册

**接口地址**: `/user/register`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| username | string | 是 | 用户名 |
| password | string | 是 | 密码 |
| phone | string | 是 | 手机号 |
| sms_code | string | 是 | 短信验证码 |
| captcha | string | 否 | 图形验证码 |

**请求示例**:

```json
{
  "username": "newuser",
  "password": "123456",
  "phone": "13800138000",
  "sms_code": "123456"
}
```

**返回示例**:

```json
{
  "code": 200,
  "msg": "注册成功",
  "data": {
    "user_id": 2,
    "username": "newuser"
  }
}
```

#### 1.3 获取当前用户信息

**接口地址**: `/user/getCurrentUser`

**请求方法**: GET

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "id": 1,
    "username": "testuser",
    "nickname": "测试用户",
    "avatar": "/uploads/avatar/xxx.jpg",
    "level": 5,
    "points": 1000,
    "followers_count": 100,
    "following_count": 50
  }
}
```

#### 1.4 更新用户资料

**接口地址**: `/user/updateProfile`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| nickname | string | 否 | 昵称 |
| avatar | string | 否 | 头像URL |
| bio | string | 否 | 个人简介 |
| gender | int | 否 | 性别：1-男，2-女，3-保密 |
| birthday | string | 否 | 生日 |
| location | string | 否 | 所在地 |

**返回示例**:

```json
{
  "code": 200,
  "msg": "更新成功"
}
```

#### 1.5 关注/取消关注

**接口地址**: `/user/follow` 或 `/user/unfollow`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| user_id | int | 是 | 用户ID |

**返回示例**:

```json
{
  "code": 200,
  "msg": "操作成功"
}
```

### 2. 动态相关API

#### 2.1 获取动态列表

**接口地址**: `/api/moments`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| type | int | 否 | 动态类型：0-全部，1-文本，2-图片，3-视频 |
| sort | string | 否 | 排序方式：time-按时间，hot-按热度 |
| topic | int | 否 | 话题ID |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "list": [
      {
        "id": 1,
        "user_id": 1,
        "username": "testuser",
        "nickname": "测试用户",
        "avatar": "/uploads/avatar/xxx.jpg",
        "content": "这是一条动态",
        "images": ["/uploads/image/xxx.jpg"],
        "videos": [],
        "location": "北京",
        "likes": 10,
        "comments": 5,
        "shares": 2,
        "is_liked": false,
        "is_collected": false,
        "create_time": "2026-02-11 10:00:00"
      }
    ],
    "total": 100,
    "page": 1,
    "limit": 20
  }
}
```

#### 2.2 获取动态详情

**接口地址**: `/api/moments/detail`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| id | int | 是 | 动态ID |

#### 2.3 发布动态

**接口地址**: `/moments/publish`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| content | string | 是 | 动态内容 |
| images | array | 否 | 图片URL数组 |
| videos | array | 否 | 视频URL数组 |
| location | string | 否 | 地理位置 |
| topics | array | 否 | 话题ID数组 |
| privacy | int | 否 | 隐私设置：1-公开，2-私密，3-仅好友，4-部分可见 |
| is_anonymous | int | 否 | 是否匿名：0-否，1-是 |
| type | int | 否 | 动态类型：1-文本，2-图片，3-视频 |

### 3. 评论相关API

#### 3.1 获取评论列表

**接口地址**: `/api/comments/list`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| moment_id | int | 是 | 动态ID |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认10 |

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "list": [
      {
        "id": 1,
        "moment_id": 1,
        "user_id": 2,
        "username": "user2",
        "nickname": "用户2",
        "avatar": "/uploads/avatar/xxx.jpg",
        "content": "这条动态很棒！",
        "likes": 5,
        "is_liked": false,
        "create_time": "2026-02-11 10:00:00",
        "replies": []
      }
    ],
    "total": 50,
    "page": 1,
    "limit": 10
  }
}
```

#### 3.2 添加评论

**接口地址**: `/api/comments/add`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| moment_id | int | 是 | 动态ID |
| content | string | 是 | 评论内容 |
| parent_id | int | 否 | 父评论ID（回复评论时使用） |
| reply_to_user_id | int | 否 | 被回复的用户ID |

#### 3.3 点赞评论

**接口地址**: `/api/comments/like`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| comment_id | int | 是 | 评论ID |
| action | string | 是 | 操作：like-点赞，unlike-取消点赞 |

#### 3.4 删除评论

**接口地址**: `/api/comments/delete`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| comment_id | int | 是 | 评论ID |

### 4. 通知相关API

#### 4.1 获取通知列表

**接口地址**: `/notifications/getNotifications`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| type | int | 否 | 通知类型：0-全部，1-点赞，2-评论，3-关注，4-私信，5-系统通知 |
| is_read | int | 否 | 是否已读：-1-全部，0-未读，1-已读 |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "list": [
      {
        "id": 1,
        "type": 1,
        "content": "用户A点赞了你的动态",
        "is_read": 0,
        "create_time": "2026-02-11 10:00:00",
        "user": {
          "id": 2,
          "username": "user2",
          "nickname": "用户2",
          "avatar": "/uploads/avatar/xxx.jpg"
        }
      }
    ],
    "total": 50,
    "page": 1,
    "limit": 20,
    "unread_count": 10,
    "type_unread_counts": {
      "1": 5,
      "2": 3,
      "3": 1,
      "4": 1,
      "5": 0
    }
  }
}
```

#### 4.2 标记通知已读

**接口地址**: `/notifications/markAsRead`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| notification_id | int | 是 | 通知ID |

#### 4.3 批量标记通知已读

**接口地址**: `/notifications/batchMarkAsRead`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| notification_ids | array | 是 | 通知ID数组 |

#### 4.4 批量删除通知

**接口地址**: `/notifications/batchDelete`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| notification_ids | array | 是 | 通知ID数组 |

#### 4.5 获取未读通知数

**接口地址**: `/notifications/getUnreadCount`

**请求方法**: GET

#### 4.6 获取通知角标

**接口地址**: `/notifications/getBadge`

**请求方法**: GET

### 5. 好友相关API

#### 5.1 获取好友分组列表

**接口地址**: `/friends/getGroupList`

**请求方法**: GET

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": [
    {
      "id": 1,
      "name": "同事",
      "sort": 1,
      "friend_count": 10
    },
    {
      "id": 2,
      "name": "家人",
      "sort": 2,
      "friend_count": 5
    }
  ]
}
```

#### 5.2 创建好友分组

**接口地址**: `/friends/createGroup`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| name | string | 是 | 分组名称 |
| sort | int | 否 | 排序 |

#### 5.3 更新好友分组

**接口地址**: `/friends/updateGroup`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| group_id | int | 是 | 分组ID |
| name | string | 否 | 分组名称 |
| sort | int | 否 | 排序 |

#### 5.4 删除好友分组

**接口地址**: `/friends/deleteGroup`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| group_id | int | 是 | 分组ID |

### 6. 发现相关API

#### 6.1 获取活动列表

**接口地址**: `/discovery/getActivityList`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| type | int | 否 | 活动类型：0-全部，1-线上活动，2-线下活动 |
| status | int | 否 | 活动状态：0-未发布，1-进行中，2-已结束，3-已取消 |
| is_hot | int | 否 | 是否热门：-1-全部，0-否，1-是 |
| keyword | string | 否 | 搜索关键词 |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认10 |

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "list": [
      {
        "id": 1,
        "title": "春节活动",
        "description": "春节特别活动",
        "type": 1,
        "status": 1,
        "is_hot": 1,
        "start_time": "2026-02-01 00:00:00",
        "end_time": "2026-02-15 23:59:59",
        "participants_count": 100
      }
    ],
    "total": 20,
    "page": 1,
    "limit": 10
  }
}
```

#### 6.2 获取热门活动

**接口地址**: `/discovery/getHotActivities`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| limit | int | 否 | 返回数量，默认10 |

#### 6.3 获取活动详情

**接口地址**: `/discovery/getActivityDetail`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| id | int | 是 | 活动ID |

#### 6.4 参与活动

**接口地址**: `/discovery/participateActivity`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| activity_id | int | 是 | 活动ID |

#### 6.5 取消参与活动

**接口地址**: `/discovery/cancelParticipation`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| activity_id | int | 是 | 活动ID |

#### 6.6 获取我的活动

**接口地址**: `/discovery/getMyActivities`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| status | int | 否 | 活动状态：0-全部，1-进行中，2-已结束 |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认10 |

### 7. 话题相关API

#### 7.1 获取热门话题列表

**接口地址**: `/topics/getHotTopics`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "list": [
      {
        "id": 1,
        "name": "生活记录",
        "description": "记录生活中的点点滴滴",
        "count": 1000,
        "is_followed": false
      }
    ],
    "total": 50,
    "page": 1,
    "limit": 20
  }
}
```

### 8. 搜索相关API

#### 8.1 全局搜索

**接口地址**: `/search`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| keyword | string | 是 | 搜索关键词 |
| type | string | 否 | 搜索类型：all-全部，user-用户，moment-动态，topic-话题 |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

**返回示例**:

```json
{
  "code": 200,
  "msg": "搜索成功",
  "data": {
    "users": [
      {
        "id": 1,
        "username": "testuser",
        "nickname": "测试用户",
        "avatar": "/uploads/avatar/xxx.jpg"
      }
    ],
    "moments": [
      {
        "id": 1,
        "content": "这是一条动态",
        "user": {
          "id": 1,
          "username": "testuser",
          "nickname": "测试用户",
          "avatar": "/uploads/avatar/xxx.jpg"
        }
      }
    ],
    "topics": [
      {
        "id": 1,
        "name": "生活记录",
        "count": 1000
      }
    ]
  }
}
```

### 9. 文件上传API

#### 9.1 上传文件

**接口地址**: `/upload/uploadFile`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| file | file | 是 | 文件 |
| type | string | 否 | 文件类型：image-图片，video-视频，audio-音频，document-文档 |

**返回示例**:

```json
{
  "code": 200,
  "msg": "上传成功",
  "data": {
    "url": "/uploads/2026/02/11/xxx.jpg",
    "filename": "xxx.jpg",
    "size": 102400,
    "mime_type": "image/jpeg"
  }
}
```

### 10. 文章相关API

#### 10.1 获取文章列表

**接口地址**: `/api/articles`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| category_id | int | 否 | 分类ID |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

#### 10.2 获取文章详情

**接口地址**: `/api/articles/detail`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| id | int | 是 | 文章ID |

#### 10.3 发布文章

**接口地址**: `/api/articles/publish`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| title | string | 是 | 文章标题 |
| content | string | 是 | 文章内容 |
| cover_image | string | 否 | 封面图片URL |
| category_id | int | 否 | 分类ID |
| tags | array | 否 | 标签数组 |
| status | int | 否 | 状态：0-草稿，1-发布 |

#### 10.4 更新文章

**接口地址**: `/api/articles/update`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| id | int | 是 | 文章ID |
| title | string | 否 | 文章标题 |
| content | string | 否 | 文章内容 |
| cover_image | string | 否 | 封面图片URL |
| category_id | int | 否 | 分类ID |
| tags | array | 否 | 标签数组 |
| status | int | 否 | 状态：0-草稿，1-发布 |

#### 10.5 删除文章

**接口地址**: `/api/articles/delete`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| id | int | 是 | 文章ID |

#### 10.6 点赞文章

**接口地址**: `/api/articles/like`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| id | int | 是 | 文章ID |
| action | string | 是 | 操作：like-点赞，unlike-取消点赞 |

#### 10.7 收藏文章

**接口地址**: `/api/articles/collect`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| id | int | 是 | 文章ID |
| action | string | 是 | 操作：collect-收藏，uncollect-取消收藏 |

#### 10.8 获取文章分类

**接口地址**: `/api/articles/categories`

**请求方法**: GET

#### 10.9 获取文章评论

**接口地址**: `/api/article-comments/list`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| article_id | int | 是 | 文章ID |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

#### 10.10 添加文章评论

**接口地址**: `/api/article-comments/add`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| article_id | int | 是 | 文章ID |
| content | string | 是 | 评论内容 |

#### 10.11 点赞文章评论

**接口地址**: `/api/article-comments/like`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| comment_id | int | 是 | 评论ID |
| action | string | 是 | 操作：like-点赞，unlike-取消点赞 |

### 11. 钱包相关API

#### 11.1 获取钱包信息

**接口地址**: `/wallet/getWalletInfo`

**请求方法**: GET

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "balance": 1000.00,
    "frozen": 100.00,
    "available": 900.00,
    "currency": "CNY"
  }
}
```

#### 11.2 创建充值订单

**接口地址**: `/wallet/createRechargeOrder`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| amount | decimal | 是 | 充值金额 |
| payment_method | string | 是 | 支付方式：alipay-支付宝，wechat-微信 |

#### 11.3 创建提现申请

**接口地址**: `/wallet/createWithdraw`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| amount | decimal | 是 | 提现金额 |
| account | string | 是 | 提现账号 |
| account_name | string | 是 | 账户名称 |

#### 11.4 获取交易记录

**接口地址**: `/wallet/transactions`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| type | string | 否 | 交易类型：all-全部，recharge-充值，withdraw-提现，consume-消费 |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

### 12. 积分相关API

#### 12.1 获取用户积分

**接口地址**: `/api/points/info`

**请求方法**: GET

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "points": 1000,
    "level": 5,
    "next_level_points": 2000,
    "next_level_name": "VIP会员"
  }
}
```

#### 12.2 获取积分记录

**接口地址**: `/api/points/history`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| type | string | 否 | 类型：all-全部，earn-获得，spend-消费 |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

#### 12.3 获取积分规则

**接口地址**: `/api/points/rules`

**请求方法**: GET

#### 12.4 积分兑换

**接口地址**: `/api/points/exchange`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| item_id | int | 是 | 兑换物品ID |
| quantity | int | 否 | 数量，默认1 |

#### 12.5 获取兑换物品列表

**接口地址**: `/api/exchange/items`

**请求方法**: GET

#### 12.6 获取兑换订单

**接口地址**: `/api/exchange/orders`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

### 13. 等级相关API

#### 13.1 获取用户等级

**接口地址**: `/api/levels/info`

**请求方法**: GET

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "level": 5,
    "level_name": "黄金会员",
    "points": 1000,
    "next_level": 6,
    "next_level_name": "铂金会员",
    "next_level_points": 2000,
    "progress": 50
  }
}
```

#### 13.2 获取等级排行榜

**接口地址**: `/api/levels/ranking`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

### 14. 任务相关API

#### 14.1 获取每日任务

**接口地址**: `/api/tasks/daily`

**请求方法**: GET

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": [
    {
      "id": 1,
      "name": "每日签到",
      "description": "每天签到可获得积分",
      "points": 10,
      "completed": false,
      "progress": 0,
      "target": 1
    },
    {
      "id": 2,
      "name": "发布动态",
      "description": "每天发布3条动态",
      "points": 20,
      "completed": false,
      "progress": 1,
      "target": 3
    }
  ]
}
```

#### 14.2 获取成长任务

**接口地址**: `/api/tasks/growth`

**请求方法**: GET

#### 14.3 完成任务

**接口地址**: `/api/tasks/complete`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| task_id | int | 是 | 任务ID |

### 15. 定位相关API

#### 15.1 保存位置

**接口地址**: `/api/location/save`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| latitude | decimal | 是 | 纬度 |
| longitude | decimal | 是 | 经度 |
| address | string | 否 | 地址 |

#### 15.2 获取附近用户

**接口地址**: `/api/location/nearby`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| latitude | decimal | 是 | 纬度 |
| longitude | decimal | 是 | 经度 |
| radius | int | 否 | 半径（公里），默认10 |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

#### 15.3 逆地理编码

**接口地址**: `/api/location/reverse`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| latitude | decimal | 是 | 纬度 |
| longitude | decimal | 是 | 经度 |

#### 15.4 获取热门地点

**接口地址**: `/api/location/popular`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| limit | int | 否 | 返回数量，默认10 |

### 16. 表情相关API

#### 16.1 获取表情列表

**接口地址**: `/emojis/getEmojiList`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| category | string | 否 | 分类 |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

#### 16.2 上传表情

**接口地址**: `/emojis/uploadEmoji`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| file | file | 是 | 表情文件 |
| name | string | 是 | 表情名称 |
| category | string | 否 | 分类 |

#### 16.3 删除表情

**接口地址**: `/emojis/deleteEmoji`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| emoji_id | int | 是 | 表情ID |

#### 16.4 记录表情使用

**接口地址**: `/emojis/recordUsage`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| emoji_id | int | 是 | 表情ID |

#### 16.5 搜索表情

**接口地址**: `/emojis/searchEmojis`

**请求方法**: GET

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| keyword | string | 是 | 搜索关键词 |
| page | int | 否 | 页码，默认1 |
| limit | int | 否 | 每页数量，默认20 |

### 17. 系统相关API

#### 17.1 获取网站配置

**接口地址**: `/site-config`

**请求方法**: GET

**返回示例**:

```json
{
  "code": 200,
  "msg": "获取成功",
  "data": {
    "site_name": "社交应用",
    "site_url": "https://example.com",
    "site_logo": "/uploads/logo.png",
    "site_description": "这是一个社交应用"
  }
}
```

#### 17.2 清除缓存

**接口地址**: `/api/clear-cache`

**请求方法**: POST

**返回示例**:

```json
{
  "code": 200,
  "msg": "缓存清除成功"
}
```

#### 17.3 JavaScript错误日志

**接口地址**: `/api/js-error-log`

**请求方法**: POST

**请求参数**:

| 参数名 | 类型 | 必填 | 说明 |
|-------|------|------|------|
| message | string | 是 | 错误信息 |
| url | string | 是 | 错误URL |
| line | int | 是 | 错误行号 |
| column | int | 是 | 错误列号 |
| stack | string | 否 | 错误堆栈 |

#### 17.4 用户心跳

**接口地址**: `/api/user/heartbeat`

**请求方法**: POST

**请求参数**: 无

## 错误处理

### 错误响应格式

所有错误响应都遵循统一的格式：

```json
{
  "code": 400,
  "msg": "请求参数错误",
  "data": {
    "errors": {
      "username": "用户名不能为空",
      "password": "密码长度不能少于6位"
    }
  }
}
```

### 常见错误码

| 错误码 | 说明 | 解决方案 |
|-------|------|---------|
| 400 | 请求参数错误 | 检查请求参数是否正确 |
| 401 | 未登录或登录已过期 | 重新登录 |
| 403 | 无权限访问 | 检查用户权限 |
| 404 | 资源不存在 | 检查资源ID是否正确 |
| 429 | 请求过于频繁 | 降低请求频率 |
| 500 | 服务器内部错误 | 联系技术支持 |

## 限流规则

为了保护系统稳定，所有API接口都有限流规则：

| 接口类型 | 限流规则 |
|---------|---------|
| 登录接口 | 每分钟5次 |
| 注册接口 | 每分钟3次 |
| 短信接口 | 每小时10次 |
| 普通接口 | 每分钟60次 |

超过限流规则将返回429错误码。

## 最佳实践

### 1. 错误处理

始终检查响应的code字段，确保请求成功后再处理数据：

```javascript
fetch('/api/moments')
  .then(response => response.json())
  .then(data => {
    if (data.code === 200) {
      // 处理成功数据
      console.log(data.data);
    } else {
      // 处理错误
      console.error(data.msg);
    }
  });
```

### 2. 分页处理

使用分页参数时，注意处理最后一页的情况：

```javascript
async function loadMoments(page = 1) {
  const response = await fetch(`/api/moments?page=${page}&limit=20`);
  const data = await response.json();
  
  if (data.code === 200) {
    const { list, total, page: currentPage, limit } = data.data;
    const totalPages = Math.ceil(total / limit);
    
    if (currentPage >= totalPages) {
      // 已到最后一页
      console.log('没有更多数据了');
    }
  }
}
```

### 3. 认证处理

确保在需要认证的接口中携带正确的认证信息：

```javascript
fetch('/api/moments/publish', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  credentials: 'include', // 携带Cookie
  body: JSON.stringify({
    content: '这是一条动态'
  })
});
```

### 4. 文件上传

使用FormData上传文件：

```javascript
const formData = new FormData();
formData.append('file', fileInput.files[0]);
formData.append('type', 'image');

fetch('/upload/uploadFile', {
  method: 'POST',
  body: formData
})
  .then(response => response.json())
  .then(data => {
    if (data.code === 200) {
      console.log('上传成功', data.data.url);
    }
  });
```

## 版本历史

| 版本 | 日期 | 更新内容 |
|-----|------|---------|
| v1.0.0 | 2026-02-11 | 初始版本 |

---

**© 2026 社交应用系统 - API接口使用文档**